/* واصل — خلفية الإضافة: تتواصل مع الموقع (agent.php)، تفتح تبويب واتساب ويب وتبقيه
   شغّال، وتمرّر رسائل الحملات لسكربت content.js اللي يرسلها داخل واتساب ويب. */
'use strict';

const WA_URL = 'https://web.whatsapp.com/';
const MAX_LOG = 60;

/* ------------------------------------------------------------ الإعدادات والربط */

async function readBundledConfig() {
  try {
    const res = await fetch(chrome.runtime.getURL('config.json'), { cache: 'no-store' });
    if (!res.ok) return null;
    const c = await res.json();
    return c && c.siteUrl && c.agentKey ? c : null;
  } catch (e) {
    return null;
  }
}

/** بيانات الربط: من config.json اللي ينحط تلقائياً بملف التحميل، أو من "ربط برمز" اليدوي. */
async function getConfig() {
  const st = await chrome.storage.local.get(['config', 'bundledKey']);
  const bundled = await readBundledConfig();
  if (bundled && bundled.agentKey !== st.bundledKey) {
    // نسخة جديدة محمّلة من الموقع — نعتمد بياناتها
    await chrome.storage.local.set({ config: bundled, bundledKey: bundled.agentKey, doneJobs: [] });
    await chrome.storage.local.remove(['job']);
    return bundled;
  }
  return st.config && st.config.siteUrl && st.config.agentKey ? st.config : null;
}

async function setStatus(patch) {
  const { status } = await chrome.storage.local.get('status');
  const next = Object.assign({}, status || {}, patch);
  Object.keys(next).forEach(k => next[k] === undefined && delete next[k]);
  await chrome.storage.local.set({ status: next });
}

async function addLog(text, level) {
  const { log } = await chrome.storage.local.get('log');
  const list = Array.isArray(log) ? log : [];
  list.push({ t: Date.now(), text: String(text || '').slice(0, 220), level: level || 'info' });
  await chrome.storage.local.set({ log: list.slice(-MAX_LOG) });
}

function localDay() {
  const d = new Date();
  return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

async function countSent() {
  const { stats } = await chrome.storage.local.get('stats');
  const day = localDay();
  const s = stats && stats.day === day ? stats : { day: day, sent: 0 };
  s.sent++;
  await chrome.storage.local.set({ stats: s });
}

/* ------------------------------------------------------------ الاتصال بالموقع */

async function agentCall(action, payload) {
  const config = await getConfig();
  if (!config) return { ok: false, error: 'not_paired' };
  const url = String(config.siteUrl).replace(/\/+$/, '') + '/agent.php';
  const body = Object.assign({ action: action, key: config.agentKey, version: chrome.runtime.getManifest().version }, payload || {});
  let data;
  try {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), 20000);
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body),
      cache: 'no-store',
      signal: ctrl.signal,
    });
    clearTimeout(timer);
    data = await res.json().catch(() => ({ ok: false, error: 'bad_response_' + res.status }));
  } catch (e) {
    data = { ok: false, error: 'network' };
  }
  const patch = { lastError: data.ok ? null : (data.error || 'error') };
  if (data.ok) patch.lastContactAt = Date.now();
  if (data.account && data.account.name) patch.accountName = data.account.name;
  if (payload && payload.state) patch.waState = payload.state;
  await setStatus(patch);
  return data;
}

async function pairWithCode(code) {
  const m = String(code || '').trim().match(/^WASEL1\.([A-Za-z0-9_-]+)$/);
  if (!m) return { ok: false, error: 'الرمز غير صحيح — انسخه كامل من صفحة "حسابي" بالموقع' };
  let data;
  try {
    let b64 = m[1].replace(/-/g, '+').replace(/_/g, '/');
    while (b64.length % 4) b64 += '=';
    const bin = atob(b64);
    data = JSON.parse(new TextDecoder().decode(Uint8Array.from(bin, c => c.charCodeAt(0))));
  } catch (e) {
    return { ok: false, error: 'الرمز غير صحيح' };
  }
  if (!data || !data.u || !data.k) return { ok: false, error: 'الرمز ناقص' };
  const config = { siteUrl: data.u, agentKey: data.k, accountName: data.n || '', siteName: data.s || '', issuedAt: new Date().toISOString(), manual: true };
  await chrome.storage.local.set({ config: config, doneJobs: [] });
  await chrome.storage.local.remove(['job']);
  const r = await agentCall('hello', { state: 'loading' });
  if (!r.ok) {
    return { ok: false, error: r.error === 'invalid_key' ? 'الرمز ما عاد صالح — ولّد رمز جديد من الموقع' : 'تعذّر الاتصال بالموقع (' + (r.error || 'خطأ') + ')' };
  }
  await addLog('تم الربط بحساب: ' + (r.account ? r.account.name : ''), 'ok');
  await ensureWaTab(false);
  wakeTabs();
  return { ok: true, account: r.account, site: r.site };
}

/* ------------------------------------------------------------ تبويب واتساب ويب */

function waTabs() {
  return chrome.tabs.query({ url: 'https://web.whatsapp.com/*' });
}

async function ensureWaTab(focus) {
  const config = await getConfig();
  if (!config) return;
  const tabs = await waTabs();
  if (tabs.length) {
    if (focus) {
      await chrome.tabs.update(tabs[0].id, { active: true });
      await chrome.windows.update(tabs[0].windowId, { focused: true }).catch(() => {});
    }
    return;
  }
  const tab = await chrome.tabs.create({ url: WA_URL, pinned: true, active: !!focus });
  chrome.tabs.update(tab.id, { autoDiscardable: false }).catch(() => {});
}

