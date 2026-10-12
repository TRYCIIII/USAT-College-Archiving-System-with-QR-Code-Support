<?php
// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Include database connection
require_once __DIR__ . '/../config/database.php';

// Create qr_scans table if not exists
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
} catch (PDOException $e) {
    // Table might already exist, ignore
}

// Get total scans count
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM qr_scans");
    $total_scans = $stmt->fetchColumn();
} catch (PDOException $e) {
    $total_scans = 0;
}

// Get today's scans
try {
    $stmt = $pdo->query("SELECT COUNT(*) FROM qr_scans WHERE DATE(scanned_at) = CURDATE()");
    $today_scans = $stmt->fetchColumn();
} catch (PDOException $e) {
    $today_scans = 0;
}

// Get trash count for admin
$trash_count = 0;
if (isset($_SESSION['role']) && $_SESSION['role'] == 'admin') {
    try {
        $table_check = $pdo->query("SHOW TABLES LIKE 'trash_bin'");
        if ($table_check->rowCount() > 0) {
            $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM trash_bin WHERE status = 'deleted'");
            $count_stmt->execute();
            $trash_count = $count_stmt->fetchColumn();
        }
    } catch (PDOException $e) {
        $trash_count = 0;
    }
}

// Handle QR scan result - AUTO DISPLAY
$scanned_file = null;
$scan_message = '';
$scan_error = '';
$auto_display = false;

