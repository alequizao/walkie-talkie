/**
 * service-worker.js — Walkie Talkie PWA
 * - Cache-first para assets estáticos
 * - Network-first para /api/ (sempre dado fresco)
 * - Bypass total para WebSocket
 */
// Versão vem do ?v= usado no register() (fonte única: APP_VERSION no .env).
// O fallback só vale se o SW for registrado sem query.
const CACHE_VERSION = 'wt-v' + (new URL(self.location.href).searchParams.get('v') || '1.9.0');
const STATIC_CACHE = 'wt-static-' + CACHE_VERSION;
// Base vem do escopo do registro: '/walkietalkie' em publishdev.com.br e ''
// na raiz de voip.usegrupodona.com.br.
const BASE = new URL(self.registration.scope).pathname.replace(/\/$/, '');
const STATIC_ASSETS = [
    BASE + '/',
    BASE + '/index.php',
    BASE + '/manifest.php',
    BASE + '/assets/css/style.css',
    BASE + '/assets/js/utils.js',
    BASE + '/assets/js/notifications.js',
    BASE + '/assets/js/websocket.js',
    BASE + '/assets/js/webrtc.js',
    BASE + '/assets/js/queue.js',
    BASE + '/assets/js/ptt.js',
    BASE + '/assets/js/background.js',
    BASE + '/assets/js/app.js',
    BASE + '/icons/icon-192.png',
    BASE + '/icons/icon-512.png'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(STATIC_CACHE).then(cache => {
            // addAll falha tudo se um item falhar; faz-se best-effort.
            return Promise.all(
                STATIC_ASSETS.map(url => cache.add(url).catch(() => null))
            );
        }).then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then(keys => {
            return Promise.all(
                keys.filter(k => k !== STATIC_CACHE).map(k => caches.delete(k))
            );
        }).then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    const url = new URL(req.url);

    // Apenas GETs same-origin
    if (req.method !== 'GET') return;
    if (url.origin !== location.origin) return;

    // HTML (navegação): network-first para o app instalado pegar a versão nova
    // ao abrir; cai no cache só quando offline.
    if (req.mode === 'navigate' || req.destination === 'document') {
        event.respondWith(
            fetch(req).then(fresh => {
                if (fresh && fresh.status === 200) {
                    const clone = fresh.clone();
                    caches.open(STATIC_CACHE).then(c => c.put(BASE + '/index.php', clone));
                }
                return fresh;
            }).catch(() => caches.match(BASE + '/index.php') || caches.match(BASE + '/'))
        );
        return;
    }

    // Bypass para WebSocket e API
    if (url.pathname.startsWith(BASE + '/api/') || url.pathname.startsWith(BASE + '/ws')) {
        // Network-first com fallback offline
        event.respondWith(
            fetch(req).catch(() => new Response(
                JSON.stringify({ ok: false, error: 'offline' }),
                { status: 503, headers: { 'Content-Type': 'application/json' } }
            ))
        );
        return;
    }

    // Cache-first para o resto (estático)
    event.respondWith(
        caches.match(req).then(cached => {
            if (cached) {
                // Atualiza em background
                fetch(req).then(fresh => {
                    if (fresh && fresh.status === 200) {
                        caches.open(STATIC_CACHE).then(c => c.put(req, fresh.clone()));
                    }
                }).catch(() => null);
                return cached;
            }
            return fetch(req).then(fresh => {
                if (fresh && fresh.status === 200) {
                    const clone = fresh.clone();
                    caches.open(STATIC_CACHE).then(c => c.put(req, clone));
                }
                return fresh;
            }).catch(() => caches.match(BASE + '/index.php'));
        })
    );
});

// Push notifications (VAPID) — chega mesmo com o app fechado.
self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch (e) {}

    event.waitUntil((async () => {
        // Se já existe uma janela VISÍVEL, o app trata pelo WebSocket:
        // manda o evento para ela e não mostra notificação duplicada.
        const list = await clients.matchAll({ type: 'window', includeUncontrolled: true });
        const visible = list.find(c => c.visibilityState === 'visible');
        if (visible) {
            visible.postMessage({ type: 'PUSH', payload: data });
            return;
        }

        const title = data.title || 'Walkie Talkie';
        await self.registration.showNotification(title, {
            body: data.body || '',
            icon: BASE + '/icons/icon-192.png',
            badge: BASE + '/icons/icon-192.png',
            vibrate: data.vibrate || [200, 100, 200],
            tag: data.tag || 'wt',
            renotify: true,
            // Chamada tocando fica na tela até o usuário responder
            requireInteraction: data.kind === 'call',
            data: { url: data.url || '', kind: data.kind || '', from: data.from || '' },
        });
    })());
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();

    const info = event.notification.data || {};
    const target = BASE + '/' + (info.url || '');

    event.waitUntil((async () => {
        const list = await clients.matchAll({ type: 'window', includeUncontrolled: true });

        // Já tem o app aberto: foca e avisa para abrir a conversa certa
        for (const c of list) {
            if (c.url.startsWith(self.location.origin) && 'focus' in c) {
                await c.focus();
                c.postMessage({ type: 'NOTIFICATION_CLICK', payload: info });
                return;
            }
        }

        // App fechado: abre já apontando para a conversa
        if (clients.openWindow) await clients.openWindow(target);
    })());
});

// Mensagem do app (skip waiting)
self.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'SKIP_WAITING') self.skipWaiting();
});
