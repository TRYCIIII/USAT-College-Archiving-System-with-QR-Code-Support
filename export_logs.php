<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

// Only admin can export
if (!isset($_SESSION['user_id']) || !isAdmin()) {
    header("Location: index.php");
    exit();
}

// Get same filters
$filter_action = isset($_GET['action']) ? $_GET['action'] : 'all';
$filter_date = isset($_GET['date']) ? $_GET['date'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$sql = "SELECT al.*, u.username, u.full_name, u.role 
        FROM activity_log al 
        LEFT JOIN users u ON al.user_id = u.id 
        WHERE 1=1";
$params = [];

if ($filter_action != 'all') {
    $sql .= " AND al.action = ?";
    $params[] = $filter_action;
}

if (!empty($filter_date)) {
    $sql .= " AND DATE(al.created_at) = ?";
    $params[] = $filter_date;
}

if (!empty($search)) {
    $sql .= " AND (al.description LIKE ? OR u.username LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
}

$sql .= " ORDER BY al.created_at DESC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll();

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="activity_log_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
fputcsv($output, ['Date & Time', 'User', 'Role', 'Action', 'Description', 'IP Address']);

foreach($logs as $log) {
    fputcsv($output, [
        date('Y-m-d H:i:s', strtotime($log['created_at'])),
        $log['full_name'] ?? $log['username'] ?? 'System',
        $log['role'] ?? 'N/A',
        $log['action'],
        $log['description'],
        $log['ip_address'] ?? '-'
    ]);
}

fclose($output);
exit();
?>