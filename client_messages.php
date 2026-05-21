<?php
require_once 'config.php';
requireAuth('client');

$client_id = (int)$_SESSION['user_id'];

// Bootstrap tabla de mensajes generales (no atados a una solicitud)
$pdo->exec("
    CREATE TABLE IF NOT EXISTS general_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_id INT NOT NULL,
        user_id INT NOT NULL,
        message TEXT NOT NULL,
        read_by_client TINYINT(1) DEFAULT 0,
        read_by_admin TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(client_id), INDEX(created_at)
    )
");

$success = $error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_general') {
    $message = trim($_POST['message'] ?? '');
    if ($message !== '') {
        $pdo->prepare("INSERT INTO general_messages (client_id, user_id, message, read_by_client) VALUES (?,?,?,1)")
            ->execute([$client_id, $client_id, $message]);
        // Marcar como no leido para admin
        logClientActivity($client_id, 'message', 'Cliente envio mensaje general');
        header('Location: client_messages.php?sent=1#chat-general');
        exit;
    }
}

// Marcar como leido lo recibido del admin
$pdo->prepare("UPDATE general_messages SET read_by_client=1 WHERE client_id=? AND user_id<>? AND read_by_client=0")
    ->execute([$client_id, $client_id]);

// Mensajes del thread general
$generalStmt = $pdo->prepare("
    SELECT gm.*, u.name as author_name, u.role as author_role
    FROM general_messages gm
    JOIN users u ON u.id = gm.user_id
    WHERE gm.client_id = ?
    ORDER BY gm.created_at ASC
");
$generalStmt->execute([$client_id]);
$generalMessages = $generalStmt->fetchAll();

// Tramites con conversacion
$threadsStmt = $pdo->prepare("
    SELECT r.id, r.status, r.updated_at, s.title as service_title, s.type as service_type,
           (SELECT COUNT(*) FROM request_comments WHERE request_id = r.id) as msg_count,
           (SELECT MAX(created_at) FROM request_comments WHERE request_id = r.id) as last_at,
           (SELECT message FROM request_comments WHERE request_id = r.id ORDER BY created_at DESC LIMIT 1) as last_msg,
           (SELECT u.name FROM request_comments rc JOIN users u ON u.id = rc.user_id WHERE rc.request_id = r.id ORDER BY rc.created_at DESC LIMIT 1) as last_author
    FROM requests r
    JOIN services s ON r.service_id = s.id
    WHERE r.client_id = ?
    ORDER BY (last_at IS NULL) ASC, last_at DESC, r.updated_at DESC
    LIMIT 100
");
$threadsStmt->execute([$client_id]);
$threads = $threadsStmt->fetchAll();

// Datos de la asesoria
$companyName  = trim(getSetting('company_name', 'Portal Asesoria'));
$companyPhone = trim(getSetting('company_phone', ''));

// Detectar admin "principal" para el avatar (el primero admin creado)
$adminStmt = $pdo->query("SELECT name FROM users WHERE role='admin' ORDER BY id ASC LIMIT 1");
$adminUser = $adminStmt->fetch();
$adminName = $adminUser['name'] ?? 'Asesor';

$page_title = 'Mensajes';
$page_subtitle = 'Chat con tu asesoria y comentarios por tramite.';
include 'components/layout_start.php';
?>

<?php if (isset($_GET['sent'])): ?>
<div class="mb-3 rounded-2xl bg-emerald-50 px-4 py-3 border border-emerald-100 text-sm font-medium text-emerald-800 flex items-center gap-2">
    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
    Mensaje enviado a tu asesor.
</div>
<?php endif; ?>

<div class="chat-layout">
    <!-- Sidebar de conversaciones -->
    <aside class="surface-card chat-threads">
        <div class="chat-threads-head">
            <h3 class="text-sm font-bold text-slate-900">Conversaciones</h3>
            <p class="text-[11px] text-slate-500 mt-0.5"><?= count($threads) + 1 ?> hilos activos</p>
        </div>
        <div class="chat-threads-list">
            <!-- Hilo general (siempre primero) -->
            <a href="#chat-general" class="chat-thread chat-thread-general is-active" data-thread="general">
                <div class="chat-thread-avatar chat-avatar-brand">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 8l9 6 9-6M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>
                </div>
                <div class="chat-thread-main">
                    <p class="chat-thread-title"><?= htmlspecialchars($companyName) ?></p>
                    <p class="chat-thread-preview">Conversacion general con el equipo</p>
                </div>
                <?php if (!empty($generalMessages)): ?>
                <span class="chat-thread-time"><?= date('H:i', strtotime(end($generalMessages)['created_at'])) ?></span>
                <?php endif; ?>
            </a>

            <?php if (!empty($threads)): ?>
            <p class="chat-threads-divider">Por tramite</p>
            <?php foreach ($threads as $t):
                $hasMsg = (int)$t['msg_count'] > 0;
                $colorByStatus = match($t['status']) {
                    'pendiente'   => 'amber',
                    'en_proceso'  => 'blue',
                    'en_revision' => 'indigo',
                    'presentado', 'completado' => 'emerald',
                    default       => 'slate',
                };
            ?>
            <a href="request_view.php?id=<?= (int)$t['id'] ?>#comments" class="chat-thread">
                <div class="chat-thread-avatar chat-avatar-<?= $colorByStatus ?>">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                </div>
                <div class="chat-thread-main">
                    <p class="chat-thread-title"><?= htmlspecialchars($t['service_title']) ?></p>
                    <p class="chat-thread-preview">
                        <?php if ($hasMsg && $t['last_msg']): ?>
                            <span class="font-semibold"><?= htmlspecialchars($t['last_author'] ?? '') ?>:</span>
                            <?= htmlspecialchars(mb_substr($t['last_msg'], 0, 60)) ?><?= mb_strlen($t['last_msg']) > 60 ? '...' : '' ?>
                        <?php else: ?>
                            <span class="text-slate-400">Sin mensajes aun. Abre para comentar.</span>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="chat-thread-meta">
                    <?php if ($t['last_at']): ?>
                    <span class="chat-thread-time"><?= date('d M', strtotime($t['last_at'])) ?></span>
                    <?php endif; ?>
                    <?php if ($hasMsg): ?>
                    <span class="chat-thread-count"><?= (int)$t['msg_count'] ?></span>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </aside>

    <!-- Conversacion general -->
    <section class="surface-card chat-window" id="chat-general">
        <header class="chat-head">
            <div class="chat-head-avatar chat-avatar-brand">
                <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M3 5a2 2 0 012-2h14a2 2 0 012 2v10a2 2 0 01-2 2H7l-4 4V5z"/></svg>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="chat-head-title"><?= htmlspecialchars($companyName) ?></h2>
                <p class="chat-head-sub">Conversacion general con el equipo</p>
            </div>
            <?php if ($companyPhone): ?>
            <a href="https://wa.me/<?= htmlspecialchars(preg_replace('/[^0-9]/','',$companyPhone)) ?>" target="_blank" class="chat-head-action" title="Abrir en WhatsApp">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51"/></svg>
            </a>
            <?php endif; ?>
        </header>

        <div class="chat-body" id="chatBody">
            <?php if (empty($generalMessages)): ?>
            <div class="chat-empty">
                <div class="chat-empty-icon">
                    <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                </div>
                <p class="font-bold text-slate-700 mt-3">Empieza una conversacion</p>
                <p class="text-[12px] text-slate-500 mt-1 max-w-sm mx-auto">Tu asesor recibira el mensaje al instante. Para consultas de un tramite especifico, abre el tramite desde la lista.</p>
            </div>
            <?php else:
                $lastDate = null;
                foreach ($generalMessages as $m):
                    $isMine = (int)$m['user_id'] === $client_id;
                    $d = date('Y-m-d', strtotime($m['created_at']));
                    if ($d !== $lastDate):
                        $lastDate = $d;
                        $dayLabel = (date('Y-m-d') === $d ? 'Hoy' : (date('Y-m-d', strtotime('-1 day')) === $d ? 'Ayer' : date('d M Y', strtotime($d))));
            ?>
                <div class="chat-day-divider"><span><?= $dayLabel ?></span></div>
            <?php endif; ?>
                <div class="chat-msg <?= $isMine ? 'chat-msg-mine' : 'chat-msg-other' ?>">
                    <?php if (!$isMine): ?>
                    <div class="chat-msg-avatar">
                        <?= htmlspecialchars(strtoupper(substr($m['author_name'], 0, 1))) ?>
                    </div>
                    <?php endif; ?>
                    <div class="chat-msg-bubble">
                        <?php if (!$isMine): ?>
                        <p class="chat-msg-author"><?= htmlspecialchars($m['author_name']) ?></p>
                        <?php endif; ?>
                        <p class="chat-msg-text"><?= nl2br(htmlspecialchars($m['message'])) ?></p>
                        <p class="chat-msg-time"><?= date('H:i', strtotime($m['created_at'])) ?></p>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <footer class="chat-input">
            <form method="POST" class="flex items-end gap-2">
                <input type="hidden" name="action" value="send_general">
                <div class="flex-1 relative">
                    <textarea name="message" rows="1" placeholder="Escribe un mensaje a tu asesor..." required
                              class="chat-textarea" id="chatMessage"
                              onkeydown="if(event.key==='Enter' && !event.shiftKey) { event.preventDefault(); this.form.requestSubmit(); }"></textarea>
                </div>
                <button type="submit" class="chat-send" title="Enviar (Enter)">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                </button>
            </form>
            <p class="text-[10px] text-slate-400 mt-2 px-1">Enter envia · Shift+Enter agrega un salto de linea</p>
        </footer>
    </section>
</div>

<style>
    .chat-layout {
        display: grid;
        grid-template-columns: 320px 1fr;
        gap: 16px;
        height: calc(100vh - 200px);
        min-height: 500px;
    }
    @media (max-width: 1023px) {
        .chat-layout { grid-template-columns: 1fr; height: auto; }
        .chat-threads { max-height: 240px; }
        .chat-window { min-height: 480px; }
    }

    .chat-threads { display: flex; flex-direction: column; overflow: hidden; }
    .chat-threads-head { padding: 16px 18px; border-bottom: 1px solid #F1F5F9; }
    .chat-threads-list { flex: 1; overflow-y: auto; padding: 6px; }
    .chat-threads-divider { font-size: 9.5px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; color: #94A3B8; padding: 10px 12px 4px; }

    .chat-thread { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 12px; transition: all .15s ease; }
    .chat-thread:hover { background: #F8FAFC; }
    .chat-thread.is-active { background: #F1F5F9; }

    .chat-thread-avatar { width: 38px; height: 38px; border-radius: 12px; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; }
    .chat-avatar-brand   { background: linear-gradient(135deg, #0F172A, #1E293B); color: #fff; }
    .chat-avatar-amber   { background: #FFFBEB; color: #B45309; }
    .chat-avatar-blue    { background: #EFF6FF; color: #2563EB; }
    .chat-avatar-indigo  { background: #EEF2FF; color: #4F46E5; }
    .chat-avatar-emerald { background: #F0FDF4; color: #15803D; }
    .chat-avatar-slate   { background: #F1F5F9; color: #475569; }

    .chat-thread-main { flex: 1; min-width: 0; }
    .chat-thread-title { font-size: 13px; font-weight: 700; color: #0F172A; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .chat-thread-preview { font-size: 11.5px; color: #64748B; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-top: 1px; }
    .chat-thread-meta { display: flex; flex-direction: column; align-items: flex-end; gap: 3px; flex-shrink: 0; }
    .chat-thread-time { font-size: 10px; color: #94A3B8; font-weight: 600; white-space: nowrap; }
    .chat-thread-count { display: inline-flex; align-items: center; justify-content: center; min-width: 18px; height: 18px; padding: 0 5px; background: #2563EB; color: #fff; border-radius: 999px; font-size: 10px; font-weight: 800; }

    .chat-window { display: flex; flex-direction: column; overflow: hidden; }
    .chat-head { display: flex; align-items: center; gap: 12px; padding: 14px 18px; border-bottom: 1px solid #F1F5F9; }
    .chat-head-avatar { width: 42px; height: 42px; border-radius: 14px; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; }
    .chat-head-title { font-size: 15px; font-weight: 800; color: #0F172A; }
    .chat-head-sub { font-size: 11.5px; color: #64748B; margin-top: 1px; }
    .chat-head-action { width: 34px; height: 34px; border-radius: 10px; background: #F0FDF4; color: #15803D; display: inline-flex; align-items: center; justify-content: center; transition: all .15s ease; }
    .chat-head-action:hover { background: #DCFCE7; transform: scale(1.05); }

    .chat-body { flex: 1; overflow-y: auto; padding: 18px 20px; background: linear-gradient(180deg, #FAFAFA, #fff); display: flex; flex-direction: column; gap: 10px; }
    .chat-empty { text-align: center; padding: 40px 20px; flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; }
    .chat-empty-icon { width: 72px; height: 72px; border-radius: 24px; background: #F1F5F9; color: #94A3B8; display: inline-flex; align-items: center; justify-content: center; }

    .chat-day-divider { display: flex; align-items: center; justify-content: center; margin: 12px 0 4px; position: relative; }
    .chat-day-divider span { font-size: 10.5px; font-weight: 700; color: #94A3B8; padding: 4px 12px; background: #F4F4F5; border-radius: 999px; }

    .chat-msg { display: flex; gap: 8px; max-width: 78%; }
    .chat-msg-mine { align-self: flex-end; flex-direction: row-reverse; }
    .chat-msg-other { align-self: flex-start; }
    .chat-msg-avatar { width: 30px; height: 30px; border-radius: 10px; background: #0F172A; color: #fff; font-size: 11px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .chat-msg-bubble {
        padding: 9px 13px;
        border-radius: 16px;
        font-size: 13px;
        line-height: 1.5;
        max-width: 100%;
        word-wrap: break-word;
    }
    .chat-msg-mine .chat-msg-bubble {
        background: #0F172A; color: #fff;
        border-bottom-right-radius: 4px;
    }
    .chat-msg-other .chat-msg-bubble {
        background: #fff; color: #0F172A;
        border: 1px solid #EEF0F2;
        border-bottom-left-radius: 4px;
    }
    .chat-msg-author { font-size: 10.5px; font-weight: 800; color: #2563EB; text-transform: uppercase; letter-spacing: 0.04em; margin-bottom: 2px; }
    .chat-msg-text { white-space: pre-wrap; }
    .chat-msg-time { font-size: 9.5px; opacity: 0.6; margin-top: 4px; text-align: right; }
    .chat-msg-mine .chat-msg-time { color: rgba(255,255,255,0.7); }

    .chat-input { padding: 12px 16px; border-top: 1px solid #F1F5F9; background: #fff; }
    .chat-textarea {
        width: 100%;
        background: #F4F4F5;
        border: 1px solid transparent;
        border-radius: 14px;
        padding: 10px 14px;
        font-size: 13.5px;
        color: #0F172A;
        font-family: inherit;
        resize: none;
        max-height: 140px;
        line-height: 1.45;
        transition: all .15s ease;
    }
    .chat-textarea:focus { outline: none; background: #fff; border-color: #0F172A; box-shadow: 0 0 0 4px rgba(15,23,42,0.06); }
    .chat-send {
        width: 42px; height: 42px;
        border-radius: 14px;
        background: #0F172A; color: #fff;
        border: 0; cursor: pointer;
        display: inline-flex; align-items: center; justify-content: center;
        transition: all .15s ease;
        flex-shrink: 0;
    }
    .chat-send:hover { background: #1E293B; transform: translateY(-1px); }
</style>

<script>
// Auto-scroll al fondo
(function() {
    const body = document.getElementById('chatBody');
    if (body) body.scrollTop = body.scrollHeight;
})();
// Auto-grow textarea
(function() {
    const ta = document.getElementById('chatMessage');
    if (!ta) return;
    ta.addEventListener('input', function() {
        this.style.height = 'auto';
        this.style.height = Math.min(140, this.scrollHeight) + 'px';
    });
})();
</script>

<?php include 'components/layout_end.php'; ?>
