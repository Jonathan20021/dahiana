<?php
require_once 'config.php';
requireAuth('admin');

$client_id = $_GET['id'] ?? null;
if (!$client_id) { header('Location: admin_clients.php'); exit; }

$success = $error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'edit_client') {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $phone = trim($_POST['phone']);
        $rnc = trim($_POST['rnc'] ?? '');
        $business_name = trim($_POST['business_name'] ?? '');
        $business_type = $_POST['business_type'] ?? 'fisica';
        $client_status = $_POST['client_status'] ?? 'activo';
        $address = trim($_POST['address'] ?? '');
        $started_at = $_POST['started_at'] ?? null;
        $notes = trim($_POST['notes'] ?? '');
        $iguala_amount = (float)($_POST['iguala_amount'] ?? 0);
        $iguala_frequency = $_POST['iguala_frequency'] ?? 'mensual';
        $tax_regime = $_POST['tax_regime'] ?? 'ordinario';
        $economic_activity = trim($_POST['economic_activity'] ?? '');
        $fiscal_year_close = $_POST['fiscal_year_close'] ?? '12-31';
        $employee_count = (int)($_POST['employee_count'] ?? 0);
        $operation_type = $_POST['operation_type'] ?? 'servicios';

        $pdo->prepare("UPDATE users SET name=?, email=?, phone=?, rnc=?, business_name=?, business_type=?, client_status=?, address=?, started_at=?, notes=?, iguala_amount=?, iguala_frequency=?, tax_regime=?, economic_activity=?, fiscal_year_close=?, employee_count=?, operation_type=? WHERE id=?")
            ->execute([$name, $email, $phone, $rnc, $business_name, $business_type, $client_status, $address, $started_at ?: null, $notes, $iguala_amount, $iguala_frequency, $tax_regime, $economic_activity, $fiscal_year_close, $employee_count, $operation_type, $client_id]);
        logClientActivity($client_id, 'updated', 'Datos del cliente actualizados');
        // Re-sync obligations against the new profile
        $gen = generateObligationsForClient($client_id, 6);
        $success = "Cliente actualizado. Obligaciones DGII sincronizadas (+{$gen} nuevas).";
    } elseif ($action === 'regenerate_obligations') {
        $gen = generateObligationsForClient($client_id, 12);
        $success = "Obligaciones DGII regeneradas para los proximos 12 meses (+{$gen}).";
    } elseif ($action === 'toggle_obligation') {
        $obId = (int)($_POST['obligation_id'] ?? 0);
        $newStatus = $_POST['new_status'] ?? 'completado';
        $pdo->prepare("UPDATE tax_obligations SET status = ?, completed_at = " . ($newStatus === 'completado' ? 'NOW()' : 'NULL') . " WHERE id = ? AND client_id = ?")
            ->execute([$newStatus, $obId, $client_id]);
        logClientActivity($client_id, 'tax', "Obligacion marcada como {$newStatus}");
        $success = "Obligacion actualizada.";
    } elseif ($action === 'delete_client') {
        $pdo->prepare("
            DELETE u FROM users u
            LEFT JOIN roles r ON r.slug = u.role
            WHERE u.id = ?
              AND COALESCE(r.access_level, CASE WHEN u.role = 'admin' THEN 'admin' ELSE 'client' END) = 'client'
        ")->execute([$client_id]);
        header('Location: admin_clients.php');
        exit;
    } elseif ($action === 'add_request') {
        $service_id = $_POST['service_id'];
        $svc_stmt = $pdo->prepare("SELECT type, title FROM services WHERE id = ?");
        $svc_stmt->execute([$service_id]);
        $service = $svc_stmt->fetch();
        if ($service['type'] === 'iguala') {
            $period = $_POST['period'] ?? date('Y-m');
            $pdo->prepare("INSERT INTO requests (client_id, service_id, status, period) VALUES (?, ?, 'pendiente', ?)")
                ->execute([$client_id, $service_id, $period]);
        } else {
            $estimated_date = $_POST['estimated_date'] ?? null;
            $pdo->prepare("INSERT INTO requests (client_id, service_id, status, estimated_delivery_date) VALUES (?, ?, 'pendiente', ?)")
                ->execute([$client_id, $service_id, $estimated_date]);
        }
        $newReqId = $pdo->lastInsertId();
        logClientActivity($client_id, 'request', "Servicio asignado: {$service['title']}");
        if (getSetting('notify_request', '1') === '1') {
            sendRequestAssignedEmail($newReqId);
        }
        $success = "Servicio asignado correctamente.";
    } elseif ($action === 'update_status') {
        $request_id = $_POST['request_id'];
        $status = $_POST['status'];
        $pdo->prepare("UPDATE requests SET status = ? WHERE id = ? AND client_id = ?")
            ->execute([$status, $request_id, $client_id]);
        logClientActivity($client_id, 'status_change', "Estado de tramite actualizado a {$status}");
        if (getSetting('notify_status', '1') === '1') {
            sendRequestStatusEmail($request_id, $status);
        }
        $success = "Estado actualizado.";
    } elseif ($action === 'delete_request') {
        $request_id = $_POST['request_id'];
        $pdo->prepare("DELETE FROM requests WHERE id = ? AND client_id = ?")
            ->execute([$request_id, $client_id]);
        $success = "Solicitud eliminada.";
    } elseif ($action === 'add_note') {
        $note = trim($_POST['note'] ?? '');
        if ($note !== '') {
            logClientActivity($client_id, 'note', $note);
            $success = "Nota agregada al historial.";
        }
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

$services = $pdo->query("SELECT * FROM services ORDER BY type, title")->fetchAll();
$reqStmt = $pdo->prepare("SELECT r.*, s.title, s.type FROM requests r JOIN services s ON r.service_id = s.id WHERE r.client_id = ? ORDER BY r.created_at DESC");
$reqStmt->execute([$client_id]);
$requests = $reqStmt->fetchAll();
$igualas = array_filter($requests, fn($r) => $r['type'] === 'iguala');
$puntuales = array_filter($requests, fn($r) => $r['type'] === 'puntual');

// Invoices for this client
$invStmt = $pdo->prepare("SELECT * FROM invoices WHERE client_id = ? ORDER BY created_at DESC LIMIT 10");
$invStmt->execute([$client_id]);
$invoices = $invStmt->fetchAll();

$totalPending = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM invoices WHERE client_id = ? AND status = 'pendiente'");
$totalPending->execute([$client_id]);
$totalPending = (float)$totalPending->fetchColumn();

$totalPaid = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM invoices WHERE client_id = ? AND status = 'pagado'");
$totalPaid->execute([$client_id]);
$totalPaid = (float)$totalPaid->fetchColumn();

// Activity timeline
$timelineStmt = $pdo->prepare("SELECT a.*, u.name AS actor_name FROM client_activity a LEFT JOIN users u ON u.id = a.actor_id WHERE a.client_id = ? ORDER BY a.created_at DESC LIMIT 30");
$timelineStmt->execute([$client_id]);
$timeline = $timelineStmt->fetchAll();

// Tax obligations for this client
$obStmt = $pdo->prepare("SELECT * FROM tax_obligations WHERE client_id = ? ORDER BY due_date ASC LIMIT 24");
$obStmt->execute([$client_id]);
$obligations = $obStmt->fetchAll();

// Update overdue status
$pdo->prepare("UPDATE tax_obligations SET status = 'vencido' WHERE client_id = ? AND status = 'pendiente' AND due_date < CURDATE()")->execute([$client_id]);

function getWhatsAppLink($phone, $clientName, $requestTitle, $status) {
    if (!$phone) return "#";
    $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
    $greeting = getSetting('whatsapp_greeting', 'te escribimos de tu Asesoria Financiera');
    $statusText = match($status) {
        'pendiente' => 'pendiente por informacion', 'en_proceso' => 'en proceso de trabajo',
        'en_revision' => 'en revision final', 'presentado' => 'presentado ante la DGII',
        'completado' => 'completado y entregado', default => 'en tramite'
    };
    $message = "Hola $clientName, $greeting para recordarte que el tramite de *$requestTitle* se encuentra actualmente *$statusText*.";
    return "https://wa.me/" . $cleanPhone . "?text=" . urlencode($message);
}

function getActivityIcon($kind) {
    return match($kind) {
        'created'       => ['bg-emerald-50 text-emerald-700', '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>'],
        'updated'       => ['bg-blue-50 text-blue-700', '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>'],
        'status_change' => ['bg-amber-50 text-amber-700', '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>'],
        'request'       => ['bg-indigo-50 text-indigo-700', '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/></svg>'],
        'invoice'       => ['bg-red-50 text-red-700', '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2"/></svg>'],
        'note'          => ['bg-slate-100 text-slate-700', '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2"/></svg>'],
        default         => ['bg-stone-100 text-slate-600', '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/></svg>'],
    };
}

$frequencies = ['mensual' => 'Mensual', 'quincenal' => 'Quincenal', 'semanal' => 'Semanal', 'bimestral' => 'Bimestral', 'trimestral' => 'Trimestral', 'semestral' => 'Semestral', 'anual' => 'Anual'];

$page_title = $client['name'];
$page_subtitle = ($client['business_name'] ?: 'Cliente') . ($client['rnc'] ? ' · RNC ' . $client['rnc'] : '');
$page_actions = '
<a href="admin_clients.php" class="btn-soft text-sm">
    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
    Volver
</a>
<button onclick="document.getElementById(\'editClientModal\').classList.remove(\'hidden\')" class="btn-dark text-sm">Editar</button>';
include 'components/layout_start.php';
?>

<?php if ($success): ?>
<div class="mb-4 rounded-2xl bg-emerald-50 px-4 py-3 border border-emerald-100 text-sm font-medium text-emerald-800"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<!-- Profile card -->
<div class="surface-card p-5 mb-4">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
        <div class="flex items-center gap-4 lg:col-span-1">
            <div class="h-14 w-14 rounded-full bg-stone-100 border border-stone-200 flex items-center justify-center text-xl font-extrabold text-slate-700 shrink-0">
                <?= htmlspecialchars(substr(strtoupper($client['name']), 0, 1)) ?>
            </div>
            <div class="min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <h2 class="text-lg font-extrabold text-slate-900 truncate"><?= htmlspecialchars($client['name']) ?></h2>
                    <?= getClientStatusBadge($client['client_status'] ?? 'activo') ?>
                </div>
                <p class="text-xs text-slate-500 mt-0.5">
                    <?= htmlspecialchars(getBusinessTypeLabel($client['business_type'] ?? 'fisica')) ?>
                    <?php if ($client['rnc']): ?>&middot; RNC <?= htmlspecialchars($client['rnc']) ?><?php endif; ?>
                </p>
            </div>
        </div>

        <div class="lg:col-span-2 grid grid-cols-2 sm:grid-cols-4 gap-2">
            <div class="rounded-xl bg-stone-50 px-3 py-2.5">
                <p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">Iguala</p>
                <p class="text-sm font-extrabold text-slate-900">RD$ <?= number_format((float)$client['iguala_amount'], 0) ?></p>
                <p class="text-[10px] text-slate-400"><?= htmlspecialchars($frequencies[$client['iguala_frequency']] ?? $client['iguala_frequency'] ?? 'mensual') ?></p>
            </div>
            <div class="rounded-xl bg-stone-50 px-3 py-2.5">
                <p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">Tramites</p>
                <p class="text-sm font-extrabold text-slate-900"><?= count($requests) ?></p>
                <p class="text-[10px] text-slate-400"><?= count(array_filter($requests, fn($r) => in_array($r['status'], ['pendiente','en_proceso','en_revision']))) ?> activos</p>
            </div>
            <div class="rounded-xl <?= $totalPending > 0 ? 'bg-red-50' : 'bg-stone-50' ?> px-3 py-2.5">
                <p class="text-[10px] uppercase tracking-wider <?= $totalPending > 0 ? 'text-red-600' : 'text-slate-400' ?> font-bold">Por cobrar</p>
                <p class="text-sm font-extrabold <?= $totalPending > 0 ? 'text-red-700' : 'text-slate-900' ?>">RD$ <?= number_format($totalPending, 0) ?></p>
                <p class="text-[10px] text-slate-400">Pendiente</p>
            </div>
            <div class="rounded-xl bg-stone-50 px-3 py-2.5">
                <p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">Cobrado</p>
                <p class="text-sm font-extrabold text-emerald-700">RD$ <?= number_format($totalPaid, 0) ?></p>
                <p class="text-[10px] text-slate-400">Historico</p>
            </div>
        </div>
    </div>

    <!-- Contact strip -->
    <div class="mt-4 pt-4 border-t border-stone-100 grid grid-cols-1 sm:grid-cols-3 gap-3 text-xs">
        <div class="flex items-center gap-2 text-slate-600">
            <svg class="w-3.5 h-3.5 text-slate-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            <span class="truncate"><?= htmlspecialchars($client['email']) ?></span>
        </div>
        <div class="flex items-center gap-2 text-slate-600">
            <svg class="w-3.5 h-3.5 text-slate-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h2.586a1 1 0 01.707.293l2.414 2.414a1 1 0 01.293.707V8.586a1 1 0 01-.293.707l-1.586 1.586a11 11 0 005.414 5.414l1.586-1.586a1 1 0 01.707-.293h2.172a1 1 0 01.707.293l2.414 2.414a1 1 0 01.293.707V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
            <span><?= htmlspecialchars($client['phone'] ?: 'Sin telefono') ?></span>
        </div>
        <div class="flex items-center gap-2 text-slate-600">
            <svg class="w-3.5 h-3.5 text-slate-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a2 2 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0zM15 11a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            <span class="truncate"><?= htmlspecialchars($client['address'] ?: 'Sin direccion') ?></span>
        </div>
    </div>
</div>

<!-- Main 2-column layout -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

    <!-- Left: services and requests -->
    <div class="lg:col-span-2 space-y-4">
        <!-- Assign service -->
        <div class="surface-card p-5">
            <h3 class="text-sm font-bold text-slate-900 mb-3">Asignar nuevo servicio</h3>
            <form action="client_details.php?id=<?= $client_id ?>" method="POST" class="flex flex-col md:flex-row md:items-end gap-2">
                <input type="hidden" name="action" value="add_request">
                <div class="flex-1">
                    <label class="field-label">Servicio</label>
                    <select name="service_id" onchange="toggleServiceFields(this)" required class="field text-sm">
                        <option value="">Selecciona un servicio...</option>
                        <optgroup label="Igualas mensuales">
                            <?php foreach ($services as $s): if($s['type'] === 'iguala'): ?>
                            <option value="<?= $s['id'] ?>" data-type="iguala"><?= htmlspecialchars($s['title']) ?></option>
                            <?php endif; endforeach; ?>
                        </optgroup>
                        <optgroup label="Solicitudes puntuales">
                            <?php foreach ($services as $s): if($s['type'] === 'puntual'): ?>
                            <option value="<?= $s['id'] ?>" data-type="puntual"><?= htmlspecialchars($s['title']) ?></option>
                            <?php endif; endforeach; ?>
                        </optgroup>
                    </select>
                </div>
                <div id="period_field" class="hidden w-full md:w-40">
                    <label class="field-label">Periodo</label>
                    <input type="month" name="period" value="<?= date('Y-m') ?>" class="field text-sm">
                </div>
                <div id="date_field" class="hidden w-full md:w-40">
                    <label class="field-label">Fecha</label>
                    <input type="date" name="estimated_date" class="field text-sm">
                </div>
                <button type="submit" class="btn-dark text-sm">Asignar</button>
            </form>
        </div>

        <!-- Requests -->
        <div class="surface-card overflow-hidden">
            <div class="px-5 py-3 border-b border-stone-100 flex items-center justify-between">
                <h3 class="text-sm font-bold text-slate-900">Tramites del cliente</h3>
                <span class="text-xs text-slate-400"><?= count($requests) ?></span>
            </div>
            <?php if (empty($requests)): ?>
            <div class="py-10 text-center text-sm text-slate-400">Aun no hay tramites asignados.</div>
            <?php else: ?>
            <ul class="divide-y divide-stone-100">
                <?php foreach ($requests as $req): ?>
                <li class="px-5 py-3 hover:bg-stone-50/60">
                    <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 mb-0.5">
                                <span class="text-[10px] font-bold uppercase tracking-wider rounded-md px-1.5 py-0.5 <?= $req['type'] === 'iguala' ? 'bg-blue-50 text-blue-700' : 'bg-indigo-50 text-indigo-700' ?>"><?= $req['type'] === 'iguala' ? 'Iguala' : 'Puntual' ?></span>
                                <span class="text-[11px] text-slate-500">
                                    <?php if ($req['type'] === 'iguala'): ?>
                                    <?= htmlspecialchars($req['period']) ?>
                                    <?php else: ?>
                                    <?= $req['estimated_delivery_date'] ? 'Entrega ' . date('d/m/Y', strtotime($req['estimated_delivery_date'])) : 'Sin fecha' ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <p class="text-sm font-semibold text-slate-900 truncate"><?= htmlspecialchars($req['title']) ?></p>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <?= getStatusBadge($req['status']) ?>
                            <form action="client_details.php?id=<?= $client_id ?>" method="POST" class="inline">
                                <input type="hidden" name="action" value="update_status">
                                <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                <select name="status" onchange="this.form.submit()" class="field !py-1 !px-2 text-xs">
                                    <option value="pendiente" <?= $req['status'] === 'pendiente' ? 'selected' : '' ?>>Pendiente</option>
                                    <option value="en_proceso" <?= $req['status'] === 'en_proceso' ? 'selected' : '' ?>>En proceso</option>
                                    <option value="en_revision" <?= $req['status'] === 'en_revision' ? 'selected' : '' ?>>En revision</option>
                                    <?php if ($req['type'] === 'iguala'): ?>
                                    <option value="presentado" <?= $req['status'] === 'presentado' ? 'selected' : '' ?>>Presentado</option>
                                    <?php else: ?>
                                    <option value="completado" <?= $req['status'] === 'completado' ? 'selected' : '' ?>>Completado</option>
                                    <?php endif; ?>
                                </select>
                            </form>
                            <a href="request_view.php?id=<?= $req['id'] ?>" class="icon-btn !w-8 !h-8 hover:!bg-blue-100 hover:!text-blue-700" title="Abrir">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                            </a>
                            <?php if ($client['phone']): ?>
                            <a href="<?= getWhatsAppLink($client['phone'], $client['name'], $req['title'], $req['status']) ?>" target="_blank" class="icon-btn !w-8 !h-8 hover:!bg-emerald-100 hover:!text-emerald-700" title="WhatsApp">
                                <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51"/></svg>
                            </a>
                            <?php endif; ?>
                            <form action="client_details.php?id=<?= $client_id ?>" method="POST" onsubmit="return confirm('Eliminar?')" class="inline">
                                <input type="hidden" name="action" value="delete_request">
                                <input type="hidden" name="request_id" value="<?= $req['id'] ?>">
                                <button class="icon-btn !w-8 !h-8 hover:!bg-red-100 hover:!text-red-700" title="Eliminar">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </form>
                        </div>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>

        <!-- Tax obligations DGII -->
        <div class="surface-card overflow-hidden">
            <div class="px-5 py-3 border-b border-stone-100 flex items-center justify-between">
                <div>
                    <h3 class="text-sm font-bold text-slate-900">Obligaciones DGII</h3>
                    <p class="text-[11px] text-slate-500"><?= htmlspecialchars(getTaxRegimeLabel($client['tax_regime'] ?? 'ordinario')) ?> &middot; Cierre <?= htmlspecialchars($client['fiscal_year_close'] ?? '12-31') ?></p>
                </div>
                <div class="flex items-center gap-2">
                    <a href="admin_tax_calendar.php?client_id=<?= $client_id ?>" class="text-xs font-semibold text-blue-600">Ver todo</a>
                    <form action="client_details.php?id=<?= $client_id ?>" method="POST" class="inline">
                        <input type="hidden" name="action" value="regenerate_obligations">
                        <button type="submit" class="text-xs font-semibold text-slate-500 hover:text-slate-900" title="Regenerar plantilla DGII">Sync</button>
                    </form>
                </div>
            </div>
            <?php if (empty($obligations)): ?>
            <div class="py-6 text-center text-xs text-slate-400">
                Sin obligaciones generadas. <br>
                <form action="client_details.php?id=<?= $client_id ?>" method="POST" class="inline-block mt-2">
                    <input type="hidden" name="action" value="regenerate_obligations">
                    <button type="submit" class="btn-soft !text-xs">Generar plantilla DGII</button>
                </form>
            </div>
            <?php else: ?>
            <ul class="divide-y divide-stone-100">
                <?php foreach ($obligations as $ob): ?>
                <li class="px-5 py-2.5 flex items-center gap-3 hover:bg-stone-50/60 group">
                    <div class="w-9 h-9 rounded-xl bg-stone-50 flex items-center justify-center text-[10px] font-extrabold text-slate-600 shrink-0">
                        <?= htmlspecialchars(str_replace(['IT-','IR-','ANTICIPO'], ['IT', 'IR', 'AN'], $ob['obligation_type'])) ?>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold text-slate-900 truncate"><?= htmlspecialchars(getObligationLabel($ob['obligation_type'])) ?> &middot; <?= htmlspecialchars(formatPeriod($ob['period'])) ?></p>
                        <p class="text-[11px] text-slate-500">Vence <?= date('d/m/Y', strtotime($ob['due_date'])) ?></p>
                    </div>
                    <?= getObligationStatusBadge($ob['status'], $ob['due_date']) ?>
                    <form action="client_details.php?id=<?= $client_id ?>" method="POST" class="opacity-0 group-hover:opacity-100 transition-opacity">
                        <input type="hidden" name="action" value="toggle_obligation">
                        <input type="hidden" name="obligation_id" value="<?= $ob['id'] ?>">
                        <input type="hidden" name="new_status" value="<?= $ob['status'] === 'completado' ? 'pendiente' : 'completado' ?>">
                        <button type="submit" class="icon-btn !w-7 !h-7 hover:!bg-emerald-100 hover:!text-emerald-700" title="<?= $ob['status'] === 'completado' ? 'Reabrir' : 'Marcar completado' ?>">
                            <?php if ($ob['status'] === 'completado'): ?>
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                            <?php else: ?>
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            <?php endif; ?>
                        </button>
                    </form>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>

        <!-- Invoices -->
        <?php if (!empty($invoices)): ?>
        <div class="surface-card overflow-hidden">
            <div class="px-5 py-3 border-b border-stone-100 flex items-center justify-between">
                <h3 class="text-sm font-bold text-slate-900">Volantes recientes</h3>
                <a href="admin_finances.php" class="text-xs font-semibold text-blue-600">Ver todos</a>
            </div>
            <ul class="divide-y divide-stone-100">
                <?php foreach ($invoices as $inv): ?>
                <li class="px-5 py-2.5 flex items-center gap-3">
                    <div class="flex-1 min-w-0">
                        <p class="text-sm font-semibold text-slate-900 truncate"><?= htmlspecialchars($inv['concept']) ?></p>
                        <p class="text-[11px] text-slate-500">Vence <?= date('d/m/Y', strtotime($inv['due_date'])) ?></p>
                    </div>
                    <p class="text-sm font-bold text-slate-900">RD$ <?= number_format((float)$inv['amount'], 0) ?></p>
                    <span class="badge-dot <?= $inv['status'] === 'pagado' ? 'badge-green' : 'badge-red' ?>"><?= ucfirst($inv['status']) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right: notes + timeline -->
    <div class="space-y-4">
        <!-- Internal notes -->
        <div class="surface-card p-5">
            <h3 class="text-sm font-bold text-slate-900 mb-3">Notas internas</h3>
            <?php if ($client['notes']): ?>
            <div class="rounded-xl bg-amber-50 border border-amber-100 p-3 text-xs text-amber-900 mb-3 whitespace-pre-line"><?= htmlspecialchars($client['notes']) ?></div>
            <?php endif; ?>
            <form action="client_details.php?id=<?= $client_id ?>" method="POST" class="space-y-2">
                <input type="hidden" name="action" value="add_note">
                <textarea name="note" rows="3" required class="field text-sm" placeholder="Agregar una nota al historial..."></textarea>
                <button type="submit" class="btn-dark text-xs w-full">Guardar nota</button>
            </form>
        </div>

        <!-- Timeline -->
        <div class="surface-card overflow-hidden">
            <div class="px-5 py-3 border-b border-stone-100">
                <h3 class="text-sm font-bold text-slate-900">Historial de actividad</h3>
            </div>
            <div class="p-5 max-h-[500px] overflow-y-auto scroll-area">
                <?php if (empty($timeline)): ?>
                <p class="text-xs text-slate-400 text-center py-6">Sin actividad registrada aun.</p>
                <?php else: ?>
                <ol class="space-y-3">
                    <?php foreach ($timeline as $t):
                        [$bg, $svg] = getActivityIcon($t['kind']);
                    ?>
                    <li class="flex gap-3">
                        <div class="w-7 h-7 rounded-full <?= $bg ?> flex items-center justify-center shrink-0">
                            <?= $svg ?>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-xs text-slate-700 leading-snug"><?= htmlspecialchars($t['summary']) ?></p>
                            <p class="text-[10px] text-slate-400 mt-0.5">
                                <?= date('d/m/Y H:i', strtotime($t['created_at'])) ?>
                                <?php if ($t['actor_name']): ?>&middot; <?= htmlspecialchars($t['actor_name']) ?><?php endif; ?>
                            </p>
                        </div>
                    </li>
                    <?php endforeach; ?>
                </ol>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Danger zone -->
<div class="mt-4 surface-card p-5 border-red-100">
    <div class="flex items-center justify-between gap-4 flex-wrap">
        <div>
            <h4 class="text-sm font-bold text-red-700">Eliminar cliente</h4>
            <p class="text-xs text-slate-500 mt-0.5">Elimina al cliente y todos sus tramites permanentemente. No se puede deshacer.</p>
        </div>
        <form action="client_details.php?id=<?= $client_id ?>" method="POST" onsubmit="return confirm('Estas seguro? Se eliminaran todos los tramites.')">
            <input type="hidden" name="action" value="delete_client">
            <button type="submit" class="rounded-2xl bg-red-50 text-red-700 hover:bg-red-100 transition-colors px-4 py-2 text-xs font-semibold">Eliminar permanentemente</button>
        </form>
    </div>
</div>

<!-- Edit client modal -->
<div id="editClientModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 modal-backdrop" onclick="document.getElementById('editClientModal').classList.add('hidden')"></div>
    <div class="relative flex min-h-full items-center justify-center p-4">
        <div class="w-full max-w-2xl bg-white rounded-3xl shadow-2xl max-h-[90vh] overflow-hidden flex flex-col">
            <div class="px-6 py-4 border-b border-stone-100 flex items-center justify-between shrink-0">
                <h3 class="text-base font-bold text-slate-900">Editar cliente</h3>
                <button type="button" onclick="document.getElementById('editClientModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-700 text-2xl leading-none">&times;</button>
            </div>
            <form action="client_details.php?id=<?= $client_id ?>" method="POST" class="flex-1 overflow-y-auto scroll-area">
                <input type="hidden" name="action" value="edit_client">
                <div class="p-6 space-y-5">
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-3">Identidad</p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div class="md:col-span-2">
                                <label class="field-label">Nombre</label>
                                <input type="text" name="name" value="<?= htmlspecialchars($client['name']) ?>" required class="field">
                            </div>
                            <div>
                                <label class="field-label">Tipo</label>
                                <select name="business_type" class="field">
                                    <option value="fisica" <?= ($client['business_type'] ?? 'fisica') === 'fisica' ? 'selected' : '' ?>>Persona Fisica</option>
                                    <option value="juridica" <?= ($client['business_type'] ?? '') === 'juridica' ? 'selected' : '' ?>>Persona Juridica</option>
                                </select>
                            </div>
                            <div>
                                <label class="field-label">RNC / Cedula</label>
                                <input type="text" name="rnc" value="<?= htmlspecialchars($client['rnc'] ?? '') ?>" class="field">
                            </div>
                            <div class="md:col-span-2">
                                <label class="field-label">Razon social</label>
                                <input type="text" name="business_name" value="<?= htmlspecialchars($client['business_name'] ?? '') ?>" class="field">
                            </div>
                            <div>
                                <label class="field-label">Estado</label>
                                <select name="client_status" class="field">
                                    <?php foreach (['activo' => 'Activo', 'lead' => 'Lead', 'inactivo' => 'Inactivo'] as $k => $v): ?>
                                    <option value="<?= $k ?>" <?= ($client['client_status'] ?? 'activo') === $k ? 'selected' : '' ?>><?= $v ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="field-label">Inicio relacion</label>
                                <input type="date" name="started_at" value="<?= htmlspecialchars($client['started_at'] ?? '') ?>" class="field">
                            </div>
                        </div>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-3">Contacto</p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="field-label">Email</label>
                                <input type="email" name="email" value="<?= htmlspecialchars($client['email']) ?>" required class="field">
                            </div>
                            <div>
                                <label class="field-label">Telefono</label>
                                <input type="text" name="phone" value="<?= htmlspecialchars($client['phone'] ?? '') ?>" class="field">
                            </div>
                            <div class="md:col-span-2">
                                <label class="field-label">Direccion</label>
                                <input type="text" name="address" value="<?= htmlspecialchars($client['address'] ?? '') ?>" class="field">
                            </div>
                        </div>
                    </div>
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-3">Perfil fiscal DGII</p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="field-label">Regimen fiscal</label>
                                <select name="tax_regime" class="field">
                                    <?php foreach (getTaxRegimes() as $k => $v): ?>
                                    <option value="<?= $k ?>" <?= ($client['tax_regime'] ?? 'ordinario') === $k ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="field-label">Tipo de operacion</label>
                                <select name="operation_type" class="field">
                                    <?php foreach (getOperationTypes() as $k => $v): ?>
                                    <option value="<?= $k ?>" <?= ($client['operation_type'] ?? 'servicios') === $k ? 'selected' : '' ?>><?= htmlspecialchars($v) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="md:col-span-2">
                                <label class="field-label">Actividad economica</label>
                                <input type="text" name="economic_activity" value="<?= htmlspecialchars($client['economic_activity'] ?? '') ?>" class="field">
                            </div>
                            <div>
                                <label class="field-label">Cierre fiscal</label>
                                <select name="fiscal_year_close" class="field">
                                    <?php foreach (['12-31'=>'31 de Diciembre','03-31'=>'31 de Marzo','06-30'=>'30 de Junio','09-30'=>'30 de Septiembre'] as $k => $v): ?>
                                    <option value="<?= $k ?>" <?= ($client['fiscal_year_close'] ?? '12-31') === $k ? 'selected' : '' ?>><?= $v ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="field-label">Empleados</label>
                                <input type="number" min="0" name="employee_count" value="<?= (int)($client['employee_count'] ?? 0) ?>" class="field">
                            </div>
                        </div>
                        <p class="mt-2 text-[11px] text-slate-400 leading-snug">Al guardar, las obligaciones DGII se re-sincronizan segun el perfil.</p>
                    </div>

                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-3">Iguala</p>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="field-label">Monto (RD$)</label>
                                <input type="number" step="0.01" name="iguala_amount" value="<?= htmlspecialchars($client['iguala_amount'] ?? '0') ?>" class="field">
                            </div>
                            <div>
                                <label class="field-label">Frecuencia</label>
                                <select name="iguala_frequency" class="field">
                                    <?php foreach ($frequencies as $key => $label): ?>
                                    <option value="<?= $key ?>" <?= ($client['iguala_frequency'] ?? 'mensual') === $key ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div>
                        <label class="field-label">Notas internas</label>
                        <textarea name="notes" rows="3" class="field"><?= htmlspecialchars($client['notes'] ?? '') ?></textarea>
                    </div>
                </div>
                <div class="px-6 py-3 border-t border-stone-100 flex justify-end gap-2 bg-stone-50/60 shrink-0">
                    <button type="button" onclick="document.getElementById('editClientModal').classList.add('hidden')" class="btn-soft">Cancelar</button>
                    <button type="submit" class="btn-dark">Guardar cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function toggleServiceFields(select) {
    const type = select.options[select.selectedIndex].getAttribute('data-type');
    document.getElementById('period_field').classList.toggle('hidden', type !== 'iguala');
    document.getElementById('date_field').classList.toggle('hidden', type !== 'puntual');
}
</script>

<?php include 'components/layout_end.php'; ?>
