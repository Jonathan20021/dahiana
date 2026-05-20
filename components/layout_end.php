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
    // Keyboard: [ collapses, ] expands, Escape closes mobile
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeSidebar();
        // Avoid hijacking when typing
        var t = e.target;
        if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) return;
        if (e.key === '[' && window.innerWidth >= 1024) {
            e.preventDefault();
            document.body.classList.add('sidebar-collapsed');
            try { localStorage.setItem('sb_collapsed', '1'); } catch (e) {}
        }
        if (e.key === ']' && window.innerWidth >= 1024) {
            e.preventDefault();
            document.body.classList.remove('sidebar-collapsed');
            try { localStorage.setItem('sb_collapsed', '0'); } catch (e) {}
        }
    });
</script>

<?php if (isset($_SESSION['user_id'])) include __DIR__ . '/onboarding.php'; ?>

</body>
</html>
