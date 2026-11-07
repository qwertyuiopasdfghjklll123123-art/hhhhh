/* Service Worker: عمل دون اتصال (تصفح فقط) + كاش للصور والخطوط + إشعارات Push */
const V = 'v8';
const SHELL = 'fc-shell-' + V, IMG = 'fc-img-' + V, STATIC = 'fc-static-' + V, API = 'fc-api-' + V;
const KEEP = [SHELL, IMG, STATIC, API];
const IMG_RE = /^\/(api\/(pm-image|ticket-img|cs-image|admin\/logo|avatar)|brand-media|brand-default|dev-avatar|pwa-icon|site-logo|favicon)/;
/* واجهات قراءة فقط تُخزَّن لتصفح الصفحات دون اتصال (لا تُخزَّن عمليات الإنشاء/الدفع) */
const API_RE = /^\/api\/(orders|my_custom_orders|payment-methods|custom_services|services|user-stats|reviews\/all|reviews|developer-card|site-policies|currency-rates|favorites|tickets|faq|levels|notifications|daily-bonus\/status|referral|recharge\/history|public-stats|announcements)(\/|$|\?)/;
const OFFLINE_HTML = '<html dir=rtl><head><meta charset=UTF-8><meta name=viewport content="width=device-width,initial-scale=1"><style>*{margin:0;font-family:IBM Plex Sans Arabic,Tajawal,sans-serif}body{display:flex;align-items:center;justify-content:center;min-height:100vh;background:#0f1b33;color:#fff;text-align:center}.c{padding:30px}.ic{font-size:48px;margin-bottom:16px}h2{font-size:18px;margin-bottom:8px}p{font-size:13px;color:#9fb0d0;margin-bottom:20px;line-height:1.8}button{padding:12px 30px;border:none;border-radius:12px;background:#2563eb;color:#fff;font-family:inherit;font-size:14px;font-weight:700;cursor:pointer}</style></head><body><div class=c><div class=ic>📡</div><h2>لا يوجد اتصال</h2><p>افتح التطبيق مرة واحدة بالإنترنت ليُحفظ للتصفح دون اتصال.<br>الطلبات والشحن تحتاج اتصالاً.</p><button onclick=location.reload()>إعادة المحاولة</button></div></body></html>';

self.addEventListener('install', e => { self.skipWaiting(); e.waitUntil(caches.open(IMG).then(c => c.addAll(['/pwa-icon-192.png', '/pwa-icon-512.png', '/brand-default.svg?v=3']).catch(() => {}))); });
self.addEventListener('activate', e => { e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => !KEEP.includes(k)).map(k => caches.delete(k)))).then(() => self.clients.claim())); });

/* مفتاح المستخدم: عند تغيّره تُمسح بيانات الـ API المخزّنة (لا تتسرّب بين حسابات) */
async function setUid(uid) {
  const c = await caches.open(API), cur = await c.match('/__uid');
  const old = cur ? await cur.text() : '';
  if (old !== String(uid)) { await caches.delete(API); const n = await caches.open(API); await n.put('/__uid', new Response(String(uid))); await caches.delete(SHELL); }
}
self.addEventListener('message', e => {
  const d = e.data || {};
  if (d.type === 'clear') e.waitUntil(Promise.all([caches.delete(API), caches.delete(SHELL)]));
  else if (d.type === 'uid') e.waitUntil(setUid(d.uid));
});

async function networkFirst(req, cacheName, timeoutMs) {
  const cache = await caches.open(cacheName);
  try {
    const ctl = new AbortController(); const t = setTimeout(() => ctl.abort(), timeoutMs || 8000);
    const r = await fetch(req, { signal: ctl.signal }); clearTimeout(t);
    if (r && r.ok && (r.type === 'basic' || r.type === 'default')) cache.put(req, r.clone());
    return r;
  } catch (err) {
    const hit = await cache.match(req, { ignoreVary: true });
    if (hit) { const h = new Headers(hit.headers); h.set('X-FC-Offline', '1'); return new Response(await hit.blob(), { status: hit.status, statusText: hit.statusText, headers: h }); }
    throw err;
  }
}
async function staleWhileRevalidate(req, cacheName) {
  const cache = await caches.open(cacheName), hit = await cache.match(req);
  const net = fetch(req).then(r => { if (r && (r.ok || r.type === 'opaque')) cache.put(req, r.clone()); return r; }).catch(() => null);
  return hit || (await net) || Response.error();
}
async function cacheFirst(req, cacheName) {
  const cache = await caches.open(cacheName), hit = await cache.match(req);
  if (hit) return hit;
  const r = await fetch(req); if (r && (r.ok || r.type === 'opaque')) cache.put(req, r.clone()); return r;
}

self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET' || req.headers.has('range')) return;
  const url = new URL(req.url);
  const same = url.origin === location.origin;
  if (same && url.pathname === '/sw.js') return;
  // 1) تنقّل الصفحات: الشبكة أولاً ثم آخر نسخة محفوظة
  if (req.mode === 'navigate') {
    e.respondWith((async () => {
      try { return await networkFirst(req, SHELL, 6000); }
      catch (err) {
        const c = await caches.open(SHELL);
        const hit = (await c.match(req)) || (await c.match('/app'));
        return hit || new Response(OFFLINE_HTML, { status: 503, headers: { 'Content-Type': 'text/html;charset=UTF-8' } });
      }
    })());
    return;
  }
  // 2) صور الموقع: من الكاش فوراً وتتجدد بالخلفية (لا تُحمَّل من جديد عند كل تنقّل)
  if (same && (req.destination === 'image' || IMG_RE.test(url.pathname))) { e.respondWith(staleWhileRevalidate(req, IMG)); return; }
  // 3) خطوط وأيقونات وأنماط خارجية: كاش أولاً
  if (!same && /fonts\.(googleapis|gstatic)\.com|cdnjs\.cloudflare\.com|cdn\.jsdelivr\.net/.test(url.hostname)) { e.respondWith(cacheFirst(req, STATIC)); return; }
  if (same && /\.(js|css|woff2?|ttf)$/.test(url.pathname) || same && url.pathname.startsWith('/assets/')) { e.respondWith(staleWhileRevalidate(req, STATIC)); return; }
  // 4) واجهات القراءة: الشبكة أولاً ثم الكاش عند انقطاع الاتصال
  if (same && API_RE.test(url.pathname)) { e.respondWith(networkFirst(req, API, 8000).catch(() => new Response(JSON.stringify({ ok: false, offline: true, msg: 'أنت غير متصل' }), { status: 503, headers: { 'Content-Type': 'application/json' } }))); return; }
});

self.addEventListener('push', e => {
  let data = {title:'__SW_SITE_NAME__', body:'', icon:'/pwa-icon-192.png', url:'/app'};
  try { data = {...data, ...e.data.json()}; } catch(err) {}
  e.waitUntil(self.registration.showNotification(data.title, {
    body: data.body, icon: data.icon, badge: '/pwa-icon-192.png',
    vibrate: [200,100,200], dir: 'rtl', lang: 'ar',
    data: {url: data.url}
  }));
});
self.addEventListener('notificationclick', e => {
  e.notification.close();
  const url = e.notification.data?.url || '/app';
  e.waitUntil(clients.matchAll({type:'window'}).then(cs => {
    for (const c of cs) { if (c.url.includes(url) && 'focus' in c) return c.focus(); }
    return clients.openWindow(url);
  }));
});
