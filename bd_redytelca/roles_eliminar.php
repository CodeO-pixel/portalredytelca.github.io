<?php
header('Content-Type: application/json; charset=utf-8');
require 'conexion.php';

/**
 * FASE DE CORRECCIONES (post Fase 2): endpoint faltante. Un rol con
 * usuarios activos asignados (usuarios.id_rol, FK NOT NULL sin ON DELETE)
 * no puede eliminarse sin romper integridad referencial ni dejar staff
 * sin rol válido — se bloquea explícitamente en vez de intentar
 * reasignar automáticamente, porque decidir a qué otro rol migran esos
 * usuarios es una decisión de negocio que corresponde al Administrador,
 * no algo que este endpoint deba inferir.
 *
 * Las asignaciones en rol_modulo_pagina sí se eliminan en cascada manual
 * (mismo patrón de limpieza explícita que el resto del proyecto, sin
 * ON DELETE CASCADE en el DDL), porque esas filas no tienen sentido de
 * existir para un rol que ya no existe.
 *
 * Salvaguarda: el rol Administrador (id_rol=1) nunca se puede eliminar,
 * es la columna vertebral del RBAC y su ausencia dejaría al sistema sin
 * forma de re-otorgar acceso total.
 */

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$idRol = isset($input['id_rol']) ? (int) $input['id_rol'] : (isset($_GET['id']) ? (int) $_GET['id'] : 0);

if ($idRol <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'No se recibió el identificador del rol']);
    exit;
}

if ($idRol === 1) {
    echo json_encode(['status' => 'error', 'message' => 'El rol Administrador no puede eliminarse: es la base del control de accesos del sistema.']);
    exit;
}

$pdo->beginTransaction();
try {
    $stmtExists = $pdo->prepare("SELECT id_rol FROM rol WHERE id_rol = ?");
    $stmtExists->execute([$idRol]);
    if (!$stmtExists->fetch()) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Este rol ya no existe (pudo haber sido eliminado por otra sesión).']);
        exit;
    }

    $stmtUsuariosConRol = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE id_rol = ?");
    $stmtUsuariosConRol->execute([$idRol]);
    $totalUsuarios = (int) $stmtUsuariosConRol->fetchColumn();

    if ($totalUsuarios > 0) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => "No se puede eliminar: hay {$totalUsuarios} usuario(s) con este rol asignado. Reasígnalos a otro rol antes de eliminarlo."]);
        exit;
    }

    $stmtDelAsignaciones = $pdo->prepare("DELETE FROM rol_modulo_pagina WHERE id_rol = ?");
    $stmtDelAsignaciones->execute([$idRol]);

    $stmtDelRol = $pdo->prepare("DELETE FROM rol WHERE id_rol = ?");
    $stmtDelRol->execute([$idRol]);
    $ok = $stmtDelRol->rowCount() > 0;

    $pdo->commit();
    echo json_encode([
        'status' => $ok ? 'success' : 'error',
        'message' => $ok ? 'Rol eliminado correctamente' : 'No se pudo eliminar el rol'
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => 'Error al eliminar el rol: ' . $e->getMessage()]);
}