<?php
require_once 'config.php';

if (isset($_SESSION['user_id'])) {
    header('Location: ' . getDashboardForRole($_SESSION['role'] ?? 'client'));
    exit;
}

if (!signupIsEnabled()) {
    http_response_code(403);
    echo 'Registro publico deshabilitado. Contacta a tu asesor.';
    exit;
}

$companyName     = trim(getSetting('company_name', 'Portal Asesoria'));
$companyInitials = trim(getSetting('company_initials', 'AF')) ?: 'AF';
$companySlogan   = trim(getSetting('company_slogan', ''));
$title           = trim(getSetting('signup_title', 'Crea tu cuenta'));
$subtitle        = trim(getSetting('signup_subtitle', ''));
$successMsg      = trim(getSetting('signup_success_message', 'Recibimos tu solicitud.'));
$termsText       = trim(getSetting('signup_terms_text', ''));
$showServices    = getSetting('signup_show_services', '1') === '1';

$visibleFields  = signupVisibleFields();
$requiredFields = signupRequiredFields();
$catalog        = signupFieldsCatalog();
$availableServices = $showServices ? signupVisibleServices() : [];

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $services = $_POST['services'] ?? [];
    if (!is_array($services)) $services = [];

    // Validar required
    $missing = [];
    foreach ($requiredFields as $req) {
        if (!in_array($req, $visibleFields, true)) continue;
        if (trim((string)($_POST[$req] ?? '')) === '') {
            $missing[] = $catalog[$req]['label'] ?? $req;
        }
    }
    if (!empty($missing)) {
        $error = 'Completa los campos obligatorios: ' . implode(', ', $missing);
    } else {
        // Solo los visibles
        $payload = [];
        foreach ($visibleFields as $f) {
            $payload[$f] = $_POST[$f] ?? '';
        }
        $res = signupCreatePendingUser($payload, $services);
        if ($res['ok']) {
            $success = $successMsg;
            // Notificar admins por email (best-effort, no bloqueante)
            if (function_exists('sendAdminNewSignupEmail')) {
                @sendAdminNewSignupEmail($res['user_id']);
            }
        } else {
            $error = $res['error'];
        }
    }
}

// Helper para detectar required en el front
$isReq = function($field) use ($requiredFields) { return in_array($field, $requiredFields, true); };
$isVis = function($field) use ($visibleFields) { return in_array($field, $visibleFields, true); };

