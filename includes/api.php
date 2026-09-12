<?php
// ================================================================
// موزّع طلبات API - يحل محل الكتلة الكبيرة inline في الملف الأصلي.
// كل نقطة نهاية (action) تحافظ على نفس الشكل والرسائل التي كان
// يعتمد عليها الواجهة الأمامية (assets/js/app.js) دون أي تغيير هناك،
// باستثناء نقاط الخدمات/المنتجات التي تحوّلت من الفهرس (index) إلى
// معرّف ثابت (id) لإصلاح خلل قديم (انظر ملاحظات التسليم).
// ================================================================

header('Content-Type: application/json');

$action = isset($_GET['action']) ? $_GET['action'] : '';

$rawBody = file_get_contents('php://input');
$input = json_decode($rawBody, true);
if (!is_array($input)) {
    $input = ($_SERVER['REQUEST_METHOD'] === 'POST') ? $_POST : [];
}

// نقاط القراءة فقط لا تحتاج CSRF (لا تغيّر أي حالة) ويمكن أن تصل عبر GET
$readOnlyActions = ['load_catalog', 'load_settings', 'get_whatsapp', 'get_most_requested', 'get_users', 'get_stats'];

if ($action && !in_array($action, $readOnlyActions, true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'message' => 'طريقة الطلب غير مسموحة']);
        exit;
    }
    verifyCsrfToken($input['csrf_token'] ?? '');
}

writeLog("طلب API: action=$action");

$pdo = getDb();

// ===== تسجيل مستخدم جديد =====
if ($action === 'register') {
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $fullname = trim($input['fullname'] ?? '');

    if (empty($fullname) || empty($email) || empty($password)) {
        echo json_encode(['success' => false, 'message' => 'يرجى ملء جميع الحقول']); exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success' => false, 'message' => 'البريد الإلكتروني غير صالح']); exit;
    }
    if (strlen($password) < 4) {
        echo json_encode(['success' => false, 'message' => 'كلمة المرور 4 أحرف على الأقل']); exit;
    }
    if (db_get_user_by_email($pdo, $email)) {
        echo json_encode(['success' => false, 'message' => 'البريد مسجل مسبقاً']); exit;
    }

    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $newId = db_create_user($pdo, $fullname, $email, $hashedPassword, false);
    echo json_encode(['success' => true, 'message' => 'تم إنشاء الحساب بنجاح', 'user' => ['id' => $newId, 'fullname' => $fullname, 'email' => $email]]);
    exit;
}

// ===== تسجيل دخول =====
if ($action === 'login') {
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';

    $user = db_get_user_by_email($pdo, $email);
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_name'] = $user['fullname'];
        $_SESSION['is_admin'] = (bool)$user['is_admin'];

        db_touch_last_login($pdo, $user['id']);
        echo json_encode(['success' => true, 'user' => db_public_user($user)]);
        exit;
    }
    echo json_encode(['success' => false, 'message' => 'البريد الإلكتروني أو كلمة المرور غير صحيحة']);
    exit;
}

// ===== تسجيل الخروج =====
if ($action === 'logout') {
    session_unset();
    session_destroy();
    echo json_encode(['success' => true, 'message' => 'تم تسجيل الخروج بنجاح']);
    exit;
}

// ===== تحديث الملف الشخصي =====
if ($action === 'update_profile') {
    requireAuth();
    $userId = $_SESSION['user_id'];
    $fullname = trim($input['fullname'] ?? '');
    $email = trim($input['email'] ?? '');
    $currentPassword = $input['currentPassword'] ?? '';
    $newPassword = $input['newPassword'] ?? '';

    $currentUser = db_get_user_by_id($pdo, $userId);
    if (!$currentUser) {
        echo json_encode(['success' => false, 'message' => 'المستخدم غير موجود']);
        exit;
    }

    if (!empty($newPassword)) {
        if (empty($currentPassword)) {
            echo json_encode(['success' => false, 'message' => 'يرجى إدخال كلمة المرور الحالية']);
            exit;
        }
        if (!password_verify($currentPassword, $currentUser['password'])) {
            echo json_encode(['success' => false, 'message' => 'كلمة المرور الحالية غير صحيحة']);
            exit;
        }
        if (strlen($newPassword) < 4) {
            echo json_encode(['success' => false, 'message' => 'كلمة المرور الجديدة 4 أحرف على الأقل']);
            exit;
        }
    }

    if (!empty($email) && $email !== $currentUser['email']) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['success' => false, 'message' => 'البريد الإلكتروني غير صالح']);
            exit;
        }
        $existing = db_get_user_by_email($pdo, $email);
        if ($existing && (int)$existing['id'] !== (int)$userId) {
            echo json_encode(['success' => false, 'message' => 'البريد الإلكتروني مستخدم من قبل']);
            exit;
        }
    }

    $fields = ['updated_at' => db_now()];
    if (!empty($fullname)) $fields['fullname'] = $fullname;
    if (!empty($email)) $fields['email'] = $email;
    if (!empty($newPassword)) $fields['password'] = password_hash($newPassword, PASSWORD_DEFAULT);

    db_update_user($pdo, $userId, $fields);
    $updated = db_get_user_by_id($pdo, $userId);

    $_SESSION['user_name'] = $updated['fullname'];
    $_SESSION['user_email'] = $updated['email'];

    echo json_encode([
        'success' => true,
        'message' => 'تم تحديث الملف الشخصي بنجاح',
        'user' => db_public_user($updated),
    ]);
    exit;
}

