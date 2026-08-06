<?php
header('Content-Type: application/json; charset=utf-8');
require 'conexion.php';

$method = $_SERVER['REQUEST_METHOD'];

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void {
    $columns = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array($column, $columns, true)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS nodos (
        id_nodo INT(11) NOT NULL AUTO_INCREMENT,
        nombre VARCHAR(100) NOT NULL,
        ubicacion VARCHAR(255) DEFAULT NULL,
        latitud DECIMAL(10,8) NOT NULL,
        longitud DECIMAL(11,8) NOT NULL,
        estado ENUM('activo','mantenimiento','inactivo') DEFAULT 'activo',
        PRIMARY KEY (id_nodo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    ensureColumn($pdo, 'nodos', 'estado', "ENUM('activo','mantenimiento','inactivo') DEFAULT 'activo'");
} catch (Exception $e) {
}

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT n.*, (SELECT COUNT(*) FROM olts o WHERE o.id_nodos = n.id_nodo) AS total_olts
                         FROM nodos n ORDER BY n.nombre ASC");
    echo json_encode(['status' => 'success', 'nodos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

if ($method === 'POST') {
    $nombre = trim($input['nombre'] ?? '');
    $ubicacion = trim($input['ubicacion'] ?? '');
    $latitudRaw = isset($input['latitud']) && $input['latitud'] !== '' ? $input['latitud'] : null;
    $longitudRaw = isset($input['longitud']) && $input['longitud'] !== '' ? $input['longitud'] : null;
    $latitud = $latitudRaw !== null ? str_replace(',', '.', (string) $latitudRaw) : null;
    $longitud = $longitudRaw !== null ? str_replace(',', '.', (string) $longitudRaw) : null;
    $estado = trim($input['estado'] ?? 'activo');

    if ($nombre === '' || $latitud === null || $longitud === null) {
        echo json_encode(['status' => 'error', 'message' => 'Nombre, latitud y longitud son obligatorios']);
        exit;
    }

    if (!is_numeric($latitud) || !is_numeric($longitud)) {
        echo json_encode(['status' => 'error', 'message' => 'La latitud/longitud debe ser un número válido (usa punto como separador decimal, ej. 10.4806).']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO nodos (nombre, ubicacion, latitud, longitud, estado) VALUES (?, ?, ?, ?, ?)");
    $ok = $stmt->execute([$nombre, $ubicacion, $latitud, $longitud, $estado]);

    echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'Nodo creado correctamente' : 'No se pudo crear el nodo']);
    exit;
}

if ($method === 'PUT') {
    $id = (int)($input['id_nodo'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Nodo inválido']);
        exit;
    }
    $latitudRaw = isset($input['latitud']) && $input['latitud'] !== '' ? $input['latitud'] : null;
    $longitudRaw = isset($input['longitud']) && $input['longitud'] !== '' ? $input['longitud'] : null;
    $latitud = $latitudRaw !== null ? str_replace(',', '.', (string) $latitudRaw) : null;
    $longitud = $longitudRaw !== null ? str_replace(',', '.', (string) $longitudRaw) : null;
    if ($latitudRaw !== null && !is_numeric($latitud)) {
        echo json_encode(['status' => 'error', 'message' => 'La latitud/longitud debe ser un número válido (usa punto como separador decimal, ej. 10.4806).']);
        exit;
    }
    if ($longitudRaw !== null && !is_numeric($longitud)) {
        echo json_encode(['status' => 'error', 'message' => 'La latitud/longitud debe ser un número válido (usa punto como separador decimal, ej. 10.4806).']);
        exit;
    }
    $stmt = $pdo->prepare("UPDATE nodos SET nombre = ?, ubicacion = ?, latitud = ?, longitud = ?, estado = ? WHERE id_nodo = ?");
    $ok = $stmt->execute([
        trim($input['nombre'] ?? ''),
        trim($input['ubicacion'] ?? ''),
        $latitud,
        $longitud,
        trim($input['estado'] ?? 'activo'),
        $id
    ]);
    echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'Nodo actualizado' : 'No se pudo actualizar']);
    exit;
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    try {
        $stmt = $pdo->prepare("DELETE FROM nodos WHERE id_nodo = ?");
        $ok = $stmt->execute([$id]);
        echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'Nodo eliminado' : 'No se pudo eliminar']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'No se puede eliminar: este nodo tiene OLTs asociadas. Elimina o reasigna esas OLTs primero.']);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Método no soportado']);