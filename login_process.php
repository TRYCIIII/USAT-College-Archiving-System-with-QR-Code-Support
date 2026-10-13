<?php
/**
 * Login Processor — USAT College Archiving System
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');   // don't leak errors in login responses
ini_set('log_errors', '1');

// ============================================
// BYPASS PAGE GUARD
// ============================================
define('AUTH_SKIP_GUARD', true);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

// Already logged in → dashboard
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
// CSRF CHECK — must be first POST check
// ============================================
requireCSRF();

// ============================================
// INPUT VALIDATION
// ============================================
$username = trim((string)($_POST['username'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($username === '' || $password === '') {
    header('Location: index.php?error=empty');
    exit();
}

// ============================================
// RATE LIMITING — DB-based (per IP + per username)
// ============================================
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS login_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL,
            ip VARCHAR(45) NOT NULL,
            attempted_at DATETIME NOT NULL,
            INDEX idx_user_ip (username, ip),
            INDEX idx_time (attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM login_attempts
        WHERE (username = ? OR ip = ?)
          AND attempted_at > (NOW() - INTERVAL 5 MINUTE)
    ");
    $stmt->execute([$username, $clientIp]);
    $failCount = (int)$stmt->fetchColumn();

    if ($failCount >= 5) {
        header('Location: index.php?error=too_many');
        exit();
    }
} catch (PDOException $e) {
    error_log('Rate-limit table error: ' . $e->getMessage());
    // don't block login on rate-limit infra failure
}

// ============================================
// AUTHENTICATE
// ============================================
try {
    $user = authenticateUser($pdo, $username, $password);

    if (!$user) {
        // Record failed attempt
        $pdo->prepare("
            INSERT INTO login_attempts (username, ip, attempted_at)
            VALUES (?, ?, NOW())
        ")->execute([$username, $clientIp]);

        header('Location: index.php?error=invalid');
        exit();
    }

    // Success: clear failed attempts for this username+IP
    $pdo->prepare("DELETE FROM login_attempts WHERE username = ? AND ip = ?")
        ->execute([$username, $clientIp]);

    // Create session (regenerates ID + sets fingerprint)
    createUserSession($user);

    // Redirect target (if user was bounced from a protected page)
    $target = $_SESSION['redirect_after_login'] ?? 'dashboard.php';
    unset($_SESSION['redirect_after_login']);

    // Only allow internal redirects
    if (!preg_match('#^/[^/]#', $target) && !preg_match('#^[a-z0-9_\-]+\.php#i', $target)) {
        $target = 'dashboard.php';
    }

    header('Location: ' . $target);
    exit();

} catch (PDOException $e) {
    error_log('Login PDO error: ' . $e->getMessage());
    header('Location: index.php?error=system');
    exit();
} catch (Throwable $e) {
    error_log('Login system error: ' . $e->getMessage());
    header('Location: index.php?error=system');
    exit();
}