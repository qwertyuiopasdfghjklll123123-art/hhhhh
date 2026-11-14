/* واصل — واجهة لوحة التحكم (صفحة واحدة). البيانات من index.php?api=... */
'use strict';

const W = window.WASEL || {};
const IS_ADMIN = !!W.isAdmin;
const SITE_NAME = W.siteName || 'واصل';
const LOGO_URL = W.logoUrl || null;
const ME = W.user || { name: '', email: '' };
const DEFAULT_COUNTRY_CODE = W.defaultCountryCode || '964';

/* ====================================================================== أدوات عامة */

function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

const ICONS = {
  home: 'fas fa-house', accounts: 'fab fa-whatsapp', campaigns: 'fas fa-bullhorn', analytics: 'fas fa-chart-column',
  admin: 'fas fa-shield-halved', settings: 'fas fa-gear', notifications: 'fas fa-bell', branches: 'fas fa-code-branch',
  plus: 'fas fa-plus', users: 'fas fa-users', rocket: 'fas fa-rocket', trophy: 'fas fa-trophy', list: 'fas fa-list',
  info: 'fas fa-circle-info', clock: 'fas fa-clock', check: 'fas fa-check', checkCircle: 'fas fa-circle-check',
  warn: 'fas fa-triangle-exclamation', trash: 'fas fa-trash', link: 'fas fa-link', calendar: 'fas fa-calendar-days',
  ban: 'fas fa-ban', copy: 'fas fa-copy', image: 'fas fa-image', download: 'fas fa-download', key: 'fas fa-key',
  plug: 'fas fa-plug', chrome: 'fab fa-chrome', back: 'fas fa-arrow-right', globe: 'fas fa-globe', file: 'fas fa-file-excel',
  phone: 'fas fa-phone', message: 'fas fa-comment-dots', hourglass: 'fas fa-hourglass-half', lock: 'fas fa-lock',
  chevron: 'fas fa-chevron-left', refresh: 'fas fa-rotate', send: 'fas fa-paper-plane', user: 'fas fa-user',
};

function icon(name, size) {
  size = size || 16;
  return '<i class="icon ' + (ICONS[name] || 'fas fa-circle') + '" style="font-size:' + size + 'px;width:' + (size + 2) + 'px;text-align:center"></i>';
}

function fmtNum(n) { return Number(n || 0).toLocaleString('en-US'); }

function agoText(seconds) {
  if (seconds == null) return 'لم يتصل بعد';
  if (seconds < 60) return 'قبل ' + Math.max(1, seconds) + ' ثانية';
  if (seconds < 3600) return 'قبل ' + Math.round(seconds / 60) + ' دقيقة';
  if (seconds < 86400) return 'قبل ' + Math.round(seconds / 3600) + ' ساعة';
  return 'قبل ' + Math.round(seconds / 86400) + ' يوم';
}

let toastTimer = null;
function toast(msg, isError) {
  const el = document.getElementById('toast');
  el.textContent = msg;
  el.className = 'toast show' + (isError ? ' error' : '');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { el.className = 'toast' + (isError ? ' error' : ''); }, 3200);
}

/** طلب للسيرفر — يرجّع دائماً كائن فيه ok. */
async function api(action, opts) {
  opts = opts || {};
  let url = 'index.php?api=' + encodeURIComponent(action);
  if (opts.query) url += '&' + new URLSearchParams(opts.query).toString();
  const init = { method: opts.form || opts.body ? 'POST' : 'GET', headers: { 'X-CSRF-Token': W.csrf }, credentials: 'same-origin', cache: 'no-store' };
  if (opts.form) init.body = opts.form;
  else if (opts.body) { init.headers['Content-Type'] = 'application/json'; init.body = JSON.stringify(opts.body); }
  let res;
  try { res = await fetch(url, init); } catch (e) { return { ok: false, error: 'تعذّر الاتصال بالسيرفر — تأكد من الإنترنت', network: true }; }
  if (res.status === 401) { location.reload(); return { ok: false, error: 'انتهت الجلسة' }; }
  try { return await res.json(); } catch (e) { return { ok: false, error: 'رد غير متوقع من السيرفر (' + res.status + ')' }; }
}

function avatarHtml(name, seed) {
  seed = String(seed || name || '');
  let h = 0;
  for (let i = 0; i < seed.length; i++) h = (h * 31 + seed.charCodeAt(i)) >>> 0;
  const letter = (String(name || '؟').trim().charAt(0) || '؟').toUpperCase();
  return '<div class="avatar avatar-' + (h % 5) + '">' + esc(letter) + '</div>';
}

function setMsg(id, text, kind) {
  const el = document.getElementById(id);
  if (!el) return;
  el.innerHTML = text;
  el.className = 'form-msg' + (kind ? ' ' + kind : '');
}

async function copyText(text) {
  try { await navigator.clipboard.writeText(text); toast('تم النسخ'); }
  catch (e) {
    const ta = document.createElement('textarea');
    ta.value = text; document.body.appendChild(ta); ta.select();
    try { document.execCommand('copy'); toast('تم النسخ'); } catch (e2) { toast('انسخه يدوياً', true); }
    ta.remove();
  }
}

/* ====================================================================== الهيكل والتنقل */

const SECTION_LABELS = {
  home: 'الرئيسية', accounts: 'حسابي', campaigns: 'الحملات', analytics: 'إحصائيات', branches: 'الفروع',
  admin: 'لوحة التحكم', notifications: 'الإشعارات', settings: 'الإعدادات',
};
const NAV = IS_ADMIN
  ? ['home', 'accounts', 'campaigns', 'analytics', 'branches', 'admin', 'notifications']
  : ['accounts', 'campaigns', 'notifications'];
const SECTIONS = IS_ADMIN ? NAV.concat(['settings']) : NAV;

function initChrome() {
  let navHtml = NAV.map(s =>
    '<button class="nav-item" data-s="' + s + '" onclick="showSection(\'' + s + '\')">' + icon(s, 16) + '<span>' + SECTION_LABELS[s] + '</span>' +
    (s === 'notifications' ? '<span class="nav-count" id="navNotifCount"></span>' : '') + '</button>'
  ).join('');
  if (IS_ADMIN) {
    navHtml += '<div class="nav-divider"></div>' +
      '<button class="nav-item" data-s="settings" onclick="showSection(\'settings\')">' + icon('settings', 16) + '<span>' + SECTION_LABELS.settings + '</span></button>';
  }
  document.getElementById('navMenu').innerHTML = navHtml;

  const displayName = ME.name || String(ME.email || '').split('@')[0];
  document.getElementById('sidebarAvatar').textContent = (displayName || '؟').trim().charAt(0).toUpperCase();
  document.getElementById('sidebarUserName').textContent = displayName;
  document.getElementById('sidebarUserRole').textContent = IS_ADMIN ? 'أدمن المنصة' : 'حساب فرع';
  document.getElementById('brandName').textContent = SITE_NAME;
  document.getElementById('pwaBannerText').textContent = 'ثبّت تطبيق ' + SITE_NAME + ' على جهازك للوصول السريع';
  const brandLogo = document.getElementById('brandLogo');
  if (LOGO_URL) {
    brandLogo.innerHTML = '<img src="' + esc(LOGO_URL) + '" alt="">';
  } else {
    brandLogo.textContent = (SITE_NAME || 'و').trim().charAt(0);
  }
}

function toggleSidebar() {
  document.getElementById('sidebar').classList.toggle('open');
  document.getElementById('sidebarOverlay').classList.toggle('show');
}
function closeSidebar() {
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('sidebarOverlay').classList.remove('show');
}

let section = null;
let gen = 0;
let pollTimer = null;
let accounts = [];
let accountsLoaded = false;
let activeAccountId = Number(localStorageGet('wasel.account')) || null;
const dataCache = {};

function localStorageGet(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
function localStorageSet(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* وضع التصفح الخاص */ } }

function showSection(s, opts) {
  if (SECTIONS.indexOf(s) === -1) s = NAV[0];
  section = s;
  document.querySelectorAll('.sidebar .nav-item').forEach(n => n.classList.toggle('active', n.dataset.s === s));
  document.getElementById('headerTitle').textContent = SECTION_LABELS[s] || '';
  if (!opts || !opts.keepHash) history.replaceState(null, '', '#' + s);
  closeSidebar();
  render();
}

/** يبدأ جيل عرض جديد: يلغي التحديثات الدورية للقسم السابق. */
function newGen() {
  gen++;
  clearTimeout(pollTimer);
  pollTimer = null;
  return gen;
}

function every(myGen, ms, fn) {
  clearTimeout(pollTimer);
  pollTimer = setTimeout(async function tick() {
    if (myGen !== gen) return;
    if (!document.hidden) {
      try { await fn(); } catch (e) { /* نكمل التحديث الدوري حتى لو فشلت دورة وحدة */ }
    }
    if (myGen === gen) pollTimer = setTimeout(tick, ms);
  }, ms);
}

function fadeContent() {
  const c = document.getElementById('content');
  c.classList.remove('fade-in');
  void c.offsetWidth;
  c.classList.add('fade-in');
}

function content() { return document.getElementById('content'); }

function render() {
  const myGen = newGen();
  const fn = {
    home: renderHome, accounts: renderAccounts, campaigns: renderCampaigns, analytics: renderAnalytics,
    branches: renderBranches, admin: renderAdmin, notifications: renderNotifications, settings: renderSettings,
  }[section] || renderAccounts;
  fn(myGen);
  fadeContent();
  window.scrollTo(0, 0);
}

