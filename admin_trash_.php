<?php
session_start();
ob_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if user is logged in and is admin
if (!isLoggedIn()) {
    header("Location: index.php");
    exit();
}

if (!isAdmin()) {
    $_SESSION['error'] = "Access denied. Only administrators can access the trash bin.";
    header("Location: files.php");
    exit();
}

// Handle actions
$success_message = '';
$error_message = '';

// Recover item
if (isset($_GET['action']) && $_GET['action'] == 'recover' && isset($_GET['id'])) {
    $trash_id = (int)$_GET['id'];
    
    try {
        $pdo->beginTransaction();
        
        // Get trash item info
        $stmt = $pdo->prepare("SELECT * FROM trash_bin WHERE id = ? AND status = 'deleted'");
        $stmt->execute([$trash_id]);
        $item = $stmt->fetch();
        
        if ($item) {
            if ($item['item_type'] == 'file') {
                // Recover file - set status back to active
                $stmt = $pdo->prepare("UPDATE files SET status = 'active', deleted_by = NULL, deleted_at = NULL WHERE id = ?");
                $stmt->execute([$item['item_id']]);
            } else {
                // Recover folder - set status back to active
                $stmt = $pdo->prepare("UPDATE folders SET status = 'active', deleted_by = NULL, deleted_at = NULL WHERE id = ?");
                $stmt->execute([$item['item_id']]);
                
                // Also recover all files and subfolders that were deleted with this folder
                $stmt = $pdo->prepare("UPDATE files SET status = 'active', deleted_by = NULL, deleted_at = NULL WHERE folder_id = ? AND status = 'deleted'");
                $stmt->execute([$item['item_id']]);
                
                $stmt = $pdo->prepare("UPDATE folders SET status = 'active', deleted_by = NULL, deleted_at = NULL WHERE parent_id = ? AND status = 'deleted'");
                $stmt->execute([$item['item_id']]);
            }
            
            // Update trash bin status
            $stmt = $pdo->prepare("UPDATE trash_bin SET status = 'recovered', recovered_at = NOW() WHERE id = ?");
            $stmt->execute([$trash_id]);
            
            // Also recover any child items in trash_bin
            if ($item['item_type'] == 'folder') {
                $stmt = $pdo->prepare("UPDATE trash_bin SET status = 'recovered', recovered_at = NOW() WHERE original_folder_id = ? AND status = 'deleted'");
                $stmt->execute([$item['item_id']]);
            }
            
            $pdo->commit();
            $success_message = "✓ {$item['item_type']} '{$item['item_name']}' recovered successfully!";
        } else {
            $error_message = "Item not found in trash!";
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_message = "Error recovering item: " . $e->getMessage();
    }
}

// Permanent delete
if (isset($_GET['action']) && $_GET['action'] == 'permanent' && isset($_GET['id'])) {
    $trash_id = (int)$_GET['id'];
    
    try {
        $pdo->beginTransaction();
        
        // Get trash item info
        $stmt = $pdo->prepare("SELECT * FROM trash_bin WHERE id = ?");
        $stmt->execute([$trash_id]);
        $item = $stmt->fetch();
        
        if ($item) {
            if ($item['item_type'] == 'file') {
                // Delete physical file from server
                if (!empty($item['item_path']) && file_exists(__DIR__ . '/' . $item['item_path'])) {
                    @unlink(__DIR__ . '/' . $item['item_path']);
                }
                // Delete QR code if exists
                $qr_path = str_replace('uploads/', 'qrcodes/', $item['item_path']);
                $qr_path = str_replace('.pdf', '.png', $qr_path);
                if (file_exists(__DIR__ . '/' . $qr_path)) {
                    @unlink(__DIR__ . '/' . $qr_path);
                }
                // Delete from files table
                $stmt = $pdo->prepare("DELETE FROM files WHERE id = ?");
                $stmt->execute([$item['item_id']]);
            } else {
                // Delete folder and all contents permanently
                deleteFolderPermanently($pdo, $item['item_id']);
            }
            
            // Update trash bin status
            $stmt = $pdo->prepare("UPDATE trash_bin SET status = 'permanent_deleted', permanent_deleted_at = NOW() WHERE id = ?");
            $stmt->execute([$trash_id]);
            
            $pdo->commit();
            $success_message = "✓ {$item['item_type']} permanently deleted!";
        } else {
            $error_message = "Item not found!";
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_message = "Error deleting item: " . $e->getMessage();
    }
}

// Empty trash (permanent delete all items in trash)
if (isset($_POST['empty_trash'])) {
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("SELECT * FROM trash_bin WHERE status = 'deleted'");
        $stmt->execute();
        $items = $stmt->fetchAll();
        
        foreach ($items as $item) {
            if ($item['item_type'] == 'file') {
                if (!empty($item['item_path']) && file_exists(__DIR__ . '/' . $item['item_path'])) {
                    @unlink(__DIR__ . '/' . $item['item_path']);
                }
                $stmt2 = $pdo->prepare("DELETE FROM files WHERE id = ?");
                $stmt2->execute([$item['item_id']]);
            } else {
                deleteFolderPermanently($pdo, $item['item_id']);
            }
        }
        
        $stmt = $pdo->prepare("UPDATE trash_bin SET status = 'permanent_deleted', permanent_deleted_at = NOW() WHERE status = 'deleted'");
        $stmt->execute();
        
        $pdo->commit();
        $success_message = "✓ Trash emptied successfully!";
    } catch (Exception $e) {
        $pdo->rollBack();
        $error_message = "Error emptying trash: " . $e->getMessage();
    }
}

// Get trash items with pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

$filter = isset($_GET['filter']) ? $_GET['filter'] : 'deleted';
$where_clause = "";
if ($filter == 'deleted') {
    $where_clause = " WHERE t.status = 'deleted'";
} elseif ($filter == 'recovered') {
    $where_clause = " WHERE t.status = 'recovered'";
} elseif ($filter == 'permanent') {
    $where_clause = " WHERE t.status = 'permanent_deleted'";
} elseif ($filter == 'all') {
    $where_clause = " WHERE 1=1";
}

// Get total count
$count_stmt = $pdo->prepare("SELECT COUNT(*) FROM trash_bin t" . $where_clause);
$count_stmt->execute();
$total_items = $count_stmt->fetchColumn();
$total_pages = ceil($total_items / $limit);

// Get items
$stmt = $pdo->prepare("
    SELECT t.*, u.full_name as deleter_name 
    FROM trash_bin t 
    LEFT JOIN users u ON t.deleted_by = u.id 
    $where_clause
    ORDER BY t.deleted_at DESC 
    LIMIT $limit OFFSET $offset
");
$stmt->execute();
$trash_items = $stmt->fetchAll();

// Get counts
$deleted_count = $pdo->query("SELECT COUNT(*) FROM trash_bin WHERE status = 'deleted'")->fetchColumn();
$recovered_count = $pdo->query("SELECT COUNT(*) FROM trash_bin WHERE status = 'recovered'")->fetchColumn();
$permanent_count = $pdo->query("SELECT COUNT(*) FROM trash_bin WHERE status = 'permanent_deleted'")->fetchColumn();
$all_count = $deleted_count + $recovered_count + $permanent_count;

function deleteFolderPermanently($pdo, $folder_id) {
    // Get all subfolders
    $stmt = $pdo->prepare("SELECT id FROM folders WHERE parent_id = ?");
    $stmt->execute([$folder_id]);
    $subfolders = $stmt->fetchAll();
    
    foreach ($subfolders as $subfolder) {
        deleteFolderPermanently($pdo, $subfolder['id']);
    }
    
    // Delete all files in this folder (physical files)
    $stmt = $pdo->prepare("SELECT file_path FROM files WHERE folder_id = ?");
    $stmt->execute([$folder_id]);
    $files = $stmt->fetchAll();
    
    foreach ($files as $file) {
        if (!empty($file['file_path']) && file_exists(__DIR__ . '/' . $file['file_path'])) {
            @unlink(__DIR__ . '/' . $file['file_path']);
        }
    }
    
    // Delete files from database
    $stmt = $pdo->prepare("DELETE FROM files WHERE folder_id = ?");
    $stmt->execute([$folder_id]);
    
    // Delete the folder
    $stmt = $pdo->prepare("DELETE FROM folders WHERE id = ?");
    $stmt->execute([$folder_id]);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Trash Bin - USAT Archive System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
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
        }
        
        .main-content {
            margin-left: 280px;
            padding: 30px;
            min-height: 100vh;
        }
        
        .trash-container {
            background: white;
            border-radius: 24px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }
        
        .page-header h1 {
            font-size: 28px;
            font-weight: 700;
            color: #1a1a2e;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .page-header h1 i {
            color: #ef4444;
            font-size: 32px;
        }
        
        .admin-badge {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .filter-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }
        
        .filter-tab {
            padding: 10px 20px;
            border-radius: 30px;
            text-decoration: none;
            color: #64748b;
            font-weight: 500;
            transition: all 0.3s;
            background: #f1f5f9;
        }
        
        .filter-tab:hover {
            background: #e2e8f0;
            color: #1a1a2e;
        }
        
        .filter-tab.active {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
        }
        
        .filter-tab .count {
            background: rgba(255,255,255,0.2);
            padding: 2px 8px;
            border-radius: 20px;
            margin-left: 8px;
            font-size: 12px;
        }
        
        .filter-tab.active .count {
            background: rgba(255,255,255,0.3);
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        .table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .table thead th {
            background: #f8fafc;
            padding: 15px;
            font-weight: 600;
            color: #475569;
            border-bottom: 2px solid #e2e8f0;
            text-align: left;
        }
        
        .table tbody tr {
            border-bottom: 1px solid #e2e8f0;
            transition: all 0.3s;
        }
        
        .table tbody tr:hover {
            background: #f8fafc;
        }
        
        .table td {
            padding: 15px;
            vertical-align: middle;
        }
        
        .item-type {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .item-type i {
            width: 24px;
            font-size: 18px;
        }
        
        .fa-folder {
            color: #f59e0b;
        }
        
        .fa-file {
            color: #64748b;
        }
        
        .badge {
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .badge-deleted {
            background: #fee2e2;
            color: #dc2626;
        }
        
        .badge-recovered {
            background: #d1fae5;
            color: #059669;
        }
        
        .badge-permanent {
            background: #e2e8f0;
            color: #475569;
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .btn-action {
            padding: 6px 12px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-size: 12px;
            font-weight: 500;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-recover {
            background: #10b981;
            color: white;
        }
        
        .btn-recover:hover {
            background: #059669;
            transform: translateY(-1px);
        }
        
        .btn-permanent {
            background: #ef4444;
            color: white;
        }
        
        .btn-permanent:hover {
            background: #dc2626;
            transform: translateY(-1px);
        }
        
        .btn-empty {
            background: #ef4444;
            color: white;
            padding: 10px 20px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-empty:hover {
            background: #dc2626;
            transform: translateY(-2px);
        }
        
        .btn-back {
            background: #64748b;
            color: white;
            padding: 10px 20px;
            border-radius: 12px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .btn-back:hover {
            background: #475569;
            transform: translateY(-2px);
            color: white;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin-top: 30px;
        }
        
        .pagination a {
            padding: 8px 15px;
            border-radius: 8px;
            background: #f1f5f9;
            color: #475569;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .pagination a:hover {
            background: #667eea;
            color: white;
        }
        
        .pagination a.active {
            background: #667eea;
            color: white;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 20px;
        }
        
        .alert-success {
            background: #d1fae5;
            color: #059669;
            border-left: 4px solid #10b981;
        }
        
        .alert-danger {
            background: #fee2e2;
            color: #dc2626;
            border-left: 4px solid #ef4444;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        
        .empty-state i {
            font-size: 80px;
            color: #cbd5e0;
            margin-bottom: 20px;
        }
        
        .location-path {
            font-size: 11px;
            color: #94a3b8;
            margin-top: 3px;
        }
        
        .deleter-info {
            font-size: 11px;
            color: #64748b;
            margin-top: 3px;
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
                padding: 15px;
            }
            
            .page-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .table td, .table th {
                font-size: 12px;
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <div class="trash-container">
            <div class="page-header">
                <h1>
                    <i class="fas fa-trash-alt"></i> 
                    Admin Trash Bin
                    <span class="admin-badge"><i class="fas fa-shield-alt"></i> Admin Only</span>
                </h1>
                <div class="d-flex gap-2">
                    <a href="files.php" class="btn-back">
                        <i class="fas fa-arrow-left"></i> Back to Files
                    </a>
                    <?php if ($deleted_count > 0): ?>
                        <form method="POST" onsubmit="return confirm('⚠️ WARNING: This will PERMANENTLY delete ALL items in trash! This action CANNOT be undone! Are you absolutely sure?');">
                            <button type="submit" name="empty_trash" class="btn-empty">
                                <i class="fas fa-trash"></i> Empty Trash (<?php echo $deleted_count; ?> items)
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($success_message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success_message; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error_message): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i> <?php echo $error_message; ?>
                </div>
            <?php endif; ?>
            
            <div class="filter-tabs">
                <a href="?filter=deleted" class="filter-tab <?php echo $filter == 'deleted' ? 'active' : ''; ?>">
                    <i class="fas fa-trash"></i> In Trash
                    <span class="count"><?php echo $deleted_count; ?></span>
                </a>
                <a href="?filter=recovered" class="filter-tab <?php echo $filter == 'recovered' ? 'active' : ''; ?>">
                    <i class="fas fa-undo"></i> Recovered
                    <span class="count"><?php echo $recovered_count; ?></span>
                </a>
                <a href="?filter=permanent" class="filter-tab <?php echo $filter == 'permanent' ? 'active' : ''; ?>">
                    <i class="fas fa-times-circle"></i> Permanently Deleted
                    <span class="count"><?php echo $permanent_count; ?></span>
                </a>
                <a href="?filter=all" class="filter-tab <?php echo $filter == 'all' ? 'active' : ''; ?>">
                    <i class="fas fa-list"></i> All Items
                    <span class="count"><?php echo $all_count; ?></span>
                </a>
            </div>
            
            <?php if (empty($trash_items)): ?>
                <div class="empty-state">
                    <i class="fas fa-trash-alt"></i>
                    <h3>Trash is empty</h3>
                    <p>Deleted items from users will appear here for you to review</p>
                    <p class="text-muted" style="font-size: 12px;">Only administrators can access this page</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Type</th>
                                <th>Original Location</th>
                                <th>Deleted By</th>
                                <th>Deleted At</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($trash_items as $item): ?>
                                <tr>
                                    <td>
                                        <div class="item-type">
                                            <i class="fas <?php echo $item['item_type'] == 'file' ? 'fa-file' : 'fa-folder'; ?>"></i>
                                            <div>
                                                <strong><?php echo htmlspecialchars($item['item_name']); ?></strong>
                                                <?php if ($item['item_type'] == 'folder'): ?>
                                                    <div class="location-path">
                                                        <i class="fas fa-folder-open"></i> Folder deleted with all contents
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="text-transform: capitalize; font-weight: 500;">
                                            <?php echo $item['item_type']; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($item['original_location'])): ?>
                                            <i class="fas fa-folder-open" style="color: #f59e0b;"></i>
                                            <?php echo htmlspecialchars($item['original_location']); ?>
                                        <?php else: ?>
                                            <span class="text-muted">Root</span>
                                        <?php endif; ?>
                                    </span>
                                    <td>
                                        <?php if (!empty($item['deleter_name'])): ?>
                                            <div>
                                                <i class="fas fa-user"></i> <?php echo htmlspecialchars($item['deleter_name']); ?>
                                            </div>
                                            <div class="deleter-info">
                                                <i class="fas fa-id-badge"></i> ID: <?php echo $item['deleted_by']; ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">System</span>
                                        <?php endif; ?>
                                    </span>
                                    <td>
                                        <i class="far fa-calendar-alt"></i> <?php echo date('M d, Y', strtotime($item['deleted_at'])); ?>
                                        <br>
                                        <small class="text-muted"><i class="far fa-clock"></i> <?php echo date('h:i A', strtotime($item['deleted_at'])); ?></small>
                                    </span>
                                    <td>
                                        <?php if ($item['status'] == 'deleted'): ?>
                                            <span class="badge badge-deleted"><i class="fas fa-trash"></i> In Trash</span>
                                        <?php elseif ($item['status'] == 'recovered'): ?>
                                            <span class="badge badge-recovered"><i class="fas fa-undo"></i> Recovered</span>
                                            <br><small><?php echo date('M d, Y', strtotime($item['recovered_at'])); ?></small>
                                        <?php else: ?>
                                            <span class="badge badge-permanent"><i class="fas fa-times"></i> Permanently Deleted</span>
                                            <br><small><?php echo date('M d, Y', strtotime($item['permanent_deleted_at'])); ?></small>
                                        <?php endif; ?>
                                    </span>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if ($item['status'] == 'deleted'): ?>
                                                <a href="?action=recover&id=<?php echo $item['id']; ?>&filter=<?php echo $filter; ?>" 
                                                   class="btn-action btn-recover"
                                                   onclick="return confirm('Recover this <?php echo $item['item_type']; ?>? It will be restored to its original location.');">
                                                    <i class="fas fa-undo"></i> Recover
                                                </a>
                                                <a href="?action=permanent&id=<?php echo $item['id']; ?>&filter=<?php echo $filter; ?>" 
                                                   class="btn-action btn-permanent"
                                                   onclick="return confirm('⚠️ PERMANENTLY DELETE this <?php echo $item['item_type']; ?>? This CANNOT be undone!');">
                                                    <i class="fas fa-trash"></i> Delete Forever
                                                </a>
                                            <?php else: ?>
                                                <span style="color: #94a3b8; font-size: 12px;">
                                                    <i class="fas fa-check-circle"></i> Processed
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </span>
                                </span>
                            <?php endforeach; ?>
                        </tbody>
                    </span>
                </div>
                
                <?php if ($total_pages > 1): ?>
                    <div class="pagination">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <a href="?page=<?php echo $i; ?>&filter=<?php echo $filter; ?>" 
                               class="<?php echo $page == $i ? 'active' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>