function wakeTabs() {
  waTabs().then(tabs => tabs.forEach(t => chrome.tabs.sendMessage(t.id, { type: 'wake' }).catch(() => {})));
}

let lastAliveWrite = 0;
function noteContentAlive() {
  const now = Date.now();
  if (now - lastAliveWrite > 20000) {
    lastAliveWrite = now;
    chrome.storage.local.set({ lastContentAt: now });
  }
}

/** كل 30 ثانية: ننبّه سكربت واتساب (حتى لو التبويب بالخلفية)، ونعيد تحميله إذا علق. */
async function tick() {
  const config = await getConfig();
  if (!config) return;
  const tabs = await waTabs();
  if (!tabs.length) {
    await agentCall('ping', { state: 'no_tab' });
    return;
  }
  for (const t of tabs) {
    chrome.tabs.update(t.id, { autoDiscardable: false }).catch(() => {});
    chrome.tabs.sendMessage(t.id, { type: 'tick' }).catch(() => {});
  }
  const st = await chrome.storage.local.get(['paused', 'lastContentAt', 'lastWatchdogAt']);
  const silentFor = Date.now() - (st.lastContentAt || 0);
  if (!st.paused && silentFor > 4 * 60000 && Date.now() - (st.lastWatchdogAt || 0) > 10 * 60000) {
    const t = tabs[0];
    if (t.discarded || !t.active) {
      await chrome.storage.local.set({ lastWatchdogAt: Date.now() });
      await addLog('تبويب واتساب توقف عن الاستجابة — إعادة تحميله', 'warn');
      chrome.tabs.reload(t.id).catch(() => {});
    }
  }
}

async function setupAlarm() {
  const existing = await chrome.alarms.get('wasel-tick');
  if (!existing) await chrome.alarms.create('wasel-tick', { periodInMinutes: 0.5 });
}

chrome.runtime.onInstalled.addListener(async details => {
  await setupAlarm();
  const config = await getConfig();
  if (details.reason === 'install') await addLog(config ? 'تم تثبيت الإضافة ومربوطة بحساب: ' + (config.accountName || '') : 'تم تثبيت الإضافة — اربطها برمز من الموقع', 'ok');
  const tabs = await waTabs();
  if (tabs.length) {
    // نعيد تحميل واتساب حتى يشتغل سكربت النسخة الجديدة من الإضافة فيه
    tabs.forEach(t => chrome.tabs.reload(t.id).catch(() => {}));
  } else {
    await ensureWaTab(details.reason === 'install');
  }
});

chrome.runtime.onStartup.addListener(async () => {
  await setupAlarm();
  await ensureWaTab(false);
});

chrome.alarms.onAlarm.addListener(alarm => {
  if (alarm.name === 'wasel-tick') tick();
});

/* ------------------------------------------------------------ الرسائل من content.js والنافذة المنبثقة */

async function getStatus() {
  const st = await chrome.storage.local.get(['config', 'status', 'log', 'paused', 'stats']);
  const tabs = await waTabs();
  const c = st.config;
  const stats = st.stats && st.stats.day === localDay() ? st.stats : { sent: 0 };
  return {
    ok: true,
    version: chrome.runtime.getManifest().version,
    config: c ? { siteUrl: c.siteUrl, accountName: c.accountName || '', siteName: c.siteName || '' } : null,
    status: st.status || {},
    log: (st.log || []).slice(-30).reverse(),
    paused: !!st.paused,
    sentToday: stats.sent || 0,
    waTabOpen: tabs.length > 0,
  };
}

async function handle(msg, sender) {
  if (sender && sender.tab) noteContentAlive();
  switch (msg && msg.type) {
    case 'next':
      return agentCall('next', { state: msg.state || 'ready' });
    case 'report':
      return agentCall('report', { job_id: msg.jobId, ok: !!msg.ok, code: msg.code || '', error: msg.error || '' });
    case 'ping':
      return agentCall('ping', { state: msg.state || 'loading' });
    case 'log':
      await addLog(msg.text, msg.level);
      return { ok: true };
    case 'countSent':
      await countSent();
      return { ok: true };
    case 'getStatus':
      await getConfig();
      return getStatus();
    case 'pair':
      return pairWithCode(msg.code);
    case 'unpair':
      await chrome.storage.local.remove(['config', 'job']);
      await addLog('تم إلغاء الربط', 'warn');
      return { ok: true };
    case 'setPaused':
      await chrome.storage.local.set({ paused: !!msg.paused });
      await addLog(msg.paused ? 'تم إيقاف الإرسال مؤقتاً' : 'تم استئناف الإرسال', 'info');
      wakeTabs();
      return { ok: true };
    case 'openWhatsApp':
      await ensureWaTab(true);
      return { ok: true };
    case 'testConnection': {
      const st = await chrome.storage.local.get('status');
      return agentCall('hello', { state: (st.status && st.status.waState) || 'loading' });
    }
    default:
      return { ok: false, error: 'unknown_message' };
  }
}

chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  handle(msg, sender).then(sendResponse, err => sendResponse({ ok: false, error: String((err && err.message) || err) }));
  return true;
});
