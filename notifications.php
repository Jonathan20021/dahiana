<?php
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'unread' => 0, 'items' => []]);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$role   = $_SESSION['role'] ?? '';
$isAdmin = canAccessArea($role, 'admin');

$items = [];
$unread = 0;

if ($isAdmin) {
    $scopeN = clientScopeWhere('client_id');
    // 1. Aprobaciones publicas pendientes
    try {
        $pendApprovals = signupPendingCount();
        if ($pendApprovals > 0) {
            $items[] = [
                'tone' => 'amber',
                'title' => $pendApprovals . ' cliente(s) esperando aprobacion',
                'sub'   => 'Nuevos registros desde la pagina publica',
                'url'   => 'admin_approvals.php',
                'icon'  => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z',
            ];
            $unread += $pendApprovals;
        }
    } catch (Throwable $e) {}

    // 2. Facturas IA por revisar
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM invoice_uploads WHERE status = 'extracted' AND {$scopeN}");
        $pendInv = (int)$stmt->fetchColumn();
        if ($pendInv > 0) {
            $items[] = [
                'tone' => 'blue',
                'title' => $pendInv . ' factura(s) IA por aprobar',
                'sub'   => 'Revisar y aprobar para que pasen al 606/607',
                'url'   => 'admin_invoice_review.php?period=all&status=pending',
                'icon'  => 'M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0',
            ];
            $unread += $pendInv;
        }
    } catch (Throwable $e) {}

    // 3. Obligaciones vencidas
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM tax_obligations WHERE status = 'vencido' AND dismissed_at IS NULL AND {$scopeN}");
        $overdue = (int)$stmt->fetchColumn();
        if ($overdue > 0) {
            $items[] = [
                'tone' => 'red',
                'title' => $overdue . ' obligacion(es) vencida(s)',
                'sub'   => 'Requieren atencion inmediata',
                'url'   => 'admin_tax_calendar.php?range=overdue',
                'icon'  => 'M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 00-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z',
            ];
            $unread += $overdue;
        }
    } catch (Throwable $e) {}

    // 4. Obligaciones que vencen esta semana
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM tax_obligations WHERE status='pendiente' AND dismissed_at IS NULL AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND {$scopeN}");
        $week = (int)$stmt->fetchColumn();
        if ($week > 0) {
            $items[] = [
                'tone' => 'amber',
                'title' => $week . ' obligacion(es) vencen en 7 dias',
                'sub'   => 'Programa los envios DGII',
                'url'   => 'admin_tax_calendar.php?range=week',
                'icon'  => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
            ];
        }
    } catch (Throwable $e) {}

    // 5. Volantes vencidos
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM invoices WHERE status='pendiente' AND due_date < CURDATE() AND {$scopeN}");
        $invOver = (int)$stmt->fetchColumn();
        if ($invOver > 0) {
            $items[] = [
                'tone' => 'red',
                'title' => $invOver . ' volante(s) vencido(s)',
                'sub'   => 'Pagos pendientes de clientes',
                'url'   => 'admin_finances.php',
                'icon'  => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1',
            ];
            $unread += $invOver;
        }
    } catch (Throwable $e) {}

    // 6. Telegram webhook errors recientes (si aplica)
    try {
        $info = getSetting('telegram_enabled', '0') === '1' ? tgGetWebhookInfo() : null;
        if ($info && !empty($info['ok']) && !empty($info['result']['last_error_message'])) {
            $items[] = [
                'tone' => 'amber',
                'title' => 'Webhook Telegram con error',
                'sub'   => substr($info['result']['last_error_message'], 0, 60),
                'url'   => 'admin_telegram_debug.php',
                'icon'  => 'M12 9v2m0 4h.01',
            ];
        }
    } catch (Throwable $e) {}

} else {
    // === Cliente ===
    // 1. Sus obligaciones vencidas
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tax_obligations WHERE client_id=? AND status = 'vencido' AND dismissed_at IS NULL");
        $stmt->execute([$userId]);
        $over = (int)$stmt->fetchColumn();
        if ($over > 0) {
            $items[] = [
                'tone' => 'red',
                'title' => $over . ' obligacion(es) vencida(s)',
                'sub'   => 'Contacta a tu asesor',
                'url'   => 'client_dashboard.php',
                'icon'  => 'M12 9v2m0 4h.01',
            ];
            $unread += $over;
        }
    } catch (Throwable $e) {}

    // 2. Sus volantes pendientes
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoices WHERE client_id=? AND status='pendiente'");
        $stmt->execute([$userId]);
        $pend = (int)$stmt->fetchColumn();
        if ($pend > 0) {
            $items[] = [
                'tone' => 'amber',
                'title' => $pend . ' volante(s) por pagar',
                'sub'   => 'Pagos pendientes de tus servicios',
                'url'   => 'client_dashboard.php',
                'icon'  => 'M12 8c-1.657 0-3 .895-3 2',
            ];
            $unread += $pend;
        }
    } catch (Throwable $e) {}

    // 3. Sus facturas aprobadas recientemente (ultimos 7 dias)
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM invoice_uploads WHERE client_id=? AND status='approved' AND approved_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $stmt->execute([$userId]);
        $approved = (int)$stmt->fetchColumn();
        if ($approved > 0) {
            $items[] = [
                'tone' => 'emerald',
                'title' => $approved . ' factura(s) aprobada(s) esta semana',
                'sub'   => 'Ya estan en tu 606/607',
                'url'   => 'client_uploads.php',
                'icon'  => 'M5 13l4 4L19 7',
            ];
        }
    } catch (Throwable $e) {}

    // 4. Vencimientos proximos
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM tax_obligations WHERE client_id=? AND status='pendiente' AND dismissed_at IS NULL AND due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)");
        $stmt->execute([$userId]);
        $coming = (int)$stmt->fetchColumn();
        if ($coming > 0) {
            $items[] = [
                'tone' => 'blue',
                'title' => $coming . ' vencimiento(s) en 7 dias',
                'sub'   => 'Sube tus facturas a tiempo',
                'url'   => 'client_uploads.php',
                'icon'  => 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
            ];
        }
    } catch (Throwable $e) {}
}

echo json_encode([
    'ok' => true,
    'unread' => $unread,
    'items' => $items,
], JSON_UNESCAPED_UNICODE);
