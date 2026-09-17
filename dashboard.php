<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$user = require_login();

$projectCount       = (int) db()->query('SELECT COUNT(*) FROM projects')->fetchColumn();
$activeProjectCount  = (int) db()->query("SELECT COUNT(*) FROM projects WHERE status = 'active'")->fetchColumn();

$userCount = null;
if ($user['role'] === 'admin') {
    $userCount = (int) db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
}

$convStmt = db()->prepare('SELECT COUNT(*) FROM ai_conversations WHERE user_id = ?');
$convStmt->execute([$user['id']]);
$myConversations = (int) $convStmt->fetchColumn();

$recentProjects = db()->query(
    'SELECT p.id, p.name, p.status, p.updated_at, u.name AS owner_name
     FROM projects p LEFT JOIN users u ON u.id = p.created_by
     ORDER BY p.updated_at DESC LIMIT 6'
)->fetchAll();

if ($user['role'] === 'admin') {
    $recentActivity = db()->query(
        'SELECT a.action, a.description, a.created_at, u.name AS user_name
         FROM activity_log a LEFT JOIN users u ON u.id = a.user_id
         ORDER BY a.created_at DESC LIMIT 10'
    )->fetchAll();
} else {
    $actStmt = db()->prepare(
        'SELECT a.action, a.description, a.created_at, u.name AS user_name
         FROM activity_log a LEFT JOIN users u ON u.id = a.user_id
         WHERE a.user_id = ? ORDER BY a.created_at DESC LIMIT 10'
    );
    $actStmt->execute([$user['id']]);
    $recentActivity = $actStmt->fetchAll();
}

$activityIcons = [
    'login'        => ['fa-right-to-bracket', 'info'],
    'logout'       => ['fa-right-from-bracket', 'muted'],
    'login_failed' => ['fa-triangle-exclamation', 'danger'],
    'install'      => ['fa-rocket', 'success'],
    'project_create' => ['fa-folder-plus', 'success'],
    'project_delete' => ['fa-trash', 'danger'],
    'context_save'   => ['fa-database', 'info'],
    'github_commit'  => ['fa-code-commit', 'success'],
    'user_create'    => ['fa-user-plus', 'success'],
    'user_update'    => ['fa-user-pen', 'info'],
];

$pageTitle = 'لوحة التحكم';
$activeNav = 'dashboard';
$topbarActions = '<a href="projects.php#new" class="btn btn-primary"><i class="fa-solid fa-plus"></i> مشروع جديد</a>';
require __DIR__ . '/includes/layout_start.php';
?>

<section class="stat-grid">
  <div class="stat-card">
    <div class="stat-icon stat-icon-primary"><i class="fa-solid fa-folder-tree"></i></div>
    <div>
      <div class="stat-value"><?= (int) $projectCount ?></div>
      <div class="stat-label">إجمالي المشاريع</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon stat-icon-success"><i class="fa-solid fa-circle-check"></i></div>
    <div>
      <div class="stat-value"><?= (int) $activeProjectCount ?></div>
      <div class="stat-label">مشاريع نشطة</div>
    </div>
  </div>
  <?php if ($userCount !== null): ?>
  <div class="stat-card">
    <div class="stat-icon stat-icon-info"><i class="fa-solid fa-users"></i></div>
    <div>
      <div class="stat-value"><?= (int) $userCount ?></div>
      <div class="stat-label">المستخدمون</div>
    </div>
  </div>
  <?php endif; ?>
  <div class="stat-card">
    <div class="stat-icon stat-icon-warning"><i class="fa-solid fa-comments"></i></div>
    <div>
      <div class="stat-value"><?= (int) $myConversations ?></div>
      <div class="stat-label">محادثاتي مع المساعد الذكي</div>
    </div>
  </div>
</section>

<div class="content-grid-2">
  <section class="card">
    <div class="card-header">
      <h2><i class="fa-solid fa-folder-tree"></i> أحدث المشاريع</h2>
      <a href="projects.php" class="link-more">عرض الكل <i class="fa-solid fa-arrow-left"></i></a>
    </div>
    <div class="card-body no-pad">
      <?php if (empty($recentProjects)): ?>
        <div class="empty-state">
          <i class="fa-regular fa-folder-open"></i>
          <p>لا توجد مشاريع بعد.</p>
          <a href="projects.php#new" class="btn btn-primary btn-sm"><i class="fa-solid fa-plus"></i> إنشاء أول مشروع</a>
        </div>
      <?php else: ?>
        <ul class="simple-list">
          <?php foreach ($recentProjects as $p): ?>
            <li>
              <a href="project_context.php?id=<?= (int) $p['id'] ?>" class="simple-list-row">
                <span class="row-icon"><i class="fa-solid fa-diagram-project"></i></span>
                <span class="row-main">
                  <span class="row-title"><?= e($p['name']) ?></span>
                  <span class="row-sub">بواسطة <?= e($p['owner_name'] ?? 'مستخدم محذوف') ?> · <?= e(format_date($p['updated_at'])) ?></span>
                </span>
                <span class="badge <?= $p['status'] === 'active' ? 'badge-active' : 'badge-disabled' ?>">
                  <?= $p['status'] === 'active' ? 'نشط' : 'مؤرشف' ?>
                </span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </section>

  <section class="card">
    <div class="card-header">
      <h2><i class="fa-solid fa-clock-rotate-left"></i> آخر النشاطات</h2>
    </div>
    <div class="card-body no-pad">
      <?php if (empty($recentActivity)): ?>
        <div class="empty-state"><i class="fa-regular fa-clock"></i><p>لا يوجد نشاط بعد.</p></div>
      <?php else: ?>
        <ul class="activity-list">
          <?php foreach ($recentActivity as $a):
              [$icon, $color] = $activityIcons[$a['action']] ?? ['fa-circle-info', 'muted'];
          ?>
            <li>
              <span class="activity-dot activity-<?= e($color) ?>"><i class="fa-solid <?= e($icon) ?>"></i></span>
              <span class="row-main">
                <span class="row-title"><?= e($a['description'] ?: $a['action']) ?></span>
                <span class="row-sub"><?= e($a['user_name'] ?? 'النظام') ?> · <?= e(format_date($a['created_at'])) ?></span>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </section>
</div>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