async function loadAccounts() {
  const r = await api('accounts');
  if (r.ok) {
    accounts = r.items;
    accountsLoaded = true;
    if (!accounts.find(a => a.id === activeAccountId)) activeAccountId = accounts.length ? accounts[0].id : null;
  }
  return r;
}

/* ====================================================================== PWA والإشعارات */

let deferredInstallPrompt = null;
window.addEventListener('beforeinstallprompt', function (e) {
  e.preventDefault();
  deferredInstallPrompt = e;
  if (localStorageGet('wasel.pwaDismissed') === '1') return;
  document.body.classList.add('pwa-banner-visible');
  document.getElementById('pwaInstallBanner').classList.add('show');
});
window.addEventListener('appinstalled', dismissPwaBanner);
async function installPwa() {
  if (!deferredInstallPrompt) { dismissPwaBanner(); return; }
  deferredInstallPrompt.prompt();
  try { await deferredInstallPrompt.userChoice; } catch (e) { /* تجاهل */ }
  deferredInstallPrompt = null;
  dismissPwaBanner();
}
function dismissPwaBanner() {
  localStorageSet('wasel.pwaDismissed', '1');
  document.body.classList.remove('pwa-banner-visible');
  document.getElementById('pwaInstallBanner').classList.remove('show');
}

if ('serviceWorker' in navigator) { navigator.serviceWorker.register('sw.js').catch(() => {}); }

function requestNotifPermission() {
  if (!('Notification' in window)) { toast('متصفحك ما يدعم الإشعارات', true); return; }
  Notification.requestPermission().then(() => { if (section === 'notifications') render(); });
}
function showLocalNotification(title, body) {
  if (!('Notification' in window) || Notification.permission !== 'granted') return;
  const opts = { body: body, icon: LOGO_URL || 'assets/icon-192.png', lang: 'ar', dir: 'rtl' };
  if (navigator.serviceWorker && navigator.serviceWorker.ready) {
    navigator.serviceWorker.ready.then(reg => reg.showNotification(title, opts)).catch(() => { try { new Notification(title, opts); } catch (e) { /* */ } });
  } else {
    try { new Notification(title, opts); } catch (e) { /* */ }
  }
}

let lastSeenEventId = null;
let eventsCache = null;
let eventsTimer = null;

function updateBadges(unread) {
  document.getElementById('bellBadge').style.display = unread > 0 ? 'block' : 'none';
  const c = document.getElementById('navNotifCount');
  if (c) { c.textContent = unread > 99 ? '99+' : String(unread); c.classList.toggle('show', unread > 0); }
}

async function pollEvents() {
  clearTimeout(eventsTimer);
  const r = await api('events');
  if (r.ok) {
    const maxId = r.items.length ? Math.max.apply(null, r.items.map(e => e.id)) : 0;
    if (lastSeenEventId === null) {
      lastSeenEventId = maxId;
    } else if (maxId > lastSeenEventId) {
      r.items.filter(e => e.id > lastSeenEventId).reverse()
        .forEach(e => showLocalNotification(e.title, (e.account ? e.account + ': ' : '') + e.body));
      lastSeenEventId = maxId;
    }
    eventsCache = r.items;
    updateBadges(r.unread);
    if (section === 'notifications') renderNotifList();
  }
  eventsTimer = setTimeout(pollEvents, document.hidden ? 60000 : 15000);
}
document.addEventListener('visibilitychange', () => { if (!document.hidden) pollEvents(); });

/* ====================================================================== الرئيسية (الأدمن) */

function statMiniHtml(value, label, colorClass) {
  return '<div class="stat-mini-box"><div class="num' + (colorClass ? ' ' + colorClass : '') + '">' + value + '</div><div class="label">' + label + '</div></div>';
}

function homeStatsHtml(d) {
  return statMiniHtml(fmtNum(d.messages_sent), 'رسائل مرسلة', 'primary') +
    statMiniHtml(d.accounts_connected + '/' + d.accounts_total, 'حسابات متصلة', 'green') +
    statMiniHtml(fmtNum(d.campaigns_active), 'حملات نشطة', 'orange') +
    statMiniHtml(d.success_rate + '%', 'معدل النجاح', 'primary');
}

function renderHome(myGen) {
  const displayName = ME.name || String(ME.email || '').split('@')[0];
  const heroIcon = LOGO_URL ? '<img src="' + esc(LOGO_URL) + '" alt="">' : icon('rocket', 28);
  content().innerHTML =
    '<div class="page-title"><h2>' + icon('home', 18) + ' الرئيسية</h2></div>' +
    '<div class="hero-main"><div class="relative-z">' +
      '<div class="hero-icon-main">' + heroIcon + '</div>' +
      '<h1>' + esc(SITE_NAME) + '</h1>' +
      '<p>أتمتة حملاتك التسويقية عبر واتساب — أرسل رسائل لعملائك بضغطة واحدة من كل فروعك.</p>' +
      '<div class="hero-badges"><span>' + icon('campaigns', 11) + ' إرسال جماعي</span><span>' + icon('calendar', 11) + ' جدولة على أيام</span><span>' + icon('branches', 11) + ' فروع</span></div>' +
    '</div></div>' +
    '<h3 class="welcome-title">مرحباً، ' + esc(displayName) + '</h3>' +
    '<p class="muted-sm">نظرة عامة على نشاط المنصة (كل الفروع)</p>' +
    '<div class="stats-mini-row" id="homeStats">' + (dataCache.dashboard ? homeStatsHtml(dataCache.dashboard) : '<div class="loading-line">جارِ التحميل...</div>') + '</div>' +
    '<div class="quick-actions">' +
      '<button class="quick-action-btn" onclick="showSection(\'campaigns\')">' + icon('campaigns', 20) + 'حملة جديدة</button>' +
      '<button class="quick-action-btn" onclick="showSection(\'accounts\')">' + icon('accounts', 20) + 'حساباتي</button>' +
      '<button class="quick-action-btn" onclick="showSection(\'branches\')">' + icon('branches', 20) + 'الفروع</button>' +
      '<button class="quick-action-btn" onclick="showSection(\'analytics\')">' + icon('analytics', 20) + 'إحصائيات</button>' +
    '</div>' +
    '<div class="card"><div class="card-header"><h4>' + icon('accounts', 15) + ' حساباتي</h4>' +
      '<button class="btn-outline btn-small" onclick="openAddAccountSheet()">' + icon('plus', 10) + ' إضافة</button></div>' +
      '<div id="homeAccounts"><div class="loading-line">جارِ التحميل...</div></div></div>' +
    '<div class="card"><div class="card-header"><h4>' + icon('clock', 15) + ' آخر الحملات بالمنصة</h4>' +
      '<span class="muted-sm" style="cursor:pointer" onclick="showSection(\'analytics\')">عرض الكل</span></div>' +
      '<div id="homeHistory">' + (dataCache.recentAll ? campaignRowsHtml(dataCache.recentAll.slice(0, 6), true) : '<div class="loading-line">جارِ التحميل...</div>') + '</div></div>';

  const load = async () => {
    const [d, rc, ra] = await Promise.all([api('dashboard'), api('campaigns', { query: { scope: 'all', limit: 6 } }), loadAccounts()]);
    if (myGen !== gen) return;
    if (d.ok) { dataCache.dashboard = d; document.getElementById('homeStats').innerHTML = homeStatsHtml(d); }
    if (rc.ok) { dataCache.recentAll = rc.items; document.getElementById('homeHistory').innerHTML = campaignRowsHtml(rc.items, true); }
    if (ra.ok) {
      document.getElementById('homeAccounts').innerHTML = accounts.length
        ? accounts.slice(0, 4).map(a =>
            '<div class="account-item">' + avatarHtml(a.name, a.id) +
            '<div class="info"><div class="name">' + esc(a.name) + '</div><div class="status">' + esc(agoText(a.last_seen_ago)) + '</div></div>' +
            statePill(a.state) + '</div>').join('') +
          (accounts.length > 4 ? '<button class="btn-outline" onclick="showSection(\'accounts\')">عرض كل الحسابات</button>' : '')
        : '<div class="muted-sm center" style="padding:16px 0">ما عندك حسابات واتساب بعد. <a href="#accounts" style="color:var(--primary);font-weight:700" onclick="showSection(\'accounts\');return false">أضف حساب</a></div>';
    }
  };
  load();
  every(myGen, 20000, load);
}

/* ====================================================================== حسابي (حسابات واتساب + جهاز الإرسال) */

const STATE_INFO = {
  online: { pill: 'pill-green', label: 'متصل', banner: 'ok', icon: 'checkCircle', text: 'جهاز الإرسال متصل وواتساب ويب جاهز — الحملات تنرسل تلقائياً.' },
  qr: { pill: 'pill-amber', label: 'يحتاج مسح QR', banner: 'warn', icon: 'warn', text: 'الإضافة شغّالة بس واتساب ويب مو مسجّل دخول. افتح تبويب واتساب ويب على جهاز الإرسال وامسح الرمز من جوالك (واتساب ← الأجهزة المرتبطة ← ربط جهاز).' },
  conflict: { pill: 'pill-amber', label: 'مفتوح بمكان ثاني', banner: 'warn', icon: 'warn', text: 'واتساب ويب مفتوح بنافذة أو تبويب ثاني. بتبويب واتساب اللي فتحته الإضافة اضغط "استخدام هنا".' },
  no_tab: { pill: 'pill-amber', label: 'تبويب واتساب مسكّر', banner: 'warn', icon: 'warn', text: 'كروم شغّال بس تبويب واتساب ويب مسكّر — اضغط أيقونة واصل بشريط الإضافات ثم "فتح واتساب ويب".' },
  paused: { pill: 'pill-gray', label: 'متوقف مؤقتاً', banner: 'info', icon: 'info', text: 'الإرسال موقوف مؤقتاً من زر الإضافة على جهاز الإرسال. الرسائل تنتظر لحد ما يرجع يشتغل.' },
  loading: { pill: 'pill-blue', label: 'جارِ التحميل', banner: 'info', icon: 'hourglass', text: 'واتساب ويب جارِ التحميل على جهاز الإرسال...' },
  offline: { pill: 'pill-red', label: 'غير متصل', banner: 'bad', icon: 'plug', text: 'جهاز الإرسال غير متصل. تأكد إن كمبيوتر الفرع شغّال وكروم مفتوح. الرسائل بتنتظر وتنرسل أول ما يرجع يتصل.' },
  new: { pill: 'pill-blue', label: 'بانتظار الربط', banner: 'info', icon: 'chrome', text: 'ثبّت إضافة الإرسال على كمبيوتر الفرع حتى يبدأ الإرسال — الخطوات تحت.' },
};

