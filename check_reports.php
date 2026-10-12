<?php
session_start();
require_once 'config/database.php';

echo "<h1>Reports System Diagnostic</h1>";

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo "❌ You are not logged in. <a href='index.php'>Login first</a><br>";
    exit();
}
echo "✅ User logged in: " . $_SESSION['username'] . "<br>";

// Check if reports.php exists
if (file_exists('reports.php')) {
    echo "✅ reports.php file exists<br>";
} else {
    echo "❌ reports.php file MISSING!<br>";
}

// Check database tables
$tables = ['document_views', 'document_downloads', 'documents', 'students'];

foreach ($tables as $table) {
    $check = $pdo->query("SHOW TABLES LIKE '$table'");
    if ($check->rowCount() > 0) {
        echo "✅ Table '$table' exists<br>";
    } else {
        echo "❌ Table '$table' MISSING!<br>";
    }
}

// Check if there are any views
$views = $pdo->query("SELECT COUNT(*) FROM document_views")->fetchColumn();
echo "📊 Total document views recorded: " . $views . "<br>";

// Check if there are any documents
$docs = $pdo->query("SELECT COUNT(*) FROM documents")->fetchColumn();
echo "📄 Total documents: " . $docs . "<br>";

echo "<hr>";
echo "<h3>Fix Instructions:</h3>";
echo "<ol>";
echo "<li>Run the SQL below in phpMyAdmin to create missing tables</li>";
echo "<li>Make sure reports.php has the complete code</li>";
echo "<li>Clear your browser cache (Ctrl+Shift+R)</li>";
echo "</ol>";
?>