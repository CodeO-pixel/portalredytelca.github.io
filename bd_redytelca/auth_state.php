<?php
header('Content-Type: application/json; charset=utf-8');
require 'conexion.php';

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

// Canal doble para el token: header (estándar) con fallback a query string.
// El query string NUNCA dispara preflight CORS y nunca se pierde por
// configuración de proxy/servidor, así que sirve de red de seguridad si el
// header custom no llega por cualquier razón de infraestructura local.
$token = '';
if (isset($_SERVER['HTTP_X_SESSION_TOKEN']) && trim($_SERVER['HTTP_X_SESSION_TOKEN']) !== '') {
    $token = trim($_SERVER['HTTP_X_SESSION_TOKEN']);
} elseif (isset($_GET['token'])) {
    $token = trim($_GET['token']);
}

if ($token === '') {
    echo json_encode(['status' => 'success', 'authenticated' => false]);
    exit;
}

try {
    ensureSesionesTable($pdo);

    $stmt = $pdo->prepare("SELECT s.id_sesion, s.id_usuario, u.username, u.id_rol, u.must_change_password, r.nombre_rol
        FROM sesiones s
        INNER JOIN usuarios u ON u.id_usuario = s.id_usuario
        LEFT JOIN rol r ON r.id_rol = u.id_rol
        WHERE s.token = ? AND s.expira_en > NOW()");
    $stmt->execute([$token]);
    $sesion = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$sesion) {
        echo json_encode(['status' => 'success', 'authenticated' => false]);
        exit;
    }

    // Sesión deslizante: cada verificación válida extiende la expiración 30
    // días más. Solo un logout explícito (o inactividad de 30 días) cierra
    // la sesión; recargar la página nunca la cierra.
    $nuevaExpiracion = date('Y-m-d H:i:s', strtotime('+30 days'));
    $stmtRenovar = $pdo->prepare('UPDATE sesiones SET expira_en = ? WHERE id_sesion = ?');
    $stmtRenovar->execute([$nuevaExpiracion, $sesion['id_sesion']]);

    // Los módulos se recalculan en CADA verificación (no se cachean en la
    // tabla sesiones). Esto es lo que permite que un cambio de permisos
    // hecho por un Administrador se refleje en la sesión activa de un
    // Operador sin que este tenga que volver a loguearse: basta con que el
    // frontend vuelva a llamar a este endpoint (ver Bug 3 en app.js).
    $modules = loadAllowedViews($pdo, (int) $sesion['id_rol']);

    echo json_encode([
        'status' => 'success',
        'authenticated' => true,
        'user' => $sesion['username'],
        'roleId' => (int) $sesion['id_rol'],
        'roleName' => $sesion['nombre_rol'] ?? 'Rol',
        'token' => $token,
        'modules' => $modules,
        'must_change_password' => isset($sesion['must_change_password']) && $sesion['must_change_password'] == 1
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Error validando la sesión: ' . $e->getMessage()]);
}