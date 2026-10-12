<?php
require_once '../config/database.php';
header('Content-Type: application/json');

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Handle different request methods
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Search by student ID
    if (isset($_POST['student_id']) && !empty($_POST['student_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM students WHERE student_id = ?");
        $stmt->execute([$_POST['student_id']]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($student) {
            echo json_encode($student);
        } else {
            echo json_encode(null);
        }
    }
    
    // Search by database ID
    elseif (isset($_POST['id']) && !empty($_POST['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($student) {
            echo json_encode($student);
        } else {
            echo json_encode(null);
        }
    }
    
    // Search by name (autocomplete)
    elseif (isset($_POST['search']) && !empty($_POST['search'])) {
        $search = '%' . $_POST['search'] . '%';
        $stmt = $pdo->prepare("SELECT id, student_id, first_name, last_name, course, year_level 
                               FROM students 
                               WHERE status = 'active' 
                               AND (first_name LIKE ? OR last_name LIKE ? OR student_id LIKE ?) 
                               LIMIT 10");
        $stmt->execute([$search, $search, $search]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($students);
    }
    
    // Get all active students
    elseif (isset($_POST['get_all']) && $_POST['get_all'] == 'true') {
        $stmt = $pdo->query("SELECT id, student_id, first_name, last_name, course, year_level, qr_code 
                             FROM students 
                             WHERE status = 'active' 
                             ORDER BY first_name ASC");
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($students);
    }
    
    else {
        echo json_encode(['error' => 'No valid parameter provided']);
    }
}

// Handle GET requests
elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    
    // Get single student by ID
    if (isset($_GET['id'])) {
        $stmt = $pdo->prepare("SELECT * FROM students WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($student) {
            echo json_encode($student);
        } else {
            echo json_encode(['error' => 'Student not found']);
        }
    }
    
    // Get student by student ID
    elseif (isset($_GET['student_id'])) {
        $stmt = $pdo->prepare("SELECT * FROM students WHERE student_id = ?");
        $stmt->execute([$_GET['student_id']]);
        $student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($student) {
            echo json_encode($student);
        } else {
            echo json_encode(['error' => 'Student not found']);
        }
    }
    
    // Get all students with optional filters
    else {
        $status = isset($_GET['status']) ? $_GET['status'] : 'active';
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;
        
        $stmt = $pdo->prepare("SELECT * FROM students WHERE status = ? ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$status, $limit]);
        $students = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode($students);
    }
}

else {
    echo json_encode(['error' => 'Invalid request method']);
}
?>