function statePill(state) {
  const s = STATE_INFO[state] || STATE_INFO.offline;
  return '<span class="pill ' + s.pill + '">' + s.label + '</span>';
}

const expandedSetup = new Set();

function setupStepsHtml(acc) {
  return '<div class="setup-box">' +
    '<div class="setup-title">' + icon('chrome', 15) + ' تثبيت إضافة الإرسال على كمبيوتر الفرع (مرة وحدة)</div>' +
    '<div class="step-item"><div class="step-num">1</div><div class="step-text">حمّل إضافة الإرسال الخاصة بهذا الحساب' +
      '<small>ملف zip فيه الإضافة جاهزة ومربوطة تلقائياً بحساب "' + esc(acc.name) + '".</small>' +
      '<a class="btn-primary-small mt-2" href="index.php?download=extension&account=' + acc.id + '&t=' + encodeURIComponent(W.csrf) + '" onclick="return confirmDownload(' + acc.id + ')">' + icon('download', 13) + ' تحميل الإضافة</a></div></div>' +
    '<div class="step-item"><div class="step-num">2</div><div class="step-text">فك الضغط وثبّتها بكروم' +
      '<small>افتح Google Chrome واكتب بشريط العنوان <span class="kbd">chrome://extensions</span> ثم فعّل "وضع المطوّر" <span class="en">Developer mode</span> واضغط "تحميل إضافة غير مضغوطة" <span class="en">Load unpacked</span> واختر مجلد <span class="kbd">wasel-sender</span>.</small></div></div>' +
    '<div class="step-item"><div class="step-num">3</div><div class="step-text">امسح رمز QR بواتساب ويب' +
      '<small>بينفتح تبويب واتساب ويب تلقائياً. من جوالك: واتساب ← الأجهزة المرتبطة ← ربط جهاز، وامسح الرمز.</small></div></div>' +
    '<div class="step-item"><div class="step-num">4</div><div class="step-text">خلّي كروم مفتوح' +
      '<small>الحالة هنا بتصير "متصل" خلال دقيقة، وأي حملة لهذا الحساب تنرسل تلقائياً من هالجهاز.</small></div></div>' +
  '</div>';
}

function accountCardHtml(acc) {
  const s = STATE_INFO[acc.state] || STATE_INFO.offline;
  const showSetup = acc.state === 'new' || expandedSetup.has(acc.id);
  let bannerText = s.text;
  if (acc.state === 'offline') bannerText = 'جهاز الإرسال غير متصل (آخر اتصال ' + agoText(acc.last_seen_ago) + '). ' + s.text.replace('جهاز الإرسال غير متصل. ', '');
  return '<div class="card" id="acc-' + acc.id + '">' +
    '<div class="row">' + avatarHtml(acc.name, acc.id) + '<span class="account-name">' + esc(acc.name) + '</span>' + statePill(acc.state) + '</div>' +
    '<div class="acc-stats">' +
      '<div><b>' + fmtNum(acc.pending) + '</b><span>بانتظار الإرسال</span></div>' +
      '<div><b>' + fmtNum(acc.sent_today) + '</b><span>أُرسلت اليوم</span></div>' +
      '<div><b style="font-size:12.5px;line-height:1.9">' + esc(agoText(acc.last_seen_ago)) + '</b><span>آخر اتصال للجهاز</span></div>' +
    '</div>' +
    '<div class="state-banner ' + s.banner + '">' + icon(s.icon, 14) + '<span>' + esc(bannerText) + '</span></div>' +
    (showSetup ? setupStepsHtml(acc) : '') +
    '<div class="btn-row">' +
      (acc.state === 'new' ? '' : '<button class="btn-outline" onclick="toggleSetup(' + acc.id + ')">' + icon('chrome', 12) + (showSetup ? ' إخفاء طريقة التثبيت' : ' طريقة التثبيت / تحميل الإضافة') + '</button>') +
      '<button class="btn-outline" onclick="showPairCode(' + acc.id + ')">' + icon('key', 12) + ' رمز الربط اليدوي</button>' +
      '<button class="btn-outline danger" onclick="deleteAccount(' + acc.id + ')">' + icon('trash', 12) + ' حذف الحساب</button>' +
    '</div>' +
    (acc.agent_version ? '<div class="muted-sm mt-2">نسخة الإضافة: ' + esc(acc.agent_version) + '</div>' : '') +
  '</div>';
}

function renderAccountsList() {
  const box = document.getElementById('accountsBox');
  if (!box) return;
  if (!accounts.length) {
    box.innerHTML = '<div class="card info"><div class="empty-state" style="padding:20px 10px">' + icon('accounts', 34) +
      'ما عندك حسابات واتساب بعد.<br>أضف حساب، ثم ثبّت إضافة الإرسال على كمبيوتر الفرع — وبعدها كل حملاتك تنرسل منه تلقائياً.' +
      '<br><button class="btn-primary-small mt-3" onclick="openAddAccountSheet()">' + icon('plus', 12) + ' إضافة حساب</button></div></div>';
    return;
  }
  box.innerHTML = accounts.map(accountCardHtml).join('');
}

function renderAccounts(myGen) {
  content().innerHTML =
    '<div class="page-title"><h2>' + icon('accounts', 18) + ' حسابي</h2>' +
      '<button class="btn-primary-small" onclick="openAddAccountSheet()">' + icon('plus', 12) + ' إضافة حساب</button></div>' +
    '<p class="muted-sm mb-3">كل حساب واتساب يرسل حملاته من كمبيوتر الفرع عبر إضافة كروم صغيرة ومجانية — بدون أي اشتراك أو سيرفر خاص.</p>' +
    '<div id="accountsBox">' + (accountsLoaded ? '' : '<div class="loading-line">جارِ التحميل...</div>') + '</div>';
  if (accountsLoaded) renderAccountsList();
  const load = async () => {
    const r = await loadAccounts();
    if (myGen !== gen) return;
    if (r.ok) renderAccountsList();
    else if (!accountsLoaded) document.getElementById('accountsBox').innerHTML = '<div class="state-banner bad">' + esc(r.error) + '</div>';
  };
  load();
  every(myGen, 8000, load);
}

function toggleSetup(id) {
  if (expandedSetup.has(id)) expandedSetup.delete(id); else expandedSetup.add(id);
  renderAccountsList();
}

function confirmDownload(id) {
  const acc = accounts.find(a => a.id === id);
  if (acc && acc.state !== 'new' && acc.paired) {
    return confirm('تحميل نسخة جديدة من الإضافة يوقف النسخة المثبّتة حالياً لهذا الحساب (لازم تثبّت الجديدة بدالها). تكمل؟');
  }
  return true;
}

function openAddAccountSheet() {
  document.getElementById('newAccountName').value = '';
  document.getElementById('addAccountOverlay').classList.add('show');
  document.getElementById('addAccountSheet').classList.add('show');
  setTimeout(() => document.getElementById('newAccountName').focus(), 100);
}
function closeAddAccountSheet() {
  document.getElementById('addAccountOverlay').classList.remove('show');
  document.getElementById('addAccountSheet').classList.remove('show');
}
async function submitAddAccount() {
  const name = document.getElementById('newAccountName').value.trim();
  closeAddAccountSheet();
  const r = await api('account_create', { body: { name: name } });
  if (!r.ok) { toast(r.error, true); return; }
  activeAccountId = r.account.id;
  localStorageSet('wasel.account', String(activeAccountId));
  await loadAccounts();
  toast('تمت إضافة الحساب — ثبّت إضافة الإرسال حتى يتصل');
  if (section !== 'accounts') showSection('accounts'); else render();
}

async function deleteAccount(id) {
  const acc = accounts.find(a => a.id === id);
  if (!confirm('حذف حساب "' + (acc ? acc.name : '') + '"؟ بتنحذف كل حملاته وسجلّه نهائياً، والإضافة المربوطة فيه بتتوقف.')) return;
  const r = await api('account_delete', { body: { id: id } });
  if (!r.ok) { toast(r.error, true); return; }
  toast('تم حذف الحساب');
  await loadAccounts();
  render();
}

function openInfoSheet(html) {
  document.getElementById('infoSheetBody').innerHTML = html;
  document.getElementById('infoOverlay').classList.add('show');
  document.getElementById('infoSheet').classList.add('show');
}
function closeInfoSheet() {
  document.getElementById('infoOverlay').classList.remove('show');
  document.getElementById('infoSheet').classList.remove('show');
}

