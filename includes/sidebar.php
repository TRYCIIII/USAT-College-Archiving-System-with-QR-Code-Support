<?php
// includes/sidebar.php — Sidebar + QR scanner + auto-view overlays (file + folder)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

// ---------- Trash count ----------
$trash_count = 0;
if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    try {
        $check = $pdo->query("SHOW TABLES LIKE 'trash_bin'");
        if ($check->rowCount() > 0) {
            $trash_count = $pdo->query("SELECT COUNT(*) FROM trash_bin WHERE status = 'deleted'")->fetchColumn();
        }
    } catch (PDOException $e) {}
}

// ---------- Scan counts ----------
$total_scans = 0;
$today_scans = 0;
try {
    $total_scans = $pdo->query("SELECT COUNT(*) FROM qr_scans")->fetchColumn();
    $today_scans = $pdo->query("SELECT COUNT(*) FROM qr_scans WHERE DATE(scanned_at) = CURDATE()")->fetchColumn();
} catch (PDOException $e) {}
?>

<!-- MOBILE TOGGLE -->
<button type="button" id="sidebarToggle" class="sidebar-toggle" aria-label="Toggle menu" aria-expanded="false">
    <i class="fas fa-bars" id="sidebarToggleIcon"></i>
</button>

<div id="sidebarBackdrop" class="sidebar-backdrop"></div>

<!-- QR SCANNER MODAL -->
<div class="modal fade" id="qrScannerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-qrcode"></i> QR Code Scanner</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" onclick="qrStop()"></button>
            </div>
            <div class="modal-body">
                <div id="qr-reader"></div>
                <div id="qr-status" class="qr-status qr-info">
                    <i class="fas fa-info-circle"></i> Click "Start Scanner" to begin
                </div>
            </div>
            <div class="modal-footer">
                <button id="qr-start-btn" class="btn btn-success" onclick="qrStart()">
                    <i class="fas fa-play"></i> Start Scanner
                </button>
                <button id="qr-stop-btn" class="btn btn-danger" onclick="qrStop()" style="display:none;">
                    <i class="fas fa-stop"></i> Stop
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" onclick="qrStop()">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- DOCUMENT OVERLAY -->
<div id="docOverlay" class="doc-overlay" aria-hidden="true">
    <div class="doc-panel">
        <div class="doc-topbar">
            <div class="doc-title">
                <i class="fas fa-file" id="docIcon"></i>
                <div>
                    <h4 id="docName">Loading…</h4>
                    <span id="docMeta">Please wait</span>
                </div>
            </div>
            <div class="doc-actions">
                <a id="docOpenNew" href="#" target="_blank" rel="noopener" class="doc-btn" title="Open in new tab">
                    <i class="fas fa-external-link-alt"></i><span class="btn-text">Open</span>
                </a>
                <a id="docDownload" href="#" class="doc-btn" title="Download">
                    <i class="fas fa-download"></i><span class="btn-text">Download</span>
                </a>
                <button type="button" class="doc-btn doc-btn-close" onclick="docClose()" title="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>

        <div class="doc-loading" id="docLoading">
            <div class="doc-spinner"></div>
            <p>Loading document…</p>
        </div>

        <div class="doc-body" id="docBody" style="display:none;"></div>

        <div class="doc-error" id="docError" style="display:none;">
            <i class="fas fa-exclamation-triangle"></i>
            <h3>Cannot Open Document</h3>
            <p id="docErrorMsg">Please try again.</p>
            <button type="button" class="doc-btn doc-btn-primary" onclick="docClose()">
                <i class="fas fa-arrow-left"></i> Close
            </button>
        </div>
    </div>
</div>

<!-- FOLDER OVERLAY -->
<div id="folderOverlay" class="doc-overlay" aria-hidden="true">
    <div class="doc-panel">
        <div class="doc-topbar">
            <div class="doc-title">
                <i class="fas fa-folder-open" id="folderIcon"></i>
                <div>
                    <h4 id="folderName">Loading…</h4>
                    <span id="folderMeta">Please wait</span>
                </div>
            </div>
            <div class="doc-actions">
                <a id="folderOpenNew" href="#" target="_blank" rel="noopener" class="doc-btn" title="Open folder page">
                    <i class="fas fa-external-link-alt"></i><span class="btn-text">Open</span>
                </a>
                <button type="button" class="doc-btn doc-btn-close" onclick="folderClose()" title="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>

        <div class="doc-loading" id="folderLoading">
            <div class="doc-spinner"></div>
            <p>Loading folder contents…</p>
        </div>

        <div class="doc-body folder-body" id="folderBody" style="display:none;"></div>

        <div class="doc-error" id="folderError" style="display:none;">
            <i class="fas fa-folder-open"></i>
            <h3>Cannot Open Folder</h3>
            <p id="folderErrorMsg">Please try again.</p>
            <button type="button" class="doc-btn doc-btn-primary" onclick="folderClose()">
                <i class="fas fa-arrow-left"></i> Close
            </button>
        </div>
    </div>
