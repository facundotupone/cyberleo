<?php
require_once 'config.php';

if (DB_HOST === '' || DB_USER === '' || DB_NAME === '') {
    http_response_code(503);
    error_log('Database configuration is incomplete.');
    exit('<h2 style="text-align:center;margin-top:2em">El servicio no está configurado. Intente más tarde.</h2>');
}
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME,
        DB_USER,
        DB_PASS,
        array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8")
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    error_log('DB Connection Error: ' . $e->getMessage());
    http_response_code(503);
    echo "<h2 style='color:#c00;text-align:center;margin-top:2em'>No se pudo conectar al servicio. Intente más tarde.</h2>";
    exit;
}
?>