<?php
require_once 'includes/auth.php';
require_once 'config/database.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header("Location: index.php");
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    
    // Validate inputs
    if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
        $error = "All fields are required!";
    } else {
        // Get current user
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([getCurrentUserId()]);
        $user = $stmt->fetch();
        
        // Verify current password
        if (password_verify($current_password, $user['password'])) {
            // Check if new password is same as current
            if (password_verify($new_password, $user['password'])) {
                $error = "New password cannot be the same as your current password!";
            } elseif (strlen($new_password) < 6) {
                $error = "New password must be at least 6 characters long!";
            } elseif (!preg_match('/[A-Za-z]/', $new_password) || !preg_match('/[0-9]/', $new_password)) {
                $error = "Password must contain both letters and numbers!";
            } elseif ($new_password != $confirm_password) {
                $error = "New passwords do not match!";
            } else {
                // Update password
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                
                $update = $pdo->prepare("UPDATE users SET password = ?, updated_at = NOW() WHERE id = ?");
                $update->execute([$hashed_password, getCurrentUserId()]);
                
                // Log activity
                try {
                    $log = $pdo->prepare("INSERT INTO activity_log (user_id, action, description, ip_address) VALUES (?, 'CHANGE_PASSWORD', ?, ?)");
                    $log->execute([getCurrentUserId(), "Changed account password", $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1']);
                } catch (PDOException $e) {
                    // Log table might not exist
                }
                
                $success = "Password changed successfully!";
                $_POST = [];
            }
        } else {
            $error = "Current password is incorrect!";
        }
    }
}

include 'includes/header.php';
?>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Inter', sans-serif;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    min-height: 100vh;
}

.main-content {
    margin-left: 280px;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 30px;
}

.password-container {
    width: 100%;
    max-width: 500px;
    margin: 0 auto;
}

.password-card {
    background: white;
    border-radius: 28px;
    padding: 45px 40px;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
    width: 100%;
}

.password-header {
    text-align: center;
    margin-bottom: 35px;
}

.icon-wrapper {
    width: 75px;
    height: 75px;
    background: linear-gradient(135deg, #667eea, #764ba2);
    border-radius: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 20px;
    box-shadow: 0 10px 25px -5px rgba(102, 126, 234, 0.4);
}

.icon-wrapper i {
    font-size: 34px;
    color: white;
}

.password-header h3 {
    font-size: 26px;
    font-weight: 700;
    color: #1a1a2e;
    margin-bottom: 8px;
}

.password-header p {
    color: #64748b;
    font-size: 14px;
    line-height: 1.6;
}

/* Form Styles */
.form-group {
    margin-bottom: 24px;
    width: 100%;
}

.form-label {
    font-weight: 600;
    font-size: 14px;
    color: #2d3748;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.form-label i {
    color: #667eea;
    width: 18px;
    font-size: 14px;
}

.input-wrapper {
    position: relative;
    width: 100%;
    display: flex;
    align-items: center;
}

.input-icon {
    position: absolute;
    left: 16px;
    top: 50%;
    transform: translateY(-50%);
    color: #94a3b8;
    font-size: 16px;
    z-index: 1;
    pointer-events: none;
}

.input-wrapper .form-control {
    width: 100%;
    padding: 14px 48px 14px 48px;
    border: 2px solid #e2e8f0;
    border-radius: 14px;
    font-size: 14px;
    font-family: 'Inter', sans-serif;
    transition: all 0.3s;
    background: white;
}

.input-wrapper .form-control:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 4px rgba(102, 126, 234, 0.1);
}

.input-wrapper .form-control.error {
    border-color: #ef4444;
}

.input-wrapper .form-control.success {
    border-color: #10b981;
}

.password-toggle {
    position: absolute;
    right: 16px;
    top: 50%;
    transform: translateY(-50%);
    cursor: pointer;
    color: #94a3b8;
    z-index: 10;
    background: transparent;
    border: none;
    padding: 5px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s;
    width: 24px;
    height: 24px;
}

.password-toggle:hover {
    color: #667eea;
}

.password-toggle i {
    font-size: 18px;
    pointer-events: none;
}

/* Password Strength */
.password-strength {
    margin-top: 10px;
    width: 100%;
}

.strength-bar {
    height: 4px;
    background: #e2e8f0;
    border-radius: 2px;
    margin-bottom: 6px;
    overflow: hidden;
    width: 100%;
}

.strength-fill {
    height: 100%;
    width: 0%;
    transition: all 0.3s ease;
    border-radius: 2px;
}

.strength-text {
    font-size: 12px;
    color: #64748b;
    font-weight: 500;
}

