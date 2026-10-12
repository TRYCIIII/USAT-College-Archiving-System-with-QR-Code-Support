<?php
/**
 * Logout handler
 * USAT College Archiving System
 *
 * - Prevents browser from caching this page
 * - Destroys session and cookie cleanly
 * - Redirects to login page
 */

// ============================================
// NO-CACHE HEADERS (must be before any output)
// ============================================
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

// ============================================
// START SESSION (needed to read existing session)
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================
// CLEAR ALL SESSION DATA
// ============================================
$_SESSION = [];

// ============================================
// DELETE SESSION COOKIE
// ============================================
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"]     ?? '/',
        $params["domain"]   ?? '',
        $params["secure"]   ?? false,
        $params["httponly"] ?? true
    );
}

// ============================================
// DESTROY SESSION
// ============================================
session_unset();
session_destroy();

// ============================================
// REDIRECT TO LOGIN
// ============================================
header("Location: index.php?logout=1");
exit();
?>