<?php
/**
 * Logout handler — USAT College Archiving System
 */

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');

// Bypass page guard so this runs even if the session is already dead
define('AUTH_SKIP_GUARD', true);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

// Log the logout before we destroy the session
if (!empty($_SESSION['user_id'])) {
    try {
        logActivity('User logged out', 'Username: ' . ($_SESSION['username'] ?? ''));
    } catch (Throwable $e) {
        error_log('Logout log error: ' . $e->getMessage());
    }
}

// Full teardown (clears array, expires cookie, destroys session)
destroySession();

// Tell the browser to wipe any cached authenticated pages
header('Clear-Site-Data: "cache", "cookies", "storage"', false);

header('Location: index.php?logout=1');
exit;