<?php
require_once 'config.php';
require_once 'vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

requireAuth(); // cualquier usuario con sesion; la autorizacion fina se valida abajo

$invoice_id = $_GET['id'] ?? null;
if (!$invoice_id) { die("ID de volante no especificado."); }

$stmt = $pdo->prepare("SELECT i.*, u.name as client_name, u.email as client_email, u.phone as client_phone, u.rnc as client_rnc, u.business_name as client_business FROM invoices i JOIN users u ON i.client_id = u.id WHERE i.id = ?");
$stmt->execute([$invoice_id]);
$inv = $stmt->fetch();
if (!$inv) { die("Volante no encontrado."); }

// Autorizacion: admin ve todo; staff solo sus clientes asignados; cliente solo
// sus propios volantes. clientAccessibleByUser() cubre los tres casos.
if (!clientAccessibleByUser((int)$inv['client_id'])) {
    http_response_code(403);
    die("No tienes permiso para ver este volante.");
}

// Load company settings
$settings = getSettings();
$companyName = htmlspecialchars($settings['company_name'] ?? 'AMD Accounting Consulting');
$companyRNC = htmlspecialchars($settings['company_rnc'] ?? '');
$companyAddress = htmlspecialchars($settings['company_address'] ?? '');
$companyPhone = htmlspecialchars($settings['company_phone'] ?? '');
$companyEmail = htmlspecialchars($settings['company_email'] ?? '');
$companySlogan = htmlspecialchars($settings['company_slogan'] ?? 'Gestión Fiscal y Tributaria');
$invoiceNote = htmlspecialchars($settings['invoice_note'] ?? 'Este documento no tiene valor fiscal.');

// Marca
$b = brandColors();
$logo = brandLogoDataUri();

// Tipo de volante: iguala vs servicio (segun periodo/concepto)
$isIguala = !empty($inv['period']) || stripos($inv['concept'] ?? '', 'iguala') !== false;
$docKind  = $isIguala ? 'Iguala mensual' : 'Servicio';

$formattedAmount = number_format($inv['amount'], 2, '.', ',');
$dueDate = date('d/m/Y', strtotime($inv['due_date']));
$createdDate = date('d/m/Y', strtotime($inv['created_at']));
$periodLabel = '';
if (!empty($inv['period'])) {
    $mm = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    $periodLabel = ($mm[(int)substr($inv['period'], 5, 2)] ?? '') . ' ' . substr($inv['period'], 0, 4);
}
$isPaid = $inv['status'] === 'pagado';
$statusLabel = $isPaid ? 'PAGADO' : 'PENDIENTE';
$statusColor = $isPaid ? '#0F7B3F' : $b['orange_600'];
$statusBg    = $isPaid ? '#E7F6EC' : '#FDF1E4';

$clientName    = htmlspecialchars($inv['client_business'] ?: $inv['client_name']);
$clientRnc     = htmlspecialchars($inv['client_rnc'] ?? '');
$clientEmail   = htmlspecialchars($inv['client_email'] ?? '');
$clientPhone   = htmlspecialchars($inv['client_phone'] ?? '');

// Footer info
$footerParts = [];
if ($companyRNC) $footerParts[] = "RNC: $companyRNC";
if ($companyPhone) $footerParts[] = "Tel: $companyPhone";
if ($companyEmail) $footerParts[] = $companyEmail;
if ($companyAddress) $footerParts[] = $companyAddress;
$footerInfo = implode(' &bull; ', $footerParts);

$blue900 = $b['blue_900'];
$blue700 = $b['blue_700'];
$blue500 = $b['blue_500'];
$orange  = $b['orange'];
$orange6 = $b['orange_600'];

$logoTag = $logo
    ? '<img src="' . $logo . '" style="height:52px; width:auto;" alt="' . $companyName . '">'
    : '<span style="font-size:24px;font-weight:800;color:#fff;">' . $companyName . '</span>';

$periodRow = $periodLabel
    ? '<td style="width: 25%;"><div class="meta-label">Periodo</div><div class="meta-value">' . htmlspecialchars($periodLabel) . '</div></td>'
    : '';
$metaCols = $periodLabel ? '25%' : '33%';

