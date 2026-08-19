// خدمة عامل بسيطة لتفعيل تثبيت النظام كتطبيق (PWA) على كل الأقسام.
// لا تخزّن أي صفحة أو طلب AJAX مؤقتاً عمداً — البيانات (حضور، طلبات، رواتب)
// حقيقية ويجب أن تصل دائماً من الخادم مباشرة، لا من ذاكرة تخزين قديمة.
const CACHE_NAME = 'alsawwa-shell-v1';
const SHELL_ASSETS = ['/icons/icon-192.png', '/icons/icon-512.png'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => cache.addAll(SHELL_ASSETS)).catch(() => {})
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== CACHE_NAME).map((k) => caches.delete(k))))
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    if (event.request.method !== 'GET') return;
    const url = new URL(event.request.url);
    if (SHELL_ASSETS.some((path) => url.pathname === path)) {
        event.respondWith(
            caches.match(event.request).then((cached) => cached || fetch(event.request))
        );
        return;
    }
    // كل شيء آخر (صفحات PHP، نقاط AJAX) يذهب مباشرة للشبكة دائماً
    event.respondWith(fetch(event.request));
});
