<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Get filter parameters
$filter_action = isset($_GET['action']) ? $_GET['action'] : 'all';
$filter_date = isset($_GET['date']) ? $_GET['date'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Detect admin once
$admin = isAdmin();
$current_user_id = getCurrentUserId();

// Build query based on user role
if ($admin) {
    $sql = "SELECT al.*, u.username, u.full_name, u.role 
            FROM activity_log al 
            LEFT JOIN users u ON al.user_id = u.id 
            WHERE 1=1";
    $params = [];
} else {
    $sql = "SELECT al.*, u.username, u.full_name, u.role 
            FROM activity_log al 
            LEFT JOIN users u ON al.user_id = u.id 
            WHERE al.user_id = ?";
    $params = [$current_user_id];
}

if ($filter_action != 'all') {
    $sql .= " AND al.action = ?";
    $params[] = $filter_action;
}

if (!empty($filter_date)) {
    $sql .= " AND DATE(al.created_at) = ?";
    $params[] = $filter_date;
}

if (!empty($search)) {
    $sql .= " AND (al.description LIKE ? OR u.username LIKE ? OR u.full_name LIKE ?)";
    $search_term = "%$search%";
    $params[] = $search_term;
    $params[] = $search_term;
    $params[] = $search_term;
}

$sql .= " ORDER BY al.created_at DESC LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique actions for filter (based on role)
if ($admin) {
    $actions = $pdo->query("SELECT DISTINCT action FROM activity_log ORDER BY action")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT DISTINCT action FROM activity_log WHERE user_id = ? ORDER BY action");
    $stmt->execute([$current_user_id]);
    $actions = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get statistics based on role
if ($admin) {
    $total_logs = $pdo->query("SELECT COUNT(*) FROM activity_log")->fetchColumn();
    $today_logs = $pdo->query("SELECT COUNT(*) FROM activity_log WHERE DATE(created_at) = CURDATE()")->fetchColumn();
    $this_week_logs = $pdo->query("SELECT COUNT(*) FROM activity_log WHERE YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)")->fetchColumn();
    $this_month_logs = $pdo->query("SELECT COUNT(*) FROM activity_log WHERE MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())")->fetchColumn();
} else {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE user_id = ?");
    $stmt->execute([$current_user_id]);
    $total_logs = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE user_id = ? AND DATE(created_at) = CURDATE()");
    $stmt->execute([$current_user_id]);
    $today_logs = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE user_id = ? AND YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)");
    $stmt->execute([$current_user_id]);
    $this_week_logs = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM activity_log WHERE user_id = ? AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())");
    $stmt->execute([$current_user_id]);
    $this_month_logs = $stmt->fetchColumn();
}

include 'includes/header.php';
?>

<style>
/* Premium Activity Log Styles */
:root {
    --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.logs-header {
    background: var(--primary-gradient);
    border-radius: 24px;
    padding: 30px;
    margin-bottom: 30px;
    color: white;
    position: relative;
    overflow: hidden;
}

.logs-header::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle, rgba(255,255,255,0.1) 1px, transparent 1px);
    background-size: 40px 40px;
    animation: floatBG 20s linear infinite;
}

@keyframes floatBG {
    0% { transform: translate(0, 0); }
    100% { transform: translate(-50px, -50px); }
}

.logs-header h2 {
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 10px;
    position: relative;
    z-index: 1;
}

.logs-header p {
    opacity: 0.9;
    margin-bottom: 0;
    position: relative;
    z-index: 1;
}

/* Stats Cards */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 25px;
}

.stat-card {
    background: white;
    border-radius: 20px;
    padding: 20px;
    text-align: center;
    transition: all 0.3s;
    box-shadow: 0 5px 20px rgba(0,0,0,0.05);
}

.stat-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.stat-value {
    font-size: 32px;
    font-weight: 800;
    color: #2c3e50;
}

.stat-label {
    font-size: 13px;
    color: #7f8c8d;
    margin-top: 5px;
}

.stat-icon {
    font-size: 28px;
    margin-bottom: 10px;
}

/* Filter Bar */
.filter-bar {
    background: white;
    border-radius: 20px;
    padding: 20px;
    margin-bottom: 25px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.05);
}

.filter-group {
    margin-bottom: 15px;
}

.filter-label {
    font-weight: 600;
    font-size: 13px;
    color: #2c3e50;
    margin-bottom: 8px;
    display: block;
}

.filter-select, .filter-input {
    padding: 10px 15px;
    border-radius: 12px;
    border: 1px solid #e0e0e0;
    width: 100%;
    transition: all 0.3s;
}

.filter-select:focus, .filter-input:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
}

