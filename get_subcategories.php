<?php
require_once 'includes/config.php';
require_once 'includes/db.php';

header('Content-Type: application/json');

if (isset($_GET['category_id'])) {
    $category_id = intval($_GET['category_id']);
    $stmt = $pdo->prepare("SELECT id, name FROM subcategories WHERE category_id = ? ORDER BY name");
    $stmt->execute([$category_id]);
    $subcategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($subcategories);
} else {
    echo json_encode([]);
}