// ===== التحقق من كلمة المرور (نقطة كانت مفقودة تماماً في النسخة الأصلية) =====
if ($action === 'verify_password') {
    requireAuth();
    $password = $input['password'] ?? '';
    // نتحقق دائماً من صاحب الجلسة الحالية، ونتجاهل أي userId يرسله الطرف الآخر
    $currentUser = db_get_user_by_id($pdo, $_SESSION['user_id']);
    if ($currentUser && password_verify($password, $currentUser['password'])) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'كلمة المرور غير صحيحة']);
    }
    exit;
}

// ===== حذف الحساب =====
if ($action === 'delete_account') {
    requireAuth();
    $userId = $_SESSION['user_id'];
    $password = $input['password'] ?? '';

    $currentUser = db_get_user_by_id($pdo, $userId);
    if (!$currentUser) {
        echo json_encode(['success' => false, 'message' => 'المستخدم غير موجود']); exit;
    }
    if ($currentUser['is_admin'] && $currentUser['email'] === 'admin@Almulla.com') {
        echo json_encode(['success' => false, 'message' => 'لا يمكن حذف حساب المدير الرئيسي']); exit;
    }
    if (!password_verify($password, $currentUser['password'])) {
        echo json_encode(['success' => false, 'message' => 'كلمة المرور غير صحيحة']); exit;
    }

    db_delete_user($pdo, $userId);
    session_unset();
    session_destroy();

    echo json_encode(['success' => true, 'message' => 'تم حذف الحساب بنجاح']);
    exit;
}

// ===== الحصول على جميع المستخدمين =====
if ($action === 'get_users') {
    requireAdmin();
    echo json_encode(['success' => true, 'users' => db_list_users($pdo)]);
    exit;
}

// ===== حذف مستخدم =====
if ($action === 'delete_user') {
    requireAdmin();
    $userId = $input['id'] ?? 0;
    db_delete_user($pdo, $userId);
    echo json_encode(['success' => true]);
    exit;
}

// ===== تحميل الكتالوج =====
if ($action === 'load_catalog') {
    echo json_encode(['success' => true, 'catalog' => db_get_catalog_tree($pdo)]);
    exit;
}

// ===== حفظ الكتالوج (استبدال/مزامنة كاملة) =====
if ($action === 'save_catalog') {
    requireAdmin();
    $catalog = $input['catalog'] ?? ['categories' => []];
    db_sync_catalog($pdo, $catalog);
    echo json_encode(['success' => true]);
    exit;
}

// ===== تحديث معلومات المنتج (مع معالجة الصور بشكل صحيح) =====
if ($action === 'edit_product_info') {
    requireAdmin();
    $catId = (string)($input['catId'] ?? '');
    $compId = (string)($input['compId'] ?? '');
    $productId = (string)($input['productId'] ?? '');
    $productData = $input['product'] ?? [];

    if (empty($catId) || empty($compId) || empty($productId) || empty($productData)) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']);
        exit;
    }

    $result = db_edit_product_info($pdo, $catId, $compId, $productId, $productData);
    if ($result && isset($result['error'])) {
        echo json_encode(['success' => false, 'message' => $result['error']]);
    } elseif ($result) {
        echo json_encode([
            'success' => true,
            'message' => "تم تحديث معلومات المنتج '{$result['name']}' بنجاح",
            'oldImage' => $result['oldImage'],
            'newImage' => $result['newImage'],
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'المنتج غير موجود']);
    }
    exit;
}

