// Service Worker بسيط لإمكانية تثبيت واصل كتطبيق (PWA) - شبكة أولاً دائماً (الصفحات
// وكل استدعاءات API تتغيّر باستمرار: حملات، رسائل، رصيد) ولا كاش لها إطلاقاً، فقط
// أيقونات التطبيق الثابتة تُخزَّن كبديل عند انقطاع الشبكة تماماً.
const CACHE_NAME = 'wasel-shell-v1';
const SHELL_ASSETS = ['/icon-192.png', '/icon-512.png'];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(SHELL_ASSETS)));
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
  event.respondWith(fetch(event.request).catch(() => caches.match(event.request)));
});
