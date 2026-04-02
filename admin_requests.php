<?php
require_once 'config.php';
requireAuth('admin');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $request_id = $_POST['request_id'];
    $status = $_POST['status'];
    $stmt = $pdo->prepare("UPDATE requests SET status = ? WHERE id = ?");
    $stmt->execute([$status, $request_id]);
    $success = "Estado actualizado correctamente.";
}

$stmt = $pdo->query("
    SELECT r.*, s.title, s.type, u.name as client_name, u.phone as client_phone
    FROM requests r
    JOIN services s ON r.service_id = s.id
    JOIN users u ON r.client_id = u.id
    ORDER BY r.created_at DESC
");
$requests = $stmt->fetchAll();

function getRequestStatusText($status) {
    return match ($status) {
        'pendiente' => 'pendiente por informacion',
        'en_proceso' => 'en proceso de trabajo',
        'en_revision' => 'en revision final',
        'presentado' => 'presentado ante la DGII',
        'completado' => 'completado y entregado',
        default => 'en actualizacion',
    };
}

$requestTemplate = getWhatsAppTemplate(
    'whatsapp_request_template',
    "Hola {{client_name}}, {{greeting}} para recordarte que el tramite de *{{request_title}}* se encuentra actualmente *{{status_text}}*."
);
$whatsAppGreeting = getWhatsAppTemplate('whatsapp_greeting', 'te escribimos de tu Asesoria Financiera');
?>
<!DOCTYPE html>
<html lang="es" class="h-full bg-slate-50/50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Todas las Solicitudes - Portal Asesoria</title>
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
                    <h1 class="text-3xl font-bold tracking-tight text-slate-900">Todas las Tareas</h1>
                    <p class="mt-1 text-sm text-slate-500">Vista global en tiempo real de todos los procesos de tus clientes.</p>
                </div>
            </div>

            <?php if (isset($success)): ?>
            <div class="mb-6 rounded-2xl bg-green-50 p-4 border border-green-100">
                <p class="text-sm font-medium text-green-800"><?= htmlspecialchars($success) ?></p>
            </div>
            <?php endif; ?>

            <div class="bg-white rounded-3xl shadow-sm border border-slate-100 overflow-hidden">
                <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/50 flex items-center justify-between">
                    <h3 class="text-base font-semibold text-slate-900">Registro de Historial</h3>
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-100">
                        <thead>
                            <tr>
                                <th class="py-4 pl-6 pr-3 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Cliente</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Servicio</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Tiempos</th>
                                <th class="px-3 py-4 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Estado Actual</th>
                                <th class="relative py-4 pl-3 pr-6 sm:pr-8"><span class="sr-only">Acciones</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50 bg-white">
                            <?php if (empty($requests)): ?>
                            <tr>
                                <td colspan="5" class="py-8 text-center text-sm text-slate-400">No hay tareas o solicitudes en curso.</td>
                            </tr>
                            <?php endif; ?>

                            <?php foreach ($requests as $req): ?>
                            <?php
                            $requestMessage = '';
                            if ($req['client_phone']) {
                                $requestMessage = renderWhatsAppTemplate($requestTemplate, [
                                    'client_name' => $req['client_name'],
                                    'greeting' => $whatsAppGreeting,
                                    'request_title' => $req['title'],
                                    'status_text' => getRequestStatusText($req['status']),
                                ]);
                            }
                            ?>
                            <tr class="hover:bg-slate-50/50 transition-colors group">
                                <td class="whitespace-nowrap py-4 pl-6 pr-3">
                                    <div class="flex items-center gap-3">
                                        <div class="h-8 w-8 rounded-full bg-slate-100 flex items-center justify-center font-bold text-slate-500 text-xs shrink-0">
                                            <?= substr(strtoupper($req['client_name']), 0, 1) ?>
                                        </div>
                                        <a href="client_details.php?id=<?= $req['client_id'] ?>" class="text-sm font-semibold text-slate-900 hover:text-blue-600 transition-colors">
                                            <?= htmlspecialchars($req['client_name']) ?>
                                        </a>
                                    </div>
                                </td>
                                <td class="px-3 py-4 text-sm max-w-xs">
                                    <div class="flex flex-col gap-1 items-start">
                                        <span class="inline-flex py-0.5 px-2 rounded-md bg-slate-100 text-[10px] font-bold text-slate-600 tracking-wider uppercase">
                                            <?= $req['type'] === 'iguala' ? 'Iguala' : 'Puntual' ?>
                                        </span>
                                        <span class="font-medium text-slate-700 truncate w-48 text-ellipsis block" title="<?= htmlspecialchars($req['title']) ?>">
                                            <?= htmlspecialchars($req['title']) ?>
                                        </span>
                                    </div>
                                </td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm text-slate-500">
                                    <?php if ($req['type'] === 'iguala'): ?>
                                        Periodo: <span class="font-medium text-slate-700"><?= htmlspecialchars($req['period']) ?></span>
                                    <?php else: ?>
                                        Entrega: <span class="font-medium text-slate-700"><?= $req['estimated_delivery_date'] ? date('d/m/Y', strtotime($req['estimated_delivery_date'])) : 'No def.' ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="whitespace-nowrap px-3 py-4 text-sm">
                                    <?= getStatusBadge($req['status']) ?>
                                </td>
                                <td class="relative whitespace-nowrap py-4 pl-3 pr-6 sm:pr-8 text-right text-sm flex justify-end gap-3 items-center">
                                    <form action="admin_requests.php" method="POST" class="inline-flex">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                        <select name="status" onchange="this.form.submit()" class="block rounded-lg border-0 py-1.5 pl-3 pr-8 text-slate-700 ring-1 ring-inset ring-slate-200 focus:ring-2 focus:ring-blue-600 sm:text-xs font-medium bg-white shadow-sm opacity-0 group-hover:opacity-100 transition-opacity focus:opacity-100">
                                            <option value="pendiente" <?= $req['status'] === 'pendiente' ? 'selected' : '' ?>>Pend.</option>
                                            <option value="en_proceso" <?= $req['status'] === 'en_proceso' ? 'selected' : '' ?>>En proc.</option>
                                            <option value="en_revision" <?= $req['status'] === 'en_revision' ? 'selected' : '' ?>>Revisando</option>
                                            <?php if ($req['type'] === 'iguala'): ?>
                                            <option value="presentado" <?= $req['status'] === 'presentado' ? 'selected' : '' ?>>Present.</option>
                                            <?php else: ?>
                                            <option value="completado" <?= $req['status'] === 'completado' ? 'selected' : '' ?>>Complet.</option>
                                            <?php endif; ?>
                                        </select>
                                    </form>

                                    <a href="request_view.php?id=<?= $req['id'] ?>" title="Ver Mensajes y Archivos" class="shrink-0 p-1.5 text-blue-600 bg-blue-50 hover:bg-blue-100 rounded-lg transition-colors border border-blue-200/50">
                                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" /></svg>
                                    </a>

                                    <?php if ($req['client_phone']): ?>
                                    <button
                                        type="button"
                                        title="Enviar Recordatorio WhatsApp"
                                        data-phone="<?= htmlspecialchars(normalizePhoneForWhatsApp($req['client_phone'])) ?>"
                                        data-message="<?= htmlspecialchars($requestMessage) ?>"
                                        onclick="openWhatsAppModal(this)"
                                        class="shrink-0 p-1.5 text-green-600 bg-green-50 hover:bg-green-100 rounded-lg transition-colors border border-green-200/50"
                                    >
                                        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>

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
        let whatsappPhone = '';

        function openWhatsAppModal(button) {
            whatsappPhone = button.dataset.phone || '';
            document.getElementById('whatsappMessage').value = button.dataset.message || '';
            document.getElementById('whatsappModal').classList.remove('hidden');
        }

        function closeWhatsAppModal() {
            document.getElementById('whatsappModal').classList.add('hidden');
        }

        function sendWhatsApp() {
            const message = document.getElementById('whatsappMessage').value;
            if (!whatsappPhone || !message) {
                return;
            }

            window.open(`https://wa.me/${whatsappPhone}?text=${encodeURIComponent(message)}`, '_blank');
            closeWhatsAppModal();
        }
    </script>
</body>
</html>
