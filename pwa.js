// pwa.js - Registracion del SW + install guide universal
// Soporta:
//   - Safari iOS: modal con 2 pasos visuales
//   - Chrome/Firefox/Edge en iOS: usan WebKit pero NO permiten instalar.
//        Mostrar instruccion para abrir en Safari + copiar enlace.
//   - Android Chrome: beforeinstallprompt nativo + fallback con menu hint
//   - Android Samsung Internet / Firefox: hint manual con menu
//   - Desktop Chrome/Edge: beforeinstallprompt nativo
//   - Desktop Safari/Firefox: hint con copiar enlace
(function() {
    'use strict';

    const REGISTER_PATH = 'sw.js';
    const DISMISS_KEY   = 'pwa_install_dismissed_at';
    const DISMISS_TTL   = 7 * 24 * 60 * 60 * 1000;

    // ===== Deteccion de plataforma
    const ua = navigator.userAgent || '';
    const isIOS = /iPad|iPhone|iPod/.test(ua) ||
                  (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
    const isIPad = /iPad/.test(ua) || (/Macintosh/.test(ua) && navigator.maxTouchPoints > 1);
    const isAndroid = /Android/i.test(ua);
    // Chrome iOS = CriOS, Firefox iOS = FxiOS, Edge iOS = EdgiOS
    const isIOSChrome  = /CriOS/i.test(ua);
    const isIOSFirefox = /FxiOS/i.test(ua);
    const isIOSEdge    = /EdgiOS/i.test(ua);
    const isIOSNonSafari = isIOS && (isIOSChrome || isIOSFirefox || isIOSEdge);
    const isSafariIOS = isIOS && !isIOSNonSafari;
    const isSamsungBrowser = /SamsungBrowser/i.test(ua);
    const isAndroidFirefox = isAndroid && /Firefox/i.test(ua);
    const isAndroidChrome  = isAndroid && /Chrome/i.test(ua) && !isSamsungBrowser && !isAndroidFirefox;
    const isStandalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) ||
                        window.navigator.standalone === true;

    if (!('serviceWorker' in navigator)) {
        window.showInstallPrompt = openManualModal.bind(null, 'unsupported');
        injectStyles();
        return;
    }

    // ===== Registrar SW
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

                // iOS Safari: nunca llega beforeinstallprompt - sugerir despues de 1.5s
                if (isSafariIOS && !isStandalone) {
                    setTimeout(function() { if (shouldShowInstall()) openManualModal('safari-ios'); }, 1500);
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

    // ===== beforeinstallprompt
    let deferredPrompt = null;
    window.addEventListener('beforeinstallprompt', function(e) {
        e.preventDefault();
        deferredPrompt = e;
        document.documentElement.classList.add('pwa-installable');
    });
    window.addEventListener('appinstalled', function() {
        deferredPrompt = null;
        document.documentElement.classList.remove('pwa-installable');
        try { localStorage.setItem('pwa_installed', '1'); } catch (e) {}
        closeManualModal();
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

    // ===== API publica
    window.showInstallPrompt = function() {
        if (isStandalone) { toast('Ya tienes la app instalada.'); return; }
        if (deferredPrompt) {
            deferredPrompt.prompt();
            deferredPrompt.userChoice.then(function(choice) {
                if (choice.outcome === 'accepted') {
                    try { localStorage.setItem('pwa_installed', '1'); } catch (e) {}
                }
                deferredPrompt = null;
                closeManualModal();
            });
            return;
        }
        // Sin prompt nativo: elegir modal segun plataforma
        try { localStorage.removeItem(DISMISS_KEY); } catch (e) {}
        if (isIOSNonSafari)         openManualModal('ios-non-safari');
        else if (isSafariIOS)       openManualModal('safari-ios');
        else if (isAndroidChrome)   openManualModal('android-chrome');
        else if (isSamsungBrowser)  openManualModal('samsung');
        else if (isAndroidFirefox)  openManualModal('android-firefox');
        else if (isAndroid)         openManualModal('android-chrome');
        else                        openManualModal('desktop');
    };

    // ===== Modal universal
    function openManualModal(variant) {
        closeManualModal();
        const root = document.createElement('div');
        root.id = 'pwaInstallModal';
        root.className = 'pwa-modal';
        root.setAttribute('role', 'dialog');
        root.setAttribute('aria-modal', 'true');

        const content = buildVariant(variant);
        root.innerHTML = ''
            + '<div class="pwa-modal-backdrop"></div>'
            + '<div class="pwa-modal-card">'
            +   '<button type="button" class="pwa-modal-close" aria-label="Cerrar">&times;</button>'
            +   content
            + '</div>';
        document.body.appendChild(root);
        requestAnimationFrame(function() { root.classList.add('is-visible'); });

        root.querySelector('.pwa-modal-close').addEventListener('click', dismiss);
        root.querySelector('.pwa-modal-backdrop').addEventListener('click', dismiss);
        const okBtn = root.querySelector('[data-pwa-ok]');
        if (okBtn) okBtn.addEventListener('click', dismiss);
        const copyBtn = root.querySelector('[data-pwa-copy]');
        if (copyBtn) copyBtn.addEventListener('click', copyLinkHandler);
        const safariBtn = root.querySelector('[data-pwa-open-safari]');
        if (safariBtn) safariBtn.addEventListener('click', openInSafariHandler);
    }
    function closeManualModal() {
        const m = document.getElementById('pwaInstallModal');
        if (!m) return;
        m.classList.remove('is-visible');
        setTimeout(function() { m.remove(); }, 220);
    }
    function dismiss() {
        try { localStorage.setItem(DISMISS_KEY, Date.now().toString()); } catch (e) {}
        closeManualModal();
    }
    function copyLinkHandler() {
        const url = window.location.href;
        const onOk = function() { toast('Enlace copiado. Pegalo en Safari.'); };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(onOk).catch(fallbackCopy);
        } else { fallbackCopy(); }
        function fallbackCopy() {
            const ta = document.createElement('textarea');
            ta.value = url; ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta); ta.select();
            try { document.execCommand('copy'); onOk(); } catch (e) {}
            ta.remove();
        }
    }
    function openInSafariHandler() {
        // En iOS Chrome existia "googlechromes://" inverso pero no hay forma
        // confiable y publica de saltar a Safari. Lo mejor: copiar y avisar.
        copyLinkHandler();
    }

    // ===== Contenido de cada variante
    function buildVariant(variant) {
        const shareIcon = svgInline('<path d="M12 2v13"/><polyline points="7 7 12 2 17 7"/><path d="M5 12v7a2 2 0 002 2h10a2 2 0 002-2v-7"/>', 26);
        const addIcon   = '<span class="pwa-step-mini-icon">⊕</span>';
        const menuIcon  = svgInline('<circle cx="12" cy="5" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="12" cy="19" r="1.6"/>', 22);
        const phoneIcon = svgInline('<rect x="6" y="2" width="12" height="20" rx="2.5"/><line x1="11" y1="18" x2="13" y2="18"/>', 22);

        if (variant === 'safari-ios') {
            return ''
                + headerFor('Instala la app en tu iPhone', 'Solo toma 5 segundos.')
                + '<div class="pwa-steps">'
                +   step(1, 'Toca el icono <strong>Compartir</strong>', shareIcon,
                       (isIPad ? 'Esta arriba a la derecha en Safari.' : 'Esta abajo en el centro de Safari.'))
                +   step(2, 'Desliza hacia abajo y toca', addIcon + '<strong> Anadir a pantalla de inicio</strong>',
                       'Si no lo ves, sigue bajando — esta debajo de "Copiar".')
                +   step(3, 'Toca <strong>Anadir</strong> arriba a la derecha', '<span class="pwa-step-mini-text">Anadir</span>',
                       'Listo. Veras el icono en tu inicio como una app.')
                + '</div>'
                + '<div class="pwa-modal-actions"><button type="button" class="pwa-btn pwa-btn-primary" data-pwa-ok>Entendido</button></div>';
        }
        if (variant === 'ios-non-safari') {
            const browser = isIOSChrome ? 'Chrome' : (isIOSFirefox ? 'Firefox' : (isIOSEdge ? 'Edge' : 'este navegador'));
            return ''
                + headerFor('Necesitas Safari para instalar', browser + ' en iPhone no permite instalar apps web (limite de Apple).')
                + '<div class="pwa-info-block">'
                +   '<div class="pwa-info-icon">' + phoneIcon + '</div>'
                +   '<div>'
                +     '<p class="pwa-info-title">Pasos faciles:</p>'
                +     '<ol class="pwa-info-list">'
                +       '<li>Copia el enlace con el boton de abajo.</li>'
                +       '<li>Abre <strong>Safari</strong> en tu iPhone.</li>'
                +       '<li>Pega el enlace y abrelo.</li>'
                +       '<li>Toca Compartir → "Anadir a pantalla de inicio".</li>'
                +     '</ol>'
                +   '</div>'
                + '</div>'
                + '<div class="pwa-modal-actions">'
                +   '<button type="button" class="pwa-btn pwa-btn-primary" data-pwa-copy>Copiar enlace</button>'
                +   '<button type="button" class="pwa-btn pwa-btn-ghost" data-pwa-ok>Cerrar</button>'
                + '</div>';
        }
        if (variant === 'android-chrome') {
            return ''
                + headerFor('Instala la app en tu Android', 'Es gratis y ocupa muy poco espacio.')
                + '<div class="pwa-steps">'
                +   step(1, 'Toca el menu <strong>(⋮)</strong>', menuIcon, 'Arriba a la derecha en Chrome.')
                +   step(2, 'Toca <strong>"Instalar app"</strong> o <strong>"Anadir a pantalla principal"</strong>', addIcon, 'Segun version de Chrome.')
                +   step(3, 'Confirma con <strong>Instalar</strong>', '<span class="pwa-step-mini-text">Instalar</span>', 'La app aparecera en tu lanzador como cualquier otra.')
                + '</div>'
                + '<div class="pwa-modal-actions"><button type="button" class="pwa-btn pwa-btn-primary" data-pwa-ok>Entendido</button></div>';
        }
        if (variant === 'samsung') {
            return ''
                + headerFor('Instala la app (Samsung Internet)', '')
                + '<div class="pwa-steps">'
                +   step(1, 'Toca el menu <strong>(≡)</strong> abajo', menuIcon, '')
                +   step(2, 'Toca <strong>"Anadir pagina a"</strong> → <strong>"Pantalla de inicio"</strong>', addIcon, '')
                +   step(3, 'Confirma con <strong>Anadir</strong>', '<span class="pwa-step-mini-text">Anadir</span>', '')
                + '</div>'
                + '<div class="pwa-modal-actions"><button type="button" class="pwa-btn pwa-btn-primary" data-pwa-ok>Entendido</button></div>';
        }
        if (variant === 'android-firefox') {
            return ''
                + headerFor('Instala la app (Firefox Android)', '')
                + '<div class="pwa-steps">'
                +   step(1, 'Toca el menu <strong>(⋮)</strong>', menuIcon, '')
                +   step(2, 'Toca <strong>"Instalar"</strong> o <strong>"Anadir a inicio"</strong>', addIcon, '')
                +   step(3, 'Confirma con <strong>Anadir</strong>', '<span class="pwa-step-mini-text">Anadir</span>', '')
                + '</div>'
                + '<div class="pwa-modal-actions"><button type="button" class="pwa-btn pwa-btn-primary" data-pwa-ok>Entendido</button></div>';
        }
        if (variant === 'unsupported') {
            return ''
                + headerFor('Tu navegador no soporta instalacion', 'Abre la pagina en Chrome, Edge o Safari (iOS).')
                + '<div class="pwa-modal-actions"><button type="button" class="pwa-btn pwa-btn-primary" data-pwa-ok>Entendido</button></div>';
        }
        // desktop
        return ''
            + headerFor('Instala la app', 'Para usarla como un programa de escritorio.')
            + '<div class="pwa-steps">'
            +   step(1, 'En la barra de direcciones busca el icono ⊕', addIcon, 'Suele estar al final de la URL.')
            +   step(2, 'O abre el menu y elige <strong>"Instalar..."</strong>', menuIcon, '')
            +   step(3, 'Confirma con <strong>Instalar</strong>', '<span class="pwa-step-mini-text">Instalar</span>', '')
            + '</div>'
            + '<div class="pwa-modal-actions"><button type="button" class="pwa-btn pwa-btn-primary" data-pwa-ok>Entendido</button></div>';
    }

    function headerFor(title, subtitle) {
        return ''
            + '<div class="pwa-modal-icon">'
            +   svgInline('<path d="M12 17V3"/><polyline points="6 11 12 17 18 11"/><line x1="3" y1="21" x2="21" y2="21"/>', 28)
            + '</div>'
            + '<h3 class="pwa-modal-title">' + title + '</h3>'
            + (subtitle ? '<p class="pwa-modal-sub">' + subtitle + '</p>' : '');
    }
    function step(num, html, icon, hint) {
        return ''
            + '<div class="pwa-step">'
            +   '<span class="pwa-step-num">' + num + '</span>'
            +   '<div class="pwa-step-main">'
            +     '<div class="pwa-step-text">' + html + (icon ? '<span class="pwa-step-icon">' + icon + '</span>' : '') + '</div>'
            +     (hint ? '<div class="pwa-step-hint">' + hint + '</div>' : '')
            +   '</div>'
            + '</div>';
    }
    function svgInline(paths, size) {
        size = size || 22;
        return '<svg width="' + size + '" height="' + size + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round">' + paths + '</svg>';
    }

    // ===== Update toast
    function showUpdateBanner(reg) {
        if (document.getElementById('pwaUpdateBanner')) return;
        const t = document.createElement('div');
        t.id = 'pwaUpdateBanner';
        t.className = 'pwa-toast';
        t.innerHTML = ''
            + svgInline('<polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/>', 18)
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

    // ===== Network pill
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

    function toast(msg) {
        const t = document.createElement('div');
        t.className = 'pwa-toast pwa-toast-info';
        t.textContent = msg;
        document.body.appendChild(t);
        requestAnimationFrame(function() { t.classList.add('is-visible'); });
        setTimeout(function() {
            t.classList.remove('is-visible');
            setTimeout(function() { t.remove(); }, 220);
        }, 2600);
    }

    function injectStyles() {
        const css = `
            .pwa-modal { position: fixed; inset: 0; z-index: 9999; pointer-events: none; }
            .pwa-modal-backdrop {
                position: absolute; inset: 0;
                background: rgba(15,23,42,0.55);
                backdrop-filter: blur(6px);
                opacity: 0;
                transition: opacity .22s ease;
                pointer-events: auto;
            }
            .pwa-modal.is-visible { pointer-events: auto; }
            .pwa-modal.is-visible .pwa-modal-backdrop { opacity: 1; }
            .pwa-modal-card {
                position: relative;
                max-width: 440px; width: calc(100% - 24px);
                margin: 0 auto;
                top: 50%; transform: translateY(-50%) scale(.96);
                background: #fff;
                border-radius: 24px;
                box-shadow: 0 25px 80px rgba(15, 23, 42, 0.3);
                padding: 28px 24px 24px;
                opacity: 0;
                transition: opacity .22s ease, transform .25s cubic-bezier(.2,.8,.2,1);
                pointer-events: auto;
                max-height: 90vh;
                overflow-y: auto;
                font-family: 'Plus Jakarta Sans', system-ui, -apple-system, sans-serif;
            }
            .pwa-modal.is-visible .pwa-modal-card { opacity: 1; transform: translateY(-50%) scale(1); }
            .pwa-modal-close {
                position: absolute; top: 12px; right: 14px;
                background: transparent; border: 0;
                color: #94A3B8; font-size: 26px; line-height: 1;
                cursor: pointer; padding: 4px 8px;
            }
            .pwa-modal-close:hover { color: #0F172A; }
            .pwa-modal-icon {
                width: 56px; height: 56px;
                background: linear-gradient(135deg, #0F172A, #1E293B);
                color: #fff;
                border-radius: 18px;
                display: inline-flex; align-items: center; justify-content: center;
                margin-bottom: 14px;
                box-shadow: 0 10px 28px rgba(15, 23, 42, 0.25);
            }
            .pwa-modal-title { font-size: 19px; font-weight: 800; color: #0F172A; margin: 0 0 4px; letter-spacing: -0.01em; }
            .pwa-modal-sub { font-size: 13px; color: #64748B; margin: 0 0 16px; line-height: 1.5; }

            .pwa-steps { display: flex; flex-direction: column; gap: 14px; margin: 14px 0 18px; }
            .pwa-step {
                display: flex; gap: 12px;
                background: #F8FAFC;
                border: 1px solid #EEF0F2;
                border-radius: 14px;
                padding: 12px 14px;
            }
            .pwa-step-num {
                flex-shrink: 0;
                width: 26px; height: 26px;
                border-radius: 999px;
                background: #0F172A; color: #fff;
                font-size: 12px; font-weight: 800;
                display: inline-flex; align-items: center; justify-content: center;
            }
            .pwa-step-main { flex: 1; min-width: 0; }
            .pwa-step-text { font-size: 13.5px; color: #0F172A; line-height: 1.45; display: inline-flex; flex-wrap: wrap; align-items: center; gap: 4px; }
            .pwa-step-text strong { font-weight: 800; }
            .pwa-step-icon {
                display: inline-flex; align-items: center; justify-content: center;
                width: 30px; height: 30px;
                background: #fff; border: 1px solid #E5E7EB; border-radius: 8px;
                color: #2563EB; margin-left: 2px;
                vertical-align: middle;
            }
            .pwa-step-mini-icon {
                display: inline-flex; align-items: center; justify-content: center;
                width: 22px; height: 22px;
                background: #DBEAFE; color: #1D4ED8;
                border-radius: 6px; font-weight: 800; font-size: 14px;
                margin: 0 2px;
                vertical-align: middle;
            }
            .pwa-step-mini-text {
                display: inline-block; padding: 3px 10px;
                background: #0F172A; color: #fff;
                border-radius: 6px; font-size: 11px; font-weight: 700;
                margin-left: 4px;
                vertical-align: middle;
            }
            .pwa-step-hint { font-size: 11.5px; color: #64748B; margin-top: 4px; line-height: 1.4; }

            .pwa-info-block {
                display: flex; gap: 14px; align-items: flex-start;
                background: #F1F5F9; border-radius: 14px; padding: 14px;
                margin-bottom: 18px;
            }
            .pwa-info-icon {
                width: 40px; height: 40px;
                background: #fff; color: #0F172A;
                border-radius: 12px;
                display: inline-flex; align-items: center; justify-content: center;
                flex-shrink: 0;
            }
            .pwa-info-title { font-size: 13px; font-weight: 800; color: #0F172A; margin: 0 0 8px; }
            .pwa-info-list { margin: 0; padding-left: 18px; font-size: 13px; color: #475569; line-height: 1.55; }
            .pwa-info-list li { margin-bottom: 4px; }

            .pwa-modal-actions {
                display: flex; gap: 8px; flex-wrap: wrap;
            }
            .pwa-modal-actions .pwa-btn { flex: 1 1 auto; }

            .pwa-btn {
                border: 0; cursor: pointer;
                font-size: 13.5px; font-weight: 700;
                padding: 12px 16px; border-radius: 12px;
                font-family: inherit;
                transition: all .15s ease;
            }
            .pwa-btn-primary { background: #0F172A; color: #fff; }
            .pwa-btn-primary:hover { background: #1E293B; }
            .pwa-btn-ghost { background: #F4F4F5; color: #475569; }
            .pwa-btn-ghost:hover { background: #E5E7EB; color: #0F172A; }

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
                font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            }
            .pwa-toast.is-visible { transform: translateX(-50%) translateY(0); opacity: 1; }
            .pwa-toast-info { font-size: 13px; font-weight: 600; }
            .pwa-toast-text { font-size: 13px; font-weight: 600; }
            .pwa-toast-btn {
                background: #fff; color: #0F172A; border: 0;
                font-size: 12px; font-weight: 800;
                padding: 6px 12px; border-radius: 999px;
                cursor: pointer; font-family: inherit;
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
                font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
            }
            .pwa-net-pill.is-visible { transform: translateX(-50%) translateY(0); }
            .pwa-net-pill.is-restored { background: #F0FDF4; color: #15803D; border-color: #86EFAC; }
            .pwa-net-dot { width: 8px; height: 8px; border-radius: 999px; background: currentColor; animation: pwaPulse 1.4s ease-in-out infinite; }
            @keyframes pwaPulse { 0%,100% { opacity: 1; } 50% { opacity: 0.35; } }

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
                .pwa-modal-card { padding: 22px 18px 18px; border-radius: 20px; }
                .pwa-modal-title { font-size: 17px; }
                .pwa-modal-sub { font-size: 12.5px; }
                .pwa-step-text { font-size: 13px; }
            }
        `;
        const style = document.createElement('style');
        style.textContent = css;
        document.head.appendChild(style);
    }
    injectStyles();

    if (isStandalone) {
        document.documentElement.classList.add('pwa-standalone');
        if (document.body) document.body.classList.add('pwa-standalone');
        document.addEventListener('DOMContentLoaded', function() { document.body.classList.add('pwa-standalone'); });
    }

    // ===== Helpers
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
})();
