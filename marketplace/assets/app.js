/* ===== تصفح بلا فتح صفحات جديدة (نفس الرابط من الدخول لآخر شي) ===== */
function setLoading(v){ document.getElementById('app-root')?.classList.toggle('nav-loading', v); }

function applySwap(data){
  const doSwap = () => {
    document.getElementById('app-root').innerHTML = data.html;
    document.title = data.title + (window.APP_CONFIG?.siteName ? ' — ' + window.APP_CONFIG.siteName : '');
    window.scrollTo(0, 0);
    showInstallBanner();
    renderGoogleButton();
    if (document.getElementById('orderSuccessTrigger')) openSheet('orderSuccessSheet');
    if (document.getElementById('onboardTrigger')) { openSheet('onboardSheet'); markOnboardSeen(); }
  };
  if (document.startViewTransition) document.startViewTransition(doSwap);
  else doSwap();
}

/* ===== شعار انقطاع الإنترنت — يظهر/يختفي تلقائياً حسب حالة الاتصال الحقيقية ===== */
function updateOfflineBanner(){
  const b = document.getElementById('offlineBanner');
  if (b) b.hidden = navigator.onLine;
}
window.addEventListener('online', updateOfflineBanner);
window.addEventListener('offline', updateOfflineBanner);
updateOfflineBanner();

/* ===== تثبيت التطبيق (PWA) + تصفح بلا إنترنت للصفحات المفتوحة سابقاً ===== */
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function(){ navigator.serviceWorker.register('sw.js').catch(function(){}); });
}
function clearOfflineCache(){
  navigator.serviceWorker?.getRegistration().then(function(reg){ reg?.active?.postMessage('clear-dynamic-cache'); }).catch(function(){});
}
/* التطبيق لا يغيّر رابط المتصفح أبداً أثناء التصفح الداخلي (كل شيء عبر
   fetch)، لكن نغيّره مرة واحدة فقط عند حدود الدخول/الخروج: دومين/app بعد
   الدخول، وجذر الدومين فقط قبل الدخول — دون أي إعادة تحميل للصفحة. */
function setAuthUrl(actionName){
  try {
    if (['login', 'register', 'google_login'].includes(actionName)) history.replaceState(null, '', 'app');
    else if (actionName === 'logout') history.replaceState(null, '', '/');
  } catch (err) {}
}
let _deferredInstall = null;
window.addEventListener('beforeinstallprompt', function(e){
  e.preventDefault();
  _deferredInstall = e;
  showInstallBanner();
});
function showInstallBanner(){
  if (!_deferredInstall) return;
  let dismissed = false;
  try { dismissed = sessionStorage.getItem('installDismissed') === '1'; } catch (err) {}
  const b = document.getElementById('installBanner');
  if (b && !dismissed) b.hidden = false;
}
function triggerInstall(){
  if (_deferredInstall) { _deferredInstall.prompt(); _deferredInstall.userChoice.finally(() => { _deferredInstall = null; }); }
}
document.addEventListener('click', function(e){
  if (e.target.closest('#installBtn')) {
    document.getElementById('installBanner')?.setAttribute('hidden', '');
    triggerInstall();
  } else if (e.target.closest('#installDismiss')) {
    document.getElementById('installBanner')?.setAttribute('hidden', '');
    try { sessionStorage.setItem('installDismissed', '1'); } catch (err) {}
  } else if (e.target.closest('#onboardInstallBtn')) {
    triggerInstall();
    closeSheets();
  } else if (e.target.closest('#onboardNotifBtn')) {
    if ('Notification' in window && Notification.requestPermission) Notification.requestPermission();
    closeSheets();
  }
});
function markOnboardSeen(){
  const fd = new FormData();
  fd.append('action', 'dismiss_onboarding');
  fetch('index.php', {method:'POST', body: fd, headers:{'X-Requested-With':'fetch'}, credentials:'same-origin'}).catch(function(){});
}
document.addEventListener('DOMContentLoaded', function(){
  if (document.getElementById('onboardTrigger')) { openSheet('onboardSheet'); markOnboardSeen(); }
});

