<?php
header('Content-Type: application/json; charset=utf-8');
require 'conexion.php';

/**
 * CORRECCIÓN FASE 2.5: se mantiene la creación del PRIMER servicio en el
 * mismo flujo de alta (el formulario "Nuevo cliente" ya captura dirección/
 * NAP/coordenadas, no tiene sentido forzar un segundo paso solo para el
 * servicio inicial). La diferencia estructural es que este archivo ya NO
 * es el único lugar donde se puede tocar `servicios`: servicios
 * ADICIONALES para el mismo cliente se crean desde api_servicios.php
 * (módulo "Servicios" en la ficha del cliente), no reutilizando este
 * endpoint ni cliente_actualizar.php.
 */

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void {
    $columns = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array($column, $columns, true)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

try {
    // Salvaguarda mínima (no mecanismo primario, ver Fase 0).
    ensureColumn($pdo, 'servicios', 'alias', 'VARCHAR(50) DEFAULT NULL');
    ensureColumn($pdo, 'servicios', 'id_naps', 'INT(11) DEFAULT NULL');
    ensureColumn($pdo, 'servicios', 'direccion_texto', "VARCHAR(255) NOT NULL DEFAULT ''");
    ensureColumn($pdo, 'servicios', 'latitud_instalacion', 'DECIMAL(10,8) DEFAULT NULL');
    ensureColumn($pdo, 'servicios', 'longitud_instalacion', 'DECIMAL(11,8) DEFAULT NULL');
    $pdo->exec("INSERT IGNORE INTO planes (id_plan, nombre, velocidad, precio_mensual, moneda)
                VALUES (1, 'Plan Básico', '10 Mbps', 15.00, 'USD')");
} catch (Exception $e) {
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);
if (!$data) {
    $data = $_POST;
}

if (empty($data['nombres']) || empty($data['apellidos']) || empty($data['cedula']) || empty($data['telefono']) || empty($data['correo'])) {
    echo json_encode(['status' => 'error', 'message' => 'Todos los campos obligatorios deben completarse']);
    exit;
}

$cedula = trim($data['cedula']);

$stmtDup = $pdo->prepare("SELECT id_cliente FROM clientes WHERE cedula = ?");
$stmtDup->execute([$cedula]);
if ($stmtDup->fetch()) {
    echo json_encode(['status' => 'error', 'message' => 'Ya existe un cliente registrado con esa cédula.']);
    exit;
}

$direccionTexto = trim($data['direccion'] ?? '');
$idNap = isset($data['id_nap']) && $data['id_nap'] !== '' ? (int) $data['id_nap'] : null;
$latitud = isset($data['latitud']) && $data['latitud'] !== '' ? $data['latitud'] : null;
$longitud = isset($data['longitud']) && $data['longitud'] !== '' ? $data['longitud'] : null;

$pdo->beginTransaction();
try {
    $stmtCliente = $pdo->prepare(
        "INSERT INTO clientes (nombres, apellidos, cedula, num_telefono, correo) VALUES (?, ?, ?, ?, ?)"
    );
    $stmtCliente->execute([
        $data['nombres'],
        $data['apellidos'],
        $cedula,
        $data['telefono'],
        $data['correo']
    ]);
    $idCliente = (int) $pdo->lastInsertId();

    // El primer servicio se crea solo si el formulario aportó al menos un
    // dato de instalación; si el alta fue solo de la persona (sin
    // dirección todavía), el cliente queda sin servicios y se le añade
    // el primero después desde el módulo "Servicios".
    if ($direccionTexto !== '' || $idNap !== null || $latitud !== null || $longitud !== null) {
        $stmtServicio = $pdo->prepare(
            "INSERT INTO servicios (estado_comercial, id_cliente, id_plan, alias, direccion_texto, id_naps, latitud_instalacion, longitud_instalacion)
             VALUES ('activo', ?, 1, 'Principal', ?, ?, ?, ?)"
        );
        $stmtServicio->execute([$idCliente, $direccionTexto, $idNap, $latitud, $longitud]);
    }

    $pdo->commit();
    echo json_encode(['status' => 'success', 'message' => 'Cliente registrado correctamente']);
} catch (PDOException $e) {
    $pdo->rollBack();
    $sqlCode = (int) ($e->errorInfo[1] ?? 0);
    if ($sqlCode === 1062) {
        echo json_encode(['status' => 'error', 'message' => 'La cédula ya está registrada.']);
    } elseif ($sqlCode === 1452) {
        echo json_encode(['status' => 'error', 'message' => 'La NAP seleccionada no es válida o fue eliminada. Actualiza la lista de NAPs e intenta de nuevo.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Error al registrar: ' . $e->getMessage()]);
    }
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['status' => 'error', 'message' => 'Error al registrar: ' . $e->getMessage()]);
}