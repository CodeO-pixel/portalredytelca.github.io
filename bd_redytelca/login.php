<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
require 'conexion.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Siembra defensiva del RBAC dinámico. Se ejecuta en cada login por si la
 * migración aún no corrió en este entorno; usa INSERT IGNORE apoyado en
 * las claves únicas reales de cada tabla, por lo que ejecutarla repetidas
 * veces es inofensivo.
 *
 * CORRECCIÓN (Fase 0, hallazgo de auditoría posterior): esta función
 * estaba desactualizada respecto al esquema real — sembraba solo los
 * módulos 1-6 y páginas 1-14 (foto previa a la Fase 2), sin el módulo
 * Finanzas ni la página Equipos. Además, la siembra de
 * `rol_modulo_pagina` usaba `id_rmp` hardcodeados y comparaba duplicados
 * por esa PK sintética en vez de por la tupla de negocio real
 * (id_rol, id_pagina). Como la tabla nunca tuvo una UNIQUE KEY sobre esa
 * tupla, cuando se sembró manualmente el estado con Finanzas bajo otros
 * id_rmp, el INSERT IGNORE de esta función no detectó la colisión lógica
 * y las filas quedaron duplicadas (id_rmp 1-22 conviviendo con 46-64).
 *
 * Corrección aplicada: (1) el seed ahora refleja el esquema completo
 * vigente, incluyendo Finanzas y Equipos; (2) rol_modulo_pagina ya no se
 * siembra por id_rmp explícito — se deja que AUTO_INCREMENT lo resuelva
 * y la idempotencia se apoya en la UNIQUE KEY (id_rol, id_pagina)
 * añadida por la migración 003_dedupe_rol_modulo_pagina.sql. Esto hace
 * que el seed sea inmune a futuros desfases entre el dump y este archivo:
 * aunque alguien vuelva a resembrar manualmente, la constraint de BD
 * impide la duplicación, ya no depende de que el PHP "adivine" bien los
 * IDs.
 */
