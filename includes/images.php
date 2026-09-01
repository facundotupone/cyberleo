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
    $real = realpath($path); $base = realpath('assets/images');
    return $real && $base && str_starts_with($real, $base . DIRECTORY_SEPARATOR);
}
function is_safe_product_image_path($path) {
    return is_string($path) && preg_match('#^assets/images/products/(?:[a-f0-9]{13}|[a-f0-9]{32})\.(?:jpe?g|png|webp)$#i', $path);
}
