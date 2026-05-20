<?php
// lib/telegram.php
// Minimal Telegram Bot API client tailored to the invoice-upload workflow.

if (!defined('TELEGRAM_LIB_LOADED')) define('TELEGRAM_LIB_LOADED', true);

function tgConfig() {
    return [
        'enabled'        => getSetting('telegram_enabled', '0') === '1',
        'token'          => trim(getSetting('telegram_bot_token', '')),
        'username'       => trim(getSetting('telegram_bot_username', '')),
        'webhook_secret' => trim(getSetting('telegram_webhook_secret', '')),
    ];
}

function tgApi($method, $params = [], $multipart = false) {
    $cfg = tgConfig();
    if (empty($cfg['token'])) return ['ok' => false, 'error' => 'Token no configurado'];

    $url = 'https://api.telegram.org/bot' . $cfg['token'] . '/' . $method;
    $ch  = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    if ($multipart) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
    } else {
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    }
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

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
    curl_setopt($ch, CURLOPT_TIMEOUT, 90);
    $ok = curl_exec($ch);
    curl_close($ch);
    fclose($fp);
    return $ok && filesize($destination) > 0;
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
        "<b>Bienvenido a {$companyName}</b>",
        "",
        "Soy el asistente que recibe tus facturas y las procesa con IA para tu 606, 607 e IT-1.",
        "",
        "<b>Como vincular tu cuenta</b>",
        "1. Entra a tu portal y abre el panel principal.",
        "2. Copia tu <i>codigo de vinculacion</i> de 8 caracteres.",
        "3. Aqui escribe: <code>/vincular CODIGO</code>",
        "",
        "Cuando estes vinculado, simplemente envia <b>fotos de tus facturas</b> y yo me encargo del resto.",
        "",
        "<b>Comandos disponibles</b>",
        "/vincular CODIGO  - vincula tu cuenta",
        "/estado  - resumen general del periodo",
        "/saldo  - tu IT-1 (ITBIS a pagar)",
        "/vencimientos  - proximas obligaciones DGII",
        "/ayuda  - ver esta lista",
        "/salir  - desvincular este chat",
    ]);
}

function tgHelpText() {
    return implode("\n", [
        "<b>Como funciona el flujo</b>",
        "1. Envia una foto a este chat (puedes enviar varias).",
        "2. La IA lee RNC, NCF, monto, ITBIS y categoria automaticamente.",
        "3. Tu asesor valida y la registra en 606/607.",
        "",
        "<b>Comandos</b>",
        "/estado  - resumen del mes (facturas + ITBIS)",
        "/saldo [YYYY-MM]  - IT-1 ITBIS del periodo",
        "/vencimientos  - calendario DGII",
        "/salir  - desvincula este chat",
        "/ayuda  - este mensaje",
    ]);
}
