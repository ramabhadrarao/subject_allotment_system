<?php
require_once 'dbconfig.php';

$error_message = '';
$success_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        log_security_event($conn, 'csrf_violation', 'high', 'CSRF token validation failed for student portal', null);
        $error_message = 'Security validation failed. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        // Student Login
        if ($action == 'login' && prevent_resubmit($conn, 'student', $_POST['regno'] ?? '', 'login')) {
            $regno = strtoupper(trim($_POST['regno'] ?? ''));
            $password = $_POST['password'] ?? '';
            
            if (empty($regno) || empty($password)) {
                $error_message = 'Please enter both registration number and password.';
            } else {
                try {
                    // Check student registration
                    $stmt = $conn->prepare("SELECT * FROM student_registrations WHERE regno = ?");
                    $stmt->execute([$regno]);
                    $student = $stmt->fetch();
                    
                    if (!$student) {
                        $error_message = 'Registration number not found. Please register first.';
                        log_security_event($conn, 'student_login_failed', 'medium', 'Student login failed - regno not found', $regno);
                    } else {
                        // Verify password (using email as password - case insensitive)
                        $student_email = trim(strtolower($student['email']));
                        $entered_password = trim(strtolower($password));
                        
                        if ($entered_password !== $student_email) {
                            $error_message = 'Invalid password. Use your registered email address as password.';
                            log_security_event($conn, 'student_login_failed', 'medium', 'Student login failed - invalid password', $regno);
                        } else {
                            // Successful login
                            session_regenerate_id(true); // Regenerate session ID for security
                            $_SESSION['student_logged_in'] = true;
                            $_SESSION['student_regno'] = $regno;
                            $_SESSION['student_email'] = $student['email'];
                            $_SESSION['student_mobile'] = $student['mobile'];
                            $_SESSION['student_pool_id'] = $student['pool_id'];
                            $_SESSION['student_login_time'] = time();
                            
                            // Create session record
                            create_session($conn, 'student', $regno);
                            
                            log_login($conn, 'student', $regno, 'login');
                            log_activity($conn, 'student', $regno, 'login_success', 'student_registrations', $student['id']);
                            
                            // Force redirect with exit
                            header("Location: student_dashboard.php");
                            exit();
                        }
                    }
                } catch(Exception $e) {
                    error_log("Student login error: " . $e->getMessage());
                    $error_message = 'An error occurred during login. Please try again.';
                }
            }
        }
        
        // Student Registration
        elseif ($action == 'register' && prevent_resubmit($conn, 'student', $_POST['regno'] ?? '', 'register')) {
            $regno = strtoupper(trim($_POST['regno'] ?? ''));
            $email = strtolower(trim($_POST['email'] ?? ''));
            $mobile = trim(preg_replace('/\s+/', '', $_POST['mobile'] ?? '')); // Remove all spaces
            $pool_id = intval($_POST['pool_id'] ?? 0);
            
            // Normalize mobile to 10 digits
            $mobile = preg_replace('/^(\+91|91)/', '', $mobile);
            
            if (empty($regno) || empty($email) || empty($mobile) || empty($pool_id)) {
                $error_message = 'Please fill in all required fields.';
            } else if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error_message = 'Please enter a valid email address.';
            } else if (!preg_match('/^[6-9][0-9]{9}$/', $mobile)) {
                $error_message = 'Mobile number must be 10 digits starting with 6-9.';
            } else {
                try {
                    // Include validation configuration
                    require_once 'validate_student.php';
                    
                    // First validate against existing database (camattendance)
                    $student_validation = validateStudentInExistingDB($attendance_conn, $regno);
                    
                    if (!$student_validation['exists']) {
                        $error_message = 'Registration number not found in student database. Please contact administration.';
                    } else {
                        $student_data = $student_validation['data'];
                        
                        // Validate email and mobile match (normalize both for comparison)
                        $student_email = trim(strtolower($student_data['email']));
                        $student_mobile = trim(preg_replace('/\s+/', '', $student_data['mobile']));
                        $student_mobile = preg_replace('/^(\+91|91)/', '', $student_mobile);
                        
                        if ($student_email !== $email) {
                            $error_message = 'Email address does not match our records. Please use the email from your student profile.';
                        } else if ($student_mobile !== $mobile) {
                            $error_message = 'Mobile number does not match our records. Please use the mobile from your student profile.';
                        } else {
                            // Student validation passed, now check if selected subject is eligible
                            $stmt = $conn->prepare("
                                SELECT * FROM subject_pools 
                                WHERE id = ? AND is_active = 1
                            ");
                            $stmt->execute([$pool_id]);
                            $selected_subject = $stmt->fetch();
                            
                            if (!$selected_subject) {
                                $error_message = 'Selected subject is not available.';
                            } else {
                                // Check eligibility
                                $allowed_programmes = json_decode($selected_subject['allowed_programmes'], true);
                                
                                if (!in_array($student_data['programme'], $allowed_programmes) || 
                                    $selected_subject['semester'] !== $student_data['semester'] || 
                                    $selected_subject['batch'] !== $student_data['batch']) {
                                    $error_message = 'Selected subject is not eligible for your profile.';
                                } else {
                                    // Check if already registered for any subject in the same pool
                                    $stmt = $conn->prepare("
                                        SELECT sr.id FROM student_registrations sr
                                        JOIN subject_pools sp1 ON sr.pool_id = sp1.id
                                        JOIN subject_pools sp2 ON sp1.pool_name = sp2.pool_name 
                                            AND sp1.semester = sp2.semester 
                                            AND sp1.batch = sp2.batch
                                        WHERE sr.regno = ? AND sp2.id = ?
                                    ");
                                    $stmt->execute([$regno, $pool_id]);
                                    
                                    if ($stmt->rowCount() > 0) {
                                        $error_message = 'You are already registered for a subject in this pool.';
                                    } else {
                                        // All validations passed - register student
                                        $registration_token = generate_token();
                                        
                                        $stmt = $conn->prepare("INSERT INTO student_registrations (regno, email, mobile, pool_id, registration_token, registration_ip) VALUES (?, ?, ?, ?, ?, ?)");
                                        $stmt->execute([$regno, $email, $mobile, $pool_id, $registration_token, get_client_ip()]);
                                        
                                        log_activity($conn, 'student', $regno, 'registration', 'student_registrations', $conn->lastInsertId(), null, [
                                            'pool_id' => $pool_id,
                                            'subject_name' => $selected_subject['subject_name'],
                                            'subject_code' => $selected_subject['subject_code'],
                                            'pool_name' => $selected_subject['pool_name'],
                                            'validated_student' => $student_data
                                        ]);
                                        
                                        $success_message = "Registration successful!<br>" .
                                            "<strong>Welcome {$student_data['student_name']}!</strong><br>" .
                                            "Programme: {$student_data['programme']}<br>" .
                                            "Subject: {$selected_subject['subject_name']} ({$selected_subject['subject_code']})<br>" .
                                            "Pool: {$selected_subject['pool_name']}<br>" .
                                            "You can now login using your registration number as username and email address as password.";
                                    }
                                }
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
}

$csrf_token = generate_csrf_token();

// Log portal access
log_activity($conn, 'system', 'anonymous', 'student_portal_accessed');
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
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .portal-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .portal-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
            max-width: 800px;
            width: 100%;
        }
        .portal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 20px 20px 0 0;
            text-align: center;
        }
        .portal-body {
            padding: 2rem;
        }
        .nav-pills .nav-link {
            border-radius: 25px;
            padding: 12px 30px;
            margin: 0 10px;
            transition: all 0.3s ease;
        }
        .nav-pills .nav-link.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }
        .form-control, .form-select {
            border-radius: 15px;
            border: 2px solid #e9ecef;
            padding: 12px 20px;
            transition: all 0.3s ease;
        }
        .form-control:focus, .form-select:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
            transform: translateY(-2px);
        }
        .btn {
            border-radius: 25px;
            padding: 12px 30px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }
        .btn-success {
            background: linear-gradient(135deg, #56ab2f 0%, #a8e6cf 100%);
            border: none;
        }
        .btn-success:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }
        .input-group-text {
            border-color: #ddd;
            background-color: #f8f9fa;
        }
        .eligible-pool {
            background-color: #d4edda !important;
            color: #155724 !important;
        }
        .ineligible-pool {
            background-color: #f8d7da !important;
            color: #721c24 !important;
        }
        #eligibilityChecker {
            animation: slideDown 0.3s ease-out;
        }
        #subjectSelection {
            animation: slideDown 0.3s ease-out;
        }
        .form-check-input:checked ~ .form-check-label {
            color: #28a745;
            font-weight: bold;
        }
        .card.border-success {
            border-color: #28a745 !important;
            box-shadow: 0 0 10px rgba(40, 167, 69, 0.3);
        }
        .login-info {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            border-radius: 15px;
            padding: 15px;
            margin-bottom: 20px;
        }
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @media (max-width: 768px) {
            .portal-body {
                padding: 1rem;
            }
            .nav-pills .nav-link {
                margin: 5px 0;
                text-align: center;
            }
        }
    </style>
