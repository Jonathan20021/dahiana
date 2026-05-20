<?php
// lib/email.php - Resend integration + email templates for all modules

/**
 * Resend config from settings table.
 */
function getResendConfig() {
    return [
        'enabled'    => getSetting('email_enabled', '1') === '1',
        'api_key'    => trim(getSetting('resend_api_key', '')),
        'from_email' => trim(getSetting('email_from', 'no-reply@kyrosrd.com')),
        'from_name'  => trim(getSetting('email_from_name', getSetting('company_name', 'Portal Asesoria'))),
        'reply_to'   => trim(getSetting('email_reply_to', getSetting('company_email', ''))),
    ];
}

/**
 * Low-level email sender. Uses Resend HTTP API via cURL.
 * Returns ['ok' => bool, 'code' => int, 'response' => string, 'error' => string]
 */
function sendEmailRaw($to, $subject, $html, $opts = []) {
    $cfg = getResendConfig();

    if (empty($to)) {
        return ['ok' => false, 'reason' => 'no_recipient', 'code' => 0, 'response' => '', 'error' => 'Sin destinatario'];
    }
    if (!$cfg['enabled']) {
        return ['ok' => false, 'reason' => 'disabled', 'code' => 0, 'response' => '', 'error' => 'Envio de correos deshabilitado'];
    }
    if (empty($cfg['api_key'])) {
        return ['ok' => false, 'reason' => 'no_api_key', 'code' => 0, 'response' => '', 'error' => 'API key no configurada'];
    }

    $fromName = $cfg['from_name'] ?: 'Portal Asesoria';
    $fromEmail = $cfg['from_email'] ?: 'no-reply@kyrosrd.com';

    $payload = [
        'from'    => sprintf('%s <%s>', $fromName, $fromEmail),
        'to'      => is_array($to) ? array_values($to) : [$to],
        'subject' => $subject,
        'html'    => $html,
    ];

    if (!empty($cfg['reply_to'])) {
        $payload['reply_to'] = $cfg['reply_to'];
    }
    if (!empty($opts['cc']))     $payload['cc'] = (array) $opts['cc'];
    if (!empty($opts['bcc']))    $payload['bcc'] = (array) $opts['bcc'];
    if (!empty($opts['text']))   $payload['text'] = $opts['text'];

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $cfg['api_key'],
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $response = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $ok = $code >= 200 && $code < 300;

    logEmailDelivery($to, $subject, $ok, $code, $response, $error, $opts['kind'] ?? null, $opts['related_id'] ?? null);

    return ['ok' => $ok, 'code' => $code, 'response' => $response, 'error' => $error];
}

function logEmailDelivery($to, $subject, $ok, $code, $response, $error, $kind = null, $relatedId = null) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("
            INSERT INTO email_log (to_email, subject, success, status_code, response, error, kind, related_id, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            is_array($to) ? implode(',', $to) : (string) $to,
            (string) $subject,
            $ok ? 1 : 0,
            (int) $code,
            (string) $response,
            (string) $error,
            $kind,
            $relatedId,
        ]);
    } catch (PDOException $e) {
        // Swallow log errors
    }
}

/**
 * Base HTML wrapper for all transactional emails. Branded with company info.
 */
