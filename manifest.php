<?php
require __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/manifest+json; charset=utf-8');

$siteName = getSetting($pdo, 'site_name', 'استضافتي');
$siteLogo = getSetting($pdo, 'site_logo', '');

// السبب المتكرر وراء ظهور أيقونة تثبيت مختلفة عن شعار الموقع رغم أنه نفس
// الإعداد في لوحة التحكم: كانت الأيقونة المرفوعة تُعلَن دائماً بحجم "512x512"
// وهمي بغض النظر عن أبعادها الحقيقية - وأي خلل بين الحجم المُعلَن والحقيقي
// يجعل Chrome يرفض الأيقونة بصمت ويستخدم الأيقونة الافتراضية المرفقة بدلاً
// منها. الحل: نقرأ الأبعاد الحقيقية فعلياً، ولا نعرض أيقونتي الاحتياط الثابتتين
// إطلاقاً إن وُجد شعار مرفوع (فلا يبقى أمام Chrome خيار آخر ليفضّله عليه)،
// وتبقى purpose "any" فقط لأن الشعار مربّع غالباً بلا هامش أمان للقص الدائري
// (maskable) - وسمه بذلك كان يجعل أندرويد يقصّ حوافه ويُظهره مشوّهاً.
$icons = [];
if ($siteLogo !== '') {
    $dims = @getimagesize(BASE_DIR . '/' . $siteLogo);
    if ($dims) {
        $icons[] = ['src' => $siteLogo, 'sizes' => $dims[0] . 'x' . $dims[1], 'type' => $dims['mime'] ?? 'image/png', 'purpose' => 'any'];
    }
}
if (!$icons) {
    $icons[] = ['src' => 'assets/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any maskable'];
    $icons[] = ['src' => 'assets/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any maskable'];
}

echo json_encode([
    'name' => $siteName,
    'short_name' => $siteName,
    'description' => getSetting($pdo, 'site_tagline', 'استضافة VPS سريعة وآمنة'),
    'start_url' => 'index.php?app=1',
    'scope' => '.',
    'display' => 'standalone',
    'orientation' => 'portrait',
    'dir' => 'rtl',
    'lang' => 'ar',
    'background_color' => '#f7f4f0',
    'theme_color' => '#ff7a1a',
    'icons' => $icons,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
