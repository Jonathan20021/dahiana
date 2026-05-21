<?php
// components/onboarding.php
//
// Sistema de onboarding multi-tour:
//   - Driver.js para los pasos guiados
//   - Modal picker propio: cuando el usuario abre "Ayuda" elige que tour quiere ver.
//   - Cada tour se marca como visto en localStorage (no spamea al usuario).
//   - El tour "overview" se ejecuta automaticamente la primera vez (1 sola vez por cuenta).
//   - Cada pagina tiene su tour contextual: si abres "Mensajes" y pulsas Ayuda, ofrece el tour de chat primero.
//
// Para que un step se enganche, el elemento debe tener data-tour="<id>".

if (!isset($_SESSION['user_id'])) return;

$onboardingUserId = (int)$_SESSION['user_id'];
$onboardingIsAdmin = canAccessArea($_SESSION['role'] ?? '', 'admin');
$onboardingPage    = basename($_SERVER['PHP_SELF'] ?? '');

$onboardingForce = isset($_GET['startOnboarding']);
$onboardingNeedsRun = false;
if (!$onboardingForce) {
    try {
        $check = $pdo->prepare("SELECT onboarding_completed_at FROM users WHERE id = ?");
        $check->execute([$onboardingUserId]);
        $row = $check->fetch();
        $onboardingNeedsRun = $row && empty($row['onboarding_completed_at']);
    } catch (PDOException $e) {
        $onboardingNeedsRun = false;
    }
}
$onboardingAutoStart = $onboardingForce || $onboardingNeedsRun;

