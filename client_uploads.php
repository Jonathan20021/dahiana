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
<div class="mb-4 rounded-2xl bg-emerald-50 px-4 py-3 border border-emerald-100 text-sm font-medium text-emerald-800"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="mb-4 rounded-2xl bg-red-50 px-4 py-3 border border-red-100 text-sm font-medium text-red-700"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Period selector + summary -->
<div class="surface-card p-4 mb-4 flex flex-col lg:flex-row items-center gap-3">
    <div class="flex items-center gap-2">
        <a href="?period=<?= $prevPeriod ?>" class="icon-btn">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <span class="px-3 py-2 rounded-xl bg-stone-50 text-sm font-bold text-slate-900 min-w-[140px] text-center"><?= $periodLabel ?></span>
        <a href="?period=<?= $nextPeriod ?>" class="icon-btn">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        </a>
    </div>
    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 lg:ml-auto w-full lg:w-auto">
        <div class="rounded-xl bg-stone-50 px-3 py-2 text-center">
            <p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">Compras</p>
            <p class="text-sm font-extrabold text-slate-900">RD$ <?= number_format((float)$s['total_compras'], 0) ?></p>
        </div>
        <div class="rounded-xl bg-stone-50 px-3 py-2 text-center">
            <p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">Ventas</p>
            <p class="text-sm font-extrabold text-slate-900">RD$ <?= number_format((float)$s['total_ventas'], 0) ?></p>
        </div>
        <div class="rounded-xl bg-stone-50 px-3 py-2 text-center">
            <p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">ITBIS pagado</p>
            <p class="text-sm font-extrabold text-slate-900">RD$ <?= number_format((float)$s['itbis_compras'], 0) ?></p>
        </div>
        <div class="rounded-xl <?= $it1Balance > 0 ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-700' ?> px-3 py-2 text-center">
            <p class="text-[10px] uppercase tracking-wider opacity-70 font-bold">IT-1 estimado</p>
            <p class="text-sm font-extrabold">RD$ <?= number_format($it1Balance, 0) ?></p>
        </div>
    </div>
</div>

<!-- Upload zone -->
<div class="surface-card p-5 mb-4">
    <div class="flex items-start gap-4 mb-3">
        <div class="w-12 h-12 rounded-2xl bg-blue-50 text-blue-600 flex items-center justify-center shrink-0">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.7"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
        </div>
        <div>
            <h3 class="text-base font-bold text-slate-900">Subir facturas con IA</h3>
            <p class="text-xs text-slate-500 leading-relaxed mt-0.5">
                Toma una foto a tu factura o sube un archivo. Nuestra IA lee el RNC, NCF, monto e ITBIS y lo deja listo para que tu asesor presente 606, 607 e IT-1.
            </p>
        </div>
    </div>

    <form method="POST" enctype="multipart/form-data" class="space-y-3" id="uploadForm">
        <input type="hidden" name="action" value="upload">
        <div class="flex flex-col sm:flex-row gap-3">
            <div class="sm:w-44">
                <label class="field-label">Tipo</label>
                <select name="doc_type" class="field text-sm">
                    <option value="auto">Auto (la IA decide)</option>
                    <option value="compra">Compra (para el 606)</option>
                    <option value="venta">Venta (para el 607)</option>
                </select>
            </div>
            <div class="flex-1">
                <label class="field-label">Fotos o archivos</label>
                <label class="block cursor-pointer rounded-2xl border-2 border-dashed border-stone-200 bg-stone-50 hover:border-blue-400 hover:bg-blue-50/40 transition-colors px-4 py-6 text-center" id="dropZone">
                    <svg class="w-7 h-7 mx-auto text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M9 13l3-3m0 0l3 3m-3-3v8m0-13a9 9 0 100 18 9 9 0 000-18z"/></svg>
                    <p class="mt-2 text-sm font-semibold text-slate-700">Arrastra fotos aqui o toca para seleccionar</p>
                    <p class="text-[11px] text-slate-400">Acepta JPG, PNG y WEBP. Puedes subir varias a la vez.</p>
                    <input type="file" name="files[]" id="fileInput" accept="image/*" multiple class="hidden">
                </label>
                <p id="fileSummary" class="mt-2 text-xs text-slate-500 hidden"></p>
            </div>
        </div>
        <div class="flex justify-end">
            <button type="submit" id="uploadBtn" class="btn-dark text-sm">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                Subir y procesar con IA
            </button>
        </div>
    </form>
</div>

