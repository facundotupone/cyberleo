<?php
require_once 'config.php';

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME,
        DB_USER,
        DB_PASS,
        array(PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8")
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    // En producción, no mostrar detalles de error al usuario
    if (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false) {
        echo "Error de conexión: " . $e->getMessage();
    } else {
        error_log('DB Connection Error: ' . $e->getMessage());
        echo "<h2 style='color:#c00;text-align:center;margin-top:2em'>No se pudo conectar a la base de datos. Intente más tarde.</h2>";
    }
    exit;
}
?>