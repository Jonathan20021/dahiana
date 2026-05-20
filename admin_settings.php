<?php
require_once 'config.php';
requireAuth('admin');

$success = null;

$defaultGreeting = 'te escribimos de tu Asesoria Financiera';
$defaultInvoiceTemplate = "Hola {{client_name}}, {{greeting}}. Tienes un volante de cobro pendiente:\n\n{{document_icon}} *{{concept}}*\n{{amount_icon}} Monto: RD$ {{amount}}\n{{date_icon}} Fecha limite: {{due_date}}\n\nQuedamos a tu disposicion para cualquier consulta.";
$defaultRequestTemplate = "Hola {{client_name}}, {{greeting}} para recordarte que el tramite de *{{request_title}}* se encuentra actualmente *{{status_text}}*.";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    $textFields = [
        'company_name','company_rnc','company_address','company_phone','company_email',
        'company_slogan','company_initials','invoice_note','whatsapp_greeting',
        'whatsapp_invoice_template','whatsapp_request_template',
        'resend_api_key','email_from','email_from_name','email_reply_to',
        'openai_api_key','openai_model','openai_max_size_mb','openai_auto_approve_threshold',
        'openai_secondary_model',
        'telegram_bot_token','telegram_bot_username','telegram_webhook_secret',
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
        }
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        $url   = $proto . '://' . $host . rtrim($scriptDir, '/') . '/telegram_webhook.php';
        $res = tgSetWebhook($url, $secret);
        if ($res['ok']) {
            $info = tgGetMe();
            if ($info['ok'] && !empty($info['result']['username'])) {
                $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('telegram_bot_username', ?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)")->execute([$info['result']['username']]);
            }
            $success = 'Webhook conectado en ' . $url;
        } else {
            $error = 'No se pudo conectar el webhook: ' . $res['error'];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'telegram_delete_webhook') {
    $res = tgDeleteWebhook();
    $success = $res['ok'] ? 'Webhook desconectado.' : ('Error: ' . $res['error']);
    if (!$res['ok']) { $error = $success; $success = null; }
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

// Email recent log
$emailLog = $pdo->query("SELECT * FROM email_log ORDER BY created_at DESC LIMIT 10")->fetchAll();

$page_title = 'Configuracion';
$page_subtitle = 'Personaliza identidad, contacto, correos y plantillas del portal.';
$main_max = 'max-w-5xl';
include 'components/layout_start.php';
?>

<?php if ($success): ?>
<div class="mb-4 rounded-2xl bg-emerald-50 px-4 py-3 border border-emerald-100 text-sm font-medium text-emerald-800"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div class="mb-4 rounded-2xl bg-red-50 px-4 py-3 border border-red-100 text-sm font-medium text-red-700"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form action="admin_settings.php" method="POST" class="space-y-6">
    <input type="hidden" name="action" value="save_settings">

    <!-- Identidad -->
    <div class="surface-card overflow-hidden">
        <div class="px-6 py-5 border-b border-stone-100">
            <h3 class="text-base font-bold text-slate-900">Identidad de la empresa</h3>
            <p class="text-xs text-slate-500 mt-0.5">El nombre y las iniciales aparecen en el sidebar y los documentos.</p>
        </div>
        <div class="p-6 grid grid-cols-1 sm:grid-cols-6 gap-5">
            <div class="sm:col-span-4">
                <label class="field-label">Nombre de la empresa</label>
                <input type="text" name="company_name" value="<?= htmlspecialchars($settings['company_name'] ?? '') ?>" class="field">
            </div>
            <div class="sm:col-span-2">
                <label class="field-label">Iniciales</label>
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

    <!-- Contacto -->
    <div class="surface-card overflow-hidden">
        <div class="px-6 py-5 border-b border-stone-100">
            <h3 class="text-base font-bold text-slate-900">Datos de contacto</h3>
        </div>
        <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-5">
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

    <!-- WhatsApp y documentos -->
    <div class="surface-card overflow-hidden">
        <div class="px-6 py-5 border-b border-stone-100">
            <h3 class="text-base font-bold text-slate-900">Documentos y WhatsApp</h3>
            <p class="text-xs text-slate-500 mt-0.5">Las plantillas son editables antes de cada envio.</p>
        </div>
        <div class="p-6 space-y-5">
            <div>
                <label class="field-label">Nota al pie del PDF</label>
                <input type="text" name="invoice_note" value="<?= htmlspecialchars($settings['invoice_note'] ?? '') ?>" class="field">
            </div>
            <div>
                <label class="field-label">Saludo base de WhatsApp</label>
                <input type="text" id="whatsapp_greeting" name="whatsapp_greeting" value="<?= htmlspecialchars($greeting) ?>" class="field">
                <p class="mt-1 text-xs text-slate-400">Disponible en plantillas como <code class="font-mono bg-stone-100 px-1 rounded">{{greeting}}</code>.</p>
            </div>
            <div>
                <label class="field-label">Plantilla para volantes</label>
                <textarea id="whatsapp_invoice_template" name="whatsapp_invoice_template" rows="7" class="field font-mono text-xs"><?= htmlspecialchars($invoiceTemplate) ?></textarea>
                <p class="mt-1 text-xs text-slate-400">Variables: <code class="font-mono">{{client_name}}</code> <code class="font-mono">{{greeting}}</code> <code class="font-mono">{{concept}}</code> <code class="font-mono">{{amount}}</code> <code class="font-mono">{{due_date}}</code>.</p>
            </div>
            <div>
                <label class="field-label">Plantilla para solicitudes</label>
                <textarea id="whatsapp_request_template" name="whatsapp_request_template" rows="5" class="field font-mono text-xs"><?= htmlspecialchars($requestTemplate) ?></textarea>
                <p class="mt-1 text-xs text-slate-400">Variables: <code class="font-mono">{{client_name}}</code> <code class="font-mono">{{greeting}}</code> <code class="font-mono">{{request_title}}</code> <code class="font-mono">{{status_text}}</code>.</p>
            </div>
        </div>
    </div>

    <!-- Email (Resend) -->
    <div class="surface-card overflow-hidden">
        <div class="px-6 py-5 border-b border-stone-100 flex items-center justify-between">
            <div>
                <h3 class="text-base font-bold text-slate-900">Correo electronico (Resend)</h3>
                <p class="text-xs text-slate-500 mt-0.5">Envia notificaciones a tus clientes y al equipo desde un dominio verificado.</p>
            </div>
            <span class="badge-dot <?= getSetting('email_enabled', '1') === '1' ? 'badge-green' : 'badge-slate' ?>">
                <?= getSetting('email_enabled', '1') === '1' ? 'Activo' : 'Desactivado' ?>
            </span>
        </div>
        <div class="p-6 space-y-5">
            <label class="flex items-start gap-3 cursor-pointer">
                <input type="checkbox" name="email_enabled" value="1" <?= getSetting('email_enabled', '1') === '1' ? 'checked' : '' ?> class="mt-1">
                <span>
                    <span class="text-sm font-semibold text-slate-900">Habilitar envio de correos</span>
                    <span class="block text-xs text-slate-500">Si lo desactivas, los disparadores en otros modulos no enviaran nada.</span>
                </span>
            </label>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div class="sm:col-span-2">
                    <label class="field-label">Resend API Key</label>
                    <input type="text" name="resend_api_key" value="<?= htmlspecialchars(getSetting('resend_api_key', '')) ?>" class="field font-mono text-xs" placeholder="re_xxxxxxxxxx">
                    <p class="mt-1 text-[11px] text-slate-400">Necesaria para autenticar contra <code class="font-mono">api.resend.com</code>.</p>
                </div>
                <div>
                    <label class="field-label">Remitente (email)</label>
                    <input type="email" name="email_from" value="<?= htmlspecialchars(getSetting('email_from', 'no-reply@kyrosrd.com')) ?>" class="field" placeholder="no-reply@kyrosrd.com">
                    <p class="mt-1 text-[11px] text-slate-400">Debe pertenecer a un dominio verificado en Resend (ej. kyrosrd.com).</p>
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

            <!-- Notification toggles -->
            <div>
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mb-2">Disparadores automaticos</p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
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
                        $checked = getSetting($k, '1') === '1';
                    ?>
                    <label class="flex items-start gap-3 cursor-pointer rounded-2xl p-3 hover:bg-stone-50 border border-stone-100">
                        <input type="checkbox" name="<?= $k ?>" value="1" <?= $checked ? 'checked' : '' ?> class="mt-1">
                        <span>
                            <span class="text-sm font-semibold text-slate-900"><?= $label ?></span>
                            <span class="block text-[11px] text-slate-500"><?= $desc ?></span>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- OpenAI (IA fiscal) -->
    <div class="surface-card overflow-hidden">
        <div class="px-6 py-5 border-b border-stone-100 flex items-center justify-between">
            <div>
                <h3 class="text-base font-bold text-slate-900">Inteligencia Artificial - Lectura de facturas</h3>
                <p class="text-xs text-slate-500 mt-0.5">OpenAI Vision lee las fotos que suben los clientes y arma el 606/607/IT-1 automaticamente.</p>
            </div>
            <span class="badge-dot <?= getSetting('openai_enabled', '1') === '1' ? 'badge-green' : 'badge-slate' ?>">
                <?= getSetting('openai_enabled', '1') === '1' ? 'Activo' : 'Desactivado' ?>
            </span>
        </div>
        <div class="p-6 space-y-5">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <label class="flex items-start gap-3 cursor-pointer rounded-2xl p-3 border border-stone-100 hover:bg-stone-50">
                    <input type="checkbox" name="openai_enabled" value="1" <?= getSetting('openai_enabled', '1') === '1' ? 'checked' : '' ?> class="mt-1">
                    <span>
                        <span class="text-sm font-semibold text-slate-900">Habilitar lectura con IA</span>
                        <span class="block text-[11px] text-slate-500">Si lo desactivas, los clientes podran subir pero no se procesara nada.</span>
                    </span>
                </label>
                <label class="flex items-start gap-3 cursor-pointer rounded-2xl p-3 border border-stone-100 hover:bg-stone-50">
                    <input type="checkbox" name="openai_auto_process" value="1" <?= getSetting('openai_auto_process', '1') === '1' ? 'checked' : '' ?> class="mt-1">
                    <span>
                        <span class="text-sm font-semibold text-slate-900">Procesar al subir</span>
                        <span class="block text-[11px] text-slate-500">Cada factura se manda a OpenAI inmediatamente.</span>
                    </span>
                </label>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                <div class="sm:col-span-3">
                    <label class="field-label">OpenAI API Key</label>
                    <input type="text" name="openai_api_key" value="<?= htmlspecialchars(getSetting('openai_api_key', '')) ?>" class="field font-mono text-xs" placeholder="sk-proj-...">
                    <p class="mt-1 text-[11px] text-slate-400">Se usa unicamente en el servidor para llamar a <code class="font-mono">api.openai.com</code>.</p>
                </div>
                <div>
                    <label class="field-label">Modelo</label>
                    <input type="text" name="openai_model" value="<?= htmlspecialchars(getSetting('openai_model', 'gpt-4o')) ?>" class="field text-sm" placeholder="gpt-4o">
                    <p class="mt-1 text-[11px] text-slate-400">Por defecto <code>gpt-4o</code>. Puedes cambiar a <code>gpt-4.1</code> o <code>gpt-5</code> si tu cuenta lo permite.</p>
                </div>
                <div>
                    <label class="field-label">Tamano max. por foto (MB)</label>
                    <input type="number" min="1" max="20" name="openai_max_size_mb" value="<?= htmlspecialchars(getSetting('openai_max_size_mb', '12')) ?>" class="field text-sm">
                </div>
                <div class="sm:col-span-3 rounded-2xl border border-stone-100 p-3 bg-blue-50/30">
                    <div class="flex items-start gap-3 mb-2">
                        <label class="flex items-start gap-3 cursor-pointer flex-1">
                            <input type="checkbox" name="openai_consensus_enabled" value="1" <?= getSetting('openai_consensus_enabled', '1') === '1' ? 'checked' : '' ?> class="mt-1">
                            <span>
                                <span class="text-sm font-semibold text-slate-900">Validacion cruzada multi-modelo (recomendado)</span>
                                <span class="block text-[11px] text-slate-500">Dos modelos extraen en paralelo y solo se conserva el dato donde ambos coinciden. Si difieren, se mantiene el valor original y se baja la confianza.</span>
                            </span>
                        </label>
                    </div>
                    <div class="mt-2 ml-7">
                        <label class="field-label">Modelo de validacion (secundario)</label>
                        <input type="text" name="openai_secondary_model" value="<?= htmlspecialchars(getSetting('openai_secondary_model', 'gpt-4o-mini')) ?>" class="field text-sm" placeholder="gpt-4o-mini">
                        <p class="mt-1 text-[11px] text-slate-400">Sugerido: <code>gpt-4o-mini</code> (rapido, economico). Puedes usar otro modelo con vision (<code>gpt-4.1</code>, <code>gpt-5</code>) si quieres maxima precision.</p>
                    </div>
                </div>
                <div>
                    <label class="field-label">Auto-aprobar si confianza es ≥</label>
                    <?php $threshold = (float)getSetting('openai_auto_approve_threshold', '0'); ?>
                    <select name="openai_auto_approve_threshold" class="field text-sm">
                        <option value="0" <?= $threshold == 0 ? 'selected' : '' ?>>Nunca auto-aprobar (revision manual siempre)</option>
                        <option value="0.85" <?= $threshold == 0.85 ? 'selected' : '' ?>>85% - Agresivo</option>
                        <option value="0.90" <?= $threshold == 0.90 ? 'selected' : '' ?>>90% - Balanceado</option>
                        <option value="0.95" <?= $threshold == 0.95 ? 'selected' : '' ?>>95% - Conservador (recomendado)</option>
                        <option value="0.98" <?= $threshold == 0.98 ? 'selected' : '' ?>>98% - Solo muy alta confianza</option>
                    </select>
                    <p class="mt-1 text-[11px] text-slate-400">Si la IA esta segura (y tiene RNC + NCF + total), se inserta en 606/607 sin que apruebes manualmente.</p>
                </div>
                <div class="sm:col-span-3">
                    <label class="flex items-start gap-3 cursor-pointer rounded-2xl p-3 border border-stone-100 hover:bg-stone-50">
                        <input type="checkbox" name="notify_invoice_approved" value="1" <?= getSetting('notify_invoice_approved', '1') === '1' ? 'checked' : '' ?> class="mt-1">
                        <span>
                            <span class="text-sm font-semibold text-slate-900">Notificar al cliente cuando se apruebe su factura</span>
                            <span class="block text-[11px] text-slate-500">Manda un email + push de Telegram si el cliente esta vinculado. No spamea: solo facturas subidas desde el portal.</span>
                        </span>
                    </label>
                </div>
            </div>
        </div>
    </div>

    <!-- Telegram bot -->
    <?php
    $tgCfg = tgConfig();
    $tgInfo = null;
    if (!empty($tgCfg['token']) && $tgCfg['enabled']) {
        $tgInfo = tgGetWebhookInfo();
    }
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $webhookUrl = $proto . '://' . $host . rtrim($scriptDir, '/') . '/telegram_webhook.php';
    ?>
    <div class="surface-card overflow-hidden">
        <div class="px-6 py-5 border-b border-stone-100 flex items-center justify-between">
            <div>
                <h3 class="text-base font-bold text-slate-900">Bot de Telegram</h3>
                <p class="text-xs text-slate-500 mt-0.5">Tus clientes pueden enviar fotos de facturas directamente a un chat de Telegram. La IA las procesa igual que en el portal.</p>
            </div>
            <span class="badge-dot <?= getSetting('telegram_enabled', '0') === '1' ? 'badge-green' : 'badge-slate' ?>">
                <?= getSetting('telegram_enabled', '0') === '1' ? 'Activo' : 'Desactivado' ?>
            </span>
        </div>
        <div class="p-6 space-y-5">
            <label class="flex items-start gap-3 cursor-pointer rounded-2xl p-3 border border-stone-100 hover:bg-stone-50">
                <input type="checkbox" name="telegram_enabled" value="1" <?= getSetting('telegram_enabled', '0') === '1' ? 'checked' : '' ?> class="mt-1">
                <span>
                    <span class="text-sm font-semibold text-slate-900">Habilitar bot de Telegram</span>
                    <span class="block text-[11px] text-slate-500">Si lo desactivas, el webhook seguira llegando pero el bot no responde.</span>
                </span>
            </label>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div class="sm:col-span-2">
                    <label class="field-label">Token del bot (BotFather)</label>
                    <input type="text" name="telegram_bot_token" value="<?= htmlspecialchars(getSetting('telegram_bot_token', '')) ?>" class="field font-mono text-xs" placeholder="123456:ABCdefGhIJklmnOpqrsTuvwxyZ">
                    <p class="mt-1 text-[11px] text-slate-400">Crea tu bot en <a href="https://t.me/BotFather" target="_blank" class="underline">@BotFather</a>, pidele el token y pegalo aqui.</p>
                </div>
                <div>
                    <label class="field-label">Username del bot</label>
                    <div class="flex">
                        <span class="px-3 py-2 rounded-l-xl bg-stone-100 border border-r-0 border-stone-200 text-slate-500 text-sm">@</span>
                        <input type="text" name="telegram_bot_username" value="<?= htmlspecialchars(getSetting('telegram_bot_username', '')) ?>" class="field !rounded-l-none text-sm" placeholder="micontable_bot">
                    </div>
                    <p class="mt-1 text-[11px] text-slate-400">Lo usamos para el deep-link de vinculacion.</p>
                </div>
                <div>
                    <label class="field-label">Webhook secret</label>
                    <input type="text" name="telegram_webhook_secret" value="<?= htmlspecialchars(getSetting('telegram_webhook_secret', '')) ?>" class="field font-mono text-xs">
                    <p class="mt-1 text-[11px] text-slate-400">Telegram lo envia en cada request para autenticar el webhook.</p>
                </div>
            </div>

            <div class="rounded-2xl bg-stone-50 border border-stone-100 p-4 space-y-2 text-xs">
                <p class="font-semibold text-slate-700">URL del webhook</p>
                <p class="font-mono text-[11px] text-slate-500 break-all"><?= htmlspecialchars($webhookUrl) ?></p>
                <?php if ($tgInfo && !empty($tgInfo['ok'])):
                    $whInfo = $tgInfo['result'];
                    $connected = !empty($whInfo['url']);
                ?>
                <p class="text-[11px]">
                    Estado:
                    <?php if ($connected): ?>
                    <span class="font-bold text-emerald-600">Conectado</span>
                    a <code class="font-mono text-[10px]"><?= htmlspecialchars($whInfo['url']) ?></code>
                    <?php else: ?>
                    <span class="font-bold text-slate-500">Sin conectar</span>
                    <?php endif; ?>
                </p>
                <?php if (!empty($whInfo['pending_update_count'])): ?>
                <p class="text-[11px] text-amber-700">Updates pendientes: <?= (int)$whInfo['pending_update_count'] ?></p>
                <?php endif; ?>
                <?php if (!empty($whInfo['last_error_message'])): ?>
                <p class="text-[11px] text-red-600">Ultimo error: <?= htmlspecialchars($whInfo['last_error_message']) ?></p>
                <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Previews -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        <div class="surface-card overflow-hidden">
            <div class="px-6 py-4 border-b border-stone-100">
                <h3 class="text-sm font-bold text-slate-900">Vista previa volante</h3>
            </div>
            <div class="p-5">
                <pre id="invoice_preview" class="whitespace-pre-wrap text-xs text-slate-700 leading-relaxed bg-emerald-50/60 rounded-2xl p-4 border border-emerald-100"><?= htmlspecialchars($invoicePreview) ?></pre>
            </div>
        </div>
        <div class="surface-card overflow-hidden">
            <div class="px-6 py-4 border-b border-stone-100">
                <h3 class="text-sm font-bold text-slate-900">Vista previa solicitud</h3>
            </div>
            <div class="p-5">
                <pre id="request_preview" class="whitespace-pre-wrap text-xs text-slate-700 leading-relaxed bg-emerald-50/60 rounded-2xl p-4 border border-emerald-100"><?= htmlspecialchars($requestPreview) ?></pre>
            </div>
        </div>
    </div>

    <div class="flex justify-end">
        <button type="submit" class="btn-dark text-sm px-8">Guardar configuracion</button>
    </div>
</form>

<!-- Telegram webhook actions (out of main form) -->
<?php $hasToken = trim(getSetting('telegram_bot_token','')) !== ''; ?>
<div class="surface-card overflow-hidden mt-6">
    <div class="px-6 py-4 border-b border-stone-100">
        <h3 class="text-sm font-bold text-slate-900">Webhook de Telegram</h3>
        <p class="text-[11px] text-slate-500 mt-0.5">Despues de guardar el token, conecta el webhook para empezar a recibir facturas por Telegram.</p>
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

<!-- Email test + log (out of main form to avoid conflict) -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-5 mt-6">
    <div class="surface-card overflow-hidden">
        <div class="px-6 py-4 border-b border-stone-100">
            <h3 class="text-sm font-bold text-slate-900">Prueba de envio</h3>
            <p class="text-[11px] text-slate-500 mt-0.5">Envia un correo de prueba con la configuracion actual.</p>
        </div>
        <form action="admin_settings.php" method="POST" class="p-5 flex flex-col sm:flex-row gap-2">
            <input type="hidden" name="action" value="test_email">
            <input type="email" name="test_to" required placeholder="correo@destino.com" class="field text-sm flex-1">
            <button type="submit" class="btn-dark text-sm">Enviar prueba</button>
        </form>
    </div>
    <div class="surface-card overflow-hidden">
        <div class="px-6 py-4 border-b border-stone-100">
            <h3 class="text-sm font-bold text-slate-900">Ultimos envios</h3>
            <p class="text-[11px] text-slate-500 mt-0.5">Historial reciente de la cola de correos.</p>
        </div>
        <?php if (empty($emailLog)): ?>
        <p class="px-6 py-8 text-center text-xs text-slate-400">Aun no se ha enviado ningun correo.</p>
        <?php else: ?>
        <ul class="divide-y divide-stone-100 max-h-72 overflow-y-auto scroll-area">
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

<script>
const invoiceTemplateEl = document.getElementById('whatsapp_invoice_template');
const requestTemplateEl = document.getElementById('whatsapp_request_template');
const greetingEl = document.getElementById('whatsapp_greeting');

function renderTemplate(template, variables) {
    return template.replace(/\{\{(\w+)\}\}/g, function(match, key) {
        return Object.prototype.hasOwnProperty.call(variables, key) ? variables[key] : match;
    });
}

function refreshPreviews() {
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

invoiceTemplateEl.addEventListener('input', refreshPreviews);
requestTemplateEl.addEventListener('input', refreshPreviews);
greetingEl.addEventListener('input', refreshPreviews);
</script>

<?php include 'components/layout_end.php'; ?>
