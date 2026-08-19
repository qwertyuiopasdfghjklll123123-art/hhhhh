<?php
/**
 * تعريف بنية قاعدة البيانات (الجداول) والبيانات الافتراضية (Seed).
 * يستخدمه المثبّت (install.php) لإنشاء الجداول تلقائياً إن لم تكن موجودة،
 * ويمكن استدعاؤه لاحقاً بأمان لأنه يعتمد على CREATE TABLE IF NOT EXISTS.
 */

function schema_statements(): array
{
    return [
        'settings' => "CREATE TABLE IF NOT EXISTS settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_name VARCHAR(150) NOT NULL DEFAULT 'شركة الصرافة',
            company_email VARCHAR(150) DEFAULT NULL,
            work_start_time TIME NOT NULL DEFAULT '09:00:00',
            work_end_time TIME NOT NULL DEFAULT '15:00:00',
            usd_exchange_rate DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'branches' => "CREATE TABLE IF NOT EXISTS branches (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            location VARCHAR(150) DEFAULT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            notes TEXT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'employees' => "CREATE TABLE IF NOT EXISTS employees (
            id INT AUTO_INCREMENT PRIMARY KEY,
            branch_id INT NOT NULL,
            employee_number VARCHAR(20) NOT NULL UNIQUE,
            full_name VARCHAR(100) NOT NULL,
            national_id VARCHAR(50) DEFAULT NULL,
            job_title VARCHAR(100) DEFAULT NULL,
            birth_date DATE DEFAULT NULL,
            hire_date DATE DEFAULT NULL,
            shift_start TIME DEFAULT NULL,
            shift_end TIME DEFAULT NULL,
            base_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
            photo VARCHAR(255) DEFAULT NULL,
            documents VARCHAR(255) DEFAULT NULL,
            is_branch_manager TINYINT(1) NOT NULL DEFAULT 0,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_emp_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'users' => "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            role ENUM('hr','branch_manager','employee') NOT NULL,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            employee_id INT DEFAULT NULL,
            branch_id INT DEFAULT NULL,
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_user_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
            CONSTRAINT fk_user_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'attendance' => "CREATE TABLE IF NOT EXISTS attendance (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            branch_id INT NOT NULL,
            attendance_date DATE NOT NULL,
            check_in TIME DEFAULT NULL,
            check_out TIME DEFAULT NULL,
            status ENUM('present','late','absent') NOT NULL DEFAULT 'present',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_emp_date (employee_id, attendance_date),
            CONSTRAINT fk_att_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
            CONSTRAINT fk_att_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'payroll' => "CREATE TABLE IF NOT EXISTS payroll (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            branch_id INT NOT NULL,
            period_month TINYINT NOT NULL,
            period_year SMALLINT NOT NULL,
            base_salary DECIMAL(12,2) NOT NULL DEFAULT 0,
            bonus DECIMAL(12,2) NOT NULL DEFAULT 0,
            deduction DECIMAL(12,2) NOT NULL DEFAULT 0,
            status ENUM('pending','delivered') NOT NULL DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_emp_period (employee_id, period_month, period_year),
            CONSTRAINT fk_pay_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
            CONSTRAINT fk_pay_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'requests' => "CREATE TABLE IF NOT EXISTS requests (
            id INT AUTO_INCREMENT PRIMARY KEY,
            employee_id INT NOT NULL,
            branch_id INT NOT NULL,
            type ENUM('leave','advance','complaint','resignation') NOT NULL,
            details TEXT DEFAULT NULL,
            amount DECIMAL(12,2) DEFAULT NULL,
            date_from DATE DEFAULT NULL,
            date_to DATE DEFAULT NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
            reviewed_by INT DEFAULT NULL,
            review_note VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_req_employee FOREIGN KEY (employee_id) REFERENCES employees(id) ON DELETE CASCADE,
            CONSTRAINT fk_req_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'delegations' => "CREATE TABLE IF NOT EXISTS delegations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            branch_id INT NOT NULL,
            delegated_employee_id INT NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            status ENUM('active','ended') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_del_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
            CONSTRAINT fk_del_employee FOREIGN KEY (delegated_employee_id) REFERENCES employees(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'daily_ledger' => "CREATE TABLE IF NOT EXISTS daily_ledger (
            id INT AUTO_INCREMENT PRIMARY KEY,
            branch_id INT NOT NULL,
            entry_date DATE NOT NULL,
            entry_type ENUM('income','expense') NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            description VARCHAR(255) DEFAULT NULL,
            attachment VARCHAR(255) DEFAULT NULL,
            created_by INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_ledger_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'exchange_rate_history' => "CREATE TABLE IF NOT EXISTS exchange_rate_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            rate DECIMAL(10,2) NOT NULL,
            updated_by VARCHAR(100) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

        'notifications' => "CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            title VARCHAR(150) NOT NULL,
            message VARCHAR(255) DEFAULT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    ];
}

/**
 * ترتيب الإنشاء مهم بسبب المفاتيح الأجنبية: الجداول التي لا تعتمد على غيرها أولاً.
 */
function schema_creation_order(): array
{
    return [
        'settings', 'branches', 'employees', 'users', 'attendance',
        'payroll', 'requests', 'delegations', 'daily_ledger',
        'exchange_rate_history', 'notifications',
    ];
}

/**
 * إدراج البيانات الافتراضية (Seed) بعد إنشاء الجداول:
 * - صف إعدادات افتراضي
 * - فرع تجريبي
 * - حساب مدير موارد بشرية افتراضي (HR) لتسجيل الدخول أول مرة
 */
function seed_default_data(PDO $pdo, string $hrEmail, string $hrPassword): void
{
    $count = (int) $pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn();
    if ($count === 0) {
        $pdo->exec("INSERT INTO settings (company_name, company_email, usd_exchange_rate) VALUES ('شركة الصوى للصرافة', 'info@example.com', 1320)");
    }

    $branchId = (int) $pdo->query("SELECT id FROM branches ORDER BY id ASC LIMIT 1")->fetchColumn();
    if (!$branchId) {
        $stmt = $pdo->prepare("INSERT INTO branches (name, location, status) VALUES (?, ?, 'active')");
        $stmt->execute(['الفرع الرئيسي', 'بغداد']);
        $branchId = (int) $pdo->lastInsertId();
    }

    $hrExists = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role = 'hr' LIMIT 1");
    $hrExists->execute();
    if ((int) $hrExists->fetchColumn() === 0) {
        $hash = password_hash($hrPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (role, username, password_hash, branch_id, status) VALUES ('hr', ?, ?, NULL, 'active')");
        $stmt->execute([$hrEmail, $hash]);
    }
}
