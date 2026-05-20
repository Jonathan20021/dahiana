<?php
// Telegram webhook endpoint - hardened.
// SIEMPRE responde 200 a Telegram. Cualquier excepcion va al log y se ignora.
// Log file: uploads/logs/telegram.log

// 1) Buffer cualquier salida accidental para que `header()` siempre funcione.
ob_start();

// 2) Suprimir display de errores - se loguean abajo igualmente.
ini_set('display_errors', '0');
error_reporting(E_ALL);

// 3) Bootstrap del log
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
    // descarta cualquier output accidental
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
    require_once __DIR__ . '/lib/telegram.php';

    $cfg = tgConfig();
    if (!$cfg['enabled']) {
        tgLog('disabled hit');
        tgRespond200(['ok' => false, 'reason' => 'disabled']);
    }

    // Validate secret_token header sent by Telegram
    $receivedSecret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
    if (!empty($cfg['webhook_secret']) && !hash_equals($cfg['webhook_secret'], $receivedSecret)) {
        tgLog('invalid_secret', ['received' => substr($receivedSecret, 0, 6) . '...']);
        // Devolvemos 200 igual para que Telegram no marque error
        tgRespond200(['ok' => false, 'reason' => 'invalid_secret']);
    }

    $payload = file_get_contents('php://input');
    $update  = json_decode($payload, true);
    if (!is_array($update)) {
        tgLog('empty_update', ['body' => substr((string)$payload, 0, 200)]);
        tgRespond200(['ok' => true, 'reason' => 'empty']);
    }

    tgLog('update', ['type' => array_keys($update), 'from' => $update['message']['from']['id'] ?? null]);

    if (isset($update['message'])) {
        tgHandleMessage($update['message']);
    } elseif (isset($update['edited_message'])) {
        tgHandleMessage($update['edited_message']);
    } elseif (isset($update['callback_query'])) {
        $cb = $update['callback_query'];
        tgAnswerCallback($cb['id'] ?? '');
    }

    tgRespond200(['ok' => true]);

} catch (Throwable $e) {
    tgLog('THROWABLE', ['msg' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    tgRespond200(['ok' => false, 'reason' => 'caught']);
}

// --------------------------------------------------------------------------
// Handlers (definidos al final para que existan despues del bootstrap)
// --------------------------------------------------------------------------
function tgRespondError($chatId, $msg) {
    tgSendMessage($chatId, "<b>Hubo un problema</b>\n{$msg}");
}

function tgProcessPhoto(array $photoOrDoc, int $chatId, array $client, string $caption = '') {
    global $pdo;
    if (isset($photoOrDoc[0]) && is_array($photoOrDoc[0])) {
        usort($photoOrDoc, fn($a,$b) => ($b['file_size'] ?? 0) <=> ($a['file_size'] ?? 0));
        $file = $photoOrDoc[0];
    } else {
        $file = $photoOrDoc;
    }
    $fileId  = $file['file_id'] ?? null;
    if (!$fileId) { tgRespondError($chatId, 'No pude leer el archivo.'); return; }

    $info = tgGetFile($fileId);
    if (!$info['ok']) { tgRespondError($chatId, 'No pude descargar: ' . $info['error']); return; }

    $remotePath = $info['result']['file_path'] ?? '';
    if ($remotePath === '') { tgRespondError($chatId, 'Archivo sin ruta.'); return; }

    $mime = $file['mime_type'] ?? '';
    if ($mime === '') {
        $ext = strtolower(pathinfo($remotePath, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'jpg','jpeg' => 'image/jpeg',
            'png'        => 'image/png',
            'webp'       => 'image/webp',
            'heic','heif'=> 'image/heic',
            default      => 'image/jpeg',
        };
    }
    if (strpos($mime, 'image/') !== 0) {
        tgSendMessage($chatId, "Solo puedo procesar imagenes (JPG, PNG, WEBP, HEIC). Recibi <code>{$mime}</code>.");
        return;
    }

    $ext = pathinfo($remotePath, PATHINFO_EXTENSION) ?: 'jpg';
    $filename = 'inv_' . $client['client_id'] . '_tg_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . strtolower($ext);
    $dest = aiUploadsDir() . '/' . $filename;
    if (!tgDownloadFile($remotePath, $dest)) {
        tgRespondError($chatId, 'No pude descargar el archivo de Telegram.');
        return;
    }
    $size = @filesize($dest);
    $sha  = @hash_file('sha256', $dest);

    if ($sha && aiFindDuplicateUpload((int)$client['client_id'], $sha) > 0) {
        @unlink($dest);
        tgSendMessage($chatId, "Esta factura ya la habias subido antes. La ignore.");
        return;
    }

    $uploadId = aiCreateUploadRecord((int)$client['client_id'], [
        'filename'      => $filename,
        'original_name' => $caption !== '' ? mb_substr($caption, 0, 240) : ($filename),
        'mime_type'     => $mime,
        'file_size'     => $size,
        'sha256'        => $sha,
    ], 'auto');

    $pdo->prepare("UPDATE invoice_uploads SET source='telegram' WHERE id=?")->execute([$uploadId]);

    $autoProcess = getSetting('openai_auto_process', '1') === '1' && getSetting('openai_enabled', '1') === '1';
    if ($autoProcess) {
        $res = aiProcessUpload($uploadId);
        if (!$res['ok']) {
            tgSendMessage($chatId, "Recibida pero la IA fallo:\n<i>" . htmlspecialchars($res['error']) . "</i>\nEl asesor la procesara manualmente.");
            return;
        }
        $exQ = $pdo->prepare("SELECT doc_type, total, itbis, ncf, counterparty_name, confidence, period FROM invoice_extractions WHERE upload_id=? ORDER BY id DESC LIMIT 1");
        $exQ->execute([$uploadId]);
        $e = $exQ->fetch();
        if ($e) {
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
            tgSendMessage($chatId, $msg);
            return;
        }
    }
    tgSendMessage($chatId, "Factura recibida. Tu asesor la procesara con IA en breve.");
}

function tgHandleMessage(array $msg) {
    global $pdo;
    $chatId = (int)($msg['chat']['id'] ?? 0);
    if ($chatId === 0) return;
    $from   = $msg['from'] ?? [];
    $text   = trim((string)($msg['text'] ?? ''));
    $caption = trim((string)($msg['caption'] ?? ''));
    $company = trim(getSetting('company_name', 'Portal Asesoria'));

    tgTouchLastSeen($chatId);
    $client = tgClientForChat($chatId);

    if ($text !== '') {
        if (preg_match('/^\/start(?:@\w+)?(?:\s+(.+))?$/i', $text, $m)) {
            $payload = trim($m[1] ?? '');
            if ($payload !== '' && !$client) {
                $found = tgFindClientByCode($payload);
                if ($found) {
                    tgLinkClient((int)$found['id'], $chatId, $from);
                    tgSetState($chatId, 'idle');
                    $label = $found['business_name'] ?: $found['name'];
                    tgSendMessage($chatId, "Cuenta vinculada con <b>" . htmlspecialchars($label) . "</b>.\n\nAhora envia fotos de tus facturas. Tambien puedes escribir /estado o /ayuda.");
                    return;
                }
                tgSendMessage($chatId, "Codigo invalido. Pide tu codigo de vinculacion al equipo o copia el que aparece en tu portal.");
                return;
            }
            tgSendMessage($chatId, tgWelcomeText(htmlspecialchars($company)));
            return;
        }
        if (preg_match('/^\/(ayuda|help)(?:@\w+)?/i', $text)) {
            tgSendMessage($chatId, tgHelpText());
            return;
        }
        if (preg_match('/^\/vincular(?:@\w+)?\s+(\S+)/i', $text, $m)) {
            $code = $m[1];
            $found = tgFindClientByCode($code);
            if (!$found) {
                tgSendMessage($chatId, "Codigo no valido. Verifica el codigo en tu portal o pidelo al equipo.");
                return;
            }
            tgLinkClient((int)$found['id'], $chatId, $from);
            tgSetState($chatId, 'idle');
            $label = $found['business_name'] ?: $found['name'];
            tgSendMessage($chatId, "Listo. Cuenta vinculada con <b>" . htmlspecialchars($label) . "</b>.\n\nEnviame fotos de tus facturas y yo me encargo. Si necesitas ayuda escribe /ayuda.");
            return;
        }
        if (preg_match('/^\/(salir|unlink|desvincular)(?:@\w+)?/i', $text)) {
            tgUnlink($chatId);
            tgSendMessage($chatId, "Listo, este chat ya no esta vinculado. Cuando quieras puedes volver con /vincular CODIGO.");
            return;
        }
        if (preg_match('/^\/(estado|status)(?:@\w+)?/i', $text)) {
            if (!$client) { tgSendMessage($chatId, "Aun no estas vinculado. Usa /vincular CODIGO."); return; }
            $period = date('Y-m');
            $st = $pdo->prepare("
                SELECT
                  COUNT(*) AS total,
                  SUM(CASE WHEN u.status='approved' THEN 1 ELSE 0 END) AS approved,
                  SUM(CASE WHEN u.status='extracted' THEN 1 ELSE 0 END) AS pending,
                  COALESCE(SUM(CASE WHEN e.doc_type='compra' THEN e.itbis ELSE 0 END),0) AS itbis_compras,
                  COALESCE(SUM(CASE WHEN e.doc_type='venta'  THEN e.itbis ELSE 0 END),0) AS itbis_ventas
                FROM invoice_uploads u
                LEFT JOIN invoice_extractions e ON e.upload_id = u.id
                WHERE u.client_id = ? AND (e.period = ? OR (e.period IS NULL AND DATE_FORMAT(u.created_at,'%Y-%m') = ?))
            ");
            $st->execute([$client['client_id'], $period, $period]);
            $s = $st->fetch();
            $itbisBalance = (float)$s['itbis_ventas'] - (float)$s['itbis_compras'];
            tgSendMessage($chatId, implode("\n", [
                "<b>Resumen " . formatPeriod($period) . "</b>",
                "Facturas subidas: <b>" . (int)$s['total'] . "</b>",
                "Por revisar: " . (int)$s['pending'],
                "Aprobadas: " . (int)$s['approved'],
                "ITBIS pagado (606): RD$ " . number_format((float)$s['itbis_compras'], 2),
                "ITBIS cobrado (607): RD$ " . number_format((float)$s['itbis_ventas'], 2),
                "IT-1 estimado: <b>RD$ " . number_format($itbisBalance, 2) . "</b>" . ($itbisBalance > 0 ? ' a pagar' : ' a favor'),
            ]));
            return;
        }
        if (preg_match('/^\//', $text)) {
            tgSendMessage($chatId, "Comando no reconocido. Escribe /ayuda para ver opciones.");
            return;
        }
    }

    if (!$client) {
        tgSendMessage($chatId, "Aun no estas vinculado. Escribe <code>/vincular CODIGO</code> usando el codigo de tu portal.");
        return;
    }

    if (!empty($msg['photo'])) {
        tgProcessPhoto($msg['photo'], $chatId, $client, $caption);
        return;
    }
    if (!empty($msg['document'])) {
        tgProcessPhoto($msg['document'], $chatId, $client, $caption);
        return;
    }

    if ($text !== '') {
        tgSendMessage($chatId, "Recibido. Si quieres procesar una factura, enviamela como foto. Escribe /ayuda para mas opciones.");
    }
}
