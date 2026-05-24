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

    // ========= ACK INMEDIATO (el mensaje en si ya hace de indicador, ahorra ~300ms) =========
    $ack = tgSendMessage($chatId, "📄 Recibida. Procesando con IA...");
    $ackMessageId = (int)($ack['result']['message_id'] ?? 0);

    // Rate limit: max 30 facturas/hora por chat
    if (!aiCheckRateLimit($chatId, 30)) {
        $msg = "⚠️ Has enviado muchas facturas en poco tiempo. Por seguridad pausamos temporalmente. Intenta de nuevo en 1 hora o contacta a tu asesor si es urgente.";
        if ($ackMessageId) tgEditMessage($chatId, $ackMessageId, $msg);
        else tgSendMessage($chatId, $msg);
        return;
    }

    if (isset($photoOrDoc[0]) && is_array($photoOrDoc[0])) {
        usort($photoOrDoc, fn($a,$b) => ($b['file_size'] ?? 0) <=> ($a['file_size'] ?? 0));
        $file = $photoOrDoc[0];
    } else {
        $file = $photoOrDoc;
    }
    $fileId = $file['file_id'] ?? null;
    if (!$fileId) {
        if ($ackMessageId) tgEditMessage($chatId, $ackMessageId, '<b>No pude leer el archivo</b>');
        return;
    }

    // Validacion de tamano max
    $sizeLimit = max(1, (int)getSetting('openai_max_size_mb', '12')) * 1024 * 1024;
    if (!empty($file['file_size']) && (int)$file['file_size'] > $sizeLimit) {
        $msg = "Archivo demasiado grande. Maximo " . getSetting('openai_max_size_mb', '12') . " MB.";
        if ($ackMessageId) tgEditMessage($chatId, $ackMessageId, $msg);
        return;
    }

    $info = tgGetFile($fileId);
    if (!$info['ok']) {
        if ($ackMessageId) tgEditMessage($chatId, $ackMessageId, 'No pude descargar: ' . htmlspecialchars($info['error']));
        return;
    }

    $remotePath = $info['result']['file_path'] ?? '';
    if ($remotePath === '') {
        if ($ackMessageId) tgEditMessage($chatId, $ackMessageId, 'Archivo sin ruta.');
        return;
    }

    $mime = $file['mime_type'] ?? '';
    if ($mime === '') {
        $ext = strtolower(pathinfo($remotePath, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'jpg','jpeg' => 'image/jpeg',
            'png'        => 'image/png',
            'webp'       => 'image/webp',
            'heic','heif'=> 'image/heic',
            'pdf'        => 'application/pdf',
            default      => 'image/jpeg',
        };
    }
    $isImage = strpos($mime, 'image/') === 0;
    $isPdf   = $mime === 'application/pdf';
    if (!$isImage && !$isPdf) {
        $msg = "Solo proceso fotos (JPG, PNG, WEBP, HEIC) o PDF. Recibi <code>" . htmlspecialchars($mime) . "</code>.\nReenvialo como foto o exportalo a PDF.";
        if ($ackMessageId) tgEditMessage($chatId, $ackMessageId, $msg);
        return;
    }

    $ext = pathinfo($remotePath, PATHINFO_EXTENSION) ?: 'jpg';
    $filename = 'inv_' . $client['client_id'] . '_tg_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.' . strtolower($ext);
    $dest = aiUploadsDir() . '/' . $filename;
    if (!tgDownloadFile($remotePath, $dest)) {
        if ($ackMessageId) tgEditMessage($chatId, $ackMessageId, 'No pude descargar el archivo de Telegram.');
        return;
    }
    $size = @filesize($dest);
    $sha  = @hash_file('sha256', $dest);

    if ($sha && aiFindDuplicateUpload((int)$client['client_id'], $sha) > 0) {
        @unlink($dest);
        if ($ackMessageId) tgEditMessage($chatId, $ackMessageId, "Esta factura ya la habias subido antes. La ignore.");
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
    if (!$autoProcess) {
        $fallback = "Factura recibida. Tu asesor la procesara con IA en breve.";
        if ($ackMessageId) tgEditMessage($chatId, $ackMessageId, $fallback);
        else tgSendMessage($chatId, $fallback);
        return;
    }

    // Despacho a worker en background: SOLO si el admin lo activo explicitamente
    // y solo despues de probar que arranca en su host. Por defecto procesamos inline
    // (mas lento si llegan varias fotos a la vez, pero garantizado a funcionar).
    if (getSetting('telegram_use_bg_worker', '0') === '1') {
        if (tgSpawnAiWorker($uploadId, $chatId, $ackMessageId)) {
            return; // worker tomara desde aqui
        }
        // Si el spawn fallo, hacemos log y caemos a inline
        @file_put_contents(
            __DIR__ . '/../uploads/logs/telegram_poll.log',
            '[' . date('Y-m-d H:i:s') . "] bg_worker spawn FAILED for upload={$uploadId}, falling back to inline\n",
            FILE_APPEND
        );
    }

    // Procesamiento inline (default): el polling loop espera al resultado de IA
    // pero el ack ya fue enviado, asi que el cliente no nota la diferencia.
    $res = aiProcessUpload($uploadId);
    if (!$res['ok']) {
        $errMsg = "⚠️ La IA tuvo un problema:\n<i>" . htmlspecialchars($res['error']) . "</i>\n\nNo te preocupes — tu asesor la procesara manualmente.";
        if ($ackMessageId) tgEditMessage($chatId, $ackMessageId, $errMsg);
        else tgSendMessage($chatId, $errMsg);
        return;
    }
    $exQ = $pdo->prepare("SELECT doc_type, total, itbis, ncf, counterparty_name, confidence, period FROM invoice_extractions WHERE upload_id=? ORDER BY id DESC LIMIT 1");
    $exQ->execute([$uploadId]);
    $e = $exQ->fetch();
    if ($e) {
        $conf = round(((float)$e['confidence']) * 100);
        // Emoji por confianza
        $confEmoji = $conf >= 90 ? '✅' : ($conf >= 70 ? '⚠️' : '❓');
        $docEmoji  = $e['doc_type'] === 'venta' ? '📤' : '📥';
        $msg = implode("\n", [
            "<b>Factura procesada</b> {$confEmoji}",
            "",
            $docEmoji . " " . ($e['doc_type'] === 'venta' ? "Venta (formulario 607)" : "Compra (formulario 606)"),
            "📅 Periodo: " . htmlspecialchars($e['period'] ?? '-'),
            "🏢 Contraparte: <i>" . htmlspecialchars($e['counterparty_name'] ?: 'No leida'). "</i>",
            "🔢 NCF: <code>" . htmlspecialchars($e['ncf'] ?: 'No leido') . "</code>",
            "💵 Total: <b>RD$ " . number_format((float)$e['total'], 2) . "</b>",
            "📊 ITBIS: RD$ " . number_format((float)$e['itbis'], 2),
            "🤖 Confianza IA: <b>{$conf}%</b>",
            "",
            ($conf >= 90
                ? "Lista para que tu asesor la valide."
                : ($conf >= 70
                    ? "Tu asesor revisara los campos dudosos."
                    : "Foto con baja calidad. Tu asesor la revisara con cuidado.")),
        ]);

        // Botones de accion rapida
        $buttons = [
            [
                ['text' => '📊 Mi estado', 'cb' => 'cmd:estado'],
                ['text' => '📅 Vencimientos', 'cb' => 'cmd:vencimientos'],
            ],
            [
                ['text' => '🔁 Subir otra', 'cb' => 'cmd:subir_otra'],
            ],
        ];

        if ($ackMessageId) {
            tgEditMessageWithKeyboard($chatId, $ackMessageId, $msg, $buttons);
        } else {
            tgSendMessageWithKeyboard($chatId, $msg, $buttons);
        }
        return;
    }
    $fallback = "Factura recibida. Tu asesor la procesara con IA en breve.";
    if ($ackMessageId) tgEditMessage($chatId, $ackMessageId, $fallback);
    else tgSendMessage($chatId, $fallback);
}

/**
 * Lanza ai_telegram_worker.php en background. Devuelve true SOLO si el worker
 * realmente arranca (verificado leyendo su log). Si falla, devuelve false y el
 * caller hace fallback a procesamiento sync.
 */
function tgSpawnAiWorker(int $uploadId, int $chatId, int $ackMessageId): bool {
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('exec', $disabled, true) || !function_exists('exec')) {
        return false;
    }

    $phpBin = defined('PHP_BINARY') && PHP_BINARY ? PHP_BINARY : 'php';
    $worker = __DIR__ . '/../ai_telegram_worker.php';
    if (!is_file($worker)) return false;

    $workerLog = __DIR__ . '/../uploads/logs/ai_telegram_worker.log';
    $beforeSize = is_file($workerLog) ? @filesize($workerLog) : 0;

    $isWin = stripos(PHP_OS, 'WIN') === 0;
    if ($isWin) {
        $cmd = sprintf('start /B "" %s %s %d %d %d',
            escapeshellarg($phpBin),
            escapeshellarg($worker),
            $uploadId, $chatId, $ackMessageId);
        @pclose(@popen($cmd, 'r'));
    } else {
        // Linux: probar nohup + & en background
        $cmd = sprintf('nohup %s %s %d %d %d > /dev/null 2>&1 &',
            escapeshellarg($phpBin),
            escapeshellarg($worker),
            $uploadId, $chatId, $ackMessageId);
        @exec($cmd);
    }

    // Verificar que el worker REALMENTE arranco esperando 1.5s y comparando
    // tamano del log. Si no escribio nada, asumimos que fallo silenciosamente.
    usleep(1500000); // 1.5s
    $afterSize = is_file($workerLog) ? @filesize($workerLog) : 0;
    if ($afterSize > $beforeSize) {
        return true; // worker escribio "start" -> arranco
    }
    // Tambien aceptamos si el upload cambio a 'processing' o ya termino
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT status FROM invoice_uploads WHERE id=?");
        $stmt->execute([$uploadId]);
        $st = (string)$stmt->fetchColumn();
        if (in_array($st, ['processing','extracted','approved','error'], true)) return true;
    } catch (PDOException $e) {}
    return false;
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
                    $buttons = [
                        [['text' => '📊 Mi estado', 'cb' => 'cmd:estado']],
                        [['text' => '📅 Vencimientos', 'cb' => 'cmd:vencimientos'], ['text' => '❓ Ayuda', 'cb' => 'cmd:ayuda']],
                    ];
                    tgSendMessageWithKeyboard($chatId, tgWelcomeAfterLink($company, $label), $buttons);
                    return;
                }
                tgSendMessage($chatId, "❌ Codigo invalido. Pide tu codigo de vinculacion al equipo o copialo desde tu portal en <b>Mi perfil</b>.");
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
            $buttons = [
                [['text' => '📊 Mi estado', 'cb' => 'cmd:estado']],
                [['text' => '📅 Vencimientos', 'cb' => 'cmd:vencimientos'], ['text' => '❓ Ayuda', 'cb' => 'cmd:ayuda']],
            ];
            tgSendMessageWithKeyboard($chatId, tgWelcomeAfterLink($company, $label), $buttons);
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
        if (preg_match('/^\/historial(?:@\w+)?(?:\s+(\d+))?/i', $text, $m)) {
            if (!$client) { tgSendMessage($chatId, "Aun no estas vinculado. Usa /vincular CODIGO."); return; }
            $limit = !empty($m[1]) ? min(20, max(1, (int)$m[1])) : 5;
            $q = $pdo->prepare("
                SELECT u.id, u.status, u.created_at,
                       e.doc_type, e.total, e.ncf, e.counterparty_name, e.confidence
                FROM invoice_uploads u
                LEFT JOIN invoice_extractions e ON e.upload_id = u.id
                WHERE u.client_id = ?
                ORDER BY u.created_at DESC
                LIMIT {$limit}
            ");
            $q->execute([$client['client_id']]);
            $rows = $q->fetchAll();
            if (empty($rows)) {
                tgSendMessage($chatId, "Aun no has subido facturas. Envia una foto cuando quieras.");
                return;
            }
            $lines = ["<b>Tus ultimas {$limit} facturas</b>", ""];
            foreach ($rows as $r) {
                $emoji = match ($r['status']) {
                    'approved'  => '✅',
                    'extracted' => '⏳',
                    'error'     => '❌',
                    'rejected'  => '🚫',
                    default     => '📄',
                };
                $when = date('d/m H:i', strtotime($r['created_at']));
                $total = $r['total'] ? 'RD$ ' . number_format((float)$r['total'], 2) : '—';
                $cp = mb_substr($r['counterparty_name'] ?: '(sin contraparte)', 0, 28);
                $lines[] = "{$emoji} <code>#{$r['id']}</code> · {$when} · " . htmlspecialchars($cp) . " · <b>{$total}</b>";
            }
            $lines[] = "";
            $lines[] = "<i>Escribe /factura ID para ver detalles de una.</i>";
            tgSendMessage($chatId, implode("\n", $lines));
            return;
        }
        if (preg_match('/^\/factura(?:@\w+)?\s+(\d+)/i', $text, $m)) {
            if (!$client) { tgSendMessage($chatId, "Aun no estas vinculado. Usa /vincular CODIGO."); return; }
            $id = (int)$m[1];
            $q = $pdo->prepare("
                SELECT u.id, u.status, u.created_at, u.source, u.error_message,
                       e.doc_type, e.period, e.date_doc, e.rnc, e.ncf, e.ncf_type, e.counterparty_name,
                       e.subtotal, e.itbis, e.total, e.confidence, e.concept
                FROM invoice_uploads u
                LEFT JOIN invoice_extractions e ON e.upload_id = u.id
                WHERE u.id = ? AND u.client_id = ?
            ");
            $q->execute([$id, $client['client_id']]);
            $r = $q->fetch();
            if (!$r) {
                tgSendMessage($chatId, "No encuentro la factura #{$id}. Usa /historial para ver tus facturas.");
                return;
            }
            $statusEmoji = match ($r['status']) {
                'approved'  => '✅ Aprobada',
                'extracted' => '⏳ Por aprobar',
                'error'     => '❌ Error',
                'rejected'  => '🚫 Rechazada',
                'processing'=> '🔄 Procesando',
                'uploaded'  => '📤 Subida',
                default     => $r['status'],
            };
            $lines = [
                "<b>Factura #{$r['id']}</b>",
                "Estado: {$statusEmoji}",
                "Subida: " . date('d/m/Y H:i', strtotime($r['created_at'])),
                "",
            ];
            if ($r['doc_type']) {
                $conf = round(((float)$r['confidence']) * 100);
                $lines[] = ($r['doc_type'] === 'venta' ? '📤 Venta (607)' : '📥 Compra (606)') . ' · Periodo ' . htmlspecialchars($r['period'] ?? '-');
                if ($r['date_doc']) $lines[] = "📅 Fecha factura: " . date('d/m/Y', strtotime($r['date_doc']));
                $lines[] = "🏢 " . htmlspecialchars($r['counterparty_name'] ?: '-');
                $lines[] = "🔢 NCF: <code>" . htmlspecialchars($r['ncf'] ?: '-') . "</code>";
                if ($r['concept']) $lines[] = "📝 " . htmlspecialchars(mb_substr($r['concept'], 0, 80));
                $lines[] = "";
                $lines[] = "Subtotal: RD$ " . number_format((float)$r['subtotal'], 2);
                $lines[] = "ITBIS: RD$ " . number_format((float)$r['itbis'], 2);
                $lines[] = "<b>Total: RD$ " . number_format((float)$r['total'], 2) . "</b>";
                $lines[] = "🤖 Confianza IA: {$conf}%";
            } elseif ($r['error_message']) {
                $lines[] = "<i>Error: " . htmlspecialchars(mb_substr($r['error_message'], 0, 200)) . "</i>";
            } else {
                $lines[] = "<i>Aun no procesada por la IA.</i>";
            }
            tgSendMessage($chatId, implode("\n", $lines));
            return;
        }
        if (preg_match('/^\/diag(?:nostico)?(?:@\w+)?/i', $text)) {
            if (!$client) { tgSendMessage($chatId, "Aun no estas vinculado."); return; }
            // Diagnostico publico (no expone secrets): contadores de su cuenta
            $c = $pdo->prepare("
                SELECT
                  (SELECT COUNT(*) FROM invoice_uploads WHERE client_id=?) AS total_uploads,
                  (SELECT COUNT(*) FROM invoice_uploads WHERE client_id=? AND status='approved') AS approved,
                  (SELECT COUNT(*) FROM invoice_uploads WHERE client_id=? AND status='extracted') AS pending,
                  (SELECT COUNT(*) FROM invoice_uploads WHERE client_id=? AND status='error') AS errors,
                  (SELECT MAX(created_at) FROM invoice_uploads WHERE client_id=?) AS last_upload
            ");
            $c->execute(array_fill(0, 5, $client['client_id']));
            $d = $c->fetch();
            $last = $d['last_upload'] ? date('d/m/Y H:i', strtotime($d['last_upload'])) : 'nunca';
            tgSendMessage($chatId, implode("\n", [
                "<b>Diagnostico</b>",
                "",
                "✅ Vinculado como: <b>" . htmlspecialchars($client['business_name'] ?: $client['name']) . "</b>",
                "📤 Facturas totales: " . (int)$d['total_uploads'],
                "✅ Aprobadas: " . (int)$d['approved'],
                "⏳ Por revisar: " . (int)$d['pending'],
                "❌ Con error: " . (int)$d['errors'],
                "🕒 Ultima subida: {$last}",
                "",
                "Bot OK · Servidor activo",
            ]));
            return;
        }
        if (preg_match('/^\/(comandos|menu)(?:@\w+)?/i', $text)) {
            tgSendMessage($chatId, tgHelpText());
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
            $balanceEmoji = $itbisBalance > 0 ? '🔴' : '🟢';
            $msg = implode("\n", [
                "<b>📊 Resumen " . formatPeriod($period) . "</b>",
                "",
                "📤 Facturas subidas: <b>" . (int)$s['total'] . "</b>",
                "⏳ Por revisar: " . (int)$s['pending'],
                "✅ Aprobadas: " . (int)$s['approved'],
                "",
                "💰 ITBIS pagado (606): RD$ " . number_format((float)$s['itbis_compras'], 2),
                "💵 ITBIS cobrado (607): RD$ " . number_format((float)$s['itbis_ventas'], 2),
                "",
                $balanceEmoji . " IT-1 estimado: <b>RD$ " . number_format(abs($itbisBalance), 2) . "</b>" . ($itbisBalance > 0 ? ' a pagar' : ' a favor'),
            ]);
            $buttons = [
                [['text' => '📅 Vencimientos', 'cb' => 'cmd:vencimientos'], ['text' => '📋 Historial', 'cb' => 'cmd:historial']],
            ];
            tgSendMessageWithKeyboard($chatId, $msg, $buttons);
            return;
        }
        if (preg_match('/^\//', $text)) {
            $buttons = [
                [['text' => '📊 Estado', 'cb' => 'cmd:estado'], ['text' => '📅 Vencimientos', 'cb' => 'cmd:vencimientos']],
                [['text' => '📋 Historial', 'cb' => 'cmd:historial'], ['text' => '❓ Ayuda', 'cb' => 'cmd:ayuda']],
            ];
            tgSendMessageWithKeyboard($chatId, "🤔 Comando no reconocido. Aqui van las opciones rapidas:", $buttons);
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
        tgHandleCallback($update['callback_query']);
    }
}

/**
 * Maneja clics en botones inline. El callback_data tiene formato 'cmd:xxx'.
 */
function tgHandleCallback(array $cb) {
    global $pdo;
    $cbId   = $cb['id'] ?? '';
    $data   = (string)($cb['data'] ?? '');
    $msg    = $cb['message'] ?? [];
    $chatId = (int)($msg['chat']['id'] ?? 0);

    // Confirmar callback de inmediato para que el spinner desaparezca
    tgAnswerCallback($cbId);

    if ($chatId === 0 || $data === '') return;

    $client = tgClientForChat($chatId);
    if (!$client) {
        tgSendMessage($chatId, "Aun no estas vinculado. Usa /vincular CODIGO.");
        return;
    }

    if ($data === 'cmd:estado') {
        tgRunCommand('estado', $chatId, $client);
    } elseif ($data === 'cmd:vencimientos') {
        tgRunCommand('vencimientos', $chatId, $client);
    } elseif ($data === 'cmd:saldo') {
        tgRunCommand('saldo', $chatId, $client);
    } elseif ($data === 'cmd:subir_otra') {
        tgSendMessage($chatId, "📷 Listo, envia la siguiente foto cuando quieras.");
    } elseif ($data === 'cmd:historial') {
        tgRunCommand('historial', $chatId, $client);
    } elseif ($data === 'cmd:ayuda') {
        tgSendMessage($chatId, tgHelpText());
    }
}

/**
 * Ejecuta un comando como si el usuario lo hubiera escrito.
 * Usado tanto desde texto como desde callbacks de teclado.
 */
function tgRunCommand(string $cmd, int $chatId, array $client) {
    $synthetic = [
        'chat'    => ['id' => $chatId],
        'from'    => ['id' => $chatId],
        'text'    => '/' . $cmd,
    ];
    tgHandleMessage($synthetic);
}
