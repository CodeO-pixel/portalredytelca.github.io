<?php
header('Content-Type: application/json; charset=utf-8');
require 'conexion.php';

$method = $_SERVER['REQUEST_METHOD'];

/**
 * CORRECCIÓN FASE 2.5: este endpoint no existía. `servicios` se gestionaba
 * de forma implícita e incorrecta desde cliente_actualizar.php/registrar_cliente.php,
 * los cuales asumían "1 cliente = 1 servicio" (buscaban el servicio más
 * reciente del cliente y lo sobreescribían en cada edición). La BD nunca
 * tuvo esa restricción (servicios.id_cliente es FK simple, sin UNIQUE),
 * el bug era puramente de capa de aplicación.
 *
 * Este archivo trata `servicios` como entidad independiente con CRUD
 * propio: un cliente puede tener N servicios (N direcciones/instalaciones),
 * cada uno con su propio plan, NAP y estado comercial.
 */

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void {
    $columns = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array($column, $columns, true)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

try {
    // Salvaguarda mínima (no mecanismo primario, ver Fase 0): estas columnas
    // ya viven nativas en el dump vigente tras la migración de `direcciones`.
    ensureColumn($pdo, 'servicios', 'alias', 'VARCHAR(50) DEFAULT NULL');
    ensureColumn($pdo, 'servicios', 'direccion_texto', "VARCHAR(255) NOT NULL DEFAULT ''");
    ensureColumn($pdo, 'servicios', 'latitud_instalacion', 'DECIMAL(10,8) DEFAULT NULL');
    ensureColumn($pdo, 'servicios', 'longitud_instalacion', 'DECIMAL(11,8) DEFAULT NULL');
    ensureColumn($pdo, 'servicios', 'id_naps', 'INT(11) DEFAULT NULL');

    $pdo->exec("INSERT IGNORE INTO planes (id_plan, nombre, velocidad, precio_mensual, moneda)
                VALUES (1, 'Plan Básico', '10 Mbps', 15.00, 'USD')");
} catch (Exception $e) {
}

if ($method === 'GET') {
    $idCliente = isset($_GET['id_cliente']) ? (int) $_GET['id_cliente'] : 0;

    $where = '';
    $params = [];
    if ($idCliente > 0) {
        $where = ' WHERE sv.id_cliente = ?';
        $params[] = $idCliente;
    }

    $stmt = $pdo->prepare("SELECT sv.*,
                                   c.nombres, c.apellidos, c.cedula,
                                   pl.nombre AS plan_nombre, pl.precio_mensual, pl.moneda,
                                   na.codigo AS nap_codigo,
                                   o.codigo AS olt_codigo, o.marca_modelo AS olt_nombre,
                                   n.nombre AS nodo_nombre,
                                   (SELECT COUNT(*) FROM facturas f WHERE f.id_servicio = sv.id_servicio) AS total_facturas,
                                   (SELECT COUNT(*) FROM contratos ct WHERE ct.id_servicio = sv.id_servicio) AS total_contratos
                            FROM servicios sv
                            INNER JOIN clientes c ON c.id_cliente = sv.id_cliente
                            LEFT JOIN planes pl ON pl.id_plan = sv.id_plan
                            LEFT JOIN naps na ON na.id_nap = sv.id_naps
                            LEFT JOIN olts o ON o.id_olt = na.id_olts
                            LEFT JOIN nodos n ON n.id_nodo = o.id_nodos
                            $where
                            ORDER BY sv.id_servicio ASC");
    $stmt->execute($params);
    echo json_encode(['status' => 'success', 'servicios' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

if ($method === 'POST') {
    $idCliente = (int) ($input['id_cliente'] ?? 0);
    $alias = trim($input['alias'] ?? '');
    $direccionTexto = trim($input['direccion_texto'] ?? '');
    $idPlan = isset($input['id_plan']) && $input['id_plan'] !== '' ? (int) $input['id_plan'] : 1;
    $idNap = isset($input['id_naps']) && $input['id_naps'] !== '' ? (int) $input['id_naps'] : null;
    $latitudRaw = isset($input['latitud']) && $input['latitud'] !== '' ? $input['latitud'] : null;
    $longitudRaw = isset($input['longitud']) && $input['longitud'] !== '' ? $input['longitud'] : null;
    $latitud = $latitudRaw !== null ? str_replace(',', '.', (string) $latitudRaw) : null;
    $longitud = $longitudRaw !== null ? str_replace(',', '.', (string) $longitudRaw) : null;
    $estadoComercial = trim($input['estado_comercial'] ?? 'pendiente');

    if ($idCliente <= 0 || $direccionTexto === '') {
        echo json_encode(['status' => 'error', 'message' => 'Cliente y dirección del servicio son obligatorios']);
        exit;
    }

    if ($latitudRaw !== null && !is_numeric($latitud)) {
        echo json_encode(['status' => 'error', 'message' => 'La latitud/longitud debe ser un número válido (usa punto como separador decimal, ej. 10.4806).']);
        exit;
    }

    if ($longitudRaw !== null && !is_numeric($longitud)) {
        echo json_encode(['status' => 'error', 'message' => 'La latitud/longitud debe ser un número válido (usa punto como separador decimal, ej. 10.4806).']);
        exit;
    }

    $stmtCliente = $pdo->prepare("SELECT id_cliente FROM clientes WHERE id_cliente = ?");
    $stmtCliente->execute([$idCliente]);
    if (!$stmtCliente->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'El cliente seleccionado no existe.']);
        exit;
    }

    $estadosValidos = ['activo', 'suspendido', 'retirado', 'pendiente'];
    if (!in_array($estadoComercial, $estadosValidos, true)) {
        $estadoComercial = 'pendiente';
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO servicios (id_cliente, alias, estado_comercial, id_plan, id_naps, direccion_texto, latitud_instalacion, longitud_instalacion)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $ok = $stmt->execute([$idCliente, $alias ?: null, $estadoComercial, $idPlan, $idNap, $direccionTexto, $latitud, $longitud]);
        echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'Servicio añadido correctamente' : 'No se pudo crear el servicio']);
    } catch (PDOException $e) {
        $sqlCode = (int) ($e->errorInfo[1] ?? 0);
        if ($sqlCode === 1452) {
            echo json_encode(['status' => 'error', 'message' => 'El plan o la NAP seleccionados no son válidos.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error creando el servicio: ' . $e->getMessage()]);
        }
    }
    exit;
}

if ($method === 'PUT') {
    $id = (int) ($input['id_servicio'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Servicio inválido']);
        exit;
    }

    $alias = trim($input['alias'] ?? '');
    $direccionTexto = trim($input['direccion_texto'] ?? '');
    $idPlan = isset($input['id_plan']) && $input['id_plan'] !== '' ? (int) $input['id_plan'] : 1;
    $idNap = isset($input['id_naps']) && $input['id_naps'] !== '' ? (int) $input['id_naps'] : null;
    $latitudRaw = isset($input['latitud']) && $input['latitud'] !== '' ? $input['latitud'] : null;
    $longitudRaw = isset($input['longitud']) && $input['longitud'] !== '' ? $input['longitud'] : null;
    $latitud = $latitudRaw !== null ? str_replace(',', '.', (string) $latitudRaw) : null;
    $longitud = $longitudRaw !== null ? str_replace(',', '.', (string) $longitudRaw) : null;
    $estadoComercial = trim($input['estado_comercial'] ?? 'pendiente');

    if ($direccionTexto === '') {
        echo json_encode(['status' => 'error', 'message' => 'La dirección del servicio es obligatoria']);
        exit;
    }

    if ($latitudRaw !== null && !is_numeric($latitud)) {
        echo json_encode(['status' => 'error', 'message' => 'La latitud/longitud debe ser un número válido (usa punto como separador decimal, ej. 10.4806).']);
        exit;
    }

    if ($longitudRaw !== null && !is_numeric($longitud)) {
        echo json_encode(['status' => 'error', 'message' => 'La latitud/longitud debe ser un número válido (usa punto como separador decimal, ej. 10.4806).']);
        exit;
    }

    $estadosValidos = ['activo', 'suspendido', 'retirado', 'pendiente'];
    if (!in_array($estadoComercial, $estadosValidos, true)) {
        $estadoComercial = 'pendiente';
    }

    try {
        $stmt = $pdo->prepare("UPDATE servicios SET alias = ?, estado_comercial = ?, id_plan = ?, id_naps = ?, direccion_texto = ?, latitud_instalacion = ?, longitud_instalacion = ? WHERE id_servicio = ?");
        $ok = $stmt->execute([$alias ?: null, $estadoComercial, $idPlan, $idNap, $direccionTexto, $latitud, $longitud, $id]);
        echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'Servicio actualizado' : 'No se pudo actualizar']);
    } catch (PDOException $e) {
        $sqlCode = (int) ($e->errorInfo[1] ?? 0);
        if ($sqlCode === 1452) {
            echo json_encode(['status' => 'error', 'message' => 'El plan o la NAP seleccionados no son válidos.']);
        } else {
            echo json_encode(['status' => 'error', 'message' => 'Error actualizando el servicio: ' . $e->getMessage()]);
        }
    }
    exit;
}

if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Servicio inválido']);
        exit;
    }

    // No se permite eliminar un servicio con historial financiero o
    // contractual: rompería trazabilidad. Debe transicionarse a 'retirado'
    // vía PUT en vez de borrarse físicamente si ya tiene facturas/pagos/contratos.
    $stmtCheck = $pdo->prepare("SELECT
        (SELECT COUNT(*) FROM facturas WHERE id_servicio = ?) AS n_facturas,
        (SELECT COUNT(*) FROM contratos WHERE id_servicio = ?) AS n_contratos");
    $stmtCheck->execute([$id, $id]);
    $counts = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if ((int) $counts['n_facturas'] > 0 || (int) $counts['n_contratos'] > 0) {
        echo json_encode(['status' => 'error', 'message' => 'No se puede eliminar: este servicio tiene facturas o contratos asociados. Márcalo como "retirado" en vez de eliminarlo.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("DELETE FROM servicios WHERE id_servicio = ?");
        $ok = $stmt->execute([$id]);
        echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'Servicio eliminado' : 'No se pudo eliminar']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'No se pudo eliminar el servicio: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Método no soportado']);