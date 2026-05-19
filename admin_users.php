<?php
require_once 'config.php';
requireAuth('admin');

$success = $error = null;
$roles = array_values(getRoles(true));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_user') {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $role = trim($_POST['role'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($name === '' || $email === '' || $password === '' || !getRole($role)) {
            $error = 'Completa nombre, correo, contrasena y rol valido.';
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO users (name, email, phone, role, password_hash) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$name, $email, $phone, $role, password_hash($password, PASSWORD_DEFAULT)]);
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
            $success = 'Usuario eliminado correctamente.';
        }
    }

    $roles = array_values(getRoles(true));
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

$page_title = 'Usuarios';
$page_subtitle = 'Crea usuarios, asigna roles y controla los accesos al portal.';
$page_actions = '<button type="button" onclick="openModal(\'createUserModal\')"
    class="inline-flex items-center gap-2 btn-dark text-sm">
    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
    Nuevo usuario
</button>';
include 'components/layout_start.php';
?>

<?php if ($success): ?>
<div class="mb-4 rounded-2xl bg-emerald-50 px-4 py-3 border border-emerald-100 text-sm font-medium text-emerald-800"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="mb-4 rounded-2xl bg-red-50 px-4 py-3 border border-red-100 text-sm font-medium text-red-700"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
    <div class="stat-card p-5">
        <p class="text-sm text-slate-500">Total usuarios</p>
        <p class="mt-1 text-3xl font-extrabold tracking-tight text-slate-900"><?= count($users) ?></p>
        <p class="mt-1 text-[11px] text-slate-400">Activos en el sistema</p>
    </div>
    <div class="stat-card p-5">
        <p class="text-sm text-slate-500">Panel administrativo</p>
        <p class="mt-1 text-3xl font-extrabold tracking-tight text-slate-900"><?= $adminCount ?></p>
        <p class="mt-1 text-[11px] text-slate-400">Gestores del portal</p>
    </div>
    <div class="stat-card p-5">
        <p class="text-sm text-slate-500">Panel cliente</p>
        <p class="mt-1 text-3xl font-extrabold tracking-tight text-slate-900"><?= $clientCount ?></p>
        <p class="mt-1 text-[11px] text-slate-400">Acceso lectura</p>
    </div>
</div>

<div class="surface-card overflow-hidden">
    <div class="px-6 py-5 border-b border-stone-100">
        <h3 class="text-base font-bold text-slate-900">Directorio de usuarios</h3>
    </div>
    <ul class="divide-y divide-stone-100">
        <?php foreach ($users as $user): ?>
        <li class="px-6 py-4 hover:bg-stone-50/60 transition-colors">
            <div class="flex flex-col lg:flex-row lg:items-center gap-4">
                <div class="flex items-center gap-3 lg:flex-1 min-w-0">
                    <div class="h-11 w-11 rounded-full bg-stone-100 border border-stone-200 flex items-center justify-center text-sm font-bold text-slate-700 shrink-0">
                        <?= htmlspecialchars(substr(strtoupper($user['name']), 0, 1)) ?>
                    </div>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-slate-900 truncate"><?= htmlspecialchars($user['name']) ?></p>
                        <p class="text-xs text-slate-500 truncate"><?= htmlspecialchars($user['email']) ?></p>
                        <p class="text-[11px] text-slate-400 truncate"><?= htmlspecialchars($user['phone'] ?: 'Sin telefono') ?></p>
                    </div>
                </div>
                <div class="flex items-center gap-3 lg:gap-5">
                    <div class="text-xs">
                        <p class="text-[10px] uppercase tracking-wider text-slate-400 font-semibold">Rol</p>
                        <p class="font-medium text-slate-700"><?= htmlspecialchars($user['role_name']) ?></p>
                    </div>
                    <span class="badge-dot <?= $user['access_level'] === 'admin' ? 'badge-blue' : 'badge-green' ?>">
                        <?= $user['access_level'] === 'admin' ? 'Admin' : 'Cliente' ?>
                    </span>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <button type="button" onclick="openModal('editUserModal<?= $user['id'] ?>')" class="btn-soft text-xs">Editar</button>
                    <?php if ((int) $user['id'] !== (int) $_SESSION['user_id']): ?>
                    <form action="admin_users.php" method="POST" onsubmit="return confirm('Eliminar este usuario?')">
                        <input type="hidden" name="action" value="delete_user">
                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                        <button type="submit" class="rounded-2xl bg-red-50 px-3 py-2.5 text-xs font-semibold text-red-700 hover:bg-red-100 transition-colors">Eliminar</button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
</div>

<!-- Create modal -->
<div id="createUserModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 modal-backdrop" onclick="closeModal('createUserModal')"></div>
    <div class="relative flex min-h-full items-center justify-center p-4">
        <div class="w-full max-w-xl rounded-3xl bg-white shadow-2xl">
            <div class="px-6 py-5 border-b border-stone-100 flex items-center justify-between">
                <h3 class="text-base font-bold text-slate-900">Crear usuario</h3>
                <button type="button" onclick="closeModal('createUserModal')" class="text-slate-400 hover:text-slate-700 text-2xl leading-none">&times;</button>
            </div>
            <form action="admin_users.php" method="POST" class="p-6 space-y-4">
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
                        <select name="role" required class="field">
                            <?php foreach ($roles as $role): ?>
                            <option value="<?= htmlspecialchars($role['slug']) ?>"><?= htmlspecialchars($role['name']) ?> (<?= htmlspecialchars($role['access_level']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="field-label">Contrasena inicial</label>
                    <input type="password" name="password" required class="field">
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" onclick="closeModal('createUserModal')" class="btn-soft text-sm">Cancelar</button>
                    <button type="submit" class="btn-dark text-sm">Crear usuario</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php foreach ($users as $user): ?>
<div id="editUserModal<?= $user['id'] ?>" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 modal-backdrop" onclick="closeModal('editUserModal<?= $user['id'] ?>')"></div>
    <div class="relative flex min-h-full items-center justify-center p-4">
        <div class="w-full max-w-xl rounded-3xl bg-white shadow-2xl">
            <div class="px-6 py-5 border-b border-stone-100 flex items-center justify-between">
                <h3 class="text-base font-bold text-slate-900">Editar usuario</h3>
                <button type="button" onclick="closeModal('editUserModal<?= $user['id'] ?>')" class="text-slate-400 hover:text-slate-700 text-2xl leading-none">&times;</button>
            </div>
            <form action="admin_users.php" method="POST" class="p-6 space-y-4">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
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
                        <select name="role" required class="field">
                            <?php foreach ($rolesBySlug as $roleSlug => $role): ?>
                            <option value="<?= htmlspecialchars($roleSlug) ?>" <?= $roleSlug === $user['role'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($role['name']) ?> (<?= htmlspecialchars($role['access_level']) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="field-label">Nueva contrasena</label>
                    <input type="password" name="password" class="field" placeholder="Deja vacio para mantener la actual">
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" onclick="closeModal('editUserModal<?= $user['id'] ?>')" class="btn-soft text-sm">Cancelar</button>
                    <button type="submit" class="btn-dark text-sm">Guardar cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script>
function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
function closeModal(id) { document.getElementById(id).classList.add('hidden'); }
</script>

<?php include 'components/layout_end.php'; ?>
