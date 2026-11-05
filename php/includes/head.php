<?php
/**
 * رأس HTML مشترك — نفس الخطوط وأيقونات Font Awesome المستخدمة بالنسخة
 * الأصلية (DASH_HTML/LANDING_HTML في app.py)، بدل إعادة كتابتها بكل صفحة.
 *
 * الاستخدام: عرّف $pageTitle و (اختياري) $assetBase ('' أو '../') قبل الإدراج.
 */
$assetBase = $assetBase ?? '';
$pageTitle = $pageTitle ?? 'fastcrand';
?>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= htmlspecialchars($pageTitle) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800;900&family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="<?= htmlspecialchars($assetBase) ?>assets/style.css">
<script>
(function(){
  try {
    var t = localStorage.getItem('fc_theme');
    if (t) document.documentElement.setAttribute('data-theme', t);
  } catch (e) {}
})();
</script>
