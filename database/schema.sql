-- =====================================================================
-- Smart E-Learning App -- MySQL Database Schema
-- منصة تعليمية ذكية - هيكل قاعدة البيانات
-- =====================================================================
-- Engine: InnoDB | Charset: utf8mb4 (يدعم العربية والرموز التعبيرية)
-- يجب تشغيل هذا الملف على MySQL 8.0+ (يستخدم CHECK constraints و JSON)
--
-- ترتيب الجداول:
--   1) الدول والمراحل الدراسية      : countries, stages
--   2) المستخدمون                   : users
--   3) المنهج الدراسي (AI Pipeline) : subjects, units, lectures, lecture_progress
--   4) الامتحانات الذكية            : quizzes, quiz_questions, quiz_options,
--                                      quiz_attempts, quiz_attempt_answers
--   5) النقاط والمكافآت             : points_transactions, badges, user_badges
--   6) المساعد الذكي (AI Tutor)     : ai_chat_sessions, ai_chat_messages
--   7) بنية تحتية للذكاء الاصطناعي  : ai_generation_jobs, api_settings
--   8) لوحة المتصدرين (View)        : leaderboard_view
-- =====================================================================

-- ملاحظة حول إنشاء قاعدة البيانات:
-- على استضافة مشتركة (cPanel) تُنشئ القاعدة عادة من لوحة التحكم (MySQL Databases)
-- بصيغة مثل cpaneluser_dbname، ولا صلاحية للمستخدم بتنفيذ CREATE DATABASE.
-- لذا هذا السطر معلَّق افتراضياً كي يعمل الاستيراد مباشرة عبر phpMyAdmin
-- (الذي يستخدم القاعدة المختارة في الشريط الجانبي تلقائياً) دون أي تعديل.
--
-- للتشغيل محلياً (مثلاً على جهازك مع نسخة Node.js)، أزل التعليق عن السطرين التاليين:
-- CREATE DATABASE IF NOT EXISTS smart_elearning CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE smart_elearning;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- =====================================================================
-- 1) الدول والمراحل الدراسية
-- =====================================================================

