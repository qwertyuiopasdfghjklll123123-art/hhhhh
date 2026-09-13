<?php
// ========== إعدادات الأمان المتقدمة ==========
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once __DIR__ . '/includes/functions.php';

// إنشاء مجلد uploads إذا لم يكن موجوداً
$uploadsDir = __DIR__ . '/uploads';
if (!file_exists($uploadsDir)) {
    @mkdir($uploadsDir, 0755, true);
}

// إذا لم يكتمل التنصيب بعد، وجّه المستخدم إلى ملف التنصيب
if (!file_exists(__DIR__ . '/config.php')) {
    header('Location: install.php');
    exit;
}
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/data.php';

// ========== إعدادات الجلسة ==========
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.gc_maxlifetime', 315360000);
ini_set('session.cookie_lifetime', 315360000);

// بدء الجلسة
session_name('Almulla_SECURE');
session_start();

// تجديد ID الجلسة
if (!isset($_SESSION['initiated'])) {
    session_regenerate_id(true);
    $_SESSION['initiated'] = true;
    $_SESSION['created_at'] = time();
}

if (isset($_SESSION['last_activity'])) {
    $_SESSION['last_activity'] = time();
}

// إنشاء CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

writeLog("========== بدء تشغيل التطبيق ==========");

// ========== معالجة طلبات API ==========
$action = isset($_GET['action']) ? $_GET['action'] : '';

if (!$action && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $earlyInput = json_decode(file_get_contents('php://input'), true);
    if (isset($earlyInput['action'])) {
        $action = $earlyInput['action'];
    }
}

if ($action) {
    require __DIR__ . '/includes/api.php';
    exit;
}

// ===== إنشاء manifest.json =====
if (isset($_GET['manifest'])) {
    header('Content-Type: application/manifest+json');
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $domain = $_SERVER['HTTP_HOST'];
    echo json_encode([
        'name' => 'Almulla',
        'short_name' => 'Almulla',
        'description' => 'منصة متكاملة للإدارة والتسوق',
        'start_url' => './?page=app',
        'display' => 'standalone',
        'theme_color' => '#6366f1',
        'background_color' => '#ffffff',
        'orientation' => 'portrait',
        'scope' => './',
        'privacy_policy_url' => $protocol . '://' . $domain . '/privacy-policy.php',
        'icons' => [
            ['src' => 'https://iili.io/CKP5shF.jpg', 'sizes' => '72x72', 'type' => 'image/jpeg'],
            ['src' => 'https://iili.io/CKP5shF.jpg', 'sizes' => '96x96', 'type' => 'image/jpeg'],
            ['src' => 'https://iili.io/CKP5shF.jpg', 'sizes' => '128x128', 'type' => 'image/jpeg'],
            ['src' => 'https://iili.io/CKP5shF.jpg', 'sizes' => '144x144', 'type' => 'image/jpeg'],
            ['src' => 'https://iili.io/CKP5shF.jpg', 'sizes' => '152x152', 'type' => 'image/jpeg'],
            ['src' => 'https://iili.io/CKP5shF.jpg', 'sizes' => '192x192', 'type' => 'image/jpeg'],
            ['src' => 'https://iili.io/CKP5shF.jpg', 'sizes' => '384x384', 'type' => 'image/jpeg'],
            ['src' => 'https://iili.io/CKP5shF.jpg', 'sizes' => '512x512', 'type' => 'image/jpeg']
        ],
        'shortcuts' => [
            [
                'name' => 'المواد',
                'short_name' => 'مواد',
                'description' => 'عرض جميع المواد المتاحة',
                'url' => './?page=app&view=services',
                'icons' => [['src' => 'https://iili.io/CKP5shF.jpg', 'sizes' => '96x96']]
            ],
            [
                'name' => 'السلة',
                'short_name' => 'سلة',
                'description' => 'عرض سلة التسوق',
                'url' => './?page=app&view=cart',
                'icons' => [['src' => 'https://iili.io/CKP5shF.jpg', 'sizes' => '96x96']]
            ],
            [
                'name' => 'المفضلات',
                'short_name' => 'مفضلات',
                'description' => 'عرض المواد المفضلة',
                'url' => './?page=app&view=favorites',
                'icons' => [['src' => 'https://iili.io/CKP5shF.jpg', 'sizes' => '96x96']]
            ]
        ]
    ]);
    exit;
}

// ===== Service Worker =====
if (isset($_GET['sw'])) {
    header('Content-Type: application/javascript');
    echo '
const CACHE_NAME="Almulla-v3";
const urlsToCache=["./","./?page=app","https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css","https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap"];

self.addEventListener("install",e=>{
    e.waitUntil(caches.open(CACHE_NAME).then(c=>c.addAll(urlsToCache)))
});

self.addEventListener("fetch",e=>{
    e.respondWith(
        fetch(e.request).catch(()=>{
            return caches.match(e.request).then(response=>{
                if(response) return response;
                if(e.request.mode === "navigate") return caches.match("/?page=app");
                return new Response("غير متصل بالإنترنت", {status: 503});
            });
        })
    );
});

self.addEventListener("activate",e=>{
    e.waitUntil(caches.keys().then(k=>Promise.all(k.filter(k=>k!==CACHE_NAME).map(k=>caches.delete(k)))));
});
';
    exit;
}

$page = isset($_GET['page']) ? $_GET['page'] : '';
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes, viewport-fit=cover">
    <meta name="theme-color" content="#6366f1">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Almulla">
    <link rel="manifest" href="?manifest=1">
    <link rel="icon" type="image/png" sizes="32x32" href="https://iili.io/CKP5shF.jpg">
    <link rel="icon" type="image/png" sizes="16x16" href="https://iili.io/CKP5shF.jpg">
    <link rel="apple-touch-icon" sizes="180x180" href="https://iili.io/CKP5shF.jpg">
    <title>Almulla | <?php echo $page === 'app' ? 'التطبيق' : 'تسجيل الدخول'; ?></title>
    <link rel="preconnect" href="https://cdnjs.cloudflare.com" crossorigin>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>.app-screen{display:<?php echo $page === 'app' ? 'block' : 'none'; ?>;}</style>
