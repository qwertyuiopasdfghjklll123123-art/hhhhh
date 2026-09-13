<?php
// ========== دوال المصادقة والصلاحيات ==========
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
}

function requireAuth() {
    if (!isLoggedIn()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'يرجى تسجيل الدخول أولاً']);
        exit;
    }
}

function requireAdmin() {
    if (!isAdmin()) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'غير مصرح بهذا الإجراء. صلاحيات مدير مطلوبة']);
        exit;
    }
}

function verifyCsrfToken($token) {
    if (!isset($_SESSION['csrf_token']) || !is_string($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        header('Content-Type: application/json');
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'طلب غير مصرح به (CSRF)']);
        exit;
    }
    return true;
}

// ========== السجلات ==========
function writeLog($message, $type = 'INFO') {
    $logDir = __DIR__ . '/../logs';
    if (!file_exists($logDir)) @mkdir($logDir, 0755, true);
    $logFile = $logDir . '/app_' . date('Y-m-d') . '.log';
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . "] [$type] $message" . PHP_EOL, FILE_APPEND);
}

// ========== دوال معالجة الصور ==========
// كل الصور تُخزَّن كبيانات ثنائية (BLOB) داخل MySQL نفسها، وليس كملفات في uploads/.
// كل دالة هنا تتحقق من أن المحتوى صورة حقيقية فعلاً (وليس فقط الترويسة/الامتداد المُعلن)
// ثم تُرجع ['data' => ..., 'mime' => ...] جاهزة للتخزين المباشر، أو null إن كانت غير صالحة.

const ALLOWED_IMAGE_MIME_TO_EXT = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];

function imageMimeToExt(string $mime): string {
    return ALLOWED_IMAGE_MIME_TO_EXT[$mime] ?? 'jpg';
}

// اسم تعريفي فقط (يُخزَّن في عمود img/logo/image كمعرّف ونوع امتداد)، لا يُكتب أي ملف بهذا الاسم
function newImageFilename(string $prefix, string $mime): string {
    return $prefix . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . imageMimeToExt($mime);
}

// يفكّ صورة base64 (data:image/xxx;base64,...) ويتحقق من محتواها الفعلي
function decodeImageBase64($base64Image) {
    if (empty($base64Image) || !is_string($base64Image)) return null;
    if (!preg_match('/^data:image\/(\w+);base64,/', $base64Image, $matches)) return null;

    $imageType = strtolower($matches[1]);
    $allowedTypes = ['jpeg', 'jpg', 'png', 'gif', 'webp'];
    if (!in_array($imageType, $allowedTypes, true)) return null;

    $base64Data = substr($base64Image, strpos($base64Image, ',') + 1);
    $imageData = base64_decode($base64Data, true);
    if ($imageData === false || strlen($imageData) === 0) return null;

    // حد أقصى 8 ميجابايت للصورة الواحدة
    if (strlen($imageData) > 8 * 1024 * 1024) return null;

    // التأكد أن المحتوى صورة فعلية وليس ملفاً منفذاً تم تمويهه بامتداد صورة
    $info = @getimagesizefromstring($imageData);
    if ($info === false || !isset(ALLOWED_IMAGE_MIME_TO_EXT[$info['mime']])) return null;

    return ['data' => $imageData, 'mime' => $info['mime']];
}

// يتحقق من صورة مرفوعة عبر $_FILES (نماذج لوحة التحكم) ويُرجع بياناتها الفعلية ونوعها الحقيقي
function readUploadedImage($file) {
    if (empty($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return null;
    if ($file['size'] <= 0 || $file['size'] > 8 * 1024 * 1024) return null;

    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false || !isset(ALLOWED_IMAGE_MIME_TO_EXT[$imageInfo['mime']])) return null;

    $data = file_get_contents($file['tmp_name']);
    if ($data === false) return null;
    return ['data' => $data, 'mime' => $imageInfo['mime']];
}

// يتحقق من صورة موجودة مسبقاً على القرص (مثل ملفات مجلد استيراد قديم) ويُرجع بياناتها ونوعها
function readLocalImageFile($sourcePath) {
    if (empty($sourcePath) || !is_file($sourcePath)) return null;

    $size = filesize($sourcePath);
    if ($size === false || $size <= 0 || $size > 8 * 1024 * 1024) return null;

    $imageInfo = @getimagesize($sourcePath);
    if ($imageInfo === false || !isset(ALLOWED_IMAGE_MIME_TO_EXT[$imageInfo['mime']])) return null;

    $data = file_get_contents($sourcePath);
    if ($data === false) return null;
    return ['data' => $data, 'mime' => $imageInfo['mime']];
}

// يشتق اسماً مقروءاً لمنتج من اسم ملف صورة، مثل "iphone_15-pro.jpg" -> "iphone 15 pro"
function productNameFromFilename($filename) {
    $name = pathinfo($filename, PATHINFO_FILENAME);
    $name = str_replace(['_', '-'], ' ', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

function newEntityId($prefix) {
    return $prefix . '_' . uniqid() . '_' . random_int(100, 999);
}
