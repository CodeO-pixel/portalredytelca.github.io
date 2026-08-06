<?php
header('Content-Type: application/json; charset=utf-8');
require 'conexion.php';

/**
 * FASE DE CORRECCIONES (post Fase 2): endpoint faltante. La eliminación
 * física de un usuario choca con 4 FKs sin ON DELETE (tareas.id_usuario_creador,
 * tickets.id_usuario_creador, pagos.id_usuario_valido, sesiones.id_usuario),
 * por lo que un DELETE directo fallaría con SQLSTATE[23000] en cuanto el
 * usuario tuviera cualquier historial. Se aplica el mismo patrón de
 * desvinculación manual ya usado en cliente_eliminar.php: las referencias
 * opcionales (creador de tarea/ticket, validador de pago) se ponen en
 * NULL en vez de bloquear el borrado, preservando el registro histórico
 * de la tarea/ticket/pago en sí. Las sesiones activas del usuario sí se
 * eliminan físicamente, ya que no tiene sentido conservar una sesión de
 * un usuario que ya no existe.
 */

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$idUsuario = isset($input['id_usuario']) ? (int) $input['id_usuario'] : 0;

if ($idUsuario <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'No se recibió el identificador del usuario']);
    exit;
}

$pdo->beginTransaction();
try {
    $stmtCheck = $pdo->prepare("SELECT id_usuario, id_rol FROM usuarios WHERE id_usuario = ?");
    $stmtCheck->execute([$idUsuario]);
    $usuario = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$usuario) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Este usuario ya no existe (pudo haber sido eliminado por otra sesión).']);
        exit;
    }

    // Salvaguarda: nunca eliminar al último Administrador del sistema,
    // igual que la regla espejo aplicada en usuarios_editar.php.
    if ((int) $usuario['id_rol'] === 1) {
        $stmtOtrosAdmins = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE id_rol = 1 AND id_usuario <> ?");
        $stmtOtrosAdmins->execute([$idUsuario]);
        if ((int) $stmtOtrosAdmins->fetchColumn() === 0) {
            $pdo->rollBack();
            echo json_encode(['status' => 'error', 'message' => 'No puedes eliminar a este usuario: es el único administrador del sistema. Crea otro administrador antes de eliminarlo.']);
            exit;
        }
    }

    $stmtUnlinkTareas = $pdo->prepare("UPDATE tareas SET id_usuario_creador = NULL WHERE id_usuario_creador = ?");
    $stmtUnlinkTareas->execute([$idUsuario]);

    $stmtUnlinkTickets = $pdo->prepare("UPDATE tickets SET id_usuario_creador = NULL WHERE id_usuario_creador = ?");
    $stmtUnlinkTickets->execute([$idUsuario]);

    $stmtUnlinkPagos = $pdo->prepare("UPDATE pagos SET id_usuario_valido = NULL WHERE id_usuario_valido = ?");
    $stmtUnlinkPagos->execute([$idUsuario]);

    $stmtDelSesiones = $pdo->prepare("DELETE FROM sesiones WHERE id_usuario = ?");
    $stmtDelSesiones->execute([$idUsuario]);

    $stmtDelUsuario = $pdo->prepare("DELETE FROM usuarios WHERE id_usuario = ?");
    $stmtDelUsuario->execute([$idUsuario]);
    $ok = $stmtDelUsuario->rowCount() > 0;

    $pdo->commit();
    echo json_encode([
        'status' => $ok ? 'success' : 'error',
        'message' => $ok ? 'Usuario eliminado correctamente' : 'No se pudo eliminar el usuario'
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => 'Error al eliminar el usuario: ' . $e->getMessage()]);
}