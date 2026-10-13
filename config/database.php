<?php
/**
 * config/database.php
 * Cross-platform PDO connection (Windows XAMPP + macOS XAMPP + Linux).
 *
 * USAT College Archiving System
 */

// ============================================
// ERROR REPORTING
// ============================================
// Set to false before deploying to production.
define('DB_DEBUG', true);

if (DB_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
    ini_set('log_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

// ============================================
// DATABASE CREDENTIALS
// ============================================
$DB_NAME = 'usat_archiving';
$DB_USER = 'root';
$DB_PASS = '';

// ============================================
// CONNECT
// ============================================
$pdo = null;
$last_error = '';

$candidates = [
    // Windows XAMPP (TCP)
    "mysql:host=127.0.0.1;port=3306;dbname={$DB_NAME};charset=utf8mb4",
    // macOS XAMPP (socket)
    "mysql:host=localhost;unix_socket=/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock;dbname={$DB_NAME};charset=utf8mb4",
    // Linux default socket
    "mysql:host=localhost;unix_socket=/var/run/mysqld/mysqld.sock;dbname={$DB_NAME};charset=utf8mb4",
    // Fallback (localhost TCP)
    "mysql:host=localhost;port=3306;dbname={$DB_NAME};charset=utf8mb4",
];

foreach ($candidates as $dsn) {
    try {
        $pdo = new PDO($dsn, $DB_USER, $DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_PERSISTENT         => false,
        ]);
        break; // success
    } catch (PDOException $e) {
        $last_error = $e->getMessage();
        $pdo = null;
    }
}

if (!$pdo) {
    error_log('DB connection failed: ' . $last_error);
    http_response_code(500);
    exit('Database connection error. Please try again later.');
}

// ============================================
// CONNECTION SANITY
// ============================================
try {
    $pdo->query('SELECT 1');
} catch (PDOException $e) {
    error_log('DB sanity check failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Database connection error. Please try again later.');
}