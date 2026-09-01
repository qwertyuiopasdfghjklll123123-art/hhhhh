<?php
// ============================================================
// إعداد مشترك: قاعدة البيانات + الجلسة + دوال مساعدة
// يُستدعى من index.php و admin.php
// ============================================================

session_start();

define('BASE_DIR', dirname(__DIR__));
define('UPLOADS_DIR', BASE_DIR . '/uploads');
define('PROOFS_DIR', UPLOADS_DIR . '/proofs');
define('LOGOS_DIR', UPLOADS_DIR . '/logos');
define('DB_CONFIG_FILE', __DIR__ . '/db_config.php');

foreach ([UPLOADS_DIR, PROOFS_DIR, LOGOS_DIR] as $dir) {
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
}

$htaccess = UPLOADS_DIR . '/.htaccess';
if (!file_exists($htaccess)) {
    // php_flag وOptions -Indexes متوافقتان مع كل إصدارات Apache؛ نتجنب توجيهات
    // access-control (Require/Deny) لأن صياغتها تختلف بين 2.2 و2.4 وقد تسبب خطأ 500
    @file_put_contents($htaccess, "php_flag engine off\nOptions -Indexes\n");
}

// ============================================================
// إعدادات الاتصال بقاعدة بيانات MySQL / MariaDB
// (يكتبها ملف install.php عند التنصيب لأول مرة)
// ============================================================

function loadDbConfig() {
    if (!file_exists(DB_CONFIG_FILE)) return null;
    $config = require DB_CONFIG_FILE;
    return is_array($config) ? $config : null;
}

