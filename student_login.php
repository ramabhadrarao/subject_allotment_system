<?php
require_once 'dbconfig.php';

// Check if already logged in
if (isset($_SESSION['student_logged_in']) && $_SESSION['student_logged_in'] === true) {
    if (validate_session($conn, 'student', $_SESSION['student_regno'])) {
        header("Location: student_dashboard.php");
        exit();
    } else {
        session_destroy();
        session_start();
    }
}

$error_message = '';
$success_message = '';
$show_registration = false;

// Handle registration form
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'register') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        log_security_event($conn, 'csrf_violation', 'high', 'CSRF token validation failed for student registration');
        $error_message = 'Security validation failed. Please try again.';
    } else if (!check_rate_limit($conn, get_client_ip(), 'student_registration', 3, 300)) {
        log_security_event($conn, 'rate_limit_exceeded', 'medium', 'Student registration rate limit exceeded');
        $error_message = 'Too many registration attempts. Please try again later.';
    } else {
        $regno = strtoupper(trim($_POST['regno'] ?? ''));
        $email = strtolower(trim($_POST['email'] ?? ''));
        $mobile = trim($_POST['mobile'] ?? '');
        $pool_id = intval($_POST['pool_id'] ?? 0);

        if (empty($regno) || empty($email) || empty($mobile) || $pool_id <= 0) {
            $error_message = 'Please fill all required fields.';
        } else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error_message = 'Please enter a valid email address.';
        } else if (!preg_match('/^[0-9]{10}$/', $mobile)) {
            $error_message = 'Please enter a valid 10-digit mobile number.';
        } else {
            try {
                // Check if student exists in main database (if available)
                $student_exists = false;
                $student_programme = '';
                $student_semester = '';
                $student_batch = '';

                if ($attendance_conn) {
                    $stmt = $attendance_conn->prepare("SELECT programme, semester, batch FROM user WHERE regid = ?");
                    $stmt->execute([$regno]);
                    $student = $stmt->fetch();
                    
                    if ($student) {
                        $student_exists = true;
                        $student_programme = $student['programme'];
                        $student_semester = $student['semester'];
                        $student_batch = $student['batch'];
                    }
                }

                // Get subject pool details
                $stmt = $conn->prepare("SELECT * FROM subject_pools WHERE id = ? AND is_active = 1");
                $stmt->execute([$pool_id]);
                $pool = $stmt->fetch();

                if (!$pool) {
                    $error_message = 'Invalid subject pool selected.';
                } else {
                    // Check if student's programme is allowed for this pool
                    $allowed_programmes = json_decode($pool['allowed_programmes'], true);
                    
                    if ($student_exists && !in_array($student_programme, $allowed_programmes)) {
                        $error_message = "Your programme ($student_programme) is not eligible for this subject pool.";
                    } else if ($student_exists && $student_semester !== $pool['semester']) {
                        $error_message = "Your semester ($student_semester) does not match the pool semester ({$pool['semester']}).";
                    } else {
                        // Check if already registered for this pool
                        $stmt = $conn->prepare("SELECT id FROM student_registrations WHERE regno = ? AND pool_id = ?");
                        $stmt->execute([$regno, $pool_id]);
                        
                        if ($stmt->rowCount() > 0) {
                            $error_message = 'You are already registered for this subject pool.';
                        } else {
                            // Register student
                            $registration_token = generate_token();
                            
                            $stmt = $conn->prepare("INSERT INTO student_registrations (regno, email, mobile, pool_id, registration_token, registration_ip) VALUES (?, ?, ?, ?, ?, ?)");
                            $stmt->execute([$regno, $email, $mobile, $pool_id, $registration_token, get_client_ip()]);
                            
                            log_activity($conn, 'student', $regno, 'registration', 'student_registrations', $conn->lastInsertId());
                            
                            $success_message = 'Registration successful! You can now login and select your subject preferences.';
                        }
                    }
                }
            } catch(Exception $e) {
                error_log("Student registration error: " . $e->getMessage());
                $error_message = 'An error occurred during registration. Please try again.';
            }
        }
    }
}

