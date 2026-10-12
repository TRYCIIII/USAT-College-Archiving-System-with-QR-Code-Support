<?php
/**
 * Login page
 * USAT College Archiving System
 *
 * - Prevents browser from caching this page (Back-button fix)
 * - Redirects logged-in users to dashboard
 */

// ============================================
// NO-CACHE HEADERS (must be first, before any output)
// ============================================
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

// ============================================
// SESSION START
// ============================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If already logged in, go to dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="USAT College Archiving System - Secure document management with QR technology">
    <meta name="theme-color" content="#667eea">
    <title>USAT College Archiving System - Secure Login</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        html, body {
            height: 100%;
        }

        body {
            font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
            min-height: 100vh;
            background: url('bg.png') no-repeat center center fixed;
            background-size: cover;
            position: relative;
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
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

        /* Animated particles - Optimized */
        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            overflow: hidden;
            pointer-events: none;
        }

        .particle {
            position: absolute;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 50%;
            animation: floatParticle 20s infinite linear;
            will-change: transform, opacity;
        }

        @keyframes floatParticle {
            0% {
                transform: translateY(100vh) rotate(0deg);
                opacity: 0;
            }
            10% {
                opacity: 1;
            }
            90% {
                opacity: 1;
            }
            100% {
                transform: translateY(-100vh) rotate(360deg);
                opacity: 0;
            }
        }

        /* Main container */
        .login-container {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 40px 80px;
            gap: 60px;
        }

        /* Left Side - Brand Section with Logo */
        .brand-section {
            flex: 1;
            max-width: 500px;
            animation: fadeInLeft 0.8s ease;
        }

        @keyframes fadeInLeft {
            from {
                opacity: 0;
                transform: translateX(-50px);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .logo-container {
            margin-bottom: 40px;
        }

        .main-logo {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 30px;
        }

        .logo-icon-large {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 25px -5px rgba(102, 126, 234, 0.4);
            animation: logoPulse 3s ease-in-out infinite;
            flex-shrink: 0;
        }

        @keyframes logoPulse {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 10px 25px -5px rgba(102, 126, 234, 0.4);
            }
            50% {
                transform: scale(1.05);
                box-shadow: 0 15px 35px -5px rgba(102, 126, 234, 0.6);
            }
        }

        .logo-icon-large i {
            font-size: 35px;
            color: white;
        }

        .logo-text h1 {
            font-size: 28px;
            font-weight: 800;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 5px;
            letter-spacing: -0.02em;
        }

        .logo-text p {
            color: rgba(255, 255, 255, 0.8);
            font-size: 14px;
        }

        .brand-title {
            margin-top: 30px;
        }

        .brand-title h2 {
            font-size: 42px;
            font-weight: 800;
            color: white;
            line-height: 1.2;
            margin-bottom: 20px;
            letter-spacing: -0.03em;
        }

        .brand-title h2 span {
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .brand-title p {
            color: rgba(255, 255, 255, 0.9);
            font-size: 16px;
            line-height: 1.6;
            margin-bottom: 30px;
        }

        .features-grid {
            display: flex;
            flex-direction: column;
            gap: 15px;
            margin-top: 30px;
        }

        .feature-card {
            background: rgba(255, 255, 255, 0.08);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: 16px;
            padding: 15px 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            transition: all 0.3s;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .feature-card:hover {
            background: rgba(255, 255, 255, 0.15);
            transform: translateX(10px);
        }

        .feature-icon {
            width: 45px;
            height: 45px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .feature-icon i {
            font-size: 20px;
            color: white;
        }

        .feature-text h4 {
            color: white;
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .feature-text p {
            color: rgba(255, 255, 255, 0.7);
            font-size: 12px;
        }

        /* Right Side - Login Card */
        .login-card {
            width: 100%;
            max-width: 480px;
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            border-radius: 32px;
            padding: 45px 40px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            transition: box-shadow 0.3s ease;
        }

        .login-card:hover {
            box-shadow: 0 30px 60px -12px rgba(0, 0, 0, 0.3);
        }

        /* Card Logo */
        .card-logo {
            text-align: center;
            margin-bottom: 30px;
        }

        .card-logo-icon {
            width: 70px;
            height: 70px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }

        .card-logo-icon i {
            font-size: 32px;
            color: white;
        }

        .card-logo h2 {
            font-size: 24px;
            font-weight: 700;
            color: #1a1a2e;
            margin-bottom: 5px;
            letter-spacing: -0.02em;
        }

        .card-logo p {
            color: #64748b;
            font-size: 13px;
        }

        /* Form Styles */
        .form-group {
            margin-bottom: 20px;
        }

        .input-group {
            position: relative;
        }

        .input-group > i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 18px;
            transition: all 0.3s;
            z-index: 1;
            pointer-events: none;
        }

        .input-group input {
            width: 100%;
            padding: 15px 16px 15px 48px;
            border: 2px solid #e2e8f0;
            border-radius: 14px;
            font-size: 15px;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s;
            background: white;
            color: #1a1a2e;
        }

        .input-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }

        .input-group input:focus ~ i {
            color: #667eea;
        }

        /* Login Button */
        .login-btn {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border: none;
            border-radius: 14px;
            color: white;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
            margin-top: 10px;
            font-family: inherit;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }

        .login-btn::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.3);
            transform: translate(-50%, -50%);
            transition: width 0.6s, height 0.6s;
        }

        .login-btn:hover::before {
            width: 300px;
            height: 300px;
        }

        .login-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(102, 126, 234, 0.4);
        }

        .login-btn:focus {
            outline: 2px solid #667eea;
            outline-offset: 2px;
        }

        .login-btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
            pointer-events: none;
        }

        .login-btn > * {
            position: relative;
            z-index: 1;
        }

        /* Forgot Password */
        .forgot-password {
            text-align: center;
            margin-top: 20px;
        }

        .forgot-password a {
            color: #64748b;
            text-decoration: none;
            font-size: 13px;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 8px;
            border-radius: 6px;
        }

        .forgot-password a:hover {
            color: #667eea;
        }

        .forgot-password a:focus {
            outline: 2px solid #667eea;
            outline-offset: 2px;
        }

        /* Demo Credentials */
        .demo-credentials {
            margin-top: 25px;
            padding: 18px;
            background: #f8fafc;
            border-radius: 14px;
            border: 1px solid #e2e8f0;
        }

        .demo-credentials h4 {
            font-size: 12px;
            font-weight: 600;
            color: #64748b;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .demo-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 8px;
            border-bottom: 1px solid #e2e8f0;
            cursor: pointer;
            transition: all 0.3s;
            border-radius: 8px;
            gap: 8px;
        }

        .demo-item:last-of-type {
            border-bottom: none;
        }

        .demo-item:hover {
            background: rgba(102, 126, 234, 0.05);
        }

        .demo-item:focus {
            outline: 2px solid #667eea;
            outline-offset: 2px;
            background: rgba(102, 126, 234, 0.05);
        }

        .demo-role {
            font-weight: 600;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .demo-role.admin {
            color: #e74c3c;
        }

        .demo-role.staff {
            color: #3498db;
        }

        .demo-details {
            font-size: 11px;
            color: #64748b;
            font-family: 'SF Mono', 'Courier New', monospace;
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
        }

        .demo-details span {
            background: #e2e8f0;
            padding: 2px 6px;
            border-radius: 6px;
        }

        .fill-btn {
            background: none;
            border: none;
            color: #667eea;
            cursor: pointer;
            font-size: 11px;
            transition: all 0.3s;
            padding: 4px 10px;
            border-radius: 6px;
            font-family: inherit;
            font-weight: 600;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .fill-btn:hover {
            color: #764ba2;
            background: rgba(102, 126, 234, 0.1);
        }

        .fill-btn:focus {
            outline: 2px solid #667eea;
            outline-offset: 2px;
        }

        /* Alert Styles */
        .alert {
            padding: 12px 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: shake 0.5s ease;
            font-size: 13px;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            25% { transform: translateX(-5px); }
            75% { transform: translateX(5px); }
        }

        .alert-danger {
            background: #fee2e2;
            color: #dc2626;
            border-left: 4px solid #dc2626;
        }

        .alert-success {
            background: #dcfce7;
            color: #16a34a;
            border-left: 4px solid #16a34a;
        }

        /* Loading state */
        .login-btn.loading i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Visually hidden labels for accessibility */
        .visually-hidden {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }

        /* ============================================
           RESPONSIVE
           ============================================ */
        @media (max-width: 1100px) {
            .login-container {
                padding: 40px;
                gap: 40px;
            }
            .brand-title h2 {
                font-size: 32px;
            }
        }

        @media (max-width: 900px) {
            .login-container {
                flex-direction: column;
                justify-content: center;
                padding: 40px 20px;
                gap: 30px;
            }
            .brand-section {
                text-align: center;
                max-width: 100%;
            }
            .main-logo {
                justify-content: center;
            }
            .features-grid {
                display: none;
            }
            .login-card {
                max-width: 450px;
                margin: 0 auto;
                width: 100%;
            }
        }

        @media (max-width: 480px) {
            .login-card {
                padding: 30px 20px;
                border-radius: 24px;
            }
            .brand-title h2 {
                font-size: 22px;
            }
            .brand-title p {
                font-size: 14px;
            }
            .login-container {
                padding: 20px 15px;
            }
            .logo-icon-large {
                width: 55px;
                height: 55px;
                border-radius: 16px;
            }
            .logo-icon-large i {
                font-size: 26px;
            }
            .logo-text h1 {
                font-size: 22px;
            }
            .demo-item {
                flex-wrap: wrap;
                gap: 8px;
                padding: 10px 4px;
            }
            .demo-details {
                order: 3;
                width: 100%;
                padding-left: 0;
            }
        }

        /* Print styles */
        @media print {
            body::before,
            .particles,
            .features-grid {
                display: none;
            }
            .login-card {
                box-shadow: none;
                border: 1px solid #ccc;
            }
        }

        /* Reduced motion */
        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
            }
            .particle {
                display: none;
            }
        }
    </style>
