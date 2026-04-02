<?php
require_once 'config.php';
requireAuth('admin');

$client_id = $_GET['id'] ?? null;
if (!$client_id) { header('Location: admin_dashboard.php'); exit; }

$success = $error = null;

// Handle Form Submissions BEFORE fetching data
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'edit_client') {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $iguala_amount = $_POST['iguala_amount'] ?? 0;
        $iguala_frequency = $_POST['iguala_frequency'] ?? 'mensual';
        $stmt = $pdo->prepare("UPDATE users SET name=?, email=?, phone=?, iguala_amount=?, iguala_frequency=? WHERE id=?");
        $stmt->execute([$name, $email, $phone, $iguala_amount, $iguala_frequency, $client_id]);
        $success = "Cliente actualizado correctamente.";
    } elseif ($action === 'delete_client') {
        $pdo->prepare("
            DELETE u
            FROM users u
            LEFT JOIN roles r ON r.slug = u.role
            WHERE u.id = ?
              AND COALESCE(r.access_level, CASE WHEN u.role = 'admin' THEN 'admin' ELSE 'client' END) = 'client'
        ")->execute([$client_id]);
        header('Location: admin_dashboard.php');
        exit;
    } elseif ($action === 'add_request') {
        $service_id = $_POST['service_id'];
        $svc_stmt = $pdo->prepare("SELECT type FROM services WHERE id = ?");
        $svc_stmt->execute([$service_id]);
        $service = $svc_stmt->fetch();
        if ($service['type'] === 'iguala') {
            $period = $_POST['period'] ?? date('Y-m');
            $pdo->prepare("INSERT INTO requests (client_id, service_id, status, period) VALUES (?, ?, 'pendiente', ?)")->execute([$client_id, $service_id, $period]);
        } else {
            $estimated_date = $_POST['estimated_date'] ?? null;
            $pdo->prepare("INSERT INTO requests (client_id, service_id, status, estimated_delivery_date) VALUES (?, ?, 'pendiente', ?)")->execute([$client_id, $service_id, $estimated_date]);
        }
        $success = "Servicio asignado correctamente.";
    } elseif ($action === 'update_status') {
        $request_id = $_POST['request_id'];
        $status = $_POST['status'];
        $pdo->prepare("UPDATE requests SET status = ? WHERE id = ? AND client_id = ?")->execute([$status, $request_id, $client_id]);
        $success = "Estado actualizado.";
    } elseif ($action === 'delete_request') {
        $request_id = $_POST['request_id'];
        $pdo->prepare("DELETE FROM requests WHERE id = ? AND client_id = ?")->execute([$request_id, $client_id]);
        $success = "Solicitud eliminada.";
    }
}

