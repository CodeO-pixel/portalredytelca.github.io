<?php
// Authentication helper for permission checks
if (session_status() === PHP_SESSION_NONE) session_start();

function has_permission($pdo, $perm_name) {
    if (!isset($_SESSION['id_rol'])) return false;
    $id_rol = intval($_SESSION['id_rol']);
    $stmt = $pdo->prepare('SELECT 1 FROM role_permission rp JOIN permissions p ON rp.id_permiso = p.id_permiso WHERE rp.id_rol = ? AND p.nombre_permiso = ? LIMIT 1');
    $stmt->execute([$id_rol, $perm_name]);
    return (bool) $stmt->fetchColumn();
}

function require_permission($pdo, $perm_name) {
    if (!isset($_SESSION['id_rol'])) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'No autenticado']);
        exit;
    }
    if (!has_permission($pdo, $perm_name)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'message' => 'No tiene permisos suficientes']);
        exit;
    }
}
?>