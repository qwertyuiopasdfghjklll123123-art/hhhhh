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

    if ((int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn() === 0) {
        $pdo->prepare('INSERT INTO users (name, email, password_hash, is_admin, balance) VALUES (?,?,?,1,0)')
            ->execute(['مدير النظام', 'admin@istidafati.local', password_hash('Admin@12345', PASSWORD_DEFAULT)]);
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

function e($str) {
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}
