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

$folder_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($folder_id <= 0 || $folder_id == 1) {
    echo json_encode(['success' => false, 'error' => 'Invalid folder ID']);
    exit();
}

try {
    // Check if folder exists and is active
    $stmt = $pdo->prepare("SELECT * FROM folders WHERE id = ? AND (status IS NULL OR status = 'active')");
    $stmt->execute([$folder_id]);
    $folder = $stmt->fetch();
    
    if (!$folder) {
        echo json_encode(['success' => false, 'error' => 'Folder not found or already archived']);
        exit();
    }
    
    // Only admin can archive folders
    if (!isAdmin()) {
        echo json_encode(['success' => false, 'error' => 'Only administrators can archive folders']);
        exit();
    }
    
    // Start transaction
    $pdo->beginTransaction();
    
    // Archive folder
    $stmt = $pdo->prepare("UPDATE folders SET status = 'archived', updated_at = NOW() WHERE id = ?");
    $result1 = $stmt->execute([$folder_id]);
    
    if (!$result1) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Failed to archive folder']);
        exit();
    }
    
    // Archive files in folder
    $stmt = $pdo->prepare("UPDATE files SET status = 'archived', updated_at = NOW() WHERE folder_id = ? AND (status IS NULL OR status = 'active')");
    $stmt->execute([$folder_id]);
    $archived_files = $stmt->rowCount();
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Folder archived successfully: ' . $folder['folder_name'] . ' with ' . $archived_files . ' files',
        'folder_name' => $folder['folder_name'],
        'archived_files' => $archived_files
    ]);
    
} catch (PDOException $e) {
    if (isset($pdo)) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>