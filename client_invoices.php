<?php
require_once 'config.php';
requireAuth('client');

$client_id = (int)$_SESSION['user_id'];

$filter = $_GET['status'] ?? 'all';
$where = ['i.client_id = ?'];
$params = [$client_id];

if ($filter === 'pendiente' || $filter === 'pagado') {
    $where[] = 'i.status = ?';
    $params[] = $filter;
} elseif ($filter === 'vencido') {
    $where[] = "i.status='pendiente' AND i.due_date < CURDATE()";
}
$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT i.*, DATEDIFF(i.due_date, CURDATE()) as days_to_due
    FROM invoices i
    WHERE $whereSql
    ORDER BY (i.status='pendiente') DESC, i.due_date ASC
    LIMIT 200
");
$stmt->execute($params);
$invoices = $stmt->fetchAll();

// KPIs
$totalPending = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE status='pendiente' AND client_id=" . $client_id)->fetchColumn();
$totalPaid    = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE status='pagado' AND client_id=" . $client_id)->fetchColumn();
$totalOverdue = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE status='pendiente' AND due_date<CURDATE() AND client_id=" . $client_id)->fetchColumn();
$nextDue = $pdo->prepare("SELECT amount, due_date, concept FROM invoices WHERE client_id=? AND status='pendiente' AND due_date>=CURDATE() ORDER BY due_date ASC LIMIT 1");
$nextDue->execute([$client_id]);
$nextRow = $nextDue->fetch();

$countPending = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE status='pendiente' AND client_id=" . $client_id)->fetchColumn();
$countPaid = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE status='pagado' AND client_id=" . $client_id)->fetchColumn();
$countOverdue = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE status='pendiente' AND due_date<CURDATE() AND client_id=" . $client_id)->fetchColumn();

// Contacto asesoria para pagar
$companyName  = trim(getSetting('company_name', 'Portal Asesoria'));
$companyPhone = trim(getSetting('company_phone', ''));
$companyEmail = trim(getSetting('company_email', ''));

$page_title = 'Mis volantes de cobro';
$page_subtitle = 'Pagos pendientes y historial de tus servicios.';
include 'components/layout_start.php';
?>

<!-- KPIs -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
    <div class="ci-kpi <?= $countOverdue > 0 ? 'ci-kpi-red' : 'ci-kpi-amber' ?>">
        <p class="ci-kpi-label">Por pagar</p>
        <p class="ci-kpi-value">RD$ <?= number_format($totalPending, 0) ?></p>
        <p class="ci-kpi-foot"><?= $countPending ?> pendiente(s)</p>
    </div>
    <div class="ci-kpi ci-kpi-red <?= $countOverdue === 0 ? 'opacity-60' : '' ?>">
        <p class="ci-kpi-label">Vencidos</p>
        <p class="ci-kpi-value">RD$ <?= number_format($totalOverdue, 0) ?></p>
        <p class="ci-kpi-foot"><?= $countOverdue ?> volante(s)</p>
    </div>
    <div class="ci-kpi ci-kpi-emerald">
        <p class="ci-kpi-label">Pagado historico</p>
        <p class="ci-kpi-value">RD$ <?= number_format($totalPaid, 0) ?></p>
        <p class="ci-kpi-foot"><?= $countPaid ?> volante(s)</p>
    </div>
    <div class="ci-kpi ci-kpi-blue">
        <p class="ci-kpi-label">Proximo pago</p>
        <?php if ($nextRow): ?>
        <p class="ci-kpi-value">RD$ <?= number_format($nextRow['amount'], 0) ?></p>
        <p class="ci-kpi-foot"><?= date('d M', strtotime($nextRow['due_date'])) ?> · <?= htmlspecialchars(mb_substr($nextRow['concept'], 0, 22)) ?></p>
        <?php else: ?>
        <p class="ci-kpi-value">—</p>
        <p class="ci-kpi-foot">Sin pendientes</p>
        <?php endif; ?>
    </div>
