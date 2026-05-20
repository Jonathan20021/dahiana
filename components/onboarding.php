<?php
// components/onboarding.php
//
// Tour guiado interactivo. Usa Driver.js (cargado por CDN).
// - Se muestra automaticamente la primera vez que el usuario entra (until users.onboarding_completed_at is set).
// - Se puede relanzar manualmente desde el sidebar (boton "Ayuda").
// - Hay tours separados para admin y cliente, sensibles al pagina actual.
//
// Detecta el rol y la pagina y construye los steps en JS. Cualquier elemento referenciado
// debe tener data-tour="<id>" en el HTML.

if (!isset($_SESSION['user_id'])) return;

$onboardingUserId = (int)$_SESSION['user_id'];
$onboardingIsAdmin = canAccessArea($_SESSION['role'] ?? '', 'admin');
$onboardingPage    = basename($_SERVER['PHP_SELF'] ?? '');

// Detecta si debe auto-arrancar (?startOnboarding=1 fuerza, o si el usuario nunca lo completo)
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
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.css">
<script src="https://cdn.jsdelivr.net/npm/driver.js@1.3.1/dist/driver.js.iife.js"></script>
<style>
    .driver-popover { font-family: 'Plus Jakarta Sans', sans-serif; max-width: 380px; }
    .driver-popover-title { color: #0F172A; font-weight: 800; font-size: 16px; }
    .driver-popover-description { color: #475569; font-size: 13px; line-height: 1.55; }
    .driver-popover-footer button { font-weight: 600; }
    .driver-popover-next-btn { background: #0F172A !important; color: #fff !important; text-shadow: none !important; border: none !important; border-radius: 10px !important; padding: 8px 16px !important; }
    .driver-popover-prev-btn, .driver-popover-close-btn { background: #F4F4F5 !important; color: #475569 !important; text-shadow: none !important; border: none !important; border-radius: 10px !important; padding: 8px 14px !important; }
    .driver-popover-progress-text { color: #94a3b8; font-size: 11px; font-weight: 600; }
    .driver-active-element { box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.35) !important; border-radius: 14px !important; }
</style>
<script>
(function() {
    const ROLE = <?= json_encode($onboardingIsAdmin ? 'admin' : 'client') ?>;
    const PAGE = <?= json_encode($onboardingPage) ?>;
    const AUTO = <?= $onboardingAutoStart ? 'true' : 'false' ?>;
    const MARK_URL = 'onboarding_complete.php';

    function buildAdminSteps() {
        // Steps comunes en todas las paginas admin
        const steps = [
            {
                popover: {
                    title: 'Bienvenido al portal',
                    description: 'Te muestro en 1 minuto como funciona la app. Puedes saltarlo y verlo despues con el boton <b>Ayuda</b> en el menu.',
                    side: 'bottom', align: 'center',
                }
            },
            {
                element: '[data-tour="nav-clients"]',
                popover: {
                    title: 'Clientes',
                    description: 'Aqui creas y administras tu cartera. Cada cliente tiene su perfil fiscal (RNC, regimen, actividad) y un codigo unico para vincular Telegram.',
                    side: 'right',
                }
            },
            {
                element: '[data-tour="nav-tax-filings"]',
                popover: {
                    title: 'Formularios fiscales',
                    description: 'Donde se construyen automaticamente el 606, 607, IT-1 y 608. Cada vez que apruebas una factura de la IA, las lineas se generan solas.',
                    side: 'right',
                }
            },
            {
                element: '[data-tour="nav-invoice-review"]',
                popover: {
                    title: 'Revisar facturas IA',
                    description: '<b>Esta es la pantalla mas importante.</b> Los clientes suben facturas (portal o Telegram), la IA extrae los datos, y aqui las validas y aprobas con un click.',
                    side: 'right',
                }
            },
            {
                element: '[data-tour="nav-tax-calendar"]',
                popover: {
                    title: 'Calendario fiscal',
                    description: 'Todas las obligaciones DGII de tus clientes en un solo lugar. Pendientes, vencidas, completadas.',
                    side: 'right',
                }
            },
            {
                element: '[data-tour="nav-finances"]',
                popover: {
                    title: 'Finanzas',
                    description: 'Tus igualas, volantes de cobro y pagos pendientes.',
                    side: 'right',
                }
            },
            {
                element: '[data-tour="nav-settings"]',
                popover: {
                    title: 'Configuracion',
                    description: 'Aqui activas la IA con OpenAI, configuras el bot de Telegram, los emails con Resend y la identidad de tu empresa. <b>Antes de usar la IA, asegurate de tener la API key configurada aqui.</b>',
                    side: 'right',
                }
            },
            {
                element: '[data-tour="help-button"]',
                popover: {
                    title: 'Ayuda siempre disponible',
                    description: 'Cuando quieras volver a ver este tour, haz click aqui.',
                    side: 'right',
                }
            },
        ];

        // Pasos especificos por pagina (se suman a los comunes solo cuando aplica)
        if (PAGE === 'admin_invoice_review.php') {
            return [
                {
                    popover: {
                        title: 'Revisar facturas IA',
                        description: 'Aqui llegan todas las facturas que tus clientes suben. La IA ya extrajo los datos: solo validas y apruebas.',
                    }
                },
                {
                    element: '[data-tour="upload-zone"]',
                    popover: {
                        title: 'Subir en nombre del cliente',
                        description: 'Si recibes facturas por WhatsApp, email o en mano, las puedes subir tu mismo aqui asignandolas al cliente.',
                        side: 'bottom',
                    }
                },
                {
                    element: '[data-tour="filters"]',
                    popover: {
                        title: 'Filtros',
                        description: 'Filtra por periodo, estado (por aprobar / aprobadas), tipo (compra / venta) o cliente especifico.',
                        side: 'bottom',
                    }
                },
            ];
        }
        if (PAGE === 'admin_tax_filings.php') {
            return [
                {
                    popover: {
                        title: 'Formularios fiscales',
                        description: 'Aqui ves el 606, 607, 608 y el IT-1 de cada cliente para cada periodo. Todo se llena automaticamente desde las facturas aprobadas.',
                    }
                },
            ];
        }
        return steps; // dashboard u otras: muestra el tour completo de navegacion
    }

    function buildClientSteps() {
        const steps = [
            {
                popover: {
                    title: 'Bienvenido a tu portal fiscal',
                    description: 'Te muestro en 30 segundos como funciona. Es muy facil.',
                }
            },
            {
                element: '[data-tour="nav-uploads"]',
                popover: {
                    title: 'Subir facturas',
                    description: 'Toma una foto a tus facturas con el celular y subelas aqui. La IA lee el RNC, NCF, ITBIS y monto.',
                    side: 'right',
                }
            },
            {
                element: '[data-tour="upload-hero"]',
                popover: {
                    title: 'O directamente desde el panel',
                    description: 'Tienes un acceso rapido aqui. Tambien puedes mandar fotos a nuestro bot de Telegram.',
                    side: 'bottom',
                }
            },
            {
                element: '[data-tour="telegram-card"]',
                popover: {
                    title: 'Manda facturas por Telegram',
                    description: 'Mas comodo que entrar al portal: conecta tu cuenta con un codigo de 8 caracteres y manda fotos directo desde el chat.',
                    side: 'left',
                }
            },
            {
                element: '[data-tour="agenda"]',
                popover: {
                    title: 'Tu agenda fiscal',
                    description: 'Aqui ves los vencimientos DGII de tus formularios. Te avisamos antes de que se venzan.',
                    side: 'left',
                }
            },
            {
                element: '[data-tour="help-button"]',
                popover: {
                    title: 'Ayuda siempre disponible',
                    description: 'Si quieres ver este tour de nuevo, click aqui.',
                    side: 'right',
                }
            },
        ];
        return steps;
    }

    function buildSteps() {
        return ROLE === 'admin' ? buildAdminSteps() : buildClientSteps();
    }

    function start() {
        const allSteps = buildSteps();
        // Filtrar steps cuyo elemento no existe en el DOM (evita errores en paginas internas)
        const steps = allSteps.filter(s => !s.element || document.querySelector(s.element));
        if (steps.length === 0) return;
        const d = window.driver.js.driver({
            showProgress: true,
            allowClose: true,
            overlayColor: 'rgba(15, 23, 42, 0.6)',
            popoverClass: 'shadow-2xl',
            nextBtnText: 'Siguiente',
            prevBtnText: 'Atras',
            doneBtnText: 'Listo',
            progressText: '{{current}} / {{total}}',
            steps: steps,
            onDestroyed: () => {
                // marca completado cuando se cierra el tour
                fetch(MARK_URL, { method: 'POST' }).catch(() => {});
            },
        });
        d.drive();
    }

    // Expone window.startOnboarding() para el boton Ayuda
    window.startOnboarding = start;

    if (AUTO) {
        // Pequena pausa para que el DOM termine de pintar
        document.addEventListener('DOMContentLoaded', () => setTimeout(start, 400));
    }
})();
</script>
