<?php
session_start();
ob_start();
require_once 'config/database.php';
require_once 'includes/auth.php';
include 'includes/header.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

// Check if temp file exists in session
if (!isset($_SESSION['temp_file'])) {
    ob_end_clean();
    header("Location: files.php?error=" . urlencode("No file selected. Please upload a file first."));
    exit();
}

$temp_data = $_SESSION['temp_file'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $title_of_program = trim($_POST['title_of_program']);
    $duration = trim($_POST['duration']);
    $no_of_beneficiaries = (int)$_POST['no_of_beneficiaries'];
    $type_of_community_service = $_POST['type_of_community_service'];
    $community_population_served = trim($_POST['community_population_served']);
    $description = trim($_POST['description']);
    
    // Validate
    $errors = array();
    if (empty($title_of_program)) $errors[] = "Title of Program is required";
    if (empty($duration)) $errors[] = "Duration / Date Conducted is required";
    if ($no_of_beneficiaries <= 0) $errors[] = "Number of Beneficiaries must be greater than 0";
    if (empty($type_of_community_service)) $errors[] = "Type of Community Service is required";
    if (empty($community_population_served)) $errors[] = "Community Population Served is required";
    
    if (empty($errors)) {
        try {
            $sql = "INSERT INTO files (
                file_name, file_path, file_type, file_size, file_extension, 
                folder_id, view_token, uploaded_by, title_of_program, 
                date_conducted, no_of_beneficiaries, type_of_community_service, 
                community_population_served, description, status, created_at
            ) VALUES (
                :file_name, :file_path, :file_type, :file_size, :file_extension, 
                :folder_id, :view_token, :uploaded_by, :title_of_program, 
                :date_conducted, :no_of_beneficiaries, :type_of_community_service, 
                :community_population_served, :description, 'active', NOW()
            )";
            
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([
                ':file_name' => $temp_data['file_name'],
                ':file_path' => $temp_data['file_path'],
                ':file_type' => $temp_data['file_type'],
                ':file_size' => $temp_data['file_size'],
                ':file_extension' => $temp_data['file_extension'],
                ':folder_id' => $temp_data['folder_id'],
                ':view_token' => $temp_data['view_token'],
                ':uploaded_by' => $_SESSION['user_id'],
                ':title_of_program' => $title_of_program,
                ':date_conducted' => $duration,
                ':no_of_beneficiaries' => $no_of_beneficiaries,
                ':type_of_community_service' => $type_of_community_service,
                ':community_population_served' => $community_population_served,
                ':description' => $description
            ]);
            
            if ($result) {
                $file_id = $pdo->lastInsertId();
                
                // Generate QR code directory
                $qr_dir = 'uploads/qrcodes/';
                if (!file_exists($qr_dir)) mkdir($qr_dir, 0777, true);
                
                // Clear session
                unset($_SESSION['temp_file']);
                
                ob_end_clean();
                header("Location: files.php?folder=" . $temp_data['folder_id'] . "&success=file_uploaded");
                exit();
            } else {
                $error = "Failed to save document information.";
            }
        } catch (PDOException $e) {
            $error = "Database Error: " . $e->getMessage();
        }
    } else {
        $error = implode("<br>", $errors);
    }
}
?>

<style>
.form-container { max-width: 800px; margin: 0 auto; }
.required:after { content: " *"; color: red; }
.upload-header {
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    padding: 20px 25px;
    border-radius: 15px 15px 0 0;
}
.upload-body {
    background: white;
    padding: 30px;
    border-radius: 0 0 15px 15px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.05);
}
.file-info {
    background: #f0fdf4;
    border-left: 4px solid #22c55e;
    padding: 15px 20px;
    border-radius: 10px;
    margin-bottom: 25px;
}
.alert-danger {
    background: #f8d7da;
    color: #721c24;
    padding: 12px 15px;
    border-radius: 10px;
    margin-bottom: 20px;
}
.btn-primary {
    background: linear-gradient(135deg, #667eea, #764ba2);
    border: none;
    padding: 10px 25px;
    color: white;
    border-radius: 8px;
    cursor: pointer;
}
.btn-secondary {
    background: #6c757d;
    border: none;
    padding: 10px 25px;
    color: white;
    border-radius: 8px;
    text-decoration: none;
    display: inline-block;
}
.d-flex { display: flex; gap: 10px; }
.form-control {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 8px;
}
.mb-3 { margin-bottom: 15px; }
.row { display: flex; gap: 15px; margin-bottom: 15px; }
.col-md-6 { flex: 1; }
label { display: block; margin-bottom: 5px; font-weight: 600; }
</style>

<div class="container mt-4">
    <div class="form-container">
        <div class="upload-header">
            <h4><i class="fas fa-file-alt"></i> Add Document Details</h4>
            <p class="mb-0">Please fill out all required information below</p>
        </div>
        <div class="upload-body">
            <?php if($error): ?>
                <div class="alert-danger"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <div class="file-info">
                <i class="fas fa-file-alt"></i> <strong>File to add:</strong> 
                <?php 
                echo htmlspecialchars($temp_data['file_name']) . '.' . strtoupper(htmlspecialchars($temp_data['file_extension']));
                ?> 
                (<?php echo htmlspecialchars($temp_data['file_size']); ?> KB)
            </div>
            
            <form method="POST" action="">
                <div class="mb-3">
                    <label class="required">📋 Title of Program / Activity</label>
                    <input type="text" name="title_of_program" class="form-control" required placeholder="Enter program title">
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="required">📅 Date Conducted</label>
                            <input type="date" name="duration" class="form-control" required>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="required">👥 No. of Beneficiaries</label>
                            <input type="number" name="no_of_beneficiaries" class="form-control" required min="1">
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="required">🤝 Type of Community Service</label>
                            <select name="type_of_community_service" class="form-control" required>
                                <option value="">Select Type</option>
                                <option value="Health Services">Health Services</option>
                                <option value="Education">Education</option>
                                <option value="Environmental">Environmental</option>
                                <option value="Livelihood">Livelihood</option>
                                <option value="Disaster Response">Disaster Response</option>
                                <option value="Feeding Program">Feeding Program</option>
                                <option value="Medical Mission">Medical Mission</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label class="required">🏘️ Community Served</label>
                            <input type="text" name="community_population_served" class="form-control" required>
                        </div>
                    </div>
                </div>
                
                <div class="mb-3">
                    <label>📝 Description</label>
                    <textarea name="description" class="form-control" rows="3"></textarea>
                </div>
                
                <div class="d-flex">
                    <button type="submit" class="btn-primary">Save Document</button>
                    <a href="files.php?folder=<?php echo $temp_data['folder_id']; ?>" class="btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>