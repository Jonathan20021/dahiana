<?php
require_once 'config.php';
requireAuth('admin');

$success = null;

$defaultGreeting = 'te escribimos de tu Asesoria Financiera';
$defaultInvoiceTemplate = "Hola {{client_name}}, {{greeting}}. Tienes un volante de cobro pendiente:\n\n{{document_icon}} *{{concept}}*\n{{amount_icon}} Monto: RD$ {{amount}}\n{{date_icon}} Fecha limite: {{due_date}}\n\nQuedamos a tu disposicion para cualquier consulta.";
$defaultRequestTemplate = "Hola {{client_name}}, {{greeting}} para recordarte que el tramite de *{{request_title}}* se encuentra actualmente *{{status_text}}*.";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_settings') {
    $fields = [
        'company_name',
        'company_rnc',
        'company_address',
        'company_phone',
        'company_email',
        'company_slogan',
        'company_initials',
        'invoice_note',
        'whatsapp_greeting',
        'whatsapp_invoice_template',
        'whatsapp_request_template',
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
    'client_name' => 'Juan Perez',
    'greeting' => $greeting,
    'concept' => 'Iguala Mensual - Marzo 2026',
    'amount' => '15,000.00',
    'due_date' => '25/03/2026',
    'document_icon' => 'Documento:',
    'amount_icon' => 'Monto:',
    'date_icon' => 'Fecha:',
]);

$requestPreview = renderWhatsAppTemplate($requestTemplate, [
    'client_name' => 'Juan Perez',
    'greeting' => $greeting,
    'request_title' => 'Renovacion de Registro Mercantil',
    'status_text' => 'en revision final',
]);
?>
<!DOCTYPE html>
<html lang="es" class="h-full bg-slate-50/50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuracion - Portal Asesoria</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Outfit', sans-serif; }
    </style>
