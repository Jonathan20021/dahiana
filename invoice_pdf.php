<?php
require_once 'config.php';
require_once 'vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

requireAuth('admin');

$invoice_id = $_GET['id'] ?? null;
if (!$invoice_id) { die("ID de volante no especificado."); }

$stmt = $pdo->prepare("SELECT i.*, u.name as client_name, u.email as client_email, u.phone as client_phone FROM invoices i JOIN users u ON i.client_id = u.id WHERE i.id = ?");
$stmt->execute([$invoice_id]);
$inv = $stmt->fetch();
if (!$inv) { die("Volante no encontrado."); }

// Load company settings
$settings = getSettings();
$companyName = htmlspecialchars($settings['company_name'] ?? 'Asesoría Financiera');
$companyRNC = htmlspecialchars($settings['company_rnc'] ?? '');
$companyAddress = htmlspecialchars($settings['company_address'] ?? '');
$companyPhone = htmlspecialchars($settings['company_phone'] ?? '');
$companyEmail = htmlspecialchars($settings['company_email'] ?? '');
$companySlogan = htmlspecialchars($settings['company_slogan'] ?? 'Gestión Fiscal y Tributaria');
$companyInitials = htmlspecialchars($settings['company_initials'] ?? 'AF');
$invoiceNote = htmlspecialchars($settings['invoice_note'] ?? 'Este documento no tiene valor fiscal.');

$formattedAmount = number_format($inv['amount'], 2, '.', ',');
$dueDate = date('d/m/Y', strtotime($inv['due_date']));
$createdDate = date('d/m/Y', strtotime($inv['created_at']));
$statusLabel = $inv['status'] === 'pagado' ? 'PAGADO' : 'PENDIENTE';
$statusColor = $inv['status'] === 'pagado' ? '#16a34a' : '#dc2626';
$statusBg = $inv['status'] === 'pagado' ? '#dcfce7' : '#fef2f2';

// Build company info line for footer
$footerParts = [];
if ($companyRNC) $footerParts[] = "RNC: $companyRNC";
if ($companyPhone) $footerParts[] = "Tel: $companyPhone";
if ($companyEmail) $footerParts[] = $companyEmail;
if ($companyAddress) $footerParts[] = $companyAddress;
$footerInfo = implode(' &bull; ', $footerParts);

$html = <<<HTML
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 0; }
    body { font-family: Helvetica, Arial, sans-serif; color: #1e293b; font-size: 13px; line-height: 1.5; margin: 0; padding: 0; }
    
    /* Header Band */
    .header-band {
        background-color: #0f172a;
        color: white;
        padding: 35px 50px;
    }
    .header-table { width: 100%; }
    .header-table td { vertical-align: middle; }
    .logo-box {
        background-color: #1e3a5f;
        width: 52px; height: 52px;
        border-radius: 14px;
        text-align: center; line-height: 52px;
        font-size: 20px; font-weight: bold;
        display: inline-block;
        letter-spacing: 1px;
    }
    .company-name { font-size: 20px; font-weight: bold; margin-left: 14px; display: inline-block; vertical-align: middle; }
    .company-sub { font-size: 11px; opacity: 0.7; margin-left: 14px; display: block; margin-top: 2px; }
    .doc-type { text-align: right; font-size: 28px; font-weight: bold; letter-spacing: -0.5px; }
    .doc-number { text-align: right; font-size: 12px; opacity: 0.7; margin-top: 4px; }
    
    /* Content */
    .content { padding: 40px 50px 20px; }
    
    /* Meta Grid */
    .meta-grid { width: 100%; margin-bottom: 35px; }
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
        background: #f8fafc; border-radius: 16px; padding: 22px 28px; margin-bottom: 30px;
        border: 1px solid #e2e8f0;
    }
    .client-card-title {
        font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase;
        letter-spacing: 1.5px; margin-bottom: 14px;
    }
    .client-table { width: 100%; }
    .client-table td { padding: 5px 0; font-size: 13px; }
    .client-table .label { color: #64748b; width: 100px; font-weight: 600; }
    .client-table .value { color: #1e293b; }
    
    /* Detail Table */
    .detail-section-title {
        font-size: 10px; font-weight: 700; color: #94a3b8; text-transform: uppercase;
        letter-spacing: 1.5px; margin-bottom: 12px;
    }
    .detail-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
    .detail-table th {
        background: #f1f5f9; text-align: left; padding: 12px 16px;
        font-size: 10px; font-weight: 700; color: #64748b;
        text-transform: uppercase; letter-spacing: 1px;
        border-bottom: 2px solid #e2e8f0;
    }
    .detail-table th:last-child { text-align: right; }
    .detail-table td { padding: 14px 16px; border-bottom: 1px solid #f1f5f9; font-size: 13px; }
    .detail-table td:last-child { text-align: right; font-weight: 700; }
    
    /* Total Box */
    .total-box {
        background-color: #f0f9ff;
        border-radius: 16px; padding: 28px 32px; text-align: right;
        border: 1px solid #bfdbfe; margin-bottom: 30px;
    }
    .total-label { font-size: 10px; color: #64748b; text-transform: uppercase; letter-spacing: 1.5px; font-weight: 700; }
    .total-amount { font-size: 36px; font-weight: 800; color: #0f172a; margin-top: 4px; letter-spacing: -1px; }
    .total-due { font-size: 12px; color: #64748b; margin-top: 8px; }
    .total-due strong { color: #1e293b; }
    
    /* Footer */
    .footer {
        position: fixed; bottom: 0; left: 0; right: 0;
        padding: 18px 50px; border-top: 1px solid #e2e8f0;
        font-size: 9px; color: #94a3b8; text-align: center;
        background: #fafbfc;
    }
    .footer-company { font-weight: 600; color: #64748b; }
</style>
</head>
<body>
    <!-- Header Band -->
    <div class="header-band">
        <table class="header-table">
            <tr>
                <td style="width:60%;">
                    <div class="logo-box">{$companyInitials}</div>
                    <span class="company-name">{$companyName}</span>
                    <span class="company-sub">{$companySlogan}</span>
                </td>
                <td style="width:40%;">
                    <div class="doc-type">VOLANTE DE COBRO</div>
                    <div class="doc-number">No. VOL-{$inv['id']}</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="content">
        <!-- Meta Row -->
        <table class="meta-grid">
            <tr>
                <td style="width: 33%;">
                    <div class="meta-label">Fecha de Emisión</div>
                    <div class="meta-value">{$createdDate}</div>
                </td>
                <td style="width: 33%;">
                    <div class="meta-label">Fecha de Vencimiento</div>
                    <div class="meta-value" style="color: #dc2626; font-weight:700;">{$dueDate}</div>
                </td>
                <td style="width: 33%; text-align: right;">
                    <div class="meta-label">Estado</div>
                    <div class="status-pill">{$statusLabel}</div>
                </td>
            </tr>
        </table>

        <!-- Client Card -->
        <div class="client-card">
            <div class="client-card-title">Datos del Cliente</div>
            <table class="client-table">
                <tr><td class="label">Nombre:</td><td class="value">{$inv['client_name']}</td></tr>
                <tr><td class="label">Correo:</td><td class="value">{$inv['client_email']}</td></tr>
                <tr><td class="label">Teléfono:</td><td class="value">{$inv['client_phone']}</td></tr>
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
