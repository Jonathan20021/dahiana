<?php
require_once 'config.php';
requireAuth('admin');

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
    } elseif ($action === 'delete_invoice') {
        $invoice_id = $_POST['invoice_id'];
        $pdo->prepare("DELETE FROM invoices WHERE id = ?")->execute([$invoice_id]);
        $success = "Volante eliminado.";
    }
}

$totalPending = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE status='pendiente'")->fetchColumn();
$totalPaid = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE status='pagado'")->fetchColumn();
$countPending = $pdo->query("SELECT COUNT(*) FROM invoices WHERE status='pendiente'")->fetchColumn();
$countPaid = $pdo->query("SELECT COUNT(*) FROM invoices WHERE status='pagado'")->fetchColumn();

$invoices = $pdo->query("
    SELECT i.*, u.name as client_name, u.phone as client_phone
    FROM invoices i
    JOIN users u ON i.client_id = u.id
    ORDER BY i.status ASC, i.due_date ASC
")->fetchAll();

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
$page_subtitle = 'Control de volantes, cobros y pagos de tus clientes.';
$page_actions = '<button type="button" onclick="document.getElementById(\'createInvoiceModal\').classList.remove(\'hidden\')"
    class="inline-flex items-center gap-2 btn-dark text-sm">
    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
    Nuevo volante
</button>';
include 'components/layout_start.php';
?>

<?php if ($success): ?>
<div class="mb-4 rounded-2xl bg-emerald-50 px-4 py-3 border border-emerald-100 text-sm font-medium text-emerald-800"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<!-- KPIs + chart -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-3 mb-4">
    <div class="stat-card p-5 lg:col-span-1">
        <div class="flex items-center justify-between mb-3">
            <span class="w-10 h-10 rounded-2xl bg-red-50 text-red-600 flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </span>
            <span class="badge-dot badge-red"><?= $countPending ?> pend.</span>
        </div>
        <p class="text-sm text-slate-500">Por cobrar</p>
        <p class="mt-1 text-3xl font-extrabold tracking-tight text-slate-900">RD$ <?= number_format($totalPending, 0) ?></p>
        <p class="text-[11px] text-slate-400 mt-1">.<?= number_format(($totalPending - floor($totalPending)) * 100, 0) ?> centavos</p>
    </div>
    <div class="stat-card p-5 lg:col-span-1">
        <div class="flex items-center justify-between mb-3">
            <span class="w-10 h-10 rounded-2xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            </span>
            <span class="badge-dot badge-green"><?= $countPaid ?> pagados</span>
        </div>
        <p class="text-sm text-slate-500">Cobrado</p>
        <p class="mt-1 text-3xl font-extrabold tracking-tight text-slate-900">RD$ <?= number_format($totalPaid, 0) ?></p>
        <p class="text-[11px] text-slate-400 mt-1">.<?= number_format(($totalPaid - floor($totalPaid)) * 100, 0) ?> centavos</p>
    </div>
    <div class="surface-card p-5 lg:col-span-1">
        <p class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-2">Balance</p>
        <div class="relative h-32">
            <canvas id="financeChart"></canvas>
        </div>
    </div>
</div>

<!-- Invoices list -->
<div class="surface-card overflow-hidden">
    <div class="px-6 py-5 border-b border-stone-100">
        <h3 class="text-base font-bold text-slate-900">Volantes de cobro</h3>
        <p class="text-xs text-slate-500 mt-0.5"><?= count($invoices) ?> registro(s)</p>
    </div>

    <?php if (empty($invoices)): ?>
    <div class="py-16 text-center">
        <p class="text-sm text-slate-400">No hay volantes creados aun.</p>
    </div>
    <?php else: ?>
    <ul class="divide-y divide-stone-100">
        <?php foreach ($invoices as $inv): ?>
        <li class="px-6 py-4 hover:bg-stone-50/60 transition-colors">
            <div class="flex flex-col lg:flex-row lg:items-center gap-4">
                <div class="flex items-center gap-3 lg:w-64 min-w-0">
                    <div class="h-10 w-10 rounded-full bg-stone-100 border border-stone-200 flex items-center justify-center text-xs font-bold text-slate-600 shrink-0">
                        <?= htmlspecialchars(substr(strtoupper($inv['client_name']), 0, 1)) ?>
                    </div>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-slate-900 truncate"><?= htmlspecialchars($inv['client_name']) ?></p>
                        <p class="text-xs text-slate-500 truncate"><?= htmlspecialchars($inv['concept']) ?></p>
                    </div>
                </div>

                <div class="lg:flex-1 flex flex-col lg:flex-row lg:items-center gap-3 lg:gap-6 text-sm">
                    <div>
                        <p class="text-[11px] uppercase tracking-wider text-slate-400 font-semibold">Monto</p>
                        <p class="font-bold text-slate-900">RD$ <?= number_format($inv['amount'], 2) ?></p>
                    </div>
                    <div>
                        <p class="text-[11px] uppercase tracking-wider text-slate-400 font-semibold">Vence</p>
                        <p class="font-medium text-slate-700"><?= date('d/m/Y', strtotime($inv['due_date'])) ?></p>
                    </div>
                </div>

                <div class="lg:w-32 shrink-0">
                    <?php if ($inv['status'] === 'pagado'): ?>
                    <span class="badge-dot badge-green">Pagado</span>
                    <?php else: ?>
                    <span class="badge-dot badge-red">Pendiente</span>
                    <?php endif; ?>
                </div>

                <div class="flex items-center gap-2 shrink-0">
                    <a href="invoice_pdf.php?id=<?= $inv['id'] ?>" target="_blank" title="Descargar PDF"
                       class="p-2 rounded-xl bg-stone-50 text-slate-600 hover:bg-blue-50 hover:text-blue-600 transition-colors">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    </a>
                    <?php if ($inv['client_phone']): ?>
                    <a href="<?= getInvoiceWhatsApp($inv['client_phone'], $inv['client_name'], $inv['concept'], $inv['amount'], date('d/m/Y', strtotime($inv['due_date']))) ?>" target="_blank"
                       class="p-2 rounded-xl bg-emerald-50 text-emerald-600 hover:bg-emerald-100 transition-colors" title="WhatsApp">
                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347"/></svg>
                    </a>
                    <?php endif; ?>
                    <?php if ($inv['status'] === 'pendiente'): ?>
                    <form action="admin_finances.php" method="POST">
                        <input type="hidden" name="action" value="mark_paid">
                        <input type="hidden" name="invoice_id" value="<?= $inv['id'] ?>">
                        <button type="submit" class="p-2 rounded-xl bg-stone-50 text-slate-600 hover:bg-emerald-50 hover:text-emerald-600 transition-colors" title="Marcar pagado">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        </button>
                    </form>
                    <?php endif; ?>
                    <form action="admin_finances.php" method="POST" onsubmit="return confirm('Eliminar este volante?')">
                        <input type="hidden" name="action" value="delete_invoice">
                        <input type="hidden" name="invoice_id" value="<?= $inv['id'] ?>">
                        <button type="submit" class="p-2 rounded-xl bg-stone-50 text-slate-400 hover:bg-red-50 hover:text-red-600 transition-colors" title="Eliminar">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
                        </button>
                    </form>
                </div>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<!-- Create invoice modal -->
<div id="createInvoiceModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 modal-backdrop" onclick="document.getElementById('createInvoiceModal').classList.add('hidden')"></div>
    <div class="relative flex min-h-full items-center justify-center p-4">
        <div class="w-full max-w-md rounded-3xl bg-white shadow-2xl">
            <div class="px-6 py-5 border-b border-stone-100 flex items-center justify-between">
                <h3 class="text-base font-bold text-slate-900">Crear volante</h3>
                <button type="button" onclick="document.getElementById('createInvoiceModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-700 text-2xl leading-none">&times;</button>
            </div>
            <form action="admin_finances.php" method="POST" class="p-6 space-y-4">
                <input type="hidden" name="action" value="create_invoice">
                <div>
                    <label class="field-label">Cliente</label>
                    <select name="client_id" id="invoiceClientSelect" required onchange="prefillAmount()" class="field">
                        <option value="">Selecciona un cliente...</option>
                        <?php foreach ($clients as $c): ?>
                        <option value="<?= $c['id'] ?>" data-amount="<?= $c['iguala_amount'] ?>" data-freq="<?= htmlspecialchars($c['iguala_frequency']) ?>">
                            <?= htmlspecialchars($c['name']) ?> (<?= $c['iguala_frequency'] ?> - RD$ <?= number_format($c['iguala_amount'], 2) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="field-label">Concepto</label>
                    <input type="text" name="concept" required placeholder="Ej: Iguala Mensual - Marzo 2026" class="field">
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="field-label">Monto (RD$)</label>
                        <input type="number" step="0.01" name="amount" id="invoiceAmount" required class="field">
                    </div>
                    <div>
                        <label class="field-label">Fecha limite</label>
                        <input type="date" name="due_date" required class="field">
                    </div>
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" onclick="document.getElementById('createInvoiceModal').classList.add('hidden')" class="btn-soft text-sm">Cancelar</button>
                    <button type="submit" class="btn-dark text-sm">Crear volante</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const fctx = document.getElementById('financeChart').getContext('2d');
new Chart(fctx, {
    type: 'doughnut',
    data: {
        labels: ['Cobrado', 'Pendiente'],
        datasets: [{
            data: [<?= $totalPaid ?>, <?= $totalPending ?>],
            backgroundColor: ['#10B981', '#EF4444'],
            borderWidth: 0,
            hoverOffset: 6
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
    if (amount && parseFloat(amount) > 0) {
        document.getElementById('invoiceAmount').value = amount;
    }
}
</script>

<?php include 'components/layout_end.php'; ?>
