<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

$folder_id = isset($_GET['folder']) ? (int)$_GET['folder'] : 1;

try {
    // Get active files count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM files WHERE folder_id = ? AND (status IS NULL OR status = 'active')");
    $stmt->execute([$folder_id]);
    $active_files = $stmt->fetchColumn();

    // Get archived files count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM files WHERE folder_id = ? AND status = 'archived'");
    $stmt->execute([$folder_id]);
    $archived_files = $stmt->fetchColumn();

    // Get active folders count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM folders WHERE parent_id = ? AND (status IS NULL OR status = 'active')");
    $stmt->execute([$folder_id]);
    $active_folders = $stmt->fetchColumn();

    // Get archived folders count
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM folders WHERE parent_id = ? AND status = 'archived'");
    $stmt->execute([$folder_id]);
    $archived_folders = $stmt->fetchColumn();

    echo json_encode([
        'success' => true,
        'active_files' => $active_files,
        'archived_files' => $archived_files,
        'active_folders' => $active_folders,
        'archived_folders' => $archived_folders
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>  