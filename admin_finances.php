<?php
require_once 'config.php';
requireAuth('admin');

$success = $error = null;

// Handle creating invoice
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
            $success = "Volante de cobro creado exitosamente.";
        }
    } elseif ($action === 'mark_paid') {
        $invoice_id = $_POST['invoice_id'];
        $stmt = $pdo->prepare("UPDATE invoices SET status = 'pagado' WHERE id = ?");
        $stmt->execute([$invoice_id]);
        $success = "Marcado como pagado.";
    } elseif ($action === 'delete_invoice') {
        $invoice_id = $_POST['invoice_id'];
        $stmt = $pdo->prepare("DELETE FROM invoices WHERE id = ?");
        $stmt->execute([$invoice_id]);
        $success = "Volante eliminado.";
    }
}

// Finance KPIs
$totalPending = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE status='pendiente'")->fetchColumn();
$totalPaid = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE status='pagado'")->fetchColumn();
$countPending = $pdo->query("SELECT COUNT(*) FROM invoices WHERE status='pendiente'")->fetchColumn();

// Fetch all invoices
$invoices = $pdo->query("
    SELECT i.*, u.name as client_name, u.phone as client_phone 
    FROM invoices i 
    JOIN users u ON i.client_id = u.id 
    ORDER BY i.due_date ASC
")->fetchAll();

// Fetch client-side users for the form
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
    $greeting = getSetting('whatsapp_greeting', 'te escribimos de tu Asesoría Financiera');
    $message = "Hola $clientName, $greeting. Tienes un volante de cobro pendiente:\n\n📄 *$concept*\n💰 Monto: RD\$ $formattedAmount\n📅 Fecha límite: $dueDate\n\nQuedamos a tu disposición para cualquier consulta.";
    return "https://wa.me/" . $cleanPhone . "?text=" . urlencode($message);
}
?>
<!DOCTYPE html>
<html lang="es" class="h-full bg-slate-50/50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Finanzas - Portal Asesoría</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Outfit', sans-serif; }
    </style>
