<?php
require_once 'includes/config.php';
require_once 'includes/db.php';

$subcat_id = isset($_GET['subcat_id']) ? intval($_GET['subcat_id']) : 0;
$aromas = [];

if ($subcat_id) {
    $stmt = $pdo->prepare("SELECT a.id, a.nombre FROM aroma_subcat ascat INNER JOIN aromas a ON ascat.aroma_id = a.id WHERE ascat.subcat_id = ?");
    $stmt->execute([$subcat_id]);
    $aromas = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

header('Content-Type: application/json');
echo json_encode($aromas);