<?php
require_once 'config.php';
requireAuth('admin');

$success = $error = null;

// Add client
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_client') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $rnc = trim($_POST['rnc'] ?? '');
    $business_name = trim($_POST['business_name'] ?? '');
    $business_type = $_POST['business_type'] ?? 'fisica';
    $client_status = $_POST['client_status'] ?? 'activo';
    $address = trim($_POST['address'] ?? '');
    $started_at = $_POST['started_at'] ?? date('Y-m-d');
    $notes = trim($_POST['notes'] ?? '');
    $iguala_amount = (float)($_POST['iguala_amount'] ?? 0);
    $iguala_frequency = $_POST['iguala_frequency'] ?? 'mensual';
    $tax_regime = $_POST['tax_regime'] ?? 'ordinario';
    $economic_activity = trim($_POST['economic_activity'] ?? '');
    $fiscal_year_close = $_POST['fiscal_year_close'] ?? '12-31';
    $employee_count = (int)($_POST['employee_count'] ?? 0);
    $operation_type = $_POST['operation_type'] ?? 'servicios';

    if ($name === '' || $email === '' || $password === '') {
        $error = 'Nombre, correo y contrasena son obligatorios.';
    } else {
        try {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("
                INSERT INTO users
                (name, email, phone, role, password_hash, rnc, business_name, business_type, client_status, address, started_at, notes, iguala_amount, iguala_frequency, tax_regime, economic_activity, fiscal_year_close, employee_count, operation_type)
                VALUES (?, ?, ?, 'client', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$name, $email, $phone, $hash, $rnc, $business_name, $business_type, $client_status, $address, $started_at ?: null, $notes, $iguala_amount, $iguala_frequency, $tax_regime, $economic_activity, $fiscal_year_close, $employee_count, $operation_type]);
            $newId = $pdo->lastInsertId();
            logClientActivity($newId, 'created', 'Cliente creado');
            // Auto-generar obligaciones fiscales
            $generated = generateObligationsForClient($newId, 6);
            if ($generated > 0) {
                logClientActivity($newId, 'tax', "Se generaron {$generated} obligaciones DGII automaticas");
            }
            // Enviar welcome email
            $emailMsg = '';
            if (getSetting('notify_welcome', '1') === '1') {
                $res = sendWelcomeEmail($newId, $password);
                if (!empty($res['ok'])) {
                    $emailMsg = ' Email de bienvenida enviado.';
                    logClientActivity($newId, 'email', "Welcome email enviado a {$email}");
                }
            }
            $success = "Cliente agregado correctamente. Obligaciones DGII generadas: {$generated}.{$emailMsg}";
        } catch (PDOException $e) {
            $error = "No se pudo agregar el cliente. El correo puede estar duplicado.";
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_status') {
    $cid = (int)($_POST['client_id'] ?? 0);
    $st = $_POST['client_status'] ?? 'activo';
    if (in_array($st, ['activo','lead','inactivo'], true) && $cid > 0) {
        $pdo->prepare("UPDATE users SET client_status = ? WHERE id = ?")->execute([$st, $cid]);
        logClientActivity($cid, 'status_change', "Estado cambiado a {$st}");
        $success = "Estado actualizado.";
    }
}

// Filters
$q       = trim($_GET['q'] ?? '');
$filter  = $_GET['filter'] ?? 'all';

$whereSql = "
    LEFT JOIN roles r ON r.slug = u.role
    WHERE COALESCE(r.access_level, CASE WHEN u.role = 'admin' THEN 'admin' ELSE 'client' END) = 'client'
";
$params = [];
if ($q !== '') {
    $whereSql .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.rnc LIKE ? OR u.business_name LIKE ?)";
    $like = "%{$q}%";
    array_push($params, $like, $like, $like, $like, $like);
}
$statusFilter = '';
if (in_array($filter, ['activo','lead','inactivo'], true)) {
    $whereSql .= " AND u.client_status = ?";
    $params[] = $filter;
}