</head>
<body data-t="light">
    <!-- ========== تحميل الصور مسبقاً ========== -->
    <div id="preloadImages" style="display:none;"></div>

    <!-- شاشة التحميل -->
    <div id="splash-screen">
        <div class="splash-logo">
            <img src="https://iili.io/CKP5shF.jpg" alt="Almulla">
            <h1>Almulla</h1>
            <div class="progress-container">
                <div class="progress-bar"></div>
            </div>
        </div>
    </div>

    <div class="bg-glow"><div class="g g1"></div><div class="g g2"></div></div>
    <div class="bg-grid"></div>

    <!-- ========== شاشة تسجيل الدخول ========== -->
    <div id="loginScreen" class="login-screen">
        <div class="login-container">
            <div class="compact-card">
                <div class="logo-area">
                    <img id="loginAppLogo" class="app-logo-img" src="" alt="logo">
                    <div class="logo" id="loginAppName">Almulla</div>
                    <div class="sub-logo" id="loginSubText">منصة متكاملة للإدارة والتسوق</div>
                </div>
                <div class="tabs">
                    <button class="tab-btn active" onclick="switchTab('login')">تسجيل الدخول</button>
                    <button class="tab-btn" onclick="switchTab('register')">إنشاء حساب</button>
                </div>
                <div class="msg err" id="authError"></div>
                <div class="msg suc" id="authSuccess"></div>
                <div id="loginTab" class="tab-content active">
                    <div class="fld"><label>البريد الإلكتروني</label><div class="iw"><span class="ic"><i class="fas fa-envelope"></i></span><input type="email" id="loginEmail" placeholder="admin@Almulla.com"></div></div>
                    <div class="fld"><label>كلمة المرور</label><div class="iw"><span class="ic"><i class="fas fa-lock"></i></span><input type="password" id="loginPassword" placeholder="أدخل كلمة المرور"></div></div>
                    <button class="sbtn" onclick="login()">تسجيل الدخول</button>
                    <button class="browse-services-btn" onclick="browseAsGuest()">
                        <i class="fas fa-eye"></i> تصفح المواد كزائر
                    </button>
                </div>
                <div id="registerTab" class="tab-content">
                    <div class="fld"><label>الاسم الكامل</label><div class="iw"><span class="ic"><i class="fas fa-user"></i></span><input type="text" id="regFullname" placeholder="الاسم الكامل"></div></div>
                    <div class="fld"><label>البريد الإلكتروني</label><div class="iw"><span class="ic"><i class="fas fa-envelope"></i></span><input type="email" id="regEmail" placeholder="example@email.com"></div></div>
                    <div class="fld"><label>كلمة المرور</label><div class="iw"><span class="ic"><i class="fas fa-lock"></i></span><input type="password" id="regPassword" placeholder="أدخل كلمة المرور"></div></div>
                    <div class="fld"><label>تأكيد كلمة المرور</label><div class="iw"><span class="ic"><i class="fas fa-check-circle"></i></span><input type="password" id="regConfirmPass" placeholder="أعد كتابة كلمة المرور"></div></div>
                    
                    <div class="captcha-container">
                        <canvas id="registerCaptchaCanvas" class="captcha-canvas" width="200" height="50"></canvas>
                        <div class="refresh-captcha" onclick="generateCaptcha()">
                            <i class="fas fa-sync-alt"></i>
                        </div>
                    </div>
                    <div class="fld">
                        <label>رمز التحقق</label>
                        <div class="iw">
                            <span class="ic"><i class="fas fa-shield-alt"></i></span>
                            <input type="text" id="registerCaptchaInput" placeholder="أدخل الأرقام التي تظهر في الصورة" maxlength="4">
                        </div>
                    </div>
                    <div id="registerWaitingTimer" class="waiting-timer"></div>
                    
                    <button class="sbtn" id="registerBtn" onclick="handleRegister()">إنشاء حساب</button>
                </div>
                
                <div class="login-footer-links">
                    <a href="/privacy-policy.php"><i class="fas fa-shield-alt"></i> سياسة الخصوصية</a>
                    <span>|</span>
                    <a href="#" onclick="showContactUs()"><i class="fas fa-headset"></i> اتصل بنا</a>
                    <span>|</span>
                    <a href="#" onclick="showAbout()"><i class="fas fa-info-circle"></i> عن التطبيق</a>
                </div>
            </div>
        </div>
    </div>
