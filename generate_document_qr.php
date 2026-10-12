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
    $stmt = $pdo->prepare("SELECT id, file_name FROM files WHERE id = ?");
    $stmt->execute([$file_id]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($file) {
        // Generate QR code using Google Charts API
        $qr_data = "USAT_DOC_" . $file['id'] . "_" . urlencode($file['file_name']);
        $qr_code_url = "https://chart.googleapis.com/chart?chs=250x250&cht=qr&chl=" . urlencode($qr_data);
        
        echo json_encode([
            'qr_code' => $qr_code_url,
            'file_name' => $file['file_name']
        ]);
    } else {
        echo json_encode(['error' => 'File not found']);
    }
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>