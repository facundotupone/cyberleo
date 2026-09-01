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
function is_safe_upload_path($path) {
    $base = realpath(dirname(__DIR__) . '/assets/images');
    $real = is_string($path) ? realpath(dirname(__DIR__) . '/' . ltrim($path, '/')) : false;
    return $real && $base && is_file($real) && !is_link($real) && str_starts_with($real, $base . DIRECTORY_SEPARATOR);
}
function is_safe_settings_image_path($path) {
    return is_string($path) && preg_match('#^assets/images/settings/[a-f0-9]{32}\.(?:jpe?g|png|webp)$#i', $path);
}
function delete_image_if_unreferenced(PDO $pdo, $path) {
    if ($pdo->inTransaction()) throw new LogicException('Image deletion requires a committed database transaction.');
    if (!is_safe_product_image_path($path) && !is_safe_settings_image_path($path)) return 'unsafe_path';
    if (!is_safe_upload_path($path)) return 'unsafe_path';
    $check = $pdo->prepare('SELECT (SELECT COUNT(*) FROM products WHERE image=?) + (SELECT COUNT(*) FROM product_images WHERE image_path=?) + (SELECT COUNT(*) FROM store_settings WHERE setting_value=?)');
    $check->execute([$path, $path, $path]);
    if ((int)$check->fetchColumn() > 0) return 'still_referenced';
    $full = realpath(dirname(__DIR__) . '/' . $path);
    if (!$full || !is_file($full) || is_link($full)) return 'missing_file';
    if (!@unlink($full)) { error_log('Could not delete unreferenced image.'); return 'deletion_failed'; }
    return 'deleted';
}
function is_safe_product_image_path($path) {
    return is_string($path) && preg_match('#^assets/images/products/(?:[a-f0-9]{13}|[a-f0-9]{32})\.(?:jpe?g|png|webp)$#i', $path);
}
