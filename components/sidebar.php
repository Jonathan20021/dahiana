<?php
$isAdmin = canAccessArea($_SESSION['role'], 'admin');
$current_page = basename($_SERVER['PHP_SELF']);

$menuItemsAdmin = [
    ['url' => 'admin_dashboard.php', 'label' => 'Vista 360', 'icon' => '<svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>'],
    ['url' => 'admin_requests.php', 'label' => 'Solicitudes', 'icon' => '<svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z" /></svg>'],
    ['url' => 'admin_finances.php', 'label' => 'Finanzas', 'icon' => '<svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z" /></svg>'],
    ['url' => 'admin_services.php', 'label' => 'Servicios', 'icon' => '<svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 010 3.75H5.625a1.875 1.875 0 010-3.75z" /></svg>'],
    ['url' => 'admin_users.php', 'label' => 'Usuarios', 'icon' => '<svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a8.94 8.94 0 01-6 2.28 8.94 8.94 0 01-6-2.28m12 0a9 9 0 10-12 0m12 0A9 9 0 0112 21m0-9a3.75 3.75 0 100-7.5A3.75 3.75 0 0012 12z" /></svg>'],
    ['url' => 'admin_roles.php', 'label' => 'Roles', 'icon' => '<svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m5.25 2.25a8.25 8.25 0 11-16.5 0 8.25 8.25 0 0116.5 0Z" /></svg>'],
    ['url' => 'admin_settings.php', 'label' => 'Configuracion', 'icon' => '<svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>'],
];

$menuItemsClient = [
    ['url' => 'client_dashboard.php', 'label' => 'Mi Panel 360', 'icon' => '<svg class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12l8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25" /></svg>'],
];

$items = $isAdmin ? $menuItemsAdmin : $menuItemsClient;
?>
<div class="hidden lg:fixed lg:inset-y-0 lg:z-30 lg:flex lg:w-72 lg:flex-col mt-16">
    <div class="flex grow flex-col gap-y-5 overflow-y-auto border-r border-slate-200/60 bg-white/50 backdrop-blur-xl px-4 pb-4 pt-8">
        <div class="px-2 mb-2 text-xs font-semibold text-slate-400 uppercase tracking-wider">Menu Principal</div>
        <nav class="flex flex-1 flex-col">
            <ul role="list" class="flex flex-1 flex-col gap-y-2">
                <?php foreach ($items as $item): ?>
                <li>
                    <a href="<?= $item['url'] ?>"
                       class="<?= $current_page === $item['url'] ? 'bg-blue-50 text-blue-700 shadow-sm ring-1 ring-inset ring-blue-700/10' : 'text-slate-600 hover:text-blue-600 hover:bg-slate-50' ?> group flex items-center gap-x-3 rounded-2xl p-3 text-sm font-semibold transition-all duration-200">
                        <div class="<?= $current_page === $item['url'] ? 'text-blue-600' : 'text-slate-400 group-hover:text-blue-500' ?> transition-colors">
                            <?= $item['icon'] ?>
                        </div>
                        <?= htmlspecialchars($item['label']) ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>

            <div class="mt-auto pb-4">
                <div class="rounded-2xl bg-gradient-to-br from-blue-50 to-indigo-50 p-4 ring-1 ring-inset ring-blue-100">
                    <p class="text-xs font-semibold text-blue-900">Ayuda y Soporte</p>
                    <p class="mt-1 text-xs text-blue-700">Si necesitas apoyo con el portal, puedes contactarnos por WhatsApp.</p>
                </div>
            </div>
        </nav>
    </div>
</div>
