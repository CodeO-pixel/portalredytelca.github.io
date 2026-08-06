<?php
header('Content-Type: application/json; charset=utf-8');
require 'conexion.php';

$input = file_get_contents('php://input');
$data = json_decode($input, true);
if (!$data) {
    $data = $_POST;
}

$id_rol = isset($data['id_rol']) ? intval($data['id_rol']) : 0;
$paginas = isset($data['paginas']) && is_array($data['paginas']) ? $data['paginas'] : [];

if ($id_rol <= 0) {
    echo json_encode(["status" => "error", "message" => "Rol inválido"]);
    exit;
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `rol_modulo_pagina` (
        `id_rmp` INT(11) NOT NULL AUTO_INCREMENT,
        `id_rol` INT(11) NOT NULL,
        `id_modulo` INT(11) NOT NULL,
        `id_pagina` INT(11) NOT NULL,
        PRIMARY KEY (`id_rmp`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {
    // La tabla ya existe; se continúa con la operación.
}

$pdo->beginTransaction();
try {
    // Estrategia de reemplazo total: se borra la asignación previa del rol y
    // se reinserta desde cero con lo que llegó marcado en la interfaz.
    $stmtDel = $pdo->prepare("DELETE FROM rol_modulo_pagina WHERE id_rol = ?");
    $stmtDel->execute([$id_rol]);

    if (!empty($paginas)) {
        // id_modulo se resuelve del lado del servidor (a partir de la página),
        // nunca se confía en un id_modulo enviado por el cliente.
        $stmtModulo = $pdo->prepare("SELECT id_modulo FROM paginas WHERE id_pagina = ?");
        $stmtInsert = $pdo->prepare("INSERT INTO rol_modulo_pagina (id_rol, id_modulo, id_pagina) VALUES (?, ?, ?)");

        foreach ($paginas as $idPaginaRaw) {
            $idPagina = intval($idPaginaRaw);
            if ($idPagina <= 0) {
                continue;
            }
            $stmtModulo->execute([$idPagina]);
            $idModulo = $stmtModulo->fetchColumn();
            if ($idModulo === false) {
                continue; // página inexistente, se ignora en vez de romper la transacción
            }
            $stmtInsert->execute([$id_rol, (int) $idModulo, $idPagina]);
        }
    }

    $pdo->commit();
    echo json_encode(["status" => "success", "message" => "Permisos actualizados correctamente"]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(["status" => "error", "message" => "Error guardando permisos: " . $e->getMessage()]);
}