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
