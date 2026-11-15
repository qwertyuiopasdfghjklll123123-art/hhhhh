'use strict';
/**
 * بوابة واصل الخاصة — تشغّل "واتساب ويب" لأرقامك على استضافتك نفسها، فموقع واصل
 * يرسل الحملات تلقائياً بدون أي اشتراك بخدمة خارجية وبدون حدود باقات.
 *
 * التشغيل بـ cPanel (مرة وحدة): Setup Node.js App ← Create Application
 *   Node.js version: 20 أو أحدث — Application root: مجلد هذا الملف
 *   Application URL: مثلاً wasel-gw — Application startup file: app.js
 *   ← Create ← Run NPM Install ← Restart. وبعدها بموقع واصل:
 *   لوحة التحكم ← "البوابة الخاصة" ← الصق رابط التطبيق ← حفظ واختبار.
 *
 * على سيرفر عادي (VPS): npm install && WASEL_KEY=المفتاح PORT=3000 node app.js
 *
 * المفتاح السري: من متغيّر WASEL_KEY، أو من ملف wasel-key.php بنفس المجلد (موقع
 * واصل يكتبه تلقائياً إذا المجلد داخل الموقع). جلسات الواتساب تنحفظ بمجلد
 * ‎~/.wasel-sessions‎ (خارج الموقع — ما تنفتح من المتصفح أبداً).
 */

const http = require('http');
const fs = require('fs');
const os = require('os');
const path = require('path');
const crypto = require('crypto');
const { pathToFileURL } = require('url');

const VERSION = '1.0.0';
const APP_DIR = __dirname;
const SESS_DIR = process.env.WASEL_SESSIONS || path.join(os.homedir() || APP_DIR, '.wasel-sessions');
const KEY_FILE = path.join(APP_DIR, 'wasel-key.php');
const PID_FILE = path.join(SESS_DIR, 'gateway.pid');
const WA_MODULE = process.env.WASEL_WA_MODULE || 'baileys';   // للاختبار فقط يتغيّر
const TYPING = process.env.WASEL_TYPING !== '0';               // "يكتب..." قبل كل رسالة حتى يبين طبيعي

let logger;
try {
  logger = require('pino')({ level: process.env.WASEL_LOG || 'silent' });
} catch (e) {
  const noop = () => {};
  logger = { level: 'silent', trace: noop, debug: noop, info: noop, warn: noop, error: noop, fatal: noop, child() { return logger; } };
}
function log(...args) { console.error(new Date().toISOString(), '[wasel-gateway]', ...args); }

// ------------------------------------------------------------------ المفتاح السري

let keyCache = { mtime: -1, key: '' };
function fileKey() {
  try {
    const st = fs.statSync(KEY_FILE);
    if (st.mtimeMs !== keyCache.mtime) {
      const m = fs.readFileSync(KEY_FILE, 'utf8').match(/\?>\s*([A-Za-z0-9]{16,128})\s*$/);
      keyCache = { mtime: st.mtimeMs, key: m ? m[1] : '' };
    }
    return keyCache.key;
  } catch (e) {
    return '';
  }
}
function keys() {
  return [String(process.env.WASEL_KEY || '').trim(), fileKey()].filter(Boolean);
}
function sameKey(a, b) {
  const x = Buffer.from(a), y = Buffer.from(b);
  return x.length === y.length && crypto.timingSafeEqual(x, y);
}

// ------------------------------------------------------------------ نسخة وحدة بس تشتغل (حتى ما تتكرر الجلسات)

