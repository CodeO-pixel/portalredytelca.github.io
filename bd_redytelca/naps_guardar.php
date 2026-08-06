<?php
header('Content-Type: application/json; charset=utf-8');
require 'conexion.php';

$input = file_get_contents('php://input');
$data = json_decode($input, true);
if (!$data) { $data = $_POST; }

if (empty($data['nombre']) || !isset($data['lat']) || !isset($data['lng'])) {
    echo json_encode(['status' => 'error', 'message' => 'Datos incompletos']);
    exit;
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS naps (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(150) NOT NULL,
        lat DECIMAL(10,6) NOT NULL,
        lng DECIMAL(10,6) NOT NULL,
        fecha_registro TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stmt = $pdo->prepare("INSERT INTO naps (nombre, lat, lng) VALUES (?, ?, ?)");
    $ok = $stmt->execute([$data['nombre'], $data['lat'], $data['lng']]);
    echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'NAP guardada' : 'Error al guardar']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>