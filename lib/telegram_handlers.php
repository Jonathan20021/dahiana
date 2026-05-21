<?php
// lib/telegram_handlers.php
// Procesa updates de Telegram (mensajes, fotos, documentos).
// Compartido entre telegram_webhook.php y telegram_poll.php.

if (!defined('TG_HANDLERS_LOADED')) define('TG_HANDLERS_LOADED', true);

function tgRespondError($chatId, $msg) {
    tgSendMessage($chatId, "<b>Hubo un problema</b>\n{$msg}");
}

function tgProcessPhoto(array $photoOrDoc, int $chatId, array $client, string $caption = '') {
    global $pdo;

    // Rate limit: max 30 facturas/hora por chat
    if (!aiCheckRateLimit($chatId, 30)) {
        tgSendMessage($chatId, "⚠️ Has enviado muchas facturas en poco tiempo. Por seguridad pausamos temporalmente. Intenta de nuevo en 1 hora o contacta a tu asesor si es urgente.");
        return;
    }

    if (isset($photoOrDoc[0]) && is_array($photoOrDoc[0])) {
        usort($photoOrDoc, fn($a,$b) => ($b['file_size'] ?? 0) <=> ($a['file_size'] ?? 0));
        $file = $photoOrDoc[0];
    } else {
        $file = $photoOrDoc;
    }
    $fileId = $file['file_id'] ?? null;
    if (!$fileId) { tgRespondError($chatId, 'No pude leer el archivo.'); return; }

    // Validacion de tamano max (mismo limite que portal web)
    $sizeLimit = max(1, (int)getSetting('openai_max_size_mb', '12')) * 1024 * 1024;
    if (!empty($file['file_size']) && (int)$file['file_size'] > $sizeLimit) {
        tgSendMessage($chatId, "Archivo demasiado grande. Maximo " . getSetting('openai_max_size_mb', '12') . " MB.");
        return;
    }

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

    // Feedback instantaneo antes de llamar a la IA (puede tardar 5-10s)
    $ack = tgSendMessage($chatId, "Recibida. Procesando con IA...");
    $ackMessageId = (int)($ack['result']['message_id'] ?? 0);

    $autoProcess = getSetting('openai_auto_process', '1') === '1' && getSetting('openai_enabled', '1') === '1';
    if (!$autoProcess) {
        $fallback = "Factura recibida. Tu asesor la procesara con IA en breve.";
        if ($ackMessageId) tgEditMessage($chatId, $ackMessageId, $fallback);
        else tgSendMessage($chatId, $fallback);
        return;
    }

    // Intentar despachar a worker en background para no bloquear el polling loop.
    // Asi el bot puede atender otras fotos mientras esta procesa.
    $spawned = tgSpawnAiWorker($uploadId, $chatId, $ackMessageId);
    if ($spawned) return;

    // Fallback: si no se pudo spawn (exec deshabilitado, etc.), procesar inline.
    $res = aiProcessUpload($uploadId);
    if (!$res['ok']) {
        $errMsg = "La IA fallo:\n<i>" . htmlspecialchars($res['error']) . "</i>\nEl asesor la procesara manualmente.";
        if ($ackMessageId) tgEditMessage($chatId, $ackMessageId, $errMsg);
        else tgSendMessage($chatId, $errMsg);
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
        if ($ackMessageId) tgEditMessage($chatId, $ackMessageId, $msg);
        else tgSendMessage($chatId, $msg);
        return;
    }
    $fallback = "Factura recibida. Tu asesor la procesara con IA en breve.";
    if ($ackMessageId) tgEditMessage($chatId, $ackMessageId, $fallback);
    else tgSendMessage($chatId, $fallback);
}

/**
 * Lanza ai_telegram_worker.php en background. Devuelve true si pudo spawn.
 * Si la funcion exec/proc_open esta deshabilitada o el binario php no se halla,
 * devuelve false y el caller hace fallback a procesamiento sync.
 */
function tgSpawnAiWorker(int $uploadId, int $chatId, int $ackMessageId): bool {
    // Si el host deshabilito exec/proc_open, no hay forma de fork-detach
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('exec', $disabled, true) || !function_exists('exec')) {
        return false;
    }
    if (in_array('proc_open', $disabled, true) || !function_exists('proc_open')) {
        // exec con & funciona en linux sin proc_open
    }

    // Detectar binario PHP: PHP_BINARY es el binario CLI corriendo.
    $phpBin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
    $worker = __DIR__ . '/../ai_telegram_worker.php';
    if (!is_file($worker)) return false;

    $isWin = stripos(PHP_OS, 'WIN') === 0;
    if ($isWin) {
        // Windows: lanzar con start /B (background sin ventana)
        $cmd = sprintf('start /B "" %s %s %d %d %d',
            escapeshellarg($phpBin),
            escapeshellarg($worker),
            $uploadId, $chatId, $ackMessageId);
        @pclose(@popen($cmd, 'r'));
        return true;
    }

    // Linux/macOS: ejecutar en background con nohup y redirect a /dev/null.
    // El & al final desconecta el proceso del shell padre.
    $cmd = sprintf('nohup %s %s %d %d %d > /dev/null 2>&1 & echo $!',
        escapeshellarg($phpBin),
        escapeshellarg($worker),
        $uploadId, $chatId, $ackMessageId);
    $pid = @exec($cmd);
    return $pid !== false && $pid !== '';
}

