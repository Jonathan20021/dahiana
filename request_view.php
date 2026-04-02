<?php
require_once 'config.php';
requireAuth();

$request_id = $_GET['id'] ?? null;
$isAdmin = canAccessArea($_SESSION['role'], 'admin');
if (!$request_id) { header('Location: ' . getDashboardForRole($_SESSION['role'])); exit; }

// Fetch request with client and service details
$stmt = $pdo->prepare("
    SELECT r.*, s.title as service_title, s.type as service_type,
           u.name as client_name, u.id as client_id, u.phone as client_phone
    FROM requests r
    JOIN services s ON r.service_id = s.id
    JOIN users u ON r.client_id = u.id
    WHERE r.id = ?
");
$stmt->execute([$request_id]);
$request = $stmt->fetch();

if (!$request) { die("Solicitud no encontrada."); }

// Security: client-side users can only see their own requests
if (!$isAdmin && $request['client_id'] != $_SESSION['user_id']) {
    die("No tienes acceso a esta solicitud.");
}

$success = $error = null;

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_comment') {
        $message = trim($_POST['message'] ?? '');
        if ($message) {
            $stmt = $pdo->prepare("INSERT INTO request_comments (request_id, user_id, message) VALUES (?, ?, ?)");
            $stmt->execute([$request_id, $_SESSION['user_id'], $message]);
            $success = "Comentario enviado.";
        }
    } elseif ($action === 'upload_file') {
        if (isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK) {
            $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx', 'xls', 'xlsx', 'zip'];
            $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed)) {
                $error = "Tipo de archivo no permitido. Usa: PDF, imágenes, Word, Excel o ZIP.";
            } elseif ($_FILES['file']['size'] > 10 * 1024 * 1024) {
                $error = "El archivo no puede pesar más de 10MB.";
            } else {
                $newName = uniqid('file_', true) . '.' . $ext;
                $destDir = __DIR__ . '/uploads/';
                move_uploaded_file($_FILES['file']['tmp_name'], $destDir . $newName);
                $stmt = $pdo->prepare("INSERT INTO request_attachments (request_id, user_id, filename, original_name, file_size, mime_type) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$request_id, $_SESSION['user_id'], $newName, $_FILES['file']['name'], $_FILES['file']['size'], $_FILES['file']['type']]);
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
        $pdo->prepare("UPDATE requests SET status = ? WHERE id = ?")->execute([$_POST['status'], $request_id]);
        header("Location: request_view.php?id=$request_id&updated=1");
        exit;
    }
    
    // Redirect to avoid re-submitting
    if ($success) {
        header("Location: request_view.php?id=$request_id&ok=1");
        exit;
    }
}

if (isset($_GET['ok'])) $success = "Acción realizada correctamente.";
if (isset($_GET['updated'])) $success = "Estado actualizado.";

// Fetch comments
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

// Fetch attachments
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

function fileIcon($mime) {
    if (str_contains($mime, 'pdf')) return '📄';
    if (str_contains($mime, 'image')) return '🖼️';
    if (str_contains($mime, 'word') || str_contains($mime, 'document')) return '📝';
    if (str_contains($mime, 'sheet') || str_contains($mime, 'excel')) return '📊';
    if (str_contains($mime, 'zip')) return '📦';
    return '📎';
}

$backUrl = $isAdmin ? "client_details.php?id={$request['client_id']}" : "client_dashboard.php";

$statusOptions = [
    'pendiente'  => '🔴 Pendiente',
    'en_proceso' => '🟡 En proceso',
    'en_revision'=> '🔵 En revisión',
];
if ($request['service_type'] === 'iguala') {
    $statusOptions['presentado'] = '🟢 Presentado';
} else {
    $statusOptions['completado'] = '🟢 Completado';
}
?>
<!DOCTYPE html>
<html lang="es" class="h-full bg-slate-50/50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($request['service_title']) ?> - Portal Asesoría</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap');
        body { font-family: 'Outfit', sans-serif; }
        .chat-bubble-admin { border-radius: 18px 18px 4px 18px; }
        .chat-bubble-client { border-radius: 18px 18px 18px 4px; }
        #drop-zone.drag-over { border-color: #3b82f6; background: #eff6ff; }
    </style>
