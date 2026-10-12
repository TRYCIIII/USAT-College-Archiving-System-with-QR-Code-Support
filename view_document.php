<?php
// view_document.php — Document viewer + JSON API for QR auto-view overlay
// IMPORTANT: JSON block MUST run before any HTML output.

error_reporting(E_ALL);
ini_set('display_errors', 0);  // Don't output HTML errors into JSON
ini_set('log_errors', 1);

require_once __DIR__ . '/config/database.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isJson = isset($_GET['format']) && $_GET['format'] === 'json';

// ============================================
// VALIDATE
// ============================================
if ($id <= 0) {
    if ($isJson) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Invalid document ID']);
        exit;
    }
    die('Invalid document reference');
}

// ============================================
// FETCH DOCUMENT
// ============================================
try {
    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ? AND (status IS NULL OR status = 'active')");
    $stmt->execute([$id]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        if ($isJson) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Document not found']);
            exit;
        }
        die('Document not found or has been removed.');
    }
} catch (PDOException $e) {
    if ($isJson) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Database error']);
        exit;
    }
    die('Database error: ' . $e->getMessage());
}

// ============================================
// RESOLVE FILE PATH (used by both JSON + HTML)
// ============================================
$file_exists = false;
$file_url = '';

if (!empty($doc['file_path'])) {
    $original = $doc['file_path'];

    $candidates = [
        $original,
        __DIR__ . '/' . ltrim($original, '/\\'),
        __DIR__ . '/uploads/' . basename($original),
        __DIR__ . '/../uploads/' . basename($original),
        'uploads/' . basename($original),
        __DIR__ . '/uploads/files/' . basename($original),
        __DIR__ . '/../uploads/files/' . basename($original),
    ];

    $resolved = null;
    foreach ($candidates as $p) {
        if (!empty($p) && file_exists($p) && is_file($p)) {
            $file_exists = true;
            $resolved = $p;
            break;
        }
    }

    if ($file_exists && $resolved) {
        $web_base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $real_script = realpath(__DIR__);
        $real_file = realpath($resolved);

        if ($real_file && $real_script && strpos($real_file, $real_script) === 0) {
            $relative = str_replace($real_script, '', $real_file);
            $relative = str_replace('\\', '/', $relative);
            $file_url = $web_base . $relative;
        } else {
            $file_url = $original;
        }
    }
}

// ============================================
// DETECT TYPE
// ============================================
$ext = strtolower($doc['file_extension'] ?? '');
$view_type = 'other';
$icon = 'fa-file';

if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
    $view_type = 'image'; $icon = 'fa-file-image';
} elseif ($ext === 'pdf') {
    $view_type = 'pdf'; $icon = 'fa-file-pdf';
} elseif ($ext === 'txt') {
    $view_type = 'text'; $icon = 'fa-file-alt';
} elseif (in_array($ext, ['doc', 'docx'])) {
    $icon = 'fa-file-word';
} elseif (in_array($ext, ['xls', 'xlsx'])) {
    $icon = 'fa-file-excel';
} elseif (in_array($ext, ['ppt', 'pptx'])) {
    $icon = 'fa-file-powerpoint';
} elseif (in_array($ext, ['zip', 'rar', '7z'])) {
    $icon = 'fa-file-archive';
}

