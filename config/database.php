<?php
// config/database.php — XAMPP Mac compatible

error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    $connection_success = false;
    $last_error = '';

    // Method 1: XAMPP default socket
    $socket_path = '/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock';
    if (file_exists($socket_path)) {
        try {
            $pdo = new PDO(
                "mysql:host=localhost;unix_socket=$socket_path;dbname=usat_archiving;charset=utf8mb4",
                'root',
                ''
            );
            $connection_success = true;
        } catch (PDOException $e) {
            $last_error = $e->getMessage();
        }
    }

    // Method 2: 127.0.0.1
    if (!$connection_success) {
        try {
            $pdo = new PDO(
                "mysql:host=127.0.0.1;port=3306;dbname=usat_archiving;charset=utf8mb4",
                'root',
                ''
            );
            $connection_success = true;
        } catch (PDOException $e) {
            $last_error = $e->getMessage();
        }
    }

    // Method 3: localhost
    if (!$connection_success) {
        try {
            $pdo = new PDO(
                "mysql:host=localhost;port=3306;dbname=usat_archiving;charset=utf8mb4",
                'root',
                ''
            );
            $connection_success = true;
        } catch (PDOException $e) {
            $last_error = $e->getMessage();
        }
    }

    // Method 4: /tmp/mysql.sock
    if (!$connection_success) {
        try {
            $pdo = new PDO(
                "mysql:host=localhost;unix_socket=/tmp/mysql.sock;dbname=usat_archiving;charset=utf8mb4",
                'root',
                ''
            );
            $connection_success = true;
        } catch (PDOException $e) {
            $last_error = $e->getMessage();
        }
    }

    if (!$connection_success) {
        die("Connection failed: " . $last_error);
    }

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}