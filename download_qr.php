<?php
require_once 'includes/auth.php';

if (!isset($_GET['file_id'])) {
    header("Location: files.php?error=" . urlencode("No document specified"));
    exit;
}

$file_id = (int)$_GET['file_id'];
$qr_file = "uploads/qrcodes/qr_" . $file_id . ".png";

if (file_exists($qr_file)) {
    header('Content-Type: image/png');
    header('Content-Disposition: attachment; filename="qr_code_document_' . $file_id . '.png"');
    readfile($qr_file);
    exit;
} else {
    // Generate QR code on the fly
    require_once 'includes/qr_helper.php';
    $qrHelper = new QRHelper();
    
    $document_url = "https://" . $_SERVER['HTTP_HOST'] . "/view_document.php?id=" . $file_id;
    $qr_code_data = $qrHelper->generateQRCodeBase64($document_url);
    
    if ($qr_code_data) {
        $image_data = base64_decode(str_replace('data:image/png;base64,', '', $qr_code_data));
        header('Content-Type: image/png');
        header('Content-Disposition: attachment; filename="qr_code_document_' . $file_id . '.png"');
        echo $image_data;
        exit;
    }
}

header("Location: files.php?error=" . urlencode("Could not generate QR code"));
exit;
?>