<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/services/AiClient.php';

/**
 * إدارة مزوّدي الذكاء الاصطناعي — عامّة على مستوى النظام كله (الأدمن حصراً).
 * كل مزوّد يُضاف هنا تتشاركه جميع المشاريع والمحادثات (دردشة عادية وكود)،
 * بدل أن يملك كل مشروع مزوّديه الخاصين كما كان سابقاً.
 */
$user = require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $formAction = (string) ($_POST['form_action'] ?? '');

    if ($formAction === 'add_provider' || $formAction === 'update_provider') {
        $label       = trim((string) ($_POST['label'] ?? ''));
        $baseUrl     = trim((string) ($_POST['base_url'] ?? '')) ?: AiClient::DEFAULT_ENDPOINT;
        $textModel   = trim((string) ($_POST['text_model'] ?? '')) ?: 'openai/gpt-oss-20b';
        $visionModel = trim((string) ($_POST['vision_model'] ?? ''));
        $specialty   = trim((string) ($_POST['specialty'] ?? ''));
        $apiKeyInput = trim((string) ($_POST['api_key'] ?? ''));
        $makeDefault = isset($_POST['is_default']);
        $budgetInput = trim((string) ($_POST['token_budget'] ?? ''));
        $tokenBudget = $budgetInput !== '' && ctype_digit($budgetInput) ? (int) $budgetInput : null;

        if ($label === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
            flash('error', 'التسمية ونقطة الاتصال (رابط صالح) مطلوبتان.');
            redirect('ai_providers.php');
        }

        if ($formAction === 'add_provider') {
            if ($apiKeyInput === '') {
                flash('error', 'مفتاح الـ API مطلوب عند إضافة مزوّد جديد.');
                redirect('ai_providers.php');
            }
            if ($makeDefault) {
                db()->exec('UPDATE ai_providers SET is_default = 0');
            }
            db()->prepare(
                'INSERT INTO ai_providers (label, base_url, api_key, text_model, vision_model, specialty, is_default, token_budget, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute([
                $label, $baseUrl, Crypto::encrypt($apiKeyInput), $textModel,
                $visionModel !== '' ? $visionModel : null, $specialty !== '' ? $specialty : null,
                $makeDefault ? 1 : 0, $tokenBudget, $user['id'],
            ]);
            // أول مزوّد يُضاف على مستوى النظام كله يُصبح افتراضياً تلقائياً حتى لو لم يُحدَّد صراحة
            if ((int) db()->query('SELECT COUNT(*) FROM ai_providers')->fetchColumn() === 1) {
                db()->exec('UPDATE ai_providers SET is_default = 1');
            }
            log_activity((int) $user['id'], 'provider_add', "إضافة مزوّد ذكاء اصطناعي: {$label}");
            flash('success', 'تمت إضافة المزوّد بنجاح.');
        } else {
            $providerId = (int) ($_POST['provider_id'] ?? 0);
            $ownStmt = db()->prepare('SELECT api_key FROM ai_providers WHERE id = ?');
            $ownStmt->execute([$providerId]);
            $existing = $ownStmt->fetch();
            if (!$existing) {
                flash('error', 'المزوّد غير موجود.');
                redirect('ai_providers.php');
            }
            $apiKeyEncrypted = $apiKeyInput !== '' ? Crypto::encrypt($apiKeyInput) : $existing['api_key'];

            if ($makeDefault) {
                db()->exec('UPDATE ai_providers SET is_default = 0');
            }
            db()->prepare(
                'UPDATE ai_providers SET label = ?, base_url = ?, api_key = ?, text_model = ?, vision_model = ?, specialty = ?, is_default = ?, token_budget = ? WHERE id = ?'
            )->execute([
                $label, $baseUrl, $apiKeyEncrypted, $textModel, $visionModel !== '' ? $visionModel : null,
                $specialty !== '' ? $specialty : null, $makeDefault ? 1 : 0, $tokenBudget, $providerId,
            ]);
            log_activity((int) $user['id'], 'provider_update', "تعديل مزوّد ذكاء اصطناعي: {$label}");
            flash('success', 'تم حفظ تعديلات المزوّد.');
        }
        redirect('ai_providers.php');
    }

    if ($formAction === 'reset_usage') {
        $providerId = (int) ($_POST['provider_id'] ?? 0);
        db()->prepare('UPDATE ai_providers SET tokens_used = 0 WHERE id = ?')->execute([$providerId]);
        log_activity((int) $user['id'], 'provider_update', 'تصفير عدّاد التوكنات لمزوّد #' . $providerId);
        flash('success', 'تم تصفير عدّاد الاستهلاك.');
        redirect('ai_providers.php');
    }

    if ($formAction === 'set_default_provider') {
        $providerId = (int) ($_POST['provider_id'] ?? 0);
        $checkStmt = db()->prepare('SELECT id FROM ai_providers WHERE id = ?');
        $checkStmt->execute([$providerId]);
        if ($checkStmt->fetch()) {
            db()->exec('UPDATE ai_providers SET is_default = 0');
            db()->prepare('UPDATE ai_providers SET is_default = 1 WHERE id = ?')->execute([$providerId]);
            flash('success', 'تم تعيين المزوّد الافتراضي.');
        } else {
            flash('error', 'المزوّد غير موجود.');
        }
        redirect('ai_providers.php');
    }

    if ($formAction === 'delete_provider') {
        $providerId = (int) ($_POST['provider_id'] ?? 0);
        $checkStmt = db()->prepare('SELECT id, label, is_default FROM ai_providers WHERE id = ?');
        $checkStmt->execute([$providerId]);
        $target = $checkStmt->fetch();
        if ($target) {
            db()->prepare('DELETE FROM ai_providers WHERE id = ?')->execute([$providerId]);
            if ((int) $target['is_default'] === 1) {
                // عيّن أقدم مزوّد متبقٍّ كافتراضي تلقائياً حتى لا يبقى النظام بلا مزوّد افتراضي
                db()->exec('UPDATE ai_providers SET is_default = 1 ORDER BY created_at ASC LIMIT 1');
            }
            log_activity((int) $user['id'], 'provider_delete', "حذف مزوّد ذكاء اصطناعي: {$target['label']}");
            flash('success', 'تم حذف المزوّد.');
        } else {
            flash('error', 'المزوّد غير موجود.');
        }
        redirect('ai_providers.php');
    }

    flash('error', 'إجراء غير معروف.');
    redirect('ai_providers.php');
}