function connectDb(array $config) {
    $host = $config['db_host'] ?? 'localhost';
    $port = $config['db_port'] ?? '3306';
    $name = $config['db_name'] ?? '';
    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";
    return new PDO($dsn, $config['db_user'] ?? '', $config['db_pass'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

// ينشئ جداول قاعدة البيانات إن لم تكن موجودة، ويضيف أي أعمدة/فهارس ناقصة من
// إصدار سابق للتطبيق. آمن لإعادة التشغيل في كل طلب (كل أمر يتحقق أولاً).
function initSchema(PDO $pdo, $dbName) {
    $charset = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    $tables = [
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NULL,
            phone VARCHAR(50) NULL,
            password_hash VARCHAR(255) NULL,
            is_admin INT NOT NULL DEFAULT 0,
            balance DECIMAL(12,2) NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )$charset",

        "CREATE TABLE IF NOT EXISTS vps_plans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            icon VARCHAR(20) NOT NULL DEFAULT '🚀',
            cpu VARCHAR(100) NOT NULL,
            ram VARCHAR(100) NOT NULL,
            storage VARCHAR(100) NOT NULL,
            bandwidth VARCHAR(100) NOT NULL,
            price DECIMAL(12,2) NOT NULL,
            badge VARCHAR(100) NULL,
            is_active INT NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )$charset",

        "CREATE TABLE IF NOT EXISTS payment_methods (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            logo_path VARCHAR(500) NULL,
            icon VARCHAR(100) NOT NULL DEFAULT 'fa-money-bill-wave',
            account_number VARCHAR(255) NULL,
            instructions TEXT NULL,
            is_active INT NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )$charset",

        "CREATE TABLE IF NOT EXISTS orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            plan_id INT NOT NULL,
            payment_method_id INT NULL,
            amount DECIMAL(12,2) NOT NULL,
            proof_image VARCHAR(500) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            admin_note TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            decided_at DATETIME NULL,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (plan_id) REFERENCES vps_plans(id)
        )$charset",

        "CREATE TABLE IF NOT EXISTS hosting (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            order_id INT NULL,
            name VARCHAR(255) NOT NULL,
            plan VARCHAR(255) NOT NULL,
            ip VARCHAR(100) NOT NULL,
            username VARCHAR(255) NOT NULL,
            password VARCHAR(255) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            expiry_date DATE NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (order_id) REFERENCES orders(id)
        )$charset",

        "CREATE TABLE IF NOT EXISTS invoices (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            order_id INT NULL,
            invoice_number VARCHAR(50) NOT NULL,
            amount DECIMAL(12,2) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            due_date DATE NULL,
            description VARCHAR(500) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (order_id) REFERENCES orders(id)
        )$charset",

        "CREATE TABLE IF NOT EXISTS settings (
            `key` VARCHAR(191) PRIMARY KEY,
            value TEXT NULL
        )$charset",

        "CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            body TEXT NULL,
            type VARCHAR(30) NOT NULL DEFAULT 'system',
            is_read INT NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        )$charset",

        "CREATE TABLE IF NOT EXISTS currencies (
            code VARCHAR(10) PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            symbol VARCHAR(20) NOT NULL,
            rate_per_usd DECIMAL(14,4) NOT NULL DEFAULT 1,
            is_active INT NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0
        )$charset",
    ];
    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }

    // ------------------------------------------------------------
    // ترحيل: إضافة أي أعمدة/فهارس ناقصة من إصدار سابق للتطبيق
    // (دون فقدان البيانات الموجودة أصلاً)
    // ------------------------------------------------------------
    $ensureColumn = function ($table, $column, $definition) use ($pdo, $dbName) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?');
        $stmt->execute([$dbName, $table, $column]);
        if ((int)$stmt->fetchColumn() > 0) return;
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
    };
    $ensureIndex = function ($table, $indexName, $columnExpr) use ($pdo, $dbName) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?');
        $stmt->execute([$dbName, $table, $indexName]);
        if ((int)$stmt->fetchColumn() > 0) return;
        $pdo->exec("CREATE UNIQUE INDEX `$indexName` ON `$table` ($columnExpr)");
    };

    $ensureColumn('hosting', 'order_id', 'INT NULL');
    $ensureColumn('invoices', 'order_id', 'INT NULL');
    $ensureColumn('vps_plans', 'original_price', 'DECIMAL(12,2) NULL');
    $ensureColumn('vps_plans', 'price_yearly', 'DECIMAL(12,2) NULL');
    $ensureColumn('vps_plans', 'billing_cycle', "VARCHAR(10) NOT NULL DEFAULT 'monthly'");
    $ensureColumn('users', 'google_id', 'VARCHAR(255) NULL');
    $ensureColumn('orders', 'billing_cycle', "VARCHAR(10) NOT NULL DEFAULT 'monthly'");
    $ensureColumn('payment_methods', 'method_type', "VARCHAR(20) NOT NULL DEFAULT 'manual'");
    $ensureColumn('payment_methods', 'currency_code', "VARCHAR(10) NOT NULL DEFAULT 'USD'");
    $ensureColumn('hosting', 'vps_id', 'VARCHAR(100) NULL');
    $ensureColumn('users', 'referral_code', 'VARCHAR(32) NULL');
    $ensureColumn('users', 'referred_by', 'INT NULL');
    $ensureColumn('invoices', 'proof_image', 'VARCHAR(500) NULL');

    // فهارس التفرّد (يتم إنشاؤها بعد التأكد من وجود الأعمدة أعلاه)
    // ملاحظة: عمود email/phone قابلان لأن يكونا NULL بتكرار (حساب دخول عبر Google
    // فقط بدون بريد، مثلاً)؛ فهرس UNIQUE في MySQL/InnoDB يسمح بعدة قيم NULL فلا مشكلة.
    $ensureIndex('users', 'idx_users_email', 'email');
    $ensureIndex('users', 'idx_users_phone', 'phone');
    $ensureIndex('users', 'idx_users_referral_code', 'referral_code');

    // ------------------------------------------------------------
    // بيانات ابتدائية (تُدرج مرة واحدة فقط إذا كانت الجداول فارغة)
    // ملاحظة: حساب الأدمن الأول يُنشأ من خلال install.php وليس هنا. لا تُزرع
    // باقات VPS أو طرق دفع تجريبية؛ الأدمن يُنشئ كل شيء بنفسه من لوحة التحكم.
    // ------------------------------------------------------------

    if ((int)$pdo->query('SELECT COUNT(*) FROM currencies')->fetchColumn() === 0) {
        $seed = $pdo->prepare('INSERT INTO currencies (code, name, symbol, rate_per_usd, sort_order) VALUES (?,?,?,?,?)');
        $worldCurrencies = [
            ['USD', 'دولار أمريكي', '$', 1],
            ['IQD', 'دينار عراقي', 'د.ع', 1310],
            ['SAR', 'ريال سعودي', 'ر.س', 3.75],
            ['AED', 'درهم إماراتي', 'د.إ', 3.67],
            ['KWD', 'دينار كويتي', 'د.ك', 0.307],
            ['QAR', 'ريال قطري', 'ر.ق', 3.64],
            ['BHD', 'دينار بحريني', 'د.ب', 0.376],
            ['OMR', 'ريال عماني', 'ر.ع', 0.385],
            ['JOD', 'دينار أردني', 'د.أ', 0.709],
            ['EGP', 'جنيه مصري', 'ج.م', 49],
            ['LBP', 'ليرة لبنانية', 'ل.ل', 89500],
            ['SYP', 'ليرة سورية', 'ل.س', 13000],
            ['YER', 'ريال يمني', 'ر.ي', 250],
            ['LYD', 'دينار ليبي', 'د.ل', 4.85],
            ['MAD', 'درهم مغربي', 'د.م', 9.9],
            ['TND', 'دينار تونسي', 'د.ت', 3.1],
            ['DZD', 'دينار جزائري', 'د.ج', 134],
            ['SDG', 'جنيه سوداني', 'ج.س', 600],
            ['MRU', 'أوقية موريتانية', 'أ.م', 39.5],
            ['EUR', 'يورو', '€', 0.92],
            ['GBP', 'جنيه إسترليني', '£', 0.79],
            ['TRY', 'ليرة تركية', '₺', 34],
            ['INR', 'روبية هندية', '₹', 83.5],
            ['PKR', 'روبية باكستانية', '₨', 278],
            ['CNY', 'يوان صيني', '¥', 7.24],
            ['JPY', 'ين ياباني', '¥', 151],
            ['KRW', 'وون كوري جنوبي', '₩', 1340],
            ['RUB', 'روبل روسي', '₽', 92],
            ['CAD', 'دولار كندي', '$', 1.36],
            ['AUD', 'دولار أسترالي', '$', 1.52],
            ['CHF', 'فرنك سويسري', 'Fr', 0.88],
            ['SEK', 'كرونة سويدية', 'kr', 10.4],
            ['NOK', 'كرونة نرويجية', 'kr', 10.6],
            ['PLN', 'زلوتي بولندي', 'zł', 3.95],
            ['ZAR', 'راند جنوب أفريقي', 'R', 18.3],
            ['NGN', 'نايرا نيجيرية', '₦', 1550],
            ['KES', 'شلن كيني', 'KSh', 129],
            ['BRL', 'ريال برازيلي', 'R$', 5.1],
            ['MXN', 'بيزو مكسيكي', '$', 17],
            ['IDR', 'روبية إندونيسية', 'Rp', 15750],
            ['MYR', 'رينغيت ماليزي', 'RM', 4.7],
            ['PHP', 'بيزو فلبيني', '₱', 56.8],
            ['THB', 'بات تايلاندي', '฿', 36.3],
            ['VND', 'دونغ فيتنامي', '₫', 24700],
            ['SGD', 'دولار سنغافوري', '$', 1.34],
            ['HKD', 'دولار هونغ كونغي', '$', 7.82],
            ['NZD', 'دولار نيوزيلندي', '$', 1.64],
            ['AFN', 'أفغاني', '؋', 71],
        ];
        foreach ($worldCurrencies as $i => $c) {
            $seed->execute([$c[0], $c[1], $c[2], $c[3], $i + 1]);
        }
    }

    if ((int)$pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn() === 0) {
        $seed = $pdo->prepare('INSERT INTO settings (`key`, value) VALUES (?, ?)');
        $seed->execute(['site_name', 'استضافتي']);
        $seed->execute(['site_tagline', 'استضافة سريعة وآمنة']);
        $seed->execute(['site_logo', '']);
        $seed->execute(['nvidia_api_key', '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']);
        $seed->execute(['nvidia_model', 'openai/gpt-oss-120b']);
        $seed->execute(['google_client_id', '']);
        $seed->execute(['google_client_secret', '']);
        $seed->execute(['app_currency', '']);
        $seed->execute(['ai_logo', '']);
        $seed->execute(['ai_home_banner', '']);
        $seed->execute(['referral_discount_pct', '10']);
        $seed->execute(['site_terms',
            "1. باستخدامك لهذه المنصة فإنك توافق على هذه الشروط والأحكام.\n" .
            "2. يُمنع استخدام الخوادم في أي نشاط غير قانوني أو ضار (احتيال، اختراق، إرسال رسائل مزعجة، توزيع محتوى مخالف).\n" .
            "3. يتم تفعيل الاشتراك بعد مراجعة الإدارة وتأكيد استلام الدفع.\n" .
            "4. الرسوم غير قابلة للاسترداد بعد تفعيل الخدمة، إلا وفق تقدير الإدارة.\n" .
            "5. تحتفظ الإدارة بحق إيقاف أو إلغاء أي حساب يخالف هذه الشروط دون إشعار مسبق.\n" .
            "6. قد تُحدَّث هذه الشروط من وقت لآخر، ويُعد استمرارك في استخدام المنصة موافقة على أي تحديث."]);
        $seed->execute(['site_privacy',
            "1. نجمع فقط البيانات اللازمة لتقديم الخدمة: الاسم، البريد الإلكتروني، وبيانات الدفع المرفقة عند الشحن أو الاشتراك.\n" .
            "2. لا تتم مشاركة بياناتك مع أي طرف ثالث إلا في حدود ما يلزم لتشغيل الخدمة (مثل مزوّد الذكاء الاصطناعي عند استخدامك للمساعد الذكي).\n" .
            "3. تُخزَّن كلمات المرور بشكل مشفّر ولا يمكن لأي أحد الاطلاع عليها كنص صريح.\n" .
            "4. يمكنك التواصل مع الدعم الفني في أي وقت لطلب حذف بياناتك أو الاستفسار عنها."]);
    }
}

