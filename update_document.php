<?php
session_start();
require_once 'config/database.php';
require_once 'includes/auth.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php");
    exit();
}

$file_id = $_POST['file_id'];
$title_of_program = trim($_POST['title_of_program']);
$date_conducted = $_POST['date_conducted'];
$no_of_beneficiaries = (int)$_POST['no_of_beneficiaries'];
$type_of_community_service = $_POST['type_of_community_service'];
$community_population_served = trim($_POST['community_population_served']);
$description = trim($_POST['description']);

$stmt = $pdo->prepare("UPDATE files SET title_of_program = ?, date_conducted = ?, no_of_beneficiaries = ?, type_of_community_service = ?, community_population_served = ?, description = ? WHERE id = ?");
$stmt->execute([$title_of_program, $date_conducted, $no_of_beneficiaries, $type_of_community_service, $community_population_served, $description, $file_id]);

header("Location: files.php?success=updated");
exit();
?>