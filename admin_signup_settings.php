<?php
require_once 'config.php';
requireAuth('admin');
requirePagePermission();

$success = null;
$error = null;

$catalog = signupFieldsCatalog();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $textFields = [
        'signup_title','signup_subtitle','signup_success_message','signup_terms_text',
    ];
    $boolFields = ['signup_enabled','signup_show_services'];
    $jsonFields = ['signup_visible_fields','signup_required_fields','signup_hidden_services'];

    $upsert = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    foreach ($textFields as $k) $upsert->execute([$k, trim($_POST[$k] ?? '')]);
    foreach ($boolFields as $k) $upsert->execute([$k, isset($_POST[$k]) ? '1' : '0']);

    // Visible fields: checkbox group por campo
    $visible = [];
    foreach (array_keys($catalog) as $f) {
        if (!empty($_POST['visible_' . $f])) $visible[] = $f;
    }
    // Forzar always-visible
    foreach (['name','email','password'] as $forced) {
        if (!in_array($forced, $visible, true)) $visible[] = $forced;
    }
    $upsert->execute(['signup_visible_fields', json_encode(array_values($visible))]);

    // Required fields
    $required = [];
    foreach (array_keys($catalog) as $f) {
        if (!empty($_POST['required_' . $f]) && in_array($f, $visible, true)) $required[] = $f;
    }
    foreach (['name','email','password'] as $forced) {
        if (!in_array($forced, $required, true)) $required[] = $forced;
    }
    $upsert->execute(['signup_required_fields', json_encode(array_values($required))]);

    // Servicios ocultos
    $hidden = $_POST['hidden_services'] ?? [];
    if (!is_array($hidden)) $hidden = [];
    $hidden = array_values(array_map('intval', $hidden));
    $upsert->execute(['signup_hidden_services', json_encode($hidden)]);

    $success = 'Configuracion del registro publico guardada.';
}

$cfg = [
    'enabled'         => signupIsEnabled(),
    'title'           => getSetting('signup_title', 'Crea tu cuenta'),
    'subtitle'        => getSetting('signup_subtitle', ''),
    'success_message' => getSetting('signup_success_message', ''),
    'terms_text'      => getSetting('signup_terms_text', ''),
    'show_services'   => getSetting('signup_show_services', '1') === '1',
];
$visibleFields  = signupVisibleFields();
$requiredFields = signupRequiredFields();
$hiddenServices = json_decode(getSetting('signup_hidden_services', '[]'), true) ?: [];
$hiddenServices = array_map('intval', $hiddenServices);
$services = $pdo->query("SELECT id, title, type FROM services ORDER BY type, title")->fetchAll();

$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
$publicUrl = $proto . '://' . $host . dirname($_SERVER['SCRIPT_NAME']) . '/signup.php';

$page_title = 'Registro publico de clientes';
$page_subtitle = 'Personaliza el formulario que los nuevos clientes ven antes de ser aprobados por tu equipo.';
$main_max = 'max-w-5xl';
include 'components/layout_start.php';
?>

