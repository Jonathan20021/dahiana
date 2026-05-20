<?php
// Endpoint minimo para descartar firewall/WAF.
// Solo registra el hit en un log y devuelve 200 OK.
// Visitalo en el navegador para verificar que el servidor procesa PHP en este path.

$LOG = __DIR__ . '/uploads/logs/telegram_ping.log';
@mkdir(dirname($LOG), 0755, true);

$line = '[' . date('Y-m-d H:i:s') . '] '
    . ($_SERVER['REQUEST_METHOD'] ?? '?')
    . ' ip=' . ($_SERVER['REMOTE_ADDR'] ?? '?')
    . ' ua=' . substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 100)
    . ' secret_hdr=' . (empty($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']) ? 'no' : 'yes')
    . ' body=' . substr((string)file_get_contents('php://input'), 0, 200);
@file_put_contents($LOG, $line . PHP_EOL, FILE_APPEND);

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'time' => date('c'), 'method' => $_SERVER['REQUEST_METHOD'] ?? '?']);
