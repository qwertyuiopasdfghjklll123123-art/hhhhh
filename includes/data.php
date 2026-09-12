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
    return [
        'appName' => $row['app_name'],
        'appLogo' => $row['app_logo'],
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
    $map = [
        'appName' => 'app_name',
        'appLogo' => 'app_logo',
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

// ---------------------------------------------------------------
// أدوات مساعدة للكتالوج
// ---------------------------------------------------------------
function fetchOneValue(PDO $pdo, string $sql, array $params, string $col) {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row ? $row[$col] : null;
}

// يقرر القيمة النهائية لحقل صورة عند التزامن: يرفع صورة base64 جديدة إن وُجدت
// ويحذف القديمة، أو يُبقي القيمة كما هي (رابط/اسم ملف موجود مسبقاً)
function resolveImageField($newValue, $existingValue, string $prefix) {
    if (!empty($newValue) && is_string($newValue) && strpos($newValue, 'data:image') === 0) {
        $saved = handleImageUpload($newValue, $prefix);
        if ($saved) {
            if (!empty($existingValue) && strpos($existingValue, 'data:') !== 0 && strpos($existingValue, 'http') !== 0) {
                deleteOldImage($existingValue);
            }
            return $saved;
        }
        return $existingValue;
    }
    if ($newValue === null || $newValue === '') {
        return $existingValue;
    }
    return $newValue;
}

function normalizeDeletedCard($value): string {
    return ($value === 'ok') ? 'ok' : 'no';
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
                    'img' => $p['img'],
                    'image_url' => $p['image_url'],
                    'available' => (bool)$p['available'],
                    'deletedCard' => $p['deleted_card'],
                ];
            }
            $companies[] = [
                'id' => $comp['id'],
                'name' => $comp['name'],
                'logo' => $comp['logo'],
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
                'img' => $s['img'],
                'available' => (bool)$s['available'],
                'deletedCard' => $s['deleted_card'],
            ];
        }

        $categories[] = [
            'id' => $cat['id'],
            'name' => $cat['name'],
            'image' => $cat['image'],
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
function syncDeleteMissingSimple(PDO $pdo, string $table, string $parentCol, string $parentId, array $keepIds, string $imageCol): void {
    if (empty($keepIds)) {
        $stmt = $pdo->prepare("SELECT id, $imageCol AS img FROM $table WHERE $parentCol = ?");
        $stmt->execute([$parentId]);
    } else {
        $ph = implode(',', array_fill(0, count($keepIds), '?'));
        $stmt = $pdo->prepare("SELECT id, $imageCol AS img FROM $table WHERE $parentCol = ? AND id NOT IN ($ph)");
        $stmt->execute(array_merge([$parentId], $keepIds));
    }
    $toDelete = $stmt->fetchAll();
    if (!$toDelete) return;
    foreach ($toDelete as $row) {
        if (!empty($row['img'])) deleteOldImage($row['img']);
    }
    $ids = array_column($toDelete, 'id');
    $ph2 = implode(',', array_fill(0, count($ids), '?'));
    $pdo->prepare("DELETE FROM $table WHERE id IN ($ph2)")->execute($ids);
}

function syncDeleteMissingCompanies(PDO $pdo, string $catId, array $keepCompIds): void {
    if (empty($keepCompIds)) {
        $stmt = $pdo->prepare('SELECT id, logo FROM companies WHERE category_id = ?');
        $stmt->execute([$catId]);
    } else {
        $ph = implode(',', array_fill(0, count($keepCompIds), '?'));
        $stmt = $pdo->prepare("SELECT id, logo FROM companies WHERE category_id = ? AND id NOT IN ($ph)");
        $stmt->execute(array_merge([$catId], $keepCompIds));
    }
    $companies = $stmt->fetchAll();
    if (!$companies) return;

    $compIds = array_column($companies, 'id');
    $ph2 = implode(',', array_fill(0, count($compIds), '?'));
    $prodStmt = $pdo->prepare("SELECT img FROM products WHERE company_id IN ($ph2)");
    $prodStmt->execute($compIds);
    foreach ($prodStmt->fetchAll() as $p) {
        if (!empty($p['img'])) deleteOldImage($p['img']);
    }
    foreach ($companies as $c) {
        if (!empty($c['logo'])) deleteOldImage($c['logo']);
    }
    $pdo->prepare("DELETE FROM companies WHERE id IN ($ph2)")->execute($compIds);
}

function syncDeleteMissingCategories(PDO $pdo, array $keepCatIds): void {
    if (empty($keepCatIds)) {
        $rows = $pdo->query('SELECT id, image FROM categories')->fetchAll();
    } else {
        $ph = implode(',', array_fill(0, count($keepCatIds), '?'));
        $stmt = $pdo->prepare("SELECT id, image FROM categories WHERE id NOT IN ($ph)");
        $stmt->execute($keepCatIds);
        $rows = $stmt->fetchAll();
    }
    if (!$rows) return;

    $catIds = array_column($rows, 'id');
    $ph2 = implode(',', array_fill(0, count($catIds), '?'));

    $compStmt = $pdo->prepare("SELECT id, logo FROM companies WHERE category_id IN ($ph2)");
    $compStmt->execute($catIds);
    $companies = $compStmt->fetchAll();
    if ($companies) {
        $compIds = array_column($companies, 'id');
        $ph3 = implode(',', array_fill(0, count($compIds), '?'));
        $prodStmt = $pdo->prepare("SELECT img FROM products WHERE company_id IN ($ph3)");
        $prodStmt->execute($compIds);
        foreach ($prodStmt->fetchAll() as $p) {
            if (!empty($p['img'])) deleteOldImage($p['img']);
        }
        foreach ($companies as $c) {
            if (!empty($c['logo'])) deleteOldImage($c['logo']);
        }
    }

    $svcStmt = $pdo->prepare("SELECT img FROM services WHERE category_id IN ($ph2)");
    $svcStmt->execute($catIds);
    foreach ($svcStmt->fetchAll() as $s) {
        if (!empty($s['img'])) deleteOldImage($s['img']);
    }

    foreach ($rows as $r) {
        if (!empty($r['image'])) deleteOldImage($r['image']);
    }

    $pdo->prepare("DELETE FROM categories WHERE id IN ($ph2)")->execute($catIds);
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

            $existingImage = fetchOneValue($pdo, 'SELECT image FROM categories WHERE id = ?', [$catId], 'image');
            $image = resolveImageField($cat['image'] ?? null, $existingImage, 'cat');
            $deletedCard = normalizeDeletedCard($cat['deletedCard'] ?? 'no');

            $pdo->prepare('
                INSERT INTO categories (id, name, image, deleted_card, sort_order)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE name = VALUES(name), image = VALUES(image), deleted_card = VALUES(deleted_card), sort_order = VALUES(sort_order)
            ')->execute([$catId, (string)($cat['name'] ?? ''), $image, $deletedCard, $catOrder]);
            $catOrder++;

            // الشركات
            $companies = $cat['companies'] ?? [];
            $keepCompIds = [];
            $compOrder = 0;
            foreach ($companies as $comp) {
                $compId = !empty($comp['id']) ? (string)$comp['id'] : newEntityId('comp');
                $keepCompIds[] = $compId;

                $existingLogo = fetchOneValue($pdo, 'SELECT logo FROM companies WHERE id = ?', [$compId], 'logo');
                $logo = resolveImageField($comp['logo'] ?? null, $existingLogo, 'logo');
                $compDeletedCard = normalizeDeletedCard($comp['deletedCard'] ?? 'no');

                $pdo->prepare('
                    INSERT INTO companies (id, category_id, name, logo, deleted_card, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE category_id = VALUES(category_id), name = VALUES(name), logo = VALUES(logo), deleted_card = VALUES(deleted_card), sort_order = VALUES(sort_order)
                ')->execute([$compId, $catId, (string)($comp['name'] ?? ''), $logo, $compDeletedCard, $compOrder]);
                $compOrder++;

                // المنتجات
                $products = $comp['products'] ?? [];
                $keepProdIds = [];
                $prodOrder = 0;
                foreach ($products as $prod) {
                    $prodId = !empty($prod['id']) ? (string)$prod['id'] : newEntityId('prod');
                    $keepProdIds[] = $prodId;

                    $existingImg = fetchOneValue($pdo, 'SELECT img FROM products WHERE id = ?', [$prodId], 'img');
                    $img = resolveImageField($prod['img'] ?? null, $existingImg, 'prod');
                    $available = array_key_exists('available', $prod) ? (bool)$prod['available'] : true;
                    $prodDeletedCard = normalizeDeletedCard($prod['deletedCard'] ?? 'no');

                    $pdo->prepare('
                        INSERT INTO products (id, company_id, name, code, color, img, image_url, available, deleted_card, sort_order)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE company_id = VALUES(company_id), name = VALUES(name), code = VALUES(code),
                            color = VALUES(color), img = VALUES(img), image_url = VALUES(image_url),
                            available = VALUES(available), deleted_card = VALUES(deleted_card), sort_order = VALUES(sort_order)
                    ')->execute([
                        $prodId, $compId, (string)($prod['name'] ?? ''), $prod['code'] ?? null, $prod['color'] ?? null,
                        $img, $prod['image_url'] ?? null, $available ? 1 : 0, $prodDeletedCard, $prodOrder,
                    ]);
                    $prodOrder++;
                }
                syncDeleteMissingSimple($pdo, 'products', 'company_id', $compId, $keepProdIds, 'img');
            }
            syncDeleteMissingCompanies($pdo, $catId, $keepCompIds);

            // الخدمات
            $services = $cat['services'] ?? [];
            $keepSvcIds = [];
            $svcOrder = 0;
            foreach ($services as $svc) {
                $svcId = !empty($svc['id']) ? (string)$svc['id'] : newEntityId('service');
                $keepSvcIds[] = $svcId;

                $existingImg = fetchOneValue($pdo, 'SELECT img FROM services WHERE id = ?', [$svcId], 'img');
                $img = resolveImageField($svc['img'] ?? null, $existingImg, 'service');
                $available = array_key_exists('available', $svc) ? (bool)$svc['available'] : true;
                $svcDeletedCard = normalizeDeletedCard($svc['deletedCard'] ?? 'no');

                $pdo->prepare('
                    INSERT INTO services (id, category_id, name, color, notes, img, available, deleted_card, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE category_id = VALUES(category_id), name = VALUES(name), color = VALUES(color),
                        notes = VALUES(notes), img = VALUES(img), available = VALUES(available),
                        deleted_card = VALUES(deleted_card), sort_order = VALUES(sort_order)
                ')->execute([
                    $svcId, $catId, (string)($svc['name'] ?? ''), $svc['color'] ?? null, $svc['notes'] ?? null,
                    $img, $available ? 1 : 0, $svcDeletedCard, $svcOrder,
                ]);
                $svcOrder++;
            }
            syncDeleteMissingSimple($pdo, 'services', 'category_id', $catId, $keepSvcIds, 'img');
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
    if (!empty($prod['img'])) deleteOldImage($prod['img']);
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
    if (isset($productData['available'])) {
        $sets[] = 'available = ?';
        $params[] = !empty($productData['available']) ? 1 : 0;
    }
    if (array_key_exists('image_url', $productData)) {
        $sets[] = 'image_url = ?';
        $params[] = $productData['image_url'] ?: null;
    }

    if (isset($productData['img']) && !empty($productData['img']) && strpos($productData['img'], 'data:image') === 0) {
        $newFilename = handleImageUpload($productData['img'], 'prod');
        if (!$newFilename) {
            return ['error' => 'فشل حفظ الصورة الجديدة'];
        }
        $newImage = $newFilename;
        if (!empty($oldImage) && strpos($oldImage, 'data:') !== 0 && strpos($oldImage, 'http') !== 0) {
            deleteOldImage($oldImage);
        }
        $sets[] = 'img = ?';
        $params[] = $newFilename;
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
    if (!empty($svc['img'])) deleteOldImage($svc['img']);
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
    if (isset($serviceData['img']) && !empty($serviceData['img']) && strpos($serviceData['img'], 'data:image') === 0) {
        $newFilename = handleImageUpload($serviceData['img'], 'service');
        if ($newFilename) {
            deleteOldImage($svc['img']);
            $img = $newFilename;
        }
    }

    $pdo->prepare('UPDATE services SET name = ?, color = ?, notes = ?, available = ?, img = ? WHERE id = ?')
        ->execute([$name, $color, $notes, $available ? 1 : 0, $img, $serviceId]);
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
    $stmt = $pdo->prepare('SELECT img FROM products WHERE company_id = ?');
    $stmt->execute([$compId]);
    foreach ($stmt->fetchAll() as $p) {
        if (!empty($p['img'])) deleteOldImage($p['img']);
    }
    if (!empty($comp['logo'])) deleteOldImage($comp['logo']);
    $pdo->prepare('DELETE FROM companies WHERE id = ?')->execute([$compId]);
    return $comp['name'];
}

function db_update_company(PDO $pdo, string $catId, string $compId, array $companyData): ?bool {
    $comp = db_find_company($pdo, $catId, $compId);
    if (!$comp) return null;

    $name = $companyData['name'] ?? $comp['name'];
    $logo = $comp['logo'];
    if (isset($companyData['logo']) && !empty($companyData['logo']) && strpos($companyData['logo'], 'data:image') === 0) {
        $newFilename = handleImageUpload($companyData['logo'], 'logo');
        if ($newFilename) {
            deleteOldImage($comp['logo']);
            $logo = $newFilename;
        }
    }

    $pdo->prepare('UPDATE companies SET name = ?, logo = ? WHERE id = ?')->execute([$name, $logo, $compId]);
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
