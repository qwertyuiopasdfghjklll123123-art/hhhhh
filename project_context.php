<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/services/AiClient.php';

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
        $sqlSchema    = (string) ($_POST['sql_schema'] ?? '');
        $systemRules  = (string) ($_POST['system_rules'] ?? '');
        $githubOwner  = trim((string) ($_POST['github_owner'] ?? ''));
        $githubRepo   = trim((string) ($_POST['github_repo'] ?? ''));
        $githubBranch = trim((string) ($_POST['github_branch'] ?? '')) ?: 'main';

        $githubTokenInput = (string) ($_POST['github_token'] ?? '');
        $clearGithubToken = isset($_POST['clear_github_token']);

        $githubTokenEncrypted = $context['github_token'];
        if ($clearGithubToken) {
            $githubTokenEncrypted = null;
        } elseif (trim($githubTokenInput) !== '') {
            $githubTokenEncrypted = Crypto::encrypt(trim($githubTokenInput));
        }

        db()->prepare(
            'UPDATE project_context SET sql_schema = ?, system_rules = ?, github_owner = ?, github_repo = ?, github_branch = ?,
             github_token = ? WHERE project_id = ?'
        )->execute([
            $sqlSchema !== '' ? $sqlSchema : null,
            $systemRules !== '' ? $systemRules : null,
            $githubOwner !== '' ? $githubOwner : null,
            $githubRepo !== '' ? $githubRepo : null,
            $githubBranch,
            $githubTokenEncrypted,
            $projectId,
        ]);

        log_activity((int) $user['id'], 'context_save', "تحديث سياق المشروع: {$project['name']}");
        flash('success', 'تم حفظ إعدادات المشروع بنجاح.');
        redirect('project_context.php?id=' . $projectId . '&tab=settings');
    }

    if ($formAction === 'add_provider' || $formAction === 'update_provider') {
        $label       = trim((string) ($_POST['label'] ?? ''));
        $baseUrl     = trim((string) ($_POST['base_url'] ?? '')) ?: AiClient::DEFAULT_ENDPOINT;
        $textModel   = trim((string) ($_POST['text_model'] ?? '')) ?: 'meta/llama-3.1-70b-instruct';
        $visionModel = trim((string) ($_POST['vision_model'] ?? ''));
        $apiKeyInput = trim((string) ($_POST['api_key'] ?? ''));
        $makeDefault = isset($_POST['is_default']);

        if ($label === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            flash('error', 'التسمية ونقطة الاتصال (رابط صالح) مطلوبتان.');
            redirect('project_context.php?id=' . $projectId . '&tab=settings');
        }

        if ($formAction === 'add_provider') {
            if ($apiKeyInput === '') {
                flash('error', 'مفتاح الـ API مطلوب عند إضافة مزوّد جديد.');
                redirect('project_context.php?id=' . $projectId . '&tab=settings');
            }
            if ($makeDefault) {
                db()->prepare('UPDATE ai_providers SET is_default = 0 WHERE project_id = ?')->execute([$projectId]);
            }
            db()->prepare(
                'INSERT INTO ai_providers (project_id, label, base_url, api_key, text_model, vision_model, is_default) VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $projectId, $label, $baseUrl, Crypto::encrypt($apiKeyInput), $textModel,
                $visionModel !== '' ? $visionModel : null, $makeDefault ? 1 : 0,
            ]);
            // أول مزوّد يُضاف للمشروع يُصبح افتراضياً تلقائياً، حتى لو لم يُحدَّد صراحة
            $countStmt = db()->prepare('SELECT COUNT(*) FROM ai_providers WHERE project_id = ?');
            $countStmt->execute([$projectId]);
            if ((int) $countStmt->fetchColumn() === 1) {
                db()->prepare('UPDATE ai_providers SET is_default = 1 WHERE project_id = ?')->execute([$projectId]);
            }
            log_activity((int) $user['id'], 'provider_add', "إضافة مزوّد ذكاء اصطناعي: {$label}");
            flash('success', 'تمت إضافة المزوّد بنجاح.');
        } else {
            $providerId = (int) ($_POST['provider_id'] ?? 0);
            $ownStmt = db()->prepare('SELECT api_key FROM ai_providers WHERE id = ? AND project_id = ?');
            $ownStmt->execute([$providerId, $projectId]);
            $existing = $ownStmt->fetch();
            if (!$existing) {
                flash('error', 'المزوّد غير موجود.');
                redirect('project_context.php?id=' . $projectId . '&tab=settings');
            }
            $apiKeyEncrypted = $apiKeyInput !== '' ? Crypto::encrypt($apiKeyInput) : $existing['api_key'];

            if ($makeDefault) {
                db()->prepare('UPDATE ai_providers SET is_default = 0 WHERE project_id = ?')->execute([$projectId]);
            }
            db()->prepare(
                'UPDATE ai_providers SET label = ?, base_url = ?, api_key = ?, text_model = ?, vision_model = ?, is_default = ? WHERE id = ? AND project_id = ?'
            )->execute([
                $label, $baseUrl, $apiKeyEncrypted, $textModel, $visionModel !== '' ? $visionModel : null,
                $makeDefault ? 1 : 0, $providerId, $projectId,
            ]);
            log_activity((int) $user['id'], 'provider_update', "تعديل مزوّد ذكاء اصطناعي: {$label}");
            flash('success', 'تم حفظ تعديلات المزوّد.');
        }
        redirect('project_context.php?id=' . $projectId . '&tab=settings');
    }

    if ($formAction === 'set_default_provider') {
        $providerId = (int) ($_POST['provider_id'] ?? 0);
        $checkStmt = db()->prepare('SELECT id FROM ai_providers WHERE id = ? AND project_id = ?');
        $checkStmt->execute([$providerId, $projectId]);
        if ($checkStmt->fetch()) {
            db()->prepare('UPDATE ai_providers SET is_default = 0 WHERE project_id = ?')->execute([$projectId]);
            db()->prepare('UPDATE ai_providers SET is_default = 1 WHERE id = ?')->execute([$providerId]);
            flash('success', 'تم تعيين المزوّد الافتراضي.');
        } else {
            flash('error', 'المزوّد غير موجود.');
        }
        redirect('project_context.php?id=' . $projectId . '&tab=settings');
    }

    if ($formAction === 'delete_provider') {
        $providerId = (int) ($_POST['provider_id'] ?? 0);
        $checkStmt = db()->prepare('SELECT id, label, is_default FROM ai_providers WHERE id = ? AND project_id = ?');
        $checkStmt->execute([$providerId, $projectId]);
        $target = $checkStmt->fetch();
        if ($target) {
            db()->prepare('DELETE FROM ai_providers WHERE id = ?')->execute([$providerId]);
            if ((int) $target['is_default'] === 1) {
                // عيّن أقدم مزوّد متبقٍّ كافتراضي تلقائياً حتى لا يبقى المشروع بلا مزوّد افتراضي
                db()->prepare('UPDATE ai_providers SET is_default = 1 WHERE project_id = ? ORDER BY created_at ASC LIMIT 1')
                    ->execute([$projectId]);
            }
            log_activity((int) $user['id'], 'provider_delete', "حذف مزوّد ذكاء اصطناعي: {$target['label']}");
            flash('success', 'تم حذف المزوّد.');
        } else {
            flash('error', 'المزوّد غير موجود.');
        }
        redirect('project_context.php?id=' . $projectId . '&tab=settings');
    }

    flash('error', 'إجراء غير معروف.');
    redirect('project_context.php?id=' . $projectId);
}

