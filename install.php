<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🚀 USAT Archiving System - Auto Installer</h1>";

// Step 1: Create database connection
echo "<h3>Step 1: Setting up Database...</h3>";

$host = 'localhost';
$dbname = 'usat_archiving';
$username = 'root';
$password = '';

try {
    // Connect without database first
    $pdo = new PDO("mysql:host=$host", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create database if not exists
    $pdo->exec("CREATE DATABASE IF NOT EXISTS $dbname");
    echo "✅ Database '$dbname' created or already exists.<br>";
    
    // Connect to the database
    $pdo = new PDO("mysql:host=$host;dbname=$dbname", $username, $password);
    echo "✅ Connected to database successfully.<br>";
    
} catch(PDOException $e) {
    die("❌ Database error: " . $e->getMessage());
}

// Step 2: Create tables
echo "<h3>Step 2: Creating Tables...</h3>";

// Users table
$sql = "CREATE TABLE IF NOT EXISTS users (
    id INT PRIMARY KEY AUTO_INCREMENT,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100),
    full_name VARCHAR(100),
    role ENUM('admin', 'staff') DEFAULT 'staff',
    status ENUM('active', 'inactive') DEFAULT 'active',
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
$pdo->exec($sql);
echo "✅ Users table created.<br>";

// Students table
$sql = "CREATE TABLE IF NOT EXISTS students (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id VARCHAR(20) UNIQUE NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    course VARCHAR(100),
    year_level VARCHAR(20),
    status ENUM('active', 'archived') DEFAULT 'active',
    qr_code VARCHAR(255),
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
)";
$pdo->exec($sql);
echo "✅ Students table created.<br>";

// Documents table
$sql = "CREATE TABLE IF NOT EXISTS documents (
    id INT PRIMARY KEY AUTO_INCREMENT,
    student_id INT NOT NULL,
    document_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_type VARCHAR(50),
    uploaded_by INT,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
)";
$pdo->exec($sql);
echo "✅ Documents table created.<br>";

// Activity log table
$sql = "CREATE TABLE IF NOT EXISTS activity_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    user_id INT,
    action VARCHAR(100),
    description TEXT,
    ip_address VARCHAR(45),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
)";
$pdo->exec($sql);
echo "✅ Activity log table created.<br>";

// Step 3: Create admin and staff users
echo "<h3>Step 3: Creating Users...</h3>";

// Check if admin exists
$check = $pdo->prepare("SELECT id FROM users WHERE username = 'admin'");
$check->execute();

if ($check->rowCount() == 0) {
    $hashed_password = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username, password, email, full_name, role, status) VALUES (?, ?, ?, ?, 'admin', 'active')");
    $stmt->execute(['admin', $hashed_password, 'admin@usat.edu.ph', 'System Administrator']);
    echo "✅ Admin user created (admin / admin123)<br>";
} else {
    // Reset admin password
    $hashed_password = password_hash('admin123', PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE username = 'admin'");
    $stmt->execute([$hashed_password]);
    echo "✅ Admin password reset to 'admin123'<br>";
}

// Create staff users
$staff_users = [
    ['jsantos', 'juan.santos@usat.edu.ph', 'Juan Santos'],
    ['mreyes', 'maria.reyes@usat.edu.ph', 'Maria Reyes']
];

foreach ($staff_users as $staff) {
    $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $check->execute([$staff[0]]);
    
    if ($check->rowCount() == 0) {
        $hashed_password = password_hash('staff123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, password, email, full_name, role, status) VALUES (?, ?, ?, ?, 'staff', 'active')");
        $stmt->execute([$staff[0], $hashed_password, $staff[1], $staff[2]]);
        echo "✅ Staff user created: {$staff[0]} / staff123<br>";
    } else {
        // Reset staff password
        $hashed_password = password_hash('staff123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE username = ?");
        $stmt->execute([$hashed_password, $staff[0]]);
        echo "✅ Staff password reset: {$staff[0]} / staff123<br>";
    }
}

// Step 4: Create folders
echo "<h3>Step 4: Creating Folders...</h3>";

$folders = ['qrcodes', 'uploads', 'assets/css', 'assets/js'];
foreach ($folders as $folder) {
    if (!file_exists($folder)) {
        mkdir($folder, 0777, true);
        echo "✅ Created folder: $folder<br>";
    } else {
        echo "✅ Folder exists: $folder<br>";
    }
}

// Step 5: Test login
echo "<h3>Step 5: Testing Login...</h3>";

$test = $pdo->prepare("SELECT * FROM users WHERE username = 'admin'");
$test->execute();
$admin = $test->fetch();

if ($admin && password_verify('admin123', $admin['password'])) {
    echo "✅ Login test PASSED!<br>";
    echo "   Username: admin<br>";
    echo "   Password: admin123<br>";
} else {
    echo "❌ Login test FAILED!<br>";
}

echo "<hr>";
echo "<h2 style='color: green'>✅ Installation Complete!</h2>";
echo "<p>You can now login with:</p>";
echo "<ul>";
echo "<li><strong>Admin:</strong> admin / admin123</li>";
echo "<li><strong>Staff:</strong> jsantos / staff123</li>";
echo "<li><strong>Staff:</strong> mreyes / staff123</li>";
echo "</ul>";
echo "<a href='index.php' style='background: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Go to Login Page →</a>";
?>