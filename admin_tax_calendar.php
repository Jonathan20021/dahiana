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
    } elseif ($action === 'send_email_reminder') {
        $oid = (int)($_POST['obligation_id'] ?? 0);
        $r = sendObligationReminderEmail($oid);
        if (!empty($r['ok'])) {
            $success = "Recordatorio enviado por email.";
        } else {
            $error = "No se pudo enviar el email: " . ($r['error'] ?? $r['reason'] ?? 'desconocido');
        }
    } elseif ($action === 'bulk_delete_completed') {
        $cutoff = trim($_POST['cutoff'] ?? '');
        if (preg_match('/^\d{4}-\d{2}$/', $cutoff)) {
            $stmt = $pdo->prepare("DELETE FROM tax_obligations WHERE status='completado' AND period <= ?");
            $stmt->execute([$cutoff]);
            $success = "Se eliminaron " . $stmt->rowCount() . " obligaciones completadas hasta {$cutoff}.";
        } else {
            $stmt = $pdo->prepare("DELETE FROM tax_obligations WHERE status='completado'");
            $stmt->execute();
            $success = "Se eliminaron " . $stmt->rowCount() . " obligaciones completadas.";
        }
    } elseif ($action === 'bulk_delete') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ids = array_values(array_filter(array_map('intval', $ids), fn($v) => $v > 0));
        if (!empty($ids)) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $pdo->prepare("DELETE FROM tax_obligations WHERE id IN ({$placeholders})");
            $stmt->execute($ids);
            $success = "Se eliminaron " . $stmt->rowCount() . " obligacion(es).";
        }
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
<form method="POST" id="bulkObForm" onsubmit="return confirm('Eliminar las obligaciones seleccionadas?')">
    <input type="hidden" name="action" value="bulk_delete">
    <div class="tc-card">
        <div class="tc-head">
            <div>
                <h3 class="tc-title">Obligaciones</h3>
                <p class="tc-sub"><?= count($obligations) ?> registro(s) en este filtro</p>
            </div>
            <div class="tc-head-actions">
                <span id="tcSelInfo" class="tc-sel-info hidden">
                    <span id="tcSelCount">0</span> seleccionadas
                </span>
                <button type="submit" id="tcBulkBtn" disabled class="tc-btn tc-btn-danger" style="opacity:.4;cursor:not-allowed">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
                    Eliminar
                </button>
                <button type="button" onclick="document.getElementById('tcCleanModal').classList.remove('hidden')" class="tc-btn tc-btn-ghost" title="Limpiar obligaciones completadas">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"/></svg>
                    Limpiar completadas
                </button>
            </div>
        </div>

        <?php if (empty($obligations)): ?>
        <div class="tc-empty">
            <div class="tc-empty-icon">
                <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </div>
            <p class="tc-empty-title">No hay obligaciones con estos filtros</p>
            <p class="tc-empty-sub">Cambia los filtros arriba o sincroniza obligaciones para todos los clientes.</p>
            <a href="admin_tax_calendar.php" class="tc-btn tc-btn-ghost mt-3 inline-flex">Ver todo</a>
        </div>
        <?php else: ?>
        <div class="tc-list">
            <?php
            $currentDate = '';
            foreach ($obligations as $ob):
                $obDate = date('Y-m-d', strtotime($ob['due_date']));
                $isNewDate = $obDate !== $currentDate;
                $currentDate = $obDate;
                $cleanPhone = preg_replace('/[^0-9]/', '', $ob['client_phone'] ?? '');
                $daysOut = (int)((strtotime($obDate) - strtotime(date('Y-m-d'))) / 86400);
                $isCompleted = $ob['status'] === 'completado';
                $isOverdue   = $ob['status'] === 'vencido' || ($ob['status'] === 'pendiente' && $daysOut < 0);
                $statusTone = $isCompleted ? 'green' : ($isOverdue ? 'red' : ($daysOut <= 7 ? 'amber' : 'blue'));
                $typeShort = match(true) {
                    str_starts_with($ob['obligation_type'], 'IT-') => 'IT' . substr($ob['obligation_type'], 3),
                    str_starts_with($ob['obligation_type'], 'IR-') => 'IR' . substr($ob['obligation_type'], 3),
                    $ob['obligation_type'] === 'ANTICIPO' => 'ANT',
                    default => $ob['obligation_type'],
                };
            ?>
            <?php if ($isNewDate): ?>
            <div class="tc-date-head">
                <span class="tc-date-strong"><?= date('d M Y', strtotime($obDate)) ?></span>
                <?php
                if ($daysOut === 0) echo '<span class="tc-date-tag tc-date-tag-amber">Hoy</span>';
                elseif ($daysOut === 1) echo '<span class="tc-date-tag tc-date-tag-amber">Manana</span>';
                elseif ($daysOut > 0 && $daysOut <= 7) echo '<span class="tc-date-tag tc-date-tag-blue">En ' . $daysOut . ' dias</span>';
                elseif ($daysOut > 7) echo '<span class="tc-date-tag tc-date-tag-slate">En ' . $daysOut . ' dias</span>';
                elseif ($daysOut < 0) echo '<span class="tc-date-tag tc-date-tag-red">Vencido hace ' . abs($daysOut) . ' d</span>';
                ?>
            </div>
            <?php endif; ?>

            <article class="tc-row tc-row-<?= $statusTone ?>">
                <label class="tc-check">
                    <input type="checkbox" name="ids[]" value="<?= (int)$ob['id'] ?>" class="tc-check-input">
                    <span class="tc-check-box"></span>
                </label>

                <div class="tc-type-badge tc-type-<?= $statusTone ?>">
                    <?= htmlspecialchars($typeShort) ?>
                </div>

                <div class="tc-main">
                    <div class="tc-main-row">
                        <p class="tc-ob-title"><?= htmlspecialchars(getObligationLabel($ob['obligation_type'])) ?></p>
                        <span class="tc-period"><?= htmlspecialchars(formatPeriod($ob['period'])) ?></span>
                    </div>
                    <a href="client_details.php?id=<?= $ob['client_id'] ?>" class="tc-client">
                        <?= htmlspecialchars($ob['client_name']) ?>
                        <?php if ($ob['business_name']): ?><span class="tc-business">· <?= htmlspecialchars($ob['business_name']) ?></span><?php endif; ?>
                    </a>
                </div>

                <div class="tc-status">
                    <?= getObligationStatusBadge($ob['status'], $ob['due_date']) ?>
                </div>

                <div class="tc-actions">
                    <?php if (!$isCompleted): ?>
                    <button type="submit" form="completeForm-<?= $ob['id'] ?>" class="tc-btn tc-btn-success">
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        Completar
                    </button>
                    <?php else: ?>
                    <button type="submit" form="reopenForm-<?= $ob['id'] ?>" class="tc-btn tc-btn-ghost">Reabrir</button>
                    <?php endif; ?>

                    <?php if (in_array($ob['status'], ['pendiente','vencido'], true)): ?>
                    <button type="submit" form="emailForm-<?= $ob['id'] ?>" class="tc-icon-btn" title="Recordar por email">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                    </button>
                    <?php endif; ?>

                    <?php if ($cleanPhone && in_array($ob['status'], ['pendiente','vencido'], true)):
                        $reminderMsg = "Hola {$ob['client_name']}, " . getSetting('whatsapp_greeting', 'te escribimos de tu Asesoria Financiera') . ". Te recordamos que la obligacion " . getObligationLabel($ob['obligation_type']) . " de " . formatPeriod($ob['period']) . " vence el " . date('d/m/Y', strtotime($ob['due_date'])) . ".";
                    ?>
                    <a href="https://wa.me/<?= $cleanPhone ?>?text=<?= urlencode($reminderMsg) ?>" target="_blank" class="tc-icon-btn tc-icon-wa" title="Recordar por WhatsApp">
                        <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207"/></svg>
                    </a>
                    <?php endif; ?>

                    <div class="tc-menu-wrap">
                        <button type="button" class="tc-icon-btn tc-menu-trigger" data-tc-menu="tc-menu-<?= $ob['id'] ?>" title="Mas opciones">
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"/></svg>
                        </button>
                        <div id="tc-menu-<?= $ob['id'] ?>" class="tc-menu hidden">
                            <a href="client_details.php?id=<?= $ob['client_id'] ?>" class="tc-menu-item">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                Abrir cliente
                            </a>
                            <button type="submit" form="deleteForm-<?= $ob['id'] ?>" class="tc-menu-item tc-menu-item-danger" onclick="return confirm('Eliminar esta obligacion? No se puede deshacer.')">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
                                Eliminar
                            </button>
                        </div>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</form>

