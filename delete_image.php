<?php
require_once 'includes/auth_check.php';
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/security.php';
require_once 'includes/images.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['image_id'])) {
    require_csrf(true);
    $image_id = intval($_POST['image_id']);
    
    // Obtener la ruta de la imagen antes de eliminar
    $stmt = $pdo->prepare("SELECT product_id, image_path FROM product_images WHERE id = ?");
    $stmt->execute([$image_id]);
    $image = $stmt->fetch();
    
    if ($image) {
        // Eliminar de la base de datos
        $stmt = $pdo->prepare("DELETE FROM product_images WHERE id = ?");
        $success = $stmt->execute([$image_id]);
        
        // Eliminar el archivo físico
        if ($success && is_safe_upload_path($image['image_path'])) {
            unlink($image['image_path']);
        }
        if ($success) {
            $main = $pdo->prepare('SELECT image_path FROM product_images WHERE product_id = ? ORDER BY is_main DESC LIMIT 1');
            $main->execute([$image['product_id']]);
            $pdo->prepare('UPDATE products SET image = ? WHERE id = ?')->execute([$main->fetchColumn() ?: null, $image['product_id']]);
        }
        
        echo json_encode(['success' => $success]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Imagen no encontrada']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Solicitud inválida']);
}