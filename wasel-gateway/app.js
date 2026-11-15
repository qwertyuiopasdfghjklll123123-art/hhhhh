/**
 * بوابة واتساب لمنصة واصل - تشغّل حسابات واتساب متعددة داخل عملية Node.js واحدة عبر
 * Baileys (يتصل بواتساب مباشرة بنفس بروتوكول واتساب ويب، بدون متصفح/Chrome ولا Selenium -
 * هذا هو الفرق الجوهري اللي يسمح لها تشتغل على استضافة مشتركة). موقع واصل (PHP) يتكلم مع
 * هذا الملف عبر HTTP فقط (core/lib/wa_provider.php بإعداد http_generic، وcore/routes/accounts.php
 * لعرض QR وحالة الاتصال) - لا علاقة مباشرة بقاعدة بيانات واصل من هذا الملف إطلاقاً.
 *
 * كل جلسة (session id = نفس id حساب واتساب بجدول wa_accounts بموقع واصل) محفوظة بمجلدها
 * الخاص تحت sessions/<id>/ عبر useMultiFileAuthState - تبقى متصلة بعد إعادة تشغيل التطبيق
 * (مثلاً لو Passenger أعاد تشغيله لعدم نشاط) بدون الحاجة لمسح QR من جديد.
 *
 * تنبيه صدق: هذا الكود مكتوب ومفحوص بنيوياً (تشغيل الخادم، نقاط API، التوثيق، معالجة
 * الأخطاء) لكن ما فُحص فعلياً مقابل حساب واتساب حقيقي بهذا البيئة المعزولة (ما فيه جوال
 * يمسح رمز QR هنا) - أول ربط حقيقي لحساب هو أول اختبار فعلي لبروتوكول Baileys نفسه.
 */
import express from 'express';
import { makeWASocket, useMultiFileAuthState, DisconnectReason, fetchLatestBaileysVersion } from '@whiskeysockets/baileys';
import pino from 'pino';
import QRCode from 'qrcode';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const SESSIONS_DIR = path.join(__dirname, 'sessions');
fs.mkdirSync(SESSIONS_DIR, { recursive: true });

// -------------------- الإعدادات --------------------
// تُقرأ من متغيّرات البيئة (الطريقة المفضّلة بـ DirectAdmin ← Setup Node.js App ← Environment
// variables)، وإن لم تتوفر تُقرأ من config.json بجانب هذا الملف (انسخ config.sample.json).
let fileConfig = {};
try { fileConfig = JSON.parse(fs.readFileSync(path.join(__dirname, 'config.json'), 'utf8')); } catch {}
const GATEWAY_TOKEN = process.env.GATEWAY_TOKEN || fileConfig.gateway_token || '';
const WEBHOOK_URL = process.env.WEBHOOK_URL || fileConfig.webhook_url || '';
const PORT = process.env.PORT || 3000;
const LOG_LEVEL = process.env.LOG_LEVEL || fileConfig.log_level || 'warn';

const log = pino({ level: LOG_LEVEL });
if (!GATEWAY_TOKEN) {
    log.warn('GATEWAY_TOKEN غير مضبوط - كل الطلبات سترفض حتى تضبطه (متغيّر بيئة أو config.json)');
}

// -------------------- إدارة الجلسات --------------------
/** @type {Map<string, {sock: any, status: 'connecting'|'qr'|'connected'|'disconnected', phone: string|null, qr: string|null, starting: boolean}>} */
const sessions = new Map();

function sessionDir(id) {
    // يمنع الخروج عن مجلد sessions عبر id يحتوي .. أو / (id يجي من الشبكة فلازم يُنقّى)
    const safe = String(id).replace(/[^a-zA-Z0-9_-]/g, '');
    if (!safe) throw new Error('invalid session id');
    return path.join(SESSIONS_DIR, safe);
}

