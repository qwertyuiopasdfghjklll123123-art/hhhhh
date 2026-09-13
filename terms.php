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
<title>الشروط والأحكام | <?php echo htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'); ?></title>
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
<body id="termsBody">
<div class="wrap">
    <a class="back" href="index.php">→ العودة إلى الموقع</a>
    <h1>الشروط والأحكام</h1>
    <div class="updated">آخر تحديث: <?php echo date('Y-m-d'); ?></div>

    <div class="note">
        هذه صفحة نموذجية عامة للشروط والأحكام. يرجى من مالك الموقع مراجعتها وتعديلها بما يتوافق
        مع طبيعة نشاطه التجاري الفعلية قبل الاعتماد عليها رسمياً.
    </div>

    <div class="card">
        <h2>١. قبول الشروط</h2>
        <p>باستخدامك لتطبيق <?php echo htmlspecialchars($appName, ENT_QUOTES, 'UTF-8'); ?> فإنك توافق على الالتزام بهذه الشروط والأحكام.
        إذا كنت لا توافق على أي جزء منها، يرجى التوقف عن استخدام التطبيق.</p>
    </div>

    <div class="card">
        <h2>٢. استخدام الحساب</h2>
        <ul>
            <li>أنت مسؤول عن الحفاظ على سرية بيانات دخولك.</li>
            <li>يجب أن تكون المعلومات التي تقدمها عند التسجيل صحيحة ودقيقة.</li>
            <li>يحق لإدارة الموقع تعليق أو حذف أي حساب يُستخدم بشكل مخالف.</li>
        </ul>
    </div>

    <div class="card">
        <h2>٣. المنتجات والأسعار والطلبات</h2>
        <p>المعلومات المعروضة عن المنتجات والخدمات (الأسعار، التوفر، الصور) هي لأغراض العرض وقد تتغير
        دون إشعار مسبق. يتم تأكيد تفاصيل الطلب النهائية عبر التواصل المباشر (واتساب) قبل إتمام أي عملية بيع.</p>
    </div>

    <div class="card">
        <h2>٤. الملكية الفكرية</h2>
        <p>جميع الشعارات والمحتوى الظاهر في التطبيق مملوكة لصاحب المتجر، ولا يجوز إعادة استخدامها دون إذن.</p>
    </div>

    <div class="card">
        <h2>٥. حدود المسؤولية</h2>
        <p>نبذل جهدنا لضمان دقة المعلومات المعروضة، لكننا لا نتحمل مسؤولية أي أخطاء طباعية أو فنية غير مقصودة.</p>
    </div>

    <div class="card">
        <h2>٦. التعديلات</h2>
        <p>يجوز تحديث هذه الشروط من وقت لآخر، ويُعتبر استمرارك في استخدام التطبيق بعد أي تعديل موافقة ضمنية عليه.</p>
    </div>

    <div class="card">
        <h2>٧. التواصل</h2>
        <p>لأي استفسار حول هذه الشروط، يرجى التواصل معنا عبر واتساب من داخل التطبيق.</p>
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
