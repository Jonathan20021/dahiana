<?php
require_once 'config.php';
requireAuth('admin');
requirePagePermission();

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

$success = $error = null;

$categories = [
    'general'      => 'Documentos generales',
    'declaracion'  => 'Declaraciones DGII',
    'comprobante'  => 'Comprobantes de pago',
    'reporte'      => 'Reportes mensuales',
    'contrato'     => 'Contratos y acuerdos',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'upload_doc') {
        $clientId = (int)($_POST['client_id'] ?? 0);
        $category = $_POST['category'] ?? 'general';
        $description = trim($_POST['description'] ?? '');
        $period = trim($_POST['period'] ?? '');

        if (!isset($categories[$category])) $category = 'general';

        if ($clientId <= 0) {
            $error = 'Selecciona un cliente.';
        } elseif (!clientAccessibleByUser($clientId)) {
            $error = 'No tienes permiso para gestionar a este cliente.';
        } elseif (empty($_FILES['file']['name'])) {
            $error = 'Selecciona un archivo.';
        } elseif ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Error al subir: codigo ' . $_FILES['file']['error'];
        } else {
            $allowed = ['pdf','jpg','jpeg','png','webp','gif','doc','docx','xls','xlsx','csv','zip','rar','txt'];
            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) {
                $error = 'Tipo de archivo no permitido. Acepta: ' . implode(', ', $allowed);
            } elseif ($_FILES['file']['size'] > 25 * 1024 * 1024) {
                $error = 'Maximo 25 MB por archivo.';
            } else {
                $destDir = __DIR__ . '/uploads/';
                if (!is_dir($destDir)) @mkdir($destDir, 0755, true);
                $newName = 'doc_' . $clientId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (move_uploaded_file($_FILES['file']['tmp_name'], $destDir . $newName)) {
                    $pdo->prepare("
                        INSERT INTO client_documents (client_id, uploaded_by, filename, original_name, file_size, mime_type, category, description, period)
                        VALUES (?,?,?,?,?,?,?,?,?)
                    ")->execute([
                        $clientId, (int)$_SESSION['user_id'], $newName, $_FILES['file']['name'],
                        $_FILES['file']['size'], $_FILES['file']['type'] ?? null,
                        $category, $description ?: null, $period ?: null
                    ]);
                    logClientActivity($clientId, 'document', 'Archivo compartido: ' . $_FILES['file']['name']);
                    $success = 'Archivo compartido con el cliente.';
                } else {
                    $error = 'No se pudo guardar el archivo.';
                }
            }
        }
    } elseif ($action === 'delete_doc') {
        $docId = (int)($_POST['doc_id'] ?? 0);
        $row = $pdo->prepare("SELECT filename FROM client_documents WHERE id=?");
        $row->execute([$docId]);
        if ($d = $row->fetch()) {
            @unlink(__DIR__ . '/uploads/' . $d['filename']);
            $pdo->prepare("DELETE FROM client_documents WHERE id=?")->execute([$docId]);
            $success = 'Archivo eliminado.';
        }
    }
}

$filterClient = (int)($_GET['client'] ?? 0);
$filterCat = $_GET['cat'] ?? 'all';

$where = ['1=1', clientScopeWhere('cd.client_id')];
$params = [];
if ($filterClient > 0) {
    if (!clientAccessibleByUser($filterClient)) {
        header('Location: admin_documents.php');
        exit;
    }
    $where[] = 'cd.client_id = ?'; $params[] = $filterClient;
}
if (isset($categories[$filterCat])) { $where[] = 'cd.category = ?'; $params[] = $filterCat; }
$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT cd.*, u.name as client_name, ub.name as uploader_name
    FROM client_documents cd
    JOIN users u ON u.id = cd.client_id
    LEFT JOIN users ub ON ub.id = cd.uploaded_by
    WHERE $whereSql
    ORDER BY cd.created_at DESC
    LIMIT 300
");
$stmt->execute($params);
$docs = $stmt->fetchAll();

