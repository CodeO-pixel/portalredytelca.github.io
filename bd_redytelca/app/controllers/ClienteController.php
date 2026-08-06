<?php
require_once __DIR__ . '/../models/ClienteModel.php';

class ClienteController {
    private ClienteModel $model;

    public function __construct(PDO $pdo) {
        $this->model = new ClienteModel($pdo);
    }

    public function listar(string $filter = ''): array {
        return ['status' => 'success', 'clientes' => $this->model->listar($filter)];
    }

    public function crear(array $data): array {
        $result = $this->model->crear($data);
        return $result['success']
            ? ['status' => 'success', 'message' => $result['message']]
            : ['status' => 'error', 'message' => $result['message']];
    }

    public function actualizar(array $data): array {
        $result = $this->model->actualizar($data);
        return $result['success']
            ? ['status' => 'success', 'message' => $result['message']]
            : ['status' => 'error', 'message' => $result['message']];
    }

    public function eliminar(int $id): array {
        $result = $this->model->eliminar($id);
        return $result['success']
            ? ['status' => 'success', 'message' => $result['message']]
            : ['status' => 'error', 'message' => $result['message']];
    }

    public function contar(): array {
        return ['status' => 'success', 'total' => $this->model->contar()];
    }
}