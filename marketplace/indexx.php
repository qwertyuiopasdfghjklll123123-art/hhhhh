<?php
/* ============================================================
   سوق رقمي متعدد المتاجر — ملف واحد بدون قاعدة بيانات (JSON)
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

if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0777, true);
if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0777, true);

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

/* ===================== طبقة التخزين (JSON) ===================== */
function db_path(string $name): string { return DATA_DIR . '/' . $name . '.json'; }

function db_read(string $name, array $default = []): array {
    $f = db_path($name);
    if (!file_exists($f)) return $default;
    $raw = file_get_contents($f);
    $data = json_decode($raw, true);
    return is_array($data) ? $data : $default;
}

function db_write(string $name, array $data): void {
    file_put_contents(db_path($name), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function next_id(array $items): int {
    $max = 0;
    foreach ($items as $it) $max = max($max, (int)($it['id'] ?? 0));
    return $max + 1;
}

/* ===================== البيانات التجريبية الأولية ===================== */
function ensure_seed_data(): void {
    if (file_exists(db_path('stores'))) return; // تم التهيئة من قبل

    $baseStore = ['sections'=>[], 'earnings'=>0, 'earnings_log'=>[], 'last_fee_at'=>null, 'subscription_expires_at'=>time()+86400*SUBSCRIPTION_DAYS, 'suspended'=>false];
    $stores = [
        $baseStore + ['id'=>1,'owner_user_id'=>0,'name'=>'متجر أحمد للملابس','slug'=>'ahmed-clothes','description'=>'أحدث صيحات الموضة والملابس الرجالية والنسائية بجودة عالية وأسعار مناسبة للجميع.','category'=>'ملابس','contact_phone'=>'07701234567','contact_whatsapp'=>'9647701234567','logo'=>'','cover'=>'','status'=>'approved','featured'=>true,'theme'=>['primary'=>'#f2b100','radius'=>18,'density'=>'comfortable','layout'=>'grid2'],'created_at'=>time()-86400*20],
        $baseStore + ['id'=>2,'owner_user_id'=>0,'name'=>'متجر علي للإلكترونيات','slug'=>'ali-electronics','description'=>'أجهزة إلكترونية، هواتف، وإكسسوارات أصلية مع ضمان حقيقي وخدمة توصيل سريعة.','category'=>'إلكترونيات','contact_phone'=>'07709876543','contact_whatsapp'=>'9647709876543','logo'=>'','cover'=>'','status'=>'approved','featured'=>true,'theme'=>['primary'=>'#2f8fd8','radius'=>10,'density'=>'compact','layout'=>'grid3'],'created_at'=>time()-86400*15],
        $baseStore + ['id'=>3,'owner_user_id'=>0,'name'=>'متجر سارة للتجميل','slug'=>'sara-beauty','description'=>'منتجات تجميل وعناية بالبشرة من ماركات موثوقة، مختارة بعناية لتناسب كل الأذواق.','category'=>'تجميل ومكياج','contact_phone'=>'07715558899','contact_whatsapp'=>'9647715558899','logo'=>'','cover'=>'','status'=>'approved','featured'=>true,'theme'=>['primary'=>'#e0559a','radius'=>26,'density'=>'spacious','layout'=>'grid2'],'created_at'=>time()-86400*8],
        $baseStore + ['id'=>4,'owner_user_id'=>0,'name'=>'بيت الأناقة للمنزل','slug'=>'home-elegance','description'=>'كل ما يحتاجه منزلك من أدوات مطبخ وديكور بأسعار تنافسية.','category'=>'منزل ومطبخ','contact_phone'=>'07733221100','contact_whatsapp'=>'9647733221100','logo'=>'','cover'=>'','status'=>'approved','featured'=>false,'theme'=>['primary'=>'#3fa66a','radius'=>14,'density'=>'comfortable','layout'=>'list'],'created_at'=>time()-86400*3],
        $baseStore + ['id'=>5,'owner_user_id'=>0,'name'=>'عالم الأطفال','slug'=>'kids-world','description'=>'ألعاب وملابس أطفال آمنة ومسلية لكل الأعمار.','category'=>'أطفال وألعاب','contact_phone'=>'07744556677','contact_whatsapp'=>'9647744556677','logo'=>'','cover'=>'','status'=>'approved','featured'=>false,'theme'=>['primary'=>'#f2b100','radius'=>18,'density'=>'comfortable','layout'=>'grid2'],'created_at'=>time()-86400*1],
    ];

    $products = [
        ['id'=>1,'store_id'=>1,'name'=>'قميص قطني كلاسيكي','description'=>'قميص رجالي قطن 100% متوفر بعدة مقاسات وألوان، مناسب للعمل والمناسبات.','price'=>25000,'discount_price'=>null,'category'=>'ملابس','images'=>[],'created_at'=>time()-86400*19],
        ['id'=>2,'store_id'=>1,'name'=>'فستان سهرة أنيق','description'=>'فستان سهرة بتصميم عصري وخامة فاخرة، مثالي للمناسبات الخاصة.','price'=>75000,'discount_price'=>60000,'category'=>'ملابس','images'=>[],'created_at'=>time()-86400*10],
        ['id'=>3,'store_id'=>1,'name'=>'جينز رجالي مريح','description'=>'بنطلون جينز بقصة عصرية ومقاومة للتمزق.','price'=>35000,'discount_price'=>null,'category'=>'ملابس','images'=>[],'created_at'=>time()-86400*2],
        ['id'=>4,'store_id'=>2,'name'=>'سماعات بلوتوث لاسلكية','description'=>'سماعات بجودة صوت عالية وعمر بطارية يصل ل 20 ساعة، مقاومة للماء.','price'=>45000,'discount_price'=>35000,'category'=>'إلكترونيات','images'=>[],'created_at'=>time()-86400*14],
        ['id'=>5,'store_id'=>2,'name'=>'شاحن سريع 65 واط','description'=>'شاحن سريع متوافق مع أغلب الأجهزة، يشحن الهاتف بالكامل خلال دقائق.','price'=>20000,'discount_price'=>null,'category'=>'إلكترونيات','images'=>[],'created_at'=>time()-86400*6],
        ['id'=>6,'store_id'=>2,'name'=>'ساعة ذكية رياضية','description'=>'تتبع اللياقة، نبضات القلب، والإشعارات مباشرة على معصمك.','price'=>90000,'discount_price'=>null,'category'=>'إلكترونيات','images'=>[],'created_at'=>time()-86400*1],
        ['id'=>7,'store_id'=>3,'name'=>'طقم فرش مكياج احترافي','description'=>'12 فرشاة مكياج بجودة عالية مع حقيبة أنيقة.','price'=>30000,'discount_price'=>22000,'category'=>'تجميل ومكياج','images'=>[],'created_at'=>time()-86400*7],
        ['id'=>8,'store_id'=>3,'name'=>'كريم ترطيب للبشرة','description'=>'كريم مرطب يومي مناسب لجميع أنواع البشرة.','price'=>15000,'discount_price'=>null,'category'=>'تجميل ومكياج','images'=>[],'created_at'=>time()-86400*4],
        ['id'=>9,'store_id'=>4,'name'=>'طقم أواني طبخ 10 قطع','description'=>'أواني طبخ غير لاصقة بجودة ممتازة.','price'=>120000,'discount_price'=>null,'category'=>'منزل ومطبخ','images'=>[],'created_at'=>time()-86400*3],
        ['id'=>10,'store_id'=>5,'name'=>'سيارة تحكم عن بعد','description'=>'لعبة سيارة سريعة تعمل بالريموت، مناسبة للأعمار +6.','price'=>28000,'discount_price'=>null,'category'=>'أطفال وألعاب','images'=>[],'created_at'=>time()-86400*1],
    ];

    db_write('stores', $stores);
    db_write('products', $products);
    db_write('users', [
        ['id'=>1, 'name'=>'الإدارة', 'email'=>ADMIN_SEED_EMAIL, 'password_hash'=>password_hash(ADMIN_SEED_PASSWORD, PASSWORD_DEFAULT), 'phone'=>'', 'wallet'=>0, 'wallet_log'=>[], 'favorites'=>['stores'=>[],'products'=>[]], 'is_admin'=>true, 'created_at'=>time()],
    ]);
    db_write('orders', []);
    db_write('complaints', []);
    db_write('topup_requests', []);
    db_write('notifications', []);
    db_write('settings', [
        'monthly_fee' => 15000,
        'categories' => ['ملابس', 'إلكترونيات', 'تجميل ومكياج', 'منزل ومطبخ', 'أطفال وألعاب', 'رياضة ولياقة', 'أخرى'],
        'payment_methods' => [
            ['id'=>1, 'name'=>'زين كاش', 'transfer_number'=>'0770-000-0000', 'agent_name'=>'إدارة ' . APP_NAME, 'logo'=>'', 'qr_code'=>'', 'details'=>'حوّل إلى الرقم ثم ارفع صورة الوصل'],
            ['id'=>2, 'name'=>'آسيا حوالة', 'transfer_number'=>'0770-111-1111', 'agent_name'=>'إدارة ' . APP_NAME, 'logo'=>'', 'qr_code'=>'', 'details'=>'حوّل باسم الوكيل وارفع صورة الوصل'],
            ['id'=>3, 'name'=>'تسليم نقدي بالمكتب', 'transfer_number'=>'', 'agent_name'=>'إدارة ' . APP_NAME, 'logo'=>'', 'qr_code'=>'', 'details'=>'راجع مكتب الإدارة وسلّم المبلغ نقداً'],
        ],
        'site_name' => APP_NAME,
        'site_logo' => '',
        'ai_api_key' => '',
        'coupons' => [],
    ]);
}
ensure_seed_data();

/* عند رفع هذا الإصدار فوق استضافة فيها بيانات من نسخة سابقة (نظام دخول قديم
   بدون بريد/كلمة مرور)، يبقى ملف stores.json موجوداً فتتخطى ensure_seed_data()
   التهيئة بالكامل ولا يُنشأ حساب الأدمن الجديد أبداً — فيفشل تسجيل الدخول
   بحساب الأدمن دائماً برسالة "البريد أو كلمة المرور غير صحيحة". هذه الدالة
   مستقلة وتُشغَّل بكل طلب: تحذف تلقائياً أي سجلات مستخدمين قديمة غير متوافقة
   (بلا password_hash) وتضمن وجود حساب أدمن صالح دوماً، دون المساس بأي حساب
   مستخدم حقيقي مسجَّل بالنظام الجديد. */
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

function get_settings(): array {
    $defaults = ['monthly_fee'=>0, 'categories'=>[], 'payment_methods'=>[], 'site_name'=>APP_NAME, 'site_logo'=>'', 'ai_api_key'=>'', 'coupons'=>[], 'telegram_bot_token'=>'', 'telegram_chat_id'=>'', 'last_backup_at'=>0];
    return db_read('settings', $defaults) + $defaults;
}
function get_categories(): array { return get_settings()['categories'] ?? []; }
function site_name(): string { $n = trim((string)(get_settings()['site_name'] ?? '')); return $n !== '' ? $n : APP_NAME; }
function site_logo_url(): ?string { $l = get_settings()['site_logo'] ?? ''; return $l ? $l : null; }

function find_coupon_for_product(int $productId): ?array {
    $code = $_SESSION['cart_coupon'] ?? null;
    if (!$code) return null;
    foreach (get_settings()['coupons'] as $c) if ($c['code'] === $code && $c['product_id'] === $productId) return $c;
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

function store_rating(int $storeId): float {
    static $ordersByStore = null, $complaintsByStore = null;
    if ($ordersByStore === null) {
        $ordersByStore = []; $complaintsByStore = [];
        foreach (db_read('orders') as $o) $ordersByStore[$o['store_id']] = ($ordersByStore[$o['store_id']] ?? 0) + 1;
        foreach (db_read('complaints') as $c) $complaintsByStore[$c['store_id']] = ($complaintsByStore[$c['store_id']] ?? 0) + 1;
    }
    $oc = $ordersByStore[$storeId] ?? 0;
    $cc = $complaintsByStore[$storeId] ?? 0;
    if ($oc === 0) return 5.0;
    $rating = 5 - min(4, ($cc / $oc) * 5);
    return max(1, round($rating * 2) / 2);
}

function render_stars(float $rating): string {
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
            redirect('indexx.php?page=login');
        }
        $_SESSION['user_id'] = $found['id'];
        redirect('indexx.php');
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
        if ($err) { flash('err', $err); redirect('indexx.php?page=register'); }

        $users = db_read('users');
        $newUser = ['id'=>next_id($users), 'name'=>$name, 'email'=>$email, 'password_hash'=>password_hash($password, PASSWORD_DEFAULT), 'phone'=>$phone, 'wallet'=>0, 'wallet_log'=>[], 'favorites'=>['stores'=>[],'products'=>[]], 'is_admin'=>false, 'created_at'=>time()];
        $users[] = $newUser;
        db_write('users', $users);
        $_SESSION['user_id'] = $newUser['id'];
        redirect('indexx.php');
    }

    if ($action === 'google_login') {
        $claims = google_verify_id_token((string)($_POST['credential'] ?? ''));
        if ($claims === null) { flash('err', 'تعذّر التحقق من حساب Google، حاول مرة ثانية'); redirect('indexx.php?page=login'); }
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
        redirect('indexx.php');
    }

    if ($action === 'logout') {
        unset($_SESSION['user_id']);
        $_SESSION['cart'] = [];
        redirect('indexx.php');
    }

    // من هنا تحتاج المستخدم مسجّل دخول
    $needsUser = ['add_to_cart','remove_from_cart','checkout','apply_vendor','toggle_favorite','update_profile','submit_complaint','request_topup','complaint_reply','request_withdraw'];
    if (in_array($action, $needsUser, true) && !current_user()) redirect('indexx.php');

    if ($action === 'add_to_cart') {
        $pid = (int)($_POST['product_id'] ?? 0);
        $qty = max(1, (int)($_POST['qty'] ?? 1));
        if (find_product($pid)) {
            $_SESSION['cart'][$pid] = ($_SESSION['cart'][$pid] ?? 0) + $qty;
            flash('ok', 'أُضيف المنتج إلى السلة');
        }
        redirect($_POST['back'] ?? 'indexx.php');
    }

    if ($action === 'remove_from_cart') {
        $pid = (int)($_POST['product_id'] ?? 0);
        unset($_SESSION['cart'][$pid]);
        redirect('indexx.php?page=cart');
    }

    if ($action === 'apply_coupon') {
        $code = strtoupper(trim((string)($_POST['coupon_code'] ?? '')));
        $cart = $_SESSION['cart'] ?? [];
        $matched = false;
        foreach (get_settings()['coupons'] as $c) if ($c['code'] === $code && isset($cart[$c['product_id']])) { $matched = true; break; }
        if ($code === '' || !$matched) {
            unset($_SESSION['cart_coupon']);
            flash('err', $code === '' ? 'تم إلغاء الكوبون' : 'هذا الكود غير صالح أو لا ينطبق على منتج بسلتك');
        } else {
            $_SESSION['cart_coupon'] = $code;
            flash('ok', 'تم تطبيق الكوبون');
        }
        redirect('indexx.php?page=cart');
    }

    if ($action === 'checkout') {
        $cart = $_SESSION['cart'] ?? [];
        if (empty($cart)) redirect('indexx.php?page=cart');
        $deliveryPhone = trim((string)($_POST['delivery_phone'] ?? ''));
        $deliveryLocation = trim((string)($_POST['delivery_location'] ?? ''));
        if ($deliveryPhone === '' || $deliveryLocation === '') {
            flash('err', 'الرجاء إدخال رقم الهاتف والموقع لإتمام الطلب');
            redirect('indexx.php?page=cart');
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
            redirect('indexx.php?page=cart');
        }
        $orders = db_read('orders');
        $stores = db_read('stores');
        foreach ($byStore as $storeId => $items) {
            $subtotal = array_sum(array_map(fn($it) => $it['price'] * $it['qty'], $items));
            $oid = next_id($orders);
            $orders[] = ['id'=>$oid, 'buyer_id'=>$user['id'], 'store_id'=>$storeId, 'items'=>$items, 'total'=>$subtotal, 'status'=>ORDER_STAGES[0], 'delivery_phone'=>$deliveryPhone, 'delivery_location'=>$deliveryLocation, 'created_at'=>time(), 'updated_at'=>time()];
            foreach ($stores as &$s) if ($s['id'] === $storeId) {
                $s['earnings_log'][] = ['amount'=>$subtotal, 'note'=>'قيمة طلب جديد (معلّقة ' . EARNINGS_HOLD_HOURS . ' ساعة)', 'at'=>time(), 'release_at'=>time() + 3600 * EARNINGS_HOLD_HOURS, 'released'=>false];
                if ($s['owner_user_id']) add_notification($s['owner_user_id'], 'طلب جديد #' . $oid, $user['name'] . ' طلب منتجات بقيمة ' . money($subtotal) . ' — راح تتوفر بالرصيد بعد ' . EARNINGS_HOLD_HOURS . ' ساعة', 'indexx.php?page=vendor&section=orders', 'order');
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
        redirect('indexx.php?page=orders');
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
        redirect($_POST['back'] ?? 'indexx.php');
    }

    if ($action === 'update_profile') {
        $users = db_read('users');
        $user = current_user();
        $newEmail = mb_strtolower(trim((string)($_POST['email'] ?? $user['email'])));
        $newPassword = (string)($_POST['password'] ?? '');
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            flash('err', 'البريد الإلكتروني غير صحيح');
            redirect('indexx.php?page=account');
        }
        foreach ($users as $u) if ($u['id'] !== $user['id'] && mb_strtolower($u['email'] ?? '') === $newEmail) {
            flash('err', 'هذا البريد مستخدم من حساب آخر');
            redirect('indexx.php?page=account');
        }
        if ($newPassword !== '' && strlen($newPassword) < 6) {
            flash('err', 'كلمة المرور الجديدة لازم لا تقل عن 6 خانات');
            redirect('indexx.php?page=account');
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
        redirect('indexx.php?page=account');
    }

    if ($action === 'request_topup') {
        $user = current_user();
        $amount = (float)($_POST['amount'] ?? 0);
        $method = trim((string)($_POST['method'] ?? ''));
        $receipt = handle_upload('receipt');
        if ($amount <= 0 || $method === '' || !$receipt) {
            flash('err', 'الرجاء اختيار طريقة الدفع، إدخال المبلغ، ورفع صورة الوصل');
            redirect('indexx.php?page=account');
        }
        $requests = db_read('topup_requests');
        $requests[] = ['id'=>next_id($requests), 'user_id'=>$user['id'], 'method'=>$method, 'amount'=>$amount, 'receipt'=>$receipt, 'status'=>'pending', 'created_at'=>time()];
        db_write('topup_requests', $requests);
        add_notification('admin', 'طلب شحن جديد', $user['name'] . ' طلب شحن ' . money($amount) . ' عبر ' . $method, 'indexx.php?page=admin&section=topups', 'wallet');
        flash('ok', 'تم إرسال طلب الشحن، بانتظار مراجعة الإدارة');
        redirect('indexx.php?page=account');
    }

    if ($action === 'request_withdraw') {
        $store = my_store();
        if (!$store) redirect('indexx.php?page=vendor');
        $amount = (float)($store['earnings'] ?? 0);
        $method = trim((string)($_POST['method'] ?? ''));
        $accountNumber = trim((string)($_POST['account_number'] ?? ''));
        $accountName = trim((string)($_POST['account_name'] ?? ''));
        if ($amount <= 0 || $method === '' || $accountNumber === '' || $accountName === '') {
            flash('err', 'الرجاء تعبئة كل الحقول، والتأكد من وجود رصيد متاح');
            redirect('indexx.php?page=vendor&section=withdraw');
        }
        $existing = array_filter(db_read('withdraw_requests'), fn($r) => $r['store_id'] === $store['id'] && $r['status'] === 'pending');
        if ($existing) {
            flash('err', 'لديك طلب سحب قيد المراجعة بالفعل');
            redirect('indexx.php?page=vendor&section=withdraw');
        }
        $requests = db_read('withdraw_requests');
        $requests[] = [
            'id' => next_id($requests), 'store_id' => $store['id'], 'owner_user_id' => $store['owner_user_id'],
            'amount' => $amount, 'method' => $method, 'account_number' => $accountNumber, 'account_name' => $accountName,
            'status' => 'pending', 'created_at' => time(),
        ];
        db_write('withdraw_requests', $requests);
        add_notification('admin', 'طلب سحب رصيد جديد', $store['name'] . ' طلب سحب ' . money($amount) . ' عبر ' . $method, 'indexx.php?page=admin&section=withdrawals', 'wallet');
        flash('ok', 'تم إرسال طلب السحب، بانتظار مراجعة الإدارة');
        redirect('indexx.php?page=vendor&section=withdraw');
    }

    if ($action === 'apply_vendor') {
        $user = current_user();
        if (my_store() || my_pending_store()) redirect('indexx.php?page=account');
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
        add_notification('admin', 'طلب انضمام جديد', $user['name'] . ' قدّم طلب انضمام كتاجر (' . trim((string)($_POST['name'] ?? '')) . ')', 'indexx.php?page=admin&section=applications', 'store');
        flash('ok', 'تم إرسال طلبك بنجاح، سيتم مراجعته من قبل الإدارة قريباً');
        redirect('indexx.php?page=account');
    }

    if ($action === 'submit_complaint') {
        $user = current_user();
        $orderId = (int)($_POST['order_id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        $details = trim((string)($_POST['details'] ?? ''));
        $order = null;
        foreach (db_read('orders') as $o) if ($o['id'] === $orderId && $o['buyer_id'] === $user['id']) { $order = $o; break; }
        if (!$order || $reason === '') { flash('err', 'الرجاء اختيار الطلب وسبب المشكلة'); redirect('indexx.php?page=ai'); }
        $complaints = db_read('complaints');
        $cid = next_id($complaints);
        $messages = [['from'=>'buyer', 'text'=>$reason . ($details !== '' ? ' — ' . $details : ''), 'at'=>time()]];
        $complaints[] = [
            'id'=>$cid, 'buyer_id'=>$user['id'], 'order_id'=>$orderId, 'store_id'=>$order['store_id'],
            'reason'=>$reason, 'details'=>$details, 'messages'=>$messages,
            'status'=>'open', 'created_at'=>time(),
        ];
        db_write('complaints', $complaints);
        add_notification('admin', 'شكوى جديدة #' . $cid, $user['name'] . ' رفع شكوى على الطلب #' . $orderId . ' (' . $reason . ')', 'indexx.php?page=admin&section=complaints', 'complaint');
        redirect('indexx.php?page=ai');
    }

    if ($action === 'complaint_reply') {
        $user = current_user();
        $cid = (int)($_POST['complaint_id'] ?? 0);
        $text = trim((string)($_POST['text'] ?? ''));
        if ($text === '') redirect('indexx.php?page=ai');
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
            if (is_admin_user()) add_notification($target['buyer_id'], 'رد جديد على شكواك #' . $cid, $text, 'indexx.php?page=ai', 'complaint');
            else add_notification('admin', 'رد جديد على الشكوى #' . $cid, $user['name'] . ': ' . $text, 'indexx.php?page=admin&section=complaints', 'complaint');
        }
        redirect(is_admin_user() ? 'indexx.php?page=admin&section=complaints&id=' . $cid : 'indexx.php?page=ai');
    }

    // إجراءات التاجر
    $vendorActions = ['vendor_add_product','vendor_edit_product','vendor_delete_product','vendor_update_order','vendor_save_theme','vendor_save_info','vendor_add_section','vendor_rename_section','vendor_delete_section','vendor_move_section'];
    if (in_array($action, $vendorActions, true)) {
        $store = my_store();
        if (!$store) redirect('indexx.php');

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
            redirect('indexx.php?page=vendor&section=products');
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
            redirect('indexx.php?page=vendor&section=products');
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
            redirect('indexx.php?page=vendor&section=sections');
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
            redirect('indexx.php?page=vendor&section=sections');
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
            redirect('indexx.php?page=vendor&section=sections');
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
            redirect('indexx.php?page=vendor&section=sections');
        }

        if ($action === 'vendor_delete_product') {
            $pid = (int)$_POST['product_id'];
            $products = array_values(array_filter(db_read('products'), fn($p) => !($p['id'] === $pid && $p['store_id'] === $store['id'])));
            db_write('products', $products);
            flash('ok', 'تم حذف المنتج');
            redirect('indexx.php?page=vendor&section=products');
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
            if ($buyerId) add_notification($buyerId, 'تحديث طلبك #' . $oid, 'طلبك صار: ' . $newStatus, 'indexx.php?page=orders', 'order');
            redirect('indexx.php?page=vendor&section=orders');
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
            redirect('indexx.php?page=vendor&section=theme');
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
            redirect('indexx.php?page=vendor&section=info');
        }
    }

    // إجراءات الأدمن
    $adminActions = ['admin_approve','admin_reject','admin_topup','admin_toggle_featured','admin_collect_fee','admin_update_fee','admin_update_branding','admin_update_ai_key','admin_add_payment_method','admin_delete_payment_method','admin_approve_topup','admin_reject_topup','admin_approve_withdraw','admin_reject_withdraw','admin_resolve_complaint','admin_toggle_suspend','admin_add_category','admin_delete_category','admin_toggle_admin','admin_broadcast','admin_add_coupon','admin_delete_coupon','admin_update_backup','admin_backup_now'];
    if (in_array($action, $adminActions, true)) {
        if (!is_admin_user()) redirect('indexx.php');

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
            if ($owner) add_notification($owner, 'تم قبول متجرك 🎉', 'تهانينا! متجرك فعّال الحين بالمنصة، اشتراكك يمتد ' . SUBSCRIPTION_DAYS . ' يوم.', 'indexx.php?page=vendor', 'store');
            flash('ok', 'تم قبول طلب التاجر');
            redirect('indexx.php?page=admin&section=applications');
        }
        if ($action === 'admin_reject') {
            $stores = db_read('stores');
            $owner = null;
            foreach ($stores as &$s) if ($s['id'] === (int)$_POST['store_id']) { $s['status'] = 'rejected'; $owner = $s['owner_user_id']; }
            unset($s);
            db_write('stores', $stores);
            if ($owner) add_notification($owner, 'تم رفض طلب متجرك', 'للأسف تمت مراجعة طلبك كتاجر ولم تتم الموافقة عليه.', 'indexx.php?page=account', 'store');
            flash('ok', 'تم رفض الطلب');
            redirect('indexx.php?page=admin&section=applications');
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
            redirect('indexx.php?page=admin&section=users');
        }
        if ($action === 'admin_toggle_featured') {
            $stores = db_read('stores');
            foreach ($stores as &$s) if ($s['id'] === (int)$_POST['store_id']) $s['featured'] = !$s['featured'];
            unset($s);
            db_write('stores', $stores);
            redirect('indexx.php?page=admin&section=stores');
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
            if ($owner) add_notification($owner, $nowSuspended ? 'تم تعليق متجرك' : 'تم تفعيل متجرك', $nowSuspended ? 'قامت الإدارة بتعليق متجرك مؤقتاً، وما راح يظهر بالسوق لحد ما يتفعّل.' : 'رجع متجرك يظهر بالسوق من جديد.', 'indexx.php?page=vendor', 'store');
            flash('ok', $nowSuspended ? 'تم تعليق المتجر' : 'تم إعادة تفعيل المتجر');
            redirect('indexx.php?page=admin&section=stores');
        }
        if ($action === 'admin_add_category') {
            $settings = get_settings();
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name !== '' && !in_array($name, $settings['categories'], true)) $settings['categories'][] = $name;
            db_write('settings', $settings);
            flash('ok', 'تمت إضافة التصنيف');
            redirect('indexx.php?page=admin&section=settings');
        }
        if ($action === 'admin_delete_category') {
            $settings = get_settings();
            $settings['categories'] = array_values(array_filter($settings['categories'], fn($c) => $c !== ($_POST['name'] ?? '')));
            db_write('settings', $settings);
            flash('ok', 'تم حذف التصنيف');
            redirect('indexx.php?page=admin&section=settings');
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
            redirect('indexx.php?page=admin&section=users');
        }
        if ($action === 'admin_broadcast') {
            $title = trim((string)($_POST['title'] ?? ''));
            $body = trim((string)($_POST['body'] ?? ''));
            if ($title !== '' && $body !== '') {
                foreach (db_read('users') as $u) add_notification($u['id'], $title, $body, null, 'broadcast');
                flash('ok', 'تم إرسال الإشعار لكل المستخدمين');
            }
            redirect('indexx.php?page=admin&section=broadcast');
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
            if ($owner) add_notification($owner, 'تجديد الاشتراك', 'تم تحصيل ' . money($fee) . ' وتجديد اشتراك متجرك ' . SUBSCRIPTION_DAYS . ' يوم إضافي.', 'indexx.php?page=vendor', 'store');
            flash('ok', 'تم تحصيل الرسم وتجديد الاشتراك ' . SUBSCRIPTION_DAYS . ' يوم');
            redirect('indexx.php?page=admin&section=stores');
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
                add_notification($req['owner_user_id'], 'تم قبول طلب سحبك', 'تم تحويل ' . money($req['amount']) . ' لحسابك عبر ' . $req['method'] . '.', 'indexx.php?page=vendor&section=withdraw', 'wallet');
            }
            flash('ok', 'تم اعتماد طلب السحب');
            redirect('indexx.php?page=admin&section=withdrawals');
        }
        if ($action === 'admin_reject_withdraw') {
            $rid = (int)($_POST['request_id'] ?? 0);
            $requests = db_read('withdraw_requests');
            $uid = null;
            foreach ($requests as &$r) if ($r['id'] === $rid && $r['status'] === 'pending') { $r['status'] = 'rejected'; $uid = $r['owner_user_id']; }
            unset($r);
            db_write('withdraw_requests', $requests);
            if ($uid) add_notification($uid, 'تم رفض طلب سحبك', 'للأسف تمت مراجعة طلب السحب ولم تتم الموافقة عليه.', 'indexx.php?page=vendor&section=withdraw', 'wallet');
            flash('ok', 'تم رفض طلب السحب');
            redirect('indexx.php?page=admin&section=withdrawals');
        }
        if ($action === 'admin_update_fee') {
            $settings = get_settings();
            $settings['monthly_fee'] = (float)($_POST['monthly_fee'] ?? 0);
            db_write('settings', $settings);
            flash('ok', 'تم تحديث قيمة الرسم الشهري');
            redirect('indexx.php?page=admin&section=settings');
        }
        if ($action === 'admin_update_branding') {
            $settings = get_settings();
            $name = trim((string)($_POST['site_name'] ?? ''));
            if ($name !== '') $settings['site_name'] = $name;
            $logo = handle_upload('site_logo');
            if ($logo) $settings['site_logo'] = $logo;
            db_write('settings', $settings);
            flash('ok', 'تم تحديث اسم وشعار الموقع');
            redirect('indexx.php?page=admin&section=settings');
        }
        if ($action === 'admin_update_ai_key') {
            $settings = get_settings();
            $settings['ai_api_key'] = trim((string)($_POST['ai_api_key'] ?? ''));
            db_write('settings', $settings);
            flash('ok', 'تم تحديث مفتاح المساعد الذكي');
            redirect('indexx.php?page=admin&section=settings');
        }
        if ($action === 'admin_update_backup') {
            $settings = get_settings();
            $settings['telegram_bot_token'] = trim((string)($_POST['telegram_bot_token'] ?? ''));
            $settings['telegram_chat_id'] = trim((string)($_POST['telegram_chat_id'] ?? ''));
            db_write('settings', $settings);
            flash('ok', 'تم حفظ إعدادات النسخ الاحتياطي');
            redirect('indexx.php?page=admin&section=backup');
        }
        if ($action === 'admin_backup_now') {
            $result = run_backup_now();
            flash($result['ok'] ? 'ok' : 'err', $result['msg']);
            redirect('indexx.php?page=admin&section=backup');
        }
        if ($action === 'admin_add_coupon') {
            $settings = get_settings();
            $code = strtoupper(trim((string)($_POST['code'] ?? '')));
            $pid = (int)($_POST['product_id'] ?? 0);
            $percent = max(1, min(90, (float)($_POST['percent'] ?? 0)));
            if ($code === '' || !find_product($pid)) {
                flash('err', 'الرجاء اختيار منتج وكتابة كود صحيح');
                redirect('indexx.php?page=admin&section=settings');
            }
            $coupons = $settings['coupons'];
            $coupons[] = ['id'=>next_id($coupons), 'code'=>$code, 'product_id'=>$pid, 'percent'=>$percent];
            $settings['coupons'] = $coupons;
            db_write('settings', $settings);
            flash('ok', 'تمت إضافة الكوبون');
            redirect('indexx.php?page=admin&section=settings');
        }
        if ($action === 'admin_delete_coupon') {
            $settings = get_settings();
            $cid = (int)($_POST['coupon_id'] ?? 0);
            $settings['coupons'] = array_values(array_filter($settings['coupons'], fn($c) => $c['id'] !== $cid));
            db_write('settings', $settings);
            flash('ok', 'تم حذف الكوبون');
            redirect('indexx.php?page=admin&section=settings');
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
            redirect('indexx.php?page=admin&section=settings');
        }
        if ($action === 'admin_delete_payment_method') {
            $settings = get_settings();
            $mid = (int)$_POST['method_id'];
            $settings['payment_methods'] = array_values(array_filter($settings['payment_methods'], fn($m) => $m['id'] !== $mid));
            db_write('settings', $settings);
            flash('ok', 'تم حذف طريقة الدفع');
            redirect('indexx.php?page=admin&section=settings');
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
                    $u['wallet_log'][] = ['amount'=>(float)$req['amount'], 'note'=>'شحن يدوي معتمد (' . $req['method'] . ')', 'at'=>time()];
                }
                unset($u);
                db_write('users', $users);
                add_notification($req['user_id'], 'تم قبول طلب شحنك', 'تمت إضافة ' . money($req['amount']) . ' لرصيدك.', 'indexx.php?page=account', 'wallet');
                flash('ok', 'تم قبول طلب الشحن وإضافة الرصيد');
            }
            redirect('indexx.php?page=admin&section=topups');
        }
        if ($action === 'admin_reject_topup') {
            $rid = (int)$_POST['request_id'];
            $requests = db_read('topup_requests');
            $uid = null;
            foreach ($requests as &$r) if ($r['id'] === $rid && $r['status'] === 'pending') { $r['status'] = 'rejected'; $uid = $r['user_id']; }
            unset($r);
            db_write('topup_requests', $requests);
            if ($uid) add_notification($uid, 'تم رفض طلب شحنك', 'للأسف تمت مراجعة طلب الشحن ولم تتم الموافقة عليه.', 'indexx.php?page=account', 'wallet');
            flash('ok', 'تم رفض طلب الشحن');
            redirect('indexx.php?page=admin&section=topups');
        }
        if ($action === 'admin_resolve_complaint') {
            $cid = (int)$_POST['complaint_id'];
            $complaints = db_read('complaints');
            $buyerId = null; $orderId = null;
            foreach ($complaints as &$c) if ($c['id'] === $cid) { $c['status'] = 'resolved'; $buyerId = $c['buyer_id']; $orderId = $c['order_id']; }
            unset($c);
            db_write('complaints', $complaints);
            if ($buyerId) add_notification($buyerId, 'تمت معالجة شكواك', 'تمت معالجة شكواك على الطلب #' . $orderId . '.', 'indexx.php?page=orders', 'complaint');
            redirect('indexx.php?page=admin&section=complaints');
        }
    }

    // إجراءات الإشعارات (متاحة لأي هوية مسجّلة: مشتري أو أدمن)
    $notifActions = ['notif_mark_read', 'notif_mark_all_read', 'notif_delete'];
    if (in_array($action, $notifActions, true)) {
        $recipient = my_notif_recipient();
        if ($recipient === null) redirect('indexx.php');
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
        redirect('indexx.php?page=notifications');
    }

    redirect('indexx.php');
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
        'رصيد'=>'الرصيد يضيفه لك الأدمن يدوياً حالياً. تواصل مع إدارة التطبيق لشحن رصيدك، وبعدها تكدر تشتري من أي متجر بيه.',
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

