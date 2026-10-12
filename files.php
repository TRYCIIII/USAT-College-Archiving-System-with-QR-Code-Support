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

// ---- Auto-ensure status column exists (safe on Windows) ----
try {
    $cols = $pdo->query("SHOW COLUMNS FROM files")->fetchAll(PDO::FETCH_COLUMN);
    $cols = array_map('strtolower', $cols);
    if (!in_array('status', $cols, true)) {
        $pdo->exec("ALTER TABLE files ADD COLUMN status ENUM('active','archived') DEFAULT 'active'");
    }
    if (!in_array('view_count', $cols, true)) {
        // Some installs use a file_views table instead — skip if so
    }
} catch (PDOException $e) {
    // Table may not exist yet — ignore
}
try {
    $cols = $pdo->query("SHOW COLUMNS FROM folders")->fetchAll(PDO::FETCH_COLUMN);
    $cols = array_map('strtolower', $cols);
    if (!in_array('status', $cols, true)) {
        $pdo->exec("ALTER TABLE folders ADD COLUMN status ENUM('active','archived') DEFAULT 'active'");
    }
} catch (PDOException $e) {
    // ignore
}

$current_folder = isset($_GET['folder']) ? (int)$_GET['folder'] : 1;
$view_mode      = isset($_GET['view']) ? $_GET['view'] : 'grid';
$search         = isset($_GET['search']) ? trim($_GET['search']) : '';
$show_archived  = isset($_GET['show_archived']) ? $_GET['show_archived'] : '0';

// ---- Validate view mode ----
if (!in_array($view_mode, ['grid', 'list'], true)) {
    $view_mode = 'grid';
}
if (!in_array($show_archived, ['0', '1'], true)) {
    $show_archived = '0';
}

// ---- Load current folder info ----
if ($current_folder > 0) {
    $stmt = $pdo->prepare("SELECT * FROM folders WHERE id = ?");
    $stmt->execute([$current_folder]);
    $current_folder_info = $stmt->fetch();
    if (!$current_folder_info) {
        $current_folder = 1;
        $current_folder_info = ['id' => 1, 'folder_name' => 'My Drive', 'parent_id' => 0];
    }
} else {
    $current_folder_info = ['id' => 1, 'folder_name' => 'My Drive', 'parent_id' => 0];
    $current_folder = 1;
}

// ---- Root folder name with cycle safety ----
function getRootFolderNameForDisplay($pdo, $folder_id) {
    $current_id = $folder_id;
    $root_folder = null;
    $safety = 0;

    while ($current_id > 1 && $safety++ < 50) {
        $stmt = $pdo->prepare("SELECT id, folder_name, parent_id FROM folders WHERE id = ?");
        $stmt->execute([$current_id]);
        $folder = $stmt->fetch();

        if (!$folder) break;

        $root_folder = $folder;

        if ($folder['parent_id'] == 0 || $folder['parent_id'] == 1) {
            return $folder['folder_name'];
        }

        $current_id = $folder['parent_id'];
    }

    if ($root_folder) return $root_folder['folder_name'];
    return 'DOCUMENTS';
}

$root_folder_name = getRootFolderNameForDisplay($pdo, $current_folder);

// ---- Get folders ----
if ($show_archived == '1') {
    $stmt = $pdo->prepare("SELECT * FROM folders WHERE parent_id = ? AND status = 'archived' ORDER BY folder_name");
} else {
    $stmt = $pdo->prepare("SELECT * FROM folders WHERE parent_id = ? AND (status IS NULL OR status = 'active') ORDER BY folder_name");
}
$stmt->execute([$current_folder]);
$subfolders = $stmt->fetchAll();

