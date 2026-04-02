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
            $stmt = $pdo->prepare("INSERT INTO services (title, type) VALUES (?, ?)");
            $stmt->execute([$title, $type]);
            $success = "Servicio agregado exitosamente.";
        }
    } elseif ($action === 'edit_service') {
        $id = $_POST['service_id'];
        $title = trim($_POST['title'] ?? '');
        $type = $_POST['type'] ?? 'iguala';
        if ($title && $id) {
            $stmt = $pdo->prepare("UPDATE services SET title = ?, type = ? WHERE id = ?");
            $stmt->execute([$title, $type, $id]);
            $success = "Servicio actualizado.";
        }
    } elseif ($action === 'delete_service') {
        $id = $_POST['service_id'];
        $stmt = $pdo->prepare("DELETE FROM services WHERE id = ?");
        $stmt->execute([$id]);
        $success = "Servicio eliminado.";
    }
}

$services = $pdo->query("SELECT * FROM services ORDER BY type, title")->fetchAll();
$igualas = array_filter($services, fn($s) => $s['type'] === 'iguala');
$puntuales = array_filter($services, fn($s) => $s['type'] === 'puntual');
?>
<!DOCTYPE html>
<html lang="es" class="h-full bg-slate-50/50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Administrar Servicios - Portal Asesoría</title>
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
        <div class="px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto">
            
            <div class="sm:flex sm:items-center sm:justify-between mb-8">
                <div>
                    <h1 class="text-3xl font-bold tracking-tight text-slate-900">Servicios</h1>
                    <p class="mt-1 text-sm text-slate-500">Administra los servicios que ofreces a tus clientes.</p>
                </div>
                <button type="button" onclick="document.getElementById('addServiceModal').classList.remove('hidden')" class="mt-4 sm:mt-0 inline-flex items-center gap-2 rounded-2xl bg-slate-900 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-slate-800 transition-all hover:-translate-y-0.5">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                    Nuevo Servicio
                </button>
            </div>

            <?php if ($success): ?>
            <div class="mb-6 rounded-2xl bg-green-50 p-4 border border-green-100"><p class="text-sm font-medium text-green-800"><?= htmlspecialchars($success) ?></p></div>
            <?php endif; ?>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Igualas -->
                <div>
                    <div class="flex items-center gap-3 mb-4">
                        <div class="p-2 bg-blue-50 text-blue-600 rounded-xl"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" /></svg></div>
                        <h3 class="text-lg font-bold text-slate-900">Igualas Mensuales</h3>
                    </div>
                    <div class="space-y-3">
                        <?php foreach ($igualas as $s): ?>
                        <div class="bg-white rounded-2xl p-4 shadow-sm border border-slate-100 flex items-center justify-between group hover:border-blue-100 transition-colors">
                            <span class="font-medium text-slate-800"><?= htmlspecialchars($s['title']) ?></span>
                            <div class="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button onclick="openEditModal(<?= $s['id'] ?>, '<?= htmlspecialchars(addslashes($s['title'])) ?>', '<?= $s['type'] ?>')" class="p-1.5 text-slate-400 hover:text-blue-600 rounded-lg hover:bg-blue-50 transition-colors">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" /></svg>
                                </button>
                                <form action="admin_services.php" method="POST" onsubmit="return confirm('¿Eliminar este servicio?')">
                                    <input type="hidden" name="action" value="delete_service">
                                    <input type="hidden" name="service_id" value="<?= $s['id'] ?>">
                                    <button type="submit" class="p-1.5 text-slate-400 hover:text-red-600 rounded-lg hover:bg-red-50 transition-colors">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Puntuales -->
                <div>
                    <div class="flex items-center gap-3 mb-4">
                        <div class="p-2 bg-indigo-50 text-indigo-600 rounded-xl"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" /></svg></div>
                        <h3 class="text-lg font-bold text-slate-900">Solicitudes Puntuales</h3>
                    </div>
                    <div class="space-y-3">
                        <?php foreach ($puntuales as $s): ?>
                        <div class="bg-white rounded-2xl p-4 shadow-sm border border-slate-100 flex items-center justify-between group hover:border-indigo-100 transition-colors">
                            <span class="font-medium text-slate-800"><?= htmlspecialchars($s['title']) ?></span>
                            <div class="flex items-center gap-2 opacity-0 group-hover:opacity-100 transition-opacity">
                                <button onclick="openEditModal(<?= $s['id'] ?>, '<?= htmlspecialchars(addslashes($s['title'])) ?>', '<?= $s['type'] ?>')" class="p-1.5 text-slate-400 hover:text-blue-600 rounded-lg hover:bg-blue-50 transition-colors">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" /></svg>
                                </button>
                                <form action="admin_services.php" method="POST" onsubmit="return confirm('¿Eliminar este servicio?')">
                                    <input type="hidden" name="action" value="delete_service">
                                    <input type="hidden" name="service_id" value="<?= $s['id'] ?>">
                                    <button type="submit" class="p-1.5 text-slate-400 hover:text-red-600 rounded-lg hover:bg-red-50 transition-colors">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg>
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Add Service Modal -->
    <div id="addServiceModal" class="relative z-50 hidden">
        <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm"></div>
        <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
            <div class="flex min-h-full items-center justify-center p-4">
                <div class="relative w-full max-w-md rounded-3xl bg-white shadow-2xl p-8">
                    <h3 class="text-lg font-bold text-slate-900 mb-6">Agregar Servicio</h3>
                    <form action="admin_services.php" method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="add_service">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Nombre del Servicio</label>
                            <input type="text" name="title" required class="w-full rounded-xl border-0 py-2.5 px-3 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600 sm:text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Tipo de Servicio</label>
                            <select name="type" class="w-full rounded-xl border-0 py-2.5 px-3 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600 sm:text-sm">
                                <option value="iguala">Iguala Mensual</option>
                                <option value="puntual">Solicitud Puntual</option>
                            </select>
                        </div>
                        <div class="flex gap-3 pt-4">
                            <button type="submit" class="flex-1 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800 transition-all">Guardar</button>
                            <button type="button" onclick="document.getElementById('addServiceModal').classList.add('hidden')" class="flex-1 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 transition-all">Cancelar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Service Modal -->
    <div id="editServiceModal" class="relative z-50 hidden">
        <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm"></div>
        <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
            <div class="flex min-h-full items-center justify-center p-4">
                <div class="relative w-full max-w-md rounded-3xl bg-white shadow-2xl p-8">
                    <h3 class="text-lg font-bold text-slate-900 mb-6">Editar Servicio</h3>
                    <form action="admin_services.php" method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="edit_service">
                        <input type="hidden" name="service_id" id="edit_service_id">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Nombre del Servicio</label>
                            <input type="text" name="title" id="edit_service_title" required class="w-full rounded-xl border-0 py-2.5 px-3 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600 sm:text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Tipo</label>
                            <select name="type" id="edit_service_type" class="w-full rounded-xl border-0 py-2.5 px-3 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600 sm:text-sm">
                                <option value="iguala">Iguala Mensual</option>
                                <option value="puntual">Solicitud Puntual</option>
                            </select>
                        </div>
                        <div class="flex gap-3 pt-4">
                            <button type="submit" class="flex-1 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800 transition-all">Actualizar</button>
                            <button type="button" onclick="document.getElementById('editServiceModal').classList.add('hidden')" class="flex-1 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 transition-all">Cancelar</button>
                        </div>
                    </form>
                </div>
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
</body>
</html>
