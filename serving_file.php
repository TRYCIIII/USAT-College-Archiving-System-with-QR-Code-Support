<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header("HTTP/1.0 403 Forbidden");
    die("Access denied");
}

$file_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$view_mode = isset($_GET['view']) && $_GET['view'] == '1';

if ($file_id <= 0) {
    header("HTTP/1.0 404 Not Found");
    die("Invalid file ID");
}

try {
    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
    $stmt->execute([$file_id]);
    $file = $stmt->fetch();
    
    if (!$file) {
        header("HTTP/1.0 404 Not Found");
        die("File not found");
    }
    
    $full_file_path = __DIR__ . '/' . $file['file_path'];
    
    if (!file_exists($full_file_path)) {
        header("HTTP/1.0 404 Not Found");
        die("File not found on server");
    }
    
    $extension = strtolower($file['file_extension']);
    $file_name = $file['file_name'] . '.' . $file['file_extension'];
    
    // Define MIME types
    $mime_types = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'txt' => 'text/plain',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'
    ];
    
    $mime_type = isset($mime_types[$extension]) ? $mime_types[$extension] : 'application/octet-stream';
    
    // Set headers
    header('Content-Type: ' . $mime_type);
    header('Content-Length: ' . filesize($full_file_path));
    header('Cache-Control: private, max-age=3600');
    
    if (!$view_mode) {
        header('Content-Disposition: inline; filename="' . $file_name . '"');
    }
    
    // Output file
    readfile($full_file_path);
    exit();
    
} catch (PDOException $e) {
    header("HTTP/1.0 500 Internal Server Error");
    die("Database error");
}
?>