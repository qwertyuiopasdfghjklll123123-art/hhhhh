<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_admin();

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_manual') {
        $name = trim($_POST['name'] ?? '');
        if ($name) {
            db()->prepare(
                "INSERT INTO payment_methods (name, icon, number, exchange_rate, currency, note, active, method_type, method_extras, created_at)
                 VALUES (?,?,?,?,?,?,?,'manual','{}',?)"
            )->execute([
                $name, ($_POST['icon'] ?? '') ?: 'fa-solid fa-credit-card', $_POST['number'] ?? '',
                $_POST['exchange_rate'] ?? '', ($_POST['currency'] ?? '') ?: 'IQD', $_POST['note'] ?? '',
                isset($_POST['active']) ? 1 : 0, time(),
            ]);
        }
    } elseif ($action === 'update_manual') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('UPDATE payment_methods SET name=?, number=?, exchange_rate=?, currency=?, note=?, active=? WHERE id=? AND method_type="manual"')
            ->execute([$_POST['name'] ?? '', $_POST['number'] ?? '', $_POST['exchange_rate'] ?? '', ($_POST['currency'] ?? '') ?: 'IQD', $_POST['note'] ?? '', isset($_POST['active']) ? 1 : 0, $id]);
    } elseif ($action === 'delete_manual') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare('DELETE FROM payment_methods WHERE id=? AND method_type="manual"')->execute([$id]);
    } elseif ($action === 'save_binance') {
        $row = db()->query("SELECT * FROM payment_methods WHERE method_type='binance' LIMIT 1")->fetch();
        if ($row) {
            $extras = json_decode($row['method_extras'] ?: '{}', true) ?: [];
            if (!empty($_POST['api_key'])) $extras['api_key'] = trim($_POST['api_key']);
            if (!empty($_POST['api_secret'])) $extras['api_secret'] = trim($_POST['api_secret']);
            db()->prepare('UPDATE payment_methods SET number=?, active=?, method_extras=? WHERE id=?')
                ->execute([trim($_POST['binance_id'] ?? ''), isset($_POST['active']) ? 1 : 0, json_encode($extras), $row['id']]);
        }
    } elseif ($action === 'save_asiacell') {
        $row = db()->query("SELECT * FROM payment_methods WHERE method_type='asiacell' LIMIT 1")->fetch();
        if ($row) {
            $extras = json_decode($row['method_extras'] ?: '{}', true) ?: [];
            $maxTransfer = (int)($_POST['max_transfer'] ?? 10000) ?: 10000;
            $extras['max_transfer'] = max(1000, $maxTransfer);
            db()->prepare('UPDATE payment_methods SET number=?, exchange_rate=?, active=?, method_extras=? WHERE id=?')
                ->execute([trim($_POST['receiver'] ?? ''), $_POST['exchange_rate'] ?? '', isset($_POST['active']) ? 1 : 0, json_encode($extras), $row['id']]);
        }
    } elseif ($action === 'save_tgstars') {
        $row = db()->query("SELECT * FROM payment_methods WHERE method_type='telegram_stars' LIMIT 1")->fetch();
        if ($row) {
            $extras = json_decode($row['method_extras'] ?: '{}', true) ?: [];
            if (!empty($_POST['bot_token'])) $extras['bot_token'] = trim($_POST['bot_token']);
            $extras['bot_username'] = trim($_POST['bot_username'] ?? '');
            $extras['admin_chat_id'] = trim($_POST['admin_chat_id'] ?? '');
            $extras['rate_usd_per_100'] = (float)($_POST['rate_usd_per_100'] ?? 1.5);
            db()->prepare('UPDATE payment_methods SET active=?, method_extras=? WHERE id=?')
                ->execute([isset($_POST['active']) ? 1 : 0, json_encode($extras), $row['id']]);
        }
    }
    $_SESSION['flash'] = ['ok' => true, 'msg' => 'تم الحفظ'];
    header('Location: payment_methods');
    exit;
}

