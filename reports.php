<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

// Set timezone
date_default_timezone_set('Asia/Manila');

// Get filter parameters
$filter_type = isset($_GET['filter']) ? $_GET['filter'] : 'this_month';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');

// Set date range based on filter
switch($filter_type) {
    case 'today':
        $start_date = date('Y-m-d');
        $end_date = date('Y-m-d');
        break;
    case 'yesterday':
        $start_date = date('Y-m-d', strtotime('-1 day'));
        $end_date = date('Y-m-d', strtotime('-1 day'));
        break;
    case 'this_week':
        $start_date = date('Y-m-d', strtotime('monday this week'));
        $end_date = date('Y-m-d');
        break;
    case 'this_month':
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-d');
        break;
    case 'last_month':
        $start_date = date('Y-m-01', strtotime('-1 month'));
        $end_date = date('Y-m-t', strtotime('-1 month'));
        break;
    case 'this_year':
        $start_date = date('Y-01-01');
        $end_date = date('Y-m-d');
        break;
    case 'last_year':
        $start_date = date('Y-01-01', strtotime('-1 year'));
        $end_date = date('Y-12-31', strtotime('-1 year'));
        break;
    case 'custom':
        $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
        $end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
        break;
}

// Initialize variables
$total_files = 0;
$total_folders = 0;
$total_archived = 0;
$total_beneficiaries = 0;
$service_stats = [];
$monthly_stats = [];
$recent_files = [];
$top_files = [];

