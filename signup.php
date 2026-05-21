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
        $payload = [];
        foreach ($visibleFields as $f) $payload[$f] = $_POST[$f] ?? '';
        $res = signupCreatePendingUser($payload, $services);
        if ($res['ok']) {
            $success = $successMsg;
            if (function_exists('sendAdminNewSignupEmail')) {
                @sendAdminNewSignupEmail($res['user_id']);
            }
        } else {
            $error = $res['error'];
        }
    }
}

$isReq = function($field) use ($requiredFields) { return in_array($field, $requiredFields, true); };
$taxRegimes      = getTaxRegimes();
$operationTypes  = getOperationTypes();

// Group fields para steps
$identityFields = array_values(array_filter($visibleFields, fn($f) => ($catalog[$f]['group'] ?? '') === 'Identificacion'));
$businessFields = array_values(array_filter($visibleFields, fn($f) => ($catalog[$f]['group'] ?? '') === 'Negocio'));
$fiscalFields   = array_values(array_filter($visibleFields, fn($f) => ($catalog[$f]['group'] ?? '') === 'Fiscal'));
$extraFields    = array_values(array_filter($visibleFields, fn($f) => ($catalog[$f]['group'] ?? '') === 'Extra'));

$hasBusinessOrFiscal = !empty($businessFields) || !empty($fiscalFields) || !empty($extraFields);
$hasServicesStep = $showServices && !empty($availableServices);

// Definir steps: 1 Identidad + (opcional Negocio/Fiscal) + (opcional Servicios)
$steps = [['key' => 'identity', 'label' => 'Tu informacion', 'icon' => 'user']];
if ($hasBusinessOrFiscal) $steps[] = ['key' => 'business', 'label' => 'Tu negocio', 'icon' => 'briefcase'];
if ($hasServicesStep)     $steps[] = ['key' => 'services', 'label' => 'Servicios', 'icon' => 'check'];
$steps[] = ['key' => 'review', 'label' => 'Confirmar', 'icon' => 'send'];
$totalSteps = count($steps);