async function notifyWebhook(sessionId, phone, text) {
    if (!WEBHOOK_URL) return;
    try {
        await fetch(WEBHOOK_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${GATEWAY_TOKEN}` },
            body: JSON.stringify({ session_id: sessionId, phone, text }),
        });
    } catch (e) {
        log.warn({ err: e.message, sessionId }, 'webhook delivery failed');
    }
}

async function startSession(id) {
    const existing = sessions.get(id);
    if (existing && (existing.status === 'connected' || existing.status === 'connecting' || existing.starting)) {
        return existing;
    }
    const dir = sessionDir(id);
    fs.mkdirSync(dir, { recursive: true });
    const state = existing || { sock: null, status: 'connecting', phone: null, qr: null, starting: true };
    state.status = 'connecting';
    state.starting = true;
    state.qr = null;
    sessions.set(id, state);

    const { state: authState, saveCreds } = await useMultiFileAuthState(dir);
    const { version } = await fetchLatestBaileysVersion();
    const sock = makeWASocket({ version, auth: authState, logger: log, printQRInTerminal: false });
    state.sock = sock;
    state.starting = false;

    sock.ev.on('creds.update', saveCreds);

    sock.ev.on('connection.update', (update) => {
        const { connection, lastDisconnect, qr } = update;
        if (qr) {
            state.status = 'qr';
            state.qr = qr;
        }
        if (connection === 'open') {
            state.status = 'connected';
            state.qr = null;
            state.phone = sock.user?.id ? sock.user.id.split(':')[0].split('@')[0] : null;
            log.warn({ id, phone: state.phone }, 'session connected');
        } else if (connection === 'close') {
            const statusCode = lastDisconnect?.error?.output?.statusCode;
            const loggedOut = statusCode === DisconnectReason.loggedOut;
            state.status = 'disconnected';
            state.phone = null;
            if (loggedOut) {
                log.warn({ id }, 'logged out from phone - clearing session, needs new QR');
                sessions.delete(id);
                try { fs.rmSync(dir, { recursive: true, force: true }); } catch {}
            } else {
                log.warn({ id, statusCode }, 'connection dropped - retrying in 3s');
                setTimeout(() => startSession(id).catch((e) => log.error({ err: e.message, id }, 'reconnect failed')), 3000);
            }
        }
    });

    sock.ev.on('messages.upsert', ({ messages }) => {
        for (const msg of messages) {
            if (msg.key.fromMe) continue;
            const jid = msg.key.remoteJid || '';
            if (!jid.endsWith('@s.whatsapp.net')) continue; // تجاهل رسائل القروبات/الحالات
            const phone = jid.split('@')[0];
            const text = msg.message?.conversation
                || msg.message?.extendedTextMessage?.text
                || msg.message?.imageMessage?.caption
                || '';
            if (text) notifyWebhook(id, phone, text);
        }
    });

    return state;
}

function toWhatsappJid(phone) {
    const digits = String(phone).replace(/\D/g, '');
    return `${digits}@s.whatsapp.net`;
}

// -------------------- HTTP API --------------------
const app = express();
app.use(express.json());

// بلا توثيق عمداً - تفيد لصفحة "لوحة تحكم الموقع ← الصق الرابط ← حفظ واختبار" بـ DirectAdmin
app.get('/', (req, res) => res.json({ ok: true, service: 'wasel-gateway' }));
app.get('/health', (req, res) => res.json({ ok: true }));

app.use((req, res, next) => {
    const auth = req.headers.authorization || '';
    const token = auth.startsWith('Bearer ') ? auth.slice(7) : '';
    if (!GATEWAY_TOKEN || token !== GATEWAY_TOKEN) {
        return res.status(401).json({ ok: false, error: 'مفتاح البوابة غير صحيح أو غير مضبوط' });
    }
    next();
});

app.get('/sessions', (req, res) => {
    const out = {};
    for (const [id, s] of sessions) out[id] = { status: s.status, phone: s.phone };
    res.json({ ok: true, sessions: out });
});

app.post('/sessions/:id/start', async (req, res) => {
    try {
        const s = await startSession(req.params.id);
        res.json({ ok: true, status: s.status, phone: s.phone });
    } catch (e) {
        res.status(500).json({ ok: false, error: e.message });
    }
});

app.get('/sessions/:id/status', (req, res) => {
    const s = sessions.get(req.params.id);
    if (!s) return res.json({ ok: true, status: 'disconnected', phone: null });
    res.json({ ok: true, status: s.status, phone: s.phone });
});

app.get('/sessions/:id/qr.png', async (req, res) => {
    const s = sessions.get(req.params.id);
    if (!s || !s.qr) return res.status(404).end();
    try {
        const png = await QRCode.toBuffer(s.qr, { type: 'png', width: 300, margin: 1 });
        res.set('Content-Type', 'image/png');
        res.set('Cache-Control', 'no-store');
        res.send(png);
    } catch (e) {
        res.status(500).json({ ok: false, error: e.message });
    }
});

app.post('/sessions/:id/send', async (req, res) => {
    const s = sessions.get(req.params.id);
    if (!s || s.status !== 'connected' || !s.sock) {
        return res.status(409).json({ ok: false, error: 'الحساب غير متصل حالياً' });
    }
    const { phone, text } = req.body || {};
    if (!phone || !text) return res.status(400).json({ ok: false, error: 'phone و text مطلوبين' });
    try {
        await s.sock.sendMessage(toWhatsappJid(phone), { text: String(text) });
        res.json({ ok: true });
    } catch (e) {
        res.status(502).json({ ok: false, error: e.message });
    }
});

app.post('/sessions/:id/logout', async (req, res) => {
    const id = req.params.id;
    const s = sessions.get(id);
    try {
        if (s?.sock) { try { await s.sock.logout(); } catch {} }
    } finally {
        sessions.delete(id);
        try { fs.rmSync(sessionDir(id), { recursive: true, force: true }); } catch {}
    }
    res.json({ ok: true });
});

app.delete('/sessions/:id', async (req, res) => {
    const id = req.params.id;
    const s = sessions.get(id);
    try {
        if (s?.sock) { try { s.sock.end(undefined); } catch {} }
    } finally {
        sessions.delete(id);
        try { fs.rmSync(sessionDir(id), { recursive: true, force: true }); } catch {}
    }
    res.json({ ok: true });
});

app.use((req, res) => res.status(404).json({ ok: false, error: 'not found' }));

app.listen(PORT, () => {
    console.log(`[wasel-gateway] شغّالة على المنفذ ${PORT}`);
});