// ============================================================
// تشغيل الاتصال الفعلي لهذا الطلب (يُتخطى عند التحميل من install.php)
// ============================================================
if (!defined('ISTIDAFATI_INSTALLER')) {
    $dbConfig = loadDbConfig();
    if (!$dbConfig) {
        header('Location: install.php');
        exit;
    }
    try {
        $pdo = connectDb($dbConfig);
        initSchema($pdo, $dbConfig['db_name']);
    } catch (PDOException $e) {
        http_response_code(500);
        die('تعذر الاتصال بقاعدة البيانات. تحقق من صحة الإعدادات في includes/db_config.php ومن أن خادم MySQL يعمل. التفاصيل: ' . htmlspecialchars($e->getMessage()));
    }
}

// ============================================================
// دوال المصادقة
// ============================================================

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function currentUser(PDO $pdo) {
    static $cached = null;
    static $fetched = false;
    if ($fetched) return $cached;
    $fetched = true;
    if (!isLoggedIn()) return null;
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $cached = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$cached) {
        session_destroy();
    }
    return $cached;
}

function isAdmin(PDO $pdo) {
    $u = currentUser($pdo);
    return $u && (int)$u['is_admin'] === 1;
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: index.php?page=login');
        exit;
    }
}

function requireAdmin(PDO $pdo) {
    requireLogin();
    if (!isAdmin($pdo)) {
        http_response_code(403);
        die('غير مصرح لك بالدخول إلى لوحة التحكم.');
    }
}