function renderEmailBase($headline, $bodyHtml, $ctaUrl = null, $ctaLabel = null) {
    $companyName     = htmlspecialchars(getSetting('company_name', 'Portal Asesoria'));
    $companyInitials = htmlspecialchars(strtoupper(substr(getSetting('company_initials', 'AF'), 0, 2)));
    $companyEmail    = htmlspecialchars(getSetting('company_email', ''));
    $companyPhone    = htmlspecialchars(getSetting('company_phone', ''));
    $companyRnc      = htmlspecialchars(getSetting('company_rnc', ''));
    $year = date('Y');

    $cta = '';
    if ($ctaUrl && $ctaLabel) {
        $cta = "
        <table cellpadding='0' cellspacing='0' style='margin:24px 0;'>
            <tr><td>
                <a href='" . htmlspecialchars($ctaUrl) . "' style='display:inline-block;background:#0F172A;color:#fff;text-decoration:none;padding:13px 26px;border-radius:14px;font-weight:700;font-size:14px;'>" . htmlspecialchars($ctaLabel) . "</a>
            </td></tr>
        </table>";
    }

    $footerContact = '';
    if ($companyEmail) $footerContact .= $companyEmail;
    if ($companyEmail && $companyPhone) $footerContact .= ' &middot; ';
    if ($companyPhone) $footerContact .= $companyPhone;

    $rncLine = $companyRnc ? "<p style='margin:4px 0 0;'>RNC: {$companyRnc}</p>" : '';

    return <<<HTML
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$headline}</title>
</head>
<body style="margin:0;padding:0;background:#ECECEC;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0F172A;-webkit-font-smoothing:antialiased;">
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#ECECEC;padding:24px 12px;">
        <tr><td align="center">
            <table width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background:#ffffff;border-radius:18px;overflow:hidden;border:1px solid #EEF0F2;">
                <!-- Header -->
                <tr>
                    <td style="padding:22px 26px;background:#0F172A;color:#fff;">
                        <table cellpadding="0" cellspacing="0" border="0" width="100%">
                            <tr>
                                <td style="width:44px;vertical-align:middle;">
                                    <div style="width:40px;height:40px;background:rgba(255,255,255,0.15);border-radius:12px;text-align:center;line-height:40px;font-weight:800;font-size:13px;letter-spacing:0.5px;">{$companyInitials}</div>
                                </td>
                                <td style="padding-left:12px;vertical-align:middle;">
                                    <div style="font-weight:700;font-size:15px;line-height:1.2;">{$companyName}</div>
                                    <div style="font-size:11px;color:#94A3B8;line-height:1.2;margin-top:2px;">Portal de gestion fiscal y tributaria</div>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <!-- Body -->
                <tr>
                    <td style="padding:32px 26px 8px;">
                        <h1 style="margin:0 0 16px;font-size:22px;font-weight:800;color:#0F172A;line-height:1.25;">{$headline}</h1>
                        <div style="font-size:14px;color:#334155;line-height:1.6;">{$bodyHtml}</div>
                        {$cta}
                    </td>
                </tr>
                <!-- Footer -->
                <tr>
                    <td style="padding:18px 26px;background:#F8FAFC;border-top:1px solid #EEF0F2;color:#64748B;font-size:11px;text-align:center;">
                        <p style="margin:0 0 4px;font-weight:700;color:#475569;">&copy; {$year} {$companyName}</p>
                        <p style="margin:0;">{$footerContact}</p>
                        {$rncLine}
                    </td>
                </tr>
            </table>
            <p style="margin:18px 0 0;font-size:11px;color:#94A3B8;">Este es un mensaje automatico, por favor no respondas directamente a este correo.</p>
        </td></tr>
    </table>
</body>
</html>
HTML;
}

// ==========================================================================
// Helpers para enviar correos especificos por evento
// ==========================================================================

function emailFmtMoney($amount) {
    return 'RD$ ' . number_format((float) $amount, 2);
}

function emailFmtDate($date) {
    return $date ? date('d/m/Y', strtotime($date)) : 'N/D';
}

function emailFmtPeriod($period) {
    $months = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    if (preg_match('/^(\d{4})-(\d{2})$/', $period, $m)) {
        return $months[(int)$m[2] - 1] . ' ' . $m[1];
    }
    if (preg_match('/^\d{4}$/', $period)) return 'Anual ' . $period;
    return $period;
}

/**
 * Email de bienvenida con credenciales iniciales.
 */
