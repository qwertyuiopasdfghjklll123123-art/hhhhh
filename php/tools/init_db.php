<?php
/**
 * إنشاء الجداول والبيانات الافتراضية من سطر الأوامر (بديل install.php):
 *     php tools/init_db.php
 * آمن عند التكرار.
 */
require __DIR__ . '/../app/bootstrap.php';
init_db();
Conn::shutdown();
echo "تم: الجداول والبيانات الافتراضية جاهزة\n";
