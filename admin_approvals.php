<?php
require_once 'config.php';
requireAuth('admin');

$success = $error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $uid = (int)($_POST['user_id'] ?? 0);

    if ($uid > 0 && $action === 'approve') {
        $res = signupApproveUser($uid, $_SESSION['user_id'] ?? null);
        if ($res['ok']) {
            $success = "Cliente aprobado. Se crearon {$res['requests_created']} solicitudes de servicios.";
        } else {
            $error = $res['error'];
        }
    } elseif ($uid > 0 && $action === 'reject') {
        $reason = trim($_POST['reason'] ?? '');
        signupRejectUser($uid, $reason, $_SESSION['user_id'] ?? null);
        $success = 'Solicitud rechazada.';
    } elseif ($uid > 0 && $action === 'edit') {
        // Permitir al admin editar datos antes de aprobar
        $fields = [
            'name','email','phone','rnc','business_name','business_type','address',
            'tax_regime','economic_activity','operation_type','employee_count','notes'
        ];
        $sql = "UPDATE users SET " . implode(',', array_map(fn($f) => "$f = ?", $fields)) . " WHERE id = ? AND approval_status='pending_approval'";
        $values = [];
        foreach ($fields as $f) $values[] = trim((string)($_POST[$f] ?? ''));
        $values[] = $uid;
        try {
            $pdo->prepare($sql)->execute($values);
            $success = 'Datos del cliente actualizados antes de aprobar.';
        } catch (PDOException $e) {
            $error = 'No se pudieron guardar los cambios.';
        }
    }
}

$filter = $_GET['filter'] ?? 'pending';
$valid  = ['pending','rejected','approved','all'];
if (!in_array($filter, $valid, true)) $filter = 'pending';

$where = "u.role <> 'admin'";
$params = [];
if ($filter === 'pending')  { $where .= " AND u.approval_status = 'pending_approval'"; }
elseif ($filter === 'rejected') { $where .= " AND u.approval_status = 'rejected'"; }
elseif ($filter === 'approved') { $where .= " AND u.approval_status = 'approved' AND u.registered_via = 'public_signup'"; }
// 'all' = todos los que vinieron por public_signup
if ($filter === 'all') $where .= " AND u.registered_via = 'public_signup'";

