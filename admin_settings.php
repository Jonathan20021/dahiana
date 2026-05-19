<?php
require_once 'config.php';
requireAuth('admin');

$success = null;

$defaultGreeting = 'te escribimos de tu Asesoria Financiera';
$defaultInvoiceTemplate = "Hola {{client_name}}, {{greeting}}. Tienes un volante de cobro pendiente:\n\n{{document_icon}} *{{concept}}*\n{{amount_icon}} Monto: RD$ {{amount}}\n{{date_icon}} Fecha limite: {{due_date}}\n\nQuedamos a tu disposicion para cualquier consulta.";
$defaultRequestTemplate = "Hola {{client_name}}, {{greeting}} para recordarte que el tramite de *{{request_title}}* se encuentra actualmente *{{status_text}}*.";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    $fields = [
        'company_name','company_rnc','company_address','company_phone','company_email',
        'company_slogan','company_initials','invoice_note','whatsapp_greeting',
        'whatsapp_invoice_template','whatsapp_request_template',
    ];

    $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach ($fields as $field) {
        $stmt->execute([$field, trim($_POST[$field] ?? '')]);
    }
    $success = "Configuracion guardada exitosamente.";
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

$page_title = 'Configuracion';
$page_subtitle = 'Personaliza identidad, contacto y plantillas del portal.';
$main_max = 'max-w-5xl';
include 'components/layout_start.php';
?>

<?php if ($success): ?>
<div class="mb-4 rounded-2xl bg-emerald-50 px-4 py-3 border border-emerald-100 text-sm font-medium text-emerald-800"><?= htmlspecialchars($success) ?></div>
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
