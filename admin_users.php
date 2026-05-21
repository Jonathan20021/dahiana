<?php
require_once 'config.php';
requireAuth('admin');
requirePagePermission();

$success = $error = null;
$rolesAll = array_values(getRoles(true));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_user') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $role = trim($_POST['role'] ?? '');
        $password = $_POST['password'] ?? '';
        $clientIds = $_POST['client_ids'] ?? [];
        if (!is_array($clientIds)) $clientIds = [];

        if ($name === '' || $email === '' || $password === '' || !getRole($role)) {
            $error = 'Completa nombre, correo, contrasena y rol valido.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO users (name, email, phone, role, password_hash) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$name, $email, $phone, $role, password_hash($password, PASSWORD_DEFAULT)]);
                $newId = (int)$pdo->lastInsertId();
                // Si es staff (admin level) y no es 'admin', persistir asignaciones de clientes
                if (getRoleAccessLevel($role) === 'admin' && $role !== 'admin') {
                    setUserClientAssignments($newId, $clientIds);
                }
                $success = 'Usuario creado correctamente.';
            } catch (PDOException $e) {
                $error = 'No se pudo crear el usuario. Revisa si el correo ya existe.';
            }
        }
    } elseif ($action === 'edit_user') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $role = trim($_POST['role'] ?? '');
        $password = $_POST['password'] ?? '';
        $clientIds = $_POST['client_ids'] ?? [];
        if (!is_array($clientIds)) $clientIds = [];

        if ($userId <= 0 || $name === '' || $email === '' || !getRole($role)) {
            $error = 'Los datos del usuario no son validos.';
        } elseif ($userId === (int) $_SESSION['user_id'] && !canAccessArea($role, 'admin')) {
            $error = 'No puedes quitarte acceso administrativo desde tu propia sesion.';
        } else {
            try {
                if ($password !== '') {
                    $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, phone = ?, role = ?, password_hash = ? WHERE id = ?");
                    $stmt->execute([$name, $email, $phone, $role, password_hash($password, PASSWORD_DEFAULT), $userId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, phone = ?, role = ? WHERE id = ?");
                    $stmt->execute([$name, $email, $phone, $role, $userId]);
                }

                if ($userId === (int) $_SESSION['user_id']) {
                    $_SESSION['name'] = $name;
                    $_SESSION['role'] = $role;
                }

                // Asignaciones de clientes (solo para staff no-admin)
                if (getRoleAccessLevel($role) === 'admin' && $role !== 'admin') {
                    setUserClientAssignments($userId, $clientIds);
                } else {
                    // Si paso a admin (ve todo) o a cliente, limpiar asignaciones
                    $pdo->prepare("DELETE FROM user_client_assignments WHERE user_id=?")->execute([$userId]);
                }

                $success = 'Usuario actualizado correctamente.';
            } catch (PDOException $e) {
                $error = 'No se pudo actualizar el usuario. Revisa si el correo ya existe.';
            }
        }
    } elseif ($action === 'delete_user') {
        $userId = (int) ($_POST['user_id'] ?? 0);

        if ($userId === (int) $_SESSION['user_id']) {
            $error = 'No puedes eliminar tu propio usuario.';
        } else {
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
            $pdo->prepare("DELETE FROM user_client_assignments WHERE user_id=?")->execute([$userId]);
            $success = 'Usuario eliminado correctamente.';
        }
    }
}

