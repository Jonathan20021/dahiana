<?php
require_once 'config.php';
requireAuth('admin');

$success = $error = null;

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'mark_completed') {
        $oid = (int)($_POST['obligation_id'] ?? 0);
        $cid = $pdo->prepare("SELECT client_id FROM tax_obligations WHERE id = ?");
        $cid->execute([$oid]);
        $clientId = $cid->fetchColumn();
        $pdo->prepare("UPDATE tax_obligations SET status='completado', completed_at=NOW() WHERE id=?")->execute([$oid]);
        if ($clientId) logClientActivity($clientId, 'tax', "Obligacion marcada como completada");
        $success = "Marcada como completada.";
    } elseif ($action === 'mark_pending') {
        $oid = (int)($_POST['obligation_id'] ?? 0);
        $pdo->prepare("UPDATE tax_obligations SET status='pendiente', completed_at=NULL WHERE id=?")->execute([$oid]);
        $success = "Marcada como pendiente.";
    } elseif ($action === 'delete') {
        $oid = (int)($_POST['obligation_id'] ?? 0);
        $pdo->prepare("DELETE FROM tax_obligations WHERE id=?")->execute([$oid]);
        $success = "Obligacion eliminada.";
    } elseif ($action === 'regenerate_all') {
        $clients = $pdo->query("
            SELECT u.id
            FROM users u
            LEFT JOIN roles r ON r.slug = u.role
            WHERE COALESCE(r.access_level, CASE WHEN u.role = 'admin' THEN 'admin' ELSE 'client' END) = 'client'
              AND (u.client_status IS NULL OR u.client_status != 'inactivo')
        ")->fetchAll(PDO::FETCH_COLUMN);
        $total = 0;
        foreach ($clients as $cid) {
            $total += generateObligationsForClient((int)$cid, 6);
        }
        $success = "Se generaron {$total} obligaciones nuevas para " . count($clients) . " cliente(s).";
    }
}

// Refresh vencidas
$pdo->exec("UPDATE tax_obligations SET status='vencido' WHERE status='pendiente' AND due_date < CURDATE()");

