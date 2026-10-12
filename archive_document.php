<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$file_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($file_id <= 0) {
    header("Location: files.php?error=" . urlencode("Invalid file ID"));
    exit();
}

try {
    // Get folder_id before archiving
    $stmt = $pdo->prepare("SELECT folder_id, file_name FROM files WHERE id = ?");
    $stmt->execute([$file_id]);
    $file = $stmt->fetch();
    
    if (!$file) {
        throw new Exception("File not found");
    }
    
    $folder_id = $file['folder_id'];
    
    // Update status to archived
    $stmt = $pdo->prepare("UPDATE files SET status = 'archived', updated_at = NOW() WHERE id = ? AND status = 'active'");
    $result = $stmt->execute([$file_id]);
    
    if ($result && $stmt->rowCount() > 0) {
        header("Location: files.php?folder=" . $folder_id . "&success=file_archived");
        exit();
    } else {
        header("Location: files.php?folder=" . $folder_id . "&error=" . urlencode("File already archived or not found"));
        exit();
    }
    
} catch (Exception $e) {
    header("Location: files.php?error=" . urlencode($e->getMessage()));
    exit();
}
?>