<?php
require_once 'config.php';
requireAuth('admin');
requirePagePermission();

$success = $error = null;

$type = $_GET['type'] ?? '606';
if (!in_array($type, ['606', '607', '608', 'IT-1'], true)) $type = '606';

$period = $_GET['period'] ?? date('Y-m');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) $period = date('Y-m');

$months = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
$periodLabel = $months[(int)substr($period, 5, 2) - 1] . ' ' . substr($period, 0, 4);
$prevPeriod = date('Y-m', strtotime($period . '-01 -1 month'));
$nextPeriod = date('Y-m', strtotime($period . '-01 +1 month'));

$selectedClient = (int)($_GET['client_id'] ?? 0);

$typeLabels = [
    '606'  => ['title' => 'Compras de Bienes y Servicios', 'short' => 'Compras'],
    '607'  => ['title' => 'Ventas de Bienes y Servicios', 'short' => 'Ventas'],
    'IT-1' => ['title' => 'Declaracion Mensual de ITBIS', 'short' => 'ITBIS'],
    '608'  => ['title' => 'NCF Anulados', 'short' => 'Anulados'],
];

// Handle DGII exports (TXT oficial, Excel, ZIP bundle)
if (isset($_GET['export']) && $selectedClient > 0) {
    $exportKind = $_GET['export']; // 'txt' | 'xls' | 'bundle'

    if ($exportKind === 'bundle') {
        dgiiStreamBundle($selectedClient, $period);
        exit;
    }

    $filing = dgiiFetchFiling($selectedClient, $type, $period);
    if ($filing) {
        if ($exportKind === 'xls') {
            dgiiStreamExcel($filing);
        } else {
            // default txt
            dgiiStreamTxt($filing);
        }
    }
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'edit_filing') {
        $fid = (int)($_POST['filing_id'] ?? 0);
        $notes = trim($_POST['notes'] ?? '');
        $newStatus = $_POST['status'] ?? 'borrador';
        if (!in_array($newStatus, ['borrador','enviado'], true)) $newStatus = 'borrador';

        if ($fid > 0) {
            if ($newStatus === 'enviado') {
                $pdo->prepare("UPDATE tax_filings SET notes=?, status='enviado', sent_at = COALESCE(sent_at, NOW()) WHERE id=?")
                    ->execute([$notes, $fid]);
            } else {
                $pdo->prepare("UPDATE tax_filings SET notes=?, status='borrador', sent_at = NULL WHERE id=?")
                    ->execute([$notes, $fid]);
            }
            // Sync obligation status when filing is enviado
            $info = $pdo->prepare("SELECT client_id, filing_type, period FROM tax_filings WHERE id=?");
            $info->execute([$fid]);
            if ($i = $info->fetch()) {
                if ($newStatus === 'enviado') {
                    $pdo->prepare("UPDATE tax_obligations SET status='completado', completed_at=NOW() WHERE client_id=? AND obligation_type=? AND period=?")
                        ->execute([$i['client_id'], $i['filing_type'], $i['period']]);
                    logClientActivity($i['client_id'], 'tax', "Formulario {$i['filing_type']} de {$i['period']} marcado como enviado");
                } else {
                    // Reopen related obligation
                    $pdo->prepare("UPDATE tax_obligations SET status='pendiente', completed_at=NULL WHERE client_id=? AND obligation_type=? AND period=? AND status='completado'")
                        ->execute([$i['client_id'], $i['filing_type'], $i['period']]);
                }
            }
            $success = "Formulario actualizado.";
        }
    } elseif ($action === 'delete_filing') {
        $fid = (int)($_POST['filing_id'] ?? 0);
        if ($fid > 0) {
            $info = $pdo->prepare("SELECT client_id, filing_type, period FROM tax_filings WHERE id=?");
            $info->execute([$fid]);
            $i = $info->fetch();
            $pdo->prepare("DELETE FROM tax_filing_rows WHERE filing_id=?")->execute([$fid]);
            $pdo->prepare("DELETE FROM tax_filings WHERE id=?")->execute([$fid]);
            if ($i) logClientActivity($i['client_id'], 'tax', "Formulario {$i['filing_type']} de {$i['period']} eliminado");
            $success = "Formulario eliminado.";
            // If we were viewing this filing's detail, drop client_id from URL
            if ($selectedClient > 0) {
                header("Location: admin_tax_filings.php?type={$type}&period={$period}");
                exit;
            }
        }
    } elseif ($action === 'create_filing') {
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
                        (function($v){ $t = !empty($v) ? strtotime($v) : false; return $t ? date('Y-m-d', $t) : date('Y-m-d'); })($row[4] ?? ''),
                        (function($v){ $t = !empty($v) ? strtotime($v) : false; return $t ? date('Y-m-d', $t) : null; })($row[5] ?? ''),
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

// Refresh IT-1 totals before listing (only when viewing IT-1)
if ($type === 'IT-1') {
    $clients606 = $pdo->prepare("SELECT DISTINCT client_id FROM tax_filings WHERE period=? AND filing_type IN ('606','607')");
    $clients606->execute([$period]);
    foreach ($clients606->fetchAll(PDO::FETCH_COLUMN) as $cid) {
        recalcIT1ForClient((int)$cid, $period);
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
$page_subtitle = $typeLabels[$type]['title'] . ' · ' . $periodLabel;
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

<?php
// Header metrics differ for IT-1 (derived)
$it1_paid    = (float)$selectedFiling['total_amount']; // ITBIS pagado (606)
$it1_charged = (float)$selectedFiling['total_itbis'];  // ITBIS cobrado (607)
$it1_balance = $it1_charged - $it1_paid;
?>
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
        <?php if ($type === 'IT-1'): ?>
        <div class="lg:ml-auto grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="rounded-xl bg-stone-50 px-3 py-2 text-center">
                <p class="text-[10px] uppercase tracking-wider text-slate-400 font-bold">Documentos</p>
                <p class="text-base font-extrabold text-slate-900"><?= (int)$selectedFiling['total_records'] ?></p>
            </div>
            <div class="rounded-xl bg-emerald-50 px-3 py-2 text-center">
                <p class="text-[10px] uppercase tracking-wider text-emerald-700 font-bold">ITBIS Cobrado (607)</p>
                <p class="text-base font-extrabold text-emerald-700">RD$ <?= number_format($it1_charged, 2) ?></p>
            </div>
            <div class="rounded-xl bg-blue-50 px-3 py-2 text-center">
                <p class="text-[10px] uppercase tracking-wider text-blue-700 font-bold">ITBIS Pagado (606)</p>
                <p class="text-base font-extrabold text-blue-700">RD$ <?= number_format($it1_paid, 2) ?></p>
            </div>
            <div class="rounded-xl <?= $it1_balance > 0 ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-700' ?> px-3 py-2 text-center">
                <p class="text-[10px] uppercase tracking-wider opacity-70 font-bold"><?= $it1_balance > 0 ? 'A pagar' : 'Saldo a favor' ?></p>
                <p class="text-base font-extrabold">RD$ <?= number_format(abs($it1_balance), 2) ?></p>
            </div>
        </div>
        <?php else: ?>
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
        <?php endif; ?>
        <div class="flex items-center gap-2 flex-wrap">
            <?php if ($type !== 'IT-1'): ?>
            <a href="?type=<?= $type ?>&period=<?= $period ?>&client_id=<?= $selectedClient ?>&export=txt" class="btn-soft text-sm" title="Archivo TXT oficial para subir a la Oficina Virtual DGII">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                TXT DGII
            </a>
            <?php endif; ?>
            <a href="?type=<?= $type ?>&period=<?= $period ?>&client_id=<?= $selectedClient ?>&export=xls" class="btn-soft text-sm" title="Hoja Excel con formato listo para revisar">
                <svg class="w-4 h-4 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                Excel
            </a>
            <a href="?type=<?= $type ?>&period=<?= $period ?>&client_id=<?= $selectedClient ?>&export=bundle" class="btn-soft text-sm" title="ZIP con 606, 607, 608, IT-1 (TXT + Excel) del periodo">
                <svg class="w-4 h-4 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                ZIP completo
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

<?php if ($type === 'IT-1'): ?>
<!-- IT-1: composicion del periodo -->
<?php
$it1Rows = $pdo->prepare("
    SELECT r.*, f.filing_type
    FROM tax_filing_rows r
    JOIN tax_filings f ON f.id = r.filing_id
    WHERE f.client_id = ? AND f.period = ? AND f.filing_type IN ('606','607')
    ORDER BY f.filing_type, r.date_doc DESC, r.id DESC
");
$it1Rows->execute([$selectedClient, $period]);
$it1Composition = $it1Rows->fetchAll();
?>
<div class="surface-card overflow-hidden mb-4">
    <div class="px-5 py-3 border-b border-stone-100 flex items-center justify-between">
        <div>
            <h3 class="text-sm font-bold text-slate-900">Composicion del IT-1</h3>
            <p class="text-[11px] text-slate-500">Las lineas se calculan automaticamente a partir del 606 y 607 aprobados desde la IA.</p>
        </div>
        <span class="text-xs text-slate-400"><?= count($it1Composition) ?> documento(s)</span>
    </div>
    <?php if (empty($it1Composition)): ?>
    <div class="py-10 text-center text-sm text-slate-400">
        Aun no hay facturas registradas en 606 o 607 para este periodo. Pide a tu cliente que suba sus facturas para que la IA las procese.
    </div>
    <?php else: ?>
    <div class="overflow-x-auto scroll-area">
        <table class="w-full text-xs">
            <thead class="bg-stone-50/60 text-[10px] uppercase tracking-wider text-slate-500">
                <tr>
                    <th class="px-4 py-2 text-left font-bold">Formulario</th>
                    <th class="px-4 py-2 text-left font-bold">RNC</th>
                    <th class="px-4 py-2 text-left font-bold">NCF</th>
                    <th class="px-4 py-2 text-left font-bold">Fecha</th>
                    <th class="px-4 py-2 text-right font-bold">Base Imp.</th>
                    <th class="px-4 py-2 text-right font-bold">ITBIS</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-stone-100">
                <?php foreach ($it1Composition as $rr):
                    $isVenta = $rr['filing_type'] === '607';
                ?>
                <tr class="hover:bg-stone-50/60">
                    <td class="px-4 py-2">
                        <span class="badge-dot <?= $isVenta ? 'badge-green' : 'badge-blue' ?>"><?= $rr['filing_type'] ?> · <?= $isVenta ? 'Venta' : 'Compra' ?></span>
                    </td>
                    <td class="px-4 py-2 font-mono"><?= htmlspecialchars($rr['rnc']) ?></td>
                    <td class="px-4 py-2 font-mono"><?= htmlspecialchars($rr['ncf']) ?></td>
                    <td class="px-4 py-2"><?= $rr['date_doc'] ? date('d/m/Y', strtotime($rr['date_doc'])) : '' ?></td>
                    <td class="px-4 py-2 text-right font-semibold">RD$ <?= number_format((float)$rr['amount'], 2) ?></td>
                    <td class="px-4 py-2 text-right <?= $isVenta ? 'text-emerald-700 font-bold' : 'text-blue-700 font-bold' ?>">
                        <?= $isVenta ? '+' : '-' ?>RD$ <?= number_format((float)$rr['itbis'], 2) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot class="bg-stone-50/60 border-t border-stone-200">
                <tr>
                    <td colspan="4" class="px-4 py-2 text-right text-[11px] text-slate-500 font-bold uppercase tracking-wider">Saldo IT-1</td>
                    <td class="px-4 py-2"></td>
                    <td class="px-4 py-2 text-right text-base font-extrabold <?= $it1_balance > 0 ? 'text-red-700' : 'text-emerald-700' ?>">
                        RD$ <?= number_format(abs($it1_balance), 2) ?>
                        <span class="text-[10px] font-semibold opacity-70 block"><?= $it1_balance > 0 ? 'a pagar a DGII' : 'a favor del contribuyente' ?></span>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>

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

<?php endif; /* end non IT-1 detail */ ?>

<?php else: ?>
<!-- List view: all filings for type+period -->

<div class="surface-card overflow-hidden mb-4">
    <div class="px-5 py-3 border-b border-stone-100">
        <h3 class="text-base font-bold text-slate-900">Formularios <?= $type ?> &middot; <?= $periodLabel ?></h3>
        <p class="text-xs text-slate-500"><?= count($filingList) ?> cliente(s) con datos</p>
    </div>
    <?php if (empty($filingList)): ?>
    <div class="tf-empty">
        <div class="tf-empty-icon">
            <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
        </div>
        <p class="tf-empty-title">Sin formularios este periodo</p>
        <p class="tf-empty-sub">
            <?php if ($type === 'IT-1'): ?>
            Cuando tus clientes suban facturas y las apruebes, el IT-1 se genera solo.
            <?php else: ?>
            Crea uno seleccionando un cliente abajo o pide a los clientes que suban sus facturas.
            <?php endif; ?>
        </p>
    </div>
    <?php else: ?>
    <div class="tf-rows">
        <?php foreach ($filingList as $f):
            $isSent = $f['status'] === 'enviado';
            $isIT1 = $type === 'IT-1';
            $rowBalance = $isIT1 ? ((float)$f['total_itbis'] - (float)$f['total_amount']) : 0;
        ?>
        <article class="tf-row">
            <a href="?type=<?= $type ?>&period=<?= $period ?>&client_id=<?= $f['client_id'] ?>" class="tf-row-link">
                <div class="tf-avatar">
                    <?= htmlspecialchars(substr(strtoupper($f['client_name']), 0, 1)) ?>
                </div>
                <div class="tf-main">
                    <div class="tf-name-row">
                        <p class="tf-name"><?= htmlspecialchars($f['client_name']) ?></p>
                        <?php if ($f['business_name']): ?>
                        <span class="tf-business">· <?= htmlspecialchars($f['business_name']) ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="tf-rnc">
                        RNC <span class="font-mono"><?= htmlspecialchars($f['rnc'] ?: 'N/A') ?></span>
                    </p>
                </div>
            </a>

            <?php if ($isIT1): ?>
            <div class="tf-metrics tf-metrics-it1">
                <div class="tf-metric">
                    <p class="tf-metric-label">ITBIS Pagado</p>
                    <p class="tf-metric-val tf-blue">RD$ <?= number_format((float)$f['total_amount'], 0) ?></p>
                </div>
                <div class="tf-metric">
                    <p class="tf-metric-label">ITBIS Cobrado</p>
                    <p class="tf-metric-val tf-green">RD$ <?= number_format((float)$f['total_itbis'], 0) ?></p>
                </div>
                <div class="tf-metric tf-balance <?= $rowBalance > 0 ? 'tf-balance-pay' : 'tf-balance-credit' ?>">
                    <p class="tf-metric-label"><?= $rowBalance > 0 ? 'A pagar' : 'A favor' ?></p>
                    <p class="tf-metric-val">RD$ <?= number_format(abs($rowBalance), 0) ?></p>
                </div>
            </div>
            <?php else: ?>
            <div class="tf-metrics">
                <div class="tf-metric">
                    <p class="tf-metric-label">Lineas</p>
                    <p class="tf-metric-val"><?= (int)$f['total_records'] ?></p>
                </div>
                <div class="tf-metric">
                    <p class="tf-metric-label">Monto</p>
                    <p class="tf-metric-val">RD$ <?= number_format((float)$f['total_amount'], 0) ?></p>
                </div>
                <div class="tf-metric">
                    <p class="tf-metric-label">ITBIS</p>
                    <p class="tf-metric-val">RD$ <?= number_format((float)$f['total_itbis'], 0) ?></p>
                </div>
            </div>
            <?php endif; ?>

            <div class="tf-actions">
                <?php if ($isSent): ?>
                <span class="tf-status tf-status-sent">
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    Enviado
                </span>
                <?php else: ?>
                <span class="tf-status tf-status-draft">Borrador</span>
                <?php endif; ?>
                <button type="button"
                        onclick="openEditFiling(<?= $f['id'] ?>, '<?= htmlspecialchars(addslashes($f['client_name']), ENT_QUOTES) ?>', '<?= htmlspecialchars($f['status'], ENT_QUOTES) ?>', <?= json_encode($f['notes'] ?? '') ?>)"
                        class="tf-icon-btn" title="Editar estado y notas">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                </button>
                <form method="POST" class="inline-flex" onsubmit="return confirm('Eliminar este formulario y todas sus lineas? Esta accion no se puede deshacer.')">
                    <input type="hidden" name="action" value="delete_filing">
                    <input type="hidden" name="filing_id" value="<?= $f['id'] ?>">
                    <button type="submit" class="tf-icon-btn tf-icon-danger" title="Eliminar formulario">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3"/></svg>
                    </button>
                </form>
                <a href="?type=<?= $type ?>&period=<?= $period ?>&client_id=<?= $f['client_id'] ?>" class="tf-open-btn">
                    Abrir
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
                </a>
            </div>
        </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<style>
    /* === Tax Filings list === */
    .tf-empty { padding: 60px 20px; text-align: center; }
    .tf-empty-icon { width: 56px; height: 56px; border-radius: 50%; background: #F4F4F5; color: #94A3B8; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 12px; }
    .tf-empty-title { font-size: 14px; font-weight: 700; color: #0F172A; }
    .tf-empty-sub { font-size: 12px; color: #94A3B8; margin-top: 4px; max-width: 380px; margin-left: auto; margin-right: auto; }

    .tf-rows { display: flex; flex-direction: column; }
    .tf-row { display: flex; align-items: center; gap: 14px; padding: 14px 18px; border-bottom: 1px solid #F4F4F5; transition: background .15s ease; }
    .tf-row:hover { background: #FAFAFA; }
    .tf-row:last-child { border-bottom: 0; }
    .tf-row-link { display: flex; align-items: center; gap: 12px; min-width: 0; flex: 1; }
    .tf-avatar { width: 40px; height: 40px; border-radius: 50%; background: linear-gradient(135deg, #F1F5F9, #E5E7EB); border: 1px solid #E5E7EB; display: inline-flex; align-items: center; justify-content: center; font-size: 14px; font-weight: 800; color: #475569; flex-shrink: 0; }
    .tf-main { min-width: 0; flex: 1; }
    .tf-name-row { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
    .tf-name { font-size: 14px; font-weight: 700; color: #0F172A; }
    .tf-row:hover .tf-name { color: #1D4ED8; }
    .tf-business { font-size: 12px; color: #64748B; }
    .tf-rnc { font-size: 11px; color: #94A3B8; margin-top: 2px; }

    .tf-metrics { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; min-width: 280px; }
    .tf-metrics-it1 { min-width: 360px; }
    .tf-metric { padding: 6px 10px; border-radius: 12px; background: #F8FAFC; text-align: left; }
    .tf-metric-label { font-size: 9px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #94A3B8; }
    .tf-metric-val { font-size: 14px; font-weight: 800; color: #0F172A; margin-top: 2px; font-variant-numeric: tabular-nums; }
    .tf-blue { color: #1D4ED8; }
    .tf-green { color: #047857; }
    .tf-balance-pay { background: #FEF2F2; }
    .tf-balance-pay .tf-metric-label { color: #B91C1C; }
    .tf-balance-pay .tf-metric-val { color: #B91C1C; }
    .tf-balance-credit { background: #ECFDF5; }
    .tf-balance-credit .tf-metric-label { color: #047857; }
    .tf-balance-credit .tf-metric-val { color: #047857; }

    .tf-actions { display: flex; align-items: center; gap: 6px; flex-shrink: 0; }
    .tf-status { display: inline-flex; align-items: center; gap: 5px; padding: 5px 10px; border-radius: 999px; font-size: 11px; font-weight: 700; }
    .tf-status-sent { background: #DCFCE7; color: #15803D; }
    .tf-status-draft { background: #FEF3C7; color: #B45309; }
    .tf-icon-btn { width: 32px; height: 32px; border-radius: 10px; background: #F4F4F5; color: #64748B; display: inline-flex; align-items: center; justify-content: center; transition: all .15s ease; }
    .tf-icon-btn:hover { background: #DBEAFE; color: #1D4ED8; }
    .tf-icon-danger:hover { background: #FEE2E2; color: #B91C1C; }
    .tf-open-btn { display: inline-flex; align-items: center; gap: 4px; padding: 7px 14px; border-radius: 11px; background: #0F172A; color: #fff; font-size: 12px; font-weight: 700; transition: all .15s ease; }
    .tf-open-btn:hover { background: #1E293B; }

    @media (max-width: 1024px) {
        .tf-row { flex-wrap: wrap; }
        .tf-metrics { width: 100%; min-width: 0; }
        .tf-actions { width: 100%; justify-content: flex-end; }
    }
</style>

<!-- Edit filing modal -->
<div id="editFilingModal" class="fixed inset-0 z-50 hidden">
    <div class="absolute inset-0 modal-backdrop" onclick="closeEditFiling()"></div>
    <div class="relative flex min-h-full items-center justify-center p-4">
        <div class="w-full max-w-md rounded-3xl bg-white shadow-2xl">
            <div class="px-6 py-5 border-b border-stone-100 flex items-center justify-between">
                <div>
                    <h3 class="text-base font-bold text-slate-900">Editar formulario</h3>
                    <p id="editFilingClientName" class="text-xs text-slate-500 mt-0.5"></p>
                </div>
                <button type="button" onclick="closeEditFiling()" class="text-slate-400 hover:text-slate-700 text-2xl leading-none">&times;</button>
            </div>
            <form method="POST" class="p-6 space-y-4">
                <input type="hidden" name="action" value="edit_filing">
                <input type="hidden" name="filing_id" id="editFilingId" value="">
                <div>
                    <label class="field-label">Estado</label>
                    <select name="status" id="editFilingStatus" class="field text-sm">
                        <option value="borrador">Borrador</option>
                        <option value="enviado">Enviado a DGII</option>
                    </select>
                    <p class="mt-1 text-[11px] text-slate-400">Al marcar como enviado, la obligacion DGII vinculada se completa automaticamente.</p>
                </div>
                <div>
                    <label class="field-label">Notas internas</label>
                    <textarea name="notes" id="editFilingNotes" rows="4" class="field text-sm" placeholder="Comentarios, referencias, numero de declaracion..."></textarea>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="closeEditFiling()" class="btn-soft text-sm">Cancelar</button>
                    <button type="submit" class="btn-dark text-sm">Guardar cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openEditFiling(id, clientName, status, notes) {
    document.getElementById('editFilingId').value = id;
    document.getElementById('editFilingClientName').textContent = clientName;
    document.getElementById('editFilingStatus').value = status;
    document.getElementById('editFilingNotes').value = notes || '';
    document.getElementById('editFilingModal').classList.remove('hidden');
}
function closeEditFiling() {
    document.getElementById('editFilingModal').classList.add('hidden');
}
</script>

<!-- Add filing -->
<?php if ($type === 'IT-1'): ?>
<div class="surface-card p-5 bg-blue-50/40 border-blue-100">
    <div class="flex items-start gap-3">
        <div class="w-10 h-10 rounded-2xl bg-blue-100 text-blue-700 flex items-center justify-center shrink-0">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <div>
            <h4 class="text-sm font-bold text-slate-900">El IT-1 se calcula automaticamente</h4>
            <p class="text-xs text-slate-600 leading-relaxed mt-0.5">No tienes que crearlo: cuando los clientes suben facturas y la IA las aprueba para 606/607, el IT-1 se forma solo. Si cambias o anulas algun NCF, este resumen se actualiza en tiempo real.</p>
        </div>
    </div>
</div>
<?php else: ?>
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
<?php endif; /* end create-filing block for IT-1 */ ?>

<?php endif; /* end list-view block */ ?>

<?php include 'components/layout_end.php'; ?>