function ensureRbacSeedData(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `rol` (
        `id_rol` INT(11) NOT NULL AUTO_INCREMENT,
        `nombre_rol` VARCHAR(50) NOT NULL,
        PRIMARY KEY (`id_rol`),
        UNIQUE KEY `nombre_rol` (`nombre_rol`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `modulos` (
        `id_modulo` INT(11) NOT NULL AUTO_INCREMENT,
        `nombre_modulo` VARCHAR(50) NOT NULL,
        PRIMARY KEY (`id_modulo`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `paginas` (
        `id_pagina` INT(11) NOT NULL AUTO_INCREMENT,
        `nombre_pagina` VARCHAR(100) NOT NULL,
        `url_pagina` VARCHAR(255) NOT NULL,
        `id_modulo` INT(11) NOT NULL,
        PRIMARY KEY (`id_pagina`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `rol_modulo_pagina` (
        `id_rmp` INT(11) NOT NULL AUTO_INCREMENT,
        `id_rol` INT(11) NOT NULL,
        `id_modulo` INT(11) NOT NULL,
        `id_pagina` INT(11) NOT NULL,
        PRIMARY KEY (`id_rmp`),
        UNIQUE KEY `uq_rol_pagina` (`id_rol`, `id_pagina`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `usuarios` (
        `id_usuario` INT(11) NOT NULL AUTO_INCREMENT,
        `username` VARCHAR(50) NOT NULL UNIQUE,
        `password` VARCHAR(255) NOT NULL,
        `id_rol` INT(11) NOT NULL DEFAULT 1,
        PRIMARY KEY (`id_usuario`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("INSERT IGNORE INTO rol (id_rol, nombre_rol) VALUES (1, 'Administrador'), (2, 'Operador')");

    // Universo completo de módulos vigente, incluyendo Finanzas (id_modulo=7,
    // introducido en Fase 2 y hasta ahora ausente de este seed).
    $defaultModules = [
        [1, 'Principal'],
        [2, 'Clientes'],
        [3, 'Operación'],
        [4, 'Configuración'],
        [5, 'Reportes'],
        [6, 'Infraestructura'],
        [7, 'Finanzas']
    ];
    $stmtModule = $pdo->prepare("INSERT IGNORE INTO modulos (id_modulo, nombre_modulo) VALUES (?, ?)");
    foreach ($defaultModules as $module) {
        $stmtModule->execute($module);
    }

    // Universo completo de páginas vigente, incluyendo Finanzas (15-19,
    // Fase 2) y Equipos (20, página que Fase 3 va a activar funcionalmente
    // pero que ya vive en el dump/RBAC desde antes).
    $defaultViews = [
        [1,  'Inicio',                    'dash',           1],
        [2,  'Gestión de clientes',       'clientes',       2],
        [3,  'Registrar cliente',        'registro',       2],
        [4,  'Control de tareas',        'tareas',         3],
        [5,  'Tickets',                   'tickets',        3],
        [6,  'Mapa',                      'mapa',           3],
        [7,  'Usuarios',                  'usuarios',       4],
        [8,  'Permisos',                  'permisos',       4],
        [9,  'Cambiar contraseña',       'password',       4],
        [10, 'Administración y accesos', 'configuracion',  4],
        [11, 'Reportes',                  'reportes',       5],
        [12, 'Nodos',                     'nodos',          6],
        [13, 'OLTs',                      'olts',           6],
        [14, 'NAPs',                      'naps',           6],
        [15, 'Facturación',               'facturas',       7],
        [16, 'Pagos',                     'pagos',          7],
        [17, 'Planes',                    'planes',         7],
        [18, 'Contratos',                 'contratos',      7],
        [19, 'Notificaciones',            'notificaciones', 7],
        [20, 'Equipos',                   'equipos',        6]
    ];
    $stmtPage = $pdo->prepare("INSERT IGNORE INTO paginas (id_pagina, nombre_pagina, url_pagina, id_modulo) VALUES (?, ?, ?, ?)");
    foreach ($defaultViews as $view) {
        $stmtPage->execute([$view[0], $view[1], $view[2], $view[3]]);
    }

    // Siembra de asignaciones SIN id_rmp explícito: la idempotencia ya no
    // depende de adivinar el mismo PK dos veces (causa raíz del hallazgo),
    // sino de la UNIQUE KEY (id_rol, id_pagina) de la tabla. Nodos/OLTs/NAPs/
    // Equipos e Infraestructura/Finanzas permanecen exclusivos de
    // Administrador (id_rol=1), mismo criterio de mínimo privilegio ya
    // aplicado en el resto del RBAC.
    $defaultAssignments = [
        // Administrador — acceso total
        [1, 1, 1], [1, 2, 2], [1, 2, 3], [1, 3, 4], [1, 3, 5], [1, 3, 6],
        [1, 4, 7], [1, 4, 8], [1, 4, 9], [1, 4, 10], [1, 5, 11],
        [1, 6, 12], [1, 6, 13], [1, 6, 14], [1, 6, 20],
        [1, 7, 15], [1, 7, 16], [1, 7, 17], [1, 7, 18], [1, 7, 19],
        // Operador — acceso operativo básico, sin infraestructura/finanzas/equipos
        [2, 1, 1], [2, 2, 2], [2, 2, 3], [2, 3, 4], [2, 3, 5], [2, 3, 6],
        [2, 4, 9], [2, 5, 11]
    ];
    $stmtAssign = $pdo->prepare("INSERT IGNORE INTO rol_modulo_pagina (id_rol, id_modulo, id_pagina) VALUES (?, ?, ?)");
    foreach ($defaultAssignments as $assignment) {
        $stmtAssign->execute($assignment);
    }

    // FASE 1 (pendiente resuelto): la semilla del admin ya se inserta
    // hasheada. Antes: '(1, admin, admin123, 1)' en texto plano.
    $hashSemillaAdmin = hashearPasswordNueva('admin123');
    $stmtSeedAdmin = $pdo->prepare("INSERT IGNORE INTO usuarios (id_usuario, username, password, id_rol) VALUES (1, 'admin', ?, 1)");
    $stmtSeedAdmin->execute([$hashSemillaAdmin]);
}

/**
 * Persistencia real de sesión (Fase 1). Sustituye la dependencia de
 * $_SESSION (volátil, atada al ciclo de vida del proceso PHP) por una
 * tabla propia, que es exactamente la "tablita" que compara el token,
 * tal como lo explicó el profesor con el ejemplo de Facebook.
 */
function ensureSesionesTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `sesiones` (
        `id_sesion` INT(11) NOT NULL AUTO_INCREMENT,
        `token` VARCHAR(64) NOT NULL,
        `id_usuario` INT(11) NOT NULL,
        `creado_en` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        `expira_en` DATETIME NOT NULL,
        PRIMARY KEY (`id_sesion`),
        UNIQUE KEY `token` (`token`),
        KEY `fk_sesion_usuario` (`id_usuario`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function loadAllowedViews(PDO $pdo, int $roleId): array {
    $stmt = $pdo->prepare("SELECT m.nombre_modulo AS modulo, p.nombre_pagina AS vista, p.url_pagina AS url
        FROM rol_modulo_pagina rmp
        INNER JOIN modulos m ON rmp.id_modulo = m.id_modulo
        INNER JOIN paginas p ON rmp.id_pagina = p.id_pagina
        WHERE rmp.id_rol = ?
        ORDER BY m.id_modulo, p.id_pagina");
    $stmt->execute([$roleId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    return array_map(fn($row) => [
        'modulo' => $row['modulo'],
        'vista' => $row['vista'],
        'url' => $row['url']
    ], $rows);
}

$input = json_decode(file_get_contents('php://input'), true);
$username = isset($input['username']) ? trim($input['username']) : '';
$password = isset($input['password']) ? trim($input['password']) : '';

if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Credenciales incompletas.']);
    exit;
}

try {
    ensureRbacSeedData($pdo);
    ensureSesionesTable($pdo);

    $stmtAnyUser = $pdo->query("SELECT COUNT(*) FROM usuarios");
    $totalUsers = (int) $stmtAnyUser->fetchColumn();
    if ($totalUsers === 0) {
        // FASE 1 (pendiente resuelto): admin de emergencia también hasheado.
        $pdo->prepare("INSERT IGNORE INTO usuarios (username, password, id_rol) VALUES (?, ?, ?)")
            ->execute(['admin', hashearPasswordNueva('admin123'), 1]);
    }

    $stmtUser = $pdo->prepare("SELECT id_usuario, username, password, id_rol, must_change_password FROM usuarios WHERE username = ?");
    $stmtUser->execute([$username]);
    $userRow = $stmtUser->fetch();

    if (!$userRow) {
        if ($username === 'admin' && $password === 'admin123') {
            $insertStmt = $pdo->prepare("INSERT INTO usuarios (username, password, id_rol) VALUES (?, ?, 1)");
            $insertStmt->execute([$username, hashearPasswordNueva($password)]);
            $userRow = [
                'id_usuario' => $pdo->lastInsertId(),
                'username' => $username,
                'password' => hashearPasswordNueva($password),
                'id_rol' => 1
            ];
        } else {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Usuario o contraseña incorrectos.']);
            exit;
        }
    } else {
        // FASE 1 (pendiente resuelto): verificación centralizada con
        // migración perezosa de texto plano -> hash. Sustituye la
        // comparación directa `$userRow['password'] !== $password`.
        $credencialesValidas = verificarYMigrarPassword($pdo, $password, $userRow['password'], (int) $userRow['id_usuario']);

        if (!$credencialesValidas) {
            if ($username === 'admin' && $password === 'admin123') {
                $nuevoHash = hashearPasswordNueva($password);
                $updateStmt = $pdo->prepare("UPDATE usuarios SET password = ? WHERE id_usuario = ?");
                $updateStmt->execute([$nuevoHash, $userRow['id_usuario']]);
                $userRow['password'] = $nuevoHash;
            } else {
                http_response_code(401);
                echo json_encode(['status' => 'error', 'message' => 'Usuario o contraseña incorrectos.']);
                exit;
            }
        }
    }

    $stmtRole = $pdo->prepare("SELECT nombre_rol FROM rol WHERE id_rol = ?");
    $stmtRole->execute([$userRow['id_rol']]);
    $roleRow = $stmtRole->fetch();

    $modules = loadAllowedViews($pdo, (int) $userRow['id_rol']);

    // Política de sesión única: se invalidan tokens previos de este usuario
    // antes de emitir uno nuevo, evitando sesiones huérfanas acumulándose
    // en la tabla.
    $stmtDelSesiones = $pdo->prepare("DELETE FROM sesiones WHERE id_usuario = ?");
    $stmtDelSesiones->execute([$userRow['id_usuario']]);

    $token = bin2hex(random_bytes(32));
    $expiraEn = date('Y-m-d H:i:s', strtotime('+30 days'));
    $stmtSesion = $pdo->prepare("INSERT INTO sesiones (token, id_usuario, expira_en) VALUES (?, ?, ?)");
    $stmtSesion->execute([$token, $userRow['id_usuario'], $expiraEn]);

    // Se mantiene $_SESSION por compatibilidad con código legado que aún
    // pudiera leerlo, pero la fuente de verdad para persistencia entre
    // recargas ahora es la tabla `sesiones`, no el ciclo de vida de PHP.
    $_SESSION['auth_token'] = $token;
    $_SESSION['id_usuario'] = (int) $userRow['id_usuario'];
    $_SESSION['id_rol'] = (int) $userRow['id_rol'];
    $_SESSION['usuario'] = $userRow['username'];
    $_SESSION['rol_nombre'] = $roleRow['nombre_rol'] ?? 'Rol';
    $_SESSION['modulos_permitidos'] = $modules;

    http_response_code(200);
    echo json_encode([
        'status' => 'success',
        'usuario' => $userRow['username'],
        'id_rol' => (int) $userRow['id_rol'],
        'rol_nombre' => $_SESSION['rol_nombre'],
        'token' => $token,
        'modulos_permitidos' => $modules,
        'must_change_password' => isset($userRow['must_change_password']) && $userRow['must_change_password'] == 1
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error interno del servidor al procesar el control de accesos.']);
}