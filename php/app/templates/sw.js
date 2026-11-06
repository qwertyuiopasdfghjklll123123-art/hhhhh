
const CACHE_NAME = 'fc-v6';
const ASSETS = ['/pwa-icon-192.png','/pwa-icon-512.png'];
self.addEventListener('install', e => { e.waitUntil(caches.open(CACHE_NAME).then(c => c.addAll(ASSETS))); self.skipWaiting(); });
self.addEventListener('activate', e => { e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== CACHE_NAME).map(k => caches.delete(k))))); self.clients.claim(); });
self.addEventListener('fetch', e => {
  if (e.request.method !== 'GET') return;
  e.respondWith(
    fetch(e.request, {cache: 'no-store'}).then(r => r).catch(() => {
      if (e.request.destination === 'image') return caches.match(e.request);
      return new Response('<html dir=rtl><head><meta charset=UTF-8><meta name=viewport content="width=device-width,initial-scale=1"><style>*{margin:0;font-family:IBM Plex Sans Arabic,Tajawal,sans-serif}body{display:flex;align-items:center;justify-content:center;min-height:100vh;background:#000000;color:#fff;text-align:center}.c{padding:30px}.ic{font-size:48px;margin-bottom:16px;opacity:.5}h2{font-size:18px;margin-bottom:8px}p{font-size:13px;color:#888;margin-bottom:20px}button{padding:12px 30px;border:none;border-radius:10px;background:#4f46e5;color:#fff;font-family:inherit;font-size:14px;font-weight:700;cursor:pointer}</style></head><body><div class=c><div class=ic>📡</div><h2>لا يوجد اتصال</h2><p>تأكد من اتصالك بالإنترنت وحاول مرة أخرى</p><button onclick=location.reload()>إعادة المحاولة</button></div></body></html>', {status:503, headers:{'Content-Type':'text/html;charset=UTF-8'}});
    })
  );
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
