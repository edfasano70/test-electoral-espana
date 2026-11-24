const CACHE_NAME = 'electoral-test-v11';
const APP_SHELL = [
  './',
  './index.html',
  './manifest.json',
  './elecciones.png',
  './icon-29n-192.png',
  './icon-29n-maskable-512.png',
  './pp.png',
  './psoe.png',
  './vox.png',
  './frenteamplio.png',
  './assets/video-thumbnail.jpg'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(APP_SHELL))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(
        keys.filter(key => key !== CACHE_NAME).map(key => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;

  // Dynamic API (rating stats) must never be served from the cache.
  const url = new URL(event.request.url);
  if (url.origin === self.location.origin && url.pathname.includes('/api/')) {
    event.respondWith(fetch(event.request));
    return;
  }

  event.respondWith(
    caches.match(event.request).then(cached => {
      if (cached) return cached;

      return fetch(event.request)
        .then(response => {
          if (response && response.status === 200 && response.type !== 'opaque') {
            const copy = response.clone();
            caches.open(CACHE_NAME).then(cache => cache.put(event.request, copy));
          }
          return response;
        })
        .catch(() => {
          if (event.request.mode === 'navigate') return caches.match('./index.html');
          return Response.error();
        });
    })
  );
});
