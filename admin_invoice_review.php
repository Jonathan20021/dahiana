<?php
require_once 'config.php';
requireAuth('admin');
requirePagePermission();

$success = $error = null;

$period = $_GET['period'] ?? date('Y-m');
$showAllPeriods = ($period === 'all');
if (!$showAllPeriods && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) $period = date('Y-m');
$months = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
$periodLabel = $showAllPeriods ? 'Todos los periodos' : ($months[(int)substr($period, 5, 2) - 1] . ' ' . substr($period, 0, 4));
$prevPeriod = $showAllPeriods ? date('Y-m') : date('Y-m', strtotime($period . '-01 -1 month'));
$nextPeriod = $showAllPeriods ? date('Y-m') : date('Y-m', strtotime($period . '-01 +1 month'));

$filterClient = (int)($_GET['client_id'] ?? 0);
$filterStatus = $_GET['status'] ?? 'pending';
$filterType   = $_GET['doc_type'] ?? '';

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_extraction') {
        $eid = (int)($_POST['extraction_id'] ?? 0);
        if ($eid > 0) {
            $fields = [
                'doc_type'         => $_POST['doc_type'] ?? 'compra',
                'date_doc'         => $_POST['date_doc'] ?: null,
                'date_payment'     => $_POST['date_payment'] ?: null,
                'rnc'              => trim($_POST['rnc'] ?? ''),
                'counterparty_name'=> trim($_POST['counterparty_name'] ?? ''),
                'ncf'              => trim($_POST['ncf'] ?? ''),
                'ncf_modified'     => trim($_POST['ncf_modified'] ?? ''),
                'ncf_type'         => trim($_POST['ncf_type'] ?? ''),
                'concept'          => trim($_POST['concept'] ?? ''),
                'expense_category' => trim($_POST['expense_category'] ?? ''),
                'income_type'      => trim($_POST['income_type'] ?? ''),
                'identification_type' => trim($_POST['identification_type'] ?? ''),
                'payment_method'   => trim($_POST['payment_method'] ?? ''),
                'subtotal'         => (float)($_POST['subtotal'] ?? 0),
                'itbis'            => (float)($_POST['itbis'] ?? 0),
                'propina_legal'    => (float)($_POST['propina_legal'] ?? 0),
                'transporte'       => (float)($_POST['transporte'] ?? 0),
                'isr_retention'    => (float)($_POST['isr_retention'] ?? 0),
                'itbis_retention'  => (float)($_POST['itbis_retention'] ?? 0),
                'total'            => (float)($_POST['total'] ?? 0),
            ];
            if (!in_array($fields['doc_type'], ['compra','venta'], true)) $fields['doc_type'] = 'compra';
            $fields['period'] = $fields['date_doc'] ? date('Y-m', strtotime($fields['date_doc'])) : null;

            $stmt = $pdo->prepare("
                UPDATE invoice_extractions SET
                  doc_type=?, period=?, date_doc=?, date_payment=?, rnc=?, counterparty_name=?,
                  ncf=?, ncf_modified=?, ncf_type=?, concept=?, expense_category=?, income_type=?, identification_type=?, payment_method=?,
                  subtotal=?, itbis=?, propina_legal=?, transporte=?, isr_retention=?, itbis_retention=?, total=?
                WHERE id=?
            ");
            $stmt->execute([
                $fields['doc_type'], $fields['period'], $fields['date_doc'], $fields['date_payment'],
                $fields['rnc'], $fields['counterparty_name'],
                $fields['ncf'], $fields['ncf_modified'], $fields['ncf_type'], $fields['concept'],
                $fields['expense_category'], $fields['income_type'], $fields['identification_type'], $fields['payment_method'],
                $fields['subtotal'], $fields['itbis'], $fields['propina_legal'], $fields['transporte'],
                $fields['isr_retention'], $fields['itbis_retention'], $fields['total'],
                $eid
            ]);
            // also sync period on upload
            $pdo->prepare("UPDATE invoice_uploads u JOIN invoice_extractions e ON e.upload_id=u.id SET u.period=e.period, u.doc_type=e.doc_type WHERE e.id=?")
                ->execute([$eid]);

            // If it was already approved, update its filing row too
            $rowInfo = $pdo->prepare("SELECT filing_row_id, client_id FROM invoice_extractions WHERE id=?");
            $rowInfo->execute([$eid]);
            $ri = $rowInfo->fetch();
            if ($ri && !empty($ri['filing_row_id'])) {
                $res = aiApproveExtraction($eid, $_SESSION['user_id'] ?? null); // re-syncs the row
            }
            $success = 'Datos actualizados.';
        }
    } elseif ($action === 'approve') {
        $eid = (int)($_POST['extraction_id'] ?? 0);
        if ($eid > 0) {
            $res = aiApproveExtraction($eid, $_SESSION['user_id'] ?? null);
            if ($res['ok']) {
                $success = "Aprobada y agregada al {$res['filing_type']} de {$res['period']}.";
            } else {
                $error = $res['error'] ?? 'Error al aprobar.';
            }
        }
    } elseif ($action === 'reject') {
        $eid = (int)($_POST['extraction_id'] ?? 0);
        if ($eid > 0) {
            $res = aiRejectExtraction($eid);
            $success = $res['ok'] ? 'Extraccion rechazada y removida del formulario.' : ($res['error'] ?? 'Error.');
        }
    } elseif ($action === 'reprocess') {
        $uid = (int)($_POST['upload_id'] ?? 0);
        if ($uid > 0) {
            $res = aiProcessUpload($uid);
            $success = $res['ok'] ? 'Reprocesada con IA.' : null;
            $error   = $res['ok'] ? null : ($res['error'] ?? 'Error.');
        }
    } elseif ($action === 'bulk_approve') {
        $ids = $_POST['ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ok = 0;
        foreach ($ids as $eid) {
            $eid = (int)$eid;
            if ($eid > 0) {
                $r = aiApproveExtraction($eid, $_SESSION['user_id'] ?? null);
                if ($r['ok']) $ok++;
            }
        }
        $success = "{$ok} factura(s) aprobada(s) e insertada(s) en sus formularios.";
    } elseif ($action === 'delete_upload') {
        $uid = (int)($_POST['upload_id'] ?? 0);
        if ($uid > 0) {
            // Free filing row if it was already approved
            $ex = $pdo->prepare("SELECT id FROM invoice_extractions WHERE upload_id=? AND approved=1");
            $ex->execute([$uid]);
            foreach ($ex->fetchAll(PDO::FETCH_COLUMN) as $eid) {
                aiRejectExtraction((int)$eid);
            }
            $u = $pdo->prepare("SELECT filename FROM invoice_uploads WHERE id=?");
            $u->execute([$uid]);
            $row = $u->fetch();
            if ($row) {
                @unlink(aiUploadsDir() . '/' . $row['filename']);
                $pdo->prepare("DELETE FROM invoice_extractions WHERE upload_id=?")->execute([$uid]);
                $pdo->prepare("DELETE FROM invoice_uploads WHERE id=?")->execute([$uid]);
                $success = 'Factura eliminada.';
            }
        }
    } elseif ($action === 'upload') {
        $targetClient = (int)($_POST['target_client_id'] ?? 0);
        $docTypeHint  = $_POST['doc_type'] ?? 'auto';
        if (!in_array($docTypeHint, ['auto','compra','venta'], true)) $docTypeHint = 'auto';

        if ($targetClient <= 0) {
            $error = 'Selecciona un cliente para asignarle estas facturas.';
        } elseif (empty($_FILES['files']['name'][0])) {
            $error = 'Selecciona al menos una foto de factura.';
        } else {
            $maxMb = (int)getSetting('openai_max_size_mb', '12');
            $maxBytes = max(1, $maxMb) * 1024 * 1024;
            $autoProcess = getSetting('openai_auto_process', '1') === '1' && getSetting('openai_enabled', '1') === '1';
            $dir = aiUploadsDir();
            $okCount = 0; $fail = [];

            $files = $_FILES['files'];
            $n = count($files['name']);
            for ($i = 0; $i < $n; $i++) {
                if ($files['error'][$i] !== UPLOAD_ERR_OK) { $fail[] = $files['name'][$i] . ' (error subida)'; continue; }
                $size = (int)$files['size'][$i];
                $mime = (string)$files['type'][$i];
                $orig = (string)$files['name'][$i];
                $tmp  = (string)$files['tmp_name'][$i];
                if (!in_array($mime, aiAcceptedMimes(), true)) {
                    $info = @getimagesize($tmp);
                    if ($info && !empty($info['mime'])) $mime = $info['mime'];
                }
                if (!in_array($mime, aiAcceptedMimes(), true) || !aiIsImageMime($mime)) { $fail[] = $orig . ' (formato no permitido)'; continue; }
                if ($size > $maxBytes) { $fail[] = $orig . " (excede {$maxMb} MB)"; continue; }

                $tmpSha = @hash_file('sha256', $tmp);
                if ($tmpSha && aiFindDuplicateUpload($targetClient, $tmpSha) > 0) {
                    $fail[] = $orig . ' (ya existe para este cliente)';
                    continue;
                }

                $ext = pathinfo($orig, PATHINFO_EXTENSION) ?: 'jpg';
                $filename = 'inv_' . $targetClient . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($ext);
                $dest = $dir . '/' . $filename;
                if (!move_uploaded_file($tmp, $dest)) { $fail[] = $orig . ' (no guardado)'; continue; }

                $uid = aiCreateUploadRecord($targetClient, [
                    'filename'      => $filename,
                    'original_name' => $orig,
                    'mime_type'     => $mime,
                    'file_size'     => $size,
                    'sha256'        => hash_file('sha256', $dest),
                ], $docTypeHint, $_SESSION['user_id'] ?? null);

                if ($autoProcess) {
                    $r = aiProcessUpload($uid);
                    if (!$r['ok']) $fail[] = $orig . ' (' . $r['error'] . ')';
                }
                $okCount++;
            }
            if ($okCount > 0) {
                logClientActivity($targetClient, 'invoice_upload', "Admin subio {$okCount} factura(s) en nombre del cliente");
            }
            $success = $okCount > 0
                ? ("{$okCount} factura(s) subida(s)" . (empty($fail) ? '.' : '. Errores: ' . implode(', ', $fail)))
                : null;
            if (empty($success)) $error = 'No se pudo subir: ' . implode(', ', $fail);
        }
    } elseif ($action === 'bulk_reprocess') {
        $ids = $_POST['upload_ids'] ?? [];
        if (!is_array($ids)) $ids = [];
        $ok = 0;
        foreach ($ids as $uid) {
            $uid = (int)$uid;
            if ($uid > 0) {
                $r = aiProcessUpload($uid);
                if ($r['ok']) $ok++;
            }
        }
        $success = "{$ok} factura(s) reprocesada(s) con IA.";
    }
}

