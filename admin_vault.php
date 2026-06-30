<?php
require_once 'config.php';
requireAuth('admin');
requirePagePermission(); // exige vault.view (admin siempre pasa)

// -------------------------------------------------------------------------
// Esquema (idempotente)
// -------------------------------------------------------------------------
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS client_credentials (
            id INT AUTO_INCREMENT PRIMARY KEY,
            client_id INT NOT NULL,
            rnc_cedula VARCHAR(40) DEFAULT NULL,
            platform VARCHAR(120) NOT NULL,
            access_url VARCHAR(500) DEFAULT NULL,
            username VARCHAR(255) DEFAULT NULL,
            password_enc TEXT DEFAULT NULL,
            email_assoc VARCHAR(255) DEFAULT NULL,
            phone_assoc VARCHAR(60) DEFAULT NULL,
            sec_q1 VARCHAR(255) DEFAULT NULL,
            sec_a1_enc TEXT DEFAULT NULL,
            sec_q2 VARCHAR(255) DEFAULT NULL,
            sec_a2_enc TEXT DEFAULT NULL,
            sec_q3 VARCHAR(255) DEFAULT NULL,
            sec_a3_enc TEXT DEFAULT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'activo',
            notes TEXT DEFAULT NULL,
            usage_notes TEXT DEFAULT NULL,
            created_by INT DEFAULT NULL,
            updated_by INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX(client_id), INDEX(platform), INDEX(status)
        )
    ");
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS credential_audit (
            id INT AUTO_INCREMENT PRIMARY KEY,
            credential_id INT NOT NULL,
            client_id INT NOT NULL,
            user_id INT DEFAULT NULL,
            action VARCHAR(20) NOT NULL,
            detail VARCHAR(255) DEFAULT NULL,
            ip VARCHAR(45) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX(credential_id), INDEX(client_id), INDEX(action)
        )
    ");
} catch (PDOException $e) { /* swallow: esquema ya existe o cambiado manualmente */ }

// -------------------------------------------------------------------------
// Catalogos
// -------------------------------------------------------------------------
$statusLabels = [
    'activo'            => 'Activo',
    'pendiente_validar' => 'Pendiente de validar',
    'bloqueado'         => 'Bloqueado',
    'cambiado'          => 'Cambiado',
    'no_funciona'       => 'No funciona',
    'suspendido'        => 'Suspendido',
];
$statusBadge = [
    'activo'            => 'badge-green',
    'pendiente_validar' => 'badge-amber',
    'bloqueado'         => 'badge-red',
    'cambiado'          => 'badge-blue',
    'no_funciona'       => 'badge-red',
    'suspendido'        => 'badge-slate',
];
$platformPresets = [
    'DGII', 'TSS', 'SIRLA', 'Ventanilla Virtual PYME', 'MICM',
    'Registro Mercantil', 'Banco', 'Correo electronico', 'Otra plataforma',
];

$canWrite  = currentUserHasPermission('vault.write');
$canReveal = currentUserHasPermission('vault.reveal') || $canWrite;

