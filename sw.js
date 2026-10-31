// Lets the gate kiosk page open again even when the internet is down (it was loaded before).
var CACHE = 'lnchs-kiosk-v1';
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });
self.addEventListener('fetch', function (e) {
    var req = e.request;
    if (req.method !== 'GET') return;
    var url = new URL(req.url);
    if (url.origin !== location.origin) return;
    if (/\/kiosk\.php$/.test(url.pathname) || /\/bg\.png$/.test(url.pathname)) {
        e.respondWith(fetch(req).then(function (res) {
            if (res.ok && !res.redirected) { var copy = res.clone(); caches.open(CACHE).then(function (c) { c.put(req, copy); }); return res; }
            return caches.match(req).then(function (c) { return c || res; });   // server error or maintenance page: keep showing the saved kiosk page
        }).catch(function () { return caches.match(req); }));
    }
});