$ctxStmt->execute([$projectId]);
$context = $ctxStmt->fetch();

$provStmt = db()->prepare('SELECT * FROM ai_providers WHERE project_id = ? ORDER BY is_default DESC, created_at ASC');
$provStmt->execute([$projectId]);
$providers = $provStmt->fetchAll();

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

    <button type="submit" class="btn btn-primary btn-lg"><i class="fa-solid fa-floppy-disk"></i> حفظ إعدادات السياق والتكامل</button>
  </form>

  <section class="card">
    <div class="card-header">
      <h2><i class="fa-solid fa-microchip"></i> مزوّدو الذكاء الاصطناعي</h2>
      <button type="button" class="btn btn-secondary btn-sm" data-action="open-provider-modal"><i class="fa-solid fa-plus"></i> إضافة مزوّد</button>
    </div>
    <div class="card-body<?= empty($providers) ? '' : ' no-pad' ?>">
      <p class="form-hint" style="margin:0 0 14px">يمكنك إضافة أكثر من مفتاح NVIDIA (للتبديل بينها أو للنسخ الاحتياطي)، أو أي مزوّد آخر متوافق مع بنية OpenAI Chat Completions API (OpenAI، Groq، DeepSeek، Together AI، OpenRouter...) بتحديد نقطة الاتصال ومفتاحه الخاص. تختار من قائمة "المساعد الذكي" أي مزوّد تستخدمه لكل رسالة.</p>
      <?php if (empty($providers)): ?>
        <div class="empty-state">
          <i class="fa-solid fa-microchip"></i>
          <p>لا يوجد أي مزوّد بعد. أضف مفتاح NVIDIA NIM أو أي مزوّد آخر لتفعيل المساعد الذكي.</p>
          <button type="button" class="btn btn-primary btn-sm" data-action="open-provider-modal"><i class="fa-solid fa-plus"></i> إضافة أول مزوّد</button>
        </div>
      <?php else: ?>
        <div class="provider-list">
          <?php foreach ($providers as $p): ?>
          <div class="provider-row">
            <div class="provider-icon"><i class="fa-solid fa-microchip"></i></div>
            <div class="provider-info">
              <div class="provider-name">
                <?= e($p['label']) ?>
                <?php if ($p['is_default']): ?><span class="badge badge-active">افتراضي</span><?php endif; ?>
              </div>
              <div class="provider-meta">
                <span class="provider-url"><?= e((string) (parse_url($p['base_url'], PHP_URL_HOST) ?: $p['base_url'])) ?></span>
                <span>·</span>
                <span><?= e($p['text_model']) ?></span>
                <?php if ($p['vision_model']): ?><span>· رؤية: <?= e($p['vision_model']) ?></span><?php endif; ?>
              </div>
            </div>
            <div class="provider-actions">
              <?php if (!$p['is_default']): ?>
              <form method="post" action="project_context.php?id=<?= $projectId ?>" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="set_default_provider">
                <input type="hidden" name="provider_id" value="<?= (int) $p['id'] ?>">
                <button type="submit" class="btn-icon" title="تعيين كافتراضي"><i class="fa-regular fa-star"></i></button>
              </form>
              <?php endif; ?>
              <button type="button" class="btn-icon" title="تعديل"
                data-action="edit-provider"
                data-id="<?= (int) $p['id'] ?>"
                data-label="<?= e($p['label']) ?>"
                data-base-url="<?= e($p['base_url']) ?>"
                data-text-model="<?= e($p['text_model']) ?>"
                data-vision-model="<?= e($p['vision_model'] ?? '') ?>">
                <i class="fa-solid fa-pen"></i>
              </button>
              <form method="post" action="project_context.php?id=<?= $projectId ?>" class="inline-form" data-confirm="حذف مزوّد «<?= e($p['label']) ?>» نهائياً؟">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="delete_provider">
                <input type="hidden" name="provider_id" value="<?= (int) $p['id'] ?>">
                <button type="submit" class="btn-icon btn-icon-danger" title="حذف"><i class="fa-solid fa-trash"></i></button>
              </form>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>
