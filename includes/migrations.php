<?php
declare(strict_types=1);

/**
 * تحديثات المخطط (schema) اللاحقة للتثبيت الأصلي — تُطبَّق تلقائياً من واجهة
 * الأدمن بضغطة واحدة بدل الحاجة لتشغيل ملفات SQL يدوياً من phpMyAdmin.
 * كل خطوة هنا idempotent (تتحقق قبل التنفيذ) بحيث يكون استدعاء
 * run_pending_migrations() أكثر من مرة آمناً دائماً ولا يكرر أي تعديل.
 */

function db_has_column(string $table, string $column): bool
{
    static $dbName = null;
    if ($dbName === null) {
        $dbName = db()->query('SELECT DATABASE()')->fetchColumn();
    }
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$dbName, $table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function db_has_table(string $table): bool
{
    static $dbName = null;
    if ($dbName === null) {
        $dbName = db()->query('SELECT DATABASE()')->fetchColumn();
    }
    $stmt = db()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    $stmt->execute([$dbName, $table]);
    return (int) $stmt->fetchColumn() > 0;
}

/** فحص رخيص (يُخزَّن نتيجته بالجلسة عند النجاح) لمعرفة إن كانت أي تحديثات ناقصة */
function needs_schema_migration(): bool
{
    if (!empty($_SESSION['schema_migrated_ok'])) {
        return false;
    }
    try {
        if (!db_has_column('users', 'github_oauth_token')) {
            return true;
        }
        if (db_has_column('ai_providers', 'project_id')) {
            return true; // لا يزال على الهيكل القديم (مزوّدون لكل مشروع)
        }
        if (!db_has_column('project_context', 'skill_content')) {
            return true;
        }
        if (!db_has_column('ai_providers', 'tokens_used')) {
            return true;
        }
        if (!db_has_column('projects', 'public_slug')) {
            return true;
        }
        if (!db_has_table('project_skills')) {
            return true;
        }
        if (!db_has_column('ai_conversations', 'provider_id')) {
            return true;
        }
    } catch (Throwable $e) {
        // تعذّر حتى فحص المخطط (اتصال DB معطوب مثلاً) — نترك الخطأ الفعلي يظهر
        // لاحقاً بمعالج الأخطاء العام بدل التستّر عليه هنا.
        return false;
    }
    $_SESSION['schema_migrated_ok'] = true;
    return false;
}

/** يطبّق كل تحديثات المخطط الناقصة، ويعيد سجلاً نصياً بالخطوات المنفَّذة فعلياً */
function run_pending_migrations(): array
{
    $pdo = db();
    $log = [];

    if (!db_has_table('app_settings')) {
        $pdo->exec(
            'CREATE TABLE `app_settings` (' .
            '`setting_key` VARCHAR(100) NOT NULL,' .
            '`setting_value` TEXT NULL,' .
            '`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,' .
            'PRIMARY KEY (`setting_key`)' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $log[] = 'أُنشئ جدول app_settings (إعدادات عامة مثل GitHub OAuth App).';
    }

    if (!db_has_column('users', 'github_oauth_token')) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `github_oauth_token` TEXT NULL COMMENT 'مشفّر' AFTER `status`");
        $log[] = 'أُضيف عمود users.github_oauth_token (ربط GitHub عبر OAuth).';
    }
    if (!db_has_column('users', 'github_oauth_username')) {
        $pdo->exec('ALTER TABLE `users` ADD COLUMN `github_oauth_username` VARCHAR(190) NULL AFTER `github_oauth_token`');
        $log[] = 'أُضيف عمود users.github_oauth_username.';
    }

    if (!db_has_column('ai_conversations', 'mode')) {
        $pdo->exec("ALTER TABLE `ai_conversations` ADD COLUMN `mode` ENUM('chat','code') NOT NULL DEFAULT 'chat' AFTER `user_id`");
        $log[] = 'أُضيف عمود ai_conversations.mode (دردشة عادية / كود).';
    }

    if (db_has_column('ai_providers', 'project_id') && !db_has_table('ai_providers_old_per_project')) {
        $pdo->exec('RENAME TABLE `ai_providers` TO `ai_providers_old_per_project`');
        $pdo->exec(
            'CREATE TABLE `ai_providers` (' .
            '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,' .
            '`label` VARCHAR(100) NOT NULL,' .
            "`base_url` VARCHAR(255) NOT NULL DEFAULT 'https://integrate.api.nvidia.com/v1/chat/completions'," .
            "`api_key` TEXT NOT NULL COMMENT 'مشفّر'," .
            "`text_model` VARCHAR(150) NOT NULL DEFAULT 'openai/gpt-oss-20b'," .
            '`vision_model` VARCHAR(150) NULL,' .
            '`is_default` TINYINT(1) NOT NULL DEFAULT 0,' .
            '`tokens_used` BIGINT UNSIGNED NOT NULL DEFAULT 0,' .
            '`token_budget` BIGINT UNSIGNED NULL,' .
            '`created_by` INT UNSIGNED NULL,' .
            '`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
            '`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,' .
            'PRIMARY KEY (`id`),' .
            'KEY `idx_provider_created_by` (`created_by`),' .
            'CONSTRAINT `fk_provider_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $pdo->exec(
            'INSERT INTO `ai_providers` (label, base_url, api_key, text_model, vision_model, is_default, created_at, updated_at) ' .
            'SELECT label, base_url, api_key, text_model, vision_model, 0, created_at, updated_at FROM `ai_providers_old_per_project`'
        );
        $pdo->exec('UPDATE `ai_providers` SET is_default = 1 WHERE id = (SELECT id FROM (SELECT MIN(id) AS id FROM `ai_providers`) AS t)');
        $log[] = 'تحويل مزوّدي الذكاء الاصطناعي إلى قائمة عامة واحدة (نُسخت بياناتك بالكامل؛ الجدول القديم محفوظ باسم ai_providers_old_per_project).';
    }

    if (!db_has_column('ai_providers', 'tokens_used')) {
        $pdo->exec('ALTER TABLE `ai_providers` ADD COLUMN `tokens_used` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `is_default`');
        $log[] = 'أُضيف عمود ai_providers.tokens_used (لتتبّع الاستهلاك).';
    }
    if (!db_has_column('ai_providers', 'token_budget')) {
        $pdo->exec('ALTER TABLE `ai_providers` ADD COLUMN `token_budget` BIGINT UNSIGNED NULL AFTER `tokens_used`');
        $log[] = 'أُضيف عمود ai_providers.token_budget (حد اختياري يضبطه الأدمن).';
    }

    if (!db_has_column('project_context', 'skill_filename')) {
        $pdo->exec('ALTER TABLE `project_context` ADD COLUMN `skill_filename` VARCHAR(255) NULL AFTER `system_rules`');
        $log[] = 'أُضيف عمود project_context.skill_filename.';
    }
    if (!db_has_column('project_context', 'skill_content')) {
        $pdo->exec('ALTER TABLE `project_context` ADD COLUMN `skill_content` LONGTEXT NULL AFTER `skill_filename`');
        $log[] = 'أُضيف عمود project_context.skill_content (سياق Skill الدائم).';
    }

    if (!db_has_column('projects', 'public_slug')) {
        $pdo->exec('ALTER TABLE `projects` ADD COLUMN `public_slug` VARCHAR(20) NULL AFTER `id`');
        $existingIds = $pdo->query('SELECT id FROM `projects`')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($existingIds as $pid) {
            $pdo->prepare('UPDATE `projects` SET `public_slug` = ? WHERE `id` = ?')->execute([generate_project_slug(), $pid]);
        }
        $pdo->exec('ALTER TABLE `projects` ADD UNIQUE KEY `uniq_projects_slug` (`public_slug`)');
        $log[] = 'أُضيف عمود projects.public_slug (معرّف عشوائي لكل مشروع يُستخدم بالرابط بدل الرقم التسلسلي).';
    }

    if (!db_has_table('project_skills')) {
        $pdo->exec(
            'CREATE TABLE `project_skills` (' .
            '`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,' .
            '`project_id` INT UNSIGNED NOT NULL,' .
            "`title` VARCHAR(190) NOT NULL DEFAULT 'سياق بلا عنوان'," .
            '`content` LONGTEXT NOT NULL,' .
            '`created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,' .
            '`updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,' .
            'PRIMARY KEY (`id`),' .
            'KEY `idx_skill_project` (`project_id`),' .
            'CONSTRAINT `fk_skill_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`id`) ON DELETE CASCADE' .
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        $log[] = 'أُنشئ جدول project_skills (يدعم أكثر من مقتطف Skill لكل مشروع).';

        // ترحيل أي محتوى Skill قديم (عمود وحيد) إلى أول صف بالجدول الجديد
        // حفاظاً على استمرارية السياق الذي كان يعمل عليه AI سابقاً.
        if (db_has_column('project_context', 'skill_content')) {
            $rows = $pdo->query(
                "SELECT project_id, skill_filename, skill_content FROM `project_context` WHERE skill_content IS NOT NULL AND TRIM(skill_content) <> ''"
            )->fetchAll(PDO::FETCH_ASSOC);
            $migrated = 0;
            foreach ($rows as $row) {
                $title = trim((string) ($row['skill_filename'] ?? '')) !== ''
                    ? (string) $row['skill_filename']
                    : 'سياق مستورَد';
                $pdo->prepare('INSERT INTO `project_skills` (project_id, title, content) VALUES (?, ?, ?)')
                    ->execute([$row['project_id'], $title, $row['skill_content']]);
                $migrated++;
            }
            if ($migrated > 0) {
                $log[] = "رُحِّل محتوى Skill القديم لـ {$migrated} مشروع(اً) إلى النظام الجديد متعدد المقتطفات.";
            }
        }
    }

    if (!db_has_column('ai_conversations', 'provider_id')) {
        $pdo->exec('ALTER TABLE `ai_conversations` ADD COLUMN `provider_id` INT UNSIGNED NULL AFTER `title`');
        $pdo->exec(
            'ALTER TABLE `ai_conversations` ADD KEY `idx_conv_provider` (`provider_id`), ' .
            'ADD CONSTRAINT `fk_conv_provider` FOREIGN KEY (`provider_id`) REFERENCES `ai_providers` (`id`) ON DELETE SET NULL'
        );
        $log[] = 'أُضيف عمود ai_conversations.provider_id (يحفظ آخر مزوّد AI استُخدم بكل محادثة).';
    }

    if (empty($log)) {
        $log[] = 'قاعدة البيانات محدَّثة بالكامل بالفعل — لا حاجة لأي تغيير.';
    }

    $_SESSION['schema_migrated_ok'] = true;
    return $log;
}

/**
 * يعترض الطلب الحالي بالكامل عندما تحتاج قاعدة البيانات تحديثاً: يعرض للأدمن
 * زر "تحديث الآن" (ينفّذ run_pending_migrations() مباشرة)، ولغير الأدمن رسالة
 * "قيد التحديث" ودّية، ولطلبات AJAX خطأ JSON نظيف. ينهي تنفيذ الصفحة دائماً.
 */
function handle_pending_migration_gate(): never
{
    $isAdmin = false;
    if (!empty($_SESSION['user_id'])) {
        try {
            $stmt = db()->prepare("SELECT role FROM users WHERE id = ? AND status = 'active'");
            $stmt->execute([$_SESSION['user_id']]);
            $isAdmin = $stmt->fetchColumn() === 'admin';
        } catch (Throwable $e) {
            $isAdmin = false;
        }
    }

    if (is_ajax_request()) {
        http_response_code(503);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => false,
            'error'   => $isAdmin
                ? 'قاعدة البيانات تحتاج تحديثاً. افتح أي صفحة عادية من المتصفح لتحديثها بضغطة واحدة.'
                : 'النظام قيد التحديث حالياً، أعد المحاولة بعد قليل.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $log = null;
    if ($isAdmin && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['run_migration'])) {
        $token = (string) ($_POST['csrf_token'] ?? '');
        if ($token !== '' && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token)) {
            try {
                $log = run_pending_migrations();
            } catch (Throwable $e) {
                error_log('run_pending_migrations failed: ' . $e->getMessage());
                $log = ['⚠️ حدث خطأ أثناء التحديث: ' . $e->getMessage() . ' — التفاصيل الكاملة في سجل أخطاء PHP.'];
            }
        }
    }

    render_migration_gate_page($isAdmin, $log);
    exit;
}

function render_migration_gate_page(bool $isAdmin, ?array $log): void
{
    http_response_code($log !== null ? 200 : 503);
    header('Content-Type: text/html; charset=utf-8');
    $continueUrl = $_SERVER['REQUEST_URI'] ?? 'dashboard.php';
    ?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>تحديث النظام</title>
<style>
body{background:#0a0a0b;color:#f1f0ee;font-family:system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;padding:24px}
.box{max-width:520px;width:100%;background:#18181b;border:1px solid rgba(255,255,255,.09);border-radius:18px;padding:32px;box-sizing:border-box}
h1{font-size:1.15rem;margin:0 0 14px}
p{color:#9c9ca3;line-height:1.85;margin:0 0 16px;font-size:.9rem}
button{background:#cc7c5e;color:#fff;border:none;border-radius:10px;padding:13px 22px;font-size:.92rem;font-weight:700;cursor:pointer;width:100%}
button:hover{background:#d98d70}
a.btn{display:block;text-align:center;text-decoration:none;background:#26262a;color:#f1f0ee;border-radius:10px;padding:13px 22px;font-size:.92rem;font-weight:700}
ul{color:#4ade80;font-size:.8rem;line-height:2;padding-inline-start:20px;margin:0 0 18px}
</style>
</head>
<body>
<div class="box">
<?php if ($log !== null): ?>
  <h1>✅ تم التحديث</h1>
  <ul><?php foreach ($log as $line): ?><li><?= e($line) ?></li><?php endforeach; ?></ul>
  <a class="btn" href="<?= e($continueUrl) ?>">متابعة إلى النظام</a>
<?php elseif ($isAdmin): ?>
  <h1>قاعدة البيانات تحتاج تحديثاً</h1>
  <p>صدر تحديث جديد للنظام يضيف بعض الجداول/الأعمدة الجديدة. هذا إجراء آمن تماماً على بياناتك الحالية — لا حذف لأي شيء، وأي جدول قديم يُستبدل يُحفظ باسم آخر كنسخة احتياطية. اضغط الزر أدناه ليُطبَّق تلقائياً خلال ثوانٍ.</p>
  <form method="post">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="run_migration" value="1">
    <button type="submit">تحديث قاعدة البيانات الآن</button>
  </form>
<?php else: ?>
  <h1>النظام قيد التحديث</h1>
  <p>الرجاء المحاولة مجدداً بعد قليل، أو التواصل مع مسؤول النظام.</p>
<?php endif; ?>
</div>
</body>
</html>
<?php
}