</div>

<!-- SIDEBAR NAVIGATION -->
<nav class="sidebar" id="sidebar" aria-label="Main navigation">
    <div class="sidebar-header">
        <div class="logo-wrapper"><i class="fas fa-archive"></i></div>
        <h3>USAT Archiving</h3>
        <small>Document Management System</small>
    </div>

    <div class="sidebar-menu">
        <a href="dashboard.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : ''; ?>">
            <i class="fas fa-tachometer-alt"></i><span>Dashboard</span>
        </a>

        <a href="#" class="menu-item" onclick="event.preventDefault(); qrOpen();">
            <i class="fas fa-qrcode"></i><span>QR Scanner</span>
            <?php if ($today_scans > 0): ?>
                <span class="badge scan-count"><?php echo $today_scans; ?></span>
            <?php endif; ?>
        </a>

        <a href="files.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) === 'files.php' ? 'active' : ''; ?>">
            <i class="fas fa-cloud-upload-alt"></i><span>File Manager</span>
        </a>

        <a href="reports.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) === 'reports.php' ? 'active' : ''; ?>">
            <i class="fas fa-chart-bar"></i><span>Reports</span>
        </a>

        <a href="change_password.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) === 'change_password.php' ? 'active' : ''; ?>">
            <i class="fas fa-key"></i><span>Change Password</span>
        </a>

        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
            <div class="menu-divider"><small><i class="fas fa-shield-alt"></i> ADMIN CONTROLS</small></div>

            <a href="scan_history.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) === 'scan_history.php' ? 'active' : ''; ?>">
                <i class="fas fa-qrcode"></i><span>Scan History</span>
                <?php if ($total_scans > 0): ?><span class="badge scan-count"><?php echo $total_scans; ?></span><?php endif; ?>
            </a>

            <a href="manage_users.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) === 'manage_users.php' ? 'active' : ''; ?>">
                <i class="fas fa-users-cog"></i><span>Manage Users</span>
            </a>

            <a href="activity_log.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) === 'activity_log.php' ? 'active' : ''; ?>">
                <i class="fas fa-history"></i><span>Activity Log</span>
            </a>

            <a href="trash_bin.php" class="menu-item <?php echo basename($_SERVER['PHP_SELF']) === 'trash_bin.php' ? 'active' : ''; ?>">
                <i class="fas fa-trash-alt"></i><span>Trash Bin</span>
                <?php if ($trash_count > 0): ?><span class="badge trash-count"><?php echo $trash_count; ?></span><?php endif; ?>
            </a>
        <?php endif; ?>

        <a href="logout.php" class="menu-item">
            <i class="fas fa-sign-out-alt"></i><span>Logout</span>
        </a>
    </div>
</nav>

<!-- ============================================
     STYLES
     ============================================ -->
<style>
.sidebar {
    width: 280px;
    background: linear-gradient(180deg, #1a1a2e 0%, #16213e 100%);
    color: #fff;
    height: 100vh;
    position: fixed;
    left: 0; top: 0;
    overflow-y: auto;
    z-index: 1040;
    transition: transform 0.3s ease;
    will-change: transform;
}
.sidebar::-webkit-scrollbar { width: 5px; }
.sidebar::-webkit-scrollbar-track { background: rgba(255,255,255,0.1); }
.sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.3); border-radius: 10px; }

