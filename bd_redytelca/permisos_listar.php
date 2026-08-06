<?php
header('Content-Type: application/json; charset=utf-8');
require 'conexion.php';

$id_rol = isset($_GET['id_rol']) ? intval($_GET['id_rol']) : 0;

try {
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
        PRIMARY KEY (`id_rmp`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Páginas ya asignadas a este rol (para marcar los checkbox como asignado)
    $asignadas = [];
    if ($id_rol > 0) {
        $stmtAsig = $pdo->prepare("SELECT id_pagina FROM rol_modulo_pagina WHERE id_rol = ?");
        $stmtAsig->execute([$id_rol]);
        $asignadas = array_map('intval', $stmtAsig->fetchAll(PDO::FETCH_COLUMN, 0));
    }

    // Universo completo de módulos/páginas del sistema, para poder asignar
    // vistas nuevas a un rol aunque todavía no tenga ninguna asignada.
    $stmt = $pdo->query("SELECT m.id_modulo, m.nombre_modulo, p.id_pagina, p.nombre_pagina, p.url_pagina
        FROM modulos m
        INNER JOIN paginas p ON p.id_modulo = m.id_modulo
        ORDER BY m.id_modulo, p.id_pagina");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $modulos = [];
    foreach ($rows as $row) {
        $idModulo = (int) $row['id_modulo'];
        if (!isset($modulos[$idModulo])) {
            $modulos[$idModulo] = [
                'id_modulo' => $idModulo,
                'nombre_modulo' => $row['nombre_modulo'],
                'vistas' => []
            ];
        }
        $idPagina = (int) $row['id_pagina'];
        $modulos[$idModulo]['vistas'][] = [
            'id_pagina' => $idPagina,
            'nombre_pagina' => $row['nombre_pagina'],
            'url_pagina' => $row['url_pagina'],
            'asignado' => in_array($idPagina, $asignadas, true)
        ];
    }

    echo json_encode(['status' => 'success', 'modulos' => array_values($modulos)]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}