<?php
session_start();
require_once 'includes/auth.php';
require_once 'config/database.php';
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: file_manager.php");
    exit();
}

$folder_id = isset($_POST['folder_id']) ? (int)$_POST['folder_id'] : 1;
$title = isset($_POST['title_of_program']) ? trim($_POST['title_of_program']) : '';
$description = isset($_POST['description']) ? trim($_POST['description']) : '';
$tags = isset($_POST['tags']) ? trim($_POST['tags']) : '';
$redirect = isset($_POST['redirect']) ? $_POST['redirect'] : 'file_manager.php';

// Check if file was uploaded
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['error_message'] = 'File upload failed. Please try again.';
    header("Location: " . $redirect);
    exit();
}

$file = $_FILES['file'];
$fileName = basename($file['name']);
$fileSize = $file['size'];
$fileTmp = $file['tmp_name'];
$fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

// Validate file extension
$allowedExts = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'gif'];
if (!in_array($fileExt, $allowedExts)) {
    $_SESSION['error_message'] = 'Invalid file type. Allowed types: PDF, Word, Excel, Images';
    header("Location: " . $redirect);
    exit();
}

// Validate file size (50MB max)
if ($fileSize > 50 * 1024 * 1024) {
    $_SESSION['error_message'] = 'File size exceeds 50MB limit.';
    header("Location: " . $redirect);
    exit();
}

// Create upload directory if it doesn't exist
$uploadDir = 'uploads/' . date('Y/m/');
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Generate unique filename
$uniqueName = uniqid() . '_' . time() . '.' . $fileExt;
$uploadPath = $uploadDir . $uniqueName;

// Move uploaded file
if (!move_uploaded_file($fileTmp, $uploadPath)) {
    $_SESSION['error_message'] = 'Failed to save file.';
    header("Location: " . $redirect);
    exit();
}

// Generate QR code
require_once 'vendor/autoload.php'; // Make sure you have phpqrcode installed
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

$qrCodeData = 'https://yourdomain.com/view_file.php?id='; // Will update after insert
$qrCodeDir = 'qrcodes/';
if (!file_exists($qrCodeDir)) {
    mkdir($qrCodeDir, 0777, true);
}

try {
    // Insert file record into database
    $stmt = $pdo->prepare("
        INSERT INTO files (folder_id, file_name, file_path, file_size, file_type, 
                          title_of_program, description, tags, uploaded_by, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')
    ");
    
    $stmt->execute([
        $folder_id,
        $fileName,
        $uploadPath,
        $fileSize,
        $fileExt,
        $title ?: $fileName,
        $description,
        $tags,
        getCurrentUserId()
    ]);
    
    $fileId = $pdo->lastInsertId();
    
    // Generate QR code with file ID
    $qrCodeData = 'https://yourdomain.com/view_file.php?id=' . $fileId;
    $qrCode = QrCode::create($qrCodeData)
        ->setSize(300)
        ->setMargin(10);
    
    $writer = new PngWriter();
    $result = $writer->write($qrCode);
    
    $qrPath = $qrCodeDir . 'qr_' . $fileId . '.png';
    $result->saveToFile($qrPath);
    
    // Update file record with QR code path
    $updateStmt = $pdo->prepare("UPDATE files SET qr_code_path = ? WHERE id = ?");
    $updateStmt->execute([$qrPath, $fileId]);
    
    $_SESSION['success_message'] = 'File uploaded successfully with QR code!';
    
} catch (Exception $e) {
    // Delete uploaded file if database insert fails
    if (file_exists($uploadPath)) {
        unlink($uploadPath);
    }
    
    $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
}

header("Location: " . $redirect . "&success=file_uploaded");
exit();
?>