</head>
<body>
    <div class="portal-container">
        <div class="portal-card">
            <div class="portal-header">
                <h1 class="mb-2">
                    <i class="fas fa-graduation-cap me-2"></i>
                    Student Portal
                </h1>
                <p class="mb-0">Subject Allotment System</p>
            </div>

            <div class="portal-body">
                <?php if (!empty($error_message)): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?php echo $error_message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($success_message)): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo $success_message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Login Instructions -->
                <div class="login-info">
                    <h6><i class="fas fa-info-circle me-2"></i>Login Instructions</h6>
                    <p class="mb-0">
                        <strong>Username:</strong> Your Registration Number (e.g., 23A21A6549)<br>
                        <strong>Password:</strong> Your Registered Email Address
                    </p>
                </div>

                <!-- Navigation Tabs -->
                <ul class="nav nav-pills nav-justified mb-4" id="portalTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="login-tab" data-bs-toggle="pill" data-bs-target="#login" type="button" role="tab">
                            <i class="fas fa-sign-in-alt me-2"></i>Login
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="register-tab" data-bs-toggle="pill" data-bs-target="#register" type="button" role="tab">
                            <i class="fas fa-user-plus me-2"></i>Register
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="portalTabContent">
                    <!-- Eligibility Checker -->
                    <div class="card mb-3" id="eligibilityChecker" style="display: none;">
                        <div class="card-header bg-info text-white">
                            <h6 class="mb-0">
                                <i class="fas fa-check-circle me-2"></i>Eligibility Check Results
                            </h6>
                        </div>
                        <div class="card-body" id="eligibilityResults">
                            <!-- Results will be populated here -->
                        </div>
                    </div>

                    <!-- Login Tab -->
                    <div class="tab-pane fade show active" id="login" role="tabpanel">
                        <form method="POST" action="" id="loginForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="form_token" value="<?php echo generate_token(); ?>">
                            <input type="hidden" name="action" value="login">

                            <div class="mb-4">
                                <label for="regno" class="form-label">
                                    <i class="fas fa-id-card me-1"></i>Registration Number (Username)
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-user"></i>
                                    </span>
                                    <input type="text" class="form-control" id="regno" name="regno" 
                                           placeholder="Enter your registration number (e.g., 23A21A6549)" required
                                           style="text-transform: uppercase;"
                                           value="<?php echo htmlspecialchars($_POST['regno'] ?? ''); ?>">
                                </div>
                            </div>

                            <div class="mb-4">
                                <label for="password" class="form-label">
                                    <i class="fas fa-lock me-1"></i>Email Address (Password)
                                </label>
                                <div class="input-group">
                                    <span class="input-group-text">
                                        <i class="fas fa-envelope"></i>
                                    </span>
                                    <input type="email" class="form-control" id="password" name="password" 
                                           placeholder="Enter your registered email address" required>
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('password')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                                <small class="text-muted">Use your registered email address as password</small>
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-sign-in-alt me-2"></i>Login to Dashboard
                                </button>
                            </div>
                            
                            <div class="text-center mt-3">
                                <small class="text-muted">
                                    Don't have an account? Switch to the <strong>Register</strong> tab above
                                </small>
                            </div>
                        </form>
                    </div>

                    <!-- Registration Tab -->
                    <div class="tab-pane fade" id="register" role="tabpanel">
                        <!-- Mandatory Eligibility Check -->
                        <div class="alert alert-warning">
                            <h6><i class="fas fa-exclamation-triangle me-2"></i>Eligibility Check Required</h6>
                            <p class="mb-2">You must check your eligibility first to see available subjects and proceed with registration.</p>
                            <button type="button" class="btn btn-warning" onclick="checkEligibility()" id="checkEligibilityBtn">
                                <i class="fas fa-search me-1"></i>Check My Eligibility
                            </button>
                        </div>

                        <form method="POST" action="" id="registerForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="form_token" value="<?php echo generate_token(); ?>">
                            <input type="hidden" name="action" value="register">

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="reg_regno" class="form-label">
                                            <i class="fas fa-id-card me-1"></i>Registration Number
                                        </label>
                                        <input type="text" class="form-control" id="reg_regno" name="regno" 
                                               placeholder="Enter registration number" required
                                               style="text-transform: uppercase;">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="reg_mobile" class="form-label">
                                            <i class="fas fa-mobile-alt me-1"></i>Mobile Number
                                        </label>
                                        <input type="tel" class="form-control" id="reg_mobile" name="mobile" 
                                               placeholder="Enter 10-digit mobile number" required
                                               pattern="[6-9][0-9]{9}" maxlength="10">
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label for="reg_email" class="form-label">
                                    <i class="fas fa-envelope me-1"></i>Email Address
                                </label>
                                <input type="email" class="form-control" id="reg_email" name="email" 
                                       placeholder="Enter your email address" required>
                                <small class="text-muted">This email will be used as your login password</small>
                            </div>

                            <div class="mb-4" id="subjectSelection" style="display: none;">
                                <label class="form-label">
                                    <i class="fas fa-books me-1"></i>Your Eligible Subjects
                                </label>
                                <div id="eligibleSubjects">
                                    <!-- Eligible subjects will be populated here after eligibility check -->
                                </div>
                                <input type="hidden" id="pool_id" name="pool_id" required>
                                
                                <div class="alert alert-success mt-3">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <strong>Great!</strong> The subjects shown above are available for your registration.
                                    You can now proceed to register.
                                </div>
                            </div>

                            <div class="d-grid">
                                <button type="submit" class="btn btn-success btn-lg" id="registerBtn" disabled>
                                    <i class="fas fa-user-plus me-2"></i>Register for Pool
                                </button>
                                <small class="text-muted text-center mt-2">
                                    <i class="fas fa-info-circle me-1"></i>
                                    Registration button will be enabled after eligibility check
                                </small>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="text-center mt-4">
                    <small class="text-muted">
                        <i class="fas fa-info-circle me-1"></i>
                        For assistance, contact the administration office
                    </small>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-format mobile numbers by removing spaces and normalizing
        function formatMobile(input) {
            // Remove all spaces and normalize
            let value = input.value.replace(/\s+/g, '');
            // Remove country code if present
            value = value.replace(/^(\+91|91)/, '');
            // Keep only digits
            value = value.replace(/[^0-9]/g, '');
            // Limit to 10 digits
            value = value.substring(0, 10);
            input.value = value;
        }
        
        // Add event listeners to mobile fields
        document.addEventListener('DOMContentLoaded', function() {
            const mobileFields = ['reg_mobile'];
            mobileFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (field) {
                    field.addEventListener('input', function() {
                        formatMobile(this);
                    });
                    field.addEventListener('paste', function() {
                        setTimeout(() => formatMobile(this), 10);
                    });
                }
            });
        });
        
        async function checkEligibility() {
            const regno = document.getElementById('reg_regno').value.toUpperCase();
            const email = document.getElementById('reg_email').value.toLowerCase();
            const mobile = document.getElementById('reg_mobile').value;
            
            if (!regno || !email || !mobile) {
                alert('Please fill in Registration Number, Email, and Mobile fields first.');
                return;
            }
            
            const resultsDiv = document.getElementById('eligibilityResults');
            const checkerCard = document.getElementById('eligibilityChecker');
            const checkBtn = document.getElementById('checkEligibilityBtn');
            
            // Show loading
            resultsDiv.innerHTML = '<div class="text-center"><i class="fas fa-spinner fa-spin fa-2x"></i><br>Checking eligibility...</div>';
            checkerCard.style.display = 'block';
            checkBtn.disabled = true;
            checkBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Checking...';
            
            try {
                const response = await fetch('check_eligibility.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `regno=${encodeURIComponent(regno)}&email=${encodeURIComponent(email)}&mobile=${encodeURIComponent(mobile)}&csrf_token=<?php echo $csrf_token; ?>`
                });
                
                const data = await response.json();
                
                if (data.success) {
                    let html = `
                        <div class="row">
                            <div class="col-md-6">
                                <h6><i class="fas fa-user-check text-success me-2"></i>Student Verified</h6>
                                <p><strong>Name:</strong> ${data.student.name}</p>
                                <p><strong>Programme:</strong> ${data.student.programme}</p>
                                <p><strong>Semester:</strong> ${data.student.semester}</p>
                                <p><strong>Batch:</strong> ${data.student.batch}</p>
                            </div>
                            <div class="col-md-6">
                                <h6><i class="fas fa-books text-info me-2"></i>Available Subjects</h6>
                    `;
                    
                    if (data.eligible_pools.length > 0) {
                        html += '<div class="mb-3">';
                        data.eligible_pools.forEach(pool => {
                            html += `<div class="mb-3">
                                <div class="d-flex align-items-start">
                                    <span class="badge bg-success me-2 mt-1">✓</span>
                                    <div>
                                        <strong>${pool.pool_name}</strong><br>
                                        <small class="text-muted">Subjects: ${pool.subjects}</small><br>
                                        <small class="text-info">Semester: ${pool.semester} | Batch: ${pool.batch}</small>
                                    </div>
                                </div>
                            </div>`;
                        });
                        html += '</div>';
                        
                        html += `<div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i>
                            Verification successful! You can now select a subject and register.
                        </div>`;
                    } else {
                        html += `<div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            No subjects are available for your current programme and semester.
                        </div>`;
                    }
                    
                    html += '</div></div>';
                    resultsDiv.innerHTML = html;
                    
                    // Show subject selection and enable registration
                    if (data.eligible_pools.length > 0) {
                        showEligiblePools(data.eligible_pools);
                        document.getElementById('registerBtn').disabled = false;
                        document.getElementById('registerBtn').innerHTML = '<i class="fas fa-user-plus me-2"></i>Register for Pool';
                        
                        // Set the first eligible pool as default selection
                        document.getElementById('pool_id').value = data.eligible_pools[0].id;
                        
                        // Hide eligibility check button
                        checkBtn.style.display = 'none';
                    }
                    
                } else {
                    resultsDiv.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-times-circle me-2"></i>
                            <strong>Verification Failed:</strong> ${data.message}
                        </div>
                    `;
                    
                    // Reset button
                    checkBtn.disabled = false;
                    checkBtn.innerHTML = '<i class="fas fa-search me-1"></i>Check My Eligibility';
                }
            } catch (error) {
                resultsDiv.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Error checking eligibility. Please try again.
                    </div>
                `;
                
                // Reset button
                checkBtn.disabled = false;
                checkBtn.innerHTML = '<i class="fas fa-search me-1"></i>Check My Eligibility';
            }
        }
        
        function showEligiblePools(eligiblePools) {
            const subjectSelectionDiv = document.getElementById('subjectSelection');
            const eligibleSubjectsDiv = document.getElementById('eligibleSubjects');
            
            let html = '';
            
            // Show eligible pools without selection - just display
            eligiblePools.forEach(pool => {
                html += `
                    <div class="card mb-3 border-success">
                        <div class="card-header bg-success text-white">
                            <h6 class="mb-0">
                                <i class="fas fa-check-circle me-2"></i>${pool.pool_name}
                                <span class="badge bg-light text-success ms-2">${pool.semester}</span>
                                <span class="badge bg-light text-success ms-1">${pool.batch}</span>
                            </h6>
                        </div>
                        <div class="card-body">
                            <h6 class="text-success mb-2">
                                <i class="fas fa-books me-2"></i>Available Subjects:
                            </h6>
                            <p class="mb-2">${pool.subjects}</p>
                            <div class="alert alert-light border-success mb-0">
                                <small class="text-success">
                                    <i class="fas fa-info-circle me-1"></i>
                                    You will be registered for this pool and can select your preferred subjects later.
                                </small>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            eligibleSubjectsDiv.innerHTML = html;
            subjectSelectionDiv.style.display = 'block';
        }
        
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

        // Form submission handlers
        document.getElementById('loginForm').addEventListener('submit', function() {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Logging in...';
            submitBtn.disabled = true;
        });

        document.getElementById('registerForm').addEventListener('submit', function() {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Registering...';
            submitBtn.disabled = true;
        });

        // Auto-capitalize registration numbers
        document.getElementById('regno').addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
        
        document.getElementById('reg_regno').addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>