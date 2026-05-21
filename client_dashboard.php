<?php
require_once 'config.php';
requireAuth('client');

$client_id = (int)$_SESSION['user_id'];
$client = $pdo->prepare("SELECT id, name, business_name, rnc, tax_regime, business_type, employee_count, fiscal_year_close, operation_type, telegram_link_code FROM users WHERE id = ?");
$client->execute([$client_id]);
$me = $client->fetch() ?: [];
if (empty($me)) {
    session_unset();
    header('Location: login.php');
    exit;
}

// Ensure Telegram link code exists
$linkCode = $me['telegram_link_code'] ?? '';
if (!$linkCode) {
    $linkCode = tgEnsureLinkCode($client_id);
}

$period = date('Y-m');
$months = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
$periodLabel = $months[(int)substr($period, 5, 2) - 1] . ' ' . substr($period, 0, 4);

// Requests (igualas + puntuales)
$stmt = $pdo->prepare("
    SELECT r.*, s.title, s.type, s.delivery_days, s.delivery_label
    FROM requests r
    JOIN services s ON r.service_id = s.id
    WHERE r.client_id = ?
    ORDER BY r.created_at DESC
");
$stmt->execute([$client_id]);
$requests = $stmt->fetchAll();
$igualas   = array_values(array_filter($requests, fn($r) => $r['type'] === 'iguala'));
$puntuales = array_values(array_filter($requests, fn($r) => $r['type'] === 'puntual'));

// Pending invoices
$invStmt = $pdo->prepare("SELECT * FROM invoices WHERE client_id = ? AND status = 'pendiente' ORDER BY due_date ASC LIMIT 5");
$invStmt->execute([$client_id]);
$pendingInvoices = $invStmt->fetchAll();
$totalPending = 0;
foreach ($pendingInvoices as $i) $totalPending += (float)$i['amount'];

// === AI invoice metrics for the current period ===
$aiStats = $pdo->prepare("
    SELECT
      COUNT(*) AS total_uploads,
      SUM(CASE WHEN u.status='approved'  THEN 1 ELSE 0 END) AS approved,
      SUM(CASE WHEN u.status='extracted' THEN 1 ELSE 0 END) AS pending_review,
      SUM(CASE WHEN u.status='error'     THEN 1 ELSE 0 END) AS errors,
      COALESCE(SUM(CASE WHEN e.doc_type='compra' THEN e.itbis ELSE 0 END), 0) AS itbis_compras,
      COALESCE(SUM(CASE WHEN e.doc_type='venta'  THEN e.itbis ELSE 0 END), 0) AS itbis_ventas,
      COALESCE(SUM(CASE WHEN e.doc_type='compra' THEN e.total ELSE 0 END), 0) AS total_compras,
      COALESCE(SUM(CASE WHEN e.doc_type='venta'  THEN e.total ELSE 0 END), 0) AS total_ventas
    FROM invoice_uploads u
    LEFT JOIN invoice_extractions e ON e.upload_id = u.id
    WHERE u.client_id = ? AND (e.period = ? OR (e.period IS NULL AND DATE_FORMAT(u.created_at,'%Y-%m') = ?))
");
$aiStats->execute([$client_id, $period, $period]);
$ai = $aiStats->fetch() ?: ['total_uploads'=>0,'approved'=>0,'pending_review'=>0,'errors'=>0,'itbis_compras'=>0,'itbis_ventas'=>0,'total_compras'=>0,'total_ventas'=>0];
$it1Balance = (float)$ai['itbis_ventas'] - (float)$ai['itbis_compras'];

// Recent uploads (5)
$recentInv = $pdo->prepare("
    SELECT u.id, u.original_name, u.filename, u.mime_type, u.status, u.created_at,
           e.doc_type, e.counterparty_name, e.total, e.itbis, e.confidence, e.ncf
    FROM invoice_uploads u
    LEFT JOIN invoice_extractions e ON e.upload_id = u.id
    WHERE u.client_id = ?
    ORDER BY u.created_at DESC
    LIMIT 6
");
$recentInv->execute([$client_id]);
$recentInvoices = $recentInv->fetchAll();

// Solo leer obligaciones (la creacion la maneja la consultora, NUNCA auto-regenerar desde vistas del cliente)
$oblStmt = $pdo->prepare("
    SELECT * FROM tax_obligations
    WHERE client_id = ? AND status IN ('pendiente','vencido') AND dismissed_at IS NULL
    ORDER BY due_date ASC LIMIT 6
");
$oblStmt->execute([$client_id]);
$obligations = $oblStmt->fetchAll();

// Filings status this month
$myFilings = $pdo->prepare("SELECT filing_type, status, total_amount, total_itbis, total_records FROM tax_filings WHERE client_id=? AND period=?");
$myFilings->execute([$client_id, $period]);
$filingsMap = [];
foreach ($myFilings as $f) $filingsMap[$f['filing_type']] = $f;

// Personal progress (existing)
$clientStatusCounts = ['pendiente' => 0, 'en_proceso' => 0, 'en_revision' => 0, 'finalizado' => 0];
foreach ($requests as $r) {
    if (in_array($r['status'], ['completado', 'presentado'])) $clientStatusCounts['finalizado']++;
    else $clientStatusCounts[$r['status']] = ($clientStatusCounts[$r['status']] ?? 0) + 1;
}
$chartClientData = array_values($clientStatusCounts);
$totalActive = count($requests) - $clientStatusCounts['finalizado'];

function clientGetProgressPercentage($status) {
    return match($status) {
        'pendiente' => 25, 'en_proceso' => 50,
        'en_revision' => 75, 'completado', 'presentado' => 100,
        default => 0
    };
}
function clientIsStepActive($currentStatus, $stepStatus) {
    $levels = ['pendiente' => 1, 'en_proceso' => 2, 'en_revision' => 3, 'completado' => 4, 'presentado' => 4];
    return ($levels[$currentStatus] ?? 0) >= ($levels[$stepStatus] ?? 0);
}

// Telegram bot info
$botUsername = trim(getSetting('telegram_bot_username', ''));
$tgEnabled   = getSetting('telegram_enabled', '0') === '1' && trim(getSetting('telegram_bot_token','')) !== '';
$deepLink    = $botUsername ? ("https://t.me/{$botUsername}?start=" . urlencode($linkCode)) : '';

$firstName = explode(' ', $_SESSION['name'])[0];
$page_title = 'Hola, ' . $firstName;
$page_subtitle = 'Tu espacio para mandar facturas, ver tu estado fiscal y dar seguimiento a tus tramites.';
$main_max = 'max-w-6xl';
include 'components/layout_start.php';
?>

<!-- Hero: subir facturas con IA -->
<div data-tour="upload-hero" class="surface-card overflow-hidden mb-5 bg-gradient-to-br from-slate-900 to-slate-800 text-white border-0">
    <div class="px-6 py-6 lg:px-8 lg:py-7 grid grid-cols-1 lg:grid-cols-5 gap-5 items-center">
        <div class="lg:col-span-3">
            <span class="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-[11px] font-bold uppercase tracking-wider text-blue-200">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
                IA fiscal activa
            </span>
            <h2 class="text-2xl lg:text-3xl font-extrabold mt-3 leading-tight">Sube tus facturas y nosotros armamos el 606, 607 e IT-1.</h2>
            <p class="mt-2 text-sm text-slate-300 leading-relaxed">
                Toma una foto a tu factura desde el celular. La IA lee RNC, NCF, ITBIS y categoria, y tu asesor solo valida.
            </p>
            <div class="mt-4 flex flex-wrap gap-2">
                <a href="client_uploads.php" class="inline-flex items-center gap-2 rounded-2xl bg-white text-slate-900 px-5 py-2.5 text-sm font-bold hover:bg-blue-50 transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                    Subir factura ahora
                </a>
                <?php if ($tgEnabled && $deepLink): ?>
                <a href="<?= htmlspecialchars($deepLink) ?>" target="_blank" class="inline-flex items-center gap-2 rounded-2xl bg-white/10 text-white px-5 py-2.5 text-sm font-bold hover:bg-white/20 transition-colors">
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M9.78 18.65l.28-4.23 7.68-6.92c.34-.31-.07-.46-.52-.19L7.74 13.3 3.64 12c-.88-.25-.89-.86.2-1.3l15.97-6.16c.73-.33 1.43.18 1.15 1.3l-2.72 12.81c-.19.91-.74 1.13-1.5.71L12.6 16.3l-1.99 1.93c-.23.23-.42.42-.83.42z"/></svg>
                    Subir desde Telegram
                </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="lg:col-span-2 grid grid-cols-2 gap-3">
            <div class="rounded-2xl bg-white/10 p-4">
                <p class="text-[10px] uppercase tracking-wider text-slate-300 font-bold"><?= htmlspecialchars($periodLabel) ?></p>
                <p class="mt-1 text-2xl font-extrabold"><?= (int)$ai['total_uploads'] ?></p>
                <p class="text-[11px] text-slate-300">Facturas subidas</p>
            </div>
            <div class="rounded-2xl bg-white/10 p-4">
                <p class="text-[10px] uppercase tracking-wider text-amber-200 font-bold">Por validar</p>
                <p class="mt-1 text-2xl font-extrabold text-amber-200"><?= (int)$ai['pending_review'] ?></p>
                <p class="text-[11px] text-slate-300">Espera al asesor</p>
            </div>
            <div class="rounded-2xl bg-white/10 p-4">
                <p class="text-[10px] uppercase tracking-wider text-emerald-200 font-bold">Aprobadas</p>
                <p class="mt-1 text-2xl font-extrabold text-emerald-200"><?= (int)$ai['approved'] ?></p>
                <p class="text-[11px] text-slate-300">En 606 / 607</p>
            </div>
            <div class="rounded-2xl <?= $it1Balance > 0 ? 'bg-red-500/20' : 'bg-emerald-500/20' ?> p-4">
                <p class="text-[10px] uppercase tracking-wider text-slate-200 font-bold">IT-1 estimado</p>
                <p class="mt-1 text-2xl font-extrabold"><?= $it1Balance > 0 ? '-' : '+' ?>RD$ <?= number_format(abs($it1Balance), 0) ?></p>
                <p class="text-[11px] text-slate-300"><?= $it1Balance > 0 ? 'A pagar a DGII' : 'Saldo a favor' ?></p>
            </div>
        </div>
    </div>
</div>

<!-- Formularios fiscales del mes -->
<div class="grid grid-cols-1 lg:grid-cols-4 gap-4 mb-5">
    <?php
    $forms = [
        '606' => ['title' => '606 Compras',    'color' => 'blue',    'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z"/>'],
        '607' => ['title' => '607 Ventas',     'color' => 'emerald', 'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"/>'],
        'IT-1'=> ['title' => 'IT-1 ITBIS',     'color' => 'indigo',  'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>'],
        '608' => ['title' => '608 NCF anul.',  'color' => 'slate',   'icon' => '<path stroke-linecap="round" stroke-linejoin="round" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>'],
    ];
    foreach ($forms as $code => $f):
        $hasFiling = isset($filingsMap[$code]);
        $sent = $hasFiling && $filingsMap[$code]['status'] === 'enviado';
        $colorClasses = match($f['color']) {
            'blue'    => ['bg' => 'bg-blue-50',    'tx' => 'text-blue-700'],
            'emerald' => ['bg' => 'bg-emerald-50', 'tx' => 'text-emerald-700'],
            'indigo'  => ['bg' => 'bg-indigo-50',  'tx' => 'text-indigo-700'],
            'slate'   => ['bg' => 'bg-stone-50',   'tx' => 'text-slate-700'],
        };
    ?>
    <div class="stat-card p-4">
        <div class="flex items-center justify-between mb-2">
            <div class="w-9 h-9 rounded-xl <?= $colorClasses['bg'] ?> <?= $colorClasses['tx'] ?> flex items-center justify-center">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><?= $f['icon'] ?></svg>
            </div>
            <?php if ($sent): ?>
            <span class="badge-dot badge-green !text-[10px]">Enviado</span>
            <?php elseif ($hasFiling): ?>
            <span class="badge-dot badge-amber !text-[10px]">Borrador</span>
            <?php else: ?>
            <span class="badge-dot badge-slate !text-[10px]">Sin datos</span>
            <?php endif; ?>
        </div>
        <p class="text-xs text-slate-500"><?= htmlspecialchars($f['title']) ?></p>
        <p class="mt-1 text-lg font-extrabold text-slate-900">
            <?php if ($code === 'IT-1' && $hasFiling): ?>
                RD$ <?= number_format(abs((float)$filingsMap['IT-1']['total_itbis'] - (float)$filingsMap['IT-1']['total_amount']), 0) ?>
            <?php elseif ($hasFiling): ?>
                <?= (int)$filingsMap[$code]['total_records'] ?> <span class="text-xs text-slate-400 font-medium">lineas</span>
            <?php else: ?>
                —
            <?php endif; ?>
        </p>
    </div>
    <?php endforeach; ?>
</div>

<!-- Telegram link + agenda fiscal + recent invoices -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-5 mb-5">
    <!-- Telegram link card -->
    <div data-tour="telegram-card" class="surface-card p-5">
        <div class="flex items-start gap-3">
            <div class="w-10 h-10 rounded-2xl bg-sky-100 text-sky-600 flex items-center justify-center shrink-0">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M9.78 18.65l.28-4.23 7.68-6.92c.34-.31-.07-.46-.52-.19L7.74 13.3 3.64 12c-.88-.25-.89-.86.2-1.3l15.97-6.16c.73-.33 1.43.18 1.15 1.3l-2.72 12.81c-.19.91-.74 1.13-1.5.71L12.6 16.3l-1.99 1.93c-.23.23-.42.42-.83.42z"/></svg>
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-bold text-slate-900">Manda facturas por Telegram</p>
                <?php if (!$tgEnabled): ?>
                <p class="text-[11px] text-slate-500 leading-snug mt-1">El equipo aun no ha activado el bot. Mientras tanto, usa el portal.</p>
                <?php else: ?>
                <p class="text-[11px] text-slate-500 leading-snug mt-1">
                    Conecta una vez y luego solo envias fotos al chat. La IA se encarga del resto.
                </p>
                <div class="mt-3 space-y-2">
                    <div class="rounded-xl bg-stone-50 border border-stone-200 px-3 py-2 flex items-center gap-2">
                        <p class="text-[10px] text-slate-500 uppercase tracking-wider font-bold">Tu codigo</p>
                        <span id="tgCode" class="font-mono text-sm font-bold text-slate-900 select-all ml-auto"><?= htmlspecialchars($linkCode) ?></span>
                        <button type="button" onclick="copyTgCode()" class="text-xs text-blue-600 hover:text-blue-800 font-semibold">Copiar</button>
                    </div>
                    <?php if ($deepLink): ?>
                    <a href="<?= htmlspecialchars($deepLink) ?>" target="_blank" class="btn-dark text-xs w-full justify-center">
                        Abrir bot y vincular automaticamente
                    </a>
                    <p class="text-[11px] text-slate-400 leading-snug">O entra a <code>@<?= htmlspecialchars($botUsername) ?></code> y escribe <code>/vincular <?= htmlspecialchars($linkCode) ?></code></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Agenda fiscal -->
    <div data-tour="agenda" class="surface-card p-5">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-bold text-slate-900">Proximos vencimientos DGII</h3>
            <span class="badge-dot badge-slate !text-[10px]"><?= count($obligations) ?></span>
        </div>
        <?php if (empty($obligations)): ?>
        <p class="text-xs text-slate-400 py-6 text-center">Estas al dia.</p>
        <?php else: ?>
        <ul class="space-y-2">
            <?php foreach ($obligations as $o):
                $days = (int)((strtotime($o['due_date']) - strtotime(date('Y-m-d'))) / 86400);
                $urgentClass = $days < 0 ? 'text-red-600' : ($days <= 5 ? 'text-amber-600' : 'text-slate-500');
            ?>
            <li class="flex items-center gap-3 rounded-xl bg-stone-50 p-3">
                <div class="w-9 h-9 rounded-lg bg-white border border-stone-200 flex items-center justify-center text-slate-600 shrink-0">
                    <span class="text-[10px] font-extrabold"><?= htmlspecialchars($o['obligation_type']) ?></span>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-xs font-semibold text-slate-900 truncate"><?= htmlspecialchars(getObligationLabel($o['obligation_type'])) ?></p>
                    <p class="text-[10px] text-slate-500">Periodo <?= htmlspecialchars($o['period']) ?></p>
                </div>
                <div class="text-right">
                    <p class="text-xs font-bold text-slate-900"><?= date('d/m', strtotime($o['due_date'])) ?></p>
                    <p class="text-[10px] <?= $urgentClass ?> font-semibold">
                        <?= $days < 0 ? abs($days) . ' d vencido' : ($days === 0 ? 'Hoy' : 'en ' . $days . ' d') ?>
                    </p>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>

    <!-- Recent invoices -->
    <div class="surface-card p-5">
        <div class="flex items-center justify-between mb-3">
            <h3 class="text-sm font-bold text-slate-900">Facturas recientes</h3>
            <a href="client_uploads.php" class="text-xs font-semibold text-blue-600 hover:text-blue-800">Ver todas</a>
        </div>
        <?php if (empty($recentInvoices)): ?>
        <p class="text-xs text-slate-400 py-6 text-center">Aun no has subido facturas. <a href="client_uploads.php" class="text-blue-600 font-semibold">Subir la primera</a>.</p>
        <?php else: ?>
        <ul class="space-y-2">
            <?php foreach ($recentInvoices as $r):
                $thumbHref = 'uploads/invoices/' . htmlspecialchars($r['filename']);
                $statusBadge = match($r['status']) {
                    'uploaded'   => '<span class="badge-dot badge-slate !text-[10px]">En cola</span>',
                    'processing' => '<span class="badge-dot badge-blue !text-[10px]">Procesando</span>',
                    'extracted'  => '<span class="badge-dot badge-amber !text-[10px]">Por validar</span>',
                    'approved'   => '<span class="badge-dot badge-green !text-[10px]">Aprobada</span>',
                    'error'      => '<span class="badge-dot badge-red !text-[10px]">Error</span>',
                    default      => '<span class="badge-dot badge-slate !text-[10px]">' . htmlspecialchars($r['status']) . '</span>',
                };
            ?>
            <li class="flex items-center gap-3 rounded-xl bg-stone-50 p-2 hover:bg-stone-100/80 transition-colors">
                <a href="<?= $thumbHref ?>" target="_blank" class="w-10 h-10 rounded-lg bg-white border border-stone-200 overflow-hidden shrink-0 block">
                    <?php if (strpos($r['mime_type'], 'image/') === 0): ?>
                    <img src="<?= $thumbHref ?>" alt="" class="w-full h-full object-cover">
                    <?php else: ?>
                    <div class="w-full h-full flex items-center justify-center text-slate-400">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                    </div>
                    <?php endif; ?>
                </a>
                <div class="flex-1 min-w-0">
                    <p class="text-xs font-semibold text-slate-900 truncate"><?= htmlspecialchars($r['counterparty_name'] ?: $r['original_name']) ?></p>
                    <p class="text-[10px] text-slate-500">
                        <?= $r['ncf'] ? htmlspecialchars($r['ncf']) . ' · ' : '' ?>
                        <?= $r['total'] ? 'RD$ ' . number_format((float)$r['total'], 2) : 'Sin total' ?>
                    </p>
                </div>
                <?= $statusBadge ?>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>

<!-- Progress + Pending invoices (legado) -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-4">
    <div class="surface-card p-6 lg:col-span-2">
        <h3 class="text-base font-bold text-slate-900">Mi progreso general</h3>
        <p class="text-xs text-slate-500 mt-0.5">Estado de tus tramites en curso</p>
        <div class="relative h-48 mt-4">
            <canvas id="clientStatusChart"></canvas>
        </div>
        <div class="mt-4 grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
            <?php
            $statusList = [
                ['Pendiente', '#EF4444', $chartClientData[0]],
                ['En proceso', '#F59E0B', $chartClientData[1]],
                ['En revision', '#3B82F6', $chartClientData[2]],
                ['Finalizado', '#10B981', $chartClientData[3]],
            ];
            foreach ($statusList as [$label, $color, $value]): ?>
            <div class="flex items-center gap-2">
                <span class="w-2 h-2 rounded-full" style="background: <?= $color ?>"></span>
                <span class="text-slate-600"><?= $label ?></span>
                <span class="ml-auto font-bold text-slate-900"><?= $value ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="surface-card p-6">
        <h3 class="text-base font-bold text-slate-900">Volantes por pagar</h3>
        <p class="text-xs text-slate-500 mt-0.5">Pagos pendientes</p>
        <div class="mt-4 space-y-3">
            <?php if (empty($pendingInvoices)): ?>
            <p class="text-sm text-slate-400 py-6 text-center">No tienes pagos pendientes.</p>
            <?php endif; ?>
            <?php foreach ($pendingInvoices as $inv): ?>
            <div class="flex items-center gap-3 rounded-2xl bg-stone-50 p-3">
                <div class="w-9 h-9 rounded-2xl bg-white border border-stone-200 flex items-center justify-center text-red-600">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"/></svg>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-xs font-semibold text-slate-900 truncate"><?= htmlspecialchars($inv['concept']) ?></p>
                    <p class="text-[11px] text-slate-500">Vence <?= date('d/m/Y', strtotime($inv['due_date'])) ?></p>
                </div>
                <p class="text-sm font-bold text-slate-900 shrink-0">RD$ <?= number_format((float)$inv['amount'], 0) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Igualas + puntuales (compactos) -->
<?php if (!empty($igualas) || !empty($puntuales)): ?>
<section class="mb-4">
    <?php if (!empty($igualas)): ?>
    <div class="flex items-center justify-between mb-3">
        <h2 class="text-base font-bold text-slate-900">Igualas mensuales</h2>
        <span class="badge-dot badge-slate"><?= count($igualas) ?> activas</span>
    </div>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-5">
        <?php foreach ($igualas as $req): ?>
        <a href="request_view.php?id=<?= $req['id'] ?>" class="surface-card p-4 group hover:border-blue-200 transition-colors block">
            <div class="flex justify-between items-start mb-2">
                <h4 class="font-bold text-sm text-slate-900 pr-3 leading-tight"><?= htmlspecialchars($req['title']) ?></h4>
                <?= getStatusBadge($req['status']) ?>
            </div>
            <p class="text-xs text-slate-500">Periodo <span class="font-semibold text-slate-700"><?= htmlspecialchars($req['period']) ?></span></p>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($puntuales)): ?>
    <div class="flex items-center justify-between mb-3">
        <h2 class="text-base font-bold text-slate-900">Tramites puntuales</h2>
        <span class="badge-dot badge-slate"><?= count($puntuales) ?> en curso</span>
    </div>
    <div class="space-y-3">
        <?php foreach ($puntuales as $req): ?>
        <div class="surface-card p-5">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-4 gap-3">
                <div>
                    <h4 class="text-sm font-bold text-slate-900 mb-0.5"><?= htmlspecialchars($req['title']) ?></h4>
                    <?php if ($req['estimated_delivery_date']): ?>
                    <p class="text-[11px] text-slate-500">Entrega estimada: <strong class="text-slate-700"><?= date('d/m/Y', strtotime($req['estimated_delivery_date'])) ?></strong></p>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-2">
                    <?= getStatusBadge($req['status']) ?>
                    <a href="request_view.php?id=<?= $req['id'] ?>" class="btn-dark text-xs">Abrir</a>
                </div>
            </div>
            <div class="h-1.5 mb-2 rounded-full bg-stone-100 overflow-hidden">
                <div style="width: <?= clientGetProgressPercentage($req['status']) ?>%" class="h-full bg-slate-900 rounded-full transition-all duration-500"></div>
            </div>
            <div class="flex justify-between text-[10px] font-medium text-slate-400">
                <div class="<?= clientIsStepActive($req['status'], 'pendiente') ? 'text-slate-900 font-bold' : '' ?>">Pendiente</div>
                <div class="<?= clientIsStepActive($req['status'], 'en_proceso') ? 'text-slate-900 font-bold' : '' ?>">En proceso</div>
                <div class="<?= clientIsStepActive($req['status'], 'en_revision') ? 'text-slate-900 font-bold' : '' ?>">En revision</div>
                <div class="<?= clientIsStepActive($req['status'], 'completado') ? 'text-slate-900 font-bold' : '' ?>">Entregado</div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<script>
new Chart(document.getElementById('clientStatusChart').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: ['Pendiente', 'En proceso', 'En revision', 'Finalizado'],
        datasets: [{
            data: <?= json_encode($chartClientData) ?>,
            backgroundColor: ['#EF4444', '#F59E0B', '#3B82F6', '#10B981'],
            borderWidth: 0,
            hoverOffset: 6
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        cutout: '72%'
    }
});

function copyTgCode() {
    const code = document.getElementById('tgCode').textContent.trim();
    if (navigator.clipboard) {
        navigator.clipboard.writeText(code).then(() => {
            const el = event.target;
            const orig = el.textContent;
            el.textContent = 'Copiado';
            setTimeout(() => { el.textContent = orig; }, 1500);
        });
    }
}
</script>

<?php include 'components/layout_end.php'; ?>
