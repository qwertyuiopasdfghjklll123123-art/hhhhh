<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['apply_code'])) {
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $myRef = get_or_create_referral($user['id']);
        if (!$code) {
            $_SESSION['flash'] = ['ok' => false, 'msg' => 'أدخل كود الإحالة'];
        } elseif ($myRef['referred_by']) {
            $_SESSION['flash'] = ['ok' => false, 'msg' => 'لديك إحالة مسبقاً'];
        } elseif ($myRef['code'] === $code) {
            $_SESSION['flash'] = ['ok' => false, 'msg' => 'لا يمكنك إحالة نفسك'];
        } else {
            $stmt = db()->prepare('SELECT * FROM referrals WHERE code = ?');
            $stmt->execute([$code]);
            $referrer = $stmt->fetch();
            if (!$referrer) {
                $_SESSION['flash'] = ['ok' => false, 'msg' => 'كود إحالة غير صالح'];
            } else {
                db()->prepare('UPDATE referrals SET referred_by=? WHERE user_id=?')->execute([$referrer['user_id'], $user['id']]);
                db()->prepare('UPDATE referrals SET ref_count=ref_count+1 WHERE user_id=?')->execute([$referrer['user_id']]);
                $_SESSION['flash'] = ['ok' => true, 'msg' => 'تم ربط كود الإحالة بنجاح'];
            }
        }
    } elseif (isset($_POST['withdraw'])) {
        $ref = get_or_create_referral($user['id']);
        if ((float)$ref['pending'] < REFERRAL_MIN_WITHDRAW) {
            $_SESSION['flash'] = ['ok' => false, 'msg' => 'الحد الأدنى للسحب $' . REFERRAL_MIN_WITHDRAW];
        } else {
            $pending = (float)$ref['pending'];
            db()->prepare('UPDATE referrals SET earnings = earnings + ?, pending = 0 WHERE user_id = ?')->execute([$pending, $user['id']]);
            db()->prepare('UPDATE users SET balance = balance + ? WHERE id = ?')->execute([$pending, $user['id']]);
            $_SESSION['flash'] = ['ok' => true, 'msg' => sprintf('تم سحب $%.2f لرصيدك', $pending)];
        }
    }
    header('Location: referral');
    exit;
}

$ref = get_or_create_referral($user['id']);
$histStmt = db()->prepare('SELECT * FROM referral_history WHERE referrer_id=? ORDER BY created_at DESC LIMIT 10');
$histStmt->execute([$user['id']]);
$history = $histStmt->fetchAll();
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<?php $pageTitle = 'برنامج الإحالة — fastcrand'; $assetBase = ''; include __DIR__ . '/includes/head.php'; ?>
</head>
<body>
<div class="wrap">
  <div class="card row">
    <h2>برنامج الإحالة</h2>
    <a class="btn secondary" href="dashboard">رجوع</a>
  </div>
  <?php if ($flash): ?><div class="card <?= $flash['ok'] ? 'ok' : 'error' ?>"><?= htmlspecialchars($flash['msg']) ?></div><?php endif; ?>

  <div class="card">
    <p>كودك: <strong><?= htmlspecialchars($ref['code']) ?></strong></p>
    <p class="muted">رابط الدعوة: <?= htmlspecialchars('https://' . ($_SERVER['HTTP_HOST'] ?? 'example.com') . '/php/register.php?ref=' . $ref['code']) ?></p>
    <p>عدد من أحلتهم: <strong><?= (int)$ref['ref_count'] ?></strong> — عمولتك: <strong><?= REFERRAL_COMMISSION ?>%</strong> من كل طلب لهم</p>
    <p>الأرباح المسحوبة: <strong>$<?= number_format((float)$ref['earnings'], 2) ?></strong> — قيد السحب: <strong>$<?= number_format((float)$ref['pending'], 2) ?></strong></p>
    <?php if (!$ref['referred_by']): ?>
    <form method="post">
      <label>لديك كود إحالة من صديق؟</label>
      <input type="text" name="code" placeholder="FC....">
      <button type="submit" name="apply_code" value="1">ربط الكود</button>
    </form>
    <?php endif; ?>
    <form method="post">
      <button type="submit" name="withdraw" value="1">سحب الأرباح المعلّقة إلى الرصيد</button>
    </form>
  </div>

  <div class="card">
    <h3>سجل العمولات</h3>
    <?php if (empty($history)): ?>
      <p class="muted">لا يوجد سجل بعد.</p>
    <?php else: ?>
      <table>
        <tr><th>المبلغ</th><th>الطلب</th><th>التاريخ</th></tr>
        <?php foreach ($history as $h): ?>
          <tr>
            <td>$<?= number_format((float)$h['amount'], 4) ?></td>
            <td><?= htmlspecialchars($h['from_order']) ?></td>
            <td><?= date('Y-m-d H:i', (int)$h['created_at']) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
