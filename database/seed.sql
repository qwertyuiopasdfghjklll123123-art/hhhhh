-- =====================================================================
-- Smart E-Learning App -- Sample Seed Data
-- بيانات تجريبية لتشغيل الموقع فوراً قبل تفعيل توليد الذكاء الاصطناعي
-- شغّل schema.sql أولاً، ثم هذا الملف
-- =====================================================================

USE smart_elearning;

-- مهم: يجبر عميل mysql على إرسال هذا الملف كـ UTF-8 بغض النظر عن الترميز
-- الافتراضي للعميل، وإلا قد يُخزَّن النص العربي بشكل تالف (mojibake)
SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- الدول
-- ---------------------------------------------------------------------
INSERT INTO countries (code, name_ar, name_en, flag_emoji) VALUES
  ('IQ', 'العراق', 'Iraq', '🇮🇶'),
  ('EG', 'مصر', 'Egypt', '🇪🇬'),
  ('SA', 'السعودية', 'Saudi Arabia', '🇸🇦');

-- ---------------------------------------------------------------------
-- المراحل الدراسية (مثال: العراق)
-- ---------------------------------------------------------------------
INSERT INTO stages (country_id, name_ar, name_en, education_level, level_order) VALUES
  ((SELECT id FROM countries WHERE code='IQ'), 'الأول متوسط',   'Intermediate 1', 'intermediate', 1),
  ((SELECT id FROM countries WHERE code='IQ'), 'الثاني متوسط',  'Intermediate 2', 'intermediate', 2),
  ((SELECT id FROM countries WHERE code='IQ'), 'الثالث متوسط',  'Intermediate 3', 'intermediate', 3),
  ((SELECT id FROM countries WHERE code='IQ'), 'السادس علمي',   'Scientific 6th (Secondary)', 'secondary', 6);

-- ---------------------------------------------------------------------
-- المواد لمرحلة "الثالث متوسط" - العراق
-- ---------------------------------------------------------------------
SET @stage_3m := (SELECT id FROM stages WHERE name_ar='الثالث متوسط'
                   AND country_id=(SELECT id FROM countries WHERE code='IQ') LIMIT 1);

INSERT INTO subjects (stage_id, name_ar, name_en, icon, color_hex, order_index, is_ai_generated) VALUES
  (@stage_3m, 'الرياضيات', 'Mathematics', 'fa-square-root-variable', '#00e6bb', 1, 0),
  (@stage_3m, 'الفيزياء',  'Physics',     'fa-atom',                 '#00c4a0', 2, 0),
  (@stage_3m, 'اللغة العربية', 'Arabic',  'fa-book-open',            '#00e6bb', 3, 0);

-- ---------------------------------------------------------------------
-- وحدة ودرسان تجريبيان لمادة الرياضيات
-- ---------------------------------------------------------------------
SET @subj_math := (SELECT id FROM subjects WHERE stage_id=@stage_3m AND name_ar='الرياضيات' LIMIT 1);

INSERT INTO units (subject_id, title_ar, description, order_index, is_ai_generated) VALUES
  (@subj_math, 'الفصل الأول: المعادلات الخطية', 'مقدمة في المعادلات الخطية بمتغير واحد وحلولها.', 1, 0);

SET @unit_1 := LAST_INSERT_ID();

INSERT INTO lectures
  (unit_id, title_ar, description, youtube_video_id, youtube_url, is_link_verified,
   duration_seconds, source, order_index)
VALUES
  (@unit_1, 'مقدمة في المعادلات الخطية',
   'شرح مفهوم المعادلة الخطية وطريقة كتابتها.',
   NULL, NULL, 0, 600, 'manual', 1),
  (@unit_1, 'حل المعادلات الخطية بخطوة واحدة',
   'أمثلة تطبيقية على حل المعادلات الخطية البسيطة.',
   NULL, NULL, 0, 720, 'manual', 2);

