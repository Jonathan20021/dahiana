<?php
require_once 'config.php';
requireAuth('admin');
requirePagePermission();

$success = $error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_invoice') {
        $client_id = $_POST['client_id'];
        $amount = $_POST['amount'];
        $concept = trim($_POST['concept'] ?? '');
        $due_date = $_POST['due_date'];
        if ($client_id && $amount && $concept && $due_date) {
            $stmt = $pdo->prepare("INSERT INTO invoices (client_id, amount, concept, due_date, status) VALUES (?, ?, ?, ?, 'pendiente')");
            $stmt->execute([$client_id, $amount, $concept, $due_date]);
            $newInvId = $pdo->lastInsertId();
            $emailNote = '';
            if (getSetting('notify_invoice', '1') === '1') {
                $r = sendInvoiceCreatedEmail($newInvId);
                if (!empty($r['ok'])) $emailNote = ' Notificacion enviada al cliente.';
            }
            logClientActivity($client_id, 'invoice', "Volante creado: {$concept}");
            $success = "Volante de cobro creado exitosamente.{$emailNote}";
        } else {
            $error = "Faltan campos obligatorios.";
        }
    } elseif ($action === 'mark_paid') {
        $invoice_id = $_POST['invoice_id'];
        $pdo->prepare("UPDATE invoices SET status = 'pagado', paid_at = NOW() WHERE id = ?")->execute([$invoice_id]);
        $emailNote = '';
        if (getSetting('notify_invoice_paid', '1') === '1') {
            $r = sendInvoicePaidEmail($invoice_id);
            if (!empty($r['ok'])) $emailNote = ' Confirmacion enviada al cliente.';
        }
        $success = "Marcado como pagado.{$emailNote}";
    } elseif ($action === 'mark_pending') {
        $invoice_id = $_POST['invoice_id'];
        $pdo->prepare("UPDATE invoices SET status = 'pendiente', paid_at = NULL WHERE id = ?")->execute([$invoice_id]);
        $success = "Marcado como pendiente.";
    } elseif ($action === 'delete_invoice') {
        $invoice_id = $_POST['invoice_id'];
        $pdo->prepare("DELETE FROM invoices WHERE id = ?")->execute([$invoice_id]);
        $success = "Volante eliminado.";
    } elseif ($action === 'bulk_paid') {
        $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("UPDATE invoices SET status='pagado', paid_at=NOW() WHERE id IN ($in) AND status='pendiente'")->execute($ids);
            $success = count($ids) . " volante(s) marcados como pagados.";
        }
    }
}

// Filtros
$filterStatus = $_GET['status'] ?? 'all';
$filterClient = (int)($_GET['client'] ?? 0);
$filterRange  = $_GET['range'] ?? 'all';
$search       = trim($_GET['q'] ?? '');

