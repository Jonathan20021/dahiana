<?php
// Autotest: el servidor le hace POST a su propio telegram_webhook.php
// simulando un update real de Telegram. Sirve para descartar WAF / Imunify360
// bloqueando POSTs JSON.
//
// Requiere estar logueado como admin.

require_once __DIR__ . '/config.php';
requireAuth('admin');

$proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
$baseUrl = $proto . '://' . $host . rtrim($scriptDir, '/');

$targets = [
    'ping'    => $baseUrl . '/telegram_ping.php',
    'webhook' => $baseUrl . '/telegram_webhook.php',
];

$samplePayload = json_encode([
    'update_id' => 999999999,
    'message' => [
        'message_id' => 1,
        'from' => ['id' => 1, 'first_name' => 'Self', 'username' => 'self_test'],
        'chat' => ['id' => 1, 'type' => 'private'],
        'date' => time(),
        'text' => '/start_selftest_ignore',
    ],
]);

$secret = trim(getSetting('telegram_webhook_secret', ''));

function runProbe($url, $method, $body, $secret) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    if ($method === 'POST') {
        $headers = ['Content-Type: application/json'];
        if (!empty($secret)) $headers[] = 'X-Telegram-Bot-Api-Secret-Token: ' . $secret;
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }
    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err  = curl_error($ch);
    curl_close($ch);
    return [
        'http' => (int)($info['http_code'] ?? 0),
        'resp' => substr((string)$resp, 0, 500),
        'err'  => $err,
        'time' => round(($info['total_time'] ?? 0) * 1000) . 'ms',
    ];
}

$probes = [];
$probes[] = ['name' => 'GET ping',          'r' => runProbe($targets['ping'],    'GET',  null,         '')];
$probes[] = ['name' => 'POST ping (json)',  'r' => runProbe($targets['ping'],    'POST', $samplePayload, '')];
$probes[] = ['name' => 'GET webhook',       'r' => runProbe($targets['webhook'], 'GET',  null,         '')];
$probes[] = ['name' => 'POST webhook (sin secret)',  'r' => runProbe($targets['webhook'], 'POST', $samplePayload, '')];
$probes[] = ['name' => 'POST webhook (con secret)',  'r' => runProbe($targets['webhook'], 'POST', $samplePayload, $secret)];

$page_title = 'Telegram self-test';
$page_subtitle = 'El servidor se hace POSTs a si mismo para descartar WAF / Imunify360.';
include 'components/layout_start.php';
?>

<div class="surface-card overflow-hidden mb-4">
    <div class="px-5 py-3 border-b border-stone-100">
        <h3 class="text-sm font-bold text-slate-900">Resultados</h3>
        <p class="text-[11px] text-slate-500 mt-0.5">Si <strong>POST webhook</strong> devuelve <code>409</code> aqui (mismo servidor a si mismo), es Imunify360 / ModSecurity del hosting.</p>
    </div>
    <table class="w-full text-xs">
        <thead class="bg-stone-50/60 text-[10px] uppercase tracking-wider text-slate-500">
            <tr>
                <th class="px-4 py-2 text-left font-bold">Test</th>
                <th class="px-4 py-2 text-left font-bold">HTTP</th>
                <th class="px-4 py-2 text-left font-bold">Tiempo</th>
                <th class="px-4 py-2 text-left font-bold">Respuesta</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-stone-100">
            <?php foreach ($probes as $p):
                $http = $p['r']['http'];
                $color = $http >= 200 && $http < 300 ? 'text-emerald-600' : ($http === 409 ? 'text-red-600 font-bold' : 'text-amber-600');
            ?>
            <tr>
                <td class="px-4 py-2 font-semibold"><?= htmlspecialchars($p['name']) ?></td>
                <td class="px-4 py-2 font-mono <?= $color ?>"><?= $http ?></td>
                <td class="px-4 py-2 text-slate-500"><?= $p['r']['time'] ?></td>
                <td class="px-4 py-2 font-mono text-[10px] text-slate-600 break-all max-w-md"><?= htmlspecialchars($p['r']['resp']) ?: htmlspecialchars($p['r']['err']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<div class="surface-card p-5">
    <h3 class="text-sm font-bold text-slate-900 mb-2">Como interpretar</h3>
    <ul class="text-xs text-slate-600 space-y-1.5 list-disc pl-5">
        <li>Todo 200 -> el problema es entre Telegram y tu servidor (probablemente Cloudflare bloqueando IPs de Telegram).</li>
        <li>POST con 409 pero GET 200 -> ModSecurity / Imunify360 bloquea POSTs JSON. Pide al hosting whitelist del path.</li>
        <li>POST sin secret 200 pero CON secret 409 -> WAF detecta el header <code>X-Telegram-Bot-Api-Secret-Token</code> como sospechoso.</li>
        <li>Todo falla con timeout -> SSL o configuracion del server. Revisa que <code>amdaccouting</code> no sea typo del dominio real <code>amdaccounting</code>.</li>
    </ul>
</div>

<?php include 'components/layout_end.php'; ?>
