<?php
// ================================================================
// دوال استيراد البيانات القديمة، تُستخدم من install.php (أثناء التنصيب)
// ومن admin/import.php (لاستيراد بيانات إضافية لاحقاً بعد التنصيب).
// ================================================================

// استيراد ملف database.json بصيغة تطبيق Almulla القديم (users/catalog/settings/stats)
function import_legacy_json(PDO $pdo, array $json): array {
    $summary = ['users' => 0, 'categories' => 0];

    if (!empty($json['users']) && is_array($json['users'])) {
        foreach ($json['users'] as $u) {
            if (empty($u['email']) || empty($u['password'])) continue;
            if (db_get_user_by_email($pdo, $u['email'])) continue;
            $stmt = $pdo->prepare('INSERT INTO users (id, fullname, email, password, is_admin, created_at, last_login) VALUES (?,?,?,?,?,?,?)');
            $stmt->execute([
                !empty($u['id']) ? (int)$u['id'] : null,
                $u['fullname'] ?? '',
                $u['email'],
                $u['password'],
                !empty($u['isAdmin']) ? 1 : 0,
                $u['created_at'] ?? db_now(),
                $u['last_login'] ?? null,
            ]);
            $summary['users']++;
        }
        $max = (int)$pdo->query('SELECT COALESCE(MAX(id),0) AS m FROM users')->fetch()['m'];
        $pdo->exec('ALTER TABLE users AUTO_INCREMENT = ' . ($max + 1));
    }

    if (!empty($json['catalog']) && is_array($json['catalog'])) {
        db_sync_catalog($pdo, $json['catalog']);
        $summary['categories'] = count($json['catalog']['categories'] ?? []);
    }

    if (!empty($json['settings']) && is_array($json['settings'])) {
        db_save_settings($pdo, $json['settings']);
    }

    db_ensure_stats_row($pdo);
    if (!empty($json['stats']) && is_array($json['stats'])) {
        $s = $json['stats'];
        $pdo->prepare('UPDATE stats SET total_visitors=?, total_favorites=?, total_orders=? WHERE id=1')->execute([
            (int)($s['total_visitors'] ?? 0), (int)($s['total_favorites'] ?? 0), (int)($s['total_orders'] ?? 0),
        ]);
        if (!empty($s['daily_visits']) && is_array($s['daily_visits'])) {
            foreach ($s['daily_visits'] as $date => $count) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$date)) continue;
                $pdo->prepare('INSERT INTO daily_visits (visit_date, count) VALUES (?,?) ON DUPLICATE KEY UPDATE count = VALUES(count)')->execute([$date, (int)$count]);
            }
        }
        if (!empty($s['most_requested_items']) && is_array($s['most_requested_items'])) {
            foreach ($s['most_requested_items'] as $item) {
                if (empty($item['name']) || empty($item['category'])) continue;
                $pdo->prepare('INSERT INTO most_requested_items (name, category, type, count) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE count = VALUES(count)')
                    ->execute([$item['name'], $item['category'], $item['type'] ?? 'product', (int)($item['count'] ?? 1)]);
            }
        }
    }

    if (!empty($json['push_subscriptions']) && is_array($json['push_subscriptions'])) {
        foreach ($json['push_subscriptions'] as $sub) {
            $pdo->prepare('INSERT INTO push_subscriptions (subscription, created_at) VALUES (?, ?)')
                ->execute([json_encode($sub, JSON_UNESCAPED_UNICODE), db_now()]);
        }
    }

    return $summary;
}

