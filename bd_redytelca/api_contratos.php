<?php
header('Content-Type: application/json; charset=utf-8');
require 'conexion.php';

$method = $_SERVER['REQUEST_METHOD'];

/**
 * CORRECCIÓN FASE 2.5: se añade sincronizarEstadoServicio(). Hallazgo de
 * la auditoría: `contratos.estado` y `servicios.estado_comercial` son ejes
 * distintos (legal vs. operativo) y NO son redundantes entre sí, pero
 * hasta ahora no había ninguna regla que los mantuviera coherentes —
 * podía existir un contrato 'rescindido' con un servicio que seguía en
 * 'activo' porque nadie disparaba el cambio en cascada. Ahora, al
 * transicionar un contrato a 'rescindido', su servicio pasa
 * automáticamente a 'retirado'. Misma filosofía que reconciliarFactura()
 * en api_pagos.php: lógica de negocio en capa de aplicación, no trigger.
 */

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS contratos (
        id_contrato INT AUTO_INCREMENT PRIMARY KEY,
        id_servicio INT NOT NULL,
        fecha_inicio DATE NOT NULL,
        fecha_fin DATE NULL,
        tipo_contrato ENUM('indefinido','plazo_fijo','promocional') DEFAULT 'indefinido',
        estado ENUM('vigente','vencido','rescindido') DEFAULT 'vigente',
        observaciones TEXT NULL,
        creado_en TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_contrato_servicio FOREIGN KEY (id_servicio) REFERENCES servicios(id_servicio)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("UPDATE contratos SET estado = 'vencido' WHERE estado = 'vigente' AND fecha_fin IS NOT NULL AND fecha_fin < CURDATE()");
} catch (Exception $e) {
}

function sincronizarEstadoServicio(PDO $pdo, int $idServicio, string $estadoContrato): void {
    if ($estadoContrato === 'rescindido') {
        $stmt = $pdo->prepare("UPDATE servicios SET estado_comercial = 'retirado' WHERE id_servicio = ? AND estado_comercial <> 'retirado'");
        $stmt->execute([$idServicio]);
    }
}

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT ct.*,
                                s.alias AS servicio_alias, s.direccion_texto,
                                c.id_cliente, c.nombres, c.apellidos, c.cedula,
                                pl.nombre AS plan_nombre
                         FROM contratos ct
                         INNER JOIN servicios s ON s.id_servicio = ct.id_servicio
                         INNER JOIN clientes c ON c.id_cliente = s.id_cliente
                         LEFT JOIN planes pl ON pl.id_plan = s.id_plan
                         ORDER BY ct.fecha_inicio DESC");
    echo json_encode(['status' => 'success', 'contratos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

if ($method === 'POST') {
    $id_servicio = (int)($input['id_servicio'] ?? 0);
    $fecha_inicio = trim($input['fecha_inicio'] ?? '');
    $fecha_fin = trim($input['fecha_fin'] ?? '');
    $tipo_contrato = trim($input['tipo_contrato'] ?? 'indefinido');
    $estado = trim($input['estado'] ?? 'vigente');
    $observaciones = trim($input['observaciones'] ?? '');

    if ($id_servicio <= 0 || $fecha_inicio === '') {
        echo json_encode(['status' => 'error', 'message' => 'Servicio y fecha de inicio son obligatorios']);
        exit;
    }

    $tiposValidos = ['indefinido', 'plazo_fijo', 'promocional'];
    if (!in_array($tipo_contrato, $tiposValidos, true)) {
        $tipo_contrato = 'indefinido';
    }
    if ($tipo_contrato !== 'indefinido' && $fecha_fin === '') {
        echo json_encode(['status' => 'error', 'message' => 'Un contrato a plazo fijo o promocional requiere fecha de fin']);
        exit;
    }

    $estadosValidos = ['vigente', 'vencido', 'rescindido'];
    if (!in_array($estado, $estadosValidos, true)) {
        $estado = 'vigente';
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("INSERT INTO contratos (id_servicio, fecha_inicio, fecha_fin, tipo_contrato, estado, observaciones) VALUES (?, ?, ?, ?, ?, ?)");
        $ok = $stmt->execute([$id_servicio, $fecha_inicio, $fecha_fin ?: null, $tipo_contrato, $estado, $observaciones ?: null]);

        if ($ok) {
            sincronizarEstadoServicio($pdo, $id_servicio, $estado);
        }

        $pdo->commit();
        echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'Contrato creado correctamente' : 'No se pudo crear el contrato']);
    } catch (PDOException $e) {
        $pdo->rollBack();
        $sqlCode = (int) ($e->errorInfo[1] ?? 0);
        if ($sqlCode === 1452) {
            echo json_encode(['status' => 'error', 'message' => 'El servicio seleccionado no existe o fue eliminado.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error creando el contrato: ' . $e->getMessage()]);
        }
    }
    exit;
}

if ($method === 'PUT') {
    $id = (int)($input['id_contrato'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Contrato inválido']);
        exit;
    }

    $id_servicio = (int)($input['id_servicio'] ?? 0);
    $fecha_inicio = trim($input['fecha_inicio'] ?? '');
    $fecha_fin = trim($input['fecha_fin'] ?? '');
    $tipo_contrato = trim($input['tipo_contrato'] ?? 'indefinido');
    $estado = trim($input['estado'] ?? 'vigente');
    $observaciones = trim($input['observaciones'] ?? '');

    if ($id_servicio <= 0 || $fecha_inicio === '') {
        echo json_encode(['status' => 'error', 'message' => 'Servicio y fecha de inicio son obligatorios']);
        exit;
    }

    $tiposValidos = ['indefinido', 'plazo_fijo', 'promocional'];
    if (!in_array($tipo_contrato, $tiposValidos, true)) {
        $tipo_contrato = 'indefinido';
    }
    if ($tipo_contrato !== 'indefinido' && $fecha_fin === '') {
        echo json_encode(['status' => 'error', 'message' => 'Un contrato a plazo fijo o promocional requiere fecha de fin']);
        exit;
    }

    $estadosValidos = ['vigente', 'vencido', 'rescindido'];
    if (!in_array($estado, $estadosValidos, true)) {
        $estado = 'vigente';
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("UPDATE contratos SET id_servicio = ?, fecha_inicio = ?, fecha_fin = ?, tipo_contrato = ?, estado = ?, observaciones = ? WHERE id_contrato = ?");
        $ok = $stmt->execute([$id_servicio, $fecha_inicio, $fecha_fin ?: null, $tipo_contrato, $estado, $observaciones ?: null, $id]);

        if ($ok) {
            sincronizarEstadoServicio($pdo, $id_servicio, $estado);
        }

        $pdo->commit();
        echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'Contrato actualizado' : 'No se pudo actualizar']);
    } catch (PDOException $e) {
        $pdo->rollBack();
        $sqlCode = (int) ($e->errorInfo[1] ?? 0);
        if ($sqlCode === 1452) {
            echo json_encode(['status' => 'error', 'message' => 'El servicio seleccionado no existe o fue eliminado.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error actualizando el contrato: ' . $e->getMessage()]);
        }
    }
    exit;
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Contrato inválido']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM contratos WHERE id_contrato = ?");
        $ok = $stmt->execute([$id]);
        echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'Contrato eliminado' : 'No se pudo eliminar']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'No se pudo eliminar el contrato: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Método no soportado']);