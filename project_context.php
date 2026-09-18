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
        $sqlSchema     = (string) ($_POST['sql_schema'] ?? '');
        $systemRules   = (string) ($_POST['system_rules'] ?? '');
        $skillFilename = trim((string) ($_POST['skill_filename'] ?? ''));
        $skillContent  = (string) ($_POST['skill_content'] ?? '');
        $githubOwner   = trim((string) ($_POST['github_owner'] ?? ''));
        $githubRepo    = trim((string) ($_POST['github_repo'] ?? ''));
        $githubBranch  = trim((string) ($_POST['github_branch'] ?? '')) ?: 'main';

        if (mb_strlen($skillContent) > 100000) {
            flash('error', 'محتوى Skill طويل جداً (الحد الأقصى 100000 حرف).');
            redirect(project_url($project, 'settings'));
        }

        db()->prepare(
            'UPDATE project_context SET sql_schema = ?, system_rules = ?, skill_filename = ?, skill_content = ?,
             github_owner = ?, github_repo = ?, github_branch = ? WHERE project_id = ?'
        )->execute([
            $sqlSchema !== '' ? $sqlSchema : null,
            $systemRules !== '' ? $systemRules : null,
            $skillContent !== '' ? ($skillFilename !== '' ? $skillFilename : null) : null,
            $skillContent !== '' ? $skillContent : null,
            $githubOwner !== '' ? $githubOwner : null,
            $githubRepo !== '' ? $githubRepo : null,
            $githubBranch,
            $projectId,
        ]);

        log_activity((int) $user['id'], 'context_save', "تحديث سياق المشروع: {$project['name']}");
        flash('success', 'تم حفظ إعدادات المشروع بنجاح.');
        redirect(project_url($project, 'settings'));
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

$validTabs = ['chat', 'code', 'settings'];
$requestedTab = (string) ($_GET['tab'] ?? 'chat');
$activeTab = in_array($requestedTab, $validTabs, true) ? $requestedTab : 'chat';
$isCode = $activeTab === 'code';

$convStmt = db()->prepare('SELECT id, title, updated_at FROM ai_conversations WHERE project_id = ? AND user_id = ? AND mode = ? ORDER BY updated_at DESC');
$convStmt->execute([$projectId, $user['id'], $isCode ? 'code' : 'chat']);
$conversationList = $convStmt->fetchAll();

$pageTitle     = $project['name'];
$activeNav     = 'projects';
$topbarActions = '<a href="projects.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-right"></i> رجوع للمشاريع</a>';
require __DIR__ . '/includes/layout_start.php';
?>

<div class="project-tabs">
  <a href="<?= e(project_url($project, 'chat')) ?>" class="tab-btn <?= $activeTab === 'chat' ? 'active' : '' ?>">
    <i class="fa-solid fa-comments"></i> الدردشة العادية
  </a>
  <a href="<?= e(project_url($project, 'code')) ?>" class="tab-btn <?= $activeTab === 'code' ? 'active' : '' ?>">
    <i class="fa-solid fa-code"></i> الكود
  </a>
  <a href="<?= e(project_url($project, 'settings')) ?>" class="tab-btn <?= $activeTab === 'settings' ? 'active' : '' ?>">
    <i class="fa-solid fa-sliders"></i> السياق والإعدادات
  </a>
