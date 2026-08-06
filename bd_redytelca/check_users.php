<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=bd_redytelca;charset=utf8mb4', 'root', '');
$stmt = $pdo->query('SELECT id_usuario, username, password, id_rol FROM usuarios');
foreach ($stmt as $row) {
    echo json_encode([
        'id_usuario' => (int)$row['id_usuario'],
        'username' => $row['username'],
        'password' => $row['password'],
        'id_rol' => (int)$row['id_rol']
    ], JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
