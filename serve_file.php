<?php
session_start();
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

$stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
$stmt->execute([$file_id]);
$file = $stmt->fetch();

if (!$file) {
    header("HTTP/1.0 404 Not Found");
    exit();
}

// Find the file path
$full_file_path = null;
$stored_path = $file['file_path'];

if (file_exists($stored_path)) {
    $full_file_path = $stored_path;
} elseif (file_exists(__DIR__ . '/' . ltrim($stored_path, '/'))) {
    $full_file_path = __DIR__ . '/' . ltrim($stored_path, '/');
} elseif (file_exists(__DIR__ . '/uploads/' . basename($stored_path))) {
    $full_file_path = __DIR__ . '/uploads/' . basename($stored_path);
}

if (!$full_file_path || !file_exists($full_file_path)) {
    header("HTTP/1.0 404 Not Found");
    exit();
}

// Set correct mime type
$extension = strtolower($file['file_extension']);
$mime_types = [
    'pdf' => 'application/pdf',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'gif' => 'image/gif',
    'txt' => 'text/plain',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
];

$mime = isset($mime_types[$extension]) ? $mime_types[$extension] : 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($full_file_path));
header('Cache-Control: public, max-age=86400');

// Clear output buffer
if (ob_get_level()) {
    ob_end_clean();
}

readfile($full_file_path);
exit();
?>