try {
    if (isAdmin()) {
        // Total active files in date range
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM files WHERE status = 'active' AND DATE(created_at) BETWEEN ? AND ?");
        $stmt->execute([$start_date, $end_date]);
        $total_files = $stmt->fetch()['total'];
        
        // Total folders
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM folders WHERE status = 'active'");
        $total_folders = $stmt->fetch()['total'];
        
        // Total archived files in date range
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM files WHERE status = 'archived' AND DATE(created_at) BETWEEN ? AND ?");
        $stmt->execute([$start_date, $end_date]);
        $total_archived = $stmt->fetch()['total'];
        
        // Total beneficiaries in date range
        $stmt = $pdo->prepare("SELECT SUM(no_of_beneficiaries) as total FROM files WHERE status = 'active' AND DATE(created_at) BETWEEN ? AND ?");
        $stmt->execute([$start_date, $end_date]);
        $total_beneficiaries = $stmt->fetch()['total'] ?? 0;
        
        // Service type distribution
        $stmt = $pdo->prepare("SELECT type_of_community_service, COUNT(*) as count, SUM(no_of_beneficiaries) as beneficiaries 
                               FROM files WHERE status = 'active' AND DATE(created_at) BETWEEN ? AND ? 
                               GROUP BY type_of_community_service ORDER BY count DESC");
        $stmt->execute([$start_date, $end_date]);
        $service_stats = $stmt->fetchAll();
        
        // Monthly uploads trend
        $stmt = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%b %Y') as month, DATE_FORMAT(created_at, '%Y-%m') as month_sort, 
                               COUNT(*) as count, SUM(no_of_beneficiaries) as beneficiaries 
                               FROM files WHERE status = 'active' AND DATE(created_at) BETWEEN ? AND ? 
                               GROUP BY DATE_FORMAT(created_at, '%Y-%m'), DATE_FORMAT(created_at, '%b %Y')
                               ORDER BY month_sort ASC");
        $stmt->execute([$start_date, $end_date]);
        $monthly_stats = $stmt->fetchAll();
        
        // Top files by beneficiaries
        $stmt = $pdo->prepare("SELECT file_name, title_of_program, no_of_beneficiaries, type_of_community_service, date_conducted, created_at 
                               FROM files WHERE status = 'active' 
                               ORDER BY no_of_beneficiaries DESC LIMIT 10");
        $stmt->execute();
        $top_files = $stmt->fetchAll();
        
        // Recent files
        $stmt = $pdo->prepare("SELECT * FROM files WHERE status = 'active' ORDER BY created_at DESC LIMIT 10");
        $stmt->execute();
        $recent_files = $stmt->fetchAll();
        
    } else {
        $user_id = getCurrentUserId();
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM files WHERE status = 'active' AND uploaded_by = ? AND DATE(created_at) BETWEEN ? AND ?");
        $stmt->execute([$user_id, $start_date, $end_date]);
        $total_files = $stmt->fetch()['total'];
        
        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM folders WHERE status = 'active' AND created_by = ?");
        $stmt->execute([$user_id]);
        $total_folders = $stmt->fetch()['total'];
        
        $stmt = $pdo->prepare("SELECT SUM(no_of_beneficiaries) as total FROM files WHERE status = 'active' AND uploaded_by = ? AND DATE(created_at) BETWEEN ? AND ?");
        $stmt->execute([$user_id, $start_date, $end_date]);
        $total_beneficiaries = $stmt->fetch()['total'] ?? 0;
        
        $stmt = $pdo->prepare("SELECT type_of_community_service, COUNT(*) as count, SUM(no_of_beneficiaries) as beneficiaries 
                               FROM files WHERE status = 'active' AND uploaded_by = ? AND DATE(created_at) BETWEEN ? AND ? 
                               GROUP BY type_of_community_service ORDER BY count DESC");
        $stmt->execute([$user_id, $start_date, $end_date]);
        $service_stats = $stmt->fetchAll();
        
        $stmt = $pdo->prepare("SELECT DATE_FORMAT(created_at, '%b %Y') as month, DATE_FORMAT(created_at, '%Y-%m') as month_sort, COUNT(*) as count 
                               FROM files WHERE uploaded_by = ? AND status = 'active' AND DATE(created_at) BETWEEN ? AND ? 
                               GROUP BY DATE_FORMAT(created_at, '%Y-%m'), DATE_FORMAT(created_at, '%b %Y')
                               ORDER BY month_sort ASC");
        $stmt->execute([$user_id, $start_date, $end_date]);
        $monthly_stats = $stmt->fetchAll();
        
        $stmt = $pdo->prepare("SELECT file_name, title_of_program, no_of_beneficiaries, type_of_community_service, date_conducted, created_at 
                               FROM files WHERE status = 'active' AND uploaded_by = ? 
                               ORDER BY no_of_beneficiaries DESC LIMIT 10");
        $stmt->execute([$user_id]);
        $top_files = $stmt->fetchAll();
        
        $stmt = $pdo->prepare("SELECT * FROM files WHERE status = 'active' AND uploaded_by = ? ORDER BY created_at DESC LIMIT 10");
        $stmt->execute([$user_id]);
        $recent_files = $stmt->fetchAll();
        
        $total_archived = 0;
    }
    
    // Calculate growth
    $prev_start = date('Y-m-d', strtotime($start_date . ' -1 month'));
    $prev_end = date('Y-m-d', strtotime($end_date . ' -1 month'));
    $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM files WHERE status = 'active' AND DATE(created_at) BETWEEN ? AND ?");
    $stmt->execute([$prev_start, $prev_end]);
    $prev_total = $stmt->fetch()['total'];
    $growth = $prev_total > 0 ? round((($total_files - $prev_total) / $prev_total) * 100) : 0;
    
} catch (PDOException $e) {
    $error_message = "Database error: " . $e->getMessage();
}

include 'includes/header.php';
?>

<style>
/* Premium Reports Styles */
:root {
    --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
    --warning-gradient: linear-gradient(135deg, #f6d365 0%, #fda085 100%);
    --info-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
}

.reports-header {
    background: var(--primary-gradient);
    border-radius: 24px;
    padding: 30px;
    margin-bottom: 30px;
    color: white;
    position: relative;
    overflow: hidden;
}

.reports-header::before {
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

.reports-header h2 {
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 10px;
    position: relative;
    z-index: 1;
}

.reports-header p {
    opacity: 0.9;
    margin-bottom: 0;
    position: relative;
    z-index: 1;
}

/* Filter Bar */
.filter-bar {
    background: white;
    border-radius: 20px;
    padding: 20px;
    margin-bottom: 30px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.05);
}

.filter-btn {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 18px;
    margin: 5px;
    border-radius: 30px;
    border: 1px solid #e0e0e0;
    background: white;
    text-decoration: none;
    color: #5f6368;
    transition: all 0.3s;
    font-size: 13px;
    font-weight: 500;
}

.filter-btn i {
    font-size: 12px;
}

.filter-btn:hover, .filter-btn.active {
    background: var(--primary-gradient);
    color: white;
    border-color: transparent;
    transform: translateY(-2px);
}

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 25px;
    margin-bottom: 30px;
}

.stat-card-premium {
    background: white;
    border-radius: 20px;
    padding: 22px;
    transition: all 0.3s;
    box-shadow: 0 5px 20px rgba(0,0,0,0.05);
    position: relative;
    overflow: hidden;
}

.stat-card-premium:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 30px rgba(0,0,0,0.1);
}

.stat-icon {
    width: 50px;
    height: 50px;
    border-radius: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 15px;
}

.stat-icon.blue { background: rgba(52,152,219,0.1); color: #3498db; }
.stat-icon.green { background: rgba(39,174,96,0.1); color: #27ae60; }
.stat-icon.orange { background: rgba(243,156,18,0.1); color: #f39c12; }
.stat-icon.purple { background: rgba(155,89,182,0.1); color: #9b59b6; }

.stat-value {
    font-size: 32px;
    font-weight: 800;
    color: #2c3e50;
    line-height: 1.2;
}

.stat-label {
    font-size: 13px;
    color: #7f8c8d;
    margin-top: 5px;
}

.stat-trend {
    position: absolute;
    top: 20px;
    right: 20px;
    padding: 4px 10px;
    border-radius: 30px;
    font-size: 12px;
    font-weight: 600;
}

.trend-up { background: rgba(39,174,96,0.1); color: #27ae60; }
.trend-down { background: rgba(231,76,60,0.1); color: #e74c3c; }

/* Chart Containers */
.chart-container {
    background: white;
    border-radius: 20px;
    padding: 20px;
    margin-bottom: 25px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.05);
    transition: all 0.3s;
}

.chart-container:hover {
    transform: translateY(-3px);
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #f0f0f0;
}

.chart-title {
    font-size: 18px;
    font-weight: 700;
    color: #2c3e50;
    display: flex;
    align-items: center;
    gap: 10px;
}

.chart-title i {
    width: 35px;
    height: 35px;
    background: var(--primary-gradient);
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 16px;
}

/* Tables */
.data-table {
    background: white;
    border-radius: 20px;
    overflow: hidden;
    box-shadow: 0 5px 20px rgba(0,0,0,0.05);
    margin-bottom: 25px;
}

.data-table .table {
    margin-bottom: 0;
}

.data-table .table thead th {
    background: var(--primary-gradient);
    color: white;
    font-weight: 600;
    padding: 15px 20px;
    border: none;
}

.data-table .table tbody tr {
    transition: all 0.3s;
}

.data-table .table tbody tr:hover {
    background: linear-gradient(135deg, rgba(102,126,234,0.05), rgba(118,75,162,0.05));
}

/* Service Tags */
.service-tag {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
}

.service-tag.health { background: #e8f8f5; color: #1abc9c; }
.service-tag.education { background: #ebf5fb; color: #3498db; }
.service-tag.environmental { background: #eafaf1; color: #27ae60; }
.service-tag.livelihood { background: #fef9e7; color: #f39c12; }

/* Summary Cards */
.summary-card {
    background: linear-gradient(135deg, #667eea, #764ba2);
    border-radius: 20px;
    padding: 20px;
    color: white;
    text-align: center;
}

.summary-value {
    font-size: 28px;
    font-weight: 700;
}

.summary-label {
    font-size: 13px;
    opacity: 0.9;
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
        font-size: 28px;
    }
    .filter-btn {
        padding: 6px 12px;
        font-size: 12px;
    }
}
</style>

<div class="reports-header fade-up">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h2><i class="fas fa-chart-line"></i> Analytics & Reports</h2>
            <p>Comprehensive insights and statistics of your archive system</p>
        </div>
        <div class="text-end">
            <div class="badge bg-light text-dark p-2">
                <i class="fas fa-calendar-alt"></i> <?php echo date('F d, Y'); ?>
            </div>
        </div>
    </div>
</div>

<!-- Filter Bar -->
<div class="filter-bar fade-up">
    <form method="GET" class="row align-items-center">
        <div class="col-md-8">
            <div style="display: flex; flex-wrap: wrap; gap: 5px;">
                <a href="?filter=today" class="filter-btn <?php echo $filter_type == 'today' ? 'active' : ''; ?>">
                    <i class="fas fa-sun"></i> Today
                </a>
                <a href="?filter=yesterday" class="filter-btn <?php echo $filter_type == 'yesterday' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-day"></i> Yesterday
                </a>
                <a href="?filter=this_week" class="filter-btn <?php echo $filter_type == 'this_week' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-week"></i> This Week
                </a>
                <a href="?filter=this_month" class="filter-btn <?php echo $filter_type == 'this_month' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-alt"></i> This Month
                </a>
                <a href="?filter=last_month" class="filter-btn <?php echo $filter_type == 'last_month' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-minus"></i> Last Month
                </a>
                <a href="?filter=this_year" class="filter-btn <?php echo $filter_type == 'this_year' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-year"></i> This Year
                </a>
                <a href="?filter=last_year" class="filter-btn <?php echo $filter_type == 'last_year' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar"></i> Last Year
                </a>
                
            </div>
        </div>
        <?php if($filter_type == 'custom'): ?>
        <div class="col-md-4 mt-2 mt-md-0">
            <div class="row g-2">
                <div class="col-md-5">
                    <input type="date" name="start_date" class="form-control" value="<?php echo $start_date; ?>">
                </div>
                <div class="col-md-5">
                    <input type="date" name="end_date" class="form-control" value="<?php echo $end_date; ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" name="filter" value="custom" class="btn btn-primary w-100">
                        <i class="fas fa-check"></i>
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </form>
    <div class="mt-2 text-muted">
        <i class="fas fa-calendar-alt"></i> Report Period: <?php echo date('F d, Y', strtotime($start_date)); ?> - <?php echo date('F d, Y', strtotime($end_date)); ?>
    </div>
</div>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card-premium fade-up">
        <div class="stat-icon blue">
            <i class="fas fa-file-alt"></i>
        </div><div class="stat-value"><?php echo number_format($total_files); ?></div>
        <div class="stat-label">Total Documents</div>
        <div class="stat-trend <?php echo $growth >= 0 ? 'trend-up' : 'trend-down'; ?>">
            <i class="fas fa-arrow-<?php echo $growth >= 0 ? 'up' : 'down'; ?>"></i> <?php echo abs($growth); ?>%
        </div>
    </div>
    
    <div class="stat-card-premium fade-up">
        <div class="stat-icon green">
            <i class="fas fa-users"></i>
        </div>
        <div class="stat-value"><?php echo number_format($total_beneficiaries); ?></div>
        <div class="stat-label">Total Beneficiaries</div>
    </div>
    
    <div class="stat-card-premium fade-up">
        <div class="stat-icon orange">
            <i class="fas fa-folder"></i>
        </div>
        <div class="stat-value"><?php echo number_format($total_folders); ?></div>
        <div class="stat-label">Total Folders</div>
    </div>
    
    <div class="stat-card-premium fade-up">
        <div class="stat-icon purple">
            <i class="fas fa-archive"></i>
        </div>
        <div class="stat-value"><?php echo number_format($total_archived); ?></div>
        <div class="stat-label">Archived Records</div>
    </div>
</div>

<!-- Charts Row -->
<div class="row">
    <div class="col-lg-7">
        <div class="chart-container fade-up">
            <div class="chart-header">
                <div class="chart-title">
                    <i class="fas fa-chart-line"></i>
                    Document Upload Trend
                </div>
                <span class="badge bg-primary rounded-pill">Last <?php echo count($monthly_stats); ?> months</span>
            </div>
            <canvas id="trendChart" height="280"></canvas>
            <?php if(count($monthly_stats) > 0): ?>
            <div class="table-responsive mt-3">
                <table class="table table-sm">
                    <thead>
                        <tr><th>Month</th><th>Documents</th><th>Beneficiaries</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach($monthly_stats as $m): ?>
                        <tr>
                            <td><?php echo $m['month']; ?></td>
                            <td><span class="badge bg-primary"><?php echo $m['count']; ?></span></td>
                            <td><?php echo number_format($m['beneficiaries'] ?? 0); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="alert alert-info">No data available for this period</div>
            <?php endif; ?>
        </div>
    </div>
    

<!-- Service Type Breakdown -->
<?php if(count($service_stats) > 0): ?>
<div class="data-table fade-up">
    <div style="padding: 20px 25px 0 25px;">
        <h5 class="mb-0"><i class="fas fa-tags text-primary"></i> Service Type Breakdown</h5>
        <hr class="my-3">
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>Type of Community Service</th>
                    <th>Number of Documents</th>
                    <th>Percentage</th>
                    <th>Beneficiaries Served</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $total_count = array_sum(array_column($service_stats, 'count'));
                foreach($service_stats as $service):
                    $percent = $total_count > 0 ? round(($service['count'] / $total_count) * 100, 1) : 0;
                    $service_class = '';
                    if(strpos(strtolower($service['type_of_community_service']), 'health') !== false) $service_class = 'health';
                    elseif(strpos(strtolower($service['type_of_community_service']), 'education') !== false) $service_class = 'education';
                    elseif(strpos(strtolower($service['type_of_community_service']), 'environment') !== false) $service_class = 'environmental';
                    else $service_class = 'livelihood';
                ?>
                <tr>
                    <td><span class="service-tag <?php echo $service_class; ?>"><?php echo htmlspecialchars($service['type_of_community_service']); ?></span></td>
                    <td><strong><?php echo $service['count']; ?></strong></td>
                    <td>
                        <div class="progress" style="height: 8px; width: 150px;">
                            <div class="progress-bar bg-primary" style="width: <?php echo $percent; ?>%"></div>
                        </div>
                        <small><?php echo $percent; ?>%</small>
                    </td>
                    <td><?php echo number_format($service['beneficiaries'] ?? 0); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>


<!-- Recent Files -->
<?php if(count($recent_files) > 0): ?>
<div class="data-table fade-up">
    <div style="padding: 20px 25px 0 25px;">
        <h5 class="mb-0"><i class="fas fa-clock text-primary"></i> Recently Added Documents</h5>
        <hr class="my-3">
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead>
                <tr>
                    <th>File Name</th>
                    <th>Title of Program</th>
                    <th>Date Conducted</th>
                    <th>Beneficiaries</th>
                    <th>Date Added</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($recent_files as $file): ?>
                <tr>
                    <td><i class="fas fa-file-alt me-2 text-muted"></i><?php echo htmlspecialchars($file['file_name']); ?>.<strong><?php echo $file['file_extension']; ?></strong></td>
                    <td><?php echo htmlspecialchars(substr($file['title_of_program'], 0, 35)) . (strlen($file['title_of_program']) > 35 ? '...' : ''); ?></td>
                    <td><?php echo date('M d, Y', strtotime($file['date_conducted'])); ?></td>
                    <td><span class="badge bg-info"><?php echo number_format($file['no_of_beneficiaries']); ?></span></td>
                    <td><small><?php echo date('M d, Y', strtotime($file['created_at'])); ?></small></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- System Summary -->
<div class="row fade-up">
    <div class="col-md-4">
        <div class="summary-card">
            <div class="summary-value"><?php echo $total_files + $total_archived; ?></div>
            <div class="summary-label">Total Records</div>
        </div>
    </div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// Intersection Observer for scroll animations
const observerOptions = { threshold: 0.1, rootMargin: '0px 0px -50px 0px' };
const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) entry.target.classList.add('visible');
    });
}, observerOptions);
document.querySelectorAll('.fade-up').forEach(el => observer.observe(el));

// Trend Chart
<?php if(count($monthly_stats) > 0): ?>
const trendLabels = [<?php foreach($monthly_stats as $m) echo "'" . $m['month'] . "',"; ?>];
const trendData = [<?php foreach($monthly_stats as $m) echo $m['count'] . ","; ?>];

new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
        labels: trendLabels,
        datasets: [{
            label: 'Documents Uploaded',
            data: trendData,
            borderColor: '#667eea',
            backgroundColor: 'rgba(102, 126, 234, 0.1)',
            borderWidth: 3,
            tension: 0.4,
            fill: true,
            pointBackgroundColor: '#667eea',
            pointBorderColor: 'white',
            pointRadius: 5,
            pointHoverRadius: 8,
            pointBorderWidth: 2
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { position: 'top', labels: { usePointStyle: true } },
            tooltip: { backgroundColor: '#2c3e50', titleColor: '#fff', bodyColor: '#ddd' }
        },
        scales: {
            y: { beginAtZero: true, grid: { color: '#ecf0f1' }, title: { display: true, text: 'Number of Documents', font: { weight: 'bold' } } },
            x: { grid: { display: false }, title: { display: true, text: 'Month', font: { weight: 'bold' } } }
        },
        animation: { duration: 1000 }
    }
});
<?php endif; ?>

// Service Distribution Chart
<?php if(count($service_stats) > 0): ?>
const serviceLabels = [<?php foreach($service_stats as $s) echo "'" . addslashes($s['type_of_community_service']) . "',"; ?>];
const serviceData = [<?php foreach($service_stats as $s) echo $s['count'] . ","; ?>];

new Chart(document.getElementById('serviceChart'), {
    type: 'doughnut',
    data: {
        labels: serviceLabels,
        datasets: [{
            data: serviceData,
            backgroundColor: ['#3498db', '#e74c3c', '#f39c12', '#27ae60', '#9b59b6', '#1abc9c', '#e67e22', '#2ecc71', '#e84393', '#3498db'],
            borderWidth: 0,
            hoverOffset: 15
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { position: 'bottom', labels: { font: { size: 11, weight: 'bold' }, usePointStyle: true } },
            tooltip: { backgroundColor: '#2c3e50', callbacks: { label: (ctx) => `${ctx.label}: ${ctx.raw} documents` } }
        },
        cutout: '60%',
        animation: { animateScale: true, animateRotate: true }
    }
});
<?php endif; ?>
</script>

<?php include 'includes/footer.php'; ?>