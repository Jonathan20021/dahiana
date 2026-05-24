<?php
// lib/telegram.php
// Minimal Telegram Bot API client tailored to the invoice-upload workflow.

if (!defined('TELEGRAM_LIB_LOADED')) define('TELEGRAM_LIB_LOADED', true);

function tgConfig() {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $cfg = [
        'enabled'        => getSetting('telegram_enabled', '0') === '1',
        'token'          => trim(getSetting('telegram_bot_token', '')),
        'username'       => trim(getSetting('telegram_bot_username', '')),
        'webhook_secret' => trim(getSetting('telegram_webhook_secret', '')),
    ];
    return $cfg;
}

function tgApi($method, $params = [], $multipart = false) {
    $cfg = tgConfig();
    if (empty($cfg['token'])) return ['ok' => false, 'error' => 'Token no configurado'];

    // Curl handle reusable -> evita un TLS handshake por llamada.
    // En el flujo de Telegram ahorra ~150-300ms por API call.
    static $ch = null;
    if ($ch === null) $ch = curl_init();

    $url = 'https://api.telegram.org/bot' . $cfg['token'] . '/' . $method;
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => $multipart ? [] : ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TCP_NODELAY    => true,
    ]);
    // getUpdates con long-poll necesita timeout > el "timeout" del parametro.
    // El polling usa timeout=25s, asi que damos 35s de holgura.
    $isLongPoll = ($method === 'getUpdates' && !empty($params['timeout']));
    curl_setopt($ch, CURLOPT_TIMEOUT, $isLongPoll ? 35 : 12);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    if ($multipart) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    } else {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    // OJO: NO cerrar $ch — se reutiliza entre llamadas.

    if ($resp === false) return ['ok' => false, 'error' => 'Red: ' . $err];
    $json = json_decode($resp, true);
    if (!is_array($json)) return ['ok' => false, 'error' => 'Respuesta invalida: ' . substr((string)$resp, 0, 200)];
    if (!($json['ok'] ?? false)) return ['ok' => false, 'error' => 'TG: ' . ($json['description'] ?? "HTTP {$http}")];
    return ['ok' => true, 'result' => $json['result']];
}

function tgSendMessage($chatId, $text, $opts = []) {
    $params = array_merge([
        'chat_id'    => $chatId,
        'text'       => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ], $opts);
    return tgApi('sendMessage', $params);
}

/**
 * Envia un mensaje con teclado inline.
 * $buttons es array de filas; cada fila es array de [text => callback_data] o [text => url].
 */
function tgSendMessageWithKeyboard($chatId, $text, $buttons, $opts = []) {
    $keyboard = ['inline_keyboard' => []];
    foreach ($buttons as $row) {
        $kbRow = [];
        foreach ($row as $btn) {
            if (isset($btn['url'])) {
                $kbRow[] = ['text' => $btn['text'], 'url' => $btn['url']];
            } else {
                $kbRow[] = ['text' => $btn['text'], 'callback_data' => $btn['cb'] ?? $btn['text']];
            }
        }
        $keyboard['inline_keyboard'][] = $kbRow;
    }
    return tgSendMessage($chatId, $text, array_merge(['reply_markup' => json_encode($keyboard)], $opts));
}

/**
 * Edita un mensaje agregandole/cambiandole el teclado inline.
 */
function tgEditMessageWithKeyboard($chatId, $messageId, $text, $buttons = null) {
    $params = [
        'chat_id'    => $chatId,
        'message_id' => $messageId,
        'text'       => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ];
    if ($buttons !== null) {
        $keyboard = ['inline_keyboard' => []];
        foreach ($buttons as $row) {
            $kbRow = [];
            foreach ($row as $btn) {
                if (isset($btn['url'])) {
                    $kbRow[] = ['text' => $btn['text'], 'url' => $btn['url']];
                } else {
                    $kbRow[] = ['text' => $btn['text'], 'callback_data' => $btn['cb'] ?? $btn['text']];
                }
            }
            $keyboard['inline_keyboard'][] = $kbRow;
        }
        $params['reply_markup'] = json_encode($keyboard);
    }
    return tgApi('editMessageText', $params);
}

