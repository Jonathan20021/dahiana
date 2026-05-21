<?php
require_once 'config.php';
requireAuth('admin');
requirePagePermission();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $request_id = $_POST['request_id'];
    $status = $_POST['status'];
    $stmt = $pdo->prepare("UPDATE requests SET status = ? WHERE id = ?");
    $stmt->execute([$status, $request_id]);
    $success = "Estado actualizado correctamente.";
}

$filter = $_GET['status'] ?? 'all';
$scopeR = clientScopeWhere('r.client_id');
$where = "WHERE {$scopeR}";
if (in_array($filter, ['pendiente','en_proceso','en_revision','presentado','completado'], true)) {
    $where .= " AND r.status = " . $pdo->quote($filter);
}

$stmt = $pdo->query("
    SELECT r.*, s.title, s.type, u.name as client_name, u.phone as client_phone
    FROM requests r
    JOIN services s ON r.service_id = s.id
    JOIN users u ON r.client_id = u.id
    $where
    ORDER BY r.created_at DESC
");
$requests = $stmt->fetchAll();

// Counters for tabs (con scope)
$counters = $pdo->query("SELECT status, COUNT(*) c FROM requests r WHERE {$scopeR} GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$totalAll = array_sum($counters);

function getRequestStatusText($status) {
    return match ($status) {
        'pendiente'   => 'pendiente por informacion',
        'en_proceso'  => 'en proceso de trabajo',
        'en_revision' => 'en revision final',
        'presentado'  => 'presentado ante la DGII',
        'completado'  => 'completado y entregado',
        default       => 'en actualizacion',
    };
}

$requestTemplate = getWhatsAppTemplate(
    'whatsapp_request_template',
    "Hola {{client_name}}, {{greeting}} para recordarte que el tramite de *{{request_title}}* se encuentra actualmente *{{status_text}}*."
);
$whatsAppGreeting = getWhatsAppTemplate('whatsapp_greeting', 'te escribimos de tu Asesoria Financiera');

$page_title = 'Solicitudes';
$page_subtitle = 'Vista global de los tramites en curso de toda tu cartera.';
include 'components/layout_start.php';
?>

<?php if (isset($success)): ?>
<div class="mb-4 rounded-2xl bg-emerald-50 px-4 py-3 border border-emerald-100 text-sm font-medium text-emerald-800"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<!-- Filter tabs -->
<div class="surface-card p-2 mb-4 flex overflow-x-auto scroll-area gap-1">
    <?php
    $tabs = [
        'all' => ['Todas', $totalAll, 'badge-slate'],
        'pendiente' => ['Pendientes', $counters['pendiente'] ?? 0, 'badge-red'],
        'en_proceso' => ['En proceso', $counters['en_proceso'] ?? 0, 'badge-amber'],
        'en_revision' => ['En revision', $counters['en_revision'] ?? 0, 'badge-blue'],
        'presentado' => ['Presentadas', $counters['presentado'] ?? 0, 'badge-green'],
        'completado' => ['Completadas', $counters['completado'] ?? 0, 'badge-green'],
    ];
    foreach ($tabs as $key => [$label, $count, $color]):
        $active = $filter === $key;
    ?>
    <a href="?status=<?= $key ?>"
       class="inline-flex items-center gap-2 rounded-2xl px-4 py-2 text-sm whitespace-nowrap transition-all
              <?= $active ? 'bg-slate-900 text-white font-semibold' : 'text-slate-600 hover:bg-stone-50 font-medium' ?>">
        <?= $label ?>
        <span class="text-[11px] font-bold rounded-full px-2 py-0.5 <?= $active ? 'bg-white/20 text-white' : 'bg-stone-100 text-slate-500' ?>"><?= $count ?></span>
    </a>
    <?php endforeach; ?>
</div>

<!-- Requests list -->
<div class="surface-card overflow-hidden">
    <div class="px-6 py-5 border-b border-stone-100 flex items-center justify-between">
        <div>
            <h3 class="text-base font-bold text-slate-900">Registro de tramites</h3>
            <p class="text-xs text-slate-500 mt-0.5">Mostrando <?= count($requests) ?> resultado(s)</p>
        </div>
    </div>

    <?php if (empty($requests)): ?>
    <div class="py-16 text-center">
        <div class="w-14 h-14 mx-auto rounded-full bg-stone-100 flex items-center justify-center mb-3">
            <svg class="w-6 h-6 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        </div>
        <p class="text-sm text-slate-500">No hay solicitudes para este filtro.</p>
    </div>
    <?php else: ?>
    <ul class="divide-y divide-stone-100">
        <?php foreach ($requests as $req):
            $requestMessage = '';
            if ($req['client_phone']) {
                $requestMessage = renderWhatsAppTemplate($requestTemplate, [
                    'client_name' => $req['client_name'],
                    'greeting' => $whatsAppGreeting,
                    'request_title' => $req['title'],
                    'status_text' => getRequestStatusText($req['status']),
                ]);
            }
        ?>
        <li class="px-6 py-4 hover:bg-stone-50/60 transition-colors group">
            <div class="flex flex-col lg:flex-row lg:items-center gap-4">
                <!-- Client + service -->
                <div class="flex items-center gap-3 lg:w-72 min-w-0">
                    <div class="h-10 w-10 rounded-full bg-stone-100 border border-stone-200 flex items-center justify-center text-xs font-bold text-slate-600 shrink-0">
                        <?= htmlspecialchars(substr(strtoupper($req['client_name']), 0, 1)) ?>
                    </div>
                    <div class="min-w-0">
                        <a href="client_details.php?id=<?= $req['client_id'] ?>" class="text-sm font-semibold text-slate-900 hover:text-blue-600 truncate block"><?= htmlspecialchars($req['client_name']) ?></a>
                        <div class="flex items-center gap-2 mt-0.5">
                            <span class="text-[10px] font-bold uppercase tracking-wider rounded-md px-1.5 py-0.5 <?= $req['type'] === 'iguala' ? 'bg-blue-50 text-blue-700' : 'bg-indigo-50 text-indigo-700' ?>"><?= $req['type'] === 'iguala' ? 'Iguala' : 'Puntual' ?></span>
                            <span class="text-xs text-slate-500 truncate"><?= htmlspecialchars($req['title']) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Times -->
                <div class="lg:flex-1 text-xs text-slate-500">
                    <?php if ($req['type'] === 'iguala'): ?>
                        Periodo: <span class="font-semibold text-slate-700"><?= htmlspecialchars($req['period']) ?></span>
                    <?php else: ?>
                        Entrega: <span class="font-semibold text-slate-700"><?= $req['estimated_delivery_date'] ? date('d/m/Y', strtotime($req['estimated_delivery_date'])) : 'No definida' ?></span>
                    <?php endif; ?>
                </div>

                <!-- Status badge -->
                <div class="lg:w-32 shrink-0">
                    <?= getStatusBadge($req['status']) ?>
                </div>

                <!-- Actions -->
                <div class="flex items-center gap-2 lg:w-auto shrink-0">
                    <form action="admin_requests.php<?= $filter !== 'all' ? '?status=' . urlencode($filter) : '' ?>" method="POST" class="inline-flex">
                        <input type="hidden" name="action" value="update_status">
                        <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                        <select name="status" onchange="this.form.submit()" class="text-xs font-medium rounded-xl border border-stone-200 bg-white px-3 py-2 focus:outline-none focus:ring-2 focus:ring-slate-200">
                            <option value="pendiente" <?= $req['status'] === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                            <option value="en_proceso" <?= $req['status'] === 'en_proceso' ? 'selected' : '' ?>>En proceso</option>
                            <option value="en_revision" <?= $req['status'] === 'en_revision' ? 'selected' : '' ?>>En revision</option>
                            <?php if ($req['type'] === 'iguala'): ?>
                            <option value="presentado" <?= $req['status'] === 'presentado' ? 'selected' : '' ?>>Presentado</option>
                            <?php else: ?>
                            <option value="completado" <?= $req['status'] === 'completado' ? 'selected' : '' ?>>Completado</option>
                            <?php endif; ?>
                        </select>
                    </form>

                    <a href="request_view.php?id=<?= $req['id'] ?>" title="Abrir tramite" class="p-2 rounded-xl bg-stone-50 text-slate-600 hover:bg-blue-50 hover:text-blue-600 transition-colors">
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.86 9.86 0 01-4-.8L3 20l1.3-3.9A8.91 8.91 0 013 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                    </a>

                    <?php if ($req['client_phone']): ?>
                    <button type="button" title="Recordatorio WhatsApp"
                            data-phone="<?= htmlspecialchars(normalizePhoneForWhatsApp($req['client_phone'])) ?>"
                            data-message="<?= htmlspecialchars($requestMessage) ?>"
                            onclick="openWhatsAppModal(this)"
                            class="p-2 rounded-xl bg-emerald-50 text-emerald-600 hover:bg-emerald-100 transition-colors">
                        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347"/></svg>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<!-- WhatsApp modal -->
<div id="whatsappModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 modal-backdrop" onclick="closeWhatsAppModal()"></div>
    <div class="relative flex min-h-full items-center justify-center p-4">
        <div class="w-full max-w-xl rounded-3xl bg-white shadow-2xl">
            <div class="px-6 py-5 border-b border-stone-100 flex items-center justify-between">
                <h3 class="text-base font-bold text-slate-900">Editar mensaje WhatsApp</h3>
                <button type="button" onclick="closeWhatsAppModal()" class="text-slate-400 hover:text-slate-700 text-2xl leading-none">&times;</button>
            </div>
            <div class="p-6 space-y-4">
                <textarea id="whatsappMessage" rows="8" class="field" style="resize: vertical;"></textarea>
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeWhatsAppModal()" class="btn-soft text-sm">Cancelar</button>
                    <button type="button" onclick="sendWhatsApp()" class="btn-dark text-sm bg-emerald-600 hover:bg-emerald-700">Abrir WhatsApp</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let whatsappPhone = '';
function openWhatsAppModal(button) {
    whatsappPhone = button.dataset.phone || '';
    document.getElementById('whatsappMessage').value = button.dataset.message || '';
    document.getElementById('whatsappModal').classList.remove('hidden');
}
function closeWhatsAppModal() {
    document.getElementById('whatsappModal').classList.add('hidden');
}
function sendWhatsApp() {
    const message = document.getElementById('whatsappMessage').value;
    if (!whatsappPhone || !message) return;
    window.open(`https://wa.me/${whatsappPhone}?text=${encodeURIComponent(message)}`, '_blank');
    closeWhatsAppModal();
}
</script>

<?php include 'components/layout_end.php'; ?>
