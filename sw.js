/* Service worker de la app instalable.
 * NO guarda páginas del panel en caché: son datos clínicos y deben venir
 * siempre frescos del servidor (y no quedarse en el teléfono). Solo da una
 * pantalla amable cuando no hay internet. */
const CACHE = 'medios-offline-v1';
const OFFLINE = new URL('offline.html', self.registration.scope).href;

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(CACHE).then((c) => c.add(OFFLINE)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (e) => {
    e.waitUntil(caches.keys().then((ks) => Promise.all(ks.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
        .then(() => self.clients.claim()));
});
self.addEventListener('fetch', (e) => {
    if (e.request.mode !== 'navigate') return;   // todo lo demás, directo a la red
    e.respondWith(fetch(e.request).catch(() => caches.match(OFFLINE)));
});