<!-- Hidden forms for each row's actions (outside bulkObForm to avoid nested forms) -->
<?php foreach ($obligations as $ob): ?>
    <?php if ($ob['status'] !== 'completado'): ?>
    <form method="POST" id="completeForm-<?= $ob['id'] ?>" style="display:none">
        <input type="hidden" name="action" value="mark_completed">
        <input type="hidden" name="obligation_id" value="<?= $ob['id'] ?>">
    </form>
    <?php else: ?>
    <form method="POST" id="reopenForm-<?= $ob['id'] ?>" style="display:none">
        <input type="hidden" name="action" value="mark_pending">
        <input type="hidden" name="obligation_id" value="<?= $ob['id'] ?>">
    </form>
    <?php endif; ?>
    <?php if (in_array($ob['status'], ['pendiente','vencido'], true)): ?>
    <form method="POST" id="emailForm-<?= $ob['id'] ?>" style="display:none">
        <input type="hidden" name="action" value="send_email_reminder">
        <input type="hidden" name="obligation_id" value="<?= $ob['id'] ?>">
    </form>
    <?php endif; ?>
    <form method="POST" id="deleteForm-<?= $ob['id'] ?>" style="display:none">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="obligation_id" value="<?= $ob['id'] ?>">
    </form>
<?php endforeach; ?>

