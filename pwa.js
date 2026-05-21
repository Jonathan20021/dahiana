// pwa.js - Registracion del service worker + install banner + update notify
// Maneja correctamente:
//   - Chrome/Edge/Android: usa beforeinstallprompt
//   - Safari iOS: muestra hint manual con instrucciones (Compartir -> Anadir a inicio)
//   - Boton manual reusable desde cualquier sitio: window.showInstallPrompt()
(function() {
    'use strict';

    if (!('serviceWorker' in navigator)) {
        // Algunos navegadores (Safari en modo privado, IE) no soportan. Igual exponemos no-op.
        window.showInstallPrompt = function() { alert('Tu navegador no soporta instalacion de PWA.'); };
        return;
    }

    const REGISTER_PATH = 'sw.js';
    const DISMISS_KEY   = 'pwa_install_dismissed_at';
    const DISMISS_TTL   = 7 * 24 * 60 * 60 * 1000; // 7 dias

    // ===== Deteccion de plataforma
    const ua = navigator.userAgent || '';
    const isIOS = /iPad|iPhone|iPod/.test(ua) ||
                  (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1); // iPad en modo desktop
    const isAndroid = /Android/i.test(ua);
    const isSafari  = /^((?!chrome|android|crios|fxios|edg).)*safari/i.test(ua);
    const isStandalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) ||
                        window.navigator.standalone === true;

    // ===== 1) Registrar SW
    window.addEventListener('load', function() {
        navigator.serviceWorker.register(REGISTER_PATH, { scope: './' })
            .then(function(reg) {
                if (reg.waiting) showUpdateBanner(reg);
                reg.addEventListener('updatefound', function() {
                    const nw = reg.installing;
                    if (!nw) return;
                    nw.addEventListener('statechange', function() {
                        if (nw.state === 'installed' && navigator.serviceWorker.controller) {
                            showUpdateBanner(reg);
                        }
                    });
                });
                setInterval(function() { reg.update().catch(function() {}); }, 15 * 60 * 1000);

                // En iOS no llega beforeinstallprompt: si no esta instalado, sugerir
                if (isIOS && !isStandalone) {
                    setTimeout(function() {
                        if (shouldShowInstall()) showInstallBanner('ios');
                    }, 1500);
                }
            })
            .catch(function(err) { console.warn('[PWA] SW register fail:', err); });

        let refreshing = false;
        navigator.serviceWorker.addEventListener('controllerchange', function() {
            if (refreshing) return;
            refreshing = true;
            window.location.reload();
        });
        navigator.serviceWorker.addEventListener('message', function(e) {
            if (e.data && e.data.type === 'SYNC_RETRY') {
                window.dispatchEvent(new CustomEvent('pwa:sync-retry'));
            }
        });
    });

    // ===== 2) Install prompt (Chrome/Edge/Android)
    let deferredPrompt = null;
    window.addEventListener('beforeinstallprompt', function(e) {
        e.preventDefault();
        deferredPrompt = e;
        // Marca que SI tenemos prompt nativo disponible
        document.documentElement.classList.add('pwa-installable');
        if (shouldShowInstall()) showInstallBanner('native');
    });
    window.addEventListener('appinstalled', function() {
        hideInstallBanner();
        deferredPrompt = null;
        document.documentElement.classList.remove('pwa-installable');
        try { localStorage.setItem('pwa_installed', '1'); } catch (e) {}
    });

    function shouldShowInstall() {
        try {
            if (localStorage.getItem('pwa_installed') === '1') return false;
            const dismissed = parseInt(localStorage.getItem(DISMISS_KEY) || '0', 10);
            if (dismissed && (Date.now() - dismissed) < DISMISS_TTL) return false;
        } catch (e) {}
        if (isStandalone) return false;
        return true;
    }

    // ===== 3) API publica: forzar mostrar el prompt (boton manual)
    window.showInstallPrompt = function() {
        if (isStandalone) {
            toast('Ya tienes la app instalada.');
            return;
        }
        if (deferredPrompt) {
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function(choice) {
                if (choice.outcome === 'accepted') {
                    try { localStorage.setItem('pwa_installed', '1'); } catch (e) {}
                }
                deferredPrompt = null;
                hideInstallBanner();
            });
            return;
        }
        // iOS Safari no expone API: mostrar banner con instrucciones
        if (isIOS) {
            // Limpiar el flag de dismiss para forzar mostrar
            try { localStorage.removeItem(DISMISS_KEY); } catch (e) {}
            showInstallBanner('ios');
            return;
        }
        // Otros casos: explicar
        if (isAndroid) {
            showInstallBanner('android-manual');
        } else {
            showInstallBanner('desktop-manual');
        }
    };

    // ===== 4) Banner de instalacion (varias variantes)
    function showInstallBanner(variant) {
        hideInstallBanner();

        const banner = document.createElement('div');
        banner.id = 'pwaInstallBanner';
        banner.className = 'pwa-banner pwa-banner-' + variant;

        let title = 'Instala la app';
        let sub   = '';
        let actions = '';

        if (variant === 'ios') {
            sub = 'Toca <span class="pwa-ios-share-inline"><svg width="14" height="16" viewBox="0 0 14 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M7 1v9M3.5 4.5L7 1l3.5 3.5M2 7.5v6a1 1 0 001 1h8a1 1 0 001-1v-6"/></svg></span> Compartir y luego "Anadir a pantalla de inicio".';
            actions = '<button type="button" class="pwa-btn pwa-btn-ghost" id="pwaDismissBtn">Entendido</button>';
        } else if (variant === 'android-manual') {
            sub = 'Abre el menu de Chrome (⋮) y toca "Instalar app" o "Anadir a pantalla principal".';
            actions = '<button type="button" class="pwa-btn pwa-btn-ghost" id="pwaDismissBtn">Entendido</button>';
        } else if (variant === 'desktop-manual') {
            sub = 'En la barra de direcciones, busca el icono de instalar (⊕) o usa el menu del navegador.';
            actions = '<button type="button" class="pwa-btn pwa-btn-ghost" id="pwaDismissBtn">Entendido</button>';
        } else {
            // native (Android Chrome / desktop Chrome con prompt disponible)
            sub = 'Accede mas rapido desde tu inicio sin abrir el navegador.';
            actions = ''
                + '<button type="button" class="pwa-btn pwa-btn-primary" id="pwaInstallBtn">Instalar</button>'
                + '<button type="button" class="pwa-btn pwa-btn-ghost" id="pwaDismissBtn">Mas tarde</button>';
        }

        banner.innerHTML = ''
            + '<div class="pwa-banner-icon">'
            +   '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 17V3"/><polyline points="6 11 12 17 18 11"/><line x1="3" y1="21" x2="21" y2="21"/></svg>'
            + '</div>'
            + '<div class="pwa-banner-main">'
            +   '<div class="pwa-banner-title">' + title + '</div>'
            +   '<div class="pwa-banner-sub">' + sub + '</div>'
            + '</div>'
            + '<div class="pwa-banner-actions">' + actions + '</div>'
            + '<button type="button" class="pwa-banner-close" id="pwaBannerClose" aria-label="Cerrar">&times;</button>';

        document.body.appendChild(banner);
        requestAnimationFrame(function() { banner.classList.add('is-visible'); });

        const installBtn = document.getElementById('pwaInstallBtn');
        const dismissBtn = document.getElementById('pwaDismissBtn');
        const closeBtn   = document.getElementById('pwaBannerClose');

        if (installBtn) {
            installBtn.addEventListener('click', function() {
                if (!deferredPrompt) { hideInstallBanner(); return; }
                deferredPrompt.prompt();
                deferredPrompt.userChoice.then(function(choice) {
                    if (choice.outcome === 'accepted') {
                        try { localStorage.setItem('pwa_installed', '1'); } catch (e) {}
                    } else {
                        try { localStorage.setItem(DISMISS_KEY, Date.now().toString()); } catch (e) {}
                    }
                    deferredPrompt = null;
                    hideInstallBanner();
                });
            });
        }
        if (dismissBtn) {
            dismissBtn.addEventListener('click', function() {
                try { localStorage.setItem(DISMISS_KEY, Date.now().toString()); } catch (e) {}
                hideInstallBanner();
            });
        }
        if (closeBtn) {
            closeBtn.addEventListener('click', function() {
                try { localStorage.setItem(DISMISS_KEY, Date.now().toString()); } catch (e) {}
                hideInstallBanner();
            });
        }

        // En iOS dejar el banner mas tiempo y con dibujo guia
        if (variant === 'ios') addIosArrowHint();
    }

    function hideInstallBanner() {
        const b = document.getElementById('pwaInstallBanner');
        if (!b) return;
        b.classList.remove('is-visible');
        setTimeout(function() { b.remove(); }, 220);
        const arrow = document.getElementById('pwaIosArrow');
        if (arrow) arrow.remove();
    }

    function addIosArrowHint() {
        // Apunta hacia abajo (barra de Safari abajo) en iPhone modern (Safari 15+)
        // En iPad/desktop iPad apunta hacia arriba (barra arriba)
        if (document.getElementById('pwaIosArrow')) return;
        const isIPad = /iPad/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
        const arrow = document.createElement('div');
        arrow.id = 'pwaIosArrow';
        arrow.className = 'pwa-ios-arrow ' + (isIPad ? 'pwa-ios-arrow-top' : 'pwa-ios-arrow-bottom');
        arrow.innerHTML = '<span>Toca el icono Compartir</span><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">' +
            (isIPad
                ? '<path d="M12 19V5"/><polyline points="6 11 12 5 18 11"/>'
                : '<path d="M12 5v14"/><polyline points="6 13 12 19 18 13"/>')
            + '</svg>';
        document.body.appendChild(arrow);
        setTimeout(function() {
            const a = document.getElementById('pwaIosArrow');
            if (a) a.classList.add('is-visible');
        }, 80);
    }

    // ===== 5) Update toast
    function showUpdateBanner(reg) {
        if (document.getElementById('pwaUpdateBanner')) return;
        const t = document.createElement('div');
        t.id = 'pwaUpdateBanner';
        t.className = 'pwa-toast';
        t.innerHTML = ''
            + '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>'
            + '<span class="pwa-toast-text">Nueva version disponible</span>'
            + '<button type="button" class="pwa-toast-btn" id="pwaUpdateBtn">Actualizar</button>'
            + '<button type="button" class="pwa-toast-close" id="pwaUpdateClose" aria-label="Cerrar">&times;</button>';
        document.body.appendChild(t);
        requestAnimationFrame(function() { t.classList.add('is-visible'); });
        document.getElementById('pwaUpdateBtn').addEventListener('click', function() {
            if (reg && reg.waiting) reg.waiting.postMessage({ type: 'SKIP_WAITING' });
            else window.location.reload();
        });
        document.getElementById('pwaUpdateClose').addEventListener('click', function() {
            t.classList.remove('is-visible');
            setTimeout(function() { t.remove(); }, 220);
        });
    }

    // ===== 6) Connection status pill
    function ensureStatusPill() {
        let pill = document.getElementById('pwaNetPill');
        if (pill) return pill;
        pill = document.createElement('div');
        pill.id = 'pwaNetPill';
        pill.className = 'pwa-net-pill';
        pill.innerHTML = '<span class="pwa-net-dot"></span><span class="pwa-net-text">Sin conexion</span>';
        document.body.appendChild(pill);
        return pill;
    }
    function updateNet() {
        const pill = ensureStatusPill();
        if (navigator.onLine) {
            if (pill.classList.contains('is-visible')) {
                pill.classList.remove('is-offline');
                pill.classList.add('is-restored');
                pill.querySelector('.pwa-net-text').textContent = 'Conexion restablecida';
                setTimeout(function() { pill.classList.remove('is-visible', 'is-restored'); }, 1800);
            }
        } else {
            pill.classList.add('is-visible', 'is-offline');
            pill.classList.remove('is-restored');
            pill.querySelector('.pwa-net-text').textContent = 'Sin conexion';
        }
    }
    window.addEventListener('online', updateNet);
    window.addEventListener('offline', updateNet);
    document.addEventListener('DOMContentLoaded', function() { if (!navigator.onLine) updateNet(); });

    // ===== 7) Mini toast helper (para "ya esta instalada")
    function toast(msg) {
        const t = document.createElement('div');
        t.className = 'pwa-toast pwa-toast-info';
        t.textContent = msg;
        document.body.appendChild(t);
        requestAnimationFrame(function() { t.classList.add('is-visible'); });
        setTimeout(function() {
            t.classList.remove('is-visible');
            setTimeout(function() { t.remove(); }, 220);
        }, 2400);
    }

    // ===== 8) Estilos inyectados
    const css = `
        .pwa-banner {
            position: fixed; left: 16px; right: 16px; bottom: 16px;
            z-index: 9999;
            max-width: 460px; margin-left: auto; margin-right: auto;
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 18px 50px rgba(15,23,42,0.22);
            border: 1px solid #EEF0F2;
            padding: 14px 40px 14px 16px;
            display: flex; align-items: center; gap: 12px;
            transform: translateY(120%);
            opacity: 0;
            transition: transform .28s cubic-bezier(.2,.8,.2,1), opacity .2s ease;
        }
        .pwa-banner.is-visible { transform: translateY(0); opacity: 1; }
        .pwa-banner-icon {
            width: 42px; height: 42px; flex-shrink: 0;
            border-radius: 12px;
            background: linear-gradient(135deg, #0F172A, #1E293B);
            color: #fff;
            display: inline-flex; align-items: center; justify-content: center;
        }
        .pwa-banner-main { flex: 1; min-width: 0; }
        .pwa-banner-title { font-size: 13.5px; font-weight: 800; color: #0F172A; }
        .pwa-banner-sub { font-size: 11.5px; color: #64748B; margin-top: 3px; line-height: 1.45; }
        .pwa-banner-sub .pwa-ios-share-inline { display: inline-flex; vertical-align: middle; align-items: center; justify-content: center; width: 22px; height: 22px; border-radius: 6px; background: #F1F5F9; color: #2563EB; margin: 0 2px; }
        .pwa-banner-actions { display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0; }
        .pwa-banner-close {
            position: absolute; top: 8px; right: 10px;
            background: transparent; border: 0; color: #94A3B8;
            font-size: 22px; line-height: 1; cursor: pointer;
            padding: 2px 6px;
        }
        .pwa-banner-close:hover { color: #0F172A; }
        .pwa-btn {
            border: 0; cursor: pointer;
            font-size: 12px; font-weight: 700;
            padding: 8px 12px; border-radius: 10px;
            font-family: inherit;
        }
        .pwa-btn-primary { background: #0F172A; color: #fff; }
        .pwa-btn-primary:hover { background: #1E293B; }
        .pwa-btn-ghost { background: transparent; color: #64748B; }
        .pwa-btn-ghost:hover { background: #F4F4F5; color: #0F172A; }

        /* Flecha animada apuntando al boton Compartir de Safari */
        .pwa-ios-arrow {
            position: fixed; left: 50%; transform: translateX(-50%) translateY(20px);
            z-index: 9998;
            display: inline-flex; align-items: center; gap: 10px;
            background: #0F172A; color: #fff;
            padding: 10px 14px; border-radius: 999px;
            font-size: 12px; font-weight: 700;
            box-shadow: 0 14px 30px rgba(15,23,42,0.3);
            opacity: 0;
            transition: opacity .3s ease, transform .3s ease;
            pointer-events: none;
        }
        .pwa-ios-arrow.is-visible { opacity: 1; transform: translateX(-50%) translateY(0); }
        .pwa-ios-arrow.pwa-ios-arrow-bottom { bottom: 92px; animation: pwaArrowBounceDown 1.6s ease-in-out infinite; }
        .pwa-ios-arrow.pwa-ios-arrow-top    { top: 20px; animation: pwaArrowBounceUp 1.6s ease-in-out infinite; }
        @keyframes pwaArrowBounceDown { 0%,100% { transform: translateX(-50%) translateY(0); } 50% { transform: translateX(-50%) translateY(6px); } }
        @keyframes pwaArrowBounceUp   { 0%,100% { transform: translateX(-50%) translateY(0); } 50% { transform: translateX(-50%) translateY(-6px); } }

        .pwa-toast {
            position: fixed; left: 50%; bottom: 22px;
            transform: translateX(-50%) translateY(120%);
            z-index: 9998;
            background: #0F172A; color: #fff;
            border-radius: 999px;
            padding: 10px 14px 10px 16px;
            display: inline-flex; align-items: center; gap: 10px;
            box-shadow: 0 18px 50px rgba(15,23,42,0.25);
            opacity: 0;
            transition: transform .28s cubic-bezier(.2,.8,.2,1), opacity .2s ease;
            max-width: calc(100vw - 32px);
        }
        .pwa-toast.is-visible { transform: translateX(-50%) translateY(0); opacity: 1; }
        .pwa-toast-info { font-size: 13px; font-weight: 600; }
        .pwa-toast-text { font-size: 13px; font-weight: 600; }
        .pwa-toast-btn {
            background: #fff; color: #0F172A; border: 0;
            font-size: 12px; font-weight: 800;
            padding: 6px 12px; border-radius: 999px;
            cursor: pointer;
        }
        .pwa-toast-btn:hover { background: #F8FAFC; }
        .pwa-toast-close {
            background: transparent; color: #94A3B8; border: 0;
            font-size: 18px; line-height: 1; padding: 2px 6px; cursor: pointer;
        }
        .pwa-toast-close:hover { color: #fff; }

        .pwa-net-pill {
            position: fixed; left: 50%; top: 16px;
            transform: translateX(-50%) translateY(-160%);
            z-index: 9997;
            display: inline-flex; align-items: center; gap: 8px;
            padding: 7px 14px;
            background: #FEF2F2; color: #B91C1C;
            border: 1px solid #FCA5A5;
            border-radius: 999px;
            font-size: 12px; font-weight: 700;
            box-shadow: 0 10px 30px rgba(220, 38, 38, 0.18);
            transition: transform .28s cubic-bezier(.2,.8,.2,1);
            pointer-events: none;
        }
        .pwa-net-pill.is-visible { transform: translateX(-50%) translateY(0); }
        .pwa-net-pill.is-restored { background: #F0FDF4; color: #15803D; border-color: #86EFAC; }
        .pwa-net-dot { width: 8px; height: 8px; border-radius: 999px; background: currentColor; animation: pwaPulse 1.4s ease-in-out infinite; }
        @keyframes pwaPulse { 0%,100% { opacity: 1; } 50% { opacity: 0.35; } }

        /* Boton "Instalar app" en topbar / sidebar */
        .pwa-install-trigger {
            display: inline-flex; align-items: center; gap: 6px;
            background: #0F172A; color: #fff;
            border: 0; cursor: pointer;
            font-family: inherit;
            font-size: 12px; font-weight: 700;
            padding: 8px 12px; border-radius: 12px;
            transition: all .15s ease;
        }
        .pwa-install-trigger:hover { background: #1E293B; transform: translateY(-1px); }
        body.pwa-standalone .pwa-install-trigger { display: none !important; }

        @media (max-width: 480px) {
            .pwa-banner { left: 10px; right: 10px; bottom: 10px; padding: 12px 36px 12px 12px; }
            .pwa-banner-title { font-size: 13px; }
            .pwa-banner-sub { font-size: 11px; }
            .pwa-btn { padding: 7px 10px; font-size: 11.5px; }
        }
    `;
    const style = document.createElement('style');
    style.textContent = css;
    document.head.appendChild(style);

    // Marcar body si esta en standalone (para ocultar botones de instalar)
    if (isStandalone) {
        document.documentElement.classList.add('pwa-standalone');
        if (document.body) document.body.classList.add('pwa-standalone');
        document.addEventListener('DOMContentLoaded', function() {
            document.body.classList.add('pwa-standalone');
        });
    }

    // ===== 9) Helpers expuestos
    window.pwaQueueRetry = function() {
        if ('serviceWorker' in navigator && 'SyncManager' in window) {
            navigator.serviceWorker.ready.then(function(reg) {
                reg.sync.register('retry-uploads').catch(function() {});
            });
        }
    };
    window.pwaRequestNotifs = function() {
        if (!('Notification' in window)) return Promise.resolve('unsupported');
        return Notification.requestPermission();
    };

    // ===== 10) Si Android Chrome y no llega beforeinstallprompt en X seg, ofrecer hint manual
    if (isAndroid && !isStandalone) {
        setTimeout(function() {
            if (!deferredPrompt && shouldShowInstall()) {
                showInstallBanner('android-manual');
            }
        }, 6000);
    }
})();
