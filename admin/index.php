<?php
require_once __DIR__ . '/includes/bootstrap.php';
admin_require_login();
require_once __DIR__ . '/includes/layout.php';

$stats = db_get_full_stats($pdo);

admin_header('لوحة المعلومات', 'index.php', 'نظرة عامة سريعة على المتجر');
?>

<div class="grid cols-4">
    <div class="stat-card"><i class="fas fa-folder-tree"></i><div class="num"><?php echo (int)$stats['total_categories']; ?></div><div class="lbl">الفئات</div></div>
    <div class="stat-card"><i class="fas fa-building"></i><div class="num"><?php echo (int)$stats['total_companies']; ?></div><div class="lbl">الشركات</div></div>
    <div class="stat-card"><i class="fas fa-box"></i><div class="num"><?php echo (int)$stats['total_products']; ?></div><div class="lbl">المنتجات</div></div>
    <div class="stat-card"><i class="fas fa-concierge-bell"></i><div class="num"><?php echo (int)$stats['total_services']; ?></div><div class="lbl">الخدمات</div></div>
</div>

<div class="grid cols-4" style="margin-top:16px;">
    <div class="stat-card"><i class="fas fa-users"></i><div class="num"><?php echo (int)$stats['total_users']; ?></div><div class="lbl">المستخدمون</div></div>
    <div class="stat-card"><i class="fas fa-eye"></i><div class="num"><?php echo (int)$stats['total_visitors']; ?></div><div class="lbl">الزيارات</div></div>
    <div class="stat-card"><i class="fas fa-heart"></i><div class="num"><?php echo (int)$stats['total_favorites']; ?></div><div class="lbl">المفضلات</div></div>
    <div class="stat-card"><i class="fas fa-cart-shopping"></i><div class="num"><?php echo (int)$stats['total_orders']; ?></div><div class="lbl">الطلبات</div></div>
</div>

<div class="card" style="margin-top:24px;">
    <h2><i class="fas fa-bolt"></i> اختصارات سريعة</h2>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <a class="btn btn-outline" href="categories.php"><i class="fas fa-plus"></i> إضافة فئة</a>
        <a class="btn btn-outline" href="companies.php"><i class="fas fa-plus"></i> إضافة شركة</a>
        <a class="btn btn-outline" href="products.php"><i class="fas fa-plus"></i> إضافة منتج</a>
        <a class="btn btn-outline" href="services.php"><i class="fas fa-plus"></i> إضافة خدمة</a>
        <a class="btn btn-outline" href="import.php"><i class="fas fa-file-import"></i> استيراد بيانات قديمة</a>
    </div>
</div>

<?php admin_footer(); ?>