</div>

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
      <div class="card-header"><h2><i class="fa-solid fa-database"></i> هيكل قاعدة البيانات (SQL Schema)</h2></div>
      <div class="card-body">
        <p class="form-hint">يُحقن هذا المحتوى تلقائياً ضمن موجّه النظام (System Prompt) عند أي طلب مراجعة أو تعديل كود من المساعد الذكي.</p>
        <textarea class="form-control code-textarea" name="sql_schema" rows="10" placeholder="CREATE TABLE users (...);"><?= e($context['sql_schema'] ?? '') ?></textarea>
      </div>
    </section>

    <section class="card">
      <div class="card-header"><h2><i class="fa-solid fa-list-check"></i> قواعد المشروع البرمجية (System Rules)</h2></div>
      <div class="card-body">
        <p class="form-hint">توجيهات ثابتة يلتزم بها المساعد الذكي عند كل طلب (أسلوب الكود، المكتبات المسموحة، الاتفاقيات...).</p>
        <textarea class="form-control code-textarea" name="system_rules" rows="8" placeholder="مثال: التزم بمعيار PSR-12، لا تستخدم أي مكتبات خارجية غير معتمدة، أسماء الجداول بصيغة snake_case ..."><?= e($context['system_rules'] ?? '') ?></textarea>
      </div>
    </section>

    <section class="card">
      <div class="card-header">
        <h2><i class="fa-solid fa-puzzle-piece"></i> Skill</h2>
        <label class="btn btn-secondary btn-sm" for="skillFileInput"><i class="fa-solid fa-paperclip"></i> إرفاق ملف نصي</label>
      </div>
      <div class="card-body">
        <p class="form-hint">سياق إضافي دائم يُحقن تلقائياً ضمن موجّه النظام في كل رسالة (دردشة عادية أو كود) لهذا المشروع — أرفق ملفاً نصياً (يُقرأ محتواه مباشرة في متصفحك، دون رفعه كملف منفصل) أو اكتب النص مباشرة.</p>
        <input type="file" id="skillFileInput" accept=".txt,.md,.markdown,.json,.csv,.log,.yml,.yaml,text/plain" hidden>
        <input type="hidden" name="skill_filename" id="skillFilename" value="<?= e($context['skill_filename'] ?? '') ?>">
        <div class="attachment-chip" id="skillFileChip" style="<?= $context['skill_filename'] ? '' : 'display:none' ?>">
          <i class="fa-solid fa-file-lines"></i>
          <span id="skillFileChipName"><?= e($context['skill_filename'] ?? '') ?></span>
          <button type="button" id="skillFileRemove"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <textarea class="form-control code-textarea" name="skill_content" id="skillContent" rows="8" placeholder="مثال: دليل أسلوب الفريق، ملخص واجهة API خارجية يعتمدها المشروع، أو أي معرفة ثابتة تريد أن يعرفها المساعد دائماً..."><?= e($context['skill_content'] ?? '') ?></textarea>
      </div>
    </section>

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
        <div class="form-grid-2">
          <div class="form-group">
            <label class="form-label">Owner / Organization</label>
            <input class="form-control" type="text" name="github_owner" value="<?= e($context['github_owner'] ?? '') ?>" placeholder="octocat">
          </div>
          <div class="form-group">
            <label class="form-label">Repository</label>
            <input class="form-control" type="text" name="github_repo" value="<?= e($context['github_repo'] ?? '') ?>" placeholder="my-repo">
          </div>
        </div>
        <div class="form-group">
          <label class="form-label">Branch</label>
          <input class="form-control" type="text" name="github_branch" value="<?= e($context['github_branch'] ?? 'main') ?>" placeholder="main">
        </div>
      </div>
    </section>

    <button type="submit" class="btn btn-primary btn-lg"><i class="fa-solid fa-floppy-disk"></i> حفظ إعدادات السياق والتكامل</button>
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

<div class="chat-layout" id="chatLayout"
     data-project-id="<?= $projectId ?>"
     data-mode="<?= $isCode ? 'code' : 'chat' ?>"
     data-has-github="<?= $hasGithubToken ? '1' : '0' ?>"
     data-has-provider="<?= empty($providers) ? '0' : '1' ?>">
  <aside class="chat-rail">
    <button type="button" class="btn btn-primary btn-block" id="btnNewChat"><i class="fa-solid fa-plus"></i> محادثة جديدة</button>
    <div class="chat-rail-list" id="chatRailList">
      <?php if (empty($conversationList)): ?>
        <p class="chat-rail-empty"><?= $isCode ? 'لا توجد محادثات كود بعد.' : 'لا توجد محادثات بعد.' ?></p>
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
          <p>اطلب حل مشكلة أو ميزة جديدة في مستودع <code><?= e(($context['github_owner'] ?? '') . '/' . ($context['github_repo'] ?? '')) ?></code>. سيستكشف المساعد الملفات ذات الصلة تلقائياً قبل أن يقترح حلاً أو كوداً جاهزاً للرفع.</p>
        <?php else: ?>
          <h3>المساعد الذكي للمشروع</h3>
          <p>اسأل عن الكود، اطلب مراجعة أو تعديلاً، أو أرفق ملفاً من GitHub ليُحلَّل. سيتم حقن هيكل قاعدة البيانات وقواعد المشروع المحفوظة تلقائياً ضمن سياق كل طلب.</p>
        <?php endif; ?>
      </div>
    </div>

    <div class="chat-attachments" id="chatAttachments"></div>

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
        <button type="button" class="btn-chip" id="btnAttachGithub" <?= $hasGithubToken ? '' : 'disabled title="اربط حساب GitHub من تبويب الإعدادات أولاً"' ?>>
          <i class="fa-brands fa-github"></i> إرفاق ملف من GitHub
        </button>
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

<div class="modal-backdrop" id="modalGithubBrowse">
  <div class="modal modal-lg">
    <div class="modal-header">
      <h3><i class="fa-brands fa-github"></i> اختيار ملف من المستودع</h3>
      <button type="button" class="btn-icon-only" data-action="close-modal"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div class="github-browser">
        <div class="github-path-bar" id="githubPathBar"></div>
        <div class="github-file-list" id="githubFileList">
          <div class="empty-state"><i class="fa-brands fa-github"></i><p>يتم التحميل...</p></div>
        </div>
      </div>
    </div>
  </div>
</div>

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

<?php require __DIR__ . '/includes/layout_end.php'; ?>
