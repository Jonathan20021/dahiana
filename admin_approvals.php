<?php
require_once 'config.php';
requireAuth('admin');

$success = $error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $uid = (int)($_POST['user_id'] ?? 0);

    if ($uid > 0 && $action === 'approve') {
        $res = signupApproveUser($uid, $_SESSION['user_id'] ?? null);
        if ($res['ok']) {
            $success = "Cliente aprobado. Se crearon {$res['requests_created']} solicitudes de servicios.";
        } else {
            $error = $res['error'];
        }
    } elseif ($uid > 0 && $action === 'reject') {
        $reason = trim($_POST['reason'] ?? '');
        signupRejectUser($uid, $reason, $_SESSION['user_id'] ?? null);
        $success = 'Solicitud rechazada.';
    } elseif ($uid > 0 && $action === 'edit') {
        // Permitir al admin editar datos antes de aprobar
        $fields = [
            'name','email','phone','rnc','business_name','business_type','address',
            'tax_regime','economic_activity','operation_type','employee_count','notes'
        ];
        $sql = "UPDATE users SET " . implode(',', array_map(fn($f) => "$f = ?", $fields)) . " WHERE id = ? AND approval_status='pending_approval'";
        $values = [];
        foreach ($fields as $f) $values[] = trim((string)($_POST[$f] ?? ''));
        $values[] = $uid;
        try {
            $pdo->prepare($sql)->execute($values);
            $success = 'Datos del cliente actualizados antes de aprobar.';
        } catch (PDOException $e) {
            $error = 'No se pudieron guardar los cambios.';
        }
    }
}

$filter = $_GET['filter'] ?? 'pending';
$valid  = ['pending','rejected','approved','all'];
if (!in_array($filter, $valid, true)) $filter = 'pending';

$where = "u.role <> 'admin'";
$params = [];
if ($filter === 'pending')  { $where .= " AND u.approval_status = 'pending_approval'"; }
elseif ($filter === 'rejected') { $where .= " AND u.approval_status = 'rejected'"; }
elseif ($filter === 'approved') { $where .= " AND u.approval_status = 'approved' AND u.registered_via = 'public_signup'"; }
// 'all' = todos los que vinieron por public_signup
if ($filter === 'all') $where .= " AND u.registered_via = 'public_signup'";

