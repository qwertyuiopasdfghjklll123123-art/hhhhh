const express = require('express');
const asyncHandler = require('../utils/asyncHandler');
const { requireAuth, requireAdmin } = require('../middleware/auth');
const settingsService = require('../services/settingsService');
const deepseekService = require('../services/deepseekService');

const router = express.Router();

// كل مسارات الإعدادات محصورة بالمشرف (admin) لأنها تتحكم بمفتاح API الحساس
router.use(requireAuth, requireAdmin);

// GET /api/settings/deepseek -- يعرض حالة المفتاح الحالي (مقنّع، لا يُعاد كاملاً أبداً)
router.get(
  '/deepseek',
  asyncHandler(async (req, res) => {
    const settings = await settingsService.getMaskedSettings('deepseek');
    res.json(settings);
  })
);

// PUT /api/settings/deepseek  { apiKey, baseUrl, model }
router.put(
  '/deepseek',
  asyncHandler(async (req, res) => {
    const { apiKey, baseUrl, model } = req.body;
    const settings = await settingsService.saveSettings({
      provider: 'deepseek',
      apiKey,
      baseUrl,
      model,
      userId: req.user.id,
    });
    res.json({ message: 'تم حفظ إعدادات DeepSeek بنجاح', ...settings });
  })
);

// POST /api/settings/deepseek/test -- يرسل رسالة تجريبية قصيرة للتأكد من صلاحية المفتاح
router.post(
  '/deepseek/test',
  asyncHandler(async (req, res) => {
    const { content } = await deepseekService.chatCompletion({
      messages: [
        { role: 'system', content: 'أجب بكلمة واحدة فقط: "متصل".' },
        { role: 'user', content: 'اختبار اتصال' },
      ],
      maxTokens: 10,
    });
    res.json({ ok: true, sample: content });
  })
);

module.exports = router;