$html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 0; }
    body { font-family: Helvetica, Arial, sans-serif; color: #1e293b; font-size: 13px; line-height: 1.5; margin: 0; padding: 0; }

    /* Header Band */
    .header-band { background-color: {$blue900}; color: #fff; padding: 30px 50px 26px; }
    .accent-strip { height: 6px; background-color: {$orange}; }
    .header-table { width: 100%; }
    .header-table td { vertical-align: middle; }
    .logo-plate {
        background-color: #ffffff; border-radius: 12px; padding: 10px 16px;
        display: inline-block;
    }
    .doc-type { text-align: right; font-size: 26px; font-weight: bold; letter-spacing: -0.5px; color:#fff; }
    .doc-kind { text-align: right; font-size: 12px; color: {$blue500}; margin-top: 3px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; }
    .doc-number { text-align: right; font-size: 12px; color: rgba(255,255,255,0.7); margin-top: 6px; }

    /* Content */
    .content { padding: 36px 50px 20px; }

    /* Meta Grid */
    .meta-grid { width: 100%; margin-bottom: 30px; }
    .meta-grid td { vertical-align: top; padding: 0; }
    .meta-label { font-size: 10px; color: #94a3b8; text-transform: uppercase; letter-spacing: 1.5px; font-weight: 600; margin-bottom: 4px; }
    .meta-value { font-size: 13px; color: #1e293b; font-weight: 500; }

    .status-pill {
        display: inline-block; padding: 5px 16px; border-radius: 20px;
        font-size: 11px; font-weight: 700; letter-spacing: 0.5px;
        color: {$statusColor}; background: {$statusBg};
    }

    /* Client Card */
    .client-card {
        background: #f8fafc; border-radius: 16px; padding: 22px 28px; margin-bottom: 28px;
        border: 1px solid #e2e8f0; border-left: 4px solid {$blue700};
    }
    .client-card-title {
        font-size: 10px; font-weight: 700; color: {$blue700}; text-transform: uppercase;
        letter-spacing: 1.5px; margin-bottom: 14px;
    }
    .client-table { width: 100%; }
    .client-table td { padding: 5px 0; font-size: 13px; }
    .client-table .label { color: #64748b; width: 110px; font-weight: 600; }
    .client-table .value { color: #1e293b; }

    /* Detail Table */
    .detail-section-title {
        font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase;
        letter-spacing: 1.5px; margin-bottom: 12px;
    }
    .detail-table { width: 100%; border-collapse: collapse; margin-bottom: 28px; }
    .detail-table th {
        background: {$blue900}; text-align: left; padding: 12px 16px;
        font-size: 10px; font-weight: 700; color: #fff;
        text-transform: uppercase; letter-spacing: 1px;
    }
    .detail-table th:last-child { text-align: right; }
    .detail-table td { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
    .detail-table td:last-child { text-align: right; font-weight: 700; }

    /* Total Box */
    .total-box {
        background-color: {$blue900};
        border-radius: 16px; padding: 26px 32px; text-align: right; margin-bottom: 26px;
    }
    .total-label { font-size: 10px; color: {$blue500}; text-transform: uppercase; letter-spacing: 1.5px; font-weight: 700; }
    .total-amount { font-size: 34px; font-weight: 800; color: #fff; margin-top: 4px; letter-spacing: -1px; }
    .total-due { font-size: 12px; color: rgba(255,255,255,0.75); margin-top: 8px; }
    .total-due strong { color: {$orange}; }

    /* Footer */
    .footer {
        position: fixed; bottom: 0; left: 0; right: 0;
        padding: 16px 50px; border-top: 3px solid {$orange};
        font-size: 9px; color: #94a3b8; text-align: center;
        background: #fafbfc;
    }
    .footer-company { font-weight: 700; color: {$blue700}; font-size: 10px; }
</style>
</head>
<body>
    <!-- Header Band -->
    <div class="header-band">
        <table class="header-table">
            <tr>
                <td style="width:58%;">
                    <div class="logo-plate">{$logoTag}</div>
                </td>
                <td style="width:42%;">
                    <div class="doc-type">VOLANTE DE COBRO</div>
                    <div class="doc-kind">{$docKind}</div>
                    <div class="doc-number">No. VOL-{$inv['id']}</div>
                </td>
            </tr>
        </table>
    </div>
    <div class="accent-strip"></div>

    <div class="content">
        <!-- Meta Row -->
        <table class="meta-grid">
            <tr>
                <td style="width: {$metaCols};">
                    <div class="meta-label">Fecha de Emisión</div>
                    <div class="meta-value">{$createdDate}</div>
                </td>
                {$periodRow}
                <td style="width: {$metaCols};">
                    <div class="meta-label">Fecha de Vencimiento</div>
                    <div class="meta-value" style="color: {$orange6}; font-weight:700;">{$dueDate}</div>
                </td>
                <td style="width: {$metaCols}; text-align: right;">
                    <div class="meta-label">Estado</div>
                    <div class="status-pill">{$statusLabel}</div>
                </td>
            </tr>
        </table>

        <!-- Client Card -->
        <div class="client-card">
            <div class="client-card-title">Datos del Cliente</div>
            <table class="client-table">
                <tr><td class="label">Nombre:</td><td class="value">{$clientName}</td></tr>
                <tr><td class="label">RNC / Cédula:</td><td class="value">{$clientRnc}</td></tr>
                <tr><td class="label">Correo:</td><td class="value">{$clientEmail}</td></tr>
                <tr><td class="label">Teléfono:</td><td class="value">{$clientPhone}</td></tr>
            </table>
        </div>

        <!-- Detail Table -->
        <div class="detail-section-title">Detalle del Cobro</div>
        <table class="detail-table">
            <thead>
                <tr><th>Concepto</th><th>Monto</th></tr>
            </thead>
            <tbody>
                <tr><td>{$inv['concept']}</td><td>RD\$ {$formattedAmount}</td></tr>
            </tbody>
        </table>

        <!-- Total Box -->
        <div class="total-box">
            <div class="total-label">Total a Pagar</div>
            <div class="total-amount">RD\$ {$formattedAmount}</div>
            <div class="total-due">Fecha Límite de Pago: <strong>{$dueDate}</strong></div>
        </div>
    </div>

    <!-- Footer -->
    <div class="footer">
        <div class="footer-company">{$companyName}</div>
        {$footerInfo}<br>
        {$invoiceNote}
    </div>
</body>
</html>
HTML;

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);
$options->set('defaultFont', 'Helvetica');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$dompdf->stream("Volante_Cobro_VOL-{$inv['id']}.pdf", ['Attachment' => false]);
