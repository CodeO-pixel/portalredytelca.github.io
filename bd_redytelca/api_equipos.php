<?php
header('Content-Type: application/json; charset=utf-8');
require 'conexion.php';

$method = $_SERVER['REQUEST_METHOD'];

/**
 * FASE 3: causa raíz original (auditoría, api_naps.php) — "equipos nunca
 * se llena porque no existe ningún api_equipos.php ni formulario que
 * inserte ahí". Este archivo es exactamente esa pieza faltante.
 *
 * Alcance explícito: inventario MANUAL de CPE (ONU/ONT/Router). Un
 * técnico teclea MAC/puerto/estado físico — pura persistencia CRUD, sin
 * tocar la red real (sin SNMP/TR-069/API propietaria), consistente con
 * la decisión de alcance de tesis documentada.
 */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS equipos (
        id_equipo INT AUTO_INCREMENT PRIMARY KEY,
        tipo ENUM('ONU','ONT','ROUTER') NOT NULL,
        marca VARCHAR(50) DEFAULT NULL,
        modelo VARCHAR(50) DEFAULT NULL,
        direccion_mac CHAR(17) NOT NULL,
        num_puerto_nap TINYINT(4) NOT NULL,
        estado_fisico ENUM('operativo','averiado','stock') DEFAULT 'stock',
        id_naps INT(11) NOT NULL,
        id_servicio INT(11) DEFAULT NULL,
        UNIQUE KEY direccion_mac (direccion_mac),
        UNIQUE KEY idx_nap_puerto (id_naps, num_puerto_nap),
        UNIQUE KEY id_servicio (id_servicio),
        CONSTRAINT fk_equipo_nap FOREIGN KEY (id_naps) REFERENCES naps(id_nap),
        CONSTRAINT fk_equipo_srv FOREIGN KEY (id_servicio) REFERENCES servicios(id_servicio) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
}

if ($method === 'GET') {
    // total_naps_puertos_ocupados en el desglose por NAP se calcula desde
    // api_naps.php (no aquí); este endpoint devuelve el detalle completo
    // de cada equipo con su NAP/OLT/Nodo y, si está asignado, el cliente.
    $stmt = $pdo->query("SELECT eq.*,
                                na.codigo AS nap_codigo,
                                o.codigo AS olt_codigo, o.marca_modelo AS olt_nombre,
                                n.nombre AS nodo_nombre,
                                sv.alias AS servicio_alias, sv.direccion_texto,
                                c.id_cliente, c.nombres, c.apellidos, c.cedula
                         FROM equipos eq
                         INNER JOIN naps na ON na.id_nap = eq.id_naps
                         LEFT JOIN olts o ON o.id_olt = na.id_olts
                         LEFT JOIN nodos n ON n.id_nodo = o.id_nodos
                         LEFT JOIN servicios sv ON sv.id_servicio = eq.id_servicio
                         LEFT JOIN clientes c ON c.id_cliente = sv.id_cliente
                         ORDER BY eq.id_equipo DESC");
    echo json_encode(['status' => 'success', 'equipos' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

function normalizarMac(string $mac): string {
    return strtoupper(trim($mac));
}

function macEsValida(string $mac): bool {
    return (bool) preg_match('/^([0-9A-Fa-f]{2}:){5}[0-9A-Fa-f]{2}$/', $mac);
}

if ($method === 'POST') {
    $tipo = trim($input['tipo'] ?? '');
    $marca = trim($input['marca'] ?? '');
    $modelo = trim($input['modelo'] ?? '');
    $direccion_mac = normalizarMac($input['direccion_mac'] ?? '');
    $num_puerto_nap = isset($input['num_puerto_nap']) && $input['num_puerto_nap'] !== '' ? (int) $input['num_puerto_nap'] : 0;
    $estado_fisico = trim($input['estado_fisico'] ?? 'stock');
    $id_naps = (int) ($input['id_naps'] ?? 0);
    $id_servicio = isset($input['id_servicio']) && $input['id_servicio'] !== '' ? (int) $input['id_servicio'] : null;

    $tiposValidos = ['ONU', 'ONT', 'ROUTER'];
    if (!in_array($tipo, $tiposValidos, true)) {
        echo json_encode(['status' => 'error', 'message' => 'El tipo de equipo debe ser ONU, ONT o ROUTER']);
        exit;
    }

    if ($direccion_mac === '' || !macEsValida($direccion_mac)) {
        echo json_encode(['status' => 'error', 'message' => 'La dirección MAC debe tener el formato AA:BB:CC:DD:EE:FF']);
        exit;
    }

    if ($num_puerto_nap <= 0 || $id_naps <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'NAP y número de puerto son obligatorios']);
        exit;
    }

    $estadosValidos = ['operativo', 'averiado', 'stock'];
    if (!in_array($estado_fisico, $estadosValidos, true)) {
        $estado_fisico = 'stock';
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO equipos (tipo, marca, modelo, direccion_mac, num_puerto_nap, estado_fisico, id_naps, id_servicio)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $ok = $stmt->execute([$tipo, $marca ?: null, $modelo ?: null, $direccion_mac, $num_puerto_nap, $estado_fisico, $id_naps, $id_servicio]);
        echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'Equipo registrado correctamente' : 'No se pudo registrar el equipo']);
    } catch (PDOException $e) {
        $sqlCode = (int) ($e->errorInfo[1] ?? 0);
        if ($sqlCode === 1062) {
            $mensaje = str_contains($e->getMessage(), 'direccion_mac')
                ? 'Ya existe un equipo registrado con esa dirección MAC.'
                : (str_contains($e->getMessage(), 'idx_nap_puerto')
                    ? 'Ese puerto de la NAP ya está ocupado por otro equipo.'
                    : 'Ese servicio ya tiene un equipo asignado. Un servicio solo puede tener un equipo activo a la vez.');
            echo json_encode(['status' => 'error', 'message' => $mensaje]);
        } elseif ($sqlCode === 1452) {
            echo json_encode(['status' => 'error', 'message' => 'La NAP o el servicio seleccionados no son válidos.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error registrando el equipo: ' . $e->getMessage()]);
        }
    }
    exit;
}

