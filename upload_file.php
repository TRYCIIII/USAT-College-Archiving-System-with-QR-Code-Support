<?php
/**
 * upload_file.php  (new version)
 *
 * REQUIRED : file, title_of_program, date_conducted
 * OPTIONAL : custom_control_number, type_of_community_service,
 *            no_of_beneficiaries, community_population_served, description
 *
 * - Works with the AJAX upload form in files.php (returns JSON)
 * - Also works as a normal form post (redirects back to files.php)
 * - Adapts to your `files` table: only fills columns that actually exist
 * - Optional fields left blank never break the insert (NULL / '' / 0 is chosen
 *   automatically depending on whether the column allows NULL)
 */

// Never print PHP warnings into the JSON response
ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'config/database.php';
require_once 'includes/auth.php';

date_default_timezone_set('Asia/Manila');

// ---------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------
$isAjax = (
    (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
);

function respond_json(array $payload, int $code = 200): void {
    while (ob_get_level() > 0) { ob_end_clean(); }
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload);
    exit();
}

function fail(string $message, int $folderId = 1): void {
    global $isAjax;
    if ($isAjax) {
        respond_json(['success' => false, 'error' => $message], 200);
    }
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Location: files.php?folder=' . $folderId . '&error=' . urlencode($message));
    exit();
}

function current_user_id(): int {
    if (function_exists('getCurrentUserId')) {
        return (int)getCurrentUserId();
    }
    return (int)($_SESSION['user_id'] ?? 0);
}

function app_base_url(): string {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    return $protocol . $host . $dir;
}

function root_folder_name(PDO $pdo, int $folderId): string {
    $currentId = $folderId;
    $safety = 0;
    $last = null;
    while ($currentId > 1 && $safety++ < 50) {
        $st = $pdo->prepare("SELECT id, folder_name, parent_id FROM folders WHERE id = ?");
        $st->execute([$currentId]);
        $f = $st->fetch(PDO::FETCH_ASSOC);
        if (!$f) break;
        $last = $f;
        if ((int)$f['parent_id'] === 0 || (int)$f['parent_id'] === 1) {
            return $f['folder_name'];
        }
        $currentId = (int)$f['parent_id'];
    }
    return $last ? $last['folder_name'] : 'DOCUMENTS';
}

function upload_error_text(int $code): string {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:   return 'The file is larger than the server limit (upload_max_filesize in php.ini).';
        case UPLOAD_ERR_FORM_SIZE:  return 'The file is too large.';
        case UPLOAD_ERR_PARTIAL:    return 'The file was only partially uploaded. Please try again.';
        case UPLOAD_ERR_NO_FILE:    return 'Please select a file to upload.';
        case UPLOAD_ERR_NO_TMP_DIR: return 'Server error: missing temporary folder.';
        case UPLOAD_ERR_CANT_WRITE: return 'Server error: failed to write file to disk.';
        case UPLOAD_ERR_EXTENSION:  return 'A PHP extension stopped the upload.';
        default:                    return 'Unknown upload error.';
    }
}

// ---------------------------------------------------------------
// Auth + method
// ---------------------------------------------------------------
if (!function_exists('isLoggedIn') || !isLoggedIn()) {
    if ($isAjax) {
        respond_json(['success' => false, 'error' => 'Session expired. Please log in again.'], 401);
    }
    header('Location: index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: files.php');
    exit();
}

$folderId = isset($_POST['folder_id']) ? (int)$_POST['folder_id'] : 1;
if ($folderId < 1) $folderId = 1;

