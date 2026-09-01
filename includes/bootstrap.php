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

$db_file = BASE_DIR . '/vps_platform.db';

try {
    $pdo = new PDO('sqlite:' . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT UNIQUE,
            phone TEXT UNIQUE,
            password_hash TEXT,
            is_admin INTEGER NOT NULL DEFAULT 0,
            balance REAL NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS vps_plans (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            icon TEXT NOT NULL DEFAULT '🚀',
            cpu TEXT NOT NULL,
            ram TEXT NOT NULL,
            storage TEXT NOT NULL,
            bandwidth TEXT NOT NULL,
            price REAL NOT NULL,
            badge TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS payment_methods (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            logo_path TEXT,
            icon TEXT NOT NULL DEFAULT 'fa-money-bill-wave',
            account_number TEXT,
            instructions TEXT,
            is_active INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            plan_id INTEGER NOT NULL,
            payment_method_id INTEGER,
            amount REAL NOT NULL,
            proof_image TEXT,
            status TEXT NOT NULL DEFAULT 'pending',
            admin_note TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            decided_at DATETIME,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (plan_id) REFERENCES vps_plans(id)
        );

        CREATE TABLE IF NOT EXISTS hosting (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            order_id INTEGER,
            name TEXT NOT NULL,
            plan TEXT NOT NULL,
            ip TEXT NOT NULL,
            username TEXT NOT NULL,
            password TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'active',
            expiry_date DATE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (order_id) REFERENCES orders(id)
        );

        CREATE TABLE IF NOT EXISTS invoices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            order_id INTEGER,
            invoice_number TEXT NOT NULL,
            amount REAL NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            due_date DATE,
            description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id),
            FOREIGN KEY (order_id) REFERENCES orders(id)
        );

        CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY,
            value TEXT
        );

        CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            title TEXT NOT NULL,
            body TEXT,
            type TEXT NOT NULL DEFAULT 'system',
            is_read INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id)
        );

        CREATE TABLE IF NOT EXISTS currencies (
            code TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            symbol TEXT NOT NULL,
            rate_per_usd REAL NOT NULL DEFAULT 1,
            is_active INTEGER NOT NULL DEFAULT 1,
            sort_order INTEGER NOT NULL DEFAULT 0
        );
    ");

    // ------------------------------------------------------------
    // ترحيل قواعد بيانات قديمة من نسخة سابقة للتطبيق (تضيف الأعمدة
    // الناقصة دون فقدان البيانات الموجودة أصلاً)
    // ------------------------------------------------------------
    $ensureColumn = function ($table, $column, $definition) use ($pdo) {
        $existing = $pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($existing as $col) {
            if ($col['name'] === $column) return;
        }
        $pdo->exec("ALTER TABLE $table ADD COLUMN $column $definition");
    };

    $usersCols = array_column($pdo->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (!in_array('email', $usersCols, true) || !in_array('password_hash', $usersCols, true)) {
        // النسخة القديمة كانت تفرض phone TEXT UNIQUE NOT NULL، وهذا القيد لا يمكن
        // إزالته بـ ALTER TABLE في SQLite، لذا نعيد بناء الجدول بالكامل محافظين على البيانات
        try {
            $pdo->beginTransaction();
            $pdo->exec("
                CREATE TABLE users_migrated (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    email TEXT,
                    phone TEXT,
                    password_hash TEXT,
                    is_admin INTEGER NOT NULL DEFAULT 0,
                    balance REAL NOT NULL DEFAULT 0,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ");
            $pdo->exec('INSERT INTO users_migrated (id, name, phone, is_admin, balance, created_at) SELECT id, name, phone, is_admin, balance, created_at FROM users');
            $pdo->exec('DROP TABLE users');
            $pdo->exec('ALTER TABLE users_migrated RENAME TO users');
            $pdo->commit();
        } catch (PDOException $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_email ON users(email)');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_users_phone ON users(phone)');

    $ensureColumn('hosting', 'order_id', 'INTEGER');
    $ensureColumn('invoices', 'order_id', 'INTEGER');
    $ensureColumn('vps_plans', 'original_price', 'REAL');
    $ensureColumn('vps_plans', 'price_yearly', 'REAL');
    $ensureColumn('users', 'google_id', 'TEXT');
    $ensureColumn('orders', 'billing_cycle', "TEXT NOT NULL DEFAULT 'monthly'");
    $ensureColumn('payment_methods', 'method_type', "TEXT NOT NULL DEFAULT 'manual'");
    $ensureColumn('payment_methods', 'currency_code', "TEXT NOT NULL DEFAULT 'USD'");

    // ------------------------------------------------------------
    // بيانات ابتدائية (تُدرج مرة واحدة فقط إذا كانت الجداول فارغة)
    // ------------------------------------------------------------
    if ((int)$pdo->query('SELECT COUNT(*) FROM vps_plans')->fetchColumn() === 0) {
        $seed = $pdo->prepare('INSERT INTO vps_plans (name, icon, cpu, ram, storage, bandwidth, price, badge, sort_order) VALUES (?,?,?,?,?,?,?,?,?)');
        $seed->execute(['أساسي', '🚀', '1 Core', '2 GB', '50 GB SSD', '1 TB', 25, '🔥 الأكثر طلباً', 1]);
        $seed->execute(['متقدم', '⚡', '2 Core', '4 GB', '100 GB SSD', '2 TB', 45, null, 2]);
        $seed->execute(['احترافي', '🔥', '4 Core', '8 GB', '200 GB SSD', '3 TB', 75, null, 3]);
        $seed->execute(['مخصص', '👑', '8 Core', '16 GB', '500 GB SSD', '5 TB', 120, null, 4]);
    }

    if ((int)$pdo->query('SELECT COUNT(*) FROM payment_methods')->fetchColumn() === 0) {
        $seed = $pdo->prepare('INSERT INTO payment_methods (name, icon, account_number, instructions, sort_order) VALUES (?,?,?,?,?)');
        $seed->execute(['زين كاش', 'fa-mobile-screen', '07801234567', 'حوّل المبلغ إلى الرقم أعلاه عبر تطبيق زين كاش، ثم ارفع صورة إيصال التحويل هنا.', 1]);
        $seed->execute(['آسيا سيل كاش', 'fa-sim-card', '07901234567', 'حوّل المبلغ إلى الرقم أعلاه عبر آسيا سيل كاش، ثم ارفع صورة إيصال التحويل هنا.', 2]);
        $seed->execute(['تحويل بنكي', 'fa-building-columns', 'IQ00 BANK 0000 0000 0000 000', 'حوّل المبلغ إلى رقم الحساب البنكي أعلاه، ثم ارفع صورة إيصال التحويل هنا.', 3]);
    }

    if ((int)$pdo->query('SELECT COUNT(*) FROM currencies')->fetchColumn() === 0) {
        $seed = $pdo->prepare('INSERT INTO currencies (code, name, symbol, rate_per_usd, sort_order) VALUES (?,?,?,?,?)');
        $seed->execute(['USD', 'دولار أمريكي', '$', 1, 1]);
        $seed->execute(['IQD', 'دينار عراقي', 'د.ع', 1310, 2]);
    }

    if ((int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn() === 0) {
        $pdo->prepare('INSERT INTO users (name, email, password_hash, is_admin, balance) VALUES (?,?,?,1,0)')
            ->execute(['مدير النظام', 'admin@istidafati.local', password_hash('Admin@12345', PASSWORD_DEFAULT)]);
    }

    if ((int)$pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn() === 0) {
        $seed = $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?)');
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
} catch (PDOException $e) {
    http_response_code(500);
    die('تعذر الاتصال بقاعدة البيانات. تأكد من أن مجلد الموقع قابل للكتابة (permissions). التفاصيل: ' . htmlspecialchars($e->getMessage()));
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
        const REGION_CURRENCY_MAP = { IQ:'IQD', SA:'SAR', AE:'AED', KW:'KWD', QA:'QAR', BH:'BHD', OM:'OMR', JO:'JOD', EG:'EGP', US:'USD', GB:'GBP' };

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
// الإعدادات (اسم الموقع، الشعار، مفاتيح API) - يديرها الأدمن
// ============================================================

function getAllSettings(PDO $pdo) {
    static $cache = null;
    if ($cache !== null) return $cache;
    $rows = $pdo->query('SELECT key, value FROM settings')->fetchAll(PDO::FETCH_KEY_PAIR);
    $cache = $rows;
    return $cache;
}

function getSetting(PDO $pdo, $key, $default = '') {
    $all = getAllSettings($pdo);
    return $all[$key] ?? $default;
}

function setSetting(PDO $pdo, $key, $value) {
    $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, $value]);
}

// ============================================================
// إشعارات المستخدم
// ============================================================

function notifyUser(PDO $pdo, $userId, $title, $body, $type = 'system') {
    $pdo->prepare('INSERT INTO notifications (user_id, title, body, type) VALUES (?,?,?,?)')
        ->execute([$userId, $title, $body, $type]);
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

