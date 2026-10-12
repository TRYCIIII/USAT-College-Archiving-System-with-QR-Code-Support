<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

if (!isLoggedIn()) {
    header("Location: index.php");
    exit();
}

if (isset($_GET['id'])) {
    $file_id = (int)$_GET['id'];
    
    $stmt = $pdo->prepare("SELECT control_number, file_name FROM files WHERE id = ?");
    $stmt->execute([$file_id]);
    $file = $stmt->fetch();
    
    if ($file) {
        // Create QR directory if not exists
        $qr_dir = 'qrcodes/';
        if (!file_exists($qr_dir)) {
            mkdir($qr_dir, 0777, true);
        }
        
        $qr_filename = 'qr_' . $file_id . '_' . time() . '.png';
        $qr_path = $qr_dir . $qr_filename;
        
        // Create URL
        $protocol = isset($_SERVER['HTTPS']) ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'];
        $file_url = $protocol . $host . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/view_file.php?id=' . $file_id;
        
        // Generate QR using Google Charts
        $qr_code_url = "https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=" . urlencode($file_url);
        $qr_image = file_get_contents($qr_code_url);
        
        if ($qr_image && file_put_contents($qr_path, $qr_image)) {
            $stmt = $pdo->prepare("UPDATE files SET qr_code_path = ? WHERE id = ?");
            $stmt->execute([$qr_path, $file_id]);
            $_SESSION['success'] = "QR Code generated successfully!";
        } else {
            $_SESSION['error'] = "Failed to generate QR Code";
        }
    }
    
    header("Location: view_file.php?id=" . $file_id . "&qr=generated");
    exit();
}
?>