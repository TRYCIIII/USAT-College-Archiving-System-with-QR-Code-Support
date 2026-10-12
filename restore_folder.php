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

if ($folder_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid folder ID']);
    exit();
}

// Root folder cannot be restored
if ($folder_id == 1) {
    echo json_encode(['success' => false, 'error' => 'Cannot restore the root folder']);
    exit();
}

try {
    // First check if folder exists
    $stmt = $pdo->prepare("SELECT * FROM folders WHERE id = ?");
    $stmt->execute([$folder_id]);
    $folder = $stmt->fetch();
    
    if (!$folder) {
        echo json_encode(['success' => false, 'error' => 'Folder not found']);
        exit();
    }
    
    // Check if folder is archived
    $current_status = $folder['status'] ?? 'active';
    
    if ($current_status !== 'archived') {
        echo json_encode([
            'success' => false, 
            'error' => 'Folder is not archived (Current status: ' . $current_status . ')'
        ]);
        exit();
    }
    
    // Only admin can restore folders
    if (!isAdmin()) {
        echo json_encode(['success' => false, 'error' => 'Only administrators can restore folders']);
        exit();
    }
    
    // Start transaction
    $pdo->beginTransaction();
    
    // Restore folder
    $stmt = $pdo->prepare("UPDATE folders SET status = 'active', updated_at = NOW() WHERE id = ?");
    $result1 = $stmt->execute([$folder_id]);
    
    if (!$result1) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Failed to restore folder']);
        exit();
    }
    
    // Restore all files in this folder
    $stmt = $pdo->prepare("UPDATE files SET status = 'active', updated_at = NOW() WHERE folder_id = ? AND status = 'archived'");
    $stmt->execute([$folder_id]);
    $restored_files = $stmt->rowCount();
    
    // Update trash bin if exists
    try {
        $stmt = $pdo->prepare("UPDATE trash_bin SET status = 'restored', restored_at = NOW(), restored_by = ? WHERE item_type = 'folder' AND item_id = ?");
        $stmt->execute([$_SESSION['user_id'], $folder_id]);
    } catch (PDOException $e) {
        // Ignore - trash bin table might not exist
    }
    
    // Log activity
    try {
        $stmt = $pdo->prepare("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, 'RESTORE_FOLDER', ?, ?)");
        $stmt->execute([
            $_SESSION['user_id'],
            "Restored folder: " . $folder['folder_name'] . " (ID: " . $folder_id . ") with " . $restored_files . " files",
            $_SERVER['REMOTE_ADDR']
        ]);
    } catch (PDOException $e) {
        // Ignore - activity log table might not exist
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Folder restored successfully: ' . $folder['folder_name'] . ' with ' . $restored_files . ' files',
        'folder_name' => $folder['folder_name'],
        'folder_id' => $folder_id,
        'parent_id' => $folder['parent_id'],
        'restored_files' => $restored_files
    ]);
    
} catch (PDOException $e) {
    if (isset($pdo)) $pdo->rollBack();
    error_log("Restore folder error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    if (isset($pdo)) $pdo->rollBack();
    error_log("Restore folder error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
?>