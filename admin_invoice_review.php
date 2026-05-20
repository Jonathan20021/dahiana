<?php
require_once 'config.php';
requireAuth('admin');

$success = $error = null;

$period = $_GET['period'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) $period = date('Y-m');
$months = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
$periodLabel = $months[(int)substr($period, 5, 2) - 1] . ' ' . substr($period, 0, 4);
$prevPeriod = date('Y-m', strtotime($period . '-01 -1 month'));
$nextPeriod = date('Y-m', strtotime($period . '-01 +1 month'));

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
                  ncf=?, ncf_modified=?, ncf_type=?, concept=?, expense_category=?, payment_method=?,
                  subtotal=?, itbis=?, propina_legal=?, transporte=?, isr_retention=?, itbis_retention=?, total=?
                WHERE id=?
            ");
            $stmt->execute([
                $fields['doc_type'], $fields['period'], $fields['date_doc'], $fields['date_payment'],
                $fields['rnc'], $fields['counterparty_name'],
                $fields['ncf'], $fields['ncf_modified'], $fields['ncf_type'], $fields['concept'],
                $fields['expense_category'], $fields['payment_method'],
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
$where = "1=1";
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

// Period filter: prefer extraction period; fall back to upload period or created_at
$where .= " AND (
    e.period = ?
    OR (e.period IS NULL AND u.period = ?)
    OR (e.period IS NULL AND u.period IS NULL AND DATE_FORMAT(u.created_at, '%Y-%m') = ?)
)";
$params[] = $period; $params[] = $period; $params[] = $period;

$sql = "
    SELECT u.id AS upload_id, u.client_id, u.filename, u.original_name, u.mime_type, u.status, u.error_message, u.created_at,
           c.name AS client_name, c.business_name, c.rnc AS client_rnc,
           e.id AS extraction_id, e.doc_type, e.date_doc, e.date_payment, e.rnc, e.counterparty_name,
           e.ncf, e.ncf_modified, e.ncf_type, e.concept, e.expense_category, e.payment_method,
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

$expenseCategories = aiExpenseCategories();
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
<div class="surface-card p-5 mb-4">
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

<!-- Filters -->
<div class="surface-card p-3 mb-4 flex flex-col lg:flex-row gap-3 items-stretch lg:items-center">
    <div class="flex items-center gap-2">
        <a href="?period=<?= $prevPeriod ?>&status=<?= urlencode($filterStatus) ?>&client_id=<?= $filterClient ?>" class="icon-btn">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <span class="px-3 py-2 rounded-xl bg-stone-50 text-sm font-bold text-slate-900 min-w-[120px] text-center"><?= $periodLabel ?></span>
        <a href="?period=<?= $nextPeriod ?>&status=<?= urlencode($filterStatus) ?>&client_id=<?= $filterClient ?>" class="icon-btn">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        </a>
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
        <div class="py-12 text-center text-sm text-slate-400">No hay facturas para este filtro.</div>
        <?php else: ?>
        <div class="divide-y divide-stone-100">
            <?php foreach ($rows as $r):
                $thumbHref = 'uploads/invoices/' . htmlspecialchars($r['filename']);
                $isApproved = !empty($r['approved']);
                $statusBadge = match($r['status']) {
                    'uploaded'   => '<span class="badge-dot badge-slate">En cola</span>',
                    'processing' => '<span class="badge-dot badge-blue">Procesando</span>',
                    'extracted'  => '<span class="badge-dot badge-amber">Por aprobar</span>',
                    'approved'   => '<span class="badge-dot badge-green">Aprobada</span>',
                    'rejected'   => '<span class="badge-dot badge-slate">Rechazada</span>',
                    'error'      => '<span class="badge-dot badge-red">Error</span>',
                    default      => '<span class="badge-dot badge-slate">' . htmlspecialchars($r['status']) . '</span>',
                };
                $confColor = ($r['confidence'] ?? 0) >= 0.85 ? 'text-emerald-600' : (($r['confidence'] ?? 0) >= 0.6 ? 'text-amber-600' : 'text-red-600');
            ?>
            <div class="p-5">
                <div class="flex flex-col lg:flex-row gap-5">
                    <!-- Thumbnail -->
                    <div class="lg:w-56 shrink-0">
                        <a href="<?= $thumbHref ?>" target="_blank" class="block rounded-2xl overflow-hidden bg-stone-100 border border-stone-200 aspect-[3/4]">
                            <?php if (strpos($r['mime_type'], 'image/') === 0): ?>
                            <img src="<?= $thumbHref ?>" alt="" class="w-full h-full object-cover">
                            <?php else: ?>
                            <div class="w-full h-full flex items-center justify-center text-slate-400">
                                <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            </div>
                            <?php endif; ?>
                        </a>
                        <p class="mt-2 text-[11px] text-slate-500 truncate"><?= htmlspecialchars($r['original_name']) ?></p>
                        <p class="text-[10px] text-slate-400"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></p>
                    </div>

                    <!-- Form -->
                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-center gap-2 mb-3">
                            <?php if (!$isApproved && $r['extraction_id']): ?>
                            <label class="flex items-center gap-2 px-2 py-1 rounded-lg bg-stone-50 cursor-pointer">
                                <input type="checkbox" name="ids[]" value="<?= (int)$r['extraction_id'] ?>" class="bulk-check">
                                <span class="text-[11px] text-slate-600 font-semibold">Aprobar</span>
                            </label>
                            <?php endif; ?>
                            <span class="text-sm font-bold text-slate-900"><?= htmlspecialchars($r['business_name'] ?: $r['client_name']) ?></span>
                            <?= $statusBadge ?>
                            <?php if (!is_null($r['confidence'])): ?>
                            <span class="text-[11px] font-semibold <?= $confColor ?>">Confianza IA: <?= round(((float)$r['confidence']) * 100) ?>%</span>
                            <?php endif; ?>
                            <?php if ($isApproved): ?>
                            <span class="badge-dot badge-green">Insertada en formulario</span>
                            <?php endif; ?>
                        </div>

                        <?php if (!$r['extraction_id']): ?>
                            <div class="rounded-xl bg-stone-50 p-4 text-xs text-slate-600">
                                <?php if ($r['status'] === 'error'): ?>
                                Error IA: <span class="text-red-600 font-semibold"><?= htmlspecialchars($r['error_message']) ?></span>
                                <?php else: ?>
                                Esta factura aun no ha sido procesada por IA.
                                <?php endif; ?>
                                <form method="POST" class="inline ml-2">
                                    <input type="hidden" name="action" value="reprocess">
                                    <input type="hidden" name="upload_id" value="<?= $r['upload_id'] ?>">
                                    <button type="submit" class="btn-dark !text-xs !py-1.5 !px-3 ml-2">Procesar con IA</button>
                                </form>
                            </div>
                        <?php else: ?>
                            <form method="POST" class="grid grid-cols-2 sm:grid-cols-6 gap-3">
                                <input type="hidden" name="action" value="update_extraction">
                                <input type="hidden" name="extraction_id" value="<?= (int)$r['extraction_id'] ?>">

                                <div class="col-span-2 sm:col-span-2">
                                    <label class="field-label">Tipo</label>
                                    <select name="doc_type" class="field !text-xs !py-2">
                                        <option value="compra" <?= $r['doc_type']==='compra'?'selected':'' ?>>Compra · 606</option>
                                        <option value="venta"  <?= $r['doc_type']==='venta'?'selected':'' ?>>Venta · 607</option>
                                    </select>
                                </div>
                                <div class="col-span-2 sm:col-span-2">
                                    <label class="field-label">Fecha doc</label>
                                    <input type="date" name="date_doc" value="<?= htmlspecialchars($r['date_doc'] ?: '') ?>" class="field !text-xs !py-2">
                                </div>
                                <div class="col-span-2 sm:col-span-2">
                                    <label class="field-label">Fecha pago</label>
                                    <input type="date" name="date_payment" value="<?= htmlspecialchars($r['date_payment'] ?: '') ?>" class="field !text-xs !py-2">
                                </div>

                                <div class="col-span-2 sm:col-span-3">
                                    <label class="field-label">Contraparte</label>
                                    <input type="text" name="counterparty_name" value="<?= htmlspecialchars($r['counterparty_name'] ?? '') ?>" class="field !text-xs !py-2">
                                </div>
                                <div class="col-span-1 sm:col-span-2">
                                    <label class="field-label">RNC / Cedula</label>
                                    <input type="text" name="rnc" value="<?= htmlspecialchars($r['rnc'] ?? '') ?>" class="field !text-xs !py-2 font-mono">
                                </div>
                                <div class="col-span-1 sm:col-span-1">
                                    <label class="field-label">Tipo NCF</label>
                                    <input type="text" name="ncf_type" value="<?= htmlspecialchars($r['ncf_type'] ?? '') ?>" class="field !text-xs !py-2 font-mono">
                                </div>

                                <div class="col-span-2 sm:col-span-2">
                                    <label class="field-label">NCF</label>
                                    <input type="text" name="ncf" value="<?= htmlspecialchars($r['ncf'] ?? '') ?>" class="field !text-xs !py-2 font-mono">
                                </div>
                                <div class="col-span-2 sm:col-span-2">
                                    <label class="field-label">NCF modificado</label>
                                    <input type="text" name="ncf_modified" value="<?= htmlspecialchars($r['ncf_modified'] ?? '') ?>" class="field !text-xs !py-2 font-mono">
                                </div>
                                <div class="col-span-2 sm:col-span-2">
                                    <label class="field-label">Concepto</label>
                                    <input type="text" name="concept" value="<?= htmlspecialchars($r['concept'] ?? '') ?>" class="field !text-xs !py-2">
                                </div>

                                <div class="col-span-2 sm:col-span-3">
                                    <label class="field-label">Categoria gasto (606)</label>
                                    <select name="expense_category" class="field !text-xs !py-2">
                                        <option value="">—</option>
                                        <?php foreach ($expenseCategories as $code=>$label): ?>
                                        <option value="<?= $code ?>" <?= $r['expense_category']===$code?'selected':'' ?>><?= $code ?> · <?= htmlspecialchars($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-span-2 sm:col-span-3">
                                    <label class="field-label">Forma de pago</label>
                                    <select name="payment_method" class="field !text-xs !py-2">
                                        <option value="">—</option>
                                        <?php foreach ($paymentMethods as $code=>$label): ?>
                                        <option value="<?= $code ?>" <?= $r['payment_method']===$code?'selected':'' ?>><?= $code ?> · <?= htmlspecialchars($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div>
                                    <label class="field-label">Subtotal</label>
                                    <input type="number" step="0.01" name="subtotal" value="<?= htmlspecialchars($r['subtotal']) ?>" class="field !text-xs !py-2 text-right">
                                </div>
                                <div>
                                    <label class="field-label">ITBIS</label>
                                    <input type="number" step="0.01" name="itbis" value="<?= htmlspecialchars($r['itbis']) ?>" class="field !text-xs !py-2 text-right">
                                </div>
                                <div>
                                    <label class="field-label">10% Ley</label>
                                    <input type="number" step="0.01" name="propina_legal" value="<?= htmlspecialchars($r['propina_legal']) ?>" class="field !text-xs !py-2 text-right">
                                </div>
                                <div>
                                    <label class="field-label">Transporte</label>
                                    <input type="number" step="0.01" name="transporte" value="<?= htmlspecialchars($r['transporte']) ?>" class="field !text-xs !py-2 text-right">
                                </div>
                                <div>
                                    <label class="field-label">Ret. ITBIS</label>
                                    <input type="number" step="0.01" name="itbis_retention" value="<?= htmlspecialchars($r['itbis_retention']) ?>" class="field !text-xs !py-2 text-right">
                                </div>
                                <div>
                                    <label class="field-label">Ret. ISR</label>
                                    <input type="number" step="0.01" name="isr_retention" value="<?= htmlspecialchars($r['isr_retention']) ?>" class="field !text-xs !py-2 text-right">
                                </div>
                                <div class="col-span-2 sm:col-span-6">
                                    <label class="field-label">Total final</label>
                                    <input type="number" step="0.01" name="total" value="<?= htmlspecialchars($r['total']) ?>" class="field !text-sm font-bold !py-2 text-right">
                                </div>

                                <div class="col-span-2 sm:col-span-6 flex flex-wrap gap-2 pt-2">
                                    <button type="submit" class="btn-soft !text-xs">Guardar correcciones</button>
                                </div>
                            </form>

                            <div class="mt-3 flex flex-wrap gap-2">
                                <?php if (!$isApproved): ?>
                                <form method="POST" onsubmit="return confirm('Aprobar y agregar al formulario 606/607?')">
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="extraction_id" value="<?= (int)$r['extraction_id'] ?>">
                                    <button type="submit" class="btn-dark !text-xs bg-emerald-600 hover:bg-emerald-700">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                        Aprobar y agregar
                                    </button>
                                </form>
                                <?php else: ?>
                                <form method="POST" onsubmit="return confirm('Revertir esta aprobacion?')">
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="extraction_id" value="<?= (int)$r['extraction_id'] ?>">
                                    <button type="submit" class="btn-soft !text-xs">
                                        Revertir aprobacion
                                    </button>
                                </form>
                                <?php endif; ?>
                                <form method="POST">
                                    <input type="hidden" name="action" value="reprocess">
                                    <input type="hidden" name="upload_id" value="<?= $r['upload_id'] ?>">
                                    <button type="submit" class="btn-soft !text-xs">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                        Reprocesar IA
                                    </button>
                                </form>
                                <form method="POST" onsubmit="return confirm('Eliminar completamente esta factura?')" class="ml-auto">
                                    <input type="hidden" name="action" value="delete_upload">
                                    <input type="hidden" name="upload_id" value="<?= $r['upload_id'] ?>">
                                    <button type="submit" class="text-red-500 hover:text-red-700 text-xs font-semibold">Eliminar</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</form>

<script>
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
