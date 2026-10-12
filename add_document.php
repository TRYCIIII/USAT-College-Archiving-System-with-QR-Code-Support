<?php
session_start();
ob_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

$file_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($file_id <= 0) {
    ob_end_clean();
    header("Location: files.php?error=" . urlencode("Invalid file ID"));
    exit();
}

try {
    // Get folder_id before archiving
    $stmt = $pdo->prepare("SELECT folder_id FROM files WHERE id = ?");
    $stmt->execute([$file_id]);
    $file = $stmt->fetch();
    
    if (!$file) {
        throw new Exception("File not found");
    }
    
    $folder_id = $file['folder_id'];
    
    // Update status to archived
    $stmt = $pdo->prepare("UPDATE files SET status = 'archived', updated_at = NOW() WHERE id = ?");
    $result = $stmt->execute([$file_id]);
    
    if ($result) {
        ob_end_clean();
        header("Location: files.php?folder=" . $folder_id . "&success=file_archived");
        exit();
    } else {
        throw new Exception("Failed to archive file");
    }
    
} catch (Exception $e) {
    ob_end_clean();
    header("Location: files.php?error=" . urlencode($e->getMessage()));
    exit();
}
?>