<?php
require_once 'config.php';
requireAuth('client');

$client_id = $_SESSION['user_id'];

$stmt = $pdo->prepare("
    SELECT r.*, s.title, s.type
    FROM requests r
    JOIN services s ON r.service_id = s.id
    WHERE r.client_id = ?
    ORDER BY r.created_at DESC
");
$stmt->execute([$client_id]);
$requests = $stmt->fetchAll();

$igualas = array_filter($requests, fn($r) => $r['type'] === 'iguala');
$puntuales = array_filter($requests, fn($r) => $r['type'] === 'puntual');

// Pending invoices
$invStmt = $pdo->prepare("SELECT * FROM invoices WHERE client_id = ? AND status = 'pendiente' ORDER BY due_date ASC LIMIT 5");
$invStmt->execute([$client_id]);
$pendingInvoices = $invStmt->fetchAll();

$totalPending = 0;
foreach ($pendingInvoices as $i) $totalPending += $i['amount'];

// Personal progress
$clientStatusCounts = ['pendiente' => 0, 'en_proceso' => 0, 'en_revision' => 0, 'finalizado' => 0];
foreach ($requests as $r) {
    if (in_array($r['status'], ['completado', 'presentado'])) $clientStatusCounts['finalizado']++;
    else $clientStatusCounts[$r['status']] = ($clientStatusCounts[$r['status']] ?? 0) + 1;
}
$chartClientData = array_values($clientStatusCounts);

$totalActive = count($requests) - $clientStatusCounts['finalizado'];

function getProgressPercentage($status) {
    return match($status) {
        'pendiente' => 25, 'en_proceso' => 50,
        'en_revision' => 75, 'completado', 'presentado' => 100,
        default => 0
    };
}
function isStepActive($currentStatus, $stepStatus) {
    $levels = ['pendiente' => 1, 'en_proceso' => 2, 'en_revision' => 3, 'completado' => 4, 'presentado' => 4];
    return ($levels[$currentStatus] ?? 0) >= ($levels[$stepStatus] ?? 0);
}

$firstName = explode(' ', $_SESSION['name'])[0];
$page_title = 'Hola, ' . $firstName;
$page_subtitle = 'Tu espacio personal para dar seguimiento a tus tramites.';
$main_max = 'max-w-6xl';
include 'components/layout_start.php';
?>

<!-- Summary cards -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-4">
    <div class="stat-card p-5">
        <div class="w-10 h-10 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center mb-3">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
        </div>
        <p class="text-sm text-slate-500">Tramites activos</p>
        <p class="mt-1 text-3xl font-extrabold tracking-tight text-slate-900"><?= $totalActive ?></p>
    </div>
    <div class="stat-card p-5">
        <div class="w-10 h-10 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center mb-3">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
        </div>
        <p class="text-sm text-slate-500">Finalizados</p>
        <p class="mt-1 text-3xl font-extrabold tracking-tight text-slate-900"><?= $clientStatusCounts['finalizado'] ?></p>
    </div>
    <div class="stat-card p-5">
        <div class="w-10 h-10 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center mb-3">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        </div>
        <p class="text-sm text-slate-500">Igualas mensuales</p>
        <p class="mt-1 text-3xl font-extrabold tracking-tight text-slate-900"><?= count($igualas) ?></p>
    </div>
    <div class="stat-card p-5">
        <div class="w-10 h-10 rounded-2xl bg-red-50 text-red-600 flex items-center justify-center mb-3">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <p class="text-sm text-slate-500">Por pagar</p>
        <p class="mt-1 text-3xl font-extrabold tracking-tight text-slate-900">RD$ <?= number_format($totalPending, 0) ?></p>
    </div>
</div>

<!-- Progress + Pending invoices -->
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
                <p class="text-sm font-bold text-slate-900 shrink-0">RD$ <?= number_format($inv['amount'], 0) ?></p>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Igualas -->
<section class="mb-4">
    <div class="flex items-center justify-between mb-4">
        <div>
            <h2 class="text-lg font-bold text-slate-900">Igualas mensuales</h2>
            <p class="text-xs text-slate-500">Servicios recurrentes activos</p>
        </div>
        <span class="badge-dot badge-slate"><?= count($igualas) ?> activas</span>
    </div>

    <?php if (empty($igualas)): ?>
    <div class="surface-card p-10 text-center border-dashed">
        <p class="text-sm text-slate-400">No tienes igualas registradas para este periodo.</p>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <?php foreach ($igualas as $req): ?>
        <a href="request_view.php?id=<?= $req['id'] ?>" class="surface-card p-5 group hover:border-blue-200 transition-colors block">
            <div class="flex justify-between items-start mb-3">
                <h4 class="font-bold text-slate-900 pr-4 leading-tight"><?= htmlspecialchars($req['title']) ?></h4>
                <?= getStatusBadge($req['status']) ?>
            </div>
            <div class="flex items-center gap-2 text-xs text-slate-500 mb-3">
                <svg class="w-3.5 h-3.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10"/></svg>
                Periodo <span class="font-semibold text-slate-700"><?= htmlspecialchars($req['period']) ?></span>
            </div>
            <div class="flex items-center justify-between pt-3 border-t border-stone-100">
                <span class="text-xs font-semibold text-blue-600 group-hover:text-blue-700">Ver detalles</span>
                <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<!-- Puntuales con timeline -->
<section>
    <div class="flex items-center justify-between mb-4">
        <div>
            <h2 class="text-lg font-bold text-slate-900">Tramites puntuales</h2>
            <p class="text-xs text-slate-500">Solicitudes individuales con progreso</p>
        </div>
        <span class="badge-dot badge-slate"><?= count($puntuales) ?> en curso</span>
    </div>

    <?php if (empty($puntuales)): ?>
    <div class="surface-card p-10 text-center border-dashed">
        <p class="text-sm text-slate-400">No tienes solicitudes puntuales en curso.</p>
    </div>
    <?php else: ?>
    <div class="space-y-4">
        <?php foreach ($puntuales as $req): ?>
        <div class="surface-card p-6">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between mb-5 gap-3">
                <div>
                    <h4 class="text-base font-bold text-slate-900 mb-1"><?= htmlspecialchars($req['title']) ?></h4>
                    <?php if ($req['estimated_delivery_date']): ?>
                    <p class="text-xs text-slate-500 flex items-center gap-1.5">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Entrega estimada: <strong class="text-slate-700"><?= date('d/m/Y', strtotime($req['estimated_delivery_date'])) ?></strong>
                    </p>
                    <?php endif; ?>
                </div>
                <div class="flex items-center gap-3">
                    <?= getStatusBadge($req['status']) ?>
                    <a href="request_view.php?id=<?= $req['id'] ?>" class="btn-dark text-xs">Abrir</a>
                </div>
            </div>
            <!-- Timeline -->
            <div class="pt-2">
                <div class="h-2 mb-4 rounded-full bg-stone-100 overflow-hidden">
                    <div style="width: <?= getProgressPercentage($req['status']) ?>%" class="h-full bg-slate-900 rounded-full transition-all duration-500"></div>
                </div>
                <div class="flex justify-between text-[11px] font-medium text-slate-400">
                    <div class="<?= isStepActive($req['status'], 'pendiente') ? 'text-slate-900 font-bold' : '' ?>">Pendiente</div>
                    <div class="<?= isStepActive($req['status'], 'en_proceso') ? 'text-slate-900 font-bold' : '' ?>">En proceso</div>
                    <div class="<?= isStepActive($req['status'], 'en_revision') ? 'text-slate-900 font-bold' : '' ?>">En revision</div>
                    <div class="<?= isStepActive($req['status'], 'completado') ? 'text-slate-900 font-bold' : '' ?>">Entregado</div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

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
</script>

<?php include 'components/layout_end.php'; ?>
