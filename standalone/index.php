<?php
/**
 * ============================================================================
 * ذَكِيّ — Smart E-Learning App — نسخة الملف الواحد (Single-File Edition)
 * ============================================================================
 * ملف واحد شامل: يحتوي على الواجهة والخادم وقاعدة البيانات معاً.
 * قاعدة البيانات SQLite تُنشأ تلقائياً (ملف واحد بجانب هذا الملف) عند أول زيارة،
 * بدون أي إعداد يدوي: بدون MySQL، بدون phpMyAdmin، بدون ملف config منفصل.
 *
 * الهيكل: مادة ← مدرّس ← وحدة ← محاضرة. قبل تسجيل الدخول لا تُعرض إلا شاشة
 * ترحيبية ثم تسجيل الدخول (بدون أي قائمة أو تنقّل)، وبعد الدخول تظهر بقية
 * الميزات. أول من يسجّل حساباً يصبح أدمن تلقائياً ويضيف مفتاح DeepSeek من
 * صفحة الإعدادات داخل الموقع.
 *
 * ملاحظة عند التحديث من نسخة سابقة: هذا الإصدار يغيّر بنية قاعدة البيانات
 * (إضافة جدول المدرّسين). احذف ملف .zaki_secure_data.sqlite القديم قبل
 * تجربة هذه النسخة كي يُعاد إنشاؤه بالبنية الجديدة تلقائياً.
 * ============================================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

define('DB_PATH', __DIR__ . '/.zaki_secure_data.sqlite');
define('POINTS_LECTURE_COMPLETE', 10);
define('POINTS_QUIZ_CORRECT', 5);
define('POINTS_QUIZ_PERFECT_BONUS', 20);

set_exception_handler(function (Throwable $e) {
    if (isset($_GET['api'])) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode(['error' => 'خطأ غير متوقع: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(500);
        echo '<div dir="rtl" style="font-family:sans-serif;padding:40px;text-align:center">حدث خطأ: '
            . htmlspecialchars($e->getMessage()) . '</div>';
    }
});

class ApiException extends Exception
{
    public $statusCode;
    public function __construct($statusCode, $message)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }
}

// ============================================================================
// طبقة قاعدة البيانات (SQLite) + التثبيت التلقائي عند أول تشغيل
// ============================================================================

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    if (!extension_loaded('pdo_sqlite')) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        die('امتداد pdo_sqlite غير مفعّل على استضافتك. فعّله من cPanel > Select PHP Version > Extensions ثم أعد تحميل الصفحة.');
    }
    $isNew = !file_exists(DB_PATH);
    $pdo = new PDO('sqlite:' . DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    if ($isNew) {
        install_schema($pdo);
        seed_data();
    } else {
        migrate_schema_if_needed($pdo);
    }
    return $pdo;
}

function q_all(string $sql, array $params = []): array
{
    $s = db()->prepare($sql);
    $s->execute($params);
    return $s->fetchAll();
}

function q_one(string $sql, array $params = [])
{
    $s = db()->prepare($sql);
    $s->execute($params);
    $r = $s->fetch();
    return $r === false ? null : $r;
}

function q_run(string $sql, array $params = []): PDOStatement
{
    $s = db()->prepare($sql);
    $s->execute($params);
    return $s;
}

function install_schema(PDO $pdo): void
{
    $statements = [
        "CREATE TABLE app_meta (meta_key TEXT PRIMARY KEY, meta_value TEXT)",

        "CREATE TABLE countries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name_ar TEXT NOT NULL,
            name_en TEXT NOT NULL,
            flag_emoji TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",

        "CREATE TABLE stages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            country_id INTEGER NOT NULL REFERENCES countries(id) ON DELETE CASCADE,
            name_ar TEXT NOT NULL,
            name_en TEXT NOT NULL,
            education_level TEXT NOT NULL DEFAULT 'other' CHECK(education_level IN ('primary','intermediate','secondary','other')),
            level_order INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(country_id, name_ar)
        )",

        // محافظات/أقاليم اختيارية لكل دولة (مثلاً محافظات العراق). دولة بلا
        // أي صف هنا تعني أن هذه الخطوة تُخطّى تلقائياً في واجهة الاختيار.
        "CREATE TABLE governorates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            country_id INTEGER NOT NULL REFERENCES countries(id) ON DELETE CASCADE,
            name_ar TEXT NOT NULL,
            order_index INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(country_id, name_ar)
        )",

        "CREATE TABLE users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'student' CHECK(role IN ('student','teacher','admin')),
            country_id INTEGER REFERENCES countries(id) ON DELETE SET NULL,
            governorate_id INTEGER REFERENCES governorates(id) ON DELETE SET NULL,
            stage_id INTEGER REFERENCES stages(id) ON DELETE SET NULL,
            avatar_url TEXT,
            points_total INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            last_login_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",

        "CREATE TABLE subjects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            stage_id INTEGER NOT NULL REFERENCES stages(id) ON DELETE CASCADE,
            name_ar TEXT NOT NULL,
            name_en TEXT,
            icon TEXT,
            color_hex TEXT DEFAULT '#00e6bb',
            order_index INTEGER NOT NULL DEFAULT 0,
            is_ai_generated INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(stage_id, name_ar)
        )",

        // المدرّسون: كل مادة يمكن أن تضم عدة مدرّسين، والطالب يختار مدرّساً
        // قبل أن يصل لوحدات/محاضرات ذلك المدرّس تحديداً
        "CREATE TABLE teachers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subject_id INTEGER NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
            name_ar TEXT NOT NULL,
            bio TEXT,
            avatar_color TEXT DEFAULT '#00e6bb',
            order_index INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",

        "CREATE TABLE units (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            teacher_id INTEGER NOT NULL REFERENCES teachers(id) ON DELETE CASCADE,
            title_ar TEXT NOT NULL,
            description TEXT,
            order_index INTEGER NOT NULL DEFAULT 0,
            is_ai_generated INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",

        "CREATE TABLE lectures (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            unit_id INTEGER NOT NULL REFERENCES units(id) ON DELETE CASCADE,
            title_ar TEXT NOT NULL,
            description TEXT,
            youtube_video_id TEXT,
            youtube_url TEXT,
            is_link_verified INTEGER NOT NULL DEFAULT 0,
            duration_seconds INTEGER,
            thumbnail_url TEXT,
            transcript_text TEXT,
            source TEXT NOT NULL DEFAULT 'ai_curated',
            order_index INTEGER NOT NULL DEFAULT 0,
            view_count INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",

        "CREATE TABLE lecture_progress (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            lecture_id INTEGER NOT NULL REFERENCES lectures(id) ON DELETE CASCADE,
            watched_seconds INTEGER NOT NULL DEFAULT 0,
            is_completed INTEGER NOT NULL DEFAULT 0,
            points_awarded INTEGER NOT NULL DEFAULT 0,
            completed_at TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, lecture_id)
        )",

        "CREATE TABLE quizzes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            lecture_id INTEGER REFERENCES lectures(id) ON DELETE CASCADE,
            unit_id INTEGER REFERENCES units(id) ON DELETE CASCADE,
            title TEXT NOT NULL DEFAULT 'اختبار سريع',
            generated_by TEXT NOT NULL DEFAULT 'ai',
            difficulty TEXT NOT NULL DEFAULT 'medium',
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",

        "CREATE TABLE quiz_questions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            quiz_id INTEGER NOT NULL REFERENCES quizzes(id) ON DELETE CASCADE,
            question_text TEXT NOT NULL,
            explanation TEXT,
            points_value INTEGER NOT NULL DEFAULT 10,
            order_index INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",

        "CREATE TABLE quiz_options (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            question_id INTEGER NOT NULL REFERENCES quiz_questions(id) ON DELETE CASCADE,
            option_text TEXT NOT NULL,
            is_correct INTEGER NOT NULL DEFAULT 0,
            order_index INTEGER NOT NULL DEFAULT 0
        )",

        "CREATE TABLE quiz_attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            quiz_id INTEGER NOT NULL REFERENCES quizzes(id) ON DELETE CASCADE,
            score INTEGER NOT NULL DEFAULT 0,
            total_questions INTEGER NOT NULL DEFAULT 0,
            correct_count INTEGER NOT NULL DEFAULT 0,
            started_at TEXT NOT NULL,
            submitted_at TEXT,
            duration_seconds INTEGER
        )",

        "CREATE TABLE quiz_attempt_answers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            attempt_id INTEGER NOT NULL REFERENCES quiz_attempts(id) ON DELETE CASCADE,
            question_id INTEGER NOT NULL REFERENCES quiz_questions(id) ON DELETE CASCADE,
            selected_option_id INTEGER REFERENCES quiz_options(id) ON DELETE SET NULL,
            is_correct INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(attempt_id, question_id)
        )",

        "CREATE TABLE points_transactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            points INTEGER NOT NULL,
            reason TEXT NOT NULL,
            reference_type TEXT,
            reference_id INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",

        "CREATE TABLE badges (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code TEXT NOT NULL UNIQUE,
            name_ar TEXT NOT NULL,
            description_ar TEXT,
            icon TEXT DEFAULT 'fa-medal',
            points_required INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",

        "CREATE TABLE user_badges (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            badge_id INTEGER NOT NULL REFERENCES badges(id) ON DELETE CASCADE,
            earned_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(user_id, badge_id)
        )",

        "CREATE TABLE ai_chat_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            lecture_id INTEGER REFERENCES lectures(id) ON DELETE SET NULL,
            quiz_id INTEGER REFERENCES quizzes(id) ON DELETE SET NULL,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",

        "CREATE TABLE ai_chat_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            session_id INTEGER NOT NULL REFERENCES ai_chat_sessions(id) ON DELETE CASCADE,
            role TEXT NOT NULL CHECK(role IN ('user','assistant','system')),
            message_text TEXT NOT NULL,
            tokens_used INTEGER,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",

        "CREATE TABLE ai_generation_jobs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            job_type TEXT NOT NULL,
            country_id INTEGER,
            stage_id INTEGER,
            status TEXT NOT NULL DEFAULT 'pending',
            request_payload TEXT,
            response_summary TEXT,
            error_message TEXT,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            completed_at TEXT
        )",

        "CREATE TABLE api_settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider TEXT NOT NULL UNIQUE DEFAULT 'deepseek',
            api_key_encrypted TEXT,
            api_base_url TEXT NOT NULL DEFAULT 'https://api.deepseek.com',
            model_name TEXT NOT NULL DEFAULT 'deepseek-chat',
            is_active INTEGER NOT NULL DEFAULT 1,
            updated_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
            updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )",
    ];
    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }
}

// يضيف جدول المحافظات وعمود governorate_id إن كانا مفقودين، بمعزل تام عن
// حالة ترقية units/teachers أدناه (يجب أن تعمل هذه الخطوة دائماً وألا تُحجب
// خلف أي return مبكر خاص بترقيات أخرى).
function migrate_governorates_if_needed(PDO $pdo): void
{
    $hasGovernorates = q_one("SELECT name FROM sqlite_master WHERE type='table' AND name='governorates'");
    if (!$hasGovernorates) {
        $pdo->exec("CREATE TABLE governorates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            country_id INTEGER NOT NULL REFERENCES countries(id) ON DELETE CASCADE,
            name_ar TEXT NOT NULL,
            order_index INTEGER NOT NULL DEFAULT 0,
            is_active INTEGER NOT NULL DEFAULT 1,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(country_id, name_ar)
        )");
    }

    $userCols = array_column(q_all("PRAGMA table_info(users)"), 'name');
    if (!in_array('governorate_id', $userCols, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN governorate_id INTEGER REFERENCES governorates(id) ON DELETE SET NULL");
    }

    // محافظات العراق: تُزرع فقط إن كان العراق موجوداً ولا يملك أي محافظة بعد
    // (قاعدة بيانات قديمة كانت أُنشئت قبل إضافة هذه الميزة)
    $iraq = q_one("SELECT id FROM countries WHERE code='IQ'");
    if ($iraq && !q_one("SELECT id FROM governorates WHERE country_id=?", [$iraq['id']])) {
        seed_iraq_governorates((int) $iraq['id']);
    }
}

// ترقية تلقائية لقاعدة بيانات موجودة من نسخة سابقة إلى البنية الحالية،
// بدون حذف أي بيانات (حسابات المستخدمين، نقاطهم، إلخ). تُستدعى في كل اتصال
// على قاعدة بيانات موجودة مسبقاً، ولا تفعل شيئاً إن كانت البنية محدَّثة أصلاً.
function migrate_schema_if_needed(PDO $pdo): void
{
    migrate_governorates_if_needed($pdo);
    migrate_iraq_only_and_full_ladder($pdo);

    $hasTeachers = q_one("SELECT name FROM sqlite_master WHERE type='table' AND name='teachers'");
    if (!$hasTeachers) {
        // نسخة سابقة كانت units.subject_id مباشرة (بدون جدول مدرّسين). ننشئ
        // جدول المدرّسين أولاً؛ تعبئته بمدرّسين افتراضيين تأتي أدناه بحسب حالة units.
        $pdo->exec("CREATE TABLE teachers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subject_id INTEGER NOT NULL REFERENCES subjects(id) ON DELETE CASCADE,
            name_ar TEXT NOT NULL,
            bio TEXT,
            avatar_color TEXT DEFAULT '#00e6bb',
            order_index INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
    }

    $hasUnits = q_one("SELECT name FROM sqlite_master WHERE type='table' AND name='units'");
    if (!$hasUnits) {
        return;
    }

    $unitCols = array_column(q_all("PRAGMA table_info(units)"), 'name');
    $hasSubjectId = in_array('subject_id', $unitCols, true);
    $hasTeacherId = in_array('teacher_id', $unitCols, true);

    if (!$hasSubjectId) {
        return; // محدَّثة بالكامل أصلاً (أو بنية غير متوقعة)؛ لا شيء نفعله
    }

    if (!$hasTeacherId) {
        // أول مرة نضيف فيها عمود teacher_id: ننشئ "مدرّساً" افتراضياً واحداً
        // لكل مادة تملك وحدات فعلاً وننقل تلك الوحدات إليه، فتبقى كل
        // البيانات القديمة سليمة ومرئية.
        $pdo->exec("ALTER TABLE units ADD COLUMN teacher_id INTEGER REFERENCES teachers(id) ON DELETE CASCADE");

        $subjectsWithUnits = q_all(
            "SELECT DISTINCT s.id, s.name_ar FROM subjects s JOIN units u ON u.subject_id = s.id"
        );
        foreach ($subjectsWithUnits as $s) {
            q_run(
                "INSERT INTO teachers (subject_id, name_ar, bio, order_index) VALUES (?,?,?,1)",
                [$s['id'], 'فريق ' . $s['name_ar'], 'محتوى منقول تلقائياً من إصدار سابق من التطبيق']
            );
            $teacherId = (int) $pdo->lastInsertId();
            q_run("UPDATE units SET teacher_id=? WHERE subject_id=?", [$teacherId, $s['id']]);
        }
    }

    // العمود القديم subject_id كان NOT NULL، فترْكه كما هو يمنع أي INSERT جديد
    // في units (الذي لا يحدّد subject_id أبداً في الكود الحالي) بخطأ
    // "NOT NULL constraint failed". لذا نعيد بناء الجدول بالكامل بالبنية
    // الصحيحة الحالية (بدون subject_id إطلاقاً) بدل ترك العمود القديم.
    // ننفّذ هذه الخطوة دائماً طالما subject_id ما زال موجوداً، سواء كان جدول
    // المدرّسين/عمود teacher_id قد أُنشئ للتو أعلاه أو كان موجوداً مسبقاً من
    // محاولة ترقية سابقة لم تكتمل (قاعدة بيانات فيها الجدولان معاً حالياً).
    $pdo->exec('PRAGMA foreign_keys = OFF');
    $pdo->beginTransaction();
    try {
        $pdo->exec("CREATE TABLE units_rebuilt (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            teacher_id INTEGER NOT NULL REFERENCES teachers(id) ON DELETE CASCADE,
            title_ar TEXT NOT NULL,
            description TEXT,
            order_index INTEGER NOT NULL DEFAULT 0,
            is_ai_generated INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
        )");
        $pdo->exec(
            "INSERT INTO units_rebuilt (id, teacher_id, title_ar, description, order_index, is_ai_generated, created_at)
             SELECT id, teacher_id, title_ar, description, order_index, is_ai_generated, created_at
             FROM units WHERE teacher_id IS NOT NULL"
        );
        $pdo->exec("DROP TABLE units");
        $pdo->exec("ALTER TABLE units_rebuilt RENAME TO units");
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $pdo->exec('PRAGMA foreign_keys = ON');
        throw $e;
    }
    $pdo->exec('PRAGMA foreign_keys = ON');
}

function seed_iraq_governorates(int $iraqId): void
{
    $names = [
        'بغداد', 'البصرة', 'نينوى', 'أربيل', 'النجف', 'كربلاء', 'الأنبار', 'ذي قار',
        'بابل', 'ديالى', 'كركوك', 'واسط', 'ميسان', 'القادسية', 'المثنى',
        'صلاح الدين', 'دهوك', 'السليمانية',
    ];
    foreach ($names as $i => $name) {
        q_run(
            "INSERT INTO governorates (country_id, name_ar, order_index) VALUES (?,?,?)",
            [$iraqId, $name, $i + 1]
        );
    }
}

// السلّم الدراسي الكامل في العراق: ابتدائية (١-٦)، متوسطة (١-٣)، ثم إعدادية
// (رابع عام موحَّد، فخامس وسادس بفرعين علمي/أدبي). كل صف [name_ar, name_en,
// education_level, level_order]. تُستخدم هذه القائمة عند الزرع الأولي وعند
// ترقية قاعدة بيانات قديمة كانت تحوي صفوفاً جزئية فقط.
function iraq_stage_list(): array
{
    return [
        ['الأول ابتدائي', 'Primary 1', 'primary', 1],
        ['الثاني ابتدائي', 'Primary 2', 'primary', 2],
        ['الثالث ابتدائي', 'Primary 3', 'primary', 3],
        ['الرابع ابتدائي', 'Primary 4', 'primary', 4],
        ['الخامس ابتدائي', 'Primary 5', 'primary', 5],
        ['السادس ابتدائي', 'Primary 6', 'primary', 6],
        ['الأول متوسط', 'Intermediate 1', 'intermediate', 1],
        ['الثاني متوسط', 'Intermediate 2', 'intermediate', 2],
        ['الثالث متوسط', 'Intermediate 3', 'intermediate', 3],
        ['الرابع الإعدادي', 'Secondary 4', 'secondary', 4],
        ['الخامس العلمي', 'Scientific 5th', 'secondary', 5],
        ['الخامس الأدبي', 'Literary 5th', 'secondary', 5],
        ['السادس علمي', 'Scientific 6th', 'secondary', 6],
        ['السادس الأدبي', 'Literary 6th', 'secondary', 6],
    ];
}

// يقصر الدول على العراق فقط (تُحذف أي دولة أخرى من نسخة قديمة بأمان عبر
// CASCADE/SET NULL)، ويضيف أي صف ناقص من السلّم الدراسي الكامل أعلاه لصفوف
// موجودة مسبقاً. مستقل تماماً عن ترقية units/teachers، ويجب أن يعمل دائماً.
function migrate_iraq_only_and_full_ladder(PDO $pdo): void
{
    $pdo->exec("DELETE FROM countries WHERE code != 'IQ'");

    $iraq = q_one("SELECT id FROM countries WHERE code='IQ'");
    if (!$iraq) {
        return;
    }
    $iraqId = (int) $iraq['id'];
    foreach (iraq_stage_list() as $s) {
        q_run(
            "INSERT OR IGNORE INTO stages (country_id,name_ar,name_en,education_level,level_order) VALUES (?,?,?,?,?)",
            [$iraqId, $s[0], $s[1], $s[2], $s[3]]
        );
    }
}

// بيانات تجريبية أولية (دولة + مرحلة + مادة + مدرّس + وحدة + 5 محاضرات + اختبار جاهز)
// كي يظهر شيء فور أول زيارة قبل تفعيل توليد الذكاء الاصطناعي
function seed_data(): void
{
    q_run("INSERT INTO countries (code,name_ar,name_en,flag_emoji) VALUES ('IQ','العراق','Iraq','🇮🇶')");
    $iraqId = (int) db()->lastInsertId();

    $stage3Id = null;
    foreach (iraq_stage_list() as $s) {
        q_run("INSERT INTO stages (country_id,name_ar,name_en,education_level,level_order) VALUES (?,?,?,?,?)",
            [$iraqId, $s[0], $s[1], $s[2], $s[3]]);
        if ($s[0] === 'الثالث متوسط') {
            $stage3Id = (int) db()->lastInsertId();
        }
    }

    q_run("INSERT INTO subjects (stage_id,name_ar,name_en,icon,color_hex,order_index) VALUES (?,?,?,?,?,1)",
        [$stage3Id, 'الرياضيات', 'Mathematics', 'fa-square-root-variable', '#00e6bb']);
    $mathId = (int) db()->lastInsertId();
    q_run("INSERT INTO subjects (stage_id,name_ar,name_en,icon,color_hex,order_index) VALUES (?,?,?,?,?,2)",
        [$stage3Id, 'الفيزياء', 'Physics', 'fa-atom', '#00c4a0']);
    q_run("INSERT INTO subjects (stage_id,name_ar,name_en,icon,color_hex,order_index) VALUES (?,?,?,?,?,3)",
        [$stage3Id, 'اللغة العربية', 'Arabic', 'fa-book-open', '#00e6bb']);

    // مدرّسان لمادة الرياضيات كمثال على تعدد المدرّسين لنفس المادة
    q_run("INSERT INTO teachers (subject_id,name_ar,bio,avatar_color,order_index) VALUES (?,?,?,?,1)",
        [$mathId, 'الأستاذ أحمد الرياضي', 'خبرة 12 سنة في تدريس الرياضيات للمرحلة المتوسطة', '#00e6bb']);
    $teacherId = (int) db()->lastInsertId();
    q_run("INSERT INTO teachers (subject_id,name_ar,bio,avatar_color,order_index) VALUES (?,?,?,?,2)",
        [$mathId, 'الأستاذة سارة العزاوي', 'متخصصة في تبسيط المعادلات والهندسة', '#00c4a0']);

    q_run("INSERT INTO units (teacher_id,title_ar,description,order_index) VALUES (?,?,?,1)",
        [$teacherId, 'الفصل الأول: المعادلات الخطية', 'مقدمة في المعادلات الخطية بمتغير واحد وحلولها.']);
    $unitId = (int) db()->lastInsertId();

    $lectures = [
        ['مقدمة في المعادلات الخطية', 'شرح مفهوم المعادلة الخطية وطريقة كتابتها.', 600],
        ['حل المعادلات الخطية بخطوة واحدة', 'أمثلة تطبيقية على حل المعادلات الخطية البسيطة.', 720],
        ['حل المعادلات الخطية بخطوتين', 'كيفية التعامل مع معادلات تحتاج أكثر من خطوة للحل.', 660],
        ['المعادلات ذات الكسور', 'حل المعادلات الخطية التي تحتوي على كسور اعتيادية.', 780],
        ['مسائل تطبيقية على المعادلات الخطية', 'حل مسائل حياتية باستخدام المعادلات الخطية.', 900],
    ];
    $lecture1Id = null;
    foreach ($lectures as $i => $l) {
        q_run("INSERT INTO lectures (unit_id,title_ar,description,duration_seconds,source,order_index) VALUES (?,?,?,?, 'manual',?)",
            [$unitId, $l[0], $l[1], $l[2], $i + 1]);
        if ($i === 0) {
            $lecture1Id = (int) db()->lastInsertId();
        }
    }

    q_run("INSERT INTO quizzes (lecture_id,title,generated_by,difficulty) VALUES (?,?, 'manual','easy')",
        [$lecture1Id, 'اختبار: مقدمة في المعادلات الخطية']);
    $quizId = (int) db()->lastInsertId();

    q_run("INSERT INTO quiz_questions (quiz_id,question_text,explanation,points_value,order_index) VALUES (?,?,?,10,1)",
        [$quizId, 'ما هي درجة المتغير في المعادلة الخطية؟', 'المعادلة الخطية دائماً من الدرجة الأولى (الأس =1).']);
    $q1 = (int) db()->lastInsertId();
    q_run("INSERT INTO quiz_questions (quiz_id,question_text,explanation,points_value,order_index) VALUES (?,?,?,10,2)",
        [$quizId, 'ما ناتج حل المعادلة: س + 5 = 12 ؟', 'بطرح 5 من الطرفين: س = 12 - 5 = 7.']);
    $q2 = (int) db()->lastInsertId();

    q_run("INSERT INTO quiz_options (question_id,option_text,is_correct,order_index) VALUES (?,?,?,?)", [$q1, 'الدرجة الأولى', 1, 1]);
    q_run("INSERT INTO quiz_options (question_id,option_text,is_correct,order_index) VALUES (?,?,?,?)", [$q1, 'الدرجة الثانية', 0, 2]);
    q_run("INSERT INTO quiz_options (question_id,option_text,is_correct,order_index) VALUES (?,?,?,?)", [$q1, 'الدرجة الثالثة', 0, 3]);
    q_run("INSERT INTO quiz_options (question_id,option_text,is_correct,order_index) VALUES (?,?,?,?)", [$q1, 'لا توجد درجة محددة', 0, 4]);
    q_run("INSERT INTO quiz_options (question_id,option_text,is_correct,order_index) VALUES (?,?,?,?)", [$q2, '5', 0, 1]);
    q_run("INSERT INTO quiz_options (question_id,option_text,is_correct,order_index) VALUES (?,?,?,?)", [$q2, '7', 1, 2]);
    q_run("INSERT INTO quiz_options (question_id,option_text,is_correct,order_index) VALUES (?,?,?,?)", [$q2, '17', 0, 3]);
    q_run("INSERT INTO quiz_options (question_id,option_text,is_correct,order_index) VALUES (?,?,?,?)", [$q2, '-7', 0, 4]);

    q_run("INSERT INTO badges (code,name_ar,description_ar,icon,points_required) VALUES ('starter','خطوة أولى','أكمل أول محاضرة لك','fa-shoe-prints',10)");
    q_run("INSERT INTO badges (code,name_ar,description_ar,icon,points_required) VALUES ('century','مئة نقطة','اجمع 100 نقطة','fa-star',100)");
    q_run("INSERT INTO badges (code,name_ar,description_ar,icon,points_required) VALUES ('quiz_master','سيد الاختبارات','احصل على علامة كاملة في اختبار','fa-trophy',0)");
    q_run("INSERT INTO badges (code,name_ar,description_ar,icon,points_required) VALUES ('thousand_club','نادي الألف','اجمع 1000 نقطة','fa-crown',1000)");
}

// ============================================================================
// إعدادات النظام (سرّية عشوائية تُولَّد تلقائياً وتُحفظ داخل قاعدة البيانات نفسها)
// ============================================================================

function app_meta_get(string $key): ?string
{
    $r = q_one("SELECT meta_value FROM app_meta WHERE meta_key=?", [$key]);
    return $r ? $r['meta_value'] : null;
}

function app_meta_set(string $key, string $value): void
{
    q_run(
        "INSERT INTO app_meta (meta_key, meta_value) VALUES (?,?)
         ON CONFLICT(meta_key) DO UPDATE SET meta_value=excluded.meta_value",
        [$key, $value]
    );
}

function get_or_create_secret(string $key, int $bytes = 32): string
{
    $v = app_meta_get($key);
    if ($v) {
        return $v;
    }
    $v = bin2hex(random_bytes($bytes));
    app_meta_set($key, $v);
    return $v;
}

// ============================================================================
// المصادقة: JWT مبسّط (HS256) بدون أي مكتبات خارجية
// ============================================================================

function base64url_encode($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function base64url_decode($data)
{
    $pad = strlen($data) % 4;
    if ($pad) {
        $data .= str_repeat('=', 4 - $pad);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

function jwt_sign($userId, $role, $name): string
{
    $secret = get_or_create_secret('jwt_secret');
    $header = base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $payload = ['sub' => $userId, 'role' => $role, 'name' => $name, 'iat' => time(), 'exp' => time() + 7 * 86400];
    $payloadEnc = base64url_encode(json_encode($payload, JSON_UNESCAPED_UNICODE));
    $sig = base64url_encode(hash_hmac('sha256', "$header.$payloadEnc", $secret, true));
    return "$header.$payloadEnc.$sig";
}

function jwt_verify_token(string $token): array
{
    $secret = get_or_create_secret('jwt_secret');
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        throw new ApiException(401, 'جلسة الدخول غير صالحة أو منتهية');
    }
    [$header, $payload, $sig] = $parts;
    $expected = base64url_encode(hash_hmac('sha256', "$header.$payload", $secret, true));
    if (!hash_equals($expected, $sig)) {
        throw new ApiException(401, 'جلسة الدخول غير صالحة أو منتهية');
    }
    $data = json_decode(base64url_decode($payload), true);
    if (!$data || (isset($data['exp']) && $data['exp'] < time())) {
        throw new ApiException(401, 'جلسة الدخول غير صالحة أو منتهية');
    }
    return $data;
}

function bearer_token(): ?string
{
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
    if (!$header && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $header = $v;
                break;
            }
        }
    }
    if (!$header || stripos($header, 'Bearer ') !== 0) {
        return null;
    }
    return trim(substr($header, 7));
}

function require_auth(): array
{
    $token = bearer_token();
    if (!$token) {
        throw new ApiException(401, 'يجب تسجيل الدخول أولاً');
    }
    $data = jwt_verify_token($token);
    return ['id' => (int) $data['sub'], 'role' => $data['role'], 'name' => $data['name']];
}

function optional_auth(): ?array
{
    $token = bearer_token();
    if (!$token) {
        return null;
    }
    try {
        $data = jwt_verify_token($token);
        return ['id' => (int) $data['sub'], 'role' => $data['role'], 'name' => $data['name']];
    } catch (Throwable $e) {
        return null;
    }
}

function require_admin(array $user): void
{
    if ($user['role'] !== 'admin') {
        throw new ApiException(403, 'هذا الإجراء متاح للمشرفين فقط');
    }
}

// ============================================================================
// تشفير مفتاح DeepSeek قبل تخزينه (AES-256-GCM بمفتاح مُولَّد تلقائياً)
// ============================================================================

function crypto_encrypt(string $plain): string
{
    $key = hex2bin(get_or_create_secret('enc_key'));
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv) . ':' . base64_encode($tag) . ':' . base64_encode($cipher);
}

function crypto_decrypt(?string $payload): ?string
{
    if (!$payload) {
        return null;
    }
    $parts = explode(':', $payload);
    if (count($parts) !== 3) {
        return null;
    }
    [$ivB64, $tagB64, $dataB64] = $parts;
    $key = hex2bin(get_or_create_secret('enc_key'));
    $result = openssl_decrypt(
        base64_decode($dataB64),
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        base64_decode($ivB64),
        base64_decode($tagB64)
    );
    return $result === false ? null : $result;
}

function mask_key(?string $key): ?string
{
    if (!$key) {
        return null;
    }
    return str_repeat('*', max(strlen($key) - 4, 4)) . substr($key, -4);
}

// ============================================================================
// خدمة YouTube Data API v3 (اختيارية) — تبحث عن فيديو تعليمي حقيقي لاعتماد
// رابط "موثوق" للمحاضرة بدل عرض رابط بحث فقط. إن لم يُضبط مفتاحها تبقى كل
// المحاضرات تعرض رسالة "سيتم إضافة الفيديو قريباً" كما في السابق، بلا أي خطأ.
// ============================================================================

function get_youtube_api_key(): ?string
{
    $row = q_one("SELECT api_key_encrypted FROM api_settings WHERE provider='youtube' LIMIT 1");
    if (!$row || !$row['api_key_encrypted']) {
        return null;
    }
    return crypto_decrypt($row['api_key_encrypted']);
}

function youtube_search_video(string $searchQuery): ?array
{
    $apiKey = get_youtube_api_key();
    if (!$apiKey || !trim($searchQuery)) {
        return null;
    }

    $url = 'https://www.googleapis.com/youtube/v3/search?' . http_build_query([
        'key' => $apiKey,
        'q' => $searchQuery,
        'part' => 'snippet',
        'type' => 'video',
        'maxResults' => 5,
        'relevanceLanguage' => 'ar',
        'safeSearch' => 'strict',
        'videoEmbeddable' => 'true',
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $status >= 400) {
        return null;
    }

    $data = json_decode($response, true);
    $first = $data['items'][0] ?? null;
    if (!$first) {
        return null;
    }
    return [
        'videoId' => $first['id']['videoId'],
        'thumbnailUrl' => $first['snippet']['thumbnails']['medium']['url'] ?? null,
    ];
}

// يبحث عن قائمة تشغيل حقيقية (بدل فيديو منفرد) لتُستخدم كمصدر محاضرات وحدة
// كاملة: عدد المحاضرات وترقيمها وعناوينها تصبح مطابقة تماماً لقائمة التشغيل
// الفعلية على يوتيوب. اختياري تماماً: بلا مفتاح، يستمر النظام بالأسلوب القديم
// (عناوين يقترحها الذكاء الاصطناعي).
function youtube_search_playlist(string $query): ?array
{
    $apiKey = get_youtube_api_key();
    if (!$apiKey || !trim($query)) {
        return null;
    }

    $url = 'https://www.googleapis.com/youtube/v3/search?' . http_build_query([
        'key' => $apiKey,
        'q' => $query,
        'part' => 'snippet',
        'type' => 'playlist',
        'maxResults' => 1,
        'relevanceLanguage' => 'ar',
        'safeSearch' => 'strict',
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $status >= 400) {
        return null;
    }

    $data = json_decode($response, true);
    $first = $data['items'][0] ?? null;
    if (!$first) {
        return null;
    }
    return ['playlistId' => $first['id']['playlistId'], 'title' => $first['snippet']['title'] ?? ''];
}

// فيديوهات قائمة تشغيل حقيقية بترتيبها الأصلي (المحاضرة الأولى، الثانية...)
function youtube_get_playlist_items(string $playlistId, int $maxItems = 20): array
{
    $apiKey = get_youtube_api_key();
    if (!$apiKey || !trim($playlistId)) {
        return [];
    }

    $url = 'https://www.googleapis.com/youtube/v3/playlistItems?' . http_build_query([
        'key' => $apiKey,
        'playlistId' => $playlistId,
        'part' => 'snippet',
        'maxResults' => max(1, min($maxItems, 50)),
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 12]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($response === false || $status >= 400) {
        return [];
    }

    $data = json_decode($response, true);
    $items = [];
    foreach (($data['items'] ?? []) as $item) {
        $videoId = $item['snippet']['resourceId']['videoId'] ?? null;
        $title = $item['snippet']['title'] ?? '';
        if (!$videoId || $title === '' || $title === 'Private video' || $title === 'Deleted video') {
            continue; // فيديوهات محذوفة/خاصة لا تظهر في الاستجابة بشكل صالح
        }
        $items[] = [
            'videoId' => $videoId,
            'title' => $title,
            'thumbnailUrl' => $item['snippet']['thumbnails']['medium']['url'] ?? ($item['snippet']['thumbnails']['default']['url'] ?? null),
            'position' => (int) ($item['snippet']['position'] ?? count($items)),
        ];
    }
    usort($items, fn ($a, $b) => $a['position'] <=> $b['position']);
    return $items;
}

// احتياطي بلا أي مفتاح أو تسجيل دخول: يطلب صفحة نتائج بحث يوتيوب العامة تماماً
// كما يفعل أي زائر غير مسجَّل دخوله، ويستخرج أول معرّف فيديو من الصفحة. هذا
// ليس رسمياً ولا مضموناً (يعتمد على بنية صفحة يوتيوب الحالية)، لذا يُستخدم
// فقط عندما لا يوجد مفتاح YouTube API مضبوط أو لم يُعثر عبره على نتيجة، كي
// تظهر محاضرة بفيديو حقيقي بدل رسالة "قريباً" حتى بلا أي إعداد من الأدمن.
function youtube_scrape_first_video_id(string $searchQuery): ?string
{
    if (!trim($searchQuery)) {
        return null;
    }
    $url = 'https://www.youtube.com/results?search_query=' . urlencode($searchQuery);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
        CURLOPT_HTTPHEADER => ['Accept-Language: ar,en;q=0.8'],
    ]);
    $html = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!$html || $status >= 400) {
        return null;
    }
    if (preg_match('/"videoId":"([a-zA-Z0-9_-]{11})"/', $html, $m)) {
        return $m[1];
    }
    return null;
}

function youtube_test_connection(): array
{
    $apiKey = get_youtube_api_key();
    if (!$apiKey) {
        throw new ApiException(412, 'لم يتم ضبط مفتاح YouTube API بعد.');
    }

    $url = 'https://www.googleapis.com/youtube/v3/search?' . http_build_query([
        'key' => $apiKey, 'q' => 'شرح تعليمي', 'part' => 'snippet', 'type' => 'video', 'maxResults' => 1,
    ]);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new ApiException(502, 'تعذّر الوصول إلى YouTube API: ' . $curlError);
    }
    $data = json_decode($response, true);
    if ($status >= 400) {
        $msg = $data['error']['message'] ?? 'خطأ غير معروف';
        throw new ApiException(502, "فشل الاتصال بـ YouTube API ($status): $msg");
    }
    return ['ok' => true, 'sample' => 'تم العثور على ' . count($data['items'] ?? []) . ' نتيجة تجريبية'];
}

// ============================================================================
// خدمة DeepSeek (عبر curl) — توليد المناهج، الاختبارات، والمساعد الذكي
// ============================================================================

function get_deepseek_config(): array
{
    $row = q_one("SELECT * FROM api_settings WHERE provider='deepseek' LIMIT 1");
    if (!$row) {
        return ['apiKey' => null, 'baseUrl' => 'https://api.deepseek.com', 'model' => 'deepseek-chat', 'source' => 'none'];
    }
    $key = $row['api_key_encrypted'] ? crypto_decrypt($row['api_key_encrypted']) : null;
    return [
        'apiKey' => $key,
        'baseUrl' => $row['api_base_url'] ?: 'https://api.deepseek.com',
        'model' => $row['model_name'] ?: 'deepseek-chat',
        'source' => $key ? 'database' : 'none',
    ];
}

function deepseek_chat(array $messages, float $temperature = 0.6, bool $jsonMode = false, int $maxTokens = 2000): array
{
    $config = get_deepseek_config();
    if (!$config['apiKey']) {
        throw new ApiException(412, 'لم يتم ضبط مفتاح الذكاء الاصطناعي بعد. أضِفه من صفحة الإعدادات (Settings) في لوحة التحكم.');
    }

    $payload = ['model' => $config['model'], 'messages' => $messages, 'temperature' => $temperature, 'max_tokens' => $maxTokens];
    if ($jsonMode) {
        $payload['response_format'] = ['type' => 'json_object'];
    }

    $ch = curl_init(rtrim($config['baseUrl'], '/') . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $config['apiKey']],
        CURLOPT_TIMEOUT => 60,
    ]);
    $responseBody = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($responseBody === false) {
        throw new ApiException(502, 'تعذّر الوصول إلى DeepSeek API: ' . $curlError);
    }
    $data = json_decode($responseBody, true);
    if ($status >= 400) {
        $msg = $data['error']['message'] ?? 'خطأ غير معروف';
        throw new ApiException(502, "فشل الاتصال بـ DeepSeek ($status): $msg");
    }
    return ['content' => $data['choices'][0]['message']['content'] ?? '', 'usage' => $data['usage'] ?? null];
}

function deepseek_parse_json(string $text): array
{
    $cleaned = trim(preg_replace('/```json|```/i', '', $text));
    $decoded = json_decode($cleaned, true);
    if ($decoded === null) {
        throw new ApiException(502, 'رد الذكاء الاصطناعي لم يكن بصيغة JSON صالحة');
    }
    return $decoded;
}

// يولّد المنهج على مرحلتين بدل نداء واحد ضخم: أولاً قائمة المواد فقط (رد صغير
// وموثوق)، ثم وحدات/محاضرات كل مادة في نداء منفصل. طلب 5 مواد × 4 وحدات × 6
// محاضرات دفعة واحدة ينتج رداً كبيراً قد يتجاوز max_tokens فيُقطع في المنتصف
// ويفشل تحليل JSON — تقسيم النداءات يضمن أن كل رد يبقى صغيراً بما يكفي دائماً.
function deepseek_generate_subject_list(string $countryName, string $stageName, int $subjectCount = 5): array
{
    $system = 'أنت خبير مناهج تعليمية عربية. أعد النتيجة بصيغة JSON فقط بدون أي شرح إضافي.';
    $user = "اقترح $subjectCount مواد دراسية رئيسية مناسبة لدولة \"$countryName\" وللمرحلة \"$stageName\"،\n"
        . "مع اسم مدرّس واحد مقترح لكل مادة وأيقونة Font Awesome مناسبة (اسم الأيقونة فقط مثل fa-atom).\n\n"
        . 'أعد الناتج بهذا الشكل بالضبط (JSON فقط):'
        . '{"subjects":[{"name_ar":"اسم المادة","icon":"fa-book","teacher_name":"اسم المدرّس المقترح"}]}';

    $r = deepseek_chat([['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]], 0.5, true, 1200);
    return deepseek_parse_json($r['content']);
}

function deepseek_generate_subject_units(string $countryName, string $stageName, string $subjectName, int $unitsCount = 4, int $lecturesPerUnit = 6): array
{
    $system = 'أنت خبير مناهج تعليمية عربية متخصص بتصميم محتوى مادة دراسية واحدة بالتفصيل. '
        . 'أعد النتيجة بصيغة JSON فقط بدون أي شرح إضافي.';
    $user = "صمّم منهج مادة \"$subjectName\" لدولة \"$countryName\" وللمرحلة \"$stageName\".\n"
        . "أعد $unitsCount وحدات/فصول بترتيب منطقي، ولكل وحدة $lecturesPerUnit محاضرات بعناوين ووصف قصير،\n"
        . "مع اقتراح search_query (عبارة بحث يوتيوب بالعربية) تساعد لاحقاً بإيجاد فيديو تعليمي مناسب لكل محاضرة.\n\n"
        . 'أعد الناتج بهذا الشكل بالضبط (JSON فقط):'
        . '{"units":[{"title_ar":"عنوان الوحدة","description":"وصف قصير","lectures":[{"title_ar":"عنوان المحاضرة","description":"وصف قصير","search_query":"عبارة بحث"}]}]}';

    $r = deepseek_chat([['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]], 0.5, true, 3000);
    return deepseek_parse_json($r['content']);
}

function deepseek_generate_quiz(string $title, ?string $description, ?string $transcript): array
{
    $system = 'أنت مساعد تعليمي متخصص بإعداد اختبارات اختيار من متعدد بالعربية. أعد النتيجة بصيغة JSON فقط.';
    $context = $transcript
        ? ('محتوى المحاضرة: ' . mb_substr($transcript, 0, 6000))
        : ('وصف المحاضرة: ' . ($description ?: 'غير متوفر'));
    $user = "أنشئ اختباراً من 5 أسئلة اختيار من متعدد حول محاضرة بعنوان \"$title\".\n$context\n\n"
        . 'لكل سؤال 4 خيارات وخيار صحيح واحد فقط وشرح مختصر للإجابة. أعد الناتج بهذا الشكل بالضبط (JSON فقط):'
        . '{"questions":[{"question_text":"...","explanation":"...","options":[{"text":"...","is_correct":false},{"text":"...","is_correct":true},{"text":"...","is_correct":false},{"text":"...","is_correct":false}]}]}';

    $r = deepseek_chat([['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]], 0.4, true, 3000);
    return deepseek_parse_json($r['content']);
}

function deepseek_tutor_reply(string $title, ?string $description, ?string $transcript, array $history, string $userMessage): array
{
    $system = "أنت مدرّس خصوصي ذكي ومحفّز لطالب عربي. أجب فقط ضمن سياق المحاضرة الحالية، بسّط الأفكار المعقدة بأمثلة "
        . "قريبة من واقع الطالب، وكن مختصراً ومشجعاً.\n\n"
        . "عنوان المحاضرة: $title\nوصف المحاضرة: " . ($description ?: 'غير متوفر')
        . ($transcript ? ("\nملخص محتوى المحاضرة: " . mb_substr($transcript, 0, 4000)) : '');

    $messages = [['role' => 'system', 'content' => $system]];
    foreach ($history as $m) {
        $messages[] = ['role' => $m['role'], 'content' => $m['message_text']];
    }
    $messages[] = ['role' => 'user', 'content' => $userMessage];

    $r = deepseek_chat($messages, 0.6, false, 800);
    return ['reply' => $r['content'], 'usage' => $r['usage']];
}

// ============================================================================
// النقاط
// ============================================================================

function award_points(int $userId, int $points, string $reason, ?string $refType = null, $refId = null): void
{
    if ($points <= 0) {
        return;
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q_run(
            "INSERT INTO points_transactions (user_id, points, reason, reference_type, reference_id) VALUES (?,?,?,?,?)",
            [$userId, $points, $reason, $refType, $refId]
        );
        q_run("UPDATE users SET points_total = points_total + ? WHERE id=?", [$points, $userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ============================================================================
// معالجات المسارات (Route Handlers)
// ============================================================================

function public_user(array $u): array
{
    return [
        'id' => (int) $u['id'],
        'name' => $u['name'],
        'email' => $u['email'],
        'role' => $u['role'],
        'countryId' => $u['country_id'] !== null ? (int) $u['country_id'] : null,
        'governorateId' => $u['governorate_id'] !== null ? (int) $u['governorate_id'] : null,
        'stageId' => $u['stage_id'] !== null ? (int) $u['stage_id'] : null,
        'avatarUrl' => $u['avatar_url'],
        'pointsTotal' => (int) $u['points_total'],
    ];
}

function h_register(array $body): void
{
    $name = trim($body['name'] ?? '');
    $email = trim($body['email'] ?? '');
    $password = (string) ($body['password'] ?? '');
    if (!$name || !$email || strlen($password) < 6) {
        throw new ApiException(400, 'الاسم والبريد الإلكتروني مطلوبان، وكلمة المرور 6 محارف على الأقل');
    }
    if (q_one("SELECT id FROM users WHERE email=?", [$email])) {
        throw new ApiException(409, 'هذا البريد الإلكتروني مسجّل مسبقاً');
    }

    // أول مستخدم يُسجَّل في الموقع يصبح أدمن تلقائياً (لا حاجة لأي أداة إدارة قاعدة بيانات)
    $userCount = (int) q_one("SELECT COUNT(*) as c FROM users")['c'];
    $role = $userCount === 0 ? 'admin' : 'student';

    $hash = password_hash($password, PASSWORD_BCRYPT);
    q_run(
        "INSERT INTO users (name,email,password_hash,role,country_id,governorate_id,stage_id) VALUES (?,?,?,?,?,?,?)",
        [$name, $email, $hash, $role, $body['countryId'] ?? null, $body['governorateId'] ?? null, $body['stageId'] ?? null]
    );
    $id = (int) db()->lastInsertId();
    $user = q_one("SELECT * FROM users WHERE id=?", [$id]);
    $token = jwt_sign($user['id'], $user['role'], $user['name']);
    json_response(['token' => $token, 'user' => public_user($user)], 201);
}

function h_login(array $body): void
{
    $email = trim($body['email'] ?? '');
    $password = (string) ($body['password'] ?? '');
    if (!$email || !$password) {
        throw new ApiException(400, 'البريد الإلكتروني وكلمة المرور مطلوبان');
    }
    $user = q_one("SELECT * FROM users WHERE email=?", [$email]);
    if (!$user || !$user['is_active']) {
        throw new ApiException(401, 'بيانات الدخول غير صحيحة');
    }
    if (!password_verify($password, $user['password_hash'])) {
        throw new ApiException(401, 'بيانات الدخول غير صحيحة');
    }
    q_run("UPDATE users SET last_login_at=CURRENT_TIMESTAMP WHERE id=?", [$user['id']]);
    $token = jwt_sign($user['id'], $user['role'], $user['name']);
    json_response(['token' => $token, 'user' => public_user($user)]);
}

function h_me(array $authUser): void
{
    $user = q_one("SELECT * FROM users WHERE id=?", [$authUser['id']]);
    if (!$user) {
        throw new ApiException(404, 'المستخدم غير موجود');
    }
    json_response(['user' => public_user($user)]);
}

function h_countries(): void
{
    json_response(['countries' => q_all("SELECT id, code, name_ar, name_en, flag_emoji FROM countries WHERE is_active=1 ORDER BY name_ar")]);
}

function h_country_stages($countryId): void
{
    $rows = q_all(
        "SELECT id, name_ar, name_en, education_level, level_order FROM stages WHERE country_id=? AND is_active=1 ORDER BY level_order",
        [$countryId]
    );
    json_response(['stages' => $rows]);
}

// محافظات الدولة (إن وُجدت). قائمة فارغة تعني أن هذه الدولة لا تملك محافظات
// مضبوطة، فتُخطّى خطوة اختيار المحافظة تلقائياً في واجهة الاختيار.
function h_country_governorates($countryId): void
{
    $rows = q_all(
        "SELECT id, name_ar, order_index FROM governorates WHERE country_id=? AND is_active=1 ORDER BY order_index, name_ar",
        [$countryId]
    );
    json_response(['governorates' => $rows]);
}

// يحفظ اختيار الطالب (الدولة/المحافظة/المرحلة) على حسابه مباشرة بدل الاكتفاء
// بتخزينه محلياً في المتصفح، كي يظهر نفس الاختيار عند الدخول من أي جهاز آخر.
function h_update_selection(array $body, array $authUser): void
{
    $countryId = !empty($body['countryId']) ? (int) $body['countryId'] : null;
    $stageId = !empty($body['stageId']) ? (int) $body['stageId'] : null;
    $governorateId = !empty($body['governorateId']) ? (int) $body['governorateId'] : null;

    if ($countryId && !q_one("SELECT id FROM countries WHERE id=?", [$countryId])) {
        throw new ApiException(400, 'الدولة المحددة غير موجودة');
    }
    if ($stageId) {
        $stage = q_one("SELECT id FROM stages WHERE id=? AND country_id=?", [$stageId, $countryId]);
        if (!$stage) {
            throw new ApiException(400, 'المرحلة الدراسية المحددة غير موجودة أو لا تطابق الدولة');
        }
    }
    if ($governorateId) {
        $gov = q_one("SELECT id FROM governorates WHERE id=? AND country_id=?", [$governorateId, $countryId]);
        if (!$gov) {
            throw new ApiException(400, 'المحافظة المحددة غير موجودة أو لا تطابق الدولة');
        }
    }

    q_run(
        "UPDATE users SET country_id=?, governorate_id=?, stage_id=?, updated_at=CURRENT_TIMESTAMP WHERE id=?",
        [$countryId, $governorateId, $stageId, $authUser['id']]
    );
    $user = q_one("SELECT * FROM users WHERE id=?", [$authUser['id']]);
    json_response(['message' => 'تم حفظ اختيارك بنجاح', 'user' => public_user($user)]);
}

function h_stage_subjects($stageId): void
{
    $rows = q_all(
        "SELECT id, name_ar, name_en, icon, color_hex, order_index FROM subjects WHERE stage_id=? ORDER BY order_index, name_ar",
        [$stageId]
    );
    json_response(['subjects' => $rows, 'needsGeneration' => count($rows) === 0]);
}

function h_subject_detail($subjectId): void
{
    $s = q_one("SELECT id, stage_id, name_ar, name_en, icon, color_hex FROM subjects WHERE id=?", [$subjectId]);
    if (!$s) {
        throw new ApiException(404, 'المادة غير موجودة');
    }
    json_response(['subject' => $s]);
}

// المدرّسون مرتّبون حسب مجموع مشاهدات محاضراتهم داخل التطبيق تنازلياً (الأكثر
// مشاهدة أولاً)، ثم ترتيب الإدخال كخيار ثابت للمدرّسين الجدد بلا مشاهدات بعد
function h_subject_teachers($subjectId): void
{
    $rows = q_all(
        "SELECT t.id, t.name_ar, t.bio, t.avatar_color, t.order_index,
                (SELECT COUNT(*) FROM units u WHERE u.teacher_id=t.id) as unit_count,
                (SELECT COUNT(*) FROM lectures l JOIN units u ON u.id=l.unit_id WHERE u.teacher_id=t.id) as lecture_count,
                (SELECT COALESCE(SUM(l.view_count),0) FROM lectures l JOIN units u ON u.id=l.unit_id WHERE u.teacher_id=t.id) as total_views
         FROM teachers t WHERE t.subject_id=? ORDER BY total_views DESC, t.order_index, t.name_ar",
        [$subjectId]
    );
    foreach ($rows as &$r) {
        $r['unit_count'] = (int) $r['unit_count'];
        $r['lecture_count'] = (int) $r['lecture_count'];
        $r['total_views'] = (int) $r['total_views'];
    }
    unset($r);
    json_response(['teachers' => $rows]);
}

function h_teacher_detail($teacherId): void
{
    $t = q_one(
        "SELECT t.id, t.name_ar, t.bio, t.avatar_color, t.subject_id, s.name_ar as subject_name
         FROM teachers t JOIN subjects s ON s.id=t.subject_id WHERE t.id=?",
        [$teacherId]
    );
    if (!$t) {
        throw new ApiException(404, 'المدرّس غير موجود');
    }
    json_response(['teacher' => $t]);
}

function h_teacher_units($teacherId): void
{
    $rows = q_all(
        "SELECT u.id, u.title_ar, u.description, u.order_index,
                (SELECT COUNT(*) FROM lectures l WHERE l.unit_id=u.id) as lecture_count
         FROM units u WHERE u.teacher_id=? ORDER BY u.order_index",
        [$teacherId]
    );
    foreach ($rows as &$r) {
        $r['lecture_count'] = (int) $r['lecture_count'];
    }
    unset($r);
    json_response(['units' => $rows]);
}

function h_unit_detail($unitId): void
{
    $u = q_one(
        "SELECT u.id, u.title_ar, u.description, u.teacher_id, t.name_ar as teacher_name,
                t.subject_id, s.name_ar as subject_name
         FROM units u
         JOIN teachers t ON t.id=u.teacher_id
         JOIN subjects s ON s.id=t.subject_id
         WHERE u.id=?",
        [$unitId]
    );
    if (!$u) {
        throw new ApiException(404, 'الوحدة غير موجودة');
    }
    json_response(['unit' => $u]);
}

function h_unit_lectures($unitId): void
{
    $rows = q_all(
        "SELECT id, title_ar, description, youtube_video_id, youtube_url, is_link_verified,
                duration_seconds, thumbnail_url, order_index
         FROM lectures WHERE unit_id=? ORDER BY order_index",
        [$unitId]
    );
    json_response(['lectures' => $rows]);
}

function h_generate_curriculum($stageId): void
{
    $stage = q_one(
        "SELECT s.*, c.name_ar as country_name_ar FROM stages s JOIN countries c ON c.id=s.country_id WHERE s.id=?",
        [$stageId]
    );
    if (!$stage) {
        throw new ApiException(404, 'المرحلة الدراسية غير موجودة');
    }
    if (q_one("SELECT id FROM subjects WHERE stage_id=?", [$stageId])) {
        throw new ApiException(409, 'تم توليد المنهج لهذه المرحلة مسبقاً');
    }

    q_run(
        "INSERT INTO ai_generation_jobs (job_type, country_id, stage_id, status, request_payload) VALUES ('curriculum', ?, ?, 'processing', ?)",
        [$stage['country_id'], $stageId, json_encode(['stageName' => $stage['name_ar']], JSON_UNESCAPED_UNICODE)]
    );
    $jobId = (int) db()->lastInsertId();

    try {
        $subjectList = deepseek_generate_subject_list($stage['country_name_ar'], $stage['name_ar']);
    } catch (Throwable $e) {
        q_run(
            "UPDATE ai_generation_jobs SET status='failed', error_message=?, completed_at=CURRENT_TIMESTAMP WHERE id=?",
            [mb_substr($e->getMessage(), 0, 500), $jobId]
        );
        throw $e;
    }

    $pdo = db();
    $subjectCount = 0;
    $teacherCount = 0;
    $unitCount = 0;
    $lectureCount = 0;
    $failedSubjects = [];
    $lastError = null;

    // كل مادة تُعالَج بنداء AI منفصل خاص بها فقط (وليس نداءً واحداً ضخماً لكل
    // المواد معاً)، كي يبقى كل رد صغيراً وموثوقاً، ولضمان أن فشل توليد محتوى
    // مادة واحدة لا يُسقط بقية المواد التي نجحت
    foreach (($subjectList['subjects'] ?? []) as $sIndex => $subject) {
        $pdo->beginTransaction();
        try {
            q_run(
                "INSERT INTO subjects (stage_id, name_ar, icon, order_index, is_ai_generated) VALUES (?,?,?,?,1)",
                [$stageId, $subject['name_ar'], $subject['icon'] ?? 'fa-book', $sIndex + 1]
            );
            $subjectCount++;
            $subjectId = (int) $pdo->lastInsertId();

            $teacherName = trim($subject['teacher_name'] ?? '') ?: ('فريق ' . $subject['name_ar']);
            q_run(
                "INSERT INTO teachers (subject_id, name_ar, bio, order_index) VALUES (?,?,?,1)",
                [$subjectId, $teacherName, 'محتوى تعليمي شامل لمادة ' . $subject['name_ar']]
            );
            $teacherCount++;
            $teacherId = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $failedSubjects[] = $subject['name_ar'] ?? ('مادة #' . ($sIndex + 1));
            $lastError = $e->getMessage();
            continue;
        }

        try {
            $subjectContent = deepseek_generate_subject_units($stage['country_name_ar'], $stage['name_ar'], $subject['name_ar']);
        } catch (Throwable $e) {
            // المادة والمدرّس أُنشئا بنجاح، لكن تعذّر توليد الوحدات/المحاضرات لهما الآن
            $failedSubjects[] = $subject['name_ar'];
            $lastError = $e->getMessage();
            continue;
        }

        // نبحث عن قائمة تشغيل حقيقية لكل وحدة *قبل* فتح أي معاملة كتابة، لأن
        // نداءات الشبكة هذه قد تستغرق ثوانٍ لكل وحدة ويجب ألا تُبقي معاملة
        // SQLite مفتوحة طوال تلك المدة. بلا مفتاح YouTube تعود القائمة فارغة
        // فوراً بلا أي نداء شبكة، فيستمر الأسلوب القديم كما هو تماماً.
        $unitsWithSource = [];
        foreach (($subjectContent['units'] ?? []) as $uIndex => $unit) {
            $playlistItems = [];
            $playlist = youtube_search_playlist(trim($subject['name_ar'] . ' ' . $unit['title_ar'] . ' ' . $teacherName));
            if ($playlist) {
                $playlistItems = youtube_get_playlist_items($playlist['playlistId'], 20);
            }
            $unitsWithSource[] = ['unit' => $unit, 'playlistItems' => $playlistItems];
        }

        $pdo->beginTransaction();
        try {
            foreach ($unitsWithSource as $uIndex => $entry) {
                $unit = $entry['unit'];
                q_run(
                    "INSERT INTO units (teacher_id, title_ar, description, order_index, is_ai_generated) VALUES (?,?,?,?,1)",
                    [$teacherId, $unit['title_ar'], $unit['description'] ?? null, $uIndex + 1]
                );
                $unitCount++;
                $unitId = (int) $pdo->lastInsertId();

                if ($entry['playlistItems']) {
                    // مصدر حقيقي: محاضرة واحدة لكل فيديو في قائمة التشغيل، بنفس
                    // عناوينها وترتيبها الأصلي على يوتيوب (محاضرة أولى، ثانية...)
                    foreach ($entry['playlistItems'] as $lIndex => $item) {
                        $videoUrl = 'https://www.youtube.com/watch?v=' . $item['videoId'];
                        q_run(
                            "INSERT INTO lectures (unit_id, title_ar, youtube_video_id, youtube_url, is_link_verified, thumbnail_url, source, order_index)
                             VALUES (?,?,?,?,1,?,'youtube_playlist',?)",
                            [$unitId, $item['title'], $item['videoId'], $videoUrl, $item['thumbnailUrl'], $lIndex + 1]
                        );
                        $lectureCount++;
                    }
                } else {
                    // لا توجد قائمة تشغيل مطابقة (أو لا مفتاح مضبوط أصلاً): نستخدم
                    // عناوين اقترحها الذكاء الاصطناعي، ويُحل فيديو كل محاضرة لاحقاً
                    // عند فتحها لأول مرة (انظر h_lecture_detail)
                    foreach (($unit['lectures'] ?? []) as $lIndex => $lecture) {
                        $searchQuery = $lecture['search_query'] ?? $lecture['title_ar'];
                        $fallbackUrl = 'https://www.youtube.com/results?search_query=' . urlencode($searchQuery);
                        q_run(
                            "INSERT INTO lectures (unit_id, title_ar, description, youtube_url, is_link_verified, source, order_index)
                             VALUES (?,?,?,?,0,'ai_curated',?)",
                            [$unitId, $lecture['title_ar'], $lecture['description'] ?? null, $fallbackUrl, $lIndex + 1]
                        );
                        $lectureCount++;
                    }
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $failedSubjects[] = $subject['name_ar'];
            $lastError = $e->getMessage();
        }
    }

    $allFailed = $subjectCount === 0 || count($failedSubjects) >= $subjectCount;
    q_run(
        "UPDATE ai_generation_jobs SET status=?, completed_at=CURRENT_TIMESTAMP, response_summary=? WHERE id=?",
        [
            $allFailed ? 'failed' : 'completed',
            json_encode(compact('subjectCount', 'teacherCount', 'unitCount', 'lectureCount', 'failedSubjects'), JSON_UNESCAPED_UNICODE),
            $jobId,
        ]
    );

    if ($allFailed) {
        $detail = $lastError ? (' السبب: ' . $lastError) : '';
        throw new ApiException(502, 'تعذّر توليد المنهج بالكامل.' . $detail);
    }

    $message = 'تم توليد المنهج بنجاح';
    if ($failedSubjects) {
        $message .= '. ملاحظة: تعذّر توليد محتوى المواد التالية (أُنشئت المادة والمدرّس فقط، بلا وحدات بعد): '
            . implode('، ', $failedSubjects);
    }

    json_response([
        'message' => $message,
        'subjectCount' => $subjectCount,
        'teacherCount' => $teacherCount,
        'unitCount' => $unitCount,
        'lectureCount' => $lectureCount,
        'failedSubjects' => $failedSubjects,
    ], 201);
}

function h_lecture_detail($id, ?array $user): void
{
    $lecture = q_one(
        "SELECT l.*, u.title_ar as unit_title, u.teacher_id, t.name_ar as teacher_name,
                t.subject_id, s.name_ar as subject_name
         FROM lectures l
         JOIN units u ON u.id=l.unit_id
         JOIN teachers t ON t.id=u.teacher_id
         JOIN subjects s ON s.id=t.subject_id
         WHERE l.id=?",
        [$id]
    );
    if (!$lecture) {
        throw new ApiException(404, 'المحاضرة غير موجودة');
    }

    // إن لم يكن للمحاضرة فيديو مضبوط بعد (شائع في المحتوى المولَّد آلياً قبل
    // تفعيل مفتاح YouTube)، نحاول إيجاد فيديو حقيقي الآن ونخزّنه في القاعدة
    // كي تُعرض المحاضرة مباشرة من غير بحث متكرر في كل مرة تُفتح فيها.
    if (!$lecture['youtube_video_id']) {
        $searchQuery = trim($lecture['title_ar'] . ' ' . $lecture['subject_name']);
        $found = youtube_search_video($searchQuery);
        if ($found) {
            $videoUrl = 'https://www.youtube.com/watch?v=' . $found['videoId'];
            q_run(
                "UPDATE lectures SET youtube_video_id=?, youtube_url=?, is_link_verified=1, thumbnail_url=? WHERE id=?",
                [$found['videoId'], $videoUrl, $found['thumbnailUrl'], $lecture['id']]
            );
            $lecture['youtube_video_id'] = $found['videoId'];
            $lecture['youtube_url'] = $videoUrl;
            $lecture['is_link_verified'] = 1;
            $lecture['thumbnail_url'] = $found['thumbnailUrl'];
        } else {
            // لا مفتاح مضبوط أو لم يُعثر عبره على نتيجة: نحاول احتياطياً بلا
            // أي مفتاح (بحث عام غير مسجَّل الدخول) بدل ترك المحاضرة بلا فيديو
            $scrapedId = youtube_scrape_first_video_id($searchQuery);
            if ($scrapedId) {
                $videoUrl = 'https://www.youtube.com/watch?v=' . $scrapedId;
                q_run(
                    "UPDATE lectures SET youtube_video_id=?, youtube_url=? WHERE id=?",
                    [$scrapedId, $videoUrl, $lecture['id']]
                );
                $lecture['youtube_video_id'] = $scrapedId;
                $lecture['youtube_url'] = $videoUrl;
            }
        }
    }

    $progress = null;
    if ($user) {
        $progress = q_one(
            "SELECT watched_seconds, is_completed, completed_at FROM lecture_progress WHERE user_id=? AND lecture_id=?",
            [$user['id'], $lecture['id']]
        );
        if (!$progress) {
            $progress = ['watched_seconds' => 0, 'is_completed' => 0, 'completed_at' => null];
        }
    }
    q_run("UPDATE lectures SET view_count = view_count + 1 WHERE id=?", [$lecture['id']]);
    json_response(['lecture' => $lecture, 'progress' => $progress]);
}

function h_lecture_progress($lectureId, array $body, array $user): void
{
    $watchedSeconds = (int) ($body['watchedSeconds'] ?? 0);
    $completed = !empty($body['completed']);
    if (!q_one("SELECT id FROM lectures WHERE id=?", [$lectureId])) {
        throw new ApiException(404, 'المحاضرة غير موجودة');
    }

    q_run(
        "INSERT INTO lecture_progress (user_id, lecture_id, watched_seconds, is_completed, completed_at)
         VALUES (?,?,?,?,?)
         ON CONFLICT(user_id, lecture_id) DO UPDATE SET
           watched_seconds = MAX(watched_seconds, excluded.watched_seconds),
           is_completed = is_completed OR excluded.is_completed,
           completed_at = COALESCE(completed_at, excluded.completed_at)",
        [$user['id'], $lectureId, $watchedSeconds, $completed ? 1 : 0, $completed ? date('Y-m-d H:i:s') : null]
    );

    $pointsAwarded = 0;
    if ($completed) {
        $row = q_one("SELECT points_awarded FROM lecture_progress WHERE user_id=? AND lecture_id=?", [$user['id'], $lectureId]);
        if ($row && !$row['points_awarded']) {
            award_points($user['id'], POINTS_LECTURE_COMPLETE, 'lecture_complete', 'lecture', $lectureId);
            q_run("UPDATE lecture_progress SET points_awarded=1 WHERE user_id=? AND lecture_id=?", [$user['id'], $lectureId]);
            $pointsAwarded = POINTS_LECTURE_COMPLETE;
        }
    }
    json_response(['ok' => true, 'pointsAwarded' => $pointsAwarded]);
}

function load_quiz_with_questions($quizId, bool $includeAnswers): ?array
{
    $quiz = q_one("SELECT * FROM quizzes WHERE id=?", [$quizId]);
    if (!$quiz) {
        return null;
    }
    $questions = q_all(
        "SELECT id, question_text, explanation, points_value, order_index FROM quiz_questions WHERE quiz_id=? ORDER BY order_index",
        [$quizId]
    );
    $cols = $includeAnswers ? "id, question_id, option_text, is_correct, order_index" : "id, question_id, option_text, order_index";
    foreach ($questions as &$q) {
        $q['options'] = q_all("SELECT $cols FROM quiz_options WHERE question_id=? ORDER BY order_index", [$q['id']]);
    }
    unset($q);
    return ['quiz' => $quiz, 'questions' => $questions];
}

function h_get_quiz($lectureId, array $user): void
{
    $lecture = q_one("SELECT * FROM lectures WHERE id=?", [$lectureId]);
    if (!$lecture) {
        throw new ApiException(404, 'المحاضرة غير موجودة');
    }

    $existing = q_one("SELECT id FROM quizzes WHERE lecture_id=? LIMIT 1", [$lectureId]);
    if (!$existing) {
        $generated = deepseek_generate_quiz($lecture['title_ar'], $lecture['description'], $lecture['transcript_text']);
        $pdo = db();
        $pdo->beginTransaction();
        try {
            q_run("INSERT INTO quizzes (lecture_id, title, generated_by) VALUES (?,?, 'ai')", [$lectureId, 'اختبار: ' . $lecture['title_ar']]);
            $quizId = (int) $pdo->lastInsertId();
            foreach (($generated['questions'] ?? []) as $qIndex => $question) {
                q_run(
                    "INSERT INTO quiz_questions (quiz_id, question_text, explanation, order_index) VALUES (?,?,?,?)",
                    [$quizId, $question['question_text'], $question['explanation'] ?? null, $qIndex + 1]
                );
                $questionId = (int) $pdo->lastInsertId();
                foreach (($question['options'] ?? []) as $oIndex => $option) {
                    q_run(
                        "INSERT INTO quiz_options (question_id, option_text, is_correct, order_index) VALUES (?,?,?,?)",
                        [$questionId, $option['text'], !empty($option['is_correct']) ? 1 : 0, $oIndex + 1]
                    );
                }
            }
            $pdo->commit();
            $existing = ['id' => $quizId];
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
    json_response(load_quiz_with_questions($existing['id'], false));
}

function h_submit_quiz($quizId, array $body, array $user): void
{
    $answers = $body['answers'] ?? null;
    if (!is_array($answers) || !count($answers)) {
        throw new ApiException(400, 'يجب إرسال إجابات الأسئلة');
    }

    $data = load_quiz_with_questions($quizId, true);
    if (!$data) {
        throw new ApiException(404, 'الاختبار غير موجود');
    }
    $questions = $data['questions'];
    $qMap = [];
    foreach ($questions as $q) {
        $qMap[$q['id']] = $q;
    }

    $correctCount = 0;
    $score = 0;
    $results = [];
    foreach ($answers as $answer) {
        $qid = (int) ($answer['questionId'] ?? 0);
        if (!isset($qMap[$qid])) {
            continue;
        }
        $question = $qMap[$qid];
        $correctOption = null;
        foreach ($question['options'] as $opt) {
            if ($opt['is_correct']) {
                $correctOption = $opt;
                break;
            }
        }
        $selectedId = isset($answer['selectedOptionId']) ? (int) $answer['selectedOptionId'] : null;
        $isCorrect = $correctOption && $selectedId === (int) $correctOption['id'];
        if ($isCorrect) {
            $correctCount++;
            $score += (int) $question['points_value'];
        }
        $results[] = [
            'questionId' => (int) $question['id'],
            'selectedOptionId' => $selectedId,
            'correctOptionId' => $correctOption ? (int) $correctOption['id'] : null,
            'isCorrect' => (bool) $isCorrect,
            'explanation' => $question['explanation'],
        ];
    }

    $pdo = db();
    $startedAt = !empty($body['startedAt']) ? date('Y-m-d H:i:s', strtotime($body['startedAt'])) : date('Y-m-d H:i:s');
    $duration = !empty($body['startedAt']) ? max(0, time() - strtotime($body['startedAt'])) : null;

    $pdo->beginTransaction();
    try {
        q_run(
            "INSERT INTO quiz_attempts (user_id, quiz_id, score, total_questions, correct_count, started_at, submitted_at, duration_seconds)
             VALUES (?,?,?,?,?,?,CURRENT_TIMESTAMP,?)",
            [$user['id'], $quizId, $score, count($questions), $correctCount, $startedAt, $duration]
        );
        $attemptId = (int) $pdo->lastInsertId();
        foreach ($results as $r) {
            q_run(
                "INSERT INTO quiz_attempt_answers (attempt_id, question_id, selected_option_id, is_correct) VALUES (?,?,?,?)",
                [$attemptId, $r['questionId'], $r['selectedOptionId'], $r['isCorrect'] ? 1 : 0]
            );
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $earned = $correctCount * POINTS_QUIZ_CORRECT;
    if ($earned > 0) {
        award_points($user['id'], $earned, 'quiz_correct_answer', 'quiz_attempt', $attemptId);
    }
    $bonus = 0;
    if ($correctCount === count($questions)) {
        $bonus = POINTS_QUIZ_PERFECT_BONUS;
        award_points($user['id'], $bonus, 'quiz_perfect_bonus', 'quiz_attempt', $attemptId);
    }

    json_response([
        'attemptId' => $attemptId,
        'totalQuestions' => count($questions),
        'correctCount' => $correctCount,
        'score' => $score,
        'pointsEarned' => $earned + $bonus,
        'perfectBonus' => $bonus,
        'results' => $results,
    ]);
}

function h_points_me(array $user): void
{
    $row = q_one("SELECT points_total FROM users WHERE id=?", [$user['id']]);
    $history = q_all(
        "SELECT id, points, reason, reference_type, reference_id, created_at FROM points_transactions WHERE user_id=? ORDER BY created_at DESC LIMIT 50",
        [$user['id']]
    );
    $badges = q_all(
        "SELECT b.code, b.name_ar, b.description_ar, b.icon, ub.earned_at
         FROM user_badges ub JOIN badges b ON b.id=ub.badge_id WHERE ub.user_id=? ORDER BY ub.earned_at DESC",
        [$user['id']]
    );
    json_response(['pointsTotal' => $row ? (int) $row['points_total'] : 0, 'history' => $history, 'badges' => $badges]);
}

// لوحة المتصدرين: يُحسب الترتيب في PHP بعد الجلب (بدل window functions في SQL)
// لضمان التوافق مع كل إصدارات SQLite المجمّعة مع PHP على مختلف الاستضافات
function h_leaderboard(?array $user): void
{
    $scope = ($_GET['scope'] ?? '') === 'country' ? 'country' : 'global';
    $limit = min((int) ($_GET['limit'] ?? 50) ?: 50, 100);
    $countryId = !empty($_GET['countryId']) ? (int) $_GET['countryId'] : null;

    if ($scope === 'country' && !$countryId && $user) {
        $u = q_one("SELECT country_id FROM users WHERE id=?", [$user['id']]);
        $countryId = $u ? $u['country_id'] : null;
    }

    $all = q_all(
        "SELECT u.id as user_id, u.name, u.avatar_url, u.points_total, u.country_id,
                c.name_ar as country_name_ar, c.flag_emoji as country_flag
         FROM users u LEFT JOIN countries c ON c.id=u.country_id
         WHERE u.role='student' AND u.is_active=1
         ORDER BY u.points_total DESC"
    );

    $global = $all;
    foreach ($global as $i => &$r) {
        $r['rank'] = $i + 1;
        $r['points_total'] = (int) $r['points_total'];
    }
    unset($r);

    $countryRows = $countryId ? array_values(array_filter($global, fn($r) => (int) $r['country_id'] === $countryId)) : [];
    foreach ($countryRows as $i => &$r) {
        $r['rank'] = $i + 1;
    }
    unset($r);

    $rows = $scope === 'country' ? array_slice($countryRows, 0, $limit) : array_slice($global, 0, $limit);

    $me = null;
    if ($user) {
        foreach ($global as $i => $r) {
            if ((int) $r['user_id'] === (int) $user['id']) {
                $me = $r;
                $me['rank_global'] = $i + 1;
                foreach ($countryRows as $ci => $cr) {
                    if ((int) $cr['user_id'] === (int) $user['id']) {
                        $me['rank_in_country'] = $ci + 1;
                        break;
                    }
                }
                break;
            }
        }
    }

    json_response(['scope' => $scope, 'countryId' => $countryId, 'leaderboard' => $rows, 'me' => $me]);
}

function get_or_create_chat_session(int $userId, $lectureId): array
{
    $existing = q_one(
        "SELECT * FROM ai_chat_sessions WHERE user_id=? AND lecture_id=? ORDER BY created_at DESC LIMIT 1",
        [$userId, $lectureId]
    );
    if ($existing) {
        return $existing;
    }
    q_run("INSERT INTO ai_chat_sessions (user_id, lecture_id) VALUES (?,?)", [$userId, $lectureId]);
    return ['id' => (int) db()->lastInsertId(), 'user_id' => $userId, 'lecture_id' => $lectureId];
}

function h_tutor_history($lectureId, array $user): void
{
    $session = q_one(
        "SELECT id FROM ai_chat_sessions WHERE user_id=? AND lecture_id=? ORDER BY created_at DESC LIMIT 1",
        [$user['id'], $lectureId]
    );
    if (!$session) {
        json_response(['sessionId' => null, 'messages' => []]);
    }
    $messages = q_all(
        "SELECT role, message_text, created_at FROM ai_chat_messages WHERE session_id=? ORDER BY created_at ASC",
        [$session['id']]
    );
    json_response(['sessionId' => (int) $session['id'], 'messages' => $messages]);
}

function h_tutor_chat(array $body, array $user): void
{
    $lectureId = $body['lectureId'] ?? null;
    $message = trim($body['message'] ?? '');
    if (!$lectureId || !$message) {
        throw new ApiException(400, 'يجب تحديد المحاضرة ونص السؤال');
    }
    $lecture = q_one("SELECT * FROM lectures WHERE id=?", [$lectureId]);
    if (!$lecture) {
        throw new ApiException(404, 'المحاضرة غير موجودة');
    }

    $session = get_or_create_chat_session($user['id'], $lectureId);
    $history = q_all(
        "SELECT role, message_text FROM ai_chat_messages WHERE session_id=? ORDER BY created_at ASC LIMIT 20",
        [$session['id']]
    );
    q_run("INSERT INTO ai_chat_messages (session_id, role, message_text) VALUES (?, 'user', ?)", [$session['id'], $message]);

    $result = deepseek_tutor_reply($lecture['title_ar'], $lecture['description'], $lecture['transcript_text'], $history, $message);

    q_run(
        "INSERT INTO ai_chat_messages (session_id, role, message_text, tokens_used) VALUES (?, 'assistant', ?, ?)",
        [$session['id'], $result['reply'], $result['usage']['total_tokens'] ?? null]
    );

    json_response(['sessionId' => (int) $session['id'], 'reply' => $result['reply']]);
}

function h_settings_get(): void
{
    $c = get_deepseek_config();
    json_response([
        'provider' => 'deepseek',
        'apiBaseUrl' => $c['baseUrl'],
        'model' => $c['model'],
        'hasApiKey' => (bool) $c['apiKey'],
        'maskedApiKey' => $c['apiKey'] ? mask_key($c['apiKey']) : null,
        'source' => $c['source'],
    ]);
}

function h_settings_save(array $body, array $user): void
{
    $apiKey = trim($body['apiKey'] ?? '');
    if (strlen($apiKey) < 10) {
        throw new ApiException(400, 'مفتاح API غير صالح');
    }
    $encrypted = crypto_encrypt($apiKey);
    $baseUrl = $body['baseUrl'] ?: 'https://api.deepseek.com';
    $model = $body['model'] ?: 'deepseek-chat';
    q_run(
        "INSERT INTO api_settings (provider, api_key_encrypted, api_base_url, model_name, is_active, updated_by)
         VALUES ('deepseek', ?, ?, ?, 1, ?)
         ON CONFLICT(provider) DO UPDATE SET
           api_key_encrypted=excluded.api_key_encrypted, api_base_url=excluded.api_base_url,
           model_name=excluded.model_name, updated_by=excluded.updated_by",
        [$encrypted, $baseUrl, $model, $user['id']]
    );
    $c = get_deepseek_config();
    json_response([
        'message' => 'تم حفظ إعدادات DeepSeek بنجاح',
        'provider' => 'deepseek',
        'apiBaseUrl' => $c['baseUrl'],
        'model' => $c['model'],
        'hasApiKey' => (bool) $c['apiKey'],
        'maskedApiKey' => mask_key($c['apiKey']),
        'source' => $c['source'],
    ]);
}

function h_settings_test(): void
{
    $result = deepseek_chat([
        ['role' => 'system', 'content' => 'أجب بكلمة واحدة فقط: "متصل".'],
        ['role' => 'user', 'content' => 'اختبار اتصال'],
    ], 0.6, false, 10);
    json_response(['ok' => true, 'sample' => $result['content']]);
}

function h_youtube_settings_get(): void
{
    $key = get_youtube_api_key();
    json_response([
        'provider' => 'youtube',
        'hasApiKey' => (bool) $key,
        'maskedApiKey' => $key ? mask_key($key) : null,
    ]);
}

function h_youtube_settings_save(array $body, array $user): void
{
    $apiKey = trim($body['apiKey'] ?? '');
    if (strlen($apiKey) < 10) {
        throw new ApiException(400, 'مفتاح API غير صالح');
    }
    $encrypted = crypto_encrypt($apiKey);
    q_run(
        "INSERT INTO api_settings (provider, api_key_encrypted, is_active, updated_by) VALUES ('youtube', ?, 1, ?)
         ON CONFLICT(provider) DO UPDATE SET api_key_encrypted=excluded.api_key_encrypted, updated_by=excluded.updated_by",
        [$encrypted, $user['id']]
    );
    $key = get_youtube_api_key();
    json_response([
        'message' => 'تم حفظ إعدادات YouTube بنجاح',
        'provider' => 'youtube',
        'hasApiKey' => (bool) $key,
        'maskedApiKey' => mask_key($key),
    ]);
}

function h_youtube_settings_test(): void
{
    json_response(youtube_test_connection());
}

// ============================================================================
// أدوات JSON عامة + التوجيه (Router)
// ============================================================================

function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function api_error(string $message, int $status = 500): void
{
    json_response(['error' => $message], $status);
}

function dispatch_api(): void
{
    header('Content-Type: application/json; charset=utf-8');
    $route = '/' . trim($_GET['api'] ?? '', '/');
    $method = $_SERVER['REQUEST_METHOD'];
    if ($method === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
    $body = [];
    if (in_array($method, ['POST', 'PUT'], true)) {
        $decoded = json_decode(file_get_contents('php://input'), true);
        $body = is_array($decoded) ? $decoded : [];
    }

    try {
        if ($method === 'GET' && $route === '/health') {
            json_response(['ok' => true, 'service' => 'zaki-standalone']);
        } elseif ($method === 'POST' && $route === '/auth/register') {
            h_register($body);
        } elseif ($method === 'POST' && $route === '/auth/login') {
            h_login($body);
        } elseif ($method === 'GET' && $route === '/auth/me') {
            h_me(require_auth());
        } elseif ($method === 'PUT' && $route === '/me/selection') {
            h_update_selection($body, require_auth());
        } elseif ($method === 'GET' && $route === '/countries') {
            h_countries();
        } elseif ($method === 'GET' && preg_match('#^/countries/([^/]+)/stages$#', $route, $m)) {
            h_country_stages($m[1]);
        } elseif ($method === 'GET' && preg_match('#^/countries/([^/]+)/governorates$#', $route, $m)) {
            h_country_governorates($m[1]);
        } elseif ($method === 'GET' && preg_match('#^/subjects/([^/]+)/teachers$#', $route, $m)) {
            h_subject_teachers($m[1]);
        } elseif ($method === 'GET' && preg_match('#^/subjects/([^/]+)$#', $route, $m)) {
            h_subject_detail($m[1]);
        } elseif ($method === 'GET' && preg_match('#^/teachers/([^/]+)/units$#', $route, $m)) {
            h_teacher_units($m[1]);
        } elseif ($method === 'GET' && preg_match('#^/teachers/([^/]+)$#', $route, $m)) {
            h_teacher_detail($m[1]);
        } elseif ($method === 'GET' && preg_match('#^/units/([^/]+)/lectures$#', $route, $m)) {
            h_unit_lectures($m[1]);
        } elseif ($method === 'GET' && preg_match('#^/units/([^/]+)$#', $route, $m)) {
            h_unit_detail($m[1]);
        } elseif ($method === 'GET' && preg_match('#^/stages/([^/]+)/subjects$#', $route, $m)) {
            h_stage_subjects($m[1]);
        } elseif ($method === 'POST' && preg_match('#^/stages/([^/]+)/generate$#', $route, $m)) {
            $user = require_auth();
            require_admin($user);
            h_generate_curriculum($m[1]);
        } elseif ($method === 'GET' && preg_match('#^/lectures/([^/]+)/quiz$#', $route, $m)) {
            h_get_quiz($m[1], require_auth());
        } elseif ($method === 'GET' && preg_match('#^/lectures/([^/]+)$#', $route, $m)) {
            h_lecture_detail($m[1], optional_auth());
        } elseif ($method === 'POST' && preg_match('#^/lectures/([^/]+)/progress$#', $route, $m)) {
            h_lecture_progress($m[1], $body, require_auth());
        } elseif ($method === 'POST' && preg_match('#^/quizzes/([^/]+)/submit$#', $route, $m)) {
            h_submit_quiz($m[1], $body, require_auth());
        } elseif ($method === 'GET' && $route === '/points/me') {
            h_points_me(require_auth());
        } elseif ($method === 'GET' && $route === '/leaderboard') {
            h_leaderboard(optional_auth());
        } elseif ($method === 'GET' && preg_match('#^/tutor/sessions/([^/]+)$#', $route, $m)) {
            h_tutor_history($m[1], require_auth());
        } elseif ($method === 'POST' && $route === '/tutor/chat') {
            h_tutor_chat($body, require_auth());
        } elseif ($method === 'GET' && $route === '/settings/deepseek') {
            $user = require_auth();
            require_admin($user);
            h_settings_get();
        } elseif ($method === 'PUT' && $route === '/settings/deepseek') {
            $user = require_auth();
            require_admin($user);
            h_settings_save($body, $user);
        } elseif ($method === 'POST' && $route === '/settings/deepseek/test') {
            $user = require_auth();
            require_admin($user);
            h_settings_test();
        } elseif ($method === 'GET' && $route === '/settings/youtube') {
            $user = require_auth();
            require_admin($user);
            h_youtube_settings_get();
        } elseif ($method === 'PUT' && $route === '/settings/youtube') {
            $user = require_auth();
            require_admin($user);
            h_youtube_settings_save($body, $user);
        } elseif ($method === 'POST' && $route === '/settings/youtube/test') {
            $user = require_auth();
            require_admin($user);
            h_youtube_settings_test();
        } else {
            api_error('المسار المطلوب غير موجود', 404);
        }
    } catch (ApiException $e) {
        api_error($e->getMessage(), $e->statusCode);
    } catch (Throwable $e) {
        api_error('حدث خطأ غير متوقع: ' . $e->getMessage(), 500);
    }
}

// ============================================================================
// نقطة الدخول
// ============================================================================

db(); // يضمن إنشاء قاعدة البيانات وتثبيتها من أول زيارة، سواء كانت API أو صفحة

if (isset($_GET['api'])) {
    dispatch_api();
    exit;
}

header('Content-Type: text/html; charset=utf-8');
echo <<<'ZAKI_STANDALONE_HTML'
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, viewport-fit=cover">
<title>ذَكِيّ — منصة التعلّم الذكية</title>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<style>
/* ============================================================
   نظام التصميم (Design Tokens)
   ============================================================ */