</head>
<body class="h-full">
    <?php include 'components/header.php'; ?>
    <?php include 'components/sidebar.php'; ?>

    <main class="lg:pl-72 py-8">
        <div class="px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto">

            <div class="sm:flex sm:items-center sm:justify-between mb-8">
                <div>
                    <h1 class="text-3xl font-bold tracking-tight text-slate-900">Finanzas</h1>
                    <p class="mt-1 text-sm text-slate-500">Control de cobros, volantes y finanzas de tus clientes.</p>
                </div>
                <button type="button" onclick="document.getElementById('createInvoiceModal').classList.remove('hidden')" class="mt-4 sm:mt-0 inline-flex items-center gap-2 rounded-2xl bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 transition-all hover:-translate-y-0.5">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                    Nuevo Volante
                </button>
            </div>

            <!-- Finance KPIs & Chart -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8 mb-8">
                <div class="lg:col-span-2 grid grid-cols-1 sm:grid-cols-3 gap-5">
                    <div class="rounded-3xl bg-white px-6 py-6 shadow-sm border border-slate-100 flex flex-col justify-center">
                        <dt class="text-sm font-medium text-slate-500">Por Cobrar</dt>
                        <dd class="mt-1 text-2xl font-bold tracking-tight text-red-600">RD$ <?= number_format($totalPending, 2) ?></dd>
                        <p class="mt-1 text-xs text-slate-400"><?= $countPending ?> volante(s) pendiente(s)</p>
                    </div>
                    <div class="rounded-3xl bg-white px-6 py-6 shadow-sm border border-slate-100 flex flex-col justify-center">
                        <dt class="text-sm font-medium text-slate-500">Cobrado</dt>
                        <dd class="mt-1 text-2xl font-bold tracking-tight text-green-600">RD$ <?= number_format($totalPaid, 2) ?></dd>
                    </div>
                    <div class="rounded-3xl bg-white px-6 py-6 shadow-sm border border-slate-100 flex flex-col justify-center">
                        <dt class="text-sm font-medium text-slate-500">Total General</dt>
                        <dd class="mt-1 text-2xl font-bold tracking-tight text-slate-900">RD$ <?= number_format($totalPending + $totalPaid, 2) ?></dd>
                    </div>
                </div>
                <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-100 h-full">
                    <h3 class="text-xs font-bold text-slate-400 uppercase tracking-wider mb-4">Estado de Cobros</h3>
                    <div class="relative h-40">
                        <canvas id="financeChart"></canvas>
                    </div>
                </div>
            </div>

            <?php if ($success): ?>
            <div class="mb-6 rounded-2xl bg-green-50 p-4 border border-green-100"><p class="text-sm font-medium text-green-800"><?= htmlspecialchars($success) ?></p></div>
            <?php endif; ?>

            <!-- Invoices Table -->
            <div class="bg-white rounded-3xl shadow-sm border border-slate-100 overflow-hidden">
                <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/50">
                    <h3 class="text-base font-semibold text-slate-900">Volantes de Cobro</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100">
                        <thead>
                            <tr>
                                <th class="py-4 pl-6 pr-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Cliente</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Concepto</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Monto</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Vencimiento</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Estado</th>
                                <th class="relative py-4 pl-3 pr-6"><span class="sr-only">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <?php if (empty($invoices)): ?>
                            <tr><td colspan="6" class="py-8 text-center text-sm text-slate-400">No hay volantes creados aún.</td></tr>
                            <?php endif; ?>
                            <?php foreach ($invoices as $inv): ?>
                            <tr class="hover:bg-slate-50/50 transition-colors group">
                                <td class="whitespace-nowrap py-4 pl-6 pr-3 text-sm font-semibold text-slate-900"><?= htmlspecialchars($inv['client_name']) ?></td>
                                <td class="px-3 py-4 text-sm text-slate-700 max-w-xs truncate"><?= htmlspecialchars($inv['concept']) ?></td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm font-bold text-slate-900">RD$ <?= number_format($inv['amount'], 2) ?></td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-slate-500"><?= date('d/m/Y', strtotime($inv['due_date'])) ?></td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm">
                                    <?php if ($inv['status'] === 'pagado'): ?>
                                        <span class="inline-flex items-center rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-700">🟢 Pagado</span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center rounded-full bg-red-100 px-2 py-0.5 text-xs font-medium text-red-700">🔴 Pendiente</span>
                                    <?php endif; ?>
                                </td>
                                <td class="whitespace-nowrap py-4 pl-3 pr-6 text-right text-sm flex justify-end gap-2 items-center">
                                    <!-- PDF -->
                                    <a href="invoice_pdf.php?id=<?= $inv['id'] ?>" target="_blank" class="p-1.5 text-slate-400 hover:text-blue-600 rounded-lg hover:bg-blue-50 transition-colors" title="Descargar PDF">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                                    </a>
                                    <!-- WhatsApp -->
                                    <?php if ($inv['client_phone']): ?>
                                    <a href="<?= getInvoiceWhatsApp($inv['client_phone'], $inv['client_name'], $inv['concept'], $inv['amount'], date('d/m/Y', strtotime($inv['due_date']))) ?>" target="_blank" class="p-1.5 text-green-600 bg-green-50 hover:bg-green-100 rounded-lg transition-colors border border-green-200/50" title="Enviar WhatsApp">
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                                    </a>
                                    <?php endif; ?>
                                    <!-- Mark Paid -->
                                    <?php if ($inv['status'] === 'pendiente'): ?>
                                    <form action="admin_finances.php" method="POST" class="inline">
                                        <input type="hidden" name="action" value="mark_paid">
                                        <input type="hidden" name="invoice_id" value="<?= $inv['id'] ?>">
                                        <button type="submit" class="p-1.5 text-slate-400 hover:text-green-600 rounded-lg hover:bg-green-50 transition-colors" title="Marcar Pagado">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <!-- Delete -->
                                    <form action="admin_finances.php" method="POST" onsubmit="return confirm('¿Eliminar este volante?')" class="inline">
                                        <input type="hidden" name="action" value="delete_invoice">
                                        <input type="hidden" name="invoice_id" value="<?= $inv['id'] ?>">
                                        <button type="submit" class="p-1.5 text-slate-400 hover:text-red-600 rounded-lg hover:bg-red-50 transition-colors" title="Eliminar">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <!-- Create Invoice Modal -->
    <div id="createInvoiceModal" class="relative z-50 hidden">
        <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm"></div>
        <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
            <div class="flex min-h-full items-center justify-center p-4">
                <div class="relative w-full max-w-lg rounded-3xl bg-white shadow-2xl p-8">
                    <h3 class="text-lg font-bold text-slate-900 mb-6">Crear Volante de Cobro</h3>
                    <form action="admin_finances.php" method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="create_invoice">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Cliente</label>
                            <select name="client_id" id="invoiceClientSelect" required onchange="prefillAmount()" class="w-full rounded-xl border-0 py-2.5 px-3 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600 sm:text-sm">
                                <option value="">Selecciona un cliente...</option>
                                <?php foreach ($clients as $c): ?>
                                <option value="<?= $c['id'] ?>" data-amount="<?= $c['iguala_amount'] ?>" data-freq="<?= htmlspecialchars($c['iguala_frequency']) ?>"><?= htmlspecialchars($c['name']) ?> (<?= $c['iguala_frequency'] ?> - RD$ <?= number_format($c['iguala_amount'], 2) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Concepto</label>
                            <input type="text" name="concept" required placeholder="Ej: Iguala Mensual - Marzo 2026" class="w-full rounded-xl border-0 py-2.5 px-3 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600 sm:text-sm">
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Monto (RD$)</label>
                                <input type="number" step="0.01" name="amount" id="invoiceAmount" required class="w-full rounded-xl border-0 py-2.5 px-3 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600 sm:text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Fecha Límite</label>
                                <input type="date" name="due_date" required class="w-full rounded-xl border-0 py-2.5 px-3 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600 sm:text-sm">
                            </div>
                        </div>
                        <div class="flex gap-3 pt-4">
                            <button type="submit" class="flex-1 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800 transition-all">Crear Volante</button>
                            <button type="button" onclick="document.getElementById('createInvoiceModal').classList.add('hidden')" class="flex-1 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 transition-all">Cancelar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div id="whatsappModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-slate-900/50" onclick="closeWhatsAppModal()"></div>
        <div class="relative flex min-h-full items-center justify-center p-4">
            <div class="w-full max-w-2xl rounded-3xl bg-white shadow-xl border border-slate-100">
                <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-slate-900">Editar mensaje de WhatsApp</h3>
                    <button type="button" onclick="closeWhatsAppModal()" class="text-slate-400 hover:text-slate-600 text-2xl leading-none">&times;</button>
                </div>
                <div class="p-6 space-y-4">
                    <textarea id="whatsappMessage" rows="10" class="w-full rounded-2xl border-slate-200 focus:border-green-500 focus:ring-green-500"></textarea>
                    <div class="flex justify-end gap-3">
                        <button type="button" onclick="closeWhatsAppModal()" class="rounded-2xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-200 hover:bg-slate-50">Cancelar</button>
                        <button type="button" onclick="sendWhatsApp()" class="rounded-2xl bg-green-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-green-700">Abrir WhatsApp</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        let whatsappUrl = '';

        function openWhatsAppModalFromUrl(url) {
            whatsappUrl = url;
            const parsed = new URL(url);
            document.getElementById('whatsappMessage').value = decodeURIComponent(parsed.searchParams.get('text') || '');
            document.getElementById('whatsappModal').classList.remove('hidden');
        }

        function closeWhatsAppModal() {
            document.getElementById('whatsappModal').classList.add('hidden');
        }

        function sendWhatsApp() {
            if (!whatsappUrl) {
                return;
            }

            const parsed = new URL(whatsappUrl);
            parsed.searchParams.set('text', document.getElementById('whatsappMessage').value);
            window.open(parsed.toString(), '_blank');
            closeWhatsAppModal();
        }

        const ctx = document.getElementById('financeChart').getContext('2d');
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: ['Cobrado', 'Pendiente'],
                datasets: [{
                    data: [<?= $totalPaid ?>, <?= $totalPending ?>],
                    backgroundColor: ['#10b981', '#ef4444'],
                    borderRadius: 8,
                    maxBarThickness: 40
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false } },
                scales: {
                    x: { beginAtZero: true, grid: { display: false }, ticks: { display: false } },
                    y: { grid: { display: false }, ticks: { font: { family: 'Outfit', weight: '600' } } }
                }
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

        document.querySelectorAll('a[href*="wa.me"]').forEach((link) => {
            link.addEventListener('click', function(event) {
                event.preventDefault();
                openWhatsAppModalFromUrl(this.href);
            });
        });
    </script>
</body>
</html>
