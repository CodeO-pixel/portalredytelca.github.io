<?php
header('Content-Type: application/json; charset=utf-8');
require 'conexion.php';

/**
 * FASE DE CORRECCIONES (post Fase 2): endpoint faltante. usuarios_crear.php
 * y usuarios_listar.php ya existían, pero no había forma de editar un
 * usuario existente (username/correo/rol) desde app.js. Se limita a estos
 * tres campos porque son los únicos reales en la tabla `usuarios`; no se
 * edita `estado` aquí porque esa columna no existe en el esquema vigente
 * (usuarios_listar.php hoy la hardcodea a 'Activo' — brecha preexistente,
 * fuera del alcance de esta corrección puntual).
 */

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$idUsuario = isset($input['id_usuario']) ? (int) $input['id_usuario'] : 0;
$username = trim($input['username'] ?? '');
$email = trim($input['email'] ?? '');
$idRol = isset($input['id_rol']) ? (int) $input['id_rol'] : 0;

if ($idUsuario <= 0 || $username === '' || $email === '' || $idRol <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Todos los campos son obligatorios']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['status' => 'error', 'message' => 'Ingresa un correo electrónico válido']);
    exit;
}

try {
    $stmtExists = $pdo->prepare("SELECT id_usuario, id_rol FROM usuarios WHERE id_usuario = ?");
    $stmtExists->execute([$idUsuario]);
    $usuarioActual = $stmtExists->fetch(PDO::FETCH_ASSOC);

    if (!$usuarioActual) {
        echo json_encode(['status' => 'error', 'message' => 'Este usuario ya no existe (pudo haber sido eliminado). Actualiza la lista e intenta de nuevo.']);
        exit;
    }

    $stmtRol = $pdo->prepare("SELECT id_rol FROM rol WHERE id_rol = ?");
    $stmtRol->execute([$idRol]);
    if (!$stmtRol->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'El rol seleccionado no existe.']);
        exit;
    }

    $stmtDup = $pdo->prepare("SELECT id_usuario FROM usuarios WHERE (username = ? OR email = ?) AND id_usuario <> ?");
    $stmtDup->execute([$username, $email, $idUsuario]);
    if ($stmtDup->fetch()) {
        echo json_encode(['status' => 'error', 'message' => 'Ya existe otro usuario con ese nombre de usuario o correo.']);
        exit;
    }

    // Salvaguarda: si se está degradando a este usuario fuera del rol
    // Administrador (id_rol=1), verificar que quede al menos otro
    // administrador activo en el sistema — evita que la plataforma quede
    // sin ningún usuario con acceso total.
    if ((int) $usuarioActual['id_rol'] === 1 && $idRol !== 1) {
        $stmtOtrosAdmins = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE id_rol = 1 AND id_usuario <> ?");
        $stmtOtrosAdmins->execute([$idUsuario]);
        if ((int) $stmtOtrosAdmins->fetchColumn() === 0) {
            echo json_encode(['status' => 'error', 'message' => 'No puedes quitarle el rol de Administrador a este usuario: es el único administrador del sistema.']);
            exit;
        }
    }

    $stmtUpdate = $pdo->prepare("UPDATE usuarios SET username = ?, email = ?, id_rol = ? WHERE id_usuario = ?");
    $ok = $stmtUpdate->execute([$username, $email, $idRol, $idUsuario]);

    echo json_encode([
        'status' => $ok ? 'success' : 'error',
        'message' => $ok ? 'Usuario actualizado correctamente' : 'No se pudo actualizar el usuario'
    ]);
} catch (PDOException $e) {
    $sqlCode = (int) ($e->errorInfo[1] ?? 0);
    if ($sqlCode === 1062) {
        echo json_encode(['status' => 'error', 'message' => 'Ya existe otro usuario con ese nombre de usuario o correo.']);
    } else {
        echo json_encode(['status' => 'error', 'message' => 'Error actualizando el usuario: ' . $e->getMessage()]);
    }
}