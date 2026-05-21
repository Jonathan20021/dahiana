            </div>
        </main>
    </div>
    <p class="text-center mt-3 text-[11px] text-slate-400">
        <?= htmlspecialchars(trim(getSetting('company_name', 'Portal Asesoria'))) ?> &copy; <?= date('Y') ?>
    </p>
</div>
<script>
    function openSidebar() {
        var sb = document.getElementById('appSidebar');
        var bd = document.getElementById('sidebarBackdrop');
        if (sb) sb.classList.add('open');
        if (bd) bd.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }
    function closeSidebar() {
        var sb = document.getElementById('appSidebar');
        var bd = document.getElementById('sidebarBackdrop');
        if (sb) sb.classList.remove('open');
        if (bd) bd.classList.add('hidden');
        document.body.style.overflow = '';
    }
    // Collapse/expand sidebar on desktop with persistence
    function toggleSidebar() {
        document.body.classList.toggle('sidebar-collapsed');
        try {
            localStorage.setItem('sb_collapsed', document.body.classList.contains('sidebar-collapsed') ? '1' : '0');
        } catch (e) {}
    }
    // Restore persisted state ASAP to avoid flash
    (function() {
        try {
            if (localStorage.getItem('sb_collapsed') === '1') {
                document.body.classList.add('sidebar-collapsed');
            }
        } catch (e) {}
    })();
    // Keyboard: [ collapses, ] expands, Escape closes mobile, g+letter navigation, ? help
    window.openKbCheatsheet = function() {
        var el = document.getElementById('kbCheatsheet');
        if (el) el.classList.remove('hidden');
    };
    window.closeKbCheatsheet = function() {
        var el = document.getElementById('kbCheatsheet');
        if (el) el.classList.add('hidden');
    };

    var __kbWaitingG = false;
    var __kbGTimer = null;
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') {
            closeSidebar();
            closeKbCheatsheet();
        }
        // Avoid hijacking when typing
        var t = e.target;
        if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) return;
        // Avoid when modifier keys are used (except shift for ?)
        if (e.ctrlKey || e.metaKey || e.altKey) return;

        if (e.key === '[' && window.innerWidth >= 1024) {
            e.preventDefault();
            document.body.classList.add('sidebar-collapsed');
            try { localStorage.setItem('sb_collapsed', '1'); } catch (e) {}
            return;
        }
        if (e.key === ']' && window.innerWidth >= 1024) {
            e.preventDefault();
            document.body.classList.remove('sidebar-collapsed');
            try { localStorage.setItem('sb_collapsed', '0'); } catch (e) {}
            return;
        }
        if (e.key === '?') {
            e.preventDefault();
            openKbCheatsheet();
            return;
        }
        // g+letter sequence
        if (__kbWaitingG) {
            var letter = e.key.toLowerCase();
            __kbWaitingG = false;
            clearTimeout(__kbGTimer);
            if (window.__kbMap && window.__kbMap[letter]) {
                e.preventDefault();
                window.location.href = window.__kbMap[letter];
            }
            return;
        }
        if (e.key === 'g' || e.key === 'G') {
            __kbWaitingG = true;
            __kbGTimer = setTimeout(function() { __kbWaitingG = false; }, 1200);
        }
    });
</script>

<?php if (isset($_SESSION['user_id'])) include __DIR__ . '/onboarding.php'; ?>

<?php if (isset($_SESSION['user_id']) && canAccessArea($_SESSION['role'] ?? '', 'admin')): ?>
<!-- Global Search Palette (admin) -->
<div id="searchPalette" class="palette-overlay hidden" onclick="if (event.target === this) closeSearchPalette()">
    <div class="palette-box">
        <div class="palette-input-wrap">
            <svg class="w-5 h-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input type="text" id="searchInput" class="palette-input" placeholder="Buscar clientes, facturas, NCF, RNC, obligaciones..." autocomplete="off">
            <span class="palette-kbd">ESC</span>
        </div>
        <div class="palette-results" id="searchResults">
            <div class="palette-empty">
                <p>Empieza a escribir para buscar en toda la app</p>
                <p class="mt-2 text-[11px]">Tip: prueba con un nombre, RNC, NCF o numero de factura</p>
            </div>
        </div>
        <div class="palette-footer">
            <span><kbd>↑</kbd> <kbd>↓</kbd> Navegar</span>
            <span><kbd>↵</kbd> Abrir</span>
            <span><kbd>ESC</kbd> Cerrar</span>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (isset($_SESSION['user_id'])): ?>