<!-- Clean modal -->
<div id="tcCleanModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 modal-backdrop" onclick="document.getElementById('tcCleanModal').classList.add('hidden')"></div>
    <div class="relative flex min-h-full items-center justify-center p-4">
        <div class="w-full max-w-md rounded-3xl bg-white shadow-2xl overflow-hidden">
            <div class="px-6 py-5 border-b border-stone-100">
                <h3 class="text-base font-bold text-slate-900">Limpiar completadas</h3>
                <p class="text-xs text-slate-500 mt-1">Elimina obligaciones ya completadas para mantener el calendario limpio.</p>
            </div>
            <form method="POST" class="p-6 space-y-4" onsubmit="return confirm('Eliminar las obligaciones completadas? Esto no se puede deshacer.')">
                <input type="hidden" name="action" value="bulk_delete_completed">
                <div>
                    <label class="field-label">Eliminar las completadas hasta el periodo</label>
                    <input type="month" name="cutoff" value="<?= date('Y-m', strtotime('-3 months')) ?>" class="field text-sm">
                    <p class="mt-2 text-[11px] text-slate-500">Dejar vacio para borrar TODAS las completadas (riesgoso).</p>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('tcCleanModal').classList.add('hidden')" class="tc-btn tc-btn-ghost">Cancelar</button>
                    <button type="submit" class="tc-btn tc-btn-danger">Eliminar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function() {
    const checks = document.querySelectorAll('.tc-check-input');
    const bulkBtn = document.getElementById('tcBulkBtn');
    const info = document.getElementById('tcSelInfo');
    const cnt = document.getElementById('tcSelCount');
    function refresh() {
        const n = Array.from(checks).filter(c => c.checked).length;
        cnt.textContent = n;
        if (n > 0) {
            info.classList.remove('hidden');
            bulkBtn.disabled = false;
            bulkBtn.style.opacity = '1';
            bulkBtn.style.cursor = 'pointer';
        } else {
            info.classList.add('hidden');
            bulkBtn.disabled = true;
            bulkBtn.style.opacity = '.4';
            bulkBtn.style.cursor = 'not-allowed';
        }
    }
    checks.forEach(c => c.addEventListener('change', refresh));

    // Row menus
    document.querySelectorAll('.tc-menu-trigger').forEach(t => {
        t.addEventListener('click', e => {
            e.stopPropagation();
            const id = t.dataset.tcMenu;
            const m = document.getElementById(id);
            const wasOpen = !m.classList.contains('hidden');
            document.querySelectorAll('.tc-menu').forEach(x => x.classList.add('hidden'));
            if (!wasOpen) m.classList.remove('hidden');
        });
    });
    document.addEventListener('click', () => {
        document.querySelectorAll('.tc-menu').forEach(m => m.classList.add('hidden'));
    });
    document.querySelectorAll('.tc-menu').forEach(m => m.addEventListener('click', e => e.stopPropagation()));
})();
</script>

