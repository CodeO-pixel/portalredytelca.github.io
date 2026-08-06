<?php
require_once __DIR__ . '/../../conexion.php';

class ClienteModel {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function listar(string $filter = ''): array {
        $query = "SELECT id_cliente, nombres, apellidos, cedula, num_telefono, correo, direccion, olt, fecha_registro FROM clientes";
        $params = [];

        if ($filter !== '') {
            $query .= " WHERE nombres LIKE ? OR apellidos LIKE ? OR cedula LIKE ? OR correo LIKE ?";
            $param = "%$filter%";
            $params = [$param, $param, $param, $param];
        }

        $query .= " ORDER BY fecha_registro DESC";
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function crear(array $data): array {
        $stmt = $this->pdo->prepare(
            "INSERT INTO clientes (nombres, apellidos, cedula, num_telefono, correo, direccion, olt) VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $ok = $stmt->execute([
            $data['nombres'],
            $data['apellidos'],
            $data['cedula'],
            $data['telefono'],
            $data['correo'],
            $data['direccion'] ?? '',
            $data['olt'] ?? ''
        ]);

        return [
            'success' => $ok,
            'message' => $ok ? 'Cliente registrado correctamente' : 'Error al registrar el cliente'
        ];
    }

    public function actualizar(array $data): array {
        $stmt = $this->pdo->prepare(
            "UPDATE clientes SET nombres = ?, apellidos = ?, cedula = ?, num_telefono = ?, correo = ?, direccion = ?, olt = ? WHERE id_cliente = ?"
        );
        $ok = $stmt->execute([
            $data['nombres'],
            $data['apellidos'],
            $data['cedula'],
            $data['telefono'],
            $data['correo'],
            $data['direccion'] ?? '',
            $data['olt'] ?? '',
            $data['id_cliente']
        ]);

        return [
            'success' => $ok,
            'message' => $ok ? 'Cliente actualizado correctamente' : 'Error al actualizar el cliente'
        ];
    }

    public function eliminar(int $id): array {
        $stmt = $this->pdo->prepare("DELETE FROM clientes WHERE id_cliente = ?");
        $ok = $stmt->execute([$id]);
        return [
            'success' => $ok,
            'message' => $ok ? 'Cliente eliminado correctamente' : 'Error al eliminar el cliente'
        ];
    }

    public function contar(): int {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM clientes");
        return (int) $stmt->fetchColumn();
    }
}