DROP TABLE IF EXISTS countries;
CREATE TABLE countries (
  id            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  code          VARCHAR(2)      NOT NULL COMMENT 'رمز الدولة ISO 3166-1 مثل IQ, EG, SA',
  name_ar       VARCHAR(100)    NOT NULL COMMENT 'اسم الدولة بالعربية',
  name_en       VARCHAR(100)    NOT NULL COMMENT 'اسم الدولة بالإنجليزية',
  flag_emoji    VARCHAR(10)     NULL,
  is_active     TINYINT(1)      NOT NULL DEFAULT 1,
  created_at    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_countries_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS stages;
CREATE TABLE stages (
  id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  country_id      INT UNSIGNED    NOT NULL,
  name_ar         VARCHAR(150)    NOT NULL COMMENT 'مثال: الثالث المتوسط',
  name_en         VARCHAR(150)    NOT NULL,
  education_level ENUM('primary','intermediate','secondary','other')
                  NOT NULL DEFAULT 'other' COMMENT 'ابتدائي / متوسط / إعدادي',
  level_order     SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'للترتيب داخل الدولة',
  is_active       TINYINT(1)      NOT NULL DEFAULT 1,
  created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_stage_per_country (country_id, name_ar),
  KEY idx_stages_country (country_id),
  CONSTRAINT fk_stages_country FOREIGN KEY (country_id)
    REFERENCES countries(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 2) المستخدمون
-- =====================================================================

DROP TABLE IF EXISTS users;
CREATE TABLE users (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name           VARCHAR(120)    NOT NULL,
  email          VARCHAR(190)    NOT NULL,
  password_hash  VARCHAR(255)    NOT NULL COMMENT 'bcrypt hash',
  role           ENUM('student','teacher','admin') NOT NULL DEFAULT 'student',
  country_id     INT UNSIGNED    NULL,
  stage_id       INT UNSIGNED    NULL,
  avatar_url     VARCHAR(255)    NULL,
  points_total   INT UNSIGNED    NOT NULL DEFAULT 0 COMMENT 'كاش لمجموع النقاط لتسريع لوحة المتصدرين',
  is_active      TINYINT(1)      NOT NULL DEFAULT 1,
  last_login_at  DATETIME        NULL,
  created_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_country_stage (country_id, stage_id),
  KEY idx_users_points (points_total DESC),
  CONSTRAINT fk_users_country FOREIGN KEY (country_id)
    REFERENCES countries(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_users_stage FOREIGN KEY (stage_id)
    REFERENCES stages(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 3) المنهج الدراسي: المواد <- الوحدات/الفصول <- المحاضرات
--    (يُملأ تلقائياً بواسطة AI Content Pipeline ويُخزَّن هنا لتقليل التكلفة)
-- =====================================================================

DROP TABLE IF EXISTS subjects;
CREATE TABLE subjects (
  id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  stage_id        INT UNSIGNED    NOT NULL,
  name_ar         VARCHAR(150)    NOT NULL COMMENT 'مثال: الرياضيات',
  name_en         VARCHAR(150)    NULL,
  icon            VARCHAR(60)     NULL COMMENT 'اسم أيقونة Font Awesome, مثل fa-square-root-variable',
  color_hex       VARCHAR(7)      NULL DEFAULT '#00e6bb',
  order_index     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_ai_generated TINYINT(1)      NOT NULL DEFAULT 0,
  created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_subject_per_stage (stage_id, name_ar),
  KEY idx_subjects_stage (stage_id),
  CONSTRAINT fk_subjects_stage FOREIGN KEY (stage_id)
    REFERENCES stages(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS units;
CREATE TABLE units (
  id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  subject_id      INT UNSIGNED    NOT NULL,
  title_ar        VARCHAR(200)    NOT NULL COMMENT 'عنوان الفصل/الوحدة',
  description     TEXT            NULL,
  order_index     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  is_ai_generated TINYINT(1)      NOT NULL DEFAULT 0,
  created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_units_subject_order (subject_id, order_index),
  CONSTRAINT fk_units_subject FOREIGN KEY (subject_id)
    REFERENCES subjects(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS lectures;
CREATE TABLE lectures (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  unit_id           INT UNSIGNED    NOT NULL,
  title_ar          VARCHAR(220)    NOT NULL,
  description       TEXT            NULL,
  youtube_video_id  VARCHAR(20)     NULL COMMENT 'معرّف فيديو يوتيوب (بدون رابط كامل)',
  youtube_url       VARCHAR(255)    NULL COMMENT 'رابط كامل أو رابط بحث احتياطي إن لم يُعتمد فيديو بعد',
  is_link_verified  TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'هل تم التحقق من صلاحية الرابط عبر YouTube Data API',
  duration_seconds  INT UNSIGNED    NULL,
  thumbnail_url     VARCHAR(255)    NULL,
  transcript_text   MEDIUMTEXT      NULL COMMENT 'نص/ملخص المحاضرة يُستخدم كسياق لتوليد الأسئلة ومساعدة الشات بوت',
  source            ENUM('ai_curated','manual') NOT NULL DEFAULT 'ai_curated',
  order_index       SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  view_count        INT UNSIGNED    NOT NULL DEFAULT 0,
  created_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_lectures_unit_order (unit_id, order_index),
  CONSTRAINT fk_lectures_unit FOREIGN KEY (unit_id)
    REFERENCES units(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS lecture_progress;
CREATE TABLE lecture_progress (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id          BIGINT UNSIGNED NOT NULL,
  lecture_id       BIGINT UNSIGNED NOT NULL,
  watched_seconds  INT UNSIGNED    NOT NULL DEFAULT 0,
  is_completed     TINYINT(1)      NOT NULL DEFAULT 0,
  points_awarded   TINYINT(1)      NOT NULL DEFAULT 0 COMMENT 'يمنع منح نقاط أكثر من مرة لنفس المحاضرة',
  completed_at     DATETIME        NULL,
  created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_progress_user_lecture (user_id, lecture_id),
  KEY idx_progress_lecture (lecture_id),
  CONSTRAINT fk_progress_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_progress_lecture FOREIGN KEY (lecture_id)
    REFERENCES lectures(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 4) قسم الامتحانات التلقائية (AI Quiz System)
-- =====================================================================

DROP TABLE IF EXISTS quizzes;
CREATE TABLE quizzes (
  id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lecture_id      BIGINT UNSIGNED NULL,
  unit_id         INT UNSIGNED    NULL COMMENT 'اختبار نهاية وحدة (اختياري لاحقاً)',
  title           VARCHAR(220)    NOT NULL DEFAULT 'اختبار سريع',
  generated_by    ENUM('ai','manual') NOT NULL DEFAULT 'ai',
  difficulty      ENUM('easy','medium','hard') NOT NULL DEFAULT 'medium',
  created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_quizzes_lecture (lecture_id),
  KEY idx_quizzes_unit (unit_id),
  CONSTRAINT fk_quizzes_lecture FOREIGN KEY (lecture_id)
    REFERENCES lectures(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_quizzes_unit FOREIGN KEY (unit_id)
    REFERENCES units(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- ملاحظة: (lecture_id IS NOT NULL OR unit_id IS NOT NULL) يُفرض على مستوى التطبيق
-- بدل CHECK constraint، لأن بعض إصدارات MariaDB ترفض CHECK على أعمدة مرتبطة بمفتاح خارجي.

DROP TABLE IF EXISTS quiz_questions;
CREATE TABLE quiz_questions (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  quiz_id        BIGINT UNSIGNED NOT NULL,
  question_text  TEXT            NOT NULL,
  explanation    TEXT            NULL COMMENT 'شرح الإجابة الصحيحة يعرض بعد التصحيح',
  points_value   SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  order_index    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  created_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_questions_quiz (quiz_id, order_index),
  CONSTRAINT fk_questions_quiz FOREIGN KEY (quiz_id)
    REFERENCES quizzes(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS quiz_options;
CREATE TABLE quiz_options (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  question_id    BIGINT UNSIGNED NOT NULL,
  option_text    VARCHAR(500)    NOT NULL,
  is_correct     TINYINT(1)      NOT NULL DEFAULT 0,
  order_index    TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY idx_options_question (question_id),
  CONSTRAINT fk_options_question FOREIGN KEY (question_id)
    REFERENCES quiz_questions(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS quiz_attempts;
CREATE TABLE quiz_attempts (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        BIGINT UNSIGNED NOT NULL,
  quiz_id        BIGINT UNSIGNED NOT NULL,
  score          SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'مجموع النقاط المكتسبة من هذه المحاولة',
  total_questions   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  correct_count     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  started_at     DATETIME        NOT NULL,
  submitted_at   DATETIME        NULL,
  duration_seconds INT UNSIGNED  NULL,
  PRIMARY KEY (id),
  KEY idx_attempts_user (user_id),
  KEY idx_attempts_quiz (quiz_id),
  CONSTRAINT fk_attempts_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_attempts_quiz FOREIGN KEY (quiz_id)
    REFERENCES quizzes(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS quiz_attempt_answers;
CREATE TABLE quiz_attempt_answers (
  id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  attempt_id         BIGINT UNSIGNED NOT NULL,
  question_id        BIGINT UNSIGNED NOT NULL,
  selected_option_id BIGINT UNSIGNED NULL,
  is_correct         TINYINT(1)      NOT NULL DEFAULT 0,
  created_at         TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_answer_per_attempt_question (attempt_id, question_id),
  KEY idx_answers_question (question_id),
  CONSTRAINT fk_answers_attempt FOREIGN KEY (attempt_id)
    REFERENCES quiz_attempts(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_answers_question FOREIGN KEY (question_id)
    REFERENCES quiz_questions(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_answers_option FOREIGN KEY (selected_option_id)
    REFERENCES quiz_options(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 5) نظام النقاط والمكافآت (Gamification)
-- =====================================================================

DROP TABLE IF EXISTS points_transactions;
CREATE TABLE points_transactions (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id        BIGINT UNSIGNED NOT NULL,
  points         INT             NOT NULL COMMENT 'قد تكون سالبة مستقبلاً لأغراض التصحيح',
  reason         ENUM('lecture_complete','quiz_correct_answer','quiz_perfect_bonus',
                       'daily_streak','manual_adjustment') NOT NULL,
  reference_type VARCHAR(30)     NULL COMMENT 'lecture | quiz | attempt ...',
  reference_id   BIGINT UNSIGNED NULL,
  created_at     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_points_user (user_id, created_at),
  CONSTRAINT fk_points_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS badges;
CREATE TABLE badges (
  id              INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  code            VARCHAR(50)     NOT NULL,
  name_ar         VARCHAR(120)    NOT NULL,
  description_ar  VARCHAR(255)    NULL,
  icon            VARCHAR(60)     NULL DEFAULT 'fa-medal',
  points_required INT UNSIGNED    NOT NULL DEFAULT 0,
  created_at      TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_badges_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS user_badges;
CREATE TABLE user_badges (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    BIGINT UNSIGNED NOT NULL,
  badge_id   INT UNSIGNED    NOT NULL,
  earned_at  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_badge (user_id, badge_id),
  CONSTRAINT fk_user_badges_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_user_badges_badge FOREIGN KEY (badge_id)
    REFERENCES badges(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 6) المساعد الشخصي الذكي (AI Tutor Chat)
-- =====================================================================

DROP TABLE IF EXISTS ai_chat_sessions;
CREATE TABLE ai_chat_sessions (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id      BIGINT UNSIGNED NOT NULL,
  lecture_id   BIGINT UNSIGNED NULL,
  quiz_id      BIGINT UNSIGNED NULL,
  title        VARCHAR(150)    NULL,
  created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_chat_sessions_user (user_id),
  KEY idx_chat_sessions_lecture (lecture_id),
  CONSTRAINT fk_chat_sessions_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_chat_sessions_lecture FOREIGN KEY (lecture_id)
    REFERENCES lectures(id) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_chat_sessions_quiz FOREIGN KEY (quiz_id)
    REFERENCES quizzes(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DROP TABLE IF EXISTS ai_chat_messages;
CREATE TABLE ai_chat_messages (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id   BIGINT UNSIGNED NOT NULL,
  role         ENUM('user','assistant','system') NOT NULL,
  message_text MEDIUMTEXT      NOT NULL,
  tokens_used  INT UNSIGNED    NULL,
  created_at   TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_chat_messages_session (session_id, created_at),
  CONSTRAINT fk_chat_messages_session FOREIGN KEY (session_id)
    REFERENCES ai_chat_sessions(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 7) البنية التحتية للذكاء الاصطناعي: سجل التوليد + إعدادات مفاتيح API
-- =====================================================================

DROP TABLE IF EXISTS ai_generation_jobs;
CREATE TABLE ai_generation_jobs (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_type         ENUM('curriculum','lecture_links','quiz','transcript') NOT NULL,
  country_id       INT UNSIGNED    NULL,
  stage_id         INT UNSIGNED    NULL,
  subject_id       INT UNSIGNED    NULL,
  unit_id          INT UNSIGNED    NULL,
  lecture_id       BIGINT UNSIGNED NULL,
  status           ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
  request_payload  JSON            NULL,
  response_summary JSON            NULL,
  error_message    VARCHAR(500)    NULL,
  created_at       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at     DATETIME        NULL,
  PRIMARY KEY (id),
  KEY idx_jobs_status (status),
  KEY idx_jobs_scope (country_id, stage_id, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- جدول إعدادات مفاتيح واجهات الذكاء الاصطناعي (قسم "أضف API Key" في لوحة التحكم)
DROP TABLE IF EXISTS api_settings;
CREATE TABLE api_settings (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider           VARCHAR(30)  NOT NULL DEFAULT 'deepseek',
  api_key_encrypted  TEXT         NULL COMMENT 'مشفّر بـ AES-256-GCM عبر SETTINGS_ENCRYPTION_KEY',
  api_base_url       VARCHAR(255) NOT NULL DEFAULT 'https://api.deepseek.com',
  model_name         VARCHAR(100) NOT NULL DEFAULT 'deepseek-chat',
  is_active          TINYINT(1)   NOT NULL DEFAULT 1,
  updated_by         BIGINT UNSIGNED NULL,
  updated_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_api_settings_provider (provider),
  CONSTRAINT fk_api_settings_user FOREIGN KEY (updated_by)
    REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- 8) لوحة المتصدرين -- VIEW محسوب من users.points_total (سريع وبدون تكرار بيانات)
-- =====================================================================

DROP VIEW IF EXISTS leaderboard_view;
CREATE VIEW leaderboard_view AS
SELECT
  u.id            AS user_id,
  u.name          AS name,
  u.avatar_url    AS avatar_url,
  u.points_total  AS points_total,
  u.country_id    AS country_id,
  c.name_ar       AS country_name_ar,
  c.flag_emoji    AS country_flag,
  u.stage_id      AS stage_id,
  RANK() OVER (ORDER BY u.points_total DESC)                           AS rank_global,
  RANK() OVER (PARTITION BY u.country_id ORDER BY u.points_total DESC) AS rank_in_country
FROM users u
LEFT JOIN countries c ON c.id = u.country_id
WHERE u.role = 'student' AND u.is_active = 1;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- فهارس إضافية مفيدة لأداء لوحة المتصدرين والاستعلامات المتكررة
-- =====================================================================
CREATE INDEX idx_users_role_active ON users (role, is_active);
