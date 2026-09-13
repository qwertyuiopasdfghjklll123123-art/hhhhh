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
// يسمح فقط بامتدادات صور حقيقية، ويتحقق من محتوى الملف الفعلي (وليس فقط الترويسة المرسلة من المتصفح)
function handleImageUpload($base64Image, $prefix = 'img') {
    if (empty($base64Image)) return null;

    if (preg_match('/^data:image\/(\w+);base64,/', $base64Image, $matches)) {
        $imageType = strtolower($matches[1]);
        $allowedExt = ['jpeg' => 'jpg', 'jpg' => 'jpg', 'png' => 'png', 'gif' => 'gif', 'webp' => 'webp'];
        if (!isset($allowedExt[$imageType])) {
            return null;
        }

        $base64Data = substr($base64Image, strpos($base64Image, ',') + 1);
        $imageData = base64_decode($base64Data, true);

        if ($imageData === false || strlen($imageData) === 0) return null;

        // حد أقصى 8 ميجابايت للصورة الواحدة
        if (strlen($imageData) > 8 * 1024 * 1024) return null;

        // التأكد أن المحتوى صورة فعلية وليس ملفاً منفذاً تم تمويهه بامتداد صورة
        if (@getimagesizefromstring($imageData) === false) return null;

        $ext = $allowedExt[$imageType];
        $uploadsDir = __DIR__ . '/../uploads';
        if (!file_exists($uploadsDir)) @mkdir($uploadsDir, 0755, true);

        $filename = $prefix . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
        $uploadPath = $uploadsDir . '/' . $filename;

        if (file_put_contents($uploadPath, $imageData)) {
            return $filename;
        }
    }
    return null;
}

// يعالج رفع صورة عادية عبر $_FILES (نماذج لوحة التحكم)، بنفس تحقق handleImageUpload:
// امتداد ضمن قائمة مسموحة والتأكد من أن المحتوى صورة حقيقية فعلاً
function handleFileUpload($file, $prefix = 'img') {
    if (empty($file) || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return null;
    if ($file['size'] <= 0 || $file['size'] > 8 * 1024 * 1024) return null;

    $imageInfo = @getimagesize($file['tmp_name']);
    if ($imageInfo === false) return null;

    $allowedMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $mime = $imageInfo['mime'] ?? '';
    if (!isset($allowedMime[$mime])) return null;
    $ext = $allowedMime[$mime];

    $uploadsDir = __DIR__ . '/../uploads';
    if (!file_exists($uploadsDir)) @mkdir($uploadsDir, 0755, true);

    $filename = $prefix . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $uploadPath = $uploadsDir . '/' . $filename;

    if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
        return $filename;
    }
    return null;
}

// ينسخ صورة موجودة مسبقاً على القرص (مثل ملفات مجلد استيراد قديم) إلى uploads/
// بنفس تحقق الصور الأخرى: امتداد ضمن قائمة مسموحة ومحتوى صورة حقيقي فعلاً
function handleLocalImageFile($sourcePath, $prefix = 'img') {
    if (empty($sourcePath) || !is_file($sourcePath)) return null;

    $size = filesize($sourcePath);
    if ($size === false || $size <= 0 || $size > 8 * 1024 * 1024) return null;

    $imageInfo = @getimagesize($sourcePath);
    if ($imageInfo === false) return null;

    $allowedMime = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
    $mime = $imageInfo['mime'] ?? '';
    if (!isset($allowedMime[$mime])) return null;
    $ext = $allowedMime[$mime];

    $uploadsDir = __DIR__ . '/../uploads';
    if (!file_exists($uploadsDir)) @mkdir($uploadsDir, 0755, true);

    $filename = $prefix . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $uploadPath = $uploadsDir . '/' . $filename;

    if (copy($sourcePath, $uploadPath)) {
        return $filename;
    }
    return null;
}

// يشتق اسماً مقروءاً لمنتج من اسم ملف صورة، مثل "iphone_15-pro.jpg" -> "iphone 15 pro"
function productNameFromFilename($filename) {
    $name = pathinfo($filename, PATHINFO_FILENAME);
    $name = str_replace(['_', '-'], ' ', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    return trim($name);
}

function deleteOldImage($filename) {
    if (empty($filename)) return;
    // لا تحذف الروابط الخارجية أو البيانات المضمنة
    if (strpos($filename, 'http') === 0 || strpos($filename, 'data:') === 0) return;
    // منع الخروج خارج مجلد uploads عبر مسار يحتوي على / أو \
    if (strpos($filename, '/') !== false || strpos($filename, '\\') !== false) return;
    $filePath = __DIR__ . '/../uploads/' . $filename;
    if (file_exists($filePath) && is_file($filePath)) {
        @unlink($filePath);
    }
}

function newEntityId($prefix) {
    return $prefix . '_' . uniqid() . '_' . random_int(100, 999);
}
