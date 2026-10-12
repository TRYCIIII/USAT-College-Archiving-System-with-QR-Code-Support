<?php
require_once 'config/database.php';

echo "<h2>Fixing Database Structure</h2>";

try {
    // Check current column types
    $stmt = $pdo->query("DESCRIBE files");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<h3>Current columns in files table:</h3>";
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th></tr>";
    foreach ($columns as $column) {
        echo "<tr>";
        echo "<td>" . $column['Field'] . "</td>";
        echo "<td>" . $column['Type'] . "</td>";
        echo "<td>" . $column['Null'] . "</td>";
        echo "<td>" . $column['Key'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    echo "<h3>Fixing column sizes...</h3>";
    
    // Fix file_type column
    try {
        $pdo->exec("ALTER TABLE files MODIFY COLUMN file_type VARCHAR(100)");
        echo "<p style='color:green'>✓ Fixed file_type column to VARCHAR(100)</p>";
    } catch (PDOException $e) {
        echo "<p style='color:red'>Error fixing file_type: " . $e->getMessage() . "</p>";
    }
    
    // Fix other columns
    $alterations = [
        "ALTER TABLE files MODIFY COLUMN file_name VARCHAR(255)",
        "ALTER TABLE files MODIFY COLUMN file_path VARCHAR(500)",
        "ALTER TABLE files MODIFY COLUMN file_extension VARCHAR(50)",
        "ALTER TABLE files MODIFY COLUMN view_token VARCHAR(255)",
        "ALTER TABLE files MODIFY COLUMN title_of_program VARCHAR(500)",
        "ALTER TABLE files MODIFY COLUMN community_population_served VARCHAR(500)",
        "ALTER TABLE files MODIFY COLUMN type_of_community_service VARCHAR(200)",
    ];
    
    foreach ($alterations as $sql) {
        try {
            $pdo->exec($sql);
            echo "<p style='color:green'>✓ Executed: " . htmlspecialchars($sql) . "</p>";
        } catch (PDOException $e) {
            echo "<p style='color:orange'>Note: " . $e->getMessage() . "</p>";
        }
    }
    
    echo "<h3>Database fix completed!</h3>";
    echo "<p><a href='files.php'>Go back to File Manager</a></p>";
    
} catch (PDOException $e) {
    echo "<p style='color:red'>Database error: " . $e->getMessage() . "</p>";
}
// In archive_document.php, when preparing the insert
// Make sure file_type is not too long
$file_type_short = substr($temp_data['file_type'], 0, 50); // Truncate to 50 characters

$stmt = $pdo->prepare("INSERT INTO files (
    file_name, 
    file_path, 
    file_type, 
    file_size, 
    file_extension, 
    folder_id, 
    view_token, 
    uploaded_by, 
    title_of_program, 
    date_conducted, 
    no_of_beneficiaries, 
    type_of_community_service, 
    community_population_served, 
    description, 
    status,
    created_at
) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', NOW())");

$result = $stmt->execute([
    $temp_data['file_name'],
    $temp_data['file_path'],
    $file_type_short, // Use truncated version
    $temp_data['file_size'],
    $temp_data['file_extension'],
    $temp_data['folder_id'],
    $temp_data['view_token'],
    getCurrentUserId(),
    $title_of_program,
    $duration,
    $no_of_beneficiaries,
    $type_of_community_service,
    $community_population_served,
    $description
]);
?>