$where = ['1=1', clientScopeWhere('i.client_id')];
$params = [];
if (in_array($filterStatus, ['pendiente','pagado','vencido'], true)) {
    if ($filterStatus === 'vencido') {
        $where[] = "i.status='pendiente' AND i.due_date < CURDATE()";
    } else {
        $where[] = 'i.status = ?';
        $params[] = $filterStatus;
    }
}
if ($filterClient > 0) { $where[] = 'i.client_id = ?'; $params[] = $filterClient; }
if ($filterRange === 'month') { $where[] = "i.due_date >= DATE_FORMAT(CURDATE(),'%Y-%m-01')"; }
elseif ($filterRange === 'next30') { $where[] = "i.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"; }
elseif ($filterRange === 'overdue') { $where[] = "i.status='pendiente' AND i.due_date < CURDATE()"; }
if ($search !== '') {
    $where[] = '(u.name LIKE ? OR i.concept LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
$whereSql = implode(' AND ', $where);

// KPIs (respetando scope de clientes del usuario actual)
$scopeKpi = clientScopeWhere('client_id');
$totalPending = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE status='pendiente' AND {$scopeKpi}")->fetchColumn();
$totalPaid    = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE status='pagado' AND {$scopeKpi}")->fetchColumn();
$totalOverdue = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE status='pendiente' AND due_date < CURDATE() AND {$scopeKpi}")->fetchColumn();
$countPending = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE status='pendiente' AND {$scopeKpi}")->fetchColumn();
$countPaid    = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE status='pagado' AND {$scopeKpi}")->fetchColumn();
$countOverdue = (int)$pdo->query("SELECT COUNT(*) FROM invoices WHERE status='pendiente' AND due_date < CURDATE() AND {$scopeKpi}")->fetchColumn();
$paidThisMonth = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE status='pagado' AND paid_at >= DATE_FORMAT(CURDATE(),'%Y-%m-01') AND {$scopeKpi}")->fetchColumn();

// Tendencia ultimos 6 meses
$trend = $pdo->query("
    SELECT DATE_FORMAT(COALESCE(paid_at, due_date), '%Y-%m') as ym,
           SUM(CASE WHEN status='pagado' THEN amount ELSE 0 END) as paid,
           SUM(CASE WHEN status='pendiente' THEN amount ELSE 0 END) as pending
    FROM invoices
    WHERE COALESCE(paid_at, due_date) >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY ym ORDER BY ym ASC
")->fetchAll();

$stmt = $pdo->prepare("
    SELECT i.*, u.name as client_name, u.phone as client_phone, u.email as client_email,
           u.iguala_amount, u.iguala_frequency,
           DATEDIFF(i.due_date, CURDATE()) as days_to_due
    FROM invoices i
    JOIN users u ON i.client_id = u.id
    WHERE $whereSql
    ORDER BY (i.status='pendiente') DESC, i.due_date ASC
    LIMIT 500
");
$stmt->execute($params);
$invoices = $stmt->fetchAll();

$clients = $pdo->query("
    SELECT u.id, u.name, u.iguala_amount, u.iguala_frequency
    FROM users u
    LEFT JOIN roles r ON r.slug = u.role
    WHERE COALESCE(r.access_level, CASE WHEN u.role = 'admin' THEN 'admin' ELSE 'client' END) = 'client'
    ORDER BY u.name
")->fetchAll();

function getInvoiceWhatsApp($phone, $clientName, $concept, $amount, $dueDate) {
    if (!$phone) return "#";
    $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
    $formattedAmount = number_format($amount, 2, '.', ',');
    $greeting = getSetting('whatsapp_greeting', 'te escribimos de tu Asesoria Financiera');
    $message = "Hola $clientName, $greeting. Tienes un volante de cobro pendiente:\n\n* $concept\n* Monto: RD\$ $formattedAmount\n* Fecha limite: $dueDate\n\nQuedamos a tu disposicion.";
    return "https://wa.me/" . $cleanPhone . "?text=" . urlencode($message);
}

$page_title = 'Finanzas';
$page_subtitle = 'Volantes de cobro, vencimientos y conciliacion mensual.';
$page_actions = '<button type="button" onclick="document.getElementById(\'createInvoiceModal\').classList.remove(\'hidden\')" class="btn-dark text-sm">
    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
    Nuevo volante
</button>';
include 'components/layout_start.php';
?>

<?php if ($success): ?>
<div class="mb-4 rounded-2xl bg-emerald-50 px-4 py-3 border border-emerald-100 text-sm font-medium text-emerald-800 flex items-center gap-2">
    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
    <?= htmlspecialchars($success) ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="mb-4 rounded-2xl bg-red-50 px-4 py-3 border border-red-100 text-sm font-medium text-red-800"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- KPIs -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
    <div class="fn-kpi fn-kpi-amber">
        <div class="fn-kpi-icon"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></div>
        <p class="fn-kpi-label">Por cobrar</p>
        <p class="fn-kpi-value">RD$ <?= number_format($totalPending, 0) ?></p>
        <p class="fn-kpi-foot"><?= $countPending ?> volante(s)</p>
    </div>
    <div class="fn-kpi fn-kpi-red">
        <div class="fn-kpi-icon"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 00-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg></div>
        <p class="fn-kpi-label">Vencidos</p>
        <p class="fn-kpi-value">RD$ <?= number_format($totalOverdue, 0) ?></p>
        <p class="fn-kpi-foot"><?= $countOverdue ?> volante(s)</p>
    </div>
    <div class="fn-kpi fn-kpi-green">
        <div class="fn-kpi-icon"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg></div>
        <p class="fn-kpi-label">Cobrado total</p>
        <p class="fn-kpi-value">RD$ <?= number_format($totalPaid, 0) ?></p>
        <p class="fn-kpi-foot"><?= $countPaid ?> volante(s)</p>
    </div>
    <div class="fn-kpi fn-kpi-blue">
        <div class="fn-kpi-icon"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0M6 7l3 9M6 7l6-2m6 2l3-1m-3 1l-3 9a5.002 5.002 0 006.001 0M18 7l3 9m-3-9l-6-2m0-2v2m0 16V5m0 16H9m3 0h3"/></svg></div>
        <p class="fn-kpi-label">Este mes</p>
        <p class="fn-kpi-value">RD$ <?= number_format($paidThisMonth, 0) ?></p>
        <p class="fn-kpi-foot">Cobrado en <?= date('M Y') ?></p>
    </div>
</div>

<!-- Tendencia + filtros -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-3 mb-4">
    <div class="surface-card p-5 lg:col-span-2">
        <div class="flex items-center justify-between mb-3">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-slate-400">Tendencia</p>
                <p class="text-sm font-semibold text-slate-900">Pagado vs Pendiente (ultimos 6 meses)</p>
            </div>
            <div class="flex items-center gap-3 text-[11px]">
                <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-emerald-500"></span> Pagado</span>
                <span class="inline-flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-amber-500"></span> Pendiente</span>
            </div>
        </div>
        <div class="h-40 relative">
            <canvas id="trendChart"></canvas>
        </div>
    </div>
    <div class="surface-card p-5">
        <p class="text-xs font-bold uppercase tracking-wider text-slate-400 mb-3">Distribucion</p>
        <div class="h-32 relative">
            <canvas id="distChart"></canvas>
        </div>
        <div class="grid grid-cols-2 gap-2 mt-3 text-xs">
            <div><span class="text-slate-500">Tasa de cobro</span><br><span class="font-bold text-slate-900"><?= ($totalPaid + $totalPending > 0) ? number_format($totalPaid / ($totalPaid + $totalPending) * 100, 1) : 0 ?>%</span></div>
            <div><span class="text-slate-500">Ticket promedio</span><br><span class="font-bold text-slate-900">RD$ <?= ($countPaid + $countPending > 0) ? number_format(($totalPaid + $totalPending) / ($countPaid + $countPending), 0) : 0 ?></span></div>
        </div>
    </div>
</div>

<!-- Filtros -->
<div class="surface-card p-3 mb-3">
    <form method="GET" class="flex flex-wrap items-center gap-2">
        <div class="relative flex-1 min-w-[200px]">
            <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Buscar por cliente o concepto..." class="w-full pl-9 pr-3 py-2 text-sm rounded-xl border border-slate-200 focus:outline-none focus:border-slate-900 focus:ring-4 focus:ring-slate-900/5">
        </div>
        <select name="status" class="fn-select" onchange="this.form.submit()">
            <option value="all" <?= $filterStatus === 'all' ? 'selected' : '' ?>>Todos</option>
            <option value="pendiente" <?= $filterStatus === 'pendiente' ? 'selected' : '' ?>>Pendientes</option>
            <option value="vencido" <?= $filterStatus === 'vencido' ? 'selected' : '' ?>>Vencidos</option>
            <option value="pagado" <?= $filterStatus === 'pagado' ? 'selected' : '' ?>>Pagados</option>
        </select>
        <select name="client" class="fn-select" onchange="this.form.submit()">
            <option value="0">Todos los clientes</option>
            <?php foreach ($clients as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $filterClient === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="range" class="fn-select" onchange="this.form.submit()">
            <option value="all" <?= $filterRange === 'all' ? 'selected' : '' ?>>Cualquier fecha</option>
            <option value="month" <?= $filterRange === 'month' ? 'selected' : '' ?>>Este mes</option>
            <option value="next30" <?= $filterRange === 'next30' ? 'selected' : '' ?>>Proximos 30 dias</option>
            <option value="overdue" <?= $filterRange === 'overdue' ? 'selected' : '' ?>>Vencidos</option>
        </select>
        <?php if ($filterStatus !== 'all' || $filterClient || $filterRange !== 'all' || $search !== ''): ?>
        <a href="admin_finances.php" class="btn-soft text-sm">Limpiar</a>
        <?php endif; ?>
    </form>
</div>

<!-- Lista -->
<form method="POST" id="bulkForm">
    <input type="hidden" name="action" value="bulk_paid">
    <div class="surface-card overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-100 flex items-center justify-between flex-wrap gap-2">
            <div class="flex items-center gap-3">
                <label class="inline-flex items-center gap-2 text-xs text-slate-500 cursor-pointer">
                    <input type="checkbox" id="selectAll" class="fn-checkbox">
                    Seleccionar
                </label>
                <span class="text-xs text-slate-400" id="selectedCount"></span>
            </div>
            <div class="flex items-center gap-2">
                <button type="submit" id="bulkPaidBtn" class="hidden btn-dark text-xs py-1.5 px-3">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    Marcar pagados
                </button>
                <span class="text-xs text-slate-400"><?= count($invoices) ?> resultado(s)</span>
            </div>
        </div>

        <?php if (empty($invoices)): ?>
        <div class="py-16 text-center">
            <svg class="w-12 h-12 mx-auto text-slate-200 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            <p class="text-sm text-slate-500">No hay volantes con esos criterios.</p>
        </div>
        <?php else: ?>
        <ul class="divide-y divide-slate-100">
            <?php foreach ($invoices as $inv):
                $isPaid = $inv['status'] === 'pagado';
                $isOverdue = !$isPaid && (int)$inv['days_to_due'] < 0;
                $isDueSoon = !$isPaid && (int)$inv['days_to_due'] >= 0 && (int)$inv['days_to_due'] <= 5;
                $statusClass = $isPaid ? 'fn-status-paid' : ($isOverdue ? 'fn-status-overdue' : ($isDueSoon ? 'fn-status-soon' : 'fn-status-pending'));
                $statusLabel = $isPaid ? 'Pagado' : ($isOverdue ? 'Vencido' : ($isDueSoon ? 'Por vencer' : 'Pendiente'));
                $daysLabel = $isPaid ? '' : (
                    $isOverdue ? abs((int)$inv['days_to_due']) . 'd vencido' :
                    ((int)$inv['days_to_due'] === 0 ? 'Hoy' : ((int)$inv['days_to_due'] . 'd restantes'))
                );
            ?>
            <li class="fn-row <?= $isOverdue ? 'fn-row-overdue' : '' ?>">
                <label class="fn-row-check">
                    <?php if (!$isPaid): ?>
                    <input type="checkbox" name="ids[]" value="<?= (int)$inv['id'] ?>" class="fn-checkbox fn-row-cb">
                    <?php endif; ?>
                </label>

                <div class="fn-avatar">
                    <?= htmlspecialchars(strtoupper(substr($inv['client_name'], 0, 2))) ?>
                </div>

                <div class="fn-main">
                    <p class="fn-client"><?= htmlspecialchars($inv['client_name']) ?></p>
                    <p class="fn-concept"><?= htmlspecialchars($inv['concept']) ?></p>
                </div>

                <div class="fn-meta">
                    <p class="fn-amount">RD$ <?= number_format($inv['amount'], 2) ?></p>
                    <p class="fn-due">
                        <?= date('d M Y', strtotime($inv['due_date'])) ?>
                        <?php if ($daysLabel): ?>
                        <span class="fn-days <?= $isOverdue ? 'fn-days-red' : ($isDueSoon ? 'fn-days-amber' : '') ?>"><?= $daysLabel ?></span>
                        <?php endif; ?>
                    </p>
                </div>

                <span class="fn-status <?= $statusClass ?>"><?= $statusLabel ?></span>

                <div class="fn-actions">
                    <a href="invoice_pdf.php?id=<?= $inv['id'] ?>" target="_blank" class="fn-icon-btn" title="Descargar PDF">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    </a>
                    <?php if ($inv['client_phone']): ?>
                    <a href="<?= getInvoiceWhatsApp($inv['client_phone'], $inv['client_name'], $inv['concept'], $inv['amount'], date('d/m/Y', strtotime($inv['due_date']))) ?>" target="_blank" class="fn-icon-btn fn-icon-wa" title="WhatsApp">
                        <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347"/></svg>
                    </a>
                    <?php endif; ?>
                    <div class="fn-menu-wrap">
                        <button type="button" class="fn-icon-btn fn-menu-trigger" data-fn-menu="fn-menu-<?= (int)$inv['id'] ?>">
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"/></svg>
                        </button>
                        <div id="fn-menu-<?= (int)$inv['id'] ?>" class="fn-menu hidden">
                            <?php if (!$isPaid): ?>
                            <button type="submit" form="markPaid-<?= (int)$inv['id'] ?>" class="fn-menu-item">
                                <svg class="w-3.5 h-3.5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                Marcar pagado
                            </button>
                            <?php else: ?>
                            <button type="submit" form="markPending-<?= (int)$inv['id'] ?>" class="fn-menu-item">
                                <svg class="w-3.5 h-3.5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg>
                                Revertir a pendiente
                            </button>
                            <?php endif; ?>
                            <a href="client_details.php?id=<?= (int)$inv['client_id'] ?>" class="fn-menu-item">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                Ver cliente
                            </a>
                            <button type="submit" form="deleteInv-<?= (int)$inv['id'] ?>" class="fn-menu-item fn-menu-item-danger" onclick="return confirm('Eliminar este volante? No se puede deshacer.')">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
                                Eliminar
                            </button>
                        </div>
                    </div>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</form>

<!-- Forms ocultos (uno por volante) -->
<?php foreach ($invoices as $inv): ?>
<form method="POST" id="markPaid-<?= (int)$inv['id'] ?>" class="hidden">
    <input type="hidden" name="action" value="mark_paid">
    <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
</form>
<form method="POST" id="markPending-<?= (int)$inv['id'] ?>" class="hidden">
    <input type="hidden" name="action" value="mark_pending">
    <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
</form>
<form method="POST" id="deleteInv-<?= (int)$inv['id'] ?>" class="hidden">
    <input type="hidden" name="action" value="delete_invoice">
    <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
</form>
<?php endforeach; ?>

<!-- Modal crear -->
<div id="createInvoiceModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 modal-backdrop" onclick="document.getElementById('createInvoiceModal').classList.add('hidden')"></div>
    <div class="relative flex min-h-full items-center justify-center p-4">
        <div class="w-full max-w-md rounded-3xl bg-white shadow-2xl">
            <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-bold text-slate-900">Nuevo volante</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Se enviara una notificacion al cliente.</p>
                </div>
                <button type="button" onclick="document.getElementById('createInvoiceModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-700 text-2xl leading-none">&times;</button>
            </div>
            <form action="admin_finances.php" method="POST" class="p-6 space-y-4">
                <input type="hidden" name="action" value="create_invoice">
                <div>
                    <label class="field-label">Cliente</label>
                    <select name="client_id" id="invoiceClientSelect" required onchange="prefillAmount()" class="field">
                        <option value="">Selecciona un cliente...</option>
                        <?php foreach ($clients as $c): ?>
                        <option value="<?= $c['id'] ?>" data-amount="<?= $c['iguala_amount'] ?>" data-freq="<?= htmlspecialchars($c['iguala_frequency']) ?>" data-name="<?= htmlspecialchars($c['name']) ?>">
                            <?= htmlspecialchars($c['name']) ?> (<?= $c['iguala_frequency'] ?> · RD$ <?= number_format($c['iguala_amount'], 2) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="field-label">Concepto</label>
                    <input type="text" name="concept" id="invoiceConcept" required placeholder="Ej: Iguala Mensual - <?= date('M Y') ?>" class="field">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="field-label">Monto (RD$)</label>
                        <input type="number" step="0.01" name="amount" id="invoiceAmount" required class="field">
                    </div>
                    <div>
                        <label class="field-label">Fecha limite</label>
                        <input type="date" name="due_date" required value="<?= date('Y-m-d', strtotime('last day of this month')) ?>" class="field">
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('createInvoiceModal').classList.add('hidden')" class="btn-soft text-sm">Cancelar</button>
                    <button type="submit" class="btn-dark text-sm">Crear volante</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .fn-kpi { background: #fff; border: 1px solid #EEF0F2; border-radius: 18px; padding: 14px 14px 12px; position: relative; }
    .fn-kpi-icon { position: absolute; top: 12px; right: 12px; width: 28px; height: 28px; border-radius: 9px; display: inline-flex; align-items: center; justify-content: center; }
    .fn-kpi-amber .fn-kpi-icon { background: #FFFBEB; color: #B45309; }
    .fn-kpi-red   .fn-kpi-icon { background: #FEF2F2; color: #DC2626; }
    .fn-kpi-green .fn-kpi-icon { background: #F0FDF4; color: #15803D; }
    .fn-kpi-blue  .fn-kpi-icon { background: #EFF6FF; color: #2563EB; }
    .fn-kpi-label { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; color: #94A3B8; }
    .fn-kpi-value { font-size: 22px; font-weight: 800; color: #0F172A; letter-spacing: -0.02em; margin-top: 2px; font-variant-numeric: tabular-nums; }
    .fn-kpi-foot  { font-size: 11px; color: #64748B; margin-top: 2px; }

    .fn-select { background: #fff; border: 1px solid #E5E7EB; border-radius: 12px; padding: 8px 12px; font-size: 13px; color: #0F172A; font-weight: 500; cursor: pointer; transition: border-color .15s ease; }
    .fn-select:hover { border-color: #94A3B8; }
    .fn-select:focus { outline: none; border-color: #0F172A; box-shadow: 0 0 0 4px rgba(15,23,42,0.06); }

    .fn-checkbox { width: 16px; height: 16px; border-radius: 4px; accent-color: #0F172A; cursor: pointer; }

    .fn-row { display: flex; align-items: center; gap: 12px; padding: 12px 16px; transition: background .15s ease; position: relative; }
    .fn-row:hover { background: #FAFAFA; }
    .fn-row-overdue { background: linear-gradient(90deg, rgba(254, 226, 226, 0.4), transparent 30%); }
    .fn-row-overdue::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px; background: #EF4444; }

    .fn-row-check { width: 22px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }

    .fn-avatar { width: 36px; height: 36px; border-radius: 12px; background: linear-gradient(135deg, #0F172A, #1E293B); color: #fff; display: inline-flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 800; flex-shrink: 0; }

    .fn-main { flex: 1; min-width: 0; }
    .fn-client { font-size: 13px; font-weight: 700; color: #0F172A; line-height: 1.2; }
    .fn-concept { font-size: 11.5px; color: #64748B; margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    .fn-meta { display: flex; flex-direction: column; align-items: flex-end; flex-shrink: 0; min-width: 140px; }
    .fn-amount { font-size: 14px; font-weight: 800; color: #0F172A; font-variant-numeric: tabular-nums; }
    .fn-due { font-size: 11px; color: #64748B; margin-top: 2px; display: inline-flex; align-items: center; gap: 6px; }
    .fn-days { display: inline-block; padding: 1px 6px; border-radius: 999px; font-size: 10px; font-weight: 700; background: #F1F5F9; color: #475569; }
    .fn-days-red { background: #FEF2F2; color: #DC2626; }
    .fn-days-amber { background: #FFFBEB; color: #B45309; }

    .fn-status { display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 999px; font-size: 10.5px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; white-space: nowrap; flex-shrink: 0; }
    .fn-status-paid     { background: #F0FDF4; color: #15803D; }
    .fn-status-pending  { background: #FFFBEB; color: #B45309; }
    .fn-status-overdue  { background: #FEF2F2; color: #DC2626; }
    .fn-status-soon     { background: #EFF6FF; color: #2563EB; }

    .fn-actions { display: inline-flex; align-items: center; gap: 4px; flex-shrink: 0; }
    .fn-icon-btn { width: 30px; height: 30px; border-radius: 10px; background: #F4F4F5; color: #64748B; display: inline-flex; align-items: center; justify-content: center; transition: all .12s ease; cursor: pointer; border: 0; }
    .fn-icon-btn:hover { background: #E5E7EB; color: #0F172A; }
    .fn-icon-wa:hover { background: #DCFCE7; color: #15803D; }

    .fn-menu-wrap { position: relative; }
    .fn-menu { position: fixed; min-width: 200px; background: #fff; border: 1px solid #E5E7EB; border-radius: 14px; box-shadow: 0 18px 50px rgba(15,23,42,0.18); z-index: 9000; padding: 6px; }
    .fn-menu-item { display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 10px; border-radius: 10px; font-size: 12px; font-weight: 600; color: #475569; text-align: left; transition: all .12s ease; background: transparent; border: 0; cursor: pointer; }
    .fn-menu-item:hover { background: #F4F4F5; color: #0F172A; }
    .fn-menu-item-danger { color: #DC2626; }
    .fn-menu-item-danger:hover { background: #FEF2F2; color: #B91C1C; }

    @media (max-width: 768px) {
        .fn-row { flex-wrap: wrap; }
        .fn-meta { width: 100%; align-items: flex-start; margin-top: 6px; }
        .fn-status { order: -1; }
    }
</style>

<script>
const trendCtx = document.getElementById('trendChart').getContext('2d');
const trend = <?= json_encode($trend) ?>;
const labels = trend.map(t => {
    const [y, m] = t.ym.split('-');
    const months = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    return months[parseInt(m, 10) - 1];
});
new Chart(trendCtx, {
    type: 'bar',
    data: {
        labels: labels,
        datasets: [
            { label: 'Pagado',    data: trend.map(t => parseFloat(t.paid)),    backgroundColor: '#10B981', borderRadius: 6, borderSkipped: false },
            { label: 'Pendiente', data: trend.map(t => parseFloat(t.pending)), backgroundColor: '#F59E0B', borderRadius: 6, borderSkipped: false }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: c => c.dataset.label + ': RD$ ' + c.parsed.y.toLocaleString() } } },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 10 }, color: '#94A3B8' } },
            y: { grid: { color: '#F1F5F9' }, ticks: { font: { size: 10 }, color: '#94A3B8', callback: v => 'RD$ ' + v.toLocaleString() } }
        }
    }
});

new Chart(document.getElementById('distChart').getContext('2d'), {
    type: 'doughnut',
    data: {
        labels: ['Cobrado', 'Pendiente'],
        datasets: [{
            data: [<?= $totalPaid ?>, <?= $totalPending ?>],
            backgroundColor: ['#10B981', '#F59E0B'],
            borderWidth: 0, hoverOffset: 6
        }]
    },
    options: {
        cutout: '70%',
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } }
    }
});

function prefillAmount() {
    const sel = document.getElementById('invoiceClientSelect');
    const opt = sel.options[sel.selectedIndex];
    const amount = opt.getAttribute('data-amount');
    const name = opt.getAttribute('data-name');
    if (amount && parseFloat(amount) > 0) {
        document.getElementById('invoiceAmount').value = amount;
    }
    // Sugerencia de concepto si esta vacio
    const conceptInput = document.getElementById('invoiceConcept');
    if (conceptInput && !conceptInput.value && name) {
        const months = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
        const now = new Date();
        conceptInput.value = 'Iguala ' + months[now.getMonth()] + ' ' + now.getFullYear();
    }
}

// Bulk select
(function() {
    const selectAll = document.getElementById('selectAll');
    const countLbl = document.getElementById('selectedCount');
    const bulkBtn = document.getElementById('bulkPaidBtn');
    function updateCount() {
        const sel = document.querySelectorAll('.fn-row-cb:checked').length;
        if (sel > 0) {
            countLbl.textContent = sel + ' seleccionado(s)';
            bulkBtn.classList.remove('hidden');
        } else {
            countLbl.textContent = '';
            bulkBtn.classList.add('hidden');
        }
    }
    if (selectAll) {
        selectAll.addEventListener('change', () => {
            document.querySelectorAll('.fn-row-cb').forEach(cb => cb.checked = selectAll.checked);
            updateCount();
        });
    }
    document.querySelectorAll('.fn-row-cb').forEach(cb => cb.addEventListener('change', updateCount));
})();

// Menus tipo dropdown (position: fixed para escapar overflow)
(function() {
    function positionMenu(menu, trigger) {
        const r = trigger.getBoundingClientRect();
        const w = menu.offsetWidth || 200, h = menu.offsetHeight || 150;
        const vw = window.innerWidth, vh = window.innerHeight;
        let top = r.bottom + 6, left = r.right - w;
        if (top + h > vh - 8) top = r.top - h - 6;
        if (left < 8) left = Math.max(8, r.left);
        if (left + w > vw - 8) left = vw - w - 8;
        menu.style.top = top + 'px'; menu.style.left = left + 'px';
    }
    function closeAll() { document.querySelectorAll('.fn-menu').forEach(m => m.classList.add('hidden')); }
    document.querySelectorAll('.fn-menu-trigger').forEach(t => {
        t.addEventListener('click', e => {
            e.stopPropagation();
            const id = t.dataset.fnMenu;
            const m = document.getElementById(id);
            const wasOpen = !m.classList.contains('hidden');
            closeAll();
            if (!wasOpen) { m.classList.remove('hidden'); positionMenu(m, t); }
        });
    });
    document.addEventListener('click', closeAll);
    document.addEventListener('keydown', e => { if (e.key === 'Escape') closeAll(); });
    window.addEventListener('scroll', closeAll, true);
    window.addEventListener('resize', closeAll);
    document.querySelectorAll('.fn-menu').forEach(m => m.addEventListener('click', e => e.stopPropagation()));
})();
</script>

<?php include 'components/layout_end.php'; ?>
