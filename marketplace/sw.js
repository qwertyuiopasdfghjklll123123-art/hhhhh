const CACHE_NAME = 'souq-cache-v1';
const FRAGMENT_CACHE_NAME = 'souq-fragments-v1';
const CORE_ASSETS = ['assets/style.css', 'assets/app.js', 'assets/icon-192.png', 'assets/icon-512.png'];
/* مفتاح ثابت يُحفظ تحته دائماً آخر "جزء" تم تصفحه بنجاح — بما أن هذا
   التطبيق تصفح-صفحة-واحدة لا يغيّر رابط المتصفح أبداً، فرابط أي إعادة
   تحميل أو فتح تطبيق مثبَّت قد يكون رابطاً مختلفاً كلياً عن آخر صفحة كانت
   معروضة فعلياً (مثلاً: صفحة الدخول التي بدأ منها المستخدم، وليس صفحة
   المتاجر التي تصفحها بعدها) — فالاعتماد على مطابقة الرابط نفسه لا يكفي. */
const LAST_PAGE_KEY = 'http://sw-internal.local/last-page';

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(CORE_ASSETS)).catch(() => {})
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((names) => Promise.all(
      names.filter((n) => n !== CACHE_NAME && n !== FRAGMENT_CACHE_NAME).map((n) => caches.delete(n))
    ))
  );
  self.clients.claim();
});

/* يُرسل من app.js بعد تسجيل دخول/خروج/تسجيل حساب ناجح — يمسح كل الصفحات
   المخزَّنة (التي قد تخص جلسة مستخدم سابقة) دون الحاجة لإعادة تحميل ملفات
   الواجهة الثابتة (CSS/JS/الأيقونات) التي تبقى كما هي. */
self.addEventListener('message', (event) => {
  if (event.data !== 'clear-dynamic-cache') return;
  event.waitUntil(caches.delete(FRAGMENT_CACHE_NAME));
});

const OFFLINE_FRAGMENT = {
  title: 'غير متصل',
  html: '<div class="card an" style="text-align:center;padding:34px 16px"><i class="fas fa-wifi" style="font-size:1.6rem;color:#e5484d;margin-bottom:10px;display:block"></i><h3 style="margin-bottom:8px">لا يوجد اتصال بالإنترنت</h3><p style="font-size:.8rem;color:#6b7280">هذه الصفحة لم تُفتح سابقاً فلا توجد نسخة محفوظة عنها.</p></div>',
};

function shellPage(title, bodyHtml) {
  return '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8">'
    + '<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">'
    + '<title>' + title + '</title>'
    + '<link rel="manifest" href="manifest.php"><meta name="theme-color" content="#f2b100">'
    + '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">'
    + '<link rel="stylesheet" href="assets/style.css"></head><body>'
    + '<div class="bg-orb bo1"></div><div class="bg-orb bo2"></div>'
    + '<div class="offline-banner" id="offlineBanner"><i class="fas fa-wifi"></i> <span>لا يوجد اتصال بالإنترنت — تتصفح نسخة محفوظة مؤقتاً</span></div>'
    + '<div id="app-root">' + bodyHtml + '</div>'
    + '<script>window.APP_CONFIG = { siteName: "", googleClientId: "" };</script>'
    + '<script src="assets/app.js"></script></body></html>';
}

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return; // لا نتدخل بطلبات تغيّر بيانات (POST) — يجب أن تفشل بوضوح بلا اتصال
  if (new URL(req.url).origin !== self.location.origin) return; // خطوط/سكربتات خارجية: تُترك للمتصفح كالمعتاد

  /* إعادة تحميل الصفحة (أو فتح التطبيق المثبَّت من الشاشة الرئيسية) بلا
     إنترنت: تخزين استجابة HTML كاملة لطلبات التنقل (mode=navigate) غير
     مضمون بثبات عبر كل المتصفحات، فبدل ذلك نعيد بناء نفس هيكل الصفحة حول
     آخر "جزء" (JSON) محفوظ لهذا الرابط بالضبط في ذاكرة الأجزاء — وهي
     الذاكرة التي تُحدَّث بثبات مع كل تصفح داخل التطبيق (SPA) أثناء الاتصال،
     فيبقى آخر محتوى حقيقي زاره المستخدم متاحاً دون إنترنت. */
  if (req.mode === 'navigate') {
    event.respondWith(
      fetch(req).catch(async () => {
        const cache = await caches.open(FRAGMENT_CACHE_NAME);
        const fragReq = new Request(req.url, { headers: { 'X-Requested-With': 'fetch' } });
        const cached = (await cache.match(fragReq)) || (await cache.match(LAST_PAGE_KEY));
        const data = cached ? await cached.json() : OFFLINE_FRAGMENT;
        return new Response(shellPage(data.title, data.html), { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
      })
    );
    return;
  }

  const isFragmentFetch = req.headers.get('X-Requested-With') === 'fetch';
  const cacheName = isFragmentFetch ? FRAGMENT_CACHE_NAME : CACHE_NAME;

  event.respondWith(
    fetch(req).then((res) => {
      if (res && res.ok) {
        const copyForUrl = res.clone();
        const copyForLastPage = isFragmentFetch ? res.clone() : null;
        event.waitUntil((async () => {
          const cache = await caches.open(cacheName);
          await cache.put(req, copyForUrl);
          if (copyForLastPage) await cache.put(LAST_PAGE_KEY, copyForLastPage);
        })());
      }
      return res;
    }).catch(async () => {
      const cache = await caches.open(cacheName);
      const cached = await cache.match(req);
      if (cached) return cached;
      if (isFragmentFetch) return new Response(JSON.stringify(OFFLINE_FRAGMENT), { headers: { 'Content-Type': 'application/json; charset=utf-8' } });
      return Response.error();
    })
  );
});