function sendWelcomeEmail($clientId, $plainPassword) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
    $stmt->execute([$clientId]);
    $client = $stmt->fetch();
    if (!$client || empty($client['email'])) return ['ok' => false, 'reason' => 'no_email'];

    $companyName = htmlspecialchars(getSetting('company_name', 'Portal Asesoria'));
    $portalUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/login.php';
    $name = htmlspecialchars($client['name']);
    $emailEsc = htmlspecialchars($client['email']);
    $pwdEsc = htmlspecialchars($plainPassword);

    $body = "
        <p>Hola <strong>{$name}</strong>,</p>
        <p>Te damos la bienvenida a <strong>{$companyName}</strong>. Hemos creado tu cuenta en nuestro portal para que puedas seguir en tiempo real tus tramites fiscales, igualas mensuales y volantes de pago.</p>
        <div style='background:#F8FAFC;border:1px solid #E5E7EB;border-radius:14px;padding:16px;margin:18px 0;'>
            <p style='margin:0 0 6px;font-size:11px;font-weight:700;color:#64748B;text-transform:uppercase;letter-spacing:0.5px;'>Tus credenciales</p>
            <p style='margin:4px 0;'><strong>Email:</strong> <span style='font-family:monospace;'>{$emailEsc}</span></p>
            <p style='margin:4px 0;'><strong>Contrasena:</strong> <span style='font-family:monospace;'>{$pwdEsc}</span></p>
        </div>
        <p>Te recomendamos cambiar tu contrasena en cuanto ingreses por primera vez.</p>
    ";
    $html = wrapEmailBase('Bienvenido a ' . $companyName, $body, $portalUrl, 'Acceder al portal');

    return sendEmailRaw($client['email'], "Bienvenido a {$companyName}", $html, ['kind' => 'welcome', 'related_id' => $clientId]);
}

/**
 * Volante creado.
 */
function sendInvoiceCreatedEmail($invoiceId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT i.*, u.name AS client_name, u.email AS client_email
        FROM invoices i
        JOIN users u ON u.id = i.client_id
        WHERE i.id = ?
    ");
    $stmt->execute([$invoiceId]);
    $inv = $stmt->fetch();
    if (!$inv || empty($inv['client_email'])) return ['ok' => false, 'reason' => 'no_email'];

    $name = htmlspecialchars($inv['client_name']);
    $concept = htmlspecialchars($inv['concept']);
    $amount = emailFmtMoney($inv['amount']);
    $due = emailFmtDate($inv['due_date']);
    $portalUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/client_dashboard.php';

    $body = "
        <p>Hola <strong>{$name}</strong>,</p>
        <p>Hemos generado un nuevo volante de cobro a tu nombre:</p>
        <div style='background:#F8FAFC;border:1px solid #E5E7EB;border-radius:14px;padding:18px;margin:18px 0;'>
            <table cellpadding='0' cellspacing='0' border='0' width='100%'>
                <tr><td style='padding:4px 0;color:#64748B;font-size:12px;'>Concepto</td><td style='padding:4px 0;text-align:right;font-weight:700;'>{$concept}</td></tr>
                <tr><td style='padding:4px 0;color:#64748B;font-size:12px;'>Monto</td><td style='padding:4px 0;text-align:right;font-weight:800;font-size:18px;color:#0F172A;'>{$amount}</td></tr>
                <tr><td style='padding:4px 0;color:#64748B;font-size:12px;'>Vence</td><td style='padding:4px 0;text-align:right;font-weight:700;'>{$due}</td></tr>
            </table>
        </div>
        <p>Puedes consultar el detalle, descargar el PDF y ver tu historial desde el portal.</p>
    ";
    $html = wrapEmailBase('Nuevo volante de cobro', $body, $portalUrl, 'Ver volante');

    return sendEmailRaw($inv['client_email'], "Volante de cobro · {$concept}", $html, ['kind' => 'invoice_created', 'related_id' => $invoiceId]);
}

/**
 * Volante marcado como pagado: confirmacion al cliente.
 */