$companyName = trim(getSetting('company_name', 'Portal Asesoria'));
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.css">
<script src="https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.js.iife.js"></script>
<style>
    /* ==== Driver.js custom skin ==== */
    .driver-popover {
        font-family: 'Plus Jakarta Sans', sans-serif;
        max-width: 400px;
        border-radius: 18px !important;
        box-shadow: 0 25px 80px rgba(15, 23, 42, 0.32) !important;
        padding: 0 !important;
        overflow: hidden;
        animation: tourPop .25s cubic-bezier(.2,.8,.2,1);
    }
    @keyframes tourPop { from { opacity: 0; transform: scale(.95) translateY(6px); } to { opacity: 1; transform: none; } }
    .driver-popover-arrow-side-bottom { border-bottom-color: #fff !important; }
    .driver-popover-arrow-side-top { border-top-color: #0F172A !important; }
    .driver-popover-arrow-side-left { border-left-color: #fff !important; }
    .driver-popover-arrow-side-right { border-right-color: #0F172A !important; }
    .driver-popover-title {
        color: #fff !important;
        font-weight: 800 !important;
        font-size: 15px !important;
        background: linear-gradient(135deg, #0F172A, #1E293B);
        margin: 0 !important;
        padding: 18px 22px 14px !important;
        position: relative;
        letter-spacing: -0.01em;
    }
    .driver-popover-title::before {
        content: ''; position: absolute; top: 0; left: 0; right: 0; height: 100%;
        background: radial-gradient(circle at 15% 20%, rgba(59,130,246,0.3), transparent 60%);
        pointer-events: none;
    }
    .driver-popover-description {
        color: #475569 !important;
        font-size: 13px !important;
        line-height: 1.6 !important;
        padding: 16px 22px 8px !important;
    }
    .driver-popover-description b, .driver-popover-description strong { color: #0F172A; font-weight: 800; }
    .driver-popover-description code {
        background: #F1F5F9; padding: 1px 6px; border-radius: 4px;
        font-family: ui-monospace, monospace; font-size: 11.5px; color: #2563EB;
    }
    .driver-popover-footer {
        padding: 6px 18px 18px !important;
        gap: 8px !important;
        display: flex !important;
        align-items: center !important;
    }
    .driver-popover-footer button {
        font-weight: 700 !important;
        font-size: 12.5px !important;
        transition: all .15s ease !important;
        font-family: inherit !important;
    }
    .driver-popover-next-btn {
        background: #0F172A !important; color: #fff !important; text-shadow: none !important;
        border: none !important; border-radius: 10px !important; padding: 9px 16px !important;
        box-shadow: 0 4px 12px rgba(15,23,42,0.18);
    }
    .driver-popover-next-btn:hover { background: #1E293B !important; transform: translateY(-1px); }
    .driver-popover-prev-btn, .driver-popover-close-btn {
        background: #F4F4F5 !important; color: #475569 !important; text-shadow: none !important;
        border: none !important; border-radius: 10px !important; padding: 9px 14px !important;
    }
    .driver-popover-prev-btn:hover, .driver-popover-close-btn:hover { background: #E5E7EB !important; color: #0F172A !important; }
    .driver-popover-progress-text { color: #94a3b8 !important; font-size: 11px !important; font-weight: 700 !important; }
    .driver-popover-close-btn { font-size: 16px !important; padding: 4px 10px !important; }
    .driver-active-element {
        box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.4), 0 0 30px rgba(37, 99, 235, 0.2) !important;
        border-radius: 14px !important;
        transition: box-shadow .25s ease !important;
    }

    /* ==== Tour picker modal ==== */
    .tour-modal { position: fixed; inset: 0; z-index: 9998; display: none; align-items: center; justify-content: center; padding: 20px; }
    .tour-modal.is-open { display: flex; }
    .tour-modal-backdrop { position: absolute; inset: 0; background: rgba(15,23,42,0.55); backdrop-filter: blur(6px); animation: tourFade .25s ease; }
    @keyframes tourFade { from { opacity: 0; } to { opacity: 1; } }
    .tour-modal-card {
        position: relative; width: 100%; max-width: 640px;
        background: #fff; border-radius: 24px;
        box-shadow: 0 30px 80px rgba(15,23,42,0.35);
        animation: tourPop .28s cubic-bezier(.2,.8,.2,1);
        max-height: 90vh; display: flex; flex-direction: column;
    }
    .tour-modal-head {
        padding: 22px 24px 18px;
        border-bottom: 1px solid #F1F5F9;
        display: flex; align-items: center; gap: 14px;
    }
    .tour-modal-head-icon {
        width: 48px; height: 48px;
        background: linear-gradient(135deg, #0F172A, #1E293B);
        color: #fff;
        border-radius: 16px;
        display: inline-flex; align-items: center; justify-content: center;
        box-shadow: 0 8px 24px rgba(15,23,42,0.22);
        flex-shrink: 0;
        position: relative; overflow: hidden;
    }
    .tour-modal-head-icon::after {
        content: ''; position: absolute; inset: 0;
        background: radial-gradient(circle at 25% 25%, rgba(96,165,250,0.55), transparent 60%);
    }
    .tour-modal-head-icon svg { position: relative; z-index: 1; }
    .tour-modal-title { font-size: 18px; font-weight: 800; color: #0F172A; letter-spacing: -0.01em; }
    .tour-modal-sub { font-size: 12.5px; color: #64748B; margin-top: 2px; }
    .tour-modal-close { position: absolute; top: 14px; right: 16px; background: transparent; border: 0; color: #94A3B8; font-size: 24px; cursor: pointer; padding: 4px 8px; line-height: 1; }
    .tour-modal-close:hover { color: #0F172A; }
    .tour-modal-body { padding: 18px 24px 22px; overflow-y: auto; flex: 1; }
    .tour-grid { display: grid; grid-template-columns: 1fr; gap: 10px; }
    @media (min-width: 540px) { .tour-grid { grid-template-columns: 1fr 1fr; } }
    .tour-card {
        position: relative;
        display: flex; align-items: center; gap: 12px;
        padding: 14px;
        background: #fff; border: 1.5px solid #EEF0F2; border-radius: 16px;
        cursor: pointer; transition: all .18s ease;
        text-align: left;
        font-family: inherit;
    }
    .tour-card:hover { border-color: #0F172A; background: #FAFAFA; transform: translateY(-2px); box-shadow: 0 6px 20px rgba(15,23,42,0.06); }
    .tour-card-icon { width: 38px; height: 38px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .tour-card-main { flex: 1; min-width: 0; }
    .tour-card-title { font-size: 13.5px; font-weight: 800; color: #0F172A; line-height: 1.25; display: flex; align-items: center; gap: 6px; }
    .tour-card-sub { font-size: 11.5px; color: #64748B; margin-top: 3px; line-height: 1.35; }
    .tour-card-time { font-size: 10px; color: #94A3B8; font-weight: 700; margin-top: 6px; display: inline-flex; align-items: center; gap: 4px; }
    .tour-card-arrow { color: #CBD5E1; flex-shrink: 0; }
    .tour-card:hover .tour-card-arrow { color: #0F172A; transform: translateX(2px); transition: transform .15s ease; }
    .tour-card-done { background: #F0FDF4; border-color: #BBF7D0; }
    .tour-card-done .tour-card-icon { background: #DCFCE7; color: #15803D; }
    .tour-pill-new { display: inline-block; padding: 1px 6px; background: #2563EB; color: #fff; border-radius: 999px; font-size: 9px; font-weight: 800; letter-spacing: 0.04em; }
    .tour-pill-done { display: inline-flex; align-items: center; gap: 3px; padding: 2px 7px; background: #DCFCE7; color: #15803D; border-radius: 999px; font-size: 9.5px; font-weight: 800; }
    .tour-modal-foot {
        padding: 14px 24px; border-top: 1px solid #F1F5F9;
        display: flex; align-items: center; justify-content: space-between;
        font-size: 11.5px; color: #94A3B8;
    }
    .tour-modal-foot button { background: transparent; border: 0; color: #2563EB; font-weight: 700; cursor: pointer; font-family: inherit; }
    .tour-modal-foot button:hover { text-decoration: underline; }

    /* Welcome splash al primer arranque */
    .tour-welcome-splash {
        max-width: 460px !important;
    }
    .tour-welcome-splash .driver-popover-title { font-size: 22px !important; padding: 36px 26px 14px !important; }
    .tour-welcome-splash .driver-popover-description { padding-bottom: 22px !important; font-size: 13.5px !important; }
</style>
<script>
(function() {
    const ROLE = <?= json_encode($onboardingIsAdmin ? 'admin' : 'client') ?>;
    const PAGE = <?= json_encode($onboardingPage) ?>;
    const AUTO = <?= $onboardingAutoStart ? 'true' : 'false' ?>;
    const COMPANY = <?= json_encode($companyName) ?>;
    const MARK_URL = 'onboarding_complete.php';
    const SEEN_KEY = 'tour_seen_' + ROLE;

    // ====== SVG helper ======
    function icon(svg, size) {
        size = size || 22;
        return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + svg + '</svg>';
    }
    const ICONS = {
        rocket:    '<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 00-2.91-.09z"/><path d="M12 15l-3-3a22 22 0 012-3.95A12.88 12.88 0 0122 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 01-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/>',
        chat:      '<path d="M21 11.5a8.38 8.38 0 01-.9 3.8 8.5 8.5 0 01-7.6 4.7 8.38 8.38 0 01-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 01-.9-3.8 8.5 8.5 0 014.7-7.6 8.38 8.38 0 013.8-.9h.5a8.48 8.48 0 018 8v.5z"/>',
        document:  '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
        invoice:   '<path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="15" y2="17"/>',
        money:     '<line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 000 7h5a3.5 3.5 0 010 7H6"/>',
        calendar:  '<rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
        upload:    '<path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/>',
        ai:        '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
        telegram:  '<path d="M21.5 4.5L2.5 12l5.5 2 2 6 3.5-4 6 4.5 2-16z"/>',
        install:   '<path d="M12 17V3"/><polyline points="6 11 12 17 18 11"/><line x1="3" y1="21" x2="21" y2="21"/>',
        users:     '<path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/>',
        approve:   '<path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>',
        settings:  '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/>',
        chart:     '<line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/>',
        help:      '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 015.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
    };

    // ====== Persistencia de tours vistos ======
    function getSeen() {
        try { return JSON.parse(localStorage.getItem(SEEN_KEY) || '[]'); } catch (e) { return []; }
    }
    function markSeen(tourId) {
        const seen = getSeen();
        if (!seen.includes(tourId)) {
            seen.push(tourId);
            try { localStorage.setItem(SEEN_KEY, JSON.stringify(seen)); } catch (e) {}
        }
    }
    function resetSeen() {
        try { localStorage.removeItem(SEEN_KEY); } catch (e) {}
    }

    // ====== Catalogo de tours ======
    const TOURS_ADMIN = {
        overview: {
            name: 'Vista general del portal',
            sub: 'Tour completo de todos los modulos',
            time: '~2 min',
            icon: ICONS.rocket,
            color: 'blue',
            steps: () => [
                {
                    popover: { title: 'Bienvenido a ' + COMPANY, popoverClass: 'tour-welcome-splash',
                        description: 'Este es tu panel de control. En 2 minutos te muestro como esta organizado todo. Puedes saltarlo y volver despues con el boton <b>Ayuda</b> del menu.' }
                },
                { element: '[data-tour="nav-dashboard"]', popover: { title: 'Vista 360', side: 'right',
                    description: 'Resumen ejecutivo: clientes activos, facturas por aprobar, vencimientos cercanos, ingresos del mes.' } },
                { element: '[data-tour="nav-clients"]', popover: { title: 'Clientes', side: 'right',
                    description: 'Tu cartera. Cada cliente tiene perfil fiscal (RNC, regimen, actividad) y codigo unico para Telegram.' } },
                { element: '[data-tour="nav-approvals"]', popover: { title: 'Aprobaciones publicas', side: 'right',
                    description: 'Quienes se registran desde la pagina publica llegan aqui. Apruebas en 1 click y el sistema crea sus tramites automaticamente.' } },
                { element: '[data-tour="nav-requests"]', popover: { title: 'Solicitudes', side: 'right',
                    description: 'Todos los tramites de tus clientes (renovacion RNC, declaraciones, etc.). Asigna estados y comenta.' } },
                { element: '[data-tour="nav-finances"]', popover: { title: 'Finanzas', side: 'right',
                    description: 'Volantes de cobro, igualas y pagos pendientes. Crea volantes con un click y notifica al cliente.' } },
                { element: '[data-tour="nav-messages"]', popover: { title: 'Mensajes', side: 'right',
                    description: '<b>NUEVO.</b> Chat directo con cada cliente sin salir del portal. El badge rojo muestra cuantos hilos tienen mensajes sin leer.' } },
                { element: '[data-tour="nav-documents"]', popover: { title: 'Documentos compartidos', side: 'right',
                    description: '<b>NUEVO.</b> Sube declaraciones, comprobantes y reportes para tus clientes. Veran un badge "NUEVO" en su portal.' } },
                { element: '[data-tour="nav-invoice-review"]', popover: { title: 'Revisar facturas IA', side: 'right',
                    description: '<b>El corazon del portal.</b> Los clientes suben facturas (portal o Telegram), la IA extrae los datos, y aqui las validas y aprobas con un click.' } },
                { element: '[data-tour="nav-tax-filings"]', popover: { title: 'Formularios fiscales', side: 'right',
                    description: 'El 606, 607, IT-1 y 608 se construyen solos desde las facturas aprobadas. Exporta en formato oficial DGII.' } },
                { element: '[data-tour="nav-tax-calendar"]', popover: { title: 'Calendario fiscal', side: 'right',
                    description: 'Todas las obligaciones DGII en un mapa: pendientes, vencidas, completadas.' } },
                { element: '[data-tour="nav-settings"]', popover: { title: 'Configuracion', side: 'right',
                    description: 'OpenAI, Telegram, Resend, identidad. <b>Si la IA esta apagada, primero configura aqui la API key.</b>' } },
                { element: '[data-tour="help-button"]', popover: { title: 'Ayuda siempre disponible', side: 'right',
                    description: 'Vuelve a este tour cuando quieras desde aqui. Tambien hay tours especificos por modulo.' } },
            ]
        },
        invoices: {
            name: 'Revisar facturas con IA',
            sub: 'Como aprobar las facturas que extrajo la IA',
            time: '~1 min',
            icon: ICONS.ai,
            color: 'indigo',
            steps: () => [
                { popover: { title: 'Como funciona la IA fiscal',
                    description: 'Los clientes suben fotos (portal o Telegram). La IA usa <b>consenso de 2 modelos</b> en paralelo: si ambos coinciden, marca alta confianza; si difieren en RNC/NCF/total, lo marca para revision.' } },
                { element: '[data-tour="nav-invoice-review"]', popover: { title: 'Ir a Revisar facturas', side: 'right',
                    description: 'Aqui llegan todas las facturas extraidas. El badge rojo muestra cuantas esperan tu validacion.' } },
                { element: '[data-tour="upload-zone"]', popover: { title: 'Subir tu mismo',
                    description: 'Si recibes facturas por WhatsApp o email, las subes desde aqui asignandolas al cliente.', side: 'bottom' } },
                { element: '[data-tour="filters"]', popover: { title: 'Filtros',
                    description: 'Filtra por periodo, estado (por aprobar / aprobadas), tipo (compra / venta) o cliente.', side: 'bottom' } },
            ]
        },
        chat: {
            name: 'Chat con tus clientes',
            sub: 'Mensajes generales sin salir del portal',
            time: '~30s',
            icon: ICONS.chat,
            color: 'emerald',
            steps: () => [
                { popover: { title: 'Mensajes',
                    description: 'Un inbox tipo email/WhatsApp con todos tus clientes. Cada uno tiene su hilo independiente. Los clientes te ven en su modulo <b>Mensajes</b>.' } },
                { element: '[data-tour="nav-messages"]', popover: { title: 'Abre Mensajes', side: 'right',
                    description: 'El badge rojo te dice cuantos hilos tienen mensajes sin leer.' } },
            ]
        },
        documents: {
            name: 'Compartir documentos',
            sub: 'Enviar declaraciones, comprobantes y reportes',
            time: '~30s',
            icon: ICONS.document,
            color: 'amber',
            steps: () => [
                { popover: { title: 'Documentos compartidos',
                    description: 'Sube archivos por cliente con <b>categoria</b> (declaracion DGII, comprobante, reporte mensual, contrato) y <b>periodo</b>. Veran un badge "NUEVO" en su portal hasta que abran el archivo.' } },
                { element: '[data-tour="nav-documents"]', popover: { title: 'Abre Documentos', side: 'right',
                    description: 'Filtra por cliente y categoria, ve cuales ya fueron leidos.' } },
            ]
        },
        telegram: {
            name: 'Bot de Telegram',
            sub: 'Recibir facturas por chat',
            time: '~1 min',
            icon: ICONS.telegram,
            color: 'blue',
            steps: () => [
                { popover: { title: 'Bot de Telegram',
                    description: 'Tus clientes mandan fotos al bot y la IA las procesa igual que en el portal. Util para clientes que no quieren entrar al sitio.' } },
                { element: '[data-tour="nav-settings"]', popover: { title: 'Configurar el bot', side: 'right',
                    description: 'Ve a <b>Configuracion → Telegram Bot</b>, pega el token de @BotFather y conecta el webhook. El usuario aparece automatico despues de conectar.' } },
            ]
        },
        install: {
            name: 'Instalar el portal como app',
            sub: 'Acceso directo desde el celular o escritorio',
            time: '~15s',
            icon: ICONS.install,
            color: 'slate',
            steps: () => [
                { popover: { title: 'Instalar como app',
                    description: 'El portal es una PWA: puedes instalarlo en iPhone, Android, Windows y Mac. Funciona offline parcialmente y tiene icono propio.' } },
                { element: '#pwaInstallTrigger', popover: { title: 'Boton Instalar', side: 'bottom',
                    description: 'Pulsa aqui para abrir las instrucciones especificas de tu navegador. Si ya esta instalada, el boton no aparece.' } },
            ]
        },
    };

    const TOURS_CLIENT = {
        overview: {
            name: 'Bienvenida al portal',
            sub: 'Tour rapido de lo que puedes hacer',
            time: '~1 min',
            icon: ICONS.rocket,
            color: 'blue',
            steps: () => [
                { popover: { title: '¡Bienvenido a ' + COMPANY + '!', popoverClass: 'tour-welcome-splash',
                    description: 'Tu espacio para mandar facturas, ver el estado de tus tramites y chatear con tu asesor. En 1 minuto te enseno lo basico.' } },
                { element: '[data-tour="nav-dashboard"]', popover: { title: 'Mi Panel', side: 'right',
                    description: 'Resumen de tu cuenta: facturas del mes, IT-1 estimado, vencimientos.' } },
                { element: '[data-tour="nav-messages"]', popover: { title: 'Mensajes', side: 'right',
                    description: '<b>NUEVO.</b> Chat directo con tu asesor para cualquier duda. No necesitas salir del portal.' } },
                { element: '[data-tour="nav-requests"]', popover: { title: 'Mis tramites', side: 'right',
                    description: '<b>NUEVO.</b> Estado en vivo de todos los servicios que tu asesor esta gestionando.' } },
                { element: '[data-tour="nav-uploads"]', popover: { title: 'Subir facturas con IA', side: 'right',
                    description: 'Toma fotos a tus facturas. La IA lee el RNC, NCF, ITBIS y monto automaticamente.' } },
                { element: '[data-tour="nav-calendar"]', popover: { title: 'Mi calendario fiscal', side: 'right',
                    description: 'Vencimientos DGII de tus declaraciones. Te avisamos antes de que se venzan.' } },
                { element: '[data-tour="nav-documents"]', popover: { title: 'Mis documentos', side: 'right',
                    description: '<b>NUEVO.</b> Aqui llegan los archivos que tu asesoria comparte contigo: declaraciones, reportes, comprobantes.' } },
                { element: '[data-tour="nav-invoices"]', popover: { title: 'Mis volantes de cobro', side: 'right',
                    description: '<b>NUEVO.</b> Pagos pendientes y tu historial. Si tienes algo vencido, te avisamos en rojo.' } },
                { element: '[data-tour="nav-profile"]', popover: { title: 'Mi perfil', side: 'right',
                    description: 'Datos personales, tu codigo de Telegram y cambio de contraseña.' } },
                { element: '[data-tour="help-button"]', popover: { title: 'Ayuda siempre a la mano', side: 'right',
                    description: 'Vuelve a este tour cuando quieras. Tambien hay tours especificos por modulo.' } },
            ]
        },
        upload: {
            name: 'Como subir facturas',
            sub: 'Desde la app o desde Telegram',
            time: '~45s',
            icon: ICONS.upload,
            color: 'indigo',
            steps: () => [
                { popover: { title: 'Subir facturas con IA',
                    description: 'Toma una foto bien iluminada a tu factura, subela y la IA lee todo. Tu asesor solo valida.' } },
                { element: '[data-tour="nav-uploads"]', popover: { title: 'Ir a subir', side: 'right',
                    description: 'Click aqui o arrastra fotos al panel principal.' } },
                { element: '[data-tour="upload-hero"]', popover: { title: 'O desde el panel principal', side: 'bottom',
                    description: 'Tienes acceso rapido desde el dashboard tambien.' } },
                { element: '[data-tour="telegram-card"]', popover: { title: 'Aun mas comodo: Telegram', side: 'left',
                    description: 'Conecta tu Telegram con un codigo de 8 caracteres y envia fotos directo desde el chat sin abrir el portal.' } },
            ]
        },
        chat: {
            name: 'Chatear con tu asesor',
            sub: 'Sin salir del portal',
            time: '~20s',
            icon: ICONS.chat,
            color: 'emerald',
            steps: () => [
                { popover: { title: 'Mensajes',
                    description: 'Chat tipo WhatsApp con tu asesor. Puedes mandar mensajes generales o comentar en un tramite especifico.' } },
                { element: '[data-tour="nav-messages"]', popover: { title: 'Abre Mensajes', side: 'right',
                    description: 'El badge rojo te dice cuando tu asesor te respondio.' } },
            ]
        },
        requests: {
            name: 'Ver mis tramites',
            sub: 'Estado de los servicios en gestion',
            time: '~30s',
            icon: ICONS.invoice,
            color: 'amber',
            steps: () => [
                { popover: { title: 'Mis tramites',
                    description: 'Cada servicio que tu asesoria esta gestionando para ti aparece aqui con su <b>barra de progreso</b>: pendiente → en proceso → en revision → presentado → completado.' } },
                { element: '[data-tour="nav-requests"]', popover: { title: 'Abrir mis tramites', side: 'right',
                    description: 'El badge muestra cuantos tienes activos. Toca uno para ver detalles y comentar.' } },
            ]
        },
        invoices: {
            name: 'Pagar mis volantes',
            sub: 'Pendientes, vencimientos y como pagar',
            time: '~30s',
            icon: ICONS.money,
            color: 'red',
            steps: () => [
                { popover: { title: 'Mis volantes de cobro',
                    description: 'Aqui ves lo que debes pagar a tu asesoria. Si hay algo vencido te aparece un banner rojo con boton directo a WhatsApp.' } },
                { element: '[data-tour="nav-invoices"]', popover: { title: 'Abrir Mis volantes', side: 'right',
                    description: 'El badge muestra cuantos pagos tienes pendientes.' } },
            ]
        },
        install: {
            name: 'Instalar como app',
            sub: 'Acceso directo desde tu inicio',
            time: '~15s',
            icon: ICONS.install,
            color: 'slate',
            steps: () => [
                { popover: { title: 'Usalo como una app',
                    description: 'Instalala en tu telefono y tendras un icono propio que abre directo al portal, sin barra del navegador, mas rapido.' } },
                { element: '#pwaInstallTrigger', popover: { title: 'Boton Instalar', side: 'bottom',
                    description: 'Pulsa aqui. Te muestro los pasos exactos para tu telefono.' } },
            ]
        },
    };

    const TOURS = ROLE === 'admin' ? TOURS_ADMIN : TOURS_CLIENT;

    // Mapeo pagina -> tour contextual sugerido
    const PAGE_TO_TOUR = {
        // admin
        'admin_invoice_review.php': 'invoices',
        'admin_messages.php':       'chat',
        'admin_documents.php':      'documents',
        'admin_settings.php':       'telegram',
        // cliente
        'client_uploads.php':       'upload',
        'client_messages.php':      'chat',
        'client_requests.php':      'requests',
        'client_invoices.php':      'invoices',
    };

    // ====== Ejecutar un tour ======
    function runTour(tourId) {
        const tour = TOURS[tourId];
        if (!tour) return;
        const allSteps = tour.steps();
        // Filtrar steps sin elemento presente (excepto los que no piden elemento)
        const steps = allSteps.filter(s => !s.element || document.querySelector(s.element));
        if (steps.length === 0) return;
        const d = window.driver.js.driver({
            showProgress: steps.length > 1,
            allowClose: true,
            overlayColor: 'rgba(15, 23, 42, 0.6)',
            nextBtnText: 'Siguiente →',
            prevBtnText: '← Atras',
            doneBtnText: '✓ Listo',
            progressText: '{{current}} / {{total}}',
            steps: steps,
            onDestroyed: () => {
                markSeen(tourId);
                if (tourId === 'overview') {
                    fetch(MARK_URL, { method: 'POST' }).catch(() => {});
                }
            },
        });
        d.drive();
    }

    // ====== Modal picker ======
    function buildPicker() {
        if (document.getElementById('tourPicker')) return;
        const seen = getSeen();
        const tourEntries = Object.entries(TOURS);
        // Tour contextual primero (si hay)
        const suggested = PAGE_TO_TOUR[PAGE];
        if (suggested && TOURS[suggested]) {
            const idx = tourEntries.findIndex(([id]) => id === suggested);
            if (idx > 0) {
                const [item] = tourEntries.splice(idx, 1);
                tourEntries.unshift(item);
            }
        }

        let cardsHtml = '';
        tourEntries.forEach(([id, t]) => {
            const isDone = seen.includes(id);
            const isSuggested = id === suggested;
            cardsHtml += '<button type="button" class="tour-card ' + (isDone ? 'tour-card-done' : '') + '" data-tour-run="' + id + '">'
                + '<div class="tour-card-icon tour-icon-' + t.color + '">' + icon(t.icon, 18) + '</div>'
                + '<div class="tour-card-main">'
                +   '<div class="tour-card-title">' + t.name
                +     (isSuggested ? ' <span class="tour-pill-new">SUGERIDO</span>' : '')
                +     (isDone ? ' <span class="tour-pill-done">✓ Visto</span>' : '')
                +   '</div>'
                +   '<div class="tour-card-sub">' + t.sub + '</div>'
                +   '<div class="tour-card-time">' + icon('<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>', 11) + ' ' + t.time + '</div>'
                + '</div>'
                + '<div class="tour-card-arrow">' + icon('<polyline points="9 18 15 12 9 6"/>', 16) + '</div>'
                + '</button>';
        });

        const modal = document.createElement('div');
        modal.id = 'tourPicker';
        modal.className = 'tour-modal';
        modal.innerHTML = ''
            + '<div class="tour-modal-backdrop"></div>'
            + '<div class="tour-modal-card">'
            +   '<button type="button" class="tour-modal-close" aria-label="Cerrar">&times;</button>'
            +   '<div class="tour-modal-head">'
            +     '<div class="tour-modal-head-icon">' + icon(ICONS.help, 26) + '</div>'
            +     '<div>'
            +       '<h2 class="tour-modal-title">Centro de ayuda</h2>'
            +       '<p class="tour-modal-sub">Elige un tour interactivo segun lo que quieras aprender.</p>'
            +     '</div>'
            +   '</div>'
            +   '<div class="tour-modal-body"><div class="tour-grid">' + cardsHtml + '</div></div>'
            +   '<div class="tour-modal-foot">'
            +     '<span>' + tourEntries.length + ' tours disponibles · ' + seen.length + ' completado(s)</span>'
            +     '<button type="button" data-tour-reset>Marcar todos como no vistos</button>'
            +   '</div>'
            + '</div>';
        document.body.appendChild(modal);

        modal.querySelector('.tour-modal-backdrop').addEventListener('click', closePicker);
        modal.querySelector('.tour-modal-close').addEventListener('click', closePicker);
        modal.querySelector('[data-tour-reset]').addEventListener('click', () => {
            if (confirm('Volver a marcar todos los tours como no vistos?')) {
                resetSeen();
                closePicker();
                openPicker();
            }
        });
        modal.querySelectorAll('[data-tour-run]').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = btn.dataset.tourRun;
                closePicker();
                setTimeout(() => runTour(id), 250);
            });
        });
        // Inyectar colores para los iconos
        const style = document.createElement('style');
        style.textContent = `
            .tour-icon-blue    { background: #EFF6FF; color: #2563EB; }
            .tour-icon-indigo  { background: #EEF2FF; color: #4F46E5; }
            .tour-icon-emerald { background: #F0FDF4; color: #15803D; }
            .tour-icon-amber   { background: #FFFBEB; color: #B45309; }
            .tour-icon-red     { background: #FEF2F2; color: #DC2626; }
            .tour-icon-slate   { background: #F1F5F9; color: #475569; }
        `;
        document.head.appendChild(style);
    }
    function openPicker() {
        buildPicker();
        const m = document.getElementById('tourPicker');
        if (m) m.classList.add('is-open');
        document.body.style.overflow = 'hidden';
    }
    function closePicker() {
        const m = document.getElementById('tourPicker');
        if (m) m.classList.remove('is-open');
        document.body.style.overflow = '';
    }

    // ====== API publica ======
    // El boton "Ayuda" del sidebar llama startOnboarding() -> abre el picker.
    window.startOnboarding = openPicker;
    window.runTour = runTour;
    window.openTourPicker = openPicker;

    // Cerrar con Escape
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closePicker(); });

    // ====== Auto-arranque primera vez ======
    if (AUTO) {
        document.addEventListener('DOMContentLoaded', () => {
            setTimeout(() => runTour('overview'), 500);
        });
    }
})();
</script>
