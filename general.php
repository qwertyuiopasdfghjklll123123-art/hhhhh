<?php
/* ======================================================================
   لوحة المسؤول العام (General Manager) — الاعتماد النهائي فوق HR
   على الإيجازات اليومية بعد موافقة الموارد البشرية.
   ====================================================================== */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');

if (!file_exists(__DIR__ . '/config.php')) {
    header('Location: /install.php');
    exit;
}
require_once __DIR__ . '/config.php';

ini_set('session.gc_maxlifetime', (string) (86400 * 30));
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'lifetime' => 86400 * 30]);
session_start();

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

$isLoggedIn = !empty($_SESSION['gm_user']);

/* ======================================================================
   نقاط AJAX
   ====================================================================== */
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_GET['ajax'];

    try {
        $pdo = db();
    } catch (Throwable $ex) {
        echo json_encode(['ok' => false, 'error' => 'تعذر الاتصال بقاعدة البيانات']);
        exit;
    }

    if ($action === 'login') {
        $username = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND role = 'general_manager' AND status = 'active' LIMIT 1");
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        if ($row && password_verify($password, $row['password_hash'])) {
            $_SESSION['gm_user'] = ['id' => (int) $row['id'], 'username' => $row['username']];
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'البريد الإلكتروني أو كلمة المرور غير صحيحة']);
        }
        exit;
    }

    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        echo json_encode(['ok' => true]);
        exit;
    }

    if (empty($_SESSION['gm_user'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'unauthorized']);
        exit;
    }
    $gmUser = $_SESSION['gm_user'];

    switch ($action) {

        case 'bootstrap': {
            $pending = (int) $pdo->query("SELECT COUNT(*) FROM daily_briefs WHERE status='hr_approved'")->fetchColumn();
            $approvedToday = (int) $pdo->query("SELECT COUNT(*) FROM daily_briefs WHERE status='approved' AND brief_date=CURDATE()")->fetchColumn();
            $branches = (int) $pdo->query("SELECT COUNT(*) FROM branches WHERE status='active'")->fetchColumn();
            $employees = (int) $pdo->query("SELECT COUNT(*) FROM employees WHERE status='active'")->fetchColumn();
            echo json_encode([
                'ok' => true,
                'username' => $gmUser['username'],
                'stats' => [
                    'pending' => $pending,
                    'approvedToday' => $approvedToday,
                    'branches' => $branches,
                    'employees' => $employees,
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'briefs_pending': {
            $stmt = $pdo->query("
                SELECT db.id, b.name AS branch, DATE_FORMAT(db.brief_date, '%d/%m/%Y') AS date,
                       db.total_income AS revenue, db.total_expense AS expenses, db.travelers_count AS travelersCount,
                       db.note, db.hr_note AS hrNote
                FROM daily_briefs db JOIN branches b ON b.id = db.branch_id
                WHERE db.status = 'hr_approved'
                ORDER BY db.brief_date DESC, db.id DESC
            ");
            $rows = array_map(function ($r) {
                $r['revenue'] = (float) $r['revenue'];
                $r['expenses'] = (float) $r['expenses'];
                $r['travelersCount'] = (int) $r['travelersCount'];
                $r['netProfit'] = $r['revenue'] - $r['expenses'];
                return $r;
            }, $stmt->fetchAll());
            echo json_encode(['ok' => true, 'briefs' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'briefs_history': {
            $stmt = $pdo->query("
                SELECT db.id, b.name AS branch, DATE_FORMAT(db.brief_date, '%d/%m/%Y') AS date,
                       db.total_income AS revenue, db.total_expense AS expenses, db.travelers_count AS travelersCount,
                       db.status, db.gm_review_note AS gmNote
                FROM daily_briefs db JOIN branches b ON b.id = db.branch_id
                WHERE db.status IN ('approved','rejected')
                ORDER BY db.brief_date DESC, db.id DESC LIMIT 50
            ");
            $rows = array_map(function ($r) {
                $r['revenue'] = (float) $r['revenue'];
                $r['expenses'] = (float) $r['expenses'];
                $r['travelersCount'] = (int) $r['travelersCount'];
                $r['netProfit'] = $r['revenue'] - $r['expenses'];
                return $r;
            }, $stmt->fetchAll());
            echo json_encode(['ok' => true, 'briefs' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'brief_final_review': {
            $id = (int) ($_POST['id'] ?? 0);
            $decision = ($_POST['decision'] ?? '') === 'approved' ? 'approved' : 'rejected';
            $note = trim($_POST['note'] ?? '');
            $stmt = $pdo->prepare("UPDATE daily_briefs SET status=?, gm_review_note=?, gm_reviewed_by=?, gm_reviewed_at=NOW() WHERE id=? AND status='hr_approved'");
            $stmt->execute([$decision, $note, $gmUser['id'], $id]);
            if ($stmt->rowCount() === 0) {
                echo json_encode(['ok' => false, 'error' => 'هذا الإيجاز ليس بانتظار اعتمادك']);
                exit;
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        case 'branches_overview': {
            $rows = $pdo->query("
                SELECT b.id, b.name, e.full_name AS manager,
                       (SELECT COUNT(*) FROM employees WHERE branch_id=b.id AND is_branch_manager=0 AND status='active') AS employeeCount
                FROM branches b
                LEFT JOIN employees e ON e.branch_id = b.id AND e.is_branch_manager = 1
                WHERE b.status = 'active'
                ORDER BY b.name
            ")->fetchAll();
            echo json_encode(['ok' => true, 'branches' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    echo json_encode(['ok' => false, 'error' => 'unknown action']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>شركة الصوى للصرافة - المسؤول العام</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
<style>
    :root {
        --primary: #006b73;
        --primary-light: #0A8A94;
        --primary-dark: #004b52;
        --primary-gradient: linear-gradient(135deg, #006b73 0%, #0A8A94 100%);
        --accent: #c99a3d;
        --green: #159447;
        --red: #df4b4b;
        --orange: #d98c1a;
        --bg: #F0F4F8;
        --bg-card: #FFFFFF;
        --text-primary: #1A2E35;
        --text-muted: #8AA0B0;
        --radius-sm: 8px;
        --radius-md: 14px;
        --radius-lg: 20px;
        --font-family: 'IBM Plex Sans Arabic', 'Tajawal', sans-serif;
    }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: var(--font-family); background: var(--bg); color: var(--text-primary); min-height: 100vh; font-size: 14px; }
    .hidden { display: none !important; }

    .login-page { min-height: 100vh; background: linear-gradient(135deg, #003f46 0%, #006b73 100%); display: flex; align-items: center; justify-content: center; padding: 20px; }
    .login-card { background: #fff; border-radius: var(--radius-lg); padding: 40px 32px; max-width: 420px; width: 100%; box-shadow: 0 20px 60px rgba(0,0,0,0.15); }
    .login-card .logo { text-align: center; margin-bottom: 28px; }
    .login-card .logo-icon { width: 72px; height: 72px; background: var(--primary-gradient); border-radius: var(--radius-lg); display: inline-flex; align-items: center; justify-content: center; color: #fff; font-size: 32px; font-weight: 900; margin-bottom: 12px; box-shadow: 0 8px 32px rgba(0,63,70,0.4); }
    .login-card h2 { font-size: 22px; font-weight: 900; color: #1A2E35; }
    .login-card h2 span { color: var(--primary); }
    .login-card p { color: var(--text-muted); font-size: 13px; margin-top: 4px; }
    .form-group { margin-bottom: 16px; }
    .form-group label { display: block; font-size: 13px; font-weight: 700; color: #4A6A78; margin-bottom: 6px; }
    .form-group input, .form-group select { width: 100%; height: 48px; padding: 0 14px; border: 2px solid rgba(0,63,70,0.08); border-radius: 8px; font-size: 14px; background: var(--bg); color: var(--text-primary); font-family: var(--font-family); outline: none; }
    .form-group input:focus { border-color: var(--primary-light); }
    .btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 46px; padding: 0 18px; border: none; border-radius: 12px; background: var(--primary-gradient); color: #fff; font-size: 14px; font-weight: 700; cursor: pointer; font-family: var(--font-family); box-shadow: 0 4px 16px rgba(0,63,70,0.25); }
    .btn.green { background: var(--green); }
    .btn.red { background: var(--red); }
    .btn.small { height: 36px; padding: 0 14px; font-size: 13px; }
    .btn:disabled { opacity: 0.6; cursor: not-allowed; }
    .login-error { color: #EF4444; font-size: 13px; text-align: center; margin-top: 12px; display: none; }

    header.topbar { background: var(--bg-card); box-shadow: 0 2px 12px rgba(0,0,0,0.05); padding: 14px 24px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 100; }
    header.topbar .brand { display: flex; align-items: center; gap: 10px; font-weight: 900; font-size: 17px; }
    header.topbar .brand .logo { width: 40px; height: 40px; border-radius: 12px; background: var(--primary-gradient); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 18px; }
    header.topbar .role-badge { background: rgba(201,154,61,0.12); color: var(--accent); padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; margin-right: 10px; }

    .container { max-width: 1100px; margin: 0 auto; padding: 24px; }
    .tabs { display: flex; gap: 8px; margin-bottom: 20px; border-bottom: 1px solid #e2ebeb; }
    .tabs button { background: none; border: none; padding: 10px 18px; font-family: var(--font-family); font-size: 14px; font-weight: 700; color: var(--text-muted); cursor: pointer; border-bottom: 3px solid transparent; }
    .tabs button.active { color: var(--primary); border-bottom-color: var(--primary); }

    .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 24px; }
    .stat-card { background: var(--bg-card); border-radius: var(--radius-md); padding: 18px; box-shadow: 0 2px 12px rgba(0,0,0,0.04); }
    .stat-card .label { font-size: 12px; color: var(--text-muted); margin-bottom: 8px; }
    .stat-card .value { font-size: 26px; font-weight: 900; color: var(--primary); }

    .brief-card { background: var(--bg-card); border-radius: var(--radius-md); padding: 16px 18px; margin-bottom: 12px; box-shadow: 0 2px 12px rgba(0,0,0,0.04); border-right: 4px solid var(--orange); }
    .brief-card.history { border-right-color: #ccc; }
    .brief-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
    .brief-top .branch { font-weight: 800; font-size: 15px; }
    .brief-top .date { font-size: 12px; color: var(--text-muted); }
    .brief-details { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 10px; }
    .brief-details .item { text-align: center; background: rgba(0,107,115,0.03); border-radius: 8px; padding: 8px; }
    .brief-details .item .v { font-weight: 800; font-size: 14px; }
    .brief-details .item .l { font-size: 10px; color: var(--text-muted); }
    .brief-note { font-size: 12px; background: rgba(0,107,115,0.04); border-radius: 8px; padding: 8px 10px; margin-bottom: 10px; }
    .brief-actions { display: flex; gap: 8px; align-items: center; }
    .brief-actions input { flex: 1; height: 38px; padding: 0 12px; border: 1.5px solid #e2ebeb; border-radius: 8px; font-family: var(--font-family); font-size: 13px; }
    .status-pill { padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 700; }
    .status-pill.approved { background: rgba(21,148,71,0.12); color: var(--green); }
    .status-pill.rejected { background: rgba(223,75,75,0.12); color: var(--red); }

    .empty-state { text-align: center; padding: 40px 20px; color: var(--text-muted); }
    .empty-state i { font-size: 48px; opacity: 0.3; display: block; margin-bottom: 12px; }

    .branches-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 14px; }
    .branch-card { background: var(--bg-card); border-radius: var(--radius-md); padding: 16px; box-shadow: 0 2px 12px rgba(0,0,0,0.04); }
    .branch-card .name { font-weight: 800; margin-bottom: 6px; }
    .branch-card .muted { font-size: 12px; color: var(--text-muted); }

    .toast-container { position: fixed; top: 20px; left: 50%; transform: translateX(-50%); z-index: 1000; display: flex; flex-direction: column; gap: 10px; align-items: center; pointer-events: none; width: 100%; max-width: 400px; padding: 0 16px; }
    .toast { background: var(--bg-card); border-radius: var(--radius-lg); padding: 14px 18px; box-shadow: 0 12px 56px rgba(0,63,70,0.1); pointer-events: auto; width: 100%; font-family: var(--font-family); display: flex; align-items: flex-start; gap: 12px; opacity: 0; transform: translateY(-80px) scale(0.9); transition: all 0.5s cubic-bezier(0.34,1.56,0.64,1); font-weight: 800; font-size: 13px; cursor: pointer; position: relative; }
    .toast.show { opacity: 1; transform: translateY(0) scale(1); }
    .toast::before { content: ''; position: absolute; top: 0; right: 0; width: 4px; height: 100%; border-radius: 0 4px 4px 0; }
    .toast.success::before { background: var(--green); }
    .toast.error::before { background: var(--red); }
    .toast.warning::before { background: var(--orange); }
    .toast .toast-icon { width: 38px; height: 38px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; }
    .toast.success .toast-icon { background: rgba(21,148,71,0.12); color: var(--green); }
    .toast.error .toast-icon { background: rgba(223,75,75,0.12); color: var(--red); }
    .toast.warning .toast-icon { background: rgba(217,140,26,0.12); color: var(--orange); }
    .toast .toast-content .toast-title { font-size: 13px; font-weight: 800; margin-bottom: 2px; }
    .toast .toast-content .toast-message { font-size: 12px; font-weight: 400; color: var(--text-muted); line-height: 1.5; }

    @media (max-width: 700px) {
        .stats-grid { grid-template-columns: repeat(2, 1fr); }
        .brief-details { grid-template-columns: repeat(2, 1fr); }
    }
</style>
</head>
<body>

    <!-- شاشة الدخول -->
    <div class="login-page" id="loginScreen">
        <div class="login-card">
            <div class="logo">
                <div class="logo-icon">✥</div>
                <h2>المسؤول <span>العام</span></h2>
                <p>الاعتماد النهائي — شركة الصوى للصرافة</p>
            </div>
            <form id="loginForm" onsubmit="handleLogin(event)">
                <div class="form-group"><label>البريد الإلكتروني</label><input type="email" id="loginUsername" required></div>
                <div class="form-group"><label>كلمة المرور</label><input type="password" id="loginPassword" required></div>
                <div class="login-error" id="loginError">بيانات الدخول غير صحيحة</div>
                <button type="submit" class="btn" id="loginBtn" style="width:100%;"><i class="fas fa-arrow-left"></i> تسجيل الدخول</button>
            </form>
        </div>
    </div>

    <!-- التطبيق -->
    <div id="appContainer" class="hidden">
        <header class="topbar">
            <div class="brand"><div class="logo">✥</div> شركة الصوى <span class="role-badge">المسؤول العام</span></div>
            <button class="btn small red" onclick="handleLogout()"><i class="fas fa-sign-out-alt"></i> تسجيل الخروج</button>
        </header>

        <div class="container">
            <div class="stats-grid">
                <div class="stat-card"><div class="label"><i class="fas fa-clock"></i> بانتظار اعتمادك</div><div class="value" id="statPending">0</div></div>
                <div class="stat-card"><div class="label"><i class="fas fa-check-circle"></i> معتمد اليوم</div><div class="value" id="statApprovedToday">0</div></div>
                <div class="stat-card"><div class="label"><i class="fas fa-building"></i> الفروع</div><div class="value" id="statBranches">0</div></div>
                <div class="stat-card"><div class="label"><i class="fas fa-users"></i> الموظفون</div><div class="value" id="statEmployees">0</div></div>
            </div>

            <div class="tabs">
                <button class="active" id="tab-pending" onclick="switchTab('pending')"><i class="fas fa-inbox"></i> بانتظار الاعتماد</button>
                <button id="tab-history" onclick="switchTab('history')"><i class="fas fa-history"></i> سجل الاعتمادات</button>
                <button id="tab-branches" onclick="switchTab('branches')"><i class="fas fa-building"></i> الفروع</button>
            </div>

            <div id="view-pending"></div>
            <div id="view-history" class="hidden"></div>
            <div id="view-branches" class="hidden"></div>
        </div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

<script>
    const loginScreen = document.getElementById('loginScreen');
    const appContainer = document.getElementById('appContainer');
    const alreadyLoggedIn = <?= $isLoggedIn ? 'true' : 'false' ?>;

    function handleLogin(e) {
        e.preventDefault();
        const username = document.getElementById('loginUsername').value;
        const password = document.getElementById('loginPassword').value;
        const btn = document.getElementById('loginBtn');
        const error = document.getElementById('loginError');
        btn.disabled = true;
        error.style.display = 'none';
        fetch('?ajax=login', { method: 'POST', body: new URLSearchParams({ username, password }) })
            .then(r => r.json()).then(data => {
                btn.disabled = false;
                if (data.ok) {
                    loginScreen.classList.add('hidden');
                    appContainer.classList.remove('hidden');
                    initApp();
                } else {
                    error.textContent = data.error || 'بيانات الدخول غير صحيحة';
                    error.style.display = 'block';
                }
            }).catch(() => {
                btn.disabled = false;
                error.textContent = 'تعذر الاتصال بالخادم';
                error.style.display = 'block';
            });
    }

    function handleLogout() {
        if (!confirm('هل أنت متأكد من رغبتك في تسجيل الخروج؟')) return;
        fetch('?ajax=logout', { method: 'POST' }).then(() => {
            appContainer.classList.add('hidden');
            loginScreen.classList.remove('hidden');
        });
    }

    function initApp() {
        loadBootstrap();
        loadPending();
    }

    function loadBootstrap() {
        fetch('?ajax=bootstrap').then(r => r.json()).then(data => {
            if (!data.ok) return;
            document.getElementById('statPending').textContent = data.stats.pending;
            document.getElementById('statApprovedToday').textContent = data.stats.approvedToday;
            document.getElementById('statBranches').textContent = data.stats.branches;
            document.getElementById('statEmployees').textContent = data.stats.employees;
        });
    }

    function switchTab(tab) {
        ['pending', 'history', 'branches'].forEach(t => {
            document.getElementById('tab-' + t).classList.toggle('active', t === tab);
            document.getElementById('view-' + t).classList.toggle('hidden', t !== tab);
        });
        if (tab === 'pending') loadPending();
        else if (tab === 'history') loadHistory();
        else if (tab === 'branches') loadBranches();
    }

    function loadPending() {
        fetch('?ajax=briefs_pending').then(r => r.json()).then(data => {
            if (!data.ok) return;
            const view = document.getElementById('view-pending');
            if (!data.briefs.length) {
                view.innerHTML = '<div class="empty-state"><i class="fas fa-inbox"></i><p>لا توجد إيجازات بانتظار الاعتماد النهائي حالياً</p></div>';
                return;
            }
            view.innerHTML = data.briefs.map(b => `
                <div class="brief-card">
                    <div class="brief-top">
                        <span class="branch"><i class="fas fa-building"></i> ${b.branch}</span>
                        <span class="date">${b.date}</span>
                    </div>
                    <div class="brief-details">
                        <div class="item"><div class="v">${b.revenue.toLocaleString()}</div><div class="l">الإيرادات</div></div>
                        <div class="item"><div class="v">${b.expenses.toLocaleString()}</div><div class="l">المصاريف</div></div>
                        <div class="item"><div class="v">${b.travelersCount}</div><div class="l">المسافرون</div></div>
                        <div class="item"><div class="v" style="color:var(--green);">${b.netProfit.toLocaleString()}</div><div class="l">صافي الربح</div></div>
                    </div>
                    ${b.hrNote ? `<div class="brief-note"><b>ملاحظة HR:</b> ${b.hrNote}</div>` : ''}
                    <div class="brief-actions">
                        <input type="text" id="gmNote_${b.id}" placeholder="ملاحظة الاعتماد النهائي (اختياري)">
                        <button class="btn small green" onclick="finalReview(${b.id}, 'approved')"><i class="fas fa-check"></i> اعتماد نهائي</button>
                        <button class="btn small red" onclick="finalReview(${b.id}, 'rejected')"><i class="fas fa-times"></i> رفض</button>
                    </div>
                </div>
            `).join('');
        });
    }

    function finalReview(id, decision) {
        const note = document.getElementById('gmNote_' + id).value;
        fetch('?ajax=brief_final_review', { method: 'POST', body: new URLSearchParams({ id, decision, note }) })
            .then(r => r.json()).then(data => {
                if (!data.ok) { showToast('⚠️ خطأ', data.error || 'تعذر تنفيذ العملية', 'error'); return; }
                showToast(decision === 'approved' ? '✅ تم الاعتماد النهائي' : '❌ تم الرفض',
                    decision === 'approved' ? 'تم اعتماد الإيجاز نهائياً' : 'تم رفض الإيجاز', decision === 'approved' ? 'success' : 'error');
                loadPending();
                loadBootstrap();
            });
    }

    function loadHistory() {
        fetch('?ajax=briefs_history').then(r => r.json()).then(data => {
            if (!data.ok) return;
            const view = document.getElementById('view-history');
            if (!data.briefs.length) {
                view.innerHTML = '<div class="empty-state"><i class="fas fa-history"></i><p>لا يوجد سجل اعتمادات بعد</p></div>';
                return;
            }
            view.innerHTML = data.briefs.map(b => `
                <div class="brief-card history">
                    <div class="brief-top">
                        <span class="branch"><i class="fas fa-building"></i> ${b.branch}</span>
                        <span class="status-pill ${b.status}">${b.status === 'approved' ? 'معتمد نهائياً' : 'مرفوض'}</span>
                        <span class="date">${b.date}</span>
                    </div>
                    <div class="brief-details">
                        <div class="item"><div class="v">${b.revenue.toLocaleString()}</div><div class="l">الإيرادات</div></div>
                        <div class="item"><div class="v">${b.expenses.toLocaleString()}</div><div class="l">المصاريف</div></div>
                        <div class="item"><div class="v">${b.travelersCount}</div><div class="l">المسافرون</div></div>
                        <div class="item"><div class="v" style="color:var(--green);">${b.netProfit.toLocaleString()}</div><div class="l">صافي الربح</div></div>
                    </div>
                    ${b.gmNote ? `<div class="brief-note"><b>ملاحظتك:</b> ${b.gmNote}</div>` : ''}
                </div>
            `).join('');
        });
    }

    function loadBranches() {
        fetch('?ajax=branches_overview').then(r => r.json()).then(data => {
            if (!data.ok) return;
            const view = document.getElementById('view-branches');
            view.innerHTML = '<div class="branches-grid">' + data.branches.map(b => `
                <div class="branch-card">
                    <div class="name"><i class="fas fa-building" style="color:var(--primary);"></i> ${b.name}</div>
                    <div class="muted">المدير: ${b.manager || '—'}</div>
                    <div class="muted">عدد الموظفين: ${b.employeeCount}</div>
                </div>
            `).join('') + '</div>';
        });
    }

    let toastId = 0;
    function showToast(title, message, type = 'info', duration = 3500) {
        const container = document.getElementById('toastContainer');
        const id = ++toastId;
        const icons = { success: 'fas fa-check-circle', error: 'fas fa-times-circle', warning: 'fas fa-exclamation-triangle', info: 'fas fa-info-circle' };
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.id = `toast-${id}`;
        toast.innerHTML = `<div class="toast-icon"><i class="${icons[type] || icons.info}"></i></div><div class="toast-content"><div class="toast-title">${title}</div><div class="toast-message">${message}</div></div>`;
        container.appendChild(toast);
        requestAnimationFrame(() => toast.classList.add('show'));
        setTimeout(() => { toast.remove(); }, duration);
        toast.addEventListener('click', () => toast.remove());
    }

    document.addEventListener('DOMContentLoaded', function() {
        if (alreadyLoggedIn) {
            loginScreen.classList.add('hidden');
            appContainer.classList.remove('hidden');
            initApp();
        }
    });
</script>
</body>
</html>
