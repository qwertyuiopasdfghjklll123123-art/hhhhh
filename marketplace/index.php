<?php
/* ============================================================
   سوق رقمي متعدد المتاجر — تخزين البيانات عبر MySQL حصراً
   ============================================================ */
declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
mb_internal_encoding('UTF-8');

/* ===================== الإعدادات ===================== */
define('APP_NAME', 'سوق');
define('CURRENCY', 'د.ع');
define('ADMIN_SEED_EMAIL', 'admin@souq.local');   // حساب الأدمن الأولي — سجّل دخول فيه وغيّر كلمة المرور
define('ADMIN_SEED_PASSWORD', 'Admin@12345');     // غيّرها قبل النشر الحقيقي
define('DEEPSEEK_API_KEY', '');       // ضع مفتاح DeepSeek هنا لتفعيل الذكاء الاصطناعي الحقيقي
define('DEEPSEEK_API_URL', 'https://api.deepseek.com/chat/completions');
define('DEEPSEEK_MODEL', 'deepseek-chat');
define('GOOGLE_CLIENT_ID', ''); // Client ID من Google Cloud Console (OAuth) — زر "الدخول عبر Google" يظهر فقط بعد تعبئته
define('DATA_DIR', __DIR__ . '/data');
define('UPLOAD_DIR', __DIR__ . '/uploads');
define('UPLOAD_URL', 'uploads');
define('ORDER_STAGES', ['قيد المراجعة', 'تم قبول الطلب', 'قيد التجهيز', 'تم التسليم']);
define('COMPLAINT_REASONS', ['المنتج تالف أو معيب', 'لم يصلني الطلب', 'الطلب غير مطابق للوصف', 'تأخير بالتوصيل', 'سبب آخر']);
define('SUBSCRIPTION_DAYS', 30);
define('EARNINGS_HOLD_HOURS', 24);

if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0777, true);

// طبقة التخزين المشتركة (MySQL حصراً) — يشترك بها أيضاً install.php وmanifest.php
require_once __DIR__ . '/includes/db.php';

/* بعض الاستضافات المشتركة يكون مسار الجلسات الافتراضي عندها غير قابل للكتابة أو
   مقيّد، فتفشل الجلسة بصمت (يظهر أثرها كـ "كود التحقق غير صحيح" دائماً لأن
   الكود المخزّن بالجلسة لا يصل من الطلب الأول للثاني). نستخدم مجلد جلسات خاص
   بالتطبيق نضمن كتابته، ونضبط الكوكي بشكل متوافق مع HTTP أو HTTPS. */
define('SESSION_DIR', DATA_DIR . '/sessions');
if (!is_dir(SESSION_DIR)) mkdir(SESSION_DIR, 0777, true);
if (is_dir(SESSION_DIR) && is_writable(SESSION_DIR)) session_save_path(SESSION_DIR);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();
header('Content-Type: text/html; charset=utf-8');

// نحمي مجلد البيانات (JSON + الجلسات) من الوصول المباشر عبر الويب — عكس مجلد uploads الذي يجب أن يبقى مفتوحاً لعرض الصور
$__dataHt = DATA_DIR . '/.htaccess';
if (!file_exists($__dataHt)) @file_put_contents($__dataHt, "Require all denied\nDeny from all\n");

function next_id(array $items): int {
    $max = 0;
    foreach ($items as $it) $max = max($max, (int)($it['id'] ?? 0));
    return $max + 1;
}

/* لا توجد أي متاجر أو منتجات أو بيانات تجريبية ثابتة بالكود — الموقع يبدأ
   فارغاً تماماً ويُبنى بالكامل من install.php ولوحة الأدمن. هذه الدالة فقط
   تضمن وجود حساب أدمن صالح دائماً (تحذف تلقائياً أي سجلات مستخدمين قديمة
   غير متوافقة من نسخة سابقة بلا password_hash)، دون المساس بأي حساب حقيقي. */
function ensure_admin_user(): void {
    $users = db_read('users');
    $before = $users;

    $users = array_values(array_filter($users, fn($u) => !empty($u['password_hash']) && !empty($u['email'])));

    $hasAdmin = false;
    foreach ($users as $u) if (!empty($u['is_admin'])) { $hasAdmin = true; break; }

    if (!$hasAdmin) {
        $users[] = [
            'id' => next_id($users), 'name' => 'الإدارة', 'email' => ADMIN_SEED_EMAIL,
            'password_hash' => password_hash(ADMIN_SEED_PASSWORD, PASSWORD_DEFAULT), 'phone' => '',
            'wallet' => 0, 'wallet_log' => [], 'favorites' => ['stores'=>[],'products'=>[]],
            'is_admin' => true, 'created_at' => time(),
        ];
    }

    if ($users !== $before) db_write('users', $users);
}
ensure_admin_user();

/* نفس فكرة ensure_admin_user لكن لبيانات المتاجر: إن كان stores.json قديماً
   من قبل إضافة نظام الأرباح/الاشتراكات فلن تحوي سجلاته حقول مثل earnings_log
   وسيظهر تحذير PHP يكسر استجابة الـ ajax. نُكمل الحقول الناقصة فقط بقيم
   افتراضية آمنة دون المساس بأي بيانات متجر حقيقية موجودة. */
function ensure_store_defaults(): void {
    $stores = db_read('stores');
    $themeDefaults = ['primary'=>'#f2b100', 'radius'=>18, 'density'=>'comfortable', 'layout'=>'grid2'];
    $defaults = [
        'sections'=>[], 'earnings'=>0, 'earnings_log'=>[], 'last_fee_at'=>null,
        'subscription_expires_at'=>null, 'suspended'=>false,
        'name'=>'بدون اسم', 'slug'=>'', 'description'=>'', 'category'=>'أخرى',
        'contact_phone'=>'', 'contact_whatsapp'=>'', 'logo'=>'', 'cover'=>'',
        'status'=>'approved', 'featured'=>false, 'created_at'=>time(),
        'theme'=>$themeDefaults, 'owner_user_id'=>0,
    ];
    $changed = false;
    foreach ($stores as &$s) {
        $merged = $s + $defaults;
        $merged['theme'] = (is_array($merged['theme'] ?? null) ? $merged['theme'] : []) + $themeDefaults;
        if ($merged !== $s) { $s = $merged; $changed = true; }
    }
    unset($s);
    if ($changed) db_write('stores', $stores);
}
ensure_store_defaults();

function release_matured_earnings(): void {
    $stores = db_read('stores');
    $changed = false;
    foreach ($stores as &$s) {
        foreach ($s['earnings_log'] as &$e) {
            if (!empty($e['release_at']) && empty($e['released']) && $e['release_at'] <= time()) {
                $s['earnings'] = (float)($s['earnings'] ?? 0) + $e['amount'];
                $e['released'] = true;
                $changed = true;
            }
        }
        unset($e);
    }
    unset($s);
    if ($changed) db_write('stores', $stores);
}
release_matured_earnings();

/* نسخ احتياطي عبر بوت تيليجرام كل 6 ساعات — بلا cron حقيقي، فالفحص كسول
   (يشتغل فقط ضمن طلبات الأدمن حتى لا يبطئ تصفح المشترين العاديين) ويُنفَّذ
   الأرشفة الفعلية فقط عند مرور المدة. */
define('BACKUP_INTERVAL_SECONDS', 6 * 3600);

function run_backup_now(): array {
    $settings = get_settings();
    $token = trim((string)($settings['telegram_bot_token'] ?? ''));
    $chatId = trim((string)($settings['telegram_chat_id'] ?? ''));
    if ($token === '' || $chatId === '') return ['ok' => false, 'msg' => 'الرجاء إدخال توكن البوت ومعرف المحادثة أولاً'];
    if (!class_exists('ZipArchive')) return ['ok' => false, 'msg' => 'امتداد ZipArchive غير مفعّل على هذه الاستضافة'];

    $files = glob(DATA_DIR . '/*.json');
    if (!$files) return ['ok' => false, 'msg' => 'لا توجد بيانات لنسخها احتياطياً بعد'];

    $zipPath = sys_get_temp_dir() . '/souq_backup_' . time() . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        return ['ok' => false, 'msg' => 'تعذّر إنشاء ملف الأرشيف'];
    }
    foreach ($files as $f) $zip->addFile($f, 'data/' . basename($f));
    $zip->close();

    $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendDocument');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'chat_id' => $chatId,
            'caption' => site_name() . ' — نسخة احتياطية ' . date('Y-m-d H:i'),
            'document' => new CURLFile($zipPath, 'application/zip', 'backup_' . date('Y-m-d_H-i') . '.zip'),
        ],
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($zipPath);

    $settings = get_settings();
    $settings['last_backup_at'] = time();
    db_write('settings', $settings);

    if ($res === false || $code !== 200) return ['ok' => false, 'msg' => 'فشل الإرسال عبر تيليجرام: ' . ($err ?: 'HTTP ' . $code)];
    return ['ok' => true, 'msg' => 'تم إرسال النسخة الاحتياطية بنجاح'];
}

function maybe_run_backup(): void {
    if (!is_admin_user()) return;
    $settings = get_settings();
    if (trim((string)($settings['telegram_bot_token'] ?? '')) === '' || trim((string)($settings['telegram_chat_id'] ?? '')) === '') return;
    if (time() - (int)($settings['last_backup_at'] ?? 0) < BACKUP_INTERVAL_SECONDS) return;
    run_backup_now();
}
maybe_run_backup();

function store_pending_earnings(array $store): float {
    $sum = 0;
    foreach ($store['earnings_log'] as $e) if (!empty($e['release_at']) && empty($e['released'])) $sum += $e['amount'];
    return $sum;
}

function find_coupon_for_product(int $productId): ?array {
    $code = $_SESSION['cart_coupon'] ?? null;
    if (!$code) return null;
    foreach (get_settings()['coupons'] as $c) if ($c['code'] === $code && ($c['product_id'] === null || $c['product_id'] === $productId)) return $c;
    return null;
}

function coupon_price(int $productId, float $price): float {
    $c = find_coupon_for_product($productId);
    return $c ? round($price * (1 - $c['percent'] / 100), 2) : $price;
}

function is_store_live(array $s): bool {
    if ($s['status'] !== 'approved' || !empty($s['suspended'])) return false;
    $exp = $s['subscription_expires_at'] ?? null;
    return $exp === null || time() < $exp;
}

/* التقييم الحقيقي مصدره فقط مشترون أكملوا طلباً فعلياً مع هذا المتجر
   (عبر submit_review) — لا يوجد أي تقييم افتراضي أو محسوب من عدد الشكاوى. */
function store_rating(int $storeId): ?float {
    static $sums = null, $counts = null;
    if ($sums === null) {
        $sums = []; $counts = [];
        foreach (db_read('reviews') as $r) {
            $sums[$r['store_id']] = ($sums[$r['store_id']] ?? 0) + $r['rating'];
            $counts[$r['store_id']] = ($counts[$r['store_id']] ?? 0) + 1;
        }
    }
    if (empty($counts[$storeId])) return null;
    return round($sums[$storeId] / $counts[$storeId] * 2) / 2;
}

function product_rating(int $productId): ?float {
    static $sums = null, $counts = null;
    if ($sums === null) {
        $sums = []; $counts = [];
        foreach (db_read('reviews') as $r) {
            $sums[$r['product_id']] = ($sums[$r['product_id']] ?? 0) + $r['rating'];
            $counts[$r['product_id']] = ($counts[$r['product_id']] ?? 0) + 1;
        }
    }
    if (empty($counts[$productId])) return null;
    return round($sums[$productId] / $counts[$productId] * 2) / 2;
}

function find_review(int $orderId, int $productId): ?array {
    foreach (db_read('reviews') as $r) if ($r['order_id'] === $orderId && $r['product_id'] === $productId) return $r;
    return null;
}

function render_stars(?float $rating): string {
    if ($rating === null) return '<span class="stars-num" style="color:var(--muted)">لا تقييمات بعد</span>';
    ob_start();
    for ($i = 1; $i <= 5; $i++) {
        if ($rating >= $i) echo '<i class="fas fa-star"></i>';
        elseif ($rating >= $i - 0.5) echo '<i class="fas fa-star-half-stroke"></i>';
        else echo '<i class="far fa-star"></i>';
    }
    return '<span class="stars">' . ob_get_clean() . '</span> <span class="stars-num">' . number_format($rating, 1) . '</span>';
}

/* ===================== الإشعارات ===================== */
function add_notification($recipient, string $title, string $body, ?string $link = null, string $type = 'info'): void {
    $notifs = db_read('notifications');
    $notifs[] = ['id'=>next_id($notifs), 'recipient'=>$recipient, 'title'=>$title, 'body'=>$body, 'link'=>$link, 'type'=>$type, 'read'=>false, 'created_at'=>time()];
    db_write('notifications', $notifs);
}

function notif_icon(string $type): array {
    return match ($type) {
        'order' => ['fa-bag-shopping', '#2f8fd8'],
        'wallet' => ['fa-wallet', '#3fa66a'],
        'store' => ['fa-store', '#f2b100'],
        'complaint' => ['fa-comment-dots', '#e0559a'],
        'broadcast' => ['fa-bullhorn', '#8a5cf6'],
        default => ['fa-bell', '#8a92a6'],
    };
}

function my_notif_recipient() {
    if (is_admin_user()) return 'admin';
    $u = current_user();
    return $u ? $u['id'] : null;
}

function my_notifications(): array {
    $recipient = my_notif_recipient();
    if ($recipient === null) return [];
    $all = array_values(array_filter(db_read('notifications'), fn($n) => $n['recipient'] === $recipient));
    usort($all, fn($a, $b) => $b['created_at'] <=> $a['created_at']);
    return $all;
}

function time_ago(int $ts): string {
    $diff = time() - $ts;
    if ($diff < 60) return 'الآن';
    if ($diff < 3600) return 'قبل ' . intdiv($diff, 60) . ' دقيقة';
    if ($diff < 86400) return 'قبل ' . intdiv($diff, 3600) . ' ساعة';
    return 'قبل ' . intdiv($diff, 86400) . ' يوم';
}

/* ===================== دوال مساعدة عامة ===================== */
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function money($n): string { return number_format((float)$n, 0) . ' ' . CURRENCY; }
function is_ajax(): bool { return ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch'; }

function redirect(string $to): void {
    if (is_ajax()) {
        $q = parse_url($to, PHP_URL_QUERY);
        parse_str((string)$q, $params);
        $_GET = $params;
        emit_fragment();
    }
    header('Location: ' . $to);
    exit;
}
function flash(string $type, string $text): void { $_SESSION['flash'][] = ['type'=>$type, 'text'=>$text]; }
function take_flashes(): array { $f = $_SESSION['flash'] ?? []; unset($_SESSION['flash']); return $f; }

function category_icon(string $cat): string {
    $map = ['ملابس'=>'fa-shirt','إلكترونيات'=>'fa-mobile-screen','تجميل ومكياج'=>'fa-wand-magic-sparkles','منزل ومطبخ'=>'fa-kitchen-set','أطفال وألعاب'=>'fa-puzzle-piece','رياضة ولياقة'=>'fa-dumbbell','أخرى'=>'fa-shapes'];
    return $map[$cat] ?? 'fa-shapes';
}

function handle_upload(string $field): ?string {
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) return null;
    $tmp = $_FILES[$field]['tmp_name'];
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) return null;
    if ($_FILES[$field]['size'] > 5 * 1024 * 1024) return null;
    $name = uniqid('u', true) . '.' . $ext;
    if (!move_uploaded_file($tmp, UPLOAD_DIR . '/' . $name)) return null;
    return UPLOAD_URL . '/' . $name;
}

