<?php
/* ======================================================================
   نافذة الموظف — نفس الملف الأصلي (HTML/CSS/JS) تماماً
   مع طبقة PHP + MySQL حقيقية خلفه بدل البيانات الوهمية في JavaScript.
   القسم الأول: منطق PHP ونقاط AJAX — القسم الثاني: نفس HTML/CSS/JS الأصلي
   ====================================================================== */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0');
date_default_timezone_set('Asia/Baghdad');

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

function notify_attendance_window(PDO $pdo, int $userId, string $shiftStart, string $shiftEnd, int $graceMinutes, $todayAtt): void
{
    $nowMinutes = (int) date('H') * 60 + (int) date('i');
    [$shH, $shM] = array_map('intval', explode(':', $shiftStart));
    [$eH, $eM] = array_map('intval', explode(':', $shiftEnd));
    $shiftStartMin = $shH * 60 + $shM;
    $shiftEndMin = $eH * 60 + $eM;
    $inWindowOpen = $nowMinutes >= ($shiftStartMin - 15) && $nowMinutes <= ($shiftStartMin + $graceMinutes);
    $outWindowOpen = $nowMinutes >= $shiftEndMin && $nowMinutes <= ($shiftEndMin + 15);

    $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND title=? AND DATE(created_at)=CURDATE()");

    if ($inWindowOpen && !$todayAtt) {
        $dupStmt->execute([$userId, 'فُتحت فترة تسجيل الحضور']);
        if (!$dupStmt->fetchColumn()) {
            $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, 'فُتحت فترة تسجيل الحضور', ?)")
                ->execute([$userId, 'يمكنك الآن تسجيل حضورك، بادر قبل انتهاء الفترة']);
        }
    }
    if ($outWindowOpen && $todayAtt && $todayAtt['check_in'] && !$todayAtt['check_out']) {
        $dupStmt->execute([$userId, 'فُتحت فترة تسجيل الانصراف']);
        if (!$dupStmt->fetchColumn()) {
            $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, 'فُتحت فترة تسجيل الانصراف', ?)")
                ->execute([$userId, 'انتهى دوامك، يمكنك الآن تسجيل انصرافك خلال 15 دقيقة']);
        }
    }
}

function distance_meters(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $r = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return $r * (2 * atan2(sqrt($a), sqrt(1 - $a)));
}

function request_type_en(string $t): string
{
    return ['إجازة' => 'leave', 'سلفة' => 'advance', 'شكوى' => 'complaint', 'استقالة' => 'resignation'][$t] ?? 'complaint';
}
function request_type_ar_emp(string $t): string
{
    return ['leave' => 'إجازة', 'advance' => 'سلفة', 'complaint' => 'شكوى', 'resignation' => 'استقالة'][$t] ?? $t;
}
function request_status_ar_emp(string $s): string
{
    return [
        'pending' => 'بانتظار موافقة مدير الفرع',
        'branch_approved' => 'قيد المراجعة لدى الموارد البشرية',
        'approved' => 'مقبول نهائياً',
        'rejected' => 'مرفوض',
    ][$s] ?? $s;
}

function log_error(PDO $pdo, string $action, ?string $role, ?int $userId, string $message): void
{
    try {
        $pdo->prepare("INSERT INTO error_log (app, action, user_role, user_id, message) VALUES ('employee', ?, ?, ?, ?)")
            ->execute([$action, $role, $userId, mb_substr($message, 0, 500)]);
    } catch (Throwable $e) {
        // جدول error_log قد لا يكون موجوداً بعد على قاعدة بيانات لم تُحدَّث — تجاهل بصمت
    }
}

