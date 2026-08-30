<?php
// ============================================================
// منصة خوادم VPS - نسخة مع استضافات نشطة
// ============================================================

session_start();

// ============================================================
// إعدادات قاعدة البيانات
// ============================================================
$db_file = __DIR__ . '/vps_platform.db';
$pdo = null;

try {
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            phone TEXT UNIQUE NOT NULL,
            is_admin INTEGER DEFAULT 0,
            balance REAL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS hosting (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            plan TEXT NOT NULL,
            ip TEXT NOT NULL,
            username TEXT NOT NULL,
            password TEXT NOT NULL,
            status TEXT DEFAULT 'active',
            expiry_date DATE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS invoices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            invoice_number TEXT NOT NULL,
            amount REAL NOT NULL,
            status TEXT DEFAULT 'pending',
            due_date DATE,
            description TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");
    
} catch (PDOException $e) {
    // تجاهل خطأ قاعدة البيانات
}

// ============================================================
// دوال مساعدة
// ============================================================

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function currentUser() {
    return $_SESSION['user'] ?? null;
}

// ============================================================
// معالجة تسجيل الدخول
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $phone = trim($_POST['phone'] ?? '');
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    if (strlen($phone) === 10 && $phone[0] === '0') {
        $phone = '964' . substr($phone, 1);
    } elseif (strlen($phone) === 9 && $phone[0] === '7') {
        $phone = '964' . $phone;
    }
    
    if (strlen($phone) >= 10) {
        $is_admin = 0;
        $name = 'مستخدم جديد';
        $balance = 0;
        
        if ($phone === '9647819044981' || $phone === '07819044981') {
            $is_admin = 1;
            $name = 'مدير النظام';
            $balance = 100;
        } elseif ($phone === '9647819044911' || $phone === '07819044911') {
            $is_admin = 0;
            $name = 'مستخدم عادي';
            $balance = 50;
        }
        
        $_SESSION['user_id'] = 1;
        $_SESSION['user'] = [
            'id' => 1,
            'name' => $name,
            'phone' => $phone,
            'is_admin' => $is_admin,
            'balance' => $balance
        ];
        
        header('Location: ?app=1');
        exit;
    }
}

// ============================================================
// معالجة تسجيل الخروج
// ============================================================

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ?');
    exit;
}

// ============================================================
// عرض الصفحات
// ============================================================

if (isset($_GET['app']) && isLoggedIn()) {
    includeAppPage();
    exit;
}

if (isLoggedIn()) {
    header('Location: ?app=1');
    exit;
}

// ============================================================
// صفحة الترحيب
// ============================================================

function includeWelcomePage() {
    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
        <title>🚀 خوادم VPS - استضافة احترافية</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
        <style>
            :root {
                --bg-primary: #f7f4f0;
                --bg-secondary: #ffffff;
                --bg-card: #fbf7f3;
                --bg-card-hover: #fdeee0;
                --text-primary: #221a12;
                --text-secondary: #6b5d50;
                --text-muted: #998a7c;
                --accent: #ff7a1a;
                --accent-dark: #ee6a05;
                --accent-light: #ffa64d;
                --accent-glow: rgba(255,122,26,.12);
                --border-color: #f0e6da;
                --border-active: rgba(255,122,26,.3);
                --shadow: 0 10px 40px rgba(34,26,18,.08);
                --shadow-sm: 0 6px 20px rgba(34,26,18,.05);
                --radius: 22px;
                --radius-sm: 14px;
                --transition: .3s cubic-bezier(.4,0,.2,1);
            }

            [data-theme="dark"] {
                --bg-primary: #16130f;
                --bg-secondary: #1e1a15;
                --bg-card: #26211a;
                --bg-card-hover: #2e2820;
                --text-primary: #f5ede6;
                --text-secondary: #b8a99a;
                --text-muted: #8a7a6b;
                --accent: #ff8c3d;
                --accent-dark: #ee6a05;
                --accent-light: #ffb066;
                --accent-glow: rgba(255,140,61,.15);
                --border-color: rgba(255,140,61,.1);
                --border-active: rgba(255,140,61,.3);
                --shadow: 0 8px 40px rgba(0,0,0,.5);
                --shadow-sm: 0 4px 20px rgba(0,0,0,.3);
            }

            * { margin:0; padding:0; box-sizing:border-box; }

            body {
                font-family: 'IBM Plex Sans Arabic', 'Tajawal', system-ui, sans-serif;
                background: var(--bg-primary);
                color: var(--text-primary);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
                transition: background var(--transition), color var(--transition);
                background-image:
                    radial-gradient(circle at 20% 0%, rgba(255,122,26,.08) 0%, transparent 50%),
                    radial-gradient(circle at 80% 70%, rgba(255,122,26,.04) 0%, transparent 40%);
            }
            
            .container {
                width: 100%;
                max-width: 500px;
                position: relative;
            }
            
            .welcome-card {
                background: var(--bg-secondary);
                border: 1px solid var(--border-color);
                border-radius: var(--radius);
                padding: 40px 28px 32px;
                box-shadow: var(--shadow);
                position: relative;
                overflow: hidden;
                transition: background var(--transition), border-color var(--transition), box-shadow var(--transition);
            }
            
            .welcome-card::before {
                content: '';
                position: absolute;
                top: -60%;
                left: -60%;
                width: 220%;
                height: 220%;
                background: conic-gradient(from 0deg at 50% 50%, transparent 0%, rgba(255,122,26,.02) 25%, transparent 50%, rgba(255,122,26,.02) 75%, transparent 100%);
                animation: spinGlow 25s linear infinite;
                pointer-events: none;
            }
            @keyframes spinGlow {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            
            .welcome-card > * { position: relative; z-index: 1; }
            
            .theme-toggle-wrapper {
                position: absolute;
                top: 16px;
                left: 16px;
                z-index: 10;
            }
            
            .theme-toggle {
                width: 44px;
                height: 44px;
                border-radius: 50%;
                border: 1px solid var(--border-color);
                background: var(--bg-card);
                color: var(--text-secondary);
                cursor: pointer;
                font-size: 18px;
                transition: var(--transition);
                display: flex;
                align-items: center;
                justify-content: center;
                box-shadow: var(--shadow-sm);
                font-family: inherit;
            }
            .theme-toggle:hover {
                border-color: var(--accent);
                color: var(--accent);
                transform: rotate(15deg);
                background: var(--bg-card-hover);
            }
            
            .hero-panel {
                margin: -40px -28px 22px;
                padding: 40px 24px 30px;
                background: linear-gradient(160deg, var(--accent-light), var(--accent) 55%, var(--accent-dark));
                border-radius: 0 0 32px 32px;
                position: relative;
            }

            .logo-wrapper {
                text-align: center;
                margin-bottom: 16px;
            }

            .logo {
                width: 84px;
                height: 84px;
                margin: 0 auto;
                border-radius: 50%;
                background: rgba(255,255,255,.22);
                border: 1.5px solid rgba(255,255,255,.4);
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 40px;
                color: #ffffff;
                box-shadow: 0 12px 40px rgba(34,26,18,.18);
                transition: var(--transition);
                position: relative;
            }
            .logo::after {
                content: '';
                position: absolute;
                inset: -3px;
                border-radius: 50%;
                background: linear-gradient(135deg, rgba(255,255,255,.6), transparent, rgba(255,255,255,.2));
                opacity: .3;
                z-index: -1;
                animation: pulseRing 2s ease-in-out infinite;
            }
            @keyframes pulseRing {
                0%, 100% { transform: scale(1); opacity: .3; }
                50% { transform: scale(1.1); opacity: .1; }
            }

            .logo:hover {
                transform: scale(1.05) rotate(-3deg);
                box-shadow: 0 16px 50px rgba(34,26,18,.22);
            }

            .site-title {
                font-size: 30px;
                font-weight: 900;
                text-align: center;
                color: #ffffff;
                line-height: 1.2;
                margin-bottom: 0;
                letter-spacing: -1px;
            }

            .site-subtitle {
                text-align: center;
                color: var(--text-secondary);
                font-size: 14px;
                margin-bottom: 24px;
                line-height: 1.8;
                transition: color var(--transition);
            }
            .site-subtitle strong {
                color: var(--accent);
                -webkit-text-fill-color: var(--accent);
            }
            
            .plans-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 8px;
                margin-bottom: 20px;
            }
            
            .plan-card {
                background: var(--bg-card);
                border: 1px solid var(--border-color);
                border-radius: var(--radius-sm);
                padding: 14px 10px;
                text-align: center;
                transition: var(--transition);
                cursor: default;
            }
            .plan-card:hover {
                border-color: var(--border-active);
                transform: translateY(-3px);
                background: var(--bg-card-hover);
                box-shadow: var(--shadow-sm);
            }
            
            .plan-card .plan-name {
                font-size: 14px;
                font-weight: 800;
                color: var(--text-primary);
                transition: color var(--transition);
            }
            .plan-card .plan-specs {
                font-size: 9px;
                color: var(--text-muted);
                margin: 3px 0;
                transition: color var(--transition);
            }
            .plan-card .plan-price {
                font-size: 19px;
                font-weight: 900;
                color: var(--accent);
            }
            .plan-card .plan-price small {
                font-size: 10px;
                font-weight: 600;
                color: var(--text-muted);
                transition: color var(--transition);
            }
            .plan-card .plan-badge {
                display: inline-block;
                padding: 1px 10px;
                border-radius: 999px;
                font-size: 8px;
                font-weight: 700;
                background: rgba(255,122,26,.12);
                color: var(--accent);
                margin-top: 5px;
            }
            
            .features-grid {
                display: grid;
                grid-template-columns: repeat(3, 1fr);
                gap: 6px;
                margin-bottom: 22px;
            }
            
            .feature-box {
                text-align: center;
                padding: 12px 4px;
                background: var(--bg-card);
                border-radius: var(--radius-sm);
                border: 1px solid var(--border-color);
                transition: var(--transition);
            }
            .feature-box:hover {
                border-color: var(--border-active);
                background: var(--bg-card-hover);
            }
            
            .feature-box i {
                font-size: 22px;
                color: var(--accent);
                display: block;
                margin-bottom: 4px;
            }
            .feature-box h4 {
                font-size: 11px;
                font-weight: 700;
                color: var(--text-primary);
                transition: color var(--transition);
            }
            .feature-box p {
                font-size: 8px;
                color: var(--text-muted);
                transition: color var(--transition);
            }
            
            .login-section {
                margin-top: 4px;
            }
            
            .login-section .field-label {
                display: block;
                font-size: 13px;
                font-weight: 600;
                color: var(--text-secondary);
                margin-bottom: 6px;
                text-align: right;
                transition: color var(--transition);
            }
            
            .phone-input-wrap {
                display: flex;
                gap: 8px;
                background: var(--bg-card);
                border: 1.5px solid var(--border-color);
                border-radius: var(--radius-sm);
                padding: 4px;
                transition: var(--transition);
                direction: ltr;
            }
            .phone-input-wrap:focus-within {
                border-color: var(--accent);
                box-shadow: 0 0 0 4px var(--accent-glow);
            }
            
            .phone-input-wrap .prefix {
                padding: 12px 12px;
                font-weight: 700;
                color: var(--text-muted);
                font-size: 14px;
                flex-shrink: 0;
                background: transparent;
                border: none;
                transition: color var(--transition);
            }
            
            .phone-input-wrap input {
                flex: 1;
                padding: 12px 10px;
                background: transparent;
                border: none;
                outline: none;
                color: var(--text-primary);
                font-size: 16px;
                font-family: inherit;
                direction: ltr;
                text-align: left;
                transition: color var(--transition);
            }
            .phone-input-wrap input::placeholder {
                color: var(--text-muted);
                font-size: 14px;
                transition: color var(--transition);
            }
            
            .btn-primary {
                width: 100%;
                padding: 16px;
                margin-top: 14px;
                border: none;
                border-radius: var(--radius-sm);
                background: linear-gradient(135deg, var(--accent), var(--accent-dark));
                color: #ffffff;
                font-weight: 800;
                font-size: 17px;
                font-family: inherit;
                cursor: pointer;
                transition: var(--transition);
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 10px;
                box-shadow: 0 8px 35px rgba(255,122,26,.15);
            }
            .btn-primary:hover {
                transform: translateY(-3px);
                box-shadow: 0 12px 45px rgba(255,122,26,.25);
            }
            .btn-primary:active {
                transform: scale(.97);
            }

            .splash-actions {
                display: flex;
                flex-direction: column;
                gap: 10px;
                margin-top: 4px;
            }
            .btn-secondary-full {
                width: 100%;
                padding: 15px;
                border: 1.5px solid var(--border-color);
                border-radius: var(--radius-sm);
                background: var(--bg-card);
                color: var(--text-primary);
                font-weight: 800;
                font-size: 15px;
                font-family: inherit;
                cursor: pointer;
                transition: var(--transition);
            }
            .btn-secondary-full:hover {
                border-color: var(--accent);
                color: var(--accent);
            }

            .hidden { display: none !important; }

            .login-hint {
                text-align: center;
                color: var(--text-muted);
                font-size: 11px;
                margin-top: 14px;
                line-height: 1.8;
                transition: color var(--transition);
            }
            .login-hint .highlight {
                color: var(--accent);
                -webkit-text-fill-color: var(--accent);
                font-weight: 700;
            }
            
            .secure-badge {
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 6px;
                color: var(--text-muted);
                font-size: 11px;
                margin-top: 16px;
                transition: color var(--transition);
            }
            .secure-badge i {
                color: var(--accent);
            }
            
            @media (max-width: 430px) {
                .plans-grid { grid-template-columns: 1fr 1fr; }
                .features-grid { grid-template-columns: repeat(2, 1fr); }
                .welcome-card { padding: 28px 16px 24px; }
                .site-title { font-size: 28px; }
                .logo { width: 74px; height: 74px; font-size: 36px; }
                .theme-toggle { width: 38px; height: 38px; font-size: 16px; }
            }
            
            @media (max-width: 360px) {
                .plans-grid { grid-template-columns: 1fr; }
                .features-grid { grid-template-columns: 1fr 1fr; }
                .welcome-card { padding: 20px 12px 18px; }
                .site-title { font-size: 24px; }
                .logo { width: 64px; height: 64px; font-size: 30px; }
            }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="welcome-card">
                <div class="theme-toggle-wrapper">
                    <button class="theme-toggle" id="themeToggle" onclick="toggleTheme()" aria-label="تبديل المظهر">
                        <i class="fas fa-moon" id="themeIcon"></i>
                    </button>
                </div>
                
                <div class="hero-panel">
                    <div class="logo-wrapper">
                        <div class="logo">
                            <i class="fas fa-server"></i>
                        </div>
                    </div>

                    <h1 class="site-title">خوادم VPS</h1>
                </div>

                <p class="site-subtitle">
                    استضافة احترافية بأعلى أداء وسرعة<br>
                    <strong>دعم فني 24/7</strong> · <strong>أمان كامل</strong>
                </p>
                
                <div id="splashView">
                    <div class="plans-grid">
                        <div class="plan-card">
                            <div class="plan-name">🚀 أساسي</div>
                            <div class="plan-specs">1 Core · 2GB · 50GB</div>
                            <div class="plan-price">25$ <small>/شهر</small></div>
                            <span class="plan-badge">شائع</span>
                        </div>
                        <div class="plan-card">
                            <div class="plan-name">⚡ متقدم</div>
                            <div class="plan-specs">2 Core · 4GB · 100GB</div>
                            <div class="plan-price">45$ <small>/شهر</small></div>
                            <span class="plan-badge">مفضل</span>
                        </div>
                        <div class="plan-card">
                            <div class="plan-name">🔥 احترافي</div>
                            <div class="plan-specs">4 Core · 8GB · 200GB</div>
                            <div class="plan-price">75$ <small>/شهر</small></div>
                            <span class="plan-badge">قوي</span>
                        </div>
                        <div class="plan-card">
                            <div class="plan-name">👑 مخصص</div>
                            <div class="plan-specs">8 Core · 16GB · 500GB</div>
                            <div class="plan-price">120$ <small>/شهر</small></div>
                            <span class="plan-badge">احترافي</span>
                        </div>
                    </div>

                    <div class="features-grid">
                        <div class="feature-box">
                            <i class="fas fa-bolt"></i>
                            <h4>أداء خارق</h4>
                            <p>معالجات حديثة SSD</p>
                        </div>
                        <div class="feature-box">
                            <i class="fas fa-shield-halved"></i>
                            <h4>أمان كامل</h4>
                            <p>حماية DDoS</p>
                        </div>
                        <div class="feature-box">
                            <i class="fas fa-headset"></i>
                            <h4>دعم فني</h4>
                            <p>خدمة 24/7</p>
                        </div>
                    </div>

                    <div class="splash-actions">
                        <button type="button" class="btn-primary" onclick="showLoginForm()">
                            <i class="fas fa-rocket"></i> ابدأ الآن
                        </button>
                        <button type="button" class="btn-secondary-full" onclick="showLoginForm()">
                            تسجيل الدخول
                        </button>
                    </div>
                </div>

                <div id="loginView" class="hidden">
                    <div class="login-section">
                        <form method="POST" action="">
                            <label class="field-label">
                                <i class="fas fa-phone"></i> رقم الهاتف للدخول
                            </label>
                            <div class="phone-input-wrap">
                                <span class="prefix">🇮🇶 +964</span>
                                <input type="tel" name="phone" placeholder="7701234567" dir="ltr" required>
                            </div>

                            <button type="submit" name="login" class="btn-primary">
                                <i class="fas fa-rocket"></i> دخول إلى لوحة التحكم
                            </button>
                        </form>

                        <div class="login-hint">
                            <span class="highlight">📱 أرقام تجريبية:</span><br>
                            <span style="color:var(--accent)">07819044981</span> ← أدمن &nbsp;|&nbsp;
                            <span style="color:var(--accent)">07819044911</span> ← مستخدم
                        </div>

                        <div class="secure-badge">
                            <i class="fas fa-lock"></i>
                            <span>آمن ومشفر · بدون كلمة مرور</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        function showLoginForm() {
            document.getElementById('splashView').classList.add('hidden');
            document.getElementById('loginView').classList.remove('hidden');
            const phoneInput = document.querySelector('#loginView input[name="phone"]');
            if (phoneInput) phoneInput.focus();
        }

        function toggleTheme() {
            const html = document.documentElement;
            const currentTheme = html.getAttribute('data-theme') || 'light';
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
            
            html.setAttribute('data-theme', newTheme);
            localStorage.setItem('theme', newTheme);
            
            const icon = document.getElementById('themeIcon');
            if (newTheme === 'dark') {
                icon.className = 'fas fa-moon';
            } else {
                icon.className = 'fas fa-sun';
            }
        }
        
        (function() {
            const savedTheme = localStorage.getItem('theme') || 'light';
            document.documentElement.setAttribute('data-theme', savedTheme);
            
            const icon = document.getElementById('themeIcon');
            if (savedTheme === 'dark') {
                icon.className = 'fas fa-moon';
            } else {
                icon.className = 'fas fa-sun';
            }
        })();
        </script>
    </body>
    </html>
    <?php
}