function handle_multi_upload(string $field): array {
    $out = [];
    if (empty($_FILES[$field]) || !is_array($_FILES[$field]['name'])) return $out;
    $count = count($_FILES[$field]['name']);
    for ($i = 0; $i < $count; $i++) {
        if ($_FILES[$field]['error'][$i] !== UPLOAD_ERR_OK) continue;
        $ext = strtolower(pathinfo($_FILES[$field]['name'][$i], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) continue;
        if ($_FILES[$field]['size'][$i] > 5 * 1024 * 1024) continue;
        $name = uniqid('u', true) . '.' . $ext;
        if (move_uploaded_file($_FILES[$field]['tmp_name'][$i], UPLOAD_DIR . '/' . $name)) {
            $out[] = UPLOAD_URL . '/' . $name;
        }
    }
    return $out;
}

function radius_px(string $preset): int {
    return ['sharp'=>6, 'rounded'=>16, 'pill'=>28][$preset] ?? 16;
}

/* ===================== الهوية ===================== */
function current_user(): ?array {
    if (empty($_SESSION['user_id'])) return null;
    $users = db_read('users');
    foreach ($users as $u) if ($u['id'] === $_SESSION['user_id']) return $u;
    return null;
}

function is_admin_user(): bool {
    $u = current_user();
    return $u !== null && !empty($u['is_admin']);
}

/* التحقق من ID token الخاص بـ Google Identity Services عبر endpoint الرسمي —
   أبسط طريقة تعمل بدون أي مكتبة JWT، مناسبة لملف واحد بدون Composer. */
function google_verify_id_token(string $idToken): ?array {
    if (GOOGLE_CLIENT_ID === '' || $idToken === '') return null;
    $ch = curl_init('https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false || $code !== 200) return null;
    $claims = json_decode($res, true);
    if (!is_array($claims)) return null;
    if (($claims['aud'] ?? '') !== GOOGLE_CLIENT_ID) return null;
    if (($claims['email_verified'] ?? 'false') !== 'true') return null;
    if (empty($claims['email'])) return null;
    return $claims;
}

function my_store(): ?array {
    $u = current_user();
    if (!$u) return null;
    $stores = db_read('stores');
    foreach ($stores as $s) if ($s['owner_user_id'] === $u['id'] && $s['status'] === 'approved') return $s;
    return null;
}

function my_pending_store(): ?array {
    $u = current_user();
    if (!$u) return null;
    $stores = db_read('stores');
    foreach ($stores as $s) if ($s['owner_user_id'] === $u['id'] && $s['status'] !== 'approved') return $s;
    return null;
}

function find_store(int $id): ?array {
    foreach (db_read('stores') as $s) if ($s['id'] === $id) return $s;
    return null;
}
function find_product(int $id): ?array {
    foreach (db_read('products') as $p) if ($p['id'] === $id) return $p;
    return null;
}
function store_products(int $storeId): array {
    return array_values(array_filter(db_read('products'), fn($p) => $p['store_id'] === $storeId));
}

/* ===================== معالجة الإجراءات (POST) ===================== */
$action = $_POST['action'] ?? '';
if ($action !== '') {

    if ($action === 'login') {
        $identifier = trim((string)($_POST['identifier'] ?? $_POST['email'] ?? ''));
        $identifierLower = mb_strtolower($identifier);
        $password = (string)($_POST['password'] ?? '');
        $users = db_read('users');
        $found = null;
        foreach ($users as $u) {
            $emailMatch = $identifierLower !== '' && mb_strtolower($u['email'] ?? '') === $identifierLower;
            $phoneMatch = $identifier !== '' && ($u['phone'] ?? '') !== '' && $u['phone'] === $identifier;
            if ($emailMatch || $phoneMatch) { $found = $u; break; }
        }
        if (!$found || !password_verify($password, $found['password_hash'] ?? '')) {
            flash('err', 'البريد أو رقم الهاتف أو كلمة المرور غير صحيحة');
            redirect('index.php?page=login');
        }
        $_SESSION['user_id'] = $found['id'];
        redirect('index.php');
    }

    if ($action === 'register') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = trim(mb_strtolower((string)($_POST['email'] ?? '')));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $captcha = strtoupper(trim((string)($_POST['captcha'] ?? '')));
        $expected = $_SESSION['reg_captcha'] ?? null;
        unset($_SESSION['reg_captcha']);

        $err = null;
        if ($name === '' || $email === '' || $phone === '' || $password === '') $err = 'الرجاء تعبئة كل الحقول';
        elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $err = 'البريد الإلكتروني غير صحيح';
        elseif (strlen($password) < 6) $err = 'كلمة المرور لازم لا تقل عن 6 خانات';
        elseif ($expected === null || $captcha !== $expected) $err = 'كود التحقق غير صحيح، حاول مرة ثانية';
        else {
            foreach (db_read('users') as $u) if (mb_strtolower($u['email'] ?? '') === $email) { $err = 'هذا البريد مسجّل مسبقاً'; break; }
        }
        if ($err) { flash('err', $err); redirect('index.php?page=register'); }

        $users = db_read('users');
        $newUser = ['id'=>next_id($users), 'name'=>$name, 'email'=>$email, 'password_hash'=>password_hash($password, PASSWORD_DEFAULT), 'phone'=>$phone, 'wallet'=>0, 'wallet_log'=>[], 'favorites'=>['stores'=>[],'products'=>[]], 'is_admin'=>false, 'created_at'=>time()];
        $users[] = $newUser;
        db_write('users', $users);
        $_SESSION['user_id'] = $newUser['id'];
        redirect('index.php');
    }

    if ($action === 'google_login') {
        $claims = google_verify_id_token((string)($_POST['credential'] ?? ''));
        if ($claims === null) { flash('err', 'تعذّر التحقق من حساب Google، حاول مرة ثانية'); redirect('index.php?page=login'); }
        $email = mb_strtolower((string)$claims['email']);
        $users = db_read('users');
        $found = null;
        foreach ($users as $u) if (mb_strtolower($u['email'] ?? '') === $email) { $found = $u; break; }
        if ($found) {
            $_SESSION['user_id'] = $found['id'];
        } else {
            $newUser = [
                'id' => next_id($users), 'name' => trim((string)($claims['name'] ?? 'مستخدم Google')),
                'email' => $email, 'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                'phone' => '', 'wallet' => 0, 'wallet_log' => [], 'favorites' => ['stores'=>[],'products'=>[]],
                'is_admin' => false, 'created_at' => time(),
            ];
            $users[] = $newUser;
            db_write('users', $users);
            $_SESSION['user_id'] = $newUser['id'];
        }
        redirect('index.php');
    }

    if ($action === 'logout') {
        unset($_SESSION['user_id']);
        $_SESSION['cart'] = [];
        redirect('index.php');
    }

    // من هنا تحتاج المستخدم مسجّل دخول
    $needsUser = ['add_to_cart','remove_from_cart','checkout','apply_vendor','toggle_favorite','update_profile','submit_complaint','request_topup','complaint_reply','request_withdraw','submit_review'];
    if (in_array($action, $needsUser, true) && !current_user()) redirect('index.php');

    if ($action === 'submit_review') {
        $user = current_user();
        $orderId = (int)($_POST['order_id'] ?? 0);
        $productId = (int)($_POST['product_id'] ?? 0);
        $rating = (int)($_POST['rating'] ?? 0);
        $comment = trim((string)($_POST['comment'] ?? ''));
        $order = null;
        foreach (db_read('orders') as $o) if ($o['id'] === $orderId && $o['buyer_id'] === $user['id']) { $order = $o; break; }
        $isDelivered = $order && $order['status'] === ORDER_STAGES[count(ORDER_STAGES) - 1];
        $hasItem = $order && in_array($productId, array_column($order['items'], 'product_id'), true);
        if (!$order || !$isDelivered || !$hasItem || $rating < 1 || $rating > 5) {
            flash('err', 'لا يمكن إضافة هذا التقييم');
            redirect('index.php?page=orders');
        }
        if (find_review($orderId, $productId)) {
            flash('err', 'تم تقييم هذا المنتج مسبقاً لهذا الطلب');
            redirect('index.php?page=orders');
        }
        $reviews = db_read('reviews');
        $reviews[] = ['id'=>next_id($reviews), 'order_id'=>$orderId, 'product_id'=>$productId, 'store_id'=>$order['store_id'], 'user_id'=>$user['id'], 'rating'=>$rating, 'comment'=>$comment, 'created_at'=>time()];
        db_write('reviews', $reviews);
        flash('ok', 'شكراً على تقييمك');
        redirect('index.php?page=orders');
    }

    if ($action === 'add_to_cart') {
        $pid = (int)($_POST['product_id'] ?? 0);
        $qty = max(1, (int)($_POST['qty'] ?? 1));
        if (find_product($pid)) {
            $_SESSION['cart'][$pid] = ($_SESSION['cart'][$pid] ?? 0) + $qty;
            flash('ok', 'أُضيف المنتج إلى السلة');
        }
        redirect($_POST['back'] ?? 'index.php');
    }

    if ($action === 'remove_from_cart') {
        $pid = (int)($_POST['product_id'] ?? 0);
        unset($_SESSION['cart'][$pid]);
        redirect('index.php?page=cart');
    }

    if ($action === 'apply_coupon') {
        $code = strtoupper(trim((string)($_POST['coupon_code'] ?? '')));
        $cart = $_SESSION['cart'] ?? [];
        $matched = false;
        foreach (get_settings()['coupons'] as $c) if ($c['code'] === $code && ($c['product_id'] === null ? !empty($cart) : isset($cart[$c['product_id']]))) { $matched = true; break; }
        if ($code === '' || !$matched) {
            unset($_SESSION['cart_coupon']);
            flash('err', $code === '' ? 'تم إلغاء الكوبون' : 'هذا الكود غير صالح أو لا ينطبق على منتج بسلتك');
        } else {
            $_SESSION['cart_coupon'] = $code;
            flash('ok', 'تم تطبيق الكوبون');
        }
        redirect('index.php?page=cart');
    }

    if ($action === 'checkout') {
        $cart = $_SESSION['cart'] ?? [];
        if (empty($cart)) redirect('index.php?page=cart');
        $deliveryPhone = trim((string)($_POST['delivery_phone'] ?? ''));
        $deliveryLocation = trim((string)($_POST['delivery_location'] ?? ''));
        if ($deliveryPhone === '' || $deliveryLocation === '') {
            flash('err', 'الرجاء إدخال رقم الهاتف والموقع لإتمام الطلب');
            redirect('index.php?page=cart');
        }
        $products = db_read('products');
        $byStore = [];
        $total = 0;
        foreach ($cart as $pid => $qty) {
            $p = null; foreach ($products as $pp) if ($pp['id'] === (int)$pid) { $p = $pp; break; }
            if (!$p) continue;
            $price = coupon_price($p['id'], $p['discount_price'] ?? $p['price']);
            $byStore[$p['store_id']][] = ['product_id'=>$p['id'], 'name'=>$p['name'], 'price'=>$price, 'qty'=>$qty];
            $total += $price * $qty;
        }
        $user = current_user();
        if ($total > (float)$user['wallet']) {
            flash('err', 'رصيدك غير كافٍ لإتمام الشراء. تواصل مع الإدارة لشحن رصيدك.');
            redirect('index.php?page=cart');
        }
        $orders = db_read('orders');
        $stores = db_read('stores');
        foreach ($byStore as $storeId => $items) {
            $subtotal = array_sum(array_map(fn($it) => $it['price'] * $it['qty'], $items));
            $oid = next_id($orders);
            $orders[] = ['id'=>$oid, 'buyer_id'=>$user['id'], 'store_id'=>$storeId, 'items'=>$items, 'total'=>$subtotal, 'status'=>ORDER_STAGES[0], 'delivery_phone'=>$deliveryPhone, 'delivery_location'=>$deliveryLocation, 'created_at'=>time(), 'updated_at'=>time()];
            foreach ($stores as &$s) if ($s['id'] === $storeId) {
                $s['earnings_log'][] = ['amount'=>$subtotal, 'note'=>'قيمة طلب جديد (معلّقة ' . EARNINGS_HOLD_HOURS . ' ساعة)', 'at'=>time(), 'release_at'=>time() + 3600 * EARNINGS_HOLD_HOURS, 'released'=>false];
                if ($s['owner_user_id']) add_notification($s['owner_user_id'], 'طلب جديد #' . $oid, $user['name'] . ' طلب منتجات بقيمة ' . money($subtotal) . ' — راح تتوفر بالرصيد بعد ' . EARNINGS_HOLD_HOURS . ' ساعة', 'index.php?page=vendor&section=orders', 'order');
            }
            unset($s);
        }
        db_write('orders', $orders);
        db_write('stores', $stores);
        $users = db_read('users');
        foreach ($users as &$u) if ($u['id'] === $user['id']) {
            $u['wallet'] = (float)$u['wallet'] - $total;
            $u['wallet_log'][] = ['amount'=>-$total, 'note'=>'عملية شراء', 'at'=>time()];
        }
        unset($u);
        db_write('users', $users);
        $_SESSION['cart'] = [];
        unset($_SESSION['cart_coupon']);
        flash('ok', 'تم إنشاء طلبك بنجاح، يمكنك متابعته من صفحة طلباتي');
        redirect('index.php?page=orders');
    }

    if ($action === 'toggle_favorite') {
        $type = $_POST['type'] === 'store' ? 'stores' : 'products';
        $id = (int)($_POST['id'] ?? 0);
        $users = db_read('users');
        $user = current_user();
        foreach ($users as &$u) if ($u['id'] === $user['id']) {
            $list = $u['favorites'][$type] ?? [];
            if (in_array($id, $list, true)) $list = array_values(array_diff($list, [$id]));
            else $list[] = $id;
            $u['favorites'][$type] = $list;
        }
        unset($u);
        db_write('users', $users);
        redirect($_POST['back'] ?? 'index.php');
    }

    if ($action === 'update_profile') {
        $users = db_read('users');
        $user = current_user();
        $newEmail = mb_strtolower(trim((string)($_POST['email'] ?? $user['email'])));
        $newPassword = (string)($_POST['password'] ?? '');
        $currentPassword = (string)($_POST['current_password'] ?? '');
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            flash('err', 'البريد الإلكتروني غير صحيح');
            redirect('index.php?page=account-edit');
        }
        foreach ($users as $u) if ($u['id'] !== $user['id'] && mb_strtolower($u['email'] ?? '') === $newEmail) {
            flash('err', 'هذا البريد مستخدم من حساب آخر');
            redirect('index.php?page=account-edit');
        }
        if ($newPassword !== '') {
            if (strlen($newPassword) < 6) {
                flash('err', 'كلمة المرور الجديدة لازم لا تقل عن 6 خانات');
                redirect('index.php?page=account-edit');
            }
            if (!password_verify($currentPassword, $user['password_hash'] ?? '')) {
                flash('err', 'كلمة المرور الحالية غير صحيحة');
                redirect('index.php?page=account-edit');
            }
        }
        foreach ($users as &$u) if ($u['id'] === $user['id']) {
            $u['name'] = trim((string)($_POST['name'] ?? $u['name']));
            $u['email'] = $newEmail;
            $u['phone'] = trim((string)($_POST['phone'] ?? $u['phone']));
            if ($newPassword !== '') $u['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        }
        unset($u);
        db_write('users', $users);
        flash('ok', 'تم تحديث بياناتك');
        redirect('index.php?page=account-edit');
    }

    if ($action === 'request_topup') {
        $user = current_user();
        $amount = (float)($_POST['amount'] ?? 0);
        $method = trim((string)($_POST['method'] ?? ''));
        $receipt = handle_upload('receipt');
        if ($amount <= 0 || $method === '' || !$receipt) {
            flash('err', 'الرجاء اختيار طريقة الدفع، إدخال المبلغ، ورفع صورة الوصل');
            redirect('index.php?page=account');
        }
        $requests = db_read('topup_requests');
        $requests[] = ['id'=>next_id($requests), 'user_id'=>$user['id'], 'method'=>$method, 'amount'=>$amount, 'receipt'=>$receipt, 'status'=>'pending', 'created_at'=>time()];
        db_write('topup_requests', $requests);
        add_notification('admin', 'طلب شحن جديد', $user['name'] . ' طلب شحن ' . money($amount) . ' عبر ' . $method, 'index.php?page=admin&section=topups', 'wallet');
        flash('ok', 'تم إرسال طلب الشحن، بانتظار مراجعة الإدارة');
        redirect('index.php?page=account');
    }

    if ($action === 'request_withdraw') {
        $store = my_store();
        if (!$store) redirect('index.php?page=vendor');
        $amount = (float)($store['earnings'] ?? 0);
        $method = trim((string)($_POST['method'] ?? ''));
        $accountNumber = trim((string)($_POST['account_number'] ?? ''));
        $accountName = trim((string)($_POST['account_name'] ?? ''));
        if ($amount <= 0 || $method === '' || $accountNumber === '' || $accountName === '') {
            flash('err', 'الرجاء تعبئة كل الحقول، والتأكد من وجود رصيد متاح');
            redirect('index.php?page=vendor&section=withdraw');
        }
        $existing = array_filter(db_read('withdraw_requests'), fn($r) => $r['store_id'] === $store['id'] && $r['status'] === 'pending');
        if ($existing) {
            flash('err', 'لديك طلب سحب قيد المراجعة بالفعل');
            redirect('index.php?page=vendor&section=withdraw');
        }
        $requests = db_read('withdraw_requests');
        $requests[] = [
            'id' => next_id($requests), 'store_id' => $store['id'], 'owner_user_id' => $store['owner_user_id'],
            'amount' => $amount, 'method' => $method, 'account_number' => $accountNumber, 'account_name' => $accountName,
            'status' => 'pending', 'created_at' => time(),
        ];
        db_write('withdraw_requests', $requests);
        add_notification('admin', 'طلب سحب رصيد جديد', $store['name'] . ' طلب سحب ' . money($amount) . ' عبر ' . $method, 'index.php?page=admin&section=withdrawals', 'wallet');
        flash('ok', 'تم إرسال طلب السحب، بانتظار مراجعة الإدارة');
        redirect('index.php?page=vendor&section=withdraw');
    }

    if ($action === 'apply_vendor') {
        $user = current_user();
        if (my_store() || my_pending_store()) redirect('index.php?page=account');
        $doc = handle_upload('document');
        $stores = db_read('stores');
        $stores[] = [
            'id'=>next_id($stores), 'owner_user_id'=>$user['id'],
            'name'=>trim((string)($_POST['name'] ?? '')),
            'slug'=>'store-' . next_id($stores),
            'description'=>trim((string)($_POST['description'] ?? '')),
            'category'=>$_POST['category'] ?? get_categories()[0],
            'contact_phone'=>trim((string)($_POST['contact_phone'] ?? '')),
            'contact_whatsapp'=>trim((string)($_POST['contact_whatsapp'] ?? '')),
            'logo'=>'', 'cover'=>'', 'document'=>$doc,
            'status'=>'pending', 'featured'=>false,
            'theme'=>['primary'=>'#f2b100','radius'=>16,'density'=>'comfortable','layout'=>'grid2'],
            'sections'=>[], 'earnings'=>0, 'earnings_log'=>[], 'last_fee_at'=>null,
            'subscription_expires_at'=>null, 'suspended'=>false,
            'created_at'=>time(),
        ];
        db_write('stores', $stores);
        add_notification('admin', 'طلب انضمام جديد', $user['name'] . ' قدّم طلب انضمام كتاجر (' . trim((string)($_POST['name'] ?? '')) . ')', 'index.php?page=admin&section=applications', 'store');
        flash('ok', 'تم إرسال طلبك بنجاح، سيتم مراجعته من قبل الإدارة قريباً');
        redirect('index.php?page=account');
    }

    if ($action === 'submit_complaint') {
        $user = current_user();
        $orderId = (int)($_POST['order_id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        $details = trim((string)($_POST['details'] ?? ''));
        $order = null;
        foreach (db_read('orders') as $o) if ($o['id'] === $orderId && $o['buyer_id'] === $user['id']) { $order = $o; break; }
        if (!$order || $reason === '') { flash('err', 'الرجاء اختيار الطلب وسبب المشكلة'); redirect('index.php?page=ai'); }
        $complaints = db_read('complaints');
        $cid = next_id($complaints);
        $messages = [['from'=>'buyer', 'text'=>$reason . ($details !== '' ? ' — ' . $details : ''), 'at'=>time()]];
        $complaints[] = [
            'id'=>$cid, 'buyer_id'=>$user['id'], 'order_id'=>$orderId, 'store_id'=>$order['store_id'],
            'reason'=>$reason, 'details'=>$details, 'messages'=>$messages,
            'status'=>'open', 'created_at'=>time(),
        ];
        db_write('complaints', $complaints);
        add_notification('admin', 'شكوى جديدة #' . $cid, $user['name'] . ' رفع شكوى على الطلب #' . $orderId . ' (' . $reason . ')', 'index.php?page=admin&section=complaints', 'complaint');
        redirect('index.php?page=ai');
    }

    if ($action === 'complaint_reply') {
        $user = current_user();
        $cid = (int)($_POST['complaint_id'] ?? 0);
        $text = trim((string)($_POST['text'] ?? ''));
        if ($text === '') redirect('index.php?page=ai');
        $complaints = db_read('complaints');
        $target = null;
        foreach ($complaints as &$c) {
            $isOwner = $c['buyer_id'] === $user['id'];
            $isAdmin = is_admin_user();
            if ($c['id'] === $cid && ($isOwner || $isAdmin)) {
                $c['messages'][] = ['from'=>$isAdmin ? 'admin' : 'buyer', 'text'=>$text, 'at'=>time()];
                $target = $c;
            }
        }
        unset($c);
        if ($target) {
            db_write('complaints', $complaints);
            if (is_admin_user()) add_notification($target['buyer_id'], 'رد جديد على شكواك #' . $cid, $text, 'index.php?page=ai', 'complaint');
            else add_notification('admin', 'رد جديد على الشكوى #' . $cid, $user['name'] . ': ' . $text, 'index.php?page=admin&section=complaints', 'complaint');
        }
        redirect(is_admin_user() ? 'index.php?page=admin&section=complaints&id=' . $cid : 'index.php?page=ai');
    }

    // إجراءات التاجر
    $vendorActions = ['vendor_add_product','vendor_edit_product','vendor_delete_product','vendor_update_order','vendor_save_theme','vendor_save_info','vendor_add_section','vendor_rename_section','vendor_delete_section','vendor_move_section'];
    if (in_array($action, $vendorActions, true)) {
        $store = my_store();
        if (!$store) redirect('index.php');

        if ($action === 'vendor_add_product') {
            $products = db_read('products');
            $products[] = ['id'=>next_id($products), 'store_id'=>$store['id'],
                'name'=>trim((string)$_POST['name']), 'description'=>trim((string)$_POST['description']),
                'price'=>(float)$_POST['price'], 'discount_price'=>$_POST['discount_price'] !== '' ? (float)$_POST['discount_price'] : null,
                'category'=>$_POST['category'] ?? $store['category'],
                'section_id'=>$_POST['section_id'] !== '' ? (int)$_POST['section_id'] : null,
                'images'=>handle_multi_upload('images'), 'created_at'=>time()];
            db_write('products', $products);
            flash('ok', 'تمت إضافة المنتج');
            redirect('index.php?page=vendor&section=products');
        }

        if ($action === 'vendor_edit_product') {
            $products = db_read('products');
            $pid = (int)$_POST['product_id'];
            foreach ($products as &$p) {
                if ($p['id'] === $pid && $p['store_id'] === $store['id']) {
                    $p['name'] = trim((string)$_POST['name']);
                    $p['description'] = trim((string)$_POST['description']);
                    $p['price'] = (float)$_POST['price'];
                    $p['discount_price'] = $_POST['discount_price'] !== '' ? (float)$_POST['discount_price'] : null;
                    $p['category'] = $_POST['category'] ?? $p['category'];
                    $p['section_id'] = $_POST['section_id'] !== '' ? (int)$_POST['section_id'] : null;
                    $newImgs = handle_multi_upload('images');
                    if ($newImgs) $p['images'] = array_merge($p['images'], $newImgs);
                }
            }
            unset($p);
            db_write('products', $products);
            flash('ok', 'تم حفظ التعديلات');
            redirect('index.php?page=vendor&section=products');
        }

        if ($action === 'vendor_add_section') {
            $stores = db_read('stores');
            foreach ($stores as &$s) if ($s['id'] === $store['id']) {
                $sections = $s['sections'];
                $sections[] = ['id'=>next_id($sections), 'title'=>trim((string)$_POST['title']), 'layout'=>$_POST['layout'] ?? 'grid2'];
                $s['sections'] = $sections;
            }
            unset($s);
            db_write('stores', $stores);
            flash('ok', 'تمت إضافة القسم');
            redirect('index.php?page=vendor&section=sections');
        }

        if ($action === 'vendor_rename_section') {
            $sid = (int)$_POST['section_id'];
            $stores = db_read('stores');
            foreach ($stores as &$s) if ($s['id'] === $store['id']) {
                foreach ($s['sections'] as &$sec) if ($sec['id'] === $sid) {
                    $sec['title'] = trim((string)$_POST['title']);
                    $sec['layout'] = $_POST['layout'] ?? $sec['layout'];
                }
                unset($sec);
            }
            unset($s);
            db_write('stores', $stores);
            flash('ok', 'تم حفظ القسم');
            redirect('index.php?page=vendor&section=sections');
        }

        if ($action === 'vendor_delete_section') {
            $sid = (int)$_POST['section_id'];
            $stores = db_read('stores');
            foreach ($stores as &$s) if ($s['id'] === $store['id']) {
                $s['sections'] = array_values(array_filter($s['sections'], fn($sec) => $sec['id'] !== $sid));
            }
            unset($s);
            db_write('stores', $stores);
            $products = db_read('products');
            foreach ($products as &$p) if ($p['store_id'] === $store['id'] && ($p['section_id'] ?? null) === $sid) $p['section_id'] = null;
            unset($p);
            db_write('products', $products);
            flash('ok', 'تم حذف القسم، ومنتجاته صارت بلا قسم');
            redirect('index.php?page=vendor&section=sections');
        }

        if ($action === 'vendor_move_section') {
            $sid = (int)$_POST['section_id'];
            $dir = $_POST['dir'] === 'up' ? -1 : 1;
            $stores = db_read('stores');
            foreach ($stores as &$s) if ($s['id'] === $store['id']) {
                $secs = $s['sections'];
                $idx = null;
                foreach ($secs as $i => $sec) if ($sec['id'] === $sid) $idx = $i;
                $swapWith = $idx + $dir;
                if ($idx !== null && $swapWith >= 0 && $swapWith < count($secs)) {
                    [$secs[$idx], $secs[$swapWith]] = [$secs[$swapWith], $secs[$idx]];
                }
                $s['sections'] = $secs;
            }
            unset($s);
            db_write('stores', $stores);
            redirect('index.php?page=vendor&section=sections');
        }

        if ($action === 'vendor_delete_product') {
            $pid = (int)$_POST['product_id'];
            $products = array_values(array_filter(db_read('products'), fn($p) => !($p['id'] === $pid && $p['store_id'] === $store['id'])));
            db_write('products', $products);
            flash('ok', 'تم حذف المنتج');
            redirect('index.php?page=vendor&section=products');
        }

        if ($action === 'vendor_update_order') {
            $oid = (int)$_POST['order_id'];
            $orders = db_read('orders');
            $buyerId = null; $newStatus = null;
            foreach ($orders as &$o) {
                if ($o['id'] === $oid && $o['store_id'] === $store['id']) {
                    $idx = array_search($o['status'], ORDER_STAGES, true);
                    if ($idx !== false && $idx < count(ORDER_STAGES) - 1) {
                        $o['status'] = ORDER_STAGES[$idx + 1];
                        $o['updated_at'] = time();
                        $buyerId = $o['buyer_id']; $newStatus = $o['status'];
                    }
                }
            }
            unset($o);
            db_write('orders', $orders);
            if ($buyerId) add_notification($buyerId, 'تحديث طلبك #' . $oid, 'طلبك صار: ' . $newStatus, 'index.php?page=orders', 'order');
            redirect('index.php?page=vendor&section=orders');
        }

        if ($action === 'vendor_save_theme') {
            $stores = db_read('stores');
            foreach ($stores as &$s) {
                if ($s['id'] === $store['id']) {
                    $s['theme'] = [
                        'primary'=>preg_match('/^#[0-9a-fA-F]{6}$/', (string)$_POST['primary']) ? $_POST['primary'] : $s['theme']['primary'],
                        'radius'=>radius_px($_POST['radius'] ?? 'rounded'),
                        'density'=>in_array($_POST['density'] ?? '', ['compact','comfortable','spacious'], true) ? $_POST['density'] : 'comfortable',
                        'layout'=>in_array($_POST['layout'] ?? '', ['grid2','grid3','list'], true) ? $_POST['layout'] : 'grid2',
                    ];
                    $logo = handle_upload('logo'); if ($logo) $s['logo'] = $logo;
                    $cover = handle_upload('cover'); if ($cover) $s['cover'] = $cover;
                }
            }
            unset($s);
            db_write('stores', $stores);
            flash('ok', 'تم حفظ تخصيص متجرك');
            redirect('index.php?page=vendor&section=theme');
        }

        if ($action === 'vendor_save_info') {
            $stores = db_read('stores');
            foreach ($stores as &$s) {
                if ($s['id'] === $store['id']) {
                    $s['description'] = trim((string)$_POST['description']);
                    $s['contact_phone'] = trim((string)$_POST['contact_phone']);
                    $s['contact_whatsapp'] = trim((string)$_POST['contact_whatsapp']);
                }
            }
            unset($s);
            db_write('stores', $stores);
            flash('ok', 'تم حفظ معلومات المتجر');
            redirect('index.php?page=vendor&section=info');
        }
    }

    // إجراءات الأدمن
    $adminActions = ['admin_approve','admin_reject','admin_topup','admin_toggle_featured','admin_collect_fee','admin_update_fee','admin_update_branding','admin_update_ai_key','admin_add_payment_method','admin_delete_payment_method','admin_approve_topup','admin_reject_topup','admin_approve_withdraw','admin_reject_withdraw','admin_resolve_complaint','admin_toggle_suspend','admin_add_category','admin_delete_category','admin_toggle_admin','admin_broadcast','admin_add_coupon','admin_delete_coupon','admin_update_backup','admin_backup_now','admin_update_db_config'];
    if (in_array($action, $adminActions, true)) {
        if (!is_admin_user()) redirect('index.php');

        if ($action === 'admin_approve') {
            $stores = db_read('stores');
            $owner = null;
            foreach ($stores as &$s) if ($s['id'] === (int)$_POST['store_id']) {
                $s['status'] = 'approved';
                $s['subscription_expires_at'] = time() + 86400 * SUBSCRIPTION_DAYS;
                $owner = $s['owner_user_id'];
            }
            unset($s);
            db_write('stores', $stores);
            if ($owner) add_notification($owner, 'تم قبول متجرك 🎉', 'تهانينا! متجرك فعّال الحين بالمنصة، اشتراكك يمتد ' . SUBSCRIPTION_DAYS . ' يوم.', 'index.php?page=vendor', 'store');
            flash('ok', 'تم قبول طلب التاجر');
            redirect('index.php?page=admin&section=applications');
        }
        if ($action === 'admin_reject') {
            $stores = db_read('stores');
            $owner = null;
            foreach ($stores as &$s) if ($s['id'] === (int)$_POST['store_id']) { $s['status'] = 'rejected'; $owner = $s['owner_user_id']; }
            unset($s);
            db_write('stores', $stores);
            if ($owner) add_notification($owner, 'تم رفض طلب متجرك', 'للأسف تمت مراجعة طلبك كتاجر ولم تتم الموافقة عليه.', 'index.php?page=account', 'store');
            flash('ok', 'تم رفض الطلب');
            redirect('index.php?page=admin&section=applications');
        }
        if ($action === 'admin_topup') {
            $uid = (int)$_POST['user_id'];
            $amount = (float)$_POST['amount'];
            $users = db_read('users');
            foreach ($users as &$u) if ($u['id'] === $uid) {
                $u['wallet'] = (float)$u['wallet'] + $amount;
                $u['wallet_log'][] = ['amount'=>$amount, 'note'=>trim((string)($_POST['note'] ?? 'شحن رصيد')), 'at'=>time()];
            }
            unset($u);
            db_write('users', $users);
            flash('ok', 'تم شحن الرصيد');
            redirect('index.php?page=admin&section=users');
        }
        if ($action === 'admin_toggle_featured') {
            $stores = db_read('stores');
            foreach ($stores as &$s) if ($s['id'] === (int)$_POST['store_id']) $s['featured'] = !$s['featured'];
            unset($s);
            db_write('stores', $stores);
            redirect('index.php?page=admin&section=stores');
        }
        if ($action === 'admin_toggle_suspend') {
            $sid = (int)$_POST['store_id'];
            $stores = db_read('stores');
            $owner = null; $nowSuspended = false;
            foreach ($stores as &$s) if ($s['id'] === $sid) {
                $s['suspended'] = !($s['suspended'] ?? false);
                $owner = $s['owner_user_id'];
                $nowSuspended = $s['suspended'];
            }
            unset($s);
            db_write('stores', $stores);
            if ($owner) add_notification($owner, $nowSuspended ? 'تم تعليق متجرك' : 'تم تفعيل متجرك', $nowSuspended ? 'قامت الإدارة بتعليق متجرك مؤقتاً، وما راح يظهر بالسوق لحد ما يتفعّل.' : 'رجع متجرك يظهر بالسوق من جديد.', 'index.php?page=vendor', 'store');
            flash('ok', $nowSuspended ? 'تم تعليق المتجر' : 'تم إعادة تفعيل المتجر');
            redirect('index.php?page=admin&section=stores');
        }
        if ($action === 'admin_add_category') {
            $settings = get_settings();
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name !== '' && !in_array($name, $settings['categories'], true)) $settings['categories'][] = $name;
            db_write('settings', $settings);
            flash('ok', 'تمت إضافة التصنيف');
            redirect('index.php?page=admin&section=settings');
        }
        if ($action === 'admin_delete_category') {
            $settings = get_settings();
            $settings['categories'] = array_values(array_filter($settings['categories'], fn($c) => $c !== ($_POST['name'] ?? '')));
            db_write('settings', $settings);
            flash('ok', 'تم حذف التصنيف');
            redirect('index.php?page=admin&section=settings');
        }
        if ($action === 'admin_toggle_admin') {
            $uid = (int)$_POST['user_id'];
            if ($uid !== current_user()['id']) {
                $users = db_read('users');
                foreach ($users as &$u) if ($u['id'] === $uid) $u['is_admin'] = !($u['is_admin'] ?? false);
                unset($u);
                db_write('users', $users);
                flash('ok', 'تم تحديث صلاحية المستخدم');
            }
            redirect('index.php?page=admin&section=users');
        }
        if ($action === 'admin_broadcast') {
            $title = trim((string)($_POST['title'] ?? ''));
            $body = trim((string)($_POST['body'] ?? ''));
            if ($title !== '' && $body !== '') {
                foreach (db_read('users') as $u) add_notification($u['id'], $title, $body, null, 'broadcast');
                flash('ok', 'تم إرسال الإشعار لكل المستخدمين');
            }
            redirect('index.php?page=admin&section=broadcast');
        }
        if ($action === 'admin_collect_fee') {
            $sid = (int)$_POST['store_id'];
            $fee = (float)get_settings()['monthly_fee'];
            $stores = db_read('stores');
            $owner = null;
            foreach ($stores as &$s) if ($s['id'] === $sid) {
                $s['earnings'] = (float)$s['earnings'] - $fee;
                $s['earnings_log'][] = ['amount'=>-$fee, 'note'=>'رسم اشتراك شهري (تجديد الاشتراك)', 'at'=>time()];
                $s['last_fee_at'] = time();
                $base = max((int)time(), (int)($s['subscription_expires_at'] ?? 0));
                $s['subscription_expires_at'] = $base + 86400 * SUBSCRIPTION_DAYS;
                $s['suspended'] = false;
                $owner = $s['owner_user_id'];
            }
            unset($s);
            db_write('stores', $stores);
            if ($owner) add_notification($owner, 'تجديد الاشتراك', 'تم تحصيل ' . money($fee) . ' وتجديد اشتراك متجرك ' . SUBSCRIPTION_DAYS . ' يوم إضافي.', 'index.php?page=vendor', 'store');
            flash('ok', 'تم تحصيل الرسم وتجديد الاشتراك ' . SUBSCRIPTION_DAYS . ' يوم');
            redirect('index.php?page=admin&section=stores');
        }
        if ($action === 'admin_approve_withdraw') {
            $rid = (int)($_POST['request_id'] ?? 0);
            $requests = db_read('withdraw_requests');
            $req = null;
            foreach ($requests as &$r) if ($r['id'] === $rid && $r['status'] === 'pending') { $r['status'] = 'approved'; $req = $r; }
            unset($r);
            if ($req) {
                db_write('withdraw_requests', $requests);
                $stores = db_read('stores');
                foreach ($stores as &$s) if ($s['id'] === $req['store_id']) {
                    $s['earnings'] = max(0, (float)$s['earnings'] - $req['amount']);
                    $s['earnings_log'][] = ['amount'=>-$req['amount'], 'note'=>'سحب نقدي معتمد عبر ' . $req['method'] . ' — ' . $req['account_name'] . ' (' . $req['account_number'] . ')', 'at'=>time()];
                }
                unset($s);
                db_write('stores', $stores);
                add_notification($req['owner_user_id'], 'تم قبول طلب سحبك', 'تم تحويل ' . money($req['amount']) . ' لحسابك عبر ' . $req['method'] . '.', 'index.php?page=vendor&section=withdraw', 'wallet');
            }
            flash('ok', 'تم اعتماد طلب السحب');
            redirect('index.php?page=admin&section=withdrawals');
        }
        if ($action === 'admin_reject_withdraw') {
            $rid = (int)($_POST['request_id'] ?? 0);
            $requests = db_read('withdraw_requests');
            $uid = null;
            foreach ($requests as &$r) if ($r['id'] === $rid && $r['status'] === 'pending') { $r['status'] = 'rejected'; $uid = $r['owner_user_id']; }
            unset($r);
            db_write('withdraw_requests', $requests);
            if ($uid) add_notification($uid, 'تم رفض طلب سحبك', 'للأسف تمت مراجعة طلب السحب ولم تتم الموافقة عليه.', 'index.php?page=vendor&section=withdraw', 'wallet');
            flash('ok', 'تم رفض طلب السحب');
            redirect('index.php?page=admin&section=withdrawals');
        }
        if ($action === 'admin_update_fee') {
            $settings = get_settings();
            $settings['monthly_fee'] = (float)($_POST['monthly_fee'] ?? 0);
            db_write('settings', $settings);
            flash('ok', 'تم تحديث قيمة الرسم الشهري');
            redirect('index.php?page=admin&section=settings');
        }
        if ($action === 'admin_update_branding') {
            $settings = get_settings();
            $name = trim((string)($_POST['site_name'] ?? ''));
            if ($name !== '') $settings['site_name'] = $name;
            $logo = handle_upload('site_logo');
            if ($logo) $settings['site_logo'] = $logo;
            db_write('settings', $settings);
            flash('ok', 'تم تحديث اسم وشعار الموقع');
            redirect('index.php?page=admin&section=settings');
        }
        if ($action === 'admin_update_ai_key') {
            $settings = get_settings();
            $settings['ai_api_key'] = trim((string)($_POST['ai_api_key'] ?? ''));
            db_write('settings', $settings);
            flash('ok', 'تم تحديث مفتاح المساعد الذكي');
            redirect('index.php?page=admin&section=settings');
        }
        if ($action === 'admin_update_backup') {
            $settings = get_settings();
            $settings['telegram_bot_token'] = trim((string)($_POST['telegram_bot_token'] ?? ''));
            $settings['telegram_chat_id'] = trim((string)($_POST['telegram_chat_id'] ?? ''));
            db_write('settings', $settings);
            flash('ok', 'تم حفظ إعدادات النسخ الاحتياطي');
            redirect('index.php?page=admin&section=backup');
        }
        if ($action === 'admin_backup_now') {
            $result = run_backup_now();
            flash($result['ok'] ? 'ok' : 'err', $result['msg']);
            redirect('index.php?page=admin&section=backup');
        }
        if ($action === 'admin_update_db_config') {
            $host = trim((string)($_POST['db_host'] ?? ''));
            $name = trim((string)($_POST['db_name'] ?? ''));
            $user = trim((string)($_POST['db_user'] ?? ''));
            $pass = (string)($_POST['db_pass'] ?? '');
            if ($host === '' || $name === '' || $user === '') {
                flash('err', 'الرجاء تعبئة المضيف واسم القاعدة واسم المستخدم — الموقع يعتمد على MySQL حصراً ولا يمكن تعطيله');
            } else {
                $err = db_test_connection($host, $name, $user, $pass);
                if ($err !== null) {
                    flash('err', $err);
                } else {
                    file_put_contents(DB_CONFIG_FILE, json_encode(['host'=>$host,'name'=>$name,'user'=>$user,'pass'=>$pass], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
                    flash('ok', 'تم اختبار الاتصال وحفظه');
                }
            }
            redirect('index.php?page=admin&section=settings');
        }
        if ($action === 'admin_add_coupon') {
            $settings = get_settings();
            $code = strtoupper(trim((string)($_POST['code'] ?? '')));
            $scope = (string)($_POST['scope'] ?? 'product');
            $pid = $scope === 'all' ? null : (int)($_POST['product_id'] ?? 0);
            $percent = max(1, min(90, (float)($_POST['percent'] ?? 0)));
            if ($code === '' || ($pid !== null && !find_product($pid))) {
                flash('err', 'الرجاء اختيار منتج وكتابة كود صحيح');
                redirect('index.php?page=admin&section=settings');
            }
            $coupons = $settings['coupons'];
            $coupons[] = ['id'=>next_id($coupons), 'code'=>$code, 'product_id'=>$pid, 'percent'=>$percent];
            $settings['coupons'] = $coupons;
            db_write('settings', $settings);
            flash('ok', 'تمت إضافة الكوبون');
            redirect('index.php?page=admin&section=settings');
        }
        if ($action === 'admin_delete_coupon') {
            $settings = get_settings();
            $cid = (int)($_POST['coupon_id'] ?? 0);
            $settings['coupons'] = array_values(array_filter($settings['coupons'], fn($c) => $c['id'] !== $cid));
            db_write('settings', $settings);
            flash('ok', 'تم حذف الكوبون');
            redirect('index.php?page=admin&section=settings');
        }
        if ($action === 'admin_add_payment_method') {
            $settings = get_settings();
            $methods = $settings['payment_methods'];
            $methods[] = [
                'id' => next_id($methods), 'name' => trim((string)$_POST['name']),
                'transfer_number' => trim((string)($_POST['transfer_number'] ?? '')),
                'agent_name' => trim((string)($_POST['agent_name'] ?? '')),
                'logo' => handle_upload('logo') ?? '',
                'qr_code' => handle_upload('qr_code') ?? '',
                'details' => trim((string)($_POST['details'] ?? '')),
            ];
            $settings['payment_methods'] = $methods;
            db_write('settings', $settings);
            flash('ok', 'تمت إضافة طريقة الدفع');
            redirect('index.php?page=admin&section=settings');
        }
        if ($action === 'admin_delete_payment_method') {
            $settings = get_settings();
            $mid = (int)$_POST['method_id'];
            $settings['payment_methods'] = array_values(array_filter($settings['payment_methods'], fn($m) => $m['id'] !== $mid));
            db_write('settings', $settings);
            flash('ok', 'تم حذف طريقة الدفع');
            redirect('index.php?page=admin&section=settings');
        }
        if ($action === 'admin_approve_topup') {
            $rid = (int)$_POST['request_id'];
            $requests = db_read('topup_requests');
            $req = null;
            foreach ($requests as &$r) if ($r['id'] === $rid && $r['status'] === 'pending') { $r['status'] = 'approved'; $req = $r; }
            unset($r);
            if ($req) {
                db_write('topup_requests', $requests);
                $users = db_read('users');
                foreach ($users as &$u) if ($u['id'] === $req['user_id']) {
                    $u['wallet'] = (float)$u['wallet'] + (float)$req['amount'];
                    $u['wallet_log'][] = ['amount'=>(float)$req['amount'], 'note'=>'شحن رصيد معتمد (' . $req['method'] . ')', 'at'=>time()];
                }
                unset($u);
                db_write('users', $users);
                add_notification($req['user_id'], 'تم قبول طلب شحنك', 'تمت إضافة ' . money($req['amount']) . ' لرصيدك.', 'index.php?page=account', 'wallet');
                flash('ok', 'تم قبول طلب الشحن وإضافة الرصيد');
            }
            redirect('index.php?page=admin&section=topups');
        }
        if ($action === 'admin_reject_topup') {
            $rid = (int)$_POST['request_id'];
            $requests = db_read('topup_requests');
            $uid = null;
            foreach ($requests as &$r) if ($r['id'] === $rid && $r['status'] === 'pending') { $r['status'] = 'rejected'; $uid = $r['user_id']; }
            unset($r);
            db_write('topup_requests', $requests);
            if ($uid) add_notification($uid, 'تم رفض طلب شحنك', 'للأسف تمت مراجعة طلب الشحن ولم تتم الموافقة عليه.', 'index.php?page=account', 'wallet');
            flash('ok', 'تم رفض طلب الشحن');
            redirect('index.php?page=admin&section=topups');
        }
        if ($action === 'admin_resolve_complaint') {
            $cid = (int)$_POST['complaint_id'];
            $complaints = db_read('complaints');
            $buyerId = null; $orderId = null;
            foreach ($complaints as &$c) if ($c['id'] === $cid) { $c['status'] = 'resolved'; $buyerId = $c['buyer_id']; $orderId = $c['order_id']; }
            unset($c);
            db_write('complaints', $complaints);
            if ($buyerId) add_notification($buyerId, 'تمت معالجة شكواك', 'تمت معالجة شكواك على الطلب #' . $orderId . '.', 'index.php?page=orders', 'complaint');
            redirect('index.php?page=admin&section=complaints');
        }
    }

    // إجراءات الإشعارات (متاحة لأي هوية مسجّلة: مشتري أو أدمن)
    $notifActions = ['notif_mark_read', 'notif_mark_all_read', 'notif_delete'];
    if (in_array($action, $notifActions, true)) {
        $recipient = my_notif_recipient();
        if ($recipient === null) redirect('index.php');
        $notifs = db_read('notifications');
        if ($action === 'notif_mark_read') {
            $nid = (int)($_POST['notif_id'] ?? 0);
            foreach ($notifs as &$n) if ($n['id'] === $nid && $n['recipient'] === $recipient) $n['read'] = true;
            unset($n);
        } elseif ($action === 'notif_mark_all_read') {
            foreach ($notifs as &$n) if ($n['recipient'] === $recipient) $n['read'] = true;
            unset($n);
        } elseif ($action === 'notif_delete') {
            $nid = (int)($_POST['notif_id'] ?? 0);
            $notifs = array_values(array_filter($notifs, fn($n) => !($n['id'] === $nid && $n['recipient'] === $recipient)));
        }
        db_write('notifications', $notifs);
        redirect('index.php?page=notifications');
    }

    redirect('index.php');
}

/* ===================== المساعد الذكي (DeepSeek) ===================== */
function ai_system_prompt(): string {
    $stores = db_read('stores');
    $liveStores = array_filter($stores, 'is_store_live');
    $liveStoreIds = array_column($liveStores, 'id');
    $names = array_map(fn($s) => $s['name'] . ' (' . $s['category'] . ')', $liveStores);
    $cats = implode('، ', get_categories());

    $products = array_values(array_filter(db_read('products'), fn($p) => in_array($p['store_id'], $liveStoreIds, true)));
    $sampleProducts = array_map(fn($p) => $p['name'] . ' — ' . money($p['discount_price'] ?? $p['price']), array_slice($products, 0, 25));

    $prompt = "أنت المساعد الذكي لتطبيق \"" . site_name() . "\" وهو سوق رقمي إلكتروني يضم عدة متاجر مستقلة. "
        . "أقسام المنتجات المتوفرة: {$cats}. المتاجر المتوفرة حالياً: " . implode('، ', $names) . ". "
        . "أمثلة من المنتجات المتوفرة فعلياً الآن (اذكر فقط منتجات من هذه القائمة إن سُئلت عن منتج محدد، ولا تختلق منتجات غير موجودة): " . implode('، ', $sampleProducts) . ". "
        . "المستخدم يشتري عبر محفظة داخلية (رصيد) يضيفه له الأدمن، والطلب يمر بأربع مراحل: " . implode(' ← ', ORDER_STAGES) . ". "
        . "أجب باختصار ووضوح باللهجة العربية الفصحى المبسطة، وساعد المستخدم بخصوص التسوق والمتاجر والطلبات وكيفية التسجيل كتاجر.";

    $u = current_user();
    if ($u) {
        $myOrders = array_values(array_filter(db_read('orders'), fn($o) => $o['buyer_id'] === $u['id']));
        usort($myOrders, fn($a, $b) => $b['created_at'] <=> $a['created_at']);
        $orderLines = array_map(function ($o) use ($stores) {
            $store = null; foreach ($stores as $s) if ($s['id'] === $o['store_id']) { $store = $s; break; }
            return 'طلب #' . $o['id'] . ' من ' . ($store['name'] ?? 'متجر') . ' بقيمة ' . money($o['total']) . ' — الحالة: ' . $o['status'];
        }, array_slice($myOrders, 0, 5));
        $prompt .= "\n\nبيانات المستخدم الحالي (فقط — لا تفصح عن بيانات أي مستخدم آخر مطلقاً): الاسم: " . $u['name'] . '. رصيد محفظته الحالي: ' . money($u['wallet']) . '. '
            . ($orderLines ? 'آخر طلباته: ' . implode(' | ', $orderLines) . '.' : 'ليس لديه أي طلبات بعد.');
    }
    return $prompt;
}

/* بحث بسيط بالكلمات المفتاحية عن أقرب منتج حقيقي لسؤال المستخدم، لإرفاقه كبطاقة
   منتج قابلة للضغط بالمحادثة بدل الاكتفاء برد نصي فقط. */
function ai_find_product(string $msg): ?array {
    $msg = mb_strtolower(trim($msg));
    if (mb_strlen($msg) < 2) return null;
    $liveStoreIds = array_column(array_filter(db_read('stores'), 'is_store_live'), 'id');
    $best = null; $bestScore = 0;
    foreach (db_read('products') as $p) {
        if (!in_array($p['store_id'], $liveStoreIds, true)) continue;
        $name = mb_strtolower($p['name']);
        $cat = mb_strtolower($p['category'] ?? '');
        $score = 0;
        if (mb_strpos($msg, $name) !== false) $score = 3;
        if ($cat !== '' && mb_strpos($msg, $cat) !== false) $score = max($score, 1);
        foreach (preg_split('/\s+/u', $name) ?: [] as $word) {
            if (mb_strlen($word) >= 3 && mb_strpos($msg, mb_strtolower($word)) !== false) $score = max($score, 2);
        }
        if ($score > $bestScore) { $bestScore = $score; $best = $p; }
    }
    if ($bestScore < 2 || !$best) return null;
    return [
        'id' => $best['id'], 'name' => $best['name'],
        'price' => money($best['discount_price'] ?? $best['price']),
        'image' => $best['images'][0] ?? '',
    ];
}

function ai_fallback_reply(string $msg): string {
    $m = mb_strtolower($msg);
    $rules = [
        'مرحبا'=>'أهلاً بيك! 👋 أقدر أساعدك تلقى متجر أو منتج، أو تعرف كيف تسوي طلب أو تصير تاجر بالتطبيق.',
        'رصيد'=>'تكدر تشحن رصيدك من "حسابي ← المحفظة"، اختر طريقة الدفع وارفع صورة وصل التحويل، وبعد موافقة الإدارة يضاف الرصيد لمحفظتك وتكدر تشتري من أي متجر بيه.',
        'طلب'=>'تكدر تتابع حالة طلباتك من صفحة "طلباتي"، وتمر كل طلبية بأربع مراحل: ' . implode(' ← ', ORDER_STAGES) . '.',
        'تاجر'=>'لتصير تاجر: روح لصفحة "حسابي" واضغط "تقديم طلب كتاجر"، عبّي بيانات متجرك وانتظر موافقة الإدارة.',
        'توصيل'=>'كل متجر يحدد طريقة التواصل والتوصيل الخاصة فيه، تكدر تتواصل مباشرة مع المتجر من صفحته عبر واتساب.',
        'شكرا'=>'العفو! تسعدني أي مساعدة ثانية. 🌟',
    ];
    foreach ($rules as $key => $reply) if (mb_strpos($m, $key) !== false) return $reply;
    return 'ما فهمت قصدك بالضبط 🙂 تكدر تسألني عن المتاجر، المنتجات، الرصيد، الطلبات، أو كيفية التسجيل كتاجر.';
}

function ai_api_key(): string {
    $k = trim((string)(get_settings()['ai_api_key'] ?? ''));
    return $k !== '' ? $k : DEEPSEEK_API_KEY;
}

function ai_call_deepseek(array $messages): ?string {
    if (ai_api_key() === '') return null;
    $ch = curl_init(DEEPSEEK_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . ai_api_key()],
        CURLOPT_POSTFIELDS => json_encode(['model'=>DEEPSEEK_MODEL, 'messages'=>$messages, 'stream'=>false], JSON_UNESCAPED_UNICODE),
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false || $code !== 200) return null;
    $data = json_decode($res, true);
    return $data['choices'][0]['message']['content'] ?? null;
}


/* ===================== مكوّنات العرض المشتركة ===================== */
function render_store_card(array $s): string {
    $img = $s['logo'] ? UPLOAD_URL . '/' . basename($s['logo']) : '';
    ob_start(); ?>
    <a class="store-card an" href="index.php?page=store&id=<?= $s['id'] ?>">
        <div class="store-card-logo" style="background:<?= $img ? 'transparent' : 'var(--gradient)' ?>">
            <?php if ($img): ?><img src="<?= h($s['logo']) ?>" alt=""><?php else: ?><i class="fas <?= category_icon($s['category']) ?>"></i><?php endif; ?>
        </div>
        <div class="store-card-info">
            <h4><?= h($s['name']) ?><?php if ($s['status']==='approved'): ?> <i class="fas fa-circle-check verified" title="متجر معتمد"></i><?php endif; ?></h4>
            <p><?= h($s['category']) ?> · <?= render_stars(store_rating($s['id'])) ?></p>
        </div>
        <i class="fas fa-chevron-left cv"></i>
    </a>
    <?php return ob_get_clean();
}

function render_store_tile(array $s): string {
    $img = $s['logo'] ? UPLOAD_URL . '/' . basename($s['logo']) : '';
    ob_start(); ?>
    <a class="store-tile an" href="index.php?page=store&id=<?= $s['id'] ?>">
        <div class="store-tile-icon" style="background:<?= $img ? 'transparent' : 'var(--gradient)' ?>">
            <?php if ($img): ?><img src="<?= h($s['logo']) ?>" alt=""><?php else: ?><i class="fas <?= category_icon($s['category']) ?>"></i><?php endif; ?>
        </div>
        <span class="store-tile-name"><?= h($s['name']) ?></span>
    </a>
    <?php return ob_get_clean();
}

function render_product_card(array $p): string {
    $store = find_store($p['store_id']);
    $img = $p['images'][0] ?? '';
    $hasDiscount = !empty($p['discount_price']) && $p['discount_price'] < $p['price'];
    $pct = $hasDiscount ? round((1 - $p['discount_price'] / $p['price']) * 100) : 0;
    ob_start(); ?>
    <a class="prod-card an" href="index.php?page=product&id=<?= $p['id'] ?>">
        <div class="prod-card-img">
            <?php if ($img): ?><img src="<?= h($img) ?>" alt=""><?php else: ?><i class="fas <?= category_icon($p['category']) ?>"></i><?php endif; ?>
            <?php if ($hasDiscount): ?><span class="prod-badge">-<?= $pct ?>%</span><?php endif; ?>
        </div>
        <div class="prod-card-info">
            <h4><?= h($p['name']) ?></h4>
            <p class="prod-store"><?= h($store['name'] ?? '') ?></p>
            <?php $pr = product_rating($p['id']); if ($pr !== null): ?><div style="font-size:.65rem"><?= render_stars($pr) ?></div><?php endif; ?>
            <div class="prod-price">
                <?php if ($hasDiscount): ?>
                    <span class="now"><?= money($p['discount_price']) ?></span>
                    <span class="was"><?= money($p['price']) ?></span>
                <?php else: ?>
                    <span class="now"><?= money($p['price']) ?></span>
                <?php endif; ?>
            </div>
        </div>
    </a>
    <?php return ob_get_clean();
}

function render_product_row(array $p): string {
    $img = $p['images'][0] ?? '';
    $hasDiscount = !empty($p['discount_price']) && $p['discount_price'] < $p['price'];
    ob_start(); ?>
    <a class="store-card an" href="index.php?page=product&id=<?= $p['id'] ?>">
        <div class="store-card-logo" style="background:<?= $img ? 'transparent' : 'var(--gradient)' ?>">
            <?php if ($img): ?><img src="<?= h($img) ?>" alt=""><?php else: ?><i class="fas <?= category_icon($p['category']) ?>"></i><?php endif; ?>
        </div>
        <div class="store-card-info">
            <h4><?= h($p['name']) ?></h4>
            <p class="prod-price">
                <?php if ($hasDiscount): ?><span class="now"><?= money($p['discount_price']) ?></span> <span class="was"><?= money($p['price']) ?></span>
                <?php else: ?><span class="now"><?= money($p['price']) ?></span><?php endif; ?>
            </p>
        </div>
        <i class="fas fa-chevron-left cv"></i>
    </a>
    <?php return ob_get_clean();
}

function render_section(string $icon, string $title, string $inner, ?string $allHref = null): string {
    ob_start(); ?>
    <section class="sec an">
        <div class="sh">
            <h3><i class="fas <?= $icon ?>"></i> <?= h($title) ?></h3>
            <?php if ($allHref): ?><a class="al" href="<?= $allHref ?>">الكل ←</a><?php endif; ?>
        </div>
        <?= $inner ?>
    </section>
    <?php return ob_get_clean();
}

function order_stepper(string $status): string {
    $idx = array_search($status, ORDER_STAGES, true);
    ob_start(); ?>
    <div class="stepper">
        <?php foreach (ORDER_STAGES as $i => $stage): ?>
            <div class="step <?= $i <= $idx ? 'done' : '' ?> <?= $i === $idx ? 'current' : '' ?>">
                <span class="dot"></span><span class="lbl"><?= h($stage) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php return ob_get_clean();
}

function app_shell_inner(string $body, ?string $activeTab = 'home'): string {
    $cartCount = array_sum($_SESSION['cart'] ?? []);
    $unreadCount = count(array_filter(my_notifications(), fn($n) => !$n['read']));
    $flashes = take_flashes();
    ob_start();
    ?>
<header class="topbar an">
  <a href="index.php" class="logo"><?php if (site_logo_url()): ?><img src="<?= h(site_logo_url()) ?>" alt="" class="logo-img"><?php else: ?><i class="fas fa-store"></i><?php endif; ?> <?= h(site_name()) ?></a>
  <div class="topbar-right">
    <a class="icon-btn" href="index.php?page=notifications" title="الإشعارات"><i class="fas fa-bell"></i><?php if ($unreadCount): ?><span class="badge"><?= $unreadCount ?></span><?php endif; ?></a>
    <a class="icon-btn" href="index.php?page=cart" title="السلة"><i class="fas fa-cart-shopping"></i><?php if ($cartCount): ?><span class="badge"><?= $cartCount ?></span><?php endif; ?></a>
  </div>
</header>

<div class="install-banner" id="installBanner" hidden>
  <i class="fas fa-mobile-screen-button"></i>
  <span>ثبّت تطبيق <?= h(site_name()) ?> على جهازك لتصفح أسرع</span>
  <button class="btn btn-sm" id="installBtn" type="button" style="width:auto">تثبيت</button>
  <button class="icon-btn" id="installDismiss" type="button" style="width:28px;height:28px"><i class="fas fa-xmark"></i></button>
</div>

<main class="content z1">
  <?php foreach ($flashes as $f): ?>
    <div class="flash flash-<?= h($f['type']) ?> an"><?= h($f['text']) ?></div>
  <?php endforeach; ?>
  <?= $body ?>
</main>

<nav class="tabbar">
  <a class="tab <?= $activeTab==='home'?'active':'' ?>" href="index.php"><i class="fas fa-house"></i><span>الرئيسية</span></a>
  <a class="tab <?= $activeTab==='stores'?'active':'' ?>" href="index.php?page=stores"><i class="fas fa-shop"></i><span>المتاجر</span></a>
  <a class="tab tab-ai <?= $activeTab==='ai'?'active':'' ?>" href="index.php?page=ai"><i class="fas fa-sparkles"></i><span>المساعد الذكي</span></a>
  <a class="tab <?= $activeTab==='orders'?'active':'' ?>" href="index.php?page=orders"><i class="fas fa-receipt"></i><span>طلباتي</span></a>
  <a class="tab <?= $activeTab==='account'?'active':'' ?>" href="index.php?page=account"><i class="fas fa-user"></i><span>حسابي</span></a>
</nav>

<div class="sheet-backdrop" id="sheetBackdrop" onclick="closeSheets()"></div>
<div class="confirm-sheet" id="logoutSheet">
    <h4>متأكد إنك تريد تسجيل الخروج؟</h4>
    <div class="confirm-actions">
        <button class="btn btn-outline" onclick="closeSheets()">إلغاء</button>
        <form method="post" style="width:100%"><input type="hidden" name="action" value="logout"><button class="btn btn-danger" type="submit">تسجيل الخروج</button></form>
    </div>
</div>
<?= admin_sidebar_html() ?>
    <?php
    return ob_get_clean();
}

function full_document(string $title, string $inner): void {
    ?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
<title><?= h($title) ?> — <?= h(site_name()) ?></title>
<link rel="manifest" href="manifest.php">
<meta name="theme-color" content="#f2b100">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= h(site_name()) ?>">
<link rel="apple-touch-icon" href="<?= h(site_logo_url() ?? 'assets/icon-192.png') ?>">
<link rel="icon" href="<?= h(site_logo_url() ?? 'assets/icon-192.png') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<?php if (GOOGLE_CLIENT_ID !== ''): ?><script src="https://accounts.google.com/gsi/client" async defer></script><?php endif; ?>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<div class="bg-orb bo1"></div><div class="bg-orb bo2"></div>
<div class="offline-banner" id="offlineBanner" hidden><i class="fas fa-wifi"></i> <span>لا يوجد اتصال بالإنترنت — تتصفح نسخة محفوظة مؤقتاً</span></div>
<div id="app-root"><?= $inner ?></div>
<script>window.APP_CONFIG = <?= json_encode(['siteName' => site_name(), 'googleClientId' => GOOGLE_CLIENT_ID], JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="assets/app.js"></script>
</body>
</html>
    <?php
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'chat') {
    header('Content-Type: application/json; charset=utf-8');
    $raw = json_decode(file_get_contents('php://input'), true) ?? [];
    $userMsg = trim((string)($raw['message'] ?? ''));
    $history = is_array($raw['history'] ?? null) ? $raw['history'] : [];
    if ($userMsg === '') { echo json_encode(['reply'=>'اكتب سؤالك أولاً 🙂']); exit; }

    $messages = [['role'=>'system', 'content'=>ai_system_prompt()]];
    foreach (array_slice($history, -6) as $h) {
        if (in_array($h['role'] ?? '', ['user','assistant'], true)) $messages[] = ['role'=>$h['role'], 'content'=>(string)$h['content']];
    }
    $messages[] = ['role'=>'user', 'content'=>$userMsg];

    $reply = ai_call_deepseek($messages);
    $source = 'ai';
    if ($reply === null) { $reply = ai_fallback_reply($userMsg); $source = 'fallback'; }

    $me = current_user();
    if ($me) {
        $logs = db_read('ai_logs');
        $logs[] = ['id'=>next_id($logs), 'user_id'=>$me['id'], 'message'=>$userMsg, 'reply'=>$reply, 'source'=>$source, 'at'=>time()];
        db_write('ai_logs', $logs);
    }

    $product = ai_find_product($userMsg);
    echo json_encode(['reply'=>$reply, 'source'=>$source, 'product'=>$product], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ===================== صفحة الدخول ===================== */
function render_captcha(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < 5; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
    $_SESSION['reg_captcha'] = $code;
    ob_start(); ?>
    <div class="captcha-box">
        <?php foreach (str_split($code) as $ch):
            $rot = random_int(-18, 18); $dy = random_int(-4, 4); ?>
            <span style="transform:rotate(<?= $rot ?>deg) translateY(<?= $dy ?>px)"><?= h($ch) ?></span>
        <?php endforeach; ?>
    </div>
    <?php return ob_get_clean();
}

function render_google_button(): string {
    if (GOOGLE_CLIENT_ID === '') return '';
    ob_start(); ?>
    <div class="auth-divider an"><span>أو</span></div>
    <div id="googleBtnContainer" class="google-btn-wrap"></div>
    <?php return ob_get_clean();
}

/* أيقونات زخرفية تمثّل تصنيفات المتجر نفسه بدل شعارات شركات عالمية (Adidas/Nike/Apple/Samsung
   بصور المرجع) — لا يصح تضمين علامات تجارية لشركات لا علاقة لها بهذا السوق. */
function render_auth_hero(bool $small = false): string {
    $icons = ['fa-shirt', 'fa-mobile-screen', 'fa-wand-magic-sparkles', 'fa-kitchen-set'];
    ob_start(); ?>
    <div class="auth-hero<?= $small ? ' sm' : '' ?> an">
        <div class="phone-mock">
            <div class="phone-mock-screen">
                <div class="phone-mock-brand"><?php if (site_logo_url()): ?><img src="<?= h(site_logo_url()) ?>" alt="" style="width:14px;height:14px;border-radius:4px;object-fit:cover;vertical-align:middle;margin-left:3px"><?php endif; ?> <?= h(site_name()) ?></div>
                <div class="phone-mock-search"><i class="fas fa-magnifying-glass" style="font-size:.5rem"></i></div>
                <div class="phone-mock-cats"><span><i class="fas fa-shirt"></i></span><span><i class="fas fa-mobile-screen"></i></span><span><i class="fas fa-wand-magic-sparkles"></i></span><span><i class="fas fa-kitchen-set"></i></span></div>
                <div class="phone-mock-row">أفضل المتاجر</div>
                <div class="phone-mock-cards"><span><i class="fas fa-store"></i></span><span><i class="fas fa-store"></i></span></div>
            </div>
        </div>
        <?php foreach ($icons as $i => $ic): ?>
        <div class="auth-hero-bubble b<?= $i + 1 ?>"><i class="fas <?= $ic ?>"></i></div>
        <?php endforeach; ?>
    </div>
    <?php return ob_get_clean();
}

function render_auth_logo(): string {
    ob_start(); ?>
    <div class="login-logo an" style="font-size:1.4rem"><?php if (site_logo_url()): ?><img src="<?= h(site_logo_url()) ?>" alt="" class="login-logo-img"><?php else: ?><i class="fas fa-store"></i><?php endif; ?> <?= h(site_name()) ?></div>
    <?php return ob_get_clean();
}

function welcome_inner(): string {
    ob_start(); ?>
<div class="auth-page">
  <div class="auth-blob auth-blob-tl"></div>
  <div class="auth-blob auth-blob-br"></div>
  <a href="index.php?page=login" class="auth-skip an">تخطي <i class="fas fa-arrow-left"></i></a>
  <?= render_auth_hero(false) ?>
  <h1 class="auth-heading an">تسوق من متاجرك المفضلة واكتشف أفضل المتاجر</h1>
  <p class="auth-heading-sub an">كل المتاجر والمنتجات بمكان واحد، بتجربة سلسة وسريعة</p>
  <div class="auth-dots an"><span class="active"></span><span></span><span></span></div>
  <div style="flex:1"></div>
  <a href="index.php?page=register" class="btn an" style="max-width:360px;margin:0 auto;display:flex"><i class="fas fa-arrow-left"></i> ابدأ الآن</a>
</div>
    <?php return ob_get_clean();
}

function login_inner(): string {
    $flashes = take_flashes();
    ob_start();
    ?>
<div class="auth-page" style="justify-content:center">
  <div class="auth-blob auth-blob-tl"></div>
  <div class="auth-blob auth-blob-br"></div>
  <?= render_auth_logo() ?>
  <?= render_auth_hero(true) ?>
  <h1 class="auth-heading an">مرحبًا بعودتك</h1>
  <p class="auth-heading-sub an">سجل دخولك للمتابعة واستكشاف أحدث العروض</p>
  <?php foreach ($flashes as $f): ?><div class="flash flash-<?= h($f['type']) ?> an" style="max-width:360px;width:100%;margin:0 auto 12px"><?= h($f['text']) ?></div><?php endforeach; ?>
  <div class="login-card an" style="--ad:.1s;margin:0 auto">
    <form method="post">
      <input type="hidden" name="action" value="login">
      <div class="field field-icon-wrap"><label>البريد الإلكتروني أو رقم الجوال</label><input type="text" name="identifier" placeholder="البريد الإلكتروني أو رقم الجوال" required autofocus><i class="fas fa-envelope field-ic"></i></div>
      <div class="field field-icon-wrap"><label>كلمة المرور</label><input type="password" name="password" required><button type="button" class="pw-toggle"><i class="fas fa-eye-slash"></i></button></div>
      <details><summary class="auth-forgot">نسيت كلمة المرور؟</summary><p class="auth-forgot-note">تواصل مع إدارة <?= h(site_name()) ?> لإعادة تعيين كلمة المرور.</p></details>
      <button class="btn" type="submit"><i class="fas fa-arrow-left"></i> تسجيل الدخول</button>
    </form>
    <?= render_google_button() ?>
  </div>
  <div class="login-admin-link an" style="--ad:.2s">ليس لديك حساب؟ <a href="index.php?page=register">إنشاء حساب جديد</a></div>
</div>
    <?php
    return ob_get_clean();
}

function register_inner(): string {
    $flashes = take_flashes();
    $old = $_SESSION['reg_old'] ?? [];
    unset($_SESSION['reg_old']);
    ob_start();
    ?>
<div class="auth-page" style="justify-content:center">
  <div class="auth-blob auth-blob-tl"></div>
  <div class="auth-blob auth-blob-br"></div>
  <?= render_auth_logo() ?>
  <?= render_auth_hero(true) ?>
  <h1 class="auth-heading an">إنشاء حساب جديد</h1>
  <p class="auth-heading-sub an">انضم إلى ملايين المتسوقين واستمتع بتجربة تسوق فريدة</p>
  <?php foreach ($flashes as $f): ?><div class="flash flash-<?= h($f['type']) ?> an" style="max-width:360px;width:100%;margin:0 auto 12px"><?= h($f['text']) ?></div><?php endforeach; ?>
  <div class="login-card an" style="--ad:.1s;margin:0 auto">
    <form method="post">
      <input type="hidden" name="action" value="register">
      <div class="field field-icon-wrap"><label>الاسم الكامل</label><input type="text" name="name" value="<?= h($old['name'] ?? '') ?>" placeholder="أدخل اسمك الكامل" required><i class="fas fa-user field-ic"></i></div>
      <div class="field field-icon-wrap"><label>البريد الإلكتروني</label><input type="email" name="email" value="<?= h($old['email'] ?? '') ?>" placeholder="example@domain.com" required><i class="fas fa-envelope field-ic"></i></div>
      <div class="field field-icon-wrap"><label>رقم الهاتف</label><input type="tel" name="phone" value="<?= h($old['phone'] ?? '') ?>" placeholder="أدخل رقم هاتفك" required><i class="fas fa-phone field-ic"></i></div>
      <div class="field field-icon-wrap"><label>كلمة المرور</label><input type="password" name="password" minlength="6" placeholder="أدخل كلمة مرور قوية" required><button type="button" class="pw-toggle"><i class="fas fa-eye-slash"></i></button></div>
      <div class="field">
        <label>كود التحقق</label>
        <?= render_captcha() ?>
        <input type="text" name="captcha" placeholder="اكتب الكود اللي فوق" required autocomplete="off" style="margin-top:8px">
      </div>
      <button class="btn" type="submit"><i class="fas fa-arrow-left"></i> إنشاء الحساب</button>
    </form>
    <?= render_google_button() ?>
  </div>
  <div class="login-admin-link an" style="--ad:.2s">لديك حساب بالفعل؟ <a href="index.php?page=login">تسجيل الدخول</a></div>
</div>
    <?php
    return ob_get_clean();
}

/* ===================== الصفحة الرئيسية ===================== */
function page_home(): string {
    $user = current_user();
    $stores = array_values(array_filter(db_read('stores'), 'is_store_live'));
    $liveStoreIds = array_column($stores, 'id');
    $products = array_values(array_filter(db_read('products'), fn($p) => in_array($p['store_id'], $liveStoreIds, true)));
    usort($stores, fn($a,$b) => $b['created_at'] <=> $a['created_at']);
    $featured = array_values(array_filter($stores, fn($s) => $s['featured']));
    $newest = array_slice($stores, 0, 12);
    usort($products, fn($a,$b) => $b['created_at'] <=> $a['created_at']);
    $newProducts = array_slice($products, 0, 8);
    $deals = array_values(array_filter($products, fn($p) => !empty($p['discount_price']) && $p['discount_price'] < $p['price']));

    ob_start(); ?>
    <div class="wallet-card an" style="--ad:.05s">
        <div class="wallet-top">
            <div><div class="greet">مرحباً <?= h($user['name']) ?> 👋</div><div class="wallet-bal"><?= money($user['wallet']) ?></div></div>
            <i class="fas fa-wallet" style="font-size:1.6rem;opacity:.7"></i>
        </div>
        <div class="wallet-note">رصيدك الحالي بالتطبيق — لشحن رصيدك تواصل مع إدارة <?= h(APP_NAME) ?></div>
    </div>

    <form method="get" style="margin-bottom:14px" class="an" style="--ad:.1s">
        <input type="hidden" name="page" value="stores">
        <div class="search-bar"><i class="fas fa-magnifying-glass"></i><input type="text" name="q" placeholder="ابحث عن متجر أو قسم..."></div>
    </form>

    <div class="hscroll an" style="--ad:.12s;margin-bottom:20px">
        <?php foreach (get_categories() as $c): ?>
            <a class="cat-chip" href="index.php?page=stores&cat=<?= urlencode($c) ?>"><i class="fas <?= category_icon($c) ?>"></i><?= h($c) ?></a>
        <?php endforeach; ?>
    </div>

    <?php
    $inner = '<div class="hscroll">' . implode('', array_map(fn($s) => '<div style="min-width:220px">' . render_store_card($s) . '</div>', array_slice($featured, 0, 6))) . '</div>';
    echo $featured ? render_section('fa-star', 'متاجر مميزة', $inner, 'index.php?page=stores') : '';

    $inner = '<div class="store-grid6">' . implode('', array_map('render_store_tile', $newest)) . '</div>';
    echo render_section('fa-clock', 'أحدث المتاجر', $inner, 'index.php?page=stores');

    $inner = '<div class="hgrid">' . implode('', array_map('render_product_card', $newProducts)) . '</div>';
    echo $newProducts ? render_section('fa-bolt', 'منتجات جديدة', $inner) : '';

    if ($deals) {
        $inner = '<div class="hgrid">' . implode('', array_map('render_product_card', array_slice($deals, 0, 8))) . '</div>';
        echo render_section('fa-tags', 'العروض والخصومات', $inner);
    }

    return ob_get_clean();
}

/* ===================== قسم المتاجر ===================== */
function page_stores(): string {
    $stores = array_values(array_filter(db_read('stores'), 'is_store_live'));
    $cat = $_GET['cat'] ?? '';
    $q = trim((string)($_GET['q'] ?? ''));
    if ($cat !== '') $stores = array_values(array_filter($stores, fn($s) => $s['category'] === $cat));
    if ($q !== '') $stores = array_values(array_filter($stores, fn($s) => mb_strpos(mb_strtolower($s['name']), mb_strtolower($q)) !== false));

    ob_start(); ?>
    <h2 style="font-size:1.05rem;font-weight:800;margin:6px 0 14px" class="an">كل المتاجر</h2>
    <form method="get" class="an" style="margin-bottom:12px">
        <input type="hidden" name="page" value="stores">
        <?php if ($cat): ?><input type="hidden" name="cat" value="<?= h($cat) ?>"><?php endif; ?>
        <div class="search-bar"><i class="fas fa-magnifying-glass"></i><input type="text" name="q" value="<?= h($q) ?>" placeholder="ابحث عن متجر..."></div>
    </form>
    <div class="chips an">
        <a class="chip <?= $cat===''?'active':'' ?>" href="index.php?page=stores">الكل</a>
        <?php foreach (get_categories() as $c): ?>
            <a class="chip <?= $cat===$c?'active':'' ?>" href="index.php?page=stores&cat=<?= urlencode($c) ?>"><?= h($c) ?></a>
        <?php endforeach; ?>
    </div>
    <?php if (!$stores): ?>
        <div class="empty-state an"><i class="fas fa-shop-slash"></i><p>لا توجد متاجر مطابقة</p></div>
    <?php else: ?>
        <div class="store-grid6 an"><?php foreach ($stores as $s): ?><?= render_store_tile($s) ?><?php endforeach; ?></div>
    <?php endif;
    return ob_get_clean();
}

/* ===================== صفحة المتجر ===================== */
function page_store(): string {
    $id = (int)($_GET['id'] ?? 0);
    $store = find_store($id);
    if (!$store || !is_store_live($store)) return '<div class="empty-state an"><i class="fas fa-triangle-exclamation"></i><p>هذا المتجر غير متوفر</p></div>';
    $products = store_products($id);
    $user = current_user();
    $isFav = $user && in_array($id, $user['favorites']['stores'] ?? [], true);
    $theme = $store['theme'];

    ob_start(); ?>
    <div class="an" style="--pv-color:<?= h($theme['primary']) ?>;--store-radius:<?= (int)$theme['radius'] ?>px">
    <div class="store-hero">
        <div class="store-hero-cover" style="background:<?= $store['cover'] ? 'none' : $theme['primary'] ?>">
            <?php if ($store['cover']): ?><img src="<?= h($store['cover']) ?>" alt=""><?php endif; ?>
        </div>
        <div class="store-hero-body">
            <div class="store-hero-logo" style="background:<?= $store['logo'] ? 'transparent' : $theme['primary'] ?>;border-radius:<?= (int)$theme['radius'] ?>px">
                <?php if ($store['logo']): ?><img src="<?= h($store['logo']) ?>" alt=""><?php else: ?><i class="fas <?= category_icon($store['category']) ?>"></i><?php endif; ?>
            </div>
            <div class="row-between">
                <div>
                <h2 style="font-size:1.05rem;font-weight:800"><?= h($store['name']) ?> <i class="fas fa-circle-check verified"></i></h2>
                <div style="font-size:.78rem;margin-top:2px"><?= render_stars(store_rating($store['id'])) ?></div>
                </div>
                <?php if ($user): ?>
                <form method="post"><input type="hidden" name="action" value="toggle_favorite"><input type="hidden" name="type" value="store"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="back" value="index.php?page=store&id=<?= $id ?>">
                    <button class="icon-btn" style="<?= $isFav?'color:var(--danger)':'' ?>"><i class="fa<?= $isFav?'s':'r' ?> fa-heart"></i></button>
                </form>
                <?php endif; ?>
            </div>
            <p style="font-size:.78rem;color:var(--muted);margin-top:4px;line-height:1.7"><?= h($store['description']) ?></p>
        </div>
    </div>
    <?php if (!$products): ?>
        <div class="empty-state"><i class="fas fa-box-open"></i><p>لا توجد منتجات بعد</p></div>
    <?php else:
        $groups = [];
        foreach ($store['sections'] as $sec) $groups[] = ['title'=>$sec['title'], 'layout'=>$sec['layout'], 'items'=>array_values(array_filter($products, fn($p) => ($p['section_id'] ?? null) === $sec['id']))];
        $unsectioned = array_values(array_filter($products, fn($p) => empty($p['section_id']) || !in_array($p['section_id'], array_column($store['sections'], 'id'), true)));
        if ($unsectioned) $groups[] = ['title'=>$store['sections'] ? 'منتجات أخرى' : 'منتجات المتجر', 'layout'=>$theme['layout'], 'items'=>$unsectioned];
        $groups = array_values(array_filter($groups, fn($g) => $g['items']));
        if (count($groups) > 1): ?>
        <div class="chips an" style="margin:14px 0">
            <?php foreach ($groups as $gi => $g): ?><a class="chip" href="#sec-<?= $gi ?>"><?= h($g['title']) ?></a><?php endforeach; ?>
        </div>
        <?php endif;
        foreach ($groups as $gi => $g): $lc = ['grid2'=>'grid2','grid3'=>'grid3','list'=>''][$g['layout']] ?? 'grid2'; ?>
        <h3 id="sec-<?= $gi ?>" style="font-size:.9rem;font-weight:800;margin:18px 0 10px"><?= h($g['title']) ?> (<?= count($g['items']) ?>)</h3>
        <?php if ($lc): ?>
            <div class="<?= $lc ?>"><?= implode('', array_map('render_product_card', $g['items'])) ?></div>
        <?php else: foreach ($g['items'] as $p): ?>
            <?= render_product_row($p) ?>
        <?php endforeach; endif; ?>
        <?php endforeach;
    endif; ?>
    </div>
    <?php return ob_get_clean();
}

/* ===================== صفحة المنتج ===================== */
function page_product(): string {
    $id = (int)($_GET['id'] ?? 0);
    $p = find_product($id);
    if (!$p) return '<div class="empty-state an"><i class="fas fa-triangle-exclamation"></i><p>المنتج غير موجود</p></div>';
    $store = find_store($p['store_id']);
    $hasDiscount = !empty($p['discount_price']) && $p['discount_price'] < $p['price'];
    $images = $p['images'] ?: [''];
    $similar = array_values(array_filter(db_read('products'), fn($x) => $x['id'] !== $id && ($x['store_id'] === $p['store_id'] || $x['category'] === $p['category'])));
    $similar = array_slice($similar, 0, 4);

    ob_start(); ?>
    <div class="an">
    <div class="gallery-main" onclick="if('<?= h($images[0]) ?>')openLightbox('<?= h($images[0]) ?>')">
        <?php if ($images[0]): ?><img id="mainImg" src="<?= h($images[0]) ?>" alt=""><?php else: ?><i class="fas <?= category_icon($p['category']) ?>"></i><?php endif; ?>
    </div>
    <?php if (count($images) > 1): ?>
    <div class="gallery-thumbs">
        <?php foreach ($images as $i => $img): ?><img src="<?= h($img) ?>" class="<?= $i===0?'active':'' ?>" onclick="swapMain('<?= h($img) ?>',this)"><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <a href="index.php?page=store&id=<?= $store['id'] ?>" style="font-size:.72rem;color:var(--accent);font-weight:700"><i class="fas fa-store"></i> <?= h($store['name'] ?? '') ?></a>
    <h2 style="font-size:1.1rem;font-weight:800;margin:8px 0 6px"><?= h($p['name']) ?></h2>
    <div style="font-size:.72rem;margin-bottom:6px"><?= render_stars(product_rating($p['id'])) ?></div>
    <div class="prod-price" style="margin-bottom:12px">
        <?php if ($hasDiscount): ?>
            <span class="now" style="font-size:1.2rem"><?= money($p['discount_price']) ?></span>
            <span class="was"><?= money($p['price']) ?></span>
        <?php else: ?>
            <span class="now" style="font-size:1.2rem"><?= money($p['price']) ?></span>
        <?php endif; ?>
    </div>
    <p style="font-size:.82rem;line-height:1.9;color:var(--muted);margin-bottom:18px"><?= nl2br(h($p['description'])) ?></p>

    <form method="post" action="index.php?page=product&id=<?= $id ?>">
        <input type="hidden" name="action" value="add_to_cart">
        <input type="hidden" name="product_id" value="<?= $id ?>">
        <input type="hidden" name="back" value="index.php?page=product&id=<?= $id ?>">
        <div class="qty-box">
            <button type="button" onclick="qtyChange(-1)">−</button>
            <input id="qtyInput" name="qty" value="1" style="width:30px;text-align:center;border:none;background:none;font-weight:800">
            <button type="button" onclick="qtyChange(1)">+</button>
        </div>
        <button class="btn" type="submit"><i class="fas fa-cart-plus"></i> أضف إلى السلة</button>
    </form>

    <?php if ($similar): ?>
    <div style="margin-top:24px"><h3 style="font-size:.88rem;font-weight:800;margin-bottom:10px">منتجات مشابهة</h3>
    <div class="hgrid"><?= implode('', array_map('render_product_card', $similar)) ?></div></div>
    <?php endif; ?>
    </div>
    <?php return ob_get_clean();
}

/* ===================== السلة ===================== */
function page_cart(): string {
    $user = current_user();
    $cart = $_SESSION['cart'] ?? [];
    if (!$cart) return '<div class="empty-state an"><i class="fas fa-cart-shopping"></i><p>سلتك فارغة</p><a class="btn" style="width:auto;display:inline-flex;margin-top:14px" href="index.php?page=stores">تصفح المتاجر</a></div>';

    $products = db_read('products');
    $byStore = [];
    $total = 0;
    foreach ($cart as $pid => $qty) {
        $p = null; foreach ($products as $pp) if ($pp['id'] === (int)$pid) { $p = $pp; break; }
        if (!$p) continue;
        $basePrice = $p['discount_price'] ?? $p['price'];
        $price = coupon_price($p['id'], $basePrice);
        $byStore[$p['store_id']][] = ['p'=>$p, 'qty'=>$qty, 'price'=>$price, 'base_price'=>$basePrice];
        $total += $price * $qty;
    }
    $appliedCoupon = $_SESSION['cart_coupon'] ?? '';
    ob_start(); ?>
    <h2 style="font-size:1.05rem;font-weight:800;margin:6px 0 16px" class="an">سلة المشتريات</h2>
    <?php foreach ($byStore as $storeId => $items): $store = find_store($storeId); $subtotal = array_sum(array_map(fn($it)=>$it['price']*$it['qty'], $items)); ?>
    <div class="cart-store-group an">
        <div class="cart-store-title"><i class="fas fa-shop" style="color:var(--accent)"></i> <?= h($store['name'] ?? '') ?></div>
        <?php foreach ($items as $it): $hasDiscount = $it['price'] < $it['base_price']; ?>
        <div class="cart-item">
            <div class="cart-item-img"><?php if ($it['p']['images'][0] ?? null): ?><img src="<?= h($it['p']['images'][0]) ?>"><?php else: ?><i class="fas <?= category_icon($it['p']['category']) ?>" style="color:var(--accent)"></i><?php endif; ?></div>
            <div class="cart-item-info"><?= h($it['p']['name']) ?>
                <span><?php if ($hasDiscount): ?><s style="color:var(--muted);margin-left:4px"><?= money($it['base_price']) ?></s><i class="fas fa-tag" style="color:var(--accent);font-size:.6rem"></i> <?php endif; ?><?= money($it['price']) ?> × <?= $it['qty'] ?></span>
            </div>
            <form method="post"><input type="hidden" name="action" value="remove_from_cart"><input type="hidden" name="product_id" value="<?= $it['p']['id'] ?>"><button class="cart-remove"><i class="fas fa-trash"></i></button></form>
        </div>
        <?php endforeach; ?>
        <div style="text-align:left;font-size:.75rem;font-weight:700;color:var(--muted)">مجموع هذا المتجر: <?= money($subtotal) ?></div>
    </div>
    <?php endforeach; ?>
    <form method="post" class="an" style="display:flex;gap:8px;margin-bottom:14px">
        <input type="hidden" name="action" value="apply_coupon">
        <div class="field" style="flex:1;margin-bottom:0"><input type="text" name="coupon_code" value="<?= h($appliedCoupon) ?>" placeholder="كود الخصم (اختياري)" style="text-transform:uppercase"></div>
        <button class="btn btn-sm" type="submit" style="width:auto"><i class="fas fa-tag"></i> تطبيق</button>
    </form>
    <div class="cart-total an"><span>الإجمالي</span><span><?= money($total) ?></span></div>
    <div class="an" style="font-size:.72rem;color:var(--muted);margin-bottom:14px">رصيدك الحالي: <?= money($user['wallet']) ?></div>
    <form method="post" class="an">
        <input type="hidden" name="action" value="checkout">
        <div class="card" style="margin-bottom:14px">
            <h3 style="font-size:.85rem;font-weight:800;margin-bottom:12px"><i class="fas fa-truck" style="color:var(--accent)"></i> معلومات التوصيل</h3>
            <div class="field"><label>رقم هاتف التوصيل</label><input type="tel" name="delivery_phone" value="<?= h($user['phone'] ?? '') ?>" placeholder="07xxxxxxxxx" required></div>
            <div class="field">
                <label>الموقع / العنوان</label>
                <textarea name="delivery_location" id="deliveryLocation" placeholder="اكتب عنوانك بالتفصيل، أو استخدم موقعك الحالي" required></textarea>
                <button type="button" class="btn btn-sm btn-outline" onclick="useMyLocation()" style="margin-top:8px;width:auto"><i class="fas fa-location-crosshairs"></i> استخدام موقعي الحالي</button>
                <span id="locStatus" style="font-size:.68rem;color:var(--muted);margin-inline-start:8px"></span>
            </div>
        </div>
        <button class="btn" type="submit" <?= $total > (float)$user['wallet'] ? 'disabled style="opacity:.5"' : '' ?>><i class="fas fa-check"></i> إتمام الشراء</button>
    </form>
    <?php if ($total > (float)$user['wallet']): ?><p class="an" style="font-size:.7rem;color:var(--danger);margin-top:8px;text-align:center">رصيدك غير كافٍ — تواصل مع الإدارة لشحن رصيدك</p><?php endif; ?>
    <?php return ob_get_clean();
}

/* ===================== طلباتي ===================== */
function page_orders(): string {
    $user = current_user();
    $orders = array_values(array_filter(db_read('orders'), fn($o) => $o['buyer_id'] === $user['id']));
    usort($orders, fn($a,$b) => $b['created_at'] <=> $a['created_at']);
    if (!$orders) return '<div class="empty-state an"><i class="fas fa-receipt"></i><p>لا توجد طلبات بعد</p></div>';
    ob_start(); ?>
    <h2 style="font-size:1.05rem;font-weight:800;margin:6px 0 16px" class="an">طلباتي</h2>
    <?php $isDelivered = fn($o) => $o['status'] === ORDER_STAGES[count(ORDER_STAGES) - 1];
    foreach ($orders as $o): $store = find_store($o['store_id']); ?>
    <div class="order-card an">
        <div class="order-top"><span><i class="fas fa-shop" style="color:var(--accent)"></i> <?= h($store['name'] ?? '') ?></span><span><?= money($o['total']) ?></span></div>
        <div class="order-items"><?= implode('، ', array_map(fn($it) => h($it['name']) . ' ×' . $it['qty'], $o['items'])) ?></div>
        <?= order_stepper($o['status']) ?>
        <details style="margin-top:10px">
            <summary style="font-size:.72rem;color:var(--accent);font-weight:700;cursor:pointer">تفاصيل الطلب #<?= $o['id'] ?></summary>
            <div style="margin-top:8px;display:flex;flex-direction:column;gap:6px">
                <?php foreach ($o['items'] as $it): ?>
                <div class="row-between" style="font-size:.74rem"><span><?= h($it['name']) ?> × <?= $it['qty'] ?></span><span style="color:var(--muted)"><?= money($it['price']) ?> = <?= money($it['price'] * $it['qty']) ?></span></div>
                <?php if ($isDelivered($o)): $rv = find_review($o['id'], $it['product_id']); ?>
                    <?php if ($rv): ?>
                    <div style="font-size:.72rem"><?= render_stars((float)$rv['rating']) ?></div>
                    <?php else: ?>
                    <form method="post" class="rate-form">
                        <input type="hidden" name="action" value="submit_review">
                        <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                        <input type="hidden" name="product_id" value="<?= $it['product_id'] ?>">
                        <input type="hidden" name="rating" value="0" class="rate-input-val">
                        <div class="rate-stars">
                            <?php for ($s = 1; $s <= 5; $s++): ?><button type="button" class="rs" data-v="<?= $s ?>"><i class="far fa-star"></i></button><?php endfor; ?>
                        </div>
                        <input type="text" name="comment" placeholder="تعليق (اختياري)" style="font-size:.72rem">
                        <button class="btn btn-sm" type="submit" style="width:auto">قيّم المنتج</button>
                    </form>
                    <?php endif; ?>
                <?php endif; ?>
                <?php endforeach; ?>
                <div class="row-between" style="font-size:.72rem;color:var(--muted);margin-top:4px;padding-top:6px;border-top:1px solid var(--border)">
                    <span><i class="far fa-clock"></i> <?= date('Y-m-d H:i', $o['created_at']) ?></span>
                    <?php if (!empty($o['delivery_phone'])): ?><span><i class="fas fa-phone"></i> <?= h($o['delivery_phone']) ?></span><?php endif; ?>
                </div>
                <?php if (!empty($o['delivery_location'])): ?><div style="font-size:.72rem;color:var(--muted)"><i class="fas fa-location-dot"></i> <?= h($o['delivery_location']) ?></div><?php endif; ?>
            </div>
        </details>
    </div>
    <?php endforeach;
    return ob_get_clean();
}

/* ===================== حسابي ===================== */
function page_account(): string {
    $user = current_user();
    $store = my_store();
    $pending = my_pending_store();
    $favCount = count($user['favorites']['stores'] ?? []) + count($user['favorites']['products'] ?? []);

    $storeTrail = 'تقديم طلب';
    if ($store) $storeTrail = 'لوحتك جاهزة';
    elseif ($pending) $storeTrail = $pending['status'] === 'pending' ? 'قيد المراجعة' : 'مرفوض';
    $storeLink = $store ? 'index.php?page=vendor' : ($pending ? 'index.php?page=account' : 'index.php?page=apply-vendor');

    ob_start(); ?>
    <div class="an" style="text-align:center;margin:10px 0 18px">
        <div class="avatar-lg"><?= h(mb_substr($user['name'], 0, 1)) ?></div>
        <h2 style="font-size:1.05rem;font-weight:800"><?= h($user['name']) ?></h2>
        <?php if ($user['phone']): ?><p style="font-size:.72rem;color:var(--muted)"><?= h($user['phone']) ?></p><?php endif; ?>
    </div>

    <?php if (is_admin_user()): ?>
    <a href="index.php?page=admin" class="nav-row an" style="background:var(--gradient);color:#1a1a2e">
        <i class="fas fa-user-shield lead" style="color:#1a1a2e"></i>
        <div class="t"><strong>لوحة الإدارة</strong><span style="color:#1a1a2e;opacity:.75">التحكم الكامل بالمنصة</span></div>
        <i class="fas fa-chevron-left"></i>
    </a>
    <?php endif; ?>

    <a href="index.php?page=account-wallet" class="nav-row an">
        <i class="fas fa-wallet lead"></i>
        <div class="t"><strong>المحفظة</strong><span>شحن الرصيد ومتابعة الطلبات</span></div>
        <span class="trail" style="color:var(--accent);font-weight:800"><?= money($user['wallet']) ?></span>
        <i class="fas fa-chevron-left" style="color:var(--muted)"></i>
    </a>

    <?php if ($pending && $pending['status'] !== 'pending'): ?>
    <div class="nav-row an" style="opacity:.85">
        <i class="fas fa-store lead"></i>
        <div class="t"><strong>المتجر</strong><span>للأسف تم رفض طلبك كتاجر (<?= h($pending['name']) ?>)</span></div>
    </div>
    <?php else: ?>
    <a href="<?= $storeLink ?>" class="nav-row an">
        <i class="fas fa-store lead"></i>
        <div class="t"><strong>المتجر</strong><span><?= $store ? h($store['name']) : 'افتح متجرك الخاص على المنصة' ?></span></div>
        <span class="trail"><?= h($storeTrail) ?></span>
        <i class="fas fa-chevron-left" style="color:var(--muted)"></i>
    </a>
    <?php endif; ?>

    <a href="index.php?page=favorites" class="nav-row an">
        <i class="fas fa-heart lead"></i>
        <div class="t"><strong>المفضلة</strong><span>المتاجر والمنتجات المحفوظة</span></div>
        <span class="trail"><?= $favCount ?></span>
        <i class="fas fa-chevron-left" style="color:var(--muted)"></i>
    </a>

    <a href="index.php?page=notifications" class="nav-row an">
        <i class="fas fa-bell lead"></i>
        <div class="t"><strong>الإشعارات</strong><span>كل التحديثات والتنبيهات</span></div>
        <i class="fas fa-chevron-left" style="color:var(--muted)"></i>
    </a>

    <div class="nav-row an">
        <i class="fas fa-moon lead"></i>
        <div class="t"><strong>الوضع الليلي</strong><span>يتذكّر اختيارك بهذا الجهاز</span></div>
        <button class="theme-toggle-btn" type="button" onclick="toggleTheme()" title="تبديل الوضع"><i class="fas fa-moon moon"></i><i class="fas fa-sun sun"></i></button>
    </div>

    <a href="index.php?page=account-edit" class="nav-row an">
        <i class="fas fa-user-pen lead"></i>
        <div class="t"><strong>تعديل معلومات الحساب</strong><span>الاسم، البريد، الهاتف، وكلمة المرور</span></div>
        <i class="fas fa-chevron-left" style="color:var(--muted)"></i>
    </a>

    <a href="index.php?page=ai" class="nav-row an">
        <i class="fas fa-sparkles lead"></i>
        <div class="t"><strong>المساعد الذكي</strong><span>اسأل عن طلباتك أو منتج تحتاجه</span></div>
        <i class="fas fa-chevron-left" style="color:var(--muted)"></i>
    </a>

    <details class="nav-row an" style="display:block">
        <summary style="display:flex;align-items:center;gap:12px;cursor:pointer;list-style:none"><i class="fas fa-shield-halved lead"></i><div class="t"><strong>سياسة الخصوصية</strong><span>كيف نتعامل مع بياناتك</span></div></summary>
        <p style="margin-top:12px;font-size:.76rem;color:var(--muted);line-height:1.9">
            نستخدم بياناتك (الاسم، البريد، الهاتف) فقط لتشغيل حسابك وطلباتك داخل <?= h(APP_NAME) ?>.
            ما نبيع ولا نشارك بياناتك مع أي جهة خارجية. صور الوصولات والمستندات تُستخدم فقط للمراجعة الإدارية.
            تقدر تطلب حذف حسابك بالتواصل مع الإدارة.
        </p>
    </details>

    <div class="nav-row an">
        <i class="fas fa-circle-info lead"></i>
        <div class="t"><strong>عن <?= h(APP_NAME) ?></strong><span>سوق رقمي متعدد المتاجر</span></div>
        <span class="trail">الإصدار 1.0.0</span>
    </div>

    <button class="btn btn-danger an" style="margin-top:16px" onclick="openSheet('logoutSheet')"><i class="fas fa-right-from-bracket"></i> تسجيل الخروج</button>
    <?php return ob_get_clean();
}

function page_account_edit(): string {
    $user = current_user();
    ob_start(); ?>
    <a href="index.php?page=account" class="ai-back an"><i class="fas fa-arrow-right"></i> رجوع لحسابي</a>
    <h2 style="font-size:1.05rem;font-weight:800;margin-bottom:14px" class="an"><i class="fas fa-user-pen"></i> تعديل معلومات الحساب</h2>

    <div class="card an" style="margin-bottom:14px">
        <h3 style="font-size:.85rem;font-weight:800;margin-bottom:14px"><i class="fas fa-id-card" style="color:var(--accent)"></i> المعلومات الشخصية</h3>
        <form method="post">
            <input type="hidden" name="action" value="update_profile">
            <div class="field"><label>الاسم</label><input type="text" name="name" value="<?= h($user['name']) ?>" required></div>
            <div class="field"><label>البريد الإلكتروني</label><input type="email" name="email" value="<?= h($user['email'] ?? '') ?>" required></div>
            <div class="field"><label>رقم الهاتف</label><input type="tel" name="phone" value="<?= h($user['phone'] ?? '') ?>"></div>
            <button class="btn btn-sm" type="submit">حفظ التعديل</button>
        </form>
    </div>

    <div class="card an">
        <h3 style="font-size:.85rem;font-weight:800;margin-bottom:14px"><i class="fas fa-lock" style="color:var(--accent)"></i> تغيير كلمة المرور</h3>
        <form method="post">
            <input type="hidden" name="action" value="update_profile">
            <input type="hidden" name="name" value="<?= h($user['name']) ?>">
            <input type="hidden" name="email" value="<?= h($user['email'] ?? '') ?>">
            <input type="hidden" name="phone" value="<?= h($user['phone'] ?? '') ?>">
            <div class="field"><label>كلمة المرور الحالية</label><input type="password" name="current_password" autocomplete="current-password"></div>
            <div class="field"><label>كلمة المرور الجديدة</label><input type="password" name="password" minlength="6" autocomplete="new-password"></div>
            <button class="btn btn-sm" type="submit">تغيير كلمة المرور</button>
        </form>
    </div>
    <?php return ob_get_clean();
}

function page_account_wallet(): string {
    $user = current_user();
    $settings = get_settings();
    $myTopups = array_values(array_filter(db_read('topup_requests'), fn($r) => $r['user_id'] === $user['id']));
    usort($myTopups, fn($a, $b) => $b['created_at'] <=> $a['created_at']);
    $myPendingTopup = null;
    foreach ($myTopups as $t) if ($t['status'] === 'pending') { $myPendingTopup = $t; break; }
    $statusLabel = ['pending'=>'بانتظار المراجعة', 'approved'=>'تمت الموافقة', 'rejected'=>'مرفوض'];

    ob_start(); ?>
    <a href="index.php?page=account" class="ai-back an"><i class="fas fa-arrow-right"></i> رجوع لحسابي</a>
    <h2 style="font-size:1.05rem;font-weight:800;margin-bottom:14px" class="an"><i class="fas fa-wallet"></i> المحفظة</h2>
    <div class="wallet-card an" style="margin-bottom:14px">
        <div class="wallet-top"><div><div class="greet">رصيدك الحالي</div><div class="wallet-bal"><?= money($user['wallet']) ?></div></div><i class="fas fa-wallet" style="font-size:1.6rem;opacity:.7"></i></div>
    </div>
    <?php if ($myPendingTopup): ?>
        <div class="card an" style="margin-bottom:14px"><i class="fas fa-hourglass-half" style="color:var(--accent)"></i> طلب شحن <?= money($myPendingTopup['amount']) ?> بانتظار مراجعة الإدارة.</div>
    <?php else: ?>
    <div class="card an" style="margin-bottom:14px">
        <h3 style="font-size:.85rem;font-weight:800;margin-bottom:14px"><i class="fas fa-plus" style="color:var(--accent)"></i> شحن المحفظة</h3>
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="request_topup">
            <div class="field"><label>طريقة الدفع</label>
                <select name="method" required onchange="document.querySelectorAll('.pm-detail').forEach(function(p){p.hidden = p.dataset.method !== this.value}, this)">
                    <?php foreach ($settings['payment_methods'] as $m): ?><option value="<?= h($m['name']) ?>"><?= h($m['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <?php foreach ($settings['payment_methods'] as $i => $m): ?>
                <div class="pm-detail" style="margin:-4px 0 14px;padding:12px;background:var(--hover-bg);border-radius:12px;display:flex;gap:10px;align-items:center" data-method="<?= h($m['name']) ?>" <?= $i===0?'':'hidden' ?>>
                    <?php if (!empty($m['logo'])): ?><img src="<?= h($m['logo']) ?>" alt="" style="width:40px;height:40px;border-radius:11px;object-fit:cover;flex-shrink:0"><?php endif; ?>
                    <div style="flex:1;font-size:.72rem;line-height:1.8">
                        <?php if (!empty($m['transfer_number'])): ?><div><strong>رقم التحويل:</strong> <?= h($m['transfer_number']) ?></div><?php endif; ?>
                        <?php if (!empty($m['agent_name'])): ?><div><strong>اسم الوكيل:</strong> <?= h($m['agent_name']) ?></div><?php endif; ?>
                        <?php if (!empty($m['details'])): ?><div style="color:var(--muted)"><?= h($m['details']) ?></div><?php endif; ?>
                    </div>
                    <?php if (!empty($m['qr_code'])): ?><img src="<?= h($m['qr_code']) ?>" alt="" style="width:56px;height:56px;object-fit:cover;border-radius:8px;flex-shrink:0"><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <div class="field"><label>المبلغ (<?= CURRENCY ?>)</label><input type="number" name="amount" min="1" required></div>
            <div class="field"><label>صورة وصل التحويل</label><input type="file" name="receipt" accept="image/*" required></div>
            <button class="btn btn-sm" type="submit">إرسال طلب الشحن</button>
        </form>
    </div>
    <?php endif; ?>
    <?php if ($myTopups): ?>
    <h3 style="font-size:.82rem;font-weight:800;margin-bottom:10px" class="an">سجل طلبات الشحن</h3>
    <?php foreach ($myTopups as $t): ?>
        <div class="table-card an row-between"><span style="font-size:.78rem"><?= money($t['amount']) ?> — <?= h($t['method']) ?></span><span style="font-size:.7rem;color:var(--muted)"><?= h($statusLabel[$t['status']] ?? $t['status']) ?></span></div>
    <?php endforeach; ?>
    <?php endif; ?>
    <?php return ob_get_clean();
}

function page_favorites(): string {
    $user = current_user();
    $favStores = array_values(array_filter(db_read('stores'), fn($s) => in_array($s['id'], $user['favorites']['stores'] ?? [], true)));
    ob_start(); ?>
    <a href="index.php?page=account" class="ai-back an"><i class="fas fa-arrow-right"></i> رجوع لحسابي</a>
    <h2 style="font-size:1.05rem;font-weight:800;margin-bottom:14px" class="an"><i class="fas fa-heart"></i> المفضلة</h2>
    <?php if (!$favStores): ?>
        <div class="empty-state an"><i class="fas fa-heart-crack"></i><p>ما عندك متاجر مفضّلة بعد</p></div>
    <?php else: ?>
        <?= implode('', array_map('render_store_card', $favStores)) ?>
    <?php endif; ?>
    <?php return ob_get_clean();
}

/* ===================== الإشعارات ===================== */
function page_notifications(): string {
    $notifs = my_notifications();
    ob_start(); ?>
    <div class="row-between an" style="margin:6px 0 14px">
        <h2 style="font-size:1.05rem;font-weight:800"><i class="fas fa-bell"></i> الإشعارات</h2>
        <?php if (array_filter($notifs, fn($n) => !$n['read'])): ?>
        <form method="post"><input type="hidden" name="action" value="notif_mark_all_read"><button class="btn btn-sm btn-outline" type="submit">تحديد الكل كمقروء</button></form>
        <?php endif; ?>
    </div>
    <?php if (!$notifs): ?>
        <div class="empty-state an"><i class="fas fa-bell-slash"></i><p>لا توجد إشعارات</p></div>
    <?php else: foreach ($notifs as $n):
        [$nIcon, $nColor] = notif_icon($n['type'] ?? 'info'); ?>
    <div class="notif-wrap an">
        <div class="notif-row <?= $n['read'] ? '' : 'unread' ?>">
            <div class="notif-icon" style="background:<?= h($nColor) ?>22;color:<?= h($nColor) ?>"><i class="fas <?= h($nIcon) ?>"></i></div>
            <?php if ($n['link']): ?><a href="<?= h($n['link']) ?>" class="notif-body">
            <?php else: ?><div class="notif-body">
            <?php endif; ?>
                <strong><?= h($n['title']) ?></strong>
                <p><?= h($n['body']) ?></p>
                <span class="notif-time"><?= time_ago($n['created_at']) ?></span>
            <?= $n['link'] ? '</a>' : '</div>' ?>
            <div class="notif-actions">
                <?php if (!$n['read']): ?>
                <form method="post"><input type="hidden" name="action" value="notif_mark_read"><input type="hidden" name="notif_id" value="<?= $n['id'] ?>"><button class="icon-btn" style="width:30px;height:30px" title="تحديد كمقروء"><i class="fas fa-check"></i></button></form>
                <?php endif; ?>
                <form method="post"><input type="hidden" name="action" value="notif_delete"><input type="hidden" name="notif_id" value="<?= $n['id'] ?>"><button class="notif-delete-trigger cart-remove" title="حذف"><i class="fas fa-trash"></i></button></form>
            </div>
        </div>
    </div>
    <?php endforeach; endif;
    return ob_get_clean();
}

/* ===================== المساعد الذكي (صفحة مستقلة) ===================== */
function page_ai(): string {
    $user = current_user();
    $orders = array_values(array_filter(db_read('orders'), fn($o) => $o['buyer_id'] === $user['id']));
    usort($orders, fn($a, $b) => $b['created_at'] <=> $a['created_at']);
    $confirm = $_SESSION['ai_confirm'] ?? null;
    unset($_SESSION['ai_confirm']);
    $openComplaint = null;
    foreach (db_read('complaints') as $c) if ($c['buyer_id'] === $user['id'] && $c['status'] === 'open') { $openComplaint = $c; break; }

    ob_start(); ?>
    <h2 style="font-size:1.05rem;font-weight:800;margin:6px 0 14px" class="an"><i class="fas fa-sparkles" style="color:var(--accent)"></i> المساعد الذكي</h2>

    <?php if ($openComplaint): ?>
    <div class="card an" style="margin-bottom:16px;border:2px solid var(--accent)">
        <div class="row-between" style="margin-bottom:8px"><strong style="font-size:.85rem"><i class="fas fa-triangle-exclamation" style="color:var(--accent)"></i> شكواك المفتوحة #<?= $openComplaint['id'] ?></strong><span class="d-st-b st-dv">قيد المتابعة</span></div>
        <div style="max-height:220px;overflow-y:auto;display:flex;flex-direction:column;gap:6px;margin-bottom:10px">
            <?php foreach ($openComplaint['messages'] ?? [] as $m): ?>
            <div class="ai-msg <?= $m['from']==='admin' ? 'ai-bot' : 'ai-user' ?>" style="<?= $m['from']==='admin' ? '' : 'align-self:flex-end' ?>"><?= h($m['text']) ?></div>
            <?php endforeach; ?>
        </div>
        <form method="post" style="display:flex;gap:6px">
            <input type="hidden" name="action" value="complaint_reply">
            <input type="hidden" name="complaint_id" value="<?= $openComplaint['id'] ?>">
            <input type="text" name="text" placeholder="اكتب ردك..." required style="flex:1;border:1px solid var(--border);border-radius:100px;padding:9px 14px;background:var(--bg);font-size:.8rem">
            <button type="submit" style="width:38px;height:38px;border-radius:50%;background:var(--gradient);border:none;color:#1a1a2e"><i class="fas fa-paper-plane"></i></button>
        </form>
    </div>
    <?php endif; ?>

    <div id="aiIntent" class="ai-intent-grid an" <?= $confirm ? 'hidden' : '' ?>>
        <button type="button" class="ai-intent-card" onclick="aiShow('aiChatView')">
            <i class="fas fa-comment-dots"></i>
            <div><strong>أسأل عن منتج أو أي شي</strong><span>اسأل عن المتاجر، المنتجات، طلباتك، أو أي استفسار</span></div>
        </button>
        <button type="button" class="ai-intent-card" onclick="aiShow('aiComplaintView')">
            <i class="fas fa-triangle-exclamation"></i>
            <div><strong>رفع شكوى على طلب</strong><span>عندك مشكلة بطلب سابق؟ بلّغ الإدارة مباشرة</span></div>
        </button>
    </div>

    <div id="aiChatView" class="an" <?= $confirm ? '' : 'hidden' ?>>
        <button type="button" class="ai-back" onclick="aiShow('aiIntent')"><i class="fas fa-arrow-right"></i> رجوع</button>
        <div class="ai-chat-box">
            <div id="aiMsgs">
                <?php if ($confirm): ?>
                    <div class="ai-msg ai-bot"><?= h($confirm) ?></div>
                <?php else: ?>
                    <div class="ai-msg ai-bot">أهلاً بيك! 👋 اسألني عن المتاجر، المنتجات، طلباتك، أو كيفية التسجيل كتاجر.</div>
                <?php endif; ?>
            </div>
            <form id="aiChatForm">
                <input id="aiInput" type="text" placeholder="اكتب سؤالك..." autocomplete="off">
                <button type="submit"><i class="fas fa-paper-plane"></i></button>
            </form>
        </div>
    </div>

    <div id="aiComplaintView" class="an" hidden>
        <button type="button" class="ai-back" onclick="aiShow('aiIntent')"><i class="fas fa-arrow-right"></i> رجوع</button>
        <?php if (!$orders): ?>
            <div class="empty-state"><i class="fas fa-receipt"></i><p>ما عندك طلبات بعد لترفع شكوى عليها</p></div>
        <?php else: ?>
        <form method="post">
            <input type="hidden" name="action" value="submit_complaint">
            <h3 style="font-size:.85rem;font-weight:800;margin-bottom:10px">اختر الطلب</h3>
            <div class="pick-list">
                <?php foreach ($orders as $o): $store = find_store($o['store_id']); ?>
                <label class="pick-card">
                    <input type="radio" name="order_id" value="<?= $o['id'] ?>" required>
                    <span class="t">طلب #<?= $o['id'] ?> — <?= h($store['name'] ?? '') ?></span>
                    <div class="s"><?= money($o['total']) ?> · <?= h($o['status']) ?></div>
                </label>
                <?php endforeach; ?>
            </div>
            <h3 style="font-size:.85rem;font-weight:800;margin-bottom:10px">سبب المشكلة</h3>
            <div class="chip-wrap" style="margin-bottom:16px">
                <?php foreach (COMPLAINT_REASONS as $i => $r): ?>
                <input type="radio" class="chip-radio" name="reason" id="reason<?= $i ?>" value="<?= h($r) ?>" required>
                <label class="chip-label" for="reason<?= $i ?>"><?= h($r) ?></label>
                <?php endforeach; ?>
            </div>
            <div class="field"><label>تفاصيل إضافية (اختياري)</label><textarea name="details" placeholder="اشرح المشكلة أكثر..."></textarea></div>
            <button class="btn" type="submit"><i class="fas fa-paper-plane"></i> إرسال الشكوى</button>
        </form>
        <?php endif; ?>
    </div>
    <?php return ob_get_clean();
}

/* ===================== تقديم طلب تاجر ===================== */
function page_apply_vendor(): string {
    if (my_store() || my_pending_store()) return '<div class="empty-state an"><i class="fas fa-circle-check"></i><p>لديك متجر أو طلب قيد المراجعة مسبقاً</p></div>';
    ob_start(); ?>
    <h2 style="font-size:1.05rem;font-weight:800;margin:6px 0 6px" class="an">تقديم طلب كتاجر</h2>
    <p style="font-size:.75rem;color:var(--muted);margin-bottom:18px" class="an">عبّي بيانات متجرك، وراح تراجع الإدارة طلبك وتفعّل حسابك كتاجر بعد الموافقة.</p>
    <form method="post" enctype="multipart/form-data" class="card an">
        <input type="hidden" name="action" value="apply_vendor">
        <div class="field"><label>اسم المتجر</label><input type="text" name="name" required></div>
        <div class="field"><label>وصف المتجر</label><textarea name="description" required></textarea></div>
        <div class="field"><label>القسم</label><select name="category"><?php foreach (get_categories() as $c): ?><option value="<?= h($c) ?>"><?= h($c) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>رقم الهاتف</label><input type="tel" name="contact_phone" required></div>
        <div class="field"><label>رقم واتساب (اختياري)</label><input type="tel" name="contact_whatsapp"></div>
        <div class="field"><label>مستند إثبات (هوية / سجل تجاري)</label><input type="file" name="document" accept="image/*,.pdf"></div>
        <button class="btn" type="submit"><i class="fas fa-paper-plane"></i> إرسال الطلب</button>
    </form>
    <?php return ob_get_clean();
}

/* ===================== لوحة تحكم التاجر ===================== */
function vendor_tabs(string $active): string {
    $tabs = ['overview'=>'نظرة عامة','products'=>'المنتجات','sections'=>'الأقسام','orders'=>'الطلبات','theme'=>'تخصيص المتجر','info'=>'معلومات المتجر','withdraw'=>'سحب الرصيد'];
    ob_start(); ?>
    <div class="section-tabs">
        <?php foreach ($tabs as $k=>$label): ?>
            <a class="<?= $active===$k?'active':'' ?>" href="index.php?page=vendor&section=<?= $k ?>"><?= $label ?></a>
        <?php endforeach; ?>
    </div>
    <?php return ob_get_clean();
}

function page_vendor(): string {
    $store = my_store();
    if (!$store) return '<div class="empty-state an"><i class="fas fa-ban"></i><p>ما عندك متجر مفعّل</p></div>';
    $section = $_GET['section'] ?? 'overview';
    $products = store_products($store['id']);
    $orders = array_values(array_filter(db_read('orders'), fn($o) => $o['store_id'] === $store['id']));
    usort($orders, fn($a,$b) => $b['created_at'] <=> $a['created_at']);

    ob_start();
    echo '<h2 style="font-size:1.05rem;font-weight:800;margin:6px 0 14px" class="an"><i class="fas fa-shop"></i> ' . h($store['name']) . '</h2>';
    if (!is_store_live($store)) {
        $why = !empty($store['suspended']) ? 'معلّق مؤقتاً من قبل الإدارة' : 'انتهى اشتراكك الشهري';
        echo '<div class="flash flash-err an">⚠️ متجرك ' . h($why) . ' وما يظهر حالياً بالسوق للمشترين. تواصل مع الإدارة لتجديد الاشتراك أو إعادة التفعيل.</div>';
    } elseif (($store['subscription_expires_at'] ?? null) && $store['subscription_expires_at'] - time() < 86400 * 5) {
        echo '<div class="flash flash-ok an">⏳ اشتراكك ينتهي بتاريخ ' . date('Y-m-d', $store['subscription_expires_at']) . ' — تواصل مع الإدارة للتجديد.</div>';
    }
    echo vendor_tabs($section);

    if ($section === 'overview') {
        $pendingOrders = count(array_filter($orders, fn($o) => $o['status'] === ORDER_STAGES[0]));
        $totalSales = array_sum(array_map(fn($o) => $o['total'], $orders));
        ?>
        <div class="stat-grid an">
            <div class="stat-box"><div class="num"><?= count($products) ?></div><div class="lbl">منتج</div></div>
            <div class="stat-box"><div class="num"><?= count($orders) ?></div><div class="lbl">طلب</div></div>
            <div class="stat-box"><div class="num"><?= $pendingOrders ?></div><div class="lbl">طلب قيد المراجعة</div></div>
            <div class="stat-box"><div class="num" style="font-size:.95rem"><?= money($totalSales) ?></div><div class="lbl">إجمالي المبيعات</div></div>
        </div>
        <div class="card an" style="margin-bottom:14px">
            <div class="row-between"><span style="font-size:.8rem">رصيدك المتاح</span><strong style="color:var(--accent)"><?= money($store['earnings'] ?? 0) ?></strong></div>
            <?php if (store_pending_earnings($store) > 0): ?>
            <div class="row-between" style="margin-top:6px"><span style="font-size:.72rem;color:var(--muted)">رصيد معلّق (يتوفر خلال <?= EARNINGS_HOLD_HOURS ?> ساعة من كل طلب)</span><strong style="font-size:.8rem;color:var(--muted)"><?= money(store_pending_earnings($store)) ?></strong></div>
            <?php endif; ?>
        </div>
        <?php if (!$store['featured']): ?><p style="font-size:.7rem;color:var(--muted)" class="an">ملاحظة: الإدارة هي من تحدد المتاجر "المميزة" بالصفحة الرئيسية.</p><?php endif; ?>
        <?php
    }

    if ($section === 'products') { ?>
        <details class="card an" style="margin-bottom:16px">
            <summary style="font-weight:800;font-size:.85rem;cursor:pointer"><i class="fas fa-plus"></i> إضافة منتج جديد</summary>
            <form method="post" enctype="multipart/form-data" style="margin-top:14px">
                <input type="hidden" name="action" value="vendor_add_product">
                <div class="field"><label>اسم المنتج</label><input type="text" name="name" required></div>
                <div class="field"><label>الوصف</label><textarea name="description" required></textarea></div>
                <div class="field"><label>السعر (<?= CURRENCY ?>)</label><input type="number" name="price" required min="0"></div>
                <div class="field"><label>سعر بعد الخصم (اختياري)</label><input type="number" name="discount_price" min="0"></div>
                <div class="field"><label>التصنيف العام</label><select name="category"><?php foreach (get_categories() as $c): ?><option <?= $c===$store['category']?'selected':'' ?>><?= h($c) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>قسم العرض بالمتجر (اختياري)</label><select name="section_id"><option value="">بدون قسم</option><?php foreach ($store['sections'] as $sec): ?><option value="<?= $sec['id'] ?>"><?= h($sec['title']) ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>صور المنتج (يمكن اختيار أكثر من صورة)</label><input type="file" name="images[]" multiple accept="image/*"></div>
                <button class="btn" type="submit">إضافة المنتج</button>
            </form>
        </details>
        <?php if (!$products): ?>
            <div class="empty-state an"><i class="fas fa-box-open"></i><p>ما أضفت منتجات بعد</p></div>
        <?php else: foreach ($products as $p): ?>
        <div class="table-card an">
            <div class="row-between">
                <h5><?= h($p['name']) ?></h5>
                <form method="post" onsubmit="return confirm('حذف هذا المنتج؟')"><input type="hidden" name="action" value="vendor_delete_product"><input type="hidden" name="product_id" value="<?= $p['id'] ?>"><button class="cart-remove"><i class="fas fa-trash"></i></button></form>
            </div>
            <div class="meta"><?= money($p['discount_price'] ?? $p['price']) ?> <?= $p['discount_price'] ? '(بعد الخصم من '.money($p['price']).')' : '' ?> — <?= h($p['category']) ?></div>
            <details>
                <summary style="font-size:.72rem;color:var(--accent);font-weight:700;cursor:pointer">تعديل المنتج</summary>
                <form method="post" enctype="multipart/form-data" style="margin-top:10px">
                    <input type="hidden" name="action" value="vendor_edit_product">
                    <input type="hidden" name="product_id" value="<?= $p['id'] ?>">
                    <div class="field"><label>اسم المنتج</label><input type="text" name="name" value="<?= h($p['name']) ?>" required></div>
                    <div class="field"><label>الوصف</label><textarea name="description" required><?= h($p['description']) ?></textarea></div>
                    <div class="field"><label>السعر</label><input type="number" name="price" value="<?= (float)$p['price'] ?>" required min="0"></div>
                    <div class="field"><label>سعر بعد الخصم</label><input type="number" name="discount_price" value="<?= h((string)($p['discount_price'] ?? '')) ?>" min="0"></div>
                    <div class="field"><label>التصنيف العام</label><select name="category"><?php foreach (get_categories() as $c): ?><option <?= $c===$p['category']?'selected':'' ?>><?= h($c) ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>قسم العرض بالمتجر (اختياري)</label><select name="section_id"><option value="">بدون قسم</option><?php foreach ($store['sections'] as $sec): ?><option value="<?= $sec['id'] ?>" <?= ($p['section_id'] ?? null)===$sec['id']?'selected':'' ?>><?= h($sec['title']) ?></option><?php endforeach; ?></select></div>
                    <div class="field"><label>إضافة صور (تُضاف للموجودة)</label><input type="file" name="images[]" multiple accept="image/*"></div>
                    <button class="btn btn-sm" type="submit">حفظ</button>
                </form>
            </details>
        </div>
        <?php endforeach; endif;
    }

    if ($section === 'sections') {
        $storeProducts = $products; ?>
        <p style="font-size:.75rem;color:var(--muted);margin-bottom:14px" class="an">صمّم متجرك بأقسام غير محدودة (مثل: تشكيلة جديدة، الأكثر مبيعاً، عروض...) ورتبها متل ما تريد. كل قسم إله شكل عرض خاص فيه.</p>
        <details class="card an" style="margin-bottom:16px">
            <summary style="font-weight:800;font-size:.85rem;cursor:pointer"><i class="fas fa-plus"></i> إضافة قسم جديد</summary>
            <form method="post" style="margin-top:14px">
                <input type="hidden" name="action" value="vendor_add_section">
                <div class="field"><label>اسم القسم</label><input type="text" name="title" required placeholder="مثال: تشكيلة الصيف"></div>
                <div class="field"><label>شكل العرض</label>
                    <select name="layout">
                        <option value="grid2">شبكة عمودين</option>
                        <option value="grid3">شبكة ثلاث أعمدة</option>
                        <option value="list">قائمة طولية</option>
                    </select>
                </div>
                <button class="btn btn-sm" type="submit">إضافة</button>
            </form>
        </details>
        <?php if (!$store['sections']): ?>
            <div class="empty-state an"><i class="fas fa-layer-group"></i><p>ما أضفت أي قسم بعد — كل منتجاتك تظهر بقسم "منتجات أخرى" افتراضياً</p></div>
        <?php else: foreach ($store['sections'] as $i => $sec):
            $count = count(array_filter($storeProducts, fn($p) => ($p['section_id'] ?? null) === $sec['id'])); ?>
        <div class="table-card an">
            <div class="row-between">
                <h5><?= h($sec['title']) ?></h5>
                <div style="display:flex;gap:4px">
                    <form method="post"><input type="hidden" name="action" value="vendor_move_section"><input type="hidden" name="section_id" value="<?= $sec['id'] ?>"><input type="hidden" name="dir" value="up"><button class="icon-btn" style="width:30px;height:30px" <?= $i===0?'disabled':'' ?>><i class="fas fa-arrow-up"></i></button></form>
                    <form method="post"><input type="hidden" name="action" value="vendor_move_section"><input type="hidden" name="section_id" value="<?= $sec['id'] ?>"><input type="hidden" name="dir" value="down"><button class="icon-btn" style="width:30px;height:30px" <?= $i===count($store['sections'])-1?'disabled':'' ?>><i class="fas fa-arrow-down"></i></button></form>
                    <form method="post" onsubmit="return confirm('حذف القسم؟ (المنتجات تبقى موجودة بدون قسم)')"><input type="hidden" name="action" value="vendor_delete_section"><input type="hidden" name="section_id" value="<?= $sec['id'] ?>"><button class="cart-remove"><i class="fas fa-trash"></i></button></form>
                </div>
            </div>
            <div class="meta"><?= $count ?> منتج</div>
            <details>
                <summary style="font-size:.72rem;color:var(--accent);font-weight:700;cursor:pointer">تعديل القسم</summary>
                <form method="post" style="margin-top:10px">
                    <input type="hidden" name="action" value="vendor_rename_section">
                    <input type="hidden" name="section_id" value="<?= $sec['id'] ?>">
                    <div class="field"><label>اسم القسم</label><input type="text" name="title" value="<?= h($sec['title']) ?>" required></div>
                    <div class="field"><label>شكل العرض</label>
                        <select name="layout">
                            <option value="grid2" <?= $sec['layout']==='grid2'?'selected':'' ?>>شبكة عمودين</option>
                            <option value="grid3" <?= $sec['layout']==='grid3'?'selected':'' ?>>شبكة ثلاث أعمدة</option>
                            <option value="list" <?= $sec['layout']==='list'?'selected':'' ?>>قائمة طولية</option>
                        </select>
                    </div>
                    <button class="btn btn-sm" type="submit">حفظ</button>
                </form>
            </details>
        </div>
        <?php endforeach; endif;
    }

    if ($section === 'orders') {
        if (!$orders) { echo '<div class="empty-state an"><i class="fas fa-receipt"></i><p>لا توجد طلبات بعد</p></div>'; }
        $allUsers = db_read('users');
        foreach ($orders as $o) {
            $idx = array_search($o['status'], ORDER_STAGES, true);
            $buyer = null; foreach ($allUsers as $u) if ($u['id'] === $o['buyer_id']) $buyer = $u;
            ?>
            <div class="order-card an">
                <div class="order-top"><span>طلب #<?= $o['id'] ?></span><span><?= money($o['total']) ?></span></div>
                <div class="meta" style="margin:4px 0"><i class="fas fa-user" style="color:var(--accent)"></i> <?= h($buyer['name'] ?? 'مستخدم محذوف') ?> <?php if ($buyer): ?>— <a href="tel:<?= h($buyer['phone']) ?>" style="color:var(--accent)"><?= h($buyer['phone']) ?></a><?php endif; ?></div>
                <div class="meta" style="margin-bottom:8px"><i class="far fa-clock" style="color:var(--accent)"></i> <?= date('Y-m-d H:i', $o['created_at']) ?></div>
                <?php if (!empty($o['delivery_phone']) || !empty($o['delivery_location'])): ?>
                <div class="meta" style="margin-bottom:8px;background:var(--hover-bg);padding:8px;border-radius:10px">
                    <?php if (!empty($o['delivery_phone'])): ?><div><i class="fas fa-phone" style="color:var(--accent)"></i> توصيل: <a href="tel:<?= h($o['delivery_phone']) ?>" style="color:var(--accent)"><?= h($o['delivery_phone']) ?></a></div><?php endif; ?>
                    <?php if (!empty($o['delivery_location'])): ?><div style="margin-top:3px"><i class="fas fa-location-dot" style="color:var(--accent)"></i> <?= h($o['delivery_location']) ?></div><?php endif; ?>
                </div>
                <?php endif; ?>
                <details>
                    <summary style="font-size:.72rem;color:var(--accent);font-weight:700;cursor:pointer">تفاصيل المنتجات (<?= count($o['items']) ?>)</summary>
                    <div style="margin-top:8px;display:flex;flex-direction:column;gap:6px">
                        <?php foreach ($o['items'] as $it): ?>
                        <div class="row-between" style="font-size:.74rem"><span><?= h($it['name']) ?> × <?= $it['qty'] ?></span><span style="color:var(--muted)"><?= money($it['price']) ?> = <?= money($it['price'] * $it['qty']) ?></span></div>
                        <?php endforeach; ?>
                    </div>
                </details>
                <?= order_stepper($o['status']) ?>
                <?php if ($idx < count(ORDER_STAGES) - 1): ?>
                <form method="post" style="margin-top:10px"><input type="hidden" name="action" value="vendor_update_order"><input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                    <button class="btn btn-sm" type="submit">نقل للمرحلة التالية: <?= h(ORDER_STAGES[$idx+1]) ?></button>
                </form>
                <?php endif; ?>
            </div>
            <?php
        }
    }

    if ($section === 'theme') { $t = $store['theme']; ?>
        <div class="theme-preview an" id="themePreview" style="--pv-color:<?= h($t['primary']) ?>;--pv-radius:<?= (int)$t['radius'] ?>px">
            <div class="pv-ico"></div>
            <div><strong style="font-size:.82rem">هذا شكل بطاقاتك تقريباً</strong><div style="font-size:.68rem;color:var(--muted)">يتغير مباشرة وأنت تعدّل</div></div>
        </div>
        <form method="post" enctype="multipart/form-data" class="card an">
            <input type="hidden" name="action" value="vendor_save_theme">
            <div class="field"><label>اللون الرئيسي</label><input type="color" id="pv_color" name="primary" value="<?= h($t['primary']) ?>" oninput="previewTheme()"></div>
            <div class="field"><label>شكل حواف البطاقات</label>
                <select id="pv_radius" name="radius" onchange="previewTheme()">
                    <option value="sharp" <?= $t['radius']<=6?'selected':'' ?>>حاد</option>
                    <option value="rounded" <?= $t['radius']>6&&$t['radius']<28?'selected':'' ?>>مستدير</option>
                    <option value="pill" <?= $t['radius']>=28?'selected':'' ?>>دائري كامل</option>
                </select>
            </div>
            <div class="field"><label>حجم/كثافة البطاقات</label>
                <select name="density">
                    <option value="compact" <?= $t['density']==='compact'?'selected':'' ?>>مضغوط</option>
                    <option value="comfortable" <?= $t['density']==='comfortable'?'selected':'' ?>>متوسط</option>
                    <option value="spacious" <?= $t['density']==='spacious'?'selected':'' ?>>واسع</option>
                </select>
            </div>
            <div class="field"><label>شكل عرض المنتجات (المنيو)</label>
                <select name="layout">
                    <option value="grid2" <?= $t['layout']==='grid2'?'selected':'' ?>>شبكة عمودين</option>
                    <option value="grid3" <?= $t['layout']==='grid3'?'selected':'' ?>>شبكة ثلاث أعمدة</option>
                    <option value="list" <?= $t['layout']==='list'?'selected':'' ?>>قائمة طولية</option>
                </select>
            </div>
            <div class="field"><label>شعار المتجر</label><input type="file" name="logo" accept="image/*"></div>
            <div class="field"><label>صورة الغلاف</label><input type="file" name="cover" accept="image/*"></div>
            <button class="btn" type="submit">حفظ التخصيص</button>
        </form>
        <?php
    }

    if ($section === 'info') { ?>
        <form method="post" class="card an">
            <input type="hidden" name="action" value="vendor_save_info">
            <div class="field"><label>وصف المتجر</label><textarea name="description"><?= h($store['description']) ?></textarea></div>
            <div class="field"><label>رقم الهاتف</label><input type="tel" name="contact_phone" value="<?= h($store['contact_phone']) ?>"></div>
            <div class="field"><label>رقم واتساب</label><input type="tel" name="contact_whatsapp" value="<?= h($store['contact_whatsapp']) ?>"></div>
            <button class="btn" type="submit">حفظ</button>
        </form>
        <?php
    }

    if ($section === 'withdraw') {
        $settings = get_settings();
        $myRequests = array_values(array_filter(db_read('withdraw_requests'), fn($r) => $r['store_id'] === $store['id']));
        usort($myRequests, fn($a, $b) => $b['created_at'] <=> $a['created_at']);
        $pendingRequest = null;
        foreach ($myRequests as $r) if ($r['status'] === 'pending') { $pendingRequest = $r; break; }
        $statusLabel = ['pending'=>'بانتظار المراجعة', 'approved'=>'تم التحويل', 'rejected'=>'مرفوض'];
        ?>
        <div class="card an" style="margin-bottom:14px">
            <div class="row-between"><span style="font-size:.8rem">رصيدك المتاح للسحب</span><strong style="color:var(--accent)"><?= money($store['earnings'] ?? 0) ?></strong></div>
        </div>
        <?php if ($pendingRequest): ?>
        <div class="card an" style="margin-bottom:14px"><i class="fas fa-hourglass-half" style="color:var(--accent)"></i> طلب سحب <?= money($pendingRequest['amount']) ?> عبر <?= h($pendingRequest['method']) ?> بانتظار مراجعة الإدارة.</div>
        <?php elseif (!$settings['payment_methods']): ?>
        <div class="empty-state an"><i class="fas fa-triangle-exclamation"></i><p>لا توجد طرق دفع مضافة من الإدارة بعد</p></div>
        <?php elseif ((float)($store['earnings'] ?? 0) <= 0): ?>
        <div class="empty-state an"><i class="fas fa-wallet"></i><p>لا يوجد رصيد متاح للسحب حالياً</p></div>
        <?php else: ?>
        <form method="post" class="card an" style="margin-bottom:16px">
            <input type="hidden" name="action" value="request_withdraw">
            <div class="field"><label>طريقة الدفع</label>
                <select name="method" required>
                    <?php foreach ($settings['payment_methods'] as $m): ?><option value="<?= h($m['name']) ?>"><?= h($m['name']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="field"><label>رقم حسابك على طريقة الدفع هذه</label><input type="text" name="account_number" required placeholder="رقم الهاتف أو الحساب"></div>
            <div class="field"><label>الاسم المسجّل على الحساب</label><input type="text" name="account_name" required placeholder="اسمك الكامل"></div>
            <button class="btn" type="submit"><i class="fas fa-hand-holding-dollar"></i> إرسال طلب السحب</button>
        </form>
        <?php endif; ?>
        <?php if ($myRequests): ?>
        <h3 style="font-size:.85rem;font-weight:800;margin-bottom:10px">سجل طلبات السحب</h3>
        <?php foreach ($myRequests as $r): ?>
        <div class="table-card an">
            <div class="row-between"><h5><?= money($r['amount']) ?> عبر <?= h($r['method']) ?></h5><span style="font-size:.7rem;color:var(--muted)"><?= h($statusLabel[$r['status']] ?? $r['status']) ?></span></div>
            <div class="meta"><?= date('Y-m-d H:i', $r['created_at']) ?></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        <?php
    }

    return ob_get_clean();
}

/* ===================== قالب لوحة الإدارة (بدون شريط المشتري) ===================== */
/* ===================== لوحة تحكم الأدمن ===================== */
function admin_section_labels(): array {
    return ['overview'=>'نظرة عامة','orders'=>'الطلبات','applications'=>'طلبات الانضمام','stores'=>'المتاجر','users'=>'المستخدمون والأرصدة','topups'=>'طلبات الشحن','withdrawals'=>'طلبات السحب','complaints'=>'الشكاوى','ai_logs'=>'محادثات AI','broadcast'=>'إشعار جماعي','backup'=>'النسخ الاحتياطي','settings'=>'الإعدادات'];
}

function admin_tabs(string $active): string {
    $tabs = admin_section_labels();
    $current = $tabs[$active] ?? 'لوحة الإدارة';
    ob_start(); ?>
    <div class="admin-topbar an">
        <button type="button" class="icon-btn" onclick="openSheet('adminSidebar')"><i class="fas fa-bars"></i></button>
        <strong><?= h($current) ?></strong>
    </div>
    <?php return ob_get_clean();
}

/* المنيو الجانبي نفسه يُرسم من app_shell_inner() لا من هنا، لأن page_admin()
   يُضمَّن داخل .content التي تملك سياق تكديس (z-index) خاصاً بها — أي عنصر
   position:fixed بداخلها يبقى محبوساً ضمن ذلك السياق ولا يقدر يتفوق فعلياً
   على شريط التنقل السفلي مهما رفعنا z-index له (نفس خلل بطاقة تسجيل الخروج
   المُكتشف والمُصلح سابقاً). لذلك المنيو الجانبي يُرسم كشقيق مباشر لـ .content
   و.tabbar، لا كابن متداخل بداخلهما. */
function admin_sidebar_html(): string {
    if (!is_admin_user()) return '';
    $active = ($_GET['page'] ?? '') === 'admin' ? ($_GET['section'] ?? 'overview') : '';
    ob_start(); ?>
    <nav class="admin-sidebar" id="adminSidebar">
        <div class="admin-sidebar-head"><span><i class="fas fa-user-shield"></i> لوحة الإدارة</span><button type="button" class="icon-btn" onclick="closeSheets()"><i class="fas fa-xmark"></i></button></div>
        <?php foreach (admin_section_labels() as $k => $label): ?>
            <a class="<?= $active === $k ? 'active' : '' ?>" href="index.php?page=admin&section=<?= $k ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <?php return ob_get_clean();
}

function page_admin(): string {
    $section = $_GET['section'] ?? 'overview';
    $stores = db_read('stores');
    $users = db_read('users');
    $orders = db_read('orders');
    $pending = array_values(array_filter($stores, fn($s) => $s['status'] === 'pending'));

    ob_start();
    echo '<a href="index.php?page=account" class="ai-back an"><i class="fas fa-arrow-right"></i> رجوع لحسابي</a>';
    echo '<h2 style="font-size:1.05rem;font-weight:800;margin-bottom:14px" class="an"><i class="fas fa-user-shield"></i> لوحة الإدارة</h2>';
    echo admin_tabs($section);

    if ($section === 'overview') { ?>
        <div class="stat-grid an">
            <div class="stat-box"><div class="num"><?= count(array_filter($stores, fn($s)=>$s['status']==='approved')) ?></div><div class="lbl">متجر معتمد</div></div>
            <div class="stat-box"><div class="num"><?= count($pending) ?></div><div class="lbl">طلب معلّق</div></div>
            <div class="stat-box"><div class="num"><?= count($users) ?></div><div class="lbl">مستخدم</div></div>
            <div class="stat-box"><div class="num"><?= count($orders) ?></div><div class="lbl">طلب شراء</div></div>
        </div>
        <?php
    }

    if ($section === 'applications') {
        if (!$pending) echo '<div class="empty-state an"><i class="fas fa-circle-check"></i><p>لا توجد طلبات معلّقة حالياً</p></div>';
        foreach ($pending as $s) {
            $owner = null; foreach ($users as $u) if ($u['id'] === $s['owner_user_id']) $owner = $u;
            ?>
            <div class="table-card an">
                <h5><?= h($s['name']) ?></h5>
                <div class="meta"><?= h($s['category']) ?> — مقدّم الطلب: <?= h($owner['name'] ?? '؟') ?> (<?= h($owner['phone'] ?? '') ?>)</div>
                <p style="font-size:.75rem;color:var(--muted);margin-bottom:8px"><?= h($s['description']) ?></p>
                <?php if (!empty($s['document'])): ?><a href="<?= h($s['document']) ?>" target="_blank" style="font-size:.7rem;color:var(--accent)"><i class="fas fa-paperclip"></i> عرض المستند المرفق</a><?php endif; ?>
                <div style="display:flex;gap:8px;margin-top:10px">
                    <form method="post" style="width:100%"><input type="hidden" name="action" value="admin_approve"><input type="hidden" name="store_id" value="<?= $s['id'] ?>"><button class="btn btn-sm" type="submit"><i class="fas fa-check"></i> قبول</button></form>
                    <form method="post" style="width:100%"><input type="hidden" name="action" value="admin_reject"><input type="hidden" name="store_id" value="<?= $s['id'] ?>"><button class="btn btn-sm btn-danger" type="submit"><i class="fas fa-xmark"></i> رفض</button></form>
                </div>
            </div>
            <?php
        }
    }

    if ($section === 'stores') {
        $fee = (float)get_settings()['monthly_fee'];
        foreach ($stores as $s) { if ($s['status'] !== 'approved') continue;
            $owner = null; foreach ($users as $u) if ($u['id'] === $s['owner_user_id']) $owner = $u; ?>
            <div class="table-card an">
                <div class="row-between">
                    <h5><?= h($s['name']) ?></h5>
                    <form method="post"><input type="hidden" name="action" value="admin_toggle_featured"><input type="hidden" name="store_id" value="<?= $s['id'] ?>">
                        <button class="btn btn-sm <?= $s['featured']?'':'btn-outline' ?>" type="submit"><i class="fas fa-star"></i> <?= $s['featured']?'مميز':'اجعله مميز' ?></button>
                    </form>
                </div>
                <div class="meta"><?= h($s['category']) ?> — <?= count(store_products($s['id'])) ?> منتج<?php if ($owner): ?> — المالك: <?= h($owner['name']) ?><?php endif; ?></div>
                <div class="meta" style="display:flex;gap:14px;flex-wrap:wrap">
                    <?php if ($s['contact_phone']): ?><span><i class="fas fa-phone" style="color:var(--accent)"></i> <?= h($s['contact_phone']) ?></span><?php endif; ?>
                    <?php if ($s['contact_whatsapp']): ?><a href="https://wa.me/<?= h($s['contact_whatsapp']) ?>" target="_blank" style="color:#25D366"><i class="fab fa-whatsapp"></i> واتساب</a><?php endif; ?>
                </div>
                <div class="row-between" style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border)">
                    <span style="font-size:.78rem">متاح: <strong style="color:var(--accent)"><?= money($s['earnings'] ?? 0) ?></strong><?php if (store_pending_earnings($s) > 0): ?> <span style="color:var(--muted);font-weight:600">(+<?= money(store_pending_earnings($s)) ?> معلّق)</span><?php endif; ?></span>
                    <?php if (!empty($s['suspended'])): ?>
                        <span class="d-st-b st-dv"><i class="fas fa-ban"></i> معلّق يدوياً</span>
                    <?php elseif (!is_store_live($s)): ?>
                        <span class="d-st-b st-dv"><i class="fas fa-clock"></i> الاشتراك منتهي</span>
                    <?php else: ?>
                        <span class="d-st-b st-on">نشط لين <?= date('Y-m-d', $s['subscription_expires_at']) ?></span>
                    <?php endif; ?>
                </div>
                <div style="display:flex;gap:8px;margin-top:10px">
                    <form method="post" style="width:100%" onsubmit="return confirm('تحصيل <?= h((string)$fee) ?> د.ع وتجديد الاشتراك <?= SUBSCRIPTION_DAYS ?> يوم؟')"><input type="hidden" name="action" value="admin_collect_fee"><input type="hidden" name="store_id" value="<?= $s['id'] ?>"><button class="btn btn-sm btn-outline" type="submit"><i class="fas fa-calendar-check"></i> تحصيل وتجديد الاشتراك</button></form>
                </div>
                <form method="post" style="margin-top:8px"><input type="hidden" name="action" value="admin_toggle_suspend"><input type="hidden" name="store_id" value="<?= $s['id'] ?>">
                    <button class="btn btn-sm <?= !empty($s['suspended']) ? '' : 'btn-outline' ?>" style="<?= !empty($s['suspended']) ? '' : 'border-color:var(--danger);color:var(--danger)' ?>" type="submit"><i class="fas fa-power-off"></i> <?= !empty($s['suspended']) ? 'إعادة تفعيل المتجر' : 'تعليق المتجر يدوياً' ?></button>
                </form>
            </div>
        <?php }
    }

    if ($section === 'users') {
        foreach ($users as $u) {
            $uOrders = array_values(array_filter($orders, fn($o) => $o['buyer_id'] === $u['id']));
            $uStore = null; foreach ($stores as $s) if ($s['owner_user_id'] === $u['id']) $uStore = $s;
            $favN = count($u['favorites']['stores'] ?? []) + count($u['favorites']['products'] ?? []); ?>
            <div class="table-card an">
                <div class="row-between"><h5><?= h($u['name']) ?> <?php if (!empty($u['is_admin'])): ?><span class="d-st-b st-on">أدمن</span><?php endif; ?></h5><strong style="color:var(--accent)"><?= money($u['wallet']) ?></strong></div>
                <div class="meta"><?= h($u['email'] ?? '') ?> — <?= h($u['phone'] ?? '') ?></div>
                <details>
                    <summary style="font-size:.72rem;color:var(--accent);font-weight:700;cursor:pointer">معاينة المستخدم (<?= count($uOrders) ?> طلب)</summary>
                    <div style="margin-top:10px;font-size:.75rem;display:flex;flex-direction:column;gap:4px">
                        <span>عدد الطلبات: <?= count($uOrders) ?> — إجمالي الشراء: <?= money(array_sum(array_map(fn($o)=>$o['total'],$uOrders))) ?></span>
                        <span>المفضلة: <?= $favN ?> عنصر</span>
                        <span>حساب تاجر: <?= $uStore ? h($uStore['name']) : 'لا يوجد' ?></span>
                        <span>تاريخ التسجيل: <?= date('Y-m-d', $u['created_at']) ?></span>
                    </div>
                </details>
                <details>
                    <summary style="font-size:.72rem;color:var(--accent);font-weight:700;cursor:pointer">شحن رصيد يدوياً</summary>
                    <form method="post" style="margin-top:10px;display:flex;gap:8px;align-items:flex-end">
                        <input type="hidden" name="action" value="admin_topup">
                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                        <div class="field" style="flex:1;margin-bottom:0"><input type="number" name="amount" placeholder="المبلغ" required></div>
                        <button class="btn btn-sm" type="submit">شحن</button>
                    </form>
                </details>
                <?php if ($u['id'] !== current_user()['id']): ?>
                <form method="post" style="margin-top:8px" onsubmit="return confirm('<?= !empty($u['is_admin']) ? 'إلغاء صلاحية الأدمن' : 'منح صلاحية أدمن' ?> لهذا المستخدم؟')">
                    <input type="hidden" name="action" value="admin_toggle_admin">
                    <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                    <button class="btn btn-sm btn-outline" type="submit"><i class="fas fa-user-shield"></i> <?= !empty($u['is_admin']) ? 'إلغاء صلاحية الأدمن' : 'ترقية لأدمن' ?></button>
                </form>
                <?php endif; ?>
            </div>
        <?php }
    }

    if ($section === 'orders') {
        $allOrders = $orders; usort($allOrders, fn($a,$b) => $b['created_at'] <=> $a['created_at']);
        if (!$allOrders) echo '<div class="empty-state an"><i class="fas fa-receipt"></i><p>لا توجد طلبات بعد</p></div>';
        foreach ($allOrders as $o) {
            $s = null; foreach ($stores as $ss) if ($ss['id'] === $o['store_id']) $s = $ss;
            $u = null; foreach ($users as $uu) if ($uu['id'] === $o['buyer_id']) $u = $uu; ?>
            <div class="table-card an">
                <div class="row-between"><h5>طلب #<?= $o['id'] ?></h5><strong style="color:var(--accent)"><?= money($o['total']) ?></strong></div>
                <div class="meta"><i class="fas fa-shop"></i> <?= h($s['name'] ?? '؟') ?> — <i class="fas fa-user"></i> <?= h($u['name'] ?? '؟') ?></div>
                <div class="meta"><?= date('Y-m-d H:i', $o['created_at']) ?> — <?= h($o['status']) ?></div>
            </div>
        <?php }
    }

    if ($section === 'broadcast') { ?>
        <p style="font-size:.75rem;color:var(--muted);margin-bottom:14px" class="an">يرسل إشعار واحد لكل المستخدمين المسجّلين دفعة وحدة.</p>
        <form method="post" class="card an">
            <input type="hidden" name="action" value="admin_broadcast">
            <div class="field"><label>عنوان الإشعار</label><input type="text" name="title" required></div>
            <div class="field"><label>نص الإشعار</label><textarea name="body" required></textarea></div>
            <button class="btn" type="submit"><i class="fas fa-bullhorn"></i> إرسال للجميع (<?= count($users) ?>)</button>
        </form>
    <?php }

    if ($section === 'ai_logs') {
        $logs = db_read('ai_logs');
        usort($logs, fn($a,$b) => $b['at'] <=> $a['at']);
        if (!$logs) echo '<div class="empty-state an"><i class="fas fa-comments"></i><p>لا توجد محادثات بعد</p></div>';
        foreach (array_slice($logs, 0, 50) as $l) {
            $u = null; foreach ($users as $uu) if ($uu['id'] === $l['user_id']) $u = $uu; ?>
            <div class="table-card an">
                <div class="meta"><?= h($u['name'] ?? '؟') ?> — <?= date('Y-m-d H:i', $l['at']) ?></div>
                <p style="font-size:.78rem;margin-top:4px"><strong>؟</strong> <?= h($l['message']) ?></p>
                <p style="font-size:.78rem;color:var(--muted);margin-top:4px"><i class="fas fa-sparkles" style="color:var(--accent)"></i> <?= h($l['reply']) ?></p>
            </div>
        <?php }
    }

    if ($section === 'topups') {
        $requests = array_reverse(db_read('topup_requests'));
        $pendingReqs = array_values(array_filter($requests, fn($r) => $r['status'] === 'pending'));
        if (!$pendingReqs) echo '<div class="empty-state an"><i class="fas fa-circle-check"></i><p>لا توجد طلبات شحن معلّقة</p></div>';
        foreach ($pendingReqs as $r) {
            $u = null; foreach ($users as $uu) if ($uu['id'] === $r['user_id']) $u = $uu; ?>
            <div class="table-card an">
                <div class="row-between"><h5><?= h($u['name'] ?? '؟') ?></h5><strong style="color:var(--accent)"><?= money($r['amount']) ?></strong></div>
                <div class="meta"><?= h($u['phone'] ?? '') ?> — <?= h($r['method']) ?> — <?= date('Y-m-d H:i', $r['created_at']) ?></div>
                <?php if (!empty($r['receipt'])): ?><a href="<?= h($r['receipt']) ?>" target="_blank" style="font-size:.7rem;color:var(--accent)"><i class="fas fa-receipt"></i> عرض صورة الوصل</a><?php endif; ?>
                <div style="display:flex;gap:8px;margin-top:10px">
                    <form method="post" style="width:100%"><input type="hidden" name="action" value="admin_approve_topup"><input type="hidden" name="request_id" value="<?= $r['id'] ?>"><button class="btn btn-sm" type="submit"><i class="fas fa-check"></i> قبول وإضافة الرصيد</button></form>
                    <form method="post" style="width:100%"><input type="hidden" name="action" value="admin_reject_topup"><input type="hidden" name="request_id" value="<?= $r['id'] ?>"><button class="btn btn-sm btn-danger" type="submit"><i class="fas fa-xmark"></i> رفض</button></form>
                </div>
            </div>
        <?php }
    }

    if ($section === 'withdrawals') {
        $wRequests = array_reverse(db_read('withdraw_requests'));
        $pendingW = array_values(array_filter($wRequests, fn($r) => $r['status'] === 'pending'));
        if (!$pendingW) echo '<div class="empty-state an"><i class="fas fa-circle-check"></i><p>لا توجد طلبات سحب معلّقة</p></div>';
        foreach ($pendingW as $r) {
            $s = null; foreach ($stores as $ss) if ($ss['id'] === $r['store_id']) $s = $ss; ?>
            <div class="table-card an">
                <div class="row-between"><h5><?= h($s['name'] ?? '؟') ?></h5><strong style="color:var(--accent)"><?= money($r['amount']) ?></strong></div>
                <div class="meta">عبر <?= h($r['method']) ?> — <?= h($r['account_name']) ?> (<?= h($r['account_number']) ?>) — <?= date('Y-m-d H:i', $r['created_at']) ?></div>
                <div style="display:flex;gap:8px;margin-top:10px">
                    <form method="post" style="width:100%"><input type="hidden" name="action" value="admin_approve_withdraw"><input type="hidden" name="request_id" value="<?= $r['id'] ?>"><button class="btn btn-sm" type="submit"><i class="fas fa-check"></i> تم التحويل</button></form>
                    <form method="post" style="width:100%"><input type="hidden" name="action" value="admin_reject_withdraw"><input type="hidden" name="request_id" value="<?= $r['id'] ?>"><button class="btn btn-sm btn-danger" type="submit"><i class="fas fa-xmark"></i> رفض</button></form>
                </div>
            </div>
        <?php }
    }

    if ($section === 'complaints') {
        $complaints = array_reverse(db_read('complaints'));
        $openId = isset($_GET['id']) ? (int)$_GET['id'] : null;
        $active = null;
        if ($openId) foreach ($complaints as $c) if ($c['id'] === $openId) $active = $c;

        if ($active) {
            $u = null; foreach ($users as $uu) if ($uu['id'] === $active['buyer_id']) $u = $uu;
            $s = null; foreach ($stores as $ss) if ($ss['id'] === $active['store_id']) $s = $ss; ?>
            <a href="index.php?page=admin&section=complaints" class="ai-back an"><i class="fas fa-arrow-right"></i> كل الشكاوى</a>
            <div class="card an" style="margin-bottom:14px">
                <div class="row-between" style="margin-bottom:6px"><strong>شكوى #<?= $active['id'] ?> — طلب #<?= $active['order_id'] ?></strong><span class="d-st-b <?= $active['status']==='resolved'?'st-on':'st-dv' ?>"><?= $active['status']==='resolved'?'تمت المعالجة':'مفتوحة' ?></span></div>
                <div class="meta">المتجر: <?= h($s['name'] ?? '؟') ?> — المشتري: <?= h($u['name'] ?? '؟') ?> (<?= h($u['phone'] ?? '') ?>)</div>
            </div>
            <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px">
                <?php foreach ($active['messages'] ?? [] as $m): ?>
                <div class="ai-msg <?= $m['from']==='admin' ? 'ai-user' : 'ai-bot' ?>" style="<?= $m['from']==='admin' ? 'align-self:flex-end' : '' ?>max-width:85%"><?= h($m['text']) ?></div>
                <?php endforeach; ?>
            </div>
            <form method="post" style="display:flex;gap:6px;margin-bottom:10px">
                <input type="hidden" name="action" value="complaint_reply">
                <input type="hidden" name="complaint_id" value="<?= $active['id'] ?>">
                <input type="text" name="text" placeholder="اكتب ردك للمشتري..." required style="flex:1;border:1px solid var(--border);border-radius:100px;padding:9px 14px;background:var(--bg);font-size:.8rem">
                <button type="submit" class="btn btn-sm" style="width:auto">إرسال</button>
            </form>
            <?php if ($active['status'] !== 'resolved'): ?>
            <form method="post"><input type="hidden" name="action" value="admin_resolve_complaint"><input type="hidden" name="complaint_id" value="<?= $active['id'] ?>"><button class="btn btn-sm btn-outline" type="submit"><i class="fas fa-check"></i> تحديد كمعالجة</button></form>
            <?php endif; ?>
            <?php
        } else {
            if (!$complaints) echo '<div class="empty-state an"><i class="fas fa-circle-check"></i><p>لا توجد شكاوى</p></div>';
            foreach ($complaints as $c) {
                $u = null; foreach ($users as $uu) if ($uu['id'] === $c['buyer_id']) $u = $uu;
                $s = null; foreach ($stores as $ss) if ($ss['id'] === $c['store_id']) $s = $ss;
                $lastMsg = end($c['messages']) ?: null; ?>
                <a href="index.php?page=admin&section=complaints&id=<?= $c['id'] ?>" class="table-card an" style="display:block">
                    <div class="row-between"><h5>طلب #<?= $c['order_id'] ?> — <?= h($s['name'] ?? '') ?></h5><span class="d-st-b <?= $c['status']==='resolved'?'st-on':'st-dv' ?>"><?= $c['status']==='resolved'?'تمت المعالجة':'مفتوحة' ?></span></div>
                    <div class="meta">من: <?= h($u['name'] ?? '؟') ?> — <?= date('Y-m-d H:i', $c['created_at']) ?></div>
                    <?php if ($lastMsg): ?><p style="font-size:.76rem;color:var(--muted);margin-top:4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($lastMsg['text']) ?></p><?php endif; ?>
                </a>
            <?php }
        }
    }

    if ($section === 'backup') {
        $settings = get_settings();
        $lastBackup = (int)($settings['last_backup_at'] ?? 0);
        ?>
        <div class="card an" style="margin-bottom:16px">
            <h3 style="font-size:.88rem;font-weight:800;margin-bottom:10px"><i class="fab fa-telegram" style="color:var(--accent)"></i> ربط بوت تيليجرام</h3>
            <p style="font-size:.7rem;color:var(--muted);margin-bottom:14px">أنشئ بوت عبر <strong>@BotFather</strong>، وخذ التوكن، ثم أرسل أي رسالة للبوت وخذ chat_id من رابط <span style="direction:ltr;display:inline-block">api.telegram.org/bot&lt;TOKEN&gt;/getUpdates</span></p>
            <form method="post">
                <input type="hidden" name="action" value="admin_update_backup">
                <div class="field"><label>توكن البوت</label><input type="text" name="telegram_bot_token" value="<?= h($settings['telegram_bot_token']) ?>" placeholder="123456:ABC-DEF..." autocomplete="off" style="direction:ltr;text-align:left"></div>
                <div class="field"><label>معرف المحادثة (chat_id)</label><input type="text" name="telegram_chat_id" value="<?= h($settings['telegram_chat_id']) ?>" placeholder="123456789" autocomplete="off" style="direction:ltr;text-align:left"></div>
                <button class="btn btn-sm" type="submit">حفظ</button>
            </form>
        </div>
        <div class="card an">
            <div class="row-between" style="margin-bottom:12px">
                <span style="font-size:.78rem">آخر نسخة احتياطية</span>
                <strong style="font-size:.78rem"><?= $lastBackup ? date('Y-m-d H:i', $lastBackup) : 'لم تُرسل بعد' ?></strong>
            </div>
            <p style="font-size:.68rem;color:var(--muted);margin-bottom:12px">تُرسل نسخة تلقائياً كل 6 ساعات طالما البوت مرتبط، أو اضغط الزر لإرسالها فوراً.</p>
            <form method="post"><input type="hidden" name="action" value="admin_backup_now"><button class="btn btn-sm btn-outline" type="submit"><i class="fas fa-cloud-arrow-up"></i> نسخ احتياطي الآن</button></form>
        </div>
        <?php
    }

    if ($section === 'settings') {
        $settings = get_settings(); ?>
        <div class="card an" style="margin-bottom:16px">
            <h3 style="font-size:.88rem;font-weight:800;margin-bottom:14px"><i class="fas fa-palette" style="color:var(--accent)"></i> اسم وشعار الموقع</h3>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="action" value="admin_update_branding">
                <?php if ($settings['site_logo']): ?><img src="<?= h($settings['site_logo']) ?>" alt="" style="width:56px;height:56px;border-radius:16px;object-fit:cover;margin-bottom:10px"><?php endif; ?>
                <div class="field"><label>اسم الموقع (يظهر بعنوان الصفحة وتثبيت التطبيق)</label><input type="text" name="site_name" value="<?= h($settings['site_name']) ?>" placeholder="<?= h(APP_NAME) ?>"></div>
                <div class="field"><label>شعار الموقع</label><input type="file" name="site_logo" accept="image/*"></div>
                <button class="btn btn-sm" type="submit">حفظ</button>
            </form>
        </div>
        <div class="card an" style="margin-bottom:16px">
            <h3 style="font-size:.88rem;font-weight:800;margin-bottom:14px"><i class="fas fa-sparkles" style="color:var(--accent)"></i> مفتاح المساعد الذكي (DeepSeek)</h3>
            <form method="post">
                <input type="hidden" name="action" value="admin_update_ai_key">
                <div class="field"><label>API Key</label><input type="text" name="ai_api_key" value="<?= h($settings['ai_api_key']) ?>" placeholder="sk-..." autocomplete="off"></div>
                <p style="font-size:.68rem;color:var(--muted);margin:-8px 0 12px">اتركه فارغاً لتعطيل الذكاء الاصطناعي الحقيقي (يعمل برد احتياطي بسيط بدون مفتاح).</p>
                <button class="btn btn-sm" type="submit">حفظ</button>
            </form>
        </div>
        <div class="card an" style="margin-bottom:16px">
            <form method="post">
                <input type="hidden" name="action" value="admin_update_fee">
                <div class="field"><label>قيمة الرسم الشهري لكل متجر (<?= CURRENCY ?>)</label><input type="number" name="monthly_fee" value="<?= (float)$settings['monthly_fee'] ?>"></div>
                <button class="btn btn-sm" type="submit">حفظ</button>
            </form>
        </div>
        <h3 style="font-size:.88rem;font-weight:800;margin-bottom:10px">تصنيفات المنتجات</h3>
        <div class="chip-wrap an" style="margin-bottom:12px">
            <?php foreach ($settings['categories'] as $c): ?>
            <form method="post" style="display:inline-flex;align-items:center;gap:4px" onsubmit="return confirm('حذف تصنيف «<?= h($c) ?>»؟')">
                <input type="hidden" name="action" value="admin_delete_category">
                <input type="hidden" name="name" value="<?= h($c) ?>">
                <button class="chip" type="submit" style="display:flex;align-items:center;gap:6px"><?= h($c) ?> <i class="fas fa-xmark" style="color:var(--danger)"></i></button>
            </form>
            <?php endforeach; ?>
        </div>
        <form method="post" style="display:flex;gap:8px;margin-bottom:20px">
            <input type="hidden" name="action" value="admin_add_category">
            <div class="field" style="flex:1;margin-bottom:0"><input type="text" name="name" placeholder="تصنيف جديد" required></div>
            <button class="btn btn-sm" type="submit" style="width:auto">إضافة</button>
        </form>
        <h3 style="font-size:.88rem;font-weight:800;margin-bottom:10px">طرق الدفع</h3>
        <?php foreach ($settings['payment_methods'] as $m): ?>
            <div class="table-card an">
                <div class="row-between">
                    <div style="display:flex;align-items:center;gap:8px">
                        <?php if (!empty($m['logo'])): ?><img src="<?= h($m['logo']) ?>" alt="" style="width:32px;height:32px;border-radius:9px;object-fit:cover"><?php endif; ?>
                        <h5><?= h($m['name']) ?></h5>
                    </div>
                    <form method="post" onsubmit="return confirm('حذف طريقة الدفع؟')"><input type="hidden" name="action" value="admin_delete_payment_method"><input type="hidden" name="method_id" value="<?= $m['id'] ?>"><button class="cart-remove"><i class="fas fa-trash"></i></button></form>
                </div>
                <?php if (!empty($m['transfer_number'])): ?><div class="meta">رقم التحويل: <?= h($m['transfer_number']) ?></div><?php endif; ?>
                <?php if (!empty($m['agent_name'])): ?><div class="meta">اسم الوكيل: <?= h($m['agent_name']) ?></div><?php endif; ?>
                <?php if (!empty($m['details'])): ?><div class="meta"><?= h($m['details']) ?></div><?php endif; ?>
                <?php if (!empty($m['qr_code'])): ?><img src="<?= h($m['qr_code']) ?>" alt="" style="width:70px;height:70px;object-fit:cover;border-radius:10px;margin-top:6px"><?php endif; ?>
            </div>
        <?php endforeach; ?>
        <details class="card an" style="margin-top:10px">
            <summary style="font-weight:800;font-size:.8rem;cursor:pointer"><i class="fas fa-plus"></i> إضافة طريقة دفع</summary>
            <form method="post" enctype="multipart/form-data" style="margin-top:14px">
                <input type="hidden" name="action" value="admin_add_payment_method">
                <div class="field"><label>الاسم</label><input type="text" name="name" required placeholder="مثال: زين كاش"></div>
                <div class="field"><label>رقم التحويل</label><input type="text" name="transfer_number" required placeholder="0770-000-0000"></div>
                <div class="field"><label>اسم الوكيل</label><input type="text" name="agent_name" required placeholder="اسم مستلم الحوالة"></div>
                <div class="field"><label>شعار طريقة الدفع</label><input type="file" name="logo" accept="image/*"></div>
                <div class="field"><label>باركود التحويل (اختياري)</label><input type="file" name="qr_code" accept="image/*"></div>
                <div class="field"><label>تفاصيل إضافية (اختياري)</label><textarea name="details" placeholder="أي تعليمات إضافية"></textarea></div>
                <button class="btn btn-sm" type="submit">إضافة</button>
            </form>
        </details>

        <h3 style="font-size:.88rem;font-weight:800;margin:22px 0 10px">كوبونات الخصم</h3>
        <?php $allProducts = db_read('products'); if (!$settings['coupons']): ?>
            <p style="font-size:.72rem;color:var(--muted);margin-bottom:10px">لا توجد كوبونات بعد</p>
        <?php else: foreach ($settings['coupons'] as $cp):
            $cpProduct = null; if ($cp['product_id'] !== null) foreach ($allProducts as $pp) if ($pp['id'] === $cp['product_id']) { $cpProduct = $pp; break; } ?>
            <div class="table-card an">
                <div class="row-between">
                    <h5><?= h($cp['code']) ?> <span style="color:var(--accent);font-weight:700">-<?= (int)$cp['percent'] ?>%</span></h5>
                    <form method="post" onsubmit="return confirm('حذف الكوبون؟')"><input type="hidden" name="action" value="admin_delete_coupon"><input type="hidden" name="coupon_id" value="<?= $cp['id'] ?>"><button class="cart-remove"><i class="fas fa-trash"></i></button></form>
                </div>
                <div class="meta"><?= $cp['product_id'] === null ? 'على كل المنتجات بجميع المتاجر' : 'على منتج: ' . h($cpProduct['name'] ?? 'منتج محذوف') ?></div>
            </div>
        <?php endforeach; endif; ?>
        <details class="card an" style="margin-top:10px">
            <summary style="font-weight:800;font-size:.8rem;cursor:pointer"><i class="fas fa-plus"></i> إضافة كوبون خصم</summary>
            <form method="post" style="margin-top:14px">
                <input type="hidden" name="action" value="admin_add_coupon">
                <div class="field"><label>الكود</label><input type="text" name="code" required placeholder="مثال: SALE20" style="text-transform:uppercase"></div>
                <div class="field"><label>نطاق الكوبون</label>
                    <select name="scope" onchange="document.getElementById('couponProductField').hidden = this.value === 'all'">
                        <option value="product">منتج محدد</option>
                        <option value="all">كل المنتجات بجميع المتاجر</option>
                    </select>
                </div>
                <div class="field" id="couponProductField"><label>المنتج</label>
                    <select name="product_id">
                        <?php foreach ($allProducts as $pp): $ps = find_store($pp['store_id']); ?>
                        <option value="<?= $pp['id'] ?>"><?= h($pp['name']) ?> — <?= h($ps['name'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label>نسبة الخصم %</label><input type="number" name="percent" min="1" max="90" required placeholder="20"></div>
                <button class="btn btn-sm" type="submit">إضافة</button>
            </form>
        </details>

        <h3 style="font-size:.88rem;font-weight:800;margin:22px 0 10px"><i class="fas fa-database" style="color:var(--accent)"></i> قاعدة بيانات MySQL</h3>
        <div class="card an">
            <div class="row-between" style="margin-bottom:12px">
                <span style="font-size:.78rem">حالة الاتصال</span>
                <strong style="font-size:.78rem;color:var(--success)">✅ متصل بـ <?= h(DB_HOST) ?></strong>
            </div>
            <p style="font-size:.68rem;color:var(--muted);margin-bottom:14px">الموقع يعتمد على MySQL حصراً لتخزين بياناته. استخدم النموذج فقط إن أردت تحويل الموقع لخادم MySQL آخر (مثلاً عند تغيير الاستضافة) — يُختبر الاتصال الجديد أولاً قبل الحفظ فلن يُقفل موقعك بخطأ كتابي، وتُنقل البيانات الحالية إليه تلقائياً إن كان فارغاً.</p>
            <form method="post">
                <input type="hidden" name="action" value="admin_update_db_config">
                <div class="field"><label>المضيف (Host)</label><input type="text" name="db_host" value="<?= h(DB_HOST) ?>" placeholder="localhost" style="direction:ltr;text-align:left" required></div>
                <div class="field"><label>اسم قاعدة البيانات</label><input type="text" name="db_name" value="<?= h(DB_NAME) ?>" style="direction:ltr;text-align:left" required></div>
                <div class="field"><label>اسم المستخدم</label><input type="text" name="db_user" value="<?= h(DB_USER) ?>" style="direction:ltr;text-align:left" required></div>
                <div class="field"><label>كلمة المرور</label><input type="password" name="db_pass" value="<?= h(DB_PASS) ?>" style="direction:ltr;text-align:left" autocomplete="off"></div>
                <button class="btn btn-sm" type="submit">اختبار وحفظ</button>
            </form>
        </div>
        <?php
    }

    return ob_get_clean();
}

/* ===================== التوجيه الرئيسي (يُرجع محتوى فقط، بدون قالب) ===================== */
function resolve_view(): array {
    $page = $_GET['page'] ?? 'home';

    if (!current_user()) {
        if ($page === 'register') return ['إنشاء حساب', register_inner()];
        if ($page === 'login') return ['تسجيل الدخول', login_inner()];
        return ['مرحباً', welcome_inner()];
    }

    switch ($page) {
        case 'stores': return ['المتاجر', app_shell_inner(page_stores(), 'stores')];
        case 'store': return ['المتجر', app_shell_inner(page_store(), 'stores')];
        case 'product': return ['المنتج', app_shell_inner(page_product(), 'stores')];
        case 'cart': return ['السلة', app_shell_inner(page_cart(), null)];
        case 'orders': return ['طلباتي', app_shell_inner(page_orders(), 'orders')];
        case 'account': return ['حسابي', app_shell_inner(page_account(), 'account')];
        case 'account-wallet': return ['المحفظة', app_shell_inner(page_account_wallet(), 'account')];
        case 'account-edit': return ['تعديل الحساب', app_shell_inner(page_account_edit(), 'account')];
        case 'favorites': return ['المفضلة', app_shell_inner(page_favorites(), 'account')];
        case 'apply-vendor': return ['تقديم طلب تاجر', app_shell_inner(page_apply_vendor(), 'account')];
        case 'vendor': return ['لوحة التاجر', app_shell_inner(page_vendor(), 'account')];
        case 'ai': return ['المساعد الذكي', app_shell_inner(page_ai(), 'ai')];
        case 'notifications': return ['الإشعارات', app_shell_inner(page_notifications(), null)];
        case 'admin':
            if (!is_admin_user()) return ['الرئيسية', app_shell_inner(page_home(), 'home')];
            return ['لوحة الإدارة', app_shell_inner(page_admin(), 'account')];
        default: return ['الرئيسية', app_shell_inner(page_home(), 'home')];
    }
}

function emit_fragment(): void {
    [$title, $inner] = resolve_view();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['title'=>$title, 'html'=>$inner], JSON_UNESCAPED_UNICODE);
    exit;
}

if (is_ajax()) emit_fragment();
[$title, $inner] = resolve_view();
full_document($title, $inner);
