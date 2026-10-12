<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/database.php';
require_once 'includes/auth.php';

if (!isLoggedIn()) {
    header("HTTP/1.0 403 Forbidden");
    exit();
}

$file_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($file_id <= 0) {
    header("HTTP/1.0 400 Bad Request");
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
    $stmt->execute([$file_id]);
    $file = $stmt->fetch();
    
    if (!$file) {
        header("HTTP/1.0 404 Not Found");
        exit();
    }
    
    // Only allow viewing if file is active
    if ($file['status'] != 'active') {
        header("HTTP/1.0 403 Forbidden");
        exit();
    }
    
    // Find the file path
    $full_file_path = null;
    $possible_paths = [
        __DIR__ . '/' . $file['file_path'],
        __DIR__ . '/uploads/' . basename($file['file_path']),
        $file['file_path']
    ];
    
    foreach ($possible_paths as $path) {
        if (file_exists($path) && is_readable($path)) {
            $full_file_path = $path;
            break;
        }
    }
    
    if (!$full_file_path || !file_exists($full_file_path)) {
        header("HTTP/1.0 404 Not Found");
        exit();
    }
    
    $extension = strtolower($file['file_extension']);
    
    // MIME types for viewing
    $mime_types = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'txt' => 'text/plain'
    ];
    
    $mime_type = isset($mime_types[$extension]) ? $mime_types[$extension] : 'application/octet-stream';
    
    // Clear buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    
    header('Content-Type: ' . $mime_type);
    header('Content-Disposition: inline; filename="' . $file['file_name'] . '.' . $file['file_extension'] . '"');
    header('Content-Length: ' . filesize($full_file_path));
    header('Cache-Control: public, max-age=86400');
    
    readfile($full_file_path);
    exit();
    
} catch (PDOException $e) {
    error_log("Database error in serve_file.php: " . $e->getMessage());
    header("HTTP/1.0 500 Internal Server Error");
    exit();
}
?>