// ============================================================
// صفحة لوحة التحكم
// ============================================================

function includeAppPage() {
    $user = currentUser();
    $is_admin = $user['is_admin'] ?? 0;
    $user_name = $user['name'] ?? 'مستخدم';
    $balance = $user['balance'] ?? 0;
    
    // استضافات وهمية
    $hosting = [
        [
            'id' => 1,
            'name' => 'خادم أساسي - مشروع 1',
            'plan' => 'أساسي',
            'ip' => '192.168.1.100',
            'username' => 'admin',
            'password' => 'P@ssw0rd123',
            'status' => 'active',
            'expiry_date' => '2025-01-15'
        ],
        [
            'id' => 2,
            'name' => 'خادم متقدم - متجر إلكتروني',
            'plan' => 'متقدم',
            'ip' => '192.168.1.101',
            'username' => 'root',
            'password' => 'Secure#2024',
            'status' => 'active',
            'expiry_date' => '2024-12-20'
        ],
        [
            'id' => 3,
            'name' => 'خادم احترافي - منصة تعليمية',
            'plan' => 'احترافي',
            'ip' => '192.168.1.102',
            'username' => 'admin',
            'password' => 'Edu@2024',
            'status' => 'expired',
            'expiry_date' => '2024-11-01'
        ],
    ];
    
    $invoices = [
        ['id' => 1, 'number' => 'VPS-2024-001', 'amount' => 25, 'status' => 'paid', 'due_date' => '2024-12-01', 'description' => 'خادم أساسي - شهر ديسمبر'],
        ['id' => 2, 'number' => 'VPS-2024-002', 'amount' => 45, 'status' => 'pending', 'due_date' => '2024-12-15', 'description' => 'خادم متقدم - شهر ديسمبر'],
        ['id' => 3, 'number' => 'VPS-2024-003', 'amount' => 75, 'status' => 'overdue', 'due_date' => '2024-11-01', 'description' => 'خادم احترافي - شهر نوفمبر'],
    ];
    
    $payment_methods = [
        ['id' => 'zain_cash', 'name' => 'زين كاش', 'icon' => 'fa-mobile-alt', 'color' => 'green'],
        ['id' => 'asia_cell', 'name' => 'آسيا سيل', 'icon' => 'fa-sim-card', 'color' => 'blue'],
        ['id' => 'credit_card', 'name' => 'بطاقة ائتمانية', 'icon' => 'fa-credit-card', 'color' => 'purple'],
        ['id' => 'bank_transfer', 'name' => 'تحويل بنكي', 'icon' => 'fa-university', 'color' => 'gold'],
    ];

    // باقات VPS للطلب
    $vps_plans = [
        ['id' => 'basic', 'icon' => '🚀', 'name' => 'أساسي', 'price' => 25, 'cpu' => '1 Core', 'ram' => '2 GB', 'storage' => '50 GB SSD', 'bandwidth' => '1 TB', 'badge' => '🔥 الأكثر طلباً'],
        ['id' => 'advanced', 'icon' => '⚡', 'name' => 'متقدم', 'price' => 45, 'cpu' => '2 Core', 'ram' => '4 GB', 'storage' => '100 GB SSD', 'bandwidth' => '2 TB', 'badge' => null],
        ['id' => 'pro', 'icon' => '🔥', 'name' => 'احترافي', 'price' => 75, 'cpu' => '4 Core', 'ram' => '8 GB', 'storage' => '200 GB SSD', 'bandwidth' => '3 TB', 'badge' => null],
        ['id' => 'custom', 'icon' => '👑', 'name' => 'مخصص', 'price' => 120, 'cpu' => '8 Core', 'ram' => '16 GB', 'storage' => '500 GB SSD', 'bandwidth' => '5 TB', 'badge' => null],
    ];

    // بيانات المساعد الذكي (تجريبية)
    $ai_tools = [
        ['icon' => 'fa-gauge-high', 'color' => 'gold', 'title' => 'تحسين حالة السيرفر', 'sub' => 'فحص أداء جميع خدمات السيرفر'],
        ['icon' => 'fa-bolt', 'color' => 'blue', 'title' => 'اختبار السرعة', 'sub' => 'اختبار سرعة الشبكة والاتصال'],
        ['icon' => 'fa-shield-halved', 'color' => 'green', 'title' => 'فحص الأمان', 'sub' => 'تدقيق إعدادات أمان السيرفر'],
        ['icon' => 'fa-database', 'color' => 'purple', 'title' => 'نسخ احتياطي ذكي', 'sub' => 'إنشاء نسخة احتياطية فورية'],
    ];
    $ai_conversations = [
        ['title' => 'تحسين أداء السيرفر', 'preview' => 'شكراً، الخطوات وضحت المشكلة', 'time' => '10:30 ص · اليوم'],
        ['title' => 'مشكلة في الاتصال بالسيرفر', 'preview' => 'جرب إعادة تشغيل خدمة الشبكة', 'time' => '4:15 م · أمس'],
        ['title' => 'شرح أمر sudo apt update', 'preview' => 'يقوم هذا الأمر بتحديث قائمة الحزم', 'time' => '9:45 ص · منذ يومين'],
    ];

    ?>
    <!DOCTYPE html>
    <html lang="ar" dir="rtl">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>لوحة تحكم VPS</title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
        <style>
            :root {
                --bg-primary: #f7f4f0;
                --bg-secondary: #ffffff;
                --bg-card: #fbf7f3;
                --bg-card-hover: #fdeee0;
                --text-primary: #221a12;
                --text-secondary: #6b5d50;
                --text-muted: #998a7c;
                --accent: #ff7a1a;
                --accent-dark: #ee6a05;
                --accent-light: #ffa64d;
                --accent-glow: rgba(255,122,26,.12);
                --border-color: #f0e6da;
                --border-active: rgba(255,122,26,.3);
                --shadow: 0 10px 40px rgba(34,26,18,.08);
                --shadow-sm: 0 6px 20px rgba(34,26,18,.05);
                --radius: 22px;
                --radius-sm: 14px;
                --transition: .3s cubic-bezier(.4,0,.2,1);
                --header-height: 64px;
                --nav-height: 68px;
            }

            [data-theme="dark"] {
                --bg-primary: #16130f;
                --bg-secondary: #1e1a15;
                --bg-card: #26211a;
                --bg-card-hover: #2e2820;
                --text-primary: #f5ede6;
                --text-secondary: #b8a99a;
                --text-muted: #8a7a6b;
                --accent: #ff8c3d;
                --accent-dark: #ee6a05;
                --accent-light: #ffb066;
                --accent-glow: rgba(255,140,61,.15);
                --border-color: rgba(255,140,61,.1);
                --border-active: rgba(255,140,61,.3);
                --shadow: 0 8px 40px rgba(0,0,0,.4);
                --shadow-sm: 0 4px 20px rgba(0,0,0,.3);
            }
            
            * { margin:0; padding:0; box-sizing:border-box; }
            
            body {
                font-family: 'IBM Plex Sans Arabic', 'Tajawal', system-ui, sans-serif;
                background: var(--bg-primary);
                color: var(--text-primary);
                min-height: 100vh;
                padding-bottom: calc(var(--nav-height) + 20px);
                transition: background var(--transition), color var(--transition);
            }
            
            .header {
                background: var(--bg-secondary);
                border-bottom: 1px solid var(--border-color);
                padding: 12px 20px;
                height: var(--header-height);
                display: flex;
                align-items: center;
                justify-content: space-between;
                position: sticky;
                top: 0;
                z-index: 100;
                backdrop-filter: blur(16px);
                -webkit-backdrop-filter: blur(16px);
                transition: background var(--transition), border-color var(--transition);
            }
            
            .header .brand {
                display: flex;
                align-items: center;
                gap: 10px;
                font-size: 17px;
                font-weight: 800;
            }
            .header .brand .logo {
                width: 36px;
                height: 36px;
                border-radius: var(--radius-sm);
                background: linear-gradient(135deg, var(--accent), var(--accent-dark));
                display: flex;
                align-items: center;
                justify-content: center;
                color: #ffffff;
                font-size: 18px;
            }
            .header .brand span {
                background: linear-gradient(135deg, var(--accent), var(--accent-light), var(--accent-dark));
                -webkit-background-clip: text;
                -webkit-text-fill-color: transparent;
                background-clip: text;
            }
            
            .header .header-actions {
                display: flex;
                align-items: center;
                gap: 8px;
            }
            
            .header-theme-toggle {
                width: 38px;
                height: 38px;
                border-radius: 50%;
                border: 1px solid var(--border-color);
                background: var(--bg-card);
                color: var(--text-secondary);
                cursor: pointer;
                font-size: 16px;
                transition: var(--transition);
                display: flex;
                align-items: center;
                justify-content: center;
                font-family: inherit;
            }
            .header-theme-toggle:hover {
                border-color: var(--accent);
                color: var(--accent);
                transform: rotate(15deg);
                background: var(--bg-card-hover);
            }
            
            .container {
                max-width: 680px;
                margin: 0 auto;
                padding: 16px 20px;
            }
            
            .card {
                background: var(--bg-secondary);
                border: 1px solid var(--border-color);
                border-radius: var(--radius);
                padding: 20px 18px;
                margin-bottom: 14px;
                transition: background var(--transition), border-color var(--transition), box-shadow var(--transition);
            }
            .card:hover {
                border-color: var(--border-active);
            }
            
            .card-header {
                display: flex;
                align-items: center;
                justify-content: space-between;
                margin-bottom: 14px;
            }
            .card-header h3 {
                font-size: 15px;
                font-weight: 800;
                display: flex;
                align-items: center;
                gap: 8px;
                color: var(--text-primary);
                transition: color var(--transition);
            }
            .card-header h3 i { color: var(--accent); }
            
            /* ============================================================
               إحصائيات جديدة - استضافات نشطة
               ============================================================ */
            .hosting-stats-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 8px;
                margin-bottom: 16px;
            }
            .stat-box {
                background: var(--bg-card);
                border: 1px solid var(--border-color);
                border-radius: var(--radius-sm);
                padding: 14px 4px;
                text-align: center;
                transition: var(--transition);
            }
            .stat-box:hover {
                border-color: var(--border-active);
                background: var(--bg-card-hover);
                transform: translateY(-2px);
            }
            .stat-box .num {
                font-size: 22px;
                font-weight: 900;
                color: var(--accent);
            }
            .stat-box .label {
                font-size: 9px;
                color: var(--text-muted);
                margin-top: 3px;
                transition: color var(--transition);
            }
            
            .quick-grid {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 8px;
                margin-bottom: 16px;
            }
            .quick-btn {
                background: var(--bg-card);
                border: 1px solid var(--border-color);
                border-radius: var(--radius-sm);
                padding: 14px 4px;
                text-align: center;
                cursor: pointer;
                font-family: inherit;
                color: var(--text-primary);
                font-weight: 600;
                font-size: 11px;
                transition: var(--transition);
            }
            .quick-btn:hover {
                border-color: var(--border-active);
                background: var(--bg-card-hover);
                transform: translateY(-3px);
                box-shadow: var(--shadow-sm);
            }
            .quick-btn i {
                font-size: 24px;
                color: var(--accent);
                display: block;
                margin-bottom: 4px;
            }
            
            .bottom-nav {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                background: var(--bg-secondary);
                border-top: 1px solid var(--border-color);
                display: flex;
                padding: 4px 4px 12px;
                z-index: 200;
                backdrop-filter: blur(16px);
                -webkit-backdrop-filter: blur(16px);
                height: var(--nav-height);
                transition: background var(--transition), border-color var(--transition);
            }
            
            .bottom-nav .nav-item {
                flex: 1;
                text-align: center;
                padding: 6px 0;
                border: none;
                background: transparent;
                color: var(--text-muted);
                cursor: pointer;
                font-family: inherit;
                font-size: 10px;
                font-weight: 600;
                transition: var(--transition);
                border-radius: var(--radius-sm);
                position: relative;
            }
            .bottom-nav .nav-item.active {
                color: var(--accent);
                background: var(--accent-glow);
            }
            .bottom-nav .nav-item i {
                font-size: 20px;
                display: block;
                margin-bottom: 2px;
                transition: var(--transition);
            }
            .bottom-nav .nav-item.active i {
                transform: translateY(-2px);
            }

            .bottom-nav .nav-item.nav-item-fab {
                overflow: visible;
            }
            .bottom-nav .nav-item.nav-item-fab .fab-icon {
                width: 52px;
                height: 52px;
                border-radius: 50%;
                background: linear-gradient(145deg, var(--accent-light), var(--accent), var(--accent-dark));
                color: #fff;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: -30px auto 4px;
                box-shadow: 0 10px 24px rgba(255,122,26,.45);
                transition: var(--transition);
            }
            .bottom-nav .nav-item.nav-item-fab i {
                font-size: 20px;
                margin-bottom: 0;
            }
            .bottom-nav .nav-item.nav-item-fab:hover .fab-icon {
                transform: translateY(-2px) scale(1.06);
            }
            .bottom-nav .nav-item.nav-item-fab.active {
                background: transparent;
                color: var(--accent);
            }
            .bottom-nav .nav-item.nav-item-fab.active .fab-icon {
                box-shadow: 0 10px 28px rgba(255,122,26,.55);
            }
            
            .btn-gold {
                width: 100%;
                padding: 14px;
                border: none;
                border-radius: var(--radius-sm);
                background: linear-gradient(135deg, var(--accent), var(--accent-dark));
                color: #ffffff;
                font-weight: 800;
                font-size: 15px;
                font-family: inherit;
                cursor: pointer;
                transition: var(--transition);
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                box-shadow: 0 8px 30px rgba(255,122,26,.15);
            }
            .btn-gold:hover {
                transform: translateY(-2px);
                box-shadow: 0 12px 40px rgba(255,122,26,.25);
            }
            .btn-gold:disabled {
                opacity: .45;
                cursor: not-allowed;
                transform: none !important;
                box-shadow: none !important;
            }
            
            .btn-outline {
                padding: 8px 16px;
                border: 1.5px solid var(--border-color);
                border-radius: var(--radius-sm);
                background: transparent;
                color: var(--text-secondary);
                font-weight: 600;
                font-size: 12px;
                cursor: pointer;
                font-family: inherit;
                transition: var(--transition);
            }
            .btn-outline:hover {
                border-color: var(--accent);
                color: var(--accent);
            }
            .btn-small { padding: 5px 12px; font-size: 11px; }
            .btn-back {
                padding: 5px 14px;
                border: 1.5px solid var(--border-color);
                border-radius: var(--radius-sm);
                background: transparent;
                color: var(--text-secondary);
                font-weight: 600;
                font-size: 12px;
                cursor: pointer;
                font-family: inherit;
                transition: var(--transition);
            }
            .btn-back:hover {
                border-color: var(--accent);
                color: var(--accent);
            }
            
            .pill {
                padding: 1px 10px;
                border-radius: 999px;
                font-size: 9px;
                font-weight: 700;
                display: inline-block;
            }
            .pill-green { background: rgba(16,185,129,.12); color: #34d399; }
            .pill-amber { background: rgba(251,191,36,.12); color: #fbbf24; }
            .pill-red { background: rgba(239,68,68,.12); color: #f87171; }
            .pill-gold { background: rgba(255,122,26,.12); color: var(--accent); }
            
            .admin-badge {
                display: inline-block;
                padding: 2px 12px;
                border-radius: 999px;
                font-size: 10px;
                font-weight: 700;
                background: linear-gradient(135deg, var(--accent), var(--accent-dark));
                color: #ffffff;
            }
            
            .vps-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }
            .vps-card {
                background: var(--bg-card);
                border: 1px solid var(--border-color);
                border-radius: var(--radius-sm);
                padding: 16px 12px;
                text-align: center;
                transition: var(--transition);
            }
            .vps-card:hover {
                border-color: var(--border-active);
                transform: translateY(-3px);
                box-shadow: var(--shadow-sm);
            }
            .vps-card .name {
                font-size: 15px;
                font-weight: 800;
                color: var(--text-primary);
                transition: color var(--transition);
            }
            .vps-card .specs {
                font-size: 10px;
                color: var(--text-muted);
                margin: 4px 0;
                transition: color var(--transition);
            }
            .vps-card .price {
                font-size: 22px;
                font-weight: 900;
                color: var(--accent);
            }
            .vps-card .price small {
                font-size: 11px;
                font-weight: 600;
                color: var(--text-muted);
                transition: color var(--transition);
            }
            
            /* ============================================================
               عناصر الاستضافة
               ============================================================ */
            .hosting-item {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 12px 0;
                border-bottom: 1px solid var(--border-color);
                transition: border-color var(--transition);
                cursor: pointer;
            }
            .hosting-item:last-child { border-bottom: none; }
            .hosting-item:hover {
                background: var(--bg-card-hover);
                margin: 0 -10px;
                padding: 12px 10px;
                border-radius: var(--radius-sm);
            }
            .hosting-item .info .name {
                font-weight: 700;
                font-size: 13px;
                color: var(--text-primary);
                transition: color var(--transition);
            }
            .hosting-item .info .sub {
                font-size: 10px;
                color: var(--text-muted);
                transition: color var(--transition);
            }
            .hosting-item .status-badge {
                text-align: left;
            }
            
            /* ============================================================
               تفاصيل الاستضافة
               ============================================================ */
            .hosting-detail {
                background: var(--bg-secondary);
                border: 1px solid var(--border-color);
                border-radius: var(--radius);
                padding: 24px 20px;
                margin-bottom: 14px;
                transition: background var(--transition), border-color var(--transition);
            }
            .hosting-detail .detail-row {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 10px 0;
                border-bottom: 1px solid var(--border-color);
            }
            .hosting-detail .detail-row:last-child {
                border-bottom: none;
            }
            .hosting-detail .detail-row .label {
                color: var(--text-muted);
                font-size: 13px;
                transition: color var(--transition);
            }
            .hosting-detail .detail-row .value {
                font-weight: 700;
                color: var(--text-primary);
                font-size: 13px;
                transition: color var(--transition);
                direction: ltr;
                text-align: left;
            }
            .hosting-detail .detail-row .value.password {
                font-family: monospace;
                letter-spacing: 2px;
            }
            .hosting-detail .detail-row .value .copy-btn {
                background: transparent;
                border: none;
                color: var(--text-muted);
                cursor: pointer;
                font-size: 14px;
                transition: var(--transition);
                padding: 0 6px;
            }
            .hosting-detail .detail-row .value .copy-btn:hover {
                color: var(--accent);
            }
            
            /* ============================================================
               بطاقة تأكيد تسجيل الخروج - FastCrand
               ============================================================ */
            .logout-overlay {
                position: fixed;
                inset: 0;
                background: rgba(0,0,0,.6);
                backdrop-filter: blur(8px);
                -webkit-backdrop-filter: blur(8px);
                z-index: 999;
                display: none;
                align-items: center;
                justify-content: center;
                padding: 20px;
                animation: fadeOverlay .3s ease;
            }
            .logout-overlay.show {
                display: flex;
            }
            @keyframes fadeOverlay {
                from { opacity: 0; }
                to { opacity: 1; }
            }
            
            .logout-sheet {
                background: var(--bg-secondary);
                border: 1px solid var(--border-color);
                border-radius: var(--radius);
                max-width: 400px;
                width: 100%;
                padding: 32px 24px 28px;
                box-shadow: var(--shadow);
                text-align: center;
                animation: slideUp .35s cubic-bezier(.34,1.56,.64,1);
                position: relative;
                overflow: hidden;
            }
            .logout-sheet::before {
                content: '';
                position: absolute;
                top: -50%;
                left: -50%;
                width: 200%;
                height: 200%;
                background: conic-gradient(from 0deg at 50% 50%, transparent 0%, rgba(255,122,26,.02) 25%, transparent 50%, rgba(255,122,26,.02) 75%, transparent 100%);
                animation: spinGlow 20s linear infinite;
                pointer-events: none;
            }
            .logout-sheet > * { position: relative; z-index: 1; }
            @keyframes spinGlow {
                0% { transform: rotate(0deg); }
                100% { transform: rotate(360deg); }
            }
            @keyframes slideUp {
                from { transform: translateY(30px) scale(.95); opacity: 0; }
                to { transform: translateY(0) scale(1); opacity: 1; }
            }
            
            .logout-sheet .icon-box {
                width: 72px;
                height: 72px;
                margin: 0 auto 16px;
                border-radius: 50%;
                background: rgba(239,68,68,.12);
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 32px;
                color: #f87171;
                transition: background var(--transition);
            }
            [data-theme="light"] .logout-sheet .icon-box {
                background: rgba(239,68,68,.08);
            }
            
            .logout-sheet h3 {
                font-size: 20px;
                font-weight: 800;
                color: var(--text-primary);
                margin-bottom: 6px;
                transition: color var(--transition);
            }
            .logout-sheet p {
                font-size: 14px;
                color: var(--text-muted);
                margin-bottom: 24px;
                line-height: 1.6;
                transition: color var(--transition);
            }
            
            .logout-sheet .actions {
                display: flex;
                gap: 10px;
            }
            .logout-sheet .actions button {
                flex: 1;
                padding: 12px;
                border: none;
                border-radius: var(--radius-sm);
                font-size: 14px;
                font-weight: 700;
                font-family: inherit;
                cursor: pointer;
                transition: var(--transition);
            }
            .logout-sheet .btn-cancel {
                background: var(--bg-card);
                color: var(--text-secondary);
                border: 1px solid var(--border-color);
            }
            .logout-sheet .btn-cancel:hover {
                background: var(--bg-card-hover);
                transform: translateY(-2px);
            }
            .logout-sheet .btn-confirm {
                background: linear-gradient(135deg, #EF4444, #DC2626);
                color: #fff;
                box-shadow: 0 4px 16px rgba(239,68,68,.25);
            }
            .logout-sheet .btn-confirm:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 24px rgba(239,68,68,.35);
            }
            
            /* ============================================================
               طرق الدفع - مصغرة
               ============================================================ */
            .payment-methods-grid {
                display: grid;
                grid-template-columns: 1fr 1fr 1fr 1fr;
                gap: 8px;
            }
            .payment-method {
                background: var(--bg-card);
                border: 1px solid var(--border-color);
                border-radius: var(--radius-sm);
                padding: 10px 6px;
                text-align: center;
                cursor: pointer;
                transition: var(--transition);
            }
            .payment-method:hover {
                border-color: var(--accent);
                transform: translateY(-2px);
                box-shadow: var(--shadow-sm);
            }
            .payment-method .icon {
                width: 36px;
                height: 36px;
                margin: 0 auto 4px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 16px;
            }
            .payment-method .icon.green { background: rgba(16,185,129,.12); color: #34d399; }
            .payment-method .icon.blue { background: rgba(59,130,246,.12); color: #3B82F6; }
            .payment-method .icon.purple { background: rgba(139,92,246,.12); color: #8B5CF6; }
            .payment-method .icon.gold { background: rgba(255,122,26,.12); color: var(--accent); }
            .payment-method .name {
                font-size: 10px;
                font-weight: 600;
                color: var(--text-primary);
                transition: color var(--transition);
            }
            
            /* ============================================================
               إعدادات FastCrand
               ============================================================ */
            .settings-container {
                display: flex;
                flex-direction: column;
                gap: 12px;
            }
            
            .profile-card {
                background: var(--bg-secondary);
                border: 1px solid var(--border-color);
                border-radius: var(--radius);
                padding: 24px 20px;
                display: flex;
                align-items: center;
                gap: 18px;
                transition: background var(--transition), border-color var(--transition);
            }
            .profile-card .avatar-large {
                width: 68px;
                height: 68px;
                border-radius: 50%;
                background: linear-gradient(135deg, var(--accent), var(--accent-dark));
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 30px;
                font-weight: 900;
                color: #ffffff;
                flex-shrink: 0;
                box-shadow: 0 4px 20px rgba(255,122,26,.2);
            }
            .profile-card .info h4 {
                font-size: 18px;
                font-weight: 800;
                color: var(--text-primary);
                transition: color var(--transition);
            }
            .profile-card .info .sub {
                font-size: 13px;
                color: var(--text-muted);
                transition: color var(--transition);
            }
            .profile-card .info .badge {
                display: inline-block;
                padding: 2px 14px;
                border-radius: 999px;
                font-size: 10px;
                font-weight: 700;
                background: var(--accent-glow);
                color: var(--accent);
                margin-top: 4px;
            }
            
            .settings-group {
                background: var(--bg-secondary);
                border: 1px solid var(--border-color);
                border-radius: var(--radius);
                overflow: hidden;
                transition: background var(--transition), border-color var(--transition);
            }
            .settings-group .group-header {
                padding: 14px 20px;
                background: var(--bg-card);
                border-bottom: 1px solid var(--border-color);
                font-size: 13px;
                font-weight: 700;
                color: var(--text-secondary);
                display: flex;
                align-items: center;
                gap: 10px;
                transition: background var(--transition), border-color var(--transition), color var(--transition);
            }
            .settings-group .group-header i {
                color: var(--accent);
                font-size: 14px;
            }
            
            .settings-item {
                display: flex;
                align-items: center;
                justify-content: space-between;
                padding: 14px 20px;
                border-bottom: 1px solid var(--border-color);
                transition: border-color var(--transition), background var(--transition);
                cursor: pointer;
            }
            .settings-item:last-child {
                border-bottom: none;
            }
            .settings-item:hover {
                background: var(--bg-card-hover);
            }
            
            .settings-item .left {
                display: flex;
                align-items: center;
                gap: 14px;
            }
            .settings-item .left .icon-wrap {
                width: 38px;
                height: 38px;
                border-radius: var(--radius-sm);
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 16px;
                flex-shrink: 0;
                transition: background var(--transition);
            }
            .settings-item .left .icon-wrap.gold {
                background: var(--accent-glow);
                color: var(--accent);
            }
            .settings-item .left .icon-wrap.blue {
                background: rgba(59,130,246,.1);
                color: #3B82F6;
            }
            .settings-item .left .icon-wrap.green {
                background: rgba(16,185,129,.1);
                color: #34d399;
            }
            .settings-item .left .icon-wrap.purple {
                background: rgba(139,92,246,.1);
                color: #8B5CF6;
            }
            
            .settings-item .left .text .title {
                font-size: 14px;
                font-weight: 600;
                color: var(--text-primary);
                transition: color var(--transition);
            }
            .settings-item .left .text .sub {
                font-size: 11px;
                color: var(--text-muted);
                transition: color var(--transition);
            }
            
            .settings-item .right {
                display: flex;
                align-items: center;
                gap: 10px;
            }
            .settings-item .right .chevron {
                color: var(--text-muted);
                font-size: 14px;
                transition: var(--transition);
            }
            .settings-item:hover .right .chevron {
                transform: translateX(-4px);
                color: var(--accent);
            }
            
            .toggle-switch {
                position: relative;
                display: inline-block;
                width: 44px;
                height: 24px;
                flex-shrink: 0;
            }
            .toggle-switch input {
                opacity: 0;
                width: 0;
                height: 0;
            }
            .toggle-switch .slider {
                position: absolute;
                cursor: pointer;
                inset: 0;
                background: var(--bg-card);
                border: 1.5px solid var(--border-color);
                border-radius: 999px;
                transition: var(--transition);
            }
            .toggle-switch .slider:before {
                position: absolute;
                content: '';
                height: 16px;
                width: 16px;
                left: 3px;
                bottom: 2px;
                background: var(--text-muted);
                border-radius: 50%;
                transition: var(--transition);
            }
            .toggle-switch input:checked + .slider {
                background: var(--accent);
                border-color: var(--accent);
            }
            .toggle-switch input:checked + .slider:before {
                transform: translateX(20px);
                background: #ffffff;
            }
            
            .hidden { display: none !important; }
            .text-center { text-align: center; }
            .text-muted { color: var(--text-muted); }
            
            .invoice-item {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 12px 0;
                border-bottom: 1px solid var(--border-color);
                transition: border-color var(--transition);
                cursor: pointer;
            }
            .invoice-item:last-child { border-bottom: none; }
            .invoice-item:hover {
                background: var(--bg-card-hover);
                margin: 0 -10px;
                padding: 12px 10px;
                border-radius: var(--radius-sm);
            }
            .invoice-item .info .number {
                font-weight: 700;
                font-size: 13px;
                color: var(--text-primary);
                transition: color var(--transition);
            }
            .invoice-item .info .date {
                font-size: 10px;
                color: var(--text-muted);
                transition: color var(--transition);
            }
            .invoice-item .amount {
                font-weight: 700;
                color: var(--accent);
            }
            
            .invoice-detail {
                background: var(--bg-secondary);
                border: 1px solid var(--border-color);
                border-radius: var(--radius);
                padding: 24px 20px;
                margin-bottom: 14px;
                transition: background var(--transition), border-color var(--transition);
            }
            .invoice-detail .detail-row {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 10px 0;
                border-bottom: 1px solid var(--border-color);
            }
            .invoice-detail .detail-row:last-child {
                border-bottom: none;
            }
            .invoice-detail .detail-row .label {
                color: var(--text-muted);
                font-size: 13px;
                transition: color var(--transition);
            }
            .invoice-detail .detail-row .value {
                font-weight: 700;
                color: var(--text-primary);
                font-size: 13px;
                transition: color var(--transition);
            }
            .invoice-detail .detail-row .value.amount {
                color: var(--accent);
                font-size: 18px;
            }
            
            .btn-pay {
                width: 100%;
                padding: 14px;
                border: none;
                border-radius: var(--radius-sm);
                background: linear-gradient(135deg, var(--accent), var(--accent-dark));
                color: #ffffff;
                font-weight: 800;
                font-size: 15px;
                font-family: inherit;
                cursor: pointer;
                transition: var(--transition);
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                margin-top: 6px;
                box-shadow: 0 4px 20px rgba(255,122,26,.15);
            }
            .btn-pay:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 30px rgba(255,122,26,.25);
            }
            
            .btn-renew {
                padding: 10px 20px;
                border: none;
                border-radius: var(--radius-sm);
                background: linear-gradient(135deg, var(--accent), var(--accent-dark));
                color: #ffffff;
                font-weight: 700;
                font-size: 14px;
                font-family: inherit;
                cursor: pointer;
                transition: var(--transition);
                display: inline-flex;
                align-items: center;
                justify-content: center;
                gap: 8px;
                box-shadow: 0 4px 16px rgba(255,122,26,.15);
                width: 100%;
                margin-top: 8px;
            }
            .btn-renew:hover {
                transform: translateY(-2px);
                box-shadow: 0 8px 24px rgba(255,122,26,.25);
            }

            /* ============================================================
               بطاقة تعريفية بالمساعد الذكي
               ============================================================ */
            .promo-ai-card {
                background: linear-gradient(135deg, #2b2440, #4a3f6b);
                border: none;
                border-radius: var(--radius);
                padding: 16px 18px;
                display: flex;
                align-items: center;
                gap: 14px;
                margin-bottom: 14px;
                cursor: pointer;
                transition: var(--transition);
                font-family: inherit;
                width: 100%;
                text-align: right;
            }
            .promo-ai-card:hover { transform: translateY(-2px); }
            .promo-ai-card .icon {
                width: 46px;
                height: 46px;
                border-radius: 50%;
                background: rgba(255,255,255,.15);
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 22px;
                flex-shrink: 0;
            }
            .promo-ai-card .text { flex: 1; }
            .promo-ai-card .text h3 { font-size: 14px; font-weight: 800; color: #fff; }
            .promo-ai-card .text p { font-size: 11px; color: rgba(255,255,255,.65); margin-top: 2px; }
            .promo-ai-card .chevron { color: rgba(255,255,255,.5); font-size: 14px; }

            /* ============================================================
               صف أزرار سريعة دائرية (تفاصيل السيرفر)
               ============================================================ */
            .icon-actions-row {
                display: grid;
                grid-template-columns: repeat(4, 1fr);
                gap: 8px;
                margin-bottom: 14px;
            }
            .icon-action {
                background: var(--bg-secondary);
                border: 1px solid var(--border-color);
                border-radius: var(--radius-sm);
                padding: 12px 4px;
                text-align: center;
                cursor: pointer;
                transition: var(--transition);
                font-family: inherit;
                color: var(--text-secondary);
            }
            .icon-action:hover {
                border-color: var(--border-active);
                transform: translateY(-2px);
                box-shadow: var(--shadow-sm);
            }
            .icon-action i { font-size: 17px; color: var(--accent); display: block; margin-bottom: 4px; }
            .icon-action span { font-size: 9px; font-weight: 700; }
            .icon-action.danger i { color: #f87171; }

            /* ============================================================
               تبويبات
               ============================================================ */
            .tab-strip {
                display: flex;
                gap: 6px;
                background: var(--bg-card);
                border-radius: var(--radius-sm);
                padding: 4px;
                margin-bottom: 14px;
            }
            .tab-btn {
                flex: 1;
                border: none;
                background: transparent;
                padding: 10px 6px;
                border-radius: 10px;
                font-family: inherit;
                font-size: 12px;
                font-weight: 700;
                color: var(--text-muted);
                cursor: pointer;
                transition: var(--transition);
            }
            .tab-btn.active {
                background: var(--accent);
                color: #fff;
                box-shadow: 0 4px 14px rgba(255,122,26,.3);
            }

            /* ============================================================
               مقاييس الاستخدام (CPU / RAM)
               ============================================================ */
            .usage-gauges {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 12px;
                margin-bottom: 16px;
            }
            .gauge-card {
                background: var(--bg-secondary);
                border: 1px solid var(--border-color);
                border-radius: var(--radius);
                padding: 18px 10px;
                text-align: center;
            }
            .gauge {
                width: 92px;
                height: 92px;
                border-radius: 50%;
                margin: 0 auto 10px;
                display: flex;
                align-items: center;
                justify-content: center;
                background: conic-gradient(var(--accent) calc(var(--pct) * 1%), var(--bg-card) 0);
                position: relative;
            }
            .gauge::before {
                content: '';
                position: absolute;
                inset: 8px;
                border-radius: 50%;
                background: var(--bg-secondary);
            }
            .gauge .gauge-value {
                position: relative;
                z-index: 1;
                font-size: 18px;
                font-weight: 900;
                color: var(--text-primary);
            }
            .gauge-card .gauge-label {
                font-size: 11px;
                color: var(--text-muted);
                font-weight: 700;
            }

            .usage-bar-block { margin-bottom: 16px; }
            .usage-bar-block:last-child { margin-bottom: 0; }
            .usage-bar-head {
                display: flex;
                justify-content: space-between;
                align-items: baseline;
                font-size: 12px;
                color: var(--text-secondary);
                margin-bottom: 6px;
            }
            .usage-bar-head strong { color: var(--text-primary); font-size: 15px; direction: ltr; unicode-bidi: embed; }
            .usage-bar-track {
                height: 10px;
                background: var(--bg-card);
                border-radius: 999px;
                overflow: hidden;
            }
            .usage-bar-fill {
                height: 100%;
                border-radius: 999px;
                background: linear-gradient(90deg, var(--accent-light), var(--accent));
            }

            /* ============================================================
               معالج طلب VPS
               ============================================================ */
            .radio-circle {
                width: 22px;
                height: 22px;
                border-radius: 50%;
                border: 2px solid var(--border-color);
                flex-shrink: 0;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 11px;
                color: transparent;
                transition: var(--transition);
            }
            .radio-circle.checked {
                border-color: var(--accent);
                background: var(--accent);
                color: #fff;
            }

            .plan-select-item {
                display: flex;
                align-items: center;
                gap: 12px;
                background: var(--bg-secondary);
                border: 2px solid var(--border-color);
                border-radius: var(--radius);
                padding: 14px 16px;
                margin-bottom: 10px;
                cursor: pointer;
                transition: var(--transition);
            }
            .plan-select-item:hover { border-color: var(--border-active); }
            .plan-select-item.selected {
                border-color: var(--accent);
                background: var(--accent-glow);
            }
            .plan-select-item .info { flex: 1; }
            .plan-select-item .info .top-row {
                display: flex;
                justify-content: space-between;
                align-items: center;
                gap: 8px;
            }
            .plan-select-item .info .plan-title { font-weight: 800; font-size: 15px; color: var(--text-primary); }
            .plan-select-item .info .plan-price { font-weight: 900; color: var(--accent); font-size: 15px; white-space: nowrap; }
            .plan-select-item .info .plan-meta { font-size: 11px; color: var(--text-muted); margin-top: 2px; }

            .icon-wrap {
                width: 38px;
                height: 38px;
                border-radius: var(--radius-sm);
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 16px;
                flex-shrink: 0;
            }
            .icon-wrap.gold { background: var(--accent-glow); color: var(--accent); }
            .icon-wrap.blue { background: rgba(59,130,246,.1); color: #3B82F6; }
            .icon-wrap.green { background: rgba(16,185,129,.1); color: #34d399; }
            .icon-wrap.purple { background: rgba(139,92,246,.1); color: #8B5CF6; }

            .pay-option {
                display: flex;
                align-items: center;
                gap: 12px;
                background: var(--bg-secondary);
                border: 1.5px solid var(--border-color);
                border-radius: var(--radius-sm);
                padding: 12px 14px;
                margin-bottom: 10px;
                cursor: pointer;
                transition: var(--transition);
            }
            .pay-option:hover { border-color: var(--border-active); }
            .pay-option.selected { border-color: var(--accent); background: var(--accent-glow); }
            .pay-option .icon-wrap { flex-shrink: 0; }
            .pay-option .title { font-size: 13px; font-weight: 700; color: var(--text-primary); }
            .pay-option .sub { font-size: 11px; color: var(--text-muted); }

            .saved-card {
                background: linear-gradient(135deg, #2b2440, #4a3f6b);
                border-radius: var(--radius);
                padding: 20px;
                color: #fff;
                margin-bottom: 14px;
                position: relative;
                overflow: hidden;
            }
            .saved-card .brand-row {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 24px;
                font-size: 13px;
                font-weight: 700;
                opacity: .85;
            }
            .saved-card .brand-row i { font-size: 20px; }
            .saved-card .number {
                font-size: 17px;
                letter-spacing: 2px;
                font-family: monospace;
                margin-bottom: 16px;
                direction: ltr;
                text-align: right;
            }
            .saved-card .bottom-row {
                display: flex;
                justify-content: space-between;
                font-size: 11px;
                opacity: .75;
                direction: ltr;
            }

            .order-total-row {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding-top: 12px;
                margin-top: 6px;
                border-top: 1.5px dashed var(--border-color);
                font-weight: 900;
                font-size: 16px;
                color: var(--text-primary);
            }
            .order-total-row span.amount { color: var(--accent); }

            .success-screen { text-align: center; padding: 30px 10px 10px; }
            .success-screen .icon-big {
                width: 96px;
                height: 96px;
                border-radius: 50%;
                background: rgba(34,197,94,.12);
                color: #22c55e;
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 46px;
                margin: 0 auto 20px;
            }
            .success-screen h2 { font-size: 19px; font-weight: 900; margin-bottom: 8px; color: var(--text-primary); }
            .success-screen p { font-size: 13px; color: var(--text-muted); line-height: 1.8; margin-bottom: 22px; }

            /* ============================================================
               شريط البحث (سيرفراتي)
               ============================================================ */
            .search-bar {
                display: flex;
                align-items: center;
                gap: 10px;
                background: var(--bg-secondary);
                border: 1.5px solid var(--border-color);
                border-radius: var(--radius-sm);
                padding: 12px 14px;
                margin-bottom: 14px;
                transition: var(--transition);
            }
            .search-bar:focus-within { border-color: var(--accent); }
            .search-bar i { color: var(--text-muted); }
            .search-bar input {
                flex: 1;
                border: none;
                outline: none;
                background: transparent;
                color: var(--text-primary);
                font-family: inherit;
                font-size: 14px;
            }
            .search-bar input::placeholder { color: var(--text-muted); }

            /* ============================================================
               المساعد الذكي
               ============================================================ */
            .ai-screen {
                position: fixed;
                inset: 0;
                background: var(--bg-primary);
                z-index: 300;
                display: flex;
                flex-direction: column;
            }
            .ai-header {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 14px 20px;
                background: var(--bg-secondary);
                border-bottom: 1px solid var(--border-color);
                flex-shrink: 0;
            }
            .ai-header .back-btn {
                width: 36px;
                height: 36px;
                border-radius: 50%;
                border: 1px solid var(--border-color);
                background: var(--bg-card);
                color: var(--text-secondary);
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                font-family: inherit;
                flex-shrink: 0;
            }
            .ai-header .ai-avatar {
                width: 36px;
                height: 36px;
                border-radius: 50%;
                background: linear-gradient(135deg, var(--accent-light), var(--accent));
                display: flex;
                align-items: center;
                justify-content: center;
                font-size: 16px;
                color: #fff;
                flex-shrink: 0;
            }
            .ai-header h2 { font-size: 16px; font-weight: 800; flex: 1; color: var(--text-primary); }
            .ai-body {
                flex: 1;
                overflow-y: auto;
                padding: 18px 20px 90px;
            }
            .ai-greeting-card {
                background: linear-gradient(135deg, #2b2440, #4a3f6b);
                border-radius: var(--radius);
                padding: 22px 18px;
                color: #fff;
                margin-bottom: 16px;
            }
            .ai-greeting-card h3 { font-size: 17px; font-weight: 800; margin-bottom: 4px; }
            .ai-greeting-card p { font-size: 12px; opacity: .75; }
            .ai-quick-grid {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 10px;
                margin-bottom: 16px;
            }
            .ai-quick-card {
                background: var(--bg-secondary);
                border: 1px solid var(--border-color);
                border-radius: var(--radius-sm);
                padding: 16px 10px;
                text-align: center;
                cursor: pointer;
                transition: var(--transition);
                font-family: inherit;
                color: var(--text-primary);
            }
            .ai-quick-card:hover { border-color: var(--border-active); transform: translateY(-2px); }
            .ai-quick-card i { font-size: 22px; color: var(--accent); display: block; margin-bottom: 6px; }
            .ai-quick-card span { font-size: 12px; font-weight: 700; }

            .chat-log { display: flex; flex-direction: column; gap: 10px; margin-bottom: 16px; }
            .chat-bubble {
                max-width: 85%;
                padding: 12px 14px;
                border-radius: 16px;
                font-size: 13px;
                line-height: 1.7;
            }
            .chat-bubble.user {
                align-self: flex-end;
                background: linear-gradient(135deg, var(--accent), var(--accent-dark));
                color: #fff;
                border-bottom-left-radius: 4px;
            }
            .chat-bubble.bot {
                align-self: flex-start;
                background: var(--bg-secondary);
                border: 1px solid var(--border-color);
                color: var(--text-primary);
                border-bottom-right-radius: 4px;
            }

            .ai-input-bar {
                position: fixed;
                bottom: var(--nav-height);
                left: 0;
                right: 0;
                display: flex;
                gap: 8px;
                align-items: center;
                padding: 10px 14px;
                background: var(--bg-secondary);
                border-top: 1px solid var(--border-color);
                z-index: 301;
            }
            .ai-input-bar input {
                flex: 1;
                padding: 12px 16px;
                border-radius: 999px;
                border: 1.5px solid var(--border-color);
                background: var(--bg-card);
                color: var(--text-primary);
                font-family: inherit;
                font-size: 13px;
                outline: none;
            }
            .ai-input-bar button {
                width: 42px;
                height: 42px;
                border-radius: 50%;
                border: none;
                background: linear-gradient(135deg, var(--accent-light), var(--accent));
                color: #fff;
                font-size: 16px;
                display: flex;
                align-items: center;
                justify-content: center;
                cursor: pointer;
                flex-shrink: 0;
            }

            .ai-card {
                background: var(--bg-secondary);
                border: 1px solid var(--border-color);
                border-radius: var(--radius);
                padding: 18px;
                margin-bottom: 14px;
            }
            .ai-card h4 {
                font-size: 14px;
                font-weight: 800;
                margin-bottom: 10px;
                display: flex;
                align-items: center;
                gap: 8px;
                color: var(--text-primary);
            }
            .ai-card h4 i { color: var(--accent); }
            .ai-card ol {
                padding-inline-start: 20px;
                font-size: 13px;
                color: var(--text-secondary);
                line-height: 2;
            }
            .ai-card code {
                display: block;
                background: var(--bg-card);
                border: 1px solid var(--border-color);
                border-radius: 10px;
                padding: 10px 14px;
                font-family: monospace;
                direction: ltr;
                text-align: left;
                color: var(--accent);
                margin: 8px 0;
                font-size: 13px;
            }

            #aiBottomNav { z-index: 301; }

            @media (max-width: 480px) {
                .hosting-stats-grid { grid-template-columns: repeat(2, 1fr); }
                .quick-grid { grid-template-columns: repeat(2, 1fr); }
                .vps-grid { grid-template-columns: 1fr; }
                .payment-methods-grid { grid-template-columns: 1fr 1fr; }
                .container { padding: 12px 14px; }
                .card { padding: 16px 12px; }
                .header { padding: 10px 14px; }
                .header .brand { font-size: 15px; }
                .profile-card { padding: 18px 14px; flex-direction: column; text-align: center; }
                .profile-card .avatar-large { width: 56px; height: 56px; font-size: 24px; }
                .settings-item { padding: 12px 14px; flex-wrap: wrap; gap: 8px; }
                .logout-sheet { padding: 24px 16px 20px; }
                .logout-sheet .actions { flex-direction: column; }
                .hosting-detail .detail-row { flex-direction: column; align-items: flex-start; gap: 4px; }
                .hosting-detail .detail-row .value { text-align: right; width: 100%; }
            }
        </style>
    </head>
    <body>
        <!-- ============================================================
        بطاقة تأكيد تسجيل الخروج - FastCrand
        ============================================================ -->
        <div class="logout-overlay" id="logoutOverlay">
            <div class="logout-sheet">
                <div class="icon-box">
                    <i class="fas fa-sign-out-alt"></i>
                </div>
                <h3>تسجيل الخروج</h3>
                <p>هل أنت متأكد من رغبتك في تسجيل الخروج من حسابك؟</p>
                <div class="actions">
                    <button class="btn-cancel" onclick="closeLogoutSheet()">إلغاء</button>
                    <button class="btn-confirm" onclick="confirmLogout()">تأكيد الخروج</button>
                </div>
            </div>
        </div>
        
        <!-- ============================================================
        الهيدر
        ============================================================ -->
        <header class="header">
            <div class="brand">
                <div class="logo"><i class="fas fa-server"></i></div>
                <span>خوادم VPS</span>
            </div>
            <div class="header-actions">
                <button class="header-theme-toggle" id="headerThemeToggle" onclick="toggleTheme()">
                    <i class="fas fa-moon" id="headerThemeIcon"></i>
                </button>
            </div>
        </header>
        
        <!-- ============================================================
        المحتوى
        ============================================================ -->
        <div class="container" id="appContent">
            <!-- ============================================================
            القسم: الرئيسية - استضافاتي النشطة
            ============================================================ -->
            <div id="section-home" class="section-content">
                <div class="card" style="background:linear-gradient(135deg, #ffa64d, #ff7a1a, #f26a00);border:none;color:#ffffff">
                    <div style="display:flex;align-items:center;gap:14px">
                        <div style="width:52px;height:52px;border-radius:50%;background:rgba(255,255,255,.22);display:flex;align-items:center;justify-content:center;font-size:24px">🚀</div>
                        <div>
                            <h3 style="font-size:18px;font-weight:900">مرحباً بك في منصة VPS</h3>
                            <div style="font-size:12px;opacity:.8">استمتع بخدماتنا المتميزة</div>
                        </div>
                    </div>
                    <div style="margin-top:10px;font-size:13px;opacity:.7;display:flex;align-items:center;gap:8px">
                        <i class="fas fa-calendar-alt"></i>
                        <?php echo date('l, d F Y', strtotime('now')); ?>
                    </div>
                </div>
                
                <!-- إحصائيات جديدة -->
                <div class="hosting-stats-grid">
                    <div class="stat-box"><div class="num"><?php echo count($hosting); ?></div><div class="label">استضافات نشطة</div></div>
                    <div class="stat-box"><div class="num"><?php echo count(array_filter($hosting, function($h) { return $h['status'] === 'active'; })); ?></div><div class="label">مفعلة</div></div>
                    <div class="stat-box"><div class="num"><?php echo count(array_filter($hosting, function($h) { return $h['status'] === 'expired'; })); ?></div><div class="label">منتهية</div></div>
                    <div class="stat-box"><div class="num"><?php echo count($invoices); ?></div><div class="label">فواتير</div></div>
                </div>
                
                <div class="quick-grid">
                    <button class="quick-btn" onclick="showSection('servers')"><i class="fas fa-server"></i>سيرفراتي</button>
                    <button class="quick-btn" onclick="showSection('invoices')"><i class="fas fa-receipt"></i>فواتير</button>
                    <button class="quick-btn" onclick="showSection('orders')"><i class="fas fa-list"></i>طلباتي</button>
                    <button class="quick-btn" onclick="showSection('settings')"><i class="fas fa-gear"></i>إعدادات</button>
                </div>

                <!-- المساعد الذكي -->
                <button type="button" class="promo-ai-card" onclick="enterAI()">
                    <div class="icon">🤖</div>
                    <div class="text">
                        <h3>مساعدك الذكي</h3>
                        <p>اسأل، اطلب شرح أمر، أو شخّص مشكلة بسيرفرك</p>
                    </div>
                    <i class="fas fa-chevron-left chevron"></i>
                </button>

                <!-- قائمة الاستضافات -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-server"></i> استضافاتي النشطة</h3>
                        <span style="font-size:11px;color:var(--text-muted);cursor:pointer" onclick="showSection('servers')">عرض الكل</span>
                    </div>
                    <?php foreach ($hosting as $h): ?>
                    <div class="hosting-item" onclick="showHostingDetail(<?php echo $h['id']; ?>)">
                        <div class="info">
                            <div class="name"><?php echo $h['name']; ?></div>
                            <div class="sub"><?php echo $h['plan']; ?> · <?php echo $h['ip']; ?></div>
                        </div>
                        <div class="status-badge">
                            <span class="pill <?php echo $h['status'] === 'active' ? 'pill-green' : 'pill-red'; ?>">
                                <?php echo $h['status'] === 'active' ? '✅ مفعل' : '❌ منتهي'; ?>
                            </span>
                            <div style="font-size:9px;color:var(--text-muted);margin-top:2px">
                                ينتهي: <?php echo $h['expiry_date']; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- آخر الفواتير -->
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-receipt"></i> آخر الفواتير</h3>
                        <span style="font-size:11px;color:var(--text-muted);cursor:pointer" onclick="showSection('invoices')">عرض الكل</span>
                    </div>
                    <?php foreach (array_slice($invoices, 0, 3) as $inv): ?>
                    <div class="invoice-item" onclick="showInvoiceDetail(<?php echo $inv['id']; ?>)">
                        <div class="info">
                            <div class="number"><?php echo $inv['number']; ?></div>
                            <div class="date">استحقاق: <?php echo $inv['due_date']; ?></div>
                        </div>
                        <div style="text-align:left">
                            <div class="amount">$<?php echo $inv['amount']; ?></div>
                            <span class="pill <?php echo $inv['status'] === 'paid' ? 'pill-green' : ($inv['status'] === 'pending' ? 'pill-amber' : 'pill-red'); ?>">
                                <?php echo $inv['status'] === 'paid' ? '✅ مدفوع' : ($inv['status'] === 'pending' ? '⏳ معلق' : '❌ متأخر'); ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if ($is_admin): ?>
                <div class="card" style="border-color:rgba(255,122,26,.15)">
                    <div class="card-header">
                        <h3><i class="fas fa-shield-halved"></i> لوحة الأدمن</h3>
                        <span class="admin-badge">👑 أدمن</span>
                    </div>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                        <button class="btn-outline" onclick="alert('📊 عرض جميع المستخدمين')"><i class="fas fa-users"></i> المستخدمين</button>
                        <button class="btn-outline" onclick="alert('📋 عرض جميع الطلبات')"><i class="fas fa-list"></i> كل الطلبات</button>
                        <button class="btn-outline" onclick="alert('📈 تقارير')"><i class="fas fa-chart-line"></i> تقارير</button>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- ============================================================
            القسم: سيرفراتي
            ============================================================ -->
            <div id="section-servers" class="section-content hidden">
                <div class="search-bar">
                    <i class="fas fa-magnifying-glass"></i>
                    <input type="text" id="serverSearchInput" placeholder="ابحث عن سيرفر..." oninput="filterServers()">
                </div>
                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-server"></i> سيرفراتي</h3>
                        <span style="font-size:11px;color:var(--text-muted)"><?php echo count($hosting); ?> سيرفر</span>
                    </div>
                    <div id="serversListContent">
                        <?php foreach ($hosting as $h): ?>
                        <div class="hosting-item server-list-item" data-name="<?php echo htmlspecialchars(mb_strtolower($h['name'])); ?>" onclick="showHostingDetail(<?php echo $h['id']; ?>)">
                            <div class="info">
                                <div class="name"><?php echo $h['name']; ?></div>
                                <div class="sub"><?php echo $h['plan']; ?> · <?php echo $h['ip']; ?></div>
                            </div>
                            <div class="status-badge">
                                <span class="pill <?php echo $h['status'] === 'active' ? 'pill-green' : 'pill-red'; ?>">
                                    <?php echo $h['status'] === 'active' ? '✅ قيد التشغيل' : '❌ منتهي'; ?>
                                </span>
                                <div style="font-size:9px;color:var(--text-muted);margin-top:2px">
                                    ينتهي: <?php echo $h['expiry_date']; ?>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <div id="noServerResults" class="text-muted text-center hidden" style="padding:24px 0">لا توجد نتائج مطابقة 🔍</div>
                    </div>
                </div>
            </div>

            <!-- ============================================================
            القسم: تفاصيل الاستضافة
            ============================================================ -->
            <div id="section-hosting-detail" class="section-content hidden">
                <div class="card-header" style="margin-bottom:14px">
                    <h3><i class="fas fa-server"></i> تفاصيل السيرفر</h3>
                    <button class="btn-back" onclick="hideHostingDetail()">رجوع</button>
                </div>

                <div class="icon-actions-row">
                    <button class="icon-action" onclick="alert('💾 جاري إنشاء نسخة احتياطية...')"><i class="fas fa-clock-rotate-left"></i><span>نسخ احتياطي</span></button>
                    <button class="icon-action" onclick="alert('🔄 جاري إعادة تشغيل السيرفر...')"><i class="fas fa-power-off"></i><span>إعادة تشغيل</span></button>
                    <button class="icon-action danger" onclick="if(confirm('هل أنت متأكد من حذف هذا السيرفر؟')) alert('🗑️ تم إرسال طلب الحذف')"><i class="fas fa-trash"></i><span>حذف</span></button>
                    <button class="icon-action" onclick="alert('⚙️ المزيد من الخيارات قريباً')"><i class="fas fa-ellipsis"></i><span>مزيد</span></button>
                </div>

                <div class="tab-strip">
                    <button class="tab-btn active" id="tabBtnUsage" onclick="switchDetailTab('usage')">الاستخدام</button>
                    <button class="tab-btn" id="tabBtnInfo" onclick="switchDetailTab('info')">معلومات السيرفر</button>
                </div>

                <div class="tab-panel" id="tabPanelUsage">
                    <div class="usage-gauges" id="usageGaugesContent"></div>
                    <div class="card">
                        <div class="usage-bar-block">
                            <div class="usage-bar-head"><span>الباندويث المستخدم</span><strong id="usageBandwidthLabel"></strong></div>
                            <div class="usage-bar-track"><div class="usage-bar-fill" id="usageBandwidthFill"></div></div>
                        </div>
                        <div class="usage-bar-block">
                            <div class="usage-bar-head"><span>التخزين المستخدم</span><strong id="usageStorageLabel"></strong></div>
                            <div class="usage-bar-track"><div class="usage-bar-fill" id="usageStorageFill"></div></div>
                        </div>
                    </div>
                </div>

                <div class="tab-panel hidden" id="tabPanelInfo">
                    <div class="hosting-detail" id="hostingDetailContent">
                        <!-- يتم تعبئتها بواسطة JavaScript -->
                    </div>
                </div>
            </div>
            
            <!-- ============================================================
            القسم: خوادم VPS
            ============================================================ -->
            <div id="section-vps" class="section-content hidden">
                <!-- خطوة 1: اختيار الباقة -->
                <div class="wizard-step" id="vpsStepPlan">
                    <div class="card" style="background:linear-gradient(135deg, #ffa64d, #ff7a1a, #f26a00);border:none;color:#ffffff;text-align:center">
                        <h3 style="font-size:20px;font-weight:900">🚀 اختر الباقة المناسبة</h3>
                        <div style="font-size:13px;opacity:.8;margin-top:4px">اختر الباقة التي تناسب احتياجاتك</div>
                    </div>
                    <div id="planListContent"></div>
                    <button class="btn-gold" id="planContinueBtn" onclick="wizardGoTo('details')" disabled>متابعة</button>
                </div>

                <!-- خطوة 2: تفاصيل الباقة -->
                <div class="wizard-step hidden" id="vpsStepDetails">
                    <div class="card-header" style="margin-bottom:14px">
                        <h3><i class="fas fa-server"></i> تفاصيل الباقة</h3>
                        <button class="btn-back" onclick="wizardGoTo('plan')">رجوع</button>
                    </div>
                    <div class="card" style="text-align:center">
                        <div style="font-size:42px;margin-bottom:4px" id="planDetailsIcon"></div>
                        <h3 id="planDetailsName" style="font-size:18px;font-weight:900"></h3>
                        <div class="price" id="planDetailsPrice" style="font-size:26px;margin:6px 0"></div>
                    </div>
                    <div class="hosting-detail" id="planDetailsSpecs"></div>
                    <button class="btn-gold" onclick="wizardGoTo('summary')" style="margin-top:14px">متابعة الطلب</button>
                </div>

                <!-- خطوة 3: ملخص الطلب -->
                <div class="wizard-step hidden" id="vpsStepSummary">
                    <div class="card-header" style="margin-bottom:14px">
                        <h3><i class="fas fa-clipboard-list"></i> ملخص الطلب</h3>
                        <button class="btn-back" onclick="wizardGoTo('details')">رجوع</button>
                    </div>
                    <div class="hosting-detail" id="orderSummaryContent"></div>
                    <button class="btn-gold" onclick="wizardGoTo('payment')" style="margin-top:14px">متابعة للدفع</button>
                </div>

                <!-- خطوة 4: طريقة الدفع -->
                <div class="wizard-step hidden" id="vpsStepPayment">
                    <div class="card-header" style="margin-bottom:14px">
                        <h3><i class="fas fa-credit-card"></i> طريقة الدفع</h3>
                        <button class="btn-back" onclick="wizardGoTo('summary')">رجوع</button>
                    </div>
                    <div class="saved-card">
                        <div class="brand-row"><span>VISA</span><i class="fab fa-cc-visa"></i></div>
                        <div class="number">•••• •••• •••• 4242</div>
                        <div class="bottom-row"><span><?php echo htmlspecialchars($user_name); ?></span><span>12/28</span></div>
                    </div>
                    <div id="payOptionsContent"></div>
                    <div class="order-total-row">
                        <span>الإجمالي</span>
                        <span class="amount" id="paymentTotalAmount"></span>
                    </div>
                    <button class="btn-gold" onclick="wizardPay()" style="margin-top:14px"><i class="fas fa-lock"></i> ادفع الآن</button>
                </div>

                <!-- خطوة 5: نجاح الطلب -->
                <div class="wizard-step hidden" id="vpsStepSuccess">
                    <div class="success-screen">
                        <div class="icon-big"><i class="fas fa-check"></i></div>
                        <h2>تم إنشاء الطلب بنجاح!</h2>
                        <p>جاري تجهيز سيرفرك الآن، ستصلك تفاصيل الدخول عبر البريد الإلكتروني خلال دقائق.</p>
                    </div>
                    <button class="btn-gold" onclick="showSection('servers')"><i class="fas fa-server"></i> الذهاب إلى سيرفراتي</button>
                </div>
            </div>
            
            <!-- ============================================================
            القسم: فواتير
            ============================================================ -->
            <div id="section-invoices" class="section-content hidden">
                <!-- الرصيد -->
                <div class="card" style="background:linear-gradient(135deg, #ffa64d, #ff7a1a, #f26a00);border:none;color:#ffffff;text-align:center">
                    <div style="font-size:14px;opacity:.8">الرصيد الحالي</div>
                    <div style="font-size:36px;font-weight:900">$<?php echo number_format($balance, 2); ?></div>
                    <button class="btn-gold" style="padding:10px;font-size:14px;margin-top:8px;width:auto;display:inline-flex" onclick="showAddBalance()">
                        <i class="fas fa-plus-circle"></i> إضافة رصيد
                    </button>
                </div>
                
                <!-- طرق الدفع -->
                <div id="addBalanceSection" class="hidden">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-credit-card"></i> طرق الدفع</h3>
                            <button class="btn-back" onclick="hideAddBalance()">رجوع</button>
                        </div>
                        <div class="payment-methods-grid">
                            <?php foreach ($payment_methods as $pm): ?>
                            <div class="payment-method" onclick="showPaymentPage('<?php echo $pm['id']; ?>', '<?php echo $pm['name']; ?>')">
                                <div class="icon <?php echo $pm['color']; ?>">
                                    <i class="fas <?php echo $pm['icon']; ?>"></i>
                                </div>
                                <div class="name"><?php echo $pm['name']; ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <!-- صفحة الدفع -->
                <div id="paymentPage" class="hidden">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-credit-card"></i> <span id="paymentMethodName">الدفع</span></h3>
                            <button class="btn-back" onclick="hidePaymentPage()">رجوع</button>
                        </div>
                        <div class="input-group" style="margin-bottom:12px">
                            <label style="display:block;font-size:13px;color:var(--text-muted);margin-bottom:4px">المبلغ ($)</label>
                            <input type="number" id="paymentAmount" placeholder="أدخل المبلغ" style="width:100%;padding:12px 14px;border-radius:var(--radius-sm);border:1.5px solid var(--border-color);background:var(--bg-card);color:var(--text-primary);font-size:15px;font-family:inherit;outline:none">
                        </div>
                        <button class="btn-pay" onclick="processPayment()">
                            <i class="fas fa-check"></i> تأكيد الدفع
                        </button>
                        <div id="paymentStatus" class="text-center" style="margin-top:10px;font-size:13px;color:var(--text-muted)"></div>
                    </div>
                </div>
                
                <!-- قائمة الفواتير -->
                <div id="invoicesList">
                    <div class="card">
                        <div class="card-header">
                            <h3><i class="fas fa-receipt"></i> جميع الفواتير</h3>
                        </div>
                        <?php foreach ($invoices as $inv): ?>
                        <div class="invoice-item" onclick="showInvoiceDetail(<?php echo $inv['id']; ?>)">
                            <div class="info">
                                <div class="number"><?php echo $inv['number']; ?></div>
                                <div class="date">استحقاق: <?php echo $inv['due_date']; ?></div>
                            </div>
                            <div style="text-align:left">
                                <div class="amount">$<?php echo $inv['amount']; ?></div>
                                <span class="pill <?php echo $inv['status'] === 'paid' ? 'pill-green' : ($inv['status'] === 'pending' ? 'pill-amber' : 'pill-red'); ?>">
                                    <?php echo $inv['status'] === 'paid' ? '✅ مدفوع' : ($inv['status'] === 'pending' ? '⏳ معلق' : '❌ متأخر'); ?>
                                </span>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                
                <!-- تفاصيل الفاتورة -->
                <div id="invoiceDetail" class="hidden">
                    <div class="card-header" style="margin-bottom:14px">
                        <h3><i class="fas fa-file-invoice"></i> تفاصيل الفاتورة</h3>
                        <button class="btn-back" onclick="hideInvoiceDetail()">رجوع</button>
                    </div>
                    <div class="invoice-detail" id="invoiceDetailContent">
                        <!-- يتم تعبئتها بواسطة JavaScript -->
                    </div>
                </div>
            </div>
            
            <!-- ============================================================
            القسم: طلباتي
            ============================================================ -->
            <div id="section-orders" class="section-content hidden">
                <div class="card" style="background:linear-gradient(135deg, #ffa64d, #ff7a1a, #f26a00);border:none;color:#ffffff;text-align:center">
                    <h3 style="font-size:18px;font-weight:900">📋 طلباتي</h3>
                    <div style="font-size:13px;opacity:.8">إجمالي الطلبات: <span id="ordersCount">0</span></div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h3><i class="fas fa-list"></i> جميع الطلبات</h3>
                        <button class="btn-outline btn-small" onclick="showSection('vps')"><i class="fas fa-plus"></i> طلب جديد</button>
                    </div>
                    <div id="ordersListContent"></div>
                    <div class="text-muted text-center" id="ordersEmptyState" style="padding:30px 0">
                        📭 ما عندك طلبات VPS بعد<br>
                        <span style="color:var(--accent);cursor:pointer" onclick="showSection('vps')">اطلب خادمك الآن</span>
                    </div>
                </div>
            </div>
            
            <!-- ============================================================
            القسم: إعدادات
            ============================================================ -->
            <div id="section-settings" class="section-content hidden">
                <div class="settings-container">
                    <div class="profile-card">
                        <div class="avatar-large"><?php echo mb_substr($user_name, 0, 1); ?></div>
                        <div class="info">
                            <h4><?php echo htmlspecialchars($user_name); ?></h4>
                            <div class="sub"><?php echo htmlspecialchars($user['phone'] ?? ''); ?></div>
                            <span class="badge"><?php echo $is_admin ? '👑 أدمن' : '👤 مستخدم'; ?></span>
                        </div>
                    </div>
                    
                    <div class="settings-group">
                        <div class="group-header">
                            <i class="fas fa-sliders-h"></i> الإعدادات العامة
                        </div>
                        
                        <div class="settings-item" onclick="toggleTheme()">
                            <div class="left">
                                <div class="icon-wrap gold"><i class="fas fa-moon"></i></div>
                                <div class="text">
                                    <div class="title">المظهر الداكن</div>
                                    <div class="sub">الوضع الليلي للتطبيق</div>
                                </div>
                            </div>
                            <div class="right">
                                <label class="toggle-switch">
                                    <input type="checkbox" id="darkModeToggle" checked onchange="toggleTheme()">
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="settings-item">
                            <div class="left">
                                <div class="icon-wrap blue"><i class="fas fa-bell"></i></div>
                                <div class="text">
                                    <div class="title">الإشعارات</div>
                                    <div class="sub">إشعارات فورية</div>
                                </div>
                            </div>
                            <div class="right">
                                <span style="color:var(--accent);font-weight:600;font-size:12px">مفعلة ✅</span>
                            </div>
                        </div>
                        
                        <div class="settings-item">
                            <div class="left">
                                <div class="icon-wrap green"><i class="fas fa-language"></i></div>
                                <div class="text">
                                    <div class="title">اللغة</div>
                                    <div class="sub">اختيار لغة التطبيق</div>
                                </div>
                            </div>
                            <div class="right">
                                <span style="color:var(--text-secondary);font-weight:600;font-size:12px">العربية</span>
                                <i class="fas fa-chevron-left chevron"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="settings-group">
                        <div class="group-header">
                            <i class="fas fa-headset"></i> الدعم والتواصل
                        </div>
                        
                        <div class="settings-item" onclick="window.open('https://wa.me/9647701234567', '_blank')">
                            <div class="left">
                                <div class="icon-wrap green"><i class="fab fa-whatsapp"></i></div>
                                <div class="text">
                                    <div class="title">واتساب الدعم الفني</div>
                                    <div class="sub">تواصل مباشر مع فريق الدعم</div>
                                </div>
                            </div>
                            <div class="right">
                                <i class="fas fa-chevron-left chevron"></i>
                            </div>
                        </div>
                        
                        <div class="settings-item">
                            <div class="left">
                                <div class="icon-wrap purple"><i class="fas fa-envelope"></i></div>
                                <div class="text">
                                    <div class="title">البريد الإلكتروني</div>
                                    <div class="sub">support@vps-platform.com</div>
                                </div>
                            </div>
                            <div class="right">
                                <i class="fas fa-chevron-left chevron"></i>
                            </div>
                        </div>
                    </div>
                    
                    <div class="settings-group">
                        <div class="group-header">
                            <i class="fas fa-info-circle"></i> معلومات التطبيق
                        </div>
                        
                        <div class="settings-item">
                            <div class="left">
                                <div class="icon-wrap gold"><i class="fas fa-code"></i></div>
                                <div class="text">
                                    <div class="title">الإصدار</div>
                                    <div class="sub">آخر تحديث</div>
                                </div>
                            </div>
                            <div class="right">
                                <span style="color:var(--text-secondary);font-weight:600;font-size:12px">v2.0.0</span>
                            </div>
                        </div>
                        
                        <div class="settings-item">
                            <div class="left">
                                <div class="icon-wrap blue"><i class="fas fa-shield-alt"></i></div>
                                <div class="text">
                                    <div class="title">سياسة الخصوصية</div>
                                    <div class="sub">اطلع على سياسة الخصوصية</div>
                                </div>
                            </div>
                            <div class="right">
                                <i class="fas fa-chevron-left chevron"></i>
                            </div>
                        </div>
                        
                        <div class="settings-item">
                            <div class="left">
                                <div class="icon-wrap purple"><i class="fas fa-file-contract"></i></div>
                                <div class="text">
                                    <div class="title">الشروط والأحكام</div>
                                    <div class="sub">اطلع على شروط الاستخدام</div>
                                </div>
                            </div>
                            <div class="right">
                                <i class="fas fa-chevron-left chevron"></i>
                            </div>
                        </div>
                    </div>
                    
                    <button class="btn-gold" onclick="showLogoutSheet()" style="margin-top:4px">
                        <i class="fas fa-sign-out-alt"></i> تسجيل الخروج
                    </button>
                    
                    <div class="text-center text-muted" style="font-size:11px;padding:12px 0">
                        <i class="fas fa-code"></i> منصة خوادم VPS v2.0 · جميع الحقوق محفوظة
                    </div>
                </div>
            </div>
        </div>
        
        <!-- ============================================================
        القائمة السفلية
        ============================================================ -->
        <nav class="bottom-nav" id="mainBottomNav">
            <button class="nav-item active" data-section="home" onclick="showSection('home')">
                <i class="fas fa-house"></i>
                <span>الرئيسية</span>
            </button>
            <button class="nav-item" data-section="servers" onclick="showSection('servers')">
                <i class="fas fa-server"></i>
                <span>سيرفراتي</span>
            </button>
            <button class="nav-item nav-item-fab" data-section="vps" onclick="showSection('vps')">
                <span class="fab-icon"><i class="fas fa-plus"></i></span>
                <span>طلب جديد</span>
            </button>
            <button class="nav-item" data-section="invoices" onclick="showSection('invoices')">
                <i class="fas fa-receipt"></i>
                <span>الفواتير</span>
            </button>
            <button class="nav-item" data-section="settings" onclick="showSection('settings')">
                <i class="fas fa-user"></i>
                <span>الحساب</span>
            </button>
        </nav>

        <!-- ============================================================
        المساعد الذكي - تطبيق مصغر منفصل
        ============================================================ -->
        <div class="ai-screen hidden" id="section-ai">
            <div class="ai-header">
                <button class="back-btn" onclick="exitAI()"><i class="fas fa-arrow-right"></i></button>
                <div class="ai-avatar"><i class="fas fa-robot"></i></div>
                <h2 id="aiHeaderTitle">المساعد الذكي</h2>
            </div>

            <div class="ai-body" id="aiBody">
                <!-- الرئيسية -->
                <div id="aiViewHome" class="ai-view">
                    <div class="ai-greeting-card">
                        <h3>مرحباً <?php echo htmlspecialchars($user_name); ?>! 👋</h3>
                        <p>كيف يمكنني مساعدتك اليوم؟</p>
                    </div>
                    <div class="ai-quick-grid">
                        <button class="ai-quick-card" onclick="showAiView('solve')"><i class="fas fa-wrench"></i><span>حل مشكلة</span></button>
                        <button class="ai-quick-card" onclick="showAiView('explain')"><i class="fas fa-terminal"></i><span>شرح أمر</span></button>
                        <button class="ai-quick-card" onclick="showAiView('tools')"><i class="fas fa-wand-magic-sparkles"></i><span>نصائح التحسين</span></button>
                        <button class="ai-quick-card" onclick="showAiView('tools')"><i class="fas fa-lightbulb"></i><span>اقتراحات ذكية</span></button>
                    </div>
                    <div class="chat-log" id="aiHomeChatLog"></div>
                </div>

                <!-- شرح أمر -->
                <div id="aiViewExplain" class="ai-view hidden">
                    <div class="chat-log" id="aiExplainChatLog"></div>
                </div>

                <!-- حل مشكلة -->
                <div id="aiViewSolve" class="ai-view hidden">
                    <div class="chat-log" id="aiSolveChatLog"></div>
                </div>

                <!-- الأدوات الذكية -->
                <div id="aiViewTools" class="ai-view hidden">
                    <div class="settings-group">
                        <div class="group-header"><i class="fas fa-wand-magic-sparkles"></i> الأدوات الذكية</div>
                        <?php foreach ($ai_tools as $tool): ?>
                        <div class="settings-item" onclick="alert('⚙️ جاري تشغيل: <?php echo htmlspecialchars($tool['title']); ?>')">
                            <div class="left">
                                <div class="icon-wrap <?php echo $tool['color']; ?>"><i class="fas <?php echo $tool['icon']; ?>"></i></div>
                                <div class="text">
                                    <div class="title"><?php echo htmlspecialchars($tool['title']); ?></div>
                                    <div class="sub"><?php echo htmlspecialchars($tool['sub']); ?></div>
                                </div>
                            </div>
                            <div class="right"><i class="fas fa-chevron-left chevron"></i></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- المحادثات -->
                <div id="aiViewConversations" class="ai-view hidden">
                    <div class="card">
                        <div class="card-header"><h3><i class="fas fa-comments"></i> المحادثات السابقة</h3></div>
                        <div id="aiConversationsList">
                            <?php foreach ($ai_conversations as $c): ?>
                            <div class="invoice-item" data-title="<?php echo htmlspecialchars($c['title'], ENT_QUOTES); ?>" onclick="openConversation(this.dataset.title)">
                                <div class="info">
                                    <div class="number"><?php echo htmlspecialchars($c['title']); ?></div>
                                    <div class="date"><?php echo htmlspecialchars($c['preview']); ?></div>
                                </div>
                                <div style="text-align:left;font-size:10px;color:var(--text-muted);white-space:nowrap"><?php echo htmlspecialchars($c['time']); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- إعدادات المساعد -->
                <div id="aiViewSettings" class="ai-view hidden">
                    <div class="settings-group">
                        <div class="group-header"><i class="fas fa-sliders-h"></i> إعدادات المساعد</div>
                        <div class="settings-item">
                            <div class="left">
                                <div class="icon-wrap gold"><i class="fas fa-brain"></i></div>
                                <div class="text">
                                    <div class="title">الوضع الذكي</div>
                                    <div class="sub">ردود آلية مخصصة لحالتك</div>
                                </div>
                            </div>
                            <div class="right">
                                <label class="toggle-switch">
                                    <input type="checkbox" checked>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                        <div class="settings-item">
                            <div class="left">
                                <div class="icon-wrap blue"><i class="fas fa-comment-dots"></i></div>
                                <div class="text">
                                    <div class="title">الردود المقترحة</div>
                                    <div class="sub">عرض اقتراحات أثناء المحادثة</div>
                                </div>
                            </div>
                            <div class="right">
                                <label class="toggle-switch">
                                    <input type="checkbox" checked>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                        <div class="settings-item">
                            <div class="left">
                                <div class="icon-wrap green"><i class="fas fa-language"></i></div>
                                <div class="text">
                                    <div class="title">لغة المساعد</div>
                                    <div class="sub">لغة الردود والشرح</div>
                                </div>
                            </div>
                            <div class="right">
                                <span style="color:var(--text-secondary);font-weight:600;font-size:12px">العربية</span>
                            </div>
                        </div>
                    </div>
                    <div class="settings-group">
                        <div class="group-header"><i class="fas fa-database"></i> حفظ المحادثات</div>
                        <div class="settings-item">
                            <div class="left">
                                <div class="icon-wrap purple"><i class="fas fa-floppy-disk"></i></div>
                                <div class="text">
                                    <div class="title">حفظ سجل المحادثات</div>
                                    <div class="sub">للرجوع لها لاحقاً</div>
                                </div>
                            </div>
                            <div class="right">
                                <label class="toggle-switch">
                                    <input type="checkbox" checked>
                                    <span class="slider"></span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <button class="btn-outline" style="width:100%;color:#f87171;border-color:rgba(239,68,68,.3)" onclick="clearAiConversations()">
                        <i class="fas fa-trash"></i> مسح جميع المحادثات
                    </button>
                </div>
            </div>

            <div class="ai-input-bar" id="aiInputBar">
                <input type="text" id="aiInputField" placeholder="اكتب سؤالك هنا..." onkeydown="if(event.key==='Enter') sendAiMessage()">
                <button onclick="sendAiMessage()"><i class="fas fa-paper-plane"></i></button>
            </div>

            <nav class="bottom-nav hidden" id="aiBottomNav">
                <button class="nav-item active" data-ai-view="home" onclick="showAiView('home')">
                    <i class="fas fa-house"></i>
                    <span>الرئيسية</span>
                </button>
                <button class="nav-item" data-ai-view="conversations" onclick="showAiView('conversations')">
                    <i class="fas fa-comments"></i>
                    <span>المحادثات</span>
                </button>
                <button class="nav-item nav-item-fab" onclick="showAiView('home')">
                    <span class="fab-icon"><i class="fas fa-plus"></i></span>
                    <span>محادثة جديدة</span>
                </button>
                <button class="nav-item" data-ai-view="tools" onclick="showAiView('tools')">
                    <i class="fas fa-wand-magic-sparkles"></i>
                    <span>الأدوات الذكية</span>
                </button>
                <button class="nav-item" data-ai-view="settings" onclick="showAiView('settings')">
                    <i class="fas fa-gear"></i>
                    <span>إعدادات</span>
                </button>
            </nav>
        </div>

        <script>
            // ============================================================
            // بيانات PHP
            // ============================================================
            const HOSTING = <?php echo json_encode($hosting); ?>;
            const INVOICES = <?php echo json_encode($invoices); ?>;
            const USER_BALANCE = <?php echo $balance; ?>;
            const VPS_PLANS = <?php echo json_encode($vps_plans); ?>;
            const AI_CONVERSATIONS = <?php echo json_encode($ai_conversations); ?>;
            const USER_NAME = <?php echo json_encode($user_name); ?>;

            let nextHostingId = HOSTING.reduce((max, h) => Math.max(max, h.id), 0) + 1;
            let ORDERS = [];
            let detailReturnSection = 'home';
            
            // ============================================================
            // تبديل المظهر
            // ============================================================
            function toggleTheme() {
                const html = document.documentElement;
                const currentTheme = html.getAttribute('data-theme') || 'light';
                const newTheme = currentTheme === 'dark' ? 'light' : 'dark';
                
                html.setAttribute('data-theme', newTheme);
                localStorage.setItem('theme', newTheme);
                
                const icons = [
                    document.getElementById('headerThemeIcon'),
                    document.getElementById('themeIcon')
                ];
                icons.forEach(icon => {
                    if (icon) {
                        icon.className = newTheme === 'dark' ? 'fas fa-moon' : 'fas fa-sun';
                    }
                });
                
                const toggle = document.getElementById('darkModeToggle');
                if (toggle) {
                    toggle.checked = newTheme === 'dark';
                }
            }
            
            // استعادة المظهر
            (function() {
                const savedTheme = localStorage.getItem('theme') || 'light';
                document.documentElement.setAttribute('data-theme', savedTheme);
                
                const icons = [
                    document.getElementById('headerThemeIcon'),
                    document.getElementById('themeIcon')
                ];
                icons.forEach(icon => {
                    if (icon) {
                        icon.className = savedTheme === 'dark' ? 'fas fa-moon' : 'fas fa-sun';
                    }
                });
                
                const toggle = document.getElementById('darkModeToggle');
                if (toggle) {
                    toggle.checked = savedTheme === 'dark';
                }
            })();
            
            // ============================================================
            // التنقل بين الأقسام
            // ============================================================
            function showSection(section) {
                document.querySelectorAll('.section-content').forEach(el => {
                    el.classList.add('hidden');
                });

                const target = document.getElementById('section-' + section);
                if (target) {
                    target.classList.remove('hidden');
                }

                document.querySelectorAll('#mainBottomNav .nav-item').forEach(el => {
                    el.classList.remove('active');
                    if (el.dataset.section === section) {
                        el.classList.add('active');
                    }
                });

                if (section === 'vps') {
                    wizardGoTo('plan');
                }
                if (section === 'servers') {
                    const searchInput = document.getElementById('serverSearchInput');
                    if (searchInput) searchInput.value = '';
                    filterServers();
                }

                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
            
            // ============================================================
            // تفاصيل الاستضافة
            // ============================================================
            function renderUsageTab(hosting) {
                // أرقام استخدام تجريبية ثابتة لكل سيرفر (مبنية على رقم السيرفر)
                const cpuPct = 15 + (hosting.id * 17) % 70;
                const ramPct = 20 + (hosting.id * 29) % 65;
                const bandwidthUsed = (0.2 + ((hosting.id * 0.37) % 0.7)).toFixed(2);
                const storageUsedPct = 25 + (hosting.id * 19) % 60;
                const storageTotal = parseInt(hosting.plan === 'أساسي' ? 50 : hosting.plan === 'متقدم' ? 100 : hosting.plan === 'احترافي' ? 200 : 40);
                const storageUsed = Math.round(storageTotal * storageUsedPct / 100);

                document.getElementById('usageGaugesContent').innerHTML = `
                    <div class="gauge-card">
                        <div class="gauge" style="--pct:${cpuPct}"><span class="gauge-value">${cpuPct}%</span></div>
                        <div class="gauge-label">CPU</div>
                    </div>
                    <div class="gauge-card">
                        <div class="gauge" style="--pct:${ramPct}"><span class="gauge-value">${ramPct}%</span></div>
                        <div class="gauge-label">RAM</div>
                    </div>
                `;
                document.getElementById('usageBandwidthLabel').textContent = bandwidthUsed + ' TB / 1 TB';
                document.getElementById('usageBandwidthFill').style.width = (bandwidthUsed * 100) + '%';
                document.getElementById('usageStorageLabel').textContent = storageUsed + ' GB / ' + storageTotal + ' GB';
                document.getElementById('usageStorageFill').style.width = storageUsedPct + '%';
            }

            function switchDetailTab(tab) {
                document.getElementById('tabBtnUsage').classList.toggle('active', tab === 'usage');
                document.getElementById('tabBtnInfo').classList.toggle('active', tab === 'info');
                document.getElementById('tabPanelUsage').classList.toggle('hidden', tab !== 'usage');
                document.getElementById('tabPanelInfo').classList.toggle('hidden', tab !== 'info');
            }

            function showHostingDetail(id) {
                const hosting = HOSTING.find(h => h.id === id);
                if (!hosting) return;

                detailReturnSection = document.getElementById('section-servers').classList.contains('hidden') ? 'home' : 'servers';

                const statusText = hosting.status === 'active' ? 'مفعل ✅' : 'منتهي ❌';
                const statusClass = hosting.status === 'active' ? 'pill-green' : 'pill-red';
                const isExpired = hosting.status === 'expired';

                renderUsageTab(hosting);
                switchDetailTab('usage');

                document.getElementById('hostingDetailContent').innerHTML = `
                    <div class="detail-row">
                        <span class="label">اسم الاستضافة</span>
                        <span class="value">${hosting.name}</span>
                    </div>
                    <div class="detail-row">
                        <span class="label">الخطة</span>
                        <span class="value">${hosting.plan}</span>
                    </div>
                    <div class="detail-row">
                        <span class="label">عنوان IP</span>
                        <span class="value">
                            ${hosting.ip}
                            <button class="copy-btn" onclick="copyText('${hosting.ip}')" title="نسخ"><i class="fas fa-copy"></i></button>
                        </span>
                    </div>
                    <div class="detail-row">
                        <span class="label">اسم المستخدم</span>
                        <span class="value">
                            ${hosting.username}
                            <button class="copy-btn" onclick="copyText('${hosting.username}')" title="نسخ"><i class="fas fa-copy"></i></button>
                        </span>
                    </div>
                    <div class="detail-row">
                        <span class="label">كلمة المرور</span>
                        <span class="value password">
                            ${hosting.password}
                            <button class="copy-btn" onclick="copyText('${hosting.password}')" title="نسخ"><i class="fas fa-copy"></i></button>
                        </span>
                    </div>
                    <div class="detail-row">
                        <span class="label">الحالة</span>
                        <span class="value"><span class="pill ${statusClass}">${statusText}</span></span>
                    </div>
                    <div class="detail-row">
                        <span class="label">تاريخ الانتهاء</span>
                        <span class="value">${hosting.expiry_date}</span>
                    </div>
                    ${isExpired ? `
                    <button class="btn-renew" onclick="renewHosting(${hosting.id})">
                        <i class="fas fa-sync"></i> تجديد الاستضافة
                    </button>
                    ` : ''}
                `;
                
                // إخفاء القسم الحالي وإظهار التفاصيل
                document.getElementById('section-' + detailReturnSection).classList.add('hidden');
                document.getElementById('section-hosting-detail').classList.remove('hidden');

                // تحديث التنقل
                document.querySelectorAll('#mainBottomNav .nav-item').forEach(el => {
                    el.classList.remove('active');
                });
            }

            function hideHostingDetail() {
                document.getElementById('section-hosting-detail').classList.add('hidden');
                document.getElementById('section-' + detailReturnSection).classList.remove('hidden');

                document.querySelectorAll('#mainBottomNav .nav-item').forEach(el => {
                    el.classList.remove('active');
                    if (el.dataset.section === detailReturnSection) {
                        el.classList.add('active');
                    }
                });
            }
            
            function copyText(text) {
                navigator.clipboard.writeText(text).then(function() {
                    // إظهار رسالة短暂ة
                    const btn = event.target.closest('.copy-btn');
                    if (btn) {
                        const original = btn.innerHTML;
                        btn.innerHTML = '<i class="fas fa-check" style="color:#34d399"></i>';
                        setTimeout(function() {
                            btn.innerHTML = original;
                        }, 1500);
                    }
                }).catch(function() {
                    // طريقة بديلة للنسخ
                    const input = document.createElement('input');
                    input.value = text;
                    document.body.appendChild(input);
                    input.select();
                    document.execCommand('copy');
                    document.body.removeChild(input);
                    alert('✅ تم نسخ النص!');
                });
            }
            
            function renewHosting(id) {
                if (confirm('هل تريد تجديد هذه الاستضافة؟')) {
                    alert('✅ تم تجديد الاستضافة بنجاح!\nتم إضافة شهر جديد إلى تاريخ الانتهاء.');
                    // في تطبيق حقيقي، يتم إرسال طلب تجديد
                    setTimeout(function() {
                        hideHostingDetail();
                        // تحديث الصفحة لإظهار التغييرات
                        location.reload();
                    }, 1000);
                }
            }
            
            // ============================================================
            // البحث في السيرفرات
            // ============================================================
            function filterServers() {
                const query = (document.getElementById('serverSearchInput').value || '').trim().toLowerCase();
                const items = document.querySelectorAll('#serversListContent .server-list-item');
                let visibleCount = 0;
                items.forEach(item => {
                    const matches = item.dataset.name.includes(query);
                    item.classList.toggle('hidden', !matches);
                    if (matches) visibleCount++;
                });
                document.getElementById('noServerResults').classList.toggle('hidden', visibleCount > 0);
            }

            // ============================================================
            // معالج طلب VPS
            // ============================================================
            let wizardState = { planId: null };

            function renderPlanList() {
                document.getElementById('planListContent').innerHTML = VPS_PLANS.map(plan => `
                    <div class="plan-select-item ${wizardState.planId === plan.id ? 'selected' : ''}" onclick="wizardSelectPlan('${plan.id}')">
                        <div class="radio-circle ${wizardState.planId === plan.id ? 'checked' : ''}"><i class="fas fa-check"></i></div>
                        <div class="info">
                            <div class="top-row">
                                <span class="plan-title">${plan.icon} ${plan.name}</span>
                                <span class="plan-price">${plan.price}$<small style="font-size:10px;font-weight:600;color:var(--text-muted)">/شهر</small></span>
                            </div>
                            <div class="plan-meta">${plan.cpu} · ${plan.ram} RAM · ${plan.storage}</div>
                            ${plan.badge ? `<span class="pill pill-gold" style="margin-top:6px">${plan.badge}</span>` : ''}
                        </div>
                    </div>
                `).join('');
            }

            function wizardSelectPlan(planId) {
                wizardState.planId = planId;
                renderPlanList();
                document.getElementById('planContinueBtn').disabled = false;
            }

            function currentPlan() {
                return VPS_PLANS.find(p => p.id === wizardState.planId);
            }

            function renderPlanDetails() {
                const plan = currentPlan();
                if (!plan) return;
                document.getElementById('planDetailsIcon').textContent = plan.icon;
                document.getElementById('planDetailsName').textContent = plan.name;
                document.getElementById('planDetailsPrice').innerHTML = `${plan.price}$ <small style="font-size:13px;color:var(--text-muted)">/شهر</small>`;
                document.getElementById('planDetailsSpecs').innerHTML = `
                    <div class="detail-row"><span class="label">المعالج</span><span class="value">${plan.cpu}</span></div>
                    <div class="detail-row"><span class="label">الذاكرة (RAM)</span><span class="value">${plan.ram}</span></div>
                    <div class="detail-row"><span class="label">التخزين</span><span class="value">${plan.storage}</span></div>
                    <div class="detail-row"><span class="label">الباندويث</span><span class="value">${plan.bandwidth}</span></div>
                    <div class="detail-row"><span class="label">نظام التشغيل</span><span class="value">Ubuntu 22.04</span></div>
                    <div class="detail-row"><span class="label">موقع السيرفر</span><span class="value">Frankfurt, Germany</span></div>
                `;
            }

            function renderOrderSummary() {
                const plan = currentPlan();
                if (!plan) return;
                document.getElementById('orderSummaryContent').innerHTML = `
                    <div class="detail-row"><span class="label">الباقة</span><span class="value">${plan.icon} ${plan.name}</span></div>
                    <div class="detail-row"><span class="label">موقع السيرفر</span><span class="value">Frankfurt, Germany</span></div>
                    <div class="detail-row"><span class="label">نظام التشغيل</span><span class="value">Ubuntu 22.04</span></div>
                    <div class="detail-row"><span class="label">مدة الاشتراك</span><span class="value">شهري</span></div>
                    <div class="detail-row"><span class="label">السعر</span><span class="value">${plan.price}$/شهر</span></div>
                `;
                document.getElementById('paymentTotalAmount').textContent = plan.price + '$';
            }

            function renderPayOptions() {
                const options = [
                    { id: 'saved_card', icon: 'fa-credit-card', color: 'blue', title: 'بطاقة فيزا •••• 4242', sub: 'تنتهي 12/28' },
                    { id: 'paypal', icon: 'fa-brands fa-paypal', color: 'purple', title: 'PayPal', sub: 'ادفع عبر حسابك في PayPal' },
                    { id: 'crypto', icon: 'fa-brands fa-bitcoin', color: 'gold', title: 'Crypto Payment', sub: 'ادفع بعملة رقمية' },
                    { id: 'balance', icon: 'fa-wallet', color: 'green', title: 'رصيد الحساب', sub: '$' + Number(USER_BALANCE).toFixed(2) + ' متاح' },
                ];
                if (!wizardState.paymentMethod) wizardState.paymentMethod = 'saved_card';
                document.getElementById('payOptionsContent').innerHTML = options.map(opt => `
                    <div class="pay-option ${wizardState.paymentMethod === opt.id ? 'selected' : ''}" onclick="wizardSelectPayment('${opt.id}')">
                        <div class="icon-wrap ${opt.color}"><i class="${opt.icon.startsWith('fa-brands') ? opt.icon : 'fas ' + opt.icon}"></i></div>
                        <div style="flex:1">
                            <div class="title">${opt.title}</div>
                            <div class="sub">${opt.sub}</div>
                        </div>
                        <div class="radio-circle ${wizardState.paymentMethod === opt.id ? 'checked' : ''}"><i class="fas fa-check"></i></div>
                    </div>
                `).join('');
            }

            function wizardSelectPayment(id) {
                wizardState.paymentMethod = id;
                renderPayOptions();
            }

            function wizardGoTo(step) {
                document.querySelectorAll('#section-vps .wizard-step').forEach(el => el.classList.add('hidden'));
                document.getElementById('vpsStep' + step.charAt(0).toUpperCase() + step.slice(1)).classList.remove('hidden');

                if (step === 'plan') {
                    wizardState = { planId: null, paymentMethod: null };
                    renderPlanList();
                    document.getElementById('planContinueBtn').disabled = true;
                } else if (step === 'details') {
                    renderPlanDetails();
                } else if (step === 'summary') {
                    renderOrderSummary();
                } else if (step === 'payment') {
                    renderPayOptions();
                    renderOrderSummary();
                }
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }

            function wizardPay() {
                const plan = currentPlan();
                if (!plan) return;

                const newId = nextHostingId++;
                const newHosting = {
                    id: newId,
                    name: 'خادم ' + plan.name + ' - طلب جديد',
                    plan: plan.name,
                    ip: '192.168.1.' + (100 + newId),
                    username: 'root',
                    password: 'Vps#' + Math.random().toString(36).slice(2, 8),
                    status: 'active',
                    expiry_date: new Date(Date.now() + 30 * 24 * 3600 * 1000).toISOString().slice(0, 10)
                };
                HOSTING.push(newHosting);
                ORDERS.push({ plan: plan.name, price: plan.price, date: new Date().toLocaleDateString('ar-IQ') });

                renderServersList();
                renderOrdersList();
                wizardGoTo('success');
            }

            function renderServersList() {
                const list = document.getElementById('serversListContent');
                const noResults = document.getElementById('noServerResults');
                list.querySelectorAll('.server-list-item').forEach(el => el.remove());
                HOSTING.forEach(h => {
                    const div = document.createElement('div');
                    div.className = 'hosting-item server-list-item';
                    div.dataset.name = h.name.toLowerCase();
                    div.onclick = () => showHostingDetail(h.id);
                    div.innerHTML = `
                        <div class="info">
                            <div class="name">${h.name}</div>
                            <div class="sub">${h.plan} · ${h.ip}</div>
                        </div>
                        <div class="status-badge">
                            <span class="pill ${h.status === 'active' ? 'pill-green' : 'pill-red'}">${h.status === 'active' ? '✅ قيد التشغيل' : '❌ منتهي'}</span>
                            <div style="font-size:9px;color:var(--text-muted);margin-top:2px">ينتهي: ${h.expiry_date}</div>
                        </div>
                    `;
                    list.insertBefore(div, noResults);
                });
                document.getElementById('section-servers').querySelector('.card-header span').textContent = HOSTING.length + ' سيرفر';
            }

            function renderOrdersList() {
                document.getElementById('ordersCount').textContent = ORDERS.length;
                document.getElementById('ordersEmptyState').classList.toggle('hidden', ORDERS.length > 0);
                document.getElementById('ordersListContent').innerHTML = ORDERS.map(o => `
                    <div class="invoice-item">
                        <div class="info">
                            <div class="number">خادم ${o.plan}</div>
                            <div class="date">تاريخ الطلب: ${o.date}</div>
                        </div>
                        <div style="text-align:left">
                            <div class="amount">$${o.price}</div>
                            <span class="pill pill-green">✅ مكتمل</span>
                        </div>
                    </div>
                `).join('');
            }
            
            // ============================================================
            // الفواتير
            // ============================================================
            function showAddBalance() {
                document.getElementById('addBalanceSection').classList.remove('hidden');
                document.getElementById('invoicesList').classList.add('hidden');
                document.getElementById('invoiceDetail').classList.add('hidden');
                document.getElementById('paymentPage').classList.add('hidden');
            }
            
            function hideAddBalance() {
                document.getElementById('addBalanceSection').classList.add('hidden');
                document.getElementById('invoicesList').classList.remove('hidden');
            }
            
            function showPaymentPage(methodId, methodName) {
                document.getElementById('paymentMethodName').textContent = 'الدفع عبر ' + methodName;
                document.getElementById('paymentPage').classList.remove('hidden');
                document.getElementById('addBalanceSection').classList.add('hidden');
                document.getElementById('paymentStatus').textContent = '';
                document.getElementById('paymentStatus').className = 'text-center';
            }
            
            function hidePaymentPage() {
                document.getElementById('paymentPage').classList.add('hidden');
                document.getElementById('addBalanceSection').classList.remove('hidden');
            }
            
            function processPayment() {
                const amount = document.getElementById('paymentAmount').value;
                const status = document.getElementById('paymentStatus');
                
                if (!amount || amount <= 0) {
                    status.textContent = '⚠️ أدخل مبلغ صحيح';
                    status.className = 'text-center error';
                    status.style.color = '#f87171';
                    return;
                }
                
                status.textContent = '⏳ جاري معالجة الدفع...';
                status.className = 'text-center';
                status.style.color = 'var(--text-muted)';
                
                setTimeout(function() {
                    status.textContent = '✅ تم إضافة $' + amount + ' إلى رصيدك بنجاح!';
                    status.className = 'text-center success';
                    status.style.color = '#34d399';
                    
                    setTimeout(function() {
                        hidePaymentPage();
                        hideAddBalance();
                        document.getElementById('invoicesList').classList.remove('hidden');
                    }, 2000);
                }, 1500);
            }
            
            function showInvoiceDetail(id) {
                const invoice = INVOICES.find(inv => inv.id === id);
                if (!invoice) return;
                
                const statusText = invoice.status === 'paid' ? 'مدفوع ✅' : 
                                  (invoice.status === 'pending' ? 'معلق ⏳' : 'متأخر ❌');
                const statusClass = invoice.status === 'paid' ? 'pill-green' : 
                                   (invoice.status === 'pending' ? 'pill-amber' : 'pill-red');
                
                document.getElementById('invoiceDetailContent').innerHTML = `
                    <div class="detail-row">
                        <span class="label">رقم الفاتورة</span>
                        <span class="value">${invoice.number}</span>
                    </div>
                    <div class="detail-row">
                        <span class="label">المبلغ</span>
                        <span class="value amount">$${invoice.amount}</span>
                    </div>
                    <div class="detail-row">
                        <span class="label">الحالة</span>
                        <span class="value"><span class="pill ${statusClass}">${statusText}</span></span>
                    </div>
                    <div class="detail-row">
                        <span class="label">تاريخ الاستحقاق</span>
                        <span class="value">${invoice.due_date}</span>
                    </div>
                    <div class="detail-row">
                        <span class="label">الوصف</span>
                        <span class="value">${invoice.description || 'لا يوجد وصف'}</span>
                    </div>
                    ${invoice.status !== 'paid' ? `
                    <button class="btn-pay" onclick="showPaymentPage('invoice', 'دفع الفاتورة')">
                        <i class="fas fa-credit-card"></i> دفع الفاتورة الآن
                    </button>
                    ` : ''}
                `;
                
                document.getElementById('invoicesList').classList.add('hidden');
                document.getElementById('invoiceDetail').classList.remove('hidden');
                document.getElementById('addBalanceSection').classList.add('hidden');
                document.getElementById('paymentPage').classList.add('hidden');
            }
            
            function hideInvoiceDetail() {
                document.getElementById('invoiceDetail').classList.add('hidden');
                document.getElementById('invoicesList').classList.remove('hidden');
            }
            
            // ============================================================
            // بطاقة تأكيد تسجيل الخروج
            // ============================================================
            function showLogoutSheet() {
                document.getElementById('logoutOverlay').classList.add('show');
                document.body.style.overflow = 'hidden';
            }
            
            function closeLogoutSheet() {
                document.getElementById('logoutOverlay').classList.remove('show');
                document.body.style.overflow = '';
            }
            
            function confirmLogout() {
                window.location.href = '?logout=1';
            }

            // ============================================================
            // المساعد الذكي
            // ============================================================
            function enterAI() {
                document.querySelector('.header').classList.add('hidden');
                document.getElementById('appContent').classList.add('hidden');
                document.getElementById('mainBottomNav').classList.add('hidden');
                document.getElementById('section-ai').classList.remove('hidden');
                showAiView('home');
            }

            function exitAI() {
                document.getElementById('section-ai').classList.add('hidden');
                document.querySelector('.header').classList.remove('hidden');
                document.getElementById('appContent').classList.remove('hidden');
                document.getElementById('mainBottomNav').classList.remove('hidden');
                showSection('home');
            }

            const AI_VIEW_TITLES = {
                home: 'المساعد الذكي',
                explain: 'شرح أمر',
                solve: 'حل مشكلة',
                tools: 'الأدوات الذكية',
                conversations: 'المحادثات',
                settings: 'إعدادات المساعد'
            };
            const AI_CHAT_VIEWS = ['home', 'explain', 'solve'];

            function showAiView(view) {
                document.querySelectorAll('.ai-view').forEach(el => el.classList.add('hidden'));
                document.getElementById('aiView' + view.charAt(0).toUpperCase() + view.slice(1)).classList.remove('hidden');
                document.getElementById('aiHeaderTitle').textContent = AI_VIEW_TITLES[view] || 'المساعد الذكي';

                const isChatView = AI_CHAT_VIEWS.includes(view);
                document.getElementById('aiInputBar').classList.toggle('hidden', !isChatView);
                document.getElementById('aiBottomNav').classList.toggle('hidden', isChatView);

                document.querySelectorAll('#aiBottomNav .nav-item').forEach(el => {
                    el.classList.toggle('active', el.dataset.aiView === view);
                });

                if (view === 'explain' && !document.getElementById('aiExplainChatLog').children.length) {
                    explainCommand('apt update');
                }
                if (view === 'solve' && !document.getElementById('aiSolveChatLog').children.length) {
                    renderSolveExample();
                }

                document.getElementById('aiBody').scrollTop = 0;
            }

            function escapeHtml(str) {
                const div = document.createElement('div');
                div.textContent = str;
                return div.innerHTML;
            }

            function appendChatBubble(logId, sender, html) {
                const log = document.getElementById(logId);
                const bubble = document.createElement('div');
                bubble.className = 'chat-bubble ' + sender;
                bubble.innerHTML = html;
                log.appendChild(bubble);
                document.getElementById('aiBody').scrollTop = document.getElementById('aiBody').scrollHeight;
            }

            function explainCommand(cmd) {
                const c = (cmd || '').trim();
                const log = document.getElementById('aiExplainChatLog');
                if (c) {
                    const bubble = document.createElement('div');
                    bubble.className = 'chat-bubble user';
                    bubble.textContent = c;
                    log.appendChild(bubble);
                }
                const card = document.createElement('div');
                card.className = 'ai-card';
                if (/apt update/i.test(c) || !c) {
                    card.innerHTML = `
                        <h4><i class="fas fa-terminal"></i> شرح أمر: apt update</h4>
                        <p style="font-size:13px;color:var(--text-secondary);line-height:1.8">
                            يقوم هذا الأمر بتحديث قائمة الحزم المتوفرة من مستودعات النظام، دون تثبيت أي تحديثات فعلياً — فقط يحدّث "الفهرس" ليعرف النظام أحدث الإصدارات المتاحة.
                        </p>
                        <div style="font-weight:700;font-size:12px;margin:10px 0 4px;color:var(--text-primary)">مثال الاستخدام:</div>
                        <code>sudo apt update</code>
                    `;
                } else {
                    card.innerHTML = `
                        <h4><i class="fas fa-terminal"></i> شرح أمر</h4>
                        <p style="font-size:13px;color:var(--text-secondary);line-height:1.8">
                            هذه ميزة تجريبية بردود جاهزة — جرّب أمر <strong>apt update</strong> لرؤية مثال كامل على الشرح.
                        </p>
                        <code>${escapeHtml(c)}</code>
                    `;
                }
                log.appendChild(card);
                document.getElementById('aiBody').scrollTop = document.getElementById('aiBody').scrollHeight;
            }

            function renderSolveExample() {
                const log = document.getElementById('aiSolveChatLog');
                const card = document.createElement('div');
                card.className = 'ai-card';
                card.innerHTML = `
                    <h4><i class="fas fa-triangle-exclamation" style="color:#f87171"></i> المشكلة: لا يمكن الاتصال بالسيرفر</h4>
                    <div style="font-weight:700;font-size:12px;margin:10px 0 4px;color:var(--text-primary)">الأسباب المحتملة:</div>
                    <ol>
                        <li>الشبكة مقطوعة عن السيرفر</li>
                        <li>جدار الحماية يمنع الاتصال</li>
                        <li>المشكلة في مزود الخدمة</li>
                        <li>إعدادات IP غير صحيحة</li>
                    </ol>
                    <div style="font-weight:700;font-size:12px;margin:10px 0 4px;color:var(--text-primary)">الحلول المقترحة:</div>
                    <ol>
                        <li>تأكد من أن السيرفر يعمل من لوحة التحكم</li>
                        <li>تحقق من إعدادات الشبكة والفايروول</li>
                    </ol>
                `;
                log.appendChild(card);
            }

            function craftAiReply(text) {
                const t = text.toLowerCase();
                if (t.includes('apt') || t.includes('تحديث') || t.includes('update')) {
                    return 'هذا يبدو كسؤال عن أوامر التحديث! جرب قسم "شرح أمر" لأشرح لك أي أمر بالتفصيل 🖥️';
                }
                if (t.includes('اتصال') || t.includes('مشكلة') || t.includes('down') || t.includes('لا يعمل')) {
                    return 'يبدو أن هناك مشكلة بالاتصال. افتح قسم "حل مشكلة" لأساعدك في تشخيصها خطوة بخطوة 🔧';
                }
                if (t.includes('بطيء') || t.includes('أداء') || t.includes('سرعة')) {
                    return 'لتحسين الأداء: جرب أداة "تحسين حالة السيرفر" من قسم الأدوات الذكية، وتأكد من عدم امتلاء التخزين 🚀';
                }
                return 'شكراً لسؤالك! هذه نسخة تجريبية من المساعد بردود جاهزة — جرب الأزرار السريعة أعلاه لتجربة كاملة 🤖';
            }

            function sendAiMessage() {
                const input = document.getElementById('aiInputField');
                const text = input.value.trim();
                if (!text) return;
                input.value = '';

                const activeView = AI_CHAT_VIEWS.find(v => !document.getElementById('aiView' + v.charAt(0).toUpperCase() + v.slice(1)).classList.contains('hidden')) || 'home';

                if (activeView === 'explain') {
                    explainCommand(text);
                    return;
                }
                if (activeView === 'solve') {
                    appendChatBubble('aiSolveChatLog', 'user', escapeHtml(text));
                    appendChatBubble('aiSolveChatLog', 'bot', 'جرب الحلول المقترحة أعلاه أولاً. إذا استمرت المشكلة، تواصل مع الدعم الفني عبر واتساب من صفحة الحساب.');
                    return;
                }

                appendChatBubble('aiHomeChatLog', 'user', escapeHtml(text));
                const reply = craftAiReply(text);
                setTimeout(() => appendChatBubble('aiHomeChatLog', 'bot', reply), 400);
            }

            function openConversation(title) {
                showAiView('home');
                appendChatBubble('aiHomeChatLog', 'bot', '📂 فتح محادثة سابقة: <strong>' + escapeHtml(title) + '</strong> (السجل الكامل غير متاح في هذه النسخة التجريبية)');
            }

            function clearAiConversations() {
                if (!confirm('هل تريد مسح جميع المحادثات؟ لا يمكن التراجع عن هذا الإجراء.')) return;
                document.getElementById('aiConversationsList').innerHTML = '<div class="text-muted text-center" style="padding:24px 0">لا توجد محادثات محفوظة</div>';
            }

            // ============================================================
            // عرض القسم الافتراضي
            // ============================================================
            showSection('home');
        </script>
    </body>
    </html>
    <?php
}

// ============================================================
// بدء التشغيل
// ============================================================

includeWelcomePage();
?>