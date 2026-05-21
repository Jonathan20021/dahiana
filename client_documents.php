<?php
require_once 'config.php';
requireAuth('client');

$client_id = (int)$_SESSION['user_id'];

// Bootstrap tabla de documentos compartidos
$pdo->exec("
    CREATE TABLE IF NOT EXISTS client_documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        uploaded_by INT NOT NULL,
        filename VARCHAR(255) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        file_size BIGINT DEFAULT 0,
        mime_type VARCHAR(100) DEFAULT NULL,
        category VARCHAR(60) DEFAULT 'general',
        description VARCHAR(500) DEFAULT NULL,
        period VARCHAR(10) DEFAULT NULL,
        read_by_client TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(client_id), INDEX(category), INDEX(period)
    )
");

// Marcar como leidos los del usuario al visitar la pagina
$pdo->prepare("UPDATE client_documents SET read_by_client=1 WHERE client_id=?")->execute([$client_id]);

// Categorias
$categories = [
    'general'      => ['Documentos generales', 'slate'],
    'declaracion'  => ['Declaraciones DGII',    'blue'],
    'comprobante'  => ['Comprobantes de pago', 'emerald'],
    'reporte'      => ['Reportes mensuales',   'indigo'],
    'contrato'     => ['Contratos y acuerdos', 'amber'],
];

$filter = $_GET['cat'] ?? 'all';
$where = ['client_id = ?'];
$params = [$client_id];
if (isset($categories[$filter])) {
    $where[] = 'category = ?';
    $params[] = $filter;
}
$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT cd.*, u.name as uploader_name
    FROM client_documents cd
    LEFT JOIN users u ON u.id = cd.uploaded_by
    WHERE $whereSql
    ORDER BY cd.created_at DESC
    LIMIT 200
");
$stmt->execute($params);
$docs = $stmt->fetchAll();

$totalDocs = (int)$pdo->query("SELECT COUNT(*) FROM client_documents WHERE client_id=" . $client_id)->fetchColumn();
$totalSize = (float)$pdo->query("SELECT COALESCE(SUM(file_size),0) FROM client_documents WHERE client_id=" . $client_id)->fetchColumn();

// Conteo por categoria
$catStmt = $pdo->prepare("SELECT category, COUNT(*) c FROM client_documents WHERE client_id=? GROUP BY category");
$catStmt->execute([$client_id]);
$catCounts = $catStmt->fetchAll(PDO::FETCH_KEY_PAIR);

function fmtBytes($b) {
    if ($b < 1024) return $b . ' B';
    if ($b < 1024*1024) return round($b/1024, 1) . ' KB';
    if ($b < 1024*1024*1024) return round($b/(1024*1024), 1) . ' MB';
    return round($b/(1024*1024*1024), 2) . ' GB';
}
function fileIcon($mime, $name) {
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (in_array($ext, ['pdf'], true)) return ['pdf', 'red'];
    if (in_array($ext, ['xls','xlsx','csv'], true)) return ['xls', 'emerald'];
    if (in_array($ext, ['doc','docx'], true)) return ['doc', 'blue'];
    if (in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) return ['img', 'indigo'];
    if (in_array($ext, ['zip','rar','7z'], true)) return ['zip', 'amber'];
    return ['file', 'slate'];
}

$page_title = 'Mis documentos';
$page_subtitle = 'Archivos compartidos por tu asesoria: declaraciones, reportes, comprobantes.';
include 'components/layout_start.php';
?>

