<?php
/* ======================================================================
   لوحة الموارد البشرية (HR) — نفس الملف الأصلي (HTML/CSS/JS) تماماً
   مع طبقة PHP + MySQL حقيقية خلفه بدل البيانات الوهمية في JavaScript.
   القسم الأول: منطق PHP ونقاط AJAX — القسم الثاني: نفس HTML/CSS/JS الأصلي
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

function handle_upload(string $field, string $sub, array $allowedExt): ?string
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        return null;
    }
    $dir = __DIR__ . '/uploads/' . $sub;
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $filename = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $dir . '/' . $filename)) {
        return null;
    }
    return 'uploads/' . $sub . '/' . $filename;
}

function attendance_status_ar(string $s): string
{
    return ['present' => 'حاضر', 'late' => 'متأخر', 'absent' => 'غائب'][$s] ?? $s;
}
function payroll_status_ar(string $s): string
{
    return $s === 'delivered' ? 'مدفوع' : 'قيد المعالجة';
}
function request_type_ar(string $t): string
{
    return ['leave' => 'إجازة', 'advance' => 'سلفة', 'complaint' => 'شكوى', 'resignation' => 'استقالة'][$t] ?? $t;
}
function request_status_ar(string $s): string
{
    return ['pending' => 'قيد مراجعة مدير الفرع', 'branch_approved' => 'قيد مراجعة الموارد البشرية', 'approved' => 'مقبول', 'rejected' => 'مرفوض'][$s] ?? $s;
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
        $pdo->prepare("INSERT INTO error_log (app, action, user_role, user_id, message) VALUES ('hr', ?, ?, ?, ?)")
            ->execute([$action, $role, $userId, mb_substr($message, 0, 500)]);
    } catch (Throwable $e) {
        // جدول error_log قد لا يكون موجوداً بعد على قاعدة بيانات لم تُحدَّث — تجاهل بصمت
    }
}

$isLoggedIn = !empty($_SESSION['hr_user']);

/* ======================================================================
   نقاط AJAX — كل الوظائف الحقيقية للنظام
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
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND role = 'hr' AND status = 'active' LIMIT 1");
        $stmt->execute([$username]);
        $row = $stmt->fetch();
        if ($row && password_verify($password, $row['password_hash'])) {
            $_SESSION['hr_user'] = ['id' => (int) $row['id'], 'username' => $row['username']];
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

    if (empty($_SESSION['hr_user'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'unauthorized']);
        exit;
    }
    $hrUser = $_SESSION['hr_user'];

    if ($action === 'log_error') {
        $clientMsg = trim((string) ($_POST['message'] ?? ''));
        $clientAction = trim((string) ($_POST['clientAction'] ?? 'client'));
        if ($clientMsg !== '') {
            log_error($pdo, $clientAction, 'hr', $hrUser['id'], $clientMsg);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    try {
    switch ($action) {

        case 'bootstrap': {
            $branches = $pdo->query("
                SELECT b.id, b.name, b.location, b.status, b.notes,
                       e.id AS managerId, e.full_name AS manager, e.national_id AS nationalId, e.phone_number AS phone, e.birth_date AS birthDate,
                       e.hire_date AS hireDate,
                       e.shift_start AS shiftStart, e.shift_end AS shiftEnd,
                       e.photo, e.documents AS docs
                FROM branches b
                LEFT JOIN employees e ON e.branch_id = b.id AND e.is_branch_manager = 1
                ORDER BY b.created_at DESC
            ")->fetchAll();

            $rateRow = $pdo->query("SELECT usd_exchange_rate FROM settings ORDER BY id DESC LIMIT 1")->fetch();
            $settingsRow = $pdo->query("SELECT * FROM settings ORDER BY id DESC LIMIT 1")->fetch();

            $topStmt = $pdo->query("
                SELECT e.id, e.full_name AS name, b.name AS branch,
                       ROUND(SUM(a.status IN ('present','late')) / GREATEST(COUNT(a.id),1) * 100) AS rate
                FROM employees e JOIN branches b ON b.id = e.branch_id
                LEFT JOIN attendance a ON a.employee_id = e.id AND a.attendance_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                WHERE e.status = 'active'
                GROUP BY e.id, e.full_name, b.name
                HAVING COUNT(a.id) > 0
                ORDER BY rate DESC LIMIT 3
            ");
            $medals = ['🥇', '🥈', '🥉'];
            $colors = ['gold', 'silver', 'bronze'];
            $topEmployees = [];
            foreach ($topStmt->fetchAll() as $i => $r) {
                $topEmployees[] = ['rank' => $i + 1, 'name' => $r['name'], 'branch' => $r['branch'], 'rate' => (int) $r['rate'], 'medal' => $medals[$i], 'color' => $colors[$i]];
            }

            $monthStart = date('Y-m-01');
            $approvedStatuses = "('hr_approved','gm_approved','approved')";
            $monthTotals = $pdo->prepare("SELECT COALESCE(SUM(total_income),0) AS income, COALESCE(SUM(total_expense),0) AS expense
                FROM daily_briefs WHERE brief_date >= ? AND status IN $approvedStatuses");
            $monthTotals->execute([$monthStart]);
            $monthTotals = $monthTotals->fetch();
            $monthlyIncome = (float) $monthTotals['income'];
            $monthlyExpense = (float) $monthTotals['expense'];
            $profitMarginPct = $monthlyIncome > 0 ? round((($monthlyIncome - $monthlyExpense) / $monthlyIncome) * 100, 1) : 0;

            // توزيع الحضور اليوم (حاضر/متأخر/غائب) لكل الموظفين النشطين — حسب شفت كل موظف الخاص به
            $presentCount = 0; $lateCount = 0; $absentCount = 0;
            $todayRows = $pdo->query("
                SELECT e.shift_start, a.status FROM employees e
                LEFT JOIN attendance a ON a.employee_id = e.id AND a.attendance_date = CURDATE()
                WHERE e.status = 'active'
            ")->fetchAll();
            $totalActive = count($todayRows);
            $dow = (int) date('w');
            $isOffDay = ($dow === 5 || $dow === 6);
            $nowMinutes = (int) date('H') * 60 + (int) date('i');
            $grace = (int) ($settingsRow['late_grace_minutes'] ?? 0);
            foreach ($todayRows as $r) {
                if ($r['status'] === 'late') { $lateCount++; continue; }
                if ($r['status'] === 'present') { $presentCount++; continue; }
                if ($isOffDay || !$r['shift_start']) continue;
                [$shH, $shM] = array_map('intval', explode(':', $r['shift_start']));
                $deadline = $shH * 60 + $shM + $grace;
                if ($nowMinutes > $deadline) $absentCount++;
            }
            $pendingCount = $totalActive - $presentCount - $lateCount - $absentCount;

            // حصة كل فرع من إيرادات الشهر الحالي كمقياس مقارنة بين الفروع
            $branchRevenueRows = $pdo->query("
                SELECT b.id, b.name, COALESCE(SUM(CASE WHEN db.brief_date >= '$monthStart' AND db.status IN $approvedStatuses THEN db.total_income ELSE 0 END),0) AS revenue
                FROM branches b
                LEFT JOIN daily_briefs db ON db.branch_id = b.id
                WHERE b.status = 'active'
                GROUP BY b.id, b.name
                ORDER BY revenue DESC
            ")->fetchAll();
            $branchRevenueTotal = array_sum(array_column($branchRevenueRows, 'revenue'));
            $branchRevenueShares = array_map(function ($r) use ($branchRevenueTotal) {
                return [
                    'name' => $r['name'],
                    'revenue' => (float) $r['revenue'],
                    'pct' => $branchRevenueTotal > 0 ? round(((float) $r['revenue'] / $branchRevenueTotal) * 100, 1) : 0,
                ];
            }, $branchRevenueRows);

            echo json_encode([
                'ok' => true,
                'branches' => $branches,
                'exchangeRate' => (float) ($rateRow['usd_exchange_rate'] ?? 0),
                'settings' => $settingsRow,
                'topEmployees' => $topEmployees,
                'branchRevenueShares' => $branchRevenueShares,
                'stats' => [
                    'employees' => $totalActive,
                    'attendanceToday' => [
                        'present' => $presentCount,
                        'late' => $lateCount,
                        'absent' => $absentCount,
                        'pending' => max(0, $pendingCount),
                        'presentPct' => $totalActive > 0 ? round($presentCount / $totalActive * 100) : 0,
                        'latePct' => $totalActive > 0 ? round($lateCount / $totalActive * 100) : 0,
                        'absentPct' => $totalActive > 0 ? round($absentCount / $totalActive * 100) : 0,
                    ],
                    'monthlyIncome' => $monthlyIncome,
                    'monthlyExpense' => $monthlyExpense,
                    'profitMarginPct' => $profitMarginPct,
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'branches': {
            $rate = (float) ($pdo->query("SELECT usd_exchange_rate FROM settings ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);
            $rows = $pdo->query("
                SELECT b.id, b.name, b.location, b.status, b.notes,
                       e.id AS managerId, e.full_name AS manager, e.national_id AS nationalId, e.phone_number AS phone, e.birth_date AS birthDate,
                       e.hire_date AS hireDate,
                       e.shift_start AS shiftStart, e.shift_end AS shiftEnd,
                       e.photo, e.documents AS docs,
                       lb.net_profit AS lastBriefProfit, lb.brief_date AS lastBriefDate
                FROM branches b
                LEFT JOIN employees e ON e.branch_id = b.id AND e.is_branch_manager = 1
                LEFT JOIN (
                    SELECT db1.branch_id, (db1.total_income - db1.total_expense) AS net_profit, db1.brief_date
                    FROM daily_briefs db1
                    INNER JOIN (SELECT branch_id, MAX(brief_date) AS max_date FROM daily_briefs GROUP BY branch_id) latest
                        ON latest.branch_id = db1.branch_id AND latest.max_date = db1.brief_date
                ) lb ON lb.branch_id = b.id
                ORDER BY b.created_at DESC
            ")->fetchAll();
            $rows = array_map(function ($r) use ($rate) {
                $r['lastBriefProfit'] = $r['lastBriefProfit'] !== null ? (float) $r['lastBriefProfit'] : null;
                $r['lastBriefProfitUsd'] = ($r['lastBriefProfit'] !== null && $rate > 0) ? round($r['lastBriefProfit'] / $rate, 2) : null;
                return $r;
            }, $rows);
            echo json_encode(['ok' => true, 'branches' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'branch_detail': {
            $branchId = (int) ($_GET['id'] ?? 0);
            $branchStmt = $pdo->prepare("
                SELECT b.id, b.name, b.location, b.status,
                       e.full_name AS manager, e.shift_start AS shiftStart, e.shift_end AS shiftEnd, e.documents AS branchDocs
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
                SELECT e.id, e.full_name AS name, e.job_title, e.shift_type, e.is_branch_manager AS isManager, e.documents,
                       ROUND(SUM(a.status IN ('present','late')) / GREATEST(COUNT(a.id),1) * 100) AS attendanceRate,
                       p.status AS payrollStatus, p.period_month AS payrollMonth, p.period_year AS payrollYear
                FROM employees e
                LEFT JOIN attendance a ON a.employee_id = e.id AND a.attendance_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                LEFT JOIN payroll p ON p.employee_id = e.id AND p.period_month = MONTH(CURDATE()) AND p.period_year = YEAR(CURDATE())
                WHERE e.branch_id = ? AND e.status = 'active'
                GROUP BY e.id, e.full_name, e.job_title, e.shift_type, e.is_branch_manager, e.documents, p.status, p.period_month, p.period_year
                ORDER BY e.is_branch_manager DESC, e.full_name
            ");
            $empStmt->execute([$branchId]);
            $monthNames = ['', 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر'];
            $employees = array_map(function ($r) use ($monthNames) {
                $r['isManager'] = (bool) $r['isManager'];
                $r['attendanceRate'] = $r['attendanceRate'] !== null ? (int) $r['attendanceRate'] : 0;
                $r['shiftTypeText'] = $r['shift_type'] === 'evening' ? 'مسائي' : 'صباحي';
                if ($r['payrollStatus'] === 'delivered') {
                    $r['payrollText'] = 'استلم راتب ' . ($monthNames[(int) $r['payrollMonth']] ?? $r['payrollMonth']) . ' ' . $r['payrollYear'];
                } else {
                    $r['payrollText'] = 'لم يستلم راتب الشهر الحالي بعد';
                }
                unset($r['payrollStatus'], $r['payrollMonth'], $r['payrollYear']);
                return $r;
            }, $empStmt->fetchAll());

            $overallRate = 0;
            if ($employees) {
                $rates = array_column($employees, 'attendanceRate');
                $overallRate = (int) round(array_sum($rates) / count($rates));
            }

            $briefsStmt = $pdo->prepare("
                SELECT db.id, DATE_FORMAT(db.brief_date,'%d/%m/%Y') AS date, db.brief_date AS rawDate, db.total_income AS revenue, db.total_expense AS expense,
                       db.travelers_count AS travelers, db.status, db.note, db.attachment, db.hr_note AS hrNote, db.gm_review_note AS gmNote,
                       COALESCE(se.full_name, 'مدير الفرع') AS sender
                FROM daily_briefs db
                LEFT JOIN employees se ON se.id = db.submitted_by
                WHERE db.branch_id = ? ORDER BY db.brief_date DESC LIMIT 30
            ");
            $briefsStmt->execute([$branchId]);
            $statusAr = [
                'pending' => 'بانتظار المراجعة', 'hr_approved' => 'وافقت HR', 'gm_approved' => 'وافق المسؤول العام',
                'approved' => 'معتمد نهائياً', 'rejected' => 'مرفوض',
            ];
            $bdEntriesStmt = $pdo->prepare("SELECT id, entry_type, amount, description, attachment FROM daily_ledger WHERE branch_id=? AND entry_date=? ORDER BY created_at ASC");
            $bdPrevStmt = $pdo->prepare("SELECT total_income, total_expense FROM daily_briefs WHERE branch_id=? AND brief_date=?");
            $briefs = array_map(function ($r) use ($statusAr, $branchId, $bdEntriesStmt, $bdPrevStmt) {
                $r['revenue'] = (float) $r['revenue'];
                $r['expense'] = (float) $r['expense'];
                $r['profit'] = $r['revenue'] - $r['expense'];
                $r['statusText'] = $statusAr[$r['status']] ?? $r['status'];
                $bdEntriesStmt->execute([$branchId, $r['rawDate']]);
                $r['entries'] = array_map(fn($e) => ['id' => (int) $e['id'], 'type' => $e['entry_type'], 'amount' => (float) $e['amount'], 'note' => $e['description'], 'attachment' => $e['attachment']], $bdEntriesStmt->fetchAll());
                $bdPrevStmt->execute([$branchId, date('Y-m-d', strtotime($r['rawDate'] . ' -1 day'))]);
                $bdPrevRow = $bdPrevStmt->fetch();
                $r['prevDayNetProfit'] = $bdPrevRow ? ((float) $bdPrevRow['total_income'] - (float) $bdPrevRow['total_expense']) : null;
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

        case 'branch_save': {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $manager = trim($_POST['manager'] ?? '');
            $nationalId = trim($_POST['nationalId'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $birthDate = $_POST['birthDate'] ?: null;
            $hireDate = $_POST['hireDate'] ?: null;
            $shiftStart = $_POST['shiftStart'] ?: null;
            $shiftEnd = $_POST['shiftEnd'] ?: null;
            $notes = trim($_POST['notes'] ?? '');
            $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
            $managerPassword = (string) ($_POST['managerPassword'] ?? '');

            if ($name === '' || $manager === '' || $nationalId === '' || $phone === '') {
                echo json_encode(['ok' => false, 'error' => 'اسم الفرع، اسم المسؤول، رقم الهوية، ورقم الهاتف مطلوبة']);
                exit;
            }

            $photoPath = handle_upload('photo', 'photos', ['jpg', 'jpeg', 'png', 'webp']);
            $docsPath = handle_upload('docs', 'documents', ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx']);

            if ($id === 0 && !$photoPath) {
                echo json_encode(['ok' => false, 'error' => 'صورة مسؤول الفرع مطلوبة عند إنشاء فرع جديد']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                if ($id > 0) {
                    $sql = "UPDATE branches SET name=?, notes=?, status=?";
                    $params = [$name, $notes, $status];
                    if ($photoPath) { $sql .= ", photo=?"; $params[] = $photoPath; }
                    $sql .= " WHERE id=?";
                    $params[] = $id;
                    $pdo->prepare($sql)->execute($params);
                    $branchId = $id;
                } else {
                    $stmt = $pdo->prepare("INSERT INTO branches (name, notes, status, photo) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$name, $notes, $status, $photoPath]);
                    $branchId = (int) $pdo->lastInsertId();
                }

                $mgrStmt = $pdo->prepare("SELECT id FROM employees WHERE branch_id=? AND is_branch_manager=1 LIMIT 1");
                $mgrStmt->execute([$branchId]);
                $existingManagerId = $mgrStmt->fetchColumn();

                if ($existingManagerId) {
                    $sql = "UPDATE employees SET full_name=?, national_id=?, phone_number=?, birth_date=?, hire_date=?, shift_start=?, shift_end=?";
                    $params = [$manager, $nationalId, $phone, $birthDate, $hireDate, $shiftStart, $shiftEnd];
                    if ($photoPath) { $sql .= ", photo=?"; $params[] = $photoPath; }
                    if ($docsPath) { $sql .= ", documents=?"; $params[] = $docsPath; }
                    $sql .= " WHERE id=?";
                    $params[] = $existingManagerId;
                    $pdo->prepare($sql)->execute($params);
                    $managerEmployeeId = (int) $existingManagerId;
                } else {
                    $numStmt = $pdo->query("SELECT MAX(CAST(employee_number AS UNSIGNED)) FROM employees WHERE employee_number REGEXP '^[0-9]+$'");
                    $empNumber = (string) max(1001, (int) $numStmt->fetchColumn() + 1);
                    $stmt = $pdo->prepare("INSERT INTO employees (branch_id, employee_number, full_name, national_id, phone_number, birth_date, hire_date, shift_start, shift_end, job_title, photo, documents, is_branch_manager, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'مدير فرع', ?, ?, 1, 'active')");
                    $stmt->execute([$branchId, $empNumber, $manager, $nationalId, $phone, $birthDate, $hireDate, $shiftStart, $shiftEnd, $photoPath, $docsPath]);
                    $managerEmployeeId = (int) $pdo->lastInsertId();

                    if ($managerPassword !== '' && strlen($managerPassword) >= 4) {
                        $hash = password_hash($managerPassword, PASSWORD_DEFAULT);
                        $pdo->prepare("INSERT INTO users (role, username, password_hash, employee_id, branch_id, status) VALUES ('branch_manager', ?, ?, ?, ?, 'active')")
                            ->execute([$empNumber, $hash, $managerEmployeeId, $branchId]);
                    }
                }

                $pdo->commit();
                echo json_encode(['ok' => true, 'branchId' => $branchId]);
            } catch (Throwable $ex) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'error' => $ex->getMessage()]);
            }
            exit;
        }

        case 'branch_delete': {
            $id = (int) ($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM branches WHERE id=?")->execute([$id]);
            echo json_encode(['ok' => true]);
            exit;
        }

        case 'notifications_list': {
            $stmt = $pdo->prepare("SELECT id, title, message, is_read, DATE_FORMAT(created_at,'%d/%m/%Y %H:%i') AS date FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 30");
            $stmt->execute([$hrUser['id']]);
            $rows = $stmt->fetchAll();
            $unread = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
            $unread->execute([$hrUser['id']]);
            echo json_encode(['ok' => true, 'notifications' => $rows, 'unread' => (int) $unread->fetchColumn()], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'notifications_mark_all_read': {
            $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$hrUser['id']]);
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

        case 'employees_full_list': {
            $month = (int) date('n');
            $year = (int) date('Y');
            $rows = $pdo->prepare("
                SELECT e.id, e.full_name AS name, b.name AS branch, e.job_title, e.employee_number,
                       e.rating, e.status, e.is_branch_manager AS isManager, e.base_salary AS baseSalary,
                       e.shift_type AS shiftType,
                       COALESCE(p.bonus, 0) AS bonus, COALESCE(p.deduction, 0) AS deduction, COALESCE(p.late_deduction, 0) AS lateDeduction,
                       (SELECT COUNT(*) FROM requests r2 WHERE r2.employee_id = e.id AND r2.type = 'advance' AND r2.status = 'approved' AND r2.remaining_balance > 0) AS activeAdvanceCount,
                       (SELECT GROUP_CONCAT(padj.note SEPARATOR ' | ') FROM payroll_adjustments padj WHERE padj.employee_id = e.id AND padj.period_month = ? AND padj.period_year = ? AND padj.type = 'deduction' AND padj.note IS NOT NULL) AS adjustmentNote
                FROM employees e
                JOIN branches b ON b.id = e.branch_id
                LEFT JOIN payroll p ON p.employee_id = e.id AND p.period_month = ? AND p.period_year = ?
                ORDER BY e.is_branch_manager DESC, e.full_name
            ");
            $rows->execute([$month, $year, $month, $year]);
            $shiftAr = ['morning' => 'صباحي', 'evening' => 'مسائي'];
            $rows = array_map(function ($r) use ($shiftAr) {
                $r['baseSalary'] = (float) $r['baseSalary'];
                $r['bonus'] = (float) $r['bonus'];
                $r['deduction'] = (float) $r['deduction'];
                $r['shiftTypeText'] = $shiftAr[$r['shiftType']] ?? $r['shiftType'];
                $reasons = [];
                if ((float) $r['lateDeduction'] > 0) $reasons[] = 'تأخير';
                if ((int) $r['activeAdvanceCount'] > 0) $reasons[] = 'سلفة';
                if ($r['adjustmentNote']) $reasons[] = $r['adjustmentNote'];
                $r['deductionReason'] = $r['deduction'] > 0 ? (($reasons ? implode(' + ', array_unique($reasons)) : 'خصم إداري')) : null;
                unset($r['lateDeduction'], $r['activeAdvanceCount'], $r['adjustmentNote'], $r['shiftType']);
                return $r;
            }, $rows->fetchAll());
            echo json_encode(['ok' => true, 'employees' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'employee_profile': {
            $employeeId = (int) ($_GET['employeeId'] ?? 0);
            $stmt = $pdo->prepare("
                SELECT e.*, b.name AS branchName
                FROM employees e JOIN branches b ON b.id = e.branch_id
                WHERE e.id = ?
            ");
            $stmt->execute([$employeeId]);
            $emp = $stmt->fetch();
            if (!$emp) {
                echo json_encode(['ok' => false, 'error' => 'الموظف غير موجود']);
                exit;
            }
            $shiftAr = ['morning' => 'صباحي', 'evening' => 'مسائي'];
            echo json_encode(['ok' => true, 'employee' => [
                'id' => (int) $emp['id'],
                'name' => $emp['full_name'],
                'motherName' => $emp['mother_name'],
                'phoneNumber' => $emp['phone_number'],
                'nationalId' => $emp['national_id'],
                'jobTitle' => $emp['job_title'],
                'branch' => $emp['branchName'],
                'employeeNumber' => $emp['employee_number'],
                'birthDate' => $emp['birth_date'],
                'hireDate' => $emp['hire_date'],
                'shiftType' => $shiftAr[$emp['shift_type']] ?? $emp['shift_type'],
                'shiftStart' => $emp['shift_start'] ? substr($emp['shift_start'], 0, 5) : null,
                'shiftEnd' => $emp['shift_end'] ? substr($emp['shift_end'], 0, 5) : null,
                'baseSalary' => (float) $emp['base_salary'],
                'rating' => (float) $emp['rating'],
                'status' => $emp['status'],
                'isManager' => (bool) $emp['is_branch_manager'],
                'documents' => $emp['documents'],
                'photo' => $emp['photo'],
            ]], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'employee_day_check': {
            $employeeId = (int) ($_GET['employeeId'] ?? 0);
            $date = $_GET['date'] ?? date('Y-m-d');
            $attStmt = $pdo->prepare("SELECT check_in, check_out, status FROM attendance WHERE employee_id=? AND attendance_date=?");
            $attStmt->execute([$employeeId, $date]);
            $att = $attStmt->fetch();

            $dt = DateTime::createFromFormat('Y-m-d', $date) ?: new DateTime();
            $month = (int) $dt->format('n');
            $year = (int) $dt->format('Y');
            $payStmt = $pdo->prepare("SELECT status, (base_salary + bonus - deduction) AS net FROM payroll WHERE employee_id=? AND period_month=? AND period_year=?");
            $payStmt->execute([$employeeId, $month, $year]);
            $pay = $payStmt->fetch();

            echo json_encode([
                'ok' => true,
                'attendance' => $att ? [
                    'checkIn' => $att['check_in'] ? substr($att['check_in'], 0, 5) : null,
                    'checkOut' => $att['check_out'] ? substr($att['check_out'], 0, 5) : null,
                    'status' => attendance_status_ar($att['status']),
                ] : null,
                'payroll' => $pay ? [
                    'delivered' => $pay['status'] === 'delivered',
                    'net' => (float) $pay['net'],
                ] : null,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'employee_attendance_month': {
            $employeeId = (int) ($_GET['employeeId'] ?? 0);
            $month = (int) ($_GET['month'] ?? date('n'));
            $year = (int) ($_GET['year'] ?? date('Y'));
            $monthStart = sprintf('%04d-%02d-01', $year, $month);
            $stmt = $pdo->prepare("SELECT attendance_date, check_in, check_out, status FROM attendance
                WHERE employee_id=? AND attendance_date >= ? AND attendance_date < DATE_ADD(?, INTERVAL 1 MONTH)
                ORDER BY attendance_date");
            $stmt->execute([$employeeId, $monthStart, $monthStart]);
            $rows = array_map(function ($r) {
                return [
                    'date' => $r['attendance_date'],
                    'checkIn' => $r['check_in'] ? substr($r['check_in'], 0, 5) : null,
                    'checkOut' => $r['check_out'] ? substr($r['check_out'], 0, 5) : null,
                    'status' => attendance_status_ar($r['status']),
                ];
            }, $stmt->fetchAll());
            echo json_encode(['ok' => true, 'days' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'rating_save': {
            $employeeId = (int) ($_POST['employeeId'] ?? 0);
            $rating = (float) ($_POST['rating'] ?? 0);
            if ($rating < 0 || $rating > 5) {
                echo json_encode(['ok' => false, 'error' => 'التقييم يجب أن يكون بين 0 و 5']);
                exit;
            }
            $pdo->prepare("UPDATE employees SET rating=? WHERE id=?")->execute([$rating, $employeeId]);
            echo json_encode(['ok' => true]);
            exit;
        }

        case 'attendance': {
            $date = $_GET['date'] ?? date('Y-m-d');
            $stmt = $pdo->prepare("
                SELECT a.id, e.id AS employeeId, e.full_name AS name, b.name AS branch, a.check_in AS checkIn, a.check_out AS checkOut, a.status
                FROM attendance a JOIN employees e ON e.id=a.employee_id JOIN branches b ON b.id=a.branch_id
                WHERE a.attendance_date = ? ORDER BY e.full_name
            ");
            $stmt->execute([$date]);
            $rows = array_map(function ($r) {
                $r['checkIn'] = $r['checkIn'] ? substr($r['checkIn'], 0, 5) : '--:--';
                $r['checkOut'] = $r['checkOut'] ? substr($r['checkOut'], 0, 5) : '--:--';
                $r['status'] = attendance_status_ar($r['status']);
                return $r;
            }, $stmt->fetchAll());
            echo json_encode(['ok' => true, 'attendance' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'attendance_set': {
            $employeeId = (int) ($_POST['employeeId'] ?? 0);
            $date = $_POST['date'] ?? date('Y-m-d');
            $status = in_array($_POST['status'] ?? '', ['present', 'late', 'absent'], true) ? $_POST['status'] : 'present';
            $empStmt = $pdo->prepare("SELECT branch_id, shift_start FROM employees WHERE id=?");
            $empStmt->execute([$employeeId]);
            $empForAttendance = $empStmt->fetch();
            if (!$empForAttendance) {
                echo json_encode(['ok' => false, 'error' => 'الموظف غير موجود']);
                exit;
            }
            $branchIdForEmp = $empForAttendance['branch_id'];
            $existing = $pdo->prepare("SELECT check_in, check_out, status FROM attendance WHERE employee_id=? AND attendance_date=?");
            $existing->execute([$employeeId, $date]);
            $existing = $existing->fetch();
            $oldStatus = $existing['status'] ?? null;
            $checkIn = $existing['check_in'] ?? null;
            $checkOut = $existing['check_out'] ?? null;
            if ($status === 'absent') {
                $checkIn = null;
                $checkOut = null;
            } elseif (!$checkIn) {
                $checkIn = date('H:i:s');
            }
            $pdo->prepare("INSERT INTO attendance (employee_id, branch_id, attendance_date, check_in, check_out, status)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE check_in=VALUES(check_in), check_out=VALUES(check_out), status=VALUES(status)")
                ->execute([$employeeId, $branchIdForEmp, $date, $checkIn, $checkOut, $status]);

            // إلغاء خصم التأخير عند تصحيح الحالة من "متأخر" إلى غير متأخر، إن كان الراتب
            // قد سُلِّم مسبقاً عن شهر هذا اليوم (نعيد احتساب خصم التأخير للشهر بالكامل من
            // سجلات الحضور الحالية بدل طرح يوم واحد، لتفادي أخطاء التقريب التراكمي)
            if ($oldStatus === 'late' && $status !== 'late') {
                $month = (int) date('n', strtotime($date));
                $year = (int) date('Y', strtotime($date));
                $payrollRow = $pdo->prepare("SELECT id, deduction, late_deduction FROM payroll WHERE employee_id=? AND period_month=? AND period_year=? AND status='delivered'");
                $payrollRow->execute([$employeeId, $month, $year]);
                $payrollRow = $payrollRow->fetch();
                if ($payrollRow) {
                    $newLateDeduction = 0.0;
                    $settingsRow = $pdo->query("SELECT late_grace_minutes, late_deduction_per_hour, work_start_time FROM settings ORDER BY id DESC LIMIT 1")->fetch();
                    if ($empForAttendance['shift_start']) { $settingsRow['work_start_time'] = $empForAttendance['shift_start']; }
                    if ($settingsRow && (float) $settingsRow['late_deduction_per_hour'] > 0 && $settingsRow['work_start_time']) {
                        $monthStart = sprintf('%04d-%02d-01', $year, $month);
                        $lateStmt = $pdo->prepare("SELECT check_in FROM attendance WHERE employee_id=? AND status='late' AND attendance_date >= ? AND attendance_date < DATE_ADD(?, INTERVAL 1 MONTH)");
                        $lateStmt->execute([$employeeId, $monthStart, $monthStart]);
                        $grace = (int) $settingsRow['late_grace_minutes'];
                        $deadline = strtotime($settingsRow['work_start_time']) + $grace * 60;
                        $lateMinutes = 0;
                        foreach ($lateStmt->fetchAll(PDO::FETCH_COLUMN) as $ci) {
                            if (!$ci) continue;
                            $diff = (strtotime($ci) - $deadline) / 60;
                            if ($diff > 0) $lateMinutes += $diff;
                        }
                        if ($lateMinutes > 0) {
                            $newLateDeduction = ceil($lateMinutes / 60) * (float) $settingsRow['late_deduction_per_hour'];
                        }
                    }
                    $oldLateDeduction = (float) $payrollRow['late_deduction'];
                    if ($newLateDeduction !== $oldLateDeduction) {
                        $newDeduction = max(0, (float) $payrollRow['deduction'] - $oldLateDeduction + $newLateDeduction);
                        $pdo->prepare("UPDATE payroll SET deduction=?, late_deduction=? WHERE id=?")
                            ->execute([$newDeduction, $newLateDeduction, $payrollRow['id']]);
                    }
                }
            }

            echo json_encode(['ok' => true]);
            exit;
        }

        case 'payroll_window_status': {
            $month = (int) date('n');
            $year = (int) date('Y');
            $stmt = $pdo->prepare("
                SELECT b.id, b.name, pw.expires_at
                FROM branches b
                LEFT JOIN payroll_windows pw ON pw.branch_id = b.id AND pw.period_month=? AND pw.period_year=? AND pw.expires_at > NOW()
                WHERE b.status = 'active'
                ORDER BY b.name
            ");
            $stmt->execute([$month, $year]);
            $branches = array_map(function ($r) {
                return ['id' => (int) $r['id'], 'name' => $r['name'], 'open' => $r['expires_at'] !== null, 'expiresAt' => $r['expires_at']];
            }, $stmt->fetchAll());
            echo json_encode(['ok' => true, 'branches' => $branches], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'salaries': {
            $month = (int) ($_GET['month'] ?? date('n'));
            $year = (int) ($_GET['year'] ?? date('Y'));
            $stmt = $pdo->prepare("
                SELECT e.id AS employeeId, e.full_name AS name, b.id AS branchId, b.name AS branch, e.is_branch_manager AS isManager,
                       p.id AS payrollId, COALESCE(p.base_salary, e.base_salary) AS base, COALESCE(p.bonus,0) AS bonus, COALESCE(p.deduction,0) AS deduction,
                       (COALESCE(p.base_salary, e.base_salary) + COALESCE(p.bonus,0) - COALESCE(p.deduction,0)) AS net,
                       COALESCE(p.status, 'pending') AS status,
                       adv.approved_monthly_deduction AS advanceMonthly, adv.remaining_balance AS advanceRemaining,
                       pw.expires_at AS windowExpiresAt
                FROM employees e
                JOIN branches b ON b.id = e.branch_id
                LEFT JOIN payroll p ON p.employee_id = e.id AND p.period_month=? AND p.period_year=?
                LEFT JOIN requests adv ON adv.employee_id = e.id AND adv.type='advance' AND adv.status='approved' AND adv.remaining_balance > 0
                LEFT JOIN payroll_windows pw ON pw.branch_id = e.branch_id AND pw.period_month=? AND pw.period_year=? AND pw.expires_at > NOW()
                WHERE e.status='active'
                ORDER BY (COALESCE(p.status, 'pending') = 'delivered') ASC, e.full_name
            ");
            $stmt->execute([$month, $year, $month, $year]);
            $rows = array_map(function ($r) {
                $r['base'] = (float) $r['base'];
                $r['bonus'] = (float) $r['bonus'];
                $r['deduction'] = (float) $r['deduction'];
                $r['net'] = (float) $r['net'];
                $r['hasAdvance'] = $r['advanceMonthly'] !== null;
                $r['advanceMonthly'] = $r['advanceMonthly'] !== null ? (float) $r['advanceMonthly'] : null;
                $r['advanceRemaining'] = $r['advanceRemaining'] !== null ? (float) $r['advanceRemaining'] : null;
                $r['statusRaw'] = $r['status'];
                $r['status'] = payroll_status_ar($r['status']);
                $r['windowOpen'] = $r['windowExpiresAt'] !== null;
                unset($r['windowExpiresAt']);
                return $r;
            }, $stmt->fetchAll());
            echo json_encode(['ok' => true, 'salaries' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'salary_deliver': {
            $employeeId = (int) ($_POST['employeeId'] ?? 0);
            $month = (int) date('n');
            $year = (int) date('Y');

            $empStmt = $pdo->prepare("SELECT branch_id, full_name, base_salary, shift_start FROM employees WHERE id=?");
            $empStmt->execute([$employeeId]);
            $emp = $empStmt->fetch();
            if (!$emp) {
                echo json_encode(['ok' => false, 'error' => 'الموظف غير موجود']);
                exit;
            }
            $branchId = (int) $emp['branch_id'];

            $winStmt = $pdo->prepare("SELECT expires_at FROM payroll_windows WHERE branch_id=? AND period_month=? AND period_year=?");
            $winStmt->execute([$branchId, $month, $year]);
            $expiresAt = $winStmt->fetchColumn();
            if (!$expiresAt || strtotime($expiresAt) < time()) {
                echo json_encode(['ok' => false, 'error' => 'صلاحية تسليم الرواتب لفرع هذا الموظف مغلقة، يجب أن يفتحها المسؤول العام أولاً']);
                exit;
            }

            $existing = $pdo->prepare("SELECT * FROM payroll WHERE employee_id=? AND period_month=? AND period_year=?");
            $existing->execute([$employeeId, $month, $year]);
            $existing = $existing->fetch();
            if ($existing && $existing['status'] === 'delivered') {
                echo json_encode(['ok' => false, 'error' => 'تم تسليم راتب هذا الموظف عن هذا الشهر مسبقاً']);
                exit;
            }

            $baseSalary = $existing ? (float) $existing['base_salary'] : (float) $emp['base_salary'];
            $bonus = $existing ? (float) $existing['bonus'] : 0.0;
            $deduction = $existing ? (float) $existing['deduction'] : 0.0;

            // خصم التأخير عن الشهر الحالي
            $lateDeduction = 0.0;
            $settingsRow = $pdo->query("SELECT late_grace_minutes, late_deduction_per_hour, work_start_time FROM settings ORDER BY id DESC LIMIT 1")->fetch();
            if ($emp['shift_start']) { $settingsRow['work_start_time'] = $emp['shift_start']; }
            if ($settingsRow && (float) $settingsRow['late_deduction_per_hour'] > 0 && $settingsRow['work_start_time']) {
                $lateStmt = $pdo->prepare("SELECT check_in FROM attendance WHERE employee_id=? AND status='late' AND attendance_date >= ? AND attendance_date < DATE_ADD(?, INTERVAL 1 MONTH)");
                $monthStart = sprintf('%04d-%02d-01', $year, $month);
                $lateStmt->execute([$employeeId, $monthStart, $monthStart]);
                $grace = (int) $settingsRow['late_grace_minutes'];
                $deadline = strtotime($settingsRow['work_start_time']) + $grace * 60;
                $lateMinutes = 0;
                foreach ($lateStmt->fetchAll(PDO::FETCH_COLUMN) as $checkIn) {
                    if (!$checkIn) continue;
                    $diff = (strtotime($checkIn) - $deadline) / 60;
                    if ($diff > 0) $lateMinutes += $diff;
                }
                if ($lateMinutes > 0) {
                    $lateDeduction = ceil($lateMinutes / 60) * (float) $settingsRow['late_deduction_per_hour'];
                    $deduction += $lateDeduction;
                }
            }

            // خصم السلفة الشهري النشط
            $advStmt = $pdo->prepare("SELECT id, approved_monthly_deduction, remaining_balance FROM requests WHERE employee_id=? AND type='advance' AND status='approved' AND remaining_balance > 0 ORDER BY id ASC LIMIT 1");
            $advStmt->execute([$employeeId]);
            $adv = $advStmt->fetch();
            if ($adv) {
                $advanceCut = min((float) $adv['approved_monthly_deduction'], (float) $adv['remaining_balance']);
                $deduction += $advanceCut;
                $pdo->prepare("UPDATE requests SET remaining_balance = remaining_balance - ? WHERE id=?")->execute([$advanceCut, $adv['id']]);
            }

            $stmt = $pdo->prepare("INSERT INTO payroll (employee_id, branch_id, period_month, period_year, base_salary, bonus, deduction, late_deduction, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'delivered')
                ON DUPLICATE KEY UPDATE base_salary=VALUES(base_salary), bonus=VALUES(bonus), deduction=VALUES(deduction), late_deduction=VALUES(late_deduction), status='delivered'");
            $stmt->execute([$employeeId, $branchId, $month, $year, $baseSalary, $bonus, $deduction, $lateDeduction]);

            $net = $baseSalary + $bonus - $deduction;
            $msg = 'تم تسليم راتب شهر ' . $month . '/' . $year . ' بصافي ' . number_format($net) . ' دينار للموظف ' . $emp['full_name'];
            $notifyUsers = $pdo->prepare("SELECT id FROM users WHERE employee_id=? UNION SELECT id FROM users WHERE branch_id=? AND role='branch_manager' UNION SELECT id FROM users WHERE role='hr'");
            $notifyUsers->execute([$employeeId, $branchId]);
            foreach ($notifyUsers->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, 'تم تسليم الراتب', ?)")->execute([$uid, $msg]);
            }

            echo json_encode(['ok' => true, 'net' => $net]);
            exit;
        }

        case 'briefs': {
            $branchId = (int) ($_GET['branchId'] ?? 0);
            $date = $_GET['date'] ?? '';
            if ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = '';
            $pendingCount = (int) $pdo->query("SELECT COUNT(*) FROM daily_briefs WHERE hr_decision='pending' AND status NOT IN ('approved','rejected')")->fetchColumn();

            if ($branchId <= 0) {
                echo json_encode(['ok' => true, 'briefs' => [], 'pendingCount' => $pendingCount], JSON_UNESCAPED_UNICODE);
                exit;
            }

            $sql = "
                SELECT db.id, e.full_name AS sender, e.job_title AS senderRole, b.id AS branchId, b.name AS branch,
                       DATE_FORMAT(db.brief_date, '%d/%m/%Y') AS date, db.brief_date AS rawDate,
                       db.total_income AS revenue, db.total_expense AS expenses, db.travelers_count AS travelersCount, db.note, db.attachment,
                       db.status, db.hr_decision, db.gm_decision, db.hr_note AS hrNote, db.gm_review_note AS gmNote
                FROM daily_briefs db
                JOIN branches b ON b.id = db.branch_id
                LEFT JOIN employees e ON e.id = db.submitted_by
                WHERE db.branch_id = ?";
            $params = [$branchId];
            if ($date !== '') { $sql .= " AND db.brief_date = ?"; $params[] = $date; }
            $sql .= " ORDER BY db.brief_date DESC LIMIT 60";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $statusAr = [
                'pending' => 'بانتظار مراجعتك',
                'hr_approved' => 'وافقت أنت — بانتظار موافقة المسؤول العام أيضاً',
                'gm_approved' => 'وافق المسؤول العام — بانتظار مراجعتك أنت',
                'approved' => 'معتمد نهائياً (وافق الطرفان)',
                'rejected' => 'مرفوض',
            ];
            $today = date('Y-m-d');
            $entriesStmt = $pdo->prepare("SELECT id, entry_type, amount, description, attachment FROM daily_ledger WHERE branch_id=? AND entry_date=? ORDER BY created_at ASC");
            $prevStmt = $pdo->prepare("SELECT total_income, total_expense FROM daily_briefs WHERE branch_id=? AND brief_date=?");
            $rows = array_map(function ($r) use ($statusAr, $today, $entriesStmt, $prevStmt) {
                $r['revenue'] = (float) $r['revenue'];
                $r['expenses'] = (float) $r['expenses'];
                $r['travelersCount'] = (int) $r['travelersCount'];
                $r['netProfit'] = $r['revenue'] - $r['expenses'];
                $r['sender'] = $r['sender'] ?: 'مدير الفرع';
                $r['senderRole'] = $r['senderRole'] ?: 'مدير فرع';
                $r['canReview'] = $r['hr_decision'] === 'pending' && !in_array($r['status'], ['approved', 'rejected'], true);
                $r['statusText'] = $statusAr[$r['status']] ?? $r['status'];
                $r['isToday'] = $r['rawDate'] === $today;
                $entriesStmt->execute([$r['branchId'], $r['rawDate']]);
                $r['entries'] = array_map(fn($e) => ['id' => (int) $e['id'], 'type' => $e['entry_type'], 'amount' => (float) $e['amount'], 'note' => $e['description'], 'attachment' => $e['attachment']], $entriesStmt->fetchAll());
                $prevStmt->execute([$r['branchId'], date('Y-m-d', strtotime($r['rawDate'] . ' -1 day'))]);
                $prevRow = $prevStmt->fetch();
                $r['prevDayNetProfit'] = $prevRow ? ((float) $prevRow['total_income'] - (float) $prevRow['total_expense']) : null;
                return $r;
            }, $stmt->fetchAll());
            echo json_encode(['ok' => true, 'briefs' => $rows, 'branchId' => $branchId, 'pendingCount' => $pendingCount], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'brief_review': {
            $id = (int) ($_POST['id'] ?? 0);
            $decision = ($_POST['decision'] ?? '') === 'approved' ? 'approved' : 'rejected';
            $hrNote = trim($_POST['hrNote'] ?? '') ?: ($decision === 'approved' ? 'تمت الموافقة من قبل الموارد البشرية' : 'تم الرفض من قبل الموارد البشرية');

            $briefRow = $pdo->prepare("SELECT branch_id, gm_decision, submitted_by FROM daily_briefs WHERE id=? AND hr_decision='pending' AND status NOT IN ('approved','rejected')");
            $briefRow->execute([$id]);
            $brief = $briefRow->fetch();
            if (!$brief) {
                echo json_encode(['ok' => false, 'error' => 'هذا الإيجاز ليس بانتظار مراجعتك']);
                exit;
            }
            $newStatus = brief_overall_status($decision, $brief['gm_decision']);

            $pdo->prepare("UPDATE daily_briefs SET hr_decision=?, status=?, hr_note=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?")
                ->execute([$decision, $newStatus, $hrNote, $hrUser['id'], $id]);

            $briefBranchId = $brief['branch_id'];
            if ($newStatus === 'approved') {
                $msg = 'تم اعتماد إيجاز اليوم نهائياً بعد موافقة الموارد البشرية والمسؤول العام' . ($hrNote ? (' — ' . $hrNote) : '');
            } elseif ($newStatus === 'rejected') {
                $msg = 'رفضت الموارد البشرية إيجاز اليوم — ' . $hrNote;
            } else {
                $msg = $decision === 'approved'
                    ? 'وافقت الموارد البشرية على إيجاز اليوم، بانتظار موافقة المسؤول العام أيضاً — ' . $hrNote
                    : 'رفضت الموارد البشرية إيجاز اليوم — ' . $hrNote;
            }
            $mgrUids = $pdo->prepare("SELECT id FROM users WHERE branch_id=? AND role='branch_manager' UNION SELECT id FROM users WHERE employee_id=? AND role='employee'");
            $mgrUids->execute([$briefBranchId, $brief['submitted_by']]);
            foreach ($mgrUids->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, 'رد على الإيجاز', ?)")->execute([$uid, $msg]);
            }
            if ($newStatus === 'hr_approved') {
                $gmUids = $pdo->query("SELECT id FROM users WHERE role='general_manager'")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($gmUids as $uid) {
                    $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, 'إيجاز بانتظار اعتمادك', ?)")
                        ->execute([$uid, 'إيجاز فرع بانتظار اعتمادك النهائي بعد موافقة HR']);
                }
            }

            echo json_encode(['ok' => true]);
            exit;
        }

        case 'requests': {
            // الموارد البشرية لا ترى إلا الطلبات التي وافق عليها مدير الفرع أولاً (أو المُنجَزة سابقاً للسجل)
            $stmt = $pdo->query("
                SELECT r.id, e.full_name AS name, b.name AS branch, r.type, r.details, r.amount, r.date_from, r.date_to,
                       DATE_FORMAT(r.created_at, '%d/%m/%Y') AS date, r.status, r.branch_review_note AS branchNote
                FROM requests r JOIN employees e ON e.id = r.employee_id JOIN branches b ON b.id = r.branch_id
                WHERE r.status IN ('branch_approved','approved','rejected')
                ORDER BY r.created_at DESC LIMIT 50
            ");
            $rows = array_map(function ($r) {
                $details = $r['details'];
                if ($r['type'] === 'advance' && $r['amount']) {
                    $details = number_format((float) $r['amount']) . ' د.ع' . ($details ? ' - ' . $details : '');
                } elseif ($r['type'] === 'leave' && $r['date_from']) {
                    $details = $r['date_from'] . ' إلى ' . $r['date_to'] . ($details ? ' - ' . $details : '');
                }
                return [
                    'id' => $r['id'],
                    'name' => $r['name'],
                    'branch' => $r['branch'],
                    'type' => request_type_ar($r['type']),
                    'rawType' => $r['type'],
                    'amount' => $r['amount'] ? (float) $r['amount'] : null,
                    'details' => $details ?: '-',
                    'date' => $r['date'],
                    'status' => request_status_ar($r['status']),
                    'canReview' => $r['status'] === 'branch_approved',
                    'branchNote' => $r['branchNote'],
                ];
            }, $stmt->fetchAll());
            echo json_encode(['ok' => true, 'requests' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'request_review': {
            $id = (int) ($_POST['id'] ?? 0);
            $decision = ($_POST['decision'] ?? '') === 'approved' ? 'approved' : 'rejected';
            $hrNote = trim($_POST['hrNote'] ?? '');
            $monthlyDeduction = (float) ($_POST['monthlyDeduction'] ?? 0);

            $reqRow = $pdo->prepare("SELECT type, amount FROM requests WHERE id=? AND status='branch_approved'");
            $reqRow->execute([$id]);
            $reqRow = $reqRow->fetch();
            if (!$reqRow) {
                echo json_encode(['ok' => false, 'error' => 'هذا الطلب ليس بانتظار مراجعة الموارد البشرية']);
                exit;
            }
            if ($decision === 'approved' && $reqRow['type'] === 'advance' && $monthlyDeduction <= 0) {
                echo json_encode(['ok' => false, 'error' => 'الرجاء تحديد قيمة الخصم الشهري للسلفة قبل الموافقة']);
                exit;
            }

            if ($decision === 'approved' && $reqRow['type'] === 'advance') {
                $stmt = $pdo->prepare("UPDATE requests SET status=?, hr_reviewed_by=?, hr_review_note=?, hr_reviewed_at=NOW(), approved_monthly_deduction=?, remaining_balance=? WHERE id=? AND status='branch_approved'");
                $stmt->execute([$decision, $hrUser['id'], $hrNote, $monthlyDeduction, $reqRow['amount'], $id]);
            } else {
                $stmt = $pdo->prepare("UPDATE requests SET status=?, hr_reviewed_by=?, hr_review_note=?, hr_reviewed_at=NOW() WHERE id=? AND status='branch_approved'");
                $stmt->execute([$decision, $hrUser['id'], $hrNote, $id]);
            }
            if ($stmt->rowCount() === 0) {
                echo json_encode(['ok' => false, 'error' => 'هذا الطلب ليس بانتظار مراجعة الموارد البشرية']);
                exit;
            }

            $empRow = $pdo->prepare("SELECT employee_id, branch_id, type FROM requests WHERE id=?");
            $empRow->execute([$id]);
            $empRow = $empRow->fetch();
            if ($empRow) {
                $msg = ($decision === 'approved' ? 'وافقت الموارد البشرية نهائياً على طلب ' : 'رفضت الموارد البشرية طلب ') . request_type_ar($empRow['type']) . ($hrNote ? (' — ' . $hrNote) : '');
                $uids = $pdo->prepare("SELECT id FROM users WHERE employee_id=? UNION SELECT id FROM users WHERE branch_id=? AND role='branch_manager'");
                $uids->execute([$empRow['employee_id'], $empRow['branch_id']]);
                foreach ($uids->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                    $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, 'رد نهائي على طلب', ?)")->execute([$uid, $msg]);
                }
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        case 'exchange_update': {
            $rate = (float) ($_POST['rate'] ?? 0);
            if ($rate > 0) {
                $pdo->prepare("UPDATE settings SET usd_exchange_rate=? ORDER BY id DESC LIMIT 1")->execute([$rate]);
                $pdo->prepare("INSERT INTO exchange_rate_history (rate, updated_by) VALUES (?, ?)")->execute([$rate, $hrUser['username']]);
                echo json_encode(['ok' => true]);
            } else {
                echo json_encode(['ok' => false, 'error' => 'الرجاء إدخال قيمة صحيحة']);
            }
            exit;
        }

        case 'rate_history': {
            $rows = $pdo->query("SELECT DATE_FORMAT(created_at,'%d/%m/%Y %H:%i') AS date, rate, updated_by AS `by` FROM exchange_rate_history ORDER BY created_at DESC LIMIT 15")->fetchAll();
            echo json_encode(['ok' => true, 'history' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'settings_save': {
            $companyName = trim($_POST['companyName'] ?? '');
            $companyEmail = trim($_POST['companyEmail'] ?? '');
            $workStart = $_POST['workStart'] ?? '09:00';
            $workEnd = $_POST['workEnd'] ?? '17:00';
            $lateGrace = (int) ($_POST['lateGrace'] ?? 15);
            $lateDeduction = (float) ($_POST['lateDeduction'] ?? 0);
            $logoPath = handle_upload('logo', 'branding', ['jpg', 'jpeg', 'png', 'webp']);
            if ($logoPath) {
                $pdo->prepare("UPDATE settings SET company_name=?, company_email=?, work_start_time=?, work_end_time=?, late_grace_minutes=?, late_deduction_per_hour=?, company_logo=? ORDER BY id DESC LIMIT 1")
                    ->execute([$companyName, $companyEmail, $workStart, $workEnd, $lateGrace, $lateDeduction, $logoPath]);
            } else {
                $pdo->prepare("UPDATE settings SET company_name=?, company_email=?, work_start_time=?, work_end_time=?, late_grace_minutes=?, late_deduction_per_hour=? ORDER BY id DESC LIMIT 1")
                    ->execute([$companyName, $companyEmail, $workStart, $workEnd, $lateGrace, $lateDeduction]);
            }
            echo json_encode(['ok' => true, 'logo' => $logoPath]);
            exit;
        }

        case 'report': {
            $type = $_GET['type'] ?? 'attendance';
            $from = $_GET['from'] ?? date('Y-m-01');
            $to = $_GET['to'] ?? date('Y-m-d');
            $branch = (int) ($_GET['branch'] ?? 0);
            echo json_encode(['ok' => true] + report_data($pdo, $type, $from, $to, $branch), JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'report_download': {
            $type = $_GET['type'] ?? 'attendance';
            $from = $_GET['from'] ?? date('Y-m-01');
            $to = $_GET['to'] ?? date('Y-m-d');
            $branch = (int) ($_GET['branch'] ?? 0);
            $data = report_data($pdo, $type, $from, $to, $branch);

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="report_' . $type . '_' . date('Ymd_His') . '.csv"');
            echo "\xEF\xBB\xBF";
            $out = fopen('php://output', 'w');
            foreach ($data as $section => $rows) {
                if (empty($rows)) {
                    continue;
                }
                fputcsv($out, [$section]);
                fputcsv($out, array_keys($rows[0]));
                foreach ($rows as $row) {
                    fputcsv($out, $row);
                }
                fputcsv($out, []);
            }
            fclose($out);
            exit;
        }
    }

    echo json_encode(['ok' => false, 'error' => 'unknown action']);
    exit;
    } catch (Throwable $ex) {
        log_error($pdo, $action, 'hr', $hrUser['id'], $ex->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'حدث خطأ غير متوقع في الخادم — تأكد من تشغيل migrate.php على قاعدة البيانات']);
        exit;
    }
}

/**
 * يبني بيانات التقرير الحقيقية بنفس شكل الحقول التي تتوقعها الواجهة الأصلية.
 */