</head>
<body class="h-full">
    <?php include 'components/header.php'; ?>
    <?php include 'components/sidebar.php'; ?>

    <main class="lg:pl-72 py-8">
        <div class="px-4 sm:px-6 lg:px-8 max-w-4xl mx-auto">
            <div class="mb-8">
                <h1 class="text-3xl font-bold tracking-tight text-slate-900">Configuracion</h1>
                <p class="mt-1 text-sm text-slate-500">Personaliza la informacion de la empresa y las plantillas de WhatsApp que luego podras editar antes de enviarlas.</p>
            </div>

            <?php if ($success): ?>
            <div class="mb-6 rounded-2xl bg-green-50 p-4 border border-green-100"><p class="text-sm font-medium text-green-800"><?= htmlspecialchars($success) ?></p></div>
            <?php endif; ?>

            <form action="admin_settings.php" method="POST">
                <input type="hidden" name="action" value="save_settings">

                <div class="bg-white rounded-3xl shadow-sm border border-slate-100 overflow-hidden mb-8">
                    <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/50">
                        <h3 class="text-base font-semibold text-slate-900">Identidad de la Empresa</h3>
                    </div>
                    <div class="p-6 space-y-5">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Nombre de la Empresa</label>
                                <input type="text" name="company_name" value="<?= htmlspecialchars($settings['company_name'] ?? '') ?>" class="w-full rounded-xl border-0 py-2.5 px-3 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600 sm:text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Iniciales</label>
                                <input type="text" name="company_initials" value="<?= htmlspecialchars($settings['company_initials'] ?? 'AF') ?>" maxlength="4" class="w-full rounded-xl border-0 py-2.5 px-3 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600 sm:text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1.5">RNC</label>
                                <input type="text" name="company_rnc" value="<?= htmlspecialchars($settings['company_rnc'] ?? '') ?>" class="w-full rounded-xl border-0 py-2.5 px-3 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600 sm:text-sm">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Eslogan</label>
                                <input type="text" name="company_slogan" value="<?= htmlspecialchars($settings['company_slogan'] ?? '') ?>" class="w-full rounded-xl border-0 py-2.5 px-3 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600 sm:text-sm">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-3xl shadow-sm border border-slate-100 overflow-hidden mb-8">
                    <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/50">
                        <h3 class="text-base font-semibold text-slate-900">Datos de Contacto</h3>
                    </div>
                    <div class="p-6 space-y-5">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Telefono</label>
                                <input type="text" name="company_phone" value="<?= htmlspecialchars($settings['company_phone'] ?? '') ?>" class="w-full rounded-xl border-0 py-2.5 px-3 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600 sm:text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Email</label>
                                <input type="email" name="company_email" value="<?= htmlspecialchars($settings['company_email'] ?? '') ?>" class="w-full rounded-xl border-0 py-2.5 px-3 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600 sm:text-sm">
                            </div>
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Direccion</label>
                                <input type="text" name="company_address" value="<?= htmlspecialchars($settings['company_address'] ?? '') ?>" class="w-full rounded-xl border-0 py-2.5 px-3 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600 sm:text-sm">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-3xl shadow-sm border border-slate-100 overflow-hidden mb-8">
                    <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/50">
                        <h3 class="text-base font-semibold text-slate-900">Documentos y WhatsApp</h3>
                    </div>
                    <div class="p-6 space-y-5">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Nota al pie del PDF</label>
                            <input type="text" name="invoice_note" value="<?= htmlspecialchars($settings['invoice_note'] ?? '') ?>" class="w-full rounded-xl border-0 py-2.5 px-3 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600 sm:text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Saludo base de WhatsApp</label>
                            <input type="text" id="whatsapp_greeting" name="whatsapp_greeting" value="<?= htmlspecialchars($greeting) ?>" class="w-full rounded-xl border-0 py-2.5 px-3 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600 sm:text-sm">
                            <p class="mt-1 text-xs text-slate-400">Se usa dentro de las plantillas con la variable <code>{{greeting}}</code>.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Plantilla para volantes</label>
                            <textarea id="whatsapp_invoice_template" name="whatsapp_invoice_template" rows="7" class="w-full rounded-2xl border-0 py-3 px-4 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600 sm:text-sm"><?= htmlspecialchars($invoiceTemplate) ?></textarea>
                            <p class="mt-1 text-xs text-slate-400">Variables: {{client_name}}, {{greeting}}, {{concept}}, {{amount}}, {{due_date}}.</p>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Plantilla para solicitudes</label>
                            <textarea id="whatsapp_request_template" name="whatsapp_request_template" rows="5" class="w-full rounded-2xl border-0 py-3 px-4 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600 sm:text-sm"><?= htmlspecialchars($requestTemplate) ?></textarea>
                            <p class="mt-1 text-xs text-slate-400">Variables: {{client_name}}, {{greeting}}, {{request_title}}, {{status_text}}.</p>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
                    <div class="bg-white rounded-3xl shadow-sm border border-slate-100 overflow-hidden">
                        <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/50">
                            <h3 class="text-base font-semibold text-slate-900">Vista Previa Volante</h3>
                        </div>
                        <div class="p-6">
                            <pre id="invoice_preview" class="whitespace-pre-wrap text-sm text-slate-700 leading-relaxed bg-green-50 rounded-2xl p-5 border border-green-100"><?= htmlspecialchars($invoicePreview) ?></pre>
                        </div>
                    </div>
                    <div class="bg-white rounded-3xl shadow-sm border border-slate-100 overflow-hidden">
                        <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/50">
                            <h3 class="text-base font-semibold text-slate-900">Vista Previa Solicitud</h3>
                        </div>
                        <div class="p-6">
                            <pre id="request_preview" class="whitespace-pre-wrap text-sm text-slate-700 leading-relaxed bg-green-50 rounded-2xl p-5 border border-green-100"><?= htmlspecialchars($requestPreview) ?></pre>
                        </div>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="rounded-2xl bg-slate-900 px-8 py-3 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 transition-all hover:-translate-y-0.5">
                        Guardar Configuracion
                    </button>
                </div>
            </form>
        </div>
    </main>

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
                client_name: 'Juan Perez',
                greeting: greeting,
                concept: 'Iguala Mensual - Marzo 2026',
                amount: '15,000.00',
                due_date: '25/03/2026',
                document_icon: 'Documento:',
                amount_icon: 'Monto:',
                date_icon: 'Fecha:'
            });

            document.getElementById('request_preview').textContent = renderTemplate(requestTemplateEl.value, {
                client_name: 'Juan Perez',
                greeting: greeting,
                request_title: 'Renovacion de Registro Mercantil',
                status_text: 'en revision final'
            });
        }

        invoiceTemplateEl.addEventListener('input', refreshPreviews);
        requestTemplateEl.addEventListener('input', refreshPreviews);
        greetingEl.addEventListener('input', refreshPreviews);
    </script>
</body>
</html>