if ($method === 'PUT') {
    $id = (int) ($input['id_equipo'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Equipo inválido']);
        exit;
    }

    $tipo = trim($input['tipo'] ?? '');
    $marca = trim($input['marca'] ?? '');
    $modelo = trim($input['modelo'] ?? '');
    $direccion_mac = normalizarMac($input['direccion_mac'] ?? '');
    $num_puerto_nap = isset($input['num_puerto_nap']) && $input['num_puerto_nap'] !== '' ? (int) $input['num_puerto_nap'] : 0;
    $estado_fisico = trim($input['estado_fisico'] ?? 'stock');
    $id_naps = (int) ($input['id_naps'] ?? 0);
    $id_servicio = isset($input['id_servicio']) && $input['id_servicio'] !== '' ? (int) $input['id_servicio'] : null;

    $tiposValidos = ['ONU', 'ONT', 'ROUTER'];
    if (!in_array($tipo, $tiposValidos, true)) {
        echo json_encode(['status' => 'error', 'message' => 'El tipo de equipo debe ser ONU, ONT o ROUTER']);
        exit;
    }

    if ($direccion_mac === '' || !macEsValida($direccion_mac)) {
        echo json_encode(['status' => 'error', 'message' => 'La dirección MAC debe tener el formato AA:BB:CC:DD:EE:FF']);
        exit;
    }

    if ($num_puerto_nap <= 0 || $id_naps <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'NAP y número de puerto son obligatorios']);
        exit;
    }

    $estadosValidos = ['operativo', 'averiado', 'stock'];
    if (!in_array($estado_fisico, $estadosValidos, true)) {
        $estado_fisico = 'stock';
    }

    try {
        $stmt = $pdo->prepare("UPDATE equipos SET tipo = ?, marca = ?, modelo = ?, direccion_mac = ?, num_puerto_nap = ?, estado_fisico = ?, id_naps = ?, id_servicio = ? WHERE id_equipo = ?");
        $ok = $stmt->execute([$tipo, $marca ?: null, $modelo ?: null, $direccion_mac, $num_puerto_nap, $estado_fisico, $id_naps, $id_servicio, $id]);
        echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'Equipo actualizado' : 'No se pudo actualizar']);
    } catch (PDOException $e) {
        $sqlCode = (int) ($e->errorInfo[1] ?? 0);
        if ($sqlCode === 1062) {
            $mensaje = str_contains($e->getMessage(), 'direccion_mac')
                ? 'Ya existe otro equipo con esa dirección MAC.'
                : (str_contains($e->getMessage(), 'idx_nap_puerto')
                    ? 'Ese puerto de la NAP ya está ocupado por otro equipo.'
                    : 'Ese servicio ya tiene otro equipo asignado.');
            echo json_encode(['status' => 'error', 'message' => $mensaje]);
        } elseif ($sqlCode === 1452) {
            echo json_encode(['status' => 'error', 'message' => 'La NAP o el servicio seleccionados no son válidos.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error actualizando el equipo: ' . $e->getMessage()]);
        }
    }
    exit;
}

if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Equipo inválido']);
        exit;
    }

    // Sin dependientes downstream (nada referencia a equipos.id_equipo),
    // por lo que el DELETE físico es seguro sin verificación adicional
    // — a diferencia de servicios/facturas, que sí bloquean por historial.
    try {
        $stmt = $pdo->prepare("DELETE FROM equipos WHERE id_equipo = ?");
        $ok = $stmt->execute([$id]);
        echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'Equipo eliminado' : 'No se pudo eliminar']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'No se pudo eliminar el equipo: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Método no soportado']);