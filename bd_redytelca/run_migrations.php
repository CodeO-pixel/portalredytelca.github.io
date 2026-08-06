<?php
require 'conexion.php';

try {
    $sql = file_get_contents(__DIR__ . '/migrations/002_rbac_dinamico_seed.sql');
    if ($sql === false) {
        throw new Exception('No se encontró el archivo de migración 002_rbac_dinamico_seed.sql');
    }

    // NOTA TÉCNICA: PDO::exec() con múltiples sentencias separadas por ';'
    // depende de que el driver tolere multi-statement. conexion.php no
    // habilita PDO::MYSQL_ATTR_MULTI_STATEMENTS explícitamente, así que si
    // este exec() falla a mitad de camino, la alternativa segura es pegar
    // el contenido de migrations/002_rbac_dinamico_seed.sql directamente
    // en la pestaña SQL de phpMyAdmin.
    $pdo->exec($sql);
    echo "Migración 002 (esquema completo + RBAC dinámico) ejecutada correctamente\n";
} catch (Exception $e) {
    echo "Error ejecutando migraciones: " . $e->getMessage() . "\n";
    exit(1);
}