function tgHandleMessage(array $msg) {
    global $pdo;
    $chatId = (int)($msg['chat']['id'] ?? 0);
    if ($chatId === 0) return;
    $from    = $msg['from'] ?? [];
    $text    = trim((string)($msg['text'] ?? ''));
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
            // Anti-bruteforce: max 5 intentos /vincular por chat por hora
            if (!aiCheckRateLimit($chatId * -1, 5)) {
                tgSendMessage($chatId, "Demasiados intentos de vinculacion. Espera una hora antes de intentar de nuevo.");
                return;
            }
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
        if (preg_match('/^\/(vencimientos|agenda)(?:@\w+)?/i', $text)) {
            if (!$client) { tgSendMessage($chatId, "Aun no estas vinculado. Usa /vincular CODIGO."); return; }
            // Solo lectura: la consultora controla que obligaciones aplican al cliente.
            $obl = $pdo->prepare("
                SELECT obligation_type, period, due_date, status
                FROM tax_obligations
                WHERE client_id = ? AND status IN ('pendiente','vencido') AND dismissed_at IS NULL
                ORDER BY due_date ASC
                LIMIT 8
            ");
            $obl->execute([$client['client_id']]);
            $rows = $obl->fetchAll();
            if (empty($rows)) {
                // Distinguir entre "al dia" y "el asesor no ha activado nada"
                $subs = function_exists('getClientObligationSubscriptions')
                    ? getClientObligationSubscriptions((int)$client['client_id'], false)
                    : [];
                $enabledCount = count(array_filter($subs));
                if ($enabledCount === 0) {
                    tgSendMessage($chatId, "Aun no hay obligaciones DGII configuradas para tu cuenta. Tu asesor las gestiona segun la iguala acordada.");
                } else {
                    tgSendMessage($chatId, "Estas al dia. No tienes vencimientos cercanos en tus " . $enabledCount . " obligacion(es) activas.");
                }
                return;
            }
            $lines = ["<b>Proximos vencimientos DGII</b>", ""];
            foreach ($rows as $r) {
                $days = (int)((strtotime($r['due_date']) - strtotime(date('Y-m-d'))) / 86400);
                $when = $days < 0 ? "🔴 vencido hace " . abs($days) . " d"
                      : ($days === 0 ? "⏰ HOY" : ($days <= 5 ? "🟠 en {$days} d" : "🟢 en {$days} d"));
                $lines[] = "• <b>" . htmlspecialchars($r['obligation_type']) . "</b> · " . htmlspecialchars($r['period']) . " · " . $when . " (" . date('d/m', strtotime($r['due_date'])) . ")";
            }
            tgSendMessage($chatId, implode("\n", $lines));
            return;
        }
        if (preg_match('/^\/(saldo|iva|itbis)(?:@\w+)?(?:\s+(\d{4}-\d{2}))?/i', $text, $m)) {
            if (!$client) { tgSendMessage($chatId, "Aun no estas vinculado. Usa /vincular CODIGO."); return; }
            $p = !empty($m[2]) ? $m[2] : date('Y-m');
            $q = $pdo->prepare("
                SELECT
                  COALESCE(SUM(CASE WHEN e.doc_type='compra' THEN e.itbis ELSE 0 END),0) AS itbis_compras,
                  COALESCE(SUM(CASE WHEN e.doc_type='venta'  THEN e.itbis ELSE 0 END),0) AS itbis_ventas,
                  COUNT(*) AS total
                FROM invoice_uploads u
                LEFT JOIN invoice_extractions e ON e.upload_id = u.id
                WHERE u.client_id = ? AND e.period = ?
            ");
            $q->execute([$client['client_id'], $p]);
            $s = $q->fetch();
            $balance = (float)$s['itbis_ventas'] - (float)$s['itbis_compras'];
            $emoji = $balance > 0 ? '🔴' : '🟢';
            tgSendMessage($chatId, implode("\n", [
                "<b>IT-1 ITBIS · " . formatPeriod($p) . "</b>",
                "",
                "ITBIS Cobrado: RD$ " . number_format((float)$s['itbis_ventas'], 2),
                "ITBIS Pagado: RD$ " . number_format((float)$s['itbis_compras'], 2),
                "",
                $emoji . " <b>" . ($balance > 0 ? "A pagar" : "Saldo a favor") . ": RD$ " . number_format(abs($balance), 2) . "</b>",
                "",
                "Documentos del periodo: " . (int)$s['total'],
            ]));
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

function tgProcessUpdate(array $update) {
    if (isset($update['message'])) {
        tgHandleMessage($update['message']);
    } elseif (isset($update['edited_message'])) {
        tgHandleMessage($update['edited_message']);
    } elseif (isset($update['callback_query'])) {
        $cb = $update['callback_query'];
        tgAnswerCallback($cb['id'] ?? '');
    }
}
