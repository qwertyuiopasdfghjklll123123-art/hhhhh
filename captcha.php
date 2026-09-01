<?php
// ============================================================
// توليد صورة كود التحقق (CAPTCHA) لصفحة إنشاء الحساب
// صورة وليست نصاً، لذا لا يمكن تحديدها أو نسخها كنص
// ============================================================
session_start();

$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // بدون رموز متشابهة الشكل: 0/O، 1/I/L
$code = '';
for ($i = 0; $i < 5; $i++) {
    $code .= $chars[random_int(0, strlen($chars) - 1)];
}
$_SESSION['captcha_code'] = $code;

$width = 160;
$height = 60;
$bgR = 247; $bgG = 244; $bgB = 240;

$image = imagecreatetruecolor($width, $height);
$bg = imagecolorallocate($image, $bgR, $bgG, $bgB);
imagefill($image, 0, 0, $bg);

for ($i = 0; $i < 5; $i++) {
    $lineColor = imagecolorallocate($image, random_int(205, 228), random_int(205, 228), random_int(205, 228));
    imageline($image, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $lineColor);
}
for ($i = 0; $i < 70; $i++) {
    $dotColor = imagecolorallocate($image, random_int(195, 222), random_int(195, 222), random_int(195, 222));
    imagesetpixel($image, random_int(0, $width - 1), random_int(0, $height - 1), $dotColor);
}

$cellWidth = intdiv($width - 20, strlen($code));
for ($i = 0; $i < strlen($code); $i++) {
    $charCanvas = imagecreatetruecolor(30, 34);
    $charBg = imagecolorallocate($charCanvas, $bgR, $bgG, $bgB);
    imagefill($charCanvas, 0, 0, $charBg);
    $textColor = imagecolorallocate($charCanvas, random_int(20, 90), random_int(30, 70), random_int(100, 170));
    imagestring($charCanvas, 5, 7, 9, $code[$i], $textColor);

    $rotated = imagerotate($charCanvas, random_int(-28, 28), $charBg);

    $destX = 10 + $i * $cellWidth + random_int(-3, 3);
    $destY = intdiv($height - imagesy($rotated), 2) + random_int(-4, 4);
    imagecopy($image, $rotated, $destX, $destY, 0, 0, imagesx($rotated), imagesy($rotated));
    imagedestroy($charCanvas);
    imagedestroy($rotated);
}

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
imagepng($image);
imagedestroy($image);