let active = false;
function pidAlive(pid) {
  try { process.kill(pid, 0); return true; } catch (e) { return e.code === 'EPERM'; }
}
function acquireLock() {
  try {
    fs.mkdirSync(SESS_DIR, { recursive: true, mode: 0o700 });
    const fd = fs.openSync(PID_FILE, 'wx');
    fs.writeSync(fd, String(process.pid));
    fs.closeSync(fd);
    return true;
  } catch (e) {
    if (e.code !== 'EEXIST') return true;   // ما نقدر نتأكد — نكمّل عادي
  }
  try {
    const pid = parseInt(fs.readFileSync(PID_FILE, 'utf8'), 10);
    const fresh = Date.now() - fs.statSync(PID_FILE).mtimeMs < 90000;
    if (pid && pid !== process.pid && fresh && pidAlive(pid)) return false;
    fs.writeFileSync(PID_FILE, String(process.pid));
  } catch (e) { /* نكمّل */ }
  return true;
}
function becomeActive() {
  if (active || !acquireLock()) return;
  active = true;
  log('active, pid', process.pid, 'sessions dir', SESS_DIR);
  restoreSessions();
}
setInterval(() => {
  if (active) {
    try { const t = new Date(); fs.utimesSync(PID_FILE, t, t); } catch (e) { /* */ }
  } else {
    becomeActive();
  }
}, 15000).unref();

// ------------------------------------------------------------------ جلسات واتساب

let wa = null;
let latestVersion = null;
async function loadWa() {
  if (!wa) {
    const spec = path.isAbsolute(WA_MODULE) || WA_MODULE.startsWith('.') ? pathToFileURL(path.resolve(WA_MODULE)).href : WA_MODULE;
    wa = await import(spec);
  }
  return wa;
}

const sessions = new Map();
const validId = id => typeof id === 'string' && /^[A-Za-z0-9_-]{3,64}$/.test(id);
const sessionDir = id => path.join(SESS_DIR, id);
const sleep = ms => new Promise(r => setTimeout(r, ms));
function withTimeout(p, ms) {
  let t;
  return Promise.race([p, new Promise((_, rej) => { t = setTimeout(() => rej(new Error('timeout')), ms); })]).finally(() => clearTimeout(t));
}
function errText(e) { return String((e && (e.message || e)) || 'error').slice(0, 200); }

function getSession(id) {
  let s = sessions.get(id);
  if (!s) {
    s = { id, state: 'idle', sock: null, creds: null, qr: null, qrUrl: null, phone: null, note: '', retry: 0, timer: null, starting: null, waiters: [], stopping: false };
    sessions.set(id, s);
  }
  return s;
}

/** الجلسة مربوطة برقم (انمسح رمزها أو انكتب رمز الربط بنجاح). */
function linkedCreds(c) { return !!(c && (c.account || c.registered)); }
function hasLinkedFiles(id) {
  try {
    return linkedCreds(JSON.parse(fs.readFileSync(path.join(sessionDir(id), 'creds.json'), 'utf8')));
  } catch (e) {
    return false;
  }
}
function isLinked(s) { return s.creds ? linkedCreds(s.creds) : hasLinkedFiles(s.id); }
function removeFiles(id) {
  try { fs.rmSync(sessionDir(id), { recursive: true, force: true }); } catch (e) { /* */ }
}

function flushWaiters(s) { s.waiters.splice(0).forEach(f => f()); }
function waitChange(s, ms) {
  if (s.state !== 'starting' && !s.starting) return Promise.resolve();
  return new Promise(resolve => {
    const done = () => { clearTimeout(t); const i = s.waiters.indexOf(done); if (i >= 0) s.waiters.splice(i, 1); resolve(); };
    const t = setTimeout(done, ms);
    s.waiters.push(done);
  });
}

async function ensureStarted(s) {
  if (s.sock) return s;
  if (s.starting) { await s.starting; return s; }
  clearTimeout(s.timer);
  s.state = 'starting';
  s.qr = null;
  s.stopping = false;
  s.starting = openSocket(s)
    .catch(e => { s.state = 'error'; s.note = 'تعذّر تشغيل الجلسة: ' + errText(e); s.sock = null; log(s.id, 'start failed', errText(e)); })
    .finally(() => { s.starting = null; });
  await s.starting;
  return s;
}

