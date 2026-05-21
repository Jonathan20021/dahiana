<?php
// ai_telegram_worker.php
//
// Worker en background invocado desde el handler de Telegram.
// Procesa un upload con la IA y edita el mensaje de "Recibida. Procesando..."
// con el resultado.
//
// Uso (siempre via CLI, no se debe exponer a la web):
//   php ai_telegram_worker.php <upload_id> <chat_id> <ack_message_id>
//
// Se invoca con:
//   exec('php this_file.php X Y Z > /dev/null 2>&1 &');
// para que corra en background y libere el polling loop.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/telegram.php';

$uploadId     = (int)($argv[1] ?? 0);
$chatId       = (int)($argv[2] ?? 0);
$ackMessageId = (int)($argv[3] ?? 0);

if ($uploadId <= 0 || $chatId === 0) {
    fwrite(STDERR, "Usage: php ai_telegram_worker.php <upload_id> <chat_id> <ack_message_id>\n");
    exit(1);
}

@set_time_limit(120);
ignore_user_abort(true);

$logFile = __DIR__ . '/uploads/logs/ai_telegram_worker.log';
@mkdir(dirname($logFile), 0755, true);
$log = function($msg, $ctx = null) use ($logFile, $uploadId) {
    $line = '[' . date('Y-m-d H:i:s') . "] upload={$uploadId} {$msg}";
    if ($ctx !== null) $line .= ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE);
    @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND);
};

$log('start');

try {
    $res = aiProcessUpload($uploadId);
    if (!$res['ok']) {
        $errMsg = "La IA fallo:\n<i>" . htmlspecialchars($res['error'] ?? 'error desconocido') . "</i>\nEl asesor la procesara manualmente.";
        if ($ackMessageId) tgEditMessage($chatId, $ackMessageId, $errMsg);
        else tgSendMessage($chatId, $errMsg);
        $log('ia failed', ['error' => $res['error'] ?? '']);
        exit(0);
    }

    $exQ = $pdo->prepare("SELECT doc_type, total, itbis, ncf, counterparty_name, confidence, period FROM invoice_extractions WHERE upload_id=? ORDER BY id DESC LIMIT 1");
    $exQ->execute([$uploadId]);
    $e = $exQ->fetch();
    if (!$e) {
        $msg = "Factura recibida. Tu asesor la revisara con IA.";
        if ($ackMessageId) tgEditMessage($chatId, $ackMessageId, $msg);
        else tgSendMessage($chatId, $msg);
        $log('no extraction row');
        exit(0);
    }

    $conf = round(((float)$e['confidence']) * 100);
    $msg = implode("\n", [
        "<b>Factura procesada</b>",
        ($e['doc_type'] === 'venta' ? "Venta (607)" : "Compra (606)") . " - Periodo " . htmlspecialchars($e['period'] ?? '-'),
        "Contraparte: <i>" . htmlspecialchars($e['counterparty_name'] ?: '-') . "</i>",
        "NCF: <code>" . htmlspecialchars($e['ncf'] ?: '-') . "</code>",
        "Total: <b>RD$ " . number_format((float)$e['total'], 2) . "</b>",
        "ITBIS: RD$ " . number_format((float)$e['itbis'], 2),
        "Confianza IA: {$conf}%",
        "",
        "Tu asesor la validara para incluirla en el formulario.",
    ]);
    if ($ackMessageId) tgEditMessage($chatId, $ackMessageId, $msg);
    else tgSendMessage($chatId, $msg);
    $log('ok', ['conf' => $conf]);
} catch (Throwable $ex) {
    $log('exception', ['msg' => $ex->getMessage(), 'file' => $ex->getFile(), 'line' => $ex->getLine()]);
    $err = "Hubo un problema procesando la factura. Tu asesor la revisara manualmente.";
    if ($ackMessageId) tgEditMessage($chatId, $ackMessageId, $err);
    else tgSendMessage($chatId, $err);
}
