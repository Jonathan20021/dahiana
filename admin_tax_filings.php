<?php
require_once 'config.php';
requireAuth('admin');

$success = $error = null;

$type = $_GET['type'] ?? '606';
if (!in_array($type, ['606', '607', '608'], true)) $type = '606';

$period = $_GET['period'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) $period = date('Y-m');

$months = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
$periodLabel = $months[(int)substr($period, 5, 2) - 1] . ' ' . substr($period, 0, 4);
$prevPeriod = date('Y-m', strtotime($period . '-01 -1 month'));
$nextPeriod = date('Y-m', strtotime($period . '-01 +1 month'));

$selectedClient = (int)($_GET['client_id'] ?? 0);

$typeLabels = [
    '606' => ['title' => 'Compras de Bienes y Servicios', 'short' => 'Compras'],
    '607' => ['title' => 'Ventas de Bienes y Servicios', 'short' => 'Ventas'],
    '608' => ['title' => 'NCF Anulados', 'short' => 'Anulados'],
];

// Handle export TXT (DGII format)
if (isset($_GET['export']) && $selectedClient > 0) {
    $filing = $pdo->prepare("SELECT * FROM tax_filings WHERE client_id=? AND filing_type=? AND period=?");
    $filing->execute([$selectedClient, $type, $period]);
    $f = $filing->fetch();

    if ($f) {
        $rows = $pdo->prepare("SELECT * FROM tax_filing_rows WHERE filing_id=? ORDER BY id");
        $rows->execute([$f['id']]);
        $rowList = $rows->fetchAll();

        $client = $pdo->prepare("SELECT name, rnc FROM users WHERE id=?");
        $client->execute([$selectedClient]);
        $c = $client->fetch();
        $rncClient = preg_replace('/[^0-9]/', '', $c['rnc'] ?? '');

        $filename = "DGII_F_{$type}_{$rncClient}_" . str_replace('-', '', $period) . ".txt";
        header('Content-Type: text/plain; charset=utf-8');
        header("Content-Disposition: attachment; filename=\"{$filename}\"");

        // Header line (varies per type, simplified)
        echo "{$rncClient}|" . str_replace('-', '', $period) . "|" . count($rowList) . "\n";
        foreach ($rowList as $r) {
            $rnc = preg_replace('/[^0-9]/', '', $r['rnc'] ?? '');
            $ncf = $r['ncf'] ?? '';
            if ($type === '606') {
                // RNC | TipoIdentificacion | TipoBien | NCF | NCFModificado | FechaComprobante | FechaPago | MontoFacturado | ITBISFacturado | ITBISRetenido | ISRRetenido
                echo "{$rnc}|1|{$r['tax_type']}|{$ncf}|{$r['ncf_modified']}|" . date('Ymd', strtotime($r['date_doc'])) . "|" . ($r['date_payment'] ? date('Ymd', strtotime($r['date_payment'])) : '') . "|{$r['amount']}|{$r['itbis']}|{$r['itbis_retention']}|{$r['isr_retention']}\n";
            } elseif ($type === '607') {
                // RNC | TipoIdentificacion | NCF | NCFModificado | TipoIngreso | FechaComprobante | FechaRetencion | MontoFacturado | ITBISFacturado | ITBISRetenidoTerceros | ISRRetenidoTerceros
                echo "{$rnc}|1|{$ncf}|{$r['ncf_modified']}|01|" . date('Ymd', strtotime($r['date_doc'])) . "|" . ($r['date_payment'] ? date('Ymd', strtotime($r['date_payment'])) : '') . "|{$r['amount']}|{$r['itbis']}|{$r['itbis_retention']}|{$r['isr_retention']}\n";
            } else {
                // 608: NCF | FechaComprobante | TipoAnulacion
                echo "{$ncf}|" . date('Ymd', strtotime($r['date_doc'])) . "|02\n";
            }
        }
        exit;
    }
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_filing') {
        $cid = (int)($_POST['client_id'] ?? 0);
        if ($cid > 0) {
            try {
                $pdo->prepare("INSERT IGNORE INTO tax_filings (client_id, filing_type, period, status) VALUES (?, ?, ?, 'borrador')")
                    ->execute([$cid, $type, $period]);
                $success = "Formulario creado.";
                header("Location: admin_tax_filings.php?type={$type}&period={$period}&client_id={$cid}");
                exit;
            } catch (PDOException $e) {
                $error = "Ya existe formulario para este cliente y periodo.";
            }
        }
    } elseif ($action === 'add_row' && $selectedClient > 0) {
        $filing = $pdo->prepare("SELECT id FROM tax_filings WHERE client_id=? AND filing_type=? AND period=?");
        $filing->execute([$selectedClient, $type, $period]);
        $fid = $filing->fetchColumn();
        if ($fid) {
            $pdo->prepare("INSERT INTO tax_filing_rows (filing_id, rnc, ncf, ncf_modified, tax_type, date_doc, date_payment, amount, itbis, isr_retention, itbis_retention) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([
                    $fid,
                    trim($_POST['rnc'] ?? ''),
                    trim($_POST['ncf'] ?? ''),
                    trim($_POST['ncf_modified'] ?? ''),
                    $_POST['tax_type'] ?? '01',
                    $_POST['date_doc'] ?: date('Y-m-d'),
                    $_POST['date_payment'] ?: null,
                    (float)($_POST['amount'] ?? 0),
                    (float)($_POST['itbis'] ?? 0),
                    (float)($_POST['isr_retention'] ?? 0),
                    (float)($_POST['itbis_retention'] ?? 0),
                ]);
            // Recalculate totals
            $tots = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(amount),0) a, COALESCE(SUM(itbis),0) i FROM tax_filing_rows WHERE filing_id=?");
            $tots->execute([$fid]);
            $t = $tots->fetch();
            $pdo->prepare("UPDATE tax_filings SET total_records=?, total_amount=?, total_itbis=? WHERE id=?")
                ->execute([$t['c'], $t['a'], $t['i'], $fid]);
            $success = "Linea agregada.";
        }
    } elseif ($action === 'delete_row') {
        $rid = (int)($_POST['row_id'] ?? 0);
        $row = $pdo->prepare("SELECT filing_id FROM tax_filing_rows WHERE id=?");
        $row->execute([$rid]);
        $fid = $row->fetchColumn();
        $pdo->prepare("DELETE FROM tax_filing_rows WHERE id=?")->execute([$rid]);
        if ($fid) {
            $tots = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(amount),0) a, COALESCE(SUM(itbis),0) i FROM tax_filing_rows WHERE filing_id=?");
            $tots->execute([$fid]);
            $t = $tots->fetch();
            $pdo->prepare("UPDATE tax_filings SET total_records=?, total_amount=?, total_itbis=? WHERE id=?")
                ->execute([$t['c'], $t['a'], $t['i'], $fid]);
        }
        $success = "Linea eliminada.";
    } elseif ($action === 'mark_sent' && $selectedClient > 0) {
        $pdo->prepare("UPDATE tax_filings SET status='enviado', sent_at=NOW() WHERE client_id=? AND filing_type=? AND period=?")
            ->execute([$selectedClient, $type, $period]);
        // Mark corresponding obligation as completed
        $pdo->prepare("UPDATE tax_obligations SET status='completado', completed_at=NOW() WHERE client_id=? AND obligation_type=? AND period=?")
            ->execute([$selectedClient, $type, $period]);
        logClientActivity($selectedClient, 'tax', "Formulario {$type} de {$periodLabel} marcado como enviado a DGII");
        $success = "Formulario marcado como enviado a DGII.";
    } elseif ($action === 'import_csv' && $selectedClient > 0 && isset($_FILES['csv_file'])) {
        // Get or create filing
        $filing = $pdo->prepare("SELECT id FROM tax_filings WHERE client_id=? AND filing_type=? AND period=?");
        $filing->execute([$selectedClient, $type, $period]);
        $fid = $filing->fetchColumn();
        if (!$fid) {
            $pdo->prepare("INSERT INTO tax_filings (client_id, filing_type, period, status) VALUES (?, ?, ?, 'borrador')")
                ->execute([$selectedClient, $type, $period]);
            $fid = $pdo->lastInsertId();
        }

        $file = $_FILES['csv_file']['tmp_name'];
        $imported = 0;
        if (($handle = fopen($file, 'r')) !== false) {
            $isHeader = true;
            while (($row = fgetcsv($handle, 0, ',')) !== false) {
                if ($isHeader) { $isHeader = false; continue; }
                if (count($row) < 4) continue;
                // Expected order: rnc, ncf, fecha, monto, itbis (others optional)
                $pdo->prepare("INSERT INTO tax_filing_rows (filing_id, rnc, ncf, ncf_modified, tax_type, date_doc, date_payment, amount, itbis, isr_retention, itbis_retention) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([
                        $fid,
                        $row[0] ?? '',
                        $row[1] ?? '',
                        $row[2] ?? '',
                        $row[3] ?? '01',
                        !empty($row[4]) ? date('Y-m-d', strtotime($row[4])) : date('Y-m-d'),
                        !empty($row[5]) ? date('Y-m-d', strtotime($row[5])) : null,
                        (float)($row[6] ?? 0),
                        (float)($row[7] ?? 0),
                        (float)($row[8] ?? 0),
                        (float)($row[9] ?? 0),
                    ]);
                $imported++;
            }
            fclose($handle);

            $tots = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(amount),0) a, COALESCE(SUM(itbis),0) i FROM tax_filing_rows WHERE filing_id=?");
            $tots->execute([$fid]);
            $t = $tots->fetch();
            $pdo->prepare("UPDATE tax_filings SET total_records=?, total_amount=?, total_itbis=? WHERE id=?")
                ->execute([$t['c'], $t['a'], $t['i'], $fid]);

            $success = "Se importaron {$imported} lineas desde el CSV.";
        }
    }
}

