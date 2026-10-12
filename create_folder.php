<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/database.php';
require_once 'includes/auth.php';

if (!isLoggedIn()) {
    header("Location: index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $folder_name = isset($_POST['folder_name']) ? trim($_POST['folder_name']) : '';
    $parent_id = isset($_POST['parent_id']) ? (int)$_POST['parent_id'] : 1;
    
    if (empty($folder_name)) {
        $_SESSION['error'] = "Please enter a folder name!";
        header("Location: files.php?folder=" . $parent_id);
        exit();
    }
    
    try {
        // Check if folder with same name exists in the same parent
        $stmt = $pdo->prepare("SELECT id FROM folders WHERE parent_id = ? AND folder_name = ? AND status != 'deleted'");
        $stmt->execute([$parent_id, $folder_name]);
        if ($stmt->fetch()) {
            $_SESSION['error'] = "A folder with this name already exists!";
            header("Location: files.php?folder=" . $parent_id);
            exit();
        }
        
        // Insert new folder
        $stmt = $pdo->prepare("INSERT INTO folders (folder_name, parent_id, status, created_by, created_at, updated_at) VALUES (?, ?, 'active', ?, NOW(), NOW())");
        $stmt->execute([$folder_name, $parent_id, getCurrentUserId()]);
        
        // Log activity
        $table_check = $pdo->query("SHOW TABLES LIKE 'activity_log'");
        if ($table_check && $table_check->rowCount() > 0) {
            $user_id = getCurrentUserId();
            $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
            $description = "Created new folder: '{$folder_name}'";
            $log_stmt = $pdo->prepare("INSERT INTO activity_log (user_id, action, description, ip_address, created_at) VALUES (?, 'CREATE', ?, ?, NOW())");
            $log_stmt->execute([$user_id, $description, $ip_address]);
        }
        
        $_SESSION['success'] = "✓ Folder '{$folder_name}' created successfully!";
        header("Location: files.php?folder=" . $parent_id);
        exit();
        
    } catch (PDOException $e) {
        error_log("Database error in create_folder.php: " . $e->getMessage());
        $_SESSION['error'] = "Database error: " . $e->getMessage();
        header("Location: files.php?folder=" . $parent_id);
        exit();
    }
} else {
    header("Location: files.php");
    exit();
}
?>