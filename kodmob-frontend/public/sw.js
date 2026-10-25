/**
 * Kodmob service worker (site ve panel, kapsam /): web uygulaması ve anlık bildirimler.
 *
 * - Sayfalar her zaman sunucudan gelir (sunucunun sayfa önbelleği ve 304'ü korunur); bağlantı yoksa çevrim dışı
 *   sayfası (/offline.html) gösterilir.
 * - Adında sürüm izi olan dosyalar (/assets/…-<iz>.js|css|woff2…) bir kez indirilir, sonra cihazdan açılır.
 * - API istekleri, panel verisi ve medya hiç saklanmaz.
 * - Anlık bildirim (Web Push, Core\Push): başlık, metin ve tıklayınca açılacak adres; aynı `tag` öncekinin yerine geçer.
 *
 * Sürüm değişince eski önbellek silinir (VERSION).
 */
const VERSION = 'km-1';
const OFFLINE = '/offline.html';
const PRECACHE = [OFFLINE, '/offline.css', '/assets/favicon/favicon.svg'];
const HASHED = /^\/assets\/.+-[A-Za-z0-9_-]{8,}\.(?:js|css|woff2|svg|png|webp|jpg)$/;

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(VERSION).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key !== VERSION).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin || url.pathname.startsWith('/api/')) return;

    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE)));
        return;
    }
    if (HASHED.test(url.pathname)) {
        event.respondWith(
            caches.match(request).then((hit) => hit || fetch(request).then((response) => {
                if (response.ok) {
                    const copy = response.clone();
                    caches.open(VERSION).then((cache) => cache.put(request, copy));
                }
                return response;
            })),
        );
    }
});

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = { title: event.data ? event.data.text() : '' };
    }
    const title = data.title || 'Bildirim';
    event.waitUntil(self.registration.showNotification(title, {
        body: data.body || '',
        icon: '/api/v1/icon/192.png',
        badge: '/api/v1/icon/192.png',
        tag: data.tag || undefined,
        data: { url: data.url || '/' },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = new URL(event.notification.data?.url || '/', self.location.origin).href;
    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            const same = windows.find((w) => w.url === target);
            if (same) return same.focus();
            const panel = target.includes('/panel/') && windows.find((w) => w.url.includes('/panel/'));
            if (panel) return panel.focus().then(() => panel.navigate(target));
            return self.clients.openWindow(target);
        }),
    );
});