</div>

<div class="modal-backdrop" id="modalProvider">
  <div class="modal">
    <div class="modal-header">
      <h3 id="providerModalTitle"><i class="fa-solid fa-microchip"></i> إضافة مزوّد ذكاء اصطناعي</h3>
      <button type="button" class="btn-icon-only" data-action="close-modal"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <form method="post" action="project_context.php?id=<?= $projectId ?>" id="providerForm">
      <?= csrf_field() ?>
      <input type="hidden" name="form_action" value="add_provider" id="providerFormAction">
      <input type="hidden" name="provider_id" id="providerId" value="">
      <div class="modal-body stack-form">
        <div class="form-group">
          <label class="form-label">التسمية</label>
          <input class="form-control" type="text" name="label" id="providerLabel" placeholder="مثال: NVIDIA - الحساب الرئيسي" required>
        </div>
        <div class="form-group">
          <label class="form-label">نقطة الاتصال (Base URL)</label>
          <input class="form-control" type="text" name="base_url" id="providerBaseUrl" list="providerPresets" value="<?= e(AiClient::DEFAULT_ENDPOINT) ?>" required>
          <datalist id="providerPresets">
            <option value="https://integrate.api.nvidia.com/v1/chat/completions" label="NVIDIA NIM">
            <option value="https://api.openai.com/v1/chat/completions" label="OpenAI">
            <option value="https://api.groq.com/openai/v1/chat/completions" label="Groq">
            <option value="https://api.deepseek.com/chat/completions" label="DeepSeek">
            <option value="https://api.together.xyz/v1/chat/completions" label="Together AI">
            <option value="https://openrouter.ai/api/v1/chat/completions" label="OpenRouter">
            <option value="https://api.mistral.ai/v1/chat/completions" label="Mistral">
          </datalist>
          <p class="form-hint">أي نقطة اتصال متوافقة مع بنية OpenAI Chat Completions API.</p>
        </div>
        <div class="form-group">
          <label class="form-label">مفتاح API</label>
          <div class="input-with-icon">
            <input class="form-control" type="password" name="api_key" id="providerApiKey" placeholder="sk-... / nvapi-..." autocomplete="new-password">
            <button type="button" class="input-icon-btn" data-action="toggle-visibility" data-target="providerApiKey" aria-label="إظهار"><i class="fa-solid fa-eye"></i></button>
          </div>
          <p class="form-hint" id="providerKeyHint">يُشفَّر قبل التخزين ولا يظهر لأي مستخدم بعد حفظه.</p>
        </div>
        <div class="form-grid-2">
          <div class="form-group">
            <label class="form-label">نموذج النصوص</label>
            <input class="form-control" type="text" name="text_model" id="providerTextModel" list="textModels" value="meta/llama-3.1-70b-instruct" required>
            <datalist id="textModels">
              <option value="meta/llama-3.1-70b-instruct">
              <option value="meta/llama-3.1-405b-instruct">
              <option value="nvidia/llama-3.1-nemotron-70b-instruct">
              <option value="openai/gpt-oss-20b" label="NVIDIA NIM · نموذج استدلال Reasoning">
              <option value="openai/gpt-oss-120b" label="NVIDIA NIM · نموذج استدلال Reasoning">
              <option value="gpt-4o">
              <option value="gpt-4o-mini">
              <option value="deepseek-chat">
              <option value="mistralai/mixtral-8x22b-instruct-v0.1">
              <option value="qwen/qwen2.5-coder-32b-instruct">
            </datalist>
            <p class="form-hint">نماذج الاستدلال (Reasoning) مثل <code>openai/gpt-oss-*</code> تعرض خطوات تفكيرها في المحادثة ضمن قسم قابل للطي قبل الإجابة النهائية.</p>
          </div>
          <div class="form-group">
            <label class="form-label">نموذج الرؤية (اختياري)</label>
            <input class="form-control" type="text" name="vision_model" id="providerVisionModel" list="visionModels" placeholder="اتركه فارغاً إن لم يتوفر">
            <datalist id="visionModels">
              <option value="meta/llama-3.2-90b-vision-instruct">
              <option value="meta/llama-3.2-11b-vision-instruct">
              <option value="gpt-4o">
              <option value="microsoft/phi-3.5-vision-instruct">
            </datalist>
          </div>
        </div>
        <label class="checkbox-label"><input type="checkbox" name="is_default" id="providerIsDefault" value="1"> تعيين كمزوّد افتراضي</label>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-action="close-modal">إلغاء</button>
        <button type="submit" class="btn btn-primary" id="providerSubmitBtn"><i class="fa-solid fa-check"></i> إضافة</button>
      </div>
    </form>
  </div>
</div>

<?php else: /* ===================== تبويب المساعد الذكي ===================== */ ?>

<div class="chat-layout" id="chatLayout"
     data-project-id="<?= $projectId ?>"
     data-has-github="<?= ($context['github_token'] && $context['github_owner'] && $context['github_repo']) ? '1' : '0' ?>"
     data-has-provider="<?= empty($providers) ? '0' : '1' ?>">
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
    <?php if (empty($providers)): ?>
      <div class="alert alert-warning chat-config-warning">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <span>لم يتم إضافة أي مزوّد ذكاء اصطناعي لهذا المشروع بعد. <a href="project_context.php?id=<?= $projectId ?>&tab=settings">أضف واحداً من هنا</a> لتفعيل المساعد الذكي.</span>
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
