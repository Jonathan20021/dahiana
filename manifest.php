<?php
// manifest.php
// PWA manifest dinamico (toma nombre/colores de la configuracion del portal).
require_once 'config.php';
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$name = trim(getSetting('company_name', 'Portal Asesoria')) ?: 'Portal Asesoria';
$rawShort = trim(getSetting('company_initials', ''));
$shortName = $rawShort !== '' ? $rawShort : substr($name, 0, 12);
$description = trim(getSetting('company_description', '')) ?: 'Portal de gestion fiscal con IA. Sube facturas, revisa formularios DGII y comunicate con tu asesor.';

$isClientLogged = isset($_SESSION['user_id']) && (($_SESSION['role'] ?? 'client') !== 'admin');
$startUrl = $isClientLogged ? './client_dashboard.php' : './login.php';

$icon = function ($size, $purpose = 'any') {
    return [
        'src'     => 'pwa_icon.php?size=' . $size . ($purpose === 'maskable' ? '&maskable=1' : ''),
        'sizes'   => $size . 'x' . $size,
        'type'    => 'image/svg+xml',
        'purpose' => $purpose,
    ];
};

echo json_encode([
    'name'             => $name,
    'short_name'       => $shortName,
    'id'               => './',
    'description'      => $description,
    'start_url'        => $startUrl,
    'scope'            => './',
    'display'          => 'standalone',
    'display_override' => ['window-controls-overlay', 'standalone', 'minimal-ui'],
    'orientation'      => 'portrait-primary',
    'background_color' => '#ECECEC',
    'theme_color'      => '#0F172A',
    'lang'             => 'es-DO',
    'dir'              => 'ltr',
    'categories'       => ['business', 'finance', 'productivity'],
    'prefer_related_applications' => false,
    'icons' => [
        $icon(72,  'any'),
        $icon(96,  'any'),
        $icon(128, 'any'),
        $icon(144, 'any'),
        $icon(152, 'any'),
        $icon(192, 'any'),
        $icon(256, 'any'),
        $icon(384, 'any'),
        $icon(512, 'any'),
        $icon(192, 'maskable'),
        $icon(512, 'maskable'),
    ],
    'shortcuts' => [
        [
            'name' => 'Subir factura',
            'short_name' => 'Subir',
            'description' => 'Toma una foto a tu factura',
            'url' => './client_uploads.php',
            'icons' => [$icon(96, 'any')],
        ],
        [
            'name' => 'Mi calendario',
            'short_name' => 'Calendario',
            'description' => 'Vencimientos y obligaciones fiscales',
            'url' => './client_calendar.php',
            'icons' => [$icon(96, 'any')],
        ],
        [
            'name' => 'Mi panel',
            'short_name' => 'Panel',
            'description' => 'Vista general de tu cuenta',
            'url' => './client_dashboard.php',
            'icons' => [$icon(96, 'any')],
        ],
    ],
    'share_target' => [
        'action' => './client_uploads.php?via=share',
        'method' => 'POST',
        'enctype' => 'multipart/form-data',
        'params' => [
            'title' => 'title',
            'text'  => 'text',
            'files' => [
                [
                    'name'    => 'files[]',
                    'accept'  => ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'application/pdf'],
                ],
            ],
        ],
    ],
    'protocol_handlers' => [],
    'edge_side_panel' => ['preferred_width' => 480],
    'launch_handler' => ['client_mode' => 'navigate-existing'],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
