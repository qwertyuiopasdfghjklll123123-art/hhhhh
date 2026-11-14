'use strict';

const WA_STATES = {
  ready: ['جاهز ✓', 'ok'],
  qr: ['يحتاج مسح رمز QR', 'warn'],
  loading: ['جارِ التحميل...', 'warn'],
  conflict: ['مفتوح بنافذة ثانية', 'warn'],
  paused: ['متوقف مؤقتاً', 'warn'],
  no_tab: ['التبويب مسكّر', 'bad'],
};

const ERRORS = {
  network: 'تعذّر الوصول للموقع',
  invalid_key: 'الربط ما عاد صالح — حمّل الإضافة من جديد',
  not_paired: 'غير مربوطة',
};

function send(msg) {
  return new Promise(resolve => chrome.runtime.sendMessage(msg, r => resolve(r || { ok: false })));
}

function setText(id, text, cls) {
  const el = document.getElementById(id);
  el.textContent = text;
  el.className = cls || '';
}

function ago(ts) {
  if (!ts) return '';
  const s = Math.round((Date.now() - ts) / 1000);
  if (s < 60) return 'قبل ' + Math.max(1, s) + ' ث';
  if (s < 3600) return 'قبل ' + Math.round(s / 60) + ' د';
  return 'قبل ' + Math.round(s / 3600) + ' س';
}

let paused = false;

async function refresh() {
  const st = await send({ type: 'getStatus' });
  if (!st.ok) return;
  document.getElementById('version').textContent = 'نسخة ' + st.version;
  if (st.config) {
    setText('siteLine', (st.config.siteName ? st.config.siteName + ' — ' : '') + st.config.siteUrl);
    setText('accName', st.config.accountName || '—');
    document.getElementById('pairBox').open = false;
  } else {
    setText('siteLine', 'الإضافة غير مربوطة بأي حساب');
    setText('accName', 'غير مربوطة', 'bad');
    document.getElementById('pairBox').open = true;
  }
  const s = st.status || {};
  if (!st.config) setText('connState', '—');
  else if (s.lastError) setText('connState', ERRORS[s.lastError] || ('خطأ: ' + s.lastError), 'bad');
  else if (s.lastContactAt) setText('connState', 'متصل ✓ ' + ago(s.lastContactAt), 'ok');
  else setText('connState', 'جارِ الاتصال...', 'warn');

  if (!st.waTabOpen) setText('waState', WA_STATES.no_tab[0], WA_STATES.no_tab[1]);
  else {
    const w = WA_STATES[s.waState] || ['مفتوح', ''];
    setText('waState', w[0], w[1]);
  }
  setText('sentToday', String(st.sentToday || 0));

  paused = st.paused;
  document.getElementById('pauseBtn').textContent = paused ? 'استئناف الإرسال' : 'إيقاف مؤقت';

  const ul = document.getElementById('log');
  ul.innerHTML = '';
  if (!st.log.length) {
    ul.innerHTML = '<li class="empty">ما فيه عمليات بعد</li>';
  } else {
    st.log.forEach(item => {
      const li = document.createElement('li');
      li.className = item.level || 'info';
      const t = document.createElement('time');
      const d = new Date(item.t);
      t.textContent = String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
      const span = document.createElement('span');
      span.textContent = item.text;
      li.appendChild(t);
      li.appendChild(span);
      ul.appendChild(li);
    });
  }
}

document.getElementById('openWa').addEventListener('click', () => send({ type: 'openWhatsApp' }));

document.getElementById('pauseBtn').addEventListener('click', async () => {
  await send({ type: 'setPaused', paused: !paused });
  refresh();
});

document.getElementById('pairBtn').addEventListener('click', async () => {
  const msg = document.getElementById('pairMsg');
  msg.className = 'msg';
  msg.textContent = 'جارِ الربط...';
  const r = await send({ type: 'pair', code: document.getElementById('pairCode').value });
  if (r.ok) {
    msg.className = 'msg ok';
    msg.textContent = 'تم الربط بحساب: ' + (r.account ? r.account.name : '');
    document.getElementById('pairCode').value = '';
  } else {
    msg.className = 'msg bad';
    msg.textContent = r.error || 'فشل الربط';
  }
  refresh();
});

document.getElementById('testBtn').addEventListener('click', async () => {
  const r = await send({ type: 'testConnection' });
  setText('connState', r.ok ? 'متصل ✓ الآن' : (ERRORS[r.error] || ('خطأ: ' + (r.error || ''))), r.ok ? 'ok' : 'bad');
});

refresh();
setInterval(refresh, 2000);
