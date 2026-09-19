-- =====================================================================
-- لوحة إدارة المشاريع الذكية — هيكل قاعدة البيانات
-- يُنفَّذ تلقائياً بواسطة install.php، ويمكن أيضاً استيراده يدوياً.
-- كل الجداول idempotent (IF NOT EXISTS) بحيث يكون تشغيل الملف عدة مرات آمناً.
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------
-- المستخدمون
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `email` VARCHAR(190) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `role` ENUM('admin','user') NOT NULL DEFAULT 'user',
  `status` ENUM('active','disabled') NOT NULL DEFAULT 'active',
  `github_oauth_token` TEXT NULL COMMENT 'مشفّر - رمز وصول GitHub الشخصي بعد الربط عبر OAuth',
  `github_oauth_username` VARCHAR(190) NULL COMMENT 'اسم مستخدم GitHub المرتبط',
  `last_login_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- إعدادات عامة على مستوى النظام (Key/Value) — مثل بيانات GitHub OAuth App
-- القيم الحساسة تُخزَّن مشفّرة من طرف التطبيق قبل الحفظ هنا (انظر includes/settings.php)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `app_settings` (
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- محاولات تسجيل الدخول (حماية من هجمات التخمين Brute-force)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identifier` VARCHAR(190) NOT NULL COMMENT 'IP + البريد الإلكتروني',
  `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  `locked_until` DATETIME NULL DEFAULT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_login_identifier` (`identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- المشاريع
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `projects` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `public_slug` VARCHAR(20) NULL COMMENT 'معرّف عشوائي يُستخدم بالرابط بدل id التسلسلي',
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT NULL,
  `status` ENUM('active','archived') NOT NULL DEFAULT 'active',
  `created_by` INT UNSIGNED NULL COMMENT 'NULL إن حُذف حساب المُنشئ لاحقاً',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_projects_slug` (`public_slug`),
  KEY `idx_projects_created_by` (`created_by`),
  CONSTRAINT `fk_projects_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- سياق المشروع: هيكل قاعدة البيانات، القواعد البرمجية، بيانات GitHub
-- القيم الحساسة (github_token) تُخزَّن مشفّرة (AES-256-GCM) عبر Crypto::encrypt()
-- ملاحظة: skill_filename/skill_content قديمان (نُقل محتواهما إلى project_skills
-- عبر ترحيل تلقائي)، أُبقيا لأجل التوافق مع نسخ قديمة من قاعدة البيانات فقط.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `project_context` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `sql_schema` LONGTEXT NULL,
  `system_rules` LONGTEXT NULL,
  `skill_filename` VARCHAR(255) NULL COMMENT 'قديم - انظر project_skills',
  `skill_content` LONGTEXT NULL COMMENT 'قديم - انظر project_skills',
  `github_owner` VARCHAR(190) NULL,
  `github_repo` VARCHAR(190) NULL,
  `github_branch` VARCHAR(100) NOT NULL DEFAULT 'main',
  `github_token` TEXT NULL COMMENT 'مشفّر',
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_context_project` (`project_id`),
  CONSTRAINT `fk_context_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Skill: مقتطفات سياق دائمة متعددة لكل مشروع، تُحقن كلها ضمن موجّه النظام
-- في كل الأوضاع (دردشة/كود). يمكن للمستخدم إضافة أكثر من مقتطف وتسميته
-- وتعديله لاحقاً — بديل عن عمود skill_content الوحيد القديم في project_context.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `project_skills` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(190) NOT NULL DEFAULT 'سياق بلا عنوان',
  `content` LONGTEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_skill_project` (`project_id`),
  CONSTRAINT `fk_skill_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- مزوّدو الذكاء الاصطناعي (عامّون على مستوى النظام كله، يديرهم الأدمن فقط
-- من ai_providers.php، وتشاركهم كل المشاريع والمحادثات). يمكن إضافة أكثر
-- من مفتاح NVIDIA، أو أي مزوّد آخر متوافق مع بنية OpenAI Chat Completions
-- (OpenAI, Groq, DeepSeek, Together AI, OpenRouter, Mistral, نموذج مستضاف
-- ذاتياً...) عبر تحديد نقطة الاتصال (base_url) الخاصة به ومفتاحه الخاص.
-- api_key تُخزَّن مشفّرة (AES-256-GCM) عبر Crypto::encrypt()
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ai_providers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `label` VARCHAR(100) NOT NULL,
  `base_url` VARCHAR(255) NOT NULL DEFAULT 'https://integrate.api.nvidia.com/v1/chat/completions',
  `api_key` TEXT NOT NULL COMMENT 'مشفّر',
  `text_model` VARCHAR(150) NOT NULL DEFAULT 'openai/gpt-oss-20b',
  `vision_model` VARCHAR(150) NULL,
  `specialty` VARCHAR(150) NULL COMMENT 'وصف مختصر يضبطه الأدمن لوظيفة هذا المزوّد (مثلاً: متخصص بالصور، تفكير عميق، عام) يظهر للمستخدم عند الاختيار',
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `tokens_used` BIGINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'إجمالي التوكنات المستهلكة (تراكمي، من usage.total_tokens بكل رد)',
  `token_budget` BIGINT UNSIGNED NULL COMMENT 'حد أقصى اختياري يضبطه الأدمن يدوياً لعرض "المتبقي" (لا يوجد API قياسي لجلبه من المزوّد)',
  `created_by` INT UNSIGNED NULL COMMENT 'الأدمن الذي أضافه، NULL إن حُذف حسابه لاحقاً',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_provider_created_by` (`created_by`),
  CONSTRAINT `fk_provider_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- محادثات المساعد الذكي لكل مشروع
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ai_conversations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `mode` ENUM('chat','code') NOT NULL DEFAULT 'chat' COMMENT 'chat = دردشة عادية، code = مساعد كود يستكشف مستودع GitHub',
  `title` VARCHAR(190) NOT NULL DEFAULT 'محادثة جديدة',
  `provider_id` INT UNSIGNED NULL COMMENT 'آخر مزوّد ذكاء اصطناعي استُخدم في هذه المحادثة - يبقى مختاراً تلقائياً',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_conv_project` (`project_id`),
  KEY `idx_conv_user` (`user_id`),
  KEY `idx_conv_provider` (`provider_id`),
  CONSTRAINT `fk_conv_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_conv_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_conv_provider` FOREIGN KEY (`provider_id`) REFERENCES `ai_providers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `ai_messages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `conversation_id` INT UNSIGNED NOT NULL,
  `role` ENUM('user','assistant') NOT NULL,
  `content` LONGTEXT NOT NULL,
  `meta` JSON NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_msg_conv` (`conversation_id`),
  CONSTRAINT `fk_msg_conv` FOREIGN KEY (`conversation_id`) REFERENCES `ai_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- سجل النشاطات (تدقيق أمني)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL,
  `action` VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) NULL,
  `ip_address` VARCHAR(45) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_log_user` (`user_id`),
  KEY `idx_log_created` (`created_at`),
  CONSTRAINT `fk_log_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