<!-- KPIs y filtros en una sola fila -->
<div class="grid grid-cols-1 lg:grid-cols-4 gap-3 mb-4">
    <div class="surface-card p-4 flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        </div>
        <div>
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Total archivos</p>
            <p class="text-xl font-extrabold text-slate-900"><?= $totalDocs ?></p>
        </div>
    </div>
    <div class="surface-card p-4 flex items-center gap-3">
        <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
        </div>
        <div>
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Espacio total</p>
            <p class="text-xl font-extrabold text-slate-900"><?= fmtBytes($totalSize) ?></p>
        </div>
    </div>
    <div class="surface-card p-4 flex items-center gap-3 lg:col-span-2">
        <div class="w-10 h-10 rounded-xl bg-amber-50 text-amber-600 flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <div class="min-w-0 flex-1">
            <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Necesitas otro archivo?</p>
            <p class="text-xs text-slate-700 mt-0.5">Pide a tu asesoria que lo suba aqui o escribelos por chat.</p>
        </div>
        <a href="client_messages.php" class="btn-soft text-xs whitespace-nowrap">Abrir chat →</a>
    </div>
</div>

<!-- Filtros categoria -->
<div class="surface-card p-2 mb-3 flex flex-wrap items-center gap-1">
    <a href="client_documents.php?cat=all" class="cd-tab <?= $filter === 'all' ? 'is-active' : '' ?>">
        Todos
        <?php if ($totalDocs > 0): ?><span class="cd-tab-count"><?= $totalDocs ?></span><?php endif; ?>
    </a>
    <?php foreach ($categories as $key => [$label, $color]):
        $cnt = (int)($catCounts[$key] ?? 0);
        if ($cnt === 0 && $filter !== $key) continue;
    ?>
    <a href="client_documents.php?cat=<?= $key ?>" class="cd-tab <?= $filter === $key ? 'is-active' : '' ?>">
        <span class="w-1.5 h-1.5 rounded-full bg-<?= $color === 'slate' ? 'slate-400' : ($color . '-500') ?>"></span>
        <?= htmlspecialchars($label) ?>
        <?php if ($cnt > 0): ?><span class="cd-tab-count"><?= $cnt ?></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Lista -->
<?php if (empty($docs)): ?>
<div class="surface-card p-10 text-center">
    <svg class="w-12 h-12 mx-auto text-slate-200 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
    <p class="text-sm text-slate-500"><?= $filter === 'all' ? 'Aun no hay documentos compartidos.' : 'No hay archivos en esta categoria.' ?></p>
    <p class="text-[11px] text-slate-400 mt-1">Tu asesoria sube aqui declaraciones, reportes y comprobantes.</p>
