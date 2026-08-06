<?php
header('Content-Type: application/json; charset=utf-8');
require 'conexion.php';

/**
 * FASE 2 — MIGRACIÓN direcciones -> servicios (4º y último archivo afectado):
 *
 * Este endpoint seleccionaba `id_direccion FROM servicios` para luego
 * hacer `DELETE FROM direcciones WHERE id_direccion IN (...)`. Ambas
 * referencias son inválidas desde el DDL de Fase 0: `servicios.id_direccion`
 * ya no existe (ahora la dirección vive inline como `direccion_texto`,
 * `latitud_instalacion`, `longitud_instalacion`), y la tabla `direcciones`
 * fue eliminada. Esto producía SQLSTATE[42S22] al leer la columna, y el
 * catch() envolvente hacía rollback silencioso — el cliente nunca se
 * borraba y no había mensaje de error visible más allá del genérico.
 *
 * CORRECCIÓN: se elimina toda referencia a `id_direccion`/`direcciones`.
 * Como la dirección ahora es una columna más de `servicios`, al borrar
 * la fila de `servicios` (paso que ya existía) la dirección desaparece
 * junto con ella — no se necesita ningún DELETE adicional a una tabla
 * separada. El resto de la estrategia de borrado en cascada manual
 * (desvincular tickets/tareas, borrar pagos/notificaciones, borrar
 * servicios, borrar cliente) se mantiene intacta: esa lógica nunca
 * dependió de `direcciones`.
 */

try {
    $pdo->exec("ALTER TABLE `tickets` MODIFY COLUMN `id_cliente` INT NULL");
} catch (Exception $e) {
    // La tabla tickets no existe todavía o la columna ya es NULL-able.
}
try {
    $pdo->exec("ALTER TABLE `tickets` MODIFY COLUMN `id_servicio` INT NULL");
} catch (Exception $e) {
    // Idem.
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);
if (!$data) {
    $data = $_POST;
}

if (empty($data['id_cliente'])) {
    echo json_encode(['status' => 'error', 'message' => 'No se recibió el identificador del cliente']);
    exit;
}

$idCliente = (int) $data['id_cliente'];

$pdo->beginTransaction();
try {
    $stmtCheck = $pdo->prepare("SELECT id_cliente FROM clientes WHERE id_cliente = ?");
    $stmtCheck->execute([$idCliente]);
    if (!$stmtCheck->fetch()) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Este cliente ya no existe (pudo haber sido eliminado por otra sesión).']);
        exit;
    }

    $stmtServicios = $pdo->prepare("SELECT id_servicio FROM servicios WHERE id_cliente = ?");
    $stmtServicios->execute([$idCliente]);
    $servicios = $stmtServicios->fetchAll(PDO::FETCH_ASSOC);

    $idsServicio = array_map(static function ($s) {
        return (int) $s['id_servicio'];
    }, $servicios);

    if (!empty($idsServicio)) {
        $placeholdersSrv = implode(',', array_fill(0, count($idsServicio), '?'));

        $stmtUnlinkTickets = $pdo->prepare(
            "UPDATE tickets SET id_cliente = NULL, id_servicio = NULL WHERE id_cliente = ? OR id_servicio IN ($placeholdersSrv)"
        );
        $stmtUnlinkTickets->execute(array_merge([$idCliente], $idsServicio));

        $stmtDelPagos = $pdo->prepare(
            "DELETE FROM pagos WHERE id_cliente = ? OR id_servicio IN ($placeholdersSrv)"
        );
        $stmtDelPagos->execute(array_merge([$idCliente], $idsServicio));
    } else {
        $stmtUnlinkTickets = $pdo->prepare(
            "UPDATE tickets SET id_cliente = NULL, id_servicio = NULL WHERE id_cliente = ?"
        );
        $stmtUnlinkTickets->execute([$idCliente]);

        $stmtDelPagos = $pdo->prepare("DELETE FROM pagos WHERE id_cliente = ?");
        $stmtDelPagos->execute([$idCliente]);
    }

    $stmtUnlinkTareas = $pdo->prepare("UPDATE tareas SET id_cliente = NULL WHERE id_cliente = ?");
    $stmtUnlinkTareas->execute([$idCliente]);

    $stmtDelNotif = $pdo->prepare("DELETE FROM notificaciones WHERE id_cliente = ?");
    $stmtDelNotif->execute([$idCliente]);

    // Al borrar la fila de servicios se elimina también la dirección
    // (direccion_texto/latitud_instalacion/longitud_instalacion viven
    // en esta misma tabla desde Fase 0) — no hay tabla separada que limpiar.
    $stmtDelServicios = $pdo->prepare("DELETE FROM servicios WHERE id_cliente = ?");
    $stmtDelServicios->execute([$idCliente]);

    $stmt = $pdo->prepare('DELETE FROM clientes WHERE id_cliente = ?');
    $stmt->execute([$idCliente]);
    $ok = $stmt->rowCount() > 0;

    $pdo->commit();
    echo json_encode([
        'status' => $ok ? 'success' : 'error',
        'message' => $ok ? 'Cliente eliminado correctamente' : 'No se pudo eliminar el cliente'
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['status' => 'error', 'message' => 'Error al eliminar el cliente: ' . $e->getMessage()]);
}