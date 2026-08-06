<?php
header('Content-Type: application/json; charset=utf-8');
require 'conexion.php';

/**
 * FASE DE CORRECCIONES (post Fase 2): endpoint faltante. roles_listar.php
 * ya soportaba creación (POST) y listado (GET), pero no edición del
 * nombre de un rol existente. Se restringe a `nombre_rol` porque es el
 * único atributo propio de la tabla `rol` — la asignación de
 * módulos/vistas ya tiene su propio endpoint dedicado (permisos_guardar.php)
 * y no se duplica aquí.
 */

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$idRol = isset($input['id_rol']) ? (int) $input['id_rol'] : 0;
$nombreRol = trim($input['nombre_rol'] ?? '');

if ($idRol <= 0 || $nombreRol === '') {
    echo json_encode(['status' => 'error', 'message' => 'El rol y el nuevo nombre son obligatorios']);
    exit;
}

try {
    $stmtExists = $pdo->prepare("SELECT id_rol FROM rol WHERE id_rol = ?");
    $stmtExists->execute([$idRol]);
    if (!$stmtExists->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Este rol ya no existe (pudo haber sido eliminado). Actualiza la lista e intenta de nuevo.']);
        exit;
    }

    $stmtDup = $pdo->prepare("SELECT id_rol FROM rol WHERE nombre_rol = ? AND id_rol <> ?");
    $stmtDup->execute([$nombreRol, $idRol]);
    if ($stmtDup->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Ya existe otro rol con ese nombre.']);
        exit;
    }

    $stmtUpdate = $pdo->prepare("UPDATE rol SET nombre_rol = ? WHERE id_rol = ?");
    $ok = $stmtUpdate->execute([$nombreRol, $idRol]);

    echo json_encode([
        'status' => $ok ? 'success' : 'error',
        'message' => $ok ? 'Rol actualizado correctamente' : 'No se pudo actualizar el rol'
    ]);
} catch (PDOException $e) {
    $sqlCode = (int) ($e->errorInfo[1] ?? 0);
    if ($sqlCode === 1062) {
        echo json_encode(['status' => 'error', 'message' => 'Ya existe otro rol con ese nombre.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Error actualizando el rol: ' . $e->getMessage()]);
    }
}