<?php
require_once 'dbconfig.php';

// Check admin authentication
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit();
}

if (!validate_session($conn, 'admin', $_SESSION['admin_username'])) {
    session_destroy();
    header("Location: admin_login.php");
    exit();
}

$error_message = '';
$success_message = '';

// Get current admin details
try {
    $stmt = $conn->prepare("SELECT * FROM admin WHERE id = ?");
    $stmt->execute([$_SESSION['admin_id']]);
    $admin_data = $stmt->fetch();
    
    if (!$admin_data) {
        session_destroy();
        header("Location: admin_login.php");
        exit();
    }
} catch(Exception $e) {
    error_log("Admin profile fetch error: " . $e->getMessage());
    $error_message = 'Unable to load profile data.';
    $admin_data = [];
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        log_security_event($conn, 'csrf_violation', 'high', 'CSRF token validation failed for admin profile', $_SESSION['admin_username']);
        $error_message = 'Security validation failed. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        // Update Profile Information
        if ($action == 'update_profile' && prevent_resubmit($conn, 'admin', $_SESSION['admin_username'], 'update_profile')) {
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $username = trim($_POST['username'] ?? '');
            
            if (empty($name) || empty($email) || empty($username)) {
                $error_message = 'Please fill in all required fields.';
            } else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error_message = 'Please enter a valid email address.';
            } else {
                try {
                    // Check if username is already taken by another admin
                    $stmt = $conn->prepare("SELECT id FROM admin WHERE username = ? AND id != ?");
                    $stmt->execute([$username, $_SESSION['admin_id']]);
                    
                    if ($stmt->rowCount() > 0) {
                        $error_message = 'Username is already taken. Please choose a different username.';
                    } else {
                        // Update profile
                        $old_values = $admin_data;
                        
                        $stmt = $conn->prepare("UPDATE admin SET name = ?, email = ?, username = ?, updated_at = NOW() WHERE id = ?");
                        $stmt->execute([$name, $email, $username, $_SESSION['admin_id']]);
                        
                        // Update session data
                        $_SESSION['admin_name'] = $name;
                        $_SESSION['admin_username'] = $username;
                        
                        // Update admin_data array
                        $admin_data['name'] = $name;
                        $admin_data['email'] = $email;
                        $admin_data['username'] = $username;
                        
                        $new_values = ['name' => $name, 'email' => $email, 'username' => $username];
                        log_activity($conn, 'admin', $_SESSION['admin_username'], 'profile_updated', 'admin', $_SESSION['admin_id'], $old_values, $new_values);
                        
                        $success_message = 'Profile updated successfully!';
                    }
                } catch(Exception $e) {
                    error_log("Profile update error: " . $e->getMessage());
                    $error_message = 'An error occurred while updating your profile.';
                }
            }
        }
        
        // Change Password
        elseif ($action == 'change_password' && prevent_resubmit($conn, 'admin', $_SESSION['admin_username'], 'change_password')) {
            $current_password = $_POST['current_password'] ?? '';
            $new_password = $_POST['new_password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            
            if (empty($current_password) || empty($new_password) || empty($confirm_password)) {
                $error_message = 'Please fill in all password fields.';
            } else if (md5($current_password) !== $admin_data['password']) {
                $error_message = 'Current password is incorrect.';
                log_security_event($conn, 'incorrect_password', 'medium', 'Incorrect current password provided during password change', $_SESSION['admin_username']);
            } else if ($new_password !== $confirm_password) {
                $error_message = 'New password and confirmation do not match.';
            } else if (strlen($new_password) < 6) {
                $error_message = 'New password must be at least 6 characters long.';
            } else {
                try {
                    // Update password
                    $stmt = $conn->prepare("UPDATE admin SET password = ?, updated_at = NOW() WHERE id = ?");
                    $stmt->execute([md5($new_password), $_SESSION['admin_id']]);
                    
                    log_activity($conn, 'admin', $_SESSION['admin_username'], 'password_changed', 'admin', $_SESSION['admin_id']);
                    log_security_event($conn, 'password_changed', 'low', 'Admin password changed successfully', $_SESSION['admin_username']);
                    
                    $success_message = 'Password changed successfully!';
                } catch(Exception $e) {
                    error_log("Password change error: " . $e->getMessage());
                    $error_message = 'An error occurred while changing your password.';
                }
            }
        }
        
        // Update Preferences
        elseif ($action == 'update_preferences' && prevent_resubmit($conn, 'admin', $_SESSION['admin_username'], 'update_preferences')) {
            // For now, we'll just log this action. You can extend this to save actual preferences
            log_activity($conn, 'admin', $_SESSION['admin_username'], 'preferences_updated', 'admin', $_SESSION['admin_id']);
            $success_message = 'Preferences updated successfully!';
        }
    }
}

