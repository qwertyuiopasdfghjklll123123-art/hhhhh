-- =====================================================================
-- ترقية: مزوّدو ذكاء اصطناعي متعددون لكل مشروع
-- ---------------------------------------------------------------------
-- شغّل هذا الملف مرة واحدة فقط إن كنت قد ثبّتّ النظام سابقاً (أي أن لديك
-- ملف config/config.php موجود بالفعل، ولذلك لن يُعاد تنفيذ schema.sql
-- تلقائياً). التثبيت الجديد (install.php) لا يحتاج لهذا الملف إطلاقاً لأن
-- database/schema.sql المحدَّث يُنشئ جدول ai_providers مباشرة.
--
-- طريقة التشغيل:
--   mysql -u USER -p DBNAME < database/migrate_ai_providers.sql
-- أو استورده من phpMyAdmin.
--
-- الأعمدة القديمة (nvidia_api_key/nvidia_text_model/nvidia_vision_model)
-- في project_context تبقى كما هي دون حذف (آمن تماماً ولا تُستخدم بعد
-- الآن) لتفادي أي عملية ALTER مؤثرة على بياناتك الحالية.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `ai_providers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `label` VARCHAR(100) NOT NULL,
  `base_url` VARCHAR(255) NOT NULL DEFAULT 'https://integrate.api.nvidia.com/v1/chat/completions',
  `api_key` TEXT NOT NULL COMMENT 'مشفّر',
  `text_model` VARCHAR(150) NOT NULL DEFAULT 'meta/llama-3.1-70b-instruct',
  `vision_model` VARCHAR(150) NULL,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_provider_project` (`project_id`),
  CONSTRAINT `fk_provider_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- نقل أي مفتاح NVIDIA محفوظ مسبقاً (النظام القديم بمفتاح واحد فقط) كأول
-- مزوّد افتراضي لكل مشروع، بحيث لا يفقد أحد إعداداته بعد الترقية.
INSERT INTO `ai_providers` (project_id, label, base_url, api_key, text_model, vision_model, is_default)
SELECT
  pc.project_id,
  'NVIDIA NIM (تمت ترقيته تلقائياً)',
  'https://integrate.api.nvidia.com/v1/chat/completions',
  pc.nvidia_api_key,
  COALESCE(NULLIF(pc.nvidia_text_model, ''), 'meta/llama-3.1-70b-instruct'),
  NULLIF(pc.nvidia_vision_model, ''),
  1
FROM `project_context` pc
WHERE pc.nvidia_api_key IS NOT NULL
  AND pc.nvidia_api_key <> ''
  AND NOT EXISTS (SELECT 1 FROM `ai_providers` p WHERE p.project_id = pc.project_id);