</div>
<?php else: ?>
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
    <?php foreach ($docs as $doc):
        [$iconType, $iconColor] = fileIcon($doc['mime_type'] ?? '', $doc['original_name']);
        $catMeta = $categories[$doc['category']] ?? ['Otro', 'slate'];
        $isNew = !$doc['read_by_client']; // Aunque ya lo marcamos, mantenemos por si llega en este request
    ?>
    <a href="uploads/<?= htmlspecialchars($doc['filename']) ?>" target="_blank" class="cd-card">
        <div class="cd-card-icon cd-icon-<?= $iconColor ?>">
            <?php if ($iconType === 'pdf'): ?>
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6zm-1.5 11h-1v2h-1v-5h2c.83 0 1.5.67 1.5 1.5S13.33 13 12.5 13zm5 .5c0 .83-.67 1.5-1.5 1.5h-2v-5h2c.83 0 1.5.67 1.5 1.5v2zM12 13h.5c.28 0 .5-.22.5-.5s-.22-.5-.5-.5H12v1zm4 0v2h.5c.28 0 .5-.22.5-.5v-1c0-.28-.22-.5-.5-.5H16z"/></svg>
            <?php elseif ($iconType === 'xls'): ?>
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6zM8 18h2l1.5-2L13 18h2l-2.5-3.5L15 11h-2l-1.5 2L10 11H8l2.5 3.5L8 18z"/></svg>
            <?php elseif ($iconType === 'doc'): ?>
            <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8l-6-6zM8 13h8v-2H8v2zm0 4h8v-2H8v2zm0-8h5V7H8v2z"/></svg>
            <?php elseif ($iconType === 'img'): ?>
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
            <?php elseif ($iconType === 'zip'): ?>
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
            <?php else: ?>
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            <?php endif; ?>
        </div>
        <div class="cd-card-main">
            <p class="cd-card-name">
                <?= htmlspecialchars($doc['original_name']) ?>
                <?php if ($isNew): ?><span class="cd-new-badge">NUEVO</span><?php endif; ?>
            </p>
            <?php if ($doc['description']): ?>
            <p class="cd-card-desc"><?= htmlspecialchars($doc['description']) ?></p>
            <?php endif; ?>
            <div class="cd-card-meta">
                <span class="cd-pill cd-pill-<?= $catMeta[1] ?>"><?= htmlspecialchars($catMeta[0]) ?></span>
                <?php if ($doc['period']): ?>
                <span class="text-[10px] text-slate-500">· <?= htmlspecialchars($doc['period']) ?></span>
                <?php endif; ?>
                <span class="text-[10px] text-slate-400">· <?= fmtBytes($doc['file_size']) ?></span>
            </div>
            <p class="cd-card-foot">
                <?= htmlspecialchars($doc['uploader_name'] ?: 'Tu asesor') ?> · <?= date('d M Y', strtotime($doc['created_at'])) ?>
            </p>
        </div>
        <div class="cd-card-action">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<style>
    .cd-tab { display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border-radius: 10px; font-size: 12px; font-weight: 700; color: #475569; transition: all .15s ease; }
    .cd-tab:hover { background: #F4F4F5; color: #0F172A; }
    .cd-tab.is-active { background: #0F172A; color: #fff; }
    .cd-tab-count { font-size: 10px; padding: 1px 6px; border-radius: 999px; background: rgba(0,0,0,0.08); }
    .cd-tab.is-active .cd-tab-count { background: rgba(255,255,255,0.18); }

    .cd-card {
        display: flex; gap: 12px; padding: 14px;
        background: #fff; border: 1px solid #EEF0F2; border-radius: 16px;
        transition: all .18s ease;
    }
    .cd-card:hover { border-color: #CBD5E1; transform: translateY(-2px); box-shadow: 0 6px 20px rgba(15,23,42,.06); }
    .cd-card-icon { width: 42px; height: 42px; border-radius: 12px; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; }
    .cd-icon-red     { background: #FEF2F2; color: #DC2626; }
    .cd-icon-emerald { background: #F0FDF4; color: #15803D; }
    .cd-icon-blue    { background: #EFF6FF; color: #2563EB; }
    .cd-icon-indigo  { background: #EEF2FF; color: #4F46E5; }
    .cd-icon-amber   { background: #FFFBEB; color: #B45309; }
    .cd-icon-slate   { background: #F1F5F9; color: #475569; }

    .cd-card-main { flex: 1; min-width: 0; }
    .cd-card-name { font-size: 13px; font-weight: 700; color: #0F172A; line-height: 1.3; word-break: break-word; }
    .cd-card-desc { font-size: 11.5px; color: #64748B; margin-top: 3px; line-height: 1.4; }
    .cd-card-meta { display: flex; align-items: center; gap: 4px; margin-top: 6px; flex-wrap: wrap; }
    .cd-card-foot { font-size: 10.5px; color: #94A3B8; margin-top: 4px; }
    .cd-card-action { color: #CBD5E1; flex-shrink: 0; align-self: center; }
    .cd-card:hover .cd-card-action { color: #2563EB; }

    .cd-pill { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 999px; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; }
    .cd-pill-slate   { background: #F1F5F9; color: #475569; }
    .cd-pill-blue    { background: #EFF6FF; color: #2563EB; }
    .cd-pill-emerald { background: #F0FDF4; color: #15803D; }
    .cd-pill-indigo  { background: #EEF2FF; color: #4F46E5; }
    .cd-pill-amber   { background: #FFFBEB; color: #B45309; }
    .cd-pill-red     { background: #FEF2F2; color: #DC2626; }

    .cd-new-badge { display: inline-block; padding: 1px 6px; background: #2563EB; color: #fff; border-radius: 4px; font-size: 9px; font-weight: 800; letter-spacing: 0.04em; margin-left: 4px; vertical-align: middle; }
</style>

<?php include 'components/layout_end.php'; ?>
