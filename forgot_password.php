<?php
session_start();
require_once 'config/database.php';

$step = isset($_GET['step']) ? $_GET['step'] : 'request';
$error = '';
$message = '';

// Step 1: Send OTP
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['send_otp'])) {
    $email = trim($_POST['email']);
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user) {
        $otp = sprintf("%06d", mt_rand(1, 999999));
        $expires = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        
        $update = $pdo->prepare("UPDATE users SET otp_code = ?, otp_expires = ? WHERE email = ?");
        $update->execute([$otp, $expires, $email]);
        
        $_SESSION['reset_email'] = $email;
        $message = "Your OTP is: <strong>$otp</strong> (Valid for 15 minutes)";
        header("Location: forgot_password.php?step=verify&email=" . urlencode($email));
        exit();
    } else {
        $error = "Email address not found in our records.";
    }
}

// Step 2: Verify OTP
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['verify_otp'])) {
    $otp = $_POST['otp'];
    $email = $_SESSION['reset_email'] ?? '';
    
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? AND otp_code = ? AND otp_expires > NOW()");
    $stmt->execute([$email, $otp]);
    $user = $stmt->fetch();
    
    if ($user) {
        $_SESSION['reset_user_id'] = $user['id'];
        header("Location: reset_password.php");
        exit();
    } else {
        $error = "Invalid or expired OTP code. Please try again.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password - USAT College Archiving System</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', 'Inter', sans-serif;
            min-height: 100vh;
            background: url('https://images.unsplash.com/photo-1562774053-701939374585?ixlib=rb-4.0.3&ixid=MnwxMjA3fDB8MHxwaG90by1wYWdlfHx8fGVufDB8fHx8&auto=format&fit=crop&w=1920&q=80') no-repeat center center fixed;
            background-size: cover;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow-x: hidden;
        }

        /* Overlay */
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

        /* Particles Animation */
        .particles {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            z-index: 0;
            overflow: hidden;
        }

        .particle {
            position: absolute;
            background: rgba(255, 255, 255, 0.08);
            border-radius: 50%;
            animation: floatParticle 15s infinite linear;
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

        /* Main Container */
        .forgot-container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 500px;
            margin: 20px;
            animation: fadeInUp 0.6s ease;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Card Design */
        .forgot-card {
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(10px);
            border-radius: 32px;
            padding: 45px 40px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            transition: all 0.3s ease;
            animation: cardGlow 3s ease-in-out infinite;
        }

        @keyframes cardGlow {
            0%, 100% {
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
            }
            50% {
                box-shadow: 0 30px 60px -12px rgba(102, 126, 234, 0.3);
            }
        }

        .forgot-card:hover {
            transform: translateY(-5px);
        }

        /* Icon Section */
        .icon-section {
            text-align: center;
            margin-bottom: 30px;
        }

        .icon-circle {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            animation: pulse 2s infinite;
            box-shadow: 0 10px 25px -5px rgba(102, 126, 234, 0.4);
        }

        @keyframes pulse {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 10px 25px -5px rgba(102, 126, 234, 0.4);
            }
            50% {
                transform: scale(1.05);
                box-shadow: 0 15px 35px -5px rgba(102, 126, 234, 0.6);
            }
        }

        .icon-circle i {
            font-size: 35px;
            color: white;
        }

        .icon-section h2 {
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(135deg, #667eea, #764ba2);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
            margin-bottom: 8px;
        }

        .icon-section p {
            color: #64748b;
            font-size: 14px;
        }

        /* Form Styles */
        .input-group {
            margin-bottom: 24px;
            position: relative;
        }

        .input-group i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 18px;
            transition: all 0.3s;
            z-index: 1;
        }

        .input-group input {
            width: 100%;
            padding: 16px 16px 16px 48px;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            font-size: 15px;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s;
            background: white;
        }

        .input-group input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
        }

        .input-group input:focus + i {
            color: #667eea;
        }

        /* OTP Input Group */
        .otp-group {
            display: flex;
            gap: 12px;
            justify-content: center;
            margin-bottom: 25px;
        }

        .otp-input {
            width: 55px;
            height: 65px;
            text-align: center;
            font-size: 24px;
            font-weight: 600;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            background: white;
            transition: all 0.3s;
            font-family: monospace;
        }

        .otp-input:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
            transform: translateY(-2px);
        }

        /* Button Styles */
        .btn-primary {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #667eea, #764ba2);
            border: none;
            border-radius: 16px;
            color: white;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            position: relative;
            overflow: hidden;
        }

        .btn-primary::before {
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

        .btn-primary:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(102, 126, 234, 0.4);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-secondary {
            width: 100%;
            padding: 14px;
            background: transparent;
            border: 2px solid #e2e8f0;
            border-radius: 16px;
            color: #64748b;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
            text-align: center;
        }

        .btn-secondary:hover {
            border-color: #667eea;
            color: #667eea;
            background: rgba(102, 126, 234, 0.05);
        }

        /* Alert Styles */
        .alert {
            padding: 14px 16px;
            border-radius: 12px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
            animation: shake 0.5s ease;
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

        .alert-info {
            background: #e0f2fe;
            color: #0284c7;
            border-left: 4px solid #0284c7;
        }

        /* Back to Login Link */
        .back-link {
            text-align: center;
            margin-top: 20px;
        }

        .back-link a {
            color: #64748b;
            text-decoration: none;
            font-size: 14px;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .back-link a:hover {
            color: #667eea;
            transform: translateX(-3px);
        }

        /* Resend OTP */
        .resend-otp {
            text-align: center;
            margin-top: 20px;
        }

        .resend-otp button {
            background: none;
            border: none;
            color: #667eea;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.3s;
        }

        .resend-otp button:hover {
            text-decoration: underline;
        }

        /* Timer */
        .timer {
            text-align: center;
            margin-top: 15px;
            font-size: 13px;
            color: #64748b;
        }

        .timer span {
            font-weight: 600;
            color: #667eea;
        }

        /* Loading State */
        .btn-primary.loading {
            pointer-events: none;
            opacity: 0.7;
        }

        .btn-primary.loading i {
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Responsive */
        @media (max-width: 480px) {
            .forgot-card {
                padding: 30px 25px;
            }
            .otp-input {
                width: 45px;
                height: 55px;
                font-size: 20px;
            }
            .icon-circle {
                width: 60px;
                height: 60px;
            }
            .icon-circle i {
                font-size: 25px;
            }
            .icon-section h2 {
                font-size: 22px;
            }
        }

        /* Email highlight animation */
        .email-highlight {
            animation: highlight 0.5s ease;
        }

        @keyframes highlight {
            0% { background: rgba(102, 126, 234, 0.2); }
            100% { background: transparent; }
        }
    </style>
</head>
<body>

<!-- Particles Background -->
<div class="particles" id="particles"></div>

<div class="forgot-container">
    <div class="forgot-card">
        
        <?php if($step == 'request'): ?>
            <!-- Step 1: Request OTP -->
            <div class="icon-section">
                <div class="icon-circle">
                    <i class="fas fa-key"></i>
                </div>
                <h2>Forgot Password?</h2>
                <p>Enter your email address and we'll send you an OTP to reset your password.</p>
            </div>

            <?php if($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php if($message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="requestForm">
                <div class="input-group">
                    <i class="fas fa-envelope"></i>
                    <input type="email" name="email" id="email" placeholder="Email Address" required autocomplete="off">
                </div>
                <button type="submit" name="send_otp" class="btn-primary" id="sendOtpBtn">
                    <i class="fas fa-paper-plane"></i> Send OTP
                </button>
            </form>

            <div class="back-link">
                <a href="index.php">
                    <i class="fas fa-arrow-left"></i> Back to Login
                </a>
            </div>

        <?php elseif($step == 'verify'): ?>
            <!-- Step 2: Verify OTP -->
            <div class="icon-section">
                <div class="icon-circle">
                    <i class="fas fa-qrcode"></i>
                </div>
                <h2>Verify OTP</h2>
                <p>Enter the 6-digit code sent to <strong id="emailDisplay"><?php echo htmlspecialchars($_GET['email'] ?? ''); ?></strong></p>
            </div>

            <?php if($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>

            <form method="POST" id="verifyForm">
                <div class="otp-group" id="otpGroup">
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" id="otp1" autofocus>
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" id="otp2">
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" id="otp3">
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" id="otp4">
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" id="otp5">
                    <input type="text" class="otp-input" maxlength="1" pattern="[0-9]" inputmode="numeric" id="otp6">
                </div>
                <input type="hidden" name="otp" id="otpHidden">
                <button type="submit" name="verify_otp" class="btn-primary" id="verifyBtn">
                    <i class="fas fa-check-circle"></i> Verify OTP
                </button>
            </form>

            <div class="timer" id="timer">
                <i class="fas fa-clock"></i> OTP expires in: <span id="countdown">15:00</span>
            </div>

            <div class="resend-otp">
                <button type="button" id="resendBtn" onclick="resendOTP()">
                    <i class="fas fa-redo-alt"></i> Didn't receive code? Resend
                </button>
            </div>

            <div class="back-link">
                <a href="forgot_password.php">
                    <i class="fas fa-arrow-left"></i> Back to Email
                </a>
            </div>
        <?php endif; ?>
        
    </div>
</div>

<script>
// Create particles
function createParticles() {
    const particlesContainer = document.getElementById('particles');
    const particleCount = 40;
    
    for (let i = 0; i < particleCount; i++) {
        const particle = document.createElement('div');
        particle.classList.add('particle');
        const size = Math.random() * 6 + 2;
        particle.style.width = size + 'px';
        particle.style.height = size + 'px';
        particle.style.left = Math.random() * 100 + '%';
        particle.style.animationDuration = Math.random() * 20 + 10 + 's';
        particle.style.animationDelay = Math.random() * 10 + 's';
        particlesContainer.appendChild(particle);
    }
}

// OTP Auto-tab and collection
<?php if($step == 'verify'): ?>
const otp1 = document.getElementById('otp1');
const otp2 = document.getElementById('otp2');
const otp3 = document.getElementById('otp3');
const otp4 = document.getElementById('otp4');
const otp5 = document.getElementById('otp5');
const otp6 = document.getElementById('otp6');
const otpHidden = document.getElementById('otpHidden');
const verifyForm = document.getElementById('verifyForm');

function moveToNext(current, next) {
    if (current.value.length === 1) {
        if (next) next.focus();
    }
}

function moveToPrevious(current, prev, event) {
    if (event.key === 'Backspace' && current.value.length === 0) {
        if (prev) prev.focus();
    }
}

otp1.addEventListener('input', () => moveToNext(otp1, otp2));
otp1.addEventListener('keydown', (e) => moveToPrevious(otp1, null, e));
otp2.addEventListener('input', () => moveToNext(otp2, otp3));
otp2.addEventListener('keydown', (e) => moveToPrevious(otp2, otp1, e));
otp3.addEventListener('input', () => moveToNext(otp3, otp4));
otp3.addEventListener('keydown', (e) => moveToPrevious(otp3, otp2, e));
otp4.addEventListener('input', () => moveToNext(otp4, otp5));
otp4.addEventListener('keydown', (e) => moveToPrevious(otp4, otp3, e));
otp5.addEventListener('input', () => moveToNext(otp5, otp6));
otp5.addEventListener('keydown', (e) => moveToPrevious(otp5, otp4, e));
otp6.addEventListener('input', () => {
    if (otp6.value.length === 1) {
        collectOTP();
    }
});
otp6.addEventListener('keydown', (e) => moveToPrevious(otp6, otp5, e));

function collectOTP() {
    const otp = otp1.value + otp2.value + otp3.value + otp4.value + otp5.value + otp6.value;
    if (otp.length === 6) {
        otpHidden.value = otp;
        verifyForm.submit();
    }
}

// Countdown timer (15 minutes = 900 seconds)
let timeLeft = 900;
const countdownElement = document.getElementById('countdown');

function updateTimer() {
    const minutes = Math.floor(timeLeft / 60);
    const seconds = timeLeft % 60;
    countdownElement.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
    
    if (timeLeft <= 0) {
        clearInterval(timerInterval);
        countdownElement.innerHTML = '<span style="color: red;">Expired! Please request a new OTP.</span>';
        document.getElementById('verifyBtn').disabled = true;
        document.getElementById('verifyBtn').style.opacity = '0.5';
    }
    timeLeft--;
}

const timerInterval = setInterval(updateTimer, 1000);

// Resend OTP function
function resendOTP() {
    const email = document.getElementById('emailDisplay')?.innerText || '';
    if (email) {
        fetch('resend_otp.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'email=' + encodeURIComponent(email)
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert('New OTP sent to your email!');
                timeLeft = 900;
                updateTimer();
            } else {
                alert('Failed to resend OTP. Please try again.');
            }
        })
        .catch(error => console.error('Error:', error));
    }
}
<?php endif; ?>

// Form submission loading state
document.getElementById('requestForm')?.addEventListener('submit', function() {
    const btn = document.getElementById('sendOtpBtn');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending OTP...';
    btn.classList.add('loading');
    btn.disabled = true;
});

document.getElementById('verifyForm')?.addEventListener('submit', function() {
    const btn = document.getElementById('verifyBtn');
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Verifying...';
    btn.classList.add('loading');
    btn.disabled = true;
});

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    createParticles();
    
    // Highlight email display
    const emailDisplay = document.getElementById('emailDisplay');
    if (emailDisplay) {
        emailDisplay.classList.add('email-highlight');
        setTimeout(() => {
            emailDisplay.classList.remove('email-highlight');
        }, 1000);
    }
});
</script>
</body>
</html>