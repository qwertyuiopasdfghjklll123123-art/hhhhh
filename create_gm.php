<?php
/* ======================================================================
   أداة مستقلة لإنشاء حساب "مسؤول عام" مباشرة في قاعدة البيانات —
   لمن يحتاج إنشاء الحساب دون المرور بخطوات migrate.php الكاملة.
   ملف واحد مستقل لا يعتمد على أي ملف آخر عدا config.php.
   ====================================================================== */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

if (!file_exists(__DIR__ . '/config.php')) {
    header('Location: /install.php');
    exit;
}
require_once __DIR__ . '/config.php';

function h(?string $v): string { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); }

try {
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (Throwable $e) {
    die('تعذر الاتصال بقاعدة البيانات: ' . h($e->getMessage()));
}

$roleSupportsGm = false;
try {
    $col = $pdo->query("SHOW COLUMNS FROM users LIKE 'role'")->fetch();
    $roleSupportsGm = $col && strpos($col['Type'], 'general_manager') !== false;
} catch (Throwable $e) {
    // جدول users غير موجود على الأغلب — سيظهر الخطأ في الواجهة أدناه
}

$existingGmAccounts = [];
if ($roleSupportsGm) {
    try {
        $existingGmAccounts = $pdo->query("SELECT id, username, status, created_at FROM users WHERE role='general_manager' ORDER BY id")->fetchAll();
    } catch (Throwable $e) {
        // تجاهل
    }
}

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $gmEmail = trim($_POST['gm_email'] ?? '');
    $gmPassword = (string) ($_POST['gm_password'] ?? '');
    $gmPasswordConfirm = (string) ($_POST['gm_password_confirm'] ?? '');

    if (!$roleSupportsGm) {
        $result = ['ok' => false, 'error' => 'قاعدة البيانات ليست محدَّثة بعد — شغّل migrate.php أولاً حتى يُضاف دور "المسؤول العام"، ثم عد إلى هذه الصفحة.'];
    } elseif ($gmEmail === '' || $gmPassword === '') {
        $result = ['ok' => false, 'error' => 'الرجاء تعبئة البريد الإلكتروني وكلمة المرور.'];
    } elseif (!filter_var($gmEmail, FILTER_VALIDATE_EMAIL) && !preg_match('/^[\w.\-]{3,}$/u', $gmEmail)) {
        $result = ['ok' => false, 'error' => 'الرجاء إدخال بريد إلكتروني أو اسم مستخدم صحيح.'];
    } elseif ($gmPassword !== $gmPasswordConfirm) {
        $result = ['ok' => false, 'error' => 'كلمتا المرور غير متطابقتين.'];
    } elseif (strlen($gmPassword) < 6) {
        $result = ['ok' => false, 'error' => 'كلمة المرور يجب أن تكون 6 أحرف على الأقل.'];
    } else {
        try {
            $existing = $pdo->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
            $existing->execute([$gmEmail]);
            if ((int) $existing->fetchColumn() > 0) {
                $result = ['ok' => false, 'error' => 'هذا البريد الإلكتروني مستخدم من قبل حساب آخر في النظام.'];
            } else {
                $hash = password_hash($gmPassword, PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO users (role, username, password_hash, status) VALUES ('general_manager', ?, ?, 'active')")
                    ->execute([$gmEmail, $hash]);
                $result = ['ok' => true, 'email' => $gmEmail];
                $existingGmAccounts = $pdo->query("SELECT id, username, status, created_at FROM users WHERE role='general_manager' ORDER BY id")->fetchAll();
            }
        } catch (Throwable $e) {
            $result = ['ok' => false, 'error' => 'تعذر إنشاء الحساب: ' . $e->getMessage()];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>إنشاء حساب المسؤول العام — شركة الصوى للصرافة</title>
<style>
@import url('https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&display=swap');
:root{--primary:#006b73;--primary-light:#0A8A94;--primary-dark:#004b52;--primary-gradient:linear-gradient(135deg,#006b73 0%,#0A8A94 100%);--accent:#c99a3d;--bg:#F0F4F8;--border:#E1E8ED;--text:#1A2E35;--text-muted:#8AA0B0;--success:#159447;--danger:#df4b4b;--radius:14px;}
*{box-sizing:border-box;}
body{margin:0;font-family:'IBM Plex Sans Arabic',sans-serif;background:var(--bg);color:var(--text);direction:rtl;text-align:right;min-height:100vh;padding:24px;}
.card{width:100%;max-width:520px;margin:0 auto;background:#fff;border:1px solid var(--border);border-radius:var(--radius);box-shadow:0 10px 30px rgba(0,107,115,.10);padding:32px;}
h1{font-size:20px;font-weight:700;margin:0 0 6px;color:var(--primary-dark);}
.sub{color:var(--text-muted);font-size:13px;margin-bottom:20px;}
.alert{padding:12px 16px;border-radius:10px;font-size:13px;margin-bottom:16px;}
.alert-success{background:#E8F6EE;color:#0E6B34;}
.alert-danger{background:#FCEAEA;color:var(--danger);}
.alert-warn{background:#FFF4E0;color:#B87400;}
label{display:block;font-size:12.5px;margin-bottom:5px;color:var(--text-muted);}
input{width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:8px;font-family:inherit;font-size:13.5px;margin-bottom:14px;}
.btn{display:inline-block;padding:11px 24px;border-radius:10px;border:none;font-family:inherit;font-weight:600;font-size:14px;cursor:pointer;background:var(--primary-gradient);color:#fff;text-decoration:none;box-shadow:0 4px 14px rgba(0,107,115,.25);width:100%;}
.links{display:flex;gap:10px;margin-top:20px;flex-wrap:wrap;}
.links a{color:var(--primary);font-size:13px;text-decoration:none;font-weight:600;}
table{width:100%;border-collapse:collapse;font-size:12.5px;margin-top:6px;}
th,td{padding:8px 10px;text-align:right;border-bottom:1px solid var(--border);}
.badge{padding:2px 8px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-active{background:#E8F6EE;color:var(--success);}
.badge-inactive{background:#FCEAEA;color:var(--danger);}
</style>
</head>
<body>
<div class="card">
    <h1>👤 إنشاء حساب المسؤول العام</h1>
    <div class="sub">أداة مستقلة لإنشاء حساب دخول جديد بصلاحية "مسؤول عام" مباشرة في قاعدة بيانات النظام.</div>

    <?php if (!$roleSupportsGm): ?>
        <div class="alert alert-warn">⚠️ قاعدة البيانات ليست محدَّثة بعد — دور "المسؤول العام" غير مضاف بعد لجدول المستخدمين. شغّل <a href="/migrate.php">migrate.php</a> أولاً، ثم عد إلى هذه الصفحة.</div>
    <?php endif; ?>

    <?php if ($result && $result['ok']): ?>
        <div class="alert alert-success">✅ تم إنشاء حساب المسؤول العام بنجاح للبريد <strong><?= h($result['email']) ?></strong>. يمكنك الآن <a href="/general">تسجيل الدخول</a>.</div>
    <?php elseif ($result && !$result['ok']): ?>
        <div class="alert alert-danger"><?= h($result['error']) ?></div>
    <?php endif; ?>

    <?php if ($roleSupportsGm): ?>
    <form method="post">
        <label>البريد الإلكتروني لتسجيل الدخول</label>
        <input type="text" name="gm_email" required value="<?= h($_POST['gm_email'] ?? '') ?>">
        <label>كلمة المرور (6 أحرف على الأقل)</label>
        <input type="password" name="gm_password" required minlength="6">
        <label>تأكيد كلمة المرور</label>
        <input type="password" name="gm_password_confirm" required minlength="6">
        <button type="submit" class="btn">إنشاء الحساب</button>
    </form>
    <?php endif; ?>

    <?php if ($existingGmAccounts): ?>
        <div class="sub" style="margin-top:24px;margin-bottom:4px;">حسابات المسؤول العام الحالية:</div>
        <table>
            <tr><th>#</th><th>البريد الإلكتروني</th><th>الحالة</th></tr>
            <?php foreach ($existingGmAccounts as $g): ?>
                <tr>
                    <td><?= (int) $g['id'] ?></td>
                    <td><?= h($g['username']) ?></td>
                    <td><span class="badge <?= $g['status'] === 'active' ? 'badge-active' : 'badge-inactive' ?>"><?= $g['status'] === 'active' ? 'فعّال' : 'موقوف' ?></span></td>
                </tr>
            <?php endforeach; ?>
        </table>
    <?php endif; ?>

    <div class="links">
        <a href="/general">لوحة المسؤول العام</a>
        <a href="/migrate.php">تحديث قاعدة البيانات</a>
        <a href="/diagnose.php">تشخيص النظام</a>
    </div>

    <p style="font-size:11.5px;color:var(--text-muted);margin-top:24px;">
        لأسباب أمنية، يُفضَّل حذف هذا الملف (create_gm.php) من السيرفر بعد إنشاء الحساب المطلوب.
    </p>
</div>
</body>
</html>
