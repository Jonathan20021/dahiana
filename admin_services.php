<?php
require_once 'config.php';
requireAuth('admin');
requirePagePermission();

$success = $error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_service') {
        $title = trim($_POST['title'] ?? '');
        $type = $_POST['type'] ?? 'iguala';
        $deliveryDays = (int)($_POST['delivery_days'] ?? 0);
        $deliveryLabel = trim($_POST['delivery_label'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if ($title !== '') {
            $pdo->prepare("INSERT INTO services (title, type, delivery_days, delivery_label, description, is_active) VALUES (?,?,?,?,?,?)")
                ->execute([$title, $type, $deliveryDays > 0 ? $deliveryDays : null, $deliveryLabel ?: null, $description ?: null, $isActive]);
            $success = "Servicio agregado.";
        } else {
            $error = "Falta el nombre del servicio.";
        }
    } elseif ($action === 'edit_service') {
        $id = (int)($_POST['service_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $type = $_POST['type'] ?? 'iguala';
        $deliveryDays = (int)($_POST['delivery_days'] ?? 0);
        $deliveryLabel = trim($_POST['delivery_label'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if ($title !== '' && $id > 0) {
            $pdo->prepare("UPDATE services SET title=?, type=?, delivery_days=?, delivery_label=?, description=?, is_active=? WHERE id=?")
                ->execute([$title, $type, $deliveryDays > 0 ? $deliveryDays : null, $deliveryLabel ?: null, $description ?: null, $isActive, $id]);
            $success = "Servicio actualizado.";
        }
    } elseif ($action === 'delete_service') {
        $id = (int)($_POST['service_id'] ?? 0);
        $pdo->prepare("DELETE FROM services WHERE id = ?")->execute([$id]);
        $success = "Servicio eliminado.";
    } elseif ($action === 'toggle_active') {
        $id = (int)($_POST['service_id'] ?? 0);
        $pdo->prepare("UPDATE services SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
        $success = "Estado del servicio actualizado.";
    }
}

$services = $pdo->query("SELECT * FROM services ORDER BY type, title")->fetchAll();
$igualas = array_filter($services, fn($s) => $s['type'] === 'iguala');
$puntuales = array_filter($services, fn($s) => $s['type'] === 'puntual');

$page_title = 'Servicios';
$page_subtitle = 'Catalogo, tiempos de entrega y descripcion que vera el cliente.';
$page_actions = '<button type="button" onclick="openAddModal()" class="btn-dark text-sm">
    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
    Nuevo servicio
</button>';
$main_max = 'max-w-6xl';
include 'components/layout_start.php';
?>

<?php if ($success): ?>
<div class="mb-4 rounded-2xl bg-emerald-50 px-4 py-3 border border-emerald-100 text-sm font-medium text-emerald-800 flex items-center gap-2">
    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
    <?= htmlspecialchars($success) ?>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="mb-4 rounded-2xl bg-red-50 px-4 py-3 border border-red-100 text-sm font-medium text-red-700"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
    <?php
    $sections = [
        ['key' => 'iguala',   'title' => 'Igualas mensuales',  'subtitle' => 'Servicios recurrentes', 'color' => 'blue',   'items' => $igualas,
         'icon' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>'],
        ['key' => 'puntual',  'title' => 'Solicitudes puntuales', 'subtitle' => 'Servicios de una sola vez', 'color' => 'indigo', 'items' => $puntuales,
         'icon' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>'],
    ];
    foreach ($sections as $sec):
    ?>
    <div class="surface-card overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-center gap-3">
            <div class="w-10 h-10 rounded-2xl bg-<?= $sec['color'] ?>-50 text-<?= $sec['color'] ?>-600 flex items-center justify-center">
                <?= $sec['icon'] ?>
            </div>
            <div>
                <h3 class="text-sm font-bold text-slate-900"><?= htmlspecialchars($sec['title']) ?></h3>
                <p class="text-[11px] text-slate-500"><?= count($sec['items']) ?> servicio(s) · <?= htmlspecialchars($sec['subtitle']) ?></p>
            </div>
        </div>
        <?php if (empty($sec['items'])): ?>
        <p class="px-5 py-8 text-center text-xs text-slate-400">Sin servicios registrados. Crea el primero arriba.</p>
        <?php else: ?>
        <ul class="divide-y divide-slate-100">
            <?php foreach ($sec['items'] as $s):
                $delivery = formatServiceDelivery($s);
                $isActive = (int)($s['is_active'] ?? 1) === 1;
            ?>
            <li class="sv-row <?= !$isActive ? 'sv-row-inactive' : '' ?>">
                <div class="sv-main">
                    <div class="flex items-center gap-2 flex-wrap">
                        <p class="sv-title"><?= htmlspecialchars($s['title']) ?></p>
                        <?php if (!$isActive): ?><span class="sv-tag-off">Inactivo</span><?php endif; ?>
                    </div>
                    <?php if ($s['description']): ?>
                    <p class="sv-desc"><?= htmlspecialchars($s['description']) ?></p>
                    <?php endif; ?>
                    <div class="sv-meta">
                        <?php if ($delivery !== ''): ?>
                        <span class="sv-pill sv-pill-clock">
                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <?= htmlspecialchars($delivery) ?>
                        </span>
                        <?php else: ?>
                        <span class="sv-pill sv-pill-warn">Sin tiempo de entrega</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="sv-actions">
                    <button type="button"
                        onclick='openEditModal(<?= htmlspecialchars(json_encode([
                            "id" => (int)$s["id"],
                            "title" => $s["title"],
                            "type" => $s["type"],
                            "delivery_days" => $s["delivery_days"],
                            "delivery_label" => $s["delivery_label"] ?? "",
                            "description" => $s["description"] ?? "",
                            "is_active" => (int)($s["is_active"] ?? 1),
                        ]), ENT_QUOTES) ?>)'
                        class="sv-icon-btn" title="Editar">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    </button>
                    <form action="admin_services.php" method="POST" class="inline">
                        <input type="hidden" name="action" value="toggle_active">
                        <input type="hidden" name="service_id" value="<?= (int)$s['id'] ?>">
                        <button type="submit" class="sv-icon-btn" title="<?= $isActive ? 'Pausar' : 'Activar' ?>">
                            <?php if ($isActive): ?>
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <?php else: ?>
                            <svg class="w-3.5 h-3.5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <?php endif; ?>
                        </button>
                    </form>
                    <form action="admin_services.php" method="POST" onsubmit="return confirm('Eliminar este servicio? No afecta a clientes que ya lo tienen asignado.')" class="inline">
                        <input type="hidden" name="action" value="delete_service">
                        <input type="hidden" name="service_id" value="<?= (int)$s['id'] ?>">
                        <button type="submit" class="sv-icon-btn sv-icon-danger" title="Eliminar">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
                        </button>
                    </form>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- Modal Add/Edit (unificado) -->
<div id="serviceModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 modal-backdrop" onclick="closeModal()"></div>
    <div class="relative flex min-h-full items-center justify-center p-4">
        <div class="w-full max-w-xl rounded-3xl bg-white shadow-2xl max-h-[90vh] flex flex-col">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                <div>
                    <h3 id="modalTitle" class="text-base font-bold text-slate-900">Nuevo servicio</h3>
                    <p class="text-xs text-slate-500 mt-0.5">El tiempo de entrega se mostrara automaticamente en el portal del cliente.</p>
                </div>
                <button type="button" onclick="closeModal()" class="text-slate-400 hover:text-slate-700 text-2xl leading-none">&times;</button>
            </div>
            <form action="admin_services.php" method="POST" class="flex-1 overflow-y-auto p-6 space-y-4">
                <input type="hidden" name="action" id="modalAction" value="add_service">
                <input type="hidden" name="service_id" id="modalServiceId" value="">

                <div>
                    <label class="field-label">Nombre del servicio</label>
                    <input type="text" name="title" id="modalTitleInput" required class="field" placeholder="Ej: Renovacion Registro Mercantil">
                </div>
                <div>
                    <label class="field-label">Tipo</label>
                    <select name="type" id="modalType" class="field">
                        <option value="iguala">Iguala mensual (servicio recurrente)</option>
                        <option value="puntual">Solicitud puntual (servicio de una vez)</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="field-label">Dias habiles de entrega</label>
                        <input type="number" name="delivery_days" id="modalDays" min="0" max="365" class="field" placeholder="Ej: 5">
                        <p class="text-[10px] text-slate-400 mt-1">El sistema calcula la fecha estimada automatic.</p>
                    </div>
                    <div>
                        <label class="field-label">Texto personalizado (opcional)</label>
                        <input type="text" name="delivery_label" id="modalLabel" class="field" placeholder="Ej: 3-5 dias habiles">
                        <p class="text-[10px] text-slate-400 mt-1">Si lo llenas, sobreescribe el calculo de dias.</p>
                    </div>
                </div>
                <div>
                    <label class="field-label">Descripcion (opcional)</label>
                    <textarea name="description" id="modalDesc" rows="3" class="field" placeholder="Lo que incluye, requisitos, etc. Se muestra al cliente."></textarea>
                </div>
                <label class="flex items-center gap-2 cursor-pointer p-3 rounded-xl border border-slate-200 bg-slate-50/50">
                    <input type="checkbox" name="is_active" id="modalActive" value="1" checked>
                    <div>
                        <p class="text-xs font-semibold text-slate-900">Servicio activo</p>
                        <p class="text-[10.5px] text-slate-500">Si lo desactivas, no se podra asignar a nuevos clientes pero los existentes se mantienen.</p>
                    </div>
                </label>

                <div class="flex justify-end gap-2 pt-3 border-t border-slate-100 sticky bottom-0 bg-white -mx-6 -mb-6 px-6 py-4">
                    <button type="button" onclick="closeModal()" class="btn-soft text-sm">Cancelar</button>
                    <button type="submit" class="btn-dark text-sm">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .sv-row { display: flex; gap: 12px; padding: 14px 18px; transition: background .15s ease; align-items: flex-start; }
    .sv-row:hover { background: #FAFAFA; }
    .sv-row-inactive { opacity: 0.6; }
    .sv-main { flex: 1; min-width: 0; }
    .sv-title { font-size: 13.5px; font-weight: 700; color: #0F172A; line-height: 1.3; }
    .sv-desc { font-size: 11.5px; color: #64748B; margin-top: 3px; line-height: 1.4; }
    .sv-meta { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
    .sv-pill { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 999px; font-size: 10.5px; font-weight: 700; }
    .sv-pill-clock { background: #EFF6FF; color: #2563EB; }
    .sv-pill-warn { background: #FFFBEB; color: #B45309; }
    .sv-tag-off { display: inline-block; padding: 1px 7px; background: #F1F5F9; color: #475569; border-radius: 999px; font-size: 9.5px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; }
    .sv-actions { display: inline-flex; gap: 4px; flex-shrink: 0; }
    .sv-icon-btn { width: 30px; height: 30px; border-radius: 10px; background: #F4F4F5; color: #64748B; display: inline-flex; align-items: center; justify-content: center; transition: all .12s ease; border: 0; cursor: pointer; }
    .sv-icon-btn:hover { background: #E5E7EB; color: #0F172A; }
    .sv-icon-danger:hover { background: #FEE2E2; color: #DC2626; }
</style>

<script>
function openModal() { document.getElementById('serviceModal').classList.remove('hidden'); document.body.style.overflow = 'hidden'; }
function closeModal() { document.getElementById('serviceModal').classList.add('hidden'); document.body.style.overflow = ''; }
function openAddModal() {
    document.getElementById('modalTitle').textContent = 'Nuevo servicio';
    document.getElementById('modalAction').value = 'add_service';
    document.getElementById('modalServiceId').value = '';
    document.getElementById('modalTitleInput').value = '';
    document.getElementById('modalType').value = 'iguala';
    document.getElementById('modalDays').value = '';
    document.getElementById('modalLabel').value = '';
    document.getElementById('modalDesc').value = '';
    document.getElementById('modalActive').checked = true;
    openModal();
}
function openEditModal(s) {
    document.getElementById('modalTitle').textContent = 'Editar servicio';
    document.getElementById('modalAction').value = 'edit_service';
    document.getElementById('modalServiceId').value = s.id;
    document.getElementById('modalTitleInput').value = s.title;
    document.getElementById('modalType').value = s.type;
    document.getElementById('modalDays').value = s.delivery_days || '';
    document.getElementById('modalLabel').value = s.delivery_label || '';
    document.getElementById('modalDesc').value = s.description || '';
    document.getElementById('modalActive').checked = !!s.is_active;
    openModal();
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });
</script>

<?php include 'components/layout_end.php'; ?>