<!-- Notifications panel -->
<div id="notifPanel" class="notif-panel hidden">
    <div class="notif-head">
        <span class="notif-title">Notificaciones</span>
        <button type="button" onclick="toggleNotifPanel()" class="text-slate-400 hover:text-slate-700 text-lg leading-none">&times;</button>
    </div>
    <div class="notif-list" id="notifList">
        <div class="notif-empty">Cargando...</div>
    </div>
</div>

<!-- Keyboard shortcuts cheatsheet -->
<?php
$kbAdmin = canAccessArea($_SESSION['role'] ?? '', 'admin');
$kbShortcuts = $kbAdmin ? [
    'Navegacion' => [
        ['keys' => ['G','D'], 'label' => 'Vista 360 (Dashboard)'],
        ['keys' => ['G','C'], 'label' => 'Clientes'],
        ['keys' => ['G','A'], 'label' => 'Aprobaciones'],
        ['keys' => ['G','F'], 'label' => 'Revisar facturas IA'],
        ['keys' => ['G','T'], 'label' => 'Formularios DGII'],
        ['keys' => ['G','K'], 'label' => 'Calendario fiscal'],
        ['keys' => ['G','S'], 'label' => 'Configuracion'],
    ],
    'Acciones' => [
        ['keys' => ['Ctrl','K'], 'label' => 'Busqueda global'],
        ['keys' => ['/'], 'label' => 'Busqueda global (alterna)'],
        ['keys' => ['?'], 'label' => 'Mostrar esta ayuda'],
    ],
    'Sidebar' => [
        ['keys' => ['['], 'label' => 'Colapsar sidebar'],
        ['keys' => [']'], 'label' => 'Expandir sidebar'],
        ['keys' => ['Esc'], 'label' => 'Cerrar dialogos / sidebar mobile'],
    ],
] : [
    'Navegacion' => [
        ['keys' => ['G','D'], 'label' => 'Mi panel'],
        ['keys' => ['G','U'], 'label' => 'Subir facturas'],
        ['keys' => ['G','K'], 'label' => 'Mi calendario'],
        ['keys' => ['G','P'], 'label' => 'Mi perfil'],
    ],
    'General' => [
        ['keys' => ['?'], 'label' => 'Mostrar esta ayuda'],
        ['keys' => ['Esc'], 'label' => 'Cerrar dialogos'],
    ],
];
$kbMap = $kbAdmin ? [
    'd' => 'admin_dashboard.php',
    'c' => 'admin_clients.php',
    'a' => 'admin_approvals.php',
    'f' => 'admin_invoice_review.php',
    't' => 'admin_tax_filings.php',
    'k' => 'admin_tax_calendar.php',
    's' => 'admin_settings.php',
] : [
    'd' => 'client_dashboard.php',
    'u' => 'client_uploads.php',
    'k' => 'client_calendar.php',
    'p' => 'client_profile.php',
];
?>
<div id="kbCheatsheet" class="kb-overlay hidden" onclick="if (event.target === this) closeKbCheatsheet()">
    <div class="kb-box">
        <div class="kb-head">
            <h3 class="kb-title">
                <svg class="w-4 h-4 inline-block mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 5H8.625A2.625 2.625 0 006 7.625v9.75A2.625 2.625 0 008.625 20H10m4 0h1.375A2.625 2.625 0 0018 17.375v-9.75A2.625 2.625 0 0015.375 5H14M10 5v15m4-15v15"/></svg>
                Atajos de teclado
            </h3>
            <button type="button" onclick="closeKbCheatsheet()" class="text-slate-400 hover:text-slate-700 text-2xl leading-none">&times;</button>
        </div>
        <div class="kb-body">
            <?php foreach ($kbShortcuts as $group => $items): ?>
            <div class="kb-section">
                <p class="kb-section-label"><?= htmlspecialchars($group) ?></p>
                <?php foreach ($items as $sc): ?>
                <div class="kb-row">
                    <span class="kb-label"><?= htmlspecialchars($sc['label']) ?></span>
                    <span class="kb-keys">
                        <?php foreach ($sc['keys'] as $idx => $key): ?>
                        <?php if ($idx > 0): ?><span class="kb-sep">+</span><?php endif; ?>
                        <kbd><?= htmlspecialchars($key) ?></kbd>
                        <?php endforeach; ?>
                    </span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<script>