// Fetch clients with aggregated stats
$sql = "
    SELECT
        u.id, u.name, u.email, u.phone, u.rnc, u.business_name, u.business_type,
        u.client_status, u.address, u.started_at, u.created_at, u.iguala_amount, u.iguala_frequency,
        (SELECT COUNT(*) FROM requests WHERE client_id = u.id) AS total_requests,
        (SELECT COUNT(*) FROM requests WHERE client_id = u.id AND status IN ('pendiente','en_proceso','en_revision')) AS active_requests,
        (SELECT COALESCE(SUM(amount), 0) FROM invoices WHERE client_id = u.id AND status = 'pendiente') AS pending_amount,
        (SELECT COUNT(*) FROM invoices WHERE client_id = u.id AND status = 'pendiente') AS pending_invoices,
        (SELECT MAX(created_at) FROM requests WHERE client_id = u.id) AS last_request_at
    FROM users u
    {$whereSql}
    ORDER BY u.client_status ASC, u.created_at DESC
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$clients = $stmt->fetchAll();

// Apply meta-filters in PHP (with_pending, no_iguala)
if ($filter === 'with_pending') {
    $clients = array_filter($clients, fn($c) => (float)$c['pending_amount'] > 0);
} elseif ($filter === 'no_iguala') {
    $clients = array_filter($clients, fn($c) => (float)$c['iguala_amount'] <= 0);
} elseif ($filter === 'with_iguala') {
    $clients = array_filter($clients, fn($c) => (float)$c['iguala_amount'] > 0);
}

// Global stats
$globalStats = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN u.client_status = 'activo' THEN 1 ELSE 0 END) AS active,
        SUM(CASE WHEN u.client_status = 'lead' THEN 1 ELSE 0 END) AS leads,
        COALESCE(SUM(u.iguala_amount), 0) AS mrr
    FROM users u
    LEFT JOIN roles r ON r.slug = u.role
    WHERE COALESCE(r.access_level, CASE WHEN u.role = 'admin' THEN 'admin' ELSE 'client' END) = 'client'
")->fetch();

$pendingGlobal = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM invoices WHERE status='pendiente'")->fetchColumn();

$frequencies = ['mensual' => 'Mensual', 'quincenal' => 'Quincenal', 'semanal' => 'Semanal', 'bimestral' => 'Bimestral', 'trimestral' => 'Trimestral', 'semestral' => 'Semestral', 'anual' => 'Anual'];

$page_title = 'Clientes';
$page_subtitle = 'CRM completo con segmentacion fiscal y seguimiento de actividad.';
$page_actions = '<button type="button" onclick="document.getElementById(\'addClientModal\').classList.remove(\'hidden\')"
    class="btn-dark">
    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
    Nuevo cliente
</button>';
include 'components/layout_start.php';
?>

<?php if ($success): ?>
<div class="mb-4 rounded-2xl bg-emerald-50 px-4 py-3 border border-emerald-100 text-sm font-medium text-emerald-800"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="mb-4 rounded-2xl bg-red-50 px-4 py-3 border border-red-100 text-sm font-medium text-red-700"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Stats -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
    <div class="stat-card p-4">
        <p class="text-xs text-slate-500">Total clientes</p>
        <p class="mt-1 text-2xl lg:text-3xl font-extrabold text-slate-900"><?= (int)$globalStats['total'] ?></p>
        <p class="text-[11px] text-slate-400 mt-1"><?= (int)$globalStats['active'] ?> activos</p>
    </div>
    <div class="stat-card p-4">
        <p class="text-xs text-slate-500">Leads</p>
        <p class="mt-1 text-2xl lg:text-3xl font-extrabold text-slate-900"><?= (int)$globalStats['leads'] ?></p>
        <p class="text-[11px] text-slate-400 mt-1">Por convertir</p>
    </div>
    <div class="stat-card p-4">
        <p class="text-xs text-slate-500">Ingreso mensual recurrente</p>
        <p class="mt-1 text-2xl lg:text-3xl font-extrabold text-slate-900">RD$ <?= number_format((float)$globalStats['mrr'], 0) ?></p>
        <p class="text-[11px] text-slate-400 mt-1">Suma de igualas</p>
    </div>
    <div class="stat-card p-4">
        <p class="text-xs text-slate-500">Por cobrar</p>
        <p class="mt-1 text-2xl lg:text-3xl font-extrabold text-red-600">RD$ <?= number_format((float)$pendingGlobal, 0) ?></p>
        <p class="text-[11px] text-slate-400 mt-1">Volantes pendientes</p>
    </div>
</div>

