/* صفحتا الأدمن: «استيراد وتصدير البيانات» و«مركز الأمان» (واجهتهما بـ dash.html، ونقاطهما: routes/transfer.php و routes/security.php) */
(function () {
  'use strict';
  var csrf = '', job = null, tgBusy = false;
  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function note(m, t) { if (window.toast) window.toast(m, t || 'info'); }
  function api(path, opt) {
    opt = opt || {};
    opt.credentials = 'same-origin';
    opt.headers = Object.assign({ 'X-CSRF-Token': csrf }, opt.headers || {});
    return fetch(path, opt);
  }
  function jpost(path, body) { return api(path, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }); }
  function fmtBytes(n) { n = +n || 0; return n >= 1048576 ? (n / 1048576).toFixed(1) + ' MB' : n >= 1024 ? (n / 1024).toFixed(1) + ' KB' : n + ' B'; }
  function fmtTime(t) {
    var d = new Date(t * 1000), p = function (x) { return String(x).padStart(2, '0'); };
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
  }
  var TYPE_LABEL = { dbjson: 'قاعدة بيانات (ملف JSON واحد)', sql: 'قاعدة بيانات (SQL)', sqlite: 'قاعدة SQLite (موقع بايثون)', vapid: 'مفاتيح VAPID للإشعارات', push_subs: 'اشتراكات الإشعارات', services_cache: 'كاش الخدمات' };
  var TYPE_ICON = { dbjson: 'fa-file-code', sql: 'fa-database', sqlite: 'fa-database', vapid: 'fa-key', push_subs: 'fa-bell', services_cache: 'fa-list' };

  /* ------------------------------ استيراد وتصدير ------------------------------ */

  window.loadTransferPage = function () {
    fetch('/api/admin/transfer/status', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok) { note(d.msg || 'غير مصرح', 'error'); return; }
      csrf = d.csrf;
      $('trfLimit').textContent = 'الحد الأقصى لحجم الملف على هذا الخادم: ' + d.limits.max_upload_h;
      // نسخ تلغرام
      var tg = d.telegram || {}, card = $('trfTgCard');
      if (tg.configured) {
        card.style.display = '';
        $('trfTgList').innerHTML = (tg.items && tg.items.length) ? tg.items.map(function (x) {
          return '<div class="bk-item"><div class="bk-ic" style="background:rgba(0,136,204,.1);color:#0088cc"><i class="fa-brands fa-telegram"></i></div><div class="bk-info"><div class="bk-name">' + esc(x.name) + '</div><div class="bk-time">' + fmtTime(x.time) + ' · ' + fmtBytes(x.size) + '</div></div>'
            + '<button class="btn-outline" style="width:auto;padding:5px 12px;margin:0;font-size:11px" data-fid="' + esc(x.file_id) + '" onclick="trfFetchTg(this)">جلب</button></div>';
        }).join('') : '<div class="loading" style="font-size:11px">لا نسخ مرسلة بعد (تظهر هنا بعد أول نسخة تُرسل من هذا الموقع). النسخ القديمة نزّلها من تلغرام وارفعها بالأعلى.</div>';
      } else card.style.display = 'none';
      // نسخ محلية
      var L = d.local_backups || [];
      $('trfLocalList').innerHTML = L.length ? L.map(function (b) {
        return '<div class="bk-item"><div class="bk-ic"><i class="fa-solid fa-database"></i></div><div class="bk-info"><div class="bk-name">' + esc(b.name) + '</div><div class="bk-time">' + fmtTime(b.time) + '</div></div><div class="bk-size">' + fmtBytes(b.size) + '</div>'
          + '<button class="btn-outline" style="width:auto;padding:5px 12px;margin:0;font-size:11px" data-bk="' + esc(b.name) + '" onclick="trfLocal(this)">استيراد</button></div>';
      }).join('') : '<div class="loading" style="font-size:11px">لا توجد نسخ محلية بعد</div>';
    }).catch(function () { note('تعذّر تحميل الصفحة', 'error'); });
  };

  window.trfExport = function () {
    var kind = (document.querySelector('input[name=trfKind]:checked') || {}).value, pw = $('trfExpPw').value;
    if (!pw) { note('اكتب كلمة مرور حسابك للتأكيد', 'error'); return; }
    var btn = $('trfExpBtn'); btn.disabled = true;
    note('جاري تجهيز الملف…', 'info');
    jpost('/api/admin/transfer/export', { kind: kind, password: pw }).then(function (r) {
      if (!r.ok) return r.json().then(function (j) { throw new Error(j.msg || 'تعذّر التصدير'); }, function () { throw new Error('تعذّر التصدير'); });
      var cd = r.headers.get('Content-Disposition') || '', m = /filename="?([^";]+)"?/.exec(cd);
      return r.blob().then(function (b) {
        var a = document.createElement('a'); a.href = URL.createObjectURL(b); a.download = m ? m[1] : 'fastcrand_backup';
        document.body.appendChild(a); a.click();
        setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 4000);
        $('trfExpPw').value = ''; note('بدأ التنزيل (' + fmtBytes(b.size) + ')', 'success');
      });
    }).catch(function (e) { note(e.message, 'error'); }).then(function () { btn.disabled = false; });
  };

  function resetImport() {
    job = null; $('trfAnalysis').style.display = 'none'; $('trfApplyBox').style.display = 'none'; $('trfReport').style.display = 'none';
    $('trfProg').style.display = 'none'; $('trfDropTxt').textContent = 'اضغط لاختيار ملف النسخة'; $('trfFile').value = '';
    $('trfApplyPw').value = ''; $('trfConfirm').value = '';
  }

  window.trfPick = function (file) {
    if (!file) return;
    resetImport();
    $('trfDropTxt').textContent = file.name + ' — ' + fmtBytes(file.size);
    var fd = new FormData(); fd.append('file', file);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', '/api/admin/transfer/analyze'); xhr.withCredentials = true; xhr.setRequestHeader('X-CSRF-Token', csrf);
    $('trfProg').style.display = ''; $('trfProgBar').style.width = '0'; $('trfProgTxt').textContent = 'جاري الرفع…';
    xhr.upload.onprogress = function (e) { if (e.lengthComputable) { var p = Math.round(e.loaded / e.total * 100); $('trfProgBar').style.width = p + '%'; $('trfProgTxt').textContent = 'رفع ' + p + '%'; } };
    xhr.upload.onload = function () { $('trfProgTxt').textContent = 'جاري فحص الملف…'; };
    xhr.onload = function () {
      $('trfProg').style.display = 'none';
      var d = null; try { d = JSON.parse(xhr.responseText); } catch (e) {}
      if (!d || !d.ok) { showAnalysisError((d && d.msg) || (xhr.status === 413 ? 'الملف أكبر من حد الخادم' : 'تعذّر فحص الملف')); return; }
      showAnalysis(d);
    };
    xhr.onerror = function () { $('trfProg').style.display = 'none'; showAnalysisError('انقطع الاتصال أثناء الرفع'); };
    xhr.send(fd);
  };

  function showAnalysisError(msg) {
    var a = $('trfAnalysis'); a.style.display = ''; a.innerHTML = '<div class="trf-bad"><i class="fa-solid fa-circle-xmark"></i> ' + esc(msg) + '</div>';
    $('trfApplyBox').style.display = 'none';
  }

  function showAnalysis(d) {
    job = d;
    var h = '<div class="trf-ok" style="margin-bottom:8px"><i class="fa-solid fa-circle-check"></i> الملف سليم وجاهز للاستيراد: <b dir="ltr">' + esc(d.source) + '</b></div>';
    d.components.forEach(function (c) {
      h += '<div class="trf-comp"><b><i class="fa-solid ' + (TYPE_ICON[c.type] || 'fa-file') + '" style="color:var(--primary)"></i> ' + esc(TYPE_LABEL[c.type] || c.type) + '</b> <span style="color:var(--text3)">· ' + esc(c.size_h) + '</span>';
      if (c.tables) {
        var names = Object.keys(c.tables).filter(function (k) { return c.tables[k].rows > 0; });
        var total = names.reduce(function (s, k) { return s + c.tables[k].rows; }, 0);
        h += '<div style="margin-top:4px;color:var(--text2)">' + names.length + ' جدولاً بها بيانات — ' + total + ' صفاً</div><div class="trf-tags">'
          + names.map(function (k) { return '<span class="trf-tag">' + esc(k) + ' ' + c.tables[k].rows + '</span>'; }).join('') + '</div>';
        if (c.unknown_tables && c.unknown_tables.length) h += '<div class="trf-warn">جداول لا تخص الموقع وسيتم تجاهلها: ' + c.unknown_tables.map(esc).join('، ') + '</div>';
      }
      if (c.subscriptions != null) h += '<div style="margin-top:4px;color:var(--text2)">' + c.subscriptions + ' اشتراك لـ ' + c.users + ' مستخدم</div>';
      if (c.services != null) h += '<div style="margin-top:4px;color:var(--text2)">' + c.services + ' خدمة</div>';
      h += '</div>';
    });
    (d.warnings || []).forEach(function (w) { h += '<div class="trf-warn">' + esc(w) + '</div>'; });
    var a = $('trfAnalysis'); a.style.display = ''; a.innerHTML = h;
    var hasJson = d.components.some(function (c) { return c.type === 'vapid' || c.type === 'push_subs' || c.type === 'services_cache'; });
    var o = '';
    if (d.has_db) o += '<label class="trf-opt"><input type="checkbox" id="trfOptDb" checked><span><b>استعادة قاعدة البيانات</b><small>تُستبدل الجداول الموجودة بالنسخة (الجداول غير الموجودة بالنسخة تبقى كما هي)</small></span></label>';
    if (hasJson) o += '<label class="trf-opt"><input type="checkbox" id="trfOptJson" checked><span><b>استعادة ملفات JSON</b><small>مفاتيح VAPID واشتراكات الإشعارات وكاش الخدمات</small></span></label>';
    if (d.has_both_db) {
      var dbOpts = { dbjson: 'ملف JSON الشامل', sqlite: 'قاعدة SQLite (موقع بايثون)', sql: 'ملف SQL' }, os = '';
      ['dbjson', 'sqlite', 'sql'].forEach(function (t) { if (d.components.some(function (c) { return c.type === t; })) os += '<option value="' + t + '">' + dbOpts[t] + '</option>'; });
      o += '<div class="field-group" style="margin:6px 0 0"><div class="field-label">المصدر عند وجود أكثر من قاعدة</div><select class="text-input" id="trfPrefer">' + os + '</select></div>';
    }
    $('trfOptRows').innerHTML = o;
    $('trfApplyBox').style.display = '';
  }

  window.trfCancel = function () {
    if (job) jpost('/api/admin/transfer/cancel', { token: job.token }).catch(function () {});
    resetImport();
  };

  window.trfApply = function () {
    if (!job) return;
    var pw = $('trfApplyPw').value, cf = $('trfConfirm').value.trim();
    if (!pw) { note('اكتب كلمة مرور حسابك', 'error'); return; }
    if (cf !== 'RESTORE') { note('اكتب RESTORE للتأكيد', 'error'); return; }
    var body = { token: job.token, password: pw, confirm: 'RESTORE', restore_db: !!($('trfOptDb') && $('trfOptDb').checked), restore_json: !!($('trfOptJson') && $('trfOptJson').checked) };
    if ($('trfPrefer')) body.prefer = $('trfPrefer').value;
    if (!body.restore_db && !body.restore_json) { note('اختر ما تريد استعادته', 'error'); return; }
    var btn = $('trfApplyBtn'); btn.disabled = true; btn.innerHTML = '<img class="fc-spin" src="/pwa-icon-192.png" alt=""> جاري الاستيراد… لا تغلق الصفحة';
    jpost('/api/admin/transfer/apply', body).then(function (r) { return r.json().catch(function () { return { ok: false, msg: 'ردّ غير متوقع من الخادم (قد يكون الاستيراد تجاوز مهلة الخادم؛ راجع النسخ المحلية)' }; }); }).then(function (d) {
      btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-rotate-left"></i> ابدأ الاستيراد';
      var box = $('trfReport'); box.style.display = '';
      if (!d.ok) { box.innerHTML = '<div class="trf-bad"><i class="fa-solid fa-circle-xmark"></i> ' + esc(d.msg || 'فشل الاستيراد') + '</div>'; return; }
      var rep = d.report || {}, h = '<div class="trf-ok"><i class="fa-solid fa-circle-check"></i> <b>تم الاستيراد بنجاح</b>'
        + (rep.safety_backup ? '<br>نسخة الأمان قبل الاستيراد: <b dir="ltr">' + esc(rep.safety_backup) + '</b>' : '') + '</div>';
      if (rep.tables && rep.tables.length) h += '<div class="trf-tags" style="margin-top:8px">' + rep.tables.filter(function (t) { return t.rows != null; }).map(function (t) { return '<span class="trf-tag">' + esc(t.table) + ' ' + t.rows + '</span>'; }).join('') + '</div>';
      (rep.warnings || []).forEach(function (w) { h += '<div class="trf-warn">' + esc(w) + '</div>'; });
      if (d.relogin) h += '<div class="trf-warn" style="background:rgba(99,102,241,.08);color:var(--primary)">أُلغيت كل الجلسات لأن المستخدمين قد يختلفون في النسخة. ستنتقل لتسجيل الدخول بعد لحظات…</div>';
      box.innerHTML = h; $('trfApplyBox').style.display = 'none'; $('trfAnalysis').style.display = 'none'; job = null;
      if (d.relogin) setTimeout(function () { location.href = '/'; }, 4500);
    }).catch(function () { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-rotate-left"></i> ابدأ الاستيراد'; note('انقطع الاتصال. تحقق من النسخ المحلية قبل إعادة المحاولة', 'error'); });
  };

  window.trfFetchTg = function (btn) {
    if (tgBusy) return; tgBusy = true; var old = btn.innerHTML; btn.disabled = true; btn.innerHTML = '<img class="fc-spin" src="/pwa-icon-192.png" alt="">';
    resetImport(); $('trfDropTxt').textContent = 'جاري الجلب من تلغرام…';
    jpost('/api/admin/transfer/telegram-fetch', { file_id: btn.getAttribute('data-fid') }).then(function (r) { return r.json(); }).then(function (d) {
      $('trfDropTxt').textContent = 'اضغط لاختيار ملف النسخة';
      if (!d.ok) showAnalysisError(d.msg || 'تعذّر الجلب'); else showAnalysis(d);
      $('trfAnalysis').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }).catch(function () { showAnalysisError('تعذّر الاتصال'); }).then(function () { tgBusy = false; btn.disabled = false; btn.innerHTML = old; });
  };

  window.trfLocal = function (btn) {
    resetImport();
    jpost('/api/admin/transfer/analyze-local', { name: btn.getAttribute('data-bk') }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok) showAnalysisError(d.msg || 'تعذّر تحليل النسخة'); else showAnalysis(d);
      $('trfAnalysis').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }).catch(function () { showAnalysisError('تعذّر الاتصال'); });
  };

  /* ------------------------------ مركز الأمان ------------------------------ */

  var LV_ICON = { ok: 'fa-circle-check', warn: 'fa-triangle-exclamation', bad: 'fa-circle-xmark', info: 'fa-circle-info' };

  window.loadSecurityPage = function () {
    fetch('/api/admin/security/status', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok) { note(d.msg || 'غير مصرح', 'error'); return; }
      csrf = d.csrf;
      var c = d.counts || {};
      $('secSummary').innerHTML = (c.bad ? '<span style="color:var(--red);font-weight:800">' + c.bad + ' خطر</span> · ' : '') + (c.warn ? '<span style="color:#d97706;font-weight:800">' + c.warn + ' تنبيه</span> · ' : '') + '<span style="color:var(--green);font-weight:800">' + (c.ok || 0) + ' سليم</span>';
      var order = { bad: 0, warn: 1, info: 2, ok: 3 };
      $('secChecks').innerHTML = d.checks.slice().sort(function (a, b) { return order[a.level] - order[b.level]; }).map(function (x) {
        return '<div class="sec-row"><div class="si ' + esc(x.level) + '"><i class="fa-solid ' + (LV_ICON[x.level] || 'fa-circle') + '"></i></div><div><div class="st">' + esc(x.title) + '</div>' + (x.detail ? '<div class="sd">' + esc(x.detail) + '</div>' : '') + '</div></div>';
      }).join('');
      var A = d.audit || [];
      $('secAudit').innerHTML = A.length ? A.map(function (e) {
        var what = esc(e.event) + (e.path ? ' — ' + esc((e.m || '') + ' ' + e.path) : '') + (e.status ? ' (' + esc(e.status) + ')' : '') + (e.kind ? ' [' + esc(e.kind) + ']' : '') + (e.amount != null ? ' مبلغ ' + esc(e.amount) : '');
        return '<div class="aud-row"><div class="at">' + esc(String(e.t || '').replace('T', ' ').slice(0, 19)) + '</div><div class="ae">' + what + '</div><div class="ai">IP ' + esc(e.ip || '') + ' · user ' + esc(e.uid == null ? '-' : e.uid) + '</div></div>';
      }).join('') : '<div class="loading" style="font-size:11px">لا نشاط مسجَّل بعد</div>';
    }).catch(function () { note('تعذّر تحميل مركز الأمان', 'error'); });
  };

  window.secProbe = function () {
    var b = $('secProbeBtn'); b.disabled = true; $('secProbeRes').innerHTML = '<div class="loading"><img class="fc-spin" src="/pwa-icon-192.png" alt=""> جاري الفحص…</div>';
    jpost('/api/admin/security/probe', {}).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok) { $('secProbeRes').innerHTML = '<div class="trf-bad">' + esc(d.msg || 'تعذّر الفحص') + '</div>'; return; }
      $('secProbeRes').innerHTML = d.results.map(function (x) {
        var lv = x.state === 'blocked' ? 'ok' : x.state === 'exposed' ? 'bad' : 'warn';
        var txt = x.state === 'blocked' ? 'محمي (' + x.code + ')' : x.state === 'exposed' ? 'مكشوف! (200) — اضبط الخادم فوراً' : 'تعذّر الفحص من الخادم نفسه';
        return '<div class="sec-row"><div class="si ' + lv + '"><i class="fa-solid ' + LV_ICON[lv] + '"></i></div><div><div class="st">' + esc(x.label) + ' <span dir="ltr" style="font-weight:400;color:var(--text3)">' + esc(x.path) + '</span></div><div class="sd">' + txt + '</div></div></div>';
      }).join('');
    }).catch(function () { $('secProbeRes').innerHTML = '<div class="trf-bad">تعذّر الاتصال</div>'; }).then(function () { b.disabled = false; });
  };

  window.secRevoke = function () {
    var pw = $('secRevokePw').value;
    if (!pw) { note('اكتب كلمة مرور حسابك', 'error'); return; }
    if (!window.confirm('سيُخرَج كل المستخدمين (وأنت). متابعة؟')) return;
    jpost('/api/admin/security/revoke-sessions', { password: pw }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok) { note(d.msg || 'تعذّر', 'error'); return; }
      note(d.msg, 'success'); setTimeout(function () { location.href = '/'; }, 1200);
    }).catch(function () { note('تعذّر الاتصال', 'error'); });
  };
})();
