<?php
const MAX_IMAGE_BYTES = 5242880;
function store_safe_image($tmpName, $error, $size, $directory) {
    if ($error !== UPLOAD_ERR_OK || $size < 1 || $size > MAX_IMAGE_BYTES) throw new RuntimeException('Imagen inválida o demasiado grande.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpName);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $dimensions = @getimagesize($tmpName);
    if (!isset($extensions[$mime]) || !$dimensions || $dimensions[0] > 6000 || $dimensions[1] > 6000) throw new RuntimeException('Formato o dimensiones de imagen no permitidos.');
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) throw new RuntimeException('No se pudo preparar imágenes.');
    $path = rtrim($directory, '/') . '/' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($tmpName, $path)) throw new RuntimeException('No se pudo guardar la imagen.');
    return $path;
}
function is_safe_product_image_path($path) {
    return is_string($path) && preg_match('#^assets/images/products/(?:[a-f0-9]{13}|[a-f0-9]{32})\.(?:jpe?g|png|webp)$#i', $path);
}

function is_safe_settings_image_path($path) {
    return is_string($path) && preg_match('#^assets/images/settings/[a-f0-9]{32}\.(?:jpe?g|png|webp)$#i', $path);
}

function product_image_directory(?string $root = null): string {
    return rtrim($root ?? dirname(__DIR__), DIRECTORY_SEPARATOR) . '/assets/images/products';
}

function path_has_symlink(string $path): bool {
    $current = str_starts_with($path, DIRECTORY_SEPARATOR) ? DIRECTORY_SEPARATOR : '';
    foreach (explode(DIRECTORY_SEPARATOR, trim($path, DIRECTORY_SEPARATOR)) as $segment) {
        if ($segment === '') continue;
        $current .= ($current === '' || $current === DIRECTORY_SEPARATOR ? '' : DIRECTORY_SEPARATOR) . $segment;
        if (is_link($current)) return true;
    }
    return false;
}

/**
 * @return array{status:string,path:?string}
 */
function resolve_safe_product_image_path($path, ?string $root = null): array {
    if (!is_safe_product_image_path($path)) return ['status' => 'unsafe_path', 'path' => null];

    $directory = product_image_directory($root);
    $candidate = $directory . DIRECTORY_SEPARATOR . basename($path);
    // Check links before realpath(), which otherwise resolves an escaped target.
    if (path_has_symlink($directory) || is_link($candidate)) return ['status' => 'symlink_path', 'path' => null];
    if (!is_file($candidate)) return ['status' => 'missing_file', 'path' => null];

    $realDirectory = realpath($directory);
    $realCandidate = realpath($candidate);
    if ($realDirectory === false || $realCandidate === false
        || !str_starts_with($realCandidate, $realDirectory . DIRECTORY_SEPARATOR)) {
        return ['status' => 'unsafe_path', 'path' => null];
    }
    if (is_link($candidate) || is_link($realCandidate)) return ['status' => 'symlink_path', 'path' => null];
    return ['status' => 'resolved', 'path' => $realCandidate];
}

function is_safe_upload_path($path, ?string $root = null): bool {
    return resolve_safe_product_image_path($path, $root)['status'] === 'resolved';
}

function delete_unreferenced_product_image(PDO $pdo, $path, ?string $root = null, ?callable $deleteFile = null): string {
    if ($pdo->inTransaction()) throw new LogicException('Image deletion requires a committed database transaction.');

    $resolved = resolve_safe_product_image_path($path, $root);
    if ($resolved['status'] !== 'resolved') return $resolved['status'];
    $check = $pdo->prepare(
        'SELECT (SELECT COUNT(*) FROM products WHERE image = ?) + '
        . '(SELECT COUNT(*) FROM product_images WHERE image_path = ?) + '
        . '(SELECT COUNT(*) FROM store_settings WHERE setting_value = ?)'
    );
    $check->execute([$path, $path, $path]);
    if ((int) $check->fetchColumn() > 0) return 'still_referenced';

    try {
        $deleted = $deleteFile !== null ? $deleteFile($resolved['path']) : @unlink($resolved['path']);
    } catch (Throwable $e) {
        error_log('Could not delete unreferenced product image: ' . $e->getMessage());
        return 'deletion_failed';
    }
    if ($deleted !== true) {
        error_log('Could not delete unreferenced product image.');
        return 'deletion_failed';
    }
    return 'deleted';
}