// Handle login form
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'login') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        log_security_event($conn, 'csrf_violation', 'high', 'CSRF token validation failed for student login');
        $error_message = 'Security validation failed. Please try again.';
    } else if (!check_rate_limit($conn, get_client_ip(), 'student_login_attempt', 5, 300)) {
        log_security_event($conn, 'rate_limit_exceeded', 'medium', 'Student login rate limit exceeded');
        $error_message = 'Too many login attempts. Please try again later.';
    } else {
        $regno = strtoupper(trim($_POST['regno'] ?? ''));
        $email = strtolower(trim($_POST['email'] ?? ''));

        if (empty($regno) || empty($email)) {
            $error_message = 'Please enter both registration number and email.';
        } else {
            try {
                $stmt = $conn->prepare("SELECT * FROM student_registrations WHERE regno = ? AND email = ?");
                $stmt->execute([$regno, $email]);
                $student = $stmt->fetch();

                if ($student) {
                    $_SESSION['student_logged_in'] = true;
                    $_SESSION['student_regno'] = $student['regno'];
                    $_SESSION['student_email'] = $student['email'];
                    $_SESSION['student_mobile'] = $student['mobile'];
                    $_SESSION['student_pool_id'] = $student['pool_id'];

                    create_session($conn, 'student', $regno);
                    log_login($conn, 'student', $regno, 'login');
                    log_activity($conn, 'student', $regno, 'successful_login');

                    header("Location: student_dashboard.php");
                    exit();
                } else {
                    log_login($conn, 'student', $regno, 'failed_login');
                    log_security_event($conn, 'failed_login', 'medium', "Failed student login attempt for regno: $regno");
                    $error_message = 'Invalid registration number or email. Please check your credentials or register first.';
                }
            } catch(Exception $e) {
                error_log("Student login error: " . $e->getMessage());
                $error_message = 'An error occurred. Please try again.';
            }
        }
    }
}

// Get available subject pools
try {
    $stmt = $conn->prepare("SELECT * FROM subject_pools WHERE is_active = 1 ORDER BY pool_name, subject_name");
    $stmt->execute();
    $subject_pools = $stmt->fetchAll();
} catch(Exception $e) {
    $subject_pools = [];
}

