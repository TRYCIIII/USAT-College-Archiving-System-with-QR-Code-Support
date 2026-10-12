<?php
require_once 'config/database.php';

echo "<h2>Database User Check</h2>";

// Check if users table exists
$tables = $pdo->query("SHOW TABLES LIKE 'users'");
if ($tables->rowCount() == 0) {
    echo "❌ Users table does not exist!<br>";
    echo "Please run the SQL script in phpMyAdmin.<br>";
} else {
    echo "✅ Users table exists.<br><br>";
    
    // Get all users
    $users = $pdo->query("SELECT id, username, role, status FROM users");
    
    if ($users->rowCount() == 0) {
        echo "❌ No users found in database!<br>";
        echo "Please insert users using the SQL script.<br>";
    } else {
        echo "<h3>Users in database:</h3>";
        echo "<table border='1' cellpadding='10'>";
        echo "<tr><th>ID</th><th>Username</th><th>Role</th><th>Status</th></tr>";
        
        while ($user = $users->fetch()) {
            echo "<tr>";
            echo "<td>" . $user['id'] . "</td>";
            echo "<td>" . $user['username'] . "</td>";
            echo "<td>" . $user['role'] . "</td>";
            echo "<td>" . $user['status'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        
        // Test admin login
        echo "<h3>Testing admin login:</h3>";
        $test = $pdo->prepare("SELECT * FROM users WHERE username = 'admin'");
        $test->execute();
        $admin = $test->fetch();
        
        if ($admin) {
            echo "✅ Admin user found!<br>";
            echo "Password hash: " . substr($admin['password'], 0, 30) . "...<br>";
            
            // Test password verification
            $test_password = 'admin123';
            if (password_verify($test_password, $admin['password'])) {
                echo "✅ Password 'admin123' is CORRECT!<br>";
                echo "You should be able to login.<br>";
            } else {
                echo "❌ Password 'admin123' is WRONG!<br>";
                echo "The stored password hash doesn't match 'admin123'.<br>";
            }
        } else {
            echo "❌ Admin user not found!<br>";
        }
    }
}
?>