window.__kbMap = <?= json_encode($kbMap) ?>;
</script>
<style>
    .kb-overlay { position: fixed; inset: 0; z-index: 100; background: rgba(15, 23, 42, 0.5); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; padding: 20px; }
    .kb-overlay.hidden { display: none; }
    .kb-box { width: 100%; max-width: 540px; background: #fff; border-radius: 18px; box-shadow: 0 25px 80px rgba(15, 23, 42, 0.3); overflow: hidden; max-height: 90vh; display: flex; flex-direction: column; }
    .kb-head { padding: 18px 22px; border-bottom: 1px solid #F1F5F9; display: flex; align-items: center; justify-content: space-between; }
    .kb-title { font-size: 15px; font-weight: 800; color: #0F172A; display: flex; align-items: center; }
    .kb-body { padding: 16px 22px; overflow-y: auto; }
    .kb-section { margin-bottom: 18px; }
    .kb-section:last-child { margin-bottom: 0; }
    .kb-section-label { font-size: 10px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.1em; color: #94A3B8; margin-bottom: 8px; padding-bottom: 6px; border-bottom: 1px solid #F1F5F9; }
    .kb-row { display: flex; align-items: center; justify-content: space-between; padding: 6px 0; }
    .kb-label { font-size: 13px; color: #475569; }
    .kb-keys { display: inline-flex; align-items: center; gap: 4px; }
    .kb-keys kbd { font-family: ui-monospace, monospace; font-size: 11px; font-weight: 700; padding: 3px 8px; background: #F4F4F5; border: 1px solid #E5E7EB; border-bottom-width: 2px; border-radius: 5px; color: #0F172A; min-width: 22px; text-align: center; display: inline-block; }
    .kb-sep { color: #CBD5E1; font-size: 11px; }
</style>
<?php endif; ?>

<script>
// ============================
// Global Search Palette
// ============================
(function() {
    const overlay = document.getElementById('searchPalette');
    const input = document.getElementById('searchInput');
    const results = document.getElementById('searchResults');
    if (!overlay || !input) return;

    let activeIdx = 0;
    let allItems = [];
    let timer = null;

    window.openSearchPalette = function() {
        overlay.classList.remove('hidden');
        setTimeout(() => input.focus(), 50);
    };
    window.closeSearchPalette = function() {
        overlay.classList.add('hidden');
        input.value = '';
        results.innerHTML = '<div class="palette-empty"><p>Empieza a escribir para buscar en toda la app</p><p class="mt-2 text-[11px]">Tip: prueba con un nombre, RNC, NCF o numero de factura</p></div>';
    };

    function iconSvg(kind) {
        const map = {
            user:     '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>',
            invoice:  '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>',
            request:  '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2"/></svg>',
            calendar: '<svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>',
        };
        return map[kind] || map.user;
    }

    function render(groups) {
        if (!groups || groups.length === 0) {
            results.innerHTML = '<div class="palette-empty"><p>Sin resultados</p><p class="mt-2 text-[11px]">Intenta otra palabra clave</p></div>';
            allItems = [];
            return;
        }
        let html = '';
        allItems = [];
        let idx = 0;
        groups.forEach(g => {
            html += '<div class="palette-group-label">' + escapeHtml(g.label) + '</div>';
            g.items.forEach(it => {
                const isActive = idx === activeIdx;
                html += `<a class="palette-item ${isActive ? 'is-active' : ''}" href="${escapeHtml(it.url)}" data-idx="${idx}">
                    <div class="palette-item-icon">${iconSvg(it.icon)}</div>
                    <div class="palette-item-main">
                        <div class="palette-item-title">${escapeHtml(it.title)}</div>
                        <div class="palette-item-sub">${escapeHtml(it.sub || '')}</div>
                    </div>
                    ${it.tag ? '<span class="palette-item-tag">' + escapeHtml(it.tag) + '</span>' : ''}
                </a>`;
                allItems.push(it);
                idx++;
            });
        });
        results.innerHTML = html;
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    function search(q) {
        if (q.length < 2) {
            results.innerHTML = '<div class="palette-empty"><p>Escribe al menos 2 caracteres</p></div>';
            allItems = [];
            return;
        }
        fetch('global_search.php?q=' + encodeURIComponent(q))
            .then(r => r.json())
            .then(data => {
                activeIdx = 0;
                render(data.groups || []);
            })
            .catch(() => {
                results.innerHTML = '<div class="palette-empty">Error de busqueda</div>';
            });
    }

    input.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(() => search(input.value.trim()), 180);
    });

    input.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { closeSearchPalette(); return; }
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (activeIdx < allItems.length - 1) activeIdx++;
            updateActive();
        }
        if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (activeIdx > 0) activeIdx--;
            updateActive();
        }
        if (e.key === 'Enter') {
            e.preventDefault();
            const item = allItems[activeIdx];
            if (item) window.location.href = item.url;
        }
    });

    function updateActive() {
        const items = results.querySelectorAll('.palette-item');
        items.forEach((el, i) => {
            el.classList.toggle('is-active', i === activeIdx);
            if (i === activeIdx) el.scrollIntoView({ block: 'nearest' });
        });
    }

    // Keyboard shortcut: Ctrl/Cmd + K
    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
            e.preventDefault();
            if (overlay.classList.contains('hidden')) openSearchPalette();
            else closeSearchPalette();
        }
        if (e.key === '/' && overlay.classList.contains('hidden')) {
            const t = e.target;
            if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) return;
            e.preventDefault();
            openSearchPalette();
        }
    });
})();

