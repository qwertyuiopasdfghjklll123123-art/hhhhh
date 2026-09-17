<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$user = require_login();

$projectId = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT p.*, u.name AS owner_name FROM projects p LEFT JOIN users u ON u.id = p.created_by WHERE p.id = ?');
$stmt->execute([$projectId]);
$project = $stmt->fetch();
if (!$project) {
    flash('error', 'المشروع غير موجود.');
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
        redirect('project_context.php?id=' . $projectId . '&tab=settings');
    }

    if ($formAction === 'save_context') {
        $sqlSchema         = (string) ($_POST['sql_schema'] ?? '');
        $systemRules       = (string) ($_POST['system_rules'] ?? '');
        $githubOwner       = trim((string) ($_POST['github_owner'] ?? ''));
        $githubRepo        = trim((string) ($_POST['github_repo'] ?? ''));
        $githubBranch      = trim((string) ($_POST['github_branch'] ?? '')) ?: 'main';
        $nvidiaTextModel   = trim((string) ($_POST['nvidia_text_model'] ?? '')) ?: 'meta/llama-3.1-70b-instruct';
        $nvidiaVisionModel = trim((string) ($_POST['nvidia_vision_model'] ?? '')) ?: 'meta/llama-3.2-90b-vision-instruct';

        $githubTokenInput = (string) ($_POST['github_token'] ?? '');
        $nvidiaKeyInput   = (string) ($_POST['nvidia_api_key'] ?? '');
        $clearGithubToken = isset($_POST['clear_github_token']);
        $clearNvidiaKey   = isset($_POST['clear_nvidia_api_key']);

        $githubTokenEncrypted = $context['github_token'];
        if ($clearGithubToken) {
            $githubTokenEncrypted = null;
        } elseif (trim($githubTokenInput) !== '') {
            $githubTokenEncrypted = Crypto::encrypt(trim($githubTokenInput));
        }

        $nvidiaKeyEncrypted = $context['nvidia_api_key'];
        if ($clearNvidiaKey) {
            $nvidiaKeyEncrypted = null;
        } elseif (trim($nvidiaKeyInput) !== '') {
            $nvidiaKeyEncrypted = Crypto::encrypt(trim($nvidiaKeyInput));
        }

        db()->prepare(
            'UPDATE project_context SET sql_schema = ?, system_rules = ?, github_owner = ?, github_repo = ?, github_branch = ?,
             github_token = ?, nvidia_api_key = ?, nvidia_text_model = ?, nvidia_vision_model = ? WHERE project_id = ?'
        )->execute([
            $sqlSchema !== '' ? $sqlSchema : null,
            $systemRules !== '' ? $systemRules : null,
            $githubOwner !== '' ? $githubOwner : null,
            $githubRepo !== '' ? $githubRepo : null,
            $githubBranch,
            $githubTokenEncrypted,
            $nvidiaKeyEncrypted,
            $nvidiaTextModel,
            $nvidiaVisionModel,
            $projectId,
        ]);

        log_activity((int) $user['id'], 'context_save', "تحديث سياق المشروع: {$project['name']}");
        flash('success', 'تم حفظ إعدادات المشروع بنجاح.');
        redirect('project_context.php?id=' . $projectId . '&tab=settings');
    }

    flash('error', 'إجراء غير معروف.');
    redirect('project_context.php?id=' . $projectId);
}

$ctxStmt->execute([$projectId]);
$context = $ctxStmt->fetch();

$activeTab = (($_GET['tab'] ?? 'chat') === 'settings') ? 'settings' : 'chat';

$convStmt = db()->prepare('SELECT id, title, updated_at FROM ai_conversations WHERE project_id = ? AND user_id = ? ORDER BY updated_at DESC');
$convStmt->execute([$projectId, $user['id']]);
$conversationList = $convStmt->fetchAll();

$pageTitle     = $project['name'];
$activeNav     = 'projects';
$topbarActions = '<a href="projects.php" class="btn btn-secondary"><i class="fa-solid fa-arrow-right"></i> رجوع للمشاريع</a>';
require __DIR__ . '/includes/layout_start.php';
?>

<div class="project-tabs">
  <a href="project_context.php?id=<?= $projectId ?>&tab=chat" class="tab-btn <?= $activeTab === 'chat' ? 'active' : '' ?>">
    <i class="fa-solid fa-wand-magic-sparkles"></i> المساعد الذكي
  </a>
  <a href="project_context.php?id=<?= $projectId ?>&tab=settings" class="tab-btn <?= $activeTab === 'settings' ? 'active' : '' ?>">
    <i class="fa-solid fa-sliders"></i> السياق والإعدادات
  </a>
</div>

<?php if ($activeTab === 'settings'): ?>

