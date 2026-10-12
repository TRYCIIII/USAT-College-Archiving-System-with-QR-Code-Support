<?php
$host = 'localhost';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Read SQL file
    $sql = file_get_contents('database.sql');
    
    // Execute SQL
    $pdo->exec($sql);
    
    echo "✅ Database installed successfully!<br>";
    echo "📧 Test accounts:<br>";
    echo "Admin: admin / admin123<br>";
    echo "Staff: jsantos / staff123<br>";
    echo "Staff: mreyes / staff123<br>";
    
} catch(PDOException $e) {
    die("❌ Error: " . $e->getMessage());
}
?>