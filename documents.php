<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

// Check login
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$student_id = isset($_GET['student_id']) ? (int)$_GET['student_id'] : 0;
$show_archived = isset($_GET['show_archived']) ? $_GET['show_archived'] : '0';
$search_control = isset($_GET['search_control']) ? trim($_GET['search_control']) : '';
$success = isset($_GET['success']) ? $_GET['success'] : '';
$error = isset($_GET['error']) ? $_GET['error'] : '';

if ($student_id > 0) {
    // Get student
    if (isAdmin()) {
        $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
        $stmt->execute([$student_id]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ? AND created_by = ?");
        $stmt->execute([$student_id, getCurrentUserId()]);
    }
    $student = $stmt->fetch();
    
    if (!$student) {
        header("Location: documents.php?error=" . urlencode("Student not found"));
        exit();
    }
    
    // Get documents (filter by archive status)
    if ($show_archived == '1') {
        $stmt = $pdo->prepare("SELECT * FROM documents WHERE student_id = ? AND is_archived = 1 ORDER BY archived_at DESC");
    } else {
        $stmt = $pdo->prepare("SELECT * FROM documents WHERE student_id = ? AND (is_archived = 0 OR is_archived IS NULL) ORDER BY uploaded_at DESC");
    }
    $stmt->execute([$student_id]);
    $documents = $stmt->fetchAll();
    
    // Get archived count
    $archivedCount = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE student_id = ? AND is_archived = 1");
    $archivedCount->execute([$student_id]);
    $archived_count = $archivedCount->fetchColumn();
    
} else {
    // Get all students
    if (isAdmin()) {
        $students = $pdo->query("SELECT * FROM students WHERE status = 'active' ORDER BY first_name")->fetchAll();
    } else {
        $stmt = $pdo->prepare("SELECT * FROM students WHERE status = 'active' AND created_by = ? ORDER BY first_name");
        $stmt->execute([getCurrentUserId()]);
        $students = $stmt->fetchAll();
    }
}

// Search by control number
$search_result = null;
$search_documents = [];
if (!empty($search_control)) {
    if (isAdmin()) {
        $stmt = $pdo->prepare("SELECT d.*, s.first_name, s.last_name, s.student_id 
                               FROM documents d 
                               JOIN students s ON d.student_id = s.id 
                               WHERE d.control_number LIKE ? OR d.document_name LIKE ?");
        $stmt->execute(["%$search_control%", "%$search_control%"]);
    } else {
        $stmt = $pdo->prepare("SELECT d.*, s.first_name, s.last_name, s.student_id 
                               FROM documents d 
                               JOIN students s ON d.student_id = s.id 
                               WHERE s.created_by = ? AND (d.control_number LIKE ? OR d.document_name LIKE ?)");
        $stmt->execute([getCurrentUserId(), "%$search_control%", "%$search_control%"]);
    }
    $search_documents = $stmt->fetchAll();
}

include 'includes/header.php';
?>

<style>
.upload-area {
    border: 2px dashed #ccc;
    border-radius: 15px;
    padding: 30px;
    text-align: center;
    background: #fafafa;
    transition: all 0.3s ease;
    margin-bottom: 30px;
}
.upload-area:hover {
    border-color: #667eea;
    background: #f0f4ff;
}
.document-card {
    border: 1px solid #e0e0e0;
    border-radius: 12px;
    padding: 15px;
    margin-bottom: 15px;
    background: white;
    transition: all 0.3s ease;
    position: relative;
}
.document-card.archived {
    background: #f8f9fa;
    opacity: 0.8;
    border-left: 4px solid #f39c12;
}
.document-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}
.student-card {
    border: 1px solid #e0e0e0;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 15px;
    cursor: pointer;
    transition: all 0.3s ease;
    background: white;
}
.student-card:hover {
    transform: translateX(5px);
    border-color: #667eea;
    background: #f0f4ff;
}
.qr-code {
    width: 80px;
    height: 80px;
    cursor: pointer;
    border-radius: 8px;
    transition: all 0.3s;
    border: 1px solid #ddd;
    padding: 5px;
}
.qr-code:hover {
    transform: scale(1.05);
    box-shadow: 0 5px 15px rgba(0,0,0,0.2);
}
.btn-sm {
    padding: 5px 10px;
    font-size: 12px;
    margin: 2px;
}
.alert {
    padding: 12px 15px;
    border-radius: 8px;
    margin-bottom: 20px;
}
.alert-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}
.alert-danger {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}
.alert-warning {
    background: #fff3cd;
    color: #856404;
    border: 1px solid #ffeeba;
}
.alert-info {
    background: #d1ecf1;
    color: #0c5460;
    border: 1px solid #bee5eb;
}
.archived-badge {
    position: absolute;
    top: 10px;
    right: 10px;
    background: #f39c12;
    color: white;
    padding: 3px 8px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: bold;
}
.modal-qr {
    max-width: 400px;
}
.modal-qr img {
    width: 100%;
    max-width: 250px;
    margin: 0 auto;
    display: block;
}
.tab-buttons {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
}
.tab-btn {
    padding: 8px 20px;
    border: none;
    background: #e0e0e0;
    border-radius: 25px;
    cursor: pointer;
    transition: 0.3s;
    text-decoration: none;
    color: #333;
}
.tab-btn.active {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
}
.search-box {
    background: white;
    border-radius: 12px;
    padding: 15px;
    margin-bottom: 20px;
    box-shadow: 0 2px 5px rgba(0,0,0,0.05);
}
.control-badge {
    background: #667eea;
    color: white;
    padding: 3px 8px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: bold;
    display: inline-block;
}
</style>

<div class="table-container">
    <!-- Search by Control Number Section -->
    <div class="search-box">
        <h5><i class="fas fa-search"></i> Find Document by Control Number</h5>
        <form method="GET" class="row g-2">
            <div class="col-md-8">
                <input type="text" name="search_control" class="form-control" placeholder="Enter Control Number or Document Name..." value="<?php echo htmlspecialchars($search_control); ?>">
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search"></i> Search Document
                </button>
            </div>
        </form>
        
        <?php if(!empty($search_control) && count($search_documents) > 0): ?>
            <div class="mt-3">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle"></i> Found <?php echo count($search_documents); ?> document(s) matching "<strong><?php echo htmlspecialchars($search_control); ?></strong>"
                </div>
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-dark">
                            <tr>
                                <th>Control #</th>
                                <th>Document Name</th>
                                <th>Student</th>
                                <th>Student ID</th>
                                <th>Date Uploaded</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($search_documents as $doc): ?>
                            <tr>
                                <td><span class="control-badge"><?php echo htmlspecialchars($doc['control_number']); ?></span></td>
                                <td><?php echo htmlspecialchars($doc['document_name']); ?></td>
                                <td><?php echo htmlspecialchars($doc['first_name'] . ' ' . $doc['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($doc['student_id']); ?></td>
                                <td><?php echo date('M d, Y', strtotime($doc['uploaded_at'])); ?></td>
                                <td>
                                    <a href="view_document.php?token=<?php echo $doc['view_token']; ?>" target="_blank" class="btn btn-sm btn-primary">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                    <a href="documents.php?student_id=<?php echo $doc['student_id']; ?>" class="btn btn-sm btn-info">
                                        <i class="fas fa-folder-open"></i> View All
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php elseif(!empty($search_control)): ?>
            <div class="alert alert-warning mt-3">
                <i class="fas fa-exclamation-triangle"></i> No documents found matching "<strong><?php echo htmlspecialchars($search_control); ?></strong>"
            </div>
        <?php endif; ?>
    </div>

    <?php if($student_id > 0 && isset($student)): ?>
        <!-- Header with back button -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 10px;">
            <h4>
                <i class="fas fa-file-alt"></i> 
                Documents for: <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                <small class="text-muted">(ID: <?php echo $student['student_id']; ?>)</small>
            </h4>
            <div>
                <a href="documents.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to Students
                </a>
                <a href="students.php" class="btn btn-info">
                    <i class="fas fa-users"></i> All Students
                </a>
            </div>
        </div>
        
        <!-- Archive Tabs -->
        <div class="tab-buttons">
            <a href="?student_id=<?php echo $student_id; ?>&show_archived=0" class="tab-btn <?php echo $show_archived == '0' ? 'active' : ''; ?>">
                <i class="fas fa-file-alt"></i> Active Documents
            </a>
            <a href="?student_id=<?php echo $student_id; ?>&show_archived=1" class="tab-btn <?php echo $show_archived == '1' ? 'active' : ''; ?>">
                <i class="fas fa-archive"></i> Archived Documents 
                <?php if($archived_count > 0): ?>
                    <span class="badge bg-warning"><?php echo $archived_count; ?></span>
                <?php endif; ?>
            </a>
        </div>
        
        <!-- Success/Error Messages -->
        <?php if($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <?php if($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <!-- Upload Form (Only show for active documents tab) -->
        <?php if($show_archived == '0'): ?>
        <div class="upload-area">
            <form action="upload_document.php" method="POST" enctype="multipart/form-data" id="uploadForm">
                <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">
                <i class="fas fa-cloud-upload-alt" style="font-size: 48px; color: #667eea;"></i>
                <h5 class="mt-2">Upload New Document</h5>
                <p class="text-muted">Supported files: PDF, DOC, DOCX, JPG, PNG (Max 5MB)</p>
                <p class="text-muted"><i class="fas fa-qrcode"></i> A unique QR code and Control Number will be generated.</p>
                <div class="row justify-content-center">
                    <div class="col-md-4 mb-2">
                        <input type="text" name="document_name" class="form-control" placeholder="Document Name" required>
                    </div>
                    <div class="col-md-4 mb-2">
                        <input type="file" name="document_file" class="form-control" id="fileInput" required accept=".pdf,.doc,.docx,.jpg,.jpeg,.png">
                    </div>
                    <div class="col-md-2 mb-2">
                        <button type="submit" class="btn btn-primary w-100" id="uploadBtn">
                            <i class="fas fa-upload"></i> Upload
                        </button>
                    </div>
                </div>
            </form>
        </div>
        <?php else: ?>
        <div class="alert alert-warning">
            <i class="fas fa-info-circle"></i> Archived documents are read-only. To restore, click the Restore button.
        </div>
        <?php endif; ?>
        
        <!-- Documents List -->
        <h5 class="mb-3">
            <i class="fas fa-folder-open"></i> 
            <?php echo $show_archived == '1' ? 'Archived Documents' : 'Active Documents'; ?> 
            <span class="badge bg-secondary"><?php echo count($documents); ?></span>
        </h5>
        
        <?php if(count($documents) > 0): ?>
            <div class="row">
                <?php foreach($documents as $doc): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="document-card <?php echo $doc['is_archived'] == 1 ? 'archived' : ''; ?>">
                        <?php if($doc['is_archived'] == 1): ?>
                            <div class="archived-badge">
                                <i class="fas fa-archive"></i> Archived
                            </div>
                        <?php endif; ?>
                        <div class="text-center">
                            <span class="control-badge">
                                <i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($doc['control_number']); ?>
                            </span>
                            <?php
                            $icon = 'fa-file-alt';
                            $color = '#667eea';
                            if ($doc['file_type'] == 'pdf') {
                                $icon = 'fa-file-pdf';
                                $color = '#dc3545';
                            } elseif ($doc['file_type'] == 'doc' || $doc['file_type'] == 'docx') {
                                $icon = 'fa-file-word';
                                $color = '#2b5797';
                            } elseif (in_array($doc['file_type'], ['jpg','jpeg','png'])) {
                                $icon = 'fa-file-image';
                                $color = '#28a745';
                            }
                            ?>
                            <i class="fas <?php echo $icon; ?>" style="font-size: 40px; color: <?php echo $color; ?>;"></i>
                            <h6 class="mt-2 mb-1"><?php echo htmlspecialchars($doc['document_name']); ?></h6>
                            <small class="text-muted">
                                <i class="fas fa-calendar-alt"></i> <?php echo date('M d, Y', strtotime($doc['uploaded_at'])); ?>
                                <br>
                                <i class="fas fa-file"></i> <?php echo strtoupper($doc['file_type']); ?>
                                <?php if($doc['is_archived'] == 1 && $doc['archived_at']): ?>
                                    <br>
                                    <i class="fas fa-archive"></i> Archived: <?php echo date('M d, Y', strtotime($doc['archived_at'])); ?>
                                <?php endif; ?>
                            </small>
                        </div>
                        <div class="text-center mt-3">
                            <?php if($doc['document_qr_code'] && file_exists($doc['document_qr_code'])): ?>
                                <img src="<?php echo $doc['document_qr_code']; ?>" class="qr-code" onclick="showQRModal('<?php echo $doc['document_qr_code']; ?>', '<?php echo htmlspecialchars($doc['document_name']); ?>', '<?php echo $doc['view_token']; ?>')" title="Click to view QR Code">
                            <?php endif; ?>
                        </div>
                        <div class="text-center mt-2">
                            <button class="btn btn-sm btn-secondary" onclick="copyControlNumber('<?php echo $doc['control_number']; ?>')">
                                <i class="fas fa-copy"></i> Copy Control #
                            </button>
                            <?php if(file_exists($doc['file_path'])): ?>
                                <a href="<?php echo $doc['file_path']; ?>" target="_blank" class="btn btn-sm btn-primary">
                                    <i class="fas fa-eye"></i> View
                                </a>
                                <a href="<?php echo $doc['file_path']; ?>" download class="btn btn-sm btn-success">
                                    <i class="fas fa-download"></i> Download
                                </a>
                            <?php endif; ?>
                            
                            <?php if($doc['is_archived'] == 1): ?>
                                <a href="archive_document.php?id=<?php echo $doc['id']; ?>&student_id=<?php echo $student_id; ?>&action=restore" class="btn btn-sm btn-warning" onclick="return confirm('Restore this document?')">
                                    <i class="fas fa-undo"></i> Restore
                                </a>
                            <?php else: ?>
                                <a href="archive_document.php?id=<?php echo $doc['id']; ?>&student_id=<?php echo $student_id; ?>&action=archive" class="btn btn-sm btn-warning" onclick="return confirm('Archive this document? It will be moved to archive.')">
                                    <i class="fas fa-archive"></i> Archive
                                </a>
                            <?php endif; ?>
                            
                            <?php if(isAdmin()): ?>
                                <a href="delete_document.php?id=<?php echo $doc['id']; ?>&student_id=<?php echo $student_id; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Permanently delete this document? This action cannot be undone.')">
                                    <i class="fas fa-trash"></i> Delete
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="text-center mt-2">
                            <small class="text-muted">
                                <i class="fas fa-mobile-alt"></i> Scan QR code with phone to open
                            </small>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="text-center" style="padding: 60px; background: #f8f9fa; border-radius: 15px;">
                <i class="fas fa-folder-open" style="font-size: 64px; color: #ccc;"></i>
                <h5 class="mt-3">No Documents Found</h5>
                <p class="text-muted">
                    <?php if($show_archived == '1'): ?>
                        No archived documents. Archive documents to see them here.
                    <?php else: ?>
                        Use the form above to upload documents for this student.
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>
        
    <?php else: ?>
        <!-- Student Selection Page -->
        <h4 class="mb-4">
            <i class="fas fa-folder-open"></i> Select Student to Manage Documents
        </h4>
        
        <?php if($error): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <div class="row">
            <?php if(count($students) > 0): ?>
                <?php foreach($students as $student): 
                    $docCount = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE student_id = ? AND (is_archived = 0 OR is_archived IS NULL)");
                    $docCount->execute([$student['id']]);
                    $docCountNum = $docCount->fetchColumn();
                    
                    $archivedCount = $pdo->prepare("SELECT COUNT(*) FROM documents WHERE student_id = ? AND is_archived = 1");
                    $archivedCount->execute([$student['id']]);
                    $archivedCountNum = $archivedCount->fetchColumn();
                ?>
                <div class="col-md-6 col-lg-4">
                    <div class="student-card" onclick="window.location.href='documents.php?student_id=<?php echo $student['id']; ?>'">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <i class="fas fa-user-graduate" style="font-size: 24px; color: #667eea;"></i>
                            </div>
                            <div>
                                <span class="badge bg-primary"><?php echo $docCountNum; ?> active</span>
                                <?php if($archivedCountNum > 0): ?>
                                    <span class="badge bg-warning"><?php echo $archivedCountNum; ?> archived</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <h5 class="mt-2 mb-1"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h5>
                        <p class="text-muted mb-1">
                            <i class="fas fa-id-card"></i> <?php echo $student['student_id']; ?>
                        </p>
                        <p class="text-muted mb-0">
                            <i class="fas fa-graduation-cap"></i> <?php echo $student['course']; ?> - <?php echo $student['year_level']; ?>
                        </p>
                        <hr class="my-2">
                        <small class="text-primary">
                            <i class="fas fa-folder-open"></i> Manage Documents →
                        </small>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="col-12">
                    <div class="text-center" style="padding: 80px; background: #f8f9fa; border-radius: 15px;">
                        <i class="fas fa-users" style="font-size: 64px; color: #ccc;"></i>
                        <h4 class="mt-3">No Students Found</h4>
                        <p class="text-muted">You haven't added any students yet.</p>
                        <a href="add_student.php" class="btn btn-primary">
                            <i class="fas fa-plus"></i> Add Your First Student
                        </a>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- QR Code Modal -->
<div class="modal fade" id="qrModal" tabindex="-1">
    <div class="modal-dialog modal-qr">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-qrcode"></i> Document QR Code</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <p>Scan this QR code with your phone to open the document.</p>
                <img id="modalQRImage" src="" style="max-width: 250px; margin: 20px auto; border: 1px solid #ddd; border-radius: 10px;">
                <p id="modalDocName" class="mt-3"></p>
                <div class="alert alert-info">
                    <i class="fas fa-mobile-alt"></i>
                    <strong>How to use:</strong><br>
                    1. Open your phone's camera<br>
                    2. Scan the QR code<br>
                    3. Document will open automatically
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <a href="#" id="printQRBtn" class="btn btn-primary" target="_blank">
                    <i class="fas fa-print"></i> Print QR Code
                </a>
            </div>
        </div>
    </div>
</div>

<script>
function showQRModal(qrPath, docName, token) {
    document.getElementById('modalQRImage').src = qrPath;
    document.getElementById('modalDocName').innerHTML = '<strong>' + docName + '</strong>';
    document.getElementById('printQRBtn').href = qrPath;
    new bootstrap.Modal(document.getElementById('qrModal')).show();
}

function copyControlNumber(controlNumber) {
    navigator.clipboard.writeText(controlNumber).then(function() {
        alert('Control Number copied: ' + controlNumber);
    }, function() {
        alert('Failed to copy control number');
    });
}

// File validation
document.getElementById('fileInput')?.addEventListener('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const fileSize = file.size / 1024 / 1024;
        if (fileSize > 5) {
            alert('File size exceeds 5MB limit!');
            this.value = '';
        }
    }
});

// Prevent double submission
document.getElementById('uploadForm')?.addEventListener('submit', function() {
    const submitBtn = document.getElementById('uploadBtn');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
        
        setTimeout(() => {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-upload"></i> Upload';
        }, 10000);
    }
});
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>

<?php include 'includes/footer.php'; ?>