/* ===== تسجيل الدخول عبر Google (يعمل فقط إن كان مفتاح Google مضبوطاً من الأدمن) ===== */
function handleGoogleCredential(response){
  setLoading(true);
  const fd = new FormData();
  fd.append('action', 'google_login');
  fd.append('credential', response.credential);
  fetch('index.php', {method:'POST', body: fd, headers:{'X-Requested-With':'fetch'}, credentials:'same-origin'})
    .then(r => r.json()).then(applySwap).then(() => setAuthUrl('google_login')).catch(() => { window.location.href = 'index.php'; })
    .finally(() => setLoading(false));
}
function renderGoogleButton(){
  const box = document.getElementById('googleBtnContainer');
  if (!box || !window.APP_CONFIG?.googleClientId || !window.google?.accounts?.id) return;
  google.accounts.id.initialize({client_id: window.APP_CONFIG.googleClientId, callback: handleGoogleCredential});
  box.innerHTML = '';
  google.accounts.id.renderButton(box, {type:'standard', theme:'outline', size:'large', shape:'pill', locale:'ar'});
}
window.addEventListener('load', renderGoogleButton);

async function navigateTo(url){
  setLoading(true);
  try {
    const r = await fetch(url, {headers:{'X-Requested-With':'fetch'}, credentials:'same-origin'});
    if (!r.ok) throw new Error('bad response');
    applySwap(await r.json());
  } catch (err) {
    window.location.href = url;
  }
  setLoading(false);
}

async function submitPost(form){
  if (!navigator.onLine) return; // إرسال بيانات (طلب، دفع، تسجيل...) يحتاج اتصالاً فعلياً؛ شعار الانقطاع أعلى الصفحة يوضّح السبب
  setLoading(true);
  try {
    const fd = new FormData(form);
    const actionName = fd.get('action');
    const r = await fetch('index.php', {method:'POST', body: fd, headers:{'X-Requested-With':'fetch'}, credentials:'same-origin'});
    if (!r.ok) throw new Error('bad response');
    applySwap(await r.json());
    if (['login', 'logout', 'register', 'google_login'].includes(actionName)) clearOfflineCache();
    setAuthUrl(actionName);
  } catch (err) {
    form.submit();
  }
  setLoading(false);
}

document.addEventListener('click', function(e){
  const a = e.target.closest('a[href]');
  if (!a || !a.closest('#app-root')) return;
  const href = a.getAttribute('href');
  if (!href) return;
  if (href.startsWith('#')) {
    e.preventDefault();
    document.querySelector(href)?.scrollIntoView({behavior:'smooth', block:'start'});
    return;
  }
  if (!href.startsWith('index.php') || a.target === '_blank') return;
  e.preventDefault();
  navigateTo(href);
});

document.addEventListener('submit', function(e){
  const form = e.target;
  if (!form.closest('#app-root')) return;
  e.preventDefault();
  if (form.id === 'aiChatForm') { handleAiChatSubmit(form); return; }
  if (form.id === 'checkoutForm') {
    if (!form.reportValidity()) return;
    window._pendingCheckoutForm = form;
    openSheet('checkoutConfirmSheet');
    return;
  }
  if ((form.getAttribute('method') || 'get').toLowerCase() === 'get') {
    const qs = new URLSearchParams(new FormData(form)).toString();
    navigateTo((form.getAttribute('action') || 'index.php') + '?' + qs);
  } else {
    submitPost(form);
  }
});
document.addEventListener('click', function(e){
  if (!e.target.closest('#checkoutConfirmBtn')) return;
  closeSheets();
  if (window._pendingCheckoutForm) submitPost(window._pendingCheckoutForm);
  window._pendingCheckoutForm = null;
});

