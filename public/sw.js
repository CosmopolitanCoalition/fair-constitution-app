/* Only the public connection-help page is cached. Never cache personal pages,
   API responses, ballots or wallet data, and never queue mutations offline. */
const OFFLINE_CACHE = 'wos-offline-v1';
self.addEventListener('install', event => {
    event.waitUntil(caches.open(OFFLINE_CACHE).then(cache => cache.add('/app-offline.html')));
});
self.addEventListener('activate', event => {
    event.waitUntil(Promise.all([
        caches.keys().then(keys => Promise.all(keys.filter(key => key.startsWith('wos-offline-') && key !== OFFLINE_CACHE).map(key => caches.delete(key)))),
        self.clients.claim(),
    ]));
});
self.addEventListener('fetch', event => {
    if (event.request.method !== 'GET' || event.request.mode !== 'navigate') return;
    event.respondWith(fetch(event.request).catch(async () =>
        (await caches.match('/app-offline.html')) || new Response('Connection needed', { status: 503 })));
});
self.addEventListener('push', event => {
    let payload = {};
    try { payload = event.data?.json() || {}; } catch { /* An empty push still needs a visible notification. */ }
    event.waitUntil(self.registration.showNotification(payload.title || 'World of Statecraft', {
        body: payload.body || 'You have an update. Open the app to see it.',
        icon: '/app-icons/icon-192.png',
        tag: payload.tag || 'statecraft-update', data: { url: safePath(payload.url) },
    }));
});
function safePath(path) {
    try { const url = new URL(path || '/system/app', self.location.origin); return url.origin === self.location.origin ? url.href : self.location.origin + '/system/app'; }
    catch { return self.location.origin + '/system/app'; }
}
self.addEventListener('notificationclick', event => {
    event.notification.close();
    event.waitUntil(self.clients.openWindow(safePath(event.notification.data?.url)));
});
