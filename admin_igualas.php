<?php
require_once 'config.php';
requireAuth('admin');
requirePagePermission();

$success = $error = null;
$months = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];

// Selected period (YYYY-MM)
$period = $_GET['period'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) {
    $period = date('Y-m');
}
$periodLabel = $months[(int)substr($period, 5, 2) - 1] . ' ' . substr($period, 0, 4);

// Navigation
$prevPeriod = date('Y-m', strtotime($period . '-01 -1 month'));
$nextPeriod = date('Y-m', strtotime($period . '-01 +1 month'));

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'generate_invoice') {
        $client_id = (int)($_POST['client_id'] ?? 0);
        $client = $pdo->prepare("SELECT id, name, iguala_amount FROM users WHERE id = ?");
        $client->execute([$client_id]);
        $c = $client->fetch();
        if ($c && (float)$c['iguala_amount'] > 0) {
            $exists = $pdo->prepare("SELECT id FROM invoices WHERE client_id = ? AND period = ?");
            $exists->execute([$client_id, $period]);
            if (!$exists->fetch()) {
                $concept = "Iguala " . $periodLabel;
                $dueDate = date('Y-m-t', strtotime($period . '-01'));
                $pdo->prepare("INSERT INTO invoices (client_id, amount, concept, due_date, period, status) VALUES (?, ?, ?, ?, ?, 'pendiente')")
                    ->execute([$client_id, $c['iguala_amount'], $concept, $dueDate, $period]);
                $newInvId = $pdo->lastInsertId();
                logClientActivity($client_id, 'invoice', "Volante generado para {$periodLabel}");
                $emailNote = '';
                if (getSetting('notify_invoice', '1') === '1') {
                    $r = sendInvoiceCreatedEmail($newInvId);
                    if (!empty($r['ok'])) $emailNote = ' Email enviado.';
                }
                $success = "Volante generado para {$c['name']}.{$emailNote}";
            } else {
                $error = "El cliente ya tiene volante en {$periodLabel}.";
            }
        }
    } elseif ($action === 'generate_all') {
        $clientsToBill = $pdo->query("
            SELECT u.id, u.name, u.iguala_amount
            FROM users u
            LEFT JOIN roles r ON r.slug = u.role
            WHERE COALESCE(r.access_level, CASE WHEN u.role = 'admin' THEN 'admin' ELSE 'client' END) = 'client'
              AND u.iguala_amount > 0
              AND (u.client_status IS NULL OR u.client_status = 'activo')
              AND NOT EXISTS (SELECT 1 FROM invoices i WHERE i.client_id = u.id AND i.period = " . $pdo->quote($period) . ")
        ")->fetchAll();
        $count = 0;
        $emailed = 0;
        $dueDate = date('Y-m-t', strtotime($period . '-01'));
        $notifyOn = getSetting('notify_invoice', '1') === '1';
        foreach ($clientsToBill as $c) {
            $concept = "Iguala " . $periodLabel;
            $pdo->prepare("INSERT INTO invoices (client_id, amount, concept, due_date, period, status) VALUES (?, ?, ?, ?, ?, 'pendiente')")
                ->execute([$c['id'], $c['iguala_amount'], $concept, $dueDate, $period]);
            $newInvId = $pdo->lastInsertId();
            logClientActivity($c['id'], 'invoice', "Volante generado para {$periodLabel} (lote)");
            if ($notifyOn) {
                $r = sendInvoiceCreatedEmail($newInvId);
                if (!empty($r['ok'])) $emailed++;
            }
            $count++;
        }
        $emailLine = $notifyOn ? " {$emailed} notificacion(es) enviadas." : '';
        $success = "Se generaron {$count} volante(s) para {$periodLabel}.{$emailLine}";
    } elseif ($action === 'mark_paid') {
        $iid = (int)($_POST['invoice_id'] ?? 0);
        $pdo->prepare("UPDATE invoices SET status='pagado', paid_at = NOW() WHERE id = ?")->execute([$iid]);
        $emailNote = '';
        if (getSetting('notify_invoice_paid', '1') === '1') {
            $r = sendInvoicePaidEmail($iid);
            if (!empty($r['ok'])) $emailNote = ' Confirmacion enviada.';
        }
        $success = "Volante marcado como pagado.{$emailNote}";
    } elseif ($action === 'delete_invoice') {
        $iid = (int)($_POST['invoice_id'] ?? 0);
        $pdo->prepare("DELETE FROM invoices WHERE id = ?")->execute([$iid]);
        $success = "Volante eliminado.";
    }
}

