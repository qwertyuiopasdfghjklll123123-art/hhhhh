<?php
require_once __DIR__ . '/includes/bootstrap.php';
admin_require_login();

// نسخة احتياطية كاملة قابلة لإعادة الاستيراد فوراً (نفس صيغة database.json القديمة)،
// بما في ذلك محتوى الصور الفعلي كـ base64 مضمّن، وليس فقط أسماء الملفات
ini_set('memory_limit', '512M');

$data = db_export_full_json($pdo);

$filename = 'almulla_export_' . date('Y-m-d_His') . '.json';
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
