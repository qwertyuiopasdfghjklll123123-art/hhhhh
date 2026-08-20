<?php
/* ======================================================================
   لوحة مدير الفرع — نفس الملف الأصلي (HTML/CSS/JS) تماماً
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

function is_late(string $now, ?array $settingsRow): bool
{
    if (!$settingsRow || !$settingsRow['work_start_time']) {
        return false;
    }
    $grace = (int) ($settingsRow['late_grace_minutes'] ?? 0);
    $deadline = date('H:i:s', strtotime($settingsRow['work_start_time']) + $grace * 60);
    return $now > $deadline;
}

function distance_meters(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $r = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $r * (2 * atan2(sqrt($a), sqrt(1 - $a)));
}

function request_type_ar(string $t): string
{
    return ['leave' => 'إجازة', 'advance' => 'سلفة', 'complaint' => 'شكوى', 'resignation' => 'استقالة'][$t] ?? $t;
}
function request_status_ar(string $s): string
{
    return ['pending' => 'بانتظار موافقتك', 'branch_approved' => 'أُرسل للموارد البشرية', 'approved' => 'مقبول نهائياً', 'rejected' => 'مرفوض'][$s] ?? $s;
}

function log_error(PDO $pdo, string $action, ?string $role, ?int $userId, string $message): void
{
    try {
        $pdo->prepare("INSERT INTO error_log (app, action, user_role, user_id, message) VALUES ('branch', ?, ?, ?, ?)")
            ->execute([$action, $role, $userId, mb_substr($message, 0, 500)]);
    } catch (Throwable $e) {
        // جدول error_log قد لا يكون موجوداً بعد على قاعدة بيانات لم تُحدَّث — تجاهل بصمت
    }
}

$isLoggedIn = !empty($_SESSION['branch_user']);

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
        $employeeNumber = trim($_POST['employeeNumber'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $stmt = $pdo->prepare("SELECT u.*, e.full_name, e.employee_number, e.branch_id AS emp_branch_id FROM users u
            JOIN employees e ON e.id = u.employee_id
            WHERE u.username = ? AND u.role = 'branch_manager' AND u.status = 'active' LIMIT 1");
        $stmt->execute([$employeeNumber]);
        $row = $stmt->fetch();
        if ($row && password_verify($password, $row['password_hash'])) {
            $_SESSION['branch_user'] = [
                'id' => (int) $row['id'],
                'employee_id' => (int) $row['employee_id'],
                'branch_id' => (int) $row['branch_id'],
                'full_name' => $row['full_name'],
                'employee_number' => $row['employee_number'],
            ];
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'رقم الموظف أو الرمز السري غير صحيح']);
        }
        exit;
    }

    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        echo json_encode(['ok' => true]);
        exit;
    }

    if (empty($_SESSION['branch_user'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'unauthorized']);
        exit;
    }
    $mgr = $_SESSION['branch_user'];
    $branchId = $mgr['branch_id'];

    if ($action === 'log_error') {
        $clientMsg = trim((string) ($_POST['message'] ?? ''));
        $clientAction = trim((string) ($_POST['clientAction'] ?? 'client'));
        if ($clientMsg !== '') {
            log_error($pdo, $clientAction, 'branch_manager', $mgr['id'], $clientMsg);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    try {
    switch ($action) {

        case 'bootstrap': {
            $branch = $pdo->prepare("SELECT * FROM branches WHERE id = ?");
            $branch->execute([$branchId]);
            $branch = $branch->fetch();

            $mgrPhotoStmt = $pdo->prepare("SELECT photo, shift_start, shift_end FROM employees WHERE id = ?");
            $mgrPhotoStmt->execute([$mgr['employee_id']]);
            $mgrRow = $mgrPhotoStmt->fetch();
            $mgrPhoto = $mgrRow['photo'] ?? null;

            $settingsRow = $pdo->query("SELECT work_start_time, work_end_time, late_grace_minutes, company_name, company_logo FROM settings ORDER BY id DESC LIMIT 1")->fetch();
            $shiftStart = $mgrRow['shift_start'] ?: ($settingsRow['work_start_time'] ?? '09:00:00');
            $shiftEnd = $mgrRow['shift_end'] ?: ($settingsRow['work_end_time'] ?? '17:00:00');
            $graceMinutes = (int) ($settingsRow['late_grace_minutes'] ?? 15);

            $todayAttStmt = $pdo->prepare("SELECT check_in, check_out FROM attendance WHERE employee_id=? AND attendance_date=CURDATE()");
            $todayAttStmt->execute([$mgr['employee_id']]);
            $todayAtt = $todayAttStmt->fetch();

            $empCount = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE branch_id=? AND status='active' AND is_branch_manager=0");
            $empCount->execute([$branchId]);

            $today = date('Y-m-d');
            $presentToday = $pdo->prepare("SELECT COUNT(*) FROM attendance a JOIN employees e ON e.id=a.employee_id WHERE a.branch_id=? AND a.attendance_date=? AND a.status IN ('present','late') AND e.is_branch_manager=0");
            $presentToday->execute([$branchId, $today]);

            $pendingRequests = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE branch_id=? AND status='pending'");
            $pendingRequests->execute([$branchId]);

            $monthStart = date('Y-m-01');
            $commitStmt = $pdo->prepare("SELECT
                SUM(status IN ('present','late')) AS present_days, COUNT(*) AS total_days
                FROM attendance WHERE branch_id=? AND attendance_date >= ?");
            $commitStmt->execute([$branchId, $monthStart]);
            $commit = $commitStmt->fetch();
            $totalDays = max(1, (int) ($commit['total_days'] ?? 0));
            $commitmentPct = round((((int) ($commit['present_days'] ?? 0)) / $totalDays) * 100);

            $delegStmt = $pdo->prepare("SELECT d.*, e.full_name FROM delegations d JOIN employees e ON e.id=d.delegated_employee_id WHERE d.branch_id=? AND d.status='active' ORDER BY d.id DESC LIMIT 1");
            $delegStmt->execute([$branchId]);
            $delegation = $delegStmt->fetch();

            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $prevStmt = $pdo->prepare("SELECT (total_income - total_expense) AS profit FROM daily_briefs WHERE branch_id=? AND brief_date=?");
            $prevStmt->execute([$branchId, $yesterday]);
            $previousProfit = (float) ($prevStmt->fetchColumn() ?: 0);

            echo json_encode([
                'ok' => true,
                'manager' => ['name' => $mgr['full_name'], 'code' => $mgr['employee_number'], 'branch' => $branch['name'], 'photo' => $mgrPhoto ?: null],
                'company' => ['name' => $settingsRow['company_name'] ?: 'شركة الصوى للصرافة', 'logo' => $settingsRow['company_logo'] ?: null],
                'stats' => [
                    'employees' => (int) $empCount->fetchColumn(),
                    'presentToday' => (int) $presentToday->fetchColumn(),
                    'pendingRequests' => (int) $pendingRequests->fetchColumn(),
                    'commitmentPct' => $commitmentPct,
                ],
                'branchLocation' => [
                    'lat' => $branch['latitude'] ? (float) $branch['latitude'] : null,
                    'lng' => $branch['longitude'] ? (float) $branch['longitude'] : null,
                    'radius' => (int) $branch['geofence_radius'],
                ],
                'delegation' => $delegation ? [
                    'employeeId' => (int) $delegation['delegated_employee_id'],
                    'employee' => $delegation['full_name'],
                    'start' => $delegation['start_date'],
                    'end' => $delegation['end_date'],
                    'active' => true,
                ] : null,
                'previousDayProfit' => $previousProfit,
                'shift' => [
                    'start' => substr($shiftStart, 0, 5),
                    'end' => substr($shiftEnd, 0, 5),
                    'graceMinutes' => $graceMinutes,
                ],
                'todayAttendance' => [
                    'checkedIn' => (bool) ($todayAtt['check_in'] ?? false),
                    'checkedOut' => (bool) ($todayAtt['check_out'] ?? false),
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'notifications_list': {
            $stmt = $pdo->prepare("SELECT id, title, message, is_read, DATE_FORMAT(created_at,'%d/%m/%Y %H:%i') AS date FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 30");
            $stmt->execute([$mgr['id']]);
            $rows = $stmt->fetchAll();
            $unread = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
            $unread->execute([$mgr['id']]);
            echo json_encode(['ok' => true, 'notifications' => $rows, 'unread' => (int) $unread->fetchColumn()], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'notifications_mark_all_read': {
            $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$mgr['id']]);
            echo json_encode(['ok' => true]);
            exit;
        }

        case 'employees': {
            $stmt = $pdo->prepare("
                SELECT e.id, e.employee_number AS code, e.full_name AS name, e.job_title AS title,
                       e.base_salary AS salary, e.rating, e.status,
                       ROUND(SUM(a.status IN ('present','late')) / GREATEST(COUNT(a.id),1) * 100) AS attendancePct
                FROM employees e
                LEFT JOIN attendance a ON a.employee_id=e.id AND a.attendance_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                WHERE e.branch_id=? AND e.is_branch_manager=0
                GROUP BY e.id, e.employee_number, e.full_name, e.job_title, e.base_salary, e.rating, e.status
                ORDER BY e.created_at DESC
            ");
            $stmt->execute([$branchId]);
            $rows = array_map(function ($r) {
                $r['attendancePct'] = (int) ($r['attendancePct'] ?? 0);
                $r['salary'] = (float) $r['salary'];
                $r['rating'] = (float) $r['rating'];
                return $r;
            }, $stmt->fetchAll());
            echo json_encode(['ok' => true, 'employees' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'employee_add': {
            $name = trim($_POST['name'] ?? '');
            $position = trim($_POST['position'] ?? '');
            $salary = (float) ($_POST['salary'] ?? 0);
            $password = (string) ($_POST['password'] ?? '');
            $motherName = trim($_POST['motherName'] ?? '');
            $nationalId = trim($_POST['nationalId'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $birthDate = $_POST['birthDate'] ?: null;
            $hireDate = $_POST['hireDate'] ?: null;
            $shiftType = ($_POST['shiftType'] ?? '') === 'evening' ? 'evening' : 'morning';
            $shiftStart = $_POST['shiftStart'] ?: null;
            $shiftEnd = $_POST['shiftEnd'] ?: null;
            if ($name === '' || $position === '' || $salary <= 0 || strlen($password) < 6 || $phone === '') {
                echo json_encode(['ok' => false, 'error' => 'الرجاء تعبئة جميع الحقول المطلوبة (رقم الهاتف مطلوب، وكلمة المرور 6 أحرف على الأقل)']);
                exit;
            }
            $photoPath = handle_upload('photo', 'photos', ['jpg', 'jpeg', 'png', 'webp']);
            if (!$photoPath) {
                echo json_encode(['ok' => false, 'error' => 'صورة الموظف مطلوبة']);
                exit;
            }
            $docsPath = handle_upload('documents', 'documents', ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx']);
            $pdo->beginTransaction();
            try {
                $numStmt = $pdo->query("SELECT MAX(CAST(employee_number AS UNSIGNED)) FROM employees WHERE employee_number REGEXP '^[0-9]+$'");
                $empNumber = (string) max(1001, (int) $numStmt->fetchColumn() + 1);
                $stmt = $pdo->prepare("INSERT INTO employees
                    (branch_id, employee_number, full_name, mother_name, national_id, phone_number, birth_date, hire_date, job_title, shift_type, shift_start, shift_end, photo, documents, base_salary, is_branch_manager, status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'active')");
                $stmt->execute([$branchId, $empNumber, $name, $motherName ?: null, $nationalId ?: null, $phone, $birthDate, $hireDate, $position, $shiftType, $shiftStart, $shiftEnd, $photoPath, $docsPath, $salary]);
                $employeeId = (int) $pdo->lastInsertId();

                $hash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("INSERT INTO users (role, username, password_hash, employee_id, branch_id, status) VALUES ('employee', ?, ?, ?, ?, 'active')")
                    ->execute([$empNumber, $hash, $employeeId, $branchId]);

                $pdo->commit();
                echo json_encode(['ok' => true, 'code' => $empNumber]);
            } catch (Throwable $ex) {
                $pdo->rollBack();
                echo json_encode(['ok' => false, 'error' => $ex->getMessage()]);
            }
            exit;
        }

        case 'branch_location_save': {
            $lat = (float) ($_POST['lat'] ?? 0);
            $lng = (float) ($_POST['lng'] ?? 0);
            $radius = (int) ($_POST['radius'] ?? 100);
            $pdo->prepare("UPDATE branches SET latitude=?, longitude=?, geofence_radius=? WHERE id=?")->execute([$lat, $lng, $radius, $branchId]);
            echo json_encode(['ok' => true]);
            exit;
        }

        case 'attendance_self': {
            $type = ($_POST['type'] ?? '') === 'out' ? 'out' : 'in';
            $lat = (float) ($_POST['lat'] ?? 0);
            $lng = (float) ($_POST['lng'] ?? 0);

            $branch = $pdo->prepare("SELECT latitude, longitude, geofence_radius FROM branches WHERE id=?");
            $branch->execute([$branchId]);
            $branch = $branch->fetch();
            if (!$branch['latitude'] || !$branch['longitude']) {
                echo json_encode(['ok' => false, 'error' => 'لم يتم تحديد موقع الفرع بعد']);
                exit;
            }
            $dist = distance_meters((float) $branch['latitude'], (float) $branch['longitude'], $lat, $lng);
            if ($dist > (int) $branch['geofence_radius']) {
                echo json_encode(['ok' => false, 'error' => 'أنت خارج نطاق الفرع (المسافة: ' . round($dist) . 'م)']);
                exit;
            }

            $today = date('Y-m-d');
            $now = date('H:i:s');
            $employeeId = $mgr['employee_id'];
            if ($type === 'in') {
                $existing = $pdo->prepare("SELECT check_in FROM attendance WHERE employee_id=? AND attendance_date=?");
                $existing->execute([$employeeId, $today]);
                if ($existing->fetchColumn()) {
                    echo json_encode(['ok' => false, 'error' => 'تم تسجيل حضورك اليوم مسبقاً']);
                    exit;
                }
                $settingsRow = $pdo->query("SELECT work_start_time, late_grace_minutes FROM settings ORDER BY id DESC LIMIT 1")->fetch();
                $status = is_late($now, $settingsRow) ? 'late' : 'present';
                $stmt = $pdo->prepare("INSERT INTO attendance (employee_id, branch_id, attendance_date, check_in, status)
                    VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE check_in=VALUES(check_in), status=VALUES(status)");
                $stmt->execute([$employeeId, $branchId, $today, $now, $status]);
            } else {
                $checkedIn = $pdo->prepare("SELECT check_in, check_out FROM attendance WHERE employee_id=? AND attendance_date=?");
                $checkedIn->execute([$employeeId, $today]);
                $checkedIn = $checkedIn->fetch();
                if (!$checkedIn || !$checkedIn['check_in']) {
                    echo json_encode(['ok' => false, 'error' => 'يجب تسجيل الحضور أولاً']);
                    exit;
                }
                if ($checkedIn['check_out']) {
                    echo json_encode(['ok' => false, 'error' => 'تم تسجيل انصرافك اليوم مسبقاً']);
                    exit;
                }
                $pdo->prepare("UPDATE attendance SET check_out=? WHERE employee_id=? AND attendance_date=?")->execute([$now, $employeeId, $today]);
            }
            echo json_encode(['ok' => true, 'time' => substr($now, 0, 5)]);
            exit;
        }

        case 'attendance_today': {
            $stmt = $pdo->prepare("
                SELECT e.full_name AS name, e.job_title AS title, a.check_in AS checkIn, a.check_out AS checkOut, a.status
                FROM employees e LEFT JOIN attendance a ON a.employee_id=e.id AND a.attendance_date=CURDATE()
                WHERE e.branch_id=? AND e.is_branch_manager=0 AND e.status='active' ORDER BY e.full_name
            ");
            $stmt->execute([$branchId]);
            $rows = array_map(function ($r) {
                $r['checkIn'] = $r['checkIn'] ? substr($r['checkIn'], 0, 5) : '-';
                $r['checkOut'] = $r['checkOut'] ? substr($r['checkOut'], 0, 5) : '-';
                $r['status'] = $r['status'] ? ['present' => 'حاضر', 'late' => 'تأخير', 'absent' => 'غائب'][$r['status']] : 'غائب';
                return $r;
            }, $stmt->fetchAll());
            echo json_encode(['ok' => true, 'attendance' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'attendance_manual': {
            $employeeId = (int) ($_POST['employeeId'] ?? 0);
            $type = ($_POST['type'] ?? '') === 'out' ? 'out' : 'in';
            $note = trim($_POST['note'] ?? '');
            $ownCheck = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE id=? AND branch_id=?");
            $ownCheck->execute([$employeeId, $branchId]);
            if (!$ownCheck->fetchColumn()) {
                echo json_encode(['ok' => false, 'error' => 'الموظف غير تابع لهذا الفرع']);
                exit;
            }
            $today = date('Y-m-d');
            $now = date('H:i:s');
            if ($type === 'in') {
                $settingsRow = $pdo->query("SELECT work_start_time, late_grace_minutes FROM settings ORDER BY id DESC LIMIT 1")->fetch();
                $status = is_late($now, $settingsRow) ? 'late' : 'present';
                $stmt = $pdo->prepare("INSERT INTO attendance (employee_id, branch_id, attendance_date, check_in, status)
                    VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE check_in=VALUES(check_in), status=VALUES(status)");
                $stmt->execute([$employeeId, $branchId, $today, $now, $status]);
            } else {
                $pdo->prepare("UPDATE attendance SET check_out=? WHERE employee_id=? AND attendance_date=?")->execute([$now, $employeeId, $today]);
            }
            echo json_encode(['ok' => true, 'time' => substr($now, 0, 5), 'note' => $note]);
            exit;
        }

        case 'my_profile': {
            $meRow = $pdo->prepare("SELECT e.full_name, e.employee_number, e.base_salary, b.name AS branch_name
                FROM employees e JOIN branches b ON b.id = e.branch_id WHERE e.id = ?");
            $meRow->execute([$mgr['employee_id']]);
            $meRow = $meRow->fetch();

            $month = (int) date('n');
            $year = (int) date('Y');
            $payStmt = $pdo->prepare("SELECT * FROM payroll WHERE employee_id=? AND period_month=? AND period_year=?");
            $payStmt->execute([$mgr['employee_id'], $month, $year]);
            $pay = $payStmt->fetch();

            $base = $pay ? (float) $pay['base_salary'] : (float) $meRow['base_salary'];
            $bonus = $pay ? (float) $pay['bonus'] : 0.0;
            $deduction = $pay ? (float) $pay['deduction'] : 0.0;
            $net = $base + $bonus - $deduction;
            $statusText = !$pay ? 'لم يُحتسب بعد' : ($pay['status'] === 'delivered' ? 'تم التسليم' : 'قيد المعالجة');

            echo json_encode([
                'ok' => true,
                'name' => $meRow['full_name'],
                'code' => $meRow['employee_number'],
                'branch' => $meRow['branch_name'],
                'baseSalary' => (float) $meRow['base_salary'],
                'salary' => ['base' => $base, 'bonus' => $bonus, 'deduction' => $deduction, 'net' => $net, 'statusText' => $statusText],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'my_attendance_month': {
            $month = max(1, min(12, (int) ($_GET['month'] ?? date('n'))));
            $year = (int) ($_GET['year'] ?? date('Y'));
            $from = sprintf('%04d-%02d-01', $year, $month);
            $daysInMonth = (int) date('t', strtotime($from));
            $to = date('Y-m-t', strtotime($from));

            $rows = $pdo->prepare("SELECT attendance_date, check_in, check_out, status FROM attendance WHERE employee_id=? AND attendance_date BETWEEN ? AND ?");
            $rows->execute([$mgr['employee_id'], $from, $to]);
            $byDate = [];
            foreach ($rows->fetchAll() as $r) { $byDate[$r['attendance_date']] = $r; }

            $today = date('Y-m-d');
            $present = 0; $late = 0; $absent = 0;
            $days = [];
            for ($d = 1; $d <= $daysInMonth; $d++) {
                $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
                $dow = (int) date('w', strtotime($date));
                $isOff = ($dow === 5 || $dow === 6);
                $rec = $byDate[$date] ?? null;
                if ($isOff) {
                    $status = 'off';
                } elseif ($rec && $rec['check_in']) {
                    $status = $rec['status'] === 'late' ? 'late' : 'present';
                } elseif ($date < $today) {
                    $status = 'absent';
                } else {
                    $status = 'future';
                }
                if ($status === 'present') $present++;
                elseif ($status === 'late') $late++;
                elseif ($status === 'absent') $absent++;
                $days[] = [
                    'day' => $d,
                    'status' => $status,
                    'checkIn' => $rec && $rec['check_in'] ? substr($rec['check_in'], 0, 5) : null,
                    'checkOut' => $rec && $rec['check_out'] ? substr($rec['check_out'], 0, 5) : null,
                ];
            }

            echo json_encode([
                'ok' => true,
                'month' => $month,
                'year' => $year,
                'days' => $days,
                'summary' => ['present' => $present, 'late' => $late, 'absent' => $absent],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'requests': {
            $stmt = $pdo->prepare("
                SELECT r.id, e.full_name AS name, r.type, r.details, r.amount, r.date_from, r.date_to, r.status
                FROM requests r JOIN employees e ON e.id = r.employee_id
                WHERE r.branch_id = ? ORDER BY r.created_at DESC LIMIT 50
            ");
            $stmt->execute([$branchId]);
            $rows = array_map(function ($r) {
                $details = $r['details'];
                if ($r['type'] === 'advance' && $r['amount']) {
                    $details = number_format((float) $r['amount']) . ' دينار' . ($details ? ' - ' . $details : '');
                } elseif ($r['type'] === 'leave' && $r['date_from']) {
                    $details = $r['date_from'] . ' إلى ' . $r['date_to'] . ($details ? ' - ' . $details : '');
                }
                return [
                    'id' => $r['id'],
                    'name' => $r['name'],
                    'type' => request_type_ar($r['type']),
                    'details' => $details ?: '-',
                    'status' => request_status_ar($r['status']),
                    'canReview' => $r['status'] === 'pending',
                ];
            }, $stmt->fetchAll());
            echo json_encode(['ok' => true, 'requests' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'request_review': {
            $id = (int) ($_POST['id'] ?? 0);
            $decision = ($_POST['decision'] ?? '') === 'approved' ? 'branch_approved' : 'rejected';
            $note = trim($_POST['note'] ?? '');
            $stmt = $pdo->prepare("UPDATE requests SET status=?, branch_review_note=?, branch_reviewed_by=?, branch_reviewed_at=NOW() WHERE id=? AND branch_id=? AND status='pending'");
            $stmt->execute([$decision, $note ?: null, $mgr['id'], $id, $branchId]);
            if ($stmt->rowCount() === 0) {
                echo json_encode(['ok' => false, 'error' => 'الطلب غير متاح للمراجعة']);
                exit;
            }
            $reqRow = $pdo->prepare("SELECT employee_id, type FROM requests WHERE id=?");
            $reqRow->execute([$id]);
            $reqRow = $reqRow->fetch();
            if ($reqRow) {
                $msg = $decision === 'branch_approved'
                    ? 'وافق مدير الفرع على طلب ' . request_type_ar($reqRow['type']) . ' وأُرسل للموارد البشرية' . ($note ? (' — ' . $note) : '')
                    : 'رفض مدير الفرع طلب ' . request_type_ar($reqRow['type']) . ($note ? (' — ' . $note) : '');
                $uids = $pdo->prepare("SELECT id FROM users WHERE employee_id=?");
                $uids->execute([$reqRow['employee_id']]);
                foreach ($uids->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                    $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, 'رد على طلبك', ?)")->execute([$uid, $msg]);
                }
            }
            echo json_encode(['ok' => true]);
            exit;
        }

        case 'delegation_save': {
            $employeeId = (int) ($_POST['employeeId'] ?? 0);
            $start = $_POST['start'] ?? date('Y-m-d');
            $end = $_POST['end'] ?? date('Y-m-d');
            $ownCheck = $pdo->prepare("SELECT COUNT(*) FROM employees WHERE id=? AND branch_id=?");
            $ownCheck->execute([$employeeId, $branchId]);
            if (!$ownCheck->fetchColumn()) {
                echo json_encode(['ok' => false, 'error' => 'الموظف غير تابع لهذا الفرع']);
                exit;
            }
            $pdo->prepare("UPDATE delegations SET status='ended' WHERE branch_id=? AND status='active'")->execute([$branchId]);
            $pdo->prepare("INSERT INTO delegations (branch_id, delegated_employee_id, start_date, end_date, status) VALUES (?, ?, ?, ?, 'active')")
                ->execute([$branchId, $employeeId, $start, $end]);
            echo json_encode(['ok' => true]);
            exit;
        }

        case 'delegation_cancel': {
            $pdo->prepare("UPDATE delegations SET status='ended' WHERE branch_id=? AND status='active'")->execute([$branchId]);
            echo json_encode(['ok' => true]);
            exit;
        }

        case 'ledger_list': {
            $date = $_GET['date'] ?? date('Y-m-d');
            $stmt = $pdo->prepare("SELECT id, entry_type, amount, description, attachment FROM daily_ledger WHERE branch_id=? AND entry_date=? ORDER BY created_at DESC");
            $stmt->execute([$branchId, $date]);
            $rows = $stmt->fetchAll();
            echo json_encode(['ok' => true, 'entries' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'ledger_add': {
            $type = ($_POST['type'] ?? '') === 'expense' ? 'expense' : 'income';
            $amount = (float) ($_POST['amount'] ?? 0);
            $note = trim($_POST['note'] ?? '');
            if ($amount <= 0) {
                echo json_encode(['ok' => false, 'error' => 'يرجى إدخال مبلغ صحيح']);
                exit;
            }
            $attachment = handle_upload('file', 'ledger', ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx']);
            $stmt = $pdo->prepare("INSERT INTO daily_ledger (branch_id, entry_date, entry_type, amount, description, attachment, created_by) VALUES (?, CURDATE(), ?, ?, ?, ?, ?)");
            $stmt->execute([$branchId, $type, $amount, $note, $attachment, $mgr['id']]);
            echo json_encode(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
            exit;
        }

        case 'ledger_delete': {
            $id = (int) ($_POST['id'] ?? 0);
            $pdo->prepare("DELETE FROM daily_ledger WHERE id=? AND branch_id=?")->execute([$id, $branchId]);
            echo json_encode(['ok' => true]);
            exit;
        }

        case 'briefing_publish': {
            $travelersCount = (int) ($_POST['travelersCount'] ?? 0);
            $stmt = $pdo->prepare("SELECT
                COALESCE(SUM(CASE WHEN entry_type='income' THEN amount ELSE 0 END),0) AS income,
                COALESCE(SUM(CASE WHEN entry_type='expense' THEN amount ELSE 0 END),0) AS expense
                FROM daily_ledger WHERE branch_id=? AND entry_date=CURDATE()");
            $stmt->execute([$branchId]);
            $totals = $stmt->fetch();
            if ((float) $totals['income'] === 0.0 && (float) $totals['expense'] === 0.0) {
                echo json_encode(['ok' => false, 'error' => 'لا توجد قيود لنشرها، أضف قيداً أولاً']);
                exit;
            }
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $prevStmt = $pdo->prepare("SELECT (total_income - total_expense) AS profit FROM daily_briefs WHERE branch_id=? AND brief_date=?");
            $prevStmt->execute([$branchId, $yesterday]);
            $previousProfit = (float) ($prevStmt->fetchColumn() ?: 0);

            $stmt = $pdo->prepare("INSERT INTO daily_briefs (branch_id, brief_date, total_income, total_expense, previous_profit, travelers_count, status, submitted_by)
                VALUES (?, CURDATE(), ?, ?, ?, ?, 'pending', ?)
                ON DUPLICATE KEY UPDATE total_income=VALUES(total_income), total_expense=VALUES(total_expense), travelers_count=VALUES(travelers_count), status='pending', submitted_by=VALUES(submitted_by)");
            $stmt->execute([$branchId, $totals['income'], $totals['expense'], $previousProfit, $travelersCount, $mgr['employee_id']]);

            $branchName = $pdo->prepare("SELECT name FROM branches WHERE id=?");
            $branchName->execute([$branchId]);
            $branchName = $branchName->fetchColumn();
            $reviewerUids = $pdo->query("SELECT id FROM users WHERE role IN ('hr','general_manager')")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($reviewerUids as $uid) {
                $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, 'إيجاز جديد بانتظار المراجعة', ?)")
                    ->execute([$uid, 'نشر مدير فرع ' . $branchName . ' إيجاز اليوم بانتظار مراجعتك']);
            }

            echo json_encode(['ok' => true]);
            exit;
        }

        case 'brief_status': {
            $stmt = $pdo->prepare("SELECT status, hr_note, gm_review_note, travelers_count FROM daily_briefs WHERE branch_id=? AND brief_date=CURDATE()");
            $stmt->execute([$branchId]);
            $brief = $stmt->fetch();
            if (!$brief) {
                echo json_encode(['ok' => true, 'status' => null, 'statusText' => 'لم يُنشر بعد', 'note' => null]);
                exit;
            }
            $map = [
                'pending' => 'بانتظار مراجعة الموارد البشرية والمسؤول العام',
                'hr_approved' => 'وافقت الموارد البشرية — بانتظار موافقة المسؤول العام أيضاً',
                'gm_approved' => 'وافق المسؤول العام — بانتظار موافقة الموارد البشرية أيضاً',
                'approved' => 'معتمد نهائياً (وافق الطرفان)',
                'rejected' => 'مرفوض',
            ];
            $notes = array_filter([$brief['hr_note'] ? ('HR: ' . $brief['hr_note']) : null, $brief['gm_review_note'] ? ('المسؤول العام: ' . $brief['gm_review_note']) : null]);
            $note = $notes ? implode(' | ', $notes) : null;
            echo json_encode(['ok' => true, 'status' => $brief['status'], 'statusText' => $map[$brief['status']] ?? $brief['status'], 'note' => $note, 'travelersCount' => (int) $brief['travelers_count']]);
            exit;
        }

        case 'payroll_list': {
            $month = (int) ($_GET['month'] ?? date('n'));
            $year = (int) ($_GET['year'] ?? date('Y'));
            $stmt = $pdo->prepare("
                SELECT e.id, e.full_name AS name, e.base_salary,
                       COALESCE(p.deduction, 0) AS loan, COALESCE(p.status, 'pending') AS status
                FROM employees e
                LEFT JOIN payroll p ON p.employee_id = e.id AND p.period_month=? AND p.period_year=?
                WHERE e.branch_id=? AND e.is_branch_manager=0 AND e.status='active'
                ORDER BY (COALESCE(p.status, 'pending') = 'delivered') ASC, e.full_name
            ");
            $stmt->execute([$month, $year, $branchId]);
            $rows = array_map(function ($r) {
                $salary = (float) $r['base_salary'];
                $loan = (float) $r['loan'];
                return [
                    'id' => $r['id'], 'name' => $r['name'], 'salary' => $salary, 'loan' => $loan,
                    'net' => $salary - $loan, 'status' => $r['status'] === 'delivered' ? 'تم التسليم' : 'قيد الانتظار',
                ];
            }, $stmt->fetchAll());
            echo json_encode(['ok' => true, 'payroll' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'pay_salary': {
            $employeeId = (int) ($_POST['employeeId'] ?? 0);
            $month = (int) date('n');
            $year = (int) date('Y');

            $winStmt = $pdo->prepare("SELECT expires_at FROM payroll_windows WHERE period_month=? AND period_year=?");
            $winStmt->execute([$month, $year]);
            $expiresAt = $winStmt->fetchColumn();
            if (!$expiresAt || strtotime($expiresAt) < time()) {
                echo json_encode(['ok' => false, 'error' => 'صلاحية تسليم الرواتب لهذا الشهر مغلقة حالياً']);
                exit;
            }

            $empStmt = $pdo->prepare("SELECT full_name, base_salary FROM employees WHERE id=? AND branch_id=?");
            $empStmt->execute([$employeeId, $branchId]);
            $emp = $empStmt->fetch();
            if (!$emp) {
                echo json_encode(['ok' => false, 'error' => 'الموظف غير موجود']);
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

            $advStmt = $pdo->prepare("SELECT id, approved_monthly_deduction, remaining_balance FROM requests WHERE employee_id=? AND type='advance' AND status='approved' AND remaining_balance > 0 ORDER BY id ASC LIMIT 1");
            $advStmt->execute([$employeeId]);
            $adv = $advStmt->fetch();
            if ($adv) {
                $advanceCut = min((float) $adv['approved_monthly_deduction'], (float) $adv['remaining_balance']);
                $deduction += $advanceCut;
                $pdo->prepare("UPDATE requests SET remaining_balance = remaining_balance - ? WHERE id=?")->execute([$advanceCut, $adv['id']]);
            }

            $stmt = $pdo->prepare("INSERT INTO payroll (employee_id, branch_id, period_month, period_year, base_salary, bonus, deduction, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'delivered')
                ON DUPLICATE KEY UPDATE base_salary=VALUES(base_salary), bonus=VALUES(bonus), deduction=VALUES(deduction), status='delivered'");
            $stmt->execute([$employeeId, $branchId, $month, $year, $baseSalary, $bonus, $deduction]);

            $net = $baseSalary + $bonus - $deduction;
            $notifyUsers = $pdo->prepare("SELECT id FROM users WHERE employee_id=? UNION SELECT id FROM users WHERE role='hr'");
            $notifyUsers->execute([$employeeId]);
            foreach ($notifyUsers->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, 'تم تسليم الراتب', ?)")
                    ->execute([$uid, 'تم تسليم راتب ' . $emp['full_name'] . ' عن شهر ' . $month . '/' . $year . ' بصافي ' . number_format($net) . ' دينار']);
            }

            echo json_encode(['ok' => true, 'net' => $net]);
            exit;
        }

        case 'report': {
            $type = $_GET['type'] ?? 'attendance';
            $from = $_GET['from'] ?? date('Y-m-01');
            $to = $_GET['to'] ?? date('Y-m-d');
            echo json_encode(['ok' => true] + branch_report_data($pdo, $type, $from, $to, $branchId), JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'report_download': {
            $type = $_GET['type'] ?? 'attendance';
            $from = $_GET['from'] ?? date('Y-m-01');
            $to = $_GET['to'] ?? date('Y-m-d');
            $data = branch_report_data($pdo, $type, $from, $to, $branchId);

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
        log_error($pdo, $action, 'branch_manager', $mgr['id'], $ex->getMessage());
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'حدث خطأ غير متوقع في الخادم — تأكد من تشغيل migrate.php على قاعدة البيانات']);
        exit;
    }
}

$welcomeCompanyName = 'شركة الصوى للصرافة';
$welcomeCompanyLogo = null;
try {
    $wcRow = db()->query("SELECT company_name, company_logo FROM settings ORDER BY id DESC LIMIT 1")->fetch();
    if ($wcRow) {
        $welcomeCompanyName = $wcRow['company_name'] ?: $welcomeCompanyName;
        $welcomeCompanyLogo = $wcRow['company_logo'] ?: null;
    }
} catch (Throwable $e) {
    // إعدادات غير متوفرة بعد (قبل تشغيل migrate.php) — استخدم الاسم الافتراضي
}

function branch_report_data(PDO $pdo, string $type, string $from, string $to, int $branchId): array
{
    $result = [];

    if ($type === 'attendance' || $type === 'all') {
        $stmt = $pdo->prepare("SELECT e.full_name AS name, a.check_in AS checkIn, a.check_out AS checkOut, a.status
                FROM attendance a JOIN employees e ON e.id=a.employee_id
                WHERE a.branch_id = ? AND a.attendance_date BETWEEN ? AND ? ORDER BY a.attendance_date DESC");
        $stmt->execute([$branchId, $from, $to]);
        $result['attendance'] = array_map(function ($r) {
            $r['checkIn'] = $r['checkIn'] ? substr($r['checkIn'], 0, 5) : '--:--';
            $r['checkOut'] = $r['checkOut'] ? substr($r['checkOut'], 0, 5) : '--:--';
            $r['status'] = ['present' => 'حاضر', 'late' => 'متأخر', 'absent' => 'غائب'][$r['status']] ?? $r['status'];
            return $r;
        }, $stmt->fetchAll());
    }

    if ($type === 'salaries' || $type === 'all') {
        $stmt = $pdo->prepare("SELECT e.full_name AS name, p.base_salary AS base, p.bonus, p.deduction,
                       (p.base_salary + p.bonus - p.deduction) AS net, p.status
                FROM payroll p JOIN employees e ON e.id=p.employee_id
                WHERE p.branch_id = ? AND DATE(p.created_at) BETWEEN ? AND ?");
        $stmt->execute([$branchId, $from, $to]);
        $result['salaries'] = array_map(function ($r) {
            foreach (['base', 'bonus', 'deduction', 'net'] as $k) $r[$k] = number_format((float) $r[$k]);
            $r['status'] = $r['status'] === 'delivered' ? 'مدفوع' : 'قيد المعالجة';
            return $r;
        }, $stmt->fetchAll());
    }

    if ($type === 'briefing' || $type === 'all') {
        $stmt = $pdo->prepare("SELECT DATE_FORMAT(brief_date,'%d/%m/%Y') AS date, total_income AS revenue, total_expense AS expense,
                       travelers_count AS travelers, (total_income - total_expense) AS profit, status,
                       hr_note AS hrNote, gm_review_note AS gmNote
                FROM daily_briefs WHERE branch_id = ? AND brief_date BETWEEN ? AND ? ORDER BY brief_date DESC");
        $stmt->execute([$branchId, $from, $to]);
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
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>شركة الصوى للصرافة - مدير الفرع</title>
    <link rel="manifest" href="manifest.php?app=branch">
    <meta name="theme-color" content="#006b73">
    <link rel="apple-touch-icon" href="icons/icon-192.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <style>
        :root {
            --primary: #003f46;
            --primary-light: #006b73;
            --primary-gradient: linear-gradient(135deg, #003f46 0%, #006b73 100%);
            --accent: #c99a3d;
            --green: #159447;
            --red: #df4b4b;
            --orange: #d98c1a;
            --bg: #e8eeee;
            --bg-card: #FFFFFF;
            --text-primary: #173437;
            --text-muted: #718083;
            --radius-md: 14px;
            --radius-lg: 20px;
            --radius-full: 9999px;
            --font-family: 'IBM Plex Sans Arabic', 'Tajawal', sans-serif;
            --header-height: 64px;
            --nav-height: 72px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: var(--font-family);
            background: var(--bg);
            color: var(--text-primary);
            min-height: 100vh;
            padding-bottom: calc(var(--nav-height) + 20px);
            font-size: 14px;
        }
        .hidden { display: none !important; }

        /* شاشة الترحيب */
        .welcome-screen {
            position: fixed;
            inset: 0;
            z-index: 9999;
            background: #003f46;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.8s ease;
            transition: opacity 0.8s ease, transform 0.8s ease;
        }
        .welcome-screen.fade-out { opacity: 0; transform: translateX(-50%) scale(1.05); pointer-events: none; }
        @keyframes fadeIn { 0% { opacity: 0; transform: translateX(-50%) scale(1.02); } 100% { opacity: 1; transform: translateX(-50%) scale(1); } }
        .welcome-logo { display: flex; flex-direction: column; align-items: center; justify-content: center; flex: 1; width: 100%; }
        .welcome-logo .logo-icon { width: 130px; height: 130px; background: var(--primary-gradient); border-radius: 32px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 56px; font-weight: 900; box-shadow: 0 12px 48px rgba(0,0,0,0.3); margin-bottom: 16px; overflow: hidden; }
        .welcome-logo .logo-icon img { width: 100%; height: 100%; object-fit: cover; }
        .welcome-logo h1 { color: #fff; font-size: 26px; font-weight: 900; text-align: center; }
        .welcome-logo h1 span { color: var(--accent); }
        .welcome-loader { width: 200px; max-width: 60%; margin-top: 12px; padding: 8px 0; display: flex; flex-direction: column; align-items: center; gap: 6px; }
        .welcome-loader .loader-label { color: rgba(255,255,255,0.5); font-size: 11px; font-weight: 400; }
        .welcome-loader .loader-bar-wrapper { width: 100%; height: 3px; background: rgba(255,255,255,0.1); border-radius: 10px; overflow: hidden; }
        .welcome-loader .loader-bar { height: 100%; width: 0%; background: linear-gradient(90deg, var(--accent), var(--primary-light)); border-radius: 10px; transition: width 0.3s ease; position: relative; }
        .welcome-loader .loader-bar::after { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent); animation: shimmer 1.8s infinite; }
        @keyframes shimmer { 0% { transform: translateX(-100%); } 100% { transform: translateX(100%); } }

        /* إطار تطبيق ثابت العرض لشاشات ما قبل الدخول فقط (الترحيب/الدخول) —
           لا يُطبَّق على لوحة التحكم بعد الدخول حتى لا يتعارض مع تخطيط سطح المكتب */
        body { background: #003f46; }
        .welcome-screen, .login-page {
            left: 50% !important;
            right: auto !important;
            width: 480px !important;
            max-width: 100% !important;
            transform: translateX(-50%);
            box-shadow: 0 0 0 1px rgba(255,255,255,0.08), 0 30px 80px rgba(0,0,0,0.45);
        }
        .login-page {
            position: fixed;
            top: 0;
            bottom: 0;
        }

        /* تسجيل الدخول */
        .login-page { min-height: 100vh; background: linear-gradient(135deg, #003f46 0%, #006b73 100%); display: flex; align-items: center; justify-content: center; padding: 20px; }
        .login-card { background: #FFFFFF; border-radius: var(--radius-lg); padding: 40px 32px; max-width: 420px; width: 100%; box-shadow: 0 20px 60px rgba(0,0,0,0.15); direction: rtl; }
        .login-card .login-logo { text-align: center; margin-bottom: 28px; }
        .login-card .login-logo .logo-icon { width: 72px; height: 72px; background: var(--primary-gradient); border-radius: var(--radius-lg); display: inline-flex; align-items: center; justify-content: center; color: #fff; font-size: 32px; font-weight: 900; margin-bottom: 12px; box-shadow: 0 8px 32px rgba(0,63,70,0.4); }
        .login-card .login-logo h2 { font-size: 24px; font-weight: 900; color: #1A2E35; }
        .login-card .login-logo h2 span { color: #006b73; }
        .login-card .login-logo p { color: var(--text-muted); font-size: 14px; margin-top: 4px; font-weight: 400; }
        .login-card .form-group { margin-bottom: 16px; }
        .login-card .form-group label { display: block; font-size: 13px; font-weight: 700; color: #4A6A78; margin-bottom: 6px; text-align: right; }
        .login-card .form-group input { width: 100%; height: 50px; padding: 0 16px; border: 2px solid rgba(0,63,70,0.08); border-radius: 8px; font-size: 14px; background: var(--bg); color: var(--text-primary); font-family: var(--font-family); outline: none; text-align: right; }
        .login-card .form-group input:focus { border-color: var(--primary-light); box-shadow: 0 0 0 4px rgba(0,107,115,0.06); }
        .login-card .btn-login { width: 100%; height: 50px; border: none; border-radius: 14px; background: var(--primary-gradient); color: #fff; font-size: 16px; font-weight: 700; cursor: pointer; font-family: var(--font-family); box-shadow: 0 4px 16px rgba(0,63,70,0.25); margin-top: 8px; display: inline-flex; align-items: center; justify-content: center; gap: 10px; }
        .login-card .btn-login:hover { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(0,63,70,0.35); }
        .login-card .login-error { color: #EF4444; font-size: 13px; text-align: center; margin-top: 12px; display: none; }
        .login-card .login-toggle { margin-top: 16px; text-align: center; font-size: 13px; color: var(--text-muted); font-weight: 400; }

        /* الهيدر */
        .header-glass {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(24px);
            padding: 12px 20px;
            height: var(--header-height);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid rgba(0,0,0,0.04);
            box-shadow: 0 2px 20px rgba(0,0,0,0.04);
        }
        .header-glass .brand { display: flex; align-items: center; gap: 10px; }
        .header-glass .brand .logo { width: 40px; height: 40px; border-radius: 14px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 18px; font-weight: 900; background: var(--primary-gradient); }
        .header-glass .brand .name { font-size: 17px; font-weight: 900; color: var(--text-primary); }
        .header-glass .brand .name span { background: var(--primary-gradient); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .header-glass .brand .role-badge { font-size: 9px; font-weight: 800; padding: 4px 12px; border-radius: var(--radius-full); background: var(--accent); color: #fff; }
        .header-glass .actions .icon-btn { width: 40px; height: 40px; border-radius: var(--radius-full); border: none; background: rgba(0,0,0,0.03); color: var(--text-muted); cursor: pointer; font-size: 18px; }

        /* المحتوى */
        .page-content { max-width: 480px; margin: 0 auto; padding: 14px; padding-bottom: 80px; }
        .page-title { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
        .page-title h2 { font-size: 19px; font-weight: 800; color: var(--text-primary); display: flex; align-items: center; gap: 10px; }
        .page-title h2 i { color: var(--primary-light); }
        .card { background: var(--bg-card); border-radius: var(--radius-lg); border: 1px solid #e2ebeb; box-shadow: 0 2px 8px rgba(0,63,70,0.04); padding: 16px; margin-bottom: 12px; }
        .section-title { font-weight: 800; margin: 16px 2px 10px; font-size: 15px; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
        .section-title i { color: var(--primary-light); }
        .flex-between { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
        .flex { display: flex; align-items: center; gap: 8px; }
        .mt-2 { margin-top: 8px; }
        .muted { font-size: 11px; color: var(--text-muted); }
        .up { color: var(--green); font-weight: 800; }
        .down { color: var(--red); font-weight: 800; }
        .badge { font-size: 10px; font-weight: 700; padding: 4px 10px; border-radius: var(--radius-full); flex-shrink: 0; }
        .badge.ok { background: #e5f6ec; color: var(--green); }
        .badge.wait { background: #fff2d9; color: var(--orange); }
        .badge.danger { background: #ffeaea; color: var(--red); }
        .badge.info { background: #e3f0f0; color: var(--primary-light); }
        .btn { width: 100%; padding: 12px; border: none; border-radius: 14px; background: var(--primary-gradient); color: #fff; font-weight: 700; cursor: pointer; font-family: var(--font-family); font-size: 14px; box-shadow: 0 4px 16px rgba(0,63,70,0.2); display: inline-flex; align-items: center; justify-content: center; gap: 8px; }
        .btn:hover { transform: translateY(-2px); box-shadow: 0 8px 28px rgba(0,63,70,0.3); }
        .btn.light { background: #eaf3f3; color: var(--primary-light); box-shadow: none; }
        .btn.light:hover { background: #dce8e8; }
        .btn.small { padding: 6px 12px; font-size: 11px; width: auto; flex: 1; }
        .btn.green { background: var(--green); }
        .btn.red { background: var(--red); }
        .btn.gold { background: linear-gradient(135deg, #c99a3d, #b8892e); }
        .btn.outline { background: transparent; border: 2px solid var(--primary-light); color: var(--primary-light); box-shadow: none; }
        .btn.outline:hover { background: var(--primary-light); color: #fff; }

        .back-btn { height: 36px; padding: 0 14px; border: 2px solid rgba(0,63,70,0.08); border-radius: 14px; background: transparent; color: var(--text-muted); font-size: 12px; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; font-family: var(--font-family); }
        .back-btn:hover { border-color: var(--primary-light); color: var(--primary-light); }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-size: 12px; font-weight: 700; color: #4A6A78; margin-bottom: 4px; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px 14px; border: 2px solid rgba(0,63,70,0.08); border-radius: 8px; font-size: 14px; background: var(--bg); color: var(--text-primary); font-family: var(--font-family); outline: none; }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color: var(--primary-light); box-shadow: 0 0 0 4px rgba(0,107,115,0.06); }
        .form-group textarea { min-height: 50px; resize: vertical; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .grid-3 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
        .table-wrap { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; font-size: 11px; }
        .table th, .table td { padding: 8px 4px; border-bottom: 1px solid #e5ebeb; text-align: right; }
        .table th { color: var(--text-muted); font-weight: 500; font-size: 10px; }

        /* بطاقات الإيجاز */
        .briefing-entry {
            background: var(--bg-card);
            border-radius: var(--radius-md);
            border: 1px solid #e2ebeb;
            padding: 12px 14px;
            margin-bottom: 10px;
            transition: var(--transition-base);
        }
        .briefing-entry:hover {
            box-shadow: var(--shadow-md);
            border-color: rgba(0,63,70,0.06);
        }
        .briefing-entry .entry-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 6px;
        }
        .briefing-entry .entry-header .entry-type {
            font-weight: 800;
            font-size: 13px;
        }
        .briefing-entry .entry-header .entry-type.income { color: var(--green); }
        .briefing-entry .entry-header .entry-type.expense { color: var(--red); }
        .briefing-entry .entry-amount {
            font-size: 18px;
            font-weight: 900;
        }
        .briefing-entry .entry-amount.income { color: var(--green); }
        .briefing-entry .entry-amount.expense { color: var(--red); }
        .briefing-entry .entry-details {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 4px;
        }
        .briefing-entry .entry-actions {
            display: flex;
            gap: 6px;
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid #edf1f1;
        }

        /* عرض الإيجاز لـ HR */
        .hr-briefing-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 2px solid var(--primary-light);
            padding: 16px;
            margin-bottom: 12px;
            box-shadow: 0 4px 20px rgba(0,107,115,0.08);
        }
        .hr-briefing-card .hr-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid var(--primary-light);
            padding-bottom: 10px;
            margin-bottom: 12px;
        }
        .hr-briefing-card .hr-header .hr-branch {
            font-weight: 800;
            font-size: 16px;
            color: var(--primary);
        }
        .hr-briefing-card .hr-header .hr-date {
            font-size: 12px;
            color: var(--text-muted);
        }
        .hr-briefing-card .hr-net-profit {
            text-align: center;
            padding: 12px;
            background: rgba(16,185,129,0.06);
            border-radius: var(--radius-md);
            margin-bottom: 12px;
            border: 1px solid rgba(16,185,129,0.12);
        }
        .hr-briefing-card .hr-net-profit .label {
            font-size: 12px;
            color: var(--text-muted);
        }
        .hr-briefing-card .hr-net-profit .value {
            font-size: 24px;
            font-weight: 900;
            color: var(--green);
        }
        .hr-briefing-card .hr-entry {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #edf1f1;
        }
        .hr-briefing-card .hr-entry:last-child { border-bottom: 0; }
        .hr-briefing-card .hr-entry .hr-entry-type {
            font-weight: 700;
            font-size: 12px;
            padding: 2px 10px;
            border-radius: var(--radius-full);
        }
        .hr-briefing-card .hr-entry .hr-entry-type.income {
            background: rgba(16,185,129,0.12);
            color: var(--green);
        }
        .hr-briefing-card .hr-entry .hr-entry-type.expense {
            background: rgba(223,75,75,0.12);
            color: var(--red);
        }
        .hr-briefing-card .hr-entry .hr-entry-amount {
            font-weight: 800;
        }
        .hr-briefing-card .hr-entry .hr-entry-amount.income { color: var(--green); }
        .hr-briefing-card .hr-entry .hr-entry-amount.expense { color: var(--red); }
        .hr-briefing-card .hr-entry .hr-entry-note {
            font-size: 11px;
            color: var(--text-muted);
        }
        .hr-briefing-card .hr-footer {
            text-align: center;
            font-size: 11px;
            color: var(--text-muted);
            border-top: 1px solid #e2ebeb;
            padding-top: 10px;
            margin-top: 12px;
        }

        /* الشريط السفلي */
        .bottom-nav-minimal {
            position: fixed; bottom: 0; left: 0; right: 0;
            height: var(--nav-height); padding: 6px 8px 12px;
            background: var(--bg-card); border-top: 2px solid rgba(0,63,70,0.04);
            display: flex; justify-content: space-around; align-items: center; z-index: 200;
        }
        .bottom-nav-minimal .nav-item {
            flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center;
            padding: 4px 2px; min-height: 44px; border-radius: 8px; background: transparent;
            border: none; cursor: pointer; font-size: 9px; font-weight: 700; color: var(--text-muted);
            font-family: var(--font-family);
        }
        .bottom-nav-minimal .nav-item i { font-size: 18px; transition: 0.3s; }
        .bottom-nav-minimal .nav-item.active { color: var(--primary-light); background: rgba(0,107,115,0.04); }
        .bottom-nav-minimal .nav-item.active i { transform: translateY(-2px); }
        .bottom-nav-minimal .nav-item .nav-badge {
            position: absolute; top: 0; right: 50%; transform: translateX(50%);
            min-width: 16px; height: 16px; padding: 0 5px; border-radius: var(--radius-full);
            background: #EF4444; color: #fff; font-size: 8px; font-weight: 800;
            display: flex; align-items: center; justify-content: center;
        }

        /* القائمة الجانبية */
        .side-menu-overlay {
            position: fixed; inset: 0; z-index: 300;
            background: rgba(0,0,0,0.4); backdrop-filter: blur(4px);
            display: none; animation: fadeInModal 0.3s ease;
        }
        .side-menu-overlay.show { display: block; }
        @keyframes fadeInModal { 0% { opacity: 0; } 100% { opacity: 1; } }
        .side-menu {
            position: fixed; right: 0; top: 0; bottom: 0;
            width: 300px; max-width: 85%;
            background: linear-gradient(180deg, #003f46, #003138);
            color: #fff; padding: 24px 18px; overflow-y: auto;
            z-index: 301; transform: translateX(100%);
            transition: transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            box-shadow: -4px 0 40px rgba(0,0,0,0.2);
        }
        .side-menu.show { transform: translateX(0); }
        .side-menu .profile { display: flex; align-items: center; gap: 12px; margin-bottom: 22px; padding-bottom: 16px; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .side-menu .profile .avatar { width: 48px; height: 48px; border-radius: var(--radius-full); background: rgba(255,255,255,0.1); display: flex; align-items: center; justify-content: center; font-size: 24px; font-weight: 900; color: #fff; overflow: hidden; }
        .side-menu .profile .avatar img { width: 100%; height: 100%; object-fit: cover; }
        .side-menu .profile .info .name { font-size: 16px; font-weight: 800; }
        .side-menu .profile .info .title { font-size: 11px; opacity: 0.7; }
        .side-menu .menu-item { padding: 10px 6px; border-bottom: 1px solid rgba(255,255,255,0.06); font-size: 13px; display: flex; gap: 12px; align-items: center; cursor: pointer; border-radius: 8px; }
        .side-menu .menu-item:hover { background: rgba(255,255,255,0.05); padding-right: 12px; }
        .side-menu .menu-item i { width: 22px; color: var(--accent); }
        .side-menu .menu-item.logout { color: #ff8178; margin-top: 10px; border-bottom: 0; }
        .side-menu .menu-item .badge { font-size: 8px; padding: 1px 8px; background: #EF4444; color: #fff; border-radius: var(--radius-full); margin-right: auto; }
        .side-menu .close-btn { position: absolute; top: 16px; left: 16px; background: none; border: none; color: rgba(255,255,255,0.5); font-size: 24px; cursor: pointer; }

        /* التوست */
        .toast-container {
            position: fixed; top: 20px; left: 50%; transform: translateX(-50%);
            z-index: 1000; display: flex; flex-direction: column; gap: 10px;
            align-items: center; pointer-events: none; width: 100%; max-width: 400px; padding: 0 16px;
        }
        .toast {
            background: var(--bg-card); border-radius: var(--radius-lg); padding: 14px 18px;
            box-shadow: 0 12px 56px rgba(0,63,70,0.1); pointer-events: auto;
            max-width: 100%; width: 100%; font-family: var(--font-family);
            display: flex; align-items: flex-start; gap: 12px;
            opacity: 0; transform: translateY(-80px) scale(0.9);
            transition: all 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            font-weight: 800; font-size: 13px; cursor: pointer;
        }
        .toast.show { opacity: 1; transform: translateY(0) scale(1); }
        .toast::before { content: ''; position: absolute; top: 0; right: 0; width: 4px; height: 100%; border-radius: 0 4px 4px 0; }
        .toast.success::before { background: var(--green); }
        .toast.info::before { background: var(--primary-light); }
        .toast.warning::before { background: var(--orange); }
        .toast.error::before { background: var(--red); }
        .toast .toast-icon { width: 38px; height: 38px; border-radius: var(--radius-full); display: flex; align-items: center; justify-content: center; font-size: 18px; flex-shrink: 0; font-weight: 800; }
        .toast .toast-icon.success { background: rgba(21,148,71,0.12); color: var(--green); }
        .toast .toast-icon.info { background: rgba(0,107,115,0.12); color: var(--primary-light); }
        .toast .toast-icon.warning { background: rgba(217,140,26,0.12); color: var(--orange); }
        .toast .toast-icon.error { background: rgba(223,75,75,0.12); color: var(--red); }
        .toast .toast-content { flex: 1; min-width: 0; }
        .toast .toast-content .toast-title { font-size: 13px; font-weight: 800; color: var(--text-primary); margin-bottom: 2px; }
        .toast .toast-content .toast-message { font-size: 12px; font-weight: 400; color: var(--text-muted); line-height: 1.5; }

        /* دوائر الإحصاء الحقيقية للفرع */
        .stats-rings { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin: 12px 0; }
        .stat-ring-card { background: var(--bg-card); border-radius: var(--radius-md); padding: 14px 8px; text-align: center; box-shadow: 0 2px 10px rgba(0,63,70,0.04); }
        .stat-ring { width: 76px; height: 76px; border-radius: 50%; margin: 0 auto 8px; position: relative; display: flex; align-items: center; justify-content: center; background: conic-gradient(var(--ring-color, var(--primary-light)) calc(var(--pct, 0) * 1%), #e7eeed 0); }
        .stat-ring::before { content: ''; position: absolute; inset: 7px; background: var(--bg-card); border-radius: 50%; }
        .stat-ring .stat-ring-val { position: relative; z-index: 1; font-weight: 900; font-size: 15px; color: var(--text-primary); }
        .stat-ring-card .stat-ring-label { font-size: 11px; color: var(--text-muted); font-weight: 700; }

        @media (max-width: 480px) {
            :root { --header-height: 58px; --nav-height: 66px; }
            .form-row { grid-template-columns: 1fr; }
            .grid-2 { grid-template-columns: 1fr; }
        }

        /* واجهة اللابتوب/سطح المكتب: قائمة جانبية ثابتة بدل الشريط السفلي */
        @media (min-width: 1024px) {
            body { padding-bottom: 0; }
            .bottom-nav-minimal { display: none; }
            .side-menu-overlay { display: none !important; }
            .side-menu {
                transform: translateX(0) !important;
                top: var(--header-height);
                box-shadow: 2px 0 24px rgba(0,0,0,0.08);
            }
            .side-menu .close-btn { display: none; }
            .page-content { max-width: 1100px; margin: 0 300px 0 auto; padding: 24px 28px 40px; }
            .login-page { padding: 40px; }
        }
    </style>
</head>
<body>

    <!-- شاشة الترحيب -->
    <div class="welcome-screen" id="welcomeScreen">
        <div class="welcome-logo" id="welcomeLogo">
            <div class="logo-icon"><?= $welcomeCompanyLogo ? '<img src="' . htmlspecialchars($welcomeCompanyLogo, ENT_QUOTES, 'UTF-8') . '" alt="">' : '✥' ?></div>
            <h1><?= htmlspecialchars($welcomeCompanyName, ENT_QUOTES, 'UTF-8') ?></h1>
        </div>
        <div class="welcome-loader">
            <div class="loader-label">جاري التحميل...</div>
            <div class="loader-bar-wrapper"><div class="loader-bar" id="loaderBar"></div></div>
        </div>
    </div>

    <!-- شاشة تسجيل الدخول -->
    <div id="loginScreen" class="login-page hidden">
        <div class="login-card">
            <div class="login-logo">
                <div class="logo-icon">✥</div>
                <h2>مدير <span>الفرع</span></h2>
                <p>نظام إدارة فروع شركة الصوى للصرافة</p>
            </div>
            <form id="loginForm" onsubmit="handleLogin(event)">
                <div class="form-group">
                    <label>رقم الموظف</label>
                    <input type="text" id="loginEmployeeId" placeholder="أدخل رقم الموظف" required>
                </div>
                <div class="form-group">
                    <label>الرمز السري</label>
                    <input type="password" id="loginPassword" placeholder="••••••••" required>
                </div>
                <div class="login-error" id="loginError">بيانات الدخول غير صحيحة</div>
                <button type="submit" class="btn-login" id="loginBtn"><i class="fas fa-arrow-left"></i> تسجيل الدخول</button>
            </form>
            <div class="login-toggle">الرمز يتم تزويد المدير من <a href="#">الإدارة العليا</a></div>
        </div>
    </div>

    <!-- التطبيق الرئيسي -->
    <div id="appContainer" class="hidden">

        <!-- الهيدر -->
        <header class="header-glass">
            <div class="brand">
                <div class="logo" id="headerLogo">✥</div>
                <div class="name" id="headerCompanyName">شركة <span>الصوى</span></div>
                <span class="role-badge">مدير فرع</span>
            </div>
            <div class="actions">
                <button class="icon-btn" onclick="toggleSideMenu()"><i class="fas fa-bars"></i></button>
                <button class="icon-btn" style="position:relative;" onclick="navigateTo('notifications')">
                    <i class="fas fa-bell"></i>
                    <span id="notifBadge" style="display:none;position:absolute;top:2px;left:2px;background:#EF4444;color:#fff;font-size:9px;font-weight:800;min-width:15px;height:15px;border-radius:50%;align-items:center;justify-content:center;">0</span>
                </button>
            </div>
        </header>

        <div class="page-content">

            <!-- ===== الصفحة الرئيسية ===== -->
            <div id="page-home" class="page-screen">
                <div class="card" style="display:flex;align-items:center;gap:14px;">
                    <div id="homeManagerAvatar" style="width:56px;height:56px;border-radius:50%;background:var(--primary-gradient);display:flex;align-items:center;justify-content:center;color:#fff;font-size:28px;font-weight:900;overflow:hidden;">👤</div>
                    <div>
                        <div style="font-weight:800;font-size:17px;" id="homeManagerName">مدير الفرع</div>
                        <div class="muted" id="homeManagerRole">مدير فرع</div>
                        <div class="muted" id="homeManagerCode">رقم الموظف: —</div>
                    </div>
                </div>

                <div class="stats-rings">
                    <div class="stat-ring-card">
                        <div class="stat-ring" id="ringAttendance" style="--ring-color:var(--green);">
                            <span class="stat-ring-val" id="homeAttendanceCount">0/0</span>
                        </div>
                        <div class="stat-ring-label">الحضور اليوم</div>
                    </div>
                    <div class="stat-ring-card">
                        <div class="stat-ring" id="ringCommitment" style="--ring-color:var(--primary-light);">
                            <span class="stat-ring-val" id="homeCommitmentPct">0%</span>
                        </div>
                        <div class="stat-ring-label">الالتزام الشهري</div>
                    </div>
                    <div class="stat-ring-card">
                        <div class="stat-ring" id="ringRequests" style="--ring-color:var(--accent);">
                            <span class="stat-ring-val" id="homeRequestsCount">0</span>
                        </div>
                        <div class="stat-ring-label">طلبات جديدة</div>
                    </div>
                </div>

                <div class="grid-2">
                    <button class="btn light small" onclick="navigateTo('employees')"><i class="fas fa-users"></i> الموظفون</button>
                    <button class="btn light small" onclick="navigateTo('attendance')"><i class="fas fa-fingerprint"></i> البصمة</button>
                    <button class="btn light small" onclick="navigateTo('requests')"><i class="fas fa-file-pen"></i> الطلبات</button>
                    <button class="btn light small" onclick="navigateTo('briefing')"><i class="fas fa-chart-simple"></i> الإيجاز</button>
                    <button class="btn light small" onclick="navigateTo('delegation')"><i class="fas fa-user-check"></i> التفويضات</button>
                    <button class="btn light small" onclick="navigateTo('payroll')"><i class="fas fa-money-bill-wave"></i> الرواتب</button>
                    <button class="btn light small" onclick="navigateTo('reports')"><i class="fas fa-chart-bar"></i> التقارير</button>
                    <button class="btn light small" onclick="navigateTo('files')"><i class="fas fa-folder"></i> الملفات</button>
                </div>

                <div class="card">
                    <div class="section-title" style="margin-top:0;"><i class="fas fa-bell"></i> التنبيهات</div>
                    <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #edf1f1;"><span>🔴 3 طلبات سلفة جديدة</span><span class="badge danger">عاجل</span></div>
                    <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid #edf1f1;"><span>🟠 طلب إجازة بانتظار موافقتك</span><span class="badge wait">قيد المراجعة</span></div>
                    <div style="display:flex;justify-content:space-between;padding:8px 0;"><span>🟢 تحديث حضور موظف اليوم</span><span class="badge ok">تم</span></div>
                </div>
            </div>

            <!-- ==========================================================
            الموظفون
            ========================================================== -->
            <div id="page-employees" class="page-screen hidden">
                <div class="page-title"><h2><i class="fas fa-users"></i> الموظفون</h2><button onclick="navigateTo('home')" class="back-btn"><i class="fas fa-arrow-right"></i> رجوع</button></div>
                <button class="btn" onclick="openRequestModal('addEmployee')"><i class="fas fa-user-plus"></i> إضافة موظف</button>
                <div id="employeesList">
                    <div class="card">
                        <div class="flex-between"><div class="flex"><div style="width:44px;height:44px;border-radius:50%;background:var(--primary-gradient);display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px;">👨🏻</div><div><b>أحمد حسن علي</b><div class="muted">الكود: EMP-001</div></div></div><span class="badge ok">نشط</span></div>
                        <div class="grid-2" style="margin-top:8px;font-size:12px;">
                            <span><span class="muted">المسمى:</span> مسؤول خزينة</span>
                            <span><span class="muted">الراتب:</span> 1,200,000</span>
                            <span><span class="muted">الحضور:</span> 100%</span>
                            <span><span class="muted">التقييم:</span> 4.8 ★</span>
                        </div>
                    </div>
                    <div class="card">
                        <div class="flex-between"><div class="flex"><div style="width:44px;height:44px;border-radius:50%;background:var(--primary-gradient);display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px;">👩🏻</div><div><b>سارة محمد حسين</b><div class="muted">الكود: EMP-002</div></div></div><span class="badge ok">نشط</span></div>
                        <div class="grid-2" style="margin-top:8px;font-size:12px;">
                            <span><span class="muted">المسمى:</span> صراف</span>
                            <span><span class="muted">الراتب:</span> 900,000</span>
                            <span><span class="muted">الحضور:</span> 96%</span>
                            <span><span class="muted">التقييم:</span> 4.5 ★</span>
                        </div>
                    </div>
                    <div class="card">
                        <div class="flex-between"><div class="flex"><div style="width:44px;height:44px;border-radius:50%;background:var(--primary-gradient);display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px;">👨🏻</div><div><b>محمد باسم كريم</b><div class="muted">الكود: EMP-003</div></div></div><span class="badge ok">نشط</span></div>
                        <div class="grid-2" style="margin-top:8px;font-size:12px;">
                            <span><span class="muted">المسمى:</span> محاسب فرع</span>
                            <span><span class="muted">الراتب:</span> 1,500,000</span>
                            <span><span class="muted">الحضور:</span> 92%</span>
                            <span><span class="muted">التقييم:</span> 4.2 ★</span>
                        </div>
                    </div>
                    <div class="card">
                        <div class="flex-between"><div class="flex"><div style="width:44px;height:44px;border-radius:50%;background:var(--primary-gradient);display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px;">👩🏻</div><div><b>نور صباح أحمد</b><div class="muted">الكود: EMP-004</div></div></div><span class="badge danger">غير نشط</span></div>
                        <div class="grid-2" style="margin-top:8px;font-size:12px;">
                            <span><span class="muted">المسمى:</span> خدمة زبائن</span>
                            <span><span class="muted">الراتب:</span> 750,000</span>
                            <span><span class="muted">الحضور:</span> 76%</span>
                            <span><span class="muted">التقييم:</span> 3.8 ★</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ==========================================================
            البصمة والحضور
            ========================================================== -->
            <div id="page-attendance" class="page-screen hidden">
                <div class="page-title"><h2><i class="fas fa-fingerprint"></i> البصمة والحضور</h2><button onclick="navigateTo('home')" class="back-btn"><i class="fas fa-arrow-right"></i> رجوع</button></div>

                <div class="location-status inactive" id="locationStatus">
                    <span class="dot"></span>
                    <span id="locationStatusText">لم يتم تحديد موقع الفرع بعد</span>
                </div>

                <div class="card">
                    <h3>📍 تحديد موقع الفرع</h3>
                    <div class="form-row">
                        <div class="form-group"><label>خط العرض</label><input type="number" id="branchLat" value="33.3152" step="0.000001"></div>
                        <div class="form-group"><label>خط الطول</label><input type="number" id="branchLng" value="44.3661" step="0.000001"></div>
                    </div>
                    <div class="form-group"><label>نطاق السماح (متر)</label><input type="number" id="branchRadius" value="100" min="10"></div>
                    <div class="grid-2">
                        <button class="btn gold small" onclick="getCurrentLocation()"><i class="fas fa-crosshairs"></i> تحديد الموقع</button>
                        <button class="btn small" onclick="saveBranchLocation()"><i class="fas fa-save"></i> حفظ الموقع</button>
                    </div>
                </div>

                <div class="card">
                    <h3>تسجيل حضوري</h3>
                    <div class="grid-2">
                        <button class="btn green hidden" id="checkInBtn" onclick="recordManagerAttendance('in')"><i class="fas fa-sign-in-alt"></i> تسجيل دخول</button>
                        <button class="btn red hidden" id="checkOutBtn" onclick="recordManagerAttendance('out')"><i class="fas fa-sign-out-alt"></i> تسجيل انصراف</button>
                    </div>
                    <div class="muted mt-2" id="attendanceWindowNote" style="text-align:center;"></div>
                    <div id="managerAttendanceStatus" class="mt-2 text-center muted"></div>
                </div>

                <div class="card">
                    <h3>حضور اليوم - <span id="todayDate">15 مايو 2024</span></h3>
                    <div class="table-wrap">
                        <table class="table">
                            <tr><th>الموظف</th><th>المسمى</th><th>دخول</th><th>انصراف</th><th>الحالة</th></tr>
                            <tr><td>أحمد حسن</td><td>مسؤول خزينة</td><td>08:02</td><td>17:05</td><td><span class="badge ok">حاضر</span></td></tr>
                            <tr><td>سارة حسين</td><td>صراف</td><td>08:08</td><td>17:00</td><td><span class="badge wait">تأخير</span></td></tr>
                            <tr><td>محمد باسم</td><td>محاسب فرع</td><td>07:58</td><td>17:10</td><td><span class="badge ok">حاضر</span></td></tr>
                            <tr><td>نور أحمد</td><td>خدمة زبائن</td><td>-</td><td>-</td><td><span class="badge danger">غائب</span></td></tr>
                        </table>
                    </div>
                    <button class="btn small light mt-2" onclick="saveAttendanceReport()"><i class="fas fa-save"></i> حفظ تقرير الحضور</button>
                </div>

                <div class="card">
                    <h3>تسجيل حضور يدوي</h3>
                    <div class="form-group"><label>اختر الموظف</label><select id="manualEmployeeSelect"><option value="1">أحمد حسن</option><option value="2">سارة حسين</option><option value="3">محمد باسم</option><option value="4">نور أحمد</option></select></div>
                    <div class="grid-2"><button class="btn green small" onclick="manualAttendance('in')"><i class="fas fa-sign-in-alt"></i> دخول</button><button class="btn red small" onclick="manualAttendance('out')"><i class="fas fa-sign-out-alt"></i> انصراف</button></div>
                    <div class="form-group mt-2"><label>ملاحظات</label><input type="text" id="manualNote" placeholder="سبب التأخير أو الملاحظة"></div>
                </div>
            </div>

            <!-- ==========================================================
            طلبات الموظفين
            ========================================================== -->
            <div id="page-requests" class="page-screen hidden">
                <div class="page-title"><h2><i class="fas fa-file-pen"></i> طلبات الموظفين</h2><button onclick="navigateTo('home')" class="back-btn"><i class="fas fa-arrow-right"></i> رجوع</button></div>
                <div class="card"><div class="flex-between"><b>📅 طلب إجازة</b><span class="badge wait">بانتظار موافقتك</span></div><p class="muted">أحمد محمد حسن • 3 أيام (16-18 مايو)</p><div class="flex gap-2 mt-2"><button class="btn green small" onclick="showToast('✅ تم الموافقة','تمت الموافقة على طلب الإجازة','success')">موافقة</button><button class="btn red small" onclick="showToast('❌ تم الرفض','تم رفض طلب الإجازة','error')">رفض</button></div></div>
                <div class="card"><div class="flex-between"><b>💰 طلب سلفة</b><span class="badge wait">بانتظار موافقتك</span></div><p class="muted">أحمد حسن علي • 1,000,000 دينار</p><div class="flex gap-2 mt-2"><button class="btn green small" onclick="showToast('✅ تم الموافقة','تمت الموافقة على طلب السلفة','success')">موافقة</button><button class="btn red small" onclick="showToast('❌ تم الرفض','تم رفض طلب السلفة','error')">رفض</button></div></div>
                <div class="card"><div class="flex-between"><b>🛒 طلب مشتريات</b><span class="badge ok">تمت الموافقة</span></div><p class="muted">محمد باسم كريم • 2,500,000 دينار</p></div>
                <div class="card"><div class="flex-between"><b>📅 طلب إجازة</b><span class="badge danger">مرفوض</span></div><p class="muted">نور صباح أحمد • 5 أيام</p></div>
            </div>

            <!-- ==========================================================
            التفويضات
            ========================================================== -->
            <div id="page-delegation" class="page-screen hidden">
                <div class="page-title"><h2><i class="fas fa-user-check"></i> التفويضات</h2><button onclick="navigateTo('home')" class="back-btn"><i class="fas fa-arrow-right"></i> رجوع</button></div>

                <div class="card">
                    <div class="flex-between"><b>✍ تفويض كتابة الإيجاز</b><span class="badge ok" id="delegationStatus">فعال</span></div>
                    <p class="muted">تفويض موظف لكتابة الإيجاز نيابة عن المدير</p>
                    <div class="form-group"><label>الموظف المفوض</label><select id="delegateEmployee"><option value="أحمد حسن">أحمد حسن</option><option value="سارة حسين">سارة حسين</option><option value="محمد باسم">محمد باسم</option></select></div>
                    <div class="form-row"><div class="form-group"><label>تاريخ البداية</label><input type="date" id="delegateStart" value="2024-05-16"></div><div class="form-group"><label>تاريخ النهاية</label><input type="date" id="delegateEnd" value="2024-05-20"></div></div>
                    <button class="btn small" onclick="saveDelegation()"><i class="fas fa-save"></i> حفظ التفويض</button>
                    <button class="btn small light mt-2" onclick="cancelDelegation()"><i class="fas fa-times"></i> إلغاء التفويض</button>
                </div>

                <div class="card" style="background:rgba(16,185,129,0.04);border-color:rgba(16,185,129,0.12);">
                    <h3>حالة التفويض</h3>
                    <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #edf1f1;"><span>الموظف</span><b id="delegateName">أحمد حسن</b></div>
                    <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid #edf1f1;"><span>المدة</span><b id="delegatePeriod">16/05/2024 - 20/05/2024</b></div>
                    <div style="display:flex;justify-content:space-between;padding:6px 0;"><span>الحالة</span><b style="color:var(--green);" id="delegateStatusText">✅ فعال</b></div>
                </div>
            </div>

            <!-- ==========================================================
            الرواتب
            ========================================================== -->
            <div id="page-payroll" class="page-screen hidden">
                <div class="page-title"><h2><i class="fas fa-money-bill-wave"></i> الرواتب</h2><button onclick="navigateTo('home')" class="back-btn"><i class="fas fa-arrow-right"></i> رجوع</button></div>

                <div class="card">
                    <h3>تسليم الرواتب - <span id="payrollMonthDisplay">الشهر الحالي</span></h3>
                    <p class="muted">يمكن تسليم رواتب الشهر الحالي فقط، ولا يمكن تسليم راتب متأخر لشهر سابق.</p>
                </div>

                <div id="payrollList">
                    <div class="payroll-card" style="background:var(--bg-card);border-radius:var(--radius-md);border:1px solid #e2ebeb;padding:12px 14px;margin-bottom:8px;">
                        <div class="payroll-header" style="display:flex;align-items:center;justify-content:space-between;">
                            <span style="font-weight:800;font-size:14px;">👨🏻 أحمد حسن علي</span>
                            <span class="badge ok">تم التسليم</span>
                        </div>
                        <div class="payroll-details" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;margin-top:6px;font-size:12px;">
                            <div class="detail-item" style="text-align:center;padding:4px;background:rgba(0,107,115,0.03);border-radius:6px;"><span class="value" style="font-weight:700;">1,200,000</span><div class="label" style="font-size:9px;color:var(--text-muted);">الراتب</div></div>
                            <div class="detail-item" style="text-align:center;padding:4px;background:rgba(0,107,115,0.03);border-radius:6px;"><span class="value" style="font-weight:700;">0</span><div class="label" style="font-size:9px;color:var(--text-muted);">السلف</div></div>
                            <div class="detail-item" style="text-align:center;padding:4px;background:rgba(0,107,115,0.03);border-radius:6px;"><span class="value" style="font-weight:700;color:var(--green);">1,200,000</span><div class="label" style="font-size:9px;color:var(--text-muted);">المستحق</div></div>
                        </div>
                        <div class="payroll-actions" style="display:flex;gap:6px;margin-top:8px;">
                            <button class="btn green small" onclick="paySalary(1)"><i class="fas fa-check"></i> تسليم</button>
                            <button class="btn light small" onclick="showToast('📄 تقرير الراتب','تم عرض تقرير الراتب','info')"><i class="fas fa-file"></i> تقرير</button>
                        </div>
                    </div>

                    <div class="payroll-card" style="background:var(--bg-card);border-radius:var(--radius-md);border:1px solid #e2ebeb;padding:12px 14px;margin-bottom:8px;">
                        <div class="payroll-header" style="display:flex;align-items:center;justify-content:space-between;">
                            <span style="font-weight:800;font-size:14px;">👩🏻 سارة محمد حسين</span>
                            <span class="badge wait">قيد الانتظار</span>
                        </div>
                        <div class="payroll-details" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;margin-top:6px;font-size:12px;">
                            <div class="detail-item" style="text-align:center;padding:4px;background:rgba(0,107,115,0.03);border-radius:6px;"><span class="value" style="font-weight:700;">900,000</span><div class="label" style="font-size:9px;color:var(--text-muted);">الراتب</div></div>
                            <div class="detail-item" style="text-align:center;padding:4px;background:rgba(0,107,115,0.03);border-radius:6px;"><span class="value" style="font-weight:700;color:var(--red);">200,000</span><div class="label" style="font-size:9px;color:var(--text-muted);">السلف</div></div>
                            <div class="detail-item" style="text-align:center;padding:4px;background:rgba(0,107,115,0.03);border-radius:6px;"><span class="value" style="font-weight:700;color:var(--green);">700,000</span><div class="label" style="font-size:9px;color:var(--text-muted);">المستحق</div></div>
                        </div>
                        <div class="payroll-actions" style="display:flex;gap:6px;margin-top:8px;">
                            <button class="btn green small" onclick="paySalary(2)"><i class="fas fa-check"></i> تسليم</button>
                            <button class="btn light small" onclick="showToast('📄 تقرير الراتب','تم عرض تقرير الراتب','info')"><i class="fas fa-file"></i> تقرير</button>
                        </div>
                    </div>

                    <div class="payroll-card" style="background:var(--bg-card);border-radius:var(--radius-md);border:1px solid #e2ebeb;padding:12px 14px;margin-bottom:8px;">
                        <div class="payroll-header" style="display:flex;align-items:center;justify-content:space-between;">
                            <span style="font-weight:800;font-size:14px;">👨🏻 محمد باسم كريم</span>
                            <span class="badge ok">تم التسليم</span>
                        </div>
                        <div class="payroll-details" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;margin-top:6px;font-size:12px;">
                            <div class="detail-item" style="text-align:center;padding:4px;background:rgba(0,107,115,0.03);border-radius:6px;"><span class="value" style="font-weight:700;">1,500,000</span><div class="label" style="font-size:9px;color:var(--text-muted);">الراتب</div></div>
                            <div class="detail-item" style="text-align:center;padding:4px;background:rgba(0,107,115,0.03);border-radius:6px;"><span class="value" style="font-weight:700;color:var(--red);">500,000</span><div class="label" style="font-size:9px;color:var(--text-muted);">السلف</div></div>
                            <div class="detail-item" style="text-align:center;padding:4px;background:rgba(0,107,115,0.03);border-radius:6px;"><span class="value" style="font-weight:700;color:var(--green);">1,000,000</span><div class="label" style="font-size:9px;color:var(--text-muted);">المستحق</div></div>
                        </div>
                        <div class="payroll-actions" style="display:flex;gap:6px;margin-top:8px;">
                            <button class="btn green small" onclick="paySalary(3)"><i class="fas fa-check"></i> تسليم</button>
                            <button class="btn light small" onclick="showToast('📄 تقرير الراتب','تم عرض تقرير الراتب','info')"><i class="fas fa-file"></i> تقرير</button>
                        </div>
                    </div>

                    <div class="payroll-card" style="background:var(--bg-card);border-radius:var(--radius-md);border:1px solid #e2ebeb;padding:12px 14px;margin-bottom:8px;">
                        <div class="payroll-header" style="display:flex;align-items:center;justify-content:space-between;">
                            <span style="font-weight:800;font-size:14px;">👩🏻 نور صباح أحمد</span>
                            <span class="badge wait">قيد الانتظار</span>
                        </div>
                        <div class="payroll-details" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;margin-top:6px;font-size:12px;">
                            <div class="detail-item" style="text-align:center;padding:4px;background:rgba(0,107,115,0.03);border-radius:6px;"><span class="value" style="font-weight:700;">750,000</span><div class="label" style="font-size:9px;color:var(--text-muted);">الراتب</div></div>
                            <div class="detail-item" style="text-align:center;padding:4px;background:rgba(0,107,115,0.03);border-radius:6px;"><span class="value" style="font-weight:700;color:var(--red);">100,000</span><div class="label" style="font-size:9px;color:var(--text-muted);">السلف</div></div>
                            <div class="detail-item" style="text-align:center;padding:4px;background:rgba(0,107,115,0.03);border-radius:6px;"><span class="value" style="font-weight:700;color:var(--green);">650,000</span><div class="label" style="font-size:9px;color:var(--text-muted);">المستحق</div></div>
                        </div>
                        <div class="payroll-actions" style="display:flex;gap:6px;margin-top:8px;">
                            <button class="btn green small" onclick="paySalary(4)"><i class="fas fa-check"></i> تسليم</button>
                            <button class="btn light small" onclick="showToast('📄 تقرير الراتب','تم عرض تقرير الراتب','info')"><i class="fas fa-file"></i> تقرير</button>
                        </div>
                    </div>
                </div>

                <button class="btn small light" onclick="savePayrollReport()"><i class="fas fa-save"></i> حفظ تقرير الرواتب</button>
            </div>

            <!-- ==========================================================
            الإيجاز اليومي - النظام المطور
            ========================================================== -->
            <div id="page-briefing" class="page-screen hidden">
                <div class="page-title">
                    <h2><i class="fas fa-chart-simple"></i> الإيجاز اليومي</h2>
                    <button onclick="navigateTo('home')" class="back-btn"><i class="fas fa-arrow-right"></i> رجوع</button>
                </div>

                <!-- معلومات أساسية -->
                <div class="card">
                    <div class="flex-between"><span class="muted">التاريخ</span><b id="briefDate">15 مايو 2024</b></div>
                    <div class="flex-between"><span class="muted">الفرع</span><b>المنصور</b></div>
                    <div class="flex-between"><span class="muted">المدير</span><b>أحمد محمد علي</b></div>
                    <div class="flex-between" style="border-top:1px solid #e2ebeb;padding-top:8px;margin-top:8px;">
                        <span class="muted">صلاحية الكتابة</span>
                        <span style="font-weight:800;color:var(--green);" id="writePermission">✅ لديك صلاحية</span>
                    </div>
                </div>

                <!-- ===== حالة اعتماد الإيجاز ===== -->
                <div class="card">
                    <div class="flex-between"><span class="muted">حالة إيجاز اليوم</span><b id="briefStatusText">لم يُنشر بعد</b></div>
                    <div class="muted" id="briefStatusNote" style="display:none;"></div>
                </div>

                <!-- ===== صافي ربح اليوم السابق ===== -->
                <div class="card" style="background:rgba(16,185,129,0.04);border-color:rgba(16,185,129,0.12);">
                    <div class="flex-between">
                        <span style="font-weight:800;"><i class="fas fa-coins"></i> صافي ربح اليوم السابق</span>
                        <span style="font-size:22px;font-weight:900;color:var(--green);" id="previousDayProfit">+2,450,000 د.ع</span>
                    </div>
                    <div class="muted">تم حساب الربح بناءً على إيرادات ومصروفات الأمس</div>
                </div>

                <!-- ===== عدد المسافرين ===== -->
                <div class="card">
                    <div class="form-group"><label>عدد المسافرين اليوم</label><input type="number" id="travelersCount" min="0" placeholder="0"></div>
                </div>

                <!-- ===== إضافة قيد جديد ===== -->
                <div class="card">
                    <h3>إضافة قيد جديد</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label>نوع القيد <span style="color:var(--red);">*</span></label>
                            <select id="entryType">
                                <option value="income">💰 إيراد (دخل)</option>
                                <option value="expense">💸 صرف (مصروف)</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>المبلغ <span style="color:var(--red);">*</span></label>
                            <input type="number" id="entryAmount" placeholder="أدخل المبلغ" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>البيان / الملاحظات</label>
                        <input type="text" id="entryNote" placeholder="مثال: عمولة تحويل، إيجار، رواتب...">
                    </div>
                    <div class="form-group">
                        <label>رفع ملف (اختياري)</label>
                        <input type="file" id="entryFile" accept=".pdf,.doc,.docx,.jpg,.png">
                    </div>
                    <button class="btn gold" onclick="addBriefingEntry()"><i class="fas fa-plus"></i> إضافة القيد</button>
                </div>

                <!-- ===== قائمة القيود المضافة ===== -->
                <div class="section-title"><i class="fas fa-list"></i> قائمة القيود</div>
                <div id="briefingEntries">
                    <div class="card" style="text-align:center;padding:30px 20px;color:var(--text-muted);">جاري التحميل...</div>
                </div>

                <!-- ===== ملخص الإيجاز ===== -->
                <div class="card" style="background:var(--primary-gradient);color:#fff;">
                    <h3 style="color:#fff;">ملخص الإيجاز اليومي</h3>
                    <div class="calc-row" style="display:flex;justify-content:space-between;padding:6px 0;border-color:rgba(255,255,255,0.1);border-bottom:1px solid rgba(255,255,255,0.1);">
                        <span style="color:rgba(255,255,255,0.7);">صافي ربح الأمس</span>
                        <span style="color:#fff;font-weight:700;" id="summaryPrevious">+2,450,000</span>
                    </div>
                    <div class="calc-row" style="display:flex;justify-content:space-between;padding:6px 0;border-color:rgba(255,255,255,0.1);border-bottom:1px solid rgba(255,255,255,0.1);">
                        <span style="color:rgba(255,255,255,0.7);">إجمالي الإيرادات</span>
                        <span style="color:#fff;font-weight:700;" id="summaryIncome">+2,000,000</span>
                    </div>
                    <div class="calc-row" style="display:flex;justify-content:space-between;padding:6px 0;border-color:rgba(255,255,255,0.1);border-bottom:1px solid rgba(255,255,255,0.1);">
                        <span style="color:rgba(255,255,255,0.7);">إجمالي المصروفات</span>
                        <span style="color:#fff;font-weight:700;" id="summaryExpense">-1,500,000</span>
                    </div>
                    <div class="calc-total" style="border-color:rgba(255,255,255,0.3);color:#fff;font-size:18px;text-align:center;padding-top:10px;border-top:2px solid rgba(255,255,255,0.3);margin-top:8px;">
                        صافي ربح اليوم
                        <div style="font-size:26px;color:var(--accent);" id="summaryTotal">+2,950,000 د.ع</div>
                    </div>
                </div>

                <!-- ===== أزرار النشر ===== -->
                <div class="grid-2">
                    <button class="btn gold" onclick="publishBriefing()"><i class="fas fa-paper-plane"></i> نشر الإيجاز لـ HR</button>
                </div>

                <!-- ===== عرض الإيجاز لـ HR ===== -->
                <div class="section-title"><i class="fas fa-building"></i> عرض الإيجاز لـ HR</div>
                <div id="hrBriefingDisplay">
                    <div class="hr-briefing-card">
                        <div class="hr-header">
                            <span class="hr-branch">🏢 فرع المنصور</span>
                            <span class="hr-date">15 مايو 2024</span>
                        </div>
                        <div class="hr-net-profit">
                            <div class="label">صافي ربح اليوم</div>
                            <div class="value">+2,950,000 د.ع</div>
                        </div>
                        <div class="hr-entry">
                            <span><span class="hr-entry-type income">💰 إيراد</span> عمولة خدمة زبائن</span>
                            <span class="hr-entry-amount income">+2,000,000</span>
                        </div>
                        <div class="hr-entry">
                            <span><span class="hr-entry-type expense">💸 صرف</span> إيجار الفرع</span>
                            <span class="hr-entry-amount expense">-1,500,000</span>
                        </div>
                        <div class="hr-footer">
                            تم إنشاء هذا التقرير بواسطة نظام إدارة فروع شركة الصوى للصرافة
                        </div>
                    </div>
                </div>
            </div>

            <!-- ==========================================================
            التقارير
            ========================================================== -->
            <div id="page-reports" class="page-screen hidden">
                <div class="page-title"><h2><i class="fas fa-chart-bar"></i> التقارير</h2><button onclick="navigateTo('home')" class="back-btn"><i class="fas fa-arrow-right"></i> رجوع</button></div>

                <div class="card">
                    <h3>إنشاء تقرير</h3>
                    <div class="form-group"><label>نوع التقرير</label>
                        <select id="reportType">
                            <option value="all">تقرير شامل</option>
                            <option value="attendance">تقرير الحضور</option>
                            <option value="salaries">تقرير الرواتب</option>
                            <option value="briefing">تقرير الإيجاز اليومي</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label>من تاريخ</label><input type="date" id="reportFrom"></div>
                        <div class="form-group"><label>إلى تاريخ</label><input type="date" id="reportTo"></div>
                    </div>
                    <div class="grid-2">
                        <button class="btn" onclick="generateReport()"><i class="fas fa-file-lines"></i> إنشاء التقرير</button>
                        <button class="btn gold" onclick="downloadReport()"><i class="fas fa-download"></i> تحميل CSV</button>
                    </div>
                </div>

                <div id="reportResult"></div>
            </div>

            <!-- ==========================================================
            الإشعارات
            ========================================================== -->
            <div id="page-notifications" class="page-screen hidden">
                <div class="page-title"><h2><i class="fas fa-bell"></i> الإشعارات</h2><button onclick="navigateTo('home')" class="back-btn"><i class="fas fa-arrow-right"></i> رجوع</button></div>
                <button class="btn small light" onclick="markAllNotifsRead()"><i class="fas fa-check-double"></i> تعليم الكل كمقروء</button>
                <div id="notifList" style="margin-top:10px;"></div>
            </div>

            <!-- ==========================================================
            ملفي — بيانات مدير الفرع الشخصية: حضوره وراتبه وخصوماته
            ========================================================== -->
            <div id="page-myProfile" class="page-screen hidden">
                <div class="page-title"><h2><i class="fas fa-user-circle"></i> ملفي الوظيفي</h2><button onclick="navigateTo('home')" class="back-btn"><i class="fas fa-arrow-right"></i> رجوع</button></div>

                <div class="card">
                    <div class="table-wrap">
                        <table class="table">
                            <tr><th>الاسم</th><td id="mpName">...</td></tr>
                            <tr><th>المنصب</th><td>مدير فرع</td></tr>
                            <tr><th>الفرع</th><td id="mpBranch">...</td></tr>
                            <tr><th>رقم التوظيف</th><td id="mpCode">...</td></tr>
                            <tr><th>الراتب الاسمي</th><td id="mpSalary">...</td></tr>
                        </table>
                    </div>
                </div>

                <div class="section-title"><i class="fas fa-money-bill-wave"></i> راتب الشهر الحالي</div>
                <div class="card">
                    <div class="table-wrap">
                        <table class="table">
                            <tr><th>البيان</th><th>المبلغ</th></tr>
                            <tr><td>الراتب الأساسي</td><td id="mpBase">0</td></tr>
                            <tr><td>الخصومات</td><td id="mpDeduction" style="color:var(--red);">- 0</td></tr>
                            <tr><td>المكافآت</td><td id="mpBonus" style="color:var(--green);">+ 0</td></tr>
                            <tr style="border-top:2px solid var(--primary);"><td><b>الصافي</b></td><td><b id="mpNet" style="color:var(--green);">0</b></td></tr>
                        </table>
                    </div>
                    <div class="muted" id="mpStatus" style="margin-top:6px;"></div>
                </div>

                <div class="section-title"><i class="fas fa-calendar-days"></i> سجل حضوري الشهري</div>
                <div class="card" style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;">
                    <button onclick="mpAttendanceNav(-1)" style="background:none;border:none;font-size:18px;color:var(--primary-light);cursor:pointer;padding:6px 10px;"><i class="fas fa-chevron-right"></i></button>
                    <b id="mpMonthLabel" style="font-size:14px;">...</b>
                    <button onclick="mpAttendanceNav(1)" id="mpNextBtn" style="background:none;border:none;font-size:18px;color:var(--primary-light);cursor:pointer;padding:6px 10px;"><i class="fas fa-chevron-left"></i></button>
                </div>
                <div class="card" id="mpMonthSummary" style="display:flex;justify-content:space-around;text-align:center;padding:14px;"></div>
                <div class="card">
                    <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:6px;margin-bottom:8px;font-size:10.5px;color:var(--text-muted);font-weight:700;text-align:center;">
                        <span>أحد</span><span>إثنين</span><span>ثلاثاء</span><span>أربعاء</span><span>خميس</span><span>جمعة</span><span>سبت</span>
                    </div>
                    <div id="mpCalendarGrid" style="display:grid;grid-template-columns:repeat(7,1fr);gap:6px;"></div>
                </div>
            </div>

            <!-- ==========================================================
            الملفات
            ========================================================== -->
            <div id="page-files" class="page-screen hidden">
                <div class="page-title"><h2><i class="fas fa-folder"></i> الملفات</h2><button onclick="navigateTo('home')" class="back-btn"><i class="fas fa-arrow-right"></i> رجوع</button></div>

                <div class="section-title"><i class="fas fa-chart-simple"></i> ملفات الإيجاز</div>
                <div id="briefingFiles">
                    <div class="file-manager" style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:var(--bg-card);border-radius:var(--radius-md);border:1px solid #e2ebeb;margin-bottom:8px;">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div style="width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;background:rgba(0,107,115,0.06);color:var(--primary-light);"><i class="fas fa-file-image"></i></div>
                            <div><div style="font-weight:700;font-size:13px;">إيجاز_15_05_2024.png</div><div style="font-size:10px;color:var(--text-muted);">15/05/2024 • 620 كيلوبايت</div></div>
                        </div>
                        <div style="display:flex;gap:6px;">
                            <button class="btn-view" style="padding:4px 10px;border:none;border-radius:8px;font-size:10px;font-weight:700;cursor:pointer;background:rgba(0,107,115,0.08);color:var(--primary-light);" onclick="showToast('👁️ معاينة','جاري فتح الملف...','info')"><i class="fas fa-eye"></i></button>
                            <button class="btn-download" style="padding:4px 10px;border:none;border-radius:8px;font-size:10px;font-weight:700;cursor:pointer;background:rgba(21,148,71,0.08);color:var(--green);" onclick="showToast('📥 تحميل','جاري تحميل الملف...','info')"><i class="fas fa-download"></i></button>
                            <button class="btn-delete" style="padding:4px 10px;border:none;border-radius:8px;font-size:10px;font-weight:700;cursor:pointer;background:rgba(223,75,75,0.08);color:var(--red);" onclick="showToast('🗑️ حذف','تم حذف الملف','warning')"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>
                </div>

                <div class="section-title"><i class="fas fa-calendar-check"></i> ملفات الحضور</div>
                <div id="attendanceFiles">
                    <div class="file-manager" style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:var(--bg-card);border-radius:var(--radius-md);border:1px solid #e2ebeb;margin-bottom:8px;">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div style="width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;background:rgba(0,107,115,0.06);color:var(--primary-light);"><i class="fas fa-file-image"></i></div>
                            <div><div style="font-weight:700;font-size:13px;">حضور_15_05_2024.png</div><div style="font-size:10px;color:var(--text-muted);">15/05/2024 • 450 كيلوبايت</div></div>
                        </div>
                        <div style="display:flex;gap:6px;">
                            <button class="btn-view" style="padding:4px 10px;border:none;border-radius:8px;font-size:10px;font-weight:700;cursor:pointer;background:rgba(0,107,115,0.08);color:var(--primary-light);" onclick="showToast('👁️ معاينة','جاري فتح الملف...','info')"><i class="fas fa-eye"></i></button>
                            <button class="btn-download" style="padding:4px 10px;border:none;border-radius:8px;font-size:10px;font-weight:700;cursor:pointer;background:rgba(21,148,71,0.08);color:var(--green);" onclick="showToast('📥 تحميل','جاري تحميل الملف...','info')"><i class="fas fa-download"></i></button>
                            <button class="btn-delete" style="padding:4px 10px;border:none;border-radius:8px;font-size:10px;font-weight:700;cursor:pointer;background:rgba(223,75,75,0.08);color:var(--red);" onclick="showToast('🗑️ حذف','تم حذف الملف','warning')"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>
                </div>

                <div class="section-title"><i class="fas fa-money-bill-wave"></i> ملفات الرواتب</div>
                <div id="payrollFiles">
                    <div class="file-manager" style="display:flex;align-items:center;justify-content:space-between;padding:10px 14px;background:var(--bg-card);border-radius:var(--radius-md);border:1px solid #e2ebeb;margin-bottom:8px;">
                        <div style="display:flex;align-items:center;gap:10px;">
                            <div style="width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;background:rgba(0,107,115,0.06);color:var(--primary-light);"><i class="fas fa-file-image"></i></div>
                            <div><div style="font-weight:700;font-size:13px;">رواتب_مايو_2024.png</div><div style="font-size:10px;color:var(--text-muted);">15/05/2024 • 520 كيلوبايت</div></div>
                        </div>
                        <div style="display:flex;gap:6px;">
                            <button class="btn-view" style="padding:4px 10px;border:none;border-radius:8px;font-size:10px;font-weight:700;cursor:pointer;background:rgba(0,107,115,0.08);color:var(--primary-light);" onclick="showToast('👁️ معاينة','جاري فتح الملف...','info')"><i class="fas fa-eye"></i></button>
                            <button class="btn-download" style="padding:4px 10px;border:none;border-radius:8px;font-size:10px;font-weight:700;cursor:pointer;background:rgba(21,148,71,0.08);color:var(--green);" onclick="showToast('📥 تحميل','جاري تحميل الملف...','info')"><i class="fas fa-download"></i></button>
                            <button class="btn-delete" style="padding:4px 10px;border:none;border-radius:8px;font-size:10px;font-weight:700;cursor:pointer;background:rgba(223,75,75,0.08);color:var(--red);" onclick="showToast('🗑️ حذف','تم حذف الملف','warning')"><i class="fas fa-trash"></i></button>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- الشريط السفلي -->
        <nav class="bottom-nav-minimal">
            <button class="nav-item active" id="nav-home" onclick="navigateTo('home')"><i class="fas fa-home"></i><span>الرئيسية</span></button>
            <button class="nav-item" id="nav-employees" onclick="navigateTo('employees')"><i class="fas fa-users"></i><span>الموظفون</span></button>
            <button class="nav-item" id="nav-attendance" onclick="navigateTo('attendance')"><i class="fas fa-fingerprint"></i><span>البصمة</span></button>
            <button class="nav-item" id="nav-briefing" onclick="navigateTo('briefing')"><i class="fas fa-chart-simple"></i><span>الإيجاز</span></button>
            <button class="nav-item" id="nav-more" onclick="toggleSideMenu()"><i class="fas fa-bars"></i><span>المزيد</span></button>
        </nav>

        <!-- القائمة الجانبية -->
        <div class="side-menu-overlay" id="sideMenuOverlay" onclick="toggleSideMenu()"></div>
        <div class="side-menu" id="sideMenu">
            <button class="close-btn" onclick="toggleSideMenu()"><i class="fas fa-times"></i></button>
            <div class="profile"><div class="avatar" id="sideMenuAvatar">👤</div><div class="info"><div class="name" id="sideMenuName">مدير الفرع</div><div class="title" id="sideMenuTitle">مدير فرع</div></div></div>
            <div class="menu-item" onclick="navigateTo('home');toggleSideMenu();"><i class="fas fa-home"></i> الرئيسية</div>
            <div class="menu-item" onclick="navigateTo('myProfile');toggleSideMenu();"><i class="fas fa-user-circle"></i> ملفي</div>
            <div class="menu-item" onclick="navigateTo('employees');toggleSideMenu();"><i class="fas fa-users"></i> الموظفون</div>
            <div class="menu-item" onclick="navigateTo('attendance');toggleSideMenu();"><i class="fas fa-fingerprint"></i> البصمة والحضور</div>
            <div class="menu-item" onclick="navigateTo('requests');toggleSideMenu();"><i class="fas fa-file-pen"></i> طلبات الموظفين</div>
            <div class="menu-item" onclick="navigateTo('delegation');toggleSideMenu();"><i class="fas fa-user-check"></i> التفويضات</div>
            <div class="menu-item" onclick="navigateTo('briefing');toggleSideMenu();"><i class="fas fa-chart-simple"></i> الإيجاز اليومي</div>
            <div class="menu-item" onclick="navigateTo('payroll');toggleSideMenu();"><i class="fas fa-money-bill-wave"></i> الرواتب</div>
            <div class="menu-item" onclick="navigateTo('reports');toggleSideMenu();"><i class="fas fa-chart-bar"></i> التقارير</div>
            <div class="menu-item" onclick="navigateTo('notifications');toggleSideMenu();"><i class="fas fa-bell"></i> الإشعارات</div>
            <div class="menu-item" onclick="navigateTo('files');toggleSideMenu();"><i class="fas fa-folder"></i> الملفات</div>
            <div class="menu-item logout" onclick="handleLogout();toggleSideMenu();"><i class="fas fa-sign-out-alt"></i> تسجيل الخروج</div>
        </div>

    </div>

    <!-- نافذة الطلب المنبثقة -->
    <div class="request-modal-overlay" id="requestModal" style="display:none;position:fixed;inset:0;z-index:500;background:rgba(0,0,0,0.5);backdrop-filter:blur(8px);align-items:center;justify-content:center;padding:20px;">
        <div style="background:#fff;border-radius:20px;max-width:480px;width:100%;max-height:90vh;overflow-y:auto;padding:0;box-shadow:0 12px 56px rgba(0,63,70,0.1);">
            <div style="padding:16px 20px;border-bottom:1px solid #e2ebeb;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;background:#fff;z-index:1;border-radius:20px 20px 0 0;">
                <h3 id="requestModalTitle" style="font-size:17px;font-weight:800;display:flex;align-items:center;gap:10px;"><i class="fas fa-user-plus" style="color:var(--primary-light);"></i> إضافة موظف</h3>
                <button class="close-btn" onclick="closeRequestModal()" style="width:34px;height:34px;border:none;border-radius:50%;background:rgba(0,63,70,0.06);color:var(--text-muted);cursor:pointer;font-size:18px;">✕</button>
            </div>
            <div style="padding:20px;">
                <form id="requestForm" onsubmit="submitRequestForm(event)">
                    <div id="requestFields">
                        <div class="form-group"><label>الاسم الكامل <span style="color:var(--red);">*</span></label><input type="text" id="reqName" placeholder="أدخل اسم الموظف" required></div>
                        <div class="form-group"><label>اسم الأم</label><input type="text" id="reqMotherName" placeholder="اسم الأم"></div>
                        <div class="form-group"><label>رقم الهوية الوطنية</label><input type="text" id="reqNationalId" placeholder="رقم الهوية الوطنية"></div>
                        <div class="form-group"><label>رقم الهاتف <span style="color:var(--red);">*</span></label><input type="text" id="reqPhone" placeholder="07xxxxxxxxx" required></div>
                        <div class="form-group"><label>المسمى الوظيفي <span style="color:var(--red);">*</span></label><input type="text" id="reqPosition" placeholder="المسمى الوظيفي" required></div>
                        <div class="form-row">
                            <div class="form-group"><label>تاريخ الولادة</label><input type="date" id="reqBirthDate"></div>
                            <div class="form-group"><label>تاريخ التعيين</label><input type="date" id="reqHireDate"></div>
                        </div>
                        <div class="form-group"><label>نوع الشفت</label>
                            <select id="reqShiftType">
                                <option value="morning">صباحي</option>
                                <option value="evening">مسائي</option>
                            </select>
                        </div>
                        <div class="form-row">
                            <div class="form-group"><label>وقت بداية الدوام</label><input type="time" id="reqShiftStart"></div>
                            <div class="form-group"><label>وقت انتهاء الدوام</label><input type="time" id="reqShiftEnd"></div>
                        </div>
                        <div class="form-group"><label>صورة الموظف <span style="color:var(--red);">*</span></label><input type="file" id="reqPhoto" accept="image/*" required></div>
                        <div class="form-group"><label>المستمسكات (ملف)</label><input type="file" id="reqDocuments" accept=".jpg,.jpeg,.png,.pdf,.doc,.docx"></div>
                        <div class="form-group"><label>الراتب الأساسي <span style="color:var(--red);">*</span></label><input type="number" id="reqSalary" placeholder="500000" required></div>
                        <div class="form-group"><label>كلمة المرور <span style="color:var(--red);">*</span></label><input type="password" id="reqPassword" placeholder="أدخل كلمة المرور" minlength="6" required></div>
                        <div style="background:#eaf7ef;padding:10px;border-radius:8px;">
                            <div style="font-size:12px;color:var(--text-muted);"><i class="fas fa-info-circle"></i> سيتم إنشاء رقم الموظف تلقائياً بعد الحفظ</div>
                        </div>
                    </div>
                    <button type="submit" style="width:100%;height:46px;border:none;border-radius:14px;background:var(--primary-gradient);color:#fff;font-size:15px;font-weight:700;cursor:pointer;font-family:var(--font-family);box-shadow:0 4px 16px rgba(0,63,70,0.25);margin-top:8px;display:inline-flex;align-items:center;justify-content:center;gap:10px;">
                        <i class="fas fa-save"></i> حفظ
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- شريط دعوة تثبيت التطبيق (PWA) -->
    <div id="pwaInstallBanner" style="display:none;position:fixed;top:0;left:0;right:0;background:#004b52;color:#fff;z-index:9999;padding:10px 16px;align-items:center;gap:10px;font-size:12.5px;">
        <img src="icons/icon-192.png" style="width:28px;height:28px;border-radius:8px;flex-shrink:0;">
        <span style="flex:1;">ثبّت تطبيق مدير الفرع على جهازك للوصول السريع</span>
        <button onclick="installPwa()" style="background:var(--accent);color:#fff;border:none;padding:6px 14px;border-radius:var(--radius-full);font-weight:700;font-size:11.5px;cursor:pointer;white-space:nowrap;">تثبيت</button>
        <button onclick="dismissPwaBanner()" style="background:none;border:none;color:rgba(255,255,255,0.7);font-size:16px;cursor:pointer;padding:0 4px;">✕</button>
    </div>

    <!-- التوست -->
    <div class="toast-container" id="toastContainer"></div>

    <script>
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
        // شاشة الترحيب
        // ============================================================
        let loaderProgress = 0;
        const loaderBar = document.getElementById('loaderBar');
        const welcomeScreen = document.getElementById('welcomeScreen');
        const loginScreen = document.getElementById('loginScreen');

        function animateLoader() {
            if (loaderProgress >= 100) {
                loaderBar.style.width = '100%';
                setTimeout(() => {
                    welcomeScreen.classList.add('fade-out');
                    setTimeout(() => {
                        welcomeScreen.style.display = 'none';
                        if (alreadyLoggedIn) {
                            document.getElementById('appContainer').classList.remove('hidden');
                            initApp();
                        } else {
                            loginScreen.classList.remove('hidden');
                        }
                        showToast('👋 مرحباً', 'تم تحميل النظام بنجاح', 'success');
                    }, 800);
                }, 600);
                return;
            }
            const increment = Math.random() * 2.5 + 0.8;
            loaderProgress = Math.min(loaderProgress + increment, 100);
            loaderBar.style.width = loaderProgress + '%';
            let delay = 60 + Math.random() * 70;
            if (loaderProgress > 80) delay = 100 + Math.random() * 80;
            if (loaderProgress > 95) delay = 150 + Math.random() * 120;
            setTimeout(animateLoader, delay);
        }

        // ============================================================
        // دوال تسجيل الدخول
        // ============================================================
        function handleLogin(e) {
            e.preventDefault();
            const employeeId = document.getElementById('loginEmployeeId').value;
            const password = document.getElementById('loginPassword').value;
            const btn = document.getElementById('loginBtn');
            const error = document.getElementById('loginError');

            btn.innerHTML = '<span style="display:inline-block;width:20px;height:20px;border:3px solid rgba(255,255,255,0.2);border-radius:50%;border-top-color:#fff;animation:spin 0.8s linear infinite;"></span> جاري التسجيل...';
            btn.disabled = true;
            error.style.display = 'none';

            const body = new URLSearchParams({ employeeNumber: employeeId, password: password });
            fetch('?ajax=login', { method: 'POST', body }).then(r => r.json()).then(data => {
                btn.innerHTML = '<i class="fas fa-arrow-left"></i> تسجيل الدخول';
                btn.disabled = false;
                if (data.ok) {
                    loginScreen.classList.add('hidden');
                    document.getElementById('appContainer').classList.remove('hidden');
                    showToast('✅ مرحباً بك', 'تم تسجيل الدخول بنجاح', 'success');
                    initApp();
                } else {
                    error.textContent = data.error || 'رقم الموظف أو الرمز السري غير صحيح';
                    error.style.display = 'block';
                }
            }).catch(() => {
                btn.innerHTML = '<i class="fas fa-arrow-left"></i> تسجيل الدخول';
                btn.disabled = false;
                error.textContent = 'تعذر الاتصال بالخادم';
                error.style.display = 'block';
            });
        }

        function initApp() {
            loadBriefingEntries();
            loadHomeStats();
            loadBriefStatus();
            loadNotifications();
            setInterval(loadNotifications, 60000);
            requestNotifPermission();
        }

        // ============================================================
        // الإشعارات
        // ============================================================
        function loadNotifications() {
            fetch('?ajax=notifications_list').then(r => r.json()).then(data => {
                if (!data.ok) return;
                const badge = document.getElementById('notifBadge');
                if (data.unread > 0) {
                    badge.style.display = 'flex';
                    badge.textContent = data.unread;
                } else {
                    badge.style.display = 'none';
                }
                const list = document.getElementById('notifList');
                if (!list) return;
                if (!data.notifications.length) {
                    list.innerHTML = '<div class="card" style="text-align:center;padding:24px;color:var(--text-muted);">لا توجد إشعارات</div>';
                    return;
                }
                list.innerHTML = data.notifications.map(n => `
                    <div class="card" style="${n.is_read ? '' : 'border-right:3px solid var(--primary-light);'}">
                        <b style="font-size:13px;">${n.title}</b>
                        <p class="muted" style="margin-top:4px;">${n.message}</p>
                        <div class="muted" style="font-size:10px;margin-top:4px;">${n.date}</div>
                    </div>
                `).join('');
            });
        }

        function markAllNotifsRead() {
            fetch('?ajax=notifications_mark_all_read', { method: 'POST' }).then(() => loadNotifications());
        }

        function loadHomeStats() {
            fetch('?ajax=bootstrap').then(r => r.json()).then(data => {
                if (!data.ok) return;
                if (data.company) {
                    document.getElementById('headerCompanyName').innerHTML = data.company.name + ' <span>مدير الفرع</span>';
                    if (data.company.logo) document.getElementById('headerLogo').innerHTML = `<img src="${data.company.logo}" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;">`;
                }
                document.getElementById('homeManagerName').textContent = data.manager.name;
                document.getElementById('homeManagerRole').textContent = 'مدير فرع — ' + data.manager.branch;
                document.getElementById('homeManagerCode').textContent = 'رقم الموظف: ' + data.manager.code;
                document.getElementById('sideMenuName').textContent = data.manager.name;
                document.getElementById('sideMenuTitle').textContent = 'مدير فرع — ' + data.manager.branch;
                if (data.manager.photo) {
                    document.getElementById('sideMenuAvatar').innerHTML = `<img src="${data.manager.photo}" alt="">`;
                    document.getElementById('homeManagerAvatar').innerHTML = `<img src="${data.manager.photo}" alt="" style="width:100%;height:100%;object-fit:cover;">`;
                }
                document.getElementById('homeRequestsCount').textContent = data.stats.pendingRequests;
                document.getElementById('homeAttendanceCount').textContent = data.stats.presentToday + '/' + data.stats.employees;
                document.getElementById('homeCommitmentPct').textContent = data.stats.commitmentPct + '%';
                const attendancePct = data.stats.employees > 0 ? Math.round((data.stats.presentToday / data.stats.employees) * 100) : 0;
                document.getElementById('ringAttendance').style.setProperty('--pct', attendancePct);
                document.getElementById('ringCommitment').style.setProperty('--pct', data.stats.commitmentPct);
                document.getElementById('ringRequests').style.setProperty('--pct', data.stats.pendingRequests > 0 ? 100 : 0);
                if (data.branchLocation && data.branchLocation.lat) {
                    branchLocation = { lat: data.branchLocation.lat, lng: data.branchLocation.lng, radius: data.branchLocation.radius };
                    document.getElementById('branchLat').value = branchLocation.lat;
                    document.getElementById('branchLng').value = branchLocation.lng;
                    document.getElementById('branchRadius').value = branchLocation.radius;
                    updateLocationStatus(true);
                }
                if (data.delegation) {
                    delegationData = data.delegation;
                    document.getElementById('delegateStart').value = delegationData.start;
                    document.getElementById('delegateEnd').value = delegationData.end;
                    updateDelegationUI();
                } else {
                    delegationData = { employee: '-', start: '-', end: '-', active: false };
                    updateDelegationUI();
                }
                previousDayProfit = Number(data.previousDayProfit) || 0;
                document.getElementById('previousDayProfit').textContent = (previousDayProfit >= 0 ? '+' : '') +
                    previousDayProfit.toLocaleString() + ' د.ع';
                updateBriefingSummary();

                shiftInfo = data.shift;
                todayAttendance = data.todayAttendance;
                updateAttendanceButtonVisibility();
                const statusDiv = document.getElementById('managerAttendanceStatus');
                if (statusDiv && !statusDiv.innerHTML) {
                    if (todayAttendance.checkedOut) statusDiv.textContent = '✅ تم تسجيل حضورك وانصرافك اليوم';
                    else if (todayAttendance.checkedIn) statusDiv.textContent = '✅ تم تسجيل دخولك اليوم';
                }
            });
        }

        // ============================================================
        // الموظفون
        // ============================================================
        function loadEmployees() {
            fetch('?ajax=employees').then(r => r.json()).then(data => {
                if (!data.ok) return;
                renderEmployees(data.employees);
                const select = document.getElementById('manualEmployeeSelect');
                if (select) {
                    select.innerHTML = data.employees.map(e => `<option value="${e.id}">${e.name}</option>`).join('') ||
                        '<option value="">لا يوجد موظفون</option>';
                }
                const delegSelect = document.getElementById('delegateEmployee');
                if (delegSelect) {
                    delegSelect.innerHTML = data.employees.map(e => `<option value="${e.id}">${e.name}</option>`).join('') ||
                        '<option value="">لا يوجد موظفون</option>';
                    if (delegationData && delegationData.employeeId) delegSelect.value = delegationData.employeeId;
                }
            });
        }

        function renderEmployees(employees) {
            const container = document.getElementById('employeesList');
            if (!employees.length) {
                container.innerHTML = '<div class="card" style="text-align:center;padding:24px;color:var(--text-muted);">لا يوجد موظفون بعد</div>';
                return;
            }
            container.innerHTML = employees.map(e => `
                <div class="card">
                    <div class="flex-between"><div class="flex"><div style="width:44px;height:44px;border-radius:50%;background:var(--primary-gradient);display:flex;align-items:center;justify-content:center;color:#fff;font-size:20px;">👤</div><div><b>${e.name}</b><div class="muted">الكود: ${e.code}</div></div></div><span class="badge ${e.status === 'active' ? 'ok' : 'danger'}">${e.status === 'active' ? 'نشط' : 'غير نشط'}</span></div>
                    <div class="grid-2" style="margin-top:8px;font-size:12px;">
                        <span><span class="muted">المسمى:</span> ${e.title || '-'}</span>
                        <span><span class="muted">الراتب:</span> ${Number(e.salary).toLocaleString()}</span>
                        <span><span class="muted">الحضور:</span> ${e.attendancePct}%</span>
                        <span><span class="muted">التقييم:</span> ${e.rating} ★</span>
                    </div>
                </div>
            `).join('');
        }

        // ============================================================
        // الحضور اليومي
        // ============================================================
        function loadAttendanceToday() {
            fetch('?ajax=attendance_today').then(r => r.json()).then(data => {
                if (!data.ok) return;
                renderAttendanceToday(data.attendance);
            });
        }

        function renderAttendanceToday(rows) {
            const wrap = document.querySelector('#page-attendance .table-wrap table');
            if (!wrap) return;
            const badgeClass = { 'حاضر': 'ok', 'تأخير': 'wait', 'غائب': 'danger' };
            wrap.innerHTML = '<tr><th>الموظف</th><th>المسمى</th><th>دخول</th><th>انصراف</th><th>الحالة</th></tr>' +
                rows.map(r => `<tr><td>${r.name}</td><td>${r.title || '-'}</td><td>${r.checkIn}</td><td>${r.checkOut}</td><td><span class="badge ${badgeClass[r.status] || 'wait'}">${r.status}</span></td></tr>`).join('');
        }

        // ============================================================
        // طلبات الموظفين
        // ============================================================
        function loadRequests() {
            fetch('?ajax=requests').then(r => r.json()).then(data => {
                if (!data.ok) return;
                renderRequests(data.requests);
            });
        }

        function renderRequests(requests) {
            const container = document.getElementById('page-requests');
            if (!requests.length) {
                container.innerHTML = '<div class="page-title"><h2><i class="fas fa-file-pen"></i> طلبات الموظفين</h2><button onclick="navigateTo(\'home\')" class="back-btn"><i class="fas fa-arrow-right"></i> رجوع</button></div>' +
                    '<div class="card" style="text-align:center;padding:24px;color:var(--text-muted);">لا توجد طلبات حالياً</div>';
                return;
            }
            const badgeClass = { 'بانتظار موافقتك': 'wait', 'أُرسل للموارد البشرية': 'wait', 'مقبول نهائياً': 'ok', 'مرفوض': 'danger' };
            const icons = { 'إجازة': '📅', 'سلفة': '💰', 'شكوى': '📋', 'استقالة': '🚪' };
            container.innerHTML = '<div class="page-title"><h2><i class="fas fa-file-pen"></i> طلبات الموظفين</h2><button onclick="navigateTo(\'home\')" class="back-btn"><i class="fas fa-arrow-right"></i> رجوع</button></div>' +
                requests.map(r => `
                    <div class="card">
                        <div class="flex-between"><b>${icons[r.type] || '📄'} طلب ${r.type}</b><span class="badge ${badgeClass[r.status] || 'wait'}">${r.status}</span></div>
                        <p class="muted">${r.name} • ${r.details}</p>
                        ${r.canReview ? `
                            <input type="text" id="reqNote_${r.id}" placeholder="رد برسالة (اختياري)" style="width:100%;height:36px;padding:0 10px;border:1.5px solid #e2ebeb;border-radius:8px;font-family:var(--font-family);margin-top:6px;">
                            <div class="flex gap-2 mt-2"><button class="btn green small" onclick="reviewRequest(${r.id},'approved')">موافقة</button><button class="btn red small" onclick="reviewRequest(${r.id},'rejected')">رفض</button></div>
                        ` : ''}
                    </div>
                `).join('');
        }

        function reviewRequest(id, decision) {
            const note = document.getElementById('reqNote_' + id) ? document.getElementById('reqNote_' + id).value : '';
            fetch('?ajax=request_review', { method: 'POST', body: new URLSearchParams({ id, decision, note }) })
                .then(r => r.json()).then(data => {
                    if (data.ok) {
                        showToast(decision === 'approved' ? '✅ تم الإرسال' : '❌ تم الرفض',
                            decision === 'approved' ? 'تم إرسال الطلب إلى الموارد البشرية للموافقة النهائية' : 'تم رفض الطلب', decision === 'approved' ? 'success' : 'error');
                        loadRequests();
                        loadHomeStats();
                    } else {
                        showToast('⚠️ خطأ', data.error || 'تعذر تنفيذ العملية', 'error');
                    }
                });
        }

        // ============================================================
        // الرواتب
        // ============================================================
        function loadPayroll() {
            fetch('?ajax=payroll_list').then(r => r.json()).then(data => {
                if (!data.ok) return;
                renderPayroll(data.payroll);
            });
        }

        // ============================================================
        // ملفي — بيانات مدير الفرع الشخصية
        // ============================================================
        function loadMyProfile() {
            fetch('?ajax=my_profile').then(r => r.json()).then(data => {
                if (!data.ok) return;
                document.getElementById('mpName').textContent = data.name;
                document.getElementById('mpBranch').textContent = data.branch;
                document.getElementById('mpCode').textContent = data.code;
                document.getElementById('mpSalary').textContent = Number(data.baseSalary).toLocaleString() + ' د.ع';
                document.getElementById('mpBase').textContent = Number(data.salary.base).toLocaleString();
                document.getElementById('mpDeduction').textContent = '- ' + Number(data.salary.deduction).toLocaleString();
                document.getElementById('mpBonus').textContent = '+ ' + Number(data.salary.bonus).toLocaleString();
                document.getElementById('mpNet').textContent = Number(data.salary.net).toLocaleString() + ' د.ع';
                document.getElementById('mpStatus').textContent = 'حالة راتب الشهر الحالي: ' + data.salary.statusText;
            });
        }

        let mpMonth = new Date().getMonth() + 1;
        let mpYear = new Date().getFullYear();
        const mpMonthNames = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];

        function loadMyAttendanceMonth() {
            fetch(`?ajax=my_attendance_month&month=${mpMonth}&year=${mpYear}`).then(r => r.json()).then(data => {
                if (!data.ok) return;
                renderMpCalendar(data);
            });
        }

        function mpAttendanceNav(delta) {
            const now = new Date();
            let m = mpMonth + delta;
            let y = mpYear;
            if (m > 12) { m = 1; y++; }
            if (m < 1) { m = 12; y--; }
            if (y > now.getFullYear() || (y === now.getFullYear() && m > now.getMonth() + 1)) return;
            mpMonth = m;
            mpYear = y;
            loadMyAttendanceMonth();
        }

        function renderMpCalendar(data) {
            document.getElementById('mpMonthLabel').textContent = mpMonthNames[data.month - 1] + ' ' + data.year;
            const now = new Date();
            const isCurrentMonth = data.year === now.getFullYear() && data.month === now.getMonth() + 1;
            document.getElementById('mpNextBtn').style.visibility = isCurrentMonth ? 'hidden' : 'visible';

            document.getElementById('mpMonthSummary').innerHTML = `
                <div><div style="font-size:20px;font-weight:800;color:var(--green);">${data.summary.present}</div><div style="font-size:11px;color:var(--text-muted);">حضور</div></div>
                <div><div style="font-size:20px;font-weight:800;color:#D97706;">${data.summary.late}</div><div style="font-size:11px;color:var(--text-muted);">تأخير</div></div>
                <div><div style="font-size:20px;font-weight:800;color:var(--red);">${data.summary.absent}</div><div style="font-size:11px;color:var(--text-muted);">غياب</div></div>
            `;

            const firstDow = new Date(data.year, data.month - 1, 1).getDay();
            const colors = {
                present: ['#059669', 'rgba(16,185,129,0.12)'],
                late: ['#D97706', 'rgba(217,119,6,0.12)'],
                absent: ['#DC2626', 'rgba(239,68,68,0.12)'],
                off: ['var(--text-muted)', 'rgba(0,107,115,0.05)'],
                future: ['var(--text-muted)', 'transparent'],
            };
            let html = '';
            for (let i = 0; i < firstDow; i++) html += '<div></div>';
            data.days.forEach(d => {
                const [fg, bg] = colors[d.status] || colors.future;
                const title = d.checkIn ? `دخول ${d.checkIn}${d.checkOut ? ' - خروج ' + d.checkOut : ''}` : '';
                html += `<div title="${title}" style="aspect-ratio:1;border-radius:8px;background:${bg};color:${fg};display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;">${d.day}</div>`;
            });
            document.getElementById('mpCalendarGrid').innerHTML = html;
        }

        function renderPayroll(rows) {
            const container = document.getElementById('payrollList');
            if (!rows.length) {
                container.innerHTML = '<div class="card" style="text-align:center;padding:24px;color:var(--text-muted);">لا يوجد موظفون بعد</div>';
                return;
            }
            container.innerHTML = rows.map(r => `
                <div class="payroll-card" style="background:var(--bg-card);border-radius:var(--radius-md);border:1px solid #e2ebeb;padding:12px 14px;margin-bottom:8px;">
                    <div class="payroll-header" style="display:flex;align-items:center;justify-content:space-between;">
                        <span style="font-weight:800;font-size:14px;">👤 ${r.name}</span>
                        <span class="badge ${r.status === 'تم التسليم' ? 'ok' : 'wait'}">${r.status}</span>
                    </div>
                    <div class="payroll-details" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;margin-top:6px;font-size:12px;">
                        <div class="detail-item" style="text-align:center;padding:4px;background:rgba(0,107,115,0.03);border-radius:6px;"><span class="value" style="font-weight:700;">${r.salary.toLocaleString()}</span><div class="label" style="font-size:9px;color:var(--text-muted);">الراتب</div></div>
                        <div class="detail-item" style="text-align:center;padding:4px;background:rgba(0,107,115,0.03);border-radius:6px;"><span class="value" style="font-weight:700;color:var(--red);">${r.loan.toLocaleString()}</span><div class="label" style="font-size:9px;color:var(--text-muted);">السلف</div></div>
                        <div class="detail-item" style="text-align:center;padding:4px;background:rgba(0,107,115,0.03);border-radius:6px;"><span class="value" style="font-weight:700;color:var(--green);">${r.net.toLocaleString()}</span><div class="label" style="font-size:9px;color:var(--text-muted);">المستحق</div></div>
                    </div>
                    <div class="payroll-actions" style="display:flex;gap:6px;margin-top:8px;">
                        ${r.status === 'تم التسليم' ? '' : `<button class="btn green small" onclick="paySalary(${r.id})"><i class="fas fa-check"></i> تسليم</button>`}
                        <button class="btn light small" onclick="showToast('📄 تقرير الراتب','تم عرض تقرير الراتب','info')"><i class="fas fa-file"></i> تقرير</button>
                    </div>
                </div>
            `).join('');
        }

        // ============================================================
        // دوال التنقل
        // ============================================================
        let currentPage = 'home';

        function navigateTo(page) {
            document.querySelectorAll('.page-screen').forEach(el => el.classList.add('hidden'));
            const target = document.getElementById('page-' + page);
            if (target) target.classList.remove('hidden');
            document.querySelectorAll('.bottom-nav-minimal .nav-item').forEach(el => el.classList.remove('active'));
            const navItem = document.getElementById('nav-' + page);
            if (navItem) navItem.classList.add('active');
            currentPage = page;
            window.scrollTo(0, 0);

            if (page === 'employees') loadEmployees();
            else if (page === 'attendance') loadAttendanceToday();
            else if (page === 'requests') loadRequests();
            else if (page === 'payroll') loadPayroll();
            else if (page === 'home') loadHomeStats();
            else if (page === 'notifications') loadNotifications();
            else if (page === 'myProfile') { loadMyProfile(); loadMyAttendanceMonth(); }
            else if (page === 'reports') {
                const fromEl = document.getElementById('reportFrom');
                const toEl = document.getElementById('reportTo');
                if (!toEl.value) {
                    const today = new Date().toISOString().split('T')[0];
                    fromEl.value = today.slice(0, 8) + '01';
                    toEl.value = today;
                }
            }
        }

        function toggleSideMenu() {
            const overlay = document.getElementById('sideMenuOverlay');
            const menu = document.getElementById('sideMenu');
            overlay.classList.toggle('show');
            menu.classList.toggle('show');
            document.body.style.overflow = overlay.classList.contains('show') ? 'hidden' : '';
        }

        // ============================================================
        // نظام تحديد موقع الفرع
        // ============================================================
        let branchLocation = { lat: 33.3152, lng: 44.3661, radius: 100 };
        let shiftInfo = null;
        let todayAttendance = { checkedIn: false, checkedOut: false };

        function updateAttendanceButtonVisibility() {
            const inBtn = document.getElementById('checkInBtn');
            const outBtn = document.getElementById('checkOutBtn');
            const note = document.getElementById('attendanceWindowNote');
            if (!inBtn || !outBtn || !shiftInfo) return;

            const now = new Date();
            const nowMinutes = now.getHours() * 60 + now.getMinutes();
            const [startH, startM] = shiftInfo.start.split(':').map(Number);
            const [endH, endM] = shiftInfo.end.split(':').map(Number);
            const shiftStartMin = startH * 60 + startM;
            const shiftEndMin = endH * 60 + endM;
            const grace = shiftInfo.graceMinutes || 15;

            const inWindowOpen = nowMinutes >= (shiftStartMin - 15) && nowMinutes <= (shiftStartMin + grace);
            const outWindowOpen = nowMinutes >= shiftEndMin && nowMinutes <= (shiftEndMin + 15);

            const showIn = !todayAttendance.checkedIn && inWindowOpen;
            const showOut = todayAttendance.checkedIn && !todayAttendance.checkedOut && outWindowOpen;

            inBtn.classList.toggle('hidden', !showIn);
            outBtn.classList.toggle('hidden', !showOut);

            if (note) {
                if (showIn || showOut) {
                    note.style.color = '';
                    note.textContent = '';
                } else if (todayAttendance.checkedIn && todayAttendance.checkedOut) {
                    note.style.color = '';
                    note.textContent = 'تم تسجيل حضورك وانصرافك اليوم';
                } else if (!todayAttendance.checkedIn && nowMinutes > shiftStartMin + grace) {
                    note.style.color = '#DC2626';
                    note.textContent = '✕ غياب — انتهت فترة تسجيل الحضور دون تسجيلك';
                } else if (!todayAttendance.checkedIn) {
                    note.style.color = '';
                    note.textContent = 'يظهر زر تسجيل الدخول من ' + shiftInfo.start + ' حتى مضي ' + grace + ' دقيقة من بداية الدوام';
                } else {
                    note.style.color = '';
                    note.textContent = 'يظهر زر تسجيل الانصراف عند نهاية الدوام (' + shiftInfo.end + ') لمدة 15 دقيقة فقط';
                }
            }
        }
        setInterval(updateAttendanceButtonVisibility, 30000);
        let userLocation = null;
        let gpsActive = false;

        function saveBranchLocation() {
            const lat = parseFloat(document.getElementById('branchLat').value);
            const lng = parseFloat(document.getElementById('branchLng').value);
            const radius = parseInt(document.getElementById('branchRadius').value);
            if (isNaN(lat) || isNaN(lng) || isNaN(radius)) {
                showToast('⚠️ تنبيه', 'يرجى إدخال قيم صحيحة للموقع', 'warning');
                return;
            }
            fetch('?ajax=branch_location_save', { method: 'POST', body: new URLSearchParams({ lat, lng, radius }) })
                .then(r => r.json()).then(data => {
                    if (!data.ok) { showToast('⚠️ خطأ', data.error || 'تعذر حفظ الموقع', 'error'); return; }
                    branchLocation = { lat, lng, radius };
                    updateLocationStatus(true);
                    showToast('✅ تم الحفظ', 'تم حفظ موقع الفرع بنجاح', 'success');
                });
        }

        function getCurrentLocation() {
            const statusDiv = document.getElementById('locationStatus');
            const statusText = document.getElementById('locationStatusText');
            statusDiv.className = 'location-status loading';
            statusText.textContent = '⏳ جاري تحديد الموقع...';
            if (!navigator.geolocation) {
                statusDiv.className = 'location-status inactive';
                statusText.textContent = '❌ متصفحك لا يدعم تحديد الموقع';
                showToast('❌ خطأ', 'متصفحك لا يدعم تحديد الموقع', 'error');
                return;
            }
            navigator.geolocation.getCurrentPosition(
                function(pos) {
                    const lat = pos.coords.latitude;
                    const lng = pos.coords.longitude;
                    document.getElementById('branchLat').value = lat;
                    document.getElementById('branchLng').value = lng;
                    userLocation = { lat, lng };
                    gpsActive = true;
                    statusDiv.className = 'location-status active';
                    statusText.textContent = '✅ تم تحديد الموقع: ' + lat.toFixed(6) + ', ' + lng.toFixed(6);
                    showToast('📍 تم التحديد', 'تم تحديد موقعك الحالي بنجاح', 'success');
                },
                function(err) {
                    statusDiv.className = 'location-status inactive';
                    statusText.textContent = '❌ فشل تحديد الموقع: ' + err.message;
                    showToast('❌ فشل', 'فشل تحديد الموقع: ' + err.message, 'error');
                }, { enableHighAccuracy: true, timeout: 10000 }
            );
        }

        function updateLocationStatus(success) {
            const statusDiv = document.getElementById('locationStatus');
            const statusText = document.getElementById('locationStatusText');
            if (success && branchLocation.lat && branchLocation.lng) {
                statusDiv.className = 'location-status active';
                statusText.textContent = '📍 موقع الفرع: ' + branchLocation.lat.toFixed(6) + ', ' + branchLocation.lng
                    .toFixed(6) + ' (النطاق: ' + branchLocation.radius + 'م)';
            } else {
                statusDiv.className = 'location-status inactive';
                statusText.textContent = '⚠️ لم يتم تحديد موقع الفرع بعد';
            }
        }

        function calculateDistance(lat1, lon1, lat2, lon2) {
            const R = 6371000;
            const dLat = (lat2 - lat1) * Math.PI / 180;
            const dLon = (lon2 - lon1) * Math.PI / 180;
            const a = Math.sin(dLat / 2) * Math.sin(dLat / 2) +
                Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
                Math.sin(dLon / 2) * Math.sin(dLon / 2);
            const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
            return R * c;
        }

        // ============================================================
        // دوال الحضور
        // ============================================================
        function recordManagerAttendance(type) {
            if (!gpsActive) { showToast('⚠️ تنبيه', 'يرجى تفعيل الموقع أولاً', 'warning');
                getCurrentLocation(); return; }
            if (!userLocation) { showToast('⚠️ تنبيه', 'لم يتم تحديد موقعك بعد', 'warning');
                getCurrentLocation(); return; }
            const distance = calculateDistance(userLocation.lat, userLocation.lng, branchLocation.lat, branchLocation.lng);
            if (distance > branchLocation.radius) {
                showToast('❌ خارج النطاق', 'أنت خارج نطاق الفرع (المسافة: ' + Math.round(distance) + 'م، النطاق المسموح: ' +
                    branchLocation.radius + 'م)', 'error');
                return;
            }
            const body = new URLSearchParams({ type, lat: userLocation.lat, lng: userLocation.lng });
            fetch('?ajax=attendance_self', { method: 'POST', body }).then(r => r.json()).then(data => {
                const statusDiv = document.getElementById('managerAttendanceStatus');
                if (!data.ok) {
                    showToast('❌ فشل', data.error || 'تعذر تسجيل الحضور', 'error');
                    return;
                }
                if (type === 'in') {
                    statusDiv.innerHTML = '✅ تم تسجيل الدخول في ' + data.time + ' (ضمن النطاق)';
                    statusDiv.style.color = 'var(--green)';
                    showToast('✅ تسجيل دخول', 'تم تسجيل دخولك في ' + data.time + '\nالموقع ضمن النطاق المسموح', 'success');
                } else {
                    statusDiv.innerHTML = '✅ تم تسجيل الانصراف في ' + data.time + ' (ضمن النطاق)';
                    statusDiv.style.color = 'var(--red)';
                    showToast('✅ تسجيل انصراف', 'تم تسجيل انصرافك في ' + data.time + '\nالموقع ضمن النطاق المسموح', 'success');
                }
                loadHomeStats();
            });
        }

        function manualAttendance(type) {
            const select = document.getElementById('manualEmployeeSelect');
            const employeeId = select.value;
            const note = document.getElementById('manualNote').value || 'بدون ملاحظات';
            const employeeName = select.options[select.selectedIndex] ? select.options[select.selectedIndex].text : '';
            if (!employeeId) { showToast('⚠️ تنبيه', 'لا يوجد موظف محدد', 'warning'); return; }
            const body = new URLSearchParams({ employeeId, type, note });
            fetch('?ajax=attendance_manual', { method: 'POST', body }).then(r => r.json()).then(data => {
                if (!data.ok) { showToast('⚠️ خطأ', data.error || 'تعذر تسجيل الحضور', 'error'); return; }
                const typeText = type === 'in' ? 'دخول' : 'انصراف';
                showToast('✅ تم التسجيل', 'تم تسجيل ' + typeText + ' للموظف ' + employeeName + ' في ' + data.time + '\nملاحظة: ' +
                    note, 'success');
                document.getElementById('manualNote').value = '';
                loadAttendanceToday();
                loadHomeStats();
            });
        }

        // ============================================================
        // دوال الرواتب
        // ============================================================
        function paySalary(employeeId) {
            if (!confirm('تأكيد تسليم الراتب لهذا الموظف؟')) return;
            fetch('?ajax=pay_salary', { method: 'POST', body: new URLSearchParams({ employeeId }) })
                .then(r => r.json()).then(data => {
                    if (!data.ok) { showToast('⚠️ خطأ', data.error || 'تعذر تسليم الراتب', 'error'); return; }
                    showToast('✅ تم التسليم', 'تم تسليم الراتب بنجاح', 'success');
                    loadPayroll();
                });
        }

        // ============================================================
        // دوال التفويض
        // ============================================================
        let delegationData = { employee: '-', start: '-', end: '-', active: false };

        function saveDelegation() {
            const employeeId = document.getElementById('delegateEmployee').value;
            const start = document.getElementById('delegateStart').value;
            const end = document.getElementById('delegateEnd').value;
            if (!employeeId) { showToast('⚠️ تنبيه', 'لا يوجد موظف لتفويضه', 'warning'); return; }
            fetch('?ajax=delegation_save', { method: 'POST', body: new URLSearchParams({ employeeId, start, end }) })
                .then(r => r.json()).then(data => {
                    if (!data.ok) { showToast('⚠️ خطأ', data.error || 'تعذر حفظ التفويض', 'error'); return; }
                    showToast('✅ تم الحفظ', 'تم حفظ تفويض كتابة الإيجاز بنجاح', 'success');
                    loadHomeStats();
                });
        }

        function cancelDelegation() {
            if (confirm('هل أنت متأكد من إلغاء التفويض؟')) {
                fetch('?ajax=delegation_cancel', { method: 'POST' }).then(r => r.json()).then(data => {
                    if (!data.ok) { showToast('⚠️ خطأ', data.error || 'تعذر إلغاء التفويض', 'error'); return; }
                    showToast('❌ تم الإلغاء', 'تم إلغاء تفويض كتابة الإيجاز', 'error');
                    loadHomeStats();
                });
            }
        }

        function updateDelegationUI() {
            document.getElementById('delegateName').textContent = delegationData.employee;
            document.getElementById('delegatePeriod').textContent = delegationData.start + ' - ' + delegationData.end;
            const statusText = document.getElementById('delegateStatusText');
            const statusBadge = document.getElementById('delegationStatus');
            if (delegationData.active) {
                statusText.textContent = '✅ فعال';
                statusText.style.color = 'var(--green)';
                statusBadge.textContent = 'فعال';
                statusBadge.className = 'badge ok';
                document.getElementById('writePermission').textContent = '✅ لديك صلاحية (مفوض)';
                document.getElementById('writePermission').style.color = 'var(--green)';
            } else {
                statusText.textContent = '❌ غير فعال';
                statusText.style.color = 'var(--red)';
                statusBadge.textContent = 'غير فعال';
                statusBadge.className = 'badge danger';
                document.getElementById('writePermission').textContent = '❌ لا توجد صلاحية';
                document.getElementById('writePermission').style.color = 'var(--red)';
            }
        }

        // ============================================================
        // نظام الإيجاز المطور
        // ============================================================
        let briefingEntries = [];
        let previousDayProfit = 0;

        function loadBriefingEntries() {
            fetch('?ajax=ledger_list').then(r => r.json()).then(data => {
                if (!data.ok) return;
                briefingEntries = data.entries.map(e => ({
                    id: e.id,
                    type: e.entry_type,
                    amount: Number(e.amount),
                    note: e.description,
                    file: e.attachment,
                }));
                renderBriefingEntries();
            });
        }

        function renderBriefingEntries() {
            const container = document.getElementById('briefingEntries');
            if (briefingEntries.length === 0) {
                container.innerHTML = `
                    <div class="card" style="text-align:center;padding:30px 20px;color:var(--text-muted);">
                        <i class="fas fa-plus-circle" style="font-size:48px;color:var(--primary-light);margin-bottom:12px;display:block;"></i>
                        <p>لا توجد قيود مضافة بعد</p>
                        <p class="muted">أضف قيداً جديداً باستخدام النموذج أعلاه</p>
                    </div>
                `;
                updateBriefingSummary();
                return;
            }

            container.innerHTML = briefingEntries.map(entry => `
                <div class="briefing-entry" data-id="${entry.id}">
                    <div class="entry-header">
                        <span class="entry-type ${entry.type}">${entry.type === 'income' ? '💰 إيراد' : '💸 صرف'}</span>
                        <span class="badge ${entry.type === 'income' ? 'ok' : 'danger'}">تم</span>
                    </div>
                    <div class="entry-amount ${entry.type}">${entry.type === 'income' ? '+' : '-'}${Number(entry.amount).toLocaleString()} د.ع</div>
                    <div class="entry-details">📝 ${entry.note || 'بدون ملاحظات'}</div>
                    ${entry.file ? `<div class="entry-details">📎 ملف مرفق: <a href="${entry.file}" target="_blank">عرض</a></div>` : ''}
                    <div class="entry-actions">
                        <button class="btn red small" onclick="deleteEntry(${entry.id})"><i class="fas fa-trash"></i> حذف</button>
                    </div>
                </div>
            `).join('');

            updateBriefingSummary();
        }

        function addBriefingEntry() {
            const type = document.getElementById('entryType').value;
            const amount = document.getElementById('entryAmount').value;
            const note = document.getElementById('entryNote').value;
            const fileInput = document.getElementById('entryFile');

            if (!amount || isNaN(amount) || Number(amount) <= 0) {
                showToast('⚠️ تنبيه', 'يرجى إدخال مبلغ صحيح', 'warning');
                return;
            }

            const formData = new FormData();
            formData.append('type', type);
            formData.append('amount', amount);
            formData.append('note', note);
            if (fileInput.files && fileInput.files.length > 0) {
                formData.append('file', fileInput.files[0]);
            }

            fetch('?ajax=ledger_add', { method: 'POST', body: formData }).then(r => r.json()).then(data => {
                if (!data.ok) { showToast('⚠️ خطأ', data.error || 'تعذر إضافة القيد', 'error'); return; }
                loadBriefingEntries();
                document.getElementById('entryAmount').value = '';
                document.getElementById('entryNote').value = '';
                document.getElementById('entryFile').value = '';
                showToast('✅ تم الإضافة', 'تم إضافة القيد بنجاح', 'success');
            });
        }

        function deleteEntry(id) {
            if (confirm('هل أنت متأكد من حذف هذا القيد؟')) {
                fetch('?ajax=ledger_delete', { method: 'POST', body: new URLSearchParams({ id }) })
                    .then(r => r.json()).then(data => {
                        if (!data.ok) { showToast('⚠️ خطأ', data.error || 'تعذر حذف القيد', 'error'); return; }
                        loadBriefingEntries();
                        showToast('🗑️ تم الحذف', 'تم حذف القيد بنجاح', 'warning');
                    });
            }
        }

        function updateBriefingSummary() {
            let totalIncome = 0;
            let totalExpense = 0;

            briefingEntries.forEach(entry => {
                if (entry.type === 'income') {
                    totalIncome += entry.amount;
                } else {
                    totalExpense += entry.amount;
                }
            });

            const netProfit = previousDayProfit + totalIncome - totalExpense;

            document.getElementById('summaryPrevious').textContent = (previousDayProfit >= 0 ? '+' : '') + previousDayProfit
                .toLocaleString();
            document.getElementById('summaryIncome').textContent = '+' + totalIncome.toLocaleString();
            document.getElementById('summaryExpense').textContent = '-' + totalExpense.toLocaleString();
            document.getElementById('summaryTotal').textContent = (netProfit >= 0 ? '+' : '') + netProfit.toLocaleString() +
                ' د.ع';

            // تحديث عرض HR
            updateHRBriefing(totalIncome, totalExpense, netProfit);
        }

        function updateHRBriefing(totalIncome, totalExpense, netProfit) {
            const container = document.getElementById('hrBriefingDisplay');
            const date = document.getElementById('briefDate').textContent;

            let entriesHTML = briefingEntries.map(entry => `
                <div class="hr-entry">
                    <span><span class="hr-entry-type ${entry.type}">${entry.type === 'income' ? '💰 إيراد' : '💸 صرف'}</span> ${entry.note || 'بدون ملاحظات'}</span>
                    <span class="hr-entry-amount ${entry.type}">${entry.type === 'income' ? '+' : '-'}${entry.amount.toLocaleString()}</span>
                </div>
            `).join('');

            if (briefingEntries.length === 0) {
                entriesHTML = `
                    <div style="text-align:center;padding:20px;color:var(--text-muted);">
                        <p>لا توجد قيود مضافة في هذا الإيجاز</p>
                    </div>
                `;
            }

            container.innerHTML = `
                <div class="hr-briefing-card">
                    <div class="hr-header">
                        <span class="hr-branch">🏢 ${document.getElementById('homeManagerRole') ? document.getElementById('homeManagerRole').textContent.replace('مدير فرع — ', '') : ''}</span>
                        <span class="hr-date">${date}</span>
                    </div>
                    <div class="hr-net-profit">
                        <div class="label">صافي ربح اليوم</div>
                        <div class="value">${netProfit >= 0 ? '+' : ''}${netProfit.toLocaleString()} د.ع</div>
                    </div>
                    ${entriesHTML}
                    <div class="hr-footer">
                        تم إنشاء هذا التقرير بواسطة نظام إدارة فروع شركة الصوى للصرافة
                    </div>
                </div>
            `;
        }

        // ============================================================
        // نشر الإيجاز
        // ============================================================

        function publishBriefing() {
            if (briefingEntries.length === 0) {
                showToast('⚠️ تنبيه', 'لا توجد قيود لنشرها، أضف قيداً أولاً', 'warning');
                return;
            }
            const travelersCount = document.getElementById('travelersCount').value || 0;
            fetch('?ajax=briefing_publish', { method: 'POST', body: new URLSearchParams({ travelersCount }) }).then(r => r.json()).then(data => {
                if (!data.ok) { showToast('⚠️ خطأ', data.error || 'تعذر نشر الإيجاز', 'error'); return; }
                const date = document.getElementById('briefDate').textContent;
                const total = document.getElementById('summaryTotal').textContent;
                showToast('✅ تم النشر', 'تم نشر الإيجاز بتاريخ ' + date + '\n' + total + '\nتم إرساله إلى HR بنجاح', 'success');
                document.getElementById('hrBriefingDisplay').scrollIntoView({ behavior: 'smooth', block: 'start' });
                loadBriefStatus();
            });
        }

        function loadBriefStatus() {
            fetch('?ajax=brief_status').then(r => r.json()).then(data => {
                if (!data.ok) return;
                document.getElementById('briefStatusText').textContent = data.statusText;
                const noteEl = document.getElementById('briefStatusNote');
                if (data.note) {
                    noteEl.textContent = 'ملاحظة: ' + data.note;
                    noteEl.style.display = 'block';
                } else {
                    noteEl.style.display = 'none';
                }
                if (data.travelersCount !== undefined) {
                    document.getElementById('travelersCount').value = data.travelersCount || '';
                }
            });
        }

        // ============================================================
        // دوال التقارير
        // ============================================================
        function reportAttendanceTable(rows) {
            if (!rows || !rows.length) return '<p class="muted">لا توجد بيانات</p>';
            return '<div class="table-wrap"><table class="table"><tr><th>الموظف</th><th>دخول</th><th>انصراف</th><th>الحالة</th></tr>' +
                rows.map(r => `<tr><td>${r.name}</td><td>${r.checkIn}</td><td>${r.checkOut}</td><td>${r.status}</td></tr>`).join('') + '</table></div>';
        }
        function reportSalariesTable(rows) {
            if (!rows || !rows.length) return '<p class="muted">لا توجد بيانات</p>';
            return '<div class="table-wrap"><table class="table"><tr><th>الموظف</th><th>الأساسي</th><th>المكافأة</th><th>الخصم</th><th>الصافي</th><th>الحالة</th></tr>' +
                rows.map(r => `<tr><td>${r.name}</td><td>${r.base}</td><td>${r.bonus}</td><td>${r.deduction}</td><td>${r.net}</td><td>${r.status}</td></tr>`).join('') + '</table></div>';
        }
        function reportBriefingTable(rows) {
            if (!rows || !rows.length) return '<p class="muted">لا توجد بيانات</p>';
            return '<div class="table-wrap"><table class="table"><tr><th>التاريخ</th><th>الإيراد</th><th>المصروف</th><th>المسافرون</th><th>الربح</th><th>الحالة</th><th>ملاحظة HR</th><th>ملاحظة المسؤول العام</th></tr>' +
                rows.map(r => `<tr><td>${r.date}</td><td>${r.revenue}</td><td>${r.expense}</td><td>${r.travelers}</td><td>${r.profit}</td><td>${r.statusText || '-'}</td><td>${r.hrNote || '-'}</td><td>${r.gmNote || '-'}</td></tr>`).join('') + '</table></div>';
        }

        function generateReport() {
            const type = document.getElementById('reportType').value;
            const from = document.getElementById('reportFrom').value;
            const to = document.getElementById('reportTo').value;
            const qs = new URLSearchParams({ type, from, to });
            fetch('?ajax=report&' + qs.toString()).then(r => r.json()).then(data => {
                if (!data.ok) { showToast('⚠️ خطأ', 'تعذر إنشاء التقرير', 'error'); return; }
                let html = '<div class="card">';
                if (type === 'attendance' || type === 'all') html += '<h3><i class="fas fa-clock"></i> الحضور</h3>' + reportAttendanceTable(data.attendance);
                if (type === 'salaries' || type === 'all') html += '<h3 style="margin-top:14px;"><i class="fas fa-wallet"></i> الرواتب</h3>' + reportSalariesTable(data.salaries);
                if (type === 'briefing' || type === 'all') html += '<h3 style="margin-top:14px;"><i class="fas fa-chart-simple"></i> الإيجاز</h3>' + reportBriefingTable(data.briefing);
                html += '</div>';
                document.getElementById('reportResult').innerHTML = html;
                showToast('✅ تم الإنشاء', 'تم إنشاء التقرير بنجاح', 'success');
            });
        }

        function downloadReport() {
            const type = document.getElementById('reportType').value;
            const from = document.getElementById('reportFrom').value;
            const to = document.getElementById('reportTo').value;
            const qs = new URLSearchParams({ type, from, to });
            window.location.href = '?ajax=report_download&' + qs.toString();
        }

        function saveAttendanceReport() {
            const today = new Date().toISOString().split('T')[0];
            const qs = new URLSearchParams({ type: 'attendance', from: today, to: today });
            showToast('📥 جاري التحميل', 'يتم تحميل تقرير حضور اليوم...', 'info');
            window.location.href = '?ajax=report_download&' + qs.toString();
        }

        function savePayrollReport() {
            const today = new Date();
            const from = today.toISOString().slice(0, 8) + '01';
            const to = today.toISOString().split('T')[0];
            const qs = new URLSearchParams({ type: 'salaries', from, to });
            showToast('📥 جاري التحميل', 'يتم تحميل تقرير رواتب هذا الشهر...', 'info');
            window.location.href = '?ajax=report_download&' + qs.toString();
        }

        // ============================================================
        // نافذة الطلب
        // ============================================================
        function openRequestModal(type) {
            const modal = document.getElementById('requestModal');
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            if (type === 'addEmployee') {
                document.getElementById('requestModalTitle').innerHTML =
                    '<i class="fas fa-user-plus" style="color:var(--primary-light);"></i> إضافة موظف';
                document.getElementById('requestForm').reset();
            }
        }

        function closeRequestModal() {
            document.getElementById('requestModal').style.display = 'none';
            document.body.style.overflow = '';
        }

        function submitRequestForm(e) {
            e.preventDefault();
            const btn = e.target.querySelector('button[type="submit"]');
            const formData = new FormData();
            formData.append('name', document.getElementById('reqName').value);
            formData.append('motherName', document.getElementById('reqMotherName').value);
            formData.append('nationalId', document.getElementById('reqNationalId').value);
            formData.append('phone', document.getElementById('reqPhone').value);
            formData.append('position', document.getElementById('reqPosition').value);
            formData.append('birthDate', document.getElementById('reqBirthDate').value);
            formData.append('hireDate', document.getElementById('reqHireDate').value);
            formData.append('shiftType', document.getElementById('reqShiftType').value);
            formData.append('shiftStart', document.getElementById('reqShiftStart').value);
            formData.append('shiftEnd', document.getElementById('reqShiftEnd').value);
            formData.append('salary', document.getElementById('reqSalary').value);
            formData.append('password', document.getElementById('reqPassword').value);
            const photoInput = document.getElementById('reqPhoto');
            if (!photoInput.files || photoInput.files.length === 0) {
                showToast('⚠️ تنبيه', 'صورة الموظف مطلوبة', 'warning');
                return;
            }
            formData.append('photo', photoInput.files[0]);
            const docsInput = document.getElementById('reqDocuments');
            if (docsInput.files && docsInput.files.length > 0) {
                formData.append('documents', docsInput.files[0]);
            }
            btn.innerHTML = '<span style="display:inline-block;width:18px;height:18px;border:2px solid rgba(255,255,255,0.3);border-radius:50%;border-top-color:#fff;animation:spin 0.8s linear infinite;"></span> جاري الحفظ...';
            btn.disabled = true;
            fetch('?ajax=employee_add', { method: 'POST', body: formData }).then(r => r.json()).then(data => {
                btn.innerHTML = '<i class="fas fa-save"></i> حفظ';
                btn.disabled = false;
                if (!data.ok) {
                    showToast('⚠️ خطأ', data.error || 'تعذر إضافة الموظف', 'error');
                    return;
                }
                closeRequestModal();
                showToast('✅ تم الحفظ', 'تم إضافة الموظف بنجاح، الكود الوظيفي: ' + data.code, 'success');
                loadEmployees();
                loadHomeStats();
            }).catch(() => {
                btn.innerHTML = '<i class="fas fa-save"></i> حفظ';
                btn.disabled = false;
                showToast('❌ خطأ', 'تعذر الاتصال بالخادم', 'error');
            });
        }

        function handleLogout() {
            if (confirm('هل أنت متأكد من رغبتك في تسجيل الخروج؟')) {
                fetch('?ajax=logout', { method: 'POST' }).then(() => {
                    document.getElementById('appContainer').classList.add('hidden');
                    loginScreen.classList.remove('hidden');
                    showToast('👋 تم تسجيل الخروج', 'نتمنى رؤيتك قريباً', 'info');
                });
            }
        }

        // ============================================================
        // Toast
        // ============================================================
        let toastId = 0;

        function showToast(title, message, type = 'info', duration = 3000) {
            const container = document.getElementById('toastContainer');
            const id = ++toastId;
            const icons = { 'success': 'fas fa-check-circle', 'info': 'fas fa-info-circle', 'warning': 'fas fa-exclamation-triangle',
                'error': 'fas fa-times-circle' };
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.id = `toast-${id}`;
            toast.innerHTML =
                `<div class="toast-icon ${type}"><i class="${icons[type] || icons.info}"></i></div><div class="toast-content"><div class="toast-title">${title}</div><div class="toast-message">${message}</div></div>`;
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
        const alreadyLoggedIn = <?= $isLoggedIn ? 'true' : 'false' ?>;

        document.addEventListener('DOMContentLoaded', function() {
            welcomeScreen.style.display = 'flex';
            loginScreen.classList.add('hidden');
            document.getElementById('appContainer').classList.add('hidden');
            setTimeout(animateLoader, 500);

            const today = new Date();
            const dateStr = today.toLocaleDateString('ar-SA', { weekday: 'long', year: 'numeric', month: 'long',
                day: 'numeric' });
            document.getElementById('todayDate').textContent = dateStr;
            document.getElementById('briefDate').textContent = dateStr;
            document.getElementById('payrollMonthDisplay').textContent = today.toLocaleDateString('ar-SA', { month: 'long',
                year: 'numeric' });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    if (document.getElementById('sideMenuOverlay').classList.contains('show')) toggleSideMenu();
                    closeRequestModal();
                }
            });
            document.getElementById('requestModal').addEventListener('click', function(e) {
                if (e.target === this) closeRequestModal();
            });

            console.log('🏢 شركة الصوى للصرافة - مدير الفرع');
        });

        window.navigateTo = navigateTo;
        window.toggleSideMenu = toggleSideMenu;
        window.openRequestModal = openRequestModal;
        window.closeRequestModal = closeRequestModal;
        window.submitRequestForm = submitRequestForm;
        window.handleLogout = handleLogout;
        window.showToast = showToast;
        window.handleLogin = handleLogin;
        window.recordManagerAttendance = recordManagerAttendance;
        window.manualAttendance = manualAttendance;
        window.paySalary = paySalary;
        window.saveBranchLocation = saveBranchLocation;
        window.getCurrentLocation = getCurrentLocation;
        window.saveDelegation = saveDelegation;
        window.cancelDelegation = cancelDelegation;
        window.addBriefingEntry = addBriefingEntry;
        window.deleteEntry = deleteEntry;
        window.publishBriefing = publishBriefing;
        window.downloadReport = downloadReport;
        window.markAllNotifsRead = markAllNotifsRead;
        window.generateReport = generateReport;
        window.saveAttendanceReport = saveAttendanceReport;
        window.savePayrollReport = savePayrollReport;
        window.reviewRequest = reviewRequest;
        window.loadEmployees = loadEmployees;
        window.loadAttendanceToday = loadAttendanceToday;
        window.loadRequests = loadRequests;
        window.loadPayroll = loadPayroll;
    </script>

</body>
</html>