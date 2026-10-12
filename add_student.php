<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $student_id = $_POST['student_id'];
    $first_name = $_POST['first_name'];
    $last_name = $_POST['last_name'];
    $email = $_POST['email'];
    $phone = $_POST['phone'];
    $course = $_POST['course'];
    $year_level = $_POST['year_level'];
    $created_by = getCurrentUserId();
    
    // Check if student ID already exists
    $check = $pdo->prepare("SELECT id FROM students WHERE student_id = ?");
    $check->execute([$student_id]);
    
    if ($check->rowCount() > 0) {
        $error = "Student ID already exists!";
    } else {
        // created_at will be automatically set by MySQL DEFAULT CURRENT_TIMESTAMP
        $stmt = $pdo->prepare("INSERT INTO students (student_id, first_name, last_name, email, phone, course, year_level, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stmt->execute([$student_id, $first_name, $last_name, $email, $phone, $course, $year_level, $created_by])) {
            // Get the exact time of insertion
            $student_id_inserted = $pdo->lastInsertId();
            $timeStmt = $pdo->prepare("SELECT created_at FROM students WHERE id = ?");
            $timeStmt->execute([$student_id_inserted]);
            $created_time = $timeStmt->fetch()['created_at'];
            
            header("Location: students.php?success=added&time=" . urlencode($created_time));
            exit();
        } else {
            $error = "Failed to add student!";
        }
    }
}

include 'includes/header.php';
?>

<div class="table-container">
    <h4><i class="fas fa-user-plus"></i> Add New Student</h4>
    
    <?php if(isset($error)): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <form method="POST">
        <div class="row">
            <div class="col-md-6 mb-3">
                <label class="form-label">Student ID *</label>
                <input type="text" name="student_id" class="form-control" required placeholder="e.g., 2024-0001">
                <small class="text-muted">Unique identifier for the student</small>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">First Name *</label>
                <input type="text" name="first_name" class="form-control" required>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Last Name *</label>
                <input type="text" name="last_name" class="form-control" required>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Email</label>
                <input type="email" name="email" class="form-control" placeholder="student@usat.edu.ph">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Phone</label>
                <input type="text" name="phone" class="form-control" placeholder="09xxxxxxxxx">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Course *</label>
                <select name="course" class="form-control" required>
                    <option value="">Select Course</option>
                    <option value="BSIT">BS Information Technology</option>
                    <option value="BSCS">BS Computer Science</option>
                    <option value="BSIS">BS Information Systems</option>
                    <option value="BSBA">BS Business Administration</option>
                    <option value="BSED">BS Education</option>
                    <option value="BSHM">BS Hospitality Management</option>
                    <option value="BSCE">BS Civil Engineering</option>
                    <option value="BSECE">BS Electronics Engineering</option>
                </select>
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label">Year Level *</label>
                <select name="year_level" class="form-control" required>
                    <option value="1st Year">1st Year</option>
                    <option value="2nd Year">2nd Year</option>
                    <option value="3rd Year">3rd Year</option>
                    <option value="4th Year">4th Year</option>
                </select>
            </div>
        </div>
        
        <div class="alert alert-info">
            <i class="fas fa-info-circle"></i> The exact date and time will be recorded automatically when you add this student.
        </div>
        
        <button type="submit" class="btn btn-primary">
            <i class="fas fa-save"></i> Save Student
        </button>
        <a href="students.php" class="btn btn-secondary">
            <i class="fas fa-times"></i> Cancel
        </a>
    </form>
</div>

<?php include 'includes/footer.php'; ?>