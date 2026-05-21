<?php
require_once 'config.php';
requireAuth('client');

$client_id = (int)$_SESSION['user_id'];

$filter = $_GET['status'] ?? 'active';
$where = ['r.client_id = ?'];
$params = [$client_id];

if ($filter === 'active') {
    $where[] = "r.status IN ('pendiente','en_proceso','en_revision')";
} elseif (in_array($filter, ['pendiente','en_proceso','en_revision','presentado','completado'], true)) {
    $where[] = 'r.status = ?';
    $params[] = $filter;
}
$whereSql = implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT r.*, s.title as service_title, s.type as service_type,
           (SELECT COUNT(*) FROM request_comments WHERE request_id = r.id) as comment_count,
           (SELECT COUNT(*) FROM request_attachments WHERE request_id = r.id) as attachment_count,
           (SELECT MAX(created_at) FROM request_comments WHERE request_id = r.id) as last_message_at
    FROM requests r
    JOIN services s ON r.service_id = s.id
    WHERE $whereSql
    ORDER BY r.status='completado' ASC, r.updated_at DESC, r.created_at DESC
    LIMIT 200
");
$stmt->execute($params);
$requests = $stmt->fetchAll();

// Contadores
$counters = $pdo->prepare("SELECT status, COUNT(*) c FROM requests WHERE client_id=? GROUP BY status");
$counters->execute([$client_id]);
$countByStatus = $counters->fetchAll(PDO::FETCH_KEY_PAIR);
$activeCount = ($countByStatus['pendiente'] ?? 0) + ($countByStatus['en_proceso'] ?? 0) + ($countByStatus['en_revision'] ?? 0);
$presentedCount = (int)($countByStatus['presentado'] ?? 0);
$completedCount = (int)($countByStatus['completado'] ?? 0);
$totalCount = array_sum($countByStatus);

function reqStatusMeta($s) {
    return match($s) {
        'pendiente'   => ['label'=>'Pendiente',    'color'=>'amber',   'pct'=>15, 'icon'=>'M12 8v4l3 3'],
        'en_proceso'  => ['label'=>'En proceso',   'color'=>'blue',    'pct'=>45, 'icon'=>'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9'],
        'en_revision' => ['label'=>'En revision',  'color'=>'indigo',  'pct'=>75, 'icon'=>'M15 12a3 3 0 11-6 0 3 3 0 016 0z'],
        'presentado'  => ['label'=>'Presentado',   'color'=>'emerald', 'pct'=>90, 'icon'=>'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2'],
        'completado'  => ['label'=>'Completado',   'color'=>'emerald', 'pct'=>100,'icon'=>'M5 13l4 4L19 7'],
        default       => ['label'=>ucfirst($s),    'color'=>'slate',   'pct'=>5,  'icon'=>'M12 8v4'],
    };
}

$page_title = 'Mis tramites';
$page_subtitle = 'Estado de los servicios que tu asesoria esta gestionando para ti.';
include 'components/layout_start.php';
?>

<!-- KPIs -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
    <div class="surface-card p-4">
        <div class="flex items-center justify-between">
            <span class="w-8 h-8 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582M20 20v-5h-.581m0 0a8.003 8.003 0 01-15.357-2"/></svg>
            </span>
        </div>
        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mt-3">Activos</p>
        <p class="text-2xl font-extrabold text-slate-900 mt-0.5"><?= $activeCount ?></p>
        <p class="text-[11px] text-slate-500">Tramites en gestion</p>
    </div>
    <div class="surface-card p-4">
        <div class="flex items-center justify-between">
            <span class="w-8 h-8 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
            </span>
        </div>
        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mt-3">Presentados DGII</p>
        <p class="text-2xl font-extrabold text-slate-900 mt-0.5"><?= $presentedCount ?></p>
        <p class="text-[11px] text-slate-500">Enviados con exito</p>
    </div>
    <div class="surface-card p-4">
        <div class="flex items-center justify-between">
            <span class="w-8 h-8 rounded-xl bg-slate-100 text-slate-600 flex items-center justify-center">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            </span>
        </div>
        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mt-3">Completados</p>
        <p class="text-2xl font-extrabold text-slate-900 mt-0.5"><?= $completedCount ?></p>
        <p class="text-[11px] text-slate-500">Tramites cerrados</p>
    </div>
    <div class="surface-card p-4">
        <div class="flex items-center justify-between">
            <span class="w-8 h-8 rounded-xl bg-indigo-50 text-indigo-600 flex items-center justify-center">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/></svg>
            </span>
        </div>
        <p class="text-[10px] font-bold uppercase tracking-wider text-slate-400 mt-3">Total historico</p>
        <p class="text-2xl font-extrabold text-slate-900 mt-0.5"><?= $totalCount ?></p>
        <p class="text-[11px] text-slate-500">Todos los registros</p>
    </div>
