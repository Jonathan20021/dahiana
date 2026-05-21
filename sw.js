// sw.js - Service Worker para PWA del portal
// Estrategias:
//  - Network-first para paginas (HTML/PHP) con fallback a cache y offline.html
//  - Cache-first para assets estaticos (iconos, fuentes, CDN)
//  - Bypass total para endpoints sensibles (auth, upload, telegram, openai)
//  - Background sync para reintentar subidas fallidas

const VERSION = 'v3-2026-05-20';
const STATIC_CACHE  = 'amd-static-' + VERSION;
const RUNTIME_CACHE = 'amd-runtime-' + VERSION;
const OFFLINE_URL   = 'offline.html';

const PRECACHE_URLS = [
    OFFLINE_URL,
    'manifest.php',
    'pwa_icon.php?size=192',
    'pwa_icon.php?size=512',
];

// Endpoints que NO deben cachearse ni interceptarse
const NEVER_CACHE_PATTERNS = [
    /\/auth\.php/i,
    /\/login\.php/i,
    /\/logout/i,
    /\/telegram_webhook\.php/i,
    /\/telegram_poll\.php/i,
    /\/telegram_ping\.php/i,
    /\/upload/i,
    /\/cron_/i,
    /\/migrate_/i,
    /\/admin_telegram_debug/i,
    /onboarding_complete\.php/i,
];

// CDNs estaticos seguros de cachear
const STATIC_CDN_PATTERNS = [
    /cdn\.tailwindcss\.com/i,
    /fonts\.googleapis\.com/i,
    /fonts\.gstatic\.com/i,
    /cdn\.jsdelivr\.net/i,
    /unpkg\.com/i,
];

// --- Install: precache de archivos basicos
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then(cache => cache.addAll(PRECACHE_URLS).catch(err => {
                console.warn('[SW] precache fallo parcialmente:', err);
            }))
            .then(() => self.skipWaiting())
    );
});

// --- Activate: limpiar caches viejos
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then(keys => Promise.all(
                keys
                    .filter(k => k.startsWith('amd-') && k !== STATIC_CACHE && k !== RUNTIME_CACHE)
                    .map(k => caches.delete(k))
            ))
            .then(() => self.clients.claim())
    );
});

// --- Mensajes desde la app (ej: skipWaiting al activar update)
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') {
        self.skipWaiting();
    }
    if (event.data && event.data.type === 'CACHE_PURGE') {
        event.waitUntil(
            caches.keys().then(keys => Promise.all(keys.map(k => caches.delete(k))))
        );
    }
});

// --- Fetch: estrategias por tipo de request
self.addEventListener('fetch', (event) => {
    const req = event.request;
    const url = new URL(req.url);

    // Solo GET
    if (req.method !== 'GET') return;

    // Saltar extension protocols
    if (!url.protocol.startsWith('http')) return;

    // Cross-origin que no sea CDN conocido -> dejar pasar
    if (url.origin !== self.location.origin) {
        if (STATIC_CDN_PATTERNS.some(p => p.test(url.href))) {
            event.respondWith(cacheFirst(req));
        }
        return;
    }

    // Patrones que nunca se cachean
    if (NEVER_CACHE_PATTERNS.some(p => p.test(url.pathname))) {
        return;
    }

    // Endpoints JSON: network-only con fallback minimo
    if (url.pathname.endsWith('notifications.php') ||
        url.pathname.endsWith('global_search.php')) {
        event.respondWith(
            fetch(req).catch(() => new Response(
                JSON.stringify({ ok: false, offline: true, items: [], groups: [] }),
                { headers: { 'Content-Type': 'application/json' } }
            ))
        );
        return;
    }

    // Iconos PWA / manifest -> cache-first
    if (url.pathname.endsWith('pwa_icon.php') || url.pathname.endsWith('manifest.php')) {
        event.respondWith(cacheFirst(req));
        return;
    }

    // Paginas (HTML/PHP) -> network-first con offline fallback
    if (req.mode === 'navigate' || req.headers.get('accept')?.includes('text/html')) {
        event.respondWith(networkFirstPage(req));
        return;
    }

    // Otros assets -> stale-while-revalidate
    event.respondWith(staleWhileRevalidate(req));
});

async function networkFirstPage(req) {
    try {
        const fresh = await fetch(req);
        if (fresh && fresh.status === 200) {
            const cache = await caches.open(RUNTIME_CACHE);
            cache.put(req, fresh.clone()).catch(() => {});
        }
        return fresh;
    } catch (err) {
        const cached = await caches.match(req);
        if (cached) return cached;
        const offline = await caches.match(OFFLINE_URL);
        if (offline) return offline;
        return new Response('Sin conexion', { status: 503, statusText: 'Offline' });
    }
}

async function cacheFirst(req) {
    const cached = await caches.match(req);
    if (cached) return cached;
    try {
        const fresh = await fetch(req);
        if (fresh && fresh.status === 200) {
            const cache = await caches.open(STATIC_CACHE);
            cache.put(req, fresh.clone()).catch(() => {});
        }
        return fresh;
    } catch (err) {
        return new Response('', { status: 504 });
    }
}

async function staleWhileRevalidate(req) {
    const cached = await caches.match(req);
    const fetchPromise = fetch(req).then(resp => {
        if (resp && resp.status === 200) {
            caches.open(RUNTIME_CACHE).then(c => c.put(req, resp.clone()).catch(() => {}));
        }
        return resp;
    }).catch(() => cached);
    return cached || fetchPromise;
}

// --- Background sync para subidas que fallaron
self.addEventListener('sync', (event) => {
    if (event.tag === 'retry-uploads') {
        event.waitUntil(retryPendingUploads());
    }
});

async function retryPendingUploads() {
    // Stub: la cola se gestiona en IndexedDB desde el cliente.
    // Aqui solo notificamos a la app que vuelva a intentar.
    const clients = await self.clients.matchAll();
    clients.forEach(c => c.postMessage({ type: 'SYNC_RETRY' }));
}

// --- Push notifications (preparado para futuro)
self.addEventListener('push', (event) => {
    if (!event.data) return;
    let payload;
    try {
        payload = event.data.json();
    } catch (e) {
        payload = { title: 'Notificacion', body: event.data.text() };
    }
    event.waitUntil(
        self.registration.showNotification(payload.title || 'Portal', {
            body: payload.body || '',
            icon: 'pwa_icon.php?size=192',
            badge: 'pwa_icon.php?size=192',
            data: { url: payload.url || './' },
            tag: payload.tag || 'general',
            renotify: true,
        })
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = (event.notification.data && event.notification.data.url) || './';
    event.waitUntil(
        self.clients.matchAll({ type: 'window' }).then(clients => {
            for (const c of clients) {
                if ('focus' in c) {
                    c.navigate(url);
                    return c.focus();
                }
            }
            if (self.clients.openWindow) return self.clients.openWindow(url);
        })
    );
});
