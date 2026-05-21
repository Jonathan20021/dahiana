<?php
// global_search.php
// Endpoint JSON para la busqueda global del admin.
// Devuelve hasta 30 resultados agrupados por tipo (clientes, facturas, NCF, formularios).

require_once 'config.php';
requireAuth('admin');

header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
if (mb_strlen($q) < 2) {
    echo json_encode(['ok' => true, 'groups' => []]);
    exit;
}

$like = '%' . $q . '%';
$results = [];

// 1. Clientes (por nombre, business_name, email, phone, RNC)
$stmt = $pdo->prepare("
    SELECT u.id, u.name, u.business_name, u.email, u.rnc, u.client_status, u.approval_status
    FROM users u
    LEFT JOIN roles r ON r.slug = u.role
    WHERE COALESCE(r.access_level, CASE WHEN u.role = 'admin' THEN 'admin' ELSE 'client' END) = 'client'
      AND (u.name LIKE ? OR u.business_name LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.rnc LIKE ?)
    ORDER BY u.name
    LIMIT 10
");
$stmt->execute([$like, $like, $like, $like, $like]);
$clients = $stmt->fetchAll();

if (!empty($clients)) {
    $items = [];
    foreach ($clients as $c) {
        $sub = $c['business_name'] ?: '';
        if ($c['rnc']) $sub .= ($sub ? ' · ' : '') . 'RNC ' . $c['rnc'];
        if ($c['email']) $sub .= ($sub ? ' · ' : '') . $c['email'];
        $items[] = [
            'title' => $c['name'],
            'sub'   => $sub ?: '—',
            'url'   => 'client_details.php?id=' . (int)$c['id'],
            'tag'   => $c['approval_status'] === 'pending_approval' ? 'Pendiente aprobar' : ($c['client_status'] ?: 'Activo'),
            'icon'  => 'user',
        ];
    }
    $results[] = ['label' => 'Clientes', 'items' => $items];
}

// 2. Facturas IA (por NCF, RNC contraparte, nombre contraparte)
$stmt = $pdo->prepare("
    SELECT u.id AS upload_id, u.client_id, u.status,
           e.ncf, e.counterparty_name, e.rnc, e.total, e.doc_type, e.period,
           c.name AS client_name
    FROM invoice_uploads u
    LEFT JOIN invoice_extractions e ON e.upload_id = u.id
    LEFT JOIN users c ON c.id = u.client_id
    WHERE e.ncf LIKE ? OR e.counterparty_name LIKE ? OR e.rnc LIKE ? OR u.original_name LIKE ?
    ORDER BY u.created_at DESC
    LIMIT 10
");
$stmt->execute([$like, $like, $like, $like]);
$invoices = $stmt->fetchAll();

if (!empty($invoices)) {
    $items = [];
    foreach ($invoices as $i) {
        $statusMap = [
            'approved'   => 'Aprobada',
            'extracted'  => 'Por aprobar',
            'error'      => 'Error',
            'processing' => 'Procesando',
            'uploaded'   => 'En cola',
        ];
        $sub = ($i['ncf'] ? 'NCF ' . $i['ncf'] . ' · ' : '')
             . ($i['rnc'] ? 'RNC ' . $i['rnc'] . ' · ' : '')
             . 'RD$ ' . number_format((float)$i['total'], 2)
             . ' · ' . ($i['client_name'] ?: '?');
        $items[] = [
            'title' => $i['counterparty_name'] ?: ('Factura #' . $i['upload_id']),
            'sub'   => $sub,
            'url'   => 'admin_invoice_review.php?period=' . urlencode($i['period'] ?: date('Y-m')) . '&client_id=' . (int)$i['client_id'] . '&status=all',
            'tag'   => $statusMap[$i['status']] ?? $i['status'],
            'icon'  => 'invoice',
        ];
    }
    $results[] = ['label' => 'Facturas IA', 'items' => $items];
}

// 3. Solicitudes / requests (por servicio + cliente)
$stmt = $pdo->prepare("
    SELECT r.id, r.status, r.period, r.estimated_delivery_date,
           s.title AS service_title, s.type,
           u.name AS client_name
    FROM requests r
    JOIN services s ON s.id = r.service_id
    JOIN users u ON u.id = r.client_id
    WHERE s.title LIKE ? OR u.name LIKE ?
    ORDER BY r.created_at DESC
    LIMIT 8
");
$stmt->execute([$like, $like]);
$requests = $stmt->fetchAll();
if (!empty($requests)) {
    $items = [];
    foreach ($requests as $r) {
        $sub = $r['client_name'] . ' · ' . ($r['type'] === 'iguala' ? ($r['period'] ?: 'sin periodo') : ($r['estimated_delivery_date'] ?: 'sin fecha'));
        $items[] = [
            'title' => $r['service_title'],
            'sub'   => $sub,
            'url'   => 'request_view.php?id=' . (int)$r['id'],
            'tag'   => $r['status'],
            'icon'  => 'request',
        ];
    }
    $results[] = ['label' => 'Solicitudes', 'items' => $items];
}

// 4. Obligaciones DGII pendientes
if (strlen($q) >= 3) {
    $stmt = $pdo->prepare("
        SELECT o.id, o.obligation_type, o.period, o.due_date, o.status, o.client_id,
               u.name AS client_name
        FROM tax_obligations o
        JOIN users u ON u.id = o.client_id
        WHERE (o.obligation_type LIKE ? OR u.name LIKE ?)
          AND o.status IN ('pendiente','vencido')
        ORDER BY o.due_date ASC
        LIMIT 6
    ");
    $stmt->execute([$like, $like]);
    $obligations = $stmt->fetchAll();
    if (!empty($obligations)) {
        $items = [];
        foreach ($obligations as $o) {
            $items[] = [
                'title' => getObligationLabel($o['obligation_type']) . ' · ' . $o['client_name'],
                'sub'   => 'Periodo ' . formatPeriod($o['period']) . ' · Vence ' . date('d/m/Y', strtotime($o['due_date'])),
                'url'   => 'admin_tax_calendar.php?client_id=' . (int)$o['client_id'],
                'tag'   => $o['status'] === 'vencido' ? 'Vencida' : 'Pendiente',
                'icon'  => 'calendar',
            ];
        }
        $results[] = ['label' => 'Obligaciones DGII', 'items' => $items];
    }
}

echo json_encode(['ok' => true, 'q' => $q, 'groups' => $results], JSON_UNESCAPED_UNICODE);
