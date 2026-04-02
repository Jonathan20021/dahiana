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
                $stmt = $pdo->prepare("INSERT INTO roles (name, slug, access_level) VALUES (?, ?, ?)");
                $stmt->execute([$name, $slug, $accessLevel]);
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
                $stmt = $pdo->prepare("UPDATE roles SET name = ?, access_level = ? WHERE id = ?");
                $stmt->execute([$name, $accessLevel, $roleId]);
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
    SELECT r.*,
           COUNT(u.id) AS users_count
    FROM roles r
    LEFT JOIN users u ON u.role = r.slug
    GROUP BY r.id, r.name, r.slug, r.access_level, r.created_at
    ORDER BY r.access_level DESC, r.name ASC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="es" class="h-full bg-slate-50/50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Roles - Portal Asesoria</title>
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
        <div class="px-4 sm:px-6 lg:px-8 max-w-6xl mx-auto">
            <div class="sm:flex sm:items-center sm:justify-between mb-8">
                <div>
                    <h1 class="text-3xl font-bold tracking-tight text-slate-900">Roles</h1>
                    <p class="mt-1 text-sm text-slate-500">Define perfiles reutilizables y decide si ese rol entra al panel administrativo o al panel cliente.</p>
                </div>
                <button type="button" onclick="openModal('createRoleModal')" class="mt-4 sm:mt-0 inline-flex items-center gap-2 rounded-2xl bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 transition-all hover:-translate-y-0.5">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                    Nuevo rol
                </button>
            </div>

            <?php if ($success): ?>
            <div class="mb-6 rounded-2xl bg-green-50 p-4 border border-green-100 text-sm font-medium text-green-800"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
            <div class="mb-6 rounded-2xl bg-red-50 p-4 border border-red-100 text-sm font-medium text-red-800"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <?php foreach ($roles as $role): ?>
                <div class="rounded-3xl bg-white p-6 shadow-sm border border-slate-100">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-3">
                                <h2 class="text-lg font-semibold text-slate-900"><?= htmlspecialchars($role['name']) ?></h2>
                                <?php if (in_array($role['slug'], $systemRoles, true)): ?>
                                <span class="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">Base</span>
                                <?php endif; ?>
                            </div>
                            <p class="mt-1 text-sm text-slate-500">Slug: <span class="font-medium text-slate-700"><?= htmlspecialchars($role['slug']) ?></span></p>
                        </div>
                        <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= $role['access_level'] === 'admin' ? 'bg-blue-50 text-blue-700' : 'bg-emerald-50 text-emerald-700' ?>">
                            <?= $role['access_level'] === 'admin' ? 'Panel admin' : 'Panel cliente' ?>
                        </span>
                    </div>

                    <div class="mt-5 grid grid-cols-2 gap-4">
                        <div class="rounded-2xl bg-slate-50 px-4 py-3">
                            <p class="text-xs uppercase tracking-wide text-slate-400">Usuarios</p>
                            <p class="mt-1 text-2xl font-semibold text-slate-900"><?= (int) $role['users_count'] ?></p>
                        </div>
                        <div class="rounded-2xl bg-slate-50 px-4 py-3">
                            <p class="text-xs uppercase tracking-wide text-slate-400">Creado</p>
                            <p class="mt-1 text-sm font-semibold text-slate-900"><?= date('d/m/Y', strtotime($role['created_at'])) ?></p>
                        </div>
                    </div>

                    <div class="mt-5 flex items-center justify-end gap-2">
                        <button type="button" onclick="openModal('editRoleModal<?= $role['id'] ?>')" class="rounded-xl bg-white px-3 py-2 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-200 hover:bg-slate-50">Editar</button>
                        <?php if (!in_array($role['slug'], $systemRoles, true)): ?>
                        <form action="admin_roles.php" method="POST" onsubmit="return confirm('Se eliminara este rol. Continuar?')">
                            <input type="hidden" name="action" value="delete_role">
                            <input type="hidden" name="role_id" value="<?= $role['id'] ?>">
                            <input type="hidden" name="slug" value="<?= htmlspecialchars($role['slug']) ?>">
                            <button type="submit" class="rounded-xl bg-red-50 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-100">Eliminar</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </main>

    <div id="createRoleModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-slate-900/50" onclick="closeModal('createRoleModal')"></div>
        <div class="relative flex min-h-full items-center justify-center p-4">
            <div class="w-full max-w-xl rounded-3xl bg-white shadow-xl border border-slate-100">
                <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-slate-900">Crear rol</h3>
                    <button type="button" onclick="closeModal('createRoleModal')" class="text-slate-400 hover:text-slate-600 text-2xl leading-none">&times;</button>
                </div>
                <form action="admin_roles.php" method="POST" class="p-6 space-y-4">
                    <input type="hidden" name="action" value="add_role">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Nombre visible</label>
                        <input type="text" name="name" required class="w-full rounded-2xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Slug tecnico</label>
                        <input type="text" name="slug" placeholder="ej. supervisor_fiscal" class="w-full rounded-2xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">
                        <p class="mt-1 text-xs text-slate-400">Si lo dejas vacio, se genera automaticamente a partir del nombre.</p>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Acceso</label>
                        <select name="access_level" class="w-full rounded-2xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">
                            <option value="admin">Panel admin</option>
                            <option value="client">Panel cliente</option>
                        </select>
                    </div>
                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" onclick="closeModal('createRoleModal')" class="rounded-2xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-200 hover:bg-slate-50">Cancelar</button>
                        <button type="submit" class="rounded-2xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Crear rol</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php foreach ($roles as $role): ?>
    <div id="editRoleModal<?= $role['id'] ?>" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-slate-900/50" onclick="closeModal('editRoleModal<?= $role['id'] ?>')"></div>
        <div class="relative flex min-h-full items-center justify-center p-4">
            <div class="w-full max-w-xl rounded-3xl bg-white shadow-xl border border-slate-100">
                <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-slate-900">Editar rol</h3>
                    <button type="button" onclick="closeModal('editRoleModal<?= $role['id'] ?>')" class="text-slate-400 hover:text-slate-600 text-2xl leading-none">&times;</button>
                </div>
                <form action="admin_roles.php" method="POST" class="p-6 space-y-4">
                    <input type="hidden" name="action" value="edit_role">
                    <input type="hidden" name="role_id" value="<?= $role['id'] ?>">
                    <input type="hidden" name="current_slug" value="<?= htmlspecialchars($role['slug']) ?>">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Nombre visible</label>
                        <input type="text" name="name" value="<?= htmlspecialchars($role['name']) ?>" required class="w-full rounded-2xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Slug tecnico</label>
                        <input type="text" value="<?= htmlspecialchars($role['slug']) ?>" disabled class="w-full rounded-2xl border-slate-200 bg-slate-50 text-slate-500">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Acceso</label>
                        <select name="access_level" class="w-full rounded-2xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">
                            <option value="admin" <?= $role['access_level'] === 'admin' ? 'selected' : '' ?>>Panel admin</option>
                            <option value="client" <?= $role['access_level'] === 'client' ? 'selected' : '' ?>>Panel cliente</option>
                        </select>
                    </div>
                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" onclick="closeModal('editRoleModal<?= $role['id'] ?>')" class="rounded-2xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-200 hover:bg-slate-50">Cancelar</button>
                        <button type="submit" class="rounded-2xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Guardar cambios</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <script>
        function openModal(id) {
            document.getElementById(id).classList.remove('hidden');
        }

        function closeModal(id) {
            document.getElementById(id).classList.add('hidden');
        }
    </script>
</body>
</html>
