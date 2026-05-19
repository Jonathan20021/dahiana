<?php
require_once 'config.php';
requireAuth('admin');

$success = $error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_service') {
        $title = trim($_POST['title'] ?? '');
        $type = $_POST['type'] ?? 'iguala';
        if ($title) {
            $pdo->prepare("INSERT INTO services (title, type) VALUES (?, ?)")->execute([$title, $type]);
            $success = "Servicio agregado exitosamente.";
        }
    } elseif ($action === 'edit_service') {
        $id = $_POST['service_id'];
        $title = trim($_POST['title'] ?? '');
        $type = $_POST['type'] ?? 'iguala';
        if ($title && $id) {
            $pdo->prepare("UPDATE services SET title = ?, type = ? WHERE id = ?")->execute([$title, $type, $id]);
            $success = "Servicio actualizado.";
        }
    } elseif ($action === 'delete_service') {
        $id = $_POST['service_id'];
        $pdo->prepare("DELETE FROM services WHERE id = ?")->execute([$id]);
        $success = "Servicio eliminado.";
    }
}

$services = $pdo->query("SELECT * FROM services ORDER BY type, title")->fetchAll();
$igualas = array_filter($services, fn($s) => $s['type'] === 'iguala');
$puntuales = array_filter($services, fn($s) => $s['type'] === 'puntual');

$page_title = 'Servicios';
$page_subtitle = 'Catalogo de servicios que ofreces a tus clientes.';
$page_actions = '<button type="button" onclick="document.getElementById(\'addServiceModal\').classList.remove(\'hidden\')"
    class="inline-flex items-center gap-2 btn-dark text-sm">
    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
    Nuevo servicio
</button>';
$main_max = 'max-w-5xl';
include 'components/layout_start.php';
?>

<?php if ($success): ?>
<div class="mb-4 rounded-2xl bg-emerald-50 px-4 py-3 border border-emerald-100 text-sm font-medium text-emerald-800"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <!-- Igualas -->
    <div class="surface-card overflow-hidden">
        <div class="px-6 py-5 border-b border-stone-100 flex items-center gap-3">
            <div class="p-2 bg-blue-50 text-blue-600 rounded-2xl">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            </div>
            <div>
                <h3 class="text-base font-bold text-slate-900">Igualas mensuales</h3>
                <p class="text-xs text-slate-500"><?= count($igualas) ?> servicio(s)</p>
            </div>
        </div>
        <ul class="divide-y divide-stone-100">
            <?php if (empty($igualas)): ?>
            <li class="px-6 py-10 text-center text-sm text-slate-400">Sin servicios de iguala registrados.</li>
            <?php endif; ?>
            <?php foreach ($igualas as $s): ?>
            <li class="px-6 py-3.5 flex items-center justify-between hover:bg-stone-50/60 transition-colors group">
                <span class="text-sm font-medium text-slate-800"><?= htmlspecialchars($s['title']) ?></span>
                <div class="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                    <button onclick="openEditModal(<?= $s['id'] ?>, '<?= htmlspecialchars(addslashes($s['title'])) ?>', '<?= $s['type'] ?>')"
                            class="p-2 rounded-xl bg-stone-50 text-slate-500 hover:bg-blue-50 hover:text-blue-600 transition-colors" title="Editar">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    </button>
                    <form action="admin_services.php" method="POST" onsubmit="return confirm('Eliminar este servicio?')">
                        <input type="hidden" name="action" value="delete_service">
                        <input type="hidden" name="service_id" value="<?= $s['id'] ?>">
                        <button type="submit" class="p-2 rounded-xl bg-stone-50 text-slate-400 hover:bg-red-50 hover:text-red-600 transition-colors" title="Eliminar">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
                        </button>
                    </form>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <!-- Puntuales -->
    <div class="surface-card overflow-hidden">
        <div class="px-6 py-5 border-b border-stone-100 flex items-center gap-3">
            <div class="p-2 bg-indigo-50 text-indigo-600 rounded-2xl">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            </div>
            <div>
                <h3 class="text-base font-bold text-slate-900">Solicitudes puntuales</h3>
                <p class="text-xs text-slate-500"><?= count($puntuales) ?> servicio(s)</p>
            </div>
        </div>
        <ul class="divide-y divide-stone-100">
            <?php if (empty($puntuales)): ?>
            <li class="px-6 py-10 text-center text-sm text-slate-400">Sin servicios puntuales registrados.</li>
            <?php endif; ?>
            <?php foreach ($puntuales as $s): ?>
            <li class="px-6 py-3.5 flex items-center justify-between hover:bg-stone-50/60 transition-colors group">
                <span class="text-sm font-medium text-slate-800"><?= htmlspecialchars($s['title']) ?></span>
                <div class="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                    <button onclick="openEditModal(<?= $s['id'] ?>, '<?= htmlspecialchars(addslashes($s['title'])) ?>', '<?= $s['type'] ?>')"
                            class="p-2 rounded-xl bg-stone-50 text-slate-500 hover:bg-blue-50 hover:text-blue-600 transition-colors" title="Editar">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    </button>
                    <form action="admin_services.php" method="POST" onsubmit="return confirm('Eliminar este servicio?')">
                        <input type="hidden" name="action" value="delete_service">
                        <input type="hidden" name="service_id" value="<?= $s['id'] ?>">
                        <button type="submit" class="p-2 rounded-xl bg-stone-50 text-slate-400 hover:bg-red-50 hover:text-red-600 transition-colors" title="Eliminar">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
                        </button>
                    </form>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<!-- Add modal -->
