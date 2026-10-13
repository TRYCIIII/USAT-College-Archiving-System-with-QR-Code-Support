<?php
/**
 * Authentication and Authorization System
 * USAT College Archiving System
 */

if (defined('AUTH_SYSTEM_LOADED')) {
    return;
}
define('AUTH_SYSTEM_LOADED', true);

// ============================================
// SESSION BOOTSTRAP
// ============================================
if (session_status() === PHP_SESSION_NONE) {

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
               || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    // These must be set BEFORE session_start()
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');
    ini_set('session.gc_maxlifetime', '1800');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_start();
}

if (!defined('SESSION_TIMEOUT')) {
    define('SESSION_TIMEOUT', 1800); // 30 min idle
}

// ============================================
// SESSION HELPERS
// ============================================
if (!function_exists('isSessionTimedOut')) {
    function isSessionTimedOut(): bool {
        if (!isset($_SESSION['last_activity'])) return false;
        return (time() - (int)$_SESSION['last_activity']) > SESSION_TIMEOUT;
    }
}

if (!function_exists('updateLastActivity')) {
    function updateLastActivity(): void {
        $_SESSION['last_activity'] = time();
    }
}

/**
 * Build a stable fingerprint.
 * NOTE: We hash only the User-Agent, not the IP.
 * IP changes on mobile networks are common and would log users out
 * mid-session. UA + a rotating session id (via session_regenerate_id)
 * is the standard mitigation.
 */