/**
 * Envia chat action ("typing", "upload_photo", etc) para que el cliente vea actividad.
 * Caduca a los 5s, hay que repetirlo si la operacion dura mas.
 */
function tgSendChatAction($chatId, $action = 'typing') {
    return tgApi('sendChatAction', [
        'chat_id' => $chatId,
        'action'  => $action,
    ]);
}

function tgAnswerCallback($callbackId, $text = '', $alert = false) {
    return tgApi('answerCallbackQuery', [
        'callback_query_id' => $callbackId,
        'text'              => $text,
        'show_alert'        => $alert ? 'true' : 'false',
    ]);
}

function tgEditMessage($chatId, $messageId, $text, $opts = []) {
    return tgApi('editMessageText', array_merge([
        'chat_id'    => $chatId,
        'message_id' => $messageId,
        'text'       => $text,
        'parse_mode' => 'HTML',
        'disable_web_page_preview' => true,
    ], $opts));
}

function tgGetFile($fileId) {
    return tgApi('getFile', ['file_id' => $fileId]);
}

function tgDownloadFile($filePath, $destination) {
    $cfg = tgConfig();
    if (empty($cfg['token'])) return false;
    $url = 'https://api.telegram.org/file/bot' . $cfg['token'] . '/' . ltrim($filePath, '/');
    $fp  = fopen($destination, 'wb');
    if (!$fp) return false;
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    $ok = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    if (!$ok || $http >= 400) {
        @unlink($destination);
        return false;
    }
    return filesize($destination) > 0;
}

function tgSetWebhook($url, $secret = '') {
    $params = ['url' => $url];
    if (!empty($secret)) $params['secret_token'] = $secret;
    return tgApi('setWebhook', $params);
}

function tgDeleteWebhook() {
    return tgApi('deleteWebhook', []);
}

function tgGetWebhookInfo() {
    return tgApi('getWebhookInfo', []);
}

function tgGetMe() {
    return tgApi('getMe', []);
}

// --------------------------------------------------------------------------
// State machine helpers
// --------------------------------------------------------------------------
function tgGetState($chatId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT state, data_json FROM telegram_state WHERE chat_id=?");
    $stmt->execute([$chatId]);
    $row = $stmt->fetch();
    if (!$row) return ['state' => 'idle', 'data' => []];
    return [
        'state' => $row['state'] ?? 'idle',
        'data'  => $row['data_json'] ? (json_decode($row['data_json'], true) ?: []) : [],
    ];
}

function tgSetState($chatId, $state, $data = []) {
    global $pdo;
    $stmt = $pdo->prepare("
        INSERT INTO telegram_state (chat_id, state, data_json) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE state=VALUES(state), data_json=VALUES(data_json)
    ");
    $stmt->execute([$chatId, $state, $data ? json_encode($data, JSON_UNESCAPED_UNICODE) : null]);
}

function tgClientForChat($chatId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT l.*, u.id AS client_id, u.name, u.business_name, u.rnc
        FROM telegram_links l
        JOIN users u ON u.id = l.client_id
        WHERE l.chat_id = ? AND l.active = 1
        LIMIT 1
    ");
    $stmt->execute([$chatId]);
    return $stmt->fetch();
}

/**
 * Generates (or returns) a unique link code for a client (8 chars, A-Z 0-9).
 */
function tgEnsureLinkCode($clientId) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT telegram_link_code FROM users WHERE id=?");
    $stmt->execute([$clientId]);
    $code = $stmt->fetchColumn();
    if ($code) return $code;
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    do {
        $candidate = '';
        for ($i = 0; $i < 8; $i++) $candidate .= $alphabet[random_int(0, strlen($alphabet)-1)];
        $check = $pdo->prepare("SELECT id FROM users WHERE telegram_link_code=?");
        $check->execute([$candidate]);
    } while ($check->fetchColumn());
    $pdo->prepare("UPDATE users SET telegram_link_code=? WHERE id=?")->execute([$candidate, $clientId]);
    return $candidate;
}