</div>

<?php if ($countOverdue > 0): ?>
<div class="mb-4 ci-alert">
    <div class="ci-alert-icon"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 00-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg></div>
    <div class="flex-1">
        <p class="font-bold text-red-800">Tienes pagos vencidos</p>
        <p class="text-[12px] text-red-700 mt-0.5">RD$ <?= number_format($totalOverdue, 2) ?> en <?= $countOverdue ?> volante(s). Por favor regulariza para evitar suspensiones.</p>
    </div>
    <?php if ($companyPhone): ?>
    <a href="https://wa.me/<?= htmlspecialchars(preg_replace('/[^0-9]/','',$companyPhone)) ?>?text=<?= urlencode('Hola, quiero ponerme al dia con mis pagos vencidos.') ?>" target="_blank" class="ci-alert-btn">
        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51"/></svg>
        Contactar
    </a>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Filtros -->
<div class="surface-card p-2 mb-3 flex flex-wrap items-center gap-1">
    <?php
    $tabs = [
        'all'       => ['Todos',        $countPending + $countPaid],
        'pendiente' => ['Pendientes',   $countPending],
        'vencido'   => ['Vencidos',     $countOverdue],
        'pagado'    => ['Pagados',      $countPaid],
    ];
    foreach ($tabs as $key => [$label, $cnt]):
        $isActive = $filter === $key;
    ?>
    <a href="client_invoices.php?status=<?= $key ?>" class="ci-tab <?= $isActive ? 'is-active' : '' ?>">
        <?= htmlspecialchars($label) ?>
        <?php if ($cnt > 0): ?><span class="ci-tab-count"><?= $cnt ?></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Lista -->
<?php if (empty($invoices)): ?>
<div class="surface-card p-10 text-center">
    <svg class="w-12 h-12 mx-auto text-slate-200 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"/></svg>
    <p class="text-sm text-slate-500">No tienes volantes en esta seccion.</p>
</div>
<?php else: ?>
<div class="surface-card overflow-hidden">
    <ul class="divide-y divide-slate-100">
        <?php foreach ($invoices as $inv):
            $isPaid = $inv['status'] === 'pagado';
            $isOverdue = !$isPaid && (int)$inv['days_to_due'] < 0;
            $isDueSoon = !$isPaid && (int)$inv['days_to_due'] >= 0 && (int)$inv['days_to_due'] <= 5;
            $statusClass = $isPaid ? 'ci-status-paid' : ($isOverdue ? 'ci-status-overdue' : ($isDueSoon ? 'ci-status-soon' : 'ci-status-pending'));
            $statusLabel = $isPaid ? 'Pagado' : ($isOverdue ? 'Vencido' : ($isDueSoon ? 'Por vencer' : 'Pendiente'));
            $daysLabel = $isPaid ? '' : (
                $isOverdue ? abs((int)$inv['days_to_due']) . 'd vencido' :
                ((int)$inv['days_to_due'] === 0 ? 'Vence hoy' : ((int)$inv['days_to_due'] . 'd restantes'))
            );
        ?>
        <li class="ci-row <?= $isOverdue ? 'ci-row-overdue' : '' ?>">
            <div class="ci-row-icon">
                <?php if ($isPaid): ?>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                <?php else: ?>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"/></svg>
                <?php endif; ?>
            </div>

            <div class="ci-row-main">
                <p class="ci-row-concept"><?= htmlspecialchars($inv['concept']) ?></p>
                <p class="ci-row-meta">
                    <?php if ($isPaid): ?>
                    Pagado el <?= date('d M Y', strtotime($inv['paid_at'] ?: $inv['created_at'])) ?>
                    <?php else: ?>
                    Vence <?= date('d M Y', strtotime($inv['due_date'])) ?>
                    <?php if ($daysLabel): ?>
                    <span class="ci-days <?= $isOverdue ? 'ci-days-red' : ($isDueSoon ? 'ci-days-amber' : '') ?>"><?= $daysLabel ?></span>
                    <?php endif; ?>
                    <?php endif; ?>
                </p>
            </div>

            <div class="ci-row-amount">
                <p class="ci-amount">RD$ <?= number_format($inv['amount'], 2) ?></p>
                <span class="ci-status <?= $statusClass ?>"><?= $statusLabel ?></span>
            </div>

            <div class="ci-row-actions">
                <a href="invoice_pdf.php?id=<?= (int)$inv['id'] ?>" target="_blank" class="ci-icon-btn" title="Descargar PDF">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                </a>
                <?php if (!$isPaid && $companyPhone): ?>
                <a href="https://wa.me/<?= htmlspecialchars(preg_replace('/[^0-9]/','',$companyPhone)) ?>?text=<?= urlencode('Hola, quiero pagar el volante "' . $inv['concept'] . '" por RD$ ' . number_format($inv['amount'], 2)) ?>" target="_blank" class="ci-icon-btn ci-icon-wa" title="Avisar pago por WhatsApp">
                    <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51"/></svg>
                </a>
                <?php endif; ?>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- Como pagar -->
