<?php
header('Content-Type: application/json; charset=utf-8');
require __DIR__ . '/../conexion.php';
require __DIR__ . '/../includes/auth.php';

try {
    // Ensure user has view permission
    require_permission($pdo, 'clientes.view');

    $filter = isset($_GET['filter']) ? trim($_GET['filter']) : '';
    $sql = 'SELECT id_cliente, nombres, apellidos, cedula, num_telefono, correo, direccion, olt, estado, zona, dias_pago, factura_emitida, aviso_pantalla, aviso_sms, proximo_corte, estado_financiero, deuda, saldo FROM clientes';
    $params = [];
    if ($filter !== '') {
        $sql .= ' WHERE cedula LIKE ? OR nombres LIKE ? OR apellidos LIKE ?';
        $like = '%' . $filter . '%';
        $params = [$like, $like, $like];
    }
    $sql .= ' ORDER BY id_cliente DESC LIMIT 500';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'clientes' => $clientes]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}
?>