<!-- ========== شاشة التطبيق ========== -->
<div id="appScreen" class="app-screen">
    <div class="main-wrapper">
        <div class="top-bar">
            <div class="user-info">
                <i class="fas fa-user-circle"></i>
                <span id="userNameDisplay"></span>
                <button id="adminBtn" style="display:none;" onclick="openAdminPanel()">
                    <i class="fas fa-cog"></i> تحكم
                </button>
            </div>
        </div>
        
        <div id="welcomeCardContainer" style="display: none;"></div>
        <div id="topServicesContainer" class="top-services-section" style="display: none;"></div>
        
        <div class="search-bar">
            <i class="fas fa-search"></i>
            <input type="text" id="searchInput" placeholder="ابحث عن خدمة..." onkeyup="searchItems()">
        </div>
        <div id="searchResults" class="search-results"></div>
        <div id="contentArea"></div>
    </div>

    <!-- صفحة الملف الشخصي -->
    <div id="profilePage" style="display:none; padding:20px; max-width:500px; margin:0 auto;">
        <div class="profile-header">
            <div class="cover-image"></div>
            <div class="profile-avatar">
                <i class="fas fa-user-circle"></i>
            </div>
            <div class="profile-name" id="displayName"></div>
            <div class="profile-email">
                <i class="fas fa-envelope"></i>
                <span id="displayEmail"></span>
            </div>
            <div class="profile-badge">
                <i class="fas fa-check-circle"></i>
                <span>عضو مميز</span>
            </div>
        </div>

        <div id="viewCard">
            <div class="section-card">
                <div class="section-title">
                    <span><i class="fas fa-shield-alt"></i> الحساب والأمان</span>
                    <i class="fas fa-lock"></i>
                </div>
                <div class="menu-grid">
                    <div class="menu-item" onclick="showEditForm()">
                        <i class="fas fa-user-edit menu-icon"></i>
                        <span class="menu-text">تعديل الملف</span>
                    </div>
                    <div class="menu-item" onclick="requestNotification()">
                        <i class="fas fa-bell menu-icon"></i>
                        <span class="menu-text">الإشعارات</span>
                    </div>
                </div>
            </div>

            <div class="section-card">
                <div class="section-title">
                    <span><i class="fas fa-share-alt"></i> مشاركة التطبيق</span>
                    <i class="fas fa-qrcode"></i>
                </div>
                <div class="share-grid">
                    <div class="share-item whatsapp" onclick="shareViaWhatsApp()">
                        <i class="fab fa-whatsapp"></i>
                        <span>واتساب</span>
                    </div>
                    <div class="share-item facebook" onclick="shareViaFacebook()">
                        <i class="fab fa-facebook-f"></i>
                        <span>فيسبوك</span>
                    </div>
                    <div class="share-item copy" onclick="copyShareLink()">
                        <i class="fas fa-link"></i>
                        <span>نسخ الرابط</span>
                    </div>
                    <div class="share-item native" onclick="shareViaNative()">
                        <i class="fas fa-mobile-alt"></i>
                        <span>مشاركة</span>
                    </div>
                    <div class="share-item email" onclick="shareViaEmail()">
                        <i class="fas fa-envelope"></i>
                        <span>بريد</span>
                    </div>
                    <div class="share-item sms" onclick="shareViaSMS()">
                        <i class="fas fa-sms"></i>
                        <span>SMS</span>
                    </div>
                </div>
            </div>

            <div class="section-card">
                <div class="section-title">
                    <span><i class="fas fa-sliders-h"></i> الإعدادات</span>
                    <i class="fas fa-cog"></i>
                </div>
                <div class="menu-grid">
                    <div class="menu-item" onclick="toggleTheme()">
                        <i class="fas fa-moon menu-icon"></i>
                        <span class="menu-text">المظهر</span>
                    </div>
                    <div class="menu-item" onclick="showLanguageMenu()">
                        <i class="fas fa-language menu-icon"></i>
                        <span class="menu-text">اللغة</span>
                    </div>
                    <div class="menu-item" onclick="showContactUs()">
                        <i class="fas fa-headset menu-icon"></i>
                        <span class="menu-text">اتصل بنا</span>
                    </div>
                    <div class="menu-item" onclick="showLogoutConfirm()" style="border: 2px solid var(--rd); background: rgba(239, 68, 68, 0.1);">
                        <i class="fas fa-sign-out-alt menu-icon" style="color: var(--rd);"></i>
                        <span class="menu-text" style="color: var(--rd); font-weight: 700;">تسجيل خروج</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- نموذج تعديل الملف الشخصي -->
        <div class="section-card edit-form" id="editForm">
            <div class="section-title">
                <span><i class="fas fa-user-edit"></i> تعديل الملف الشخصي</span>
            </div>
            <div class="form-group">
                <label>الاسم الكامل</label>
                <div class="input-wrapper">
                    <i class="fas fa-user"></i>
                    <input type="text" id="editFullname" placeholder="الاسم الكامل">
                </div>
            </div>
            <div class="form-group">
                <label>البريد الإلكتروني</label>
                <div class="input-wrapper">
                    <i class="fas fa-envelope"></i>
                    <input type="email" id="editEmail" placeholder="البريد الإلكتروني">
                </div>
            </div>
            <div class="btn-group">
                <button class="btn btn-primary" onclick="saveProfileChanges()">
                    <i class="fas fa-save"></i> حفظ التغييرات
                </button>
                <button class="btn btn-secondary" onclick="hideEditForm()">
                    <i class="fas fa-times"></i> إلغاء
                </button>
            </div>
        </div>

        <div class="footer">
            <i class="fas fa-shield-alt"></i> حسابك محمي بأحدث تقنيات التشفير
            <br>
            <span style="font-size: 0.6rem;">الإصدار 2.0.0</span>
        </div>
    </div>

    <!-- شريط التنقل السفلي -->
    <div class="bottom-nav">
        <div class="bottom-nav-container">
            <div class="nav-item active" data-page="home" onclick="switchPage('home')">
                <i class="fas fa-home"></i>
                <span>الرئيسية</span>
            </div>
            <div class="nav-item" data-page="cart" onclick="toggleCart()">
                <i class="fas fa-shopping-cart"></i>
                <span>السلة</span>
                <span class="nav-badge" id="cartNavBadge" style="display:none;">0</span>
            </div>
            <div class="nav-item" data-page="favorites" onclick="toggleFavorites()">
                <i class="fas fa-heart"></i>
                <span>المفضلات</span>
                <span class="nav-badge" id="favoritesNavBadge" style="display:none;">0</span>
            </div>
            <div class="nav-item" data-page="profile" onclick="switchPage('profile')">
                <i class="fas fa-user"></i>
                <span>حسابي</span>
            </div>
        </div>
    </div>