.strength-weak .strength-fill { width: 25%; background: #ef4444; }
.strength-fair .strength-fill { width: 50%; background: #f59e0b; }
.strength-good .strength-fill { width: 75%; background: #3b82f6; }
.strength-strong .strength-fill { width: 100%; background: #10b981; }

#matchMessage {
    font-size: 12px;
    margin-top: 6px;
    font-weight: 500;
}

/* Submit Button */
.btn-submit {
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
    margin-top: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}

.btn-submit:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
}

.btn-submit:disabled {
    opacity: 0.6;
    cursor: not-allowed;
    transform: none;
}

.btn-submit i {
    font-size: 16px;
}

/* Password Requirements */
.password-requirements {
    background: #f8fafc;
    border-radius: 14px;
    padding: 18px 20px;
    margin-top: 25px;
    border: 1px solid #e2e8f0;
    width: 100%;
}

.password-requirements h6 {
    font-size: 13px;
    font-weight: 600;
    color: #2d3748;
    margin-bottom: 12px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.password-requirements h6 i {
    color: #667eea;
}

.password-requirements ul {
    margin: 0;
    padding: 0;
    list-style: none;
}

.password-requirements li {
    font-size: 12px;
    color: #64748b;
    margin-bottom: 8px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.password-requirements li:last-child {
    margin-bottom: 0;
}

.password-requirements li i {
    width: 16px;
    font-size: 12px;
}

.password-requirements li.valid {
    color: #10b981;
}

.password-requirements li.valid i {
    color: #10b981;
}

/* Alerts */
.alert {
    padding: 14px 18px;
    border-radius: 14px;
    margin-bottom: 25px;
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 14px;
    font-weight: 500;
    width: 100%;
}

.alert i {
    font-size: 18px;
}

.alert-success {
    background: #d1fae5;
    color: #065f46;
    border-left: 4px solid #10b981;
}

.alert-danger {
    background: #fee2e2;
    color: #991b1b;
    border-left: 4px solid #ef4444;
}

/* Responsive */
@media (max-width: 768px) {
    .main-content {
        margin-left: 0;
        padding: 20px;
    }
    
    .password-card {
        padding: 35px 25px;
    }
    
    .password-header h3 {
        font-size: 24px;
    }
    
    .icon-wrapper {
        width: 65px;
        height: 65px;
    }
    
    .icon-wrapper i {
        font-size: 30px;
    }
}

@media (max-width: 480px) {
    .password-card {
        padding: 30px 20px;
    }
    
    .form-label {
        font-size: 13px;
    }
    
    .input-wrapper .form-control {
        padding: 12px 42px 12px 42px;
        font-size: 13px;
    }
    
    .btn-submit {
        padding: 13px;
        font-size: 15px;
    }
}
</style>

<div class="main-content">
    <div class="password-container">
        <div class="password-card">
            <div class="password-header">
                <div class="icon-wrapper">
                    <i class="fas fa-lock"></i>
                </div>
                <h3>Change Password</h3>
                <p>Update your account password to keep your account secure</p>
            </div>
            
            <?php if($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle"></i>
                    <span><?php echo $error; ?></span>
                </div>
            <?php endif; ?>
            
            <?php if($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <span><?php echo $success; ?></span>
                </div>
            <?php endif; ?>
            
            <form method="POST" id="passwordForm">
                <!-- Current Password -->
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-key"></i> 
                        <span>Current Password</span>
                    </label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" 
                               name="current_password" 
                               class="form-control" 
                               id="current_password"
                               placeholder="Enter current password" 
                               required>
                        <button type="button" class="password-toggle" onclick="togglePassword('current_password')">
                            <i class="far fa-eye" id="current_eye"></i>
                        </button>
                    </div>
                </div>
                
                <!-- New Password -->
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-plus-circle"></i> 
                        <span>New Password</span>
                    </label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" 
                               name="new_password" 
                               class="form-control" 
                               id="new_password"
                               placeholder="Enter new password" 
                               required>
                        <button type="button" class="password-toggle" onclick="togglePassword('new_password')">
                            <i class="far fa-eye" id="new_eye"></i>
                        </button>
                    </div>
                    <div class="password-strength">
                        <div class="strength-bar">
                            <div class="strength-fill" id="strengthFill"></div>
                        </div>
                        <div class="strength-text" id="strengthText">Enter a password</div>
                    </div>
                </div>
                
                <!-- Confirm Password -->
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-check-circle"></i> 
                        <span>Confirm New Password</span>
                    </label>
                    <div class="input-wrapper">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" 
                               name="confirm_password" 
                               class="form-control" 
                               id="confirm_password"
                               placeholder="Confirm new password" 
                               required>
                        <button type="button" class="password-toggle" onclick="togglePassword('confirm_password')">
                            <i class="far fa-eye" id="confirm_eye"></i>
                        </button>
                    </div>
                    <div id="matchMessage"></div>
                </div>
                
                <button type="submit" class="btn-submit" id="submitBtn">
                    <i class="fas fa-save"></i>
                    <span>Update Password</span>
                </button>
            </form>
            
            <div class="password-requirements">
                <h6><i class="fas fa-shield-alt"></i> Password Requirements</h6>
                <ul>
                    <li id="req-length"><i class="far fa-circle"></i> At least 6 characters</li>
                    <li id="req-letter"><i class="far fa-circle"></i> Contains at least one letter</li>
                    <li id="req-number"><i class="far fa-circle"></i> Contains at least one number</li>
                    <li id="req-match"><i class="far fa-circle"></i> Passwords match</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
// Toggle password visibility - FIXED
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    const button = field.parentElement.querySelector('.password-toggle i');
    
    if (field.type === 'password') {
        field.type = 'text';
        button.classList.remove('fa-eye');
        button.classList.add('fa-eye-slash');
    } else {
        field.type = 'password';
        button.classList.remove('fa-eye-slash');
        button.classList.add('fa-eye');
    }
}

// Password strength checker
const newPassword = document.getElementById('new_password');
const confirmPassword = document.getElementById('confirm_password');
const strengthFill = document.getElementById('strengthFill');
const strengthText = document.getElementById('strengthText');
const matchMessage = document.getElementById('matchMessage');
const submitBtn = document.getElementById('submitBtn');

// Requirement elements
const reqLength = document.getElementById('req-length');
const reqLetter = document.getElementById('req-letter');
const reqNumber = document.getElementById('req-number');
const reqMatch = document.getElementById('req-match');

function checkPasswordStrength(password) {
    let strength = 0;
    const parent = strengthFill.parentElement.parentElement;
    
    parent.classList.remove('strength-weak', 'strength-fair', 'strength-good', 'strength-strong');
    
    if (password.length >= 6) {
        strength += 25;
        reqLength.innerHTML = '<i class="fas fa-check-circle" style="color: #10b981;"></i> At least 6 characters';
        reqLength.classList.add('valid');
    } else {
        reqLength.innerHTML = '<i class="far fa-circle"></i> At least 6 characters';
        reqLength.classList.remove('valid');
    }
    
    if (/[A-Za-z]/.test(password)) {
        strength += 25;
        reqLetter.innerHTML = '<i class="fas fa-check-circle" style="color: #10b981;"></i> Contains at least one letter';
        reqLetter.classList.add('valid');
    } else {
        reqLetter.innerHTML = '<i class="far fa-circle"></i> Contains at least one letter';
        reqLetter.classList.remove('valid');
    }
    
    if (/[0-9]/.test(password)) {
        strength += 25;
        reqNumber.innerHTML = '<i class="fas fa-check-circle" style="color: #10b981;"></i> Contains at least one number';
        reqNumber.classList.add('valid');
    } else {
        reqNumber.innerHTML = '<i class="far fa-circle"></i> Contains at least one number';
        reqNumber.classList.remove('valid');
    }
    
    if (password.length >= 8 && /[A-Za-z]/.test(password) && /[0-9]/.test(password)) {
        strength += 25;
    }
    
    if (strength <= 25) {
        parent.classList.add('strength-weak');
        strengthText.textContent = 'Weak password';
    } else if (strength <= 50) {
        parent.classList.add('strength-fair');
        strengthText.textContent = 'Fair password';
    } else if (strength <= 75) {
        parent.classList.add('strength-good');
        strengthText.textContent = 'Good password';
    } else {
        parent.classList.add('strength-strong');
        strengthText.textContent = 'Strong password';
    }
    
    return strength;
}

function checkPasswordMatch() {
    const newPass = newPassword.value;
    const confirmPass = confirmPassword.value;
    
    if (confirmPass === '') {
        matchMessage.textContent = '';
        confirmPassword.classList.remove('error', 'success');
        reqMatch.innerHTML = '<i class="far fa-circle"></i> Passwords match';
        reqMatch.classList.remove('valid');
        return false;
    }
    
    if (newPass === confirmPass) {
        matchMessage.textContent = '✓ Passwords match';
        matchMessage.style.color = '#10b981';
        confirmPassword.classList.remove('error');
        confirmPassword.classList.add('success');
        reqMatch.innerHTML = '<i class="fas fa-check-circle" style="color: #10b981;"></i> Passwords match';
        reqMatch.classList.add('valid');
        return true;
    } else {
        matchMessage.textContent = '✗ Passwords do not match';
        matchMessage.style.color = '#ef4444';
        confirmPassword.classList.remove('success');
        confirmPassword.classList.add('error');
        reqMatch.innerHTML = '<i class="far fa-circle"></i> Passwords match';
        reqMatch.classList.remove('valid');
        return false;
    }
}

function validateForm() {
    const newPass = newPassword.value;
    const strength = checkPasswordStrength(newPass);
    const match = checkPasswordMatch();
    
    return strength >= 50 && match;
}

newPassword.addEventListener('input', function() {
    checkPasswordStrength(this.value);
    if (confirmPassword.value) checkPasswordMatch();
});

confirmPassword.addEventListener('input', checkPasswordMatch);

document.getElementById('passwordForm').addEventListener('submit', function(e) {
    if (!validateForm()) {
        e.preventDefault();
        alert('Please ensure your password meets all requirements!');
    } else {
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i><span>Updating...</span>';
        submitBtn.disabled = true;
    }
});

// Initialize
checkPasswordStrength('');
</script>

<?php include 'includes/footer.php'; ?>