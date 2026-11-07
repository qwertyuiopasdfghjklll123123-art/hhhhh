/* Service Worker: كاش للصور والخطوط والملفات الثابتة + إشعارات Push (لا تصفح دون اتصال: الصفحات وواجهات الـ API تُجلب دائماً من الشبكة) */
const V = 'v9';
const IMG = 'fc-img-' + V, STATIC = 'fc-static-' + V;
const KEEP = [IMG, STATIC];
const IMG_RE = /^\/(api\/(pm-image|ticket-img|cs-image|admin\/logo|avatar)|brand-media|brand-default|dev-avatar|pwa-icon|site-logo|favicon)/;

self.addEventListener('install', e => { self.skipWaiting(); e.waitUntil(caches.open(IMG).then(c => c.addAll(['/pwa-icon-192.png', '/pwa-icon-512.png', '/brand-default.svg?v=3']).catch(() => {}))); });
/* تنظيف كاشات الإصدارات القديمة (بما فيها كاش الصفحات/الـ API من الإصدارات التي كانت تدعم العمل دون اتصال) */
self.addEventListener('activate', e => { e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => !KEEP.includes(k)).map(k => caches.delete(k)))).then(() => self.clients.claim())); });

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
  if (req.method !== 'GET' || req.headers.has('range') || req.mode === 'navigate') return;      // الصفحات دائماً من الشبكة
  const url = new URL(req.url);
  const same = url.origin === location.origin;
  if (same && url.pathname === '/sw.js') return;
  // 1) صور الموقع: من الكاش فوراً وتتجدد بالخلفية (لا تُحمَّل من جديد عند كل تنقّل)
  if (same && (req.destination === 'image' || IMG_RE.test(url.pathname))) { e.respondWith(staleWhileRevalidate(req, IMG)); return; }
  // 2) خطوط وأيقونات وأنماط خارجية: كاش أولاً
  if (!same && /fonts\.(googleapis|gstatic)\.com|cdnjs\.cloudflare\.com|cdn\.jsdelivr\.net/.test(url.hostname)) { e.respondWith(cacheFirst(req, STATIC)); return; }
  if (same && /\.(js|css|woff2?|ttf)$/.test(url.pathname) || same && url.pathname.startsWith('/assets/')) { e.respondWith(staleWhileRevalidate(req, STATIC)); return; }
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
