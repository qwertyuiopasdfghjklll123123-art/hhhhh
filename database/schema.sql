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
  `last_login_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_users_email` (`email`)
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
  `name` VARCHAR(150) NOT NULL,
  `description` TEXT NULL,
  `status` ENUM('active','archived') NOT NULL DEFAULT 'active',
  `created_by` INT UNSIGNED NULL COMMENT 'NULL إن حُذف حساب المُنشئ لاحقاً',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_projects_created_by` (`created_by`),
  CONSTRAINT `fk_projects_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- سياق المشروع: هيكل قاعدة البيانات، القواعد البرمجية، بيانات GitHub
-- القيم الحساسة (github_token) تُخزَّن مشفّرة (AES-256-GCM) عبر Crypto::encrypt()
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `project_context` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `sql_schema` LONGTEXT NULL,
  `system_rules` LONGTEXT NULL,
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
-- مزوّدو الذكاء الاصطناعي لكل مشروع (متعدد): يمكن إضافة أكثر من مفتاح NVIDIA،
-- أو أي مزوّد آخر متوافق مع بنية OpenAI Chat Completions (OpenAI, Groq,
-- DeepSeek, Together AI, OpenRouter, Mistral, نموذج مستضاف ذاتياً...) عبر
-- تحديد نقطة الاتصال (base_url) الخاصة به ومفتاح الـ API الخاص به.
-- api_key تُخزَّن مشفّرة (AES-256-GCM) عبر Crypto::encrypt()
-- ---------------------------------------------------------------------
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

-- ---------------------------------------------------------------------
-- محادثات المساعد الذكي لكل مشروع
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `ai_conversations` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `project_id` INT UNSIGNED NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `title` VARCHAR(190) NOT NULL DEFAULT 'محادثة جديدة',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_conv_project` (`project_id`),
  KEY `idx_conv_user` (`user_id`),
  CONSTRAINT `fk_conv_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_conv_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
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
