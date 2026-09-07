const { query } = require('../config/db');
const { encrypt, decrypt, maskKey } = require('../utils/crypto');
const ApiError = require('../utils/ApiError');

// يقرأ إعداد مزوّد الذكاء الاصطناعي من قاعدة البيانات (المصدر الأساسي)
// ويسقط احتياطياً على متغيرات البيئة إن لم يوجد مفتاح محفوظ في لوحة التحكم
async function getActiveConfig(provider = 'deepseek') {
  const rows = await query(
    'SELECT * FROM api_settings WHERE provider = :provider LIMIT 1',
    { provider }
  );
  const row = rows[0];

  const fallback = {
    apiKey: process.env.DEEPSEEK_API_KEY || null,
    baseUrl: process.env.DEEPSEEK_API_BASE_URL || 'https://api.deepseek.com',
    model: process.env.DEEPSEEK_MODEL || 'deepseek-chat',
    source: 'env',
  };

  if (!row) return fallback;

  const dbKey = row.api_key_encrypted ? decrypt(row.api_key_encrypted) : null;

  return {
    apiKey: dbKey || fallback.apiKey,
    baseUrl: row.api_base_url || fallback.baseUrl,
    model: row.model_name || fallback.model,
    source: dbKey ? 'database' : 'env',
  };
}

async function getMaskedSettings(provider = 'deepseek') {
  const config = await getActiveConfig(provider);
  return {
    provider,
    apiBaseUrl: config.baseUrl,
    model: config.model,
    hasApiKey: Boolean(config.apiKey),
    maskedApiKey: config.apiKey ? maskKey(config.apiKey) : null,
    source: config.source,
  };
}

async function saveSettings({ provider = 'deepseek', apiKey, baseUrl, model, userId }) {
  if (!apiKey || apiKey.trim().length < 10) {
    throw new ApiError(400, 'مفتاح API غير صالح');
  }
  const encrypted = encrypt(apiKey.trim());

  await query(
    `INSERT INTO api_settings (provider, api_key_encrypted, api_base_url, model_name, is_active, updated_by)
     VALUES (:provider, :encrypted, :baseUrl, :model, 1, :userId)
     ON DUPLICATE KEY UPDATE
       api_key_encrypted = VALUES(api_key_encrypted),
       api_base_url = VALUES(api_base_url),
       model_name = VALUES(model_name),
       updated_by = VALUES(updated_by)`,
    {
      provider,
      encrypted,
      baseUrl: baseUrl || 'https://api.deepseek.com',
      model: model || 'deepseek-chat',
      userId: userId || null,
    }
  );

  return getMaskedSettings(provider);
}

module.exports = { getActiveConfig, getMaskedSettings, saveSettings };