function sendInvoicePaidEmail($invoiceId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT i.*, u.name AS client_name, u.email AS client_email
        FROM invoices i
        JOIN users u ON u.id = i.client_id
        WHERE i.id = ?
    ");
    $stmt->execute([$invoiceId]);
    $inv = $stmt->fetch();
    if (!$inv || empty($inv['client_email'])) return ['ok' => false, 'reason' => 'no_email'];

    $name = htmlspecialchars($inv['client_name']);
    $concept = htmlspecialchars($inv['concept']);
    $amount = emailFmtMoney($inv['amount']);
    $paidAt = emailFmtDate($inv['paid_at'] ?? date('Y-m-d'));

    $body = "
        <p>Hola <strong>{$name}</strong>,</p>
        <p>Confirmamos la recepcion del pago de tu volante:</p>
        <div style='background:#F0FDF4;border:1px solid #BBF7D0;border-radius:14px;padding:18px;margin:18px 0;'>
            <p style='margin:0 0 6px;font-size:11px;font-weight:700;color:#15803D;text-transform:uppercase;letter-spacing:0.5px;'>Pago confirmado</p>
            <table cellpadding='0' cellspacing='0' border='0' width='100%'>
                <tr><td style='padding:4px 0;color:#64748B;font-size:12px;'>Concepto</td><td style='padding:4px 0;text-align:right;font-weight:700;'>{$concept}</td></tr>
                <tr><td style='padding:4px 0;color:#64748B;font-size:12px;'>Monto</td><td style='padding:4px 0;text-align:right;font-weight:800;font-size:18px;color:#15803D;'>{$amount}</td></tr>
                <tr><td style='padding:4px 0;color:#64748B;font-size:12px;'>Fecha de pago</td><td style='padding:4px 0;text-align:right;font-weight:700;'>{$paidAt}</td></tr>
            </table>
        </div>
        <p>Gracias por tu pago. Si necesitas un recibo formal, escribenos por aqui o por WhatsApp.</p>
    ";
    $html = wrapEmailBase('Pago confirmado', $body);

    return sendEmailRaw($inv['client_email'], "Pago confirmado · {$concept}", $html, ['kind' => 'invoice_paid', 'related_id' => $invoiceId]);
}

/**
 * Solicitud / tramite asignado.
 */
function sendRequestAssignedEmail($requestId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT r.*, s.title AS service_title, s.type AS service_type, u.name AS client_name, u.email AS client_email
        FROM requests r
        JOIN services s ON s.id = r.service_id
        JOIN users u ON u.id = r.client_id
        WHERE r.id = ?
    ");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch();
    if (!$req || empty($req['client_email'])) return ['ok' => false, 'reason' => 'no_email'];

    $name = htmlspecialchars($req['client_name']);
    $title = htmlspecialchars($req['service_title']);
    $portalUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/request_view.php?id=' . (int)$requestId;

    $periodLine = '';
    if ($req['service_type'] === 'iguala' && $req['period']) {
        $periodLine = "<p style='margin:8px 0;color:#475569;font-size:13px;'>Periodo: <strong>" . htmlspecialchars($req['period']) . "</strong></p>";
    } elseif ($req['estimated_delivery_date']) {
        $periodLine = "<p style='margin:8px 0;color:#475569;font-size:13px;'>Entrega estimada: <strong>" . emailFmtDate($req['estimated_delivery_date']) . "</strong></p>";
    }

    $body = "
        <p>Hola <strong>{$name}</strong>,</p>
        <p>Hemos asignado un nuevo servicio a tu cuenta:</p>
        <div style='background:#F8FAFC;border:1px solid #E5E7EB;border-radius:14px;padding:18px;margin:18px 0;'>
            <p style='margin:0;font-weight:700;font-size:15px;color:#0F172A;'>{$title}</p>
            {$periodLine}
        </div>
        <p>Puedes ver el estado, subir documentos y conversar con nosotros desde el portal.</p>
    ";
    $html = wrapEmailBase('Nuevo tramite asignado', $body, $portalUrl, 'Abrir tramite');

    return sendEmailRaw($req['client_email'], "Nuevo tramite · {$title}", $html, ['kind' => 'request_assigned', 'related_id' => $requestId]);
}

/**
 * Cambio de estado en una solicitud.
 */