// Fetch active clients with iguala + their invoice for this period
$clients = $pdo->prepare("
    SELECT
        u.id, u.name, u.email, u.phone, u.business_name, u.rnc,
        u.iguala_amount, u.iguala_frequency, u.client_status,
        i.id AS invoice_id, i.amount AS invoice_amount, i.status AS invoice_status, i.due_date,
        i.paid_at, i.created_at AS invoice_created_at
    FROM users u
    LEFT JOIN roles r ON r.slug = u.role
    LEFT JOIN invoices i ON i.client_id = u.id AND i.period = ?
    WHERE COALESCE(r.access_level, CASE WHEN u.role = 'admin' THEN 'admin' ELSE 'client' END) = 'client'
      AND u.iguala_amount > 0
    ORDER BY
        CASE
            WHEN i.id IS NULL THEN 0
            WHEN i.status = 'pendiente' THEN 1
            ELSE 2
        END,
        u.name
");
$clients->execute([$period]);
$clientList = $clients->fetchAll();

// Period stats
$stats = [
    'total' => 0,
    'sin_volante' => 0,
    'pendiente' => 0,
    'pagado' => 0,
    'monto_total' => 0,
    'monto_cobrado' => 0,
    'monto_pendiente' => 0,
];
foreach ($clientList as $c) {
    $stats['total']++;
    $stats['monto_total'] += (float)$c['iguala_amount'];
    if (!$c['invoice_id']) {
        $stats['sin_volante']++;
    } elseif ($c['invoice_status'] === 'pagado') {
        $stats['pagado']++;
        $stats['monto_cobrado'] += (float)$c['invoice_amount'];
    } else {
        $stats['pendiente']++;
        $stats['monto_pendiente'] += (float)$c['invoice_amount'];
    }
}

$page_title = 'Control de Igualas';
$page_subtitle = 'Vista mensual con generacion masiva y seguimiento de cobros.';
$page_actions = '<form method="POST" class="inline" onsubmit="return confirm(\'Generar volantes para todos los clientes con iguala sin volante este mes?\')">
    <input type="hidden" name="action" value="generate_all">
    <button type="submit" class="btn-dark">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
        Generar todos
    </button>
</form>';
include 'components/layout_start.php';
?>

<?php if ($success): ?>
<div class="mb-4 rounded-2xl bg-emerald-50 px-4 py-3 border border-emerald-100 text-sm font-medium text-emerald-800"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="mb-4 rounded-2xl bg-red-50 px-4 py-3 border border-red-100 text-sm font-medium text-red-700"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Period selector + stats -->
<div class="surface-card p-4 mb-4">
    <div class="flex flex-col lg:flex-row gap-4 items-stretch">
        <!-- Period nav -->
        <div class="flex items-center gap-2 lg:w-72 shrink-0">
            <a href="?period=<?= $prevPeriod ?>" class="icon-btn" title="Mes anterior">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
            </a>
            <div class="flex-1 text-center">
                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Periodo</p>
                <p class="text-base lg:text-lg font-extrabold text-slate-900 leading-tight"><?= $periodLabel ?></p>
            </div>
            <a href="?period=<?= $nextPeriod ?>" class="icon-btn" title="Mes siguiente">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
            </a>
            <a href="?period=<?= date('Y-m') ?>" class="btn-soft !text-xs !py-2 !px-3 ml-1">Hoy</a>
        </div>

        <!-- Inline stats -->
        <div class="flex-1 grid grid-cols-2 lg:grid-cols-4 gap-3 lg:border-l lg:border-stone-200 lg:pl-4">
            <div>
                <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Clientes</p>
                <p class="text-lg font-extrabold text-slate-900"><?= $stats['total'] ?></p>
            </div>
            <div>
                <p class="text-[10px] font-bold uppercase tracking-wider text-amber-600">Sin volante</p>
                <p class="text-lg font-extrabold text-slate-900"><?= $stats['sin_volante'] ?></p>
            </div>
            <div>
                <p class="text-[10px] font-bold uppercase tracking-wider text-red-600">Pendientes</p>
                <p class="text-lg font-extrabold text-slate-900"><?= $stats['pendiente'] ?></p>
                <p class="text-[10px] text-red-600">RD$ <?= number_format($stats['monto_pendiente'], 0) ?></p>
            </div>
            <div>
                <p class="text-[10px] font-bold uppercase tracking-wider text-emerald-600">Cobrados</p>
                <p class="text-lg font-extrabold text-slate-900"><?= $stats['pagado'] ?></p>
                <p class="text-[10px] text-emerald-600">RD$ <?= number_format($stats['monto_cobrado'], 0) ?></p>
            </div>
        </div>
    </div>

    <!-- Progress bar -->
    <?php if ($stats['total'] > 0):
        $pctPagado = $stats['total'] > 0 ? ($stats['pagado'] / $stats['total']) * 100 : 0;
        $pctPend = $stats['total'] > 0 ? ($stats['pendiente'] / $stats['total']) * 100 : 0;
    ?>
    <div class="mt-3 pt-3 border-t border-stone-100">
        <div class="flex items-center justify-between text-[11px] mb-1.5">
            <span class="font-semibold text-slate-600">Avance del mes</span>
            <span class="font-bold text-slate-900"><?= round($pctPagado) ?>% cobrado</span>
        </div>
        <div class="h-2 rounded-full bg-stone-100 overflow-hidden flex">
            <div class="bg-emerald-500 h-full" style="width: <?= $pctPagado ?>%"></div>
            <div class="bg-amber-400 h-full" style="width: <?= $pctPend ?>%"></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Clients table -->
<div class="surface-card overflow-hidden">
    <div class="px-5 py-4 border-b border-stone-100 flex items-center justify-between">
        <h3 class="text-base font-bold text-slate-900">Clientes con iguala &middot; <?= $periodLabel ?></h3>
        <span class="text-xs text-slate-400"><?= count($clientList) ?> registro(s)</span>
    </div>

    <?php if (empty($clientList)): ?>
    <div class="py-16 text-center">
        <div class="w-14 h-14 mx-auto rounded-full bg-stone-100 flex items-center justify-center mb-3">
            <svg class="w-6 h-6 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        </div>
        <p class="text-sm text-slate-500">No hay clientes con iguala configurada.</p>
        <a href="admin_clients.php" class="mt-3 inline-flex btn-soft !text-xs">Ir a Clientes</a>
    </div>
    <?php else: ?>
    <ul class="divide-y divide-stone-100">
        <?php foreach ($clientList as $c):
            $cleanPhone = preg_replace('/[^0-9]/', '', $c['phone'] ?? '');
            if (!$c['invoice_id']) {
                $stateColor = 'badge-amber';
                $stateLabel = 'Sin volante';
            } elseif ($c['invoice_status'] === 'pagado') {
                $stateColor = 'badge-green';
                $stateLabel = 'Cobrado';
            } else {
                $stateColor = 'badge-red';
                $stateLabel = 'Pendiente';
            }
        ?>
        <li class="table-row px-5 py-3.5">
            <div class="flex flex-col lg:flex-row lg:items-center gap-3">
                <!-- Client info -->
                <div class="flex items-center gap-3 lg:flex-1 min-w-0">
                    <div class="h-10 w-10 rounded-full bg-stone-100 border border-stone-200 flex items-center justify-center text-xs font-bold text-slate-700 shrink-0">
                        <?= htmlspecialchars(substr(strtoupper($c['name']), 0, 1)) ?>
                    </div>
                    <div class="min-w-0 flex-1">
                        <a href="client_details.php?id=<?= $c['id'] ?>" class="text-sm font-bold text-slate-900 hover:text-blue-600 truncate block"><?= htmlspecialchars($c['name']) ?></a>
                        <p class="text-[11px] text-slate-500 truncate">
                            <?php if ($c['business_name']): ?><?= htmlspecialchars($c['business_name']) ?> &middot; <?php endif; ?>
                            <?= htmlspecialchars($c['iguala_frequency'] ?: 'mensual') ?>
                        </p>
                    </div>
                </div>

                <!-- Amount -->
                <div class="lg:w-32 shrink-0">
                    <p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">Monto iguala</p>
                    <p class="text-sm font-extrabold text-slate-900">RD$ <?= number_format((float)$c['iguala_amount'], 2) ?></p>
                </div>

                <!-- Status this month -->
                <div class="lg:w-44 shrink-0">
                    <p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold mb-1">Estado <?= $periodLabel ?></p>
                    <span class="badge-dot <?= $stateColor ?>"><?= $stateLabel ?></span>
                    <?php if ($c['invoice_id']): ?>
                    <p class="text-[10px] text-slate-400 mt-1">Vence <?= date('d/m/Y', strtotime($c['due_date'])) ?></p>
                    <?php endif; ?>
                </div>

                <!-- Actions -->
                <div class="flex items-center gap-1 shrink-0 lg:w-auto">
                    <?php if (!$c['invoice_id']): ?>
                    <form method="POST" class="inline">
                        <input type="hidden" name="action" value="generate_invoice">
                        <input type="hidden" name="client_id" value="<?= $c['id'] ?>">
                        <button type="submit" class="btn-dark !text-xs !py-1.5 !px-3">
                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                            Generar
                        </button>
                    </form>
                    <?php else: ?>
                    <?php if ($c['invoice_status'] === 'pendiente'): ?>
                    <form method="POST" class="inline">
                        <input type="hidden" name="action" value="mark_paid">
                        <input type="hidden" name="invoice_id" value="<?= $c['invoice_id'] ?>">
                        <button type="submit" class="icon-btn hover:!bg-emerald-100 hover:!text-emerald-700" title="Marcar pagado">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        </button>
                    </form>
                    <?php endif; ?>
                    <a href="invoice_pdf.php?id=<?= $c['invoice_id'] ?>" target="_blank" class="icon-btn hover:!bg-blue-100 hover:!text-blue-700" title="Descargar PDF">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    </a>
                    <form method="POST" class="inline" onsubmit="return confirm('Eliminar este volante?')">
                        <input type="hidden" name="action" value="delete_invoice">
                        <input type="hidden" name="invoice_id" value="<?= $c['invoice_id'] ?>">
                        <button type="submit" class="icon-btn hover:!bg-red-100 hover:!text-red-700" title="Eliminar volante">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
                        </button>
                    </form>
                    <?php endif; ?>

                    <?php if ($cleanPhone && $c['invoice_id'] && $c['invoice_status'] === 'pendiente'):
                        $waText = "Hola {$c['name']}, " . getSetting('whatsapp_greeting', 'te escribimos de tu Asesoria Financiera') . ". Tu volante de iguala {$periodLabel} por RD$ " . number_format((float)$c['invoice_amount'], 2) . " esta pendiente. Vence el " . date('d/m/Y', strtotime($c['due_date'])) . ".";
                    ?>
                    <a href="https://wa.me/<?= $cleanPhone ?>?text=<?= urlencode($waText) ?>" target="_blank" class="icon-btn hover:!bg-emerald-100 hover:!text-emerald-700" title="Recordar por WhatsApp">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51"/></svg>
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
