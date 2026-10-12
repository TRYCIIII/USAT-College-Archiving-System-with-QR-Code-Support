<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/database.php';
require_once 'includes/auth.php';

if (!isLoggedIn()) {
    header("Location: index.php");
    exit();
}

$file_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($file_id <= 0) {
    die("Invalid file ID");
}

try {
    // Check if PDO connection exists
    if (!isset($pdo) || !$pdo) {
        die("Database connection failed");
    }
    
    $stmt = $pdo->prepare("SELECT f.*, u.full_name as uploader_name FROM files f LEFT JOIN users u ON f.uploaded_by = u.id WHERE f.id = ?");
    $stmt->execute([$file_id]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$file) { 
        die("File not found in database"); 
    }
    
    // Update view count
    $updateStmt = $pdo->prepare("UPDATE files SET view_count = COALESCE(view_count, 0) + 1 WHERE id = ?");
    $updateStmt->execute([$file_id]);
    
    // ============ FIXED FILE PATH HANDLING ============
    // Try multiple possible paths to find the file
    $full_file_path = null;
    $possible_paths = [];
    
    // Path as stored in database (absolute or relative)
    $stored_path = $file['file_path'];
    
    // Option 1: Path stored as is (might be absolute or relative)
    if (file_exists($stored_path)) {
        $full_file_path = $stored_path;
    }
    
    // Option 2: Path relative to script directory
    $relative_path = __DIR__ . '/' . ltrim($stored_path, '/');
    if (!$full_file_path && file_exists($relative_path)) {
        $full_file_path = $relative_path;
    }
    
    // Option 3: Path inside uploads folder (stripping any extra paths)
    $basename = basename($stored_path);
    $uploads_path = __DIR__ . '/uploads/' . $basename;
    if (!$full_file_path && file_exists($uploads_path)) {
        $full_file_path = $uploads_path;
    }
    
    // Option 4: Search in uploads folder for matching filename
    if (!$full_file_path) {
        $search_pattern = __DIR__ . '/uploads/*.' . $file['file_extension'];
        $found_files = glob($search_pattern);
        foreach ($found_files as $found) {
            if (strpos($found, $file['file_name']) !== false) {
                $full_file_path = $found;
                break;
            }
        }
    }
    
    // Option 5: Search for file by ID in filename
    if (!$full_file_path) {
        $search_pattern = __DIR__ . '/uploads/*' . $file_id . '*';
        $found_files = glob($search_pattern);
        if (!empty($found_files)) {
            $full_file_path = $found_files[0];
        }
    }
    
    // If file still not found, show debug info
    if (!$full_file_path || !file_exists($full_file_path)) {
        // For debugging - show where we looked
        $debug_info = "<strong>Debug Information:</strong><br>";
        $debug_info .= "Stored path in DB: " . htmlspecialchars($stored_path) . "<br>";
        $debug_info .= "File ID: $file_id<br>";
        $debug_info .= "File Name: " . htmlspecialchars($file['file_name']) . "<br>";
        $debug_info .= "Extension: " . htmlspecialchars($file['file_extension']) . "<br>";
        $debug_info .= "Script directory: " . __DIR__ . "<br>";
        $debug_info .= "Expected filename pattern: " . $file['file_name'] . "." . $file['file_extension'] . "<br>";
        
        // List files in uploads directory
        $upload_dir = __DIR__ . '/uploads/';
        if (file_exists($upload_dir)) {
            $debug_info .= "<br>Files in uploads directory:<br>";
            $files = scandir($upload_dir);
            foreach ($files as $f) {
                if ($f != '.' && $f != '..') {
                    $debug_info .= "- " . htmlspecialchars($f) . "<br>";
                }
            }
        } else {
            $debug_info .= "<br>Uploads directory does NOT exist at: " . $upload_dir . "<br>";
        }
        
        die("File not found on server.<br><br>" . $debug_info);
    }
    
    $extension = strtolower($file['file_extension']);
    $file_name = $file['file_name'] . '.' . $file['file_extension'];
    
    // Define viewable file types
    $viewable_types = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'txt'];
    
    // For non-viewable files, force download
    if (!in_array($extension, $viewable_types)) {
        $mime_types = [
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'
        ];
        $mime_type = isset($mime_types[$extension]) ? $mime_types[$extension] : 'application/octet-stream';
        
        header('Content-Type: ' . $mime_type);
        header('Content-Disposition: attachment; filename="' . $file_name . '"');
        header('Content-Length: ' . filesize($full_file_path));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');
        
        // Clear output buffer
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        readfile($full_file_path);
        exit();
    }
    
    // Generate QR code for print if not exists
    if (empty($file['qr_code_path']) || !file_exists($file['qr_code_path'])) {
        $qr_dir = __DIR__ . '/qrcodes/';
        if (!file_exists($qr_dir)) {
            mkdir($qr_dir, 0777, true);
        }
        
        $qr_filename = 'qr_' . $file_id . '_' . time() . '.png';
        $qr_path = 'qrcodes/' . $qr_filename;
        $full_qr_path = __DIR__ . '/' . $qr_path;
        
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'];
        $script_dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $file_url = $protocol . $host . $script_dir . '/view_file.php?id=' . $file_id;
        
        $qr_content = false;
        $qr_urls = [
            "https://quickchart.io/qr?text=" . urlencode($file_url) . "&size=300",
            "https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=" . urlencode($file_url),
            "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($file_url)
        ];
        
        foreach ($qr_urls as $qr_api_url) {
            $qr_content = @file_get_contents($qr_api_url);
            if ($qr_content !== false) break;
        }
        
        if ($qr_content !== false && file_put_contents($full_qr_path, $qr_content)) {
            $updateStmt = $pdo->prepare("UPDATE files SET qr_code_path = ? WHERE id = ?");
            $updateStmt->execute([$qr_path, $file_id]);
            $file['qr_code_path'] = $qr_path;
        }
    }
    
    // Get file icon
    $ext_icon = 'fa-file';
    if ($extension == 'pdf') { 
        $ext_icon = 'fa-file-pdf'; 
    } elseif (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])) { 
        $ext_icon = 'fa-file-image'; 
    } elseif ($extension == 'txt') { 
        $ext_icon = 'fa-file-alt'; 
    }
    
    // Get QR code for print
    $qr_code_src = '';
    if (!empty($file['qr_code_path']) && file_exists(__DIR__ . '/' . $file['qr_code_path'])) {
        $qr_code_src = $file['qr_code_path'];
    } else {
        // Generate on the fly
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? "https://" : "http://";
        $host = $_SERVER['HTTP_HOST'];
        $script_dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $file_url = $protocol . $host . $script_dir . '/view_file.php?id=' . $file_id;
        $qr_code_src = "https://quickchart.io/qr?text=" . urlencode($file_url) . "&size=200";
    }
    
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Viewing: <?php echo htmlspecialchars($file_name); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Inter', sans-serif;
            background: #1a1a2e;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        .viewer-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .viewer-header-left {
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        
        .viewer-header h1 {
            font-size: 18px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .viewer-header h1 i { font-size: 24px; }
        
        .control-number-badge {
            background: rgba(255,255,255,0.2);
            color: white;
            padding: 4px 10px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        
        .viewer-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .viewer-btn {
            background: rgba(255,255,255,0.2);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 8px;
            text-decoration: none;
            white-space: nowrap;
        }
        
        .viewer-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: translateY(-2px);
        }
        
        .viewer-container {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: #1a1a2e;
        }
        
        .viewer-wrapper {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.5);
            overflow: hidden;
            max-width: 95%;
            width: 100%;
            max-height: 85vh;
            display: flex;
            flex-direction: column;
        }
        
        .viewer-content {
            overflow: auto;
            padding: 20px;
            background: #f5f5f5;
            flex: 1;
            text-align: center;
        }
        
        .viewer-content img {
            max-width: 100%;
            height: auto;
            display: block;
            margin: 0 auto;
            background: white;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .viewer-content iframe {
            width: 100%;
            height: 70vh;
            border: none;
            background: white;
            border-radius: 4px;
        }
        
        .viewer-content pre {
            background: #2d2d2d;
            color: #f8f8f2;
            padding: 20px;
            border-radius: 8px;
            overflow: auto;
            max-height: 70vh;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            line-height: 1.6;
            text-align: left;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        
        .file-info {
            background: #f8f9fa;
            padding: 15px 20px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .file-info-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #495057;
            font-size: 13px;
        }
        
        .file-info-item i { color: #667eea; width: 20px; }
        
        /* QR CODE FOR PRINT ONLY - Hidden on screen, visible when printing */
        .print-qr-section {
            display: none;
        }
        
        @media print {
            body * {
                visibility: hidden;
            }
            .print-container, .print-container * {
                visibility: visible;
            }
            .print-container {
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                padding: 20px;
                background: white;
            }
            .print-qr-section {
                display: block !important;
                text-align: center;
                margin-top: 30px;
                padding: 20px;
                border: 2px solid #667eea;
                border-radius: 12px;
                page-break-inside: avoid;
            }
            .print-qr-section h3 {
                color: #333;
                margin-bottom: 15px;
                font-size: 16px;
            }
            .print-qr-section img {
                max-width: 180px;
                height: auto;
                margin: 10px auto;
                border: 1px solid #ddd;
                padding: 10px;
                border-radius: 8px;
            }
            .print-qr-section p {
                color: #666;
                font-size: 11px;
                margin-top: 10px;
            }
            .print-header {
                text-align: center;
                margin-bottom: 30px;
                padding-bottom: 15px;
                border-bottom: 2px solid #667eea;
            }
            .print-header h1 {
                color: #333;
                font-size: 20px;
                margin-bottom: 5px;
            }
            .print-header .control-number {
                color: #667eea;
                font-size: 12px;
                font-family: monospace;
            }
            .print-doc-info {
                margin: 20px 0;
                padding: 15px;
                background: #f8f9fa;
                border-radius: 8px;
            }
            .print-doc-info table {
                width: 100%;
                font-size: 12px;
            }
            .print-doc-info td {
                padding: 5px;
            }
            .print-footer {
                margin-top: 20px;
                text-align: center;
                font-size: 9px;
                color: #999;
                border-top: 1px solid #eee;
                padding-top: 10px;
            }
            .no-print {
                display: none;
            }
            @page {
                size: portrait;
                margin: 0.5in;
            }
        }
        
        .back-btn {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #667eea;
            color: white;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            text-decoration: none;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
            transition: all 0.3s;
            z-index: 100;
        }
        
        .back-btn:hover {
            transform: scale(1.1);
            background: #764ba2;
        }
        
        .error-message {
            text-align: center;
            padding: 40px;
            color: #666;
        }
        
        .error-message i {
            font-size: 60px;
            color: #ef4444;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .viewer-header {
                flex-direction: column;
                gap: 10px;
                text-align: center;
                padding: 15px;
            }
            .viewer-header-left { flex-direction: column; align-items: center; }
            .viewer-header h1 { font-size: 14px; }
            .viewer-btn { padding: 8px 15px; font-size: 12px; }
            .file-info { flex-direction: column; align-items: flex-start; }
            .viewer-container { padding: 10px; }
            .viewer-content { padding: 10px; }
            .viewer-content iframe { height: 50vh; }
            .viewer-content pre { max-height: 50vh; }
        }
    </style>
</head>
<body>
    <div class="viewer-header">
        <div class="viewer-header-left">
            <h1>
                <i class="fas <?php echo $ext_icon; ?>"></i>
                <?php echo htmlspecialchars($file_name); ?>
            </h1>
            <?php if (!empty($file['control_number'])): ?>
                <span class="control-number-badge">
                    <i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($file['control_number']); ?>
                </span>
            <?php endif; ?>
        </div>
        <div class="viewer-actions">
            <a href="download_file.php?id=<?php echo $file_id; ?>" class="viewer-btn">
                <i class="fas fa-download"></i> Download
            </a>
            <button class="viewer-btn" onclick="window.print()">
                <i class="fas fa-print"></i> Print with QR
            </button>
            <a href="files.php?folder=<?php echo urlencode($file['folder_id']); ?>" class="viewer-btn">
                <i class="fas fa-arrow-left"></i> Back to Folder
            </a>
        </div>
    </div>
    
    <div class="viewer-container">
        <div class="viewer-wrapper">
            <div class="viewer-content">
                <?php if ($extension == 'pdf'): ?>
                    <!-- PDF Viewer -->
                    <iframe src="serve_file.php?id=<?php echo $file_id; ?>" allowfullscreen></iframe>
                    
                <?php elseif (in_array($extension, ['jpg', 'jpeg', 'png', 'gif'])): ?>
                    <!-- Image Viewer -->
                    <img src="serve_file.php?id=<?php echo $file_id; ?>" alt="<?php echo htmlspecialchars($file_name); ?>">
                    
                <?php elseif ($extension == 'txt'): ?>
                    <!-- Text Viewer -->
                    <?php
                    $content = file_get_contents($full_file_path);
                    if ($content !== false) {
                        echo '<pre>' . htmlspecialchars($content) . '</pre>';
                    } else {
                        echo '<div class="error-message"><i class="fas fa-exclamation-triangle"></i><p>Unable to read file content</p></div>';
                    }
                    ?>
                    
                <?php else: ?>
                    <!-- Fallback for other file types -->
                    <div class="error-message">
                        <i class="fas fa-file"></i>
                        <h3>File Type Not Viewable</h3>
                        <p>This file type cannot be previewed in the browser.</p>
                        <a href="download_file.php?id=<?php echo $file_id; ?>" style="display: inline-block; margin-top: 20px; padding: 10px 20px; background: #667eea; color: white; text-decoration: none; border-radius: 8px;">
                            <i class="fas fa-download"></i> Download File
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="file-info">
                <div class="file-info-item">
                    <i class="fas fa-file"></i>
                    <span><strong>Type:</strong> <?php echo strtoupper($extension); ?></span>
                </div>
                <div class="file-info-item">
                    <i class="fas fa-weight-hanging"></i>
                    <span><strong>Size:</strong> <?php echo number_format((float)($file['file_size'] ?? 0), 2); ?> KB</span>
                </div>
                <div class="file-info-item">
                    <i class="fas fa-calendar"></i>
                    <span><strong>Uploaded:</strong> <?php echo date('M d, Y h:i A', strtotime($file['created_at'])); ?></span>
                </div>
                <?php if (!empty($file['title_of_program'])): ?>
                    <div class="file-info-item">
                        <i class="fas fa-tag"></i>
                        <span><?php echo htmlspecialchars($file['title_of_program']); ?></span>
                    </div>
                <?php endif; ?>
                <?php if (!empty($file['uploader_name'])): ?>
                    <div class="file-info-item">
                        <i class="fas fa-user"></i>
                        <span><?php echo htmlspecialchars($file['uploader_name']); ?></span>
                    </div>
                <?php endif; ?>
                <div class="file-info-item">
                    <i class="fas fa-eye"></i>
                    <span><strong>Views:</strong> <?php echo (int)($file['view_count'] ?? 0); ?></span>
                </div>
                <div class="file-info-item">
                    <i class="fas fa-download"></i>
                    <span><strong>Downloads:</strong> <?php echo (int)($file['download_count'] ?? 0); ?></span>
                </div>
            </div>
        </div>
    </div>
    
    <!-- PRINT SECTION - Hidden on screen, appears when printing -->
    <div class="print-container" style="display: none;">
        <div class="print-header">
            <h1><?php echo htmlspecialchars($file_name); ?></h1>
            <div class="control-number">Control Number: <?php echo htmlspecialchars($file['control_number']); ?></div>
        </div>
        
        <div class="print-doc-info">
            <table style="width:100%">
                <tr><td width="30%"><strong>Document Title:</strong></td><td><?php echo htmlspecialchars($file['title_of_program'] ?? 'N/A'); ?></td></tr>
                <?php if (!empty($file['date_conducted'])): ?>
                <tr><td><strong>Date Conducted:</strong></td><td><?php echo htmlspecialchars($file['date_conducted']); ?></td></tr>
                <?php endif; ?>
                <?php if (!empty($file['type_of_service'])): ?>
                <tr><td><strong>Type of Service:</strong></td><td><?php echo htmlspecialchars($file['type_of_service']); ?></td></tr>
                <?php endif; ?>
                <?php if (!empty($file['community_served'])): ?>
                <tr><td><strong>Community Served:</strong></td><td><?php echo htmlspecialchars($file['community_served']); ?></td></tr>
                <?php endif; ?>
                <?php if (!empty($file['beneficiaries'])): ?>
                <tr><td><strong>Beneficiaries:</strong></td><td><?php echo htmlspecialchars($file['beneficiaries']); ?></td></tr>
                <?php endif; ?>
                <?php if (!empty($file['description'])): ?>
                <tr><td><strong>Description:</strong></td><td><?php echo nl2br(htmlspecialchars($file['description'])); ?></td></tr>
                <?php endif; ?>
                <tr><td><strong>Uploaded:</strong></td><td><?php echo date('F d, Y h:i A', strtotime($file['created_at'])); ?></td></tr>
            </table>
        </div>
        
        <!-- QR CODE - Only visible in print -->
        <div class="print-qr-section">
            <h3><i class="fas fa-qrcode"></i> Document QR Code</h3>
            <img src="<?php echo $qr_code_src; ?>" alt="QR Code">
            <p>Scan this QR code to access this document digitally</p>
            <p><strong>Control Number:</strong> <?php echo htmlspecialchars($file['control_number']); ?></p>
        </div>
        
        <div class="print-footer">
            <span>USAT Archive System</span>
            <span>Printed: <?php echo date('F d, Y h:i A'); ?></span>
        </div>
    </div>
    
    <a href="files.php?folder=<?php echo urlencode($file['folder_id']); ?>" class="back-btn" title="Back to folder">
        <i class="fas fa-arrow-left"></i>
    </a>
    
    <script>
        // Override print function to show print content
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                window.location.href = 'files.php?folder=<?php echo urlencode($file['folder_id']); ?>';
            }
        });
        
        // Custom print function
        const originalPrint = window.print;
        window.print = function() {
            const printContainer = document.querySelector('.print-container');
            if (printContainer) {
                printContainer.style.display = 'block';
                setTimeout(() => {
                    originalPrint.call(window);
                    setTimeout(() => {
                        printContainer.style.display = 'none';
                    }, 100);
                }, 100);
            } else {
                originalPrint.call(window);
            }
        };
    </script>
</body>
</html>
    <?php
} catch (PDOException $e) {
    error_log("Database error in view_file.php: " . $e->getMessage());
    die("A database error occurred. Please try again later.");
} catch (Exception $e) {
    error_log("General error in view_file.php: " . $e->getMessage());
    die("An error occurred. Please try again later.");
}
?>