</head>
<body class="h-full">
    <?php include 'components/header.php'; ?>
    <?php include 'components/sidebar.php'; ?>

    <main class="lg:pl-72 py-8">
        <div class="px-4 sm:px-6 lg:px-8 max-w-5xl mx-auto">

            <!-- Breadcrumb -->
            <div class="mb-6">
                <a href="<?= $backUrl ?>" class="inline-flex items-center gap-2 text-sm font-medium text-slate-500 hover:text-blue-600 transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18" /></svg>
                    Volver
                </a>
            </div>

            <?php if ($success): ?>
            <div class="mb-6 rounded-2xl bg-green-50 p-4 border border-green-100"><p class="text-sm font-medium text-green-800"><?= htmlspecialchars($success) ?></p></div>
            <?php endif; ?>
            <?php if ($error): ?>
            <div class="mb-6 rounded-2xl bg-red-50 p-4 border border-red-100"><p class="text-sm font-medium text-red-800"><?= htmlspecialchars($error) ?></p></div>
            <?php endif; ?>

            <!-- Header Card -->
            <div class="bg-white rounded-3xl p-6 shadow-sm border border-slate-100 mb-6">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <div class="flex items-center gap-2 mb-2">
                            <span class="inline-flex rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600 uppercase tracking-wider">
                                <?= $request['service_type'] === 'iguala' ? 'Iguala' : 'Puntual' ?>
                            </span>
                            <?= getStatusBadge($request['status']) ?>
                        </div>
                        <h1 class="text-xl font-bold text-slate-900"><?= htmlspecialchars($request['service_title']) ?></h1>
                        <?php if ($isAdmin): ?>
                        <p class="text-sm text-slate-500 mt-1">Cliente: <strong><?= htmlspecialchars($request['client_name']) ?></strong></p>
                        <?php endif; ?>
                    </div>

                    <?php if ($isAdmin): ?>
                    <form action="request_view.php?id=<?= $request_id ?>" method="POST" class="flex items-center gap-3">
                        <input type="hidden" name="action" value="update_status">
                        <select name="status" onchange="this.form.submit()" class="rounded-xl border-0 py-2.5 pl-3 pr-8 text-sm font-medium text-slate-700 ring-1 ring-inset ring-slate-200 focus:ring-2 focus:ring-blue-600 bg-slate-50">
                            <?php foreach ($statusOptions as $val => $label): ?>
                            <option value="<?= $val ?>" <?= $request['status'] === $val ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <?php endif; ?>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

                <!-- Activity Feed (left, wider) -->
                <div class="lg:col-span-2 space-y-6">

                    <!-- Comments Section -->
                    <div class="bg-white rounded-3xl shadow-sm border border-slate-100 overflow-hidden">
                        <div class="px-6 py-5 border-b border-slate-100 bg-slate-50/50 flex items-center gap-3">
                            <div class="p-2 bg-blue-50 text-blue-600 rounded-xl">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" /></svg>
                            </div>
                            <h3 class="text-base font-semibold text-slate-900">Mensajes y Comentarios</h3>
                            <span class="ml-auto text-xs text-slate-400"><?= count($comments) ?> mensaje(s)</span>
                        </div>

                        <!-- Chat Area -->
                        <div class="p-6 space-y-4 min-h-[200px]" id="chat-area">
                            <?php if (empty($comments)): ?>
                            <div class="py-8 text-center text-slate-400">
                                <svg class="w-10 h-10 mx-auto mb-2 text-slate-200" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" /></svg>
                                <p class="text-sm">Aún no hay mensajes. ¡Inicia la conversación!</p>
                            </div>
                            <?php endif; ?>

                            <?php foreach ($comments as $comment): 
                                $isMe = $comment['user_id'] == $_SESSION['user_id'];
                                $isAdminMsg = $comment['user_access_level'] === 'admin';
                            ?>
                            <div class="flex <?= $isMe ? 'flex-row-reverse' : 'flex-row' ?> gap-3 items-end group">
                                <!-- Avatar -->
                                <div class="flex-none w-8 h-8 rounded-full <?= $isAdminMsg ? 'bg-blue-100' : 'bg-indigo-100' ?> flex items-center justify-center text-xs font-bold <?= $isAdminMsg ? 'text-blue-700' : 'text-indigo-700' ?> shrink-0">
                                    <?= substr(strtoupper($comment['user_name']), 0, 1) ?>
                                </div>
                                <!-- Bubble -->
                                <div class="max-w-[75%] <?= $isMe ? 'items-end' : 'items-start' ?> flex flex-col gap-1">
                                    <span class="text-xs font-medium text-slate-400 <?= $isMe ? 'text-right' : 'text-left' ?>"><?= $isMe ? 'Tú' : htmlspecialchars($comment['user_name']) ?></span>
                                    <div class="<?= $isMe ? 'bg-slate-900 text-white chat-bubble-admin' : 'bg-slate-100 text-slate-800 chat-bubble-client' ?> px-4 py-3 text-sm leading-relaxed">
                                        <?= nl2br(htmlspecialchars($comment['message'])) ?>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs text-slate-400"><?= date('d/m/Y H:i', strtotime($comment['created_at'])) ?></span>
                                        <?php if ($isAdmin): ?>
                                        <form action="request_view.php?id=<?= $request_id ?>" method="POST" class="opacity-0 group-hover:opacity-100 transition-opacity">
                                            <input type="hidden" name="action" value="delete_comment">
                                            <input type="hidden" name="comment_id" value="<?= $comment['id'] ?>">
                                            <button type="submit" class="text-xs text-red-400 hover:text-red-600" onclick="return confirm('¿Eliminar este mensaje?')">Eliminar</button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Comment Input -->
                        <div class="px-6 pb-6">
                            <form action="request_view.php?id=<?= $request_id ?>" method="POST">
                                <input type="hidden" name="action" value="add_comment">
                                <div class="flex gap-3 items-end">
                                    <div class="flex-1">
                                        <textarea name="message" rows="2" required placeholder="Escribe un mensaje..." class="block w-full resize-none rounded-2xl border-0 py-3 px-4 text-slate-900 shadow-sm ring-1 ring-inset ring-slate-200 focus:ring-2 focus:ring-blue-500 sm:text-sm placeholder:text-slate-400"></textarea>
                                    </div>
                                    <button type="submit" class="shrink-0 inline-flex items-center gap-2 rounded-2xl bg-slate-900 px-4 py-3 text-sm font-semibold text-white hover:bg-slate-800 transition-all">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" /></svg>
                                        Enviar
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Right Panel: Files -->
                <div class="space-y-6">

                    <!-- Upload Section -->
                    <div class="bg-white rounded-3xl shadow-sm border border-slate-100 overflow-hidden">
                        <div class="px-5 py-4 border-b border-slate-100 bg-slate-50/50 flex items-center gap-3">
                            <div class="p-2 bg-indigo-50 text-indigo-600 rounded-xl">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18.375 12.739l-7.693 7.693a4.5 4.5 0 01-6.364-6.364l10.94-10.94A3 3 0 1119.5 7.372L8.552 18.32m.009-.01l-.01.01m5.699-9.941l-7.81 7.81a1.5 1.5 0 002.112 2.13" /></svg>
                            </div>
                            <h3 class="text-sm font-semibold text-slate-900">Archivos</h3>
                        </div>

                        <div class="p-5">
                            <!-- Upload Form -->
                            <form action="request_view.php?id=<?= $request_id ?>" method="POST" enctype="multipart/form-data" id="upload-form">
                                <input type="hidden" name="action" value="upload_file">
                                <div id="drop-zone" class="border-2 border-dashed border-slate-200 rounded-2xl p-5 text-center cursor-pointer hover:border-blue-400 hover:bg-blue-50/50 transition-colors" onclick="document.getElementById('fileInput').click()">
                                    <svg class="w-8 h-8 text-slate-300 mx-auto mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5" /></svg>
                                    <p class="text-xs font-medium text-slate-500">Haz clic o arrastra un archivo</p>
                                    <p class="text-xs text-slate-400 mt-1">PDF, imágenes, Word, Excel, ZIP · Máx 10MB</p>
                                    <input type="file" id="fileInput" name="file" class="hidden" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.zip" onchange="this.form.submit()">
                                </div>
                            </form>

                            <!-- Files List -->
                            <div class="mt-5 space-y-2">
                                <?php if (empty($attachments)): ?>
                                <p class="text-xs text-slate-400 text-center py-4">No hay archivos adjuntos aún.</p>
                                <?php endif; ?>
                                <?php foreach ($attachments as $att): ?>
                                <div class="flex items-center gap-3 p-3 rounded-xl bg-slate-50 border border-slate-100 hover:border-slate-200 transition-colors group">
                                    <span class="text-xl shrink-0"><?= fileIcon($att['mime_type']) ?></span>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-xs font-semibold text-slate-800 truncate"><?= htmlspecialchars($att['original_name']) ?></p>
                                        <p class="text-xs text-slate-400"><?= fileSizeHuman($att['file_size']) ?> &bull; <?= htmlspecialchars($att['user_name']) ?></p>
                                        <p class="text-xs text-slate-400"><?= date('d/m/Y H:i', strtotime($att['created_at'])) ?></p>
                                    </div>
                                    <div class="flex flex-col gap-1 shrink-0">
                                        <?php if (str_contains($att['mime_type'], 'pdf') || str_contains($att['mime_type'], 'image')): ?>
                                        <a href="uploads/<?= htmlspecialchars($att['filename']) ?>" target="_blank" class="p-1.5 text-slate-400 hover:text-indigo-600 rounded-lg hover:bg-indigo-50 transition-colors" title="Ver online">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.644C3.301 8.844 6.429 6.75 10 6.75s6.699 2.094 7.964 4.928a1.012 1.012 0 010 .644c-1.265 2.834-4.393 4.928-7.964 4.928s-6.699-2.094-7.964-4.928z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                        </a>
                                        <?php endif; ?>
                                        <a href="uploads/<?= htmlspecialchars($att['filename']) ?>" download="<?= htmlspecialchars($att['original_name']) ?>" class="p-1.5 text-slate-400 hover:text-blue-600 rounded-lg hover:bg-blue-50 transition-colors" title="Descargar">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                                        </a>
                                        <?php if ($isAdmin): ?>
                                        <form action="request_view.php?id=<?= $request_id ?>" method="POST">
                                            <input type="hidden" name="action" value="delete_attachment">
                                            <input type="hidden" name="attachment_id" value="<?= $att['id'] ?>">
                                            <button type="submit" onclick="return confirm('¿Eliminar archivo?')" class="p-1.5 text-slate-300 hover:text-red-500 rounded-lg hover:bg-red-50 transition-colors" title="Eliminar">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Request Info Card -->
                    <div class="bg-white rounded-3xl shadow-sm border border-slate-100 p-5">
                        <h4 class="text-sm font-semibold text-slate-700 mb-3">Detalles del Trámite</h4>
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-slate-500">Tipo</dt>
                                <dd class="font-medium text-slate-800"><?= $request['service_type'] === 'iguala' ? 'Iguala Mensual' : 'Solicitud Puntual' ?></dd>
                            </div>
                            <?php if ($request['period']): ?>
                            <div class="flex justify-between">
                                <dt class="text-slate-500">Período</dt>
                                <dd class="font-medium text-slate-800"><?= htmlspecialchars($request['period']) ?></dd>
                            </div>
                            <?php endif; ?>
                            <?php if ($request['estimated_delivery_date']): ?>
                            <div class="flex justify-between">
                                <dt class="text-slate-500">Entrega Estimada</dt>
                                <dd class="font-medium text-slate-800"><?= date('d/m/Y', strtotime($request['estimated_delivery_date'])) ?></dd>
                            </div>
                            <?php endif; ?>
                            <div class="flex justify-between">
                                <dt class="text-slate-500">Creado el</dt>
                                <dd class="font-medium text-slate-800"><?= date('d/m/Y', strtotime($request['created_at'])) ?></dd>
                            </div>
                        </dl>
                    </div>
                </div>

            </div>
        </div>
    </main>

    <script>
        // Drag and drop
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

        // Auto-scroll chat to bottom
        const chatArea = document.getElementById('chat-area');
        chatArea.scrollTop = chatArea.scrollHeight;
    </script>
</body>
</html>