function employee_active_delegation(PDO $pdo, int $branchId, int $employeeId): ?array
{
    $stmt = $pdo->prepare("SELECT start_date, end_date FROM delegations
        WHERE branch_id=? AND delegated_employee_id=? AND status='active' AND CURDATE() BETWEEN start_date AND end_date
        LIMIT 1");
    $stmt->execute([$branchId, $employeeId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

$isLoggedIn = !empty($_SESSION['employee_user']);

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
            WHERE u.username = ? AND u.role = 'employee' AND u.status = 'active' LIMIT 1");
        $stmt->execute([$employeeNumber]);
        $row = $stmt->fetch();
        if ($row && password_verify($password, $row['password_hash'])) {
            $_SESSION['employee_user'] = [
                'id' => (int) $row['id'],
                'employee_id' => (int) $row['employee_id'],
                'branch_id' => (int) $row['branch_id'],
                'full_name' => $row['full_name'],
                'employee_number' => $row['employee_number'],
            ];
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'رقم التوظيف أو الرمز السري غير صحيح']);
        }
        exit;
    }

    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        echo json_encode(['ok' => true]);
        exit;
    }

    if (empty($_SESSION['employee_user'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'unauthorized']);
        exit;
    }
    $emp = $_SESSION['employee_user'];
    $employeeId = $emp['employee_id'];
    $branchId = $emp['branch_id'];

    if ($action === 'log_error') {
        $clientMsg = trim((string) ($_POST['message'] ?? ''));
        $clientAction = trim((string) ($_POST['clientAction'] ?? 'client'));
        if ($clientMsg !== '') {
            log_error($pdo, $clientAction, 'employee', $emp['id'], $clientMsg);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    try {
    switch ($action) {

        case 'bootstrap': {
            $empRow = $pdo->prepare("SELECT e.*, b.name AS branch_name, b.latitude, b.longitude, b.geofence_radius
                FROM employees e JOIN branches b ON b.id = e.branch_id WHERE e.id = ?");
            $empRow->execute([$employeeId]);
            $empRow = $empRow->fetch();

            $settingsRow = $pdo->query("SELECT work_start_time, work_end_time, late_grace_minutes, company_name, company_logo FROM settings ORDER BY id DESC LIMIT 1")->fetch();
            $shiftStart = $empRow['shift_start'] ?: ($settingsRow['work_start_time'] ?? '09:00:00');
            $shiftEnd = $empRow['shift_end'] ?: ($settingsRow['work_end_time'] ?? '15:00:00');
            $graceMinutes = (int) ($settingsRow['late_grace_minutes'] ?? 15);

            $today = date('Y-m-d');
            $todayAttStmt = $pdo->prepare("SELECT check_in, check_out, status FROM attendance WHERE employee_id=? AND attendance_date=?");
            $todayAttStmt->execute([$employeeId, $today]);
            $todayAtt = $todayAttStmt->fetch();

            notify_attendance_window($pdo, $emp['id'], $shiftStart, $shiftEnd, $graceMinutes, $todayAtt);

            $monthStart = date('Y-m-01');
            $commitStmt = $pdo->prepare("SELECT SUM(status IN ('present','late')) AS present_days, COUNT(*) AS total_days
                FROM attendance WHERE employee_id=? AND attendance_date >= ?");
            $commitStmt->execute([$employeeId, $monthStart]);
            $commit = $commitStmt->fetch();
            $totalDays = max(1, (int) ($commit['total_days'] ?? 0));
            $commitmentPct = round((((int) ($commit['present_days'] ?? 0)) / $totalDays) * 100);

            $pendingReq = $pdo->prepare("SELECT COUNT(*) FROM requests WHERE employee_id=? AND status IN ('pending','branch_approved')");
            $pendingReq->execute([$employeeId]);

            $unreadNotif = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
            $unreadNotif->execute([$emp['id']]);

            $topStmt = $pdo->query("
                SELECT e.id, e.full_name AS name, e.photo, b.name AS branch,
                       ROUND(SUM(a.status IN ('present','late')) / GREATEST(COUNT(a.id),1) * 100) AS rate
                FROM employees e JOIN branches b ON b.id = e.branch_id
                LEFT JOIN attendance a ON a.employee_id = e.id AND a.attendance_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')
                WHERE e.status = 'active'
                GROUP BY e.id, e.full_name, e.photo, b.name
                HAVING COUNT(a.id) > 0
                ORDER BY rate DESC LIMIT 3
            ");
            $medals = ['🥇', '🥈', '🥉'];
            $honorRoll = [];
            foreach ($topStmt->fetchAll() as $i => $r) {
                $honorRoll[] = ['name' => $r['name'], 'branch' => $r['branch'], 'photo' => $r['photo'] ?: null, 'rate' => (int) $r['rate'], 'medal' => $medals[$i]];
            }

            $delegation = employee_active_delegation($pdo, $branchId, $employeeId);

            $month = (int) date('n');
            $year = (int) date('Y');
            $payStmt = $pdo->prepare("SELECT bonus, deduction, status FROM payroll WHERE employee_id=? AND period_month=? AND period_year=?");
            $payStmt->execute([$employeeId, $month, $year]);
            $pay = $payStmt->fetch();

            $advStmt = $pdo->prepare("SELECT amount, approved_monthly_deduction, remaining_balance FROM requests WHERE employee_id=? AND type='advance' AND status='approved' AND remaining_balance > 0 ORDER BY id ASC LIMIT 1");
            $advStmt->execute([$employeeId]);
            $activeAdvance = $advStmt->fetch();

            $todayStatusText = 'لم يسجل بعد';
            if ($todayAtt) {
                if ($todayAtt['check_in'] && $todayAtt['check_out']) $todayStatusText = '✓ حضور وانصراف';
                elseif ($todayAtt['check_in']) $todayStatusText = ($todayAtt['status'] === 'late') ? '⏰ تأخير' : '✓ حضور';
            } else {
                $nowMinutes = (int) date('H') * 60 + (int) date('i');
                [$shH, $shM] = array_map('intval', explode(':', $shiftStart));
                $checkinDeadline = $shH * 60 + $shM + $graceMinutes;
                if ($nowMinutes > $checkinDeadline) {
                    $todayStatusText = '✕ غياب';
                }
            }

            $recentStmt = $pdo->prepare("SELECT attendance_date, check_in, check_out, status FROM attendance WHERE employee_id=? ORDER BY attendance_date DESC LIMIT 5");
            $recentStmt->execute([$employeeId]);
            $recentAttendance = array_map(function ($r) {
                return [
                    'date' => date('d/m/Y', strtotime($r['attendance_date'])),
                    'checkIn' => $r['check_in'] ? substr($r['check_in'], 0, 5) : null,
                    'checkOut' => $r['check_out'] ? substr($r['check_out'], 0, 5) : null,
                    'late' => $r['status'] === 'late',
                ];
            }, $recentStmt->fetchAll());

            echo json_encode([
                'ok' => true,
                'profile' => [
                    'name' => $empRow['full_name'],
                    'code' => $empRow['employee_number'],
                    'title' => $empRow['job_title'],
                    'branch' => $empRow['branch_name'],
                    'photo' => $empRow['photo'] ?: null,
                    'documents' => $empRow['documents'] ?: null,
                    'hireDate' => $empRow['hire_date'],
                    'baseSalary' => (float) $empRow['base_salary'],
                    'shiftTypeText' => $empRow['shift_type'] === 'evening' ? 'مسائي' : 'صباحي',
                    'adminDeduction' => (float) ($pay['deduction'] ?? 0),
                    'adminBonus' => (float) ($pay['bonus'] ?? 0),
                    'netSalary' => (float) $empRow['base_salary'] + (float) ($pay['bonus'] ?? 0) - (float) ($pay['deduction'] ?? 0),
                    'salaryDelivered' => ($pay['status'] ?? null) === 'delivered',
                    'hasAdvance' => (bool) $activeAdvance,
                    'advanceAmount' => $activeAdvance ? (float) $activeAdvance['amount'] : null,
                    'advanceMonthlyDeduction' => $activeAdvance ? (float) $activeAdvance['approved_monthly_deduction'] : null,
                    'advanceRemaining' => $activeAdvance ? (float) $activeAdvance['remaining_balance'] : null,
                ],
                'stats' => [
                    'commitmentPct' => $commitmentPct,
                    'todayStatus' => $todayStatusText,
                    'pendingRequests' => (int) $pendingReq->fetchColumn(),
                    'unreadNotifications' => (int) $unreadNotif->fetchColumn(),
                ],
                'company' => [
                    'name' => $settingsRow['company_name'] ?: 'شركة الصوى للصرافة',
                    'logo' => $settingsRow['company_logo'] ?: null,
                ],
                'honorRoll' => $honorRoll,
                'recentAttendance' => $recentAttendance,
                'delegation' => $delegation ? [
                    'active' => true,
                    'start' => date('d/m/Y', strtotime($delegation['start_date'])),
                    'end' => date('d/m/Y', strtotime($delegation['end_date'])),
                ] : ['active' => false],
                'branchLocation' => [
                    'lat' => $empRow['latitude'] ? (float) $empRow['latitude'] : null,
                    'lng' => $empRow['longitude'] ? (float) $empRow['longitude'] : null,
                    'radius' => (int) $empRow['geofence_radius'],
                ],
                'shift' => [
                    'start' => substr($shiftStart, 0, 5),
                    'end' => substr($shiftEnd, 0, 5),
                    'graceMinutes' => $graceMinutes,
                ],
                'todayAttendance' => [
                    'checkedIn' => (bool) ($todayAtt['check_in'] ?? false),
                    'checkedOut' => (bool) ($todayAtt['check_out'] ?? false),
                    'checkInTime' => $todayAtt['check_in'] ? substr($todayAtt['check_in'], 0, 5) : null,
                    'checkOutTime' => $todayAtt['check_out'] ? substr($todayAtt['check_out'], 0, 5) : null,
                ],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'password_change': {
            $oldPassword = (string) ($_POST['oldPassword'] ?? '');
            $newPassword = (string) ($_POST['newPassword'] ?? '');
            if (strlen($newPassword) < 6) {
                echo json_encode(['ok' => false, 'error' => 'كلمة المرور الجديدة يجب ألا تقل عن 6 أحرف']);
                exit;
            }
            $userRow = $pdo->prepare("SELECT password_hash FROM users WHERE id=?");
            $userRow->execute([$emp['id']]);
            $userRow = $userRow->fetch();
            if (!$userRow || !password_verify($oldPassword, $userRow['password_hash'])) {
                echo json_encode(['ok' => false, 'error' => 'كلمة المرور الحالية غير صحيحة']);
                exit;
            }
            $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $pdo->prepare("UPDATE users SET password_hash=? WHERE id=?")->execute([$newHash, $emp['id']]);
            echo json_encode(['ok' => true]);
            exit;
        }

        case 'notifications_list': {
            $stmt = $pdo->prepare("SELECT id, title, message, is_read, DATE_FORMAT(created_at,'%d/%m/%Y %H:%i') AS date FROM notifications WHERE user_id=? ORDER BY created_at DESC LIMIT 30");
            $stmt->execute([$emp['id']]);
            $rows = $stmt->fetchAll();
            $unread = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
            $unread->execute([$emp['id']]);
            echo json_encode(['ok' => true, 'notifications' => $rows, 'unread' => (int) $unread->fetchColumn()], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'notifications_mark_all_read': {
            $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=?")->execute([$emp['id']]);
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
                echo json_encode(['ok' => false, 'error' => 'لم يتم تحديد موقع الفرع بعد من قبل الإدارة']);
                exit;
            }
            $dist = distance_meters((float) $branch['latitude'], (float) $branch['longitude'], $lat, $lng);
            if ($dist > (int) $branch['geofence_radius']) {
                echo json_encode(['ok' => false, 'error' => 'أنت خارج نطاق الفرع (المسافة: ' . round($dist) . 'م)']);
                exit;
            }

            $today = date('Y-m-d');
            $now = date('H:i:s');
            if ($type === 'in') {
                $existing = $pdo->prepare("SELECT check_in FROM attendance WHERE employee_id=? AND attendance_date=?");
                $existing->execute([$employeeId, $today]);
                if ($existing->fetchColumn()) {
                    echo json_encode(['ok' => false, 'error' => 'تم تسجيل حضورك اليوم مسبقاً']);
                    exit;
                }
                $settingsRow = $pdo->query("SELECT work_start_time, late_grace_minutes FROM settings ORDER BY id DESC LIMIT 1")->fetch();
                $empShiftStart = $pdo->prepare("SELECT shift_start FROM employees WHERE id=?");
                $empShiftStart->execute([$employeeId]);
                $empShiftStart = $empShiftStart->fetchColumn();
                if ($empShiftStart) { $settingsRow['work_start_time'] = $empShiftStart; }
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

        case 'my_requests': {
            $stmt = $pdo->prepare("SELECT * FROM requests WHERE employee_id=? ORDER BY created_at DESC LIMIT 50");
            $stmt->execute([$employeeId]);
            $rows = array_map(function ($r) {
                $details = $r['details'];
                if ($r['type'] === 'advance' && $r['amount']) {
                    $details = number_format((float) $r['amount']) . ' د.ع';
                } elseif (in_array($r['type'], ['leave', 'resignation'], true) && $r['date_from']) {
                    $details = $r['date_from'] . ' إلى ' . $r['date_to'];
                }
                $responseText = null;
                if ($r['status'] === 'branch_approved') {
                    $responseText = 'تمت الموافقة من مدير الفرع' . ($r['branch_reviewed_at'] ? (' في ' . date('d/m/Y', strtotime($r['branch_reviewed_at']))) : '') . ($r['branch_review_note'] ? (' — ' . $r['branch_review_note']) : '');
                } elseif ($r['status'] === 'approved') {
                    $responseText = 'تمت الموافقة النهائية من الموارد البشرية' . ($r['hr_reviewed_at'] ? (' في ' . date('d/m/Y', strtotime($r['hr_reviewed_at']))) : '') . ($r['hr_review_note'] ? (' — ' . $r['hr_review_note']) : '');
                } elseif ($r['status'] === 'rejected') {
                    $by = $r['hr_reviewed_at'] ? 'الموارد البشرية' : 'مدير الفرع';
                    $at = $r['hr_reviewed_at'] ?: $r['branch_reviewed_at'];
                    $note = $r['hr_review_note'] ?: $r['branch_review_note'];
                    $responseText = 'تم الرفض من ' . $by . ($at ? (' في ' . date('d/m/Y', strtotime($at))) : '') . ($note ? (' — ' . $note) : '');
                } else {
                    $responseText = 'بانتظار مراجعة مدير الفرع';
                }
                $isAdvanceApproved = $r['type'] === 'advance' && $r['status'] === 'approved';
                return [
                    'id' => (int) $r['id'],
                    'type' => request_type_ar_emp($r['type']),
                    'details' => $details ?: '-',
                    'status' => $r['status'],
                    'statusText' => request_status_ar_emp($r['status']),
                    'responseText' => $responseText,
                    'isAdvanceApproved' => $isAdvanceApproved,
                    'amount' => $isAdvanceApproved ? (float) $r['amount'] : null,
                    'monthlyDeduction' => $isAdvanceApproved ? (float) $r['approved_monthly_deduction'] : null,
                    'remainingBalance' => $isAdvanceApproved ? (float) $r['remaining_balance'] : null,
                ];
            }, $stmt->fetchAll());
            echo json_encode(['ok' => true, 'requests' => $rows], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'submit_request': {
            $typeAr = trim($_POST['requestType'] ?? '');
            $type = request_type_en($typeAr);
            $amount = null;
            $dateFrom = null;
            $dateTo = null;
            $details = trim($_POST['reason'] ?? '');

            if ($type === 'leave') {
                $leaveType = trim($_POST['leaveType'] ?? '');
                $dateFrom = $_POST['startDate'] ?: null;
                $dateTo = $_POST['endDate'] ?: null;
                $details = 'إجازة ' . $leaveType . ($details ? (' — ' . $details) : '');
                if (!$dateFrom || !$dateTo || $details === '') {
                    echo json_encode(['ok' => false, 'error' => 'الرجاء تعبئة جميع الحقول المطلوبة']);
                    exit;
                }
            } elseif ($type === 'advance') {
                $amount = (float) ($_POST['loanAmount'] ?? 0);
                $installments = trim($_POST['installments'] ?? '');
                $notes = trim($_POST['notes'] ?? '');
                $details .= ' (عدد الدفعات: ' . $installments . ')' . ($notes ? (' — ' . $notes) : '');
                if ($amount <= 0 || trim($_POST['reason'] ?? '') === '') {
                    echo json_encode(['ok' => false, 'error' => 'الرجاء تعبئة جميع الحقول المطلوبة']);
                    exit;
                }
            } elseif ($type === 'complaint') {
                $title = trim($_POST['complaintTitle'] ?? '');
                $details = $title . ($details ? (' — ' . $details) : '');
                if ($title === '' || trim($_POST['reason'] ?? '') === '') {
                    echo json_encode(['ok' => false, 'error' => 'الرجاء تعبئة جميع الحقول المطلوبة']);
                    exit;
                }
            } elseif ($type === 'resignation') {
                $dateFrom = $_POST['resignDate'] ?: null;
                $dateTo = $_POST['lastDay'] ?: null;
                $notes = trim($_POST['notes'] ?? '');
                $details .= $notes ? (' — ' . $notes) : '';
                if (!$dateFrom || !$dateTo || trim($_POST['reason'] ?? '') === '') {
                    echo json_encode(['ok' => false, 'error' => 'الرجاء تعبئة جميع الحقول المطلوبة']);
                    exit;
                }
            }

            $stmt = $pdo->prepare("INSERT INTO requests (employee_id, branch_id, type, details, amount, date_from, date_to, status)
                VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([$employeeId, $branchId, $type, $details, $amount, $dateFrom, $dateTo]);

            $mgrUids = $pdo->prepare("SELECT id FROM users WHERE role='branch_manager' AND branch_id=?");
            $mgrUids->execute([$branchId]);
            foreach ($mgrUids->fetchAll(PDO::FETCH_COLUMN) as $uid) {
                $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, 'طلب جديد بانتظار المراجعة', ?)")
                    ->execute([$uid, 'قدّم ' . $emp['full_name'] . ' طلب ' . request_type_ar_emp($type) . ' بانتظار مراجعتك']);
            }

            echo json_encode(['ok' => true]);
            exit;
        }

        case 'commitment': {
            $monthStart = date('Y-m-01');
            $stmt = $pdo->prepare("SELECT status, COUNT(*) AS c FROM attendance WHERE employee_id=? AND attendance_date >= ? GROUP BY status");
            $stmt->execute([$employeeId, $monthStart]);
            $present = 0; $late = 0; $absent = 0;
            foreach ($stmt->fetchAll() as $r) {
                if ($r['status'] === 'present') $present = (int) $r['c'];
                elseif ($r['status'] === 'late') $late = (int) $r['c'];
                elseif ($r['status'] === 'absent') $absent = (int) $r['c'];
            }
            $total = max(1, $present + $late + $absent);
            $pct = round((($present + $late) / $total) * 100);

            $prevMonthStart = date('Y-m-01', strtotime('-1 month'));
            $prevMonthEnd = date('Y-m-t', strtotime('-1 month'));
            $prevStmt = $pdo->prepare("SELECT SUM(status IN ('present','late')) AS ok_days, COUNT(*) AS total_days
                FROM attendance WHERE employee_id=? AND attendance_date BETWEEN ? AND ?");
            $prevStmt->execute([$employeeId, $prevMonthStart, $prevMonthEnd]);
            $prev = $prevStmt->fetch();
            $prevTotal = max(1, (int) ($prev['total_days'] ?? 0));
            $prevPct = (int) ($prev['total_days'] ?? 0) > 0 ? round((((int) ($prev['ok_days'] ?? 0)) / $prevTotal) * 100) : 0;

            $rating = 'ضعيف';
            if ($pct >= 90) $rating = 'ممتاز';
            elseif ($pct >= 75) $rating = 'جيد جداً';
            elseif ($pct >= 60) $rating = 'جيد';

            echo json_encode([
                'ok' => true,
                'pct' => $pct,
                'previousPct' => $prevPct,
                'rating' => $rating,
                'presentDays' => $present,
                'lateDays' => $late,
                'absentDays' => $absent,
                'totalDays' => $present + $late + $absent,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'salary': {
            $month = (int) date('n');
            $year = (int) date('Y');
            $stmt = $pdo->prepare("SELECT * FROM payroll WHERE employee_id=? AND period_month=? AND period_year=?");
            $stmt->execute([$employeeId, $month, $year]);
            $pay = $stmt->fetch();

            $empRow = $pdo->prepare("SELECT base_salary FROM employees WHERE id=?");
            $empRow->execute([$employeeId]);
            $baseSalary = (float) $empRow->fetchColumn();

            $bonus = $pay ? (float) $pay['bonus'] : 0.0;
            $deduction = $pay ? (float) $pay['deduction'] : 0.0;
            $base = $pay ? (float) $pay['base_salary'] : $baseSalary;
            $net = $base + $bonus - $deduction;
            $statusText = !$pay ? 'لم يُحتسب بعد' : ($pay['status'] === 'delivered' ? 'تم التسليم' : 'قيد المعالجة');

            echo json_encode([
                'ok' => true,
                'month' => $month,
                'year' => $year,
                'baseSalary' => $base,
                'adminBonus' => $bonus,
                'adminDeduction' => $deduction,
                'net' => $net,
                'statusText' => $statusText,
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'ledger_list': {
            if (!employee_active_delegation($pdo, $branchId, $employeeId)) {
                echo json_encode(['ok' => false, 'error' => 'غير مخوّل بهذه العملية']);
                exit;
            }
            $stmt = $pdo->prepare("SELECT id, entry_type, amount, description, attachment FROM daily_ledger WHERE branch_id=? AND entry_date=CURDATE() AND brief_id IS NULL ORDER BY created_at DESC");
            $stmt->execute([$branchId]);
            echo json_encode(['ok' => true, 'entries' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
            exit;
        }

        case 'ledger_add': {
            if (!employee_active_delegation($pdo, $branchId, $employeeId)) {
                echo json_encode(['ok' => false, 'error' => 'غير مخوّل بهذه العملية — لست مفوّضاً بكتابة إيجاز اليوم']);
                exit;
            }
            $type = ($_POST['type'] ?? '') === 'expense' ? 'expense' : 'income';
            $amount = (float) ($_POST['amount'] ?? 0);
            $note = trim($_POST['note'] ?? '');
            if ($amount <= 0) {
                echo json_encode(['ok' => false, 'error' => 'يرجى إدخال مبلغ صحيح']);
                exit;
            }
            $attachment = handle_upload('file', 'ledger', ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx']);
            $stmt = $pdo->prepare("INSERT INTO daily_ledger (branch_id, entry_date, entry_type, amount, description, attachment, created_by) VALUES (?, CURDATE(), ?, ?, ?, ?, ?)");
            $stmt->execute([$branchId, $type, $amount, $note, $attachment, $emp['id']]);
            echo json_encode(['ok' => true]);
            exit;
        }

        case 'briefing_publish_delegate': {
            if (!employee_active_delegation($pdo, $branchId, $employeeId)) {
                echo json_encode(['ok' => false, 'error' => 'غير مخوّل بهذه العملية — لست مفوّضاً بكتابة إيجاز اليوم']);
                exit;
            }
            $travelersCount = (int) ($_POST['travelersCount'] ?? 0);
            $note = trim($_POST['note'] ?? '');
            $attachment = handle_upload('attachment', 'briefs', ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx']);
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

            $stmt = $pdo->prepare("INSERT INTO daily_briefs (branch_id, brief_date, total_income, total_expense, previous_profit, travelers_count, note, attachment, status, submitted_by)
                VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, 'pending', ?)
                ON DUPLICATE KEY UPDATE total_income=VALUES(total_income), total_expense=VALUES(total_expense), travelers_count=VALUES(travelers_count),
                    note=VALUES(note), attachment=COALESCE(VALUES(attachment), attachment), status='pending', submitted_by=VALUES(submitted_by),
                    hr_decision='pending', gm_decision='pending', hr_note=NULL, gm_review_note=NULL, reviewed_by=NULL, reviewed_at=NULL, gm_reviewed_by=NULL, gm_reviewed_at=NULL");
            $stmt->execute([$branchId, $totals['income'], $totals['expense'], $previousProfit, $travelersCount, $note ?: null, $attachment, $employeeId]);

            $briefIdStmt = $pdo->prepare("SELECT id FROM daily_briefs WHERE branch_id=? AND brief_date=CURDATE()");
            $briefIdStmt->execute([$branchId]);
            $publishedBriefId = (int) $briefIdStmt->fetchColumn();
            $pdo->prepare("UPDATE daily_ledger SET brief_id=? WHERE branch_id=? AND entry_date=CURDATE()")->execute([$publishedBriefId, $branchId]);

            $branchName = $pdo->prepare("SELECT name FROM branches WHERE id=?");
            $branchName->execute([$branchId]);
            $branchName = $branchName->fetchColumn();
            $hrUids = $pdo->query("SELECT id FROM users WHERE role='hr'")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($hrUids as $uid) {
                $pdo->prepare("INSERT INTO notifications (user_id, title, message) VALUES (?, 'إيجاز جديد بانتظار المراجعة', ?)")
                    ->execute([$uid, 'نشر ' . $emp['full_name'] . ' (مفوّض) إيجاز فرع ' . $branchName . ' اليوم بانتظار مراجعتك']);
            }

            echo json_encode(['ok' => true]);
            exit;
        }

        case 'attendance_month': {
            $month = max(1, min(12, (int) ($_GET['month'] ?? date('n'))));
            $year = (int) ($_GET['year'] ?? date('Y'));
            $from = sprintf('%04d-%02d-01', $year, $month);
            $daysInMonth = (int) date('t', strtotime($from));
            $to = date('Y-m-t', strtotime($from));

            $rows = $pdo->prepare("SELECT attendance_date, check_in, check_out, status FROM attendance WHERE employee_id=? AND attendance_date BETWEEN ? AND ?");
            $rows->execute([$employeeId, $from, $to]);
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
                    'dow' => $dow,
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

        case 'branch_briefing': {
            $branchName = $pdo->prepare("SELECT name FROM branches WHERE id=?");
            $branchName->execute([$branchId]);
            $branchName = $branchName->fetchColumn();

            $stmt = $pdo->prepare("SELECT * FROM daily_briefs WHERE branch_id=? AND brief_date=CURDATE()");
            $stmt->execute([$branchId]);
            $brief = $stmt->fetch();

            if (!$brief) {
                echo json_encode(['ok' => true, 'exists' => false, 'branch' => $branchName, 'date' => date('d / m / Y')]);
                exit;
            }

            if ($brief['status'] !== 'approved') {
                $pendingMap = [
                    'pending' => 'الإيجاز قيد مراجعة الموارد البشرية والمسؤول العام، سيظهر بعد الاعتماد النهائي',
                    'hr_approved' => 'وافقت الموارد البشرية — بانتظار الاعتماد النهائي من المسؤول العام',
                    'gm_approved' => 'وافق المسؤول العام — بانتظار الاعتماد النهائي من الموارد البشرية',
                    'rejected' => 'تم رفض إيجاز اليوم',
                ];
                echo json_encode([
                    'ok' => true,
                    'exists' => true,
                    'pendingApproval' => true,
                    'branch' => $branchName,
                    'date' => date('d / m / Y'),
                    'statusText' => $pendingMap[$brief['status']] ?? 'قيد الاعتماد',
                ], JSON_UNESCAPED_UNICODE);
                exit;
            }

            echo json_encode([
                'ok' => true,
                'exists' => true,
                'pendingApproval' => false,
                'branch' => $branchName,
                'date' => date('d / m / Y'),
                'totalIncome' => (float) $brief['total_income'],
                'totalExpense' => (float) $brief['total_expense'],
                'travelersCount' => (int) $brief['travelers_count'],
                'profit' => (float) $brief['total_income'] - (float) $brief['total_expense'],
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }

    echo json_encode(['ok' => false, 'error' => 'unknown action']);
    exit;
    } catch (Throwable $ex) {
        log_error($pdo, $action, 'employee', $emp['id'], $ex->getMessage());
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
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>شركة الصوى للصرافة - نافذة الموظف</title>
    <link rel="manifest" href="manifest.php?app=employee">
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
            --bg: #F5F7FA;
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
            transition: background var(--transition-base), color var(--transition-base);
            -webkit-font-smoothing: antialiased;
            font-size: 14px;
        }

        .hidden { display: none !important; }

        /* ============================================================
           شاشة الترحيب
           ============================================================ */
        .welcome-screen {
            position: fixed;
            inset: 0;
            z-index: 9999;
            background: #004b52;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            animation: fadeIn 0.8s ease;
            transition: opacity 0.8s ease, transform 0.8s ease;
        }
        .welcome-screen.fade-out {
            opacity: 0;
            transform: translateX(-50%) scale(1.05);
            pointer-events: none;
        }
        @keyframes fadeIn {
            0% { opacity: 0; transform: translateX(-50%) scale(1.02); }
            100% { opacity: 1; transform: translateX(-50%) scale(1); }
        }

        .welcome-logo {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            flex: 1;
            width: 100%;
        }
        .welcome-logo .logo-icon {
            width: 130px;
            height: 130px;
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            border-radius: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 56px;
            font-weight: 900;
            box-shadow: 0 12px 48px rgba(0,0,0,0.3);
            margin-bottom: 16px;
            overflow: hidden;
        }
        .welcome-logo .logo-icon img { width: 100%; height: 100%; object-fit: cover; }
        .welcome-logo h1 {
            color: #fff;
            font-size: 26px;
            font-weight: 900;
            letter-spacing: -0.5px;
            text-align: center;
        }
        .welcome-logo h1 span { color: var(--accent); }

        .welcome-loader {
            width: 200px;
            max-width: 60%;
            margin-top: 12px;
            padding: 8px 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 6px;
        }
        .welcome-loader .loader-label {
            color: rgba(255,255,255,0.5);
            font-size: 11px;
            font-weight: 400;
        }
        .welcome-loader .loader-bar-wrapper {
            width: 100%;
            height: 3px;
            background: rgba(255,255,255,0.1);
            border-radius: 10px;
            overflow: hidden;
            position: relative;
        }
        .welcome-loader .loader-bar {
            height: 100%;
            width: 0%;
            background: linear-gradient(90deg, var(--accent), var(--primary-light));
            border-radius: 10px;
            transition: width 0.3s ease;
            position: relative;
        }
        .welcome-loader .loader-bar::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            animation: shimmer 1.8s infinite;
        }
        @keyframes shimmer {
            0% { transform: translateX(-100%); }
            100% { transform: translateX(100%); }
        }


        /* ============================================================
           شاشة تسجيل الدخول
           ============================================================ */
        .login-page {
            min-height: 100vh;
            background: linear-gradient(135deg, #004b52 0%, #006b73 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .login-card {
            background: #FFFFFF;
            border-radius: var(--radius-lg);
            padding: 40px 32px;
            max-width: 420px;
            width: 100%;
            box-shadow: var(--shadow-xl);
            border: 1px solid rgba(255,255,255,0.08);
            direction: rtl;
        }

        .login-card .login-logo {
            text-align: center;
            margin-bottom: 28px;
        }
        .login-card .login-logo .logo-icon {
            width: 72px; height: 72px;
            background: var(--primary-gradient);
            border-radius: var(--radius-lg);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 32px;
            font-weight: 900;
            margin-bottom: 12px;
            box-shadow: 0 8px 32px rgba(0,107,115,0.4);
            overflow: hidden;
        }
        .login-card .login-logo h2 {
            font-size: 24px;
            font-weight: 900;
            color: #1A2E35;
            letter-spacing: -0.5px;
        }
        .login-card .login-logo h2 span { color: #006b73; }
        .login-card .login-logo p {
            color: var(--text-muted);
            font-size: 14px;
            margin-top: 4px;
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
            height: 50px;
            padding: 0 16px;
            border: 2px solid rgba(0,107,115,0.08);
            border-radius: var(--radius-sm);
            font-size: 14px;
            background: var(--bg);
            color: var(--text-primary);
            transition: var(--transition-base);
            font-family: var(--font-family);
            outline: none;
            text-align: right;
        }
        .login-card .form-group input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(0,107,115,0.06);
        }

        .login-card .btn-login {
            width: 100%;
            height: 50px;
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
            margin-top: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .login-card .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(0,107,115,0.35);
        }
        .login-card .btn-login:active { transform: scale(0.97); }
        .login-card .btn-login:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            transform: none !important;
        }

        .login-card .login-error {
            color: #EF4444;
            font-size: 13px;
            text-align: center;
            margin-top: 12px;
            display: none;
        }
        .login-card .login-toggle {
            margin-top: 16px;
            text-align: center;
            font-size: 13px;
            color: var(--text-muted);
            font-weight: 400;
        }
        .login-card .login-toggle a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 700;
            cursor: pointer;
        }
        .login-card .login-toggle a:hover { text-decoration: underline; }

        /* ============================================================
           الهيدر
           ============================================================ */
        .header-glass {
            background: rgba(255,255,255,0.92);
            backdrop-filter: blur(24px) saturate(180%);
            -webkit-backdrop-filter: blur(24px) saturate(180%);
            padding: 12px 24px;
            height: var(--header-height);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
            border-bottom: 1px solid rgba(0,0,0,0.04);
            box-shadow: 0 2px 20px rgba(0,0,0,0.04);
            transition: var(--transition-base);
        }

        .header-glass .brand { display: flex; align-items: center; gap: 12px; }
        .header-glass .brand .logo {
            width: 40px; height: 40px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-size: 18px;
            font-weight: 900;
            box-shadow: 0 4px 16px rgba(0,107,115,0.25);
            overflow: hidden;
            background: var(--primary-gradient);
        }
        .header-glass .brand .name {
            font-size: 18px;
            font-weight: 900;
            color: var(--text-primary);
            letter-spacing: -0.5px;
        }
        .header-glass .brand .name span {
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        .header-glass .brand .role-badge {
            font-size: 9px; font-weight: 800;
            padding: 4px 14px;
            border-radius: var(--radius-full);
            background: var(--primary-gradient);
            color: #fff;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            box-shadow: 0 2px 12px rgba(0,107,115,0.25);
            border: 1px solid rgba(255,255,255,0.15);
        }

        .header-glass .actions { display: flex; align-items: center; gap: 6px; }
        .header-glass .actions .icon-btn {
            width: 42px; height: 42px;
            border-radius: var(--radius-full);
            border: none;
            background: rgba(0,0,0,0.03);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-secondary);
            cursor: pointer;
            transition: var(--transition-base);
            font-size: 18px;
            position: relative;
            border: 1px solid rgba(0,0,0,0.04);
        }
        .header-glass .actions .icon-btn:hover {
            background: rgba(0,0,0,0.06);
            color: var(--primary);
            transform: scale(1.05);
        }

        /* ============================================================
           الصفحات الداخلية
           ============================================================ */
        .page-content {
            max-width: 480px;
            margin: 0 auto;
            padding: 16px;
            padding-bottom: 80px;
        }

        .page-title {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
        }
        .page-title h2 {
            font-size: 20px;
            font-weight: 800;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .page-title h2 i { color: var(--primary); }

        .card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid rgba(0,107,115,0.04);
            box-shadow: var(--shadow-sm);
            padding: 20px;
            margin-bottom: 14px;
            transition: var(--transition-base);
        }
        .card:hover {
            box-shadow: var(--shadow-md);
            border-color: rgba(0,107,115,0.06);
        }

        .form-group { margin-bottom: 14px; }
        .form-group:last-of-type { margin-bottom: 0; }
        .form-group label {
            display: block;
            font-size: 12.5px;
            font-weight: 700;
            color: var(--text-secondary);
            margin-bottom: 6px;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            height: 46px;
            padding: 0 14px;
            border: 2px solid rgba(0,107,115,0.08);
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-family: var(--font-family);
            background: var(--bg);
            color: var(--text-primary);
            transition: var(--transition-base);
        }
        .form-group textarea { height: auto; padding: 12px 14px; resize: vertical; }
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(0,107,115,0.06);
        }

        .settings-card-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 16px;
        }
        .settings-card-header .icon-badge {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            background: rgba(0,107,115,0.08);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            flex-shrink: 0;
        }
        .settings-card-header .icon-badge.danger { background: rgba(239,68,68,0.1); color: #EF4444; }
        .settings-card-header .titles .t1 { font-size: 14px; font-weight: 800; color: var(--text-primary); }
        .settings-card-header .titles .t2 { font-size: 11.5px; color: var(--text-muted); margin-top: 1px; }
        .logout-card {
            display: flex;
            align-items: center;
            gap: 12px;
            cursor: pointer;
            border-color: rgba(239,68,68,0.15);
        }
        .logout-card .chev { margin-right: auto; color: rgba(239,68,68,0.5); }

        .section-title {
            font-weight: 800;
            margin: 17px 2px 10px;
            font-size: 15px;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .section-title i { color: var(--primary); }

        /* ============================================================
           الملف الشخصي
           ============================================================ */
        .profile-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid rgba(0,107,115,0.04);
            box-shadow: var(--shadow-sm);
            padding: 20px;
            margin-bottom: 14px;
            display: flex;
            align-items: center;
            gap: 16px;
            transition: var(--transition-base);
        }
        .profile-card .avatar {
            width: 64px; height: 64px;
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            font-weight: 900;
            flex-shrink: 0;
            box-shadow: 0 4px 16px rgba(0,107,115,0.2);
            overflow: hidden;
            background: var(--primary-gradient);
            color: #fff;
        }
        .profile-card .info { flex: 1; }
        .profile-card .info .name {
            font-size: 18px;
            font-weight: 800;
            color: var(--text-primary);
        }
        .profile-card .info .email {
            font-size: 13px;
            color: var(--text-muted);
        }
        .profile-card .info .role {
            font-size: 12px;
            font-weight: 600;
            color: var(--primary);
            background: rgba(0,107,115,0.06);
            padding: 2px 12px;
            border-radius: var(--radius-full);
            display: inline-block;
            margin-top: 2px;
        }

        /* ============================================================
           الإحصائيات
           ============================================================ */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 8px;
            margin-bottom: 14px;
        }
        .stat-item {
            background: var(--bg-card);
            border-radius: var(--radius-md);
            padding: 12px 6px;
            text-align: center;
            border: 1px solid rgba(0,107,115,0.04);
            box-shadow: var(--shadow-sm);
            transition: var(--transition-base);
        }
        .stat-item .num {
            font-size: 22px;
            font-weight: 900;
            color: var(--text-primary);
            line-height: 1.2;
        }
        .stat-item .num.green { color: #059669; }
        .stat-item .num.primary { color: var(--primary); }
        .stat-item .num.orange { color: #D97706; }
        .stat-item .label {
            font-size: 10px;
            color: var(--text-muted);
            margin-top: 3px;
            font-weight: 500;
        }

        /* ============================================================
           بطاقة لائحة الشرف
           ============================================================ */
        .honor-card {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 2px solid var(--accent);
            box-shadow: 0 4px 24px rgba(201,154,61,0.12);
            padding: 16px 20px;
            margin-bottom: 14px;
            overflow: hidden;
            position: relative;
        }
        .honor-card .honor-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 12px;
        }
        .honor-card .honor-header h4 {
            font-size: 15px;
            font-weight: 800;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .honor-card .honor-header h4 i { color: var(--accent); }
        .honor-card .honor-header .badge-gold {
            font-size: 10px;
            font-weight: 700;
            padding: 3px 12px;
            border-radius: var(--radius-full);
            background: var(--accent);
            color: #fff;
        }

        .honor-slider {
            display: flex;
            transition: transform 0.6s cubic-bezier(0.4, 0, 0.2, 1);
            width: 300%;
        }
        .honor-slide {
            width: 33.333%;
            padding: 4px 0;
            flex-shrink: 0;
        }
        .honor-slide .honor-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 14px;
            background: rgba(201,154,61,0.04);
            border-radius: var(--radius-md);
            border: 1px solid rgba(201,154,61,0.08);
        }
        .honor-slide .honor-item .medal {
            font-size: 28px;
            width: 40px;
            text-align: center;
            flex-shrink: 0;
        }
        .honor-slide .honor-item .avatar {
            width: 44px; height: 44px;
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            border: 2px solid rgba(201,154,61,0.15);
            background: var(--primary-gradient);
            color: #fff;
            font-size: 18px;
            font-weight: 900;
        }
        .honor-slide .honor-item .info { flex: 1; }
        .honor-slide .honor-item .info .name {
            font-size: 14px;
            font-weight: 800;
            color: var(--text-primary);
        }
        .honor-slide .honor-item .info .title {
            font-size: 11px;
            color: var(--text-muted);
        }
        .honor-slide .honor-item .percent {
            font-size: 18px;
            font-weight: 900;
            color: #059669;
            flex-shrink: 0;
        }

        .honor-dots {
            display: flex;
            justify-content: center;
            gap: 8px;
            margin-top: 12px;
        }
        .honor-dots .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--text-muted);
            opacity: 0.3;
            transition: var(--transition-base);
            cursor: pointer;
            border: none;
            padding: 0;
        }
        .honor-dots .dot.active {
            opacity: 1;
            background: var(--accent);
            width: 24px;
            border-radius: 4px;
        }

        /* ============================================================
           أزرار البصمة
           ============================================================ */
        .fingerprint-buttons {
            display: flex;
            gap: 16px;
            margin: 14px 0;
        }
        .fingerprint-btn {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 20px 12px;
            border: none;
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: var(--transition-base);
            font-family: var(--font-family);
            position: relative;
            min-height: 130px;
            box-shadow: var(--shadow-sm);
            color: #fff;
        }
        .fingerprint-btn .fingerprint-icon {
            font-size: 48px;
            margin-bottom: 6px;
            position: relative;
            z-index: 1;
        }
        .fingerprint-btn .fingerprint-label {
            font-size: 14px;
            font-weight: 700;
            position: relative;
            z-index: 1;
        }
        .fingerprint-btn .fingerprint-sub {
            font-size: 11px;
            font-weight: 400;
            opacity: 0.8;
            position: relative;
            z-index: 1;
        }
        .fingerprint-btn .fingerprint-time {
            font-size: 12px;
            font-weight: 600;
            margin-top: 4px;
            position: relative;
            z-index: 1;
        }

        .fingerprint-btn-checkin {
            background: linear-gradient(135deg, #10B981, #059669);
            box-shadow: 0 4px 16px rgba(16,185,129,0.3);
        }
        .fingerprint-btn-checkin:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 8px 28px rgba(16,185,129,0.4);
        }
        .fingerprint-btn-checkout {
            background: linear-gradient(135deg, #EF4444, #DC2626);
            box-shadow: 0 4px 16px rgba(239,68,68,0.3);
        }
        .fingerprint-btn-checkout:hover:not(:disabled) {
            transform: translateY(-3px);
            box-shadow: 0 8px 28px rgba(239,68,68,0.4);
        }
        .fingerprint-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none !important;
        }
        .fingerprint-btn .spinner-small {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 0.8s linear infinite;
            position: relative;
            z-index: 1;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        /* ============================================================
           أيام الدوام
           ============================================================ */
        .work-schedule {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid rgba(0,107,115,0.04);
            padding: 16px 20px;
            margin-bottom: 14px;
            box-shadow: var(--shadow-sm);
        }
        .work-schedule .schedule-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .work-schedule .schedule-header h4 {
            font-size: 14px;
            font-weight: 800;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .work-schedule .schedule-header h4 i { color: var(--primary); }

        .work-days {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }
        .work-days .day {
            padding: 4px 12px;
            border-radius: var(--radius-full);
            font-size: 11px;
            font-weight: 700;
            background: rgba(0,107,115,0.04);
            color: var(--text-muted);
            transition: var(--transition-base);
        }
        .work-days .day.active {
            background: var(--primary-gradient);
            color: #fff;
            box-shadow: 0 2px 12px rgba(0,107,115,0.2);
        }
        .work-days .day.today {
            border: 2px solid var(--primary);
            color: var(--primary);
            background: rgba(0,107,115,0.04);
        }
        .work-days .day.inactive {
            opacity: 0.4;
        }

        .work-hours {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            margin-top: 10px;
            padding-top: 10px;
            border-top: 1px solid rgba(0,107,115,0.04);
            flex-wrap: wrap;
        }
        .work-hours .time-block {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
        }
        .work-hours .time-block i { color: var(--primary); }
        .work-hours .time-block .time-value {
            color: var(--text-primary);
            font-weight: 800;
        }
        .work-hours .countdown {
            font-size: 13px;
            font-weight: 700;
            color: var(--primary);
            padding: 4px 14px;
            background: rgba(0,107,115,0.06);
            border-radius: var(--radius-full);
        }

        /* ============================================================
           القائمة السريعة
           ============================================================ */
        .quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-bottom: 14px;
        }
        .quick-action-btn {
            padding: 14px 8px;
            border-radius: var(--radius-md);
            border: 2px solid rgba(0,107,115,0.06);
            background: var(--bg-card);
            text-align: center;
            cursor: pointer;
            transition: var(--transition-base);
            font-weight: 700;
            font-size: 11px;
            color: var(--text-primary);
            font-family: var(--font-family);
            box-shadow: var(--shadow-sm);
        }
        .quick-action-btn:hover {
            border-color: var(--primary);
            background: rgba(0,107,115,0.02);
            transform: translateY(-3px);
            box-shadow: var(--shadow-md);
        }
        .quick-action-btn i {
            font-size: 22px;
            display: block;
            margin-bottom: 4px;
            color: var(--primary);
        }
        .quick-action-btn .badge {
            display: inline-block;
            font-size: 9px;
            font-weight: 800;
            padding: 1px 8px;
            border-radius: var(--radius-full);
            background: #EF4444;
            color: #fff;
            margin-top: 2px;
        }
        .quick-action-btn .sub-text {
            font-size: 9px;
            color: var(--text-muted);
            font-weight: 400;
            display: block;
            margin-top: 2px;
        }

        /* ============================================================
           الإشعارات المصغرة
           ============================================================ */
        .mini-notifications {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            border: 1px solid rgba(0,107,115,0.04);
            padding: 16px 18px;
            margin-bottom: 14px;
            box-shadow: var(--shadow-sm);
            cursor: pointer;
            transition: var(--transition-base);
        }
        .mini-notifications .mini-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
        }
        .mini-notifications .mini-header h4 {
            font-size: 14px;
            font-weight: 800;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .mini-notifications .mini-header h4 i { color: var(--primary); }
        .mini-notifications .mini-header .view-all {
            font-size: 11px;
            color: var(--text-muted);
            font-weight: 600;
        }
        .mini-notification-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 8px 0;
            border-bottom: 1px solid rgba(0,107,115,0.04);
        }
        .mini-notification-item:last-child { border-bottom: 0; }
        .mini-notification-item .notif-icon {
            width: 32px; height: 32px;
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 13px;
            flex-shrink: 0;
            background: var(--primary-gradient);
            color: #fff;
            font-weight: 900;
        }
        .mini-notification-item .notif-icon.success {
            background: rgba(16,185,129,0.12);
            color: #059669;
        }
        .mini-notification-item .notif-icon.info {
            background: rgba(0,107,115,0.08);
            color: var(--primary);
        }
        .mini-notification-item .notif-content { flex: 1; }
        .mini-notification-item .notif-content .notif-title {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-primary);
        }
        .mini-notification-item .notif-content .notif-message {
            font-size: 11px;
            color: var(--text-muted);
            font-weight: 400;
        }
        .mini-notification-item .notif-time {
            font-size: 10px;
            color: var(--text-muted);
            font-weight: 400;
            flex-shrink: 0;
        }

        /* ============================================================
           الجداول
           ============================================================ */
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
            padding: 10px 5px;
            border-bottom: 1px solid rgba(0,107,115,0.04);
            text-align: right;
        }
        .table th {
            color: var(--text-muted);
            font-weight: 500;
            font-size: 12px;
        }

        /* ============================================================
           عناصر القوائم
           ============================================================ */
        .list-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 0;
            border-bottom: 1px solid rgba(0,107,115,0.04);
        }
        .list-item:last-child { border-bottom: 0; }
        .list-item .item-icon {
            width: 40px; height: 40px;
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
            background: rgba(0,107,115,0.06);
            color: var(--primary);
        }
        .list-item .item-content { flex: 1; }
        .list-item .item-content .item-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-primary);
        }
        .list-item .item-content .item-desc {
            font-size: 12px;
            color: var(--text-muted);
            font-weight: 400;
        }
        .list-item .item-badge {
            font-size: 10px;
            font-weight: 700;
            padding: 3px 12px;
            border-radius: var(--radius-full);
        }
        .list-item .item-badge.ok {
            background: rgba(16,185,129,0.12);
            color: #059669;
        }
        .list-item .item-badge.wait {
            background: rgba(217,119,6,0.12);
            color: #D97706;
        }
        .list-item .item-badge.danger {
            background: rgba(239,68,68,0.12);
            color: #DC2626;
        }

        /* ============================================================
           الإشعارات الكاملة
           ============================================================ */
        .notification-item-full {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 0;
            border-bottom: 1px solid rgba(0,107,115,0.04);
        }
        .notification-item-full:last-child { border-bottom: 0; }
        .notification-item-full .notif-icon {
            width: 44px; height: 44px;
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
            background: var(--primary-gradient);
            color: #fff;
            font-weight: 900;
        }
        .notification-item-full .notif-icon.success {
            background: rgba(16,185,129,0.12);
            color: #059669;
        }
        .notification-item-full .notif-icon.info {
            background: rgba(0,107,115,0.08);
            color: var(--primary);
        }
        .notification-item-full .notif-content { flex: 1; }
        .notification-item-full .notif-content .notif-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-primary);
        }
        .notification-item-full .notif-content .notif-message {
            font-size: 13px;
            color: var(--text-muted);
            font-weight: 400;
        }
        .notification-item-full .notif-time {
            font-size: 11px;
            color: var(--text-muted);
            font-weight: 400;
            flex-shrink: 0;
        }

        /* ============================================================
           نافذة تقديم الطلب
           ============================================================ */
        .request-modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            backdrop-filter: blur(8px);
            -webkit-backdrop-filter: blur(8px);
            z-index: 500;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 20px;
            animation: fadeInModal 0.3s ease;
        }
        .request-modal-overlay.show { display: flex; }
        @keyframes fadeInModal {
            0% { opacity: 0; }
            100% { opacity: 1; }
        }

        .request-modal {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            max-width: 480px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            box-shadow: var(--shadow-xl);
            animation: slideUp 0.4s cubic-bezier(0.34, 1.56, 0.64, 1);
            padding: 0;
        }
        @keyframes slideUp {
            0% { opacity: 0; transform: translateY(40px) scale(0.96); }
            100% { opacity: 1; transform: translateY(0) scale(1); }
        }
        /* نسخة خاصة بالبطاقات المثبّتة أفقياً في منتصف الشاشة (left:50%+translateX(-50%))
           حتى لا يفقد العنصر توسيطه الأفقي أثناء الحركة ويبدو وكأنه يخرج من اليمين */
        @keyframes slideUpCentered {
            0% { opacity: 0; transform: translateX(-50%) translateY(40px) scale(0.96); }
            100% { opacity: 1; transform: translateX(-50%) translateY(0) scale(1); }
        }

        .request-modal .modal-header {
            padding: 18px 22px;
            border-bottom: 1px solid rgba(0,107,115,0.04);
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            background: var(--bg-card);
            z-index: 1;
            border-radius: var(--radius-lg) var(--radius-lg) 0 0;
        }
        .request-modal .modal-header h3 {
            font-size: 18px;
            font-weight: 800;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .request-modal .modal-header h3 i { color: var(--primary); }
        .request-modal .modal-header .close-btn {
            width: 36px; height: 36px;
            border: none;
            border-radius: var(--radius-full);
            background: rgba(0,107,115,0.06);
            color: var(--text-muted);
            cursor: pointer;
            transition: var(--transition-base);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        .request-modal .modal-header .close-btn:hover {
            background: rgba(0,107,115,0.12);
            color: var(--primary);
            transform: rotate(90deg);
        }
        .request-modal .modal-body { padding: 22px; }
        .request-modal .modal-body .form-group { margin-bottom: 16px; }
        .request-modal .modal-body .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            color: var(--text-secondary);
            margin-bottom: 6px;
        }
        .request-modal .modal-body .form-group label .required {
            color: #EF4444;
            margin-right: 2px;
        }
        .request-modal .modal-body .form-group input,
        .request-modal .modal-body .form-group select,
        .request-modal .modal-body .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            border: 2px solid rgba(0,107,115,0.08);
            border-radius: var(--radius-sm);
            font-size: 14px;
            background: var(--bg);
            color: var(--text-primary);
            transition: var(--transition-base);
            font-family: var(--font-family);
            outline: none;
        }
        .request-modal .modal-body .form-group input:focus,
        .request-modal .modal-body .form-group select:focus,
        .request-modal .modal-body .form-group textarea:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(0,107,115,0.06);
        }
        .request-modal .modal-body .form-group textarea {
            min-height: 80px;
            resize: vertical;
        }
        .request-modal .modal-body .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }
        .request-modal .modal-body .btn-submit-request {
            width: 100%;
            height: 50px;
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
            margin-top: 8px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .request-modal .modal-body .btn-submit-request:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(0,107,115,0.35);
        }
        .request-modal .modal-body .btn-submit-request:active { transform: scale(0.97); }

        /* ============================================================
           الشريط السفلي
           ============================================================ */
        .bottom-nav-minimal {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            height: var(--nav-height);
            min-height: var(--nav-height);
            padding: 6px 8px 12px;
            background: var(--bg-card);
            border-top: 2px solid rgba(0,107,115,0.04);
            box-shadow: 0 -4px 30px rgba(0,0,0,0.02);
            display: flex;
            justify-content: space-around;
            align-items: center;
            transition: var(--transition-base);
            z-index: 200;
            gap: 2px;
        }
        .bottom-nav-minimal .nav-item {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 2px;
            padding: 4px 2px;
            min-height: 48px;
            border-radius: var(--radius-sm);
            transition: var(--transition-base);
            background: transparent;
            border: none;
            cursor: pointer;
            font-size: 10px;
            font-weight: 700;
            color: var(--text-muted);
            position: relative;
            font-family: var(--font-family);
        }
        .bottom-nav-minimal .nav-item i {
            font-size: 20px;
            transition: var(--transition-base);
        }
        .bottom-nav-minimal .nav-item.active {
            color: var(--primary);
            background: rgba(0,107,115,0.04);
        }
        .bottom-nav-minimal .nav-item.active i {
            transform: translateY(-2px);
        }
        .bottom-nav-minimal .nav-item .nav-badge {
            position: absolute;
            top: 0;
            right: 50%;
            transform: translateX(50%);
            min-width: 18px;
            height: 18px;
            padding: 0 6px;
            border-radius: var(--radius-full);
            background: #EF4444;
            color: #fff;
            font-size: 9px;
            font-weight: 800;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .bottom-nav-minimal .nav-item.nav-locked {
            opacity: 0.45;
        }
        .bottom-nav-minimal .nav-item .nav-lock-icon {
            position: absolute;
            top: 2px;
            right: 50%;
            transform: translateX(14px);
            font-size: 9px !important;
            color: var(--text-muted);
            background: var(--bg-card);
            border-radius: 50%;
            width: 14px;
            height: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* ============================================================
           التوست
           ============================================================ */
        .toast-container {
            position: fixed;
            top: 20px;
            left: 50%;
            transform: translateX(-50%);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            gap: 10px;
            align-items: center;
            pointer-events: none;
            width: 100%;
            max-width: 400px;
            padding: 0 16px;
        }
        .toast {
            background: var(--bg-card);
            border-radius: var(--radius-lg);
            padding: 16px 20px;
            box-shadow: var(--shadow-xl);
            border: 1px solid rgba(0,107,115,0.04);
            pointer-events: auto;
            max-width: 100%;
            width: 100%;
            font-family: var(--font-family);
            display: flex;
            align-items: flex-start;
            gap: 14px;
            opacity: 0;
            transform: translateY(-80px) scale(0.9);
            transition: all 0.5s cubic-bezier(0.34, 1.56, 0.64, 1);
            position: relative;
            overflow: hidden;
            font-weight: 800;
            font-size: 14px;
            cursor: pointer;
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
            width: 5px;
            height: 100%;
            border-radius: 0 4px 4px 0;
        }
        .toast.success::before { background: #10B981; }
        .toast.info::before { background: var(--primary); }
        .toast.warning::before { background: #F59E0B; }
        .toast.error::before { background: #EF4444; }

        .toast .toast-icon {
            width: 44px; height: 44px;
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            flex-shrink: 0;
            margin-top: 2px;
            position: relative;
            font-weight: 800;
            background: var(--primary-gradient);
            color: #fff;
        }
        .toast .toast-icon.success {
            background: rgba(16,185,129,0.15);
            color: #059669;
        }
        .toast .toast-icon.info {
            background: rgba(0,107,115,0.12);
            color: var(--primary);
        }
        .toast .toast-icon.warning {
            background: rgba(251,191,36,0.15);
            color: #D97706;
        }
        .toast .toast-icon.error {
            background: rgba(239,68,68,0.12);
            color: #EF4444;
        }
        .toast .toast-content { flex: 1; min-width: 0; }
        .toast .toast-content .toast-title {
            font-size: 14px;
            font-weight: 800;
            color: var(--text-primary);
            margin-bottom: 2px;
        }
        .toast .toast-content .toast-message {
            font-size: 13px;
            font-weight: 400;
            color: var(--text-muted);
            line-height: 1.5;
        }

        /* ============================================================
           أدوات مساعدة
           ============================================================ */
        .back-btn {
            height: 40px;
            padding: 0 18px;
            border: 2px solid rgba(0,107,115,0.08);
            border-radius: var(--radius-md);
            background: transparent;
            color: var(--text-muted);
            font-size: 13px;
            font-weight: 700;
            cursor: pointer;
            transition: var(--transition-base);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-family: var(--font-family);
        }
        .back-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: rgba(0,107,115,0.02);
        }

        /* ============================================================
           التجاوب
           ============================================================ */
        @media (max-width: 480px) {
            :root {
                --header-height: 58px;
                --nav-height: 66px;
            }
            .header-glass { padding: 10px 16px; }
            .header-glass .brand .name { font-size: 16px; }
            .header-glass .brand .role-badge { font-size: 8px; padding: 2px 10px; }
            .header-glass .brand .logo { width: 34px; height: 34px; font-size: 16px; }
            .header-glass .actions .icon-btn { width: 36px; height: 36px; font-size: 16px; }
            .page-title h2 { font-size: 18px; }
            .stats-row { grid-template-columns: repeat(2, 1fr); gap: 6px; }
            .quick-actions-grid { grid-template-columns: repeat(2, 1fr); gap: 8px; }
            .bottom-nav-minimal .nav-item { font-size: 9px; min-height: 42px; }
            .bottom-nav-minimal .nav-item i { font-size: 18px; }
            .login-card { padding: 28px 20px; }
            .profile-card .avatar { width: 50px; height: 50px; font-size: 22px; }
            .fingerprint-buttons { flex-direction: column; gap: 10px; }
            .fingerprint-btn { min-height: 100px; padding: 16px 12px; }
            .fingerprint-btn .fingerprint-icon { font-size: 38px; }
            .work-hours { flex-wrap: wrap; gap: 8px; }
            .request-modal .modal-body .form-row { grid-template-columns: 1fr; }
            .honor-slide .honor-item { padding: 8px 10px; }
            .honor-slide .honor-item .medal { font-size: 22px; width: 30px; }
            .honor-slide .honor-item .avatar { width: 36px; height: 36px; }
            .honor-slide .honor-item .percent { font-size: 15px; }
            .welcome-loader .loader-label { font-size: 11px; }
        }

        @media (max-width: 380px) {
            .bottom-nav-minimal .nav-item span { display: none; }
            .bottom-nav-minimal .nav-item i { font-size: 20px; }
            .header-glass .brand .role-badge { display: none; }
            .stats-row { grid-template-columns: 1fr 1fr; gap: 4px; }
            .quick-actions-grid { grid-template-columns: 1fr 1fr; gap: 6px; }
            .stat-item .num { font-size: 18px; }
            .work-days .day { font-size: 10px; padding: 3px 8px; }
        }

        /* ============================================================
           إطار تطبيق الجوال — على الشاشات الواسعة يظهر النظام كأنه
           تطبيق هاتف حقيقي (عرض ثابت 480px بمنتصف الشاشة) بدل موقع ويب ممتد
           ============================================================ */
        /* إطار تطبيق ثابت العرض (480px كحد أقصى، ويتقلص تلقائياً على الجوال) —
           بنفس أسلوب app-wrapper: يبدو دائماً كتطبيق حقيقي وليس موقع ويب ممتد */
        body {
            background: var(--primary-dark);
        }
        .welcome-screen,
        .login-page {
            left: 50% !important;
            right: auto !important;
            width: 480px !important;
            max-width: 100% !important;
            transform: translateX(-50%);
            box-shadow: 0 0 0 1px rgba(255,255,255,0.08), 0 30px 80px rgba(0,0,0,0.45);
        }
        .welcome-screen, .login-page {
            position: fixed;
            top: 0;
            bottom: 0;
        }
        /* بدون transform هنا عمداً: أي transform على #appContainer يجعله containing block
           لعناصر position:fixed بداخله (شريط التنقل السفلي، القائمة الجانبية...)
           فتصبح "ثابتة" بالنسبة له بدل نافذة العرض، فتحتاج تمرير الصفحة لأسفل لرؤيتها. */
        #appContainer {
            width: 480px !important;
            max-width: 100% !important;
            box-shadow: 0 0 0 1px rgba(255,255,255,0.08), 0 30px 80px rgba(0,0,0,0.45);
            position: relative;
            margin: 0 auto;
            min-height: 100vh;
        }
        .bottom-nav-minimal,
        #sideMenu,
        #pwaInstallBanner,
        #confirmSheet {
            left: 50% !important;
            right: auto !important;
            width: 480px !important;
            max-width: 100% !important;
            transform: translateX(-50%);
        }
    </style>
</head>
<body>

    <!-- ============================================================
    شاشة الترحيب
    ============================================================ -->
    <div class="welcome-screen" id="welcomeScreen">
        <div class="welcome-logo" id="welcomeLogo">
            <div class="logo-icon"><?= $welcomeCompanyLogo ? '<img src="' . htmlspecialchars($welcomeCompanyLogo, ENT_QUOTES, 'UTF-8') . '" alt="">' : '✥' ?></div>
            <h1><?= htmlspecialchars($welcomeCompanyName, ENT_QUOTES, 'UTF-8') ?></h1>
        </div>
        <div class="welcome-loader">
            <div class="loader-label">جاري التحميل...</div>
            <div class="loader-bar-wrapper">
                <div class="loader-bar" id="loaderBar"></div>
            </div>
        </div>
    </div>

    <!-- ============================================================
    شاشة تسجيل الدخول
    ============================================================ -->
    <div id="loginScreen" class="login-page hidden">
        <div class="login-card">
            <div class="login-logo">
                <div class="logo-icon">✥</div>
                <h2>نافذة <span>الموظف</span></h2>
                <p>نظام إدارة الموارد البشرية</p>
            </div>
            <form id="loginForm" onsubmit="handleLogin(event)">
                <div class="form-group">
                    <label>رقم التوظيف</label>
                    <input type="text" id="loginEmployeeId" placeholder="أدخل رقم التوظيف" required>
                </div>
                <div class="form-group">
                    <label>الرمز السري</label>
                    <input type="password" id="loginPassword" placeholder="••••••••" required>
                </div>
                <div class="login-error" id="loginError">بيانات الدخول غير صحيحة</div>
                <button type="submit" class="btn-login" id="loginBtn">
                    <i class="fas fa-arrow-left"></i> تسجيل الدخول
                </button>
            </form>
            <div class="login-toggle">
                الرمز يتم تزويد الموظف به من <a href="#">الموارد البشرية</a>
            </div>
        </div>
    </div>

    <!-- ============================================================
    التطبيق الرئيسي
    ============================================================ -->
    <div id="appContainer" class="hidden">

        <!-- الهيدر -->
        <header class="header-glass">
            <div class="brand">
                <div class="logo" id="headerLogo">✥</div>
                <div class="name" id="headerCompanyName">نافذة <span>الموظف</span></div>
                <span class="role-badge">موظف</span>
            </div>
            <div class="actions">
                <button class="icon-btn" onclick="navigateTo('notifications')" title="الإشعارات">
                    <i class="fas fa-bell"></i>
                </button>
                <button class="icon-btn" onclick="navigateTo('profile')" title="حسابي">
                    <i class="fas fa-user"></i>
                </button>
            </div>
        </header>

        <!-- المحتوى -->
        <div class="page-content" id="pageContent">

            <!-- ===== الصفحة الرئيسية ===== -->
            <div id="page-home" class="page-screen">
                <!-- الملف الشخصي -->
                <div class="profile-card" onclick="navigateTo('profile')" style="cursor:pointer;">
                    <div class="avatar" id="homeAvatar">أ</div>
                    <div class="info">
                        <div class="name" id="homeName">...</div>
                        <div class="email" id="homeTitleBranch">...</div>
                        <div class="role" id="homeCode">...</div>
                    </div>
                </div>

                <!-- الإحصائيات -->
                <div class="stats-row">
                    <div class="stat-item">
                        <div class="num primary" id="statCommitment">0%</div>
                        <div class="label">نسبة الالتزام</div>
                    </div>
                    <div class="stat-item">
                        <div class="num green" id="statTodayStatus">...</div>
                        <div class="label">حالة اليوم</div>
                    </div>
                    <div class="stat-item">
                        <div class="num orange" id="statPendingRequests">0</div>
                        <div class="label">طلبات قيد المراجعة</div>
                    </div>
                    <div class="stat-item">
                        <div class="num primary" id="statUnreadNotifs">0</div>
                        <div class="label">إشعارات جديدة</div>
                    </div>
                </div>

                <!-- لائحة الشرف -->
                <div class="honor-card">
                    <div class="honor-header">
                        <h4><i class="fas fa-trophy"></i> لائحة الشرف</h4>
                        <span class="badge-gold">🏆 أفضل 3</span>
                    </div>
                    <div class="honor-slider" id="honorSlider"></div>
                    <div class="honor-dots" id="honorDots"></div>
                </div>

                <!-- أزرار البصمة -->
                <div class="fingerprint-buttons">
                    <button type="button" class="fingerprint-btn fingerprint-btn-checkin" id="checkInBtn" onclick="handleCheckIn()" disabled>
                        <div class="fingerprint-icon"><i class="fas fa-fingerprint"></i></div>
                        <span class="fingerprint-label">تسجيل حضور</span>
                        <span class="fingerprint-sub">بصمة الدخول</span>
                        <span class="fingerprint-time" id="timeToStart">09:00 ص</span>
                    </button>
                    <button type="button" class="fingerprint-btn fingerprint-btn-checkout" id="checkOutBtn" onclick="handleCheckOut()" disabled>
                        <div class="fingerprint-icon"><i class="fas fa-fingerprint"></i></div>
                        <span class="fingerprint-label">تسجيل انصراف</span>
                        <span class="fingerprint-sub">بصمة الخروج</span>
                        <span class="fingerprint-time" id="timeToEnd">03:00 م</span>
                    </button>
                </div>

                <!-- أيام الدوام -->
                <div class="work-schedule">
                    <div class="schedule-header">
                        <h4><i class="fas fa-calendar-alt"></i> أيام الدوام</h4>
                        <span style="font-size:11px;color:var(--text-muted);font-weight:600;">
                            <i class="fas fa-clock"></i> المتبقي: <span id="remainingTime">7 ساعات</span>
                        </span>
                    </div>
                    <div class="work-days" id="workDays">
                        <span class="day" data-day="0">الأحد</span>
                        <span class="day" data-day="1">الإثنين</span>
                        <span class="day" data-day="2">الثلاثاء</span>
                        <span class="day" data-day="3">الأربعاء</span>
                        <span class="day" data-day="4">الخميس</span>
                        <span class="day inactive" data-day="5">الجمعة</span>
                        <span class="day inactive" data-day="6">السبت</span>
                    </div>
                    <div class="work-hours">
                        <div class="time-block">
                            <i class="fas fa-sun"></i>
                            <span>بداية: <span class="time-value" id="startTime">09:00 ص</span></span>
                        </div>
                        <div class="time-block">
                            <i class="fas fa-moon"></i>
                            <span>نهاية: <span class="time-value" id="endTime">03:00 م</span></span>
                        </div>
                        <div class="countdown" id="countdownStatus">✅ في الدوام</div>
                    </div>
                </div>

                <!-- القائمة السريعة -->
                <div class="quick-actions-grid">
            
                    <button class="quick-action-btn" onclick="navigateTo('commitment')">
                        <i class="fas fa-medal"></i> الالتزام
                        <span class="sub-text" id="quickCommitmentSub">نسبتك 0%</span>
                    
                    </button>
                    <button class="quick-action-btn" onclick="navigateTo('salary')">
                        <i class="fas fa-money-bill-wave"></i> الراتب
                        <span class="sub-text">الخصومات والمكافآت</span>
        
                    </button>
                </div>

                <!-- الإشعارات المصغرة -->
                <div class="mini-notifications" onclick="navigateTo('notifications')">
                    <div class="mini-header">
                        <h4><i class="fas fa-bell"></i> آخر الإشعارات</h4>
                        <span class="view-all">عرض الكل</span>
                    </div>
                    <div id="miniNotifItems">
                        <div class="mini-notification-item">
                            <div class="notif-content"><div class="notif-message">لا توجد إشعارات بعد</div></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ===== باقي الصفحات ===== -->
            <div id="page-profile" class="page-screen hidden">
                <div class="page-title">
                    <h2><i class="fas fa-user-circle"></i> ملفي الوظيفي</h2>
                    <button onclick="navigateTo('home')" class="back-btn"><i class="fas fa-arrow-right"></i> رجوع</button>
                </div>
                <div class="profile-card">
                    <div class="avatar" id="profileAvatar">أ</div>
                    <div class="info">
                        <div class="name" id="profileName">...</div>
                        <div class="email" id="profileTitle">...</div>
                        <div class="role" id="profileBranch">...</div>
                    </div>
                </div>
                <div class="card">
                    <div class="table-wrap">
                        <table class="table">
                            <tr><th>الاسم</th><td id="profileTableName">...</td></tr>
                            <tr><th>المنصب</th><td id="profileTableTitle">...</td></tr>
                            <tr><th>الفرع</th><td id="profileTableBranch">...</td></tr>
                            <tr><th>تاريخ المباشرة</th><td id="profileTableHireDate">...</td></tr>
                            <tr><th>رقم التوظيف</th><td id="profileTableCode">...</td></tr>
                            <tr><th>الراتب الاسمي</th><td id="profileTableSalary">...</td></tr>
                            <tr><th>الشفت المسائي</th><td id="profileTableShift">...</td></tr>
                            <tr><th>الخصومات الإدارية</th><td id="profileTableDeduction">...</td></tr>
                            <tr><th>المكافآت الإدارية</th><td id="profileTableBonus">...</td></tr>
                        </table>
                    </div>
                </div>

                <div class="section-title"><i class="fas fa-money-bill-wave"></i> راتب الشهر الحالي</div>
                <div class="card">
                    <div class="table-wrap">
                        <table class="table">
                            <tr><td>الراتب الأساسي + المكافآت - الخصومات</td><td></td></tr>
                            <tr style="border-top:2px solid var(--primary);"><th><b>الصافي المتوقع استلامه</b></th><td><b id="profileNetSalary" style="color:var(--green,#059669);">0</b></td></tr>
                        </table>
                    </div>
                    <div class="muted" id="profileSalaryStatus" style="margin-top:6px;font-size:12px;"></div>
                </div>

                <div id="profileAdvanceCard" style="display:none;">
                    <div class="section-title"><i class="fas fa-hand-holding-dollar"></i> السلفة الحالية</div>
                    <div class="card">
                        <div class="table-wrap">
                            <table class="table">
                                <tr><th>مبلغ السلفة</th><td id="profileAdvanceAmount">0</td></tr>
                                <tr><th>الخصم الشهري</th><td id="profileAdvanceMonthly">0</td></tr>
                                <tr style="border-top:2px solid var(--primary);"><th><b>المتبقي</b></th><td><b id="profileAdvanceRemaining" style="color:var(--red,#DC2626);">0</b></td></tr>
                            </table>
                        </div>
                    </div>
                </div>

               
                <div class="section-title"><i class="fas fa-shield-halved"></i> الحساب والأمان</div>
                <div class="card">
                    <div class="settings-card-header">
                        <div class="icon-badge"><i class="fas fa-key"></i></div>
                        <div class="titles">
                            <div class="t1">تغيير كلمة المرور</div>
                            <div class="t2">أدخل كلمة المرور الحالية ثم الجديدة</div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>كلمة المرور الحالية</label>
                        <input type="password" id="pwOld" placeholder="••••••••" autocomplete="current-password">
                    </div>
                    <div class="form-group">
                        <label>كلمة المرور الجديدة</label>
                        <input type="password" id="pwNew" placeholder="6 أحرف على الأقل" autocomplete="new-password">
                    </div>
                    <button class="quick-action-btn" style="width:100%;padding:12px;border-color:var(--primary);margin-top:4px;" onclick="changePassword()">
                        <i class="fas fa-save"></i> حفظ كلمة المرور الجديدة
                    </button>
                </div>

                <div class="card logout-card" onclick="requestLogout()">
                    <div class="icon-badge danger"><i class="fas fa-sign-out-alt"></i></div>
                    <div class="titles">
                        <div class="t1" style="color:#EF4444;">تسجيل الخروج</div>
                        <div class="t2">إنهاء الجلسة الحالية على هذا الجهاز</div>
                    </div>
                    <i class="fas fa-chevron-left chev"></i>
                </div>
            </div>

            <div id="page-attendance" class="page-screen hidden">
                <div class="page-title">
                    <h2><i class="fas fa-fingerprint"></i> الحضور والبصمة</h2>
                    <button onclick="navigateTo('home')" class="back-btn"><i class="fas fa-arrow-right"></i> رجوع</button>
                </div>

                <div class="card" style="text-align:center;">
                    <div class="muted">الوقت الحالي</div>
                    <h2 style="margin:8px 0;font-size:28px;" id="currentTime">08:05:12</h2>
                    <div class="muted" id="currentDate">الأربعاء 15 مايو 2024</div>
                </div>

                <div class="fingerprint-buttons" style="flex-direction:column;gap:12px;">
                    <button type="button" class="fingerprint-btn fingerprint-btn-checkin" style="width:100%;" id="checkInBtn2" onclick="handleCheckIn()" disabled>
                        <div class="fingerprint-icon"><i class="fas fa-fingerprint"></i></div>
                        <span class="fingerprint-label">تسجيل حضور</span>
                        <span class="fingerprint-sub">بصمة الدخول</span>
                    </button>
                    <button type="button" class="fingerprint-btn fingerprint-btn-checkout" style="width:100%;" id="checkOutBtn2" onclick="handleCheckOut()" disabled>
                        <div class="fingerprint-icon"><i class="fas fa-fingerprint"></i></div>
                        <span class="fingerprint-label">تسجيل انصراف</span>
                        <span class="fingerprint-sub">بصمة الخروج</span>
                    </button>
                </div>

                <div id="attendanceStatus" style="display:none;padding:12px;border-radius:var(--radius-md);margin:12px 0;text-align:center;font-weight:700;"></div>

                <div class="section-title"><i class="fas fa-history"></i> آخر البصمات</div>
                <div class="card" id="attendanceHistoryList">
                    <div class="list-item"><div class="item-content"><div class="item-title">لا يوجد سجل بعد</div></div></div>
                </div>
                <button class="quick-action-btn" style="width:100%;padding:14px;" onclick="navigateTo('attendanceHistory')">
                    <i class="fas fa-list"></i> عرض سجل الحضور الكامل
                </button>
            </div>

            <div id="page-attendanceHistory" class="page-screen hidden">
                <div class="page-title">
                    <h2><i class="fas fa-calendar-days"></i> سجل الحضور الشهري</h2>
                    <button onclick="navigateTo('attendance')" class="back-btn"><i class="fas fa-arrow-right"></i> رجوع</button>
                </div>
                <div class="card" style="display:flex;align-items:center;justify-content:space-between;padding:12px 16px;">
                    <button onclick="attendanceMonthNav(-1)" style="background:none;border:none;font-size:18px;color:var(--primary);cursor:pointer;padding:6px 10px;"><i class="fas fa-chevron-right"></i></button>
                    <b id="attendanceMonthLabel" style="font-size:14px;">...</b>
                    <button onclick="attendanceMonthNav(1)" id="attendanceNextBtn" style="background:none;border:none;font-size:18px;color:var(--primary);cursor:pointer;padding:6px 10px;"><i class="fas fa-chevron-left"></i></button>
                </div>
                <div class="card" id="attendanceMonthSummary" style="display:flex;justify-content:space-around;text-align:center;padding:14px;"></div>
                <div class="card">
                    <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:6px;margin-bottom:8px;font-size:10.5px;color:var(--text-muted);font-weight:700;text-align:center;">
                        <span>أحد</span><span>إثنين</span><span>ثلاثاء</span><span>أربعاء</span><span>خميس</span><span>جمعة</span><span>سبت</span>
                    </div>
                    <div id="attendanceCalendarGrid" style="display:grid;grid-template-columns:repeat(7,1fr);gap:6px;"></div>
                </div>
                <div class="card" style="display:flex;flex-wrap:wrap;gap:12px;font-size:11px;color:var(--text-muted);justify-content:center;">
                    <span><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#059669;margin-left:4px;"></span> حضور</span>
                    <span><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#D97706;margin-left:4px;"></span> تأخير</span>
                    <span><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#DC2626;margin-left:4px;"></span> غياب</span>
                    <span><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:rgba(0,107,115,0.08);margin-left:4px;"></span> عطلة</span>
                </div>
            </div>

            <div id="page-commitment" class="page-screen hidden">
                <div class="page-title">
                    <h2><i class="fas fa-medal"></i> نسبة الالتزام</h2>
                    <button onclick="navigateTo('home')" class="back-btn"><i class="fas fa-arrow-right"></i> رجوع</button>
                </div>
                <div class="card" style="text-align:center;">
                    <div class="label">نسبة الالتزام الشهرية</div>
                    <div style="position:relative;width:140px;height:140px;margin:12px auto;">
                        <svg viewBox="0 0 140 140" style="transform:rotate(-90deg);width:140px;height:140px;">
                            <circle cx="70" cy="70" r="60" fill="none" stroke="#e5eded" stroke-width="10"/>
                            <circle cx="70" cy="70" r="60" fill="none" stroke="#006b73" stroke-width="10"
                                    stroke-linecap="round" stroke-dasharray="376.99" stroke-dashoffset="376.99" id="commitmentRing"/>
                        </svg>
                        <div style="position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);font-size:32px;font-weight:900;color:var(--text-primary);" id="commitmentPctText">0%</div>
                    </div>
                    <div style="font-size:18px;font-weight:700;color:#059669;" id="commitmentRatingText">...</div>
                    <div style="font-size:13px;color:var(--text-muted);margin-top:4px;" id="commitmentPrevText">نسبة الشهر السابق: 0%</div>
                </div>
                <div class="section-title"><i class="fas fa-chart-line"></i> تفاصيل الالتزام</div>
                <div class="card">
                    <div class="list-item">
                        <div class="item-icon" style="background:rgba(16,185,129,0.12);color:#059669;"><i class="fas fa-calendar-check"></i></div>
                        <div class="item-content">
                            <div class="item-title">أيام الحضور</div>
                            <div class="item-desc" id="commitmentPresentDesc">0 يوم من 0 يوم عمل</div>
                        </div>
                        <span style="font-weight:800;color:#059669;" id="commitmentPresentPct">0%</span>
                    </div>
                    <div class="list-item">
                        <div class="item-icon" style="background:rgba(217,119,6,0.12);color:#D97706;"><i class="fas fa-clock"></i></div>
                        <div class="item-content">
                            <div class="item-title">أيام التأخير</div>
                            <div class="item-desc" id="commitmentLateDesc">0 أيام تأخير</div>
                        </div>
                        <span style="font-weight:800;color:#D97706;" id="commitmentLatePct">0%</span>
                    </div>
                    <div class="list-item">
                        <div class="item-icon" style="background:rgba(239,68,68,0.12);color:#DC2626;"><i class="fas fa-times-circle"></i></div>
                        <div class="item-content">
                            <div class="item-title">أيام الغياب</div>
                            <div class="item-desc" id="commitmentAbsentDesc">0 أيام غياب</div>
                        </div>
                        <span style="font-weight:800;color:#059669;" id="commitmentAbsentPct">0%</span>
                    </div>
                </div>
            </div>

            <div id="page-salary" class="page-screen hidden">
                <div class="page-title">
                    <h2><i class="fas fa-money-bill-wave"></i> الراتب والخصومات</h2>
                    <button onclick="navigateTo('home')" class="back-btn"><i class="fas fa-arrow-right"></i> رجوع</button>
                </div>
                <div class="card">
                    <div class="list-item">
                        <div class="item-icon" style="background:rgba(0,107,115,0.08);color:var(--primary);"><i class="fas fa-calendar"></i></div>
                        <div class="item-content">
                            <div class="item-title">الشهر الحالي</div>
                            <div class="item-desc" id="salaryPeriodText">...</div>
                        </div>
                        <span style="font-weight:800;" id="salaryStatusText">...</span>
                    </div>
                </div>
                <div class="card">
                    <div class="table-wrap">
                        <table class="table">
                            <tr><th>البيان</th><th>المبلغ</th></tr>
                            <tr><td>الراتب الاسمي</td><td id="salaryBase">0</td></tr>
                            <tr><td>الخصومات</td><td style="color:#DC2626;" id="salaryDeduction">- 0</td></tr>
                            <tr><td>المكافآت</td><td style="color:#059669;" id="salaryBonus">+ 0</td></tr>
                            <tr style="border-top:2px solid var(--primary);">
                                <td><b>الراتب النهائي</b></td>
                                <td><b style="color:var(--green);font-size:16px;" id="salaryNet">0 د.ع</b></td>
                            </tr>
                        </table>
                    </div>
                </div>
            </div>

            <div id="page-requests" class="page-screen hidden">
                <div class="page-title">
                    <h2><i class="fas fa-file-pen"></i> طلبات الموظف</h2>
                    <button onclick="navigateTo('home')" class="back-btn"><i class="fas fa-arrow-right"></i> رجوع</button>
                </div>
                <div class="quick-actions-grid" style="grid-template-columns:1fr 1fr;">
                    <button class="quick-action-btn" onclick="openRequestModal('إجازة')" style="padding:16px 10px;">
                        <i class="fas fa-calendar-plus"></i> إجازة
                    </button>
                    <button class="quick-action-btn" onclick="openRequestModal('سلفة')" style="padding:16px 10px;">
                        <i class="fas fa-hand-holding-usd"></i> سلفة
                    </button>
                    <button class="quick-action-btn" onclick="openRequestModal('شكوى')" style="padding:16px 10px;">
                        <i class="fas fa-exclamation-triangle"></i> شكوى
                    </button>
                    <button class="quick-action-btn" onclick="openRequestModal('استقالة')" style="padding:16px 10px;">
                        <i class="fas fa-sign-out-alt"></i> استقالة
                    </button>
                </div>
                <div class="section-title"><i class="fas fa-list"></i> طلباتي السابقة</div>
                <div class="card" id="recentRequestsList">
                    <div class="list-item"><div class="item-content"><div class="item-title">لا توجد طلبات بعد</div></div></div>
                </div>
                <button class="quick-action-btn" style="width:100%;padding:14px;" onclick="navigateTo('myRequests')">
                    <i class="fas fa-list"></i> عرض جميع الطلبات (<span id="allRequestsCount">0</span>)
                </button>
            </div>

            <div id="page-myRequests" class="page-screen hidden">
                <div class="page-title">
                    <h2><i class="fas fa-list"></i> طلباتي</h2>
                    <button onclick="navigateTo('requests')" class="back-btn"><i class="fas fa-arrow-right"></i> رجوع</button>
                </div>
                <div class="quick-actions-grid" style="grid-template-columns:1fr 1fr;margin-bottom:14px;">
                    <button class="quick-action-btn" onclick="filterRequests('all')" style="border-color:var(--primary);font-size:11px;padding:10px;">
                        <i class="fas fa-list"></i> الكل
                    </button>
                    <button class="quick-action-btn" onclick="filterRequests('pending')" style="font-size:11px;padding:10px;">
                        <i class="fas fa-clock"></i> قيد المراجعة
                    </button>
                </div>
                <div id="requestsList">
                    <div class="card request-item" data-status="pending"><div class="list-item"><div class="item-content"><div class="item-title">لا توجد طلبات بعد</div></div></div></div>
                </div>
            </div>

            <div id="page-briefing" class="page-screen hidden">
                <div class="page-title">
                    <h2><i class="fas fa-chart-simple"></i> إيجاز الفرع اليومي</h2>
                    <button onclick="navigateTo('home')" class="back-btn"><i class="fas fa-arrow-right"></i> رجوع</button>
                </div>
                <div class="card">
                    <div class="list-item">
                        <div class="item-icon" style="background:rgba(0,107,115,0.08);color:var(--primary);"><i class="fas fa-calendar"></i></div>
                        <div class="item-content">
                            <div class="item-title">التاريخ</div>
                            <div class="item-desc" id="briefDate">...</div>
                        </div>
                    </div>
                    <div class="list-item">
                        <div class="item-icon" style="background:rgba(0,107,115,0.08);color:var(--primary);"><i class="fas fa-store"></i></div>
                        <div class="item-content">
                            <div class="item-title">الفرع</div>
                            <div class="item-desc" id="briefBranch">...</div>
                        </div>
                    </div>
                </div>
                <div class="card" id="briefStatsCard">
                    <div class="list-item"><div class="item-content"><div class="item-title">المسافرين</div></div><span style="font-weight:800;" id="briefTravelers">0</span></div>
                    <div class="list-item"><div class="item-content"><div class="item-title">المصاريف</div></div><span style="font-weight:800;" id="briefExpense">0</span></div>
                    <div class="list-item" style="border-bottom:0;"><div class="item-content"><div class="item-title">الإيرادات</div></div><span style="font-weight:800;color:#059669;" id="briefIncome">0</span></div>
                </div>
                <div class="card" style="text-align:center;background:rgba(16,185,129,0.04);border-color:rgba(16,185,129,0.12);">
                    <div class="label">نتيجة اليوم</div>
                    <h2 style="color:var(--green);font-size:28px;margin:8px 0;" id="briefProfit">0 د.ع</h2>
                    <p class="muted" style="font-size:13px;color:var(--text-muted);" id="briefViewOnlyNote">للمشاهدة فقط. لا يمكن للموظف العادي التعديل أو الحذف.</p>
                </div>

                <div id="briefDelegatedZone" style="display:none;">
                    <div class="section-title"><i class="fas fa-user-check"></i> أنت مخوّل بكتابة إيجاز اليوم</div>
                    <div class="card" style="background:rgba(201,154,61,0.06);border-color:var(--accent);">
                        <p class="muted" style="font-size:12px;" id="briefDelegationPeriod">فترة التفويض: ...</p>
                    </div>
                    <div class="card">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                            <div class="form-group">
                                <label>المبلغ</label>
                                <input type="number" id="ledgerAmount" placeholder="أدخل المبلغ" min="1">
                            </div>
                            <div class="form-group">
                                <label>نوع القيد</label>
                                <select id="ledgerType">
                                    <option value="income">إيراد</option>
                                    <option value="expense">مصروف</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>ملاحظة</label>
                            <input type="text" id="ledgerNote" placeholder="وصف القيد (اختياري)">
                        </div>
                        <div class="form-group">
                            <label>إرفاق ملف (اختياري)</label>
                            <input type="file" id="ledgerFile" accept=".pdf,.doc,.docx,.jpg,.png">
                        </div>
                        <button class="quick-action-btn" style="width:100%;padding:12px;border-color:var(--primary);" onclick="addLedgerEntry()">
                            <i class="fas fa-plus"></i> إضافة قيد
                        </button>
                    </div>
                    <div class="section-title"><i class="fas fa-list"></i> قيود اليوم</div>
                    <div class="card" id="ledgerEntriesList">
                        <div class="list-item"><div class="item-content"><div class="item-title">لا توجد قيود بعد</div></div></div>
                    </div>
                    <div class="form-group">
                        <label>عدد المسافرين اليوم</label>
                        <input type="number" id="briefTravelersInput" placeholder="0" min="0">
                    </div>
                    <div class="card">
                        <h3 style="margin-top:0;">ملاحظة ومرفق الإيجاز (اختياري)</h3>
                        <p class="muted" style="font-size:12px;">يصل هذا المرفق إلى الموارد البشرية والمسؤول العام لمعاينته مع الإيجاز</p>
                        <div class="form-group"><label>ملاحظة</label><input type="text" id="briefNoteInput" placeholder="ملاحظة عامة على إيجاز اليوم"></div>
                        <div class="form-group"><label>إرفاق ملف</label><input type="file" id="briefAttachmentInput" accept=".pdf,.doc,.docx,.jpg,.png"></div>
                    </div>
                    <button class="quick-action-btn" style="width:100%;padding:14px;border-color:var(--accent);" onclick="publishBriefingAsDelegate()">
                        <i class="fas fa-paper-plane"></i> نشر إيجاز اليوم
                    </button>
                </div>
            </div>

            <div id="page-notifications" class="page-screen hidden">
                <div class="page-title">
                    <h2><i class="fas fa-bell"></i> الإشعارات</h2>
                    <button onclick="navigateTo('home')" class="back-btn"><i class="fas fa-arrow-right"></i> رجوع</button>
                </div>
                <button class="quick-action-btn" style="width:100%;padding:10px;margin-bottom:14px;border-color:var(--primary);" onclick="markAllRead()">
                    <i class="fas fa-check-double"></i> تحديد الكل كمقروء
                </button>
                <div class="card" id="notifFullList">
                    <div class="notification-item-full"><div class="notif-content"><div class="notif-title">لا توجد إشعارات</div></div></div>
                </div>
            </div>

        </div>

        <!-- ===== الشريط السفلي ===== -->
        <nav class="bottom-nav-minimal">
            <button class="nav-item active" id="nav-home" onclick="navigateTo('home')">
                <i class="fas fa-home"></i><span>الرئيسية</span>
            </button>
            <button class="nav-item" id="nav-attendance" onclick="navigateTo('attendance')">
                <i class="fas fa-fingerprint"></i><span>البصمة</span>
            </button>
            <button class="nav-item" id="nav-requests" onclick="navigateTo('requests')">
                <i class="fas fa-file-pen"></i><span>الطلبات</span>
                <span class="nav-badge" id="navRequestsBadge" style="display:none;">0</span>
            </button>
            <button class="nav-item" id="nav-profile" onclick="navigateTo('profile')">
                <i class="fas fa-user"></i><span>ملفي</span>
            </button>
            <button class="nav-item" id="nav-more" onclick="handleBriefingNavClick()">
                <i class="fas fa-chart-simple"></i><span>إيجاز</span>
                <i class="fas fa-lock nav-lock-icon" id="navMoreLock" style="display:none;"></i>
            </button>
        </nav>

        <!-- ===== القائمة الجانبية ===== -->
        <div id="sideMenu" style="display:none;position:fixed;bottom:var(--nav-height);left:0;right:0;background:var(--bg-card);border-top:2px solid rgba(0,107,115,0.04);box-shadow:var(--shadow-lg);z-index:199;max-height:60vh;overflow-y:auto;border-radius:var(--radius-lg) var(--radius-lg) 0 0;padding:16px 20px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;padding-bottom:12px;border-bottom:1px solid rgba(0,107,115,0.04);">
                <h3 style="font-size:16px;font-weight:800;color:var(--text-primary);">📋 القائمة</h3>
                <button onclick="toggleMenu()" style="background:none;border:none;font-size:24px;color:var(--text-muted);cursor:pointer;">✕</button>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;">
                <button class="quick-action-btn" onclick="navigateTo('home');toggleMenu();" style="font-size:11px;padding:10px 6px;">
                    <i class="fas fa-home"></i> الرئيسية
                </button>
                <button class="quick-action-btn" onclick="navigateTo('profile');toggleMenu();" style="font-size:11px;padding:10px 6px;">
                    <i class="fas fa-user"></i> ملفي
                </button>
                <button class="quick-action-btn" onclick="navigateTo('attendance');toggleMenu();" style="font-size:11px;padding:10px 6px;">
                    <i class="fas fa-fingerprint"></i> البصمة
                </button>
                <button class="quick-action-btn" onclick="navigateTo('commitment');toggleMenu();" style="font-size:11px;padding:10px 6px;">
                    <i class="fas fa-medal"></i> الالتزام
                </button>
                <button class="quick-action-btn" onclick="navigateTo('salary');toggleMenu();" style="font-size:11px;padding:10px 6px;">
                    <i class="fas fa-money-bill-wave"></i> الراتب
                </button>
                <button class="quick-action-btn" onclick="navigateTo('briefing');toggleMenu();" id="menuBriefing" style="font-size:11px;padding:10px 6px;display:none;">
                    <i class="fas fa-chart-simple"></i> إيجاز
                </button>
                <button class="quick-action-btn" onclick="navigateTo('notifications');toggleMenu();" style="font-size:11px;padding:10px 6px;">
                    <i class="fas fa-bell"></i> الإشعارات
                </button>
                <button class="quick-action-btn" onclick="toggleMenu();requestLogout();" style="font-size:11px;padding:10px 6px;border-color:#EF4444;color:#EF4444;">
                    <i class="fas fa-sign-out-alt" style="color:#EF4444;"></i> خروج
                </button>
            </div>
        </div>

    </div>

    <!-- ============================================================
    نافذة تقديم الطلب
    ============================================================ -->
    <div class="request-modal-overlay" id="requestModal">
        <div class="request-modal">
            <div class="modal-header">
                <h3 id="requestModalTitle"><i class="fas fa-file-pen"></i> تقديم طلب</h3>
                <button class="close-btn" onclick="closeRequestModal()"><i class="fas fa-times"></i></button>
            </div>
            <div class="modal-body">
                <form id="requestForm" onsubmit="submitRequestForm(event)">
                    <input type="hidden" id="requestType" value="إجازة">
                    <div id="requestFields"></div>
                    <button type="submit" class="btn-submit-request">
                        <i class="fas fa-paper-plane"></i> إرسال الطلب
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- ============================================================
    شريط دعوة تثبيت التطبيق (PWA)
    ============================================================ -->
    <div id="pwaInstallBanner" style="display:none;position:fixed;top:0;left:50%;transform:translateX(-50%);width:100%;max-width:480px;background:var(--primary-dark);color:#fff;z-index:9999;padding:10px 16px;align-items:center;gap:10px;font-size:12.5px;">
        <img src="icons/icon-192.png" style="width:28px;height:28px;border-radius:8px;flex-shrink:0;">
        <span style="flex:1;">ثبّت تطبيق نافذة الموظف على جهازك للوصول السريع</span>
        <button onclick="installPwa()" style="background:var(--accent);color:#fff;border:none;padding:6px 14px;border-radius:var(--radius-full);font-weight:700;font-size:11.5px;cursor:pointer;white-space:nowrap;">تثبيت</button>
        <button onclick="dismissPwaBanner()" style="background:none;border:none;color:rgba(255,255,255,0.7);font-size:16px;cursor:pointer;padding:0 4px;">✕</button>
    </div>

    <!-- ============================================================
    بطاقة تأكيد منبثقة من الأسفل (بديل عن confirm() الأصلية بالمتصفح)
    ============================================================ -->
    <div id="confirmSheetOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);backdrop-filter:blur(4px);z-index:600;" onclick="if(event.target===this) closeConfirmSheet()">
        <div id="confirmSheet" style="position:fixed;bottom:0;left:50%;transform:translateX(-50%);width:100%;max-width:480px;background:var(--bg-card);border-radius:var(--radius-lg) var(--radius-lg) 0 0;box-shadow:var(--shadow-xl);padding:24px 20px calc(24px + env(safe-area-inset-bottom));animation:slideUpCentered 0.35s cubic-bezier(0.34,1.56,0.64,1);">
            <div style="width:40px;height:4px;background:rgba(0,0,0,0.15);border-radius:4px;margin:0 auto 16px;"></div>
            <h3 id="confirmSheetTitle" style="font-size:16px;font-weight:800;color:var(--text-primary);text-align:center;margin-bottom:6px;">تأكيد</h3>
            <p id="confirmSheetMessage" style="font-size:13px;color:var(--text-muted);text-align:center;margin-bottom:20px;">هل أنت متأكد؟</p>
            <div style="display:flex;gap:10px;">
                <button class="quick-action-btn" style="flex:1;padding:14px;border-color:#EF4444;color:#EF4444;" onclick="confirmSheetAccept()"><i class="fas fa-check"></i> تأكيد</button>
                <button class="quick-action-btn" style="flex:1;padding:14px;" onclick="closeConfirmSheet()"><i class="fas fa-times"></i> إلغاء</button>
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
        window.addEventListener('unhandledrejection', function(e) {
            console.error('Unhandled request failure:', e.reason);
            if (typeof showToast === 'function') {
                showToast('❌ خطأ في الاتصال', 'تعذر تنفيذ العملية، تحقق من اتصال الإنترنت وحاول مرة أخرى', 'error');
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
        // شاشة الترحيب - محاكاة التحميل
        // ============================================================
        let loaderProgress = 0;
        const loaderBar = document.getElementById('loaderBar');
        const welcomeScreen = document.getElementById('welcomeScreen');
        const loginScreen = document.getElementById('loginScreen');
        const alreadyLoggedIn = <?= $isLoggedIn ? 'true' : 'false' ?>;

        function animateLoader() {
            if (loaderProgress >= 100) {
                loaderBar.style.width = '100%';
                setTimeout(() => {
                    welcomeScreen.classList.add('fade-out');
                    setTimeout(() => {
                        welcomeScreen.style.display = 'none';
                        if (alreadyLoggedIn) {
                            document.getElementById('appContainer').classList.remove('hidden');
                            startAutoSlide();
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
        // متغيرات GPS
        // ============================================================
        let gpsEnabled = false;
        let gpsPosition = null;
        let isGPSRequestInProgress = false;

        // ============================================================
        // دالة تفعيل الموقع (GPS) - تستدعى فقط عند الضغط على أزرار البصمة
        // ============================================================
        function requestGPS() {
            return new Promise((resolve) => {
                if (gpsEnabled) {
                    resolve(true);
                    return;
                }

                if (isGPSRequestInProgress) {
                    showToast('⏳ جاري التفعيل', 'يتم تحديد الموقع حالياً...', 'info');
                    resolve(false);
                    return;
                }

                isGPSRequestInProgress = true;

                if (!navigator.geolocation) {
                    showToast('❌ غير مدعوم', 'متصفحك لا يدعم تحديد الموقع', 'error');
                    isGPSRequestInProgress = false;
                    resolve(false);
                    return;
                }

                // إظهار طلب تفعيل الموقع
                showToast('📍 طلب موقع', 'يرجى السماح بتحديد موقعك لتسجيل البصمة', 'info', 4000);

                navigator.geolocation.getCurrentPosition(
                    function(pos) {
                        gpsEnabled = true;
                        gpsPosition = {
                            latitude: pos.coords.latitude,
                            longitude: pos.coords.longitude,
                            accuracy: pos.coords.accuracy
                        };
                        isGPSRequestInProgress = false;

                        // تفعيل أزرار البصمة
                        enableAttendanceButtons(true);

                        // تحديث واجهة المستخدم في الصفحة الرئيسية
                        showToast('✅ تم التفعيل', 'تم تحديد موقعك بنجاح', 'success');
                        resolve(true);
                    },
                    function(err) {
                        gpsEnabled = false;
                        isGPSRequestInProgress = false;
                        enableAttendanceButtons(false);

                        let errorMsg = 'يرجى تفعيل خدمة الموقع يدوياً';
                        if (err.code === 1) {
                            errorMsg = 'تم رفض إذن الموقع. يرجى السماح بتحديد الموقع';
                        } else if (err.code === 2) {
                            errorMsg = 'الموقع غير متوفر حالياً. حاول مرة أخرى';
                        } else if (err.code === 3) {
                            errorMsg = 'انتهت مهلة تحديد الموقع. حاول مرة أخرى';
                        }

                        showToast('❌ فشل التفعيل', errorMsg, 'error');
                        resolve(false);
                    }, {
                        enableHighAccuracy: true,
                        timeout: 15000,
                        maximumAge: 0
                    }
                );
            });
        }

        // ============================================================
        // تفعيل/تعطيل أزرار البصمة
        // ============================================================
        function enableAttendanceButtons(enabled) {
            const btns = [
                document.getElementById('checkInBtn'),
                document.getElementById('checkOutBtn'),
                document.getElementById('checkInBtn2'),
                document.getElementById('checkOutBtn2')
            ];
            btns.forEach(btn => {
                if (btn) btn.disabled = !enabled;
            });
        }

        // ============================================================
        // تحميل البيانات الحقيقية من الخادم
        // ============================================================
        function initApp() {
            loadBootstrap();
            loadNotifications();
            setInterval(loadNotifications, 60000);
            setInterval(loadBootstrap, 60000);
            requestNotifPermission();
        }

        let profileDocumentsPath = null;

        function loadBootstrap() {
            fetch('?ajax=bootstrap').then(r => r.json()).then(data => {
                if (!data.ok) return;
                const p = data.profile;
                profileDocumentsPath = p.documents || null;

                if (data.company) {
                    document.getElementById('headerCompanyName').innerHTML = data.company.name + ' <span>نافذة الموظف</span>';
                    if (data.company.logo) document.getElementById('headerLogo').innerHTML = `<img src="${data.company.logo}" style="width:100%;height:100%;object-fit:cover;border-radius:inherit;">`;
                }

                document.getElementById('homeAvatar').textContent = p.photo ? '' : (p.name ? p.name.charAt(0) : '؟');
                if (p.photo) document.getElementById('homeAvatar').innerHTML = `<img src="${p.photo}" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">`;
                document.getElementById('homeName').textContent = p.name;
                document.getElementById('homeTitleBranch').textContent = (p.title || 'موظف') + ' · ' + p.branch;
                document.getElementById('homeCode').textContent = 'رقم التوظيف: ' + p.code;

                document.getElementById('profileAvatar').innerHTML = p.photo ? `<img src="${p.photo}" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">` : (p.name ? p.name.charAt(0) : '؟');
                document.getElementById('profileName').textContent = p.name;
                document.getElementById('profileTitle').textContent = p.title || 'موظف';
                document.getElementById('profileBranch').textContent = p.branch;
                document.getElementById('profileTableName').textContent = p.name;
                document.getElementById('profileTableTitle').textContent = p.title || '-';
                document.getElementById('profileTableBranch').textContent = p.branch;
                document.getElementById('profileTableHireDate').textContent = p.hireDate || '-';
                document.getElementById('profileTableCode').textContent = p.code;
                document.getElementById('profileTableSalary').textContent = Number(p.baseSalary).toLocaleString() + ' د.ع';
                document.getElementById('profileTableShift').textContent = p.shiftTypeText;
                document.getElementById('profileTableDeduction').textContent = Number(p.adminDeduction).toLocaleString() + ' د.ع';
                document.getElementById('profileTableBonus').textContent = Number(p.adminBonus).toLocaleString() + ' د.ع';
                document.getElementById('profileNetSalary').textContent = Number(p.netSalary).toLocaleString() + ' د.ع';
                document.getElementById('profileSalaryStatus').textContent = p.salaryDelivered ? '✅ تم تسليم راتب هذا الشهر' : '⏳ لم يتم تسليم راتب هذا الشهر بعد';
                const advCard = document.getElementById('profileAdvanceCard');
                if (p.hasAdvance) {
                    advCard.style.display = 'block';
                    document.getElementById('profileAdvanceAmount').textContent = Number(p.advanceAmount).toLocaleString() + ' د.ع';
                    document.getElementById('profileAdvanceMonthly').textContent = Number(p.advanceMonthlyDeduction).toLocaleString() + ' د.ع';
                    document.getElementById('profileAdvanceRemaining').textContent = Number(p.advanceRemaining).toLocaleString() + ' د.ع';
                } else {
                    advCard.style.display = 'none';
                }

                if (document.getElementById('statCommitment')) document.getElementById('statCommitment').textContent = data.stats.commitmentPct + '%';
                if (document.getElementById('statTodayStatus')) document.getElementById('statTodayStatus').textContent = data.stats.todayStatus;
                if (document.getElementById('statPendingRequests')) document.getElementById('statPendingRequests').textContent = data.stats.pendingRequests;
                if (document.getElementById('statUnreadNotifs')) document.getElementById('statUnreadNotifs').textContent = data.stats.unreadNotifications;
                if (document.getElementById('quickCommitmentSub')) document.getElementById('quickCommitmentSub').textContent = 'نسبتك ' + data.stats.commitmentPct + '%';
                if (document.getElementById('quickNotifBadge')) document.getElementById('quickNotifBadge').textContent = data.stats.unreadNotifications;

                const navBadge = document.getElementById('navRequestsBadge');
                if (navBadge) {
                    if (data.stats.pendingRequests > 0) {
                        navBadge.style.display = 'flex';
                        navBadge.textContent = data.stats.pendingRequests;
                    } else {
                        navBadge.style.display = 'none';
                    }
                }

                renderHonorRoll(data.honorRoll);

                const histWrap = document.getElementById('attendanceHistoryList');
                if (histWrap) {
                    if (!data.recentAttendance.length) {
                        histWrap.innerHTML = '<div class="list-item"><div class="item-content"><div class="item-title">لا يوجد سجل بعد</div></div></div>';
                    } else {
                        histWrap.innerHTML = data.recentAttendance.map(a => `
                            <div class="list-item">
                                <div class="item-icon" style="background:${a.late ? 'rgba(217,119,6,0.12)' : 'rgba(16,185,129,0.12)'};color:${a.late ? '#D97706' : '#059669'};">${a.checkIn ? '✓' : '✕'}</div>
                                <div class="item-content">
                                    <div class="item-title">${a.date}${a.late ? ' (تأخير)' : ''}</div>
                                    <div class="item-desc">دخول: ${a.checkIn || '--:--'} · انصراف: ${a.checkOut || '--:--'}</div>
                                </div>
                            </div>
                        `).join('');
                    }
                }

                shiftInfo = data.shift;
                todayAttendance = data.todayAttendance;
                updateWorkSchedule();

                isDelegated = data.delegation && data.delegation.active;
                if (document.getElementById('qaBriefing')) document.getElementById('qaBriefing').style.display = isDelegated ? '' : 'none';
                if (document.getElementById('menuBriefing')) document.getElementById('menuBriefing').style.display = isDelegated ? '' : 'none';
                if (document.getElementById('briefDelegatedZone')) document.getElementById('briefDelegatedZone').style.display = isDelegated ? 'block' : 'none';
                if (document.getElementById('briefViewOnlyNote')) document.getElementById('briefViewOnlyNote').style.display = isDelegated ? 'none' : 'block';
                if (document.getElementById('navMoreLock')) document.getElementById('navMoreLock').style.display = isDelegated ? 'none' : 'flex';
                if (document.getElementById('nav-more')) document.getElementById('nav-more').classList.toggle('nav-locked', !isDelegated);
                if (isDelegated && document.getElementById('briefDelegationPeriod')) {
                    document.getElementById('briefDelegationPeriod').textContent = 'فترة التفويض: ' + data.delegation.start + ' إلى ' + data.delegation.end;
                }
            });
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
                checkNewBrowserNotifications(data.notifications, 'lastNotifId_employee');
                const miniWrap = document.getElementById('miniNotifItems');
                const fullWrap = document.getElementById('notifFullList');
                if (!data.notifications.length) {
                    if (miniWrap) miniWrap.innerHTML = '<div class="mini-notification-item"><div class="notif-content"><div class="notif-message">لا توجد إشعارات</div></div></div>';
                    if (fullWrap) fullWrap.innerHTML = '<div class="notification-item-full"><div class="notif-content"><div class="notif-title">لا توجد إشعارات</div></div></div>';
                    return;
                }
                if (miniWrap) {
                    miniWrap.innerHTML = data.notifications.slice(0, 2).map(n => `
                        <div class="mini-notification-item">
                            <div class="notif-icon ${n.is_read ? 'info' : 'success'}">${n.is_read ? 'ℹ' : '✓'}</div>
                            <div class="notif-content">
                                <div class="notif-title">${n.title}</div>
                                <div class="notif-message">${n.message || ''}</div>
                            </div>
                            <span class="notif-time">${n.date}</span>
                        </div>
                    `).join('');
                }
                if (fullWrap) {
                    fullWrap.innerHTML = data.notifications.map(n => `
                        <div class="notification-item-full" style="${n.is_read ? '' : 'border-right:3px solid var(--primary);'}">
                            <div class="notif-icon ${n.is_read ? 'info' : 'success'}">${n.is_read ? 'ℹ' : '✓'}</div>
                            <div class="notif-content">
                                <div class="notif-title">${n.title}</div>
                                <div class="notif-message">${n.message || ''}</div>
                            </div>
                            <span class="notif-time">${n.date}</span>
                        </div>
                    `).join('');
                }
            });
        }

        // ============================================================
        // طلباتي
        // ============================================================
        function loadMyRequests() {
            fetch('?ajax=my_requests').then(r => r.json()).then(data => {
                if (!data.ok) return;
                renderMyRequests(data.requests);
            });
        }

        const requestStatusBadgeClass = { 'pending': 'wait', 'branch_approved': 'wait', 'approved': 'ok', 'rejected': 'danger' };
        const requestTypeIcons = { 'إجازة': '📅', 'سلفة': '💰', 'شكوى': '📋', 'استقالة': '🚪' };

        function renderMyRequests(requests) {
            document.getElementById('allRequestsCount').textContent = requests.length;

            const recentWrap = document.getElementById('recentRequestsList');
            if (recentWrap) {
                if (!requests.length) {
                    recentWrap.innerHTML = '<div class="list-item"><div class="item-content"><div class="item-title">لا توجد طلبات بعد</div></div></div>';
                } else {
                    recentWrap.innerHTML = requests.slice(0, 3).map(r => `
                        <div class="list-item" onclick="navigateTo('myRequests')" style="cursor:pointer;">
                            <div class="item-icon">${requestTypeIcons[r.type] || '📄'}</div>
                            <div class="item-content">
                                <div class="item-title">طلب ${r.type}</div>
                                <div class="item-desc">${r.details}</div>
                            </div>
                            <span class="item-badge ${requestStatusBadgeClass[r.status] || 'wait'}">${r.statusText}</span>
                        </div>
                    `).join('');
                }
            }

            const listWrap = document.getElementById('requestsList');
            if (!requests.length) {
                listWrap.innerHTML = '<div class="card request-item" data-status="pending"><div class="list-item"><div class="item-content"><div class="item-title">لا توجد طلبات بعد</div></div></div></div>';
                return;
            }
            listWrap.innerHTML = requests.map(r => `
                <div class="card request-item" data-status="${r.status === 'pending' || r.status === 'branch_approved' ? 'pending' : 'done'}">
                    <div class="list-item" style="border-bottom:1px solid rgba(0,107,115,0.04);padding-bottom:10px;margin-bottom:6px;${r.isAdvanceApproved ? 'cursor:pointer;' : ''}" ${r.isAdvanceApproved ? `onclick="toggleAdvanceDetail(${r.id})"` : ''}>
                        <div class="item-icon">${requestTypeIcons[r.type] || '📄'}</div>
                        <div class="item-content">
                            <div class="item-title">طلب ${r.type} ${r.isAdvanceApproved ? '<i class="fas fa-chevron-down" style="font-size:10px;color:var(--text-muted);"></i>' : ''}</div>
                            <div class="item-desc">${r.details}</div>
                        </div>
                        <span class="item-badge ${requestStatusBadgeClass[r.status] || 'wait'}">${r.statusText}</span>
                    </div>
                    <div style="font-size:12px;color:var(--text-muted);">${r.responseText || ''}</div>
                    ${r.isAdvanceApproved ? `
                        <div id="advanceDetail_${r.id}" style="display:none;margin-top:8px;padding:10px 12px;background:var(--bg);border-radius:var(--radius-sm,8px);font-size:12px;">
                            <div style="display:flex;justify-content:space-between;padding:3px 0;"><span style="color:var(--text-muted);">💰 مبلغ السلفة</span><b>${Number(r.amount).toLocaleString()} د.ع</b></div>
                            <div style="display:flex;justify-content:space-between;padding:3px 0;"><span style="color:var(--text-muted);">📉 الخصم الشهري</span><b>${Number(r.monthlyDeduction).toLocaleString()} د.ع</b></div>
                            <div style="display:flex;justify-content:space-between;padding:3px 0;border-top:1px solid rgba(0,107,115,0.06);margin-top:4px;padding-top:6px;"><span style="color:var(--text-muted);">المتبقي</span><b style="color:${r.remainingBalance > 0 ? 'var(--red,#DC2626)' : 'var(--green,#059669)'};">${Number(r.remainingBalance).toLocaleString()} د.ع</b></div>
                        </div>
                    ` : ''}
                </div>
            `).join('');
        }

        function toggleAdvanceDetail(id) {
            const el = document.getElementById(`advanceDetail_${id}`);
            if (el) el.style.display = el.style.display === 'none' ? 'block' : 'none';
        }

        function openMyDocuments() {
            if (!profileDocumentsPath) {
                showToast('🪪 المستمسكات', 'لم يقم قسم الموارد البشرية برفع مستمسكاتك بعد', 'info');
                return;
            }
            window.open(profileDocumentsPath, '_blank');
        }

        // ============================================================
        // نسبة الالتزام
        // ============================================================
        function loadCommitment() {
            fetch('?ajax=commitment').then(r => r.json()).then(data => {
                if (!data.ok) return;
                const circumference = 376.99;
                document.getElementById('commitmentRing').setAttribute('stroke-dashoffset', circumference * (1 - data.pct / 100));
                document.getElementById('commitmentPctText').textContent = data.pct + '%';
                document.getElementById('commitmentRatingText').textContent = '⭐ ' + data.rating;
                document.getElementById('commitmentPrevText').textContent = 'نسبة الشهر السابق: ' + data.previousPct + '%';
                document.getElementById('commitmentPresentDesc').textContent = data.presentDays + ' يوم من ' + data.totalDays + ' يوم عمل';
                document.getElementById('commitmentPresentPct').textContent = data.pct + '%';
                document.getElementById('commitmentLateDesc').textContent = data.lateDays + ' أيام تأخير';
                document.getElementById('commitmentLatePct').textContent = (data.totalDays ? Math.round(data.lateDays / data.totalDays * 100) : 0) + '%';
                document.getElementById('commitmentAbsentDesc').textContent = data.absentDays + ' أيام غياب';
                document.getElementById('commitmentAbsentPct').textContent = (data.totalDays ? Math.round(data.absentDays / data.totalDays * 100) : 0) + '%';
            });
        }

        // ============================================================
        // الراتب
        // ============================================================
        function loadSalary() {
            fetch('?ajax=salary').then(r => r.json()).then(data => {
                if (!data.ok) return;
                const months = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];
                document.getElementById('salaryPeriodText').textContent = months[data.month - 1] + ' ' + data.year;
                document.getElementById('salaryStatusText').textContent = data.statusText;
                document.getElementById('salaryBase').textContent = Number(data.baseSalary).toLocaleString();
                document.getElementById('salaryDeduction').textContent = '- ' + Number(data.adminDeduction).toLocaleString();
                document.getElementById('salaryBonus').textContent = '+ ' + Number(data.adminBonus).toLocaleString();
                document.getElementById('salaryNet').textContent = Number(data.net).toLocaleString() + ' د.ع';
            });
        }

        // ============================================================
        // سجل الحضور الشهري الكامل
        // ============================================================
        let attendanceViewMonth = new Date().getMonth() + 1;
        let attendanceViewYear = new Date().getFullYear();
        const arMonthNames = ['يناير','فبراير','مارس','أبريل','مايو','يونيو','يوليو','أغسطس','سبتمبر','أكتوبر','نوفمبر','ديسمبر'];

        function loadAttendanceMonth() {
            fetch(`?ajax=attendance_month&month=${attendanceViewMonth}&year=${attendanceViewYear}`).then(r => r.json()).then(data => {
                if (!data.ok) return;
                renderAttendanceCalendar(data);
            });
        }

        function attendanceMonthNav(delta) {
            const now = new Date();
            let m = attendanceViewMonth + delta;
            let y = attendanceViewYear;
            if (m > 12) { m = 1; y++; }
            if (m < 1) { m = 12; y--; }
            if (y > now.getFullYear() || (y === now.getFullYear() && m > now.getMonth() + 1)) return;
            attendanceViewMonth = m;
            attendanceViewYear = y;
            loadAttendanceMonth();
        }

        function renderAttendanceCalendar(data) {
            document.getElementById('attendanceMonthLabel').textContent = arMonthNames[data.month - 1] + ' ' + data.year;
            const now = new Date();
            const isCurrentMonth = data.year === now.getFullYear() && data.month === now.getMonth() + 1;
            document.getElementById('attendanceNextBtn').style.visibility = isCurrentMonth ? 'hidden' : 'visible';

            document.getElementById('attendanceMonthSummary').innerHTML = `
                <div><div style="font-size:20px;font-weight:800;color:#059669;">${data.summary.present}</div><div style="font-size:11px;color:var(--text-muted);">حضور</div></div>
                <div><div style="font-size:20px;font-weight:800;color:#D97706;">${data.summary.late}</div><div style="font-size:11px;color:var(--text-muted);">تأخير</div></div>
                <div><div style="font-size:20px;font-weight:800;color:#DC2626;">${data.summary.absent}</div><div style="font-size:11px;color:var(--text-muted);">غياب</div></div>
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
            document.getElementById('attendanceCalendarGrid').innerHTML = html;
        }

        // ============================================================
        // إيجاز الفرع
        // ============================================================
        function loadBriefing() {
            fetch('?ajax=branch_briefing').then(r => r.json()).then(data => {
                if (!data.ok) return;
                document.getElementById('briefDate').textContent = data.date;
                document.getElementById('briefBranch').textContent = data.branch;
                if (!data.exists) {
                    document.getElementById('briefTravelers').textContent = '-';
                    document.getElementById('briefExpense').textContent = '-';
                    document.getElementById('briefIncome').textContent = '-';
                    document.getElementById('briefProfit').textContent = 'لم يُنشر بعد';
                } else if (data.pendingApproval) {
                    document.getElementById('briefTravelers').textContent = '-';
                    document.getElementById('briefExpense').textContent = '-';
                    document.getElementById('briefIncome').textContent = '-';
                    document.getElementById('briefProfit').textContent = data.statusText;
                } else {
                    document.getElementById('briefTravelers').textContent = data.travelersCount;
                    document.getElementById('briefExpense').textContent = Number(data.totalExpense).toLocaleString();
                    document.getElementById('briefIncome').textContent = Number(data.totalIncome).toLocaleString();
                    document.getElementById('briefProfit').textContent = Number(data.profit).toLocaleString() + ' د.ع';
                }
            });
            if (isDelegated) loadLedgerEntries();
        }

        // ============================================================
        // كتابة الإيجاز (للموظف المفوَّض فقط)
        // ============================================================
        function loadLedgerEntries() {
            fetch('?ajax=ledger_list').then(r => r.json()).then(data => {
                if (!data.ok) return;
                const wrap = document.getElementById('ledgerEntriesList');
                if (!data.entries.length) {
                    wrap.innerHTML = '<div class="list-item"><div class="item-content"><div class="item-title">لا توجد قيود بعد</div></div></div>';
                    return;
                }
                wrap.innerHTML = data.entries.map(e => `
                    <div class="list-item">
                        <div class="item-icon" style="background:${e.entry_type === 'income' ? 'rgba(16,185,129,0.12)' : 'rgba(239,68,68,0.12)'};color:${e.entry_type === 'income' ? '#059669' : '#DC2626'};">${e.entry_type === 'income' ? '+' : '-'}</div>
                        <div class="item-content">
                            <div class="item-title">${e.entry_type === 'income' ? 'إيراد' : 'مصروف'}</div>
                            <div class="item-desc">${e.description || '-'}</div>
                            ${e.attachment ? `<a href="${e.attachment}" target="_blank" style="font-size:10px;"><i class="fas fa-paperclip"></i> عرض الملف</a>` : ''}
                        </div>
                        <span style="font-weight:800;">${Number(e.amount).toLocaleString()}</span>
                    </div>
                `).join('');
            });
        }

        function addLedgerEntry() {
            const type = document.getElementById('ledgerType').value;
            const amount = document.getElementById('ledgerAmount').value;
            const note = document.getElementById('ledgerNote').value;
            const fileInput = document.getElementById('ledgerFile');
            if (!amount || Number(amount) <= 0) {
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
                document.getElementById('ledgerAmount').value = '';
                document.getElementById('ledgerNote').value = '';
                if (fileInput) fileInput.value = '';
                showToast('✅ تمت الإضافة', 'تم إضافة القيد بنجاح', 'success');
                loadLedgerEntries();
                loadBriefing();
            });
        }

        function publishBriefingAsDelegate() {
            showConfirmSheet('نشر إيجاز اليوم', 'تأكيد نشر إيجاز اليوم؟ سيُرسل للموارد البشرية للمراجعة.', doPublishBriefingAsDelegate);
        }

        function doPublishBriefingAsDelegate() {
            const travelersCount = document.getElementById('briefTravelersInput').value || 0;
            const note = document.getElementById('briefNoteInput').value;
            const fileInput = document.getElementById('briefAttachmentInput');
            const formData = new FormData();
            formData.append('travelersCount', travelersCount);
            formData.append('note', note);
            if (fileInput.files && fileInput.files.length > 0) {
                formData.append('attachment', fileInput.files[0]);
            }
            fetch('?ajax=briefing_publish_delegate', { method: 'POST', body: formData }).then(r => r.json()).then(data => {
                if (!data.ok) { showToast('⚠️ خطأ', data.error || 'تعذر نشر الإيجاز', 'error'); return; }
                showToast('✅ تم النشر', 'تم نشر إيجاز اليوم بانتظار مراجعة الموارد البشرية', 'success');
                loadBriefing();
            }).catch(() => {
                showToast('⚠️ خطأ', 'تعذر الاتصال بالخادم', 'error');
            });
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

            btn.innerHTML = '<span class="spinner-small" style="display:inline-block;width:20px;height:20px;border:3px solid rgba(255,255,255,0.2);border-radius:50%;border-top-color:#fff;animation:spin 0.8s linear infinite;"></span> جاري التسجيل...';
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
                    startAutoSlide();
                    // أزرار البصمة تبقى معطلة حتى يطلب المستخدم تفعيلها
                    enableAttendanceButtons(false);
                    initApp();
                } else {
                    error.textContent = data.error || 'رقم التوظيف أو الرمز السري غير صحيح';
                    error.style.display = 'block';
                }
            }).catch(() => {
                btn.innerHTML = '<i class="fas fa-arrow-left"></i> تسجيل الدخول';
                btn.disabled = false;
                error.textContent = 'تعذر الاتصال بالخادم';
                error.style.display = 'block';
            });
        }

        // ============================================================
        // دوال الحضور والانصراف - تطلب تفعيل الموقع عند الضغط عليها
        // ============================================================
        async function handleCheckIn() {
            const btn = document.getElementById('checkInBtn') || document.getElementById('checkInBtn2');

            // إذا كان الموقع مفعلاً، سجل مباشرة
            if (gpsEnabled) {
                performCheckIn(btn);
                return;
            }

            // طلب تفعيل الموقع
            btn.disabled = true;
            btn.innerHTML = `
                <div class="fingerprint-icon"><span class="spinner-small"></span></div>
                <span class="fingerprint-label">جاري طلب الموقع...</span>
                <span class="fingerprint-sub">يرجى الانتظار</span>
            `;

            const gpsOk = await requestGPS();

            btn.disabled = false;
            if (gpsOk) {
                performCheckIn(btn);
            } else {
                btn.innerHTML = `
                    <div class="fingerprint-icon"><i class="fas fa-fingerprint"></i></div>
                    <span class="fingerprint-label">تسجيل حضور</span>
                    <span class="fingerprint-sub">بصمة الدخول</span>
                    <span class="fingerprint-time">${document.getElementById('timeToStart')?.textContent || '09:00 ص'}</span>
                `;
                // تحديث حالة البصمة
                showToast('⚠️ غير مفعل', 'يجب تفعيل الموقع لتسجيل البصمة', 'warning');
            }
        }

        function performCheckIn(btn) {
            btn.disabled = true;
            btn.innerHTML = `
                <div class="fingerprint-icon"><span class="spinner-small"></span></div>
                <span class="fingerprint-label">جاري التسجيل...</span>
                <span class="fingerprint-sub">التحقق من الموقع</span>
            `;

            const body = new URLSearchParams({ type: 'in', lat: gpsPosition ? gpsPosition.latitude : 0, lng: gpsPosition ? gpsPosition.longitude : 0 });
            fetch('?ajax=attendance_self', { method: 'POST', body }).then(r => r.json()).then(data => {
                if (!data.ok) {
                    btn.disabled = false;
                    btn.innerHTML = `
                        <div class="fingerprint-icon"><i class="fas fa-fingerprint"></i></div>
                        <span class="fingerprint-label">تسجيل حضور</span>
                        <span class="fingerprint-sub">بصمة الدخول</span>
                        <span class="fingerprint-time">${document.getElementById('timeToStart')?.textContent || ''}</span>
                    `;
                    showToast('❌ فشل التسجيل', data.error || 'تعذر تسجيل الحضور', 'error');
                    return;
                }
                btn.innerHTML = `
                    <div class="fingerprint-icon"><i class="fas fa-fingerprint"></i></div>
                    <span class="fingerprint-label">✅ تم الحضور</span>
                    <span class="fingerprint-sub">${data.time}</span>
                `;
                btn.style.background = 'linear-gradient(135deg, #059669, #047857)';
                showToast('✅ تم تسجيل الدخول', 'بصمة الدخول مسجلة بنجاح', 'success');
                loadBootstrap();

                setTimeout(() => {
                    btn.disabled = false;
                    btn.style.background = 'linear-gradient(135deg, #10B981, #059669)';
                }, 3000);
            }).catch(() => {
                btn.disabled = false;
                showToast('❌ فشل التسجيل', 'تعذر الاتصال بالخادم', 'error');
            });
        }

        async function handleCheckOut() {
            const btn = document.getElementById('checkOutBtn') || document.getElementById('checkOutBtn2');

            // إذا كان الموقع مفعلاً، سجل مباشرة
            if (gpsEnabled) {
                performCheckOut(btn);
                return;
            }

            // طلب تفعيل الموقع
            btn.disabled = true;
            btn.innerHTML = `
                <div class="fingerprint-icon"><span class="spinner-small"></span></div>
                <span class="fingerprint-label">جاري طلب الموقع...</span>
                <span class="fingerprint-sub">يرجى الانتظار</span>
            `;

            const gpsOk = await requestGPS();

            btn.disabled = false;
            if (gpsOk) {
                performCheckOut(btn);
            } else {
                btn.innerHTML = `
                    <div class="fingerprint-icon"><i class="fas fa-fingerprint"></i></div>
                    <span class="fingerprint-label">تسجيل انصراف</span>
                    <span class="fingerprint-sub">بصمة الخروج</span>
                    <span class="fingerprint-time">${document.getElementById('timeToEnd')?.textContent || '03:00 م'}</span>
                `;
                showToast('⚠️ غير مفعل', 'يجب تفعيل الموقع لتسجيل البصمة', 'warning');
            }
        }

        function performCheckOut(btn) {
            btn.disabled = true;
            btn.innerHTML = `
                <div class="fingerprint-icon"><span class="spinner-small"></span></div>
                <span class="fingerprint-label">جاري التسجيل...</span>
                <span class="fingerprint-sub">التحقق من الموقع</span>
            `;

            const body = new URLSearchParams({ type: 'out', lat: gpsPosition ? gpsPosition.latitude : 0, lng: gpsPosition ? gpsPosition.longitude : 0 });
            fetch('?ajax=attendance_self', { method: 'POST', body }).then(r => r.json()).then(data => {
                if (!data.ok) {
                    btn.disabled = false;
                    btn.innerHTML = `
                        <div class="fingerprint-icon"><i class="fas fa-fingerprint"></i></div>
                        <span class="fingerprint-label">تسجيل انصراف</span>
                        <span class="fingerprint-sub">بصمة الخروج</span>
                        <span class="fingerprint-time">${document.getElementById('timeToEnd')?.textContent || ''}</span>
                    `;
                    showToast('❌ فشل التسجيل', data.error || 'تعذر تسجيل الانصراف', 'error');
                    return;
                }
                btn.innerHTML = `
                    <div class="fingerprint-icon"><i class="fas fa-fingerprint"></i></div>
                    <span class="fingerprint-label">✅ تم الانصراف</span>
                    <span class="fingerprint-sub">${data.time}</span>
                `;
                btn.style.background = 'linear-gradient(135deg, #DC2626, #B91C1C)';
                showToast('✅ تم تسجيل الانصراف', 'بصمة الخروج مسجلة بنجاح', 'success');
                loadBootstrap();

                setTimeout(() => {
                    btn.disabled = false;
                    btn.style.background = 'linear-gradient(135deg, #EF4444, #DC2626)';
                }, 3000);
            }).catch(() => {
                btn.disabled = false;
                showToast('❌ فشل التسجيل', 'تعذر الاتصال بالخادم', 'error');
            });
        }

        // ============================================================
        // باقي الدوال
        // ============================================================

        // لائحة الشرف
        let currentSlide = 0;
        let slideInterval = null;
        let honorSlideCount = 0;

        function renderHonorRoll(honorRoll) {
            const slider = document.getElementById('honorSlider');
            const dots = document.getElementById('honorDots');
            honorSlideCount = honorRoll.length;
            if (!honorSlideCount) {
                slider.style.width = '100%';
                slider.innerHTML = '<div class="honor-slide" style="width:100%;"><div class="honor-item"><div class="info"><div class="name">لا توجد بيانات كافية بعد</div></div></div></div>';
                dots.innerHTML = '';
                return;
            }
            slider.style.width = (honorSlideCount * 100) + '%';
            slider.innerHTML = honorRoll.map(h => `
                <div class="honor-slide" style="width:${100 / honorSlideCount}%;">
                    <div class="honor-item">
                        <span class="medal">${h.medal}</span>
                        <div class="avatar">${h.photo ? `<img src="${h.photo}" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">` : h.name.charAt(0)}</div>
                        <div class="info">
                            <div class="name">${h.name}</div>
                            <div class="title">${h.branch}</div>
                        </div>
                        <span class="percent">${h.rate}%</span>
                    </div>
                </div>
            `).join('');
            dots.innerHTML = honorRoll.map((h, i) => `<button class="dot ${i === 0 ? 'active' : ''}" data-slide="${i}" onclick="goToSlide(${i})"></button>`).join('');
            goToSlide(0);
        }

        function goToSlide(index) {
            currentSlide = index;
            const slider = document.getElementById('honorSlider');
            if (slider && honorSlideCount) slider.style.transform = `translateX(-${index * (100 / honorSlideCount)}%)`;
            document.querySelectorAll('.honor-dots .dot').forEach((dot, i) => {
                dot.classList.toggle('active', i === index);
            });
        }

        function nextSlide() {
            if (honorSlideCount > 1) goToSlide((currentSlide + 1) % honorSlideCount);
        }

        function startAutoSlide() {
            if (slideInterval) clearInterval(slideInterval);
            slideInterval = setInterval(nextSlide, 5000);
        }

        // أيام الدوام
        let shiftInfo = { start: '09:00', end: '15:00', graceMinutes: 15 };
        let todayAttendance = { checkedIn: false, checkedOut: false };
        let isDelegated = false;

        function updateWorkSchedule() {
            const now = new Date();
            const day = now.getDay();
            const hours = now.getHours();
            const minutes = now.getMinutes();

            document.querySelectorAll('.work-days .day').forEach(el => {
                const dayIndex = parseInt(el.dataset.day);
                el.classList.remove('active', 'today', 'inactive');
                if (dayIndex === day) el.classList.add('today');
                if (dayIndex >= 0 && dayIndex <= 4) el.classList.add('active');
                else el.classList.add('inactive');
                if (dayIndex === day && dayIndex >= 0 && dayIndex <= 4) el.classList.add('active');
            });

            const [startHour, startMinute] = shiftInfo.start.split(':').map(Number);
            const [endHour, endMinute] = shiftInfo.end.split(':').map(Number);
            const startTotal = startHour * 60 + startMinute;
            const endTotal = endHour * 60 + endMinute;
            const nowTotal = hours * 60 + minutes;
            const remaining = endTotal - nowTotal;
            const remainingHours = Math.floor(remaining / 60);
            const remainingMinutes = remaining % 60;

            const remainingEl = document.getElementById('remainingTime');
            const countdownEl = document.getElementById('countdownStatus');
            document.getElementById('startTime').textContent = shiftInfo.start;
            document.getElementById('endTime').textContent = shiftInfo.end;

            if (remaining > 0 && day >= 0 && day <= 4) {
                remainingEl.textContent = `${remainingHours} ساعات و ${remainingMinutes} دقيقة`;
                countdownEl.textContent = `⏳ متبقي ${remainingHours}h ${remainingMinutes}m`;
            } else if (day >= 5) {
                remainingEl.textContent = 'يوم عطلة';
                countdownEl.textContent = '🎉 يوم عطلة';
            } else {
                remainingEl.textContent = 'انتهى الدوام';
                countdownEl.textContent = '✅ انتهى الدوام';
            }

            const timeToStart = document.getElementById('timeToStart');
            const timeToEnd = document.getElementById('timeToEnd');
            if (nowTotal < startTotal) {
                const diff = startTotal - nowTotal;
                timeToStart.textContent = `يبدأ بعد ${Math.floor(diff/60)}h ${diff%60}m`;
                timeToEnd.textContent = shiftInfo.end;
            } else if (nowTotal < endTotal) {
                timeToStart.textContent = '✅ في الدوام';
                timeToEnd.textContent = `ينتهي بعد ${remainingHours}h ${remainingMinutes}m`;
            } else {
                timeToStart.textContent = '✅ انتهى الدوام';
                timeToEnd.textContent = '✅ انتهى';
            }

            updateAttendanceButtonVisibility();
        }

        // ============================================================
        // إظهار/إخفاء أزرار البصمة حسب توقيت الدوام
        // ============================================================
        function updateAttendanceButtonVisibility() {
            const inBtns = [document.getElementById('checkInBtn'), document.getElementById('checkInBtn2')];
            const outBtns = [document.getElementById('checkOutBtn'), document.getElementById('checkOutBtn2')];

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

            inBtns.forEach(btn => { if (btn) btn.classList.toggle('hidden', !showIn); });
            outBtns.forEach(btn => { if (btn) btn.classList.toggle('hidden', !showOut); });

            const statusDiv = document.getElementById('attendanceStatus');
            if (statusDiv) {
                if (todayAttendance.checkedIn && todayAttendance.checkedOut) {
                    statusDiv.style.display = 'block';
                    statusDiv.style.color = 'var(--green)';
                    statusDiv.textContent = '✅ تم تسجيل حضورك وانصرافك اليوم';
                } else if (!showIn && !todayAttendance.checkedIn && nowMinutes > shiftStartMin + grace) {
                    statusDiv.style.display = 'block';
                    statusDiv.style.color = '#DC2626';
                    statusDiv.textContent = '✕ غياب — انتهت فترة تسجيل الحضور دون تسجيلك';
                } else if (!showIn && !showOut && !todayAttendance.checkedIn) {
                    statusDiv.style.display = 'block';
                    statusDiv.style.color = 'var(--text-muted)';
                    statusDiv.textContent = 'يظهر زر تسجيل الحضور من ' + shiftInfo.start + ' حتى مضي ' + grace + ' دقيقة من بداية الدوام';
                } else if (!showIn && !showOut && todayAttendance.checkedIn) {
                    statusDiv.style.display = 'block';
                    statusDiv.style.color = 'var(--text-muted)';
                    statusDiv.textContent = 'يظهر زر تسجيل الانصراف عند نهاية الدوام (' + shiftInfo.end + ') لمدة 15 دقيقة فقط';
                } else {
                    statusDiv.style.display = 'none';
                }
            }
        }
        setInterval(updateAttendanceButtonVisibility, 30000);

        // ============================================================
        // نافذة تقديم الطلب
        // ============================================================
        function openRequestModal(type) {
            const modal = document.getElementById('requestModal');
            const title = document.getElementById('requestModalTitle');
            const fields = document.getElementById('requestFields');
            const typeInput = document.getElementById('requestType');

            typeInput.value = type;
            title.innerHTML = `<i class="fas fa-file-pen"></i> تقديم طلب ${type}`;

            let html = '';
            if (type === 'إجازة') {
                html = `
                    <div class="form-group">
                        <label>نوع الإجازة <span class="required">*</span></label>
                        <select id="reqLeaveType">
                            <option value="سنوية">إجازة سنوية</option>
                            <option value="مرضية">إجازة مرضية</option>
                            <option value="اضطرارية">إجازة اضطرارية</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label>تاريخ البداية <span class="required">*</span></label>
                            <input type="date" id="reqStartDate" value="${new Date().toISOString().split('T')[0]}">
                        </div>
                        <div class="form-group">
                            <label>تاريخ النهاية <span class="required">*</span></label>
                            <input type="date" id="reqEndDate" value="${new Date(Date.now()+7*86400000).toISOString().split('T')[0]}">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>السبب <span class="required">*</span></label>
                        <textarea id="reqReason" placeholder="اكتب سبب الإجازة..." required></textarea>
                    </div>
                    <div class="form-group">
                        <label>المرفقات</label>
                        <input type="file" id="reqAttachment" accept=".pdf,.doc,.docx,.jpg,.png">
                    </div>
                `;
            } else if (type === 'سلفة') {
                html = `
                    <div class="form-group">
                        <label>مبلغ السلفة <span class="required">*</span></label>
                        <input type="number" id="reqLoanAmount" placeholder="أدخل المبلغ" min="10000" step="10000" value="500000">
                    </div>
                    <div class="form-group">
                        <label>عدد دفعات التسديد</label>
                        <select id="reqLoanInstallments">
                            <option value="3">3 دفعات</option>
                            <option value="6" selected>6 دفعات</option>
                            <option value="10">10 دفعات</option>
                            <option value="12">12 دفعة</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>السبب <span class="required">*</span></label>
                        <textarea id="reqReason" placeholder="اكتب سبب طلب السلفة..." required></textarea>
                    </div>
                    <div class="form-group">
                        <label>ملاحظات</label>
                        <textarea id="reqNotes" placeholder="ملاحظات إضافية (اختياري)"></textarea>
                    </div>
                `;
            } else if (type === 'شكوى') {
                html = `
                    <div class="form-group">
                        <label>عنوان الشكوى <span class="required">*</span></label>
                        <input type="text" id="reqComplaintTitle" placeholder="أدخل عنوان الشكوى" required>
                    </div>
                    <div class="form-group">
                        <label>التفاصيل <span class="required">*</span></label>
                        <textarea id="reqReason" placeholder="اكتب تفاصيل الشكوى..." required></textarea>
                    </div>
                    <div class="form-group">
                        <label>المرفقات</label>
                        <input type="file" id="reqAttachment" accept=".pdf,.doc,.docx,.jpg,.png">
                    </div>
                `;
            } else if (type === 'استقالة') {
                html = `
                    <div class="form-group">
                        <label>تاريخ تقديم الاستقالة <span class="required">*</span></label>
                        <input type="date" id="reqResignDate" value="${new Date().toISOString().split('T')[0]}">
                    </div>
                    <div class="form-group">
                        <label>آخر يوم عمل مقترح <span class="required">*</span></label>
                        <input type="date" id="reqLastDay" value="${new Date(Date.now()+30*86400000).toISOString().split('T')[0]}">
                    </div>
                    <div class="form-group">
                        <label>السبب <span class="required">*</span></label>
                        <textarea id="reqReason" placeholder="سبب الاستقالة..." required></textarea>
                    </div>
                    <div class="form-group">
                        <label>الملاحظات</label>
                        <textarea id="reqNotes" placeholder="ملاحظات إضافية"></textarea>
                    </div>
                    <div class="form-group">
                        <label>المرفقات</label>
                        <input type="file" id="reqAttachment" accept=".pdf,.doc,.docx,.jpg,.png">
                    </div>
                `;
            }

            fields.innerHTML = html;
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeRequestModal() {
            document.getElementById('requestModal').classList.remove('show');
            document.body.style.overflow = '';
        }

        function submitRequestForm(e) {
            e.preventDefault();
            const type = document.getElementById('requestType').value;
            const val = id => { const el = document.getElementById(id); return el ? el.value : ''; };

            const params = { requestType: type, reason: val('reqReason') };
            if (type === 'إجازة') {
                params.leaveType = val('reqLeaveType');
                params.startDate = val('reqStartDate');
                params.endDate = val('reqEndDate');
            } else if (type === 'سلفة') {
                params.loanAmount = val('reqLoanAmount');
                params.installments = val('reqLoanInstallments');
                params.notes = val('reqNotes');
            } else if (type === 'شكوى') {
                params.complaintTitle = val('reqComplaintTitle');
            } else if (type === 'استقالة') {
                params.resignDate = val('reqResignDate');
                params.lastDay = val('reqLastDay');
                params.notes = val('reqNotes');
            }

            const btn = e.target.querySelector('.btn-submit-request');
            btn.innerHTML = '<span class="spinner-small"></span> جاري الإرسال...';
            btn.disabled = true;

            fetch('?ajax=submit_request', { method: 'POST', body: new URLSearchParams(params) }).then(r => r.json()).then(data => {
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> إرسال الطلب';
                btn.disabled = false;
                if (!data.ok) {
                    showToast('⚠️ خطأ', data.error || 'تعذر إرسال الطلب', 'error');
                    return;
                }
                closeRequestModal();
                showToast('✅ تم الإرسال', `تم إرسال طلب ${type} بنجاح`, 'success');
                navigateTo('myRequests');
                loadMyRequests();
                loadBootstrap();
            }).catch(() => {
                btn.innerHTML = '<i class="fas fa-paper-plane"></i> إرسال الطلب';
                btn.disabled = false;
                showToast('⚠️ خطأ', 'تعذر الاتصال بالخادم', 'error');
            });
        }

        // ============================================================
        // تصفية الطلبات
        // ============================================================
        function filterRequests(filter) {
            const items = document.querySelectorAll('.request-item');
            items.forEach(item => {
                item.style.display = (filter === 'all' || item.dataset.status === filter) ? 'block' : 'none';
            });
            document.querySelectorAll('.quick-actions-grid .quick-action-btn').forEach(btn => {
                btn.style.borderColor = 'rgba(0,107,115,0.06)';
            });
            const btns = document.querySelectorAll('.quick-actions-grid .quick-action-btn');
            if (filter === 'all') btns[0].style.borderColor = 'var(--primary)';
            else btns[1].style.borderColor = 'var(--primary)';
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
            const navId = (page === 'briefing') ? 'nav-more' : ('nav-' + page);
            const navItem = document.getElementById(navId);
            if (navItem) navItem.classList.add('active');

            currentPage = page;
            window.scrollTo(0, 0);
            document.getElementById('sideMenu').style.display = 'none';

            if (page === 'requests' || page === 'myRequests') loadMyRequests();
            else if (page === 'commitment') loadCommitment();
            else if (page === 'salary') loadSalary();
            else if (page === 'briefing') loadBriefing();
            else if (page === 'notifications') loadNotifications();
            else if (page === 'attendanceHistory') loadAttendanceMonth();
        }

        function toggleMenu() {
            const menu = document.getElementById('sideMenu');
            menu.style.display = menu.style.display === 'none' ? 'block' : 'none';
        }

        function handleBriefingNavClick() {
            if (!isDelegated) {
                showToast('🔒 مقفل', 'إيجاز الفرع اليومي متاح فقط عند تفويضك من مدير الفرع لكتابته', 'warning');
                return;
            }
            navigateTo('briefing');
        }

        function handleLogout() {
            fetch('?ajax=logout', { method: 'POST' }).catch(() => {});
            if (slideInterval) clearInterval(slideInterval);
            document.getElementById('appContainer').classList.add('hidden');
            loginScreen.classList.remove('hidden');
            document.getElementById('sideMenu').style.display = 'none';
            showToast('👋 تم تسجيل الخروج', 'نتمنى رؤيتك قريباً', 'info');
        }

        function requestLogout() {
            showConfirmSheet('تسجيل الخروج', 'هل أنت متأكد من رغبتك في تسجيل الخروج؟', handleLogout);
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
        // تغيير كلمة المرور
        // ============================================================
        function changePassword() {
            const oldPw = document.getElementById('pwOld').value;
            const newPw = document.getElementById('pwNew').value;
            if (!oldPw || !newPw) {
                showToast('⚠️ تنبيه', 'يرجى تعبئة كلمة المرور الحالية والجديدة', 'warning');
                return;
            }
            if (newPw.length < 6) {
                showToast('⚠️ تنبيه', 'كلمة المرور الجديدة يجب ألا تقل عن 6 أحرف', 'warning');
                return;
            }
            fetch('?ajax=password_change', { method: 'POST', body: new URLSearchParams({ oldPassword: oldPw, newPassword: newPw }) })
                .then(r => r.json()).then(data => {
                    if (!data.ok) { showToast('⚠️ خطأ', data.error || 'تعذر تغيير كلمة المرور', 'error'); return; }
                    document.getElementById('pwOld').value = '';
                    document.getElementById('pwNew').value = '';
                    showToast('✅ تم التغيير', 'تم تغيير كلمة المرور بنجاح', 'success');
                }).catch(() => {
                    showToast('⚠️ خطأ', 'تعذر الاتصال بالخادم', 'error');
                });
        }

        function markAllRead() {
            fetch('?ajax=notifications_mark_all_read', { method: 'POST' }).then(() => {
                loadNotifications();
                showToast('✅ تم التحديد', 'تم تحديد جميع الإشعارات كمقروءة', 'success');
            });
        }

        // ============================================================
        // تحديث الوقت
        // ============================================================
        function updateTime() {
            const now = new Date();
            const timeStr = now.toLocaleTimeString('ar-SA', { hour: '2-digit', minute: '2-digit', second: '2-digit' });
            const dateStr = now.toLocaleDateString('ar-SA', { weekday: 'long', year: 'numeric', month: 'long',
                day: 'numeric' });
            const timeEl = document.getElementById('currentTime');
            const dateEl = document.getElementById('currentDate');
            if (timeEl) timeEl.textContent = timeStr;
            if (dateEl) dateEl.textContent = dateStr;
            updateWorkSchedule();
        }

        // ============================================================
        // Toast Notifications
        // ============================================================
        let toastId = 0;

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
            welcomeScreen.style.display = 'flex';
            loginScreen.classList.add('hidden');
            document.getElementById('appContainer').classList.add('hidden');

            setTimeout(animateLoader, 500);

            updateTime();
            setInterval(updateTime, 1000);

            document.addEventListener('click', function(e) {
                const menu = document.getElementById('sideMenu');
                const moreBtn = document.getElementById('nav-more');
                if (menu.style.display === 'block' && !menu.contains(e.target) && !moreBtn.contains(e.target)) {
                    menu.style.display = 'none';
                }
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    document.getElementById('sideMenu').style.display = 'none';
                    closeRequestModal();
                }
            });

            document.getElementById('requestModal').addEventListener('click', function(e) {
                if (e.target === this) closeRequestModal();
            });

            console.log('🏢 شركة الصوى للصرافة - نافذة الموظف');
            console.log('📍 يتم طلب تفعيل الموقع فقط عند الضغط على أزرار الحضور/الانصراف');
        });
    </script>

</body>
</html>