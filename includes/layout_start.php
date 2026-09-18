<?php
/**
 * رأس الصفحة المشترك (القالب الشبيه بواجهة Claude: قائمة جانبية + محتوى رئيسي).
 * يتطلب أن تكون المتغيرات التالية معرَّفة قبل تضمين هذا الملف:
 *   $user        مصفوفة المستخدم الحالي (من require_login() أو require_admin())
 *   $pageTitle   عنوان الصفحة
 *   $activeNav   المعرّف النشط في القائمة الجانبية: dashboard|projects|users|profile
 *   $topbarActions (اختياري) HTML جاهز لأزرار أعلى الصفحة
 *   $currentProjectNav (اختياري، من project_context.php فقط) = ['project' => صف المشروع, 'tab' => التبويب النشط]
 *     يعرض تبويبات المشروع الحالي (دردشة/كود/Skill/إعدادات) داخل القائمة
 *     الجانبية نفسها بدل شريط منفصل داخل الصفحة - نفس منطق قائمة تطبيق Claude.
 */
$pageTitle     = $pageTitle ?? 'لوحة التحكم';
$activeNav     = $activeNav ?? '';
$topbarActions = $topbarActions ?? '';
$currentProjectNav = $currentProjectNav ?? null;
$appName       = app_config()['app']['name'] ?? 'لوحة إدارة المشاريع';
$sidebarProjects = sidebar_projects_for_user($user);
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($pageTitle) ?> · <?= e($appName) ?></title>
<!-- يطبَّق الوضع الداكن/النهاري المحفوظ فوراً قبل أول رسم لتفادي وميض لون خاطئ -->
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
  try {
    var t = localStorage.getItem('pmdash_theme');
    if (t === 'light' || t === 'dark') { document.documentElement.setAttribute('data-theme', t); }
  } catch (e) {}
})();
</script>
<!-- يضمن أن كل الروابط النسبية (القائمة الجانبية، CSS/JS، النماذج) تُحلّ من
     جذر التطبيق دائماً، حتى عند فتح رابط نظيف معاد كتابته مثل chat/{معرّف}
     حيث يختلف شريط عنوان المتصفح عن مسار السكربت الفعلي المُنفَّذ -->
