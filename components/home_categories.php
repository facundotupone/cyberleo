<?php
/**
 * Categories section fragment for the homepage.
 * Expects: $categories, $all_subcategories, $themeSettings
 */
if (($themeSettings['show_categories'] ?? '1') !== '1') {
    return;
}
require_once __DIR__ . '/../includes/catalog_taxonomy.php';
if (function_exists('catalog_taxonomy_sort_categories')) {
    $categories = catalog_taxonomy_sort_categories($categories);
}
?>
        <section id="categorias" class="row mb-1 mt-4 g-3" aria-label="Categorías y subcategorías">
            <?php foreach ($categories as $category):
                $subcategories = isset($all_subcategories[$category['id']]) ? $all_subcategories[$category['id']] : [];
                if ($subcategories === []) {
                    // Avoid empty category groups on the homepage.
                    continue;
                }
                $iconClass = catalog_taxonomy_icon_class((string) ($category['icon'] ?? ''));
            ?>
            <div class="col-6 col-md-4 col-lg-3">
                <div class="card h-100 category-card">
                    <div class="card-body text-center">
                        <h3 class="card-title h5">
                            <i class="<?= htmlspecialchars($iconClass) ?> category-icon" aria-hidden="true"></i>
                            <?= htmlspecialchars((string) $category['name']) ?>
                        </h3>
                        <div class="subcategories-container mb-3">
                            <?php foreach ($subcategories as $subcategory): ?>
                                <a href="category.php?id=<?= (int) $category['id'] ?>&sub=<?= (int) $subcategory['id'] ?>"
                                   class="badge text-decoration-none m-1">
                                    <?= htmlspecialchars((string) $subcategory['name']) ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <a href="category.php?id=<?= (int) $category['id'] ?>" class="btn btn-primary">Ver productos</a>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </section>
