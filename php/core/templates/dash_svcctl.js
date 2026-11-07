/* صفحة «تحكم بالخدمات» (أدمن): خدماتي (تعديل اسم/وصف/سعر) + إضافة مجموعة أو خدمة من مزوّد + أدوات.
   نقاطها: routes/svcctl.php — الواجهة (HTML/CSS) بـ dash.html (#adminSvcCtlPage, #scSheet) */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function note(m, t) { if (window.toast) window.toast(m, t || 'info'); }
  function get(path) { return fetch(path, { credentials: 'same-origin' }).then(function (r) { return r.json(); }); }
  function post(path, body) {
    return fetch(path, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }).then(function (r) { return r.json(); });
  }
  function qs(o) { return Object.keys(o).filter(function (k) { return o[k] !== '' && o[k] != null && o[k] !== 0; }).map(function (k) { return k + '=' + encodeURIComponent(o[k]); }).join('&'); }
  function money(v) { return v == null || isNaN(v) ? '—' : '$' + (+v).toFixed(+v < 1 ? 4 : 3).replace(/0+$/, '').replace(/\.$/, ''); }
  function debounce(fn, ms) { var t; return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); }; }

  var S = {
    tab: 'mine', ov: null,
    mine: { q: '', flag: '', platform: '', prov: 0, page: 1, items: [], total: 0, stats: null, platforms: [], loading: false, sel: {} },
    add: { prov: 0, mode: 'group', q: '', only: 'all', page: 1, cats: [], items: [], total: 0, sel: {}, profit: 30, loading: false, err: '' },
    edit: null
  };

  /* ------------------------------ فتح الصفحة ------------------------------ */
  window.loadSvcCtl = function () {
    S.tab = 'mine'; S.mine.page = 1; S.mine.items = []; S.mine.sel = {};
    render();
    get('/api/admin/svc-ctl/overview').then(function (d) {
      if (!d.ok) { $('scBody').innerHTML = '<div class="sc-empty">غير مصرح</div>'; return; }
      S.ov = d;
      var act = d.providers.filter(function (p) { return p.active; });
      if (!S.add.prov && act.length) S.add.prov = act[0].id;
      render();
      loadMine(true);
    }).catch(function () { $('scBody').innerHTML = '<div class="sc-empty">خطأ في التحميل</div>'; });
  };
  window.scClose = function () { $('adminSvcCtlPage').classList.remove('show'); scSheetClose(); updBar(); };

  /* ------------------------------ الهيكل ------------------------------ */
  function render() {
    var st = S.mine.stats || { total: (S.ov && S.ov.shown) || 0, refill: 0, cancel: 0, edited: 0, missing: 0 };
    var h = '<div class="sc-stats">'
      + tile(st.total, 'خدمة معروضة', 'fa-cubes', 'var(--primary)')
      + tile(st.refill, 'تدعم التعويض', 'fa-recycle', 'var(--green)')
      + tile(st.cancel, 'تدعم الإلغاء', 'fa-ban', 'var(--red)')
      + tile(st.edited, 'معدّلة', 'fa-pen', '#f59e0b') + '</div>';
    h += '<div class="sc-tabs">'
      + tab('mine', 'fa-list-check', 'خدماتي') + tab('add', 'fa-cloud-arrow-down', 'إضافة من مزوّد') + tab('tools', 'fa-screwdriver-wrench', 'أدوات') + '</div>';
    h += '<div id="scTabBody"></div>';
    $('scBody').innerHTML = h;
    renderTab();
  }
  function tile(n, l, ic, c) { return '<div class="sc-stat"><i class="fa-solid ' + ic + '" style="color:' + c + '"></i><b>' + (n || 0) + '</b><span>' + l + '</span></div>'; }
  function tab(k, ic, l) { return '<button class="sc-tab' + (S.tab === k ? ' on' : '') + '" onclick="scTab(\'' + k + '\')"><i class="fa-solid ' + ic + '"></i> ' + l + '</button>'; }
  window.scTab = function (k) { S.tab = k; render(); if (k === 'add') loadAdd(true); updBar(); };
  function renderTab() { (S.tab === 'mine' ? renderMine : S.tab === 'add' ? renderAdd : renderTools)(); }

  /* ------------------------------ خدماتي ------------------------------ */
  var FLAGS = [['', 'الكل'], ['refill', '♻ تعويض'], ['cancel', '✖ إلغاء'], ['edited', '✎ معدّلة'], ['none', 'بلا تعويض/إلغاء'], ['missing', '⚠ مفقودة']];
  function renderMine() {
    var m = S.mine, h = '';
    h += '<div class="sc-search"><i class="fa-solid fa-magnifying-glass"></i><input id="scQ" placeholder="ابحث بالاسم أو الرقم أو الفئة..." value="' + esc(m.q) + '" oninput="scMineQ(this.value)"></div>';
    h += '<div class="sc-chips">' + FLAGS.map(function (f) { return '<button class="sc-chip' + (m.flag === f[0] ? ' on' : '') + '" onclick="scMineFlag(\'' + f[0] + '\')">' + f[1] + '</button>'; }).join('') + '</div>';
    h += '<div class="sc-sel-row"><select onchange="scMinePlat(this.value)"><option value="">كل المنصات</option>'
      + m.platforms.map(function (p) { return '<option value="' + esc(p) + '"' + (m.platform === p ? ' selected' : '') + '>' + esc(p) + '</option>'; }).join('') + '</select>';
    var provs = ((S.ov && S.ov.providers) || []).filter(function (p) { return p.imported > 0; });
    if (provs.length > 1) h += '<select onchange="scMineProv(+this.value)"><option value="0">كل المزودين</option>' + provs.map(function (p) { return '<option value="' + p.id + '"' + (m.prov === p.id ? ' selected' : '') + '>' + esc(p.name) + ' (' + p.imported + ')</option>'; }).join('') + '</select>';
    h += '</div><div id="scMineList"></div>';
    $('scTabBody').innerHTML = h;
    renderMineList();
  }
  function renderMineList() {
    var m = S.mine, el = $('scMineList'); if (!el) return;
    if (m.loading && !m.items.length) { el.innerHTML = '<div class="loading">جاري التحميل...</div>'; return; }
    if (!m.items.length) { el.innerHTML = '<div class="sc-empty"><i class="fa-solid fa-box-open"></i><div>لا توجد خدمات مطابقة</div><button class="sc-btn" onclick="scTab(\'add\')"><i class="fa-solid fa-plus"></i> أضف خدمات من مزوّد</button></div>'; return; }
    var h = m.items.map(function (it, i) {
      var key = it.prov_id + '|' + it.id + '|' + it.platform;
      return '<div class="sc-card' + (it.missing ? ' miss' : '') + (m.sel[key] ? ' picked' : '') + '">'
        + '<label class="sc-ck"><input type="checkbox"' + (m.sel[key] ? ' checked' : '') + ' onchange="scPick(' + i + ',this.checked)"></label>'
        + '<div class="sc-main" onclick="scEdit(' + i + ')"><div class="sc-name">' + esc(it.name) + '</div>'
        + '<div class="sc-sub">#' + esc(it.id) + (it.prov_name ? ' · ' + esc(it.prov_name) : '') + ' · ' + esc(it.platform) + ' › ' + esc(it.category) + '</div>'
        + '<div class="sc-badges">' + badges(it) + '</div></div>'
        + '<div class="sc-price" onclick="scEdit(' + i + ')"><b>' + money(it.price) + '</b><small>لكل 1000</small>' + (it.prov_rate != null ? '<small>تكلفتك ' + money(it.prov_rate) + '</small>' : '') + '</div>'
        + '</div>';
    }).join('');
    if (m.items.length < m.total) h += '<button class="sc-more" onclick="scMineMore()">عرض المزيد (' + (m.total - m.items.length) + ')</button>';
    el.innerHTML = '<div class="sc-count">' + m.total + ' خدمة</div>' + h;
  }
  function badges(it) {
    var b = '';
    if (it.refill) b += '<span class="sc-b g"><i class="fa-solid fa-recycle"></i> تعويض</span>';
    if (it.cancel) b += '<span class="sc-b r"><i class="fa-solid fa-ban"></i> إلغاء</span>';
    if (it.edited) b += '<span class="sc-b o"><i class="fa-solid fa-pen"></i> معدّلة</span>';
    if (it.missing) b += '<span class="sc-b x"><i class="fa-solid fa-triangle-exclamation"></i> غير موجودة عند المزوّد</span>';
    return b;
  }
  function loadMine(reset) {
    var m = S.mine; if (reset) { m.page = 1; m.items = []; }
    m.loading = true; renderMineList();
    get('/api/admin/svc-ctl/mine?' + qs({ q: m.q, flag: m.flag, platform: m.platform, prov_id: m.prov, page: m.page, per: 25 })).then(function (d) {
      m.loading = false;
      if (!d.ok) { note('تعذّر التحميل', 'error'); return; }
      m.items = m.items.concat(d.items); m.total = d.total; m.stats = d.stats; m.platforms = d.platforms;
      updStats(); renderMineList(); updBar();
    }).catch(function () { m.loading = false; note('خطأ بالاتصال', 'error'); renderMineList(); });
  }
  function updStats() {   // تحديث الأرقام العلوية وقائمة المنصات دون إعادة بناء مربع البحث (حتى لا يفقد التركيز)
    var st = S.mine.stats; if (!st) return;
    var el = $('scBody').querySelector('.sc-stats'); if (!el) return;
    var t = el.querySelectorAll('b'); [st.total, st.refill, st.cancel, st.edited].forEach(function (n, i) { if (t[i]) t[i].textContent = n; });
    var sel = S.tab === 'mine' && $('scTabBody') && $('scTabBody').querySelector('.sc-sel-row select');
    if (sel && sel.options.length - 1 !== S.mine.platforms.length) {
      var q = $('scQ'), f = q && document.activeElement === q, v = q ? q.value : '';
      renderMine(); if (f) { var n = $('scQ'); n.focus(); n.setSelectionRange(v.length, v.length); }
    }
  }
  window.scMineQ = debounce(function (v) { S.mine.q = v.trim(); loadMine(true); }, 300);
  window.scMineFlag = function (f) { S.mine.flag = f; renderMine(); loadMine(true); };
  window.scMinePlat = function (p) { S.mine.platform = p; loadMine(true); };
  window.scMineProv = function (p) { S.mine.prov = p; loadMine(true); };
  window.scMineMore = function () { S.mine.page++; loadMine(false); };
  window.scPick = function (i, on) {
    var it = S.mine.items[i], key = it.prov_id + '|' + it.id + '|' + it.platform;
    if (on) S.mine.sel[key] = { id: it.id, prov_id: it.prov_id, platform: it.platform }; else delete S.mine.sel[key];
    renderMineList(); updBar();
  };

  /* شريط الإجراءات الجماعية */
  function updBar() {
    var bar = $('scBar'); if (!bar) return;
    var n = Object.keys(S.mine.sel).length;
    var open = $('adminSvcCtlPage').classList.contains('show');
    if (S.tab === 'mine' && n && open) {
      bar.innerHTML = '<div class="sc-bar-in"><div class="sc-bar-n"><b>' + n + '</b> محدّدة</div>'
        + '<div class="sc-bar-p">ربح <input type="number" id="scBulkP" value="30" min="0" max="10000"><span>%</span></div>'
        + '<button class="sc-btn sm" onclick="scBulk(\'profit\')">تطبيق</button>'
        + '<button class="sc-btn sm ghost" onclick="scBulk(\'reset_edits\')" title="إرجاع الاسم والوصف والسعر الأصلي"><i class="fa-solid fa-rotate-left"></i></button>'
        + '<button class="sc-btn sm danger" onclick="scBulk(\'hide\')" title="إخفاء من المتجر"><i class="fa-solid fa-eye-slash"></i></button>'
        + '<button class="sc-btn sm ghost" onclick="scClearSel()"><i class="fa-solid fa-xmark"></i></button></div>';
      bar.classList.add('show');
    } else if (S.tab === 'add' && S.add.mode === 'group' && Object.keys(S.add.sel).length && open) {
      var cats = Object.keys(S.add.sel), cnt = cats.reduce(function (a, k) { return a + S.add.sel[k]; }, 0);
      bar.innerHTML = '<div class="sc-bar-in"><div class="sc-bar-n"><b>' + cats.length + '</b> فئة · ' + cnt + ' خدمة</div>'
        + '<div class="sc-bar-p">ربح <input type="number" id="scGrpP" value="' + S.add.profit + '" min="0" max="10000" oninput="scAddProfit(this.value)"><span>%</span></div>'
        + '<button class="sc-btn sm" onclick="scImportGroup()"><i class="fa-solid fa-file-import"></i> استيراد</button>'
        + '<button class="sc-btn sm ghost" onclick="scClearSel()"><i class="fa-solid fa-xmark"></i></button></div>';
      bar.classList.add('show');
    } else bar.classList.remove('show');
  }
  window.scClearSel = function () { S.mine.sel = {}; S.add.sel = {}; renderTab(); updBar(); };
  window.scBulk = function (action) {
    var items = Object.keys(S.mine.sel).map(function (k) { return S.mine.sel[k]; });
    if (!items.length) return;
    var body = { action: action, items: items };
    if (action === 'profit') { body.profit_pct = $('scBulkP').value; }
    var go = function () {
      post('/api/admin/svc-ctl/bulk', body).then(function (d) {
        note(d.msg || (d.ok ? 'تم' : 'فشل'), d.ok ? 'success' : 'error');
        if (d.ok) { S.mine.sel = {}; loadMine(true); refreshOv(); }
      }).catch(function () { note('خطأ بالاتصال', 'error'); });
    };
    if (action === 'hide') { if (window.customConfirm) window.customConfirm('إخفاء ' + items.length + ' خدمة؟', 'ستختفي من المتجر ويمكن استعادتها من «الإدارة المتقدمة».', 'eye-slash', 'red', go); else go(); }
    else if (action === 'reset_edits') { if (window.customConfirm) window.customConfirm('إرجاع الأصل؟', 'سيُمسح الاسم والوصف والسعر المعدّل لـ ' + items.length + ' خدمة.', 'rotate-left', 'red', go); else go(); }
    else go();
  };
  function refreshOv() { get('/api/admin/svc-ctl/overview').then(function (d) { if (d.ok) S.ov = d; }); }

  /* ------------------------------ ورقة التعديل ------------------------------ */
  window.scEdit = function (i) {
    var it = S.mine.items[i]; if (!it) return; S.edit = { it: it, mode: 'edit' };
    openSheet(editSheet(it, 'edit'));
    bindPreview(it);
  };
  function editSheet(it, mode) {
    var imp = mode === 'import';
    var h = '<div class="sc-sh-h"><b>' + (imp ? 'استيراد خدمة' : 'تعديل الخدمة') + '</b><button class="sc-x" onclick="scSheetClose()"><i class="fa-solid fa-xmark"></i></button></div>';
    h += '<div class="sc-sh-s">#' + esc(it.id) + ' · ' + esc(it.platform) + ' › ' + esc(it.category) + '</div><div class="sc-badges" style="margin:6px 0 10px">' + badges(it) + '</div>';
    h += '<label class="sc-l">اسم الخدمة <small>(اتركه فارغاً لاستعمال اسم المزوّد)</small></label><input class="sc-in" id="scEName" maxlength="300" placeholder="' + esc(it.orig_name || it.name) + '" value="' + esc(it.custom_name || '') + '">';
    h += '<label class="sc-l">الوصف <small>(اتركه فارغاً لاستعمال وصف المزوّد)</small></label><textarea class="sc-in" id="scEDesc" rows="4" maxlength="4000" placeholder="' + esc(it.orig_desc || 'بلا وصف') + '">' + esc(it.custom_desc || '') + '</textarea>';
    h += '<div class="sc-price-box"><div class="sc-pb-h"><i class="fa-solid fa-tag"></i> التسعير <span>تكلفتك من المزوّد: <b dir="ltr">' + money(it.prov_rate) + '</b> لكل 1000</span></div>';
    h += '<div class="sc-row2"><div><label class="sc-l">نسبة الربح %</label><input class="sc-in" id="scEProfit" type="number" min="0" max="10000" step="any" value="' + (it.profit_pct == null ? '' : it.profit_pct) + '" placeholder="حسب الفئة"></div>';
    h += '<div><label class="sc-l">سعر ثابت $ / 1000</label><input class="sc-in" id="scERate" type="number" min="0" step="any" value="' + (it.custom_rate == null ? '' : it.custom_rate) + '" placeholder="اختياري"></div></div>';
    h += '<div class="sc-prev" id="scEPrev"></div><div class="sc-hint">السعر الثابت (إن وُجد) يتجاوز نسبة الربح. يظهر السعر فوراً للمستخدمين وللـ API.</div></div>';
    if (!imp) h += '<label class="sc-l">اسم الفئة المعروضة <small>(اختياري)</small></label><input class="sc-in" id="scECat" maxlength="500" value="' + esc(it.category) + '">';
    h += '<div class="sc-sh-btns">';
    if (imp) h += '<button class="sc-btn big" onclick="scDoImportOne()"><i class="fa-solid fa-file-import"></i> استيراد الخدمة</button>';
    else h += '<button class="sc-btn big" onclick="scSaveEdit()"><i class="fa-solid fa-floppy-disk"></i> حفظ</button><button class="sc-btn ghost" onclick="scResetEdit()"><i class="fa-solid fa-rotate-left"></i> الأصل</button><button class="sc-btn danger" onclick="scHideOne()"><i class="fa-solid fa-eye-slash"></i> إخفاء</button>';
    h += '</div>';
    return h;
  }
  function bindPreview(it) {
    var up = function () {
      var pe = $('scEProfit'), re = $('scERate'), out = $('scEPrev'); if (!out) return;
      var cost = it.prov_rate, rate = re.value === '' ? null : +re.value, pr = pe.value === '' ? null : +pe.value, v, how;
      if (rate != null && !isNaN(rate)) { v = rate; how = 'سعر ثابت'; }
      else if (pr != null && !isNaN(pr) && cost != null) { v = cost * (1 + pr / 100); how = 'ربح ' + pr + '%'; }
      else { v = it.price; how = 'ربح الفئة الحالي'; }
      var gain = (v != null && cost != null) ? v - cost : null;
      out.innerHTML = 'سعر المستخدم: <b dir="ltr">' + money(v) + '</b> <span>(' + how + ')</span>' + (gain != null ? ' · ربحك لكل 1000: <b dir="ltr" style="color:' + (gain < 0 ? 'var(--red)' : 'var(--green)') + '">' + money(gain) + '</b>' : '');
    };
    ['scEProfit', 'scERate'].forEach(function (id) { var e = $(id); if (e) e.addEventListener('input', up); }); up();
  }
  function openSheet(html) { $('scSheetBox').innerHTML = html; $('scSheet').classList.add('show'); }
  window.scSheetClose = function () { var s = $('scSheet'); if (s) s.classList.remove('show'); S.edit = null; };
  window.scSaveEdit = function () {
    var it = S.edit && S.edit.it; if (!it) return;
    var body = { id: it.id, prov_id: it.prov_id, platform: it.platform, name: $('scEName').value, desc: $('scEDesc').value, price: $('scERate').value, profit_pct: $('scEProfit').value };
    var cat = $('scECat').value.trim(); if (cat && cat !== it.category) body.category = cat;
    post('/api/admin/svc-ctl/update', body).then(function (d) {
      note(d.msg || (d.ok ? 'تم الحفظ' : 'فشل'), d.ok ? 'success' : 'error');
      if (d.ok) { scSheetClose(); loadMine(true); }
    }).catch(function () { note('خطأ بالاتصال', 'error'); });
  };
  window.scResetEdit = function () {
    var it = S.edit && S.edit.it; if (!it) return;
    post('/api/admin/svc-ctl/update', { id: it.id, prov_id: it.prov_id, platform: it.platform, name: '', desc: '', price: '' }).then(function (d) {
      note(d.ok ? 'أُرجع الاسم والوصف والسعر الأصلي' : (d.msg || 'فشل'), d.ok ? 'success' : 'error'); if (d.ok) { scSheetClose(); loadMine(true); }
    });
  };
  window.scHideOne = function () {
    var it = S.edit && S.edit.it; if (!it) return;
    var go = function () {
      post('/api/admin/svc-mgmt/delete', { type: 'service', items: [{ key: it.id, platform: it.platform }] }).then(function (d) {
        note(d.ok ? 'أُخفيت الخدمة' : 'فشل', d.ok ? 'success' : 'error'); if (d.ok) { scSheetClose(); loadMine(true); refreshOv(); }
      });
    };
    if (window.customConfirm) window.customConfirm('إخفاء الخدمة؟', 'ستختفي من المتجر.', 'eye-slash', 'red', go); else go();
  };

  /* ------------------------------ إضافة من مزوّد ------------------------------ */
  function renderAdd() {
    var a = S.add, provs = ((S.ov && S.ov.providers) || []).filter(function (p) { return p.active; }), h = '';
    if (!provs.length) { $('scTabBody').innerHTML = '<div class="sc-empty"><i class="fa-solid fa-plug-circle-xmark"></i><div>لا يوجد مزوّد مفعّل. أضف مزوّداً من «المزودون» أولاً.</div></div>'; return; }
    h += '<div class="sc-chips">' + provs.map(function (p) { return '<button class="sc-chip' + (a.prov === p.id ? ' on' : '') + '" onclick="scAddProv(' + p.id + ')"><i class="fa-solid fa-server"></i> ' + esc(p.name) + '</button>'; }).join('') + '</div>';
    h += '<div class="sc-seg"><button class="' + (a.mode === 'group' ? 'on' : '') + '" onclick="scAddMode(\'group\')"><i class="fa-solid fa-layer-group"></i> مجموعة خدمات (فئات)</button><button class="' + (a.mode === 'single' ? 'on' : '') + '" onclick="scAddMode(\'single\')"><i class="fa-solid fa-file-import"></i> خدمة واحدة</button></div>';
    h += '<div class="sc-search"><i class="fa-solid fa-magnifying-glass"></i><input id="scAQ" placeholder="' + (a.mode === 'group' ? 'ابحث في الفئات...' : 'ابحث بالاسم أو الرقم أو الفئة...') + '" value="' + esc(a.q) + '" oninput="scAddQ(this.value)"></div>';
    if (a.mode === 'single') h += '<div class="sc-chips"><button class="sc-chip' + (a.only === 'all' ? ' on' : '') + '" onclick="scAddOnly(\'all\')">الكل</button><button class="sc-chip' + (a.only === 'new' ? ' on' : '') + '" onclick="scAddOnly(\'new\')">غير المستوردة</button><button class="sc-chip' + (a.only === 'imported' ? ' on' : '') + '" onclick="scAddOnly(\'imported\')">المستوردة</button></div>';
    h += '<div id="scAddList"></div>';
    $('scTabBody').innerHTML = h; renderAddList();
  }
  function renderAddList() {
    var a = S.add, el = $('scAddList'); if (!el) return;
    if (a.loading && !a.cats.length) { el.innerHTML = '<div class="loading">جاري جلب خدمات المزوّد...</div>'; return; }
    if (a.err) { el.innerHTML = '<div class="sc-empty"><i class="fa-solid fa-triangle-exclamation"></i><div>' + esc(a.err) + '</div><button class="sc-btn" onclick="scAddRefresh()">إعادة المحاولة</button></div>'; return; }
    var h = '';
    if (a.mode === 'group') {
      var q = a.q.toLowerCase(), cats = a.cats.filter(function (c) { return !q || (c.name + ' ' + c.platform).toLowerCase().indexOf(q) !== -1; });
      if (!cats.length) { el.innerHTML = '<div class="sc-empty">لا توجد فئات مطابقة</div>'; return; }
      h += '<div class="sc-count">' + cats.length + ' فئة <button class="sc-link" onclick="scAddSelAll()">تحديد كل المعروض</button></div>';
      cats.forEach(function (c) {
        var on = a.sel[c.name] != null, full = c.imported >= c.count;
        h += '<label class="sc-card cat' + (on ? ' picked' : '') + '"><span class="sc-ck"><input type="checkbox" data-cat="' + esc(c.name) + '" data-n="' + c.count + '"' + (on ? ' checked' : '') + ' onchange="scAddPickCat(this)"></span>'
          + '<div class="sc-main"><div class="sc-name">' + esc(c.name) + '</div><div class="sc-sub">' + esc(c.platform) + ' · ' + c.count + ' خدمة' + (c.imported ? ' · <span style="color:var(--green)">' + c.imported + ' مستوردة</span>' : '') + '</div></div>'
          + (full ? '<span class="sc-b g"><i class="fa-solid fa-check"></i> مكتملة</span>' : '') + '</label>';
      });
    } else {
      h += '<div class="sc-count">' + a.total + ' خدمة</div>';
      if (!a.items.length) h += '<div class="sc-empty">لا توجد خدمات مطابقة</div>';
      a.items.forEach(function (it, i) {
        h += '<div class="sc-card"><div class="sc-main"><div class="sc-name">' + esc(it.name) + '</div><div class="sc-sub">#' + esc(it.id) + ' · ' + esc(it.platform) + ' › ' + esc(it.category) + ' · ' + esc(it.min) + '–' + esc(it.max) + '</div>'
          + '<div class="sc-badges">' + badges(it) + '</div></div><div class="sc-price"><b>' + money(+it.rate) + '</b><small>لكل 1000</small></div>'
          + '<button class="sc-btn sm' + (it.imported ? ' ghost' : '') + '" onclick="scImportOne(' + i + ')">' + (it.imported ? '<i class="fa-solid fa-check"></i> تعديل' : '<i class="fa-solid fa-plus"></i> استيراد') + '</button></div>';
      });
      if (a.items.length < a.total) h += '<button class="sc-more" onclick="scAddMore()">عرض المزيد (' + (a.total - a.items.length) + ')</button>';
    }
    el.innerHTML = h;
  }
  function loadAdd(reset) {
    var a = S.add; if (!a.prov) return;
    if (reset) { a.page = 1; a.items = []; if (a.mode === 'group') a.cats = a.cats.length && !a._stale ? a.cats : []; }
    a.loading = true; a.err = ''; renderAddList();
    var q = { prov_id: a.prov, page: a.page, per: 30, fresh: a._fresh ? 1 : '' }; a._fresh = false;
    if (a.mode === 'single') { q.q = a.q; q.only = a.only === 'all' ? '' : a.only; }
    get('/api/admin/svc-ctl/provider?' + qs(q)).then(function (d) {
      a.loading = false; a._stale = false;
      if (!d.ok) { a.err = d.msg || 'تعذّر جلب الخدمات'; renderAddList(); return; }
      a.cats = d.categories; a.total = d.total; a.items = a.items.concat(d.items); renderAddList(); updBar();
    }).catch(function () { a.loading = false; a.err = 'خطأ بالاتصال بالخادم'; renderAddList(); });
  }
  window.scAddProv = function (id) { S.add.prov = id; S.add.sel = {}; S.add.cats = []; S.add.items = []; renderAdd(); loadAdd(true); updBar(); };
  window.scAddMode = function (m) { S.add.mode = m; S.add.q = ''; S.add.sel = {}; renderAdd(); loadAdd(true); updBar(); };
  window.scAddQ = debounce(function (v) { S.add.q = v.trim(); if (S.add.mode === 'single') loadAdd(true); else renderAddList(); }, 300);
  window.scAddOnly = function (o) { S.add.only = o; renderAdd(); loadAdd(true); };
  window.scAddMore = function () { S.add.page++; loadAdd(false); };
  window.scAddRefresh = function () { S.add._fresh = true; S.add.cats = []; loadAdd(true); };
  window.scAddProfit = function (v) { S.add.profit = v; };
  window.scAddPickCat = function (cb) { var c = cb.getAttribute('data-cat'); if (cb.checked) S.add.sel[c] = +cb.getAttribute('data-n'); else delete S.add.sel[c]; renderAddList(); updBar(); };
  window.scAddSelAll = function () {
    var q = S.add.q.toLowerCase();
    S.add.cats.filter(function (c) { return !q || (c.name + ' ' + c.platform).toLowerCase().indexOf(q) !== -1; }).forEach(function (c) { S.add.sel[c.name] = c.count; });
    renderAddList(); updBar();
  };
  window.scImportGroup = function () {
    var cats = Object.keys(S.add.sel); if (!cats.length) return;
    var p = $('scGrpP') ? $('scGrpP').value : S.add.profit;
    post('/api/admin/svc-ctl/import', { prov_id: S.add.prov, categories: cats, profit_pct: p }).then(function (d) {
      note(d.msg || (d.ok ? 'تم' : 'فشل'), d.ok ? 'success' : 'error');
      if (d.ok) { S.add.sel = {}; S.add.cats = []; refreshOv(); loadAdd(true); loadMine(true); }
    }).catch(function () { note('خطأ بالاتصال', 'error'); });
  };
  window.scImportOne = function (i) {
    var s = S.add.items[i]; if (!s) return;
    var it = { id: s.id, prov_id: S.add.prov, platform: s.platform, category: s.category, name: s.name, orig_name: s.name, orig_desc: s.desc, custom_name: '', custom_desc: '', prov_rate: +s.rate, price: +s.rate * 1.3, profit_pct: 30, custom_rate: null, refill: s.refill, cancel: s.cancel };
    S.edit = { it: it, mode: 'import' };
    openSheet(editSheet(it, 'import')); bindPreview(it);
  };
  window.scDoImportOne = function () {
    var it = S.edit && S.edit.it; if (!it) return;
    var body = { prov_id: it.prov_id, ids: [it.id], profit_pct: $('scEProfit').value === '' ? 30 : $('scEProfit').value, name: $('scEName').value, desc: $('scEDesc').value, rate: $('scERate').value };
    post('/api/admin/svc-ctl/import', body).then(function (d) {
      note(d.msg || (d.ok ? 'تم' : 'فشل'), d.ok ? 'success' : 'error');
      if (d.ok && d.count) { scSheetClose(); refreshOv(); loadAdd(true); loadMine(true); }
    }).catch(function () { note('خطأ بالاتصال', 'error'); });
  };

  /* ------------------------------ أدوات ------------------------------ */
  function renderTools() {
    var ov = S.ov || { hidden: 0, shown: 0 };
    var h = '<div class="sc-tool"><i class="fa-solid fa-rotate" style="color:var(--primary)"></i><div><b>مزامنة مع المزوّدين</b><small>يحذف من المتجر الخدمات التي حذفها المزوّد، ويفحص التغييرات.</small></div><button class="sc-btn sm" id="scSyncBtn" onclick="scSync()">مزامنة</button></div>';
    h += '<div class="sc-tool"><i class="fa-solid fa-cloud-arrow-down" style="color:var(--green)"></i><div><b>تحديث قوائم المزوّدين</b><small>يعيد جلب خدمات المزوّد عند الإضافة (بدل الكاش لـ 3 دقائق).</small></div><button class="sc-btn sm ghost" onclick="scTab(\'add\');scAddRefresh()">تحديث</button></div>';
    h += '<div class="sc-tool"><i class="fa-solid fa-eye-slash" style="color:#f59e0b"></i><div><b>الخدمات المخفية: ' + (ov.hidden || 0) + '</b><small>استعادة المخفي، حذف منصة كاملة، وتصفير الاستيراد من الصفحة المتقدمة.</small></div><button class="sc-btn sm ghost" onclick="scOpenAdvanced()">فتح</button></div>';
    h += '<div class="sc-hint" style="margin-top:12px"><b>كيف يُكتشف التعويض والإلغاء؟</b> يقرأ النظام حقلي <code>refill</code> و<code>cancel</code> اللذين يرسلهما المزوّد لكل خدمة، فتظهر الشارات هنا، ويظهر للمستخدم زر «تعويض» أو «إلغاء» بجانب طلبه تلقائياً متى كانت خدمته تدعمهما وكان الطلب بحالة تسمح بذلك.</div>';
    $('scTabBody').innerHTML = h;
  }
  window.scSync = function () {
    var b = $('scSyncBtn'); if (b) { b.disabled = true; b.textContent = 'جارٍ...'; }
    post('/api/admin/svc-mgmt/sync', {}).then(function (d) { note(d.msg || 'تمت المزامنة', d.ok ? 'success' : 'error'); if (b) { b.disabled = false; b.textContent = 'مزامنة'; } loadMine(true); refreshOv(); }).catch(function () { note('خطأ بالاتصال', 'error'); if (b) { b.disabled = false; b.textContent = 'مزامنة'; } });
  };
  window.scOpenAdvanced = function () { scClose(); var p = $('adminSvcMgmtPage'); if (p) { p.classList.add('show'); if (window.loadSvcMgmt) window.loadSvcMgmt(); } };
})();
