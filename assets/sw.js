/* Минимальный service worker: кеширует оболочку, чтобы приложение
   открывалось при плохой связи. API никогда не кешируется — данные
   о прогрессе должны быть свежими. */

var CACHE = 'l180-shell-v1';
var SHELL = ['/', '/assets/app.css', '/assets/app.js', '/assets/manifest.json', '/assets/icon.svg'];

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll(SHELL); }).then(function () {
    return self.skipWaiting();
  }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) {
      return caches.delete(k);
    }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
  var url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.pathname.indexOf('/api/') === 0) return;

  e.respondWith(
    fetch(e.request).then(function (res) {
      if (res && res.status === 200 && url.origin === location.origin) {
        var copy = res.clone();
        caches.open(CACHE).then(function (c) { c.put(e.request, copy); });
      }
      return res;
    }).catch(function () { return caches.match(e.request); })
  );
});
