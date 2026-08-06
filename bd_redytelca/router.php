<?php
// Simple router: /cliente -> portal de pago, /admin -> aplicación admin (index.html)
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = trim($uri, '/');
$parts = explode('/', $uri === '' ? '/' : $uri);
$first = $parts[0] ?? '';

if ($first === 'cliente' || $first === 'portal' || $first === 'pagos' || $first === 'pago') {
    // Serve payment portal
    require __DIR__ . '/portal_cliente.php';
    exit;
}

if ($first === 'admin' || $first === '') {
    // Serve admin SPA (index.html) so it can handle client-side routes
    $index = __DIR__ . '/index.html';
    if (file_exists($index)) {
        header('Content-Type: text/html; charset=utf-8');
        readfile($index);
        exit;
    }
}

// If nothing matched, try to serve a file if present, otherwise 404
$path = __DIR__ . $uri;
if (file_exists($path) && is_file($path)) {
    readfile($path);
    exit;
}

http_response_code(404);
echo "404 Not Found";