// Filters
$clientFilter = (int)($_GET['client_id'] ?? 0);
$typeFilter = $_GET['type'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$periodFilter = $_GET['period'] ?? '';
$range = $_GET['range'] ?? 'upcoming';

$where = "WHERE 1=1";
$params = [];

if ($clientFilter > 0) {
    $where .= " AND o.client_id = ?";
    $params[] = $clientFilter;
}
if ($typeFilter !== '' && array_key_exists($typeFilter, getObligationTypes())) {
    $where .= " AND o.obligation_type = ?";
    $params[] = $typeFilter;
}
if ($statusFilter !== '' && in_array($statusFilter, ['pendiente','vencido','completado','no_aplica'], true)) {
    $where .= " AND o.status = ?";
    $params[] = $statusFilter;
}
if ($periodFilter !== '') {
    $where .= " AND o.period = ?";
    $params[] = $periodFilter;
}

// Range
if ($range === 'overdue') {
    $where .= " AND o.status = 'vencido'";
} elseif ($range === 'week') {
    $where .= " AND o.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND o.status != 'completado'";
} elseif ($range === 'month') {
    $where .= " AND o.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND o.status != 'completado'";
} elseif ($range === 'upcoming') {
    $where .= " AND o.status IN ('pendiente','vencido')";
}

$stmt = $pdo->prepare("
    SELECT o.*, u.name AS client_name, u.business_name, u.tax_regime, u.phone AS client_phone
    FROM tax_obligations o
    JOIN users u ON u.id = o.client_id
    $where
    ORDER BY
        CASE o.status WHEN 'vencido' THEN 0 WHEN 'pendiente' THEN 1 ELSE 2 END,
        o.due_date ASC
    LIMIT 500
");
$stmt->execute($params);
$obligations = $stmt->fetchAll();

// Stats (global, ignoring filters)
$globalStats = $pdo->query("
    SELECT
        SUM(CASE WHEN status='vencido' THEN 1 ELSE 0 END) AS overdue,
        SUM(CASE WHEN status='pendiente' AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS week,
        SUM(CASE WHEN status='pendiente' AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS month,
        SUM(CASE WHEN status='completado' AND MONTH(completed_at) = MONTH(CURDATE()) AND YEAR(completed_at) = YEAR(CURDATE()) THEN 1 ELSE 0 END) AS done_this_month
    FROM tax_obligations
")->fetch();

// Clients list for filter
$clientsStmt = $pdo->query("
    SELECT u.id, u.name
    FROM users u
    LEFT JOIN roles r ON r.slug = u.role
    WHERE COALESCE(r.access_level, CASE WHEN u.role = 'admin' THEN 'admin' ELSE 'client' END) = 'client'
    ORDER BY u.name
");
$clientList = $clientsStmt->fetchAll();

$page_title = 'Calendario Fiscal DGII';
$page_subtitle = 'Obligaciones tributarias por cliente con vencimientos automaticos.';
$page_actions = '<form method="POST" class="inline" onsubmit="return confirm(\'Regenerar obligaciones para todos los clientes activos para los proximos 6 meses?\')">
    <input type="hidden" name="action" value="regenerate_all">
    <button type="submit" class="btn-soft text-sm">Sincronizar todos</button>
</form>';
include 'components/layout_start.php';
?>

<?php if ($success): ?>
<div class="mb-4 rounded-2xl bg-emerald-50 px-4 py-3 border border-emerald-100 text-sm font-medium text-emerald-800"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<!-- Stats -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
    <a href="?range=overdue" class="stat-card p-4 hover:border-red-200 transition-colors">
        <div class="flex items-center justify-between mb-2">
            <span class="w-8 h-8 rounded-xl bg-red-50 text-red-600 flex items-center justify-center">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </span>
            <span class="badge-dot badge-red">!</span>
        </div>
        <p class="text-xs text-slate-500">Vencidas</p>
        <p class="text-2xl font-extrabold text-red-700"><?= (int)$globalStats['overdue'] ?></p>
    </a>
    <a href="?range=week" class="stat-card p-4 hover:border-amber-200 transition-colors">
        <div class="flex items-center justify-between mb-2">
            <span class="w-8 h-8 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 19h12M6 19a2 2 0 01-2-2V7a2 2 0 012-2h12a2 2 0 012 2v10a2 2 0 01-2 2"/></svg>
            </span>
        </div>
        <p class="text-xs text-slate-500">Esta semana</p>
        <p class="text-2xl font-extrabold text-amber-700"><?= (int)$globalStats['week'] ?></p>
    </a>
    <a href="?range=month" class="stat-card p-4 hover:border-blue-200 transition-colors">
        <div class="flex items-center justify-between mb-2">
            <span class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            </span>
        </div>
        <p class="text-xs text-slate-500">Este mes</p>
        <p class="text-2xl font-extrabold text-blue-700"><?= (int)$globalStats['month'] ?></p>
    </a>
    <div class="stat-card p-4">
        <div class="flex items-center justify-between mb-2">
            <span class="w-8 h-8 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            </span>
        </div>
        <p class="text-xs text-slate-500">Completadas este mes</p>
        <p class="text-2xl font-extrabold text-emerald-700"><?= (int)$globalStats['done_this_month'] ?></p>
    </div>
</div>

<!-- Filters -->
<form method="GET" class="surface-card p-3 mb-4 flex flex-col lg:flex-row gap-2">
    <select name="range" onchange="this.form.submit()" class="field text-sm lg:w-44">
        <option value="upcoming" <?= $range === 'upcoming' ? 'selected' : '' ?>>Pendientes y vencidas</option>
        <option value="overdue" <?= $range === 'overdue' ? 'selected' : '' ?>>Solo vencidas</option>
        <option value="week" <?= $range === 'week' ? 'selected' : '' ?>>Vence en 7 dias</option>
        <option value="month" <?= $range === 'month' ? 'selected' : '' ?>>Vence en 30 dias</option>
        <option value="all" <?= $range === 'all' ? 'selected' : '' ?>>Todo</option>
    </select>
    <select name="type" onchange="this.form.submit()" class="field text-sm lg:w-44">
        <option value="">Todos los tipos</option>
        <?php foreach (getObligationTypes() as $tk => $tcfg): ?>
        <option value="<?= $tk ?>" <?= $typeFilter === $tk ? 'selected' : '' ?>><?= htmlspecialchars($tcfg['label']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="status" onchange="this.form.submit()" class="field text-sm lg:w-36">
        <option value="">Todos</option>
        <option value="pendiente" <?= $statusFilter === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
        <option value="vencido" <?= $statusFilter === 'vencido' ? 'selected' : '' ?>>Vencida</option>
        <option value="completado" <?= $statusFilter === 'completado' ? 'selected' : '' ?>>Completado</option>
    </select>
    <select name="client_id" onchange="this.form.submit()" class="field text-sm flex-1">
        <option value="0">Todos los clientes</option>
        <?php foreach ($clientList as $cl): ?>
        <option value="<?= $cl['id'] ?>" <?= $clientFilter === (int)$cl['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cl['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($clientFilter || $typeFilter || $statusFilter || $range !== 'upcoming'): ?>
    <a href="admin_tax_calendar.php" class="btn-soft text-sm">Limpiar</a>
    <?php endif; ?>
</form>

<!-- Obligations list -->
<div class="surface-card overflow-hidden">
    <div class="px-5 py-3 border-b border-stone-100 flex items-center justify-between">
        <h3 class="text-base font-bold text-slate-900">Obligaciones</h3>
        <span class="text-xs text-slate-400"><?= count($obligations) ?> registro(s)</span>
    </div>

    <?php if (empty($obligations)): ?>
    <div class="py-16 text-center">
        <div class="w-14 h-14 mx-auto rounded-full bg-stone-100 flex items-center justify-center mb-3">
            <svg class="w-6 h-6 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
        </div>
        <p class="text-sm text-slate-500">No hay obligaciones para los filtros seleccionados.</p>
        <a href="admin_tax_calendar.php" class="mt-3 inline-flex btn-soft !text-xs">Ver todo</a>
    </div>
    <?php else: ?>
    <ul class="divide-y divide-stone-100">
        <?php
        $currentDate = '';
        foreach ($obligations as $ob):
            $obDate = date('Y-m-d', strtotime($ob['due_date']));
            $isNewDate = $obDate !== $currentDate;
            $currentDate = $obDate;
            $cleanPhone = preg_replace('/[^0-9]/', '', $ob['client_phone'] ?? '');
        ?>
        <?php if ($isNewDate): ?>
        <li class="px-5 py-2 bg-stone-50/70 border-y border-stone-100">
            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">
                <?= date('d M Y', strtotime($obDate)) ?>
                <?php
                $daysOut = (int)((strtotime($obDate) - strtotime(date('Y-m-d'))) / 86400);
                if ($daysOut === 0) echo ' &middot; Hoy';
                elseif ($daysOut === 1) echo ' &middot; Manana';
                elseif ($daysOut > 0 && $daysOut <= 7) echo " &middot; En {$daysOut} dias";
                elseif ($daysOut < 0) echo ' &middot; Vencido hace ' . abs($daysOut) . ' dias';
                ?>
            </p>
        </li>
        <?php endif; ?>
        <li class="table-row px-5 py-3">
            <div class="flex flex-col lg:flex-row lg:items-center gap-3">
                <!-- Type icon -->
                <div class="w-10 h-10 rounded-xl bg-stone-50 flex items-center justify-center text-[10px] font-extrabold text-slate-600 shrink-0">
                    <?= htmlspecialchars(str_replace(['IT-','IR-','ANTICIPO'], ['IT', 'IR', 'AN'], $ob['obligation_type'])) ?>
                </div>
                <!-- Title -->
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap mb-0.5">
                        <p class="text-sm font-bold text-slate-900"><?= htmlspecialchars(getObligationLabel($ob['obligation_type'])) ?></p>
                        <span class="badge-dot badge-slate"><?= htmlspecialchars(formatPeriod($ob['period'])) ?></span>
                    </div>
                    <a href="client_details.php?id=<?= $ob['client_id'] ?>" class="text-xs text-slate-500 hover:text-blue-600 truncate block">
                        <?= htmlspecialchars($ob['client_name']) ?>
                        <?php if ($ob['business_name']): ?>&middot; <?= htmlspecialchars($ob['business_name']) ?><?php endif; ?>
                    </a>
                </div>
                <!-- Status -->
                <div class="lg:w-36 shrink-0">
                    <?= getObligationStatusBadge($ob['status'], $ob['due_date']) ?>
                </div>
                <!-- Actions -->
                <div class="flex items-center gap-1 shrink-0">
                    <?php if ($ob['status'] !== 'completado'): ?>
                    <form method="POST" class="inline">
                        <input type="hidden" name="action" value="mark_completed">
                        <input type="hidden" name="obligation_id" value="<?= $ob['id'] ?>">
                        <button type="submit" class="btn-dark !text-xs !py-1.5 !px-3">
                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            Completar
                        </button>
                    </form>
                    <?php else: ?>
                    <form method="POST" class="inline">
                        <input type="hidden" name="action" value="mark_pending">
                        <input type="hidden" name="obligation_id" value="<?= $ob['id'] ?>">
                        <button type="submit" class="btn-soft !text-xs !py-1.5 !px-3">Reabrir</button>
                    </form>
                    <?php endif; ?>
                    <a href="client_details.php?id=<?= $ob['client_id'] ?>" class="icon-btn !w-8 !h-8 hover:!bg-blue-100 hover:!text-blue-700" title="Abrir cliente">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                    </a>
                    <?php if ($cleanPhone && in_array($ob['status'], ['pendiente','vencido'], true)):
                        $reminderMsg = "Hola {$ob['client_name']}, " . getSetting('whatsapp_greeting', 'te escribimos de tu Asesoria Financiera') . ". Te recordamos que la obligacion " . getObligationLabel($ob['obligation_type']) . " de " . formatPeriod($ob['period']) . " vence el " . date('d/m/Y', strtotime($ob['due_date'])) . ".";
                    ?>
                    <a href="https://wa.me/<?= $cleanPhone ?>?text=<?= urlencode($reminderMsg) ?>" target="_blank" class="icon-btn !w-8 !h-8 hover:!bg-emerald-100 hover:!text-emerald-700" title="Recordar por WhatsApp">
                        <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207"/></svg>
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<?php include 'components/layout_end.php'; ?>
