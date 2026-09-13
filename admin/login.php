<?php
require_once __DIR__ . '/includes/bootstrap.php';

// لا توجد صفحة تسجيل دخول منفصلة خاصة بلوحة التحكم - تسجيل الدخول موحّد بالكامل عبر
// الموقع الرئيسي (نفس الجلسة وحساب المستخدم نفسه). من كان مسجَّلاً بالفعل وحسابه مدير
// يدخل مباشرة؛ غير ذلك يُعاد توجيهه لصفحة الدخول الرئيسية وتُعيده تلقائياً هنا بعد الدخول.
if (isAdmin()) {
    header('Location: index.php');
} else {
    $reason = isLoggedIn() ? 'not_admin' : 'login';
    header('Location: ../index.php?admin_redirect=' . $reason);
}
exit;
