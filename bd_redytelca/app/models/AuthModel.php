<?php
require_once __DIR__ . '/../../conexion.php';

class AuthModel {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function login(string $user, string $pass): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM usuarios WHERE username = ? AND password = ?");
        $stmt->execute([$user, $pass]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function cambiarPassword(string $user, string $passActual, string $passNueva): array {
        $stmt = $this->pdo->prepare("SELECT id_usuario FROM usuarios WHERE username = ? AND password = ?");
        $stmt->execute([$user, $passActual]);
        if (!$stmt->fetch()) {
            return ['success' => false, 'message' => 'La contraseña actual es incorrecta'];
        }

        $update = $this->pdo->prepare("UPDATE usuarios SET password = ? WHERE username = ?");
        $ok = $update->execute([$passNueva, $user]);

        return [
            'success' => $ok,
            'message' => $ok ? 'Contraseña actualizada correctamente' : 'No se pudo actualizar la contraseña'
        ];
    }
}