<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

if (!isLoggedIn()) {
    header("Location: index.php");
    exit();
}

if (isset($_POST['file_ids']) && is_array($_POST['file_ids'])) {
    $file_ids = $_POST['file_ids'];
    
    // Create ZIP file
    $zip = new ZipArchive();
    $zip_filename = 'documents_' . time() . '.zip';
    $zip_path = sys_get_temp_dir() . '/' . $zip_filename;
    
    if ($zip->open($zip_path, ZipArchive::CREATE) !== TRUE) {
        die("Cannot create ZIP file");
    }
    
    foreach ($file_ids as $file_id) {
        $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ? AND status = 'active'");
        $stmt->execute([$file_id]);
        $file = $stmt->fetch();
        
        if ($file && file_exists($file['file_path'])) {
            $zip->addFile($file['file_path'], $file['file_name']);
        }
    }
    
    $zip->close();
    
    // Download the ZIP file
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $zip_filename . '"');
    header('Content-Length: ' . filesize($zip_path));
    readfile($zip_path);
    unlink($zip_path);
    exit();
} else {
    header("Location: files.php");
    exit();
}
?>