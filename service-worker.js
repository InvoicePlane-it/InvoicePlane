const CACHE_NAME = 'invoiceplane-v1.3';
const STATIC_ASSETS = [
  '/assets/invoiceplane/css/style.css',
  '/assets/core/css/custom.css',
  '/assets/core/js/locales/bootstrap-datepicker.it.js',
  '/assets/core/js/dependencies.js',
  '/assets/core/img/favicon.png',
  '/assets/core/js/locales/select2/it.js',
  '/assets/core/js/locales/select2/en.js',
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME).then(cache => {
        return cache.addAll(STATIC_ASSETS).catch(err => {
            console.warn('Alcuni file statici non sono stati trovati:', err);
        });
    })
  );
  self.skipWaiting();
});

self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys().then(keys => Promise.all(
      keys.map(key => {
        if (key !== CACHE_NAME) return caches.delete(key);
      })
    ))
  );
  self.clients.claim();
});

self.addEventListener('fetch', event => {
  const requestURL = new URL(event.request.url);

  if (requestURL.pathname.startsWith('/assets/') || requestURL.pathname.match(/\.(png|jpg|jpeg|svg|css|js)$/)) {
    event.respondWith(
      caches.match(event.request).then(cachedResponse => {
        return cachedResponse || fetch(event.request);
      })
    );
    return;
  }
});
