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
    ];
    $boolFields = [
        'email_enabled','notify_welcome','notify_invoice','notify_invoice_paid',
        'notify_request','notify_status','notify_comment',
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
