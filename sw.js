/**
 * Service Worker básico do EcoCity (PWA).
 * - Páginas PHP/HTML e APIs: sempre direto na rede (nunca armazena HTML com sessão/CSRF/nonce em cache);
 *   em caso de falha de rede em navegação, exibe ./offline.html.
 * - Ativos estáticos da própria origem: Network-First com fallback para Cache.
 */
const CACHE_NAME = 'ecocity-pwa-v1';
const PRECACHE = [
    './offline.html',
    './manifest.json',
    './assets/css/base.css',
    './assets/js/offline.js',
    './assets/img/logo-ecocity-icone.png',
    './assets/img/logo-ecocity.png',
    './assets/img/icone-pwa-180.png',
    './assets/img/icone-pwa-192.png',
    './assets/img/icone-pwa-512.png',
    './assets/img/icone-pwa-maskable-512.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => cache.addAll(PRECACHE))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;
    if (req.method !== 'GET') return;

    const url = new URL(req.url);
    if (url.origin !== self.location.origin) return;
    if (url.pathname.includes('/api/') || url.pathname.includes('/uploads/')) return;

    const ehNavegacao = req.mode === 'navigate'
        || (req.headers.get('accept') || '').includes('text/html')
        || url.pathname.endsWith('.php');

    if (ehNavegacao) {
        event.respondWith(
            fetch(req).catch(() => caches.match('./offline.html'))
        );
        return;
    }

    const ehAtivo = url.pathname.includes('/assets/')
        || url.pathname.endsWith('/manifest.json')
        || url.pathname.endsWith('/offline.html');
    if (!ehAtivo) return;

    event.respondWith(
        fetch(req).then((res) => {
            if (res && res.ok) {
                const copia = res.clone();
                caches.open(CACHE_NAME).then((cache) => cache.put(req, copia)).catch(() => {});
            }
            return res;
        }).catch(() => caches.match(req).then((c) => c || caches.match(req, { ignoreSearch: true })))
    );
});
