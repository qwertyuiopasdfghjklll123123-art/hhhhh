<?php
require_once __DIR__ . '/../includes/functions.php';
require_login();
require_admin();

$statusLabels = ['open' => 'مفتوحة', 'waiting' => 'بانتظار الرد', 'closed' => 'مغلقة'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tid = (int)($_POST['ticket_id'] ?? 0);
    if (isset($_POST['reply'])) {
        $msg = trim($_POST['message'] ?? '');
        $tStmt = db()->prepare('SELECT * FROM tickets WHERE id=?');
        $tStmt->execute([$tid]);
        $t = $tStmt->fetch();
        if ($msg && $t) {
            db()->prepare('INSERT INTO ticket_messages (ticket_id, sender, text, created_at) VALUES (?,?,?,?)')
                ->execute([$tid, 'admin', $msg, time()]);
            db()->prepare("UPDATE tickets SET status='open' WHERE id=?")->execute([$tid]);
            push_user_notif($t['user_id'], 'system', "رد جديد على تذكرة #TK-$tid", mb_substr($msg, 0, 100), 'ticket', "tickets.php?id=$tid");
        }
    } elseif (isset($_POST['close'])) {
        db()->prepare("UPDATE tickets SET status='closed' WHERE id=?")->execute([$tid]);
    }
    header("Location: tickets.php?id=$tid");
    exit;
}

$ticketId = (int)($_GET['id'] ?? 0);
if ($ticketId) {
    $stmt = db()->prepare('SELECT t.*, u.name AS user_name, u.email AS user_email FROM tickets t JOIN users u ON u.id=t.user_id WHERE t.id=?');
    $stmt->execute([$ticketId]);
    $ticket = $stmt->fetch();
    if ($ticket) {
        $msgStmt = db()->prepare('SELECT * FROM ticket_messages WHERE ticket_id=? ORDER BY created_at ASC');
        $msgStmt->execute([$ticketId]);
        $messages = $msgStmt->fetchAll();
    }
} else {
    $tickets = db()->query(
        'SELECT t.*, u.name AS user_name FROM tickets t JOIN users u ON u.id=t.user_id ORDER BY t.id DESC LIMIT 100'
    )->fetchAll();
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<title>التذاكر — الإدارة</title>
<link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="wrap">
  <div class="card row">
    <h2>التذاكر</h2>
    <a class="btn secondary" href="index.php">رجوع</a>
  </div>

  <?php if ($ticketId && !empty($ticket)): ?>
    <div class="card">
      <strong>#TK-<?= $ticket['id'] ?> — <?= htmlspecialchars($ticket['subject']) ?></strong>
      <span class="badge"><?= htmlspecialchars($statusLabels[$ticket['status']] ?? $ticket['status']) ?></span>
      <div class="muted"><?= htmlspecialchars($ticket['user_name']) ?> (<?= htmlspecialchars($ticket['user_email']) ?>)</div>
    </div>
    <div class="card">
      <?php foreach ($messages as $m): ?>
        <div style="margin-bottom:10px; text-align:<?= $m['sender'] === 'user' ? 'right' : 'left' ?>">
          <div class="badge"><?= $m['sender'] === 'user' ? htmlspecialchars($ticket['user_name']) : 'الدعم' ?></div>
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
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="ticket_id" value="<?= $ticket['id'] ?>">
        <button type="submit" name="close" value="1" class="secondary">إغلاق التذكرة</button>
      </form>
    </div>
  <?php else: ?>
    <div class="card">
      <table>
        <tr><th>#</th><th>المستخدم</th><th>الموضوع</th><th>الحالة</th><th></th></tr>
        <?php foreach ($tickets as $t): ?>
          <tr>
            <td>TK-<?= $t['id'] ?></td>
            <td><?= htmlspecialchars($t['user_name']) ?></td>
            <td><?= htmlspecialchars($t['subject']) ?></td>
            <td><span class="badge"><?= htmlspecialchars($statusLabels[$t['status']] ?? $t['status']) ?></span></td>
            <td><a href="tickets.php?id=<?= $t['id'] ?>">فتح</a></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
