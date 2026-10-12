<?php
// ============================================================
// delete_file.php — Move a file to trash with reason + audit
// ============================================================

error_reporting(E_ALL);
ini_set('display_errors', 0);   // never leak HTML into JSON
ini_set('log_errors', 1);

// Ensure log dir exists
$logDir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}
@ini_set('error_log', $logDir . DIRECTORY_SEPARATOR . 'delete_errors.log');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

// ---- Catch fatal errors so we always return JSON ----
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => false,
            'error'   => 'Fatal: ' . $err['message'] . ' in ' . basename($err['file']) . ':' . $err['line'],
        ]);
    }
});

function json_out($d, $code = 200) {
    http_response_code($code);
    echo json_encode($d);
    exit();
}

try {
    // ---- Auth ----
    if (!function_exists('isLoggedIn') || !isLoggedIn()) {
        json_out(['success' => false, 'error' => 'Not logged in'], 401);
    }

    $user_id = (int)($_SESSION['user_id'] ?? 0);
    $role    = $_SESSION['role'] ?? 'staff';

    // ---- Inputs ----
    $file_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($file_id <= 0) {
        json_out(['success' => false, 'error' => 'Invalid file ID'], 400);
    }

    // Reason (from front-end prompt)
    $reason = isset($_GET['reason']) ? trim((string)$_GET['reason']) : '';
    if (strlen($reason) > 500) {
        $reason = substr($reason, 0, 500);
    }

    // ---- Ensure trash_bin exists ----
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
                deletion_reason VARCHAR(500) DEFAULT NULL,
                recovered_at DATETIME DEFAULT NULL,
                permanent_deleted_at DATETIME DEFAULT NULL,
                status ENUM('deleted','recovered','permanent_deleted') DEFAULT 'deleted',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // ---- Ensure missing columns exist (safe) ----
    function ensure_column(PDO $pdo, string $table, string $column, string $definition): void {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
            ");
            $stmt->execute([$table, $column]);
            if ((int)$stmt->fetchColumn() === 0) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            }
        } catch (Throwable $e) {
            error_log("ensure_column failed for $table.$column: " . $e->getMessage());
        }
    }

    // files table: needs status
    ensure_column($pdo, 'files', 'status', "ENUM('active','archived','deleted') DEFAULT 'active'");
    ensure_column($pdo, 'files', 'deleted_by', "INT DEFAULT NULL");
    ensure_column($pdo, 'files', 'deleted_at', "DATETIME DEFAULT NULL");

    // trash_bin: needs reason + new schema columns
    ensure_column($pdo, 'trash_bin', 'deletion_reason', "VARCHAR(500) DEFAULT NULL");
    ensure_column($pdo, 'trash_bin', 'item_name', "VARCHAR(255) DEFAULT ''");
    ensure_column($pdo, 'trash_bin', 'item_path', "VARCHAR(500) DEFAULT ''");
    ensure_column($pdo, 'trash_bin', 'folder_id', "INT DEFAULT 0");
    ensure_column($pdo, 'trash_bin', 'recovered_at', "DATETIME DEFAULT NULL");
    ensure_column($pdo, 'trash_bin', 'permanent_deleted_at', "DATETIME DEFAULT NULL");

    // ---- Fetch file ----
    $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
    $stmt->execute([$file_id]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$file) {
        json_out(['success' => false, 'error' => 'File not found'], 404);
    }

    // Already deleted?
    if (isset($file['status']) && $file['status'] === 'deleted') {
        json_out(['success' => false, 'error' => 'File is already in the trash'], 409);
    }

    // ---- Permission check ----
    // Admin can delete anything.
    // Staff can delete their own files (and, by default, also others' files).
    // To restrict staff to only their own files, uncomment the block below.
    $isAdminUser = function_exists('isAdmin') && isAdmin();

    if (!$isAdminUser) {
        // ---- STAFF PATH ----
        // OPTION A (current): staff can delete any file
        // OPTION B: staff can only delete their own file — uncomment the next 4 lines
        /*
        $owner = (int)($file['uploaded_by'] ?? 0);
        if ($owner !== 0 && $owner !== $user_id) {
            json_out(['success' => false, 'error' => "You can only delete files you uploaded."], 403);
        }
        */
    }

    // ---- Determine file path (support multiple column names) ----
    $filePath = '';
    foreach (['file_path', 'path', 'filepath'] as $c) {
        if (!empty($file[$c])) {
            $filePath = (string)$file[$c];
            break;
        }
    }

    // ---- Mark file as deleted ----
    try {
        $pdo->prepare("UPDATE files SET status = 'deleted', updated_at = NOW() WHERE id = ?")
            ->execute([$file_id]);
    } catch (PDOException $e) {
        // If updated_at column doesn't exist, try without it
        try {
            $pdo->prepare("UPDATE files SET status = 'deleted' WHERE id = ?")
                ->execute([$file_id]);
        } catch (PDOException $e2) {
            error_log("delete_file: failed to update status: " . $e2->getMessage());
            json_out(['success' => false, 'error' => 'Failed to mark file as deleted'], 500);
        }
    }

    // ---- Insert into trash_bin ----
    // Detect which columns exist and only insert what's available
    try {
        $tcols = $pdo->query("SHOW COLUMNS FROM trash_bin")->fetchAll(PDO::FETCH_COLUMN);
        $tcols = array_map('strtolower', $tcols);
    } catch (Throwable $e) {
        $tcols = [];
    }

    // Candidate values (name → value)
    $candidates = [
        'item_type'        => 'file',
        'item_id'          => $file_id,
        'item_name'        => $file['file_name'] ?? 'Untitled',
        'item_path'        => $filePath,
        'folder_id'        => (int)($file['folder_id'] ?? 0),
        'deleted_by'       => $user_id,
        'deleted_at'       => date('Y-m-d H:i:s'),
        'deletion_reason'  => $reason !== '' ? $reason : null,
        'status'           => 'deleted',
        // Legacy column some installs have
        'item_data'        => json_encode($file),
    ];

    $insCols = [];
    $ph      = [];
    $vals    = [];
    foreach ($candidates as $col => $val) {
        if (in_array($col, $tcols, true)) {
            $insCols[] = "`$col`";
            $ph[]      = '?';
            $vals[]    = $val;
        }
    }

    if (empty($insCols)) {
        json_out(['success' => false, 'error' => 'trash_bin table has no compatible columns'], 500);
    }

    $sql = "INSERT INTO trash_bin (" . implode(',', $insCols) . ") VALUES (" . implode(',', $ph) . ")";

    try {
        $ins = $pdo->prepare($sql);
        $ins->execute($vals);
        $trash_id = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        // If the file was marked deleted but trash insert failed, revert
        try {
            $pdo->prepare("UPDATE files SET status = 'active' WHERE id = ?")->execute([$file_id]);
        } catch (Throwable $e2) { /* ignore */ }

        error_log("delete_file: trash insert failed: " . $e->getMessage());
        json_out(['success' => false, 'error' => 'Failed to record deletion: ' . $e->getMessage()], 500);
    }

    // ---- Activity log (best-effort) ----
    try {
        $pdo->prepare("
            INSERT INTO activity_log (user_id, action, description, ip_address)
            VALUES (?, 'FILE_DELETE', ?, ?)
        ")->execute([
            $user_id,
            "Deleted file: " . ($file['file_name'] ?? '') . " (ID: $file_id)" .
                ($reason !== '' ? " — Reason: $reason" : ''),
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        ]);
    } catch (Throwable $e) { /* table may not exist */ }

    // ---- Success ----
    json_out([
        'success'   => true,
        'message'   => 'File deleted: ' . ($file['file_name'] ?? ''),
        'file_name' => $file['file_name'] ?? '',
        'file_id'   => $file_id,
        'trash_id'  => $trash_id ?? null,
        'reason'    => $reason,
    ]);

} catch (Throwable $e) {
    error_log("delete_file FATAL: " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode([
        'success' => false,
        'error'   => 'Server error: ' . $e->getMessage(),
    ]);
    exit();
}