</div>

<!-- Filtros -->
<div class="surface-card p-2 mb-3 flex flex-wrap items-center gap-1">
    <?php
    $tabs = [
        'active'      => ['Activos',     $activeCount],
        'pendiente'   => ['Pendientes',  $countByStatus['pendiente'] ?? 0],
        'en_proceso'  => ['En proceso',  $countByStatus['en_proceso'] ?? 0],
        'en_revision' => ['En revision', $countByStatus['en_revision'] ?? 0],
        'presentado'  => ['Presentados', $presentedCount],
        'completado'  => ['Completados', $completedCount],
        'all'         => ['Todos',       $totalCount],
    ];
    foreach ($tabs as $key => [$label, $cnt]):
        $isActive = $filter === $key;
    ?>
    <a href="client_requests.php?status=<?= $key ?>" class="cr-tab <?= $isActive ? 'is-active' : '' ?>">
        <?= htmlspecialchars($label) ?>
        <?php if ($cnt > 0): ?><span class="cr-tab-count"><?= $cnt ?></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- Lista -->
<?php if (empty($requests)): ?>
<div class="surface-card p-10 text-center">
    <svg class="w-12 h-12 mx-auto text-slate-200 mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
    <p class="text-sm text-slate-500">No tienes tramites en esta seccion.</p>
    <p class="text-[11px] text-slate-400 mt-1">Cuando tu asesor te asigne un servicio, lo veras aqui.</p>