async function openSocket(s) {
  const m = await loadWa();
  const makeWASocket = typeof m.default === 'function' ? m.default : (m.default && typeof m.default.default === 'function' ? m.default.default : m.makeWASocket);
  const dir = sessionDir(s.id);
  fs.mkdirSync(dir, { recursive: true, mode: 0o700 });
  const auth = await m.useMultiFileAuthState(dir);
  s.creds = auth.state.creds;
  if (!latestVersion || Date.now() - latestVersion.at > 6 * 3600 * 1000) {
    try {
      const v = await withTimeout(m.fetchLatestBaileysVersion(), 8000);
      if (v && Array.isArray(v.version)) latestVersion = { v: v.version, at: Date.now() };
    } catch (e) { /* نستخدم النسخة الافتراضية */ }
  }
  const config = {
    auth: auth.state,
    logger,
    markOnlineOnConnect: false,
    syncFullHistory: false,
    shouldSyncHistoryMessage: () => false,
    generateHighQualityLinkPreview: false,
    connectTimeoutMs: 30000,
    defaultQueryTimeoutMs: 30000,
    keepAliveIntervalMs: 25000,
  };
  if (latestVersion) config.version = latestVersion.v;
  if (m.Browsers && typeof m.Browsers.ubuntu === 'function') config.browser = m.Browsers.ubuntu('Chrome');
  const sock = makeWASocket(config);
  s.sock = sock;
  s.gotQr = false;
  sock.ev.on('creds.update', auth.saveCreds);
  sock.ev.on('connection.update', u => onUpdate(s, sock, u));
}

function disconnectCode(err) {
  if (!err) return 0;
  if (err.output && err.output.statusCode) return err.output.statusCode;
  return err.statusCode || 0;
}

function scheduleReconnect(s, delay) {
  clearTimeout(s.timer);
  const d = delay != null ? delay : Math.min(60000, 2000 * Math.pow(2, Math.min(s.retry, 5)));
  s.retry++;
  s.timer = setTimeout(() => { ensureStarted(s).catch(() => {}); }, d);
}

function onUpdate(s, sock, u) {
  if (s.sock !== sock) return;
  if (u.qr) {
    s.qr = u.qr;
    s.qrUrl = null;
    s.gotQr = true;
    if (s.state !== 'connected') s.state = 'qr';
    flushWaiters(s);
  }
  if (u.connection === 'open') {
    const id = sock.user && sock.user.id ? String(sock.user.id) : '';
    s.state = 'connected';
    s.qr = null;
    s.retry = 0;
    s.note = '';
    if (id) s.phone = id.split('@')[0].split(':')[0];
    log(s.id, 'connected', s.phone || '');
    flushWaiters(s);
  } else if (u.connection === 'close') {
    s.sock = null;
    s.qr = null;
    const code = disconnectCode(u.lastDisconnect && u.lastDisconnect.error);
    if (s.stopping) {
      s.state = 'idle';
    } else if (code === 401) {
      // الجوال فصل الجهاز (أو فصلناه نحنا) — نمسح الجلسة وننتظر ربط جديد
      removeFiles(s.id);
      s.creds = null;
      s.phone = null;
      s.state = 'logged_out';
      s.note = 'انفصل الرقم — امسح رمز QR من جديد';
      log(s.id, 'logged out');
    } else if (code === 515) {
      scheduleReconnect(s, 300);   // طبيعي مباشرة بعد الربط
      s.state = 'starting';
    } else if (!isLinked(s) && s.gotQr) {
      s.state = 'qr_expired';      // خلصت رموز QR بدون مسح — يبدأ من جديد أول ما أحد يطلب رمز
    } else if (!isLinked(s)) {
      // ما وصلنا لواتساب أصلاً (ولا رمز QR طلع) — غالباً الاستضافة تمنع الاتصال الخارجي
      s.state = 'error';
      s.note = 'تعذّر الاتصال بخوادم واتساب من السيرفر (' + (code || 'بدون رد') + ') — تأكد إن الاستضافة تسمح بالاتصال الخارجي';
    } else if (code === 440) {
      s.state = 'error';
      s.note = 'الرقم انفتح بجلسة ثانية — نعيد الاتصال';
      scheduleReconnect(s, 30000);
    } else if (code === 403) {
      s.state = 'error';
      s.note = 'واتساب رفض الاتصال (403) — يمكن الرقم محظور';
      scheduleReconnect(s, 120000);
    } else {
      s.state = 'starting';
      scheduleReconnect(s);
    }
    if (code && code !== 515) log(s.id, 'closed', code);
    flushWaiters(s);
  }
}

