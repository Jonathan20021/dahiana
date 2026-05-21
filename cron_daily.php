<?php
// cron_daily.php
// Tareas automatizadas diarias del portal. Configurar como cron en cPanel:
//   30 7 * * * /usr/local/bin/php /home2/neetjbte/amdaccouting.kyrosrd.com/cron_daily.php >/dev/null 2>&1
// (Diario a las 7:30am hora del servidor)
//
// Tambien se puede invocar manualmente desde el navegador con ?manual=1 (admin only).

$isCli = (php_sapi_name() === 'cli');

require_once __DIR__ . '/config.php';

if (!$isCli) {
    if (!isset($_SESSION['user_id']) || !canAccessArea($_SESSION['role'] ?? '', 'admin')) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$LOG_DIR = __DIR__ . '/uploads/logs';
@mkdir($LOG_DIR, 0755, true);
$LOG_FILE = $LOG_DIR . '/cron_daily.log';
$LOCK_FILE = $LOG_DIR . '/cron_daily.lock';

function cronLog($msg, $ctx = null) {
    global $LOG_FILE;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    if ($ctx !== null) {
        $line .= ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    @file_put_contents($LOG_FILE, $line . PHP_EOL, FILE_APPEND);
    echo $line . PHP_EOL;
}

// Lock para evitar ejecuciones simultaneas
$lock = @fopen($LOCK_FILE, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    cronLog('Another instance is running. Exiting.');
    exit;
}

@set_time_limit(300);
$startTime = microtime(true);

try {
    // ========================================================================
    // 1. Marcar obligaciones vencidas
    // ========================================================================
    $n = $pdo->exec("UPDATE tax_obligations SET status='vencido' WHERE status='pendiente' AND due_date < CURDATE()");
    cronLog('Mark overdue', ['updated' => $n]);

    // ========================================================================
    // 2. Generar obligaciones para clientes activos (mantenido fresco 6 meses)
    // ========================================================================
    $clients = $pdo->query("
        SELECT u.id
        FROM users u
        LEFT JOIN roles r ON r.slug = u.role
        WHERE COALESCE(r.access_level, CASE WHEN u.role = 'admin' THEN 'admin' ELSE 'client' END) = 'client'
          AND (u.client_status IS NULL OR u.client_status != 'inactivo')
          AND COALESCE(u.approval_status, 'approved') = 'approved'
    ")->fetchAll(PDO::FETCH_COLUMN);
    $generated = 0;
    foreach ($clients as $cid) {
        $generated += generateObligationsForClient((int)$cid, 6);
    }
    cronLog('Generate obligations', ['clients' => count($clients), 'created' => $generated]);

    // ========================================================================
    // 3. Recordatorios de vencimiento (5 dias antes y 1 dia antes)
    // ========================================================================
    $reminderDays = [5, 1];
    $remindersSent = 0;
    foreach ($reminderDays as $days) {
        $stmt = $pdo->prepare("
            SELECT o.id, o.obligation_type, o.period, o.due_date, o.client_id,
                   u.name, u.email, u.phone
            FROM tax_obligations o
            JOIN users u ON u.id = o.client_id
            WHERE o.status = 'pendiente'
              AND o.dismissed_at IS NULL
              AND o.due_date = DATE_ADD(CURDATE(), INTERVAL ? DAY)
              AND COALESCE(u.client_status, 'activo') != 'inactivo'
        ");
        $stmt->execute([$days]);
        $rows = $stmt->fetchAll();

        foreach ($rows as $r) {
            // Email
            if (!empty($r['email']) && function_exists('sendObligationReminderEmail')) {
                @sendObligationReminderEmail((int)$r['id']);
                $remindersSent++;
            }
            // Telegram (si esta vinculado)
            try {
                $tg = $pdo->prepare("SELECT chat_id FROM telegram_links WHERE client_id = ? AND active = 1 LIMIT 1");
                $tg->execute([(int)$r['client_id']]);
                $chatId = (int)($tg->fetchColumn() ?: 0);
                if ($chatId > 0 && function_exists('tgSendMessage')) {
                    $emoji = $days === 1 ? '⚠️' : '⏰';
                    $msg = "{$emoji} <b>Recordatorio DGII</b>\n\n"
                         . "Vence " . ($days === 1 ? '<b>MAÑANA</b>' : 'en <b>' . $days . ' dias</b>') . ":\n"
                         . "<b>" . htmlspecialchars(getObligationLabel($r['obligation_type'])) . "</b>\n"
                         . "Periodo: " . htmlspecialchars(formatPeriod($r['period'])) . "\n"
                         . "Fecha limite: " . date('d/m/Y', strtotime($r['due_date']));
                    tgSendMessage($chatId, $msg);
                    $remindersSent++;
                }
            } catch (PDOException $e) {}
        }
    }
    cronLog('Reminders', ['sent' => $remindersSent]);

    // ========================================================================
    // 4. Reprocesar uploads atascados en 'processing' (mas de 10 min)
    // ========================================================================
    $stuck = $pdo->query("
        SELECT id FROM invoice_uploads
        WHERE status = 'processing'
          AND processed_at IS NULL
          AND created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        LIMIT 20
    ")->fetchAll(PDO::FETCH_COLUMN);
    $reprocessed = 0;
    foreach ($stuck as $uid) {
        // Reset to uploaded para reintentar
        $pdo->prepare("UPDATE invoice_uploads SET status='uploaded', error_message='Reset por cron (procesando >10min)' WHERE id=?")
            ->execute([(int)$uid]);
        $r = aiProcessUpload((int)$uid);
        if (!empty($r['ok'])) $reprocessed++;
    }
    cronLog('Stuck uploads recovered', ['count' => $reprocessed, 'total_stuck' => count($stuck)]);

    // ========================================================================
    // 5. Limpieza de telegram_state viejos (>7 dias sin update)
    // ========================================================================
    $n = $pdo->exec("DELETE FROM telegram_state WHERE updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    cronLog('Cleanup telegram_state', ['deleted' => $n]);

    // ========================================================================
    // 6. Limpieza de logs (mantener solo 60 dias)
    // ========================================================================
    $n = $pdo->exec("DELETE FROM email_log WHERE created_at < DATE_SUB(NOW(), INTERVAL 60 DAY)");
    cronLog('Cleanup email_log', ['deleted' => $n]);

    // ========================================================================
    // 7. Auto-archivar facturas viejas con error (>30 dias) - solo log, no borrar
    // ========================================================================
    $old = $pdo->query("
        SELECT COUNT(*) FROM invoice_uploads
        WHERE status = 'error' AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)
    ")->fetchColumn();
    if ((int)$old > 0) {
        cronLog('Old error uploads', ['count' => $old, 'note' => 'Considerar limpieza manual']);
    }

    cronLog('Cron completed', ['duration_ms' => round((microtime(true) - $startTime) * 1000)]);

} catch (Throwable $e) {
    cronLog('FATAL', ['msg' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
}

flock($lock, LOCK_UN);
fclose($lock);
@unlink($LOCK_FILE);
