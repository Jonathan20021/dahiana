<?php
// pwa_icon.php
// Genera un SVG dinamico para el icono PWA con el logo AMD.
// Variantes:
//   ?size=192       -> icono normal (marco azul + placa blanca con logo)
//   ?maskable=1     -> safe-zone reducida (Android maskable icons)
require_once 'config.php';
header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: public, max-age=604800, immutable'); // 7 dias

$size = (int)($_GET['size'] ?? 512);
$size = max(48, min(1024, $size));
$maskable = !empty($_GET['maskable']);

$b = brandColors();
$logo = brandLogoDataUri();

// Fondo azul de marca a sangre (en maskable el sistema recorta como quiera).
$corner = $maskable ? 0 : round($size * 0.22);

// Placa blanca centrada donde vive el logo.
$plateInset = $maskable ? round($size * 0.16) : round($size * 0.13);
$plateXY    = $plateInset;
$plateWH    = $size - 2 * $plateInset;
$plateR     = round($size * 0.12);

// Caja del logo dentro de la placa (con padding). El logo (2:1) se centra sin
// distorsion via preserveAspectRatio.
$logoPad = round($plateWH * 0.14);
$logoX   = $plateXY + $logoPad;
$logoY   = $plateXY + $logoPad;
$logoW   = $plateWH - 2 * $logoPad;
$logoH   = $plateWH - 2 * $logoPad;
?>
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="<?= $size ?>" height="<?= $size ?>" viewBox="0 0 <?= $size ?> <?= $size ?>">
    <defs>
        <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="<?= $b['blue_900'] ?>"/>
            <stop offset="100%" stop-color="<?= $b['blue_700'] ?>"/>
        </linearGradient>
        <clipPath id="frameClip">
            <rect x="0" y="0" width="<?= $size ?>" height="<?= $size ?>" rx="<?= $corner ?>"/>
        </clipPath>
    </defs>
    <!-- Todo recortado al marco redondeado para que nada sobresalga -->
    <g clip-path="url(#frameClip)">
        <!-- Marco azul de marca -->
        <rect x="0" y="0" width="<?= $size ?>" height="<?= $size ?>" fill="url(#bg)"/>
        <!-- Franja naranja inferior (acento) -->
        <rect x="0" y="<?= $size - round($size * 0.06) ?>" width="<?= $size ?>" height="<?= round($size * 0.06) ?>" fill="<?= $b['orange'] ?>"/>
        <!-- Placa blanca -->
        <rect x="<?= $plateXY ?>" y="<?= $plateXY ?>" width="<?= $plateWH ?>" height="<?= $plateWH ?>" rx="<?= $plateR ?>" fill="#ffffff"/>
        <?php if ($logo): ?>
        <!-- Logo AMD -->
        <image x="<?= $logoX ?>" y="<?= $logoY ?>" width="<?= $logoW ?>" height="<?= $logoH ?>"
               preserveAspectRatio="xMidYMid meet" xlink:href="<?= htmlspecialchars($logo, ENT_QUOTES) ?>"/>
        <?php else: ?>
        <text x="50%" y="52%" text-anchor="middle" dominant-baseline="central"
              font-family="'Plus Jakarta Sans', system-ui, sans-serif" font-size="<?= round($plateWH * 0.32) ?>"
              font-weight="800" fill="<?= $b['blue_900'] ?>">AMD</text>
        <?php endif; ?>
    </g>
</svg>