</div>
<?php else: ?>
<div class="space-y-3">
    <?php foreach ($requests as $r):
        $meta = reqStatusMeta($r['status']);
        $color = $meta['color'];
        $isCompleted = in_array($r['status'], ['completado','presentado'], true);
        $period = $r['period'] ? '· ' . $r['period'] : '';
        $eta = $r['estimated_delivery_date'] ? date('d M Y', strtotime($r['estimated_delivery_date'])) : '';
        $lastMsg = $r['last_message_at'] ? date('d M, H:i', strtotime($r['last_message_at'])) : null;
    ?>
    <a href="request_view.php?id=<?= (int)$r['id'] ?>" class="cr-card cr-card-<?= $color ?>">
        <div class="cr-card-icon cr-icon-<?= $color ?>">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="<?= $meta['icon'] ?>"/></svg>
        </div>
        <div class="cr-card-main">
            <div class="flex items-center gap-2 flex-wrap">
                <h3 class="cr-card-title"><?= htmlspecialchars($r['service_title']) ?></h3>
                <span class="cr-status cr-status-<?= $color ?>"><?= $meta['label'] ?></span>
                <?php if ($r['service_type'] === 'iguala'): ?>
                <span class="cr-tag">Iguala</span>
                <?php else: ?>
                <span class="cr-tag">Puntual</span>
                <?php endif; ?>
            </div>
            <p class="cr-card-meta">
                Creado <?= date('d M Y', strtotime($r['created_at'])) ?>
                <?= $period ?>
                <?php if ($eta): ?>· Entrega estimada <?= $eta ?><?php endif; ?>
            </p>
            <!-- Progreso -->
            <div class="cr-progress">
                <div class="cr-progress-bar cr-progress-<?= $color ?>" style="width: <?= $meta['pct'] ?>%"></div>
            </div>
            <div class="cr-card-foot">
                <?php if ($r['comment_count'] > 0): ?>
                <span class="cr-foot-pill">
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
                    <?= (int)$r['comment_count'] ?> mensaje(s)
                </span>
                <?php endif; ?>
                <?php if ($r['attachment_count'] > 0): ?>
                <span class="cr-foot-pill">
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                    <?= (int)$r['attachment_count'] ?> archivo(s)
                </span>
                <?php endif; ?>
                <?php if ($lastMsg): ?>
                <span class="cr-foot-pill cr-foot-pill-blue">
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Ultimo mensaje <?= $lastMsg ?>
                </span>
                <?php endif; ?>
            </div>
        </div>
        <div class="cr-card-arrow">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<style>
    .cr-tab { display: inline-flex; align-items: center; gap: 6px; padding: 7px 12px; border-radius: 10px; font-size: 12px; font-weight: 700; color: #475569; transition: all .15s ease; background: transparent; }
    .cr-tab:hover { background: #F4F4F5; color: #0F172A; }
    .cr-tab.is-active { background: #0F172A; color: #fff; }
    .cr-tab-count { font-size: 10px; padding: 1px 6px; border-radius: 999px; background: rgba(0,0,0,0.08); }
    .cr-tab.is-active .cr-tab-count { background: rgba(255,255,255,0.18); }

    .cr-card { display: flex; align-items: flex-start; gap: 14px; padding: 16px; background: #fff; border: 1px solid #EEF0F2; border-radius: 18px; transition: all .18s ease; position: relative; }
    .cr-card:hover { border-color: #CBD5E1; transform: translateY(-1px); box-shadow: 0 6px 20px rgba(15,23,42,0.06); }
    .cr-card-icon { width: 42px; height: 42px; border-radius: 14px; flex-shrink: 0; display: inline-flex; align-items: center; justify-content: center; }
    .cr-icon-amber   { background: #FFFBEB; color: #B45309; }
    .cr-icon-blue    { background: #EFF6FF; color: #2563EB; }
    .cr-icon-indigo  { background: #EEF2FF; color: #4F46E5; }
    .cr-icon-emerald { background: #F0FDF4; color: #15803D; }
    .cr-icon-slate   { background: #F1F5F9; color: #475569; }

    .cr-card-main { flex: 1; min-width: 0; }
    .cr-card-title { font-size: 14.5px; font-weight: 800; color: #0F172A; letter-spacing: -0.01em; line-height: 1.3; }
    .cr-card-meta { font-size: 11.5px; color: #64748B; margin-top: 4px; }
    .cr-card-foot { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
    .cr-card-arrow { color: #CBD5E1; flex-shrink: 0; align-self: center; }
    .cr-card:hover .cr-card-arrow { color: #0F172A; transform: translateX(2px); transition: transform .15s ease; }

    .cr-status { display: inline-flex; align-items: center; padding: 3px 9px; border-radius: 999px; font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; }
    .cr-status-amber   { background: #FFFBEB; color: #B45309; }
    .cr-status-blue    { background: #EFF6FF; color: #2563EB; }
    .cr-status-indigo  { background: #EEF2FF; color: #4F46E5; }
    .cr-status-emerald { background: #F0FDF4; color: #15803D; }
    .cr-status-slate   { background: #F1F5F9; color: #475569; }

    .cr-tag { display: inline-flex; align-items: center; padding: 2px 8px; border-radius: 999px; background: #F1F5F9; color: #475569; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; }

    .cr-progress { height: 4px; background: #F1F5F9; border-radius: 999px; overflow: hidden; margin-top: 10px; }
    .cr-progress-bar { height: 100%; border-radius: 999px; transition: width .6s ease; }
    .cr-progress-amber   { background: linear-gradient(90deg, #FBBF24, #F59E0B); }
    .cr-progress-blue    { background: linear-gradient(90deg, #60A5FA, #2563EB); }
    .cr-progress-indigo  { background: linear-gradient(90deg, #818CF8, #4F46E5); }
    .cr-progress-emerald { background: linear-gradient(90deg, #34D399, #10B981); }
    .cr-progress-slate   { background: linear-gradient(90deg, #94A3B8, #475569); }

    .cr-foot-pill { display: inline-flex; align-items: center; gap: 4px; padding: 3px 8px; background: #F4F4F5; border-radius: 6px; font-size: 10.5px; font-weight: 600; color: #475569; }
    .cr-foot-pill-blue { background: #EFF6FF; color: #2563EB; }
</style>

<?php include 'components/layout_end.php'; ?>