.sidebar-header { padding: 25px 20px; text-align: center; border-bottom: 1px solid rgba(255,255,255,0.1); }
.logo-wrapper { width: 60px; height: 60px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 15px; display: flex; align-items: center; justify-content: center; margin: 0 auto 15px; box-shadow: 0 5px 15px rgba(102,126,234,0.3); }
.logo-wrapper i { font-size: 30px; color: #fff; }
.sidebar-header h3 { font-size: 18px; margin-bottom: 5px; font-weight: 600; }
.sidebar-header small { font-size: 11px; opacity: 0.7; }

.sidebar-menu { padding: 20px 15px 40px; }
.menu-item { display: flex; align-items: center; gap: 12px; padding: 12px 15px; margin: 5px 0; border-radius: 12px; color: rgba(255,255,255,0.8); text-decoration: none; transition: all 0.25s ease; cursor: pointer; font-size: 14px; }
.menu-item i { width: 22px; font-size: 16px; text-align: center; }
.menu-item span:not(.badge) { flex: 1; }
.menu-item:hover { background: rgba(255,255,255,0.1); color: #fff; transform: translateX(4px); }
.menu-item.active { background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; box-shadow: 0 5px 15px rgba(102,126,234,0.3); }
.menu-item .badge { padding: 3px 8px; border-radius: 20px; font-size: 10px; font-weight: 600; margin-left: auto; }
.menu-item .badge.scan-count { background: #10b981; color: #fff; }
.menu-item .badge.trash-count { background: #ef4444; color: #fff; }

.menu-divider { padding: 15px 15px 5px; margin-top: 10px; }
.menu-divider small { font-size: 10px; opacity: 0.5; text-transform: uppercase; letter-spacing: 1px; display: flex; align-items: center; gap: 5px; }

.sidebar-toggle {
    display: none;
    position: fixed; top: 16px; left: 16px; z-index: 1060;
    width: 44px; height: 44px; border-radius: 12px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: #fff; border: none; cursor: pointer;
    align-items: center; justify-content: center;
    font-size: 18px;
    box-shadow: 0 4px 14px rgba(0,0,0,0.18);
    transition: transform 0.2s ease, box-shadow 0.2s ease;
    font-family: inherit;
    -webkit-tap-highlight-color: transparent;
}
.sidebar-toggle:hover { transform: scale(1.05); box-shadow: 0 6px 20px rgba(102,126,234,0.4); }
.sidebar-toggle:active { transform: scale(0.96); }
.sidebar-toggle:focus-visible { outline: 2px solid #667eea; outline-offset: 3px; }

.sidebar-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); backdrop-filter: blur(2px); -webkit-backdrop-filter: blur(2px); z-index: 1030; opacity: 0; transition: opacity 0.3s ease; }
.sidebar-backdrop.show { display: block; opacity: 1; }

#qrScannerModal .modal-content { border-radius: 20px; border: none; }
#qrScannerModal .modal-header { background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; border-radius: 20px 20px 0 0; border: none; }
#qrScannerModal .modal-header .btn-close { filter: brightness(0) invert(1); }
#qr-reader { width: 100%; max-width: 500px; margin: 0 auto; border: 3px dashed #e2e8f0; border-radius: 16px; overflow: hidden; background: #f8fafc; min-height: 280px; }
#qr-reader video { border-radius: 12px; }
.qr-status { padding: 12px; font-size: 14px; text-align: center; margin-top: 15px; }
.qr-status i { margin-right: 8px; }
.qr-info    { color: #3b82f6; }
.qr-success { color: #10b981; }
.qr-error   { color: #ef4444; }

.doc-overlay { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.8); backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); z-index: 1070; display: none; align-items: center; justify-content: center; padding: 20px; opacity: 0; transition: opacity 0.25s ease; }
.doc-overlay.show { display: flex; opacity: 1; }
.doc-panel { background: #fff; width: 100%; max-width: 1100px; height: 92vh; max-height: 900px; border-radius: 20px; box-shadow: 0 25px 60px rgba(0,0,0,0.4); display: flex; flex-direction: column; overflow: hidden; transform: translateY(20px) scale(0.98); transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.doc-overlay.show .doc-panel { transform: translateY(0) scale(1); }
.doc-topbar { display: flex; align-items: center; justify-content: space-between; padding: 14px 20px; background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; gap: 16px; flex-shrink: 0; }
.doc-title { display: flex; align-items: center; gap: 14px; min-width: 0; flex: 1; }
.doc-title > i { font-size: 22px; background: rgba(255,255,255,0.15); width: 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.doc-title > div { min-width: 0; flex: 1; }
.doc-title h4 { font-size: 15px; font-weight: 700; margin: 0 0 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.doc-title span { font-size: 11.5px; opacity: 0.85; font-family: 'SF Mono', Monaco, monospace; }
.doc-actions { display: flex; gap: 8px; flex-shrink: 0; }
.doc-btn { background: rgba(255,255,255,0.15); color: #fff; border: none; padding: 9px 14px; border-radius: 10px; font-size: 13px; font-weight: 600; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; font-family: inherit; transition: all 0.2s; white-space: nowrap; }
.doc-btn:hover { background: rgba(255,255,255,0.28); color: #fff; transform: translateY(-1px); }
.doc-btn-close { background: rgba(239,68,68,0.9); padding: 9px 12px; }
.doc-btn-close:hover { background: #dc2626; }
.doc-btn-primary { background: linear-gradient(135deg, #667eea, #764ba2); padding: 12px 24px; font-size: 14px; }
.doc-btn-primary:hover { background: linear-gradient(135deg, #5a67d8, #6b3fa0); }
.doc-loading { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 20px; color: #64748b; }
.doc-spinner { width: 48px; height: 48px; border: 4px solid #e2e8f0; border-top-color: #667eea; border-radius: 50%; animation: docSpin 0.8s linear infinite; }
@keyframes docSpin { to { transform: rotate(360deg); } }
.doc-body { flex: 1; overflow: auto; background: #f1f5f9; display: flex; align-items: center; justify-content: center; padding: 16px; }
.doc-body iframe { width: 100%; height: 100%; border: none; border-radius: 10px; background: #fff; box-shadow: 0 2px 12px rgba(0,0,0,0.06); }
.doc-body img { max-width: 100%; max-height: 100%; border-radius: 10px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); object-fit: contain; }
.doc-body .download-prompt { text-align: center; padding: 60px 30px; color: #475569; }
.doc-body .download-prompt i { font-size: 80px; color: #667eea; margin-bottom: 24px; display: block; }
.doc-body .download-prompt h3 { font-size: 18px; font-weight: 700; color: #1e293b; margin-bottom: 8px; }
.doc-body .download-prompt p { font-size: 14px; color: #64748b; margin-bottom: 24px; line-height: 1.6; }
.doc-error { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; text-align: center; padding: 40px 24px; color: #475569; }
.doc-error > i { font-size: 72px; color: #f59e0b; margin-bottom: 20px; }
.doc-error h3 { font-size: 20px; font-weight: 700; color: #1e293b; margin-bottom: 8px; }
.doc-error p { font-size: 14px; color: #64748b; margin-bottom: 24px; max-width: 400px; line-height: 1.6; }

.folder-body { display: block !important; align-items: stretch !important; justify-content: flex-start !important; padding: 20px !important; overflow-y: auto; }
.folder-breadcrumb { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; font-size: 12.5px; color: #64748b; margin-bottom: 16px; padding: 8px 12px; background: #fff; border-radius: 10px; border: 1px solid #e2e8f0; }
.folder-breadcrumb a { color: #667eea; text-decoration: none; cursor: pointer; font-weight: 600; }
.folder-breadcrumb a:hover { text-decoration: underline; }
.folder-breadcrumb i.fa-chevron-right { font-size: 9px; opacity: 0.5; }
.folder-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 14px; width: 100%; }
.folder-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 14px; padding: 16px; display: flex; flex-direction: column; gap: 8px; cursor: pointer; transition: all 0.2s ease; text-decoration: none; color: inherit; }
.folder-card:hover { border-color: #667eea; box-shadow: 0 6px 20px rgba(102,126,234,0.18); transform: translateY(-2px); color: inherit; }
.folder-card .fc-icon { width: 42px; height: 42px; border-radius: 10px; background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 18px; }
.folder-card.folder .fc-icon { background: linear-gradient(135deg, #f59e0b, #d97706); }
.folder-card .fc-name { font-size: 13.5px; font-weight: 600; color: #1e293b; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.folder-card .fc-meta { font-size: 11px; color: #64748b; }
.folder-empty { text-align: center; padding: 60px 20px; color: #64748b; grid-column: 1 / -1; }
.folder-empty i { font-size: 56px; color: #cbd5e1; margin-bottom: 16px; display: block; }
.folder-empty h3 { font-size: 16px; color: #1e293b; margin-bottom: 6px; }
.folder-empty p { font-size: 13px; }

@media (max-width: 768px) {
    .sidebar { transform: translateX(-100%); box-shadow: 4px 0 24px rgba(0,0,0,0.2); }
    .sidebar.open { transform: translateX(0); }
    .sidebar-toggle { display: inline-flex; }
    .doc-overlay { padding: 0; }
    .doc-panel { height: 100vh; max-height: 100vh; max-width: 100%; border-radius: 0; }
    .doc-topbar { padding: 12px 14px; gap: 10px; }
    .doc-title > i { width: 38px; height: 38px; font-size: 18px; }
    .doc-title h4 { font-size: 13.5px; }
    .doc-title span { font-size: 10.5px; }
    .doc-btn .btn-text { display: none; }
    .doc-btn { padding: 9px 11px; }
    .doc-body { padding: 8px; }
    .folder-body { padding: 12px !important; }
    .folder-grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; }
}
</style>

<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

<script>
/* ============================================
   SIDEBAR TOGGLE
   ============================================ */
(function() {
    'use strict';
    var sidebar  = document.getElementById('sidebar');
    var toggle   = document.getElementById('sidebarToggle');
    var icon     = document.getElementById('sidebarToggleIcon');
    var backdrop = document.getElementById('sidebarBackdrop');
    if (!sidebar || !toggle) return;

    function isMobile() { return window.innerWidth <= 768; }
    function openSidebar() {
        sidebar.classList.add('open');
        if (backdrop) backdrop.classList.add('show');
        if (icon) { icon.classList.remove('fa-bars'); icon.classList.add('fa-times'); }
        toggle.setAttribute('aria-expanded', 'true');
        document.body.style.overflow = 'hidden';
    }
    function closeSidebar() {
        sidebar.classList.remove('open');
        if (backdrop) backdrop.classList.remove('show');
        if (icon) { icon.classList.remove('fa-times'); icon.classList.add('fa-bars'); }
        toggle.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';
    }
    function toggleSidebar() {
        sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
    }
    toggle.addEventListener('click', function(e) {
        e.preventDefault(); e.stopPropagation(); toggleSidebar();
    });
    if (backdrop) backdrop.addEventListener('click', closeSidebar);
    sidebar.querySelectorAll('a.menu-item').forEach(function(link) {
        link.addEventListener('click', function() { if (isMobile()) closeSidebar(); });
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && sidebar.classList.contains('open')) closeSidebar();
    });
    var resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(function() { if (!isMobile()) closeSidebar(); }, 150);
    });
    window.openSidebar = openSidebar;
    window.closeSidebar = closeSidebar;
    window.toggleSidebar = toggleSidebar;
})();

/* ============================================
   QR SCANNER
   ============================================ */
let qrScanner = null;
let qrRunning = false;
let qrModalInstance = null;

document.addEventListener('DOMContentLoaded', function() {
    const modalEl = document.getElementById('qrScannerModal');
    if (modalEl && typeof bootstrap !== 'undefined') {
        qrModalInstance = new bootstrap.Modal(modalEl);
    }
    if (modalEl) {
        modalEl.addEventListener('hidden.bs.modal', function() { qrStop(); });
    }
});

function qrOpen() {
    const modalEl = document.getElementById('qrScannerModal');
    if (!modalEl) return;
    if (!qrModalInstance && typeof bootstrap !== 'undefined') {
        qrModalInstance = new bootstrap.Modal(modalEl);
    }
    if (qrModalInstance) {
        qrModalInstance.show();
    } else {
        modalEl.style.display = 'block';
        modalEl.classList.add('show');
    }
    qrSetStatus('info', 'info-circle', 'Click "Start Scanner" to begin');
    document.getElementById('qr-start-btn').style.display = 'inline-block';
    document.getElementById('qr-stop-btn').style.display = 'none';
}

function qrStart() {
    const startBtn = document.getElementById('qr-start-btn');
    const stopBtn  = document.getElementById('qr-stop-btn');
    if (qrRunning) return;

    if (typeof Html5Qrcode === 'undefined') {
        qrSetStatus('error', 'exclamation-triangle', 'QR library not loaded. Refresh the page.');
        return;
    }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
        qrSetStatus('error', 'exclamation-triangle', 'Camera not supported on this device.');
        return;
    }

    startBtn.style.display = 'none';
    stopBtn.style.display  = 'inline-block';
    qrSetStatus('info', 'spinner fa-spin', 'Accessing camera...');

    qrScanner = new Html5Qrcode('qr-reader');
    qrScanner.start(
        { facingMode: 'environment' },
        { fps: 10, qrbox: { width: 250, height: 250 }, aspectRatio: 1.0 },
        qrOnSuccess,
        () => {}
    ).then(() => {
        qrRunning = true;
        qrSetStatus('success', 'check-circle', 'Camera active. Point at a QR code.');
    }).catch(err => {
        console.error('Scanner start error:', err);
        qrSetStatus('error', 'exclamation-triangle', err.message || 'Failed to start camera');
        qrResetUI();
    });
}

function qrStop() {
    if (qrScanner && qrRunning) {
        qrScanner.stop().then(() => {
            qrRunning = false; qrResetUI();
            qrSetStatus('info', 'info-circle', 'Scanner stopped.');
        }).catch(() => { qrRunning = false; qrResetUI(); });
    } else {
        qrResetUI();
    }
}
function qrResetUI() {
    qrRunning = false;
    const s = document.getElementById('qr-start-btn');
    const e = document.getElementById('qr-stop-btn');
    if (s) s.style.display = 'inline-block';
    if (e) e.style.display = 'none';
}
function qrSetStatus(type, icon, text) {
    const el = document.getElementById('qr-status');
    if (!el) return;
    el.className = 'qr-status qr-' + type;
    el.innerHTML = '<i class="fas fa-' + icon + '"></i> ' + text;
}

/* ============================================
   QR SCAN SUCCESS
   ============================================ */
function qrOnSuccess(decodedText) {
    console.log('[QR] Scanned:', decodedText);

    const target = extractScanTarget(decodedText);
    if (!target) {
        qrSetStatus('error', 'exclamation-triangle',
            'Invalid QR. Content: "' + String(decodedText).substring(0, 60) + '"');
        return;
    }

    qrStop();

    if (target.type === 'folder') {
        qrSetStatus('success', 'folder-open', 'Folder found! Opening…');
        hideQrModal();
        setTimeout(() => openFolderOverlay(target.id), 250);
    } else {
        qrSetStatus('success', 'check-circle', 'Document found! Opening…');
        hideQrModal();
        setTimeout(() => openDocOverlay(target.id), 250);
    }
}

function hideQrModal() {
    if (qrModalInstance) {
        qrModalInstance.hide();
    } else {
        const modalEl = document.getElementById('qrScannerModal');
        if (modalEl) {
            modalEl.style.display = 'none';
            modalEl.classList.remove('show');
        }
    }
}

/* ============================================
   SMART PARSER — handles YOUR app's QR formats
   ============================================ */
function extractScanTarget(text) {
    if (!text) return null;
    text = String(text).trim();

    /* 1. YOUR APP — files.php?folder=X  → FOLDER */
    let m = text.match(/files\.php\??[^\d]*(?:folder|folder_id|f|dir|category_id)=([\w\-]+)/i);
    if (m) return { type: 'folder', id: m[1] };

    /* 2. folder.php?id=X / folder_view.php?id=X → FOLDER */
    m = text.match(/folder(?:_view)?\.php\??[^\d]*id=([\w\-]+)/i);
    if (m) return { type: 'folder', id: m[1] };

    /* 3. view_document.php?id=X / document.php?id=X / file.php?id=X → FILE */
    m = text.match(/(?:view_?document|document|file)\.php\??[^\d]*id=([\w\-]+)/i);
    if (m) return { type: 'file', id: m[1] };

    /* 4. USAT-FOLDER-X / USAT-FILE-X */
    m = text.match(/usat[\s\-_\/]+(folder|file)[\s\-_\/]+([\w\-]+)$/i);
    if (m) return { type: m[1].toLowerCase(), id: m[2] };

    /* 5. folder:X | folder=X | FOLDER-X | folder/X | folder X */
    m = text.match(/^folder[\s:\-=\/]+([\w\-]+)$/i);
    if (m) return { type: 'folder', id: m[1] };

    /* 6. file:X | file=X | FILE-X */
    m = text.match(/^file[\s:\-=\/]+([\w\-]+)$/i);
    if (m) return { type: 'file', id: m[1] };

    /* 7. Generic ?X=Y → file */
    m = text.match(/[?&][\w_]+=([\w\-]+)/i);
    if (m) return { type: 'file', id: m[1] };

    /* 8. Bare number → file */
    if (/^\d+$/.test(text)) return { type: 'file', id: text };

    return null;
}

/* ============================================
   DOCUMENT OVERLAY
   ============================================ */
function openDocOverlay(fileId) {
    const overlay = document.getElementById('docOverlay');
    const loading = document.getElementById('docLoading');
    const body    = document.getElementById('docBody');
    const errorEl = document.getElementById('docError');
    const nameEl  = document.getElementById('docName');
    const metaEl  = document.getElementById('docMeta');
    const iconEl  = document.getElementById('docIcon');

    loading.style.display = 'flex';
    body.style.display    = 'none';
    body.innerHTML        = '';
    errorEl.style.display = 'none';
    nameEl.textContent    = 'Loading…';
    metaEl.textContent    = 'Please wait';

    overlay.classList.add('show');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';

    const url = 'view_document.php?id=' + encodeURIComponent(fileId) + '&format=json';
    console.log('[DocViewer] Fetching:', url);

    fetch(url, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        cache: 'no-store'
    })
    .then(res => res.text())
    .then(text => {
        let data;
        try { data = JSON.parse(text); }
        catch (e) {
            console.error('[DocViewer] Non-JSON:', text.substring(0, 300));
            showDocError('Server returned invalid response. Check that view_document.php outputs JSON when format=json.');
            return;
        }
        if (!data.success) { showDocError(data.error || 'Document not found.'); return; }

        nameEl.textContent = data.file_name || 'Document';
        metaEl.textContent = (data.control_number || 'N/A') + ' • ' + (data.file_ext || 'file').toUpperCase();
        iconEl.className   = 'fas ' + (data.icon || 'fa-file');

        document.getElementById('docOpenNew').href  = data.file_url || '#';
        document.getElementById('docDownload').href = 'download_file.php?id=' + encodeURIComponent(fileId);

        renderDocBody(data);
    })
    .catch(err => {
        console.error('[DocViewer] Fetch failed:', err);
        showDocError('Could not connect to server: ' + err.message);
    });
}

function renderDocBody(data) {
    const loading = document.getElementById('docLoading');
    const body    = document.getElementById('docBody');
    const errorEl = document.getElementById('docError');

    loading.style.display = 'none';
    if (!data.file_exists) {
        showDocError('File is missing on the server. Path: ' + (data.file_path || 'unknown'));
        return;
    }
    body.innerHTML = '';

    if (data.view_type === 'image') {
        const img = document.createElement('img');
        img.src = data.file_url;
        img.alt = data.file_name;
        img.onerror = () => showDocError('Image could not be loaded.');
        body.appendChild(img);
    } else if (data.view_type === 'pdf' || data.view_type === 'text') {
        const iframe = document.createElement('iframe');
        iframe.src = data.file_url + (data.view_type === 'pdf' ? '#toolbar=1&view=FitH' : '');
        iframe.title = data.file_name;
        body.appendChild(iframe);
    } else {
        body.innerHTML =
            '<div class="download-prompt">' +
                '<i class="fas ' + (data.icon || 'fa-file') + '"></i>' +
                '<h3>' + esc(data.file_name) + '</h3>' +
                '<p>This file type (' + esc((data.file_ext || '').toUpperCase()) + ') cannot be previewed in the browser.</p>' +
                '<a href="download_file.php?id=' + encodeURIComponent(data.file_id) + '" class="doc-btn doc-btn-primary">' +
                    '<i class="fas fa-download"></i> Download to View' +
                '</a>' +
            '</div>';
    }
    body.style.display = 'flex';
    errorEl.style.display = 'none';
}

function showDocError(message) {
    document.getElementById('docLoading').style.display = 'none';
    document.getElementById('docBody').style.display    = 'none';
    document.getElementById('docError').style.display   = 'flex';
    document.getElementById('docErrorMsg').textContent  = message;
}

function docClose() {
    const overlay = document.getElementById('docOverlay');
    overlay.classList.remove('show');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    setTimeout(() => {
        const body = document.getElementById('docBody');
        if (body) body.innerHTML = '';
    }, 300);
}

/* ============================================
   FOLDER OVERLAY
   ============================================ */
let folderHistory = [];

function openFolderOverlay(folderId) {
    const overlay  = document.getElementById('folderOverlay');
    const loading  = document.getElementById('folderLoading');
    const body     = document.getElementById('folderBody');
    const errorEl  = document.getElementById('folderError');
    const nameEl   = document.getElementById('folderName');
    const metaEl   = document.getElementById('folderMeta');

    loading.style.display = 'flex';
    body.style.display    = 'none';
    body.innerHTML        = '';
    errorEl.style.display = 'none';
    nameEl.textContent    = 'Loading…';
    metaEl.textContent    = 'Please wait';

    overlay.classList.add('show');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';

    const url = 'folder.php?id=' + encodeURIComponent(folderId) + '&format=json';
    console.log('[FolderViewer] Fetching:', url);

    fetch(url, {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        cache: 'no-store'
    })
    .then(res => res.text())
    .then(text => {
        let data;
        try { data = JSON.parse(text); }
        catch (e) {
            console.error('[FolderViewer] Non-JSON:', text.substring(0, 500));
            showFolderError('Server returned invalid response (not JSON).');
            return;
        }
        if (!data.success) {
            showFolderError(data.error || 'Folder not found.');
            return;
        }

        nameEl.textContent = data.folder_name || 'Folder';
        metaEl.textContent = (data.item_count || 0) + ' item(s) • ' + (data.folder_code || '');
        document.getElementById('folderOpenNew').href = data.folder_url || ('files.php?folder=' + data.folder_id);

        folderHistory = data.breadcrumb && data.breadcrumb.length
            ? data.breadcrumb.map(b => ({ id: b.id, name: b.name }))
            : [{ id: data.folder_id, name: data.folder_name || 'Folder' }];

        renderFolderBody(data);
    })
    .catch(err => {
        console.error('[FolderViewer] Fetch failed:', err);
        showFolderError('Could not connect to server: ' + err.message);
    });
}

function renderFolderBody(data) {
    const loading = document.getElementById('folderLoading');
    const body    = document.getElementById('folderBody');
    const items   = data.items || [];

    loading.style.display = 'none';
    body.innerHTML = '';

    if (folderHistory.length > 0) {
        const bc = document.createElement('div');
        bc.className = 'folder-breadcrumb';
        folderHistory.forEach((crumb, idx) => {
            const a = document.createElement('a');
            a.textContent = crumb.name;
            a.addEventListener('click', function(e) {
                e.preventDefault();
                folderHistory = folderHistory.slice(0, idx + 1);
                openFolderOverlay(crumb.id);
            });
            bc.appendChild(a);
            if (idx < folderHistory.length - 1) {
                const sep = document.createElement('i');
                sep.className = 'fas fa-chevron-right';
                bc.appendChild(sep);
            }
        });
        body.appendChild(bc);
    }

    if (items.length === 0) {
        const empty = document.createElement('div');
        empty.className = 'folder-empty';
        empty.innerHTML = '<i class="fas fa-folder-open"></i><h3>This folder is empty</h3><p>No documents or subfolders yet.</p>';
        body.appendChild(empty);
    } else {
        const grid = document.createElement('div');
        grid.className = 'folder-grid';

        items.forEach(item => {
            const a = document.createElement('a');
            a.href = '#';
            a.className = 'folder-card' + (item.type === 'folder' ? ' folder' : '');
            a.innerHTML =
                '<div class="fc-icon"><i class="fas ' + (item.icon || (item.type === 'folder' ? 'fa-folder' : 'fa-file')) + '"></i></div>' +
                '<div class="fc-name" title="' + esc(item.name) + '">' + esc(item.name) + '</div>' +
                '<div class="fc-meta">' +
                    (item.type === 'folder'
                        ? (item.item_count || 0) + ' items'
                        : (item.file_ext || 'file').toUpperCase() + (item.size_human ? ' • ' + item.size_human : '')) +
                '</div>';

            a.addEventListener('click', function(ev) {
                ev.preventDefault();
                if (item.type === 'folder') {
                    // FOLDER: drill into subfolder (folder overlay stays open)
                    folderHistory.push({ id: item.id, name: item.name });
                    openFolderOverlay(item.id);
                } else {
                    // FILE: hide folder overlay FIRST, then open doc overlay
                    const overlay = document.getElementById('folderOverlay');
                    overlay.classList.remove('show');
                    overlay.setAttribute('aria-hidden', 'true');
                    folderHistory = [];
                    const fbody = document.getElementById('folderBody');
                    if (fbody) fbody.innerHTML = '';
                    document.body.style.overflow = '';
                    // Wait for close transition, then open document
                    setTimeout(function() {
                        openDocOverlay(item.id);
                    }, 220);
                }
            });

            grid.appendChild(a);
        });

        body.appendChild(grid);
    }

    body.style.display = 'block';
}

function showFolderError(message) {
    document.getElementById('folderLoading').style.display = 'none';
    document.getElementById('folderBody').style.display    = 'none';
    document.getElementById('folderError').style.display   = 'flex';
    document.getElementById('folderErrorMsg').textContent  = message;
}

function folderClose() {
    const overlay = document.getElementById('folderOverlay');
    overlay.classList.remove('show');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
    folderHistory = [];
    setTimeout(() => {
        const body = document.getElementById('folderBody');
        if (body) body.innerHTML = '';
    }, 300);
}

/* ============================================
   GLOBAL ESC + BACKDROP
   ============================================ */
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const d = document.getElementById('docOverlay');
        const f = document.getElementById('folderOverlay');
        if (d && d.classList.contains('show')) docClose();
        else if (f && f.classList.contains('show')) folderClose();
    }
});
document.addEventListener('click', function(e) {
    const d = document.getElementById('docOverlay');
    const f = document.getElementById('folderOverlay');
    if (d && e.target === d) docClose();
    if (f && e.target === f) folderClose();
});

/* ---------- UTIL ---------- */
function esc(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

window.addEventListener('beforeunload', function() {
    if (qrScanner && qrRunning) qrScanner.stop().catch(() => {});
});
</script>