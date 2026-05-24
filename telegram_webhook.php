<?php
// Telegram webhook endpoint - hardened + async.
//
// IMPORTANTE: Telegram tiene un timeout estricto en los webhooks. Si la
// respuesta tarda mas de unos segundos, el servidor de Telegram retransmite
// y muchos hosting (LiteSpeed, Imunify360, mod_security) responden 409
// "Conflict" al request duplicado mientras el primero sigue procesando.
//
// Por eso este endpoint:
//   1. Recibe el update
//   2. Responde 200 INMEDIATAMENTE (con fastcgi_finish_request / flush)
//   3. Procesa el update DESPUES de cerrar la conexion
//
// Si tu hosting bloquea POSTs entrantes (Imunify360 estricto), usa
// telegram_poll.php con un cron en su lugar.

ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);
ignore_user_abort(true);
@set_time_limit(120);

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

/**
 * Cierra la conexion con Telegram devolviendo 200 OK pero deja el script
 * corriendo en background para procesar el update. Esto evita el 409 Conflict
 * que generaba el host cuando Telegram retransmitia por timeout.
 */
function tgFlushAndContinue($payload) {
    // Cerramos la sesion para liberar el lock antes de seguir procesando.
    if (session_status() === PHP_SESSION_ACTIVE) {
        @session_write_close();
    }
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    header('Connection: close');
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    header('Content-Length: ' . strlen($body));
    echo $body;

    if (function_exists('fastcgi_finish_request')) {
        // PHP-FPM: cierra el response y libera al cliente al instante.
        @fastcgi_finish_request();
    } else {
        // Apache mod_php / LiteSpeed: forzar flush manual.
        @ob_flush();
        @flush();
    }
}

/**
 * Respuesta 200 que termina el script (para casos donde no hay nada que
 * procesar despues, como invalid_secret o disabled).
 */
function tgRespond200AndExit($payload) {
    tgFlushAndContinue($payload);
    exit;
}

set_exception_handler(function ($e) {
    tgLog('EXCEPTION', ['msg' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    if (!headers_sent()) tgRespond200AndExit(['ok' => false, 'reason' => 'exception']);
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
        tgRespond200AndExit(['ok' => false, 'reason' => 'disabled']);
    }

    $receivedSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!empty($cfg['webhook_secret']) && !hash_equals($cfg['webhook_secret'], $receivedSecret)) {
        tgLog('invalid_secret', [
            'received_prefix' => substr($receivedSecret, 0, 6),
            'expected_prefix' => substr($cfg['webhook_secret'], 0, 6),
        ]);
        tgRespond200AndExit(['ok' => false, 'reason' => 'invalid_secret']);
    }

    $payload = file_get_contents('php://input');
    $update  = json_decode($payload, true);
    if (!is_array($update)) {
        tgLog('empty_update');
        tgRespond200AndExit(['ok' => true, 'reason' => 'empty']);
    }

    // Loguear y CERRAR conexion antes de procesar. Telegram recibe 200 al
    // instante y no retransmite, evitando el 409 Conflict.
    tgLog('update', ['type' => array_keys($update), 'from' => $update['message']['from']['id'] ?? null]);
    tgFlushAndContinue(['ok' => true]);

    // Procesamiento despues de cerrar el response.
    try {
        tgProcessUpdate($update);
    } catch (Throwable $e) {
        tgLog('PROCESS_ERROR', ['msg' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    }
    exit;

} catch (Throwable $e) {
    tgLog('THROWABLE', ['msg' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    if (!headers_sent()) tgRespond200AndExit(['ok' => false, 'reason' => 'caught']);
}
