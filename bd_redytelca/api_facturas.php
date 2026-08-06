<?php
header('Content-Type: application/json; charset=utf-8');
require 'conexion.php';

$method = $_SERVER['REQUEST_METHOD'];

/**
 * Salvaguarda mínima (no mecanismo primario, ver Fase 0 del documento
 * maestro): `facturas` ya vive nativamente en bd_redytelca.sql. Este
 * CREATE TABLE IF NOT EXISTS solo protege instalaciones que aún no
 * corrieron el dump vigente, replicando el patrón defensivo del resto
 * de endpoints del proyecto.
 */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS facturas (
        id_factura INT AUTO_INCREMENT PRIMARY KEY,
        id_servicio INT NOT NULL,
        periodo VARCHAR(7) NOT NULL,
        monto DECIMAL(10,2) NOT NULL,
        fecha_emision DATE NOT NULL,
        fecha_vencimiento DATE NOT NULL,
        estado ENUM('pendiente','pagada','parcial','vencida','anulada') DEFAULT 'pendiente',
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_factura_servicio FOREIGN KEY (id_servicio) REFERENCES servicios(id_servicio)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Vencimiento automático sin cron (limitación de hosting tipo
    // XAMPP/cPanel asumida para el alcance de tesis, Parte 4.5 del
    // documento maestro): se recalcula en cada GET, antes de leer.
    $pdo->exec("UPDATE facturas SET estado = 'vencida' WHERE estado = 'pendiente' AND fecha_vencimiento < CURDATE()");
} catch (Exception $e) {
}

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT f.*,
                                s.alias AS servicio_alias, s.direccion_texto,
                                c.id_cliente, c.nombres, c.apellidos, c.cedula,
                                pl.nombre AS plan_nombre,
                                (SELECT COALESCE(SUM(pg.monto), 0) FROM pagos pg
                                  WHERE pg.id_factura = f.id_factura AND pg.estado = 'validado') AS total_pagado
                         FROM facturas f
                         INNER JOIN servicios s ON s.id_servicio = f.id_servicio
                         INNER JOIN clientes c ON c.id_cliente = s.id_cliente
                         LEFT JOIN planes pl ON pl.id_plan = s.id_plan
                         ORDER BY f.fecha_vencimiento DESC");
    echo json_encode(['status' => 'success', 'facturas' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

if ($method === 'POST') {
    $id_servicio = (int)($input['id_servicio'] ?? 0);
    $periodo = trim($input['periodo'] ?? '');
    $monto = isset($input['monto']) && $input['monto'] !== '' ? (float)$input['monto'] : null;
    $fecha_emision = trim($input['fecha_emision'] ?? '');
    $fecha_vencimiento = trim($input['fecha_vencimiento'] ?? '');
    $estado = trim($input['estado'] ?? 'pendiente');

    if ($id_servicio <= 0 || $periodo === '' || $monto === null || $monto <= 0 || $fecha_emision === '' || $fecha_vencimiento === '') {
        echo json_encode(['status' => 'error', 'message' => 'Servicio, periodo, monto, fecha de emisión y fecha de vencimiento son obligatorios']);
        exit;
    }

    if (!preg_match('/^\d{4}-\d{2}$/', $periodo)) {
        echo json_encode(['status' => 'error', 'message' => 'El periodo debe tener el formato AAAA-MM (ej. 2026-07)']);
        exit;
    }

    $estadosValidos = ['pendiente', 'pagada', 'parcial', 'vencida', 'anulada'];
    if (!in_array($estado, $estadosValidos, true)) {
        $estado = 'pendiente';
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO facturas (id_servicio, periodo, monto, fecha_emision, fecha_vencimiento, estado) VALUES (?, ?, ?, ?, ?, ?)");
        $ok = $stmt->execute([$id_servicio, $periodo, $monto, $fecha_emision, $fecha_vencimiento, $estado]);
        echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'Factura creada correctamente' : 'No se pudo crear la factura']);
    } catch (PDOException $e) {
        $sqlCode = (int) ($e->errorInfo[1] ?? 0);
        if ($sqlCode === 1452) {
            echo json_encode(['status' => 'error', 'message' => 'El servicio seleccionado no existe o fue eliminado.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error creando la factura: ' . $e->getMessage()]);
        }
    }
    exit;
}

if ($method === 'PUT') {
    $id = (int)($input['id_factura'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Factura inválida']);
        exit;
    }

    $id_servicio = (int)($input['id_servicio'] ?? 0);
    $periodo = trim($input['periodo'] ?? '');
    $monto = isset($input['monto']) && $input['monto'] !== '' ? (float)$input['monto'] : null;
    $fecha_emision = trim($input['fecha_emision'] ?? '');
    $fecha_vencimiento = trim($input['fecha_vencimiento'] ?? '');
    $estado = trim($input['estado'] ?? 'pendiente');

    if ($id_servicio <= 0 || $periodo === '' || $monto === null || $monto <= 0 || $fecha_emision === '' || $fecha_vencimiento === '') {
        echo json_encode(['status' => 'error', 'message' => 'Servicio, periodo, monto, fecha de emisión y fecha de vencimiento son obligatorios']);
        exit;
    }

    $estadosValidos = ['pendiente', 'pagada', 'parcial', 'vencida', 'anulada'];
    if (!in_array($estado, $estadosValidos, true)) {
        $estado = 'pendiente';
    }

    try {
        $stmt = $pdo->prepare("UPDATE facturas SET id_servicio = ?, periodo = ?, monto = ?, fecha_emision = ?, fecha_vencimiento = ?, estado = ? WHERE id_factura = ?");
        $ok = $stmt->execute([$id_servicio, $periodo, $monto, $fecha_emision, $fecha_vencimiento, $estado, $id]);
        echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'Factura actualizada' : 'No se pudo actualizar']);
    } catch (PDOException $e) {
        $sqlCode = (int) ($e->errorInfo[1] ?? 0);
        if ($sqlCode === 1452) {
            echo json_encode(['status' => 'error', 'message' => 'El servicio seleccionado no existe o fue eliminado.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error actualizando la factura: ' . $e->getMessage()]);
        }
    }
    exit;
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Factura inválida']);
        exit;
    }

    // No se permite DELETE físico si ya hay pagos asociados: rompería la
    // trazabilidad contable. La vía correcta para "cancelar" una factura
    // con historial es transicionarla a estado 'anulada' vía PUT.
    $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM pagos WHERE id_factura = ?");
    $stmtCheck->execute([$id]);
    if ((int) $stmtCheck->fetchColumn() > 0) {
        echo json_encode(['status' => 'error', 'message' => 'No se puede eliminar: esta factura tiene pagos asociados. Anúlala en vez de eliminarla, o reasigna los pagos primero.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM facturas WHERE id_factura = ?");
        $ok = $stmt->execute([$id]);
        echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'Factura eliminada' : 'No se pudo eliminar']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'No se pudo eliminar la factura: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Método no soportado']);