<?php
require_once __DIR__ . '/../models/AuthModel.php';

class AuthController {
    private AuthModel $model;

    public function __construct(PDO $pdo) {
        $this->model = new AuthModel($pdo);
    }

    public function login(string $user, string $pass): array {
        $usuario = $this->model->login($user, $pass);
        if ($usuario) {
            return ['status' => 'success', 'rol' => $usuario['id_rol']];
        }
        return ['status' => 'error', 'message' => 'Credenciales incorrectas'];
    }

    public function cambiarPassword(string $user, string $passActual, string $passNueva): array {
        return $this->model->cambiarPassword($user, $passActual, $passNueva);
    }
}