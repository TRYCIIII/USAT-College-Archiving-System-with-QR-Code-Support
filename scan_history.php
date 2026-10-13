<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/auth.php';
require_once 'config/database.php';

// Check if user is logged in and is admin
if (!isLoggedIn() || !isAdmin()) {
    header("Location: index.php");
    exit();
}

// Set timezone
date_default_timezone_set('Asia/Manila');

// ============================================
// FILTERS
// ============================================
$filter_date = isset($_GET['date']) ? trim($_GET['date']) : '';
$filter_user = isset($_GET['user']) ? (int)$_GET['user'] : 0;
$filter_file = isset($_GET['file_id']) ? (int)$_GET['file_id'] : 0;
$search      = isset($_GET['search']) ? trim($_GET['search']) : '';

// Pagination
$page    = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$perPage = 20;
$offset  = ($page - 1) * $perPage;

// ============================================
// BUILD WHERE CLAUSE
// ============================================
$where  = " WHERE 1=1 ";
$params = [];

if ($filter_date !== '') {
    $where .= " AND DATE(qs.scanned_at) = ? ";
    $params[] = $filter_date;
}
if ($filter_user > 0) {
    $where .= " AND qs.scanned_by = ? ";
    $params[] = $filter_user;
}
if ($filter_file > 0) {
    $where .= " AND qs.file_id = ? ";
    $params[] = $filter_file;
}
if ($search !== '') {
    $where .= " AND (f.file_name LIKE ? OR f.control_number LIKE ? OR u.full_name LIKE ? OR u.username LIKE ?) ";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

// ============================================
// COUNT TOTAL
// ============================================
$total_rows = 0;
$total_pages = 1;

try {
    $countSql = "SELECT COUNT(*) FROM qr_scans qs 
                 LEFT JOIN files f ON qs.file_id = f.id 
                 LEFT JOIN users u ON qs.scanned_by = u.id" . $where;
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $total_rows = (int)$countStmt->fetchColumn();
    $total_pages = max(1, ceil($total_rows / $perPage));
    if ($page > $total_pages) $page = $total_pages;
} catch (PDOException $e) {
    $total_rows = 0;
    $total_pages = 1;
}

// ============================================
// FETCH SCANS
// ============================================
$scans = [];
try {
    $sql = "SELECT qs.*, 
                   f.file_name, 
                   f.control_number,
                   u.full_name AS scanned_by_name,
                   u.username AS scanned_by_username
            FROM qr_scans qs
            LEFT JOIN files f ON qs.file_id = f.id
            LEFT JOIN users u ON qs.scanned_by = u.id"
         . $where
         . " ORDER BY qs.scanned_at DESC LIMIT $perPage OFFSET $offset";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $scans = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS qr_scans (
                id INT AUTO_INCREMENT PRIMARY KEY,
                file_id INT NOT NULL,
                scanned_by INT,
                scanned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                ip_address VARCHAR(45),
                user_agent TEXT,
                status VARCHAR(20) DEFAULT 'success',
                INDEX idx_file_id (file_id),
                INDEX idx_scanned_at (scanned_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (PDOException $e2) {}
    $scans = [];
}

// ============================================
// SUMMARY STATS
// ============================================
try {
    $total_scans   = (int)$pdo->query("SELECT COUNT(*) FROM qr_scans")->fetchColumn();
    $today_scans   = (int)$pdo->query("SELECT COUNT(*) FROM qr_scans WHERE DATE(scanned_at) = CURDATE()")->fetchColumn();
    $week_scans    = (int)$pdo->query("SELECT COUNT(*) FROM qr_scans WHERE YEARWEEK(scanned_at, 1) = YEARWEEK(CURDATE(), 1)")->fetchColumn();
    $unique_files  = (int)$pdo->query("SELECT COUNT(DISTINCT file_id) FROM qr_scans")->fetchColumn();
    
} catch (PDOException $e) {
    $total_scans = $today_scans = $week_scans = $unique_files = 0;
}

// ============================================
// USERS LIST
// ============================================
$users_list = [];
try {
    $users_stmt = $pdo->query("SELECT id, full_name, username FROM users WHERE status = 'active' ORDER BY full_name");
    $users_list = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// Helper
function buildQueryString($overrides = []) {
    $base = $_GET;
    foreach ($overrides as $k => $v) {
        if ($v === null) unset($base[$k]);
        else $base[$k] = $v;
    }
    return http_build_query($base);
}

include 'includes/header.php';
?>

<!-- Scan History Container -->
<div class="scan-history-container">

    <!-- Page Header -->
    <div class="page-header">
        <h2>
            <i class="fas fa-qrcode" style="color: #667eea;"></i> QR Scan History
        </h2>
        <div class="header-actions">
            <button onclick="window.location.reload()" class="btn-refresh">
                <i class="fas fa-sync"></i> Refresh
            </button>
            <button onclick="window.print()" class="btn-print">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>

    <!-- Stats Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fas fa-qrcode"></i></div>
            <div class="stat-info">
                <div class="stat-number"><?php echo number_format($total_scans); ?></div>
                <div class="stat-label">Total Scans</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon green"><i class="fas fa-calendar-day"></i></div>
            <div class="stat-info">
                <div class="stat-number"><?php echo number_format($today_scans); ?></div>
                <div class="stat-label">Today's Scans</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon pink"><i class="fas fa-calendar-week"></i></div>
            <div class="stat-info">
                <div class="stat-number"><?php echo number_format($week_scans); ?></div>
                <div class="stat-label">This Week</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon purple"><i class="fas fa-file"></i></div>
            <div class="stat-info">
                <div class="stat-number"><?php echo number_format($unique_files); ?></div>
                <div class="stat-label">Unique Documents</div>
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-icon blue"><i class="fas fa-users"></i></div>
            <div class="stat-info">
                <div class="stat-number"><?php echo number_format(count($users_list)); ?></div>
                <div class="stat-label">Active Users</div>
            </div>
        </div>
    </div><!-- /.stats-grid -->

    <!-- Filters -->
    <div class="filter-container">
        <form method="GET" class="filter-form">
            <div class="filter-group">
                <i class="fas fa-calendar"></i>
                <input type="date" name="date" class="form-control" value="<?php echo htmlspecialchars($filter_date); ?>">
            </div>

            <div class="filter-group">
                <i class="fas fa-user"></i>
                <select name="user" class="form-control">
                    <option value="0">All Users</option>
                    <?php foreach ($users_list as $u): ?>
                        <option value="<?php echo (int)$u['id']; ?>" <?php echo ($filter_user == $u['id']) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($u['full_name'] . ' (' . $u['username'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group search-group">
                <i class="fas fa-search"></i>
                <input type="text" name="search" class="form-control" placeholder="Search files or users..." value="<?php echo htmlspecialchars($search); ?>">
            </div>

            <button type="submit" class="btn-filter">
                <i class="fas fa-filter"></i> Filter
            </button>

            <a href="scan_history.php" class="btn-clear">
                <i class="fas fa-times"></i> Clear
            </a>
        </form>
    </div>

    <!-- Table -->
    <div class="table-container">
        <div class="table-header">
            <h5>
                <i class="fas fa-clock" style="color: #667eea;"></i> Scan Records
                <?php if ($total_rows > 0): ?>
                    <span class="record-count"><?php echo number_format($total_rows); ?></span>
                <?php endif; ?>
            </h5>
            <div class="table-badges">
                <span class="badge badge-primary"><i class="fas fa-database"></i> Total: <?php echo number_format($total_scans); ?></span>
                <span class="badge badge-success"><i class="fas fa-calendar-day"></i> Today: <?php echo number_format($today_scans); ?></span>
            </div>
        </div>

       

        <div class="table-responsive" id="slideTable">
            <?php if (empty($scans)): ?>
                <div class="empty-state">
                    <i class="fas fa-qrcode"></i>
                    <p>No scan records found</p>
                    <small>Scan a QR code to start recording history.</small>
                </div>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Document</th>
                            <th>Control #</th>
                            <th>Date &amp; Time</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($scans as $index => $scan): ?>
                            <?php $rowNum = $offset + $index + 1; ?>
                            <tr>
                                <td data-label="#"><?php echo $rowNum; ?></td>
                                <td data-label="Document">
                                    <?php if (!empty($scan['file_id'])): ?>
                                        <a href="view_document.php?id=<?php echo (int)$scan['file_id']; ?>" target="_blank" class="doc-link" title="<?php echo htmlspecialchars($scan['file_name'] ?? 'Deleted file'); ?>">
                                            <i class="fas fa-file"></i>
                                            <span class="doc-name"><?php echo htmlspecialchars($scan['file_name'] ?? 'Deleted file'); ?></span>
                                        </a>
                                    <?php else: ?>
                                        <span class="text-muted"><i class="fas fa-trash"></i> Deleted file</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Control #">
                                    <?php if (!empty($scan['control_number'])): ?>
                                        <code><?php echo htmlspecialchars($scan['control_number']); ?></code>
                                    <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Date &amp; Time">
                                    <div class="date-time">
                                        <?php echo date('M d, Y', strtotime($scan['scanned_at'])); ?>
                                        <span class="time"><?php echo date('h:i:s A', strtotime($scan['scanned_at'])); ?></span>
                                    </div>
                                </td>
                                <td data-label="Status">
                                    <span class="status-badge success"><?php echo ucfirst($scan['status'] ?? 'success'); ?></span>
                                </td>
                                <td data-label="Action">
                                    <?php if (!empty($scan['file_id'])): ?>
                                        <div class="action-buttons">
                                            <a href="view_document.php?id=<?php echo (int)$scan['file_id']; ?>" target="_blank" class="btn-action view" title="View Document">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="download_file.php?id=<?php echo (int)$scan['file_id']; ?>" class="btn-action download" title="Download Document">
                                                <i class="fas fa-download"></i>
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="pagination-wrapper">
                <div class="pagination-info">
                    Showing <strong><?php echo $offset + 1; ?></strong>–<strong><?php echo min($offset + $perPage, $total_rows); ?></strong>
                    of <strong><?php echo number_format($total_rows); ?></strong> records
                </div>
                <div class="pagination">
                    <a href="?<?php echo buildQueryString(['page' => $page - 1]); ?>"
                       class="page-btn <?php echo ($page <= 1) ? 'disabled' : ''; ?>">
                        <i class="fas fa-chevron-left"></i>
                    </a>

                    <?php
                    $start = max(1, $page - 2);
                    $end = min($total_pages, $page + 2);

                    if ($start > 1) {
                        echo '<a href="?' . buildQueryString(['page' => 1]) . '" class="page-btn">1</a>';
                        if ($start > 2) echo '<span class="page-btn disabled">…</span>';
                    }

                    for ($p = $start; $p <= $end; $p++) {
                        $active = ($p === $page) ? 'active' : '';
                        echo '<a href="?' . buildQueryString(['page' => $p]) . '" class="page-btn ' . $active . '">' . $p . '</a>';
                    }

                    if ($end < $total_pages) {
                        if ($end < $total_pages - 1) echo '<span class="page-btn disabled">…</span>';
                        echo '<a href="?' . buildQueryString(['page' => $total_pages]) . '" class="page-btn">' . $total_pages . '</a>';
                    }
                    ?>

                    <a href="?<?php echo buildQueryString(['page' => $page + 1]); ?>"
                       class="page-btn <?php echo ($page >= $total_pages) ? 'disabled' : ''; ?>">
                        <i class="fas fa-chevron-right"></i>
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
/* ============================================
   SCAN HISTORY — FIXED SPACING
   ============================================ */

.scan-history-container {
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    padding: 0;
    margin: 0;
}

/* ============================================
   PAGE HEADER
   ============================================ */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
    flex-wrap: wrap;
    gap: 10px;
}

.page-header h2 {
    font-weight: 700;
    color: #1a1a2e;
    margin: 0;
    font-size: 19px;
    display: flex;
    align-items: center;
    gap: 9px;
}

.header-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.btn-refresh, .btn-print {
    border-radius: 8px;
    padding: 7px 14px;
    font-size: 12.5px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
    font-family: inherit;
}

.btn-refresh {
    background: white;
    color: #374151;
    border: 1px solid #e2e8f0;
}
.btn-refresh:hover { background: #f1f5f9; }

.btn-print {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
}
.btn-print:hover {
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
}

/* ============================================
   STATS GRID
   ============================================ */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 14px;
    width: 100%;
}

.stat-card {
    background: white;
    border-radius: 10px;
    padding: 12px 12px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    display: flex;
    align-items: center;
    gap: 10px;
    transition: box-shadow 0.2s;
    border: 1px solid #f1f5f9;
    box-sizing: border-box;
    min-width: 0;
    overflow: hidden;
}
.stat-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}

.stat-icon {
    width: 38px;
    height: 38px;
    border-radius: 9px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 16px;
    flex-shrink: 0;
}
.stat-icon.blue   { background: rgba(59, 130, 246, 0.1);  color: #3b82f6; }
.stat-icon.green  { background: rgba(16, 185, 129, 0.1);  color: #10b981; }
.stat-icon.purple { background: rgba(139, 92, 246, 0.1);  color: #8b5cf6; }
.stat-icon.orange { background: rgba(245, 158, 11, 0.1);  color: #f59e0b; }
.stat-icon.pink   { background: rgba(236, 72, 153, 0.1);  color: #ec4899; }

.stat-info { flex: 1; min-width: 0; }
.stat-number {
    font-size: 18px;
    font-weight: 700;
    color: #1a1a2e;
    line-height: 1.15;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.stat-label {
    color: #64748b;
    font-size: 10.5px;
    margin-top: 1px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

/* ============================================
   FILTER CONTAINER
   ============================================ */
.filter-container {
    background: white;
    border-radius: 10px;
    padding: 12px 14px;
    margin-bottom: 14px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    border: 1px solid #f1f5f9;
    width: 100%;
    box-sizing: border-box;
}

.filter-form {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    align-items: center;
    width: 100%;
}

.filter-group {
    display: flex;
    align-items: center;
    gap: 7px;
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 0 11px;
    transition: all 0.2s;
    flex: 1;
    min-width: 140px;
    height: 36px;
    box-sizing: border-box;
}

.filter-group:focus-within {
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    background: white;
}

.filter-group i {
    color: #94a3b8;
    font-size: 12px;
    flex-shrink: 0;
}

.filter-group .form-control {
    border: none;
    background: transparent;
    padding: 5px 0;
    font-size: 12.5px;
    color: #1a1a2e;
    width: 100%;
    min-width: 0;
    outline: none;
    box-shadow: none;
    font-family: inherit;
}

.filter-group select.form-control {
    cursor: pointer;
    appearance: auto;
}

.search-group { flex: 2; min-width: 200px; }

.btn-filter, .btn-clear {
    border-radius: 8px;
    padding: 7px 16px;
    font-size: 12.5px;
    font-weight: 600;
    border: none;
    cursor: pointer;
    transition: all 0.2s;
    display: inline-flex;
    align-items: center;
    gap: 5px;
    white-space: nowrap;
    height: 36px;
    text-decoration: none;
    font-family: inherit;
}

.btn-filter {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
}
.btn-filter:hover {
    box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
}

.btn-clear {
    background: #e2e8f0;
    color: #475569;
}
.btn-clear:hover { background: #cbd5e1; }

/* ============================================
   TABLE CONTAINER
   ============================================ */
.table-container {
    background: white;
    border-radius: 10px;
    padding: 14px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
    border: 1px solid #f1f5f9;
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    overflow: visible;
}

.table-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    flex-wrap: wrap;
    gap: 8px;
}

.table-header h5 {
    font-weight: 600;
    color: #1a1a2e;
    margin: 0;
    font-size: 13.5px;
    display: flex;
    align-items: center;
    gap: 7px;
}

.record-count {
    background: #667eea;
    color: white;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 10.5px;
    font-weight: 700;
}

.table-badges { display: flex; gap: 5px; flex-wrap: wrap; }

.badge {
    padding: 3px 9px;
    border-radius: 12px;
    font-size: 10.5px;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 4px;
}
.badge-primary { background: rgba(59, 130, 246, 0.1);  color: #3b82f6; }
.badge-success { background: rgba(16, 185, 129, 0.1);  color: #10b981; }

/* ============================================
   SLIDE HINT
   ============================================ */
.slide-hint {
    display: flex;
    align-items: center;
    gap: 7px;
    padding: 7px 11px;
    margin-bottom: 9px;
    background: linear-gradient(135deg, rgba(102, 126, 234, 0.08), rgba(118, 75, 162, 0.08));
    border: 1px dashed rgba(102, 126, 234, 0.3);
    border-radius: 8px;
    font-size: 11.5px;
    color: #667eea;
    font-weight: 600;
}

.slide-hint i {
    font-size: 13px;
    animation: slideHint 1.5s ease-in-out infinite;
}

@keyframes slideHint {
    0%, 100% { transform: translateX(-4px); }
    50% { transform: translateX(4px); }
}

/* ============================================
   TABLE RESPONSIVE
   ============================================ */
.table-responsive {
    overflow-x: auto;
    overflow-y: hidden;
    -webkit-overflow-scrolling: touch;
    border-radius: 8px;
    width: 100%;
    cursor: grab;
    scrollbar-width: thin;
    scrollbar-color: #cbd5e1 #f1f5f9;
    padding-bottom: 3px;
}

.table-responsive::-webkit-scrollbar { height: 9px; }
.table-responsive::-webkit-scrollbar-track { background: #f1f5f9; border-radius: 8px; }
.table-responsive::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 8px; border: 2px solid #f1f5f9; }
.table-responsive::-webkit-scrollbar-thumb:hover { background: #94a3b8; }

.table-responsive.dragging { cursor: grabbing; }
.table-responsive.dragging * { user-select: none; cursor: grabbing !important; }

/* ============================================
   TABLE — FIXED SPACING
   ============================================ */
.table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12.5px;
    min-width: 750px;
    max-width: 100%;
    box-sizing: border-box;
    table-layout: fixed;
}

.table thead {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
}

.table thead th {
    padding: 14px 16px;
    text-align: left;
    font-weight: 600;
    font-size: 10.5px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    border: none;
    white-space: nowrap;
    vertical-align: middle;
}

/* Column widths — balanced for 6 columns */
.table thead th:nth-child(1),
.table tbody td:nth-child(1) { width: 50px; text-align: center; }

.table thead th:nth-child(2),
.table tbody td:nth-child(2) { width: 30%; }

.table thead th:nth-child(3),
.table tbody td:nth-child(3) { width: 160px; }

.table thead th:nth-child(4),
.table tbody td:nth-child(4) { width: 22%; }

.table thead th:nth-child(5),
.table tbody td:nth-child(5) { width: 130px; text-align: center; }

.table thead th:nth-child(6),
.table tbody td:nth-child(6) { width: 110px; text-align: center; }

.table thead th:first-child { border-top-left-radius: 8px; border-bottom-left-radius: 8px; }
.table thead th:last-child  { border-top-right-radius: 8px; border-bottom-right-radius: 8px; }

.table tbody tr {
    border-bottom: 1px solid #f1f5f9;
    transition: background 0.15s;
}
.table tbody tr:hover { background: rgba(102, 126, 234, 0.04); }
.table tbody tr:last-child { border-bottom: none; }

.table tbody td {
    padding: 14px 16px;
    vertical-align: middle;
    color: #334155;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.45;
}

/* ============================================
   CELL CONTENT
   ============================================ */
.date-time { font-size: 12px; color: #475569; font-weight: 500; }
.date-time .time { display: block; font-size: 10px; color: #94a3b8; margin-top: 2px; font-weight: 400; }

.status-badge {
    padding: 5px 12px;
    border-radius: 12px;
    font-weight: 600;
    font-size: 10px;
    display: inline-block;
    text-transform: capitalize;
}
.status-badge.success { background: rgba(16, 185, 129, 0.1); color: #16a34a; }

.action-buttons { display: flex; gap: 6px; justify-content: center; align-items: center; }
.btn-action {
    width: 32px; height: 32px;
    border-radius: 7px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s;
    text-decoration: none;
    font-size: 12px;
    border: none;
    cursor: pointer;
}
.btn-action.view { background: #dbeafe; color: #2563eb; }
.btn-action.view:hover { background: #bfdbfe; }
.btn-action.download { background: #dcfce7; color: #16a34a; }
.btn-action.download:hover { background: #bbf7d0; }

.doc-link {
    color: #1a1a2e;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    font-weight: 500;
    transition: color 0.15s;
    max-width: 100%;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.doc-link:hover { color: #667eea; }
.doc-link i { color: #667eea; flex-shrink: 0; font-size: 12px; }
.doc-link .doc-name { overflow: hidden; text-overflow: ellipsis; }

.empty-state { text-align: center; padding: 45px 20px; }
.empty-state i { font-size: 44px; color: #cbd5e0; display: block; margin-bottom: 12px; }
.empty-state p { color: #64748b; font-size: 13.5px; margin: 0 0 5px 0; font-weight: 500; }
.empty-state small { color: #94a3b8; font-size: 12px; }

.text-muted { color: #94a3b8; font-size: 11.5px; }

code {
    background: #f1f5f9;
    padding: 3px 8px;
    border-radius: 5px;
    font-size: 10.5px;
    font-family: 'SF Mono', Monaco, monospace;
    color: #475569;
    font-weight: 600;
}

/* ============================================
   PAGINATION
   ============================================ */
.pagination-wrapper {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 14px;
    flex-wrap: wrap;
    gap: 9px;
}

.pagination-info { color: #64748b; font-size: 12px; }

.pagination { display: flex; gap: 4px; flex-wrap: wrap; }

.page-btn {
    min-width: 32px;
    height: 32px;
    padding: 0 9px;
    border-radius: 7px;
    background: white;
    border: 1px solid #e2e8f0;
    color: #475569;
    font-size: 12px;
    font-weight: 600;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    text-decoration: none;
    transition: all 0.15s;
    font-family: inherit;
}
.page-btn:hover { background: #f1f5f9; border-color: #cbd5e1; color: #667eea; }
.page-btn.active {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    border-color: transparent;
    box-shadow: 0 3px 8px rgba(102, 126, 234, 0.3);
}
.page-btn.disabled { opacity: 0.4; cursor: not-allowed; pointer-events: none; }

/* ============================================
   RESPONSIVE
   ============================================ */
@media (min-width: 1400px) {
    .stats-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); }
}

@media (max-width: 1400px) and (min-width: 1100px) {
    .stats-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
}

@media (max-width: 1100px) and (min-width: 769px) {
    .stats-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .page-header h2 { font-size: 18px; }
    .table { min-width: 700px; }
}

@media (max-width: 768px) {
    .page-header { flex-direction: column; align-items: stretch; margin-bottom: 14px; }
    .page-header h2 { font-size: 17px; }
    .header-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
    .btn-refresh, .btn-print { width: 100%; justify-content: center; padding: 8px; }

    .stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
        margin-bottom: 12px;
    }
    .stat-card { padding: 10px; gap: 8px; }
    .stat-icon { width: 32px; height: 32px; font-size: 13px; border-radius: 8px; }
    .stat-number { font-size: 15px; }
    .stat-label { font-size: 9.5px; }

    .filter-container { padding: 11px; }
    .filter-form { flex-direction: column; gap: 8px; }
    .filter-group, .search-group { width: 100%; min-width: auto; flex: none; }
    .btn-filter, .btn-clear { width: 100%; justify-content: center; }

    .table-container { padding: 11px; }
    .table-header { flex-direction: column; align-items: flex-start; gap: 7px; }
    .table-header h5 { font-size: 13px; width: 100%; }

    .slide-hint { display: none; }

    /* Mobile card view */
    .table { min-width: unset; width: 100%; table-layout: auto; }
    .table thead { display: none; }
    .table tbody tr {
        display: block;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 11px;
        margin-bottom: 7px;
        background: white;
    }
    .table tbody td,
    .table tbody td:nth-child(n) {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 7px 0;
        border: none;
        border-bottom: 1px dashed #f1f5f9;
        gap: 10px;
        font-size: 12px;
        text-align: right;
        width: auto !important;
    }
    .table tbody td:first-child { padding-top: 0; }
    .table tbody td:last-child { border-bottom: none; padding-bottom: 0; }
    .table tbody td::before {
        content: attr(data-label);
        font-weight: 700;
        color: #64748b;
        font-size: 9.5px;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        flex-shrink: 0;
        text-align: left;
        min-width: 78px;
    }

    .doc-link { justify-content: flex-end; text-align: right; max-width: 65%; }
    .action-buttons { justify-content: flex-end; }
    .date-time { text-align: right; }

    .pagination-wrapper { flex-direction: column; align-items: stretch; text-align: center; }
    .pagination { justify-content: center; }

    .table-responsive { cursor: default; }
}

@media (max-width: 480px) {
    .stat-number { font-size: 14px; }
    .stat-label { font-size: 9px; }
    .table tbody td { font-size: 11.5px; padding: 5px 0; }
    .table tbody td::before { font-size: 9px; min-width: 68px; }
    .btn-action { width: 28px; height: 28px; font-size: 11px; }
    .page-btn { min-width: 28px; height: 28px; font-size: 11px; padding: 0 6px; }
}

/* ============================================
   PRINT STYLES
   ============================================ */
@media print {
    .header-actions, .filter-container, .slide-hint,
    .pagination-wrapper, .action-buttons,
    .btn-refresh, .btn-print, .btn-filter, .btn-clear {
        display: none !important;
    }
    .stat-card, .table-container {
        box-shadow: none !important;
        border: 1px solid #ccc !important;
    }
    .table { min-width: unset; font-size: 11px; table-layout: auto; }
    .table thead { display: table-header-group; background: #667eea !important; }
    .table tbody tr { display: table-row !important; }
    .table tbody td { display: table-cell !important; border: 1px solid #ddd !important; padding: 8px 10px !important; }
    .table tbody td::before { display: none !important; }
    .badge, .status-badge, .table thead th {
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }
}
</style>

<script>
/* ============================================
   SLIDE-TO-SCROLL TABLE
   ============================================ */
document.addEventListener('DOMContentLoaded', function () {
    const sliders = document.querySelectorAll('.table-responsive');

    sliders.forEach(function (el) {
        let isDown = false;
        let startX = 0;
        let scrollLeft = 0;
        let moved = false;

        function updateCursor() {
            el.style.cursor = (el.scrollWidth > el.clientWidth) ? 'grab' : 'default';
        }
        updateCursor();
        window.addEventListener('resize', updateCursor);

        el.addEventListener('mousedown', function (e) {
            if (e.target.closest('a, button, input, select, label, textarea, .btn-action, .doc-link')) return;
            if (el.scrollWidth <= el.clientWidth) return;

            isDown = true;
            moved = false;
            el.classList.add('dragging');
            startX = e.pageX - el.offsetLeft;
            scrollLeft = el.scrollLeft;
        });

        el.addEventListener('mouseleave', function () {
            isDown = false;
            el.classList.remove('dragging');
        });

        el.addEventListener('mouseup', function () {
            isDown = false;
            el.classList.remove('dragging');
        });

        el.addEventListener('mousemove', function (e) {
            if (!isDown) return;
            e.preventDefault();
            const x = e.pageX - el.offsetLeft;
            const walk = (x - startX) * 1.5;
            if (Math.abs(walk) > 5) moved = true;
            el.scrollLeft = scrollLeft - walk;
        });

        el.addEventListener('touchstart', function () {
            el.classList.add('dragging');
        }, { passive: true });

        el.addEventListener('touchend', function () {
            el.classList.remove('dragging');
        }, { passive: true });

        el.addEventListener('click', function (e) {
            if (moved) {
                e.preventDefault();
                e.stopPropagation();
                moved = false;
            }
        }, true);

        el.addEventListener('wheel', function (e) {
            if (e.shiftKey && el.scrollWidth > el.clientWidth) {
                e.preventDefault();
                el.scrollLeft += e.deltaY;
            }
        }, { passive: false });
    });
});
</script>

<?php include 'includes/footer.php'; ?>