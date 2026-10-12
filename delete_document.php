<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;

if ($id > 0) {
    // Get file paths
    $stmt = $pdo->prepare("SELECT file_path, document_qr_code FROM documents WHERE id = ?");
    $stmt->execute([$id]);
    $doc = $stmt->fetch();
    
    // Delete physical files
    if ($doc) {
        if ($doc['file_path'] && file_exists($doc['file_path'])) {
            unlink($doc['file_path']);
        }
        if ($doc['document_qr_code'] && file_exists($doc['document_qr_code'])) {
            unlink($doc['document_qr_code']);
        }
    }
    
    // Delete from database
    $stmt = $pdo->prepare("DELETE FROM documents WHERE id = ?");
    $stmt->execute([$id]);
}

header("Location: documents.php?student_id=" . $student_id . "&success=" . urlencode("Document deleted successfully"));
exit();
?>