<!-- Uploads list -->
<div class="surface-card overflow-hidden">
    <div class="px-5 py-3 border-b border-stone-100 flex items-center justify-between">
        <h3 class="text-base font-bold text-slate-900">Mis facturas</h3>
        <span class="text-xs text-slate-400"><?= count($uploads) ?> registro(s)</span>
    </div>

    <?php if (empty($uploads)): ?>
    <div class="py-12 text-center text-sm text-slate-400">
        Aun no has subido facturas. Sube la primera arriba.
    </div>
    <?php else: ?>
    <div class="overflow-x-auto scroll-area">
        <table class="w-full text-xs">
            <thead class="bg-stone-50/60 text-[10px] uppercase tracking-wider text-slate-500">
                <tr>
                    <th class="px-4 py-2 text-left font-bold">Archivo</th>
                    <th class="px-4 py-2 text-left font-bold">Tipo</th>
                    <th class="px-4 py-2 text-left font-bold">Periodo</th>
                    <th class="px-4 py-2 text-left font-bold">Datos extraidos</th>
                    <th class="px-4 py-2 text-right font-bold">Total</th>
                    <th class="px-4 py-2 text-right font-bold">ITBIS</th>
                    <th class="px-4 py-2 text-left font-bold">Estado</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                <?php foreach ($uploads as $u):
                    $statusBadge = match($u['status']) {
                        'uploaded'   => '<span class="badge-dot badge-slate">En cola</span>',
                        'processing' => '<span class="badge-dot badge-blue">Procesando</span>',
                        'extracted'  => '<span class="badge-dot badge-amber">Por aprobar</span>',
                        'approved'   => '<span class="badge-dot badge-green">Aprobada</span>',
                        'rejected'   => '<span class="badge-dot badge-slate">Rechazada</span>',
                        'error'      => '<span class="badge-dot badge-red">Error</span>',
                        default      => '<span class="badge-dot badge-slate">' . htmlspecialchars($u['status']) . '</span>',
                    };
                    $docTypeLabel = $u['ai_doc_type'] ? ($u['ai_doc_type']==='venta'?'Venta · 607':'Compra · 606') : ($u['doc_type']==='auto'?'Auto':ucfirst($u['doc_type']));
                    $thumbHref = 'uploads/invoices/' . htmlspecialchars($u['filename']);
                ?>
                <tr class="hover:bg-stone-50/60">
                    <td class="px-4 py-2 max-w-xs">
                        <a href="<?= $thumbHref ?>" target="_blank" class="flex items-center gap-2 hover:text-blue-600">
                            <div class="w-9 h-9 rounded-lg bg-stone-100 border border-stone-200 flex items-center justify-center text-slate-400 shrink-0 overflow-hidden">
                                <?php if (strpos($u['mime_type'], 'image/') === 0): ?>
                                <img src="<?= $thumbHref ?>" alt="" class="w-full h-full object-cover">
                                <?php else: ?>
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                <?php endif; ?>
                            </div>
                            <div class="min-w-0">
                                <p class="font-semibold truncate"><?= htmlspecialchars($u['original_name']) ?></p>
                                <p class="text-[10px] text-slate-400"><?= date('d/m H:i', strtotime($u['created_at'])) ?></p>
                            </div>
                        </a>
                    </td>
                    <td class="px-4 py-2"><?= htmlspecialchars($docTypeLabel) ?></td>
                    <td class="px-4 py-2 font-mono"><?= htmlspecialchars($u['ai_period'] ?? $u['period'] ?? '—') ?></td>
                    <td class="px-4 py-2">
                        <?php if ($u['extraction_id']): ?>
                            <p class="font-semibold text-slate-900 truncate max-w-[220px]"><?= htmlspecialchars($u['counterparty_name'] ?: '—') ?></p>
                            <p class="text-[10px] text-slate-500 font-mono">RNC <?= htmlspecialchars($u['rnc'] ?: '—') ?> · NCF <?= htmlspecialchars($u['ncf'] ?: '—') ?></p>
                        <?php elseif ($u['status'] === 'error'): ?>
                            <p class="text-[11px] text-red-600 truncate max-w-[220px]"><?= htmlspecialchars($u['error_message'] ?? 'Error') ?></p>
                        <?php else: ?>
                            <span class="text-slate-400">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-2 text-right font-semibold">
                        <?= $u['total'] ? 'RD$ ' . number_format((float)$u['total'], 2) : '—' ?>
                    </td>
                    <td class="px-4 py-2 text-right text-slate-600">
                        <?= $u['itbis'] ? 'RD$ ' . number_format((float)$u['itbis'], 2) : '—' ?>
                    </td>
                    <td class="px-4 py-2"><?= $statusBadge ?></td>
                    <td class="px-4 py-2 text-right whitespace-nowrap">
                        <?php if (in_array($u['status'], ['error','extracted'], true)): ?>
                        <form method="POST" class="inline">
                            <input type="hidden" name="action" value="reprocess">
                            <input type="hidden" name="upload_id" value="<?= $u['id'] ?>">
                            <button type="submit" class="text-blue-600 hover:text-blue-800 text-xs font-semibold">Reprocesar</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($u['status'] !== 'approved'): ?>
                        <form method="POST" class="inline" onsubmit="return confirm('Eliminar esta factura?')">
                            <input type="hidden" name="action" value="delete_upload">
                            <input type="hidden" name="upload_id" value="<?= $u['id'] ?>">
                            <button type="submit" class="ml-2 text-red-500 hover:text-red-700 text-xs font-semibold">Eliminar</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
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
        summary.textContent = input.files.length + ' archivo(s) seleccionados · ' + Math.round(totalKb / 1024) + ' KB';
        summary.classList.remove('hidden');
    }
    input.addEventListener('change', refreshSummary);

    ['dragenter','dragover'].forEach(ev => {
        dropZone.addEventListener(ev, function(e){ e.preventDefault(); dropZone.classList.add('border-blue-400','bg-blue-50/40'); });
    });
    ['dragleave','drop'].forEach(ev => {
        dropZone.addEventListener(ev, function(e){ e.preventDefault(); dropZone.classList.remove('border-blue-400','bg-blue-50/40'); });
    });
    dropZone.addEventListener('drop', function(e){
        if (e.dataTransfer && e.dataTransfer.files) {
            input.files = e.dataTransfer.files;
            refreshSummary();
        }
    });

    form.addEventListener('submit', function(){
        if (input.files && input.files.length > 0) {
            btn.innerHTML = '<svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" opacity=".25"/><path d="M22 12a10 10 0 01-10 10" stroke="currentColor" stroke-width="3" fill="none"/></svg> Procesando con IA...';
            btn.disabled = true;
        }
    });
})();
</script>

<?php include 'components/layout_end.php'; ?>
