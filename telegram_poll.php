<?php
// telegram_poll.php
// Hace polling a Telegram via getUpdates en vez de depender del webhook.
// Esto evita TODOS los problemas de WAF / Imunify360 / firewall del hosting,
// porque NUESTRO servidor inicia la conexion saliente a Telegram (no al reves).
//
// Configurar como cron en cPanel:
//   * * * * * /usr/bin/php /home/USER/public_html/telegram_poll.php >/dev/null 2>&1
//
// Tambien se puede invocar desde el navegador (admin only) con ?manual=1
// para testear manualmente.

// Permitir CLI o web (admin)
$isCli = (php_sapi_name() === 'cli');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/telegram_handlers.php';

if (!$isCli) {
    // Requerir admin si se invoca via web
    if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
        if (!function_exists('canAccessArea') || !canAccessArea($_SESSION['role'] ?? '', 'admin')) {
            http_response_code(403);
            exit('Forbidden');
        }
    }
    header('Content-Type: text/plain; charset=utf-8');
}

// Lock para evitar dos ejecuciones simultaneas
$LOCK_FILE = __DIR__ . '/uploads/logs/telegram_poll.lock';
@mkdir(dirname($LOCK_FILE), 0755, true);
$lockHandle = @fopen($LOCK_FILE, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "Another polling instance is running. Skipping.\n";
    exit;
}

$LOG_FILE = __DIR__ . '/uploads/logs/telegram_poll.log';

function pollLog($msg, $ctx = null) {
    global $LOG_FILE;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    if ($ctx !== null) {
        $line .= ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    @file_put_contents($LOG_FILE, $line . PHP_EOL, FILE_APPEND);
    echo $line . "\n";
}

$cfg = tgConfig();
if (empty($cfg['token'])) {
    pollLog('No token configured. Exiting.');
    exit;
}
if (!$cfg['enabled']) {
    pollLog('Telegram disabled in settings. Exiting.');
    exit;
}

// Asegurar que no haya webhook activo (sino getUpdates devuelve 409)
$wh = tgGetWebhookInfo();
if (!empty($wh['ok']) && !empty($wh['result']['url'])) {
    pollLog('Deleting active webhook to enable polling', ['url' => $wh['result']['url']]);
    tgDeleteWebhook();
}

// === Recovery: procesar uploads huerfanos antes de pedir nuevos updates ===
// 1) uploads via telegram en 'uploaded' por >90s -> bg worker fallo o sync fallo
// 2) uploads en 'processing' por >5min -> proceso anterior crasheo a mitad
try {
    // Resetear los que llevan demasiado en 'processing' (probable crash anterior)
    $pdo->exec("
        UPDATE invoice_uploads
        SET status='uploaded'
        WHERE source = 'telegram'
          AND status = 'processing'
          AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ");

    $stuck = $pdo->query("
        SELECT id FROM invoice_uploads
        WHERE source = 'telegram'
          AND status = 'uploaded'
          AND created_at < DATE_SUB(NOW(), INTERVAL 90 SECOND)
          AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ORDER BY created_at ASC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($stuck as $stuckId) {
        pollLog('Recovering stuck telegram upload', ['upload_id' => $stuckId]);
        try {
            aiProcessUpload((int)$stuckId);
        } catch (Throwable $e) {
            pollLog('Recovery failed', ['upload_id' => $stuckId, 'msg' => $e->getMessage()]);
        }
    }
    if (!empty($stuck)) {
        pollLog('Recovery done', ['count' => count($stuck)]);
    }
} catch (PDOException $e) {
    pollLog('Recovery query failed', ['msg' => $e->getMessage()]);
}

// Estado: ultimo update_id procesado, guardado en settings
$lastOffset = (int)getSetting('telegram_last_offset', 0);
pollLog('Polling start', ['offset' => $lastOffset]);

// Long polling continuo durante toda la ventana del cron.
// Telegram nos responde en cuanto llega un update (latencia ~1s en vez de 60s).
@set_time_limit(0);
ignore_user_abort(true);

$startTime = microtime(true);
$processedCount = 0;
$maxRuntimeSeconds = $isCli ? 55 : 30; // CLI: hasta 55s. Web manual: 30s para no colgar el browser.
$longPollTimeout   = 25; // Telegram espera hasta 25s por updates (long polling)

while (true) {
    $elapsed = microtime(true) - $startTime;
    $remaining = $maxRuntimeSeconds - $elapsed;
    if ($remaining < 5) {
        pollLog('Time budget reached, stopping');
        break;
    }

    // Ajustar timeout para no exceder el budget
    $effectiveTimeout = min($longPollTimeout, max(1, (int)$remaining - 2));

    $res = tgApi('getUpdates', [
        'offset'  => $lastOffset + 1,
        'limit'   => 50,
        'timeout' => $effectiveTimeout,
        'allowed_updates' => json_encode(['message','edited_message','callback_query']),
    ]);

    if (!$res['ok']) {
        pollLog('getUpdates failed', ['error' => $res['error']]);
        sleep(2);
        continue;
    }

    $updates = $res['result'] ?? [];
    if (empty($updates)) {
        // Sin updates en esta ventana de long polling -> seguimos escuchando
        continue;
    }

    foreach ($updates as $update) {
        $uid = (int)($update['update_id'] ?? 0);
        if ($uid > $lastOffset) $lastOffset = $uid;
        try {
            tgProcessUpdate($update);
            $processedCount++;
        } catch (Throwable $e) {
            pollLog('Update handler exception', [
                'update_id' => $uid,
                'msg' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
        }
    }

    // Persistir offset despues de cada batch
    global $pdo;
    $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('telegram_last_offset', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([(string)$lastOffset]);
}

pollLog('Polling end', ['processed' => $processedCount, 'last_offset' => $lastOffset]);

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
@unlink($LOCK_FILE);