// Filters builder
$where = "1=1 AND " . clientScopeWhere('u.client_id');
$params = [];

if ($filterStatus === 'pending') {
    $where .= " AND u.status IN ('extracted')";
} elseif ($filterStatus === 'approved') {
    $where .= " AND u.status = 'approved'";
} elseif ($filterStatus === 'errors') {
    $where .= " AND u.status IN ('error','uploaded','processing')";
} elseif ($filterStatus === 'all') {
    // no constraint
}
if ($filterClient > 0) { $where .= " AND u.client_id = ?"; $params[] = $filterClient; }
if (in_array($filterType, ['compra','venta'], true)) { $where .= " AND e.doc_type = ?"; $params[] = $filterType; }

// Period filter: prefer extraction period; fall back to upload period or created_at.
// Si se elige "all", no se filtra por periodo (muestra todas las facturas).
if (!$showAllPeriods) {
    $where .= " AND (
        e.period = ?
        OR (e.period IS NULL AND u.period = ?)
        OR (e.period IS NULL AND u.period IS NULL AND DATE_FORMAT(u.created_at, '%Y-%m') = ?)
    )";
    $params[] = $period; $params[] = $period; $params[] = $period;
}

$sql = "
    SELECT u.id AS upload_id, u.client_id, u.filename, u.original_name, u.mime_type, u.status, u.error_message, u.created_at,
           c.name AS client_name, c.business_name, c.rnc AS client_rnc,
           e.id AS extraction_id, e.doc_type, e.date_doc, e.date_payment, e.rnc, e.counterparty_name,
           e.ncf, e.ncf_modified, e.ncf_type, e.concept, e.expense_category, e.income_type, e.identification_type, e.payment_method,
           e.subtotal, e.itbis, e.propina_legal, e.transporte, e.isr_retention, e.itbis_retention, e.total,
           e.confidence, e.period AS ai_period, e.approved, e.filing_row_id
    FROM invoice_uploads u
    LEFT JOIN invoice_extractions e ON e.upload_id = u.id
    LEFT JOIN users c ON c.id = u.client_id
    WHERE {$where}
    ORDER BY u.created_at DESC
    LIMIT 500
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// Clients dropdown
$clients = $pdo->query("
    SELECT u.id, u.name, u.business_name
    FROM users u
    LEFT JOIN roles r ON r.slug = u.role
    WHERE COALESCE(r.access_level, CASE WHEN u.role = 'admin' THEN 'admin' ELSE 'client' END) = 'client'
    ORDER BY u.name
")->fetchAll();

// Aggregated totals for header chip
if ($showAllPeriods) {
    $agg = $pdo->query("
        SELECT
          COUNT(*) AS n,
          SUM(CASE WHEN u.status='extracted' THEN 1 ELSE 0 END) AS pending,
          SUM(CASE WHEN u.status='approved'  THEN 1 ELSE 0 END) AS approved
        FROM invoice_uploads u
        LEFT JOIN invoice_extractions e ON e.upload_id = u.id
    ");
    $summary = $agg->fetch() ?: ['n'=>0,'pending'=>0,'approved'=>0];
} else {
    $agg = $pdo->prepare("
        SELECT
          COUNT(*) AS n,
          SUM(CASE WHEN u.status='extracted' THEN 1 ELSE 0 END) AS pending,
          SUM(CASE WHEN u.status='approved'  THEN 1 ELSE 0 END) AS approved
        FROM invoice_uploads u
        LEFT JOIN invoice_extractions e ON e.upload_id = u.id
        WHERE (e.period = ? OR (e.period IS NULL AND DATE_FORMAT(u.created_at,'%Y-%m') = ?))
    ");
    $agg->execute([$period, $period]);
    $summary = $agg->fetch() ?: ['n'=>0,'pending'=>0,'approved'=>0];
}

// Pendientes en OTROS periodos (para banner de alerta)
$otherPeriodsAlert = null;
if (!$showAllPeriods) {
    $otherPending = $pdo->prepare("
        SELECT e.period, COUNT(*) AS n
        FROM invoice_uploads u
        JOIN invoice_extractions e ON e.upload_id = u.id
        WHERE u.status = 'extracted'
          AND e.period IS NOT NULL
          AND e.period != ?
        GROUP BY e.period
        ORDER BY e.period DESC
    ");
    $otherPending->execute([$period]);
    $otherRows = $otherPending->fetchAll();
    if (!empty($otherRows)) {
        $totalOther = array_sum(array_column($otherRows, 'n'));
        $otherPeriodsAlert = [
            'total'  => (int)$totalOther,
            'periods'=> $otherRows,
        ];
    }
}

$expenseCategories = aiExpenseCategories();
$incomeTypes       = aiIncomeTypes();
$idTypes           = aiIdentificationTypes();
$paymentMethods    = aiPaymentMethods();

$page_title = 'Revisar facturas IA';
$page_subtitle = 'Aprueba o corrige los datos que la IA extrajo de las facturas subidas por los clientes.';
include 'components/layout_start.php';
?>

<?php if ($success): ?>
<div class="mb-4 rounded-2xl bg-emerald-50 px-4 py-3 border border-emerald-100 text-sm font-medium text-emerald-800"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="mb-4 rounded-2xl bg-red-50 px-4 py-3 border border-red-100 text-sm font-medium text-red-700"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Upload (admin sube facturas en nombre del cliente) -->
<div data-tour="upload-zone" class="surface-card p-5 mb-4">
    <div class="flex items-start gap-3 mb-4">
        <div class="w-11 h-11 rounded-2xl bg-slate-900 text-white flex items-center justify-center shrink-0">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
        </div>
        <div class="min-w-0">
            <h3 class="text-base font-bold text-slate-900">Subir facturas con IA</h3>
            <p class="text-xs text-slate-500 leading-relaxed mt-0.5">
                Carga fotos en nombre de cualquier cliente. La IA extrae RNC, NCF, ITBIS, propina, retenciones y categoria 606. Al aprobar, se generan automaticamente las lineas del 606/607 y se recalcula el IT-1.
            </p>
        </div>
    </div>

    <form method="POST" enctype="multipart/form-data" class="grid grid-cols-1 sm:grid-cols-12 gap-3" id="adminUploadForm">
        <input type="hidden" name="action" value="upload">
        <div class="sm:col-span-5">
            <label class="field-label">Cliente destino</label>
            <select name="target_client_id" required class="field !text-sm">
                <option value="">Selecciona un cliente...</option>
                <?php foreach ($clients as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= ($filterClient===(int)$c['id'])?'selected':'' ?>><?= htmlspecialchars($c['business_name'] ?: $c['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="sm:col-span-3">
            <label class="field-label">Tipo</label>
            <select name="doc_type" class="field !text-sm">
                <option value="auto">Auto (la IA decide)</option>
                <option value="compra">Compra (606)</option>
                <option value="venta">Venta (607)</option>
            </select>
        </div>
        <div class="sm:col-span-4 flex items-end">
            <button type="submit" id="adminUploadBtn" class="btn-dark text-sm w-full justify-center">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                Subir y procesar con IA
            </button>
        </div>
        <div class="sm:col-span-12">
            <label class="block cursor-pointer rounded-2xl border-2 border-dashed border-stone-200 bg-stone-50 hover:border-blue-400 hover:bg-blue-50/40 transition-colors px-4 py-6 text-center" id="adminDropZone">
                <svg class="w-6 h-6 mx-auto text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M9 13l3-3m0 0l3 3m-3-3v8m0-13a9 9 0 100 18 9 9 0 000-18z"/></svg>
                <p class="mt-1 text-sm font-semibold text-slate-700">Arrastra fotos aqui o haz click</p>
                <p class="text-[11px] text-slate-400">JPG/PNG/WEBP. Multiple seleccion permitida.</p>
                <input type="file" name="files[]" id="adminFileInput" accept="image/*" multiple class="hidden">
            </label>
            <p id="adminFileSummary" class="mt-2 text-xs text-slate-500 hidden"></p>
        </div>
    </form>
</div>

<?php if ($otherPeriodsAlert): ?>
<div class="mb-4 rounded-2xl bg-amber-50 border border-amber-200 px-4 py-3 flex items-start gap-3">
    <svg class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75c0 4.97-4.03 9-9 9s-9-4.03-9-9 4.03-9 9-9 9 4.03 9 9zm-9 3.75h.008v.008H12v-.008z"/></svg>
    <div class="flex-1 min-w-0">
        <p class="text-sm font-bold text-amber-900">
            Hay <?= (int)$otherPeriodsAlert['total'] ?> factura(s) pendiente(s) por aprobar en otros periodos
        </p>
        <p class="text-xs text-amber-800 mt-1">
            <?php foreach (array_slice($otherPeriodsAlert['periods'], 0, 5) as $op): ?>
            <a href="?period=<?= htmlspecialchars($op['period']) ?>&status=pending" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-white border border-amber-200 hover:border-amber-400 mr-1 mb-1 font-semibold">
                <?= htmlspecialchars(formatPeriod($op['period'])) ?>
                <span class="text-amber-700"><?= (int)$op['n'] ?></span>
            </a>
            <?php endforeach; ?>
            <a href="?period=all&status=pending" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-amber-900 text-white hover:bg-amber-800 ml-1 font-bold">
                Ver todas →
            </a>
        </p>
    </div>
</div>
<?php endif; ?>

<!-- Filters -->
<div data-tour="filters" class="surface-card p-3 mb-4 flex flex-col lg:flex-row gap-3 items-stretch lg:items-center">
    <div class="flex items-center gap-2">
        <a href="?period=<?= $prevPeriod ?>&status=<?= urlencode($filterStatus) ?>&client_id=<?= $filterClient ?>" class="icon-btn" title="Mes anterior">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <span class="px-3 py-2 rounded-xl <?= $showAllPeriods ? 'bg-blue-100 text-blue-900' : 'bg-stone-50 text-slate-900' ?> text-sm font-bold min-w-[140px] text-center"><?= $periodLabel ?></span>
        <a href="?period=<?= $nextPeriod ?>&status=<?= urlencode($filterStatus) ?>&client_id=<?= $filterClient ?>" class="icon-btn" title="Mes siguiente">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        </a>
        <?php if ($showAllPeriods): ?>
        <a href="?period=<?= date('Y-m') ?>&status=<?= urlencode($filterStatus) ?>&client_id=<?= $filterClient ?>" class="text-xs px-3 py-2 rounded-xl bg-stone-100 hover:bg-stone-200 text-slate-700 font-bold">
            Volver al mes actual
        </a>
        <?php else: ?>
        <a href="?period=all&status=<?= urlencode($filterStatus) ?>&client_id=<?= $filterClient ?>" class="text-xs px-3 py-2 rounded-xl bg-stone-100 hover:bg-stone-200 text-slate-700 font-bold" title="Mostrar facturas de todos los periodos">
            Todos
        </a>
        <?php endif; ?>
    </div>

    <form method="GET" class="flex flex-col sm:flex-row gap-2 lg:ml-auto w-full lg:w-auto">
        <input type="hidden" name="period" value="<?= htmlspecialchars($period) ?>">
        <select name="status" class="field !text-sm sm:w-40" onchange="this.form.submit()">
            <?php foreach (['pending'=>'Por aprobar','approved'=>'Aprobadas','errors'=>'En cola/error','all'=>'Todas'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= $filterStatus===$k?'selected':'' ?>><?= $v ?></option>
            <?php endforeach; ?>
        </select>
        <select name="doc_type" class="field !text-sm sm:w-36" onchange="this.form.submit()">
            <option value="">Compras y ventas</option>
            <option value="compra" <?= $filterType==='compra'?'selected':'' ?>>Compras (606)</option>
            <option value="venta"  <?= $filterType==='venta'?'selected':'' ?>>Ventas (607)</option>
        </select>
        <select name="client_id" class="field !text-sm sm:w-56" onchange="this.form.submit()">
            <option value="0">Todos los clientes</option>
            <?php foreach ($clients as $c): ?>
            <option value="<?= $c['id'] ?>" <?= $filterClient===(int)$c['id']?'selected':'' ?>><?= htmlspecialchars($c['business_name'] ?: $c['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-4">
    <div class="surface-card p-4 flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        </div>
        <div><p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">Total subidas</p><p class="text-lg font-extrabold text-slate-900"><?= (int)$summary['n'] ?></p></div>
    </div>
    <div class="surface-card p-4 flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <div><p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">Por aprobar</p><p class="text-lg font-extrabold text-slate-900"><?= (int)$summary['pending'] ?></p></div>
    </div>
    <div class="surface-card p-4 flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
        </div>
        <div><p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">Aprobadas</p><p class="text-lg font-extrabold text-slate-900"><?= (int)$summary['approved'] ?></p></div>
    </div>
</div>

<form method="POST" id="bulkForm">
    <input type="hidden" name="action" value="bulk_approve">
    <div class="surface-card overflow-hidden">
        <div class="px-5 py-3 border-b border-stone-100 flex items-center gap-3">
            <h3 class="text-sm font-bold text-slate-900">Facturas IA</h3>
            <span class="text-xs text-slate-400"><?= count($rows) ?> resultado(s)</span>
            <div class="ml-auto flex items-center gap-2">
                <span class="text-[11px] text-slate-500 hidden sm:inline">Seleccionadas: <span id="selCount">0</span></span>
                <button type="submit" id="bulkBtn" disabled class="btn-dark text-xs opacity-50 cursor-not-allowed">Aprobar seleccionadas</button>
            </div>
        </div>

        <?php if (empty($rows)): ?>
        <div class="py-16 text-center">
            <div class="w-14 h-14 mx-auto rounded-full bg-stone-100 flex items-center justify-center mb-3">
                <svg class="w-6 h-6 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12c0 1.268-.63 2.39-1.593 3.068a3.745 3.745 0 01-1.043 3.296 3.745 3.745 0 01-3.296 1.043A3.745 3.745 0 0112 21c-1.268 0-2.39-.63-3.068-1.593a3.746 3.746 0 01-3.296-1.043 3.745 3.745 0 01-1.043-3.296A3.745 3.745 0 013 12c0-1.268.63-2.39 1.593-3.068a3.745 3.745 0 011.043-3.296 3.746 3.746 0 013.296-1.043A3.746 3.746 0 0112 3c1.268 0 2.39.63 3.068 1.593a3.746 3.746 0 013.296 1.043 3.746 3.746 0 011.043 3.296A3.745 3.745 0 0121 12z"/></svg>
            </div>
            <p class="text-sm text-slate-500 font-semibold">Sin facturas para este filtro</p>
            <p class="text-xs text-slate-400 mt-1">Sube facturas desde el formulario de arriba o pide a tus clientes que las envien.</p>
        </div>
        <?php else: ?>
        <div class="divide-y divide-stone-100" id="invoiceList">
            <?php foreach ($rows as $r):
                $thumbHref = 'uploads/invoices/' . htmlspecialchars($r['filename']);
                $isApproved = !empty($r['approved']);
                $isImage = strpos($r['mime_type'], 'image/') === 0;
                $confidence = (float)($r['confidence'] ?? 0);
                $confPct = round($confidence * 100);
                $confTone = $confidence >= 0.85 ? 'emerald' : ($confidence >= 0.6 ? 'amber' : 'red');
                $statusBadge = match($r['status']) {
                    'uploaded'   => '<span class="ir-pill ir-pill-slate">En cola</span>',
                    'processing' => '<span class="ir-pill ir-pill-blue">Procesando</span>',
                    'extracted'  => '<span class="ir-pill ir-pill-amber">Por aprobar</span>',
                    'approved'   => '<span class="ir-pill ir-pill-emerald">Aprobada</span>',
                    'rejected'   => '<span class="ir-pill ir-pill-slate">Rechazada</span>',
                    'error'      => '<span class="ir-pill ir-pill-red">Error</span>',
                    default      => '<span class="ir-pill ir-pill-slate">' . htmlspecialchars($r['status']) . '</span>',
                };
                $rowId = 'inv-row-' . (int)$r['upload_id'];
                $docTypeBadge = $r['doc_type']
                    ? ($r['doc_type'] === 'venta' ? '<span class="ir-pill ir-pill-blue">607 Venta</span>' : '<span class="ir-pill ir-pill-indigo">606 Compra</span>')
                    : '';
                $clientLabel = $r['business_name'] ?: $r['client_name'];
            ?>
            <article class="ir-row group" data-row-id="<?= $rowId ?>">
                <!-- Compact header always visible -->
                <div class="ir-head">
                    <!-- thumb -->
                    <a href="<?= $thumbHref ?>" target="_blank" class="ir-thumb shrink-0" title="Ver original">
                        <?php if ($isImage): ?>
                        <img src="<?= $thumbHref ?>" alt="" loading="lazy">
                        <?php else: ?>
                        <span class="ir-thumb-fallback">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                        </span>
                        <?php endif; ?>
                    </a>

                    <!-- main info -->
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2 flex-wrap mb-1">
                            <p class="font-bold text-slate-900 text-sm truncate"><?= htmlspecialchars($clientLabel) ?></p>
                            <?= $statusBadge ?>
                            <?= $docTypeBadge ?>
                            <?php if ($r['extraction_id']): ?>
                            <span class="ir-conf ir-conf-<?= $confTone ?>" title="Confianza de la IA en esta extraccion">
                                <span class="ir-conf-bar"><span style="width: <?= $confPct ?>%"></span></span>
                                <?= $confPct ?>%
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="flex items-center gap-x-4 gap-y-1 flex-wrap text-[11px] text-slate-500">
                            <?php if ($r['extraction_id']): ?>
                            <span class="inline-flex items-center gap-1.5 truncate max-w-[260px]">
                                <span class="text-slate-400">Proveedor:</span>
                                <span class="font-semibold text-slate-700 truncate"><?= htmlspecialchars($r['counterparty_name'] ?: '—') ?></span>
                            </span>
                            <span class="inline-flex items-center gap-1.5 font-mono">
                                <span class="text-slate-400">NCF:</span>
                                <span class="font-semibold text-slate-700"><?= htmlspecialchars($r['ncf'] ?: '—') ?></span>
                            </span>
                            <span class="inline-flex items-center gap-1.5 font-mono">
                                <span class="text-slate-400">RNC:</span>
                                <span class="text-slate-700"><?= htmlspecialchars($r['rnc'] ?: '—') ?></span>
                            </span>
                            <span class="inline-flex items-center gap-1.5">
                                <span class="text-slate-400"><?= $r['date_doc'] ? date('d/m/Y', strtotime($r['date_doc'])) : '—' ?></span>
                            </span>
                            <?php else: ?>
                            <span class="text-amber-700"><?= htmlspecialchars($r['original_name']) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Totals (visible) -->
                    <?php if ($r['extraction_id']): ?>
                    <div class="ir-totals shrink-0">
                        <div>
                            <p class="ir-tot-label">Total</p>
                            <p class="ir-tot-val">RD$ <?= number_format((float)$r['total'], 2) ?></p>
                        </div>
                        <div>
                            <p class="ir-tot-label">ITBIS</p>
                            <p class="ir-tot-val ir-tot-itbis">RD$ <?= number_format((float)$r['itbis'], 2) ?></p>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Quick actions -->
                    <div class="flex items-center gap-1.5 shrink-0">
                        <?php if (!$isApproved && $r['extraction_id']): ?>
                        <label class="ir-checkbox" title="Seleccionar para aprobar en lote">
                            <input type="checkbox" name="ids[]" value="<?= (int)$r['extraction_id'] ?>" class="bulk-check">
                            <span></span>
                        </label>
                        <?php endif; ?>

                        <?php if ($r['extraction_id']): ?>
                        <button type="button" class="ir-btn ir-btn-ghost" data-toggle-row="<?= $rowId ?>" title="Editar campos">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                            <span class="hidden md:inline">Editar</span>
                        </button>

                        <?php if (!$isApproved): ?>
                        <form method="POST" onsubmit="return confirm('Aprobar y agregar a 606/607?')" class="inline-flex">
                            <input type="hidden" name="action" value="approve">
                            <input type="hidden" name="extraction_id" value="<?= (int)$r['extraction_id'] ?>">
                            <button type="submit" class="ir-btn ir-btn-success">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                <span class="hidden md:inline">Aprobar</span>
                            </button>
                        </form>
                        <?php else: ?>
                        <form method="POST" onsubmit="return confirm('Revertir aprobacion?')" class="inline-flex">
                            <input type="hidden" name="action" value="reject">
                            <input type="hidden" name="extraction_id" value="<?= (int)$r['extraction_id'] ?>">
                            <button type="submit" class="ir-btn ir-btn-ghost" title="Revertir aprobacion">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg>
                                <span class="hidden md:inline">Revertir</span>
                            </button>
                        </form>
                        <?php endif; ?>
                        <?php endif; ?>

                        <div class="relative">
                            <button type="button" class="ir-btn ir-btn-ghost ir-menu-trigger" data-menu="<?= $rowId ?>-menu" aria-label="Mas opciones">
                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"/></svg>
                            </button>
                            <div id="<?= $rowId ?>-menu" class="ir-menu hidden">
                                <form method="POST">
                                    <input type="hidden" name="action" value="reprocess">
                                    <input type="hidden" name="upload_id" value="<?= $r['upload_id'] ?>">
                                    <button type="submit" class="ir-menu-item">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                        Reprocesar con IA
                                    </button>
                                </form>
                                <a href="<?= $thumbHref ?>" target="_blank" class="ir-menu-item">
                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                    Ver imagen original
                                </a>
                                <form method="POST" onsubmit="return confirm('Eliminar esta factura para siempre?')">
                                    <input type="hidden" name="action" value="delete_upload">
                                    <input type="hidden" name="upload_id" value="<?= $r['upload_id'] ?>">
                                    <button type="submit" class="ir-menu-item ir-menu-item-danger">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
                                        Eliminar
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Error state (no extraction) -->
                <?php if (!$r['extraction_id']): ?>
                <div class="ir-error-row">
                    <div class="flex items-center gap-3">
                        <svg class="w-4 h-4 text-amber-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 00-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z"/></svg>
                        <p class="text-xs">
                            <?php if ($r['status'] === 'error'): ?>
                            <span class="font-semibold text-red-600">Error de IA:</span>
                            <span class="text-slate-600"><?= htmlspecialchars($r['error_message'] ?? '') ?></span>
                            <?php else: ?>
                            <span class="text-slate-600">Esta factura aun no ha sido procesada por la IA.</span>
                            <?php endif; ?>
                        </p>
                        <form method="POST" class="ml-auto">
                            <input type="hidden" name="action" value="reprocess">
                            <input type="hidden" name="upload_id" value="<?= $r['upload_id'] ?>">
                            <button type="submit" class="ir-btn ir-btn-dark">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3"/></svg>
                                Procesar con IA
                            </button>
                        </form>
                    </div>
                </div>
                <?php else: ?>

                <!-- Editable body (collapsible) -->
                <div class="ir-body hidden" id="<?= $rowId ?>-body">
                    <form method="POST" class="ir-form">
                        <input type="hidden" name="action" value="update_extraction">
                        <input type="hidden" name="extraction_id" value="<?= (int)$r['extraction_id'] ?>">

                        <!-- Group: Identificacion -->
                        <div class="ir-group">
                            <p class="ir-group-title">Identificacion</p>
                            <div class="ir-grid">
                                <div class="ir-f ir-f-3">
                                    <label class="ir-label">Tipo</label>
                                    <select name="doc_type" class="ir-input">
                                        <option value="compra" <?= $r['doc_type']==='compra'?'selected':'' ?>>Compra · 606</option>
                                        <option value="venta"  <?= $r['doc_type']==='venta'?'selected':'' ?>>Venta · 607</option>
                                    </select>
                                </div>
                                <div class="ir-f ir-f-3">
                                    <label class="ir-label">Fecha documento</label>
                                    <input type="date" name="date_doc" value="<?= htmlspecialchars($r['date_doc'] ?: '') ?>" class="ir-input">
                                </div>
                                <div class="ir-f ir-f-3">
                                    <label class="ir-label">Fecha pago</label>
                                    <input type="date" name="date_payment" value="<?= htmlspecialchars($r['date_payment'] ?: '') ?>" class="ir-input">
                                </div>
                                <div class="ir-f ir-f-3">
                                    <label class="ir-label">Concepto</label>
                                    <input type="text" name="concept" value="<?= htmlspecialchars($r['concept'] ?? '') ?>" class="ir-input" placeholder="Combustible, comida...">
                                </div>
                            </div>
                        </div>

                        <!-- Group: Contraparte -->
                        <div class="ir-group">
                            <p class="ir-group-title">Contraparte y NCF</p>
                            <div class="ir-grid">
                                <div class="ir-f ir-f-6">
                                    <label class="ir-label">Nombre / Razon social</label>
                                    <input type="text" name="counterparty_name" value="<?= htmlspecialchars($r['counterparty_name'] ?? '') ?>" class="ir-input">
                                </div>
                                <div class="ir-f ir-f-3">
                                    <label class="ir-label">RNC / Cedula</label>
                                    <input type="text" name="rnc" value="<?= htmlspecialchars($r['rnc'] ?? '') ?>" class="ir-input font-mono">
                                </div>
                                <div class="ir-f ir-f-3">
                                    <label class="ir-label">Tipo NCF</label>
                                    <input type="text" name="ncf_type" value="<?= htmlspecialchars($r['ncf_type'] ?? '') ?>" class="ir-input font-mono" placeholder="B01, B02, E31...">
                                </div>
                                <div class="ir-f ir-f-6">
                                    <label class="ir-label">NCF</label>
                                    <input type="text" name="ncf" value="<?= htmlspecialchars($r['ncf'] ?? '') ?>" class="ir-input font-mono">
                                </div>
                                <div class="ir-f ir-f-6">
                                    <label class="ir-label">NCF modificado <span class="text-slate-400 font-normal normal-case">(notas de credito)</span></label>
                                    <input type="text" name="ncf_modified" value="<?= htmlspecialchars($r['ncf_modified'] ?? '') ?>" class="ir-input font-mono">
                                </div>
                            </div>
                        </div>

                        <!-- Group: Clasificacion -->
                        <div class="ir-group">
                            <p class="ir-group-title">Clasificacion fiscal DGII</p>
                            <div class="ir-grid">
                                <div class="ir-f ir-f-6">
                                    <label class="ir-label">Categoria gasto (606)</label>
                                    <select name="expense_category" class="ir-input">
                                        <option value="">—</option>
                                        <?php foreach ($expenseCategories as $code=>$label): ?>
                                        <option value="<?= $code ?>" <?= $r['expense_category']===$code?'selected':'' ?>><?= $code ?> · <?= htmlspecialchars($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="ir-f ir-f-6">
                                    <label class="ir-label">Tipo ingreso (607)</label>
                                    <select name="income_type" class="ir-input">
                                        <option value="">—</option>
                                        <?php foreach ($incomeTypes as $code=>$label): ?>
                                        <option value="<?= $code ?>" <?= $r['income_type']===$code?'selected':'' ?>><?= $code ?> · <?= htmlspecialchars($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="ir-f ir-f-3">
                                    <label class="ir-label">Tipo ID</label>
                                    <select name="identification_type" class="ir-input">
                                        <option value="">—</option>
                                        <?php foreach ($idTypes as $code=>$label): ?>
                                        <option value="<?= $code ?>" <?= $r['identification_type']===$code?'selected':'' ?>><?= $code ?> · <?= htmlspecialchars($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="ir-f ir-f-3"></div>
                                <div class="ir-f ir-f-6">
                                    <label class="ir-label">Forma de pago</label>
                                    <select name="payment_method" class="ir-input">
                                        <option value="">—</option>
                                        <?php foreach ($paymentMethods as $code=>$label): ?>
                                        <option value="<?= $code ?>" <?= $r['payment_method']===$code?'selected':'' ?>><?= $code ?> · <?= htmlspecialchars($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Group: Montos -->
                        <div class="ir-group">
                            <p class="ir-group-title">Montos</p>
                            <div class="ir-grid">
                                <div class="ir-f ir-f-2">
                                    <label class="ir-label">Subtotal</label>
                                    <input type="number" step="0.01" name="subtotal" value="<?= htmlspecialchars($r['subtotal']) ?>" class="ir-input text-right font-mono" data-sumtotal>
                                </div>
                                <div class="ir-f ir-f-2">
                                    <label class="ir-label">ITBIS</label>
                                    <input type="number" step="0.01" name="itbis" value="<?= htmlspecialchars($r['itbis']) ?>" class="ir-input text-right font-mono" data-sumtotal>
                                </div>
                                <div class="ir-f ir-f-2">
                                    <label class="ir-label">10% Ley</label>
                                    <input type="number" step="0.01" name="propina_legal" value="<?= htmlspecialchars($r['propina_legal']) ?>" class="ir-input text-right font-mono" data-sumtotal>
                                </div>
                                <div class="ir-f ir-f-2">
                                    <label class="ir-label">Transporte</label>
                                    <input type="number" step="0.01" name="transporte" value="<?= htmlspecialchars($r['transporte']) ?>" class="ir-input text-right font-mono" data-sumtotal>
                                </div>
                                <div class="ir-f ir-f-2">
                                    <label class="ir-label">Ret. ITBIS</label>
                                    <input type="number" step="0.01" name="itbis_retention" value="<?= htmlspecialchars($r['itbis_retention']) ?>" class="ir-input text-right font-mono">
                                </div>
                                <div class="ir-f ir-f-2">
                                    <label class="ir-label">Ret. ISR</label>
                                    <input type="number" step="0.01" name="isr_retention" value="<?= htmlspecialchars($r['isr_retention']) ?>" class="ir-input text-right font-mono">
                                </div>
                                <div class="ir-f ir-f-12">
                                    <label class="ir-label">Total final</label>
                                    <input type="number" step="0.01" name="total" value="<?= htmlspecialchars($r['total']) ?>" class="ir-input ir-input-total text-right font-mono">
                                </div>
                            </div>
                        </div>

                        <div class="ir-form-footer">
                            <button type="button" class="ir-btn ir-btn-ghost" data-toggle-row="<?= $rowId ?>">Cerrar</button>
                            <button type="submit" class="ir-btn ir-btn-dark">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                Guardar correcciones
                            </button>
                        </div>
                    </form>
                </div>
                <?php endif; ?>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</form>

<style>
    /* === Invoice Review compact list === */
    .ir-row { transition: background .15s ease; }
    .ir-row:hover { background: #FAFAFA; }
    .ir-head { display: flex; align-items: center; gap: 14px; padding: 14px 20px; }
    .ir-thumb { display: block; width: 56px; height: 56px; border-radius: 14px; overflow: hidden; background: #F4F4F5; border: 1px solid #E5E7EB; transition: transform .2s ease; }
    .ir-thumb:hover { transform: scale(1.06); }
    .ir-thumb img { width: 100%; height: 100%; object-fit: cover; }
    .ir-thumb-fallback { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; color: #94A3B8; }
    .ir-pill { display: inline-flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 700; padding: 3px 9px; border-radius: 999px; white-space: nowrap; letter-spacing: 0.02em; }
    .ir-pill::before { content: ''; width: 5px; height: 5px; border-radius: 999px; background: currentColor; }
    .ir-pill-emerald { color: #15803D; background: #DCFCE7; }
    .ir-pill-amber   { color: #B45309; background: #FEF3C7; }
    .ir-pill-red     { color: #DC2626; background: #FEE2E2; }
    .ir-pill-blue    { color: #1D4ED8; background: #DBEAFE; }
    .ir-pill-indigo  { color: #4F46E5; background: #E0E7FF; }
    .ir-pill-slate   { color: #475569; background: #F1F5F9; }
    .ir-conf { display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 700; padding: 3px 8px; border-radius: 999px; background: #F4F4F5; }
    .ir-conf-bar { width: 38px; height: 4px; border-radius: 999px; background: #E5E7EB; overflow: hidden; display: inline-block; }
    .ir-conf-bar span { display: block; height: 100%; border-radius: 999px; }
    .ir-conf-emerald { color: #15803D; background: #ECFDF5; }
    .ir-conf-emerald .ir-conf-bar span { background: #10B981; }
    .ir-conf-amber   { color: #B45309; background: #FFFBEB; }
    .ir-conf-amber   .ir-conf-bar span { background: #F59E0B; }
    .ir-conf-red     { color: #DC2626; background: #FEF2F2; }
    .ir-conf-red     .ir-conf-bar span { background: #EF4444; }
    .ir-totals { display: flex; gap: 18px; align-items: center; }
    .ir-tot-label { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #94A3B8; }
    .ir-tot-val   { font-size: 14px; font-weight: 800; color: #0F172A; margin-top: 2px; font-variant-numeric: tabular-nums; }
    .ir-tot-itbis { color: #475569; font-size: 12px; font-weight: 600; }
    .ir-btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border-radius: 10px; font-size: 12px; font-weight: 700; transition: all .15s ease; white-space: nowrap; }
    .ir-btn-ghost { background: #F4F4F5; color: #475569; }
    .ir-btn-ghost:hover { background: #E5E7EB; color: #0F172A; }
    .ir-btn-dark { background: #0F172A; color: #fff; }
    .ir-btn-dark:hover { background: #1E293B; }
    .ir-btn-success { background: #10B981; color: #fff; }
    .ir-btn-success:hover { background: #059669; }
    .ir-checkbox { display: inline-flex; align-items: center; cursor: pointer; padding: 2px; }
    .ir-checkbox input { position: absolute; opacity: 0; pointer-events: none; }
    .ir-checkbox span { width: 18px; height: 18px; border-radius: 6px; border: 1.5px solid #CBD5E1; display: inline-flex; align-items: center; justify-content: center; transition: all .15s ease; }
    .ir-checkbox input:checked + span { background: #0F172A; border-color: #0F172A; }
    .ir-checkbox input:checked + span::after { content: ''; width: 6px; height: 9px; border-right: 2px solid #fff; border-bottom: 2px solid #fff; transform: rotate(45deg) translateY(-1px); }
    .ir-menu { position: fixed; min-width: 220px; background: #fff; border: 1px solid #E5E7EB; border-radius: 14px; box-shadow: 0 18px 50px rgba(15,23,42,0.18); z-index: 9000; padding: 6px; }
    .ir-menu-item { display: flex; align-items: center; gap: 8px; width: 100%; padding: 8px 10px; border-radius: 10px; font-size: 12px; font-weight: 600; color: #475569; text-align: left; transition: all .12s ease; }
    .ir-menu-item:hover { background: #F4F4F5; color: #0F172A; }
    .ir-menu-item-danger { color: #DC2626; }
    .ir-menu-item-danger:hover { background: #FEF2F2; color: #B91C1C; }

    .ir-body { padding: 0 20px 18px 20px; background: linear-gradient(to bottom, #FAFAFA, #fff); }
    .ir-form { display: grid; gap: 14px; }
    .ir-group { background: #fff; border: 1px solid #EEF0F2; border-radius: 16px; padding: 14px 16px; }
    .ir-group-title { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; color: #64748B; margin-bottom: 10px; padding-bottom: 8px; border-bottom: 1px solid #F1F5F9; }
    .ir-grid { display: grid; grid-template-columns: repeat(12, minmax(0, 1fr)); gap: 10px; }
    .ir-f-2 { grid-column: span 6; }
    .ir-f-3 { grid-column: span 6; }
    .ir-f-6 { grid-column: span 12; }
    .ir-f-12 { grid-column: span 12; }
    @media (min-width: 640px) {
        .ir-f-2 { grid-column: span 4; }
        .ir-f-3 { grid-column: span 6; }
        .ir-f-6 { grid-column: span 6; }
    }
    @media (min-width: 1024px) {
        .ir-f-2 { grid-column: span 2; }
        .ir-f-3 { grid-column: span 3; }
        .ir-f-6 { grid-column: span 6; }
    }
    .ir-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #64748B; margin-bottom: 5px; display: block; }
    .ir-input { width: 100%; border: 1.5px solid #E5E7EB; border-radius: 10px; padding: 9px 11px; font-size: 13px; color: #0F172A; background: #fff; transition: all .12s ease; font-variant-numeric: tabular-nums; }
    .ir-input:focus { outline: none; border-color: #0F172A; box-shadow: 0 0 0 3px rgba(15,23,42,0.05); }
    .ir-input-total { font-size: 16px; font-weight: 800; background: #F4F4F5; border-color: #CBD5E1; }
    .ir-form-footer { display: flex; gap: 8px; justify-content: flex-end; padding-top: 4px; }
    .ir-error-row { padding: 0 20px 16px 20px; }
    .ir-error-row > div { background: #FFFBEB; border: 1px solid #FDE68A; border-radius: 14px; padding: 12px 14px; }

    @media (max-width: 768px) {
        .ir-head { flex-wrap: wrap; }
        .ir-totals { width: 100%; justify-content: flex-end; }
    }
</style>

<script>
// Bulk selection
(function() {
    const form = document.getElementById('bulkForm');
    const btn = document.getElementById('bulkBtn');
    const counter = document.getElementById('selCount');

    function refresh() {
        const checks = form.querySelectorAll('.bulk-check:checked');
        counter.textContent = checks.length;
        if (checks.length > 0) {
            btn.disabled = false;
            btn.classList.remove('opacity-50','cursor-not-allowed');
        } else {
            btn.disabled = true;
            btn.classList.add('opacity-50','cursor-not-allowed');
        }
    }
    form.addEventListener('change', refresh);
    refresh();
})();

// Row expansion + dropdown menus
(function() {
    // Toggle expandible body
    document.querySelectorAll('[data-toggle-row]').forEach(btn => {
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const id = btn.dataset.toggleRow;
            const body = document.getElementById(id + '-body');
            if (!body) return;
            const isOpen = !body.classList.contains('hidden');
            // Close other open bodies
            document.querySelectorAll('.ir-body').forEach(b => {
                if (b.id !== id + '-body') b.classList.add('hidden');
            });
            body.classList.toggle('hidden', isOpen);
            if (!isOpen) {
                body.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        });
    });

    // Dropdown menus (position: fixed para escapar overflow del card)
    function positionMenu(menu, trigger) {
        const r = trigger.getBoundingClientRect();
        const menuW = menu.offsetWidth || 220;
        const menuH = menu.offsetHeight || 160;
        const vw = window.innerWidth;
        const vh = window.innerHeight;
        // Por defecto debajo del boton, alineado a su derecha
        let top = r.bottom + 6;
        let left = r.right - menuW;
        // Si se sale por abajo, abrir hacia arriba
        if (top + menuH > vh - 8) top = r.top - menuH - 6;
        // Si se sale por la izquierda, alinear con el boton
        if (left < 8) left = Math.max(8, r.left);
        // Si se sale por la derecha
        if (left + menuW > vw - 8) left = vw - menuW - 8;
        menu.style.top = top + 'px';
        menu.style.left = left + 'px';
    }
    function closeAllMenus() {
        document.querySelectorAll('.ir-menu').forEach(m => m.classList.add('hidden'));
    }
    document.querySelectorAll('.ir-menu-trigger').forEach(trigger => {
        trigger.addEventListener('click', (e) => {
            e.stopPropagation();
            const menuId = trigger.dataset.menu;
            const menu = document.getElementById(menuId);
            const wasOpen = !menu.classList.contains('hidden');
            closeAllMenus();
            if (!wasOpen) {
                menu.classList.remove('hidden');
                positionMenu(menu, trigger);
            }
        });
    });
    document.addEventListener('click', closeAllMenus);
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAllMenus(); });
    window.addEventListener('scroll', closeAllMenus, true);
    window.addEventListener('resize', closeAllMenus);
    document.querySelectorAll('.ir-menu').forEach(m => {
        m.addEventListener('click', (e) => e.stopPropagation());
    });
})();

// Auto-recalc Total when components change
(function() {
    document.querySelectorAll('.ir-form').forEach(form => {
        const inputs = form.querySelectorAll('[data-sumtotal]');
        const totalInput = form.querySelector('input[name="total"]');
        if (!totalInput) return;
        inputs.forEach(inp => {
            inp.addEventListener('input', () => {
                let sum = 0;
                inputs.forEach(i => sum += parseFloat(i.value) || 0);
                totalInput.value = sum.toFixed(2);
            });
        });
    });
})();

// Admin upload zone
(function() {
    const input  = document.getElementById('adminFileInput');
    const drop   = document.getElementById('adminDropZone');
    const sum    = document.getElementById('adminFileSummary');
    const form   = document.getElementById('adminUploadForm');
    const btn    = document.getElementById('adminUploadBtn');
    if (!input || !drop || !form) return;

    function refresh() {
        if (!input.files || input.files.length === 0) { sum.classList.add('hidden'); return; }
        let kb = 0;
        for (const f of input.files) kb += f.size;
        sum.textContent = input.files.length + ' archivo(s) · ' + Math.round(kb/1024) + ' KB';
        sum.classList.remove('hidden');
    }
    input.addEventListener('change', refresh);
    ['dragenter','dragover'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.add('border-blue-400','bg-blue-50/40'); }));
    ['dragleave','drop'].forEach(ev => drop.addEventListener(ev, e => { e.preventDefault(); drop.classList.remove('border-blue-400','bg-blue-50/40'); }));
    drop.addEventListener('drop', e => { if (e.dataTransfer && e.dataTransfer.files) { input.files = e.dataTransfer.files; refresh(); } });
    form.addEventListener('submit', () => {
        if (input.files && input.files.length > 0) {
            btn.disabled = true;
            btn.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity=".25"/><path d="M22 12a10 10 0 01-10 10" stroke="currentColor" stroke-width="3" fill="none"/></svg> Procesando con IA...';
        }
    });
})();
</script>

<?php include 'components/layout_end.php'; ?>
