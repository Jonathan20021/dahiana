<?php
// pwa_icon.php
// Genera un SVG dinamico para el icono PWA con las iniciales del portal.
// Soporta variantes:
//   ?size=192       -> icono normal (transparente fuera del round-rect)
//   ?maskable=1     -> safe-zone reducida (Android maskable icons)
//   ?theme=light    -> variante clara (debug)
require_once 'config.php';
header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: public, max-age=604800, immutable'); // 7 dias

$size = (int)($_GET['size'] ?? 512);
$size = max(48, min(1024, $size));
$maskable = !empty($_GET['maskable']);
$theme = ($_GET['theme'] ?? '') === 'light' ? 'light' : 'dark';

$initials = strtoupper(substr(trim(getSetting('company_initials', 'AF')) ?: 'AF', 0, 2));

// En maskable, el contenido visible debe vivir en el 80% central.
$pad = $maskable ? round($size * 0.1) : 0;
$innerSize = $size - 2 * $pad;
$cornerRadius = $maskable ? 0 : round($size * 0.22);
$fontSize = round($innerSize * 0.42);
$letterSpacing = round($size * 0.005);

// En maskable usamos un cuadrado completo de fondo (sin radio) para que la mascara
// del sistema lo recorte como el usuario tenga configurado (cuadrado, redondo, etc.)
$bgX = $maskable ? 0 : 0;
$bgY = $maskable ? 0 : 0;
$bgW = $size;
$bgH = $size;

$gradStart = $theme === 'light' ? '#F8FAFC' : '#0F172A';
$gradEnd   = $theme === 'light' ? '#E2E8F0' : '#1E293B';
$glowColor = $theme === 'light' ? '#60A5FA' : '#3B82F6';
$textColor = $theme === 'light' ? '#0F172A' : '#FFFFFF';
?>
<svg xmlns="http://www.w3.org/2000/svg" width="<?= $size ?>" height="<?= $size ?>" viewBox="0 0 <?= $size ?> <?= $size ?>">
    <defs>
        <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="<?= $gradStart ?>"/>
            <stop offset="100%" stop-color="<?= $gradEnd ?>"/>
        </linearGradient>
        <radialGradient id="glow" cx="22%" cy="22%" r="80%">
            <stop offset="0%" stop-color="<?= $glowColor ?>" stop-opacity="0.45"/>
            <stop offset="100%" stop-color="<?= $glowColor ?>" stop-opacity="0"/>
        </radialGradient>
        <filter id="soft" x="-10%" y="-10%" width="120%" height="120%">
            <feGaussianBlur stdDeviation="<?= max(0.5, $size * 0.004) ?>"/>
        </filter>
    </defs>
    <!-- Fondo -->
    <rect x="<?= $bgX ?>" y="<?= $bgY ?>" width="<?= $bgW ?>" height="<?= $bgH ?>" rx="<?= $cornerRadius ?>" fill="url(#bg)"/>
    <rect x="<?= $bgX ?>" y="<?= $bgY ?>" width="<?= $bgW ?>" height="<?= $bgH ?>" rx="<?= $cornerRadius ?>" fill="url(#glow)"/>
    <!-- Brillo sutil arriba -->
    <ellipse cx="<?= $size / 2 ?>" cy="<?= $size * 0.18 ?>" rx="<?= $size * 0.35 ?>" ry="<?= $size * 0.08 ?>" fill="#ffffff" fill-opacity="0.06" filter="url(#soft)"/>
    <!-- Iniciales -->
    <text x="50%" y="50%" text-anchor="middle" dominant-baseline="central"
          font-family="'Plus Jakarta Sans', system-ui, -apple-system, sans-serif"
          font-size="<?= $fontSize ?>" font-weight="800"
          fill="<?= $textColor ?>" letter-spacing="<?= $letterSpacing ?>"><?= htmlspecialchars($initials, ENT_XML1, 'UTF-8') ?></text>
</svg>
