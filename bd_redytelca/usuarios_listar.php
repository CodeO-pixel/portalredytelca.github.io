<?php
header('Content-Type: application/json; charset=utf-8');
require 'conexion.php';

$query = "
    SELECT u.id_usuario, u.username, u.email, u.must_change_password, u.email_verified, u.id_rol, r.nombre_rol,
           CASE 
               WHEN u.id_usuario = 1 THEN 'Activo'
               ELSE 'Activo'
           END AS estado,
           'Nunca' AS ultima_conexion
    FROM usuarios u
    LEFT JOIN rol r ON r.id_rol = u.id_rol
    ORDER BY u.username ASC
";

$stmt = $pdo->query($query);
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'status' => 'success',
    'usuarios' => $usuarios
]);
