<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../conexion.php';
require __DIR__ . '/../includes/auth.php';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $id_rol = isset($_GET['id_rol']) ? intval($_GET['id_rol']) : 0;
        if (!$id_rol) {
            echo json_encode(['status' => 'error', 'message' => 'id_rol es requerido']);
            exit;
        }
        if (!isset($_SESSION['id_rol'])) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'No autenticado']);
            exit;
        }
        $stmt = $pdo->prepare('SELECT p.id_permiso, p.nombre_permiso FROM permissions p JOIN role_permission rp ON p.id_permiso = rp.id_permiso WHERE rp.id_rol = ?');
        $stmt->execute([$id_rol]);
        $perms = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'permisos' => $perms]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Only manage permissions with roles.manage
        require_permission($pdo, 'roles.manage');

        $input = json_decode(file_get_contents('php://input'), true);
        $id_rol = isset($input['id_rol']) ? intval($input['id_rol']) : 0;
        $permisos = isset($input['permisos']) && is_array($input['permisos']) ? $input['permisos'] : [];

        if (!$id_rol) {
            echo json_encode(['status' => 'error', 'message' => 'id_rol es requerido']);
            exit;
        }

        // Simple replacement strategy: remove existing and insert new
        $pdo->beginTransaction();
        $stmtDel = $pdo->prepare('DELETE FROM role_permission WHERE id_rol = ?');
        $stmtDel->execute([$id_rol]);

        if (count($permisos) > 0) {
            $stmtIns = $pdo->prepare('INSERT INTO role_permission (id_rol, id_permiso) VALUES (?, ?)');
            foreach ($permisos as $permId) {
                $stmtIns->execute([$id_rol, intval($permId)]);
            }
        }
        $pdo->commit();

        echo json_encode(['status' => 'success', 'message' => 'Permisos actualizados']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Método no permitido']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
