// pwa.js - Registracion del service worker + install banner + update notify
// Tambien expone una pequena cola offline en IndexedDB para subidas que fallaron.
(function() {
    'use strict';

    if (!('serviceWorker' in navigator)) return;

    const REGISTER_PATH = 'sw.js';
    const DISMISS_KEY   = 'pwa_install_dismissed_at';
    const DISMISS_TTL   = 7 * 24 * 60 * 60 * 1000; // 7 dias

    // --- 1) Registrar SW al cargar
    window.addEventListener('load', function() {
        navigator.serviceWorker.register(REGISTER_PATH, { scope: './' })
            .then(function(reg) {
                // Detectar updates: si hay nuevo SW esperando, mostrar toast
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
                // Chequear updates cada 15 min
                setInterval(function() { reg.update().catch(function() {}); }, 15 * 60 * 1000);
            })
            .catch(function(err) {
                console.warn('[PWA] SW register fail:', err);
            });

        // Reload una vez cuando el SW nuevo toma control
        let refreshing = false;
        navigator.serviceWorker.addEventListener('controllerchange', function() {
            if (refreshing) return;
            refreshing = true;
            window.location.reload();
        });

        // Mensajes del SW (ej: sync retry)
        navigator.serviceWorker.addEventListener('message', function(e) {
            if (e.data && e.data.type === 'SYNC_RETRY') {
                window.dispatchEvent(new CustomEvent('pwa:sync-retry'));
            }
        });
    });

    // --- 2) Install prompt (Chrome/Edge/Android)
    let deferredPrompt = null;
    window.addEventListener('beforeinstallprompt', function(e) {
        e.preventDefault();
        deferredPrompt = e;
        if (shouldShowInstall()) showInstallBanner();
    });
    window.addEventListener('appinstalled', function() {
        hideInstallBanner();
        deferredPrompt = null;
        try { localStorage.setItem('pwa_installed', '1'); } catch (e) {}
    });

    function shouldShowInstall() {
        try {
            if (localStorage.getItem('pwa_installed') === '1') return false;
            const dismissed = parseInt(localStorage.getItem(DISMISS_KEY) || '0', 10);
            if (dismissed && (Date.now() - dismissed) < DISMISS_TTL) return false;
        } catch (e) {}
        // No mostrar si ya esta en modo standalone (instalado)
        if (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) return false;
        if (window.navigator.standalone === true) return false;
        return true;
    }

    function showInstallBanner() {
        if (document.getElementById('pwaInstallBanner')) return;
        const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
        const banner = document.createElement('div');
        banner.id = 'pwaInstallBanner';
        banner.className = 'pwa-banner';
        banner.innerHTML = ''
            + '<div class="pwa-banner-icon">'
            +   '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 17V3"/><polyline points="6 11 12 17 18 11"/><line x1="3" y1="21" x2="21" y2="21"/></svg>'
            + '</div>'
            + '<div class="pwa-banner-main">'
            +   '<div class="pwa-banner-title">Instala la app</div>'
            +   '<div class="pwa-banner-sub">' + (isIOS
                    ? 'Toca Compartir y luego "Anadir a inicio".'
                    : 'Accede mas rapido desde tu inicio sin abrir el navegador.')
            + '</div>'
            + '</div>'
            + '<div class="pwa-banner-actions">'
            +   (isIOS
                    ? ''
                    : '<button type="button" class="pwa-btn pwa-btn-primary" id="pwaInstallBtn">Instalar</button>')
            +   '<button type="button" class="pwa-btn pwa-btn-ghost" id="pwaDismissBtn">Mas tarde</button>'
            + '</div>';
        document.body.appendChild(banner);
        requestAnimationFrame(function() { banner.classList.add('is-visible'); });

        const installBtn = document.getElementById('pwaInstallBtn');
        const dismissBtn = document.getElementById('pwaDismissBtn');

        if (installBtn) {
            installBtn.addEventListener('click', function() {
                if (!deferredPrompt) return;
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
    }

    function hideInstallBanner() {
        const b = document.getElementById('pwaInstallBanner');
        if (!b) return;
        b.classList.remove('is-visible');
        setTimeout(function() { b.remove(); }, 220);
    }

    // --- 3) Update toast
    function showUpdateBanner(reg) {
        if (document.getElementById('pwaUpdateBanner')) return;
        const toast = document.createElement('div');
        toast.id = 'pwaUpdateBanner';
        toast.className = 'pwa-toast';
        toast.innerHTML = ''
            + '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>'
            + '<span class="pwa-toast-text">Nueva version disponible</span>'
            + '<button type="button" class="pwa-toast-btn" id="pwaUpdateBtn">Actualizar</button>'
            + '<button type="button" class="pwa-toast-close" id="pwaUpdateClose" aria-label="Cerrar">&times;</button>';
        document.body.appendChild(toast);
        requestAnimationFrame(function() { toast.classList.add('is-visible'); });

        document.getElementById('pwaUpdateBtn').addEventListener('click', function() {
            if (reg && reg.waiting) {
                reg.waiting.postMessage({ type: 'SKIP_WAITING' });
            } else {
                window.location.reload();
            }
        });
        document.getElementById('pwaUpdateClose').addEventListener('click', function() {
            toast.classList.remove('is-visible');
            setTimeout(function() { toast.remove(); }, 220);
        });
    }

    // --- 4) Connection status pill
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
                setTimeout(function() {
                    pill.classList.remove('is-visible', 'is-restored');
                }, 1800);
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

    // --- 5) Estilos inyectados
    const css = `
        .pwa-banner {
            position: fixed; left: 16px; right: 16px; bottom: 16px;
            z-index: 9999;
            max-width: 460px; margin-left: auto; margin-right: auto;
            background: #fff;
            border-radius: 18px;
            box-shadow: 0 18px 50px rgba(15,23,42,0.22);
            border: 1px solid #EEF0F2;
            padding: 14px 16px;
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
        .pwa-banner-sub { font-size: 11.5px; color: #64748B; margin-top: 2px; line-height: 1.4; }
        .pwa-banner-actions { display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0; }
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

        @media (max-width: 480px) {
            .pwa-banner { left: 10px; right: 10px; bottom: 10px; padding: 12px; }
            .pwa-banner-title { font-size: 13px; }
            .pwa-banner-sub { font-size: 11px; }
            .pwa-btn { padding: 7px 10px; font-size: 11.5px; }
        }
    `;
    const style = document.createElement('style');
    style.textContent = css;
    document.head.appendChild(style);

    // --- 6) Helper expuesto para activar background sync luego de un upload fallido
    window.pwaQueueRetry = function() {
        if ('serviceWorker' in navigator && 'SyncManager' in window) {
            navigator.serviceWorker.ready.then(function(reg) {
                reg.sync.register('retry-uploads').catch(function() {});
            });
        }
    };

    // --- 7) Bonus: si llegan via push, solicitar permiso al click manual del usuario (no auto-prompt)
    window.pwaRequestNotifs = function() {
        if (!('Notification' in window)) return Promise.resolve('unsupported');
        return Notification.requestPermission();
    };
})();
