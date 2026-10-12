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
    // Check if folder exists
    $stmt = $pdo->prepare("SELECT * FROM folders WHERE id = ?");
    $stmt->execute([$folder_id]);
    $folder = $stmt->fetch();
    
    if (!$folder) {
        echo json_encode(['success' => false, 'error' => 'Folder not found']);
        exit();
    }
    
    // Only admin can delete folders
    if (!isAdmin()) {
        echo json_encode(['success' => false, 'error' => 'Only administrators can delete folders']);
        exit();
    }
    
    // Get file count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM files WHERE folder_id = ?");
    $stmt->execute([$folder_id]);
    $file_count = $stmt->fetchColumn();
    
    // Start transaction
    $pdo->beginTransaction();
    
    // Delete folder
    $stmt = $pdo->prepare("UPDATE folders SET status = 'deleted', updated_at = NOW() WHERE id = ?");
    $result1 = $stmt->execute([$folder_id]);
    
    if (!$result1) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'error' => 'Failed to delete folder']);
        exit();
    }
    
    // Delete files in folder
    $stmt = $pdo->prepare("UPDATE files SET status = 'deleted', updated_at = NOW() WHERE folder_id = ?");
    $stmt->execute([$folder_id]);
    
    // Insert into trash bin
    try {
        $stmt = $pdo->prepare("INSERT INTO trash_bin (item_type, item_id, item_data, deleted_by, deleted_at) VALUES ('folder', ?, ?, ?, NOW())");
        $stmt->execute([$folder_id, json_encode($folder), $_SESSION['user_id']]);
    } catch (PDOException $e) {
        // Ignore
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Folder deleted successfully: ' . $folder['folder_name'] . ' with ' . $file_count . ' files',
        'folder_name' => $folder['folder_name'],
        'file_count' => $file_count
    ]);
    
} catch (PDOException $e) {
    if (isset($pdo)) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
?>