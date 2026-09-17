<?php
/**
 * انسخ هذا الملف إلى config.php فقط إذا كنت تفضّل التثبيت اليدوي.
 * في الوضع الطبيعي، صفحة install.php تُنشئ config/config.php تلقائياً
 * ولا تحتاج لمساس هذا الملف إطلاقاً.
 *
 * ملف config/config.php الفعلي غير موجود في المستودع (.gitignore) لأنه
 * يحتوي على بيانات اتصال قاعدة البيانات ومفتاح التشفير الخاص بالتطبيق.
 */
return [
    'db' => [
        'host'    => '127.0.0.1',
        'port'    => '3306',
        'name'    => 'pm_dashboard',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name'     => 'لوحة إدارة المشاريع',
        'url'      => 'http://localhost',
        // مفتاح عشوائي بطول 32 بايت مُرمَّز بصيغة base64، يُستخدم لتشفير
        // بيانات GitHub Token و NVIDIA API Key داخل قاعدة البيانات (AES-256-GCM).
        // ولّد مفتاحاً جديداً بالأمر: php -r "echo 'base64:'.base64_encode(random_bytes(32));"
        'key'      => 'base64:REPLACE_WITH_32_RANDOM_BYTES_BASE64',
        'debug'    => false,
        'timezone' => 'Asia/Baghdad',
    ],
];
