const CACHE_NAME = 'souq-cache-v1';
const CORE_ASSETS = ['assets/style.css', 'assets/app.js', 'assets/icon-192.png', 'assets/icon-512.png'];

/* لا يخزّن أي صفحة أو بيانات ديناميكية إطلاقاً — فقط ملفات الواجهة الثابتة
   (CSS/JS/الأيقونات) لتحميل أسرع وتثبيت PWA فقط. كل طلب آخر (كل صفحات
   الموقع، بيانات المستخدم، تسجيل الدخول...) يذهب للشبكة مباشرة دون أي
   تدخل من الـ Service Worker ولا يُخزَّن، فلا تبقى أي صفحة متاحة أو تُعرض
   من نسخة قديمة عند انقطاع الإنترنت — يفشل الطلب بشكل طبيعي وواضح بدلاً
   من ذلك. */

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(CORE_ASSETS)).catch(() => {})
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
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  if (!CORE_ASSETS.some((a) => url.pathname.endsWith('/' + a) || url.pathname.endsWith(a))) return;

  event.respondWith(
    caches.match(req).then((cached) => cached || fetch(req))
  );
});
