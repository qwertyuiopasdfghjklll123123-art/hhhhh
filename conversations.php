<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$user = require_login();

$requestedMode = (string) ($_GET['mode'] ?? 'chat');
$mode = $requestedMode === 'code' ? 'code' : 'chat';

$myProjects = sidebar_projects_for_user($user, 100);
$recentConversations = recent_conversations_for_user($user, $mode, 40);

$pageTitle = $mode === 'code' ? 'الكود' : 'الدردشة';
$activeNav = $mode === 'code' ? 'hub_code' : 'hub_chat';
require __DIR__ . '/includes/layout_start.php';
?>

<div class="hub-page">
  <section class="card hub-start">
    <div class="card-header">
      <h2>
        <i class="fa-solid <?= $mode === 'code' ? 'fa-code' : 'fa-comments' ?>"></i>
        <?= $mode === 'code' ? 'بدء جلسة كود جديدة' : 'بدء محادثة جديدة' ?>
      </h2>
    </div>
    <div class="card-body">
      <?php if (empty($myProjects)): ?>
        <div class="empty-state">
          <i class="fa-regular fa-folder-open"></i>
          <p>لا توجد مشاريع بعد. أنشئ مشروعاً أولاً لتتمكن من بدء <?= $mode === 'code' ? 'جلسة كود' : 'محادثة' ?>.</p>
          <a href="projects.php" class="btn btn-primary"><i class="fa-solid fa-plus"></i> إنشاء مشروع</a>
        </div>
      <?php elseif (count($myProjects) === 1): ?>
        <a href="<?= e(project_url($myProjects[0], $mode)) ?>" class="btn btn-primary btn-block btn-lg">
          <i class="fa-solid fa-plus"></i>
          <?= $mode === 'code' ? 'فتح جلسة الكود' : 'بدء محادثة جديدة' ?> — <?= e($myProjects[0]['name']) ?>
        </a>
      <?php else: ?>
        <p class="form-hint">اختر مشروعاً لبدء <?= $mode === 'code' ? 'جلسة كود' : 'محادثة' ?> جديدة فيه:</p>
        <div class="hub-project-picker">
          <?php foreach ($myProjects as $p): ?>
            <a href="<?= e(project_url($p, $mode)) ?>" class="hub-project-chip">
              <i class="fa-solid fa-diagram-project"></i> <?= e($p['name']) ?>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>

  <section class="hub-recent">
    <h2 class="hub-recent-title"><?= $mode === 'code' ? 'جلسات الكود' : 'آخر المحادثات' ?></h2>
    <?php if (empty($recentConversations)): ?>
      <div class="empty-state">
        <i class="fa-regular fa-comment-dots"></i>
        <p>لا توجد <?= $mode === 'code' ? 'جلسات كود' : 'محادثات' ?> بعد.</p>
      </div>
    <?php else: ?>
      <div class="hub-conv-list">
        <?php foreach ($recentConversations as $c):
            $convProject = ['id' => $c['project_id'], 'public_slug' => $c['project_public_slug']];
            $convUrl = $mode === 'code' ? project_url($convProject, 'code') : project_url($convProject, 'chat', (int) $c['id']);
        ?>
          <a class="hub-conv-item" href="<?= e($convUrl) ?>">
            <div class="hub-conv-icon"><i class="fa-solid <?= $mode === 'code' ? 'fa-code' : 'fa-message' ?>"></i></div>
            <div class="hub-conv-body">
              <div class="hub-conv-title"><?= e($c['title']) ?></div>
              <div class="hub-conv-meta"><?= e($c['project_name']) ?> · <?= e(format_date($c['updated_at'])) ?></div>
            </div>
            <i class="fa-solid fa-chevron-left hub-conv-arrow"></i>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>
</div>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
