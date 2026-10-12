<?php
session_start();
require_once 'config/database.php';

header('Content-Type: application/json');

if (isset($_POST['email'])) {
    $email = $_POST['email'];
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user) {
        $otp = sprintf("%06d", mt_rand(1, 999999));
        $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        
        $update = $pdo->prepare("UPDATE users SET otp_code = ?, otp_expires = ? WHERE email = ?");
        $update->execute([$otp, $expires, $email]);
        
        echo json_encode(['success' => true, 'otp' => $otp]);
    } else {
        echo json_encode(['success' => false]);
    }
} else {
    echo json_encode(['success' => false]);
}
?>