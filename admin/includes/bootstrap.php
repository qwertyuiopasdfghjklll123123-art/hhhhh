<?php
// يُضمَّن في بداية كل صفحة تحت admin/ - يجهز الجلسة، الاتصال بقاعدة البيانات، CSRF ورسائل التنبيه
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../../includes/functions.php';

if (!file_exists(__DIR__ . '/../../config.php')) {
    header('Location: ../install.php');
    exit;
}
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/data.php';

ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.gc_maxlifetime', 315360000);
ini_set('session.cookie_lifetime', 315360000);
ensureAppSessionStorage();
session_name('Almulla_SECURE');
session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pdo = getDb();

// استهلاك رمز الانتقال الآمن القادم من زر "تحكم" في الموقع الرئيسي، إن وُجد، قبل فحص الجلسة
// العادية - يضمن الدخول للوحة حتى لو لم تُشارَك جلسة تسجيل الدخول بشكل موثوق بين مجلد الموقع
// ومجلد admin/ على بعض الاستضافات (انظر التعليق عند نقطة admin_handoff في includes/api.php)
if (!isAdmin() && !empty($_GET['handoff'])) {
    $stmt = $pdo->prepare("SELECT user_id FROM admin_handoff_tokens WHERE token = ? AND expires_at > NOW()");
    $stmt->execute([(string)$_GET['handoff']]);
    $row = $stmt->fetch();
    if ($row) {
        $pdo->prepare("DELETE FROM admin_handoff_tokens WHERE token = ?")->execute([(string)$_GET['handoff']]);
        $handoffUser = db_get_user_by_id($pdo, $row['user_id']);
        if ($handoffUser && (bool)$handoffUser['is_admin']) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$handoffUser['id'];
            $_SESSION['user_email'] = $handoffUser['email'];
            $_SESSION['user_name'] = $handoffUser['fullname'];
            $_SESSION['is_admin'] = true;
        }
    }
}

function admin_require_login() {
    if (!isAdmin()) {
        // تسجيل الدخول موحّد بالكامل عبر صفحة الموقع الرئيسية (نفس الجلسة)، لا توجد صفحة
        // دخول منفصلة خاصة بلوحة التحكم - يعود المستخدم هنا تلقائياً إن كان حسابه مديراً
        $reason = isLoggedIn() ? 'not_admin' : 'login';
        header('Location: ../?admin_redirect=' . $reason);
        exit;
    }

    // مزامنة تلقائية: إن وضع المستخدم مجلد أو ملف ZIP صور جديداً داخل logs/legacy-imports
    // (على استضافته مباشرة، بلا حاجة لإرساله عبر المحادثة) يُستوردان الآن تلقائياً عند أي
    // دخول للوحة التحكم، دون الحاجة لفتح صفحة الاستيراد أو الضغط على أي زر يدوياً.
    global $pdo;
    require_once __DIR__ . '/../../includes/import.php';
    $autoSync = auto_sync_pending_legacy_images($pdo, __DIR__ . '/../../logs/legacy-imports');
    if (!empty($autoSync['processed'])) {
        $names = implode('، ', $autoSync['processed']);
        $msg = "تمت مزامنة الصور تلقائياً من: $names — تم تخزين {$autoSync['stored']} صورة داخل قاعدة البيانات. ";
        $msg .= "المتوفر الآن {$autoSync['matched']} من أصل {$autoSync['referenced_total']} صورة مطلوبة";
        $msg .= $autoSync['unmatched'] > 0 ? "، وما زال {$autoSync['unmatched']} ناقصاً." : ' (اكتملت كل الصور).';
        admin_flash($autoSync['unmatched'] > 0 ? 'error' : 'success', $msg);
    }
}

function admin_csrf_field() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8') . '">';
}

function admin_verify_csrf() {
    $token = $_POST['csrf_token'] ?? '';
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        admin_flash('error', 'انتهت صلاحية الجلسة، حاول مرة أخرى.');
        // basename(PHP_SELF) يُرجع دائماً اسم الملف الفعلي بامتداد .php (بصرف النظر عن
        // إعادة كتابة الرابط)، لذا يُزال الامتداد صراحة حتى يبقى المستخدم على الرابط النظيف
        header('Location: ' . preg_replace('/\.php$/', '', basename($_SERVER['PHP_SELF'])));
        exit;
    }
}

function admin_flash($type, $message) {
    $_SESSION['admin_flash'][] = ['type' => $type, 'message' => $message];
}

function admin_get_flashes() {
    $flashes = $_SESSION['admin_flash'] ?? [];
    unset($_SESSION['admin_flash']);
    return $flashes;
}

function e($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}
