<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not authenticated']);
    exit();
}

$file_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($file_id <= 0) {
    echo json_encode(['error' => 'Invalid file ID']);
    exit();
}

try {
    $stmt = $pdo->prepare("SELECT f.*, u.name as uploaded_by_name 
                           FROM files f 
                           LEFT JOIN users u ON f.uploaded_by = u.id 
                           WHERE f.id = ?");
    $stmt->execute([$file_id]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($file) {
        echo json_encode($file);
    } else {
        echo json_encode(['error' => 'File not found']);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>