// ============================================================
// حماية CSRF
// ============================================================

function csrfToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

function csrfCheck() {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
        http_response_code(400);
        die('انتهت صلاحية النموذج، الرجاء إعادة تحميل الصفحة والمحاولة مجدداً.');
    }
}

// ============================================================
// دوال مساعدة عامة
// ============================================================

function nextInvoiceNumber(PDO $pdo) {
    $count = (int)$pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn() + 1;
    return 'INV-' . date('Y') . '-' . str_pad((string)$count, 4, '0', STR_PAD_LEFT);
}

/**
 * يتحقق من الملف المرفوع ويحفظه باسم عشوائي آمن.
 * يرجع [المسار النسبي أو null, رسالة الخطأ أو null]
 */
function handleImageUpload($fileField, $destDir, $publicPrefix) {
    if (!isset($_FILES[$fileField]) || $_FILES[$fileField]['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    $file = $_FILES[$fileField];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return [null, 'حدث خطأ أثناء رفع الملف.'];
    }
    if ($file['size'] > 4 * 1024 * 1024) {
        return [null, 'حجم الملف أكبر من 4 ميجابايت.'];
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        return [null, 'صيغة الصورة غير مدعومة (jpg, png, webp فقط).'];
    }
    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $destDir . '/' . $filename)) {
        return [null, 'فشل حفظ الملف على السيرفر.'];
    }
    return [$publicPrefix . '/' . $filename, null];
}

