<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit();
}

$file_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($file_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'Invalid file ID']);
    exit();
}

try {
    // Get file details
    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ? AND (status IS NULL OR status = 'active')");
    $stmt->execute([$file_id]);
    $file = $stmt->fetch();
    
    if (!$file) {
        echo json_encode(['success' => false, 'error' => 'File not found']);
        exit();
    }
    
    // Generate view URL
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'];
    $script_dir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    $view_url = $protocol . $host . $script_dir . '/view_document.php?id=' . $file_id;
    
    // Check for saved QR code
    $qr_data = null;
    $qr_dir = __DIR__ . '/qrcodes/';
    
    // Look for existing QR file
    if (!empty($file['qr_code_path']) && file_exists($file['qr_code_path'])) {
        $qr_content = file_get_contents($file['qr_code_path']);
        if ($qr_content !== false) {
            $qr_data = 'data:image/png;base64,' . base64_encode($qr_content);
        }
    } elseif (!empty($file['qr_code_path']) && file_exists(__DIR__ . '/' . $file['qr_code_path'])) {
        $qr_content = file_get_contents(__DIR__ . '/' . $file['qr_code_path']);
        if ($qr_content !== false) {
            $qr_data = 'data:image/png;base64,' . base64_encode($qr_content);
        }
    } else {
        // Try to find by pattern
        $qr_files = glob($qr_dir . 'qr_' . $file_id . '_*.png');
        if (!empty($qr_files)) {
            rsort($qr_files);
            $qr_content = file_get_contents($qr_files[0]);
            if ($qr_content !== false) {
                $qr_data = 'data:image/png;base64,' . base64_encode($qr_content);
            }
        }
    }
    
    // If no saved QR code, generate one
    if (!$qr_data) {
        // Create qrcodes directory if it doesn't exist
        if (!file_exists($qr_dir)) {
            mkdir($qr_dir, 0777, true);
            chmod($qr_dir, 0777);
        }
        
        // Try multiple QR generation methods
        $qr_content = false;
        
        // Method 1: Google Charts API
        $qr_content = @file_get_contents("https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=" . urlencode($view_url) . "&choe=UTF-8");
        
        // Method 2: QuickChart.io (Fallback)
        if ($qr_content === false) {
            $qr_content = @file_get_contents("https://quickchart.io/qr?text=" . urlencode($view_url) . "&size=300&margin=2");
        }
        
        // Method 3: QR Server API (Fallback)
        if ($qr_content === false) {
            $qr_content = @file_get_contents("https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($view_url));
        }
        
        if ($qr_content !== false) {
            // Save QR code
            $qr_filename = 'qr_' . $file_id . '_' . time() . '.png';
            $qr_path = 'qrcodes/' . $qr_filename;
            $full_path = $qr_dir . $qr_filename;
            
            if (file_put_contents($full_path, $qr_content)) {
                chmod($full_path, 0644);
                // Update database
                try {
                    $update = $pdo->prepare("UPDATE files SET qr_code_path = ? WHERE id = ?");
                    $update->execute([$qr_path, $file_id]);
                } catch (PDOException $e) {
                    // Column might not exist
                }
            }
            $qr_data = 'data:image/png;base64,' . base64_encode($qr_content);
        } else {
            // Create fallback QR
            $qr_data = createFallbackQR($view_url, $file['control_number'] ?? 'N/A', $file_id);
        }
    }
    
    echo json_encode([
        'success' => true,
        'file_id' => $file['id'],
        'file_name' => $file['file_name'],
        'control_number' => $file['control_number'] ?? 'N/A',
        'qr_code' => $qr_data,
        'title_of_program' => $file['title_of_program'] ?? 'N/A',
        'date_conducted' => $file['date_conducted'] ?? 'N/A',
        'type_of_community_service' => $file['type_of_community_service'] ?? 'N/A',
        'community_population_served' => $file['community_population_served'] ?? 'N/A',
        'no_of_beneficiaries' => $file['no_of_beneficiaries'] ?? 0,
        'view_count' => $file['view_count'] ?? 0,
        'file_extension' => $file['file_extension'] ?? '',
        'view_url' => $view_url
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => 'Error: ' . $e->getMessage()
    ]);
}

// Function to create fallback QR using GD
function createFallbackQR($url, $control_number, $file_id) {
    if (!extension_loaded('gd')) {
        // SVG fallback
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="300">
            <rect width="300" height="300" fill="white"/>
            <rect x="10" y="10" width="280" height="280" fill="#667eea" rx="10"/>
            <text x="150" y="130" font-family="Arial" font-size="14" fill="white" text-anchor="middle">QR Code</text>
            <text x="150" y="155" font-family="Courier" font-size="12" fill="white" text-anchor="middle">' . htmlspecialchars($control_number) . '</text>
            <text x="150" y="180" font-family="Arial" font-size="10" fill="rgba(255,255,255,0.7)" text-anchor="middle">USAT Archive</text>
            <text x="150" y="200" font-family="Arial" font-size="8" fill="rgba(255,255,255,0.5)" text-anchor="middle">ID: ' . $file_id . '</text>
        </svg>';
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }
    
    $image = imagecreate(300, 300);
    $bg = imagecolorallocate($image, 255, 255, 255);
    $blue = imagecolorallocate($image, 102, 126, 234);
    $dark = imagecolorallocate($image, 30, 30, 30);
    
    imagefilledrectangle($image, 0, 0, 300, 300, $bg);
    imagerectangle($image, 10, 10, 290, 290, $blue);
    imagerectangle($image, 12, 12, 288, 288, $blue);
    
    for ($i = 0; $i < 20; $i++) {
        $x = 30 + ($i * 12);
        $y = 30 + ($i * 12);
        if ($i % 2 == 0) {
            imagefilledrectangle($image, $x, $y, $x+8, $y+8, $blue);
        }
    }
    
    for ($i = 0; $i < 30; $i++) {
        $x = 30 + rand(0, 20) * 12;
        $y = 30 + rand(0, 20) * 12;
        if (rand(0, 1)) {
            imagefilledrectangle($image, $x, $y, $x+6, $y+6, $dark);
        }
    }
    
    imagestring($image, 5, 50, 150, "QR: " . $control_number, $dark);
    imagestring($image, 3, 40, 170, "Scan to view document", $dark);
    imagestring($image, 2, 60, 190, "USAT Archive", $blue);
    imagestring($image, 2, 80, 210, "ID: " . $file_id, $dark);
    
    ob_start();
    imagepng($image);
    $data = ob_get_clean();
    imagedestroy($image);
    
    return 'data:image/png;base64,' . base64_encode($data);
}
?>