async function handleAiChatSubmit(form){
  const inp = document.getElementById('aiInput');
  const msg = inp.value.trim();
  if (!msg) return;
  const box = document.getElementById('aiMsgs');
  box.insertAdjacentHTML('beforeend', '<div class="ai-msg ai-user"></div>');
  box.lastElementChild.textContent = msg;
  window._aiHistory = window._aiHistory || [];
  window._aiHistory.push({role:'user', content: msg});
  inp.value = '';
  box.scrollTop = box.scrollHeight;
  box.insertAdjacentHTML('beforeend', '<div class="ai-msg ai-bot" id="aiTyping">...</div>');
  box.scrollTop = box.scrollHeight;
  try {
    const r = await fetch('index.php?ajax=chat', {method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin', body: JSON.stringify({message: msg, history: window._aiHistory})});
    const data = await r.json();
    document.getElementById('aiTyping')?.remove();
    box.insertAdjacentHTML('beforeend', '<div class="ai-msg ai-bot"></div>');
    box.lastElementChild.textContent = data.reply;
    window._aiHistory.push({role:'assistant', content: data.reply});
    if (data.product) {
      const p = data.product;
      box.insertAdjacentHTML('beforeend', '<a href="index.php?page=product&id=' + encodeURIComponent(p.id) + '" class="ai-product-card"><div class="ai-product-img">' + (p.image ? '<img src="' + p.image + '" alt="">' : '<i class="fas fa-box"></i>') + '</div><div class="ai-product-info"><h5></h5><span></span></div><i class="fas fa-arrow-left"></i></a>');
      const cardEl = box.lastElementChild;
      cardEl.querySelector('h5').textContent = p.name;
      cardEl.querySelector('span').textContent = p.price;
    }
  } catch (err) {
    document.getElementById('aiTyping')?.remove();
    box.insertAdjacentHTML('beforeend', '<div class="ai-msg ai-bot">صار خطأ بالاتصال، حاول مرة ثانية.</div>');
  }
  box.scrollTop = box.scrollHeight;
}

function aiShow(view){
  document.getElementById('aiIntent')?.setAttribute('hidden','');
  document.getElementById('aiChatView')?.setAttribute('hidden','');
  document.getElementById('aiComplaintView')?.setAttribute('hidden','');
  document.getElementById(view)?.removeAttribute('hidden');
  if (view === 'aiChatView') document.getElementById('aiInput')?.focus();
}

function useMyLocation(){
  const status = document.getElementById('locStatus');
  if (!navigator.geolocation) { status.textContent = 'المتصفح ما يدعم تحديد الموقع'; return; }
  status.textContent = 'جاري التحديد...';
  navigator.geolocation.getCurrentPosition(function(pos){
    const lat = pos.coords.latitude, lng = pos.coords.longitude;
    document.getElementById('deliveryLocation').value = 'إحداثيات GPS: ' + lat.toFixed(6) + ', ' + lng.toFixed(6);
    status.textContent = 'جارٍ تحديد اسم الموقع...';
    fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat=' + lat + '&lon=' + lng + '&accept-language=ar&zoom=18')
      .then(r => r.json())
      .then(data => {
        if (data && data.display_name) {
          document.getElementById('deliveryLocation').value = data.display_name;
          status.textContent = 'تم تحديد موقعك ✅';
        } else {
          status.textContent = 'تم تحديد إحداثياتك، تعذّر إيجاد اسم للمكان';
        }
      })
      .catch(() => { status.textContent = 'تم تحديد إحداثياتك، تعذّر إيجاد اسم للمكان'; });
  }, function(){
    status.textContent = 'تعذّر تحديد الموقع، اكتبه يدوياً';
  });
}

/* ===== سحب لحذف الإشعارات ===== */
let _swEl = null, _swStartX = 0, _swCurX = 0;
document.addEventListener('pointerdown', function(e){
  const row = e.target.closest('.notif-row');
  if (!row || e.target.closest('button')) return;
  _swEl = row; _swStartX = e.clientX; _swCurX = 0;
  row.style.transition = 'none';
});
document.addEventListener('pointermove', function(e){
  if (!_swEl) return;
  _swCurX = e.clientX - _swStartX;
  if (_swCurX > 0) _swCurX = 0;
  _swEl.style.transform = `translateX(${_swCurX}px)`;
});
function _swEnd(){
  if (!_swEl) return;
  const el = _swEl, cur = _swCurX;
  el.style.transition = 'transform .2s';
  if (cur < -80) {
    el.style.transform = 'translateX(-110%)';
    const btn = el.querySelector('.notif-delete-trigger');
    setTimeout(() => btn?.click(), 180);
  } else {
    el.style.transform = '';
  }
  _swEl = null; _swCurX = 0;
}
document.addEventListener('pointerup', _swEnd);
document.addEventListener('pointercancel', _swEnd);

function toggleTheme(){
  const cur=document.documentElement.getAttribute('data-theme');
  const next=cur==='dark'?null:'dark';
  if(next)document.documentElement.setAttribute('data-theme',next);else document.documentElement.removeAttribute('data-theme');
  try{localStorage.setItem('mk_theme',next||'')}catch(e){}
}
(function(){try{if(localStorage.getItem('mk_theme')==='dark')document.documentElement.setAttribute('data-theme','dark')}catch(e){}})();


document.addEventListener('click', function(e){
  const btn = e.target.closest('.rate-stars .rs');
  if (!btn) return;
  const v = parseInt(btn.dataset.v, 10);
  const wrap = btn.closest('.rate-stars');
  wrap.querySelectorAll('.rs').forEach(function(b){
    const active = parseInt(b.dataset.v, 10) <= v;
    b.querySelector('i').className = active ? 'fas fa-star' : 'far fa-star';
  });
  wrap.closest('form').querySelector('.rate-input-val').value = v;
});

function openLightbox(src){
  let lb=document.querySelector('.lightbox');
  if(!lb){lb=document.createElement('div');lb.className='lightbox';lb.innerHTML='<img>';lb.onclick=()=>lb.classList.remove('open');document.body.appendChild(lb);}
  lb.querySelector('img').src=src;
  lb.classList.add('open');
}

function swapMain(src,el){
  document.getElementById('mainImg').src=src;
  document.querySelectorAll('.gallery-thumbs img').forEach(t=>t.classList.remove('active'));
  el.classList.add('active');
}

function qtyChange(delta){
  const inp=document.getElementById('qtyInput');
  let v=parseInt(inp.value||'1')+delta;
  if(v<1)v=1;
  inp.value=v;
}

function openSheet(id){document.getElementById(id).classList.add('open');document.getElementById('sheetBackdrop').classList.add('open');}
function closeSheets(){document.querySelectorAll('.confirm-sheet, .admin-sidebar').forEach(s=>s.classList.remove('open'));document.getElementById('sheetBackdrop')?.classList.remove('open');}

document.addEventListener('click', function(e){
  const btn = e.target.closest('.pw-toggle');
  if (!btn) return;
  const input = btn.previousElementSibling;
  if (!input || input.tagName !== 'INPUT') return;
  const showing = input.type === 'text';
  input.type = showing ? 'password' : 'text';
  btn.querySelector('i').className = 'fas ' + (showing ? 'fa-eye-slash' : 'fa-eye');
});

function previewTheme(){
  const color=document.getElementById('pv_color')?.value;
  const radiusSel=document.getElementById('pv_radius')?.value;
  const radiusMap={sharp:'6px',rounded:'16px',pill:'28px'};
  const pv=document.getElementById('themePreview');
  if(!pv)return;
  pv.style.setProperty('--pv-color',color);
  pv.style.setProperty('--pv-radius',radiusMap[radiusSel]||'16px');
}
