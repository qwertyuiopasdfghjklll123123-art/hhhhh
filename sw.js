// Service Worker بسيط لتفعيل إمكانية تثبيت التطبيق (PWA) - بدون تخزين مؤقت
// يمرّر كل الطلبات مباشرة للشبكة حتى تبقى بيانات التطبيق الحيّة دائماً محدثة.

self.addEventListener('install', (event) => {
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('fetch', (event) => {
    event.respondWith(fetch(event.request));
});