if (empty($_SESSION['vault_csrf'])) {
    $_SESSION['vault_csrf'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['vault_csrf'];

function vaultAudit($credId, $clientId, $action, $detail = null) {
    global $pdo;
    try {
        $pdo->prepare("
            INSERT INTO credential_audit (credential_id, client_id, user_id, action, detail, ip)
            VALUES (?,?,?,?,?,?)
        ")->execute([
            (int)$credId, (int)$clientId, $_SESSION['user_id'] ?? null,
            $action, $detail !== null ? mb_substr($detail, 0, 250) : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (PDOException $e) {}
}

$scope = clientScopeWhere('cc.client_id');

// -------------------------------------------------------------------------
// Endpoint AJAX: devolver un registro descifrado (revelar / editar)
// -------------------------------------------------------------------------
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $out = ['ok' => false, 'error' => 'Accion no valida'];

    if ($_GET['ajax'] === 'record') {
        if (($_GET['t'] ?? '') !== $csrf) {
            echo json_encode(['ok' => false, 'error' => 'Token invalido. Recarga la pagina.']);
            exit;
        }
        $id = (int)($_GET['id'] ?? 0);
        $mode = ($_GET['mode'] ?? 'edit') === 'reveal' ? 'reveal' : 'edit';

        $stmt = $pdo->prepare("
            SELECT cc.*, u.name AS client_name,
                   cb.name AS created_by_name, ub.name AS updated_by_name
            FROM client_credentials cc
            JOIN users u ON u.id = cc.client_id
            LEFT JOIN users cb ON cb.id = cc.created_by
            LEFT JOIN users ub ON ub.id = cc.updated_by
            WHERE cc.id = ? LIMIT 1
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        if (!$row) {
            echo json_encode(['ok' => false, 'error' => 'Registro no encontrado.']);
            exit;
        }
        if (!clientAccessibleByUser((int)$row['client_id'])) {
            echo json_encode(['ok' => false, 'error' => 'No tienes acceso a este cliente.']);
            exit;
        }

        $hasPass = $row['password_enc'] !== null && $row['password_enc'] !== '';
        $hasA1   = $row['sec_a1_enc'] !== null && $row['sec_a1_enc'] !== '';
        $hasA2   = $row['sec_a2_enc'] !== null && $row['sec_a2_enc'] !== '';
        $hasA3   = $row['sec_a3_enc'] !== null && $row['sec_a3_enc'] !== '';

        $record = [
            'id'              => (int)$row['id'],
            'client_id'       => (int)$row['client_id'],
            'client_name'     => $row['client_name'],
            'rnc_cedula'      => $row['rnc_cedula'],
            'platform'        => $row['platform'],
            'access_url'      => $row['access_url'],
            'username'        => $row['username'],
            'password'        => $canReveal ? vaultDecrypt($row['password_enc']) : null,
            'email_assoc'     => $row['email_assoc'],
            'phone_assoc'     => $row['phone_assoc'],
            'sec_q1'          => $row['sec_q1'], 'sec_a1' => $canReveal ? vaultDecrypt($row['sec_a1_enc']) : null,
            'sec_q2'          => $row['sec_q2'], 'sec_a2' => $canReveal ? vaultDecrypt($row['sec_a2_enc']) : null,
            'sec_q3'          => $row['sec_q3'], 'sec_a3' => $canReveal ? vaultDecrypt($row['sec_a3_enc']) : null,
            'status'          => $row['status'],
            'notes'           => $row['notes'],
            'usage_notes'     => $row['usage_notes'],
            'created_at'      => $row['created_at'],
            'updated_at'      => $row['updated_at'],
            'created_by_name' => $row['created_by_name'],
            'updated_by_name' => $row['updated_by_name'],
            'can_reveal'      => $canReveal,
            'has_password'    => $hasPass,
            'has_a1'          => $hasA1,
            'has_a2'          => $hasA2,
            'has_a3'          => $hasA3,
        ];

        $audit = [];
        if ($mode === 'reveal') {
            vaultAudit($id, (int)$row['client_id'], 'view', $row['platform']);
            $a = $pdo->prepare("
                SELECT ca.action, ca.detail, ca.created_at, us.name AS user_name
                FROM credential_audit ca
                LEFT JOIN users us ON us.id = ca.user_id
                WHERE ca.credential_id = ?
                ORDER BY ca.created_at DESC LIMIT 12
            ");
            $a->execute([$id]);
            $audit = $a->fetchAll();
        }

        echo json_encode(['ok' => true, 'record' => $record, 'audit' => $audit], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode($out);
    exit;
}

// -------------------------------------------------------------------------
// Mutaciones (crear / actualizar / borrar)
// -------------------------------------------------------------------------
$success = $error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if (($_POST['csrf'] ?? '') !== $csrf) {
        $error = 'Sesion expirada. Recarga la pagina e intenta de nuevo.';
    } elseif (!$canWrite) {
        $error = 'Tu rol no tiene permiso para modificar accesos.';
    } elseif ($action === 'save') {
        $id        = (int)($_POST['id'] ?? 0);
        $clientId  = (int)($_POST['client_id'] ?? 0);
        $platform  = trim($_POST['platform'] ?? '');
        $status    = $_POST['status'] ?? 'activo';
        if (!isset($statusLabels[$status])) $status = 'activo';

        $rnc       = trim($_POST['rnc_cedula'] ?? '');
        $url       = trim($_POST['access_url'] ?? '');
        $username  = trim($_POST['username'] ?? '');
        $password  = (string)($_POST['password'] ?? '');
        $emailA    = trim($_POST['email_assoc'] ?? '');
        $phoneA    = trim($_POST['phone_assoc'] ?? '');
        $q1 = trim($_POST['sec_q1'] ?? ''); $a1 = (string)($_POST['sec_a1'] ?? '');
        $q2 = trim($_POST['sec_q2'] ?? ''); $a2 = (string)($_POST['sec_a2'] ?? '');
        $q3 = trim($_POST['sec_q3'] ?? ''); $a3 = (string)($_POST['sec_a3'] ?? '');
        $notes     = trim($_POST['notes'] ?? '');
        $usage     = trim($_POST['usage_notes'] ?? '');

        if ($clientId <= 0 || !clientAccessibleByUser($clientId)) {
            $error = 'Selecciona un cliente valido al que tengas acceso.';
        } elseif ($platform === '') {
            $error = 'Indica la plataforma o servicio.';
        } else {
            try {
                $uid = (int)$_SESSION['user_id'];
                if ($id > 0) {
                    // Verificar propiedad / acceso del registro existente
                    $chk = $pdo->prepare("SELECT client_id FROM client_credentials WHERE id=?");
                    $chk->execute([$id]);
                    $ownerClient = $chk->fetchColumn();
                    if ($ownerClient === false || !clientAccessibleByUser((int)$ownerClient)) {
                        $error = 'No puedes editar este acceso.';
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE client_credentials SET
                                client_id=?, rnc_cedula=?, platform=?, access_url=?, username=?, password_enc=?,
                                email_assoc=?, phone_assoc=?,
                                sec_q1=?, sec_a1_enc=?, sec_q2=?, sec_a2_enc=?, sec_q3=?, sec_a3_enc=?,
                                status=?, notes=?, usage_notes=?, updated_by=?
                            WHERE id=?
                        ");
                        $stmt->execute([
                            $clientId, $rnc ?: null, $platform, $url ?: null, $username ?: null, vaultEncrypt($password),
                            $emailA ?: null, $phoneA ?: null,
                            $q1 ?: null, vaultEncrypt($a1), $q2 ?: null, vaultEncrypt($a2), $q3 ?: null, vaultEncrypt($a3),
                            $status, $notes ?: null, $usage ?: null, $uid, $id,
                        ]);
                        vaultAudit($id, $clientId, 'update', $platform);
                        logClientActivity($clientId, 'vault', 'Acceso actualizado: ' . $platform);
                        $success = 'Acceso actualizado correctamente.';
                    }
                } else {
                    $stmt = $pdo->prepare("
                        INSERT INTO client_credentials
                            (client_id, rnc_cedula, platform, access_url, username, password_enc,
                             email_assoc, phone_assoc,
                             sec_q1, sec_a1_enc, sec_q2, sec_a2_enc, sec_q3, sec_a3_enc,
                             status, notes, usage_notes, created_by, updated_by)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
                    ");
                    $stmt->execute([
                        $clientId, $rnc ?: null, $platform, $url ?: null, $username ?: null, vaultEncrypt($password),
                        $emailA ?: null, $phoneA ?: null,
                        $q1 ?: null, vaultEncrypt($a1), $q2 ?: null, vaultEncrypt($a2), $q3 ?: null, vaultEncrypt($a3),
                        $status, $notes ?: null, $usage ?: null, $uid, $uid,
                    ]);
                    $newId = (int)$pdo->lastInsertId();
                    vaultAudit($newId, $clientId, 'create', $platform);
                    logClientActivity($clientId, 'vault', 'Acceso registrado: ' . $platform);
                    $success = 'Acceso guardado correctamente.';
                }
            } catch (Throwable $e) {
                $error = 'No se pudo guardar el acceso. ' . $e->getMessage();
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $chk = $pdo->prepare("SELECT client_id, platform FROM client_credentials WHERE id=?");
        $chk->execute([$id]);
        $row = $chk->fetch();
        if ($row && clientAccessibleByUser((int)$row['client_id'])) {
            $pdo->prepare("DELETE FROM client_credentials WHERE id=?")->execute([$id]);
            vaultAudit($id, (int)$row['client_id'], 'delete', $row['platform']);
            logClientActivity((int)$row['client_id'], 'vault', 'Acceso eliminado: ' . $row['platform']);
            $success = 'Acceso eliminado.';
        } else {
            $error = 'No puedes eliminar este acceso.';
        }
    }
}

// -------------------------------------------------------------------------
// Filtros + listado
// -------------------------------------------------------------------------
$fClient   = (int)($_GET['client'] ?? 0);
$fPlatform = trim($_GET['platform'] ?? '');
$fStatus   = trim($_GET['status'] ?? '');
$fQ        = trim($_GET['q'] ?? '');

$where  = [$scope];
$params = [];
if ($fClient > 0 && clientAccessibleByUser($fClient)) { $where[] = 'cc.client_id = ?'; $params[] = $fClient; }
if ($fPlatform !== '')                                { $where[] = 'cc.platform = ?';  $params[] = $fPlatform; }
if ($fStatus !== '' && isset($statusLabels[$fStatus])){ $where[] = 'cc.status = ?';    $params[] = $fStatus; }
if ($fQ !== '') {
    $where[] = '(cc.platform LIKE ? OR cc.username LIKE ? OR cc.email_assoc LIKE ? OR cc.rnc_cedula LIKE ? OR u.name LIKE ?)';
    $like = '%' . $fQ . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT cc.id, cc.client_id, cc.rnc_cedula, cc.platform, cc.access_url, cc.username,
           cc.email_assoc, cc.phone_assoc, cc.status, cc.created_at, cc.updated_at,
           (cc.password_enc IS NOT NULL AND cc.password_enc <> '') AS has_pass,
           u.name AS client_name, ub.name AS updated_by_name
    FROM client_credentials cc
    JOIN users u ON u.id = cc.client_id
    LEFT JOIN users ub ON ub.id = cc.updated_by
    WHERE $whereSql
    ORDER BY u.name ASC, cc.platform ASC
    LIMIT 600
");
$stmt->execute($params);
$creds = $stmt->fetchAll();

// Clientes accesibles (para selects + prefill)
$clients = $pdo->query("
    SELECT u.id, u.name, u.rnc, u.email, u.phone
    FROM users u
    LEFT JOIN roles r ON r.slug = u.role
    WHERE COALESCE(r.access_level, CASE WHEN u.role='admin' THEN 'admin' ELSE 'client' END)='client'
      AND " . clientScopeWhere('u.id') . "
    ORDER BY u.name
")->fetchAll();

$clientsMap = [];
foreach ($clients as $c) {
    $clientsMap[(int)$c['id']] = [
        'rnc'   => $c['rnc'] ?? '',
        'email' => $c['email'] ?? '',
        'phone' => $c['phone'] ?? '',
    ];
}

// Plataformas en uso (filtro)
$platformsInUse = $pdo->query("SELECT DISTINCT platform FROM client_credentials cc WHERE $scope ORDER BY platform")->fetchAll(PDO::FETCH_COLUMN);

// KPIs (respetando scope)
$agg = $pdo->query("SELECT status, COUNT(*) c FROM client_credentials cc WHERE $scope GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$totalCreds = (int)array_sum($agg);
$kpiActivos = (int)($agg['activo'] ?? 0);
$kpiAtencion = (int)(($agg['bloqueado'] ?? 0) + ($agg['no_funciona'] ?? 0) + ($agg['pendiente_validar'] ?? 0));
$kpiClientes = (int)$pdo->query("SELECT COUNT(DISTINCT cc.client_id) FROM client_credentials cc WHERE $scope")->fetchColumn();

$page_title = 'Boveda de accesos';
$page_subtitle = 'Contrasenas y credenciales de clientes, cifradas y con control de acceso.';
if ($canWrite) {
    $page_actions = '<button type="button" onclick="openVaultCreate()" class="btn-dark text-sm">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
        Nuevo acceso
    </button>';
}
include 'components/layout_start.php';
?>

<?php if ($success): ?>
<div class="mb-4 rounded-2xl bg-emerald-50 px-4 py-3 border border-emerald-100 text-sm font-medium text-emerald-800"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="mb-4 rounded-2xl bg-red-50 px-4 py-3 border border-red-100 text-sm font-medium text-red-700"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- KPIs -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
    <div class="surface-card p-4">
        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Accesos guardados</p>
        <p class="text-2xl font-extrabold text-slate-900 mt-1"><?= $totalCreds ?></p>
    </div>
    <div class="surface-card p-4">
        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Clientes con accesos</p>
        <p class="text-2xl font-extrabold text-slate-900 mt-1"><?= $kpiClientes ?></p>
    </div>
    <div class="surface-card p-4">
        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Activos</p>
        <p class="text-2xl font-extrabold text-emerald-600 mt-1"><?= $kpiActivos ?></p>
    </div>
    <div class="surface-card p-4">
        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Requieren atencion</p>
        <p class="text-2xl font-extrabold text-amber-600 mt-1"><?= $kpiAtencion ?></p>
    </div>
</div>

<!-- Filtros -->
<form method="GET" class="surface-card p-3 mb-3 flex flex-wrap gap-2 items-center">
    <div class="relative flex-1 min-w-[200px]">
        <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        <input type="text" name="q" value="<?= htmlspecialchars($fQ) ?>" placeholder="Buscar cliente, plataforma, usuario, correo..." class="field text-sm pl-9">
    </div>
    <select name="client" onchange="this.form.submit()" class="field text-sm">
        <option value="0">Todos los clientes</option>
        <?php foreach ($clients as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $fClient === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="platform" onchange="this.form.submit()" class="field text-sm">
        <option value="">Todas las plataformas</option>
        <?php foreach ($platformsInUse as $p): ?>
        <option value="<?= htmlspecialchars($p) ?>" <?= $fPlatform === $p ? 'selected' : '' ?>><?= htmlspecialchars($p) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="status" onchange="this.form.submit()" class="field text-sm">
        <option value="">Todos los estados</option>
        <?php foreach ($statusLabels as $k => $lbl): ?>
        <option value="<?= $k ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn-soft text-sm">Filtrar</button>
    <?php if ($fClient || $fPlatform !== '' || $fStatus !== '' || $fQ !== ''): ?>
    <a href="admin_vault.php" class="btn-ghost text-sm">Limpiar</a>
    <?php endif; ?>
</form>

<!-- Listado -->
<?php if (empty($creds)): ?>
<div class="surface-card p-10 text-center">
    <svg class="w-12 h-12 mx-auto text-slate-200 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
    <p class="text-sm text-slate-500">No hay accesos registrados<?= ($fClient || $fPlatform || $fStatus || $fQ) ? ' con esos filtros' : '' ?>.</p>
    <?php if ($canWrite): ?>
    <button type="button" onclick="openVaultCreate()" class="btn-dark text-sm mt-4">Registrar primer acceso</button>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="surface-card overflow-hidden">
    <div class="overflow-x-auto scroll-area">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-[10px] font-bold uppercase tracking-wider text-slate-400 border-b border-slate-100">
                    <th class="px-4 py-3">Cliente</th>
                    <th class="px-4 py-3">Plataforma</th>
                    <th class="px-4 py-3">Usuario</th>
                    <th class="px-4 py-3">Contrasena</th>
                    <th class="px-4 py-3">Estado</th>
                    <th class="px-4 py-3 whitespace-nowrap">Actualizado</th>
                    <th class="px-4 py-3 text-right">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-50">
                <?php foreach ($creds as $cr):
                    $st = $cr['status'];
                    $badge = $statusBadge[$st] ?? 'badge-slate';
                    $stLbl = $statusLabels[$st] ?? ucfirst($st);
                ?>
                <tr class="table-row align-top">
                    <td class="px-4 py-3">
                        <a href="client_details.php?id=<?= (int)$cr['client_id'] ?>" class="font-semibold text-slate-900 hover:text-blue-600"><?= htmlspecialchars($cr['client_name']) ?></a>
                        <?php if ($cr['rnc_cedula']): ?>
                        <p class="text-[11px] text-slate-400 font-mono"><?= htmlspecialchars($cr['rnc_cedula']) ?></p>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-medium text-slate-800"><?= htmlspecialchars($cr['platform']) ?></div>
                        <?php if ($cr['access_url']): ?>
                        <a href="<?= htmlspecialchars($cr['access_url']) ?>" target="_blank" rel="noopener" class="text-[11px] text-blue-600 hover:underline inline-flex items-center gap-1">
                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                            Abrir
                        </a>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <?php if ($cr['username']): ?>
                        <div class="flex items-center gap-1.5">
                            <span class="font-mono text-slate-700 break-all"><?= htmlspecialchars($cr['username']) ?></span>
                            <button type="button" class="v-mini" title="Copiar usuario" onclick="copyText(this.dataset.v, this)" data-v="<?= htmlspecialchars($cr['username']) ?>">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                            </button>
                        </div>
                        <?php else: ?>
                        <span class="text-slate-300">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <?php if (!$cr['has_pass']): ?>
                        <span class="text-slate-300">—</span>
                        <?php elseif (!$canReveal): ?>
                        <span class="font-mono text-slate-400 tracking-widest">••••••••</span>
                        <?php else: ?>
                        <div class="vault-pass flex items-center gap-1.5" data-id="<?= (int)$cr['id'] ?>">
                            <span class="vault-pass-dots font-mono text-slate-400 tracking-widest">••••••••</span>
                            <span class="vault-pass-val font-mono text-slate-800 break-all hidden"></span>
                            <button type="button" class="v-mini" title="Mostrar/ocultar" onclick="vaultRevealPass(<?= (int)$cr['id'] ?>, this)">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            </button>
                            <button type="button" class="v-mini" title="Copiar contrasena" onclick="vaultCopyPass(<?= (int)$cr['id'] ?>, this)">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                            </button>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3"><span class="badge-dot <?= $badge ?>"><?= htmlspecialchars($stLbl) ?></span></td>
                    <td class="px-4 py-3 text-[11px] text-slate-500 whitespace-nowrap">
                        <?= date('d M Y', strtotime($cr['updated_at'])) ?>
                        <?php if ($cr['updated_by_name']): ?>
                        <p class="text-slate-400">por <?= htmlspecialchars($cr['updated_by_name']) ?></p>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center justify-end gap-1">
                            <button type="button" class="v-action" title="Ver detalle" onclick="openVaultDetail(<?= (int)$cr['id'] ?>)">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            </button>
                            <?php if ($canWrite): ?>
                            <button type="button" class="v-action" title="Editar" onclick="openVaultEdit(<?= (int)$cr['id'] ?>)">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            </button>
                            <form method="POST" class="inline" onsubmit="return confirm('Eliminar este acceso? Esta accion no se puede deshacer.')">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int)$cr['id'] ?>">
                                <button type="submit" class="v-action v-action-danger" title="Eliminar">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
                                </button>
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
<p class="text-[11px] text-slate-400 mt-3 flex items-center gap-1.5">
    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
    Contrasenas y respuestas de seguridad se guardan cifradas (AES-256). Cada visualizacion queda registrada en la auditoria.
</p>
<?php endif; ?>

<?php if ($canWrite): ?>
<!-- ===================== Modal: crear / editar ===================== -->
<div id="vaultFormModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 modal-backdrop" onclick="hideModal('vaultFormModal')"></div>
    <div class="relative flex min-h-full items-start justify-center p-3 sm:p-4">
        <div class="w-full max-w-2xl rounded-3xl bg-white shadow-2xl my-4">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between sticky top-0 bg-white rounded-t-3xl z-10">
                <div>
                    <h3 id="vaultFormTitle" class="text-base font-bold text-slate-900">Nuevo acceso</h3>
                    <p class="text-xs text-slate-500 mt-0.5">Los datos sensibles se guardan cifrados.</p>
                </div>
                <button type="button" onclick="hideModal('vaultFormModal')" class="text-slate-400 hover:text-slate-700 text-2xl leading-none">&times;</button>
            </div>
            <form id="vaultForm" method="POST" action="admin_vault.php" class="p-6 space-y-4 max-h-[78vh] overflow-y-auto scroll-area">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="field-label">Cliente *</label>
                        <select name="client_id" required class="field" onchange="vaultClientChange(this)">
                            <option value="">Selecciona un cliente...</option>
                            <?php foreach ($clients as $c): ?>
                            <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="field-label">RNC o cedula</label>
                        <input type="text" name="rnc_cedula" class="field" placeholder="Se autocompleta del cliente">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="field-label">Plataforma o servicio *</label>
                        <input type="text" name="platform" required list="platformList" class="field" placeholder="DGII, TSS, Banco...">
                        <datalist id="platformList">
                            <?php foreach ($platformPresets as $p): ?>
                            <option value="<?= htmlspecialchars($p) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                    <div>
                        <label class="field-label">Estado del acceso</label>
                        <select name="status" class="field">
                            <?php foreach ($statusLabels as $k => $lbl): ?>
                            <option value="<?= $k ?>"><?= htmlspecialchars($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="field-label">Link de acceso</label>
                    <input type="url" name="access_url" class="field" placeholder="https://...">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="field-label">Usuario</label>
                        <input type="text" name="username" class="field" autocomplete="off">
                    </div>
                    <div>
                        <label class="field-label">Contrasena</label>
                        <div class="flex items-center gap-1.5">
                            <input type="password" id="vaultPassInput" name="password" class="field font-mono" autocomplete="new-password">
                            <button type="button" class="v-mini-btn" title="Mostrar/ocultar" onclick="togglePass()">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                            </button>
                            <button type="button" class="v-mini-btn" title="Generar contrasena segura" onclick="genPass()">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 5v.01M12 12v.01M9 19v.01M19 9l-7 7-4-4-5 5"/><path stroke-linecap="round" stroke-linejoin="round" d="M14 4h6v6"/></svg>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="field-label">Correo asociado</label>
                        <input type="email" name="email_assoc" class="field" autocomplete="off">
                    </div>
                    <div>
                        <label class="field-label">Telefono asociado</label>
                        <input type="text" name="phone_assoc" class="field" autocomplete="off">
                    </div>
                </div>

                <div class="rounded-2xl bg-slate-50 border border-slate-100 p-3 space-y-3">
                    <p class="text-[11px] font-bold uppercase tracking-wider text-slate-500">Preguntas de seguridad</p>
                    <?php for ($i = 1; $i <= 3; $i++): ?>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <input type="text" name="sec_q<?= $i ?>" class="field text-sm" placeholder="Pregunta <?= $i ?>">
                        <input type="text" name="sec_a<?= $i ?>" class="field text-sm" placeholder="Respuesta <?= $i ?>" autocomplete="off">
                    </div>
                    <?php endfor; ?>
                </div>

                <div>
                    <label class="field-label">Observaciones</label>
                    <textarea name="notes" rows="2" class="field" placeholder="Notas internas, vencimientos, advertencias..."></textarea>
                </div>
                <div>
                    <label class="field-label">Forma de uso</label>
                    <textarea name="usage_notes" rows="2" class="field" placeholder="Pasos o indicaciones para usar este acceso..."></textarea>
                </div>

                <div class="flex justify-end gap-2 pt-1">
                    <button type="button" onclick="hideModal('vaultFormModal')" class="btn-soft text-sm">Cancelar</button>
                    <button type="submit" class="btn-dark text-sm">Guardar acceso</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ===================== Modal: detalle ===================== -->
<div id="vaultDetailModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 modal-backdrop" onclick="hideModal('vaultDetailModal')"></div>
    <div class="relative flex min-h-full items-start justify-center p-3 sm:p-4">
        <div class="w-full max-w-xl rounded-3xl bg-white shadow-2xl my-4">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between sticky top-0 bg-white rounded-t-3xl z-10">
                <div>
                    <h3 class="text-base font-bold text-slate-900">Detalle del acceso</h3>
                    <p id="vaultDetailSub" class="text-xs text-slate-500 mt-0.5"></p>
                </div>
                <button type="button" onclick="hideModal('vaultDetailModal')" class="text-slate-400 hover:text-slate-700 text-2xl leading-none">&times;</button>
            </div>
            <div id="vaultDetailBody" class="p-6 max-h-[78vh] overflow-y-auto scroll-area"></div>
        </div>
    </div>
</div>

<div id="vaultToast" class="vault-toast">Copiado</div>

<style>
    .v-mini { display:inline-flex; align-items:center; justify-content:center; width:26px; height:26px; border-radius:8px; background:#F4F4F5; color:#64748B; border:0; cursor:pointer; transition:all .12s ease; flex-shrink:0; }
    .v-mini:hover { background:#E5E7EB; color:#0F172A; }
    .v-mini-btn { display:inline-flex; align-items:center; justify-content:center; width:38px; height:38px; border-radius:12px; background:#F4F4F5; color:#475569; border:0; cursor:pointer; transition:all .12s ease; flex-shrink:0; }
    .v-mini-btn:hover { background:#E5E7EB; color:#0F172A; }
    .v-action { display:inline-flex; align-items:center; justify-content:center; width:32px; height:32px; border-radius:10px; background:#F4F4F5; color:#64748B; border:0; cursor:pointer; transition:all .12s ease; }
    .v-action:hover { background:#E5E7EB; color:#0F172A; }
    .v-action-danger:hover { background:#FEE2E2; color:#DC2626; }
    .d-row { padding:9px 0; border-bottom:1px solid #F4F4F5; }
    .d-row:last-child { border-bottom:0; }
    .d-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#94A3B8; margin-bottom:3px; }
    .d-val { font-size:13.5px; color:#0F172A; word-break:break-word; display:flex; align-items:center; gap:8px; }
    .d-empty { color:#CBD5E1; }
    .d-mini { font-size:11px; font-weight:600; color:#2563EB; background:#EFF6FF; border:0; border-radius:8px; padding:2px 8px; cursor:pointer; }
    .d-mini:hover { background:#DBEAFE; }
    .d-audit-item { display:flex; gap:8px; align-items:flex-start; padding:6px 0; font-size:12px; color:#64748B; border-bottom:1px solid #F8FAFC; }
    .vault-toast { position:fixed; bottom:24px; left:50%; transform:translateX(-50%) translateY(20px); background:#0F172A; color:#fff; font-size:13px; font-weight:600; padding:10px 18px; border-radius:999px; box-shadow:0 10px 30px rgba(15,23,42,.3); opacity:0; pointer-events:none; transition:all .2s ease; z-index:120; }
    .vault-toast.show { opacity:1; transform:translateX(-50%) translateY(0); }
</style>

<script>
const VAULT_CSRF = <?= json_encode($csrf) ?>;
const VAULT_CLIENTS = <?= json_encode($clientsMap, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
const VAULT_STATUS = <?= json_encode($statusLabels, JSON_UNESCAPED_UNICODE) ?>;
let vaultFormMode = 'create';
const secretCache = {};

function showModal(id){ document.getElementById(id).classList.remove('hidden'); document.body.style.overflow='hidden'; }
function hideModal(id){ document.getElementById(id).classList.add('hidden'); document.body.style.overflow=''; }

function jsEsc(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function jsAttr(s){ return String(s==null?'':s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

function vaultToast(msg){
    const t = document.getElementById('vaultToast');
    t.textContent = msg || 'Copiado';
    t.classList.add('show');
    clearTimeout(t._h);
    t._h = setTimeout(()=> t.classList.remove('show'), 1400);
}

function copyText(text, btn){
    if(text===undefined||text===null||text==='') { vaultToast('Nada que copiar'); return; }
    const done = ()=> vaultToast('Copiado');
    if(navigator.clipboard && navigator.clipboard.writeText){
        navigator.clipboard.writeText(text).then(done).catch(()=>fallbackCopy(text,done));
    } else { fallbackCopy(text,done); }
}
function fallbackCopy(text, done){
    const ta=document.createElement('textarea'); ta.value=text; ta.style.position='fixed'; ta.style.opacity='0';
    document.body.appendChild(ta); ta.focus(); ta.select();
    try{ document.execCommand('copy'); done(); }catch(e){ vaultToast('No se pudo copiar'); }
    document.body.removeChild(ta);
}

async function fetchRecord(id, mode){
    try{
        const res = await fetch('admin_vault.php?ajax=record&id='+encodeURIComponent(id)+'&mode='+mode+'&t='+encodeURIComponent(VAULT_CSRF), {headers:{'X-Requested-With':'fetch'}});
        return await res.json();
    }catch(e){ return {ok:false, error:'Error de red'}; }
}

// ---- Revelar / copiar en la tabla ----
async function vaultRevealPass(id, btn){
    const wrap = btn.closest('.vault-pass');
    const dots = wrap.querySelector('.vault-pass-dots');
    const val  = wrap.querySelector('.vault-pass-val');
    if(!val.classList.contains('hidden')){
        val.classList.add('hidden'); dots.classList.remove('hidden'); return;
    }
    let pass = secretCache[id];
    if(pass===undefined){
        const j = await fetchRecord(id,'reveal');
        if(!j.ok){ vaultToast(j.error||'Error'); return; }
        pass = j.record.password||''; secretCache[id]=pass;
    }
    val.textContent = pass || '(sin contrasena)';
    val.classList.remove('hidden'); dots.classList.add('hidden');
}
async function vaultCopyPass(id, btn){
    let pass = secretCache[id];
    if(pass===undefined){
        const j = await fetchRecord(id,'reveal');
        if(!j.ok){ vaultToast(j.error||'Error'); return; }
        pass = j.record.password||''; secretCache[id]=pass;
    }
    copyText(pass, btn);
}

// ---- Crear / editar ----
function setF(name, val){ const f=document.getElementById('vaultForm'); if(f && f.elements[name]) f.elements[name].value = (val==null?'':val); }
function setPassType(t){ const i=document.getElementById('vaultPassInput'); if(i) i.type=t; }
function togglePass(){ const i=document.getElementById('vaultPassInput'); i.type = i.type==='password'?'text':'password'; }
function genPass(){
    const chars='ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!@#$%&*?-_';
    const a=new Uint32Array(18); crypto.getRandomValues(a);
    let p=''; for(let i=0;i<18;i++) p+=chars[a[i]%chars.length];
    setF('password',p); setPassType('text'); vaultToast('Contrasena generada');
}

function openVaultCreate(){
    vaultFormMode='create';
    const f=document.getElementById('vaultForm'); f.reset();
    setF('id',''); setF('action','save'); setF('status','activo');
    setPassType('password');
    document.getElementById('vaultFormTitle').textContent='Nuevo acceso';
    showModal('vaultFormModal');
}

async function openVaultEdit(id){
    vaultFormMode='edit';
    const j = await fetchRecord(id,'edit');
    if(!j || !j.ok){ vaultToast(j&&j.error||'No se pudo cargar'); return; }
    const r=j.record, f=document.getElementById('vaultForm'); f.reset();
    setF('id', r.id); setF('action','save'); setF('client_id', r.client_id);
    setF('rnc_cedula', r.rnc_cedula); setF('platform', r.platform); setF('status', r.status||'activo');
    setF('access_url', r.access_url); setF('username', r.username); setF('password', r.password);
    setF('email_assoc', r.email_assoc); setF('phone_assoc', r.phone_assoc);
    setF('sec_q1', r.sec_q1); setF('sec_a1', r.sec_a1);
    setF('sec_q2', r.sec_q2); setF('sec_a2', r.sec_a2);
    setF('sec_q3', r.sec_q3); setF('sec_a3', r.sec_a3);
    setF('notes', r.notes); setF('usage_notes', r.usage_notes);
    setPassType('password');
    document.getElementById('vaultFormTitle').textContent='Editar acceso';
    showModal('vaultFormModal');
}

function vaultClientChange(sel){
    if(vaultFormMode!=='create') return;
    const c = VAULT_CLIENTS[sel.value];
    if(!c) return;
    const f=document.getElementById('vaultForm');
    if(c.rnc && !f.elements['rnc_cedula'].value) f.elements['rnc_cedula'].value = c.rnc;
    if(c.email && !f.elements['email_assoc'].value) f.elements['email_assoc'].value = c.email;
    if(c.phone && !f.elements['phone_assoc'].value) f.elements['phone_assoc'].value = c.phone;
}

// ---- Detalle ----
function dRow(label, value, opts){
    opts = opts||{};
    if(value===null||value===undefined||value===''){
        return '<div class="d-row"><div class="d-label">'+label+'</div><div class="d-val d-empty">—</div></div>';
    }
    let valHtml;
    if(opts.secret){
        valHtml = '<span class="d-secret">'
            + '<span class="d-dots" style="font-family:ui-monospace,monospace;letter-spacing:.15em;color:#94A3B8">••••••••</span>'
            + '<span class="d-real hidden" style="font-family:ui-monospace,monospace">'+jsEsc(value)+'</span>'
            + '</span>'
            + '<button type="button" class="d-mini" data-toggle="1">Mostrar</button>'
            + '<button type="button" class="d-mini" data-copy="'+jsAttr(value)+'">Copiar</button>';
    } else {
        valHtml = '<span'+(opts.mono?' style="font-family:ui-monospace,monospace"':'')+'>'+jsEsc(value)+'</span>';
        if(opts.link) valHtml = '<a href="'+jsAttr(value)+'" target="_blank" rel="noopener" class="text-blue-600 hover:underline" style="word-break:break-all">'+jsEsc(value)+'</a>';
        if(!opts.nocopy) valHtml += '<button type="button" class="d-mini" data-copy="'+jsAttr(value)+'">Copiar</button>';
    }
    return '<div class="d-row"><div class="d-label">'+label+'</div><div class="d-val">'+valHtml+'</div></div>';
}

function secretRow(label, value, hasValue, canReveal){
    if(!hasValue){ return dRow(label, '', {}); }
    if(!canReveal){
        return '<div class="d-row"><div class="d-label">'+label+'</div>'
            + '<div class="d-val"><span style="font-family:ui-monospace,monospace;letter-spacing:.15em;color:#94A3B8">••••••••</span>'
            + '<span style="font-size:11px;color:#CBD5E1">(sin permiso para revelar)</span></div></div>';
    }
    return dRow(label, value, {secret:true});
}

function fmtDate(s){ if(!s) return ''; const d=new Date(s.replace(' ','T')); if(isNaN(d)) return s; return d.toLocaleString('es-DO',{day:'2-digit',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}); }

const AUDIT_LABEL = {create:'Creado', update:'Actualizado', delete:'Eliminado', view:'Visto'};

async function openVaultDetail(id){
    const j = await fetchRecord(id,'reveal');
    if(!j || !j.ok){ vaultToast(j&&j.error||'No se pudo cargar'); return; }
    const r=j.record;
    document.getElementById('vaultDetailSub').textContent = r.platform + ' · ' + r.client_name;
    const stLbl = VAULT_STATUS[r.status] || r.status;
    let html = '';
    html += dRow('Cliente', r.client_name, {nocopy:true});
    html += dRow('RNC o cedula', r.rnc_cedula, {mono:true});
    html += dRow('Plataforma o servicio', r.platform, {nocopy:true});
    html += dRow('Estado del acceso', stLbl, {nocopy:true});
    html += dRow('Link de acceso', r.access_url, {link:true});
    html += dRow('Usuario', r.username, {mono:true});
    html += secretRow('Contrasena', r.password, r.has_password, r.can_reveal);
    html += dRow('Correo asociado', r.email_assoc, {});
    html += dRow('Telefono asociado', r.phone_assoc, {});
    if(r.sec_q1||r.has_a1){ html += secretRow('Pregunta 1: '+(r.sec_q1||'—'), r.sec_a1, r.has_a1, r.can_reveal); }
    if(r.sec_q2||r.has_a2){ html += secretRow('Pregunta 2: '+(r.sec_q2||'—'), r.sec_a2, r.has_a2, r.can_reveal); }
    if(r.sec_q3||r.has_a3){ html += secretRow('Pregunta 3: '+(r.sec_q3||'—'), r.sec_a3, r.has_a3, r.can_reveal); }
    html += dRow('Observaciones', r.notes, {nocopy:true});
    html += dRow('Forma de uso', r.usage_notes, {nocopy:true});
    html += '<div class="d-row"><div class="d-label">Registro</div><div class="d-val" style="display:block;color:#64748B;font-size:12px">'
        + 'Creado '+fmtDate(r.created_at)+(r.created_by_name?(' por '+jsEsc(r.created_by_name)):'')+'<br>'
        + 'Actualizado '+fmtDate(r.updated_at)+(r.updated_by_name?(' por '+jsEsc(r.updated_by_name)):'')
        + '</div></div>';

    if(j.audit && j.audit.length){
        html += '<div style="margin-top:14px"><div class="d-label" style="margin-bottom:6px">Auditoria reciente</div>';
        j.audit.forEach(function(a){
            html += '<div class="d-audit-item"><span style="font-weight:700;color:#475569;min-width:78px">'+(AUDIT_LABEL[a.action]||a.action)+'</span>'
                + '<span style="flex:1">'+jsEsc(a.user_name||'Sistema')+' · '+fmtDate(a.created_at)+'</span></div>';
        });
        html += '</div>';
    }

    document.getElementById('vaultDetailBody').innerHTML = html;
    showModal('vaultDetailModal');
}

document.getElementById('vaultDetailBody').addEventListener('click', function(e){
    const cp = e.target.closest('[data-copy]');
    if(cp){ copyText(cp.getAttribute('data-copy')); return; }
    const tg = e.target.closest('[data-toggle]');
    if(tg){
        const box = tg.closest('.d-val');
        const dots = box.querySelector('.d-dots');
        const real = box.querySelector('.d-real');
        const hidden = real.classList.toggle('hidden');
        dots.classList.toggle('hidden');
        tg.textContent = hidden ? 'Mostrar' : 'Ocultar';
    }
});

document.addEventListener('keydown', function(e){
    if(e.key==='Escape'){ hideModal('vaultDetailModal'); hideModal('vaultFormModal'); }
});
</script>

<?php include 'components/layout_end.php'; ?>
