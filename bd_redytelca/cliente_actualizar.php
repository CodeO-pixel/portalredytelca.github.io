<?php
header('Content-Type: application/json; charset=utf-8');
require 'conexion.php';

/**
 * CORRECCIÓN FASE 2.5: este archivo tocaba `servicios` además de `clientes`,
 * asumiendo 1 cliente = 1 servicio (buscaba el servicio MÁS RECIENTE del
 * cliente y lo sobreescribía con lo que viniera en el formulario de
 * edición de cliente). Eso hacía imposible tener más de un servicio por
 * cliente y además corrompía silenciosamente el servicio "equivocado" si
 * el cliente tenía varios.
 *
 * Su responsabilidad se reduce ahora exclusivamente a la entidad
 * `clientes` (persona). La gestión de `servicios` (N por cliente) vive
 * en api_servicios.php.
 */

$input = file_get_contents('php://input');
$data = json_decode($input, true);
if (!$data) {
    $data = $_POST;
}

if (empty($data['id_cliente']) || empty($data['nombres']) || empty($data['apellidos']) || empty($data['cedula']) || empty($data['telefono']) || empty($data['correo'])) {
    echo json_encode(['status' => 'error', 'message' => 'Faltan datos obligatorios']);
    exit;
}

$idCliente = (int) $data['id_cliente'];
$cedula = trim($data['cedula']);

$stmtExists = $pdo->prepare("SELECT id_cliente FROM clientes WHERE id_cliente = ?");
$stmtExists->execute([$idCliente]);
if (!$stmtExists->fetch()) {
    echo json_encode(['status' => 'error', 'message' => 'Este cliente ya no existe en la base de datos (pudo haber sido eliminado). Actualiza la lista de clientes e intenta de nuevo.']);
    exit;
}

$stmtDup = $pdo->prepare("SELECT id_cliente FROM clientes WHERE cedula = ? AND id_cliente <> ?");
$stmtDup->execute([$cedula, $idCliente]);
if ($stmtDup->fetch()) {
    echo json_encode(['status' => 'error', 'message' => 'Ya existe otro cliente registrado con esa cédula.']);
    exit;
}

try {
    $stmtCliente = $pdo->prepare(
        "UPDATE clientes SET nombres = ?, apellidos = ?, cedula = ?, num_telefono = ?, correo = ? WHERE id_cliente = ?"
    );
    $stmtCliente->execute([
        $data['nombres'],
        $data['apellidos'],
        $cedula,
        $data['telefono'],
        $data['correo'],
        $idCliente
    ]);

    echo json_encode(['status' => 'success', 'message' => 'Cliente actualizado correctamente']);
} catch (PDOException $e) {
    $sqlCode = (int) ($e->errorInfo[1] ?? 0);
    if ($sqlCode === 1062) {
        echo json_encode(['status' => 'error', 'message' => 'Ya existe otro cliente con esa cédula.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Error al actualizar cliente: ' . $e->getMessage()]);
    }
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Error al actualizar cliente: ' . $e->getMessage()]);
}