<!-- Search + filter chips -->
<div class="surface-card p-3 mb-4 flex flex-col lg:flex-row gap-3">
    <form method="GET" class="flex-1 flex gap-2">
        <div class="relative flex-1">
            <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="search" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Buscar por nombre, RNC, razon social, correo, telefono..."
                   class="field pl-9 text-sm">
        </div>
        <?php if ($filter !== 'all'): ?>
            <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
        <?php endif; ?>
        <button type="submit" class="btn-dark text-sm">Buscar</button>
    </form>
    <div class="flex gap-2 overflow-x-auto scroll-area">
        <?php
        $chips = [
            'all' => 'Todos',
            'activo' => 'Activos',
            'lead' => 'Leads',
            'inactivo' => 'Inactivos',
            'with_iguala' => 'Con iguala',
            'no_iguala' => 'Sin iguala',
            'with_pending' => 'Con pendientes',
        ];
        foreach ($chips as $key => $label):
            $active = $filter === $key;
            $href = '?filter=' . urlencode($key) . ($q ? '&q=' . urlencode($q) : '');
        ?>
        <a href="<?= $href ?>" class="whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-semibold transition-colors <?= $active ? 'bg-slate-900 text-white' : 'bg-stone-100 text-slate-600 hover:bg-stone-200' ?>">
            <?= $label ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Clients grid -->
<?php if (empty($clients)): ?>
<div class="surface-card p-12 text-center">
    <div class="w-14 h-14 mx-auto rounded-full bg-stone-100 flex items-center justify-center mb-3">
        <svg class="w-6 h-6 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
    </div>
    <p class="text-sm text-slate-500">No hay clientes que coincidan con el filtro.</p>
    <p class="text-xs text-slate-400 mt-1">Cambia el filtro o agrega tu primer cliente.</p>
