<?php
/**
 * ملف الإعدادات الوحيد الذي تعدّله. انسخه إلى core/config.php واكتب قيمك.
 */

// -------------------- قاعدة البيانات --------------------
define('DB_HOST', 'localhost');           // غالباً localhost
define('DB_PORT', 3306);
define('DB_NAME', 'YOUR_DB_NAME');        // اسم القاعدة كما يظهر بلوحة الاستضافة
define('DB_USER', 'YOUR_DB_USER');
define('DB_PASS', 'YOUR_DB_PASSWORD');

// -------------------- الدومين --------------------
define('DOMAIN', 'example.com');          // دومينك بدون https://

// مفتاح سرّي يحمي جلسات الدخول والتوقيع - عدّله لنص عشوائي طويل قبل الإطلاق.
// تنبيه: لو كان لديك موقع واصل القديم (بايثون/Flask) وتريد بقاء كل المستخدمين مسجّلين
// الدخول بلا الحاجة لإعادة تسجيل الدخول بعد النقل لـ PHP، ضع هنا نفس قيمة secret_key
// المستعملة بـ webapp.py (كوكي الجلسة متوافق حرفياً بين الاثنين). غيّره بعد استقرار النقل.
define('SECRET_KEY', 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET');

// -------------------- حساب الأدمن (يُنشأ تلقائياً بأول زيارة لو ما فيه أدمن) --------------------
define('ADMIN_EMAIL', '');                // بريدك، مثال: me@example.com
define('ADMIN_PASSWORD', '');             // 8 محارف فأكثر - احذفه من هذا الملف بعد أول دخول
define('ADMIN_NAME', 'الأدمن');

// -------------------- تسجيل الدخول بحساب أدمن ثابت (احتياطي بدون كلمة مرور) --------------------
// يدخل مباشرة برقم واتساب + رمز ثابتين من صفحة الدخول العادية (بدون رمز تحقق حقيقي ولا
// حساب واتساب متصل). اتركه فاضياً لتعطيل هذه الميزة كلياً (مستحسن بعد تثبيت تسجيل الدخول
// الحقيقي - واتساب/Google). كان هذا ثابتاً بالكود المصدري بنسخة بايثون (خطر أمني: أي أحد
// يشوف الكود يدخل بهذا الحساب) - هنا صار اختيارياً وسرّه بهذا الملف فقط، مثل ADMIN_PASSWORD.
define('MASTER_ADMIN_PHONE', '');
define('MASTER_ADMIN_CODE', '');

// -------------------- تسجيل الدخول بجوجل (اختياري) --------------------
// Google Cloud Console → APIs & Services → Credentials → Create OAuth client ID
// (Web application) → Authorized JavaScript origins: https://YOUR_DOMAIN
define('GOOGLE_CLIENT_ID', '');           // اتركه فاضياً لتعطيل زر «الدخول بجوجل»

// -------------------- مزوّد إرسال رسائل واتساب (الأكواد + الحملات) --------------------
// shared hosting ما تدعم تشغيل متصفح Chrome/Selenium (أتمتة واتساب ويب المباشرة)، فالإرسال
// يصير عبر مزوّد واتساب خارجي (API). اختر driver واحداً واكتب بياناته - راجع ملف
// core/lib/wa_provider.php للتفاصيل والمزوّدين المدعومين.
define('WA_PROVIDER_DRIVER', 'none');     // 'none' (معطّل مؤقتاً) | 'cloud_api' (Meta الرسمي) | 'ultramsg' | 'http_generic'
define('WA_PROVIDER_CONFIG', [
    // أمثلة حسب WA_PROVIDER_DRIVER المختار - راجع core/lib/wa_provider.php
    // cloud_api:   'phone_number_id' => '...', 'access_token' => '...'
    // ultramsg:    'instance_id' => '...', 'token' => '...'
    // http_generic:'send_url' => '...', 'headers' => [...]
]);

// -------------------- خطة الاشتراك --------------------
define('PLAN_NAME', 'الخطة الاحترافية');
define('PLAN_PRICE_IQD', 60000);
define('TRIAL_DAYS', 3);
define('PLAN_ACTIVATION_DAYS', 30);
define('WHATSAPP_PAY_NUMBER', '');        // رقم واتساب لتفعيل الاشتراك

// -------------------- اختيارية --------------------
// define('ADMIN_IP_ALLOWLIST', ['203.0.113.5', '198.51.100.0/24']);
// define('TRUSTED_PROXIES', ['127.0.0.1']);
// define('LOGIN_MAX_FAILS', 8);
// define('LOGIN_MAX_FAILS_IP', 40);
// define('LOGIN_WINDOW', 900);
// define('CODE_GUESS_MAX', 6);
// define('HSTS_ENABLE', true);