$rolesBySlug = getRoles(true);
$users = $pdo->query("
    SELECT u.*,
           COALESCE(r.name, u.role) AS role_name,
           COALESCE(r.access_level, CASE WHEN u.role = 'admin' THEN 'admin' ELSE 'client' END) AS access_level
    FROM users u
    LEFT JOIN roles r ON r.slug = u.role
    ORDER BY access_level DESC, u.created_at DESC
")->fetchAll();

$adminCount = 0;
$clientCount = 0;
foreach ($users as $user) {
    if ($user['access_level'] === 'admin') $adminCount++;
    else $clientCount++;
}

// Conteo de asignaciones por usuario
$assignmentsStmt = $pdo->query("SELECT user_id, COUNT(*) c FROM user_client_assignments GROUP BY user_id");
$assignmentCounts = $assignmentsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

// Lista de clientes para el selector
$allClients = $pdo->query("
    SELECT u.id, u.name, u.email, u.business_name
    FROM users u
    LEFT JOIN roles r ON r.slug = u.role
    WHERE COALESCE(r.access_level, CASE WHEN u.role='admin' THEN 'admin' ELSE 'client' END)='client'
    ORDER BY u.name ASC
")->fetchAll();

// Asignaciones por usuario (para pre-marcar checkboxes en edit modal)
$userAssignments = [];
$asignStmt = $pdo->query("SELECT user_id, client_id FROM user_client_assignments");
foreach ($asignStmt->fetchAll() as $r) {
    $userAssignments[(int)$r['user_id']][] = (int)$r['client_id'];
}

$page_title = 'Usuarios del staff';
$page_subtitle = 'Crea usuarios, asigna roles y limita que clientes ve cada uno.';
$page_actions = '<button type="button" onclick="openModal(\'createUserModal\')" class="btn-dark text-sm">
    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
    Nuevo usuario
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
<div class="mb-4 rounded-2xl bg-red-50 px-4 py-3 border border-red-100 text-sm font-medium text-red-700"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- KPIs -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
    <div class="surface-card p-4">
        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Total</p>
        <p class="text-2xl font-extrabold text-slate-900 mt-1"><?= count($users) ?></p>
        <p class="text-[11px] text-slate-500">Usuarios registrados</p>
    </div>
    <div class="surface-card p-4">
        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Staff</p>
        <p class="text-2xl font-extrabold text-slate-900 mt-1"><?= $adminCount ?></p>
        <p class="text-[11px] text-slate-500">Acceso al panel admin</p>
    </div>
    <div class="surface-card p-4">
        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Clientes</p>
        <p class="text-2xl font-extrabold text-slate-900 mt-1"><?= $clientCount ?></p>
        <p class="text-[11px] text-slate-500">Acceso al portal cliente</p>
    </div>
    <div class="surface-card p-4">
        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Roles activos</p>
        <p class="text-2xl font-extrabold text-slate-900 mt-1"><?= count($rolesBySlug) ?></p>
        <p class="text-[11px] text-slate-500"><a href="admin_roles.php" class="text-blue-600 hover:underline">Editar permisos →</a></p>
    </div>
</div>

<!-- Lista -->
<div class="surface-card overflow-hidden">
    <div class="px-5 py-4 border-b border-slate-100 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-bold text-slate-900">Directorio</h3>
            <p class="text-[11px] text-slate-500 mt-0.5">Los usuarios del staff sin asignaciones de clientes NO veran ningun cliente. Asigna explicitamente.</p>
        </div>
    </div>
    <ul class="divide-y divide-slate-100">
        <?php foreach ($users as $user):
            $uid = (int)$user['id'];
            $isStaffScoped = $user['access_level'] === 'admin' && $user['role'] !== 'admin';
            $assignedCount = (int)($assignmentCounts[$uid] ?? 0);
            $isMe = $uid === (int) $_SESSION['user_id'];
        ?>
        <li class="px-5 py-3 flex flex-col lg:flex-row lg:items-center gap-3 hover:bg-slate-50/60 transition-colors">
            <div class="flex items-center gap-3 lg:flex-1 min-w-0">
                <div class="h-10 w-10 rounded-xl <?= $user['access_level'] === 'admin' ? 'bg-slate-900 text-white' : 'bg-emerald-50 text-emerald-700' ?> flex items-center justify-center text-xs font-extrabold shrink-0">
                    <?= htmlspecialchars(strtoupper(substr($user['name'], 0, 2))) ?>
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-bold text-slate-900 truncate"><?= htmlspecialchars($user['name']) ?> <?php if ($isMe): ?><span class="text-[10px] text-blue-600 font-semibold">(tu)</span><?php endif; ?></p>
                    <p class="text-xs text-slate-500 truncate"><?= htmlspecialchars($user['email']) ?><?= $user['phone'] ? ' · ' . htmlspecialchars($user['phone']) : '' ?></p>
                </div>
            </div>
            <div class="flex items-center gap-3 text-xs flex-wrap">
                <div>
                    <p class="text-[9px] uppercase tracking-wider font-bold text-slate-400">Rol</p>
                    <p class="font-semibold text-slate-900"><?= htmlspecialchars($user['role_name']) ?></p>
                </div>
                <span class="badge-dot <?= $user['access_level'] === 'admin' ? 'badge-blue' : 'badge-green' ?>">
                    <?= $user['access_level'] === 'admin' ? 'Staff' : 'Cliente' ?>
                </span>
                <?php if ($isStaffScoped): ?>
                <div>
                    <p class="text-[9px] uppercase tracking-wider font-bold text-slate-400">Clientes asignados</p>
                    <p class="font-semibold text-slate-900">
                        <?php if ($assignedCount === 0): ?>
                        <span class="text-red-600">Ninguno (no ve clientes)</span>
                        <?php else: ?>
                        <?= $assignedCount ?> cliente(s)
                        <?php endif; ?>
                    </p>
                </div>
                <?php elseif ($user['role'] === 'admin'): ?>
                <div>
                    <p class="text-[9px] uppercase tracking-wider font-bold text-slate-400">Alcance</p>
                    <p class="font-semibold text-slate-900">Todos los clientes</p>
                </div>
                <?php endif; ?>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <button type="button" onclick="openModal('editUserModal<?= $uid ?>')" class="btn-soft text-xs">Editar</button>
                <?php if (!$isMe): ?>
                <form action="admin_users.php" method="POST" onsubmit="return confirm('Eliminar este usuario?')">
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="user_id" value="<?= $uid ?>">
                    <button type="submit" class="bg-red-50 text-red-700 hover:bg-red-100 text-xs font-semibold rounded-2xl px-3 py-2">Eliminar</button>
                </form>
                <?php endif; ?>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
</div>

<!-- Modal CREAR -->
<div id="createUserModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 modal-backdrop" onclick="closeModal('createUserModal')"></div>
    <div class="relative flex min-h-full items-center justify-center p-4">
        <div class="w-full max-w-2xl rounded-3xl bg-white shadow-2xl max-h-[90vh] flex flex-col">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                <h3 class="text-base font-bold text-slate-900">Nuevo usuario</h3>
                <button type="button" onclick="closeModal('createUserModal')" class="text-slate-400 hover:text-slate-700 text-2xl leading-none">&times;</button>
            </div>
            <form action="admin_users.php" method="POST" class="flex-1 overflow-y-auto p-6 space-y-4">
                <input type="hidden" name="action" value="add_user">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="field-label">Nombre</label>
                        <input type="text" name="name" required class="field">
                    </div>
                    <div>
                        <label class="field-label">Correo</label>
                        <input type="email" name="email" required class="field">
                    </div>
                    <div>
                        <label class="field-label">Telefono</label>
                        <input type="text" name="phone" class="field">
                    </div>
                    <div>
                        <label class="field-label">Rol</label>
                        <select name="role" required class="field role-select" data-target="newClientPicker">
                            <?php foreach ($rolesAll as $role): ?>
                            <option value="<?= htmlspecialchars($role['slug']) ?>" data-level="<?= $role['access_level'] ?>" data-slug="<?= $role['slug'] ?>">
                                <?= htmlspecialchars($role['name']) ?> (<?= $role['access_level'] ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-[10px] text-slate-400 mt-1">Crea roles personalizados en <a href="admin_roles.php" class="text-blue-600">Roles</a>.</p>
                    </div>
                </div>
                <div>
                    <label class="field-label">Contrasena inicial</label>
                    <input type="password" name="password" required class="field" minlength="6">
                </div>

                <!-- Selector de clientes (solo visible para staff no-admin) -->
                <div id="newClientPicker" class="hidden">
                    <div class="flex items-center justify-between mb-2">
                        <label class="field-label !mb-0">Clientes asignados</label>
                        <div class="flex items-center gap-2 text-[10px]">
                            <input type="search" placeholder="Buscar..." onkeyup="filterClients(this, 'newClientList')" class="px-2 py-1 text-xs rounded-lg border border-slate-200 focus:outline-none focus:border-slate-900">
                            <button type="button" onclick="checkAllClients('newClientList', true)" class="text-blue-600 hover:underline">Todos</button>
                            <button type="button" onclick="checkAllClients('newClientList', false)" class="text-slate-500 hover:underline">Ninguno</button>
                        </div>
                    </div>
                    <p class="text-[11px] text-slate-500 mb-2">Este usuario solo vera a los clientes marcados aqui. Sin asignaciones, no vera ninguno.</p>
                    <div id="newClientList" class="max-h-64 overflow-y-auto border border-slate-200 rounded-xl p-2 space-y-1">
                        <?php foreach ($allClients as $c): ?>
                        <label class="client-row flex items-center gap-2 p-2 rounded-lg hover:bg-slate-50 cursor-pointer">
                            <input type="checkbox" name="client_ids[]" value="<?= (int)$c['id'] ?>" class="w-3.5 h-3.5">
                            <span class="text-xs text-slate-700">
                                <span class="font-semibold"><?= htmlspecialchars($c['name']) ?></span>
                                <?php if ($c['business_name']): ?><span class="text-slate-400"> · <?= htmlspecialchars($c['business_name']) ?></span><?php endif; ?>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t border-slate-100 sticky bottom-0 bg-white -mx-6 -mb-6 px-6 py-4">
                    <button type="button" onclick="closeModal('createUserModal')" class="btn-soft text-sm">Cancelar</button>
                    <button type="submit" class="btn-dark text-sm">Crear usuario</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modales EDITAR -->
<?php foreach ($users as $user):
    $uid = (int)$user['id'];
    $assigned = $userAssignments[$uid] ?? [];
?>
<div id="editUserModal<?= $uid ?>" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 modal-backdrop" onclick="closeModal('editUserModal<?= $uid ?>')"></div>
    <div class="relative flex min-h-full items-center justify-center p-4">
        <div class="w-full max-w-2xl rounded-3xl bg-white shadow-2xl max-h-[90vh] flex flex-col">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                <h3 class="text-base font-bold text-slate-900">Editar usuario</h3>
                <button type="button" onclick="closeModal('editUserModal<?= $uid ?>')" class="text-slate-400 hover:text-slate-700 text-2xl leading-none">&times;</button>
            </div>
            <form action="admin_users.php" method="POST" class="flex-1 overflow-y-auto p-6 space-y-4">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" value="<?= $uid ?>">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="field-label">Nombre</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($user['name']) ?>" required class="field">
                    </div>
                    <div>
                        <label class="field-label">Correo</label>
                        <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required class="field">
                    </div>
                    <div>
                        <label class="field-label">Telefono</label>
                        <input type="text" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" class="field">
                    </div>
                    <div>
                        <label class="field-label">Rol</label>
                        <select name="role" required class="field role-select" data-target="clientPicker<?= $uid ?>">
                            <?php foreach ($rolesBySlug as $roleSlug => $role): ?>
                            <option value="<?= htmlspecialchars($roleSlug) ?>" data-level="<?= $role['access_level'] ?>" data-slug="<?= $roleSlug ?>" <?= $roleSlug === $user['role'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($role['name']) ?> (<?= $role['access_level'] ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="field-label">Nueva contrasena</label>
                    <input type="password" name="password" class="field" placeholder="Deja vacio para mantener la actual">
                </div>

                <div id="clientPicker<?= $uid ?>" <?= ($user['access_level'] === 'admin' && $user['role'] !== 'admin') ? '' : 'class="hidden"' ?>>
                    <div class="flex items-center justify-between mb-2">
                        <label class="field-label !mb-0">Clientes asignados</label>
                        <div class="flex items-center gap-2 text-[10px]">
                            <input type="search" placeholder="Buscar..." onkeyup="filterClients(this, 'clientList<?= $uid ?>')" class="px-2 py-1 text-xs rounded-lg border border-slate-200 focus:outline-none focus:border-slate-900">
                            <button type="button" onclick="checkAllClients('clientList<?= $uid ?>', true)" class="text-blue-600 hover:underline">Todos</button>
                            <button type="button" onclick="checkAllClients('clientList<?= $uid ?>', false)" class="text-slate-500 hover:underline">Ninguno</button>
                        </div>
                    </div>
                    <p class="text-[11px] text-slate-500 mb-2">
                        <?php if (count($assigned) === 0 && $user['access_level'] === 'admin' && $user['role'] !== 'admin'): ?>
                        <span class="text-red-600 font-semibold">Actualmente sin asignaciones — no ve ningun cliente.</span>
                        <?php else: ?>
                        Asignaciones actuales: <?= count($assigned) ?> cliente(s).
                        <?php endif; ?>
                    </p>
                    <div id="clientList<?= $uid ?>" class="max-h-64 overflow-y-auto border border-slate-200 rounded-xl p-2 space-y-1">
                        <?php foreach ($allClients as $c):
                            $isChecked = in_array((int)$c['id'], $assigned, true);
                        ?>
                        <label class="client-row flex items-center gap-2 p-2 rounded-lg hover:bg-slate-50 cursor-pointer">
                            <input type="checkbox" name="client_ids[]" value="<?= (int)$c['id'] ?>" class="w-3.5 h-3.5" <?= $isChecked ? 'checked' : '' ?>>
                            <span class="text-xs text-slate-700">
                                <span class="font-semibold"><?= htmlspecialchars($c['name']) ?></span>
                                <?php if ($c['business_name']): ?><span class="text-slate-400"> · <?= htmlspecialchars($c['business_name']) ?></span><?php endif; ?>
                            </span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t border-slate-100 sticky bottom-0 bg-white -mx-6 -mb-6 px-6 py-4">
                    <button type="button" onclick="closeModal('editUserModal<?= $uid ?>')" class="btn-soft text-sm">Cancelar</button>
                    <button type="submit" class="btn-dark text-sm">Guardar cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script>
function openModal(id) { document.getElementById(id).classList.remove('hidden'); document.body.style.overflow = 'hidden'; }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); document.body.style.overflow = ''; }
function filterClients(input, listId) {
    const q = input.value.trim().toLowerCase();
    document.getElementById(listId).querySelectorAll('.client-row').forEach(row => {
        const txt = row.textContent.toLowerCase();
        row.style.display = !q || txt.includes(q) ? '' : 'none';
    });
}
function checkAllClients(listId, on) {
    document.getElementById(listId).querySelectorAll('input[type=checkbox]').forEach(cb => {
        if (cb.closest('.client-row').style.display === 'none') return;
        cb.checked = on;
    });
}
// Mostrar/ocultar client picker segun rol elegido
document.querySelectorAll('.role-select').forEach(sel => {
    function refresh() {
        const opt = sel.options[sel.selectedIndex];
        const level = opt?.dataset.level;
        const slug = opt?.dataset.slug;
        const picker = document.getElementById(sel.dataset.target);
        if (!picker) return;
        // Solo mostrar para staff (admin-level) que NO es el rol 'admin' root
        picker.classList.toggle('hidden', !(level === 'admin' && slug !== 'admin'));
    }
    sel.addEventListener('change', refresh);
    refresh();
});
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.fixed.inset-0.z-50').forEach(m => closeModal(m.id)); });
</script>

<?php include 'components/layout_end.php'; ?>
