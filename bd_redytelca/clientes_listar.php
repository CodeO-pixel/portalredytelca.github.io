<?php
header('Content-Type: application/json; charset=utf-8');
require 'conexion.php';

/**
 * CORRECCIÓN FASE 2.5: el JOIN anterior traía únicamente el servicio MÁS
 * RECIENTE de cada cliente (subquery con ORDER BY id_servicio DESC LIMIT 1),
 * asumiendo 1:1. Esto ocultaba servicios adicionales del listado y hacía
 * que la búsqueda no encontrara clientes por una dirección/NAP que no
 * fuera la del servicio más reciente.
 *
 * Ahora: se trae el servicio de MENOR id (el "principal"/primero dado de
 * alta) solo como referencia visual de la fila en la tabla, PERO se añade
 * `total_servicios` para que el frontend pueda indicar "+N más" y
 * enlazar al módulo de Servicios completo. La búsqueda (`filter`) usa
 * EXISTS contra TODOS los servicios del cliente, no solo el principal,
 * para no perder coincidencias.
 */

function ensureColumn(PDO $pdo, string $table, string $column, string $definition): void {
    $columns = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array($column, $columns, true)) {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    }
}

function ensureInfraSchema(PDO $pdo): void {
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
        KEY fk_olt_nodo (id_nodos)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    ensureColumn($pdo, 'olts', 'codigo', 'VARCHAR(20) DEFAULT NULL');

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
        KEY fk_nap_olt (id_olts)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    ensureColumn($pdo, 'servicios', 'alias', 'VARCHAR(50) DEFAULT NULL');
    ensureColumn($pdo, 'servicios', 'id_naps', 'INT(11) DEFAULT NULL');
    ensureColumn($pdo, 'servicios', 'direccion_texto', "VARCHAR(255) NOT NULL DEFAULT ''");
    ensureColumn($pdo, 'servicios', 'latitud_instalacion', 'DECIMAL(10,8) DEFAULT NULL');
    ensureColumn($pdo, 'servicios', 'longitud_instalacion', 'DECIMAL(11,8) DEFAULT NULL');
}

try {
    ensureInfraSchema($pdo);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error preparando el esquema de infraestructura: ' . $e->getMessage()]);
    exit;
}

try {
    $filter = isset($_GET['filter']) ? trim($_GET['filter']) : '';

    $perPage = isset($_GET['per_page']) ? intval($_GET['per_page']) : 10;
    $perPage = max(1, min(100, $perPage));

    $page = isset($_GET['page']) ? intval($_GET['page']) : 1;
    $page = max(1, $page);

    // Servicio "principal" = el de menor id_servicio (primero dado de alta),
    // solo para mostrar algo representativo en la fila de la tabla.
    $joinSql = "
        FROM clientes c
        LEFT JOIN servicios s ON s.id_servicio = (
            SELECT s2.id_servicio FROM servicios s2 WHERE s2.id_cliente = c.id_cliente ORDER BY s2.id_servicio ASC LIMIT 1
        )
        LEFT JOIN naps na ON na.id_nap = s.id_naps
        LEFT JOIN olts o ON o.id_olt = na.id_olts
        LEFT JOIN nodos n ON n.id_nodo = o.id_nodos
    ";

    $whereSql = '';
    $params = [];
    if ($filter !== '') {
        // EXISTS contra TODOS los servicios del cliente (no solo el
        // principal), para no perder coincidencias por dirección/NAP/OLT
        // de un servicio secundario.
        $whereSql = " WHERE c.nombres LIKE ? OR c.apellidos LIKE ? OR c.cedula LIKE ? OR c.correo LIKE ?
                      OR EXISTS (
                          SELECT 1 FROM servicios sx
                          LEFT JOIN naps nax ON nax.id_nap = sx.id_naps
                          LEFT JOIN olts ox ON ox.id_olt = nax.id_olts
                          WHERE sx.id_cliente = c.id_cliente
                            AND (sx.direccion_texto LIKE ? OR COALESCE(nax.codigo, '') LIKE ? OR COALESCE(ox.codigo, '') LIKE ?)
                      )";
        $filterParam = "%$filter%";
        $params = [$filterParam, $filterParam, $filterParam, $filterParam, $filterParam, $filterParam, $filterParam];
    }

    $countQuery = "SELECT COUNT(*) " . $joinSql . $whereSql;
    $stmtCount = $pdo->prepare($countQuery);
    $stmtCount->execute($params);
    $total = (int) $stmtCount->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));

    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $query = "SELECT c.id_cliente, c.nombres, c.apellidos, c.cedula, c.num_telefono, c.correo, c.fecha_registro,
                     COALESCE(s.direccion_texto, '') AS direccion,
                     s.id_servicio, s.estado_comercial, s.id_naps,
                     na.codigo AS nap_codigo,
                     o.codigo AS olt_codigo, o.marca_modelo AS olt_nombre,
                     n.nombre AS nodo_nombre,
                     s.latitud_instalacion AS latitud, s.longitud_instalacion AS longitud,
                     (SELECT COUNT(*) FROM servicios sc WHERE sc.id_cliente = c.id_cliente) AS total_servicios
              " . $joinSql . $whereSql . "
              ORDER BY c.fecha_registro DESC LIMIT ? OFFSET ?";

    $stmt = $pdo->prepare($query);
    $paramIndex = 1;
    foreach ($params as $param) {
        $stmt->bindValue($paramIndex, $param, PDO::PARAM_STR);
        $paramIndex++;
    }
    $stmt->bindValue($paramIndex++, $perPage, PDO::PARAM_INT);
    $stmt->bindValue($paramIndex++, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'clientes' => $clientes,
        'page' => $page,
        'pages' => $totalPages,
        'total' => $total,
        'per_page' => $perPage
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error listando clientes: ' . $e->getMessage()]);
}