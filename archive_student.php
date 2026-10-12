<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

$id = $_GET['id'];
$current_status = $_GET['status'];
$new_status = ($current_status == 'active') ? 'archived' : 'active';

if (isAdmin()) {
    $stmt = $pdo->prepare("UPDATE students SET status = ? WHERE id = ?");
    $stmt->execute([$new_status, $id]);
} else {
    $stmt = $pdo->prepare("UPDATE students SET status = ? WHERE id = ? AND created_by = ?");
    $stmt->execute([$new_status, $id, getCurrentUserId()]);
}

header("Location: students.php");
?>