// ===== تبديل حالة المنتج =====
if ($action === 'toggle_product_status') {
    requireAdmin();
    $catId = (string)($input['catId'] ?? '');
    $compId = (string)($input['compId'] ?? '');
    $productId = (string)($input['productId'] ?? '');

    if (empty($catId) || empty($compId) || empty($productId)) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']);
        exit;
    }

    $result = db_toggle_product_status($pdo, $catId, $compId, $productId);
    if ($result) {
        echo json_encode(['success' => true, 'message' => 'تم تحديث حالة المنتج', 'newStatus' => $result['newStatus'], 'productName' => $result['name']]);
    } else {
        echo json_encode(['success' => false, 'message' => 'المنتج غير موجود']);
    }
    exit;
}

// ===== إخفاء المنتج =====
if ($action === 'hide_product') {
    requireAdmin();
    $catId = (string)($input['catId'] ?? '');
    $compId = (string)($input['compId'] ?? '');
    $productId = (string)($input['productId'] ?? '');

    if (empty($catId) || empty($compId) || empty($productId)) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']);
        exit;
    }

    $name = db_set_product_deleted_card($pdo, $catId, $compId, $productId, 'ok');
    if ($name !== null) {
        echo json_encode(['success' => true, 'message' => "تم إخفاء المنتج: $name", 'productName' => $name]);
    } else {
        echo json_encode(['success' => false, 'message' => 'المنتج غير موجود']);
    }
    exit;
}

// ===== استعادة المنتج =====
if ($action === 'restore_product') {
    requireAdmin();
    $catId = (string)($input['catId'] ?? '');
    $compId = (string)($input['compId'] ?? '');
    $productId = (string)($input['productId'] ?? '');

    if (empty($catId) || empty($compId) || empty($productId)) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']);
        exit;
    }

    $name = db_set_product_deleted_card($pdo, $catId, $compId, $productId, 'no');
    if ($name !== null) {
        echo json_encode(['success' => true, 'message' => "تم استعادة المنتج: $name", 'productName' => $name]);
    } else {
        echo json_encode(['success' => false, 'message' => 'المنتج غير موجود']);
    }
    exit;
}

// ===== حذف المنتج نهائياً =====
if ($action === 'delete_product_permanent') {
    requireAdmin();
    $catId = (string)($input['catId'] ?? '');
    $compId = (string)($input['compId'] ?? '');
    $productId = (string)($input['productId'] ?? '');

    if (empty($catId) || empty($compId) || empty($productId)) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']);
        exit;
    }

    $name = db_delete_product_permanent($pdo, $catId, $compId, $productId);
    if ($name !== null) {
        echo json_encode(['success' => true, 'message' => "تم حذف المنتج نهائياً: $name", 'deletedName' => $name]);
    } else {
        echo json_encode(['success' => false, 'message' => 'المنتج غير موجود']);
    }
    exit;
}

// ===== تبديل حالة الخدمة =====
if ($action === 'toggle_service_status') {
    requireAdmin();
    $catId = (string)($input['catId'] ?? '');
    $serviceId = (string)($input['serviceId'] ?? '');

    if (empty($catId) || empty($serviceId)) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']);
        exit;
    }

    $result = db_toggle_service_status($pdo, $catId, $serviceId);
    if ($result) {
        echo json_encode(['success' => true, 'message' => 'تم تحديث حالة الخدمة', 'newStatus' => $result['newStatus'], 'serviceName' => $result['name']]);
    } else {
        echo json_encode(['success' => false, 'message' => 'الخدمة غير موجودة']);
    }
    exit;
}

// ===== إخفاء الخدمة =====
if ($action === 'hide_service') {
    requireAdmin();
    $catId = (string)($input['catId'] ?? '');
    $serviceId = (string)($input['serviceId'] ?? '');

    if (empty($catId) || empty($serviceId)) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']);
        exit;
    }

    $name = db_set_service_deleted_card($pdo, $catId, $serviceId, 'ok');
    if ($name !== null) {
        echo json_encode(['success' => true, 'message' => "تم إخفاء الخدمة: $name"]);
    } else {
        echo json_encode(['success' => false, 'message' => 'الخدمة غير موجودة']);
    }
    exit;
}

// ===== استعادة الخدمة =====
if ($action === 'restore_service') {
    requireAdmin();
    $catId = (string)($input['catId'] ?? '');
    $serviceId = (string)($input['serviceId'] ?? '');

    if (empty($catId) || empty($serviceId)) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']);
        exit;
    }

    $name = db_set_service_deleted_card($pdo, $catId, $serviceId, 'no');
    if ($name !== null) {
        echo json_encode(['success' => true, 'message' => "تم استعادة الخدمة: $name"]);
    } else {
        echo json_encode(['success' => false, 'message' => 'الخدمة غير موجودة']);
    }
    exit;
}

