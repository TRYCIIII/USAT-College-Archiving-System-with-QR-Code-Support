<?php
session_start();
header('Content-Type: application/json');

// Prevent PHP warnings from breaking JSON output
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once 'config/database.php';
require_once 'includes/auth.php';

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

if (!isset($_GET['id'])) {
    echo json_encode(['success' => false, 'error' => 'No file ID provided']);
    exit();
}

$file_id = (int)$_GET['id'];

try {
    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
    $stmt->execute([$file_id]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        echo json_encode(['success' => false, 'error' => 'File not found']);
        exit();
    }

    // Build QR code
    $qr_code = '';
    if (!empty($file['qr_code_path']) && file_exists($file['qr_code_path'])) {
        $qr_data = file_get_contents($file['qr_code_path']);
        $qr_code = 'data:image/png;base64,' . base64_encode($qr_data);
    } else {
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $script_dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $file_url = $protocol . $host . $script_dir . '/view_file.php?id=' . $file_id;
        $qr_code = 'https://quickchart.io/qr?text=' . urlencode($file_url) . '&size=150';
    }

    echo json_encode([
        'success' => true,
        'id' => (int)$file['id'],
        'file_name' => $file['file_name'] ?? 'Untitled',
        'control_number' => $file['control_number'] ?? 'N/A',
        'title_of_program' => $file['title_of_program'] ?? 'N/A',
        'date_conducted' => $file['date_conducted'] ?? 'N/A',
        // Match the ACTUAL column names from your files table
        'type_of_service' => $file['type_of_community_service'] ?? 'N/A',
        'community_served' => $file['community_population_served'] ?? 'N/A',
        'beneficiaries' => $file['no_of_beneficiaries'] ?? 'N/A',
        'description' => $file['description'] ?? '',
        'created_at' => $file['created_at'] ?? '',
        'qr_code' => $qr_code
    ]);
    exit();

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    exit();
}