<?php if ($success): ?>
<div class="mb-4 rounded-2xl bg-emerald-50 px-4 py-3 border border-emerald-100 text-sm font-medium text-emerald-800"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<form method="POST" class="space-y-5">
    <input type="hidden" name="action" value="save">

    <!-- Estado del registro publico -->
    <div class="surface-card overflow-hidden">
        <div class="px-6 py-5 border-b border-stone-100 flex items-center justify-between">
            <div>
                <h3 class="text-base font-bold text-slate-900">Estado</h3>
                <p class="text-xs text-slate-500 mt-0.5">Activa o desactiva el registro publico desde la pagina de login.</p>
            </div>
            <span class="badge-dot <?= $cfg['enabled'] ? 'badge-green' : 'badge-slate' ?>">
                <?= $cfg['enabled'] ? 'Activo' : 'Desactivado' ?>
            </span>
        </div>
        <div class="p-6 space-y-4">
            <label class="flex items-start gap-3 cursor-pointer rounded-2xl p-3 border border-stone-100 hover:bg-stone-50">
                <input type="checkbox" name="signup_enabled" value="1" <?= $cfg['enabled'] ? 'checked' : '' ?> class="mt-1">
                <span>
                    <span class="text-sm font-semibold text-slate-900">Permitir registro publico</span>
                    <span class="block text-[11px] text-slate-500">Los visitantes pueden crear una cuenta. Quedan en estado pending_approval hasta que las apruebes.</span>
                </span>
            </label>

            <div class="rounded-xl bg-stone-50 border border-stone-100 p-4">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">URL publica</p>
                <div class="flex items-center gap-2">
                    <code class="font-mono text-xs text-slate-700 break-all flex-1"><?= htmlspecialchars($publicUrl) ?></code>
                    <button type="button" onclick="navigator.clipboard.writeText(<?= json_encode($publicUrl) ?>); this.textContent='Copiado';" class="text-xs text-blue-600 hover:text-blue-800 font-semibold shrink-0">Copiar</button>
                </div>
                <p class="mt-2 text-[11px] text-slate-400">Comparte este link con prospectos. Tambien aparece como "Crear cuenta" en la pagina de login.</p>
            </div>
        </div>
    </div>

    <!-- Textos del form -->
    <div class="surface-card overflow-hidden">
        <div class="px-6 py-5 border-b border-stone-100">
            <h3 class="text-base font-bold text-slate-900">Textos del formulario</h3>
            <p class="text-xs text-slate-500 mt-0.5">Personaliza el titulo, subtitulo, mensaje de exito y aviso legal.</p>
        </div>
        <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
                <label class="field-label">Titulo</label>
                <input type="text" name="signup_title" value="<?= htmlspecialchars($cfg['title']) ?>" class="field text-sm">
            </div>
            <div>
                <label class="field-label">Mensaje legal (opcional)</label>
                <input type="text" name="signup_terms_text" value="<?= htmlspecialchars($cfg['terms_text']) ?>" class="field text-sm" placeholder="Acepto los terminos...">
            </div>
            <div class="sm:col-span-2">
                <label class="field-label">Subtitulo</label>
                <textarea name="signup_subtitle" rows="2" class="field text-sm"><?= htmlspecialchars($cfg['subtitle']) ?></textarea>
            </div>
            <div class="sm:col-span-2">
                <label class="field-label">Mensaje al completar</label>
                <textarea name="signup_success_message" rows="2" class="field text-sm"><?= htmlspecialchars($cfg['success_message']) ?></textarea>
            </div>
        </div>
    </div>

    <!-- Campos del form -->
    <div class="surface-card overflow-hidden">
        <div class="px-6 py-5 border-b border-stone-100">
            <h3 class="text-base font-bold text-slate-900">Campos del formulario</h3>
            <p class="text-xs text-slate-500 mt-0.5">Decide que campos mostrar y cuales hacer obligatorios. Nombre, correo y contrasena siempre estan visibles y son obligatorios.</p>
        </div>
        <div class="p-6">
            <?php
            $groupedCatalog = [];
            foreach ($catalog as $k => $meta) {
                $g = $meta['group'] ?? 'Otros';
                $groupedCatalog[$g][$k] = $meta;
            }
            foreach ($groupedCatalog as $group => $fields):
            ?>
            <div class="mb-5">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-3"><?= htmlspecialchars($group) ?></p>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    <?php foreach ($fields as $key => $meta):
                        $isForced = in_array($key, ['name','email','password'], true);
                        $isVisible = in_array($key, $visibleFields, true) || $isForced;
                        $isRequired = in_array($key, $requiredFields, true) || $isForced;
                    ?>
                    <div class="rounded-xl border border-stone-100 p-3 flex items-center gap-3 <?= $isForced ? 'bg-stone-50' : '' ?>">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-semibold text-slate-900 truncate"><?= htmlspecialchars($meta['label']) ?></p>
                            <p class="text-[10px] text-slate-400 font-mono"><?= htmlspecialchars($key) ?> <?= $isForced ? '· obligatorio del sistema' : '' ?></p>
                        </div>
                        <label class="flex items-center gap-1.5 text-[11px] cursor-pointer" title="Mostrar en el form">
                            <input type="checkbox" name="visible_<?= $key ?>" <?= $isVisible ? 'checked' : '' ?> <?= $isForced ? 'disabled checked' : '' ?>>
                            <span class="text-slate-600">Visible</span>
                        </label>
                        <label class="flex items-center gap-1.5 text-[11px] cursor-pointer" title="Hacer obligatorio">
                            <input type="checkbox" name="required_<?= $key ?>" <?= $isRequired ? 'checked' : '' ?> <?= $isForced ? 'disabled checked' : '' ?>>
                            <span class="text-slate-600">Obligatorio</span>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Servicios visibles -->
    <div class="surface-card overflow-hidden">
        <div class="px-6 py-5 border-b border-stone-100">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="text-base font-bold text-slate-900">Servicios visibles en el registro</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Marca los servicios que los nuevos clientes pueden seleccionar.</p>
                </div>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="signup_show_services" value="1" <?= $cfg['show_services'] ? 'checked' : '' ?>>
                    <span class="text-sm font-semibold text-slate-700">Mostrar seccion de servicios</span>
                </label>
            </div>
        </div>
        <div class="p-6">
            <p class="text-[11px] text-slate-500 mb-3">Desmarca los servicios que NO quieres mostrar en el form publico.</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                <?php foreach ($services as $s):
                    $isHidden = in_array((int)$s['id'], $hiddenServices, true);
                ?>
                <label class="flex items-center gap-3 p-3 rounded-xl border border-stone-100 cursor-pointer hover:bg-stone-50">
                    <input type="checkbox" name="hidden_services[]" value="<?= $s['id'] ?>" <?= $isHidden ? 'checked' : '' ?>>
                    <span class="flex-1 min-w-0">
                        <span class="text-sm font-semibold text-slate-900 truncate block"><?= htmlspecialchars($s['title']) ?></span>
                        <span class="text-[10px] text-slate-400"><?= $s['type'] === 'iguala' ? 'Iguala' : 'Puntual' ?></span>
                    </span>
                    <span class="text-[10px] text-red-500 font-bold uppercase tracking-wider">Ocultar</span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="flex justify-end">
        <button type="submit" class="btn-dark text-sm px-8">Guardar configuracion</button>
    </div>
</form>

<?php include 'components/layout_end.php'; ?>
