<?php
require_once __DIR__ . '/includes/functions.php';
require_login();
$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create'])) {
        $subject = trim($_POST['subject'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $category = trim($_POST['category'] ?? 'عام') ?: 'عام';
        if ($subject && $desc) {
            db()->prepare('INSERT INTO tickets (user_id, subject, category, created_at) VALUES (?,?,?,?)')
                ->execute([$user['id'], $subject, $category, time()]);
            $tid = (int)db()->lastInsertId();
            db()->prepare('INSERT INTO ticket_messages (ticket_id, sender, text, created_at) VALUES (?,?,?,?)')
                ->execute([$tid, 'user', $desc, time()]);
            push_user_notif($user['id'], 'system', "تم إنشاء تذكرة #TK-$tid", "$subject — سيتم الرد عليك قريباً", 'ticket', "tickets?id=$tid");
            $admins = db()->query('SELECT id FROM users WHERE is_admin=1')->fetchAll();
            foreach ($admins as $a) {
                push_user_notif($a['id'], 'admin', "تذكرة جديدة #TK-$tid", "من {$user['name']} — $subject", 'ticket', "tickets?id=$tid");
            }
            header("Location: tickets?id=$tid");
            exit;
        }
    } elseif (isset($_POST['reply'])) {
        $tid = (int)($_POST['ticket_id'] ?? 0);
        $msg = trim($_POST['message'] ?? '');
        $stmt = db()->prepare('SELECT * FROM tickets WHERE id=? AND user_id=?');
        $stmt->execute([$tid, $user['id']]);
        if ($msg && $stmt->fetch()) {
            db()->prepare('INSERT INTO ticket_messages (ticket_id, sender, text, created_at) VALUES (?,?,?,?)')
                ->execute([$tid, 'user', $msg, time()]);
            db()->prepare("UPDATE tickets SET status='waiting' WHERE id=?")->execute([$tid]);
            $admins = db()->query('SELECT id FROM users WHERE is_admin=1')->fetchAll();
            foreach ($admins as $a) {
                push_user_notif($a['id'], 'admin', "رد جديد على تذكرة #TK-$tid", "من {$user['name']}", 'message', "tickets?id=$tid");
            }
        }
        header("Location: tickets?id=$tid");
        exit;
    }
}

$ticketId = (int)($_GET['id'] ?? 0);
$statusLabels = ['open' => 'مفتوحة', 'waiting' => 'بانتظار الرد', 'closed' => 'مغلقة'];

if ($ticketId) {
    $stmt = db()->prepare('SELECT * FROM tickets WHERE id=? AND user_id=?');
    $stmt->execute([$ticketId, $user['id']]);
    $ticket = $stmt->fetch();
    if ($ticket) {
        $msgStmt = db()->prepare('SELECT * FROM ticket_messages WHERE ticket_id=? ORDER BY created_at ASC');
        $msgStmt->execute([$ticketId]);
        $messages = $msgStmt->fetchAll();
    }
} else {
    $stmt = db()->prepare('SELECT * FROM tickets WHERE user_id=? ORDER BY id DESC');
    $stmt->execute([$user['id']]);
    $myTickets = $stmt->fetchAll();
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<?php $pageTitle = 'الدعم الفني — fastcrand'; $assetBase = ''; include __DIR__ . '/includes/head.php'; ?>
</head>
<body>
<div class="wrap">
  <div class="card row">
    <h2>الدعم الفني</h2>
    <a class="btn secondary" href="dashboard">رجوع</a>
  </div>

  <?php if ($ticketId && !empty($ticket)): ?>
    <div class="card">
      <div class="row">
        <strong>#TK-<?= $ticket['id'] ?> — <?= htmlspecialchars($ticket['subject']) ?></strong>
        <span class="badge"><?= htmlspecialchars($statusLabels[$ticket['status']] ?? $ticket['status']) ?></span>
      </div>
      <div class="muted"><?= htmlspecialchars($ticket['category']) ?></div>
    </div>
    <div class="card">
      <?php foreach ($messages as $m): ?>
        <div style="margin-bottom:10px; text-align:<?= $m['sender'] === 'user' ? 'right' : 'left' ?>">
          <div class="badge"><?= $m['sender'] === 'user' ? 'أنت' : 'الدعم' ?></div>
          <div><?= nl2br(htmlspecialchars($m['text'])) ?></div>
          <div class="muted"><?= date('Y-m-d H:i', (int)$m['created_at']) ?></div>
        </div>
      <?php endforeach; ?>
      <?php if ($ticket['status'] !== 'closed'): ?>
      <form method="post">
        <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
        <textarea name="message" rows="3" required placeholder="اكتب ردك..."></textarea>
        <button type="submit" name="reply" value="1">إرسال</button>
      </form>
      <?php else: ?>
        <p class="muted">هذه التذكرة مغلقة.</p>
      <?php endif; ?>
    </div>
  <?php elseif ($ticketId): ?>
    <div class="card error">التذكرة غير موجودة</div>
  <?php else: ?>
    <div class="card">
      <h3>فتح تذكرة جديدة</h3>
      <form method="post">
        <label>الموضوع</label>
        <input type="text" name="subject" required>
        <label>التصنيف</label>
        <select name="category">
          <option value="عام">عام</option>
          <option value="مشكلة طلب">مشكلة طلب</option>
          <option value="شحن رصيد">شحن رصيد</option>
          <option value="أخرى">أخرى</option>
        </select>
        <label>الوصف</label>
        <textarea name="description" rows="4" required></textarea>
        <button type="submit" name="create" value="1">إرسال</button>
      </form>
    </div>
    <div class="card">
      <h3>تذاكري</h3>
      <?php if (empty($myTickets)): ?>
        <p class="muted">لا توجد تذاكر بعد.</p>
      <?php else: ?>
        <table>
          <tr><th>#</th><th>الموضوع</th><th>الحالة</th><th></th></tr>
          <?php foreach ($myTickets as $t): ?>
            <tr>
              <td>TK-<?= $t['id'] ?></td>
              <td><?= htmlspecialchars($t['subject']) ?></td>
              <td><span class="badge"><?= htmlspecialchars($statusLabels[$t['status']] ?? $t['status']) ?></span></td>
              <td><a href="tickets?id=<?= $t['id'] ?>">فتح</a></td>
            </tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