-- ملاحظة: اترك youtube_video_id فارغاً حتى يقوم AI Content Pipeline (أو محرر
-- بشري) باعتماد رابط محاضرة حقيقي وموثوق عبر YouTube Data API، تفادياً لتخزين
-- روابط غير صحيحة.

-- ---------------------------------------------------------------------
-- اختبار قصير تجريبي لأول محاضرة
-- ---------------------------------------------------------------------
SET @lecture_1 := (SELECT id FROM lectures WHERE unit_id=@unit_1 ORDER BY order_index LIMIT 1);

INSERT INTO quizzes (lecture_id, title, generated_by, difficulty) VALUES
  (@lecture_1, 'اختبار: مقدمة في المعادلات الخطية', 'manual', 'easy');

SET @quiz_1 := LAST_INSERT_ID();

INSERT INTO quiz_questions (quiz_id, question_text, explanation, points_value, order_index) VALUES
  (@quiz_1, 'ما هي درجة المتغير في المعادلة الخطية؟', 'المعادلة الخطية دائماً من الدرجة الأولى (الأس =1).', 10, 1),
  (@quiz_1, 'ما ناتج حل المعادلة: س + 5 = 12 ؟', 'بطرح 5 من الطرفين: س = 12 - 5 = 7.', 10, 2);

SET @q1 := (SELECT id FROM quiz_questions WHERE quiz_id=@quiz_1 ORDER BY order_index LIMIT 1);
SET @q2 := (SELECT id FROM quiz_questions WHERE quiz_id=@quiz_1 ORDER BY order_index DESC LIMIT 1);

INSERT INTO quiz_options (question_id, option_text, is_correct, order_index) VALUES
  (@q1, 'الدرجة الأولى', 1, 1),
  (@q1, 'الدرجة الثانية', 0, 2),
  (@q1, 'الدرجة الثالثة', 0, 3),
  (@q1, 'لا توجد درجة محددة', 0, 4),
  (@q2, '5', 0, 1),
  (@q2, '7', 1, 2),
  (@q2, '17', 0, 3),
  (@q2, '-7', 0, 4);

-- ---------------------------------------------------------------------
-- أوسمة أساسية للمكافآت
-- ---------------------------------------------------------------------
INSERT INTO badges (code, name_ar, description_ar, icon, points_required) VALUES
  ('starter',       'خطوة أولى',      'أكمل أول محاضرة لك',            'fa-shoe-prints', 10),
  ('century',       'مئة نقطة',       'اجمع 100 نقطة',                  'fa-star',        100),
  ('quiz_master',   'سيد الاختبارات', 'احصل على علامة كاملة في اختبار', 'fa-trophy',      0),
  ('thousand_club', 'نادي الألف',     'اجمع 1000 نقطة',                 'fa-crown',       1000);

-- ---------------------------------------------------------------------
-- إعداد افتراضي فارغ لمزوّد الذكاء الاصطناعي (DeepSeek)
-- المفتاح الفعلي يُضاف لاحقاً من صفحة الإعدادات في لوحة التحكم
-- أو عبر متغير البيئة DEEPSEEK_API_KEY في ملف .env كخيار احتياطي
-- ---------------------------------------------------------------------
INSERT INTO api_settings (provider, api_key_encrypted, api_base_url, model_name, is_active) VALUES
  ('deepseek', NULL, 'https://api.deepseek.com', 'deepseek-chat', 1);

-- ---------------------------------------------------------------------
-- ملاحظة أمنية بخصوص أول حساب أدمن:
-- لا يزرع هذا الملف كلمة مرور افتراضية معروفة. لإنشاء أول حساب أدمن:
--   1) سجّل مستخدماً عادياً من صفحة التسجيل في الموقع (/api/auth/register)
--   2) رقّه إلى admin عبر:
--        UPDATE users SET role='admin' WHERE email='you@example.com';
-- ---------------------------------------------------------------------