$stmt = $pdo->prepare("
    SELECT u.*, approver.name AS approver_name,
           (SELECT COUNT(*) FROM signup_requested_services WHERE user_id = u.id) AS services_requested
    FROM users u
    LEFT JOIN users approver ON approver.id = u.approved_by
    WHERE {$where}
    ORDER BY
      CASE u.approval_status WHEN 'pending_approval' THEN 0 WHEN 'rejected' THEN 1 ELSE 2 END,
      u.created_at DESC
");
$stmt->execute($params);
$users = $stmt->fetchAll();

$pendingCount = signupPendingCount();

$page_title = 'Aprobaciones de clientes';
$page_subtitle = 'Revisa y aprueba las solicitudes de registro publico.';
include 'components/layout_start.php';
?>

<?php if ($success): ?>
<div class="mb-4 rounded-2xl bg-emerald-50 px-4 py-3 border border-emerald-100 text-sm font-medium text-emerald-800"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="mb-4 rounded-2xl bg-red-50 px-4 py-3 border border-red-100 text-sm font-medium text-red-700"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Tabs -->
<div class="surface-card p-2 mb-4 flex gap-2 overflow-x-auto scroll-area">
    <?php
    $tabs = [
        'pending'  => ['label' => 'Pendientes', 'count' => $pendingCount, 'cls' => 'badge-amber'],
        'approved' => ['label' => 'Aprobadas',  'count' => null, 'cls' => 'badge-green'],
        'rejected' => ['label' => 'Rechazadas', 'count' => null, 'cls' => 'badge-slate'],
        'all'      => ['label' => 'Todas',      'count' => null, 'cls' => 'badge-slate'],
    ];
    foreach ($tabs as $tk => $tab):
        $active = $filter === $tk;
    ?>
    <a href="?filter=<?= $tk ?>" class="whitespace-nowrap rounded-2xl px-4 py-2 text-sm font-bold transition-colors <?= $active ? 'bg-slate-900 text-white' : 'bg-stone-100 text-slate-600 hover:bg-stone-200' ?>">
        <?= $tab['label'] ?>
        <?php if ($tab['count']): ?>
        <span class="ml-1.5 inline-flex items-center px-1.5 rounded-full bg-white/20 text-[10px]"><?= $tab['count'] ?></span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
    <a href="admin_signup_settings.php" class="ml-auto text-sm text-blue-600 hover:text-blue-800 self-center px-3">Personalizar form &rarr;</a>
</div>

<?php if (empty($users)): ?>
<div class="surface-card p-10 text-center">
    <svg class="w-12 h-12 mx-auto text-slate-300 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z"/></svg>
    <p class="text-sm text-slate-400">
        <?= $filter === 'pending' ? 'Sin solicitudes pendientes ahora.' : 'Nada por aqui.' ?>
    </p>
</div>
<?php else: ?>

<div class="space-y-3">
    <?php foreach ($users as $u):
        $reqServices = $pdo->prepare("SELECT s.title, s.type FROM signup_requested_services r JOIN services s ON s.id = r.service_id WHERE r.user_id = ? ORDER BY s.type, s.title");
        $reqServices->execute([$u['id']]);
        $servicesList = $reqServices->fetchAll();
        $statusBadge = match($u['approval_status']) {
            'pending_approval' => '<span class="badge-dot badge-amber">Pendiente</span>',
            'approved'         => '<span class="badge-dot badge-green">Aprobado</span>',
            'rejected'         => '<span class="badge-dot badge-red">Rechazado</span>',
            default            => '<span class="badge-dot badge-slate">' . htmlspecialchars($u['approval_status']) . '</span>',
        };
    ?>
    <div class="surface-card overflow-hidden" id="user-<?= $u['id'] ?>">
        <div class="p-5">
            <div class="flex flex-col lg:flex-row lg:items-start gap-4">
                <div class="flex items-start gap-3 lg:flex-1 min-w-0">
                    <div class="w-11 h-11 rounded-2xl bg-stone-100 border border-stone-200 flex items-center justify-center text-sm font-bold text-slate-700 shrink-0">
                        <?= htmlspecialchars(strtoupper(substr($u['name'], 0, 1))) ?>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2 mb-1">
                            <p class="text-base font-bold text-slate-900 truncate"><?= htmlspecialchars($u['name']) ?></p>
                            <?= $statusBadge ?>
                        </div>
                        <p class="text-xs text-slate-500"><?= htmlspecialchars($u['email']) ?> <?= $u['phone'] ? '· ' . htmlspecialchars($u['phone']) : '' ?></p>
                        <p class="text-[11px] text-slate-400 mt-1">Solicitud: <?= date('d/m/Y H:i', strtotime($u['created_at'])) ?></p>
                    </div>
                </div>

                <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 lg:w-2/3">
                    <div class="text-xs">
                        <p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">Negocio</p>
                        <p class="font-semibold text-slate-900 truncate"><?= htmlspecialchars($u['business_name'] ?: '—') ?></p>
                    </div>
                    <div class="text-xs">
                        <p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">RNC</p>
                        <p class="font-mono font-semibold text-slate-900"><?= htmlspecialchars($u['rnc'] ?: '—') ?></p>
                    </div>
                    <div class="text-xs">
                        <p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">Regimen</p>
                        <p class="font-semibold text-slate-900"><?= htmlspecialchars(getTaxRegimeLabel($u['tax_regime'])) ?></p>
                    </div>
                    <div class="text-xs">
                        <p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">Servicios</p>
                        <p class="font-semibold text-slate-900"><?= count($servicesList) ?> seleccionados</p>
                    </div>
                </div>
            </div>

            <?php if (!empty($servicesList)): ?>
            <div class="mt-4 pt-4 border-t border-stone-100">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-2">Servicios solicitados</p>
                <div class="flex flex-wrap gap-1.5">
                    <?php foreach ($servicesList as $sv): ?>
                    <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[11px] font-semibold <?= $sv['type'] === 'iguala' ? 'bg-blue-50 text-blue-700' : 'bg-emerald-50 text-emerald-700' ?>">
                        <?= htmlspecialchars($sv['title']) ?>
                        <span class="text-[9px] opacity-60"><?= $sv['type'] === 'iguala' ? 'mensual' : 'puntual' ?></span>
                    </span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (!empty($u['notes'])): ?>
            <div class="mt-3 rounded-xl bg-stone-50 p-3">
                <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500 mb-1">Notas del cliente</p>
                <p class="text-xs text-slate-700 leading-relaxed"><?= nl2br(htmlspecialchars($u['notes'])) ?></p>
            </div>
            <?php endif; ?>

            <?php if ($u['approval_status'] === 'rejected' && !empty($u['rejected_reason'])): ?>
            <div class="mt-3 rounded-xl bg-red-50 border border-red-100 p-3">
                <p class="text-[11px] font-bold uppercase tracking-wider text-red-700 mb-1">Motivo de rechazo</p>
                <p class="text-xs text-red-800"><?= htmlspecialchars($u['rejected_reason']) ?></p>
            </div>
            <?php endif; ?>

            <?php if ($u['approval_status'] === 'approved' && $u['approver_name']): ?>
            <p class="mt-3 text-[11px] text-slate-400">Aprobado por <strong><?= htmlspecialchars($u['approver_name']) ?></strong> el <?= $u['approved_at'] ? date('d/m/Y H:i', strtotime($u['approved_at'])) : '' ?></p>
            <?php endif; ?>

            <?php if ($u['approval_status'] === 'pending_approval'): ?>
            <div class="mt-4 flex flex-wrap gap-2 pt-3 border-t border-stone-100">
                <button type="button" onclick="toggleEdit(<?= $u['id'] ?>)" class="btn-soft text-xs">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    Editar antes de aprobar
                </button>
                <form method="POST" class="inline" onsubmit="return confirm('Aprobar a este cliente y crear sus solicitudes de servicios?')">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <button type="submit" class="btn-dark text-xs bg-emerald-600 hover:bg-emerald-700">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        Aprobar
                    </button>
                </form>
                <button type="button" onclick="openReject(<?= $u['id'] ?>)" class="text-xs text-red-600 hover:text-red-800 font-semibold ml-auto">
                    Rechazar
                </button>
            </div>
            <?php endif; ?>
        </div>

        <?php if ($u['approval_status'] === 'pending_approval'): ?>
        <!-- Inline edit form -->
        <div id="edit-<?= $u['id'] ?>" class="hidden border-t border-stone-100 bg-stone-50/50 p-5">
            <form method="POST" class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <div>
                    <label class="field-label">Nombre</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($u['name']) ?>" class="field text-sm" required>
                </div>
                <div>
                    <label class="field-label">Email</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($u['email']) ?>" class="field text-sm" required>
                </div>
                <div>
                    <label class="field-label">Telefono</label>
                    <input type="text" name="phone" value="<?= htmlspecialchars($u['phone']) ?>" class="field text-sm">
                </div>
                <div>
                    <label class="field-label">RNC</label>
                    <input type="text" name="rnc" value="<?= htmlspecialchars($u['rnc']) ?>" class="field text-sm">
                </div>
                <div>
                    <label class="field-label">Nombre comercial</label>
                    <input type="text" name="business_name" value="<?= htmlspecialchars($u['business_name']) ?>" class="field text-sm">
                </div>
                <div>
                    <label class="field-label">Tipo persona</label>
                    <select name="business_type" class="field text-sm">
                        <option value="fisica" <?= $u['business_type']==='fisica'?'selected':'' ?>>Fisica</option>
                        <option value="juridica" <?= $u['business_type']==='juridica'?'selected':'' ?>>Juridica</option>
                    </select>
                </div>
                <div>
                    <label class="field-label">Regimen</label>
                    <select name="tax_regime" class="field text-sm">
                        <?php foreach (getTaxRegimes() as $val => $lbl): ?>
                        <option value="<?= $val ?>" <?= $u['tax_regime']===$val?'selected':'' ?>><?= htmlspecialchars($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="field-label">Operacion</label>
                    <select name="operation_type" class="field text-sm">
                        <?php foreach (getOperationTypes() as $val => $lbl): ?>
                        <option value="<?= $val ?>" <?= $u['operation_type']===$val?'selected':'' ?>><?= htmlspecialchars($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="field-label">Empleados</label>
                    <input type="number" name="employee_count" value="<?= (int)$u['employee_count'] ?>" class="field text-sm">
                </div>
                <div class="sm:col-span-2">
                    <label class="field-label">Actividad economica</label>
                    <input type="text" name="economic_activity" value="<?= htmlspecialchars($u['economic_activity']) ?>" class="field text-sm">
                </div>
                <div>
                    <label class="field-label">Direccion</label>
                    <input type="text" name="address" value="<?= htmlspecialchars($u['address']) ?>" class="field text-sm">
                </div>
                <div class="sm:col-span-3">
                    <label class="field-label">Notas</label>
                    <textarea name="notes" rows="2" class="field text-sm"><?= htmlspecialchars($u['notes']) ?></textarea>
                </div>
                <div class="sm:col-span-3 flex justify-end gap-2">
                    <button type="button" onclick="toggleEdit(<?= $u['id'] ?>)" class="btn-soft text-xs">Cancelar</button>
                    <button type="submit" class="btn-dark text-xs">Guardar cambios</button>
                </div>
            </form>
        </div>

        <!-- Reject modal trigger (renders modal on page) -->
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- Reject modal -->
<div id="rejectModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 modal-backdrop" onclick="closeReject()"></div>
    <div class="relative flex min-h-full items-center justify-center p-4">
        <div class="w-full max-w-md rounded-3xl bg-white shadow-2xl">
            <div class="px-6 py-5 border-b border-stone-100">
                <h3 class="text-base font-bold text-slate-900">Rechazar solicitud</h3>
                <p class="text-xs text-slate-500 mt-0.5">El cliente recibira una notificacion con el motivo.</p>
            </div>
            <form method="POST" class="p-6 space-y-4">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="user_id" id="rejectUserId">
                <div>
                    <label class="field-label">Motivo (opcional)</label>
                    <textarea name="reason" rows="3" class="field text-sm" placeholder="Ej: Datos incompletos, no es nuestro publico objetivo..."></textarea>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="closeReject()" class="btn-soft text-sm">Cancelar</button>
                    <button type="submit" class="btn-dark text-sm bg-red-600 hover:bg-red-700">Rechazar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>

<script>
function toggleEdit(id) {
    const el = document.getElementById('edit-' + id);
    if (el) el.classList.toggle('hidden');
}
function openReject(id) {
    document.getElementById('rejectUserId').value = id;
    document.getElementById('rejectModal').classList.remove('hidden');
}
function closeReject() {
    document.getElementById('rejectModal').classList.add('hidden');
}
</script>

<?php include 'components/layout_end.php'; ?>
