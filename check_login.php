<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/database.php';

echo "<h1>Login System Debug</h1>";

try {
    // Test database connection
    echo "<h2>1. Database Connection:</h2>";
    if (isset($pdo) && $pdo instanceof PDO) {
        echo "✅ Database connected successfully<br>";
    } else {
        echo "❌ Database connection failed<br>";
        exit();
    }
    
    // Check if users table exists
    echo "<h2>2. Users Table:</h2>";
    $tables = $pdo->query("SHOW TABLES LIKE 'users'")->fetchAll();
    if (count($tables) > 0) {
        echo "✅ Users table exists<br>";
        
        // Show table structure
        echo "<h3>Table Structure:</h3>";
        $columns = $pdo->query("DESCRIBE users")->fetchAll();
        echo "<pre>";
        foreach ($columns as $column) {
            echo "{$column['Field']} - {$column['Type']} - {$column['Null']} - {$column['Key']}<br>";
        }
        echo "</pre>";
        
        // Count users
        $count = $pdo->query("SELECT COUNT(*) as total FROM users")->fetch();
        echo "<h3>Total Users: {$count['total']}</h3>";
        
        // List users (without showing passwords)
        $users = $pdo->query("SELECT id, username, full_name, email, role, status FROM users")->fetchAll();
        echo "<h3>User List:</h3>";
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>ID</th><th>Username</th><th>Full Name</th><th>Email</th><th>Role</th><th>Status</th></tr>";
        foreach ($users as $user) {
            echo "<tr>";
            echo "<td>{$user['id']}</td>";
            echo "<td>{$user['username']}</td>";
            echo "<td>{$user['full_name']}</td>";
            echo "<td>{$user['email']}</td>";
            echo "<td>{$user['role']}</td>";
            echo "<td>{$user['status']}</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Test password verification for admin
        echo "<h2>3. Password Test:</h2>";
        $admin = $pdo->query("SELECT password FROM users WHERE username = 'admin'")->fetch();
        if ($admin) {
            echo "Admin password hash: " . substr($admin['password'], 0, 20) . "...<br>";
            $testPassword = 'admin123';
            if (password_verify($testPassword, $admin['password'])) {
                echo "✅ Password 'admin123' is CORRECT for admin<br>";
            } else {
                echo "❌ Password 'admin123' is INCORRECT for admin<br>";
                echo "Creating new password hash for testing...<br>";
                $newHash = password_hash('admin123', PASSWORD_DEFAULT);
                echo "New hash: " . $newHash . "<br>";
                echo "Verification test: " . (password_verify('admin123', $newHash) ? '✅ Works' : '❌ Fails') . "<br>";
            }
        } else {
            echo "❌ Admin user not found<br>";
        }
        
    } else {
        echo "❌ Users table does not exist<br>";
        
        // Create users table
        echo "<h2>Creating Users Table:</h2>";
        $createSQL = "
        CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(50) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            full_name VARCHAR(100) NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            role ENUM('admin', 'staff') DEFAULT 'staff',
            status ENUM('active', 'inactive') DEFAULT 'active',
            last_login DATETIME DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        
        $pdo->exec($createSQL);
        echo "✅ Users table created<br>";
        
        // Insert default users
        echo "<h2>Creating Default Users:</h2>";
        $password = password_hash('admin123', PASSWORD_DEFAULT);
        
        $insertSQL = "
        INSERT INTO users (username, password, full_name, email, role, status) VALUES 
        ('admin', ?, 'System Administrator', 'admin@usat.edu', 'admin', 'active'),
        ('jsantos', ?, 'Juan Santos', 'jsantos@usat.edu', 'staff', 'active'),
        ('mreyes', ?, 'Maria Reyes', 'mreyes@usat.edu', 'staff', 'active')
        ";
        
        $stmt = $pdo->prepare($insertSQL);
        $stmt->execute([$password, $password, $password]);
        echo "✅ Default users created<br>";
        echo "<p><strong>Login Credentials:</strong><br>";
        echo "Admin: username='admin', password='admin123'<br>";
        echo "Staff1: username='jsantos', password='admin123'<br>";
        echo "Staff2: username='mreyes', password='admin123'</p>";
    }
    
    echo "<h2>4. PHP Configuration:</h2>";
    echo "PHP Version: " . phpversion() . "<br>";
    echo "Password Hash Algorithm: " . PASSWORD_DEFAULT . "<br>";
    echo "Session Status: " . (session_status() === PHP_SESSION_ACTIVE ? 'Active' : 'Inactive') . "<br>";
    
    echo "<h2>5. Quick Fix Options:</h2>";
    echo "<form method='post'>";
    echo "<button type='submit' name='reset_admin'>Reset Admin Password to 'admin123'</button><br><br>";
    echo "<button type='submit' name='recreate_table'>Recreate Users Table</button>";
    echo "</form>";
    
    if (isset($_POST['reset_admin'])) {
        $newHash = password_hash('admin123', PASSWORD_DEFAULT);
        $pdo->prepare("UPDATE users SET password = ? WHERE username = 'admin'")->execute([$newHash]);
        echo "<p style='color:green'>✅ Admin password has been reset to 'admin123'</p>";
        echo "<script>setTimeout(function(){ location.reload(); }, 2000);</script>";
    }
    
    if (isset($_POST['recreate_table'])) {
        $pdo->exec("DROP TABLE IF EXISTS users");
        echo "<p style='color:green'>✅ Table dropped. Refreshing...</p>";
        echo "<script>setTimeout(function(){ location.reload(); }, 2000);</script>";
    }
    
} catch (PDOException $e) {
    echo "<h2 style='color:red'>Database Error:</h2>";
    echo "<pre>" . $e->getMessage() . "</pre>";
}
?>