<base href="<?= e(rtrim(app_config()['app']['url'] ?? '', '/')) ?>/">
<?= csrf_meta() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<div class="app-shell" id="appShell">
  <aside class="app-sidebar" id="appSidebar">
    <div class="sidebar-brand">
      <span class="brand-mark"><i class="fa-solid fa-diagram-project"></i></span>
      <span class="brand-name"><?= e($appName) ?></span>
    </div>

    <nav class="sidebar-nav">
      <a href="dashboard.php" class="nav-item <?= $activeNav === 'dashboard' ? 'active' : '' ?>">
        <i class="fa-solid fa-grip nav-icon"></i><span>لوحة التحكم</span>
      </a>
      <a href="projects.php" class="nav-item <?= $activeNav === 'projects' && !$currentProjectNav ? 'active' : '' ?>">
        <i class="fa-solid fa-folder-tree nav-icon"></i><span>المشاريع</span>
      </a>

      <?php if ($currentProjectNav): ?>
      <div class="sidebar-project-block">
        <div class="sidebar-project-name" title="<?= e($currentProjectNav['project']['name']) ?>">
          <i class="fa-solid fa-diagram-project"></i><span><?= e($currentProjectNav['project']['name']) ?></span>
        </div>
        <a href="<?= e(project_url($currentProjectNav['project'], 'chat')) ?>" class="nav-subitem <?= $currentProjectNav['tab'] === 'chat' ? 'active' : '' ?>">
          <i class="fa-solid fa-comments"></i><span>الدردشة العادية</span>
        </a>
        <a href="<?= e(project_url($currentProjectNav['project'], 'code')) ?>" class="nav-subitem <?= $currentProjectNav['tab'] === 'code' ? 'active' : '' ?>">
          <i class="fa-solid fa-code"></i><span>الكود</span>
        </a>
        <a href="<?= e(project_url($currentProjectNav['project'], 'skill')) ?>" class="nav-subitem <?= $currentProjectNav['tab'] === 'skill' ? 'active' : '' ?>">
          <i class="fa-solid fa-puzzle-piece"></i><span>Skill</span>
        </a>
        <a href="<?= e(project_url($currentProjectNav['project'], 'settings')) ?>" class="nav-subitem <?= $currentProjectNav['tab'] === 'settings' ? 'active' : '' ?>">
          <i class="fa-solid fa-sliders"></i><span>الإعدادات</span>
        </a>
      </div>
      <?php endif; ?>

      <?php if (!empty($sidebarProjects)): ?>
      <div class="sidebar-section-label">مشاريعي</div>
      <div class="sidebar-project-list">
        <?php foreach ($sidebarProjects as $sp): ?>
          <a href="<?= e(project_url($sp, 'chat')) ?>"
             class="sidebar-project-item <?= ($currentProjectNav && (int) $currentProjectNav['project']['id'] === (int) $sp['id']) ? 'active' : '' ?>"
             title="<?= e($sp['name']) ?>">
            <i class="fa-solid fa-circle"></i><span><?= e($sp['name']) ?></span>
          </a>
        <?php endforeach; ?>
      </div>
      <?php elseif ($activeNav === 'projects'): ?>
      <p class="sidebar-empty-hint">لا توجد مشاريع بعد.</p>
      <?php endif; ?>

      <?php if (($user['role'] ?? '') === 'admin'): ?>
      <a href="users.php" class="nav-item <?= $activeNav === 'users' ? 'active' : '' ?>">
        <i class="fa-solid fa-users nav-icon"></i><span>المستخدمون</span>
      </a>
      <a href="ai_providers.php" class="nav-item <?= $activeNav === 'ai_providers' ? 'active' : '' ?>">
        <i class="fa-solid fa-microchip nav-icon"></i><span>مزوّدو الذكاء الاصطناعي</span>
      </a>
      <a href="admin_settings.php" class="nav-item <?= $activeNav === 'admin_settings' ? 'active' : '' ?>">
        <i class="fa-solid fa-gear nav-icon"></i><span>إعدادات النظام</span>
      </a>
      <?php endif; ?>
      <a href="profile.php" class="nav-item <?= $activeNav === 'profile' ? 'active' : '' ?>">
        <i class="fa-solid fa-user-gear nav-icon"></i><span>حسابي</span>
      </a>
    </nav>

    <div class="sidebar-footer">
      <button type="button" class="btn-theme-toggle" id="themeToggle" title="تبديل الوضع الداكن/النهاري">
        <span class="theme-icon-dark"><i class="fa-solid fa-moon"></i> الوضع الداكن</span>
        <span class="theme-icon-light"><i class="fa-solid fa-sun"></i> الوضع النهاري</span>
      </button>
      <div class="user-chip">
        <span class="user-avatar"><?= e(mb_strtoupper(mb_substr($user['name'] ?? '?', 0, 1, 'UTF-8'), 'UTF-8')) ?></span>
        <span class="user-meta">
          <span class="user-name" title="<?= e($user['name'] ?? '') ?>"><?= e($user['name'] ?? '') ?></span>
          <span class="badge <?= ($user['role'] ?? '') === 'admin' ? 'badge-admin' : 'badge-user' ?>">
            <?= ($user['role'] ?? '') === 'admin' ? 'مسؤول' : 'مستخدم' ?>
          </span>
        </span>
      </div>
      <form action="logout.php" method="post" style="width:100%">
        <?= csrf_field() ?>
        <button type="submit" class="btn-logout" title="تسجيل الخروج">
          <i class="fa-solid fa-arrow-right-from-bracket"></i>
          <span>تسجيل الخروج</span>
        </button>
      </form>
    </div>
  </aside>

  <div class="app-overlay" id="appOverlay"></div>

  <main class="app-main">
    <header class="topbar">
      <button type="button" class="mobile-toggle" id="mobileToggle" aria-label="فتح القائمة">
        <i class="fa-solid fa-bars"></i>
      </button>
      <h1 class="topbar-title"><?= e($pageTitle) ?></h1>
      <div class="topbar-actions"><?= $topbarActions ?></div>
    </header>

    <div class="app-content">
      <?php $flashSuccess = flash('success'); ?>
      <?php $flashError = flash('error'); ?>
      <?php if ($flashSuccess): ?>
        <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i><span><?= e($flashSuccess) ?></span></div>
      <?php endif; ?>
      <?php if ($flashError): ?>
        <div class="alert alert-error"><i class="fa-solid fa-triangle-exclamation"></i><span><?= e($flashError) ?></span></div>
      <?php endif; ?>
