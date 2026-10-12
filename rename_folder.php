<?php
session_start();
ob_start(); // Add output buffering
require_once 'config/database.php';
require_once 'includes/auth.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in
if (!isLoggedIn()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not logged in']);
    } else {
        header("Location: index.php");
    }
    exit();
}

// For AJAX requests (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // Get POST data
    $folder_id = isset($_POST['folder_id']) ? (int)$_POST['folder_id'] : 0;
    $new_name = isset($_POST['new_name']) ? trim($_POST['new_name']) : '';
    
    // Validate inputs
    if ($folder_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid folder ID']);
        exit();
    }
    
    if (empty($new_name)) {
        echo json_encode(['success' => false, 'error' => 'Folder name cannot be empty']);
        exit();
    }
    
    // Sanitize folder name
    $new_name = htmlspecialchars(strip_tags($new_name));
    
    if (strlen($new_name) > 255) {
        echo json_encode(['success' => false, 'error' => 'Folder name is too long (max 255 characters)']);
        exit();
    }
    
    try {
        // Check if folder exists and get its info
        $stmt = $pdo->prepare("SELECT f.*, u.full_name as creator_name FROM folders f 
                               LEFT JOIN users u ON f.created_by = u.id 
                               WHERE f.id = ?");
        $stmt->execute([$folder_id]);
        $folder = $stmt->fetch();
        
        if (!$folder) {
            echo json_encode(['success' => false, 'error' => 'Folder not found']);
            exit();
        }
        
        // Check permission (admin or the creator)
        $user_id = getCurrentUserId();
        if (!isAdmin() && $folder['created_by'] != $user_id) {
            echo json_encode(['success' => false, 'error' => 'You don\'t have permission to rename this folder']);
            exit();
        }
        
        // Check if folder name already exists in the same parent directory
        $check = $pdo->prepare("SELECT id FROM folders WHERE parent_id = ? AND folder_name = ? AND id != ? AND status = 'active'");
        $check->execute([$folder['parent_id'], $new_name, $folder_id]);
        if ($check->rowCount() > 0) {
            echo json_encode(['success' => false, 'error' => 'A folder with this name already exists in this location']);
            exit();
        }
        
        // Update folder name
        $update = $pdo->prepare("UPDATE folders SET folder_name = ?, updated_at = NOW() WHERE id = ?");
        $result = $update->execute([$new_name, $folder_id]);
        
        if ($result) {
            // Log the activity
            if (function_exists('logActivity')) {
                logActivity('Folder renamed', "Folder ID: $folder_id, Old name: {$folder['folder_name']}, New name: $new_name");
            }
            
            echo json_encode([
                'success' => true, 
                'message' => 'Folder renamed successfully',
                'new_name' => $new_name
            ]);
            exit();
        } else {
            echo json_encode(['success' => false, 'error' => 'Failed to rename folder. No changes made.']);
            exit();
        }
        
    } catch (PDOException $e) {
        error_log("Rename folder error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => 'Database error occurred']);
        exit();
    }
}

// For GET requests (simple redirect from prompt)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['id']) && isset($_GET['name'])) {
    $folder_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $new_name = isset($_GET['name']) ? trim($_GET['name']) : '';
    
    if ($folder_id > 0 && !empty($new_name)) {
        try {
            // Sanitize folder name
            $new_name = htmlspecialchars(strip_tags($new_name));
            
            // Check if folder exists
            $stmt = $pdo->prepare("SELECT * FROM folders WHERE id = ?");
            $stmt->execute([$folder_id]);
            $folder = $stmt->fetch();
            
            if ($folder) {
                // Check permission
                $user_id = getCurrentUserId();
                if (isAdmin() || $folder['created_by'] == $user_id) {
                    // Check for duplicate name
                    $check = $pdo->prepare("SELECT id FROM folders WHERE parent_id = ? AND folder_name = ? AND id != ?");
                    $check->execute([$folder['parent_id'], $new_name, $folder_id]);
                    
                    if ($check->rowCount() == 0) {
                        // Update folder name
                        $update = $pdo->prepare("UPDATE folders SET folder_name = ?, updated_at = NOW() WHERE id = ?");
                        if ($update->execute([$new_name, $folder_id])) {
                            $_SESSION['success'] = 'Folder renamed successfully!';
                        } else {
                            $_SESSION['error'] = 'Failed to rename folder';
                        }
                    } else {
                        $_SESSION['error'] = 'A folder with this name already exists';
                    }
                } else {
                    $_SESSION['error'] = 'You don\'t have permission to rename this folder';
                }
            } else {
                $_SESSION['error'] = 'Folder not found';
            }
        } catch (PDOException $e) {
            error_log("Rename folder error: " . $e->getMessage());
            $_SESSION['error'] = 'Database error occurred';
        }
    } else {
        $_SESSION['error'] = 'Invalid folder name';
    }
    
    // Redirect back to the previous folder
    $redirect_folder = isset($folder['parent_id']) ? $folder['parent_id'] : 1;
    header("Location: files.php?folder=" . $redirect_folder);
    exit();
}

// If no valid request method
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
} else {
    header("Location: files.php");
}
exit();
?>