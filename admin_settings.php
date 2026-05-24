<?php
require_once 'config.php';
requireAuth('admin');
requirePagePermission();

$success = $error = null;

$defaultGreeting = 'te escribimos de tu Asesoria Financiera';
$defaultInvoiceTemplate = "Hola {{client_name}}, {{greeting}}. Tienes un volante de cobro pendiente:\n\n{{document_icon}} *{{concept}}*\n{{amount_icon}} Monto: RD$ {{amount}}\n{{date_icon}} Fecha limite: {{due_date}}\n\nQuedamos a tu disposicion para cualquier consulta.";
$defaultRequestTemplate = "Hola {{client_name}}, {{greeting}} para recordarte que el tramite de *{{request_title}}* se encuentra actualmente *{{status_text}}*.";

// Campos que son secretos: si se envia el valor enmascarado (placeholder), NO se sobreescribe.
$secretFields = ['openai_api_key', 'resend_api_key', 'telegram_bot_token', 'telegram_webhook_secret'];
$secretSentinel = '••••KEEP••••';

function isSecretPlaceholder($value, $sentinel) {
    if ($value === '' || $value === $sentinel) return true;
    // Si solo contiene bullets / asteriscos, tratarlo como placeholder
    $cleaned = preg_replace('/[•*\s]/u', '', $value);
    if ($cleaned === '') return true;
    // Si trae la cadena enmascarada (head + bullets + tail) que renderizamos en pantalla
    if (strpos($value, '•••') !== false) return true;
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    $textFields = [
        'company_name','company_rnc','company_address','company_phone','company_email',
        'company_slogan','company_initials','invoice_note','whatsapp_greeting',
        'whatsapp_invoice_template','whatsapp_request_template',
        'email_from','email_from_name','email_reply_to',
        'openai_model','openai_max_size_mb','openai_auto_approve_threshold',
        'openai_secondary_model',
        'telegram_bot_username',
    ];
    $boolFields = [
        'email_enabled','notify_welcome','notify_invoice','notify_invoice_paid',
        'notify_request','notify_status','notify_comment','notify_invoice_approved',
        'openai_enabled','openai_auto_process','openai_consensus_enabled',
        'telegram_enabled',
    ];

    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach ($textFields as $field) {
        $stmt->execute([$field, trim($_POST[$field] ?? '')]);
    }
    foreach ($boolFields as $field) {
        $stmt->execute([$field, isset($_POST[$field]) ? '1' : '0']);
    }
    foreach ($secretFields as $field) {
        $val = trim($_POST[$field] ?? '');
        if (isSecretPlaceholder($val, $secretSentinel)) {
            // No tocar el valor existente
            continue;
        }
        $stmt->execute([$field, $val]);
    }
    clearSettingsCache();
    $success = "Configuracion guardada exitosamente.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'telegram_set_webhook') {
    $token = trim(getSetting('telegram_bot_token', ''));
    if ($token === '') {
        $error = 'Configura primero el token del bot.';
    } else {
        $secret = trim(getSetting('telegram_webhook_secret', ''));
        if ($secret === '') {
            $secret = bin2hex(random_bytes(8));
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('telegram_webhook_secret', ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$secret]);
            clearSettingsCache();
        }
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        $url   = $proto . '://' . $host . rtrim($scriptDir, '/') . '/telegram_webhook.php';
        // drop_pending_updates=true limpia updates atascados (origen del 409)
        // y deja la cola en estado limpio para el webhook recien configurado.
        $res = tgApi('setWebhook', [
            'url'                  => $url,
            'secret_token'         => $secret,
            'drop_pending_updates' => 'true',
            'allowed_updates'      => json_encode(['message','edited_message','callback_query']),
            // max_connections=1 evita que Telegram abra varias conexiones en paralelo
            // contra el webhook. En shared hosting con limite de "Entry Processes"
            // (cPanel/LiteSpeed) eso disparaba 409 Conflict en las conexiones extra.
            // Como el endpoint ahora cierra en <300ms, Telegram igual puede
            // entregar ~5-10 updates/s.
            'max_connections'      => 1,
        ]);
        if ($res['ok']) {
            $info = tgGetMe();
            if ($info['ok'] && !empty($info['result']['username'])) {
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('telegram_bot_username', ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$info['result']['username']]);
            }
            // Cambiar modo a 'webhook' para que el cron de telegram_poll.php NO
            // borre este webhook ni compita con getUpdates (causa raiz del 409 Conflict).
            $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('telegram_mode', 'webhook') ON DUPLICATE KEY UPDATE setting_value='webhook'")->execute();
            clearSettingsCache();
            $success = 'Webhook conectado en ' . $url . ' (modo: webhook, polling desactivado)';
        } else {
            $error = 'No se pudo conectar el webhook: ' . $res['error'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'telegram_delete_webhook') {
    // drop_pending_updates evita que mensajes viejos lleguen cuando se reactive otra via.
    $res = tgApi('deleteWebhook', ['drop_pending_updates' => 'true']);
    if ($res['ok']) {
        // Volver a modo polling: el cron retomara getUpdates automaticamente.
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('telegram_mode', 'poll') ON DUPLICATE KEY UPDATE setting_value='poll'")->execute();
        clearSettingsCache();
        $success = 'Webhook desconectado. Modo cambiado a polling (cron retomara).';
    } else {
        $error = 'Error: ' . $res['error'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'test_email') {
    $to = trim($_POST['test_to'] ?? '');
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $error = 'Ingresa un correo valido para la prueba.';
    } else {
        $res = sendTestEmail($to);
        if (!empty($res['ok'])) {
            $success = "Correo de prueba enviado a {$to}.";
        } else {
            $error = "Fallo el envio: " . ($res['error'] ?? $res['reason'] ?? 'Error desconocido') . ($res['code'] ? " (HTTP {$res['code']})" : '');
        }
    }
}

$settings = getSettings();
$invoiceTemplate = $settings['whatsapp_invoice_template'] ?? $defaultInvoiceTemplate;
$requestTemplate = $settings['whatsapp_request_template'] ?? $defaultRequestTemplate;
$greeting = $settings['whatsapp_greeting'] ?? $defaultGreeting;

$invoicePreview = renderWhatsAppTemplate($invoiceTemplate, [
    'client_name' => 'Juan Perez', 'greeting' => $greeting,
    'concept' => 'Iguala Mensual - Marzo 2026', 'amount' => '15,000.00',
    'due_date' => '25/03/2026', 'document_icon' => 'Documento:',
    'amount_icon' => 'Monto:', 'date_icon' => 'Fecha:',
]);
$requestPreview = renderWhatsAppTemplate($requestTemplate, [
    'client_name' => 'Juan Perez', 'greeting' => $greeting,
    'request_title' => 'Renovacion de Registro Mercantil', 'status_text' => 'en revision final',
]);

$emailLog = $pdo->query("SELECT * FROM email_log ORDER BY created_at DESC LIMIT 10")->fetchAll();

// === Helpers para enmascarar secrets ===
function maskSecret($value, $visibleTail = 4) {
    $value = (string)$value;
    if ($value === '') return '';
    $len = strlen($value);
    if ($len <= $visibleTail) return str_repeat('•', $len);
    $head = substr($value, 0, min(7, $len - $visibleTail));
    $tail = substr($value, -$visibleTail);
    $bullets = str_repeat('•', max(4, $len - strlen($head) - $visibleTail));
    return $head . $bullets . $tail;
}
function secretIsSet($key) {
    return trim((string)getSetting($key, '')) !== '';
}

// Status por seccion (para badges en las tabs)
$emailReady = getSetting('email_enabled', '1') === '1' && secretIsSet('resend_api_key');
$aiReady = getSetting('openai_enabled', '1') === '1' && secretIsSet('openai_api_key');
$tgReady = getSetting('telegram_enabled', '0') === '1' && secretIsSet('telegram_bot_token');

$page_title = 'Configuracion';
$page_subtitle = 'Identidad, IA, mensajeria y conexiones del portal.';
$main_max = 'max-w-6xl';
include 'components/layout_start.php';
?>

<?php if ($success): ?>
<div class="mb-4 rounded-2xl bg-emerald-50 px-4 py-3 border border-emerald-100 text-sm font-medium text-emerald-800 flex items-center gap-2">
    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
    <?= htmlspecialchars($success) ?>
</div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div class="mb-4 rounded-2xl bg-red-50 px-4 py-3 border border-red-100 text-sm font-medium text-red-700 flex items-center gap-2">
    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 00-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg>
    <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<div class="settings-shell">
    <!-- Tabs sidebar -->
    <aside class="settings-tabs surface-card">
        <nav class="space-y-1 p-3">
            <button type="button" class="set-tab is-active" data-tab="identity">
                <span class="set-tab-icon"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7l9-4 9 4M5 9.5V19a2 2 0 002 2h10a2 2 0 002-2V9.5M9 22V12h6v10"/></svg></span>
                <span class="set-tab-label">Identidad</span>
            </button>
            <button type="button" class="set-tab" data-tab="contact">
                <span class="set-tab-icon"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.95.68l1.5 4.5a1 1 0 01-.5 1.21l-2.26 1.13a11.04 11.04 0 005.52 5.52l1.13-2.26a1 1 0 011.21-.5l4.5 1.5a1 1 0 01.68.95V19a2 2 0 01-2 2H5a2 2 0 01-2-2V5z"/></svg></span>
                <span class="set-tab-label">Contacto</span>
            </button>
            <button type="button" class="set-tab" data-tab="messaging">
                <span class="set-tab-icon"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg></span>
                <span class="set-tab-label">Plantillas WhatsApp</span>
            </button>
            <button type="button" class="set-tab" data-tab="email">
                <span class="set-tab-icon"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l9 6 9-6M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg></span>
                <span class="set-tab-label">Correo (Resend)</span>
                <span class="set-tab-dot <?= $emailReady ? 'set-dot-green' : 'set-dot-slate' ?>"></span>
            </button>
            <button type="button" class="set-tab" data-tab="ai">
                <span class="set-tab-icon"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg></span>
                <span class="set-tab-label">IA Fiscal</span>
                <span class="set-tab-dot <?= $aiReady ? 'set-dot-green' : 'set-dot-amber' ?>"></span>
            </button>
            <button type="button" class="set-tab" data-tab="telegram">
                <span class="set-tab-icon"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M21.5 4.5L2.5 12l5.5 2 2 6 3.5-4 6 4.5 2-16zM18 7l-9 7-2 6"/></svg></span>
                <span class="set-tab-label">Telegram Bot</span>
                <span class="set-tab-dot <?= $tgReady ? 'set-dot-green' : 'set-dot-slate' ?>"></span>
            </button>
            <button type="button" class="set-tab" data-tab="tools">
                <span class="set-tab-icon"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.7 6.3a1 1 0 000 1.4l1.6 1.6a1 1 0 001.4 0l3.77-3.77a6 6 0 01-7.94 7.94l-6.91 6.91a2.12 2.12 0 01-3-3l6.91-6.91a6 6 0 017.94-7.94L14.71 6.3z"/></svg></span>
                <span class="set-tab-label">Pruebas y logs</span>
            </button>
        </nav>
        <div class="px-4 py-3 border-t border-slate-100">
            <p class="text-[10px] uppercase tracking-wider font-bold text-slate-400 mb-2">Estado</p>
            <div class="space-y-1.5 text-xs">
                <div class="flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full <?= $emailReady ? 'bg-emerald-500' : 'bg-slate-300' ?>"></span><span class="text-slate-600">Email <?= $emailReady ? 'listo' : 'pendiente' ?></span></div>
                <div class="flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full <?= $aiReady ? 'bg-emerald-500' : 'bg-amber-500' ?>"></span><span class="text-slate-600">IA <?= $aiReady ? 'lista' : 'sin API key' ?></span></div>
                <div class="flex items-center gap-2"><span class="w-1.5 h-1.5 rounded-full <?= $tgReady ? 'bg-emerald-500' : 'bg-slate-300' ?>"></span><span class="text-slate-600">Telegram <?= $tgReady ? 'activo' : 'apagado' ?></span></div>
            </div>
        </div>
    </aside>

    <!-- Panels -->
    <form action="admin_settings.php" method="POST" class="settings-content space-y-4" id="settingsForm">
        <input type="hidden" name="action" value="save_settings">

        <!-- IDENTITY -->
        <section class="set-panel" data-panel="identity">
            <header class="set-panel-head">
                <div>
                    <h2 class="set-panel-title">Identidad de la empresa</h2>
                    <p class="set-panel-sub">Nombre, iniciales y RNC que aparecen en el sidebar, los PDFs y la PWA.</p>
                </div>
            </header>
            <div class="surface-card overflow-hidden">
                <div class="p-5 grid grid-cols-1 sm:grid-cols-6 gap-4">
                    <div class="sm:col-span-4">
                        <label class="field-label">Nombre de la empresa</label>
                        <input type="text" name="company_name" value="<?= htmlspecialchars($settings['company_name'] ?? '') ?>" class="field">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="field-label">Iniciales (max 4)</label>
                        <input type="text" name="company_initials" value="<?= htmlspecialchars($settings['company_initials'] ?? 'AF') ?>" maxlength="4" class="field">
                    </div>
                    <div class="sm:col-span-3">
                        <label class="field-label">RNC</label>
                        <input type="text" name="company_rnc" value="<?= htmlspecialchars($settings['company_rnc'] ?? '') ?>" class="field">
                    </div>
                    <div class="sm:col-span-3">
                        <label class="field-label">Eslogan</label>
                        <input type="text" name="company_slogan" value="<?= htmlspecialchars($settings['company_slogan'] ?? '') ?>" class="field">
                    </div>
                </div>
            </div>
        </section>

        <!-- CONTACT -->
        <section class="set-panel hidden" data-panel="contact">
            <header class="set-panel-head">
                <div>
                    <h2 class="set-panel-title">Datos de contacto</h2>
                    <p class="set-panel-sub">Se muestran en pie de pagina de los PDFs y en las plantillas.</p>
                </div>
            </header>
            <div class="surface-card overflow-hidden">
                <div class="p-5 grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="field-label">Telefono</label>
                        <input type="text" name="company_phone" value="<?= htmlspecialchars($settings['company_phone'] ?? '') ?>" class="field">
                    </div>
                    <div>
                        <label class="field-label">Email</label>
                        <input type="email" name="company_email" value="<?= htmlspecialchars($settings['company_email'] ?? '') ?>" class="field">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="field-label">Direccion</label>
                        <input type="text" name="company_address" value="<?= htmlspecialchars($settings['company_address'] ?? '') ?>" class="field">
                    </div>
                </div>
            </div>
        </section>

        <!-- MESSAGING -->
        <section class="set-panel hidden" data-panel="messaging">
            <header class="set-panel-head">
                <div>
                    <h2 class="set-panel-title">Documentos y WhatsApp</h2>
                    <p class="set-panel-sub">Las plantillas son editables antes de cada envio.</p>
                </div>
            </header>
            <div class="surface-card overflow-hidden">
                <div class="p-5 space-y-4">
                    <div>
                        <label class="field-label">Nota al pie del PDF</label>
                        <input type="text" name="invoice_note" value="<?= htmlspecialchars($settings['invoice_note'] ?? '') ?>" class="field">
                    </div>
                    <div>
                        <label class="field-label">Saludo base de WhatsApp</label>
                        <input type="text" id="whatsapp_greeting" name="whatsapp_greeting" value="<?= htmlspecialchars($greeting) ?>" class="field">
                        <p class="mt-1 text-xs text-slate-400">Disponible como <code class="font-mono bg-slate-100 px-1 rounded">{{greeting}}</code>.</p>
                    </div>
                    <div>
                        <label class="field-label">Plantilla para volantes</label>
                        <textarea id="whatsapp_invoice_template" name="whatsapp_invoice_template" rows="6" class="field font-mono text-xs"><?= htmlspecialchars($invoiceTemplate) ?></textarea>
                        <p class="mt-1 text-xs text-slate-400">Variables: <code class="font-mono">{{client_name}}</code> <code class="font-mono">{{greeting}}</code> <code class="font-mono">{{concept}}</code> <code class="font-mono">{{amount}}</code> <code class="font-mono">{{due_date}}</code></p>
                    </div>
                    <div>
                        <label class="field-label">Plantilla para solicitudes</label>
                        <textarea id="whatsapp_request_template" name="whatsapp_request_template" rows="4" class="field font-mono text-xs"><?= htmlspecialchars($requestTemplate) ?></textarea>
                        <p class="mt-1 text-xs text-slate-400">Variables: <code class="font-mono">{{client_name}}</code> <code class="font-mono">{{greeting}}</code> <code class="font-mono">{{request_title}}</code> <code class="font-mono">{{status_text}}</code></p>
                    </div>
                </div>
            </div>
            <!-- Previews lado a lado -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 mt-3">
                <div class="surface-card overflow-hidden">
                    <div class="px-4 py-3 border-b border-slate-100 flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                        <p class="text-xs font-bold text-slate-700">Vista previa volante</p>
                    </div>
                    <pre id="invoice_preview" class="whitespace-pre-wrap text-xs text-slate-700 leading-relaxed bg-emerald-50/40 p-4 m-3 rounded-xl border border-emerald-100"><?= htmlspecialchars($invoicePreview) ?></pre>
                </div>
                <div class="surface-card overflow-hidden">
                    <div class="px-4 py-3 border-b border-slate-100 flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                        <p class="text-xs font-bold text-slate-700">Vista previa solicitud</p>
                    </div>
                    <pre id="request_preview" class="whitespace-pre-wrap text-xs text-slate-700 leading-relaxed bg-emerald-50/40 p-4 m-3 rounded-xl border border-emerald-100"><?= htmlspecialchars($requestPreview) ?></pre>
                </div>
            </div>
        </section>

        <!-- EMAIL -->
        <section class="set-panel hidden" data-panel="email">
            <header class="set-panel-head">
                <div>
                    <h2 class="set-panel-title">Correo electronico (Resend)</h2>
                    <p class="set-panel-sub">Notificaciones automaticas al cliente y al equipo desde un dominio verificado.</p>
                </div>
                <span class="set-status <?= $emailReady ? 'set-status-on' : 'set-status-off' ?>"><?= $emailReady ? 'Activo' : 'Inactivo' ?></span>
            </header>

            <div class="surface-card overflow-hidden mb-3">
                <div class="p-5 space-y-4">
                    <?php
                    renderToggle('email_enabled', getSetting('email_enabled', '1') === '1',
                                 'Habilitar envio de correos',
                                 'Si lo desactivas, los disparadores en otros modulos no enviaran nada.');
                    ?>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="sm:col-span-2">
                            <label class="field-label">Resend API Key</label>
                            <?php renderSecretInput('resend_api_key', 'Resend API Key', 're_xxxxxxxxxx'); ?>
                            <p class="mt-1 text-[11px] text-slate-400">Solo se guarda en el servidor. Genera la key en <a href="https://resend.com/api-keys" target="_blank" class="text-blue-600 hover:underline">resend.com/api-keys</a>.</p>
                        </div>
                        <div>
                            <label class="field-label">Remitente (email)</label>
                            <input type="email" name="email_from" value="<?= htmlspecialchars(getSetting('email_from', 'no-reply@kyrosrd.com')) ?>" class="field" placeholder="no-reply@kyrosrd.com">
                            <p class="mt-1 text-[11px] text-slate-400">Debe pertenecer a un dominio verificado en Resend.</p>
                        </div>
                        <div>
                            <label class="field-label">Nombre remitente</label>
                            <input type="text" name="email_from_name" value="<?= htmlspecialchars(getSetting('email_from_name', '')) ?>" class="field" placeholder="<?= htmlspecialchars(getSetting('company_name', 'Portal Asesoria')) ?>">
                        </div>
                        <div class="sm:col-span-2">
                            <label class="field-label">Reply-To (opcional)</label>
                            <input type="email" name="email_reply_to" value="<?= htmlspecialchars(getSetting('email_reply_to', '')) ?>" class="field" placeholder="Para que las respuestas lleguen a otro correo">
                        </div>
                    </div>
                </div>
            </div>

            <div class="surface-card overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100">
                    <h3 class="text-sm font-bold text-slate-900">Disparadores automaticos</h3>
                    <p class="text-[11px] text-slate-500 mt-0.5">Eventos que envian correos cuando ocurren.</p>
                </div>
                <div class="p-5 grid grid-cols-1 sm:grid-cols-2 gap-2">
                    <?php
                    $triggers = [
                        'notify_welcome'      => ['Bienvenida al crear cliente', 'Envia credenciales y link al portal'],
                        'notify_invoice'      => ['Volante nuevo', 'Cuando se crea o se factura una iguala'],
                        'notify_invoice_paid' => ['Confirmacion de pago', 'Al marcar un volante como pagado'],
                        'notify_request'      => ['Tramite asignado', 'Cuando se asigna un nuevo servicio'],
                        'notify_status'       => ['Cambio de estado', 'En tramites (pendiente -> proceso -> revision...)'],
                        'notify_comment'      => ['Nuevo mensaje', 'Comentarios entre admin y cliente'],
                    ];
                    foreach ($triggers as $k => [$label, $desc]):
                        renderToggle($k, getSetting($k, '1') === '1', $label, $desc);
                    endforeach;
                    ?>
                </div>
            </div>
        </section>

        <!-- AI -->
        <section class="set-panel hidden" data-panel="ai">
            <header class="set-panel-head">
                <div>
                    <h2 class="set-panel-title">Inteligencia Artificial — Lectura de facturas</h2>
                    <p class="set-panel-sub">OpenAI Vision lee las fotos y arma el 606/607/IT-1 automaticamente con consenso entre dos modelos.</p>
                </div>
                <span class="set-status <?= $aiReady ? 'set-status-on' : 'set-status-off' ?>"><?= $aiReady ? 'Operativa' : 'Sin API key' ?></span>
            </header>

            <div class="surface-card overflow-hidden mb-3">
                <div class="p-5 space-y-4">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <?php renderToggle('openai_enabled', getSetting('openai_enabled', '1') === '1',
                                           'Habilitar lectura con IA',
                                           'Si lo desactivas, los clientes podran subir pero no se procesara nada.'); ?>
                        <?php renderToggle('openai_auto_process', getSetting('openai_auto_process', '1') === '1',
                                           'Procesar al subir',
                                           'Cada factura se manda a OpenAI inmediatamente.'); ?>
                    </div>

                    <div>
                        <label class="field-label">OpenAI API Key</label>
                        <?php renderSecretInput('openai_api_key', 'OpenAI API Key', 'sk-proj-...'); ?>
                        <p class="mt-1 text-[11px] text-slate-400">Solo en el servidor. Genera la key en <a href="https://platform.openai.com/api-keys" target="_blank" class="text-blue-600 hover:underline">platform.openai.com/api-keys</a>.</p>
                    </div>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="field-label">Modelo principal</label>
                            <input type="text" name="openai_model" value="<?= htmlspecialchars(getSetting('openai_model', 'gpt-4o')) ?>" class="field text-sm" placeholder="gpt-4o">
                            <p class="mt-1 text-[11px] text-slate-400">Default: <code>gpt-4o</code>.</p>
                        </div>
                        <div>
                            <label class="field-label">Tamano max. por foto (MB)</label>
                            <input type="number" min="1" max="20" name="openai_max_size_mb" value="<?= htmlspecialchars(getSetting('openai_max_size_mb', '12')) ?>" class="field text-sm">
                        </div>
                        <div>
                            <label class="field-label">Auto-aprobar si confianza ≥</label>
                            <?php $threshold = (float)getSetting('openai_auto_approve_threshold', '0'); ?>
                            <select name="openai_auto_approve_threshold" class="field text-sm">
                                <option value="0" <?= $threshold == 0 ? 'selected' : '' ?>>Nunca (revision manual)</option>
                                <option value="0.85" <?= $threshold == 0.85 ? 'selected' : '' ?>>85% - Agresivo</option>
                                <option value="0.90" <?= $threshold == 0.90 ? 'selected' : '' ?>>90% - Balanceado</option>
                                <option value="0.95" <?= $threshold == 0.95 ? 'selected' : '' ?>>95% - Recomendado</option>
                                <option value="0.98" <?= $threshold == 0.98 ? 'selected' : '' ?>>98% - Muy estricto</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="surface-card overflow-hidden mb-3">
                <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-bold text-slate-900">Validacion cruzada multi-modelo</h3>
                        <p class="text-[11px] text-slate-500 mt-0.5">Dos modelos extraen en paralelo y se compara campo por campo. Si difieren en RNC/NCF/total se marca para revision.</p>
                    </div>
                    <?php
                    // Inline toggle visual
                    $consensusOn = getSetting('openai_consensus_enabled', '1') === '1';
                    ?>
                    <label class="set-switch" title="Activar consenso">
                        <input type="checkbox" name="openai_consensus_enabled" value="1" <?= $consensusOn ? 'checked' : '' ?>>
                        <span class="set-switch-slider"></span>
                    </label>
                </div>
                <div class="p-5">
                    <label class="field-label">Modelo secundario (validador)</label>
                    <input type="text" name="openai_secondary_model" value="<?= htmlspecialchars(getSetting('openai_secondary_model', 'gpt-4o-mini')) ?>" class="field text-sm" placeholder="gpt-4o-mini">
                    <p class="mt-1 text-[11px] text-slate-400">Sugerido: <code>gpt-4o-mini</code> (rapido, economico). Si quieres maxima precision usa otro modelo con vision.</p>
                </div>
            </div>

            <div class="surface-card overflow-hidden">
                <div class="p-5">
                    <?php renderToggle('notify_invoice_approved', getSetting('notify_invoice_approved', '1') === '1',
                                        'Notificar al cliente cuando se apruebe su factura',
                                        'Manda email + push de Telegram si el cliente esta vinculado. Solo facturas subidas desde el portal.'); ?>
                </div>
            </div>
        </section>

        <!-- TELEGRAM -->
        <?php
        $tgCfg = tgConfig();
        $tgInfo = null;
        if (!empty($tgCfg['token']) && $tgCfg['enabled']) $tgInfo = tgGetWebhookInfo();
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        $webhookUrl = $proto . '://' . $host . rtrim($scriptDir, '/') . '/telegram_webhook.php';
        ?>
        <section class="set-panel hidden" data-panel="telegram">
            <header class="set-panel-head">
                <div>
                    <h2 class="set-panel-title">Bot de Telegram</h2>
                    <p class="set-panel-sub">Tus clientes envian fotos de facturas a un chat. La IA las procesa igual que en el portal.</p>
                </div>
                <span class="set-status <?= $tgReady ? 'set-status-on' : 'set-status-off' ?>"><?= $tgReady ? 'Conectado' : 'Apagado' ?></span>
            </header>

            <div class="surface-card overflow-hidden mb-3">
                <div class="p-5 space-y-4">
                    <?php renderToggle('telegram_enabled', getSetting('telegram_enabled', '0') === '1',
                                        'Habilitar bot de Telegram',
                                        'Si lo desactivas, el webhook seguira llegando pero el bot no responde.'); ?>

                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="sm:col-span-2">
                            <label class="field-label">Token del bot (BotFather)</label>
                            <?php renderSecretInput('telegram_bot_token', 'Token Telegram', '123456:ABCdefGhIJklm...'); ?>
                            <p class="mt-1 text-[11px] text-slate-400">Crea tu bot en <a href="https://t.me/BotFather" target="_blank" class="text-blue-600 hover:underline">@BotFather</a> y pegalo aqui.</p>
                        </div>
                        <div>
                            <label class="field-label">Username del bot</label>
                            <div class="flex">
                                <span class="px-3 py-2 rounded-l-xl bg-slate-100 border border-r-0 border-slate-200 text-slate-500 text-sm font-mono">@</span>
                                <input type="text" name="telegram_bot_username" value="<?= htmlspecialchars(getSetting('telegram_bot_username', '')) ?>" class="field !rounded-l-none text-sm" placeholder="micontable_bot">
                            </div>
                        </div>
                        <div>
                            <label class="field-label">Webhook secret</label>
                            <?php renderSecretInput('telegram_webhook_secret', 'Webhook secret', 'Se autogenera al conectar'); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="surface-card overflow-hidden">
                <div class="px-5 py-4 border-b border-slate-100">
                    <h3 class="text-sm font-bold text-slate-900">Estado del webhook</h3>
                </div>
                <div class="p-5 space-y-3 text-xs">
                    <div>
                        <p class="text-[10px] uppercase tracking-wider font-bold text-slate-400">URL</p>
                        <p class="font-mono text-[11px] text-slate-600 break-all mt-1"><?= htmlspecialchars($webhookUrl) ?></p>
                    </div>
                    <?php if ($tgInfo && !empty($tgInfo['ok'])):
                        $whInfo = $tgInfo['result'];
                        $connected = !empty($whInfo['url']);
                    ?>
                    <div>
                        <p class="text-[10px] uppercase tracking-wider font-bold text-slate-400">Conexion</p>
                        <p class="mt-1">
                            <?php if ($connected): ?>
                            <span class="inline-flex items-center gap-1.5 text-emerald-700 font-bold"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>Conectado</span>
                            <span class="text-slate-500 ml-2 font-mono text-[10px]"><?= htmlspecialchars($whInfo['url']) ?></span>
                            <?php else: ?>
                            <span class="inline-flex items-center gap-1.5 text-slate-500 font-bold"><span class="w-1.5 h-1.5 rounded-full bg-slate-300"></span>Sin conectar</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php if (!empty($whInfo['pending_update_count'])): ?>
                    <div class="bg-amber-50 border border-amber-100 rounded-xl p-3 text-amber-800">
                        <span class="font-bold"><?= (int)$whInfo['pending_update_count'] ?></span> updates pendientes.
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($whInfo['last_error_message'])): ?>
                    <div class="bg-red-50 border border-red-100 rounded-xl p-3 text-red-700">
                        <p class="font-bold mb-1">Ultimo error</p>
                        <p class="font-mono text-[10px]"><?= htmlspecialchars($whInfo['last_error_message']) ?></p>
                    </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- TOOLS (out of save form: just empty placeholder; real tools forms below) -->
        <section class="set-panel hidden" data-panel="tools">
            <header class="set-panel-head">
                <div>
                    <h2 class="set-panel-title">Pruebas y logs</h2>
                    <p class="set-panel-sub">Diagnostico rapido de las integraciones.</p>
                </div>
            </header>
            <p class="text-xs text-slate-500 px-2">Las acciones de prueba estan abajo (fuera del formulario principal para no guardar configuracion sin querer).</p>
        </section>

        <!-- Sticky save bar -->
        <div class="set-save-bar">
            <div class="text-xs text-slate-500">Recuerda guardar despues de editar.</div>
            <div class="flex items-center gap-2">
                <button type="button" onclick="window.location.reload()" class="btn-soft text-sm">Descartar</button>
                <button type="submit" class="btn-dark text-sm px-6">Guardar configuracion</button>
            </div>
        </div>
    </form>
</div>

<!-- Tools (fuera del form principal) -->
<div class="settings-shell mt-6" id="toolsBlock">
    <aside class="settings-tabs invisible hidden lg:block"></aside>
    <div class="settings-content space-y-3">
        <!-- Telegram webhook actions -->
        <?php $hasToken = trim(getSetting('telegram_bot_token','')) !== ''; ?>
        <div class="surface-card overflow-hidden" id="telegramToolsCard">
            <div class="px-5 py-4 border-b border-slate-100">
                <h3 class="text-sm font-bold text-slate-900">Webhook de Telegram</h3>
                <p class="text-[11px] text-slate-500 mt-0.5">Despues de guardar el token, conecta el webhook para recibir mensajes.</p>
            </div>
            <div class="p-5 flex flex-col sm:flex-row gap-2">
                <form method="POST" class="inline">
                    <input type="hidden" name="action" value="telegram_set_webhook">
                    <button type="submit" class="btn-dark text-sm" <?= $hasToken ? '' : 'disabled style="opacity:.5;cursor:not-allowed"' ?>>
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 015.656 5.656l-3 3a4 4 0 01-5.656-5.656l1.1-1.1"/><path stroke-linecap="round" stroke-linejoin="round" d="M10.172 13.828a4 4 0 01-5.656-5.656l3-3a4 4 0 015.656 5.656l-1.1 1.1"/></svg>
                        Conectar / actualizar webhook
                    </button>
                </form>
                <form method="POST" class="inline">
                    <input type="hidden" name="action" value="telegram_delete_webhook">
                    <button type="submit" class="btn-soft text-sm">Desconectar webhook</button>
                </form>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-3">
            <div class="surface-card overflow-hidden" id="emailTestCard">
                <div class="px-5 py-4 border-b border-slate-100">
                    <h3 class="text-sm font-bold text-slate-900">Prueba de envio de correo</h3>
                    <p class="text-[11px] text-slate-500 mt-0.5">Envia un correo de prueba con la configuracion actual.</p>
                </div>
                <form action="admin_settings.php" method="POST" class="p-5 flex flex-col sm:flex-row gap-2">
                    <input type="hidden" name="action" value="test_email">
                    <input type="email" name="test_to" required placeholder="correo@destino.com" class="field text-sm flex-1">
                    <button type="submit" class="btn-dark text-sm">Enviar prueba</button>
                </form>
            </div>
            <div class="surface-card overflow-hidden" id="emailLogCard">
                <div class="px-5 py-4 border-b border-slate-100">
                    <h3 class="text-sm font-bold text-slate-900">Ultimos envios</h3>
                    <p class="text-[11px] text-slate-500 mt-0.5">Historial reciente de la cola de correos.</p>
                </div>
                <?php if (empty($emailLog)): ?>
                <p class="px-5 py-8 text-center text-xs text-slate-400">Aun no se ha enviado ningun correo.</p>
                <?php else: ?>
                <ul class="divide-y divide-slate-100 max-h-72 overflow-y-auto scroll-area">
                    <?php foreach ($emailLog as $log): ?>
                    <li class="px-5 py-3 flex items-center gap-3">
                        <span class="w-2 h-2 rounded-full <?= $log['success'] ? 'bg-emerald-500' : 'bg-red-500' ?> shrink-0"></span>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs font-semibold text-slate-900 truncate"><?= htmlspecialchars($log['subject'] ?: '(sin asunto)') ?></p>
                            <p class="text-[11px] text-slate-500 truncate"><?= htmlspecialchars($log['to_email']) ?></p>
                        </div>
                        <span class="text-[10px] text-slate-400"><?= date('d/m H:i', strtotime($log['created_at'])) ?></span>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
    /* Layout */
    .settings-shell { display: grid; grid-template-columns: 240px 1fr; gap: 16px; align-items: start; }
    @media (max-width: 1023px) { .settings-shell { grid-template-columns: 1fr; } }
    .settings-tabs { position: sticky; top: 16px; align-self: start; }
    @media (max-width: 1023px) { .settings-tabs { position: static; } }
    .settings-tabs nav { display: flex; flex-direction: column; }
    @media (max-width: 1023px) {
        .settings-tabs nav { flex-direction: row; overflow-x: auto; padding: 8px; gap: 4px; }
        .settings-tabs nav::-webkit-scrollbar { display: none; }
    }
    .settings-content { min-width: 0; }

    /* Tabs */
    .set-tab {
        display: flex; align-items: center; gap: 10px;
        padding: 10px 12px; border-radius: 12px;
        background: transparent; border: 0;
        font-size: 13px; font-weight: 600; color: #475569;
        cursor: pointer; transition: all .15s ease;
        width: 100%; text-align: left;
        position: relative; white-space: nowrap;
    }
    .set-tab:hover { background: #F4F4F5; color: #0F172A; }
    .set-tab.is-active { background: #0F172A; color: #fff; }
    .set-tab.is-active .set-tab-icon { color: #fff; }
    .set-tab-icon { color: #94A3B8; display: inline-flex; align-items: center; justify-content: center; }
    .set-tab-label { flex: 1; }
    .set-tab-dot { width: 6px; height: 6px; border-radius: 999px; flex-shrink: 0; }
    .set-dot-green { background: #10B981; }
    .set-dot-amber { background: #F59E0B; }
    .set-dot-slate { background: #CBD5E1; }
    .set-tab.is-active .set-tab-dot { box-shadow: 0 0 0 2px rgba(255,255,255,.3); }

    /* Panels */
    .set-panel { animation: setFadeIn .25s ease; }
    @keyframes setFadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
    .set-panel-head {
        display: flex; align-items: flex-start; justify-content: space-between;
        gap: 12px; margin-bottom: 12px; padding: 0 4px;
    }
    .set-panel-title { font-size: 18px; font-weight: 800; color: #0F172A; letter-spacing: -0.01em; }
    .set-panel-sub { font-size: 12.5px; color: #64748B; margin-top: 2px; max-width: 640px; line-height: 1.5; }

    /* Status pill */
    .set-status {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 5px 12px; border-radius: 999px;
        font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em;
        white-space: nowrap;
    }
    .set-status::before { content: ''; width: 6px; height: 6px; border-radius: 999px; background: currentColor; }
    .set-status-on { background: #F0FDF4; color: #15803D; }
    .set-status-off { background: #F1F5F9; color: #64748B; }

    /* Switch */
    .set-switch { position: relative; display: inline-block; width: 40px; height: 22px; flex-shrink: 0; cursor: pointer; }
    .set-switch input { display: none; }
    .set-switch-slider { position: absolute; inset: 0; background: #CBD5E1; border-radius: 999px; transition: background .2s ease; }
    .set-switch-slider::before {
        content: ''; position: absolute; top: 3px; left: 3px;
        width: 16px; height: 16px; border-radius: 999px;
        background: #fff; transition: transform .2s ease, box-shadow .2s ease;
        box-shadow: 0 1px 3px rgba(15,23,42,.3);
    }
    .set-switch input:checked + .set-switch-slider { background: #0F172A; }
    .set-switch input:checked + .set-switch-slider::before { transform: translateX(18px); }
    .set-switch input:focus-visible + .set-switch-slider { box-shadow: 0 0 0 3px rgba(15,23,42,.15); }

    /* Toggle card */
    .set-toggle-card {
        display: flex; align-items: flex-start; gap: 12px;
        padding: 12px 14px;
        border-radius: 14px; border: 1px solid #EEF0F2; background: #fff;
        cursor: pointer; transition: all .15s ease;
    }
    .set-toggle-card:hover { background: #FAFAFA; border-color: #CBD5E1; }
    .set-toggle-card .set-switch { margin-top: 1px; }
    .set-toggle-main { flex: 1; min-width: 0; }
    .set-toggle-label { font-size: 13px; font-weight: 700; color: #0F172A; }
    .set-toggle-desc { font-size: 11.5px; color: #64748B; margin-top: 2px; line-height: 1.4; }

    /* Secret input */
    .set-secret { position: relative; display: flex; align-items: center; gap: 6px; }
    .set-secret input { flex: 1; padding-right: 100px; font-family: ui-monospace, 'SF Mono', Menlo, monospace; font-size: 12px; }
    .set-secret-actions { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); display: inline-flex; gap: 4px; }
    .set-secret-btn {
        background: #F4F4F5; color: #475569; border: 0;
        font-size: 11px; font-weight: 700; padding: 5px 10px; border-radius: 8px;
        cursor: pointer; transition: all .12s ease; font-family: inherit;
        display: inline-flex; align-items: center; gap: 4px;
    }
    .set-secret-btn:hover { background: #E5E7EB; color: #0F172A; }
    .set-secret-btn.is-danger { background: #FEF2F2; color: #DC2626; }
    .set-secret-btn.is-danger:hover { background: #FEE2E2; }

    /* Sticky save bar */
    .set-save-bar {
        position: sticky; bottom: 16px; z-index: 20;
        background: #fff; border: 1px solid #EEF0F2;
        border-radius: 18px;
        padding: 12px 16px;
        box-shadow: 0 12px 30px rgba(15,23,42,.08);
        display: flex; align-items: center; justify-content: space-between;
        gap: 12px;
    }
</style>

<?php
// Helper functions for rendering toggles and secret inputs
// (declared after HTML to avoid breaking the PHP flow above; they were called via include earlier but
// inline definitions also work since they're used after definition).
function renderToggle($name, $checked, $label, $desc) {
    ?>
    <label class="set-toggle-card">
        <span class="set-switch">
            <input type="checkbox" name="<?= htmlspecialchars($name) ?>" value="1" <?= $checked ? 'checked' : '' ?>>
            <span class="set-switch-slider"></span>
        </span>
        <span class="set-toggle-main">
            <span class="set-toggle-label"><?= htmlspecialchars($label) ?></span>
            <span class="set-toggle-desc"><?= htmlspecialchars($desc) ?></span>
        </span>
    </label>
    <?php
}
function renderSecretInput($name, $aria, $placeholder = '') {
    $isSet = trim((string)getSetting($name, '')) !== '';
    $maskedDisplay = $isSet ? maskSecret(getSetting($name, '')) : '';
    ?>
    <div class="set-secret" data-set-secret data-key="<?= htmlspecialchars($name) ?>" data-is-set="<?= $isSet ? '1' : '0' ?>">
        <input type="password"
               name="<?= htmlspecialchars($name) ?>"
               aria-label="<?= htmlspecialchars($aria) ?>"
               value="<?= $isSet ? '••••KEEP••••' : '' ?>"
               data-original="<?= htmlspecialchars($maskedDisplay) ?>"
               readonly
               autocomplete="off"
               class="field"
               placeholder="<?= htmlspecialchars($placeholder) ?>">
        <div class="set-secret-actions">
            <?php if ($isSet): ?>
            <button type="button" class="set-secret-btn" data-set-secret-edit>
                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                Cambiar
            </button>
            <button type="button" class="set-secret-btn is-danger" data-set-secret-clear title="Borrar valor guardado">
                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
            <?php else: ?>
            <span class="text-[10px] text-slate-400 px-2">No configurada</span>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
?>

<script>
// Tabs
(function() {
    const tabs = document.querySelectorAll('.set-tab');
    const panels = document.querySelectorAll('.set-panel');
    function activate(name) {
        tabs.forEach(t => t.classList.toggle('is-active', t.dataset.tab === name));
        panels.forEach(p => p.classList.toggle('hidden', p.dataset.panel !== name));
        // Mostrar tools card relevante en la seccion tools
        const toolsBlock = document.getElementById('toolsBlock');
        if (toolsBlock) toolsBlock.style.display = (name === 'tools' || name === 'email' || name === 'telegram') ? '' : 'none';
        try { localStorage.setItem('admin_settings_tab', name); } catch(e) {}
        // Update URL hash sin reload
        if (history.replaceState) history.replaceState(null, '', '#' + name);
    }
    tabs.forEach(t => t.addEventListener('click', () => activate(t.dataset.tab)));
    // Restaurar tab activa
    const hash = window.location.hash.replace('#', '');
    let initial = hash || (function(){ try { return localStorage.getItem('admin_settings_tab'); } catch(e) { return null; } })() || 'identity';
    if (!Array.from(tabs).some(t => t.dataset.tab === initial)) initial = 'identity';
    activate(initial);
})();

// Secret inputs: solo desbloquear al pulsar "Cambiar"
(function() {
    document.querySelectorAll('[data-set-secret]').forEach(wrap => {
        const input = wrap.querySelector('input');
        const editBtn = wrap.querySelector('[data-set-secret-edit]');
        const clearBtn = wrap.querySelector('[data-set-secret-clear]');
        const isSet = wrap.dataset.isSet === '1';

        if (editBtn) {
            editBtn.addEventListener('click', () => {
                input.removeAttribute('readonly');
                input.value = '';
                input.type = 'text';
                input.focus();
                editBtn.style.display = 'none';
                // Anadir boton "Cancelar"
                const cancelBtn = document.createElement('button');
                cancelBtn.type = 'button';
                cancelBtn.className = 'set-secret-btn';
                cancelBtn.textContent = 'Cancelar';
                cancelBtn.addEventListener('click', () => {
                    input.value = '••••KEEP••••';
                    input.setAttribute('readonly', '');
                    input.type = 'password';
                    cancelBtn.remove();
                    editBtn.style.display = '';
                });
                editBtn.parentNode.insertBefore(cancelBtn, editBtn.nextSibling);
            });
        }
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                if (!confirm('Borrar este valor del servidor? Tendras que volver a pegarlo despues.')) return;
                input.removeAttribute('readonly');
                input.value = '';
                input.type = 'text';
                input.focus();
                clearBtn.remove();
                if (editBtn) editBtn.remove();
            });
        }
    });
})();

// Previews WhatsApp (igual que antes)
(function() {
    const invoiceTemplateEl = document.getElementById('whatsapp_invoice_template');
    const requestTemplateEl = document.getElementById('whatsapp_request_template');
    const greetingEl = document.getElementById('whatsapp_greeting');
    if (!invoiceTemplateEl) return;

    function renderTemplate(template, variables) {
        return template.replace(/\{\{(\w+)\}\}/g, function(match, key) {
            return Object.prototype.hasOwnProperty.call(variables, key) ? variables[key] : match;
        });
    }
    function refresh() {
        const greeting = greetingEl.value || '<?= htmlspecialchars($defaultGreeting, ENT_QUOTES) ?>';
        document.getElementById('invoice_preview').textContent = renderTemplate(invoiceTemplateEl.value, {
            client_name: 'Juan Perez', greeting, concept: 'Iguala Mensual - Marzo 2026',
            amount: '15,000.00', due_date: '25/03/2026',
            document_icon: 'Documento:', amount_icon: 'Monto:', date_icon: 'Fecha:'
        });
        document.getElementById('request_preview').textContent = renderTemplate(requestTemplateEl.value, {
            client_name: 'Juan Perez', greeting,
            request_title: 'Renovacion de Registro Mercantil', status_text: 'en revision final'
        });
    }
    invoiceTemplateEl.addEventListener('input', refresh);
    requestTemplateEl.addEventListener('input', refresh);
    greetingEl.addEventListener('input', refresh);
})();
</script>

<?php include 'components/layout_end.php'; ?>