function sendRequestStatusEmail($requestId, $newStatus) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT r.*, s.title AS service_title, u.name AS client_name, u.email AS client_email
        FROM requests r
        JOIN services s ON s.id = r.service_id
        JOIN users u ON u.id = r.client_id
        WHERE r.id = ?
    ");
    $stmt->execute([$requestId]);
    $req = $stmt->fetch();
    if (!$req || empty($req['client_email'])) return ['ok' => false, 'reason' => 'no_email'];

    $statusLabels = [
        'pendiente' => 'Pendiente por informacion',
        'en_proceso' => 'En proceso',
        'en_revision' => 'En revision final',
        'presentado' => 'Presentado ante la DGII',
        'completado' => 'Completado y entregado',
    ];
    $statusLabel = $statusLabels[$newStatus] ?? $newStatus;

    $name = htmlspecialchars($req['client_name']);
    $title = htmlspecialchars($req['service_title']);
    $portalUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/request_view.php?id=' . (int)$requestId;

    $body = "
        <p>Hola <strong>{$name}</strong>,</p>
        <p>Tu tramite ha cambiado de estado:</p>
        <div style='background:#F8FAFC;border:1px solid #E5E7EB;border-radius:14px;padding:18px;margin:18px 0;'>
            <p style='margin:0 0 8px;font-weight:700;font-size:15px;color:#0F172A;'>{$title}</p>
            <p style='margin:0;'>
                <span style='display:inline-block;background:#0F172A;color:#fff;border-radius:999px;padding:5px 14px;font-size:12px;font-weight:700;'>{$statusLabel}</span>
            </p>
        </div>
        <p>Puedes ver el detalle y enviarnos mensajes desde el portal.</p>
    ";
    $html = wrapEmailBase('Actualizacion de tu tramite', $body, $portalUrl, 'Ver tramite');

    return sendEmailRaw($req['client_email'], "Actualizacion · {$title}", $html, ['kind' => 'request_status', 'related_id' => $requestId]);
}

/**
 * Nuevo comentario en una solicitud. Notifica al lado opuesto (admin -> cliente o viceversa).
 */