// ============================================
// JSON API — for auto-view overlay
// ============================================
if ($isJson) {
    // Clear any buffered output
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store, must-revalidate');

    // Log scan
    try {
        $pdo->prepare("
            INSERT INTO qr_scans (file_id, scanned_by, ip_address, user_agent, scanned_at)
            VALUES (?, ?, ?, ?, NOW())
        ")->execute([
            $doc['id'],
            $_SESSION['user_id'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? 'Unknown',
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (PDOException $e) { /* silent */ }

    // Increment view count
    try {
        $pdo->prepare("UPDATE files SET view_count = view_count + 1 WHERE id = ?")
            ->execute([$doc['id']]);
    } catch (PDOException $e) { /* silent */ }

    echo json_encode([
        'success'        => true,
        'file_id'        => (int)$doc['id'],
        'file_name'      => $doc['file_name'],
        'control_number' => $doc['control_number'] ?? 'N/A',
        'file_ext'       => $ext,
        'file_exists'    => $file_exists,
        'file_url'       => $file_url,
        'file_path'      => $doc['file_path'] ?? '',
        'view_type'      => $view_type,
        'icon'           => $icon,
        'title'          => $doc['title_of_program'] ?? $doc['file_name'],
    ]);
    exit;
}

// ============================================
// NORMAL HTML VIEW
// ============================================

// Increment view count
try {
    $pdo->prepare("UPDATE files SET view_count = view_count + 1 WHERE id = ?")->execute([$id]);
} catch (PDOException $e) {}

// Fetch folder name
$folder_name = 'My Drive';
if (!empty($doc['folder_id']) && $doc['folder_id'] > 0) {
    try {
        $stmt = $pdo->prepare("SELECT folder_name FROM folders WHERE id = ?");
        $stmt->execute([$doc['folder_id']]);
        $folder = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($folder) {
            $folder_name = $folder['folder_name'];
        }
    } catch (PDOException $e) {}
}

// Icon helpers
function getFileIcon($extension) {
    $icons = [
        'pdf' => 'fa-file-pdf', 'doc' => 'fa-file-word', 'docx' => 'fa-file-word',
        'xls' => 'fa-file-excel', 'xlsx' => 'fa-file-excel',
        'jpg' => 'fa-file-image', 'jpeg' => 'fa-file-image', 'png' => 'fa-file-image',
        'gif' => 'fa-file-image', 'txt' => 'fa-file-alt',
        'ppt' => 'fa-file-powerpoint', 'pptx' => 'fa-file-powerpoint',
        'zip' => 'fa-file-archive', 'rar' => 'fa-file-archive'
    ];
    return $icons[strtolower($extension)] ?? 'fa-file';
}

function getFileColor($extension) {
    $colors = [
        'pdf' => '#ef4444', 'doc' => '#3b82f6', 'docx' => '#3b82f6',
        'xls' => '#10b981', 'xlsx' => '#10b981',
        'jpg' => '#8b5cf6', 'jpeg' => '#8b5cf6', 'png' => '#8b5cf6',
        'gif' => '#8b5cf6',
        'ppt' => '#f59e0b', 'pptx' => '#f59e0b',
        'zip' => '#6b7280', 'rar' => '#6b7280'
    ];
    return $colors[strtolower($extension)] ?? '#6b7280';
}

// Generate QR code
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$script_dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$full_view_url = $protocol . $host . $script_dir . '/view_document.php?id=' . $doc['id'];

$qr_base64 = '';

// Method 1: QuickChart.io (primary — Google Charts is dead)
$qr_content = @file_get_contents('https://quickchart.io/qr?text=' . urlencode($full_view_url) . '&size=300&margin=2');

// Method 2: QR Server API (fallback)
if ($qr_content === false || empty($qr_content)) {
    $qr_content = @file_get_contents('https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($full_view_url));
}

if ($qr_content !== false && !empty($qr_content)) {
    $qr_base64 = 'data:image/png;base64,' . base64_encode($qr_content);
} else {
    // SVG fallback
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="300">
        <rect width="300" height="300" fill="white"/>
        <rect x="10" y="10" width="280" height="280" fill="#667eea" rx="10"/>
        <text x="150" y="130" font-family="Arial" font-size="14" fill="white" text-anchor="middle">QR Code</text>
        <text x="150" y="155" font-family="Courier" font-size="12" fill="white" text-anchor="middle">' . htmlspecialchars($doc['control_number'] ?? 'N/A') . '</text>
        <text x="150" y="180" font-family="Arial" font-size="10" fill="rgba(255,255,255,0.7)" text-anchor="middle">USAT Archive</text>
    </svg>';
    $qr_base64 = 'data:image/svg+xml;base64,' . base64_encode($svg);
}

$is_image = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
$is_pdf = ($ext === 'pdf');
$is_txt = ($ext === 'txt');
$user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$is_mobile = preg_match('/(android|iphone|ipad|ipod|webos|blackberry|windows phone)/i', $user_agent);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="theme-color" content="#667eea">
    <meta property="og:title" content="<?php echo htmlspecialchars($doc['title_of_program'] ?: $doc['file_name']); ?>">
    <meta property="og:description" content="USAT Archive Document">
    <meta property="og:type" content="document">
    <meta property="og:url" content="<?php echo $full_view_url; ?>">
    <title><?php echo htmlspecialchars($doc['title_of_program'] ?: $doc['file_name']); ?> | USAT Archive</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 15px;
            -webkit-tap-highlight-color: transparent;
            -webkit-font-smoothing: antialiased;
        }

        .container { max-width: 900px; margin: 0 auto; }

        /* ---------- HEADER ---------- */
        .document-header {
            background: white;
            border-radius: 24px 24px 0 0;
            padding: 28px 20px;
            text-align: center;
            position: relative;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        .verification-badge {
            position: absolute;
            top: 15px; right: 15px;
            background: #10b981;
            color: white;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .document-icon {
            font-size: 60px;
            margin-bottom: 12px;
            display: block;
        }
        .document-title {
            font-size: clamp(18px, 4vw, 26px);
            font-weight: 700;
            color: #1f2937;
            margin-bottom: 8px;
            line-height: 1.3;
        }
        .document-subtitle {
            color: #6b7280;
            font-size: 13px;
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .document-subtitle span {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        /* ---------- CONTENT ---------- */
        .document-content {
            background: white;
            padding: 20px;
            border-radius: 0 0 24px 24px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }

        /* ---------- DOCUMENT VIEWER ---------- */
        .document-viewer {
            background: #f8fafc;
            border-radius: 16px;
            padding: 12px;
            margin-bottom: 20px;
            border: 1px solid #e2e8f0;
            min-height: 400px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            overflow: hidden;
        }
        .document-viewer iframe {
            width: 100%;
            height: 700px;
            border: none;
            border-radius: 10px;
            background: white;
            display: block;
        }
        .document-viewer img.document-image {
            max-width: 100%;
            height: auto;
            border-radius: 10px;
            display: block;
        }
        .document-viewer .viewer-placeholder {
            text-align: center;
            color: #64748b;
            padding: 50px 20px;
            width: 100%;
        }
        .document-viewer .viewer-placeholder i {
            font-size: 72px;
            color: #667eea;
            margin-bottom: 20px;
            display: block;
        }
        .document-viewer .viewer-placeholder h3 {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 8px;
            color: #1e293b;
        }
        .document-viewer .viewer-placeholder p {
            font-size: 13px;
            color: #94a3b8;
            margin-bottom: 20px;
        }
        .btn-view-doc {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border: none;
            border-radius: 50px;
            padding: 14px 32px;
            font-weight: 600;
            cursor: pointer;
            font-size: 15px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
            transition: all 0.3s;
        }
        .btn-view-doc:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
            color: white;
        }

        /* ---------- QR DISPLAY ---------- */
        .qr-display {
            background: #f8fafc;
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
            border: 1px solid #f1f5f9;
        }
        .qr-display img {
            max-width: 180px;
            height: auto;
            margin: 0 auto;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            padding: 8px;
            background: white;
        }
        .qr-display .control-number {
            font-family: 'Courier New', monospace;
            font-size: 14px;
            font-weight: 600;
            color: #667eea;
            margin-top: 10px;
        }
        .qr-display .qr-help {
            font-size: 11px;
            color: #94a3b8;
            margin-top: 8px;
        }

        /* ---------- INFO GRID ---------- */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            margin-bottom: 20px;
        }
        .info-card {
            background: #f8fafc;
            padding: 14px 16px;
            border-radius: 14px;
            border: 1px solid #f1f5f9;
        }
        .info-label {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            color: #94a3b8;
            margin-bottom: 4px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .info-value {
            font-size: 14px;
            font-weight: 600;
            color: #1e293b;
            word-break: break-word;
        }

        /* ---------- DESCRIPTION ---------- */
        .description-section {
            background: #f8fafc;
            padding: 16px 18px;
            border-radius: 14px;
            margin-bottom: 20px;
            border: 1px solid #f1f5f9;
        }
        .description-section h3 {
            font-size: 13px;
            margin-bottom: 8px;
            color: #334155;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .description-section p {
            color: #475569;
            line-height: 1.7;
            font-size: 13px;
        }

        /* ---------- ACTION BUTTONS ---------- */
        .action-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .btn {
            padding: 14px 24px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            font-size: 15px;
            flex: 1;
            min-width: 130px;
            -webkit-tap-highlight-color: transparent;
            font-family: inherit;
        }
        .btn:active { transform: scale(0.96); }
        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4);
            color: white;
        }
        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4);
            color: white;
        }

        /* ---------- SHARE ---------- */
        .share-buttons {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        .share-btn {
            background: transparent;
            border: none;
            color: #667eea;
            font-size: 13px;
            cursor: pointer;
            padding: 8px 16px;
            border-radius: 8px;
            transition: background 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
            font-family: inherit;
        }
        .share-btn:hover { background: #f1f5f9; }

        /* ---------- FOOTER ---------- */
        .footer {
            text-align: center;
            margin-top: 20px;
            color: rgba(255,255,255,0.85);
            font-size: 11px;
            padding: 10px;
        }

        /* ---------- TOAST ---------- */
        .toast {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0,0,0,0.85);
            color: white;
            padding: 12px 24px;
            border-radius: 50px;
            font-size: 14px;
            z-index: 9999;
            display: none;
        }

        /* ---------- RESPONSIVE ---------- */
        @media (max-width: 640px) {
            body { padding: 10px; }
            .document-header { padding: 20px 15px; }
            .document-content { padding: 15px; }
            .verification-badge { top: 10px; right: 10px; font-size: 10px; padding: 4px 10px; }
            .document-icon { font-size: 48px; }
            .document-title { font-size: 17px; }
            .info-grid { grid-template-columns: 1fr 1fr; gap: 8px; }
            .info-card { padding: 10px 12px; }
            .btn { padding: 12px 18px; font-size: 14px; min-width: 100px; }
            .action-buttons { flex-direction: column; }
            .qr-display img { max-width: 150px; }
            .document-viewer { min-height: 300px; padding: 8px; }
            .document-viewer iframe { height: 500px; }
        }
        @media (max-width: 400px) {
            .info-grid { grid-template-columns: 1fr; }
            .document-viewer iframe { height: 400px; }
        }

        @media print {
            .action-buttons, .footer, .share-buttons { display: none; }
            .document-header, .document-content { box-shadow: none; }
            body { background: white; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- HEADER -->
        <div class="document-header">
            <div class="verification-badge">
                <i class="fas fa-check-circle"></i> Verified
            </div>
            <div class="document-icon" style="color: <?php echo getFileColor($doc['file_extension']); ?>;">
                <i class="fas <?php echo getFileIcon($doc['file_extension']); ?>"></i>
            </div>
            <h1 class="document-title"><?php echo htmlspecialchars($doc['title_of_program'] ?: $doc['file_name']); ?></h1>
            <div class="document-subtitle">
                <span><i class="fas fa-folder"></i> <?php echo htmlspecialchars($folder_name); ?></span>
                <span><i class="fas fa-file"></i> <?php echo strtoupper($doc['file_extension'] ?? 'file'); ?></span>
                <span><i class="fas fa-eye"></i> <?php echo ($doc['view_count'] ?? 0) + 1; ?> views</span>
            </div>
        </div>

        <!-- CONTENT -->
        <div class="document-content">

            <!-- DOCUMENT VIEWER -->
            <?php if ($file_exists): ?>
            <div class="document-viewer">
                <?php if ($is_image): ?>
                    <img src="<?php echo htmlspecialchars($file_url); ?>"
                         alt="<?php echo htmlspecialchars($doc['file_name']); ?>"
                         class="document-image">
                <?php elseif ($is_pdf): ?>
                    <iframe src="<?php echo htmlspecialchars($file_url); ?>#toolbar=1&view=FitH"
                            title="<?php echo htmlspecialchars($doc['file_name']); ?>"></iframe>
                <?php elseif ($is_txt): ?>
                    <iframe src="<?php echo htmlspecialchars($file_url); ?>"
                            title="<?php echo htmlspecialchars($doc['file_name']); ?>"></iframe>
                <?php else: ?>
                    <div class="viewer-placeholder">
                        <i class="fas <?php echo getFileIcon($doc['file_extension']); ?>"
                           style="color: <?php echo getFileColor($doc['file_extension']); ?>;"></i>
                        <h3><?php echo htmlspecialchars($doc['file_name']); ?></h3>
                        <p>This file type (<?php echo strtoupper($ext); ?>) cannot be previewed directly.</p>
                        <a href="download_file.php?id=<?php echo $doc['id']; ?>" class="btn-view-doc">
                            <i class="fas fa-download"></i> Download to View
                        </a>
                    </div>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="document-viewer">
                <div class="viewer-placeholder">
                    <i class="fas fa-exclamation-triangle" style="color: #f59e0b;"></i>
                    <h3>File Not Found on Server</h3>
                    <p style="font-family: monospace; font-size: 11px; word-break: break-all; color: #94a3b8;">
                        Path: <?php echo htmlspecialchars($doc['file_path'] ?? 'N/A'); ?>
                    </p>
                </div>
            </div>
            <?php endif; ?>

            <!-- QR CODE -->
            <div class="qr-display">
                <p style="font-size: 12px; color: #64748b; margin-bottom: 8px;">
                    <i class="fas fa-qrcode"></i> Document QR Code
                </p>
                <img src="<?php echo $qr_base64; ?>" alt="QR Code" id="qrImage">
                <div class="control-number">
                    <i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($doc['control_number'] ?? 'N/A'); ?>
                </div>
                <p class="qr-help">Scan this QR code to view on any device</p>
            </div>

            <!-- INFO GRID -->
            <div class="info-grid">
                <div class="info-card">
                    <div class="info-label"><i class="fas fa-hashtag"></i> Control Number</div>
                    <div class="info-value"><?php echo htmlspecialchars($doc['control_number'] ?? 'N/A'); ?></div>
                </div>
                <?php if (!empty($doc['date_conducted'])): ?>
                <div class="info-card">
                    <div class="info-label"><i class="fas fa-calendar-alt"></i> Date Conducted</div>
                    <div class="info-value"><?php echo date('F d, Y', strtotime($doc['date_conducted'])); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($doc['type_of_community_service'])): ?>
                <div class="info-card">
                    <div class="info-label"><i class="fas fa-hand-holding-heart"></i> Type of Service</div>
                    <div class="info-value"><?php echo htmlspecialchars($doc['type_of_community_service']); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($doc['community_population_served'])): ?>
                <div class="info-card">
                    <div class="info-label"><i class="fas fa-map-marker-alt"></i> Community Served</div>
                    <div class="info-value"><?php echo htmlspecialchars($doc['community_population_served']); ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($doc['no_of_beneficiaries'])): ?>
                <div class="info-card">
                    <div class="info-label"><i class="fas fa-users"></i> Beneficiaries</div>
                    <div class="info-value"><?php echo number_format($doc['no_of_beneficiaries']); ?> people</div>
                </div>
                <?php endif; ?>
                <div class="info-card">
                    <div class="info-label"><i class="fas fa-chart-line"></i> File Size</div>
                    <div class="info-value"><?php echo $doc['file_size'] ?? '0'; ?> KB</div>
                </div>
                <div class="info-card">
                    <div class="info-label"><i class="fas fa-clock"></i> Uploaded</div>
                    <div class="info-value"><?php echo date('M d, Y', strtotime($doc['created_at'] ?? 'now')); ?></div>
                </div>
            </div>

            <?php if (!empty($doc['description'])): ?>
            <div class="description-section">
                <h3><i class="fas fa-align-left"></i> Description</h3>
                <p><?php echo nl2br(htmlspecialchars($doc['description'])); ?></p>
            </div>
            <?php endif; ?>

            <!-- ACTIONS -->
            <div class="action-buttons">
                <?php if ($file_exists): ?>
                    <a href="<?php echo htmlspecialchars($file_url); ?>"
                       class="btn btn-primary"
                       target="_blank"
                       rel="noopener">
                        <i class="fas fa-external-link-alt"></i> Open in New Tab
                    </a>
                    <a href="download_file.php?id=<?php echo $doc['id']; ?>" class="btn btn-success">
                        <i class="fas fa-download"></i> Download
                    </a>
                <?php else: ?>
                <div style="background:#fef2f2; padding:14px 20px; border-radius:12px; color:#dc2626; text-align:center; width:100%; font-size:13px;">
                    <i class="fas fa-exclamation-triangle"></i> Document file not available on server
                </div>
                <?php endif; ?>
            </div>

            <!-- SHARE BUTTONS -->
            <div class="share-buttons">
                <button class="share-btn" onclick="shareDocument()">
                    <i class="fas fa-share-alt"></i> Share
                </button>
                <button class="share-btn" onclick="copyLink()">
                    <i class="fas fa-copy"></i> Copy Link
                </button>
                <button class="share-btn" onclick="downloadQR()">
                    <i class="fas fa-download"></i> Download QR
                </button>
                <button class="share-btn" onclick="window.location.href='files.php'">
                    <i class="fas fa-arrow-left"></i> Back
                </button>
            </div>
        </div>

        <!-- FOOTER -->
        <div class="footer">
            <p>
                <i class="fas fa-qrcode"></i> USAT College Archiving System
                <br><small>Digitally Verified Document • <?php echo date('Y'); ?></small>
            </p>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        /* Share */
        function shareDocument() {
            const url = window.location.href;
            const title = "<?php echo htmlspecialchars($doc['title_of_program'] ?: $doc['file_name']); ?>";
            if (navigator.share) {
                navigator.share({
                    title: title,
                    text: 'View this document from USAT Archive System',
                    url: url
                }).catch(() => copyLink());
            } else {
                copyLink();
            }
        }

        /* Copy link */
        function copyLink() {
            const url = window.location.href;
            if (navigator.clipboard) {
                navigator.clipboard.writeText(url)
                    .then(() => showToast('✅ Link copied!'))
                    .catch(() => fallbackCopy(url));
            } else {
                fallbackCopy(url);
            }
        }

        function fallbackCopy(text) {
            const t = document.createElement('textarea');
            t.value = text;
            t.style.position = 'fixed';
            t.style.left = '-9999px';
            document.body.appendChild(t);
            t.select();
            try {
                document.execCommand('copy');
                showToast('✅ Link copied!');
            } catch (err) {
                showToast('❌ Unable to copy');
            }
            document.body.removeChild(t);
        }

        /* Download QR */
        function downloadQR() {
            const qrImage = document.getElementById('qrImage');
            if (qrImage) {
                const link = document.createElement('a');
                link.download = 'USAT_QR_<?php echo $doc['id']; ?>.png';
                link.href = qrImage.src;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                showToast('✅ QR downloading...');
            }
        }

        /* Toast */
        function showToast(message) {
            const toast = document.getElementById('toast');
            toast.textContent = message;
            toast.style.display = 'block';
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transition = 'opacity 0.3s';
                setTimeout(() => {
                    toast.style.display = 'none';
                    toast.style.opacity = '1';
                }, 300);
            }, 2000);
        }

        /* QR image fallback */
        document.addEventListener('DOMContentLoaded', function() {
            const qrImage = document.getElementById('qrImage');
            if (qrImage) {
                qrImage.onerror = function() {
                    this.src = "https://quickchart.io/qr?text=" + encodeURIComponent(window.location.href) + "&size=300";
                };
            }
        });
    </script>
</body>
</html>