// Fetch client
$stmt = $pdo->prepare("
    SELECT u.*
    FROM users u
    LEFT JOIN roles r ON r.slug = u.role
    WHERE u.id = ?
      AND COALESCE(r.access_level, CASE WHEN u.role = 'admin' THEN 'admin' ELSE 'client' END) = 'client'
");
$stmt->execute([$client_id]);
$client = $stmt->fetch();
if (!$client) { die("Cliente no encontrado."); }

// Fetch services and requests
$services = $pdo->query("SELECT * FROM services ORDER BY type, title")->fetchAll();
$stmt = $pdo->prepare("SELECT r.*, s.title, s.type FROM requests r JOIN services s ON r.service_id = s.id WHERE r.client_id = ? ORDER BY r.created_at DESC");
$stmt->execute([$client_id]);
$requests = $stmt->fetchAll();
$igualas = array_filter($requests, fn($r) => $r['type'] === 'iguala');
$puntuales = array_filter($requests, fn($r) => $r['type'] === 'puntual');

function getWhatsAppLink($phone, $clientName, $requestTitle, $status) {
    if (!$phone) return "#";
    $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
    $greeting = getSetting('whatsapp_greeting', 'te escribimos de tu Asesoría Financiera');
    $statusText = match($status) {
        'pendiente' => 'pendiente por información', 'en_proceso' => 'en proceso de trabajo',
        'en_revision' => 'en revisión final', 'presentado' => 'presentado ante la DGII',
        'completado' => 'completado y entregado', default => 'en trámite'
    };
    $message = "Hola $clientName, $greeting para recordarte que el trámite de *$requestTitle* se encuentra actualmente *$statusText*.";
    return "https://wa.me/" . $cleanPhone . "?text=" . urlencode($message);
}

$frequencies = ['mensual' => 'Mensual', 'quincenal' => 'Quincenal', 'semanal' => 'Semanal', 'bimestral' => 'Bimestral', 'trimestral' => 'Trimestral', 'semestral' => 'Semestral', 'anual' => 'Anual'];
?>
<!DOCTYPE html>
<html lang="es" class="h-full bg-slate-50/50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perfil del Cliente - Portal Asesoría</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Outfit', sans-serif; }
    </style>
    <script>
        function toggleServiceFields(select) {
            const type = select.options[select.selectedIndex].getAttribute('data-type');
            document.getElementById('period_field').classList.toggle('hidden', type !== 'iguala');
            document.getElementById('date_field').classList.toggle('hidden', type !== 'puntual');
        }
    </script>
</head>
<body class="h-full">
    <?php include 'components/header.php'; ?>
    <?php include 'components/sidebar.php'; ?>

    <main class="lg:pl-72 py-8">
        <div class="px-4 sm:px-6 lg:px-8 max-w-7xl mx-auto">
            
            <div class="mb-4">
                <a href="admin_dashboard.php" class="text-sm font-medium text-slate-500 hover:text-blue-600 flex items-center gap-1 transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
                    Volver al Directorio
                </a>
            </div>

            <?php if ($success): ?>
            <div class="mb-6 rounded-2xl bg-green-50 p-4 border border-green-100"><p class="text-sm font-medium text-green-800"><?= htmlspecialchars($success) ?></p></div>
            <?php endif; ?>

            <!-- Profile Header with Edit/Delete -->
            <div class="bg-white rounded-3xl p-8 shadow-sm border border-slate-100 mb-8">
                <div class="flex flex-col md:flex-row md:items-center gap-6 justify-between">
                    <div class="flex items-center gap-6">
                        <div class="h-20 w-20 flex-none rounded-full bg-blue-50 border border-blue-100 flex items-center justify-center">
                            <span class="text-3xl font-bold text-blue-600"><?= substr(strtoupper($client['name']), 0, 1) ?></span>
                        </div>
                        <div>
                            <h2 class="text-2xl font-bold text-slate-900"><?= htmlspecialchars($client['name']) ?></h2>
                            <div class="mt-2 flex flex-wrap gap-4 text-sm text-slate-500">
                                <span class="flex items-center gap-1.5">📧 <?= htmlspecialchars($client['email']) ?></span>
                                <span class="flex items-center gap-1.5">📱 <?= htmlspecialchars($client['phone'] ?: 'N/A') ?></span>
                            </div>
                            <div class="mt-2 flex flex-wrap gap-3">
                                <span class="inline-flex items-center rounded-lg bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/10">
                                    💰 Iguala: RD$ <?= number_format($client['iguala_amount'], 2) ?> (<?= htmlspecialchars($frequencies[$client['iguala_frequency']] ?? $client['iguala_frequency']) ?>)
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <button onclick="document.getElementById('editClientModal').classList.remove('hidden')" class="rounded-xl bg-white px-4 py-2 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 transition-all">Editar</button>
                        <form action="client_details.php?id=<?= $client_id ?>" method="POST" onsubmit="return confirm('¿Segura que deseas eliminar este cliente? Se eliminarán todas sus solicitudes.')">
                            <input type="hidden" name="action" value="delete_client">
                            <button type="submit" class="rounded-xl bg-red-50 px-4 py-2 text-sm font-semibold text-red-600 ring-1 ring-inset ring-red-200 hover:bg-red-100 transition-all">Eliminar</button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Add Request Form -->
            <div class="bg-white rounded-3xl shadow-sm border border-slate-100 mb-8 overflow-hidden">
                <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/50">
                    <h3 class="text-base font-semibold text-slate-900">Asignar Nuevo Servicio</h3>
                </div>
                <div class="p-6">
                    <form action="client_details.php?id=<?= $client_id ?>" method="POST" class="flex flex-col md:flex-row md:items-end gap-4">
                        <input type="hidden" name="action" value="add_request">
                        <div class="flex-1">
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Servicio a Asignar</label>
                            <select name="service_id" onchange="toggleServiceFields(this)" required class="block w-full rounded-xl border-0 py-2.5 px-3 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600 sm:text-sm">
                                <option value="">Selecciona un servicio...</option>
                                <optgroup label="Igualas Mensuales">
                                    <?php foreach ($services as $s): if($s['type'] === 'iguala'): ?>
                                    <option value="<?= $s['id'] ?>" data-type="iguala"><?= htmlspecialchars($s['title']) ?></option>
                                    <?php endif; endforeach; ?>
                                </optgroup>
                                <optgroup label="Solicitudes Puntuales">
                                    <?php foreach ($services as $s): if($s['type'] === 'puntual'): ?>
                                    <option value="<?= $s['id'] ?>" data-type="puntual"><?= htmlspecialchars($s['title']) ?></option>
                                    <?php endif; endforeach; ?>
                                </optgroup>
                            </select>
                        </div>
                        <div id="period_field" class="hidden w-full md:w-48">
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Período</label>
                            <input type="month" name="period" value="<?= date('Y-m') ?>" class="block w-full rounded-xl border-0 py-2.5 px-3 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 sm:text-sm">
                        </div>
                        <div id="date_field" class="hidden w-full md:w-48">
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Fecha Estimada</label>
                            <input type="date" name="estimated_date" class="block w-full rounded-xl border-0 py-2.5 px-3 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 sm:text-sm">
                        </div>
                        <button type="submit" class="rounded-xl bg-slate-900 px-6 py-2.5 text-sm font-semibold text-white hover:bg-slate-800 transition-all">Asignar</button>
                    </form>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <!-- Igualas -->
                <div>
                    <h3 class="text-lg font-bold text-slate-900 mb-4 px-2">Igualas Asignadas</h3>
                    <div class="space-y-4">
                        <?php if (empty($igualas)): ?>
                            <div class="py-10 text-center bg-white rounded-3xl border border-slate-100 border-dashed"><p class="text-slate-400 text-sm">Sin igualas activas.</p></div>
                        <?php else: foreach ($igualas as $req): ?>
                        <div class="bg-white rounded-3xl p-5 shadow-sm border border-slate-100 hover:border-blue-100 transition-all">
                            <div class="flex items-start justify-between mb-4">
                                <div>
                                    <p class="font-semibold text-slate-900"><?= htmlspecialchars($req['title']) ?></p>
                                    <p class="text-xs text-slate-400 mt-1">Período: <span class="text-slate-600"><?= htmlspecialchars($req['period']) ?></span></p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <?= getStatusBadge($req['status']) ?>
                                    <form action="client_details.php?id=<?= $client_id ?>" method="POST" onsubmit="return confirm('¿Eliminar esta solicitud?')" class="inline">
                                        <input type="hidden" name="action" value="delete_request">
                                        <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                        <button class="p-1 text-slate-300 hover:text-red-500 transition-colors"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg></button>
                                    </form>
                                </div>
                            </div>
                            <hr class="border-slate-100 mb-4">
                            <form action="client_details.php?id=<?= $client_id ?>" method="POST" class="flex items-center gap-3">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                <select name="status" onchange="this.form.submit()" class="block w-full rounded-lg border-0 py-2 pl-3 pr-10 text-slate-700 ring-1 ring-inset ring-slate-200 sm:text-sm bg-slate-50/50">
                                    <option value="pendiente" <?= $req['status'] === 'pendiente' ? 'selected' : '' ?>>🔴 Pendiente</option>
                                    <option value="en_proceso" <?= $req['status'] === 'en_proceso' ? 'selected' : '' ?>>🟡 En proceso</option>
                                    <option value="en_revision" <?= $req['status'] === 'en_revision' ? 'selected' : '' ?>>🔵 En revisión</option>
                                    <option value="presentado" <?= $req['status'] === 'presentado' ? 'selected' : '' ?>>🟢 Presentado</option>
                                </select>
                                <a href="request_view.php?id=<?= $req['id'] ?>" class="shrink-0 p-2 text-blue-600 bg-blue-50 hover:bg-blue-100 rounded-lg transition-colors border border-blue-200/50" title="Ver Mensajes y Archivos">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" /></svg>
                                </a>
                                <?php if ($client['phone']): ?>
                                <a href="<?= getWhatsAppLink($client['phone'], $client['name'], $req['title'], $req['status']) ?>" target="_blank" class="shrink-0 p-2 text-green-600 bg-green-50 hover:bg-green-100 rounded-lg transition-colors border border-green-200/50" title="WhatsApp">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                                </a>
                                <?php endif; ?>
                            </form>
                        </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <!-- Puntuales -->
                <div>
                    <h3 class="text-lg font-bold text-slate-900 mb-4 px-2">Trámites Puntuales</h3>
                    <div class="space-y-4">
                        <?php if (empty($puntuales)): ?>
                            <div class="py-10 text-center bg-white rounded-3xl border border-slate-100 border-dashed"><p class="text-slate-400 text-sm">Sin trámites puntuales.</p></div>
                        <?php else: foreach ($puntuales as $req): ?>
                        <div class="bg-white rounded-3xl p-5 shadow-sm border border-slate-100 hover:border-indigo-100 transition-all">
                            <div class="flex items-start justify-between mb-4">
                                <div>
                                    <p class="font-semibold text-slate-900"><?= htmlspecialchars($req['title']) ?></p>
                                    <p class="text-xs text-slate-400 mt-1">Estimado: <span class="text-slate-600"><?= $req['estimated_delivery_date'] ? date('d/m/Y', strtotime($req['estimated_delivery_date'])) : 'No definido' ?></span></p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <?= getStatusBadge($req['status']) ?>
                                    <form action="client_details.php?id=<?= $client_id ?>" method="POST" onsubmit="return confirm('¿Eliminar?')" class="inline">
                                        <input type="hidden" name="action" value="delete_request">
                                        <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                        <button class="p-1 text-slate-300 hover:text-red-500 transition-colors"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg></button>
                                    </form>
                                </div>
                            </div>
                            <hr class="border-slate-100 mb-4">
                            <form action="client_details.php?id=<?= $client_id ?>" method="POST" class="flex items-center gap-3">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                <select name="status" onchange="this.form.submit()" class="block w-full rounded-lg border-0 py-2 pl-3 pr-10 text-slate-700 ring-1 ring-inset ring-slate-200 sm:text-sm bg-slate-50/50">
                                    <option value="pendiente" <?= $req['status'] === 'pendiente' ? 'selected' : '' ?>>🔴 Pendiente</option>
                                    <option value="en_proceso" <?= $req['status'] === 'en_proceso' ? 'selected' : '' ?>>🟡 En proceso</option>
                                    <option value="en_revision" <?= $req['status'] === 'en_revision' ? 'selected' : '' ?>>🔵 En revisión</option>
                                    <option value="completado" <?= $req['status'] === 'completado' ? 'selected' : '' ?>>🟢 Completado</option>
                                </select>
                                <a href="request_view.php?id=<?= $req['id'] ?>" class="shrink-0 p-2 text-indigo-600 bg-indigo-50 hover:bg-indigo-100 rounded-lg transition-colors border border-indigo-200/50" title="Ver Mensajes y Archivos">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" /></svg>
                                </a>
                                <?php if ($client['phone']): ?>
                                <a href="<?= getWhatsAppLink($client['phone'], $client['name'], $req['title'], $req['status']) ?>" target="_blank" class="shrink-0 p-2 text-green-600 bg-green-50 hover:bg-green-100 rounded-lg transition-colors border border-green-200/50" title="WhatsApp">
                                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                                </a>
                                <?php endif; ?>
                            </form>
                        </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Edit Client Modal -->
    <div id="editClientModal" class="relative z-50 hidden">
        <div class="fixed inset-0 bg-slate-900/40 backdrop-blur-sm"></div>
        <div class="fixed inset-0 z-10 w-screen overflow-y-auto">
            <div class="flex min-h-full items-center justify-center p-4">
                <div class="relative w-full max-w-lg rounded-3xl bg-white shadow-2xl p-8">
                    <h3 class="text-lg font-bold text-slate-900 mb-6">Editar Cliente</h3>
                    <form action="client_details.php?id=<?= $client_id ?>" method="POST" class="space-y-4">
                        <input type="hidden" name="action" value="edit_client">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div class="sm:col-span-2">
                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Nombre</label>
                                <input type="text" name="name" value="<?= htmlspecialchars($client['name']) ?>" required class="w-full rounded-xl border-0 py-2.5 px-3 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600 sm:text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Email</label>
                                <input type="email" name="email" value="<?= htmlspecialchars($client['email']) ?>" required class="w-full rounded-xl border-0 py-2.5 px-3 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600 sm:text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Teléfono</label>
                                <input type="text" name="phone" value="<?= htmlspecialchars($client['phone']) ?>" class="w-full rounded-xl border-0 py-2.5 px-3 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600 sm:text-sm">
                            </div>
                        </div>
                        <hr class="border-slate-100">
                        <p class="text-sm font-semibold text-slate-700">Configuración de Iguala</p>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Monto (RD$)</label>
                                <input type="number" step="0.01" name="iguala_amount" value="<?= $client['iguala_amount'] ?>" class="w-full rounded-xl border-0 py-2.5 px-3 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600 sm:text-sm">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Frecuencia</label>
                                <select name="iguala_frequency" class="w-full rounded-xl border-0 py-2.5 px-3 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-300 focus:ring-2 focus:ring-blue-600 sm:text-sm">
                                    <?php foreach ($frequencies as $key => $label): ?>
                                    <option value="<?= $key ?>" <?= $client['iguala_frequency'] === $key ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="flex gap-3 pt-4">
                            <button type="submit" class="flex-1 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800 transition-all">Guardar Cambios</button>
                            <button type="button" onclick="document.getElementById('editClientModal').classList.add('hidden')" class="flex-1 rounded-xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-300 hover:bg-slate-50 transition-all">Cancelar</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div id="whatsappModal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-slate-900/50" onclick="closeWhatsAppModal()"></div>
        <div class="relative flex min-h-full items-center justify-center p-4">
            <div class="w-full max-w-2xl rounded-3xl bg-white shadow-xl border border-slate-100">
                <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-slate-900">Editar mensaje de WhatsApp</h3>
                    <button type="button" onclick="closeWhatsAppModal()" class="text-slate-400 hover:text-slate-600 text-2xl leading-none">&times;</button>
                </div>
                <div class="p-6 space-y-4">
                    <textarea id="whatsappMessage" rows="10" class="w-full rounded-2xl border-slate-200 focus:border-green-500 focus:ring-green-500"></textarea>
                    <div class="flex justify-end gap-3">
                        <button type="button" onclick="closeWhatsAppModal()" class="rounded-2xl bg-white px-4 py-2.5 text-sm font-semibold text-slate-700 ring-1 ring-inset ring-slate-200 hover:bg-slate-50">Cancelar</button>
                        <button type="button" onclick="sendWhatsApp()" class="rounded-2xl bg-green-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-green-700">Abrir WhatsApp</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        let whatsappUrl = '';

        function openWhatsAppModalFromUrl(url) {
            whatsappUrl = url;
            const parsed = new URL(url);
            document.getElementById('whatsappMessage').value = decodeURIComponent(parsed.searchParams.get('text') || '');
            document.getElementById('whatsappModal').classList.remove('hidden');
        }

        function closeWhatsAppModal() {
            document.getElementById('whatsappModal').classList.add('hidden');
        }

        function sendWhatsApp() {
            if (!whatsappUrl) {
                return;
            }

            const parsed = new URL(whatsappUrl);
            parsed.searchParams.set('text', document.getElementById('whatsappMessage').value);
            window.open(parsed.toString(), '_blank');
            closeWhatsAppModal();
        }

        document.querySelectorAll('a[href*="wa.me"]').forEach((link) => {
            link.addEventListener('click', function(event) {
                event.preventDefault();
                openWhatsAppModalFromUrl(this.href);
            });
        });
    </script>
</body>
</html>