</div>

    <!-- ========== السلة والمفضلات ========== -->
    <div id="cartSidebar" class="cart-sidebar">
        <div class="cart-header" style="padding:15px; border-bottom:1px solid var(--cardBd); display:flex; justify-content:space-between; align-items:center;">
            <h3>🛒 السلة</h3>
            <button onclick="toggleCart()" style="background:none; border:none; font-size:1.2rem; cursor:pointer; color:var(--t4);">✕</button>
        </div>
        <div class="cart-items" id="cartItems" style="flex:1; overflow-y:auto; padding:10px;"></div>
        <div class="cart-footer" id="cartFooter" style="display:none; padding:15px; border-top:1px solid var(--cardBd);">
            <button onclick="checkout()" style="width:100%; padding:12px; border-radius:50px; background:#25D366; color:white; border:none; font-weight:700; cursor:pointer; margin-bottom:8px;"><i class="fab fa-whatsapp"></i> إرسال الطلب</button>
            <button onclick="clearCart()" style="width:100%; padding:12px; border-radius:50px; background:transparent; border:1px solid var(--rd); color:var(--rd); font-weight:700; cursor:pointer;">🗑️ تفريغ السلة</button>
        </div>
    </div>

    <div id="favoritesSidebar" class="favorites-sidebar">
        <div class="favorites-header" style="padding:15px; border-bottom:1px solid var(--cardBd); display:flex; justify-content:space-between; align-items:center;">
            <h3><i class="fas fa-heart" style="color:#ff4757;"></i> المفضلات</h3>
            <button onclick="toggleFavorites()" style="background:none; border:none; font-size:1.2rem; cursor:pointer; color:var(--t4);">✕</button>
        </div>
        <div class="favorites-items" id="favoritesItems" style="flex:1; overflow-y:auto; padding:10px;"></div>
        <div class="favorites-footer" style="padding:15px; border-top:1px solid var(--cardBd);">
            <button onclick="clearFavorites()" style="width:100%; padding:12px; border-radius:50px; background:transparent; border:1px solid var(--rd); color:var(--rd); font-weight:700; cursor:pointer;"><i class="fas fa-trash"></i> مسح الكل</button>
        </div>
    </div>

    <!-- ========== نافذة معلومات الاتصال ========== -->
    <div id="contactUsModal" class="modal-overlay" onclick="if(event.target===this) closeContactUsModal()">
        <div class="confirm-modal-container">
            <div class="confirm-modal-header" style="background: linear-gradient(135deg, var(--ac), var(--ac2));">
                <i class="fas fa-headset"></i>
                <h3>📞 اتصل بنا</h3>
            </div>
            <div class="confirm-modal-body" style="text-align: center;">
                <div style="margin-bottom: 15px;">
                    <i class="fas fa-store" style="font-size: 2rem; color: var(--ac);"></i>
                </div>
                <h3 style="color: var(--ac); margin-bottom: 10px;">Almulla</h3>
                <div style="background: var(--bg2); border-radius: 12px; padding: 15px; margin-top: 10px; text-align: right;">
                    <p><i class="fas fa-envelope" style="color: var(--ac); margin-left: 8px;"></i> <strong>البريد الإلكتروني:</strong> <a href="mailto:info@dhilalbarada.com" style="color: var(--ac);">info@dhilalbarada.com</a></p>
                    <p style="margin-top: 10px;"><i class="fab fa-whatsapp" style="color: #25D366; margin-left: 8px;"></i> <strong>واتساب:</strong> <a href="https://wa.me/9647732068081" style="color: var(--ac);">+964 77 320 68081</a></p>
                    <p style="margin-top: 10px;"><i class="fas fa-phone" style="color: var(--ac); margin-left: 8px;"></i> <strong>الهاتف:</strong> <a href="tel:+9647801099000" style="color: var(--ac);">+964 780 109 9000</a></p>
                    <p style="margin-top: 10px;"><i class="fas fa-map-marker-alt" style="color: var(--rd); margin-left: 8px;"></i> <strong>العنوان:</strong> بغداد - حي تونس - سريع محمد القاسم - الشارع الخدمي - مجاور شركة تويوتا</p>
                </div>
            </div>
            <div class="confirm-modal-footer">
                <button class="confirm-btn confirm-ok" onclick="closeContactUsModal()">
                    <i class="fas fa-check"></i> حسناً
                </button>
            </div>
        </div>
    </div>

    <!-- ========== نافذة تعديل الخدمة ========== -->
    <div id="editServiceModal" class="modal-overlay" onclick="if(event.target===this) closeEditServiceModal()">
        <div class="modal-container">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> تعديل الخدمة</h3>
                <button class="modal-close" onclick="closeEditServiceModal()">✕</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>اسم الخدمة</label>
                    <input type="text" id="editServiceName" class="modal-input" placeholder="اسم الخدمة">
                </div>
                <div class="form-group">
                    <label>اللون</label>
                    <input type="text" id="editServiceColor" class="modal-input" placeholder="مثال: أزرق, #6366f1">
                </div>
                <div class="form-group">
                    <label>وصف الخدمة</label>
                    <textarea id="editServiceNotes" class="modal-textarea" rows="3" placeholder="وصف الخدمة..."></textarea>
                </div>
                <div class="form-group">
                    <label>صورة الخدمة</label>
                    <label class="file-input-label" onclick="document.getElementById('editServiceImageInput').click()" style="display:block; background:var(--acSh); border:1px dashed var(--ac); border-radius:10px; padding:10px; text-align:center; cursor:pointer;">
                        <i class="fas fa-upload"></i> رفع صورة جديدة
                    </label>
                    <input type="file" id="editServiceImageInput" accept="image/*" style="display:none" onchange="previewEditServiceImage(this)">
                    <div id="editServiceImagePreview" class="image-preview-container" style="margin-top:10px; text-align:center;"></div>
                </div>
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer;">
                        <input type="checkbox" id="editServiceAvailable"> 
                        <span>الخدمة متوفرة</span>
                    </label>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" onclick="saveServiceChanges()" style="flex:1; padding:10px; background:var(--ac); color:white; border:none; border-radius:10px; cursor:pointer;">
                    <i class="fas fa-save"></i> حفظ التغييرات
                </button>
                <button class="btn btn-secondary" onclick="closeEditServiceModal()" style="flex:1; padding:10px; background:var(--bg2); border:1px solid var(--cardBd); color:var(--t2); border-radius:10px; cursor:pointer;">
                    <i class="fas fa-times"></i> إلغاء
                </button>
            </div>
        </div>
    </div>

    <!-- ========== نافذة تعديل الشركة ========== -->
    <div id="editCompanyModal" class="modal-overlay" onclick="if(event.target===this) closeEditCompanyModal()">
        <div class="modal-container">
            <div class="modal-header">
                <h3><i class="fas fa-edit"></i> تعديل الشركة</h3>
                <button class="modal-close" onclick="closeEditCompanyModal()">✕</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>اسم الشركة</label>
                    <input type="text" id="editCompanyName" class="modal-input" placeholder="اسم الشركة">
                </div>
                <div class="form-group">
                    <label>شعار الشركة</label>
                    <label class="file-input-label" onclick="document.getElementById('editCompanyLogoInput').click()" style="display:block; background:var(--acSh); border:1px dashed var(--ac); border-radius:10px; padding:10px; text-align:center; cursor:pointer;">
                        <i class="fas fa-upload"></i> رفع شعار جديد
                    </label>
                    <input type="file" id="editCompanyLogoInput" accept="image/*" style="display:none" onchange="previewEditCompanyLogo(this)">
                    <div id="editCompanyLogoPreview" class="image-preview-container" style="margin-top:10px; text-align:center;"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-primary" onclick="saveCompanyChanges()" style="flex:1; padding:10px; background:var(--ac); color:white; border:none; border-radius:10px; cursor:pointer;">
                    <i class="fas fa-save"></i> حفظ التغييرات
                </button>
                <button class="btn btn-secondary" onclick="closeEditCompanyModal()" style="flex:1; padding:10px; background:var(--bg2); border:1px solid var(--cardBd); color:var(--t2); border-radius:10px; cursor:pointer;">
                    <i class="fas fa-times"></i> إلغاء
                </button>
            </div>
        </div>
    </div>

    <!-- ========== نافذة تعديل معلومات المنتج ========== -->
    <div id="editProductInfoModal" class="modal-overlay" onclick="if(event.target===this) closeEditProductInfoModal()">
        <div class="modal-container" style="max-width: 550px;">
            <div class="modal-header">
                <h3><i class="fas fa-edit" style="color: var(--ac);"></i> تعديل معلومات المنتج</h3>
                <button class="modal-close" onclick="closeEditProductInfoModal()">✕</button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editProductInfoCatId">
                <input type="hidden" id="editProductInfoCompId">
                <input type="hidden" id="editProductInfoProductId">
                
                <div class="form-group">
                    <label><i class="fas fa-tag" style="color: var(--ac);"></i> اسم المنتج</label>
                    <input type="text" id="editProductInfoName" class="modal-input" placeholder="اسم المنتج">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-barcode" style="color: var(--ac);"></i> كود المنتج</label>
                    <input type="text" id="editProductInfoCode" class="modal-input" placeholder="كود المنتج">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-palette" style="color: var(--ac);"></i> اللون</label>
                    <input type="text" id="editProductInfoColor" class="modal-input" placeholder="اللون (مثال: رمادي، #808080)">
                </div>

                <!-- رابط الصورة -->
                <div class="form-group">
                    <label><i class="fas fa-link" style="color: var(--ac);"></i> رابط الصورة (URL)</label>
                    <input type="url" id="editProductInfoImageUrl" class="modal-input" placeholder="https://example.com/image.jpg">
                    <div style="font-size: 0.65rem; color: var(--t3); margin-top: 5px;">
                        <i class="fas fa-info-circle"></i> يمكنك إدخال رابط صورة خارجي بدلاً من رفع ملف
                    </div>
                </div>
                
                <!-- حالة التوفر -->
                <div class="form-group">
                    <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; padding: 10px; background: var(--bg2); border-radius: 10px; border: 1px solid var(--cardBd);">
                        <input type="checkbox" id="editProductInfoAvailable" style="width: 20px; height: 20px; cursor: pointer;">
                        <span style="font-weight: 700; font-size: 0.9rem;">
                            <i class="fas fa-check-circle" style="color: var(--gn);"></i> المنتج متوفر
                        </span>
                    </label>
                    <div style="font-size: 0.65rem; color: var(--t3); margin-top: 5px; padding-right: 10px;">
                        <i class="fas fa-info-circle"></i> عند إلغاء التحديد، سيظهر المنتج كـ "غير متوفر" للعملاء
                    </div>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-image" style="color: var(--ac);"></i> صورة المنتج (رفع ملف)</label>
                    <div id="editProductInfoCurrentImage" style="margin-bottom: 10px; display: flex; align-items: center; gap: 10px;">
                        <span style="font-size: 0.7rem; color: var(--t3);">الصورة الحالية:</span>
                        <img id="editProductInfoImagePreview" src="" style="width: 80px; height: 80px; border-radius: 12px; object-fit: cover; border: 2px solid var(--cardBd);">
                    </div>
                    <label class="file-input-label" onclick="document.getElementById('editProductInfoImageInput').click()" style="display: block; background: var(--acSh); border: 1px dashed var(--ac); border-radius: 10px; padding: 12px; text-align: center; cursor: pointer; transition: 0.2s;">
                        <i class="fas fa-upload" style="font-size: 1.2rem; display: block; margin-bottom: 4px;"></i>
                        <span style="font-size: 0.7rem;">رفع صورة جديدة (اختياري)</span>
                    </label>
                    <input type="file" id="editProductInfoImageInput" accept="image/*" style="display: none" onchange="previewEditProductInfoImage(this)">
                    <div id="editProductInfoNewImagePreview" class="image-preview-container" style="margin-top: 10px; text-align: center; display: none;">
                        <img src="" style="width: 80px; height: 80px; border-radius: 12px; object-fit: cover; border: 2px solid var(--gn);">
                        <div style="font-size: 0.6rem; color: var(--gn); margin-top: 4px;">✅ صورة جديدة</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeEditProductInfoModal()" style="flex: 1; padding: 10px; background: var(--bg2); border: 1px solid var(--cardBd); color: var(--t2); border-radius: 10px; cursor: pointer;">
                    <i class="fas fa-times"></i> إلغاء
                </button>
                <button class="btn btn-primary" onclick="saveEditProductInfo()" style="flex: 1; padding: 10px; background: var(--ac); color: white; border: none; border-radius: 10px; cursor: pointer;">
                    <i class="fas fa-save"></i> حفظ التغييرات
                </button>
            </div>
        </div>
    </div>

    <!-- ========== نافذة معاينة الصورة ========== -->
    <div id="imagePreviewModal" class="modal-overlay" onclick="closeImagePreview()">
        <div class="image-preview-container-modal">
            <button class="image-preview-close" onclick="closeImagePreview()">✕</button>
            <img id="previewImage" src="" alt="معاينة الصورة">
        </div>
    </div>

    <!-- ========== نافذة التأكيد ========== -->
    <div id="confirmModal" class="modal-overlay" onclick="if(event.target===this) closeConfirmModal()">
        <div class="confirm-modal-container">
            <div class="confirm-modal-header">
                <i class="fas fa-question-circle"></i>
                <h3 id="confirmModalTitle">تأكيد العملية</h3>
            </div>
            <div class="confirm-modal-body">
                <p id="confirmModalMessage">هل أنت متأكد من القيام بهذه العملية؟</p>
            </div>
            <div class="confirm-modal-footer">
                <button class="confirm-btn confirm-cancel" onclick="closeConfirmModal()">
                    <i class="fas fa-times"></i> إلغاء
                </button>
                <button class="confirm-btn confirm-ok" id="confirmOkBtn" onclick="executeConfirmedAction()">
                    <i class="fas fa-check"></i> موافق
                </button>
            </div>
        </div>
    </div>

    <!-- ========== نافذة تأكيد الخروج ========== -->
    <div id="logoutConfirmModal" class="modal-overlay" onclick="if(event.target===this) closeLogoutConfirm()">
        <div class="confirm-modal-container">
            <div class="confirm-modal-header" style="background: linear-gradient(135deg, var(--rd), #c0392b);">
                <i class="fas fa-sign-out-alt"></i>
                <h3>⚠️ تأكيد الخروج</h3>
            </div>
            <div class="confirm-modal-body">
                <p style="font-size: 1.1rem;">هل أنت متأكد من تسجيل الخروج؟</p>
                <p style="color: var(--t3); font-size: 0.85rem; margin-top: 10px;">
                    سيتم إنهاء جلستك الحالية وسيتم نقلك إلى صفحة تسجيل الدخول.
                </p>
            </div>
            <div class="confirm-modal-footer">
                <button class="confirm-btn confirm-cancel" onclick="closeLogoutConfirm()">
                    <i class="fas fa-times"></i> إلغاء
                </button>
                <button class="confirm-btn confirm-ok delete" onclick="confirmLogout()">
                    <i class="fas fa-sign-out-alt"></i> تأكيد الخروج
                </button>
            </div>
        </div>
    </div>

    <!-- ========== نافذة حذف الحساب ========== -->
    <div id="deleteAccountModal" class="modal-overlay" onclick="if(event.target===this) closeDeleteAccountModal()">
        <div class="confirm-modal-container">
            <div class="confirm-modal-header" style="background: linear-gradient(135deg, var(--rd), #c0392b);">
                <i class="fas fa-exclamation-triangle"></i>
                <h3>⚠️ تحذير: حذف الحساب</h3>
            </div>
            <div class="confirm-modal-body">
                <p style="margin-bottom: 15px;">هل أنت متأكد من حذف حسابك؟</p>
                <p class="warning-text" style="margin-bottom: 20px;">⚠️ هذا الإجراء لا يمكن التراجع عنه! سيتم حذف جميع بياناتك بشكل دائم.</p>
                <div class="delete-password-field">
                    <label style="display: block; text-align: right; margin-bottom: 8px; font-size: 0.8rem; font-weight: 600;">
                        <i class="fas fa-lock" style="margin-left: 5px;"></i> أدخل كلمة المرور لتأكيد الحذف:
                    </label>
                    <div class="input-wrapper">
                        <i class="fas fa-key"></i>
                        <input type="password" id="deletePasswordInput" class="modal-input" placeholder="كلمة المرور" style="text-align: right; width: 100%;">
                    </div>
                    <div id="deletePasswordError" style="color: var(--rd); font-size: 0.7rem; margin-top: 5px; display: none;">
                        <i class="fas fa-exclamation-circle"></i> كلمة المرور غير صحيحة
                    </div>
                </div>
            </div>
            <div class="confirm-modal-footer">
                <button class="confirm-btn confirm-cancel" onclick="closeDeleteAccountModal()">
                    <i class="fas fa-times"></i> إلغاء
                </button>
                <button class="confirm-btn confirm-ok delete" id="deleteAccountConfirmBtn" onclick="confirmDeleteAccount()">
                    <i class="fas fa-trash-alt"></i> تأكيد الحذف
                </button>
            </div>
        </div>
    </div>

    <!-- ========== نافذة تثبيت التطبيق ========== -->
    <div id="installAppModal" class="modal-overlay" onclick="if(event.target===this) closeInstallAppModal()">
        <div class="confirm-modal-container">
            <div class="confirm-modal-header" style="background: linear-gradient(135deg, var(--ac), var(--ac2));">
                <i class="fas fa-mobile-alt"></i>
                <h3>📱 تثبيت التطبيق</h3>
            </div>
            <div class="confirm-modal-body" style="text-align: center;">
                <p>هل تريد تثبيت تطبيق Almulla على جهازك؟</p>
                <div style="background: var(--bg2); border-radius: 12px; padding: 15px; margin-top: 10px;">
                    <i class="fas fa-star" style="color: var(--or);"></i>
                    <strong>مميزات التطبيق:</strong>
                    <ul style="text-align: right; margin-top: 10px; list-style: none;">
                        <li><i class="fas fa-check-circle" style="color: var(--gn);"></i> وصول سريع من سطح المكتب</li>
                        <li><i class="fas fa-check-circle" style="color: var(--gn);"></i> إشعارات فورية</li>
                        <li><i class="fas fa-check-circle" style="color: var(--gn);"></i> تجربة مستخدم محسنة</li>
                    </ul>
                </div>
            </div>
            <div class="confirm-modal-footer">
                <button class="confirm-btn confirm-cancel" onclick="closeInstallAppModal()">
                    <i class="fas fa-times"></i> تذكر لاحقاً
                </button>
                <button class="confirm-btn confirm-ok" onclick="installPwaConfirmed()">
                    <i class="fas fa-download"></i> تثبيت الآن
                </button>
            </div>
        </div>
    </div>

    <!-- ========== نافذة معلومات عن التطبيق ========== -->
    <div id="aboutAppModal" class="modal-overlay" onclick="if(event.target===this) closeAboutAppModal()">
        <div class="confirm-modal-container">
            <div class="confirm-modal-header" style="background: linear-gradient(135deg, var(--ac), var(--ac2));">
                <i class="fas fa-info-circle"></i>
                <h3>ℹ️ عن التطبيق</h3>
            </div>
            <div class="confirm-modal-body" style="text-align: center;">
                <div style="margin-bottom: 15px;">
                    <i class="fas fa-store" style="font-size: 3rem; color: var(--ac);"></i>
                </div>
                <h3 style="color: var(--ac); margin-bottom: 10px;">Almulla</h3>
                <p style="margin-bottom: 10px;">الإصدار: <strong>2.0.0</strong></p>
                <p style="margin-bottom: 15px;">منصة متكاملة للإدارة والتسوق</p>
                <div style="background: var(--bg2); border-radius: 12px; padding: 10px; margin-top: 10px;">
                    <i class="fas fa-code"></i> جميع الحقوق محفوظة © 2025
                </div>
                <div style="margin-top: 14px; font-size: 0.8rem;">
                    <a href="privacy-policy.php" target="_blank" style="color: var(--ac);"><i class="fas fa-shield-halved"></i> سياسة الخصوصية</a>
                    <span style="color: var(--t4);"> | </span>
                    <a href="terms.php" target="_blank" style="color: var(--ac);"><i class="fas fa-file-contract"></i> الشروط والأحكام</a>
                </div>
            </div>
            <div class="confirm-modal-footer">
                <button class="confirm-btn confirm-ok" onclick="closeAboutAppModal()">
                    <i class="fas fa-check"></i> حسناً
                </button>
            </div>
        </div>
    </div>

    <!-- ========== لوحة التحكم ========== -->
    <div id="adminPanel" class="admin-panel">
        <div class="admin-header">
            <h3><i class="fas fa-cog"></i> لوحة التحكم</h3>
            <button class="admin-close" onclick="closeAdminPanel()">✕</button>
        </div>
        <div style="padding: 10px 20px; text-align: center;">
            <a href="admin/index.php" target="_blank" style="display:inline-flex; align-items:center; gap:6px; font-size:0.75rem; color: var(--ac); background: var(--bg2); padding:8px 14px; border-radius:10px;">
                <i class="fas fa-up-right-from-square"></i> فتح لوحة التحكم الجديدة (صفحة مستقلة)
            </a>
        </div>

        <!-- إحصائيات التطبيق -->
        <div class="admin-section">
            <h4><i class="fas fa-chart-line"></i> إحصائيات التطبيق</h4>
            <div class="stats-grid" id="statsGrid">
                <div class="stat-card"><div class="stat-number" id="statUsers">0</div><div class="stat-label">المستخدمين</div></div>
                <div class="stat-card"><div class="stat-number" id="statCategories">0</div><div class="stat-label">الاقسام</div></div>
                <div class="stat-card"><div class="stat-number" id="statCompanies">0</div><div class="stat-label">الشركات</div></div>
                <div class="stat-card"><div class="stat-number" id="statProducts">0</div><div class="stat-label">المنتجات</div></div>
                <div class="stat-card"><div class="stat-number" id="statServices">0</div><div class="stat-label">المواد</div></div>
                <div class="stat-card"><div class="stat-number" id="statFavorites">0</div><div class="stat-label">المفضلات</div></div>
                <div class="stat-card"><div class="stat-number" id="statOrders">0</div><div class="stat-label">الطلبات</div></div>
                <div class="stat-card"><div class="stat-number" id="statVisitors">0</div><div class="stat-label">الزوار</div></div>
            </div>
            <button onclick="refreshStats()"><i class="fas fa-sync-alt"></i> تحديث الإحصائيات</button>
        </div>

        <!-- المواد الأكثر طلباً -->
        <div class="admin-section">
            <h4><i class="fas fa-trophy"></i> المواد الأكثر طلباً</h4>
            <div id="mostRequestedList" class="items-list"></div>
            <button onclick="clearMostRequested()" style="margin-top:8px; background:var(--rd);"><i class="fas fa-trash"></i> مسح جميع الإحصائيات</button>
        </div>

        <!-- إعدادات الأكثر طلباً -->
        <div class="admin-section">
            <h4><i class="fas fa-fire"></i> إعدادات الأكثر طلباً</h4>
            <div style="display:flex; align-items:center; gap:10px; padding:8px 0; flex-wrap:wrap;">
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:0.8rem;">
                    <input type="checkbox" id="hideMostRequested" onchange="toggleHideMostRequested()">
                    <i class="fas fa-eye-slash" style="color:var(--t3);"></i>
                    إخفاء بطاقة الأكثر طلباً من الواجهة الرئيسية
                </label>
            </div>
            <div style="font-size:0.65rem; color:var(--t3); padding:4px 0;">
                <i class="fas fa-info-circle"></i> عند التفعيل، ستختفي بطاقة الأكثر طلباً من الصفحة الرئيسية للعملاء
            </div>
        </div>

        <!-- البطاقة الترحيبية -->
        <div class="admin-section">
            <h4><i class="fas fa-greeting"></i> البطاقة الترحيبية</h4>
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:8px;">
                <label style="display:flex; align-items:center; gap:5px;">
                    <input type="checkbox" id="welcomeEnabled"> تفعيل البطاقة
                </label>
            </div>
            <input type="text" id="welcomeTitle" placeholder="عنوان الترحيب">
            <textarea id="welcomeMessage" placeholder="نص الترحيب" rows="2"></textarea>
            <input type="text" id="welcomeBtnText" placeholder="نص الزر">
            <input type="text" id="welcomeBtnLink" placeholder="رابط الزر">
            <input type="text" id="officialWebsite" placeholder="رابط الموقع الرسمي">
            <button onclick="saveWelcomeCardSettings()"><i class="fas fa-save"></i> حفظ إعدادات البطاقة</button>
        </div>

        <!-- إعدادات التطبيق -->
        <div class="admin-section">
            <h4><i class="fas fa-globe"></i> إعدادات التطبيق</h4>
            <input type="text" id="appNameInput" placeholder="اسم التطبيق">
            <input type="text" id="whatsappNumberInput" placeholder="رقم الواتساب">
            <button onclick="saveAppSettings()"><i class="fas fa-save"></i> حفظ الإعدادات</button>
        </div>

        <!-- إرسال إشعار -->
        <div class="admin-section">
            <h4><i class="fas fa-bell"></i> إرسال إشعار</h4>
            <input type="text" id="notifyTitle" placeholder="عنوان الإشعار">
            <textarea id="notifyBody" placeholder="محتوى الإشعار" rows="2"></textarea>
            <button onclick="sendNotificationToAll()"><i class="fas fa-paper-plane"></i> إرسال الإشعار</button>
        </div>

        <!-- المستخدمين -->
        <div class="admin-section">
            <h4><i class="fas fa-users"></i> المستخدمين</h4>
            <div id="usersList" class="users-list"></div>
        </div>

        <!-- إضافة فئة جديدة -->
        <div class="admin-section">
            <h4><i class="fas fa-folder-plus"></i> إضافة فئة جديدة</h4>
            <input type="text" id="newCatName" placeholder="اسم الفئة">
            <label class="file-input-label" onclick="document.getElementById('catImageInput').click()">📷 رفع صورة الفئة</label>
            <input type="file" id="catImageInput" accept="image/*" style="display:none" onchange="previewCategoryImage(this)">
            <div id="catImagePreview"></div>
            <button onclick="addCategory()"><i class="fas fa-plus"></i> إضافة فئة</button>
        </div>

        <!-- إضافة شركة جديدة -->
        <div class="admin-section">
            <h4><i class="fas fa-building"></i> إضافة شركة جديدة</h4>
            <select id="companyCatSelect">
                <option value="">اختر الفئة</option>
            </select>
            <input type="text" id="newCompanyName" placeholder="اسم الشركة">
            <label class="file-input-label" onclick="document.getElementById('companyLogoInput').click()">🏢 رفع شعار الشركة</label>
            <input type="file" id="companyLogoInput" accept="image/*" style="display:none" onchange="previewCompanyLogo(this)">
            <div id="companyLogoPreview"></div>
            <button onclick="addCompany()"><i class="fas fa-plus"></i> إضافة شركة</button>
        </div>

        <!-- إضافة منتج جديد -->
        <div class="admin-section">
            <h4><i class="fas fa-box"></i> إضافة منتج جديد</h4>
            <select id="productCatSelect">
                <option value="">اختر الفئة</option>
            </select>
            <select id="productCompSelect">
                <option value="">اختر الشركة</option>
            </select>
            <input type="text" id="newProductName" placeholder="اسم المنتج">
            <input type="text" id="newProductCode" placeholder="كود المنتج (اختياري)">
            <input type="text" id="newProductColor" placeholder="اللون (اختياري)">
            <label class="file-input-label" onclick="document.getElementById('productImageInput').click()">📦 رفع صورة المنتج</label>
            <input type="file" id="productImageInput" accept="image/*" style="display:none" onchange="previewProductImage(this)">
            <div id="productImagePreview"></div>
            <button onclick="addProduct()"><i class="fas fa-plus"></i> إضافة منتج</button>
        </div>

        <!-- إضافة خدمة مباشرة -->
        <div class="admin-section">
            <h4><i class="fas fa-concierge-bell"></i> إضافة خدمة مباشرة</h4>
            <select id="serviceCatSelect">
                <option value="">اختر الفئة</option>
            </select>
            <input type="text" id="newServiceName" placeholder="اسم الخدمة">
            <input type="text" id="newServiceColor" placeholder="اللون (اختياري)">
            <textarea id="newServiceNotes" placeholder="وصف الخدمة (اختياري)" rows="2"></textarea>
            <label class="file-input-label" onclick="document.getElementById('serviceImageInput').click()">🛎️ رفع صورة الخدمة</label>
            <input type="file" id="serviceImageInput" accept="image/*" style="display:none" onchange="previewServiceImage(this)">
            <div id="serviceImagePreview"></div>
            <button onclick="addService()"><i class="fas fa-plus"></i> إضافة خدمة</button>
        </div>

       

  
        <!-- جميع الأقسام والشركات والمواد -->
        <div class="admin-section">
            <h4><i class="fas fa-list"></i> جميع الأقسام والشركات والمواد</h4>
            <div id="adminCategoriesList" class="items-list"></div>
        </div>
    </div>

    <script>window.CSRF_TOKEN = "<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES, 'UTF-8'); ?>";</script>
    <script src="assets/js/app.js"></script>
</body>
</html>