$stmt = $pdo->prepare("
    SELECT u.*, approver.name AS approver_name,
           (SELECT COUNT(*) FROM signup_requested_services WHERE user_id = u.id) AS services_requested
    FROM users u
    LEFT JOIN users approver ON approver.id = u.approved_by
    WHERE {$where}
    ORDER BY
      CASE u.approval_status WHEN 'pending_approval' THEN 0 WHEN 'rejected' THEN 1 ELSE 2 END,
      u.created_at DESC
");
$stmt->execute($params);
$users = $stmt->fetchAll();

$pendingCount = signupPendingCount();

$page_title = 'Aprobaciones de clientes';
$page_subtitle = 'Revisa y aprueba las solicitudes de registro publico.';
include 'components/layout_start.php';
?>

<?php if ($success): ?>
<div class="mb-4 rounded-2xl bg-emerald-50 px-4 py-3 border border-emerald-100 text-sm font-medium text-emerald-800"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="mb-4 rounded-2xl bg-red-50 px-4 py-3 border border-red-100 text-sm font-medium text-red-700"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Tabs -->
<div class="surface-card p-2 mb-4 flex gap-2 overflow-x-auto scroll-area">
    <?php
    $tabs = [
        'pending'  => ['label' => 'Pendientes', 'count' => $pendingCount, 'cls' => 'badge-amber'],
        'approved' => ['label' => 'Aprobadas',  'count' => null, 'cls' => 'badge-green'],
        'rejected' => ['label' => 'Rechazadas', 'count' => null, 'cls' => 'badge-slate'],
        'all'      => ['label' => 'Todas',      'count' => null, 'cls' => 'badge-slate'],
    ];
    foreach ($tabs as $tk => $tab):
        $active = $filter === $tk;
    ?>
    <a href="?filter=<?= $tk ?>" class="whitespace-nowrap rounded-2xl px-4 py-2 text-sm font-bold transition-colors <?= $active ? 'bg-slate-900 text-white' : 'bg-stone-100 text-slate-600 hover:bg-stone-200' ?>">
        <?= $tab['label'] ?>
        <?php if ($tab['count']): ?>
        <span class="ml-1.5 inline-flex items-center px-1.5 rounded-full bg-white/20 text-[10px]"><?= $tab['count'] ?></span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
    <a href="admin_signup_settings.php" class="ml-auto text-sm text-blue-600 hover:text-blue-800 self-center px-3">Personalizar form &rarr;</a>
</div>

<?php if (empty($users)): ?>
<div class="ap-empty">
    <div class="ap-empty-icon">
        <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
    </div>
    <p class="ap-empty-title"><?= $filter === 'pending' ? 'Sin solicitudes pendientes' : 'Nada por aqui' ?></p>
    <p class="ap-empty-sub">
        <?= $filter === 'pending'
            ? 'Cuando un visitante se registre desde la pagina publica de signup, llegara aqui para que lo apruebes.'
            : 'Cambia el filtro arriba para ver otras solicitudes.' ?>
    </p>
</div>
<?php else: ?>

<div class="ap-rows">
    <?php foreach ($users as $u):
        $reqServices = $pdo->prepare("SELECT s.title, s.type FROM signup_requested_services r JOIN services s ON s.id = r.service_id WHERE r.user_id = ? ORDER BY s.type, s.title");
        $reqServices->execute([$u['id']]);
        $servicesList = $reqServices->fetchAll();
        $statusBadge = match($u['approval_status']) {
            'pending_approval' => '<span class="ap-pill ap-pill-amber">Pendiente</span>',
            'approved'         => '<span class="ap-pill ap-pill-emerald">Aprobado</span>',
            'rejected'         => '<span class="ap-pill ap-pill-red">Rechazado</span>',
            default            => '<span class="ap-pill ap-pill-slate">' . htmlspecialchars($u['approval_status']) . '</span>',
        };
        $iniLetter = strtoupper(substr($u['name'], 0, 1));
    ?>
    <article class="ap-card" id="user-<?= $u['id'] ?>">
        <div class="ap-head">
            <div class="ap-identity">
                <div class="ap-avatar"><?= htmlspecialchars($iniLetter) ?></div>
                <div class="ap-id-main">
                    <div class="ap-name-row">
                        <h3 class="ap-name"><?= htmlspecialchars($u['name']) ?></h3>
                        <?= $statusBadge ?>
                    </div>
                    <p class="ap-contact">
                        <a href="mailto:<?= htmlspecialchars($u['email']) ?>" class="ap-link"><?= htmlspecialchars($u['email']) ?></a>
                        <?php if ($u['phone']): ?>
                        <span class="ap-sep">·</span>
                        <a href="tel:<?= htmlspecialchars($u['phone']) ?>" class="ap-link"><?= htmlspecialchars($u['phone']) ?></a>
                        <?php endif; ?>
                    </p>
                    <p class="ap-meta">Solicitud recibida <?= date('d/m/Y H:i', strtotime($u['created_at'])) ?></p>
                </div>
            </div>

            <?php if ($u['approval_status'] === 'pending_approval'): ?>
            <div class="ap-quick-actions">
                <button type="button" onclick="toggleEdit(<?= $u['id'] ?>)" class="ap-btn ap-btn-ghost" title="Corregir datos antes de aprobar">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    Editar
                </button>
                <button type="button" onclick="openReject(<?= $u['id'] ?>)" class="ap-btn ap-btn-danger">
                    Rechazar
                </button>
                <form method="POST" class="inline-flex" onsubmit="return confirm('Aprobar este cliente? Se crearan sus solicitudes de servicios automaticamente.')">
                    <input type="hidden" name="action" value="approve">
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <button type="submit" class="ap-btn ap-btn-success">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                        Aprobar
                    </button>
                </form>
            </div>
            <?php elseif ($u['approval_status'] === 'approved'): ?>
            <a href="client_details.php?id=<?= $u['id'] ?>" class="ap-btn ap-btn-ghost">Ver perfil &rarr;</a>
            <?php endif; ?>
        </div>

        <div class="ap-grid">
            <div class="ap-field">
                <p class="ap-field-label">Negocio</p>
                <p class="ap-field-val"><?= htmlspecialchars($u['business_name'] ?: '—') ?></p>
            </div>
            <div class="ap-field">
                <p class="ap-field-label">RNC / Cedula</p>
                <p class="ap-field-val font-mono"><?= htmlspecialchars($u['rnc'] ?: '—') ?></p>
            </div>
            <div class="ap-field">
                <p class="ap-field-label">Regimen fiscal</p>
                <p class="ap-field-val"><?= htmlspecialchars(getTaxRegimeLabel($u['tax_regime'])) ?></p>
            </div>
            <div class="ap-field">
                <p class="ap-field-label">Servicios elegidos</p>
                <p class="ap-field-val"><?= count($servicesList) ?> <span class="text-slate-400 font-normal">items</span></p>
            </div>
        </div>

        <?php if (!empty($servicesList)): ?>
        <div class="ap-section">
            <p class="ap-section-title">Servicios solicitados</p>
            <div class="ap-services">
                <?php foreach ($servicesList as $sv):
                    $tone = $sv['type'] === 'iguala' ? 'ap-srv-blue' : 'ap-srv-emerald';
                ?>
                <span class="ap-service <?= $tone ?>">
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    <?= htmlspecialchars($sv['title']) ?>
                    <span class="ap-service-tag"><?= $sv['type'] === 'iguala' ? 'Mensual' : 'Puntual' ?></span>
                </span>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($u['notes'])): ?>
        <div class="ap-section ap-notes">
            <p class="ap-section-title">Notas del cliente</p>
            <p class="ap-notes-body"><?= nl2br(htmlspecialchars($u['notes'])) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($u['approval_status'] === 'rejected' && !empty($u['rejected_reason'])): ?>
        <div class="ap-section ap-rejected">
            <p class="ap-section-title text-red-700">Motivo del rechazo</p>
            <p class="ap-notes-body text-red-800"><?= htmlspecialchars($u['rejected_reason']) ?></p>
        </div>
        <?php endif; ?>

        <?php if ($u['approval_status'] === 'approved' && $u['approver_name']): ?>
        <p class="ap-approved-by">
            <svg class="w-3.5 h-3.5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            Aprobado por <strong><?= htmlspecialchars($u['approver_name']) ?></strong>
            <?php if ($u['approved_at']): ?>el <?= date('d/m/Y H:i', strtotime($u['approved_at'])) ?><?php endif; ?>
        </p>
        <?php endif; ?>

        <?php if ($u['approval_status'] === 'pending_approval'): ?>
        <!-- Inline edit form (collapsible) -->
        <div id="edit-<?= $u['id'] ?>" class="ap-edit hidden">
            <p class="ap-section-title mb-3">Corregir antes de aprobar</p>
            <form method="POST" class="ap-edit-grid">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <div class="ap-edit-f">
                    <label class="ap-edit-label">Nombre</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($u['name']) ?>" class="ap-edit-input" required>
                </div>
                <div class="ap-edit-f">
                    <label class="ap-edit-label">Email</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($u['email']) ?>" class="ap-edit-input" required>
                </div>
                <div class="ap-edit-f">
                    <label class="ap-edit-label">Telefono</label>
                    <input type="text" name="phone" value="<?= htmlspecialchars($u['phone']) ?>" class="ap-edit-input">
                </div>
                <div class="ap-edit-f">
                    <label class="ap-edit-label">RNC / Cedula</label>
                    <input type="text" name="rnc" value="<?= htmlspecialchars($u['rnc']) ?>" class="ap-edit-input font-mono">
                </div>
                <div class="ap-edit-f">
                    <label class="ap-edit-label">Nombre comercial</label>
                    <input type="text" name="business_name" value="<?= htmlspecialchars($u['business_name']) ?>" class="ap-edit-input">
                </div>
                <div class="ap-edit-f">
                    <label class="ap-edit-label">Tipo persona</label>
                    <select name="business_type" class="ap-edit-input">
                        <option value="fisica" <?= $u['business_type']==='fisica'?'selected':'' ?>>Persona fisica</option>
                        <option value="juridica" <?= $u['business_type']==='juridica'?'selected':'' ?>>Persona juridica</option>
                    </select>
                </div>
                <div class="ap-edit-f">
                    <label class="ap-edit-label">Regimen fiscal</label>
                    <select name="tax_regime" class="ap-edit-input">
                        <?php foreach (getTaxRegimes() as $val => $lbl): ?>
                        <option value="<?= $val ?>" <?= $u['tax_regime']===$val?'selected':'' ?>><?= htmlspecialchars($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ap-edit-f">
                    <label class="ap-edit-label">Tipo de operacion</label>
                    <select name="operation_type" class="ap-edit-input">
                        <?php foreach (getOperationTypes() as $val => $lbl): ?>
                        <option value="<?= $val ?>" <?= $u['operation_type']===$val?'selected':'' ?>><?= htmlspecialchars($lbl) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="ap-edit-f">
                    <label class="ap-edit-label">Empleados</label>
                    <input type="number" name="employee_count" value="<?= (int)$u['employee_count'] ?>" class="ap-edit-input">
                </div>
                <div class="ap-edit-f ap-edit-f-wide">
                    <label class="ap-edit-label">Actividad economica</label>
                    <input type="text" name="economic_activity" value="<?= htmlspecialchars($u['economic_activity']) ?>" class="ap-edit-input">
                </div>
                <div class="ap-edit-f">
                    <label class="ap-edit-label">Direccion</label>
                    <input type="text" name="address" value="<?= htmlspecialchars($u['address']) ?>" class="ap-edit-input">
                </div>
                <div class="ap-edit-f ap-edit-f-full">
                    <label class="ap-edit-label">Notas</label>
                    <textarea name="notes" rows="2" class="ap-edit-input"><?= htmlspecialchars($u['notes']) ?></textarea>
                </div>
                <div class="ap-edit-f-full flex justify-end gap-2">
                    <button type="button" onclick="toggleEdit(<?= $u['id'] ?>)" class="ap-btn ap-btn-ghost">Cancelar</button>
                    <button type="submit" class="ap-btn ap-btn-dark">Guardar correcciones</button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </article>
    <?php endforeach; ?>
</div>

<style>
    /* === Approvals === */
    .ap-empty { padding: 60px 20px; text-align: center; background: #fff; border: 1px solid #EEF0F2; border-radius: 22px; }
    .ap-empty-icon { width: 56px; height: 56px; border-radius: 50%; background: #F4F4F5; color: #94A3B8; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 12px; }
    .ap-empty-title { font-size: 15px; font-weight: 700; color: #0F172A; }
    .ap-empty-sub { font-size: 13px; color: #94A3B8; margin-top: 6px; max-width: 460px; margin-left: auto; margin-right: auto; line-height: 1.55; }

    .ap-rows { display: flex; flex-direction: column; gap: 14px; }
    .ap-card { background: #fff; border: 1px solid #EEF0F2; border-radius: 22px; padding: 20px 22px; }
    .ap-card:has([data-status="pending"]) { border-color: #FCD34D; }

    .ap-head { display: flex; flex-direction: column; gap: 14px; margin-bottom: 14px; }
    .ap-identity { display: flex; gap: 14px; align-items: flex-start; min-width: 0; flex: 1; }
    .ap-avatar { width: 48px; height: 48px; border-radius: 14px; background: linear-gradient(135deg, #DBEAFE, #E0E7FF); color: #1E40AF; display: inline-flex; align-items: center; justify-content: center; font-size: 18px; font-weight: 800; flex-shrink: 0; }
    .ap-id-main { min-width: 0; flex: 1; }
    .ap-name-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 4px; }
    .ap-name { font-size: 17px; font-weight: 800; color: #0F172A; }
    .ap-contact { font-size: 12px; color: #64748B; }
    .ap-link { color: #475569; transition: color .12s ease; }
    .ap-link:hover { color: #1D4ED8; }
    .ap-sep { color: #CBD5E1; margin: 0 4px; }
    .ap-meta { font-size: 11px; color: #94A3B8; margin-top: 3px; }
    .ap-quick-actions { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }

    .ap-btn { display: inline-flex; align-items: center; gap: 6px; padding: 9px 14px; border-radius: 11px; font-size: 12px; font-weight: 700; transition: all .15s ease; white-space: nowrap; }
    .ap-btn-ghost { background: #F4F4F5; color: #475569; }
    .ap-btn-ghost:hover { background: #E5E7EB; color: #0F172A; }
    .ap-btn-dark { background: #0F172A; color: #fff; }
    .ap-btn-dark:hover { background: #1E293B; }
    .ap-btn-danger { background: #FEF2F2; color: #DC2626; }
    .ap-btn-danger:hover { background: #FECACA; color: #B91C1C; }
    .ap-btn-success { background: #10B981; color: #fff; padding: 9px 18px; }
    .ap-btn-success:hover { background: #059669; box-shadow: 0 4px 12px rgba(16,185,129,0.25); }

    .ap-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; padding: 14px 16px; background: #F8FAFC; border-radius: 14px; margin-bottom: 12px; }
    .ap-field-label { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #94A3B8; }
    .ap-field-val { font-size: 13px; font-weight: 700; color: #0F172A; margin-top: 2px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    @media (min-width: 768px) {
        .ap-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        .ap-head { flex-direction: row; align-items: flex-start; justify-content: space-between; }
    }

    .ap-section { margin-top: 12px; }
    .ap-section-title { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; color: #64748B; margin-bottom: 8px; }
    .ap-services { display: flex; gap: 6px; flex-wrap: wrap; }
    .ap-service { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 999px; font-size: 12px; font-weight: 700; line-height: 1; }
    .ap-service-tag { font-size: 9px; padding: 2px 7px; border-radius: 999px; font-weight: 700; opacity: 0.7; }
    .ap-srv-blue { background: #DBEAFE; color: #1E40AF; }
    .ap-srv-blue .ap-service-tag { background: rgba(30,64,175,0.15); }
    .ap-srv-emerald { background: #DCFCE7; color: #047857; }
    .ap-srv-emerald .ap-service-tag { background: rgba(4,120,87,0.15); }

    .ap-notes { background: #F8FAFC; border-radius: 14px; padding: 12px 14px; }
    .ap-notes-body { font-size: 13px; color: #334155; line-height: 1.5; }
    .ap-rejected { background: #FEF2F2; border: 1px solid #FECACA; border-radius: 14px; padding: 12px 14px; }
    .ap-approved-by { display: inline-flex; align-items: center; gap: 6px; margin-top: 10px; font-size: 11px; color: #64748B; }

    .ap-pill { display: inline-flex; align-items: center; gap: 5px; font-size: 10px; font-weight: 700; padding: 3px 9px; border-radius: 999px; letter-spacing: 0.02em; }
    .ap-pill::before { content: ''; width: 5px; height: 5px; border-radius: 999px; background: currentColor; }
    .ap-pill-emerald { color: #15803D; background: #DCFCE7; }
    .ap-pill-amber   { color: #B45309; background: #FEF3C7; }
    .ap-pill-red     { color: #DC2626; background: #FEE2E2; }
    .ap-pill-slate   { color: #475569; background: #F1F5F9; }

    .ap-edit { margin-top: 14px; padding: 16px; background: linear-gradient(180deg, #FAFAFA, #fff); border: 1px solid #EEF0F2; border-radius: 14px; }
    .ap-edit-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    .ap-edit-f { display: flex; flex-direction: column; min-width: 0; }
    .ap-edit-f-wide { grid-column: span 2; }
    .ap-edit-f-full { grid-column: 1 / -1; }
    .ap-edit-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #64748B; margin-bottom: 4px; }
    .ap-edit-input { width: 100%; border: 1.5px solid #E5E7EB; border-radius: 10px; padding: 9px 11px; font-size: 13px; background: #fff; color: #0F172A; transition: all .12s ease; }
    .ap-edit-input:focus { outline: none; border-color: #0F172A; box-shadow: 0 0 0 3px rgba(15,23,42,0.05); }
    @media (min-width: 768px) {
        .ap-edit-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        .ap-edit-f-wide { grid-column: span 2; }
    }
</style>

<!-- Reject modal -->
<div id="rejectModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 modal-backdrop" onclick="closeReject()"></div>
    <div class="relative flex min-h-full items-center justify-center p-4">
        <div class="w-full max-w-md rounded-3xl bg-white shadow-2xl">
            <div class="px-6 py-5 border-b border-stone-100">
                <h3 class="text-base font-bold text-slate-900">Rechazar solicitud</h3>
                <p class="text-xs text-slate-500 mt-0.5">El cliente recibira una notificacion con el motivo.</p>
            </div>
            <form method="POST" class="p-6 space-y-4">
                <input type="hidden" name="action" value="reject">
                <input type="hidden" name="user_id" id="rejectUserId">
                <div>
                    <label class="field-label">Motivo (opcional)</label>
                    <textarea name="reason" rows="3" class="field text-sm" placeholder="Ej: Datos incompletos, no es nuestro publico objetivo..."></textarea>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="closeReject()" class="btn-soft text-sm">Cancelar</button>
                    <button type="submit" class="btn-dark text-sm bg-red-600 hover:bg-red-700">Rechazar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php endif; ?>

<script>
function toggleEdit(id) {
    const el = document.getElementById('edit-' + id);
    if (el) el.classList.toggle('hidden');
}
function openReject(id) {
    document.getElementById('rejectUserId').value = id;
    document.getElementById('rejectModal').classList.remove('hidden');
}
function closeReject() {
    document.getElementById('rejectModal').classList.add('hidden');
}
</script>

<?php include 'components/layout_end.php'; ?>
