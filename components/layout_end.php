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
    // Close on Escape
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeSidebar();
    });
</script>
</body>
</html>
