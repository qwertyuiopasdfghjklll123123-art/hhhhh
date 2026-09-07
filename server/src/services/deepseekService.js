const axios = require('axios');
const ApiError = require('../utils/ApiError');
const { getActiveConfig } = require('./settingsService');

// نداء عام لواجهة chat/completions المتوافقة مع OpenAI والتي توفرها DeepSeek
// راجع: https://api-docs.deepseek.com/
async function chatCompletion({ messages, temperature = 0.6, jsonMode = false, maxTokens = 2000 }) {
  const config = await getActiveConfig('deepseek');
  if (!config.apiKey) {
    throw new ApiError(
      412,
      'لم يتم ضبط مفتاح DeepSeek API بعد. أضِفه من صفحة الإعدادات (Settings) في لوحة التحكم.'
    );
  }

  try {
    const response = await axios.post(
      `${config.baseUrl.replace(/\/$/, '')}/chat/completions`,
      {
        model: config.model,
        messages,
        temperature,
        max_tokens: maxTokens,
        ...(jsonMode ? { response_format: { type: 'json_object' } } : {}),
      },
      {
        headers: {
          Authorization: `Bearer ${config.apiKey}`,
          'Content-Type': 'application/json',
        },
        timeout: 60000,
      }
    );

    const choice = response.data.choices && response.data.choices[0];
    return {
      content: choice ? choice.message.content : '',
      usage: response.data.usage || null,
    };
  } catch (err) {
    if (err.response) {
      throw new ApiError(
        502,
        `فشل الاتصال بـ DeepSeek (${err.response.status}): ${
          err.response.data && err.response.data.error
            ? err.response.data.error.message
            : 'خطأ غير معروف'
        }`
      );
    }
    throw new ApiError(502, `تعذّر الوصول إلى DeepSeek API: ${err.message}`);
  }
}

// يحاول استخراج JSON من نص الرد حتى لو أحاطه النموذج بعلامات ```json
function parseJsonSafely(text) {
  const cleaned = text.replace(/```json/gi, '').replace(/```/g, '').trim();
  try {
    return JSON.parse(cleaned);
  } catch (err) {
    throw new ApiError(502, 'رد الذكاء الاصطناعي لم يكن بصيغة JSON صالحة');
  }
}

// === محرك المناهج المدعوم بالذكاء الاصطناعي (AI Content Pipeline) ===
// يولّد هيكل المواد والوحدات والمحاضرات المقترحة لدولة/مرحلة معينة.
// ملاحظة مهمة: النموذج اللغوي لا يستطيع التحقق من روابط يوتيوب الحقيقية،
// لذا يُطلب منه اقتراح "عبارة بحث" لكل محاضرة فقط، ثم خدمة منفصلة
// (youtubeService، اختيارية) تبحث فعلياً وتُرجع فيديو حقيقياً موثوقاً.
async function generateCurriculum({ countryName, stageName, subjectCount = 5, unitsPerSubject = 4, lecturesPerUnit = 3 }) {
  const system = `أنت خبير مناهج تعليمية عربية. مهمتك اقتراح هيكل دراسي دقيق ومناسب
لعمر ومستوى الطلاب. أعد النتيجة بصيغة JSON فقط بدون أي شرح إضافي.`;

  const user = `اقترح هيكلاً دراسياً لدولة "${countryName}" للمرحلة "${stageName}".
أعد ${subjectCount} مواد دراسية رئيسية مناسبة لهذه المرحلة في هذه الدولة.
لكل مادة أعد ${unitsPerSubject} وحدات/فصول بالترتيب المنطقي للمنهج.
لكل وحدة أعد ${lecturesPerUnit} محاضرات بعناوين واضحة ووصف قصير،
مع اقتراح "search_query" (عبارة بحث يوتيوب بالعربية) تساعد لاحقاً بإيجاد فيديو تعليمي موثوق لهذه المحاضرة.

أعد الناتج بالشكل التالي بالضبط (JSON فقط):
{
  "subjects": [
    {
      "name_ar": "اسم المادة",
      "icon": "اسم أيقونة Font Awesome مناسب مثل fa-square-root-variable",
      "units": [
        {
          "title_ar": "عنوان الوحدة",
          "description": "وصف قصير",
          "lectures": [
            { "title_ar": "عنوان المحاضرة", "description": "وصف قصير", "search_query": "عبارة بحث يوتيوب" }
          ]
        }
      ]
    }
  ]
}`;

  const { content } = await chatCompletion({
    messages: [
      { role: 'system', content: system },
      { role: 'user', content: user },
    ],
    temperature: 0.5,
    jsonMode: true,
    maxTokens: 4000,
  });

  return parseJsonSafely(content);
}