// ===== حذف الخدمة نهائياً =====
if ($action === 'delete_service_permanent') {
    requireAdmin();
    $catId = (string)($input['catId'] ?? '');
    $serviceId = (string)($input['serviceId'] ?? '');

    if (empty($catId) || empty($serviceId)) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']);
        exit;
    }

    $name = db_delete_service_permanent($pdo, $catId, $serviceId);
    if ($name !== null) {
        echo json_encode(['success' => true, 'message' => "تم حذف الخدمة نهائياً: $name"]);
    } else {
        echo json_encode(['success' => false, 'message' => 'الخدمة غير موجودة']);
    }
    exit;
}

// ===== إخفاء الشركة =====
if ($action === 'hide_company') {
    requireAdmin();
    $catId = (string)($input['catId'] ?? '');
    $compId = (string)($input['compId'] ?? '');

    if (empty($catId) || empty($compId)) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']);
        exit;
    }

    $name = db_hide_company($pdo, $catId, $compId);
    if ($name !== null) {
        echo json_encode(['success' => true, 'message' => "تم إخفاء الشركة: $name"]);
    } else {
        echo json_encode(['success' => false, 'message' => 'الشركة غير موجودة']);
    }
    exit;
}

// ===== استعادة الشركة =====
if ($action === 'restore_company') {
    requireAdmin();
    $catId = (string)($input['catId'] ?? '');
    $compId = (string)($input['compId'] ?? '');

    if (empty($catId) || empty($compId)) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']);
        exit;
    }

    $name = db_restore_company($pdo, $catId, $compId);
    if ($name !== null) {
        echo json_encode(['success' => true, 'message' => "تم استعادة الشركة: $name"]);
    } else {
        echo json_encode(['success' => false, 'message' => 'الشركة غير موجودة']);
    }
    exit;
}

// ===== حذف الشركة نهائياً =====
if ($action === 'delete_company_permanent') {
    requireAdmin();
    $catId = (string)($input['catId'] ?? '');
    $compId = (string)($input['compId'] ?? '');

    if (empty($catId) || empty($compId)) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']);
        exit;
    }

    $name = db_delete_company_permanent($pdo, $catId, $compId);
    if ($name !== null) {
        echo json_encode(['success' => true, 'message' => "تم حذف الشركة نهائياً: $name"]);
    } else {
        echo json_encode(['success' => false, 'message' => 'الشركة غير موجودة']);
    }
    exit;
}

// ===== إخفاء الفئة =====
if ($action === 'hide_category') {
    requireAdmin();
    $catId = (string)($input['catId'] ?? '');

    if (empty($catId)) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']);
        exit;
    }

    $name = db_hide_category($pdo, $catId);
    if ($name !== null) {
        echo json_encode(['success' => true, 'message' => "تم إخفاء الفئة: $name"]);
    } else {
        echo json_encode(['success' => false, 'message' => 'الفئة غير موجودة']);
    }
    exit;
}

// ===== استعادة الفئة =====
if ($action === 'restore_category') {
    requireAdmin();
    $catId = (string)($input['catId'] ?? '');

    if (empty($catId)) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']);
        exit;
    }

    $name = db_restore_category($pdo, $catId);
    if ($name !== null) {
        echo json_encode(['success' => true, 'message' => "تم استعادة الفئة: $name"]);
    } else {
        echo json_encode(['success' => false, 'message' => 'الفئة غير موجودة']);
    }
    exit;
}

// ===== حذف الفئة نهائياً =====
if ($action === 'delete_category_permanent') {
    requireAdmin();
    $catId = (string)($input['catId'] ?? '');

    if (empty($catId)) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']);
        exit;
    }

    $name = db_delete_category_permanent($pdo, $catId);
    if ($name !== null) {
        echo json_encode(['success' => true, 'message' => "تم حذف الفئة نهائياً: $name"]);
    } else {
        echo json_encode(['success' => false, 'message' => 'الفئة غير موجودة']);
    }
    exit;
}

// ===== تحديث الشركة =====
if ($action === 'update_company') {
    requireAdmin();
    $catId = (string)($input['catId'] ?? '');
    $compId = (string)($input['compId'] ?? '');
    $companyData = $input['company'] ?? [];

    if (empty($catId) || empty($compId) || empty($companyData)) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']);
        exit;
    }

    $ok = db_update_company($pdo, $catId, $compId, $companyData);
    if ($ok) {
        echo json_encode(['success' => true, 'message' => 'تم تحديث الشركة بنجاح']);
    } else {
        echo json_encode(['success' => false, 'message' => 'الشركة غير موجودة']);
    }
    exit;
}

