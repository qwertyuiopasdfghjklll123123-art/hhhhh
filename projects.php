<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$user = require_login();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $formAction = (string) ($_POST['form_action'] ?? '');

    if ($formAction === 'create_project') {
        $name        = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        if ($name === '') {
            flash('error', 'اسم المشروع مطلوب.');
            redirect('projects.php');
        }
        $newSlug = generate_project_slug();
        db()->prepare('INSERT INTO projects (public_slug, name, description, created_by) VALUES (?, ?, ?, ?)')
            ->execute([$newSlug, $name, $description !== '' ? $description : null, $user['id']]);
        $projectId = (int) db()->lastInsertId();
        db()->prepare('INSERT INTO project_context (project_id) VALUES (?)')->execute([$projectId]);
        log_activity((int) $user['id'], 'project_create', "إنشاء مشروع: {$name}");
        flash('success', 'تم إنشاء المشروع بنجاح. أكمل بيانات السياق والتكامل الآن.');
        redirect(project_url(['id' => $projectId, 'public_slug' => $newSlug], 'settings'));
    }

    if ($formAction === 'toggle_archive' || $formAction === 'delete_project') {
        $id = (int) ($_POST['id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM projects WHERE id = ?');
        $stmt->execute([$id]);
        $project = $stmt->fetch();
        if (!$project) {
            flash('error', 'المشروع غير موجود.');
            redirect('projects.php');
        }
        $canManage = $user['role'] === 'admin' || (int) $project['created_by'] === (int) $user['id'];
        if (!$canManage) {
            flash('error', 'ليست لديك صلاحية لتعديل هذا المشروع.');
            redirect('projects.php');
        }

        if ($formAction === 'toggle_archive') {
            $newStatus = $project['status'] === 'active' ? 'archived' : 'active';
            db()->prepare('UPDATE projects SET status = ? WHERE id = ?')->execute([$newStatus, $id]);
            log_activity((int) $user['id'], 'project_update', ($newStatus === 'archived' ? 'أرشفة' : 'إعادة تفعيل') . " المشروع: {$project['name']}");
            flash('success', $newStatus === 'archived' ? 'تم أرشفة المشروع.' : 'تم إعادة تفعيل المشروع.');
        } else {
            db()->prepare('DELETE FROM projects WHERE id = ?')->execute([$id]);
            log_activity((int) $user['id'], 'project_delete', "حذف المشروع: {$project['name']}");
            flash('success', 'تم حذف المشروع نهائياً.');
        }
        redirect('projects.php');
    }

    flash('error', 'إجراء غير معروف.');
    redirect('projects.php');
}

// عزل المشاريع: كل مستخدم يرى مشاريعه فقط، والأدمن وحده يرى الجميع.
if ($user['role'] === 'admin') {
    $allProjects = db()->query(
        "SELECT p.*, u.name AS owner_name,
            (SELECT COUNT(*) FROM ai_conversations c WHERE c.project_id = p.id) AS conv_count
         FROM projects p
         LEFT JOIN users u ON u.id = p.created_by
         ORDER BY CASE WHEN p.status = 'active' THEN 0 ELSE 1 END, p.updated_at DESC"
    )->fetchAll();
} else {
    $stmt = db()->prepare(
        "SELECT p.*, u.name AS owner_name,
            (SELECT COUNT(*) FROM ai_conversations c WHERE c.project_id = p.id) AS conv_count
         FROM projects p
         LEFT JOIN users u ON u.id = p.created_by
         WHERE p.created_by = ?
         ORDER BY CASE WHEN p.status = 'active' THEN 0 ELSE 1 END, p.updated_at DESC"
    );
    $stmt->execute([$user['id']]);
    $allProjects = $stmt->fetchAll();
}

$pageTitle     = 'المشاريع';
$activeNav     = 'projects';
$topbarActions = '<button type="button" class="btn btn-primary" data-action="open-modal" data-modal="modalNewProject"><i class="fa-solid fa-plus"></i> مشروع جديد</button>';
require __DIR__ . '/includes/layout_start.php';
?>

<?php if (empty($allProjects)): ?>
  <div class="empty-state empty-state-lg">
    <i class="fa-regular fa-folder-open"></i>
    <p>لا توجد مشاريع بعد. أنشئ أول مشروع لربطه بمستودع GitHub والمساعد الذكي.</p>
    <button type="button" class="btn btn-primary" data-action="open-modal" data-modal="modalNewProject"><i class="fa-solid fa-plus"></i> إنشاء مشروع</button>
  </div>
<?php else: ?>
  <div class="project-grid">
    <?php foreach ($allProjects as $p):
        $canManage = $user['role'] === 'admin' || (int) $p['created_by'] === (int) $user['id'];
    ?>
    <article class="project-card">
      <div class="project-card-top">
        <span class="badge <?= $p['status'] === 'active' ? 'badge-active' : 'badge-disabled' ?>"><?= $p['status'] === 'active' ? 'نشط' : 'مؤرشف' ?></span>
        <?php if ($canManage): ?>
        <div class="project-card-menu">
          <form method="post" action="projects.php" class="inline-form">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="toggle_archive">
            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <button type="submit" class="btn-icon" title="<?= $p['status'] === 'active' ? 'أرشفة' : 'إعادة تفعيل' ?>">
              <i class="fa-solid <?= $p['status'] === 'active' ? 'fa-box-archive' : 'fa-rotate-left' ?>"></i>
            </button>
          </form>
          <form method="post" action="projects.php" class="inline-form" data-confirm="حذف مشروع «<?= e($p['name']) ?>» نهائياً؟ سيُحذف كل السياق والمحادثات المرتبطة به.">
            <?= csrf_field() ?>
            <input type="hidden" name="form_action" value="delete_project">
            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
            <button type="submit" class="btn-icon btn-icon-danger" title="حذف"><i class="fa-solid fa-trash"></i></button>
          </form>
        </div>
        <?php endif; ?>
      </div>
      <a href="<?= e(project_url($p, 'chat')) ?>" class="project-card-body">
        <h3><?= e($p['name']) ?></h3>
        <p><?= e($p['description'] !== null && $p['description'] !== '' ? truncate($p['description'], 110) : 'لا يوجد وصف لهذا المشروع.') ?></p>
      </a>
      <div class="project-card-footer">
        <span title="المالك"><i class="fa-regular fa-user"></i> <?= e($p['owner_name'] ?? 'مستخدم محذوف') ?></span>
        <span title="عدد المحادثات"><i class="fa-regular fa-comments"></i> <?= (int) $p['conv_count'] ?></span>
        <span title="آخر تحديث"><i class="fa-regular fa-clock"></i> <?= e(format_date($p['updated_at'])) ?></span>
      </div>
    </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="modal-backdrop" id="modalNewProject">
  <div class="modal">
    <div class="modal-header">
      <h3><i class="fa-solid fa-diagram-project"></i> مشروع جديد</h3>
      <button type="button" class="btn-icon-only" data-action="close-modal"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="post" action="projects.php">
      <?= csrf_field() ?>
      <input type="hidden" name="form_action" value="create_project">
      <div class="modal-body stack-form">
        <div class="form-group">
          <label class="form-label">اسم المشروع</label>
          <input class="form-control" type="text" name="name" required autofocus>
        </div>
        <div class="form-group">
          <label class="form-label">وصف مختصر (اختياري)</label>
          <textarea class="form-control" name="description" rows="3"></textarea>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-action="close-modal">إلغاء</button>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-check"></i> إنشاء</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