// Get admin statistics
try {
    // Get admin's activity statistics
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_actions,
            COUNT(DISTINCT DATE(timestamp)) as active_days,
            MAX(timestamp) as last_activity
        FROM activity_logs 
        WHERE user_type = 'admin' AND user_identifier = ?
    ");
    $stmt->execute([$_SESSION['admin_username']]);
    $admin_stats = $stmt->fetch();
    
    // Get recent activities
    $stmt = $conn->prepare("
        SELECT action, table_name, timestamp, ip_address
        FROM activity_logs 
        WHERE user_type = 'admin' AND user_identifier = ?
        ORDER BY timestamp DESC 
        LIMIT 10
    ");
    $stmt->execute([$_SESSION['admin_username']]);
    $recent_activities = $stmt->fetchAll();
    
    // Get login history
    $stmt = $conn->prepare("
        SELECT action, login_time, ip_address, user_agent
        FROM login_logs 
        WHERE user_type = 'admin' AND user_identifier = ?
        ORDER BY login_time DESC 
        LIMIT 10
    ");
    $stmt->execute([$_SESSION['admin_username']]);
    $login_history = $stmt->fetchAll();
    
} catch(Exception $e) {
    error_log("Admin stats error: " . $e->getMessage());
    $admin_stats = ['total_actions' => 0, 'active_days' => 0, 'last_activity' => null];
    $recent_activities = [];
    $login_history = [];
}

$csrf_token = generate_csrf_token();
log_activity($conn, 'admin', $_SESSION['admin_username'], 'profile_viewed');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Profile - Subject Allotment System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .profile-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 2rem;
        }
        .profile-card {
            border-left: 5px solid #667eea;
        }
        .password-card {
            border-left: 5px solid #28a745;
        }
        .stats-card {
            border-left: 5px solid #ffc107;
        }
        .activity-card {
            border-left: 5px solid #17a2b8;
        }
        .avatar {
            width: 120px;
            height: 120px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            margin: 0 auto 1rem;
        }
        .stat-item {
            text-align: center;
            padding: 1rem;
        }
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #667eea;
        }
        .activity-item {
            padding: 10px 0;
            border-bottom: 1px solid #eee;
        }
        .activity-item:last-child {
            border-bottom: none;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }
        @media (max-width: 768px) {
            .avatar {
                width: 80px;
                height: 80px;
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="admin_dashboard.php">
                <i class="fas fa-graduation-cap me-2"></i>
                Subject Allotment System
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown">
                            <i class="fas fa-user-circle me-1"></i>
                            <?php echo htmlspecialchars($_SESSION['admin_name']); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="admin_dashboard.php">
                                <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                            </a></li>
                            <li><a class="dropdown-item active" href="admin_profile.php">
                                <i class="fas fa-user me-2"></i>Profile
                            </a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="admin_logout.php">
                                <i class="fas fa-sign-out-alt me-2"></i>Logout
                            </a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid py-4">
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?php echo htmlspecialchars($error_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!empty($success_message)): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo htmlspecialchars($success_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Left Column -->
            <div class="col-lg-4">
                <!-- Profile Card -->
                <div class="card profile-card">
                    <div class="profile-header text-center">
                        <div class="avatar">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <h3><?php echo htmlspecialchars($admin_data['name'] ?? 'Admin'); ?></h3>
                        <p class="mb-0">
                            <i class="fas fa-at me-1"></i>
                            <?php echo htmlspecialchars($admin_data['username'] ?? ''); ?>
                        </p>
                        <p class="mb-0">
                            <i class="fas fa-envelope me-1"></i>
                            <?php echo htmlspecialchars($admin_data['email'] ?? ''); ?>
                        </p>
                        <hr style="border-color: rgba(255,255,255,0.3);">
                        <p class="mb-0">
                            <small>
                                <i class="fas fa-calendar me-1"></i>
                                Member since: <?php echo $admin_data['created_at'] ? date('M Y', strtotime($admin_data['created_at'])) : 'N/A'; ?>
                            </small>
                        </p>
                    </div>
                </div>

                <!-- Statistics Card -->
                <div class="card stats-card">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0">
                            <i class="fas fa-chart-bar me-2"></i>Your Statistics
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="row g-0">
                            <div class="col-4">
                                <div class="stat-item border-end">
                                    <div class="stat-number"><?php echo $admin_stats['total_actions'] ?? 0; ?></div>
                                    <small class="text-muted">Total Actions</small>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="stat-item border-end">
                                    <div class="stat-number"><?php echo $admin_stats['active_days'] ?? 0; ?></div>
                                    <small class="text-muted">Active Days</small>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="stat-item">
                                    <div class="stat-number">
                                        <?php 
                                        if ($admin_stats['last_activity']) {
                                            $hours = round((time() - strtotime($admin_stats['last_activity'])) / 3600);
                                            echo $hours . 'h';
                                        } else {
                                            echo 'N/A';
                                        }
                                        ?>
                                    </div>
                                    <small class="text-muted">Last Activity</small>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-bolt me-2"></i>Quick Actions
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="admin_dashboard.php" class="btn btn-outline-primary">
                                <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                            </a>
                            <a href="manage_subject_pools.php" class="btn btn-outline-success">
                                <i class="fas fa-layer-group me-2"></i>Manage Pools
                            </a>
                            <a href="student_registrations.php" class="btn btn-outline-info">
                                <i class="fas fa-users me-2"></i>Registrations
                            </a>
                            <a href="reports.php" class="btn btn-outline-warning">
                                <i class="fas fa-chart-bar me-2"></i>Reports
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Right Column -->
            <div class="col-lg-8">
                <!-- Profile Information Form -->
                <div class="card profile-card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-user-edit me-2"></i>Profile Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" id="profileForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="form_token" value="<?php echo generate_token(); ?>">
                            <input type="hidden" name="action" value="update_profile">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="name" class="form-label">
                                            <i class="fas fa-user me-1"></i>Full Name
                                        </label>
                                        <input type="text" class="form-control" id="name" name="name" 
                                               value="<?php echo htmlspecialchars($admin_data['name'] ?? ''); ?>" required>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="username" class="form-label">
                                            <i class="fas fa-at me-1"></i>Username
                                        </label>
                                        <input type="text" class="form-control" id="username" name="username" 
                                               value="<?php echo htmlspecialchars($admin_data['username'] ?? ''); ?>" required>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="email" class="form-label">
                                    <i class="fas fa-envelope me-1"></i>Email Address
                                </label>
                                <input type="email" class="form-control" id="email" name="email" 
                                       value="<?php echo htmlspecialchars($admin_data['email'] ?? ''); ?>" required>
                            </div>
                            
                            <div class="mb-3">
                                <label class="form-label">
                                    <i class="fas fa-info-circle me-1"></i>Account Information
                                </label>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-control-plaintext">
                                            <strong>Created:</strong> 
                                            <?php echo $admin_data['created_at'] ? date('M j, Y g:i A', strtotime($admin_data['created_at'])) : 'N/A'; ?>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-control-plaintext">
                                            <strong>Last Updated:</strong> 
                                            <?php echo $admin_data['updated_at'] ? date('M j, Y g:i A', strtotime($admin_data['updated_at'])) : 'Never'; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i>Update Profile
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Change Password Form -->
                <div class="card password-card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-key me-2"></i>Change Password
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" id="passwordForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="form_token" value="<?php echo generate_token(); ?>">
                            <input type="hidden" name="action" value="change_password">
                            
                            <div class="mb-3">
                                <label for="current_password" class="form-label">
                                    <i class="fas fa-lock me-1"></i>Current Password
                                </label>
                                <div class="input-group">
                                    <input type="password" class="form-control" id="current_password" 
                                           name="current_password" required autocomplete="current-password">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('current_password')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="new_password" class="form-label">
                                            <i class="fas fa-key me-1"></i>New Password
                                        </label>
                                        <div class="input-group">
                                            <input type="password" class="form-control" id="new_password" 
                                                   name="new_password" required autocomplete="new-password" minlength="6">
                                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('new_password')">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                        <small class="text-muted">Minimum 6 characters</small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="confirm_password" class="form-label">
                                            <i class="fas fa-check me-1"></i>Confirm Password
                                        </label>
                                        <div class="input-group">
                                            <input type="password" class="form-control" id="confirm_password" 
                                                   name="confirm_password" required autocomplete="new-password">
                                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('confirm_password')">
                                                <i class="fas fa-eye"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-key me-2"></i>Change Password
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Recent Activities -->
                <div class="card activity-card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-history me-2"></i>Recent Activities
                        </h5>
                    </div>
                    <div class="card-body p-0" style="max-height: 300px; overflow-y: auto;">
                        <?php if (!empty($recent_activities)): ?>
                            <?php foreach ($recent_activities as $activity): ?>
                                <div class="activity-item px-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?php echo htmlspecialchars($activity['action']); ?></strong>
                                            <?php if ($activity['table_name']): ?>
                                                <span class="text-muted">on</span>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($activity['table_name']); ?></span>
                                            <?php endif; ?>
                                            <br>
                                            <small class="text-muted">
                                                <i class="fas fa-globe me-1"></i>
                                                <?php echo htmlspecialchars($activity['ip_address']); ?>
                                            </small>
                                        </div>
                                        <small class="text-muted">
                                            <?php echo date('M j, g:i A', strtotime($activity['timestamp'])); ?>
                                        </small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-history fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No recent activities found</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Login History -->
                <div class="card">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-sign-in-alt me-2"></i>Recent Login History
                        </h5>
                    </div>
                    <div class="card-body p-0" style="max-height: 250px; overflow-y: auto;">
                        <?php if (!empty($login_history)): ?>
                            <?php foreach ($login_history as $login): ?>
                                <div class="activity-item px-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <span class="badge <?php echo $login['action'] == 'login' ? 'bg-success' : ($login['action'] == 'logout' ? 'bg-info' : 'bg-danger'); ?>">
                                                <?php echo ucfirst($login['action']); ?>
                                            </span>
                                            <br>
                                            <small class="text-muted">
                                                <i class="fas fa-globe me-1"></i>
                                                <?php echo htmlspecialchars($login['ip_address']); ?>
                                            </small>
                                        </div>
                                        <small class="text-muted">
                                            <?php echo date('M j, g:i A', strtotime($login['login_time'])); ?>
                                        </small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-sign-in-alt fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No login history found</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function togglePassword(fieldId) {
            const field = document.getElementById(fieldId);
            const button = field.nextElementSibling;
            const icon = button.querySelector('i');
            
            if (field.type === 'password') {
                field.type = 'text';
                icon.className = 'fas fa-eye-slash';
            } else {
                field.type = 'password';
                icon.className = 'fas fa-eye';
            }
        }

        // Password confirmation validation
        document.getElementById('confirm_password').addEventListener('input', function() {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = this.value;
            
            if (newPassword !== confirmPassword) {
                this.setCustomValidity('Passwords do not match');
            } else {
                this.setCustomValidity('');
            }
        });

        // Form submission handlers
        document.getElementById('profileForm').addEventListener('submit', function() {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Updating...';
            submitBtn.disabled = true;
        });

        document.getElementById('passwordForm').addEventListener('submit', function() {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Changing...';
            submitBtn.disabled = true;
        });

        // Clear password fields after successful submission
        <?php if (!empty($success_message) && strpos($success_message, 'Password') !== false): ?>
        document.getElementById('current_password').value = '';
        document.getElementById('new_password').value = '';
        document.getElementById('confirm_password').value = '';
        <?php endif; ?>

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>