/* ===================== CSS و JS المشتركة ===================== */
function render_css(): void { ?>
*{margin:0;padding:0;box-sizing:border-box;-webkit-tap-highlight-color:transparent}
[hidden]{display:none!important}
html{scroll-behavior:smooth}
:root{--bg:#f8f6f3;--card:#fff;--text:#1a1a2e;--muted:#6b7280;--accent:#f2b100;--accent2:#ffcf40;--shadow:0 1px 2px rgba(242,177,0,.08),0 6px 18px rgba(242,177,0,.08);--border:rgba(242,177,0,.14);--hover-bg:rgba(242,177,0,.07);--gradient:linear-gradient(135deg,#f2b100,#ffcf40);--danger:#e5484d;--success:#2f9e5c;--radius:16px}
[data-theme="dark"]{--bg:#0d0d0d;--card:#1a1a1a;--text:#f0ece0;--muted:#a89f8e;--accent:#ffcf40;--accent2:#ffe27a;--shadow:0 1px 2px rgba(0,0,0,.5),0 6px 20px rgba(0,0,0,.6);--border:rgba(255,207,64,.14);--hover-bg:rgba(255,207,64,.08);--gradient:linear-gradient(135deg,#ffcf40,#ffe27a)}
body{font-family:'IBM Plex Sans Arabic',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;min-height:100dvh;overflow-x:hidden;position:relative;transition:background .3s,color .3s;animation:pageIn .5s ease both;scrollbar-width:none}
body::-webkit-scrollbar{display:none}
@keyframes pageIn{from{opacity:0}to{opacity:1}}
@keyframes fadeUp{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:translateY(0)}}
.an{animation:fadeUp .5s cubic-bezier(.22,1,.36,1) both;animation-delay:var(--ad,0s)}
.bg-orb{position:fixed;border-radius:50%;filter:blur(80px);pointer-events:none;z-index:0}
.bo1{width:280px;height:280px;background:rgba(242,177,0,.10);top:-100px;right:-80px}
.bo2{width:220px;height:220px;background:rgba(255,207,64,.08);bottom:10%;left:-70px}
.z1{position:relative;z-index:1}
a{color:inherit;text-decoration:none}
button,input,select,textarea{font-family:inherit;color:inherit}
.topbar{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;position:sticky;top:0;z-index:50;background:var(--bg);backdrop-filter:blur(10px)}
.logo{font-size:1.2rem;font-weight:900;display:flex;align-items:center;gap:8px;background:var(--gradient);-webkit-background-clip:text;-webkit-text-fill-color:transparent}
.logo-img{width:24px;height:24px;border-radius:8px;object-fit:cover}
.install-banner{display:flex;align-items:center;gap:10px;background:var(--gradient);color:#1a1a2e;font-size:.72rem;font-weight:700;padding:9px 14px;position:sticky;top:56px;z-index:45}
.install-banner span{flex:1}
.topbar-right{display:flex;gap:8px}
.icon-btn{position:relative;width:38px;height:38px;border-radius:50%;background:var(--card);box-shadow:var(--shadow);border:none;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.95rem;color:var(--text);transition:transform .15s}
.icon-btn:active{transform:scale(.88)}
.icon-btn .badge{position:absolute;top:-4px;left:-4px;background:var(--danger);color:#fff;font-size:.6rem;font-weight:800;border-radius:100px;min-width:16px;height:16px;display:flex;align-items:center;justify-content:center;padding:0 3px}
.icon-btn .sun{display:none}[data-theme="dark"] .icon-btn .sun{display:inline;color:var(--accent)}[data-theme="dark"] .icon-btn .moon{display:none}
.content{max-width:520px;margin:0 auto;padding:4px 16px 100px}
#app-root.nav-loading{pointer-events:none}
.flash{padding:12px 16px;border-radius:14px;margin-bottom:14px;font-size:.82rem;font-weight:600}
.flash-ok{background:rgba(47,158,92,.12);color:var(--success);border:1px solid rgba(47,158,92,.25)}
.flash-err{background:rgba(229,72,77,.12);color:var(--danger);border:1px solid rgba(229,72,77,.25)}
.tabbar{position:fixed;bottom:0;right:0;left:0;z-index:50;display:flex;background:var(--card);box-shadow:0 -2px 16px rgba(0,0,0,.08);padding:6px 4px calc(6px + env(safe-area-inset-bottom,0px));z-index:60}
.tab{flex:1;display:flex;flex-direction:column;align-items:center;gap:3px;padding:6px 2px;font-size:.62rem;font-weight:700;color:var(--muted);border-radius:12px;position:relative;transition:color .2s}
.tab i{font-size:1.05rem}
.tab.active{color:var(--accent)}
.tab-badge{position:absolute;top:0;left:22%;background:var(--danger);color:#fff;font-size:.55rem;font-weight:800;border-radius:100px;min-width:14px;height:14px;display:flex;align-items:center;justify-content:center}
.tab-ai{gap:0}
.tab-ai i{width:40px;height:40px;border-radius:50%;background:var(--gradient);color:#1a1a2e;display:flex;align-items:center;justify-content:center;font-size:1.15rem;margin-top:-20px;box-shadow:0 6px 16px rgba(242,177,0,.45);border:3px solid var(--card);transition:transform .2s}
.tab-ai:active i{transform:scale(.9)}
.tab-ai span{margin-top:2px}
.tab-ai.active span{color:var(--accent)}
.sec{margin-bottom:22px}
.sh{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.sh h3{font-size:.9rem;font-weight:800;display:flex;align-items:center;gap:8px}
.sh .al{font-size:.68rem;color:var(--accent);font-weight:700}
.wallet-card{border-radius:22px;padding:22px 18px;background:var(--gradient);color:#1a1a2e;position:relative;overflow:hidden;box-shadow:0 10px 28px rgba(242,177,0,.3);margin-bottom:18px}
.wallet-card::after{content:'';position:absolute;bottom:-30px;left:-30px;width:120px;height:120px;border-radius:50%;background:rgba(255,255,255,.18)}
.wallet-top{display:flex;justify-content:space-between;align-items:flex-start;position:relative;z-index:1}
.wallet-top .greet{font-size:.8rem;font-weight:700;opacity:.85}
.wallet-bal{font-size:1.7rem;font-weight:900;margin-top:4px}
.wallet-note{font-size:.65rem;margin-top:10px;opacity:.85;position:relative;z-index:1}
.hscroll{display:flex;gap:10px;overflow-x:auto;padding-bottom:4px;scrollbar-width:none}
.hscroll::-webkit-scrollbar{display:none}
.cat-chip{flex-shrink:0;display:flex;flex-direction:column;align-items:center;gap:6px;padding:10px 14px;border-radius:16px;background:var(--card);box-shadow:var(--shadow);font-size:.65rem;font-weight:700;min-width:66px}
.cat-chip i{font-size:1.1rem;color:var(--accent)}
.cat-chip.active{background:var(--gradient);color:#1a1a2e}
.search-bar{display:flex;align-items:center;gap:8px;background:var(--card);border-radius:100px;box-shadow:var(--shadow);padding:10px 16px;margin-bottom:14px}
.search-bar input{flex:1;border:none;background:none;outline:none;font-size:.8rem}
.search-bar i{color:var(--muted)}
.store-card{display:flex;align-items:center;gap:12px;background:var(--card);border-radius:16px;padding:12px;margin-bottom:8px;box-shadow:var(--shadow);transition:transform .15s}
.store-card:active{transform:scale(.97)}
.store-card-logo{width:48px;height:48px;border-radius:14px;display:flex;align-items:center;justify-content:center;color:#1a1a2e;flex-shrink:0;overflow:hidden}
.store-card-logo img{width:100%;height:100%;object-fit:cover}
.store-card-info{flex:1;min-width:0}
.store-card-info h4{font-size:.82rem;font-weight:700;margin-bottom:2px;display:flex;align-items:center;gap:5px}
.store-card-info p{font-size:.68rem;color:var(--muted)}
.verified{color:var(--accent);font-size:.7rem}
.stars{color:#f5b400;font-size:.7rem;letter-spacing:1px}
.stars-num{font-size:.68rem;color:var(--muted);font-weight:700}
.cv{color:var(--muted);font-size:.65rem;opacity:.5}
.store-grid6{display:grid;grid-template-columns:repeat(6,1fr);gap:10px 4px}
.store-tile{display:flex;flex-direction:column;align-items:center;gap:5px;text-align:center;transition:transform .15s}
.store-tile:active{transform:scale(.94)}
.store-tile-icon{width:100%;aspect-ratio:1;border-radius:18px;background:var(--hover-bg);display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:#1a1a2e;overflow:hidden;box-shadow:var(--shadow)}
.store-tile-icon img{width:100%;height:100%;object-fit:cover}
.store-tile-name{font-size:.62rem;font-weight:700;color:var(--text);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.3;word-break:break-word}
.hgrid{display:flex;gap:10px;overflow-x:auto;scrollbar-width:none;padding-bottom:4px}
.hgrid::-webkit-scrollbar{display:none}
.hgrid .prod-card{min-width:150px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.grid3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px}
.prod-card{background:var(--card);border-radius:16px;overflow:hidden;box-shadow:var(--shadow);transition:transform .15s}
.prod-card:active{transform:scale(.97)}
.prod-card-img{aspect-ratio:1;background:var(--hover-bg);display:flex;align-items:center;justify-content:center;position:relative;overflow:hidden}
.prod-card-img img{width:100%;height:100%;object-fit:cover}
.prod-card-img i{font-size:1.8rem;color:var(--accent);opacity:.5}
.prod-badge{position:absolute;top:6px;right:6px;background:var(--danger);color:#fff;font-size:.6rem;font-weight:800;padding:2px 7px;border-radius:8px}
.prod-card-info{padding:10px}
.prod-card-info h4{font-size:.74rem;font-weight:700;margin-bottom:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.prod-store{font-size:.62rem;color:var(--muted);margin-bottom:4px}
.prod-price .now{font-size:.8rem;font-weight:800;color:var(--accent)}
[data-theme="dark"] .prod-price .now{color:#d9a300}
.prod-price .was{font-size:.65rem;color:var(--muted);text-decoration:line-through;margin-inline-start:5px}
.empty-state{text-align:center;padding:50px 20px;color:var(--muted)}
.empty-state i{font-size:2.2rem;margin-bottom:10px;opacity:.4}
.card{background:var(--card);border-radius:18px;padding:16px;box-shadow:var(--shadow)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:12px 20px;border-radius:14px;background:var(--gradient);color:#1a1a2e;font-weight:800;font-size:.82rem;border:none;cursor:pointer;width:100%;transition:transform .15s}
.btn:active{transform:scale(.96)}
.btn-outline{background:none;border:2px solid var(--accent);color:var(--accent)}
.btn-danger{background:var(--danger);color:#fff}
.btn-sm{width:auto;padding:8px 16px;font-size:.72rem}
.field{margin-bottom:14px}
.field label{display:block;font-size:.72rem;font-weight:700;margin-bottom:6px;color:var(--muted)}
.field input,.field select,.field textarea{width:100%;padding:11px 14px;border-radius:12px;border:1px solid var(--border);background:var(--card);font-size:.82rem;outline:none}
.field textarea{resize:vertical;min-height:80px}
.field input[type=file]{padding:9px}
.field input[type=color]{padding:4px;height:44px;cursor:pointer}
.login-wrap{min-height:100vh;min-height:100dvh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:24px;position:relative;z-index:1}
.login-logo{font-size:2rem;font-weight:900;display:flex;align-items:center;gap:10px;background:var(--gradient);-webkit-background-clip:text;-webkit-text-fill-color:transparent;margin-bottom:6px}
.login-logo-img{width:40px;height:40px;border-radius:12px;object-fit:cover}
.login-sub{font-size:.78rem;color:var(--muted);margin-bottom:26px;text-align:center}
.login-card{width:100%;max-width:360px;background:var(--card);border-radius:22px;padding:24px;box-shadow:var(--shadow)}
.login-admin-link{margin-top:18px;font-size:.7rem;color:var(--muted);text-align:center}
.auth-divider{display:flex;align-items:center;gap:10px;margin:16px 0;font-size:.68rem;color:var(--muted)}
.auth-divider::before,.auth-divider::after{content:'';flex:1;height:1px;background:var(--border)}
.google-btn-wrap{display:flex;justify-content:center;min-height:40px}
.auth-page{min-height:100vh;min-height:100dvh;position:relative;overflow:hidden;padding:22px 22px calc(22px + env(safe-area-inset-bottom,0px));display:flex;flex-direction:column;z-index:1}
.auth-blob{position:absolute;border-radius:50%;z-index:-1}
.auth-blob-tl{width:230px;height:230px;background:var(--accent2);top:-100px;left:-100px;opacity:.4}
.auth-blob-br{width:260px;height:260px;background:var(--accent2);bottom:-120px;right:-120px;opacity:.35}
.auth-skip{align-self:flex-start;font-size:.78rem;font-weight:700;color:var(--text);display:flex;align-items:center;gap:6px;text-decoration:none}
.auth-hero{position:relative;display:flex;align-items:center;justify-content:center;margin:22px auto 14px;width:100%;max-width:250px;height:300px}
.auth-hero.sm{max-width:170px;height:190px;margin:8px auto 16px}
.auth-hero-bubble{position:absolute;width:50px;height:50px;border-radius:16px;background:var(--card);box-shadow:var(--shadow);display:flex;align-items:center;justify-content:center;font-size:1.15rem;color:var(--accent);z-index:2}
.auth-hero.sm .auth-hero-bubble{width:34px;height:34px;border-radius:11px;font-size:.85rem}
.auth-hero-bubble.b1{top:6%;left:-4%}
.auth-hero-bubble.b2{top:2%;right:-6%}
.auth-hero-bubble.b3{bottom:22%;left:-8%}
.auth-hero-bubble.b4{bottom:4%;right:-4%}
.phone-mock{width:64%;height:94%;background:#1a1a2e;border-radius:32px;padding:8px;box-shadow:0 22px 44px rgba(0,0,0,.22);position:relative}
.auth-hero.sm .phone-mock{border-radius:22px;padding:5px}
.phone-mock::before{content:'';position:absolute;top:8px;left:50%;transform:translateX(-50%);width:34%;height:14px;background:#1a1a2e;border-radius:0 0 10px 10px;z-index:2}
.phone-mock-screen{width:100%;height:100%;background:var(--card);border-radius:24px;overflow:hidden;padding:14px 8px 8px;display:flex;flex-direction:column;gap:6px}
.auth-hero.sm .phone-mock-screen{border-radius:16px;padding:9px 6px 6px;gap:4px}
.phone-mock-brand{font-size:.6rem;font-weight:900;text-align:center;color:var(--accent);margin-bottom:2px}
.auth-hero.sm .phone-mock-brand{font-size:.5rem}
.phone-mock-search{height:16px;border-radius:8px;background:var(--hover-bg);display:flex;align-items:center;padding:0 6px;color:var(--muted);font-size:.5rem}
.phone-mock-cats{display:flex;gap:5px;justify-content:center}
.phone-mock-cats span{flex:1;aspect-ratio:1;border-radius:8px;background:var(--gradient);display:flex;align-items:center;justify-content:center;font-size:.5rem;color:#1a1a2e;max-width:24px}
.phone-mock-row{font-size:.48rem;font-weight:800;color:var(--text);margin-top:2px}
.phone-mock-cards{display:flex;gap:5px;flex:1}
.phone-mock-cards span{flex:1;border-radius:8px;background:var(--hover-bg);display:flex;align-items:center;justify-content:center;color:var(--accent);font-size:.6rem}
.auth-heading{font-size:1.2rem;font-weight:900;text-align:center;margin:4px 0 6px;line-height:1.5}
.auth-heading-sub{font-size:.78rem;color:var(--muted);text-align:center;margin-bottom:18px;line-height:1.7}
.auth-dots{display:flex;gap:6px;justify-content:center;margin:16px 0 4px}
.auth-dots span{width:7px;height:7px;border-radius:50%;background:var(--border);display:block}
.auth-dots span.active{width:20px;border-radius:4px;background:var(--accent)}
.field-icon-wrap{position:relative}
.field-icon-wrap input{padding-right:42px}
.field-icon-wrap .field-ic{position:absolute;right:14px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:.85rem;pointer-events:none}
.field-icon-wrap .pw-toggle{position:absolute;right:14px;top:50%;transform:translateY(-50%);color:var(--muted);font-size:.85rem;background:none;border:none;cursor:pointer;padding:4px}
.auth-forgot{display:block;width:fit-content;margin:-6px 0 6px;font-size:.7rem;color:var(--accent);cursor:pointer;list-style:none}
.auth-forgot::-webkit-details-marker{display:none}
.auth-forgot-note{font-size:.68rem;color:var(--muted);margin:-4px 0 12px;line-height:1.7}
.captcha-box{display:flex;gap:10px;justify-content:center;padding:14px 10px;background:repeating-linear-gradient(135deg,var(--hover-bg),var(--hover-bg) 6px,transparent 6px,transparent 12px);border-radius:12px;border:1px dashed var(--border);user-select:none;-webkit-user-select:none;-moz-user-select:none;pointer-events:none}
.captcha-box span{font-size:1.3rem;font-weight:900;font-family:monospace;letter-spacing:2px;color:var(--text);display:inline-block}
.stepper{display:flex;justify-content:space-between;position:relative;margin:18px 0 6px}
.stepper::before{content:'';position:absolute;top:7px;right:6%;left:6%;height:2px;background:var(--border);z-index:0}
.step{flex:1;text-align:center;position:relative;z-index:1}
.step .dot{display:block;width:16px;height:16px;border-radius:50%;background:var(--border);margin:0 auto 6px;border:3px solid var(--bg)}
.step.done .dot{background:var(--accent)}
.step .lbl{font-size:.55rem;color:var(--muted);font-weight:700}
.step.current .lbl{color:var(--accent)}
.order-card{background:var(--card);border-radius:16px;padding:14px;margin-bottom:12px;box-shadow:var(--shadow)}
.order-top{display:flex;justify-content:space-between;align-items:center;font-size:.75rem;font-weight:700}
.order-items{font-size:.7rem;color:var(--muted);margin-top:6px}
.chips{display:flex;gap:8px;overflow-x:auto;margin-bottom:14px;scrollbar-width:none}
.chips::-webkit-scrollbar{display:none}
.chip{flex-shrink:0;padding:8px 16px;border-radius:100px;background:var(--card);box-shadow:var(--shadow);font-size:.72rem;font-weight:700;color:var(--muted)}
.chip.active{background:var(--gradient);color:#1a1a2e}
.gallery-main{aspect-ratio:1;border-radius:18px;overflow:hidden;background:var(--card);box-shadow:var(--shadow);display:flex;align-items:center;justify-content:center;margin-bottom:10px;cursor:zoom-in}
.gallery-main img{width:100%;height:100%;object-fit:cover}
.gallery-main i{font-size:3rem;color:var(--accent);opacity:.4}
.gallery-thumbs{display:flex;gap:8px;margin-bottom:16px}
.gallery-thumbs img{width:56px;height:56px;object-fit:cover;border-radius:10px;cursor:pointer;opacity:.6;box-shadow:var(--shadow)}
.gallery-thumbs img.active{opacity:1;outline:2px solid var(--accent)}
.lightbox{position:fixed;inset:0;background:rgba(0,0,0,.9);z-index:200;display:none;align-items:center;justify-content:center}
.lightbox.open{display:flex}
.lightbox img{max-width:92%;max-height:92%;object-fit:contain;border-radius:8px}
.qty-box{display:flex;align-items:center;gap:14px;background:var(--card);border-radius:14px;padding:8px 14px;box-shadow:var(--shadow);width:fit-content;margin-bottom:14px}
.qty-box button{width:28px;height:28px;border-radius:8px;border:none;background:var(--hover-bg);color:var(--text);font-weight:800;cursor:pointer}
.cart-store-group{margin-bottom:16px}
.cart-store-title{font-size:.78rem;font-weight:800;margin-bottom:8px;display:flex;align-items:center;gap:6px}
.cart-item{display:flex;align-items:center;gap:10px;background:var(--card);border-radius:14px;padding:10px;margin-bottom:8px;box-shadow:var(--shadow)}
.cart-item-img{width:46px;height:46px;border-radius:10px;background:var(--hover-bg);display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden}
.cart-item-img img{width:100%;height:100%;object-fit:cover}
.cart-item-info{flex:1;min-width:0;font-size:.75rem;font-weight:700}
.cart-item-info span{display:block;font-size:.68rem;color:var(--muted);font-weight:600;margin-top:2px}
.cart-remove{color:var(--danger);background:none;border:none;font-size:.85rem;cursor:pointer;padding:6px}
.cart-total{display:flex;justify-content:space-between;font-weight:800;font-size:.95rem;padding:14px 0;border-top:1px solid var(--border);margin-top:6px}
.section-tabs{display:flex;gap:8px;overflow-x:auto;margin-bottom:16px;scrollbar-width:none}
.section-tabs::-webkit-scrollbar{display:none}
.section-tabs a{flex-shrink:0;padding:9px 16px;border-radius:100px;background:var(--card);box-shadow:var(--shadow);font-size:.72rem;font-weight:700;color:var(--muted)}
.section-tabs a.active{background:var(--gradient);color:#1a1a2e}
.admin-topbar{display:flex;align-items:center;gap:12px;margin-bottom:16px}
.admin-topbar strong{font-size:.95rem;font-weight:800}
.admin-sidebar{position:fixed;top:0;bottom:0;right:0;width:78%;max-width:280px;background:var(--card);z-index:120;transform:translateX(100%);transition:transform .25s cubic-bezier(.22,1,.36,1);overflow-y:auto;padding:10px 0 calc(10px + env(safe-area-inset-bottom,0px))}
.admin-sidebar.open{transform:translateX(0)}
.admin-sidebar-head{display:flex;justify-content:space-between;align-items:center;padding:8px 16px 14px;border-bottom:1px solid var(--border);margin-bottom:8px;font-weight:800;font-size:.85rem}
.admin-sidebar a{display:block;padding:13px 16px;font-size:.8rem;font-weight:700;color:var(--text);text-decoration:none;border-right:3px solid transparent}
.admin-sidebar a.active{color:var(--accent);border-right-color:var(--accent);background:var(--hover-bg)}
.stat-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:18px}
.stat-box{background:var(--card);border-radius:16px;padding:14px;box-shadow:var(--shadow);text-align:center}
.stat-box .num{font-size:1.3rem;font-weight:900;color:var(--accent)}
.stat-box .lbl{font-size:.65rem;color:var(--muted);font-weight:700;margin-top:2px}
.row-between{display:flex;justify-content:space-between;align-items:center}
.table-card{background:var(--card);border-radius:14px;padding:12px;margin-bottom:10px;box-shadow:var(--shadow)}
.table-card h5{font-size:.8rem;font-weight:800;margin-bottom:4px}
.table-card .meta{font-size:.68rem;color:var(--muted);margin-bottom:8px}
.acct-row{display:flex;align-items:center;justify-content:space-between;padding:14px 4px;border-bottom:1px solid var(--border);font-size:.82rem;font-weight:600}
.acct-row:last-child{border-bottom:none}
.acct-row i{color:var(--accent);margin-inline-end:10px;width:20px}
.avatar-lg{width:70px;height:70px;border-radius:50%;background:var(--gradient);display:flex;align-items:center;justify-content:center;font-size:1.6rem;font-weight:900;color:#1a1a2e;margin:0 auto 10px}
.theme-preview{border-radius:var(--pv-radius,16px);padding:var(--pv-pad,14px);background:var(--card);box-shadow:var(--shadow);display:flex;align-items:center;gap:10px;margin-bottom:16px;border-top:4px solid var(--pv-color,#f2b100)}
.theme-preview .pv-ico{width:40px;height:40px;border-radius:var(--pv-radius,16px);background:var(--pv-color,#f2b100)}
.confirm-sheet{position:fixed;left:0;right:0;bottom:0;z-index:120;background:var(--card);border-radius:22px 22px 0 0;padding:20px 20px calc(20px + env(safe-area-inset-bottom,0px));box-shadow:0 -10px 30px rgba(0,0,0,.2);transform:translateY(110%);transition:transform .25s cubic-bezier(.22,1,.36,1)}
.confirm-sheet.open{transform:translateY(0)}
.sheet-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:110;display:none}
.sheet-backdrop.open{display:block}
.confirm-sheet h4{font-size:.92rem;margin-bottom:14px;text-align:center}
.confirm-actions{display:flex;gap:10px}
.theme-toggle-btn{width:38px;height:38px;border-radius:50%;background:var(--bg);box-shadow:var(--shadow);border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:.9rem;color:var(--text);flex-shrink:0}
.theme-toggle-btn .sun{display:none}
[data-theme="dark"] .theme-toggle-btn .sun{display:inline;color:var(--accent)}
[data-theme="dark"] .theme-toggle-btn .moon{display:none}
.ai-intent-grid{display:flex;flex-direction:column;gap:12px;margin-top:10px}
.ai-intent-card{display:flex;align-items:center;gap:14px;background:var(--card);border-radius:18px;padding:18px;box-shadow:var(--shadow);border:none;width:100%;text-align:right;cursor:pointer;font-family:inherit}
.ai-intent-card i{font-size:1.4rem;color:#1a1a2e;width:48px;height:48px;border-radius:14px;background:var(--gradient);display:flex;align-items:center;justify-content:center;flex-shrink:0}
.ai-intent-card strong{font-size:.88rem;display:block;margin-bottom:3px;color:var(--text)}
.ai-intent-card span{font-size:.7rem;color:var(--muted)}
.ai-back{display:flex;align-items:center;gap:6px;font-size:.75rem;font-weight:700;color:var(--accent);background:none;border:none;cursor:pointer;padding:8px 0;margin-bottom:8px;font-family:inherit}
.ai-chat-box{background:var(--card);border-radius:18px;box-shadow:var(--shadow);display:flex;flex-direction:column;height:60vh;overflow:hidden}
#aiMsgs{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:8px}
.ai-msg{max-width:80%;padding:9px 12px;border-radius:14px;font-size:.78rem;line-height:1.6}
.ai-bot{background:var(--hover-bg);align-self:flex-start;border-bottom-left-radius:4px}
.ai-user{background:var(--gradient);color:#1a1a2e;align-self:flex-end;border-bottom-right-radius:4px;font-weight:600}
.ai-product-card{display:flex;align-items:center;gap:10px;background:var(--card);border:1px solid var(--border);border-radius:14px;padding:8px;max-width:80%;align-self:flex-start;text-decoration:none;color:inherit}
.ai-product-img{width:42px;height:42px;border-radius:10px;background:var(--hover-bg);display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;color:var(--accent)}
.ai-product-img img{width:100%;height:100%;object-fit:cover}
.ai-product-info{flex:1;min-width:0}
.ai-product-info h5{font-size:.74rem;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.ai-product-info span{font-size:.68rem;color:var(--accent);font-weight:700}
#aiChatForm{display:flex;gap:6px;padding:10px;border-top:1px solid var(--border)}
#aiChatForm input{flex:1;border:1px solid var(--border);border-radius:100px;padding:10px 14px;background:var(--bg);font-size:.8rem;outline:none}
#aiChatForm button{width:38px;height:38px;border-radius:50%;background:var(--gradient);border:none;color:#1a1a2e;cursor:pointer;flex-shrink:0}
.pick-list{display:flex;flex-direction:column;gap:8px;margin-bottom:16px}
.pick-card{display:block;background:var(--card);border-radius:14px;padding:12px 14px;box-shadow:var(--shadow);cursor:pointer;border:2px solid transparent}
.pick-card input{margin-inline-end:8px}
.pick-card:has(input:checked){border-color:var(--accent)}
.pick-card .t{font-size:.78rem;font-weight:700}
.pick-card .s{font-size:.68rem;color:var(--muted);margin-top:2px}
.chip-wrap{display:flex;flex-wrap:wrap;gap:8px}
.chip-radio{position:absolute;opacity:0;pointer-events:none}
.chip-label{display:inline-block;padding:9px 16px;border-radius:100px;background:var(--card);box-shadow:var(--shadow);font-size:.74rem;font-weight:700;color:var(--muted);cursor:pointer}
.chip-radio:checked + .chip-label{background:var(--gradient);color:#1a1a2e}
.notif-wrap{position:relative;overflow:hidden;border-radius:16px;margin-bottom:8px}
.notif-row{display:flex;align-items:center;gap:8px;background:var(--card);box-shadow:var(--shadow);border-radius:16px;padding:12px;touch-action:pan-y;position:relative;z-index:1}
.notif-row.unread{border-right:3px solid var(--accent)}
.notif-icon{width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.9rem;flex-shrink:0}
.notif-body{flex:1;min-width:0;color:inherit;text-decoration:none}
.notif-body strong{font-size:.8rem;display:block;margin-bottom:2px}
.notif-body p{font-size:.72rem;color:var(--muted);margin-bottom:4px;line-height:1.6}
.notif-time{font-size:.62rem;color:var(--muted);opacity:.7}
.notif-actions{display:flex;gap:4px;flex-shrink:0}
.nav-row{display:flex;align-items:center;gap:12px;background:var(--card);border-radius:16px;padding:14px 16px;box-shadow:var(--shadow);margin-bottom:10px}
.nav-row i.lead{color:var(--accent);font-size:1.1rem;width:24px;text-align:center}
.nav-row .t{flex:1;min-width:0}
.nav-row .t strong{font-size:.85rem;display:block}
.nav-row .t span{font-size:.68rem;color:var(--muted)}
.nav-row .trail{color:var(--muted);font-size:.75rem}
.store-hero{border-radius:20px;overflow:hidden;margin-bottom:16px;box-shadow:var(--shadow)}
.store-hero-cover{height:120px;background:var(--gradient);position:relative}
.store-hero-cover img{width:100%;height:100%;object-fit:cover}
.store-hero-body{background:var(--card);padding:16px;margin-top:-30px;position:relative}
.store-hero-logo{width:64px;height:64px;border-radius:18px;background:var(--gradient);border:4px solid var(--card);display:flex;align-items:center;justify-content:center;font-size:1.5rem;color:#1a1a2e;margin-bottom:10px;overflow:hidden}
.store-hero-logo img{width:100%;height:100%;object-fit:cover}
@media (min-width:900px){
  .content{max-width:1100px}
  .grid2{grid-template-columns:repeat(4,1fr)}
  .grid3{grid-template-columns:repeat(5,1fr)}
  .hgrid .prod-card{min-width:190px}
  .store-grid6{grid-template-columns:repeat(8,1fr);gap:16px 8px}
}
@media (max-width:360px){
  .store-grid6{grid-template-columns:repeat(4,1fr)}
}
<?php }

function render_js(): void { ?>
/* ===== تصفح بلا فتح صفحات جديدة (نفس الرابط من الدخول لآخر شي) ===== */
function setLoading(v){ document.getElementById('app-root')?.classList.toggle('nav-loading', v); }

function applySwap(data){
  const doSwap = () => {
    document.getElementById('app-root').innerHTML = data.html;
    document.title = data.title + ' — <?= h(site_name()) ?>';
    window.scrollTo(0, 0);
    showInstallBanner();
    renderGoogleButton();
  };
  if (document.startViewTransition) document.startViewTransition(doSwap);
  else doSwap();
}

/* ===== تثبيت التطبيق (PWA) ===== */
if ('serviceWorker' in navigator) {
  window.addEventListener('load', function(){ navigator.serviceWorker.register('indexx.php?asset=sw').catch(function(){}); });
}
let _deferredInstall = null;
window.addEventListener('beforeinstallprompt', function(e){
  e.preventDefault();
  _deferredInstall = e;
  showInstallBanner();
});
function showInstallBanner(){
  if (!_deferredInstall) return;
  let dismissed = false;
  try { dismissed = sessionStorage.getItem('installDismissed') === '1'; } catch (err) {}
  const b = document.getElementById('installBanner');
  if (b && !dismissed) b.hidden = false;
}
document.addEventListener('click', function(e){
  if (e.target.closest('#installBtn')) {
    document.getElementById('installBanner')?.setAttribute('hidden', '');
    if (_deferredInstall) { _deferredInstall.prompt(); _deferredInstall.userChoice.finally(() => { _deferredInstall = null; }); }
  } else if (e.target.closest('#installDismiss')) {
    document.getElementById('installBanner')?.setAttribute('hidden', '');
    try { sessionStorage.setItem('installDismissed', '1'); } catch (err) {}
  }
});

function renderGoogleButton(){}
<?php if (GOOGLE_CLIENT_ID !== ''): ?>
/* ===== تسجيل الدخول عبر Google ===== */
function handleGoogleCredential(response){
  setLoading(true);
  const fd = new FormData();
  fd.append('action', 'google_login');
  fd.append('credential', response.credential);
  fetch('indexx.php', {method:'POST', body: fd, headers:{'X-Requested-With':'fetch'}, credentials:'same-origin'})
    .then(r => r.json()).then(applySwap).catch(() => { window.location.href = 'indexx.php'; })
    .finally(() => setLoading(false));
}
function renderGoogleButton(){
  const box = document.getElementById('googleBtnContainer');
  if (!box || !window.google?.accounts?.id) return;
  google.accounts.id.initialize({client_id: '<?= h(GOOGLE_CLIENT_ID) ?>', callback: handleGoogleCredential});
  box.innerHTML = '';
  google.accounts.id.renderButton(box, {type:'standard', theme:'outline', size:'large', shape:'pill', locale:'ar'});
}
window.addEventListener('load', renderGoogleButton);
<?php endif; ?>

async function navigateTo(url){
  setLoading(true);
  try {
    const r = await fetch(url, {headers:{'X-Requested-With':'fetch'}, credentials:'same-origin'});
    if (!r.ok) throw new Error('bad response');
    applySwap(await r.json());
  } catch (err) {
    window.location.href = url;
  }
  setLoading(false);
}

async function submitPost(form){
  setLoading(true);
  try {
    const fd = new FormData(form);
    const r = await fetch('indexx.php', {method:'POST', body: fd, headers:{'X-Requested-With':'fetch'}, credentials:'same-origin'});
    if (!r.ok) throw new Error('bad response');
    applySwap(await r.json());
  } catch (err) {
    form.submit();
  }
  setLoading(false);
}

document.addEventListener('click', function(e){
  const a = e.target.closest('a[href]');
  if (!a || !a.closest('#app-root')) return;
  const href = a.getAttribute('href');
  if (!href || !href.startsWith('indexx.php') || a.target === '_blank') return;
  e.preventDefault();
  navigateTo(href);
});

document.addEventListener('submit', function(e){
  const form = e.target;
  if (!form.closest('#app-root')) return;
  e.preventDefault();
  if (form.id === 'aiChatForm') { handleAiChatSubmit(form); return; }
  if ((form.getAttribute('method') || 'get').toLowerCase() === 'get') {
    const qs = new URLSearchParams(new FormData(form)).toString();
    navigateTo((form.getAttribute('action') || 'indexx.php') + '?' + qs);
  } else {
    submitPost(form);
  }
});

async function handleAiChatSubmit(form){
  const inp = document.getElementById('aiInput');
  const msg = inp.value.trim();
  if (!msg) return;
  const box = document.getElementById('aiMsgs');
  box.insertAdjacentHTML('beforeend', '<div class="ai-msg ai-user"></div>');
  box.lastElementChild.textContent = msg;
  window._aiHistory = window._aiHistory || [];
  window._aiHistory.push({role:'user', content: msg});
  inp.value = '';
  box.scrollTop = box.scrollHeight;
  box.insertAdjacentHTML('beforeend', '<div class="ai-msg ai-bot" id="aiTyping">...</div>');
  box.scrollTop = box.scrollHeight;
  try {
    const r = await fetch('indexx.php?ajax=chat', {method:'POST', headers:{'Content-Type':'application/json'}, credentials:'same-origin', body: JSON.stringify({message: msg, history: window._aiHistory})});
    const data = await r.json();
    document.getElementById('aiTyping')?.remove();
    box.insertAdjacentHTML('beforeend', '<div class="ai-msg ai-bot"></div>');
    box.lastElementChild.textContent = data.reply;
    window._aiHistory.push({role:'assistant', content: data.reply});
    if (data.product) {
      const p = data.product;
      box.insertAdjacentHTML('beforeend', '<a href="indexx.php?page=product&id=' + encodeURIComponent(p.id) + '" class="ai-product-card"><div class="ai-product-img">' + (p.image ? '<img src="' + p.image + '" alt="">' : '<i class="fas fa-box"></i>') + '</div><div class="ai-product-info"><h5></h5><span></span></div><i class="fas fa-arrow-left"></i></a>');
      const cardEl = box.lastElementChild;
      cardEl.querySelector('h5').textContent = p.name;
      cardEl.querySelector('span').textContent = p.price;
    }
  } catch (err) {
    document.getElementById('aiTyping')?.remove();
    box.insertAdjacentHTML('beforeend', '<div class="ai-msg ai-bot">صار خطأ بالاتصال، حاول مرة ثانية.</div>');
  }
  box.scrollTop = box.scrollHeight;
}

function aiShow(view){
  document.getElementById('aiIntent')?.setAttribute('hidden','');
  document.getElementById('aiChatView')?.setAttribute('hidden','');
  document.getElementById('aiComplaintView')?.setAttribute('hidden','');
  document.getElementById(view)?.removeAttribute('hidden');
  if (view === 'aiChatView') document.getElementById('aiInput')?.focus();
}

function useMyLocation(){
  const status = document.getElementById('locStatus');
  if (!navigator.geolocation) { status.textContent = 'المتصفح ما يدعم تحديد الموقع'; return; }
  status.textContent = 'جاري التحديد...';
  navigator.geolocation.getCurrentPosition(function(pos){
    const lat = pos.coords.latitude, lng = pos.coords.longitude;
    document.getElementById('deliveryLocation').value = 'إحداثيات GPS: ' + lat.toFixed(6) + ', ' + lng.toFixed(6);
    status.textContent = 'جارٍ تحديد اسم الموقع...';
    fetch('https://nominatim.openstreetmap.org/reverse?format=json&lat=' + lat + '&lon=' + lng + '&accept-language=ar&zoom=18')
      .then(r => r.json())
      .then(data => {
        if (data && data.display_name) {
          document.getElementById('deliveryLocation').value = data.display_name;
          status.textContent = 'تم تحديد موقعك ✅';
        } else {
          status.textContent = 'تم تحديد إحداثياتك، تعذّر إيجاد اسم للمكان';
        }
      })
      .catch(() => { status.textContent = 'تم تحديد إحداثياتك، تعذّر إيجاد اسم للمكان'; });
  }, function(){
    status.textContent = 'تعذّر تحديد الموقع، اكتبه يدوياً';
  });
}

/* ===== سحب لحذف الإشعارات ===== */
let _swEl = null, _swStartX = 0, _swCurX = 0;
document.addEventListener('pointerdown', function(e){
  const row = e.target.closest('.notif-row');
  if (!row || e.target.closest('button')) return;
  _swEl = row; _swStartX = e.clientX; _swCurX = 0;
  row.style.transition = 'none';
});
document.addEventListener('pointermove', function(e){
  if (!_swEl) return;
  _swCurX = e.clientX - _swStartX;
  if (_swCurX > 0) _swCurX = 0;
  _swEl.style.transform = `translateX(${_swCurX}px)`;
});
function _swEnd(){
  if (!_swEl) return;
  const el = _swEl, cur = _swCurX;
  el.style.transition = 'transform .2s';
  if (cur < -80) {
    el.style.transform = 'translateX(-110%)';
    const btn = el.querySelector('.notif-delete-trigger');
    setTimeout(() => btn?.click(), 180);
  } else {
    el.style.transform = '';
  }
  _swEl = null; _swCurX = 0;
}
document.addEventListener('pointerup', _swEnd);
document.addEventListener('pointercancel', _swEnd);

function toggleTheme(){
  const cur=document.documentElement.getAttribute('data-theme');
  const next=cur==='dark'?null:'dark';
  if(next)document.documentElement.setAttribute('data-theme',next);else document.documentElement.removeAttribute('data-theme');
  try{localStorage.setItem('mk_theme',next||'')}catch(e){}
}
(function(){try{if(localStorage.getItem('mk_theme')==='dark')document.documentElement.setAttribute('data-theme','dark')}catch(e){}})();


function openLightbox(src){
  let lb=document.querySelector('.lightbox');
  if(!lb){lb=document.createElement('div');lb.className='lightbox';lb.innerHTML='<img>';lb.onclick=()=>lb.classList.remove('open');document.body.appendChild(lb);}
  lb.querySelector('img').src=src;
  lb.classList.add('open');
}

function swapMain(src,el){
  document.getElementById('mainImg').src=src;
  document.querySelectorAll('.gallery-thumbs img').forEach(t=>t.classList.remove('active'));
  el.classList.add('active');
}

function qtyChange(delta){
  const inp=document.getElementById('qtyInput');
  let v=parseInt(inp.value||'1')+delta;
  if(v<1)v=1;
  inp.value=v;
}

function openSheet(id){document.getElementById(id).classList.add('open');document.getElementById('sheetBackdrop').classList.add('open');}
function closeSheets(){document.querySelectorAll('.confirm-sheet, .admin-sidebar').forEach(s=>s.classList.remove('open'));document.getElementById('sheetBackdrop')?.classList.remove('open');}

document.addEventListener('click', function(e){
  const btn = e.target.closest('.pw-toggle');
  if (!btn) return;
  const input = btn.previousElementSibling;
  if (!input || input.tagName !== 'INPUT') return;
  const showing = input.type === 'text';
  input.type = showing ? 'password' : 'text';
  btn.querySelector('i').className = 'fas ' + (showing ? 'fa-eye-slash' : 'fa-eye');
});

function previewTheme(){
  const color=document.getElementById('pv_color')?.value;
  const radiusSel=document.getElementById('pv_radius')?.value;
  const radiusMap={sharp:'6px',rounded:'16px',pill:'28px'};
  const pv=document.getElementById('themePreview');
  if(!pv)return;
  pv.style.setProperty('--pv-color',color);
  pv.style.setProperty('--pv-radius',radiusMap[radiusSel]||'16px');
}
<?php }

/* ===================== مكوّنات العرض المشتركة ===================== */
function render_store_card(array $s): string {
    $img = $s['logo'] ? UPLOAD_URL . '/' . basename($s['logo']) : '';
    ob_start(); ?>
    <a class="store-card an" href="indexx.php?page=store&id=<?= $s['id'] ?>">
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
    <a class="store-tile an" href="indexx.php?page=store&id=<?= $s['id'] ?>">
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
    <a class="prod-card an" href="indexx.php?page=product&id=<?= $p['id'] ?>">
        <div class="prod-card-img">
            <?php if ($img): ?><img src="<?= h($img) ?>" alt=""><?php else: ?><i class="fas <?= category_icon($p['category']) ?>"></i><?php endif; ?>
            <?php if ($hasDiscount): ?><span class="prod-badge">-<?= $pct ?>%</span><?php endif; ?>
        </div>
        <div class="prod-card-info">
            <h4><?= h($p['name']) ?></h4>
            <p class="prod-store"><?= h($store['name'] ?? '') ?></p>
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
    <a class="store-card an" href="indexx.php?page=product&id=<?= $p['id'] ?>">
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
  <a href="indexx.php" class="logo"><?php if (site_logo_url()): ?><img src="<?= h(site_logo_url()) ?>" alt="" class="logo-img"><?php else: ?><i class="fas fa-store"></i><?php endif; ?> <?= h(site_name()) ?></a>
  <div class="topbar-right">
    <a class="icon-btn" href="indexx.php?page=notifications" title="الإشعارات"><i class="fas fa-bell"></i><?php if ($unreadCount): ?><span class="badge"><?= $unreadCount ?></span><?php endif; ?></a>
    <a class="icon-btn" href="indexx.php?page=cart" title="السلة"><i class="fas fa-cart-shopping"></i><?php if ($cartCount): ?><span class="badge"><?= $cartCount ?></span><?php endif; ?></a>
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
  <a class="tab <?= $activeTab==='home'?'active':'' ?>" href="indexx.php"><i class="fas fa-house"></i><span>الرئيسية</span></a>
  <a class="tab <?= $activeTab==='stores'?'active':'' ?>" href="indexx.php?page=stores"><i class="fas fa-shop"></i><span>المتاجر</span></a>
  <a class="tab tab-ai <?= $activeTab==='ai'?'active':'' ?>" href="indexx.php?page=ai"><i class="fas fa-sparkles"></i><span>المساعد الذكي</span></a>
  <a class="tab <?= $activeTab==='orders'?'active':'' ?>" href="indexx.php?page=orders"><i class="fas fa-receipt"></i><span>طلباتي</span></a>
  <a class="tab <?= $activeTab==='account'?'active':'' ?>" href="indexx.php?page=account"><i class="fas fa-user"></i><span>حسابي</span></a>
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
<link rel="manifest" href="indexx.php?asset=manifest">
<meta name="theme-color" content="#f2b100">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= h(site_name()) ?>">
<link rel="apple-touch-icon" href="<?= h(site_logo_url() ?? 'indexx.php?asset=icon&size=192') ?>">
<link rel="icon" href="<?= h(site_logo_url() ?? 'indexx.php?asset=icon&size=192') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
<?php if (GOOGLE_CLIENT_ID !== ''): ?><script src="https://accounts.google.com/gsi/client" async defer></script><?php endif; ?>
<link rel="stylesheet" href="indexx.php?asset=style.css">
</head>
<body>
<div class="bg-orb bo1"></div><div class="bg-orb bo2"></div>
<div id="app-root"><?= $inner ?></div>
<script src="indexx.php?asset=app.js"></script>
</body>
</html>
    <?php
}

/* ===================== PWA: مانيفست وservice worker من نفس الملف (بلا ملفات إضافية) ===================== */
if (isset($_GET['asset']) && $_GET['asset'] === 'manifest') {
    header('Content-Type: application/manifest+json; charset=utf-8');
    $logo = site_logo_url();
    $iconType = function_exists('imagecreatetruecolor') ? 'image/png' : 'image/svg+xml';
    $icons = $logo
        ? [['src'=>$logo, 'sizes'=>'192x192', 'type'=>'image/png', 'purpose'=>'any'], ['src'=>$logo, 'sizes'=>'512x512', 'type'=>'image/png', 'purpose'=>'any']]
        : [['src'=>'indexx.php?asset=icon&size=192', 'sizes'=>'192x192', 'type'=>$iconType, 'purpose'=>'any'], ['src'=>'indexx.php?asset=icon&size=512', 'sizes'=>'512x512', 'type'=>$iconType, 'purpose'=>'any']];
    echo json_encode([
        'name' => site_name(),
        'short_name' => site_name(),
        'start_url' => 'indexx.php',
        'scope' => './',
        'display' => 'standalone',
        'background_color' => '#ffffff',
        'theme_color' => '#f2b100',
        'dir' => 'rtl',
        'lang' => 'ar',
        'icons' => $icons,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if (isset($_GET['asset']) && $_GET['asset'] === 'icon') {
    $size = (int)($_GET['size'] ?? 512);
    if (!in_array($size, [192, 512], true)) $size = 512;
    /* لا يوجد ملف خط TTF متاح برفقة هذا الملف الواحد لرسم أول حرف من الاسم
       (عربي غالباً) بامتداد GD، فنرسم أيقونة حقيبة تسوّق بسيطة بأشكال هندسية
       بدل النص — تعمل بأي لغة وتبقى مقروءة بأصغر حجم. PNG حقيقي أفضل لمعايير
       تثبيت PWA على أندرويد من SVG وحدها؛ SVG تبقى احتياطاً إن كان GD معطّلاً. */
    if (function_exists('imagecreatetruecolor')) {
        header('Content-Type: image/png');
        $im = imagecreatetruecolor($size, $size);
        $bg = imagecolorallocate($im, 0xf2, 0xb1, 0x00);
        imagefill($im, 0, 0, $bg);
        $white = imagecolorallocate($im, 255, 255, 255);
        $m = (int)($size * 0.24);
        $bodyTop = (int)($size * 0.44);
        $bodyBottom = (int)($size * 0.8);
        imagefilledrectangle($im, $m, $bodyTop, $size - $m, $bodyBottom, $white);
        imagesetthickness($im, max(2, (int)($size * 0.045)));
        $handleW = (int)($size * 0.34);
        imagearc($im, (int)($size / 2), $bodyTop, $handleW, (int)($handleW * 1.15), 180, 360, $white);
        imagepng($im);
        imagedestroy($im);
        exit;
    }
    header('Content-Type: image/svg+xml; charset=utf-8');
    echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><rect width="512" height="512" fill="#f2b100"/><rect x="123" y="225" width="266" height="184" fill="#fff"/><path d="M190 225a66 66 0 0 1 132 0" fill="none" stroke="#fff" stroke-width="22"/></svg>';
    exit;
}
if (isset($_GET['asset']) && $_GET['asset'] === 'sw') {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Service-Worker-Allowed: ./');
    echo "self.addEventListener('install',e=>self.skipWaiting());self.addEventListener('activate',e=>self.clients.claim());self.addEventListener('fetch',e=>{});";
    exit;
}
if (isset($_GET['asset']) && $_GET['asset'] === 'style.css') {
    header('Content-Type: text/css; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    render_css();
    exit;
}
if (isset($_GET['asset']) && $_GET['asset'] === 'app.js') {
    header('Content-Type: application/javascript; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    render_js();
    exit;
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
  <a href="indexx.php?page=login" class="auth-skip an">تخطي <i class="fas fa-arrow-left"></i></a>
  <?= render_auth_hero(false) ?>
  <h1 class="auth-heading an">تسوق من متاجرك المفضلة واكتشف أفضل المتاجر</h1>
  <p class="auth-heading-sub an">كل المتاجر والمنتجات بمكان واحد، بتجربة سلسة وسريعة</p>
  <div class="auth-dots an"><span class="active"></span><span></span><span></span></div>
  <div style="flex:1"></div>
  <a href="indexx.php?page=register" class="btn an" style="max-width:360px;margin:0 auto;display:flex"><i class="fas fa-arrow-left"></i> ابدأ الآن</a>
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
  <div class="login-admin-link an" style="--ad:.2s">ليس لديك حساب؟ <a href="indexx.php?page=register">إنشاء حساب جديد</a></div>
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
  <div class="login-admin-link an" style="--ad:.2s">لديك حساب بالفعل؟ <a href="indexx.php?page=login">تسجيل الدخول</a></div>
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
            <a class="cat-chip" href="indexx.php?page=stores&cat=<?= urlencode($c) ?>"><i class="fas <?= category_icon($c) ?>"></i><?= h($c) ?></a>
        <?php endforeach; ?>
    </div>

    <?php
    $inner = '<div class="hscroll">' . implode('', array_map(fn($s) => '<div style="min-width:220px">' . render_store_card($s) . '</div>', array_slice($featured, 0, 6))) . '</div>';
    echo $featured ? render_section('fa-star', 'متاجر مميزة', $inner, 'indexx.php?page=stores') : '';

    $inner = '<div class="store-grid6">' . implode('', array_map('render_store_tile', $newest)) . '</div>';
    echo render_section('fa-clock', 'أحدث المتاجر', $inner, 'indexx.php?page=stores');

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
        <a class="chip <?= $cat===''?'active':'' ?>" href="indexx.php?page=stores">الكل</a>
        <?php foreach (get_categories() as $c): ?>
            <a class="chip <?= $cat===$c?'active':'' ?>" href="indexx.php?page=stores&cat=<?= urlencode($c) ?>"><?= h($c) ?></a>
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
                <form method="post"><input type="hidden" name="action" value="toggle_favorite"><input type="hidden" name="type" value="store"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="back" value="indexx.php?page=store&id=<?= $id ?>">
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
        foreach ($groups as $g): if (!$g['items']) continue; $lc = ['grid2'=>'grid2','grid3'=>'grid3','list'=>''][$g['layout']] ?? 'grid2'; ?>
        <h3 style="font-size:.9rem;font-weight:800;margin:18px 0 10px"><?= h($g['title']) ?> (<?= count($g['items']) ?>)</h3>
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
    <a href="indexx.php?page=store&id=<?= $store['id'] ?>" style="font-size:.72rem;color:var(--accent);font-weight:700"><i class="fas fa-store"></i> <?= h($store['name'] ?? '') ?></a>
    <h2 style="font-size:1.1rem;font-weight:800;margin:8px 0 6px"><?= h($p['name']) ?></h2>
    <div class="prod-price" style="margin-bottom:12px">
        <?php if ($hasDiscount): ?>
            <span class="now" style="font-size:1.2rem"><?= money($p['discount_price']) ?></span>
            <span class="was"><?= money($p['price']) ?></span>
        <?php else: ?>
            <span class="now" style="font-size:1.2rem"><?= money($p['price']) ?></span>
        <?php endif; ?>
    </div>
    <p style="font-size:.82rem;line-height:1.9;color:var(--muted);margin-bottom:18px"><?= nl2br(h($p['description'])) ?></p>

    <form method="post" action="indexx.php?page=product&id=<?= $id ?>">
        <input type="hidden" name="action" value="add_to_cart">
        <input type="hidden" name="product_id" value="<?= $id ?>">
        <input type="hidden" name="back" value="indexx.php?page=product&id=<?= $id ?>">
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
    if (!$cart) return '<div class="empty-state an"><i class="fas fa-cart-shopping"></i><p>سلتك فارغة</p><a class="btn" style="width:auto;display:inline-flex;margin-top:14px" href="indexx.php?page=stores">تصفح المتاجر</a></div>';

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
    <?php foreach ($orders as $o): $store = find_store($o['store_id']); ?>
    <div class="order-card an">
        <div class="order-top"><span><i class="fas fa-shop" style="color:var(--accent)"></i> <?= h($store['name'] ?? '') ?></span><span><?= money($o['total']) ?></span></div>
        <div class="order-items"><?= implode('، ', array_map(fn($it) => h($it['name']) . ' ×' . $it['qty'], $o['items'])) ?></div>
        <?= order_stepper($o['status']) ?>
        <details style="margin-top:10px">
            <summary style="font-size:.72rem;color:var(--accent);font-weight:700;cursor:pointer">تفاصيل الطلب #<?= $o['id'] ?></summary>
            <div style="margin-top:8px;display:flex;flex-direction:column;gap:6px">
                <?php foreach ($o['items'] as $it): ?>
                <div class="row-between" style="font-size:.74rem"><span><?= h($it['name']) ?> × <?= $it['qty'] ?></span><span style="color:var(--muted)"><?= money($it['price']) ?> = <?= money($it['price'] * $it['qty']) ?></span></div>
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
    $storeLink = $store ? 'indexx.php?page=vendor' : ($pending ? 'indexx.php?page=account' : 'indexx.php?page=apply-vendor');

    ob_start(); ?>
    <div class="an" style="text-align:center;margin:10px 0 18px">
        <div class="avatar-lg"><?= h(mb_substr($user['name'], 0, 1)) ?></div>
        <h2 style="font-size:1.05rem;font-weight:800"><?= h($user['name']) ?></h2>
        <?php if ($user['phone']): ?><p style="font-size:.72rem;color:var(--muted)"><?= h($user['phone']) ?></p><?php endif; ?>
    </div>

    <?php if (is_admin_user()): ?>
    <a href="indexx.php?page=admin" class="nav-row an" style="background:var(--gradient);color:#1a1a2e">
        <i class="fas fa-user-shield lead" style="color:#1a1a2e"></i>
        <div class="t"><strong>لوحة الإدارة</strong><span style="color:#1a1a2e;opacity:.75">التحكم الكامل بالمنصة</span></div>
        <i class="fas fa-chevron-left"></i>
    </a>
    <?php endif; ?>

    <a href="indexx.php?page=account-wallet" class="nav-row an">
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

    <a href="indexx.php?page=favorites" class="nav-row an">
        <i class="fas fa-heart lead"></i>
        <div class="t"><strong>المفضلة</strong><span>المتاجر والمنتجات المحفوظة</span></div>
        <span class="trail"><?= $favCount ?></span>
        <i class="fas fa-chevron-left" style="color:var(--muted)"></i>
    </a>

    <a href="indexx.php?page=notifications" class="nav-row an">
        <i class="fas fa-bell lead"></i>
        <div class="t"><strong>الإشعارات</strong><span>كل التحديثات والتنبيهات</span></div>
        <i class="fas fa-chevron-left" style="color:var(--muted)"></i>
    </a>

    <div class="nav-row an">
        <i class="fas fa-moon lead"></i>
        <div class="t"><strong>الوضع الليلي</strong><span>يتذكّر اختيارك بهذا الجهاز</span></div>
        <button class="theme-toggle-btn" type="button" onclick="toggleTheme()" title="تبديل الوضع"><i class="fas fa-moon moon"></i><i class="fas fa-sun sun"></i></button>
    </div>

    <details class="nav-row an" style="display:block">
        <summary style="display:flex;align-items:center;gap:12px;cursor:pointer;list-style:none"><i class="fas fa-user-pen lead"></i><div class="t"><strong>تعديل معلومات الحساب</strong><span><?= h($user['name']) ?></span></div></summary>
        <form method="post" style="margin-top:14px"><input type="hidden" name="action" value="update_profile">
            <div class="field"><label>الاسم</label><input type="text" name="name" value="<?= h($user['name']) ?>"></div>
            <div class="field"><label>البريد الإلكتروني</label><input type="email" name="email" value="<?= h($user['email'] ?? '') ?>"></div>
            <div class="field"><label>رقم الهاتف</label><input type="tel" name="phone" value="<?= h($user['phone'] ?? '') ?>"></div>
            <div class="field"><label>كلمة مرور جديدة (اتركه فارغاً إذا لا تريد تغييرها)</label><input type="password" name="password" autocomplete="new-password"></div>
            <button class="btn btn-sm" type="submit">حفظ التعديل</button>
        </form>
    </details>

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

function page_account_wallet(): string {
    $user = current_user();
    $settings = get_settings();
    $myTopups = array_values(array_filter(db_read('topup_requests'), fn($r) => $r['user_id'] === $user['id']));
    usort($myTopups, fn($a, $b) => $b['created_at'] <=> $a['created_at']);
    $myPendingTopup = null;
    foreach ($myTopups as $t) if ($t['status'] === 'pending') { $myPendingTopup = $t; break; }
    $statusLabel = ['pending'=>'بانتظار المراجعة', 'approved'=>'تمت الموافقة', 'rejected'=>'مرفوض'];

    ob_start(); ?>
    <a href="indexx.php?page=account" class="ai-back an"><i class="fas fa-arrow-right"></i> رجوع لحسابي</a>
    <h2 style="font-size:1.05rem;font-weight:800;margin-bottom:14px" class="an"><i class="fas fa-wallet"></i> المحفظة</h2>
    <div class="wallet-card an" style="margin-bottom:14px">
        <div class="wallet-top"><div><div class="greet">رصيدك الحالي</div><div class="wallet-bal"><?= money($user['wallet']) ?></div></div><i class="fas fa-wallet" style="font-size:1.6rem;opacity:.7"></i></div>
    </div>
    <?php if ($myPendingTopup): ?>
        <div class="card an" style="margin-bottom:14px"><i class="fas fa-hourglass-half" style="color:var(--accent)"></i> طلب شحن <?= money($myPendingTopup['amount']) ?> بانتظار مراجعة الإدارة.</div>
    <?php else: ?>
    <div class="card an" style="margin-bottom:14px">
        <h3 style="font-size:.85rem;font-weight:800;margin-bottom:14px"><i class="fas fa-plus" style="color:var(--accent)"></i> شحن يدوي</h3>
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
    <a href="indexx.php?page=account" class="ai-back an"><i class="fas fa-arrow-right"></i> رجوع لحسابي</a>
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
            <a class="<?= $active===$k?'active':'' ?>" href="indexx.php?page=vendor&section=<?= $k ?>"><?= $label ?></a>
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
            <a class="<?= $active === $k ? 'active' : '' ?>" href="indexx.php?page=admin&section=<?= $k ?>"><?= h($label) ?></a>
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
    echo '<a href="indexx.php?page=account" class="ai-back an"><i class="fas fa-arrow-right"></i> رجوع لحسابي</a>';
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
            <a href="indexx.php?page=admin&section=complaints" class="ai-back an"><i class="fas fa-arrow-right"></i> كل الشكاوى</a>
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
                <a href="indexx.php?page=admin&section=complaints&id=<?= $c['id'] ?>" class="table-card an" style="display:block">
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
        <h3 style="font-size:.88rem;font-weight:800;margin-bottom:10px">طرق الدفع للشحن اليدوي</h3>
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
            $cpProduct = null; foreach ($allProducts as $pp) if ($pp['id'] === $cp['product_id']) { $cpProduct = $pp; break; } ?>
            <div class="table-card an">
                <div class="row-between">
                    <h5><?= h($cp['code']) ?> <span style="color:var(--accent);font-weight:700">-<?= (int)$cp['percent'] ?>%</span></h5>
                    <form method="post" onsubmit="return confirm('حذف الكوبون؟')"><input type="hidden" name="action" value="admin_delete_coupon"><input type="hidden" name="coupon_id" value="<?= $cp['id'] ?>"><button class="cart-remove"><i class="fas fa-trash"></i></button></form>
                </div>
                <div class="meta">على منتج: <?= h($cpProduct['name'] ?? 'منتج محذوف') ?></div>
            </div>
        <?php endforeach; endif; ?>
        <details class="card an" style="margin-top:10px">
            <summary style="font-weight:800;font-size:.8rem;cursor:pointer"><i class="fas fa-plus"></i> إضافة كوبون خصم</summary>
            <form method="post" style="margin-top:14px">
                <input type="hidden" name="action" value="admin_add_coupon">
                <div class="field"><label>الكود</label><input type="text" name="code" required placeholder="مثال: SALE20" style="text-transform:uppercase"></div>
                <div class="field"><label>المنتج</label>
                    <select name="product_id" required>
                        <?php foreach ($allProducts as $pp): $ps = find_store($pp['store_id']); ?>
                        <option value="<?= $pp['id'] ?>"><?= h($pp['name']) ?> — <?= h($ps['name'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field"><label>نسبة الخصم %</label><input type="number" name="percent" min="1" max="90" required placeholder="20"></div>
                <button class="btn btn-sm" type="submit">إضافة</button>
            </form>
        </details>
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
