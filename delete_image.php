<?php
require_once 'includes/config.php';
require_once 'includes/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['image_id'])) {
    $image_id = intval($_POST['image_id']);
    
    // Obtener la ruta de la imagen antes de eliminar
    $stmt = $pdo->prepare("SELECT image_path FROM product_images WHERE id = ?");
    $stmt->execute([$image_id]);
    $image = $stmt->fetch();
    
    if ($image) {
        // Eliminar de la base de datos
        $stmt = $pdo->prepare("DELETE FROM product_images WHERE id = ?");
        $success = $stmt->execute([$image_id]);
        
        // Eliminar el archivo físico
        if ($success && file_exists($image['image_path'])) {
            unlink($image['image_path']);
        }
        
        echo json_encode(['success' => $success]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Imagen no encontrada']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Solicitud inválida']);
}