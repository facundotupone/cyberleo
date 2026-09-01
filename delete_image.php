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
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("SELECT product_id, image_path, is_main FROM product_images WHERE id = ? FOR UPDATE");
        $stmt->execute([$image_id]); $image = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$image) { $pdo->rollBack(); http_response_code(404); echo json_encode(['success'=>false,'message'=>'Imagen no encontrada']); exit; }
        $pdo->prepare('SELECT id FROM products WHERE id=? FOR UPDATE')->execute([$image['product_id']]);
        $pdo->prepare('DELETE FROM product_images WHERE id=?')->execute([$image_id]);
        $next = $pdo->prepare('SELECT id,image_path FROM product_images WHERE product_id=? ORDER BY id LIMIT 1');
        $next->execute([$image['product_id']]); $nextImage = $next->fetch(PDO::FETCH_ASSOC);
        if ($nextImage) {
            $pdo->prepare('UPDATE product_images SET is_main=0 WHERE product_id=?')->execute([$image['product_id']]);
            $pdo->prepare('UPDATE product_images SET is_main=1 WHERE id=?')->execute([$nextImage['id']]);
        }
        $pdo->prepare('UPDATE products SET image=? WHERE id=?')->execute([$nextImage['image_path'] ?? null, $image['product_id']]);
        $pdo->commit();
        delete_image_if_unreferenced($pdo, $image['image_path']);
        echo json_encode(['success'=>true]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Image deletion failed: ' . $e->getMessage());
        http_response_code(500); echo json_encode(['success'=>false,'message'=>'No se pudo eliminar la imagen']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Solicitud inválida']);
}