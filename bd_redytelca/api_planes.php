<?php
header('Content-Type: application/json; charset=utf-8');
require 'conexion.php';

$method = $_SERVER['REQUEST_METHOD'];

/**
 * Salvaguarda mínima (no mecanismo primario): `planes` ya vive en el dump
 * vigente con la fila semilla id_plan=1 "Plan Básico". Este bloque solo
 * protege instalaciones que aún no corrieron el dump actual.
 */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS planes (
        id_plan INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(60) NOT NULL,
        velocidad VARCHAR(60) NOT NULL,
        precio_mensual DECIMAL(10,2) NOT NULL,
        moneda VARCHAR(10) NOT NULL DEFAULT 'USD'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT IGNORE INTO planes (id_plan, nombre, velocidad, precio_mensual, moneda)
                VALUES (1, 'Plan Básico', '10 Mbps', 15.00, 'USD')");
} catch (Exception $e) {
}

if ($method === 'GET') {
    // total_servicios permite que la UI advierta antes de un DELETE que
    // rompería integridad referencial, igual que total_naps en api_olts.php.
    $stmt = $pdo->query("SELECT p.*,
                                (SELECT COUNT(*) FROM servicios s WHERE s.id_plan = p.id_plan) AS total_servicios
                         FROM planes p
                         ORDER BY p.precio_mensual ASC");
    echo json_encode(['status' => 'success', 'planes' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

if ($method === 'POST') {
    $nombre = trim($input['nombre'] ?? '');
    $velocidad = trim($input['velocidad'] ?? '');
    $precio_mensual = isset($input['precio_mensual']) && $input['precio_mensual'] !== '' ? (float)$input['precio_mensual'] : null;
    $moneda = trim($input['moneda'] ?? 'USD');

    if ($nombre === '' || $velocidad === '' || $precio_mensual === null || $precio_mensual <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Nombre, velocidad y precio mensual son obligatorios']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO planes (nombre, velocidad, precio_mensual, moneda) VALUES (?, ?, ?, ?)");
        $ok = $stmt->execute([$nombre, $velocidad, $precio_mensual, $moneda ?: 'USD']);
        echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'Plan creado correctamente' : 'No se pudo crear el plan']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Error creando el plan: ' . $e->getMessage()]);
    }
    exit;
}

if ($method === 'PUT') {
    $id = (int)($input['id_plan'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Plan inválido']);
        exit;
    }

    $nombre = trim($input['nombre'] ?? '');
    $velocidad = trim($input['velocidad'] ?? '');
    $precio_mensual = isset($input['precio_mensual']) && $input['precio_mensual'] !== '' ? (float)$input['precio_mensual'] : null;
    $moneda = trim($input['moneda'] ?? 'USD');

    if ($nombre === '' || $velocidad === '' || $precio_mensual === null || $precio_mensual <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Nombre, velocidad y precio mensual son obligatorios']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("UPDATE planes SET nombre = ?, velocidad = ?, precio_mensual = ?, moneda = ? WHERE id_plan = ?");
        $ok = $stmt->execute([$nombre, $velocidad, $precio_mensual, $moneda ?: 'USD', $id]);
        echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'Plan actualizado' : 'No se pudo actualizar']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Error actualizando el plan: ' . $e->getMessage()]);
    }
    exit;
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Plan inválido']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM planes WHERE id_plan = ?");
        $ok = $stmt->execute([$id]);
        echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'Plan eliminado' : 'No se pudo eliminar']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'No se puede eliminar: este plan tiene servicios asociados. Reasígnalos primero.']);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Método no soportado']);