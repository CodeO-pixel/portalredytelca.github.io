<?php
/**
 * Cabeceras CORS centralizadas. Se manejan aquí (y no repetidas en cada
 * endpoint) porque conexion.php es el único archivo que TODOS los .php
 * requieren sin excepción. Antes, auth_state.php y logout.php no tenían
 * ningún header CORS ni respondían al preflight OPTIONS: si el navegador
 * decidía enviar una petición preflight por el header custom
 * X-Session-Token, esas dos rutas la bloqueaban silenciosamente y el
 * fetch terminaba en catch(error), lo que producía un cierre de sesión
 * fantasma en cada F5.
 */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-Session-Token');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$host = "localhost";
$dbname = "bd_redytelca";
$user = "root";
$pass = ""; // Por defecto en XAMPP está vacío

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    try {
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS email VARCHAR(100) NOT NULL DEFAULT ''");
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0");
        $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS email_verified TINYINT(1) NOT NULL DEFAULT 0");
    } catch (PDOException $schemaError) {
        // Ignoramos errores de esquema que puedan ocurrir en versiones de MySQL antiguas.
    }
} catch (PDOException $e) {
    if (php_sapi_name() !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(["status" => "error", "message" => "Error de conexión: " . $e->getMessage()]);
        exit;
    }
    throw $e;
}

/**
 * FASE 1 (pendiente resuelto) — MIGRACIÓN DE CONTRASEÑAS A password_hash():
 *
 * Hallazgo de la auditoría: `usuarios.password` se comparaba en texto
 * plano (`login.php`: `$userRow['password'] !== $password`), riesgo de
 * seguridad real ante cualquier acceso de lectura a la BD (backup, dump,
 * SQL injection residual, etc.).
 *
 * CORRECCIÓN: migración perezosa (lazy migration), patrón estándar para
 * este escenario. `verificarYMigrarPassword()` centraliza la lógica para
 * que login.php y cambiar_password.php no dupliquen el mismo criterio:
 *
 * 1. Si el valor almacenado ya tiene formato de hash reconocible por PHP
 *    (password_get_info devuelve un algo distinto de null), se verifica
 *    con password_verify() — comportamiento moderno normal.
 * 2. Si NO tiene formato de hash (contraseñas legadas en texto plano,
 *    como la semilla 'admin123'), se compara con hash_equals() (comparación
 *    de tiempo constante, evita timing attacks incluso en el fallback) y,
 *    si coincide, se re-escribe inmediatamente como hash bcrypt en la
 *    misma llamada. El usuario migra a hash de forma transparente en su
 *    siguiente login exitoso, sin necesidad de un script separado que
 *    alguien podría olvidar ejecutar.
 *
 * hashearPasswordNueva() se usa en cambiar_password.php/usuarios_crear.php
 * para que toda contraseña nueva o restablecida se guarde siempre hasheada
 * desde el origen, sin excepción.
 */
function verificarYMigrarPassword(PDO $pdo, string $passwordIngresada, string $passwordAlmacenada, int $idUsuario): bool {
    $infoHash = password_get_info($passwordAlmacenada);

    if ($infoHash['algo'] !== null && $infoHash['algo'] !== 0) {
        // Ya es un hash reconocido (bcrypt/argon2) -> verificación moderna.
        return password_verify($passwordIngresada, $passwordAlmacenada);
    }

    // Contraseña legada en texto plano: comparación de tiempo constante.
    if (hash_equals($passwordAlmacenada, $passwordIngresada)) {
        $nuevoHash = password_hash($passwordIngresada, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('UPDATE usuarios SET password = ? WHERE id_usuario = ?');
        $stmt->execute([$nuevoHash, $idUsuario]);
        return true;
    }

    return false;
}

function hashearPasswordNueva(string $passwordPlana): string {
    return password_hash($passwordPlana, PASSWORD_DEFAULT);
}
?>