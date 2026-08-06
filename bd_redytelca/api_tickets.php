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
    $pdo->exec("CREATE TABLE IF NOT EXISTS tickets (
        id_ticket INT AUTO_INCREMENT PRIMARY KEY,
        asunto VARCHAR(150) NOT NULL,
        descripcion TEXT NULL,
        estado VARCHAR(30) NOT NULL DEFAULT 'Abierto',
        prioridad VARCHAR(20) NOT NULL DEFAULT 'Media',
        id_cliente INT NULL,
        id_usuario_creador INT NULL,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        actualizado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("ALTER TABLE `tickets` MODIFY COLUMN `estado` VARCHAR(30) NOT NULL DEFAULT 'Abierto'");
    $pdo->exec("ALTER TABLE `tickets` MODIFY COLUMN `id_cliente` INT NULL");
    $pdo->exec("ALTER TABLE `tickets` MODIFY COLUMN `id_servicio` INT NULL");
    $pdo->exec("ALTER TABLE `tickets` MODIFY COLUMN `descripcion` TEXT NULL");
    $pdo->exec("ALTER TABLE `tickets` ADD COLUMN IF NOT EXISTS `prioridad` VARCHAR(20) NOT NULL DEFAULT 'Media'");
    $pdo->exec("ALTER TABLE `tickets` ADD COLUMN IF NOT EXISTS `id_usuario_creador` INT NULL");
    $pdo->exec("ALTER TABLE `tickets` ADD COLUMN IF NOT EXISTS `creado_en` TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    $pdo->exec("ALTER TABLE `tickets` ADD COLUMN IF NOT EXISTS `actualizado_en` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
} catch (Exception $e) {
}

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT t.*, c.nombres, c.apellidos, u.username AS creador FROM tickets t LEFT JOIN clientes c ON c.id_cliente = t.id_cliente LEFT JOIN usuarios u ON u.id_usuario = t.id_usuario_creador ORDER BY t.creado_en DESC");
    echo json_encode(['status' => 'success', 'tickets' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

if ($method === 'POST') {
    $asunto = trim($input['asunto'] ?? '');
    $descripcion = trim($input['descripcion'] ?? '');
    $estado = trim($input['estado'] ?? 'Abierto');
    $prioridad = trim($input['prioridad'] ?? 'Media');
    $id_cliente = (isset($input['id_cliente']) && $input['id_cliente'] !== '' && $input['id_cliente'] !== null) ? (int)$input['id_cliente'] : null;
    $id_usuario_creador = (isset($input['id_usuario_creador']) && $input['id_usuario_creador'] !== '' && $input['id_usuario_creador'] !== null) ? (int)$input['id_usuario_creador'] : null;

    if ($asunto === '') {
        echo json_encode(['status' => 'error', 'message' => 'El asunto es obligatorio']);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO tickets (asunto, descripcion, estado, prioridad, id_cliente, id_usuario_creador) VALUES (?, ?, ?, ?, ?, ?)");
    $ok = $stmt->execute([$asunto, $descripcion, $estado, $prioridad, $id_cliente, $id_usuario_creador]);

    echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'Ticket creado correctamente' : 'No se pudo crear el ticket']);
    exit;
}

if ($method === 'PUT') {
    $id = (int)($input['id_ticket'] ?? 0);
    $stmt = $pdo->prepare("UPDATE tickets SET asunto = ?, descripcion = ?, estado = ?, prioridad = ?, id_cliente = ?, id_usuario_creador = ? WHERE id_ticket = ?");
    $ok = $stmt->execute([
        trim($input['asunto'] ?? ''),
        trim($input['descripcion'] ?? ''),
        trim($input['estado'] ?? 'Abierto'),
        trim($input['prioridad'] ?? 'Media'),
        (isset($input['id_cliente']) && $input['id_cliente'] !== '' && $input['id_cliente'] !== null) ? (int)$input['id_cliente'] : null,
        (isset($input['id_usuario_creador']) && $input['id_usuario_creador'] !== '' && $input['id_usuario_creador'] !== null) ? (int)$input['id_usuario_creador'] : null,
        $id
    ]);
    echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'Ticket actualizado' : 'No se pudo actualizar']);
    exit;
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    $stmt = $pdo->prepare("DELETE FROM tickets WHERE id_ticket = ?");
    $ok = $stmt->execute([$id]);
    echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'Ticket eliminado' : 'No se pudo eliminar']);
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Método no soportado']);
