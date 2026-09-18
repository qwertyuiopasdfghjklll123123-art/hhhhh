<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$user = require_login();

$slug = trim((string) ($_GET['slug'] ?? ''));
if ($slug !== '') {
    $stmt = db()->prepare('SELECT p.*, u.name AS owner_name FROM projects p LEFT JOIN users u ON u.id = p.created_by WHERE p.public_slug = ?');
    $stmt->execute([$slug]);
} else {
    $stmt = db()->prepare('SELECT p.*, u.name AS owner_name FROM projects p LEFT JOIN users u ON u.id = p.created_by WHERE p.id = ?');
    $stmt->execute([(int) ($_GET['id'] ?? 0)]);
}
$project = $stmt->fetch();
if (!$project) {
    flash('error', 'المشروع غير موجود.');
    redirect('projects.php');
}
$projectId = (int) $project['id'];

// عزل المشاريع: منشئ المشروع أو أدمن فقط يمكنهما فتحه.
$isOwner = (int) ($project['created_by'] ?? 0) === (int) $user['id'];
if ($user['role'] !== 'admin' && !$isOwner) {
    flash('error', 'ليست لديك صلاحية الوصول لهذا المشروع.');
    redirect('projects.php');
}

$ctxStmt = db()->prepare('SELECT * FROM project_context WHERE project_id = ?');
$ctxStmt->execute([$projectId]);
$context = $ctxStmt->fetch();
if (!$context) {
    db()->prepare('INSERT INTO project_context (project_id) VALUES (?)')->execute([$projectId]);
    $ctxStmt->execute([$projectId]);
    $context = $ctxStmt->fetch();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $formAction = (string) ($_POST['form_action'] ?? '');

    if ($formAction === 'update_project_info') {
        $name        = trim((string) ($_POST['name'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        if ($name === '') {
            flash('error', 'اسم المشروع مطلوب.');
        } else {
            db()->prepare('UPDATE projects SET name = ?, description = ? WHERE id = ?')
                ->execute([$name, $description !== '' ? $description : null, $projectId]);
            log_activity((int) $user['id'], 'project_update', "تعديل بيانات المشروع: {$name}");
            flash('success', 'تم تحديث بيانات المشروع.');
        }
        redirect(project_url($project, 'settings'));
    }

    if ($formAction === 'save_context') {
        $githubOwner  = trim((string) ($_POST['github_owner'] ?? ''));
        $githubRepo   = trim((string) ($_POST['github_repo'] ?? ''));
        $githubBranch = trim((string) ($_POST['github_branch'] ?? '')) ?: 'main';

        db()->prepare(
            'UPDATE project_context SET github_owner = ?, github_repo = ?, github_branch = ? WHERE project_id = ?'
        )->execute([
            $githubOwner !== '' ? $githubOwner : null,
            $githubRepo !== '' ? $githubRepo : null,
            $githubBranch,
            $projectId,
        ]);

        log_activity((int) $user['id'], 'context_save', "تحديث بيانات GitHub لمشروع: {$project['name']}");
        flash('success', 'تم حفظ إعدادات GitHub بنجاح.');
        redirect(project_url($project, 'settings'));
    }

    if ($formAction === 'create_skill' || $formAction === 'update_skill') {
        $title   = trim((string) ($_POST['title'] ?? ''));
        $content = (string) ($_POST['content'] ?? '');

        if (trim($content) === '') {
            flash('error', 'محتوى Skill مطلوب.');
            redirect(project_url($project, 'skill'));
        }
        if (mb_strlen($content) > 100000) {
            flash('error', 'محتوى Skill طويل جداً (الحد الأقصى 100000 حرف).');
            redirect(project_url($project, 'skill'));
        }
        $title = $title !== '' ? $title : 'سياق بلا عنوان';

        if ($formAction === 'create_skill') {
            db()->prepare('INSERT INTO project_skills (project_id, title, content) VALUES (?, ?, ?)')
                ->execute([$projectId, $title, $content]);
            flash('success', 'تمت إضافة سياق Skill جديد.');
        } else {
            $skillId = (int) ($_POST['id'] ?? 0);
            db()->prepare('UPDATE project_skills SET title = ?, content = ? WHERE id = ? AND project_id = ?')
                ->execute([$title, $content, $skillId, $projectId]);
            flash('success', 'تم تحديث سياق Skill.');
        }
        log_activity((int) $user['id'], 'skill_save', "حفظ سياق Skill في مشروع: {$project['name']}");
        redirect(project_url($project, 'skill'));
    }

    if ($formAction === 'delete_skill') {
        $skillId = (int) ($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM project_skills WHERE id = ? AND project_id = ?')->execute([$skillId, $projectId]);
        flash('success', 'تم حذف سياق Skill.');
        redirect(project_url($project, 'skill'));
    }

    flash('error', 'إجراء غير معروف.');
    redirect(project_url($project, 'chat'));
}

$ctxStmt->execute([$projectId]);
$context = $ctxStmt->fetch();

$providers = db()->query('SELECT * FROM ai_providers ORDER BY is_default DESC, created_at ASC')->fetchAll();

$effectiveGithubToken = resolve_github_token($context, $user);
$hasGithubToken  = $effectiveGithubToken !== null;
$hasGithubOauth  = !empty($user['github_oauth_username']);
$githubConfigured = $hasGithubToken && !empty($context['github_owner']) && !empty($context['github_repo']);

$validTabs = ['chat', 'code', 'settings', 'skill'];
$requestedTab = (string) ($_GET['tab'] ?? 'chat');
$activeTab = in_array($requestedTab, $validTabs, true) ? $requestedTab : 'chat';
$isCode = $activeTab === 'code';

// وضع الكود: محادثة واحدة مستمرة فقط لكل مستخدم بهذا المشروع (بلا رواق/محادثات متعددة)،
// تماماً مثل صفحة دردشة واحدة مع مساعد كود. الدردشة العادية تبقى تدعم عدة محادثات.
$codeConvId = 0;
$conversationList = [];
if ($activeTab === 'chat' || $activeTab === 'code') {
    if ($isCode) {
        $codeConvStmt = db()->prepare("SELECT id FROM ai_conversations WHERE project_id = ? AND user_id = ? AND mode = 'code' ORDER BY id ASC LIMIT 1");
        $codeConvStmt->execute([$projectId, $user['id']]);
        $codeConvId = (int) ($codeConvStmt->fetchColumn() ?: 0);
    } else {
        $convStmt = db()->prepare('SELECT id, title, updated_at FROM ai_conversations WHERE project_id = ? AND user_id = ? AND mode = ? ORDER BY updated_at DESC');
        $convStmt->execute([$projectId, $user['id'], 'chat']);
        $conversationList = $convStmt->fetchAll();
    }
}

$skills = [];
if ($activeTab === 'skill') {
    $skillsStmt = db()->prepare('SELECT * FROM project_skills WHERE project_id = ? ORDER BY updated_at DESC');
    $skillsStmt->execute([$projectId]);
    $skills = $skillsStmt->fetchAll();
}

$providersForJs = array_map(static fn (array $p): array => [
    'id'           => (int) $p['id'],
    'label'        => $p['label'],
    'text_model'   => $p['text_model'],
    'vision_model' => $p['vision_model'],
], $providers);

$pageTitle     = $project['name'];
$activeNav     = 'projects';
$currentProjectNav = ['project' => $project, 'tab' => $activeTab];
$topbarActions = '<a href="projects.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-right"></i> رجوع للمشاريع</a>';
require __DIR__ . '/includes/layout_start.php';
?>

<?php if ($activeTab === 'settings'): ?>

<div class="settings-stack">
  <section class="card">
    <div class="card-header"><h2><i class="fa-solid fa-circle-info"></i> بيانات المشروع</h2></div>
    <div class="card-body">
      <form method="post" action="<?= e(project_url($project, 'settings')) ?>" class="stack-form">
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="update_project_info">
        <div class="form-group">
          <label class="form-label">اسم المشروع</label>
          <input class="form-control" type="text" name="name" value="<?= e($project['name']) ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">الوصف</label>
          <textarea class="form-control" name="description" rows="2"><?= e($project['description'] ?? '') ?></textarea>
        </div>
        <button type="submit" class="btn btn-secondary"><i class="fa-solid fa-check"></i> حفظ</button>
      </form>
    </div>
  </section>

  <form method="post" action="<?= e(project_url($project, 'settings')) ?>" class="stack-form">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="save_context">

    <section class="card">
      <div class="card-header"><h2><i class="fa-brands fa-github"></i> بيانات الوصول السحابية (GitHub)</h2></div>
      <div class="card-body">
        <div class="github-connect-status">
          <?php if ($hasGithubOauth): ?>
            <span class="badge badge-active"><i class="fa-solid fa-circle-check"></i> حسابك مرتبط: @<?= e($user['github_oauth_username']) ?></span>
            <a href="profile.php" class="link-muted">إدارة الربط من صفحة حسابي</a>
          <?php else: ?>
            <span class="badge badge-disabled"><i class="fa-solid fa-circle-exclamation"></i> لم تربط حساب GitHub بعد</span>
            <a href="github_oauth_start.php?<?= http_build_query(['return' => project_url($project, 'settings')]) ?>" class="btn btn-secondary btn-sm"><i class="fa-brands fa-github"></i> ربط حساب GitHub</a>
          <?php endif; ?>
        </div>
        <p class="form-hint" style="margin:12px 0 16px">حدّد المستودع والفرع الذي يعمل عليه هذا المشروع. تُستخدم صلاحيات حسابك المرتبط تلقائياً للقراءة والرفع (Commit) — لا حاجة لإدخال أي Personal Access Token يدوياً بعد الآن.</p>
        <?php if ($hasGithubOauth): ?>
          <button type="button" class="btn btn-secondary btn-sm" id="btnPickRepo" style="margin-bottom:14px"><i class="fa-solid fa-list-ul"></i> اختر من مستودعاتك</button>
        <?php endif; ?>
        <div class="form-grid-2">
          <div class="form-group">
            <label class="form-label">Owner / Organization</label>
            <input class="form-control" type="text" name="github_owner" id="githubOwnerInput" value="<?= e($context['github_owner'] ?? '') ?>" placeholder="octocat">
          </div>
          <div class="form-group">
            <label class="form-label">Repository</label>
            <input class="form-control" type="text" name="github_repo" id="githubRepoInput" value="<?= e($context['github_repo'] ?? '') ?>" placeholder="my-repo">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Branch</label>
          <input class="form-control" type="text" name="github_branch" id="githubBranchInput" value="<?= e($context['github_branch'] ?? 'main') ?>" placeholder="main">
        </div>
      </div>
    </section>

    <button type="submit" class="btn btn-primary btn-lg"><i class="fa-solid fa-floppy-disk"></i> حفظ إعدادات GitHub</button>
  </form>

  <?php if (($user['role'] ?? '') === 'admin'): ?>
  <section class="card">
    <div class="card-header"><h2><i class="fa-solid fa-microchip"></i> مزوّدو الذكاء الاصطناعي</h2></div>
    <div class="card-body">
      <p class="form-hint" style="margin:0 0 14px">مزوّدو الذكاء الاصطناعي أصبحوا مشتركين بين كل المشاريع والمحادثات، ويُداران من صفحة واحدة على مستوى النظام.</p>
      <a href="ai_providers.php" class="btn btn-secondary btn-sm"><i class="fa-solid fa-arrow-left"></i> إدارة المزوّدين</a>
    </div>
  </section>
  <?php endif; ?>
</div>

<div class="modal-backdrop" id="modalRepoPicker">
  <div class="modal modal-lg">
    <div class="modal-header">
      <h3><i class="fa-brands fa-github"></i> اختيار مستودع</h3>
      <button type="button" class="btn-icon-only" data-action="close-modal"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <input class="form-control repo-picker-search" type="text" id="repoPickerSearch" placeholder="ابحث باسم المستودع...">
      <div class="repo-picker-list" id="repoPickerList">
        <div class="empty-state"><i class="fa-solid fa-spinner fa-spin"></i><p>يتم التحميل...</p></div>
      </div>
    </div>
  </div>
</div>

<?php elseif ($activeTab === 'skill'): ?>

<div class="settings-stack">
  <section class="card">
    <div class="card-header">
      <h2><i class="fa-solid fa-plus"></i> إضافة سياق Skill جديد</h2>
      <label class="btn btn-secondary btn-sm" for="skillFileInput"><i class="fa-solid fa-paperclip"></i> إرفاق ملف نصي</label>
    </div>
    <div class="card-body">
      <p class="form-hint">سياق إضافي دائم يُحقن تلقائياً ضمن موجّه النظام في كل رسالة (دردشة عادية أو كود) لهذا المشروع. يمكنك إضافة أكثر من مقتطف، كلٌّ بعنوانه الخاص — مثلاً دليل أسلوب الفريق، ملخص واجهة API خارجية، أو أي معرفة ثابتة تريد أن يعرفها المساعد دائماً.</p>
      <form method="post" action="<?= e(project_url($project, 'skill')) ?>" class="stack-form">
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="create_skill">
        <input type="file" id="skillFileInput" accept=".txt,.md,.markdown,.json,.csv,.log,.yml,.yaml,text/plain" hidden>
        <div class="form-group">
          <label class="form-label">العنوان</label>
          <input class="form-control" type="text" name="title" id="skillTitleInput" placeholder="مثال: دليل أسلوب الفريق" maxlength="190">
        </div>
        <div class="form-group">
          <textarea class="form-control code-textarea" name="content" id="skillContent" rows="7" placeholder="اكتب النص هنا..." required></textarea>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> حفظ</button>
      </form>
    </div>
  </section>

  <?php if (empty($skills)): ?>
    <div class="empty-state"><i class="fa-solid fa-puzzle-piece"></i><p>لا يوجد أي سياق Skill بعد لهذا المشروع.</p></div>
  <?php else: foreach ($skills as $sk): ?>
    <section class="card skill-card">
      <div class="card-header">
        <h2><i class="fa-solid fa-note-sticky"></i> <?= e($sk['title']) ?></h2>
        <span class="form-hint" style="margin:0">آخر تحديث: <?= e(format_date($sk['updated_at'])) ?></span>
      </div>
      <div class="card-body">
        <form method="post" action="<?= e(project_url($project, 'skill')) ?>" class="stack-form">
          <?= csrf_field() ?>
          <input type="hidden" name="form_action" value="update_skill">
          <input type="hidden" name="id" value="<?= (int) $sk['id'] ?>">
          <div class="form-group">
            <label class="form-label">العنوان</label>
            <input class="form-control" type="text" name="title" value="<?= e($sk['title']) ?>" maxlength="190">
          </div>
          <div class="form-group">
            <textarea class="form-control code-textarea" name="content" rows="6" required><?= e($sk['content']) ?></textarea>
          </div>
          <div class="skill-card-actions">
            <button type="submit" class="btn btn-secondary btn-sm"><i class="fa-solid fa-floppy-disk"></i> حفظ التعديل</button>
          </div>
        </form>
        <form method="post" action="<?= e(project_url($project, 'skill')) ?>" class="inline-form" data-confirm="حذف سياق «<?= e($sk['title']) ?>»؟" style="margin-top:10px">
          <?= csrf_field() ?>
          <input type="hidden" name="form_action" value="delete_skill">
          <input type="hidden" name="id" value="<?= (int) $sk['id'] ?>">
          <button type="submit" class="btn-outline-danger" title="حذف"><i class="fa-solid fa-trash"></i> حذف هذا السياق</button>
        </form>
      </div>
    </section>
  <?php endforeach; endif; ?>
</div>

<?php else: /* ===================== تبويب "الدردشة العادية" أو "الكود" ===================== */ ?>

<?php if ($isCode && !$githubConfigured): ?>

<div class="empty-state empty-state-lg">
  <i class="fa-solid fa-code-branch"></i>
  <h3>فعّل قسم الكود</h3>
  <p>قسم الكود يعمل أشبه بـ Claude Code: يستكشف مستودع GitHub المرتبط بهذا المشروع تلقائياً ويحاول حل المشكلة أو تنفيذ الطلب اعتماداً على ملفاته الفعلية. يحتاج هذا حساب GitHub مرتبطاً ومستودعاً محدَّداً لهذا المشروع أولاً.</p>
  <?php if (!$hasGithubOauth): ?>
    <a href="github_oauth_start.php?<?= http_build_query(['return' => project_url($project, 'code')]) ?>" class="btn btn-primary"><i class="fa-brands fa-github"></i> ربط حساب GitHub</a>
  <?php else: ?>
    <a href="<?= e(project_url($project, 'settings')) ?>" class="btn btn-primary"><i class="fa-solid fa-sliders"></i> حدّد المستودع من الإعدادات</a>
  <?php endif; ?>
</div>

<?php else: ?>

<div class="chat-layout <?= $isCode ? 'no-rail' : '' ?>" id="chatLayout"
     data-project-id="<?= $projectId ?>"
     data-mode="<?= $isCode ? 'code' : 'chat' ?>"
     data-has-github="<?= $hasGithubToken ? '1' : '0' ?>"
     data-has-provider="<?= empty($providers) ? '0' : '1' ?>"
     data-code-conv-id="<?= $codeConvId ?>"
     data-providers="<?= e(json_encode($providersForJs, JSON_UNESCAPED_UNICODE)) ?>">
  <?php if (!$isCode): ?>
  <aside class="chat-rail">
    <button type="button" class="btn btn-primary btn-block" id="btnNewChat"><i class="fa-solid fa-plus"></i> محادثة جديدة</button>
    <div class="chat-rail-list" id="chatRailList">
      <?php if (empty($conversationList)): ?>
        <p class="chat-rail-empty">لا توجد محادثات بعد.</p>
      <?php else: ?>
        <?php foreach ($conversationList as $c): ?>
          <div class="conv-item" data-conv-id="<?= (int) $c['id'] ?>">
            <button type="button" class="conv-item-btn">
              <i class="fa-regular fa-message"></i>
              <span class="conv-item-text"><?= e($c['title']) ?></span>
            </button>
            <button type="button" class="conv-item-del" data-action="delete-conv" data-id="<?= (int) $c['id'] ?>" title="حذف المحادثة">
              <i class="fa-solid fa-trash"></i>
            </button>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </aside>
  <?php endif; ?>

  <section class="chat-pane">
    <?php if (empty($providers)): ?>
      <div class="alert alert-warning chat-config-warning">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <span>
          لم يتم إضافة أي مزوّد ذكاء اصطناعي بعد.
          <?php if (($user['role'] ?? '') === 'admin'): ?>
            <a href="ai_providers.php">أضف واحداً من هنا</a> لتفعيل المساعد الذكي.
          <?php else: ?>
            يجب على مسؤول النظام إضافة واحد لتفعيل المساعد الذكي.
          <?php endif; ?>
        </span>
      </div>
    <?php endif; ?>

    <div class="chat-messages" id="chatMessages">
      <div class="chat-welcome" id="chatWelcome">
        <div class="chat-welcome-icon"><i class="fa-solid <?= $isCode ? 'fa-code' : 'fa-wand-magic-sparkles' ?>"></i></div>
        <?php if ($isCode): ?>
          <h3>مساعد الكود</h3>
          <p>اطلب حل مشكلة أو ميزة جديدة في مستودع <code><?= e(($context['github_owner'] ?? '') . '/' . ($context['github_repo'] ?? '')) ?></code>. سيستكشف المساعد الملفات ذات الصلة تلقائياً، ثم يرفع أي تعديل تطلبه كـ Commit مباشر على المستودع بنفسه — مثل مساحة عمل حقيقية، بلا نسخ ولصق يدوي.</p>
        <?php else: ?>
          <h3>المساعد الذكي للمشروع</h3>
          <p>اسأل عن الكود، أو اطلب مراجعة أو تعديلاً. سيتم حقن هيكل قاعدة البيانات وقواعد المشروع ومقتطفات Skill المحفوظة تلقائياً ضمن سياق كل طلب.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="chat-attachments" id="chatAttachments"></div>
    <div class="provider-info-card" id="providerInfoCard" style="display:none"></div>

    <form class="chat-input-bar" id="chatForm">
      <div class="chat-toolbar">
        <?php if (!empty($providers)): ?>
        <div class="provider-select-wrap">
          <i class="fa-solid fa-microchip"></i>
          <select id="providerSelect" title="مزوّد الذكاء الاصطناعي المستخدَم لهذه الرسالة">
            <?php foreach ($providers as $p): ?>
              <option value="<?= (int) $p['id'] ?>" <?= $p['is_default'] ? 'selected' : '' ?>><?= e($p['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>
        <?php if (!$isCode): ?>
        <label class="btn-chip" for="chatImageInput"><i class="fa-regular fa-image"></i> إرفاق صورة</label>
        <input type="file" id="chatImageInput" accept="image/png,image/jpeg,image/webp,image/gif" hidden>
        <?php endif; ?>
      </div>
      <div class="chat-input-row">
        <textarea id="chatInput" class="chat-textarea" placeholder="<?= $isCode ? 'اكتب المشكلة أو الميزة المطلوبة... مثال: أصلح خطأ 500 عند فتح صفحة تسجيل الدخول' : 'اكتب طلبك هنا... مثال: راجع دالة تسجيل الدخول وتحقق من الحماية من SQL Injection' ?>" rows="1"></textarea>
        <button type="submit" class="btn btn-primary btn-send" id="btnSendChat" title="إرسال"><i class="fa-solid fa-paper-plane"></i></button>
      </div>
    </form>
  </section>
</div>

<?php if (!$isCode): ?>
<div class="modal-backdrop" id="modalCommit">
  <div class="modal">
    <div class="modal-header">
      <h3><i class="fa-solid fa-code-commit"></i> رفع تعديل إلى GitHub</h3>
      <button type="button" class="btn-icon-only" data-action="close-modal"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body stack-form">
      <div class="form-group">
        <label class="form-label">مسار الملف في المستودع</label>
        <input class="form-control" type="text" id="commitPath" placeholder="src/example.php">
      </div>
      <div class="form-group">
        <label class="form-label">محتوى الملف</label>
        <textarea class="form-control code-textarea" id="commitContent" rows="12"></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">رسالة الـ Commit</label>
        <input class="form-control" type="text" id="commitMessage" value="تحديث عبر المساعد الذكي">
      </div>
      <div class="alert alert-info" id="commitStatus" style="display:none"></div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-secondary" data-action="close-modal">إلغاء</button>
      <button type="button" class="btn btn-success" id="btnConfirmCommit"><i class="fa-solid fa-code-commit"></i> تنفيذ الـ Commit</button>
    </div>
  </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php endif; ?>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