let lastPairCode = '';
async function showPairCode(id) {
  const acc = accounts.find(a => a.id === id);
  if (acc && acc.paired && !confirm('توليد رمز ربط جديد يوقف أي إضافة مربوطة حالياً بهذا الحساب. تكمل؟')) return;
  const r = await api('account_pair_code', { body: { id: id } });
  if (!r.ok) { toast(r.error, true); return; }
  lastPairCode = r.code;
  openInfoSheet(
    '<div class="sheet-title">' + icon('key', 16) + ' رمز الربط اليدوي</div>' +
    '<div class="sheet-message">استخدمه إذا ثبّتت الإضافة من مجلد extension مباشرة، أو تريد تنقل الإضافة لحساب ثاني: اضغط أيقونة واصل بكروم ← "ربط برمز" ← الصق الرمز.</div>' +
    '<div class="code-box">' + esc(r.code) + '</div>' +
    '<div class="sheet-actions mt-3">' +
      '<button class="btn-cancel" onclick="closeInfoSheet()">إغلاق</button>' +
      '<button class="btn-confirm-gold" onclick="copyText(lastPairCode)">' + icon('copy', 13) + ' نسخ الرمز</button>' +
    '</div>'
  );
  loadAccounts().then(() => { if (section === 'accounts') renderAccountsList(); });
}

/* ====================================================================== الحملات */

const COUNTRY_CODES = [
  ['964', 'العراق (964+)'], ['966', 'السعودية (966+)'], ['971', 'الإمارات (971+)'], ['965', 'الكويت (965+)'],
  ['974', 'قطر (974+)'], ['973', 'البحرين (973+)'], ['968', 'عُمان (968+)'], ['962', 'الأردن (962+)'],
  ['961', 'لبنان (961+)'], ['963', 'سوريا (963+)'], ['970', 'فلسطين (970+)'], ['967', 'اليمن (967+)'],
  ['20', 'مصر (20+)'], ['212', 'المغرب (212+)'], ['213', 'الجزائر (213+)'], ['216', 'تونس (216+)'],
  ['218', 'ليبيا (218+)'], ['249', 'السودان (249+)'], ['90', 'تركيا (90+)'], ['98', 'إيران (98+)'],
  ['1', 'أمريكا وكندا (1+)'], ['44', 'بريطانيا (44+)'],
];

function countryCodeSelectHtml() {
  const saved = localStorageGet('wasel.cc') || DEFAULT_COUNTRY_CODE;
  return '<select id="countryCode" onchange="localStorageSet(\'wasel.cc\', this.value); updateNumbersCount()">' +
    COUNTRY_CODES.map(([code, label]) => '<option value="' + code + '"' + (code === saved ? ' selected' : '') + '>' + label + '</option>').join('') +
    '</select>';
}

/** نفس منطق السيرفر تقريباً — عدّ تقديري للأرقام المكتوبة بالحقل. */
function estimateNumbers(text, cc) {
  const ar = '٠١٢٣٤٥٦٧٨٩', fa = '۰۱۲۳۴۵۶۷۸۹';
  text = String(text || '').replace(/[٠-٩]/g, d => ar.indexOf(d)).replace(/[۰-۹]/g, d => fa.indexOf(d));
  const set = new Set();
  const add = raw => {
    let intl = /^[^0-9]*(\+|00)/.test(raw);
    let digits = raw.replace(/\D+/g, '');
    if (!digits) return false;
    if (digits.startsWith('00')) { digits = digits.slice(2); intl = true; }
    if (!intl && cc && !digits.startsWith(cc)) digits = cc + digits.replace(/^0+/, '');
    if (digits.length < 8 || digits.length > 15) return false;
    if (!intl && cc && digits.length - cc.length < 7) return false;
    set.add(digits);
    return true;
  };
  text.split(/[,\n\r;،\t]+/).forEach(part => {
    const d = part.replace(/\D+/g, '');
    if (!d) return;
    if (!add(part) && d.length > 15) part.trim().split(/\s+/).forEach(p => add(p));
  });
  return set.size;
}

function updateNumbersCount() {
  const ta = document.getElementById('numbersText');
  const out = document.getElementById('numbersCount');
  if (!ta || !out) return;
  const n = estimateNumbers(ta.value, document.getElementById('countryCode').value);
  out.textContent = n ? 'تم التعرّف على ' + fmtNum(n) + ' رقم' : '';
  updateSplitSummary();
}