function sendRequestCommentEmail($requestId, $commentId, $authorId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT r.*, s.title AS service_title, u.name AS client_name, u.email AS client_email, u.id AS client_id,
               c.message, ua.name AS author_name
        FROM requests r
        JOIN services s ON s.id = r.service_id
        JOIN users u ON u.id = r.client_id
        JOIN request_comments c ON c.id = ?
        LEFT JOIN users ua ON ua.id = c.user_id
        WHERE r.id = ?
    ");
    $stmt->execute([$commentId, $requestId]);
    $req = $stmt->fetch();
    if (!$req) return ['ok' => false, 'reason' => 'not_found'];

    // Decide recipient: if author is the client, notify all admins; else notify the client
    $recipients = [];
    if ((int)$authorId === (int)$req['client_id']) {
        // Cliente comento -> notificar a todos los admins
        $adminEmails = $pdo->query("
            SELECT u.email
            FROM users u
            LEFT JOIN roles r ON r.slug = u.role
            WHERE COALESCE(r.access_level, CASE WHEN u.role = 'admin' THEN 'admin' ELSE 'client' END) = 'admin'
              AND u.email <> ''
        ")->fetchAll(PDO::FETCH_COLUMN);
        $recipients = array_filter($adminEmails);
    } else {
        // Admin comento -> notificar al cliente
        if (!empty($req['client_email'])) $recipients = [$req['client_email']];
    }

    if (empty($recipients)) return ['ok' => false, 'reason' => 'no_recipients'];

    $title = htmlspecialchars($req['service_title']);
    $authorName = htmlspecialchars($req['author_name'] ?? 'Equipo');
    $message = nl2br(htmlspecialchars($req['message']));
    $portalUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/request_view.php?id=' . (int)$requestId;

    $body = "
        <p><strong>{$authorName}</strong> escribio un nuevo mensaje en el tramite <strong>{$title}</strong>:</p>
        <div style='background:#F8FAFC;border-left:3px solid #0F172A;border-radius:8px;padding:14px;margin:18px 0;'>
            <p style='margin:0;font-size:13px;color:#334155;line-height:1.55;'>{$message}</p>
        </div>
    ";
    $html = wrapEmailBase('Nuevo mensaje en tu tramite', $body, $portalUrl, 'Responder en el portal');

    $results = [];
    foreach ($recipients as $em) {
        $results[] = sendEmailRaw($em, "Nuevo mensaje · {$title}", $html, ['kind' => 'request_comment', 'related_id' => $requestId]);
    }
    return $results;
}

/**
 * Recordatorio email para una obligacion DGII.
 */
function sendObligationReminderEmail($obligationId) {
    global $pdo;
    $stmt = $pdo->prepare("
        SELECT o.*, u.name AS client_name, u.email AS client_email
        FROM tax_obligations o
        JOIN users u ON u.id = o.client_id
        WHERE o.id = ?
    ");
    $stmt->execute([$obligationId]);
    $ob = $stmt->fetch();
    if (!$ob || empty($ob['client_email'])) return ['ok' => false, 'reason' => 'no_email'];

    $name = htmlspecialchars($ob['client_name']);
    $obLabel = htmlspecialchars(function_exists('getObligationLabel') ? getObligationLabel($ob['obligation_type']) : $ob['obligation_type']);
    $period = htmlspecialchars(emailFmtPeriod($ob['period']));
    $due = emailFmtDate($ob['due_date']);

    $days = (int) ((strtotime($ob['due_date']) - strtotime(date('Y-m-d'))) / 86400);
    $dayLabel = $days < 0 ? "Vencido hace " . abs($days) . " dias"
              : ($days === 0 ? "Vence hoy" : "Vence en {$days} dias");
    $bgUrgent = $days < 0 ? '#FEF2F2' : ($days <= 3 ? '#FFFBEB' : '#EFF6FF');
    $colorUrgent = $days < 0 ? '#DC2626' : ($days <= 3 ? '#B45309' : '#2563EB');

    $body = "
        <p>Hola <strong>{$name}</strong>,</p>
        <p>Te escribimos para recordarte que la siguiente obligacion DGII esta proxima a vencer:</p>
        <div style='background:{$bgUrgent};border:1px solid #E5E7EB;border-radius:14px;padding:18px;margin:18px 0;'>
            <p style='margin:0 0 8px;font-weight:800;font-size:16px;color:#0F172A;'>{$obLabel}</p>
            <p style='margin:0 0 6px;color:#475569;font-size:13px;'>Periodo: <strong>{$period}</strong></p>
            <p style='margin:0 0 10px;color:#475569;font-size:13px;'>Fecha limite: <strong>{$due}</strong></p>
            <span style='display:inline-block;background:{$colorUrgent};color:#fff;border-radius:999px;padding:5px 14px;font-size:12px;font-weight:700;'>{$dayLabel}</span>
        </div>
        <p>Por favor envianos cualquier documentacion pendiente para procesarla a tiempo.</p>
    ";
    $html = wrapEmailBase('Recordatorio DGII', $body);

    return sendEmailRaw($ob['client_email'], "Recordatorio DGII · {$obLabel} {$period}", $html, ['kind' => 'obligation_reminder', 'related_id' => $obligationId]);
}

/**
 * Correo de prueba desde settings.
 */
function sendTestEmail($toEmail) {
    $body = "
        <p>Si recibes este correo, la configuracion de <strong>Resend</strong> esta funcionando correctamente.</p>
        <p>Fecha de prueba: " . date('d/m/Y H:i') . "</p>
    ";
    $html = wrapEmailBase('Test de correo · Configuracion exitosa', $body);
    return sendEmailRaw($toEmail, 'Test de configuracion de email', $html, ['kind' => 'test', 'related_id' => null]);
}

/**
 * Wrapper que combina renderEmailBase + footerExtra. Usado por todas las helpers.
 */
function wrapEmailBase($headline, $bodyHtml, $ctaUrl = null, $ctaLabel = null) {
    return renderEmailBase($headline, $bodyHtml, $ctaUrl, $ctaLabel);
}

/**
 * Notifica a los admins cuando llega una solicitud de registro publico.
 */
function sendAdminNewSignupEmail($userId) {
    global $pdo;
    $u = $pdo->prepare("SELECT name, email, business_name, phone FROM users WHERE id = ?");
    $u->execute([$userId]);
    $user = $u->fetch();
    if (!$user) return ['ok' => false];

    $admins = $pdo->query("SELECT email FROM users WHERE role = 'admin'")->fetchAll(PDO::FETCH_COLUMN);
    if (empty($admins)) return ['ok' => false];

    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $url   = $proto . '://' . $host . dirname($_SERVER['SCRIPT_NAME']) . '/admin_approvals.php';

    $headline = 'Nueva solicitud de registro';
    $body = '<p>Recibiste una nueva solicitud de cliente.</p>'
          . '<p><strong>' . htmlspecialchars($user['name']) . '</strong></p>'
          . '<p>' . htmlspecialchars($user['email']) . ($user['phone'] ? ' &middot; ' . htmlspecialchars($user['phone']) : '') . '</p>'
          . ($user['business_name'] ? '<p>' . htmlspecialchars($user['business_name']) . '</p>' : '')
          . '<p>Entra al panel para aprobar o rechazar.</p>';

    $html = wrapEmailBase($headline, $body, $url, 'Ir a Aprobaciones');
    foreach ($admins as $adminEmail) {
        sendEmailRaw($adminEmail, 'Nueva solicitud: ' . $user['name'], $html, ['kind' => 'new_signup', 'related_id' => $userId]);
    }
    return ['ok' => true];
}

/**
 * Notifica al cliente cuando su factura es aprobada por el asesor.
 */
function sendInvoiceApprovedEmail($clientId, $extractionId, $filingType, $period) {
    global $pdo;
    $u = $pdo->prepare("SELECT name, email FROM users WHERE id = ?");
    $u->execute([$clientId]);
    $user = $u->fetch();
    if (!$user || empty($user['email'])) return ['ok' => false];

    $e = $pdo->prepare("SELECT counterparty_name, total, itbis, ncf, doc_type FROM invoice_extractions WHERE id = ?");
    $e->execute([$extractionId]);
    $row = $e->fetch();
    if (!$row) return ['ok' => false];

    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host  = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    $url   = $proto . '://' . $host . rtrim($scriptDir, '/') . '/client_uploads.php';

    $tipo = $row['doc_type'] === 'venta' ? 'Venta (607)' : 'Compra (606)';
    $headline = 'Tu factura fue aprobada';
    $body = '<p>Hola ' . htmlspecialchars(explode(' ', $user['name'])[0] ?? '') . ',</p>'
          . '<p>Tu factura fue revisada y aprobada por nuestro equipo. Ya quedo registrada en tu formulario fiscal.</p>'
          . '<div style="margin:16px 0;padding:14px 16px;border:1px solid #E5E7EB;border-radius:12px;background:#F9FAFB">'
          . '<p style="margin:0;font-size:12px;color:#6B7280">' . $tipo . ' &middot; Periodo ' . htmlspecialchars($period) . '</p>'
          . '<p style="margin:6px 0 0;font-size:14px;font-weight:700">' . htmlspecialchars($row['counterparty_name'] ?: '-') . '</p>'
          . '<p style="margin:2px 0 0;font-size:12px;color:#6B7280">NCF ' . htmlspecialchars($row['ncf'] ?: '-') . '</p>'
          . '<p style="margin:10px 0 0;font-size:16px;font-weight:800;color:#0F172A">RD$ ' . number_format((float)$row['total'], 2) . '</p>'
          . '<p style="margin:2px 0 0;font-size:11px;color:#6B7280">ITBIS RD$ ' . number_format((float)$row['itbis'], 2) . '</p>'
          . '</div>'
          . '<p>Puedes ver el detalle y subir mas facturas en tu portal.</p>';

    $html = wrapEmailBase($headline, $body, $url, 'Ver mis facturas');
    return sendEmailRaw($user['email'], 'Factura aprobada - ' . $period, $html, ['kind' => 'invoice_approved', 'related_id' => $extractionId]);
}
