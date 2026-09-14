const CACHE_NAME = 'souq-cache-v2';
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

  /* stale-while-revalidate: نرجع النسخة المخزّنة فوراً (سرعة التحميل نفسها
     كالسابق)، لكن نجلب بالخلفية نسخة جديدة من الشبكة ونحدّث بها المخزن
     المؤقت دائماً — بهذا لو رُفع تحديث لـ app.js/style.css بدون تذكّر رفع
     رقم إصدار CACHE_NAME، أقصى تأخير لوصول التحديث لجهاز المستخدم زيارة
     واحدة فقط، بدل بقاء نسخة قديمة من الكود مخزّنة للأبد وتسبب أزراراً لا
     تعمل بسبب عدم توافقها مع HTML الحالي. */
  event.respondWith(
    caches.open(CACHE_NAME).then(async (cache) => {
      const cached = await cache.match(req);
      const networkFetch = fetch(req).then((res) => {
        if (res && res.ok) cache.put(req, res.clone());
        return res;
      });
      if (cached) {
        networkFetch.catch(() => {});
        return cached;
      }
      return networkFetch;
    })
  );
});