// ============================
// Notifications panel
// ============================
(function() {
    const panel = document.getElementById('notifPanel');
    const list = document.getElementById('notifList');
    const badge = document.getElementById('notifBadge');
    if (!panel || !list) return;

    let loaded = false;

    window.toggleNotifPanel = function() {
        panel.classList.toggle('hidden');
        if (!panel.classList.contains('hidden') && !loaded) {
            loadNotifs();
        }
    };

    function loadNotifs() {
        fetch('notifications.php')
            .then(r => r.json())
            .then(data => {
                loaded = true;
                if (badge && data.unread > 0) {
                    badge.textContent = data.unread > 9 ? '9+' : data.unread;
                    badge.classList.remove('hidden');
                }
                if (!data.items || data.items.length === 0) {
                    list.innerHTML = '<div class="notif-empty">Todo al dia 🎉<br><span class="text-[11px]">No tienes notificaciones pendientes</span></div>';
                    return;
                }
                const toneMap = {
                    red:     { bg: '#FEF2F2', fg: '#DC2626' },
                    amber:   { bg: '#FEF3C7', fg: '#B45309' },
                    emerald: { bg: '#DCFCE7', fg: '#15803D' },
                    blue:    { bg: '#DBEAFE', fg: '#1D4ED8' },
                    slate:   { bg: '#F1F5F9', fg: '#475569' },
                };
                list.innerHTML = data.items.map(n => {
                    const t = toneMap[n.tone] || toneMap.slate;
                    return `<a class="notif-item" href="${escapeHtml(n.url)}">
                        <div class="notif-item-icon" style="background:${t.bg};color:${t.fg}">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="${n.icon || 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z'}"/></svg>
                        </div>
                        <div class="notif-item-main">
                            <div class="notif-item-title">${escapeHtml(n.title)}</div>
                            <div class="notif-item-sub">${escapeHtml(n.sub || '')}</div>
                        </div>
                    </a>`;
                }).join('');
            })
            .catch(() => {
                list.innerHTML = '<div class="notif-empty">Error al cargar</div>';
            });
    }

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    // Refresh badge on load (background fetch)
    fetch('notifications.php?count=1')
        .then(r => r.json())
        .then(data => {
            if (badge && data.unread > 0) {
                badge.textContent = data.unread > 9 ? '9+' : data.unread;
                badge.classList.remove('hidden');
            }
        })
        .catch(() => {});

    // Close panel when clicking outside
    document.addEventListener('click', (e) => {
        if (panel.classList.contains('hidden')) return;
        const trigger = document.getElementById('notifTrigger');
        if (panel.contains(e.target) || (trigger && trigger.contains(e.target))) return;
        panel.classList.add('hidden');
    });
})();
</script>

</body>
</html>
