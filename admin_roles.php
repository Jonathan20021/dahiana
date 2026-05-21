<?php
require_once 'config.php';
requireAuth('admin');
requirePagePermission();

$success = $error = null;
$systemRoles = ['admin', 'client'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_role') {
        $name = trim($_POST['name'] ?? '');
        $slug = slugify($_POST['slug'] ?? $name);
        $accessLevel = $_POST['access_level'] ?? 'client';
        $perms = $_POST['permissions'] ?? [];
        if (!is_array($perms)) $perms = [];

        if ($name === '' || $slug === '' || !in_array($accessLevel, ['admin', 'client'], true)) {
            $error = 'Debes indicar nombre, slug valido y nivel de acceso.';
        } elseif (getRole($slug)) {
            $error = 'Ese slug ya existe. Usa otro identificador.';
        } else {
            try {
                $pdo->prepare("INSERT INTO roles (name, slug, access_level) VALUES (?, ?, ?)")->execute([$name, $slug, $accessLevel]);
                if ($accessLevel === 'admin') {
                    setRolePermissions($slug, $perms);
                }
                getRoles(true); // invalidar cache
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
        $perms = $_POST['permissions'] ?? [];
        if (!is_array($perms)) $perms = [];

        if ($roleId <= 0 || $name === '' || !in_array($accessLevel, ['admin', 'client'], true)) {
            $error = 'Los datos del rol no son validos.';
        } elseif ($currentSlug === $_SESSION['role'] && $accessLevel !== 'admin') {
            $error = 'No puedes quitar acceso administrativo a tu rol actual desde esta sesion.';
        } else {
            try {
                $pdo->prepare("UPDATE roles SET name = ?, access_level = ? WHERE id = ?")->execute([$name, $accessLevel, $roleId]);
                if ($currentSlug !== 'admin' && $accessLevel === 'admin') {
                    setRolePermissions($currentSlug, $perms);
                }
                getRoles(true);
                $success = 'Rol y permisos actualizados.';
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
                $pdo->prepare("DELETE FROM role_permissions WHERE role_slug = ?")->execute([$slug]);
                getRoles(true);
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

$catalog = permissionsCatalog();

$page_title = 'Roles y permisos';
$page_subtitle = 'Define quien puede entrar a cada modulo del panel admin.';
$page_actions = '<button type="button" onclick="openModal(\'createRoleModal\')" class="btn-dark text-sm">
    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
    Nuevo rol
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

<div class="space-y-3">
    <?php foreach ($roles as $role):
        $rolePerms = getRolePermissions($role['slug']);
        $totalPerms = count(allPermissionKeys());
        $isSystem = in_array($role['slug'], $systemRoles, true);
        $isAdminLevel = $role['access_level'] === 'admin';
    ?>
    <div class="surface-card overflow-hidden">
        <div class="p-5 flex flex-col lg:flex-row lg:items-center gap-4">
            <div class="flex items-center gap-3 lg:w-72">
                <div class="w-11 h-11 rounded-xl <?= $isAdminLevel ? 'bg-slate-900 text-white' : 'bg-emerald-50 text-emerald-700' ?> flex items-center justify-center font-extrabold">
                    <?= htmlspecialchars(strtoupper(substr($role['name'], 0, 2))) ?>
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-bold text-slate-900 flex items-center gap-2">
                        <?= htmlspecialchars($role['name']) ?>
                        <?php if ($isSystem): ?>
                        <span class="text-[9px] bg-slate-100 text-slate-600 px-1.5 py-0.5 rounded font-bold">BASE</span>
                        <?php endif; ?>
                    </p>
                    <p class="text-[11px] text-slate-500 font-mono"><?= htmlspecialchars($role['slug']) ?></p>
                </div>
            </div>
            <div class="flex-1 grid grid-cols-3 gap-3 text-xs">
                <div>
                    <p class="text-[9px] uppercase tracking-wider font-bold text-slate-400">Nivel</p>
                    <p class="font-semibold text-slate-900 mt-0.5"><?= $isAdminLevel ? 'Panel admin' : 'Panel cliente' ?></p>
                </div>
                <div>
                    <p class="text-[9px] uppercase tracking-wider font-bold text-slate-400">Usuarios</p>
                    <p class="font-semibold text-slate-900 mt-0.5"><?= (int) $role['users_count'] ?></p>
                </div>
                <div>
                    <p class="text-[9px] uppercase tracking-wider font-bold text-slate-400">Permisos</p>
                    <?php if ($role['slug'] === 'admin'): ?>
                    <p class="font-semibold text-slate-900 mt-0.5">Todos (super)</p>
                    <?php elseif ($isAdminLevel): ?>
                    <p class="font-semibold text-slate-900 mt-0.5"><?= count($rolePerms) ?> / <?= $totalPerms ?></p>
                    <?php else: ?>
                    <p class="font-semibold text-slate-500 mt-0.5">No aplica</p>
                    <?php endif; ?>
                </div>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                <?php if ($role['slug'] !== 'admin'): ?>
                <button type="button" onclick="openModal('editRoleModal<?= $role['id'] ?>')" class="btn-soft text-xs">Editar</button>
                <?php endif; ?>
                <?php if (!$isSystem): ?>
                <form action="admin_roles.php" method="POST" onsubmit="return confirm('Eliminar este rol?')">
                    <input type="hidden" name="action" value="delete_role">
                    <input type="hidden" name="role_id" value="<?= $role['id'] ?>">
                    <input type="hidden" name="slug" value="<?= htmlspecialchars($role['slug']) ?>">
                    <button type="submit" class="bg-red-50 text-red-700 hover:bg-red-100 text-xs font-semibold rounded-2xl px-3 py-2">Eliminar</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php if ($isAdminLevel && $role['slug'] !== 'admin' && !empty($rolePerms)): ?>
        <div class="px-5 pb-4 flex flex-wrap gap-1.5">
            <?php
            $byKey = [];
            foreach ($catalog as $cat => $perms) foreach ($perms as $k => $m) $byKey[$k] = $m['label'];
            foreach ($rolePerms as $pk):
                if (!isset($byKey[$pk])) continue;
            ?>
            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-blue-50 text-blue-700 text-[10.5px] font-semibold border border-blue-100">
                <?= htmlspecialchars($byKey[$pk]) ?>
            </span>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- Modal CREAR -->
<div id="createRoleModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 modal-backdrop" onclick="closeModal('createRoleModal')"></div>
    <div class="relative flex min-h-full items-center justify-center p-4">
        <div class="w-full max-w-3xl rounded-3xl bg-white shadow-2xl max-h-[90vh] flex flex-col">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-bold text-slate-900">Crear rol</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Define el nombre, el nivel y los modulos a los que tendra acceso.</p>
                </div>
                <button type="button" onclick="closeModal('createRoleModal')" class="text-slate-400 hover:text-slate-700 text-2xl leading-none">&times;</button>
            </div>
            <form action="admin_roles.php" method="POST" class="flex-1 overflow-y-auto p-6 space-y-4">
                <input type="hidden" name="action" value="add_role">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="field-label">Nombre visible</label>
                        <input type="text" name="name" required class="field" placeholder="Ej: Secretaria fiscal">
                    </div>
                    <div>
                        <label class="field-label">Slug tecnico</label>
                        <input type="text" name="slug" placeholder="secretaria_fiscal" class="field">
                        <p class="mt-1 text-[10px] text-slate-400">Auto-generado si lo dejas vacio.</p>
                    </div>
                </div>
                <div>
                    <label class="field-label">Nivel de acceso</label>
                    <select name="access_level" class="field" onchange="togglePermsBlock(this, 'newPerms')">
                        <option value="admin">Panel admin (staff)</option>
                        <option value="client">Panel cliente</option>
                    </select>
                </div>

                <div id="newPerms">
                    <p class="field-label">Permisos por modulo</p>
                    <p class="text-[11px] text-slate-500 mb-3">Si un permiso esta apagado, el usuario no vera el modulo en el menu ni podra abrirlo.</p>
                    <?php foreach ($catalog as $catLabel => $perms): ?>
                    <div class="mb-3 p-3 rounded-xl border border-slate-200 bg-slate-50/40">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500"><?= htmlspecialchars($catLabel) ?></p>
                            <div class="flex gap-1 text-[10px]">
                                <button type="button" onclick="toggleCat(this, true)" class="text-blue-600 hover:underline">Todo</button>
                                <span class="text-slate-300">·</span>
                                <button type="button" onclick="toggleCat(this, false)" class="text-slate-500 hover:underline">Nada</button>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5">
                            <?php foreach ($perms as $key => $meta): ?>
                            <label class="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-white cursor-pointer">
                                <input type="checkbox" name="permissions[]" value="<?= htmlspecialchars($key) ?>" class="w-3.5 h-3.5">
                                <span class="text-xs text-slate-700"><?= htmlspecialchars($meta['label']) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t border-slate-100 sticky bottom-0 bg-white -mx-6 -mb-6 px-6 py-4">
                    <button type="button" onclick="closeModal('createRoleModal')" class="btn-soft text-sm">Cancelar</button>
                    <button type="submit" class="btn-dark text-sm">Crear rol</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modales EDITAR (uno por rol no-admin) -->
<?php foreach ($roles as $role):
    if ($role['slug'] === 'admin') continue;
    $rolePerms = getRolePermissions($role['slug']);
?>
<div id="editRoleModal<?= $role['id'] ?>" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 modal-backdrop" onclick="closeModal('editRoleModal<?= $role['id'] ?>')"></div>
    <div class="relative flex min-h-full items-center justify-center p-4">
        <div class="w-full max-w-3xl rounded-3xl bg-white shadow-2xl max-h-[90vh] flex flex-col">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-bold text-slate-900">Editar rol — <?= htmlspecialchars($role['name']) ?></h3>
                    <p class="text-xs text-slate-500 mt-0.5">Slug: <span class="font-mono"><?= htmlspecialchars($role['slug']) ?></span></p>
                </div>
                <button type="button" onclick="closeModal('editRoleModal<?= $role['id'] ?>')" class="text-slate-400 hover:text-slate-700 text-2xl leading-none">&times;</button>
            </div>
            <form action="admin_roles.php" method="POST" class="flex-1 overflow-y-auto p-6 space-y-4">
                <input type="hidden" name="action" value="edit_role">
                <input type="hidden" name="role_id" value="<?= $role['id'] ?>">
                <input type="hidden" name="current_slug" value="<?= htmlspecialchars($role['slug']) ?>">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="field-label">Nombre visible</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($role['name']) ?>" required class="field">
                    </div>
                    <div>
                        <label class="field-label">Nivel de acceso</label>
                        <select name="access_level" class="field" onchange="togglePermsBlock(this, 'permsBlock<?= $role['id'] ?>')">
                            <option value="admin" <?= $role['access_level'] === 'admin' ? 'selected' : '' ?>>Panel admin (staff)</option>
                            <option value="client" <?= $role['access_level'] === 'client' ? 'selected' : '' ?>>Panel cliente</option>
                        </select>
                    </div>
                </div>

                <div id="permsBlock<?= $role['id'] ?>" <?= $role['access_level'] !== 'admin' ? 'class="hidden"' : '' ?>>
                    <div class="flex items-center justify-between mb-2">
                        <p class="field-label !mb-0">Permisos por modulo</p>
                        <div class="flex gap-2 text-[10px]">
                            <button type="button" onclick="toggleAll(<?= $role['id'] ?>, true)" class="text-blue-600 hover:underline">Marcar todos</button>
                            <span class="text-slate-300">·</span>
                            <button type="button" onclick="toggleAll(<?= $role['id'] ?>, false)" class="text-slate-500 hover:underline">Desmarcar</button>
                        </div>
                    </div>
                    <?php foreach ($catalog as $catLabel => $perms): ?>
                    <div class="mb-3 p-3 rounded-xl border border-slate-200 bg-slate-50/40">
                        <div class="flex items-center justify-between mb-2">
                            <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500"><?= htmlspecialchars($catLabel) ?></p>
                            <div class="flex gap-1 text-[10px]">
                                <button type="button" onclick="toggleCat(this, true)" class="text-blue-600 hover:underline">Todo</button>
                                <span class="text-slate-300">·</span>
                                <button type="button" onclick="toggleCat(this, false)" class="text-slate-500 hover:underline">Nada</button>
                            </div>
                        </div>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5 perms-cat" data-role="<?= $role['id'] ?>">
                            <?php foreach ($perms as $key => $meta):
                                $checked = in_array($key, $rolePerms, true);
                            ?>
                            <label class="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-white cursor-pointer">
                                <input type="checkbox" name="permissions[]" value="<?= htmlspecialchars($key) ?>" class="w-3.5 h-3.5 perm-cb-r<?= $role['id'] ?>" <?= $checked ? 'checked' : '' ?>>
                                <span class="text-xs text-slate-700"><?= htmlspecialchars($meta['label']) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t border-slate-100 sticky bottom-0 bg-white -mx-6 -mb-6 px-6 py-4">
                    <button type="button" onclick="closeModal('editRoleModal<?= $role['id'] ?>')" class="btn-soft text-sm">Cancelar</button>
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
function togglePermsBlock(sel, blockId) {
    const blk = document.getElementById(blockId);
    if (!blk) return;
    blk.classList.toggle('hidden', sel.value !== 'admin');
}
function toggleCat(btn, on) {
    const wrap = btn.closest('.rounded-xl');
    if (!wrap) return;
    wrap.querySelectorAll('input[type=checkbox]').forEach(cb => cb.checked = on);
}
function toggleAll(roleId, on) {
    document.querySelectorAll('.perm-cb-r' + roleId).forEach(cb => cb.checked = on);
}
document.addEventListener('keydown', e => { if (e.key === 'Escape') document.querySelectorAll('.fixed.inset-0.z-50').forEach(m => closeModal(m.id)); });
</script>

<?php include 'components/layout_end.php'; ?>
