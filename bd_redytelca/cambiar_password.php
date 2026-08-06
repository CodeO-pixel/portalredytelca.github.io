<?php
header('Content-Type: application/json; charset=utf-8');
require 'conexion.php';

$input = file_get_contents('php://input');
$data = json_decode($input, true);
if (!$data) {
    $data = $_POST;
}

$username = trim($data['username'] ?? '');
$passwordActual = trim($data['password_actual'] ?? '');
$passwordNueva = trim($data['password_nueva'] ?? '');

$userId = isset($data['user_id']) ? intval($data['user_id']) : 0;

if (!$passwordNueva || (!$username && $userId <= 0)) {
    echo json_encode(['status' => 'error', 'message' => 'Todos los campos son obligatorios']);
    exit;
}

if ($userId > 0) {
    // Flujo de restablecimiento por Administrador: no requiere validar
    // contraseña actual, ya que es el operador con rol admin quien fuerza
    // el reseteo.
    $stmt = $pdo->prepare('SELECT username FROM usuarios WHERE id_usuario = ?');
    $stmt->execute([$userId]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$userRow) {
        echo json_encode(['status' => 'error', 'message' => 'Usuario no encontrado']);
        exit;
    }

    $username = $userRow['username'];
    $mustChangeReset = true;
} else {
    if (!$passwordActual) {
        echo json_encode(['status' => 'error', 'message' => 'La contraseña actual es obligatoria']);
        exit;
    }

    // FASE 1 (pendiente resuelto): antes se comparaba en la propia
    // sentencia SQL (`WHERE username = ? AND password = ?`) en texto
    // plano. Ahora se recupera el hash almacenado y se verifica con
    // verificarYMigrarPassword(), que además migra contraseñas legadas
    // en texto plano al primer cambio exitoso.
    $stmt = $pdo->prepare('SELECT id_usuario, password FROM usuarios WHERE username = ?');
    $stmt->execute([$username]);
    $userRow = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$userRow || !verificarYMigrarPassword($pdo, $passwordActual, $userRow['password'], (int) $userRow['id_usuario'])) {
        echo json_encode(['status' => 'error', 'message' => 'La contraseña actual es incorrecta']);
        exit;
    }
}

// FASE 1 (pendiente resuelto): toda contraseña nueva se guarda siempre
// hasheada, sin excepción, sea flujo de auto-cambio o de reseteo admin.
$passwordNuevaHash = hashearPasswordNueva($passwordNueva);

if (isset($mustChangeReset) && $mustChangeReset) {
    $update = $pdo->prepare('UPDATE usuarios SET password = ?, must_change_password = 1 WHERE username = ?');
    $ok = $update->execute([$passwordNuevaHash, $username]);
} else {
    $update = $pdo->prepare('UPDATE usuarios SET password = ?, must_change_password = 0 WHERE username = ?');
    $ok = $update->execute([$passwordNuevaHash, $username]);
}

echo json_encode([
    'status' => $ok ? 'success' : 'error',
    'message' => $ok ? 'Contraseña actualizada correctamente' : 'No se pudo actualizar la contraseña'
]);