function publicState(s) {
  let state = s.state;
  if (state === 'idle') state = isLinked(s) ? 'starting' : 'qr';
  if (state === 'qr_expired' || state === 'logged_out') state = 'qr';
  return { ok: true, state, phone: s.phone || null, note: s.note || '' };
}

function restoreSessions() {
  let dirs = [];
  try { dirs = fs.readdirSync(SESS_DIR, { withFileTypes: true }).filter(d => d.isDirectory()).map(d => d.name); } catch (e) { return; }
  dirs.filter(validId).forEach(id => {
    if (hasLinkedFiles(id)) {
      ensureStarted(getSession(id)).catch(() => {});
    } else {
      removeFiles(id);   // ربط ما كمل — ما له فايدة
    }
  });
}

// ------------------------------------------------------------------ واجهة HTTP (لموقع واصل)

async function apiState(s) {
  if (!s.sock && !s.starting && s.state !== 'qr_expired' && isLinked(s)) {
    await ensureStarted(s);
    await waitChange(s, 4000);
  }
  return publicState(s);
}

async function freshStart(s) {
  if (!s.sock && !s.starting) {
    if (!isLinked(s)) {
      removeFiles(s.id);
      s.creds = null;
    }
    await ensureStarted(s);
  }
  await waitChange(s, 15000);
}

async function apiQr(s) {
  if (s.state === 'connected') return publicState(s);
  await freshStart(s);
  const out = publicState(s);
  if (s.state === 'qr' && s.qr) {
    if (!s.qrUrl) s.qrUrl = await require('qrcode').toDataURL(s.qr, { margin: 1, width: 300 });
    out.qr = s.qrUrl;
  }
  return out;
}

async function apiPair(s, phone) {
  if (s.state === 'connected') return { ok: false, error: 'already_connected' };
  await freshStart(s);
  if (!s.sock || s.state !== 'qr') return { ok: false, error: 'not_ready', state: publicState(s).state };
  const code = await withTimeout(s.sock.requestPairingCode(phone), 20000);
  return { ok: true, code: String(code) };
}

async function apiSend(s, phone, text) {
  if (s.state !== 'connected' || !s.sock) {
    if (!s.sock && !s.starting && isLinked(s)) ensureStarted(s).catch(() => {});
    return { ok: false, error: 'not_connected', state: publicState(s).state };
  }
  const sock = s.sock;
  let jid = phone + '@s.whatsapp.net';
  try {
    const found = await withTimeout(sock.onWhatsApp(jid), 15000);
    if (Array.isArray(found)) {
      if (!found.length || !found[0].exists) return { ok: false, error: 'not_on_whatsapp' };
      if (found[0].jid) jid = found[0].jid;
    }
  } catch (e) { /* الفحص ما نجح — نكمّل الإرسال عادي */ }
  if (TYPING) {
    try { await sock.sendPresenceUpdate('composing', jid); } catch (e) { /* */ }
    await sleep(Math.min(4000, 700 + text.length * 25));
    try { await sock.sendPresenceUpdate('paused', jid); } catch (e) { /* */ }
  }
  const msg = await withTimeout(sock.sendMessage(jid, { text }), 30000);
  return { ok: true, id: msg && msg.key && msg.key.id ? String(msg.key.id) : null };
}

async function apiLogout(s) {
  clearTimeout(s.timer);
  const sock = s.sock;
  if (sock) {
    try { await withTimeout(sock.logout(), 10000); } catch (e) { /* */ }
    try { sock.end(undefined); } catch (e) { /* */ }
  }
  s.sock = null;
  s.creds = null;
  s.phone = null;
  s.qr = null;
  s.state = 'logged_out';
  removeFiles(s.id);
  return { ok: true };
}

function summary() {
  let connected = 0;
  sessions.forEach(s => { if (s.state === 'connected') connected++; });
  return { ok: true, name: 'wasel-gateway', version: VERSION, node: process.version, sessions: sessions.size, connected };
}