function tgFindClientByCode($code) {
    global $pdo;
    $code = strtoupper(trim($code));
    if ($code === '') return null;
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.business_name, u.email
        FROM users u
        LEFT JOIN roles r ON r.slug = u.role
        WHERE u.telegram_link_code = ?
          AND COALESCE(r.access_level, CASE WHEN u.role='admin' THEN 'admin' ELSE 'client' END) = 'client'
        LIMIT 1
    ");
    $stmt->execute([$code]);
    return $stmt->fetch() ?: null;
}

function tgLinkClient($clientId, $chatId, $from) {
    global $pdo;
    $stmt = $pdo->prepare("
        INSERT INTO telegram_links (client_id, chat_id, username, first_name, last_name, last_seen_at)
        VALUES (?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
            client_id  = VALUES(client_id),
            username   = VALUES(username),
            first_name = VALUES(first_name),
            last_name  = VALUES(last_name),
            last_seen_at = NOW(),
            active     = 1
    ");
    $stmt->execute([
        $clientId,
        $chatId,
        $from['username']  ?? null,
        $from['first_name'] ?? null,
        $from['last_name'] ?? null,
    ]);
}

function tgTouchLastSeen($chatId) {
    global $pdo;
    $pdo->prepare("UPDATE telegram_links SET last_seen_at=NOW() WHERE chat_id=?")->execute([$chatId]);
}

function tgUnlink($chatId) {
    global $pdo;
    $pdo->prepare("UPDATE telegram_links SET active=0 WHERE chat_id=?")->execute([$chatId]);
    $pdo->prepare("DELETE FROM telegram_state WHERE chat_id=?")->execute([$chatId]);
}

// --------------------------------------------------------------------------
// Texts
// --------------------------------------------------------------------------
function tgWelcomeText($companyName) {
    return implode("\n", [
        "👋 <b>Bienvenido a {$companyName}</b>",
        "",
        "Soy el asistente que recibe tus facturas y las procesa con IA para tu 606, 607 e IT-1.",
        "",
        "🔗 <b>Como vincular tu cuenta</b>",
        "1. Entra a tu portal y abre <b>Mi perfil</b>.",
        "2. Copia el <i>codigo de vinculacion</i> de 8 caracteres.",
        "3. Aqui escribe: <code>/vincular CODIGO</code>",
        "",
        "Cuando estes vinculado:",
        "📷 Envia <b>fotos</b> de tus facturas (o PDF) y la IA lee RNC, NCF, ITBIS y total.",
        "💬 Puedes agregar un texto al enviar la foto y se guarda como nota.",
        "📤 Tambien funciona si reenvias varias facturas a la vez.",
        "",
        "📌 Escribe /ayuda para ver todos los comandos.",
    ]);
}

function tgHelpText() {
    return implode("\n", [
        "📚 <b>Comandos del bot</b>",
        "",
        "📷 <b>Envia foto o PDF</b> de tu factura → la IA hace todo.",
        "Tip: puedes agregar texto (caption) y se guarda como nota.",
        "",
        "📊 /estado — resumen del mes (facturas + ITBIS)",
        "💰 /saldo [YYYY-MM] — IT-1 ITBIS del periodo",
        "📅 /vencimientos — calendario DGII",
        "📋 /historial [N] — tus ultimas facturas (default 5)",
        "🔎 /factura ID — detalle de una factura especifica",
        "🩺 /diag — diagnostico de tu cuenta",
        "🔗 /vincular CODIGO — conectar tu cuenta",
        "👋 /salir — desvincular este chat",
        "❓ /ayuda — este mensaje",
    ]);
}

function tgWelcomeAfterLink($companyName, $label) {
    return implode("\n", [
        "✅ <b>Listo!</b> Cuenta vinculada con <b>" . htmlspecialchars($label) . "</b>.",
        "",
        "Ya puedes enviarme <b>fotos de tus facturas</b> y la IA las procesa al instante.",
        "",
        "💡 <b>Tips rapidos:</b>",
        "• Busca buena iluminacion al fotografiar.",
        "• Puedes mandar PDF tambien.",
        "• Agregar un texto en la foto se guarda como nota.",
        "• Usa /estado para ver tu resumen del mes.",
        "• Usa /vencimientos para ver cuando vencen tus formularios DGII.",
    ]);
}