</head>
<body>

<!-- Particles Background -->
<div class="particles" id="particles" aria-hidden="true"></div>

<!-- Main Container -->
<div class="login-container">
    <!-- Left Side - Brand Section with Logo -->
    <div class="brand-section">
        <div class="logo-container">
            <div class="main-logo">
                <div class="logo-icon-large">
                    <i class="fas fa-archive" aria-hidden="true"></i>
                </div>
                <div class="logo-text">
                    <h1>USAT College</h1>
                    <p>Archiving System</p>
                </div>
            </div>
        </div>

        <div class="brand-title">
            <h2>Welcome to <span>USAT</span><br>College Archiving System</h2>
            <p>Secure, modern, and efficient document management with QR technology for USAT College.</p>
        </div>

        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-shield-alt" aria-hidden="true"></i>
                </div>
                <div class="feature-text">
                    <h4>Secure Document Storage</h4>
                    <p>Enterprise-grade security for all your documents</p>
                </div>
            </div>
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-qrcode" aria-hidden="true"></i>
                </div>
                <div class="feature-text">
                    <h4>QR Code Technology</h4>
                    <p>Instant access with unique QR codes</p>
                </div>
            </div>
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-chart-line" aria-hidden="true"></i>
                </div>
                <div class="feature-text">
                    <h4>Real-time Analytics</h4>
                    <p>Track and monitor all activities</p>
                </div>
            </div>
            <div class="feature-card">
                <div class="feature-icon">
                    <i class="fas fa-users" aria-hidden="true"></i>
                </div>
                <div class="feature-text">
                    <h4>Role-based Access Control</h4>
                    <p>Admin and Staff with different permissions</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Right Side - Login Card -->
    <div class="login-card" id="loginCard" role="main" aria-labelledby="login-heading">
        <div class="card-logo">
            <div class="card-logo-icon">
                <i class="fas fa-lock" aria-hidden="true"></i>
            </div>
            <h2 id="login-heading">Secure Login</h2>
            <p>Enter your credentials to access the system</p>
        </div>

        <?php if(isset($_GET['error'])): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fas fa-exclamation-circle" aria-hidden="true"></i>
                <span>Invalid username or password</span>
            </div>
        <?php endif; ?>

        <?php if(isset($_GET['reset_success'])): ?>
            <div class="alert alert-success" role="alert">
                <i class="fas fa-check-circle" aria-hidden="true"></i>
                <span>Password reset successful! Please login.</span>
            </div>
        <?php endif; ?>

        <?php if(isset($_GET['timeout'])): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fas fa-clock" aria-hidden="true"></i>
                <span>Session expired. Please login again.</span>
            </div>
        <?php endif; ?>

        <?php if(isset($_GET['logout'])): ?>
            <div class="alert alert-success" role="alert">
                <i class="fas fa-check-circle" aria-hidden="true"></i>
                <span>You have been logged out successfully.</span>
            </div>
        <?php endif; ?>

        <form action="login_process.php" method="POST" id="loginForm" novalidate>
            <div class="form-group">
                <label for="username" class="visually-hidden">Username</label>
                <div class="input-group">
                    <i class="fas fa-user" aria-hidden="true"></i>
                    <input type="text" 
                           name="username" 
                           id="username" 
                           placeholder="Username" 
                           required 
                           autocomplete="username"
                           aria-required="true"
                           aria-label="Username"
                           autocapitalize="off"
                           autocorrect="off"
                           spellcheck="false">
                </div>
            </div>
            
            <div class="form-group">
                <label for="password" class="visually-hidden">Password</label>
                <div class="input-group">
                    <i class="fas fa-lock" aria-hidden="true"></i>
                    <input type="password" 
                           name="password" 
                           id="password" 
                           placeholder="Password" 
                           required
                           autocomplete="current-password"
                           aria-required="true"
                           aria-label="Password">
                </div>
            </div>
            
            <button type="submit" class="login-btn" id="loginBtn">
                <i class="fas fa-sign-in-alt" aria-hidden="true"></i> 
                <span>Login to Dashboard</span>
            </button>
        </form>

        <div class="forgot-password">
            <a href="forgot_password.php">
                <i class="fas fa-key" aria-hidden="true"></i> Forgot Password?
            </a>
        </div>

        <div class="demo-credentials">
            <h4><i class="fas fa-info-circle" aria-hidden="true"></i> Demo Credentials (Click to auto-fill)</h4>
            
            <div class="demo-item" 
                 onclick="fillCredentials('admin', 'admin123')"
                 tabindex="0"
                 role="button"
                 aria-label="Fill admin credentials: username admin, password admin123">
                <span class="demo-role admin">
                    <i class="fas fa-user-shield" aria-hidden="true"></i> Administrator
                </span>
                <div class="demo-details">
                    <span>admin</span>
                    <span>admin123</span>
                </div>
                <button class="fill-btn" type="button" aria-label="Fill admin credentials">
                    <i class="fas fa-pen" aria-hidden="true"></i> Fill
                </button>
        </div>
            <p style="font-size: 10px; color: #94a3b8; margin-top: 12px; text-align: center; display: flex; align-items: center; justify-content: center; gap: 4px;">
                <i class="fas fa-shield-alt" aria-hidden="true"></i> Demo credentials for testing only
            </p>
        </div>
    </div>
