<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../conexion.php';
require __DIR__ . '/../includes/auth.php';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // Require authentication for listing
        if (!isset($_SESSION['id_rol'])) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'No autenticado']);
            exit;
        }
        $stmt = $pdo->query('SELECT id_rol, nombre_rol, descripcion FROM roles ORDER BY id_rol');
        $roles = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'roles' => $roles]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Only users with roles.manage can create roles
        require_permission($pdo, 'roles.manage');

        $input = json_decode(file_get_contents('php://input'), true);
        $nombre = isset($input['nombre_rol']) ? trim($input['nombre_rol']) : '';
        $descripcion = isset($input['descripcion']) ? trim($input['descripcion']) : null;

        if ($nombre === '') {
            echo json_encode(['status' => 'error', 'message' => 'nombre_rol es requerido']);
            exit;
        }

        $stmt = $pdo->prepare('INSERT INTO roles (nombre_rol, descripcion) VALUES (?, ?)');
        $stmt->execute([$nombre, $descripcion]);
        echo json_encode(['status' => 'success', 'message' => 'Rol creado', 'id' => $pdo->lastInsertId()]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
