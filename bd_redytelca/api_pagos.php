<?php
header('Content-Type: application/json; charset=utf-8');
require 'conexion.php';

$method = $_SERVER['REQUEST_METHOD'];

/**
 * Salvaguarda mínima: `pagos` ya vive reestructurada en el dump vigente
 * (id_factura, id_usuario_valido, origen). Este bloque solo protege
 * instalaciones que aún no corrieron el dump actual.
 */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pagos (
        id_pago INT AUTO_INCREMENT PRIMARY KEY,
        id_factura INT NULL,
        id_cliente INT NOT NULL,
        id_servicio INT NOT NULL,
        monto DECIMAL(10,2) NOT NULL,
        fecha_pago DATETIME NOT NULL,
        metodo_pago ENUM('transferencia','pago_movil','zelle','efectivo') DEFAULT NULL,
        referencia_bancaria VARCHAR(255) DEFAULT NULL,
        estado ENUM('pendiente','validado','rechazado') DEFAULT 'pendiente',
        fecha_validacion DATETIME DEFAULT NULL,
        id_usuario_valido INT DEFAULT NULL,
        origen ENUM('portal_cliente','backoffice') DEFAULT 'backoffice',
        CONSTRAINT fk_pago_factura FOREIGN KEY (id_factura) REFERENCES facturas(id_factura),
        CONSTRAINT fk_pago_cliente FOREIGN KEY (id_cliente) REFERENCES clientes(id_cliente),
        CONSTRAINT fk_pago_servicio FOREIGN KEY (id_servicio) REFERENCES servicios(id_servicio),
        CONSTRAINT fk_pago_usuario FOREIGN KEY (id_usuario_valido) REFERENCES usuarios(id_usuario)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
}

/**
 * Resuelve el rol del staff a partir del token de sesión, mismo canal
 * doble (header X-Session-Token con fallback a query string) que usa
 * auth_state.php. Se usa exclusivamente para autorizar la transición a
 * 'validado'/'rechazado' — nunca para lectura general, que permanece
 * abierta al patrón existente del resto de endpoints del proyecto.
 */
