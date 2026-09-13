<?php
// ================================================================
// طبقة الوصول إلى البيانات (MySQL) - تحل محل loadData()/saveData()
// القديمتين اللتين كانتا تقرأان/تكتبان logs/database.json بالكامل
// في كل عملية. كل دالة هنا تنفذ فقط الاستعلامات التي تحتاجها.
// ================================================================

function db_now() {
    return date('Y-m-d H:i:s');
}

// ---------------------------------------------------------------
// المستخدمون
// ---------------------------------------------------------------
function db_get_user_by_email(PDO $pdo, string $email): ?array {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function db_get_user_by_id(PDO $pdo, $id): ?array {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function db_create_user(PDO $pdo, string $fullname, string $email, string $passwordHash, bool $isAdmin = false): int {
    $stmt = $pdo->prepare('INSERT INTO users (fullname, email, password, is_admin, created_at) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$fullname, $email, $passwordHash, $isAdmin ? 1 : 0, db_now()]);
    return (int)$pdo->lastInsertId();
}

function db_update_user(PDO $pdo, $id, array $fields): void {
    if (empty($fields)) return;
    $sets = [];
    $params = [];
    foreach ($fields as $col => $val) {
        $sets[] = "$col = ?";
        $params[] = $val;
    }
    $params[] = $id;
    $pdo->prepare('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
}

// يتحقق من كلمة مرور المستخدم. يدعم أيضاً حسابات قديمة مخزَّنة بتجزئة MD5 خام (وليس
// بصيغة password_hash المعتادة) - مشكلة بيانات موجودة مسبقاً في بعض الحسابات القديمة (مثل
// admin@almulla.com) على الأرجح من أداة استيراد أو تعديل يدوي قبل هذا المشروع. عند نجاح
// الدخول بكلمة مرور تطابق التجزئة القديمة، تُرقّى تلقائياً وبصمت إلى تجزئة آمنة حديثة.
function verifyUserPassword(PDO $pdo, array $user, string $password): bool {
    if (password_verify($password, $user['password'])) {
        return true;
    }

    $hash = $user['password'] ?? '';
    $isLegacyMd5 = is_string($hash) && strlen($hash) === 32 && ctype_xdigit($hash);
    if ($isLegacyMd5 && hash_equals(strtolower($hash), md5($password))) {
        db_update_user($pdo, $user['id'], ['password' => password_hash($password, PASSWORD_DEFAULT)]);
        return true;
    }

    return false;
}

function db_touch_last_login(PDO $pdo, $id): void {
    $pdo->prepare('UPDATE users SET last_login = ? WHERE id = ?')->execute([db_now(), $id]);
}

function db_delete_user(PDO $pdo, $id): void {
    $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
}

function db_list_users(PDO $pdo): array {
    $rows = $pdo->query('SELECT id, fullname, email, is_admin, created_at, last_login FROM users ORDER BY id ASC')->fetchAll();
    return array_map(function ($u) {
        return [
            'id' => (int)$u['id'],
            'fullname' => $u['fullname'],
            'email' => $u['email'],
            'isAdmin' => (bool)$u['is_admin'],
            'created_at' => $u['created_at'],
            'last_login' => $u['last_login'],
        ];
    }, $rows);
}

function db_count_users(PDO $pdo): int {
    return (int)$pdo->query('SELECT COUNT(*) AS c FROM users')->fetch()['c'];
}

function db_count_admins(PDO $pdo): int {
    return (int)$pdo->query('SELECT COUNT(*) AS c FROM users WHERE is_admin = 1')->fetch()['c'];
}

function db_public_user(array $u): array {
    return [
        'id' => (int)$u['id'],
        'fullname' => $u['fullname'],
        'email' => $u['email'],
        'isAdmin' => (bool)$u['is_admin'],
    ];
}

// ---------------------------------------------------------------
// الإعدادات
// ---------------------------------------------------------------
function db_ensure_settings_row(PDO $pdo): void {
    $pdo->exec("INSERT IGNORE INTO settings (id, app_name, app_logo, whatsapp_number, official_website, hide_most_requested, welcome_card) VALUES (1, 'Almulla', 'https://iili.io/CKP5shF.jpg', '966555555555', 'Almulla.com', 0, " . $pdo->quote(json_encode([
        'enabled' => true,
        'title' => 'مرحباً بك في Almulla',
        'message' => 'اكتشف أفضل المواد والمنتجات مع عروضنا الحصرية',
        'buttonText' => 'تصفح الآن',
        'buttonLink' => '#',
        'animationSpeed' => 30,
    ], JSON_UNESCAPED_UNICODE)) . ')');
}

function db_get_settings(PDO $pdo): array {
    db_ensure_settings_row($pdo);
    $row = $pdo->query('SELECT * FROM settings WHERE id = 1')->fetch();
    $welcomeCard = null;
    if (!empty($row['welcome_card'])) {
        $welcomeCard = json_decode($row['welcome_card'], true);
    }
    $appLogo = $row['app_logo'];
    if (!empty($appLogo) && strpos($appLogo, 'http') !== 0) {
        $appLogo = !empty($row['app_logo_data']) ? 'index.php?image=logo' : null;
    }
    return [
        'appName' => $row['app_name'],
        'appLogo' => $appLogo,
        'whatsappNumber' => $row['whatsapp_number'],
        'officialWebsite' => $row['official_website'],
        'hideMostRequested' => (bool)$row['hide_most_requested'],
        'welcomeCard' => $welcomeCard,
    ];
}

function db_save_settings(PDO $pdo, array $input): void {
    db_ensure_settings_row($pdo);
    $sets = [];
    $params = [];

    // الشعار يُخزَّن كبيانات BLOB مباشرة داخل MySQL (app_logo_data/app_logo_mime)، وعمود
    // app_logo نفسه يبقى اسماً تعريفياً فقط (أو رابطاً خارجياً كما هو). appLogoBlob: بيانات
    // مُتحقَّق منها مسبقاً (رفع ملف من لوحة التحكم)؛ appLogo: رابط/اسم أو data: base64 (من SPA)
    if (isset($input['appLogoBlob']) && is_array($input['appLogoBlob'])) {
        $sets[] = 'app_logo = ?';
        $params[] = newImageFilename('logo', $input['appLogoBlob']['mime']);
        $sets[] = 'app_logo_data = ?';
        $params[] = $input['appLogoBlob']['data'];
        $sets[] = 'app_logo_mime = ?';
        $params[] = $input['appLogoBlob']['mime'];
    } elseif (isset($input['appLogo'])) {
        $row = $pdo->query('SELECT app_logo, app_logo_data, app_logo_mime FROM settings WHERE id = 1')->fetch();
        $existing = ['name' => $row['app_logo'] ?? null, 'data' => $row['app_logo_data'] ?? null, 'mime' => $row['app_logo_mime'] ?? null];
        $resolved = resolveImageBlobField($input['appLogo'], $existing, 'logo');
        $sets[] = 'app_logo = ?';
        $params[] = $resolved['name'];
        $sets[] = 'app_logo_data = ?';
        $params[] = $resolved['data'];
        $sets[] = 'app_logo_mime = ?';
        $params[] = $resolved['mime'];
    }

    $map = [
        'appName' => 'app_name',
        'whatsappNumber' => 'whatsapp_number',
        'officialWebsite' => 'official_website',
    ];
    foreach ($map as $key => $col) {
        if (isset($input[$key])) {
            $sets[] = "$col = ?";
            $params[] = $input[$key];
        }
    }
    if (isset($input['hideMostRequested'])) {
        $sets[] = 'hide_most_requested = ?';
        $params[] = !empty($input['hideMostRequested']) ? 1 : 0;
    }
    if (isset($input['welcomeCard'])) {
        $sets[] = 'welcome_card = ?';
        $params[] = json_encode($input['welcomeCard'], JSON_UNESCAPED_UNICODE);
    }
    if (empty($sets)) return;
    $pdo->prepare('UPDATE settings SET ' . implode(', ', $sets) . ' WHERE id = 1')->execute($params);
}

// ---------------------------------------------------------------
// الإحصائيات
// ---------------------------------------------------------------
function db_ensure_stats_row(PDO $pdo): void {
    $pdo->exec('INSERT IGNORE INTO stats (id, total_visitors, total_favorites, total_orders) VALUES (1, 0, 0, 0)');
}

function db_track_visit(PDO $pdo): void {
    db_ensure_stats_row($pdo);
    $pdo->exec('UPDATE stats SET total_visitors = total_visitors + 1 WHERE id = 1');
    $today = date('Y-m-d');
    $stmt = $pdo->prepare('INSERT INTO daily_visits (visit_date, count) VALUES (?, 1) ON DUPLICATE KEY UPDATE count = count + 1');
    $stmt->execute([$today]);
}

function db_update_favorites_stats(PDO $pdo, int $count): void {
    db_ensure_stats_row($pdo);
    $pdo->prepare('UPDATE stats SET total_favorites = ? WHERE id = 1')->execute([$count]);
}

function db_update_orders_stats(PDO $pdo): void {
    db_ensure_stats_row($pdo);
    $pdo->exec('UPDATE stats SET total_orders = total_orders + 1 WHERE id = 1');
}

function db_get_full_stats(PDO $pdo): array {
    db_ensure_stats_row($pdo);
    $stats = $pdo->query('SELECT total_visitors, total_favorites, total_orders FROM stats WHERE id = 1')->fetch();

    $totalCategories = (int)$pdo->query("SELECT COUNT(*) AS c FROM categories")->fetch()['c'];
    $totalCompanies = (int)$pdo->query("SELECT COUNT(*) AS c FROM companies co JOIN categories ca ON co.category_id = ca.id WHERE ca.deleted_card <> 'ok'")->fetch()['c'];
    $totalServices = (int)$pdo->query("SELECT COUNT(*) AS c FROM services s JOIN categories ca ON s.category_id = ca.id WHERE ca.deleted_card <> 'ok'")->fetch()['c'];
    $totalProducts = (int)$pdo->query("
        SELECT COUNT(*) AS c FROM products p
        JOIN companies co ON p.company_id = co.id
        JOIN categories ca ON co.category_id = ca.id
        WHERE ca.deleted_card <> 'ok' AND co.deleted_card <> 'ok'
    ")->fetch()['c'];

    return [
        'total_users' => db_count_users($pdo),
        'total_categories' => $totalCategories,
        'total_companies' => $totalCompanies,
        'total_products' => $totalProducts,
        'total_services' => $totalServices,
        'total_favorites' => (int)$stats['total_favorites'],
        'total_orders' => (int)$stats['total_orders'],
        'total_visitors' => (int)$stats['total_visitors'],
    ];
}

// ---------------------------------------------------------------
// الأكثر طلباً
// ---------------------------------------------------------------
function db_track_item_request(PDO $pdo, string $name, string $category, string $type): void {
    $stmt = $pdo->prepare('
        INSERT INTO most_requested_items (name, category, type, count) VALUES (?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE count = count + 1
    ');
    $stmt->execute([$name, $category, $type]);

    // إبقاء أعلى 20 عنصراً فقط (نفس سلوك النسخة الأصلية)
    $pdo->exec('
        DELETE FROM most_requested_items WHERE id NOT IN (
            SELECT id FROM (SELECT id FROM most_requested_items ORDER BY count DESC, id ASC LIMIT 20) t
        )
    ');
}

function db_get_most_requested(PDO $pdo): array {
    $rows = $pdo->query('SELECT name, category, type, count FROM most_requested_items ORDER BY count DESC, id ASC LIMIT 20')->fetchAll();
    return array_map(function ($r) {
        return [
            'name' => $r['name'],
            'category' => $r['category'],
            'type' => $r['type'],
            'count' => (int)$r['count'],
        ];
    }, $rows);
}

function db_delete_most_requested(PDO $pdo, string $name, string $category): void {
    $pdo->prepare('DELETE FROM most_requested_items WHERE name = ? AND category = ?')->execute([$name, $category]);
}

// يجمع كل أسماء ملفات الصور التي يشير إليها الكتالوج الحالي فعلياً (فئات، شركات، منتجات،
// خدمات، شعار الموقع) دون تكرار. تُستخدم لمعرفة أي الصور ما زال محتواها ناقصاً داخل MySQL
// بعد استيراد بيانات تشير للصور بالاسم فقط (بلا محتوى الصورة نفسه).
function db_list_referenced_images(PDO $pdo): array {
    $names = [];
    $queries = [
        "SELECT image AS n FROM categories WHERE image IS NOT NULL AND image <> '' AND image NOT LIKE 'http%'",
        "SELECT logo AS n FROM companies WHERE logo IS NOT NULL AND logo <> '' AND logo NOT LIKE 'http%'",
        "SELECT img AS n FROM products WHERE img IS NOT NULL AND img <> '' AND img NOT LIKE 'http%'",
        "SELECT img AS n FROM services WHERE img IS NOT NULL AND img <> '' AND img NOT LIKE 'http%'",
        "SELECT app_logo AS n FROM settings WHERE id = 1 AND app_logo IS NOT NULL AND app_logo <> '' AND app_logo NOT LIKE 'http%'",
    ];
    foreach ($queries as $sql) {
        foreach ($pdo->query($sql)->fetchAll() as $row) {
            $names[] = $row['n'];
        }
    }
    return array_values(array_unique($names));
}

// يعدّ كم صورة من الصور التي يشير إليها الكتالوج الحالي (عبر db_list_referenced_images)
// أصبح محتواها الفعلي مخزَّناً في MySQL (img_data/logo_data/... ليس NULL) مقابل ما زال ناقصاً
function db_count_stored_images(PDO $pdo): array {
    $referenced = db_list_referenced_images($pdo);
    $total = count($referenced);
    if ($total === 0) return ['total' => 0, 'stored' => 0, 'missing' => 0];

    $ph = implode(',', array_fill(0, $total, '?'));
    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM categories WHERE image IN ($ph) AND image_data IS NOT NULL");
    $stmt->execute($referenced); $stored = (int)$stmt->fetch()['c'];

    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM companies WHERE logo IN ($ph) AND logo_data IS NOT NULL");
    $stmt->execute($referenced); $stored += (int)$stmt->fetch()['c'];

    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM products WHERE img IN ($ph) AND img_data IS NOT NULL");
    $stmt->execute($referenced); $stored += (int)$stmt->fetch()['c'];

    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM services WHERE img IN ($ph) AND img_data IS NOT NULL");
    $stmt->execute($referenced); $stored += (int)$stmt->fetch()['c'];

    $stmt = $pdo->prepare("SELECT COUNT(*) c FROM settings WHERE id = 1 AND app_logo IN ($ph) AND app_logo_data IS NOT NULL");
    $stmt->execute($referenced); $stored += (int)$stmt->fetch()['c'];

    return ['total' => $total, 'stored' => $stored, 'missing' => $total - $stored];
}

// يجلب بيانات صورة واحدة (ثنائية + نوعها) لعرضها عبر نقطة index.php?image=... . يُستخدم
// من نقطة العرض فقط، لذلك $type يأتي من قائمة ثابتة داخل index.php وليس من مُدخل حر.
function db_get_image_blob(PDO $pdo, string $type, string $id): ?array {
    $map = [
        'category' => ['categories', 'image_data', 'image_mime'],
        'company' => ['companies', 'logo_data', 'logo_mime'],
        'product' => ['products', 'img_data', 'img_mime'],
        'service' => ['services', 'img_data', 'img_mime'],
    ];
    if (!isset($map[$type])) return null;
    [$table, $dataCol, $mimeCol] = $map[$type];
    $stmt = $pdo->prepare("SELECT $dataCol AS data, $mimeCol AS mime FROM $table WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row || empty($row['data'])) return null;
    return ['data' => $row['data'], 'mime' => $row['mime'] ?: 'image/jpeg'];
}

function db_get_logo_blob(PDO $pdo): ?array {
    $row = $pdo->query('SELECT app_logo_data AS data, app_logo_mime AS mime FROM settings WHERE id = 1')->fetch();
    if (!$row || empty($row['data'])) return null;
    return ['data' => $row['data'], 'mime' => $row['mime'] ?: 'image/jpeg'];
}

// ---------------------------------------------------------------
// أدوات مساعدة للكتالوج
// ---------------------------------------------------------------
function fetchOneValue(PDO $pdo, string $sql, array $params, string $col) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ? $row[$col] : null;
}

// يقرر القيم النهائية لحقل صورة عند التزامن/التعديل: صورة base64 جديدة تُفكّ وتُتحقق ثم
// تُعاد كبيانات BLOB جاهزة للتخزين المباشر في MySQL باسم تعريفي جديد، أو تبقى القيم
// الحالية (اسم + بيانات) كما هي إن لم تُرسل صورة جديدة أو كان الاسم نفسه لم يتغيّر، أو
// اسم/رابط مختلف بلا بيانات بعد (بانتظار مزامنة الصور الفعلية).
// $existing = ['name' => ?string, 'data' => ?string, 'mime' => ?string]
//
// ملاحظة مهمة: الواجهة (save_catalog) تُعيد إرسال الشجرة كاملة في كل حفظ، بما فيها حقول
// صور عناصر لم تتغيّر إطلاقاً - وقيمتها عندها هي رابط العرض الذي ولّدناه نحن في
// catalogImageUrl() (مثل index.php?image=product&id=x)، وليس الاسم المجرّد المخزَّن. لذا
// أي قيمة بهذا الشكل تُعامَل دائماً على أنها "بلا تغيير"، وإلا لكانت كل عملية حفظ تمسح
// بيانات الصورة الفعلية لكل عنصر لم تُعدَّل صورته.
function resolveImageBlobField($newValue, array $existing, string $prefix): array {
    if (!empty($newValue) && is_string($newValue) && strpos($newValue, 'data:image') === 0) {
        $decoded = decodeImageBase64($newValue);
        if ($decoded) {
            return ['name' => newImageFilename($prefix, $decoded['mime']), 'data' => $decoded['data'], 'mime' => $decoded['mime']];
        }
        return $existing; // فشل فك الصورة الجديدة: أبقِ كل شيء كما هو
    }
    $isOwnDisplayUrl = is_string($newValue) && strpos($newValue, 'index.php?image=') === 0;
    if ($newValue === null || $newValue === '' || $newValue === ($existing['name'] ?? null) || $isOwnDisplayUrl) {
        return $existing; // لا تغيير فعلي
    }
    // اسم/رابط مختلف تماماً (مثلاً استيراد جديد يشير لصورة لم تُخزَّن بياناتها بعد)
    return ['name' => $newValue, 'data' => null, 'mime' => null];
}

function normalizeDeletedCard($value): string {
    return ($value === 'ok') ? 'ok' : 'no';
}

// يحوّل قيمة عمود صورة مخزَّنة (اسم مجرّد أو رابط خارجي) إلى رابط جاهز للعرض مباشرة في
// الواجهة: رابط خارجي يبقى كما هو، أي قيمة أخرى (اسم ملف) تتحول لرابط نقطة عرض BLOB حسب
// النوع والمعرّف. القيمة الفارغة تبقى فارغة (تُستخدم في الواجهة كمؤشر "لا توجد صورة")
function catalogImageUrl($storedValue, string $type, string $id): ?string {
    if (empty($storedValue)) return null;
    if (strpos($storedValue, 'http://') === 0 || strpos($storedValue, 'https://') === 0) {
        return $storedValue;
    }
    return 'index.php?image=' . $type . '&id=' . urlencode($id);
}

// ---------------------------------------------------------------
// قراءة شجرة الكتالوج الكاملة (تُستخدم في load_catalog)
// ---------------------------------------------------------------
function db_get_catalog_tree(PDO $pdo): array {
    $categories = [];
    $catRows = $pdo->query('SELECT * FROM categories ORDER BY sort_order ASC, created_at ASC')->fetchAll();

    $compStmt = $pdo->prepare('SELECT * FROM companies WHERE category_id = ? ORDER BY sort_order ASC, created_at ASC');
    $prodStmt = $pdo->prepare('SELECT * FROM products WHERE company_id = ? ORDER BY sort_order ASC, created_at ASC');
    $svcStmt = $pdo->prepare('SELECT * FROM services WHERE category_id = ? ORDER BY sort_order ASC, created_at ASC');

    foreach ($catRows as $cat) {
        $companies = [];
        $compStmt->execute([$cat['id']]);
        foreach ($compStmt->fetchAll() as $comp) {
            $prodStmt->execute([$comp['id']]);
            $products = [];
            foreach ($prodStmt->fetchAll() as $p) {
                $products[] = [
                    'id' => $p['id'],
                    'name' => $p['name'],
                    'code' => $p['code'],
                    'color' => $p['color'],
                    'price' => $p['price'],
                    'img' => catalogImageUrl($p['img'], 'product', $p['id']),
                    'image_url' => $p['image_url'],
                    'available' => (bool)$p['available'],
                    'deletedCard' => $p['deleted_card'],
                ];
            }
            $companies[] = [
                'id' => $comp['id'],
                'name' => $comp['name'],
                'logo' => catalogImageUrl($comp['logo'], 'company', $comp['id']),
                'deletedCard' => $comp['deleted_card'],
                'products' => $products,
            ];
        }

        $svcStmt->execute([$cat['id']]);
        $services = [];
        foreach ($svcStmt->fetchAll() as $s) {
            $services[] = [
                'id' => $s['id'],
                'name' => $s['name'],
                'color' => $s['color'],
                'notes' => $s['notes'],
                'img' => catalogImageUrl($s['img'], 'service', $s['id']),
                'available' => (bool)$s['available'],
                'deletedCard' => $s['deleted_card'],
            ];
        }

        $categories[] = [
            'id' => $cat['id'],
            'name' => $cat['name'],
            'image' => catalogImageUrl($cat['image'], 'category', $cat['id']),
            'deletedCard' => $cat['deleted_card'],
            'companies' => $companies,
            'services' => $services,
        ];
    }

    return ['categories' => $categories];
}

// ---------------------------------------------------------------
// حذف العناصر المحذوفة من الشجرة مع تنظيف صورها (تُستخدم داخل db_sync_catalog)
// ---------------------------------------------------------------
// ملاحظة: لا حاجة لأي تنظيف يدوي لصور العناصر المحذوفة - بياناتها (img_data/...) عمود
// عادي في نفس الصف، يُحذف تلقائياً مع الصف نفسه، بخلاف الملفات على القرص سابقاً.
function syncDeleteMissingSimple(PDO $pdo, string $table, string $parentCol, string $parentId, array $keepIds): void {
    if (empty($keepIds)) {
        $pdo->prepare("DELETE FROM $table WHERE $parentCol = ?")->execute([$parentId]);
    } else {
        $ph = implode(',', array_fill(0, count($keepIds), '?'));
        $pdo->prepare("DELETE FROM $table WHERE $parentCol = ? AND id NOT IN ($ph)")->execute(array_merge([$parentId], $keepIds));
    }
}

function syncDeleteMissingCompanies(PDO $pdo, string $catId, array $keepCompIds): void {
    if (empty($keepCompIds)) {
        $pdo->prepare('DELETE FROM companies WHERE category_id = ?')->execute([$catId]);
    } else {
        $ph = implode(',', array_fill(0, count($keepCompIds), '?'));
        $pdo->prepare("DELETE FROM companies WHERE category_id = ? AND id NOT IN ($ph)")->execute(array_merge([$catId], $keepCompIds));
    }
}

function syncDeleteMissingCategories(PDO $pdo, array $keepCatIds): void {
    if (empty($keepCatIds)) {
        $pdo->exec('DELETE FROM categories');
    } else {
        $ph = implode(',', array_fill(0, count($keepCatIds), '?'));
        $pdo->prepare("DELETE FROM categories WHERE id NOT IN ($ph)")->execute($keepCatIds);
    }
}

// ---------------------------------------------------------------
// مزامنة شجرة الكتالوج بالكامل مع قاعدة البيانات (تُستخدم في save_catalog
// واستيراد البيانات من logs/database.json عبر ملف التنصيب).
// يجعل محتوى الجداول مطابقاً تماماً لما ورد في $catalog: يضيف/يحدّث
// الموجود، ويحذف (مع تنظيف الصور) أي عنصر لم يعد موجوداً في الشجرة.
// ---------------------------------------------------------------
function db_sync_catalog(PDO $pdo, array $catalog): void {
    $categories = $catalog['categories'] ?? [];

    $pdo->beginTransaction();
    try {
        $keepCatIds = [];
        $catOrder = 0;

        foreach ($categories as $cat) {
            $catId = !empty($cat['id']) ? (string)$cat['id'] : newEntityId('cat');
            $keepCatIds[] = $catId;

            $existingCatRow = $pdo->prepare('SELECT image, image_data, image_mime FROM categories WHERE id = ?');
            $existingCatRow->execute([$catId]);
            $existingCat = $existingCatRow->fetch() ?: ['image' => null, 'image_data' => null, 'image_mime' => null];
            $image = resolveImageBlobField($cat['image'] ?? null, ['name' => $existingCat['image'], 'data' => $existingCat['image_data'], 'mime' => $existingCat['image_mime']], 'cat');
            $deletedCard = normalizeDeletedCard($cat['deletedCard'] ?? 'no');

            $pdo->prepare('
                INSERT INTO categories (id, name, image, image_data, image_mime, deleted_card, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE name = VALUES(name), image = VALUES(image), image_data = VALUES(image_data),
                    image_mime = VALUES(image_mime), deleted_card = VALUES(deleted_card), sort_order = VALUES(sort_order)
            ')->execute([$catId, (string)($cat['name'] ?? ''), $image['name'], $image['data'], $image['mime'], $deletedCard, $catOrder]);
            $catOrder++;

            // الشركات
            $companies = $cat['companies'] ?? [];
            $keepCompIds = [];
            $compOrder = 0;
            foreach ($companies as $comp) {
                $compId = !empty($comp['id']) ? (string)$comp['id'] : newEntityId('comp');
                $keepCompIds[] = $compId;

                $existingCompRow = $pdo->prepare('SELECT logo, logo_data, logo_mime FROM companies WHERE id = ?');
                $existingCompRow->execute([$compId]);
                $existingComp = $existingCompRow->fetch() ?: ['logo' => null, 'logo_data' => null, 'logo_mime' => null];
                $logo = resolveImageBlobField($comp['logo'] ?? null, ['name' => $existingComp['logo'], 'data' => $existingComp['logo_data'], 'mime' => $existingComp['logo_mime']], 'logo');
                $compDeletedCard = normalizeDeletedCard($comp['deletedCard'] ?? 'no');

                $pdo->prepare('
                    INSERT INTO companies (id, category_id, name, logo, logo_data, logo_mime, deleted_card, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE category_id = VALUES(category_id), name = VALUES(name), logo = VALUES(logo),
                        logo_data = VALUES(logo_data), logo_mime = VALUES(logo_mime), deleted_card = VALUES(deleted_card), sort_order = VALUES(sort_order)
                ')->execute([$compId, $catId, (string)($comp['name'] ?? ''), $logo['name'], $logo['data'], $logo['mime'], $compDeletedCard, $compOrder]);
                $compOrder++;

                // المنتجات
                $products = $comp['products'] ?? [];
                $keepProdIds = [];
                $prodOrder = 0;
                foreach ($products as $prod) {
                    $prodId = !empty($prod['id']) ? (string)$prod['id'] : newEntityId('prod');
                    $keepProdIds[] = $prodId;

                    $existingProdRow = $pdo->prepare('SELECT img, img_data, img_mime FROM products WHERE id = ?');
                    $existingProdRow->execute([$prodId]);
                    $existingProd = $existingProdRow->fetch() ?: ['img' => null, 'img_data' => null, 'img_mime' => null];
                    $img = resolveImageBlobField($prod['img'] ?? null, ['name' => $existingProd['img'], 'data' => $existingProd['img_data'], 'mime' => $existingProd['img_mime']], 'prod');
                    $available = array_key_exists('available', $prod) ? (bool)$prod['available'] : true;
                    $prodDeletedCard = normalizeDeletedCard($prod['deletedCard'] ?? 'no');

                    $pdo->prepare('
                        INSERT INTO products (id, company_id, name, code, color, price, img, img_data, img_mime, image_url, available, deleted_card, sort_order)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE company_id = VALUES(company_id), name = VALUES(name), code = VALUES(code),
                            color = VALUES(color), price = VALUES(price), img = VALUES(img), img_data = VALUES(img_data),
                            img_mime = VALUES(img_mime), image_url = VALUES(image_url),
                            available = VALUES(available), deleted_card = VALUES(deleted_card), sort_order = VALUES(sort_order)
                    ')->execute([
                        $prodId, $compId, (string)($prod['name'] ?? ''), $prod['code'] ?? null, $prod['color'] ?? null,
                        $prod['price'] ?? null, $img['name'], $img['data'], $img['mime'], $prod['image_url'] ?? null, $available ? 1 : 0, $prodDeletedCard, $prodOrder,
                    ]);
                    $prodOrder++;
                }
                syncDeleteMissingSimple($pdo, 'products', 'company_id', $compId, $keepProdIds);
            }
            syncDeleteMissingCompanies($pdo, $catId, $keepCompIds);

            // الخدمات
            $services = $cat['services'] ?? [];
            $keepSvcIds = [];
            $svcOrder = 0;
            foreach ($services as $svc) {
                $svcId = !empty($svc['id']) ? (string)$svc['id'] : newEntityId('service');
                $keepSvcIds[] = $svcId;

                $existingSvcRow = $pdo->prepare('SELECT img, img_data, img_mime FROM services WHERE id = ?');
                $existingSvcRow->execute([$svcId]);
                $existingSvc = $existingSvcRow->fetch() ?: ['img' => null, 'img_data' => null, 'img_mime' => null];
                $img = resolveImageBlobField($svc['img'] ?? null, ['name' => $existingSvc['img'], 'data' => $existingSvc['img_data'], 'mime' => $existingSvc['img_mime']], 'service');
                $available = array_key_exists('available', $svc) ? (bool)$svc['available'] : true;
                $svcDeletedCard = normalizeDeletedCard($svc['deletedCard'] ?? 'no');

                $pdo->prepare('
                    INSERT INTO services (id, category_id, name, color, notes, img, img_data, img_mime, available, deleted_card, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE category_id = VALUES(category_id), name = VALUES(name), color = VALUES(color),
                        notes = VALUES(notes), img = VALUES(img), img_data = VALUES(img_data), img_mime = VALUES(img_mime),
                        available = VALUES(available), deleted_card = VALUES(deleted_card), sort_order = VALUES(sort_order)
                ')->execute([
                    $svcId, $catId, (string)($svc['name'] ?? ''), $svc['color'] ?? null, $svc['notes'] ?? $svc['desc'] ?? null,
                    $img['name'], $img['data'], $img['mime'], $available ? 1 : 0, $svcDeletedCard, $svcOrder,
                ]);
                $svcOrder++;
            }
            syncDeleteMissingSimple($pdo, 'services', 'category_id', $catId, $keepSvcIds);
        }

        syncDeleteMissingCategories($pdo, $keepCatIds);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

// ---------------------------------------------------------------
// عمليات مباشرة على عنصر واحد (تُستخدم من نقاط API الجراحية:
// hide/restore/toggle/delete نهائي/تحديث). كلها بمعرّف ثابت (id)
// بدلاً من الفهرس (index) الذي كان يُستخدم سابقاً وقد يتغير مكانه.
// ---------------------------------------------------------------
function db_find_category(PDO $pdo, string $catId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM categories WHERE id = ?');
    $stmt->execute([$catId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function db_find_company(PDO $pdo, string $catId, string $compId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM companies WHERE id = ? AND category_id = ?');
    $stmt->execute([$compId, $catId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function db_find_product(PDO $pdo, string $compId, string $productId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM products WHERE id = ? AND company_id = ?');
    $stmt->execute([$productId, $compId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function db_find_service(PDO $pdo, string $catId, string $serviceId): ?array {
    $stmt = $pdo->prepare('SELECT * FROM services WHERE id = ? AND category_id = ?');
    $stmt->execute([$serviceId, $catId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function db_toggle_product_status(PDO $pdo, string $catId, string $compId, string $productId): ?array {
    $comp = db_find_company($pdo, $catId, $compId);
    if (!$comp) return null;
    $prod = db_find_product($pdo, $compId, $productId);
    if (!$prod) return null;
    $newStatus = !$prod['available'];
    $pdo->prepare('UPDATE products SET available = ? WHERE id = ?')->execute([$newStatus ? 1 : 0, $productId]);
    return ['name' => $prod['name'], 'newStatus' => (bool)$newStatus];
}

function db_set_product_deleted_card(PDO $pdo, string $catId, string $compId, string $productId, string $value): ?string {
    $comp = db_find_company($pdo, $catId, $compId);
    if (!$comp) return null;
    $prod = db_find_product($pdo, $compId, $productId);
    if (!$prod) return null;
    $pdo->prepare('UPDATE products SET deleted_card = ? WHERE id = ?')->execute([$value, $productId]);
    return $prod['name'];
}

function db_delete_product_permanent(PDO $pdo, string $catId, string $compId, string $productId): ?string {
    $comp = db_find_company($pdo, $catId, $compId);
    if (!$comp) return null;
    $prod = db_find_product($pdo, $compId, $productId);
    if (!$prod) return null;
    $pdo->prepare('DELETE FROM products WHERE id = ?')->execute([$productId]);
    return $prod['name'];
}

function db_edit_product_info(PDO $pdo, string $catId, string $compId, string $productId, array $productData): ?array {
    $comp = db_find_company($pdo, $catId, $compId);
    if (!$comp) return null;
    $prod = db_find_product($pdo, $compId, $productId);
    if (!$prod) return null;

    $oldImage = $prod['img'];
    $newImage = null;

    $sets = [];
    $params = [];
    if (isset($productData['name']) && $productData['name'] !== '') {
        $sets[] = 'name = ?';
        $params[] = $productData['name'];
    }
    if (array_key_exists('code', $productData)) {
        $sets[] = 'code = ?';
        $params[] = $productData['code'];
    }
    if (array_key_exists('color', $productData)) {
        $sets[] = 'color = ?';
        $params[] = $productData['color'];
    }
    if (array_key_exists('price', $productData)) {
        $sets[] = 'price = ?';
        $params[] = $productData['price'];
    }
    if (isset($productData['available'])) {
        $sets[] = 'available = ?';
        $params[] = !empty($productData['available']) ? 1 : 0;
    }
    if (array_key_exists('image_url', $productData)) {
        $sets[] = 'image_url = ?';
        $params[] = $productData['image_url'] ?: null;
    }

    if (isset($productData['img']) && !empty($productData['img']) && strpos($productData['img'], 'data:image') === 0) {
        $decoded = decodeImageBase64($productData['img']);
        if (!$decoded) {
            return ['error' => 'فشل حفظ الصورة الجديدة'];
        }
        $newFilename = newImageFilename('prod', $decoded['mime']);
        $newImage = $newFilename;
        $sets[] = 'img = ?';
        $params[] = $newFilename;
        $sets[] = 'img_data = ?';
        $params[] = $decoded['data'];
        $sets[] = 'img_mime = ?';
        $params[] = $decoded['mime'];
    }

    if ($sets) {
        $params[] = $productId;
        $pdo->prepare('UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($params);
    }

    $finalName = $productData['name'] ?? $prod['name'];
    return ['name' => $finalName, 'oldImage' => $oldImage, 'newImage' => $newImage];
}

function db_toggle_service_status(PDO $pdo, string $catId, string $serviceId): ?array {
    $svc = db_find_service($pdo, $catId, $serviceId);
    if (!$svc) return null;
    $newStatus = !$svc['available'];
    $pdo->prepare('UPDATE services SET available = ? WHERE id = ?')->execute([$newStatus ? 1 : 0, $serviceId]);
    return ['name' => $svc['name'], 'newStatus' => (bool)$newStatus];
}

function db_set_service_deleted_card(PDO $pdo, string $catId, string $serviceId, string $value): ?string {
    $svc = db_find_service($pdo, $catId, $serviceId);
    if (!$svc) return null;
    $pdo->prepare('UPDATE services SET deleted_card = ? WHERE id = ?')->execute([$value, $serviceId]);
    return $svc['name'];
}

function db_delete_service_permanent(PDO $pdo, string $catId, string $serviceId): ?string {
    $svc = db_find_service($pdo, $catId, $serviceId);
    if (!$svc) return null;
    $pdo->prepare('DELETE FROM services WHERE id = ?')->execute([$serviceId]);
    return $svc['name'];
}

function db_update_service(PDO $pdo, string $catId, string $serviceId, array $serviceData): ?bool {
    $svc = db_find_service($pdo, $catId, $serviceId);
    if (!$svc) return null;

    $name = $serviceData['name'] ?? $svc['name'];
    $color = $serviceData['color'] ?? ($svc['color'] ?? '');
    $notes = $serviceData['notes'] ?? ($svc['notes'] ?? '');
    $available = isset($serviceData['available']) ? (bool)$serviceData['available'] : (bool)$svc['available'];

    $img = $svc['img'];
    $imgData = $svc['img_data'];
    $imgMime = $svc['img_mime'];
    if (isset($serviceData['img']) && !empty($serviceData['img']) && strpos($serviceData['img'], 'data:image') === 0) {
        $decoded = decodeImageBase64($serviceData['img']);
        if ($decoded) {
            $img = newImageFilename('service', $decoded['mime']);
            $imgData = $decoded['data'];
            $imgMime = $decoded['mime'];
        }
    }

    $pdo->prepare('UPDATE services SET name = ?, color = ?, notes = ?, available = ?, img = ?, img_data = ?, img_mime = ? WHERE id = ?')
        ->execute([$name, $color, $notes, $available ? 1 : 0, $img, $imgData, $imgMime, $serviceId]);
    return true;
}

function db_hide_company(PDO $pdo, string $catId, string $compId): ?string {
    $comp = db_find_company($pdo, $catId, $compId);
    if (!$comp) return null;
    $pdo->prepare("UPDATE companies SET deleted_card = 'ok' WHERE id = ?")->execute([$compId]);
    return $comp['name'];
}

function db_restore_company(PDO $pdo, string $catId, string $compId): ?string {
    $comp = db_find_company($pdo, $catId, $compId);
    if (!$comp) return null;
    $pdo->prepare("UPDATE companies SET deleted_card = 'no' WHERE id = ?")->execute([$compId]);
    return $comp['name'];
}

function db_delete_company_permanent(PDO $pdo, string $catId, string $compId): ?string {
    $comp = db_find_company($pdo, $catId, $compId);
    if (!$comp) return null;
    $pdo->prepare('DELETE FROM companies WHERE id = ?')->execute([$compId]);
    return $comp['name'];
}

function db_update_company(PDO $pdo, string $catId, string $compId, array $companyData): ?bool {
    $comp = db_find_company($pdo, $catId, $compId);
    if (!$comp) return null;

    $name = $companyData['name'] ?? $comp['name'];
    $logo = $comp['logo'];
    $logoData = $comp['logo_data'];
    $logoMime = $comp['logo_mime'];
    if (isset($companyData['logo']) && !empty($companyData['logo']) && strpos($companyData['logo'], 'data:image') === 0) {
        $decoded = decodeImageBase64($companyData['logo']);
        if ($decoded) {
            $logo = newImageFilename('logo', $decoded['mime']);
            $logoData = $decoded['data'];
            $logoMime = $decoded['mime'];
        }
    }

    $pdo->prepare('UPDATE companies SET name = ?, logo = ?, logo_data = ?, logo_mime = ? WHERE id = ?')->execute([$name, $logo, $logoData, $logoMime, $compId]);
    return true;
}

function db_hide_category(PDO $pdo, string $catId): ?string {
    $cat = db_find_category($pdo, $catId);
    if (!$cat) return null;
    $pdo->prepare("UPDATE categories SET deleted_card = 'ok' WHERE id = ?")->execute([$catId]);
    return $cat['name'];
}

function db_restore_category(PDO $pdo, string $catId): ?string {
    $cat = db_find_category($pdo, $catId);
    if (!$cat) return null;
    $pdo->prepare("UPDATE categories SET deleted_card = 'no' WHERE id = ?")->execute([$catId]);
    return $cat['name'];
}

function db_delete_category_permanent(PDO $pdo, string $catId): ?string {
    $cat = db_find_category($pdo, $catId);
    if (!$cat) return null;
    syncDeleteMissingCategories($pdo, array_values(array_diff(
        array_column($pdo->query('SELECT id FROM categories')->fetchAll(), 'id'),
        [$catId]
    )));
    return $cat['name'];
}

// ---------------------------------------------------------------
// عمليات إنشاء/تحديث/سرد مباشرة لصفحات لوحة التحكم (admin/*.php).
// منفصلة عن db_sync_catalog التي تُستخدم لمزامنة الشجرة كاملة من الواجهة القديمة.
// ---------------------------------------------------------------
function db_next_sort_order(PDO $pdo, string $table, ?string $whereCol = null, $whereVal = null): int {
    if ($whereCol) {
        $stmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) AS m FROM $table WHERE $whereCol = ?");
        $stmt->execute([$whereVal]);
    } else {
        $stmt = $pdo->query("SELECT COALESCE(MAX(sort_order), -1) AS m FROM $table");
    }
    return (int)$stmt->fetch()['m'] + 1;
}

function db_list_categories_admin(PDO $pdo): array {
    return $pdo->query('SELECT * FROM categories ORDER BY sort_order ASC, created_at ASC')->fetchAll();
}

// $imageBlob: ['data' => binary, 'mime' => string] أو null إن لم تُرفع صورة
function db_create_category(PDO $pdo, string $name, ?array $imageBlob): string {
    $id = newEntityId('cat');
    $order = db_next_sort_order($pdo, 'categories');
    $imageName = $imageBlob ? newImageFilename('cat', $imageBlob['mime']) : null;
    $pdo->prepare("INSERT INTO categories (id, name, image, image_data, image_mime, deleted_card, sort_order) VALUES (?, ?, ?, ?, ?, 'no', ?)")
        ->execute([$id, $name, $imageName, $imageBlob['data'] ?? null, $imageBlob['mime'] ?? null, $order]);
    return $id;
}

function db_update_category_fields(PDO $pdo, string $id, string $name, ?array $newImageBlob): void {
    if ($newImageBlob !== null) {
        $imageName = newImageFilename('cat', $newImageBlob['mime']);
        $pdo->prepare('UPDATE categories SET name = ?, image = ?, image_data = ?, image_mime = ? WHERE id = ?')
            ->execute([$name, $imageName, $newImageBlob['data'], $newImageBlob['mime'], $id]);
    } else {
        $pdo->prepare('UPDATE categories SET name = ? WHERE id = ?')->execute([$name, $id]);
    }
}

function db_list_companies_admin(PDO $pdo): array {
    return $pdo->query('
        SELECT co.*, ca.name AS category_name
        FROM companies co JOIN categories ca ON co.category_id = ca.id
        ORDER BY ca.sort_order ASC, co.sort_order ASC, co.created_at ASC
    ')->fetchAll();
}

function db_create_company(PDO $pdo, string $categoryId, string $name, ?array $logoBlob): string {
    $id = newEntityId('comp');
    $order = db_next_sort_order($pdo, 'companies', 'category_id', $categoryId);
    $logoName = $logoBlob ? newImageFilename('logo', $logoBlob['mime']) : null;
    $pdo->prepare("INSERT INTO companies (id, category_id, name, logo, logo_data, logo_mime, deleted_card, sort_order) VALUES (?, ?, ?, ?, ?, ?, 'no', ?)")
        ->execute([$id, $categoryId, $name, $logoName, $logoBlob['data'] ?? null, $logoBlob['mime'] ?? null, $order]);
    return $id;
}

function db_update_company_fields(PDO $pdo, string $id, string $categoryId, string $name, ?array $newLogoBlob): void {
    if ($newLogoBlob !== null) {
        $logoName = newImageFilename('logo', $newLogoBlob['mime']);
        $pdo->prepare('UPDATE companies SET category_id = ?, name = ?, logo = ?, logo_data = ?, logo_mime = ? WHERE id = ?')
            ->execute([$categoryId, $name, $logoName, $newLogoBlob['data'], $newLogoBlob['mime'], $id]);
    } else {
        $pdo->prepare('UPDATE companies SET category_id = ?, name = ? WHERE id = ?')->execute([$categoryId, $name, $id]);
    }
}

function db_list_products_admin(PDO $pdo): array {
    return $pdo->query('
        SELECT p.*, co.name AS company_name, ca.id AS category_id, ca.name AS category_name
        FROM products p
        JOIN companies co ON p.company_id = co.id
        JOIN categories ca ON co.category_id = ca.id
        ORDER BY ca.sort_order ASC, co.sort_order ASC, p.sort_order ASC, p.created_at ASC
    ')->fetchAll();
}

function db_create_product(PDO $pdo, string $companyId, array $fields, ?array $imgBlob): string {
    $id = newEntityId('prod');
    $order = db_next_sort_order($pdo, 'products', 'company_id', $companyId);
    $imgName = $imgBlob ? newImageFilename('prod', $imgBlob['mime']) : null;
    $pdo->prepare("
        INSERT INTO products (id, company_id, name, code, color, price, img, img_data, img_mime, image_url, available, deleted_card, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'no', ?)
    ")->execute([
        $id, $companyId, $fields['name'], $fields['code'] ?? null, $fields['color'] ?? null, $fields['price'] ?? null,
        $imgName, $imgBlob['data'] ?? null, $imgBlob['mime'] ?? null, $fields['image_url'] ?? null, !empty($fields['available']) ? 1 : 0, $order,
    ]);
    return $id;
}

function db_update_product_fields(PDO $pdo, string $id, string $companyId, array $fields, ?array $newImgBlob): void {
    $sets = 'company_id = ?, name = ?, code = ?, color = ?, price = ?, image_url = ?, available = ?';
    $params = [
        $companyId, $fields['name'], $fields['code'] ?? null, $fields['color'] ?? null, $fields['price'] ?? null,
        $fields['image_url'] ?? null, !empty($fields['available']) ? 1 : 0,
    ];
    if ($newImgBlob !== null) {
        $sets .= ', img = ?, img_data = ?, img_mime = ?';
        $params[] = newImageFilename('prod', $newImgBlob['mime']);
        $params[] = $newImgBlob['data'];
        $params[] = $newImgBlob['mime'];
    }
    $params[] = $id;
    $pdo->prepare("UPDATE products SET $sets WHERE id = ?")->execute($params);
}

function db_list_services_admin(PDO $pdo): array {
    return $pdo->query('
        SELECT s.*, ca.name AS category_name
        FROM services s JOIN categories ca ON s.category_id = ca.id
        ORDER BY ca.sort_order ASC, s.sort_order ASC, s.created_at ASC
    ')->fetchAll();
}

function db_create_service(PDO $pdo, string $categoryId, array $fields, ?array $imgBlob): string {
    $id = newEntityId('service');
    $order = db_next_sort_order($pdo, 'services', 'category_id', $categoryId);
    $imgName = $imgBlob ? newImageFilename('service', $imgBlob['mime']) : null;
    $pdo->prepare("
        INSERT INTO services (id, category_id, name, color, notes, img, img_data, img_mime, available, deleted_card, sort_order)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'no', ?)
    ")->execute([
        $id, $categoryId, $fields['name'], $fields['color'] ?? null, $fields['notes'] ?? null,
        $imgName, $imgBlob['data'] ?? null, $imgBlob['mime'] ?? null, !empty($fields['available']) ? 1 : 0, $order,
    ]);
    return $id;
}

function db_update_service_fields(PDO $pdo, string $id, string $categoryId, array $fields, ?array $newImgBlob): void {
    $sets = 'category_id = ?, name = ?, color = ?, notes = ?, available = ?';
    $params = [$categoryId, $fields['name'], $fields['color'] ?? null, $fields['notes'] ?? null, !empty($fields['available']) ? 1 : 0];
    if ($newImgBlob !== null) {
        $sets .= ', img = ?, img_data = ?, img_mime = ?';
        $params[] = newImageFilename('service', $newImgBlob['mime']);
        $params[] = $newImgBlob['data'];
        $params[] = $newImgBlob['mime'];
    }
    $params[] = $id;
    $pdo->prepare("UPDATE services SET $sets WHERE id = ?")->execute($params);
}
