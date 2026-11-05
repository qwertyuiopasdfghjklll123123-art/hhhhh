<?php
/**
 * سكربت التثبيت: يتحقق من وجود ملف sql/schema.sql، ينشئ قاعدة البيانات
 * إن لم تكن موجودة، ثم يستورد كل الجداول والبيانات الأولية منه.
 *
 * التشغيل من الترمنال (الأفضل):
 *   php install.php
 * أو من المتصفح (احذفه بعد الانتهاء لأسباب أمنية):
 *   https://yourdomain.com/php/install.php
 */

require_once __DIR__ . '/config.php';

$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
}

function out($line) {
    echo $line . "\n";
}

out('========================================');
out(' تثبيت قاعدة بيانات fastcrand (PHP/MySQL)');
out('========================================');

// 1) تحقق من وجود ملف الشيما فعلاً قبل أي شيء
if (!file_exists(SCHEMA_SQL_PATH)) {
    out("❌ لم يتم العثور على ملف الشيما: " . SCHEMA_SQL_PATH);
    out('تأكد أن مجلد php/sql/schema.sql موجود بجانب هذا السكربت.');
    exit(1);
}
out('✅ ملف الشيما موجود: ' . SCHEMA_SQL_PATH . ' (' . filesize(SCHEMA_SQL_PATH) . ' بايت)');

$sql = file_get_contents(SCHEMA_SQL_PATH);
if ($sql === false || trim($sql) === '') {
    out('❌ ملف الشيما موجود لكنه فاضي أو تعذّرت قراءته.');
    exit(1);
}

// 2) الاتصال بسيرفر MySQL (بدون اختيار قاعدة بيانات بعد) وإنشاؤها إذا ما كانت موجودة
try {
    $root = new PDO('mysql:host=' . DB_HOST . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $root->exec("CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    out('✅ قاعدة البيانات `' . DB_NAME . '` جاهزة.');
} catch (PDOException $e) {
    out('❌ فشل الاتصال بسيرفر MySQL أو إنشاء قاعدة البيانات: ' . $e->getMessage());
    out('تحقق من DB_HOST / DB_USER / DB_PASS داخل config.php.');
    exit(1);
}

// 3) الاتصال بالقاعدة نفسها واستيراد الشيما
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    out('❌ فشل الاتصال بقاعدة البيانات ' . DB_NAME . ': ' . $e->getMessage());
    exit(1);
}

$statements = split_sql_statements($sql);
out('سيتم تنفيذ ' . count($statements) . ' جملة SQL...');

// نفصل جمل إنشاء الجداول (آمنة التكرار بفضل IF NOT EXISTS) عن جمل إدخال
// البيانات الأولية (seed). عدة جداول seed (levels, providers, payment_methods)
// بدون مفتاح فريد طبيعي، فإعادة تنفيذها بعد تثبيت ناجح سابقاً يكرّر الصفوف.
// الحل: نشغّل كل جمل الإنشاء دوماً، ونشغّل جمل الإدخال فقط إذا كانت القاعدة
// فعلاً جديدة (لا يوجد أي مستخدم بعد).
$ddl = [];
$seed = [];
foreach ($statements as $stmt) {
    if (stripos(ltrim($stmt), 'INSERT') === 0) {
        $seed[] = $stmt;
    } else {
        $ddl[] = $stmt;
    }
}

$alreadySeeded = false;
try {
    $check = $pdo->query('SELECT COUNT(*) AS c FROM users')->fetch();
    $alreadySeeded = $check && (int)$check['c'] > 0;
} catch (PDOException $e) {
    $alreadySeeded = false; // جدول users نفسه غير موجود بعد (أول تشغيل)
}

$ok = 0; $skipped = 0; $failed = 0;

foreach ($ddl as $stmt) {
    $stmt = trim($stmt);
    if ($stmt === '') continue;
    try {
        $pdo->exec($stmt);
        $ok++;
    } catch (PDOException $e) {
        $failed++;
        out('⚠️  فشلت جملة: ' . substr(str_replace("\n", ' ', $stmt), 0, 100) . '...');
        out('    السبب: ' . $e->getMessage());
    }
}

if ($alreadySeeded) {
    $seedCount = count(array_filter($seed, fn($s) => trim($s) !== ''));
    $skipped += $seedCount;
    out("ℹ️  البيانات الأولية (seed) موجودة مسبقاً — تم تخطّي $seedCount جملة إدخال لتفادي التكرار.");
} else {
    foreach ($seed as $stmt) {
        $stmt = trim($stmt);
        if ($stmt === '') continue;
        try {
            $pdo->exec($stmt);
            $ok++;
        } catch (PDOException $e) {
            if ($e->getCode() == 23000) {
                $skipped++;
                continue;
            }
            $failed++;
            out('⚠️  فشلت جملة: ' . substr(str_replace("\n", ' ', $stmt), 0, 100) . '...');
            out('    السبب: ' . $e->getMessage());
        }
    }
}

out('----------------------------------------');
out("منفّذة بنجاح: $ok | متخطّاة (موجودة مسبقاً): $skipped | فاشلة: $failed");
if ($failed === 0) {
    out('✅ التثبيت تم بنجاح. عدّل config.php إذا لم تكن قد وضعت بيانات MySQL الحقيقية بعد.');
    out('⚠️  احذف هذا الملف (install.php) من السيرفر الآن لأسباب أمنية.');
} else {
    out('❌ فيه جمل فشلت، راجع الرسائل أعلاه.');
}

/**
 * يقسّم محتوى ملف sql إلى جمل منفصلة عند ';' خارج أي نص بين علامتي اقتباس،
 * لأن PDO لا ينفّذ عدة جمل دفعة واحدة بشكل مضمون.
 */
function split_sql_statements($sql) {
    $statements = [];
    $buf = '';
    $inSingle = false;
    $inDouble = false;
    $len = strlen($sql);
    for ($i = 0; $i < $len; $i++) {
        $ch = $sql[$i];
        $buf .= $ch;
        if ($ch === "'" && !$inDouble) {
            // تجاهل علامة اقتباس مهروبة بـ backslash
            if ($i === 0 || $sql[$i - 1] !== '\\') {
                $inSingle = !$inSingle;
            }
        } elseif ($ch === '"' && !$inSingle) {
            if ($i === 0 || $sql[$i - 1] !== '\\') {
                $inDouble = !$inDouble;
            }
        } elseif ($ch === ';' && !$inSingle && !$inDouble) {
            $statements[] = $buf;
            $buf = '';
        }
    }
    if (trim($buf) !== '') $statements[] = $buf;
    // إزالة الأسطر التي هي تعليقات فقط (-- ...) من كل جملة لتفادي اللخبطة، مع الحفاظ على باقي الجملة
    return array_map(function ($stmt) {
        $lines = explode("\n", $stmt);
        $lines = array_filter($lines, function ($l) {
            return strpos(ltrim($l), '--') !== 0;
        });
        return implode("\n", $lines);
    }, $statements);
}