.btn-clear {
    background: #f8f9fa;
    border: 1px solid #e0e0e0;
    padding: 10px 20px;
    border-radius: 12px;
    color: #6c757d;
    text-decoration: none;
    display: inline-block;
    transition: all 0.3s;
}

.btn-clear:hover {
    background: #e9ecef;
}

.btn-apply {
    background: var(--primary-gradient);
    border: none;
    padding: 10px 25px;
    border-radius: 12px;
    color: white;
    font-weight: 500;
    transition: all 0.3s;
    cursor: pointer;
}

.btn-apply:hover {
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(102,126,234,0.4);
}

/* Action Badges */
.action-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.action-badge.login { background: #e8f8f5; color: #1abc9c; }
.action-badge.logout { background: #fef9e7; color: #f39c12; }
.action-badge.upload { background: #eafaf1; color: #27ae60; }
.action-badge.delete { background: #fdeded; color: #e74c3c; }
.action-badge.archive { background: #f4ecf7; color: #9b59b6; }
.action-badge.restore { background: #e8f8f5; color: #1abc9c; }
.action-badge.recover { background: #e8f8f5; color: #16a085; }
.action-badge.download { background: #ebf5fb; color: #2980b9; }
.action-badge.default { background: #f0f0f0; color: #555; }

/* Table Styles */
.logs-table {
    background: white;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 5px 20px rgba(0,0,0,0.05);
}

.logs-table .table {
    margin-bottom: 0;
}

.logs-table .table thead th {
    background: var(--primary-gradient);
    color: white;
    font-weight: 600;
    padding: 15px 20px;
    border: none;
    font-size: 13px;
}

.logs-table .table tbody tr {
    transition: all 0.3s;
}

.logs-table .table tbody tr:hover {
    background: linear-gradient(135deg, rgba(102,126,234,0.05), rgba(118,75,162,0.05));
}

.logs-table .table td {
    padding: 15px 20px;
    vertical-align: middle;
    border-bottom: 1px solid #f0f0f0;
}

.user-cell {
    display: flex;
    align-items: center;
    gap: 10px;
}

.user-avatar-small {
    width: 32px;
    height: 32px;
    background: var(--primary-gradient);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: bold;
    font-size: 12px;
    flex-shrink: 0;
}

.user-info {
    line-height: 1.3;
}

.user-name {
    font-weight: 600;
    font-size: 13px;
}

.user-role {
    font-size: 10px;
    color: #7f8c8d;
}

/* Role Badge */
.role-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 600;
}

.role-admin {
    background: #fdeded;
    color: #e74c3c;
}

.role-staff {
    background: #ebf5fb;
    color: #3498db;
}

/* Animations */
@keyframes fadeInUp {
    from { opacity: 0; transform: translateY(30px); }
    to { opacity: 1; transform: translateY(0); }
}

.fade-up {
    opacity: 0;
    transform: translateY(30px);
    transition: all 0.6s ease;
}

.fade-up.visible {
    opacity: 1;
    transform: translateY(0);
}

@media (max-width: 768px) {
    .stats-grid {
        gap: 15px;
    }
    .stat-value {
        font-size: 24px;
    }
    .logs-table .table td, 
    .logs-table .table th {
        padding: 10px 12px;
        font-size: 12px;
    }
}
</style>

<div class="logs-header fade-up">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h2><i class="fas fa-history"></i> Activity Log</h2>
            <p><?php echo $admin ? 'Track all user activities and system events' : 'Track your personal activity history'; ?></p>
        </div>
        <?php if($admin): ?>
        <div>
            <button class="btn-apply" onclick="exportLogs()">
                <i class="fas fa-download"></i> Export Logs
            </button>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card fade-up">
        <div class="stat-icon"><i class="fas fa-database" style="color: #3498db;"></i></div>
        <div class="stat-value"><?php echo number_format($total_logs); ?></div>
        <div class="stat-label">Total Activities</div>
    </div>
    <div class="stat-card fade-up">
        <div class="stat-icon"><i class="fas fa-calendar-day" style="color: #27ae60;"></i></div>
        <div class="stat-value"><?php echo number_format($today_logs); ?></div>
        <div class="stat-label">Today</div>
    </div>
    <div class="stat-card fade-up">
        <div class="stat-icon"><i class="fas fa-calendar-week" style="color: #f39c12;"></i></div>
        <div class="stat-value"><?php echo number_format($this_week_logs); ?></div>
        <div class="stat-label">This Week</div>
    </div>
    <div class="stat-card fade-up">
        <div class="stat-icon"><i class="fas fa-calendar-alt" style="color: #9b59b6;"></i></div>
        <div class="stat-value"><?php echo number_format($this_month_logs); ?></div>
        <div class="stat-label">This Month</div>
    </div>
</div>

<!-- Filter Bar -->
<div class="filter-bar fade-up">
    <form method="GET" id="filterForm">
        <div class="row">
            <div class="col-md-4">
                <div class="filter-group">
                    <label class="filter-label"><i class="fas fa-filter"></i> Action Type</label>
                    <select name="action" class="filter-select" onchange="this.form.submit()">
                        <option value="all" <?php echo $filter_action == 'all' ? 'selected' : ''; ?>>All Actions</option>
                        <?php foreach($actions as $action): ?>
                            <option value="<?php echo htmlspecialchars($action['action']); ?>" <?php echo $filter_action == $action['action'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($action['action']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="col-md-3">
                <div class="filter-group">
                    <label class="filter-label"><i class="fas fa-calendar"></i> Date</label>
                    <input type="date" name="date" class="filter-input" value="<?php echo htmlspecialchars($filter_date); ?>" onchange="this.form.submit()">
                </div>
            </div>
            <div class="col-md-3">
                <div class="filter-group">
                    <label class="filter-label"><i class="fas fa-search"></i> Search</label>
                    <input type="text" name="search" class="filter-input" placeholder="Search description..." value="<?php echo htmlspecialchars($search); ?>" onkeyup="if(event.keyCode==13) this.form.submit()">
                </div>
            </div>
            <div class="col-md-2">
                <div class="filter-group">
                    <label class="filter-label">&nbsp;</label>
                    <a href="activity_log.php" class="btn-clear d-block text-center">
                        <i class="fas fa-times"></i> Clear
                    </a>
                </div>
            </div>
        </div>
    </form>
</div>

<!-- Activity Logs Table -->
<div class="logs-table fade-up">
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th><i class="fas fa-clock"></i> Date & Time</th>
                    <?php if($admin): ?>
                    <th><i class="fas fa-user"></i> User</th>
                    <?php endif; ?>
                    <th><i class="fas fa-tag"></i> Action</th>
                    <th><i class="fas fa-info-circle"></i> Description</th>
                    
                </tr>
            </thead>
            <tbody>
                <?php if(count($logs) > 0): ?>
                    <?php foreach($logs as $log): 
                        $action_key = strtolower($log['action']);
                        $valid_classes = ['login','logout','upload','delete','archive','restore','recover','download'];
                        $badge_class = in_array($action_key, $valid_classes) ? $action_key : 'default';

                        $icon_map = [
                            'login' => 'fa-sign-in-alt',
                            'logout' => 'fa-sign-out-alt',
                            'upload' => 'fa-upload',
                            'delete' => 'fa-trash',
                            'archive' => 'fa-archive',
                            'restore' => 'fa-undo',
                            'recover' => 'fa-recycle',
                            'download' => 'fa-download',
                            'default' => 'fa-bell'
                        ];
                        $icon = $icon_map[$badge_class] ?? 'fa-bell';
                    ?>
                    <tr>
                        <td>
                            <i class="far fa-calendar-alt me-1 text-muted"></i> <?php echo date('M d, Y', strtotime($log['created_at'])); ?>
                            <br>
                            <small class="text-muted"><i class="far fa-clock"></i> <?php echo date('h:i:s A', strtotime($log['created_at'])); ?></small>
                        </td>
                        <?php if($admin): ?>
                        <td>
                            <div class="user-cell">
                                <div class="user-avatar-small">
                                    <?php echo strtoupper(substr($log['full_name'] ?? $log['username'] ?? 'S', 0, 1)); ?>
                                </div>
                                <div class="user-info">
                                    <div class="user-name"><?php echo htmlspecialchars($log['full_name'] ?? $log['username'] ?? 'System'); ?></div>
                                    <?php if(!empty($log['role'])): ?>
                                        <span class="role-badge role-<?php echo htmlspecialchars($log['role']); ?>"><?php echo ucfirst($log['role']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <?php endif; ?>
                        <td>
                            <span class="action-badge <?php echo $badge_class; ?>">
                                <i class="fas <?php echo $icon; ?>"></i>
                                <?php echo htmlspecialchars($log['action']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($log['description']); ?></td>
                        <td>
                            
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="<?php echo $admin ? '5' : '4'; ?>" class="text-center py-5">
                            <i class="fas fa-inbox fa-3x text-muted mb-3 d-block"></i>
                            <h5>No activity logs found</h5>
                            <p class="text-muted">Try changing your filter criteria or perform some actions</p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Intersection Observer for scroll animations
const observerOptions = { threshold: 0.1, rootMargin: '0px 0px -50px 0px' };
const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) entry.target.classList.add('visible');
    });
}, observerOptions);
document.querySelectorAll('.fade-up').forEach(el => observer.observe(el));

// Export logs function (admin only)
function exportLogs() {
    window.location.href = 'export_logs.php' + window.location.search;
}
</script>

<?php include 'includes/footer.php'; ?>