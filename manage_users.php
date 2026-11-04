<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/includes/auth.php';
requireAdmin();
require_once __DIR__ . '/config/database.php';

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

    if (empty($username) || empty($password) || empty($full_name)) {
        $error = "Please fill in all required fields.";
    } elseif (strlen($password) < 6) {
        $error = "Password must be at least 6 characters long.";
    } else {
        try {
            $check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $check->execute([$username]);

            if ($check->rowCount() > 0) {
                $error = "Username already exists!";
            } else {
                if (!empty($email)) {
                    $check = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                    $check->execute([$email]);
                    if ($check->rowCount() > 0) {
                        $error = "Email already exists!";
                    }
                }

                if (empty($error)) {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                    try {
                        $stmt = $pdo->prepare("INSERT INTO users (username, password, email, full_name, role, status) VALUES (?, ?, ?, ?, ?, 'active')");
                        $stmt->execute([$username, $hashed_password, $email, $full_name, $role]);
                    } catch (PDOException $e) {
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
// UPDATE USER (edit name / email / role / password)
// =============================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['edit_user'])) {
    $id        = (int)($_POST['user_id'] ?? 0);
    $full_name = trim($_POST['edit_full_name'] ?? '');
    $email     = trim($_POST['edit_email'] ?? '');
    $role      = $_POST['edit_role'] ?? '';
    $new_pass  = $_POST['edit_password'] ?? '';

    if ($id <= 0 || empty($full_name)) {
        $error = "Full name is required.";
    } elseif (!in_array($role, ['admin', 'staff'], true)) {
        $error = "Invalid role.";
    } elseif (!empty($new_pass) && strlen($new_pass) < 6) {
        $error = "New password must be at least 6 characters long.";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$id]);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$current) {
                $error = "User not found.";
            } elseif ($id == getCurrentUserId() && $role !== $current['role']) {
                $error = "You cannot change your own role.";
            } else {
                // Email uniqueness
                if (!empty($email) && strcasecmp($email, $current['email'] ?? '') !== 0) {
                    $check = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id <> ?");
                    $check->execute([$email, $id]);
                    if ($check->rowCount() > 0) {
                        $error = "Email already used by another user.";
                    }
                }

                if (empty($error)) {
                    if (!empty($new_pass)) {
                        $hashed = password_hash($new_pass, PASSWORD_DEFAULT);
                        $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, role = ?, password = ? WHERE id = ?");
                        $stmt->execute([$full_name, $email, $role, $hashed, $id]);
                        $success = "User updated successfully (password changed).";
                    } else {
                        $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, role = ? WHERE id = ?");
                        $stmt->execute([$full_name, $email, $role, $id]);
                        $success = "User updated successfully!";
                    }
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
            try {
                $stmt = $pdo->prepare("UPDATE users SET status = IF(status = 'active', 'inactive', 'active') WHERE id = ?");
                $stmt->execute([$id]);
            } catch (PDOException $e) {
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

    header("Location: manage_users.php");
    exit();
}

// =============================================
// DELETE USER
// =============================================
if (isset($_GET['delete_user']) && isAdmin()) {
    $id = (int)$_GET['delete_user'];

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

    header("Location: manage_users.php");
    exit();
}

// =============================================
// GET ALL USERS
// =============================================
try {
    try {
        $stmt = $pdo->query("SELECT * FROM users ORDER BY role, username");
        $users = $stmt->fetchAll();
    } catch (PDOException $e) {
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
    try {
        $pdo->query("SELECT last_login FROM users LIMIT 1");
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'last_login') !== false) {
            $pdo->exec("ALTER TABLE users ADD COLUMN last_login DATETIME DEFAULT NULL");
        }
    }
} catch (PDOException $e) {}

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
        <table class="table table-bordered align-middle">
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
                    <td class="text-nowrap">
                        <!-- NEW: Edit button -->
                        <button type="button" class="btn btn-sm btn-primary" title="Edit user details"
                                data-bs-toggle="modal" data-bs-target="#editUserModal"
                                data-user-id="<?php echo (int)$user['id']; ?>"
                                data-username="<?php echo htmlspecialchars($user['username'], ENT_QUOTES); ?>"
                                data-full-name="<?php echo htmlspecialchars($user['full_name'], ENT_QUOTES); ?>"
                                data-email="<?php echo htmlspecialchars($user['email'] ?? '', ENT_QUOTES); ?>"
                                data-role="<?php echo htmlspecialchars($user['role'], ENT_QUOTES); ?>"
                                onclick="fillEditUser(this)">
                            <i class="fas fa-pen"></i>
                        </button>

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

<!-- NEW: Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;">
                <h5 class="modal-title"><i class="fas fa-user-edit"></i> Edit User</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="editUserForm">
                <input type="hidden" name="edit_user" value="1">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" id="edit_username" class="form-control" readonly disabled>
                        <small class="text-muted">Username cannot be changed.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Full Name *</label>
                        <input type="text" name="edit_full_name" id="edit_full_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="edit_email" id="edit_email" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role *</label>
                        <select name="edit_role" id="edit_role" class="form-control" required>
                            <option value="staff">Staff</option>
                            <option value="admin">Admin</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password <span class="text-muted" style="font-weight:400;font-size:12px;">(leave blank to keep current)</span></label>
                        <input type="password" name="edit_password" id="edit_password" class="form-control" minlength="6">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
/* Pre-fill the edit modal from the clicked row's data-* attributes */
function fillEditUser(btn) {
    document.getElementById('edit_user_id').value   = btn.dataset.userId;
    document.getElementById('edit_username').value  = btn.dataset.username;
    document.getElementById('edit_full_name').value = btn.dataset.fullName;
    document.getElementById('edit_email').value     = btn.dataset.email;
    document.getElementById('edit_role').value      = btn.dataset.role;
    document.getElementById('edit_password').value  = '';
}
</script>

<?php include 'includes/footer.php'; ?>