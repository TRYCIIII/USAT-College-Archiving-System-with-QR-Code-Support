<?php
session_start();
require_once 'config/database.php';

if (!isset($_SESSION['reset_user_id'])) {
    header("Location: index.php");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $password = $_POST['password'];
    $confirm = $_POST['confirm_password'];
    
    if (strlen($password) < 6) {
        $error = "Password must be at least 6 characters!";
    } elseif ($password != $confirm) {
        $error = "Passwords do not match!";
    } else {
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $update = $pdo->prepare("UPDATE users SET password = ?, otp_code = NULL, otp_expires = NULL WHERE id = ?");
        $update->execute([$hashed, $_SESSION['reset_user_id']]);
        
        unset($_SESSION['reset_email']);
        unset($_SESSION['reset_user_id']);
        
        header("Location: index.php?reset_success=1");
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password - USAT</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            background: url('https://images.unsplash.com/photo-1562774053-701939374585?ixlib=rb-4.0.3&auto=format&fit=crop&w=1920&q=80') no-repeat center center fixed;
            background-size: cover;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, rgba(0, 0, 0, 0.75) 0%, rgba(0, 0, 0, 0.6) 100%);
            z-index: 0;
        }
        .reset-container { position: relative; z-index: 1; width: 100%; max-width: 450px; margin: 20px; animation: fadeInUp 0.6s ease; }
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
        .reset-card { background: rgba(255, 255, 255, 0.98); backdrop-filter: blur(10px); border-radius: 32px; padding: 45px 40px; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); }
        .icon-circle { width: 70px; height: 70px; background: linear-gradient(135deg, #667eea, #764ba2); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; }
        .icon-circle i { font-size: 30px; color: white; }
        h2 { text-align: center; margin-bottom: 30px; background: linear-gradient(135deg, #667eea, #764ba2); -webkit-background-clip: text; background-clip: text; color: transparent; }
        .input-group { margin-bottom: 20px; position: relative; }
        .input-group i { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: #94a3b8; }
        .input-group input { width: 100%; padding: 14px 14px 14px 45px; border: 2px solid #e2e8f0; border-radius: 12px; font-size: 15px; transition: 0.3s; }
        .input-group input:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.1); }
        .btn-primary { width: 100%; padding: 14px; background: linear-gradient(135deg, #667eea, #764ba2); border: none; border-radius: 12px; color: white; font-size: 16px; font-weight: 600; cursor: pointer; transition: 0.3s; }
        .btn-primary:hover { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(102,126,234,0.4); }
        .alert { padding: 12px; border-radius: 10px; margin-bottom: 20px; background: #fee2e2; color: #dc2626; text-align: center; }
        .back-link { text-align: center; margin-top: 20px; }
        .back-link a { color: #64748b; text-decoration: none; font-size: 14px; }
        .back-link a:hover { color: #667eea; }
    </style>
</head>
<body>
<div class="reset-container">
    <div class="reset-card">
        <div class="icon-circle"><i class="fas fa-lock"></i></div>
        <h2>Create New Password</h2>
        <?php if($error): ?>
            <div class="alert"><?php echo $error; ?></div>
        <?php endif; ?>
        <form method="POST">
            <div class="input-group">
                <i class="fas fa-lock"></i>
                <input type="password" name="password" placeholder="New Password" required>
            </div>
            <div class="input-group">
                <i class="fas fa-check-circle"></i>
                <input type="password" name="confirm_password" placeholder="Confirm Password" required>
            </div>
            <button type="submit" class="btn-primary">Reset Password</button>
        </form>
        <div class="back-link"><a href="index.php">← Back to Login</a></div>
    </div>
</div>
</body>
</html>