<div class="settings-stack">
  <section class="card">
    <div class="card-header"><h2><i class="fa-solid fa-circle-info"></i> بيانات المشروع</h2></div>
    <div class="card-body">
      <form method="post" action="project_context.php?id=<?= $projectId ?>" class="stack-form">
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

  <form method="post" action="project_context.php?id=<?= $projectId ?>" class="stack-form">
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
      <div class="card-header"><h2><i class="fa-brands fa-github"></i> بيانات الوصول السحابية (GitHub)</h2></div>
      <div class="card-body">
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
        <div class="form-group">
          <label class="form-label">Personal Access Token</label>
          <div class="input-with-icon">
            <input class="form-control" type="password" name="github_token" id="githubTokenInput"
              placeholder="<?= $context['github_token'] ? '•••••••• محفوظ - اترك الحقل فارغاً للإبقاء عليه' : 'ghp_xxxxxxxxxxxxxxxxxxxx' ?>"
              autocomplete="new-password">
            <button type="button" class="input-icon-btn" data-action="toggle-visibility" data-target="githubTokenInput" aria-label="إظهار"><i class="fa-solid fa-eye"></i></button>
          </div>
          <?php if ($context['github_token']): ?>
          <label class="checkbox-label"><input type="checkbox" name="clear_github_token" value="1"> إزالة الـ Token المحفوظ</label>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="card">
      <div class="card-header"><h2><i class="fa-solid fa-microchip"></i> مفاتيح NVIDIA NIM API</h2></div>
      <div class="card-body">
        <div class="form-group">
          <label class="form-label">NVIDIA NIM API Key</label>
          <div class="input-with-icon">
            <input class="form-control" type="password" name="nvidia_api_key" id="nvidiaKeyInput"
              placeholder="<?= $context['nvidia_api_key'] ? '•••••••• محفوظ - اترك الحقل فارغاً للإبقاء عليه' : 'nvapi-xxxxxxxxxxxxxxxxxxxx' ?>"
              autocomplete="new-password">
            <button type="button" class="input-icon-btn" data-action="toggle-visibility" data-target="nvidiaKeyInput" aria-label="إظهار"><i class="fa-solid fa-eye"></i></button>
          </div>
          <?php if ($context['nvidia_api_key']): ?>
          <label class="checkbox-label"><input type="checkbox" name="clear_nvidia_api_key" value="1"> إزالة المفتاح المحفوظ</label>
          <?php endif; ?>
          <p class="form-hint">يُستخدم مع نقطة الاتصال: <code>https://integrate.api.nvidia.com/v1/chat/completions</code> (متوافقة مع بنية OpenAI API). المفتاح يُشفَّر قبل التخزين ولا يظهر لأي مستخدم بعد حفظه.</p>
        </div>
        <div class="form-grid-2">
          <div class="form-group">
            <label class="form-label">نموذج النصوص (Text Model)</label>
            <input class="form-control" type="text" name="nvidia_text_model" list="textModels" value="<?= e($context['nvidia_text_model']) ?>">
            <datalist id="textModels">
              <option value="meta/llama-3.1-70b-instruct">
              <option value="meta/llama-3.1-405b-instruct">
              <option value="nvidia/llama-3.1-nemotron-70b-instruct">
              <option value="mistralai/mixtral-8x22b-instruct-v0.1">
              <option value="qwen/qwen2.5-coder-32b-instruct">
            </datalist>
          </div>
          <div class="form-group">
            <label class="form-label">نموذج الرؤية (Vision Model)</label>
            <input class="form-control" type="text" name="nvidia_vision_model" list="visionModels" value="<?= e($context['nvidia_vision_model']) ?>">
            <datalist id="visionModels">
              <option value="meta/llama-3.2-90b-vision-instruct">
              <option value="meta/llama-3.2-11b-vision-instruct">
              <option value="microsoft/phi-3.5-vision-instruct">
            </datalist>
          </div>
        </div>
      </div>
    </section>

    <button type="submit" class="btn btn-primary btn-lg"><i class="fa-solid fa-floppy-disk"></i> حفظ إعدادات السياق والتكامل</button>
  </form>
</div>

<?php else: /* ===================== تبويب المساعد الذكي ===================== */ ?>

<div class="chat-layout" id="chatLayout"
     data-project-id="<?= $projectId ?>"
     data-has-github="<?= ($context['github_token'] && $context['github_owner'] && $context['github_repo']) ? '1' : '0' ?>"
     data-has-nvidia="<?= $context['nvidia_api_key'] ? '1' : '0' ?>">
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

  <section class="chat-pane">
    <?php if (!$context['nvidia_api_key']): ?>
      <div class="alert alert-warning chat-config-warning">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <span>لم يتم ضبط مفتاح NVIDIA NIM API لهذا المشروع بعد. <a href="project_context.php?id=<?= $projectId ?>&tab=settings">أضفه من هنا</a> لتفعيل المساعد الذكي.</span>
      </div>
    <?php endif; ?>

    <div class="chat-messages" id="chatMessages">
      <div class="chat-welcome" id="chatWelcome">
        <div class="chat-welcome-icon"><i class="fa-solid fa-wand-magic-sparkles"></i></div>
        <h3>المساعد الذكي للمشروع</h3>
        <p>اسأل عن الكود، اطلب مراجعة أو تعديلاً، أو أرفق ملفاً من GitHub ليُحلَّل. سيتم حقن هيكل قاعدة البيانات وقواعد المشروع المحفوظة تلقائياً ضمن سياق كل طلب.</p>
      </div>
    </div>

    <div class="chat-attachments" id="chatAttachments"></div>

    <form class="chat-input-bar" id="chatForm">
      <div class="chat-toolbar">
        <button type="button" class="btn-chip" id="btnAttachGithub" <?= $context['github_token'] ? '' : 'disabled title="اضبط بيانات GitHub من تبويب الإعدادات أولاً"' ?>>
          <i class="fa-brands fa-github"></i> إرفاق ملف من GitHub
        </button>
        <label class="btn-chip" for="chatImageInput"><i class="fa-regular fa-image"></i> إرفاق صورة</label>
        <input type="file" id="chatImageInput" accept="image/png,image/jpeg,image/webp,image/gif" hidden>
      </div>
      <div class="chat-input-row">
        <textarea id="chatInput" class="chat-textarea" placeholder="اكتب طلبك هنا... مثال: راجع دالة تسجيل الدخول وتحقق من الحماية من SQL Injection" rows="1"></textarea>
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

<?php require __DIR__ . '/includes/layout_end.php'; ?>
