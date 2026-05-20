<?php
require_once 'config.php';
requireAuth('admin');
// El alta de clientes se hace desde admin_clients.php (form completo con perfil fiscal).
// Aqui solo dejamos el dashboard mostrar metricas.

// 360 Metrics
$totalClients = $pdo->query("
    SELECT COUNT(*)
    FROM users u
    LEFT JOIN roles r ON r.slug = u.role
    WHERE COALESCE(r.access_level, CASE WHEN u.role = 'admin' THEN 'admin' ELSE 'client' END) = 'client'
")->fetchColumn();
$pendingRequests = $pdo->query("SELECT COUNT(*) FROM requests WHERE status='pendiente'")->fetchColumn();
$inProcessRequests = $pdo->query("SELECT COUNT(*) FROM requests WHERE status='en_proceso'")->fetchColumn();
$completedRequests = $pdo->query("SELECT COUNT(*) FROM requests WHERE status='completado' OR status='presentado'")->fetchColumn();

// Status distribution
$statusCounts = $pdo->query("SELECT status, COUNT(*) as count FROM requests GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$allStatuses = ['pendiente', 'en_proceso', 'en_revision', 'presentado', 'completado'];
$chartStatusData = [];
foreach ($allStatuses as $s) {
    $chartStatusData[] = $statusCounts[$s] ?? 0;
}

// Client growth chart (6 months)
$growthData = $pdo->query("
    SELECT DATE_FORMAT(u.created_at, '%Y-%m') as month, COUNT(*) as count
    FROM users u
    LEFT JOIN roles r ON r.slug = u.role
    WHERE COALESCE(r.access_level, CASE WHEN u.role = 'admin' THEN 'admin' ELSE 'client' END) = 'client'
      AND u.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY month
    ORDER BY month ASC
")->fetchAll(PDO::FETCH_KEY_PAIR);

$chartMonths = [];
$chartGrowthValues = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i months"));
    $chartMonths[] = date('M', strtotime("-$i months"));
    $chartGrowthValues[] = $growthData[$m] ?? 0;
}

// Recent activity feed: latest requests
$recentActivity = $pdo->query("
    SELECT r.id, r.status, r.created_at, s.title as service_title, u.name as client_name
    FROM requests r
    JOIN services s ON r.service_id = s.id
    JOIN users u ON r.client_id = u.id
    ORDER BY r.created_at DESC
    LIMIT 6
")->fetchAll();

// Update overdue DGII obligations
$pdo->exec("UPDATE tax_obligations SET status='vencido' WHERE status='pendiente' AND due_date < CURDATE()");

// DGII alerts
$alertCounts = $pdo->query("
    SELECT
        SUM(CASE WHEN status='vencido' THEN 1 ELSE 0 END) AS overdue,
        SUM(CASE WHEN status='pendiente' AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS week,
        SUM(CASE WHEN status='pendiente' AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS month
    FROM tax_obligations
")->fetch();

$upcomingObligations = $pdo->query("
    SELECT o.id, o.obligation_type, o.period, o.due_date, o.status, u.name AS client_name, o.client_id
    FROM tax_obligations o
    JOIN users u ON u.id = o.client_id
    WHERE o.status IN ('pendiente','vencido')
      AND o.due_date <= DATE_ADD(CURDATE(), INTERVAL 14 DAY)
    ORDER BY o.due_date ASC
    LIMIT 8
")->fetchAll();

// Overdue invoices
$overdueInvoices = $pdo->query("
    SELECT COUNT(*) AS c, COALESCE(SUM(amount),0) AS total
    FROM invoices
    WHERE status='pendiente' AND due_date < CURDATE()
")->fetch();

// AI invoice metrics (current month)
$aiPeriod = date('Y-m');
$aiKpis = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN u.status='approved'  THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN u.status='extracted' THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN u.status='error'     THEN 1 ELSE 0 END) AS errors,
        SUM(CASE WHEN u.source='telegram'  THEN 1 ELSE 0 END) AS via_telegram,
        COALESCE(SUM(CASE WHEN e.doc_type='compra' THEN e.itbis ELSE 0 END), 0) AS itbis_compras,
        COALESCE(SUM(CASE WHEN e.doc_type='venta'  THEN e.itbis ELSE 0 END), 0) AS itbis_ventas
    FROM invoice_uploads u
    LEFT JOIN invoice_extractions e ON e.upload_id = u.id
    WHERE (e.period = ? OR (e.period IS NULL AND DATE_FORMAT(u.created_at,'%Y-%m') = ?))
");
$aiKpis->execute([$aiPeriod, $aiPeriod]);
$ai = $aiKpis->fetch() ?: ['total'=>0,'approved'=>0,'pending'=>0,'errors'=>0,'via_telegram'=>0,'itbis_compras'=>0,'itbis_ventas'=>0];

// Pending approvals count (public signups)
$pendingApprovalsCount = signupPendingCount();

// Recent IA activity (last 5 extracted/approved invoices)
$recentInvoices = $pdo->query("
    SELECT u.id, u.status, u.created_at, u.source, u.client_id,
           e.doc_type, e.total, e.itbis, e.counterparty_name, e.confidence,
           c.name AS client_name, c.business_name
    FROM invoice_uploads u
    LEFT JOIN invoice_extractions e ON e.upload_id = u.id
    LEFT JOIN users c ON c.id = u.client_id
    WHERE u.status IN ('extracted','approved','error')
    ORDER BY u.created_at DESC
    LIMIT 6
")->fetchAll();

// All clients
$stmt = $pdo->query("
    SELECT u.*,
           (SELECT COUNT(*) FROM requests WHERE client_id = u.id) as request_count
    FROM users u
    LEFT JOIN roles r ON r.slug = u.role
    WHERE COALESCE(r.access_level, CASE WHEN u.role = 'admin' THEN 'admin' ELSE 'client' END) = 'client'
    ORDER BY u.created_at DESC
");
$clients = $stmt->fetchAll();

$firstName = explode(' ', $_SESSION['name'])[0];

$page_title    = 'Hola, ' . $firstName;
$page_subtitle = 'Tu cartera de clientes y tramites de un vistazo.';
$page_actions  = '<a href="admin_clients.php" class="btn-soft text-sm">Ver clientes</a>
<a href="admin_clients.php?new=1" class="btn-dark text-sm">
    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
    Nuevo cliente
</a>';

include 'components/layout_start.php';
?>

<?php if (isset($success)): ?>
<div class="mb-6 rounded-2xl bg-emerald-50 px-4 py-3 border border-emerald-100 text-sm font-medium text-emerald-800"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if (isset($error)): ?>
<div class="mb-6 rounded-2xl bg-red-50 px-4 py-3 border border-red-100 text-sm font-medium text-red-700"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Stats row -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
    <?php
    $stats = [
        ['label' => 'Total clientes', 'value' => $totalClients, 'icon' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6 5.87v-2a4 4 0 00-4-4H7a4 4 0 00-4 4v2m13-10a4 4 0 11-8 0 4 4 0 018 0z"/></svg>', 'tint' => 'bg-blue-50 text-blue-600', 'change' => '+' . max(0, $totalClients) . ' total'],
        ['label' => 'Pendientes',    'value' => $pendingRequests, 'icon' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>', 'tint' => 'bg-red-50 text-red-600', 'change' => 'requieren info'],
        ['label' => 'En proceso',    'value' => $inProcessRequests, 'icon' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>', 'tint' => 'bg-amber-50 text-amber-700', 'change' => 'en trabajo activo'],
        ['label' => 'Finalizadas',   'value' => $completedRequests, 'icon' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>', 'tint' => 'bg-emerald-50 text-emerald-600', 'change' => 'entregadas o presentadas'],
    ];
    foreach ($stats as $s):
    ?>
    <div class="stat-card p-5">
        <div class="flex items-start justify-between">
            <div class="w-10 h-10 rounded-2xl <?= $s['tint'] ?> flex items-center justify-center">
                <?= $s['icon'] ?>
            </div>
        </div>
        <p class="mt-4 text-sm text-slate-500"><?= $s['label'] ?></p>
        <p class="mt-1 text-3xl font-extrabold tracking-tight text-slate-900"><?= $s['value'] ?></p>
        <p class="mt-1 text-[11px] text-slate-400 font-medium"><?= $s['change'] ?></p>
    </div>
    <?php endforeach; ?>
</div>

<!-- IA + Aprobaciones bloque -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-3 mb-4">
    <!-- IA card -->
    <a href="admin_invoice_review.php" class="surface-card p-5 lg:col-span-2 hover:border-blue-200 transition-colors group">
        <div class="flex items-start justify-between mb-4">
            <div>
                <p class="text-[10px] font-bold uppercase tracking-wider text-blue-600 mb-1">IA Fiscal este mes</p>
                <h3 class="text-lg font-extrabold text-slate-900">Lectura inteligente de facturas</h3>
            </div>
            <div class="w-10 h-10 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
            </div>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div>
                <p class="text-2xl font-extrabold text-slate-900"><?= (int)$ai['total'] ?></p>
                <p class="text-[11px] text-slate-500">Subidas</p>
            </div>
            <div>
                <p class="text-2xl font-extrabold text-amber-600"><?= (int)$ai['pending'] ?></p>
                <p class="text-[11px] text-slate-500">Por validar</p>
            </div>
            <div>
                <p class="text-2xl font-extrabold text-emerald-600"><?= (int)$ai['approved'] ?></p>
                <p class="text-[11px] text-slate-500">Aprobadas</p>
            </div>
            <div>
                <p class="text-2xl font-extrabold text-sky-600"><?= (int)$ai['via_telegram'] ?></p>
                <p class="text-[11px] text-slate-500">Por Telegram</p>
            </div>
        </div>
        <div class="mt-4 pt-4 border-t border-stone-100 flex items-center justify-between">
            <div class="grid grid-cols-2 gap-4 flex-1">
                <div>
                    <p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">ITBIS cobrado</p>
                    <p class="text-sm font-bold text-emerald-700">RD$ <?= number_format((float)$ai['itbis_ventas'], 0) ?></p>
                </div>
                <div>
                    <p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">ITBIS pagado</p>
                    <p class="text-sm font-bold text-blue-700">RD$ <?= number_format((float)$ai['itbis_compras'], 0) ?></p>
                </div>
            </div>
            <span class="inline-flex items-center gap-1 text-xs font-semibold text-blue-600 group-hover:text-blue-800">
                Revisar facturas
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            </span>
        </div>
    </a>

    <!-- Aprobaciones pendientes -->
    <a href="admin_approvals.php" class="surface-card p-5 hover:border-amber-200 transition-colors group <?= $pendingApprovalsCount > 0 ? 'bg-amber-50/40 border-amber-100' : '' ?>">
        <div class="flex items-start justify-between mb-3">
            <div>
                <p class="text-[10px] font-bold uppercase tracking-wider <?= $pendingApprovalsCount > 0 ? 'text-amber-700' : 'text-slate-500' ?> mb-1">Aprobaciones</p>
                <h3 class="text-lg font-extrabold text-slate-900">Clientes nuevos</h3>
            </div>
            <div class="w-10 h-10 rounded-2xl <?= $pendingApprovalsCount > 0 ? 'bg-amber-100 text-amber-700' : 'bg-stone-100 text-slate-500' ?> flex items-center justify-center relative">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                <?php if ($pendingApprovalsCount > 0): ?>
                <span class="absolute -top-1 -right-1 inline-flex items-center justify-center min-w-[18px] h-4 px-1 rounded-full bg-red-500 text-white text-[10px] font-bold animate-pulse"><?= $pendingApprovalsCount ?></span>
                <?php endif; ?>
            </div>
        </div>
        <p class="text-4xl font-extrabold tracking-tight <?= $pendingApprovalsCount > 0 ? 'text-amber-700' : 'text-slate-300' ?>"><?= $pendingApprovalsCount ?></p>
        <p class="text-xs text-slate-500 mt-1">
            <?= $pendingApprovalsCount > 0 ? 'esperando tu revision' : 'sin solicitudes pendientes' ?>
        </p>
        <span class="mt-3 inline-flex items-center gap-1 text-xs font-semibold text-blue-600 group-hover:text-blue-800">
            <?= $pendingApprovalsCount > 0 ? 'Revisar ahora' : 'Ver historial' ?>
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        </span>
    </a>
</div>

<!-- DGII Alerts -->
<?php
$hasAlerts = (int)$alertCounts['overdue'] > 0 || (int)$alertCounts['week'] > 0 || (int)$overdueInvoices['c'] > 0;
if ($hasAlerts):
?>
<div class="surface-card p-4 lg:p-5 mb-4 border-amber-200 bg-amber-50/40">
    <div class="flex items-start gap-3">
        <div class="w-10 h-10 rounded-2xl bg-amber-100 text-amber-700 flex items-center justify-center shrink-0">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 00-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg>
        </div>
        <div class="flex-1 min-w-0">
            <h3 class="text-sm font-bold text-amber-900">Alertas DGII y finanzas</h3>
            <div class="mt-2 flex flex-wrap gap-3 text-xs">
                <?php if ((int)$alertCounts['overdue'] > 0): ?>
                <a href="admin_tax_calendar.php?range=overdue" class="inline-flex items-center gap-2 rounded-xl bg-white px-3 py-2 border border-red-200 hover:border-red-400">
                    <span class="w-2 h-2 rounded-full bg-red-500"></span>
                    <span class="font-bold text-red-700"><?= (int)$alertCounts['overdue'] ?></span>
                    <span class="text-slate-700">obligacion(es) vencida(s)</span>
                </a>
                <?php endif; ?>
                <?php if ((int)$alertCounts['week'] > 0): ?>
                <a href="admin_tax_calendar.php?range=week" class="inline-flex items-center gap-2 rounded-xl bg-white px-3 py-2 border border-amber-200 hover:border-amber-400">
                    <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                    <span class="font-bold text-amber-700"><?= (int)$alertCounts['week'] ?></span>
                    <span class="text-slate-700">vencen esta semana</span>
                </a>
                <?php endif; ?>
                <?php if ((int)$overdueInvoices['c'] > 0): ?>
                <a href="admin_finances.php" class="inline-flex items-center gap-2 rounded-xl bg-white px-3 py-2 border border-red-200 hover:border-red-400">
                    <span class="w-2 h-2 rounded-full bg-red-500"></span>
                    <span class="font-bold text-red-700"><?= (int)$overdueInvoices['c'] ?></span>
                    <span class="text-slate-700">volante(s) vencido(s) - RD$ <?= number_format((float)$overdueInvoices['total'], 0) ?></span>
                </a>
                <?php endif; ?>
            </div>
            <?php if (!empty($upcomingObligations)): ?>
            <div class="mt-3 pt-3 border-t border-amber-200">
                <p class="text-[11px] font-bold uppercase tracking-wider text-amber-700 mb-2">Proximos vencimientos</p>
                <div class="space-y-1.5">
                    <?php foreach (array_slice($upcomingObligations, 0, 4) as $ob):
                        $days = (int)((strtotime($ob['due_date']) - strtotime(date('Y-m-d'))) / 86400);
                        $dayLabel = $days < 0 ? 'vencido hace ' . abs($days) . 'd' : ($days === 0 ? 'hoy' : "en {$days}d");
                    ?>
                    <a href="client_details.php?id=<?= $ob['client_id'] ?>" class="flex items-center gap-2 text-xs hover:bg-amber-100/50 rounded-lg px-2 py-1 -mx-2">
                        <span class="w-7 text-center text-[9px] font-extrabold text-slate-700 bg-stone-100 rounded px-1"><?= htmlspecialchars(str_replace(['IT-','IR-','ANTICIPO'], ['IT', 'IR', 'AN'], $ob['obligation_type'])) ?></span>
                        <span class="text-slate-700 truncate flex-1"><?= htmlspecialchars($ob['client_name']) ?></span>
                        <span class="text-slate-500"><?= htmlspecialchars(formatPeriod($ob['period'])) ?></span>
                        <span class="font-bold <?= $days < 0 ? 'text-red-600' : ($days <= 3 ? 'text-amber-700' : 'text-slate-600') ?>"><?= $dayLabel ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
                <a href="admin_tax_calendar.php" class="mt-2 inline-block text-xs font-semibold text-amber-800 hover:text-amber-900">Ver todo el calendario fiscal &rarr;</a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Charts row -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
    <div class="surface-card p-6 lg:col-span-2">
        <div class="flex items-center justify-between mb-1">
            <div>
                <h3 class="text-base font-bold text-slate-900">Rendimiento</h3>
                <p class="text-xs text-slate-500 mt-0.5">Nuevos clientes en los ultimos 6 meses</p>
            </div>
            <span class="badge-dot badge-slate">Ultimo semestre</span>
        </div>
        <div class="relative h-64 mt-4">
            <canvas id="growthChart"></canvas>
        </div>
    </div>

    <div class="surface-card p-6">
        <div class="flex items-center justify-between mb-1">
            <h3 class="text-base font-bold text-slate-900">Estado de tramites</h3>
        </div>
        <p class="text-xs text-slate-500">Distribucion actual</p>
        <div class="relative h-44 mt-4">
            <canvas id="statusChart"></canvas>
        </div>
        <div class="mt-4 grid grid-cols-1 gap-2 text-xs">
            <?php
            $statusList = [
                ['label' => 'Pendiente', 'color' => '#EF4444', 'value' => $chartStatusData[0]],
                ['label' => 'En proceso', 'color' => '#F59E0B', 'value' => $chartStatusData[1]],
                ['label' => 'En revision', 'color' => '#3B82F6', 'value' => $chartStatusData[2]],
                ['label' => 'Presentado', 'color' => '#10B981', 'value' => $chartStatusData[3]],
                ['label' => 'Completado', 'color' => '#059669', 'value' => $chartStatusData[4]],
            ];
            foreach ($statusList as $st): ?>
            <div class="flex items-center justify-between">
                <span class="flex items-center gap-2 text-slate-600">
                    <span class="w-2 h-2 rounded-full" style="background: <?= $st['color'] ?>"></span>
                    <?= $st['label'] ?>
                </span>
                <span class="font-bold text-slate-900"><?= $st['value'] ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Activity + Clients row -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

    <!-- Recent activity -->
    <div class="surface-card p-6 lg:col-span-1">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-base font-bold text-slate-900">Actividad reciente</h3>
            <a href="admin_requests.php" class="text-xs font-semibold text-blue-600 hover:text-blue-700">Ver todo &rarr;</a>
        </div>
        <div class="space-y-4">
            <?php if (empty($recentActivity)): ?>
            <p class="text-sm text-slate-400 py-8 text-center">Sin actividad reciente.</p>
            <?php endif; ?>
            <?php foreach ($recentActivity as $act):
                $statusMap = [
                    'pendiente' => 'badge-red',
                    'en_proceso' => 'badge-amber',
                    'en_revision' => 'badge-blue',
                    'presentado' => 'badge-green',
                    'completado' => 'badge-green',
                ];
                $statusLabel = [
                    'pendiente' => 'Pendiente',
                    'en_proceso' => 'En proceso',
                    'en_revision' => 'En revision',
                    'presentado' => 'Presentado',
                    'completado' => 'Completado',
                ];
            ?>
            <a href="request_view.php?id=<?= $act['id'] ?>" class="flex gap-3 group">
                <div class="w-9 h-9 rounded-full bg-stone-100 flex items-center justify-center text-xs font-bold text-slate-600 shrink-0">
                    <?= htmlspecialchars(strtoupper(substr($act['client_name'], 0, 1))) ?>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-semibold text-slate-900 truncate group-hover:text-blue-600">
                        <?= htmlspecialchars($act['client_name']) ?>
                    </p>
                    <p class="text-xs text-slate-500 truncate"><?= htmlspecialchars($act['service_title']) ?></p>
                    <div class="mt-1.5 flex items-center gap-2">
                        <span class="badge-dot <?= $statusMap[$act['status']] ?? 'badge-slate' ?>"><?= $statusLabel[$act['status']] ?? $act['status'] ?></span>
                        <span class="text-[11px] text-slate-400"><?= date('d/m', strtotime($act['created_at'])) ?></span>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Recent IA invoices stream -->
    <div class="surface-card overflow-hidden lg:col-span-2">
        <div class="px-6 py-5 border-b border-stone-100 flex items-center justify-between">
            <div>
                <h3 class="text-base font-bold text-slate-900">Facturas IA recientes</h3>
                <p class="text-xs text-slate-500 mt-0.5">Ultimas extraidas o aprobadas por la IA</p>
            </div>
            <a href="admin_invoice_review.php" class="text-xs font-semibold text-blue-600 hover:text-blue-700">Revisar todo &rarr;</a>
        </div>
        <ul class="divide-y divide-stone-100">
            <?php if (empty($recentInvoices)): ?>
            <li class="px-6 py-10 text-center text-sm text-slate-400">
                <svg class="w-10 h-10 mx-auto text-slate-300 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                Cuando los clientes suban facturas apareceran aqui en tiempo real.
            </li>
            <?php else: ?>
            <?php foreach ($recentInvoices as $inv):
                $statusTone = $inv['status'] === 'approved' ? 'emerald' : ($inv['status'] === 'extracted' ? 'amber' : 'red');
                $statusLabel = $inv['status'] === 'approved' ? 'Aprobada' : ($inv['status'] === 'extracted' ? 'Por validar' : 'Error');
                $confPct = round(((float)($inv['confidence'] ?? 0)) * 100);
                $sourceIcon = $inv['source'] === 'telegram'
                    ? '<svg class="w-3.5 h-3.5 text-sky-500" fill="currentColor" viewBox="0 0 24 24"><path d="M9.78 18.65l.28-4.23 7.68-6.92c.34-.31-.07-.46-.52-.19L7.74 13.3 3.64 12c-.88-.25-.89-.86.2-1.3l15.97-6.16c.73-.33 1.43.18 1.15 1.3l-2.72 12.81c-.19.91-.74 1.13-1.5.71L12.6 16.3l-1.99 1.93c-.23.23-.42.42-.83.42z"/></svg>'
                    : '<svg class="w-3.5 h-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 3v18m-6-9h18M21 6V4a1 1 0 00-1-1H4a1 1 0 00-1 1v16a1 1 0 001 1h16a1 1 0 001-1v-2"/></svg>';
            ?>
            <li>
                <a href="admin_invoice_review.php?client_id=<?= (int)$inv['client_id'] ?>" class="flex items-center gap-3 px-6 py-3.5 hover:bg-stone-50/60 transition-colors">
                    <div class="w-9 h-9 rounded-xl bg-stone-100 border border-stone-200 flex items-center justify-center text-xs font-bold text-slate-700 shrink-0">
                        <?= htmlspecialchars(strtoupper(substr($inv['business_name'] ?: $inv['client_name'] ?: 'C', 0, 1))) ?>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <p class="text-sm font-bold text-slate-900 truncate"><?= htmlspecialchars($inv['business_name'] ?: $inv['client_name']) ?></p>
                            <?= $sourceIcon ?>
                        </div>
                        <p class="text-[11px] text-slate-500 truncate">
                            <?= htmlspecialchars($inv['counterparty_name'] ?: '—') ?>
                            <?php if ($inv['doc_type']): ?>
                            <span class="text-slate-400">·</span> <?= $inv['doc_type'] === 'venta' ? '607' : '606' ?>
                            <?php endif; ?>
                            <span class="text-slate-400">·</span> <?= date('d/m H:i', strtotime($inv['created_at'])) ?>
                        </p>
                    </div>
                    <?php if ((float)$inv['total'] > 0): ?>
                    <div class="text-right shrink-0">
                        <p class="text-sm font-extrabold text-slate-900">RD$ <?= number_format((float)$inv['total'], 0) ?></p>
                        <?php if ($confPct > 0): ?>
                        <p class="text-[10px] font-bold text-<?= $statusTone === 'amber' ? 'amber' : 'slate' ?>-500"><?= $confPct ?>% IA</p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <span class="inline-flex items-center gap-1 text-[10px] font-bold uppercase tracking-wider px-2 py-1 rounded-full bg-<?= $statusTone ?>-50 text-<?= $statusTone ?>-700">
                        <?= $statusLabel ?>
                    </span>
                </a>
            </li>
            <?php endforeach; ?>
            <?php endif; ?>
        </ul>
    </div>
</div>

<!-- Clients directory -->
<div class="grid grid-cols-1 mt-4">
    <div class="surface-card overflow-hidden">
        <div class="px-6 py-5 border-b border-stone-100 flex items-center justify-between">
            <div>
                <h3 class="text-base font-bold text-slate-900">Directorio de clientes</h3>
                <p class="text-xs text-slate-500 mt-0.5"><?= count($clients) ?> registrados</p>
            </div>
            <a href="admin_clients.php" class="text-xs font-semibold text-blue-600 hover:text-blue-700">Ver todos &rarr;</a>
        </div>
        <ul class="divide-y divide-stone-100">
            <?php if (empty($clients)): ?>
            <li class="px-6 py-10 text-center text-sm text-slate-400">Aun no hay clientes. Agrega el primero usando el boton arriba.</li>
            <?php endif; ?>
            <?php foreach (array_slice($clients, 0, 8) as $client): ?>
            <li>
                <a href="client_details.php?id=<?= $client['id'] ?>"
                   class="flex items-center gap-4 px-6 py-4 hover:bg-stone-50/60 transition-colors">
                    <div class="h-11 w-11 rounded-full bg-stone-100 border border-stone-200 flex items-center justify-center text-sm font-bold text-slate-700">
                        <?= htmlspecialchars(substr(strtoupper($client['name']), 0, 1)) ?>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-slate-900 truncate"><?= htmlspecialchars($client['name']) ?></p>
                        <p class="text-xs text-slate-500 truncate"><?= htmlspecialchars($client['email']) ?></p>
                    </div>
                    <div class="hidden sm:flex flex-col items-end gap-1">
                        <span class="badge-dot badge-slate"><?= (int) $client['request_count'] ?> tramites</span>
                        <span class="text-[11px] text-slate-400"><?= date('d/m/Y', strtotime($client['created_at'])) ?></span>
                    </div>
                    <svg class="w-4 h-4 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div> <!-- /clients directory grid -->

<script>
const baseChart = {
    plugins: { legend: { display: false } },
    responsive: true,
    maintainAspectRatio: false
};

// Status doughnut
new Chart(document.getElementById('statusChart').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: ['Pendiente', 'En proceso', 'En revision', 'Presentado', 'Completado'],
        datasets: [{
            data: <?= json_encode($chartStatusData) ?>,
            backgroundColor: ['#EF4444', '#F59E0B', '#3B82F6', '#10B981', '#059669'],
            borderWidth: 0,
            hoverOffset: 6
        }]
    },
    options: { ...baseChart, cutout: '72%' }
});

// Growth line/area chart
const growthCtx = document.getElementById('growthChart').getContext('2d');
const gradient = growthCtx.createLinearGradient(0, 0, 0, 240);
gradient.addColorStop(0, 'rgba(37, 99, 235, 0.25)');
gradient.addColorStop(1, 'rgba(37, 99, 235, 0)');

new Chart(growthCtx, {
    type: 'line',
    data: {
        labels: <?= json_encode($chartMonths) ?>,
        datasets: [{
            label: 'Nuevos clientes',
            data: <?= json_encode($chartGrowthValues) ?>,
            borderColor: '#2563EB',
            backgroundColor: gradient,
            tension: 0.4,
            fill: true,
            pointBackgroundColor: '#fff',
            pointBorderColor: '#2563EB',
            pointBorderWidth: 2,
            pointRadius: 5,
            pointHoverRadius: 7,
            borderWidth: 3
        }]
    },
    options: {
        ...baseChart,
        scales: {
            y: { beginAtZero: true, grid: { color: '#F1F5F9' }, ticks: { stepSize: 1, color: '#94A3B8', font: { size: 11 } }, border: { display: false } },
            x: { grid: { display: false }, ticks: { color: '#94A3B8', font: { size: 11 } }, border: { display: false } }
        }
    }
});
</script>

<?php include 'components/layout_end.php'; ?>
