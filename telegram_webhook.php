<?php
// Telegram webhook endpoint - hardened.
// Si tu hosting bloquea POSTs entrantes (Imunify360/ModSecurity), usa
// telegram_poll.php con un cron en su lugar. Este endpoint queda como
// fallback.

ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

$LOG_DIR  = __DIR__ . '/uploads/logs';
$LOG_FILE = $LOG_DIR . '/telegram.log';
if (!is_dir($LOG_DIR)) @mkdir($LOG_DIR, 0755, true);

function tgLog($msg, $context = null) {
    global $LOG_FILE;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    if ($context !== null) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    @file_put_contents($LOG_FILE, $line . PHP_EOL, FILE_APPEND);
}

function tgRespond200($payload) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

set_exception_handler(function ($e) {
    tgLog('EXCEPTION', ['msg' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    tgRespond200(['ok' => false, 'reason' => 'exception']);
});

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    tgLog('PHP_ERROR', ['sev' => $severity, 'msg' => $message, 'file' => $file, 'line' => $line]);
    return true;
});

register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        tgLog('FATAL', $err);
        if (!headers_sent()) {
            while (ob_get_level() > 0) { ob_end_clean(); }
            http_response_code(200);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'reason' => 'fatal']);
        }
    }
});

try {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/lib/telegram_handlers.php';

    $cfg = tgConfig();
    if (!$cfg['enabled']) {
        tgLog('disabled hit');
        tgRespond200(['ok' => false, 'reason' => 'disabled']);
    }

    $receivedSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!empty($cfg['webhook_secret']) && !hash_equals($cfg['webhook_secret'], $receivedSecret)) {
        tgLog('invalid_secret', [
            'received_prefix' => substr($receivedSecret, 0, 6),
            'expected_prefix' => substr($cfg['webhook_secret'], 0, 6),
        ]);
        tgRespond200(['ok' => false, 'reason' => 'invalid_secret']);
    }

    $payload = file_get_contents('php://input');
    $update  = json_decode($payload, true);
    if (!is_array($update)) {
        tgLog('empty_update');
        tgRespond200(['ok' => true, 'reason' => 'empty']);
    }

    tgLog('update', ['type' => array_keys($update), 'from' => $update['message']['from']['id'] ?? null]);
    tgProcessUpdate($update);
    tgRespond200(['ok' => true]);

} catch (Throwable $e) {
    tgLog('THROWABLE', ['msg' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    tgRespond200(['ok' => false, 'reason' => 'caught']);
}
