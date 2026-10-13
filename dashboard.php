<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

// Redirect if not logged in
if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    header("Location: index.php");
    exit();
}

// Provide fallback for missing helper functions
if (!function_exists('getCurrentUserId')) {
    function getCurrentUserId() {
        return (int)($_SESSION['user_id'] ?? 0);
    }
}
if (!function_exists('isAdmin')) {
    function isAdmin() {
        return ($_SESSION['role'] ?? '') === 'admin';
    }
}

// Set timezone
date_default_timezone_set('Asia/Manila');

// ============================================
// FILE ICON & COLOR HELPERS
// ============================================
if (!function_exists('getFileIcon')) {
    function getFileIcon($ext) {
        $icons = [
            'pdf' => 'fa-file-pdf', 'doc' => 'fa-file-word', 'docx' => 'fa-file-word',
            'xls' => 'fa-file-excel', 'xlsx' => 'fa-file-excel', 'ppt' => 'fa-file-powerpoint',
            'pptx' => 'fa-file-powerpoint', 'jpg' => 'fa-file-image', 'jpeg' => 'fa-file-image',
            'png' => 'fa-file-image', 'gif' => 'fa-file-image', 'txt' => 'fa-file-alt'
        ];
        $key = strtolower((string)($ext ?? ''));
        return $icons[$key] ?? 'fa-file';
    }
}
if (!function_exists('getFileColor')) {
    function getFileColor($ext) {
        $colors = [
            'pdf' => '#dc3545', 'doc' => '#2b5797', 'docx' => '#2b5797',
            'xls' => '#1e7145', 'xlsx' => '#1e7145', 'ppt' => '#d35230',
            'pptx' => '#d35230', 'jpg' => '#28a745', 'jpeg' => '#28a745',
            'png' => '#28a745', 'gif' => '#28a745'
        ];
        $key = strtolower((string)($ext ?? ''));
        return $colors[$key] ?? '#5f6368';
    }
}

// ============================================
// DETECT SCHEMA
// ============================================
function column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

$filesHasStatus   = column_exists($pdo, 'files', 'status');
$foldersHasStatus = column_exists($pdo, 'folders', 'status');
$foldersHasCreatedBy = column_exists($pdo, 'folders', 'created_by');

$filesActiveClause   = $filesHasStatus   ? "(status = 'active' OR status IS NULL)"     : "1=1";
$filesArchivedClause = $filesHasStatus   ? "status = 'archived'"                        : "1=0";
$foldersActiveClause = $foldersHasStatus ? "(status = 'active' OR status IS NULL)"     : "1=1";

// ============================================
// STATISTICS
// ============================================
$total_files = 0;
$total_folders = 0;
$total_staff = 0;
$archived = 0;
$total_beneficiaries = 0;
$service_stats = [];
$monthly_stats = [];
$recent_files = [];
$yearly_files = 0;
$growth_percentage = 0;