// استيراد قاعدة بيانات SQLite بسيطة تحتوي جدول products مسطّح (بلا فئات/شركات) مثل:
// products(id, name, price, code, color, img, status, created_at, updated_at)
// وجدول settings اختياري بصيغة key/value. تُنشأ فئة وشركة هدف تلقائياً لاستقبال المنتجات.
function import_flat_products_sqlite(PDO $pdo, string $sqliteFilePath, string $targetCategoryName, string $targetCompanyName, bool $importSettings = true): array {
    $summary = ['products' => 0, 'settings' => 0, 'error' => null];

    try {
        $src = new PDO('sqlite:' . $sqliteFilePath);
        $src->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $src->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $summary['error'] = 'الملف ليس قاعدة بيانات SQLite صالحة.';
        return $summary;
    }

    $tables = array_column($src->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(), 'name');

    if (!in_array('products', $tables, true)) {
        $summary['error'] = 'لم يتم العثور على جدول products داخل الملف.';
        return $summary;
    }

    $rows = $src->query('SELECT * FROM products')->fetchAll();
    if (empty($rows)) {
        $summary['error'] = 'جدول المنتجات فارغ، لا يوجد شيء لاستيراده.';
        return $summary;
    }

    $targetCategoryName = $targetCategoryName !== '' ? $targetCategoryName : 'منتجات مستوردة';
    $targetCompanyName = $targetCompanyName !== '' ? $targetCompanyName : 'عام';

    $hiddenStatuses = ['off', 'hidden', 'disabled', 'no', '0', 'inactive'];

    $pdo->beginTransaction();
    try {
        $categoryId = db_create_category($pdo, $targetCategoryName, null);
        $companyId = db_create_company($pdo, $categoryId, $targetCompanyName, null);

        foreach ($rows as $row) {
            $name = trim((string)($row['name'] ?? ''));
            if ($name === '') continue;

            $status = strtolower(trim((string)($row['status'] ?? 'on')));
            $available = !in_array($status, $hiddenStatuses, true);

            $img = trim((string)($row['img'] ?? ''));
            $imgToStore = null;
            if ($img !== '') {
                if (strpos($img, 'data:image') === 0) {
                    $imgToStore = handleImageUpload($img, 'prod');
                } elseif (strpos($img, 'http') === 0) {
                    $imgToStore = null; // يُحفظ كرابط خارجي عبر image_url بدل img
                } else {
                    $imgToStore = $img; // اسم ملف يُفترض أنه سيُنسخ يدوياً إلى uploads/
                }
            }

            db_create_product($pdo, $companyId, [
                'name' => $name,
                'code' => $row['code'] ?? null,
                'color' => $row['color'] ?? null,
                'price' => $row['price'] ?? null,
                'image_url' => (strpos($img, 'http') === 0) ? $img : null,
                'available' => $available,
            ], $imgToStore);

            $summary['products']++;
        }

        if ($importSettings && in_array('settings', $tables, true)) {
            $settingsRows = $src->query('SELECT `key`, value FROM settings')->fetchAll();
            $map = ['appName' => 'appName', 'appLogo' => 'appLogo', 'whatsappNumber' => 'whatsappNumber', 'officialWebsite' => 'officialWebsite'];
            $toSave = [];
            foreach ($settingsRows as $sRow) {
                $key = $sRow['key'] ?? '';
                if (isset($map[$key]) && $sRow['value'] !== '' && $sRow['value'] !== null) {
                    $toSave[$map[$key]] = $sRow['value'];
                    $summary['settings']++;
                }
            }
            if ($toSave) db_save_settings($pdo, $toSave);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $summary['error'] = 'حدث خطأ أثناء الاستيراد: ' . $e->getMessage();
    }

    return $summary;
}

// استيراد مجلد صور كمنتجات: كل صورة تصبح منتجاً منفصلاً، واسم الملف (بدون الامتداد)
// يصبح اسم المنتج. تُستخدم لمجلدات مثل logs/legacy-imports/ali/ التي تحتوي صوراً فقط
// بلا قاعدة بيانات مرافقة. $files اختياري: مصفوفة [اسم الملف => المسار الكامل]؛ إن تُرك
// فارغاً يُقرأ المجلد مباشرة من القرص (لحالة install.php).
function import_images_as_products(PDO $pdo, string $folderPath, string $categoryName, string $companyName, ?array $files = null): array {
    $summary = ['products' => 0, 'skipped' => 0, 'error' => null];

    if ($files === null) {
        if (!is_dir($folderPath)) {
            $summary['error'] = 'المجلد غير موجود.';
            return $summary;
        }
        $files = [];
        foreach (scandir($folderPath) as $entry) {
            $fullPath = $folderPath . '/' . $entry;
            if (is_file($fullPath)) {
                $files[$entry] = $fullPath;
            }
        }
    }

    if (empty($files)) {
        $summary['error'] = 'لا توجد ملفات داخل المجلد.';
        return $summary;
    }

    $categoryName = $categoryName !== '' ? $categoryName : 'منتجات مستوردة';
    $companyName = $companyName !== '' ? $companyName : 'عام';

    $pdo->beginTransaction();
    try {
        $categoryId = db_create_category($pdo, $categoryName, null);
        $companyId = db_create_company($pdo, $categoryId, $companyName, null);

        foreach ($files as $originalName => $path) {
            $savedFilename = handleLocalImageFile($path, 'prod');
            if (!$savedFilename) {
                $summary['skipped']++;
                continue;
            }

            $name = productNameFromFilename($originalName);
            if ($name === '') $name = 'منتج بلا اسم';

            db_create_product($pdo, $companyId, [
                'name' => $name,
                'available' => true,
            ], $savedFilename);

            $summary['products']++;
        }

        if ($summary['products'] === 0) {
            $pdo->rollBack();
            $summary['error'] = 'لم يتم التعرف على أي صورة صالحة داخل الملفات المحددة.';
            return $summary;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $summary['error'] = 'حدث خطأ أثناء الاستيراد: ' . $e->getMessage();
    }

    return $summary;
}

// يبحث عن مجلدات صور جاهزة للاستيراد داخل logs/legacy-imports (كل مجلد فرعي يُعتبر مرشحاً)
function find_legacy_image_folders(string $legacyImportsDir): array {
    $found = [];
    if (!is_dir($legacyImportsDir)) return $found;
    foreach (scandir($legacyImportsDir) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $fullPath = $legacyImportsDir . '/' . $entry;
        if (is_dir($fullPath)) {
            $found[] = ['name' => $entry, 'path' => $fullPath];
        }
    }
    return $found;
}
