<?php
// ============================================================
// upload_file.php — Fixed & bulletproof JSON-only version
// ============================================================

// ---- Must be first: prevent HTML error leaks ----
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// ---- Ensure log dir exists ----
$logDir = __DIR__ . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0777, true);
}
@ini_set('error_log', $logDir . DIRECTORY_SEPARATOR . 'upload_errors.log');

// ---- Start session ----
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ---- Determine AJAX ----
$is_ajax = isset($_SERVER['HTTP_X_REQUESTED_WITH'])
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

// ---- ALWAYS send JSON header, even for errors ----
header('Content-Type: application/json; charset=utf-8');

// ---- Catch fatal errors and still return JSON ----
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

// ---- Load DB & auth ----
try {
    require_once __DIR__ . '/config/database.php';
    require_once __DIR__ . '/includes/auth.php';
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Bootstrap failed: ' . $e->getMessage()]);
    exit();
}

// ---- JSON helpers ----
function json_out($success, $error = '', $data = []) {
    $response = array_merge(['success' => (bool)$success, 'error' => $error], $data);
    echo json_encode($response);
    exit();
}

function fail($msg, $code = 400, $folder_id = 1) {
    global $is_ajax, $pdo;
    error_log("UPLOAD FAIL: " . $msg);
    if ($is_ajax) {
        http_response_code($code);
        json_out(false, $msg);
    }
    $_SESSION['error'] = $msg;
    header("Location: files.php?folder=" . (int)$folder_id . "&error=" . urlencode($msg));
    exit();
}