:root{
  --bg:#f8f6f3;
  --card:#ffffff;
  --text:#1a1a2e;
  --muted:#6b7280;
  --blue:#00e6bb;
  --cyan:#00c4a0;
  --gold:#f5b300;
  --danger:#ef4444;
  --success:#22c55e;
  --shadow:0 1px 2px rgba(0,230,187,0.08),0 6px 18px rgba(0,230,187,0.06);
  --shadow-hover:0 12px 32px rgba(0,230,187,0.14);
  --border:rgba(0,230,187,0.12);
  --hover-bg:rgba(0,230,187,0.06);
  --gradient-primary:linear-gradient(135deg,#00e6bb,#00c4a0);
  --gradient-gold:linear-gradient(135deg,#ffd54a,#f5b300);
  --font-ar:'IBM Plex Sans Arabic', system-ui, sans-serif;
  --radius-sm:10px;
  --radius-md:16px;
  --radius-lg:22px;
  --safe-b:env(safe-area-inset-bottom,0px);
}
[data-theme="dark"]{
  --bg:#0d0f10;
  --card:#171a1b;
  --text:#f2f3f5;
  --muted:#9aa3a0;
  --blue:#00ffcc;
  --cyan:#00e6bb;
  --gold:#ffd54a;
  --shadow:0 1px 2px rgba(0,0,0,0.5),0 6px 20px rgba(0,0,0,0.6);
  --shadow-hover:0 12px 32px rgba(0,255,204,0.18);
  --border:rgba(0,255,204,0.12);
  --hover-bg:rgba(0,255,204,0.07);
  --gradient-primary:linear-gradient(135deg,#00ffcc,#00e6bb);
}
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html{scroll-behavior:smooth}
body{font-family:var(--font-ar);background:var(--bg);color:var(--text);min-height:100vh;min-height:100dvh;overflow-x:hidden;-webkit-font-smoothing:antialiased;text-rendering:optimizeLegibility;transition:background .3s,color .3s}
::selection{background:var(--blue);color:#04231c}
a{color:inherit;text-decoration:none}
button{font-family:inherit}
input,select,textarea{font-family:inherit}
.bg-orb{position:fixed;border-radius:50%;filter:blur(80px);pointer-events:none;z-index:0}
.bo1{width:340px;height:340px;background:rgba(0,230,187,0.09);top:-120px;inset-inline-end:-90px}
.bo2{width:260px;height:260px;background:rgba(0,196,160,0.06);bottom:10%;inset-inline-start:-80px}
[data-theme="dark"] .bo1{background:rgba(0,255,204,0.12)}
[data-theme="dark"] .bo2{background:rgba(0,230,187,0.09)}
.hidden{display:none !important}
::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-thumb{background:var(--border);border-radius:10px}
@keyframes fadeUp{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
.an{animation:fadeUp .35s both}
@keyframes spin{to{transform:rotate(360deg)}}
.spinner{width:18px;height:18px;border-radius:50%;border:3px solid var(--border);border-top-color:var(--blue);animation:spin .7s linear infinite;display:inline-block}
.loading-block{display:flex;align-items:center;justify-content:center;gap:10px;padding:48px 16px;color:var(--muted);font-size:.85rem}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.25}}
.dot-live{width:6px;height:6px;border-radius:50%;background:var(--success);animation:blink 2s infinite;display:inline-block}

/* ============================================================
   الهيكل العام
   ============================================================ */
.shell{position:relative;z-index:1;width:100%;max-width:560px;margin:0 auto;padding-bottom:calc(84px + var(--safe-b));min-height:100vh;min-height:100dvh}
@media (min-width:1024px){.shell{max-width:1080px;padding-bottom:48px}}
.hdr{position:sticky;top:0;z-index:100;display:flex;align-items:center;justify-content:space-between;padding:calc(env(safe-area-inset-top,14px) + 6px) 16px 12px;background:color-mix(in srgb, var(--bg) 88%, transparent);backdrop-filter:blur(14px)}
.logo{font-size:1.25rem;font-weight:900;background:var(--gradient-primary);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.logo-dot{color:var(--gold);-webkit-text-fill-color:var(--gold)}
.hdr-right{display:flex;align-items:center;gap:8px;position:relative}
.view{position:relative;z-index:1;padding:6px 16px 24px;min-height:60vh}
.bottom-nav{position:fixed;bottom:0;inset-inline:0;z-index:100;display:flex;justify-content:space-around;align-items:center;padding:8px 6px calc(8px + var(--safe-b));background:var(--card);border-top:1px solid var(--border);box-shadow:0 -4px 20px rgba(0,0,0,.05)}
.bn-item{display:flex;flex-direction:column;align-items:center;gap:3px;background:none;border:none;color:var(--muted);cursor:pointer;font-size:.62rem;font-weight:600;padding:4px 10px;border-radius:12px;transition:color .2s,background .2s}
.bn-item i{font-size:1.05rem}
.bn-item.active{color:var(--blue);background:var(--hover-bg)}
@media (min-width:1024px){.bottom-nav{display:none !important}.view{padding:24px 8px}}
body.auth-mode .hdr,body.auth-mode .bottom-nav{display:none !important}
body.auth-mode .view{padding:0;min-height:100vh;min-height:100dvh}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.grid-cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px}
@media (min-width:1024px){.grid-cards{grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:18px}.grid-2{grid-template-columns:repeat(auto-fill,minmax(320px,1fr))}}
.section{margin-bottom:22px}
.section-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px}
.section-head h3{font-size:.95rem;font-weight:800;display:flex;align-items:center;gap:8px}
.section-head h3 i{width:26px;height:26px;display:inline-flex;align-items:center;justify-content:center;background:var(--hover-bg);border-radius:8px;font-size:.8em;color:var(--cyan)}
.page-title{font-size:1.15rem;font-weight:800;margin-bottom:4px}
.page-sub{font-size:.78rem;color:var(--muted);margin-bottom:18px}
.breadcrumb{display:flex;align-items:center;gap:6px;font-size:.72rem;color:var(--muted);margin-bottom:14px;flex-wrap:wrap}
.breadcrumb a{color:var(--cyan);font-weight:700}
.breadcrumb i{font-size:.6rem}
.center-box{display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:40px 20px;gap:10px;color:var(--muted)}
.center-box i{font-size:2.2rem;color:var(--cyan);opacity:.7}

/* ============================================================
   المكوّنات
   ============================================================ */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 20px;border-radius:100px;border:none;cursor:pointer;font-weight:700;font-size:.85rem;font-family:var(--font-ar);transition:transform .15s cubic-bezier(.4,0,.2,1),box-shadow .2s,opacity .2s}
.btn:active{transform:scale(.95)}
.btn:disabled{opacity:.55;cursor:not-allowed;transform:none}
.btn-primary{background:var(--gradient-primary);color:#04231c;box-shadow:0 6px 18px rgba(0,230,187,.28)}
.btn-outline{background:transparent;border:2px solid var(--blue);color:var(--text)}
.btn-ghost{background:var(--hover-bg);color:var(--text)}
.btn-danger{background:rgba(239,68,68,.12);color:var(--danger)}
.btn-block{width:100%}
.btn-sm{padding:7px 14px;font-size:.75rem}
.icon-btn{width:36px;height:36px;border-radius:50%;background:var(--card);box-shadow:var(--shadow);border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--text);font-size:.9rem;transition:transform .15s;flex-shrink:0}
.icon-btn:active{transform:scale(.88) rotate(-8deg)}
.icon-btn .sun{display:none}
[data-theme="dark"] .icon-btn .sun{display:inline;color:var(--gold)}
[data-theme="dark"] .icon-btn .moon{display:none}
.points-chip{display:flex;align-items:center;gap:6px;padding:7px 12px;border-radius:100px;background:var(--gradient-gold);color:#3a2900;font-weight:800;font-size:.75rem;box-shadow:var(--shadow)}
.user-menu{position:relative}
.avatar-btn{width:36px;height:36px;border-radius:50%;background:var(--gradient-primary);border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;color:#04231c;font-size:.9rem;box-shadow:var(--shadow)}
.user-dropdown{position:absolute;top:calc(100% + 8px);inset-inline-end:0;min-width:220px;background:var(--card);border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,.16);overflow:hidden;z-index:200;opacity:0;transform:translateY(-8px) scale(.96);pointer-events:none;transition:all .18s}
.user-dropdown.open{opacity:1;transform:translateY(0) scale(1);pointer-events:auto}
.ud-name{padding:14px 16px 8px;font-weight:800;font-size:.85rem;border-bottom:1px solid var(--border);margin-bottom:6px}
.ud-item{display:flex;align-items:center;gap:10px;width:100%;padding:10px 16px;background:none;border:none;font-size:.78rem;font-weight:600;color:var(--text);cursor:pointer;text-align:start}
.ud-item:hover{background:var(--hover-bg)}
.ud-item i{width:16px;color:var(--cyan)}
.ud-danger{color:var(--danger)}
.ud-danger i{color:var(--danger)}
.notif-dot{position:absolute;top:5px;inset-inline-end:5px;width:9px;height:9px;border-radius:50%;background:var(--danger);border:2px solid var(--bg)}
.notif-panel{max-height:70vh;overflow-y:auto}
.notif-empty{padding:24px 16px;text-align:center;color:var(--muted);font-size:.78rem}
.sheet-overlay{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:299;opacity:0;pointer-events:none;transition:opacity .2s}
.sheet-overlay.open{opacity:1;pointer-events:auto}
.sheet{position:fixed;bottom:0;inset-inline:0;z-index:300;max-width:480px;margin:0 auto;background:var(--card);border-radius:22px 22px 0 0;box-shadow:0 -10px 40px rgba(0,0,0,.25);padding:14px 20px calc(20px + var(--safe-b));transform:translateY(100%);transition:transform .25s ease}
.sheet.open{transform:translateY(0)}
.sheet-handle{width:40px;height:4px;border-radius:2px;background:var(--border);margin:0 auto 16px}
.card{background:var(--card);border-radius:var(--radius-lg);box-shadow:var(--shadow);padding:16px}
.profile-card{border-radius:var(--radius-lg);padding:22px 18px;background:var(--gradient-primary);color:#04231c;position:relative;overflow:hidden;box-shadow:0 10px 30px rgba(0,230,187,.25);margin-bottom:22px}
[data-theme="dark"] .profile-card{color:#04231c}
.profile-card::after{content:'';position:absolute;bottom:-40px;inset-inline-start:-40px;width:140px;height:140px;border-radius:50%;background:rgba(255,255,255,.12)}
.profile-row{display:flex;align-items:center;gap:14px;position:relative;z-index:1}
.profile-av{width:56px;height:56px;border-radius:18px;background:rgba(255,255,255,.28);display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0}
.profile-info h2{font-size:1.05rem;font-weight:800}
.profile-info p{font-size:.72rem;opacity:.85;margin-top:2px}
.profile-stats{display:flex;gap:18px;margin-top:16px;position:relative;z-index:1}
.profile-stat b{display:block;font-size:1.15rem;font-weight:900}
.profile-stat span{font-size:.65rem;opacity:.85}
.subject-card{background:var(--card);border-radius:var(--radius-md);padding:16px;box-shadow:var(--shadow);cursor:pointer;transition:transform .15s cubic-bezier(.4,0,.2,1),box-shadow .2s;text-align:center}
.subject-card:hover{transform:translateY(-3px);box-shadow:var(--shadow-hover)}
.subject-card:active{transform:scale(.97)}
.subject-ico{width:48px;height:48px;border-radius:14px;background:var(--gradient-primary);color:#04231c;display:flex;align-items:center;justify-content:center;font-size:1.2rem;margin:0 auto 10px}
.subject-ico.round{border-radius:50%}
.subject-card h4{font-size:.82rem;font-weight:700}
.subject-card p{font-size:.65rem;color:var(--muted);margin-top:2px}
.list-row{display:flex;align-items:center;gap:12px;background:var(--card);border-radius:16px;padding:13px;margin-bottom:9px;box-shadow:var(--shadow);cursor:pointer;transition:transform .15s,box-shadow .2s}
.list-row:hover{box-shadow:var(--shadow-hover)}
.list-row:active{transform:scale(.98)}
.list-ico{width:42px;height:42px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:.95rem;color:#04231c;background:var(--gradient-primary);flex-shrink:0}
.list-ico.done{background:var(--gradient-gold)}
.list-body{flex:1;min-width:0}
.list-body h4{font-size:.82rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.list-body p{font-size:.68rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:2px}
.list-meta{font-size:.62rem;color:var(--muted);flex-shrink:0;display:flex;align-items:center;gap:4px}
.chip{font-size:.58rem;font-weight:700;padding:3px 9px;border-radius:8px;background:var(--hover-bg);color:var(--cyan);flex-shrink:0}
.chip-done{background:rgba(34,197,94,.14);color:var(--success)}
.video-wrap{position:relative;width:100%;padding-top:56.25%;border-radius:var(--radius-lg);overflow:hidden;background:#000;box-shadow:var(--shadow);margin-bottom:16px}
.video-wrap iframe{position:absolute;inset:0;width:100%;height:100%;border:0}
.video-fallback{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px;color:#fff;text-align:center;padding:20px;background:linear-gradient(135deg,#111,#000)}
.video-fallback i{font-size:2rem;color:var(--blue)}
.progress-track{height:6px;border-radius:100px;background:var(--hover-bg);overflow:hidden;margin:14px 0}
.progress-fill{height:100%;background:var(--gradient-primary);border-radius:100px;transition:width .4s}
.tutor-fab{position:fixed;bottom:calc(94px + var(--safe-b));inset-inline-end:18px;z-index:150;width:54px;height:54px;border-radius:50%;background:var(--gradient-primary);color:#04231c;border:none;box-shadow:0 8px 24px rgba(0,230,187,.4);font-size:1.3rem;cursor:pointer;display:flex;align-items:center;justify-content:center}
@media (min-width:1024px){.tutor-fab{bottom:24px}}
.tutor-panel{position:fixed;bottom:0;inset-inline:0;z-index:200;max-width:480px;margin:0 auto;background:var(--card);border-radius:22px 22px 0 0;box-shadow:0 -10px 40px rgba(0,0,0,.25);display:flex;flex-direction:column;height:min(72vh,600px);transform:translateY(100%);transition:transform .25s ease}
.tutor-panel.open{transform:translateY(0)}
@media (min-width:640px){.tutor-panel{inset-inline:auto;inset-inline-end:18px;bottom:24px;width:380px;border-radius:22px}}
.tutor-head{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid var(--border)}
.tutor-head h4{font-size:.88rem;font-weight:800;display:flex;align-items:center;gap:8px}
.tutor-head h4 i{color:var(--cyan)}
.tutor-body{flex:1;overflow-y:auto;padding:14px 16px;display:flex;flex-direction:column;gap:10px}
.tutor-msg{max-width:85%;padding:10px 13px;border-radius:14px;font-size:.78rem;line-height:1.7}
.tutor-msg.user{align-self:flex-end;background:var(--gradient-primary);color:#04231c;border-bottom-left-radius:4px;border-bottom-right-radius:14px}
.tutor-msg.assistant{align-self:flex-start;background:var(--hover-bg);border-bottom-right-radius:4px}
.tutor-input{display:flex;gap:8px;padding:12px 14px calc(12px + var(--safe-b));border-top:1px solid var(--border)}
.tutor-input input{flex:1;border:1px solid var(--border);background:var(--bg);border-radius:100px;padding:10px 16px;font-size:.8rem;color:var(--text);outline:none}
.tutor-input input:focus{border-color:var(--blue)}
.tutor-send{width:40px;height:40px;border-radius:50%;background:var(--gradient-primary);color:#04231c;border:none;cursor:pointer;flex-shrink:0}
.quiz-progress{font-size:.72rem;color:var(--muted);font-weight:700;margin-bottom:8px}
.question-card{background:var(--card);border-radius:var(--radius-lg);box-shadow:var(--shadow);padding:20px;margin-bottom:16px}
.question-text{font-size:.95rem;font-weight:700;margin-bottom:16px;line-height:1.8}
.option-row{display:flex;align-items:center;gap:10px;padding:12px 14px;border-radius:14px;border:2px solid var(--border);margin-bottom:9px;cursor:pointer;transition:border-color .15s,background .15s;font-size:.82rem}
.option-row:hover{background:var(--hover-bg)}
.option-row.selected{border-color:var(--blue);background:var(--hover-bg)}
.option-row.correct{border-color:var(--success);background:rgba(34,197,94,.1)}
.option-row.incorrect{border-color:var(--danger);background:rgba(239,68,68,.08)}
.option-mark{width:22px;height:22px;border-radius:50%;border:2px solid var(--border);flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:.65rem}
.option-row.selected .option-mark{border-color:var(--blue);background:var(--blue);color:#04231c}
.option-row.correct .option-mark{border-color:var(--success);background:var(--success);color:#fff}
.option-row.incorrect .option-mark{border-color:var(--danger);background:var(--danger);color:#fff}
.option-explain{font-size:.7rem;color:var(--muted);margin-top:8px;padding-top:8px;border-top:1px dashed var(--border);line-height:1.7}
.result-hero{text-align:center;padding:32px 20px}
.result-ring{width:120px;height:120px;border-radius:50%;background:var(--gradient-primary);display:flex;align-items:center;justify-content:center;margin:0 auto 16px;box-shadow:0 10px 30px rgba(0,230,187,.3)}
.result-ring b{font-size:1.8rem;color:#04231c}
.result-points{display:inline-flex;align-items:center;gap:6px;background:var(--gradient-gold);color:#3a2900;font-weight:800;padding:8px 18px;border-radius:100px;font-size:.85rem;margin-top:10px}
.lb-tabs{display:flex;gap:8px;margin-bottom:16px}
.lb-tab{flex:1;padding:10px;border-radius:100px;border:2px solid var(--border);background:transparent;color:var(--muted);font-weight:700;font-size:.78rem;cursor:pointer}
.lb-tab.active{background:var(--gradient-primary);color:#04231c;border-color:transparent}
.lb-row{display:flex;align-items:center;gap:12px;background:var(--card);border-radius:16px;padding:12px 14px;margin-bottom:8px;box-shadow:var(--shadow)}
.lb-row.me{border:2px solid var(--blue)}
.lb-rank{width:30px;text-align:center;font-weight:900;font-size:.9rem;color:var(--muted);flex-shrink:0}
.lb-rank.top1{color:#f5b300}.lb-rank.top2{color:#9aa3a0}.lb-rank.top3{color:#c47a3d}
.lb-av{width:38px;height:38px;border-radius:50%;background:var(--gradient-primary);display:flex;align-items:center;justify-content:center;color:#04231c;font-weight:800;font-size:.78rem;flex-shrink:0}
.lb-info{flex:1;min-width:0}
.lb-info h4{font-size:.8rem;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.lb-info p{font-size:.63rem;color:var(--muted)}
.lb-points{font-weight:800;color:var(--cyan);font-size:.85rem;flex-shrink:0}
.form-group{margin-bottom:14px}
.form-group label{display:block;font-size:.75rem;font-weight:700;margin-bottom:6px;color:var(--muted)}
.form-control{width:100%;padding:12px 14px;border-radius:12px;border:1.5px solid var(--border);background:var(--card);color:var(--text);font-size:.85rem;outline:none;transition:border-color .15s}
.form-control:focus{border-color:var(--blue)}
.form-hint{font-size:.68rem;color:var(--muted);margin-top:5px;line-height:1.6}
.form-error{font-size:.72rem;color:var(--danger);margin-top:8px;display:none}
.form-error.show{display:block}
.select-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.auth-card{max-width:400px;width:100%;margin:0 auto;padding:24px 20px}
body.auth-mode .view:has(> .auth-card){display:flex;align-items:center;justify-content:center;padding:20px 16px}
.auth-switch{text-align:center;font-size:.78rem;color:var(--muted);margin-top:14px}
.auth-switch a{color:var(--blue);font-weight:700}
.key-status{display:flex;align-items:center;gap:8px;padding:10px 14px;border-radius:12px;background:var(--hover-bg);font-size:.75rem;font-weight:700;margin-bottom:16px}
.key-status.ok{color:var(--success)}
.key-status.missing{color:var(--danger)}
.toast{position:fixed;top:16px;left:50%;transform:translateX(-50%) translateY(-20px);z-index:400;background:var(--text);color:var(--bg);padding:12px 20px;border-radius:100px;font-size:.78rem;font-weight:700;box-shadow:0 8px 24px rgba(0,0,0,.25);opacity:0;transition:opacity .25s,transform .25s;pointer-events:none;max-width:90%;text-align:center}
.toast.show{opacity:1;transform:translateX(-50%) translateY(0)}
.toast.err{background:var(--danger);color:#fff}
.toast.ok{background:var(--success);color:#fff}

/* ============================================================
   شاشة الترحيب (قبل تسجيل الدخول)
   ============================================================ */
.welcome-screen{min-height:100vh;min-height:100dvh;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:40px 28px;background:radial-gradient(circle at 30% 15%, rgba(0,230,187,0.28), transparent 55%),linear-gradient(180deg,#04231c,#081210 75%);color:#fff}
.welcome-hero{position:relative;width:140px;height:140px;margin-bottom:14px;display:flex;align-items:center;justify-content:center}
.welcome-badge{width:104px;height:104px;border-radius:32px;background:var(--gradient-primary);display:flex;align-items:center;justify-content:center;font-size:2.6rem;color:#04231c;box-shadow:0 24px 60px rgba(0,230,187,.4)}
.welcome-orbit i{position:absolute;width:36px;height:36px;border-radius:11px;background:rgba(255,255,255,.1);backdrop-filter:blur(6px);display:flex;align-items:center;justify-content:center;font-size:.95rem;color:#7cffe0;box-shadow:0 8px 20px rgba(0,0,0,.25)}
.welcome-orbit i:nth-child(1){top:-8px;right:-14px}
.welcome-orbit i:nth-child(2){bottom:2px;left:-20px}
.welcome-orbit i:nth-child(3){bottom:-14px;right:22px}
.welcome-logo{font-size:1.05rem;font-weight:900;letter-spacing:.5px;color:#7cffe0;margin-top:6px}
.welcome-title{font-size:1.55rem;font-weight:900;margin-top:12px;line-height:1.4}
.welcome-sub{font-size:.85rem;color:rgba(255,255,255,.68);margin-top:10px;line-height:1.9;max-width:340px}
.welcome-features{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin:30px 0 6px;width:100%;max-width:420px}
.welcome-feature{display:flex;flex-direction:column;align-items:center;gap:6px}
.wf-ico{width:46px;height:46px;border-radius:14px;background:rgba(255,255,255,.08);display:flex;align-items:center;justify-content:center;color:#7cffe0;font-size:1.05rem}
.welcome-feature h5{font-size:.64rem;font-weight:700}
.welcome-feature p{font-size:.56rem;color:rgba(255,255,255,.5)}
.welcome-cta{max-width:420px;width:100%;margin-top:26px;padding:14px 20px;font-size:.9rem}
.welcome-skip{background:none;border:none;color:rgba(255,255,255,.55);font-size:.78rem;font-weight:700;margin-top:16px;cursor:pointer;padding:10px}
</style>
</head>
<body>

<div class="bg-orb bo1"></div>
<div class="bg-orb bo2"></div>

<div id="toast" class="toast" hidden></div>

<div class="shell">

  <header class="hdr">
    <a href="#/home" class="logo">ذَكِيّ<span class="logo-dot">.</span></a>
    <div class="hdr-right" id="hdrRight" hidden>
      <div class="user-menu">
        <button class="icon-btn" id="notifBtn" title="الإشعارات" style="position:relative">
          <i class="fas fa-bell"></i>
          <span class="notif-dot" id="notifDot" hidden></span>
        </button>
        <div class="user-dropdown notif-panel" id="notifPanel">
          <div class="ud-name">الإشعارات</div>
          <div id="notifList"><div class="notif-empty">لا توجد إشعارات بعد</div></div>
        </div>
      </div>
    </div>
  </header>

  <main id="view" class="view"></main>

  <nav class="bottom-nav" id="bottomNav" hidden>
    <button class="bn-item" data-nav="#/home"><i class="fas fa-house"></i><span>الرئيسية</span></button>
    <button class="bn-item" data-nav="#/leaderboard"><i class="fas fa-ranking-star"></i><span>المتصدرون</span></button>
    <button class="bn-item" data-nav="#/account"><i class="fas fa-user"></i><span>حسابي</span></button>
  </nav>

</div>

<div class="sheet-overlay" id="sheetOverlay"></div>
<div class="sheet" id="logoutSheet">
  <div class="sheet-handle"></div>
  <div style="text-align:center;font-weight:800;font-size:.95rem;margin-bottom:6px">تسجيل الخروج</div>
  <div style="text-align:center;color:var(--muted);font-size:.8rem;margin-bottom:18px">هل تريد تسجيل الخروج من حسابك؟</div>
  <button class="btn btn-outline btn-block" id="confirmLogoutBtn" style="border-color:var(--danger);color:var(--danger);margin-bottom:10px"><i class="fas fa-arrow-right-from-bracket"></i> تسجيل الخروج</button>
  <button class="btn btn-block" id="cancelLogoutBtn" style="background:var(--hover-bg);color:var(--text)">إلغاء</button>
</div>

<script>
window.App = window.App || {};

App.config = {
  POINTS_LABELS: {
    lecture_complete: 'إكمال محاضرة',
    quiz_correct_answer: 'إجابة صحيحة',
    quiz_perfect_bonus: 'مكافأة علامة كاملة',
    daily_streak: 'مواظبة يومية',
    manual_adjustment: 'تعديل يدوي',
  },
};

App.api = (function () {
  async function request(path, { method = 'GET', body, auth = true } = {}) {
    const headers = { 'Content-Type': 'application/json' };
    if (auth) {
      const token = App.state.getToken();
      if (token) headers.Authorization = `Bearer ${token}`;
    }

    const [routePart, queryPart] = path.split('?');
    const url = new URL('index.php', window.location.href);
    url.searchParams.set('api', routePart.replace(/^\//, ''));
    if (queryPart) {
      new URLSearchParams(queryPart).forEach((value, key) => url.searchParams.append(key, value));
    }

    let response;
    try {
      response = await fetch(url.toString(), {
        method,
        headers,
        body: body ? JSON.stringify(body) : undefined,
      });
    } catch (err) {
      throw new Error('تعذّر الاتصال بالخادم.');
    }

    let data = null;
    try {
      data = await response.json();
    } catch (err) {
      // استجابة بلا محتوى JSON
    }

    if (!response.ok) {
      const message = (data && data.error) || `خطأ (${response.status})`;
      const err = new Error(message);
      err.status = response.status;
      throw err;
    }

    return data;
  }

  return {
    get: (path) => request(path, { method: 'GET' }),
    post: (path, body) => request(path, { method: 'POST', body }),
    put: (path, body) => request(path, { method: 'PUT', body }),

    register: (payload) => request('/auth/register', { method: 'POST', body: payload, auth: false }),
    login: (payload) => request('/auth/login', { method: 'POST', body: payload, auth: false }),
    me: () => request('/auth/me'),
    updateSelection: (payload) => request('/me/selection', { method: 'PUT', body: payload }),

    getCountries: () => request('/countries', { auth: false }),
    getStages: (countryId) => request(`/countries/${countryId}/stages`, { auth: false }),
    getGovernorates: (countryId) => request(`/countries/${countryId}/governorates`, { auth: false }),
    getSubjects: (stageId) => request(`/stages/${stageId}/subjects`, { auth: false }),
    getSubject: (subjectId) => request(`/subjects/${subjectId}`, { auth: false }),
    getTeachers: (subjectId) => request(`/subjects/${subjectId}/teachers`, { auth: false }),
    getTeacher: (teacherId) => request(`/teachers/${teacherId}`, { auth: false }),
    getTeacherUnits: (teacherId) => request(`/teachers/${teacherId}/units`, { auth: false }),
    getUnit: (unitId) => request(`/units/${unitId}`, { auth: false }),
    getLectures: (unitId) => request(`/units/${unitId}/lectures`, { auth: false }),
    generateCurriculum: (stageId) => request(`/stages/${stageId}/generate`, { method: 'POST' }),

    getLecture: (id) => request(`/lectures/${id}`),
    updateProgress: (id, payload) => request(`/lectures/${id}/progress`, { method: 'POST', body: payload }),

    getQuiz: (lectureId) => request(`/lectures/${lectureId}/quiz`),
    submitQuiz: (quizId, payload) => request(`/quizzes/${quizId}/submit`, { method: 'POST', body: payload }),

    getTutorHistory: (lectureId) => request(`/tutor/sessions/${lectureId}`),
    sendTutorMessage: (lectureId, message) =>
      request('/tutor/chat', { method: 'POST', body: { lectureId, message } }),

    getMyPoints: () => request('/points/me'),
    getLeaderboard: (scope, countryId) =>
      request(`/leaderboard?scope=${scope}${countryId ? `&countryId=${countryId}` : ''}`),

    getDeepseekSettings: () => request('/settings/deepseek'),
    saveDeepseekSettings: (payload) => request('/settings/deepseek', { method: 'PUT', body: payload }),
    testDeepseekConnection: () => request('/settings/deepseek/test', { method: 'POST' }),

    getYoutubeSettings: () => request('/settings/youtube'),
    saveYoutubeSettings: (payload) => request('/settings/youtube', { method: 'PUT', body: payload }),
    testYoutubeConnection: () => request('/settings/youtube/test', { method: 'POST' }),
  };
})();

App.state = (function () {
  const KEYS = { token: 'zaki_token', user: 'zaki_user', country: 'zaki_country', governorate: 'zaki_governorate', stage: 'zaki_stage', welcome: 'zaki_seen_welcome', notifSeen: 'zaki_notif_seen_at' };
  function safeGet(key) { try { return localStorage.getItem(key); } catch (err) { return null; } }
  function safeSet(key, value) { try { localStorage.setItem(key, value); } catch (err) {} }
  function safeRemove(key) { try { localStorage.removeItem(key); } catch (err) {} }

  return {
    getToken() { return safeGet(KEYS.token); },
    getUser() { const raw = safeGet(KEYS.user); return raw ? JSON.parse(raw) : null; },
    setSession(token, user) { safeSet(KEYS.token, token); safeSet(KEYS.user, JSON.stringify(user)); },
    updateUser(patch) { const user = this.getUser(); const next = Object.assign({}, user, patch); safeSet(KEYS.user, JSON.stringify(next)); return next; },
    clearSession() { safeRemove(KEYS.token); safeRemove(KEYS.user); },
    isLoggedIn() { return Boolean(this.getToken()); },
    isAdmin() { const user = this.getUser(); return Boolean(user && user.role === 'admin'); },
    getSelection() { return { countryId: safeGet(KEYS.country), governorateId: safeGet(KEYS.governorate), stageId: safeGet(KEYS.stage) }; },
    setSelection(countryId, governorateId, stageId) {
      safeSet(KEYS.country, String(countryId));
      if (governorateId) safeSet(KEYS.governorate, String(governorateId)); else safeRemove(KEYS.governorate);
      safeSet(KEYS.stage, String(stageId));
    },
    clearSelection() { safeRemove(KEYS.country); safeRemove(KEYS.governorate); safeRemove(KEYS.stage); },
    // يزامن اختيار الدولة/المحافظة/المرحلة المخزَّن محلياً مع بيانات الحساب
    // القادمة من الخادم (بعد تسجيل الدخول مثلاً)، كي يظهر نفس الاختيار على
    // أي جهاز يسجّل منه الطالب دخوله، لا في متصفح واحد فقط.
    hydrateSelectionFromUser(user) {
      if (user && user.countryId && user.stageId) this.setSelection(user.countryId, user.governorateId, user.stageId);
    },
    hasSeenWelcome() { return safeGet(KEYS.welcome) === '1'; },
    markWelcomeSeen() { safeSet(KEYS.welcome, '1'); },
    getNotifSeenAt() { return safeGet(KEYS.notifSeen) || '1970-01-01'; },
    markNotifSeen() { safeSet(KEYS.notifSeen, new Date().toISOString()); },
  };
})();

App.theme = {
  init() {
    try { if (localStorage.getItem('zaki_theme') === 'dark') document.documentElement.setAttribute('data-theme', 'dark'); } catch (err) {}
  },
};
App.theme.init();

App.ui = (function () {
  let toastTimer = null;
  function toast(message, type = '') {
    const el = document.getElementById('toast');
    if (!el) return;
    el.textContent = message;
    el.className = 'toast show' + (type ? ` ${type}` : '');
    el.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { el.classList.remove('show'); setTimeout(() => { el.hidden = true; }, 250); }, 3000);
  }
  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }
  function loadingHtml(label = 'جاري التحميل...') { return `<div class="loading-block"><span class="spinner"></span> ${escapeHtml(label)}</div>`; }
  function emptyStateHtml(icon, title, subtitle = '') {
    return `<div class="center-box"><i class="fas ${icon}"></i><div style="font-weight:700;color:var(--text)">${escapeHtml(title)}</div>${subtitle ? `<div style="font-size:.75rem">${escapeHtml(subtitle)}</div>` : ''}</div>`;
  }
  function formatDuration(seconds) { if (!seconds) return ''; const m = Math.floor(seconds / 60); const s = seconds % 60; return `${m}:${String(s).padStart(2, '0')}`; }
  function formatDate(value) { if (!value) return ''; try { return new Date(value).toLocaleDateString('ar-EG', { year: 'numeric', month: 'short', day: 'numeric' }); } catch (err) { return ''; } }
  function initials(name) { if (!name) return '؟'; return name.trim().slice(0, 1).toUpperCase(); }
  function handleAuthError(err) {
    if (err && err.status === 401) {
      App.state.clearSession(); App.main.refreshHeader(); location.hash = '#/login'; toast('يرجى تسجيل الدخول مجدداً', 'err'); return true;
    }
    return false;
  }
  return { toast, escapeHtml, loadingHtml, emptyStateHtml, formatDuration, formatDate, initials, handleAuthError };
})();

App.router = (function () {
  const routes = [];
  function register(pattern, handler, opts = {}) {
    const segments = pattern.split('/').filter(Boolean);
    routes.push({
      pattern, segments, handler,
      requiresAuth: Boolean(opts.requiresAuth),
      requiresAdmin: Boolean(opts.requiresAdmin),
      noChrome: Boolean(opts.noChrome),
    });
  }
  function match(hashPath) {
    const pathSegments = hashPath.split('/').filter(Boolean);
    for (const route of routes) {
      if (route.segments.length !== pathSegments.length) continue;
      const params = {}; let ok = true;
      for (let i = 0; i < route.segments.length; i++) {
        const rs = route.segments[i]; const ps = pathSegments[i];
        if (rs.startsWith(':')) params[rs.slice(1)] = decodeURIComponent(ps);
        else if (rs !== ps) { ok = false; break; }
      }
      if (ok) return { route, params };
    }
    return null;
  }
  async function resolve() {
    const hash = location.hash || '#/home';
    const path = hash.replace(/^#/, '') || '/home';
    const found = match(path);
    const view = document.getElementById('view');
    if (!found) { view.innerHTML = App.ui.emptyStateHtml('fa-compass', 'الصفحة غير موجودة', 'تحقق من الرابط أو عد للرئيسية'); return; }

    document.body.classList.toggle('auth-mode', found.route.noChrome);

    if (found.route.requiresAuth && !App.state.isLoggedIn()) {
      location.hash = App.state.hasSeenWelcome() ? '#/login' : '#/welcome';
      return;
    }
    if (found.route.requiresAdmin && !App.state.isAdmin()) { App.ui.toast('هذه الصفحة للمشرفين فقط', 'err'); location.hash = '#/home'; return; }
    App.main.highlightNav(path);
    view.innerHTML = App.ui.loadingHtml();
    try { await found.route.handler(found.params); }
    catch (err) { if (!App.ui.handleAuthError(err)) view.innerHTML = App.ui.emptyStateHtml('fa-triangle-exclamation', 'حدث خطأ', err.message); }
    window.scrollTo({ top: 0, behavior: 'instant' in window ? 'instant' : 'auto' });
  }
  function start() { window.addEventListener('hashchange', resolve); resolve(); }
  return { register, start, resolve };
})();

App.views = App.views || {};

App.views.welcome = async function welcome() {
  const view = document.getElementById('view');
  view.innerHTML = `
    <div class="welcome-screen an">
      <div class="welcome-hero">
        <div class="welcome-badge"><i class="fas fa-graduation-cap"></i></div>
        <div class="welcome-orbit">
          <i class="fas fa-robot"></i><i class="fas fa-clipboard-question"></i><i class="fas fa-trophy"></i>
        </div>
      </div>
      <div class="welcome-logo">ذَكِيّ.</div>
      <h1 class="welcome-title">مرحباً بك في ذَكِيّ</h1>
      <p class="welcome-sub">منصتك الذكية لإدارة المحاضرات والمواد الدراسية بكل سهولة وتنظيم</p>
      <div class="welcome-features">
        <div class="welcome-feature"><div class="wf-ico"><i class="fas fa-robot"></i></div><h5>مساعد ذكي</h5><p>يجيب فوراً</p></div>
        <div class="welcome-feature"><div class="wf-ico"><i class="fas fa-clipboard-question"></i></div><h5>اختبارات</h5><p>بعد كل محاضرة</p></div>
        <div class="welcome-feature"><div class="wf-ico"><i class="fas fa-star"></i></div><h5>نقاط</h5><p>لكل إنجاز</p></div>
        <div class="welcome-feature"><div class="wf-ico"><i class="fas fa-ranking-star"></i></div><h5>تنافس</h5><p>مع طلاب بلدك</p></div>
      </div>
      <button class="btn btn-primary welcome-cta" id="welcomeStart">ابدأ الآن <i class="fas fa-arrow-left"></i></button>
      <button class="welcome-skip" id="welcomeSkip">تخطي</button>
    </div>
  `;
  const goLogin = () => { App.state.markWelcomeSeen(); location.hash = '#/login'; };
  document.getElementById('welcomeStart').addEventListener('click', goLogin);
  document.getElementById('welcomeSkip').addEventListener('click', goLogin);
};

App.views.login = async function login() {
  const view = document.getElementById('view');
  view.innerHTML = `
    <div class="auth-card an">
      <div style="text-align:center;margin-bottom:18px">
        <div class="logo" style="font-size:1.6rem">ذَكِيّ<span class="logo-dot">.</span></div>
      </div>
      <div class="page-title" style="text-align:center">مرحباً بعودتك 👋</div>
      <div class="page-sub" style="text-align:center">سجّل الدخول لمتابعة رحلتك التعليمية</div>
      <div class="card">
        <form id="loginForm">
          <div class="form-group"><label>البريد الإلكتروني</label><input class="form-control" type="email" id="loginEmail" required placeholder="example@mail.com"></div>
          <div class="form-group"><label>كلمة المرور</label><input class="form-control" type="password" id="loginPassword" required placeholder="••••••••"></div>
          <div class="form-error" id="loginError"></div>
          <button class="btn btn-primary btn-block" type="submit" id="loginSubmit">دخول</button>
        </form>
      </div>
      <div class="auth-switch">ليس لديك حساب؟ <a href="#/register">أنشئ حساباً جديداً</a></div>
    </div>
  `;
  document.getElementById('loginForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const errorEl = document.getElementById('loginError');
    const submitBtn = document.getElementById('loginSubmit');
    errorEl.classList.remove('show'); submitBtn.disabled = true; submitBtn.textContent = 'جاري الدخول...';
    try {
      const { token, user } = await App.api.login({ email: document.getElementById('loginEmail').value.trim(), password: document.getElementById('loginPassword').value });
      App.state.setSession(token, user); App.state.markWelcomeSeen(); App.state.hydrateSelectionFromUser(user); App.main.refreshHeader();
      App.ui.toast(`أهلاً بك ${user.name}!`, 'ok');
      location.hash = (user.countryId && user.stageId) ? '#/home' : '#/onboarding';
    } catch (err) { errorEl.textContent = err.message; errorEl.classList.add('show'); }
    finally { submitBtn.disabled = false; submitBtn.textContent = 'دخول'; }
  });
};

App.views.register = async function register() {
  const view = document.getElementById('view');
  view.innerHTML = `
    <div class="auth-card an">
      <div style="text-align:center;margin-bottom:18px">
        <div class="logo" style="font-size:1.6rem">ذَكِيّ<span class="logo-dot">.</span></div>
      </div>
      <div class="page-title" style="text-align:center">إنشاء حساب جديد ✨</div>
      <div class="page-sub" style="text-align:center">انضم وابدأ اجمع النقاط من أول محاضرة</div>
      <div class="card">
        <form id="registerForm">
          <div class="form-group"><label>الاسم الكامل</label><input class="form-control" type="text" id="regName" required placeholder="اسمك"></div>
          <div class="form-group"><label>البريد الإلكتروني</label><input class="form-control" type="email" id="regEmail" required placeholder="example@mail.com"></div>
          <div class="form-group"><label>كلمة المرور</label><input class="form-control" type="password" id="regPassword" required minlength="6" placeholder="6 محارف على الأقل"></div>
          <div class="form-error" id="regError"></div>
          <button class="btn btn-primary btn-block" type="submit" id="regSubmit">إنشاء الحساب</button>
        </form>
      </div>
      <div class="auth-switch">لديك حساب بالفعل؟ <a href="#/login">سجّل الدخول</a></div>
    </div>
  `;
  document.getElementById('registerForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const errorEl = document.getElementById('regError');
    const submitBtn = document.getElementById('regSubmit');
    errorEl.classList.remove('show'); submitBtn.disabled = true; submitBtn.textContent = 'جاري الإنشاء...';
    try {
      const { token, user } = await App.api.register({
        name: document.getElementById('regName').value.trim(), email: document.getElementById('regEmail').value.trim(),
        password: document.getElementById('regPassword').value,
      });
      App.state.setSession(token, user); App.state.markWelcomeSeen();
      App.main.refreshHeader();
      App.ui.toast(`تم إنشاء حسابك بنجاح، أهلاً بك ${user.name}!`, 'ok');
      // اختيار الدولة/المحافظة/المرحلة يتم دائماً بعد ذلك من صفحة الاختيار
      location.hash = '#/onboarding';
    } catch (err) { errorEl.textContent = err.message; errorEl.classList.add('show'); }
    finally { submitBtn.disabled = false; submitBtn.textContent = 'إنشاء الحساب'; }
  });
};

const EDUCATION_LEVEL_LABELS = { primary: 'الابتدائية', intermediate: 'المتوسطة', secondary: 'الثانوية', other: 'أخرى' };

App.views.onboarding = async function onboarding() {
  const view = document.getElementById('view');
  view.innerHTML = `
    <div class="an" style="padding-top:26px">
      <div class="page-title" style="text-align:center;font-size:1.35rem">اختر بياناتك الدراسية</div>
      <div class="page-sub" style="text-align:center">سنعرض لك المواد والمدرّسين المناسبين لك تلقائياً</div>
      <div class="card" style="max-width:420px;margin:0 auto">
        <div class="form-group" id="obCountryGroup"><label>الدولة</label><select class="form-control" id="obCountry"><option value="">جاري التحميل...</option></select></div>
        <div class="form-group" id="obGovernorateGroup" hidden><label>المحافظة</label><select class="form-control" id="obGovernorate" disabled><option value="">اختر الدولة أولاً</option></select></div>
        <div class="form-group"><label>المرحلة</label><select class="form-control" id="obLevel" disabled><option value="">اختر الدولة أولاً</option></select></div>
        <div class="form-group"><label>الصف</label><select class="form-control" id="obGrade" disabled><option value="">اختر المرحلة أولاً</option></select></div>
        <button class="btn btn-primary btn-block" id="obSubmit" disabled>ابدأ التعلّم <i class="fas fa-arrow-left"></i></button>
      </div>
    </div>
  `;
  const countryGroup = document.getElementById('obCountryGroup');
  const countrySelect = document.getElementById('obCountry');
  const govGroup = document.getElementById('obGovernorateGroup');
  const govSelect = document.getElementById('obGovernorate');
  const levelSelect = document.getElementById('obLevel');
  const gradeSelect = document.getElementById('obGrade');
  const submitBtn = document.getElementById('obSubmit');
  let stagesByLevel = {};
  let governorateRequired = false;

  function updateSubmitState() {
    const ok = Boolean(countrySelect.value) && (!governorateRequired || govSelect.value) && Boolean(gradeSelect.value);
    submitBtn.disabled = !ok;
  }

  async function onCountryChange(prefill) {
    prefill = prefill || {};
    submitBtn.disabled = true;
    govGroup.hidden = true; governorateRequired = false;
    levelSelect.disabled = true; levelSelect.innerHTML = '<option value="">اختر الدولة أولاً</option>';
    gradeSelect.disabled = true; gradeSelect.innerHTML = '<option value="">اختر المرحلة أولاً</option>';
    if (!countrySelect.value) { return; }

    govSelect.disabled = true; govSelect.innerHTML = '<option value="">جاري التحميل...</option>';
    levelSelect.innerHTML = '<option value="">جاري التحميل...</option>';
    try {
      const [{ governorates }, { stages }] = await Promise.all([
        App.api.getGovernorates(countrySelect.value),
        App.api.getStages(countrySelect.value),
      ]);

      if (governorates.length) {
        governorateRequired = true; govGroup.hidden = false; govSelect.disabled = false;
        govSelect.innerHTML = '<option value="">اختر المحافظة...</option>' + governorates.map((g) => `<option value="${g.id}">${App.ui.escapeHtml(g.name_ar)}</option>`).join('');
        if (prefill.governorateId) govSelect.value = String(prefill.governorateId);
      } else {
        governorateRequired = false; govGroup.hidden = true; govSelect.innerHTML = '';
      }

      stagesByLevel = {};
      stages.forEach((s) => {
        if (!stagesByLevel[s.education_level]) stagesByLevel[s.education_level] = { minOrder: s.level_order, stages: [] };
        stagesByLevel[s.education_level].stages.push(s);
        stagesByLevel[s.education_level].minOrder = Math.min(stagesByLevel[s.education_level].minOrder, s.level_order);
      });
      const levels = Object.keys(stagesByLevel).sort((a, b) => stagesByLevel[a].minOrder - stagesByLevel[b].minOrder);
      levelSelect.disabled = false;
      levelSelect.innerHTML = '<option value="">اختر المرحلة...</option>' + levels.map((lv) => `<option value="${App.ui.escapeHtml(lv)}">${App.ui.escapeHtml(EDUCATION_LEVEL_LABELS[lv] || lv)}</option>`).join('');

      if (prefill.stageId) {
        const level = levels.find((lv) => stagesByLevel[lv].stages.some((s) => String(s.id) === String(prefill.stageId)));
        if (level) { levelSelect.value = level; onLevelChange(prefill.stageId); }
      }
    } catch (err) { App.ui.toast(err.message, 'err'); }
    updateSubmitState();
  }

  function onLevelChange(prefillStageId) {
    gradeSelect.disabled = true; gradeSelect.innerHTML = '<option value="">اختر الصف أولاً</option>';
    const group = stagesByLevel[levelSelect.value];
    if (!group) { updateSubmitState(); return; }
    gradeSelect.disabled = false;
    const sorted = group.stages.slice().sort((a, b) => a.level_order - b.level_order);
    gradeSelect.innerHTML = '<option value="">اختر الصف...</option>' + sorted.map((s) => `<option value="${s.id}">${App.ui.escapeHtml(s.name_ar)}</option>`).join('');
    if (prefillStageId) gradeSelect.value = String(prefillStageId);
    updateSubmitState();
  }

  let countries = [];
  try {
    ({ countries } = await App.api.getCountries());
    if (countries.length === 1) {
      // دولة واحدة فقط مدعومة حالياً: لا داعي لإظهار اختيار لا فائدة منه
      countryGroup.hidden = true;
      countrySelect.innerHTML = `<option value="${countries[0].id}">${countries[0].flag_emoji || ''} ${App.ui.escapeHtml(countries[0].name_ar)}</option>`;
      countrySelect.value = String(countries[0].id);
    } else {
      countryGroup.hidden = false;
      countrySelect.innerHTML = '<option value="">اختر الدولة...</option>' + countries.map((c) => `<option value="${c.id}">${c.flag_emoji || ''} ${App.ui.escapeHtml(c.name_ar)}</option>`).join('');
    }
  } catch (err) { countrySelect.innerHTML = '<option value="">تعذّر تحميل الدول</option>'; App.ui.toast(err.message, 'err'); return; }

  countrySelect.addEventListener('change', () => onCountryChange());
  govSelect.addEventListener('change', updateSubmitState);
  levelSelect.addEventListener('change', () => onLevelChange());
  gradeSelect.addEventListener('change', updateSubmitState);

  // إن كان للحساب اختيار محفوظ مسبقاً (تعديل لاحق عبر "تغيير المرحلة")، نعبّئ
  // الحقول به تلقائياً بدل أن يبدأ الطالب من الصفر في كل مرة. وإن كانت هناك
  // دولة واحدة فقط فالاختيار تم تلقائياً أعلاه، فنبدأ تحميل بقية الخطوات فوراً
  // حتى للحساب الجديد الذي لا يملك أي اختيار محفوظ بعد.
  const user = App.state.getUser();
  if (user && user.countryId && countries.some((c) => String(c.id) === String(user.countryId))) {
    countrySelect.value = String(user.countryId);
  }
  if (countrySelect.value) {
    await onCountryChange({ governorateId: user && user.governorateId, stageId: user && user.stageId });
  }

  submitBtn.addEventListener('click', async () => {
    submitBtn.disabled = true; submitBtn.innerHTML = '<span class="spinner"></span> جاري الحفظ...';
    try {
      const payload = { countryId: countrySelect.value, governorateId: govSelect.value || null, stageId: gradeSelect.value };
      const { user: updatedUser } = await App.api.updateSelection(payload);
      App.state.updateUser(updatedUser);
      App.state.setSelection(payload.countryId, payload.governorateId, payload.stageId);
      location.hash = '#/home';
    } catch (err) {
      App.ui.toast(err.message, 'err');
      submitBtn.disabled = false; submitBtn.innerHTML = 'ابدأ التعلّم <i class="fas fa-arrow-left"></i>';
    }
  });
};

App.views.home = async function home() {
  const selection = App.state.getSelection();
  if (!selection.countryId || !selection.stageId) { location.hash = '#/onboarding'; return; }
  const view = document.getElementById('view');
  const user = App.state.getUser();
  view.innerHTML = `
    <div class="an">
      <div class="profile-card an">
        <div class="profile-row">
          <div class="profile-av">${App.ui.initials(user.name)}</div>
          <div class="profile-info"><h2>أهلاً، ${App.ui.escapeHtml(user.name)}</h2><p>واصل التعلّم واجمع المزيد من النقاط اليوم</p></div>
        </div>
        <div class="profile-stats">
          <div class="profile-stat"><b id="homePoints">${user.pointsTotal || 0}</b><span>نقطة</span></div>
          <div class="profile-stat"><b><i class="fas fa-arrow-left" style="font-size:.9rem"></i></b><span><a href="#/leaderboard" style="color:inherit">لوحة المتصدرين</a></span></div>
        </div>
      </div>
      <div class="section-head"><h3><i class="fas fa-graduation-cap"></i> المواد الدراسية</h3><a href="#/onboarding" style="font-size:.68rem;color:var(--cyan);font-weight:700">تغيير المرحلة</a></div>
      <div id="subjectsHolder">${App.ui.loadingHtml('جاري تحميل المواد...')}</div>
    </div>
  `;
  const holder = document.getElementById('subjectsHolder');
  try {
    const { subjects, needsGeneration } = await App.api.getSubjects(selection.stageId);
    if (!subjects.length && needsGeneration) {
      holder.innerHTML = App.state.isAdmin()
        ? `<div class="center-box"><i class="fas fa-wand-magic-sparkles"></i><div style="font-weight:700;color:var(--text)">لا يوجد منهج بعد لهذه المرحلة</div><div style="font-size:.75rem">يمكنك توليده تلقائياً بالذكاء الاصطناعي الآن (مواد، مدرّسون، ومحاضرات)</div><button class="btn btn-primary" id="genCurriculumBtn" style="margin-top:10px"><i class="fas fa-sparkles"></i> توليد المنهج بالذكاء الاصطناعي</button><div id="genErrorBox"></div></div>`
        : App.ui.emptyStateHtml('fa-hourglass-half', 'المنهج قيد التحضير', 'يقوم فريقنا بإعداد محتوى هذه المرحلة، عد قريباً');
      const genBtn = document.getElementById('genCurriculumBtn');
      if (genBtn) {
        genBtn.addEventListener('click', async () => {
          genBtn.disabled = true; genBtn.innerHTML = '<span class="spinner"></span> جاري التوليد (قد يستغرق دقيقة)...';
          const errorBox = document.getElementById('genErrorBox');
          errorBox.innerHTML = '';
          try {
            const result = await App.api.generateCurriculum(selection.stageId);
            App.ui.toast(result.message, (result.failedSubjects && result.failedSubjects.length) ? '' : 'ok');
            App.views.home();
          } catch (err) {
            App.ui.toast('فشل التوليد — التفاصيل أسفل الزر', 'err');
            genBtn.disabled = false;
            genBtn.innerHTML = '<i class="fas fa-sparkles"></i> توليد المنهج بالذكاء الاصطناعي';
            // نعرض رسالة الخطأ الكاملة بشكل ثابت (لا تختفي كالتوست) مع زر نسخ
            // كي يسهل تشخيص السبب الحقيقي بدل تخمينه
            errorBox.innerHTML = `
              <div class="card an" style="margin-top:14px;border:1px solid var(--danger);text-align:start">
                <div style="display:flex;align-items:center;gap:8px;color:var(--danger);font-weight:700;font-size:.8rem;margin-bottom:8px">
                  <i class="fas fa-triangle-exclamation"></i> سبب فشل التوليد
                </div>
                <div style="font-size:.72rem;color:var(--muted);line-height:1.8;word-break:break-word">${App.ui.escapeHtml(err.message)}</div>
                <button class="btn btn-outline btn-sm" id="copyGenErrorBtn" style="margin-top:10px"><i class="fas fa-copy"></i> نسخ رسالة الخطأ</button>
              </div>`;
            document.getElementById('copyGenErrorBtn').addEventListener('click', async () => {
              try { await navigator.clipboard.writeText(err.message); App.ui.toast('تم نسخ رسالة الخطأ', 'ok'); }
              catch (copyErr) { App.ui.toast('تعذّر النسخ التلقائي، انسخ النص يدوياً', 'err'); }
            });
          }
        });
      }
      return;
    }
    holder.innerHTML = subjects.map((s, i) => `
      <div class="list-row an" data-nav="#/subject/${s.id}" style="animation-delay:${i * 0.05}s">
        <div class="list-ico" style="${s.color_hex ? `background:${s.color_hex}22;color:${s.color_hex}` : ''}"><i class="fas ${s.icon || 'fa-book'}"></i></div>
        <div class="list-body"><h4>${App.ui.escapeHtml(s.name_ar)}</h4><p>${App.ui.escapeHtml(s.name_en || '')}</p></div>
        <i class="fas fa-chevron-left" style="color:var(--muted)"></i>
      </div>`).join('');
    holder.querySelectorAll('[data-nav]').forEach((el) => el.addEventListener('click', () => (location.hash = el.dataset.nav)));
  } catch (err) { holder.innerHTML = App.ui.emptyStateHtml('fa-triangle-exclamation', 'تعذّر تحميل المواد', err.message); }
};

App.views.subjectTeachers = async function subjectTeachers({ id }) {
  const view = document.getElementById('view');
  const [subjectRes, teachersRes] = await Promise.all([App.api.getSubject(id), App.api.getTeachers(id)]);
  const subject = subjectRes.subject; const teachers = teachersRes.teachers;
  view.innerHTML = `
    <div class="an">
      <div class="breadcrumb"><a href="#/home">الرئيسية</a> <i class="fas fa-chevron-left"></i> <span>${App.ui.escapeHtml(subject.name_ar)}</span></div>
      <div class="page-title"><i class="fas ${subject.icon || 'fa-book'}" style="color:var(--cyan);margin-inline-end:6px"></i>${App.ui.escapeHtml(subject.name_ar)}</div>
      <div class="page-sub">اختر المدرّس لعرض محاضراته</div>
      <div id="teachersHolder"></div>
    </div>
  `;
  const holder = document.getElementById('teachersHolder');
  if (!teachers.length) { holder.innerHTML = App.ui.emptyStateHtml('fa-chalkboard-user', 'لا يوجد مدرّسون بعد', 'سيُضافون قريباً'); return; }
  // المدرّسون مرتّبون من الخادم حسب مجموع المشاهدات تنازلياً؛ الأول (إن كان
  // لديه مشاهدات فعلاً) يُميَّز بشارة "الأعلى مشاهدة"
  holder.innerHTML = `<div class="grid-cards">${teachers.map((t, i) => `
    <div class="subject-card an" data-nav="#/teacher/${t.id}" style="position:relative">
      ${i === 0 && t.total_views > 0 ? '<span class="chip chip-done" style="position:absolute;top:8px;inset-inline-end:8px;font-size:.6rem"><i class="fas fa-fire"></i> الأعلى مشاهدة</span>' : ''}
      <div class="subject-ico round" style="${t.avatar_color ? `background:${t.avatar_color}` : ''}"><i class="fas fa-chalkboard-user"></i></div>
      <h4>${App.ui.escapeHtml(t.name_ar)}</h4>
      <p>${t.lecture_count} محاضرة${t.unit_count ? ' · ' + t.unit_count + ' وحدة' : ''}</p>
      <p style="margin-top:2px"><i class="fas fa-eye" style="font-size:.65rem"></i> ${t.total_views}</p>
    </div>`).join('')}</div>`;
  holder.querySelectorAll('[data-nav]').forEach((el) => el.addEventListener('click', () => (location.hash = el.dataset.nav)));
};

App.views.teacherUnits = async function teacherUnits({ id }) {
  const view = document.getElementById('view');
  const [teacherRes, unitsRes] = await Promise.all([App.api.getTeacher(id), App.api.getTeacherUnits(id)]);
  const teacher = teacherRes.teacher; const units = unitsRes.units;
  view.innerHTML = `
    <div class="an">
      <div class="breadcrumb">
        <a href="#/home">الرئيسية</a> <i class="fas fa-chevron-left"></i>
        <a href="#/subject/${teacher.subject_id}">${App.ui.escapeHtml(teacher.subject_name)}</a> <i class="fas fa-chevron-left"></i>
        <span>${App.ui.escapeHtml(teacher.name_ar)}</span>
      </div>
      <div class="page-title"><i class="fas fa-chalkboard-user" style="color:var(--cyan);margin-inline-end:6px"></i>${App.ui.escapeHtml(teacher.name_ar)}</div>
      <div class="page-sub">${App.ui.escapeHtml(teacher.bio || '')}</div>
      <div id="unitsHolder"></div>
    </div>
  `;
  const holder = document.getElementById('unitsHolder');
  if (!units.length) { holder.innerHTML = App.ui.emptyStateHtml('fa-layer-group', 'لا توجد وحدات بعد', 'سيتم إضافتها قريباً'); return; }
  holder.innerHTML = units.map((u, i) => `
    <div class="list-row an" data-nav="#/unit/${u.id}" style="animation-delay:${i * 0.05}s">
      <div class="list-ico"><i class="fas fa-layer-group"></i></div>
      <div class="list-body"><h4>${App.ui.escapeHtml(u.title_ar)}</h4><p>${App.ui.escapeHtml(u.description || '')}</p></div>
      <span class="chip">${u.lecture_count} محاضرة</span>
    </div>`).join('');
  holder.querySelectorAll('[data-nav]').forEach((el) => el.addEventListener('click', () => (location.hash = el.dataset.nav)));
};

App.views.unitLectures = async function unitLectures({ id }) {
  const view = document.getElementById('view');
  const [unitRes, lecturesRes] = await Promise.all([App.api.getUnit(id), App.api.getLectures(id)]);
  const unit = unitRes.unit; const lectures = lecturesRes.lectures;
  view.innerHTML = `
    <div class="an">
      <div class="breadcrumb">
        <a href="#/home">الرئيسية</a> <i class="fas fa-chevron-left"></i>
        <a href="#/subject/${unit.subject_id}">${App.ui.escapeHtml(unit.subject_name)}</a> <i class="fas fa-chevron-left"></i>
        <a href="#/teacher/${unit.teacher_id}">${App.ui.escapeHtml(unit.teacher_name)}</a> <i class="fas fa-chevron-left"></i>
        <span>${App.ui.escapeHtml(unit.title_ar)}</span>
      </div>
      <div class="page-title"><i class="fas fa-layer-group" style="color:var(--cyan);margin-inline-end:6px"></i>${App.ui.escapeHtml(unit.title_ar)}</div>
      <div class="page-sub">${App.ui.escapeHtml(unit.description || '')}</div>
      <div id="lecturesHolder"></div>
    </div>
  `;
  const holder = document.getElementById('lecturesHolder');
  if (!lectures.length) { holder.innerHTML = App.ui.emptyStateHtml('fa-video', 'لا توجد محاضرات بعد', 'سيتم إضافتها قريباً'); return; }
  holder.innerHTML = lectures.map((l, i) => `
    <div class="list-row an" data-nav="#/lecture/${l.id}" style="animation-delay:${i * 0.05}s">
      <div class="list-ico"><i class="fas fa-play"></i></div>
      <div class="list-body"><h4>${i + 1}. ${App.ui.escapeHtml(l.title_ar)}</h4><p>${App.ui.escapeHtml(l.description || '')}</p></div>
      <span class="list-meta">${App.ui.formatDuration(l.duration_seconds)}</span>
    </div>`).join('');
  holder.querySelectorAll('[data-nav]').forEach((el) => el.addEventListener('click', () => (location.hash = el.dataset.nav)));
};

let ytApiPromise = null;
function loadYouTubeApi() {
  if (window.YT && window.YT.Player) return Promise.resolve();
  if (ytApiPromise) return ytApiPromise;
  ytApiPromise = new Promise((resolve) => {
    const prevCallback = window.onYouTubeIframeAPIReady;
    window.onYouTubeIframeAPIReady = () => { if (prevCallback) prevCallback(); resolve(); };
    const tag = document.createElement('script'); tag.src = 'https://www.youtube.com/iframe_api'; document.head.appendChild(tag);
  });
  return ytApiPromise;
}

App.views.lecture = async function lecture({ id }) {
  const view = document.getElementById('view');
  const { lecture: lec, progress } = await App.api.getLecture(id);
  const isCompleted = Boolean(progress && progress.is_completed);
  // كل محاضرة تُعرض داخل صفحة المحاضرة نفسها (مضمّنة)، ولا يُوجَّه الطالب أبداً إلى يوتيوب مباشرة
  const videoInner = lec.youtube_video_id
    ? `<div id="ytPlayer"></div>`
    : `<div class="video-fallback"><i class="fas fa-clock"></i><div>سيتم إضافة فيديو هذه المحاضرة قريباً</div></div>`;
  view.innerHTML = `
    <div class="an">
      <div class="breadcrumb">
        <a href="#/home">الرئيسية</a> <i class="fas fa-chevron-left"></i>
        <a href="#/subject/${lec.subject_id}">${App.ui.escapeHtml(lec.subject_name)}</a> <i class="fas fa-chevron-left"></i>
        <a href="#/teacher/${lec.teacher_id}">${App.ui.escapeHtml(lec.teacher_name)}</a> <i class="fas fa-chevron-left"></i>
        <a href="#/unit/${lec.unit_id}">${App.ui.escapeHtml(lec.unit_title)}</a>
      </div>
      <div class="page-title">${App.ui.escapeHtml(lec.title_ar)}</div>
      <div class="page-sub">${App.ui.escapeHtml(lec.description || '')}</div>
      <div class="video-wrap">${videoInner}</div>
      <div class="chip ${isCompleted ? 'chip-done' : ''}" id="completionChip" style="margin-bottom:14px"><i class="fas ${isCompleted ? 'fa-circle-check' : 'fa-clock'}"></i> ${isCompleted ? 'تم إكمال المشاهدة' : 'لم تكتمل بعد'}</div>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <button class="btn btn-outline" id="markCompleteBtn" ${isCompleted ? 'disabled' : ''}><i class="fas fa-check"></i> ${isCompleted ? 'تمت المشاهدة' : 'أنهيت المشاهدة'}</button>
        <button class="btn btn-primary" id="startQuizBtn"><i class="fas fa-pen-to-square"></i> ابدأ الاختبار</button>
      </div>
    </div>
    <button class="tutor-fab" id="tutorFab" title="المساعد الذكي"><i class="fas fa-robot"></i></button>
    <div class="tutor-panel" id="tutorPanel">
      <div class="tutor-head"><h4><i class="fas fa-robot"></i> المساعد الذكي</h4><button class="icon-btn" id="tutorCloseBtn"><i class="fas fa-xmark"></i></button></div>
      <div class="tutor-body" id="tutorBody"><div class="tutor-msg assistant">أهلاً بك! أنا مساعدك الذكي لهذه المحاضرة. اسألني عن أي نقطة غامضة 🤓</div></div>
      <form class="tutor-input" id="tutorForm">
        <input type="text" id="tutorInput" placeholder="اكتب سؤالك هنا..." autocomplete="off">
        <button class="tutor-send" type="submit"><i class="fas fa-paper-plane"></i></button>
      </form>
    </div>
  `;
  view.querySelectorAll('[data-nav]').forEach((el) => el.addEventListener('click', () => (location.hash = el.dataset.nav)));
  async function markComplete(watchedSeconds) {
    if (isCompletedNow) return;
    isCompletedNow = true;
    try {
      const res = await App.api.updateProgress(id, { watchedSeconds: watchedSeconds || 0, completed: true });
      const chip = document.getElementById('completionChip');
      if (chip) { chip.classList.add('chip-done'); chip.innerHTML = '<i class="fas fa-circle-check"></i> تم إكمال المشاهدة'; }
      const btn = document.getElementById('markCompleteBtn');
      if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-check"></i> تمت المشاهدة'; }
      if (res.pointsAwarded) {
        App.ui.toast(`أحسنت! حصلت على ${res.pointsAwarded} نقطة 🎉`, 'ok');
        App.state.updateUser({ pointsTotal: (App.state.getUser().pointsTotal || 0) + res.pointsAwarded });
        App.main.refreshHeader();
      }
    } catch (err) { isCompletedNow = false; if (!App.ui.handleAuthError(err)) App.ui.toast(err.message, 'err'); }
  }
  let isCompletedNow = isCompleted;
  document.getElementById('markCompleteBtn').addEventListener('click', () => markComplete(lec.duration_seconds));
  if (lec.youtube_video_id) {
    loadYouTubeApi().then(() => {
      try {
        new YT.Player('ytPlayer', { videoId: lec.youtube_video_id, playerVars: { rel: 0 },
          events: { onStateChange: (e) => { if (e.data === YT.PlayerState.ENDED) markComplete(lec.duration_seconds); } } });
      } catch (err) { console.error('YouTube player init failed:', err); }
    });
  }
  document.getElementById('startQuizBtn').addEventListener('click', () => { location.hash = `#/quiz/${id}`; });
  const fab = document.getElementById('tutorFab');
  const panel = document.getElementById('tutorPanel');
  const body = document.getElementById('tutorBody');
  const form = document.getElementById('tutorForm');
  const input = document.getElementById('tutorInput');
  let historyLoaded = false;
  function addMessage(role, text) {
    const div = document.createElement('div'); div.className = `tutor-msg ${role}`; div.textContent = text;
    body.appendChild(div); body.scrollTop = body.scrollHeight;
  }
  fab.addEventListener('click', async () => {
    panel.classList.toggle('open');
    if (panel.classList.contains('open') && !historyLoaded) {
      historyLoaded = true;
      try { const { messages } = await App.api.getTutorHistory(id); messages.forEach((m) => addMessage(m.role, m.message_text)); }
      catch (err) {}
    }
  });
  document.getElementById('tutorCloseBtn').addEventListener('click', () => panel.classList.remove('open'));
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const text = input.value.trim();
    if (!text) return;
    addMessage('user', text); input.value = ''; input.disabled = true;
    const typingEl = document.createElement('div'); typingEl.className = 'tutor-msg assistant'; typingEl.innerHTML = '<span class="spinner"></span>';
    body.appendChild(typingEl); body.scrollTop = body.scrollHeight;
    try { const { reply } = await App.api.sendTutorMessage(id, text); typingEl.remove(); addMessage('assistant', reply); }
    catch (err) { typingEl.remove(); addMessage('assistant', `عذراً، حدث خطأ: ${err.message}`); }
    finally { input.disabled = false; input.focus(); }
  });
};

App.views.quiz = async function quiz({ lectureId }) {
  const view = document.getElementById('view');
  view.innerHTML = App.ui.loadingHtml('جاري تجهيز الاختبار بالذكاء الاصطناعي...');
  const { quiz: quizData, questions } = await App.api.getQuiz(lectureId);
  const startedAt = new Date().toISOString();
  const selected = {};
  function renderQuestions(submittedResults) {
    const resultMap = submittedResults ? new Map(submittedResults.map((r) => [r.questionId, r])) : null;
    return questions.map((q, qi) => {
      const result = resultMap ? resultMap.get(q.id) : null;
      const optionsHtml = q.options.map((o) => {
        let cls = '';
        if (result) { if (o.id === result.correctOptionId) cls = 'correct'; else if (o.id === result.selectedOptionId && !result.isCorrect) cls = 'incorrect'; }
        else if (selected[q.id] === o.id) cls = 'selected';
        return `<div class="option-row ${cls}" data-question="${q.id}" data-option="${o.id}" ${result ? 'style="pointer-events:none"' : ''}>
          <span class="option-mark">${result && o.id === result.correctOptionId ? '<i class="fas fa-check"></i>' : result && cls === 'incorrect' ? '<i class="fas fa-xmark"></i>' : ''}</span>
          <span>${App.ui.escapeHtml(o.option_text)}</span>
        </div>`;
      }).join('');
      return `<div class="question-card an">
        <div class="quiz-progress">السؤال ${qi + 1} من ${questions.length}</div>
        <div class="question-text">${App.ui.escapeHtml(q.question_text)}</div>
        ${optionsHtml}
        ${result && result.explanation ? `<div class="option-explain"><i class="fas fa-lightbulb" style="color:var(--gold)"></i> ${App.ui.escapeHtml(result.explanation)}</div>` : ''}
      </div>`;
    }).join('');
  }
  function attachOptionHandlers() {
    view.querySelectorAll('.option-row').forEach((row) => {
      row.addEventListener('click', () => {
        const qId = Number(row.dataset.question); const oId = Number(row.dataset.option);
        selected[qId] = oId;
        view.querySelectorAll(`.option-row[data-question="${qId}"]`).forEach((r) => r.classList.remove('selected'));
        row.classList.add('selected'); updateSubmitState();
      });
    });
  }
  function updateSubmitState() {
    const submitBtn = document.getElementById('submitQuizBtn'); if (!submitBtn) return;
    const answeredCount = Object.keys(selected).length;
    submitBtn.disabled = answeredCount < questions.length;
    submitBtn.textContent = answeredCount < questions.length ? `أجب على كل الأسئلة (${answeredCount}/${questions.length})` : 'تصحيح الاختبار';
  }
  function renderForm() {
    view.innerHTML = `
      <div class="an">
        <div class="breadcrumb"><a href="#/lecture/${lectureId}">العودة للمحاضرة</a></div>
        <div class="page-title">${App.ui.escapeHtml(quizData.title)}</div>
        <div class="page-sub">أجب على جميع الأسئلة ثم اضغط تصحيح لمعرفة نتيجتك فوراً</div>
        <div id="questionsHolder">${renderQuestions(null)}</div>
        <button class="btn btn-primary btn-block" id="submitQuizBtn" disabled>أجب على كل الأسئلة (0/${questions.length})</button>
      </div>
    `;
    attachOptionHandlers();
    document.getElementById('submitQuizBtn').addEventListener('click', handleSubmit);
  }
  async function handleSubmit() {
    const submitBtn = document.getElementById('submitQuizBtn');
    submitBtn.disabled = true; submitBtn.innerHTML = '<span class="spinner"></span> جاري التصحيح...';
    try {
      const answers = Object.entries(selected).map(([questionId, selectedOptionId]) => ({ questionId: Number(questionId), selectedOptionId }));
      const result = await App.api.submitQuiz(quizData.id, { answers, startedAt });
      renderResult(result);
    } catch (err) {
      if (!App.ui.handleAuthError(err)) App.ui.toast(err.message, 'err');
      submitBtn.disabled = false; submitBtn.textContent = 'تصحيح الاختبار';
    }
  }
  function renderResult(result) {
    const percentage = Math.round((result.correctCount / result.totalQuestions) * 100);
    view.innerHTML = `
      <div class="an">
        <div class="result-hero">
          <div class="result-ring"><b>${percentage}%</b></div>
          <div style="font-weight:800;font-size:1rem">${result.correctCount} من ${result.totalQuestions} إجابات صحيحة</div>
          <div class="result-points"><i class="fas fa-star"></i> +${result.pointsEarned} نقطة${result.perfectBonus ? ' (شامل مكافأة العلامة الكاملة 🏆)' : ''}</div>
        </div>
        <div id="questionsHolder">${renderQuestions(result.results)}</div>
        <div style="display:flex;gap:10px;margin-top:6px">
          <a class="btn btn-outline btn-block" href="#/lecture/${lectureId}">العودة للمحاضرة</a>
          <a class="btn btn-primary btn-block" href="#/leaderboard">لوحة المتصدرين</a>
        </div>
      </div>
    `;
    App.state.updateUser({ pointsTotal: (App.state.getUser().pointsTotal || 0) + result.pointsEarned });
    App.main.refreshHeader();
  }
  if (!questions.length) { view.innerHTML = App.ui.emptyStateHtml('fa-clipboard-question', 'لا يوجد اختبار متاح حالياً', 'حاول لاحقاً'); return; }
  renderForm();
};

App.views.leaderboard = async function leaderboard() {
  const view = document.getElementById('view');
  const user = App.state.getUser();
  view.innerHTML = `
    <div class="an">
      <div class="page-title"><i class="fas fa-ranking-star" style="color:var(--gold)"></i> المتصدرون ونقاطي</div>
      <div class="profile-card" style="margin-top:10px">
        <div class="profile-row"><div class="profile-av">${App.ui.initials(user.name)}</div><div class="profile-info"><h2>${App.ui.escapeHtml(user.name)}</h2><p>${App.ui.escapeHtml(user.email)}</p></div></div>
        <div class="profile-stats"><div class="profile-stat"><b id="profilePoints">${user.pointsTotal || 0}</b><span>مجموع النقاط</span></div></div>
      </div>
      <div class="section"><div class="section-head"><h3><i class="fas fa-medal"></i> الأوسمة</h3></div><div id="badgesHolder" class="grid-cards">${App.ui.loadingHtml()}</div></div>
      <div class="section-head" style="margin-top:22px"><h3><i class="fas fa-clock-rotate-left"></i> سجل النقاط</h3></div>
      <div id="historyHolder">${App.ui.loadingHtml()}</div>

      <div class="section-head" style="margin-top:22px"><h3><i class="fas fa-ranking-star"></i> لوحة المتصدرين</h3></div>
      <div class="lb-tabs"><button class="lb-tab active" data-scope="global">🌍 عالمياً</button><button class="lb-tab" data-scope="country">🏳️ داخل بلدي</button></div>
      <div id="lbHolder">${App.ui.loadingHtml()}</div>
    </div>
  `;
  (async () => {
    try {
      const { pointsTotal, history, badges } = await App.api.getMyPoints();
      document.getElementById('profilePoints').textContent = pointsTotal;
      document.getElementById('badgesHolder').innerHTML = badges.length
        ? badges.map((b) => `<div class="subject-card"><div class="subject-ico" style="background:var(--gradient-gold);color:#3a2900"><i class="fas ${b.icon || 'fa-medal'}"></i></div><h4>${App.ui.escapeHtml(b.name_ar)}</h4><p>${App.ui.formatDate(b.earned_at)}</p></div>`).join('')
        : App.ui.emptyStateHtml('fa-medal', 'لا توجد أوسمة بعد', 'أكمل محاضرات واختبارات لكسب أوسمتك الأولى');
      document.getElementById('historyHolder').innerHTML = history.length
        ? history.map((h) => `<div class="list-row" style="cursor:default"><div class="list-ico ${h.points > 0 ? '' : 'done'}"><i class="fas ${h.points > 0 ? 'fa-plus' : 'fa-minus'}"></i></div><div class="list-body"><h4>${App.ui.escapeHtml(App.config.POINTS_LABELS[h.reason] || h.reason)}</h4><p>${App.ui.formatDate(h.created_at)}</p></div><span class="chip ${h.points > 0 ? 'chip-done' : ''}">${h.points > 0 ? '+' : ''}${h.points}</span></div>`).join('')
        : App.ui.emptyStateHtml('fa-inbox', 'لا يوجد سجل نقاط بعد');
    } catch (err) { App.ui.toast(err.message, 'err'); }
  })();
  const holder = document.getElementById('lbHolder');
  const tabs = view.querySelectorAll('.lb-tab');
  async function load(scope) {
    holder.innerHTML = App.ui.loadingHtml();
    try {
      const selection = App.state.getSelection();
      const { leaderboard: rows, me } = await App.api.getLeaderboard(scope, selection.countryId);
      if (!rows.length) { holder.innerHTML = App.ui.emptyStateHtml('fa-users', 'لا يوجد طلاب بعد', 'كن أول المتصدرين!'); return; }
      const medal = (rank) => (rank === 1 ? 'top1' : rank === 2 ? 'top2' : rank === 3 ? 'top3' : '');
      const currentUserId = user.id;
      holder.innerHTML = rows.map((r) => `
        <div class="lb-row an ${r.user_id === currentUserId ? 'me' : ''}">
          <div class="lb-rank ${medal(r.rank)}">${r.rank <= 3 ? '🏅' : r.rank}</div>
          <div class="lb-av">${App.ui.initials(r.name)}</div>
          <div class="lb-info"><h4>${App.ui.escapeHtml(r.name)}</h4><p>${r.country_flag || ''} ${App.ui.escapeHtml(r.country_name_ar || '')}</p></div>
          <div class="lb-points">${r.points_total}</div>
        </div>`).join('');
      if (me && !rows.find((r) => r.user_id === me.user_id)) {
        const myRank = scope === 'country' ? me.rank_in_country : me.rank_global;
        holder.innerHTML += `<div class="lb-row me an"><div class="lb-rank">${myRank}</div><div class="lb-av">${App.ui.initials(me.name)}</div><div class="lb-info"><h4>${App.ui.escapeHtml(me.name)} (أنت)</h4></div><div class="lb-points">${me.points_total}</div></div>`;
      }
    } catch (err) { holder.innerHTML = App.ui.emptyStateHtml('fa-triangle-exclamation', 'تعذّر تحميل لوحة المتصدرين', err.message); }
  }
  tabs.forEach((tab) => tab.addEventListener('click', () => { tabs.forEach((t) => t.classList.remove('active')); tab.classList.add('active'); load(tab.dataset.scope); }));
  load('global');
};

// يبني نموذج إعدادات الذكاء الاصطناعي (DeepSeek/متوافق + YouTube) داخل أي
// عنصر حاوٍ يُمرَّر له، بدل الاعتماد دوماً على عنصر الصفحة الكامل مباشرة —
// كي يمكن تضمينه داخل صفحة "حسابي" للأدمن بدل كونه صفحة مستقلة بمسارها الخاص.
App.views.renderAiSettings = function renderAiSettings(container) {
  container.innerHTML = `
    <div style="max-width:480px;margin:0 auto">
      <div class="page-title"><i class="fas fa-key" style="color:var(--cyan)"></i> إعدادات الذكاء الاصطناعي</div>
      <div class="page-sub">اربط أي مزوّد متوافق مع OpenAI Chat Completions (DeepSeek، أو NVIDIA NIM، أو غيرهما) لتفعيل توليد المناهج، الاختبارات، والمساعد الذكي</div>
      <div id="keyStatus" class="key-status">${App.ui.loadingHtml('جاري التحقق من الحالة...')}</div>
      <div class="card">
        <form id="settingsForm">
          <div class="form-group">
            <label>مفتاح API</label>
            <input class="form-control" type="password" id="apiKeyInput" placeholder="sk-xxxx أو nvapi-xxxx" autocomplete="off">
            <div class="form-hint">يُخزَّن مشفّراً في قاعدة البيانات ولا يُعرض كاملاً بعد الحفظ. مثال DeepSeek: <a href="https://platform.deepseek.com" target="_blank" rel="noopener" style="color:var(--cyan);font-weight:700">platform.deepseek.com</a> — أو استخدم مفتاح أي مزوّد آخر متوافق مثل build.nvidia.com مع تغيير الرابط الأساسي واسم النموذج أدناه</div>
          </div>
          <div class="form-group"><label>رابط الـ API الأساسي</label><input class="form-control" type="text" id="baseUrlInput" placeholder="https://api.deepseek.com"></div>
          <div class="form-group"><label>اسم النموذج</label><input class="form-control" type="text" id="modelInput" placeholder="deepseek-chat"></div>
          <div class="form-error" id="settingsError"></div>
          <div style="display:flex;gap:10px">
            <button class="btn btn-primary btn-block" type="submit" id="saveSettingsBtn"><i class="fas fa-floppy-disk"></i> حفظ الإعدادات</button>
            <button class="btn btn-outline" type="button" id="testConnBtn"><i class="fas fa-plug"></i> اختبار الاتصال</button>
          </div>
        </form>
      </div>

      <div class="page-title" style="margin-top:26px"><i class="fab fa-youtube" style="color:var(--cyan)"></i> فيديوهات المحاضرات (اختياري)</div>
      <div class="page-sub">أضف مفتاح YouTube Data API ليبحث النظام تلقائياً عن فيديو تعليمي حقيقي لكل محاضرة ويعرضه هنا مباشرة بدل رسالة "قريباً". بدون هذا المفتاح تبقى المحاضرات تعمل لكن بلا فيديو مضمَّن.</div>
      <div id="ytKeyStatus" class="key-status">${App.ui.loadingHtml('جاري التحقق من الحالة...')}</div>
      <div class="card">
        <form id="ytSettingsForm">
          <div class="form-group">
            <label>مفتاح YouTube API</label>
            <input class="form-control" type="password" id="ytApiKeyInput" placeholder="AIzaSyxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" autocomplete="off">
            <div class="form-hint">مفتاح مجاني من <a href="https://console.cloud.google.com/apis/library/youtube.googleapis.com" target="_blank" rel="noopener" style="color:var(--cyan);font-weight:700">Google Cloud Console</a> (فعّل YouTube Data API v3 ثم أنشئ API Key). يُخزَّن مشفّراً ولا يُعرض كاملاً بعد الحفظ.</div>
          </div>
          <div class="form-error" id="ytSettingsError"></div>
          <div style="display:flex;gap:10px">
            <button class="btn btn-primary btn-block" type="submit" id="saveYtSettingsBtn"><i class="fas fa-floppy-disk"></i> حفظ الإعدادات</button>
            <button class="btn btn-outline" type="button" id="testYtConnBtn"><i class="fas fa-plug"></i> اختبار الاتصال</button>
          </div>
        </form>
      </div>
    </div>
  `;
  const statusEl = document.getElementById('keyStatus');
  async function loadStatus() {
    try {
      const s = await App.api.getDeepseekSettings();
      statusEl.className = `key-status ${s.hasApiKey ? 'ok' : 'missing'}`;
      statusEl.innerHTML = s.hasApiKey
        ? `<i class="fas fa-circle-check"></i> مفتاح مضبوط حالياً: ${App.ui.escapeHtml(s.maskedApiKey)}`
        : `<i class="fas fa-circle-exclamation"></i> لم يتم ضبط أي مفتاح بعد — الميزات الذكية معطّلة حالياً`;
      document.getElementById('baseUrlInput').value = s.apiBaseUrl || 'https://api.deepseek.com';
      document.getElementById('modelInput').value = s.model || 'deepseek-chat';
    } catch (err) { statusEl.className = 'key-status missing'; statusEl.textContent = 'تعذّر جلب حالة الإعدادات: ' + err.message; }
  }
  loadStatus();
  document.getElementById('settingsForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const errorEl = document.getElementById('settingsError');
    const saveBtn = document.getElementById('saveSettingsBtn');
    errorEl.classList.remove('show');
    const apiKey = document.getElementById('apiKeyInput').value.trim();
    if (!apiKey) { errorEl.textContent = 'يرجى إدخال مفتاح API لحفظه'; errorEl.classList.add('show'); return; }
    saveBtn.disabled = true; saveBtn.innerHTML = '<span class="spinner"></span> جاري الحفظ...';
    try {
      await App.api.saveDeepseekSettings({ apiKey, baseUrl: document.getElementById('baseUrlInput').value.trim(), model: document.getElementById('modelInput').value.trim() });
      document.getElementById('apiKeyInput').value = '';
      App.ui.toast('تم حفظ إعدادات DeepSeek بنجاح', 'ok'); loadStatus();
    } catch (err) { errorEl.textContent = err.message; errorEl.classList.add('show'); }
    finally { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fas fa-floppy-disk"></i> حفظ الإعدادات'; }
  });
  document.getElementById('testConnBtn').addEventListener('click', async () => {
    const btn = document.getElementById('testConnBtn');
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> جاري الاختبار...';
    try { await App.api.testDeepseekConnection(); App.ui.toast('الاتصال ناجح! المفتاح يعمل بشكل صحيح ✅', 'ok'); }
    catch (err) { App.ui.toast(err.message, 'err'); }
    finally { btn.disabled = false; btn.innerHTML = '<i class="fas fa-plug"></i> اختبار الاتصال'; }
  });

  const ytStatusEl = document.getElementById('ytKeyStatus');
  async function loadYtStatus() {
    try {
      const s = await App.api.getYoutubeSettings();
      ytStatusEl.className = `key-status ${s.hasApiKey ? 'ok' : 'missing'}`;
      ytStatusEl.innerHTML = s.hasApiKey
        ? `<i class="fas fa-circle-check"></i> مفتاح مضبوط حالياً: ${App.ui.escapeHtml(s.maskedApiKey)}`
        : `<i class="fas fa-circle-exclamation"></i> لم يتم ضبط أي مفتاح بعد — ستظهر رسالة "قريباً" بدل الفيديو`;
    } catch (err) { ytStatusEl.className = 'key-status missing'; ytStatusEl.textContent = 'تعذّر جلب حالة الإعدادات: ' + err.message; }
  }
  loadYtStatus();
  document.getElementById('ytSettingsForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const errorEl = document.getElementById('ytSettingsError');
    const saveBtn = document.getElementById('saveYtSettingsBtn');
    errorEl.classList.remove('show');
    const apiKey = document.getElementById('ytApiKeyInput').value.trim();
    if (!apiKey) { errorEl.textContent = 'يرجى إدخال مفتاح API لحفظه'; errorEl.classList.add('show'); return; }
    saveBtn.disabled = true; saveBtn.innerHTML = '<span class="spinner"></span> جاري الحفظ...';
    try {
      await App.api.saveYoutubeSettings({ apiKey });
      document.getElementById('ytApiKeyInput').value = '';
      App.ui.toast('تم حفظ إعدادات YouTube بنجاح', 'ok'); loadYtStatus();
    } catch (err) { errorEl.textContent = err.message; errorEl.classList.add('show'); }
    finally { saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fas fa-floppy-disk"></i> حفظ الإعدادات'; }
  });
  document.getElementById('testYtConnBtn').addEventListener('click', async () => {
    const btn = document.getElementById('testYtConnBtn');
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> جاري الاختبار...';
    try { await App.api.testYoutubeConnection(); App.ui.toast('الاتصال ناجح! المفتاح يعمل بشكل صحيح ✅', 'ok'); }
    catch (err) { App.ui.toast(err.message, 'err'); }
    finally { btn.disabled = false; btn.innerHTML = '<i class="fas fa-plug"></i> اختبار الاتصال'; }
  });
};

App.views.account = async function account() {
  const view = document.getElementById('view');
  const user = App.state.getUser();
  const isAdmin = App.state.isAdmin();
  view.innerHTML = `
    <div class="an">
      <div class="page-title"><i class="fas fa-user" style="color:var(--cyan)"></i> حسابي</div>
      <div class="profile-card" style="margin-top:10px">
        <div class="profile-row"><div class="profile-av">${App.ui.initials(user.name)}</div><div class="profile-info"><h2>${App.ui.escapeHtml(user.name)}</h2><p>${App.ui.escapeHtml(user.email)}</p></div></div>
        <div class="profile-stats">
          <div class="profile-stat"><b>${user.pointsTotal || 0}</b><span>مجموع النقاط</span></div>
          <div class="profile-stat"><b>${isAdmin ? 'أدمن' : 'طالب'}</b><span>نوع الحساب</span></div>
        </div>
      </div>

      <div class="section-head" style="margin-top:22px"><h3><i class="fas fa-sliders"></i> بيانات الدراسة</h3></div>
      <div class="card">
        <div class="list-row" style="cursor:pointer" id="changeStageRow">
          <div class="list-ico"><i class="fas fa-graduation-cap"></i></div>
          <div class="list-body"><h4>تغيير المحافظة / المرحلة / الصف</h4><p>يعيد فتح شاشة الاختيار</p></div>
          <i class="fas fa-chevron-left" style="color:var(--muted)"></i>
        </div>
      </div>

      <div class="section-head" style="margin-top:22px"><h3><i class="fas fa-circle-info"></i> تعليمات حول التطبيق</h3></div>
      <div class="card" style="font-size:.8rem;line-height:2;color:var(--muted)">
        <p><b style="color:var(--text)">١. اختيار المرحلة:</b> من صفحة الاختيار تحدّد محافظتك، ثم مرحلتك الدراسية، ثم صفك — تظهر بعدها المواد الخاصة بصفّك فقط.</p>
        <p><b style="color:var(--text)">٢. المسار داخل كل مادة:</b> تختار مدرّساً، ثم وحدة دراسية، ثم محاضرة. كل محاضرة تحتوي فيديو مضمَّن داخل الموقع مباشرة.</p>
        <p><b style="color:var(--text)">٣. النقاط:</b> تربح نقاطاً عند إكمال مشاهدة محاضرة وعند الإجابة الصحيحة في الاختبارات، وتظهر في قسم "المتصدرون ونقاطي".</p>
        <p><b style="color:var(--text)">٤. الاختبارات:</b> بعد كل محاضرة يمكنك بدء اختبار قصير يولّده الذكاء الاصطناعي، مع شرح فوري لكل إجابة.</p>
        <p><b style="color:var(--text)">٥. المساعد الذكي:</b> داخل كل محاضرة زر "المساعد الذكي" يجيب على أسئلتك ويلخّص تلك المحاضرة تحديداً.</p>
      </div>

      <div id="adminSettingsHolder"></div>

      <button class="btn btn-outline btn-block" id="accountLogoutBtn" style="margin-top:26px;border-color:var(--danger);color:var(--danger)"><i class="fas fa-arrow-right-from-bracket"></i> تسجيل الخروج</button>
    </div>
  `;
  document.getElementById('changeStageRow').addEventListener('click', () => { location.hash = '#/onboarding'; });
  document.getElementById('accountLogoutBtn').addEventListener('click', () => App.main.openLogoutSheet());
  if (isAdmin) {
    const holder = document.getElementById('adminSettingsHolder');
    holder.innerHTML = '<div class="section-head" style="margin-top:22px"><h3><i class="fas fa-user-shield"></i> إعدادات الأدمن</h3></div><div id="adminSettingsBody"></div>';
    App.views.renderAiSettings(document.getElementById('adminSettingsBody'));
  }
};

App.main = (function () {
  async function loadNotifications(markSeen) {
    const list = document.getElementById('notifList');
    const dot = document.getElementById('notifDot');
    try {
      const { history } = await App.api.getMyPoints();
      const toTime = (s) => new Date(String(s).replace(' ', 'T') + 'Z').getTime();
      const seenAt = new Date(App.state.getNotifSeenAt()).getTime();
      if (dot) dot.hidden = !history.some((h) => toTime(h.created_at) > seenAt);
      if (list) {
        list.innerHTML = history.length
          ? history.slice(0, 20).map((h) => `
            <div class="list-row" style="cursor:default">
              <div class="list-ico ${h.points > 0 ? '' : 'done'}"><i class="fas ${h.points > 0 ? 'fa-plus' : 'fa-minus'}"></i></div>
              <div class="list-body"><h4>${App.ui.escapeHtml(App.config.POINTS_LABELS[h.reason] || h.reason)}</h4><p>${App.ui.formatDate(h.created_at)}</p></div>
              <span class="chip ${h.points > 0 ? 'chip-done' : ''}">${h.points > 0 ? '+' : ''}${h.points}</span>
            </div>`).join('')
          : '<div class="notif-empty">لا توجد إشعارات بعد</div>';
      }
      if (markSeen) { App.state.markNotifSeen(); if (dot) dot.hidden = true; }
    } catch (err) { /* الإشعارات ليست حرجة؛ فشل جلبها لا يجب أن يعطّل الواجهة */ }
  }
  function refreshHeader() {
    const user = App.state.getUser();
    const hdrRight = document.getElementById('hdrRight');
    const bottomNav = document.getElementById('bottomNav');
    if (user) {
      bottomNav.hidden = false;
      hdrRight.hidden = false;
      loadNotifications(false);
    } else {
      bottomNav.hidden = true;
      hdrRight.hidden = true;
    }
  }
  function highlightNav(path) {
    const homeLike = path === '/home' || path === '/onboarding' || path.startsWith('/subject/') || path.startsWith('/teacher/') || path.startsWith('/unit/') || path.startsWith('/lecture/') || path.startsWith('/quiz/');
    document.querySelectorAll('.bn-item[data-nav]').forEach((item) => {
      const target = item.dataset.nav.replace('#', '');
      const active = target === '/home' ? homeLike : target === path;
      item.classList.toggle('active', active);
    });
  }
  function openLogoutSheet() {
    document.getElementById('sheetOverlay').classList.add('open');
    document.getElementById('logoutSheet').classList.add('open');
  }
  function closeLogoutSheet() {
    document.getElementById('sheetOverlay').classList.remove('open');
    document.getElementById('logoutSheet').classList.remove('open');
  }
  function wireStaticHeader() {
    document.querySelectorAll('#bottomNav [data-nav]').forEach((el) => {
      el.addEventListener('click', () => { location.hash = el.dataset.nav; });
    });
    document.getElementById('notifBtn').addEventListener('click', (e) => {
      e.stopPropagation();
      const panel = document.getElementById('notifPanel');
      const willOpen = !panel.classList.contains('open');
      panel.classList.toggle('open');
      if (willOpen) loadNotifications(true);
    });
    document.addEventListener('click', (e) => {
      const panel = document.getElementById('notifPanel');
      if (panel && !panel.contains(e.target) && e.target.id !== 'notifBtn' && !document.getElementById('notifBtn').contains(e.target)) {
        panel.classList.remove('open');
      }
    });
    document.getElementById('sheetOverlay').addEventListener('click', closeLogoutSheet);
    document.getElementById('cancelLogoutBtn').addEventListener('click', closeLogoutSheet);
    document.getElementById('confirmLogoutBtn').addEventListener('click', () => {
      closeLogoutSheet();
      App.state.clearSession(); App.state.clearSelection(); refreshHeader(); App.ui.toast('تم تسجيل الخروج'); location.hash = '#/login'; App.router.resolve();
    });
  }
  function registerRoutes() {
    App.router.register('/welcome', App.views.welcome, { noChrome: true });
    App.router.register('/login', App.views.login, { noChrome: true });
    App.router.register('/register', App.views.register, { noChrome: true });
    App.router.register('/onboarding', App.views.onboarding, { requiresAuth: true });
    App.router.register('/home', App.views.home, { requiresAuth: true });
    App.router.register('/subject/:id', App.views.subjectTeachers, { requiresAuth: true });
    App.router.register('/teacher/:id', App.views.teacherUnits, { requiresAuth: true });
    App.router.register('/unit/:id', App.views.unitLectures, { requiresAuth: true });
    App.router.register('/lecture/:id', App.views.lecture, { requiresAuth: true });
    App.router.register('/quiz/:lectureId', App.views.quiz, { requiresAuth: true });
    App.router.register('/tutor/:lectureId', App.views.tutorPage, { requiresAuth: true });
    App.router.register('/leaderboard', App.views.leaderboard, { requiresAuth: true });
    App.router.register('/account', App.views.account, { requiresAuth: true });
  }
  async function init() {
    wireStaticHeader(); refreshHeader(); registerRoutes(); App.router.start();
    if (App.state.isLoggedIn()) {
      try { const { user } = await App.api.me(); App.state.updateUser(user); refreshHeader(); }
      catch (err) { if (err.status === 401) { App.state.clearSession(); refreshHeader(); } }
    }
  }
  return { init, refreshHeader, highlightNav, openLogoutSheet };
})();

App.main.init();
</script>
</body>
</html>
ZAKI_STANDALONE_HTML;