function money($amount) {
    return number_format((float)$amount, 2);
}

function planDiscountPct($plan) {
    $original = (float)($plan['original_price'] ?? 0);
    $price = (float)($plan['price'] ?? 0);
    if ($original <= $price) return null;
    return (int)round((($original - $price) / $original) * 100);
}

// ============================================================
// العملات (كل الأسعار مخزّنة بالدولار كعملة أساس؛ هذه الدوال
// تُستخدم فقط لعرضها بعملة أخرى محوّلة، وليس لتخزينها)
// ============================================================

function getActiveCurrencies(PDO $pdo) {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = $pdo->query('SELECT * FROM currencies WHERE is_active = 1 ORDER BY sort_order ASC, code ASC')->fetchAll(PDO::FETCH_ASSOC);
    return $cache;
}

function getAllCurrencies(PDO $pdo) {
    return $pdo->query('SELECT * FROM currencies ORDER BY sort_order ASC, code ASC')->fetchAll(PDO::FETCH_ASSOC);
}

function getCurrency(PDO $pdo, $code) {
    foreach (getActiveCurrencies($pdo) as $c) {
        if ($c['code'] === $code) return $c;
    }
    return ['code' => 'USD', 'name' => 'دولار أمريكي', 'symbol' => '$', 'rate_per_usd' => 1];
}