try {
    // -----------------------------------------------------------
    // Read + validate input
    // -----------------------------------------------------------
    $title        = trim($_POST['title_of_program'] ?? '');
    $dateRaw      = trim($_POST['date_conducted'] ?? '');
    $customCN     = strtoupper(trim($_POST['custom_control_number'] ?? ''));
    $typeService  = trim($_POST['type_of_community_service'] ?? '');
    $benefRaw     = trim($_POST['no_of_beneficiaries'] ?? '');
    $community    = trim($_POST['community_population_served'] ?? '');
    $description  = trim($_POST['description'] ?? '');

    // ---- REQUIRED fields only ----
    if (!isset($_FILES['file'])) {
        fail('Please select a file to upload.', $folderId);
    }
    if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        fail(upload_error_text((int)$_FILES['file']['error']), $folderId);
    }
    if ($title === '') {
        fail('Title of Program is required.', $folderId);
    }
    if ($dateRaw === '') {
        fail('Date Conducted is required.', $folderId);
    }
    $dt = DateTime::createFromFormat('Y-m-d', $dateRaw);
    if (!$dt || $dt->format('Y-m-d') !== $dateRaw) {
        fail('Date Conducted is not a valid date.', $folderId);
    }

    // ---- OPTIONAL fields: sanitize only ----
    $benefInt = null;
    if ($benefRaw !== '') {
        if (!ctype_digit($benefRaw)) {
            fail('No. of Beneficiaries must be a whole number (or leave it blank).', $folderId);
        }
        $benefInt = (int)$benefRaw;
    }

    // ---- File checks ----
    $origName = basename($_FILES['file']['name']);
    $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    $allowed  = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'jpg', 'jpeg', 'png', 'gif'];
    if (!in_array($ext, $allowed, true)) {
        fail('File type not allowed. Allowed: ' . implode(', ', $allowed) . '.', $folderId);
    }
    $sizeBytes = (int)$_FILES['file']['size'];
    if ($sizeBytes <= 0) {
        fail('The selected file is empty.', $folderId);
    }
    if ($sizeBytes > 10 * 1024 * 1024) {
        fail('File is too large. Maximum size is 10MB.', $folderId);
    }
    if (!is_uploaded_file($_FILES['file']['tmp_name'])) {
        fail('Invalid upload.', $folderId);
    }

    // ---- Folder must exist (folder 1 = virtual root "My Drive") ----
    if ($folderId > 1) {
        $st = $pdo->prepare("SELECT id FROM folders WHERE id = ?");
        $st->execute([$folderId]);
        if (!$st->fetch()) {
            fail('The destination folder no longer exists.', 1);
        }
    }

    // -----------------------------------------------------------
    // Inspect the `files` table (so we only use columns that exist)
    // -----------------------------------------------------------
    $colInfo = [];
    foreach ($pdo->query("SHOW COLUMNS FROM files")->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $colInfo[strtolower($c['Field'])] = $c;
    }
    $has = function (string $name) use ($colInfo): bool { return isset($colInfo[strtolower($name)]); };

    // For optional fields: choose NULL when the column allows it, otherwise '' or 0
    $nullable = function (string $name) use ($colInfo): bool {
        return isset($colInfo[strtolower($name)]) && strtoupper($colInfo[strtolower($name)]['Null']) === 'YES';
    };
    $optString = function (string $col, string $value) use ($nullable) {
        if ($value !== '') return $value;
        return $nullable($col) ? null : '';
    };
    $optInt = function (string $col, ?int $value) use ($nullable) {
        if ($value !== null) return $value;
        return $nullable($col) ? null : 0;
    };

    // -----------------------------------------------------------
    // Control number (custom or auto)
    // -----------------------------------------------------------
    if (!$has('control_number')) {
        $controlNumber = '';
    } else {
        if ($customCN !== '') {
            $st = $pdo->prepare("SELECT id FROM files WHERE control_number = ? LIMIT 1");
            $st->execute([$customCN]);
            if ($st->fetch()) {
                fail('Control Number "' . $customCN . '" is already used. Leave it blank for auto-generate.', $folderId);
            }
            $controlNumber = $customCN;
        } else {
            $rootName = root_folder_name($pdo, $folderId);
            $rootName = strtoupper(preg_replace('/[^A-Za-z0-9_\-]/', '', str_replace(' ', '', $rootName)));
            if ($rootName === '') $rootName = 'DOCUMENTS';
            $prefix = 'USAT-' . $rootName . '-';

            $st = $pdo->prepare("SELECT control_number FROM files WHERE control_number LIKE ?");
            $st->execute([$prefix . '%']);
            $max = 0;
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $cn) {
                if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', (string)$cn, $m)) {
                    $max = max($max, (int)$m[1]);
                }
            }
            do {
                $max++;
                $controlNumber = $prefix . str_pad((string)$max, 4, '0', STR_PAD_LEFT);
                $chk = $pdo->prepare("SELECT id FROM files WHERE control_number = ? LIMIT 1");
                $chk->execute([$controlNumber]);
            } while ($chk->fetch());
        }
    }

    // -----------------------------------------------------------
    // Where to store the file (matches how existing rows store it)
    // -----------------------------------------------------------
    $pathCol = null;
    foreach (['file_path', 'filepath', 'path', 'stored_name', 'file_location', 'file_url'] as $cand) {
        if ($has($cand)) { $pathCol = $cand; break; }
    }

    $diskDir    = __DIR__ . '/uploads/';
    $storedPref = 'uploads/';   // what we write in the DB column in front of the file name
    $absStored  = false;

    if ($pathCol) {
        try {
            $sample = $pdo->query("SELECT `$pathCol` FROM files WHERE `$pathCol` IS NOT NULL AND `$pathCol` <> '' ORDER BY id DESC LIMIT 1")->fetchColumn();
        } catch (Throwable $e) { $sample = false; }

        if ($sample) {
            $norm = str_replace('\\', '/', (string)$sample);
            $isAbsolute = (bool)preg_match('#^([a-zA-Z]:/|/)#', $norm);
            if ($isAbsolute) {
                $absStored  = true;
                $diskDir    = rtrim(dirname($norm), '/') . '/';
                $storedPref = $diskDir;
            } elseif (strpos($norm, '/') !== false) {
                $storedPref = rtrim(dirname($norm), '/') . '/';
                $diskDir    = __DIR__ . '/' . $storedPref;
            } else {
                // existing rows store only the file name
                $storedPref = '';
                $diskDir    = __DIR__ . '/uploads/';
            }
        }
    }

    if (!is_dir($diskDir)) {
        if (!@mkdir($diskDir, 0755, true) && !is_dir($diskDir)) {
            fail('Server error: cannot create the uploads folder.', $folderId);
        }
    }
    if (!is_writable($diskDir)) {
        fail('Server error: the uploads folder is not writable.', $folderId);
    }

    $storedName = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    $diskPath   = rtrim($diskDir, '/\\') . '/' . $storedName;

    if (!move_uploaded_file($_FILES['file']['tmp_name'], $diskPath)) {
        fail('Failed to save the uploaded file.', $folderId);
    }

    $dbPathValue = $storedPref . $storedName;
    $sizeKB      = (int)max(1, ceil($sizeBytes / 1024));
    $mime        = $_FILES['file']['type'] ?? '';

    // -----------------------------------------------------------
    // Build INSERT dynamically (only existing columns)
    // -----------------------------------------------------------
    $values = [];   // column => value (bound)
    $literal = [];  // column => raw SQL (e.g. NOW())

    $set = function (string $col, $val) use (&$values, $has) {
        if ($has($col)) $values[$col] = $val;
    };

    $set('file_name',       $origName);
    $set('original_name',   $origName);
    $set('stored_name',     $storedName);
    $set('file_extension',  $ext);
    $set('file_type',       $ext);
    $set('mime_type',       $mime);
    $set('file_size',       $sizeKB);   // files.php displays this value as "KB"
    $set('folder_id',       $folderId);
    $set('control_number',  $controlNumber);
    $set('title_of_program', $title);
    $set('date_conducted',  $dateRaw);
    $set('type_of_community_service',   $optString('type_of_community_service', $typeService));
    $set('no_of_beneficiaries',         $optInt('no_of_beneficiaries', $benefInt));
    $set('community_population_served', $optString('community_population_served', $community));
    $set('description',     $optString('description', $description));
    $set('uploaded_by',     current_user_id());
    $set('status',          'active');

    if ($pathCol && $pathCol !== 'stored_name') {
        $values[$pathCol] = $dbPathValue;
    } elseif ($pathCol === 'stored_name') {
        $values['stored_name'] = $dbPathValue;
    }
    // If file_path-like column differs from file_name, don't overwrite file_name
    if ($has('file_path') && $pathCol !== 'file_path') { $values['file_path'] = $dbPathValue; }

    if ($has('created_at')) $literal['created_at'] = 'NOW()';
    if ($has('updated_at')) $literal['updated_at'] = 'NOW()';

    $cols = [];
    $phs  = [];
    $bind = [];
    foreach ($values as $col => $val) {
        $cols[] = "`$col`";
        $phs[]  = '?';
        $bind[] = $val;
    }
    foreach ($literal as $col => $sql) {
        $cols[] = "`$col`";
        $phs[]  = $sql;
    }

    $sql = "INSERT INTO files (" . implode(',', $cols) . ") VALUES (" . implode(',', $phs) . ")";

    try {
        $ins = $pdo->prepare($sql);
        $ins->execute($bind);
    } catch (Throwable $e) {
        // roll back the physical file if the DB insert failed
        @unlink($diskPath);
        error_log('upload_file.php insert error: ' . $e->getMessage());
        fail('Database error while saving the file: ' . $e->getMessage(), $folderId);
    }

    $fileId = (int)$pdo->lastInsertId();

    // -----------------------------------------------------------
    // QR code (same link format used by files.php)
    // -----------------------------------------------------------
    $viewUrl = app_base_url() . '/view_document.php?id=' . $fileId;
    $qrUrl   = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($viewUrl);

    foreach (['qr_code', 'qr_code_path', 'qr_path', 'qr_url'] as $qrCol) {
        if ($has($qrCol)) {
            try {
                $pdo->prepare("UPDATE files SET `$qrCol` = ? WHERE id = ?")->execute([$qrUrl, $fileId]);
            } catch (Throwable $e) { /* ignore */ }
            break;
        }
    }

    // -----------------------------------------------------------
    // Activity log (optional table)
    // -----------------------------------------------------------
    try {
        $log = $pdo->prepare("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, 'UPLOAD_FILE', ?, ?)");
        $log->execute([current_user_id(), 'Uploaded file: ' . $origName . ($controlNumber ? ' (' . $controlNumber . ')' : ''), $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);
    } catch (Throwable $e) { /* table may not exist */ }

    // -----------------------------------------------------------
    // Done
    // -----------------------------------------------------------
    if ($isAjax) {
        respond_json([
            'success'        => true,
            'message'        => 'File uploaded successfully',
            'file_id'        => $fileId,
            'control_number' => $controlNumber,
            'qr_code'        => $qrUrl,
            'view_url'       => $viewUrl
        ]);
    }

    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Location: files.php?folder=' . $folderId . '&success=file_uploaded&show_qr=' . $fileId);
    exit();

} catch (Throwable $e) {
    error_log('upload_file.php fatal: ' . $e->getMessage());
    fail('Upload failed: ' . $e->getMessage(), $folderId);
}