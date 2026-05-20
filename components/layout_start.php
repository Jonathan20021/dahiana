<?php
// components/layout_start.php
$page_title     = $page_title     ?? 'Portal Asesoria';
$page_subtitle  = $page_subtitle  ?? '';
$page_actions   = $page_actions   ?? '';
$head_extra     = $head_extra     ?? '';
$main_max       = $main_max       ?? 'max-w-7xl';
$show_topbar    = $show_topbar    ?? true;

$companyName     = trim(getSetting('company_name', 'Portal Asesoria'));
$companyInitials = trim(getSetting('company_initials', 'AF')) ?: 'AF';
$months = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
$today = (int)date('d') . ' ' . $months[(int)date('n') - 1] . ', ' . date('Y');

$currentPage = basename($_SERVER['PHP_SELF']);
$isLoggedIn  = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="es" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#ECECEC">
    <title><?= htmlspecialchars($page_title) ?> &middot; <?= htmlspecialchars($companyName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --app-bg: #ECECEC;
            --shell-radius: 28px;
        }
        html, body { background: var(--app-bg); }
        body { font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif; -webkit-font-smoothing: antialiased; color: #0F172A; }
        .app-shell {
            background: #fff;
            border-radius: var(--shell-radius);
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        }
        .nav-item-active {
            background: #F4F4F5;
            color: #0F172A;
            font-weight: 600;
        }
        .nav-item-active .nav-icon { color: #2563EB; }
        .stat-card { background: #fff; border: 1px solid #F1F1F3; border-radius: 22px; }
        .surface-card { background: #fff; border: 1px solid #EEF0F2; border-radius: 22px; }
        .pill-soft { background: #F4F4F5; color: #475569; font-weight: 500; }
        .btn-dark { background: #0F172A; color: #fff; border-radius: 14px; padding: 9px 16px; font-weight: 600; font-size: 13px; transition: all .15s ease; display: inline-flex; align-items: center; gap: 6px; }
        .btn-dark:hover { background: #1E293B; transform: translateY(-1px); }
        .btn-soft { background: #fff; color: #1E293B; border: 1px solid #E5E7EB; border-radius: 14px; padding: 9px 16px; font-weight: 600; font-size: 13px; transition: all .15s ease; display: inline-flex; align-items: center; gap: 6px; }
        .btn-soft:hover { background: #F8FAFC; }
        .btn-ghost { background: transparent; color: #475569; border-radius: 12px; padding: 8px 12px; font-weight: 500; transition: all .15s ease; }
        .btn-ghost:hover { background: #F4F4F5; color: #0F172A; }
        .field {
            width: 100%;
            border: 1px solid #E5E7EB;
            border-radius: 12px;
            padding: 10px 13px;
            font-size: 14px;
            background: #fff;
            color: #0F172A;
            transition: all .15s ease;
        }
        .field:focus { outline: none; border-color: #0F172A; box-shadow: 0 0 0 4px rgba(15,23,42,0.06); }
        .field-label { font-size: 11px; font-weight: 700; color: #475569; margin-bottom: 5px; display: block; letter-spacing: 0.04em; text-transform: uppercase; }
        .badge-dot { display: inline-flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 600; padding: 3px 10px; border-radius: 999px; background: #F4F4F5; color: #475569; white-space: nowrap; }
        .badge-dot::before { content: ''; width: 6px; height: 6px; border-radius: 999px; background: currentColor; }
        .badge-blue  { color: #2563EB; background: #EFF6FF; }
        .badge-amber { color: #B45309; background: #FFFBEB; }
        .badge-red   { color: #DC2626; background: #FEF2F2; }
        .badge-green { color: #15803D; background: #F0FDF4; }
        .badge-slate { color: #475569; background: #F4F4F5; }
        .badge-indigo { color: #4F46E5; background: #EEF2FF; }
        .scroll-area::-webkit-scrollbar { width: 6px; height: 6px; }
        .scroll-area::-webkit-scrollbar-thumb { background: #E5E7EB; border-radius: 999px; }
        .scroll-area::-webkit-scrollbar-track { background: transparent; }
        .modal-backdrop { background: rgba(15, 23, 42, 0.45); backdrop-filter: blur(4px); }
        .icon-btn { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: 12px; background: #F4F4F5; color: #475569; transition: all .15s ease; }
        .icon-btn:hover { background: #E5E7EB; color: #0F172A; }
        .table-row { transition: background .15s ease; }
        .table-row:hover { background: #FAFAFA; }
        @media (max-width: 1023px) {
            .app-shell { border-radius: 18px; }
            .sidebar.open { transform: translateX(0); }
        }
        @media (max-width: 640px) {
            .app-shell { border-radius: 14px; }
        }

        /* === Sidebar default (expanded) === */
        .sidebar { width: 260px; }
        @media (min-width: 1024px) { .sidebar { width: 260px; } }
        .sb-collapse-btn {
            width: 30px; height: 30px; border-radius: 10px; background: #F4F4F5; color: #475569;
            display: inline-flex; align-items: center; justify-content: center;
            transition: all .15s ease; margin-left: auto; flex-shrink: 0;
        }
        .sb-collapse-btn:hover { background: #E5E7EB; color: #0F172A; }

        /* === Sidebar collapsed (desktop only) === */
        @media (min-width: 1024px) {
            body.sidebar-collapsed .sidebar { width: 76px; padding-left: 12px; padding-right: 12px; }
            body.sidebar-collapsed .sb-brand { display: none; }
            body.sidebar-collapsed .sb-collapse-btn { margin-left: 0; }
            body.sidebar-collapsed .sb-collapse-btn svg { transform: rotate(180deg); }
            body.sidebar-collapsed .sb-group-header { display: none; }
            body.sidebar-collapsed .sb-label { display: none; }
            body.sidebar-collapsed .sb-badge { position: absolute; top: 4px; right: 4px; min-width: 16px; height: 16px; font-size: 9px; padding: 0 4px; }
            body.sidebar-collapsed .sb-extra { display: none; }
            body.sidebar-collapsed .sb-user-info { display: none; }
            body.sidebar-collapsed .sb-user { justify-content: center; padding: 0; gap: 6px; flex-direction: column; }
            body.sidebar-collapsed .sb-link {
                position: relative;
                justify-content: center;
                padding-left: 0; padding-right: 0;
            }
            body.sidebar-collapsed .sb-link[data-tooltip]:hover::after {
                content: attr(data-tooltip);
                position: absolute;
                left: calc(100% + 12px);
                top: 50%; transform: translateY(-50%);
                background: #0F172A;
                color: #fff;
                padding: 6px 12px;
                border-radius: 8px;
                font-size: 12px;
                font-weight: 600;
                white-space: nowrap;
                z-index: 50;
                pointer-events: none;
                box-shadow: 0 4px 12px rgba(15,23,42,.2);
            }
            body.sidebar-collapsed .sb-link[data-tooltip]:hover::before {
                content: '';
                position: absolute;
                left: calc(100% + 6px);
                top: 50%; transform: translateY(-50%);
                border-style: solid;
                border-width: 5px 6px 5px 0;
                border-color: transparent #0F172A transparent transparent;
                z-index: 50;
            }
        }

        /* Topbar pin button */
        .topbar-pin {
            display: inline-flex; align-items: center; justify-content: center;
            width: 36px; height: 36px; border-radius: 12px;
            background: #F4F4F5; color: #475569;
            transition: all .15s ease;
        }
        .topbar-pin:hover { background: #E5E7EB; color: #0F172A; }

        <?= $head_extra ?>
    </style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="min-h-full">
<div class="min-h-screen p-2 sm:p-4 lg:p-5">
    <div class="app-shell mx-auto flex w-full max-w-[1500px] flex-col lg:flex-row overflow-hidden lg:min-h-[calc(100vh-2.5rem)]">

        <?php if ($isLoggedIn) include __DIR__ . '/sidebar.php'; ?>

        <main class="flex-1 flex flex-col min-w-0">
            <?php if ($isLoggedIn && $show_topbar): ?>
            <!-- Top bar -->
            <div class="px-4 sm:px-6 lg:px-8 pt-4 sm:pt-6 lg:pt-8">
                <div class="flex flex-col lg:flex-row lg:items-start lg:justify-between gap-3">
                    <!-- Left: hamburger + title -->
                    <div class="flex items-start gap-3 min-w-0">
                        <button type="button" onclick="openSidebar()" class="lg:hidden mt-1 p-2 rounded-xl bg-stone-100 hover:bg-stone-200 text-slate-700 shrink-0">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
                        </button>
                        <div class="min-w-0 flex-1">
                            <h1 class="text-xl sm:text-2xl lg:text-[28px] font-extrabold tracking-tight text-slate-900 leading-tight break-words">
                                <?= htmlspecialchars($page_title) ?>
                            </h1>
                            <?php if ($page_subtitle): ?>
                            <p class="mt-0.5 text-xs sm:text-sm text-slate-500 leading-tight"><?= htmlspecialchars($page_subtitle) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Right: date + actions -->
                    <div class="flex items-center gap-2 flex-wrap lg:shrink-0 lg:justify-end">
                        <span class="hidden lg:inline-flex items-center gap-2 rounded-full bg-stone-100 px-3.5 py-1.5 text-xs font-semibold text-slate-600">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25"/></svg>
                            <?= $today ?>
                        </span>
                        <?php if ($page_actions): ?>
                        <div class="flex items-center gap-2 flex-wrap"><?= $page_actions ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div class="flex-1 px-4 sm:px-6 lg:px-8 py-4 sm:py-5 lg:py-6 <?= $main_max ?> w-full mx-auto">