function renderField($field, $catalog, $isReq, $taxRegimes, $operationTypes) {
    $meta = $catalog[$field] ?? null;
    if (!$meta) return '';
    $required = $isReq($field);
    $opts = $meta['options'] ?? null;
    if ($opts === 'tax_regimes') $opts = $taxRegimes;
    if ($opts === 'operation_types') $opts = $operationTypes;
    $colSpan = in_array($field, ['address','notes','economic_activity'], true) ? 'sm:col-span-2' : '';
    ob_start();
    ?>
    <div class="<?= $colSpan ?>">
        <label class="su-label"><?= htmlspecialchars($meta['label']) ?> <?= $required ? '<span class="text-red-500">*</span>' : '<span class="text-slate-400 font-normal text-[10px]">(opcional)</span>' ?></label>
        <?php if ($meta['type'] === 'select' && is_array($opts)): ?>
        <select name="<?= $field ?>" class="su-field" <?= $required ? 'required' : '' ?>>
            <option value="">Seleccionar...</option>
            <?php foreach ($opts as $val => $label): ?>
            <option value="<?= htmlspecialchars($val) ?>" <?= (($_POST[$field] ?? '') === $val) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
            <?php endforeach; ?>
        </select>
        <?php elseif ($meta['type'] === 'textarea'): ?>
        <textarea name="<?= $field ?>" rows="3" class="su-field" placeholder="<?= htmlspecialchars($meta['placeholder']) ?>" <?= $required ? 'required' : '' ?>><?= htmlspecialchars($_POST[$field] ?? '') ?></textarea>
        <?php else: ?>
        <div class="relative">
            <input type="<?= $meta['type'] ?>" name="<?= $field ?>" value="<?= htmlspecialchars($field === 'password' ? '' : ($_POST[$field] ?? '')) ?>" class="su-field" placeholder="<?= htmlspecialchars($meta['placeholder']) ?>" <?= $required ? 'required' : '' ?> <?= $field === 'password' ? 'minlength="8"' : '' ?> autocomplete="<?= $field === 'password' ? 'new-password' : ($field === 'email' ? 'email' : 'off') ?>">
            <?php if ($field === 'password'): ?>
            <button type="button" data-toggle-pwd class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-700">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
}
?>
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover, maximum-scale=5">
    <meta name="theme-color" content="#0F172A">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= htmlspecialchars($companyName) ?>">
    <meta name="mobile-web-app-capable" content="yes">
    <link rel="manifest" href="manifest.php">
    <link rel="icon" type="image/svg+xml" href="pwa_icon.php?size=192">
    <link rel="apple-touch-icon" href="pwa_icon.php?size=192">
    <title>Registro &middot; <?= htmlspecialchars($companyName) ?></title>
    <script src="pwa.js" defer></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --ink: #0F172A; --muted: #475569; --line: #E5E7EB; --bg: #ECECEC; }
        html, body { background: var(--bg); }
        body { font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif; -webkit-font-smoothing: antialiased; color: var(--ink); }
        .shell { background: #fff; border-radius: 28px; box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04); }
        .panel-illustration {
            background:
                radial-gradient(circle at 20% 15%, rgba(96, 165, 250, 0.35), transparent 45%),
                radial-gradient(circle at 80% 90%, rgba(167, 139, 250, 0.35), transparent 45%),
                linear-gradient(135deg, #0F172A 0%, #1E293B 100%);
        }
        .su-field { width: 100%; border: 1.5px solid var(--line); border-radius: 12px; padding: 12px 14px; font-size: 14px; background: #fff; color: var(--ink); transition: all .18s ease; }
        .su-field:focus { outline: none; border-color: var(--ink); box-shadow: 0 0 0 4px rgba(15,23,42,0.06); }
        .su-field:invalid:not(:placeholder-shown) { border-color: #FCA5A5; }
        .su-field:valid:not(:placeholder-shown) { border-color: #86EFAC; }
        .su-label { font-size: 11px; font-weight: 700; color: var(--muted); margin-bottom: 6px; display: flex; align-items: center; gap: 4px; letter-spacing: 0.04em; text-transform: uppercase; }
        .step-dot {
            width: 36px; height: 36px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center;
            background: #F4F4F5; color: #94A3B8; font-weight: 700; font-size: 13px;
            transition: all .25s ease;
            border: 1.5px solid transparent;
        }
        .step-dot.is-active { background: var(--ink); color: #fff; }
        .step-dot.is-done   { background: #10B981; color: #fff; }
        .step-bar { flex: 1; height: 2px; background: #F4F4F5; position: relative; overflow: hidden; }
        .step-bar.is-done::after, .step-bar.is-active::after { content: ''; position: absolute; inset: 0; background: #10B981; }
        .step-bar.is-active::after { background: linear-gradient(90deg, #10B981 0%, #0F172A 100%); }
        .step-pane { display: none; animation: fadeIn .35s ease; }
        .step-pane.is-active { display: block; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
        .service-chip { transition: all .18s ease; }
        .service-chip:has(input:checked) { border-color: #0F172A; background: #0F172A; color: #fff; box-shadow: 0 4px 14px rgba(15,23,42,0.15); transform: translateY(-1px); }
        .service-chip:has(input:checked) .svc-check { background: #10B981; }
        .service-chip:has(input:checked) .svc-check svg { opacity: 1; }
        .service-chip:has(input:checked) .svc-meta { color: #94A3B8; }
        .svc-check { width: 22px; height: 22px; border-radius: 999px; background: #fff; border: 1.5px solid #CBD5E1; display: flex; align-items: center; justify-content: center; transition: all .18s ease; }
        .svc-check svg { opacity: 0; transition: opacity .18s ease; color: #fff; }
        .btn-primary { background: var(--ink); color: #fff; border-radius: 14px; padding: 13px 26px; font-weight: 700; font-size: 14px; letter-spacing: 0.01em; transition: all .15s ease; display: inline-flex; align-items: center; gap: 8px; }
        .btn-primary:hover { background: #1E293B; transform: translateY(-1px); box-shadow: 0 8px 20px rgba(15,23,42,0.2); }
        .btn-primary:disabled { opacity: .5; cursor: not-allowed; transform: none; box-shadow: none; }
        .btn-ghost { background: #F4F4F5; color: var(--muted); border-radius: 14px; padding: 13px 22px; font-weight: 600; font-size: 14px; transition: all .15s ease; display: inline-flex; align-items: center; gap: 8px; }
        .btn-ghost:hover { background: #E5E7EB; color: var(--ink); }
        .pwd-meter { height: 4px; border-radius: 999px; background: #F4F4F5; overflow: hidden; margin-top: 6px; }
        .pwd-meter-fill { height: 100%; transition: width .25s ease, background .25s ease; }
        .review-row { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border-bottom: 1px solid #F4F4F5; }
        .review-row:last-child { border-bottom: none; }
        .review-key { font-size: 11px; font-weight: 700; color: #94A3B8; text-transform: uppercase; letter-spacing: 0.06em; }
        .review-val { font-size: 14px; font-weight: 600; color: var(--ink); text-align: right; max-width: 60%; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        @media (max-width: 640px) {
            .step-bar { display: none; }
            .step-dot { width: 30px; height: 30px; font-size: 11px; }
        }
    </style>
</head>
<body class="min-h-full">
<button type="button" onclick="window.showInstallPrompt && window.showInstallPrompt()" class="pwa-install-trigger fixed top-4 right-4 z-50" title="Instalar app">
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 17V3"/><polyline points="6 11 12 17 18 11"/><line x1="3" y1="21" x2="21" y2="21"/></svg>
    <span class="hidden sm:inline">Instalar app</span>
</button>
<div class="min-h-screen p-3 sm:p-5 lg:p-6 flex items-center justify-center">
    <div class="shell w-full max-w-[1200px] overflow-hidden grid grid-cols-1 lg:grid-cols-5 min-h-[700px]">

        <!-- Brand panel -->
        <div class="panel-illustration relative hidden lg:flex lg:col-span-2 flex-col justify-between p-10 text-white overflow-hidden">
            <div class="relative z-10">
                <a href="login.php" class="inline-flex items-center gap-3 group">
                    <div class="w-11 h-11 rounded-2xl bg-white/15 backdrop-blur flex items-center justify-center font-extrabold tracking-tight group-hover:bg-white/20 transition-colors">
                        <?= htmlspecialchars(strtoupper(substr($companyInitials, 0, 2))) ?>
                    </div>
                    <div>
                        <p class="font-bold text-[15px] leading-tight"><?= htmlspecialchars($companyName) ?></p>
                        <p class="text-xs text-blue-200/80">Portal de gestion</p>
                    </div>
                </a>
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
                    <?php
                    $benefits = [
                        'IA fiscal que lee tus facturas en segundos',
                        'Reportes DGII (606, 607, IT-1) generados solos',
                        'Manda facturas por Telegram desde el celular',
                        'Calendario fiscal con todos tus vencimientos',
                    ];
                    foreach ($benefits as $b):
                    ?>
                    <li class="flex items-start gap-3">
                        <svg class="w-5 h-5 text-emerald-400 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        <?= htmlspecialchars($b) ?>
                    </li>
                    <?php endforeach; ?>
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
        <div class="lg:col-span-3 p-6 sm:p-10 lg:p-12 flex flex-col">
            <?php if ($success): ?>
            <div class="flex-1 flex items-center justify-center">
                <div class="max-w-md text-center py-12 animate-fadeIn">
                    <div class="w-20 h-20 rounded-full bg-emerald-100 text-emerald-600 mx-auto mb-6 flex items-center justify-center">
                        <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <h2 class="text-3xl font-extrabold text-slate-900 mb-3">Solicitud enviada</h2>
                    <p class="text-sm text-slate-600 leading-relaxed mb-8 max-w-sm mx-auto"><?= htmlspecialchars($success) ?></p>
                    <div class="flex flex-col sm:flex-row gap-2 justify-center">
                        <a href="login.php" class="btn-primary justify-center">Volver al inicio</a>
                    </div>
                </div>
            </div>
            <?php else: ?>

            <!-- Header -->
            <div class="mb-6">
                <span class="inline-flex items-center gap-2 rounded-full bg-blue-50 text-blue-700 px-3 py-1 text-[11px] font-bold uppercase tracking-wider">
                    <span class="w-1.5 h-1.5 rounded-full bg-blue-500 animate-pulse"></span>
                    Registro nuevo cliente
                </span>
                <h1 class="mt-3 text-3xl font-extrabold text-slate-900 tracking-tight"><?= htmlspecialchars($title) ?></h1>
                <?php if ($subtitle): ?>
                <p class="mt-1.5 text-sm text-slate-500 leading-relaxed"><?= htmlspecialchars($subtitle) ?></p>
                <?php endif; ?>
            </div>

            <!-- Stepper -->
            <div class="flex items-center gap-2 mb-8">
                <?php foreach ($steps as $idx => $step):
                    $iconSvg = match($step['icon']) {
                        'user'      => '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>',
                        'briefcase' => '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 8h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>',
                        'check'     => '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>',
                        'send'      => '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>',
                        default     => '',
                    };
                ?>
                <div class="flex flex-col items-center">
                    <div class="step-dot <?= $idx === 0 ? 'is-active' : '' ?>" data-step-dot="<?= $idx ?>">
                        <span class="step-num"><?= $iconSvg ?></span>
                    </div>
                    <span class="hidden sm:block mt-1.5 text-[10px] font-bold uppercase tracking-wider <?= $idx === 0 ? 'text-slate-900' : 'text-slate-400' ?>" data-step-label="<?= $idx ?>">
                        <?= htmlspecialchars($step['label']) ?>
                    </span>
                </div>
                <?php if ($idx < $totalSteps - 1): ?>
                <div class="step-bar mb-5" data-step-bar="<?= $idx ?>"></div>
                <?php endif; ?>
                <?php endforeach; ?>
            </div>

            <?php if ($error): ?>
            <div class="mb-5 flex items-start gap-3 rounded-2xl bg-red-50 px-4 py-3 text-sm font-medium text-red-700 border border-red-100 animate-fadeIn">
                <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <!-- Multi-step form -->
            <form method="POST" id="signupForm" class="flex-1 flex flex-col" autocomplete="off" novalidate>
                <div class="flex-1">
                    <!-- Step 1: Identity -->
                    <div class="step-pane is-active" data-pane="0">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <?php foreach ($identityFields as $f) echo renderField($f, $catalog, $isReq, $taxRegimes, $operationTypes); ?>
                        </div>
                        <div class="mt-3 hidden" id="pwdStrength">
                            <p class="text-[11px] text-slate-500 mb-1">Fuerza de tu contrasena: <span id="pwdLabel" class="font-bold">—</span></p>
                            <div class="pwd-meter"><div id="pwdFill" class="pwd-meter-fill" style="width: 0%"></div></div>
                        </div>
                    </div>

                    <?php if ($hasBusinessOrFiscal): ?>
                    <!-- Step 2: Business -->
                    <div class="step-pane" data-pane="1">
                        <?php if (!empty($businessFields)): ?>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-3">Datos del negocio</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
                            <?php foreach ($businessFields as $f) echo renderField($f, $catalog, $isReq, $taxRegimes, $operationTypes); ?>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($fiscalFields)): ?>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-3 mt-2">Perfil fiscal</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-6">
                            <?php foreach ($fiscalFields as $f) echo renderField($f, $catalog, $isReq, $taxRegimes, $operationTypes); ?>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($extraFields)): ?>
                        <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-3 mt-2">Adicional</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <?php foreach ($extraFields as $f) echo renderField($f, $catalog, $isReq, $taxRegimes, $operationTypes); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($hasServicesStep): ?>
                    <!-- Step 3: Services -->
                    <div class="step-pane" data-pane="<?= $hasBusinessOrFiscal ? 2 : 1 ?>">
                        <p class="text-sm text-slate-500 mb-4">Marca los servicios que necesitas. Tu asesor confirmara y ajustara cuando apruebe tu cuenta.</p>

                        <?php
                        $byType = ['iguala' => [], 'puntual' => []];
                        foreach ($availableServices as $s) $byType[$s['type']][] = $s;
                        ?>

                        <?php if (!empty($byType['iguala'])): ?>
                        <div class="mb-5">
                            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-2 flex items-center gap-2">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                Igualas mensuales
                            </p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                <?php foreach ($byType['iguala'] as $s):
                                    $checked = is_array($_POST['services'] ?? null) && in_array((string)$s['id'], $_POST['services'], true);
                                ?>
                                <label class="service-chip cursor-pointer flex items-center gap-3 px-4 py-3 rounded-2xl border-1.5 border-stone-200 bg-white hover:border-slate-400">
                                    <input type="checkbox" name="services[]" value="<?= $s['id'] ?>" class="hidden" <?= $checked ? 'checked' : '' ?>>
                                    <div class="svc-check">
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-bold leading-tight"><?= htmlspecialchars($s['title']) ?></p>
                                        <p class="text-[10px] svc-meta text-slate-400 mt-0.5">Recurrente cada mes</p>
                                    </div>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($byType['puntual'])): ?>
                        <div>
                            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-2 flex items-center gap-2">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                Tramites puntuales
                            </p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                <?php foreach ($byType['puntual'] as $s):
                                    $checked = is_array($_POST['services'] ?? null) && in_array((string)$s['id'], $_POST['services'], true);
                                ?>
                                <label class="service-chip cursor-pointer flex items-center gap-3 px-4 py-3 rounded-2xl border-1.5 border-stone-200 bg-white hover:border-slate-400">
                                    <input type="checkbox" name="services[]" value="<?= $s['id'] ?>" class="hidden" <?= $checked ? 'checked' : '' ?>>
                                    <div class="svc-check">
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-bold leading-tight"><?= htmlspecialchars($s['title']) ?></p>
                                        <p class="text-[10px] svc-meta text-slate-400 mt-0.5">Una sola vez</p>
                                    </div>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Review step -->
                    <div class="step-pane" data-pane="<?= $totalSteps - 1 ?>">
                        <p class="text-sm text-slate-500 mb-4">Revisa que todo este correcto antes de enviar tu solicitud.</p>
                        <div class="rounded-2xl border border-stone-200 overflow-hidden">
                            <div class="px-5 py-3 bg-stone-50 border-b border-stone-200 flex items-center justify-between">
                                <p class="text-xs font-bold uppercase tracking-wider text-slate-600">Resumen</p>
                                <button type="button" data-go-to-step="0" class="text-xs text-blue-600 hover:text-blue-800 font-semibold">Editar</button>
                            </div>
                            <div id="reviewBody"></div>
                        </div>

                        <?php if ($termsText): ?>
                        <label class="flex items-start gap-3 cursor-pointer mt-5 text-xs text-slate-600 bg-stone-50 rounded-2xl p-3">
                            <input type="checkbox" name="terms" required class="mt-0.5 w-4 h-4 accent-slate-900">
                            <span class="leading-relaxed"><?= htmlspecialchars($termsText) ?></span>
                        </label>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Navigation -->
                <div class="flex flex-col-reverse sm:flex-row gap-3 pt-8 mt-auto border-t border-stone-100 mt-6">
                    <a href="login.php" class="text-sm text-center text-slate-500 hover:text-slate-900 self-center sm:flex-1">
                        Ya tengo cuenta &middot; <span class="font-semibold">Iniciar sesion</span>
                    </a>
                    <div class="flex gap-2 sm:ml-auto">
                        <button type="button" id="prevBtn" class="btn-ghost" style="display:none">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
                            Atras
                        </button>
                        <button type="button" id="nextBtn" class="btn-primary">
                            Siguiente
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                        </button>
                        <button type="submit" id="submitBtn" class="btn-primary" style="display:none">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            Enviar solicitud
                        </button>
                    </div>
                </div>
            </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if (!$success): ?>
<script>
(function() {
    const TOTAL_STEPS = <?= $totalSteps ?>;
    const form = document.getElementById('signupForm');
    const prevBtn = document.getElementById('prevBtn');
    const nextBtn = document.getElementById('nextBtn');
    const submitBtn = document.getElementById('submitBtn');
    const panes = document.querySelectorAll('.step-pane');
    const dots = document.querySelectorAll('[data-step-dot]');
    const bars = document.querySelectorAll('[data-step-bar]');
    const labels = document.querySelectorAll('[data-step-label]');
    let current = 0;

    function showStep(idx) {
        if (idx < 0 || idx >= TOTAL_STEPS) return;
        panes.forEach((p, i) => p.classList.toggle('is-active', i === idx));
        dots.forEach((d, i) => {
            d.classList.remove('is-active', 'is-done');
            if (i < idx) d.classList.add('is-done');
            if (i === idx) d.classList.add('is-active');
        });
        bars.forEach((b, i) => {
            b.classList.remove('is-active', 'is-done');
            if (i < idx) b.classList.add('is-done');
            else if (i === idx - 1) b.classList.add('is-done');
        });
        labels.forEach((l, i) => {
            l.classList.toggle('text-slate-900', i === idx);
            l.classList.toggle('text-slate-400', i !== idx);
        });

        prevBtn.style.display = idx === 0 ? 'none' : '';
        nextBtn.style.display = idx === TOTAL_STEPS - 1 ? 'none' : '';
        submitBtn.style.display = idx === TOTAL_STEPS - 1 ? '' : 'none';

        // Render review on last step
        if (idx === TOTAL_STEPS - 1) renderReview();

        current = idx;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function validateStep(idx) {
        const pane = panes[idx];
        if (!pane) return true;
        const fields = pane.querySelectorAll('input[required], select[required], textarea[required]');
        let valid = true;
        for (const f of fields) {
            if (!f.checkValidity()) {
                f.reportValidity();
                f.focus();
                valid = false;
                break;
            }
        }
        return valid;
    }

    nextBtn.addEventListener('click', () => {
        if (!validateStep(current)) return;
        showStep(current + 1);
    });
    prevBtn.addEventListener('click', () => showStep(current - 1));

    // Click on step dots to navigate (solo si ya pasaste)
    dots.forEach((dot, i) => {
        dot.addEventListener('click', () => {
            if (i <= current || dot.classList.contains('is-done')) showStep(i);
        });
        dot.style.cursor = 'pointer';
    });

    // Go to step from review
    document.querySelectorAll('[data-go-to-step]').forEach(el => {
        el.addEventListener('click', () => showStep(parseInt(el.dataset.goToStep, 10)));
    });

    // Password toggle + strength meter
    const pwdInputs = form.querySelectorAll('input[type="password"]');
    pwdInputs.forEach(input => {
        const toggle = input.parentElement.querySelector('[data-toggle-pwd]');
        if (toggle) {
            toggle.addEventListener('click', () => {
                input.type = input.type === 'password' ? 'text' : 'password';
            });
        }
        if (input.name === 'password') {
            const meter = document.getElementById('pwdStrength');
            const fill = document.getElementById('pwdFill');
            const label = document.getElementById('pwdLabel');
            input.addEventListener('input', () => {
                const v = input.value;
                if (!v) { meter.classList.add('hidden'); return; }
                meter.classList.remove('hidden');
                let score = 0;
                if (v.length >= 8) score++;
                if (/[A-Z]/.test(v)) score++;
                if (/[0-9]/.test(v)) score++;
                if (/[^a-zA-Z0-9]/.test(v)) score++;
                if (v.length >= 12) score++;
                const pct = Math.min(100, score * 20);
                fill.style.width = pct + '%';
                if (score <= 1) { fill.style.background = '#ef4444'; label.textContent = 'Debil'; label.style.color = '#ef4444'; }
                else if (score <= 3) { fill.style.background = '#f59e0b'; label.textContent = 'Aceptable'; label.style.color = '#f59e0b'; }
                else { fill.style.background = '#10b981'; label.textContent = 'Fuerte'; label.style.color = '#10b981'; }
            });
        }
    });

    // Review renderer
    const fieldLabels = <?= json_encode(array_map(fn($k) => $catalog[$k]['label'] ?? $k, array_combine($visibleFields, $visibleFields))) ?>;
    function renderReview() {
        const body = document.getElementById('reviewBody');
        if (!body) return;
        const data = new FormData(form);
        let html = '';
        for (const [key, label] of Object.entries(fieldLabels)) {
            let val = data.get(key);
            if (key === 'password') val = val ? '••••••••' : '';
            if (!val) continue;
            html += `<div class="review-row">
                <span class="review-key">${label}</span>
                <span class="review-val">${escapeHtml(val)}</span>
            </div>`;
        }
        const svcs = data.getAll('services[]') || [];
        if (svcs.length) {
            html += `<div class="review-row">
                <span class="review-key">Servicios elegidos</span>
                <span class="review-val">${svcs.length} servicio(s)</span>
            </div>`;
        }
        body.innerHTML = html || '<div class="px-5 py-6 text-center text-sm text-slate-400">No has llenado nada todavia.</div>';
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    showStep(0);

    // Animate fade-in
    const style = document.createElement('style');
    style.textContent = '@keyframes fadeIn { from { opacity:0; transform:translateY(8px); } to { opacity:1; transform:translateY(0); } } .animate-fadeIn { animation: fadeIn .4s ease; }';
    document.head.appendChild(style);
})();
</script>
<?php endif; ?>
</body>
</html>
