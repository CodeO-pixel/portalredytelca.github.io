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
    // Se auto-asegura también `nodos`: este endpoint puede ser el primero
    // en ejecutarse en un entorno limpio, antes que api_nodos.php.
    $pdo->exec("CREATE TABLE IF NOT EXISTS nodos (
        id_nodo INT(11) NOT NULL AUTO_INCREMENT,
        nombre VARCHAR(100) NOT NULL,
        ubicacion VARCHAR(255) DEFAULT NULL,
        latitud DECIMAL(10,8) NOT NULL,
        longitud DECIMAL(11,8) NOT NULL,
        estado ENUM('activo','mantenimiento','inactivo') DEFAULT 'activo',
        PRIMARY KEY (id_nodo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS olts (
        id_olt INT(11) NOT NULL AUTO_INCREMENT,
        marca_modelo VARCHAR(100) NOT NULL,
        puertos_pon TINYINT(4) NOT NULL DEFAULT 16,
        ip_gestion VARCHAR(45) DEFAULT NULL,
        id_nodos INT(11) NOT NULL,
        PRIMARY KEY (id_olt),
        KEY fk_olt_nodo (id_nodos),
        CONSTRAINT fk_olt_nodo FOREIGN KEY (id_nodos) REFERENCES nodos (id_nodo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 'codigo' no existe en el dump original (bd_redytelca.sql solo trae
    // marca_modelo/ip_gestion). Se agrega porque el resto del sistema
    // (dashboard, clientes.olt heredado) ya identifica OLTs por códigos
    // tipo "OLT-01", no por marca/modelo — sin esto no hay forma amigable
    // de referenciarlas desde la UI ni de migrar el dato viejo en Fase 2.
    ensureColumn($pdo, 'olts', 'codigo', "VARCHAR(20) DEFAULT NULL");
} catch (Exception $e) {
}

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT o.*, n.nombre AS nombre_nodo,
                                (SELECT COUNT(*) FROM naps na WHERE na.id_olts = o.id_olt) AS total_naps
                         FROM olts o
                         LEFT JOIN nodos n ON n.id_nodo = o.id_nodos
                         ORDER BY o.id_olt ASC");
    echo json_encode(['status' => 'success', 'olts' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

if ($method === 'POST') {
    $marca_modelo = trim($input['marca_modelo'] ?? '');
    $puertos_pon = isset($input['puertos_pon']) && $input['puertos_pon'] !== '' ? (int)$input['puertos_pon'] : 16;
    $ip_gestion = trim($input['ip_gestion'] ?? '');
    $id_nodos = (int)($input['id_nodos'] ?? 0);
    $codigo = trim($input['codigo'] ?? '');

    if ($marca_modelo === '' || $id_nodos <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Marca/modelo y nodo son obligatorios']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO olts (marca_modelo, puertos_pon, ip_gestion, id_nodos, codigo) VALUES (?, ?, ?, ?, ?)");
        $ok = $stmt->execute([$marca_modelo, $puertos_pon, $ip_gestion ?: null, $id_nodos, $codigo ?: null]);
        echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'OLT creada correctamente' : 'No se pudo crear la OLT']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Error creando la OLT: el nodo seleccionado no existe o la IP ya está en uso']);
    }
    exit;
}

if ($method === 'PUT') {
    $id = (int)($input['id_olt'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'OLT inválida']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("UPDATE olts SET marca_modelo = ?, puertos_pon = ?, ip_gestion = ?, id_nodos = ?, codigo = ? WHERE id_olt = ?");
        $ok = $stmt->execute([
            trim($input['marca_modelo'] ?? ''),
            isset($input['puertos_pon']) && $input['puertos_pon'] !== '' ? (int)$input['puertos_pon'] : 16,
            trim($input['ip_gestion'] ?? '') ?: null,
            (int)($input['id_nodos'] ?? 0),
            trim($input['codigo'] ?? '') ?: null,
            $id
        ]);
        echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'OLT actualizada' : 'No se pudo actualizar']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Error actualizando la OLT: verifica el nodo o la IP']);
    }
    exit;
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    try {
        $stmt = $pdo->prepare("DELETE FROM olts WHERE id_olt = ?");
        $ok = $stmt->execute([$id]);
        echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'OLT eliminada' : 'No se pudo eliminar']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'No se puede eliminar: esta OLT tiene NAPs asociadas. Elimina o reasigna esas NAPs primero.']);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Método no soportado']);