// ===== تحديث الخدمة =====
if ($action === 'update_service') {
    requireAdmin();
    $catId = (string)($input['catId'] ?? '');
    $serviceId = (string)($input['serviceId'] ?? '');
    $serviceData = $input['service'] ?? [];

    if (empty($catId) || empty($serviceId) || empty($serviceData)) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']);
        exit;
    }

    $ok = db_update_service($pdo, $catId, $serviceId, $serviceData);
    if ($ok) {
        echo json_encode(['success' => true, 'message' => 'تم تحديث الخدمة بنجاح']);
    } else {
        echo json_encode(['success' => false, 'message' => 'الخدمة غير موجودة']);
    }
    exit;
}

// ===== حذف الصورة =====
if ($action === 'delete_image') {
    requireAdmin();
    $filename = $input['filename'] ?? '';
    if (!empty($filename)) {
        deleteOldImage($filename);
        echo json_encode(['success' => true, 'message' => 'تم حذف الصورة']);
    } else {
        echo json_encode(['success' => false, 'message' => 'اسم الملف مطلوب']);
    }
    exit;
}

// ===== تحميل الإعدادات =====
if ($action === 'load_settings') {
    echo json_encode(['success' => true, 'settings' => db_get_settings($pdo)]);
    exit;
}

// ===== حفظ الإعدادات =====
if ($action === 'save_settings') {
    requireAdmin();
    db_save_settings($pdo, $input);
    echo json_encode(['success' => true]);
    exit;
}

// ===== الحصول على رقم الواتساب =====
if ($action === 'get_whatsapp') {
    $settings = db_get_settings($pdo);
    $whatsappNumber = $settings['whatsappNumber'] ?: '966555555555';
    echo json_encode(['success' => true, 'whatsappNumber' => $whatsappNumber]);
    exit;
}

// ===== الحصول على المواد الأكثر طلباً =====
if ($action === 'get_most_requested') {
    echo json_encode([
        'success' => true,
        'items' => db_get_most_requested($pdo),
        'settings' => db_get_settings($pdo),
    ]);
    exit;
}

// ===== حذف عنصر من الأكثر طلباً (نقطة كانت مفقودة تماماً في النسخة الأصلية) =====
if ($action === 'delete_most_requested') {
    requireAdmin();
    $itemName = $input['itemName'] ?? '';
    $categoryName = $input['categoryName'] ?? '';
    if (empty($itemName) || empty($categoryName)) {
        echo json_encode(['success' => false, 'message' => 'بيانات غير صالحة']);
        exit;
    }
    db_delete_most_requested($pdo, $itemName, $categoryName);
    echo json_encode(['success' => true]);
    exit;
}

// ===== إخفاء/إظهار بطاقة الأكثر طلباً (نقطة كانت مفقودة تماماً في النسخة الأصلية) =====
if ($action === 'toggle_hide_most_requested') {
    requireAdmin();
    db_save_settings($pdo, ['hideMostRequested' => !empty($input['hide'])]);
    echo json_encode(['success' => true]);
    exit;
}

// ===== تسجيل طلب خدمة =====
if ($action === 'track_service_request') {
    $serviceName = $input['serviceName'] ?? '';
    $categoryName = $input['categoryName'] ?? '';
    if ($serviceName !== '' && $categoryName !== '') {
        db_track_item_request($pdo, $serviceName, $categoryName, 'service');
    }
    echo json_encode(['success' => true]);
    exit;
}

// ===== تسجيل طلب منتج =====
if ($action === 'track_product_request') {
    $productName = $input['productName'] ?? '';
    $categoryName = $input['categoryName'] ?? '';
    if ($productName !== '' && $categoryName !== '') {
        db_track_item_request($pdo, $productName, $categoryName, 'product');
    }
    echo json_encode(['success' => true]);
    exit;
}

// ===== الحصول على الإحصائيات =====
if ($action === 'get_stats') {
    requireAdmin();
    echo json_encode(['success' => true, 'stats' => db_get_full_stats($pdo)]);
    exit;
}

// ===== تحديث إحصائيات المفضلات =====
if ($action === 'update_favorites_stats') {
    $favoritesCount = (int)($input['count'] ?? 0);
    db_update_favorites_stats($pdo, $favoritesCount);
    echo json_encode(['success' => true]);
    exit;
}

// ===== تحديث إحصائيات الطلبات =====
if ($action === 'update_orders_stats') {
    db_update_orders_stats($pdo);
    echo json_encode(['success' => true]);
    exit;
}

// ===== تسجيل زيارة =====
if ($action === 'track_visit') {
    db_track_visit($pdo);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'طلب غير معروف']);
exit;