</div>

<script>
    // Fill credentials function
    function fillCredentials(username, password) {
        const usernameField = document.getElementById('username');
        const passwordField = document.getElementById('password');
        
        usernameField.value = username;
        passwordField.value = password;
        
        // Highlight effect
        usernameField.style.transition = 'border-color 0.3s, box-shadow 0.3s';
        passwordField.style.transition = 'border-color 0.3s, box-shadow 0.3s';
        usernameField.style.borderColor = '#667eea';
        passwordField.style.borderColor = '#667eea';
        usernameField.style.boxShadow = '0 0 0 4px rgba(102, 126, 234, 0.1)';
        passwordField.style.boxShadow = '0 0 0 4px rgba(102, 126, 234, 0.1)';
        
        // Focus on login button
        document.getElementById('loginBtn').focus();
        
        setTimeout(() => {
            usernameField.style.borderColor = '#e2e8f0';
            passwordField.style.borderColor = '#e2e8f0';
            usernameField.style.boxShadow = 'none';
            passwordField.style.boxShadow = 'none';
        }, 1200);
    }

    // Form submission loading state
    document.getElementById('loginForm').addEventListener('submit', function(e) {
        const username = document.getElementById('username').value.trim();
        const password = document.getElementById('password').value;
        
        // Basic client-side validation
        if (!username || !password) {
            e.preventDefault();
            alert('Please enter both username and password');
            return;
        }
        
        const btn = document.getElementById('loginBtn');
        const btnText = btn.querySelector('span');
        const icon = btn.querySelector('i');
        
        btnText.textContent = 'Logging in...';
        icon.classList.remove('fa-sign-in-alt');
        icon.classList.add('fa-spinner', 'fa-spin');
        btn.classList.add('loading');
        btn.disabled = true;
        btn.setAttribute('aria-busy', 'true');
    });

    // Create particles - Optimized
    function createParticles() {
        const particlesContainer = document.getElementById('particles');
        // Respect reduced motion
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }
        
        const particleCount = window.innerWidth < 768 ? 20 : 40;
        const fragment = document.createDocumentFragment();
        
        for (let i = 0; i < particleCount; i++) {
            const particle = document.createElement('div');
            particle.classList.add('particle');
            const size = Math.random() * 8 + 2;
            particle.style.width = size + 'px';
            particle.style.height = size + 'px';
            particle.style.left = Math.random() * 100 + '%';
            particle.style.animationDuration = Math.random() * 20 + 10 + 's';
            particle.style.animationDelay = Math.random() * -20 + 's';
            fragment.appendChild(particle);
        }
        
        particlesContainer.appendChild(fragment);
    }

    // Handle keyboard navigation for demo items
    function handleKeyboardNavigation() {
        const demoItems = document.querySelectorAll('.demo-item');
        demoItems.forEach(item => {
            item.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    this.click();
                }
            });
        });
    }

    // Auto-focus username if no alert is showing
    document.addEventListener('DOMContentLoaded', function() {
        createParticles();
        handleKeyboardNavigation();
        
        // Focus on username only if no error alert is present
        const hasAlert = document.querySelector('.alert');
        if (!hasAlert) {
            setTimeout(() => {
                document.getElementById('username').focus();
            }, 100);
        }
    });

    // Debounced resize handler for particles
    let resizeTimer;
    window.addEventListener('resize', function() {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            const particlesContainer = document.getElementById('particles');
            particlesContainer.innerHTML = '';
            createParticles();
        }, 300);
    });
</script>
</body>
</html>