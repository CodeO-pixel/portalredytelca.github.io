<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../conexion.php';
require __DIR__ . '/../includes/auth.php';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (!isset($_SESSION['id_rol'])) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'No autenticado']);
            exit;
        }
        $stmt = $pdo->query('SELECT id_permiso, nombre_permiso, descripcion FROM permissions ORDER BY id_permiso');
        $perms = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'permisos' => $perms]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        require_permission($pdo, 'roles.manage');

        $input = json_decode(file_get_contents('php://input'), true);
        $nombre = isset($input['nombre_permiso']) ? trim($input['nombre_permiso']) : '';
        $descripcion = isset($input['descripcion']) ? trim($input['descripcion']) : null;

        if ($nombre === '') {
            echo json_encode(['status' => 'error', 'message' => 'nombre_permiso es requerido']);
            exit;
        }

        $stmt = $pdo->prepare('INSERT INTO permissions (nombre_permiso, descripcion) VALUES (?, ?)');
        $stmt->execute([$nombre, $descripcion]);
        echo json_encode(['status' => 'success', 'message' => 'Permiso creado', 'id' => $pdo->lastInsertId()]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
