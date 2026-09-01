<?php
require_once 'includes/auth_check.php';
require_once 'includes/config.php';
require_once 'includes/db.php';
require_once 'includes/security.php';
require_once 'includes/images.php';

header('Content-Type: application/json; charset=UTF-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['image_id'])) {
    require_csrf(true);
    $image_id = filter_var($_POST['image_id'], FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (!$image_id) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Solicitud inválida']); exit; }
    try {
        $result = delete_product_image_record($pdo, $image_id);
        if ($result['status'] === 'not_found') {
            http_response_code(404);
            echo json_encode(['success'=>false,'message'=>'Imagen no encontrada']);
            exit;
        }
        if ($result['status'] !== 'deleted') throw new RuntimeException('Invalid image deletion state.');
        cleanup_product_images_after_commit($pdo, [$result['path']]);
        echo json_encode(['success'=>true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Image deletion failed: ' . $e->getMessage());
        http_response_code(500); echo json_encode(['success'=>false,'message'=>'No se pudo eliminar la imagen']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Solicitud inválida']);
}