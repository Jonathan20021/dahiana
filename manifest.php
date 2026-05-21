<?php
// manifest.php
// PWA manifest dinamico (toma nombre/colores de la configuracion del portal).
require_once 'config.php';
header('Content-Type: application/manifest+json; charset=utf-8');

$name = trim(getSetting('company_name', 'Portal Asesoria')) ?: 'Portal Asesoria';
$shortName = trim(getSetting('company_initials', '')) ?: substr($name, 0, 12);

echo json_encode([
    'name' => $name,
    'short_name' => $shortName,
    'description' => 'Portal de gestion fiscal con IA. Sube facturas, revisa formularios DGII y comunicate con tu asesor.',
    'start_url' => './client_dashboard.php',
    'scope' => './',
    'display' => 'standalone',
    'orientation' => 'portrait-primary',
    'background_color' => '#ECECEC',
    'theme_color' => '#0F172A',
    'lang' => 'es-DO',
    'icons' => [
        ['src' => 'pwa_icon.php?size=192', 'sizes' => '192x192', 'type' => 'image/svg+xml', 'purpose' => 'any maskable'],
        ['src' => 'pwa_icon.php?size=512', 'sizes' => '512x512', 'type' => 'image/svg+xml', 'purpose' => 'any maskable'],
    ],
    'shortcuts' => [
        [
            'name' => 'Subir factura',
            'short_name' => 'Subir',
            'description' => 'Toma una foto a tu factura',
            'url' => './client_uploads.php',
        ],
        [
            'name' => 'Mi calendario',
            'short_name' => 'Calendario',
            'url' => './client_calendar.php',
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
