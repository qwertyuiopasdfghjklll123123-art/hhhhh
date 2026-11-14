/* واصل — يشتغل داخل تبويب واتساب ويب: يطلب من الموقع الرسالة الجاية، يفتح محادثة
   الرقم برابط send?phone=&text= (نفس طريقة النسخة السابقة)، يضغط إرسال، ويبلّغ
   الموقع بالنتيجة. الرسائل تنرسل وحدة وحدة بالفاصل الزمني المحدد بالحملة. */
(() => {
  'use strict';
  if (window.__waselSender) return;
  window.__waselSender = true;

  const WA = 'https://web.whatsapp.com';
  const JOB_TIMEOUT = 150000;     // أقصى مدة لرسالة وحدة قبل اعتبارها فاشلة
  const IDLE_BEFORE_SEND = 25000; // ما نسحب التبويب إذا المستخدم يستخدم واتساب هسه
  const sleep = ms => new Promise(r => setTimeout(r, ms));
  const fmtPhone = p => '\u2066+' + p + '\u2069';   // رقم دولي بعزل اتجاه حتى ما تنقلب علامة + بالنص العربي
  const $ = (sel, root) => (root || document).querySelector(sel);
  const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

  let busy = false;
  let stopped = false;
  let nextAt = 0;
  let qrSince = 0;
  let loadingSince = 0;
  let lastPing = 0;
  let lastUserAction = 0;

  function extensionAlive() {
    try { return !!(chrome.runtime && chrome.runtime.id); } catch (e) { return false; }
  }

  function bg(msg) {
    return new Promise(resolve => {
      if (!extensionAlive()) { resolve({ ok: false, error: 'extension_reloaded' }); return; }
      try {
        chrome.runtime.sendMessage(msg, res => {
          if (chrome.runtime.lastError) { resolve({ ok: false, error: 'bg_unavailable' }); return; }
          resolve(res || { ok: false });
        });
      } catch (e) {
        resolve({ ok: false, error: 'extension_reloaded' });
      }
    });
  }
  const log = (text, level) => bg({ type: 'log', text: text, level: level || 'info' });

  /** يبلّغ الموقع بحالة واتساب ويب: فوراً إذا تغيّرت، وإلا مرة كل 20 ثانية. */
  let lastPingState = '';
  async function pingState(state) {
    if (state === lastPingState && Date.now() - lastPing < 20000) return;
    lastPing = Date.now();
    lastPingState = state;
    await bg({ type: 'ping', state: state });
  }

  ['keydown', 'mousedown', 'touchstart', 'wheel'].forEach(t =>
    window.addEventListener(t, e => { if (e.isTrusted) lastUserAction = Date.now(); }, true));

  /* ------------------------------------------------------------ قراءة حالة واتساب ويب */

  function isLoggedIn() {
    return !!$('#pane-side, #side, [data-testid="chat-list"], [aria-label="Chat list"], [aria-label="قائمة الدردشات"], [aria-label="قائمة المحادثات"]');
  }

  function isConflict() {
    if (isLoggedIn()) return false;
    const t = (document.body && document.body.innerText) || '';
    return /use here|استخدام هنا|استخدم هنا/i.test(t);
  }

  function hasQr() {
    return !isLoggedIn() && !!$('canvas');
  }

  function detectState() {
    if (isLoggedIn()) return 'ready';
    if (isConflict()) return 'conflict';
    if (hasQr()) return 'qr';
    return 'loading';
  }

  const COMPOSE = [
    '#main footer div[contenteditable="true"][role="textbox"]',
    '#main footer div[contenteditable="true"]',
    'footer div[contenteditable="true"][data-tab="10"]',
    '#main div[contenteditable="true"][role="textbox"]',
    'div[contenteditable="true"][data-tab="10"]',
  ];

  function findCompose() {
    for (const s of COMPOSE) {
      const el = $(s);
      if (el) return el;
    }
    return null;
  }

  function textOf(el) {
    return ((el && (el.innerText || el.textContent)) || '').replace(/[​‎‏]/g, '').trim();
  }

  function findSendButton() {
    for (const name of ['send', 'wds-ic-send-filled', 'send-light']) {
      const el = $('#main footer span[data-icon="' + name + '"]') || $('footer span[data-icon="' + name + '"]');
      if (el) return el.closest('button, [role="button"]') || el;
    }
    for (const label of ['Send', 'إرسال', 'ارسال', 'Envoyer', 'Enviar', 'Gönder', 'Senden', 'ارسال پیام']) {
      const el = $('#main footer [aria-label="' + label + '"]') || $('footer [aria-label="' + label + '"]');
      if (el) return el.closest('button, [role="button"]') || el;
    }
    const byTestId = $('[data-testid="send"], [data-testid="compose-btn-send"]');
    return byTestId ? (byTestId.closest('button, [role="button"]') || byTestId) : null;
  }

  const INVALID_RE = /invalid|غير صالح|غير صحيح|isn.t on whatsapp|is not on whatsapp|not on whatsapp|غير موجود على واتساب|ليس على واتساب|ليس لديه واتساب|no está en whatsapp|n.est pas sur whatsapp/i;

  function findInvalidPopup() {
    const candidates = $$('[role="dialog"], div[data-animate-modal-popup="true"], [data-testid="popup-contents"], [data-testid="confirm-popup"]');
    for (const d of candidates) {
      if (INVALID_RE.test(d.innerText || '')) return d;
    }
    return null;
  }

  function dismissPopup(popup) {
    const btn = popup.querySelector('button, [role="button"]');
    if (btn) btn.click();
  }

  function pressEnter(el) {
    el.focus();
    const o = { key: 'Enter', code: 'Enter', keyCode: 13, which: 13, bubbles: true, cancelable: true };
    el.dispatchEvent(new KeyboardEvent('keydown', o));
    el.dispatchEvent(new KeyboardEvent('keypress', o));
    el.dispatchEvent(new KeyboardEvent('keyup', o));
  }

  /** احتياط: إذا واتساب ما عبّى النص من الرابط، نكتبه بأنفسنا (سطر سطر). */
  function typeText(el, text) {
    el.focus();
    String(text).split('\n').forEach((line, i) => {
      if (i > 0) document.execCommand('insertLineBreak');
      if (line) document.execCommand('insertText', false, line);
    });
  }

  function hasPendingClock() {
    return !!$('#main [data-icon="msg-time"]');
  }

  /* ------------------------------------------------------------ إرسال رسالة وحدة */

  async function performJob(job) {
    const started = Date.now();
    let compose = null;
    let composeSince = 0;
    let typed = false;
    while (Date.now() - started < 45000) {
      const popup = findInvalidPopup();
      if (popup) {
        dismissPopup(popup);
        return { ok: false, code: 'invalid_number', error: 'الرقم غير مسجّل على واتساب' };
      }
      if (hasQr()) return { ok: false, code: 'not_logged_in', error: 'واتساب ويب يحتاج تسجيل دخول' };
      compose = findCompose();
      if (compose) {
        if (textOf(compose)) break;
        if (!composeSince) {
          composeSince = Date.now();
        } else if (!typed && Date.now() - composeSince > 6000) {
          typeText(compose, job.text);
          typed = true;
        }
      }
      await sleep(400);
    }
    if (!compose || !textOf(compose)) {
      return { ok: false, code: 'timeout', error: 'ما انفتحت المحادثة خلال 45 ثانية' };
    }

    await sleep(900 + Math.random() * 900);   // مهلة قصيرة قبل الضغط (أقرب للاستخدام الطبيعي)
    for (let attempt = 0; attempt < 3; attempt++) {
      const btn = findSendButton();
      if (btn) btn.click(); else pressEnter(findCompose() || compose);
      const until = Date.now() + 8000;
      while (Date.now() < until) {
        await sleep(300);
        if (findInvalidPopup()) {
          dismissPopup(findInvalidPopup());
          return { ok: false, code: 'invalid_number', error: 'الرقم غير مسجّل على واتساب' };
        }
        const c = findCompose();
        if (c && !textOf(c)) {
          // ننتظر شوي حتى تطلع الرسالة من الجهاز (علامة الساعة تختفي) قبل ما ننتقل للرقم الجاي
          const clockUntil = Date.now() + 15000;
          await sleep(600);
          while (hasPendingClock() && Date.now() < clockUntil) await sleep(500);
          return { ok: true };
        }
      }
    }
    return { ok: false, code: 'not_sent', error: 'تعذّر تأكيد إرسال الرسالة' };
  }

  async function openJob(job) {
    const j = { id: job.id, phone: String(job.phone), text: String(job.text), phase: 'opened', openedAt: Date.now() };
    await chrome.storage.local.set({ job: j });
    badge('sending', j);
    await log('فتح محادثة ' + fmtPhone(j.phone));
    location.href = WA + '/send?phone=' + encodeURIComponent(j.phone) + '&text=' + encodeURIComponent(j.text);
  }

  async function finishJob(job, res) {
    const st = await chrome.storage.local.get('doneJobs');
    let done = Array.isArray(st.doneJobs) ? st.doneJobs : [];
    if (res.ok) {
      done.push(job.id);
      done = done.slice(-200);
    }
    await chrome.storage.local.set({ doneJobs: done });
    await chrome.storage.local.remove('job');
    await bg({ type: 'report', jobId: job.id, ok: !!res.ok, code: res.code || '', error: res.error || '' });
    if (res.ok) {
      await bg({ type: 'countSent' });
      await log('✓ تم الإرسال إلى ' + fmtPhone(job.phone), 'ok');
    } else {
      await log('✗ فشل الإرسال إلى ' + fmtPhone(job.phone) + ' — ' + (res.error || res.code), 'error');
    }
  }

  /* ------------------------------------------------------------ الحلقة الرئيسية */

  async function step() {
    if (busy || stopped) return;
    if (!extensionAlive()) {
      stopped = true;
      badge('stopped');
      return;
    }
    busy = true;
    try {
      const st = await chrome.storage.local.get(['job', 'paused', 'doneJobs', 'config']);
      if (!st.config || !st.config.agentKey) {
        badge('unpaired');
        nextAt = Date.now() + 10000;
        return;
      }

      let state = detectState();
      if (state === 'qr') {
        if (!qrSince) qrSince = Date.now();
        if (Date.now() - qrSince < 10000) state = 'loading';   // نتأكد إنها شاشة QR فعلاً مو تحميل
      } else {
        qrSince = 0;
      }

      if (state === 'loading') {
        if (!loadingSince) loadingSince = Date.now();
        if (Date.now() - loadingSince > 120000) {
          // واتساب ويب علق على شاشة التحميل — نعيد تحميل الصفحة
          await log('واتساب ويب علق بالتحميل — إعادة تحميل الصفحة', 'warn');
          location.reload();
          return;
        }
      } else {
        loadingSince = 0;
      }

      if (state !== 'ready') {
        badge(state);
        if (st.job && state === 'qr') {
          await finishJob(st.job, { ok: false, code: 'not_logged_in', error: 'واتساب ويب يحتاج تسجيل دخول' });
        }
        await pingState(state);
        nextAt = Date.now() + 3000;
        return;
      }

      const job = st.job;
      if (job) {
        if (job.phase === 'opened') {
          if (Date.now() - (job.openedAt || 0) > JOB_TIMEOUT) {
            await finishJob(job, { ok: false, code: 'timeout', error: 'انتهت المهلة' });
            nextAt = Date.now() + 1000;
            return;
          }
          badge('sending', job);
          const res = await performJob(job);
          await finishJob(job, res);
          nextAt = Date.now() + 500;
          return;
        }
        await openJob(job);
        return;
      }

      if (st.paused) {
        badge('paused');
        await pingState('paused');
        nextAt = Date.now() + 5000;
        return;
      }

      if (Date.now() - lastUserAction < IDLE_BEFORE_SEND) {
        badge('waiting_user');
        await pingState('ready');
        nextAt = Date.now() + 5000;
        return;
      }

      const r = await bg({ type: 'next', state: 'ready' });
      lastPing = Date.now();
      lastPingState = 'ready';
      if (r && r.ok && r.job) {
        const done = Array.isArray(st.doneJobs) ? st.doneJobs : [];
        if (done.indexOf(r.job.id) !== -1) {
          // انرسلت قبل بس التبليغ ما وصل الموقع — نبلّغ مرة ثانية بدون إعادة إرسال
          await bg({ type: 'report', jobId: r.job.id, ok: true });
          nextAt = Date.now() + 1000;
          return;
        }
        await openJob(r.job);
        return;
      }
      if (r && r.error === 'invalid_key') {
        badge('invalid_key');
        nextAt = Date.now() + 60000;
        return;
      }
      if (r && r.error === 'not_paired') {
        badge('unpaired');
        nextAt = Date.now() + 10000;
        return;
      }
      badge(r && r.ok ? 'idle' : 'error');
      const wait = r && r.ok && r.wait ? r.wait : 20;
      nextAt = Date.now() + Math.min(Math.max(wait, 2), 60) * 1000;
    } catch (e) {
      log('خطأ غير متوقع: ' + ((e && e.message) || e), 'error');
      nextAt = Date.now() + 10000;
    } finally {
      busy = false;
    }
  }

  /* ------------------------------------------------------------ شارة الحالة داخل واتساب */

  let badgeEl = null;
  const BADGE = {
    idle: ['#10B981', 'واصل: متصل — بانتظار رسائل الحملات'],
    sending: ['#38BDF8', 'واصل: جارِ الإرسال'],
    waiting_user: ['#FBBF24', 'واصل: بنكمل الإرسال لما تخلّص استخدام واتساب'],
    paused: ['#94A3B8', 'واصل: الإرسال متوقف مؤقتاً'],
    qr: ['#FBBF24', 'واصل: امسح رمز QR من جوالك حتى يبدأ الإرسال'],
    loading: ['#94A3B8', 'واصل: بانتظار تحميل واتساب ويب...'],
    conflict: ['#FBBF24', 'واصل: واتساب مفتوح بنافذة ثانية — اضغط "استخدام هنا"'],
    unpaired: ['#F87171', 'واصل: الإضافة غير مربوطة بحساب — اضغط أيقونة واصل'],
    invalid_key: ['#F87171', 'واصل: الربط ما عاد صالح — حمّل الإضافة من جديد من الموقع'],
    error: ['#F87171', 'واصل: تعذّر الاتصال بالموقع — بنحاول مرة ثانية'],
    stopped: ['#94A3B8', 'واصل: تم تحديث الإضافة — حدّث الصفحة'],
  };

  function badge(state, job) {
    if (!document.body) return;
    if (!badgeEl || !badgeEl.isConnected) {
      badgeEl = document.createElement('div');
      badgeEl.id = 'wasel-sender-badge';
      badgeEl.setAttribute('dir', 'rtl');
      badgeEl.style.cssText = 'position:fixed;left:14px;bottom:14px;z-index:2147483647;display:flex;align-items:center;gap:8px;' +
        'max-width:340px;padding:7px 12px;border-radius:12px;background:#004b52;color:#fff;opacity:.95;pointer-events:none;' +
        'font:600 12px/1.6 "Segoe UI",Tahoma,system-ui,sans-serif;box-shadow:0 6px 20px rgba(0,0,0,.25)';
      badgeEl.innerHTML = '<span style="width:8px;height:8px;border-radius:50%;flex-shrink:0"></span><span></span>';
      document.body.appendChild(badgeEl);
    }
    const b = BADGE[state] || BADGE.idle;
    badgeEl.firstChild.style.background = b[0];
    badgeEl.lastChild.textContent = state === 'sending' && job ? b[1] + ' إلى ' + fmtPhone(job.phone) : b[1];
  }

  setInterval(() => { if (Date.now() >= nextAt) step(); }, 1000);
  chrome.runtime.onMessage.addListener(msg => {
    if (!msg) return;
    if (msg.type === 'wake') nextAt = 0;
    if ((msg.type === 'tick' || msg.type === 'wake') && Date.now() >= nextAt) step();
  });
  setTimeout(step, 1500);
})();