$all = db()->query('SELECT * FROM payment_methods ORDER BY sort_order ASC')->fetchAll();
$binance = null; $asiacell = null; $tgStars = null; $manual = [];
foreach ($all as $m) {
    if ($m['method_type'] === 'binance') $binance = $m;
    elseif ($m['method_type'] === 'asiacell') $asiacell = $m;
    elseif ($m['method_type'] === 'telegram_stars') $tgStars = $m;
    else $manual[] = $m;
}
$binanceExtras = $binance ? (json_decode($binance['method_extras'] ?: '{}', true) ?: []) : [];
$asiacellExtras = $asiacell ? (json_decode($asiacell['method_extras'] ?: '{}', true) ?: []) : [];
$tgExtras = $tgStars ? (json_decode($tgStars['method_extras'] ?: '{}', true) ?: []) : [];
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<?php $pageTitle = 'طرق الدفع — الإدارة'; $assetBase = '../'; include __DIR__ . '/../includes/head.php'; ?>
</head>
<body>
<div class="wrap">
  <div class="card row">
    <h2>طرق الدفع</h2>
    <a class="btn secondary" href="index">رجوع</a>
  </div>
  <?php if ($flash): ?><div class="card ok"><?= htmlspecialchars($flash['msg']) ?></div><?php endif; ?>

  <?php if ($binance): ?>
  <div class="card">
    <h3>Binance Pay <span class="muted">(تحقق تلقائي فوري)</span></h3>
    <form method="post">
      <input type="hidden" name="action" value="save_binance">
      <label>Binance Pay ID (يُعرض للمستخدم)</label>
      <input type="text" name="binance_id" value="<?= htmlspecialchars($binance['number']) ?>">
      <label>API Key <span class="muted">(اتركه فاضي للاحتفاظ بالحالي)</span></label>
      <input type="text" name="api_key">
      <label>API Secret <span class="muted">(اتركه فاضي للاحتفاظ بالحالي)</span></label>
      <input type="text" name="api_secret">
      <label><input type="checkbox" name="active" <?= $binance['active'] ? 'checked' : '' ?> style="width:auto;display:inline"> فعّال</label>
      <button type="submit">حفظ</button>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($asiacell): ?>
  <div class="card">
    <h3>آسياسيل <span class="muted">(التحقق التلقائي الكامل غير منقول بعد — راجع README)</span></h3>
    <form method="post">
      <input type="hidden" name="action" value="save_asiacell">
      <label>رقم الاستقبال</label>
      <input type="text" name="receiver" value="<?= htmlspecialchars($asiacell['number']) ?>">
      <label>سعر الصرف (IQD لكل USD)</label>
      <input type="text" name="exchange_rate" value="<?= htmlspecialchars($asiacell['exchange_rate']) ?>">
      <label>أقصى مبلغ تحويل (IQD)</label>
      <input type="number" name="max_transfer" value="<?= htmlspecialchars($asiacellExtras['max_transfer'] ?? 10000) ?>">
      <label><input type="checkbox" name="active" <?= $asiacell['active'] ? 'checked' : '' ?> style="width:auto;display:inline"> فعّال</label>
      <button type="submit">حفظ</button>
    </form>
  </div>
  <?php endif; ?>

  <?php if ($tgStars): ?>
  <div class="card">
    <h3>نجوم تليجرام <span class="muted">(ربط البوت الفعلي غير منقول بعد — راجع README)</span></h3>
    <form method="post">
      <input type="hidden" name="action" value="save_tgstars">
      <label>Bot Token <span class="muted">(اتركه فاضي للاحتفاظ بالحالي)</span></label>
      <input type="text" name="bot_token">
      <label>اسم البوت (username)</label>
      <input type="text" name="bot_username" value="<?= htmlspecialchars($tgExtras['bot_username'] ?? '') ?>">
      <label>Chat ID الأدمن</label>
      <input type="text" name="admin_chat_id" value="<?= htmlspecialchars($tgExtras['admin_chat_id'] ?? '') ?>">
      <label>السعر (USD لكل 100 نجمة)</label>
      <input type="number" step="0.01" name="rate_usd_per_100" value="<?= htmlspecialchars($tgExtras['rate_usd_per_100'] ?? 1.5) ?>">
      <label><input type="checkbox" name="active" <?= $tgStars['active'] ? 'checked' : '' ?> style="width:auto;display:inline"> فعّال</label>
      <button type="submit">حفظ</button>
    </form>
  </div>
  <?php endif; ?>

  <div class="card">
    <h3>طرق دفع يدوية</h3>
    <?php foreach ($manual as $m): ?>
      <form method="post" class="card" style="background:#0f172a">
        <input type="hidden" name="action" value="update_manual">
        <input type="hidden" name="id" value="<?= $m['id'] ?>">
        <label>الاسم</label>
        <input type="text" name="name" value="<?= htmlspecialchars($m['name']) ?>">
        <label>رقم/حساب الاستقبال</label>
        <input type="text" name="number" value="<?= htmlspecialchars($m['number']) ?>">
        <label>سعر الصرف</label>
        <input type="text" name="exchange_rate" value="<?= htmlspecialchars($m['exchange_rate']) ?>">
        <label>العملة</label>
        <input type="text" name="currency" value="<?= htmlspecialchars($m['currency']) ?>">
        <label>ملاحظة</label>
        <input type="text" name="note" value="<?= htmlspecialchars($m['note']) ?>">
        <label><input type="checkbox" name="active" <?= $m['active'] ? 'checked' : '' ?> style="width:auto;display:inline"> فعّال</label>
        <button type="submit">حفظ</button>
        <button type="submit" formnovalidate onclick="this.form.action.value='delete_manual'" class="secondary">حذف</button>
      </form>
    <?php endforeach; ?>

    <h4>إضافة طريقة يدوية جديدة</h4>
    <form method="post">
      <input type="hidden" name="action" value="add_manual">
      <label>الاسم</label>
      <input type="text" name="name" required>
      <label>رقم/حساب الاستقبال</label>
      <input type="text" name="number">
      <label>سعر الصرف</label>
      <input type="text" name="exchange_rate">
      <label>العملة</label>
      <input type="text" name="currency" value="IQD">
      <label>ملاحظة</label>
      <input type="text" name="note">
      <label><input type="checkbox" name="active" checked style="width:auto;display:inline"> فعّال</label>
      <button type="submit">إضافة</button>
    </form>
  </div>
</div>
</body>
</html>