$csrf_token = generate_csrf_token();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Portal - Subject Allotment System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }
        .portal-card {
            max-width: 500px;
            margin: auto;
            margin-top: 5vh;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.95);
        }
        .portal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            text-align: center;
            padding: 2rem;
            border-radius: 15px 15px 0 0;
        }
        .nav-tabs .nav-link {
            border: none;
            color: #667eea;
            font-weight: 600;
        }
        .nav-tabs .nav-link.active {
            background-color: #667eea;
            color: white;
            border-radius: 8px;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            padding: 12px;
            font-weight: 600;
            border-radius: 8px;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }
        .input-group-text {
            border-color: #ddd;
            background-color: #f8f9fa;
        }
        @media (max-width: 576px) {
            .portal-card {
                margin: 2vh 1rem;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-12">
                <div class="card portal-card">
                    <div class="portal-header">
                        <i class="fas fa-graduation-cap fa-3x mb-3"></i>
                        <h3>Student Portal</h3>
                        <p class="mb-0">Subject Allotment System</p>
                    </div>
                    <div class="card-body p-4">
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

                        <!-- Tab Navigation -->
                        <ul class="nav nav-tabs mb-4" id="portalTabs" role="tablist">
                            <li class="nav-item flex-fill text-center" role="presentation">
                                <button class="nav-link active w-100" id="login-tab" data-bs-toggle="tab" data-bs-target="#login" type="button" role="tab">
                                    <i class="fas fa-sign-in-alt me-2"></i>Login
                                </button>
                            </li>
                            <li class="nav-item flex-fill text-center" role="presentation">
                                <button class="nav-link w-100" id="register-tab" data-bs-toggle="tab" data-bs-target="#register" type="button" role="tab">
                                    <i class="fas fa-user-plus me-2"></i>Register
                                </button>
                            </li>
                        </ul>

                        <!-- Tab Content -->
                        <div class="tab-content" id="portalTabContent">
                            <!-- Login Tab -->
                            <div class="tab-pane fade show active" id="login" role="tabpanel">
                                <form method="POST" action="" id="loginForm">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="login">
                                    
                                    <div class="mb-3">
                                        <label for="login_regno" class="form-label">
                                            <i class="fas fa-id-card me-1"></i>Registration Number
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text">
                                                <i class="fas fa-id-card"></i>
                                            </span>
                                            <input type="text" 
                                                   class="form-control" 
                                                   id="login_regno" 
                                                   name="regno" 
                                                   placeholder="Enter your registration number"
                                                   required 
                                                   style="text-transform: uppercase;">
                                        </div>
                                    </div>

                                    <div class="mb-4">
                                        <label for="login_email" class="form-label">
                                            <i class="fas fa-envelope me-1"></i>Email Address
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text">
                                                <i class="fas fa-envelope"></i>
                                            </span>
                                            <input type="email" 
                                                   class="form-control" 
                                                   id="login_email" 
                                                   name="email" 
                                                   placeholder="Enter your email address"
                                                   required>
                                        </div>
                                    </div>

                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-sign-in-alt me-2"></i>Login
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <!-- Registration Tab -->
                            <div class="tab-pane fade" id="register" role="tabpanel">
                                <form method="POST" action="" id="registerForm">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action" value="register">
                                    
                                    <div class="mb-3">
                                        <label for="reg_regno" class="form-label">
                                            <i class="fas fa-id-card me-1"></i>Registration Number
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text">
                                                <i class="fas fa-id-card"></i>
                                            </span>
                                            <input type="text" 
                                                   class="form-control" 
                                                   id="reg_regno" 
                                                   name="regno" 
                                                   placeholder="Enter your registration number"
                                                   required 
                                                   style="text-transform: uppercase;">
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="reg_email" class="form-label">
                                            <i class="fas fa-envelope me-1"></i>Email Address
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text">
                                                <i class="fas fa-envelope"></i>
                                            </span>
                                            <input type="email" 
                                                   class="form-control" 
                                                   id="reg_email" 
                                                   name="email" 
                                                   placeholder="Enter your email address"
                                                   required>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label for="reg_mobile" class="form-label">
                                            <i class="fas fa-phone me-1"></i>Mobile Number
                                        </label>
                                        <div class="input-group">
                                            <span class="input-group-text">
                                                <i class="fas fa-phone"></i>
                                            </span>
                                            <input type="tel" 
                                                   class="form-control" 
                                                   id="reg_mobile" 
                                                   name="mobile" 
                                                   placeholder="Enter your mobile number"
                                                   pattern="[0-9]{10}"
                                                   maxlength="10"
                                                   required>
                                        </div>
                                    </div>

                                    <div class="mb-4">
                                        <label for="pool_id" class="form-label">
                                            <i class="fas fa-layer-group me-1"></i>Select Subject Pool
                                        </label>
                                        <select class="form-select" id="pool_id" name="pool_id" required>
                                            <option value="">Choose a subject pool...</option>
                                            <?php foreach ($subject_pools as $pool): ?>
                                                <option value="<?php echo $pool['id']; ?>">
                                                    <?php echo htmlspecialchars($pool['pool_name'] . ' - ' . $pool['subject_name'] . ' (Intake: ' . $pool['intake'] . ')'); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>

                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-user-plus me-2"></i>Register
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>

                        <div class="text-center mt-4">
                            <small class="text-muted">
                                <i class="fas fa-shield-alt me-1"></i>
                                Secure Registration & Login System
                            </small>
                        </div>
                    </div>
                </div>

                <div class="text-center mt-3">
                    <a href="admin_login.php" class="text-white text-decoration-none">
                        <i class="fas fa-user-shield me-2"></i>Admin Login
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form validation and user experience enhancements
        document.addEventListener('DOMContentLoaded', function() {
            // Auto uppercase registration numbers
            const regnoInputs = document.querySelectorAll('input[name="regno"]');
            regnoInputs.forEach(input => {
                input.addEventListener('input', function() {
                    this.value = this.value.toUpperCase();
                });
            });

            // Mobile number validation
            const mobileInput = document.getElementById('reg_mobile');
            if (mobileInput) {
                mobileInput.addEventListener('input', function() {
                    this.value = this.value.replace(/\D/g, '').substring(0, 10);
                });
            }

            // Form submission loading states
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function() {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
                    submitBtn.disabled = true;
                    
                    // Re-enable after 5 seconds to prevent permanent disable on validation errors
                    setTimeout(() => {
                        submitBtn.innerHTML = originalText;
                        submitBtn.disabled = false;
                    }, 5000);
                });
            });
        });

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }

        // Show registration tab if there was a registration error
        <?php if (isset($_POST['action']) && $_POST['action'] == 'register'): ?>
        document.getElementById('register-tab').click();
        <?php endif; ?>
    </script>
</body>
</html>