<?php
require_once 'config.php';
requireAuth();

$request_id = $_GET['id'] ?? null;
$isAdmin = canAccessArea($_SESSION['role'], 'admin');
if (!$request_id) { header('Location: ' . getDashboardForRole($_SESSION['role'])); exit; }

$stmt = $pdo->prepare("
    SELECT r.*, s.title as service_title, s.type as service_type, s.delivery_days, s.delivery_label, s.description as service_description,
           u.name as client_name, u.id as client_id, u.phone as client_phone
    FROM requests r
    JOIN services s ON r.service_id = s.id
    JOIN users u ON r.client_id = u.id
    WHERE r.id = ?
");
$stmt->execute([$request_id]);
$request = $stmt->fetch();

if (!$request) { die("Solicitud no encontrada."); }

if (!$isAdmin && $request['client_id'] != $_SESSION['user_id']) {
    die("No tienes acceso a esta solicitud.");
}

$success = $error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_comment') {
        $message = trim($_POST['message'] ?? '');
        if ($message) {
            $pdo->prepare("INSERT INTO request_comments (request_id, user_id, message) VALUES (?, ?, ?)")
                ->execute([$request_id, $_SESSION['user_id'], $message]);
            $newCommentId = $pdo->lastInsertId();
            if (getSetting('notify_comment', '1') === '1') {
                sendRequestCommentEmail($request_id, $newCommentId, $_SESSION['user_id']);
            }
            $success = "Comentario enviado.";
        }
    } elseif ($action === 'upload_file') {
        if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'zip'];
            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) {
                $error = "Tipo de archivo no permitido.";
            } elseif ($_FILES['file']['size'] > 10 * 1024 * 1024) {
                $error = "El archivo supera los 10MB.";
            } else {
                $newName = uniqid('file_', true) . '.' . $ext;
                $destDir = __DIR__ . '/uploads/';
                move_uploaded_file($_FILES['file']['tmp_name'], $destDir . $newName);
                $pdo->prepare("INSERT INTO request_attachments (request_id, user_id, filename, original_name, file_size, mime_type) VALUES (?, ?, ?, ?, ?, ?)")
                    ->execute([$request_id, $_SESSION['user_id'], $newName, $_FILES['file']['name'], $_FILES['file']['size'], $_FILES['file']['type']]);
                $success = "Archivo subido correctamente.";
            }
        }
    } elseif ($action === 'delete_attachment' && $isAdmin) {
        $att_id = $_POST['attachment_id'];
        $row = $pdo->prepare("SELECT filename FROM request_attachments WHERE id = ?");
        $row->execute([$att_id]);
        $att = $row->fetch();
        if ($att) {
            @unlink(__DIR__ . '/uploads/' . $att['filename']);
            $pdo->prepare("DELETE FROM request_attachments WHERE id = ?")->execute([$att_id]);
            $success = "Archivo eliminado.";
        }
    } elseif ($action === 'delete_comment' && $isAdmin) {
        $pdo->prepare("DELETE FROM request_comments WHERE id = ?")->execute([$_POST['comment_id']]);
        $success = "Comentario eliminado.";
    } elseif ($action === 'update_status' && $isAdmin) {
        $newStatus = $_POST['status'];
        $pdo->prepare("UPDATE requests SET status = ? WHERE id = ?")->execute([$newStatus, $request_id]);
        if (getSetting('notify_status', '1') === '1') {
            sendRequestStatusEmail($request_id, $newStatus);
        }
        header("Location: request_view.php?id=$request_id&updated=1");
        exit;
    }

    if ($success) {
        header("Location: request_view.php?id=$request_id&ok=1");
        exit;
    }
}

if (isset($_GET['ok'])) $success = "Accion realizada correctamente.";
if (isset($_GET['updated'])) $success = "Estado actualizado.";

