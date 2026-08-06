<?php
header('Content-Type: application/json; charset=utf-8');
require 'conexion.php';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS naps (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(150) NOT NULL,
        lat DECIMAL(10,6) NOT NULL,
        lng DECIMAL(10,6) NOT NULL,
        fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stmt = $pdo->query("SELECT id, nombre, lat, lng, fecha_registro FROM naps ORDER BY fecha_registro DESC");
    $naps = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['status' => 'success', 'naps' => $naps]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>