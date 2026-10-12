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
    // Check if file exists
    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
    $stmt->execute([$file_id]);
    $file = $stmt->fetch();
    
    if (!$file) {
        echo json_encode(['success' => false, 'error' => 'File not found']);
        exit();
    }
    
    // Get current status
    $current_status = $file['status'] ?? 'active';
    
    // If file is already active, just return success
    if ($current_status == 'active') {
        echo json_encode([
            'success' => true,
            'message' => 'File is already active: ' . $file['file_name'],
            'file_name' => $file['file_name'],
            'already_active' => true
        ]);
        exit();
    }
    
    // If file is archived, restore it
    if ($current_status == 'archived') {
        // Check permission
        $user_id = $_SESSION['user_id'] ?? 0;
        if (!isAdmin() && isset($file['uploaded_by']) && $file['uploaded_by'] != $user_id) {
            echo json_encode(['success' => false, 'error' => 'You don\'t have permission to restore this file']);
            exit();
        }
        
        // Restore file - set status back to active
        $stmt = $pdo->prepare("UPDATE files SET status = 'active', updated_at = NOW() WHERE id = ?");
        $result = $stmt->execute([$file_id]);
        
        if (!$result) {
            echo json_encode(['success' => false, 'error' => 'Failed to restore file']);
            exit();
        }
        
        // Update trash bin if exists
        try {
            $stmt = $pdo->prepare("UPDATE trash_bin SET status = 'restored', restored_at = NOW(), restored_by = ? WHERE item_type = 'file' AND item_id = ?");
            $stmt->execute([$user_id, $file_id]);
        } catch (PDOException $e) {
            // Ignore
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'File restored successfully: ' . $file['file_name'],
            'file_name' => $file['file_name'],
            'file_id' => $file_id,
            'folder_id' => $file['folder_id']
        ]);
        exit();
    }
    
    // If file has any other status (deleted, etc.)
    echo json_encode([
        'success' => false,
        'error' => 'Cannot restore file with status: ' . $current_status
    ]);
    
} catch (PDOException $e) {
    error_log("Restore file error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    error_log("Restore file error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
?>