$comments = $pdo->prepare("
    SELECT rc.*, u.name as user_name, u.role as user_role,
           COALESCE(r.access_level, CASE WHEN u.role = 'admin' THEN 'admin' ELSE 'client' END) as user_access_level
    FROM request_comments rc
    JOIN users u ON rc.user_id = u.id
    LEFT JOIN roles r ON r.slug = u.role
    WHERE rc.request_id = ?
    ORDER BY rc.created_at ASC
");
$comments->execute([$request_id]);
$comments = $comments->fetchAll();

$attachments = $pdo->prepare("
    SELECT ra.*, u.name as user_name, u.role as user_role,
           COALESCE(r.access_level, CASE WHEN u.role = 'admin' THEN 'admin' ELSE 'client' END) as user_access_level
    FROM request_attachments ra
    JOIN users u ON ra.user_id = u.id
    LEFT JOIN roles r ON r.slug = u.role
    WHERE ra.request_id = ?
    ORDER BY ra.created_at ASC
");
$attachments->execute([$request_id]);
$attachments = $attachments->fetchAll();

function fileSizeHuman($bytes) {
    if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
    if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
    return $bytes . ' B';
}

function fileIconSvg($mime) {
    if (str_contains($mime, 'pdf')) return ['#DC2626', 'PDF'];
    if (str_contains($mime, 'image')) return ['#2563EB', 'IMG'];
    if (str_contains($mime, 'word') || str_contains($mime, 'document')) return ['#1D4ED8', 'DOC'];
    if (str_contains($mime, 'sheet') || str_contains($mime, 'excel')) return ['#16A34A', 'XLS'];
    if (str_contains($mime, 'zip')) return ['#7C3AED', 'ZIP'];
    return ['#64748B', 'FILE'];
}

$backUrl = $isAdmin ? "client_details.php?id={$request['client_id']}" : "client_dashboard.php";

$statusOptions = [
    'pendiente'  => 'Pendiente',
    'en_proceso' => 'En proceso',
    'en_revision'=> 'En revision',
];
if ($request['service_type'] === 'iguala') {
    $statusOptions['presentado'] = 'Presentado';
} else {
    $statusOptions['completado'] = 'Completado';
}

$page_title = $request['service_title'];
$page_subtitle = ($request['service_type'] === 'iguala' ? 'Iguala mensual' : 'Solicitud puntual') . ($isAdmin ? ' - ' . $request['client_name'] : '');
$page_actions = '<a href="' . $backUrl . '" class="btn-soft text-sm inline-flex items-center gap-2">
    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
    Volver
</a>';

$head_extra = "
.chat-bubble-me { border-radius: 18px 18px 4px 18px; }
.chat-bubble-other { border-radius: 18px 18px 18px 4px; }
#drop-zone.drag-over { border-color: #2563EB; background: #EFF6FF; }
";

$main_max = 'max-w-6xl';
include 'components/layout_start.php';
?>

<?php if ($success): ?>
<div class="mb-4 rounded-2xl bg-emerald-50 px-4 py-3 border border-emerald-100 text-sm font-medium text-emerald-800"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="mb-4 rounded-2xl bg-red-50 px-4 py-3 border border-red-100 text-sm font-medium text-red-700"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Request header card -->
<div class="surface-card p-6 mb-4">
    <div class="flex flex-col lg:flex-row lg:items-center gap-4 justify-between">
        <div class="flex items-center gap-4">
            <div class="w-12 h-12 rounded-2xl <?= $request['service_type'] === 'iguala' ? 'bg-blue-50 text-blue-600' : 'bg-indigo-50 text-indigo-600' ?> flex items-center justify-center">
                <?php if ($request['service_type'] === 'iguala'): ?>
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                <?php else: ?>
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                <?php endif; ?>
            </div>
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <span class="text-[10px] font-bold uppercase tracking-wider rounded-md px-2 py-0.5 <?= $request['service_type'] === 'iguala' ? 'bg-blue-50 text-blue-700' : 'bg-indigo-50 text-indigo-700' ?>">
                        <?= $request['service_type'] === 'iguala' ? 'Iguala' : 'Puntual' ?>
                    </span>
                    <?= getStatusBadge($request['status']) ?>
                </div>
                <h2 class="text-lg font-extrabold text-slate-900"><?= htmlspecialchars($request['service_title']) ?></h2>
                <?php
                $reqSvcInfo = ['delivery_label' => $request['delivery_label'] ?? null, 'delivery_days' => $request['delivery_days'] ?? null];
                $reqDeliveryText = formatServiceDelivery($reqSvcInfo);
                $isCompletedReq = in_array($request['status'], ['completado','presentado'], true);
                $isOverdueReq = !$isCompletedReq && !empty($request['estimated_delivery_date']) && strtotime($request['estimated_delivery_date']) < strtotime('today');
                ?>
                <div class="mt-1 flex items-center gap-3 flex-wrap text-xs">
                    <?php if ($request['period']): ?>
                    <span class="text-slate-500">Periodo: <span class="font-semibold text-slate-700"><?= htmlspecialchars($request['period']) ?></span></span>
                    <?php endif; ?>
                    <?php if ($request['estimated_delivery_date']): ?>
                    <span class="text-slate-500">
                        <?= $isCompletedReq ? 'Entregado el' : ($isOverdueReq ? '<span class="text-red-600 font-bold">Retrasado · Estimado</span>' : 'Entrega estimada') ?>:
                        <span class="font-semibold <?= $isOverdueReq && !$isCompletedReq ? 'text-red-600' : 'text-slate-800' ?>"><?= date('d/m/Y', strtotime($request['estimated_delivery_date'])) ?></span>
                    </span>
                    <?php endif; ?>
                    <?php if ($reqDeliveryText !== '' && $request['service_type'] !== 'iguala'): ?>
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full bg-blue-50 text-blue-700 font-semibold">
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        SLA: <?= htmlspecialchars($reqDeliveryText) ?>
                    </span>
                    <?php endif; ?>
                </div>
                <?php if (!empty($request['service_description'])): ?>
                <p class="mt-2 text-xs text-slate-600 leading-relaxed"><?= nl2br(htmlspecialchars($request['service_description'])) ?></p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($isAdmin): ?>
        <form action="request_view.php?id=<?= $request_id ?>" method="POST">
            <input type="hidden" name="action" value="update_status">
            <label class="field-label">Cambiar estado</label>
            <select name="status" onchange="this.form.submit()" class="field py-2.5 text-sm">
                <?php foreach ($statusOptions as $val => $label): ?>
                <option value="<?= $val ?>" <?= $request['status'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

    <!-- Conversation -->
    <div class="lg:col-span-2 surface-card overflow-hidden flex flex-col">
        <div class="px-6 py-5 border-b border-stone-100 flex items-center justify-between">
            <div>
                <h3 class="text-base font-bold text-slate-900">Conversacion</h3>
                <p class="text-xs text-slate-500"><?= count($comments) ?> mensaje(s)</p>
            </div>
        </div>

        <div class="p-6 space-y-4 min-h-[300px] max-h-[600px] overflow-y-auto scroll-area" id="chat-area">
            <?php if (empty($comments)): ?>
            <div class="py-12 text-center text-slate-400">
                <div class="w-12 h-12 mx-auto rounded-full bg-stone-100 flex items-center justify-center mb-3">
                    <svg class="w-5 h-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/></svg>
                </div>
                <p class="text-sm">Aun no hay mensajes. Inicia la conversacion.</p>
            </div>
            <?php endif; ?>

            <?php foreach ($comments as $comment):
                $isMe = $comment['user_id'] == $_SESSION['user_id'];
                $isAdminMsg = $comment['user_access_level'] === 'admin';
            ?>
            <div class="flex <?= $isMe ? 'flex-row-reverse' : 'flex-row' ?> gap-3 items-end group">
                <div class="flex-none w-9 h-9 rounded-full <?= $isAdminMsg ? 'bg-blue-50 text-blue-700' : 'bg-indigo-50 text-indigo-700' ?> flex items-center justify-center text-xs font-bold shrink-0">
                    <?= htmlspecialchars(substr(strtoupper($comment['user_name']), 0, 1)) ?>
                </div>
                <div class="max-w-[78%] <?= $isMe ? 'items-end' : 'items-start' ?> flex flex-col gap-1">
                    <span class="text-[11px] font-semibold text-slate-400"><?= $isMe ? 'Tu' : htmlspecialchars($comment['user_name']) ?></span>
                    <div class="<?= $isMe ? 'bg-slate-900 text-white chat-bubble-me' : 'bg-stone-100 text-slate-800 chat-bubble-other' ?> px-4 py-3 text-sm leading-relaxed">
                        <?= nl2br(htmlspecialchars($comment['message'])) ?>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-[11px] text-slate-400"><?= date('d/m/Y H:i', strtotime($comment['created_at'])) ?></span>
                        <?php if ($isAdmin): ?>
                        <form action="request_view.php?id=<?= $request_id ?>" method="POST" class="opacity-0 group-hover:opacity-100 transition-opacity">
                            <input type="hidden" name="action" value="delete_comment">
                            <input type="hidden" name="comment_id" value="<?= $comment['id'] ?>">
                            <button type="submit" class="text-[11px] text-red-400 hover:text-red-600 font-semibold" onclick="return confirm('Eliminar este mensaje?')">Eliminar</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="px-6 py-4 border-t border-stone-100">
            <form action="request_view.php?id=<?= $request_id ?>" method="POST" class="flex gap-3 items-end">
                <input type="hidden" name="action" value="add_comment">
                <textarea name="message" rows="2" required placeholder="Escribe un mensaje..."
                          class="field text-sm" style="resize: vertical;"></textarea>
                <button type="submit" class="btn-dark text-sm shrink-0 inline-flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5"/></svg>
                    Enviar
                </button>
            </form>
        </div>
    </div>

    <!-- Sidebar: Files + Details -->
    <div class="space-y-6">
        <!-- Files -->
        <div class="surface-card overflow-hidden">
            <div class="px-5 py-4 border-b border-stone-100 flex items-center justify-between">
                <h3 class="text-sm font-bold text-slate-900">Archivos</h3>
                <span class="text-xs text-slate-400"><?= count($attachments) ?></span>
            </div>
            <div class="p-5">
                <form action="request_view.php?id=<?= $request_id ?>" method="POST" enctype="multipart/form-data" id="upload-form">
                    <input type="hidden" name="action" value="upload_file">
                    <div id="drop-zone" class="border-2 border-dashed border-stone-200 rounded-2xl p-5 text-center cursor-pointer hover:border-blue-400 hover:bg-blue-50/40 transition-colors" onclick="document.getElementById('fileInput').click()">
                        <div class="w-10 h-10 mx-auto rounded-2xl bg-stone-100 flex items-center justify-center mb-2">
                            <svg class="w-5 h-5 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 7.5m0 0L7.5 12m4.5-4.5V21"/></svg>
                        </div>
                        <p class="text-xs font-semibold text-slate-700">Sube un archivo</p>
                        <p class="text-[11px] text-slate-400 mt-1">PDF, imagenes, Word, Excel, ZIP - Max 10MB</p>
                        <input type="file" id="fileInput" name="file" class="hidden" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.zip" onchange="this.form.submit()">
                    </div>
                </form>

                <div class="mt-4 space-y-2">
                    <?php if (empty($attachments)): ?>
                    <p class="text-xs text-slate-400 text-center py-4">Sin archivos adjuntos.</p>
                    <?php endif; ?>
                    <?php foreach ($attachments as $att):
                        [$color, $label] = fileIconSvg($att['mime_type']);
                    ?>
                    <div class="flex items-center gap-3 p-3 rounded-2xl bg-stone-50 group">
                        <div class="w-9 h-9 rounded-xl flex items-center justify-center text-white text-[10px] font-extrabold shrink-0" style="background: <?= $color ?>"><?= $label ?></div>
                        <div class="flex-1 min-w-0">
                            <p class="text-xs font-semibold text-slate-800 truncate"><?= htmlspecialchars($att['original_name']) ?></p>
                            <p class="text-[11px] text-slate-400 truncate"><?= fileSizeHuman($att['file_size']) ?> &bull; <?= htmlspecialchars($att['user_name']) ?> &bull; <?= date('d/m/Y', strtotime($att['created_at'])) ?></p>
                        </div>
                        <div class="flex items-center gap-1 shrink-0">
                            <a href="uploads/<?= htmlspecialchars($att['filename']) ?>" download="<?= htmlspecialchars($att['original_name']) ?>" class="p-1.5 text-slate-400 hover:text-blue-600 rounded-lg hover:bg-white" title="Descargar">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            </a>
                            <?php if ($isAdmin): ?>
                            <form action="request_view.php?id=<?= $request_id ?>" method="POST">
                                <input type="hidden" name="action" value="delete_attachment">
                                <input type="hidden" name="attachment_id" value="<?= $att['id'] ?>">
                                <button type="submit" onclick="return confirm('Eliminar archivo?')" class="p-1.5 text-slate-300 hover:text-red-500 rounded-lg hover:bg-white" title="Eliminar">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Details -->
        <div class="surface-card p-5">
            <h4 class="text-sm font-bold text-slate-900 mb-3">Detalles</h4>
            <dl class="space-y-2.5 text-sm">
                <div class="flex justify-between">
                    <dt class="text-slate-500 text-xs">Tipo</dt>
                    <dd class="font-semibold text-slate-800 text-xs"><?= $request['service_type'] === 'iguala' ? 'Iguala mensual' : 'Solicitud puntual' ?></dd>
                </div>
                <?php if ($request['period']): ?>
                <div class="flex justify-between">
                    <dt class="text-slate-500 text-xs">Periodo</dt>
                    <dd class="font-semibold text-slate-800 text-xs"><?= htmlspecialchars($request['period']) ?></dd>
                </div>
                <?php endif; ?>
                <?php if ($request['estimated_delivery_date']): ?>
                <div class="flex justify-between">
                    <dt class="text-slate-500 text-xs">Entrega</dt>
                    <dd class="font-semibold text-slate-800 text-xs"><?= date('d/m/Y', strtotime($request['estimated_delivery_date'])) ?></dd>
                </div>
                <?php endif; ?>
                <div class="flex justify-between">
                    <dt class="text-slate-500 text-xs">Creado</dt>
                    <dd class="font-semibold text-slate-800 text-xs"><?= date('d/m/Y', strtotime($request['created_at'])) ?></dd>
                </div>
                <?php if ($isAdmin): ?>
                <div class="flex justify-between pt-2 border-t border-stone-100">
                    <dt class="text-slate-500 text-xs">Cliente</dt>
                    <dd class="font-semibold text-slate-800 text-xs"><?= htmlspecialchars($request['client_name']) ?></dd>
                </div>
                <?php endif; ?>
            </dl>
        </div>
    </div>
</div>

<script>
const dropZone = document.getElementById('drop-zone');
const fileInput = document.getElementById('fileInput');

dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
dropZone.addEventListener('drop', e => {
    e.preventDefault();
    dropZone.classList.remove('drag-over');
    if (e.dataTransfer.files.length > 0) {
        fileInput.files = e.dataTransfer.files;
        document.getElementById('upload-form').submit();
    }
});

const chatArea = document.getElementById('chat-area');
chatArea.scrollTop = chatArea.scrollHeight;
</script>

<?php include 'components/layout_end.php'; ?>
