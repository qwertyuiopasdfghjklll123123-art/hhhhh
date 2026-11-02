<?php
require_once __DIR__ . '/includes/bootstrap.php';
admin_require_login();

// نسخة احتياطية كاملة قابلة لإعادة الاستيراد فوراً (نفس صيغة database.json القديمة)،
// بما في ذلك محتوى الصور الفعلي كـ base64 مضمّن، وليس فقط أسماء الملفات. خيار ?images=0
// يستثني محتوى الصور (نسخة نصية خفيفة فقط) لتفادي حدود الحجم الشائعة على الاستضافات
// المشتركة (max_allowed_packet على خادم MySQL، أو حد ذاكرة/وقت تنفيذ PHP) عند تصدير صور
// مخزَّنة كبيانات BLOB كبيرة
ini_set('memory_limit', '512M');

$includeImages = ($_GET['images'] ?? '1') !== '0';
$data = db_export_full_json($pdo, $includeImages);

$suffix = $includeImages ? '' : '_no_images';
$filename = 'almulla_export_' . date('Y-m-d_His') . $suffix . '.json';
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
