<?php
require_once '../config/database.php';
header('Content-Type: application/json');

$stmt = $pdo->query("SELECT COUNT(*) as total FROM students WHERE status = 'active'");
$total_students = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COUNT(*) as total FROM documents");
$total_documents = $stmt->fetch()['total'];

$stmt = $pdo->query("SELECT COUNT(*) as total FROM users WHERE status = 'active'");
$total_users = $stmt->fetch()['total'];

echo json_encode([
    'total_students' => $total_students,
    'total_documents' => $total_documents,
    'total_users' => $total_users
]);
?>