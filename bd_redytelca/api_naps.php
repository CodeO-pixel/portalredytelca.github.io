<?php
header('Content-Type: application/json; charset=utf-8');
require 'conexion.php';

$method = $_SERVER['REQUEST_METHOD'];

/**
 * CORRECCIÓN (columna "Equipos" siempre en 0):
 * El contador original hacía COUNT(*) contra la tabla `equipos`, que
 * nunca se llena porque no existe ningún api_equipos.php ni formulario
 * que inserte ahí — es una tabla muerta en la práctica actual del
 * sistema. La conexión física real "cliente <-> NAP" ya existe y es
 * dinámica: `servicios.id_naps` (la misma que llena el <select> de
 * Clientes). Se cambia el contador para leer de ahí, y se agrega un
 * listado de nombres (clientes_conectados) para poder ver la conexión
 * también desde el lado de la NAP, no solo desde el cliente.
 */

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void {
    $columns = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array($column, $columns, true)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS nodos (
        id_nodo INT(11) NOT NULL AUTO_INCREMENT,
        nombre VARCHAR(100) NOT NULL,
        ubicacion VARCHAR(255) DEFAULT NULL,
        latitud DECIMAL(10,8) NOT NULL,
        longitud DECIMAL(11,8) NOT NULL,
        estado ENUM('activo','mantenimiento','inactivo') DEFAULT 'activo',
        PRIMARY KEY (id_nodo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS olts (
        id_olt INT(11) NOT NULL AUTO_INCREMENT,
        marca_modelo VARCHAR(100) NOT NULL,
        puertos_pon TINYINT(4) NOT NULL DEFAULT 16,
        ip_gestion VARCHAR(45) DEFAULT NULL,
        id_nodos INT(11) NOT NULL,
        codigo VARCHAR(20) DEFAULT NULL,
        PRIMARY KEY (id_olt),
        KEY fk_olt_nodo (id_nodos),
        CONSTRAINT fk_olt_nodo FOREIGN KEY (id_nodos) REFERENCES nodos (id_nodo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS naps (
        id_nap INT(11) NOT NULL AUTO_INCREMENT,
        codigo VARCHAR(20) NOT NULL,
        cantidad_puertos_max TINYINT(4) NOT NULL DEFAULT 16,
        ubicacion_fisica TEXT DEFAULT NULL,
        latitud DECIMAL(10,8) NOT NULL,
        longitud DECIMAL(11,8) NOT NULL,
        id_olts INT(11) NOT NULL,
        PRIMARY KEY (id_nap),
        UNIQUE KEY codigo (codigo),
        KEY fk_nap_olt (id_olts),
        CONSTRAINT fk_nap_olt FOREIGN KEY (id_olts) REFERENCES olts (id_olt)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Garantiza que servicios.id_naps exista, aunque este endpoint se
    // ejecute antes que registrar_cliente.php / cliente_actualizar.php.
    ensureColumn($pdo, 'servicios', 'id_naps', 'INT(11) DEFAULT NULL');
} catch (Exception $e) {
}

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT na.*, o.marca_modelo AS olt_nombre, o.codigo AS olt_codigo, n.nombre AS nodo_nombre,
                                (SELECT COUNT(DISTINCT sv.id_cliente) FROM servicios sv WHERE sv.id_naps = na.id_nap) AS total_clientes,
                                (SELECT GROUP_CONCAT(DISTINCT CONCAT(c.nombres, ' ', c.apellidos) SEPARATOR ', ')
                                   FROM servicios sv
                                   INNER JOIN clientes c ON c.id_cliente = sv.id_cliente
                                  WHERE sv.id_naps = na.id_nap) AS clientes_conectados
                         FROM naps na
                         LEFT JOIN olts o ON o.id_olt = na.id_olts
                         LEFT JOIN nodos n ON n.id_nodo = o.id_nodos
                         ORDER BY na.codigo ASC");
    echo json_encode(['status' => 'success', 'naps' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

if ($method === 'POST') {
    $codigo = trim($input['codigo'] ?? '');
    $cantidad_puertos_max = isset($input['cantidad_puertos_max']) && $input['cantidad_puertos_max'] !== '' ? (int)$input['cantidad_puertos_max'] : 16;
    $ubicacion_fisica = trim($input['ubicacion_fisica'] ?? '');
    $latitudRaw = isset($input['latitud']) && $input['latitud'] !== '' ? $input['latitud'] : null;
    $longitudRaw = isset($input['longitud']) && $input['longitud'] !== '' ? $input['longitud'] : null;
    $latitud = $latitudRaw !== null ? str_replace(',', '.', (string) $latitudRaw) : null;
    $longitud = $longitudRaw !== null ? str_replace(',', '.', (string) $longitudRaw) : null;
    $id_olts = (int)($input['id_olts'] ?? 0);

    if ($codigo === '' || $latitud === null || $longitud === null || $id_olts <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'Código, latitud, longitud y OLT son obligatorios']);
        exit;
    }

    if (!is_numeric($latitud) || !is_numeric($longitud)) {
        echo json_encode(['status' => 'error', 'message' => 'La latitud/longitud debe ser un número válido (usa punto como separador decimal, ej. 10.4806).']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO naps (codigo, cantidad_puertos_max, ubicacion_fisica, latitud, longitud, id_olts) VALUES (?, ?, ?, ?, ?, ?)");
        $ok = $stmt->execute([$codigo, $cantidad_puertos_max, $ubicacion_fisica, $latitud, $longitud, $id_olts]);
        echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'NAP creada correctamente' : 'No se pudo crear la NAP']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Error creando la NAP: el código ya existe o la OLT seleccionada no es válida']);
    }
    exit;
}

if ($method === 'PUT') {
    $id = (int)($input['id_nap'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'NAP inválida']);
        exit;
    }
    $latitudRaw = isset($input['latitud']) && $input['latitud'] !== '' ? $input['latitud'] : null;
    $longitudRaw = isset($input['longitud']) && $input['longitud'] !== '' ? $input['longitud'] : null;
    $latitud = $latitudRaw !== null ? str_replace(',', '.', (string) $latitudRaw) : null;
    $longitud = $longitudRaw !== null ? str_replace(',', '.', (string) $longitudRaw) : null;
    if ($latitudRaw !== null && !is_numeric($latitud)) {
        echo json_encode(['status' => 'error', 'message' => 'La latitud/longitud debe ser un número válido (usa punto como separador decimal, ej. 10.4806).']);
        exit;
    }
    if ($longitudRaw !== null && !is_numeric($longitud)) {
        echo json_encode(['status' => 'error', 'message' => 'La latitud/longitud debe ser un número válido (usa punto como separador decimal, ej. 10.4806).']);
        exit;
    }
    try {
        $stmt = $pdo->prepare("UPDATE naps SET codigo = ?, cantidad_puertos_max = ?, ubicacion_fisica = ?, latitud = ?, longitud = ?, id_olts = ? WHERE id_nap = ?");
        $ok = $stmt->execute([
            trim($input['codigo'] ?? ''),
            isset($input['cantidad_puertos_max']) && $input['cantidad_puertos_max'] !== '' ? (int)$input['cantidad_puertos_max'] : 16,
            trim($input['ubicacion_fisica'] ?? ''),
            $latitud,
            $longitud,
            (int)($input['id_olts'] ?? 0),
            $id
        ]);
        echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'NAP actualizada' : 'No se pudo actualizar']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'Error actualizando la NAP: verifica el código o la OLT']);
    }
    exit;
}

if ($method === 'DELETE') {
    $id = (int)($_GET['id'] ?? 0);
    try {
        $stmt = $pdo->prepare("DELETE FROM naps WHERE id_nap = ?");
        $ok = $stmt->execute([$id]);
        echo json_encode(['status' => $ok ? 'success' : 'error', 'message' => $ok ? 'NAP eliminada' : 'No se pudo eliminar']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => 'No se puede eliminar: esta NAP tiene clientes o equipos asociados. Reasígnalos primero.']);
    }
    exit;
}

echo json_encode(['status' => 'error', 'message' => 'Método no soportado']);