// ---- Main try/catch — nothing escapes ----
try {
    // ---- Auth ----
    if (!function_exists('isLoggedIn') || !isLoggedIn()) {
        fail('Not logged in. Please log in again.', 401);
    }

    // ---- Method ----
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fail('POST required', 405);
    }

    // ---- Oversized upload (post_max_size exceeded → empty $_POST + $_FILES) ----
    if (empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        fail('Upload exceeded post_max_size (' . ini_get('post_max_size') . '). Increase in php.ini.', 413);
    }

    $folder_id = isset($_POST['folder_id']) ? (int)$_POST['folder_id'] : 1;
    if ($folder_id <= 0) $folder_id = 1;

    $custom_control_number = isset($_POST['custom_control_number'])
        ? trim($_POST['custom_control_number'])
        : '';

    // ---- Verify folder exists ----
    try {
        $stmt = $pdo->prepare("SELECT id FROM folders WHERE id = ?");
        $stmt->execute([$folder_id]);
        if (!$stmt->fetch()) {
            fail("Folder (id=$folder_id) not found. Create it or use folder 1.", 404, 1);
        }
    } catch (Throwable $e) {
        fail("Cannot verify folder: " . $e->getMessage(), 500, 1);
    }

    // ---- File checks ----
    if (!isset($_FILES['file'])) {
        fail('No file received. Form must use enctype="multipart/form-data".', 400, $folder_id);
    }

    $err_code = isset($_FILES['file']['error']) ? (int)$_FILES['file']['error'] : 4;
    if ($err_code !== UPLOAD_ERR_OK) {
        $errs = [
            UPLOAD_ERR_INI_SIZE   => 'File too large (server limit: ' . ini_get('upload_max_filesize') . ')',
            UPLOAD_ERR_FORM_SIZE  => 'File too large (form limit)',
            UPLOAD_ERR_PARTIAL    => 'File only partially uploaded',
            UPLOAD_ERR_NO_FILE    => 'No file selected',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing upload_tmp_dir (check php.ini)',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION  => 'Upload stopped by PHP extension',
        ];
        fail($errs[$err_code] ?? "Upload error code: $err_code", 400, $folder_id);
    }

    // ---- Sanitize filename (prevent path traversal) ----
    $raw_name = (string)($_FILES['file']['name'] ?? '');
    $filename = basename($raw_name);
    $filename = preg_replace('/[^\w\-\. ]+/u', '_', $filename);
    if ($filename === '' || $filename === '.' || $filename === '..') {
        $filename = 'upload_' . time() . '.bin';
    }

    $file_tmp   = $_FILES['file']['tmp_name'];
    $file_size  = (int)($_FILES['file']['size'] ?? 0);
    $file_ext   = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    // ---- Size limit (10 MB) ----
    $max_bytes = 10 * 1024 * 1024;
    if ($file_size > $max_bytes) {
        fail('File too large (max 10 MB, yours: ' . round($file_size / 1048576, 2) . ' MB)', 413, $folder_id);
    }
    if ($file_size === 0) {
        fail('Uploaded file is empty', 400, $folder_id);
    }

    // ---- Extension check ----
    $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx', 'xls', 'xlsx'];
    if (!in_array($file_ext, $allowed, true)) {
        fail('File type .' . $file_ext . ' not allowed. Allowed: ' . implode(', ', $allowed), 400, $folder_id);
    }

    // ---- Temp file sanity ----
    if (!file_exists($file_tmp)) {
        fail('Temporary upload file missing. Check upload_tmp_dir in php.ini.', 500, $folder_id);
    }

    // ---- Ensure uploads & qrcodes dirs ----
    $upload_dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
    $qr_dir     = __DIR__ . DIRECTORY_SEPARATOR . 'qrcodes';

    foreach ([$upload_dir, $qr_dir] as $dir) {
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0777, true) && !is_dir($dir)) {
                fail('Cannot create directory: ' . $dir, 500, $folder_id);
            }
        }
        if (!is_writable($dir)) {
            @chmod($dir, 0777);
            if (!is_writable($dir)) {
                fail('Directory not writable: ' . $dir, 500, $folder_id);
            }
        }
    }

    // ---- Move uploaded file ----
    $new_filename = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $file_ext;
    $final_path   = $upload_dir . DIRECTORY_SEPARATOR . $new_filename;
    $rel_path     = 'uploads/' . $new_filename;

    if (!move_uploaded_file($file_tmp, $final_path)) {
        fail('Failed to save uploaded file. Check permissions on: ' . $upload_dir, 500, $folder_id);
    }
    @chmod($final_path, 0644);

    $file_size_kb = round($file_size / 1024, 2);

    // ---- Metadata ----
    $title_of_program            = trim($_POST['title_of_program'] ?? '');
    $date_conducted              = !empty($_POST['date_conducted']) ? $_POST['date_conducted'] : null;
    $type_of_community_service   = trim($_POST['type_of_community_service'] ?? '');
    $community_population_served = trim($_POST['community_population_served'] ?? '');
    $no_of_beneficiaries         = !empty($_POST['no_of_beneficiaries']) ? (int)$_POST['no_of_beneficiaries'] : 0;
    $description                 = trim($_POST['description'] ?? '');

    if ($title_of_program === '')            { @unlink($final_path); fail('Title of Program is required', 400, $folder_id); }
    if (empty($date_conducted))              { @unlink($final_path); fail('Date Conducted is required', 400, $folder_id); }
    if ($type_of_community_service === '')   { @unlink($final_path); fail('Type of Community Service is required', 400, $folder_id); }
    if ($community_population_served === '') { @unlink($final_path); fail('Community Served is required', 400, $folder_id); }

    // ---- Root folder name (cycle-safe) ----
    function getRootFolderName($pdo, $folder_id) {
        $current_id = $folder_id;
        $safety = 0;
        while ($current_id > 1 && $safety++ < 50) {
            $stmt = $pdo->prepare("SELECT id, folder_name, parent_id FROM folders WHERE id = ?");
            $stmt->execute([$current_id]);
            $folder = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$folder) break;
            if ($folder['parent_id'] == 0 || $folder['parent_id'] == 1) {
                return $folder['folder_name'];
            }
            $current_id = $folder['parent_id'];
        }
        return 'DOCUMENTS';
    }

    // ---- Control number generation ----
    if ($custom_control_number === '') {
        $root_name     = getRootFolderName($pdo, $folder_id);
        $folder_prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $root_name));
        if ($folder_prefix === '') $folder_prefix = 'DOCUMENTS';

        $prefix = 'USAT-' . $folder_prefix . '-';

        // Find max numeric suffix
        $stmt = $pdo->prepare(
            "SELECT control_number FROM files
             WHERE control_number LIKE ?
             ORDER BY LENGTH(control_number) DESC, control_number DESC
             LIMIT 1"
        );
        $stmt->execute([$prefix . '%']);
        $last = $stmt->fetchColumn();

        $next = 1;
        if ($last) {
            $parts = explode('-', $last);
            $next = (int)end($parts) + 1;
        }

        $control_number = $prefix . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
    } else {
        $control_number = strtoupper($custom_control_number);
        $chk = $pdo->prepare("SELECT id FROM files WHERE control_number = ?");
        $chk->execute([$control_number]);
        if ($chk->fetch()) {
            @unlink($final_path);
            fail('Control number already exists: ' . $control_number, 409, $folder_id);
        }
    }

    // ---- Detect files table columns ----
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM files")->fetchAll(PDO::FETCH_COLUMN);
        $cols = array_map('strtolower', $cols);
    } catch (Throwable $e) {
        @unlink($final_path);
        fail('Cannot read files table schema: ' . $e->getMessage(), 500, $folder_id);
    }

    $candidate = [
        'folder_id'                   => $folder_id,
        'file_name'                   => $filename,
        'file_path'                   => $rel_path,
        'file_extension'              => $file_ext,
        'file_size'                   => $file_size_kb,
        'control_number'              => $control_number,
        'title_of_program'            => $title_of_program,
        'date_conducted'              => $date_conducted,
        'type_of_community_service'   => $type_of_community_service,
        'community_population_served' => $community_population_served,
        'no_of_beneficiaries'         => $no_of_beneficiaries,
        'description'                 => $description,
        'status'                      => 'active',
        'uploaded_by'                 => $_SESSION['user_id'] ?? 1,
    ];

    $insert_cols  = [];
    $placeholders = [];
    $values       = [];
    foreach ($candidate as $col => $val) {
        if (in_array($col, $cols, true)) {
            $insert_cols[]  = "`$col`";
            $placeholders[] = '?';
            $values[]       = $val;
        }
    }

    // Timestamps
    if (in_array('created_at', $cols, true)) {
        $insert_cols[]  = '`created_at`';
        $placeholders[] = 'NOW()';
    }
    if (in_array('updated_at', $cols, true)) {
        $insert_cols[]  = '`updated_at`';
        $placeholders[] = 'NOW()';
    }

    if (empty($insert_cols)) {
        @unlink($final_path);
        fail('files table has no compatible columns', 500, $folder_id);
    }

    $sql = "INSERT INTO files (" . implode(',', $insert_cols) . ") VALUES (" . implode(',', $placeholders) . ")";

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($values);
        $file_id = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
        @unlink($final_path);
        fail('Database insert failed: ' . $e->getMessage(), 500, $folder_id);
    }

    // ---- QR code — return URL (fast, no download during upload) ----
    $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host       = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script_dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    $view_url   = $protocol . $host . $script_dir . '/view_document.php?id=' . $file_id;
    $qr_url     = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($view_url);

    // Try to cache locally (short timeout — never blocks upload)
    $ctx = stream_context_create([
        'http'  => ['timeout' => 3],
        'https' => ['timeout' => 3],
    ]);
    $qr_content = @file_get_contents($qr_url, false, $ctx);

    $qr_base64 = '';
    if ($qr_content !== false && strlen($qr_content) > 100) {
        $qr_filename = 'qr_' . $file_id . '_' . time() . '.png';
        $qr_full     = $qr_dir . DIRECTORY_SEPARATOR . $qr_filename;
        $qr_rel      = 'qrcodes/' . $qr_filename;

        if (@file_put_contents($qr_full, $qr_content) !== false && in_array('qr_code_path', $cols, true)) {
            try {
                $pdo->prepare("UPDATE files SET qr_code_path = ? WHERE id = ?")
                    ->execute([$qr_rel, $file_id]);
            } catch (Throwable $e) { /* ignore */ }
        }
        $qr_base64 = 'data:image/png;base64,' . base64_encode($qr_content);
    } else {
        // SVG fallback (no GD needed, no warnings)
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="300" viewBox="0 0 300 300">'
             . '<rect width="300" height="300" fill="#ffffff"/>'
             . '<rect x="10" y="10" width="280" height="280" fill="#667eea" rx="12"/>'
             . '<text x="150" y="130" font-family="Arial" font-size="16" fill="#ffffff" text-anchor="middle">QR Code</text>'
             . '<text x="150" y="160" font-family="Courier" font-size="14" fill="#ffffff" text-anchor="middle">'
             . htmlspecialchars($control_number, ENT_QUOTES) . '</text>'
             . '<text x="150" y="195" font-family="Arial" font-size="11" fill="rgba(255,255,255,0.8)" text-anchor="middle">USAT Archive System</text>'
             . '</svg>';
        $qr_base64 = 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    // ---- Activity log (best-effort) ----
    try {
        $pdo->prepare("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, 'FILE_UPLOAD', ?, ?)")
            ->execute([
                $_SESSION['user_id'] ?? 1,
                "Uploaded file: $filename (Control: $control_number)",
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            ]);
    } catch (Throwable $e) { /* ignore */ }

    // ---- Success ----
    if ($is_ajax) {
        json_out(true, '', [
            'file_id'        => $file_id,
            'file_name'      => $filename,
            'control_number' => $control_number,
            'qr_code'        => $qr_base64,
            'view_url'       => $view_url,
            'message'        => 'File uploaded successfully',
        ]);
    }

    $_SESSION['success'] = "File uploaded successfully! Control Number: " . $control_number;
    header("Location: files.php?folder=" . $folder_id . "&success=file_uploaded&show_qr=" . $file_id);
    exit();

} catch (Throwable $e) {
    error_log("upload_file FATAL: " . $e->getMessage() . " @ " . $e->getFile() . ":" . $e->getLine());
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