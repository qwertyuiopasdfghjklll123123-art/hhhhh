<?php
/**
 * إعدادات الخادم — انسخ هذا الملف إلى config.php ثم عدّل القيم
 * (لا يُرفع config.php الحقيقي لأي مكان عام؛ ملف .htaccess في هذا المجلد يمنع الوصول إليه مباشرة أصلاً)
 */
return [
    // بيانات قاعدة البيانات كما تظهر في cPanel > MySQL Databases
    // عادة يكون الاسم والمستخدم بصيغة: اسم_المستخدم_في_cpanel_اسم_تختاره
    'db' => [
        'host' => 'localhost',
        'name' => 'cpaneluser_elearning',
        'user' => 'cpaneluser_elearning',
        'pass' => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],

    // أي نص عشوائي طويل تختاره بنفسك (يُستخدم لتوقيع جلسات الدخول)
    'jwt_secret' => 'change_this_to_a_long_random_string',
    'jwt_expires_in_days' => 7,

    // أي نص عشوائي طويل آخر (يُستخدم لتشفير مفتاح DeepSeek قبل تخزينه في قاعدة البيانات)
    'settings_encryption_key' => 'change_this_to_another_long_random_passphrase',

    // احتياطي فقط: القيمة الفعلية تُدار من صفحة الإعدادات (Settings) داخل الموقع بعد تسجيل دخول الأدمن
    'deepseek' => [
        'api_key' => '',
        'base_url' => 'https://api.deepseek.com',
        'model' => 'deepseek-chat',
    ],

    // اختياري: للتحقق الفعلي من روابط يوتيوب عبر YouTube Data API
    'youtube_api_key' => '',
];
