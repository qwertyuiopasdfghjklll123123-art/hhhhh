<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    set_setting('nvidia_api_key', trim($_POST['api_key'] ?? ''));
    set_setting('nvidia_model', trim($_POST['model'] ?? '') ?: 'openai/gpt-oss-20b');
    $_SESSION['flash'] = ['ok' => true, 'msg' => 'تم الحفظ'];
    header('Location: ai_settings');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$apiKey = get_setting('nvidia_api_key', '');
$model = get_setting('nvidia_model', 'openai/gpt-oss-20b');
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<?php $pageTitle = 'إعدادات المساعد الذكي — الإدارة'; $assetBase = '../'; include __DIR__ . '/../includes/head.php'; ?>
</head>
<body>
<div class="wrap">
  <div class="card row">
    <h2>إعدادات المساعد الذكي</h2>
    <a class="btn secondary" href="index">رجوع</a>
  </div>
  <?php if ($flash): ?><div class="card ok"><?= htmlspecialchars($flash['msg']) ?></div><?php endif; ?>
  <div class="card">
    <form method="post">
      <label>NVIDIA API Key</label>
      <input type="text" name="api_key" value="<?= htmlspecialchars($apiKey) ?>" placeholder="nvapi-...">
      <p class="muted">أنشئ مفتاحاً من <a href="https://build.nvidia.com" target="_blank">build.nvidia.com</a></p>
      <label>الموديل</label>
      <input type="text" name="model" value="<?= htmlspecialchars($model) ?>">
      <button type="submit">حفظ</button>
    </form>
  </div>
</div>
</body>
</html>
