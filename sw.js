/* واصل — Service Worker بسيط: يفعّل تثبيت الموقع كتطبيق (PWA) وعرض إشعارات المتصفح.
   بدون تخزين مؤقت للصفحات — بيانات الحملات لازم تكون حيّة دائماً. */
self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (e) { e.waitUntil(self.clients.claim()); });
self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    for (var i = 0; i < list.length; i++) {
      if ('focus' in list[i]) return list[i].focus();
    }
    return self.clients.openWindow(self.registration.scope);
  }));
});
