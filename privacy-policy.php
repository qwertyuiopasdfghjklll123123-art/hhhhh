<?php
require_once __DIR__ . '/includes/functions.php';
$appName = 'Almulla';
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/includes/db.php';
    require_once __DIR__ . '/includes/data.php';
    try {
        $settings = db_get_settings(getDb());
        if (!empty($settings['appName'])) $appName = $settings['appName'];
    } catch (Throwable $e) {
        // تجاهل أي خطأ اتصال والاستمرار بالاسم الافتراضي
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>سياسة الخصوصية | <?php echo htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800;900&display=swap" rel="stylesheet">
<style>
    :root{--bg:#ffffff;--bg2:#f8fafc;--t1:#0f172a;--t2:#334155;--t3:#64748b;--ac:#6366f1;--ac2:#818cf8;--card:#ffffff;--cardBd:#e2e8f0;}
    [data-t="dark"]{--bg:#0f172a;--bg2:#1e293b;--t1:#f1f5f9;--t2:#cbd5e1;--t3:#94a3b8;--ac:#818cf8;--ac2:#a5b4fc;--card:#1e293b;--cardBd:#334155;}
    *{box-sizing:border-box}
    body{font-family:'Tajawal',sans-serif;background:var(--bg);color:var(--t1);margin:0;padding:0;transition:background .3s,color .3s;}
    .wrap{max-width:760px;margin:0 auto;padding:40px 20px 70px;}
    .back{display:inline-flex;align-items:center;gap:6px;color:var(--ac);text-decoration:none;font-weight:700;font-size:0.85rem;margin-bottom:20px;}
    h1{font-size:1.6rem;font-weight:900;background:linear-gradient(135deg,var(--ac),var(--ac2));-webkit-background-clip:text;background-clip:text;color:transparent;margin-bottom:6px;}
    .updated{color:var(--t3);font-size:0.8rem;margin-bottom:30px;}
    .card{background:var(--card);border:1px solid var(--cardBd);border-radius:18px;padding:24px 26px;margin-bottom:18px;}
    .card h2{font-size:1.05rem;font-weight:800;margin:0 0 10px;color:var(--t1);}
    .card p, .card li{color:var(--t2);font-size:0.9rem;line-height:1.9;}
    .card ul{padding-inline-start:20px;margin:8px 0;}
    .note{background:rgba(99,102,241,0.08);border:1px solid rgba(99,102,241,0.25);border-radius:12px;padding:14px 16px;font-size:0.82rem;color:var(--t2);margin-bottom:24px;}
</style>
</head>
<body id="ppBody">
<div class="wrap">
    <a class="back" href="index.php">→ العودة إلى الموقع</a>
    <h1>سياسة الخصوصية</h1>
    <div class="updated">آخر تحديث: <?php echo date('Y-m-d'); ?></div>

    <div class="note">
        هذه صفحة نموذجية عامة لسياسة الخصوصية. يرجى من مالك الموقع مراجعتها وتعديلها لتعكس
        الممارسات الفعلية لجمع البيانات واستخدامها في هذا المتجر قبل الاعتماد عليها رسمياً.
    </div>

    <div class="card">
        <h2>١. البيانات التي نجمعها</h2>
        <p>عند إنشاء حساب أو استخدام <?php echo htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'); ?> قد نجمع:</p>
        <ul>
            <li>الاسم الكامل والبريد الإلكتروني عند إنشاء حساب.</li>
            <li>بيانات التصفح الأساسية (عدد الزيارات، المنتجات الأكثر طلباً) لتحسين الخدمة.</li>
            <li>أي معلومات ترسلها طوعاً عبر التواصل معنا (مثل واتساب).</li>
        </ul>
    </div>

    <div class="card">
        <h2>٢. كيف نستخدم بياناتك</h2>
        <ul>
            <li>لتمكينك من تسجيل الدخول وإدارة حسابك.</li>
            <li>لعرض المنتجات والخدمات المتاحة وتحسين تجربة التصفح.</li>
            <li>للرد على استفساراتك عند التواصل معنا.</li>
        </ul>
        <p>لا نبيع بياناتك الشخصية لأي طرف ثالث.</p>
    </div>

    <div class="card">
        <h2>٣. حماية البيانات</h2>
        <p>يتم تخزين كلمات المرور بصيغة مشفّرة، ولا يمكن لأي شخص الاطلاع عليها كنص صريح. نتخذ إجراءات
        معقولة لحماية بياناتك من الوصول غير المصرح به.</p>
    </div>

    <div class="card">
        <h2>٤. حقوقك</h2>
        <ul>
            <li>يمكنك تعديل بيانات حسابك في أي وقت من صفحة الملف الشخصي.</li>
            <li>يمكنك حذف حسابك نهائياً من نفس الصفحة.</li>
            <li>يمكنك التواصل معنا لأي استفسار يخص بياناتك.</li>
        </ul>
    </div>

    <div class="card">
        <h2>٥. التواصل</h2>
        <p>لأي استفسار حول هذه السياسة، يرجى التواصل معنا عبر واتساب من داخل التطبيق.</p>
    </div>
</div>
<script>
    try {
        var t = localStorage.getItem('theme');
        if (t === 'dark') document.body.setAttribute('data-t', 'dark');
    } catch (e) {}
</script>
</body>
</html>
