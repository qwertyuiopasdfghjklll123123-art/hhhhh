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

$sessionDir = __DIR__ . '/uploads/sessions';
if (!is_dir($sessionDir)) {
    @mkdir($sessionDir, 0755, true);
    @file_put_contents($sessionDir . '/.htaccess', "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
}
if (is_dir($sessionDir) && is_writable($sessionDir)) {
    session_save_path($sessionDir);
}
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

function attendance_status_ar(string $s): string
{
    return ['present' => 'حاضر', 'late' => 'متأخر', 'absent' => 'غائب'][$s] ?? $s;
}

function brief_overall_status(string $hrDecision, string $gmDecision): string
{
    if ($hrDecision === 'rejected' || $gmDecision === 'rejected') return 'rejected';
    if ($hrDecision === 'approved' && $gmDecision === 'approved') return 'approved';
    if ($hrDecision === 'approved') return 'hr_approved';
    if ($gmDecision === 'approved') return 'gm_approved';
    return 'pending';
}

function log_error(PDO $pdo, string $action, ?string $role, ?int $userId, string $message): void
{
    try {
        $pdo->prepare("INSERT INTO error_log (app, action, user_role, user_id, message) VALUES ('general', ?, ?, ?, ?)")
            ->execute([$action, $role, $userId, mb_substr($message, 0, 500)]);
    } catch (Throwable $e) {
        // جدول error_log قد لا يكون موجوداً بعد على قاعدة بيانات لم تُحدَّث — تجاهل بصمت
    }
}

function gm_report_data(PDO $pdo, string $type, string $from, string $to, int $branch): array
{
    $result = [];

    if ($type === 'attendance' || $type === 'all') {
        $sql = "SELECT e.full_name AS name, b.name AS branch, a.check_in AS checkIn, a.check_out AS checkOut, a.status
                FROM attendance a JOIN employees e ON e.id=a.employee_id JOIN branches b ON b.id=a.branch_id
                WHERE a.attendance_date BETWEEN ? AND ?";
        $params = [$from, $to];
        if ($branch > 0) { $sql .= " AND a.branch_id = ?"; $params[] = $branch; }
        $sql .= " ORDER BY a.attendance_date DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result['attendance'] = array_map(function ($r) {
            $r['checkIn'] = $r['checkIn'] ? substr($r['checkIn'], 0, 5) : '--:--';
            $r['checkOut'] = $r['checkOut'] ? substr($r['checkOut'], 0, 5) : '--:--';
            $r['status'] = attendance_status_ar($r['status']);
            return $r;
        }, $stmt->fetchAll());
    }

    if ($type === 'salaries' || $type === 'all') {
        $sql = "SELECT e.full_name AS name, b.name AS branch, p.base_salary AS base, p.bonus, p.deduction,
                       (p.base_salary + p.bonus - p.deduction) AS net, p.status
                FROM payroll p JOIN employees e ON e.id=p.employee_id JOIN branches b ON b.id=p.branch_id
                WHERE DATE(p.created_at) BETWEEN ? AND ?";
        $params = [$from, $to];
        if ($branch > 0) { $sql .= " AND p.branch_id = ?"; $params[] = $branch; }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result['salaries'] = array_map(function ($r) {
            foreach (['base', 'bonus', 'deduction', 'net'] as $k) $r[$k] = number_format((float) $r[$k]);
            $r['status'] = $r['status'] === 'delivered' ? 'مدفوع' : 'قيد المعالجة';
            return $r;
        }, $stmt->fetchAll());
    }

    if ($type === 'briefing' || $type === 'all') {
        $sql = "SELECT b.name AS branch, DATE_FORMAT(db.brief_date,'%d/%m/%Y') AS date,
                       db.total_income AS revenue, db.total_expense AS expense, db.travelers_count AS travelers,
                       (db.total_income - db.total_expense) AS profit, db.status,
                       db.hr_note AS hrNote, db.gm_review_note AS gmNote,
                       COALESCE(se.full_name, 'مدير الفرع') AS sender
                FROM daily_briefs db JOIN branches b ON b.id = db.branch_id
                LEFT JOIN employees se ON se.id = db.submitted_by
                WHERE db.brief_date BETWEEN ? AND ?";
        $params = [$from, $to];
        if ($branch > 0) { $sql .= " AND db.branch_id = ?"; $params[] = $branch; }
        $sql .= " ORDER BY db.brief_date DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $briefStatusAr = [
            'pending' => 'بانتظار مراجعة HR والمسؤول العام',
            'hr_approved' => 'وافقت HR — بانتظار المسؤول العام',
            'gm_approved' => 'وافق المسؤول العام — بانتظار HR',
            'approved' => 'معتمد نهائياً (موافق عليه من الاثنين)',
            'rejected' => 'مرفوض',
        ];
        $result['briefing'] = array_map(function ($r) use ($briefStatusAr) {
            foreach (['revenue', 'expense', 'profit'] as $k) $r[$k] = number_format((float) $r[$k]);
            $r['hrNote'] = $r['hrNote'] ?: '-';
            $r['gmNote'] = $r['gmNote'] ?: '-';
            $r['statusText'] = $briefStatusAr[$r['status']] ?? $r['status'];
            return $r;
        }, $stmt->fetchAll());
    }

    return $result;
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
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND role IN ('general_manager','shareholder') AND status = 'active' LIMIT 1");
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        if ($row && password_verify($password, $row['password_hash'])) {
            $_SESSION['gm_user'] = [
                'id' => (int) $row['id'],
                'username' => $row['username'],
                'role' => $row['role'],
                'displayName' => $row['display_name'],
            ];
            echo json_encode(['ok' => true, 'role' => $row['role']]);
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
    $isShareholder = $gmUser['role'] === 'shareholder';
    $gmOnlyActions = ['brief_final_review', 'payroll_window_open', 'payroll_window_close', 'shareholders_list', 'shareholder_create', 'shareholder_toggle', 'payroll_adjustment_add'];
    if ($isShareholder && in_array($action, $gmOnlyActions, true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'هذه الصلاحية متاحة للمسؤول العام فقط']);
        exit;
    }

    if ($action === 'log_error') {
        $clientMsg = trim((string) ($_POST['message'] ?? ''));
        $clientAction = trim((string) ($_POST['clientAction'] ?? 'client'));
        if ($clientMsg !== '') {
            log_error($pdo, $clientAction, $gmUser['role'], $gmUser['id'], $clientMsg);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    try {
    switch ($action) {

        case 'bootstrap': {
            $pending = (int) $pdo->query("SELECT COUNT(*) FROM daily_briefs WHERE status='hr_approved'")->fetchColumn();
            $approvedToday = (int) $pdo->query("SELECT COUNT(*) FROM daily_briefs WHERE status='approved' AND brief_date=CURDATE()")->fetchColumn();
            $branches = (int) $pdo->query("SELECT COUNT(*) FROM branches WHERE status='active'")->fetchColumn();
            $employees = (int) $pdo->query("SELECT COUNT(*) FROM employees WHERE status='active'")->fetchColumn();
            $month = (int) date('n');
            $year = (int) date('Y');
            $branchWinStmt = $pdo->prepare("
                SELECT b.id, b.name, pw.expires_at
                FROM branches b
                LEFT JOIN payroll_windows pw ON pw.branch_id = b.id AND pw.period_month = ? AND pw.period_year = ? AND pw.expires_at > NOW()
                WHERE b.status = 'active'
                ORDER BY b.name
            ");
            $branchWinStmt->execute([$month, $year]);
            $payrollBranches = array_map(function ($r) {
                return [
                    'id' => (int) $r['id'],
                    'name' => $r['name'],
                    'open' => $r['expires_at'] !== null,
                    'expiresAt' => $r['expires_at'],
                ];
            }, $branchWinStmt->fetchAll());
            $settingsRow = $pdo->query("SELECT company_name, company_logo FROM settings ORDER BY id DESC LIMIT 1")->fetch();

            echo json_encode([
                'ok' => true,
                'username' => $gmUser['username'],
                'company' => [
                    'name' => $settingsRow['company_name'] ?: 'شركة الصوى للصرافة',
                    'logo' => $settingsRow['company_logo'] ?: null,
                ],
                'stats' => [
                    'pending' => $pending,
                    'approvedToday' => $approvedToday,
                    'branches' => $branches,
                    'employees' => $employees,
                ],
                'payrollBranches' => $payrollBranches,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'notifications_list': {
            $stmt = $pdo->prepare("SELECT id, title, message, is_read, DATE_FORMAT(created_at,'%d/%m/%Y %H:%i') AS date FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 30");
            $stmt->execute([$gmUser['id']]);
            $rows = $stmt->fetchAll();
            $unread = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
            $unread->execute([$gmUser['id']]);
            echo json_encode(['ok' => true, 'notifications' => $rows, 'unread' => (int) $unread->fetchColumn()], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'notifications_mark_all_read': {
            $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$gmUser['id']]);
            echo json_encode(['ok' => true]);
            exit;
        }

        case 'payroll_window_open': {
            $branchIds = array_filter(array_map('intval', $_POST['branchIds'] ?? []), fn($id) => $id > 0);
            if (empty($branchIds)) {
                echo json_encode(['ok' => false, 'error' => 'الرجاء اختيار فرع واحد على الأقل']);
                exit;
            }
            $month = (int) date('n');
            $year = (int) date('Y');
            $expiresAt = date('Y-m-d H:i:s', strtotime('+3 days'));
            $winStmt = $pdo->prepare("INSERT INTO payroll_windows (branch_id, period_month, period_year, opened_by, expires_at) VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE opened_by=VALUES(opened_by), opened_at=NOW(), expires_at=VALUES(expires_at)");
            $nameStmt = $pdo->prepare("SELECT name FROM branches WHERE id=?");
            $uidStmt = $pdo->prepare("SELECT id FROM users WHERE (branch_id=? AND role='branch_manager') OR role='hr'");
            foreach ($branchIds as $branchId) {
                $winStmt->execute([$branchId, $month, $year, $gmUser['id'], $expiresAt]);
                $nameStmt->execute([$branchId]);
                $branchName = $nameStmt->fetchColumn() ?: 'الفرع';
                $msg = 'فتح المسؤول العام صلاحية تسليم الرواتب لفرع ' . $branchName . ' لشهر ' . $month . '/' . $year . ' لمدة 3 أيام';
                $uidStmt->execute([$branchId]);
                foreach ($uidStmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                    $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, 'صلاحية تسليم الرواتب مفتوحة', ?)")->execute([$uid, $msg]);
                }
            }

            echo json_encode(['ok' => true, 'expiresAt' => $expiresAt]);
            exit;
        }

        case 'payroll_window_close': {
            $branchId = (int) ($_POST['branchId'] ?? 0);
            if ($branchId <= 0) {
                echo json_encode(['ok' => false, 'error' => 'فرع غير صالح']);
                exit;
            }
            $month = (int) date('n');
            $year = (int) date('Y');
            $pdo->prepare("DELETE FROM payroll_windows WHERE branch_id=? AND period_month=? AND period_year=?")
                ->execute([$branchId, $month, $year]);

            $nameStmt = $pdo->prepare("SELECT name FROM branches WHERE id=?");
            $nameStmt->execute([$branchId]);
            $branchName = $nameStmt->fetchColumn() ?: 'الفرع';
            $uidStmt = $pdo->prepare("SELECT id FROM users WHERE branch_id=? AND role='branch_manager'");
            $uidStmt->execute([$branchId]);
            foreach ($uidStmt->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, 'أُلغيت صلاحية تسليم الرواتب', ?)")
                    ->execute([$uid, 'ألغى المسؤول العام صلاحية تسليم رواتب فرع ' . $branchName . ' لهذا الشهر']);
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        case 'shareholders_list': {
            $rows = $pdo->query("SELECT id, username, display_name, status, created_at FROM users WHERE role='shareholder' ORDER BY created_at DESC")->fetchAll();
            echo json_encode(['ok' => true, 'shareholders' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'shareholder_create': {
            $name = trim($_POST['name'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $password = (string) ($_POST['password'] ?? '');
            if ($name === '' || $username === '' || strlen($password) < 6) {
                echo json_encode(['ok' => false, 'error' => 'الرجاء تعبئة الاسم والبريد الإلكتروني (كلمة المرور 6 أحرف على الأقل)']);
                exit;
            }
            if (!filter_var($username, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['ok' => false, 'error' => 'الرجاء إدخال بريد إلكتروني صحيح لتسجيل الدخول']);
                exit;
            }
            try {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO users (role, username, password_hash, display_name, status) VALUES ('shareholder', ?, ?, ?, 'active')")
                    ->execute([$username, $hash, $name]);
                echo json_encode(['ok' => true]);
            } catch (Throwable $ex) {
                echo json_encode(['ok' => false, 'error' => 'اسم الدخول مستخدم مسبقاً']);
            }
            exit;
        }

        case 'shareholder_toggle': {
            $id = (int) ($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE users SET status = IF(status='active','inactive','active') WHERE id=? AND role='shareholder'")->execute([$id]);
            echo json_encode(['ok' => true]);
            exit;
        }

        case 'briefs_pending': {
            $stmt = $pdo->query("
                SELECT db.id, db.branch_id, b.name AS branch, DATE_FORMAT(db.brief_date, '%d/%m/%Y') AS date, db.brief_date AS rawDate,
                       db.total_income AS revenue, db.total_expense AS expenses, db.travelers_count AS travelersCount,
                       db.note, db.attachment, db.hr_note AS hrNote, db.status, db.hr_decision, db.gm_decision
                FROM daily_briefs db JOIN branches b ON b.id = db.branch_id
                WHERE db.gm_decision = 'pending' AND db.status NOT IN ('approved','rejected')
                ORDER BY db.brief_date DESC, db.id DESC
            ");
            $entriesStmt = $pdo->prepare("SELECT id, entry_type, amount, description, attachment FROM daily_ledger WHERE branch_id=? AND entry_date=? ORDER BY created_at ASC");
            $prevStmt = $pdo->prepare("SELECT total_income, total_expense FROM daily_briefs WHERE branch_id=? AND brief_date=?");
            $rows = array_map(function ($r) use ($entriesStmt, $prevStmt) {
                $r['revenue'] = (float) $r['revenue'];
                $r['expenses'] = (float) $r['expenses'];
                $r['travelersCount'] = (int) $r['travelersCount'];
                $r['netProfit'] = $r['revenue'] - $r['expenses'];
                $r['hrStatusText'] = $r['hr_decision'] === 'approved' ? 'وافقت عليه الموارد البشرية' : 'بانتظار مراجعة الموارد البشرية أيضاً';
                $entriesStmt->execute([$r['branch_id'], $r['rawDate']]);
                $r['entries'] = array_map(fn($e) => ['id' => (int) $e['id'], 'type' => $e['entry_type'], 'amount' => (float) $e['amount'], 'note' => $e['description'], 'attachment' => $e['attachment']], $entriesStmt->fetchAll());
                $prevStmt->execute([$r['branch_id'], date('Y-m-d', strtotime($r['rawDate'] . ' -1 day'))]);
                $prevRow = $prevStmt->fetch();
                $r['prevDayNetProfit'] = $prevRow ? ((float) $prevRow['total_income'] - (float) $prevRow['total_expense']) : null;
                return $r;
            }, $stmt->fetchAll());
            echo json_encode(['ok' => true, 'briefs' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'briefs_history': {
            $stmt = $pdo->query("
                SELECT db.id, b.name AS branch, DATE_FORMAT(db.brief_date, '%d/%m/%Y') AS date,
                       db.total_income AS revenue, db.total_expense AS expenses, db.travelers_count AS travelersCount,
                       db.status, db.gm_review_note AS gmNote, db.hr_note AS hrNote
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

            $briefRow = $pdo->prepare("SELECT branch_id, hr_decision, submitted_by FROM daily_briefs WHERE id=? AND gm_decision='pending' AND status NOT IN ('approved','rejected')");
            $briefRow->execute([$id]);
            $brief = $briefRow->fetch();
            if (!$brief) {
                echo json_encode(['ok' => false, 'error' => 'هذا الإيجاز ليس بانتظار اعتمادك']);
                exit;
            }
            $newStatus = brief_overall_status($brief['hr_decision'], $decision);

            $pdo->prepare("UPDATE daily_briefs SET gm_decision=?, status=?, gm_review_note=?, gm_reviewed_by=?, gm_reviewed_at=NOW() WHERE id=?")
                ->execute([$decision, $newStatus, $note, $gmUser['id'], $id]);

            $briefBranchId = $brief['branch_id'];
            if ($newStatus === 'approved') {
                $msg = 'تم اعتماد إيجاز اليوم نهائياً بعد موافقة المسؤول العام والموارد البشرية' . ($note ? (' — ' . $note) : '');
            } elseif ($newStatus === 'rejected') {
                $msg = 'رفض المسؤول العام إيجاز اليوم' . ($note ? (' — ' . $note) : '');
            } else {
                $msg = 'وافق المسؤول العام على إيجاز اليوم، بانتظار موافقة الموارد البشرية أيضاً' . ($note ? (' — ' . $note) : '');
            }
            $notifyUids = $pdo->prepare("SELECT id FROM users WHERE branch_id=? AND role='branch_manager' UNION SELECT id FROM users WHERE role='hr' UNION SELECT id FROM users WHERE employee_id=? AND role='employee'");
            $notifyUids->execute([$briefBranchId, $brief['submitted_by']]);
            foreach ($notifyUids->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, 'اعتماد نهائي للإيجاز', ?)")->execute([$uid, $msg]);
            }

            echo json_encode(['ok' => true]);
            exit;
        }

        case 'employees_list': {
            $rows = $pdo->query("
                SELECT e.id, e.full_name AS name, b.name AS branch
                FROM employees e JOIN branches b ON b.id = e.branch_id
                WHERE e.status='active' ORDER BY e.full_name
            ")->fetchAll();
            echo json_encode(['ok' => true, 'employees' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'payroll_overview': {
            $month = (int) date('n');
            $year = (int) date('Y');
            $stmt = $pdo->prepare("
                SELECT e.id AS employeeId, e.full_name AS name, b.name AS branch, e.is_branch_manager AS isManager,
                       COALESCE(p.base_salary, e.base_salary) AS base, COALESCE(p.bonus,0) AS bonus, COALESCE(p.deduction,0) AS deduction,
                       (COALESCE(p.base_salary, e.base_salary) + COALESCE(p.bonus,0) - COALESCE(p.deduction,0)) AS net,
                       COALESCE(p.status, 'pending') AS status,
                       adv.approved_monthly_deduction AS advanceMonthly, adv.remaining_balance AS advanceRemaining
                FROM employees e
                JOIN branches b ON b.id = e.branch_id
                LEFT JOIN payroll p ON p.employee_id = e.id AND p.period_month=? AND p.period_year=?
                LEFT JOIN requests adv ON adv.employee_id = e.id AND adv.type='advance' AND adv.status='approved' AND adv.remaining_balance > 0
                WHERE e.status='active'
                ORDER BY (COALESCE(p.status, 'pending') = 'delivered') ASC, e.full_name
            ");
            $stmt->execute([$month, $year]);
            $rows = array_map(function ($r) {
                $r['base'] = (float) $r['base'];
                $r['bonus'] = (float) $r['bonus'];
                $r['deduction'] = (float) $r['deduction'];
                $r['net'] = (float) $r['net'];
                $r['hasAdvance'] = $r['advanceMonthly'] !== null;
                $r['advanceMonthly'] = $r['advanceMonthly'] !== null ? (float) $r['advanceMonthly'] : null;
                $r['statusRaw'] = $r['status'];
                $r['status'] = $r['status'] === 'delivered' ? 'مدفوع' : 'قيد المعالجة';
                return $r;
            }, $stmt->fetchAll());
            echo json_encode(['ok' => true, 'salaries' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'payroll_adjustment_add': {
            $employeeId = (int) ($_POST['employeeId'] ?? 0);
            $type = $_POST['type'] ?? '';
            $amount = (float) ($_POST['amount'] ?? 0);
            $note = trim($_POST['note'] ?? '');
            if (!in_array($type, ['salary', 'bonus', 'deduction'], true) || $amount <= 0 || $employeeId <= 0) {
                echo json_encode(['ok' => false, 'error' => 'الرجاء اختيار الموظف ونوع التعديل وإدخال مبلغ صحيح']);
                exit;
            }
            $empStmt = $pdo->prepare("SELECT branch_id, full_name FROM employees WHERE id=?");
            $empStmt->execute([$employeeId]);
            $emp = $empStmt->fetch();
            if (!$emp) {
                echo json_encode(['ok' => false, 'error' => 'الموظف غير موجود']);
                exit;
            }
            $month = (int) date('n');
            $year = (int) date('Y');
            $branchId = (int) $emp['branch_id'];

            $existing = $pdo->prepare("SELECT id, status FROM payroll WHERE employee_id=? AND period_month=? AND period_year=?");
            $existing->execute([$employeeId, $month, $year]);
            $existing = $existing->fetch();
            if ($existing && $existing['status'] === 'delivered') {
                echo json_encode(['ok' => false, 'error' => 'تم تسليم راتب هذا الشهر مسبقاً، لا يمكن تعديله']);
                exit;
            }

            if ($type === 'salary') {
                $pdo->prepare("INSERT INTO payroll (employee_id, branch_id, period_month, period_year, base_salary, status)
                    VALUES (?, ?, ?, ?, ?, 'pending') ON DUPLICATE KEY UPDATE base_salary=?")
                    ->execute([$employeeId, $branchId, $month, $year, $amount, $amount]);
            } elseif ($type === 'bonus') {
                $pdo->prepare("INSERT INTO payroll (employee_id, branch_id, period_month, period_year, base_salary, bonus, status)
                    VALUES (?, ?, ?, ?, (SELECT base_salary FROM employees WHERE id=?), ?, 'pending')
                    ON DUPLICATE KEY UPDATE bonus = bonus + VALUES(bonus)")
                    ->execute([$employeeId, $branchId, $month, $year, $employeeId, $amount]);
            } else {
                $pdo->prepare("INSERT INTO payroll (employee_id, branch_id, period_month, period_year, base_salary, deduction, status)
                    VALUES (?, ?, ?, ?, (SELECT base_salary FROM employees WHERE id=?), ?, 'pending')
                    ON DUPLICATE KEY UPDATE deduction = deduction + VALUES(deduction)")
                    ->execute([$employeeId, $branchId, $month, $year, $employeeId, $amount]);
            }

            $pdo->prepare("INSERT INTO payroll_adjustments (employee_id, branch_id, period_month, period_year, type, amount, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$employeeId, $branchId, $month, $year, $type, $amount, $note ?: null, $gmUser['id']]);

            $typeLabel = ['salary' => 'تحديث الراتب الأساسي', 'bonus' => 'مكافأة', 'deduction' => 'خصم'];
            $msg = $typeLabel[$type] . ' بقيمة ' . number_format($amount) . ' للموظف ' . $emp['full_name'] . ' من المسؤول العام';
            $notifyUsers = $pdo->prepare("SELECT id FROM users WHERE employee_id=? UNION SELECT id FROM users WHERE branch_id=? AND role='branch_manager' UNION SELECT id FROM users WHERE role='hr'");
            $notifyUsers->execute([$employeeId, $branchId]);
            foreach ($notifyUsers->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, 'تحديث في الراتب', ?)")->execute([$uid, $msg]);
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

        case 'branch_detail': {
            $branchId = (int) ($_GET['id'] ?? 0);
            $date = $_GET['date'] ?? '';
            if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = '';

            $branchStmt = $pdo->prepare("
                SELECT b.id, b.name, b.status, e.full_name AS manager, e.shift_start AS shiftStart, e.shift_end AS shiftEnd
                FROM branches b
                LEFT JOIN employees e ON e.branch_id = b.id AND e.is_branch_manager = 1
                WHERE b.id = ?
            ");
            $branchStmt->execute([$branchId]);
            $branch = $branchStmt->fetch();
            if (!$branch) {
                echo json_encode(['ok' => false, 'error' => 'الفرع غير موجود']);
                exit;
            }
            $branch['shiftStart'] = $branch['shiftStart'] ? substr($branch['shiftStart'], 0, 5) : null;
            $branch['shiftEnd'] = $branch['shiftEnd'] ? substr($branch['shiftEnd'], 0, 5) : null;

            $empStmt = $pdo->prepare("
                SELECT e.id, e.full_name AS name, e.job_title, e.shift_type, e.is_branch_manager AS isManager,
                       ROUND(SUM(a.status IN ('present','late')) / GREATEST(COUNT(a.id),1) * 100) AS attendanceRate
                FROM employees e
                LEFT JOIN attendance a ON a.employee_id = e.id AND a.attendance_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                WHERE e.branch_id = ? AND e.status = 'active'
                GROUP BY e.id, e.full_name, e.job_title, e.shift_type, e.is_branch_manager
                ORDER BY e.is_branch_manager DESC, e.full_name
            ");
            $empStmt->execute([$branchId]);
            $employees = array_map(function ($r) {
                $r['isManager'] = (bool) $r['isManager'];
                $r['attendanceRate'] = $r['attendanceRate'] !== null ? (int) $r['attendanceRate'] : 0;
                $r['shiftTypeText'] = $r['shift_type'] === 'evening' ? 'مسائي' : 'صباحي';
                return $r;
            }, $empStmt->fetchAll());

            $overallRate = 0;
            if ($employees) {
                $rates = array_column($employees, 'attendanceRate');
                $overallRate = (int) round(array_sum($rates) / count($rates));
            }

            $sql = "
                SELECT db.id, DATE_FORMAT(db.brief_date,'%d/%m/%Y') AS date, db.brief_date AS rawDate,
                       db.total_income AS revenue, db.total_expense AS expense, db.travelers_count AS travelers,
                       db.status, db.note, db.attachment, db.hr_note AS hrNote, db.gm_review_note AS gmNote,
                       COALESCE(se.full_name, 'مدير الفرع') AS sender
                FROM daily_briefs db
                LEFT JOIN employees se ON se.id = db.submitted_by
                WHERE db.branch_id = ?";
            $params = [$branchId];
            if ($date !== '') { $sql .= " AND db.brief_date = ?"; $params[] = $date; }
            $sql .= " ORDER BY db.brief_date DESC LIMIT 60";
            $briefsStmt = $pdo->prepare($sql);
            $briefsStmt->execute($params);
            $statusAr = [
                'pending' => 'بانتظار المراجعة', 'hr_approved' => 'وافقت HR', 'gm_approved' => 'وافقت أنت',
                'approved' => 'معتمد نهائياً', 'rejected' => 'مرفوض',
            ];
            $today = date('Y-m-d');
            $gmEntriesStmt = $pdo->prepare("SELECT id, entry_type, amount, description, attachment FROM daily_ledger WHERE branch_id=? AND entry_date=? ORDER BY created_at ASC");
            $gmPrevStmt = $pdo->prepare("SELECT total_income, total_expense FROM daily_briefs WHERE branch_id=? AND brief_date=?");
            $briefs = array_map(function ($r) use ($statusAr, $today, $branchId, $gmEntriesStmt, $gmPrevStmt) {
                $r['revenue'] = (float) $r['revenue'];
                $r['expense'] = (float) $r['expense'];
                $r['profit'] = $r['revenue'] - $r['expense'];
                $r['statusText'] = $statusAr[$r['status']] ?? $r['status'];
                $r['isToday'] = $r['rawDate'] === $today;
                $gmEntriesStmt->execute([$branchId, $r['rawDate']]);
                $r['entries'] = array_map(fn($e) => ['id' => (int) $e['id'], 'type' => $e['entry_type'], 'amount' => (float) $e['amount'], 'note' => $e['description'], 'attachment' => $e['attachment']], $gmEntriesStmt->fetchAll());
                $gmPrevStmt->execute([$branchId, date('Y-m-d', strtotime($r['rawDate'] . ' -1 day'))]);
                $gmPrevRow = $gmPrevStmt->fetch();
                $r['prevDayNetProfit'] = $gmPrevRow ? ((float) $gmPrevRow['total_income'] - (float) $gmPrevRow['total_expense']) : null;
                return $r;
            }, $briefsStmt->fetchAll());

            echo json_encode([
                'ok' => true,
                'branch' => $branch,
                'employees' => $employees,
                'overallAttendanceRate' => $overallRate,
                'briefs' => $briefs,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'report': {
            $type = $_GET['type'] ?? 'attendance';
            $from = $_GET['from'] ?? date('Y-m-01');
            $to = $_GET['to'] ?? date('Y-m-d');
            $branch = (int) ($_GET['branch'] ?? 0);
            echo json_encode(['ok' => true] + gm_report_data($pdo, $type, $from, $to, $branch), JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'report_download': {
            $type = $_GET['type'] ?? 'attendance';
            $from = $_GET['from'] ?? date('Y-m-01');
            $to = $_GET['to'] ?? date('Y-m-d');
            $branch = (int) ($_GET['branch'] ?? 0);
            $data = gm_report_data($pdo, $type, $from, $to, $branch);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="report_' . $type . '_' . date('Ymd_His') . '.csv"');
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');
            foreach ($data as $section => $rows) {
                if (empty($rows)) continue;
                fputcsv($out, [$section]);
                fputcsv($out, array_keys($rows[0]));
                foreach ($rows as $row) fputcsv($out, $row);
                fputcsv($out, []);
            }
            fclose($out);
            exit;
        }
    }

    echo json_encode(['ok' => false, 'error' => 'unknown action']);
    exit;
    } catch (Throwable $ex) {
        log_error($pdo, $action, $gmUser['role'], $gmUser['id'], $ex->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'حدث خطأ غير متوقع في الخادم — تأكد من تشغيل migrate.php على قاعدة البيانات']);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>شركة الصوى للصرافة - المسؤول العام</title>
<link rel="manifest" href="manifest.php?app=general">
<meta name="theme-color" content="#006b73">
<link rel="apple-touch-icon" href="icons/icon-192.png">
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
        --radius-full: 9999px;
        --font-family: 'IBM Plex Sans Arabic', 'Tajawal', sans-serif;
        --transition-base: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        --sidebar-width: 260px;
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

    /* ============================================================
       الشريط الجانبي والمحتوى الرئيسي (بنفس شكل نظام الموارد البشرية)
       ============================================================ */
    #appContainer { display: flex; width: 100%; }
    .sidebar {
        width: var(--sidebar-width);
        height: 100vh;
        background: linear-gradient(180deg, #4B5320 0%, #3A4019 100%);
        border-left: 1px solid rgba(0,0,0,0.08);
        box-shadow: 2px 0 20px rgba(0,0,0,0.08);
        position: fixed;
        right: 0;
        top: 0;
        z-index: 100;
        display: flex;
        flex-direction: column;
        padding: 20px 16px;
        overflow-y: auto;
        transition: var(--transition-base);
    }
    .sidebar .brand { display: flex; align-items: center; gap: 12px; padding-bottom: 20px; border-bottom: 1px solid rgba(255,255,255,0.1); margin-bottom: 16px; }
    .sidebar .brand .logo { width: 44px; height: 44px; border-radius: var(--radius-md); background: rgba(255,255,255,0.14); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 20px; font-weight: 900; flex-shrink: 0; }
    .sidebar .brand .name { font-size: 18px; font-weight: 900; color: #fff; }
    .sidebar .brand .name span { color: #C9D18F; }
    .sidebar .brand .version { font-size: 9px; color: rgba(255,255,255,0.55); font-weight: 400; display: block; }
    .sidebar .nav-menu { flex: 1; display: flex; flex-direction: column; gap: 2px; }
    .sidebar .nav-item { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-radius: var(--radius-md); cursor: pointer; transition: var(--transition-base); color: rgba(255,255,255,0.75); font-weight: 600; font-size: 13px; border: none; background: transparent; width: 100%; font-family: var(--font-family); text-align: right; }
    .sidebar .nav-item:hover { background: rgba(255,255,255,0.08); color: #fff; }
    .sidebar .nav-item.active { background: rgba(255,255,255,0.16); color: #fff; }
    .sidebar .nav-item i { width: 20px; font-size: 16px; text-align: center; flex-shrink: 0; }
    .sidebar .nav-divider { height: 1px; background: rgba(255,255,255,0.1); margin: 8px 0; }
    .sidebar .user-info { padding-top: 16px; border-top: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; gap: 12px; }
    .sidebar .user-info .avatar { width: 44px; height: 44px; border-radius: var(--radius-full); background: rgba(255,255,255,0.14); display: flex; align-items: center; justify-content: center; color: #fff; font-size: 18px; font-weight: 900; flex-shrink: 0; }
    .sidebar .user-info .info .name { font-size: 14px; font-weight: 800; color: #fff; }
    .sidebar .user-info .info .role { font-size: 11px; color: rgba(255,255,255,0.6); font-weight: 400; }
    .sidebar .user-info .logout-btn { margin-right: auto; background: none; border: none; color: rgba(255,255,255,0.6); cursor: pointer; font-size: 18px; transition: var(--transition-base); padding: 4px 8px; border-radius: var(--radius-full); }
    .sidebar .user-info .logout-btn:hover { color: #EF4444; background: rgba(239,68,68,0.08); }

    .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.3); z-index: 99; }
    .sidebar-overlay.show { display: block; }

    .main-content { flex: 1; margin-right: var(--sidebar-width); padding: 24px 32px 40px; min-height: 100vh; }
    .top-header { display: flex; align-items: center; justify-content: space-between; padding-bottom: 20px; border-bottom: 1px solid rgba(0,107,115,0.04); margin-bottom: 24px; flex-wrap: wrap; gap: 12px; }
    .top-header .page-title { display: flex; align-items: center; gap: 10px; }
    .top-header .page-title h2 { font-size: 22px; font-weight: 800; color: var(--text-primary); display: flex; align-items: center; gap: 10px; }
    .top-header .page-title h2 i { color: var(--primary); }
    .top-header .page-title .sub { font-size: 13px; color: var(--text-muted); font-weight: 400; display: block; }
    .top-header .header-actions { display: flex; align-items: center; gap: 12px; }
    .top-header .header-actions .date-display { font-size: 13px; color: var(--text-primary); font-weight: 500; background: var(--bg-card); padding: 8px 16px; border-radius: var(--radius-md); border: 1px solid rgba(0,107,115,0.04); box-shadow: 0 2px 8px rgba(0,107,115,0.04); }
    .top-header .header-actions .date-display i { color: var(--primary); margin-left: 6px; }

    .mobile-menu-toggle { display: none; background: none; border: none; font-size: 24px; color: var(--text-primary); cursor: pointer; padding: 4px 8px; }

    @media (max-width: 768px) {
        :root { --sidebar-width: 0px; }
        .sidebar { transform: translateX(100%); width: 280px; }
        .sidebar.open { transform: translateX(0); }
        .main-content { margin-right: 0; padding: 16px; }
        .top-header .page-title h2 { font-size: 18px; }
        .mobile-menu-toggle { display: flex !important; }
    }

    .container { max-width: 1100px; margin: 0 auto; }

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
    .status-pill.pending { background: rgba(217,140,26,0.12); color: var(--orange); }

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

    /* طباعة التقرير كـ PDF */
    #reportPrintHeader { display: none; }
    @media print {
        body { background: #fff !important; }
        body * { visibility: hidden; }
        #reportResult, #reportResult * { visibility: visible; }
        #reportResult { width: 100%; box-shadow: none; border: none; }
        #reportPrintHeader, #reportPrintHeader * { visibility: visible; }
        #reportPrintHeader { display: block; text-align: center; margin-bottom: 16px; background: #fff; }
        #reportPrintHeader h2 { font-size: 20px; margin-bottom: 4px; color: #173437; }
        #reportPrintHeader span { font-size: 12px; color: #555; }
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
    <div id="appContainer" class="hidden" style="display:flex;width:100%;">

        <!-- ===== الشريط الجانبي ===== -->
        <aside class="sidebar" id="sidebar">
            <div class="brand">
                <div class="logo" id="headerLogo">✥</div>
                <div>
                    <div class="name" id="headerCompanyName">شركة <span>الصوى</span></div>
                    <span class="version">GM</span>
                </div>
            </div>

            <nav class="nav-menu">
                <button class="nav-item active" id="sidenav-pending" onclick="switchTab('pending')">
                    <i class="fas fa-inbox"></i> بانتظار الاعتماد
                </button>
                <button class="nav-item" id="sidenav-history" onclick="switchTab('history')">
                    <i class="fas fa-history"></i> سجل الاعتمادات
                </button>
                <button class="nav-item" id="sidenav-branches" onclick="switchTab('branches')">
                    <i class="fas fa-building"></i> الفروع
                </button>
                <button class="nav-item" id="sidenav-payroll" onclick="switchTab('payroll')">
                    <i class="fas fa-money-check-dollar"></i> الرواتب والمكافآت
                </button>
                <button class="nav-item" id="sidenav-reports" onclick="switchTab('reports')">
                    <i class="fas fa-chart-bar"></i> التقارير
                </button>
                <div class="nav-divider"></div>
                <button class="nav-item" id="sidenav-shareholders" onclick="switchTab('shareholders')">
                    <i class="fas fa-user-tie"></i> المساهمون
                </button>
            </nav>

            <div class="user-info">
                <div class="avatar"><i class="fas fa-user-tie"></i></div>
                <div class="info">
                    <div class="name">الإدارة العليا</div>
                    <div class="role" id="roleBadge">المسؤول العام</div>
                </div>
                <button class="logout-btn" onclick="handleLogout()" title="تسجيل الخروج">
                    <i class="fas fa-sign-out-alt"></i>
                </button>
            </div>
        </aside>

        <!-- ===== الظل خلف القائمة الجانبية (موبايل) ===== -->
        <div class="sidebar-overlay" id="sidebarOverlay" onclick="toggleSidebar()"></div>

        <!-- ===== المحتوى الرئيسي ===== -->
        <main class="main-content">

            <!-- ===== الهيدر العلوي ===== -->
            <header class="top-header">
                <div class="page-title">
                    <button class="mobile-menu-toggle" onclick="toggleSidebar()">
                        <i class="fas fa-bars"></i>
                    </button>
                    <div>
                        <h2 id="pageTitle"><i class="fas fa-inbox"></i> بانتظار الاعتماد</h2>
                        <span class="sub" id="pageSub">الإيجازات التي تحتاج اعتمادك النهائي</span>
                    </div>
                </div>
                <div class="header-actions" style="position:relative;">
                    <span class="date-display">
                        <i class="fas fa-calendar"></i>
                        <span id="currentDateDisplay"></span>
                    </span>
                    <button onclick="toggleNotifPanel()" style="position:relative;width:38px;height:38px;border:none;border-radius:50%;background:rgba(0,107,115,0.06);color:var(--primary);cursor:pointer;font-size:16px;">
                        <i class="fas fa-bell"></i>
                        <span id="notifBadge" style="display:none;position:absolute;top:-2px;left:-2px;background:#DC2626;color:#fff;font-size:10px;font-weight:800;min-width:16px;height:16px;border-radius:50%;align-items:center;justify-content:center;">0</span>
                    </button>
                    <div id="notifPanel" style="display:none;position:absolute;left:0;top:48px;width:320px;max-height:420px;overflow-y:auto;background:#fff;border-radius:14px;box-shadow:0 12px 40px rgba(0,0,0,0.15);z-index:500;">
                        <div style="padding:12px 16px;border-bottom:1px solid #eee;display:flex;justify-content:space-between;align-items:center;">
                            <b style="font-size:13px;">الإشعارات</b>
                            <button onclick="markAllNotifsRead()" style="background:none;border:none;color:var(--primary);font-size:11px;cursor:pointer;">تعليم الكل كمقروء</button>
                        </div>
                        <div id="notifList" style="padding:6px;"></div>
                    </div>
                </div>
            </header>

            <!-- ===== المحتوى ===== -->
            <div id="pageContent">
            <div class="container">
            <div class="stats-grid">
                <div class="stat-card" id="pendingCard"><div class="label"><i class="fas fa-clock"></i> بانتظار الاعتماد</div><div class="value" id="statPending">0</div></div>
                <div class="stat-card"><div class="label"><i class="fas fa-check-circle"></i> معتمد اليوم</div><div class="value" id="statApprovedToday">0</div></div>
                <div class="stat-card"><div class="label"><i class="fas fa-building"></i> الفروع</div><div class="value" id="statBranches">0</div></div>
                <div class="stat-card"><div class="label"><i class="fas fa-users"></i> الموظفون</div><div class="value" id="statEmployees">0</div></div>
            </div>

            <div class="brief-card" id="payrollWindowCard" style="display:none;border-right-color:var(--primary);">
                <div class="brief-top">
                    <span class="branch"><i class="fas fa-money-check-dollar"></i> صلاحية تسليم رواتب هذا الشهر لكل فرع</span>
                </div>
                <div style="font-size:12px;color:var(--text-muted);margin-bottom:10px;">اختر فرعاً أو أكثر لفتح صلاحية تسليم الرواتب له لمدة 3 أيام، أو ألغِ صلاحية فرع مفتوحة</div>
                <div id="payrollBranchList" style="display:flex;flex-direction:column;gap:8px;margin-bottom:10px;"></div>
                <button class="btn small" id="payrollWindowBtn" onclick="openPayrollWindow()"><i class="fas fa-unlock"></i> فتح الصلاحية للفروع المحددة (3 أيام)</button>
            </div>

            <div id="view-pending"></div>
            <div id="view-history" class="hidden"></div>
            <div id="view-branches" class="hidden"></div>
            <div id="view-payroll" class="hidden">
                <div class="brief-card">
                    <h4 style="margin-bottom:10px;"><i class="fas fa-plus-circle"></i> إضافة راتب / مكافأة / خصم لموظف</h4>
                    <div style="display:grid;grid-template-columns:1.5fr 1fr 1fr 1.5fr auto;gap:10px;">
                        <select id="payrollEmployee" style="height:38px;padding:0 10px;border:1.5px solid #e2ebeb;border-radius:8px;font-family:var(--font-family);"></select>
                        <select id="payrollType" style="height:38px;padding:0 10px;border:1.5px solid #e2ebeb;border-radius:8px;font-family:var(--font-family);">
                            <option value="salary">راتب أساسي</option>
                            <option value="bonus">مكافأة</option>
                            <option value="deduction">خصم</option>
                        </select>
                        <input type="number" id="payrollAmount" placeholder="المبلغ" style="height:38px;padding:0 10px;border:1.5px solid #e2ebeb;border-radius:8px;font-family:var(--font-family);">
                        <input type="text" id="payrollNote" placeholder="ملاحظة (اختياري)" style="height:38px;padding:0 10px;border:1.5px solid #e2ebeb;border-radius:8px;font-family:var(--font-family);">
                        <button class="btn small" onclick="addPayrollAdjustment()"><i class="fas fa-save"></i> حفظ</button>
                    </div>
                </div>
                <div class="brief-card">
                    <h4 style="margin-bottom:10px;"><i class="fas fa-list"></i> قائمة رواتب هذا الشهر (من لم يستلم راتبه يظهر أولاً)</h4>
                    <div style="overflow-x:auto;">
                        <table style="width:100%;border-collapse:collapse;font-size:12px;">
                            <thead><tr style="background:var(--bg);"><th style="padding:6px;text-align:right;">الموظف</th><th style="padding:6px;">الفرع</th><th style="padding:6px;">الأساسي</th><th style="padding:6px;">المكافأة</th><th style="padding:6px;">الخصم</th><th style="padding:6px;">الصافي</th><th style="padding:6px;">الحالة</th></tr></thead>
                            <tbody id="payrollOverviewBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div id="view-reports" class="hidden">
                <div class="brief-card">
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px;margin-bottom:12px;">
                        <div class="form-group"><label style="font-size:12px;">نوع التقرير</label>
                            <select id="reportType" style="width:100%;height:38px;border:1.5px solid #e2ebeb;border-radius:8px;font-family:var(--font-family);">
                                <option value="all">تقرير شامل</option>
                                <option value="attendance">الحضور</option>
                                <option value="salaries">الرواتب</option>
                                <option value="briefing">الإيجاز</option>
                            </select>
                        </div>
                        <div class="form-group"><label style="font-size:12px;">من تاريخ</label><input type="date" id="reportFrom" style="width:100%;height:38px;border:1.5px solid #e2ebeb;border-radius:8px;font-family:var(--font-family);"></div>
                        <div class="form-group"><label style="font-size:12px;">إلى تاريخ</label><input type="date" id="reportTo" style="width:100%;height:38px;border:1.5px solid #e2ebeb;border-radius:8px;font-family:var(--font-family);"></div>
                        <div class="form-group"><label style="font-size:12px;">الفرع</label><select id="reportBranch" style="width:100%;height:38px;border:1.5px solid #e2ebeb;border-radius:8px;font-family:var(--font-family);"><option value="0">جميع الفروع</option></select></div>
                    </div>
                    <div style="display:flex;gap:8px;">
                        <button class="btn small" onclick="generateReport()"><i class="fas fa-file-lines"></i> إنشاء التقرير</button>
                        <button class="btn small green" onclick="downloadReport()"><i class="fas fa-download"></i> تحميل CSV</button>
                        <button class="btn small" onclick="printReportPDF()"><i class="fas fa-file-pdf"></i> تصدير PDF</button>
                    </div>
                </div>
                <div id="reportPrintHeader">
                    <h2 id="reportPrintCompany">شركة الصوى للصرافة</h2>
                    <span id="reportPrintMeta"></span>
                </div>
                <div id="reportResult"></div>
            </div>
            <div id="view-shareholders" class="hidden">
                <div class="brief-card">
                    <h4 style="margin-bottom:10px;"><i class="fas fa-user-plus"></i> إضافة حساب مساهم جديد</h4>
                    <div style="display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:10px;">
                        <input type="text" id="shName" placeholder="الاسم" style="height:38px;padding:0 10px;border:1.5px solid #e2ebeb;border-radius:8px;font-family:var(--font-family);">
                        <input type="email" id="shUsername" placeholder="البريد الإلكتروني (لتسجيل الدخول)" style="height:38px;padding:0 10px;border:1.5px solid #e2ebeb;border-radius:8px;font-family:var(--font-family);">
                        <input type="password" id="shPassword" placeholder="كلمة المرور" style="height:38px;padding:0 10px;border:1.5px solid #e2ebeb;border-radius:8px;font-family:var(--font-family);">
                        <button class="btn small" onclick="createShareholder()"><i class="fas fa-save"></i> إنشاء</button>
                    </div>
                </div>
                <div id="shareholdersList"></div>
            </div>
            </div>
            </div>

        </main>

    </div>

    <!-- شريط دعوة تثبيت التطبيق (PWA) -->
    <div id="pwaInstallBanner" style="display:none;position:fixed;top:0;left:0;right:0;background:var(--primary-dark);color:#fff;z-index:9999;padding:10px 16px;align-items:center;gap:10px;font-size:12.5px;">
        <img src="icons/icon-192.png" style="width:28px;height:28px;border-radius:8px;flex-shrink:0;">
        <span style="flex:1;">ثبّت تطبيق المسؤول العام على جهازك للوصول السريع</span>
        <button onclick="installPwa()" style="background:var(--accent);color:#fff;border:none;padding:6px 14px;border-radius:999px;font-weight:700;font-size:11.5px;cursor:pointer;white-space:nowrap;">تثبيت</button>
        <button onclick="dismissPwaBanner()" style="background:none;border:none;color:rgba(255,255,255,0.7);font-size:16px;cursor:pointer;padding:0 4px;">✕</button>
    </div>

    <!-- بطاقة تأكيد منبثقة من الأسفل (بديل عن confirm() الأصلية بالمتصفح) -->
    <style>
        @keyframes confirmSheetSlideUp { from { transform: translate(-50%, 100%); } to { transform: translate(-50%, 0); } }
    </style>
    <div id="confirmSheetOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);backdrop-filter:blur(4px);z-index:9998;" onclick="if(event.target===this) closeConfirmSheet()">
        <div id="confirmSheet" style="position:fixed;bottom:0;left:50%;width:100%;max-width:420px;background:var(--bg-card);border-radius:var(--radius-lg) var(--radius-lg) 0 0;box-shadow:var(--shadow-xl);padding:24px 20px calc(24px + env(safe-area-inset-bottom));animation:confirmSheetSlideUp 0.3s ease;transform:translate(-50%,0);">
            <div style="width:40px;height:4px;background:rgba(0,0,0,0.15);border-radius:4px;margin:0 auto 16px;"></div>
            <h3 id="confirmSheetTitle" style="font-size:16px;font-weight:800;color:var(--text-primary);text-align:center;margin-bottom:6px;">تأكيد</h3>
            <p id="confirmSheetMessage" style="font-size:13px;color:var(--text-muted);text-align:center;margin-bottom:20px;">هل أنت متأكد؟</p>
            <div style="display:flex;gap:10px;">
                <button style="flex:1;padding:14px;border:1.5px solid #EF4444;color:#EF4444;background:#fff;border-radius:10px;font-weight:700;cursor:pointer;" onclick="confirmSheetAccept()">تأكيد</button>
                <button style="flex:1;padding:14px;border:1.5px solid rgba(0,107,115,0.15);color:var(--text-primary);background:#fff;border-radius:10px;font-weight:700;cursor:pointer;" onclick="closeConfirmSheet()">إلغاء</button>
            </div>
        </div>
    </div>

    <div class="toast-container" id="toastContainer"></div>

<script>
    // شبكة أمان: التقاط أي طلب فشل بصمت بدل أن يظهر وكأن الزر لا يستجيب
    window.addEventListener('unhandledrejection', function(e) {
        console.error('Unhandled request failure:', e.reason);
        if (typeof showToast === 'function') {
            showToast('❌ خطأ في الاتصال', 'تعذر تنفيذ العملية — تأكد من تشغيل migrate.php على قاعدة البيانات ثم أعد المحاولة', 'error');
        }
        try {
            fetch('?ajax=log_error', { method: 'POST', body: new URLSearchParams({ clientAction: 'unhandledrejection', message: String(e.reason && e.reason.message || e.reason || 'unknown') }) }).catch(() => {});
        } catch (_) {}
        e.preventDefault();
    });

    // PWA: تسجيل خدمة العامل + دعوة التثبيت عند أول استخدام
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function() {
            navigator.serviceWorker.register('/sw.js').catch(function() {});
        });
    }
    let deferredInstallPrompt = null;
    window.addEventListener('beforeinstallprompt', function(e) {
        e.preventDefault();
        deferredInstallPrompt = e;
        if (!localStorage.getItem('pwaInstallDismissed')) {
            document.getElementById('pwaInstallBanner').style.display = 'flex';
        }
    });
    function installPwa() {
        document.getElementById('pwaInstallBanner').style.display = 'none';
        if (!deferredInstallPrompt) return;
        deferredInstallPrompt.prompt();
        deferredInstallPrompt.userChoice.finally(function() {
            deferredInstallPrompt = null;
            localStorage.setItem('pwaInstallDismissed', '1');
        });
    }
    function dismissPwaBanner() {
        document.getElementById('pwaInstallBanner').style.display = 'none';
        localStorage.setItem('pwaInstallDismissed', '1');
    }

    function requestNotifPermission() {
        if (!('Notification' in window) || localStorage.getItem('notifPermissionAsked')) return;
        localStorage.setItem('notifPermissionAsked', '1');
        if (Notification.permission === 'default') {
            Notification.requestPermission().catch(function() {});
        }
    }

    function checkNewBrowserNotifications(list, storageKey) {
        if (!('Notification' in window) || Notification.permission !== 'granted' || !list.length) return;
        const lastId = parseInt(localStorage.getItem(storageKey) || '0', 10);
        const maxId = list.reduce((m, n) => Math.max(m, n.id || 0), 0);
        if (lastId > 0) {
            list.filter(n => (n.id || 0) > lastId).slice(0, 3).forEach(n => {
                try {
                    const notif = new Notification(n.title, { body: n.message || '', icon: 'icons/icon-192.png', tag: storageKey + '_' + n.id });
                    notif.onclick = () => { window.focus(); notif.close(); };
                } catch (e) {}
            });
        }
        if (maxId > lastId) localStorage.setItem(storageKey, maxId);
    }

    function loadNotifications() {
        fetch('?ajax=notifications_list').then(r => r.json()).then(data => {
            if (!data.ok) return;
            checkNewBrowserNotifications(data.notifications, 'lastNotifId_gm');
            const badge = document.getElementById('notifBadge');
            if (data.unread > 0) {
                badge.style.display = 'flex';
                badge.textContent = data.unread;
            } else {
                badge.style.display = 'none';
            }
            const list = document.getElementById('notifList');
            if (!data.notifications.length) {
                list.innerHTML = '<div style="padding:20px;text-align:center;color:var(--text-muted);font-size:12px;">لا توجد إشعارات</div>';
                return;
            }
            list.innerHTML = data.notifications.map(n => `
                <div style="padding:10px 12px;border-radius:8px;margin-bottom:4px;background:${n.is_read ? 'transparent' : 'rgba(0,107,115,0.05)'};">
                    <div style="font-size:12px;font-weight:800;">${n.title}</div>
                    <div style="font-size:11.5px;color:var(--text-secondary);margin-top:2px;">${n.message}</div>
                    <div style="font-size:10px;color:var(--text-muted);margin-top:4px;">${n.date}</div>
                </div>
            `).join('');
        }).catch(() => {});
    }

    function toggleNotifPanel() {
        const panel = document.getElementById('notifPanel');
        const show = panel.style.display === 'none';
        panel.style.display = show ? 'block' : 'none';
        if (show) loadNotifications();
    }

    function markAllNotifsRead() {
        fetch('?ajax=notifications_mark_all_read', { method: 'POST' }).then(() => loadNotifications());
    }

    // بطاقة تأكيد منبثقة (بديل عن confirm() الأصلية بالمتصفح)
    let confirmSheetCallback = null;
    function showConfirmSheet(title, message, onConfirm) {
        document.getElementById('confirmSheetTitle').textContent = title;
        document.getElementById('confirmSheetMessage').textContent = message;
        confirmSheetCallback = onConfirm;
        document.getElementById('confirmSheetOverlay').style.display = 'block';
    }
    function closeConfirmSheet() {
        document.getElementById('confirmSheetOverlay').style.display = 'none';
        confirmSheetCallback = null;
    }
    function confirmSheetAccept() {
        const cb = confirmSheetCallback;
        closeConfirmSheet();
        if (cb) cb();
    }

    const loginScreen = document.getElementById('loginScreen');
    const appContainer = document.getElementById('appContainer');
    const alreadyLoggedIn = <?= $isLoggedIn ? 'true' : 'false' ?>;
    const initialRole = <?= json_encode($_SESSION['gm_user']['role'] ?? 'general_manager') ?>;

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
                    currentRole = data.role;
                    loginScreen.classList.add('hidden');
                    appContainer.classList.remove('hidden');
                    applyRoleUI();
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
        showConfirmSheet('تسجيل الخروج', 'هل أنت متأكد من رغبتك في تسجيل الخروج؟', function() {
            fetch('?ajax=logout', { method: 'POST' }).then(() => {
                appContainer.classList.add('hidden');
                loginScreen.classList.remove('hidden');
            }).catch(() => {
                showToast('❌ خطأ', 'تعذر الاتصال بالخادم', 'error');
            });
        });
    }

    let currentRole = 'general_manager';

    function initApp() {
        const now = new Date();
        const dEl = document.getElementById('currentDateDisplay');
        if (dEl) dEl.textContent = now.toLocaleDateString('ar-SA', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
        loadBootstrap();
        requestNotifPermission();
        loadNotifications();
        setInterval(loadNotifications, 60000);
        if (currentRole !== 'shareholder') loadPending();
        const today = new Date().toISOString().split('T')[0];
        const monthStart = today.slice(0, 8) + '01';
        document.getElementById('reportFrom').value = monthStart;
        document.getElementById('reportTo').value = today;
        fetch('?ajax=branches_overview').then(r => r.json()).then(data => {
            if (!data.ok) return;
            const select = document.getElementById('reportBranch');
            select.innerHTML = '<option value="0">جميع الفروع</option>' + data.branches.map(b => `<option value="${b.id}">${b.name}</option>`).join('');
        });
        if (currentRole !== 'shareholder') {
            fetch('?ajax=employees_list').then(r => r.json()).then(data => {
                if (!data.ok) return;
                const select = document.getElementById('payrollEmployee');
                if (select) select.innerHTML = data.employees.map(e => `<option value="${e.id}">${e.name} — ${e.branch}</option>`).join('');
            });
        }
    }

    // ============================================================
    // الرواتب والمكافآت (المسؤول العام فقط)
    // ============================================================
    function loadPayrollOverview() {
        fetch('?ajax=payroll_overview').then(r => r.json()).then(data => {
            if (!data.ok) return;
            const tbody = document.getElementById('payrollOverviewBody');
            tbody.innerHTML = data.salaries.map(r => `
                <tr style="border-bottom:1px solid #eee;">
                    <td style="padding:6px;">${r.name}${r.isManager ? ' <span style="color:var(--accent);font-size:10px;">(مدير فرع)</span>' : ''}${r.hasAdvance ? `<br><span style="font-size:10px;color:var(--red);">سلفة نشطة (${Number(r.advanceMonthly).toLocaleString()}/شهر)</span>` : ''}</td>
                    <td style="padding:6px;">${r.branch}</td>
                    <td style="padding:6px;">${r.base.toLocaleString()}</td>
                    <td style="padding:6px;color:var(--green);">+${r.bonus.toLocaleString()}</td>
                    <td style="padding:6px;color:var(--red);">-${r.deduction.toLocaleString()}</td>
                    <td style="padding:6px;font-weight:800;">${r.net.toLocaleString()}</td>
                    <td style="padding:6px;"><span class="status-pill ${r.statusRaw === 'delivered' ? 'approved' : 'pending'}">${r.status}</span></td>
                </tr>
            `).join('');
        });
    }

    function addPayrollAdjustment() {
        const select = document.getElementById('payrollEmployee');
        const name = select.options[select.selectedIndex] ? select.options[select.selectedIndex].text : '';
        const employeeId = select.value;
        const type = document.getElementById('payrollType').value;
        const amount = parseFloat(document.getElementById('payrollAmount').value) || 0;
        const note = document.getElementById('payrollNote').value;
        if (!employeeId || amount <= 0) {
            showToast('⚠️ تنبيه', 'الرجاء اختيار الموظف وإدخال مبلغ صحيح', 'warning');
            return;
        }
        fetch('?ajax=payroll_adjustment_add', { method: 'POST', body: new URLSearchParams({ employeeId, type, amount, note }) })
            .then(r => r.json()).then(data => {
                if (!data.ok) { showToast('⚠️ خطأ', data.error || 'تعذر الحفظ', 'error'); return; }
                showToast('✅ تم الحفظ', `تم تحديث راتب ${name} وإرسال إشعار له ولمسؤول فرعه و HR`, 'success');
                document.getElementById('payrollAmount').value = '';
                document.getElementById('payrollNote').value = '';
                loadPayrollOverview();
            });
    }

    function applyRoleUI() {
        const isShareholder = currentRole === 'shareholder';
        document.getElementById('roleBadge').textContent = isShareholder ? 'مساهم' : 'المسؤول العام';
        document.getElementById('sidenav-shareholders').style.display = isShareholder ? 'none' : '';
        document.getElementById('sidenav-payroll').style.display = isShareholder ? 'none' : '';
        document.getElementById('pendingCard').style.display = isShareholder ? 'none' : '';
        document.getElementById('sidenav-pending').style.display = isShareholder ? 'none' : '';
        if (isShareholder) switchTab('history');
    }

    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('open');
        document.getElementById('sidebarOverlay').classList.toggle('show');
    }

    function loadBootstrap() {
        fetch('?ajax=bootstrap').then(r => r.json()).then(data => {
            if (!data.ok) return;
            if (data.company) {
                document.getElementById('headerCompanyName').textContent = data.company.name;
                if (data.company.logo) document.getElementById('headerLogo').innerHTML = `<img src="${data.company.logo}" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;">`;
            }
            document.getElementById('statPending').textContent = data.stats.pending;
            document.getElementById('statApprovedToday').textContent = data.stats.approvedToday;
            document.getElementById('statBranches').textContent = data.stats.branches;
            document.getElementById('statEmployees').textContent = data.stats.employees;

            if (currentRole !== 'shareholder') {
                document.getElementById('payrollWindowCard').style.display = 'block';
                renderPayrollBranchList(data.payrollBranches || []);
            }
        });
    }

    function renderPayrollBranchList(branches) {
        const wrap = document.getElementById('payrollBranchList');
        if (!branches.length) {
            wrap.innerHTML = '<p style="font-size:12px;color:var(--text-muted);">لا توجد فروع نشطة</p>';
            return;
        }
        wrap.innerHTML = branches.map(b => {
            if (b.open) {
                const expires = new Date(b.expiresAt.replace(' ', 'T'));
                return `
                    <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 10px;background:rgba(16,185,129,0.06);border-radius:8px;">
                        <div>
                            <b style="font-size:13px;">${b.name}</b>
                            <div style="font-size:11px;color:var(--text-muted);">مفتوحة حتى ${expires.toLocaleString('ar-SA')}</div>
                        </div>
                        <button class="btn small red" onclick="closePayrollWindow(${b.id}, '${b.name.replace(/'/g, "")}')"><i class="fas fa-lock"></i> إلغاء</button>
                    </div>
                `;
            }
            return `
                <label style="display:flex;align-items:center;gap:8px;padding:8px 10px;background:var(--bg);border-radius:8px;cursor:pointer;">
                    <input type="checkbox" class="payroll-branch-check" value="${b.id}">
                    <span style="font-size:13px;">${b.name}</span>
                </label>
            `;
        }).join('');
    }

    function openPayrollWindow() {
        const checked = [...document.querySelectorAll('.payroll-branch-check:checked')].map(el => el.value);
        if (!checked.length) {
            showToast('⚠️ تنبيه', 'الرجاء اختيار فرع واحد على الأقل', 'warning');
            return;
        }
        showConfirmSheet('فتح صلاحية تسليم الرواتب', `سيمنح هذا صلاحية تسليم الرواتب لـ ${checked.length} فرع لمدة 3 أيام من الآن. متابعة؟`, function() {
            const body = new URLSearchParams();
            checked.forEach(id => body.append('branchIds[]', id));
            fetch('?ajax=payroll_window_open', { method: 'POST', body }).then(r => r.json()).then(data => {
                if (!data.ok) { showToast('⚠️ خطأ', data.error || 'تعذر الفتح', 'error'); return; }
                showToast('✅ تم الفتح', 'أصبح بإمكان الفروع المحددة تسليم الرواتب لمدة 3 أيام', 'success');
                loadBootstrap();
            }).catch(() => {
                showToast('⚠️ خطأ', 'تعذر الاتصال بالخادم', 'error');
            });
        });
    }

    function closePayrollWindow(branchId, branchName) {
        showConfirmSheet('إلغاء صلاحية تسليم الرواتب', `سيتم إلغاء صلاحية تسليم الرواتب لفرع ${branchName} فوراً. متابعة؟`, function() {
            fetch('?ajax=payroll_window_close', { method: 'POST', body: new URLSearchParams({ branchId }) }).then(r => r.json()).then(data => {
                if (!data.ok) { showToast('⚠️ خطأ', data.error || 'تعذر الإلغاء', 'error'); return; }
                showToast('✅ تم الإلغاء', `أُلغيت صلاحية تسليم رواتب فرع ${branchName}`, 'success');
                loadBootstrap();
            }).catch(() => {
                showToast('⚠️ خطأ', 'تعذر الاتصال بالخادم', 'error');
            });
        });
    }

    const gmPageTitles = {
        pending: { title: 'بانتظار الاعتماد', sub: 'الإيجازات التي تحتاج اعتمادك النهائي', icon: 'fa-inbox' },
        history: { title: 'سجل الاعتمادات', sub: 'كل الإيجازات المعتمدة أو المرفوضة', icon: 'fa-history' },
        branches: { title: 'الفروع', sub: 'نظرة عامة على أداء كل فرع', icon: 'fa-building' },
        payroll: { title: 'الرواتب والمكافآت', sub: 'إدارة رواتب ومكافآت وخصومات الموظفين', icon: 'fa-money-check-dollar' },
        reports: { title: 'التقارير', sub: 'إنشاء وتصدير التقارير', icon: 'fa-chart-bar' },
        shareholders: { title: 'المساهمون', sub: 'إدارة حسابات المساهمين', icon: 'fa-user-tie' },
    };

    function switchTab(tab) {
        ['pending', 'history', 'branches', 'payroll', 'reports', 'shareholders'].forEach(t => {
            document.getElementById('sidenav-' + t).classList.toggle('active', t === tab);
            document.getElementById('view-' + t).classList.toggle('hidden', t !== tab);
        });
        const info = gmPageTitles[tab];
        if (info) {
            document.getElementById('pageTitle').innerHTML = `<i class="fas ${info.icon}"></i> ${info.title}`;
            document.getElementById('pageSub').textContent = info.sub;
        }
        if (window.innerWidth <= 768) {
            document.getElementById('sidebar').classList.remove('open');
            document.getElementById('sidebarOverlay').classList.remove('show');
        }
        if (tab === 'pending') loadPending();
        else if (tab === 'history') loadHistory();
        else if (tab === 'branches') loadBranches();
        else if (tab === 'payroll') loadPayrollOverview();
        else if (tab === 'shareholders') loadShareholders();
    }

    function renderBriefEntriesBlock(entries, prevDayNetProfit) {
        entries = entries || [];
        const hasPrev = prevDayNetProfit !== undefined && prevDayNetProfit !== null;
        if (!entries.length && !hasPrev) return '';
        let inner = '';
        if (entries.length) {
            inner += `<div style="padding:8px 10px;font-weight:800;font-size:11.5px;background:rgba(0,107,115,0.05);"><i class="fas fa-list"></i> قيود الإيجاز (${entries.length})</div>`;
            inner += entries.map((en, idx) => `
                <div style="padding:10px 12px;${idx > 0 ? 'border-top:1px solid rgba(0,107,115,0.08);' : ''}">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                        <span style="font-size:10.5px;color:var(--text-muted);font-weight:700;">قيد ${idx + 1}</span>
                        <b style="font-size:14px;color:${en.type === 'income' ? '#059669' : '#DC2626'};">${en.type === 'income' ? '+' : '-'}${Number(en.amount).toLocaleString()} د.ع</b>
                    </div>
                    <div style="display:flex;flex-wrap:wrap;gap:6px 16px;font-size:11.5px;${en.attachment ? 'margin-bottom:8px;' : ''}">
                        <span><b style="color:var(--text-muted);">النوع:</b> <span style="font-weight:700;color:${en.type === 'income' ? '#059669' : '#DC2626'};">${en.type === 'income' ? '💰 إيراد' : '💸 صرف'}</span></span>
                        <span><b style="color:var(--text-muted);">الملاحظة:</b> ${en.note || 'بدون ملاحظات'}</span>
                    </div>
                    ${en.attachment ? `<a href="${en.attachment}" target="_blank" style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;background:rgba(0,107,115,0.08);color:var(--primary);border-radius:8px;font-size:11px;font-weight:700;text-decoration:none;"><i class="fas fa-paperclip"></i> عرض الملف المرفق</a>` : ''}
                </div>
            `).join('');
        }
        const totalIn = entries.filter(e => e.type === 'income').reduce((s, e) => s + Number(e.amount), 0);
        const totalOut = entries.filter(e => e.type === 'expense').reduce((s, e) => s + Number(e.amount), 0);
        inner += `<div style="padding:10px 12px;${entries.length ? 'border-top:1.5px solid rgba(0,107,115,0.1);' : ''}background:rgba(0,107,115,0.04);">
            ${entries.length ? `
                <div style="display:flex;flex-wrap:wrap;gap:10px 18px;font-size:11.5px;${hasPrev ? 'margin-bottom:6px;' : ''}">
                    <span><b style="color:var(--text-muted);">إجمالي الإيراد:</b> <b style="color:#059669;">${totalIn.toLocaleString()}</b></span>
                    <span><b style="color:var(--text-muted);">إجمالي الصرف:</b> <b style="color:#DC2626;">${totalOut.toLocaleString()}</b></span>
                </div>
            ` : ''}
            ${hasPrev ? `
                <div style="font-size:11px;color:var(--text-muted);">
                    <i class="fas fa-calendar-day"></i> صافي ربح اليوم السابق:
                    <b style="color:${prevDayNetProfit >= 0 ? '#059669' : '#DC2626'};">${prevDayNetProfit >= 0 ? '+' : ''}${Number(prevDayNetProfit).toLocaleString()}</b> د.ع
                </div>
            ` : '<div style="font-size:11px;color:var(--text-muted);">لا يوجد إيجاز لليوم السابق للمقارنة</div>'}
        </div>`;
        return `<div style="margin:8px 0;border-radius:8px;overflow:hidden;border:1px solid rgba(0,107,115,0.06);">${inner}</div>`;
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
                    <div class="muted" style="font-size:13px;margin:4px 0;">
                        <i class="fas ${b.hr_decision === 'approved' ? 'fa-circle-check' : 'fa-clock'}" style="color:${b.hr_decision === 'approved' ? 'var(--green)' : '#D97706'};"></i>
                        ${b.hrStatusText}
                    </div>
                    ${renderBriefEntriesBlock(b.entries, b.prevDayNetProfit)}
                    ${b.note ? `<div class="brief-note"><b>ملاحظة المرسل:</b> ${b.note}</div>` : ''}
                    ${b.attachment ? `<div class="brief-note"><a href="${b.attachment}" target="_blank"><i class="fas fa-paperclip"></i> عرض الملف المرفق مع الإيجاز</a></div>` : ''}
                    ${b.hrNote ? `<div class="brief-note"><b>ملاحظة HR:</b> ${b.hrNote}</div>` : ''}
                    ${currentRole !== 'shareholder' ? `
                        <div class="brief-actions">
                            <input type="text" id="gmNote_${b.id}" placeholder="ملاحظة الاعتماد النهائي (اختياري)">
                            <button class="btn small green" onclick="finalReview(${b.id}, 'approved')"><i class="fas fa-check"></i> اعتماد نهائي</button>
                            <button class="btn small red" onclick="finalReview(${b.id}, 'rejected')"><i class="fas fa-times"></i> رفض</button>
                        </div>
                    ` : ''}
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

    let gmBranchList = [];
    let gmBranchDetailId = null;
    let gmBranchDateFilter = null;

    function loadBranches() {
        fetch('?ajax=branches_overview').then(r => r.json()).then(data => {
            if (!data.ok) return;
            gmBranchList = data.branches;
            renderGmBranchGrid();
        });
    }

    function renderGmBranchGrid() {
        gmBranchDetailId = null;
        const view = document.getElementById('view-branches');
        view.innerHTML = '<div class="branches-grid">' + gmBranchList.map(b => `
            <div class="branch-card" style="cursor:pointer;" onclick="openGmBranchDetail(${b.id})">
                <div class="name"><i class="fas fa-building" style="color:var(--primary);"></i> ${b.name}</div>
                <div class="muted">المدير: ${b.manager || '—'}</div>
                <div class="muted">عدد الموظفين: ${b.employeeCount}</div>
            </div>
        `).join('') + '</div>';
    }

    function openGmBranchDetail(id) {
        gmBranchDetailId = id;
        gmBranchDateFilter = null;
        loadGmBranchDetail(id, null);
    }

    function loadGmBranchDetail(id, date) {
        let url = '?ajax=branch_detail&id=' + id;
        if (date) url += '&date=' + encodeURIComponent(date);
        fetch(url).then(r => r.json()).then(data => {
            if (!data.ok) { showToast('⚠️ خطأ', data.error || 'تعذر تحميل بيانات الفرع', 'error'); return; }
            renderGmBranchDetail(data);
        });
    }

    function closeGmBranchDetail() {
        renderGmBranchGrid();
    }

    function filterGmBranchDate() {
        const date = document.getElementById('gmBranchDateInput').value;
        if (!date) { showToast('⚠️ تنبيه', 'اختر تاريخاً أولاً', 'warning'); return; }
        gmBranchDateFilter = date;
        loadGmBranchDetail(gmBranchDetailId, date);
    }

    function clearGmBranchDateFilter() {
        gmBranchDateFilter = null;
        loadGmBranchDetail(gmBranchDetailId, null);
    }

    function renderGmBranchDetail(data) {
        const view = document.getElementById('view-branches');
        const b = data.branch;
        const pct = data.overallAttendanceRate || 0;

        const employeesHtml = data.employees.length ? data.employees.map(e => `
            <div style="display:flex;align-items:center;justify-content:space-between;padding:8px 10px;background:var(--bg);border-radius:8px;margin-bottom:6px;">
                <div><b style="font-size:13px;">${e.name}${e.isManager ? ' 👑' : ''}</b><div class="muted" style="font-size:11px;">${e.job_title || ''} · ${e.shiftTypeText}</div></div>
                <div style="font-weight:800;color:${e.attendanceRate >= 80 ? 'var(--green)' : (e.attendanceRate >= 50 ? 'var(--orange)' : 'var(--red)')};">${e.attendanceRate}%</div>
            </div>
        `).join('') : '<p class="muted">لا يوجد موظفون</p>';

        let briefsHtml = '';
        if (!data.briefs.length) {
            briefsHtml = `<p class="muted" style="text-align:center;padding:16px;">لا توجد إيجازات ${gmBranchDateFilter ? 'في هذا التاريخ' : 'منشورة بعد'}</p>`;
        } else {
            let dividerInserted = !!gmBranchDateFilter;
            data.briefs.forEach(br => {
                if (!dividerInserted && !br.isToday) {
                    briefsHtml += `<div style="display:flex;align-items:center;gap:10px;margin:14px 0;color:var(--text-muted);font-size:11.5px;"><span style="flex:1;height:1px;background:#eee;"></span>إيجازات سابقة<span style="flex:1;height:1px;background:#eee;"></span></div>`;
                    dividerInserted = true;
                }
                briefsHtml += `
                    <div class="brief-card history">
                        <div class="brief-top">
                            <span class="branch"><i class="fas fa-pen"></i> ${br.sender}</span>
                            <span class="status-pill ${br.status === 'approved' ? 'approved' : (br.status === 'rejected' ? 'rejected' : 'pending')}">${br.statusText}</span>
                            <span class="date">${br.date}${br.isToday ? ' (اليوم)' : ''}</span>
                        </div>
                        <div class="brief-details">
                            <div class="item"><div class="v">${br.revenue.toLocaleString()}</div><div class="l">الإيرادات</div></div>
                            <div class="item"><div class="v">${br.expense.toLocaleString()}</div><div class="l">المصاريف</div></div>
                            <div class="item"><div class="v">${br.travelers}</div><div class="l">المسافرون</div></div>
                            <div class="item"><div class="v" style="color:var(--green);">${br.profit.toLocaleString()}</div><div class="l">صافي الربح</div></div>
                        </div>
                        ${renderBriefEntriesBlock(br.entries, br.prevDayNetProfit)}
                        ${br.note ? `<div class="brief-note"><b>ملاحظة المرسل:</b> ${br.note}</div>` : ''}
                        ${br.attachment ? `<div class="brief-note"><a href="${br.attachment}" target="_blank"><i class="fas fa-paperclip"></i> عرض الملف المرفق مع الإيجاز</a></div>` : ''}
                        ${br.hrNote ? `<div class="brief-note"><b>ملاحظة HR:</b> ${br.hrNote}</div>` : ''}
                        ${br.gmNote ? `<div class="brief-note"><b>ملاحظتك:</b> ${br.gmNote}</div>` : ''}
                    </div>
                `;
            });
        }

        view.innerHTML = `
            <button class="btn small" onclick="closeGmBranchDetail()" style="margin-bottom:12px;"><i class="fas fa-arrow-right"></i> رجوع لكل الفروع</button>
            <div class="brief-card" style="border-right-color:var(--primary);">
                <div class="brief-top">
                    <span class="branch"><i class="fas fa-building"></i> ${b.name}</span>
                    <span class="date">${pct}% حضور</span>
                </div>
                <div class="muted" style="font-size:12px;">المدير: ${b.manager || '—'} • الدوام: ${(b.shiftStart && b.shiftEnd) ? (b.shiftStart + ' - ' + b.shiftEnd) : 'غير محدد'}</div>
            </div>
            <h4 style="font-size:14px;margin:14px 0 8px;"><i class="fas fa-users"></i> الموظفون</h4>
            ${employeesHtml}
            <h4 style="font-size:14px;margin:14px 0 8px;"><i class="fas fa-file-signature"></i> الإيجازات</h4>
            <div style="display:flex;gap:8px;align-items:center;margin-bottom:10px;flex-wrap:wrap;">
                <input type="date" id="gmBranchDateInput" style="height:36px;padding:0 10px;border:1.5px solid #e2ebeb;border-radius:8px;font-family:var(--font-family);">
                <button class="btn small" onclick="filterGmBranchDate()"><i class="fas fa-rotate"></i> تحديث</button>
                <button class="btn small" onclick="clearGmBranchDateFilter()"><i class="fas fa-list"></i> كل السجل</button>
            </div>
            ${briefsHtml}
        `;
    }

    // ============================================================
    // التقارير
    // ============================================================
    function attendanceTable(rows) {
        if (!rows || !rows.length) return '<p style="color:var(--text-muted);font-size:13px;">لا توجد بيانات</p>';
        return '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:12px;"><thead><tr style="background:var(--bg);"><th style="padding:6px;text-align:right;">الموظف</th><th style="padding:6px;">الفرع</th><th style="padding:6px;">دخول</th><th style="padding:6px;">انصراف</th><th style="padding:6px;">الحالة</th></tr></thead><tbody>' +
            rows.map(r => `<tr style="border-bottom:1px solid #eee;"><td style="padding:6px;">${r.name}</td><td style="padding:6px;">${r.branch}</td><td style="padding:6px;">${r.checkIn}</td><td style="padding:6px;">${r.checkOut}</td><td style="padding:6px;">${r.status}</td></tr>`).join('') + '</tbody></table></div>';
    }
    function salariesTable(rows) {
        if (!rows || !rows.length) return '<p style="color:var(--text-muted);font-size:13px;">لا توجد بيانات</p>';
        return '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:12px;"><thead><tr style="background:var(--bg);"><th style="padding:6px;text-align:right;">الموظف</th><th style="padding:6px;">الفرع</th><th style="padding:6px;">الأساسي</th><th style="padding:6px;">المكافأة</th><th style="padding:6px;">الخصم</th><th style="padding:6px;">الصافي</th><th style="padding:6px;">الحالة</th></tr></thead><tbody>' +
            rows.map(r => `<tr style="border-bottom:1px solid #eee;"><td style="padding:6px;">${r.name}</td><td style="padding:6px;">${r.branch}</td><td style="padding:6px;">${r.base}</td><td style="padding:6px;">${r.bonus}</td><td style="padding:6px;">${r.deduction}</td><td style="padding:6px;">${r.net}</td><td style="padding:6px;">${r.status}</td></tr>`).join('') + '</tbody></table></div>';
    }
    function briefingTable(rows) {
        if (!rows || !rows.length) return '<p style="color:var(--text-muted);font-size:13px;">لا توجد بيانات</p>';
        return '<div style="overflow-x:auto;"><table style="width:100%;border-collapse:collapse;font-size:12px;"><thead><tr style="background:var(--bg);"><th style="padding:6px;text-align:right;">الفرع</th><th style="padding:6px;">كاتب الإيجاز</th><th style="padding:6px;">التاريخ</th><th style="padding:6px;">الإيراد</th><th style="padding:6px;">المصروف</th><th style="padding:6px;">المسافرون</th><th style="padding:6px;">الربح</th><th style="padding:6px;">الحالة</th><th style="padding:6px;">ملاحظة HR</th><th style="padding:6px;">ملاحظة المسؤول العام</th></tr></thead><tbody>' +
            rows.map(r => `<tr style="border-bottom:1px solid #eee;"><td style="padding:6px;">${r.branch}</td><td style="padding:6px;">${r.sender || '-'}</td><td style="padding:6px;">${r.date}</td><td style="padding:6px;">${r.revenue}</td><td style="padding:6px;">${r.expense}</td><td style="padding:6px;">${r.travelers}</td><td style="padding:6px;">${r.profit}</td><td style="padding:6px;">${r.statusText || '-'}</td><td style="padding:6px;">${r.hrNote || '-'}</td><td style="padding:6px;">${r.gmNote || '-'}</td></tr>`).join('') + '</tbody></table></div>';
    }

    const reportTypeNamesGm = { 'attendance': 'الحضور', 'salaries': 'الرواتب', 'briefing': 'الإيجاز', 'all': 'تقرير شامل' };

    function generateReport() {
        const type = document.getElementById('reportType').value;
        const from = document.getElementById('reportFrom').value;
        const to = document.getElementById('reportTo').value;
        const branch = document.getElementById('reportBranch').value || '0';
        const qs = new URLSearchParams({ type, from, to, branch });
        fetch('?ajax=report&' + qs.toString()).then(r => r.json()).then(data => {
            if (!data.ok) { showToast('⚠️ خطأ', 'تعذر إنشاء التقرير', 'error'); return; }
            let html = '<div class="brief-card">';
            if (type === 'attendance' || type === 'all') html += '<h4 style="margin-bottom:8px;"><i class="fas fa-clock"></i> الحضور</h4>' + attendanceTable(data.attendance);
            if (type === 'salaries' || type === 'all') html += '<h4 style="margin:14px 0 8px;"><i class="fas fa-wallet"></i> الرواتب</h4>' + salariesTable(data.salaries);
            if (type === 'briefing' || type === 'all') html += '<h4 style="margin:14px 0 8px;"><i class="fas fa-chart-simple"></i> الإيجاز</h4>' + briefingTable(data.briefing);
            html += '</div>';
            document.getElementById('reportResult').innerHTML = html;
            document.getElementById('reportPrintMeta').textContent = `${reportTypeNamesGm[type] || type} — من ${from} إلى ${to}`;
            showToast('📊 تم الإنشاء', 'تم إنشاء التقرير بنجاح', 'success');
        });
    }

    function downloadReport() {
        const type = document.getElementById('reportType').value;
        const from = document.getElementById('reportFrom').value;
        const to = document.getElementById('reportTo').value;
        const branch = document.getElementById('reportBranch').value || '0';
        const qs = new URLSearchParams({ type, from, to, branch });
        window.location.href = '?ajax=report_download&' + qs.toString();
    }

    function printReportPDF() {
        if (!document.getElementById('reportResult').innerHTML.trim()) {
            showToast('⚠️ تنبيه', 'الرجاء إنشاء التقرير أولاً', 'warning');
            return;
        }
        showToast('🖨️ جاري التجهيز', 'اختر "حفظ كـ PDF" من نافذة الطباعة', 'info');
        setTimeout(() => window.print(), 300);
    }

    // ============================================================
    // المساهمون
    // ============================================================
    function loadShareholders() {
        fetch('?ajax=shareholders_list').then(r => r.json()).then(data => {
            if (!data.ok) return;
            const view = document.getElementById('shareholdersList');
            if (!data.shareholders.length) {
                view.innerHTML = '<div class="empty-state"><i class="fas fa-user-tie"></i><p>لا توجد حسابات مساهمين بعد</p></div>';
                return;
            }
            view.innerHTML = data.shareholders.map(s => `
                <div class="brief-card" style="display:flex;align-items:center;justify-content:space-between;">
                    <div><b>${s.display_name}</b> <span style="color:var(--text-muted);font-size:12px;">(${s.username})</span></div>
                    <button class="btn small ${s.status === 'active' ? 'red' : 'green'}" onclick="toggleShareholder(${s.id})">${s.status === 'active' ? 'تعطيل' : 'تفعيل'}</button>
                </div>
            `).join('');
        });
    }

    function createShareholder() {
        const name = document.getElementById('shName').value;
        const username = document.getElementById('shUsername').value;
        const password = document.getElementById('shPassword').value;
        if (!name || !username || password.length < 6) {
            showToast('⚠️ تنبيه', 'الرجاء تعبئة الاسم والبريد الإلكتروني وكلمة مرور 6 أحرف على الأقل', 'warning');
            return;
        }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(username)) {
            showToast('⚠️ تنبيه', 'الرجاء إدخال بريد إلكتروني صحيح', 'warning');
            return;
        }
        fetch('?ajax=shareholder_create', { method: 'POST', body: new URLSearchParams({ name, username, password }) })
            .then(r => r.json()).then(data => {
                if (!data.ok) { showToast('⚠️ خطأ', data.error || 'تعذر الإنشاء', 'error'); return; }
                showToast('✅ تم الإنشاء', 'تم إنشاء حساب المساهم بنجاح', 'success');
                document.getElementById('shName').value = '';
                document.getElementById('shUsername').value = '';
                document.getElementById('shPassword').value = '';
                loadShareholders();
            }).catch(() => {
                showToast('⚠️ خطأ', 'تعذر الاتصال بالخادم', 'error');
            });
    }

    function toggleShareholder(id) {
        fetch('?ajax=shareholder_toggle', { method: 'POST', body: new URLSearchParams({ id }) })
            .then(r => r.json()).then(data => {
                if (data.ok) loadShareholders();
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
            currentRole = initialRole;
            loginScreen.classList.add('hidden');
            appContainer.classList.remove('hidden');
            applyRoleUI();
            initApp();
        }
    });
</script>
</body>
</html>
