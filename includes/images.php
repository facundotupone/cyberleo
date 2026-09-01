<?php
const MAX_IMAGE_BYTES = 5242880;

function image_storage_directory(string $scope, ?string $root = null): string {
    if (!in_array($scope, ['products', 'settings'], true)) {
        throw new InvalidArgumentException('Directorio de imágenes no permitido.');
    }
    return rtrim($root ?? dirname(__DIR__), DIRECTORY_SEPARATOR) . '/assets/images/' . $scope;
}

function image_storage_relative_directory(string $directory, ?string $root = null): string {
    $normalized = str_replace('\\', '/', rtrim($directory, '/\\'));
    $projectRoot = str_replace('\\', '/', rtrim($root ?? dirname(__DIR__), '/\\'));
    if (str_starts_with($normalized, $projectRoot . '/')) {
        $normalized = substr($normalized, strlen($projectRoot) + 1);
    }
    if (!in_array($normalized, ['assets/images/products', 'assets/images/settings'], true)) {
        throw new InvalidArgumentException('Directorio de imágenes no permitido.');
    }
    return $normalized;
}

function store_safe_image(
    $tmpName,
    $error,
    $size,
    $directory,
    ?string $root = null,
    ?callable $moveFile = null,
    ?callable $deleteFile = null
): string {
    if ($error !== UPLOAD_ERR_OK || $size < 1 || $size > MAX_IMAGE_BYTES || !is_file($tmpName)) {
        throw new RuntimeException('Imagen inválida o demasiado grande.');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpName);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $dimensions = @getimagesize($tmpName);
    if (!isset($extensions[$mime]) || !$dimensions || $dimensions[0] > 6000 || $dimensions[1] > 6000) throw new RuntimeException('Formato o dimensiones de imagen no permitidos.');
    $relativeDirectory = image_storage_relative_directory((string) $directory, $root);
    $scope = basename($relativeDirectory);
    $physicalDirectory = image_storage_directory($scope, $root);
    $parentDirectory = dirname($physicalDirectory);
    if (path_has_symlink($parentDirectory)
        || (!is_dir($physicalDirectory) && !mkdir($physicalDirectory, 0755, true))
        || path_has_symlink($physicalDirectory)) {
        throw new RuntimeException('No se pudo preparar imágenes.');
    }
    $relativePath = $relativeDirectory . '/' . bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
    $physicalPath = $physicalDirectory . '/' . basename($relativePath);
    try {
        $moved = $moveFile !== null
            ? $moveFile((string) $tmpName, $physicalPath)
            : move_uploaded_file((string) $tmpName, $physicalPath);
    } catch (Throwable $e) {
        remove_image_file($physicalPath, $deleteFile);
        throw new RuntimeException('No se pudo guardar la imagen.', 0, $e);
    }
    if ($moved !== true) {
        remove_image_file($physicalPath, $deleteFile);
        throw new RuntimeException('No se pudo guardar la imagen.');
    }
    return $relativePath;
}

function remove_image_file(string $path, ?callable $deleteFile = null): bool {
    if (!is_file($path) || is_link($path)) return false;
    try {
        return ($deleteFile !== null ? $deleteFile($path) : @unlink($path)) === true;
    } catch (Throwable $e) {
        error_log('Could not remove image file: ' . $e->getMessage());
        return false;
    }
}
function is_safe_product_image_path($path) {
    return is_string($path) && preg_match('#^assets/images/products/(?:[a-f0-9]{13}|[a-f0-9]{32})\.(?:jpe?g|png|webp)$#i', $path);
}

function is_safe_settings_image_path($path) {
    return is_string($path) && preg_match('#^assets/images/settings/[a-f0-9]{32}\.(?:jpe?g|png|webp)$#i', $path);
}

function product_image_directory(?string $root = null): string {
    return image_storage_directory('products', $root);
}

function settings_image_directory(?string $root = null): string {
    return image_storage_directory('settings', $root);
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
    return resolve_safe_stored_image_path($path, $root, 'products');
}

/**
 * @return array{status:string,path:?string}
 */
function resolve_safe_stored_image_path($path, ?string $root = null, ?string $scope = null): array {
    $isProduct = ($scope === null || $scope === 'products') && is_safe_product_image_path($path);
    $isSetting = ($scope === null || $scope === 'settings') && is_safe_settings_image_path($path);
    if (!$isProduct && !$isSetting) return ['status' => 'unsafe_path', 'path' => null];

    $directory = $isProduct ? product_image_directory($root) : settings_image_directory($root);
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
    if (!is_safe_product_image_path($path)) return 'unsafe_path';
    return delete_unreferenced_image($pdo, $path, $root, $deleteFile);
}