<?php if ($countPending > 0): ?>
<div class="surface-card mt-4 p-5">
    <h3 class="text-sm font-bold text-slate-900 mb-3">Como pagar</h3>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
        <div class="ci-pay-method">
            <div class="ci-pay-icon ci-pay-icon-green">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075"/></svg>
            </div>
            <p class="text-xs font-bold text-slate-900">WhatsApp</p>
            <p class="text-[11px] text-slate-500 mt-1">Avisanos al recibo y te confirmamos el pago.</p>
            <?php if ($companyPhone): ?>
            <a href="https://wa.me/<?= htmlspecialchars(preg_replace('/[^0-9]/','',$companyPhone)) ?>" target="_blank" class="text-[11px] text-emerald-700 font-semibold mt-2 inline-block">+<?= htmlspecialchars(preg_replace('/[^0-9]/','',$companyPhone)) ?> →</a>
            <?php endif; ?>
        </div>
        <div class="ci-pay-method">
            <div class="ci-pay-icon ci-pay-icon-blue">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l9 6 9-6M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            </div>
            <p class="text-xs font-bold text-slate-900">Email</p>
            <p class="text-[11px] text-slate-500 mt-1">Escribenos para coordinar el metodo de pago.</p>
            <?php if ($companyEmail): ?>
            <a href="mailto:<?= htmlspecialchars($companyEmail) ?>" class="text-[11px] text-blue-700 font-semibold mt-2 inline-block break-all"><?= htmlspecialchars($companyEmail) ?> →</a>
            <?php endif; ?>
        </div>
        <div class="ci-pay-method">
            <div class="ci-pay-icon ci-pay-icon-slate">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
            </div>
            <p class="text-xs font-bold text-slate-900">Chat del portal</p>
            <p class="text-[11px] text-slate-500 mt-1">Mensaje directo con tu asesor.</p>
            <a href="client_messages.php" class="text-[11px] text-slate-700 font-semibold mt-2 inline-block">Abrir chat →</a>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
    .ci-kpi { background: #fff; border: 1px solid #EEF0F2; border-radius: 18px; padding: 14px 14px 12px; }
    .ci-kpi-amber   { border-left: 3px solid #F59E0B; }
    .ci-kpi-red     { border-left: 3px solid #EF4444; }
    .ci-kpi-emerald { border-left: 3px solid #10B981; }
    .ci-kpi-blue    { border-left: 3px solid #2563EB; }
    .ci-kpi-label { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; color: #94A3B8; }
    .ci-kpi-value { font-size: 22px; font-weight: 800; color: #0F172A; letter-spacing: -0.02em; margin-top: 4px; font-variant-numeric: tabular-nums; }
    .ci-kpi-foot { font-size: 11px; color: #64748B; margin-top: 2px; }

    .ci-alert { display: flex; align-items: center; gap: 12px; padding: 14px 16px; background: linear-gradient(90deg, #FEF2F2, #FEE2E2); border: 1px solid #FCA5A5; border-radius: 18px; }
    .ci-alert-icon { width: 40px; height: 40px; border-radius: 12px; background: #fff; color: #DC2626; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .ci-alert-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; background: #DC2626; color: #fff; border-radius: 999px; font-size: 12px; font-weight: 700; flex-shrink: 0; transition: all .15s ease; }
    .ci-alert-btn:hover { background: #B91C1C; transform: translateY(-1px); }

    .ci-tab { display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border-radius: 10px; font-size: 12px; font-weight: 700; color: #475569; transition: all .15s ease; }
    .ci-tab:hover { background: #F4F4F5; color: #0F172A; }
    .ci-tab.is-active { background: #0F172A; color: #fff; }
    .ci-tab-count { font-size: 10px; padding: 1px 6px; border-radius: 999px; background: rgba(0,0,0,0.08); }
    .ci-tab.is-active .ci-tab-count { background: rgba(255,255,255,0.18); }

    .ci-row { display: flex; align-items: center; gap: 14px; padding: 14px 16px; transition: background .15s ease; position: relative; }
    .ci-row:hover { background: #FAFAFA; }
    .ci-row-overdue { background: linear-gradient(90deg, rgba(254, 226, 226, 0.4), transparent 40%); }
    .ci-row-overdue::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: #EF4444; }

    .ci-row-icon { width: 36px; height: 36px; border-radius: 12px; background: #F1F5F9; color: #475569; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }

    .ci-row-main { flex: 1; min-width: 0; }
    .ci-row-concept { font-size: 13.5px; font-weight: 700; color: #0F172A; line-height: 1.3; }
    .ci-row-meta { font-size: 11.5px; color: #64748B; margin-top: 3px; display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .ci-days { display: inline-block; padding: 1px 7px; border-radius: 999px; font-size: 10px; font-weight: 700; background: #F1F5F9; color: #475569; }
    .ci-days-red { background: #FEF2F2; color: #DC2626; }
    .ci-days-amber { background: #FFFBEB; color: #B45309; }

    .ci-row-amount { text-align: right; flex-shrink: 0; }
    .ci-amount { font-size: 15px; font-weight: 800; color: #0F172A; font-variant-numeric: tabular-nums; }
    .ci-status { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; margin-top: 4px; }
    .ci-status-paid     { background: #F0FDF4; color: #15803D; }
    .ci-status-pending  { background: #FFFBEB; color: #B45309; }
    .ci-status-overdue  { background: #FEF2F2; color: #DC2626; }
    .ci-status-soon     { background: #EFF6FF; color: #2563EB; }

    .ci-row-actions { display: inline-flex; align-items: center; gap: 4px; flex-shrink: 0; }
    .ci-icon-btn { width: 30px; height: 30px; border-radius: 10px; background: #F4F4F5; color: #64748B; display: inline-flex; align-items: center; justify-content: center; transition: all .12s ease; }
    .ci-icon-btn:hover { background: #E5E7EB; color: #0F172A; }
    .ci-icon-wa:hover { background: #DCFCE7; color: #15803D; }

    .ci-pay-method { padding: 14px; border-radius: 14px; background: #FAFAFA; border: 1px solid #EEF0F2; }
    .ci-pay-icon { width: 32px; height: 32px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 8px; }
    .ci-pay-icon-green { background: #DCFCE7; color: #15803D; }
    .ci-pay-icon-blue  { background: #DBEAFE; color: #2563EB; }
    .ci-pay-icon-slate { background: #F1F5F9; color: #475569; }

    @media (max-width: 640px) {
        .ci-row { flex-wrap: wrap; }
        .ci-row-amount { width: auto; }
    }
</style>

<?php include 'components/layout_end.php'; ?>
