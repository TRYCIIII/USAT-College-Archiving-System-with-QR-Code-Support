<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check login
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Get form data
$student_id = isset($_POST['student_id']) ? (int)$_POST['student_id'] : 0;
$document_name = isset($_POST['document_name']) ? trim($_POST['document_name']) : '';

// Validate
if ($student_id <= 0) {
    header("Location: documents.php?error=" . urlencode("Invalid student ID"));
    exit();
}

if (empty($document_name)) {
    header("Location: documents.php?student_id=$student_id&error=" . urlencode("Document name is required"));
    exit();
}

// Check file
if (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] != 0) {
    header("Location: documents.php?student_id=$student_id&error=" . urlencode("Please select a file"));
    exit();
}

// Validate file type
$allowed = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
$ext = strtolower(pathinfo($_FILES['document_file']['name'], PATHINFO_EXTENSION));

if (!in_array($ext, $allowed)) {
    header("Location: documents.php?student_id=$student_id&error=" . urlencode("Invalid file type. Allowed: PDF, DOC, DOCX, JPG, PNG"));
    exit();
}

// Check size (5MB)
if ($_FILES['document_file']['size'] > 5 * 1024 * 1024) {
    header("Location: documents.php?student_id=$student_id&error=" . urlencode("File too large. Max 5MB"));
    exit();
}

// Create uploads folder
if (!file_exists('uploads')) {
    mkdir('uploads', 0777, true);
}

// Create qrcodes folder
if (!file_exists('qrcodes')) {
    mkdir('qrcodes', 0777, true);
}

// Upload file
$filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '', $_FILES['document_file']['name']);
$upload_path = 'uploads/' . $filename;

if (!move_uploaded_file($_FILES['document_file']['tmp_name'], $upload_path)) {
    header("Location: documents.php?student_id=$student_id&error=" . urlencode("Failed to upload file"));
    exit();
}

// Generate unique token for document access
$view_token = bin2hex(random_bytes(32));

// Generate unique control number
// Format: DOC-YYYYMMDD-XXXX (e.g., DOC-20240120-0001)
$year_month = date('Ymd');
$prefix = "DOC-" . $year_month . "-";

// Get the last control number for today
$stmt = $pdo->prepare("SELECT control_number FROM documents WHERE control_number LIKE ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$prefix . '%']);
$last = $stmt->fetch();

if ($last) {
    $last_num = intval(substr($last['control_number'], -4));
    $new_num = str_pad($last_num + 1, 4, '0', STR_PAD_LEFT);
} else {
    $new_num = '0001';
}
$control_number = $prefix . $new_num;

// Get base URL for the document view link
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
$base_url = $protocol . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/');
$view_link = $base_url . "/view_document.php?token=" . $view_token;

// Generate QR code with the view link
$qr_filename = 'qrcodes/doc_' . time() . '.png';
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($view_link);
$qr_content = @file_get_contents($qr_url);

if ($qr_content !== false) {
    file_put_contents($qr_filename, $qr_content);
} else {
    $qr_filename = null;
}

// Save to database with control number
try {
    $stmt = $pdo->prepare("INSERT INTO documents (student_id, document_name, file_path, file_type, uploaded_by, view_token, document_qr_code, control_number, is_archived) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)");
    $result = $stmt->execute([$student_id, $document_name, $upload_path, $ext, getCurrentUserId(), $view_token, $qr_filename, $control_number]);
    
    if ($result) {
        // Log activity
        $log = $pdo->prepare("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, 'UPLOAD_DOCUMENT', ?, ?)");
        $log->execute([getCurrentUserId(), "Uploaded document: $document_name with Control #: $control_number", $_SERVER['REMOTE_ADDR']]);
        
        header("Location: documents.php?student_id=$student_id&success=" . urlencode("Document uploaded! Control Number: $control_number. QR code generated."));
        exit();
    } else {
        // Clean up files if database insert fails
        if (file_exists($upload_path)) unlink($upload_path);
        if ($qr_filename && file_exists($qr_filename)) unlink($qr_filename);
        header("Location: documents.php?student_id=$student_id&error=" . urlencode("Database error"));
        exit();
    }
} catch (PDOException $e) {
    // Clean up files if database insert fails
    if (file_exists($upload_path)) unlink($upload_path);
    if ($qr_filename && file_exists($qr_filename)) unlink($qr_filename);
    header("Location: documents.php?student_id=$student_id&error=" . urlencode("Database error: " . $e->getMessage()));
    exit();
}
?>