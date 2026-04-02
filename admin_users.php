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
    if ($user['access_level'] === 'admin') {
        $adminCount++;
    } else {
        $clientCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="es" class="h-full bg-slate-50/50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Usuarios - Portal Asesoria</title>
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
                    <h1 class="text-3xl font-bold tracking-tight text-slate-900">Usuarios</h1>
                    <p class="mt-1 text-sm text-slate-500">Crea usuarios, asigna roles y controla quien entra al panel administrativo o al panel cliente.</p>
                </div>
                <button type="button" onclick="openModal('createUserModal')" class="mt-4 sm:mt-0 inline-flex items-center gap-2 rounded-2xl bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 transition-all hover:-translate-y-0.5">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                    Nuevo usuario
                </button>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-5 mb-8">
                <div class="rounded-3xl bg-white px-6 py-5 shadow-sm border border-slate-100">
                    <p class="text-sm text-slate-500">Total usuarios</p>
                    <p class="mt-2 text-3xl font-semibold text-slate-900"><?= count($users) ?></p>
                </div>
                <div class="rounded-3xl bg-white px-6 py-5 shadow-sm border border-slate-100">
                    <p class="text-sm text-slate-500">Acceso admin</p>
                    <p class="mt-2 text-3xl font-semibold text-slate-900"><?= $adminCount ?></p>
                </div>
                <div class="rounded-3xl bg-white px-6 py-5 shadow-sm border border-slate-100">
                    <p class="text-sm text-slate-500">Acceso cliente</p>
                    <p class="mt-2 text-3xl font-semibold text-slate-900"><?= $clientCount ?></p>
                </div>
            </div>

            <?php if ($success): ?>
            <div class="mb-6 rounded-2xl bg-green-50 p-4 border border-green-100 text-sm font-medium text-green-800"><?= htmlspecialchars($success) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
            <div class="mb-6 rounded-2xl bg-red-50 p-4 border border-red-100 text-sm font-medium text-red-800"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <div class="bg-white rounded-3xl shadow-sm border border-slate-100 overflow-hidden">
                <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/60">
                    <h2 class="text-base font-semibold text-slate-900">Directorio de usuarios</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100">
                        <thead class="bg-white">
                            <tr class="text-left text-xs font-semibold uppercase tracking-wide text-slate-400">
                                <th class="px-6 py-4">Usuario</th>
                                <th class="px-6 py-4">Rol</th>
                                <th class="px-6 py-4">Acceso</th>
                                <th class="px-6 py-4">Registro</th>
                                <th class="px-6 py-4 text-right">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($users as $user): ?>
                            <tr class="hover:bg-slate-50/80 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-4">
                                        <div class="h-11 w-11 rounded-full bg-slate-100 border border-slate-200 flex items-center justify-center text-sm font-bold text-slate-600">
                                            <?= htmlspecialchars(substr(strtoupper($user['name']), 0, 1)) ?>
                                        </div>
                                        <div>
                                            <p class="text-sm font-semibold text-slate-900"><?= htmlspecialchars($user['name']) ?></p>
                                            <p class="text-xs text-slate-500"><?= htmlspecialchars($user['email']) ?></p>
                                            <p class="text-xs text-slate-400"><?= htmlspecialchars($user['phone'] ?: 'Sin telefono') ?></p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-700"><?= htmlspecialchars($user['role_name']) ?></td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold <?= $user['access_level'] === 'admin' ? 'bg-blue-50 text-blue-700' : 'bg-emerald-50 text-emerald-700' ?>">
                                        <?= $user['access_level'] === 'admin' ? 'Panel admin' : 'Panel cliente' ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 text-sm text-slate-500"><?= date('d/m/Y', strtotime($user['created_at'])) ?></td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center justify-end gap-2">
                                        <button type="button" onclick="openModal('editUserModal<?= $user['id'] ?>')" class="rounded-xl bg-white px-3 py-2 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-200 hover:bg-slate-50">Editar</button>
                                        <?php if ((int) $user['id'] !== (int) $_SESSION['user_id']): ?>
                                        <form action="admin_users.php" method="POST" onsubmit="return confirm('Se eliminara este usuario. Continuar?')">
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                            <button type="submit" class="rounded-xl bg-red-50 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-100">Eliminar</button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

    <div id="createUserModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-slate-900/50" onclick="closeModal('createUserModal')"></div>
        <div class="relative flex min-h-full items-center justify-center p-4">
            <div class="w-full max-w-2xl rounded-3xl bg-white shadow-xl border border-slate-100">
                <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-slate-900">Crear usuario</h3>
                    <button type="button" onclick="closeModal('createUserModal')" class="text-slate-400 hover:text-slate-600 text-2xl leading-none">&times;</button>
                </div>
                <form action="admin_users.php" method="POST" class="p-6 space-y-4">
                    <input type="hidden" name="action" value="add_user">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">Nombre</label>
                            <input type="text" name="name" required class="w-full rounded-2xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">Correo</label>
                            <input type="email" name="email" required class="w-full rounded-2xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">Telefono</label>
                            <input type="text" name="phone" class="w-full rounded-2xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">Rol</label>
                            <select name="role" required class="w-full rounded-2xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">
                                <?php foreach ($roles as $role): ?>
                                <option value="<?= htmlspecialchars($role['slug']) ?>"><?= htmlspecialchars($role['name']) ?> (<?= htmlspecialchars($role['access_level']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Contrasena inicial</label>
                        <input type="password" name="password" required class="w-full rounded-2xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">
                    </div>
                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" onclick="closeModal('createUserModal')" class="rounded-2xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-200 hover:bg-slate-50">Cancelar</button>
                        <button type="submit" class="rounded-2xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800">Crear usuario</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php foreach ($users as $user): ?>
    <div id="editUserModal<?= $user['id'] ?>" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-slate-900/50" onclick="closeModal('editUserModal<?= $user['id'] ?>')"></div>
        <div class="relative flex min-h-full items-center justify-center p-4">
            <div class="w-full max-w-2xl rounded-3xl bg-white shadow-xl border border-slate-100">
                <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-slate-900">Editar usuario</h3>
                    <button type="button" onclick="closeModal('editUserModal<?= $user['id'] ?>')" class="text-slate-400 hover:text-slate-600 text-2xl leading-none">&times;</button>
                </div>
                <form action="admin_users.php" method="POST" class="p-6 space-y-4">
                    <input type="hidden" name="action" value="edit_user">
                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">Nombre</label>
                            <input type="text" name="name" value="<?= htmlspecialchars($user['name']) ?>" required class="w-full rounded-2xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">Correo</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required class="w-full rounded-2xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">Telefono</label>
                            <input type="text" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" class="w-full rounded-2xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">Rol</label>
                            <select name="role" required class="w-full rounded-2xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">
                                <?php foreach ($rolesBySlug as $roleSlug => $role): ?>
                                <option value="<?= htmlspecialchars($roleSlug) ?>" <?= $roleSlug === $user['role'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($role['name']) ?> (<?= htmlspecialchars($role['access_level']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-2">Nueva contrasena</label>
                        <input type="password" name="password" class="w-full rounded-2xl border-slate-200 focus:border-blue-500 focus:ring-blue-500">
                        <p class="mt-1 text-xs text-slate-400">Dejalo vacio para mantener la actual.</p>
                    </div>
                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" onclick="closeModal('editUserModal<?= $user['id'] ?>')" class="rounded-2xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-200 hover:bg-slate-50">Cancelar</button>
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