// يُطبع داخل <script> في أي صفحة تعرض أسعاراً: يحوّل كل عنصر يحمل
// data-usd="<amount>" لعملة الزائر المكتشفة تلقائياً من لغة متصفحه
// (أو العملة التي يفرضها الأدمن من الإعدادات)، دون أي تغيير على
// الأسعار الفعلية المخزّنة بالدولار.
function currencyJsSnippet(PDO $pdo) {
    $currencies = [];
    foreach (getActiveCurrencies($pdo) as $c) {
        $currencies[$c['code']] = ['name' => $c['name'], 'symbol' => $c['symbol'], 'rate' => (float)$c['rate_per_usd']];
    }
    $forced = getSetting($pdo, 'app_currency', '');
    $currenciesJson = json_encode($currencies, JSON_UNESCAPED_UNICODE);
    $forcedJson = json_encode($forced);
    return <<<JS
    <script>
        const CURRENCIES = {$currenciesJson};
        const APP_CURRENCY_FORCED = {$forcedJson};
        const REGION_CURRENCY_MAP = {
            IQ:'IQD', SA:'SAR', AE:'AED', KW:'KWD', QA:'QAR', BH:'BHD', OM:'OMR', JO:'JOD', EG:'EGP',
            LB:'LBP', SY:'SYP', YE:'YER', LY:'LYD', MA:'MAD', TN:'TND', DZ:'DZD', SD:'SDG', MR:'MRU',
            US:'USD', GB:'GBP', TR:'TRY', IN:'INR', PK:'PKR', CN:'CNY', JP:'JPY', KR:'KRW', RU:'RUB',
            CA:'CAD', AU:'AUD', CH:'CHF', SE:'SEK', NO:'NOK', PL:'PLN', ZA:'ZAR', NG:'NGN', KE:'KES',
            BR:'BRL', MX:'MXN', ID:'IDR', MY:'MYR', PH:'PHP', TH:'THB', VN:'VND', SG:'SGD', HK:'HKD',
            NZ:'NZD', AF:'AFN',
            DE:'EUR', FR:'EUR', IT:'EUR', ES:'EUR', NL:'EUR', BE:'EUR', AT:'EUR', PT:'EUR', IE:'EUR', FI:'EUR', GR:'EUR', LU:'EUR',
        };

        function detectCurrencyCode() {
            try {
                const saved = localStorage.getItem('displayCurrency');
                if (saved && CURRENCIES[saved]) return saved;
            } catch (e) {}
            if (APP_CURRENCY_FORCED && CURRENCIES[APP_CURRENCY_FORCED]) return APP_CURRENCY_FORCED;
            const locale = navigator.language || 'en-US';
            const region = (locale.split('-')[1] || '').toUpperCase();
            const mapped = REGION_CURRENCY_MAP[region];
            return (mapped && CURRENCIES[mapped]) ? mapped : 'USD';
        }

        function setDisplayCurrency(code) {
            try { localStorage.setItem('displayCurrency', code); } catch (e) {}
            applyCurrencyDisplay();
        }

        function formatUsd(amountUsd) {
            const code = detectCurrencyCode();
            const cur = CURRENCIES[code] || { symbol: '\$', rate: 1 };
            const converted = (Number(amountUsd) || 0) * cur.rate;
            const decimals = code === 'USD' ? 2 : 0;
            const num = converted.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
            return num + ' ' + cur.symbol;
        }

        function applyCurrencyDisplay(root) {
            (root || document).querySelectorAll('[data-usd]').forEach(el => {
                el.textContent = formatUsd(el.getAttribute('data-usd'));
            });
        }

        document.addEventListener('DOMContentLoaded', () => applyCurrencyDisplay());
    </script>
    JS;
}

function e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

// ============================================================
// رابط المشاركة (الإحالة)
// ============================================================

function generateReferralCode() {
    return strtoupper(bin2hex(random_bytes(4)));
}

function getOrCreateReferralCode(PDO $pdo, $user) {
    if (!empty($user['referral_code'])) return $user['referral_code'];
    $code = generateReferralCode();
    $pdo->prepare('UPDATE users SET referral_code = ? WHERE id = ?')->execute([$code, (int)$user['id']]);
    return $code;
}

// ============================================================
// الإعدادات (اسم الموقع، الشعار، مفاتيح API) - يديرها الأدمن
// ============================================================

