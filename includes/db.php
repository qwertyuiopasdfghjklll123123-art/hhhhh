<?php
// صفحة خطأ واضحة بدل شاشة بيضاء فارغة (display_errors مُعطَّل أمنياً في كل نقاط الدخول)، حتى
// يمكن معرفة سبب تعطّل الموقع فوراً بدل تخمينه - تُستخدم هنا فقط، عند فشل الاتصال تحديداً
function dbFatalErrorPage(string $message): void {
    http_response_code(500);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="ar" dir="rtl"><head><meta charset="UTF-8">'
        . '<title>تعذّر الاتصال بقاعدة البيانات</title></head>'
        . '<body style="font-family:Tahoma,Arial,sans-serif;max-width:640px;margin:60px auto;padding:0 20px;line-height:1.8;color:#222;">'
        . '<h2 style="color:#c0392b;">⚠️ تعذّر الاتصال بقاعدة البيانات</h2>'
        . '<p>' . $message . '</p>'
        . '</body></html>';
    exit;
}

function getDb(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER')) {
        throw new RuntimeException('إعدادات قاعدة البيانات غير موجودة');
    }

    if (!extension_loaded('pdo_mysql')) {
        dbFatalErrorPage(
            'امتداد PHP المطلوب (<code>pdo_mysql</code>) غير مُفعَّل على هذه الاستضافة. '
            . 'الحل غالباً خطوة بسيطة من لوحة تحكم الاستضافة: '
            . '<b>Select PHP Version ← Extensions ← فعّل pdo_mysql</b> (في cPanel أو ما يعادلها)، '
            . 'ثم أعد تحميل هذه الصفحة. إن لم تجد هذا الخيار، تواصل مع دعم الاستضافة واطلب '
            . 'تفعيل امتداد <code>pdo_mysql</code> لحسابك.'
        );
    }

    $port = defined('DB_PORT') && DB_PORT ? DB_PORT : 3306;
    $charset = defined('DB_CHARSET') && DB_CHARSET ? DB_CHARSET : 'utf8mb4';
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . $port . ';dbname=' . DB_NAME . ';charset=' . $charset;

    try {
        $pdo = new PDO($dsn, DB_USER, defined('DB_PASS') ? DB_PASS : '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        error_log('Almulla DB connection failed: ' . $e->getMessage());
        dbFatalErrorPage(
            'تعذّر الاتصال بقاعدة البيانات. الأسباب الشائعة: '
            . '<ul style="text-align:right;">'
            . '<li>بيانات الاتصال في <code>config.php</code> (اسم المستخدم/كلمة المرور/اسم القاعدة) غير صحيحة - '
            . 'تحقق منها في لوحة تحكم الاستضافة تحت MySQL Databases.</li>'
            . '<li>خادم قاعدة البيانات متوقف مؤقتاً على الاستضافة - تحقق من حالة MySQL في لوحة التحكم أو راسل الدعم الفني.</li>'
            . '<li>قاعدة البيانات نفسها حُذفت أو أُعيدت تسميتها.</li>'
            . '</ul>'
            . 'تفاصيل الخطأ الفنية الكاملة مسجَّلة في ملف سجل أخطاء PHP على استضافتك (error_log) لمراجعتها مع الدعم الفني إن احتجت.'
        );
    }

    return $pdo;
}