$providers = db()->query('SELECT * FROM ai_providers ORDER BY is_default DESC, created_at ASC')->fetchAll();

$pageTitle = 'مزوّدو الذكاء الاصطناعي';
$activeNav = 'ai_providers';
require __DIR__ . '/includes/layout_start.php';
?>

<div class="settings-stack">
  <section class="card">
    <div class="card-header">
      <h2><i class="fa-solid fa-microchip"></i> مزوّدو الذكاء الاصطناعي</h2>
      <button type="button" class="btn btn-secondary btn-sm" data-action="open-provider-modal"><i class="fa-solid fa-plus"></i> إضافة مزوّد</button>
    </div>
    <div class="card-body<?= empty($providers) ? '' : ' no-pad' ?>">
      <p class="form-hint" style="margin:0 0 14px">
        هذه القائمة مشتركة بين جميع المشاريع والمحادثات (الدردشة العادية وقسم الكود) — يديرها الأدمن حصراً.
        يمكن إضافة أكثر من مفتاح NVIDIA (للتبديل بينها أو للنسخ الاحتياطي)، أو أي مزوّد آخر متوافق مع بنية
        OpenAI Chat Completions API (OpenAI، Groq، DeepSeek، Together AI، OpenRouter...) بتحديد نقطة الاتصال
        ومفتاحه الخاص. يختار المستخدم من قائمة "المساعد الذكي" في كل مشروع أي مزوّد يستخدمه لكل رسالة.
      </p>
      <?php if (empty($providers)): ?>
        <div class="empty-state">
          <i class="fa-solid fa-microchip"></i>
          <p>لا يوجد أي مزوّد بعد. أضف مفتاح NVIDIA NIM أو أي مزوّد آخر لتفعيل المساعد الذكي في كل المشاريع.</p>
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
                <?php if (!empty($p['specialty'])): ?><span class="badge badge-specialty"><i class="fa-solid fa-star"></i> <?= e($p['specialty']) ?></span><?php endif; ?>
              </div>
              <div class="provider-meta">
                <span class="provider-url"><?= e((string) (parse_url($p['base_url'], PHP_URL_HOST) ?: $p['base_url'])) ?></span>
                <span>·</span>
                <span><?= e($p['text_model']) ?></span>
                <?php if ($p['vision_model']): ?><span>· رؤية: <?= e($p['vision_model']) ?></span><?php endif; ?>
              </div>
              <div class="provider-usage">
                <?php $usagePct = $p['token_budget'] ? min(100, (int) round($p['tokens_used'] / max(1, (int) $p['token_budget']) * 100)) : null; ?>
                <span class="provider-usage-text">
                  <i class="fa-solid fa-gauge-high"></i>
                  المستهلك: <?= number_format((int) $p['tokens_used']) ?> توكن
                  <?php if ($p['token_budget']): ?>
                    من <?= number_format((int) $p['token_budget']) ?> (المتبقي: <?= number_format(max(0, (int) $p['token_budget'] - (int) $p['tokens_used'])) ?>)
                  <?php endif; ?>
                </span>
                <?php if ($usagePct !== null): ?>
                <div class="provider-usage-bar"><div class="provider-usage-fill<?= $usagePct >= 90 ? ' is-danger' : ($usagePct >= 70 ? ' is-warning' : '') ?>" style="width:<?= $usagePct ?>%"></div></div>
                <?php endif; ?>
              </div>
            </div>
            <div class="provider-actions">
              <?php if (!$p['is_default']): ?>
              <form method="post" action="ai_providers.php" class="inline-form">
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
                data-vision-model="<?= e($p['vision_model'] ?? '') ?>"
                data-specialty="<?= e($p['specialty'] ?? '') ?>"
                data-token-budget="<?= e((string) ($p['token_budget'] ?? '')) ?>">
                <i class="fa-solid fa-pen"></i>
              </button>
              <form method="post" action="ai_providers.php" class="inline-form" data-confirm="تصفير عدّاد استهلاك «<?= e($p['label']) ?>»؟">
                <?= csrf_field() ?>
                <input type="hidden" name="form_action" value="reset_usage">
                <input type="hidden" name="provider_id" value="<?= (int) $p['id'] ?>">
                <button type="submit" class="btn-icon" title="تصفير عدّاد الاستهلاك"><i class="fa-solid fa-rotate-left"></i></button>
              </form>
              <form method="post" action="ai_providers.php" class="inline-form" data-confirm="حذف مزوّد «<?= e($p['label']) ?>» نهائياً؟ سيؤثر هذا على كل المشاريع التي تستخدمه.">
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
    <form method="post" action="ai_providers.php" id="providerForm">
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
            <input class="form-control" type="text" name="text_model" id="providerTextModel" list="textModels" value="openai/gpt-oss-20b" required>
            <datalist id="textModels">
              <option value="openai/gpt-oss-20b" label="NVIDIA NIM · نموذج استدلال Reasoning">
              <option value="openai/gpt-oss-120b" label="NVIDIA NIM · نموذج استدلال Reasoning">
              <option value="meta/llama-3.1-405b-instruct">
              <option value="nvidia/llama-3.1-nemotron-70b-instruct">
              <option value="gpt-4o">
              <option value="gpt-4o-mini">
              <option value="deepseek-chat">
              <option value="mistralai/mixtral-8x22b-instruct-v0.1">
              <option value="qwen/qwen2.5-coder-32b-instruct">
            </datalist>
            <p class="form-hint">نماذج الاستدلال (Reasoning) مثل <code>openai/gpt-oss-*</code> تعرض خطوات تفكيرها في المحادثة ضمن قسم قابل للطي قبل الإجابة النهائية. كتالوج النماذج يتغيّر باستمرار — إن ظهر خطأ "end of life" لأي نموذج، استبدله باسم نموذج آخر متوفر حالياً من نفس المزوّد.</p>
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
        <div class="form-group">
          <label class="form-label">التخصص (اختياري)</label>
          <input class="form-control" type="text" name="specialty" id="providerSpecialty" list="specialtyPresets" placeholder="مثال: متخصص بالصور">
          <datalist id="specialtyPresets">
            <option value="عام">
            <option value="متخصص بالصور">
            <option value="تفكير عميق (Reasoning)">
            <option value="أكواد">
            <option value="تلخيص">
          </datalist>
          <p class="form-hint">وصف قصير يظهر للمستخدم عند اختيار هذا المزوّد ليعرف وظيفته (مثال: "متخصص بالصور"، "تفكير عميق").</p>
        </div>
        <div class="form-group">
          <label class="form-label">حد التوكنات الشهري (اختياري)</label>
          <input class="form-control" type="text" inputmode="numeric" pattern="[0-9]*" name="token_budget" id="providerTokenBudget" placeholder="مثال: 1000000">
          <p class="form-hint">لعرض "المتبقي" فقط — اكتب الحد الذي تعرفه من لوحة تحكم المزوّد نفسه (NVIDIA/OpenAI...)، فلا يوجد API موحّد لجلبه تلقائياً. اتركه فارغاً لعرض المستهلك بلا حد.</p>
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

<?php require __DIR__ . '/includes/layout_end.php'; ?>
