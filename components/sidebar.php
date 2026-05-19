<?php
// components/sidebar.php
$isAdmin = canAccessArea($_SESSION['role'], 'admin');
$current_page = basename($_SERVER['PHP_SELF']);

$companyName     = trim(getSetting('company_name', 'Portal Asesoria'));
$companyInitials = trim(getSetting('company_initials', 'AF')) ?: 'AF';
$companySlogan   = trim(getSetting('company_slogan', 'Asesoria Financiera'));

$current_tax_pages = ['admin_tax_calendar.php', 'admin_tax_filings.php'];
$sidebarGroupsAdmin = [
    'Principal' => [
        ['url' => 'admin_dashboard.php', 'label' => 'Vista 360', 'icon' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>'],
        ['url' => 'admin_clients.php', 'label' => 'Clientes', 'icon' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6 5.87v-2a4 4 0 00-4-4H7a4 4 0 00-4 4v2m13-10a4 4 0 11-8 0 4 4 0 018 0zm6 2a3 3 0 11-6 0 3 3 0 016 0z"/></svg>'],
        ['url' => 'admin_requests.php', 'label' => 'Solicitudes', 'icon' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>'],
        ['url' => 'admin_finances.php', 'label' => 'Finanzas', 'icon' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>'],
    ],
    'Fiscal DGII' => [
        ['url' => 'admin_tax_calendar.php', 'label' => 'Calendario fiscal', 'icon' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 16l2 2 4-4"/></svg>'],
        ['url' => 'admin_tax_filings.php', 'label' => 'Formularios 6xx', 'icon' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>'],
        ['url' => 'admin_igualas.php', 'label' => 'Igualas', 'icon' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>'],
    ],
    'Gestion' => [
        ['url' => 'admin_services.php', 'label' => 'Servicios', 'icon' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 10h16M4 14h10M4 18h10"/></svg>'],
        ['url' => 'admin_users.php', 'label' => 'Usuarios', 'icon' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>'],
        ['url' => 'admin_roles.php', 'label' => 'Roles', 'icon' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4M12 2l9 4v6c0 5-3.5 9-9 10-5.5-1-9-5-9-10V6l9-4z"/></svg>'],
        ['url' => 'admin_settings.php', 'label' => 'Configuracion', 'icon' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317a1.724 1.724 0 013.35 0 1.724 1.724 0 002.591 1.06 1.724 1.724 0 012.37 2.37 1.724 1.724 0 001.06 2.59 1.724 1.724 0 010 3.352 1.724 1.724 0 00-1.06 2.59 1.724 1.724 0 01-2.37 2.37 1.724 1.724 0 00-2.59 1.06 1.724 1.724 0 01-3.35 0 1.724 1.724 0 00-2.591-1.06 1.724 1.724 0 01-2.37-2.37 1.724 1.724 0 00-1.06-2.59 1.724 1.724 0 010-3.352 1.724 1.724 0 001.06-2.59 1.724 1.724 0 012.37-2.37 1.724 1.724 0 002.591-1.06z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>'],
    ],
];

$sidebarGroupsClient = [
    'Principal' => [
        ['url' => 'client_dashboard.php', 'label' => 'Mi Panel', 'icon' => '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>'],
    ],
];

$groups = $isAdmin ? $sidebarGroupsAdmin : $sidebarGroupsClient;
$whatsappSupport = trim(getSetting('company_phone', ''));
?>
<aside id="appSidebar"
       class="sidebar fixed lg:static inset-y-0 left-0 z-50 w-[260px] lg:w-[260px] lg:shrink-0
              bg-white lg:bg-transparent lg:border-r lg:border-stone-200/70
              flex flex-col px-5 py-6 lg:py-7
              transform -translate-x-full lg:translate-x-0 transition-transform duration-200 ease-out
              shadow-2xl lg:shadow-none">
    <!-- Logo -->
    <div class="flex items-center gap-3 px-1 pb-5 border-b border-stone-200/70">
        <div class="w-10 h-10 rounded-2xl bg-slate-900 text-white flex items-center justify-center font-extrabold text-sm tracking-tight shrink-0">
            <?= htmlspecialchars(strtoupper(substr($companyInitials, 0, 2))) ?>
        </div>
        <div class="min-w-0">
            <p class="text-[15px] font-bold text-slate-900 truncate leading-tight"><?= htmlspecialchars($companyName) ?></p>
            <?php if ($companySlogan): ?>
            <p class="text-[11px] text-slate-400 truncate"><?= htmlspecialchars($companySlogan) ?></p>
            <?php endif; ?>
        </div>
        <button type="button" onclick="closeSidebar()" class="ml-auto lg:hidden p-1.5 text-slate-400 hover:text-slate-900">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </div>

    <!-- Navigation (grouped) -->
    <nav class="flex-1 overflow-y-auto scroll-area pt-4">
        <?php foreach ($groups as $groupName => $items): ?>
        <div class="mb-5">
            <p class="px-3 mb-2 text-[10px] font-bold text-slate-400 uppercase tracking-[0.12em]"><?= htmlspecialchars($groupName) ?></p>
            <ul class="space-y-1">
                <?php foreach ($items as $item):
                    $active = $current_page === $item['url'];
                ?>
                <li>
                    <a href="<?= $item['url'] ?>"
                       class="group flex items-center gap-3 rounded-2xl px-3 py-2.5 text-sm transition-all
                              <?= $active ? 'nav-item-active' : 'text-slate-500 hover:text-slate-900 hover:bg-stone-50' ?>">
                        <span class="nav-icon <?= $active ? 'text-blue-600' : 'text-slate-400 group-hover:text-slate-700' ?> transition-colors">
                            <?= $item['icon'] ?>
                        </span>
                        <span class="font-medium"><?= htmlspecialchars($item['label']) ?></span>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endforeach; ?>
    </nav>

    <!-- Support card -->
    <div class="mt-3 rounded-2xl bg-gradient-to-br from-slate-900 to-slate-800 p-4 text-white relative overflow-hidden">
        <div class="absolute -right-4 -top-4 w-20 h-20 rounded-full bg-blue-500/20 blur-2xl"></div>
        <div class="relative">
            <p class="text-[11px] font-semibold uppercase tracking-wider text-blue-200">Soporte</p>
            <p class="mt-1 text-sm font-bold">Necesitas ayuda?</p>
            <p class="mt-1 text-[11px] text-slate-300 leading-snug">Equipo disponible por WhatsApp.</p>
            <?php if ($whatsappSupport): ?>
            <a href="https://wa.me/<?= htmlspecialchars(preg_replace('/[^0-9]/', '', $whatsappSupport)) ?>" target="_blank"
               class="mt-3 inline-flex items-center gap-1.5 rounded-full bg-white text-slate-900 px-3 py-1.5 text-xs font-bold hover:bg-blue-50 transition-colors">
                <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51"/></svg>
                Contactar
            </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- User + logout -->
    <div class="mt-3 pt-3 border-t border-stone-200/70">
        <div class="flex items-center gap-3 px-1">
            <div class="w-9 h-9 rounded-full bg-stone-100 border border-stone-200 flex items-center justify-center text-sm font-bold text-slate-700 shrink-0">
                <?= htmlspecialchars(strtoupper(substr($_SESSION['name'] ?? 'U', 0, 1))) ?>
            </div>
            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-slate-900 truncate"><?= htmlspecialchars($_SESSION['name'] ?? '') ?></p>
                <p class="text-[11px] text-slate-400 truncate"><?= htmlspecialchars(getRoleName($_SESSION['role'] ?? '')) ?></p>
            </div>
            <a href="auth.php?action=logout" title="Salir" class="text-slate-400 hover:text-red-600 transition-colors p-1.5 rounded-lg hover:bg-red-50">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            </a>
        </div>
    </div>
</aside>
<div id="sidebarBackdrop" onclick="closeSidebar()" class="fixed inset-0 z-40 bg-slate-900/50 backdrop-blur-sm hidden lg:hidden"></div>
