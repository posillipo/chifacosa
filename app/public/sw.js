// Service worker minimo per l'installabilità PWA. Non mette MAI in cache le pagine PHP
// (contenuti dinamici: dashboard, timeline, feed...) per evitare di mostrare dati vecchi o
// rompere form/CSRF — mette in cache solo gli asset statici (css/js/icone), che sono già
// versionati con ?v=<filemtime> da assetUrl() lato server, quindi una nuova versione ha
// sempre un URL diverso e non serve invalidare nulla manualmente.
const CACHE_NAME = 'cfc-static-v1';
const PRECACHE_URLS = [
  '/assets/css/style.css',
  '/assets/icons/icon-192.png',
  '/assets/icons/icon-512.png',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(PRECACHE_URLS)).catch(() => {})
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((names) => Promise.all(
      names.filter((n) => n !== CACHE_NAME).map((n) => caches.delete(n))
    ))
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // Solo GET, solo stessa origine, solo file statici sotto /assets/ — tutto il resto (pagine
  // PHP, chiamate AJAX, richieste cross-origin a CDN esterni) passa dritto alla rete.
  if (event.request.method !== 'GET' || url.origin !== self.location.origin || !url.pathname.startsWith('/assets/')) {
    return;
  }

  event.respondWith(
    caches.match(event.request).then((cached) => {
      const network = fetch(event.request).then((response) => {
        if (response && response.ok) {
          caches.open(CACHE_NAME).then((cache) => cache.put(event.request, response.clone()));
        }
        return response;
      }).catch(() => cached);
      return cached || network;
    })
  );
});
