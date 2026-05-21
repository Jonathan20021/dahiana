<?php
// pwa_icon.php
// Genera un SVG dinamico para el icono PWA con las iniciales del portal.
require_once 'config.php';
header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: public, max-age=86400');

$size = (int)($_GET['size'] ?? 512);
$size = max(48, min(1024, $size));
$initials = strtoupper(substr(trim(getSetting('company_initials', 'AF')) ?: 'AF', 0, 2));

$fontSize = round($size * 0.42);
$cornerRadius = round($size * 0.22); // mascarable safe
?>
<svg xmlns="http://www.w3.org/2000/svg" width="<?= $size ?>" height="<?= $size ?>" viewBox="0 0 <?= $size ?> <?= $size ?>">
    <defs>
        <linearGradient id="bg" x1="0%" y1="0%" x2="100%" y2="100%">
            <stop offset="0%" stop-color="#0F172A"/>
            <stop offset="100%" stop-color="#1E293B"/>
        </linearGradient>
        <radialGradient id="glow" cx="20%" cy="20%" r="80%">
            <stop offset="0%" stop-color="#3B82F6" stop-opacity="0.4"/>
            <stop offset="100%" stop-color="#3B82F6" stop-opacity="0"/>
        </radialGradient>
    </defs>
    <rect width="<?= $size ?>" height="<?= $size ?>" rx="<?= $cornerRadius ?>" fill="url(#bg)"/>
    <rect width="<?= $size ?>" height="<?= $size ?>" rx="<?= $cornerRadius ?>" fill="url(#glow)"/>
    <text x="50%" y="50%" text-anchor="middle" dominant-baseline="central" font-family="system-ui,-apple-system,'Plus Jakarta Sans',sans-serif" font-size="<?= $fontSize ?>" font-weight="800" fill="#FFFFFF" letter-spacing="<?= round($size * 0.005) ?>"><?= htmlspecialchars($initials) ?></text>
</svg>
