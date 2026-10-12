<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/database.php';
require_once 'includes/auth.php';

header('Content-Type: application/json');

// Check if user is logged in
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

$file_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($file_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid file ID']);
    exit();
}

try {
    // Check if file exists and is active
    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ? AND (status IS NULL OR status = 'active')");
    $stmt->execute([$file_id]);
    $file = $stmt->fetch();
    
    if (!$file) {
        echo json_encode(['success' => false, 'error' => 'File not found or already archived']);
        exit();
    }
    
    // Check permission
    $user_id = $_SESSION['user_id'] ?? 0;
    if (!isAdmin() && isset($file['uploaded_by']) && $file['uploaded_by'] != $user_id) {
        echo json_encode(['success' => false, 'error' => 'You don\'t have permission to archive this file']);
        exit();
    }
    
    // Archive file
    $stmt = $pdo->prepare("UPDATE files SET status = 'archived', updated_at = NOW() WHERE id = ?");
    $result = $stmt->execute([$file_id]);
    
    if (!$result) {
        echo json_encode(['success' => false, 'error' => 'Failed to archive file']);
        exit();
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'File archived successfully: ' . $file['file_name'],
        'file_name' => $file['file_name']
    ]);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>