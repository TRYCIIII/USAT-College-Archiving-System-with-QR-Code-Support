<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

$file_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($file_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid file ID']);
    exit();
}

try {
    // Get file details
    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
    $stmt->execute([$file_id]);
    $file = $stmt->fetch();
    
    if (!$file) {
        echo json_encode(['success' => false, 'error' => 'File not found']);
        exit();
    }
    
    // Generate QR token if not exists
    if (empty($file['qr_code'])) {
        $qr_token = 'DOC_' . $file_id . '_' . time() . '_' . bin2hex(random_bytes(8));
        $update = $pdo->prepare("UPDATE files SET qr_code = ?, qr_generated_at = NOW() WHERE id = ?");
        $update->execute([$qr_token, $file_id]);
        $file['qr_code'] = $qr_token;
    }
    
    // Get base URL
    $protocol = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
    $base_url = $protocol . $_SERVER['HTTP_HOST'];
    $script_dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    $view_url = $base_url . $script_dir . '/view_document.php?token=' . $file['qr_code'];
    
    // Generate QR code URL using Google Charts API
    $qr_code_url = "https://chart.googleapis.com/chart?chs=250x250&cht=qr&chl=" . urlencode($view_url) . "&choe=UTF-8";
    
    echo json_encode([
        'success' => true,
        'qr_code_url' => $qr_code_url,
        'file_name' => $file['file_name'],
        'view_url' => $view_url
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>