$taxRegimes      = getTaxRegimes();
$operationTypes  = getOperationTypes();
?>
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro &middot; <?= htmlspecialchars($companyName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        html, body { background: #ECECEC; }
        body { font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif; -webkit-font-smoothing: antialiased; color: #0F172A; }
        .shell { background: #fff; border-radius: 28px; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); }
        .panel-illustration {
            background:
                radial-gradient(circle at 20% 15%, rgba(96, 165, 250, 0.35), transparent 45%),
                radial-gradient(circle at 80% 90%, rgba(167, 139, 250, 0.35), transparent 45%),
                linear-gradient(135deg, #0F172A 0%, #1E293B 100%);
        }
        .field { width: 100%; border: 1px solid #E5E7EB; border-radius: 12px; padding: 11px 14px; font-size: 14px; background: #fff; color: #0F172A; transition: all .15s ease; }
        .field:focus { outline: none; border-color: #0F172A; box-shadow: 0 0 0 4px rgba(15,23,42,0.06); }
        .field-label { font-size: 11px; font-weight: 700; color: #475569; margin-bottom: 5px; display: block; letter-spacing: 0.04em; text-transform: uppercase; }
        .service-chip { cursor: pointer; transition: all .15s ease; }
        .service-chip:has(input:checked) { background: #0F172A; color: #fff; border-color: #0F172A; }
        .service-chip:has(input:checked) .service-meta { color: #94A3B8; }
        .group-header { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #475569; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid #E5E7EB; }
    </style>
</head>
<body class="min-h-full">
<div class="min-h-screen p-3 sm:p-5 lg:p-6 flex items-center justify-center">
    <div class="shell w-full max-w-[1200px] overflow-hidden grid grid-cols-1 lg:grid-cols-5 min-h-[700px]">

        <!-- Brand panel -->
        <div class="panel-illustration relative hidden lg:flex lg:col-span-2 flex-col justify-between p-10 text-white overflow-hidden">
            <div class="relative z-10">
                <div class="flex items-center gap-3">
                    <div class="w-11 h-11 rounded-2xl bg-white/15 backdrop-blur flex items-center justify-center font-extrabold tracking-tight">
                        <?= htmlspecialchars(strtoupper(substr($companyInitials, 0, 2))) ?>
                    </div>
                    <div>
                        <p class="font-bold text-[15px] leading-tight"><?= htmlspecialchars($companyName) ?></p>
                        <p class="text-xs text-blue-200/80">Portal de gestion</p>
                    </div>
                </div>
            </div>

            <div class="relative z-10 space-y-6">
                <h2 class="text-3xl font-extrabold leading-tight">
                    Tu asesoria fiscal<br>
                    <span class="text-blue-300">en piloto automatico.</span>
                </h2>
                <p class="text-sm text-slate-300 leading-relaxed max-w-sm">
                    Sube facturas, deja que la IA arme tu 606, 607 e IT-1, y comunicate con tu asesor desde un solo lugar.
                </p>
                <ul class="space-y-3 text-sm text-slate-300">
                    <li class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-emerald-400 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        IA fiscal que lee tus facturas en segundos
                    </li>
                    <li class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-emerald-400 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        Reportes DGII (606, 607, IT-1) generados solos
                    </li>
                    <li class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-emerald-400 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        Manda facturas por Telegram desde el celular
                    </li>
                    <li class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-emerald-400 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        Calendario fiscal con todos tus vencimientos
                    </li>
                </ul>
            </div>

            <div class="relative z-10 text-xs text-slate-400 flex items-center justify-between">
                <span>&copy; <?= date('Y') ?> <?= htmlspecialchars($companyName) ?></span>
                <a href="login.php" class="text-blue-300 hover:text-blue-200 font-semibold">Ya tengo cuenta &rarr;</a>
            </div>

            <div class="absolute -bottom-20 -right-20 w-80 h-80 rounded-full bg-blue-500/20 blur-3xl"></div>
            <div class="absolute -top-32 -left-20 w-80 h-80 rounded-full bg-indigo-500/20 blur-3xl"></div>
        </div>

        <!-- Form panel -->
        <div class="lg:col-span-3 p-6 sm:p-10 lg:p-12 overflow-y-auto">
            <?php if ($success): ?>
            <div class="max-w-md mx-auto text-center py-12">
                <div class="w-16 h-16 rounded-full bg-emerald-100 text-emerald-600 mx-auto mb-5 flex items-center justify-center">
                    <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                </div>
                <h2 class="text-2xl font-extrabold text-slate-900 mb-3">Solicitud enviada</h2>
                <p class="text-sm text-slate-600 leading-relaxed mb-8"><?= htmlspecialchars($success) ?></p>
                <a href="login.php" class="inline-flex items-center gap-2 rounded-2xl bg-slate-900 text-white px-6 py-3 text-sm font-bold hover:bg-slate-800 transition-all">
                    Volver al inicio
                </a>
            </div>
            <?php else: ?>
            <div class="max-w-2xl mx-auto">
                <div class="mb-6">
                    <span class="inline-flex items-center gap-2 rounded-full bg-stone-100 px-3 py-1 text-[11px] font-bold text-slate-600 uppercase tracking-wider">Registro</span>
                    <h1 class="mt-4 text-3xl font-extrabold text-slate-900 tracking-tight"><?= htmlspecialchars($title) ?></h1>
                    <?php if ($subtitle): ?>
                    <p class="mt-2 text-sm text-slate-500 leading-relaxed"><?= htmlspecialchars($subtitle) ?></p>
                    <?php endif; ?>
                </div>

                <?php if ($error): ?>
                <div class="mb-5 flex items-start gap-3 rounded-2xl bg-red-50 px-4 py-3 text-sm font-medium text-red-700 border border-red-100">
                    <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                    <?= htmlspecialchars($error) ?>
                </div>
                <?php endif; ?>

                <form method="POST" class="space-y-6" autocomplete="off">
                    <?php
                    // Renderizar campos agrupados
                    $groups = [];
                    foreach ($visibleFields as $field) {
                        $meta = $catalog[$field] ?? null;
                        if (!$meta) continue;
                        $g = $meta['group'] ?? 'Datos';
                        $groups[$g][] = $field;
                    }
                    foreach ($groups as $groupName => $fields):
                    ?>
                    <div>
                        <p class="group-header"><?= htmlspecialchars($groupName) ?></p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <?php foreach ($fields as $field):
                                $meta = $catalog[$field];
                                $required = $isReq($field);
                                $colSpan = in_array($field, ['address','notes','economic_activity'], true) ? 'sm:col-span-2' : '';
                                $opts = $meta['options'] ?? null;
                                if ($opts === 'tax_regimes') $opts = $taxRegimes;
                                if ($opts === 'operation_types') $opts = $operationTypes;
                            ?>
                            <div class="<?= $colSpan ?>">
                                <label class="field-label"><?= htmlspecialchars($meta['label']) ?> <?= $required ? '<span class="text-red-500">*</span>' : '' ?></label>
                                <?php if ($meta['type'] === 'select' && is_array($opts)): ?>
                                <select name="<?= $field ?>" class="field" <?= $required ? 'required' : '' ?>>
                                    <option value="">Seleccionar...</option>
                                    <?php foreach ($opts as $val => $label): ?>
                                    <option value="<?= htmlspecialchars($val) ?>" <?= (($_POST[$field] ?? '') === $val) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php elseif ($meta['type'] === 'textarea'): ?>
                                <textarea name="<?= $field ?>" rows="3" class="field" placeholder="<?= htmlspecialchars($meta['placeholder']) ?>" <?= $required ? 'required' : '' ?>><?= htmlspecialchars($_POST[$field] ?? '') ?></textarea>
                                <?php else: ?>
                                <input type="<?= $meta['type'] ?>" name="<?= $field ?>" value="<?= htmlspecialchars($field === 'password' ? '' : ($_POST[$field] ?? '')) ?>" class="field" placeholder="<?= htmlspecialchars($meta['placeholder']) ?>" <?= $required ? 'required' : '' ?> <?= $field === 'password' ? 'minlength="8"' : '' ?> autocomplete="off">
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <?php if ($showServices && !empty($availableServices)): ?>
                    <div>
                        <p class="group-header">Servicios que necesitas</p>
                        <p class="text-xs text-slate-500 mb-3">Marca los que aplican a tu negocio. Tu asesor confirmara y ajustara despues.</p>

                        <?php
                        $byType = ['iguala' => [], 'puntual' => []];
                        foreach ($availableServices as $s) $byType[$s['type']][] = $s;
                        ?>

                        <?php if (!empty($byType['iguala'])): ?>
                        <p class="text-[11px] font-bold text-slate-500 mb-2 mt-3">Igualas mensuales</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-4">
                            <?php foreach ($byType['iguala'] as $s):
                                $checked = is_array($_POST['services'] ?? null) && in_array((string)$s['id'], $_POST['services'], true);
                            ?>
                            <label class="service-chip flex items-center gap-3 px-3 py-2.5 rounded-xl border border-stone-200 hover:border-slate-400">
                                <input type="checkbox" name="services[]" value="<?= $s['id'] ?>" class="hidden" <?= $checked ? 'checked' : '' ?>>
                                <div class="w-4 h-4 rounded border border-stone-300 flex items-center justify-center">
                                    <svg class="w-2.5 h-2.5 opacity-0 transition-opacity" fill="currentColor" viewBox="0 0 8 8" style="opacity: 1"><path d="M2.5 4l1 1 2-2.5"/></svg>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-semibold leading-tight"><?= htmlspecialchars($s['title']) ?></p>
                                    <p class="text-[10px] service-meta text-slate-400">Mensual</p>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($byType['puntual'])): ?>
                        <p class="text-[11px] font-bold text-slate-500 mb-2 mt-3">Tramites puntuales</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                            <?php foreach ($byType['puntual'] as $s):
                                $checked = is_array($_POST['services'] ?? null) && in_array((string)$s['id'], $_POST['services'], true);
                            ?>
                            <label class="service-chip flex items-center gap-3 px-3 py-2.5 rounded-xl border border-stone-200 hover:border-slate-400">
                                <input type="checkbox" name="services[]" value="<?= $s['id'] ?>" class="hidden" <?= $checked ? 'checked' : '' ?>>
                                <div class="w-4 h-4 rounded border border-stone-300 flex items-center justify-center">
                                    <svg class="w-2.5 h-2.5" fill="currentColor" viewBox="0 0 8 8"><path d="M2.5 4l1 1 2-2.5"/></svg>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-semibold leading-tight"><?= htmlspecialchars($s['title']) ?></p>
                                    <p class="text-[10px] service-meta text-slate-400">Puntual</p>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($termsText): ?>
                    <label class="flex items-start gap-3 cursor-pointer pt-2 text-xs text-slate-600">
                        <input type="checkbox" name="terms" required class="mt-0.5">
                        <span><?= htmlspecialchars($termsText) ?></span>
                    </label>
                    <?php endif; ?>

                    <div class="flex flex-col-reverse sm:flex-row gap-3 pt-4">
                        <a href="login.php" class="text-sm text-center text-slate-500 hover:text-slate-900 self-center sm:flex-1">
                            Ya tengo cuenta &middot; <span class="font-semibold">Iniciar sesion</span>
                        </a>
                        <button type="submit" class="sm:flex-1 rounded-2xl bg-slate-900 text-white py-3.5 text-sm font-bold tracking-wide hover:bg-slate-800 transition-all hover:shadow-lg">
                            Enviar solicitud
                        </button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
</body>
</html>