// === قسم الامتحانات التلقائية (AI Quiz System) ===
// يولّد أسئلة اختيار من متعدد بالاستناد إلى عنوان/وصف/نص المحاضرة
async function generateQuiz({ lectureTitle, lectureDescription, transcript, questionCount = 5 }) {
  const system = `أنت مساعد تعليمي متخصص بإعداد اختبارات اختيار من متعدد بالعربية.
أعد النتيجة بصيغة JSON فقط بدون أي نص خارج JSON.`;

  const contextText = transcript
    ? `محتوى/ملخص المحاضرة:\n${transcript.slice(0, 6000)}`
    : `وصف المحاضرة: ${lectureDescription || 'غير متوفر'}`;

  const user = `أنشئ اختباراً من ${questionCount} أسئلة اختيار من متعدد حول محاضرة بعنوان
"${lectureTitle}".
${contextText}

لكل سؤال أعد 4 خيارات، خيار واحد صحيح فقط، مع شرح مختصر للإجابة الصحيحة.
أعد الناتج بالشكل التالي بالضبط (JSON فقط):
{
  "questions": [
    {
      "question_text": "نص السؤال",
      "explanation": "شرح مختصر للإجابة الصحيحة",
      "options": [
        { "text": "خيار 1", "is_correct": false },
        { "text": "خيار 2", "is_correct": true },
        { "text": "خيار 3", "is_correct": false },
        { "text": "خيار 4", "is_correct": false }
      ]
    }
  ]
}`;

  const { content } = await chatCompletion({
    messages: [
      { role: 'system', content: system },
      { role: 'user', content: user },
    ],
    temperature: 0.4,
    jsonMode: true,
    maxTokens: 3000,
  });

  return parseJsonSafely(content);
}

// === المساعد الشخصي الذكي (AI Tutor) ===
// يجيب على سؤال الطالب في سياق المحاضرة الحالية فقط، بأسلوب مبسّط
async function tutorReply({ lectureTitle, lectureDescription, transcript, history, userMessage }) {
  const system = `أنت مدرّس خصوصي ذكي ومحفّز لطالب عربي. أجب فقط ضمن سياق المحاضرة الحالية،
بسّط الأفكار المعقدة بأمثلة قريبة من واقع الطالب، وكن مختصراً ومشجعاً.
إن سُئلت عن موضوع خارج سياق المحاضرة، وجّه الطالب بلطف للعودة لموضوع الدرس.

عنوان المحاضرة: ${lectureTitle}
وصف المحاضرة: ${lectureDescription || 'غير متوفر'}
${transcript ? `ملخص محتوى المحاضرة: ${transcript.slice(0, 4000)}` : ''}`;

  const messages = [
    { role: 'system', content: system },
    ...(history || []).map((m) => ({ role: m.role, content: m.message_text })),
    { role: 'user', content: userMessage },
  ];

  const { content, usage } = await chatCompletion({ messages, temperature: 0.6, maxTokens: 800 });
  return { reply: content, usage };
}

module.exports = { chatCompletion, generateCurriculum, generateQuiz, tutorReply };
