<?php
header('Content-Type: application/json; charset=utf-8');
require 'conexion.php';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `rol` (
        `id_rol` INT(11) NOT NULL AUTO_INCREMENT,
        `nombre_rol` VARCHAR(50) NOT NULL,
        PRIMARY KEY (`id_rol`),
        UNIQUE KEY `nombre_rol` (`nombre_rol`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    // Si ya existe, se continúa con la operación solicitada.
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }

    $nombre = isset($input['nombre_rol']) ? trim($input['nombre_rol']) : '';
    if ($nombre === '') {
        echo json_encode(['status' => 'error', 'message' => 'El nombre del rol es obligatorio']);
        exit;
    }

    $stmtCheck = $pdo->prepare('SELECT id_rol FROM rol WHERE nombre_rol = ?');
    $stmtCheck->execute([$nombre]);
    if ($stmtCheck->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Ya existe un rol con ese nombre']);
        exit;
    }

    try {
        $stmt = $pdo->prepare('INSERT INTO rol (nombre_rol) VALUES (?)');
        $ok = $stmt->execute([$nombre]);
        echo json_encode([
            'status' => $ok ? 'success' : 'error',
            'message' => $ok ? 'Rol creado correctamente' : 'No se pudo crear el rol',
            'id_rol' => $ok ? (int) $pdo->lastInsertId() : null
        ]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Error creando el rol: ' . $e->getMessage()]);
    }
    exit;
}

// GET: listar roles (con semilla mínima si la tabla llegara vacía)
$stmt = $pdo->query("SELECT * FROM rol ORDER BY id_rol");
$roles = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($roles)) {
    $defaultRoles = [
        ['nombre_rol' => 'Administrador'],
        ['nombre_rol' => 'Operador']
    ];
    $stmtInsert = $pdo->prepare("INSERT INTO rol (nombre_rol) VALUES (?)");
    foreach ($defaultRoles as $rol) {
        $stmtInsert->execute([$rol['nombre_rol']]);
    }
    $stmt = $pdo->query("SELECT * FROM rol ORDER BY id_rol");
    $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

echo json_encode(["status" => "success", "roles" => $roles]);