if (isAdmin()) {
    try {
        $total_files   = (int)$pdo->query("SELECT COUNT(*) FROM files WHERE $filesActiveClause")->fetchColumn();
        $total_folders = (int)$pdo->query("SELECT COUNT(*) FROM folders WHERE $foldersActiveClause")->fetchColumn();

        try {
            $total_staff = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'staff' AND status = 'active'")->fetchColumn();
        } catch (PDOException $e) {
            try {
                $total_staff = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'staff'")->fetchColumn();
            } catch (PDOException $e2) { $total_staff = 0; }
        }

        $archived = (int)$pdo->query("SELECT COUNT(*) FROM files WHERE $filesArchivedClause")->fetchColumn();
        $total_beneficiaries = (int)($pdo->query("SELECT COALESCE(SUM(no_of_beneficiaries), 0) FROM files WHERE $filesActiveClause")->fetchColumn() ?? 0);

        $service_stats = $pdo->query("
            SELECT type_of_community_service, COUNT(*) AS count
            FROM files
            WHERE $filesActiveClause
              AND type_of_community_service IS NOT NULL
              AND type_of_community_service <> ''
            GROUP BY type_of_community_service
            ORDER BY count DESC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);

        $monthly_stats = $pdo->query("
            SELECT DATE_FORMAT(created_at, '%b') AS month, COUNT(*) AS count
            FROM files
            WHERE created_at IS NOT NULL
              AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY YEAR(created_at), MONTH(created_at), DATE_FORMAT(created_at, '%b')
            ORDER BY YEAR(created_at) ASC, MONTH(created_at) ASC
        ")->fetchAll(PDO::FETCH_ASSOC);

        $recent_files = $pdo->query("
            SELECT * FROM files
            WHERE $filesActiveClause
            ORDER BY created_at DESC
            LIMIT 8
        ")->fetchAll(PDO::FETCH_ASSOC);

        $yearly_files = (int)$pdo->query("SELECT COUNT(*) FROM files WHERE YEAR(created_at) = YEAR(NOW())")->fetchColumn();

        $growth_data = $pdo->query("
            SELECT
                COUNT(CASE WHEN MONTH(created_at) = MONTH(CURDATE() - INTERVAL 1 MONTH)
                             AND YEAR(created_at) = YEAR(CURDATE() - INTERVAL 1 MONTH)
                           THEN 1 END) AS last_month,
                COUNT(CASE WHEN MONTH(created_at) = MONTH(CURDATE() - INTERVAL 2 MONTH)
                             AND YEAR(created_at) = YEAR(CURDATE() - INTERVAL 2 MONTH)
                           THEN 1 END) AS previous_month
            FROM files
            WHERE created_at IS NOT NULL
        ")->fetch(PDO::FETCH_ASSOC);

        if ($growth_data && (int)$growth_data['previous_month'] > 0) {
            $growth_percentage = (int)round(
                (((int)$growth_data['last_month'] - (int)$growth_data['previous_month']) / (int)$growth_data['previous_month']) * 100
            );
        } elseif ($growth_data && (int)$growth_data['last_month'] > 0) {
            $growth_percentage = 12;
        } else {
            $growth_percentage = 0;
        }
    } catch (PDOException $e) {
        error_log("Dashboard admin stats error: " . $e->getMessage());
    }
} else {
    $user_id = getCurrentUserId();

    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM files WHERE $filesActiveClause AND uploaded_by = ?");
        $stmt->execute([$user_id]);
        $total_files = (int)$stmt->fetchColumn();

        if ($foldersHasCreatedBy) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM folders WHERE $foldersActiveClause AND created_by = ?");
            $stmt->execute([$user_id]);
            $total_folders = (int)$stmt->fetchColumn();
        } else {
            $total_folders = (int)$pdo->query("SELECT COUNT(*) FROM folders WHERE $foldersActiveClause")->fetchColumn();
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM files WHERE $filesArchivedClause AND uploaded_by = ?");
        $stmt->execute([$user_id]);
        $archived = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(no_of_beneficiaries), 0) FROM files WHERE $filesActiveClause AND uploaded_by = ?");
        $stmt->execute([$user_id]);
        $total_beneficiaries = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT type_of_community_service, COUNT(*) AS count
            FROM files
            WHERE $filesActiveClause
              AND uploaded_by = ?
              AND type_of_community_service IS NOT NULL
              AND type_of_community_service <> ''
            GROUP BY type_of_community_service
            ORDER BY count DESC
            LIMIT 5
        ");
        $stmt->execute([$user_id]);
        $service_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("
            SELECT DATE_FORMAT(created_at, '%b') AS month, COUNT(*) AS count
            FROM files
            WHERE uploaded_by = ?
              AND created_at IS NOT NULL
              AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
            GROUP BY YEAR(created_at), MONTH(created_at), DATE_FORMAT(created_at, '%b')
            ORDER BY YEAR(created_at) ASC, MONTH(created_at) ASC
        ");
        $stmt->execute([$user_id]);
        $monthly_stats = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT * FROM files WHERE $filesActiveClause AND uploaded_by = ? ORDER BY created_at DESC LIMIT 8");
        $stmt->execute([$user_id]);
        $recent_files = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $yearly_files = $total_files;
        $growth_percentage = $total_files > 0 ? 8 : 0;
    } catch (PDOException $e) {
        error_log("Dashboard staff stats error: " . $e->getMessage());
    }
}

// ============================================
// GREETING
// ============================================
$current_hour = (int)date('H');
if ($current_hour < 12) {
    $greeting = "Good Morning";
} elseif ($current_hour < 18) {
    $greeting = "Good Afternoon";
} else {
    $greeting = "Good Evening";
}

$user_full_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User';

include 'includes/header.php';
?>

<style>
html, body {
    margin: 0;
    padding: 0;
    width: 100%;
    overflow-x: hidden;
    -webkit-text-size-adjust: 100%;
    text-size-adjust: 100%;
}

.main-content {
    width: 100%;
    max-width: 100%;
    padding: 15px 20px;
    margin: 0 auto;
    box-sizing: border-box;
    overflow-x: hidden;
}

.container-fluid {
    padding-right: 15px;
    padding-left: 15px;
    max-width: 100%;
    overflow-x: hidden;
}

:root {
    --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.welcome-banner {
    background: var(--primary-gradient);
    border-radius: 16px;
    padding: 20px;
    margin-bottom: 20px;
    position: relative;
    overflow: hidden;
    animation: fadeInUp 0.6s ease;
    width: 100%;
    box-sizing: border-box;
    color: white;
}

.welcome-banner h2 {
    font-size: 20px;
    font-weight: 700;
    margin-bottom: 6px;
    position: relative;
    z-index: 1;
    color: white;
}

.welcome-banner p {
    opacity: 0.9;
    margin-bottom: 6px;
    position: relative;
    z-index: 1;
    color: white;
    font-size: 13px;
}

.greeting-time {
    font-size: 12px;
    opacity: 0.85;
    margin-top: 4px;
    color: white;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
    width: 100%;
}

.stat-card-premium {
    background: white;
    border-radius: 14px;
    padding: 15px 14px;
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(0,0,0,0.06);
    position: relative;
    overflow: hidden;
    cursor: pointer;
    min-height: 90px;
}

.stat-card-premium:hover {
    transform: translateY(-4px);
    box-shadow: 0 10px 25px rgba(0,0,0,0.1);
}

.stat-card-premium::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 3px;
}

.stat-card-premium.blue::after { background: linear-gradient(90deg, #3498db, #2980b9); }
.stat-card-premium.orange::after { background: linear-gradient(90deg, #f39c12, #e67e22); }
.stat-card-premium.green::after { background: linear-gradient(90deg, #27ae60, #1e8449); }
.stat-card-premium.red::after { background: linear-gradient(90deg, #e74c3c, #c0392b); }
.stat-card-premium.purple::after { background: linear-gradient(90deg, #9b59b6, #8e44ad); }

.stat-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 8px;
}

.stat-icon {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
}

.stat-icon.blue { background: rgba(52,152,219,0.1); color: #3498db; }
.stat-icon.orange { background: rgba(243,156,18,0.1); color: #f39c12; }
.stat-icon.green { background: rgba(39,174,96,0.1); color: #27ae60; }
.stat-icon.red { background: rgba(231,76,60,0.1); color: #e74c3c; }
.stat-icon.purple { background: rgba(155,89,182,0.1); color: #9b59b6; }

.stat-value {
    font-size: 24px;
    font-weight: 800;
    color: #2c3e50;
    line-height: 1.2;
    margin-bottom: 2px;
}

.stat-label {
    font-size: 12px;
    color: #7f8c8d;
    margin-bottom: 5px;
    font-weight: 500;
}

.stat-trend {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    padding: 2px 8px;
    border-radius: 30px;
    font-size: 10px;
    font-weight: 600;
    flex-shrink: 0;
}

.trend-up { background: rgba(39,174,96,0.1); color: #27ae60; }
.trend-down { background: rgba(231,76,60,0.1); color: #e74c3c; }

.stat-card-premium small {
    font-size: 10px;
    opacity: 0.7;
    display: block;
    margin-top: 3px;
}

/* ---------- DO NUT CARD ---------- */
.donut-card {
    background: white;
    border-radius: 14px;
    padding: 15px 14px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.06);
    position: relative;
    overflow: hidden;
    min-height: 90px;
    display: flex;
    flex-direction: column;
    transition: all 0.3s ease;
}

.donut-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 10px 25px rgba(0,0,0,0.1);
}

.donut-card::after {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 3px;
    background: linear-gradient(90deg, #667eea, #764ba2);
}

.donut-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 6px;
    padding-bottom: 6px;
    border-bottom: 2px solid #f0f0f0;
    flex-wrap: wrap;
    gap: 6px;
}

.donut-title {
    font-size: 14px;
    font-weight: 700;
    color: #2c3e50;
    display: flex;
    align-items: center;
    gap: 8px;
}

.donut-title i {
    width: 28px;
    height: 28px;
    background: var(--primary-gradient);
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 13px;
    flex-shrink: 0;
}

.donut-badge {
    background: #f0f2f5;
    color: #2c3e50;
    font-size: 10px;
    font-weight: 600;
    padding: 4px 10px;
    border-radius: 30px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.donut-wrapper {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 130px;
    position: relative;
    padding-top: 4px;
}

.donut-wrapper canvas {
    max-width: 100%;
    max-height: 140px;
}

/* Custom legend under the donut */
.donut-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 6px 10px;
    justify-content: center;
    padding: 4px 2px 0;
    margin-top: 4px;
}

.donut-legend-item {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 10px;
    color: #2c3e50;
    font-weight: 500;
    white-space: nowrap;
}

.donut-legend-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    flex-shrink: 0;
    display: inline-block;
}

.donut-legend-pct {
    font-weight: 700;
    color: #667eea;
    margin-left: 2px;
}

.quick-actions {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px;
    margin-top: 15px;
    margin-bottom: 20px;
    width: 100%;
}

.quick-action-btn {
    background: white;
    border-radius: 12px;
    padding: 12px 10px;
    text-align: center;
    text-decoration: none;
    transition: all 0.3s;
    border: 1px solid #e8e8e8;
    position: relative;
    overflow: hidden;
    min-height: 70px;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
}

.quick-action-btn::before {
    content: '';
    position: absolute;
    top: 0;
    left: -100%;
    width: 100%;
    height: 100%;
    background: var(--primary-gradient);
    transition: left 0.4s;
    z-index: 0;
}

.quick-action-btn:hover::before { left: 0; }

.quick-action-btn i,
.quick-action-btn span {
    position: relative;
    z-index: 1;
    transition: all 0.3s;
}

.quick-action-btn:hover i,
.quick-action-btn:hover span { color: white !important; }

.quick-action-btn i {
    font-size: 20px;
    margin-bottom: 4px;
    display: block;
    color: #667eea;
}

.quick-action-btn span {
    font-size: 11px;
    font-weight: 600;
    color: #2c3e50;
}

.chart-container {
    background: white;
    border-radius: 14px;
    padding: 15px;
    margin-bottom: 20px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    width: 100%;
    box-sizing: border-box;
    height: 100%;
}

.chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    padding-bottom: 10px;
    border-bottom: 2px solid #f0f0f0;
    flex-wrap: wrap;
    gap: 8px;
}

.chart-title {
    font-size: 14px;
    font-weight: 700;
    color: #2c3e50;
    display: flex;
    align-items: center;
    gap: 8px;
}

.chart-title i {
    width: 28px;
    height: 28px;
    background: var(--primary-gradient);
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 13px;
    flex-shrink: 0;
}

.chart-wrapper {
    position: relative;
    height: 200px;
    width: 100%;
}

.recent-table {
    background: white;
    border-radius: 14px;
    overflow: hidden;
    box-shadow: 0 4px 15px rgba(0,0,0,0.05);
    margin-bottom: 15px;
    width: 100%;
}

.recent-table .table {
    margin-bottom: 0;
    font-size: 13px;
}

.recent-table .table thead th {
    background: var(--primary-gradient);
    color: white;
    font-weight: 600;
    padding: 10px 14px;
    border: none;
    font-size: 12px;
    white-space: nowrap;
}

.recent-table .table tbody td {
    padding: 10px 14px;
    vertical-align: middle;
    font-size: 12px;
}

.service-tag {
    display: inline-block;
    padding: 2px 10px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 600;
    background: #e8f8f5;
    color: #1abc9c;
}

@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(20px); }
    to { opacity: 1; transform: translateY(0); }
}

.fade-up {
    opacity: 0;
    transform: translateY(20px);
    transition: all 0.5s ease;
}

.fade-up.visible {
    opacity: 1;
    transform: translateY(0);
}

@media (max-width: 992px) {
    .main-content { padding: 12px 15px; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 12px; }
    .stat-card-premium { padding: 12px; min-height: 80px; }
    .stat-value { font-size: 20px; }
    .quick-actions { grid-template-columns: repeat(2, 1fr); gap: 10px; }
    .quick-action-btn { min-height: 60px; padding: 10px 8px; }
    .quick-action-btn i { font-size: 18px; }
}

@media (max-width: 768px) {
    .main-content { padding: 10px 12px; }
    .welcome-banner { padding: 15px; border-radius: 12px; }
    .welcome-banner h2 { font-size: 17px; }
    .welcome-banner p { font-size: 12px; }
    .greeting-time { font-size: 11px; }
    .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
    .stat-card-premium { padding: 10px; min-height: 70px; border-radius: 10px; }
    .stat-value { font-size: 18px; }
    .stat-icon { width: 32px; height: 32px; font-size: 14px; }
    .stat-label { font-size: 11px; }
    .stat-trend { font-size: 9px; padding: 2px 6px; }
    .stat-card-premium small { font-size: 9px; }
    .quick-actions { grid-template-columns: repeat(2, 1fr); gap: 8px; }
    .quick-action-btn { min-height: 55px; padding: 8px 6px; border-radius: 10px; }
    .quick-action-btn i { font-size: 16px; margin-bottom: 3px; }
    .quick-action-btn span { font-size: 10px; }
    .chart-container { padding: 12px; border-radius: 10px; }
    .chart-title { font-size: 13px; }
    .chart-title i { width: 24px; height: 24px; font-size: 11px; }
    .chart-wrapper { height: 180px; }
    .donut-card { padding: 10px; min-height: 70px; border-radius: 10px; }
    .donut-title { font-size: 12px; }
    .donut-title i { width: 24px; height: 24px; font-size: 11px; }
    .donut-wrapper { min-height: 110px; }
    .donut-wrapper canvas { max-height: 110px; }
    .donut-legend-item { font-size: 9px; }

    .recent-table { border-radius: 10px; }
    .recent-table .table thead { display: none; }
    .recent-table .table tbody tr {
        display: block;
        padding: 10px 12px;
        border-bottom: 1px solid #f0f0f0;
    }
    .recent-table .table tbody tr:last-child { border-bottom: none; }
    .recent-table .table tbody td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 4px 0;
        border: none;
        font-size: 11px;
    }
    .recent-table .table tbody td:before {
        content: attr(data-label);
        font-weight: 600;
        color: #7f8c8d;
        margin-right: 8px;
        flex-shrink: 0;
        min-width: 80px;
        font-size: 10px;
    }
    .recent-table .badge { font-size: 10px; padding: 3px 8px; }
}

@media (max-width: 480px) {
    .main-content { padding: 8px 10px; }
    .stats-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
    .stat-card-premium { padding: 8px; min-height: 60px; border-radius: 8px; }
    .stat-value { font-size: 16px; }
    .stat-icon { width: 28px; height: 28px; font-size: 12px; border-radius: 8px; }
    .stat-label { font-size: 10px; margin-bottom: 2px; }
    .stat-trend { font-size: 8px; padding: 1px 5px; }
    .stat-card-premium small { font-size: 8px; }
    .welcome-banner { padding: 12px; }
    .welcome-banner h2 { font-size: 15px; }
    .welcome-banner p { font-size: 11px; }
    .quick-actions { grid-template-columns: 1fr 1fr; gap: 6px; }
    .quick-action-btn { min-height: 50px; padding: 6px 5px; border-radius: 8px; }
    .quick-action-btn i { font-size: 14px; margin-bottom: 2px; }
    .quick-action-btn span { font-size: 9px; }
    .chart-container { padding: 10px; }
    .chart-title { font-size: 12px; }
    .chart-title i { width: 22px; height: 22px; font-size: 10px; }
    .chart-wrapper { height: 150px; }
    .recent-table .table tbody td { font-size: 10px; padding: 3px 0; }
    .recent-table .table tbody td:before { min-width: 60px; font-size: 9px; }
    .service-tag { font-size: 8px; padding: 1px 6px; }
}

@media (max-width: 360px) {
    .stats-grid { grid-template-columns: 1fr 1fr; gap: 6px; }
    .stat-card-premium { padding: 6px; min-height: 50px; }
    .stat-value { font-size: 14px; }
    .stat-icon { width: 24px; height: 24px; font-size: 10px; }
    .quick-actions { grid-template-columns: 1fr 1fr; gap: 5px; }
    .quick-action-btn { min-height: 45px; padding: 5px 4px; }
}

.navbar-toggler {
    border: none;
    padding: 6px 8px;
    border-radius: 6px;
    background: rgba(255,255,255,0.1);
}
.navbar-toggler:focus { box-shadow: none; outline: none; }
.navbar-collapse { transition: all 0.3s ease; }

@media (max-width: 991.98px) {
    .navbar-collapse {
        background: rgba(102, 126, 234, 0.95);
        padding: 15px;
        border-radius: 10px;
        margin-top: 8px;
        backdrop-filter: blur(10px);
    }
}

.badge { font-size: 11px; padding: 4px 10px; }

img, svg, canvas, video, iframe { max-width: 100%; height: auto; }
</style>

<div class="main-content">
    <!-- Welcome Banner -->
    <div class="welcome-banner fade-up">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div>
                <!-- ===== UPDATED: admin name removed ===== -->
                <h2><i class="fas fa-graduation-cap"></i> <?php echo $greeting; ?>!</h2>
                <p>Welcome to USAT College Archiving System. Here's your dashboard overview.</p>
                <div class="greeting-time">
                    <i class="fas fa-calendar-alt"></i> <?php echo date('l, F j, Y'); ?> &nbsp;|&nbsp;
                    <i class="fas fa-clock"></i> <span id="liveClock"><?php echo date('h:i:s A'); ?></span>
                </div>
            </div>
            <div>
                <span class="badge bg-light text-dark">
                    <i class="fas fa-chart-line"></i> <?php echo $growth_percentage >= 0 ? '+' : ''; ?><?php echo $growth_percentage; ?>% growth
                </span>
            </div>
        </div>
    </div>

    <!-- Statistics Cards + Do Nut -->
    <div class="stats-grid">
        <div class="stat-card-premium blue fade-up" onclick="window.location.href='files.php'">
            <div class="stat-header">
                <div class="stat-icon blue"><i class="fas fa-file-alt"></i></div>
                <div class="stat-trend <?php echo $growth_percentage >= 0 ? 'trend-up' : 'trend-down'; ?>">
                    <i class="fas fa-arrow-<?php echo $growth_percentage >= 0 ? 'up' : 'down'; ?>"></i> <?php echo abs($growth_percentage); ?>%
                </div>
            </div>
            <div class="stat-value"><?php echo number_format((int)$total_files); ?></div>
            <div class="stat-label">Total Documents</div>
            <small><i class="fas fa-calendar"></i> <?php echo number_format((int)$yearly_files); ?> added</small>
        </div>

        <div class="stat-card-premium orange fade-up" onclick="window.location.href='files.php'">
            <div class="stat-header">
                <div class="stat-icon orange"><i class="fas fa-folder"></i></div>
            </div>
            <div class="stat-value"><?php echo number_format((int)$total_folders); ?></div>
            <div class="stat-label">Total Folders</div>
            <small><i class="fas fa-folder-open"></i> Organized storage</small>
        </div>

        <div class="stat-card-premium green fade-up" onclick="window.location.href='files.php?show_archived=1'">
            <div class="stat-header">
                <div class="stat-icon green"><i class="fas fa-archive"></i></div>
            </div>
            <div class="stat-value"><?php echo number_format((int)$archived); ?></div>
            <div class="stat-label">Archived Records</div>
            <small><i class="fas fa-database"></i> Safe storage</small>
        </div>

        <div class="stat-card-premium purple fade-up" onclick="window.location.href='reports.php'">
            <div class="stat-header">
                <div class="stat-icon purple"><i class="fas fa-users"></i></div>
            </div>
            <div class="stat-value"><?php echo number_format((int)$total_beneficiaries); ?></div>
            <div class="stat-label">Total Beneficiaries</div>
            <small><i class="fas fa-hand-holding-heart"></i> People served</small>
        </div>

        <?php if(isAdmin()): ?>
        <div class="stat-card-premium red fade-up" onclick="window.location.href='manage_users.php'">
            <div class="stat-header">
                <div class="stat-icon red"><i class="fas fa-users-cog"></i></div>
            </div>
            <div class="stat-value"><?php echo number_format((int)$total_staff); ?></div>
            <div class="stat-label">Staff Members</div>
            <small><i class="fas fa-user-check"></i> Active users</small>
        </div>
        <?php endif; ?>

        <!-- DO NUT CARD with % labels -->
        <?php if(count($service_stats) > 0): ?>
        <div class="donut-card fade-up">
            <div class="donut-header">
                <div class="donut-title">
                    <i class="fas fa-chart-pie"></i>
                    Service Mix
                </div>
                <span class="donut-badge"><i class="fas fa-percent"></i> By Share</span>
            </div>
            <div class="donut-wrapper">
                <canvas id="doNutChart"></canvas>
            </div>
            <div class="donut-legend" id="doNutLegend"></div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Quick Actions -->
    <div class="quick-actions fade-up">
        <a href="files.php" class="quick-action-btn" onclick="openUploadFromDashboard(event)">
            <i class="fas fa-cloud-upload-alt"></i>
            <span>Upload Document</span>
        </a>
        <a href="files.php" class="quick-action-btn">
            <i class="fas fa-folder-open"></i>
            <span>Browse Files</span>
        </a>
        <a href="reports.php" class="quick-action-btn">
            <i class="fas fa-chart-line"></i>
            <span>View Reports</span>
        </a>
        <a href="change_password.php" class="quick-action-btn">
            <i class="fas fa-key"></i>
            <span>Change Password</span>
        </a>
    </div>

    <!-- Charts Row -->
    <div class="row">
        <div class="col-12 col-lg-7">
            <div class="chart-container fade-up">
                <div class="chart-header">
                    <div class="chart-title">
                        <i class="fas fa-chart-line"></i>
                        Document Upload Trend
                    </div>
                    <span class="badge bg-primary">Last 6 months</span>
                </div>
                <div class="chart-wrapper">
                    <canvas id="trendChart"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-lg-5">
            <div class="chart-container fade-up">
                <div class="chart-header">
                    <div class="chart-title">
                        <i class="fas fa-chart-pie"></i>
                        Service Distribution
                    </div>
                    <span class="badge bg-success">By %</span>
                </div>
                <div class="chart-wrapper">
                    <canvas id="serviceChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Documents Table -->
    <div class="recent-table fade-up">
        <div style="padding: 14px 16px 0 16px;">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h5 class="mb-0" style="font-size: 15px;"><i class="fas fa-clock text-primary"></i> Recently Added Documents</h5>
                <a href="files.php" class="btn btn-sm btn-outline-primary">View All <i class="fas fa-arrow-right"></i></a>
            </div>
            <hr class="my-2">
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>File Name</th>
                        <th>Title of Program</th>
                        <th>Date Conducted</th>
                        <th>Beneficiaries</th>
                        <th>Service Type</th>
                        <th>Date Added</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if(count($recent_files) > 0): ?>
                        <?php foreach($recent_files as $file): ?>
                        <tr onclick="window.location.href='files.php'" style="cursor: pointer;">
                            <td data-label="File Name">
                                <i class="fas <?php echo getFileIcon($file['file_extension'] ?? ''); ?> me-1" style="color: <?php echo getFileColor($file['file_extension'] ?? ''); ?>"></i>
                                <?php echo htmlspecialchars($file['file_name'] ?? 'Untitled'); ?>
                            </td>
                            <td data-label="Title of Program">
                                <?php
                                    $title = $file['title_of_program'] ?? 'N/A';
                                    echo htmlspecialchars(substr($title, 0, 35)) . (strlen($title) > 35 ? '...' : '');
                                ?>
                            </td>
                            <td data-label="Date Conducted">
                                <?php echo !empty($file['date_conducted']) ? date('M d, Y', strtotime($file['date_conducted'])) : 'N/A'; ?>
                            </td>
                            <td data-label="Beneficiaries">
                                <span class="badge bg-info"><?php echo number_format((int)($file['no_of_beneficiaries'] ?? 0)); ?></span>
                            </td>
                            <td data-label="Service Type">
                                <span class="service-tag"><?php echo htmlspecialchars($file['type_of_community_service'] ?? 'N/A'); ?></span>
                            </td>
                            <td data-label="Date Added">
                                <small><?php echo !empty($file['created_at']) ? date('M d, Y', strtotime($file['created_at'])) : 'N/A'; ?></small>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="6" class="text-center py-4">
                                <i class="fas fa-folder-open fa-2x text-muted mb-2 d-block"></i>
                                No documents found. Click "Upload Document" to get started.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>
<script>
// Live Clock
function updateClock() {
    const now = new Date();
    const timeString = now.toLocaleTimeString('en-US', { hour12: true });
    const clockElement = document.getElementById('liveClock');
    if (clockElement) clockElement.innerHTML = timeString;
}
setInterval(updateClock, 1000);
updateClock();

// Intersection Observer
const observerOptions = { threshold: 0.1, rootMargin: '0px 0px -50px 0px' };
const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) entry.target.classList.add('visible');
    });
}, observerOptions);
document.querySelectorAll('.fade-up').forEach(el => observer.observe(el));

// Upload redirect
function openUploadFromDashboard(e) {
    e.preventDefault();
    window.location.href = 'files.php?open_upload=1';
}

// ============================================
// SHARED: percentage formatter
// ============================================
function formatPercent(value, total) {
    if (!total) return '0%';
    return ((value / total) * 100).toFixed(1).replace(/\.0$/, '') + '%';
}

// ============================================
// CHARTS
// ============================================

// ---- Line Chart ----
<?php if(count($monthly_stats) > 0): ?>
(function() {
    const el = document.getElementById('trendChart');
    if (!el) return;
    const monthlyLabels = <?php echo json_encode(array_column($monthly_stats, 'month')); ?>;
    const monthlyData = <?php echo json_encode(array_map('intval', array_column($monthly_stats, 'count'))); ?>;

    new Chart(el.getContext('2d'), {
        type: 'line',
        data: {
            labels: monthlyLabels,
            datasets: [{
                label: 'Documents Uploaded',
                data: monthlyData,
                borderColor: '#667eea',
                backgroundColor: 'rgba(102, 126, 234, 0.1)',
                borderWidth: 2,
                tension: 0.4,
                fill: true,
                pointBackgroundColor: '#667eea',
                pointBorderColor: 'white',
                pointRadius: 4,
                pointHoverRadius: 7,
                pointBorderWidth: 2
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top', labels: { usePointStyle: true, font: { size: 10 } } },
                tooltip: { backgroundColor: '#2c3e50', titleColor: '#fff', bodyColor: '#ddd' }
            },
            scales: {
                y: { beginAtZero: true, grid: { color: '#ecf0f1' }, ticks: { font: { size: 9 } } },
                x: { grid: { display: false }, ticks: { font: { size: 9 } } }
            },
            animation: { duration: 800 }
        }
    });
})();
<?php endif; ?>

// ---- Big Service Donut (with % labels) ----
<?php if(count($service_stats) > 0): ?>
(function() {
    const el = document.getElementById('serviceChart');
    if (!el) return;
    const serviceLabels = <?php echo json_encode(array_column($service_stats, 'type_of_community_service')); ?>;
    const serviceData = <?php echo json_encode(array_map('intval', array_column($service_stats, 'count'))); ?>;
    const total = serviceData.reduce((a, b) => a + b, 0);

    new Chart(el.getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: serviceLabels,
            datasets: [{
                data: serviceData,
                backgroundColor: ['#3498db', '#e74c3c', '#f39c12', '#27ae60', '#9b59b6', '#1abc9c', '#e67e22', '#2ecc71'],
                borderWidth: 2,
                borderColor: '#fff',
                hoverOffset: 10
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom', labels: { font: { size: 9, weight: 'bold' }, usePointStyle: true, padding: 8 } },
                tooltip: {
                    backgroundColor: '#2c3e50',
                    callbacks: {
                        label: (ctx) => {
                            const val = ctx.raw;
                            const pct = formatPercent(val, total);
                            return ` ${ctx.label}: ${val} (${pct})`;
                        }
                    }
                },
                datalabels: {
                    color: '#fff',
                    font: { weight: 'bold', size: 10 },
                    formatter: (value, ctx) => {
                        const pct = formatPercent(value, total);
                        return pct;
                    },
                    display: (ctx) => {
                        // Hide tiny slices (< 5%) to avoid overlap
                        const pct = (ctx.dataset.data[ctx.dataIndex] / total) * 100;
                        return pct >= 5;
                    }
                }
            },
            cutout: '55%',
            animation: { animateScale: true, animateRotate: true }
        },
        plugins: [ChartDataLabels]
    });
})();
<?php endif; ?>

// ---- Compact Do Nut (with % labels + custom legend) ----
<?php if(count($service_stats) > 0): ?>
(function() {
    const el = document.getElementById('doNutChart');
    if (!el) return;
    const serviceLabels = <?php echo json_encode(array_column($service_stats, 'type_of_community_service')); ?>;
    const serviceData = <?php echo json_encode(array_map('intval', array_column($service_stats, 'count'))); ?>;
    const colors = ['#3498db', '#e74c3c', '#f39c12', '#27ae60', '#9b59b6'];
    const total = serviceData.reduce((a, b) => a + b, 0);

    // Render the % inside each slice
    new Chart(el.getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: serviceLabels,
            datasets: [{
                data: serviceData,
                backgroundColor: colors,
                borderWidth: 2,
                borderColor: '#fff',
                hoverOffset: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: { padding: 4 },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#2c3e50',
                    callbacks: {
                        label: (ctx) => {
                            const val = ctx.raw;
                            const pct = formatPercent(val, total);
                            return ` ${ctx.label}: ${val} (${pct})`;
                        }
                    }
                },
                datalabels: {
                    color: '#fff',
                    font: { weight: 'bold', size: 10 },
                    formatter: (value) => formatPercent(value, total),
                    display: (ctx) => {
                        const pct = (ctx.dataset.data[ctx.dataIndex] / total) * 100;
                        return pct >= 8; // hide very small slices to keep it readable
                    },
                    textShadowColor: 'rgba(0,0,0,0.35)',
                    textShadowBlur: 3
                }
            },
            cutout: '60%',
            animation: { animateScale: true, animateRotate: true }
        },
        plugins: [ChartDataLabels]
    });

    // Build custom legend with % next to each label
    const legendEl = document.getElementById('doNutLegend');
    if (legendEl) {
        legendEl.innerHTML = serviceLabels.map((label, i) => {
            const val = serviceData[i];
            const pct = formatPercent(val, total);
            return `<span class="donut-legend-item">
                        <span class="donut-legend-dot" style="background:${colors[i % colors.length]};"></span>
                        ${label} <span class="donut-legend-pct">${pct}</span>
                    </span>`;
        }).join('');
    }
})();
<?php endif; ?>

// Mobile navbar toggle
document.addEventListener('DOMContentLoaded', function() {
    const toggler = document.querySelector('.navbar-toggler');
    const collapse = document.querySelector('.navbar-collapse');
    if (toggler && collapse) {
        toggler.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            collapse.classList.toggle('show');
        });
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.navbar')) {
                if (collapse.classList.contains('show')) collapse.classList.remove('show');
            }
        });
    }
});
</script>

<?php include 'includes/footer.php'; ?>