// ---- Get files ----
try {
    if ($show_archived == '1') {
        if (!empty($search)) {
            $stmt = $pdo->prepare("
                SELECT f.*,
                       (SELECT COUNT(*) FROM file_views WHERE file_id = f.id) as view_count
                FROM files f
                WHERE f.folder_id = ? AND f.status = 'archived'
                AND (f.file_name LIKE ? OR f.title_of_program LIKE ? OR f.control_number LIKE ?)
                ORDER BY f.updated_at DESC
            ");
            $stmt->execute([$current_folder, "%$search%", "%$search%", "%$search%"]);
        } else {
            $stmt = $pdo->prepare("
                SELECT f.*,
                       (SELECT COUNT(*) FROM file_views WHERE file_id = f.id) as view_count
                FROM files f
                WHERE f.folder_id = ? AND f.status = 'archived'
                ORDER BY f.updated_at DESC
            ");
            $stmt->execute([$current_folder]);
        }
    } else {
        if (!empty($search)) {
            $stmt = $pdo->prepare("
                SELECT f.*,
                       (SELECT COUNT(*) FROM file_views WHERE file_id = f.id) as view_count
                FROM files f
                WHERE f.folder_id = ? AND (f.status IS NULL OR f.status = 'active')
                AND (f.file_name LIKE ? OR f.title_of_program LIKE ? OR f.control_number LIKE ?)
                ORDER BY f.created_at DESC
            ");
            $stmt->execute([$current_folder, "%$search%", "%$search%", "%$search%"]);
        } else {
            $stmt = $pdo->prepare("
                SELECT f.*,
                       (SELECT COUNT(*) FROM file_views WHERE file_id = f.id) as view_count
                FROM files f
                WHERE f.folder_id = ? AND (f.status IS NULL OR f.status = 'active')
                ORDER BY f.created_at DESC
            ");
            $stmt->execute([$current_folder]);
        }
    }
    $files = $stmt->fetchAll();
} catch (PDOException $e) {
    // Fallback if file_views or status column missing
    if (!empty($search)) {
        $stmt = $pdo->prepare("SELECT * FROM files WHERE folder_id = ? AND file_name LIKE ? ORDER BY created_at DESC");
        $stmt->execute([$current_folder, "%$search%"]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM files WHERE folder_id = ? ORDER BY created_at DESC");
        $stmt->execute([$current_folder]);
    }
    $files = $stmt->fetchAll();
}

// ---- Counts ----
try {
    $activeCount = $pdo->prepare("SELECT COUNT(*) FROM files WHERE folder_id = ? AND (status IS NULL OR status = 'active')");
    $activeCount->execute([$current_folder]);
    $active_total = $activeCount->fetchColumn();

    $archivedCount = $pdo->prepare("SELECT COUNT(*) FROM files WHERE folder_id = ? AND status = 'archived'");
    $archivedCount->execute([$current_folder]);
    $archived_total = $archivedCount->fetchColumn();

    $activeFolders = $pdo->prepare("SELECT COUNT(*) FROM folders WHERE parent_id = ? AND (status IS NULL OR status = 'active')");
    $activeFolders->execute([$current_folder]);
    $active_folders_total = $activeFolders->fetchColumn();

    $archivedFolders = $pdo->prepare("SELECT COUNT(*) FROM folders WHERE parent_id = ? AND status = 'archived'");
    $archivedFolders->execute([$current_folder]);
    $archived_folders_total = $archivedFolders->fetchColumn();
} catch (PDOException $e) {
    $active_total = $archived_total = $active_folders_total = $archived_folders_total = 0;
}

// ---- Breadcrumb with cycle safety ----
$breadcrumb = [];
$parent_id = $current_folder;
$safety = 0;
while ($parent_id > 0 && $parent_id != 1 && $safety++ < 50) {
    $stmt = $pdo->prepare("SELECT id, folder_name, parent_id FROM folders WHERE id = ?");
    $stmt->execute([$parent_id]);
    $folder = $stmt->fetch();
    if ($folder) {
        array_unshift($breadcrumb, $folder);
        $parent_id = $folder['parent_id'];
    } else break;
}

// ---- Messages ----
$success_message = '';
$error_message = '';
if (isset($_SESSION['success'])) { $success_message = $_SESSION['success']; unset($_SESSION['success']); }
if (isset($_SESSION['error']))   { $error_message   = $_SESSION['error'];   unset($_SESSION['error']); }

if (isset($_GET['success'])) {
    $msgs = [
        'folder_created'   => '📁 Folder created!',
        'file_uploaded'    => '📄 File uploaded with QR code!',
        'deleted'          => '🗑️ File moved to trash!',
        'folder_renamed'   => '📁 Folder renamed!',
        'file_renamed'     => '📄 File renamed!',
        'folder_archived'  => '📁 Folder archived!',
        'folder_restored'  => '📁 Folder restored!',
        'file_archived'    => '📄 File archived!',
        'file_restored'    => '📄 File restored!'
    ];
    if (isset($msgs[$_GET['success']])) $success_message = $msgs[$_GET['success']];
}
if (isset($_GET['error'])) $error_message = '❌ ' . htmlspecialchars(urldecode($_GET['error']));

$show_qr_id = isset($_GET['show_qr']) ? (int)$_GET['show_qr'] : 0;

// ---- Recent control numbers ----
try {
    $recentCN = $pdo->query("SELECT id, control_number, file_name FROM files WHERE control_number IS NOT NULL AND (status IS NULL OR status='active') ORDER BY created_at DESC LIMIT 10")->fetchAll();
} catch (PDOException $e) { $recentCN = []; }

// ---- Trash count ----
$trash_count = 0;
if (function_exists('isAdmin') && isAdmin()) {
    try {
        $trash_count = $pdo->query("SELECT COUNT(*) FROM trash_bin WHERE status = 'deleted'")->fetchColumn();
    } catch (PDOException $e) { $trash_count = 0; }
}

// ---- QR Code URL (replaced dead Google Chart API with working QR service) ----
function getQRCodeUrl($file_id) {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script_dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $view_url = $protocol . $host . $script_dir . '/view_document.php?id=' . $file_id;
    // api.qrserver.com is free and still works — no API key needed
    return "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=" . urlencode($view_url);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Manager - USAT Archive System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:'Inter',sans-serif;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);min-height:100vh;padding:20px}
        .file-manager{background:#fff;border-radius:32px;padding:32px;box-shadow:0 25px 50px -12px rgba(0,0,0,.25)}
        .fm-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:32px;flex-wrap:wrap;gap:20px}
        .breadcrumb-premium{display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:#f8f9fa;padding:8px 20px;border-radius:50px}
        .breadcrumb-item{color:#4a5568;text-decoration:none;padding:6px 14px;border-radius:30px;font-size:13px;font-weight:500;transition:all .3s}
        .breadcrumb-item:hover{background:#fff;color:#667eea;text-decoration:none}
        .view-toggle-premium{display:flex;gap:10px;background:#f1f3f5;padding:5px;border-radius:50px}
        .view-btn-premium{padding:8px 24px;border-radius:40px;background:none;border:none;cursor:pointer;font-weight:600;transition:all .3s;text-decoration:none;color:#4a5568}
        .view-btn-premium.active{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff}
        .alert{padding:15px 20px;border-radius:16px;margin-bottom:24px;font-weight:500}
        .alert-success{background:#d4edda;color:#155724;border-left:4px solid #28a745}
        .alert-danger{background:#f8d7da;color:#721c24;border-left:4px solid #dc3545}
        .tabs-premium{display:flex;gap:15px;margin-bottom:32px;flex-wrap:wrap}
        .tab-premium{padding:12px 28px;border-radius:50px;text-decoration:none;display:inline-flex;align-items:center;gap:10px;font-weight:600;transition:all .3s;background:#f8f9fa;color:#4a5568}
        .tab-premium.active{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff}
        .tab-premium:hover{transform:translateY(-2px);text-decoration:none}
        .action-buttons-premium{display:flex;gap:15px;justify-content:flex-end;margin-bottom:24px;flex-wrap:wrap}
        .btn-new-folder-premium{background:#fff;border:2px solid #e2e8f0;border-radius:50px;padding:10px 24px;cursor:pointer;font-weight:600;transition:all .3s}
        .btn-new-folder-premium:hover{border-color:#667eea;color:#667eea;transform:translateY(-2px)}
        .btn-upload-premium{background:linear-gradient(135deg,#11998e,#38ef7d);border:none;border-radius:50px;padding:10px 28px;color:#fff;cursor:pointer;font-weight:600;transition:all .3s}
        .btn-upload-premium:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(17,153,142,.4)}
        .btn-download-all{background:linear-gradient(135deg,#667eea,#764ba2);border:none;border-radius:50px;padding:10px 28px;color:#fff;cursor:pointer;font-weight:600;transition:all .3s}
        .btn-download-all:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(102,126,234,.4)}
        .btn-print-all{background:linear-gradient(135deg,#f59e0b,#ea580c);border:none;border-radius:50px;padding:10px 28px;color:#fff;cursor:pointer;font-weight:600;transition:all .3s}
        .btn-print-all:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(245,158,11,.4)}
        .btn-trash{background:linear-gradient(135deg,#ef4444,#dc2626);border:none;border-radius:50px;padding:10px 28px;color:#fff;cursor:pointer;font-weight:600;transition:all .3s;text-decoration:none;display:inline-flex;align-items:center;gap:8px}
        .btn-trash:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(239,68,68,.4);color:#fff}
        .search-container-enhanced{display:flex;gap:15px;margin-bottom:32px;flex-wrap:wrap;align-items:center}
        .search-wrapper-enhanced{position:relative;flex:1;min-width:280px}
        .search-wrapper-enhanced .search-icon{position:absolute;left:16px;top:50%;transform:translateY(-50%);color:#667eea;font-size:16px;z-index:1}
        .search-wrapper-enhanced input{width:100%;padding:14px 20px 14px 48px;border-radius:16px;border:2px solid #e2e8f0;font-size:14px;transition:all .3s;background:#fff}
        .search-wrapper-enhanced input:focus{outline:none;border-color:#667eea;box-shadow:0 0 0 4px rgba(102,126,234,.1)}
        .search-wrapper-enhanced .clear-search{position:absolute;right:14px;top:50%;transform:translateY(-50%);color:#94a3b8;cursor:pointer;background:none;border:none;padding:6px;border-radius:50%;transition:all .2s;display:none;z-index:2}
        .search-wrapper-enhanced .clear-search.visible{display:flex}
        .search-wrapper-enhanced .clear-search:hover{color:#ef4444;background:#fee2e2}
        .search-by-cn-btn{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border:none;padding:14px 20px;border-radius:16px;font-weight:600;font-size:14px;cursor:pointer;transition:all .3s;display:flex;align-items:center;gap:8px;white-space:nowrap}
        .search-by-cn-btn:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(102,126,234,.4)}
        .control-number-top{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:6px 12px;border-radius:8px;font-size:11px;font-weight:700;font-family:'Courier New',monospace;letter-spacing:.5px;margin-bottom:12px;display:inline-block;align-self:center}
        .grid-view-premium{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:24px;margin-top:24px}
        .grid-item-premium{background:#fff;border-radius:20px;padding:24px 16px;text-align:center;cursor:pointer;transition:all .3s;border:1px solid #e9ecef;position:relative;display:flex;flex-direction:column}
        .grid-item-premium:hover{transform:translateY(-5px);box-shadow:0 10px 30px rgba(0,0,0,.1);border-color:#667eea}
        .grid-icon-premium{font-size:56px;margin-bottom:16px}
        .grid-name-premium{font-weight:700;margin-bottom:8px;word-break:break-word;color:#2d3748;font-size:14px}
        .grid-meta-premium{font-size:11px;color:#718096;margin-top:auto}
        .action-menu{position:absolute;top:12px;right:12px;display:flex;gap:6px;opacity:0;transition:all .3s;z-index:10;flex-wrap:wrap;max-width:calc(100% - 24px);justify-content:flex-end}
        .grid-item-premium:hover .action-menu{opacity:1}
        .action-icon{background:#fff;border-radius:50%;width:32px;height:32px;display:flex;align-items:center;justify-content:center;cursor:pointer;box-shadow:0 2px 6px rgba(0,0,0,.1);border:none;transition:all .2s;color:#4a5568}
        .action-icon.edit:hover{background:#3b82f6;color:#fff}
        .action-icon.archive:hover{background:#f59e0b;color:#fff}
        .action-icon.qr:hover{background:#10b981;color:#fff}
        .action-icon.trash:hover{background:#ef4444;color:#fff}
        .action-icon.download:hover{background:#8b5cf6;color:#fff}
        .action-icon.print:hover{background:#ea580c;color:#fff}
        .empty-state-premium{text-align:center;padding:60px 20px}
        .empty-icon-premium{font-size:80px;color:#cbd5e0;margin-bottom:24px}
        .table-responsive{overflow-x:auto;margin-top:24px}
        .table{width:100%;border-collapse:collapse;background:#fff;border-radius:16px;overflow:hidden}
        .table thead th{background:#f8f9fa;padding:15px;font-weight:600;color:#374151;border-bottom:2px solid #e9ecef}
        .table tbody tr{transition:all .3s;border-bottom:1px solid #f0f0f0;cursor:pointer}
        .table tbody tr:hover{background:#f8f9fa}
        .table td{padding:12px 15px;vertical-align:middle}
        .cn-badge-list{background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:4px 10px;border-radius:6px;font-size:11px;font-weight:600;font-family:'Courier New',monospace;white-space:nowrap}
        .btn-sm{padding:5px 12px;font-size:12px;border-radius:8px;margin:2px}
        .btn-outline-primary{border:1px solid #667eea;background:transparent;color:#667eea}.btn-outline-primary:hover{background:#667eea;color:#fff}
        .btn-outline-warning{border:1px solid #f59e0b;background:transparent;color:#f59e0b}.btn-outline-warning:hover{background:#f59e0b;color:#fff}
        .btn-outline-danger{border:1px solid #ef4444;background:transparent;color:#ef4444}.btn-outline-danger:hover{background:#ef4444;color:#fff}
        .btn-outline-info{border:1px solid #10b981;background:transparent;color:#10b981}.btn-outline-info:hover{background:#10b981;color:#fff}
        .btn-outline-success{border:1px solid #28a745;background:transparent;color:#28a745}.btn-outline-success:hover{background:#28a745;color:#fff}
        .btn-outline-print{border:1px solid #ea580c;background:transparent;color:#ea580c}.btn-outline-print:hover{background:#ea580c;color:#fff}
        .modal-content{border-radius:24px;border:none}.modal-header{border-bottom:none;padding:24px 28px}.modal-body{padding:24px 28px}.modal-footer{border-top:none;padding:20px 28px}
        .form-label{font-weight:600;margin-bottom:8px;color:#2d3748}
        .form-control,.form-select{border-radius:12px;border:2px solid #e2e8f0;padding:10px 14px}
        .form-control:focus,.form-select:focus{border-color:#667eea;box-shadow:0 0 0 3px rgba(102,126,234,.1)}
        .qr-code-img{max-width:250px;margin:20px auto;border:1px solid #ddd;border-radius:10px;padding:10px}
        .cn-input-group{display:flex;gap:10px}
        .cn-input-group input{flex:1;padding:12px 16px;border:2px solid #e2e8f0;border-radius:12px;font-family:'Courier New',monospace;font-size:16px;font-weight:600;letter-spacing:1px;text-transform:uppercase}
        .cn-input-group input:focus{border-color:#667eea;outline:none;box-shadow:0 0 0 3px rgba(102,126,234,.1)}
        .cn-input-group button{padding:12px 24px;background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;border:none;border-radius:12px;font-weight:600;cursor:pointer;transition:all .3s}
        .cn-input-group button:hover{transform:translateY(-2px);box-shadow:0 5px 15px rgba(102,126,234,.3)}
        .recent-cn-list{display:flex;flex-wrap:wrap;gap:8px;margin-top:15px}
        .recent-cn-item{background:#f1f5f9;padding:6px 14px;border-radius:20px;font-family:'Courier New',monospace;font-size:12px;font-weight:600;cursor:pointer;transition:all .2s;color:#475569}
        .recent-cn-item:hover{background:#667eea;color:#fff}
        .cn-upload-field{background:#f0f4ff;border-radius:12px;border:2px dashed #667eea;padding:15px;margin-bottom:20px}
        .cn-upload-field label{color:#667eea;font-weight:600}
        .cn-upload-field .form-control{border:2px solid #667eea;font-family:'Courier New',monospace;font-weight:600;letter-spacing:.5px}
        .qr-upload-success{background:linear-gradient(135deg,#d1fae5,#a7f3d0);border-radius:16px;padding:20px;margin-top:20px;text-align:center}
        .qr-upload-success .qr-img{max-width:180px;margin:15px auto;border:2px solid #fff;border-radius:16px;box-shadow:0 4px 12px rgba(0,0,0,.1)}
        .checkbox-select{width:20px;height:20px;cursor:pointer;margin:0}
        .bulk-actions-bar{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#fff;border-radius:50px;padding:12px 24px;box-shadow:0 10px 40px rgba(0,0,0,.2);display:none;z-index:1000;gap:15px;align-items:center}
        .loading-overlay{position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:9999;display:flex;align-items:center;justify-content:center;color:#fff;font-size:18px}
        .loading-overlay i{font-size:48px;margin-bottom:15px}
        .trash-badge{background:#ef4444;color:white;border-radius:20px;padding:2px 8px;font-size:11px;margin-left:8px}
        .qr-thumb{width:60px;height:60px;border-radius:8px;border:1px solid #e9ecef;padding:4px;background:#fff}
        .qr-preview-card{background:#f8f9fa;border-radius:12px;padding:10px;text-align:center;border:1px solid #e9ecef}
        .qr-preview-card img{max-width:60px;margin:0 auto;border-radius:8px}
        .qr-preview-card .qr-label{font-size:9px;color:#6c757d;margin-top:4px;word-break:break-all}
        .toast-notification{position:fixed;bottom:80px;left:50%;transform:translateX(-50%);background:rgba(0,0,0,0.85);color:white;padding:14px 28px;border-radius:50px;font-size:15px;font-weight:500;z-index:99999;max-width:90%;text-align:center;animation:fadeInUp 0.3s ease;box-shadow:0 4px 20px rgba(0,0,0,0.3)}
        @keyframes fadeInUp{from{opacity:0;transform:translateX(-50%) translateY(20px)}to{opacity:1;transform:translateX(-50%) translateY(0)}}
        @media(max-width:768px){body{padding:10px}.file-manager{padding:20px}.grid-view-premium{grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:16px}.action-buttons-premium{justify-content:center}.fm-header{flex-direction:column}.breadcrumb-premium{justify-content:center}.search-container-enhanced{flex-direction:column}.search-by-cn-btn{width:100%;justify-content:center}}
    </style>
</head>
<body>
<?php include 'includes/header.php'; ?>
<div class="file-manager">
    <div class="fm-header">
        <div class="breadcrumb-premium">
            <a href="files.php?folder=1" class="breadcrumb-item"><i class="fas fa-home"></i> My Drive</a>
            <?php foreach($breadcrumb as $crumb): ?>
                <i class="fas fa-chevron-right" style="color:#cbd5e0;font-size:12px"></i>
                <a href="files.php?folder=<?php echo (int)$crumb['id']; ?>" class="breadcrumb-item"><i class="fas fa-folder"></i> <?php echo htmlspecialchars($crumb['folder_name']); ?></a>
            <?php endforeach; ?>
        </div>
        <div class="view-toggle-premium">
            <a href="?folder=<?php echo $current_folder; ?>&view=grid<?php echo $show_archived=='1'?'&show_archived=1':''; ?><?php echo !empty($search)?'&search='.urlencode($search):''; ?>" class="view-btn-premium <?php echo $view_mode=='grid'?'active':''; ?>"><i class="fas fa-th-large"></i> Grid</a>
            <a href="?folder=<?php echo $current_folder; ?>&view=list<?php echo $show_archived=='1'?'&show_archived=1':''; ?><?php echo !empty($search)?'&search='.urlencode($search):''; ?>" class="view-btn-premium <?php echo $view_mode=='list'?'active':''; ?>"><i class="fas fa-list"></i> List</a>
        </div>
    </div>

    <?php if($success_message): ?><div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success_message; ?></div><?php endif; ?>
    <?php if($error_message): ?><div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?></div><?php endif; ?>

    <div class="tabs-premium" id="tabsContainer">
        <a href="?folder=<?php echo $current_folder; ?>&show_archived=0&view=<?php echo $view_mode; ?>" class="tab-premium <?php echo $show_archived=='0'?'active':''; ?>">
            <i class="fas fa-folder-open"></i> Active
            <span class="badge bg-primary" id="activeFilesCount">📄<?php echo (int)$active_total; ?></span>
            <span class="badge bg-secondary" id="activeFoldersCount">📁<?php echo (int)$active_folders_total; ?></span>
        </a>
        <a href="?folder=<?php echo $current_folder; ?>&show_archived=1&view=<?php echo $view_mode; ?>" class="tab-premium <?php echo $show_archived=='1'?'active':''; ?>">
            <i class="fas fa-archive"></i> Archived
            <span class="badge bg-secondary" id="archivedFilesCount">📄<?php echo (int)$archived_total; ?></span>
            <span class="badge bg-secondary" id="archivedFoldersCount">📁<?php echo (int)$archived_folders_total; ?></span>
        </a>
    </div>

    <?php if($show_archived=='0'): ?>
    <div class="action-buttons-premium">
        <button class="btn-new-folder-premium" onclick="openNewFolderModal()"><i class="fas fa-folder-plus"></i> New Folder</button>
        <button class="btn-upload-premium" onclick="openUploadModal()"><i class="fas fa-cloud-upload-alt"></i> Upload File</button>
        <?php if(function_exists('isAdmin') && isAdmin()): ?>
        <a href="trash_bin.php" class="btn-trash">
            <i class="fas fa-trash-alt"></i> Trash Bin
            <?php if($trash_count > 0): ?>
                <span class="trash-badge"><?php echo (int)$trash_count; ?></span>
            <?php endif; ?>
        </a>
        <?php endif; ?>
        <?php if(count($files) > 0): ?>
        <button class="btn-download-all" onclick="downloadAllFiles()"><i class="fas fa-download"></i> Download All (<?php echo count($files); ?> files)</button>
        <button class="btn-print-all" onclick="printAllFiles()"><i class="fas fa-print"></i> Print All (<?php echo count($files); ?> docs)</button>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="search-container-enhanced">
        <div class="search-wrapper-enhanced">
            <i class="fas fa-search search-icon"></i>
            <input type="text" id="searchInput" placeholder="Search files, control numbers..." value="<?php echo htmlspecialchars($search); ?>">
            <button class="clear-search <?php echo !empty($search)?'visible':''; ?>" id="clearSearchBtn" onclick="clearSearch()"><i class="fas fa-times"></i></button>
        </div>
        <button class="search-by-cn-btn" onclick="openCNSearch()"><i class="fas fa-hashtag"></i> Search by Control No.</button>
    </div>

    <?php if(empty($subfolders) && empty($files)): ?>
        <div class="empty-state-premium"><div class="empty-icon-premium"><i class="fas fa-folder-open"></i></div><h4><?php echo !empty($search)?'No matching files found':'This folder is empty'; ?></h4><p><?php echo !empty($search)?'Try a different search term':'Upload files or create a folder!'; ?></p></div>
    <?php elseif($view_mode=='grid'): ?>
        <div class="grid-view-premium">
            <?php foreach($subfolders as $folder): ?>
                <div class="grid-item-premium" data-folder-id="<?php echo (int)$folder['id']; ?>" data-folder-type="folder" ondblclick="location.href='?folder=<?php echo (int)$folder['id']; ?><?php echo $show_archived=='1'?'&show_archived=1':''; ?>'">
                    <div class="action-menu">
                        <button class="action-icon edit" onclick="event.stopPropagation();renameFolder(<?php echo (int)$folder['id'];?>,'<?php echo htmlspecialchars($folder['folder_name'],ENT_QUOTES);?>')"><i class="fas fa-pen"></i></button>
                        <?php if($show_archived=='1'): ?>
                            <button class="action-icon archive" onclick="event.stopPropagation();restoreFolder(<?php echo (int)$folder['id'];?>)"><i class="fas fa-undo"></i></button>
                        <?php else: ?>
                            <button class="action-icon archive" onclick="event.stopPropagation();archiveFolder(<?php echo (int)$folder['id'];?>)"><i class="fas fa-archive"></i></button>
                        <?php endif; ?>
                        <button class="action-icon trash" onclick="event.stopPropagation();deleteFolder(<?php echo (int)$folder['id'];?>)"><i class="fas fa-trash"></i></button>
                    </div>
                    <div class="grid-icon-premium" onclick="location.href='?folder=<?php echo (int)$folder['id']; ?><?php echo $show_archived=='1'?'&show_archived=1':''; ?>'"><i class="fas fa-folder" style="color:#fbbf24;"></i></div>
                    <div class="grid-name-premium"><?php echo htmlspecialchars($folder['folder_name']);?></div>
                    <div class="grid-meta-premium"><?php echo !empty($folder['created_at']) ? date('M d, Y', strtotime($folder['created_at'])) : '-';?></div>
                </div>
            <?php endforeach; ?>

            <?php foreach($files as $file): ?>
                <div class="grid-item-premium" data-file-id="<?php echo (int)$file['id']; ?>" onclick="viewFile(<?php echo (int)$file['id'];?>)">
                    <div class="action-menu">
                        <button class="action-icon print" onclick="event.stopPropagation();printSingleFile(<?php echo (int)$file['id'];?>)" title="Print this document"><i class="fas fa-print"></i></button>
                        <button class="action-icon download" onclick="event.stopPropagation();downloadFile(<?php echo (int)$file['id'];?>)" title="Download"><i class="fas fa-download"></i></button>
                        <button class="action-icon edit" onclick="event.stopPropagation();renameFile(<?php echo (int)$file['id'];?>,'<?php echo htmlspecialchars($file['file_name'],ENT_QUOTES);?>')" title="Rename"><i class="fas fa-pen"></i></button>
                        <button class="action-icon qr" onclick="event.stopPropagation();openQRCode(<?php echo (int)$file['id'];?>)" title="Show QR Code"><i class="fas fa-qrcode"></i></button>
                        <?php if($show_archived=='1'): ?>
                            <button class="action-icon archive" onclick="event.stopPropagation();restoreFile(<?php echo (int)$file['id'];?>)" title="Restore"><i class="fas fa-undo"></i></button>
                        <?php else: ?>
                            <button class="action-icon archive" onclick="event.stopPropagation();archiveFile(<?php echo (int)$file['id'];?>)" title="Archive"><i class="fas fa-archive"></i></button>
                        <?php endif; ?>
                        <button class="action-icon trash" onclick="event.stopPropagation();deleteFile(<?php echo (int)$file['id'];?>)" title="Delete"><i class="fas fa-trash"></i></button>
                    </div>
                    <?php if(!empty($file['control_number'])): ?><div class="control-number-top"><?php echo htmlspecialchars($file['control_number']);?></div><?php endif; ?>
                    <div class="grid-icon-premium">
                        <?php
                        $ext = strtolower($file['file_extension'] ?? '');
                        $icon = 'fa-file'; $color = '#94a3b8';
                        if($ext=='pdf'){$icon='fa-file-pdf';$color='#ef4444';}
                        elseif(in_array($ext,['doc','docx'])){$icon='fa-file-word';$color='#3b82f6';}
                        elseif(in_array($ext,['xls','xlsx'])){$icon='fa-file-excel';$color='#10b981';}
                        elseif(in_array($ext,['jpg','jpeg','png','gif'])){$icon='fa-file-image';$color='#f59e0b';}
                        ?>
                        <i class="fas <?php echo $icon;?>" style="color:<?php echo $color;?>;"></i>
                    </div>
                    <div class="grid-name-premium"><?php echo htmlspecialchars($file['file_name']);?></div>
                    <div class="grid-meta-premium">
                        <?php echo !empty($file['created_at']) ? date('M d, Y', strtotime($file['created_at'])) : '-';?>
                        <?php if(isset($file['view_count']) && $file['view_count'] > 0): ?>
                            <br><i class="fas fa-eye"></i> <?php echo (int)$file['view_count']; ?> views
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="selectAll" class="checkbox-select" onclick="toggleSelectAll()"></th>
                        <th>QR Code</th>
                        <th>Control No.</th>
                        <th>Name</th>
                        <th>Title</th>
                        <th>Type</th>
                        <th>Size</th>
                        <th>Views</th>
                        <th>Modified</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($subfolders as $folder): ?>
                        <tr ondblclick="location.href='?folder=<?php echo (int)$folder['id']; ?><?php echo $show_archived=='1'?'&show_archived=1':''; ?>'">
                            <td><input type="checkbox" class="checkbox-select folder-checkbox" value="folder_<?php echo (int)$folder['id']; ?>" disabled></td>
                            <td><span class="text-muted">-</span></td>
                            <td><span class="text-muted">-</span></td>
                            <td><a href="?folder=<?php echo (int)$folder['id'];?>" style="text-decoration:none;color:#2d3748;"><i class="fas fa-folder" style="color:#fbbf24;margin-right:10px;"></i><?php echo htmlspecialchars($folder['folder_name']);?></a></td>
                            <td>-</td>
                            <td>Folder</td>
                            <td>-</td>
                            <td>-</td>
                            <td><?php echo !empty($folder['created_at']) ? date('M d, Y', strtotime($folder['created_at'])) : '-';?></td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary" onclick="event.stopPropagation();renameFolder(<?php echo (int)$folder['id'];?>,'<?php echo htmlspecialchars($folder['folder_name'],ENT_QUOTES);?>')"><i class="fas fa-pen"></i></button>
                                <?php if($show_archived=='1'): ?>
                                    <button class="btn btn-sm btn-outline-success" onclick="event.stopPropagation();restoreFolder(<?php echo (int)$folder['id'];?>)"><i class="fas fa-undo"></i></button>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-outline-warning" onclick="event.stopPropagation();archiveFolder(<?php echo (int)$folder['id'];?>)"><i class="fas fa-archive"></i></button>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-outline-danger" onclick="event.stopPropagation();deleteFolder(<?php echo (int)$folder['id'];?>)"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach($files as $file): ?>
                        <tr onclick="viewFile(<?php echo (int)$file['id'];?>)">
                            <td><input type="checkbox" class="checkbox-select file-checkbox" value="<?php echo (int)$file['id']; ?>" onclick="event.stopPropagation()"></td>
                            <td>
                                <?php if(!empty($file['control_number'])): ?>
                                    <div class="qr-preview-card">
                                        <img src="<?php echo getQRCodeUrl($file['id']); ?>" alt="QR" class="qr-thumb">
                                        <div class="qr-label"><?php echo htmlspecialchars($file['control_number']); ?></div>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?php if(!empty($file['control_number'])):?><span class="cn-badge-list"><?php echo htmlspecialchars($file['control_number']);?></span><?php else:?><span class="text-muted">-</span><?php endif;?></td>
                            <td>
                                <?php
                                $ext = strtolower($file['file_extension'] ?? '');
                                $icon = 'fa-file'; $color = '#94a3b8';
                                if($ext=='pdf'){$icon='fa-file-pdf';$color='#ef4444';}
                                elseif(in_array($ext,['doc','docx'])){$icon='fa-file-word';$color='#3b82f6';}
                                elseif(in_array($ext,['xls','xlsx'])){$icon='fa-file-excel';$color='#10b981';}
                                elseif(in_array($ext,['jpg','jpeg','png','gif'])){$icon='fa-file-image';$color='#f59e0b';}
                                ?>
                                <i class="fas <?php echo $icon;?>" style="color:<?php echo $color;?>;margin-right:8px;"></i>
                                <?php echo htmlspecialchars($file['file_name']);?>
                            </td>
                            <td><?php echo htmlspecialchars($file['title_of_program'] ?? '-');?></td>
                            <td><?php echo strtoupper($ext);?></td>
                            <td><?php echo isset($file['file_size']) ? ((int)$file['file_size'] . ' KB') : '-';?></td>
                            <td><?php echo isset($file['view_count']) ? (int)$file['view_count'] : 0;?></td>
                            <td><?php echo !empty($file['updated_at']) ? date('M d, Y', strtotime($file['updated_at'])) : (!empty($file['created_at']) ? date('M d, Y', strtotime($file['created_at'])) : '-');?></td>
                            <td>
                                <button class="btn btn-sm btn-outline-print" onclick="event.stopPropagation();printSingleFile(<?php echo (int)$file['id'];?>)" title="Print this document"><i class="fas fa-print"></i></button>
                                <button class="btn btn-sm btn-outline-info" onclick="event.stopPropagation();downloadFile(<?php echo (int)$file['id'];?>)" title="Download"><i class="fas fa-download"></i></button>
                                <button class="btn btn-sm btn-outline-primary" onclick="event.stopPropagation();renameFile(<?php echo (int)$file['id'];?>,'<?php echo htmlspecialchars($file['file_name'],ENT_QUOTES);?>')" title="Rename"><i class="fas fa-pen"></i></button>
                                <button class="btn btn-sm btn-outline-success" onclick="event.stopPropagation();openQRCode(<?php echo (int)$file['id'];?>)" title="QR Code"><i class="fas fa-qrcode"></i></button>
                                <?php if($show_archived=='1'): ?>
                                    <button class="btn btn-sm btn-outline-success" onclick="event.stopPropagation();restoreFile(<?php echo (int)$file['id'];?>)" title="Restore"><i class="fas fa-undo"></i></button>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-outline-warning" onclick="event.stopPropagation();archiveFile(<?php echo (int)$file['id'];?>)" title="Archive"><i class="fas fa-archive"></i></button>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-outline-danger" onclick="event.stopPropagation();deleteFile(<?php echo (int)$file['id'];?>)" title="Delete"><i class="fas fa-trash"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Bulk Actions Bar -->
<div id="bulkActionsBar" class="bulk-actions-bar">
    <span id="selectedCount">0</span> files selected
    <button class="btn btn-sm btn-success" onclick="downloadSelected()"><i class="fas fa-download"></i> Download Selected</button>
    <button class="btn btn-sm btn-warning" onclick="printSelected()"><i class="fas fa-print"></i> Print Selected</button>
    <button class="btn btn-sm btn-secondary" onclick="clearSelection()"><i class="fas fa-times"></i> Cancel</button>
</div>

<!-- NEW FOLDER MODAL -->
<div class="modal fade" id="newFolderModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title"><i class="fas fa-folder-plus"></i> Create New Folder</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><form action="create_folder.php" method="POST"><input type="hidden" name="parent_id" value="<?php echo $current_folder;?>"><div class="modal-body"><div class="mb-3"><label class="form-label">Folder Name</label><input type="text" class="form-control" name="folder_name" required autofocus></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary">Create Folder</button></div></form></div></div></div>

<!-- UPLOAD MODAL -->
<div class="modal fade" id="uploadModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header" style="background:linear-gradient(135deg,#11998e,#38ef7d);color:#fff;"><h5 class="modal-title"><i class="fas fa-cloud-upload-alt"></i> Upload Document</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><form id="uploadForm" action="upload_file.php" method="POST" enctype="multipart/form-data"><input type="hidden" name="folder_id" value="<?php echo $current_folder;?>"><div class="modal-body">
    <div class="cn-upload-field">
        <label class="form-label"><i class="fas fa-hashtag"></i> Control Number <span style="font-size:11px;color:#94a3b8;">(Auto if blank)</span></label>
        <input type="text" class="form-control" name="custom_control_number" id="custom_control_number" placeholder="e.g., USAT-<?php echo str_replace(' ', '', $root_folder_name); ?>-0001">
    </div>
    <div class="mb-3">
        <label class="form-label">Select File <span class="text-danger">*</span></label>
        <input type="file" class="form-control" name="file" id="upload_file" required accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif">
        <small class="text-muted">Max: 10MB | QR code will be generated automatically</small>
    </div>
    <div class="mb-3">
        <label class="form-label">Title of Program <span class="text-danger">*</span></label>
        <input type="text" class="form-control" name="title_of_program" id="title_of_program" required placeholder="e.g., Medical Mission 2024">
    </div>
    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">Date Conducted <span class="text-danger">*</span></label>
            <input type="date" name="date_conducted" id="date_conducted" class="form-control" required>
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">Type of Community Service <span class="text-danger">*</span></label>
            <input type="text" name="type_of_community_service" id="type_of_community_service" class="form-control" required placeholder="e.g., Medical Mission">
        </div>
    </div>
    <div class="row">
        <div class="col-md-6 mb-3">
            <label class="form-label">No. of Beneficiaries <span class="text-danger">*</span></label>
            <input type="number" name="no_of_beneficiaries" id="no_of_beneficiaries" class="form-control" required placeholder="e.g., 150">
        </div>
        <div class="col-md-6 mb-3">
            <label class="form-label">Community Served <span class="text-danger">*</span></label>
            <input type="text" name="community_population_served" id="community_population_served" class="form-control" required placeholder="e.g., Barangay San Juan">
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label">Description</label>
        <textarea class="form-control" name="description" id="description" rows="2" placeholder="Brief description..."></textarea>
    </div>
    <div id="uploadQRPreview" style="display:none;" class="qr-upload-success">
        <i class="fas fa-qrcode fa-2x mb-2" style="color:#059669;"></i>
        <p class="mb-1 fw-bold">✓ Document uploaded successfully!</p>
        <p class="small">QR code has been generated</p>
        <div id="previewQRImage"></div>
    </div>
</div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-success" id="uploadSubmitBtn">Upload</button></div></form></div></div></div>

<!-- QR MODAL -->
<div class="modal fade" id="qrModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header" style="background:linear-gradient(135deg,#10b981,#059669);color:#fff;"><h5 class="modal-title"><i class="fas fa-qrcode"></i> Document QR Code</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body" id="qrModalBody"><div class="text-center py-4"><div class="spinner-border text-success"></div><p class="mt-2">Loading QR Code...</p></div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button><a href="#" id="downloadQRBtn" class="btn btn-primary" download style="display:none;">Download QR</a></div></div></div></div>

<!-- CN SEARCH MODAL -->
<div class="modal fade" id="cnSearchModal" tabindex="-1"><div class="modal-dialog"><div class="modal-content"><div class="modal-header" style="background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;"><h5 class="modal-title"><i class="fas fa-hashtag"></i> Search by Control Number</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body"><p class="text-muted mb-3">Enter control number to find document</p><div class="cn-input-group"><input type="text" id="cnSearchInput" placeholder="USAT-<?php echo str_replace(' ', '', $root_folder_name); ?>-0001" autocomplete="off"><button onclick="searchByCN()"><i class="fas fa-search"></i> Find</button></div><?php if(!empty($recentCN)):?><div class="mt-4"><small class="text-muted"><i class="fas fa-clock"></i> Recent</small><div class="recent-cn-list"><?php foreach($recentCN as $rcn):?><span class="recent-cn-item" onclick="quickCNSearch('<?php echo htmlspecialchars($rcn['control_number']);?>')"><?php echo htmlspecialchars($rcn['control_number']);?></span><?php endforeach;?></div></div><?php endif;?></div></div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script>
// =============================================
// VARIABLES
// =============================================
let newFolderModal, uploadModal, qrModal, cnSearchModal;
let lastUploadedFileId = null;
let lastUploadedQRDataUrl = null;
let selectedFiles = [];

// All files in current folder (PHP-injected)
const ALL_FILES = <?php echo json_encode(array_map(function($f) {
    return [
        'id' => (int)$f['id'],
        'file_name' => $f['file_name'] ?? '',
        'control_number' => $f['control_number'] ?? ''
    ];
}, $files)); ?>;

// =============================================
// DOCUMENT READY
// =============================================
document.addEventListener('DOMContentLoaded', function() {
    newFolderModal = new bootstrap.Modal(document.getElementById('newFolderModal'));
    uploadModal = new bootstrap.Modal(document.getElementById('uploadModal'));
    qrModal = new bootstrap.Modal(document.getElementById('qrModal'));
    cnSearchModal = new bootstrap.Modal(document.getElementById('cnSearchModal'));

    const si = document.getElementById('searchInput');
    const csb = document.getElementById('clearSearchBtn');

    if (si.value.trim()) csb.classList.add('visible');

    si.addEventListener('input', function() {
        if (this.value.trim()) csb.classList.add('visible');
        else csb.classList.remove('visible');
    });

    si.addEventListener('keypress', function(e) {
        if (e.key === 'Enter') performSearch(this.value);
    });

    document.getElementById('cnSearchModal').addEventListener('shown.bs.modal', function() {
        document.getElementById('cnSearchInput').focus();
    });

    document.getElementById('cnSearchInput').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') searchByCN();
    });

    setupFileSelection();

    const uploadForm = document.getElementById('uploadForm');
    if (uploadForm) {
        uploadForm.addEventListener('submit', function(e) {
            e.preventDefault();

            const fileInput = document.getElementById('upload_file');
            if (!fileInput.files || !fileInput.files[0]) {
                alert('Please select a file to upload');
                return;
            }

            const formData = new FormData(uploadForm);
            const submitBtn = document.getElementById('uploadSubmitBtn');
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
            submitBtn.disabled = true;

            fetch('upload_file.php', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    lastUploadedFileId = data.file_id;
                    lastUploadedQRDataUrl = data.qr_code;

                    const previewDiv = document.getElementById('uploadQRPreview');
                    const previewImgDiv = document.getElementById('previewQRImage');

                    if (previewImgDiv) {
                        previewImgDiv.innerHTML = `<div class="text-center"><p class="small text-muted mb-2">Control Number: <strong>${escapeHtml(data.control_number)}</strong></p><img src="${data.qr_code}" class="qr-img" style="max-width:180px;margin:10px auto;border:2px solid #fff;border-radius:16px;box-shadow:0 4px 12px rgba(0,0,0,.1);"><p class="small mt-2 text-muted">QR code has been generated</p></div>`;
                    }

                    previewDiv.style.display = 'block';
                    submitBtn.innerHTML = 'Upload Another File';
                    submitBtn.disabled = false;

                    document.getElementById('custom_control_number').value = '';
                    document.getElementById('upload_file').value = '';
                    document.getElementById('title_of_program').value = '';
                    document.getElementById('date_conducted').value = '';
                    document.getElementById('type_of_community_service').value = '';
                    document.getElementById('community_population_served').value = '';
                    document.getElementById('no_of_beneficiaries').value = '';
                    document.getElementById('description').value = '';

                    setTimeout(function() { location.reload(); }, 2000);
                } else {
                    alert('Upload failed: ' + (data.error || 'Unknown error'));
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Upload failed. Please check console for errors.');
                submitBtn.innerHTML = originalText;
                submitBtn.disabled = false;
            });
        });
    }

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('success') === 'file_uploaded' && urlParams.get('show_qr')) {
        setTimeout(function() { showQRCode(parseInt(urlParams.get('show_qr'))); }, 500);
    }

    setTimeout(function() {
        document.querySelectorAll('.alert').forEach(function(a) {
            setTimeout(function() {
                a.style.opacity = '0';
                setTimeout(function() { if(a.parentNode) a.remove(); }, 500);
            }, 5000);
        });
    }, 100);
});

// =============================================
// TOAST NOTIFICATION
// =============================================
function showToast(message) {
    const existingToast = document.querySelector('.toast-notification');
    if (existingToast) existingToast.remove();

    const toast = document.createElement('div');
    toast.className = 'toast-notification';
    toast.textContent = message;
    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transition = 'opacity 0.3s';
        setTimeout(() => { if (toast.parentNode) toast.remove(); }, 300);
    }, 2500);
}

// =============================================
// PRINT FUNCTIONS
// =============================================
function printSingleFile(fileId) {
    showLoading('Preparing document...');

    fetch('get_document_for_print.php?id=' + fileId, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.text())
    .then(text => {
        hideLoading();
        let doc;
        try {
            doc = JSON.parse(text);
        } catch (e) {
            console.error('Raw response:', text.substring(0, 500));
            alert('Invalid response from server. Check console (F12).');
            return;
        }

        if (!doc.success) {
            alert('Failed to load document: ' + (doc.error || 'Unknown error'));
            return;
        }

        openPrintWindow([doc]);
    })
    .catch(err => {
        hideLoading();
        console.error(err);
        alert('Failed to prepare document for printing');
    });
}

function printSelected() {
    if (selectedFiles.length === 0) { alert('No files selected'); return; }
    if (selectedFiles.length === 1) { printSingleFile(selectedFiles[0]); return; }
    printMultipleFiles(selectedFiles);
}

function printAllFiles() {
    const fileIds = ALL_FILES.map(f => f.id);
    if (fileIds.length === 0) { alert('No documents to print'); return; }
    printMultipleFiles(fileIds);
}

function printMultipleFiles(fileIds) {
    showLoading('Preparing ' + fileIds.length + ' document(s) for printing...');

    const promises = fileIds.map(id =>
        fetch('get_document_for_print.php?id=' + id, {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => {
            if (!r.ok) throw new Error('HTTP ' + r.status + ' for file ' + id);
            return r.text();
        })
        .then(text => {
            try { return JSON.parse(text); }
            catch (e) {
                console.error('Invalid JSON for file', id, ':', text.substring(0, 500));
                return { error: 'Invalid JSON response for file ' + id };
            }
        })
        .catch(err => {
            console.error('Fetch error for file', id, ':', err);
            return { error: err.message };
        })
    );

    Promise.all(promises)
    .then(docs => {
        hideLoading();
        const validDocs = docs.filter(d => d && d.success === true);
        const failed = docs.filter(d => d && d.success !== true);

        if (validDocs.length === 0) {
            let errorMsg = 'Failed to load documents for printing.\n\n';
            if (failed.length > 0 && failed[0].error) {
                errorMsg += 'Reason: ' + failed[0].error + '\n\n';
            }
            alert(errorMsg + 'Check browser console (F12) for details.');
            return;
        }

        if (failed.length > 0) {
            console.warn('Some documents failed to load:', failed);
        }

        openPrintWindow(validDocs);
    })
    .catch(err => {
        hideLoading();
        console.error('Unexpected error:', err);
        alert('Unexpected error: ' + err.message);
    });
}

function openPrintWindow(documents) {
    const printWindow = window.open('', '_blank', 'width=900,height=700,scrollbars=yes');
    if (!printWindow) {
        alert('Please allow pop-ups to print documents');
        return;
    }

    let html = `<!DOCTYPE html>
    <html>
    <head>
        <title>Print Documents - USAT Archive System</title>
        <meta charset="UTF-8">
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: 'Arial', sans-serif; background: white; padding: 8px; }
            .print-container { max-width: 100%; margin: 0 auto; }
            .print-document { background: white; margin-bottom: 10px; page-break-inside: avoid; border: 1px solid #ddd; border-radius: 4px; overflow: hidden; }
            .print-header { background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 6px 12px; }
            .print-header h2 { margin: 0; font-size: 12px; font-weight: bold; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .print-header .control-number { display: inline-block; background: rgba(255,255,255,0.2); padding: 2px 8px; border-radius: 10px; font-size: 9px; font-family: monospace; margin-top: 3px; }
            .print-body { padding: 6px 10px; }
            .document-info { background: #f9fafb; border-radius: 4px; padding: 5px 10px; margin-bottom: 6px; border: 1px solid #e5e7eb; }
            .info-row { display: flex; margin-bottom: 2px; font-size: 9px; line-height: 1.3; }
            .info-label { width: 95px; font-weight: 600; color: #374151; }
            .info-value { flex: 1; color: #1f2937; }
            .qr-section { text-align: center; margin-top: 5px; padding: 5px 10px; background: #f0fdf4; border-radius: 5px; border: 1px solid #bbf7d0; display: flex; align-items: center; justify-content: space-between; }
            .qr-section img { max-width: 50px; height: auto; margin: 0; border: 1px solid #ddd; border-radius: 4px; padding: 2px; background: white; }
            .qr-section p { color: #166534; font-size: 8px; margin: 0; flex: 1; text-align: left; padding-left: 8px; }
            .print-footer { padding: 4px 10px; background: #f9fafb; border-top: 1px solid #e5e7eb; font-size: 7px; color: #6b7280; display: flex; justify-content: space-between; }
            @media print {
                body { padding: 0; margin: 0; }
                .print-container { display: block; width: 100%; }
                .print-document { display: inline-block; width: 49%; margin-right: 1%; vertical-align: top; page-break-inside: avoid; break-inside: avoid; }
                @page { size: portrait; margin: 0.2in; }
                .print-header { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                .qr-section { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            }
        </style>
    </head>
    <body>
        <div class="print-container">`;

    documents.forEach((doc) => {
        const fileName = doc.file_name || 'Untitled';
        const controlNum = doc.control_number || 'N/A';
        const title = doc.title_of_program || 'N/A';
        const dateConducted = doc.date_conducted || 'N/A';
        const serviceType = doc.type_of_service || 'N/A';
        const community = doc.community_served || 'N/A';
        const beneficiaries = doc.beneficiaries || 'N/A';
        const qrCode = doc.qr_code || '';

        html += `
            <div class="print-document">
                <div class="print-header">
                    <h2 title="${escapeHtml(fileName)}">${escapeHtml(String(fileName).substring(0, 35))}${String(fileName).length > 35 ? '...' : ''}</h2>
                    <div class="control-number">${escapeHtml(controlNum)}</div>
                </div>
                <div class="print-body">
                    <div class="document-info">
                        <div class="info-row"><div class="info-label">Title:</div><div class="info-value">${escapeHtml(String(title).substring(0, 40))}</div></div>
                        <div class="info-row"><div class="info-label">Date:</div><div class="info-value">${escapeHtml(String(dateConducted))}</div></div>
                        <div class="info-row"><div class="info-label">Service Type:</div><div class="info-value">${escapeHtml(String(serviceType).substring(0, 30))}</div></div>
                        <div class="info-row"><div class="info-label">Community:</div><div class="info-value">${escapeHtml(String(community).substring(0, 30))}</div></div>
                        <div class="info-row"><div class="info-label">Beneficiaries:</div><div class="info-value">${escapeHtml(String(beneficiaries))}</div></div>
                    </div>
                    ${qrCode ? `
                    <div class="qr-section">
                        <img src="${qrCode}" alt="QR">
                        <p>Scan QR to access digital copy | Control: ${escapeHtml(controlNum)}</p>
                    </div>` : ''}
                </div>
                <div class="print-footer">
                    <span>USAT Archive</span>
                    <span>${new Date().toLocaleDateString()}</span>
                </div>
            </div>`;
    });

    html += `</div><script>window.onload=function(){setTimeout(function(){window.print();},300);};<\/script></body></html>`;

    printWindow.document.write(html);
    printWindow.document.close();
}

// =============================================
// FILE SELECTION
// =============================================
function setupFileSelection() {
    const checkboxes = document.querySelectorAll('.file-checkbox');
    checkboxes.forEach(cb => {
        cb.addEventListener('change', function(e) {
            e.stopPropagation();
            updateSelectedFiles();
        });
    });
}

function updateSelectedFiles() {
    const checkboxes = document.querySelectorAll('.file-checkbox:checked');
    selectedFiles = Array.from(checkboxes).map(cb => cb.value);
    const bar = document.getElementById('bulkActionsBar');
    const countSpan = document.getElementById('selectedCount');
    if (selectedFiles.length > 0) {
        countSpan.innerHTML = selectedFiles.length;
        bar.style.display = 'flex';
    } else {
        bar.style.display = 'none';
    }
}

function toggleSelectAll() {
    const selectAll = document.getElementById('selectAll');
    const checkboxes = document.querySelectorAll('.file-checkbox');
    checkboxes.forEach(cb => { cb.checked = selectAll.checked; });
    updateSelectedFiles();
}

function clearSelection() {
    const checkboxes = document.querySelectorAll('.file-checkbox');
    checkboxes.forEach(cb => { cb.checked = false; });
    const selectAll = document.getElementById('selectAll');
    if (selectAll) selectAll.checked = false;
    updateSelectedFiles();
}

// =============================================
// DOWNLOAD FUNCTIONS
// =============================================
function downloadSelected() {
    if (selectedFiles.length === 0) { alert('No files selected'); return; }
    if (selectedFiles.length === 1) { downloadFile(selectedFiles[0]); }
    else { downloadMultipleFiles(selectedFiles); }
}

function downloadMultipleFiles(fileIds) {
    const zip = new JSZip();
    let pending = fileIds.length;
    let hasError = false;
    showLoading('Preparing download...');

    fileIds.forEach(fileId => {
        fetch(`download_file.php?id=${fileId}&raw=1`)
            .then(response => {
                if (!response.ok) throw new Error('Download failed');
                return response.blob();
            })
            .then(blob => {
                zip.file(`document_${fileId}.pdf`, blob);
                pending--;
                if (pending === 0 && !hasError) {
                    zip.generateAsync({ type: 'blob' }).then(content => {
                        const link = document.createElement('a');
                        const url = URL.createObjectURL(content);
                        link.href = url;
                        link.download = `documents_${new Date().getTime()}.zip`;
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                        URL.revokeObjectURL(url);
                        hideLoading();
                        clearSelection();
                    });
                }
            })
            .catch(error => {
                console.error('Error:', error);
                hasError = true;
                hideLoading();
                alert('Error downloading some files');
            });
    });
}

function downloadAllFiles() {
    const fileIds = ALL_FILES.map(f => f.id);
    if (fileIds.length === 0) { alert('No files to download'); return; }
    downloadMultipleFiles(fileIds);
}

// =============================================
// LOADING
// =============================================
function showLoading(message) {
    let loader = document.getElementById('loadingOverlay');
    if (!loader) {
        loader = document.createElement('div');
        loader.id = 'loadingOverlay';
        loader.className = 'loading-overlay';
        loader.innerHTML = '<div style="text-align:center"><i class="fas fa-spinner fa-spin fa-3x"></i><p style="margin-top:15px">' + message + '</p></div>';
        document.body.appendChild(loader);
    } else {
        loader.style.display = 'flex';
        loader.querySelector('p').innerHTML = message;
    }
}

function hideLoading() {
    const loader = document.getElementById('loadingOverlay');
    if (loader) loader.style.display = 'none';
}

// =============================================
// SEARCH
// =============================================
function performSearch(q) { const u = new URL(window.location); if (q.trim()) u.searchParams.set('search', q.trim()); else u.searchParams.delete('search'); window.location = u.toString(); }
function clearSearch() { const u = new URL(window.location); u.searchParams.delete('search'); window.location = u.toString(); }
function openCNSearch() { cnSearchModal.show(); }
function searchByCN() { const v = document.getElementById('cnSearchInput').value.trim(); if (!v) { alert('Enter a control number'); return; } const u = new URL(window.location); u.searchParams.set('search', v); window.location = u.toString(); }
function quickCNSearch(cn) { document.getElementById('cnSearchInput').value = cn; searchByCN(); }

// =============================================
// MODAL FUNCTIONS
// =============================================
function openNewFolderModal() { newFolderModal.show(); }
function openUploadModal() { document.getElementById('uploadForm').reset(); document.getElementById('uploadQRPreview').style.display = 'none'; document.getElementById('uploadSubmitBtn').innerHTML = 'Upload'; document.getElementById('uploadSubmitBtn').disabled = false; uploadModal.show(); }
function viewFile(fileId) { window.location.href = 'view_document.php?id=' + fileId; }
function downloadFile(fileId) { window.location.href = 'download_file.php?id=' + fileId; }

// =============================================
// QR CODE
// =============================================
function openQRCode(fileId) {
    window.open('qr_code.php?id=' + fileId, '_blank', 'width=600,height=800,scrollbars=yes');
}

function showQRCode(fileId) {
    document.getElementById('qrModalBody').innerHTML = '<div class="text-center py-4"><div class="spinner-border text-success"></div><p class="mt-2">Loading QR Code...</p></div>';
    document.getElementById('downloadQRBtn').style.display = 'none';
    qrModal.show();

    fetch('get_qr.php?id=' + fileId)
        .then(r => r.json())
        .then(d => {
            if (d.success && d.qr_code) {
                const viewUrl = window.location.origin + window.location.pathname.replace('files.php', 'view_document.php?id=' + fileId);

                document.getElementById('qrModalBody').innerHTML = `
                    <div class="row">
                        <div class="col-md-6 text-center">
                            <div class="control-number-top" style="display:inline-block;margin-bottom:15px;font-size:14px;">${escapeHtml(d.control_number || 'N/A')}</div>
                            <img src="${d.qr_code}" class="qr-code-img" alt="QR Code" style="max-width:250px;margin:0 auto;border:1px solid #ddd;border-radius:10px;padding:10px;">
                            <p class="mt-3 text-muted small"><i class="fas fa-qrcode"></i> Scan to view document on any device</p>
                            <div class="mt-2">
                                <input type="text" class="form-control form-control-sm" value="${viewUrl}" readonly style="font-size:12px;text-align:center;">
                            </div>
                            <div class="mt-2">
                                <a href="qr_code.php?id=${fileId}" target="_blank" class="btn btn-sm btn-outline-primary">
                                    <i class="fas fa-external-link-alt"></i> Open Full QR
                                </a>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h6>Document Details</h6>
                            <table class="table table-sm table-borderless">
                                <tr><td><strong>Control Number:</strong></td><td>${escapeHtml(d.control_number || 'N/A')}</td></tr>
                                <tr><td><strong>File Name:</strong></td><td>${escapeHtml(d.file_name || 'N/A')}</td></tr>
                                <tr><td><strong>Title:</strong></td><td>${escapeHtml(d.title_of_program || 'N/A')}</td></tr>
                                <tr><td><strong>Date Conducted:</strong></td><td>${escapeHtml(d.date_conducted || 'N/A')}</td></tr>
                                <tr><td><strong>Service Type:</strong></td><td>${escapeHtml(d.type_of_community_service || 'N/A')}</td></tr>
                                <tr><td><strong>Community:</strong></td><td>${escapeHtml(d.community_population_served || 'N/A')}</td></tr>
                                <tr><td><strong>Beneficiaries:</strong></td><td>${escapeHtml(d.no_of_beneficiaries || '0')}</td></tr>
                                <tr><td><strong>Views:</strong></td><td>${escapeHtml(d.view_count || '0')}</td></tr>
                            </table>
                            <a href="${viewUrl}" class="btn btn-success btn-sm w-100" target="_blank">
                                <i class="fas fa-eye"></i> View Document
                            </a>
                        </div>
                    </div>
                `;
                document.getElementById('downloadQRBtn').href = d.qr_code;
                document.getElementById('downloadQRBtn').style.display = 'inline-block';
            } else {
                document.getElementById('qrModalBody').innerHTML = `
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> QR Code not available for this file.
                        <br><br>
                        <a href="qr_code.php?id=${fileId}" target="_blank" class="btn btn-primary">
                            <i class="fas fa-qrcode"></i> Generate QR Code
                        </a>
                    </div>
                `;
            }
        })
        .catch(() => {
            document.getElementById('qrModalBody').innerHTML = `
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> Error loading QR code.
                    <br><br>
                    <a href="qr_code.php?id=${fileId}" target="_blank" class="btn btn-primary">
                        <i class="fas fa-qrcode"></i> Open QR Code Page
                    </a>
                </div>
            `;
        });
}

// =============================================
// DELETE
// =============================================
function deleteFile(fileId) {
    if (confirm('⚠️ Are you sure you want to delete this file?\n\nThis action will move the file to the trash bin.')) {
        showLoading('Deleting file...');
        fetch('delete_file.php?id=' + fileId, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
        .then(response => response.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                showToast('✅ ' + data.message);
                updateCounts(<?php echo (int)$current_folder; ?>);
                setTimeout(function() { location.reload(); }, 800);
            } else {
                alert('❌ ' + (data.error || 'Error deleting file'));
            }
        })
        .catch(error => {
            hideLoading();
            console.error('Error:', error);
            alert('Error deleting file. Please check console for details.');
        });
    }
}

function deleteFolder(folderId) {
    if (confirm('⚠️ Are you sure you want to delete this folder and all its contents?\n\nThis action will move everything to the trash bin.')) {
        showLoading('Deleting folder...');
        fetch('delete_folder.php?id=' + folderId, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
        .then(response => response.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                showToast('✅ ' + data.message);
                updateCounts(<?php echo (int)$current_folder; ?>);
                setTimeout(function() { location.reload(); }, 800);
            } else {
                alert('❌ ' + (data.error || 'Error deleting folder'));
            }
        })
        .catch(error => {
            hideLoading();
            console.error('Error:', error);
            alert('Error deleting folder. Please check console for details.');
        });
    }
}

// =============================================
// ARCHIVE / RESTORE
// =============================================
function archiveFile(fileId) {
    if (confirm('Archive this file?')) {
        showLoading('Archiving file...');
        fetch('archive_file.php?id=' + fileId, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
        .then(response => response.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                showToast('✅ ' + data.message);
                updateCounts(<?php echo (int)$current_folder; ?>);
                setTimeout(function() { location.reload(); }, 800);
            } else { alert('❌ ' + (data.error || 'Error archiving file')); }
        })
        .catch(error => { hideLoading(); console.error('Error:', error); alert('Error archiving file.'); });
    }
}

function archiveFolder(folderId) {
    if (confirm('Archive this folder?')) {
        showLoading('Archiving folder...');
        fetch('archive_folder.php?id=' + folderId, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
        .then(response => response.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                showToast('✅ ' + data.message);
                updateCounts(<?php echo (int)$current_folder; ?>);
                setTimeout(function() { location.reload(); }, 800);
            } else { alert('❌ ' + (data.error || 'Error archiving folder')); }
        })
        .catch(error => { hideLoading(); console.error('Error:', error); alert('Error archiving folder.'); });
    }
}

function restoreFile(fileId) {
    if (confirm('Restore this file from archive? It will become active again.')) {
        showLoading('Restoring file...');
        fetch('restore_file.php?id=' + fileId, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
        .then(response => response.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                showToast('✅ ' + data.message);
                updateCounts(<?php echo (int)$current_folder; ?>);
                setTimeout(function() { location.reload(); }, 800);
            } else { alert('❌ ' + (data.error || 'Error restoring file')); }
        })
        .catch(error => { hideLoading(); console.error('Error:', error); alert('Error restoring file.'); });
    }
}

function restoreFolder(folderId) {
    if (confirm('Restore this folder from archive? It will become active again.')) {
        showLoading('Restoring folder...');
        fetch('restore_folder.php?id=' + folderId, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
        })
        .then(response => response.json())
        .then(data => {
            hideLoading();
            if (data.success) {
                showToast('✅ ' + data.message);
                updateCounts(<?php echo (int)$current_folder; ?>);
                setTimeout(function() { location.reload(); }, 800);
            } else { alert('❌ ' + (data.error || 'Error restoring folder')); }
        })
        .catch(error => { hideLoading(); console.error('Error:', error); alert('Error restoring folder.'); });
    }
}

// =============================================
// RENAME
// =============================================
function renameFile(id, name) {
    const newName = prompt('Enter new file name:', name);
    if (newName && newName.trim() && newName !== name) {
        showLoading('Renaming file...');
        fetch('rename_file.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'file_id=' + id + '&new_name=' + encodeURIComponent(newName.trim())
        })
        .then(r => r.json())
        .then(d => {
            hideLoading();
            if (d.success) { showToast('✅ File renamed successfully'); setTimeout(function() { location.reload(); }, 800); }
            else { alert(d.error || 'Error renaming file'); }
        })
        .catch(() => { hideLoading(); alert('Error renaming file'); });
    }
}

function renameFolder(id, name) {
    const newName = prompt('Enter new folder name:', name);
    if (newName && newName.trim() && newName !== name) {
        showLoading('Renaming folder...');
        fetch('rename_folder.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'folder_id=' + id + '&new_name=' + encodeURIComponent(newName.trim())
        })
        .then(r => r.json())
        .then(d => {
            hideLoading();
            if (d.success) { showToast('✅ Folder renamed successfully'); setTimeout(function() { location.reload(); }, 800); }
            else { alert(d.error || 'Error renaming folder'); }
        })
        .catch(() => { hideLoading(); alert('Error renaming folder'); });
    }
}

// =============================================
// UPDATE COUNTS
// =============================================
function updateCounts(folderId) {
    fetch('get_counts.php?folder=' + folderId, {
        method: 'GET',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            const activeFilesEl = document.getElementById('activeFilesCount');
            const activeFoldersEl = document.getElementById('activeFoldersCount');
            const archivedFilesEl = document.getElementById('archivedFilesCount');
            const archivedFoldersEl = document.getElementById('archivedFoldersCount');

            if (activeFilesEl) activeFilesEl.innerHTML = '📄' + data.active_files;
            if (activeFoldersEl) activeFoldersEl.innerHTML = '📁' + data.active_folders;
            if (archivedFilesEl) archivedFilesEl.innerHTML = '📄' + data.archived_files;
            if (archivedFoldersEl) archivedFoldersEl.innerHTML = '📁' + data.archived_folders;
        }
    })
    .catch(error => console.error('Error updating counts:', error));
}

// =============================================
// UTILITIES
// =============================================
function escapeHtml(t) {
    if (t === null || t === undefined) return '';
    const d = document.createElement('div');
    d.textContent = String(t);
    return d.innerHTML;
}
</script>
</body>
</html>