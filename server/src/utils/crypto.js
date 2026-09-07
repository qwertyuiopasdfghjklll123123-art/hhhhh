const crypto = require('crypto');

const ALGORITHM = 'aes-256-gcm';

function getKey() {
  const hex = process.env.SETTINGS_ENCRYPTION_KEY;
  if (!hex || hex.length !== 64) {
    throw new Error(
      'SETTINGS_ENCRYPTION_KEY يجب أن يكون سلسلة hex بطول 64 محرف (32 بايت). ' +
        "ولّدها بأمر: node -e \"console.log(require('crypto').randomBytes(32).toString('hex'))\""
    );
  }
  return Buffer.from(hex, 'hex');
}

// يشفّر نصاً عادياً (مثل مفتاح DeepSeek) قبل تخزينه في قاعدة البيانات
function encrypt(plainText) {
  const iv = crypto.randomBytes(12);
  const cipher = crypto.createCipheriv(ALGORITHM, getKey(), iv);
  const encrypted = Buffer.concat([cipher.update(String(plainText), 'utf8'), cipher.final()]);
  const authTag = cipher.getAuthTag();
  return [iv.toString('hex'), authTag.toString('hex'), encrypted.toString('hex')].join(':');
}

// يفك تشفير القيمة المخزنة لاستخدامها الفعلي عند مناداة DeepSeek
function decrypt(payload) {
  if (!payload) return null;
  const [ivHex, tagHex, dataHex] = String(payload).split(':');
  if (!ivHex || !tagHex || !dataHex) return null;
  const decipher = crypto.createDecipheriv(ALGORITHM, getKey(), Buffer.from(ivHex, 'hex'));
  decipher.setAuthTag(Buffer.from(tagHex, 'hex'));
  const decrypted = Buffer.concat([
    decipher.update(Buffer.from(dataHex, 'hex')),
    decipher.final(),
  ]);
  return decrypted.toString('utf8');
}

// يعرض آخر 4 محارف فقط من المفتاح لغرض العرض في واجهة الإعدادات
function maskKey(plainKey) {
  if (!plainKey) return null;
  const tail = plainKey.slice(-4);
  return `${'*'.repeat(Math.max(plainKey.length - 4, 4))}${tail}`;
}

module.exports = { encrypt, decrypt, maskKey };
