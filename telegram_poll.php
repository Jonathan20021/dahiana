<?php
// telegram_poll.php
// Hace polling a Telegram via getUpdates en vez de depender del webhook.
//
// Configurar como cron en cPanel:
//   * * * * * /usr/bin/php /home/USER/public_html/telegram_poll.php >/dev/null 2>&1

$isCli = (php_sapi_name() === 'cli');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/telegram_handlers.php';

if (!$isCli) {
    // Acceso permitido si:
    //  (a) hay sesion admin valida, O
    //  (b) viene con el cron_token correcto (para servicios externos tipo
    //      cron-job.org cuando el cron de cPanel no esta disponible).
    $providedToken = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';
    $expectedToken = trim(getSetting('telegram_cron_token', ''));
    $tokenOk = ($expectedToken !== '' && $providedToken !== '' && hash_equals($expectedToken, $providedToken));

    $sessionOk = isset($_SESSION['user_id']) && (
        ($_SESSION['role'] ?? '') === 'admin' ||
        (function_exists('canAccessArea') && canAccessArea($_SESSION['role'] ?? '', 'admin'))
    );

    if (!$tokenOk && !$sessionOk) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

// ============================================================
// Lock con cleanup garantizado (incluso en crash / fatal error)
// ============================================================
$LOCK_FILE = __DIR__ . '/uploads/logs/telegram_poll.lock';
$LOG_FILE  = __DIR__ . '/uploads/logs/telegram_poll.log';
@mkdir(dirname($LOCK_FILE), 0755, true);

function pollLog($msg, $ctx = null) {
    global $LOG_FILE;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    if ($ctx !== null) {
        $line .= ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    @file_put_contents($LOG_FILE, $line . PHP_EOL, FILE_APPEND);
    if (php_sapi_name() !== 'cli') echo $line . "\n";
}

// Stale lock: si lleva >90s sin tocarse, asumimos crash anterior.
if (is_file($LOCK_FILE) && (time() - @filemtime($LOCK_FILE)) > 90) {
    pollLog('Stale lock detected (>90s), forcing release', ['age' => time() - @filemtime($LOCK_FILE)]);
    @unlink($LOCK_FILE);
}

$lockHandle = @fopen($LOCK_FILE, 'c');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    exit; // otro proceso esta corriendo
}
@touch($LOCK_FILE);

// Cleanup GARANTIZADO (incluso en fatal error / kill / die)
register_shutdown_function(function() use (&$lockHandle) {
    global $LOCK_FILE;
    if ($lockHandle) {
        @flock($lockHandle, LOCK_UN);
        @fclose($lockHandle);
    }
    @unlink($LOCK_FILE);
});

// ============================================================
// Validar config y webhook
// ============================================================
$cfg = tgConfig();
if (empty($cfg['token'])) {
    pollLog('No token configured. Exiting.');
    exit;
}
if (!$cfg['enabled']) {
    pollLog('Telegram disabled in settings. Exiting.');
    exit;
}

// ============================================================
// Modo: 'webhook' | 'poll' | 'auto' (default).
//   - webhook -> el poll NO toca nada y se sale (deja webhook activo).
//   - poll    -> el poll borra cualquier webhook y hace getUpdates.
//   - auto    -> si hay webhook activo SIN error, lo respeta y se sale.
//                Si no hay webhook (o esta roto) hace polling normal.
// Esto evita el 409 Conflict tipico de tener webhook + getUpdates compitiendo.
// ============================================================
$mode = trim(getSetting('telegram_mode', 'auto'));
if (!in_array($mode, ['webhook', 'poll', 'auto'], true)) $mode = 'auto';

if ($mode === 'webhook') {
    pollLog('Mode=webhook: exiting, cron no debe correr cuando el webhook esta activo.');
    exit;
}

$wh = tgGetWebhookInfo();
$webhookUrl = $wh['ok'] ?? false ? trim((string)($wh['result']['url'] ?? '')) : '';

if ($mode === 'auto' && $webhookUrl !== '') {
    // Hay webhook configurado. NO lo borramos: el poll respeta el webhook.
    // Si el webhook tiene un error real (rate-limit/host caido), el admin tiene
    // que ir a admin_telegram_debug.php para reconectarlo o cambiar telegram_mode='poll'.
    pollLog('Mode=auto: webhook activo detectado, no se hace polling', ['url' => $webhookUrl]);
    exit;
}

// Modo poll explicito o auto sin webhook: borrar webhook si lo hay y arrancar polling.
if ($webhookUrl !== '') {
    pollLog('Deleting active webhook to enable polling', ['url' => $webhookUrl, 'mode' => $mode]);
    tgDeleteWebhook();
}

// ============================================================
// Recovery OPCIONAL (apagado por defecto - estaba bloqueando el polling)
// Se activa solo si telegram_recovery_enabled='1' Y limitada a 1 upload por cron
// ============================================================
if (getSetting('telegram_recovery_enabled', '0') === '1') {
    try {
        $pdo->exec("UPDATE invoice_uploads SET status='uploaded' WHERE source='telegram' AND status='processing' AND created_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");
        $stuckId = (int)$pdo->query("
            SELECT id FROM invoice_uploads
            WHERE source='telegram' AND status='uploaded'
              AND created_at < DATE_SUB(NOW(), INTERVAL 120 SECOND)
              AND created_at > DATE_SUB(NOW(), INTERVAL 4 HOUR)
            ORDER BY created_at ASC LIMIT 1
        ")->fetchColumn();
        if ($stuckId > 0) {
            pollLog('Recovery: processing stuck upload', ['id' => $stuckId]);
            @touch($LOCK_FILE);
            try { aiProcessUpload($stuckId); }
            catch (Throwable $e) { pollLog('Recovery failed', ['msg' => $e->getMessage()]); }
        }
    } catch (PDOException $e) {
        pollLog('Recovery query failed', ['msg' => $e->getMessage()]);
    }
    @touch($LOCK_FILE);
}

// ============================================================
// Polling loop
// ============================================================
$lastOffset = (int)getSetting('telegram_last_offset', 0);
pollLog('Polling start', ['offset' => $lastOffset]);

@set_time_limit(0);
ignore_user_abort(true);

$startTime = microtime(true);
$processedCount = 0;
$maxRuntimeSeconds = $isCli ? 55 : 30;
$longPollTimeout   = 25;

// Helper: persistir offset (idempotente)
function saveOffset(int $offset) {
    global $pdo;
    static $stmt = null;
    if ($stmt === null) {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('telegram_last_offset', ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    }
    $stmt->execute([(string)$offset]);
}

while (true) {
    $elapsed = microtime(true) - $startTime;
    $remaining = $maxRuntimeSeconds - $elapsed;
    if ($remaining < 5) {
        pollLog('Time budget reached, stopping');
        break;
    }

    @touch($LOCK_FILE);

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
        continue;
    }

    foreach ($updates as $update) {
        $uid = (int)($update['update_id'] ?? 0);
        if ($uid <= $lastOffset) {
            // Skip duplicado: Telegram ya nos dio este update antes
            continue;
        }
        // FIX CRITICO: persistir offset ANTES de procesar.
        // Asi si el handler crashea o el cron muere, no volvemos a procesar
        // el mismo mensaje (evita el bug de "duplicada x N veces").
        $lastOffset = $uid;
        try {
            saveOffset($lastOffset);
        } catch (Throwable $e) {
            pollLog('Offset save failed', ['msg' => $e->getMessage()]);
        }

        try {
            tgProcessUpdate($update);
            $processedCount++;
        } catch (Throwable $e) {
            pollLog('Update handler exception', [
                'update_id' => $uid,
                'msg'  => $e->getMessage(),
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
            ]);
        }
    }
}

pollLog('Polling end', ['processed' => $processedCount, 'last_offset' => $lastOffset]);
