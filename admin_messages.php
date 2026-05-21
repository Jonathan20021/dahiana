<?php
require_once 'config.php';
requireAuth('admin');

// Bootstrap tabla de mensajes generales (idempotente)
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

$admin_id = (int)$_SESSION['user_id'];
$selectedClient = (int)($_GET['client'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_general') {
    $clientId = (int)($_POST['client_id'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    if ($clientId > 0 && $message !== '') {
        $pdo->prepare("INSERT INTO general_messages (client_id, user_id, message, read_by_admin) VALUES (?,?,?,1)")
            ->execute([$clientId, $admin_id, $message]);
        logClientActivity($clientId, 'message', 'Asesor envio mensaje general');
        header('Location: admin_messages.php?client=' . $clientId . '#chat-area');
        exit;
    }
}

// Lista de clientes con info de mensajes
$clientsStmt = $pdo->query("
    SELECT u.id, u.name, u.email, u.phone,
        (SELECT COUNT(*) FROM general_messages WHERE client_id=u.id AND read_by_admin=0 AND user_id<>" . $admin_id . ") AS unread,
        (SELECT MAX(created_at) FROM general_messages WHERE client_id=u.id) AS last_at,
        (SELECT message FROM general_messages WHERE client_id=u.id ORDER BY created_at DESC LIMIT 1) AS last_msg,
        (SELECT user_id FROM general_messages WHERE client_id=u.id ORDER BY created_at DESC LIMIT 1) AS last_user
    FROM users u
    LEFT JOIN roles r ON r.slug = u.role
    WHERE COALESCE(r.access_level, CASE WHEN u.role='admin' THEN 'admin' ELSE 'client' END)='client'
    ORDER BY (last_at IS NULL) ASC, last_at DESC, u.name ASC
    LIMIT 200
");
$clients = $clientsStmt->fetchAll();

// Si no hay cliente seleccionado, agarrar el primero con mensajes
if (!$selectedClient && !empty($clients)) {
    foreach ($clients as $c) {
        if ($c['last_at']) { $selectedClient = (int)$c['id']; break; }
    }
    if (!$selectedClient) $selectedClient = (int)$clients[0]['id'];
}

$selectedClientData = null;
$messages = [];
if ($selectedClient) {
    $st = $pdo->prepare("SELECT id, name, email, phone FROM users WHERE id=?");
    $st->execute([$selectedClient]);
    $selectedClientData = $st->fetch();

    if ($selectedClientData) {
        $st = $pdo->prepare("
            SELECT gm.*, u.name as author_name, u.role as author_role
            FROM general_messages gm
            JOIN users u ON u.id = gm.user_id
            WHERE gm.client_id = ?
            ORDER BY gm.created_at ASC
        ");
        $st->execute([$selectedClient]);
        $messages = $st->fetchAll();

        // Marcar leidos del cliente
        $pdo->prepare("UPDATE general_messages SET read_by_admin=1 WHERE client_id=? AND user_id<>? AND read_by_admin=0")
            ->execute([$selectedClient, $admin_id]);
    }
}

$totalUnread = (int)$pdo->query("SELECT COUNT(DISTINCT client_id) FROM general_messages WHERE read_by_admin=0 AND user_id IN (SELECT id FROM users WHERE id<>" . $admin_id . ")")->fetchColumn();

$page_title = 'Mensajes';
$page_subtitle = 'Chat directo con cada cliente del portal.';
$main_max = 'max-w-7xl';
include 'components/layout_start.php';
?>

<div class="chat-layout">
    <!-- Lista clientes -->
    <aside class="surface-card chat-threads">
        <div class="chat-threads-head">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-bold text-slate-900">Conversaciones</h3>
                <?php if ($totalUnread > 0): ?>
                <span class="inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full bg-red-500 text-white text-[10px] font-bold"><?= $totalUnread ?></span>
                <?php endif; ?>
            </div>
            <div class="relative mt-2">
                <svg class="w-3.5 h-3.5 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                <input type="text" id="threadSearch" placeholder="Buscar cliente..." class="w-full text-xs pl-8 pr-3 py-2 rounded-xl bg-slate-100 border border-transparent focus:outline-none focus:bg-white focus:border-slate-900">
            </div>
        </div>
        <div class="chat-threads-list" id="threadsList">
            <?php foreach ($clients as $c):
                $isMe = (int)$c['last_user'] === $admin_id;
                $isActive = (int)$c['id'] === $selectedClient;
            ?>
            <a href="admin_messages.php?client=<?= (int)$c['id'] ?>" class="chat-thread <?= $isActive ? 'is-active' : '' ?>" data-name="<?= htmlspecialchars(strtolower($c['name'])) ?>">
                <div class="chat-thread-avatar chat-avatar-brand">
                    <?= htmlspecialchars(strtoupper(substr($c['name'], 0, 1))) ?>
                </div>
                <div class="chat-thread-main">
                    <p class="chat-thread-title"><?= htmlspecialchars($c['name']) ?></p>
                    <p class="chat-thread-preview">
                        <?php if ($c['last_msg']): ?>
                            <?php if ($isMe): ?><span class="text-slate-500">Tu: </span><?php endif; ?>
                            <?= htmlspecialchars(mb_substr($c['last_msg'], 0, 50)) ?><?= mb_strlen($c['last_msg']) > 50 ? '...' : '' ?>
                        <?php else: ?>
                            <span class="text-slate-400">Sin mensajes</span>
                        <?php endif; ?>
                    </p>
                </div>
                <div class="chat-thread-meta">
                    <?php if ($c['last_at']): ?>
                    <span class="chat-thread-time"><?= date('d M', strtotime($c['last_at'])) ?></span>
                    <?php endif; ?>
                    <?php if ((int)$c['unread'] > 0): ?>
                    <span class="chat-thread-count"><?= (int)$c['unread'] ?></span>
                    <?php endif; ?>
                </div>
            </a>
            <?php endforeach; ?>
            <?php if (empty($clients)): ?>
            <p class="text-center text-xs text-slate-400 py-8">No hay clientes registrados.</p>
            <?php endif; ?>
        </div>
    </aside>

    <!-- Conversacion -->
    <section class="surface-card chat-window" id="chat-area">
        <?php if (!$selectedClientData): ?>
        <div class="flex-1 flex items-center justify-center p-10 text-center">
            <div>
                <svg class="w-16 h-16 mx-auto text-slate-200 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                <p class="text-sm font-bold text-slate-700">Selecciona un cliente</p>
                <p class="text-[12px] text-slate-500 mt-1">Escoge a la izquierda para empezar a chatear.</p>
            </div>
        </div>
        <?php else: ?>
        <header class="chat-head">
            <div class="chat-head-avatar chat-avatar-brand">
                <?= htmlspecialchars(strtoupper(substr($selectedClientData['name'], 0, 1))) ?>
            </div>
            <div class="flex-1 min-w-0">
                <h2 class="chat-head-title"><?= htmlspecialchars($selectedClientData['name']) ?></h2>
                <p class="chat-head-sub"><?= htmlspecialchars($selectedClientData['email']) ?><?= $selectedClientData['phone'] ? ' · ' . htmlspecialchars($selectedClientData['phone']) : '' ?></p>
            </div>
            <a href="client_details.php?id=<?= (int)$selectedClientData['id'] ?>" class="chat-head-action" title="Ver ficha del cliente">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
            </a>
            <?php if ($selectedClientData['phone']): ?>
            <a href="https://wa.me/<?= htmlspecialchars(preg_replace('/[^0-9]/','',$selectedClientData['phone'])) ?>" target="_blank" class="chat-head-action" style="background:#F0FDF4;color:#15803D" title="WhatsApp">
                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51"/></svg>
            </a>
            <?php endif; ?>
        </header>

        <div class="chat-body" id="chatBody">
            <?php if (empty($messages)): ?>
            <div class="chat-empty">
                <div class="chat-empty-icon">
                    <svg class="w-10 h-10" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                </div>
                <p class="font-bold text-slate-700 mt-3">Inicia la conversacion</p>
                <p class="text-[12px] text-slate-500 mt-1 max-w-sm mx-auto">Envia el primer mensaje. <?= htmlspecialchars($selectedClientData['name']) ?> lo vera en su portal.</p>
            </div>
            <?php else:
                $lastDate = null;
                foreach ($messages as $m):
                    $isMine = (int)$m['user_id'] === $admin_id;
                    $d = date('Y-m-d', strtotime($m['created_at']));
                    if ($d !== $lastDate):
                        $lastDate = $d;
                        $dayLabel = (date('Y-m-d') === $d ? 'Hoy' : (date('Y-m-d', strtotime('-1 day')) === $d ? 'Ayer' : date('d M Y', strtotime($d))));
            ?>
                <div class="chat-day-divider"><span><?= $dayLabel ?></span></div>
            <?php endif; ?>
                <div class="chat-msg <?= $isMine ? 'chat-msg-mine' : 'chat-msg-other' ?>">
                    <?php if (!$isMine): ?>
                    <div class="chat-msg-avatar"><?= htmlspecialchars(strtoupper(substr($m['author_name'], 0, 1))) ?></div>
                    <?php endif; ?>
                    <div class="chat-msg-bubble">
                        <p class="chat-msg-text"><?= nl2br(htmlspecialchars($m['message'])) ?></p>
                        <p class="chat-msg-time"><?= date('H:i', strtotime($m['created_at'])) ?></p>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <footer class="chat-input">
            <form method="POST" class="flex items-end gap-2">
                <input type="hidden" name="action" value="send_general">
                <input type="hidden" name="client_id" value="<?= (int)$selectedClient ?>">
                <textarea name="message" rows="1" required placeholder="Escribe un mensaje a <?= htmlspecialchars(explode(' ', $selectedClientData['name'])[0]) ?>..."
                          class="chat-textarea flex-1" id="chatMessage"
                          onkeydown="if(event.key==='Enter' && !event.shiftKey) { event.preventDefault(); this.form.requestSubmit(); }"></textarea>
                <button type="submit" class="chat-send" title="Enviar (Enter)">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19V5m-7 7l7-7 7 7"/></svg>
                </button>
            </form>
            <p class="text-[10px] text-slate-400 mt-2 px-1">Enter envia · Shift+Enter agrega un salto</p>
        </footer>
        <?php endif; ?>
    </section>
</div>

<style>
    .chat-layout { display: grid; grid-template-columns: 320px 1fr; gap: 16px; height: calc(100vh - 200px); min-height: 540px; }
    @media (max-width: 1023px) { .chat-layout { grid-template-columns: 1fr; height: auto; } .chat-threads { max-height: 260px; } .chat-window { min-height: 480px; } }
    .chat-threads { display: flex; flex-direction: column; overflow: hidden; }
    .chat-threads-head { padding: 14px 16px; border-bottom: 1px solid #F1F5F9; }
    .chat-threads-list { flex: 1; overflow-y: auto; padding: 6px; }
    .chat-thread { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 12px; transition: all .15s ease; }
    .chat-thread:hover { background: #F8FAFC; }
    .chat-thread.is-active { background: #F1F5F9; }
    .chat-thread-avatar { width: 38px; height: 38px; border-radius: 12px; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; font-weight: 800; font-size: 13px; }
    .chat-avatar-brand { background: linear-gradient(135deg, #0F172A, #1E293B); color: #fff; }
    .chat-thread-main { flex: 1; min-width: 0; }
    .chat-thread-title { font-size: 13px; font-weight: 700; color: #0F172A; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .chat-thread-preview { font-size: 11.5px; color: #64748B; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; margin-top: 1px; }
    .chat-thread-meta { display: flex; flex-direction: column; align-items: flex-end; gap: 3px; flex-shrink: 0; }
    .chat-thread-time { font-size: 10px; color: #94A3B8; font-weight: 600; white-space: nowrap; }
    .chat-thread-count { display: inline-flex; align-items: center; justify-content: center; min-width: 18px; height: 18px; padding: 0 5px; background: #EF4444; color: #fff; border-radius: 999px; font-size: 10px; font-weight: 800; }
    .chat-window { display: flex; flex-direction: column; overflow: hidden; }
    .chat-head { display: flex; align-items: center; gap: 12px; padding: 14px 18px; border-bottom: 1px solid #F1F5F9; }
    .chat-head-avatar { width: 42px; height: 42px; border-radius: 14px; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; font-weight: 800; font-size: 15px; }
    .chat-head-title { font-size: 15px; font-weight: 800; color: #0F172A; }
    .chat-head-sub { font-size: 11.5px; color: #64748B; margin-top: 1px; }
    .chat-head-action { width: 34px; height: 34px; border-radius: 10px; background: #F4F4F5; color: #475569; display: inline-flex; align-items: center; justify-content: center; transition: all .15s ease; }
    .chat-head-action:hover { background: #E5E7EB; color: #0F172A; }
    .chat-body { flex: 1; overflow-y: auto; padding: 18px 20px; background: linear-gradient(180deg, #FAFAFA, #fff); display: flex; flex-direction: column; gap: 10px; }
    .chat-empty { text-align: center; padding: 40px 20px; flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; }
    .chat-empty-icon { width: 72px; height: 72px; border-radius: 24px; background: #F1F5F9; color: #94A3B8; display: inline-flex; align-items: center; justify-content: center; }
    .chat-day-divider { display: flex; align-items: center; justify-content: center; margin: 12px 0 4px; }
    .chat-day-divider span { font-size: 10.5px; font-weight: 700; color: #94A3B8; padding: 4px 12px; background: #F4F4F5; border-radius: 999px; }
    .chat-msg { display: flex; gap: 8px; max-width: 78%; }
    .chat-msg-mine { align-self: flex-end; flex-direction: row-reverse; }
    .chat-msg-other { align-self: flex-start; }
    .chat-msg-avatar { width: 30px; height: 30px; border-radius: 10px; background: #475569; color: #fff; font-size: 11px; font-weight: 800; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .chat-msg-bubble { padding: 9px 13px; border-radius: 16px; font-size: 13px; line-height: 1.5; max-width: 100%; word-wrap: break-word; }
    .chat-msg-mine .chat-msg-bubble { background: #0F172A; color: #fff; border-bottom-right-radius: 4px; }
    .chat-msg-other .chat-msg-bubble { background: #fff; color: #0F172A; border: 1px solid #EEF0F2; border-bottom-left-radius: 4px; }
    .chat-msg-text { white-space: pre-wrap; }
    .chat-msg-time { font-size: 9.5px; opacity: 0.6; margin-top: 4px; text-align: right; }
    .chat-msg-mine .chat-msg-time { color: rgba(255,255,255,0.7); }
    .chat-input { padding: 12px 16px; border-top: 1px solid #F1F5F9; background: #fff; }
    .chat-textarea { background: #F4F4F5; border: 1px solid transparent; border-radius: 14px; padding: 10px 14px; font-size: 13.5px; color: #0F172A; font-family: inherit; resize: none; max-height: 140px; line-height: 1.45; transition: all .15s ease; }
    .chat-textarea:focus { outline: none; background: #fff; border-color: #0F172A; box-shadow: 0 0 0 4px rgba(15,23,42,0.06); }
    .chat-send { width: 42px; height: 42px; border-radius: 14px; background: #0F172A; color: #fff; border: 0; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; transition: all .15s ease; flex-shrink: 0; }
    .chat-send:hover { background: #1E293B; transform: translateY(-1px); }
</style>

<script>
(function() {
    const body = document.getElementById('chatBody');
    if (body) body.scrollTop = body.scrollHeight;
    const ta = document.getElementById('chatMessage');
    if (ta) ta.addEventListener('input', function(){ this.style.height='auto'; this.style.height=Math.min(140,this.scrollHeight)+'px'; });
    // Buscador de hilos
    const search = document.getElementById('threadSearch');
    if (search) {
        search.addEventListener('input', function() {
            const q = this.value.trim().toLowerCase();
            document.querySelectorAll('#threadsList .chat-thread').forEach(el => {
                el.style.display = !q || (el.dataset.name && el.dataset.name.indexOf(q) !== -1) ? '' : 'none';
            });
        });
    }
})();
</script>

<?php include 'components/layout_end.php'; ?>