/**
 * @return array<string,string> paths indexed by their cleanup state
 */
function cleanup_product_images_after_commit(PDO $pdo, array $paths, ?string $root = null, ?callable $deleteFile = null): array {
    if ($pdo->inTransaction()) throw new LogicException('Image cleanup requires a committed database transaction.');

    $results = [];
    foreach (array_unique($paths) as $path) {
        if (is_string($path)) {
            $results[$path] = delete_unreferenced_product_image($pdo, $path, $root, $deleteFile);
        }
    }
    return $results;
}

/**
 * Deletes one image row and repairs the selected main image atomically.
 *
 * @return array{status:string,path:?string}
 */
function delete_product_image_record(PDO $pdo, int $imageId): array {
    if ($imageId < 1) return ['status' => 'invalid_image_id', 'path' => null];

    $pdo->beginTransaction();
    try {
        $statement = $pdo->prepare('SELECT product_id, image_path FROM product_images WHERE id = ?');
        $statement->execute([$imageId]);
        $image = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$image) {
            $pdo->rollBack();
            return ['status' => 'not_found', 'path' => null];
        }

        // Keep lock acquisition deterministic: product first, then its image rows by id.
        $product = $pdo->prepare('SELECT id FROM products WHERE id = ? FOR UPDATE');
        $product->execute([$image['product_id']]);
        if ($product->fetchColumn() === false) {
            $pdo->rollBack();
            return ['status' => 'not_found', 'path' => null];
        }

        $images = $pdo->prepare(
            'SELECT id, image_path, is_main FROM product_images WHERE product_id = ? ORDER BY id ASC FOR UPDATE'
        );
        $images->execute([$image['product_id']]);
        $lockedImages = $images->fetchAll(PDO::FETCH_ASSOC);
        $lockedImage = null;
        foreach ($lockedImages as $candidate) {
            if ((int) $candidate['id'] === $imageId) {
                $lockedImage = $candidate;
                break;
            }
        }
        if (!$lockedImage) {
            $pdo->rollBack();
            return ['status' => 'not_found', 'path' => null];
        }

        $pdo->prepare('DELETE FROM product_images WHERE id = ?')->execute([$imageId]);
        $next = $pdo->prepare(
            'SELECT id, image_path FROM product_images WHERE product_id = ? '
            . 'ORDER BY is_main DESC, id ASC LIMIT 1 FOR UPDATE'
        );
        $next->execute([$image['product_id']]);
        $replacement = $next->fetch(PDO::FETCH_ASSOC) ?: null;
        $pdo->prepare('UPDATE product_images SET is_main = 0 WHERE product_id = ?')->execute([$image['product_id']]);
        if ($replacement) {
            $pdo->prepare('UPDATE product_images SET is_main = 1 WHERE id = ?')->execute([$replacement['id']]);
        }
        $pdo->prepare('UPDATE products SET image = ? WHERE id = ?')
            ->execute([$replacement['image_path'] ?? null, $image['product_id']]);

        $pdo->commit();
        return ['status' => 'deleted', 'path' => $lockedImage['image_path']];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * Deletes a product and returns candidate files that may be cleaned post-commit.
 *
 * @return array{status:string,paths:array<int,string>}
 */
function delete_product_record(PDO $pdo, int $productId): array {
    if ($productId < 1) return ['status' => 'invalid_product_id', 'paths' => []];

    $pdo->beginTransaction();
    try {
        $product = $pdo->prepare('SELECT image FROM products WHERE id = ? FOR UPDATE');
        $product->execute([$productId]);
        $mainPath = $product->fetchColumn();
        if ($mainPath === false) {
            $pdo->rollBack();
            return ['status' => 'not_found', 'paths' => []];
        }
        // Lock in the same product-then-image/id order as single-image deletion.
        $images = $pdo->prepare(
            'SELECT image_path FROM product_images WHERE product_id = ? ORDER BY id ASC FOR UPDATE'
        );
        $images->execute([$productId]);
        $paths = $images->fetchAll(PDO::FETCH_COLUMN);
        if (is_string($mainPath)) $paths[] = $mainPath;

        $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$productId]);
        $pdo->commit();
        return ['status' => 'deleted', 'paths' => array_values(array_unique($paths))];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
