<?php
require_once 'config.php';
requireAuth('admin');

$success = $error = null;
$systemRoles = ['admin', 'client'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_role') {
        $name = trim($_POST['name'] ?? '');
        $slug = slugify($_POST['slug'] ?? $name);
        $accessLevel = $_POST['access_level'] ?? 'client';

        if ($name === '' || $slug === '' || !in_array($accessLevel, ['admin', 'client'], true)) {
            $error = 'Debes indicar nombre, slug valido y nivel de acceso.';
        } elseif (getRole($slug)) {
            $error = 'Ese slug ya existe. Usa otro identificador.';
        } else {
            try {
                $pdo->prepare("INSERT INTO roles (name, slug, access_level) VALUES (?, ?, ?)")->execute([$name, $slug, $accessLevel]);
                $success = 'Rol creado correctamente.';
            } catch (PDOException $e) {
                $error = 'No se pudo crear el rol.';
            }
        }
    } elseif ($action === 'edit_role') {
        $roleId = (int) ($_POST['role_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $accessLevel = $_POST['access_level'] ?? 'client';
        $currentSlug = trim($_POST['current_slug'] ?? '');

        if ($roleId <= 0 || $name === '' || !in_array($accessLevel, ['admin', 'client'], true)) {
            $error = 'Los datos del rol no son validos.';
        } elseif ($currentSlug === $_SESSION['role'] && $accessLevel !== 'admin') {
            $error = 'No puedes quitar acceso administrativo a tu rol actual desde esta sesion.';
        } else {
            try {
                $pdo->prepare("UPDATE roles SET name = ?, access_level = ? WHERE id = ?")->execute([$name, $accessLevel, $roleId]);
                $success = 'Rol actualizado correctamente.';
            } catch (PDOException $e) {
                $error = 'No se pudo actualizar el rol.';
            }
        }
    } elseif ($action === 'delete_role') {
        $roleId = (int) ($_POST['role_id'] ?? 0);
        $slug = trim($_POST['slug'] ?? '');

        if (in_array($slug, $systemRoles, true)) {
            $error = 'Los roles base no se pueden eliminar.';
        } else {
            $usageStmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = ?");
            $usageStmt->execute([$slug]);
            $usageCount = (int) $usageStmt->fetchColumn();
            if ($usageCount > 0) {
                $error = 'No puedes eliminar un rol que todavia tiene usuarios asignados.';
            } else {
                $pdo->prepare("DELETE FROM roles WHERE id = ?")->execute([$roleId]);
                $success = 'Rol eliminado correctamente.';
            }
        }
    }
}

$roles = $pdo->query("
    SELECT r.*, COUNT(u.id) AS users_count
    FROM roles r
    LEFT JOIN users u ON u.role = r.slug
    GROUP BY r.id, r.name, r.slug, r.access_level, r.created_at
    ORDER BY r.access_level DESC, r.name ASC
")->fetchAll();

$page_title = 'Roles';
$page_subtitle = 'Define perfiles y controla si entran al panel admin o cliente.';
$page_actions = '<button type="button" onclick="openModal(\'createRoleModal\')"
    class="inline-flex items-center gap-2 btn-dark text-sm">
    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
    Nuevo rol
</button>';
$main_max = 'max-w-6xl';
include 'components/layout_start.php';
?>

<?php if ($success): ?>
<div class="mb-4 rounded-2xl bg-emerald-50 px-4 py-3 border border-emerald-100 text-sm font-medium text-emerald-800"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="mb-4 rounded-2xl bg-red-50 px-4 py-3 border border-red-100 text-sm font-medium text-red-700"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="grid grid-cols-1 md:grid-cols-2 gap-5">
    <?php foreach ($roles as $role): ?>
    <div class="surface-card p-6">
        <div class="flex items-start justify-between gap-4 mb-4">
            <div class="min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <h2 class="text-lg font-bold text-slate-900"><?= htmlspecialchars($role['name']) ?></h2>
                    <?php if (in_array($role['slug'], $systemRoles, true)): ?>
                    <span class="badge-dot badge-slate">Base</span>
                    <?php endif; ?>
                </div>
                <p class="mt-1 text-xs text-slate-500">Slug: <span class="font-mono font-semibold text-slate-700"><?= htmlspecialchars($role['slug']) ?></span></p>
            </div>
            <span class="badge-dot <?= $role['access_level'] === 'admin' ? 'badge-blue' : 'badge-green' ?>">
                <?= $role['access_level'] === 'admin' ? 'Admin' : 'Cliente' ?>
            </span>
        </div>

        <div class="grid grid-cols-2 gap-3 mb-4">
            <div class="rounded-2xl bg-stone-50 px-4 py-3">
                <p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">Usuarios</p>
                <p class="mt-1 text-2xl font-extrabold text-slate-900"><?= (int) $role['users_count'] ?></p>
            </div>
            <div class="rounded-2xl bg-stone-50 px-4 py-3">
                <p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">Creado</p>
                <p class="mt-1 text-sm font-bold text-slate-900"><?= date('d/m/Y', strtotime($role['created_at'])) ?></p>
            </div>
        </div>

        <div class="flex items-center justify-end gap-2">
            <button type="button" onclick="openModal('editRoleModal<?= $role['id'] ?>')" class="btn-soft text-xs">Editar</button>
            <?php if (!in_array($role['slug'], $systemRoles, true)): ?>
            <form action="admin_roles.php" method="POST" onsubmit="return confirm('Eliminar este rol?')">
                <input type="hidden" name="action" value="delete_role">
                <input type="hidden" name="role_id" value="<?= $role['id'] ?>">
                <input type="hidden" name="slug" value="<?= htmlspecialchars($role['slug']) ?>">
                <button type="submit" class="rounded-2xl bg-red-50 px-3 py-2.5 text-xs font-semibold text-red-700 hover:bg-red-100 transition-colors">Eliminar</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Create modal -->
<div id="createRoleModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 modal-backdrop" onclick="closeModal('createRoleModal')"></div>
    <div class="relative flex min-h-full items-center justify-center p-4">
        <div class="w-full max-w-xl rounded-3xl bg-white shadow-2xl">
            <div class="px-6 py-5 border-b border-stone-100 flex items-center justify-between">
                <h3 class="text-base font-bold text-slate-900">Crear rol</h3>
                <button type="button" onclick="closeModal('createRoleModal')" class="text-slate-400 hover:text-slate-700 text-2xl leading-none">&times;</button>
            </div>
            <form action="admin_roles.php" method="POST" class="p-6 space-y-4">
                <input type="hidden" name="action" value="add_role">
                <div>
                    <label class="field-label">Nombre visible</label>
                    <input type="text" name="name" required class="field">
                </div>
                <div>
                    <label class="field-label">Slug tecnico</label>
                    <input type="text" name="slug" placeholder="ej. supervisor_fiscal" class="field">
                    <p class="mt-1 text-xs text-slate-400">Si lo dejas vacio, se genera automaticamente.</p>
                </div>
                <div>
                    <label class="field-label">Nivel de acceso</label>
                    <select name="access_level" class="field">
                        <option value="admin">Panel admin</option>
                        <option value="client">Panel cliente</option>
                    </select>
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" onclick="closeModal('createRoleModal')" class="btn-soft text-sm">Cancelar</button>
                    <button type="submit" class="btn-dark text-sm">Crear rol</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php foreach ($roles as $role): ?>
<div id="editRoleModal<?= $role['id'] ?>" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 modal-backdrop" onclick="closeModal('editRoleModal<?= $role['id'] ?>')"></div>
    <div class="relative flex min-h-full items-center justify-center p-4">
        <div class="w-full max-w-xl rounded-3xl bg-white shadow-2xl">
            <div class="px-6 py-5 border-b border-stone-100 flex items-center justify-between">
                <h3 class="text-base font-bold text-slate-900">Editar rol</h3>
                <button type="button" onclick="closeModal('editRoleModal<?= $role['id'] ?>')" class="text-slate-400 hover:text-slate-700 text-2xl leading-none">&times;</button>
            </div>
            <form action="admin_roles.php" method="POST" class="p-6 space-y-4">
                <input type="hidden" name="action" value="edit_role">
                <input type="hidden" name="role_id" value="<?= $role['id'] ?>">
                <input type="hidden" name="current_slug" value="<?= htmlspecialchars($role['slug']) ?>">
                <div>
                    <label class="field-label">Nombre visible</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($role['name']) ?>" required class="field">
                </div>
                <div>
                    <label class="field-label">Slug tecnico</label>
                    <input type="text" value="<?= htmlspecialchars($role['slug']) ?>" disabled class="field bg-stone-50 text-slate-500">
                </div>
                <div>
                    <label class="field-label">Nivel de acceso</label>
                    <select name="access_level" class="field">
                        <option value="admin" <?= $role['access_level'] === 'admin' ? 'selected' : '' ?>>Panel admin</option>
                        <option value="client" <?= $role['access_level'] === 'client' ? 'selected' : '' ?>>Panel cliente</option>
                    </select>
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" onclick="closeModal('editRoleModal<?= $role['id'] ?>')" class="btn-soft text-sm">Cancelar</button>
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
