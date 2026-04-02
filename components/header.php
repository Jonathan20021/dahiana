<?php
// components/header.php
?>
<header class="bg-white/80 backdrop-blur-md border-b border-slate-200/60 sticky top-0 z-40">
    <div class="px-4 sm:px-6 lg:px-8">
        <div class="flex h-16 items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 md:hidden bg-blue-600 rounded-lg flex items-center justify-center text-white">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7" />
                    </svg>
                </div>
                <h1 class="text-xl font-bold text-slate-900 tracking-tight">Portal Asesoría</h1>
            </div>
            <div class="flex items-center gap-4">
                <div class="hidden sm:flex flex-col text-right">
                    <span class="text-sm font-semibold text-slate-900 leading-tight"><?= htmlspecialchars($_SESSION['name']) ?></span>
                    <span class="text-xs font-medium text-slate-500 leading-tight"><?= htmlspecialchars(getRoleName($_SESSION['role'])) ?></span>
                </div>
                <div class="w-10 h-10 rounded-full bg-slate-100 border border-slate-200 flex items-center justify-center overflow-hidden">
                    <svg class="w-6 h-6 text-slate-400" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M24 20.993V24H0v-2.996A14.977 14.977 0 0112.004 15c4.904 0 9.26 2.354 11.996 5.993zM16.002 8.999a4 4 0 11-8 0 4 4 0 018 0z" />
                    </svg>
                </div>
                <a href="auth.php?action=logout" class="ml-2 rounded-xl bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm border border-slate-200 hover:bg-slate-50 transition-all hover:text-red-600 focus:ring-2 focus:ring-red-100">
                    Salir
                </a>
            </div>
        </div>
    </div>
</header>
