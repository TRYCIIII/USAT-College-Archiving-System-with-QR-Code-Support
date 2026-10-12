<?php
// ============================================================
// trash_bin.php — Admin trash manager (fixed)
// ============================================================

session_start();

// ---- Errors: log, don't display (redirects won't break) ----
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once 'config/database.php';
require_once 'includes/auth.php';

// ---- Auth guards ----
if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    header("Location: index.php");
    exit();
}
if (!function_exists('isAdmin') || !isAdmin()) {
    $_SESSION['error'] = "Access denied. Only administrators can access the trash bin.";
    header("Location: files.php");
    exit();
}

// ---- Ensure trash_bin table exists ----
try {
    $pdo->query("SELECT 1 FROM trash_bin LIMIT 1");
} catch (PDOException $e) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS trash_bin (
            id INT AUTO_INCREMENT PRIMARY KEY,
            item_type ENUM('file','folder') NOT NULL DEFAULT 'file',
            item_id INT NOT NULL,
            item_name VARCHAR(255) DEFAULT '',
            item_path VARCHAR(500) DEFAULT '',
            folder_id INT DEFAULT 0,
            deleted_by INT DEFAULT 0,
            deleted_at DATETIME DEFAULT NULL,
            recovered_at DATETIME DEFAULT NULL,
            permanent_deleted_at DATETIME DEFAULT NULL,
            status ENUM('deleted','recovered','permanent_deleted') DEFAULT 'deleted',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// ---- Ensure columns exist on files/folders (safe on MariaDB/MySQL) ----
function column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
        ");
        $stmt->execute([$table, $column]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

// files
foreach (['status', 'deleted_by', 'deleted_at', 'file_path'] as $col) {
    if (!column_exists($pdo, 'files', $col)) {
        try {
            if ($col === 'status') {
                $pdo->exec("ALTER TABLE files ADD COLUMN status ENUM('active','archived','deleted') DEFAULT 'active'");
            } elseif ($col === 'deleted_by') {
                $pdo->exec("ALTER TABLE files ADD COLUMN deleted_by INT DEFAULT NULL");
            } elseif ($col === 'deleted_at') {
                $pdo->exec("ALTER TABLE files ADD COLUMN deleted_at DATETIME DEFAULT NULL");
            } elseif ($col === 'file_path') {
                $pdo->exec("ALTER TABLE files ADD COLUMN file_path VARCHAR(500) DEFAULT NULL");
            }
        } catch (PDOException $e) { /* ignore */ }
    }
}

// folders
foreach (['status', 'deleted_by', 'deleted_at'] as $col) {
    if (!column_exists($pdo, 'folders', $col)) {
        try {
            if ($col === 'status') {
                $pdo->exec("ALTER TABLE folders ADD COLUMN status ENUM('active','archived','deleted') DEFAULT 'active'");
            } elseif ($col === 'deleted_by') {
                $pdo->exec("ALTER TABLE folders ADD COLUMN deleted_by INT DEFAULT NULL");
            } elseif ($col === 'deleted_at') {
                $pdo->exec("ALTER TABLE folders ADD COLUMN deleted_at DATETIME DEFAULT NULL");
            }
        } catch (PDOException $e) { /* ignore */ }
    }
}

// trash_bin — add missing columns
foreach (['recovered_at', 'permanent_deleted_at', 'item_path', 'folder_id'] as $col) {
    if (!column_exists($pdo, 'trash_bin', $col)) {
        try {
            if ($col === 'recovered_at')            $pdo->exec("ALTER TABLE trash_bin ADD COLUMN recovered_at DATETIME DEFAULT NULL");
            if ($col === 'permanent_deleted_at')    $pdo->exec("ALTER TABLE trash_bin ADD COLUMN permanent_deleted_at DATETIME DEFAULT NULL");
            if ($col === 'item_path')               $pdo->exec("ALTER TABLE trash_bin ADD COLUMN item_path VARCHAR(500) DEFAULT ''");
            if ($col === 'folder_id')               $pdo->exec("ALTER TABLE trash_bin ADD COLUMN folder_id INT DEFAULT 0");
        } catch (PDOException $e) { /* ignore */ }
    }
}

// ---- Helper: run SQL with dynamic IN clause (avoids repeats) ----
function fetch_folder_descendants(PDO $pdo, int $rootId): array {
    $all = [$rootId];
    $queue = [$rootId];
    $safety = 0;
    while ($queue && $safety++ < 1000) {
        $parent = array_shift($queue);
        $stmt = $pdo->prepare("SELECT id FROM folders WHERE parent_id = ?");
        $stmt->execute([$parent]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $cid) {
            $cid = (int)$cid;
            if (!in_array($cid, $all, true)) {
                $all[] = $cid;
                $queue[] = $cid;
            }
        }
    }
    return $all;
}

// ---- Helper: safely unlink a file ----
function safe_unlink(?string $relPath): void {
    if (empty($relPath)) return;
    $abs = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
    if (is_file($abs)) @unlink($abs);
}

// ============================================================
// ACTION: Recover from trash
// ============================================================
if (isset($_GET['action']) && $_GET['action'] === 'recover' && isset($_GET['id'])) {
    $trash_id = (int)$_GET['id'];

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM trash_bin WHERE id = ? AND status = 'deleted'");
        $stmt->execute([$trash_id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($item) {
            $itemType = $item['item_type'] ?? 'file';
            $itemId   = (int)$item['item_id'];

            if ($itemType === 'file') {
                $pdo->prepare("UPDATE files SET status = 'active', deleted_by = NULL, deleted_at = NULL WHERE id = ?")
                    ->execute([$itemId]);
            } else {
                // Restore the folder and everything under it
                $allFolderIds = fetch_folder_descendants($pdo, $itemId);
                if (!empty($allFolderIds)) {
                    $ph = implode(',', array_fill(0, count($allFolderIds), '?'));

                    $pdo->prepare("UPDATE folders SET status = 'active', deleted_by = NULL, deleted_at = NULL WHERE id IN ($ph)")
                        ->execute($allFolderIds);

                    $pdo->prepare("UPDATE files SET status = 'active', deleted_by = NULL, deleted_at = NULL WHERE folder_id IN ($ph)")
                        ->execute($allFolderIds);
                }
            }

            $pdo->prepare("UPDATE trash_bin SET status = 'recovered', recovered_at = NOW() WHERE id = ?")
                ->execute([$trash_id]);

            $pdo->commit();
            $_SESSION['success'] = "✓ " . ucfirst($itemType) . " '" . ($item['item_name'] ?? '') . "' recovered successfully!";
        } else {
            $pdo->rollBack();
            $_SESSION['error'] = "Item not found in trash.";
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("trash recover error: " . $e->getMessage());
        $_SESSION['error'] = "Error recovering item: " . $e->getMessage();
    }

    header("Location: trash_bin.php?filter=deleted");
    exit();
}

// ============================================================
// ACTION: Permanent delete
// ============================================================
if (isset($_GET['action']) && $_GET['action'] === 'permanent' && isset($_GET['id'])) {
    $trash_id = (int)$_GET['id'];

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM trash_bin WHERE id = ?");
        $stmt->execute([$trash_id]);
        $item = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($item) {
            $itemType = $item['item_type'] ?? 'file';
            $itemId   = (int)$item['item_id'];

            if ($itemType === 'file') {
                // Delete physical file
                $filePath = $item['item_path'] ?? '';
                if ($filePath === '') {
                    // Look up from files table
                    try {
                        $s = $pdo->prepare("SELECT file_path FROM files WHERE id = ?");
                        $s->execute([$itemId]);
                        $filePath = (string)$s->fetchColumn();
                    } catch (Throwable $e) { /* ignore */ }
                }
                safe_unlink($filePath);

                $pdo->prepare("DELETE FROM files WHERE id = ?")->execute([$itemId]);
            } else {
                // Folder + contents
                $allFolderIds = fetch_folder_descendants($pdo, $itemId);
                if (!empty($allFolderIds)) {
                    $ph = implode(',', array_fill(0, count($allFolderIds), '?'));

                    // Unlink physical files first
                    $s = $pdo->prepare("SELECT file_path FROM files WHERE folder_id IN ($ph)");
                    $s->execute($allFolderIds);
                    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $fpath) {
                        safe_unlink((string)$fpath);
                    }

                    // Delete files from DB
                    $pdo->prepare("DELETE FROM files WHERE folder_id IN ($ph)")->execute($allFolderIds);

                    // Delete child folders first, then parents
                    // (delete in reverse-depth order)
                    $sorted = $allFolderIds;
                    usort($sorted, function ($a, $b) { return $b - $a; });
                    $ph2 = implode(',', array_fill(0, count($sorted), '?'));
                    $pdo->prepare("DELETE FROM folders WHERE id IN ($ph2)")->execute($sorted);
                }
            }

            $pdo->prepare("UPDATE trash_bin SET status = 'permanent_deleted', permanent_deleted_at = NOW() WHERE id = ?")
                ->execute([$trash_id]);

            $pdo->commit();
            $_SESSION['success'] = "✓ " . ucfirst($itemType) . " permanently deleted!";
        } else {
            $pdo->rollBack();
            $_SESSION['error'] = "Item not found in trash.";
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("trash permanent delete error: " . $e->getMessage());
        $_SESSION['error'] = "Error deleting item: " . $e->getMessage();
    }

    header("Location: trash_bin.php?filter=deleted");
    exit();
}

// ============================================================
// ACTION: Empty trash
// ============================================================
if (isset($_POST['empty_trash'])) {
    // Basic CSRF check (session token must match if provided)
    $csrfOk = true;
    if (!empty($_POST['csrf_token']) && !empty($_SESSION['csrf_token'])) {
        $csrfOk = hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
    }
    if (!$csrfOk) {
        $_SESSION['error'] = "Invalid request.";
        header("Location: trash_bin.php?filter=deleted");
        exit();
    }

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT * FROM trash_bin WHERE status = 'deleted'");
        $stmt->execute();
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($items as $item) {
            $itemType = $item['item_type'] ?? 'file';
            $itemId   = (int)$item['item_id'];

            if ($itemType === 'file') {
                $filePath = $item['item_path'] ?? '';
                if ($filePath === '') {
                    try {
                        $s = $pdo->prepare("SELECT file_path FROM files WHERE id = ?");
                        $s->execute([$itemId]);
                        $filePath = (string)$s->fetchColumn();
                    } catch (Throwable $e) { /* ignore */ }
                }
                safe_unlink($filePath);
                $pdo->prepare("DELETE FROM files WHERE id = ?")->execute([$itemId]);
            } else {
                $allFolderIds = fetch_folder_descendants($pdo, $itemId);
                if (!empty($allFolderIds)) {
                    $ph = implode(',', array_fill(0, count($allFolderIds), '?'));

                    $s = $pdo->prepare("SELECT file_path FROM files WHERE folder_id IN ($ph)");
                    $s->execute($allFolderIds);
                    foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $fpath) {
                        safe_unlink((string)$fpath);
                    }

                    $pdo->prepare("DELETE FROM files WHERE folder_id IN ($ph)")->execute($allFolderIds);

                    $sorted = $allFolderIds;
                    usort($sorted, function ($a, $b) { return $b - $a; });
                    $ph2 = implode(',', array_fill(0, count($sorted), '?'));
                    $pdo->prepare("DELETE FROM folders WHERE id IN ($ph2)")->execute($sorted);
                }
            }
        }

        $pdo->prepare("UPDATE trash_bin SET status = 'permanent_deleted', permanent_deleted_at = NOW() WHERE status = 'deleted'")
            ->execute();

        $pdo->commit();
        $_SESSION['success'] = "✓ Trash emptied successfully!";
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("empty trash error: " . $e->getMessage());
        $_SESSION['error'] = "Error emptying trash: " . $e->getMessage();
    }

    header("Location: trash_bin.php?filter=deleted");
    exit();
}

// ============================================================
// Fetch counts
// ============================================================
$deleted_count   = 0;
$recovered_count = 0;
$permanent_count = 0;
try {
    $deleted_count   = (int)$pdo->query("SELECT COUNT(*) FROM trash_bin WHERE status = 'deleted'")->fetchColumn();
    $recovered_count = (int)$pdo->query("SELECT COUNT(*) FROM trash_bin WHERE status = 'recovered'")->fetchColumn();
    $permanent_count = (int)$pdo->query("SELECT COUNT(*) FROM trash_bin WHERE status = 'permanent_deleted'")->fetchColumn();
} catch (Throwable $e) {
    error_log("trash counts error: " . $e->getMessage());
}
$all_count = $deleted_count + $recovered_count + $permanent_count;

// ============================================================
// Filter (whitelisted)
// ============================================================
$filter = $_GET['filter'] ?? 'deleted';
if (!in_array($filter, ['deleted', 'recovered', 'permanent', 'all'], true)) {
    $filter = 'deleted';
}

$where_clause = "";
switch ($filter) {
    case 'deleted':    $where_clause = " WHERE t.status = 'deleted'"; break;
    case 'recovered':  $where_clause = " WHERE t.status = 'recovered'"; break;
    case 'permanent':  $where_clause = " WHERE t.status = 'permanent_deleted'"; break;
    case 'all':        $where_clause = " WHERE 1=1"; break;
}

$trash_items = [];
try {
    $stmt = $pdo->prepare("
        SELECT t.*, u.full_name AS deleter_name
        FROM trash_bin t
        LEFT JOIN users u ON t.deleted_by = u.id
        $where_clause
        ORDER BY COALESCE(t.deleted_at, t.created_at) DESC
    ");
    $stmt->execute();
    $trash_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log("trash list error: " . $e->getMessage());
    $_SESSION['error'] = "Could not load trash items: " . $e->getMessage();
}

// CSRF token for the empty trash form
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trash Bin - Admin</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Inter', sans-serif; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .main-content { margin-left: 280px; padding: 30px; min-height: 100vh; }
        .trash-container { background: white; border-radius: 24px; padding: 30px; box-shadow: 0 10px 40px rgba(0,0,0,0.1); }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 20px; }
        .page-header h1 { font-size: 28px; font-weight: 700; color: #1a1a2e; display: flex; align-items: center; gap: 15px; }
        .page-header h1 i { color: #ef4444; font-size: 32px; }
        .admin-badge { background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
        .filter-tabs { display: flex; gap: 10px; margin-bottom: 25px; flex-wrap: wrap; }
        .filter-tab { padding: 10px 20px; border-radius: 30px; text-decoration: none; color: #64748b; font-weight: 500; transition: all 0.3s; background: #f1f5f9; }
        .filter-tab:hover { background: #e2e8f0; color: #64748b; }
        .filter-tab.active { background: linear-gradient(135deg, #667eea, #764ba2); color: white; }
        .filter-tab .count { background: rgba(255,255,255,0.2); padding: 2px 8px; border-radius: 20px; margin-left: 8px; font-size: 12px; }
        .filter-tab.active .count { background: rgba(255,255,255,0.3); }
        .table-container { overflow-x: auto; }
        .table { width: 100%; border-collapse: collapse; }
        .table thead th { background: #f8fafc; padding: 15px; font-weight: 600; color: #475569; border-bottom: 2px solid #e2e8f0; text-align: left; }
        .table tbody tr { border-bottom: 1px solid #e2e8f0; transition: all 0.3s; }
        .table tbody tr:hover { background: #f8fafc; }
        .table td { padding: 15px; vertical-align: middle; }
        .badge { padding: 5px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-deleted { background: #fee2e2; color: #dc2626; }
        .badge-recovered { background: #d1fae5; color: #059669; }
        .badge-permanent { background: #e2e8f0; color: #475569; }
        .action-buttons { display: flex; gap: 8px; flex-wrap: wrap; }
        .btn-action { padding: 6px 12px; border-radius: 8px; border: none; cursor: pointer; font-size: 12px; font-weight: 500; transition: all 0.3s; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .btn-recover { background: #10b981; color: white; }
        .btn-recover:hover { background: #059669; transform: translateY(-1px); color: white; }
        .btn-permanent { background: #ef4444; color: white; }
        .btn-permanent:hover { background: #dc2626; transform: translateY(-1px); color: white; }
        .btn-empty { background: #ef4444; color: white; padding: 10px 20px; border-radius: 12px; border: none; cursor: pointer; font-weight: 600; transition: all 0.3s; }
        .btn-empty:hover { background: #dc2626; transform: translateY(-2px); }
        .btn-back { background: #64748b; color: white; padding: 10px 20px; border-radius: 12px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; font-weight: 500; transition: all 0.3s; }
        .btn-back:hover { background: #475569; transform: translateY(-2px); color: white; }
        .alert { padding: 15px 20px; border-radius: 12px; margin-bottom: 20px; }
        .alert-success { background: #d1fae5; color: #059669; border-left: 4px solid #10b981; }
        .alert-danger { background: #fee2e2; color: #dc2626; border-left: 4px solid #ef4444; }
        .empty-state { text-align: center; padding: 60px 20px; }
        .empty-state i { font-size: 80px; color: #cbd5e0; margin-bottom: 20px; }
        .location-path { font-size: 11px; color: #94a3b8; margin-top: 3px; }
        .deleter-info { font-size: 11px; color: #64748b; margin-top: 3px; }
        .item-type { display: flex; align-items: center; gap: 10px; }
        .item-type i { font-size: 20px; color: #667eea; }
        @media (max-width: 768px) {
            .main-content { margin-left: 0; padding: 15px; }
            .page-header { flex-direction: column; align-items: flex-start; }
            .action-buttons { flex-direction: column; }
            .table td, .table th { font-size: 12px; padding: 10px; }
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
                    Trash Bin
                    <span class="admin-badge"><i class="fas fa-shield-alt"></i> Admin Only</span>
                </h1>
                <div class="d-flex gap-2">
                    <a href="files.php" class="btn-back">
                        <i class="fas fa-arrow-left"></i> Back to Files
                    </a>
                    <?php if ($deleted_count > 0): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('⚠️ WARNING: This will PERMANENTLY delete ALL items in trash! This CANNOT be undone!');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                            <button type="submit" name="empty_trash" class="btn-empty">
                                <i class="fas fa-trash"></i> Empty Trash (<?php echo $deleted_count; ?> items)
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($_SESSION['success'])): ?>
                <div class="alert alert-success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>
            <?php if (!empty($_SESSION['error'])): ?>
                <div class="alert alert-danger"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
            <?php endif; ?>

            <div class="filter-tabs">
                <a href="?filter=deleted" class="filter-tab <?php echo $filter === 'deleted' ? 'active' : ''; ?>">
                    <i class="fas fa-trash"></i> In Trash <span class="count"><?php echo $deleted_count; ?></span>
                </a>
                <a href="?filter=recovered" class="filter-tab <?php echo $filter === 'recovered' ? 'active' : ''; ?>">
                    <i class="fas fa-undo"></i> Recovered <span class="count"><?php echo $recovered_count; ?></span>
                </a>
                <a href="?filter=permanent" class="filter-tab <?php echo $filter === 'permanent' ? 'active' : ''; ?>">
                    <i class="fas fa-times-circle"></i> Permanently Deleted <span class="count"><?php echo $permanent_count; ?></span>
                </a>
                <a href="?filter=all" class="filter-tab <?php echo $filter === 'all' ? 'active' : ''; ?>">
                    <i class="fas fa-list"></i> All Items <span class="count"><?php echo $all_count; ?></span>
                </a>
            </div>

            <?php if (empty($trash_items)): ?>
                <div class="empty-state">
                    <i class="fas fa-trash-alt"></i>
                    <h3>Trash is empty</h3>
                    <p>Deleted items from users will appear here for admin review</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Item Name</th>
                                <th>Type</th>
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
                                            <i class="fas <?php echo ($item['item_type'] ?? 'file') === 'file' ? 'fa-file' : 'fa-folder'; ?>"></i>
                                            <div>
                                                <strong><?php echo htmlspecialchars($item['item_name'] ?? ''); ?></strong>
                                                <?php if (($item['item_type'] ?? '') === 'folder'): ?>
                                                    <div class="location-path">
                                                        <i class="fas fa-folder-open"></i> Folder with all contents
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <span style="text-transform: capitalize; font-weight: 500;">
                                            <?php echo htmlspecialchars($item['item_type'] ?? 'file'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($item['deleter_name'])): ?>
                                            <div><i class="fas fa-user"></i> <?php echo htmlspecialchars($item['deleter_name']); ?></div>
                                            <div class="deleter-info">
                                                <i class="fas fa-id-badge"></i> ID: <?php echo (int)($item['deleted_by'] ?? 0); ?>
                                            </div>
                                        <?php else: ?>
                                            <span class="text-muted">System</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php
                                        $deletedAt = $item['deleted_at'] ?? $item['created_at'] ?? null;
                                        if ($deletedAt):
                                        ?>
                                            <i class="far fa-calendar-alt"></i> <?php echo date('M d, Y', strtotime($deletedAt)); ?>
                                            <br>
                                            <small class="text-muted"><i class="far fa-clock"></i> <?php echo date('h:i A', strtotime($deletedAt)); ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (($item['status'] ?? '') === 'deleted'): ?>
                                            <span class="badge badge-deleted"><i class="fas fa-trash"></i> In Trash</span>
                                        <?php elseif (($item['status'] ?? '') === 'recovered'): ?>
                                            <span class="badge badge-recovered"><i class="fas fa-undo"></i> Recovered</span>
                                            <?php if (!empty($item['recovered_at'])): ?>
                                                <br><small><?php echo date('M d, Y', strtotime($item['recovered_at'])); ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge badge-permanent"><i class="fas fa-times"></i> Permanently Deleted</span>
                                            <?php if (!empty($item['permanent_deleted_at'])): ?>
                                                <br><small><?php echo date('M d, Y', strtotime($item['permanent_deleted_at'])); ?></small>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <?php if (($item['status'] ?? '') === 'deleted'): ?>
                                                <a href="?action=recover&id=<?php echo (int)$item['id']; ?>&filter=<?php echo urlencode($filter); ?>"
                                                   class="btn-action btn-recover"
                                                   onclick="return confirm('Restore this <?php echo ($item['item_type'] ?? 'file') === 'file' ? 'file' : 'folder and all its contents'; ?>?');">
                                                    <i class="fas fa-undo"></i> Recover
                                                </a>
                                                <a href="?action=permanent&id=<?php echo (int)$item['id']; ?>&filter=<?php echo urlencode($filter); ?>"
                                                   class="btn-action btn-permanent"
                                                   onclick="return confirm('⚠️ PERMANENTLY DELETE this item? This CANNOT be undone!');">
                                                    <i class="fas fa-trash"></i> Delete Forever
                                                </a>
                                            <?php else: ?>
                                                <span style="color: #94a3b8; font-size: 12px;">
                                                    <i class="fas fa-check-circle"></i> Processed
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>