<style>
    /* === Tax Calendar list === */
    .tc-card { background: #fff; border: 1px solid #EEF0F2; border-radius: 22px; overflow: hidden; }
    .tc-head { padding: 16px 20px; border-bottom: 1px solid #F4F4F5; display: flex; flex-direction: column; gap: 12px; }
    .tc-title { font-size: 15px; font-weight: 800; color: #0F172A; }
    .tc-sub   { font-size: 11px; color: #94A3B8; margin-top: 2px; }
    .tc-head-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .tc-sel-info { padding: 4px 10px; border-radius: 999px; background: #DBEAFE; color: #1E40AF; font-size: 11px; font-weight: 700; }
    @media (min-width: 768px) { .tc-head { flex-direction: row; justify-content: space-between; align-items: center; } }

    .tc-btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border-radius: 10px; font-size: 12px; font-weight: 700; transition: all .15s ease; white-space: nowrap; }
    .tc-btn-ghost { background: #F4F4F5; color: #475569; }
    .tc-btn-ghost:hover { background: #E5E7EB; color: #0F172A; }
    .tc-btn-danger { background: #FEF2F2; color: #DC2626; }
    .tc-btn-danger:hover { background: #FECACA; }
    .tc-btn-success { background: #10B981; color: #fff; padding: 7px 14px; }
    .tc-btn-success:hover { background: #059669; }

    .tc-empty { padding: 60px 20px; text-align: center; }
    .tc-empty-icon { width: 56px; height: 56px; border-radius: 50%; background: #F4F4F5; color: #94A3B8; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 12px; }
    .tc-empty-title { font-size: 14px; font-weight: 700; color: #0F172A; }
    .tc-empty-sub { font-size: 12px; color: #94A3B8; margin-top: 4px; }

    .tc-list { display: flex; flex-direction: column; }

    .tc-date-head { padding: 10px 20px; background: #F8FAFC; display: flex; align-items: center; gap: 10px; border-top: 1px solid #F4F4F5; border-bottom: 1px solid #F4F4F5; }
    .tc-date-head:first-child { border-top: 0; }
    .tc-date-strong { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; color: #475569; }
    .tc-date-tag { font-size: 10px; font-weight: 700; padding: 2px 9px; border-radius: 999px; }
    .tc-date-tag-amber { background: #FEF3C7; color: #B45309; }
    .tc-date-tag-red { background: #FEE2E2; color: #B91C1C; }
    .tc-date-tag-blue { background: #DBEAFE; color: #1D4ED8; }
    .tc-date-tag-slate { background: #F1F5F9; color: #475569; }

    .tc-row { display: flex; align-items: center; gap: 12px; padding: 12px 18px; border-bottom: 1px solid #F4F4F5; transition: background .15s ease; }
    .tc-row:hover { background: #FAFAFA; }
    .tc-row:last-child { border-bottom: 0; }
    .tc-row-red { border-left: 3px solid #EF4444; }
    .tc-row-amber { border-left: 3px solid #F59E0B; }
    .tc-row-blue { border-left: 3px solid transparent; }
    .tc-row-green { border-left: 3px solid #10B981; background: #FAFAFA; opacity: 0.85; }

    .tc-check { display: inline-flex; align-items: center; cursor: pointer; padding: 2px; flex-shrink: 0; }
    .tc-check-input { position: absolute; opacity: 0; pointer-events: none; }
    .tc-check-box { width: 18px; height: 18px; border-radius: 6px; border: 1.5px solid #CBD5E1; display: inline-flex; align-items: center; justify-content: center; transition: all .15s ease; }
    .tc-check-input:checked + .tc-check-box { background: #0F172A; border-color: #0F172A; }
    .tc-check-input:checked + .tc-check-box::after { content: ''; width: 6px; height: 9px; border-right: 2px solid #fff; border-bottom: 2px solid #fff; transform: rotate(45deg) translateY(-1px); }

    .tc-type-badge { width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 800; letter-spacing: 0.01em; flex-shrink: 0; }
    .tc-type-red { background: #FEF2F2; color: #B91C1C; }
    .tc-type-amber { background: #FEF3C7; color: #B45309; }
    .tc-type-blue { background: #DBEAFE; color: #1E40AF; }
    .tc-type-green { background: #DCFCE7; color: #15803D; }

    .tc-main { flex: 1; min-width: 0; }
    .tc-main-row { display: flex; align-items: center; gap: 8px; margin-bottom: 2px; flex-wrap: wrap; }
    .tc-ob-title { font-size: 13px; font-weight: 700; color: #0F172A; }
    .tc-period { font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 999px; background: #F1F5F9; color: #475569; }
    .tc-client { font-size: 11px; color: #64748B; display: block; transition: color .12s ease; }
    .tc-client:hover { color: #1D4ED8; }
    .tc-business { color: #94A3B8; }

    .tc-status { flex-shrink: 0; min-width: 110px; }
    .tc-actions { display: flex; align-items: center; gap: 4px; flex-shrink: 0; }
    .tc-icon-btn { width: 32px; height: 32px; border-radius: 10px; background: #F4F4F5; color: #64748B; display: inline-flex; align-items: center; justify-content: center; transition: all .15s ease; }
    .tc-icon-btn:hover { background: #DBEAFE; color: #1D4ED8; }
    .tc-icon-wa:hover { background: #DCFCE7; color: #15803D; }

    .tc-menu-wrap { position: relative; }
    .tc-menu { position: absolute; top: calc(100% + 4px); right: 0; min-width: 180px; background: #fff; border: 1px solid #E5E7EB; border-radius: 14px; box-shadow: 0 10px 30px rgba(15,23,42,0.12); z-index: 30; padding: 6px; }
    .tc-menu-item { display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 10px; border-radius: 10px; font-size: 12px; font-weight: 600; color: #475569; text-align: left; transition: all .12s ease; }
    .tc-menu-item:hover { background: #F4F4F5; color: #0F172A; }
    .tc-menu-item-danger { color: #DC2626; }
    .tc-menu-item-danger:hover { background: #FEF2F2; color: #B91C1C; }

    @media (max-width: 1024px) {
        .tc-row { flex-wrap: wrap; gap: 10px; }
        .tc-main { width: 100%; order: 2; }
        .tc-status { order: 3; }
        .tc-actions { order: 4; width: 100%; justify-content: flex-end; padding-top: 4px; border-top: 1px dashed #F1F5F9; margin-top: 6px; }
    }
</style>

<?php include 'components/layout_end.php'; ?>