</div>
<?php else: ?>
<div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3">
    <?php foreach ($clients as $c):
        $cleanPhone = preg_replace('/[^0-9]/', '', $c['phone'] ?? '');
        $waMessage = urlencode("Hola {$c['name']}, " . getSetting('whatsapp_greeting', 'te escribimos de tu Asesoria Financiera') . '.');
    ?>
    <div class="surface-card p-5 flex flex-col">
        <div class="flex items-start gap-3 mb-3">
            <div class="h-12 w-12 rounded-full bg-stone-100 border border-stone-200 flex items-center justify-center text-base font-extrabold text-slate-700 shrink-0">
                <?= htmlspecialchars(substr(strtoupper($c['name']), 0, 1)) ?>
            </div>
            <div class="min-w-0 flex-1">
                <div class="flex items-center gap-2 flex-wrap">
                    <a href="client_details.php?id=<?= $c['id'] ?>" class="text-sm font-bold text-slate-900 hover:text-blue-600 truncate"><?= htmlspecialchars($c['name']) ?></a>
                    <?= getClientStatusBadge($c['client_status'] ?? 'activo') ?>
                </div>
                <?php if ($c['business_name']): ?>
                <p class="text-xs text-slate-500 truncate" title="<?= htmlspecialchars($c['business_name']) ?>"><?= htmlspecialchars($c['business_name']) ?></p>
                <?php endif; ?>
                <div class="mt-1 flex items-center gap-2 flex-wrap">
                    <span class="text-[10px] uppercase tracking-wider font-bold rounded-md px-1.5 py-0.5 bg-stone-100 text-slate-600">
                        <?= $c['business_type'] === 'juridica' ? 'PJ' : 'PN' ?>
                    </span>
                    <?php if ($c['rnc']): ?>
                    <span class="text-[11px] text-slate-500 font-mono">RNC: <?= htmlspecialchars($c['rnc']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Contact -->
        <div class="space-y-1 text-xs text-slate-500 mb-3">
            <div class="flex items-center gap-2 truncate">
                <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                <span class="truncate"><?= htmlspecialchars($c['email']) ?></span>
            </div>
            <?php if ($c['phone']): ?>
            <div class="flex items-center gap-2">
                <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 5a2 2 0 012-2h2.586a1 1 0 01.707.293l2.414 2.414a1 1 0 01.293.707V8.586a1 1 0 01-.293.707l-1.586 1.586a11 11 0 005.414 5.414l1.586-1.586a1 1 0 01.707-.293h2.172a1 1 0 01.707.293l2.414 2.414a1 1 0 01.293.707V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/></svg>
                <span><?= htmlspecialchars($c['phone']) ?></span>
            </div>
            <?php endif; ?>
        </div>

        <!-- KPIs -->
        <div class="grid grid-cols-3 gap-2 mb-3">
            <div class="rounded-xl bg-stone-50 px-2 py-2 text-center">
                <p class="text-[9px] uppercase tracking-wider text-slate-400 font-bold">Iguala</p>
                <p class="text-xs font-extrabold text-slate-900 truncate" title="RD$ <?= number_format((float)$c['iguala_amount'], 2) ?>">
                    <?= (float)$c['iguala_amount'] > 0 ? 'RD$ ' . number_format((float)$c['iguala_amount'], 0) : '-' ?>
                </p>
            </div>
            <div class="rounded-xl bg-stone-50 px-2 py-2 text-center">
                <p class="text-[9px] uppercase tracking-wider text-slate-400 font-bold">Tramites</p>
                <p class="text-xs font-extrabold text-slate-900"><?= (int)$c['active_requests'] ?>/<?= (int)$c['total_requests'] ?></p>
            </div>
            <div class="rounded-xl <?= (float)$c['pending_amount'] > 0 ? 'bg-red-50' : 'bg-stone-50' ?> px-2 py-2 text-center">
                <p class="text-[9px] uppercase tracking-wider <?= (float)$c['pending_amount'] > 0 ? 'text-red-500' : 'text-slate-400' ?> font-bold">Por cobrar</p>
                <p class="text-xs font-extrabold <?= (float)$c['pending_amount'] > 0 ? 'text-red-700' : 'text-slate-900' ?>">RD$ <?= number_format((float)$c['pending_amount'], 0) ?></p>
            </div>
        </div>

        <!-- Actions -->
        <div class="mt-auto pt-3 border-t border-stone-100 flex items-center justify-between gap-2">
            <p class="text-[11px] text-slate-400 truncate">
                <?php if ($c['last_request_at']): ?>
                Ult. tramite: <?= date('d/m/Y', strtotime($c['last_request_at'])) ?>
                <?php elseif ($c['started_at']): ?>
                Inicio: <?= date('d/m/Y', strtotime($c['started_at'])) ?>
                <?php else: ?>
                Sin actividad
                <?php endif; ?>
            </p>
            <div class="flex items-center gap-1">
                <?php if ($cleanPhone): ?>
                <a href="https://wa.me/<?= $cleanPhone ?>?text=<?= $waMessage ?>" target="_blank" class="icon-btn !w-8 !h-8 hover:!bg-emerald-100 hover:!text-emerald-700" title="WhatsApp">
                    <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51"/></svg>
                </a>
                <?php endif; ?>
                <a href="admin_invoice_review.php?client_id=<?= $c['id'] ?>" class="icon-btn !w-8 !h-8 hover:!bg-amber-100 hover:!text-amber-700" title="Facturas IA del cliente">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                </a>
                <a href="admin_tax_filings.php?client_id=<?= $c['id'] ?>" class="icon-btn !w-8 !h-8 hover:!bg-blue-100 hover:!text-blue-700" title="Formularios DGII">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                </a>
                <a href="client_details.php?id=<?= $c['id'] ?>" class="btn-dark !text-xs !py-1.5 !px-3">
                    Abrir
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Add client modal -->
<div id="addClientModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 modal-backdrop" onclick="document.getElementById('addClientModal').classList.add('hidden')"></div>
    <div class="relative flex min-h-full items-center justify-center p-4">
        <div class="w-full max-w-2xl bg-white rounded-3xl shadow-2xl max-h-[90vh] overflow-hidden flex flex-col">
            <div class="px-6 py-5 border-b border-stone-100 flex items-center justify-between shrink-0">
                <div>
                    <h3 class="text-base font-bold text-slate-900">Nuevo cliente</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Datos completos para gestion fiscal en RD</p>
                </div>
                <button type="button" onclick="document.getElementById('addClientModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-700 text-2xl leading-none">&times;</button>
            </div>
            <form action="admin_clients.php" method="POST" class="flex-1 overflow-y-auto scroll-area">
                <input type="hidden" name="action" value="add_client">

                <div class="p-6 space-y-5">
                    <!-- Identity -->
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-3">Identidad</p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div class="md:col-span-2">
                                <label class="field-label">Nombre completo *</label>
                                <input type="text" name="name" required class="field">
                            </div>
                            <div>
                                <label class="field-label">Tipo</label>
                                <select name="business_type" class="field">
                                    <option value="fisica">Persona Fisica</option>
                                    <option value="juridica">Persona Juridica</option>
                                </select>
                            </div>
                            <div>
                                <label class="field-label">RNC / Cedula</label>
                                <input type="text" name="rnc" placeholder="000-00000-0" class="field">
                            </div>
                            <div class="md:col-span-2">
                                <label class="field-label">Razon social / nombre comercial</label>
                                <input type="text" name="business_name" placeholder="(opcional, para PJ)" class="field">
                            </div>
                            <div>
                                <label class="field-label">Estado</label>
                                <select name="client_status" class="field">
                                    <option value="activo">Activo</option>
                                    <option value="lead">Lead</option>
                                    <option value="inactivo">Inactivo</option>
                                </select>
                            </div>
                            <div>
                                <label class="field-label">Inicio relacion</label>
                                <input type="date" name="started_at" value="<?= date('Y-m-d') ?>" class="field">
                            </div>
                        </div>
                    </div>

                    <!-- Contact -->
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-3">Contacto y acceso</p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="field-label">Correo *</label>
                                <input type="email" name="email" required class="field">
                            </div>
                            <div>
                                <label class="field-label">Telefono / WhatsApp</label>
                                <input type="text" name="phone" placeholder="+18090000000" class="field">
                            </div>
                            <div class="md:col-span-2">
                                <label class="field-label">Direccion</label>
                                <input type="text" name="address" class="field">
                            </div>
                            <div class="md:col-span-2">
                                <label class="field-label">Contrasena inicial *</label>
                                <input type="text" name="password" required class="field">
                            </div>
                        </div>
                    </div>

                    <!-- DGII Fiscal -->
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-3">Perfil fiscal DGII</p>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div>
                                <label class="field-label">Regimen fiscal</label>
                                <select name="tax_regime" class="field">
                                    <?php foreach (getTaxRegimes() as $k => $v): ?>
                                    <option value="<?= $k ?>"><?= htmlspecialchars($v) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="field-label">Tipo de operacion</label>
                                <select name="operation_type" class="field">
                                    <?php foreach (getOperationTypes() as $k => $v): ?>
                                    <option value="<?= $k ?>"><?= htmlspecialchars($v) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="md:col-span-2">
                                <label class="field-label">Actividad economica</label>
                                <input type="text" name="economic_activity" placeholder="Ej: Servicios de consultoria contable" class="field">
                            </div>
                            <div>
                                <label class="field-label">Cierre fiscal (MM-DD)</label>
                                <select name="fiscal_year_close" class="field">
                                    <option value="12-31">31 de Diciembre</option>
                                    <option value="03-31">31 de Marzo</option>
                                    <option value="06-30">30 de Junio</option>
                                    <option value="09-30">30 de Septiembre</option>
                                </select>
                            </div>
                            <div>
                                <label class="field-label">Empleados</label>
                                <input type="number" min="0" name="employee_count" value="0" class="field">
                            </div>
                        </div>
                        <p class="mt-2 text-[11px] text-slate-400 leading-snug">Al guardar se generaran automaticamente las obligaciones DGII (IT-1, 606/607/608, IR-17, TSS, etc.) para los proximos 6 meses segun el perfil.</p>
                    </div>

                    <!-- Iguala config -->
                    <div>
                        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mb-3">Iguala (opcional)</p>
                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="field-label">Monto (RD$)</label>
                                <input type="number" step="0.01" name="iguala_amount" value="0" class="field">
                            </div>
                            <div>
                                <label class="field-label">Frecuencia</label>
                                <select name="iguala_frequency" class="field">
                                    <?php foreach ($frequencies as $k => $v): ?>
                                    <option value="<?= $k ?>"><?= $v ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- Notes -->
                    <div>
                        <label class="field-label">Notas internas</label>
                        <textarea name="notes" rows="3" class="field" placeholder="Notas que solo verás tú y tu equipo..."></textarea>
                    </div>
                </div>

                <div class="px-6 py-4 border-t border-stone-100 flex justify-end gap-2 shrink-0 bg-stone-50/60">
                    <button type="button" onclick="document.getElementById('addClientModal').classList.add('hidden')" class="btn-soft">Cancelar</button>
                    <button type="submit" class="btn-dark">Guardar cliente</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if (isset($_GET['new'])): ?>
<script>
    // Auto-abrir modal cuando se llega con ?new=1 (desde dashboard)
    document.addEventListener('DOMContentLoaded', function() {
        var m = document.getElementById('addClientModal');
        if (m) m.classList.remove('hidden');
    });
</script>
<?php endif; ?>

<?php include 'components/layout_end.php'; ?>
