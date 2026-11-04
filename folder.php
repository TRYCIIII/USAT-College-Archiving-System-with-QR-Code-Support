<?php
// folder.php — JSON endpoint for folder QR overlay + HTML fallback page
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/config/database.php';

/* =========================================================
   JSON ENDPOINT (?format=json) — used by folder overlay
   ========================================================= */
if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $folderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($folderId <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid folder ID']);
        exit;
    }

    $humanSize = function ($bytes) {
        $bytes = (int)$bytes;
        if ($bytes <= 0) return '';
        if ($bytes >= 1048576) return round($bytes / 1048576, 2) . ' MB';
        if ($bytes >= 1024)    return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    };
    $iconForExt = function ($ext) {
        $map = [
            'pdf'  => 'fa-file-pdf',
            'doc'  => 'fa-file-word',       'docx' => 'fa-file-word',
            'xls'  => 'fa-file-excel',      'xlsx' => 'fa-file-excel',
            'ppt'  => 'fa-file-powerpoint', 'pptx' => 'fa-file-powerpoint',
            'jpg'  => 'fa-file-image',      'jpeg' => 'fa-file-image',
            'png'  => 'fa-file-image',      'gif'  => 'fa-file-image',
            'zip'  => 'fa-file-archive',    'rar'  => 'fa-file-archive',
            'txt'  => 'fa-file-alt',
        ];
        return $map[strtolower((string)$ext)] ?? 'fa-file';
    };

    try {
        /* 1. fetch folder */
        $stmt = $pdo->prepare("SELECT id, folder_name, parent_id FROM folders WHERE id = ? LIMIT 1");
        $stmt->execute([$folderId]);
        $folder = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$folder) {
            echo json_encode(['success' => false, 'error' => 'Folder not found (ID ' . $folderId . ')']);
            exit;
        }

        /* 2. breadcrumb */
        $breadcrumb = [];
        $cur = $folder;
        $guard = 0;
        while ($cur && $guard++ < 50) {
            array_unshift($breadcrumb, ['id' => (int)$cur['id'], 'name' => $cur['folder_name']]);
            if (empty($cur['parent_id']) || (int)$cur['parent_id'] === 0) break;
            $p = $pdo->prepare("SELECT id, folder_name, parent_id FROM folders WHERE id = ? LIMIT 1");
            $p->execute([(int)$cur['parent_id']]);
            $cur = $p->fetch(PDO::FETCH_ASSOC);
        }

        /* 3. subfolders */
        $items = [];
        $sub = $pdo->prepare(
            "SELECT id, folder_name FROM folders
             WHERE parent_id = ? AND (status IS NULL OR status = 'active')
             ORDER BY folder_name ASC"
        );
        $sub->execute([$folderId]);
        foreach ($sub->fetchAll(PDO::FETCH_ASSOC) as $sf) {
            $cnt = $pdo->prepare(
                "SELECT COUNT(*) FROM files
                 WHERE folder_id = ? AND (status IS NULL OR status = 'active')"
            );
            $cnt->execute([(int)$sf['id']]);
            $items[] = [
                'type'       => 'folder',
                'id'         => (int)$sf['id'],
                'name'       => $sf['folder_name'],
                'icon'       => 'fa-folder',
                'item_count' => (int)$cnt->fetchColumn(),
            ];
        }

        /* 4. files */
        $files = $pdo->prepare(
            "SELECT id, file_name, file_extension, file_size
             FROM files
             WHERE folder_id = ? AND (status IS NULL OR status = 'active')
             ORDER BY file_name ASC"
        );
        $files->execute([$folderId]);
        foreach ($files->fetchAll(PDO::FETCH_ASSOC) as $f) {
            $items[] = [
                'type'       => 'file',
                'id'         => (int)$f['id'],
                'name'       => $f['file_name'],
                'file_ext'   => $f['file_extension'],
                'size_human' => $humanSize($f['file_size'] ?? 0),
                'icon'       => $iconForExt($f['file_extension'] ?? ''),
            ];
        }

        /* 5. respond */
        echo json_encode([
            'success'     => true,
            'folder_id'   => (int)$folder['id'],
            'folder_name' => $folder['folder_name'],
            'folder_code' => 'ID ' . $folder['id'],
            'folder_url'  => 'files.php?folder=' . (int)$folder['id'],
            'breadcrumb'  => $breadcrumb,
            'item_count'  => count($items),
            'items'       => $items,
        ]);
        exit;

    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

/* =========================================================
   HTML PAGE — direct access fallback
   ========================================================= */
$folderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Folder — USAT Archiving</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        body { background: #f1f5f9; padding: 24px; padding-left: 304px; font-family: system-ui, sans-serif; }
        @media (max-width: 768px) { body { padding: 76px 16px 16px; } }
        .page-title { font-size: 20px; font-weight: 700; color: #1e293b; margin-bottom: 20px; }
        .folder-card { background:#fff; border:1px solid #e2e8f0; border-radius:14px; padding:16px; display:flex; flex-direction:column; gap:8px; cursor:pointer; transition:all .2s; text-decoration:none; color:inherit; height:100%; }
        .folder-card:hover { border-color:#667eea; box-shadow:0 6px 20px rgba(102,126,234,.18); transform:translateY(-2px); color:inherit; }
        .folder-card .fc-icon { width:42px; height:42px; border-radius:10px; background:linear-gradient(135deg,#667eea,#764ba2); color:#fff; display:flex; align-items:center; justify-content:center; font-size:18px; }
        .folder-card.folder .fc-icon { background:linear-gradient(135deg,#f59e0b,#d97706); }
        .folder-card .fc-name { font-size:13.5px; font-weight:600; color:#1e293b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .folder-card .fc-meta { font-size:11px; color:#64748b; }
        .empty-state { text-align:center; padding:60px 20px; color:#64748b; }
        .empty-state i { font-size:56px; color:#cbd5e1; margin-bottom:16px; display:block; }
        .breadcrumb-bar { display:flex; align-items:center; flex-wrap:wrap; gap:6px; font-size:13px; color:#64748b; margin-bottom:16px; padding:10px 14px; background:#fff; border-radius:10px; border:1px solid #e2e8f0; }
        .breadcrumb-bar a { color:#667eea; text-decoration:none; font-weight:600; }
        .breadcrumb-bar a:hover { text-decoration:underline; }
        .breadcrumb-bar i.fa-chevron-right { font-size:9px; opacity:.5; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div id="breadcrumb" class="breadcrumb-bar" style="display:none;"></div>
    <div class="page-title" id="pageTitle">Loading…</div>
    <div class="row g-3" id="folderGrid"></div>

    <script>
        (function () {
            const folderId = <?= (int)$folderId ?>;
            const titleEl  = document.getElementById('pageTitle');
            const gridEl   = document.getElementById('folderGrid');
            const bcEl     = document.getElementById('breadcrumb');

            if (!folderId) {
                titleEl.textContent = 'No folder specified';
                return;
            }

            function esc(s) {
                return String(s || '').replace(/[&<>"']/g, c =>
                    ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c])
                );
            }

            fetch('folder.php?id=' + folderId + '&format=json', { cache: 'no-store' })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        titleEl.textContent = data.error || 'Folder not found';
                        titleEl.style.color = '#dc2626';
                        return;
                    }
                    titleEl.textContent = data.folder_name;

                    if (data.breadcrumb && data.breadcrumb.length) {
                        bcEl.style.display = 'flex';
                        bcEl.innerHTML = '';
                        data.breadcrumb.forEach((c, i) => {
                            const a = document.createElement('a');
                            a.href = 'files.php?folder=' + c.id;
                            a.textContent = c.name;
                            bcEl.appendChild(a);
                            if (i < data.breadcrumb.length - 1) {
                                const sep = document.createElement('i');
                                sep.className = 'fas fa-chevron-right';
                                bcEl.appendChild(sep);
                            }
                        });
                    }

                    if (data.items.length === 0) {
                        gridEl.innerHTML =
                            '<div class="col-12"><div class="empty-state">' +
                            '<i class="fas fa-folder-open"></i>' +
                            '<h3>This folder is empty</h3>' +
                            '<p>No documents or subfolders yet.</p>' +
                            '</div></div>';
                        return;
                    }

                    data.items.forEach(item => {
                        const col = document.createElement('div');
                        col.className = 'col-6 col-md-4 col-lg-3';
                        const href = item.type === 'folder'
                            ? 'files.php?folder=' + item.id
                            : 'view_document.php?id=' + item.id;
                        col.innerHTML =
                            '<a class="folder-card ' + (item.type === 'folder' ? 'folder' : '') + '" href="' + href + '">' +
                                '<div class="fc-icon"><i class="fas ' + (item.icon || 'fa-file') + '"></i></div>' +
                                '<div class="fc-name" title="' + esc(item.name) + '">' + esc(item.name) + '</div>' +
                                '<div class="fc-meta">' +
                                    (item.type === 'folder'
                                        ? (item.item_count || 0) + ' items'
                                        : ((item.file_ext || 'file').toUpperCase() +
                                           (item.size_human ? ' • ' + item.size_human : ''))) +
                                '</div>' +
                            '</a>';
                        gridEl.appendChild(col);
                    });
                })
                .catch(err => {
                    titleEl.textContent = 'Failed to load folder: ' + err.message;
                    titleEl.style.color = '#dc2626';
                });
        })();
    </script>
</body>
</html>