if (!function_exists('buildFingerprint')) {
    function buildFingerprint(): string {
        return hash('sha256', ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown'));
    }
}

// ============================================
// SESSION DESTRUCTION
// ============================================
if (!function_exists('destroySession')) {
    function destroySession(): void {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $p['path']     ?? '/',
                $p['domain']   ?? '',
                $p['secure']   ?? false,
                $p['httponly'] ?? true
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}

if (!function_exists('logout')) {
    function logout(): void {
        destroySession();
    }
}

// ============================================
// LOGIN STATE
// ============================================
if (!function_exists('isLoggedIn')) {
    function isLoggedIn(): bool {
        if (empty($_SESSION['user_id'])) {
            return false;
        }

        // Fingerprint (UA only — see buildFingerprint comment)
        if (empty($_SESSION['fingerprint'])
            || !hash_equals($_SESSION['fingerprint'], buildFingerprint())) {
            destroySession();
            return false;
        }

        if (isSessionTimedOut()) {
            destroySession();
            return false;
        }

        updateLastActivity();
        return true;
    }
}

if (!function_exists('isAdmin')) {
    function isAdmin(): bool {
        return isLoggedIn() && (($_SESSION['role'] ?? '') === 'admin');
    }
}

if (!function_exists('isStaff')) {
    function isStaff(): bool {
        return isLoggedIn() && (($_SESSION['role'] ?? '') === 'staff');
    }
}

// ============================================
// ACCESS GUARDS
// ============================================
if (!function_exists('requireLogin')) {
    function requireLogin(string $redirect = 'index.php'): void {
        if (!isLoggedIn()) {
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '';
            header("Location: {$redirect}?error=login_required");
            exit();
        }
    }
}

if (!function_exists('requireAdmin')) {
    function requireAdmin(string $redirect = 'dashboard.php'): void {
        requireLogin();
        if (!isAdmin()) {
            error_log('Unauthorized admin access by User ID: ' . getCurrentUserId());
            $_SESSION['error_message'] = 'You do not have permission to access this page.';
            header("Location: {$redirect}");
            exit();
        }
    }
}

if (!function_exists('requireStaff')) {
    function requireStaff(string $redirect = 'dashboard.php'): void {
        requireLogin();
        if (!isStaff() && !isAdmin()) {
            error_log('Unauthorized staff access by User ID: ' . getCurrentUserId());
            $_SESSION['error_message'] = 'You do not have permission to access this page.';
            header("Location: {$redirect}");
            exit();
        }
    }
}

// ============================================
// CURRENT-USER ACCESSORS
// ============================================
if (!function_exists('getCurrentUserId')) {
    function getCurrentUserId(): int {
        return (int)($_SESSION['user_id'] ?? 0);
    }
}
if (!function_exists('getCurrentUserName')) {
    function getCurrentUserName(): string {
        return htmlspecialchars($_SESSION['full_name'] ?? 'Guest', ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('getCurrentUsername')) {
    function getCurrentUsername(): string {
        return htmlspecialchars($_SESSION['username'] ?? '', ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('getCurrentUserRole')) {
    function getCurrentUserRole(): string {
        return $_SESSION['role'] ?? 'guest';
    }
}
if (!function_exists('getCurrentUserEmail')) {
    function getCurrentUserEmail(): string {
        return htmlspecialchars($_SESSION['email'] ?? '', ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('getUserInitials')) {
    function getUserInitials(): string {
        $name = $_SESSION['full_name'] ?? 'U';
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $initials = '';
        foreach ($parts as $w) {
            if ($w !== '' && strlen($initials) < 2) {
                $initials .= strtoupper($w[0]);
            }
        }
        return $initials ?: 'U';
    }
}

// ============================================
// PERMISSIONS
// ============================================
if (!function_exists('hasPermission')) {
    function hasPermission(string $permission): bool {
        if (!isLoggedIn()) return false;

        $role = getCurrentUserRole();
        $permissions = [
            'admin' => [
                'view_dashboard','manage_users','create_users','edit_users','delete_users',
                'manage_folders','manage_files','upload_files','delete_files','archive_files',
                'view_reports','export_data','manage_settings','view_logs'
            ],
            'staff' => [
                'view_dashboard','manage_folders','manage_files','upload_files','view_reports'
            ]
        ];
        return isset($permissions[$role])
            && in_array($permission, $permissions[$role], true);
    }
}

// ============================================
// FLASH MESSAGES
// ============================================
if (!function_exists('setFlashMessage')) {
    function setFlashMessage(string $type, string $message): void {
        $_SESSION['flash_messages'][$type] = $message;
    }
}
if (!function_exists('getFlashMessage')) {
    function getFlashMessage(string $type): ?string {
        if (isset($_SESSION['flash_messages'][$type])) {
            $msg = $_SESSION['flash_messages'][$type];
            unset($_SESSION['flash_messages'][$type]);
            return $msg;
        }
        return null;
    }
}
if (!function_exists('displayFlashMessage')) {
    function displayFlashMessage(string $type): string {
        $msg = getFlashMessage($type);
        if (!$msg) return '';

        $map = [
            'success' => ['alert-success', 'fa-check-circle'],
            'error'   => ['alert-danger',  'fa-exclamation-circle'],
            'warning' => ['alert-warning', 'fa-exclamation-triangle'],
            'info'    => ['alert-info',    'fa-info-circle'],
        ];
        [$cls, $icon] = $map[$type] ?? ['alert-info', 'fa-info-circle'];

        return "<div class='alert {$cls}'><i class='fas {$icon}'></i> "
             . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . "</div>";
    }
}
if (!function_exists('redirectWithMessage')) {
    function redirectWithMessage(string $url, string $type, string $message): void {
        setFlashMessage($type, $message);
        header("Location: {$url}");
        exit();
    }
}

// ============================================
// CSRF
// ============================================
if (!function_exists('generateCSRFToken')) {
    function generateCSRFToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}
if (!function_exists('verifyCSRFToken')) {
    function verifyCSRFToken(?string $token): bool {
        return !empty($_SESSION['csrf_token'])
            && !empty($token)
            && hash_equals($_SESSION['csrf_token'], $token);
    }
}
if (!function_exists('csrfField')) {
    function csrfField(): string {
        return '<input type="hidden" name="csrf_token" value="'
             . htmlspecialchars(generateCSRFToken(), ENT_QUOTES, 'UTF-8') . '">';
    }
}
if (!function_exists('requireCSRF')) {
    function requireCSRF(): void {
        $sent = $_POST['csrf_token'] ?? '';
        if (!verifyCSRFToken($sent)) {
            http_response_code(403);
            exit('CSRF token mismatch.');
        }
    }
}

// ============================================
// ACTIVITY LOG
// ============================================
if (!function_exists('logActivity')) {
    function logActivity(string $action, string $details = ''): void {
        $uid   = getCurrentUserId();
        $uname = $_SESSION['username'] ?? 'guest';
        $ip    = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $entry = sprintf(
            "[%s] User: %s (ID: %d) | IP: %s | Action: %s | %s\n",
            date('Y-m-d H:i:s'),
            $uname, $uid, $ip, $action, $details
        );

        $dir = __DIR__ . '/../logs';
        if (!is_dir($dir)) @mkdir($dir, 0777, true);
        @file_put_contents($dir . '/activity.log', $entry, FILE_APPEND | LOCK_EX);
    }
}

// ============================================
// DATABASE HELPERS
// ============================================
if (!function_exists('isDatabaseConnected')) {
    function isDatabaseConnected(PDO $pdo): bool {
        try { $pdo->query('SELECT 1'); return true; }
        catch (PDOException $e) {
            error_log('Database connection error: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('authenticateUser')) {
    function authenticateUser(PDO $pdo, string $username, string $password): ?array {
        try {
            $stmt = $pdo->prepare(
                "SELECT * FROM users WHERE username = ? AND status = 'active' LIMIT 1"
            );
            $stmt->execute([$username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                logActivity('Failed login', "Username: {$username} (not found)");
                return null;
            }

            if (password_verify($password, $user['password'])) {
                $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")
                    ->execute([$user['id']]);

                // Rehash if bcrypt cost has been upgraded
                if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
                    $new = password_hash($password, PASSWORD_DEFAULT);
                    $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
                        ->execute([$new, $user['id']]);
                }

                logActivity('User logged in', "Username: {$username}");
                return $user;
            }

            // Legacy plaintext migration
            if (hash_equals((string)$user['password'], $password)) {
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password = ?, last_login = NOW() WHERE id = ?")
                    ->execute([$newHash, $user['id']]);
                logActivity('User logged in (plaintext migrated)', "Username: {$username}");
                return $user;
            }

            logActivity('Failed login', "Username: {$username} (bad password)");
            return null;

        } catch (PDOException $e) {
            error_log('authenticateUser error: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('createUserSession')) {
    function createUserSession(array $user): void {
        session_regenerate_id(true);

        $_SESSION['user_id']       = (int)$user['id'];
        $_SESSION['username']      = $user['username'];
        $_SESSION['full_name']     = $user['full_name'];
        $_SESSION['email']         = $user['email'] ?? '';
        $_SESSION['role']          = $user['role'];
        $_SESSION['last_activity'] = time();
        $_SESSION['fingerprint']   = buildFingerprint();
    }
}

// ============================================
// AUTH ERROR MESSAGES
// ============================================
if (!function_exists('getAuthErrorMessage')) {
    function getAuthErrorMessage(string $error): string {
        $messages = [
            'login_required'      => 'Please log in to access this page.',
            'unauthorized'        => 'You do not have permission to access this page.',
            'invalid_credentials' => 'Invalid username or password.',
            'account_inactive'    => 'Your account is inactive.',
            'session_expired'     => 'Your session has expired. Please log in again.',
            'system_error'        => 'A system error occurred. Please try again later.',
        ];
        return $messages[$error] ?? 'An error occurred.';
    }
}

// ============================================
// GLOBAL PAGE GUARD
// ============================================
if (!defined('AUTH_SKIP_GUARD') || AUTH_SKIP_GUARD !== true) {

    $public_pages = [
        'index.php',
        'login_process.php',
        'forgot_password.php',
        'reset_password.php',
        'logout.php',
    ];

    $current_page = basename($_SERVER['PHP_SELF'] ?? '');

    if (!isLoggedIn() && !in_array($current_page, $public_pages, true)) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '';
        header('Location: index.php?error=login_required');
        exit();
    }

    if (isLoggedIn() && $current_page === 'index.php' && !isset($_GET['logout'])) {
        header('Location: dashboard.php');
        exit();
    }
}

// ============================================
// SECURITY HEADERS
// ============================================
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(self)');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
}