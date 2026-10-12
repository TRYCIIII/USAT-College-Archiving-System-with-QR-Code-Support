<?php
session_start();
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
    // Get file details
    $stmt = $pdo->prepare("
        SELECT f.*, 
               (SELECT folder_name FROM folders WHERE id = f.folder_id) as folder_name
        FROM files f
        WHERE f.id = ? AND (f.status IS NULL OR f.status = 'active')
    ");
    $stmt->execute([$file_id]);
    $file = $stmt->fetch();

    if (!$file) {
        die("File not found");
    }

    // Generate view URL
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $script_dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $view_url = $protocol . $host . $script_dir . '/view_document.php?id=' . $file_id;

    // Generate QR code URL
    $qr_code_url = "https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=" . urlencode($view_url) . "&choe=UTF-8";

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <meta name="theme-color" content="#667eea">
    <title>QR Code - <?php echo htmlspecialchars($file['file_name']); ?> | USAT Archive</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .qr-card {
            background: white;
            border-radius: 32px;
            max-width: 550px;
            width: 100%;
            overflow: hidden;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            animation: fadeInUp 0.5s ease;
        }
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .qr-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 25px 20px;
            text-align: center;
        }
        .qr-header i {
            font-size: 50px;
            margin-bottom: 10px;
        }
        .qr-header h2 {
            font-size: 22px;
            margin-bottom: 5px;
        }
        .qr-header p {
            font-size: 12px;
            opacity: 0.9;
        }
        .qr-header .control-number {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            padding: 4px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-family: monospace;
            margin-top: 8px;
        }
        .qr-body {
            padding: 25px;
            text-align: center;
        }
        .document-title {
            background: #f3f4f6;
            padding: 12px;
            border-radius: 12px;
            margin-bottom: 15px;
        }
        .document-title .label {
            font-size: 10px;
            color: #6b7280;
            text-transform: uppercase;
        }
        .document-title .value {
            font-weight: 700;
            color: #1f2937;
            font-size: 15px;
            margin-top: 3px;
        }
        .qr-image {
            background: white;
            padding: 20px;
            border-radius: 16px;
            margin: 15px 0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        .qr-image img {
            max-width: 100%;
            height: auto;
            max-height: 250px;
        }
        .qr-url {
            background: #f3f4f6;
            padding: 10px;
            border-radius: 10px;
            font-size: 10px;
            word-break: break-all;
            font-family: monospace;
            margin: 10px 0;
            color: #475569;
            cursor: pointer;
        }
        .qr-url:hover {
            background: #e5e7eb;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin: 12px 0;
        }
        .info-item {
            background: #f8fafc;
            padding: 8px 10px;
            border-radius: 8px;
            text-align: left;
        }
        .info-item .label {
            font-size: 9px;
            color: #94a3b8;
            text-transform: uppercase;
        }
        .info-item .value {
            font-size: 12px;
            font-weight: 600;
            color: #1e293b;
            margin-top: 2px;
        }
        .button-group {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 15px;
            flex-wrap: wrap;
        }
        .btn {
            padding: 10px 20px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            -webkit-tap-highlight-color: transparent;
            flex: 1;
            justify-content: center;
            min-width: 100px;
        }
        .btn:active { transform: scale(0.95); }
        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(102, 126, 234, 0.4); color: white; }
        .btn-success {
            background: linear-gradient(135deg, #10b981, #059669);
            color: white;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }
        .btn-success:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(16, 185, 129, 0.4); color: white; }
        .btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }
        .btn-secondary:hover { transform: translateY(-2px); background: #d1d5db; }
        .btn-warning {
            background: linear-gradient(135deg, #f59e0b, #d97706);
            color: white;
            box-shadow: 0 4px 15px rgba(245, 158, 11, 0.3);
        }
        .btn-warning:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(245, 158, 11, 0.4); color: white; }
        .footer {
            text-align: center;
            margin-top: 15px;
            font-size: 10px;
            color: #94a3b8;
        }
        .toast {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0,0,0,0.8);
            color: white;
            padding: 12px 24px;
            border-radius: 50px;
            font-size: 14px;
            z-index: 9999;
            display: none;
            animation: fadeInUp 0.3s ease;
        }
        @media (max-width: 480px) {
            .qr-card { border-radius: 20px; }
            .qr-header { padding: 18px 15px; }
            .qr-header i { font-size: 40px; }
            .qr-header h2 { font-size: 18px; }
            .qr-body { padding: 18px; }
            .info-grid { grid-template-columns: 1fr; }
            .btn { flex: 1 1 100%; }
        }
        @media print {
            .button-group, .footer { display: none; }
            .qr-card { box-shadow: none; border: 1px solid #ddd; }
            body { background: white; padding: 0; }
            .qr-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        }
    </style>
</head>
<body>
    <div class="qr-card">
        <div class="qr-header">
            <i class="fas fa-qrcode"></i>
            <h2>Document QR Code</h2>
            <p>Scan to view document on any device</p>
            <div class="control-number">
                <i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($file['control_number'] ?? 'N/A'); ?>
            </div>
        </div>

        <div class="qr-body">
            <div class="document-title">
                <div class="label">Document Title</div>
                <div class="value"><?php echo htmlspecialchars($file['title_of_program'] ?: $file['file_name']); ?></div>
            </div>

            <div class="qr-image">
                <img src="<?php echo $qr_code_url; ?>" alt="QR Code" id="qrImage">
            </div>

            <div class="info-grid">
                <div class="info-item">
                    <div class="label">File Name</div>
                    <div class="value"><?php echo htmlspecialchars($file['file_name']); ?></div>
                </div>
                <div class="info-item">
                    <div class="label">File Size</div>
                    <div class="value"><?php echo $file['file_size'] ?? '0'; ?> KB</div>
                </div>
                <?php if (!empty($file['date_conducted'])): ?>
                <div class="info-item">
                    <div class="label">Date Conducted</div>
                    <div class="value"><?php echo date('M d, Y', strtotime($file['date_conducted'])); ?></div>
                </div>
                <?php endif; ?>
                <div class="info-item">
                    <div class="label">Views</div>
                    <div class="value"><?php echo $file['view_count'] ?? 0; ?></div>
                </div>
            </div>

            <div class="qr-url" onclick="copyUrl()" title="Click to copy URL">
                <i class="fas fa-link"></i> <?php echo $view_url; ?>
            </div>

            <div class="button-group">
                <a href="<?php echo $view_url; ?>" target="_blank" class="btn btn-primary">
                    <i class="fas fa-eye"></i> View Document
                </a>
                <a href="<?php echo $qr_code_url; ?>" download="USAT_QR_<?php echo $file['id']; ?>.png" class="btn btn-success">
                    <i class="fas fa-download"></i> Download QR
                </a>
                <button onclick="window.print()" class="btn btn-secondary">
                    <i class="fas fa-print"></i> Print
                </button>
                <button onclick="window.location.href='files.php?folder=<?php echo $file['folder_id'] ?? 1; ?>'" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </button>
            </div>

            <div class="footer">
                <i class="fas fa-shield-alt"></i> Secured Document • USAT Archive System
            </div>
        </div>
    </div>

    <div class="toast" id="toast"></div>

    <script>
        function copyUrl() {
            const url = "<?php echo $view_url; ?>";
            if (navigator.clipboard) {
                navigator.clipboard.writeText(url).then(() => {
                    showToast('URL copied to clipboard!');
                }).catch(() => {
                    fallbackCopy(url);
                });
            } else {
                fallbackCopy(url);
            }
        }

        function fallbackCopy(text) {
            const textArea = document.createElement('textarea');
            textArea.value = text;
            document.body.appendChild(textArea);
            textArea.select();
            try {
                document.execCommand('copy');
                showToast('URL copied to clipboard!');
            } catch (err) {
                showToast('Unable to copy URL');
            }
            document.body.removeChild(textArea);
        }

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
            }, 2500);
        }

        // Add QR code reload if image fails to load
        document.getElementById('qrImage').onerror = function() {
            this.src = "https://quickchart.io/qr?text=" + encodeURIComponent("<?php echo $view_url; ?>") + "&size=300";
        };

        // Share functionality for mobile
        document.addEventListener('DOMContentLoaded', function() {
            const shareBtn = document.createElement('button');
            shareBtn.className = 'btn btn-warning';
            shareBtn.innerHTML = '<i class="fas fa-share-alt"></i> Share';
            shareBtn.onclick = function() {
                if (navigator.share) {
                    navigator.share({
                        title: '<?php echo htmlspecialchars($file['title_of_program'] ?: $file['file_name']); ?>',
                        text: 'View this document from USAT Archive System',
                        url: '<?php echo $view_url; ?>'
                    }).catch(() => {});
                } else {
                    copyUrl();
                }
            };
            document.querySelector('.button-group').appendChild(shareBtn);
        });
    </script>
</body>
</html>