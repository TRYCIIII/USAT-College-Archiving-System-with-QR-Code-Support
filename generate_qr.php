<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$file_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($file_id <= 0) {
    die("Invalid file ID");
}

try {
    // Get file details
    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
    $stmt->execute([$file_id]);
    $file = $stmt->fetch();
    
    if (!$file) {
        die("File not found");
    }
    
    // Generate QR token if not exists
    if (empty($file['qr_code'])) {
        $qr_token = 'DOC_' . $file_id . '_' . time() . '_' . bin2hex(random_bytes(8));
        $update = $pdo->prepare("UPDATE files SET qr_code = ?, qr_generated_at = NOW() WHERE id = ?");
        $update->execute([$qr_token, $file_id]);
        $file['qr_code'] = $qr_token;
    }
    
    // Get base URL
    $protocol = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
    $base_url = $protocol . $_SERVER['HTTP_HOST'];
    $script_dir = dirname($_SERVER['SCRIPT_NAME']);
    $view_url = $base_url . $script_dir . '/view_document.php?token=' . $file['qr_code'];
    
    // Generate QR code using Google Charts API
    $qr_code_url = "https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=" . urlencode($view_url) . "&choe=UTF-8";
    
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QR Code - <?php echo htmlspecialchars($file['file_name']); ?> | USAT Archive</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
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
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .qr-header {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .qr-header i {
            font-size: 60px;
            margin-bottom: 15px;
        }
        
        .qr-header h2 {
            font-size: 24px;
            margin-bottom: 5px;
        }
        
        .qr-header p {
            font-size: 13px;
            opacity: 0.9;
        }
        
        .qr-body {
            padding: 30px;
            text-align: center;
        }
        
        .document-title {
            background: #f3f4f6;
            padding: 15px;
            border-radius: 16px;
            margin-bottom: 20px;
        }
        
        .document-title .label {
            font-size: 11px;
            color: #6b7280;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        
        .document-title .value {
            font-weight: 700;
            color: #1f2937;
            font-size: 16px;
        }
        
        .qr-image {
            background: white;
            padding: 20px;
            border-radius: 20px;
            margin: 20px 0;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .qr-image img {
            max-width: 100%;
            height: auto;
        }
        
        .qr-url {
            background: #f3f4f6;
            padding: 12px;
            border-radius: 12px;
            font-size: 11px;
            word-break: break-all;
            font-family: monospace;
            margin: 15px 0;
        }
        
        .button-group {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-top: 20px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 10px 20px;
            border-radius: 50px;
            text-decoration: none;
            font-weight: 600;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        
        .btn-secondary {
            background: #e5e7eb;
            color: #374151;
        }
        
        .btn-primary:hover, .btn-secondary:hover {
            transform: translateY(-2px);
        }
        
        @media print {
            .button-group {
                display: none;
            }
            .qr-card {
                box-shadow: none;
            }
            body {
                background: white;
                padding: 0;
            }
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label-small {
            color: #6b7280;
            font-size: 12px;
        }
        
        .info-value-small {
            font-weight: 600;
            color: #1f2937;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="qr-card">
        <div class="qr-header">
            <i class="fas fa-qrcode"></i>
            <h2>Document QR Code</h2>
            <p>Scan to view document details</p>
        </div>
        
        <div class="qr-body">
            <div class="document-title">
                <div class="label">Document Title</div>
                <div class="value"><?php echo htmlspecialchars($file['title_of_program'] ?: $file['file_name']); ?></div>
            </div>
            
            <div class="qr-image">
                <img src="<?php echo $qr_code_url; ?>" alt="QR Code for Document Access" id="qrImage">
            </div>
            
            <div class="info-row">
                <span class="info-label-small">File Name:</span>
                <span class="info-value-small"><?php echo htmlspecialchars($file['file_name']); ?>.<?php echo $file['file_extension']; ?></span>
            </div>
            
            <div class="info-row">
                <span class="info-label-small">File Size:</span>
                <span class="info-value-small"><?php echo $file['file_size']; ?> KB</span>
            </div>
            
            <div class="info-row">
                <span class="info-label-small">Document ID:</span>
                <span class="info-value-small">#<?php echo $file['id']; ?></span>
            </div>
            
            <div class="qr-url">
                <i class="fas fa-link"></i> <?php echo $view_url; ?>
            </div>
            
            <div class="button-group">
                <button onclick="window.print()" class="btn btn-primary">
                    <i class="fas fa-print"></i> Print QR
                </button>
                <a href="<?php echo $qr_code_url; ?>" download="USAT_QR_<?php echo $file['id']; ?>.png" class="btn btn-primary">
                    <i class="fas fa-download"></i> Download PNG
                </a>
                <a href="view_document.php?token=<?php echo $file['qr_code']; ?>" target="_blank" class="btn btn-secondary">
                    <i class="fas fa-external-link-alt"></i> Preview
                </a>
                <button onclick="window.location.href='files.php?folder=<?php echo $file['folder_id']; ?>'" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back
                </button>
            </div>
        </div>
    </div>
    
    <script>
        // Optional: Add copy URL functionality
        function copyUrl() {
            const url = "<?php echo $view_url; ?>";
            navigator.clipboard.writeText(url);
            alert("URL copied to clipboard!");
        }
    </script>
</body>
</html>