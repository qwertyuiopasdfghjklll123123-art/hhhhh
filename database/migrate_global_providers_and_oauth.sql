-- =====================================================================
-- ترقية: مزوّدو ذكاء اصطناعي عامّون (يديرهم الأدمن فقط) + ربط GitHub عبر
-- OAuth + وضع "الكود" للمحادثات.
-- ---------------------------------------------------------------------
-- شغّل هذا الملف مرة واحدة فقط على قاعدة بيانات مُثبَّتة مسبقاً (عندها
-- ملف config/config.php موجود بالفعل، ولذلك لن يُعاد تنفيذ schema.sql
-- تلقائياً). التثبيت الجديد (install.php) لا يحتاج لهذا الملف إطلاقاً.
--
-- طريقة التشغيل: الصقه كاملاً في تبويب SQL بـ phpMyAdmin واضغط Go،
-- أو: mysql -u USER -p DBNAME < database/migrate_global_providers_and_oauth.sql
--
-- ⚠️ آمن على بياناتك الحالية: مزوّدو الذكاء الاصطناعي المحفوظون مسبقاً في
-- كل مشاريعك (المفاتيح المشفّرة، النماذج...) يُنقَلون بالكامل دون فقدان
-- أي منها؛ الجدول القديم يُعاد تسميته فقط (لا يُحذف) لضمان إمكانية
-- الرجوع إليه.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1) إعدادات عامة على مستوى النظام (بيانات GitHub OAuth App)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `app_settings` (
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2) ربط حساب GitHub بكل مستخدم (عبر OAuth بدل لصق Token يدوياً)
-- ---------------------------------------------------------------------
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `github_oauth_token` TEXT NULL COMMENT 'مشفّر' AFTER `status`;
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `github_oauth_username` VARCHAR(190) NULL AFTER `github_oauth_token`;

-- ---------------------------------------------------------------------
-- 3) وضع المحادثة: دردشة عادية أو وضع "الكود" (يستكشف مستودع GitHub)
-- ---------------------------------------------------------------------
ALTER TABLE `ai_conversations` ADD COLUMN IF NOT EXISTS `mode` ENUM('chat','code') NOT NULL DEFAULT 'chat' AFTER `user_id`;

-- ---------------------------------------------------------------------
-- 4) تحويل مزوّدي الذكاء الاصطناعي من "لكل مشروع" إلى "عامّون على مستوى
--    النظام" — يُعاد تسمية الجدول القديم (وليس حذفه) فتبقى بياناتك
--    محفوظة بالكامل كنسخة احتياطية حتى تتأكد من نجاح الانتقال.
-- ---------------------------------------------------------------------
RENAME TABLE `ai_providers` TO `ai_providers_old_per_project`;

CREATE TABLE IF NOT EXISTS `ai_providers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `label` VARCHAR(100) NOT NULL,
  `base_url` VARCHAR(255) NOT NULL DEFAULT 'https://integrate.api.nvidia.com/v1/chat/completions',
  `api_key` TEXT NOT NULL COMMENT 'مشفّر',
  `text_model` VARCHAR(150) NOT NULL DEFAULT 'openai/gpt-oss-20b',
  `vision_model` VARCHAR(150) NULL,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `created_by` INT UNSIGNED NULL COMMENT 'الأدمن الذي أضافه',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_provider_created_by` (`created_by`),
  CONSTRAINT `fk_provider_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- نقل كل المزوّدين المحفوظين مسبقاً (من كل المشاريع) إلى القائمة العامة
-- الجديدة كما هم تماماً (المفتاح المشفّر يبقى صالحاً لأنه يُفكّ بنفس مفتاح
-- التطبيق app.key بغض النظر عن الجدول). قد يظهر تكرار إن كان لديك نفس
-- المزوّد مضافاً في أكثر من مشروع سابقاً — يمكنك حذف المكرر لاحقاً من
-- صفحة "مزوّدو الذكاء الاصطناعي" الجديدة بسهولة.
INSERT INTO `ai_providers` (label, base_url, api_key, text_model, vision_model, is_default, created_at, updated_at)
SELECT label, base_url, api_key, text_model, vision_model, 0, created_at, updated_at
FROM `ai_providers_old_per_project`;

-- عيّن أول مزوّد منقول كافتراضي (يمكنك تغييره لاحقاً بضغطة واحدة)
UPDATE `ai_providers`
SET is_default = 1
WHERE id = (SELECT MIN(id) FROM (SELECT id FROM `ai_providers`) AS first_row);

-- بعد التأكد أن كل مزوّديك ظاهرون وتعمل بشكل صحيح في الصفحة الجديدة،
-- يمكنك حذف الجدول القديم يدوياً (اختياري تماماً، وليس ضرورياً):
--   DROP TABLE `ai_providers_old_per_project`;