<div id="addServiceModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 modal-backdrop" onclick="document.getElementById('addServiceModal').classList.add('hidden')"></div>
    <div class="relative flex min-h-full items-center justify-center p-4">
        <div class="w-full max-w-md rounded-3xl bg-white shadow-2xl">
            <div class="px-6 py-5 border-b border-stone-100 flex items-center justify-between">
                <h3 class="text-base font-bold text-slate-900">Agregar servicio</h3>
                <button type="button" onclick="document.getElementById('addServiceModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-700 text-2xl leading-none">&times;</button>
            </div>
            <form action="admin_services.php" method="POST" class="p-6 space-y-4">
                <input type="hidden" name="action" value="add_service">
                <div>
                    <label class="field-label">Nombre del servicio</label>
                    <input type="text" name="title" required class="field">
                </div>
                <div>
                    <label class="field-label">Tipo</label>
                    <select name="type" class="field">
                        <option value="iguala">Iguala mensual</option>
                        <option value="puntual">Solicitud puntual</option>
                    </select>
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" onclick="document.getElementById('addServiceModal').classList.add('hidden')" class="btn-soft text-sm">Cancelar</button>
                    <button type="submit" class="btn-dark text-sm">Guardar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit modal -->
<div id="editServiceModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 modal-backdrop" onclick="document.getElementById('editServiceModal').classList.add('hidden')"></div>
    <div class="relative flex min-h-full items-center justify-center p-4">
        <div class="w-full max-w-md rounded-3xl bg-white shadow-2xl">
            <div class="px-6 py-5 border-b border-stone-100 flex items-center justify-between">
                <h3 class="text-base font-bold text-slate-900">Editar servicio</h3>
                <button type="button" onclick="document.getElementById('editServiceModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-700 text-2xl leading-none">&times;</button>
            </div>
            <form action="admin_services.php" method="POST" class="p-6 space-y-4">
                <input type="hidden" name="action" value="edit_service">
                <input type="hidden" name="service_id" id="edit_service_id">
                <div>
                    <label class="field-label">Nombre del servicio</label>
                    <input type="text" name="title" id="edit_service_title" required class="field">
                </div>
                <div>
                    <label class="field-label">Tipo</label>
                    <select name="type" id="edit_service_type" class="field">
                        <option value="iguala">Iguala mensual</option>
                        <option value="puntual">Solicitud puntual</option>
                    </select>
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" onclick="document.getElementById('editServiceModal').classList.add('hidden')" class="btn-soft text-sm">Cancelar</button>
                    <button type="submit" class="btn-dark text-sm">Actualizar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openEditModal(id, title, type) {
    document.getElementById('edit_service_id').value = id;
    document.getElementById('edit_service_title').value = title;
    document.getElementById('edit_service_type').value = type;
    document.getElementById('editServiceModal').classList.remove('hidden');
}
</script>

<?php include 'components/layout_end.php'; ?>
