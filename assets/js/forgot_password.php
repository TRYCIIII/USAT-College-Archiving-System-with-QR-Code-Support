<?php
session_start();
require_once 'config/database.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = $_POST['email'];
    
    // Check if email exists
    $stmt = $pdo->prepare("SELECT id, username, full_name FROM users WHERE email = ? AND status = 'active'");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user) {
        // Generate reset token
        $token = bin2hex(random_bytes(50));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Save token to database
        $update = $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
        $update->execute([$token, $expires, $user['id']]);
        
        // Create reset link
        $reset_link = "http://" . $_SERVER['HTTP_HOST'] . "/usat_archiving/reset_password.php?token=" . $token;
        
        // In a real system, you would send an email here
        // For demo purposes, we'll show the link
        $success = "Password reset link generated!<br>
                    <strong>Demo Link:</strong> <a href='$reset_link'>$reset_link</a><br>
                    <small class='text-muted'>In production, this would be sent to your email.</small>";
    } else {
        $error = "Email address not found in our records!";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - USAT College Archiving System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .reset-card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 450px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            animation: fadeIn 0.5s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .reset-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }
        .reset-icon i { font-size: 30px; color: white; }
        h2 { text-align: center; margin-bottom: 10px; color: #333; font-size: 24px; }
        .subtitle { text-align: center; color: #666; margin-bottom: 30px; font-size: 14px; }
        .form-control {
            padding: 12px;
            border-radius: 10px;
            margin-bottom: 15px;
            border: 1px solid #ddd;
        }
        .btn-reset {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border: none;
            border-radius: 10px;
            color: white;
            font-weight: bold;
            cursor: pointer;
            transition: 0.3s;
        }
        .btn-reset:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(102,126,234,0.4); }
        .back-link { text-align: center; margin-top: 20px; }
        .back-link a { color: #667eea; text-decoration: none; }
        .back-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="reset-card">
        <div class="reset-icon">
            <i class="fas fa-key"></i>
        </div>
        <h2>Forgot Password?</h2>
        <p class="subtitle">Enter your email address and we'll help you reset your password.</p>
        
        <?php if($error): ?>
            <div class="alert alert-danger"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
            <div class="back-link">
                <a href="index.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
            </div>
        <?php else: ?>
            <form method="POST">
                <input type="email" name="email" class="form-control" placeholder="Enter your email address" required>
                <button type="submit" class="btn-reset">
                    <i class="fas fa-paper-plane"></i> Send Reset Link
                </button>
            </form>
            <div class="back-link">
                <a href="index.php"><i class="fas fa-arrow-left"></i> Back to Login</a>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>