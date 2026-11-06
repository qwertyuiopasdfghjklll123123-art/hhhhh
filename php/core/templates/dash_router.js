/* روابط أقسام لوحة /app — يعمل فوق الواجهة الحالية دون تعديل شكلها (انظر core/lib/app_routes.php).
 *  • يراقب فتح/إغلاق الصفحات والطبقات (تغيّر الصنف active / show) ويحدّث الرابط: /app/<slug>
 *  • عند تحميل رابط قسم (أو إعادة تحميله) يفتح القسم بنفس الكود الذي تستعمله القوائم عند النقر
 *  • زر الرجوع/التقدّم بالمتصفح يغلق/يفتح الأقسام؛ وفي الجذر /app يبقى المستخدم داخل التطبيق كما في الأصل
 */
(function () {
  'use strict';
  var S = window.__FC_SECTIONS || [], bySlug = {}, byEl = {};
  S.forEach(function (x) { bySlug[x.s] = x; byEl[x.el] = x; });
  var ADMIN = !!window.__FC_ADMIN;
  var applying = false, popPending = false, stack = [], curSlug = '', timer = null;

  function allowed(x) { return x.a === 'all' || (x.a === 'admin' && ADMIN) || (x.a === 'user' && !ADMIN); }
  function urlFor(slug) { return '/app' + (slug ? '/' + slug : ''); }
  function slugFromPath() {
    var p = location.pathname.replace(/\/+$/, '');
    if (p.indexOf('/app/') !== 0) return '';
    var s = '';
    try { s = decodeURIComponent(p.slice(5)); } catch (e) { return ''; }
    return bySlug[s] ? s : '';
  }
  function el(id) { return document.getElementById(id); }
  function isShown(id) { var e = el(id); return !!(e && e.classList.contains('show')); }
  function run(code) { try { (new Function(code))(); } catch (e) { if (window.console) console.warn('route open failed:', code, e); } }

  /* الطبقات المفتوحة حالياً بترتيب الفتح */
  function syncFromDom() {
    var now = {};
    S.forEach(function (s) { if (s.k === 'ov' && isShown(s.el)) now[s.el] = 1; });
    stack = stack.filter(function (id) { return now[id]; });
    S.forEach(function (s) { if (now[s.el] && stack.indexOf(s.el) < 0) stack.push(s.el); });
  }
  function currentSlug() {
    for (var i = stack.length - 1; i >= 0; i--) if (isShown(stack[i])) return byEl[stack[i]].s;
    var pg = document.querySelector('.page.active');
    return (pg && byEl[pg.id]) ? byEl[pg.id].s : '';
  }
  function closeOv(id) {
    var e = el(id);
    if (!e) return;
    var b = e.querySelector('.ov-back');
    if (b) b.click();                       // نفس معالج الإغلاق الذي يستعمله المستخدم
    if (isShown(id)) e.classList.remove('show');
  }

  /* يجعل الواجهة تطابق القسم المطلوب (عند تحميل الرابط وعند زر الرجوع/التقدّم).
     الواجهة الأصلية تُبقي طبقة واحدة فقط مفتوحة (تغلق غيرها تلقائياً)، فنغلق الباقي ونفتح الهدف وحده. */
  function applyRoute(slug) {
    var x = bySlug[slug];
    if (!x || !allowed(x)) { slug = ''; x = bySlug['']; }
    syncFromDom();
    if (currentSlug() === slug) { curSlug = slug; return; }
    applying = true;
    try {
      stack.slice().reverse().forEach(function (id) { if (id !== x.el) closeOv(id); });
      if (x.k === 'pg' || !isShown(x.el)) run(x.o);
    } finally {
      syncFromDom();
      curSlug = slug;
      setTimeout(function () { applying = false; }, 0);
    }
  }

  /* تغيّرت الواجهة بفعل المستخدم: حدّث الرابط (رجوع للخلف إن كان الهدف هو الإدخال السابق، وإلا إدخال جديد) */
  function onChange() {
    timer = null;
    if (applying) return;
    syncFromDom();
    var r = currentSlug();
    if (r === curSlug) return;
    var st = history.state || {};
    if (st.fc && st.prev === r && !popPending) { popPending = true; curSlug = r; history.back(); return; }
    history.pushState({ fc: 1, s: r, prev: curSlug }, '', urlFor(r));
    curSlug = r;
  }
  function schedule() { if (timer === null) timer = setTimeout(onChange, 0); }

  window.addEventListener('popstate', function (e) {
    popPending = false;
    var st = e.state;
    if (st && st.sentinel) {          // وصل للحارس أسفل الجذر: ابقَ داخل التطبيق (سلوك الأصل: لا خروج بزر الرجوع)
      history.pushState({ fc: 1, s: '', prev: '' }, '', '/app');
      applyRoute('');
      return;
    }
    applyRoute((st && st.fc) ? st.s : slugFromPath());
  });

  function start() {
    var initial = slugFromPath(), x = bySlug[initial];
    if (!x || !allowed(x)) initial = '';
    var st = history.state;
    if (st && st.fc) {
      curSlug = st.s || '';             // إعادة تحميل لنفس الإدخال: الحارس موجود مسبقاً
    } else {
      history.replaceState({ fc: 1, sentinel: 1 }, '', '/app');
      history.pushState({ fc: 1, s: initial, prev: '' }, '', urlFor(initial));
      curSlug = initial;
    }
    var mo = new MutationObserver(function (recs) {
      recs.forEach(function (r) {
        var id = r.target && r.target.id, s = byEl[id];
        if (!s || s.k !== 'ov') return;
        var sh = isShown(id), i = stack.indexOf(id);
        if (sh && i < 0) stack.push(id); else if (!sh && i > -1) stack.splice(i, 1);
      });
      schedule();
    });
    S.forEach(function (s) { var e = el(s.el); if (e) mo.observe(e, { attributes: true, attributeFilter: ['class'] }); });
    if (initial) {
      curSlug = '';                      // الواجهة بالجذر الآن؛ افتح القسم ثم يُحدَّث curSlug
      applyRoute(initial);
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
  else start();
})();