// Fetch filings for this period+type
$filings = $pdo->prepare("
    SELECT f.*, u.name AS client_name, u.rnc, u.business_name
    FROM tax_filings f
    JOIN users u ON u.id = f.client_id
    WHERE f.filing_type = ? AND f.period = ?
    ORDER BY u.name
");
$filings->execute([$type, $period]);
$filingList = $filings->fetchAll();

// Selected client data
$selectedFiling = null;
$selectedRows = [];
if ($selectedClient > 0) {
    $sf = $pdo->prepare("SELECT f.*, u.name AS client_name, u.rnc, u.business_name FROM tax_filings f JOIN users u ON u.id = f.client_id WHERE f.client_id=? AND f.filing_type=? AND f.period=?");
    $sf->execute([$selectedClient, $type, $period]);
    $selectedFiling = $sf->fetch();
    if ($selectedFiling) {
        $rs = $pdo->prepare("SELECT * FROM tax_filing_rows WHERE filing_id=? ORDER BY date_doc DESC, id DESC");
        $rs->execute([$selectedFiling['id']]);
        $selectedRows = $rs->fetchAll();
    }
}

// All clients
$allClients = $pdo->query("
    SELECT u.id, u.name, u.rnc
    FROM users u
    LEFT JOIN roles r ON r.slug = u.role
    WHERE COALESCE(r.access_level, CASE WHEN u.role = 'admin' THEN 'admin' ELSE 'client' END) = 'client'
      AND (u.client_status IS NULL OR u.client_status != 'inactivo')
    ORDER BY u.name
")->fetchAll();

$page_title = "Formulario {$type}";
$page_subtitle = $typeLabels[$type]['title'] . ' &middot; ' . $periodLabel;
include 'components/layout_start.php';
?>

<?php if ($success): ?>
<div class="mb-4 rounded-2xl bg-emerald-50 px-4 py-3 border border-emerald-100 text-sm font-medium text-emerald-800"><?= htmlspecialchars($success) ?></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="mb-4 rounded-2xl bg-red-50 px-4 py-3 border border-red-100 text-sm font-medium text-red-700"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<!-- Tabs + period -->
<div class="surface-card p-3 mb-4 flex flex-col lg:flex-row gap-3">
    <div class="flex gap-2 overflow-x-auto scroll-area">
        <?php foreach ($typeLabels as $tk => $tl):
            $active = $type === $tk;
            $href = "?type={$tk}&period=" . urlencode($period) . ($selectedClient ? '&client_id=' . $selectedClient : '');
        ?>
        <a href="<?= $href ?>" class="whitespace-nowrap rounded-2xl px-4 py-2 text-sm font-bold transition-colors <?= $active ? 'bg-slate-900 text-white' : 'bg-stone-100 text-slate-600 hover:bg-stone-200' ?>">
            <?= $tk ?> <span class="font-medium opacity-75"><?= htmlspecialchars($tl['short']) ?></span>
        </a>
        <?php endforeach; ?>
    </div>
    <div class="flex items-center gap-2 lg:ml-auto">
        <a href="?type=<?= $type ?>&period=<?= $prevPeriod ?><?= $selectedClient ? '&client_id=' . $selectedClient : '' ?>" class="icon-btn">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <span class="px-3 py-2 rounded-xl bg-stone-50 text-sm font-bold text-slate-900 min-w-[120px] text-center"><?= $periodLabel ?></span>
        <a href="?type=<?= $type ?>&period=<?= $nextPeriod ?><?= $selectedClient ? '&client_id=' . $selectedClient : '' ?>" class="icon-btn">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        </a>
    </div>
</div>

<?php if ($selectedClient && $selectedFiling): ?>
<!-- Detail view: rows of selected filing -->

<div class="surface-card p-5 mb-4">
    <div class="flex flex-col lg:flex-row lg:items-center gap-4">
        <div class="flex items-center gap-4">
            <a href="admin_tax_filings.php?type=<?= $type ?>&period=<?= $period ?>" class="icon-btn">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/></svg>
            </a>
            <div>
                <p class="text-xs text-slate-500">Cliente</p>
                <h3 class="text-base font-extrabold text-slate-900"><?= htmlspecialchars($selectedFiling['client_name']) ?></h3>
                <p class="text-xs text-slate-500">RNC <?= htmlspecialchars($selectedFiling['rnc'] ?: 'N/A') ?></p>
            </div>
        </div>
        <div class="lg:ml-auto grid grid-cols-3 gap-3">
            <div class="rounded-xl bg-stone-50 px-3 py-2 text-center">
                <p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">Lineas</p>
                <p class="text-base font-extrabold text-slate-900"><?= (int)$selectedFiling['total_records'] ?></p>
            </div>
            <div class="rounded-xl bg-stone-50 px-3 py-2 text-center">
                <p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">Monto</p>
                <p class="text-base font-extrabold text-slate-900">RD$ <?= number_format((float)$selectedFiling['total_amount'], 0) ?></p>
            </div>
            <div class="rounded-xl bg-stone-50 px-3 py-2 text-center">
                <p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">ITBIS</p>
                <p class="text-base font-extrabold text-slate-900">RD$ <?= number_format((float)$selectedFiling['total_itbis'], 0) ?></p>
            </div>
        </div>
        <div class="flex items-center gap-2 flex-wrap">
            <a href="?type=<?= $type ?>&period=<?= $period ?>&client_id=<?= $selectedClient ?>&export=1" class="btn-soft text-sm">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                Exportar DGII
            </a>
            <?php if ($selectedFiling['status'] !== 'enviado'): ?>
            <form method="POST" class="inline" onsubmit="return confirm('Marcar como enviado a DGII? Tambien marcara la obligacion como completada.')">
                <input type="hidden" name="action" value="mark_sent">
                <button type="submit" class="btn-dark text-sm">Marcar enviado</button>
            </form>
            <?php else: ?>
            <span class="badge-dot badge-green">Enviado <?= date('d/m/Y', strtotime($selectedFiling['sent_at'])) ?></span>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-4">
    <!-- Add row form -->
    <div class="surface-card p-5">
        <h4 class="text-sm font-bold text-slate-900 mb-3">Agregar linea</h4>
        <form method="POST" class="space-y-2">
            <input type="hidden" name="action" value="add_row">
            <?php if ($type !== '608'): ?>
            <div>
                <label class="field-label">RNC / Cedula</label>
                <input type="text" name="rnc" required class="field !text-sm" placeholder="000-00000-0">
            </div>
            <?php endif; ?>
            <div>
                <label class="field-label">NCF</label>
                <input type="text" name="ncf" required class="field !text-sm" placeholder="B0100000001">
            </div>
            <?php if ($type === '606'): ?>
            <div>
                <label class="field-label">Tipo bien/servicio</label>
                <select name="tax_type" class="field !text-sm">
                    <option value="01">01 - Gastos de Personal</option>
                    <option value="02">02 - Gastos por Trabajos, Suministros y Servicios</option>
                    <option value="03">03 - Arrendamientos</option>
                    <option value="04">04 - Gastos de Activos Fijos</option>
                    <option value="05">05 - Gastos de Representacion</option>
                    <option value="06">06 - Otras Deducciones Admitidas</option>
                    <option value="07">07 - Gastos Financieros</option>
                    <option value="08">08 - Gastos Extraordinarios</option>
                    <option value="09" selected>09 - Compras y Gastos del Periodo</option>
                    <option value="10">10 - Adquisiciones de Activos</option>
                    <option value="11">11 - Gastos de Seguros</option>
                </select>
            </div>
            <?php endif; ?>
            <div>
                <label class="field-label">Fecha comprobante</label>
                <input type="date" name="date_doc" required value="<?= $period ?>-01" class="field !text-sm">
            </div>
            <?php if ($type !== '608'): ?>
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="field-label">Monto</label>
                    <input type="number" step="0.01" name="amount" class="field !text-sm" value="0">
                </div>
                <div>
                    <label class="field-label">ITBIS</label>
                    <input type="number" step="0.01" name="itbis" class="field !text-sm" value="0">
                </div>
            </div>
            <?php endif; ?>
            <button type="submit" class="btn-dark text-sm w-full mt-2">Agregar</button>
        </form>
    </div>

    <!-- Import CSV -->
    <div class="surface-card p-5 lg:col-span-2">
        <h4 class="text-sm font-bold text-slate-900 mb-2">Importar desde CSV</h4>
        <p class="text-xs text-slate-500 mb-3">Sube un CSV con columnas: <code class="font-mono bg-stone-100 px-1 rounded">rnc, ncf, ncf_modificado, tipo, fecha_comprobante, fecha_pago, monto, itbis, isr_ret, itbis_ret</code></p>
        <form method="POST" enctype="multipart/form-data" class="flex flex-col sm:flex-row gap-2">
            <input type="hidden" name="action" value="import_csv">
            <input type="file" name="csv_file" accept=".csv" required class="field !text-sm flex-1">
            <button type="submit" class="btn-dark text-sm">Importar</button>
        </form>
        <p class="mt-3 text-[11px] text-slate-400">Tip: descarga el formato actual con "Exportar DGII" arriba y úsalo como base.</p>
    </div>
</div>

<!-- Rows table -->
<div class="surface-card overflow-hidden">
    <div class="px-5 py-3 border-b border-stone-100 flex items-center justify-between">
        <h3 class="text-sm font-bold text-slate-900">Lineas del formulario</h3>
        <span class="text-xs text-slate-400"><?= count($selectedRows) ?> registro(s)</span>
    </div>
    <?php if (empty($selectedRows)): ?>
    <div class="py-10 text-center text-sm text-slate-400">Sin lineas registradas. Agrega manualmente o importa un CSV.</div>
    <?php else: ?>
    <div class="overflow-x-auto scroll-area">
        <table class="w-full text-xs">
            <thead class="bg-stone-50/60 text-[10px] uppercase tracking-wider text-slate-500">
                <tr>
                    <?php if ($type !== '608'): ?><th class="px-4 py-2 text-left font-bold">RNC</th><?php endif; ?>
                    <th class="px-4 py-2 text-left font-bold">NCF</th>
                    <th class="px-4 py-2 text-left font-bold">Fecha</th>
                    <?php if ($type !== '608'): ?>
                    <th class="px-4 py-2 text-right font-bold">Monto</th>
                    <th class="px-4 py-2 text-right font-bold">ITBIS</th>
                    <th class="px-4 py-2 text-right font-bold">Ret. ITBIS</th>
                    <th class="px-4 py-2 text-right font-bold">Ret. ISR</th>
                    <?php endif; ?>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                <?php foreach ($selectedRows as $r): ?>
                <tr class="hover:bg-stone-50/60">
                    <?php if ($type !== '608'): ?><td class="px-4 py-2 font-mono"><?= htmlspecialchars($r['rnc']) ?></td><?php endif; ?>
                    <td class="px-4 py-2 font-mono"><?= htmlspecialchars($r['ncf']) ?></td>
                    <td class="px-4 py-2"><?= $r['date_doc'] ? date('d/m/Y', strtotime($r['date_doc'])) : '' ?></td>
                    <?php if ($type !== '608'): ?>
                    <td class="px-4 py-2 text-right font-semibold">RD$ <?= number_format((float)$r['amount'], 2) ?></td>
                    <td class="px-4 py-2 text-right">RD$ <?= number_format((float)$r['itbis'], 2) ?></td>
                    <td class="px-4 py-2 text-right text-slate-500">RD$ <?= number_format((float)$r['itbis_retention'], 2) ?></td>
                    <td class="px-4 py-2 text-right text-slate-500">RD$ <?= number_format((float)$r['isr_retention'], 2) ?></td>
                    <?php endif; ?>
                    <td class="px-4 py-2 text-right">
                        <form method="POST" class="inline" onsubmit="return confirm('Eliminar esta linea?')">
                            <input type="hidden" name="action" value="delete_row">
                            <input type="hidden" name="row_id" value="<?= $r['id'] ?>">
                            <button type="submit" class="text-red-400 hover:text-red-600 text-xs font-semibold">Eliminar</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- List view: all filings for type+period -->

<div class="surface-card overflow-hidden mb-4">
    <div class="px-5 py-3 border-b border-stone-100">
        <h3 class="text-base font-bold text-slate-900">Formularios <?= $type ?> &middot; <?= $periodLabel ?></h3>
        <p class="text-xs text-slate-500"><?= count($filingList) ?> cliente(s) con datos</p>
    </div>
    <?php if (empty($filingList)): ?>
    <div class="py-12 text-center text-sm text-slate-400">
        Sin formularios este mes. Crea uno seleccionando un cliente abajo.
    </div>
    <?php else: ?>
    <ul class="divide-y divide-stone-100">
        <?php foreach ($filingList as $f): ?>
        <li class="px-5 py-3.5 hover:bg-stone-50/60 transition-colors">
            <div class="flex flex-col lg:flex-row lg:items-center gap-3">
                <div class="flex items-center gap-3 lg:flex-1 min-w-0">
                    <div class="h-10 w-10 rounded-full bg-stone-100 border border-stone-200 flex items-center justify-center text-xs font-bold text-slate-700 shrink-0">
                        <?= htmlspecialchars(substr(strtoupper($f['client_name']), 0, 1)) ?>
                    </div>
                    <div class="min-w-0">
                        <a href="?type=<?= $type ?>&period=<?= $period ?>&client_id=<?= $f['client_id'] ?>" class="text-sm font-bold text-slate-900 hover:text-blue-600 truncate block"><?= htmlspecialchars($f['client_name']) ?></a>
                        <p class="text-[11px] text-slate-500">RNC <?= htmlspecialchars($f['rnc'] ?: 'N/A') ?></p>
                    </div>
                </div>
                <div class="grid grid-cols-3 gap-3 lg:w-96 shrink-0 text-xs">
                    <div>
                        <p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">Lineas</p>
                        <p class="font-extrabold text-slate-900"><?= (int)$f['total_records'] ?></p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">Monto</p>
                        <p class="font-extrabold text-slate-900">RD$ <?= number_format((float)$f['total_amount'], 0) ?></p>
                    </div>
                    <div>
                        <p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">ITBIS</p>
                        <p class="font-extrabold text-slate-900">RD$ <?= number_format((float)$f['total_itbis'], 0) ?></p>
                    </div>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    <?php if ($f['status'] === 'enviado'): ?>
                    <span class="badge-dot badge-green">Enviado</span>
                    <?php else: ?>
                    <span class="badge-dot badge-amber">Borrador</span>
                    <?php endif; ?>
                    <a href="?type=<?= $type ?>&period=<?= $period ?>&client_id=<?= $f['client_id'] ?>" class="btn-dark !text-xs !py-1.5 !px-3">Abrir</a>
                </div>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<!-- Add filing -->
<div class="surface-card p-5">
    <h4 class="text-sm font-bold text-slate-900 mb-3">Nuevo formulario <?= $type ?> <?= $periodLabel ?></h4>
    <form method="POST" class="flex flex-col sm:flex-row gap-2">
        <input type="hidden" name="action" value="create_filing">
        <select name="client_id" required class="field text-sm flex-1">
            <option value="">Selecciona un cliente...</option>
            <?php foreach ($allClients as $c): ?>
            <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> <?= $c['rnc'] ? '(' . htmlspecialchars($c['rnc']) . ')' : '' ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn-dark text-sm">Crear formulario</button>
    </form>
</div>

<?php endif; ?>

<?php include 'components/layout_end.php'; ?>
