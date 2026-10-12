<?php
/**
 * Login Processor — USAT College Archiving System
 *
 * FIXES APPLIED:
 *   • Added AUTH_SKIP_GUARD so the page guard doesn't block login
 *   • Removed duplicate session_start() — auth.php handles it
 *   • Consistent redirect target (dashboard.php)
 *   • Rate limiting (5 attempts / 5 min per IP)
 *   • Uses authenticateUser() + createUserSession() from auth.php
 *   • Auto-creates users table + seeds demo accounts
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

// ============================================
// 0. BYPASS PAGE GUARD (must come BEFORE auth.php)
// ============================================
define('AUTH_SKIP_GUARD', true);

// ============================================
// 1. BOOTSTRAP
// ============================================
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

// Already logged in → bounce to dashboard
if (isLoggedIn()) {
    header('Location: dashboard.php');
    exit();
}

// POST only
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit();
}

// ============================================
// 2. LOGIN-ATTEMPT LOGGER (Windows-safe)
// ============================================
if (!function_exists('logLoginAttempt')) {
    function logLoginAttempt(string $username, bool $success, string $message): void {
        $logDir  = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
        $logFile = $logDir . DIRECTORY_SEPARATOR . 'login_attempts.log';

        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        if (!is_writable($logDir)) {
            error_log("Cannot write to log directory: {$logDir}");
            return;
        }

        $ip    = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
        $entry = sprintf(
            "%s | IP: %s | Username: %s | Success: %s | Message: %s%s",
            date('Y-m-d H:i:s'),
            $ip,
            $username,
            $success ? 'Yes' : 'No',
            $message,
            PHP_EOL
        );

        @file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);
    }
}

// ============================================
// 3. INPUT VALIDATION
// ============================================
$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    logLoginAttempt($username, false, 'Empty username or password');
    header('Location: index.php?error=empty');
    exit();
}

// ============================================
// 4. RATE LIMITING (5 attempts / 5 min per IP)
// ============================================
if (!function_exists('isRateLimited')) {
    function isRateLimited(string $ip): bool {
        $file   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'login_rl_' . md5($ip) . '.json';
        $now    = time();
        $window = 300;   // 5 minutes
        $max    = 5;

        $data = ['count' => 0, 'start' => $now];
        if (is_file($file)) {
            $raw  = @file_get_contents($file);
            $json = $raw ? json_decode($raw, true) : null;
            if (is_array($json)) $data = $json;
        }

        if (($now - (int)$data['start']) > $window) {
            $data = ['count' => 0, 'start' => $now];
        }

        $data['count'] = (int)$data['count'] + 1;
        @file_put_contents($file, json_encode($data), LOCK_EX);

        return $data['count'] > $max;
    }
}

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
if (isRateLimited($clientIp)) {
    logLoginAttempt($username, false, 'Rate limit exceeded');
    header('Location: index.php?error=too_many');
    exit();
}

// ============================================
// 5. DATABASE SANITY
// ============================================
if (!isset($pdo) || !($pdo instanceof PDO)) {
    error_log('login_process: PDO connection unavailable.');
    logLoginAttempt($username, false, 'Database connection missing');
    header('Location: index.php?error=system');
    exit();
}

try {
    // ----------------------------------------
    // 5a. Ensure `users` table exists
    // ----------------------------------------
    try {
        $pdo->query('SELECT 1 FROM users LIMIT 1');
    } catch (PDOException $e) {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(50) UNIQUE NOT NULL,
                password VARCHAR(255) NOT NULL,
                full_name VARCHAR(100) NOT NULL,
                email VARCHAR(100) NULL,
                role ENUM('admin','staff') DEFAULT 'staff',
                status ENUM('active','inactive') DEFAULT 'active',
                last_login DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    // ----------------------------------------
    // 5b. Ensure required columns exist
    // ----------------------------------------
    $columns = [];
    try {
        $cols    = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
        $columns = array_map('strtolower', $cols);
    } catch (PDOException $e) { /* ignore */ }

    if (!in_array('email', $columns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(100) NULL");
        $pdo->exec("UPDATE users SET email = CONCAT(username, '@usat.edu.ph')
                    WHERE email IS NULL OR email = ''");
        try {
            $pdo->exec("ALTER TABLE users ADD UNIQUE KEY uniq_email (email)");
        } catch (PDOException $e) { /* exists */ }
    }
    if (!in_array('last_login', $columns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN last_login DATETIME DEFAULT NULL");
    }
    if (!in_array('status', $columns, true)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN status ENUM('active','inactive') DEFAULT 'active'");
    }

    // ----------------------------------------
    // 5c. Seed / repair demo accounts
    // ----------------------------------------
    $defaults = [
        'admin'   => ['password' => 'admin123', 'full_name' => 'System Administrator', 'email' => 'admin@usat.edu.ph',   'role' => 'admin'],
        'jsantos' => ['password' => 'staff123', 'full_name' => 'Juan Santos',          'email' => 'jsantos@usat.edu.ph', 'role' => 'staff'],
        'mreyes'  => ['password' => 'staff123', 'full_name' => 'Maria Reyes',          'email' => 'mreyes@usat.edu.ph',  'role' => 'staff'],
    ];

    foreach ($defaults as $u => $data) {
        $stmt = $pdo->prepare('SELECT id, password FROM users WHERE username = ? LIMIT 1');
        $stmt->execute([$u]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            $pdo->prepare(
                "INSERT INTO users (username, password, full_name, email, role, status)
                 VALUES (?, ?, ?, ?, ?, 'active')"
            )->execute([
                $u,
                password_hash($data['password'], PASSWORD_DEFAULT),
                $data['full_name'],
                $data['email'],
                $data['role'],
            ]);
            continue;
        }

        $stored   = (string)($existing['password'] ?? '');
        $needsFix = false;

        if ($stored === '' ||
            in_array($stored, ['PASTE_YOUR_ADMIN_HASH_HERE','PASTE_YOUR_STAFF_HASH_HERE'], true)) {
            $needsFix = true;
        }
        // Repair staff accounts that accidentally got the admin hash
        if ($data['role'] === 'staff'
            && password_verify('admin123', $stored)
            && !password_verify('staff123', $stored)) {
            $needsFix = true;
        }

        if ($needsFix) {
            $pdo->prepare(
                "UPDATE users SET password = ?, full_name = ?, email = ?, role = ? WHERE username = ?"
            )->execute([
                password_hash($data['password'], PASSWORD_DEFAULT),
                $data['full_name'],
                $data['email'],
                $data['role'],
                $u,
            ]);
        }
    }

    // ============================================
    // 6. AUTHENTICATE (uses auth.php helper)
    // ============================================
    $user = authenticateUser($pdo, $username, $password);

    if (!$user) {
        logLoginAttempt($username, false, 'Invalid credentials');
        header('Location: index.php?error=invalid');
        exit();
    }

    // ============================================
    // 7. CREATE SESSION + REDIRECT
    // ============================================
    createUserSession($user);   // regenerates ID + sets fingerprint

    logLoginAttempt($username, true, 'Login successful');

    header('Location: dashboard.php');
    exit();

} catch (PDOException $e) {
    error_log('Login PDO Error: ' . $e->getMessage());
    logLoginAttempt($username, false, 'Database error: ' . $e->getMessage());
    header('Location: index.php?error=system');
    exit();
} catch (Throwable $e) {
    error_log('Login System Error: ' . $e->getMessage());
    logLoginAttempt($username, false, 'System error: ' . $e->getMessage());
    header('Location: index.php?error=system');
    exit();
}