<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clear'])) {
    @file_put_contents(ERROR_LOG_FILE, '');
    header('Location: errors');
    exit;
}

$entries = [];
if (file_exists(ERROR_LOG_FILE)) {
    $lines = file(ERROR_LOG_FILE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach (array_reverse($lines) as $line) {
        $decoded = json_decode($line, true);
        if ($decoded) $entries[] = $decoded;
        if (count($entries) >= 200) break;
    }
}

$levelColors = [
    'FATAL' => '#ef4444', 'EXCEPTION' => '#ef4444', 'ERROR' => '#ef4444',
    'WARNING' => '#f59e0b', 'NOTICE' => '#60a5fa', 'DEPRECATED' => '#94a3b8', 'STRICT' => '#94a3b8',
];
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<?php $pageTitle = 'سجل الأخطاء — الإدارة'; $assetBase = '../'; include __DIR__ . '/../includes/head.php'; ?>
</head>
<body>
<div class="wrap wide">
  <div class="card row">
    <h2>سجل الأخطاء</h2>
    <a class="btn secondary" href="index">رجوع</a>
  </div>
  <div class="card row">
    <p class="muted">آخر <?= count($entries) ?> خطأ/تحذير مسجّل (من إجمالي الملف، الأحدث أولاً). يُقلَّم الملف تلقائياً عند بلوغه 5MB.</p>
    <form method="post" onsubmit="return confirm('مسح كل السجل؟')">
      <button type="submit" name="clear" value="1" class="secondary">مسح السجل</button>
    </form>
  </div>

  <?php if (empty($entries)): ?>
    <div class="card ok">لا توجد أخطاء مسجّلة 🎉</div>
  <?php else: foreach ($entries as $e): ?>
    <div class="card">
      <div class="row">
        <span class="badge" style="background:<?= htmlspecialchars($levelColors[$e['level']] ?? '#64748b') ?>22;color:<?= htmlspecialchars($levelColors[$e['level']] ?? '#64748b') ?>">
          <?= htmlspecialchars($e['level']) ?>
        </span>
        <span class="muted"><?= date('Y-m-d H:i:s', (int)$e['time']) ?></span>
      </div>
      <p style="margin-top:8px"><?= htmlspecialchars($e['message']) ?></p>
      <p class="muted" style="direction:ltr;text-align:right;font-family:monospace">
        <?= htmlspecialchars($e['file'] ?? '') ?><?= $e['line'] ? ':' . (int)$e['line'] : '' ?>
      </p>
      <?php if (!empty($e['url'])): ?><p class="muted">الطلب: <?= htmlspecialchars($e['url']) ?></p><?php endif; ?>
      <?php if (!empty($e['trace'])): ?>
        <details>
          <summary class="muted" style="cursor:pointer">Stack trace</summary>
          <pre style="white-space:pre-wrap;font-size:11px;color:var(--text3)"><?= htmlspecialchars($e['trace']) ?></pre>
        </details>
      <?php endif; ?>
    </div>
  <?php endforeach; endif; ?>
</div>
</body>
</html>
