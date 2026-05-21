<?php
require_once 'config.php';
requireAuth('client');

$clientId = (int)$_SESSION['user_id'];

// Period: month being viewed
$ymRaw = $_GET['ym'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ymRaw)) $ymRaw = date('Y-m');
[$year, $month] = explode('-', $ymRaw);
$year = (int)$year;
$month = (int)$month;

$firstOfMonth = mktime(0, 0, 0, $month, 1, $year);
$daysInMonth  = (int)date('t', $firstOfMonth);
$firstDow     = (int)date('w', $firstOfMonth); // 0 = Sunday
// Normalizar a lunes-domingo (0 = Lunes)
$firstDowMon  = ($firstDow + 6) % 7;

$monthsLabels = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$periodLabel  = $monthsLabels[$month - 1] . ' ' . $year;
$prevYm = date('Y-m', mktime(0, 0, 0, $month - 1, 1, $year));
$nextYm = date('Y-m', mktime(0, 0, 0, $month + 1, 1, $year));

$dateStart = sprintf('%04d-%02d-01', $year, $month);
$dateEnd   = date('Y-m-d', mktime(0, 0, 0, $month + 1, 0, $year));

// Asegurar obligaciones generadas
generateObligationsForClient($clientId, 3);

// Obligaciones del mes
$obStmt = $pdo->prepare("
    SELECT obligation_type, period, due_date, status
    FROM tax_obligations
    WHERE client_id = ?
      AND due_date BETWEEN ? AND ?
    ORDER BY due_date ASC
");
$obStmt->execute([$clientId, $dateStart, $dateEnd]);
$obligations = $obStmt->fetchAll();

// Facturas subidas en el mes (por fecha de subida o fecha del documento)
$invStmt = $pdo->prepare("
    SELECT u.id, u.status, u.created_at, u.source,
           e.date_doc, e.doc_type, e.counterparty_name, e.total, e.itbis, e.ncf
    FROM invoice_uploads u
    LEFT JOIN invoice_extractions e ON e.upload_id = u.id
    WHERE u.client_id = ?
      AND (e.date_doc BETWEEN ? AND ? OR (e.date_doc IS NULL AND DATE(u.created_at) BETWEEN ? AND ?))
    ORDER BY COALESCE(e.date_doc, u.created_at) ASC
");
$invStmt->execute([$clientId, $dateStart, $dateEnd, $dateStart, $dateEnd]);
$invoices = $invStmt->fetchAll();

// Indexar por dia
$byDay = [];
foreach ($obligations as $o) {
    $d = (int)date('j', strtotime($o['due_date']));
    $byDay[$d]['obs'][] = $o;
}
foreach ($invoices as $i) {
    $dateUse = $i['date_doc'] ?: $i['created_at'];
    $d = (int)date('j', strtotime($dateUse));
    $byDay[$d]['invs'][] = $i;
}

// Resumen del mes
$monthSummary = [
    'obligaciones' => count($obligations),
    'vencidas'     => count(array_filter($obligations, fn($o) => $o['status'] === 'vencido')),
    'completadas'  => count(array_filter($obligations, fn($o) => $o['status'] === 'completado')),
    'facturas'     => count($invoices),
    'total_compras'=> array_sum(array_map(fn($i) => $i['doc_type'] === 'compra' ? (float)$i['total'] : 0, $invoices)),
    'total_ventas' => array_sum(array_map(fn($i) => $i['doc_type'] === 'venta' ? (float)$i['total'] : 0, $invoices)),
];

$today = date('Y-m-d');
$todayDay = (int)date('j');
$isCurrentMonth = (date('Y-m') === sprintf('%04d-%02d', $year, $month));

$page_title = 'Mi calendario fiscal';
$page_subtitle = 'Visualiza tus vencimientos DGII y las facturas que has subido este mes.';
$main_max = 'max-w-6xl';
include 'components/layout_start.php';
?>

<!-- Header con navegacion + resumen -->
<div class="cc-header">
    <div class="cc-nav">
        <a href="?ym=<?= $prevYm ?>" class="cc-nav-btn" title="Mes anterior">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <div class="cc-period">
            <p class="cc-period-month"><?= htmlspecialchars($periodLabel) ?></p>
            <p class="cc-period-sub"><?= $daysInMonth ?> dias</p>
        </div>
        <a href="?ym=<?= $nextYm ?>" class="cc-nav-btn" title="Mes siguiente">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        </a>
        <?php if (!$isCurrentMonth): ?>
        <a href="?ym=<?= date('Y-m') ?>" class="cc-btn-soft">Hoy</a>
        <?php endif; ?>
    </div>

    <div class="cc-summary">
        <div class="cc-summary-item">
            <p class="cc-summary-label">Obligaciones</p>
            <p class="cc-summary-val"><?= $monthSummary['obligaciones'] ?></p>
            <?php if ($monthSummary['vencidas'] > 0): ?>
            <span class="cc-summary-tag cc-summary-tag-red"><?= $monthSummary['vencidas'] ?> vencidas</span>
            <?php elseif ($monthSummary['completadas'] > 0): ?>
            <span class="cc-summary-tag cc-summary-tag-green"><?= $monthSummary['completadas'] ?> OK</span>
            <?php endif; ?>
        </div>
        <div class="cc-summary-item">
            <p class="cc-summary-label">Facturas</p>
            <p class="cc-summary-val"><?= $monthSummary['facturas'] ?></p>
            <span class="cc-summary-tag cc-summary-tag-slate">subidas</span>
        </div>
        <div class="cc-summary-item">
            <p class="cc-summary-label">Compras</p>
            <p class="cc-summary-val">RD$ <?= number_format($monthSummary['total_compras'], 0) ?></p>
        </div>
        <div class="cc-summary-item">
            <p class="cc-summary-label">Ventas</p>
            <p class="cc-summary-val">RD$ <?= number_format($monthSummary['total_ventas'], 0) ?></p>
        </div>
    </div>
</div>

<!-- Leyenda -->
<div class="cc-legend">
    <span class="cc-legend-item"><span class="cc-dot cc-dot-red"></span> Vencimiento DGII vencido</span>
    <span class="cc-legend-item"><span class="cc-dot cc-dot-amber"></span> Vence en ≤7 dias</span>
    <span class="cc-legend-item"><span class="cc-dot cc-dot-blue"></span> Proximo vencimiento</span>
    <span class="cc-legend-item"><span class="cc-dot cc-dot-emerald"></span> Completado</span>
    <span class="cc-legend-item"><span class="cc-dot cc-dot-indigo"></span> Factura subida</span>
</div>

<!-- Calendario -->
<div class="cc-grid">
    <?php foreach (['Lun','Mar','Mie','Jue','Vie','Sab','Dom'] as $dayName): ?>
    <div class="cc-day-header"><?= $dayName ?></div>
    <?php endforeach; ?>

    <?php for ($i = 0; $i < $firstDowMon; $i++): ?>
    <div class="cc-day cc-day-empty"></div>
    <?php endfor; ?>

    <?php for ($d = 1; $d <= $daysInMonth; $d++):
        $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $isToday = $isCurrentMonth && $d === $todayDay;
        $isPast  = $dateStr < $today;
        $dayObs  = $byDay[$d]['obs'] ?? [];
        $dayInvs = $byDay[$d]['invs'] ?? [];
        $hasContent = !empty($dayObs) || !empty($dayInvs);

        // Indicador principal del dia
        $tone = 'none';
        if (!empty($dayObs)) {
            $statuses = array_column($dayObs, 'status');
            if (in_array('vencido', $statuses, true)) $tone = 'red';
            elseif (in_array('completado', $statuses, true)) $tone = 'emerald';
            else $tone = 'amber';
        } elseif (!empty($dayInvs)) {
            $tone = 'indigo';
        }
    ?>
    <div class="cc-day <?= $isToday ? 'cc-day-today' : '' ?> <?= $isPast ? 'cc-day-past' : '' ?> <?= $hasContent ? 'cc-day-has' : '' ?>">
        <div class="cc-day-num <?= $isToday ? 'cc-day-num-today' : '' ?>"><?= $d ?></div>

        <?php if (!empty($dayObs)): ?>
        <div class="cc-day-events">
            <?php foreach ($dayObs as $o):
                $obTone = $o['status'] === 'vencido' ? 'red' : ($o['status'] === 'completado' ? 'emerald' : 'amber');
            ?>
            <div class="cc-event cc-event-<?= $obTone ?>" title="<?= htmlspecialchars(getObligationLabel($o['obligation_type'])) ?> · <?= htmlspecialchars($o['period']) ?>">
                <?= htmlspecialchars($o['obligation_type']) ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($dayInvs)):
            $count = count($dayInvs);
            $totalDay = array_sum(array_map(fn($i) => (float)$i['total'], $dayInvs));
        ?>
        <div class="cc-day-invs" title="<?= $count ?> factura(s) · RD$ <?= number_format($totalDay, 2) ?>">
            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            <?= $count ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endfor; ?>

    <?php
    // Padding final hasta llenar la semana
    $totalCells = $firstDowMon + $daysInMonth;
    $remaining = (7 - ($totalCells % 7)) % 7;
    for ($i = 0; $i < $remaining; $i++): ?>
    <div class="cc-day cc-day-empty"></div>
    <?php endfor; ?>
</div>

<!-- Lista detallada del mes -->
<div class="cc-detail">
    <div class="cc-detail-section">
        <div class="cc-detail-head">
            <h3>Obligaciones DGII de <?= htmlspecialchars($periodLabel) ?></h3>
            <span class="cc-detail-count"><?= count($obligations) ?></span>
        </div>
        <?php if (empty($obligations)): ?>
        <p class="cc-empty">Sin obligaciones este mes.</p>
        <?php else: ?>
        <ul class="cc-list">
            <?php foreach ($obligations as $o):
                $days = (int)((strtotime($o['due_date']) - strtotime($today)) / 86400);
                $when = $days < 0 ? "vencido hace " . abs($days) . " d"
                      : ($days === 0 ? "HOY" : ($days <= 7 ? "en {$days} d" : "en {$days} d"));
                $tone = $o['status'] === 'vencido' ? 'red' : ($o['status'] === 'completado' ? 'emerald' : ($days <= 5 ? 'amber' : 'slate'));
            ?>
            <li class="cc-list-item">
                <div class="cc-list-icon cc-list-icon-<?= $tone ?>"><?= htmlspecialchars($o['obligation_type']) ?></div>
                <div class="cc-list-main">
                    <p class="cc-list-title"><?= htmlspecialchars(getObligationLabel($o['obligation_type'])) ?></p>
                    <p class="cc-list-sub">Periodo <?= htmlspecialchars($o['period']) ?> · vence <?= date('d/m/Y', strtotime($o['due_date'])) ?></p>
                </div>
                <span class="cc-list-tag cc-list-tag-<?= $tone ?>">
                    <?= $o['status'] === 'completado' ? 'Completada' : $when ?>
                </span>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>

    <div class="cc-detail-section">
        <div class="cc-detail-head">
            <h3>Facturas subidas en <?= htmlspecialchars($periodLabel) ?></h3>
            <span class="cc-detail-count"><?= count($invoices) ?></span>
        </div>
        <?php if (empty($invoices)): ?>
        <p class="cc-empty">Sin facturas este mes. <a href="client_uploads.php" class="text-blue-600 font-semibold">Sube la primera →</a></p>
        <?php else: ?>
        <ul class="cc-list">
            <?php foreach (array_slice($invoices, 0, 10) as $i):
                $tone = $i['doc_type'] === 'venta' ? 'emerald' : 'blue';
                $tag = $i['doc_type'] === 'venta' ? '607' : '606';
            ?>
            <li class="cc-list-item">
                <div class="cc-list-icon cc-list-icon-<?= $tone ?>"><?= $tag ?></div>
                <div class="cc-list-main">
                    <p class="cc-list-title"><?= htmlspecialchars($i['counterparty_name'] ?: 'Sin proveedor') ?></p>
                    <p class="cc-list-sub">
                        <?= $i['date_doc'] ? date('d/m/Y', strtotime($i['date_doc'])) : '—' ?>
                        <?php if ($i['ncf']): ?> · NCF <?= htmlspecialchars($i['ncf']) ?><?php endif; ?>
                        <?php if ($i['source'] === 'telegram'): ?> · <span class="text-sky-600">via Telegram</span><?php endif; ?>
                    </p>
                </div>
                <div class="text-right shrink-0">
                    <p class="cc-list-amount">RD$ <?= number_format((float)$i['total'], 2) ?></p>
                    <p class="cc-list-itbis">ITBIS <?= number_format((float)$i['itbis'], 2) ?></p>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php if (count($invoices) > 10): ?>
        <a href="client_uploads.php" class="cc-see-all">Ver todas las <?= count($invoices) ?> →</a>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<style>
.cc-header { display: flex; flex-direction: column; gap: 16px; background: #fff; border: 1px solid #EEF0F2; border-radius: 22px; padding: 18px 20px; margin-bottom: 16px; }
.cc-nav { display: flex; align-items: center; gap: 10px; }
.cc-nav-btn { width: 38px; height: 38px; border-radius: 12px; background: #F4F4F5; color: #475569; display: inline-flex; align-items: center; justify-content: center; transition: all .15s ease; }
.cc-nav-btn:hover { background: #E5E7EB; color: #0F172A; }
.cc-period { display: flex; flex-direction: column; min-width: 200px; }
.cc-period-month { font-size: 22px; font-weight: 800; color: #0F172A; line-height: 1.1; }
.cc-period-sub { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #94A3B8; margin-top: 2px; }
.cc-btn-soft { padding: 8px 14px; border-radius: 10px; background: #DBEAFE; color: #1D4ED8; font-size: 12px; font-weight: 700; }
.cc-btn-soft:hover { background: #BFDBFE; }

.cc-summary { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.cc-summary-item { padding: 10px 14px; border-radius: 14px; background: #F8FAFC; }
.cc-summary-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #94A3B8; }
.cc-summary-val { font-size: 18px; font-weight: 800; color: #0F172A; margin-top: 2px; font-variant-numeric: tabular-nums; }
.cc-summary-tag { display: inline-block; font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; padding: 2px 7px; border-radius: 999px; margin-top: 4px; }
.cc-summary-tag-red { background: #FEE2E2; color: #B91C1C; }
.cc-summary-tag-green { background: #DCFCE7; color: #047857; }
.cc-summary-tag-slate { background: #F1F5F9; color: #475569; }
@media (min-width: 768px) {
    .cc-header { flex-direction: row; align-items: center; justify-content: space-between; }
    .cc-summary { grid-template-columns: repeat(4, minmax(120px, 1fr)); flex: 1; max-width: 720px; }
}

.cc-legend { display: flex; flex-wrap: wrap; gap: 14px; padding: 12px 20px; background: #fff; border: 1px solid #EEF0F2; border-radius: 16px; margin-bottom: 12px; }
.cc-legend-item { display: inline-flex; align-items: center; gap: 6px; font-size: 11px; color: #475569; font-weight: 600; }
.cc-dot { width: 8px; height: 8px; border-radius: 999px; }
.cc-dot-red { background: #EF4444; }
.cc-dot-amber { background: #F59E0B; }
.cc-dot-blue { background: #3B82F6; }
.cc-dot-emerald { background: #10B981; }
.cc-dot-indigo { background: #6366F1; }

.cc-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; background: #fff; border: 1px solid #EEF0F2; border-radius: 22px; padding: 14px; margin-bottom: 16px; }
.cc-day-header { padding: 8px 0; text-align: center; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; color: #94A3B8; }
.cc-day { min-height: 92px; padding: 6px 8px; border-radius: 10px; background: #FAFAFA; transition: all .12s ease; position: relative; }
.cc-day:hover { background: #F4F4F5; transform: translateY(-1px); }
.cc-day-empty { background: transparent; pointer-events: none; }
.cc-day-empty:hover { transform: none; }
.cc-day-past { opacity: 0.7; }
.cc-day-today { background: #DBEAFE; box-shadow: inset 0 0 0 2px #2563EB; }
.cc-day-today:hover { background: #BFDBFE; }
.cc-day-has { background: #fff; border: 1px solid #E5E7EB; }
.cc-day-num { font-size: 12px; font-weight: 700; color: #475569; }
.cc-day-num-today { color: #1D4ED8; font-weight: 800; }
.cc-day-events { margin-top: 4px; display: flex; flex-direction: column; gap: 2px; }
.cc-event { font-size: 9px; font-weight: 700; padding: 2px 5px; border-radius: 5px; line-height: 1.2; text-align: center; }
.cc-event-red { background: #FEE2E2; color: #B91C1C; }
.cc-event-amber { background: #FEF3C7; color: #B45309; }
.cc-event-emerald { background: #DCFCE7; color: #047857; }
.cc-event-blue { background: #DBEAFE; color: #1E40AF; }
.cc-day-invs { position: absolute; bottom: 6px; right: 6px; display: inline-flex; align-items: center; gap: 2px; font-size: 9px; font-weight: 700; padding: 2px 6px; border-radius: 999px; background: #E0E7FF; color: #4F46E5; }

@media (max-width: 768px) {
    .cc-day { min-height: 70px; padding: 4px 5px; }
    .cc-event { font-size: 8px; padding: 1px 3px; }
    .cc-day-invs { font-size: 8px; padding: 1px 4px; }
}
@media (max-width: 480px) {
    .cc-day { min-height: 56px; }
    .cc-day-header { font-size: 9px; }
}

.cc-detail { display: grid; grid-template-columns: 1fr; gap: 12px; }
.cc-detail-section { background: #fff; border: 1px solid #EEF0F2; border-radius: 22px; overflow: hidden; }
.cc-detail-head { padding: 14px 18px; border-bottom: 1px solid #F4F4F5; display: flex; align-items: center; justify-content: space-between; }
.cc-detail-head h3 { font-size: 14px; font-weight: 800; color: #0F172A; }
.cc-detail-count { font-size: 11px; font-weight: 700; padding: 2px 9px; border-radius: 999px; background: #F1F5F9; color: #475569; }
.cc-empty { padding: 28px 18px; text-align: center; color: #94A3B8; font-size: 13px; }
.cc-list { display: flex; flex-direction: column; }
.cc-list-item { display: flex; align-items: center; gap: 12px; padding: 11px 18px; border-bottom: 1px solid #F8FAFC; }
.cc-list-item:last-child { border-bottom: 0; }
.cc-list-icon { width: 36px; height: 36px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 800; flex-shrink: 0; }
.cc-list-icon-red { background: #FEE2E2; color: #B91C1C; }
.cc-list-icon-amber { background: #FEF3C7; color: #B45309; }
.cc-list-icon-emerald { background: #DCFCE7; color: #047857; }
.cc-list-icon-slate { background: #F1F5F9; color: #475569; }
.cc-list-icon-blue { background: #DBEAFE; color: #1E40AF; }
.cc-list-main { flex: 1; min-width: 0; }
.cc-list-title { font-size: 13px; font-weight: 700; color: #0F172A; }
.cc-list-sub { font-size: 11px; color: #64748B; margin-top: 1px; }
.cc-list-tag { font-size: 10px; font-weight: 700; padding: 3px 9px; border-radius: 999px; }
.cc-list-tag-red { background: #FEE2E2; color: #B91C1C; }
.cc-list-tag-amber { background: #FEF3C7; color: #B45309; }
.cc-list-tag-emerald { background: #DCFCE7; color: #047857; }
.cc-list-tag-slate { background: #F1F5F9; color: #475569; }
.cc-list-amount { font-size: 13px; font-weight: 800; color: #0F172A; font-variant-numeric: tabular-nums; }
.cc-list-itbis { font-size: 10px; color: #64748B; margin-top: 1px; font-variant-numeric: tabular-nums; }
.cc-see-all { display: block; padding: 12px 18px; text-align: center; font-size: 12px; font-weight: 700; color: #2563EB; border-top: 1px solid #F4F4F5; }
.cc-see-all:hover { background: #F8FAFC; }
@media (min-width: 1024px) {
    .cc-detail { grid-template-columns: 1fr 1fr; }
}
</style>

<?php include 'components/layout_end.php'; ?>
