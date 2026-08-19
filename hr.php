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

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
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

    switch ($action) {

        case 'bootstrap': {
            $branches = $pdo->query("
                SELECT b.id, b.name, b.location, b.status, b.notes,
                       e.full_name AS manager, e.national_id AS nationalId, e.birth_date AS birthDate,
                       e.hire_date AS hireDate, e.duty_start_date AS startDate, e.duty_end_date AS endDate,
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

            echo json_encode([
                'ok' => true,
                'branches' => $branches,
                'exchangeRate' => (float) ($rateRow['usd_exchange_rate'] ?? 0),
                'settings' => $settingsRow,
                'topEmployees' => $topEmployees,
                'stats' => [
                    'employees' => (int) $pdo->query("SELECT COUNT(*) FROM employees WHERE status='active'")->fetchColumn(),
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'branches': {
            $rows = $pdo->query("
                SELECT b.id, b.name, b.location, b.status, b.notes,
                       e.full_name AS manager, e.national_id AS nationalId, e.birth_date AS birthDate,
                       e.hire_date AS hireDate, e.duty_start_date AS startDate, e.duty_end_date AS endDate,
                       e.photo, e.documents AS docs
                FROM branches b
                LEFT JOIN employees e ON e.branch_id = b.id AND e.is_branch_manager = 1
                ORDER BY b.created_at DESC
            ")->fetchAll();
            echo json_encode(['ok' => true, 'branches' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'branch_save': {
            $id = (int) ($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $manager = trim($_POST['manager'] ?? '');
            $nationalId = trim($_POST['nationalId'] ?? '');
            $birthDate = $_POST['birthDate'] ?: null;
            $hireDate = $_POST['hireDate'] ?: null;
            $startDate = $_POST['startDate'] ?: null;
            $endDate = $_POST['endDate'] ?: null;
            $notes = trim($_POST['notes'] ?? '');
            $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
            $managerPassword = (string) ($_POST['managerPassword'] ?? '');

            if ($name === '' || $manager === '' || $nationalId === '') {
                echo json_encode(['ok' => false, 'error' => 'اسم الفرع، اسم المسؤول، ورقم الهوية مطلوبة']);
                exit;
            }

            $photoPath = handle_upload('photo', 'photos', ['jpg', 'jpeg', 'png', 'webp']);
            $docsPath = handle_upload('docs', 'documents', ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx']);

            $pdo->beginTransaction();
            try {
                if ($id > 0) {
                    $stmt = $pdo->prepare("UPDATE branches SET name=?, notes=?, status=? WHERE id=?");
                    $stmt->execute([$name, $notes, $status, $id]);
                    $branchId = $id;
                } else {
                    $stmt = $pdo->prepare("INSERT INTO branches (name, notes, status) VALUES (?, ?, ?)");
                    $stmt->execute([$name, $notes, $status]);
                    $branchId = (int) $pdo->lastInsertId();
                }

                $mgrStmt = $pdo->prepare("SELECT id FROM employees WHERE branch_id=? AND is_branch_manager=1 LIMIT 1");
                $mgrStmt->execute([$branchId]);
                $existingManagerId = $mgrStmt->fetchColumn();

                if ($existingManagerId) {
                    $sql = "UPDATE employees SET full_name=?, national_id=?, birth_date=?, hire_date=?, duty_start_date=?, duty_end_date=?";
                    $params = [$manager, $nationalId, $birthDate, $hireDate, $startDate, $endDate];
                    if ($photoPath) { $sql .= ", photo=?"; $params[] = $photoPath; }
                    if ($docsPath) { $sql .= ", documents=?"; $params[] = $docsPath; }
                    $sql .= " WHERE id=?";
                    $params[] = $existingManagerId;
                    $pdo->prepare($sql)->execute($params);
                    $managerEmployeeId = (int) $existingManagerId;
                } else {
                    $numStmt = $pdo->query("SELECT MAX(CAST(employee_number AS UNSIGNED)) FROM employees WHERE employee_number REGEXP '^[0-9]+$'");
                    $empNumber = (string) max(1001, (int) $numStmt->fetchColumn() + 1);
                    $stmt = $pdo->prepare("INSERT INTO employees (branch_id, employee_number, full_name, national_id, birth_date, hire_date, duty_start_date, duty_end_date, job_title, photo, documents, is_branch_manager, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'مدير فرع', ?, ?, 1, 'active')");
                    $stmt->execute([$branchId, $empNumber, $manager, $nationalId, $birthDate, $hireDate, $startDate, $endDate, $photoPath, $docsPath]);
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

        case 'employees_list': {
            $rows = $pdo->query("
                SELECT e.id, e.full_name AS name, b.name AS branch
                FROM employees e JOIN branches b ON b.id = e.branch_id
                WHERE e.status='active' ORDER BY e.full_name
            ")->fetchAll();
            echo json_encode(['ok' => true, 'employees' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'attendance': {
            $date = $_GET['date'] ?? date('Y-m-d');
            $stmt = $pdo->prepare("
                SELECT a.id, e.full_name AS name, b.name AS branch, a.check_in AS checkIn, a.check_out AS checkOut, a.status
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

        case 'salaries': {
            $month = (int) ($_GET['month'] ?? date('n'));
            $year = (int) ($_GET['year'] ?? date('Y'));
            $stmt = $pdo->prepare("
                SELECT p.id, e.full_name AS name, b.name AS branch, p.base_salary AS base, p.bonus, p.deduction,
                       (p.base_salary + p.bonus - p.deduction) AS net, p.status
                FROM payroll p JOIN employees e ON e.id=p.employee_id JOIN branches b ON b.id=p.branch_id
                WHERE p.period_month=? AND p.period_year=? ORDER BY e.full_name
            ");
            $stmt->execute([$month, $year]);
            $rows = array_map(function ($r) {
                $r['base'] = (float) $r['base'];
                $r['bonus'] = (float) $r['bonus'];
                $r['deduction'] = (float) $r['deduction'];
                $r['net'] = (float) $r['net'];
                $r['status'] = payroll_status_ar($r['status']);
                return $r;
            }, $stmt->fetchAll());
            echo json_encode(['ok' => true, 'salaries' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'salary_add': {
            $employeeId = (int) ($_POST['employeeId'] ?? 0);
            $base = (float) ($_POST['base'] ?? 0);
            $bonus = (float) ($_POST['bonus'] ?? 0);
            $deduction = (float) ($_POST['deduction'] ?? 0);
            if ($base <= 0 || $employeeId <= 0) {
                echo json_encode(['ok' => false, 'error' => 'الرجاء إدخال راتب أساسي صحيح']);
                exit;
            }
            $empStmt = $pdo->prepare("SELECT branch_id FROM employees WHERE id=?");
            $empStmt->execute([$employeeId]);
            $branchId = $empStmt->fetchColumn();
            if (!$branchId) {
                echo json_encode(['ok' => false, 'error' => 'الموظف غير موجود']);
                exit;
            }
            $month = (int) date('n');
            $year = (int) date('Y');
            $stmt = $pdo->prepare("INSERT INTO payroll (employee_id, branch_id, period_month, period_year, base_salary, bonus, deduction, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')
                ON DUPLICATE KEY UPDATE base_salary=VALUES(base_salary), bonus=VALUES(bonus), deduction=VALUES(deduction)");
            $stmt->execute([$employeeId, $branchId, $month, $year, $base, $bonus, $deduction]);
            echo json_encode(['ok' => true]);
            exit;
        }

        case 'salary_delete': {
            $id = (int) ($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM payroll WHERE id=?")->execute([$id]);
            echo json_encode(['ok' => true]);
            exit;
        }

        case 'briefs': {
            $stmt = $pdo->query("
                SELECT db.id, e.full_name AS sender, e.job_title AS senderRole, b.name AS branch,
                       DATE_FORMAT(db.brief_date, '%d/%m/%Y') AS date,
                       db.total_income AS revenue, db.total_expense AS expenses, db.note,
                       db.status, db.hr_note AS hrNote
                FROM daily_briefs db
                JOIN branches b ON b.id = db.branch_id
                LEFT JOIN employees e ON e.id = db.submitted_by
                ORDER BY db.brief_date DESC, db.id DESC LIMIT 30
            ");
            $rows = array_map(function ($r) {
                $r['revenue'] = (float) $r['revenue'];
                $r['expenses'] = (float) $r['expenses'];
                $r['netProfit'] = $r['revenue'] - $r['expenses'];
                $r['sender'] = $r['sender'] ?: 'مدير الفرع';
                $r['senderRole'] = $r['senderRole'] ?: 'مدير فرع';
                return $r;
            }, $stmt->fetchAll());
            echo json_encode(['ok' => true, 'briefs' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'brief_review': {
            $id = (int) ($_POST['id'] ?? 0);
            $decision = ($_POST['decision'] ?? '') === 'approved' ? 'approved' : 'rejected';
            $hrNote = trim($_POST['hrNote'] ?? '') ?: ($decision === 'approved' ? 'تم الاعتماد من قبل الموارد البشرية' : 'تم الرفض من قبل الموارد البشرية');
            $pdo->prepare("UPDATE daily_briefs SET status=?, hr_note=?, reviewed_by=?, reviewed_at=NOW() WHERE id=?")
                ->execute([$decision, $hrNote, $hrUser['id'], $id]);
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
            // لا يجوز لـ HR البت إلا في طلب وافق عليه مدير الفرع أولاً
            $stmt = $pdo->prepare("UPDATE requests SET status=?, hr_reviewed_by=?, hr_review_note=?, hr_reviewed_at=NOW() WHERE id=? AND status='branch_approved'");
            $stmt->execute([$decision, $hrUser['id'], $hrNote, $id]);
            if ($stmt->rowCount() === 0) {
                echo json_encode(['ok' => false, 'error' => 'هذا الطلب ليس بانتظار مراجعة الموارد البشرية']);
                exit;
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
            $pdo->prepare("UPDATE settings SET company_name=?, company_email=?, work_start_time=?, work_end_time=? ORDER BY id DESC LIMIT 1")
                ->execute([$companyName, $companyEmail, $workStart, $workEnd]);
            echo json_encode(['ok' => true]);
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
                       (db.total_income - db.total_expense) AS profit
                FROM daily_briefs db JOIN branches b ON b.id = db.branch_id
                WHERE db.brief_date BETWEEN ? AND ?";
        $params = [$from, $to];
        if ($branch > 0) { $sql .= " AND db.branch_id = ?"; $params[] = $branch; }
        $sql .= " ORDER BY db.brief_date DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = array_map(function ($r) {
            foreach (['revenue', 'expense', 'profit'] as $k) {
                $r[$k] = number_format((float) $r[$k]);
            }
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
            background: var(--bg-card);
            border-left: 1px solid rgba(0,107,115,0.04);
            box-shadow: 2px 0 20px rgba(0,0,0,0.02);
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
            border-bottom: 1px solid rgba(0,107,115,0.04);
            margin-bottom: 16px;
        }
        .sidebar .brand .logo {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-md);
            background: var(--primary-gradient);
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
            color: var(--text-primary);
        }
        .sidebar .brand .name span {
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .sidebar .brand .version {
            font-size: 9px;
            color: var(--text-muted);
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
            color: var(--text-secondary);
            font-weight: 600;
            font-size: 13px;
            border: none;
            background: transparent;
            width: 100%;
            font-family: var(--font-family);
            text-align: right;
        }
        .sidebar .nav-item:hover {
            background: rgba(0,107,115,0.04);
            color: var(--text-primary);
        }
        .sidebar .nav-item.active {
            background: rgba(0,107,115,0.06);
            color: var(--primary);
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
            background: rgba(0,107,115,0.04);
            margin: 8px 0;
        }

        .sidebar .user-info {
            padding-top: 16px;
            border-top: 1px solid rgba(0,107,115,0.04);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .sidebar .user-info .avatar {
            width: 44px;
            height: 44px;
            border-radius: var(--radius-full);
            background: var(--primary-gradient);
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
            color: var(--text-primary);
        }
        .sidebar .user-info .info .role {
            font-size: 11px;
            color: var(--text-muted);
            font-weight: 400;
        }
        .sidebar .user-info .logout-btn {
            margin-right: auto;
            background: none;
            border: none;
            color: var(--text-muted);
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
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 20px 22px;
            border: 1px solid rgba(0,107,115,0.04);
            box-shadow: var(--shadow-sm);
            transition: var(--transition-base);
        }
        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
        }
        .stat-card .stat-label {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .stat-card .stat-label i { color: var(--primary); }
        .stat-card .stat-value {
            font-size: 28px;
            font-weight: 900;
            color: var(--text-primary);
            margin-top: 6px;
        }
        .stat-card .stat-value.green { color: #059669; }
        .stat-card .stat-value.red { color: #DC2626; }
        .stat-card .stat-value.primary { color: var(--primary); }
        .stat-card .stat-value.gold { color: var(--accent); }
        .stat-card .stat-change {
            font-size: 12px;
            font-weight: 600;
            margin-top: 4px;
        }
        .stat-card .stat-change.up { color: #059669; }
        .stat-card .stat-change.down { color: #DC2626; }
        .stat-card .stat-change i { font-size: 10px; }

        /* ============================================================
           أفضل 3 موظفين حضور
           ============================================================ */
        .top-employees {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 16px;
            margin-bottom: 24px;
        }
        .top-employee-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 20px;
            border: 1px solid rgba(0,107,115,0.04);
            box-shadow: var(--shadow-sm);
            text-align: center;
            transition: var(--transition-base);
            position: relative;
            overflow: hidden;
        }
        .top-employee-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-md);
        }
        .top-employee-card .rank {
            font-size: 32px;
            margin-bottom: 8px;
        }
        .top-employee-card .avatar {
            width: 64px;
            height: 64px;
            border-radius: var(--radius-full);
            background: var(--primary-gradient);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 24px;
            font-weight: 900;
            margin: 0 auto 10px;
        }
        .top-employee-card .name {
            font-size: 16px;
            font-weight: 800;
            color: var(--text-primary);
        }
        .top-employee-card .branch {
            font-size: 12px;
            color: var(--text-muted);
        }
        .top-employee-card .attendance-rate {
            font-size: 22px;
            font-weight: 900;
            color: #059669;
            margin-top: 8px;
        }
        .top-employee-card .attendance-label {
            font-size: 11px;
            color: var(--text-muted);
            font-weight: 400;
        }
        .top-employee-card .badge-gold {
            position: absolute;
            top: 12px;
            left: 12px;
            font-size: 10px;
            font-weight: 700;
            padding: 3px 12px;
            border-radius: var(--radius-full);
            background: var(--accent);
            color: #fff;
        }
        .top-employee-card .medal {
            font-size: 28px;
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
            gap: 8px;
            height: 120px;
            padding: 8px 0;
        }
        .stocks-chart .bar {
            flex: 1;
            border-radius: 4px 4px 0 0;
            background: var(--primary-gradient);
            min-height: 8px;
            transition: height 0.6s ease;
            position: relative;
            opacity: 0.7;
            cursor: pointer;
        }
        .stocks-chart .bar:hover {
            opacity: 1;
            transform: scaleY(1.02);
            transform-origin: bottom;
        }
        .stocks-chart .bar .bar-label {
            position: absolute;
            bottom: -22px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 9px;
            color: var(--text-muted);
            font-weight: 500;
            white-space: nowrap;
        }
        .stocks-chart .bar .bar-value {
            position: absolute;
            top: -18px;
            left: 50%;
            transform: translateX(-50%);
            font-size: 9px;
            font-weight: 700;
            color: var(--text-secondary);
        }
        .stocks-chart .bar.up { background: linear-gradient(180deg, #059669, #10B981); }
        .stocks-chart .bar.down { background: linear-gradient(180deg, #DC2626, #EF4444); }

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

        .briefing-card {
            background: var(--bg);
            border-radius: var(--radius-md);
            padding: 16px 20px;
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
        .briefing-card .briefing-actions .hr-note-input {
            flex: 1;
            min-width: 200px;
            padding: 8px 12px;
            border: 2px solid rgba(0,107,115,0.06);
            border-radius: var(--radius-sm);
            font-size: 12px;
            font-family: var(--font-family);
            background: var(--bg-card);
            color: var(--text-primary);
            outline: none;
            transition: var(--transition-base);
        }
        .briefing-card .briefing-actions .hr-note-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(0,107,115,0.06);
        }
        .briefing-card .briefing-actions .hr-note-input::placeholder {
            color: var(--text-muted);
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

        .branch-card {
            background: var(--bg);
            border-radius: var(--radius-md);
            padding: 16px 20px;
            margin-bottom: 14px;
            border: 1px solid rgba(0,107,115,0.04);
            border-right: 4px solid var(--primary);
            transition: var(--transition-base);
        }
        .branch-card:hover {
            box-shadow: var(--shadow-sm);
        }
        .branch-card .branch-top {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 8px;
        }
        .branch-card .branch-top .branch-name {
            font-size: 16px;
            font-weight: 800;
            color: var(--text-primary);
        }
        .branch-card .branch-top .branch-name i { color: var(--primary); }
        .branch-card .branch-top .branch-status {
            font-size: 11px;
            font-weight: 700;
            padding: 3px 14px;
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
            grid-template-columns: 1fr 1fr 1fr 1fr;
            gap: 8px;
            margin: 8px 0;
            font-size: 13px;
        }
        .branch-card .branch-details .detail-item {
            background: var(--bg-card);
            padding: 6px 12px;
            border-radius: var(--radius-sm);
            border: 1px solid rgba(0,107,115,0.04);
        }
        .branch-card .branch-details .detail-item .label {
            font-size: 10px;
            color: var(--text-muted);
            font-weight: 500;
            display: block;
        }
        .branch-card .branch-details .detail-item .value {
            font-size: 13px;
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
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
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
            .stats-grid { grid-template-columns: 1fr 1fr; }
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
            .stocks-chart .bar .bar-label { font-size: 7px; bottom: -18px; }
            .stocks-chart .bar .bar-value { font-size: 7px; top: -14px; }
            .mobile-menu-toggle {
                display: flex !important;
            }
        }

        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: 1fr; }
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
                <div class="logo">✥</div>
                <div>
                    <div class="name">نظام <span>الموارد</span></div>
                    <span class="version">v3.0 · HR</span>
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
                <div class="header-actions">
                    <span class="date-display">
                        <i class="fas fa-calendar"></i>
                        <span id="currentDateDisplay">الأربعاء 19 أغسطس 2026</span>
                    </span>
                </div>
            </header>

            <!-- ===== المحتوى ===== -->
            <div id="pageContent">

                <!-- ==========================================================
                صفحة لوحة التحكم
                ========================================================== -->
                <div id="page-dashboard" class="page-section">

                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-label"><i class="fas fa-users"></i> إجمالي الموظفين</div>
                            <div class="stat-value primary">247</div>
                            <div class="stat-change up"><i class="fas fa-arrow-up"></i> +12 هذا الشهر</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label"><i class="fas fa-percent"></i> نسبة الإيداع</div>
                            <div class="stat-value green">78%</div>
                            <div class="stat-change up"><i class="fas fa-arrow-up"></i> +5% عن الشهر الماضي</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label"><i class="fas fa-money-bill-wave"></i> صافي الربح الشهري</div>
                            <div class="stat-value gold">4,250,000</div>
                            <div class="stat-change up"><i class="fas fa-arrow-up"></i> +8.2% عن الشهر الماضي</div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label"><i class="fas fa-hand-holding-usd"></i> إجمالي الديون</div>
                            <div class="stat-value red">1,820,000</div>
                            <div class="stat-change down"><i class="fas fa-arrow-down"></i> +3.5% عن الشهر الماضي</div>
                        </div>
                    </div>

                    <!-- أفضل 3 موظفين حضور -->
                    <div class="top-employees" id="topEmployees">
                        <!-- يتم التعبئة بواسطة JavaScript -->
                    </div>

                    <!-- الأسهم اليومية -->
                    <div class="stocks-section">
                        <div class="stocks-header">
                            <h4><i class="fas fa-chart-line"></i> الأسهم اليومية</h4>
                            <span class="update-time"><i class="fas fa-clock"></i> تحديث: <span id="stocksTime">15:30</span></span>
                        </div>
                        <div class="stocks-chart" id="stocksChart"></div>
                        <div class="stocks-summary">
                            <span class="summary-item"><span class="dot up"></span> صاعد: <span class="value" id="stocksUp">3</span></span>
                            <span class="summary-item"><span class="dot down"></span> هابط: <span class="value" id="stocksDown">2</span></span>
                            <span class="summary-item"><i class="fas fa-arrow-up" style="color:#059669;"></i> أعلى: <span class="value" id="stocksHigh">92.5</span></span>
                            <span class="summary-item"><i class="fas fa-arrow-down" style="color:#DC2626;"></i> أدنى: <span class="value" id="stocksLow">84.2</span></span>
                        </div>
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
                                <label>تاريخ الميلاد</label>
                                <input type="date" id="branchBirthDate">
                            </div>
                            <div class="form-group">
                                <label>تاريخ التعيين</label>
                                <input type="date" id="branchHireDate">
                            </div>
                            <div class="form-group">
                                <label>تاريخ بداية الدوام</label>
                                <input type="date" id="branchStartDate">
                            </div>
                            <div class="form-group">
                                <label>تاريخ نهاية الدوام</label>
                                <input type="date" id="branchEndDate">
                            </div>
                            <div class="form-group">
                                <label>الصورة الشخصية</label>
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
                صفحة الحضور
                ========================================================== -->
                <div id="page-attendance" class="page-section" style="display:none;">
                    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:16px;">
                        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                            <input type="date" id="attendanceDate" value="2026-08-19" style="padding:8px 12px;border:2px solid rgba(0,107,115,0.06);border-radius:var(--radius-sm);font-family:var(--font-family);background:var(--bg);">
                            <button class="btn-primary" onclick="loadAttendance()">عرض</button>
                        </div>
                        <button class="btn-primary" onclick="showToast('✅ تم التحديث','تم تحديث سجل الحضور','success')">
                            <i class="fas fa-sync"></i> تحديث
                        </button>
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
                        <h4 style="font-size:16px;font-weight:800;"><i class="fas fa-wallet" style="color:var(--primary);"></i> إدارة الرواتب</h4>
                        <button class="btn-primary" onclick="showAddSalaryForm()">
                            <i class="fas fa-plus"></i> إضافة راتب
                        </button>
                    </div>

                    <div id="addSalaryForm" style="display:none;background:var(--bg-card);border-radius:var(--radius-lg);padding:20px;margin-bottom:16px;border:1px solid rgba(0,107,115,0.04);box-shadow:var(--shadow-sm);">
                        <h5 style="font-size:15px;font-weight:800;margin-bottom:12px;"><i class="fas fa-plus-circle" style="color:var(--primary);"></i> إضافة راتب جديد</h5>
                        <div class="salary-form" style="display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:12px;">
                            <div class="form-group">
                                <label style="font-size:12px;font-weight:600;color:var(--text-muted);">الموظف</label>
                                <select id="salaryEmployee" style="padding:8px 12px;border:2px solid rgba(0,107,115,0.06);border-radius:var(--radius-sm);font-size:13px;font-family:var(--font-family);background:var(--bg);color:var(--text-primary);outline:none;">
                                    <option value="1">أحمد محمد علي - صراف</option>
                                    <option value="2">سارة حسين - صرافة</option>
                                    <option value="3">محمد باسم - مدير فرع</option>
                                    <option value="4">علي حسن - مسؤول فرع</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label style="font-size:12px;font-weight:600;color:var(--text-muted);">الراتب الأساسي</label>
                                <input type="number" id="salaryBase" value="1200000" placeholder="الراتب الأساسي" style="padding:8px 12px;border:2px solid rgba(0,107,115,0.06);border-radius:var(--radius-sm);font-size:13px;font-family:var(--font-family);background:var(--bg);color:var(--text-primary);outline:none;">
                            </div>
                            <div class="form-group">
                                <label style="font-size:12px;font-weight:600;color:var(--text-muted);">المكافأة</label>
                                <input type="number" id="salaryBonus" value="100000" placeholder="المكافأة" style="padding:8px 12px;border:2px solid rgba(0,107,115,0.06);border-radius:var(--radius-sm);font-size:13px;font-family:var(--font-family);background:var(--bg);color:var(--text-primary);outline:none;">
                            </div>
                            <div class="form-group">
                                <label style="font-size:12px;font-weight:600;color:var(--text-muted);">الخصم</label>
                                <input type="number" id="salaryDeduction" value="25000" placeholder="الخصم" style="padding:8px 12px;border:2px solid rgba(0,107,115,0.06);border-radius:var(--radius-sm);font-size:13px;font-family:var(--font-family);background:var(--bg);color:var(--text-primary);outline:none;">
                            </div>
                            <div class="form-group" style="display:flex;align-items:flex-end;">
                                <button class="btn-primary" onclick="addSalary()" style="width:100%;">
                                    <i class="fas fa-save"></i> حفظ الراتب
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="content-card">
                        <div class="card-header">
                            <h4><i class="fas fa-list"></i> قائمة الرواتب</h4>
                            <span style="font-size:12px;color:var(--text-muted);">أغسطس 2026</span>
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
                            <h4><i class="fas fa-inbox"></i> الإيجازات الواردة</h4>
                            <span style="font-size:12px;color:var(--text-muted);" id="briefingCount">3 إيجازات</span>
                        </div>
                        <div id="briefingList">
                            <!-- يتم التعبئة بواسطة JavaScript -->
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
                            <button class="btn-primary" onclick="downloadReport()" style="font-size:12px;padding:6px 14px;">
                                <i class="fas fa-download"></i> تحميل
                            </button>
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
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
                                <div class="form-group"><label style="font-size:12px;font-weight:600;color:var(--text-muted);">اسم الشركة</label><input type="text" id="settingsCompanyName" value="شركة الصوى للصرافة" style="width:100%;padding:10px 12px;border:2px solid rgba(0,107,115,0.06);border-radius:var(--radius-sm);font-family:var(--font-family);"></div>
                                <div class="form-group"><label style="font-size:12px;font-weight:600;color:var(--text-muted);">البريد الإلكتروني</label><input type="email" id="settingsCompanyEmail" value="hr@alsawwa.com" style="width:100%;padding:10px 12px;border:2px solid rgba(0,107,115,0.06);border-radius:var(--radius-sm);font-family:var(--font-family);"></div>
                                <div class="form-group"><label style="font-size:12px;font-weight:600;color:var(--text-muted);">وقت بداية الدوام</label><input type="time" id="settingsWorkStart" value="09:00" style="width:100%;padding:10px 12px;border:2px solid rgba(0,107,115,0.06);border-radius:var(--radius-sm);font-family:var(--font-family);"></div>
                                <div class="form-group"><label style="font-size:12px;font-weight:600;color:var(--text-muted);">وقت نهاية الدوام</label><input type="time" id="settingsWorkEnd" value="17:00" style="width:100%;padding:10px 12px;border:2px solid rgba(0,107,115,0.06);border-radius:var(--radius-sm);font-family:var(--font-family);"></div>
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
    TOAST CONTAINER
    ============================================================ -->
    <div class="toast-container" id="toastContainer"></div>

    <!-- ============================================================
    سكربتات JavaScript
    ============================================================ -->
    <script>
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
            generateStocks();

            fetch('?ajax=bootstrap').then(r => r.json()).then(data => {
                if (!data.ok) return;
                exchangeRate = data.exchangeRate || 0;
                updateExchangeRateDisplay();
                loadRateHistory();
                renderTopEmployees(data.topEmployees || []);
                if (data.settings) {
                    const s = data.settings;
                    const byId = id => document.getElementById(id);
                    if (byId('settingsCompanyName')) byId('settingsCompanyName').value = s.company_name || '';
                    if (byId('settingsCompanyEmail')) byId('settingsCompanyEmail').value = s.company_email || '';
                    if (byId('settingsWorkStart')) byId('settingsWorkStart').value = (s.work_start_time || '').slice(0, 5);
                    if (byId('settingsWorkEnd')) byId('settingsWorkEnd').value = (s.work_end_time || '').slice(0, 5);
                }
                loadAttendance();
                loadSalaries();
                loadRequests();
                loadBriefingRequests();
                loadBranches();
            });

            fetch('?ajax=employees_list').then(r => r.json()).then(data => {
                if (!data.ok) return;
                const select = document.getElementById('salaryEmployee');
                if (select) {
                    select.innerHTML = data.employees.map(e => `<option value="${e.id}">${e.name} — ${e.branch}</option>`).join('') || '<option value="">لا يوجد موظفون</option>';
                }
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
                'attendance': { title: 'الحضور', sub: 'سجل حضور الموظفين اليومي' },
                'salaries': { title: 'الرواتب', sub: 'إدارة رواتب الموظفين والمكافآت' },
                'briefing': { title: 'الإيجاز', sub: 'معاينة واعتماد الإيجازات' },
                'requests': { title: 'الطلبات', sub: 'إدارة طلبات الموظفين' },
                'reports': { title: 'التقارير', sub: 'إنشاء وعرض التقارير' },
                'exchange': { title: 'سعر الصرف', sub: 'تحديد سعر صرف الدولار' },
                'settings': { title: 'الإعدادات', sub: 'إعدادات النظام والشركة' }
            };
            document.getElementById('pageTitle').innerHTML = `<i class="fas ${getPageIcon(page)}"></i> ${titles[page]?.title || page}`;
            document.getElementById('pageSub').textContent = titles[page]?.sub || '';

            if (window.innerWidth <= 768) {
                document.getElementById('sidebar').classList.remove('open');
                document.getElementById('sidebarOverlay').classList.remove('show');
            }
        }

        function getPageTitle(page) {
            const titles = { 'dashboard': 'لوحة التحكم', 'branches': 'الفروع', 'attendance': 'الحضور', 'salaries': 'الرواتب', 'briefing': 'الإيجاز', 'requests': 'الطلبات', 'reports': 'التقارير', 'exchange': 'سعر الصرف', 'settings': 'الإعدادات' };
            return titles[page] || page;
        }

        function getPageIcon(page) {
            const icons = { 'dashboard': 'fa-chart-pie', 'branches': 'fa-building', 'attendance': 'fa-clock', 'salaries': 'fa-wallet', 'briefing': 'fa-file-signature', 'requests': 'fa-file-pen', 'reports': 'fa-chart-bar', 'exchange': 'fa-dollar-sign', 'settings': 'fa-cog' };
            return icons[page] || 'fa-circle';
        }

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('open');
            document.getElementById('sidebarOverlay').classList.toggle('show');
        }

        function handleLogout() {
            if (confirm('هل أنت متأكد من رغبتك في تسجيل الخروج؟')) {
                fetch('?ajax=logout', { method: 'POST' }).then(() => {
                    document.getElementById('appContainer').style.display = 'none';
                    document.getElementById('loginPage').style.display = 'flex';
                    showToast('👋 تم تسجيل الخروج', 'نتمنى رؤيتك قريباً', 'info');
                });
            }
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
        // توليد الأسهم اليومية
        // ============================================================
        function generateStocks() {
            const chart = document.getElementById('stocksChart');
            const days = ['أحد', 'إثن', 'ثلاث', 'أربع', 'خميس', 'جمعة', 'سبت'];
            const values = [85, 88, 84, 90, 92, 87, 89];
            let html = '';
            let up = 0,
                down = 0,
                high = 0,
                low = 100;

            values.forEach((val, i) => {
                const isUp = i > 0 && val > values[i - 1];
                if (i > 0) { if (isUp) up++;
                    else down++; }
                if (val > high) high = val;
                if (val < low) low = val;
                const height = Math.max((val / 100) * 100, 15);
                html += `
                    <div class="bar ${isUp ? 'up' : 'down'}" style="height:${height}%;">
                        <span class="bar-value">${val}</span>
                        <span class="bar-label">${days[i]}</span>
                    </div>
                `;
            });

            chart.innerHTML = html;
            document.getElementById('stocksUp').textContent = up;
            document.getElementById('stocksDown').textContent = down;
            document.getElementById('stocksHigh').textContent = high.toFixed(1);
            document.getElementById('stocksLow').textContent = low.toFixed(1);
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
            const body = new URLSearchParams({
                companyName: document.getElementById('settingsCompanyName').value,
                companyEmail: document.getElementById('settingsCompanyEmail').value,
                workStart: document.getElementById('settingsWorkStart').value,
                workEnd: document.getElementById('settingsWorkEnd').value,
            });
            fetch('?ajax=settings_save', { method: 'POST', body }).then(r => r.json()).then(data => {
                if (data.ok) showToast('✅ تم الحفظ', 'تم حفظ إعدادات الشركة بنجاح', 'success');
                else showToast('⚠️ خطأ', data.error || 'تعذر الحفظ', 'error');
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

                html += `
                    <div class="branch-card">
                        <div class="branch-top">
                            <div>
                                <div class="branch-name"><i class="fas fa-building"></i> ${branch.name}</div>
                                <div style="font-size:13px;color:var(--text-secondary);">
                                    <i class="fas fa-user-tie"></i> مسؤول: ${branch.manager}
                                </div>
                            </div>
                            <div>
                                <span class="branch-status ${statusClass}">${statusLabel}</span>
                                <span style="font-size:11px;color:var(--text-muted);margin-right:10px;">
                                    <i class="fas fa-id-card"></i> ${branch.nationalId}
                                </span>
                            </div>
                        </div>

                        <div class="branch-details">
                            <div class="detail-item">
                                <span class="label">📅 تاريخ الميلاد</span>
                                <span class="value">${branch.birthDate || 'غير محدد'}</span>
                            </div>
                            <div class="detail-item">
                                <span class="label">📅 تاريخ التعيين</span>
                                <span class="value">${branch.hireDate || 'غير محدد'}</span>
                            </div>
                            <div class="detail-item">
                                <span class="label">📅 بداية الدوام</span>
                                <span class="value">${branch.startDate || 'غير محدد'}</span>
                            </div>
                            <div class="detail-item">
                                <span class="label">📅 نهاية الدوام</span>
                                <span class="value">${branch.endDate || 'غير محدد'}</span>
                            </div>
                        </div>

                        ${branch.notes ? `
                            <div style="font-size:12px;color:var(--text-muted);padding:6px 12px;background:var(--bg-card);border-radius:var(--radius-sm);margin:6px 0;border:1px solid rgba(0,107,115,0.04);">
                                <strong><i class="fas fa-comment"></i> ملاحظات:</strong> ${branch.notes}
                            </div>
                        ` : ''}

                        <div class="branch-docs">
                            ${branch.photo ? `<span class="doc-tag"><i class="fas fa-image"></i> صورة شخصية</span>` : ''}
                            ${branch.docs ? `<span class="doc-tag"><i class="fas fa-file-pdf"></i> مستمسكات</span>` : ''}
                            <span class="doc-tag"><i class="fas fa-id-card"></i> هوية وطنية: ${branch.nationalId}</span>
                        </div>

                        <div class="branch-actions">
                            <button class="btn-sm edit" onclick="editBranch(${branch.id})">
                                <i class="fas fa-edit"></i> تعديل
                            </button>
                            <button class="btn-sm view" onclick="viewBranch(${branch.id})">
                                <i class="fas fa-eye"></i> عرض
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
            document.getElementById('branchBirthDate').value = '';
            document.getElementById('branchHireDate').value = '';
            document.getElementById('branchStartDate').value = '';
            document.getElementById('branchEndDate').value = '';
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

            if (!name || !manager || !nationalId) {
                showToast('⚠️ تنبيه', 'اسم الفرع، اسم المسؤول، ورقم الهوية مطلوبة', 'warning');
                return;
            }

            const fd = new FormData();
            if (editingBranchId) fd.append('id', editingBranchId);
            fd.append('name', name);
            fd.append('manager', manager);
            fd.append('nationalId', nationalId);
            fd.append('birthDate', document.getElementById('branchBirthDate').value);
            fd.append('hireDate', document.getElementById('branchHireDate').value);
            fd.append('startDate', document.getElementById('branchStartDate').value);
            fd.append('endDate', document.getElementById('branchEndDate').value);
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
            document.getElementById('branchBirthDate').value = branch.birthDate || '';
            document.getElementById('branchHireDate').value = branch.hireDate || '';
            document.getElementById('branchStartDate').value = branch.startDate || '';
            document.getElementById('branchEndDate').value = branch.endDate || '';
            document.getElementById('branchNotes').value = branch.notes || '';
            document.getElementById('branchStatus').value = branch.status;

            if (branch.photo) document.getElementById('branchPhotoLabel').textContent = branch.photo;
            if (branch.docs) document.getElementById('branchDocsLabel').textContent = branch.docs;

            document.getElementById('branchFormTitle').textContent = 'تعديل الفرع';
            document.getElementById('branchForm').style.display = 'block';
            window.scrollTo({ top: document.getElementById('branchForm').offsetTop - 100, behavior: 'smooth' });
        }

        function viewBranch(id) {
            const branch = branches.find(b => b.id === id);
            if (!branch) return;
            const statusLabel = branch.status === 'active' ? 'نشط' : 'غير نشط';
            const statusEmoji = branch.status === 'active' ? '✅' : '❌';
            let details = `
                🏢 ${branch.name}
                👤 مسؤول: ${branch.manager}
                🪪 الهوية: ${branch.nationalId}
                📅 تاريخ الميلاد: ${branch.birthDate || 'غير محدد'}
                📅 تاريخ التعيين: ${branch.hireDate || 'غير محدد'}
                📅 بداية الدوام: ${branch.startDate || 'غير محدد'}
                📅 نهاية الدوام: ${branch.endDate || 'غير محدد'}
                📝 ملاحظات: ${branch.notes || 'لا توجد'}
                📌 الحالة: ${statusEmoji} ${statusLabel}
            `;
            if (branch.photo) details += `\n🖼️ الصورة: ${branch.photo}`;
            if (branch.docs) details += `\n📄 المستمسكات: ${branch.docs}`;
            showToast('📋 تفاصيل الفرع', details, 'info', 6000);
        }

        function deleteBranch(id) {
            if (!confirm('هل أنت متأكد من حذف هذا الفرع؟')) return;
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
                            <button class="action-btn approve" onclick="showToast('✅ تعديل','تم تعديل حالة الحضور','success')">
                                <i class="fas fa-edit"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        }

        // ============================================================
        // تحميل بيانات الرواتب
        // ============================================================
        function loadSalaries() {
            fetch('?ajax=salaries').then(r => r.json()).then(data => {
                if (!data.ok) return;
                salaryData = data.salaries;
                renderSalaries();
            });
        }

        function deleteSalary(id) {
            if (!confirm('هل أنت متأكد من حذف هذا الراتب؟')) return;
            const body = new URLSearchParams({ id });
            fetch('?ajax=salary_delete', { method: 'POST', body }).then(r => r.json()).then(data => {
                if (data.ok) { loadSalaries(); showToast('🗑️ حذف', 'تم حذف الراتب', 'warning'); }
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
                        <td>${item.name}</td>
                        <td>${item.branch}</td>
                        <td>${item.base.toLocaleString()}</td>
                        <td style="color:#059669;">+${item.bonus.toLocaleString()}</td>
                        <td style="color:#DC2626;">-${item.deduction.toLocaleString()}</td>
                        <td><strong>${item.net.toLocaleString()}</strong></td>
                        <td><span class="status-badge ${statusClass}">${item.status}</span></td>
                        <td>
                            <button class="action-btn add" onclick="showToast('✏️ تعديل','تم فتح نموذج تعديل الراتب','info')">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="action-btn reject" onclick="deleteSalary(${item.id})">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                `;
            });
            tbody.innerHTML = html;
        }

        function showAddSalaryForm() {
            const form = document.getElementById('addSalaryForm');
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
        }

        function addSalary() {
            const select = document.getElementById('salaryEmployee');
            const name = select.options[select.selectedIndex] ? select.options[select.selectedIndex].text : '';
            const employeeId = select.value;
            const base = parseFloat(document.getElementById('salaryBase').value) || 0;
            const bonus = parseFloat(document.getElementById('salaryBonus').value) || 0;
            const deduction = parseFloat(document.getElementById('salaryDeduction').value) || 0;

            if (base <= 0 || !employeeId) {
                showToast('⚠️ تنبيه', 'الرجاء إدخال راتب أساسي صحيح واختيار الموظف', 'warning');
                return;
            }

            const body = new URLSearchParams({ employeeId, base, bonus, deduction });
            fetch('?ajax=salary_add', { method: 'POST', body }).then(r => r.json()).then(data => {
                if (!data.ok) { showToast('⚠️ خطأ', data.error || 'تعذر الحفظ', 'error'); return; }
                loadSalaries();
                document.getElementById('addSalaryForm').style.display = 'none';
                showToast('✅ تم الإضافة', `تم إضافة راتب ${name} بنجاح`, 'success');
            });
        }

        // ============================================================
        // نظام الإيجاز - تم تصحيحه
        // ============================================================
        function loadBriefingRequests() {
            fetch('?ajax=briefs').then(r => r.json()).then(data => {
                if (!data.ok) return;
                briefingRequests = data.briefs;
                renderBriefingRequests();
                updateBriefingBadge();
            });
        }

        function renderBriefingRequests() {
            const list = document.getElementById('briefingList');
            if (!list) return;

            if (briefingRequests.length === 0) {
                list.innerHTML = `
                    <div style="text-align:center;padding:30px 20px;color:var(--text-muted);">
                        <i class="fas fa-inbox" style="font-size:48px;opacity:0.3;display:block;margin-bottom:12px;"></i>
                        <p>لا توجد إيجازات في انتظار الاعتماد</p>
                    </div>
                `;
                document.getElementById('briefingCount').textContent = '0 إيجازات';
                return;
            }

            let html = '';
            briefingRequests.forEach((item) => {
                const statusClass = item.status === 'approved' ? 'approved' : (item.status === 'rejected' ? 'rejected' : 'pending');
                const statusLabel = item.status === 'approved' ? '✅ معتمد' : (item.status === 'rejected' ? '❌ مرفوض' : '⏳ قيد المراجعة');

                html += `
                    <div class="briefing-card" style="${item.status === 'pending' ? 'border-right-color: #D97706;' : (item.status === 'approved' ? 'border-right-color: #10B981;' : 'border-right-color: #DC2626;')}">
                        <div class="briefing-top">
                            <div>
                                <div class="sender">
                                    <i class="fas fa-user-circle"></i> ${item.sender}
                                    <span style="font-size:11px;color:var(--text-muted);font-weight:400;"> - ${item.senderRole}</span>
                                </div>
                                <div style="font-size:12px;color:var(--text-muted);">${item.branch}</div>
                            </div>
                            <div>
                                <span class="briefing-status ${statusClass}">${statusLabel}</span>
                                <span class="date" style="margin-right:10px;">${item.date}</span>
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
                            <div class="detail-item" style="grid-column: span 2;">
                                <span class="label">💰 صافي الربح</span>
                                <span class="value" style="color:#059669;font-size:16px;">${item.netProfit.toLocaleString()}</span>
                            </div>
                        </div>

                        ${item.note ? `
                            <div class="briefing-note">
                                <strong><i class="fas fa-comment"></i> ملاحظة المرسل:</strong>
                                ${item.note}
                            </div>
                        ` : ''}

                        ${item.hrNote ? `
                            <div class="briefing-note" style="border-color:var(--primary);background:rgba(0,107,115,0.03);">
                                <strong><i class="fas fa-check-circle" style="color:var(--primary);"></i> ملاحظة HR:</strong>
                                ${item.hrNote}
                            </div>
                        ` : ''}

                        ${item.status === 'pending' ? `
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
                                <i class="fas fa-${item.status === 'approved' ? 'check-circle' : 'times-circle'}" style="color:${item.status === 'approved' ? '#059669' : '#DC2626'};"></i>
                                تم ${item.status === 'approved' ? 'اعتماد' : 'رفض'} هذا الإيجاز
                                ${item.hrNote ? ` - ملاحظة: ${item.hrNote}` : ''}
                            </div>
                        `}
                    </div>
                `;
            });

            list.innerHTML = html;
            document.getElementById('briefingCount').textContent = briefingRequests.length + ' إيجازات';
            updateBriefingBadge();
        }

        function reviewBriefing(id, decision) {
            const item = briefingRequests.find(b => b.id === id);
            if (!item) return;
            const noteInput = document.getElementById(`hrNote_${id}`);
            const hrNote = noteInput ? noteInput.value.trim() : '';
            const body = new URLSearchParams({ id, decision, hrNote });
            fetch('?ajax=brief_review', { method: 'POST', body }).then(r => r.json()).then(data => {
                if (!data.ok) { showToast('⚠️ خطأ', data.error || 'تعذر الحفظ', 'error'); return; }
                loadBriefingRequests();
                if (decision === 'approved') showToast('✅ تم الاعتماد', `تم اعتماد إيجاز ${item.sender} - ${item.branch}`, 'success');
                else showToast('❌ تم الرفض', `تم رفض إيجاز ${item.sender} - ${item.branch}`, 'error');
            });
        }

        function approveBriefing(id) { reviewBriefing(id, 'approved'); }
        function rejectBriefing(id) { reviewBriefing(id, 'rejected'); }

        function updateBriefingBadge() {
            const pending = briefingRequests.filter(b => b.status === 'pending').length;
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
                                <button class="action-btn approve" onclick="approveRequest(${item.id})">
                                    <i class="fas fa-check"></i> موافقة
                                </button>
                                <button class="action-btn reject" onclick="rejectRequest(${item.id})">
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

        function reviewRequest(id, decision) {
            const req = requestsData.find(r => r.id === id);
            if (!req) return;
            const body = new URLSearchParams({ id, decision });
            fetch('?ajax=request_review', { method: 'POST', body }).then(r => r.json()).then(data => {
                if (!data.ok) { showToast('⚠️ خطأ', data.error || 'تعذر الحفظ', 'error'); return; }
                loadRequests();
                if (decision === 'approved') showToast('✅ تمت الموافقة', `تم قبول طلب ${req.type}`, 'success');
                else showToast('❌ تم الرفض', `تم رفض طلب ${req.type}`, 'error');
            });
        }

        function approveRequest(id) { reviewRequest(id, 'approved'); }
        function rejectRequest(id) { reviewRequest(id, 'rejected'); }

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
            let html = '<div class="table-wrap"><table class="table"><thead><tr><th>#</th><th>الفرع</th><th>التاريخ</th><th>الإيرادات</th><th>المصاريف</th><th>صافي الربح</th></tr></thead><tbody>';
            rows.forEach((item, i) => {
                html += `<tr><td>${i+1}</td><td>${item.branch}</td><td>${item.date}</td><td>${item.revenue}</td><td>${item.expense}</td><td style="color:#059669;">${item.profit}</td></tr>`;
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