function report_data(PDO $pdo, string $type, string $from, string $to, int $branch): array
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
        $rows = array_map(function ($r) {
            $r['checkIn'] = $r['checkIn'] ? substr($r['checkIn'], 0, 5) : '--:--';
            $r['checkOut'] = $r['checkOut'] ? substr($r['checkOut'], 0, 5) : '--:--';
            $r['status'] = attendance_status_ar($r['status']);
            return $r;
        }, $stmt->fetchAll());
        $result['attendance'] = $rows;
    }

    if ($type === 'salaries' || $type === 'all') {
        $sql = "SELECT e.full_name AS name, b.name AS branch, p.base_salary AS base, p.bonus, p.deduction,
                       (p.base_salary + p.bonus - p.deduction) AS net
                FROM payroll p JOIN employees e ON e.id=p.employee_id JOIN branches b ON b.id=p.branch_id
                WHERE DATE(p.created_at) BETWEEN ? AND ?";
        $params = [$from, $to];
        if ($branch > 0) { $sql .= " AND p.branch_id = ?"; $params[] = $branch; }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = array_map(function ($r) {
            foreach (['base', 'bonus', 'deduction', 'net'] as $k) {
                $r[$k] = number_format((float) $r[$k]);
            }
            return $r;
        }, $stmt->fetchAll());
        $result['salaries'] = $rows;
    }

    if ($type === 'briefing' || $type === 'all') {
        $sql = "SELECT b.name AS branch, DATE_FORMAT(db.brief_date,'%d/%m/%Y') AS date,
                       db.total_income AS revenue, db.total_expense AS expense,
                       (db.total_income - db.total_expense) AS profit,
                       db.hr_note AS hrNote, db.gm_review_note AS gmNote, db.status,
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
        $rows = array_map(function ($r) use ($briefStatusAr) {
            foreach (['revenue', 'expense', 'profit'] as $k) {
                $r[$k] = number_format((float) $r[$k]);
            }
            $r['hrNote'] = $r['hrNote'] ?: '-';
            $r['gmNote'] = $r['gmNote'] ?: '-';
            $r['statusText'] = $briefStatusAr[$r['status']] ?? $r['status'];
            return $r;
        }, $stmt->fetchAll());
        $result['briefing'] = $rows;
    }

    return $result;
}
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>نظام إدارة الموارد البشرية - HR</title>
    <link rel="manifest" href="manifest.php?app=hr">
    <meta name="theme-color" content="#006b73">
    <link rel="apple-touch-icon" href="icons/icon-192.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        /* ============================================================
           الأنماط الأساسية
           ============================================================ */
        :root {
            --primary: #006b73;
            --primary-light: #0A8A94;
            --primary-dark: #004b52;
            --primary-gradient: linear-gradient(135deg, #006b73 0%, #0A8A94 100%);
            --accent: #c99a3d;
            --bg: #F0F4F8;
            --bg-card: #FFFFFF;
            --text-primary: #1A2E35;
            --text-secondary: #4A6A78;
            --text-muted: #8AA0B0;
            --shadow-sm: 0 2px 8px rgba(0,107,115,0.04);
            --shadow-md: 0 4px 20px rgba(0,107,115,0.06);
            --shadow-lg: 0 8px 40px rgba(0,107,115,0.08);
            --shadow-xl: 0 12px 56px rgba(0,107,115,0.10);
            --radius-sm: 8px;
            --radius-md: 14px;
            --radius-lg: 20px;
            --radius-full: 9999px;
            --font-family: 'IBM Plex Sans Arabic', 'Tajawal', sans-serif;
            --transition-base: 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --header-height: 70px;
            --sidebar-width: 260px;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: var(--font-family);
            background: var(--bg);
            color: var(--text-primary);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
            font-size: 14px;
            display: flex;
        }

        .hidden { display: none !important; }

        /* ============================================================
           الشريط الجانبي
           ============================================================ */
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
        .sidebar .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            margin-bottom: 16px;
        }
        .sidebar .brand .logo {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-md);
            background: rgba(255,255,255,0.14);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 20px;
            font-weight: 900;
            flex-shrink: 0;
        }
        .sidebar .brand .name {
            font-size: 18px;
            font-weight: 900;
            color: #fff;
        }
        .sidebar .brand .name span {
            color: #C9D18F;
        }
        .sidebar .brand .version {
            font-size: 9px;
            color: rgba(255,255,255,0.55);
            font-weight: 400;
            display: block;
        }

        .sidebar .nav-menu {
            flex: 1;
            display: flex;
            flex-direction: column;
            gap: 2px;
        }
        .sidebar .nav-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 14px;
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: var(--transition-base);
            color: rgba(255,255,255,0.75);
            font-weight: 600;
            font-size: 13px;
            border: none;
            background: transparent;
            width: 100%;
            font-family: var(--font-family);
            text-align: right;
        }
        .sidebar .nav-item:hover {
            background: rgba(255,255,255,0.08);
            color: #fff;
        }
        .sidebar .nav-item.active {
            background: rgba(255,255,255,0.16);
            color: #fff;
        }
        .sidebar .nav-item i {
            width: 20px;
            font-size: 16px;
            text-align: center;
            flex-shrink: 0;
        }
        .sidebar .nav-item .badge {
            margin-right: auto;
            font-size: 9px;
            font-weight: 800;
            padding: 2px 8px;
            border-radius: var(--radius-full);
            background: #EF4444;
            color: #fff;
        }
        .sidebar .nav-item .badge.success {
            background: #10B981;
        }
        .sidebar .nav-item .badge.warning {
            background: #F59E0B;
        }

        .sidebar .nav-divider {
            height: 1px;
            background: rgba(255,255,255,0.1);
            margin: 8px 0;
        }

        .sidebar .user-info {
            padding-top: 16px;
            border-top: 1px solid rgba(255,255,255,0.1);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .sidebar .user-info .avatar {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-full);
            background: rgba(255,255,255,0.14);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 18px;
            font-weight: 900;
            flex-shrink: 0;
        }
        .sidebar .user-info .info .name {
            font-size: 14px;
            font-weight: 800;
            color: #fff;
        }
        .sidebar .user-info .info .role {
            font-size: 11px;
            color: rgba(255,255,255,0.6);
            font-weight: 400;
        }
        .sidebar .user-info .logout-btn {
            margin-right: auto;
            background: none;
            border: none;
            color: rgba(255,255,255,0.6);
            cursor: pointer;
            font-size: 18px;
            transition: var(--transition-base);
            padding: 4px 8px;
            border-radius: var(--radius-full);
        }
        .sidebar .user-info .logout-btn:hover {
            color: #EF4444;
            background: rgba(239,68,68,0.08);
        }

        /* ============================================================
           المحتوى الرئيسي
           ============================================================ */
        .main-content {
            flex: 1;
            margin-right: var(--sidebar-width);
            padding: 24px 32px 40px;
            min-height: 100vh;
        }

        .top-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(0,107,115,0.04);
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .top-header .page-title h2 {
            font-size: 22px;
            font-weight: 800;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .top-header .page-title h2 i { color: var(--primary); }
        .top-header .page-title .sub {
            font-size: 13px;
            color: var(--text-muted);
            font-weight: 400;
            display: block;
        }
        .top-header .header-actions {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .top-header .header-actions .date-display {
            font-size: 13px;
            color: var(--text-secondary);
            font-weight: 500;
            background: var(--bg-card);
            padding: 8px 16px;
            border-radius: var(--radius-md);
            border: 1px solid rgba(0,107,115,0.04);
            box-shadow: var(--shadow-sm);
        }
        .top-header .header-actions .date-display i { color: var(--primary); margin-left: 6px; }

        /* ============================================================
           البطاقات الإحصائية
           ============================================================ */
        .ring-stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
            margin-bottom: 20px;
        }
        .ring-stat-card {
            background: var(--bg-card);
            border-radius: var(--radius-md);
            padding: 14px 16px;
            border: 1px solid rgba(0,107,115,0.04);
            box-shadow: var(--shadow-sm);
            display: flex;
            align-items: center;
            gap: 14px;
            transition: var(--transition-base);
        }
        .ring-stat-card:hover { box-shadow: var(--shadow-md); }
        .ring-chart {
            width: 66px;
            height: 66px;
            min-width: 66px;
            border-radius: 50%;
            position: relative;
            background: conic-gradient(#E5E7EB 0% 100%);
        }
        .ring-chart .ring-center {
            position: absolute;
            inset: 9px;
            border-radius: 50%;
            background: var(--bg-card);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            line-height: 1.1;
        }
        .ring-chart .ring-center span {
            font-size: 14px;
            font-weight: 900;
            color: var(--text-primary);
        }
        .ring-chart .ring-center small {
            font-size: 8.5px;
            color: var(--text-muted);
            font-weight: 500;
        }
        .ring-info {
            display: flex;
            flex-direction: column;
            gap: 6px;
            min-width: 0;
        }
        .ring-title {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .ring-title i { color: var(--primary); font-size: 11px; }
        .ring-legend {
            display: flex;
            flex-direction: column;
            gap: 3px;
            font-size: 11px;
            color: var(--text-secondary);
        }
        .ring-legend .legend-item {
            display: flex;
            align-items: center;
            gap: 5px;
            white-space: nowrap;
        }
        .ring-legend .legend-item b {
            color: var(--text-primary);
            font-weight: 800;
        }
        .ring-legend .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }

        /* ============================================================
           أفضل 3 موظفين حضور
           ============================================================ */
        .top-employees {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 4px;
            margin-bottom: 10px;
            max-width: 260px;
        }
        .top-employee-card {
            background: var(--bg-card);
            border-radius: var(--radius-sm);
            padding: 5px 4px;
            border: 1px solid rgba(0,107,115,0.04);
            box-shadow: var(--shadow-sm);
            text-align: center;
            transition: var(--transition-base);
            position: relative;
            overflow: hidden;
        }
        .top-employee-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }
        .top-employee-card .rank {
            font-size: 11px;
            margin-bottom: 2px;
        }
        .top-employee-card .avatar {
            width: 20px;
            height: 20px;
            border-radius: var(--radius-full);
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 9px;
            font-weight: 900;
            margin: 0 auto 3px;
        }
        .top-employee-card .name {
            font-size: 8px;
            font-weight: 800;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .top-employee-card .branch {
            font-size: 7px;
            color: var(--text-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .top-employee-card .attendance-rate {
            font-size: 10px;
            font-weight: 900;
            color: #059669;
            margin-top: 2px;
        }
        .top-employee-card .attendance-label {
            font-size: 6px;
            color: var(--text-muted);
            font-weight: 400;
        }
        .top-employee-card .badge-gold {
            position: absolute;
            top: 2px;
            left: 2px;
            font-size: 6px;
            font-weight: 700;
            padding: 1px 4px;
            border-radius: var(--radius-full);
            background: var(--accent);
            color: #fff;
        }
        .top-employee-card .medal {
            font-size: 10px;
        }
        .top-employee-card.gold {
            border: 2px solid var(--accent);
            background: rgba(201,154,61,0.03);
        }
        .top-employee-card.silver {
            border: 2px solid #C0C0C0;
            background: rgba(192,192,192,0.03);
        }
        .top-employee-card.bronze {
            border: 2px solid #CD7F32;
            background: rgba(205,127,50,0.03);
        }

        /* ============================================================
           الأسهم اليومية
           ============================================================ */
        .stocks-section {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 20px 24px;
            margin-bottom: 24px;
            border: 1px solid rgba(0,107,115,0.04);
            box-shadow: var(--shadow-sm);
        }
        .stocks-section .stocks-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
        }
        .stocks-section .stocks-header h4 {
            font-size: 16px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .stocks-section .stocks-header h4 i { color: var(--accent); }
        .stocks-section .stocks-header .update-time {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 400;
        }
        .stocks-chart {
            display: flex;
            align-items: flex-end;
            justify-content: center;
            gap: 18px;
            height: 110px;
            padding: 22px 4px 8px;
            overflow-x: auto;
        }
        .branch-bar-wrap {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-end;
            height: 100%;
            min-width: 60px;
            position: relative;
            cursor: default;
        }
        .branch-bar {
            width: 20px;
            border-radius: 2px;
            background: var(--primary-gradient);
            min-height: 4px;
            transition: height 0.6s ease;
            opacity: 0.85;
        }
        .branch-bar-wrap:hover .branch-bar { opacity: 1; }
        .branch-bar-pct {
            font-size: 10px;
            font-weight: 800;
            color: var(--text-secondary);
            margin-bottom: 4px;
            white-space: nowrap;
        }
        .branch-bar-label {
            position: absolute;
            bottom: -20px;
            font-size: 9.5px;
            color: var(--text-muted);
            font-weight: 500;
            white-space: nowrap;
            max-width: 70px;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .stocks-summary {
            display: flex;
            gap: 24px;
            margin-top: 16px;
            padding-top: 14px;
            border-top: 1px solid rgba(0,107,115,0.04);
            flex-wrap: wrap;
        }
        .stocks-summary .summary-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 13px;
            font-weight: 600;
        }
        .stocks-summary .summary-item .dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }
        .stocks-summary .summary-item .dot.up { background: #10B981; }
        .stocks-summary .summary-item .dot.down { background: #EF4444; }
        .stocks-summary .summary-item .value { font-weight: 800; color: var(--text-primary); }

        /* ============================================================
           صندوق سعر الصرف
           ============================================================ */
        .exchange-rate-box {
            background: linear-gradient(135deg, #004b52, #006b73);
            border-radius: var(--radius-lg);
            padding: 16px 24px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            color: #fff;
        }
        .exchange-rate-box .rate-info {
            display: flex;
            align-items: center;
            gap: 16px;
        }
        .exchange-rate-box .rate-info .rate-icon {
            font-size: 28px;
            opacity: 0.8;
        }
        .exchange-rate-box .rate-info .rate-label {
            font-size: 13px;
            opacity: 0.7;
        }
        .exchange-rate-box .rate-info .rate-value {
            font-size: 24px;
            font-weight: 900;
        }
        .exchange-rate-box .rate-actions {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .exchange-rate-box .rate-actions input {
            padding: 8px 14px;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-weight: 600;
            width: 120px;
            font-family: var(--font-family);
            background: rgba(255,255,255,0.15);
            color: #fff;
            outline: none;
        }
        .exchange-rate-box .rate-actions .btn-update {
            padding: 8px 20px;
            border: none;
            border-radius: var(--radius-sm);
            background: var(--accent);
            color: #fff;
            font-weight: 700;
            cursor: pointer;
            font-family: var(--font-family);
            transition: var(--transition-base);
            font-size: 13px;
        }
        .exchange-rate-box .rate-actions .btn-update:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(201,154,61,0.4);
        }

        /* ============================================================
           نظام الإيجاز المعتمد
           ============================================================ */
        .briefing-section {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 20px 24px;
            margin-bottom: 24px;
            border: 1px solid rgba(0,107,115,0.04);
            box-shadow: var(--shadow-sm);
        }
        .briefing-section .briefing-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .briefing-section .briefing-header h4 {
            font-size: 16px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .briefing-section .briefing-header h4 i { color: var(--primary); }

        .brief-date-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            background: var(--bg);
            border-radius: var(--radius-md);
            padding: 8px 12px;
            margin-bottom: 14px;
            border: 1px solid rgba(0,107,115,0.06);
        }
        .brief-date-bar label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .brief-date-bar label i { color: var(--primary); }
        .brief-date-bar input[type="date"] {
            padding: 6px 10px;
            border-radius: var(--radius-sm);
            border: 1px solid rgba(0,107,115,0.1);
            font-family: var(--font-family);
            font-size: 12px;
            background: var(--bg-card);
            color: var(--text-primary);
        }
        .brief-day-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
            gap: 10px;
        }
        .brief-day-card {
            background: var(--bg);
            border-radius: var(--radius-md);
            padding: 10px 8px;
            text-align: center;
            cursor: pointer;
            border-top: 3px solid var(--primary);
            transition: var(--transition-base);
        }
        .brief-day-card:hover {
            box-shadow: var(--shadow-sm);
            transform: translateY(-2px);
        }
        .brief-day-icon { font-size: 18px; margin-bottom: 4px; }
        .brief-day-branch { font-size: 12px; font-weight: 700; color: var(--text-primary); }
        .brief-day-sender { font-size: 10px; color: var(--text-muted); margin-top: 2px; }

        .briefing-card {
            background: var(--bg);
            border-radius: var(--radius-md);
            padding: 12px 14px;
            margin-bottom: 14px;
            border-right: 4px solid var(--primary);
            transition: var(--transition-base);
        }
        .briefing-card:hover {
            box-shadow: var(--shadow-sm);
        }
        .briefing-card .briefing-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 8px;
        }
        .briefing-card .briefing-top .sender {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
        }
        .briefing-card .briefing-top .sender i { color: var(--primary); }
        .briefing-card .briefing-top .date {
            font-size: 12px;
            color: var(--text-muted);
        }
        .briefing-card .briefing-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin: 8px 0;
            font-size: 13px;
        }
        .briefing-card .briefing-details .detail-item {
            background: var(--bg-card);
            padding: 6px 12px;
            border-radius: var(--radius-sm);
            border: 1px solid rgba(0,107,115,0.04);
        }
        .briefing-card .briefing-details .detail-item .label {
            font-size: 10px;
            color: var(--text-muted);
            font-weight: 500;
            display: block;
        }
        .briefing-card .briefing-details .detail-item .value {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-primary);
        }
        .briefing-card .briefing-note {
            font-size: 12px;
            color: var(--text-muted);
            padding: 8px 12px;
            background: var(--bg-card);
            border-radius: var(--radius-sm);
            margin: 8px 0;
            border: 1px solid rgba(0,107,115,0.04);
        }
        .briefing-card .briefing-note strong {
            color: var(--text-primary);
        }
        .briefing-card .briefing-status {
            display: inline-block;
            font-size: 11px;
            font-weight: 700;
            padding: 3px 14px;
            border-radius: var(--radius-full);
        }
        .briefing-card .briefing-status.pending {
            background: rgba(217,119,6,0.12);
            color: #D97706;
        }
        .briefing-card .briefing-status.approved {
            background: rgba(16,185,129,0.12);
            color: #059669;
        }
        .briefing-card .briefing-status.rejected {
            background: rgba(239,68,68,0.12);
            color: #DC2626;
        }
        .briefing-card .briefing-actions {
            display: flex;
            gap: 8px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        .hr-note-input {
            display: block;
            width: 100%;
            flex: 1;
            min-width: 160px;
            padding: 8px 12px;
            margin-bottom: 6px;
            border: 2px solid rgba(0,107,115,0.06);
            border-radius: var(--radius-sm);
            font-size: 12px;
            font-family: var(--font-family);
            background: var(--bg-card);
            color: var(--text-primary);
            outline: none;
            box-sizing: border-box;
            transition: var(--transition-base);
        }
        .hr-note-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0,107,115,0.06);
        }
        .hr-note-input::placeholder {
            color: var(--text-muted);
        }
        .hr-note-input.input-error {
            border-color: #DC2626 !important;
            box-shadow: 0 0 0 3px rgba(220,38,38,0.15) !important;
            animation: hrInputShake 0.4s;
        }
        @keyframes hrInputShake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-4px); }
            75% { transform: translateX(4px); }
        }

        /* ============================================================
           إدارة الفروع
           ============================================================ */
        .branch-section {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 20px 24px;
            margin-bottom: 24px;
            border: 1px solid rgba(0,107,115,0.04);
            box-shadow: var(--shadow-sm);
        }
        .branch-section .branch-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .branch-section .branch-header h4 {
            font-size: 16px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .branch-section .branch-header h4 i { color: var(--primary); }

        .branch-form {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }
        .branch-form .form-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .branch-form .form-group label {
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
        }
        .branch-form .form-group input,
        .branch-form .form-group select,
        .branch-form .form-group textarea {
            padding: 8px 12px;
            border: 2px solid rgba(0,107,115,0.06);
            border-radius: var(--radius-sm);
            font-size: 13px;
            font-family: var(--font-family);
            background: var(--bg);
            color: var(--text-primary);
            outline: none;
            transition: var(--transition-base);
        }
        .branch-form .form-group input:focus,
        .branch-form .form-group select:focus,
        .branch-form .form-group textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0,107,115,0.06);
        }
        .branch-form .form-group textarea {
            min-height: 40px;
            resize: vertical;
        }
        .branch-form .form-group .file-input-wrapper {
            position: relative;
            overflow: hidden;
        }
        .branch-form .form-group .file-input-wrapper input[type="file"] {
            position: absolute;
            right: 0;
            top: 0;
            opacity: 0;
            width: 100%;
            height: 100%;
            cursor: pointer;
        }
        .branch-form .form-group .file-input-wrapper .file-label {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border: 2px solid rgba(0,107,115,0.06);
            border-radius: var(--radius-sm);
            background: var(--bg);
            font-size: 13px;
            color: var(--text-muted);
            cursor: pointer;
            transition: var(--transition-base);
        }
        .branch-form .form-group .file-input-wrapper .file-label:hover {
            border-color: var(--primary);
        }
        .branch-form .form-group .file-input-wrapper .file-label i {
            color: var(--primary);
        }

        #branchList {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
            gap: 10px;
        }
        .branch-card {
            background: var(--bg);
            border-radius: var(--radius-md);
            padding: 10px 12px;
            border: 1px solid rgba(0,107,115,0.04);
            border-right: 3px solid var(--primary);
            transition: var(--transition-base);
            cursor: pointer;
            display: flex;
            flex-direction: column;
        }
        .branch-card:hover {
            box-shadow: var(--shadow-sm);
            transform: translateY(-1px);
        }
        .branch-card .branch-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 4px;
        }
        .branch-card .branch-top .branch-name {
            font-size: 13.5px;
            font-weight: 800;
            color: var(--text-primary);
        }
        .branch-card .branch-top .branch-name i { color: var(--primary); }
        .branch-card .branch-top .branch-status {
            font-size: 10px;
            font-weight: 700;
            padding: 2px 10px;
            border-radius: var(--radius-full);
        }
        .branch-card .branch-top .branch-status.active {
            background: rgba(16,185,129,0.12);
            color: #059669;
        }
        .branch-card .branch-top .branch-status.inactive {
            background: rgba(239,68,68,0.12);
            color: #DC2626;
        }
        .branch-card .branch-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            margin: 6px 0;
            font-size: 13px;
        }
        .branch-card .branch-details .detail-item {
            background: var(--bg-card);
            padding: 4px 10px;
            border-radius: var(--radius-sm);
            border: 1px solid rgba(0,107,115,0.04);
        }
        .branch-card .branch-details .detail-item .label {
            font-size: 9.5px;
            color: var(--text-muted);
            font-weight: 500;
            display: block;
        }
        .branch-card .branch-details .detail-item .value {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-primary);
        }
        .branch-card .branch-docs {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin: 8px 0;
        }
        .branch-card .branch-docs .doc-tag {
            font-size: 11px;
            font-weight: 600;
            padding: 3px 12px;
            border-radius: var(--radius-full);
            background: rgba(0,107,115,0.06);
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .branch-card .branch-docs .doc-tag i { color: var(--primary); }
        .branch-card .branch-actions {
            display: flex;
            gap: 8px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        .branch-card .branch-actions .btn-sm {
            padding: 4px 14px;
            font-size: 11px;
            border: none;
            border-radius: var(--radius-sm);
            font-weight: 700;
            cursor: pointer;
            font-family: var(--font-family);
            transition: var(--transition-base);
        }
        .branch-card .branch-actions .btn-sm.edit {
            background: rgba(0,107,115,0.08);
            color: var(--primary);
        }
        .branch-card .branch-actions .btn-sm.edit:hover {
            background: var(--primary);
            color: #fff;
        }
        .branch-card .branch-actions .btn-sm.delete {
            background: rgba(239,68,68,0.08);
            color: #DC2626;
        }
        .branch-card .branch-actions .btn-sm.delete:hover {
            background: #DC2626;
            color: #fff;
        }

        /* ============================================================
           تفاصيل الفرع
           ============================================================ */
        .branch-detail-header {
            background: var(--bg-card);
            border-radius: var(--radius-md);
            padding: 14px 18px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: var(--shadow-sm);
            border: 1px solid rgba(0,107,115,0.04);
            margin-bottom: 18px;
            flex-wrap: wrap;
        }
        .branch-detail-icon {
            width: 44px;
            height: 44px;
            min-width: 44px;
            border-radius: var(--radius-md);
            background: var(--primary-gradient);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        .branch-detail-name {
            font-size: 16px;
            font-weight: 800;
            color: var(--text-primary);
        }
        .branch-detail-sub {
            display: flex;
            gap: 14px;
            font-size: 12px;
            color: var(--text-secondary);
            margin-top: 4px;
            flex-wrap: wrap;
        }
        .branch-detail-rate {
            margin-right: auto;
            text-align: center;
        }
        .branch-detail-rate small {
            display: block;
            font-size: 10px;
            color: var(--text-muted);
            margin-top: 4px;
        }
        .section-subtitle {
            font-size: 13px;
            font-weight: 800;
            color: var(--text-primary);
            margin: 18px 0 10px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .section-subtitle i { color: var(--primary); }
        .bd-employees-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 8px;
        }
        .bd-employee-card {
            background: var(--bg-card);
            border-radius: var(--radius-sm);
            padding: 10px 12px;
            border: 1px solid rgba(0,107,115,0.04);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .bd-employee-card .avatar {
            width: 32px;
            height: 32px;
            min-width: 32px;
            border-radius: 50%;
            background: var(--primary-gradient);
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 12px;
            font-weight: 800;
        }
        .bd-employee-card .name {
            font-size: 12.5px;
            font-weight: 700;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .bd-employee-card .role {
            font-size: 10.5px;
            color: var(--text-muted);
        }
        .bd-employee-card .rate {
            margin-right: auto;
            font-size: 12px;
            font-weight: 800;
        }
        .bd-brief-card {
            background: var(--bg-card);
            border-radius: var(--radius-sm);
            padding: 8px 12px;
            border: 1px solid rgba(0,107,115,0.04);
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            font-size: 12px;
            flex-wrap: wrap;
        }
        .bd-brief-card .bd-brief-status {
            font-size: 10px;
            font-weight: 700;
            padding: 2px 10px;
            border-radius: var(--radius-full);
        }

        /* ============================================================
           باقي الأقسام
           ============================================================ */
        .content-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid rgba(0,107,115,0.04);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            transition: var(--transition-base);
        }
        .content-card:hover {
            box-shadow: var(--shadow-md);
        }
        .content-card .card-header {
            padding: 16px 20px;
            border-bottom: 1px solid rgba(0,107,115,0.04);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .content-card .card-header h4 {
            font-size: 15px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .content-card .card-header h4 i { color: var(--primary); }
        .content-card .card-body { padding: 16px 20px; }

        .table-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        .table th,
        .table td {
            padding: 10px 8px;
            border-bottom: 1px solid rgba(0,107,115,0.04);
            text-align: right;
        }
        .table th {
            color: var(--text-muted);
            font-weight: 500;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .table tr:hover td { background: rgba(0,107,115,0.02); }
        .table .status-badge {
            font-size: 10px;
            font-weight: 700;
            padding: 2px 10px;
            border-radius: var(--radius-full);
        }
        .table .status-badge.present { background: rgba(16,185,129,0.12); color: #059669; }
        .table .status-badge.absent { background: rgba(239,68,68,0.12); color: #DC2626; }
        .table .status-badge.late { background: rgba(217,119,6,0.12); color: #D97706; }
        .table .status-badge.pending { background: rgba(217,119,6,0.12); color: #D97706; }
        .table .status-badge.approved { background: rgba(16,185,129,0.12); color: #059669; }
        .table .status-badge.rejected { background: rgba(239,68,68,0.12); color: #DC2626; }

        .table .action-btn {
            padding: 4px 10px;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 11px;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition-base);
            font-family: var(--font-family);
        }
        .table .action-btn.approve {
            background: rgba(16,185,129,0.12);
            color: #059669;
        }
        .table .action-btn.approve:hover {
            background: #059669;
            color: #fff;
        }
        .table .action-btn.reject {
            background: rgba(239,68,68,0.12);
            color: #DC2626;
        }
        .table .action-btn.reject:hover {
            background: #DC2626;
            color: #fff;
        }

        /* ============================================================
           الأزرار العامة
           ============================================================ */
        .btn-primary {
            padding: 8px 20px;
            border: none;
            border-radius: var(--radius-sm);
            background: var(--primary-gradient);
            color: #fff;
            font-weight: 700;
            cursor: pointer;
            font-family: var(--font-family);
            transition: var(--transition-base);
            font-size: 13px;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(0,107,115,0.3);
        }
        .btn-success {
            padding: 8px 20px;
            border: none;
            border-radius: var(--radius-sm);
            background: #10B981;
            color: #fff;
            font-weight: 700;
            cursor: pointer;
            font-family: var(--font-family);
            transition: var(--transition-base);
            font-size: 13px;
        }
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(16,185,129,0.3);
        }
        .btn-danger {
            padding: 8px 20px;
            border: none;
            border-radius: var(--radius-sm);
            background: #EF4444;
            color: #fff;
            font-weight: 700;
            cursor: pointer;
            font-family: var(--font-family);
            transition: var(--transition-base);
            font-size: 13px;
        }
        .btn-danger:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 16px rgba(239,68,68,0.3);
        }
        .btn-outline {
            padding: 8px 20px;
            border: 2px solid rgba(0,107,115,0.08);
            border-radius: var(--radius-sm);
            background: transparent;
            color: var(--text-secondary);
            font-weight: 700;
            cursor: pointer;
            font-family: var(--font-family);
            transition: var(--transition-base);
            font-size: 13px;
        }
        .btn-outline:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        /* ============================================================
           التوست
           ============================================================ */
        .toast-container {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 999;
            display: flex;
            flex-direction: column;
            gap: 10px;
            align-items: center;
            pointer-events: none;
            width: 100%;
            max-width: 420px;
            padding: 0 16px;
        }
        .toast {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 14px 20px;
            box-shadow: var(--shadow-xl);
            border: 1px solid rgba(0,107,115,0.04);
            pointer-events: auto;
            width: 100%;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            opacity: 0;
            transform: translateY(-80px) scale(0.9);
            transition: all 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            font-weight: 700;
            font-size: 13px;
        }
        .toast.show { opacity: 1; transform: translateY(0) scale(1); }
        .toast.swipe-up {
            transform: translateY(-120px) scale(0.9);
            opacity: 0;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }
        .toast::before {
            content: '';
            position: absolute;
            top: 0;
            right: 0;
            width: 4px;
            height: 100%;
            border-radius: 0 4px 4px 0;
        }
        .toast.success::before { background: #10B981; }
        .toast.info::before { background: var(--primary); }
        .toast.warning::before { background: #F59E0B; }
        .toast.error::before { background: #EF4444; }

        .toast .toast-icon {
            width: 36px;
            height: 36px;
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }
        .toast .toast-icon.success { background: rgba(16,185,129,0.12); color: #059669; }
        .toast .toast-icon.info { background: rgba(0,107,115,0.12); color: var(--primary); }
        .toast .toast-icon.warning { background: rgba(217,119,6,0.12); color: #D97706; }
        .toast .toast-icon.error { background: rgba(239,68,68,0.12); color: #DC2626; }
        .toast .toast-content { flex: 1; }
        .toast .toast-content .toast-title { font-weight: 800; }
        .toast .toast-content .toast-message { font-weight: 400; color: var(--text-muted); }

        /* ============================================================
           شاشة تسجيل الدخول
           ============================================================ */
        .login-page {
            width: 100vw;
            height: 100vh;
            background: linear-gradient(135deg, #004b52 0%, #006b73 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: fixed;
            inset: 0;
            z-index: 1000;
        }
        .login-card {
            background: #FFFFFF;
            border-radius: var(--radius-lg);
            padding: 40px 32px;
            max-width: 420px;
            width: 100%;
            box-shadow: var(--shadow-xl);
            direction: rtl;
        }
        .login-card .login-logo {
            text-align: center;
            margin-bottom: 28px;
        }
        .login-card .login-logo .logo-icon {
            width: 64px;
            height: 64px;
            background: var(--primary-gradient);
            border-radius: var(--radius-lg);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 28px;
            font-weight: 900;
            margin-bottom: 12px;
            box-shadow: 0 8px 32px rgba(0,107,115,0.4);
        }
        .login-card .login-logo h2 {
            font-size: 22px;
            font-weight: 900;
            color: #1A2E35;
        }
        .login-card .login-logo h2 span { color: #006b73; }
        .login-card .login-logo p {
            color: var(--text-muted);
            font-size: 14px;
            font-weight: 400;
        }
        .login-card .form-group { margin-bottom: 16px; }
        .login-card .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: var(--text-secondary);
            margin-bottom: 6px;
            text-align: right;
        }
        .login-card .form-group input {
            width: 100%;
            height: 48px;
            padding: 0 16px;
            border: 2px solid rgba(0,107,115,0.08);
            border-radius: var(--radius-sm);
            font-size: 14px;
            background: var(--bg);
            color: var(--text-primary);
            outline: none;
            font-family: var(--font-family);
            text-align: right;
        }
        .login-card .form-group input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(0,107,115,0.06);
        }
        .login-card .btn-login {
            width: 100%;
            height: 48px;
            border: none;
            border-radius: var(--radius-md);
            background: var(--primary-gradient);
            color: #fff;
            font-size: 16px;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition-base);
            font-family: var(--font-family);
            box-shadow: 0 4px 16px rgba(0,107,115,0.25);
        }
        .login-card .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(0,107,115,0.35);
        }
        .login-card .login-error {
            color: #EF4444;
            font-size: 13px;
            text-align: center;
            margin-top: 12px;
            display: none;
        }

        /* ============================================================
           التجاوب
           ============================================================ */
        @media (max-width: 1200px) {
            .top-employees { grid-template-columns: 1fr 1fr 1fr; }
            .branch-form { grid-template-columns: 1fr 1fr; }
            .branch-card .branch-details { grid-template-columns: 1fr 1fr; }
            .briefing-card .briefing-details { grid-template-columns: 1fr 1fr; }
        }

        @media (max-width: 768px) {
            :root { --sidebar-width: 0px; }
            .sidebar {
                transform: translateX(100%);
                width: 280px;
            }
            .sidebar.open { transform: translateX(0); }
            .main-content { margin-right: 0; padding: 16px; }
            .top-employees { grid-template-columns: 1fr; }
            .top-header .page-title h2 { font-size: 18px; }
            .branch-form { grid-template-columns: 1fr; }
            .branch-card .branch-details { grid-template-columns: 1fr; }
            .briefing-card .briefing-details { grid-template-columns: 1fr; }
            .briefing-card .briefing-actions .hr-note-input { min-width: 100%; }
            .exchange-rate-box { flex-direction: column; align-items: stretch; }
            .exchange-rate-box .rate-actions { flex-wrap: wrap; }
            .exchange-rate-box .rate-actions input { width: 100%; }
            .stocks-chart { height: 80px; }
            .branch-bar-label { font-size: 8px; bottom: -16px; max-width: 50px; }
            .branch-bar-pct { font-size: 9px; }
            .mobile-menu-toggle {
                display: flex !important;
            }
        }

        @media (max-width: 480px) {
            .ring-stats-grid { grid-template-columns: 1fr; }
            .top-employees { grid-template-columns: 1fr; }
            .top-header .header-actions .date-display { font-size: 11px; padding: 6px 12px; }
            .stat-card .stat-value { font-size: 22px; }
            .branch-form { grid-template-columns: 1fr; }
            .branch-card .branch-details { grid-template-columns: 1fr; }
            .briefing-card .briefing-details { grid-template-columns: 1fr; }
        }

        .mobile-menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 24px;
            color: var(--text-primary);
            cursor: pointer;
            padding: 4px 8px;
        }
        .sidebar-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.3);
            z-index: 99;
        }
        .sidebar-overlay.show { display: block; }

        /* طباعة التقرير كـ PDF */
        #reportPrintHeader { display: none; }
        @media print {
            body { background: #fff !important; }
            body * { visibility: hidden; }
            #reportResult, #reportResult * { visibility: visible; }
            #reportResult { width: 100%; box-shadow: none; border: none; }
            #reportResult .card-header button { display: none !important; }
            #reportPrintHeader, #reportPrintHeader * { visibility: visible; }
            #reportPrintHeader { display: block; text-align: center; margin-bottom: 16px; background: #fff; }
            #reportPrintHeader h2 { font-size: 20px; margin-bottom: 4px; color: #173437; }
            #reportPrintHeader span { font-size: 12px; color: #555; }
            .table th, .table td { color: #000 !important; }
        }
    </style>
</head>
<body>

    <!-- ============================================================
    شاشة تسجيل الدخول
    ============================================================ -->
    <div class="login-page" id="loginPage">
        <div class="login-card">
            <div class="login-logo">
                <div class="logo-icon">✥</div>
                <h2>نظام <span>الموارد البشرية</span></h2>
                <p>لوحة تحكم المدير التنفيذي</p>
            </div>
            <form onsubmit="handleLogin(event)">
                <div class="form-group">
                    <label>البريد الإلكتروني</label>
                    <input type="email" id="loginEmail" placeholder="hr@company.com">
                </div>
                <div class="form-group">
                    <label>كلمة المرور</label>
                    <input type="password" id="loginPassword" placeholder="••••••••">
                </div>
                <div class="login-error" id="loginError">بيانات الدخول غير صحيحة</div>
                <button type="submit" class="btn-login" id="loginBtn">
                    <i class="fas fa-arrow-left"></i> تسجيل الدخول
                </button>
            </form>
        </div>
    </div>

    <!-- ============================================================
    التطبيق الرئيسي
    ============================================================ -->
    <div id="appContainer" style="display:none;width:100%;">

        <!-- ===== الشريط الجانبي ===== -->
        <aside class="sidebar" id="sidebar">
            <div class="brand">
                <div class="logo" id="headerLogo">✥</div>
                <div>
                    <div class="name" id="headerCompanyName">نظام <span>الموارد</span></div>
                    <span class="version">HR</span>
                </div>
            </div>

            <nav class="nav-menu">
                <button class="nav-item active" onclick="navigateTo('dashboard')">
                    <i class="fas fa-chart-pie"></i> لوحة التحكم
                </button>
                <button class="nav-item" onclick="navigateTo('branches')">
                    <i class="fas fa-building"></i> الفروع
                    <span class="badge success" id="branchBadge">3</span>
                </button>
                <button class="nav-item" onclick="navigateTo('employees')">
                    <i class="fas fa-users"></i> الموظفون
                </button>
                <button class="nav-item" onclick="navigateTo('attendance')">
                    <i class="fas fa-clock"></i> الحضور
                    <span class="badge">12</span>
                </button>
                <button class="nav-item" onclick="navigateTo('salaries')">
                    <i class="fas fa-wallet"></i> الرواتب
                    <span class="badge success">جديد</span>
                </button>
                <button class="nav-item" onclick="navigateTo('briefing')">
                    <i class="fas fa-file-signature"></i> الإيجاز
                    <span class="badge warning" id="briefingBadge">3</span>
                </button>
                <button class="nav-item" onclick="navigateTo('requests')">
                    <i class="fas fa-file-pen"></i> الطلبات
                    <span class="badge warning">5</span>
                </button>
                <button class="nav-item" onclick="navigateTo('reports')">
                    <i class="fas fa-chart-bar"></i> التقارير
                </button>
                <button class="nav-item" onclick="navigateTo('exchange')">
                    <i class="fas fa-dollar-sign"></i> سعر الصرف
                </button>
                <div class="nav-divider"></div>
                <button class="nav-item" onclick="navigateTo('settings')">
                    <i class="fas fa-cog"></i> الإعدادات
                </button>
            </nav>

            <div class="user-info">
                <div class="avatar">أ</div>
                <div class="info">
                    <div class="name">أحمد المدير</div>
                    <div class="role">مدير الموارد البشرية</div>
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
                        <h2 id="pageTitle"><i class="fas fa-chart-pie"></i> لوحة التحكم</h2>
                        <span class="sub" id="pageSub">نظرة عامة على أداء الشركة</span>
                    </div>
                </div>
                <div class="header-actions" style="position:relative;">
                    <span class="date-display">
                        <i class="fas fa-calendar"></i>
                        <span id="currentDateDisplay">الأربعاء 19 أغسطس 2026</span>
                    </span>
                    <button onclick="toggleNotifPanel()" style="position:relative;width:38px;height:38px;border:none;border-radius:50%;background:rgba(0,107,115,0.06);color:var(--primary);cursor:pointer;font-size:16px;">
                        <i class="fas fa-bell"></i>
                        <span id="notifBadge" style="display:none;position:absolute;top:-2px;left:-2px;background:#DC2626;color:#fff;font-size:10px;font-weight:800;min-width:16px;height:16px;border-radius:50%;align-items:center;justify-content:center;">0</span>
                    </button>
                    <div id="notifPanel" style="display:none;position:absolute;left:0;top:48px;width:340px;max-height:420px;overflow-y:auto;background:#fff;border-radius:14px;box-shadow:0 12px 40px rgba(0,0,0,0.15);z-index:500;">
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

                <!-- ==========================================================
                صفحة لوحة التحكم
                ========================================================== -->
                <div id="page-dashboard" class="page-section">

                    <div class="ring-stats-grid">
                        <div class="ring-stat-card">
                            <div class="ring-chart" id="attendanceRing">
                                <div class="ring-center">
                                    <span id="ringEmployeeCount">0</span>
                                    <small>موظف</small>
                                </div>
                            </div>
                            <div class="ring-info">
                                <div class="ring-title"><i class="fas fa-users"></i> حضور اليوم</div>
                                <div class="ring-legend">
                                    <span class="legend-item"><i class="dot" style="background:#059669;"></i> حاضر <b id="legendPresentPct">0%</b></span>
                                    <span class="legend-item"><i class="dot" style="background:#D97706;"></i> متأخر <b id="legendLatePct">0%</b></span>
                                    <span class="legend-item"><i class="dot" style="background:#DC2626;"></i> غائب <b id="legendAbsentPct">0%</b></span>
                                </div>
                            </div>
                        </div>
                        <div class="ring-stat-card">
                            <div class="ring-chart" id="profitRing">
                                <div class="ring-center">
                                    <span id="ringProfitPct">0%</span>
                                    <small>الربح</small>
                                </div>
                            </div>
                            <div class="ring-info">
                                <div class="ring-title"><i class="fas fa-percent"></i> نسبة الربح الشهرية</div>
                                <div class="ring-legend">
                                    <span class="legend-item"><i class="dot" style="background:#059669;"></i> ربح صافٍ من إجمالي الإيرادات</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- أفضل 3 موظفين حضور -->
                    <div class="top-employees" id="topEmployees">
                        <!-- يتم التعبئة بواسطة JavaScript -->
                    </div>

                    <!-- مقارنة إيرادات الفروع -->
                    <div class="stocks-section">
                        <div class="stocks-header">
                            <h4><i class="fas fa-chart-column"></i> مقارنة إيرادات الفروع (الشهر الحالي)</h4>
                            <span class="update-time"><i class="fas fa-clock"></i> تحديث: <span id="stocksTime">15:30</span></span>
                        </div>
                        <div class="stocks-chart" id="stocksChart"></div>
                        <div class="stocks-summary" id="stocksSummary"></div>
                    </div>

                    <!-- صندوق سعر الصرف -->
                    <div class="exchange-rate-box">
                        <div class="rate-info">
                            <div class="rate-icon"><i class="fas fa-dollar-sign"></i></div>
                            <div>
                                <div class="rate-label">سعر صرف الدولار</div>
                                <div class="rate-value" id="exchangeRateDisplay">1,320</div>
                            </div>
                        </div>
                        <div class="rate-actions">
                            <input type="number" id="exchangeRateInput" value="1320" placeholder="سعر الصرف">
                            <button class="btn-update" onclick="updateExchangeRate()">
                                <i class="fas fa-save"></i> تحديث
                            </button>
                        </div>
                    </div>
                </div>

                <!-- ==========================================================
                صفحة إدارة الفروع
                ========================================================== -->
                <div id="page-branches" class="page-section" style="display:none;">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
                        <h4 style="font-size:16px;font-weight:800;"><i class="fas fa-building" style="color:var(--primary);"></i> إدارة الفروع</h4>
                        <button class="btn-primary" onclick="toggleBranchForm()">
                            <i class="fas fa-plus"></i> إضافة فرع جديد
                        </button>
                    </div>

                    <!-- نموذج إضافة فرع -->
                    <div class="branch-section" id="branchForm" style="display:none;">
                        <div class="branch-header">
                            <h4><i class="fas fa-edit"></i> <span id="branchFormTitle">إضافة فرع جديد</span></h4>
                            <button class="btn-outline" onclick="toggleBranchForm()">
                                <i class="fas fa-times"></i> إلغاء
                            </button>
                        </div>

                        <div class="branch-form">
                            <div class="form-group">
                                <label>اسم الفرع <span style="color:#EF4444;">*</span></label>
                                <input type="text" id="branchName" placeholder="مثال: فرع الكرادة">
                            </div>
                            <div class="form-group">
                                <label>اسم مسؤول الفرع <span style="color:#EF4444;">*</span></label>
                                <input type="text" id="branchManager" placeholder="اسم المدير المسؤول">
                            </div>
                            <div class="form-group">
                                <label>رقم الهوية الوطنية <span style="color:#EF4444;">*</span></label>
                                <input type="text" id="branchNationalId" placeholder="رقم الهوية الوطنية">
                            </div>
                            <div class="form-group">
                                <label>رقم الهاتف <span style="color:#EF4444;">*</span></label>
                                <input type="text" id="branchPhone" placeholder="07xxxxxxxxx">
                            </div>
                            <div class="form-group">
                                <label>تاريخ الميلاد</label>
                                <input type="date" id="branchBirthDate">
                            </div>
                            <div class="form-group">
                                <label>تاريخ التعيين</label>
                                <input type="date" id="branchHireDate">
                            </div>
                            <div class="form-group">
                                <label>⏰ وقت بداية الدوام</label>
                                <input type="time" id="branchShiftStart">
                            </div>
                            <div class="form-group">
                                <label>⏰ وقت نهاية الدوام (الانصراف)</label>
                                <input type="time" id="branchShiftEnd">
                            </div>
                            <div class="form-group">
                                <label>الصورة الشخصية <span style="color:#EF4444;">*</span></label>
                                <div class="file-input-wrapper">
                                    <div class="file-label">
                                        <i class="fas fa-image"></i>
                                        <span id="branchPhotoLabel">اختر صورة</span>
                                    </div>
                                    <input type="file" id="branchPhoto" accept="image/*" onchange="updateFileLabel('branchPhoto', 'branchPhotoLabel')">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>المستمسكات (ملف)</label>
                                <div class="file-input-wrapper">
                                    <div class="file-label">
                                        <i class="fas fa-file-pdf"></i>
                                        <span id="branchDocsLabel">اختر ملف المستمسكات</span>
                                    </div>
                                    <input type="file" id="branchDocs" accept=".pdf,.doc,.docx,.jpg,.png" onchange="updateFileLabel('branchDocs', 'branchDocsLabel')">
                                </div>
                            </div>
                            <div class="form-group" style="grid-column: 1 / -1;">
                                <label>ملاحظات إضافية</label>
                                <textarea id="branchNotes" placeholder="أي معلومات إضافية عن الفرع أو المسؤول..." rows="2"></textarea>
                            </div>
                            <div class="form-group">
                                <label>حالة الفرع</label>
                                <select id="branchStatus">
                                    <option value="active">نشط</option>
                                    <option value="inactive">غير نشط</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label>كلمة مرور حساب المسؤول (لأول مرة فقط)</label>
                                <input type="text" id="branchManagerPassword" placeholder="لإنشاء حساب دخول لمسؤول الفرع">
                            </div>
                        </div>

                        <div class="branch-actions">
                            <button class="btn-primary" onclick="saveBranch()">
                                <i class="fas fa-save"></i> حفظ الفرع
                            </button>
                            <button class="btn-outline" onclick="clearBranchForm()">
                                <i class="fas fa-undo"></i> إعادة تعيين
                            </button>
                        </div>
                    </div>

                    <!-- قائمة الفروع -->
                    <div class="branch-section">
                        <div class="branch-header">
                            <h4><i class="fas fa-list"></i> قائمة الفروع</h4>
                            <span style="font-size:12px;color:var(--text-muted);" id="branchCount">3 فروع</span>
                        </div>
                        <div id="branchList"></div>
                    </div>
                </div>

                <!-- ==========================================================
                صفحة تفاصيل الفرع
                ========================================================== -->
                <div id="page-branchDetail" class="page-section" style="display:none;">
                    <button class="btn-outline" style="margin-bottom:14px;" onclick="navigateTo('branches')">
                        <i class="fas fa-arrow-right"></i> رجوع للفروع
                    </button>

                    <div class="branch-detail-header">
                        <div class="branch-detail-icon"><i class="fas fa-building"></i></div>
                        <div>
                            <div class="branch-detail-name" id="bdName">...</div>
                            <div class="branch-detail-sub">
                                <span><i class="fas fa-user-tie"></i> <span id="bdManager">...</span></span>
                                <span><i class="fas fa-clock"></i> <span id="bdShift">...</span></span>
                                <span id="bdDocuments" style="font-size:11px;"></span>
                            </div>
                        </div>
                        <div class="branch-detail-rate">
                            <div class="ring-chart" id="bdAttendanceRing" style="width:56px;height:56px;min-width:56px;">
                                <div class="ring-center" style="inset:7px;"><span id="bdAttendancePct">0%</span></div>
                            </div>
                            <small>نسبة الحضور الشهرية</small>
                        </div>
                    </div>

                    <div class="section-subtitle"><i class="fas fa-users"></i> موظفو الفرع</div>
                    <div id="bdEmployeesList" class="bd-employees-list"></div>

                    <div class="section-subtitle"><i class="fas fa-file-signature"></i> الإيجازات المنشورة</div>
                    <div id="bdBriefsList"></div>
                </div>

                <!-- ==========================================================
                صفحة الموظفون
                ========================================================== -->
                <div id="page-employees" class="page-section" style="display:none;">
                    <div class="content-card">
                        <div class="card-header">
                            <h4><i class="fas fa-users"></i> جميع الموظفين ومديري الفروع</h4>
                            <input type="text" id="employeeSearch" placeholder="بحث بالاسم..." oninput="renderEmployeesTable()" style="padding:8px 12px;border:2px solid rgba(0,107,115,0.06);border-radius:var(--radius-sm);font-family:var(--font-family);font-size:13px;">
                        </div>
                        <div class="card-body">
                            <div class="table-wrap">
                                <table class="table">
                                    <thead><tr><th>#</th><th>الاسم</th><th>الفرع</th><th>المسمى</th><th>الشفت</th><th>الراتب الأساسي</th><th>المكافأة</th><th>الخصم وسببه</th><th>التقييم</th><th>الحالة</th><th>إجراء</th></tr></thead>
                                    <tbody id="employeesBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ==========================================================
                صفحة الحضور
                ========================================================== -->
                <div id="page-attendance" class="page-section" style="display:none;">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
                        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                            <input type="date" id="attendanceDate" value="2026-08-19" style="padding:8px 12px;border:2px solid rgba(0,107,115,0.06);border-radius:var(--radius-sm);font-family:var(--font-family);background:var(--bg);">
                            <button class="btn-primary" onclick="loadAttendance()">عرض</button>
                        </div>
                        <button class="btn-primary" onclick="loadAttendance()">
                            <i class="fas fa-sync"></i> تحديث
                        </button>
                    </div>

                    <div class="content-card" style="margin-bottom:16px;">
                        <div class="card-header"><h4><i class="fas fa-user-clock"></i> تسجيل أو تعديل حضور موظف يدوياً</h4></div>
                        <div class="card-body">
                            <div style="display:grid;grid-template-columns:1.5fr 1fr 1fr auto;gap:10px;align-items:end;">
                                <div class="form-group"><label style="font-size:12px;color:var(--text-muted);">الموظف</label>
                                    <select id="manualEmployeeSelect" style="width:100%;height:38px;padding:0 10px;border:2px solid rgba(0,107,115,0.06);border-radius:var(--radius-sm);font-family:var(--font-family);"></select>
                                </div>
                                <div class="form-group"><label style="font-size:12px;color:var(--text-muted);">الحالة</label>
                                    <select id="manualStatus" style="width:100%;height:38px;padding:0 10px;border:2px solid rgba(0,107,115,0.06);border-radius:var(--radius-sm);font-family:var(--font-family);">
                                        <option value="present">حاضر</option>
                                        <option value="late">متأخر</option>
                                        <option value="absent">غائب</option>
                                    </select>
                                </div>
                                <div class="form-group"><label style="font-size:12px;color:var(--text-muted);">ملاحظة</label>
                                    <input type="text" id="manualNote" placeholder="اختياري" style="width:100%;height:38px;padding:0 10px;border:2px solid rgba(0,107,115,0.06);border-radius:var(--radius-sm);font-family:var(--font-family);">
                                </div>
                                <button class="btn-primary" onclick="saveManualAttendance()"><i class="fas fa-save"></i> حفظ</button>
                            </div>
                        </div>
                    </div>

                    <div class="content-card">
                        <div class="card-header">
                            <h4><i class="fas fa-clock"></i> سجل الحضور اليومي</h4>
                            <span style="font-size:12px;color:var(--text-muted);" id="attendanceDateLabel">19 أغسطس 2026</span>
                        </div>
                        <div class="card-body">
                            <div class="table-wrap">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>الموظف</th>
                                            <th>الفرع</th>
                                            <th>وقت الدخول</th>
                                            <th>وقت الخروج</th>
                                            <th>الحالة</th>
                                            <th>إجراء</th>
                                        </tr>
                                    </thead>
                                    <tbody id="attendanceBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ==========================================================
                صفحة الرواتب
                ========================================================== -->
                <div id="page-salaries" class="page-section" style="display:none;">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
                        <h4 style="font-size:16px;font-weight:800;"><i class="fas fa-wallet" style="color:var(--primary);"></i> تسليم الرواتب</h4>
                    </div>

                    <div style="padding:10px 16px;border-radius:var(--radius-md);margin-bottom:16px;font-size:12px;background:rgba(0,107,115,0.05);color:var(--primary-dark);">
                        <i class="fas fa-info-circle"></i> تحديد الرواتب الأساسية والمكافآت والخصومات أصبح من صلاحية المسؤول العام. دور HR هنا هو تسليم الرواتب فقط بعد فتح الصلاحية الشهرية.
                    </div>

                    <div id="payrollWindowBanner" style="padding:10px 16px;border-radius:var(--radius-md);margin-bottom:16px;font-size:13px;font-weight:700;"></div>

                    <div class="content-card">
                        <div class="card-header">
                            <h4><i class="fas fa-list"></i> قائمة الرواتب (من لم يستلم راتبه يظهر أولاً)</h4>
                            <span style="font-size:12px;color:var(--text-muted);" id="salaryPeriodLabel"></span>
                        </div>
                        <div class="card-body">
                            <div class="table-wrap">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>الموظف</th>
                                            <th>الفرع</th>
                                            <th>الراتب الأساسي</th>
                                            <th>المكافأة</th>
                                            <th>الخصم</th>
                                            <th>الصافي</th>
                                            <th>الحالة</th>
                                            <th>إجراء</th>
                                        </tr>
                                    </thead>
                                    <tbody id="salaryBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ==========================================================
                صفحة الإيجاز - تم تصحيحها
                ========================================================== -->
                <div id="page-briefing" class="page-section" style="display:none;">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
                        <h4 style="font-size:16px;font-weight:800;"><i class="fas fa-file-signature" style="color:var(--primary);"></i> نظام الإيجاز المعتمد</h4>
                        <span style="font-size:12px;color:var(--text-muted);">إدارة طلبات اعتماد الإيجازات</span>
                    </div>

                    <!-- تنبيه توضيحي -->
                    <div style="background:rgba(0,107,115,0.04);border-radius:var(--radius-md);padding:12px 16px;margin-bottom:16px;border-right:4px solid var(--primary);">
                        <p style="font-size:13px;color:var(--text-secondary);">
                            <i class="fas fa-info-circle" style="color:var(--primary);"></i>
                            هنا يمكنك معاينة الإيجازات المرسلة من الموظفين ومسؤولي الفروع.
                            قم بإضافة ملاحظاتك ثم اعتماد أو رفض الإيجاز.
                        </p>
                    </div>

                    <!-- قائمة الإيجازات -->
                    <div class="briefing-section">
                        <div class="briefing-header">
                            <h4><i class="fas fa-inbox"></i> الإيجازات الواردة — اختر فرعاً</h4>
                            <span style="font-size:12px;color:var(--text-muted);" id="briefingCount">0 فرع</span>
                        </div>
                        <div id="briefingDayList" class="brief-day-grid">
                            <!-- يتم التعبئة بواسطة JavaScript: بطاقة لكل فرع -->
                        </div>
                        <div id="briefingDetailPanel" style="display:none;">
                            <button class="btn-outline" style="margin-bottom:10px;" onclick="closeBriefingDetail()"><i class="fas fa-arrow-right"></i> رجوع لقائمة الفروع</button>
                            <div class="brief-date-bar">
                                <label for="briefingDateInput"><i class="fas fa-calendar-day"></i> عرض تاريخ محدد</label>
                                <input type="date" id="briefingDateInput">
                                <button class="btn-sm view" onclick="filterBriefingByDate()"><i class="fas fa-rotate"></i> تحديث</button>
                                <button class="btn-sm view" onclick="clearBriefingDateFilter()"><i class="fas fa-list"></i> كل السجل</button>
                            </div>
                            <div id="briefingDetailContent"></div>
                        </div>
                    </div>
                </div>

                <!-- ==========================================================
                صفحة الطلبات
                ========================================================== -->
                <div id="page-requests" class="page-section" style="display:none;">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
                        <h4 style="font-size:16px;font-weight:800;"><i class="fas fa-file-pen" style="color:var(--primary);"></i> إدارة الطلبات</h4>
                        <div style="display:flex;gap:8px;">
                            <button class="btn-outline" onclick="filterRequests('all')">الكل</button>
                            <button class="btn-outline" onclick="filterRequests('pending')" style="border-color:#D97706;color:#D97706;">قيد المراجعة</button>
                        </div>
                    </div>

                    <div class="content-card">
                        <div class="card-header">
                            <h4><i class="fas fa-list"></i> طلبات الموظفين</h4>
                            <span style="font-size:12px;color:var(--text-muted);" id="requestsCount">5 طلبات</span>
                        </div>
                        <div class="card-body">
                            <div class="table-wrap">
                                <table class="table" id="requestsTable">
                                    <thead>
                                        <tr>
                                            <th>#</th>
                                            <th>الموظف</th>
                                            <th>نوع الطلب</th>
                                            <th>التفاصيل</th>
                                            <th>التاريخ</th>
                                            <th>الحالة</th>
                                            <th>إجراء</th>
                                        </tr>
                                    </thead>
                                    <tbody id="requestsBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ==========================================================
                صفحة التقارير
                ========================================================== -->
                <div id="page-reports" class="page-section" style="display:none;">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
                        <h4 style="font-size:16px;font-weight:800;"><i class="fas fa-chart-bar" style="color:var(--primary);"></i> التقارير</h4>
                    </div>

                    <div class="content-card" style="margin-bottom:16px;">
                        <div class="card-header">
                            <h4><i class="fas fa-sliders-h"></i> إعدادات التقرير</h4>
                        </div>
                        <div class="card-body">
                            <div class="report-filters" style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px;">
                                <div class="filter-group" style="display:flex;flex-direction:column;gap:4px;">
                                    <label style="font-size:12px;font-weight:600;color:var(--text-muted);">نوع التقرير</label>
                                    <select id="reportType" style="padding:8px 12px;border:2px solid rgba(0,107,115,0.06);border-radius:var(--radius-sm);font-size:13px;font-family:var(--font-family);background:var(--bg);color:var(--text-primary);outline:none;min-width:140px;">
                                        <option value="attendance">تقرير الحضور</option>
                                        <option value="salaries">تقرير الرواتب</option>
                                        <option value="briefing">تقرير الإيجاز</option>
                                        <option value="all">تقرير شامل</option>
                                    </select>
                                </div>
                                <div class="filter-group" style="display:flex;flex-direction:column;gap:4px;">
                                    <label style="font-size:12px;font-weight:600;color:var(--text-muted);">من تاريخ</label>
                                    <input type="date" id="reportFrom" value="2026-08-01" style="padding:8px 12px;border:2px solid rgba(0,107,115,0.06);border-radius:var(--radius-sm);font-size:13px;font-family:var(--font-family);background:var(--bg);color:var(--text-primary);outline:none;min-width:140px;">
                                </div>
                                <div class="filter-group" style="display:flex;flex-direction:column;gap:4px;">
                                    <label style="font-size:12px;font-weight:600;color:var(--text-muted);">إلى تاريخ</label>
                                    <input type="date" id="reportTo" value="2026-08-31" style="padding:8px 12px;border:2px solid rgba(0,107,115,0.06);border-radius:var(--radius-sm);font-size:13px;font-family:var(--font-family);background:var(--bg);color:var(--text-primary);outline:none;min-width:140px;">
                                </div>
                                <div class="filter-group" style="display:flex;flex-direction:column;gap:4px;">
                                    <label style="font-size:12px;font-weight:600;color:var(--text-muted);">الفرع</label>
                                    <select id="reportBranch" style="padding:8px 12px;border:2px solid rgba(0,107,115,0.06);border-radius:var(--radius-sm);font-size:13px;font-family:var(--font-family);background:var(--bg);color:var(--text-primary);outline:none;min-width:140px;">
                                        <option value="all">جميع الفروع</option>
                                        <option value="1">فرع الكرادة</option>
                                        <option value="2">فرع المنصور</option>
                                        <option value="3">فرع البصرة</option>
                                    </select>
                                </div>
                                <button class="btn-generate" onclick="generateReport()" style="padding:8px 24px;border:none;border-radius:var(--radius-sm);background:var(--primary-gradient);color:#fff;font-weight:700;cursor:pointer;font-family:var(--font-family);transition:var(--transition-base);height:40px;font-size:13px;">
                                    <i class="fas fa-file-pdf"></i> إنشاء التقرير
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="content-card" id="reportResult" style="display:none;">
                        <div class="card-header">
                            <h4><i class="fas fa-file-alt"></i> <span id="reportTitle">تقرير الحضور</span></h4>
                            <div style="display:flex;gap:8px;">
                                <button class="btn-primary" onclick="downloadReport()" style="font-size:12px;padding:6px 14px;">
                                    <i class="fas fa-file-csv"></i> CSV
                                </button>
                                <button class="btn-primary" onclick="printReportPDF()" style="font-size:12px;padding:6px 14px;">
                                    <i class="fas fa-file-pdf"></i> PDF
                                </button>
                            </div>
                        </div>
                        <div id="reportPrintHeader">
                            <h2 id="reportPrintCompany">شركة الصوى للصرافة</h2>
                            <span id="reportPrintMeta"></span>
                        </div>
                        <div class="card-body" id="reportContent"></div>
                    </div>
                </div>

                <!-- ==========================================================
                صفحة سعر الصرف
                ========================================================== -->
                <div id="page-exchange" class="page-section" style="display:none;">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
                        <h4 style="font-size:16px;font-weight:800;"><i class="fas fa-dollar-sign" style="color:var(--accent);"></i> إدارة سعر الصرف</h4>
                    </div>

                    <div class="content-card">
                        <div class="card-header">
                            <h4><i class="fas fa-edit"></i> تحديث سعر الصرف</h4>
                        </div>
                        <div class="card-body">
                            <div style="display:flex;gap:16px;flex-wrap:wrap;align-items:flex-end;">
                                <div class="form-group" style="display:flex;flex-direction:column;gap:4px;">
                                    <label style="font-size:12px;font-weight:600;color:var(--text-muted);">سعر صرف الدولار (د.ع)</label>
                                    <input type="number" id="exchangeRateInput2" value="1320" style="padding:10px 16px;border:2px solid rgba(0,107,115,0.06);border-radius:var(--radius-sm);font-size:16px;font-weight:700;width:200px;font-family:var(--font-family);background:var(--bg);">
                                </div>
                                <button class="btn-primary" onclick="updateExchangeRate2()" style="height:46px;padding:0 32px;">
                                    <i class="fas fa-save"></i> تحديث السعر
                                </button>
                            </div>
                            <div style="margin-top:16px;padding-top:16px;border-top:1px solid rgba(0,107,115,0.04);display:flex;gap:24px;flex-wrap:wrap;">
                                <div><span style="color:var(--text-muted);">السعر الحالي:</span> <strong style="font-size:20px;color:var(--primary);" id="exchangeRateDisplay2">1,320 د.ع</strong></div>
                                <div><span style="color:var(--text-muted);">آخر تحديث:</span> <strong id="exchangeRateLastUpdate">19/08/2026 15:30</strong></div>
                            </div>
                        </div>
                    </div>

                    <div class="content-card">
                        <div class="card-header">
                            <h4><i class="fas fa-history"></i> سجل تحديثات سعر الصرف</h4>
                        </div>
                        <div class="card-body">
                            <div class="table-wrap">
                                <table class="table">
                                    <thead><tr><th>التاريخ</th><th>السعر</th><th>تم التحديث بواسطة</th></tr></thead>
                                    <tbody id="rateHistoryBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- ==========================================================
                صفحة الإعدادات
                ========================================================== -->
                <div id="page-settings" class="page-section" style="display:none;">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
                        <h4 style="font-size:16px;font-weight:800;"><i class="fas fa-cog" style="color:var(--primary);"></i> إعدادات النظام</h4>
                    </div>

                    <div class="content-card">
                        <div class="card-header"><h4><i class="fas fa-building"></i> إعدادات الشركة</h4></div>
                        <div class="card-body">
                            <div style="display:flex;align-items:center;gap:16px;margin-bottom:16px;">
                                <div id="settingsLogoPreview" style="width:64px;height:64px;border-radius:12px;background:var(--primary-gradient);display:flex;align-items:center;justify-content:center;color:#fff;font-size:28px;overflow:hidden;flex-shrink:0;">✥</div>
                                <div class="form-group" style="flex:1;">
                                    <label style="font-size:12px;font-weight:600;color:var(--text-muted);">شعار الشركة (يظهر في كل الأقسام)</label>
                                    <input type="file" id="settingsLogoFile" accept=".jpg,.jpeg,.png,.webp" style="width:100%;padding:8px;border:2px solid rgba(0,107,115,0.06);border-radius:var(--radius-sm);font-family:var(--font-family);">
                                </div>
                            </div>
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                                <div class="form-group"><label style="font-size:12px;font-weight:600;color:var(--text-muted);">اسم الشركة</label><input type="text" id="settingsCompanyName" value="شركة الصوى للصرافة" style="width:100%;padding:10px 12px;border:2px solid rgba(0,107,115,0.06);border-radius:var(--radius-sm);font-family:var(--font-family);"></div>
                                <div class="form-group"><label style="font-size:12px;font-weight:600;color:var(--text-muted);">البريد الإلكتروني</label><input type="email" id="settingsCompanyEmail" value="hr@alsawwa.com" style="width:100%;padding:10px 12px;border:2px solid rgba(0,107,115,0.06);border-radius:var(--radius-sm);font-family:var(--font-family);"></div>
                                <div class="form-group"><label style="font-size:12px;font-weight:600;color:var(--text-muted);">وقت بداية الدوام</label><input type="time" id="settingsWorkStart" value="09:00" style="width:100%;padding:10px 12px;border:2px solid rgba(0,107,115,0.06);border-radius:var(--radius-sm);font-family:var(--font-family);"></div>
                                <div class="form-group"><label style="font-size:12px;font-weight:600;color:var(--text-muted);">وقت نهاية الدوام</label><input type="time" id="settingsWorkEnd" value="17:00" style="width:100%;padding:10px 12px;border:2px solid rgba(0,107,115,0.06);border-radius:var(--radius-sm);font-family:var(--font-family);"></div>
                                <div class="form-group"><label style="font-size:12px;font-weight:600;color:var(--text-muted);">مدة السماح بالتأخير (دقيقة)</label><input type="number" id="settingsLateGrace" value="15" min="0" style="width:100%;padding:10px 12px;border:2px solid rgba(0,107,115,0.06);border-radius:var(--radius-sm);font-family:var(--font-family);"></div>
                                <div class="form-group"><label style="font-size:12px;font-weight:600;color:var(--text-muted);">قيمة الخصم عن كل ساعة تأخير</label><input type="number" id="settingsLateDeduction" value="0" min="0" style="width:100%;padding:10px 12px;border:2px solid rgba(0,107,115,0.06);border-radius:var(--radius-sm);font-family:var(--font-family);"></div>
                            </div>
                            <button class="btn-primary" style="margin-top:12px;" onclick="settingsSave()">
                                <i class="fas fa-save"></i> حفظ الإعدادات
                            </button>
                        </div>
                    </div>
                </div>

            </div>
        </main>
    </div>

    <!-- ============================================================
    نافذة الملف الشخصي للموظف
    ============================================================ -->
    <div id="profileModal" style="display:none;position:fixed;inset:0;z-index:900;background:rgba(0,0,0,0.5);backdrop-filter:blur(6px);align-items:center;justify-content:center;padding:20px;">
        <div style="background:#fff;border-radius:20px;max-width:640px;width:100%;max-height:90vh;overflow-y:auto;box-shadow:0 12px 56px rgba(0,63,70,0.15);">
            <div style="padding:18px 22px;border-bottom:1px solid #e2ebeb;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:#fff;border-radius:20px 20px 0 0;">
                <h3 style="font-size:17px;font-weight:800;"><i class="fas fa-id-card" style="color:var(--primary);"></i> الملف الشخصي</h3>
                <button onclick="closeProfileModal()" style="width:34px;height:34px;border:none;border-radius:50%;background:rgba(0,63,70,0.06);color:var(--text-muted);cursor:pointer;font-size:16px;">✕</button>
            </div>
            <div style="padding:22px;" id="profileModalBody"></div>
        </div>
    </div>

    <!-- ============================================================
    شريط دعوة تثبيت التطبيق (PWA)
    ============================================================ -->
    <div id="pwaInstallBanner" style="display:none;position:fixed;top:0;left:0;right:0;background:var(--primary-dark);color:#fff;z-index:9999;padding:10px 16px;align-items:center;gap:10px;font-size:12.5px;">
        <img src="icons/icon-192.png" style="width:28px;height:28px;border-radius:8px;flex-shrink:0;">
        <span style="flex:1;">ثبّت تطبيق الموارد البشرية على جهازك للوصول السريع</span>
        <button onclick="installPwa()" style="background:var(--accent);color:#fff;border:none;padding:6px 14px;border-radius:var(--radius-full);font-weight:700;font-size:11.5px;cursor:pointer;white-space:nowrap;">تثبيت</button>
        <button onclick="dismissPwaBanner()" style="background:none;border:none;color:rgba(255,255,255,0.7);font-size:16px;cursor:pointer;padding:0 4px;">✕</button>
    </div>

    <!-- ============================================================
    بطاقة تأكيد منبثقة من الأسفل (بديل عن confirm() الأصلية بالمتصفح)
    ============================================================ -->
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

    <!-- ============================================================
    TOAST CONTAINER
    ============================================================ -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- ============================================================
    سكربتات JavaScript
    ============================================================ -->
    <script>
        // ============================================================
        // شبكة أمان: التقاط أي طلب فشل بصمت (مثال: قاعدة بيانات غير محدّثة)
        // بدل أن يظهر وكأن الزر لا يستجيب
        // ============================================================
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

        // ============================================================
        // PWA: تسجيل خدمة العامل + دعوة التثبيت عند أول استخدام
        // ============================================================
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

        // ============================================================
        // بطاقة تأكيد منبثقة (بديل عن confirm() الأصلية بالمتصفح)
        // ============================================================
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

        // ============================================================
        // المتغيرات العامة
        // ============================================================
        let toastId = 0;
        let currentPage = 'dashboard';
        let attendanceData = [];
        let salaryData = [];
        let requestsData = [];
        let briefingRequests = [];
        let branches = [];
        let companyNameForPrint = 'شركة الصوى للصرافة';
        let exchangeRate = 1320;
        let branchIdCounter = 1;
        let editingBranchId = null;

        // ============================================================
        // دوال تسجيل الدخول
        // ============================================================
        function handleLogin(e) {
            e.preventDefault();
            const email = document.getElementById('loginEmail').value;
            const password = document.getElementById('loginPassword').value;
            const btn = document.getElementById('loginBtn');
            const error = document.getElementById('loginError');

            btn.innerHTML = '<span style="display:inline-block;width:20px;height:20px;border:3px solid rgba(255,255,255,0.2);border-radius:50%;border-top-color:#fff;animation:spin 0.8s linear infinite;"></span> جاري التسجيل...';
            btn.disabled = true;
            error.style.display = 'none';

            const body = new URLSearchParams({ username: email, password: password });
            fetch('?ajax=login', { method: 'POST', body })
                .then(r => r.json())
                .then(data => {
                    btn.innerHTML = '<i class="fas fa-arrow-left"></i> تسجيل الدخول';
                    btn.disabled = false;
                    if (data.ok) {
                        document.getElementById('loginPage').style.display = 'none';
                        document.getElementById('appContainer').style.display = 'block';
                        showToast('✅ مرحباً بك', 'تم تسجيل الدخول بنجاح', 'success');
                        initData();
                    } else {
                        error.textContent = data.error || 'البريد الإلكتروني أو كلمة المرور غير صحيحة';
                        error.style.display = 'block';
                    }
                })
                .catch(() => {
                    btn.innerHTML = '<i class="fas fa-arrow-left"></i> تسجيل الدخول';
                    btn.disabled = false;
                    error.textContent = 'تعذر الاتصال بالخادم';
                    error.style.display = 'block';
                });
        }

        // ============================================================
        // تهيئة البيانات
        // ============================================================
        function initData() {
            updateDateTime();
            setInterval(updateDateTime, 1000);
            loadNotifications();
            setInterval(loadNotifications, 60000);
            requestNotifPermission();

            fetch('?ajax=bootstrap').then(r => r.json()).then(data => {
                if (!data.ok) return;
                exchangeRate = data.exchangeRate || 0;
                updateExchangeRateDisplay();
                loadRateHistory();
                renderTopEmployees(data.topEmployees || []);
                if (data.stats) {
                    renderAttendanceRing(data.stats.employees, data.stats.attendanceToday || {});
                    renderProfitRing(data.stats.profitMarginPct || 0);
                }
                renderBranchRevenueBars(data.branchRevenueShares || []);
                if (data.settings) {
                    const s = data.settings;
                    const byId = id => document.getElementById(id);
                    companyNameForPrint = s.company_name || 'شركة الصوى للصرافة';
                    if (byId('settingsCompanyName')) byId('settingsCompanyName').value = s.company_name || '';
                    if (s.company_logo && byId('settingsLogoPreview')) byId('settingsLogoPreview').innerHTML = `<img src="${s.company_logo}" style="width:100%;height:100%;object-fit:cover;">`;
                    if (byId('headerCompanyName')) byId('headerCompanyName').innerHTML = (s.company_name || 'نظام') + ' <span>الموارد البشرية</span>';
                    if (s.company_logo && byId('headerLogo')) byId('headerLogo').innerHTML = `<img src="${s.company_logo}" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;">`;
                    if (byId('settingsCompanyEmail')) byId('settingsCompanyEmail').value = s.company_email || '';
                    if (byId('settingsWorkStart')) byId('settingsWorkStart').value = (s.work_start_time || '').slice(0, 5);
                    if (byId('settingsWorkEnd')) byId('settingsWorkEnd').value = (s.work_end_time || '').slice(0, 5);
                    if (byId('settingsLateGrace')) byId('settingsLateGrace').value = s.late_grace_minutes ?? 15;
                    if (byId('settingsLateDeduction')) byId('settingsLateDeduction').value = s.late_deduction_per_hour ?? 0;
                }
                loadAttendance();
                loadSalaries();
                loadRequests();
                loadBriefingRequests();
                loadBranches();
                loadEmployeesFullList();
            });

            fetch('?ajax=employees_list').then(r => r.json()).then(data => {
                if (!data.ok) return;
                const manualSelect = document.getElementById('manualEmployeeSelect');
                if (manualSelect) {
                    manualSelect.innerHTML = data.employees.map(e => `<option value="${e.id}">${e.name}</option>`).join('');
                }
            });

            fetch('?ajax=branches').then(r => r.json()).then(data => {
                if (!data.ok) return;
                const select = document.getElementById('reportBranch');
                if (select) {
                    select.innerHTML = '<option value="0">جميع الفروع</option>' + data.branches.map(b => `<option value="${b.id}">${b.name}</option>`).join('');
                }
            });
        }

        // ============================================================
        // تحديث الوقت والتاريخ
        // ============================================================
        function updateDateTime() {
            const now = new Date();
            const dateStr = now.toLocaleDateString('ar-SA', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });
            document.getElementById('currentDateDisplay').textContent = dateStr;
            document.getElementById('stocksTime').textContent = now.toLocaleTimeString('ar-SA', { hour: '2-digit', minute: '2-digit' });
        }

        // ============================================================
        // التنقل بين الصفحات
        // ============================================================
        function navigateTo(page) {
            currentPage = page;
            document.querySelectorAll('.page-section').forEach(el => el.style.display = 'none');
            const target = document.getElementById('page-' + page);
            if (target) target.style.display = 'block';

            document.querySelectorAll('.sidebar .nav-item').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.sidebar .nav-item').forEach(el => {
                if (el.textContent.trim().includes(getPageTitle(page))) {
                    el.classList.add('active');
                }
            });

            const titles = {
                'dashboard': { title: 'لوحة التحكم', sub: 'نظرة عامة على أداء الشركة' },
                'branches': { title: 'الفروع', sub: 'إدارة الفروع ومسؤوليها' },
                'employees': { title: 'الموظفون', sub: 'ملفات الموظفين الكاملة والتقييمات' },
                'attendance': { title: 'الحضور', sub: 'سجل حضور الموظفين اليومي' },
                'salaries': { title: 'الرواتب', sub: 'إدارة رواتب الموظفين والمكافآت' },
                'briefing': { title: 'الإيجاز', sub: 'معاينة واعتماد الإيجازات' },
                'requests': { title: 'الطلبات', sub: 'إدارة طلبات الموظفين' },
                'reports': { title: 'التقارير', sub: 'إنشاء وعرض التقارير' },
                'exchange': { title: 'سعر الصرف', sub: 'تحديد سعر صرف الدولار' },
                'settings': { title: 'الإعدادات', sub: 'إعدادات النظام والشركة' },
                'branchDetail': { title: 'تفاصيل الفرع', sub: 'الموظفون والإيجازات المنشورة' }
            };
            document.getElementById('pageTitle').innerHTML = `<i class="fas ${getPageIcon(page)}"></i> ${titles[page]?.title || page}`;
            document.getElementById('pageSub').textContent = titles[page]?.sub || '';

            if (window.innerWidth <= 768) {
                document.getElementById('sidebar').classList.remove('open');
                document.getElementById('sidebarOverlay').classList.remove('show');
            }
        }

        function getPageTitle(page) {
            const titles = { 'dashboard': 'لوحة التحكم', 'branches': 'الفروع', 'employees': 'الموظفون', 'attendance': 'الحضور', 'salaries': 'الرواتب', 'briefing': 'الإيجاز', 'requests': 'الطلبات', 'reports': 'التقارير', 'exchange': 'سعر الصرف', 'settings': 'الإعدادات' };
            return titles[page] || page;
        }

        function getPageIcon(page) {
            const icons = { 'dashboard': 'fa-chart-pie', 'branches': 'fa-building', 'employees': 'fa-users', 'attendance': 'fa-clock', 'salaries': 'fa-wallet', 'briefing': 'fa-file-signature', 'requests': 'fa-file-pen', 'reports': 'fa-chart-bar', 'exchange': 'fa-dollar-sign', 'settings': 'fa-cog', 'branchDetail': 'fa-building' };
            return icons[page] || 'fa-circle';
        }

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('show');
        }

        // ============================================================
        // الإشعارات
        // ============================================================
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
                checkNewBrowserNotifications(data.notifications, 'lastNotifId_hr');
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
            });
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

        function handleLogout() {
            showConfirmSheet('تسجيل الخروج', 'هل أنت متأكد من رغبتك في تسجيل الخروج؟', function() {
                fetch('?ajax=logout', { method: 'POST' }).then(() => {
                    document.getElementById('appContainer').style.display = 'none';
                    document.getElementById('loginPage').style.display = 'flex';
                    showToast('👋 تم تسجيل الخروج', 'نتمنى رؤيتك قريباً', 'info');
                }).catch(() => {
                    showToast('❌ خطأ', 'تعذر الاتصال بالخادم', 'error');
                });
            });
        }

        // ============================================================
        // أفضل 3 موظفين حضور
        // ============================================================
        function renderTopEmployees(topEmployees) {
            const container = document.getElementById('topEmployees');
            if (!topEmployees || topEmployees.length === 0) {
                container.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:20px;color:var(--text-muted);">لا توجد بيانات حضور كافية بعد</div>';
                return;
            }
            let html = '';
            topEmployees.forEach(emp => {
                html += `
                    <div class="top-employee-card ${emp.color}">
                        <div class="medal">${emp.medal}</div>
                        <div class="avatar">${emp.name.charAt(0)}</div>
                        <div class="name">${emp.name}</div>
                        <div class="branch">${emp.branch}</div>
                        <div class="attendance-rate">${emp.rate}%</div>
                        <div class="attendance-label">نسبة الحضور</div>
                        <div class="badge-gold">#${emp.rank}</div>
                    </div>
                `;
            });
            container.innerHTML = html;
        }

        // ============================================================
        // حلقة حضور اليوم (دائرة حاضر/متأخر/غائب)
        // ============================================================
        function renderAttendanceRing(totalEmployees, att) {
            const p = att.presentPct || 0, l = att.latePct || 0, a = att.absentPct || 0;
            const ring = document.getElementById('attendanceRing');
            if (totalEmployees > 0 && (p + l + a) > 0) {
                ring.style.background = `conic-gradient(#059669 0% ${p}%, #D97706 ${p}% ${p + l}%, #DC2626 ${p + l}% ${p + l + a}%, #E5E7EB ${p + l + a}% 100%)`;
            } else {
                ring.style.background = 'conic-gradient(#E5E7EB 0% 100%)';
            }
            document.getElementById('ringEmployeeCount').textContent = totalEmployees;
            document.getElementById('legendPresentPct').textContent = p + '%';
            document.getElementById('legendLatePct').textContent = l + '%';
            document.getElementById('legendAbsentPct').textContent = a + '%';
        }

        // ============================================================
        // حلقة نسبة الربح الشهرية
        // ============================================================
        function renderProfitRing(pct) {
            const ring = document.getElementById('profitRing');
            const clamped = Math.max(0, Math.min(100, pct));
            const color = pct < 0 ? '#DC2626' : '#059669';
            ring.style.background = `conic-gradient(${color} 0% ${clamped}%, #E5E7EB ${clamped}% 100%)`;
            document.getElementById('ringProfitPct').textContent = pct + '%';
        }

        // ============================================================
        // مقارنة إيرادات الفروع (يحل محل شريط الأسهم الوهمي)
        // ============================================================
        function renderBranchRevenueBars(shares) {
            const chart = document.getElementById('stocksChart');
            const summary = document.getElementById('stocksSummary');
            if (!shares || shares.length === 0) {
                chart.innerHTML = '<div style="width:100%;text-align:center;color:var(--text-muted);font-size:12px;padding:20px 0;">لا توجد بيانات إيرادات كافية بعد</div>';
                if (summary) summary.innerHTML = '';
                return;
            }
            let html = '';
            shares.forEach(s => {
                const height = Math.max(s.pct, 3);
                html += `
                    <div class="branch-bar-wrap" title="${s.name}: ${s.pct}% (${Number(s.revenue).toLocaleString()} د.ع)">
                        <span class="branch-bar-pct">${s.pct}%</span>
                        <div class="branch-bar" style="height:${height}%;"></div>
                        <span class="branch-bar-label">${s.name}</span>
                    </div>
                `;
            });
            chart.innerHTML = html;
            if (summary) {
                const total = shares.reduce((sum, s) => sum + s.revenue, 0);
                const best = shares[0];
                summary.innerHTML = `
                    <span class="summary-item">إجمالي إيرادات الشهر: <span class="value">${Number(total).toLocaleString()}</span></span>
                    ${best && best.revenue > 0 ? `<span class="summary-item"><i class="fas fa-trophy" style="color:var(--accent);"></i> الأعلى: <span class="value">${best.name}</span></span>` : ''}
                `;
            }
        }

        // ============================================================
        // تحديث سعر الصرف
        // ============================================================
        function postExchangeRate(val) {
            const body = new URLSearchParams({ rate: val });
            fetch('?ajax=exchange_update', { method: 'POST', body }).then(r => r.json()).then(data => {
                if (data.ok) {
                    exchangeRate = val;
                    updateExchangeRateDisplay();
                    showToast('✅ تم التحديث', `تم تحديث سعر الصرف إلى ${val} د.ع`, 'success');
                    loadRateHistory();
                } else {
                    showToast('⚠️ تنبيه', data.error || 'تعذر التحديث', 'warning');
                }
            });
        }

        function updateExchangeRate() {
            const input = document.getElementById('exchangeRateInput');
            const val = parseFloat(input.value);
            if (val > 0) { postExchangeRate(val); }
            else { showToast('⚠️ تنبيه', 'الرجاء إدخال قيمة صحيحة', 'warning'); }
        }

        function updateExchangeRate2() {
            const input = document.getElementById('exchangeRateInput2');
            const val = parseFloat(input.value);
            if (val > 0) { postExchangeRate(val); }
            else { showToast('⚠️ تنبيه', 'الرجاء إدخال قيمة صحيحة', 'warning'); }
        }

        function loadRateHistory() {
            fetch('?ajax=rate_history').then(r => r.json()).then(data => {
                if (!data.ok) return;
                const tbody = document.getElementById('rateHistoryBody');
                if (!tbody) return;
                tbody.innerHTML = data.history.map(h => `<tr><td>${h.date}</td><td>${Number(h.rate).toLocaleString()} د.ع</td><td>${h.by}</td></tr>`).join('');
            });
        }

        function updateExchangeRateDisplay() {
            document.getElementById('exchangeRateDisplay').textContent = exchangeRate.toLocaleString();
            document.getElementById('exchangeRateDisplay2').textContent = exchangeRate.toLocaleString() + ' د.ع';
            const now = new Date();
            document.getElementById('exchangeRateLastUpdate').textContent = now.toLocaleDateString('ar-SA') + ' ' + now.toLocaleTimeString('ar-SA', { hour: '2-digit', minute: '2-digit' });
        }

        function settingsSave() {
            const fd = new FormData();
            fd.append('companyName', document.getElementById('settingsCompanyName').value);
            fd.append('companyEmail', document.getElementById('settingsCompanyEmail').value);
            fd.append('workStart', document.getElementById('settingsWorkStart').value);
            fd.append('workEnd', document.getElementById('settingsWorkEnd').value);
            fd.append('lateGrace', document.getElementById('settingsLateGrace').value);
            fd.append('lateDeduction', document.getElementById('settingsLateDeduction').value);
            const logoFile = document.getElementById('settingsLogoFile').files[0];
            if (logoFile) fd.append('logo', logoFile);
            fetch('?ajax=settings_save', { method: 'POST', body: fd }).then(r => r.json()).then(data => {
                if (data.ok) {
                    showToast('✅ تم الحفظ', 'تم حفظ إعدادات الشركة بنجاح', 'success');
                    if (data.logo) document.getElementById('settingsLogoPreview').innerHTML = `<img src="${data.logo}" style="width:100%;height:100%;object-fit:cover;">`;
                } else showToast('⚠️ خطأ', data.error || 'تعذر الحفظ', 'error');
            }).catch(() => {
                showToast('⚠️ خطأ', 'تعذر الاتصال بالخادم', 'error');
            });
        }

        // ============================================================
        // إدارة الفروع
        // ============================================================
        function loadBranches() {
            fetch('?ajax=branches').then(r => r.json()).then(data => {
                if (!data.ok) return;
                branches = data.branches;
                renderBranches();
                updateBranchBadge();
            });
        }

        function renderBranches() {
            const list = document.getElementById('branchList');
            if (!list) return;

            if (branches.length === 0) {
                list.innerHTML = `
                    <div style="text-align:center;padding:30px 20px;color:var(--text-muted);">
                        <i class="fas fa-building" style="font-size:48px;opacity:0.3;display:block;margin-bottom:12px;"></i>
                        <p>لا توجد فروع مسجلة</p>
                        <button class="btn-primary" style="margin-top:12px;" onclick="toggleBranchForm()">
                            <i class="fas fa-plus"></i> إضافة فرع جديد
                        </button>
                    </div>
                `;
                document.getElementById('branchCount').textContent = '0 فروع';
                return;
            }

            let html = '';
            branches.forEach((branch) => {
                const statusClass = branch.status === 'active' ? 'active' : 'inactive';
                const statusLabel = branch.status === 'active' ? '✅ نشط' : '❌ غير نشط';

                const profitIqd = branch.lastBriefProfit !== null ? (branch.lastBriefProfit >= 0 ? '+' : '') + Number(branch.lastBriefProfit).toLocaleString() + ' د.ع' : 'لا يوجد إيجاز بعد';
                const profitUsd = branch.lastBriefProfitUsd !== null ? (branch.lastBriefProfitUsd >= 0 ? '+' : '') + Number(branch.lastBriefProfitUsd).toLocaleString() + ' $' : '—';

                html += `
                    <div class="branch-card" onclick="viewBranch(${branch.id})">
                        <div class="branch-top">
                            <div>
                                <div class="branch-name"><i class="fas fa-building"></i> ${branch.name}</div>
                                <div style="font-size:12px;color:var(--text-secondary);">
                                    <i class="fas fa-user-tie"></i> مسؤول: ${branch.manager}
                                </div>
                            </div>
                            <span class="branch-status ${statusClass}">${statusLabel}</span>
                        </div>

                        <div class="branch-details">
                            <div class="detail-item">
                                <span class="label">💰 صافي آخر إيجاز (د.ع)</span>
                                <span class="value" style="color:${branch.lastBriefProfit >= 0 ? 'var(--success,#159447)' : 'var(--red)'};">${profitIqd}</span>
                            </div>
                            <div class="detail-item">
                                <span class="label">💵 صافي آخر إيجاز ($)</span>
                                <span class="value">${profitUsd}</span>
                            </div>
                        </div>

                        <div class="branch-actions" onclick="event.stopPropagation();">
                            <button class="btn-sm edit" onclick="editBranch(${branch.id})">
                                <i class="fas fa-edit"></i> تعديل
                            </button>
                            <button class="btn-sm delete" onclick="deleteBranch(${branch.id})">
                                <i class="fas fa-trash"></i> حذف
                            </button>
                        </div>
                    </div>
                `;
            });

            list.innerHTML = html;
            document.getElementById('branchCount').textContent = branches.length + ' فروع';
            updateBranchBadge();
        }

        function toggleBranchForm() {
            const form = document.getElementById('branchForm');
            if (form.style.display === 'none') {
                form.style.display = 'block';
                document.getElementById('branchFormTitle').textContent = editingBranchId ? 'تعديل الفرع' : 'إضافة فرع جديد';
                if (!editingBranchId) clearBranchForm();
            } else {
                form.style.display = 'none';
                editingBranchId = null;
                clearBranchForm();
            }
        }

        function clearBranchForm() {
            document.getElementById('branchName').value = '';
            document.getElementById('branchManager').value = '';
            document.getElementById('branchNationalId').value = '';
            document.getElementById('branchPhone').value = '';
            document.getElementById('branchBirthDate').value = '';
            document.getElementById('branchHireDate').value = '';
            document.getElementById('branchShiftStart').value = '';
            document.getElementById('branchShiftEnd').value = '';
            document.getElementById('branchNotes').value = '';
            document.getElementById('branchStatus').value = 'active';
            document.getElementById('branchPhotoLabel').textContent = 'اختر صورة';
            document.getElementById('branchDocsLabel').textContent = 'اختر ملف المستمسكات';
            document.getElementById('branchPhoto').value = '';
            document.getElementById('branchDocs').value = '';
            document.getElementById('branchManagerPassword').value = '';
            editingBranchId = null;
            document.getElementById('branchFormTitle').textContent = 'إضافة فرع جديد';
        }

        function updateFileLabel(inputId, labelId) {
            const input = document.getElementById(inputId);
            const label = document.getElementById(labelId);
            if (input.files && input.files[0]) {
                label.textContent = input.files[0].name;
            } else {
                label.textContent = inputId === 'branchPhoto' ? 'اختر صورة' : 'اختر ملف المستمسكات';
            }
        }

        function saveBranch() {
            const name = document.getElementById('branchName').value.trim();
            const manager = document.getElementById('branchManager').value.trim();
            const nationalId = document.getElementById('branchNationalId').value.trim();
            const phone = document.getElementById('branchPhone').value.trim();
            const photoInputEl = document.getElementById('branchPhoto');

            if (!name || !manager || !nationalId || !phone) {
                showToast('⚠️ تنبيه', 'اسم الفرع، اسم المسؤول، رقم الهوية، ورقم الهاتف مطلوبة', 'warning');
                return;
            }
            if (!editingBranchId && !(photoInputEl.files && photoInputEl.files[0])) {
                showToast('⚠️ تنبيه', 'صورة مسؤول الفرع مطلوبة عند إنشاء فرع جديد', 'warning');
                return;
            }

            const fd = new FormData();
            if (editingBranchId) fd.append('id', editingBranchId);
            fd.append('name', name);
            fd.append('manager', manager);
            fd.append('nationalId', nationalId);
            fd.append('phone', phone);
            fd.append('birthDate', document.getElementById('branchBirthDate').value);
            fd.append('hireDate', document.getElementById('branchHireDate').value);
            fd.append('shiftStart', document.getElementById('branchShiftStart').value);
            fd.append('shiftEnd', document.getElementById('branchShiftEnd').value);
            fd.append('notes', document.getElementById('branchNotes').value.trim());
            fd.append('status', document.getElementById('branchStatus').value);
            const managerPasswordEl = document.getElementById('branchManagerPassword');
            if (managerPasswordEl) fd.append('managerPassword', managerPasswordEl.value);

            const photoInput = document.getElementById('branchPhoto');
            const docsInput = document.getElementById('branchDocs');
            if (photoInput.files && photoInput.files[0]) fd.append('photo', photoInput.files[0]);
            if (docsInput.files && docsInput.files[0]) fd.append('docs', docsInput.files[0]);

            fetch('?ajax=branch_save', { method: 'POST', body: fd }).then(r => r.json()).then(data => {
                if (!data.ok) {
                    showToast('⚠️ خطأ', data.error || 'تعذر الحفظ', 'error');
                    return;
                }
                showToast(editingBranchId ? '✅ تم التحديث' : '✅ تم الإضافة', editingBranchId ? `تم تحديث بيانات فرع ${name}` : `تم إضافة فرع ${name} بنجاح`, 'success');
                editingBranchId = null;
                loadBranches();
                toggleBranchForm();
            });
        }

        function editBranch(id) {
            const branch = branches.find(b => b.id === id);
            if (!branch) return;

            editingBranchId = id;
            document.getElementById('branchName').value = branch.name;
            document.getElementById('branchManager').value = branch.manager;
            document.getElementById('branchNationalId').value = branch.nationalId;
            document.getElementById('branchPhone').value = branch.phone || '';
            document.getElementById('branchBirthDate').value = branch.birthDate || '';
            document.getElementById('branchHireDate').value = branch.hireDate || '';
            document.getElementById('branchShiftStart').value = branch.shiftStart || '';
            document.getElementById('branchShiftEnd').value = branch.shiftEnd || '';
            document.getElementById('branchNotes').value = branch.notes || '';
            document.getElementById('branchStatus').value = branch.status;

            if (branch.photo) document.getElementById('branchPhotoLabel').textContent = branch.photo;
            if (branch.docs) document.getElementById('branchDocsLabel').textContent = branch.docs;

            document.getElementById('branchFormTitle').textContent = 'تعديل الفرع';
            document.getElementById('branchForm').style.display = 'block';
            window.scrollTo({ top: document.getElementById('branchForm').offsetTop - 100, behavior: 'smooth' });
        }

        function viewBranch(id) {
            navigateTo('branchDetail');
            document.getElementById('bdEmployeesList').innerHTML = '<p style="color:var(--text-muted);font-size:12px;">جاري التحميل...</p>';
            document.getElementById('bdBriefsList').innerHTML = '';
            fetch('?ajax=branch_detail&id=' + id).then(r => r.json()).then(data => {
                if (!data.ok) { showToast('⚠️ خطأ', data.error || 'تعذر تحميل تفاصيل الفرع', 'error'); navigateTo('branches'); return; }
                renderBranchDetail(data);
            }).catch(() => {
                showToast('❌ خطأ', 'تعذر الاتصال بالخادم', 'error');
                navigateTo('branches');
            });
        }

        function renderBranchDetail(data) {
            const b = data.branch;
            document.getElementById('bdName').textContent = b.name;
            document.getElementById('bdManager').textContent = b.manager || 'غير محدد';
            document.getElementById('bdShift').textContent = (b.shiftStart && b.shiftEnd) ? `${b.shiftStart} - ${b.shiftEnd}` : 'غير محدد';
            const docsEl = document.getElementById('bdDocuments');
            if (docsEl) {
                docsEl.innerHTML = b.branchDocs ? `<a href="${b.branchDocs}" target="_blank"><i class="fas fa-id-card"></i> عرض مستمسكات مسؤول الفرع</a>` : 'لا توجد مستمسكات مرفوعة';
            }

            const pct = data.overallAttendanceRate || 0;
            document.getElementById('bdAttendancePct').textContent = pct + '%';
            document.getElementById('bdAttendanceRing').style.background = `conic-gradient(#059669 0% ${pct}%, #E5E7EB ${pct}% 100%)`;

            const empList = document.getElementById('bdEmployeesList');
            if (!data.employees || !data.employees.length) {
                empList.innerHTML = '<p style="color:var(--text-muted);font-size:12px;">لا يوجد موظفون في هذا الفرع</p>';
            } else {
                empList.innerHTML = data.employees.map(e => `
                    <div class="bd-employee-card">
                        <div class="avatar">${e.name.charAt(0)}</div>
                        <div style="min-width:0;">
                            <div class="name">${e.name}${e.isManager ? ' 👑' : ''}</div>
                            <div class="role">${e.job_title || ''} · ${e.shiftTypeText}</div>
                            <div class="role" style="color:${e.payrollText.startsWith('استلم') ? '#059669' : '#D97706'};"><i class="fas fa-money-bill-wave"></i> ${e.payrollText}</div>
                            ${e.documents ? `<a href="${e.documents}" target="_blank" style="font-size:10px;" onclick="event.stopPropagation()"><i class="fas fa-id-card"></i> مستمسكاته</a>` : ''}
                        </div>
                        <div class="rate" style="color:${e.attendanceRate >= 80 ? '#059669' : (e.attendanceRate >= 50 ? '#D97706' : '#DC2626')};">${e.attendanceRate}%</div>
                    </div>
                `).join('');
            }

            const briefsList = document.getElementById('bdBriefsList');
            if (!data.briefs || !data.briefs.length) {
                briefsList.innerHTML = '<p style="color:var(--text-muted);font-size:12px;">لا توجد إيجازات منشورة بعد</p>';
            } else {
                const statusColors = { pending: '#D97706', hr_approved: '#0A8A94', gm_approved: '#0A8A94', approved: '#059669', rejected: '#DC2626' };
                briefsList.innerHTML = data.briefs.map(br => `
                    <div class="bd-brief-card">
                        <span><i class="fas fa-calendar" style="color:var(--text-muted);"></i> ${br.date}</span>
                        <span><i class="fas fa-pen" style="color:var(--text-muted);"></i> ${br.sender}</span>
                        <span>الإيراد: <b>${Number(br.revenue).toLocaleString()}</b></span>
                        <span>الربح: <b style="color:#059669;">${Number(br.profit).toLocaleString()}</b></span>
                        <span class="bd-brief-status" style="background:${statusColors[br.status]}1A;color:${statusColors[br.status]};">${br.statusText}</span>
                        ${br.attachment ? `<a href="${br.attachment}" target="_blank" style="font-size:11px;"><i class="fas fa-paperclip"></i> الملف المرفق</a>` : ''}
                        <div style="width:100%;">${renderBriefEntriesBlock(br.entries, br.prevDayNetProfit)}</div>
                    </div>
                `).join('');
            }
        }

        function deleteBranch(id) {
            showConfirmSheet('حذف الفرع', 'هل أنت متأكد من حذف هذا الفرع؟', function() { doDeleteBranch(id); });
        }

        function doDeleteBranch(id) {
            const body = new URLSearchParams({ id });
            fetch('?ajax=branch_delete', { method: 'POST', body }).then(r => r.json()).then(data => {
                if (!data.ok) { showToast('⚠️ خطأ', data.error || 'تعذر الحذف', 'error'); return; }
                loadBranches();
                showToast('🗑️ تم الحذف', 'تم حذف الفرع بنجاح', 'warning');
                if (editingBranchId === id) {
                    editingBranchId = null;
                    document.getElementById('branchForm').style.display = 'none';
                }
            });
        }

        function updateBranchBadge() {
            const badge = document.getElementById('branchBadge');
            if (badge) badge.textContent = branches.length;
        }

        // ============================================================
        // تحميل بيانات الحضور
        // ============================================================
        function loadAttendance() {
            const dateInput = document.getElementById('attendanceDate');
            const date = dateInput ? dateInput.value : '2026-08-19';
            const dateLabel = document.getElementById('attendanceDateLabel');
            if (dateLabel) {
                const d = new Date(date + 'T00:00:00');
                dateLabel.textContent = d.toLocaleDateString('ar-SA', { day: 'numeric', month: 'long', year: 'numeric' });
            }

            fetch('?ajax=attendance&date=' + encodeURIComponent(date)).then(r => r.json()).then(data => {
                if (!data.ok) return;
                attendanceData = data.attendance;
                renderAttendance();
            });
        }

        function renderAttendance() {
            const tbody = document.getElementById('attendanceBody');
            if (!tbody) return;
            let html = '';
            attendanceData.forEach((item, index) => {
                const statusClass = item.status === 'حاضر' ? 'present' : (item.status === 'متأخر' ? 'late' : 'absent');
                html += `
                    <tr>
                        <td>${index + 1}</td>
                        <td>${item.name}</td>
                        <td>${item.branch}</td>
                        <td>${item.checkIn}</td>
                        <td>${item.checkOut}</td>
                        <td><span class="status-badge ${statusClass}">${item.status}</span></td>
                        <td>
                            <button class="action-btn approve" onclick="editAttendanceRow(${item.employeeId}, '${item.status === 'حاضر' ? 'present' : (item.status === 'متأخر' ? 'late' : 'absent')}')">
                                <i class="fas fa-edit"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        }

        function editAttendanceRow(employeeId, status) {
            document.getElementById('manualEmployeeSelect').value = employeeId;
            document.getElementById('manualStatus').value = status;
            document.getElementById('manualEmployeeSelect').scrollIntoView({ behavior: 'smooth', block: 'center' });
        }

        function saveManualAttendance() {
            const employeeId = document.getElementById('manualEmployeeSelect').value;
            const status = document.getElementById('manualStatus').value;
            const note = document.getElementById('manualNote').value;
            const date = document.getElementById('attendanceDate').value || new Date().toISOString().split('T')[0];
            if (!employeeId) { showToast('⚠️ تنبيه', 'الرجاء اختيار الموظف', 'warning'); return; }
            fetch('?ajax=attendance_set', { method: 'POST', body: new URLSearchParams({ employeeId, date, status, note }) })
                .then(r => r.json()).then(data => {
                    if (!data.ok) { showToast('⚠️ خطأ', data.error || 'تعذر الحفظ', 'error'); return; }
                    showToast('✅ تم الحفظ', 'تم تحديث سجل الحضور', 'success');
                    document.getElementById('manualNote').value = '';
                    loadAttendance();
                });
        }

        // ============================================================
        // تحميل بيانات الرواتب
        // ============================================================
        function loadSalaries() {
            const label = document.getElementById('salaryPeriodLabel');
            if (label) label.textContent = new Date().toLocaleDateString('ar-SA', { month: 'long', year: 'numeric' });
            fetch('?ajax=salaries').then(r => r.json()).then(data => {
                if (!data.ok) return;
                salaryData = data.salaries;
                renderSalaries();
            });
            fetch('?ajax=payroll_window_status').then(r => r.json()).then(data => {
                if (!data.ok) return;
                const banner = document.getElementById('payrollWindowBanner');
                const openBranches = data.branches.filter(b => b.open);
                if (openBranches.length > 0) {
                    banner.style.background = 'rgba(21,148,71,0.08)';
                    banner.style.color = 'var(--success, #159447)';
                    banner.innerHTML = `<i class="fas fa-unlock"></i> صلاحية تسليم الرواتب مفتوحة لـ ${openBranches.length} من ${data.branches.length} فرع: ${openBranches.map(b => b.name).join('، ')}`;
                } else {
                    banner.style.background = 'rgba(223,75,75,0.08)';
                    banner.style.color = '#df4b4b';
                    banner.innerHTML = '<i class="fas fa-lock"></i> صلاحية تسليم الرواتب مغلقة لكل الفروع هذا الشهر — يجب أن يفتحها المسؤول العام لكل فرع';
                }
            });
        }

        function deliverSalary(employeeId, name) {
            showConfirmSheet('تسليم الراتب', `تأكيد تسليم راتب ${name} لهذا الشهر؟`, function() { doDeliverSalary(employeeId, name); });
        }

        function doDeliverSalary(employeeId, name) {
            const body = new URLSearchParams({ employeeId });
            fetch('?ajax=salary_deliver', { method: 'POST', body }).then(r => r.json()).then(data => {
                if (!data.ok) { showToast('⚠️ تنبيه', data.error || 'تعذر التسليم', 'warning'); return; }
                loadSalaries();
                showToast('✅ تم التسليم', `تم تسليم راتب ${name} بصافي ${Number(data.net).toLocaleString()} دينار`, 'success');
            }).catch(() => {
                showToast('⚠️ خطأ', 'تعذر الاتصال بالخادم', 'error');
            });
        }

        function renderSalaries() {
            const tbody = document.getElementById('salaryBody');
            if (!tbody) return;
            let html = '';
            salaryData.forEach((item, index) => {
                const statusClass = item.status === 'مدفوع' ? 'approved' : 'pending';
                html += `
                    <tr>
                        <td>${index + 1}</td>
                        <td>${item.name}${item.isManager ? ' <span style="font-size:10px;color:var(--accent);">(مدير فرع)</span>' : ''}${item.hasAdvance ? `<br><span style="font-size:10px;color:#DC2626;"><i class="fas fa-hand-holding-usd"></i> لديه سلفة نشطة (خصم شهري ${Number(item.advanceMonthly).toLocaleString()})</span>` : ''}</td>
                        <td>${item.branch}</td>
                        <td>${item.base.toLocaleString()}</td>
                        <td style="color:#059669;">+${item.bonus.toLocaleString()}</td>
                        <td style="color:#DC2626;">-${item.deduction.toLocaleString()}</td>
                        <td><strong>${item.net.toLocaleString()}</strong></td>
                        <td><span class="status-badge ${statusClass}">${item.status}</span></td>
                        <td>
                            ${item.statusRaw !== 'delivered' ? (item.windowOpen ? `
                                <button class="action-btn approve" onclick="deliverSalary(${item.employeeId}, '${item.name}')" title="تسليم">
                                    <i class="fas fa-check"></i> تسليم
                                </button>
                            ` : `<span style="font-size:10.5px;color:var(--text-muted);"><i class="fas fa-lock"></i> الصلاحية مغلقة لفرع ${item.branch}</span>`) : '<span style="font-size:11px;color:var(--text-muted);">تم التسليم</span>'}
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        }

        // ============================================================
        // صفحة الموظفين — الملف الشخصي الكامل
        // ============================================================
        let employeesFullData = [];

        function loadEmployeesFullList() {
            fetch('?ajax=employees_full_list').then(r => r.json()).then(data => {
                if (!data.ok) return;
                employeesFullData = data.employees;
                renderEmployeesTable();
            });
        }

        function renderEmployeesTable() {
            const tbody = document.getElementById('employeesBody');
            if (!tbody) return;
            const search = (document.getElementById('employeeSearch')?.value || '').trim();
            const rows = employeesFullData.filter(e => !search || e.name.includes(search));
            tbody.innerHTML = rows.map((item, index) => `
                <tr>
                    <td>${index + 1}</td>
                    <td>${item.name}${item.isManager ? ' <span style="font-size:10px;color:var(--accent);">(مدير فرع)</span>' : ''}</td>
                    <td>${item.branch}</td>
                    <td>${item.job_title || '-'}</td>
                    <td>${item.shiftTypeText || '-'}</td>
                    <td>${Number(item.baseSalary).toLocaleString()}</td>
                    <td style="color:#059669;">${item.bonus > 0 ? '+' + Number(item.bonus).toLocaleString() : '-'}</td>
                    <td>${item.deduction > 0 ? `<span style="color:#DC2626;">-${Number(item.deduction).toLocaleString()}</span><br><span style="font-size:10px;color:var(--text-muted);">${item.deductionReason || ''}</span>` : '-'}</td>
                    <td>${Number(item.rating).toFixed(1)} ★</td>
                    <td><span class="status-badge ${item.status === 'active' ? 'approved' : 'rejected'}">${item.status === 'active' ? 'نشط' : 'غير نشط'}</span></td>
                    <td><button class="action-btn add" onclick="openProfile(${item.id})"><i class="fas fa-eye"></i> عرض الملف</button></td>
                </tr>
            `).join('');
        }

        function openProfile(employeeId) {
            fetch('?ajax=employee_profile&employeeId=' + employeeId).then(r => r.json()).then(data => {
                if (!data.ok) { showToast('⚠️ خطأ', data.error || 'تعذر تحميل الملف', 'error'); return; }
                const e = data.employee;
                const today = new Date().toISOString().split('T')[0];
                document.getElementById('profileModalBody').innerHTML = `
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:13px;margin-bottom:18px;">
                        <div><span style="color:var(--text-muted);">الاسم:</span> <b>${e.name}</b></div>
                        <div><span style="color:var(--text-muted);">اسم الأم:</span> <b>${e.motherName || '-'}</b></div>
                        <div><span style="color:var(--text-muted);">رقم الهاتف:</span> <b>${e.phoneNumber || '-'}</b></div>
                        <div><span style="color:var(--text-muted);">رقم الهوية الوطنية:</span> <b>${e.nationalId || '-'}</b></div>
                        <div><span style="color:var(--text-muted);">الرقم الوظيفي:</span> <b>${e.employeeNumber}</b></div>
                        <div><span style="color:var(--text-muted);">الفرع:</span> <b>${e.branch}</b></div>
                        <div><span style="color:var(--text-muted);">المسمى الوظيفي:</span> <b>${e.jobTitle || '-'}</b></div>
                        <div><span style="color:var(--text-muted);">تاريخ الولادة:</span> <b>${e.birthDate || '-'}</b></div>
                        <div><span style="color:var(--text-muted);">تاريخ التعيين:</span> <b>${e.hireDate || '-'}</b></div>
                        <div><span style="color:var(--text-muted);">الشفت:</span> <b>${e.shiftType || '-'}</b></div>
                        <div><span style="color:var(--text-muted);">دوام:</span> <b>${e.shiftStart || '-'} — ${e.shiftEnd || '-'}</b></div>
                        <div><span style="color:var(--text-muted);">الراتب الأساسي:</span> <b>${Number(e.baseSalary).toLocaleString()}</b></div>
                        <div><span style="color:var(--text-muted);">الحالة:</span> <b>${e.status === 'active' ? 'نشط' : 'غير نشط'}</b></div>
                        ${e.documents ? `<div><span style="color:var(--text-muted);">المستمسكات:</span> <a href="${e.documents}" target="_blank">عرض الملف</a></div>` : ''}
                    </div>

                    <div style="background:rgba(0,107,115,0.04);border-radius:10px;padding:14px;margin-bottom:18px;">
                        <label style="font-size:12px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:6px;">التقييم (0 - 5) — يحدده HR فقط</label>
                        <div style="display:flex;gap:8px;">
                            <input type="number" id="ratingInput" min="0" max="5" step="0.1" value="${e.rating}" style="flex:1;padding:8px 12px;border:2px solid rgba(0,107,115,0.08);border-radius:8px;font-family:var(--font-family);">
                            <button class="btn-primary" onclick="saveRating(${e.id})"><i class="fas fa-save"></i> حفظ التقييم</button>
                        </div>
                    </div>

                    <div style="border-top:1px solid #e2ebeb;padding-top:14px;margin-bottom:14px;">
                        <label style="font-size:12px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:6px;">التحقق من الحضور واستلام الراتب في تاريخ محدد</label>
                        <div style="display:flex;gap:8px;margin-bottom:10px;">
                            <input type="date" id="checkDate_${e.id}" value="${today}" style="flex:1;padding:8px 12px;border:2px solid rgba(0,107,115,0.08);border-radius:8px;font-family:var(--font-family);">
                            <button class="btn-primary" onclick="checkEmployeeDay(${e.id})"><i class="fas fa-search"></i> تحقق</button>
                        </div>
                        <div id="dayCheckResult_${e.id}" style="font-size:13px;"></div>
                    </div>

                    <div style="border-top:1px solid #e2ebeb;padding-top:14px;">
                        <label style="font-size:12px;font-weight:700;color:var(--text-muted);display:block;margin-bottom:6px;">سجل حضور الشهر الحالي</label>
                        <div class="table-wrap" style="max-height:260px;overflow-y:auto;">
                            <table class="table">
                                <thead><tr><th>التاريخ</th><th>دخول</th><th>انصراف</th><th>الحالة</th></tr></thead>
                                <tbody id="monthTable_${e.id}"></tbody>
                            </table>
                        </div>
                    </div>
                `;
                document.getElementById('profileModal').style.display = 'flex';
                loadEmployeeMonthTable(e.id);
            });
        }

        function loadEmployeeMonthTable(employeeId) {
            fetch('?ajax=employee_attendance_month&employeeId=' + employeeId).then(r => r.json()).then(data => {
                if (!data.ok) return;
                const tbody = document.getElementById('monthTable_' + employeeId);
                if (!tbody) return;
                if (!data.days.length) {
                    tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--text-muted);">لا يوجد سجل حضور لهذا الشهر</td></tr>';
                    return;
                }
                tbody.innerHTML = data.days.map(d => `<tr><td>${d.date}</td><td>${d.checkIn || '-'}</td><td>${d.checkOut || '-'}</td><td>${d.status}</td></tr>`).join('');
            });
        }

        function closeProfileModal() {
            document.getElementById('profileModal').style.display = 'none';
        }

        function saveRating(employeeId) {
            const rating = document.getElementById('ratingInput').value;
            fetch('?ajax=rating_save', { method: 'POST', body: new URLSearchParams({ employeeId, rating }) })
                .then(r => r.json()).then(data => {
                    if (!data.ok) { showToast('⚠️ خطأ', data.error || 'تعذر الحفظ', 'error'); return; }
                    showToast('✅ تم الحفظ', 'تم تحديث تقييم الموظف', 'success');
                    loadEmployeesFullList();
                });
        }

        function checkEmployeeDay(employeeId) {
            const date = document.getElementById('checkDate_' + employeeId).value;
            fetch('?ajax=employee_day_check&employeeId=' + employeeId + '&date=' + date).then(r => r.json()).then(data => {
                if (!data.ok) return;
                const box = document.getElementById('dayCheckResult_' + employeeId);
                let html = '';
                if (data.attendance) {
                    html += `<div style="padding:8px 0;border-bottom:1px solid #e2ebeb;">الحضور: <b>${data.attendance.status}</b> — دخول: ${data.attendance.checkIn || '-'} — انصراف: ${data.attendance.checkOut || '-'}</div>`;
                } else {
                    html += `<div style="padding:8px 0;border-bottom:1px solid #e2ebeb;color:var(--text-muted);">لا يوجد سجل حضور في هذا التاريخ</div>`;
                }
                if (data.payroll) {
                    html += `<div style="padding:8px 0;">راتب شهر هذا التاريخ: <b>${data.payroll.delivered ? 'تم الاستلام (' + Number(data.payroll.net).toLocaleString() + ')' : 'لم يُسلَّم بعد'}</b></div>`;
                } else {
                    html += `<div style="padding:8px 0;color:var(--text-muted);">لا يوجد سجل راتب لشهر هذا التاريخ</div>`;
                }
                box.innerHTML = html;
            });
        }

        // ============================================================
        // نظام الإيجاز - تم تصحيحه
        // ============================================================
        let briefingSelectedBranchId = null;
        let briefingDateFilter = null;
        let briefingBranchList = [];

        function loadBriefingRequests() {
            fetch('?ajax=briefs').then(r => r.json()).then(data => {
                if (data.ok) updateBriefingBadge(data.pendingCount);
            }).catch(() => {});

            if (briefingSelectedBranchId) {
                loadBriefingBranchDetail(briefingSelectedBranchId, briefingDateFilter);
            } else {
                fetch('?ajax=branches').then(r => r.json()).then(data => {
                    if (!data.ok) return;
                    briefingBranchList = data.branches;
                    renderBriefingBranchGrid();
                }).catch(() => {});
            }
        }

        function renderBriefingBranchGrid() {
            const list = document.getElementById('briefingDayList');
            if (!list) return;
            document.getElementById('briefingDetailPanel').style.display = 'none';
            list.style.display = '';

            if (briefingBranchList.length === 0) {
                list.innerHTML = `
                    <div style="text-align:center;padding:30px 20px;color:var(--text-muted);grid-column:1/-1;">
                        <i class="fas fa-inbox" style="font-size:40px;opacity:0.3;display:block;margin-bottom:10px;"></i>
                        <p style="font-size:13px;">لا توجد فروع بعد</p>
                    </div>
                `;
                document.getElementById('briefingCount').textContent = '0 فرع';
                return;
            }

            list.innerHTML = briefingBranchList.map(b => `
                <div class="brief-day-card" onclick="openBriefingBranch(${b.id})">
                    <div class="brief-day-icon"><i class="fas fa-building"></i></div>
                    <div class="brief-day-branch">${b.name}</div>
                </div>
            `).join('');
            document.getElementById('briefingCount').textContent = briefingBranchList.length + ' فرع';
        }

        function openBriefingBranch(branchId) {
            briefingSelectedBranchId = branchId;
            briefingDateFilter = null;
            const dateInput = document.getElementById('briefingDateInput');
            if (dateInput) dateInput.value = '';
            document.getElementById('briefingDayList').style.display = 'none';
            document.getElementById('briefingDetailPanel').style.display = 'block';
            document.getElementById('briefingDetailContent').innerHTML = '<p style="color:var(--text-muted);font-size:12px;text-align:center;padding:20px;">جاري التحميل...</p>';
            loadBriefingBranchDetail(branchId, null);
        }

        function loadBriefingBranchDetail(branchId, date) {
            let url = '?ajax=briefs&branchId=' + branchId;
            if (date) url += '&date=' + encodeURIComponent(date);
            fetch(url).then(r => r.json()).then(data => {
                if (!data.ok) return;
                briefingRequests = data.briefs;
                updateBriefingBadge(data.pendingCount);
                renderBriefingHistoryFeed();
            }).catch(() => {});
        }

        function filterBriefingByDate() {
            const date = document.getElementById('briefingDateInput').value;
            if (!date) { showToast('⚠️ تنبيه', 'اختر تاريخاً أولاً', 'warning'); return; }
            briefingDateFilter = date;
            loadBriefingBranchDetail(briefingSelectedBranchId, date);
        }

        function clearBriefingDateFilter() {
            briefingDateFilter = null;
            const dateInput = document.getElementById('briefingDateInput');
            if (dateInput) dateInput.value = '';
            loadBriefingBranchDetail(briefingSelectedBranchId, null);
        }

        function closeBriefingDetail() {
            briefingSelectedBranchId = null;
            briefingDateFilter = null;
            document.getElementById('briefingDetailPanel').style.display = 'none';
            renderBriefingBranchGrid();
        }

        function renderBriefingHistoryFeed() {
            const content = document.getElementById('briefingDetailContent');
            if (!content) return;
            if (briefingRequests.length === 0) {
                content.innerHTML = `<p style="color:var(--text-muted);font-size:13px;text-align:center;padding:20px;">لا توجد إيجازات ${briefingDateFilter ? 'في هذا التاريخ' : 'منشورة بعد لهذا الفرع'}</p>`;
                return;
            }
            let html = '';
            let dividerInserted = !!briefingDateFilter;
            briefingRequests.forEach(item => {
                if (!dividerInserted && !item.isToday) {
                    html += `<div style="display:flex;align-items:center;gap:10px;margin:14px 0;color:var(--text-muted);font-size:11.5px;"><span style="flex:1;height:1px;background:rgba(0,107,115,0.12);"></span>إيجازات سابقة<span style="flex:1;height:1px;background:rgba(0,107,115,0.12);"></span></div>`;
                    dividerInserted = true;
                }
                html += renderBriefingCard(item);
            });
            content.innerHTML = html;
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

        function renderBriefingCard(item) {
            const statusClass = item.status === 'approved' ? 'approved' : (item.status === 'rejected' ? 'rejected' : 'pending');
            const statusColors = { pending: '#D97706', hr_approved: '#0A8A94', gm_approved: '#0A8A94', approved: '#10B981', rejected: '#DC2626' };
            const statusIcons = { pending: '⏳', hr_approved: '📤', gm_approved: '📥', approved: '✅', rejected: '❌' };

            return `
                <div class="briefing-card" style="border-right-color: ${statusColors[item.status] || '#D97706'};">
                    <div class="briefing-top">
                        <div>
                            <div class="sender">
                                <i class="fas fa-user-circle"></i> ${item.sender}
                                <span style="font-size:11px;color:var(--text-muted);font-weight:400;"> - ${item.senderRole}</span>
                            </div>
                            <div style="font-size:12px;color:var(--text-muted);"><i class="fas fa-building"></i> ${item.branch}</div>
                        </div>
                        <div>
                            <span class="briefing-status ${statusClass}">${statusIcons[item.status] || ''} ${item.statusText}</span>
                            <span class="date" style="margin-right:10px;">${item.date}${item.isToday ? ' (اليوم)' : ''}</span>
                        </div>
                    </div>

                    <div class="briefing-details">
                        <div class="detail-item">
                            <span class="label">📉 المصاريف</span>
                            <span class="value">${item.expenses.toLocaleString()}</span>
                        </div>
                        <div class="detail-item">
                            <span class="label">📈 الإيرادات</span>
                            <span class="value" style="color:#059669;">${item.revenue.toLocaleString()}</span>
                        </div>
                        <div class="detail-item">
                            <span class="label">🧳 عدد المسافرين</span>
                            <span class="value">${item.travelersCount}</span>
                        </div>
                        <div class="detail-item">
                            <span class="label">💰 صافي الربح</span>
                            <span class="value" style="color:#059669;font-size:16px;">${item.netProfit.toLocaleString()}</span>
                        </div>
                    </div>

                    ${renderBriefEntriesBlock(item.entries, item.prevDayNetProfit)}

                    ${item.note ? `
                        <div class="briefing-note">
                            <strong><i class="fas fa-comment"></i> ملاحظة المرسل:</strong>
                            ${item.note}
                        </div>
                    ` : ''}

                    ${item.attachment ? `
                        <div class="briefing-note">
                            <a href="${item.attachment}" target="_blank"><i class="fas fa-paperclip"></i> عرض الملف المرفق مع الإيجاز</a>
                        </div>
                    ` : ''}

                    ${item.hrNote ? `
                        <div class="briefing-note" style="border-color:var(--primary);background:rgba(0,107,115,0.03);">
                            <strong><i class="fas fa-check-circle" style="color:var(--primary);"></i> ملاحظة HR:</strong>
                            ${item.hrNote}
                        </div>
                    ` : ''}

                    ${item.canReview ? `
                        <div class="briefing-actions">
                            <input type="text" class="hr-note-input" id="hrNote_${item.id}" placeholder="أضف ملاحظة (اختياري)...">
                            <button class="btn-success" onclick="approveBriefing(${item.id})">
                                <i class="fas fa-check"></i> اعتماد
                            </button>
                            <button class="btn-danger" onclick="rejectBriefing(${item.id})">
                                <i class="fas fa-times"></i> رفض
                            </button>
                        </div>
                    ` : `
                        <div style="font-size:12px;color:var(--text-muted);padding-top:8px;">
                            <i class="fas fa-info-circle" style="color:${statusColors[item.status] || '#666'};"></i>
                            ${item.statusText}
                        </div>
                    `}
                </div>
            `;
        }

        function reviewBriefing(id, decision) {
            const item = briefingRequests.find(b => Number(b.id) === Number(id));
            const noteInput = document.getElementById(`hrNote_${id}`);
            const hrNote = noteInput ? noteInput.value.trim() : '';
            const body = new URLSearchParams({ id, decision, hrNote });
            fetch('?ajax=brief_review', { method: 'POST', body }).then(r => r.json()).then(data => {
                if (!data.ok) { showToast('⚠️ خطأ', data.error || 'تعذر الحفظ', 'error'); return; }
                loadBriefingRequests();
                const who = item ? `${item.sender} - ${item.branch}` : 'الإيجاز';
                if (decision === 'approved') showToast('✅ تمت الموافقة', `تمت موافقتك على إيجاز ${who}، بانتظار الاعتماد النهائي من المسؤول العام`, 'success');
                else showToast('❌ تم الرفض', `تم رفض إيجاز ${who}`, 'error');
            }).catch(() => {
                showToast('❌ خطأ', 'تعذر الاتصال بالخادم — تأكد من تشغيل migrate.php على قاعدة البيانات', 'error');
            });
        }

        function approveBriefing(id) { reviewBriefing(id, 'approved'); }
        function rejectBriefing(id) { reviewBriefing(id, 'rejected'); }

        function updateBriefingBadge(count) {
            const pending = typeof count === 'number' ? count : briefingRequests.filter(b => b.status === 'pending').length;
            const badge = document.getElementById('briefingBadge');
            if (badge) {
                badge.textContent = pending;
                badge.style.display = pending > 0 ? 'inline-block' : 'none';
            }
        }

        // ============================================================
        // تحميل بيانات الطلبات
        // ============================================================
        function loadRequests() {
            fetch('?ajax=requests').then(r => r.json()).then(data => {
                if (!data.ok) return;
                requestsData = data.requests;
                renderRequests();
                document.getElementById('requestsCount').textContent = requestsData.length + ' طلبات';
            });
        }

        function renderRequests() {
            const tbody = document.getElementById('requestsBody');
            if (!tbody) return;
            let html = '';
            requestsData.forEach((item, index) => {
                const statusClass = item.status === 'مقبول' ? 'approved' : (item.status === 'مرفوض' ? 'rejected' : 'pending');
                html += `
                    <tr>
                        <td>${index + 1}</td>
                        <td>${item.name}</td>
                        <td>${item.type}</td>
                        <td>${item.details}</td>
                        <td>${item.date}</td>
                        <td><span class="status-badge ${statusClass}">${item.status}</span></td>
                        <td>
                            ${item.canReview ? `
                                ${item.rawType === 'advance' ? `<input type="number" min="1" step="1000" class="hr-note-input" id="hrDeduction_${item.id}" placeholder="⚠ الخصم الشهري (إلزامي للموافقة)">` : ''}
                                <input type="text" class="hr-note-input" id="hrReqNote_${item.id}" placeholder="رد برسالة (اختياري)...">
                                <button class="action-btn approve" onclick="approveRequest(${item.id}, '${item.rawType}', '${item.type}')">
                                    <i class="fas fa-check"></i> موافقة
                                </button>
                                <button class="action-btn reject" onclick="rejectRequest(${item.id}, '${item.rawType}', '${item.type}')">
                                    <i class="fas fa-times"></i> رفض
                                </button>
                            ` : `
                                <span style="color:var(--text-muted);font-size:11px;">${item.branchNote ? 'ملاحظة الفرع: ' + item.branchNote : 'تم الرد'}</span>
                            `}
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        }

        function reviewRequest(id, decision, rawType, typeLabel) {
            const params = { id, decision };
            if (decision === 'approved' && rawType === 'advance') {
                const deductionInput = document.getElementById(`hrDeduction_${id}`);
                const monthlyDeduction = deductionInput ? deductionInput.value : '';
                if (!monthlyDeduction || Number(monthlyDeduction) <= 0) {
                    showToast('⚠️ تنبيه', 'يرجى إدخال قيمة الخصم الشهري قبل الموافقة على السلفة — الحقل المحدد بالأحمر', 'warning', 5000);
                    if (deductionInput) {
                        deductionInput.classList.add('input-error');
                        deductionInput.focus();
                        setTimeout(() => deductionInput.classList.remove('input-error'), 1200);
                    }
                    return;
                }
                params.monthlyDeduction = monthlyDeduction;
            }
            const noteInput = document.getElementById(`hrReqNote_${id}`);
            params.hrNote = noteInput ? noteInput.value.trim() : '';
            const body = new URLSearchParams(params);
            fetch('?ajax=request_review', { method: 'POST', body }).then(r => r.json()).then(data => {
                if (!data.ok) { showToast('⚠️ خطأ', data.error || 'تعذر الحفظ — قد يكون الطلب رُوجع بالفعل، يتم تحديث القائمة', 'error'); loadRequests(); return; }
                loadRequests();
                if (decision === 'approved') showToast('✅ تمت الموافقة', `تم قبول طلب ${typeLabel}`, 'success');
                else showToast('❌ تم الرفض', `تم رفض طلب ${typeLabel}`, 'error');
            }).catch(() => {
                showToast('❌ خطأ', 'تعذر الاتصال بالخادم — تأكد من تشغيل migrate.php على قاعدة البيانات', 'error');
            });
        }

        function approveRequest(id, rawType, typeLabel) { reviewRequest(id, 'approved', rawType, typeLabel); }
        function rejectRequest(id, rawType, typeLabel) { reviewRequest(id, 'rejected', rawType, typeLabel); }

        function filterRequests(filter) {
            const rows = document.querySelectorAll('#requestsBody tr');
            rows.forEach(row => {
                const status = row.querySelector('.status-badge')?.textContent || '';
                if (filter === 'all') row.style.display = '';
                else if (filter === 'pending') row.style.display = status.includes('قيد المراجعة') ? '' : 'none';
            });
        }

        // ============================================================
        // التقارير
        // ============================================================
        function attendanceTable(rows) {
            if (!rows || rows.length === 0) return '<p style="color:var(--text-muted);text-align:center;padding:12px;">لا توجد بيانات حضور ضمن هذا النطاق</p>';
            let html = '<div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>الموظف</th><th>الفرع</th><th>وقت الدخول</th><th>وقت الخروج</th><th>الحالة</th></tr></thead><tbody>';
            rows.forEach((item, i) => {
                html += `<tr><td>${i+1}</td><td>${item.name}</td><td>${item.branch}</td><td>${item.checkIn}</td><td>${item.checkOut}</td><td><span class="status-badge ${item.status === 'حاضر' ? 'present' : (item.status === 'متأخر' ? 'late' : 'absent')}">${item.status}</span></td></tr>`;
            });
            return html + '</tbody></table></div>';
        }
        function salariesTable(rows) {
            if (!rows || rows.length === 0) return '<p style="color:var(--text-muted);text-align:center;padding:12px;">لا توجد بيانات رواتب ضمن هذا النطاق</p>';
            let html = '<div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>الموظف</th><th>الفرع</th><th>الراتب الأساسي</th><th>المكافأة</th><th>الخصم</th><th>الصافي</th></tr></thead><tbody>';
            rows.forEach((item, i) => {
                html += `<tr><td>${i+1}</td><td>${item.name}</td><td>${item.branch}</td><td>${item.base}</td><td style="color:#059669;">+${item.bonus}</td><td style="color:#DC2626;">-${item.deduction}</td><td><strong>${item.net}</strong></td></tr>`;
            });
            return html + '</tbody></table></div>';
        }
        function briefingTable(rows) {
            if (!rows || rows.length === 0) return '<p style="color:var(--text-muted);text-align:center;padding:12px;">لا توجد بيانات إيجاز ضمن هذا النطاق</p>';
            let html = '<div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>الفرع</th><th>كاتب الإيجاز</th><th>التاريخ</th><th>الإيرادات</th><th>المصاريف</th><th>صافي الربح</th><th>الحالة</th><th>ملاحظة HR</th><th>ملاحظة المسؤول العام</th></tr></thead><tbody>';
            rows.forEach((item, i) => {
                html += `<tr><td>${i+1}</td><td>${item.branch}</td><td>${item.sender || '-'}</td><td>${item.date}</td><td>${item.revenue}</td><td>${item.expense}</td><td style="color:#059669;">${item.profit}</td><td>${item.statusText || '-'}</td><td>${item.hrNote || '-'}</td><td>${item.gmNote || '-'}</td></tr>`;
            });
            return html + '</tbody></table></div>';
        }

        function generateReport() {
            const type = document.getElementById('reportType').value;
            const from = document.getElementById('reportFrom').value;
            const to = document.getElementById('reportTo').value;
            const branchSelect = document.getElementById('reportBranch');
            const branch = branchSelect.value;
            const branchLabel = branch === '0' || !branch ? 'جميع الفروع' : branchSelect.options[branchSelect.selectedIndex].text;

            const typeNames = {
                'attendance': 'تقرير الحضور',
                'salaries': 'تقرير الرواتب',
                'briefing': 'تقرير الإيجاز',
                'all': 'تقرير شامل'
            };
            document.getElementById('reportTitle').textContent = typeNames[type] || 'تقرير';
            const content = document.getElementById('reportContent');

            const qs = new URLSearchParams({ type, from, to, branch: branch || '0' });
            fetch('?ajax=report&' + qs.toString()).then(r => r.json()).then(data => {
                if (!data.ok) { showToast('⚠️ خطأ', 'تعذر إنشاء التقرير', 'error'); return; }

                let html = `
                    <div style="margin-bottom:12px;padding:12px;background:var(--bg);border-radius:var(--radius-md);">
                        <div style="display:flex;gap:16px;flex-wrap:wrap;font-size:13px;">
                            <span><strong>نوع التقرير:</strong> ${typeNames[type]}</span>
                            <span><strong>الفترة:</strong> ${from} إلى ${to}</span>
                            <span><strong>الفرع:</strong> ${branchLabel}</span>
                            <span><strong>تاريخ الإنشاء:</strong> ${new Date().toLocaleDateString('ar-SA')}</span>
                        </div>
                    </div>
                `;

                if (type === 'attendance') html += attendanceTable(data.attendance);
                else if (type === 'salaries') html += salariesTable(data.salaries);
                else if (type === 'briefing') html += briefingTable(data.briefing);
                else {
                    html += '<h5 style="margin:14px 0 6px;"><i class="fas fa-clock"></i> الحضور</h5>' + attendanceTable(data.attendance);
                    html += '<h5 style="margin:14px 0 6px;"><i class="fas fa-wallet"></i> الرواتب</h5>' + salariesTable(data.salaries);
                    html += '<h5 style="margin:14px 0 6px;"><i class="fas fa-chart-simple"></i> الإيجاز</h5>' + briefingTable(data.briefing);
                }

                html += `
                    <div style="margin-top:12px;padding:12px;background:rgba(16,185,129,0.04);border-radius:var(--radius-md);text-align:center;font-size:13px;color:var(--text-muted);">
                        <i class="fas fa-info-circle"></i> تم إنشاء التقرير بواسطة نظام إدارة الموارد البشرية
                    </div>
                `;

                content.innerHTML = html;
                document.getElementById('reportPrintCompany').textContent = companyNameForPrint;
                document.getElementById('reportPrintMeta').textContent = `${typeNames[type]} — من ${from} إلى ${to} — ${branchLabel}`;
                document.getElementById('reportResult').style.display = 'block';
                showToast('📊 تم الإنشاء', 'تم إنشاء التقرير بنجاح', 'success');
            });
        }

        function downloadReport() {
            const type = document.getElementById('reportType').value;
            const from = document.getElementById('reportFrom').value;
            const to = document.getElementById('reportTo').value;
            const branch = document.getElementById('reportBranch').value || '0';
            const qs = new URLSearchParams({ type, from, to, branch });
            showToast('📥 جاري التحميل', 'يتم تحميل التقرير...', 'info');
            window.location.href = '?ajax=report_download&' + qs.toString();
        }

        function printReportPDF() {
            if (document.getElementById('reportResult').style.display === 'none') {
                showToast('⚠️ تنبيه', 'الرجاء إنشاء التقرير أولاً', 'warning');
                return;
            }
            showToast('🖨️ جاري التجهيز', 'اختر "حفظ كـ PDF" من نافذة الطباعة', 'info');
            setTimeout(() => window.print(), 300);
        }

        // ============================================================
        // Toast Notifications
        // ============================================================
        function showToast(title, message, type = 'info', duration = 3000) {
            const container = document.getElementById('toastContainer');
            const id = ++toastId;
            const icons = {
                'success': 'fas fa-check-circle',
                'info': 'fas fa-info-circle',
                'warning': 'fas fa-exclamation-triangle',
                'error': 'fas fa-times-circle'
            };

            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.id = `toast-${id}`;
            toast.innerHTML = `
                <div class="toast-icon ${type}"><i class="${icons[type] || icons.info}"></i></div>
                <div class="toast-content">
                    <div class="toast-title">${title}</div>
                    <div class="toast-message">${message}</div>
                </div>
            `;
            container.appendChild(toast);
            requestAnimationFrame(() => { toast.classList.add('show'); });
            const timer = setTimeout(() => { closeToast(id); }, duration);
            toast._timer = timer;
            toast.addEventListener('click', () => { closeToast(id); });
            return id;
        }

        function closeToast(id) {
            const toast = document.getElementById(`toast-${id}`);
            if (!toast) return;
            if (toast._timer) clearTimeout(toast._timer);
            toast.classList.add('swipe-up');
            setTimeout(() => { if (toast.parentElement) toast.remove(); }, 400);
        }

        // ============================================================
        // عند تحميل الصفحة
        // ============================================================
        document.addEventListener('DOMContentLoaded', function() {
            const today = new Date().toISOString().split('T')[0];
            const dateInput = document.getElementById('attendanceDate');
            if (dateInput) dateInput.value = today;

            const alreadyLoggedIn = <?= $isLoggedIn ? 'true' : 'false' ?>;
            if (alreadyLoggedIn) {
                document.getElementById('loginPage').style.display = 'none';
                document.getElementById('appContainer').style.display = 'block';
                initData();
            } else {
                document.getElementById('loginPage').style.display = 'flex';
                document.getElementById('appContainer').style.display = 'none';
            }
        });

        const style = document.createElement('style');
        style.textContent = `@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }`;
        document.head.appendChild(style);
    </script>

</body>
</html>