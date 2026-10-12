<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'includes/auth.php';
requireAdmin();
require_once 'config/database.php';

// Set timezone
date_default_timezone_set('Asia/Manila');

$success = '';
$error = '';

// =============================================
// ADD NEW USER
// =============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_user'])) {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $email = trim($_POST['email']);
    $full_name = trim($_POST['full_name']);
    $role = $_POST['role'];
    
    // Validate inputs
    if (empty($username) || empty($password) || empty($full_name)) {
        $error = "Please fill in all required fields.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } else {
        try {
            // Check if username exists
            $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $check->execute([$username]);
            
            if ($check->rowCount() > 0) {
                $error = "Username already exists!";
            } else {
                // Check if email exists (if provided)
                if (!empty($email)) {
                    $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                    $check->execute([$email]);
                    if ($check->rowCount() > 0) {
                        $error = "Email already exists!";
                    }
                }
                
                if (empty($error)) {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    
                    // Check if status column exists, if not add it
                    try {
                        $stmt = $pdo->prepare("INSERT INTO users (username, password, email, full_name, role, status) VALUES (?, ?, ?, ?, ?, 'active')");
                        $stmt->execute([$username, $hashed_password, $email, $full_name, $role]);
                    } catch (PDOException $e) {
                        // If status column doesn't exist, add it
                        if (strpos($e->getMessage(), 'status') !== false) {
                            $pdo->exec("ALTER TABLE users ADD COLUMN status VARCHAR(20) DEFAULT 'active'");
                            $stmt = $pdo->prepare("INSERT INTO users (username, password, email, full_name, role, status) VALUES (?, ?, ?, ?, ?, 'active')");
                            $stmt->execute([$username, $hashed_password, $email, $full_name, $role]);
                        } else {
                            throw $e;
                        }
                    }
                    
                    $success = "User added successfully!";
                }
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// =============================================
// TOGGLE USER STATUS
// =============================================
if (isset($_GET['toggle_status'])) {
    $id = (int)$_GET['toggle_status'];
    
    if ($id == getCurrentUserId()) {
        $error = "You cannot change your own status.";
    } else {
        try {
            // Check if status column exists
            try {
                $stmt = $pdo->prepare("UPDATE users SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?");
                $stmt->execute([$id]);
            } catch (PDOException $e) {
                // If status column doesn't exist, add it
                if (strpos($e->getMessage(), 'status') !== false) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN status VARCHAR(20) DEFAULT 'active'");
                    $stmt = $pdo->prepare("UPDATE users SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?");
                    $stmt->execute([$id]);
                } else {
                    throw $e;
                }
            }
            $success = "User status updated successfully!";
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
    
    // Redirect after toggle
    header("Location: manage_users.php");
    exit();
}

// =============================================
// DELETE USER
// =============================================
if (isset($_GET['delete_user']) && isAdmin()) {
    $id = (int)$_GET['delete_user'];
    
    // Don't allow deleting own account
    if ($id == getCurrentUserId()) {
        $error = "You cannot delete your own account.";
    } else {
        try {
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $success = "User deleted successfully!";
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
    
    // Redirect after delete
    header("Location: manage_users.php");
    exit();
}

// =============================================
// GET ALL USERS
// =============================================
try {
    // Check if status column exists
    try {
        $stmt = $pdo->query("SELECT * FROM users ORDER BY role, username");
        $users = $stmt->fetchAll();
    } catch (PDOException $e) {
        // If status column doesn't exist, add it
        if (strpos($e->getMessage(), 'status') !== false) {
            $pdo->exec("ALTER TABLE users ADD COLUMN status VARCHAR(20) DEFAULT 'active'");
            $stmt = $pdo->query("SELECT * FROM users ORDER BY role, username");
            $users = $stmt->fetchAll();
        } else {
            throw $e;
        }
    }
} catch (PDOException $e) {
    $error = "Database error: " . $e->getMessage();
    $users = [];
}

// Add missing columns if needed
try {
    // Check if last_login column exists
    try {
        $pdo->query("SELECT last_login FROM users LIMIT 1");
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'last_login') !== false) {
            $pdo->exec("ALTER TABLE users ADD COLUMN last_login DATETIME DEFAULT NULL");
        }
    }
} catch (PDOException $e) {
    // Ignore
}

include 'includes/header.php';
?>

<div class="table-container">
    <h4><i class="fas fa-users-cog"></i> Manage Users</h4>
    
    <?php if(!empty($success)): ?>
        <div class="alert alert-success"><?php echo $success; ?></div>
    <?php endif; ?>
    <?php if(!empty($error)): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>
    
    <button class="btn btn-primary mb-3" data-bs-toggle="modal" data-bs-target="#addUserModal">
        <i class="fas fa-plus"></i> Add New User
    </button>
    
    <div class="table-responsive">
        <table class="table table-bordered">
            <thead class="table-dark">
                <tr>
                    <th>ID</th>
                    <th>Username</th>
                    <th>Full Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th>Last Login</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if(empty($users)): ?>
                <tr>
                    <td colspan="8" class="text-center">No users found.</td>
                </tr>
                <?php else: ?>
                <?php foreach($users as $user): ?>
                <tr>
                    <td><?php echo $user['id']; ?></td>
                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                    <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                    <td><?php echo htmlspecialchars($user['email'] ?? ''); ?></td>
                    <td>
                        <span class="badge bg-<?php echo $user['role'] == 'admin' ? 'danger' : 'info'; ?>">
                            <?php echo ucfirst($user['role']); ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge bg-<?php echo (isset($user['status']) && $user['status'] == 'active') ? 'success' : 'secondary'; ?>">
                            <?php echo isset($user['status']) ? $user['status'] : 'active'; ?>
                        </span>
                    </td>
                    <td><?php echo (isset($user['last_login']) && $user['last_login']) ? date('M d, Y', strtotime($user['last_login'])) : 'Never'; ?></td>
                    <td>
                        <a href="?toggle_status=<?php echo $user['id']; ?>" class="btn btn-sm btn-warning" title="Toggle Status">
                            <i class="fas fa-toggle-<?php echo (isset($user['status']) && $user['status'] == 'active') ? 'off' : 'on'; ?>"></i>
                        </a>
                        <?php if($user['id'] != getCurrentUserId()): ?>
                        <a href="?delete_user=<?php echo $user['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('Delete this user?')" title="Delete">
                            <i class="fas fa-trash"></i>
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-user-plus"></i> Add New User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Username *</label>
                        <input type="text" name="username" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Password *</label>
                        <input type="password" name="password" class="form-control" required minlength="6">
                        <small class="text-muted">Minimum 6 characters.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Full Name *</label>
                        <input type="text" name="full_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role *</label>
                        <select name="role" class="form-control" required>
                            <option value="staff">Staff</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_user" class="btn btn-primary">Add User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>