<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/smm.php';
require_login();
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        if ($name) {
            db()->prepare('INSERT INTO providers (name, api_url, api_key, active, is_default, created_at) VALUES (?,?,?,1,0,?)')
                ->execute([$name, trim($_POST['api_url'] ?? ''), trim($_POST['api_key'] ?? ''), time()]);
        }
    } elseif ($action === 'update') {
        $pid = (int)($_POST['id'] ?? 0);
        if ($pid) {
            db()->prepare('UPDATE providers SET name=?, api_url=?, api_key=?, active=?, show_in_store=? WHERE id=?')
                ->execute([trim($_POST['name'] ?? ''), trim($_POST['api_url'] ?? ''), trim($_POST['api_key'] ?? ''),
                    isset($_POST['active']) ? 1 : 0, isset($_POST['show_in_store']) ? 1 : 0, $pid]);
            if (isset($_POST['is_default'])) {
                db()->exec('UPDATE providers SET is_default=0');
                db()->prepare('UPDATE providers SET is_default=1, show_in_store=1 WHERE id=?')->execute([$pid]);
            }
            invalidate_services_cache();
        }
    } elseif ($action === 'delete') {
        $pid = (int)($_POST['id'] ?? 0);
        $count = (int)db()->query('SELECT COUNT(*) AS c FROM providers')->fetch()['c'];
        if ($pid && $count > 1) {
            db()->prepare('DELETE FROM providers WHERE id=?')->execute([$pid]);
            db()->prepare('DELETE FROM service_filters WHERE prov_id=?')->execute([$pid]);
            $hasDefault = (int)db()->query('SELECT COUNT(*) AS c FROM providers WHERE is_default=1')->fetch()['c'];
            if (!$hasDefault) {
                $first = db()->query('SELECT id FROM providers LIMIT 1')->fetch();
                if ($first) db()->prepare('UPDATE providers SET is_default=1 WHERE id=?')->execute([$first['id']]);
            }
            invalidate_services_cache();
        } else {
            $_SESSION['flash'] = ['ok' => false, 'msg' => 'لا يمكن حذف المزوّد الوحيد'];
        }
    }
    header('Location: providers');
    exit;
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

$providers = get_all_providers();
foreach ($providers as &$p) {
    if ($p['active'] && $p['api_url'] && $p['api_key']) {
        $bal = smm_api_post($p['api_url'], ['key' => $p['api_key'], 'action' => 'balance'], 8);
        if (is_array($bal) && isset($bal['balance'])) {
            $p['live_balance'] = (float)$bal['balance'];
            db()->prepare('UPDATE providers SET balance=?, balance_updated=? WHERE id=?')->execute([$p['live_balance'], time(), $p['id']]);
        }
    }
}
unset($p);
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<?php $pageTitle = 'مزودو SMM — الإدارة'; $assetBase = '../'; include __DIR__ . '/../includes/head.php'; ?>
</head>
<body>
<div class="wrap">
  <div class="card row">
    <h2>مزودو SMM</h2>
    <a class="btn secondary" href="index">رجوع</a>
  </div>
  <?php if ($flash): ?><div class="card error"><?= htmlspecialchars($flash['msg']) ?></div><?php endif; ?>

  <?php foreach ($providers as $p): ?>
    <form method="post" class="card">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" value="<?= $p['id'] ?>">
      <div class="row">
        <strong><?= htmlspecialchars($p['name']) ?></strong>
        <?php if ($p['is_default']): ?><span class="badge">افتراضي</span><?php endif; ?>
      </div>
      <label>الاسم</label>
      <input type="text" name="name" value="<?= htmlspecialchars($p['name']) ?>">
      <label>رابط API</label>
      <input type="text" name="api_url" value="<?= htmlspecialchars($p['api_url']) ?>">
      <label>مفتاح API</label>
      <input type="text" name="api_key" value="<?= htmlspecialchars($p['api_key']) ?>">
      <p class="muted">الرصيد لدى المزوّد: <?= isset($p['live_balance']) ? '$' . number_format($p['live_balance'], 2) : '$' . number_format((float)$p['balance'], 2) . ' (مخزّن، تعذّر التحديث الآن)' ?></p>
      <label><input type="checkbox" name="active" <?= $p['active'] ? 'checked' : '' ?> style="width:auto;display:inline"> فعّال</label>
      <label><input type="checkbox" name="show_in_store" <?= $p['show_in_store'] ? 'checked' : '' ?> style="width:auto;display:inline"> يعرض خدماته بالمتجر</label>
      <label><input type="checkbox" name="is_default" <?= $p['is_default'] ? 'checked' : '' ?> style="width:auto;display:inline"> المزوّد الافتراضي</label>
      <button type="submit">حفظ</button>
      <?php if (count($providers) > 1): ?>
        <button type="submit" name="action" value="delete" class="secondary" onclick="return confirm('حذف هذا المزوّد؟')">حذف</button>
      <?php endif; ?>
    </form>
  <?php endforeach; ?>

  <div class="card">
    <h3>إضافة مزوّد جديد</h3>
    <form method="post">
      <input type="hidden" name="action" value="add">
      <label>الاسم</label>
      <input type="text" name="name" required>
      <label>رابط API</label>
      <input type="text" name="api_url">
      <label>مفتاح API</label>
      <input type="text" name="api_key">
      <button type="submit">إضافة</button>
    </form>
  </div>
</div>
</body>
</html>
