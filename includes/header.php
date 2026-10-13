<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) && basename($_SERVER['PHP_SELF']) != 'index.php' && basename($_SERVER['PHP_SELF']) != 'login_process.php' && basename($_SERVER['PHP_SELF']) != 'forgot_password.php' && basename($_SERVER['PHP_SELF']) != 'reset_password.php') {
    header("Location: index.php");
    exit();
}

$display_name = $_SESSION['full_name'] ?? 'User';
$role_name = isset($_SESSION['role']) ? ucfirst($_SESSION['role']) : 'Guest';
$role_is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';
$hide_role_text = $role_is_admin && stripos($display_name, 'admin') !== false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>USAT College Archiving System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f0f2f5;
            overflow-x: hidden;
        }
        
        .wrapper {
            display: flex;
            min-height: 100vh;
        }
        
        /* Main Content Area */
        .content {
            flex: 1;
            margin-left: 280px;
            padding: 20px;
            transition: all 0.3s ease;
            min-height: 100vh;
        }
        
        /* Top Navbar */
        .top-navbar {
            background: white;
            border-radius: 15px;
            padding: 15px 25px;
            margin-bottom: 25px;
            display: flex;
            justify-content: flex-end;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
        }
        
        .user-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .user-avatar {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
            font-size: 18px;
        }
        
        .user-details {
            text-align: right;
        }
        
        .user-details strong {
            display: block;
            font-size: 14px;
            color: #333;
        }
        
        .user-details small {
            font-size: 12px;
            color: #666;
        }
        
        .role-badge {
            display: inline-block;
            padding: 2px 8px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            border-radius: 20px;
            font-size: 10px;
            margin-left: 5px;
        }
        
        /* Table Container */
        .table-container {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 25px;
        }
        
        /* Buttons */
        .btn-primary {
            background: linear-gradient(135deg, #667eea, #764ba2);
            border: none;
            padding: 10px 20px;
            border-radius: 10px;
            font-weight: 500;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, #5a67d8, #6b46c1);
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #6c757d;
            border: none;
            padding: 10px 20px;
            border-radius: 10px;
        }
        
        /* Cards */
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            transition: all 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        
        .stat-card i {
            font-size: 40px;
            margin-bottom: 10px;
        }
        
        .stat-card h3 {
            font-size: 32px;
            font-weight: bold;
            margin: 10px 0;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .content {
                margin-left: 0;
            }
            
            .user-details strong {
                font-size: 12px;
            }
            
            .user-details small {
                font-size: 10px;
            }
            
            .user-avatar {
                width: 35px;
                height: 35px;
                font-size: 14px;
            }
            
            .table-container {
                padding: 15px;
            }
        }
        
        /* Animations */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .table-container {
            animation: fadeIn 0.5s ease;
        }
    </style>
</head>
<body>
    <div class="wrapper">
        <?php include 'sidebar.php'; ?>
        
        <div class="content">
            <div class="top-navbar">
                <div class="user-info">
                    <div class="user-details">
                        <strong><?php echo htmlspecialchars($display_name); ?></strong>
                        <small>
                            <i class="fas fa-<?php echo $role_is_admin ? 'shield-alt' : 'user'; ?>"></i>
                            <?php if($role_is_admin && !$hide_role_text): ?>
                                <span class="role-badge">Admin</span>
                            <?php elseif(!$hide_role_text): ?>
                                <?php echo htmlspecialchars($role_name); ?>
                            <?php endif; ?>
                        </small>
                    </div>
                    <div class="user-avatar">
                        <?php 
                        if (isset($_SESSION['full_name'])) {
                            echo strtoupper(substr($_SESSION['full_name'], 0, 1));
                        } else {
                            echo 'U';
                        }
                        ?>
                    </div>
                </div>
            </div>