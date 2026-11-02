<?php
require_once __DIR__ . '/includes/bootstrap.php';
admin_require_login();

// نسخة احتياطية كاملة قابلة لإعادة الاستيراد فوراً (نفس صيغة database.json القديمة)،
// بما في ذلك محتوى الصور الفعلي كـ base64 مضمّن، وليس فقط أسماء الملفات. خيار ?images=0
// يستثني محتوى الصور (نسخة نصية خفيفة فقط) لتفادي حدود الحجم الشائعة على الاستضافات
// المشتركة (max_allowed_packet على خادم MySQL، أو حد ذاكرة/وقت تنفيذ PHP) عند تصدير صور
// مخزَّنة كبيانات BLOB كبيرة - استخدمه إن توقف تحميل النسخة الكاملة أو ظهر خطأ أثناءه.
//
// الناتج يُكتَب تدريجياً (بث) بدل بنائه كمصفوفة ضخمة في الذاكرة ثم ترميزها لنص واحد -
// ضروري لكتالوجات بمئات الصور الحقيقية المخزَّنة كـ BLOB حيث قد يتجاوز بناء كل شيء دفعة
// واحدة حدود الذاكرة، أو يتسبب صمت طويل قبل إرسال أي بيانات بانقطاع عند خادم وسيط (nginx
// reverse proxy شائع جداً على الاستضافات المشتركة) قبل اكتمال التصدير.
ini_set('memory_limit', '1024M');
set_time_limit(0);
header('X-Accel-Buffering: no'); // يمنع nginx (إن استُخدم كـ reverse proxy) من تخزين الاستجابة مؤقتاً قبل إرسالها

$includeImages = ($_GET['images'] ?? '1') !== '0';

$suffix = $includeImages ? '' : '_no_images';
$filename = 'almulla_export_' . date('Y-m-d_His') . $suffix . '.json';
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
db_stream_export_full_json($pdo, $includeImages);
