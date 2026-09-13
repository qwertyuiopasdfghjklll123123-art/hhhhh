<?php
// المفاتيح أسماء نظيفة بلا امتداد .php - تُستخدم في الروابط مباشرة وتُطابَق مع
// $active القادم من admin_header(). قاعدة إعادة الكتابة في .htaccess تحوّل
// /admin/<name> إلى admin/<name>.php فعلياً على مستوى السيرفر
$ADMIN_NAV = [
    'index' => ['icon' => 'fa-gauge-high', 'label' => 'لوحة المعلومات'],
    'categories' => ['icon' => 'fa-folder-tree', 'label' => 'الفئات'],
    'companies' => ['icon' => 'fa-building', 'label' => 'الشركات'],
    'products' => ['icon' => 'fa-box', 'label' => 'المنتجات'],
    'services' => ['icon' => 'fa-concierge-bell', 'label' => 'الخدمات'],
    'users' => ['icon' => 'fa-users', 'label' => 'المستخدمون'],
    'settings' => ['icon' => 'fa-gear', 'label' => 'الإعدادات'],
    'import' => ['icon' => 'fa-rotate', 'label' => 'الاستيراد والتصدير'],
];

function admin_header($title, $active = '', $subtitle = '') {
    global $ADMIN_NAV;
    $appName = 'Almulla';
    ?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo e($title); ?> | لوحة تحكم <?php echo e($appName); ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/admin.css">
</head>
<body>
<div class="bg-orb o1"></div>
<div class="bg-orb o2"></div>
<div class="sidebar-overlay"></div>

<div class="admin-shell">
    <aside class="sidebar">
        <div class="brand">⚡ <?php echo e($appName); ?><small>لوحة التحكم</small></div>
        <?php foreach ($ADMIN_NAV as $name => $item): ?>
            <a class="nav-item <?php echo $active === $name ? 'active' : ''; ?>" href="<?php echo $name === 'index' ? './' : e($name); ?>">
                <i class="fas <?php echo e($item['icon']); ?>"></i> <?php echo e($item['label']); ?>
            </a>
        <?php endforeach; ?>
        <div class="nav-sep"></div>
        <a class="nav-item" href="../" target="_blank"><i class="fas fa-arrow-up-right-from-square"></i> عرض الموقع</a>
        <a class="nav-item" href="logout"><i class="fas fa-right-from-bracket"></i> تسجيل الخروج</a>
        <div class="nav-foot">مسجّل الدخول: <?php echo e($_SESSION['user_name'] ?? ''); ?></div>
    </aside>

    <main class="main">
        <div class="topbar">
            <div style="display:flex;align-items:center;gap:12px;">
                <button class="menu-toggle" aria-label="القائمة"><i class="fas fa-bars"></i></button>
                <div>
                    <h1><?php echo e($title); ?></h1>
                    <?php if ($subtitle): ?><div class="sub"><?php echo e($subtitle); ?></div><?php endif; ?>
                </div>
            </div>
            <div class="admin-user">
                <div class="av"><?php echo e(mb_substr($_SESSION['user_name'] ?? 'A', 0, 1)); ?></div>
                <span><?php echo e($_SESSION['user_email'] ?? ''); ?></span>
            </div>
        </div>

        <?php foreach (admin_get_flashes() as $f): ?>
            <div class="alert alert-<?php echo e($f['type']); ?>"><?php echo e($f['message']); ?></div>
        <?php endforeach; ?>
    <?php
}

function admin_footer() {
    ?>
    </main>
</div>
<script src="assets/admin.js"></script>
</body>
</html>
    <?php
}