function getAllSettings(PDO $pdo) {
    static $cache = null;
    if ($cache !== null) return $cache;
    $rows = $pdo->query('SELECT `key`, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    $cache = $rows;
    return $cache;
}

function getSetting(PDO $pdo, $key, $default = '') {
    $all = getAllSettings($pdo);
    return $all[$key] ?? $default;
}

function setSetting(PDO $pdo, $key, $value) {
    $pdo->prepare('INSERT INTO settings (`key`, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
        ->execute([$key, $value]);
}

// ============================================================
// إشعارات المستخدم
// ============================================================

function notifyUser(PDO $pdo, $userId, $title, $body, $type = 'system') {
    $pdo->prepare('INSERT INTO notifications (user_id, title, body, type) VALUES (?,?,?,?)')
        ->execute([$userId, $title, $body, $type]);
}

function notifyAdmins(PDO $pdo, $title, $body, $type = 'system') {
    $adminIds = $pdo->query('SELECT id FROM users WHERE is_admin = 1')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($adminIds as $adminId) {
        notifyUser($pdo, (int)$adminId, $title, $body, $type);
    }
}

// ============================================================
// استدعاء واجهة الذكاء الاصطناعي (متوافقة مع NVIDIA / OpenAI chat completions)
// ============================================================

function callAiApi(PDO $pdo, $messages) {
    $apiKey = getSetting($pdo, 'nvidia_api_key', '');
    $model = getSetting($pdo, 'nvidia_model', 'openai/gpt-oss-120b');

    if ($apiKey === '') {
        return [null, 'لم يتم إعداد مفتاح الذكاء الاصطناعي بعد. الرجاء التواصل مع الإدارة.'];
    }

    $ch = curl_init('https://integrate.api.nvidia.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $model,
            'messages' => $messages,
            'temperature' => 0.6,
            'top_p' => 0.9,
            'max_tokens' => 1024,
            'stream' => false,
        ]),
        CURLOPT_TIMEOUT => 45,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return [null, 'تعذر الاتصال بخدمة الذكاء الاصطناعي: ' . $curlError];
    }
    if ($httpCode !== 200) {
        return [null, 'خطأ من خدمة الذكاء الاصطناعي (HTTP ' . $httpCode . ').'];
    }
    $data = json_decode($response, true);
    $reply = $data['choices'][0]['message']['content'] ?? null;
    if (!$reply) {
        return [null, 'لم يصل رد من خدمة الذكاء الاصطناعي.'];
    }
    return [$reply, null];
}

// يُضاف لآخر system prompt الخاص بالمساعد الذكي، ليعرف الحالة الحقيقية
// لطلبات وشحنات المستخدم المعلقة، فيطمئنه بدل أن يعطيه رداً عاماً غير دقيق
function aiAccountStatusContext(PDO $pdo, $userId) {
    $lines = [];

    $orders = $pdo->prepare("
        SELECT o.id, o.created_at, p.name AS plan_name
        FROM orders o JOIN vps_plans p ON p.id = o.plan_id
        WHERE o.user_id = ? AND o.status = 'pending' ORDER BY o.created_at DESC
    ");
    $orders->execute([$userId]);
    foreach ($orders->fetchAll(PDO::FETCH_ASSOC) as $o) {
        $lines[] = '- طلب اشتراك رقم #' . $o['id'] . ' لباقة "' . $o['plan_name'] . '" بتاريخ ' . $o['created_at'] . '، لا يزال قيد المراجعة من الإدارة.';
    }

    $topups = $pdo->prepare("SELECT id, amount, created_at FROM invoices WHERE user_id = ? AND order_id IS NULL AND status = 'pending' ORDER BY created_at DESC");
    $topups->execute([$userId]);
    foreach ($topups->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $lines[] = '- طلب شحن رصيد رقم #' . $t['id'] . ' بمبلغ $' . money($t['amount']) . ' بتاريخ ' . $t['created_at'] . '، لا يزال قيد المراجعة من الإدارة.';
    }

    if (!$lines) {
        return "\n\nملاحظة: لا توجد لدى هذا المستخدم حالياً أي طلبات اشتراك أو شحن رصيد معلقة.";
    }

    return "\n\nمعلومات حقيقية عن حساب هذا المستخدم الآن (استخدمها فقط إذا سأل عن حالة طلب أو شحن رصيد أو تأخير في الموافقة):\n"
        . implode("\n", $lines)
        . "\nإذا سأل عن سبب التأخير أو متى ستتم الموافقة: اذكر له رقم الطلب/الشحن المعلق أعلاه، وطمئنه بأن طلبه مسجّل وأن الفريق يعمل على مراجعته حالياً، واطلب منه التحلي بالصبر قليلاً دون الوعد بوقت محدد.";
}

