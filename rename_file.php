<?php
session_start();
ob_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in
if (!isLoggedIn()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Not logged in']);
    } else {
        header("Location: index.php");
    }
    exit();
}

// For AJAX requests (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // Get POST data
    $file_id = isset($_POST['file_id']) ? (int)$_POST['file_id'] : 0;
    $new_name = isset($_POST['new_name']) ? trim($_POST['new_name']) : '';
    
    // Validate inputs
    if ($file_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid file ID']);
        exit();
    }
    
    if (empty($new_name)) {
        echo json_encode(['success' => false, 'error' => 'File name cannot be empty']);
        exit();
    }
    
    // Sanitize file name
    $new_name = htmlspecialchars(strip_tags($new_name));
    
    if (strlen($new_name) > 255) {
        echo json_encode(['success' => false, 'error' => 'File name is too long (max 255 characters)']);
        exit();
    }
    
    try {
        // Check if file exists and get its info
        $stmt = $pdo->prepare("SELECT f.*, u.full_name as uploader_name FROM files f 
                               LEFT JOIN users u ON f.uploaded_by = u.id 
                               WHERE f.id = ?");
        $stmt->execute([$file_id]);
        $file = $stmt->fetch();
        
        if (!$file) {
            echo json_encode(['success' => false, 'error' => 'File not found']);
            exit();
        }
        
        // Check permission (admin or the uploader)
        $user_id = getCurrentUserId();
        if (!isAdmin() && $file['uploaded_by'] != $user_id) {
            echo json_encode(['success' => false, 'error' => 'You don\'t have permission to rename this file']);
            exit();
        }
        
        // Check if file name already exists in the same folder
        $check = $pdo->prepare("SELECT id FROM files WHERE folder_id = ? AND file_name = ? AND id != ? AND status = 'active'");
        $check->execute([$file['folder_id'], $new_name, $file_id]);
        if ($check->rowCount() > 0) {
            echo json_encode(['success' => false, 'error' => 'A file with this name already exists in this folder']);
            exit();
        }
        
        // Update file name
        $update = $pdo->prepare("UPDATE files SET file_name = ?, updated_at = NOW() WHERE id = ?");
        $result = $update->execute([$new_name, $file_id]);
        
        if ($result) {
            // Log the activity
            if (function_exists('logActivity')) {
                logActivity('File renamed', "File ID: $file_id, Old name: {$file['file_name']}, New name: $new_name");
            }
            
            echo json_encode([
                'success' => true, 
                'message' => 'File renamed successfully',
                'new_name' => $new_name
            ]);
            exit();
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to rename file. No changes made.']);
            exit();
        }
        
    } catch (PDOException $e) {
        error_log("Rename file error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error occurred']);
        exit();
    }
}

// For GET requests (simple redirect from prompt)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id']) && isset($_GET['name'])) {
    $file_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $new_name = isset($_GET['name']) ? trim($_GET['name']) : '';
    
    if ($file_id > 0 && !empty($new_name)) {
        try {
            // Sanitize file name
            $new_name = htmlspecialchars(strip_tags($new_name));
            
            // Check if file exists
            $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
            $stmt->execute([$file_id]);
            $file = $stmt->fetch();
            
            if ($file) {
                // Check permission
                $user_id = getCurrentUserId();
                if (isAdmin() || $file['uploaded_by'] == $user_id) {
                    // Check for duplicate name
                    $check = $pdo->prepare("SELECT id FROM files WHERE folder_id = ? AND file_name = ? AND id != ?");
                    $check->execute([$file['folder_id'], $new_name, $file_id]);
                    
                    if ($check->rowCount() == 0) {
                        // Update file name
                        $update = $pdo->prepare("UPDATE files SET file_name = ?, updated_at = NOW() WHERE id = ?");
                        if ($update->execute([$new_name, $file_id])) {
                            $_SESSION['success'] = 'File renamed successfully!';
                        } else {
                            $_SESSION['error'] = 'Failed to rename file';
                        }
                    } else {
                        $_SESSION['error'] = 'A file with this name already exists';
                    }
                } else {
                    $_SESSION['error'] = 'You don\'t have permission to rename this file';
                }
            } else {
                $_SESSION['error'] = 'File not found';
            }
        } catch (PDOException $e) {
            error_log("Rename file error: " . $e->getMessage());
            $_SESSION['error'] = 'Database error occurred';
        }
    } else {
        $_SESSION['error'] = 'Invalid file name';
    }
    
    // Redirect back to the folder
    $redirect_folder = isset($file['folder_id']) ? $file['folder_id'] : 1;
    header("Location: files.php?folder=" . $redirect_folder);
    exit();
}

header("Location: files.php");
exit();
?>