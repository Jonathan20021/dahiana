<?php
require_once 'config.php';
requireAuth('admin');
requirePagePermission();

$LOG_FILE = __DIR__ . '/uploads/logs/telegram.log';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_log') {
    @file_put_contents($LOG_FILE, '');
    header('Location: admin_telegram_debug.php');
    exit;
}

$info = tgGetWebhookInfo();
$me   = tgGetMe();

$log = '';
if (is_file($LOG_FILE)) {
    $log = @file_get_contents($LOG_FILE);
    if (strlen($log) > 50000) $log = substr($log, -50000); // last 50KB
}

// Stats
$pdo->query("SELECT 1"); // ping
$tgUploads = (int)$pdo->query("SELECT COUNT(*) FROM invoice_uploads WHERE source='telegram'")->fetchColumn();
$tgLinks   = (int)$pdo->query("SELECT COUNT(*) FROM telegram_links WHERE active=1")->fetchColumn();
$lastUploads = $pdo->query("SELECT id, client_id, original_name, status, created_at FROM invoice_uploads WHERE source='telegram' ORDER BY id DESC LIMIT 10")->fetchAll();

$page_title = 'Diagnostico Telegram';
$page_subtitle = 'Estado del webhook, log de eventos y pruebas.';
$main_max = 'max-w-5xl';
include 'components/layout_start.php';
?>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
    <!-- Bot info -->
    <div class="surface-card p-5">
        <h3 class="text-sm font-bold text-slate-900 mb-2">Bot</h3>
        <?php if (!empty($me['ok'])): ?>
        <p class="text-xs text-slate-600">Nombre: <strong><?= htmlspecialchars($me['result']['first_name'] ?? '') ?></strong></p>
        <p class="text-xs text-slate-600">Username: <code class="font-mono">@<?= htmlspecialchars($me['result']['username'] ?? '') ?></code></p>
        <p class="text-xs text-slate-600">ID: <code class="font-mono"><?= htmlspecialchars((string)($me['result']['id'] ?? '')) ?></code></p>
        <p class="text-xs text-slate-600">Can read all msgs: <?= !empty($me['result']['can_read_all_group_messages']) ? 'si' : 'no' ?></p>
        <span class="badge-dot badge-green mt-2">Token valido</span>
        <?php else: ?>
        <p class="text-xs text-red-600">No pude conectar al bot: <?= htmlspecialchars($me['error'] ?? '') ?></p>
        <?php endif; ?>
    </div>

    <!-- Webhook info -->
    <div class="surface-card p-5">
        <h3 class="text-sm font-bold text-slate-900 mb-2">Webhook</h3>
        <?php if (!empty($info['ok'])):
            $w = $info['result']; ?>
        <p class="text-xs text-slate-600 break-all">URL: <code class="font-mono"><?= htmlspecialchars($w['url'] ?? '(sin url)') ?></code></p>
        <p class="text-xs text-slate-600">Updates pendientes: <strong><?= (int)($w['pending_update_count'] ?? 0) ?></strong></p>
        <p class="text-xs text-slate-600">Max connections: <?= (int)($w['max_connections'] ?? 0) ?></p>
        <p class="text-xs text-slate-600">IP allowed: <?= htmlspecialchars($w['ip_address'] ?? '-') ?></p>
        <p class="text-xs text-slate-600">Secret token: <?= !empty($w['has_custom_certificate']) ? 'cert custom' : '-' ?></p>
        <?php if (!empty($w['last_error_message'])): ?>
        <div class="mt-2 rounded-xl bg-red-50 border border-red-100 p-3">
            <p class="text-[11px] font-bold text-red-700">Ultimo error</p>
            <p class="text-[11px] text-red-700"><?= htmlspecialchars($w['last_error_message']) ?></p>
            <p class="text-[10px] text-red-500"><?= date('d/m/Y H:i', (int)($w['last_error_date'] ?? 0)) ?></p>
        </div>
        <?php else: ?>
        <span class="badge-dot badge-green mt-2">Sin errores</span>
        <?php endif; ?>
        <?php else: ?>
        <p class="text-xs text-red-600"><?= htmlspecialchars($info['error'] ?? '') ?></p>
        <?php endif; ?>
    </div>
</div>

<!-- Quick stats -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
    <div class="stat-card p-4">
        <p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">Clientes vinculados</p>
        <p class="text-2xl font-extrabold text-slate-900"><?= $tgLinks ?></p>
    </div>
    <div class="stat-card p-4">
        <p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">Facturas por Telegram</p>
        <p class="text-2xl font-extrabold text-slate-900"><?= $tgUploads ?></p>
    </div>
    <div class="stat-card p-4">
        <p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">Log size</p>
        <p class="text-2xl font-extrabold text-slate-900"><?= round(strlen($log)/1024, 1) ?> KB</p>
    </div>
    <div class="stat-card p-4 flex items-center justify-center">
        <form method="POST">
            <input type="hidden" name="action" value="clear_log">
            <button type="submit" class="btn-soft text-xs">Limpiar log</button>
        </form>
    </div>
</div>

<!-- Recent uploads via Telegram -->
<div class="surface-card overflow-hidden mb-4">
    <div class="px-5 py-3 border-b border-stone-100">
        <h3 class="text-sm font-bold text-slate-900">Ultimas facturas via Telegram</h3>
    </div>
    <?php if (empty($lastUploads)): ?>
    <p class="px-5 py-6 text-center text-xs text-slate-400">Aun no se ha recibido nada por Telegram.</p>
    <?php else: ?>
    <table class="w-full text-xs">
        <thead class="bg-stone-50/60 text-[10px] uppercase tracking-wider text-slate-500">
            <tr>
                <th class="px-4 py-2 text-left font-bold">#</th>
                <th class="px-4 py-2 text-left font-bold">Cliente</th>
                <th class="px-4 py-2 text-left font-bold">Archivo</th>
                <th class="px-4 py-2 text-left font-bold">Estado</th>
                <th class="px-4 py-2 text-left font-bold">Cuando</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
            <?php foreach ($lastUploads as $u): ?>
            <tr>
                <td class="px-4 py-2 font-mono"><?= (int)$u['id'] ?></td>
                <td class="px-4 py-2"><?= (int)$u['client_id'] ?></td>
                <td class="px-4 py-2 truncate max-w-xs"><?= htmlspecialchars($u['original_name']) ?></td>
                <td class="px-4 py-2"><?= htmlspecialchars($u['status']) ?></td>
                <td class="px-4 py-2 text-slate-500"><?= date('d/m H:i', strtotime($u['created_at'])) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- Log viewer -->
<div class="surface-card overflow-hidden">
    <div class="px-5 py-3 border-b border-stone-100 flex items-center justify-between">
        <h3 class="text-sm font-bold text-slate-900">Log del webhook</h3>
        <span class="text-xs text-slate-400"><?= is_file($LOG_FILE) ? 'Ultimas 50KB' : 'Sin log aun' ?></span>
    </div>
    <div class="p-0">
        <?php if ($log === ''): ?>
        <p class="px-5 py-8 text-center text-xs text-slate-400">Aun no hay eventos. Envia un mensaje al bot y recarga.</p>
        <?php else: ?>
        <pre class="text-[11px] text-slate-700 leading-relaxed bg-stone-900 text-emerald-300 p-4 max-h-[500px] overflow-auto"><?= htmlspecialchars($log) ?></pre>
        <?php endif; ?>
    </div>
</div>

<?php include 'components/layout_end.php'; ?>
