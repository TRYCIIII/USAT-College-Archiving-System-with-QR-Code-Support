<?php
session_start();
require_once 'config/database.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

if ($id > 0) {
    // Get file path
    $stmt = $pdo->prepare("SELECT file_path, student_id FROM documents WHERE id = ?");
    $stmt->execute([$id]);
    $doc = $stmt->fetch();
    
    if ($doc && file_exists($doc['file_path'])) {
        // Track download
        $track = $pdo->prepare("INSERT INTO document_downloads (document_id, student_id, downloaded_by, ip_address) VALUES (?, ?, ?, ?)");
        $track->execute([$id, $doc['student_id'], isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null, $_SERVER['REMOTE_ADDR']]);
        
        // Force download
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . basename($doc['file_path']) . '"');
        header('Content-Length: ' . filesize($doc['file_path']));
        readfile($doc['file_path']);
        exit();
    }
}

header("Location: documents.php?student_id=" . $student_id);
exit();
?>