function reply(res, code, obj) {
  res.writeHead(code, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff' });
  res.end(JSON.stringify(obj));
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    let size = 0;
    const chunks = [];
    req.on('data', c => {
      size += c.length;
      if (size > 1024 * 1024) { reject(new Error('too_large')); req.destroy(); return; }
      chunks.push(c);
    });
    req.on('end', () => {
      const raw = Buffer.concat(chunks).toString('utf8');
      if (!raw) return resolve({});
      try { const j = JSON.parse(raw); resolve(j && typeof j === 'object' ? j : {}); } catch (e) { reject(new Error('bad_json')); }
    });
    req.on('error', reject);
  });
}

async function handle(req, res) {
  const url = new URL(req.url, 'http://localhost');
  const p = url.pathname;
  const at = p.indexOf('/api/');
  // الرابط يعمل تحت أي مسار (cPanel يضيف مسار التطبيق مثل /wasel-gw قبل /api/)
  if (at === -1) {
    res.writeHead(200, { 'Content-Type': 'text/plain; charset=utf-8', 'Cache-Control': 'no-store' });
    res.end('Wasel gateway is running');
    return;
  }
  const route = p.slice(at + 5).replace(/\/+$/, '');
  const allowed = keys();
  if (!allowed.length) return reply(res, 503, { ok: false, error: 'no_key' });
  const given = String(req.headers['x-wasel-key'] || '');
  if (!given || !allowed.some(k => sameKey(k, given))) return reply(res, 401, { ok: false, error: 'unauthorized' });
  if (!active) {
    becomeActive();
    if (!active) return reply(res, 503, { ok: false, error: 'busy_instance' });
  }
  if (route === 'info' || route === 'ping') return reply(res, 200, summary());

  const body = req.method === 'POST' ? await readBody(req) : {};
  const id = String(body.session || url.searchParams.get('session') || '');
  if (!validId(id)) return reply(res, 400, { ok: false, error: 'bad_session' });
  const s = getSession(id);
  const phone = String(body.phone || '').replace(/\D+/g, '');

  if (route === 'state' && req.method === 'GET') return reply(res, 200, await apiState(s));
  if (route === 'qr' && req.method === 'GET') return reply(res, 200, await apiQr(s));
  if (route === 'pair' && req.method === 'POST') {
    if (!/^\d{8,15}$/.test(phone)) return reply(res, 400, { ok: false, error: 'bad_phone' });
    return reply(res, 200, await apiPair(s, phone));
  }
  if (route === 'send' && req.method === 'POST') {
    const text = typeof body.text === 'string' ? body.text : '';
    if (!/^\d{8,15}$/.test(phone) || !text) return reply(res, 400, { ok: false, error: 'bad_request' });
    return reply(res, 200, await apiSend(s, phone, text));
  }
  if (route === 'logout' && req.method === 'POST') return reply(res, 200, await apiLogout(s));
  return reply(res, 404, { ok: false, error: 'not_found' });
}

const server = http.createServer((req, res) => {
  handle(req, res).catch(e => {
    log('request error', errText(e));
    if (!res.headersSent) reply(res, 200, { ok: false, error: 'failed', message: errText(e) });
    else res.end();
  });
});
server.requestTimeout = 120000;
server.listen(process.env.PORT || 3000, () => log('listening, version', VERSION));

becomeActive();

function shutdown() {
  sessions.forEach(s => {
    s.stopping = true;
    clearTimeout(s.timer);
    if (s.sock) { try { s.sock.end(undefined); } catch (e) { /* */ } }
  });
  if (active) { try { if (parseInt(fs.readFileSync(PID_FILE, 'utf8'), 10) === process.pid) fs.unlinkSync(PID_FILE); } catch (e) { /* */ } }
  setTimeout(() => process.exit(0), 800).unref();
}
process.on('SIGTERM', shutdown);
process.on('SIGINT', shutdown);
// خطأ بمكتبة واتساب ما لازم يوقف كل الجلسات
process.on('unhandledRejection', e => log('unhandled', errText(e)));
process.on('uncaughtException', e => log('uncaught', errText(e)));
