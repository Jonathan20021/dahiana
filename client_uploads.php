<?php
require_once 'config.php';
requireAuth('client');

$client_id = (int)$_SESSION['user_id'];
$success = $error = null;

$period = $_GET['period'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) $period = date('Y-m');

$months = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
$periodLabel = $months[(int)substr($period, 5, 2) - 1] . ' ' . substr($period, 0, 4);
$prevPeriod = date('Y-m', strtotime($period . '-01 -1 month'));
$nextPeriod = date('Y-m', strtotime($period . '-01 +1 month'));

// Upload via PWA Share Target -> tratarlo como upload normal
$isShareTarget = ($_GET['via'] ?? '') === 'share';
if ($isShareTarget && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['files']['name'][0])) {
    $_POST['action'] = 'upload';
    if (empty($_POST['doc_type'])) $_POST['doc_type'] = 'auto';
}

// Upload handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
    $docTypeHint = $_POST['doc_type'] ?? 'auto';
    if (!in_array($docTypeHint, ['auto','compra','venta'], true)) $docTypeHint = 'auto';

    if (empty($_FILES['files']['name'][0])) {
        $error = 'Selecciona al menos una foto de factura.';
    } else {
        $maxMb = (int)getSetting('openai_max_size_mb', '12');
        $maxBytes = max(1, $maxMb) * 1024 * 1024;
        $autoProcess = getSetting('openai_auto_process', '1') === '1' && getSetting('openai_enabled', '1') === '1';

        $uploadedOk = 0;
        $failed = [];
        $files = $_FILES['files'];
        $n = count($files['name']);
        $dir = aiUploadsDir();

        for ($i = 0; $i < $n; $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) {
                $failed[] = $files['name'][$i] . ' (error de subida)';
                continue;
            }
            $size = (int)$files['size'][$i];
            $mime = (string)$files['type'][$i];
            $orig = (string)$files['name'][$i];
            $tmp  = (string)$files['tmp_name'][$i];

            if (!in_array($mime, aiAcceptedMimes(), true)) {
                // Try to fix via getimagesize for images that arrive as application/octet-stream
                $info = @getimagesize($tmp);
                if ($info && !empty($info['mime'])) $mime = $info['mime'];
            }
            if (!in_array($mime, aiAcceptedMimes(), true) || !aiIsImageMime($mime)) {
                $failed[] = $orig . ' (formato no permitido, sube JPG/PNG/WEBP)';
                continue;
            }
            if ($size > $maxBytes) {
                $failed[] = $orig . " (excede {$maxMb} MB)";
                continue;
            }

            // Pre-check duplicate via in-memory sha256
            $tmpSha = @hash_file('sha256', $tmp);
            if ($tmpSha && aiFindDuplicateUpload($client_id, $tmpSha) > 0) {
                $failed[] = $orig . ' (duplicada, ya la habias subido)';
                continue;
            }

            $ext = pathinfo($orig, PATHINFO_EXTENSION) ?: 'jpg';
            $filename = 'inv_' . $client_id . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . strtolower($ext);
            $dest = $dir . '/' . $filename;
            if (!move_uploaded_file($tmp, $dest)) {
                $failed[] = $orig . ' (no se pudo guardar)';
                continue;
            }

            $sha = hash_file('sha256', $dest);

            $uploadId = aiCreateUploadRecord($client_id, [
                'filename'      => $filename,
                'original_name' => $orig,
                'mime_type'     => $mime,
                'file_size'     => $size,
                'sha256'        => $sha,
            ], $docTypeHint, $client_id);

            if ($autoProcess) {
                $res = aiProcessUpload($uploadId);
                if (!$res['ok']) {
                    $failed[] = $orig . ' (' . $res['error'] . ')';
                }
            }

            $uploadedOk++;
        }
        if ($uploadedOk > 0) {
            logClientActivity($client_id, 'invoice_upload', "{$uploadedOk} factura(s) subida(s) desde el portal");
        }

        if ($uploadedOk > 0 && empty($failed)) {
            $success = "{$uploadedOk} factura(s) subida(s) y procesada(s) con IA.";
        } elseif ($uploadedOk > 0) {
            $success = "{$uploadedOk} subida(s). Con errores: " . implode(', ', $failed);
        } else {
            $error = 'No se pudo subir: ' . implode(', ', $failed);
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reprocess') {
    $uid = (int)($_POST['upload_id'] ?? 0);
    if ($uid) {
        $own = $pdo->prepare("SELECT id FROM invoice_uploads WHERE id=? AND client_id=?");
        $own->execute([$uid, $client_id]);
        if ($own->fetchColumn()) {
            $res = aiProcessUpload($uid);
            $success = $res['ok'] ? 'Factura reprocesada.' : ('Error al reprocesar: ' . ($res['error'] ?? ''));
            if (!$res['ok']) { $error = $success; $success = null; }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_upload') {
    $uid = (int)($_POST['upload_id'] ?? 0);
    if ($uid) {
        $u = $pdo->prepare("SELECT filename, status FROM invoice_uploads WHERE id=? AND client_id=?");
        $u->execute([$uid, $client_id]);
        $row = $u->fetch();
        if ($row && $row['status'] !== 'approved') {
            @unlink(aiUploadsDir() . '/' . $row['filename']);
            $pdo->prepare("DELETE FROM invoice_extractions WHERE upload_id=?")->execute([$uid]);
            $pdo->prepare("DELETE FROM invoice_uploads WHERE id=?")->execute([$uid]);
            $success = 'Factura eliminada.';
        } else {
            $error = 'Esa factura ya fue aprobada por el equipo, no se puede eliminar.';
        }
    }
}

// Fetch all uploads for this client, ordered by date
$stmt = $pdo->prepare("
    SELECT u.*,
           e.id AS extraction_id, e.doc_type AS ai_doc_type, e.date_doc, e.rnc, e.counterparty_name,
           e.ncf, e.total, e.itbis, e.subtotal, e.confidence, e.period AS ai_period, e.approved
    FROM invoice_uploads u
    LEFT JOIN invoice_extractions e ON e.upload_id = u.id
    WHERE u.client_id = ?
    ORDER BY u.created_at DESC
    LIMIT 200
");
$stmt->execute([$client_id]);
$uploads = $stmt->fetchAll();

// Period summary
$summary = $pdo->prepare("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN u.status='approved' THEN 1 ELSE 0 END) AS approved,
        SUM(CASE WHEN u.status='extracted' THEN 1 ELSE 0 END) AS extracted,
        SUM(CASE WHEN u.status IN ('uploaded','processing') THEN 1 ELSE 0 END) AS pending,
        SUM(CASE WHEN u.status='error' THEN 1 ELSE 0 END) AS errors,
        COALESCE(SUM(CASE WHEN e.doc_type='compra' THEN e.itbis ELSE 0 END),0) AS itbis_compras,
        COALESCE(SUM(CASE WHEN e.doc_type='venta' THEN e.itbis ELSE 0 END),0) AS itbis_ventas,
        COALESCE(SUM(CASE WHEN e.doc_type='compra' THEN e.total ELSE 0 END),0) AS total_compras,
        COALESCE(SUM(CASE WHEN e.doc_type='venta'  THEN e.total ELSE 0 END),0) AS total_ventas
    FROM invoice_uploads u
    LEFT JOIN invoice_extractions e ON e.upload_id = u.id
    WHERE u.client_id = ?
      AND (e.period = ? OR (u.period = ? AND e.id IS NULL))
");
$summary->execute([$client_id, $period, $period]);
$s = $summary->fetch() ?: ['total'=>0,'approved'=>0,'extracted'=>0,'pending'=>0,'errors'=>0,'itbis_compras'=>0,'itbis_ventas'=>0,'total_compras'=>0,'total_ventas'=>0];

$it1Balance = (float)$s['itbis_ventas'] - (float)$s['itbis_compras'];

$page_title = 'Mis facturas';
$page_subtitle = 'Sube tus facturas, nuestra IA arma tu 606, 607 e IT-1 automaticamente.';
$main_max = 'max-w-6xl';
include 'components/layout_start.php';
?>

<?php if ($success): ?>
<div class="cu-flash cu-flash-success animate-fadeIn">
    <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
    <span><?= htmlspecialchars($success) ?></span>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="cu-flash cu-flash-error animate-fadeIn">
    <svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75c0 4.97-4.03 9-9 9s-9-4.03-9-9 4.03-9 9-9 9 4.03 9 9z"/></svg>
    <span><?= htmlspecialchars($error) ?></span>
</div>
<?php endif; ?>

<!-- Period selector + summary -->
<div class="cu-period-bar">
    <div class="cu-period-nav">
        <a href="?period=<?= $prevPeriod ?>" class="cu-nav-btn" title="Mes anterior">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <div class="cu-period-label">
            <span class="cu-period-month"><?= $periodLabel ?></span>
            <span class="cu-period-sub">Periodo fiscal</span>
        </div>
        <a href="?period=<?= $nextPeriod ?>" class="cu-nav-btn" title="Mes siguiente">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        </a>
    </div>
    <div class="cu-stats">
        <div class="cu-stat">
            <p class="cu-stat-label">Compras</p>
            <p class="cu-stat-val">RD$ <?= number_format((float)$s['total_compras'], 0) ?></p>
        </div>
        <div class="cu-stat">
            <p class="cu-stat-label">Ventas</p>
            <p class="cu-stat-val">RD$ <?= number_format((float)$s['total_ventas'], 0) ?></p>
        </div>
        <div class="cu-stat">
            <p class="cu-stat-label">ITBIS pagado</p>
            <p class="cu-stat-val cu-stat-val-blue">RD$ <?= number_format((float)$s['itbis_compras'], 0) ?></p>
        </div>
        <div class="cu-stat <?= $it1Balance > 0 ? 'cu-stat-red' : 'cu-stat-green' ?>">
            <p class="cu-stat-label">IT-1 <?= $it1Balance > 0 ? 'a pagar' : 'saldo a favor' ?></p>
            <p class="cu-stat-val">RD$ <?= number_format(abs($it1Balance), 0) ?></p>
        </div>
    </div>
</div>

<!-- Upload zone (hero) -->
<div class="cu-upload-hero">
    <div class="cu-upload-info">
        <div class="cu-upload-icon">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
        </div>
        <div>
            <h3 class="cu-upload-title">Sube tus facturas con IA</h3>
            <p class="cu-upload-desc">Toma una foto a tu factura o suelta el archivo aqui. La IA extrae RNC, NCF, ITBIS y total automaticamente.</p>
            <div class="cu-upload-pills">
                <span class="cu-mini-pill">JPG · PNG · WEBP · HEIC</span>
                <span class="cu-mini-pill">Multiple a la vez</span>
                <span class="cu-mini-pill">Hasta <?= htmlspecialchars(getSetting('openai_max_size_mb', '12')) ?>MB c/u</span>
            </div>
        </div>
    </div>

    <form method="POST" enctype="multipart/form-data" id="uploadForm" class="cu-upload-form">
        <input type="hidden" name="action" value="upload">

        <label class="cu-dropzone" id="dropZone">
            <input type="file" name="files[]" id="fileInput" accept="image/*" multiple class="hidden">
            <div class="cu-dropzone-icon">
                <svg class="w-8 h-8" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
            </div>
            <p class="cu-dropzone-title">Arrastra aqui tus facturas</p>
            <p class="cu-dropzone-sub">o haz click para seleccionar desde tu dispositivo</p>
            <p id="fileSummary" class="cu-dropzone-summary hidden"></p>
        </label>

        <div class="cu-upload-controls">
            <select name="doc_type" class="cu-select">
                <option value="auto">Auto (la IA decide)</option>
                <option value="compra">Compra (para el 606)</option>
                <option value="venta">Venta (para el 607)</option>
            </select>
            <button type="submit" id="uploadBtn" class="cu-submit-btn">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                Procesar con IA
            </button>
        </div>
    </form>
</div>

<!-- Uploads list -->
<div class="cu-list">
    <div class="cu-list-head">
        <div>
            <h3 class="cu-list-title">Mis facturas</h3>
            <p class="cu-list-sub"><?= count($uploads) ?> en total <?php if (!empty($uploads)): ?>· las mas recientes arriba<?php endif; ?></p>
        </div>
        <?php
        // Quick filter tabs at the top of the list
        $tabCounts = ['all' => count($uploads)];
        foreach ($uploads as $u) {
            $key = $u['status'] === 'approved' ? 'approved' : ($u['status'] === 'extracted' ? 'extracted' : ($u['status'] === 'error' ? 'error' : 'pending'));
            $tabCounts[$key] = ($tabCounts[$key] ?? 0) + 1;
        }
        ?>
        <div class="cu-tabs" role="tablist">
            <button type="button" class="cu-tab is-active" data-filter="all">Todas <span class="cu-tab-count"><?= $tabCounts['all'] ?? 0 ?></span></button>
            <button type="button" class="cu-tab" data-filter="extracted">Por validar <span class="cu-tab-count"><?= $tabCounts['extracted'] ?? 0 ?></span></button>
            <button type="button" class="cu-tab" data-filter="approved">Aprobadas <span class="cu-tab-count"><?= $tabCounts['approved'] ?? 0 ?></span></button>
            <?php if (!empty($tabCounts['error'])): ?>
            <button type="button" class="cu-tab" data-filter="error">Errores <span class="cu-tab-count"><?= $tabCounts['error'] ?></span></button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (empty($uploads)): ?>
    <div class="cu-empty">
        <div class="cu-empty-icon">
            <svg class="w-7 h-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        </div>
        <p class="cu-empty-title">Aun no has subido facturas</p>
        <p class="cu-empty-sub">Sube la primera arriba y veras el resultado en pocos segundos.</p>
    </div>
    <?php else: ?>
    <div class="cu-rows" id="cuRows">
        <?php foreach ($uploads as $u):
            $statusKey = $u['status'] === 'approved' ? 'approved' : ($u['status'] === 'extracted' ? 'extracted' : ($u['status'] === 'error' ? 'error' : 'pending'));
            $statusBadge = match($u['status']) {
                'uploaded'   => '<span class="cu-pill cu-pill-slate">En cola</span>',
                'processing' => '<span class="cu-pill cu-pill-blue">Procesando…</span>',
                'extracted'  => '<span class="cu-pill cu-pill-amber">Por validar</span>',
                'approved'   => '<span class="cu-pill cu-pill-emerald">Aprobada</span>',
                'rejected'   => '<span class="cu-pill cu-pill-slate">Rechazada</span>',
                'error'      => '<span class="cu-pill cu-pill-red">Error</span>',
                default      => '<span class="cu-pill cu-pill-slate">' . htmlspecialchars($u['status']) . '</span>',
            };
            $docTypePill = '';
            if ($u['ai_doc_type'] === 'venta') $docTypePill = '<span class="cu-pill cu-pill-blue">607 Venta</span>';
            elseif ($u['ai_doc_type'] === 'compra') $docTypePill = '<span class="cu-pill cu-pill-indigo">606 Compra</span>';
            $thumbHref = 'uploads/invoices/' . htmlspecialchars($u['filename']);
            $isImage = strpos($u['mime_type'], 'image/') === 0;
            $confidence = (float)($u['confidence'] ?? 0);
            $confPct = round($confidence * 100);
        ?>
        <article class="cu-row" data-status="<?= $statusKey ?>">
            <a href="<?= $thumbHref ?>" target="_blank" class="cu-thumb">
                <?php if ($isImage): ?>
                <img src="<?= $thumbHref ?>" alt="" loading="lazy">
                <?php else: ?>
                <svg class="w-5 h-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                <?php endif; ?>
            </a>

            <div class="cu-row-main">
                <div class="cu-row-header">
                    <p class="cu-row-title">
                        <?= $u['extraction_id'] ? htmlspecialchars($u['counterparty_name'] ?: $u['original_name']) : htmlspecialchars($u['original_name']) ?>
                    </p>
                    <?= $statusBadge ?>
                    <?= $docTypePill ?>
                </div>
                <div class="cu-row-meta">
                    <?php if ($u['extraction_id']): ?>
                    <span class="cu-meta-item"><span class="cu-meta-label">NCF</span> <span class="font-mono"><?= htmlspecialchars($u['ncf'] ?: '—') ?></span></span>
                    <span class="cu-meta-item"><span class="cu-meta-label">RNC</span> <span class="font-mono"><?= htmlspecialchars($u['rnc'] ?: '—') ?></span></span>
                    <span class="cu-meta-item"><span class="cu-meta-label"><?= $u['date_doc'] ? date('d/m/Y', strtotime($u['date_doc'])) : '—' ?></span></span>
                    <?php if ($confidence > 0): ?>
                    <span class="cu-meta-item cu-conf cu-conf-<?= $confidence >= 0.85 ? 'good' : ($confidence >= 0.6 ? 'mid' : 'low') ?>">
                        IA <?= $confPct ?>%
                    </span>
                    <?php endif; ?>
                    <?php elseif ($u['status'] === 'error'): ?>
                    <span class="cu-meta-error truncate"><?= htmlspecialchars($u['error_message'] ?? 'Error') ?></span>
                    <?php else: ?>
                    <span class="cu-meta-label"><?= date('d/m/Y H:i', strtotime($u['created_at'])) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($u['extraction_id'] && $u['total']): ?>
            <div class="cu-row-amount">
                <p class="cu-amount-val">RD$ <?= number_format((float)$u['total'], 2) ?></p>
                <p class="cu-amount-itbis">ITBIS <?= number_format((float)$u['itbis'], 2) ?></p>
            </div>
            <?php endif; ?>

            <div class="cu-row-actions">
                <?php if (in_array($u['status'], ['error','extracted'], true)): ?>
                <form method="POST" class="inline-flex">
                    <input type="hidden" name="action" value="reprocess">
                    <input type="hidden" name="upload_id" value="<?= $u['id'] ?>">
                    <button type="submit" class="cu-action-btn cu-action-btn-blue" title="Reprocesar con IA">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                    </button>
                </form>
                <?php endif; ?>
                <?php if ($u['status'] !== 'approved'): ?>
                <form method="POST" class="inline-flex" onsubmit="return confirm('Eliminar esta factura?')">
                    <input type="hidden" name="action" value="delete_upload">
                    <input type="hidden" name="upload_id" value="<?= $u['id'] ?>">
                    <button type="submit" class="cu-action-btn cu-action-btn-red" title="Eliminar">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
                    </button>
                </form>
                <?php endif; ?>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<style>
    /* === Client uploads (modulo del cliente) === */
    .cu-flash { display: flex; align-items: flex-start; gap: 10px; padding: 12px 16px; border-radius: 14px; font-size: 13px; font-weight: 600; margin-bottom: 16px; }
    .cu-flash-success { background: #ECFDF5; color: #047857; border: 1px solid #A7F3D0; }
    .cu-flash-error   { background: #FEF2F2; color: #B91C1C; border: 1px solid #FECACA; }

    /* Period bar */
    .cu-period-bar { display: flex; flex-direction: column; gap: 14px; padding: 16px 18px; background: #fff; border: 1px solid #EEF0F2; border-radius: 22px; margin-bottom: 16px; }
    .cu-period-nav { display: flex; align-items: center; gap: 10px; }
    .cu-nav-btn { width: 38px; height: 38px; border-radius: 12px; background: #F4F4F5; color: #475569; display: inline-flex; align-items: center; justify-content: center; transition: all .15s ease; }
    .cu-nav-btn:hover { background: #E5E7EB; color: #0F172A; }
    .cu-period-label { display: flex; flex-direction: column; min-width: 140px; }
    .cu-period-month { font-size: 18px; font-weight: 800; color: #0F172A; line-height: 1.1; }
    .cu-period-sub { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #94A3B8; margin-top: 2px; }
    .cu-stats { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 8px; }
    .cu-stat { padding: 10px 12px; border-radius: 14px; background: #F8FAFC; }
    .cu-stat-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #64748B; }
    .cu-stat-val { font-size: 15px; font-weight: 800; color: #0F172A; margin-top: 2px; font-variant-numeric: tabular-nums; }
    .cu-stat-val-blue { color: #1D4ED8; }
    .cu-stat-red  { background: #FEF2F2; }
    .cu-stat-red  .cu-stat-label { color: #B91C1C; }
    .cu-stat-red  .cu-stat-val { color: #B91C1C; }
    .cu-stat-green { background: #ECFDF5; }
    .cu-stat-green .cu-stat-label { color: #047857; }
    .cu-stat-green .cu-stat-val { color: #047857; }
    @media (min-width: 768px) {
        .cu-period-bar { flex-direction: row; align-items: center; gap: 24px; }
        .cu-stats { grid-template-columns: repeat(4, minmax(120px,1fr)); flex: 1; }
    }

    /* Upload hero */
    .cu-upload-hero { background: #fff; border: 1px solid #EEF0F2; border-radius: 22px; padding: 20px; margin-bottom: 16px; display: grid; gap: 16px; grid-template-columns: 1fr; }
    .cu-upload-info { display: flex; gap: 14px; align-items: flex-start; }
    .cu-upload-icon { width: 44px; height: 44px; border-radius: 14px; background: linear-gradient(135deg, #DBEAFE, #E0E7FF); color: #1D4ED8; display: inline-flex; align-items: center; justify-content: center; shrink-0: 0; flex-shrink: 0; }
    .cu-upload-title { font-size: 16px; font-weight: 800; color: #0F172A; }
    .cu-upload-desc { font-size: 13px; color: #64748B; line-height: 1.55; margin-top: 4px; }
    .cu-upload-pills { margin-top: 8px; display: flex; gap: 6px; flex-wrap: wrap; }
    .cu-mini-pill { font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 999px; background: #F1F5F9; color: #475569; }
    .cu-upload-form { display: flex; flex-direction: column; gap: 12px; }
    .cu-dropzone { display: flex; flex-direction: column; align-items: center; gap: 6px; padding: 28px 16px; border: 2px dashed #CBD5E1; border-radius: 18px; background: linear-gradient(180deg, #FAFAFA, #F4F4F5); cursor: pointer; transition: all .2s ease; }
    .cu-dropzone:hover, .cu-dropzone.is-drag { border-color: #2563EB; background: linear-gradient(180deg, #EFF6FF, #DBEAFE); }
    .cu-dropzone-icon { color: #2563EB; }
    .cu-dropzone-title { font-size: 14px; font-weight: 700; color: #0F172A; }
    .cu-dropzone-sub   { font-size: 12px; color: #64748B; }
    .cu-dropzone-summary { font-size: 12px; font-weight: 600; color: #1D4ED8; margin-top: 4px; padding: 4px 10px; border-radius: 999px; background: #DBEAFE; }
    .cu-upload-controls { display: flex; gap: 8px; }
    .cu-select { flex: 1; padding: 11px 14px; border-radius: 12px; border: 1.5px solid #E5E7EB; background: #fff; font-size: 13px; font-weight: 600; color: #0F172A; }
    .cu-submit-btn { display: inline-flex; align-items: center; gap: 8px; padding: 11px 22px; background: #0F172A; color: #fff; border-radius: 12px; font-size: 13px; font-weight: 700; transition: all .15s ease; white-space: nowrap; }
    .cu-submit-btn:hover { background: #1E293B; transform: translateY(-1px); box-shadow: 0 8px 20px rgba(15,23,42,0.18); }
    .cu-submit-btn:disabled { opacity: .6; cursor: not-allowed; transform: none; box-shadow: none; }
    @media (min-width: 768px) {
        .cu-upload-hero { grid-template-columns: 1.1fr 1fr; align-items: center; }
    }

    /* List */
    .cu-list { background: #fff; border: 1px solid #EEF0F2; border-radius: 22px; overflow: hidden; }
    .cu-list-head { padding: 14px 18px; border-bottom: 1px solid #F4F4F5; display: flex; flex-direction: column; gap: 12px; }
    .cu-list-title { font-size: 15px; font-weight: 800; color: #0F172A; }
    .cu-list-sub   { font-size: 11px; color: #94A3B8; }
    .cu-tabs { display: flex; gap: 6px; overflow-x: auto; }
    .cu-tabs::-webkit-scrollbar { display: none; }
    .cu-tab { display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border-radius: 999px; background: #F4F4F5; color: #475569; font-size: 12px; font-weight: 700; transition: all .15s ease; white-space: nowrap; cursor: pointer; }
    .cu-tab:hover { background: #E5E7EB; color: #0F172A; }
    .cu-tab.is-active { background: #0F172A; color: #fff; }
    .cu-tab-count { padding: 1px 7px; border-radius: 999px; background: rgba(255,255,255,0.18); font-size: 10px; }
    .cu-tab:not(.is-active) .cu-tab-count { background: rgba(0,0,0,0.06); }
    @media (min-width: 768px) {
        .cu-list-head { flex-direction: row; align-items: center; justify-content: space-between; }
    }

    .cu-empty { padding: 60px 20px; text-align: center; }
    .cu-empty-icon { width: 56px; height: 56px; border-radius: 50%; background: #F4F4F5; color: #94A3B8; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 12px; }
    .cu-empty-title { font-size: 14px; font-weight: 700; color: #0F172A; }
    .cu-empty-sub { font-size: 12px; color: #94A3B8; margin-top: 4px; }

    .cu-rows { display: flex; flex-direction: column; }
    .cu-row { display: flex; align-items: center; gap: 14px; padding: 14px 18px; border-bottom: 1px solid #F4F4F5; transition: background .15s ease; }
    .cu-row:hover { background: #FAFAFA; }
    .cu-row:last-child { border-bottom: 0; }
    .cu-row[hidden] { display: none; }
    .cu-thumb { width: 48px; height: 48px; border-radius: 12px; background: #F4F4F5; border: 1px solid #E5E7EB; overflow: hidden; display: inline-flex; align-items: center; justify-content: center; transition: transform .15s ease; flex-shrink: 0; }
    .cu-thumb:hover { transform: scale(1.06); }
    .cu-thumb img { width: 100%; height: 100%; object-fit: cover; }
    .cu-row-main { flex: 1; min-width: 0; }
    .cu-row-header { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 4px; }
    .cu-row-title { font-size: 13px; font-weight: 700; color: #0F172A; max-width: 360px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .cu-row-meta { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; font-size: 11px; color: #64748B; }
    .cu-meta-item { display: inline-flex; align-items: center; gap: 5px; }
    .cu-meta-label { font-size: 10px; color: #94A3B8; font-weight: 600; letter-spacing: 0.03em; }
    .cu-meta-error { color: #DC2626; font-weight: 600; max-width: 380px; }
    .cu-conf { padding: 2px 8px; border-radius: 999px; font-weight: 700; font-size: 10px; }
    .cu-conf-good { background: #ECFDF5; color: #047857; }
    .cu-conf-mid  { background: #FEF3C7; color: #B45309; }
    .cu-conf-low  { background: #FEE2E2; color: #B91C1C; }

    .cu-row-amount { text-align: right; flex-shrink: 0; }
    .cu-amount-val { font-size: 14px; font-weight: 800; color: #0F172A; font-variant-numeric: tabular-nums; }
    .cu-amount-itbis { font-size: 10px; color: #64748B; font-weight: 600; margin-top: 2px; font-variant-numeric: tabular-nums; }

    .cu-row-actions { display: flex; gap: 4px; flex-shrink: 0; }
    .cu-action-btn { width: 34px; height: 34px; border-radius: 10px; background: #F4F4F5; color: #64748B; display: inline-flex; align-items: center; justify-content: center; transition: all .15s ease; }
    .cu-action-btn-blue:hover { background: #DBEAFE; color: #1D4ED8; }
    .cu-action-btn-red:hover  { background: #FEE2E2; color: #B91C1C; }

    .cu-pill { display: inline-flex; align-items: center; gap: 5px; font-size: 10px; font-weight: 700; padding: 3px 9px; border-radius: 999px; white-space: nowrap; letter-spacing: 0.02em; }
    .cu-pill::before { content: ''; width: 5px; height: 5px; border-radius: 999px; background: currentColor; }
    .cu-pill-emerald { color: #15803D; background: #DCFCE7; }
    .cu-pill-amber   { color: #B45309; background: #FEF3C7; }
    .cu-pill-red     { color: #DC2626; background: #FEE2E2; }
    .cu-pill-blue    { color: #1D4ED8; background: #DBEAFE; }
    .cu-pill-indigo  { color: #4F46E5; background: #E0E7FF; }
    .cu-pill-slate   { color: #475569; background: #F1F5F9; }

    @media (max-width: 640px) {
        .cu-row { flex-wrap: wrap; }
        .cu-row-amount { order: 3; width: 100%; text-align: left; padding-left: 62px; }
        .cu-row-title { max-width: 220px; }
    }

    @keyframes fadeIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }
    .animate-fadeIn { animation: fadeIn .3s ease; }
</style>

<script>
// Upload form interactions
(function() {
    const input = document.getElementById('fileInput');
    const summary = document.getElementById('fileSummary');
    const dropZone = document.getElementById('dropZone');
    const btn = document.getElementById('uploadBtn');
    const form = document.getElementById('uploadForm');

    function refreshSummary() {
        if (!input.files || input.files.length === 0) {
            summary.classList.add('hidden');
            return;
        }
        let totalKb = 0;
        for (const f of input.files) totalKb += f.size;
        summary.textContent = input.files.length + ' archivo(s) · ' + Math.round(totalKb / 1024) + ' KB · listos para enviar';
        summary.classList.remove('hidden');
    }
    input.addEventListener('change', refreshSummary);

    ['dragenter','dragover'].forEach(ev => {
        dropZone.addEventListener(ev, function(e){ e.preventDefault(); dropZone.classList.add('is-drag'); });
    });
    ['dragleave','drop'].forEach(ev => {
        dropZone.addEventListener(ev, function(e){ e.preventDefault(); dropZone.classList.remove('is-drag'); });
    });
    dropZone.addEventListener('drop', function(e){
        if (e.dataTransfer && e.dataTransfer.files) {
            input.files = e.dataTransfer.files;
            refreshSummary();
        }
    });

    form.addEventListener('submit', function(){
        if (input.files && input.files.length > 0) {
            btn.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity=".25"/><path d="M22 12a10 10 0 01-10 10" stroke="currentColor" stroke-width="3" fill="none"/></svg> Procesando…';
            btn.disabled = true;
        }
    });
})();

// Tabs filter
(function() {
    const tabs = document.querySelectorAll('.cu-tab');
    const rows = document.querySelectorAll('.cu-row');
    if (!tabs.length || !rows.length) return;
    tabs.forEach(t => {
        t.addEventListener('click', () => {
            tabs.forEach(x => x.classList.remove('is-active'));
            t.classList.add('is-active');
            const f = t.dataset.filter;
            rows.forEach(r => {
                const status = r.dataset.status;
                const show = (f === 'all') || (status === f);
                r.hidden = !show;
            });
        });
    });
})();
</script>

<?php include 'components/layout_end.php'; ?>
