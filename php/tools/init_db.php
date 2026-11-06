<?php
/**
 * تنفيذ sql/database.sql من سطر الأوامر (بديل استيراده من phpMyAdmin) ثم إنشاء حساب الأدمن من config.php:
 *     php tools/init_db.php
 * آمن عند التكرار (لا يمسح شيئاً ولا يكرر البيانات الافتراضية).
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require __DIR__ . '/../core/bootstrap.php';
init_db();
Conn::shutdown();
echo "تم: الجداول والبيانات الافتراضية جاهزة\n";
