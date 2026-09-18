<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/settings.php';

$user = require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    verify_csrf();
    $formAction = (string) ($_POST['form_action'] ?? '');

    if ($formAction === 'save_github_oauth') {
        $clientId        = trim((string) ($_POST['github_oauth_client_id'] ?? ''));
        $clientSecretNew = trim((string) ($_POST['github_oauth_client_secret'] ?? ''));
        $clearSecret     = isset($_POST['clear_github_oauth_client_secret']);

        set_app_setting('github_oauth_client_id', $clientId !== '' ? $clientId : null);

        if ($clearSecret) {
            set_encrypted_setting('github_oauth_client_secret', null);
        } elseif ($clientSecretNew !== '') {
            set_encrypted_setting('github_oauth_client_secret', $clientSecretNew);
        }

        log_activity((int) $user['id'], 'settings_update', 'تحديث إعدادات GitHub OAuth App');
        flash('success', 'تم حفظ الإعدادات بنجاح.');
        redirect('admin_settings.php');
    }

    flash('error', 'إجراء غير معروف.');
    redirect('admin_settings.php');
}

$githubClientId     = get_app_setting('github_oauth_client_id') ?? '';
$githubClientSecret = get_encrypted_setting('github_oauth_client_secret');
$callbackUrl         = rtrim(app_config()['app']['url'] ?? '', '/') . '/github_oauth_callback.php';

$pageTitle = 'إعدادات النظام';
$activeNav = 'admin_settings';
require __DIR__ . '/includes/layout_start.php';
?>

<div class="settings-stack">
  <section class="card">
    <div class="card-header"><h2><i class="fa-brands fa-github"></i> ربط GitHub (OAuth App)</h2></div>
    <div class="card-body">
      <p class="form-hint" style="margin:0 0 14px">
        لتفعيل زر "ربط GitHub" لجميع المستخدمين، سجّل تطبيق OAuth من حسابك على GitHub، ثم أدخل بياناته هنا.
        هذا إعداد واحد على مستوى النظام كله (وليس لكل مستخدم أو مشروع على حدة).
      </p>

      <ol class="oauth-steps">
        <li>افتح <code>github.com/settings/developers</code> ← <strong>OAuth Apps</strong> ← <strong>New OAuth App</strong>.</li>
        <li>Homepage URL: <code><?= e(rtrim(app_config()['app']['url'] ?? '', '/')) ?></code></li>
        <li>Authorization callback URL (انسخه بالضبط):
          <div class="copy-box">
            <code id="callbackUrlText"><?= e($callbackUrl) ?></code>
            <button type="button" class="btn-icon" data-action="copy-text" data-copy-target="callbackUrlText" title="نسخ"><i class="fa-regular fa-copy"></i></button>
          </div>
        </li>
        <li>بعد الإنشاء، انسخ <strong>Client ID</strong>، وأنشئ <strong>Client Secret</strong> جديداً وانسخه (يظهر مرة واحدة فقط) وألصقهما أدناه.</li>
      </ol>

      <form method="post" action="admin_settings.php" class="stack-form">
        <?= csrf_field() ?>
        <input type="hidden" name="form_action" value="save_github_oauth">
        <div class="form-group">
          <label class="form-label">Client ID</label>
          <input class="form-control" type="text" name="github_oauth_client_id" value="<?= e($githubClientId) ?>" placeholder="Iv1.xxxxxxxxxxxxxxxx">
        </div>
        <div class="form-group">
          <label class="form-label">Client Secret</label>
          <div class="input-with-icon">
            <input class="form-control" type="password" name="github_oauth_client_secret" id="githubClientSecretInput"
              placeholder="<?= $githubClientSecret ? '•••••••• محفوظ - اترك الحقل فارغاً للإبقاء عليه' : 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx' ?>"
              autocomplete="new-password">
            <button type="button" class="input-icon-btn" data-action="toggle-visibility" data-target="githubClientSecretInput" aria-label="إظهار"><i class="fa-solid fa-eye"></i></button>
          </div>
          <?php if ($githubClientSecret): ?>
          <label class="checkbox-label"><input type="checkbox" name="clear_github_oauth_client_secret" value="1"> إزالة القيمة المحفوظة (يُعطِّل الربط)</label>
          <?php endif; ?>
        </div>
        <div class="form-group">
          <span class="badge <?= ($githubClientId && $githubClientSecret) ? 'badge-active' : 'badge-disabled' ?>">
            <?= ($githubClientId && $githubClientSecret) ? 'مفعَّل — زر الربط يعمل' : 'غير مكتمل — زر الربط لن يعمل بعد' ?>
          </span>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> حفظ</button>
      </form>
    </div>
  </section>
</div>

<?php require __DIR__ . '/includes/layout_end.php'; ?>
