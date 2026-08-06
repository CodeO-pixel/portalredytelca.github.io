<?php
header('Content-Type: application/json; charset=utf-8');
require 'conexion.php';

$input = file_get_contents('php://input');
$data = json_decode($input, true);
if (!$data) {
    $data = $_POST;
}

$username = trim($data['username'] ?? '');
$email = trim($data['email'] ?? '');
$idRol = isset($data['id_rol']) ? intval($data['id_rol']) : 0;
$defaultPassword = 'Redytelca123!';

if (!$username || !$email || $idRol <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Todos los campos son obligatorios']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['status' => 'error', 'message' => 'Ingresa un correo electrónico válido']);
    exit;
}

$stmt = $pdo->prepare('SELECT id_usuario FROM usuarios WHERE username = ? OR email = ?');
$stmt->execute([$username, $email]);
if ($stmt->fetch()) {
    echo json_encode(['status' => 'error', 'message' => 'Ese usuario o correo ya existe']);
    exit;
}

// FASE 1 (pendiente resuelto): la contraseña temporal se guarda hasheada
// desde su creación. El texto plano solo se muestra una vez en el mensaje
// de confirmación (para que el operador se lo comunique al usuario nuevo),
// nunca se persiste en claro en la base de datos.
$stmt = $pdo->prepare('INSERT INTO usuarios (username, password, id_rol, email, must_change_password, email_verified) VALUES (?, ?, ?, ?, 1, 0)');
$ok = $stmt->execute([$username, hashearPasswordNueva($defaultPassword), $idRol, $email]);

echo json_encode([
    'status' => $ok ? 'success' : 'error',
    'message' => $ok ? 'Usuario creado correctamente. Contraseña temporal: ' . $defaultPassword . '. El usuario deberá cambiarla en su primer ingreso.' : 'No se pudo crear el usuario'
]);