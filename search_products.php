<?php
require_once 'includes/config.php';
require_once 'includes/db.php';

header('Content-Type: application/json');

$search = isset($_GET['q']) ? trim($_GET['q']) : '';

if (empty($search)) {
    echo json_encode([]);
    exit;
}

$stmt = $pdo->prepare("SELECT p.*, c.name as category_name 
                       FROM products p 
                       LEFT JOIN categories c ON p.category_id = c.id 
                       WHERE p.name LIKE ? OR p.description LIKE ? 
                       LIMIT 5");
$searchTerm = "%{$search}%";
$stmt->execute([$searchTerm, $searchTerm]);
$results = $stmt->fetchAll();

echo json_encode($results);
?>