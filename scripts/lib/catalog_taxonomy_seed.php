<?php
declare(strict_types=1);

/**
 * Shared taxonomy seed runner (CLI tools + tests).
 * Requires includes/catalog_taxonomy.php to be loaded.
 */

/**
 * @return array{
 *   created_categories:list<string>,
 *   renamed_categories:list<string>,
 *   reused_categories:list<string>,
 *   created_subcategories:list<string>,
 *   reused_subcategories:list<string>,
 *   updated_icons:list<string>,
 *   conflicts:list<string>,
 *   products_preserved:int,
 *   category_count:int,
 *   subcategory_count:int
 * }
 */
function seed_catalog_taxonomy_run(PDO $pdo, bool $apply): array
{
    $report = [
        'created_categories' => [],
        'renamed_categories' => [],
        'reused_categories' => [],
        'created_subcategories' => [],
        'reused_subcategories' => [],
        'updated_icons' => [],
        'conflicts' => [],
        'products_preserved' => 0,
        'category_count' => 0,
        'subcategory_count' => 0,
    ];

    $definition = catalog_taxonomy_definition();
    $expectedCats = catalog_taxonomy_expected_category_count();
    $expectedSubs = catalog_taxonomy_expected_subcategory_count();
    if ($expectedCats !== 10 || $expectedSubs !== 69) {
        throw new RuntimeException('Definición canónica inválida (se esperaban 10 categorías y 69 subcategorías).');
    }

    $pdo->beginTransaction();
    try {
        $productCountStmt = $pdo->query('SELECT COUNT(*) FROM products');
        $report['products_preserved'] = (int) $productCountStmt->fetchColumn();

        // Load existing categories by lowercase name.
        $existing = $pdo->query('SELECT id, name, icon FROM categories')->fetchAll(PDO::FETCH_ASSOC);
        $byName = [];
        foreach ($existing as $row) {
            $key = mb_strtolower(trim((string) $row['name']));
            if ($key === '') {
                continue;
            }
            if (isset($byName[$key])) {
                throw new RuntimeException('Categorías duplicadas por nombre en la base: ' . $row['name']);
            }
            $byName[$key] = $row;
        }

        // Conflict: legacy and canonical both present.
        foreach ($definition as $def) {
            $canonicalKey = mb_strtolower($def['name']);
            foreach ($def['legacy_names'] as $legacy) {
                $legacyKey = mb_strtolower($legacy);
                if (isset($byName[$legacyKey], $byName[$canonicalKey]) && $legacyKey !== $canonicalKey) {
                    $msg = "Conflicto: existen '{$legacy}' e '{$def['name']}' a la vez. Abortando sin fusionar.";
                    $report['conflicts'][] = $msg;
                    throw new RuntimeException($msg);
                }
            }
        }

        $findCategoryStmt = $pdo->prepare('SELECT id, name, icon FROM categories WHERE name = ? LIMIT 1');
        $renameStmt = $pdo->prepare('UPDATE categories SET name = ?, icon = ? WHERE id = ?');
        $iconStmt = $pdo->prepare('UPDATE categories SET icon = ? WHERE id = ?');
        $insertCatStmt = $pdo->prepare('INSERT INTO categories (name, icon) VALUES (?, ?)');
        $findSubStmt = $pdo->prepare(
            'SELECT id, category_id, name FROM subcategories WHERE category_id = ? AND name = ? LIMIT 1'
        );
        $insertSubStmt = $pdo->prepare('INSERT INTO subcategories (category_id, name) VALUES (?, ?)');
        $wrongParentStmt = $pdo->prepare(
            'SELECT id, category_id, name FROM subcategories WHERE name = ? AND category_id <> ? LIMIT 1'
        );

        $categoryIds = [];

        foreach ($definition as $def) {
            $canonical = $def['name'];
            $icon = catalog_taxonomy_icon_token($def['icon']);
            $row = null;

            $findCategoryStmt->execute([$canonical]);
            $row = $findCategoryStmt->fetch(PDO::FETCH_ASSOC) ?: null;

            if ($row === null) {
                foreach ($def['legacy_names'] as $legacy) {
                    $findCategoryStmt->execute([$legacy]);
                    $legacyRow = $findCategoryStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                    if ($legacyRow !== null) {
                        $row = $legacyRow;
                        break;
                    }
                }
            }

            if ($row !== null) {
                $id = (int) $row['id'];
                $currentName = (string) $row['name'];
                $currentIcon = catalog_taxonomy_icon_token((string) $row['icon']);
                if ($currentName !== $canonical) {
                    $renameStmt->execute([$canonical, $icon, $id]);
                    $report['renamed_categories'][] = "{$currentName} → {$canonical} (id={$id})";
                } elseif ($currentIcon !== $icon) {
                    $iconStmt->execute([$icon, $id]);
                    $report['updated_icons'][] = "{$canonical} icon {$currentIcon} → {$icon}";
                } else {
                    $report['reused_categories'][] = "{$canonical} (id={$id})";
                }
                $categoryIds[$canonical] = $id;
            } else {
                $insertCatStmt->execute([$canonical, $icon]);
                $id = (int) $pdo->lastInsertId();
                $categoryIds[$canonical] = $id;
                $report['created_categories'][] = "{$canonical} (id={$id})";
            }
        }

        foreach ($definition as $def) {
            $canonical = $def['name'];
            $categoryId = $categoryIds[$canonical] ?? 0;
            if ($categoryId <= 0) {
                throw new RuntimeException("Categoría sin id resuelto: {$canonical}");
            }
            foreach ($def['subcategories'] as $subName) {
                $findSubStmt->execute([$categoryId, $subName]);
                $sub = $findSubStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($sub !== null) {
                    if ((int) $sub['category_id'] !== $categoryId) {
                        throw new RuntimeException(
                            "Subcategoría '{$subName}' pertenece a category_id={$sub['category_id']}, esperado {$categoryId}"
                        );
                    }
                    $report['reused_subcategories'][] = "{$canonical} / {$subName}";
                    continue;
                }

                // Same name under another category: do not move; create under canonical parent.
                $wrongParentStmt->execute([$subName, $categoryId]);
                $elsewhere = $wrongParentStmt->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($elsewhere !== null) {
                    // Allowed: e.g. unrelated historical rows; we still insert under correct parent
                    // only if name+category unique isn't constrained — schema has no unique on name.
                    // Creating a second subcategory with same name under different category is OK.
                }

                $insertSubStmt->execute([$categoryId, $subName]);
                $report['created_subcategories'][] = "{$canonical} / {$subName}";
            }
        }

        // Validate every canonical subcategory is under the correct category.
        $validateStmt = $pdo->prepare(
            'SELECT s.id, s.category_id, c.name AS category_name
             FROM subcategories s
             INNER JOIN categories c ON c.id = s.category_id
             WHERE s.name = ? AND c.name = ?'
        );
        foreach ($definition as $def) {
            foreach ($def['subcategories'] as $subName) {
                $validateStmt->execute([$subName, $def['name']]);
                $ok = $validateStmt->fetch(PDO::FETCH_ASSOC);
                if ($ok === false) {
                    throw new RuntimeException(
                        "Validación fallida: falta subcategoría '{$subName}' bajo '{$def['name']}'"
                    );
                }
            }
        }

        $report['category_count'] = (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
        $report['subcategory_count'] = (int) $pdo->query('SELECT COUNT(*) FROM subcategories')->fetchColumn();

        $productsAfter = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
        if ($productsAfter !== $report['products_preserved']) {
            throw new RuntimeException('La cantidad de productos cambió durante el seed; abortando.');
        }

        // Ensure product FKs still resolve (no product category deleted).
        $orphan = (int) $pdo->query(
            'SELECT COUNT(*) FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             WHERE c.id IS NULL'
        )->fetchColumn();
        if ($orphan > 0) {
            throw new RuntimeException("Productos huérfanos de categoría: {$orphan}");
        }

        if ($apply) {
            $pdo->commit();
        } else {
            $pdo->rollBack();
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $report;
}

