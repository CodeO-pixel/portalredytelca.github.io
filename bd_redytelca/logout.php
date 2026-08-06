<?php
header('Content-Type: application/json; charset=utf-8');
require 'conexion.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Mismo canal doble que auth_state.php: header con fallback a query string.
$token = '';
if (isset($_SERVER['HTTP_X_SESSION_TOKEN']) && trim($_SERVER['HTTP_X_SESSION_TOKEN']) !== '') {
    $token = trim($_SERVER['HTTP_X_SESSION_TOKEN']);
} elseif (isset($_GET['token'])) {
    $token = trim($_GET['token']);
}

try {
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

    if ($token !== '') {
        $stmt = $pdo->prepare('DELETE FROM sesiones WHERE token = ?');
        $stmt->execute([$token]);
    }
} catch (Exception $e) {
    // No se bloquea el logout local si falla el borrado en servidor.
}

session_unset();
session_destroy();

echo json_encode(['status' => 'success']);