function updateMessagePreview() {
  const box = document.getElementById('msgPreview');
  if (!box) return;
  const text = document.getElementById('text').value.trim() || (W.defaultMessage || '');
  const link = document.getElementById('link').value.trim();
  box.textContent = link ? text + '\n' + (/^[a-z][a-z0-9+.\-]*:\/\//i.test(link) ? link : 'https://' + link) : text;
}

function updateSplitSummary() {
  const out = document.getElementById('splitSummary');
  if (!out) return;
  const mode = document.getElementById('splitMode').value;
  const n = estimateNumbers(document.getElementById('numbersText').value, document.getElementById('countryCode').value);
  const v = parseInt(document.getElementById('splitValue').value, 10);
  const time = document.getElementById('sendTime').value || '10:00';
  if (mode === 'none' || !n || !v || v < 1) {
    out.textContent = 'مثال: 80 رقم مع "عدد ثابت كل يوم" = 20 ← تنرسل 20 رسالة يومياً على 4 أيام تلقائياً.';
    return;
  }
  const perDay = mode === 'count' ? v : Math.ceil(n / v);
  const days = Math.ceil(n / perDay);
  out.textContent = fmtNum(n) + ' رقم ← ' + days + ' يوم × ' + perDay + ' رقم باليوم، الساعة ' + time + ' (أرقام ملف Excel تنضاف للحساب عند الإرسال).';
}

function onSplitModeChange() {
  const mode = document.getElementById('splitMode').value;
  document.getElementById('singleScheduleBox').style.display = mode === 'none' ? 'block' : 'none';
  document.getElementById('splitBox').style.display = mode === 'none' ? 'none' : 'block';
  document.getElementById('splitValueLabel').textContent = mode === 'days' ? 'عدد الأيام المطلوب التوزيع عليها' : 'عدد الأرقام باليوم الواحد';
  document.getElementById('splitValue').placeholder = mode === 'days' ? 'مثال: 4' : 'مثال: 20';
  updateSplitSummary();
}

function accountOptionLabel(a) {
  const s = STATE_INFO[a.state] || STATE_INFO.offline;
  return a.name + ' — ' + s.label;
}

function renderCampaigns(myGen) {
  const c = content();
  c.innerHTML = '<div class="page-title"><h2>' + icon('campaigns', 18) + ' الحملات</h2></div><div class="loading-line">جارِ التحميل...</div>';
  const draw = () => {
    if (myGen !== gen) return;
    if (!accounts.length) {
      c.innerHTML = '<div class="page-title"><h2>' + icon('campaigns', 18) + ' الحملات</h2></div>' +
        '<div class="card info"><div class="empty-state" style="padding:20px 10px">' + icon('accounts', 34) +
        'تحتاج حساب واتساب واحد على الأقل قبل ما تبدأ حملة.<br><button class="btn-primary-small mt-3" onclick="showSection(\'accounts\')">' + icon('plus', 12) + ' إضافة حساب واتساب</button></div></div>';
      return;
    }
    drawCampaignForm(myGen);
  };
  if (accountsLoaded) draw();
  loadAccounts().then(() => { if (myGen === gen && !document.getElementById('campaignForm')) draw(); else if (myGen === gen) refreshAccountState(); });
}

function accountStateBannerHtml(acc) {
  if (!acc || acc.state === 'online') return '';
  const s = STATE_INFO[acc.state] || STATE_INFO.offline;
  return '<div class="state-banner ' + (acc.state === 'offline' || acc.state === 'new' ? 'warn' : s.banner) + '">' + icon('warn', 14) +
    '<span>جهاز الإرسال لهذا الحساب: ' + s.label + '. تقدر تنشئ الحملة الآن، ورسائلها بتنتظر وتنرسل تلقائياً أول ما يتصل الجهاز. ' +
    '<a href="#accounts" onclick="showSection(\'accounts\');return false" style="color:inherit;font-weight:700;text-decoration:underline">التفاصيل</a></span></div>';
}

function refreshAccountState() {
  const sel = document.getElementById('accPicker');
  if (!sel) return;
  accounts.forEach(a => {
    const opt = sel.querySelector('option[value="' + a.id + '"]');
    if (opt) opt.textContent = accountOptionLabel(a);
  });
  const box = document.getElementById('accStateBox');
  if (box) box.innerHTML = accountStateBannerHtml(accounts.find(a => a.id === activeAccountId));
}

function drawCampaignForm(myGen) {
  const acc = accounts.find(a => a.id === activeAccountId) || accounts[0];
  activeAccountId = acc.id;
  const today = W.today || new Date().toISOString().slice(0, 10);
  content().innerHTML =
    '<div class="page-title"><h2>' + icon('campaigns', 18) + ' الحملات</h2></div>' +
    '<div class="card" id="campaignForm">' +
      '<div class="card-header"><h4>' + icon('send', 15) + ' حملة جديدة</h4></div>' +
      '<label class="field-label">حساب واتساب المرسِل</label>' +
      '<select id="accPicker" onchange="switchCampaignAccount(this.value)">' +
        accounts.map(a => '<option value="' + a.id + '"' + (a.id === acc.id ? ' selected' : '') + '>' + esc(accountOptionLabel(a)) + '</option>').join('') +
      '</select>' +
      '<div id="accStateBox">' + accountStateBannerHtml(acc) + '</div>' +
      '<label class="field-label">رمز الدولة الافتراضي (يُضاف تلقائياً لأي رقم ما يبدأ فيه)</label>' +
      countryCodeSelectHtml() +
      '<label class="field-label">الأرقام — رقم بكل سطر أو مفصولة بفواصل (بدون حاجة لرمز الدولة)</label>' +
      '<textarea id="numbersText" rows="5" placeholder="07701234567&#10;7709876543" dir="ltr" style="text-align:right" oninput="updateNumbersCount()"></textarea>' +
      '<div class="numbers-count" id="numbersCount"></div>' +
      '<label class="field-label">' + icon('file', 12) + ' أو ارفع ملف Excel / CSV فيه الأرقام</label>' +
      '<input type="file" id="numbersFile" accept=".xlsx,.csv,.txt">' +
      '<div class="field-hint">يتعرّف تلقائياً على عمود الأرقام بأول ورقة بالملف. الأرقام المكررة تنحذف.</div>' +
      '<label class="field-label">نص الرسالة</label>' +
      '<textarea id="text" rows="3" placeholder="اكتب نص رسالتك هنا" oninput="updateMessagePreview()">' + esc(W.defaultMessage || '') + '</textarea>' +
      '<label class="field-label">' + icon('link', 12) + ' رابط (اختياري — يُضاف بآخر الرسالة)</label>' +
      '<input id="link" type="text" inputmode="url" placeholder="https://example.com" dir="ltr" oninput="updateMessagePreview()">' +
      '<label class="field-label">معاينة الرسالة كما توصل للعميل</label>' +
      '<div class="message-preview" id="msgPreview"></div>' +
      '<div class="field-grid">' +
        '<div><label class="field-label">الفاصل بين كل رسالة وأخرى (ثواني)</label>' +
          '<input id="delay" type="number" min="' + (W.minDelay || 5) + '" value="' + (W.defaultDelay || 15) + '"></div>' +
        '<div><label class="field-label">' + icon('calendar', 12) + ' طريقة الإرسال</label>' +
          '<select id="splitMode" onchange="onSplitModeChange()">' +
            '<option value="none">فوري، أو بموعد واحد</option>' +
            '<option value="count">تقسيم على أيام — عدد ثابت من الأرقام كل يوم</option>' +
            '<option value="days">تقسيم على أيام — وزّع الأرقام على عدد أيام</option>' +
          '</select></div>' +
      '</div>' +
      '<div id="singleScheduleBox">' +
        '<label class="field-label">موعد الإرسال (اختياري — اتركه فاضي للإرسال الفوري)</label>' +
        '<input id="sendAt" type="datetime-local">' +
      '</div>' +
      '<div id="splitBox" style="display:none">' +
        '<div class="field-grid">' +
          '<div><label class="field-label" id="splitValueLabel">عدد الأرقام باليوم الواحد</label>' +
            '<input id="splitValue" type="number" min="1" placeholder="مثال: 20" oninput="updateSplitSummary()"></div>' +
          '<div><label class="field-label">وقت الإرسال اليومي</label>' +
            '<input id="sendTime" type="time" value="' + esc(W.defaultSplitTime || '10:00') + '" oninput="updateSplitSummary()"></div>' +
        '</div>' +
        '<label class="field-label">تاريخ بداية الإرسال</label>' +
        '<input id="startDate" type="date" value="' + esc(today) + '" min="' + esc(today) + '">' +
        '<div class="field-hint" id="splitSummary"></div>' +
      '</div>' +
      '<button class="btn-submit" id="startBtn" onclick="startCampaign()">' + icon('rocket', 15) + ' بدء الحملة</button>' +
      '<div class="form-msg" id="campaignMsg"></div>' +
    '</div>' +
    '<h3 class="section-sub">' + icon('list', 13) + ' حملات هذا الحساب</h3>' +
    '<div id="campaignList"><div class="loading-line">جارِ التحميل...</div></div>';
  updateMessagePreview();
  updateSplitSummary();
  loadCampaignList(myGen);
  every(myGen, 6000, async () => {
    await Promise.all([loadCampaignList(myGen), loadAccounts().then(refreshAccountState)]);
  });
}

function switchCampaignAccount(id) {
  activeAccountId = Number(id);
  localStorageSet('wasel.account', String(activeAccountId));
  refreshAccountState();
  const list = document.getElementById('campaignList');
  if (list) list.innerHTML = '<div class="loading-line">جارِ التحميل...</div>';
  loadCampaignList(gen);
}

async function loadCampaignList(myGen) {
  const accId = activeAccountId;
  const r = await api('campaigns', { query: { account: accId } });
  if (myGen !== gen || accId !== activeAccountId) return;
  const box = document.getElementById('campaignList');
  if (!box) return;
  if (!r.ok) { if (!box.dataset.loaded) box.innerHTML = '<div class="state-banner bad">' + esc(r.error) + '</div>'; return; }
  box.dataset.loaded = '1';
  box.innerHTML = r.items.length ? r.items.map(campaignCardHtml).join('') : '<div class="card"><div class="muted-sm center">ما فيه حملات لهذا الحساب بعد</div></div>';
}

const CAMPAIGN_STATUS = {
  active: ['pill-blue', 'جارِ الإرسال'], scheduled: ['pill-amber', 'مجدولة'], done: ['pill-green', 'مكتملة'], cancelled: ['pill-gray', 'ملغاة'],
};

function campaignStatusPill(c) {
  const s = CAMPAIGN_STATUS[c.status] || ['pill-gray', c.status];
  return '<span class="pill ' + s[0] + '">' + s[1] + '</span>';
}

function campaignModeText(c) {
  if (c.mode === 'split') return 'مقسّمة على ' + c.days + ' يوم × ' + c.per_day + ' — الساعة ' + (c.send_time || '');
  if (c.mode === 'once') return 'مجدولة بموعد ' + c.start_at;
  return 'إرسال فوري';
}

function progressHtml(c) {
  const t = Math.max(1, c.total);
  const pct = n => (n * 100 / t).toFixed(2) + '%';
  return '<div class="progress"><div class="p-sent" style="width:' + pct(c.sent) + '"></div><div class="p-failed" style="width:' + pct(c.failed) + '"></div>' +
    '<div class="p-cancel" style="width:' + pct(c.cancelled) + '"></div></div>';
}

function firstLine(text) {
  const t = String(text || '').trim().split('\n')[0];
  return t || '(بدون نص)';
}

function campaignCardHtml(c) {
  return '<div class="card campaign-card" onclick="openCampaign(' + c.id + ')">' +
    '<div class="top"><div class="grow"><div class="title">' + esc(firstLine(c.text)) + '</div>' +
      '<div class="meta">' + esc(campaignModeText(c)) + ' · أُنشئت ' + esc(c.created_at) +
      (c.status !== 'done' && c.next_at ? ' · الموعد الجاي ' + esc(c.next_at) : '') + '</div></div>' + campaignStatusPill(c) + '</div>' +
    progressHtml(c) +
    '<div class="counts"><span class="pill pill-teal">' + fmtNum(c.total) + ' رقم</span><span class="pill pill-green">نجح ' + fmtNum(c.sent) + '</span>' +
      '<span class="pill pill-red">فشل ' + fmtNum(c.failed) + '</span>' +
      (c.pending ? '<span class="pill pill-amber">متبقي ' + fmtNum(c.pending) + '</span>' : '') +
      (c.cancelled ? '<span class="pill pill-gray">ملغاة ' + fmtNum(c.cancelled) + '</span>' : '') + '</div>' +
  '</div>';
}

/** صفوف مختصرة (الرئيسية والإحصائيات). */
function campaignRowsHtml(rows, showOwner) {
  if (!rows.length) return '<div class="muted-sm">ما فيه حملات بعد</div>';
  return rows.map(c =>
    '<div class="history-row clickable" onclick="openCampaign(' + c.id + ')">' +
      '<div class="grow"><span class="campaign-name">' + esc(firstLine(c.text)) + '</span>' +
      '<div class="campaign-detail">' + esc((showOwner && c.owner_name ? c.owner_name + ' · ' : '') + (c.account_name || '') + ' · ' + c.created_at) + '</div></div>' +
      '<div class="side">' + campaignStatusPill(c) + '<span class="pill pill-green">نجح ' + fmtNum(c.sent) + '</span><span class="pill pill-red">فشل ' + fmtNum(c.failed) + '</span></div>' +
    '</div>'
  ).join('');
}

async function startCampaign() {
  const btn = document.getElementById('startBtn');
  const form = new FormData();
  form.append('account_id', document.getElementById('accPicker').value);
  form.append('country_code', document.getElementById('countryCode').value);
  form.append('numbers_text', document.getElementById('numbersText').value);
  form.append('text', document.getElementById('text').value.trim());
  form.append('link', document.getElementById('link').value.trim());
  form.append('delay', document.getElementById('delay').value || String(W.defaultDelay || 15));
  const splitMode = document.getElementById('splitMode').value;
  form.append('split_mode', splitMode);
  if (splitMode === 'none') {
    form.append('send_at', document.getElementById('sendAt').value || '');
  } else {
    form.append('split_value', document.getElementById('splitValue').value || '');
    form.append('start_date', document.getElementById('startDate').value || '');
    form.append('send_time', document.getElementById('sendTime').value || '');
  }
  const file = document.getElementById('numbersFile').files[0];
  if (file) form.append('numbers_file', file);

  if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission();
  btn.disabled = true;
  setMsg('campaignMsg', 'جارِ إنشاء الحملة...');
  const r = await api('campaign_create', { form: form });
  btn.disabled = false;
  if (!r.ok) { setMsg('campaignMsg', esc(r.error), 'error'); return; }
  let msg;
  if (r.mode === 'split') msg = 'تمت الجدولة: ' + fmtNum(r.total) + ' رقم على ' + r.days + ' يوم (~' + r.per_day + ' يومياً)، ابتداءً من ' + esc(r.start_at);
  else if (r.mode === 'once') msg = 'تمت جدولة ' + fmtNum(r.total) + ' رسالة بموعد ' + esc(r.start_at);
  else msg = 'بدأت الحملة: ' + fmtNum(r.total) + ' رسالة — جهاز الإرسال يرسلها تلقائياً بالترتيب';
  if (r.account_state !== 'online') msg += '<br><span style="color:var(--amber)">ملاحظة: جهاز الإرسال لهذا الحساب مو متصل حالياً — الرسائل بتنرسل أول ما يتصل.</span>';
  setMsg('campaignMsg', msg, 'success');
  toast('تم إنشاء الحملة');
  document.getElementById('numbersText').value = '';
  document.getElementById('numbersFile').value = '';
  updateNumbersCount();
  loadCampaignList(gen);
}

/* ---------- تفاصيل حملة ---------- */

let detailId = null;
let detailReturn = null;

function openCampaign(id) {
  detailId = id;
  detailReturn = section;
  const myGen = newGen();
  content().innerHTML = '<div class="loading-line">جارِ التحميل...</div>';
  fadeContent();
  window.scrollTo(0, 0);
  const load = async () => {
    const r = await api('campaign', { query: { id: id } });
    if (myGen !== gen) return;
    if (!r.ok) { content().innerHTML = '<div class="state-banner bad">' + esc(r.error) + '</div>'; return; }
    drawCampaignDetail(r);
  };
  load();
  every(myGen, 5000, load);
}

function closeCampaign() {
  detailId = null;
  showSection(detailReturn && SECTIONS.indexOf(detailReturn) !== -1 ? detailReturn : 'campaigns');
}

const DAY_STATE = { pending: ['pill-amber', 'بانتظار موعده'], running: ['pill-blue', 'شغّال'], done: ['pill-green', 'اكتمل'], cancelled: ['pill-gray', 'ملغى'] };

function drawCampaignDetail(c) {
  const isOwnerView = !IS_ADMIN || c.owner_name === (ME.name || ME.email);
  let html =
    '<div class="page-title"><h2>' + icon('info', 18) + ' تفاصيل الحملة</h2>' +
      '<button class="btn-outline btn-small" onclick="closeCampaign()">' + icon('back', 11) + ' رجوع</button></div>' +
    '<div class="campaign-detail-card">' +
      '<div class="detail-row"><span class="label">' + icon('accounts', 13) + ' الحساب</span><span class="value">' + esc(c.account_name || '—') + ' ' + statePill(c.account_state) + '</span></div>' +
      (IS_ADMIN && !isOwnerView ? '<div class="detail-row"><span class="label">' + icon('branches', 13) + ' الفرع</span><span class="value">' + esc(c.owner_name) + '</span></div>' : '') +
      '<div class="detail-row"><span class="label">' + icon('info', 13) + ' الحالة</span><span class="value">' + campaignStatusPill(c) + '</span></div>' +
      '<div class="detail-row"><span class="label">' + icon('calendar', 13) + ' طريقة الإرسال</span><span class="value">' + esc(campaignModeText(c)) + '</span></div>' +
      '<div class="detail-row"><span class="label">' + icon('hourglass', 13) + ' الفاصل بين الرسائل</span><span class="value">' + c.delay + ' ثانية</span></div>' +
      '<div class="detail-row"><span class="label">' + icon('clock', 13) + ' أُنشئت</span><span class="value ltr">' + esc(c.created_at) + '</span></div>' +
      (c.next_at && c.status !== 'done' && c.status !== 'cancelled' ? '<div class="detail-row"><span class="label">' + icon('clock', 13) + ' الرسالة الجاية</span><span class="value ltr">' + esc(c.next_at) + '</span></div>' : '') +
      (c.finished_at ? '<div class="detail-row"><span class="label">' + icon('checkCircle', 13) + ' انتهت</span><span class="value ltr">' + esc(c.finished_at) + '</span></div>' : '') +
    '</div>' +
    '<div class="stats-mini-row">' +
      statMiniHtml(fmtNum(c.total), 'الإجمالي', '') + statMiniHtml(fmtNum(c.sent), 'نجح', 'green') +
      statMiniHtml(fmtNum(c.failed), 'فشل', 'orange') + statMiniHtml(fmtNum(c.pending), 'متبقي', 'primary') +
    '</div>' + progressHtml(c) +
    '<div class="card mt-3"><div class="card-header"><h4>' + icon('message', 15) + ' نص الرسالة</h4></div><div class="message-preview">' + esc(c.message) + '</div></div>';

  if (c.days > 1 && c.days_detail && c.days_detail.length) {
    html += '<div class="card"><div class="card-header"><h4>' + icon('calendar', 15) + ' جدول الأيام</h4></div><div class="table-wrap"><table class="days-table">' +
      '<tr><th>اليوم</th><th>الموعد</th><th>الأرقام</th><th>نجح</th><th>فشل</th><th>متبقي</th><th>الحالة</th><th></th></tr>' +
      c.days_detail.map(d => {
        const st = DAY_STATE[d.state] || ['pill-gray', d.state];
        return '<tr><td class="fw-bold">' + d.day + '/' + c.days + '</td><td class="ltr" style="text-align:right">' + esc(d.scheduled_at) + '</td><td>' + d.total + '</td>' +
          '<td style="color:#059669;font-weight:700">' + d.sent + '</td><td style="color:#DC2626;font-weight:700">' + d.failed + '</td><td>' + d.pending + '</td>' +
          '<td><span class="pill ' + st[0] + '">' + st[1] + '</span></td>' +
          '<td>' + (c.can_cancel && d.pending > 0 && d.state === 'pending' ? '<button class="btn-outline danger btn-small" onclick="cancelCampaignDay(' + c.id + ',' + d.day + ')">' + icon('ban', 10) + ' إلغاء اليوم</button>' : '') + '</td></tr>';
      }).join('') + '</table></div></div>';
  }

  if (c.failures && c.failures.length) {
    window._failedNumbers = c.failures.map(f => f.phone).join('\n');
    html += '<div class="card"><div class="card-header"><h4>' + icon('warn', 15) + ' الأرقام الفاشلة (' + c.failures.length + ')</h4>' +
      '<button class="btn-outline btn-small" onclick="copyText(window._failedNumbers)">' + icon('copy', 10) + ' نسخ الأرقام</button></div>' +
      c.failures.map(f => '<div class="history-row"><span class="ltr fw-bold">+' + esc(f.phone) + '</span><span class="muted-sm">' + esc(f.error || 'فشل الإرسال') + '</span></div>').join('') +
    '</div>';
  }

  html += '<div class="btn-row">' +
    (c.can_cancel ? '<button class="btn-outline danger" onclick="cancelCampaign(' + c.id + ')">' + icon('ban', 12) + ' إلغاء الحملة (الرسائل المتبقية)</button>' : '') +
    '<button class="btn-outline" onclick="closeCampaign()">' + icon('back', 12) + ' رجوع</button></div>';
  content().innerHTML = html;
}

async function cancelCampaign(id) {
  if (!confirm('إلغاء الحملة؟ الرسائل اللي ما انرسلت بعد بتنلغي (اللي انرسلت تبقى بالسجل).')) return;
  const r = await api('campaign_cancel', { body: { id: id } });
  if (!r.ok) { toast(r.error, true); return; }
  toast('تم إلغاء الحملة');
  openCampaign(id);
}

async function cancelCampaignDay(id, day) {
  if (!confirm('إلغاء رسائل اليوم ' + day + ' من هذه الحملة؟')) return;
  const r = await api('campaign_cancel_day', { body: { id: id, day: day } });
  if (!r.ok) { toast(r.error, true); return; }
  toast('تم إلغاء ' + r.cancelled + ' رسالة');
  openCampaign(id);
}

/* ====================================================================== الإحصائيات (الأدمن) */

function analyticsPerfHtml(s) {
  const finished = s.campaigns_success + s.campaigns_with_failures;
  const rate = finished ? Math.round(s.campaigns_success * 100 / finished) : 0;
  return '<div class="history-row"><span>إجمالي الحملات</span><span class="fw-bold">' + fmtNum(s.campaigns_total) + '</span></div>' +
    '<div class="history-row"><span>حملات شغّالة أو مجدولة</span><span class="pill pill-blue">' + fmtNum(s.campaigns_active) + '</span></div>' +
    '<div class="history-row"><span>حملات مكتملة بدون أي فشل</span><span class="pill pill-green">' + fmtNum(s.campaigns_success) + '</span></div>' +
    '<div class="history-row"><span>حملات مكتملة فيها أرقام فاشلة</span><span class="pill pill-red">' + fmtNum(s.campaigns_with_failures) + '</span></div>' +
    '<div class="history-row"><span>نسبة الحملات الناجحة بالكامل</span><span class="pill pill-green">' + rate + '%</span></div>';
}

function renderAnalytics(myGen) {
  const s = dataCache.dashboard;
  content().innerHTML =
    '<div class="page-title"><h2>' + icon('analytics', 18) + ' الإحصائيات</h2></div>' +
    '<div class="stats-mini-row" id="anStats1"></div><div class="stats-mini-row" id="anStats2"></div>' +
    '<div class="card"><div class="card-header"><h4>' + icon('trophy', 15) + ' أداء الحملات</h4></div><div id="anPerf"><div class="loading-line">جارِ التحميل...</div></div></div>' +
    '<div class="card"><div class="card-header"><h4>' + icon('list', 15) + ' آخر حملات المنصة</h4></div><div id="anList"><div class="loading-line">جارِ التحميل...</div></div></div>';
  const fill = d => {
    document.getElementById('anStats1').innerHTML =
      statMiniHtml(fmtNum(d.messages_sent), 'إجمالي الرسائل المرسلة', 'primary') + statMiniHtml(d.success_rate + '%', 'معدل النجاح', 'green') +
      statMiniHtml(fmtNum(d.messages_failed), 'رسائل فاشلة', 'orange') + statMiniHtml(fmtNum(d.sent_today), 'أُرسلت اليوم', 'primary');
    document.getElementById('anStats2').innerHTML =
      statMiniHtml(fmtNum(d.accounts_total), 'حسابات واتساب', '') + statMiniHtml(fmtNum(d.accounts_connected), 'متصلة الآن', 'green') +
      statMiniHtml(fmtNum(d.campaigns_active), 'حملات نشطة', 'orange') + statMiniHtml(fmtNum(d.messages_pending), 'بانتظار الإرسال', 'primary');
    document.getElementById('anPerf').innerHTML = analyticsPerfHtml(d);
  };
  if (s) fill(s);
  const load = async () => {
    const [d, rc] = await Promise.all([api('dashboard'), api('campaigns', { query: { scope: 'all', limit: 50 } })]);
    if (myGen !== gen) return;
    if (d.ok) { dataCache.dashboard = d; fill(d); }
    if (rc.ok) document.getElementById('anList').innerHTML = campaignRowsHtml(rc.items, true);
  };
  load();
  every(myGen, 20000, load);
}

/* ====================================================================== الفروع (الأدمن) */

function renderBranches(myGen) {
  content().innerHTML =
    '<div class="page-title"><h2>' + icon('branches', 18) + ' الفروع</h2></div>' +
    '<div class="card"><div class="card-header"><h4>' + icon('plus', 15) + ' إنشاء فرع جديد</h4></div>' +
      '<p class="muted-sm mb-2">كل فرع ياخذ حساب دخول مستقل (بريد وكلمة مرور). صلاحيات الفرع: إضافة حسابات واتساب، إدارة الحملات، والإشعارات — وبياناته معزولة تماماً عن باقي الفروع.</p>' +
      '<label class="field-label">اسم الفرع</label><input id="branchName" maxlength="120" placeholder="مثال: فرع الكرادة">' +
      '<label class="field-label">البريد الإلكتروني (للدخول)</label><input id="branchEmail" type="email" dir="ltr" placeholder="branch1@example.com">' +
      '<label class="field-label">كلمة المرور (اتركها فاضية لتوليد كلمة مرور عشوائية)</label><input id="branchPassword" type="text" dir="ltr" placeholder="6 أحرف على الأقل" autocomplete="new-password">' +
      '<button class="btn-submit" id="branchBtn" onclick="createBranch()">' + icon('plus', 14) + ' إنشاء الفرع</button>' +
      '<div class="form-msg" id="branchMsg"></div>' +
    '</div>' +
    '<div class="card"><div class="card-header"><h4>' + icon('branches', 15) + ' الفروع الحالية</h4></div>' +
      '<div id="branchesBox">' + (dataCache.branches ? branchesListHtml(dataCache.branches) : '<div class="loading-line">جارِ التحميل...</div>') + '</div></div>';
  loadBranchesList(myGen);
}

function branchesListHtml(rows) {
  if (!rows.length) return '<div class="muted-sm">ما فيه فروع بعد — أنشئ أول فرع من الأعلى.</div>';
  return rows.map(b =>
    '<div class="history-row"><div class="row grow">' + avatarHtml(b.name || b.email, b.id) +
      '<div class="grow"><span class="campaign-name">' + esc(b.name || b.email) + '</span>' +
      '<div class="campaign-detail"><span class="ltr">' + esc(b.email) + '</span> · ' + b.accounts + ' حساب واتساب · ' + b.campaigns + ' حملة</div></div></div>' +
      '<div class="side">' +
        '<button class="btn-outline btn-small" onclick="resetBranchPassword(' + b.id + ')">' + icon('lock', 10) + ' كلمة المرور</button>' +
        '<button class="btn-outline danger btn-small" onclick="deleteBranch(' + b.id + ')">' + icon('trash', 10) + ' حذف</button>' +
      '</div></div>'
  ).join('');
}

async function loadBranchesList(myGen) {
  const r = await api('branches');
  if (myGen !== undefined && myGen !== gen) return;
  if (!r.ok) return;
  dataCache.branches = r.items;
  const box = document.getElementById('branchesBox');
  if (box) box.innerHTML = branchesListHtml(r.items);
}

function credentialsHtml(email, password) {
  return 'بيانات دخول الفرع (تظهر مرة وحدة — احفظها أو أرسلها للفرع الآن):<br>' +
    '<span class="ltr fw-bold">' + esc(email) + '</span> / <span class="ltr fw-bold">' + esc(password) + '</span> ' +
    '<button class="btn-outline btn-small" onclick="copyText(window._lastCreds)">' + icon('copy', 10) + ' نسخ</button>';
}

async function createBranch() {
  const name = document.getElementById('branchName').value.trim();
  const email = document.getElementById('branchEmail').value.trim();
  const password = document.getElementById('branchPassword').value;
  const btn = document.getElementById('branchBtn');
  btn.disabled = true;
  setMsg('branchMsg', 'جارِ الإنشاء...');
  const r = await api('branch_create', { body: { name: name, email: email, password: password } });
  btn.disabled = false;
  if (!r.ok) { setMsg('branchMsg', esc(r.error), 'error'); return; }
  window._lastCreds = 'رابط الدخول: ' + location.href.split('#')[0] + '\nالبريد: ' + r.email + '\nكلمة المرور: ' + r.password;
  setMsg('branchMsg', 'تم إنشاء الفرع "' + esc(r.name) + '". ' + credentialsHtml(r.email, r.password), 'success');
  document.getElementById('branchName').value = '';
  document.getElementById('branchEmail').value = '';
  document.getElementById('branchPassword').value = '';
  loadBranchesList();
}

async function deleteBranch(id) {
  const b = (dataCache.branches || []).find(x => x.id === id);
  if (!confirm('حذف الفرع "' + (b ? (b.name || b.email) : '') + '"؟ بتنحذف كل حسابات واتساب وحملات هذا الفرع نهائياً.')) return;
  const r = await api('branch_delete', { body: { id: id } });
  if (!r.ok) { toast(r.error, true); return; }
  toast('تم حذف الفرع');
  loadBranchesList();
}

function resetBranchPassword(id) {
  const b = (dataCache.branches || []).find(x => x.id === id);
  openInfoSheet(
    '<div class="sheet-title">' + icon('lock', 16) + ' تغيير كلمة مرور الفرع</div>' +
    '<div class="sheet-message">' + esc(b ? (b.name || '') + ' — ' + b.email : '') + '<br>الفرع بيطلع من كل أجهزته ويدخل بكلمة المرور الجديدة.</div>' +
    '<input id="newBranchPassword" type="text" dir="ltr" placeholder="اتركها فاضية لتوليد كلمة مرور عشوائية" autocomplete="new-password">' +
    '<div class="form-msg" id="pwMsg"></div>' +
    '<div class="sheet-actions mt-2"><button class="btn-cancel" onclick="closeInfoSheet()">إغلاق</button>' +
    '<button class="btn-confirm-gold" id="pwBtn" onclick="submitBranchPassword(' + id + ')">' + icon('check', 13) + ' حفظ</button></div>'
  );
}

async function submitBranchPassword(id) {
  const r = await api('branch_password', { body: { id: id, password: document.getElementById('newBranchPassword').value } });
  if (!r.ok) { setMsg('pwMsg', esc(r.error), 'error'); return; }
  window._lastCreds = 'رابط الدخول: ' + location.href.split('#')[0] + '\nالبريد: ' + r.email + '\nكلمة المرور: ' + r.password;
  setMsg('pwMsg', credentialsHtml(r.email, r.password), 'success');
  document.getElementById('pwBtn').style.display = 'none';
}

/* ====================================================================== لوحة التحكم (الأدمن) */

function renderAdmin(myGen) {
  content().innerHTML =
    '<div class="page-title"><h2>' + icon('admin', 18) + ' لوحة التحكم</h2></div>' +
    '<div class="card"><div class="card-header"><h4>' + icon('image', 15) + ' هوية الموقع</h4></div>' +
      '<div class="row" style="gap:16px;align-items:flex-start">' +
        '<div class="logo-preview" id="logoPreview">' + (LOGO_URL ? '<img src="' + esc(LOGO_URL) + '" alt="">' : esc(SITE_NAME.charAt(0))) + '</div>' +
        '<div class="grow"><input type="file" id="siteLogo" accept="image/png,image/jpeg,image/webp,image/gif">' +
          '<div class="field-hint">PNG أو JPG أو WEBP — مربعة يُفضّل، وأقل من 2 ميغابايت. تظهر بالشريط الجانبي وشاشة الدخول وأيقونة التطبيق.</div>' +
          '<button class="btn-outline btn-small mt-2" onclick="removeSiteLogo()">' + icon('trash', 10) + ' إزالة الشعار</button></div>' +
      '</div>' +
      '<label class="field-label">اسم الموقع</label><input id="siteName" maxlength="60" value="' + esc(SITE_NAME) + '">' +
      '<button class="btn-submit" onclick="saveSiteIdentity()">' + icon('checkCircle', 14) + ' حفظ الهوية</button>' +
      '<div class="form-msg" id="siteIdentityMsg"></div>' +
    '</div>' +
    '<div class="card"><div class="card-header"><h4>' + icon('trophy', 15) + ' الأفضل نجاحاً بإرسال الحملات</h4></div>' +
      '<div id="leaderboardBox">' + (dataCache.leaderboard ? leaderboardHtml(dataCache.leaderboard) : '<div class="loading-line">جارِ التحميل...</div>') + '</div></div>' +
    '<div class="card"><div class="card-header"><h4>' + icon('users', 15) + ' كل حسابات المنصة</h4></div>' +
      '<div id="adminAccountsBox">' + (dataCache.adminUsers ? adminUsersHtml(dataCache.adminUsers) : '<div class="loading-line">جارِ التحميل...</div>') + '</div></div>';
  (async () => {
    const [lb, au] = await Promise.all([api('leaderboard'), api('admin_users')]);
    if (myGen !== gen) return;
    if (lb.ok) { dataCache.leaderboard = lb.items; document.getElementById('leaderboardBox').innerHTML = leaderboardHtml(lb.items); }
    if (au.ok) { dataCache.adminUsers = au.items; document.getElementById('adminAccountsBox').innerHTML = adminUsersHtml(au.items); }
  })();
}

function leaderboardHtml(rows) {
  if (!rows.length) return '<div class="muted-sm">ما فيه رسائل مُرسلة بعد لعرض ترتيب</div>';
  return rows.map((r, i) => {
    const kind = r.is_branch ? '<span class="pill pill-blue">فرع</span>' : '<span class="pill pill-gold">أدمن</span>';
    return '<div class="history-row"><div class="row grow"><b style="width:20px;text-align:center;color:var(--text-muted)">' + (i + 1) + '</b>' +
      avatarHtml(r.name, r.user_id) + '<div class="grow"><span class="campaign-name">' + esc(r.name) + '</span><div class="campaign-detail ltr" style="text-align:right">' + esc(r.email) + '</div></div>' + kind + '</div>' +
      '<div class="side"><span class="pill pill-green">' + r.success_rate + '%</span><span class="muted-sm">(' + fmtNum(r.sent) + '/' + fmtNum(r.total) + ')</span></div></div>';
  }).join('');
}

function adminUsersHtml(rows) {
  if (!rows.length) return '<div class="muted-sm">ما فيه حسابات بعد</div>';
  return rows.map(u => {
    const kind = u.is_admin ? '<span class="pill pill-gold">أدمن</span>' : '<span class="pill pill-blue">فرع</span>';
    return '<div class="history-row"><div class="row grow">' + avatarHtml(u.name || u.email, u.id) +
      '<div class="grow"><span class="campaign-name">' + esc(u.name || u.email) + '</span><div class="campaign-detail"><span class="ltr">' + esc(u.email) + '</span> · ' + esc(u.created_at) + '</div></div></div>' + kind + '</div>';
  }).join('');
}

async function saveSiteIdentity() {
  const form = new FormData();
  form.append('site_name', document.getElementById('siteName').value.trim());
  const file = document.getElementById('siteLogo').files[0];
  if (file) form.append('logo', file);
  setMsg('siteIdentityMsg', 'جارِ الحفظ...');
  const r = await api('site_identity_save', { form: form });
  if (!r.ok) { setMsg('siteIdentityMsg', esc(r.error), 'error'); return; }
  setMsg('siteIdentityMsg', 'تم الحفظ — جارِ تحديث الصفحة...', 'success');
  setTimeout(() => location.reload(), 900);
}

async function removeSiteLogo() {
  if (!confirm('إزالة شعار الموقع والرجوع للشعار الافتراضي؟')) return;
  const form = new FormData();
  form.append('site_name', document.getElementById('siteName').value.trim());
  form.append('remove_logo', '1');
  const r = await api('site_identity_save', { form: form });
  if (!r.ok) { toast(r.error, true); return; }
  location.reload();
}

/* ====================================================================== الإعدادات (الأدمن): معلومات الأدمن + إنشاء فرع فقط */

function renderSettings() {
  const displayName = ME.name || String(ME.email || '').split('@')[0];
  content().innerHTML =
    '<div class="page-title"><h2>' + icon('settings', 18) + ' الإعدادات</h2></div>' +
    '<div class="profile-card">' +
      '<div class="avatar avatar-0">' + esc((displayName || '؟').charAt(0).toUpperCase()) + '</div>' +
      '<div class="info"><div class="name">' + esc(displayName) + '</div><div class="email ltr" style="text-align:right">' + esc(ME.email) + '</div><span class="role">أدمن المنصة</span></div>' +
    '</div>' +
    '<div class="settings-list">' +
      '<div class="settings-head">' + icon('branches', 13) + ' الفروع</div>' +
      '<div class="settings-item" onclick="showSection(\'branches\')">' +
        '<div class="left"><div class="icon-wrap primary">' + icon('plus', 15) + '</div><div><span class="text">إنشاء فرع جديد</span><span class="sub-text">أنشئ وأدر حسابات دخول الفروع التابعة للمنصة</span></div></div>' +
        '<i class="fas fa-chevron-left chevron"></i>' +
      '</div>' +
    '</div>';
}

/* ====================================================================== الإشعارات */

function renderNotifications() {
  let permCard = '';
  if ('Notification' in window) {
    if (Notification.permission === 'default') {
      permCard = '<div class="card info"><div class="row-wrap"><div class="grow muted">' + icon('bell', 14) + ' فعّل إشعارات المتصفح حتى يوصلك تنبيه أول ما تبدأ أو تخلص أي حملة.</div>' +
        '<button class="btn-primary-small" onclick="requestNotifPermission()">' + icon('check', 12) + ' تفعيل الإشعارات</button></div></div>';
    } else if (Notification.permission === 'denied') {
      permCard = '<div class="card warn"><div class="muted-sm">' + icon('warn', 13) + ' إشعارات المتصفح محظورة لهذا الموقع. فعّلها من رمز القفل بجانب عنوان الموقع ← الإشعارات ← سماح.</div></div>';
    }
  }
  content().innerHTML =
    '<div class="page-title"><h2>' + icon('notifications', 18) + ' الإشعارات</h2>' +
      '<button class="btn-outline btn-small" onclick="markAllEventsRead()">' + icon('check', 10) + ' تحديد الكل كمقروء</button></div>' +
    permCard + '<div id="notifList">' + (eventsCache ? '' : '<div class="loading-line">جارِ التحميل...</div>') + '</div>';
  if (eventsCache) renderNotifList();
  pollEvents();
}

function renderNotifList() {
  const box = document.getElementById('notifList');
  if (!box || !eventsCache) return;
  box.innerHTML = eventsCache.length
    ? eventsCache.map(e => {
        const cls = e.kind === 'success' ? 'success' : e.kind === 'warning' ? 'warning' : '';
        const ic = e.kind === 'success' ? 'checkCircle' : e.kind === 'warning' ? 'warn' : 'bell';
        return '<div class="notif-item-full' + (e.read ? '' : ' unread') + '">' +
          '<div class="notif-icon ' + cls + '">' + icon(ic, 15) + '</div>' +
          '<div class="notif-content"><div class="notif-title">' + esc(e.title) + '</div>' +
          '<div class="notif-message">' + (e.account ? esc(e.account) + ' — ' : '') + esc(e.body) + '</div></div>' +
          '<span class="notif-time ltr">' + esc(e.time) + '</span>' +
          '<div class="notif-actions">' +
            (e.read ? '' : '<button class="btn-read" onclick="markEventRead(' + e.id + ')" title="تحديد كمقروء">' + icon('check', 12) + '</button>') +
            '<button class="btn-delete" onclick="deleteEvent(' + e.id + ')" title="حذف">' + icon('trash', 12) + '</button>' +
          '</div></div>';
      }).join('')
    : '<div class="card"><div class="empty-state">' + icon('notifications', 30) + 'ما فيه إشعارات بعد</div></div>';
}

async function markEventRead(id) {
  await api('event_read', { body: { id: id } });
  eventsCache = (eventsCache || []).map(e => e.id === id ? Object.assign({}, e, { read: true }) : e);
  renderNotifList();
  updateBadges(eventsCache.filter(e => !e.read).length);
}

async function markAllEventsRead() {
  await api('events_read_all', { body: {} });
  eventsCache = (eventsCache || []).map(e => Object.assign({}, e, { read: true }));
  renderNotifList();
  updateBadges(0);
}

async function deleteEvent(id) {
  await api('event_delete', { body: { id: id } });
  eventsCache = (eventsCache || []).filter(e => e.id !== id);
  renderNotifList();
  updateBadges(eventsCache.filter(e => !e.read).length);
}

/* ====================================================================== تسجيل الخروج */

function logoutPlatform() {
  document.getElementById('confirmOverlay').classList.add('show');
  document.getElementById('confirmSheet').classList.add('show');
}
function closeConfirmSheet() {
  document.getElementById('confirmOverlay').classList.remove('show');
  document.getElementById('confirmSheet').classList.remove('show');
}
function confirmLogout() { document.getElementById('logoutForm').submit(); }

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { closeConfirmSheet(); closeAddAccountSheet(); closeInfoSheet(); closeSidebar(); }
});

/* ====================================================================== البدء */

initChrome();
showSection((location.hash || '').replace('#', '') || NAV[0], { keepHash: true });
pollEvents();