function resolveStaffRoleId(PDO $pdo): ?int {
    $token = '';
    if (isset($_SERVER['HTTP_X_SESSION_TOKEN']) && trim($_SERVER['HTTP_X_SESSION_TOKEN']) !== '') {
        $token = trim($_SERVER['HTTP_X_SESSION_TOKEN']);
    } elseif (isset($_GET['token'])) {
        $token = trim($_GET['token']);
    } elseif (isset($_POST['token'])) {
        $token = trim($_POST['token']);
    }

    if ($token === '') {
        return null;
    }

    try {
        $stmt = $pdo->prepare("SELECT u.id_rol
            FROM sesiones s
            INNER JOIN usuarios u ON u.id_usuario = s.id_usuario
            WHERE s.token = ? AND (s.tipo_usuario = 'staff' OR s.tipo_usuario IS NULL) AND s.expira_en > NOW()");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int) $row['id_rol'] : null;
    } catch (Exception $e) {
        return null;
    }
}

/**
 * Reconciliación de estado de factura (Parte 4.5 del documento maestro):
 * suma de pagos validados vs. monto de la factura ->
 *   >= monto  => 'pagada'
 *   > 0       => 'parcial'
 *   = 0       => vuelve a 'pendiente' (nunca revierte una 'anulada' manual)
 * Se ejecuta en PHP, no como trigger de BD, consistente con el patrón de
 * lógica de negocio en capa de aplicación que ya usa el resto del proyecto.
 */
function reconciliarFactura(PDO $pdo, ?int $idFactura): void {
    if (!$idFactura) {
        return;
    }

    $stmtFactura = $pdo->prepare("SELECT monto, estado FROM facturas WHERE id_factura = ?");
    $stmtFactura->execute([$idFactura]);
    $factura = $stmtFactura->fetch(PDO::FETCH_ASSOC);
    if (!$factura || $factura['estado'] === 'anulada') {
        return;
    }

    $stmtSuma = $pdo->prepare("SELECT COALESCE(SUM(monto), 0) FROM pagos WHERE id_factura = ? AND estado = 'validado'");
    $stmtSuma->execute([$idFactura]);
    $totalPagado = (float) $stmtSuma->fetchColumn();
    $monto = (float) $factura['monto'];

    if ($totalPagado >= $monto && $monto > 0) {
        $nuevoEstado = 'pagada';
    } elseif ($totalPagado > 0) {
        $nuevoEstado = 'parcial';
    } else {
        // No revertir 'vencida' automáticamente aquí; el recalculo de
        // vencimiento vive en api_facturas.php y corre en cada GET de
        // facturas. Solo evitamos dejarla en 'pagada'/'parcial' huérfana.
        $nuevoEstado = ($factura['estado'] === 'vencida') ? 'vencida' : 'pendiente';
    }

    $stmtUpdate = $pdo->prepare("UPDATE facturas SET estado = ? WHERE id_factura = ?");
    $stmtUpdate->execute([$nuevoEstado, $idFactura]);
}

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT pg.*,
                                c.nombres, c.apellidos, c.cedula,
                                s.direccion_texto,
                                f.periodo AS factura_periodo, f.monto AS factura_monto, f.estado AS factura_estado,
                                u.username AS validado_por
                         FROM pagos pg
                         INNER JOIN clientes c ON c.id_cliente = pg.id_cliente
                         INNER JOIN servicios s ON s.id_servicio = pg.id_servicio
                         LEFT JOIN facturas f ON f.id_factura = pg.id_factura
                         LEFT JOIN usuarios u ON u.id_usuario = pg.id_usuario_valido
                         ORDER BY pg.fecha_pago DESC");
    echo json_encode(['status' => 'success', 'pagos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

if ($method === 'POST') {
    $id_factura = isset($input['id_factura']) && $input['id_factura'] !== '' ? (int)$input['id_factura'] : null;
    $id_cliente = (int)($input['id_cliente'] ?? 0);
    $id_servicio = (int)($input['id_servicio'] ?? 0);
    $monto = isset($input['monto']) && $input['monto'] !== '' ? (float)$input['monto'] : null;
    $fecha_pago = trim($input['fecha_pago'] ?? '');
    $metodo_pago = trim($input['metodo_pago'] ?? '');
    $referencia_bancaria = trim($input['referencia_bancaria'] ?? '');
    // Todo pago creado desde el backoffice nace 'pendiente' por defecto,
    // igual que uno creado desde el futuro portal de cliente (Fase 5):
    // la validación es siempre un acto explícito y posterior de Admin.
    $estado = 'pendiente';
    $origen = trim($input['origen'] ?? 'backoffice');
    if (!in_array($origen, ['portal_cliente', 'backoffice'], true)) {
        $origen = 'backoffice';
    }

    if ($id_cliente <= 0 || $id_servicio <= 0 || $monto === null || $monto <= 0 || $fecha_pago === '') {
        echo json_encode(['status' => 'error', 'message' => 'Cliente, servicio, monto y fecha de pago son obligatorios']);
        exit;
    }

    $metodosValidos = ['transferencia', 'pago_movil', 'zelle', 'efectivo'];
    if ($metodo_pago !== '' && !in_array($metodo_pago, $metodosValidos, true)) {
        $metodo_pago = '';
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO pagos (id_factura, id_cliente, id_servicio, monto, fecha_pago, metodo_pago, referencia_bancaria, estado, origen)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ok = $stmt->execute([
            $id_factura,
            $id_cliente,
            $id_servicio,
            $monto,
            $fecha_pago,
            $metodo_pago ?: null,
            $referencia_bancaria ?: null,
            $estado,
            $origen
        ]);
        echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'Pago registrado correctamente, pendiente de validación' : 'No se pudo registrar el pago']);
    } catch (PDOException $e) {
        $sqlCode = (int) ($e->errorInfo[1] ?? 0);
        if ($sqlCode === 1452) {
            echo json_encode(['status' => 'error', 'message' => 'El cliente, servicio o factura seleccionados no son válidos.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error registrando el pago: ' . $e->getMessage()]);
        }
    }
    exit;
}

if ($method === 'PUT') {
    $id = (int)($input['id_pago'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Pago inválido']);
        exit;
    }

    $stmtActual = $pdo->prepare("SELECT * FROM pagos WHERE id_pago = ?");
    $stmtActual->execute([$id]);
    $pagoActual = $stmtActual->fetch(PDO::FETCH_ASSOC);
    if (!$pagoActual) {
        echo json_encode(['status' => 'error', 'message' => 'El pago ya no existe.']);
        exit;
    }

    $nuevoEstado = trim($input['estado'] ?? $pagoActual['estado']);
    $estadosValidos = ['pendiente', 'validado', 'rechazado'];
    if (!in_array($nuevoEstado, $estadosValidos, true)) {
        $nuevoEstado = $pagoActual['estado'];
    }

    // Autoridad exclusiva de Administrador para transicionar a
    // 'validado'/'rechazado' (Parte 4.7 y 4.1 del documento maestro):
    // el portal de cliente nunca podrá marcar su propio pago como
    // validado, y aquí en el backoffice se exige rol 1 explícitamente.
    $cambioDeEstadoSensible = $nuevoEstado !== $pagoActual['estado'] && in_array($nuevoEstado, ['validado', 'rechazado'], true);
    if ($cambioDeEstadoSensible) {
        $roleId = resolveStaffRoleId($pdo);
        if ($roleId !== 1) {
            echo json_encode(['status' => 'error', 'message' => 'Solo un Administrador puede validar o rechazar pagos.']);
            exit;
        }
    }

    $id_factura = isset($input['id_factura']) && $input['id_factura'] !== '' ? (int)$input['id_factura'] : null;
    $id_cliente = (int)($input['id_cliente'] ?? $pagoActual['id_cliente']);
    $id_servicio = (int)($input['id_servicio'] ?? $pagoActual['id_servicio']);
    $monto = isset($input['monto']) && $input['monto'] !== '' ? (float)$input['monto'] : (float)$pagoActual['monto'];
    $fecha_pago = trim($input['fecha_pago'] ?? $pagoActual['fecha_pago']);
    $metodo_pago = trim($input['metodo_pago'] ?? ($pagoActual['metodo_pago'] ?? ''));
    $referencia_bancaria = trim($input['referencia_bancaria'] ?? ($pagoActual['referencia_bancaria'] ?? ''));

    $metodosValidos = ['transferencia', 'pago_movil', 'zelle', 'efectivo'];
    if ($metodo_pago !== '' && !in_array($metodo_pago, $metodosValidos, true)) {
        $metodo_pago = '';
    }

    $pdo->beginTransaction();
    try {
        if ($cambioDeEstadoSensible) {
            $idUsuarioValido = isset($input['id_usuario_valido']) && $input['id_usuario_valido'] !== '' ? (int)$input['id_usuario_valido'] : null;
            $stmt = $pdo->prepare("UPDATE pagos SET id_factura = ?, id_cliente = ?, id_servicio = ?, monto = ?, fecha_pago = ?, metodo_pago = ?, referencia_bancaria = ?, estado = ?, fecha_validacion = NOW(), id_usuario_valido = ? WHERE id_pago = ?");
            $stmt->execute([$id_factura, $id_cliente, $id_servicio, $monto, $fecha_pago, $metodo_pago ?: null, $referencia_bancaria ?: null, $nuevoEstado, $idUsuarioValido, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE pagos SET id_factura = ?, id_cliente = ?, id_servicio = ?, monto = ?, fecha_pago = ?, metodo_pago = ?, referencia_bancaria = ?, estado = ? WHERE id_pago = ?");
            $stmt->execute([$id_factura, $id_cliente, $id_servicio, $monto, $fecha_pago, $metodo_pago ?: null, $referencia_bancaria ?: null, $nuevoEstado, $id]);
        }

        // Reconciliar tanto la factura anterior (si el pago se reasignó a
        // otra factura) como la nueva, para no dejar la vieja con un total
        // desfasado.
        if ($pagoActual['id_factura'] && $pagoActual['id_factura'] != $id_factura) {
            reconciliarFactura($pdo, (int) $pagoActual['id_factura']);
        }
        reconciliarFactura($pdo, $id_factura);

        $pdo->commit();
        echo json_encode(['status' => 'success', 'message' => 'Pago actualizado correctamente']);
    } catch (PDOException $e) {
        $pdo->rollBack();
        $sqlCode = (int) ($e->errorInfo[1] ?? 0);
        if ($sqlCode === 1452) {
            echo json_encode(['status' => 'error', 'message' => 'El cliente, servicio o factura seleccionados no son válidos.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error actualizando el pago: ' . $e->getMessage()]);
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'message' => 'Error actualizando el pago: ' . $e->getMessage()]);
    }
    exit;
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Pago inválido']);
        exit;
    }

    $stmtActual = $pdo->prepare("SELECT id_factura, estado FROM pagos WHERE id_pago = ?");
    $stmtActual->execute([$id]);
    $pagoActual = $stmtActual->fetch(PDO::FETCH_ASSOC);
    if (!$pagoActual) {
        echo json_encode(['status' => 'error', 'message' => 'El pago ya no existe.']);
        exit;
    }

    // Un pago ya validado no se elimina físicamente: rompería la
    // trazabilidad contable frente a la factura conciliada. La vía
    // correcta es rechazarlo (PUT estado='rechazado') si fue un error.
    if ($pagoActual['estado'] === 'validado') {
        echo json_encode(['status' => 'error', 'message' => 'No se puede eliminar un pago ya validado. Márcalo como rechazado si fue un error, para preservar la trazabilidad contable.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM pagos WHERE id_pago = ?");
        $ok = $stmt->execute([$id]);
        if ($ok && $pagoActual['id_factura']) {
            reconciliarFactura($pdo, (int) $pagoActual['id_factura']);
        }
        echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'Pago eliminado' : 'No se pudo eliminar']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'No se pudo eliminar el pago: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Método no soportado']);