function delete_unreferenced_image(PDO $pdo, $path, ?string $root = null, ?callable $deleteFile = null): string {
    if ($pdo->inTransaction()) throw new LogicException('Image deletion requires a committed database transaction.');

    $resolved = resolve_safe_stored_image_path($path, $root);
    if ($resolved['status'] !== 'resolved') return $resolved['status'];
    $check = $pdo->prepare(
        'SELECT (SELECT COUNT(*) FROM products WHERE image = ?) + '
        . '(SELECT COUNT(*) FROM product_images WHERE image_path = ?) + '
        . '(SELECT COUNT(*) FROM store_settings WHERE setting_value = ?)'
    );
    $check->execute([$path, $path, $path]);
    if ((int) $check->fetchColumn() > 0) return 'still_referenced';

    if (!remove_image_file($resolved['path'], $deleteFile)) {
        error_log('Could not delete unreferenced product image.');
        return 'deletion_failed';
    }
    return 'deleted';
}

/**
 * Removes files created by the current failed operation. These paths are never
 * taken from request data or from pre-existing database records.
 *
 * @return array<string,string>
 */
function cleanup_stored_images(array $paths, ?string $root = null, ?callable $deleteFile = null): array {
    $results = [];
    foreach (array_unique($paths) as $path) {
        if (!is_string($path)) continue;
        $resolved = resolve_safe_stored_image_path($path, $root);
        if ($resolved['status'] !== 'resolved') {
            $results[$path] = $resolved['status'];
            continue;
        }
        $results[$path] = remove_image_file($resolved['path'], $deleteFile) ? 'deleted' : 'deletion_failed';
    }
    return $results;
}
function normalize_upload_batch(array $files): array {
    $batch = [];
    foreach (($files['name'] ?? []) as $i => $name) {
        $batch[] = ['name'=>$name, 'tmp_name'=>$files['tmp_name'][$i] ?? '', 'error'=>$files['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size'=>$files['size'][$i] ?? 0];
    }
    return $batch;
}
function store_image_batch(array $uploads, string $scope, ?string $root = null, ?callable $moveFile = null, ?callable $deleteFile = null): array {
    $created = [];
    try {
        foreach ($uploads as $upload) {
            $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
            if ($error === UPLOAD_ERR_NO_FILE) continue;
            if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('Falló una imagen del lote.');
            $created[] = store_safe_image($upload['tmp_name'] ?? '', $error, $upload['size'] ?? 0, 'assets/images/' . $scope, $root, $moveFile, $deleteFile);
        }
        return $created;
    } catch (Throwable $e) {
        cleanup_stored_images($created, $root, $deleteFile);
        throw $e;
    }
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
 * Persists settings and their two managed backgrounds as one database/file
 * lifecycle. Newly moved files are removed on rollback; replaced files are
 * considered for deletion only after commit.
 *
 * @param array<string,mixed> $values
 * @param array<string,array<string,mixed>> $uploads
 * @param array<string,bool> $remove
 * @return array{backgrounds:array<string,string>,cleanup:array<string,string>}
 */
function save_settings_with_images(
    PDO $pdo,
    array $values,
    array $uploads = [],
    array $remove = [],
    ?string $root = null,
    ?callable $moveFile = null,
    ?callable $deleteFile = null
): array {
    $backgroundKeys = ['hero_background', 'body_background'];
    $newPaths = [];
    $oldPaths = [];
    $backgrounds = [];

    $pdo->beginTransaction();
    try {
        $select = $pdo->prepare('SELECT setting_value FROM store_settings WHERE setting_key = ? FOR UPDATE');
        foreach ($backgroundKeys as $key) {
            $select->execute([$key]);
            $old = $select->fetchColumn();
            $old = is_string($old) ? $old : '';
            $oldPaths[$key] = $old;
            $upload = $uploads[$key] ?? null;
            $hasUpload = is_array($upload) && !empty($upload['name']);
            if ($hasUpload) {
                $backgrounds[$key] = store_safe_image(
                    $upload['tmp_name'] ?? '',
                    $upload['error'] ?? UPLOAD_ERR_NO_FILE,
                    $upload['size'] ?? 0,
                    'assets/images/settings',
                    $root,
                    $moveFile,
                    $deleteFile
                );
                $newPaths[] = $backgrounds[$key];
            } elseif (!empty($remove[$key])) {
                $backgrounds[$key] = '';
            } else {
                $backgrounds[$key] = $old;
            }
        }

        $upsert = $pdo->prepare(
            'INSERT INTO store_settings (setting_key, setting_value) VALUES (?, ?) '
            . 'ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        );
        foreach (array_merge($values, $backgrounds) as $key => $value) {
            $upsert->execute([(string) $key, (string) $value]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        cleanup_stored_images($newPaths, $root, $deleteFile);
        throw $e;
    }

    $cleanup = [];
    foreach ($backgroundKeys as $key) {
        $old = $oldPaths[$key];
        if ($old !== '' && $old !== $backgrounds[$key]) {
            $cleanup[$old] = delete_unreferenced_image($pdo, $old, $root, $deleteFile);
        }
    }
    return ['backgrounds' => $backgrounds, 'cleanup' => $cleanup];
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
