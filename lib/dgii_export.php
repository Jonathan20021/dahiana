<?php
// lib/dgii_export.php
// Exportadores oficiales DGII (TXT + Excel HTML compatible) + bundle ZIP.

if (!defined('DGII_EXPORT_LOADED')) define('DGII_EXPORT_LOADED', true);

/**
 * Devuelve los datos de un filing con sus filas y datos del cliente.
 */
function dgiiFetchFiling($clientId, $filingType, $period) {
    global $pdo;
    $f = $pdo->prepare("
        SELECT f.*, u.name AS client_name, u.business_name, u.rnc
        FROM tax_filings f
        JOIN users u ON u.id = f.client_id
        WHERE f.client_id = ? AND f.filing_type = ? AND f.period = ?
    ");
    $f->execute([$clientId, $filingType, $period]);
    $filing = $f->fetch();
    if (!$filing) return null;

    $r = $pdo->prepare("SELECT * FROM tax_filing_rows WHERE filing_id = ? ORDER BY date_doc ASC, id ASC");
    $r->execute([$filing['id']]);
    $filing['rows'] = $r->fetchAll();
    return $filing;
}

/**
 * Solo digitos (para RNC y NCF que la DGII espera limpios).
 */
function dgiiDigits($s) { return preg_replace('/\D+/', '', (string)$s); }
function dgiiUpper($s)  { return strtoupper(preg_replace('/\s+/', '', (string)$s)); }
function dgiiDate($d)   { $t = $d ? strtotime($d) : false; return $t ? date('Ymd', $t) : ''; }
function dgiiNum($n)    { return number_format((float)$n, 2, '.', ''); }

// --------------------------------------------------------------------------
// TXT oficial DGII
// --------------------------------------------------------------------------

/**
 * Formato 606 (Compras de Bienes y Servicios).
 * Estructura por linea (pipe-delimited):
 *  1. RNC/Cedula proveedor (11 digitos)
 *  2. Tipo identificacion (1=RNC, 2=Cedula, 3=Pasaporte)
 *  3. Tipo bien/servicio (01-11)
 *  4. NCF
 *  5. NCF modificado (si aplica)
 *  6. Fecha comprobante (YYYYMMDD)
 *  7. Fecha pago (YYYYMMDD)
 *  8. Monto facturado servicios
 *  9. Monto facturado bienes
 * 10. Total monto facturado
 * 11. ITBIS facturado
 * 12. ITBIS retenido
 * 13. ITBIS sujeto a proporcionalidad
 * 14. ITBIS llevado al costo
 * 15. ITBIS por adelantar
 * 16. ITBIS percibido en compras
 * 17. Tipo retencion ISR (01 alquileres, 02 honorarios, 03 otros)
 * 18. Monto retencion renta
 * 19. ISR percibido en compras
 * 20. Impuesto selectivo al consumo (ISC)
 * 21. Otros impuestos / tasas
 * 22. Monto propina legal
 * 23. Forma de pago (01-07)
 */
function dgiiTxt606($filing) {
    $rncClient = dgiiDigits($filing['rnc'] ?? '');
    $period    = str_replace('-', '', $filing['period']);
    $rows      = $filing['rows'];
    $lines     = [];
    // Encabezado: 606|RNC|Periodo|TotalRegistros
    $lines[] = "606|{$rncClient}|{$period}|" . count($rows);

    foreach ($rows as $r) {
        $rnc       = dgiiDigits($r['rnc']);
        $tipoId    = strlen($rnc) === 11 ? '2' : '1'; // 11 digitos = cedula
        $tipoBien  = $r['tax_type'] ?: '09';
        $ncf       = dgiiUpper($r['ncf']);
        $ncfMod    = dgiiUpper($r['ncf_modified']);
        $fechaDoc  = dgiiDate($r['date_doc']);
        $fechaPago = dgiiDate($r['date_payment']);
        $monto     = dgiiNum($r['amount']);
        $itbis     = dgiiNum($r['itbis']);
        $isrRet    = dgiiNum($r['isr_retention']);
        $itbisRet  = dgiiNum($r['itbis_retention']);

        // 23 columnas. Los campos que no tenemos van vacios o 0.
        $cols = [
            $rnc, $tipoId, $tipoBien, $ncf, $ncfMod,
            $fechaDoc, $fechaPago,
            '0.00',   // 8 servicios
            $monto,   // 9 bienes (asumimos todo es bien si no se especifica)
            $monto,   // 10 total
            $itbis,   // 11 itbis facturado
            $itbisRet,// 12 itbis retenido
            '0.00',   // 13 proporcionalidad
            '0.00',   // 14 al costo
            '0.00',   // 15 por adelantar
            '0.00',   // 16 percibido en compras
            '',       // 17 tipo retencion isr
            $isrRet,  // 18 monto retencion renta
            '0.00',   // 19 isr percibido
            '0.00',   // 20 ISC
            '0.00',   // 21 otros impuestos
            '0.00',   // 22 propina legal
            '',       // 23 forma de pago
        ];
        $lines[] = implode('|', $cols);
    }
    return implode("\r\n", $lines) . "\r\n";
}

/**
 * Formato 607 (Ventas de Bienes y Servicios).
 * Columnas oficiales:
 *  1. RNC/Cedula comprador
 *  2. Tipo identificacion (1/2/3)
 *  3. NCF
 *  4. NCF modificado
 *  5. Tipo ingreso (01-06)
 *  6. Fecha comprobante YYYYMMDD
 *  7. Fecha retencion YYYYMMDD
 *  8. Monto facturado
 *  9. ITBIS facturado
 * 10. ITBIS retenido por terceros
 * 11. ITBIS percibido
 * 12. Retencion renta por terceros
 * 13. ISR percibido
 * 14. Impuesto selectivo al consumo
 * 15. Otros impuestos / tasas
 * 16. Monto propina legal
 */
function dgiiTxt607($filing) {
    $rncClient = dgiiDigits($filing['rnc'] ?? '');
    $period    = str_replace('-', '', $filing['period']);
    $rows      = $filing['rows'];
    $lines     = [];
    $lines[] = "607|{$rncClient}|{$period}|" . count($rows);

    foreach ($rows as $r) {
        $rnc       = dgiiDigits($r['rnc']);
        $tipoId    = $rnc === '' ? '' : (strlen($rnc) === 11 ? '2' : '1');
        $ncf       = dgiiUpper($r['ncf']);
        $ncfMod    = dgiiUpper($r['ncf_modified']);
        $tipoIng   = '01'; // 01=Ingresos por operaciones
        $fechaDoc  = dgiiDate($r['date_doc']);
        $fechaRet  = dgiiDate($r['date_payment']);
        $monto     = dgiiNum($r['amount']);
        $itbis     = dgiiNum($r['itbis']);
        $itbisRet  = dgiiNum($r['itbis_retention']);
        $isrRet    = dgiiNum($r['isr_retention']);

        $cols = [
            $rnc, $tipoId, $ncf, $ncfMod, $tipoIng,
            $fechaDoc, $fechaRet,
            $monto, $itbis, $itbisRet, '0.00', $isrRet, '0.00', '0.00', '0.00', '0.00',
        ];
        $lines[] = implode('|', $cols);
    }
    return implode("\r\n", $lines) . "\r\n";
}

/**
 * Formato 608 (NCF Anulados).
 * Columnas:
 *  1. NCF
 *  2. Fecha comprobante YYYYMMDD
 *  3. Tipo anulacion (01-09)
 */
function dgiiTxt608($filing) {
    $rncClient = dgiiDigits($filing['rnc'] ?? '');
    $period    = str_replace('-', '', $filing['period']);
    $rows      = $filing['rows'];
    $lines     = [];
    $lines[] = "608|{$rncClient}|{$period}|" . count($rows);
    foreach ($rows as $r) {
        $cols = [dgiiUpper($r['ncf']), dgiiDate($r['date_doc']), '02'];
        $lines[] = implode('|', $cols);
    }
    return implode("\r\n", $lines) . "\r\n";
}

function dgiiTxt($filing) {
    return match ($filing['filing_type']) {
        '606' => dgiiTxt606($filing),
        '607' => dgiiTxt607($filing),
        '608' => dgiiTxt608($filing),
        default => '',
    };
}

function dgiiTxtFilename($filing) {
    $rnc = dgiiDigits($filing['rnc'] ?? '');
    $period = str_replace('-', '', $filing['period']);
    $type = $filing['filing_type'];
    return "DGII_F_{$type}_{$rnc}_{$period}.txt";
}

// --------------------------------------------------------------------------
// Excel HTML (Excel abre HTML como hoja nativa, sin necesidad de PhpSpreadsheet)
// --------------------------------------------------------------------------

function dgiiExcelHeader606() {
    return ['Fecha','RNC/Cedula','Tipo ID','Tipo Bien','NCF','NCF Modificado','Fecha Pago','Monto Facturado','ITBIS Facturado','Ret. ITBIS','Ret. ISR','Propina Legal'];
}
function dgiiExcelHeader607() {
    return ['Fecha','RNC/Cedula Cliente','Tipo ID','NCF','NCF Modificado','Tipo Ingreso','Fecha Retencion','Monto Facturado','ITBIS','Ret. ITBIS Terceros','Ret. ISR Terceros'];
}
function dgiiExcelHeader608() {
    return ['NCF Anulado','Fecha Comprobante','Tipo Anulacion'];
}

function dgiiExcelRows606($filing) {
    $out = [];
    foreach ($filing['rows'] as $r) {
        $rnc = dgiiDigits($r['rnc']);
        $tipoId = $rnc === '' ? '' : (strlen($rnc) === 11 ? 'Cedula' : 'RNC');
        $out[] = [
            $r['date_doc'] ? date('d/m/Y', strtotime($r['date_doc'])) : '',
            $rnc, $tipoId, $r['tax_type'] ?: '09',
            dgiiUpper($r['ncf']), dgiiUpper($r['ncf_modified']),
            $r['date_payment'] ? date('d/m/Y', strtotime($r['date_payment'])) : '',
            number_format((float)$r['amount'], 2, '.', ','),
            number_format((float)$r['itbis'], 2, '.', ','),
            number_format((float)$r['itbis_retention'], 2, '.', ','),
            number_format((float)$r['isr_retention'], 2, '.', ','),
            '0.00',
        ];
    }
    return $out;
}
function dgiiExcelRows607($filing) {
    $out = [];
    foreach ($filing['rows'] as $r) {
        $rnc = dgiiDigits($r['rnc']);
        $tipoId = $rnc === '' ? '' : (strlen($rnc) === 11 ? 'Cedula' : 'RNC');
        $out[] = [
            $r['date_doc'] ? date('d/m/Y', strtotime($r['date_doc'])) : '',
            $rnc, $tipoId,
            dgiiUpper($r['ncf']), dgiiUpper($r['ncf_modified']),
            '01',
            $r['date_payment'] ? date('d/m/Y', strtotime($r['date_payment'])) : '',
            number_format((float)$r['amount'], 2, '.', ','),
            number_format((float)$r['itbis'], 2, '.', ','),
            number_format((float)$r['itbis_retention'], 2, '.', ','),
            number_format((float)$r['isr_retention'], 2, '.', ','),
        ];
    }
    return $out;
}
function dgiiExcelRows608($filing) {
    $out = [];
    foreach ($filing['rows'] as $r) {
        $out[] = [
            dgiiUpper($r['ncf']),
            $r['date_doc'] ? date('d/m/Y', strtotime($r['date_doc'])) : '',
            '02 - Errores de impresion',
        ];
    }
    return $out;
}

/**
 * Genera un archivo Excel-compatible (HTML con mimetype xls).
 * Se abre nativamente en Excel y LibreOffice manteniendo formato y formulas.
 */
function dgiiExcelHtml($filing) {
    $type = $filing['filing_type'];
    $period = $filing['period'];
    $client = $filing['business_name'] ?: $filing['client_name'];
    $rnc = $filing['rnc'] ?: 'N/A';

    if ($type === '606') {
        $headers = dgiiExcelHeader606();
        $rows = dgiiExcelRows606($filing);
        $title = '606 - Compras de Bienes y Servicios';
    } elseif ($type === '607') {
        $headers = dgiiExcelHeader607();
        $rows = dgiiExcelRows607($filing);
        $title = '607 - Ventas de Bienes y Servicios';
    } elseif ($type === '608') {
        $headers = dgiiExcelHeader608();
        $rows = dgiiExcelRows608($filing);
        $title = '608 - NCF Anulados';
    } elseif ($type === 'IT-1') {
        return dgiiExcelHtmlIT1($filing);
    } else {
        return '';
    }

    $totalMonto = (float)$filing['total_amount'];
    $totalItbis = (float)$filing['total_itbis'];

    $css = '
        <style>
            body { font-family: Arial, sans-serif; }
            h1 { font-size: 16pt; color: #0F172A; }
            h2 { font-size: 11pt; color: #475569; font-weight: normal; margin: 0 0 12pt 0; }
            table { border-collapse: collapse; }
            th { background: #0F172A; color: #fff; padding: 8px; font-size: 10pt; border: 1px solid #1E293B; text-align: left; }
            td { padding: 6px 8px; font-size: 10pt; border: 1px solid #E5E7EB; }
            .num { mso-number-format: "#,##0.00"; text-align: right; }
            .total td { background: #F4F4F5; font-weight: bold; }
        </style>
    ';

    ob_start();
    ?>
    <!DOCTYPE html>
    <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">
    <head>
        <meta charset="UTF-8">
        <title><?= htmlspecialchars($title) ?></title>
        <?= $css ?>
    </head>
    <body>
        <h1><?= htmlspecialchars($title) ?></h1>
        <h2><?= htmlspecialchars($client) ?> &middot; RNC <?= htmlspecialchars($rnc) ?> &middot; Periodo <?= htmlspecialchars($period) ?></h2>
        <table>
            <thead>
                <tr>
                    <?php foreach ($headers as $h): ?><th><?= htmlspecialchars($h) ?></th><?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <?php foreach ($row as $i => $cell):
                        $isNum = is_numeric(str_replace([',','.'], '', (string)$cell)) && $i >= 7;
                    ?>
                    <td class="<?= $isNum ? 'num' : '' ?>"><?= htmlspecialchars((string)$cell) ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                <?php if ($type === '606' || $type === '607'): ?>
                <tr class="total">
                    <td colspan="<?= count($headers) - 5 ?>">TOTALES</td>
                    <td></td><td></td>
                    <td class="num"><?= number_format($totalMonto, 2, '.', ',') ?></td>
                    <td class="num"><?= number_format($totalItbis, 2, '.', ',') ?></td>
                    <td colspan="2"></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

/**
 * IT-1 (resumen mensual de ITBIS) - vista ejecutiva.
 */
function dgiiExcelHtmlIT1($filing) {
    global $pdo;
    $clientId = $filing['client_id'];
    $period   = $filing['period'];
    $client   = $filing['business_name'] ?: $filing['client_name'];
    $rnc      = $filing['rnc'] ?: 'N/A';

    $f606 = dgiiFetchFiling($clientId, '606', $period);
    $f607 = dgiiFetchFiling($clientId, '607', $period);

    $itbisCompras = (float)($f606['total_itbis'] ?? 0);
    $itbisVentas  = (float)($f607['total_itbis'] ?? 0);
    $balance      = $itbisVentas - $itbisCompras;

    $css = '
        <style>
            body { font-family: Arial, sans-serif; }
            h1 { font-size: 16pt; color: #0F172A; }
            h2 { font-size: 11pt; color: #475569; font-weight: normal; margin: 0 0 12pt 0; }
            table { border-collapse: collapse; min-width: 600px; }
            th { background: #0F172A; color: #fff; padding: 8px; font-size: 11pt; border: 1px solid #1E293B; text-align: left; }
            td { padding: 8px 12px; font-size: 11pt; border: 1px solid #E5E7EB; }
            .num { mso-number-format: "#,##0.00"; text-align: right; font-family: Consolas, monospace; }
            .total td { background: #F4F4F5; font-weight: bold; font-size: 13pt; }
            .pos { color: #15803D; }
            .neg { color: #DC2626; }
        </style>
    ';
    ob_start();
    ?>
    <!DOCTYPE html>
    <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">
    <head>
        <meta charset="UTF-8">
        <title>IT-1 ITBIS</title>
        <?= $css ?>
    </head>
    <body>
        <h1>IT-1 - Declaracion Mensual de ITBIS</h1>
        <h2><?= htmlspecialchars($client) ?> &middot; RNC <?= htmlspecialchars($rnc) ?> &middot; Periodo <?= htmlspecialchars($period) ?></h2>
        <table>
            <thead>
                <tr><th>Concepto</th><th>Monto RD$</th></tr>
            </thead>
            <tbody>
                <tr><td>ITBIS facturado en ventas (606)</td><td class="num"><?= number_format($itbisVentas, 2, '.', ',') ?></td></tr>
                <tr><td>ITBIS pagado en compras (607)</td><td class="num"><?= number_format($itbisCompras, 2, '.', ',') ?></td></tr>
                <tr class="total">
                    <td><?= $balance >= 0 ? 'ITBIS a pagar a DGII' : 'Saldo a favor del contribuyente' ?></td>
                    <td class="num <?= $balance >= 0 ? 'neg' : 'pos' ?>"><?= number_format(abs($balance), 2, '.', ',') ?></td>
                </tr>
            </tbody>
        </table>
        <p style="margin-top:16pt; font-size:9pt; color:#94a3b8">
            Calculo automatico desde formularios 606 y 607 del periodo. Para presentar a DGII usa la plataforma oficial Oficina Virtual.
        </p>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

function dgiiExcelFilename($filing) {
    $rnc = dgiiDigits($filing['rnc'] ?? '');
    $period = str_replace('-', '', $filing['period']);
    $type = $filing['filing_type'];
    return "DGII_F_{$type}_{$rnc}_{$period}.xls";
}

// --------------------------------------------------------------------------
// HTTP stream helpers
// --------------------------------------------------------------------------

function dgiiStreamTxt($filing) {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . dgiiTxtFilename($filing) . '"');
    echo dgiiTxt($filing);
    exit;
}

function dgiiStreamExcel($filing) {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . dgiiExcelFilename($filing) . '"');
    // BOM para Excel reconozca UTF-8
    echo "\xEF\xBB\xBF" . dgiiExcelHtml($filing);
    exit;
}

/**
 * Bundle ZIP con TXT + Excel de 606/607/608/IT-1 de un cliente y periodo.
 */
function dgiiStreamBundle($clientId, $period) {
    global $pdo;
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        exit('ZipArchive no disponible en este servidor.');
    }
    $client = $pdo->prepare("SELECT name, business_name, rnc FROM users WHERE id=?");
    $client->execute([$clientId]);
    $c = $client->fetch();
    if (!$c) { http_response_code(404); exit('Cliente no encontrado'); }

    $rnc = dgiiDigits($c['rnc'] ?: '');
    $periodKey = str_replace('-', '', $period);
    $tmp = tempnam(sys_get_temp_dir(), 'dgii_bundle_');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500); exit('No se pudo crear el ZIP');
    }

    foreach (['606','607','608','IT-1'] as $type) {
        $filing = dgiiFetchFiling($clientId, $type, $period);
        if (!$filing) continue;
        if ($type !== 'IT-1') {
            $zip->addFromString(dgiiTxtFilename($filing), dgiiTxt($filing));
        }
        $zip->addFromString(dgiiExcelFilename($filing), "\xEF\xBB\xBF" . dgiiExcelHtml($filing));
    }
    $zip->close();

    $bundleName = "DGII_{$rnc}_{$periodKey}.zip";
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $bundleName . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}