$clients = $pdo->query("
    SELECT u.id, u.name FROM users u
    LEFT JOIN roles r ON r.slug = u.role
    WHERE COALESCE(r.access_level, CASE WHEN u.role='admin' THEN 'admin' ELSE 'client' END)='client'
      AND " . clientScopeWhere('u.id') . "
    ORDER BY u.name
")->fetchAll();

$totalDocs = (int)$pdo->query("SELECT COUNT(*) FROM client_documents")->fetchColumn();
$totalSize = (float)$pdo->query("SELECT COALESCE(SUM(file_size),0) FROM client_documents")->fetchColumn();

function fmtBytes($b) {
    if ($b < 1024) return $b . ' B';
    if ($b < 1024*1024) return round($b/1024, 1) . ' KB';
    if ($b < 1024*1024*1024) return round($b/(1024*1024), 1) . ' MB';
    return round($b/(1024*1024*1024), 2) . ' GB';
}

$page_title = 'Documentos compartidos';
$page_subtitle = 'Sube archivos para tus clientes (declaraciones, reportes, comprobantes).';
$page_actions = '<button type="button" onclick="document.getElementById(\'uploadDocModal\').classList.remove(\'hidden\')" class="btn-dark text-sm">
    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
    Subir documento
</button>';
include 'components/layout_start.php';
?>

<?php if ($success): ?>
<div class="mb-4 rounded-2xl bg-emerald-50 px-4 py-3 border border-emerald-100 text-sm font-medium text-emerald-800"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="mb-4 rounded-2xl bg-red-50 px-4 py-3 border border-red-100 text-sm font-medium text-red-700"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- KPIs -->
<div class="grid grid-cols-2 lg:grid-cols-3 gap-3 mb-4">
    <div class="surface-card p-4">
        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Total compartidos</p>
        <p class="text-2xl font-extrabold text-slate-900 mt-1"><?= $totalDocs ?></p>
    </div>
    <div class="surface-card p-4">
        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Espacio usado</p>
        <p class="text-2xl font-extrabold text-slate-900 mt-1"><?= fmtBytes($totalSize) ?></p>
    </div>
    <div class="surface-card p-4">
        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400">Clientes</p>
        <p class="text-2xl font-extrabold text-slate-900 mt-1"><?= count($clients) ?></p>
    </div>
</div>

<!-- Filtros -->
<form method="GET" class="surface-card p-3 mb-3 flex flex-wrap gap-2 items-center">
    <select name="client" onchange="this.form.submit()" class="field text-sm flex-1 min-w-[200px]">
        <option value="0">Todos los clientes</option>
        <?php foreach ($clients as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $filterClient === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <select name="cat" onchange="this.form.submit()" class="field text-sm">
        <option value="all">Todas las categorias</option>
        <?php foreach ($categories as $k => $lbl): ?>
        <option value="<?= $k ?>" <?= $filterCat === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($filterClient || $filterCat !== 'all'): ?>
    <a href="admin_documents.php" class="btn-soft text-sm">Limpiar</a>
    <?php endif; ?>
</form>

<!-- Lista -->
<?php if (empty($docs)): ?>
<div class="surface-card p-10 text-center">
    <svg class="w-12 h-12 mx-auto text-slate-200 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
    <p class="text-sm text-slate-500">No hay documentos compartidos.</p>
</div>
<?php else: ?>
<div class="surface-card overflow-hidden">
    <ul class="divide-y divide-slate-100">
        <?php foreach ($docs as $d):
            $catLabel = $categories[$d['category']] ?? ucfirst($d['category']);
        ?>
        <li class="ad-row">
            <div class="ad-icon"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg></div>
            <div class="ad-main">
                <p class="ad-name"><?= htmlspecialchars($d['original_name']) ?></p>
                <?php if ($d['description']): ?>
                <p class="ad-desc"><?= htmlspecialchars($d['description']) ?></p>
                <?php endif; ?>
                <p class="ad-meta">
                    <a href="client_details.php?id=<?= (int)$d['client_id'] ?>" class="text-blue-600 hover:underline"><?= htmlspecialchars($d['client_name']) ?></a>
                    · <span class="ad-pill"><?= htmlspecialchars($catLabel) ?></span>
                    <?php if ($d['period']): ?>· <?= htmlspecialchars($d['period']) ?><?php endif; ?>
                    · <?= fmtBytes($d['file_size']) ?>
                    · <?= date('d M Y H:i', strtotime($d['created_at'])) ?>
                    <?php if ($d['read_by_client']): ?>
                    <span class="text-emerald-600 text-[10px]">✓ Leido</span>
                    <?php else: ?>
                    <span class="text-amber-600 text-[10px]">No leido</span>
                    <?php endif; ?>
                </p>
            </div>
            <div class="ad-actions">
                <a href="uploads/<?= htmlspecialchars($d['filename']) ?>" target="_blank" class="ad-icon-btn" title="Descargar">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                </a>
                <form method="POST" onsubmit="return confirm('Eliminar este documento?')">
                    <input type="hidden" name="action" value="delete_doc">
                    <input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
                    <button type="submit" class="ad-icon-btn ad-icon-danger" title="Eliminar">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
                    </button>
                </form>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<!-- Modal subir -->
<div id="uploadDocModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 modal-backdrop" onclick="document.getElementById('uploadDocModal').classList.add('hidden')"></div>
    <div class="relative flex min-h-full items-center justify-center p-4">
        <div class="w-full max-w-md rounded-3xl bg-white shadow-2xl">
            <div class="px-6 py-5 border-b border-slate-100 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-bold text-slate-900">Subir documento</h3>
                    <p class="text-xs text-slate-500 mt-0.5">El cliente lo vera en su modulo Mis documentos.</p>
                </div>
                <button type="button" onclick="document.getElementById('uploadDocModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-700 text-2xl leading-none">&times;</button>
            </div>
            <form action="admin_documents.php" method="POST" enctype="multipart/form-data" class="p-6 space-y-3">
                <input type="hidden" name="action" value="upload_doc">
                <div>
                    <label class="field-label">Cliente</label>
                    <select name="client_id" required class="field">
                        <option value="">Selecciona un cliente...</option>
                        <?php foreach ($clients as $c): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $filterClient === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label class="field-label">Archivo</label>
                    <input type="file" name="file" required class="field" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx,.csv,.zip,.rar,.txt">
                    <p class="text-[10px] text-slate-400 mt-1">PDF, imagen, Word, Excel, ZIP. Max 25 MB.</p>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="field-label">Categoria</label>
                        <select name="category" class="field">
                            <?php foreach ($categories as $k => $lbl): ?>
                            <option value="<?= $k ?>"><?= htmlspecialchars($lbl) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="field-label">Periodo (opcional)</label>
                        <input type="text" name="period" placeholder="Ej: 2026-03" class="field text-sm">
                    </div>
                </div>
                <div>
                    <label class="field-label">Descripcion (opcional)</label>
                    <input type="text" name="description" placeholder="Ej: Declaracion IT-1 marzo 2026" class="field">
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('uploadDocModal').classList.add('hidden')" class="btn-soft text-sm">Cancelar</button>
                    <button type="submit" class="btn-dark text-sm">Subir</button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
    .ad-row { display: flex; align-items: center; gap: 12px; padding: 12px 16px; transition: background .15s ease; }
    .ad-row:hover { background: #FAFAFA; }
    .ad-icon { width: 36px; height: 36px; border-radius: 12px; background: #F1F5F9; color: #475569; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .ad-main { flex: 1; min-width: 0; }
    .ad-name { font-size: 13px; font-weight: 700; color: #0F172A; word-break: break-word; }
    .ad-desc { font-size: 11.5px; color: #64748B; margin-top: 2px; }
    .ad-meta { font-size: 11px; color: #64748B; margin-top: 4px; display: inline-flex; align-items: center; gap: 4px; flex-wrap: wrap; }
    .ad-pill { display: inline-block; padding: 1px 7px; background: #F1F5F9; color: #475569; border-radius: 999px; font-size: 10px; font-weight: 700; }
    .ad-actions { display: inline-flex; gap: 4px; flex-shrink: 0; }
    .ad-icon-btn { width: 30px; height: 30px; border-radius: 10px; background: #F4F4F5; color: #64748B; display: inline-flex; align-items: center; justify-content: center; transition: all .12s ease; border: 0; cursor: pointer; }
    .ad-icon-btn:hover { background: #E5E7EB; color: #0F172A; }
    .ad-icon-danger:hover { background: #FEE2E2; color: #DC2626; }
</style>

<?php include 'components/layout_end.php'; ?>