if (isset($_GET['scan_result']) && !empty($_GET['scan_result'])) {
    $scanned_id = (int)$_GET['scan_result'];
    $auto_display = true;
    
    if ($scanned_id > 0) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ? AND status = 'active'");
            $stmt->execute([$scanned_id]);
            $scanned_file = $stmt->fetch();
            
            if ($scanned_file) {
                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO qr_scans (file_id, scanned_by, ip_address, user_agent, scanned_at) 
                        VALUES (?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([
                        $scanned_id,
                        $_SESSION['user_id'] ?? null,
                        $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
                        $_SERVER['HTTP_USER_AGENT'] ?? ''
                    ]);
                    $scan_message = "✅ QR Code scanned successfully!";
                } catch (PDOException $e) {
                    $scan_message = "✅ Document found!";
                }
            } else {
                $scan_error = "Document not found for the scanned QR code.";
            }
        } catch (PDOException $e) {
            $scan_error = "Error retrieving document.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>USAT Archiving System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- html5-qrcode library -->
    <script src="https://unpkg.com/html5-qrcode"></script>
    <style>
        /* Modern Header Styles */
        .navbar-custom {
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            padding: 12px 0;
            box-shadow: 0 2px 20px rgba(0,0,0,0.3);
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .navbar-custom .navbar-brand {
            color: white;
            font-weight: 700;
            font-size: 1.3rem;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .navbar-custom .navbar-brand .brand-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
        }
        
        .navbar-custom .navbar-brand span {
            color: #667eea;
        }
        
        .navbar-custom .nav-link {
            color: rgba(255,255,255,0.8) !important;
            font-weight: 500;
            padding: 8px 18px !important;
            border-radius: 10px;
            transition: all 0.3s;
            font-size: 14px;
            position: relative;
        }
        
        .navbar-custom .nav-link:hover {
            color: white !important;
            background: rgba(255,255,255,0.1);
            transform: translateY(-2px);
        }
        
        .navbar-custom .nav-link.active {
            color: white !important;
            background: linear-gradient(135deg, #667eea, #764ba2);
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        
        .navbar-custom .nav-link .badge-count {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #10b981;
            color: white;
            font-size: 10px;
            padding: 2px 7px;
            border-radius: 20px;
            font-weight: 600;
        }
        
        .navbar-custom .nav-link .badge-trash {
            background: #ef4444;
        }
        
        .navbar-custom .dropdown-menu {
            background: #1a1a2e;
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 12px;
            padding: 8px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.5);
        }
        
        .navbar-custom .dropdown-item {
            color: rgba(255,255,255,0.8);
            padding: 10px 16px;
            border-radius: 8px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .navbar-custom .dropdown-item:hover {
            background: rgba(255,255,255,0.1);
            color: white;
        }
        
        .navbar-custom .dropdown-item i {
            width: 20px;
            color: #667eea;
        }
        
        .navbar-custom .dropdown-divider {
            border-color: rgba(255,255,255,0.1);
        }
        
        .navbar-custom .user-info {
            display: flex;
            align-items: center;
            gap: 12px;
            color: white;
            padding: 5px 15px 5px 5px;
            border-radius: 50px;
            background: rgba(255,255,255,0.08);
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .navbar-custom .user-info:hover {
            background: rgba(255,255,255,0.15);
        }
        
        .navbar-custom .user-avatar {
            width: 35px;
            height: 35px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea, #764ba2);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 700;
            font-size: 14px;
        }
        
        .navbar-custom .user-name {
            font-size: 13px;
            font-weight: 500;
        }
        
        .navbar-custom .user-role {
            font-size: 10px;
            opacity: 0.6;
        }
        
        /* QR Scanner Button in Nav */
        .btn-scan-nav {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            border: none;
            border-radius: 50px;
            padding: 8px 20px;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-scan-nav:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4);
            color: white;
        }
        
        .btn-scan-nav i {
            font-size: 16px;
        }
        
        /* Scanner Modal - AUTO DISPLAY */
        .scanner-modal .modal-content {
            border-radius: 20px;
            background: white;
        }
        
        .scanner-modal .modal-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-radius: 20px 20px 0 0;
            border: none;
        }
        
        .scanner-modal .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }
        
        #reader {
            width: 100%;
            max-width: 500px;
            margin: 0 auto;
            border: 3px dashed #e2e8f0;
            border-radius: 16px;
            overflow: hidden;
            background: #f8fafc;
        }
        
        #reader video {
            border-radius: 12px;
        }
        
        .scan-status {
            padding: 12px;
            font-size: 14px;
            color: #64748b;
            text-align: center;
        }
        
        .scan-status i {
            margin-right: 8px;
        }
        
        .scan-status.success {
            color: #10b981;
        }
        
        .scan-status.error {
            color: #ef4444;
        }
        
        .scan-status.info {
            color: #3b82f6;
        }
        
        /* AUTO DISPLAY - Scanned Result Card */
        .scanned-result {
            background: #f0fdf4;
            border: 2px solid #10b981;
            border-radius: 12px;
            padding: 15px;
            margin-top: 15px;
            animation: slideDown 0.5s ease;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .scanned-result .file-info {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .scanned-result .file-info .file-icon {
            width: 50px;
            height: 50px;
            background: rgba(102, 126, 234, 0.1);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #667eea;
        }
        
        .scanned-result .file-details {
            flex: 1;
        }
        
        .scanned-result .file-details h6 {
            margin-bottom: 2px;
            font-weight: 700;
            color: #1a1a2e;
        }
        
        .scanned-result .file-details small {
            color: #64748b;
            font-size: 12px;
        }
        
        .scanned-result .file-details .badge-info {
            background: #dbeafe;
            color: #2563eb;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .scanned-result .action-btns {
            display: flex;
            gap: 8px;
        }
        
        .scanned-result .action-btns .btn {
            border-radius: 8px;
            padding: 6px 14px;
            font-size: 12px;
            font-weight: 600;
        }
        
        /* Auto Display Alert */
        .auto-display-alert {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            padding: 12px 20px;
            border-radius: 10px;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: slideDown 0.5s ease;
        }
        
        /* Mobile Responsive */
        @media (max-width: 992px) {
            .navbar-custom .navbar-collapse {
                background: #1a1a2e;
                padding: 15px;
                border-radius: 12px;
                margin-top: 10px;
                border: 1px solid rgba(255,255,255,0.1);
            }
            
            .navbar-custom .nav-link {
                padding: 10px 15px !important;
            }
            
            .btn-scan-nav {
                width: 100%;
                justify-content: center;
                margin-top: 5px;
            }
        }
        
        @media (max-width: 768px) {
            .navbar-custom .user-name {
                display: none;
            }
            .scanned-result .file-info {
                flex-direction: column;
                text-align: center;
            }
            .scanned-result .action-btns {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <!-- AUTO DISPLAY - Show scanned result immediately on page load -->
    <?php if ($auto_display && $scanned_file): ?>
    <div style="position: fixed; top: 80px; right: 20px; z-index: 9999; max-width: 400px; width: 100%; animation: slideDown 0.5s ease;" id="autoDisplayCard">
        <div style="background: white; border-radius: 16px; padding: 20px; box-shadow: 0 10px 40px rgba(0,0,0,0.2); border-left: 5px solid #10b981;">
            <div style="display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;">
                <div style="display: flex; align-items: center; gap: 10px;">
                    <div style="width: 40px; height: 40px; background: #dcfce7; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #10b981; font-size: 20px;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div>
                        <div style="font-weight: 700; color: #1a1a2e; font-size: 14px;">QR Code Scanned!</div>
                        <div style="font-size: 11px; color: #10b981;"><?php echo $scan_message; ?></div>
                    </div>
                </div>
                <button onclick="document.getElementById('autoDisplayCard').style.display='none'" style="background: none; border: none; color: #94a3b8; cursor: pointer; font-size: 16px;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div style="display: flex; gap: 15px; align-items: center; padding: 10px 0; border-top: 1px solid #f1f5f9;">
                <div style="width: 50px; height: 50px; background: rgba(102,126,234,0.1); border-radius: 12px; display: flex; align-items: center; justify-content: center; font-size: 24px; color: #667eea;">
                    <i class="fas <?php echo getFileIcon($scanned_file['file_extension'] ?? ''); ?>"></i>
                </div>
                <div style="flex: 1;">
                    <div style="font-weight: 600; color: #1a1a2e; font-size: 14px;"><?php echo htmlspecialchars($scanned_file['file_name']); ?></div>
                    <div style="font-size: 12px; color: #64748b;">
                        <span class="badge-info"><?php echo htmlspecialchars($scanned_file['control_number'] ?? 'N/A'); ?></span>
                        <span style="margin-left: 8px;"><?php echo date('M d, Y', strtotime($scanned_file['created_at'])); ?></span>
                    </div>
                </div>
            </div>
            
            <div style="display: flex; gap: 8px; margin-top: 10px; padding-top: 10px; border-top: 1px solid #f1f5f9;">
                <a href="view_document.php?id=<?php echo $scanned_file['id']; ?>" target="_blank" class="btn btn-primary" style="flex: 1; background: linear-gradient(135deg, #667eea, #764ba2); border: none; border-radius: 8px; padding: 6px 14px; font-size: 12px; font-weight: 600; text-align: center; color: white; text-decoration: none;">
                    <i class="fas fa-eye"></i> View
                </a>
                <a href="download_file.php?id=<?php echo $scanned_file['id']; ?>" class="btn btn-success" style="flex: 1; background: linear-gradient(135deg, #10b981, #059669); border: none; border-radius: 8px; padding: 6px 14px; font-size: 12px; font-weight: 600; text-align: center; color: white; text-decoration: none;">
                    <i class="fas fa-download"></i> Download
                </a>
                <button onclick="document.getElementById('autoDisplayCard').style.display='none'" class="btn btn-secondary" style="background: #e2e8f0; border: none; border-radius: 8px; padding: 6px 14px; font-size: 12px; font-weight: 600; color: #475569;">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- QR Scanner Modal -->
    <div class="modal fade scanner-modal" id="qrScannerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-qrcode"></i> QR Code Scanner
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" onclick="stopScanner()"></button>
                </div>
                <div class="modal-body" style="padding: 25px;">
                    <div id="reader"></div>
                    <div id="scanStatus" class="scan-status info">
                        <i class="fas fa-info-circle"></i> Click "Start Scanner" to begin
                    </div>
                    <div id="scanResult" style="display: none;"></div>
                </div>
                <div class="modal-footer">
                    <button id="startScannerBtn" class="btn btn-success" onclick="startScanner()">
                        <i class="fas fa-play"></i> Start Scanner
                    </button>
                    <button id="stopScannerBtn" class="btn btn-danger" onclick="stopScanner()" style="display: none;">
                        <i class="fas fa-stop"></i> Stop
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" onclick="stopScanner()">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-custom">
        <div class="container-fluid px-4">
            <a class="navbar-brand" href="dashboard.php">
                <div class="brand-icon">
                    <i class="fas fa-archive"></i>
                </div>
                USAT <span>Archiving</span>
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarMain">
                <ul class="navbar-nav ms-auto align-items-lg-center gap-1">
                    <!-- Dashboard -->
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'active' : ''; ?>" href="dashboard.php">
                            <i class="fas fa-th-large"></i> Dashboard
                        </a>
                    </li>
                    
                    <!-- QR Scanner -->
                    <li class="nav-item">
                        <button class="btn-scan-nav" onclick="openScanner()">
                            <i class="fas fa-qrcode"></i> Scan QR
                            <?php if ($today_scans > 0): ?>
                                <span class="badge-count"><?php echo $today_scans; ?></span>
                            <?php endif; ?>
                        </button>
                    </li>
                    
                    <!-- File Manager -->
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'files.php' ? 'active' : ''; ?>" href="files.php">
                            <i class="fas fa-folder-open"></i> Files
                        </a>
                    </li>
                    
                    <!-- Reports -->
                    <li class="nav-item">
                        <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'active' : ''; ?>" href="reports.php">
                            <i class="fas fa-chart-bar"></i> Reports
                        </a>
                    </li>
                    
                    <?php if(isAdmin()): ?>
                    <!-- Admin Dropdown -->
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="adminDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-cog"></i> Admin
                        </a>
                        <ul class="dropdown-menu" aria-labelledby="adminDropdown">
                            <li><a class="dropdown-item" href="manage_users.php"><i class="fas fa-users-cog"></i> Manage Users</a></li>
                            <li><a class="dropdown-item" href="activity_log.php"><i class="fas fa-history"></i> Activity Log</a></li>
                            <li><a class="dropdown-item" href="scan_history.php"><i class="fas fa-qrcode"></i> Scan History <span class="badge bg-primary ms-2"><?php echo $total_scans; ?></span></a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="trash_bin.php"><i class="fas fa-trash-alt"></i> Trash Bin <?php if($trash_count > 0): ?><span class="badge bg-danger ms-2"><?php echo $trash_count; ?></span><?php endif; ?></a></li>
                        </ul>
                    </li>
                    <?php endif; ?>
                    
                    <!-- Change Password -->
                    <li class="nav-item">
                        <a class="nav-link" href="change_password.php">
                            <i class="fas fa-key"></i>
                        </a>
                    </li>
                    
                    <!-- User Profile -->
                    <li class="nav-item dropdown">
                        <a class="nav-link" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                            <div class="user-info">
                                <div class="user-avatar">
                                    <?php echo strtoupper(substr($_SESSION['full_name'] ?? 'U', 0, 1)); ?>
                                </div>
                                <div>
                                    <div class="user-name"><?php echo htmlspecialchars($_SESSION['full_name'] ?? 'User'); ?></div>
                                    <div class="user-role"><?php echo ucfirst($_SESSION['role'] ?? 'staff'); ?></div>
                                </div>
                            </div>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="userDropdown">
                            <li><a class="dropdown-item" href="profile.php"><i class="fas fa-user"></i> Profile</a></li>
                            <li><a class="dropdown-item" href="change_password.php"><i class="fas fa-key"></i> Change Password</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <script>
        // =============================================
        // QR CODE SCANNER - AUTO DISPLAY
        // =============================================
        let html5QrCode = null;
        let isScanning = false;
        let scannerModal = null;

        // Initialize modal when document is ready
        document.addEventListener('DOMContentLoaded', function() {
            scannerModal = new bootstrap.Modal(document.getElementById('qrScannerModal'));
            
            // Auto hide auto display after 10 seconds
            setTimeout(function() {
                const autoDisplay = document.getElementById('autoDisplayCard');
                if (autoDisplay) {
                    autoDisplay.style.opacity = '0';
                    autoDisplay.style.transition = 'opacity 0.5s ease';
                    setTimeout(function() {
                        autoDisplay.style.display = 'none';
                    }, 500);
                }
            }, 10000);
        });

        function openScanner() {
            if (!scannerModal) {
                scannerModal = new bootstrap.Modal(document.getElementById('qrScannerModal'));
            }
            scannerModal.show();
            // Reset scanner state
            document.getElementById('scanResult').style.display = 'none';
            document.getElementById('scanResult').innerHTML = '';
            document.getElementById('scanStatus').innerHTML = '<i class="fas fa-info-circle"></i> Click "Start Scanner" to begin scanning';
            document.getElementById('scanStatus').className = 'scan-status info';
            document.getElementById('startScannerBtn').style.display = 'inline-block';
            document.getElementById('stopScannerBtn').style.display = 'none';
        }

        function startScanner() {
            const readerElement = document.getElementById('reader');
            const startBtn = document.getElementById('startScannerBtn');
            const stopBtn = document.getElementById('stopScannerBtn');
            const scanStatus = document.getElementById('scanStatus');
            
            if (isScanning) {
                scanStatus.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Scanner is already running...';
                return;
            }
            
            if (typeof Html5Qrcode === 'undefined') {
                scanStatus.innerHTML = '<i class="fas fa-exclamation-triangle" style="color: #e74c3c;"></i> QR Code library not loaded. Please refresh the page.';
                scanStatus.className = 'scan-status error';
                return;
            }
            
            startBtn.style.display = 'none';
            stopBtn.style.display = 'inline-block';
            scanStatus.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Accessing camera... Please allow camera access.';
            scanStatus.className = 'scan-status info';
            
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                scanStatus.innerHTML = '<i class="fas fa-exclamation-triangle" style="color: #e74c3c;"></i> Camera not supported on this device.';
                scanStatus.className = 'scan-status error';
                return;
            }
            
            html5QrCode = new Html5Qrcode("reader");
            
            const config = {
                fps: 10,
                qrbox: { width: 250, height: 250 },
                aspectRatio: 1.0
            };
            
            html5QrCode.start(
                { facingMode: "environment" },
                config,
                onScanSuccess,
                onScanError
            ).then(() => {
                isScanning = true;
                scanStatus.innerHTML = '<i class="fas fa-check-circle" style="color: #10b981;"></i> Camera active. Point at a QR code to scan.';
                scanStatus.className = 'scan-status success';
            }).catch((err) => {
                console.error("Scanner error:", err);
                scanStatus.innerHTML = '<i class="fas fa-exclamation-triangle" style="color: #e74c3c;"></i> ' + err.message;
                scanStatus.className = 'scan-status error';
                resetScannerUI();
            });
        }

        function onScanSuccess(decodedText, decodedResult) {
            console.log("Scanned: " + decodedText);
            
            let fileId = null;
            
            if (decodedText.includes('view_document.php?id=')) {
                const matches = decodedText.match(/id=(\d+)/);
                if (matches && matches[1]) {
                    fileId = matches[1];
                }
            } else if (decodedText.includes('id=')) {
                const matches = decodedText.match(/id=(\d+)/);
                if (matches && matches[1]) {
                    fileId = matches[1];
                }
            } else if (!isNaN(decodedText) && decodedText.length > 0) {
                fileId = decodedText;
            }
            
            if (fileId) {
                // Stop scanner
                stopScanner();
                
                // Show loading
                const scanStatus = document.getElementById('scanStatus');
                scanStatus.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading document...';
                scanStatus.className = 'scan-status info';
                
                // Redirect to same page with scan_result parameter - AUTO DISPLAY
                window.location.href = window.location.pathname + '?scan_result=' + fileId;
            } else {
                if (decodedText.startsWith('http://') || decodedText.startsWith('https://')) {
                    window.open(decodedText, '_blank');
                    const scanStatus = document.getElementById('scanStatus');
                    scanStatus.innerHTML = '<i class="fas fa-external-link-alt"></i> Opened link: ' + decodedText;
                    scanStatus.className = 'scan-status info';
                } else {
                    const scanStatus = document.getElementById('scanStatus');
                    scanStatus.innerHTML = '<i class="fas fa-exclamation-triangle" style="color: #e74c3c;"></i> Invalid QR code format. Please scan a valid document QR code.';
                    scanStatus.className = 'scan-status error';
                }
            }
        }

        function onScanError(error) {
            // Silent fail
        }

        function stopScanner() {
            if (html5QrCode && isScanning) {
                html5QrCode.stop().then(() => {
                    isScanning = false;
                    resetScannerUI();
                    const scanStatus = document.getElementById('scanStatus');
                    scanStatus.innerHTML = '<i class="fas fa-info-circle"></i> Scanner stopped. Click "Start Scanner" to scan again.';
                    scanStatus.className = 'scan-status info';
                }).catch((err) => {
                    console.error("Error stopping scanner:", err);
                    resetScannerUI();
                });
            } else {
                resetScannerUI();
            }
        }

        function resetScannerUI() {
            const startBtn = document.getElementById('startScannerBtn');
            const stopBtn = document.getElementById('stopScannerBtn');
            
            startBtn.style.display = 'inline-block';
            stopBtn.style.display = 'none';
            isScanning = false;
        }

        // Clean up on page unload
        window.addEventListener('beforeunload', function() {
            if (html5QrCode && isScanning) {
                html5QrCode.stop().catch(() => {});
            }
        });

        // Close scanner when modal is hidden
        document.addEventListener('hidden.bs.modal', function(e) {
            if (e.target.id === 'qrScannerModal') {
                stopScanner();
            }
        });
    </script>
</body>
</html>

<?php
function getFileIcon($ext) {
    $icons = [
        'pdf' => 'fa-file-pdf', 'doc' => 'fa-file-word', 'docx' => 'fa-file-word',
        'xls' => 'fa-file-excel', 'xlsx' => 'fa-file-excel', 'ppt' => 'fa-file-powerpoint',
        'pptx' => 'fa-file-powerpoint', 'jpg' => 'fa-file-image', 'jpeg' => 'fa-file-image',
        'png' => 'fa-file-image', 'txt' => 'fa-file-alt'
    ];
    return $icons[$ext] ?? 'fa-file';
}
?>