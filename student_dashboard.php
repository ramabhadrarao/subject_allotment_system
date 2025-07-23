<?php
require_once 'dbconfig.php';

// Check student authentication
if (!isset($_SESSION['student_logged_in']) || $_SESSION['student_logged_in'] !== true) {
    header("Location: student_login.php");
    exit();
}

// Validate session
if (!validate_session($conn, 'student', $_SESSION['student_regno'])) {
    session_destroy();
    header("Location: student_login.php");
    exit();
}

$regno = $_SESSION['student_regno'];
$pool_id = $_SESSION['student_pool_id'];
$error_message = '';
$success_message = '';

// Handle priority selection form
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'save_priorities') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        log_security_event($conn, 'csrf_violation', 'high', 'CSRF token validation failed for priority selection', $regno);
        $error_message = 'Security validation failed. Please try again.';
    } else if (!prevent_resubmit($conn, 'student', $regno, 'priority_selection')) {
        $error_message = 'Form already submitted. Please refresh the page.';
    } else {
        try {
            // Check if already frozen
            $stmt = $conn->prepare("SELECT status FROM student_registrations WHERE regno = ? AND pool_id = ?");
            $stmt->execute([$regno, $pool_id]);
            $registration = $stmt->fetch();
            
            if ($registration && $registration['status'] == 'frozen') {
                $error_message = 'Your preferences are already frozen and cannot be modified.';
            } else {
                $priorities = [];
                $subject_priorities = $_POST['subject_priority'] ?? [];
                
                // Validate priorities
                $used_priorities = [];
                $has_valid_priority = false;
                
                foreach ($subject_priorities as $subject_code => $priority) {
                    $priority = intval($priority);
                    if ($priority > 0) {
                        if (in_array($priority, $used_priorities)) {
                            $error_message = 'Duplicate priority numbers are not allowed.';
                            break;
                        }
                        $used_priorities[] = $priority;
                        $priorities[] = ['subject_code' => $subject_code, 'priority' => $priority];
                        $has_valid_priority = true;
                    }
                }
                
                if (empty($error_message)) {
                    if (!$has_valid_priority) {
                        $error_message = 'Please set at least one priority.';
                    } else {
                        // Sort by priority
                        usort($priorities, function($a, $b) {
                            return $a['priority'] - $b['priority'];
                        });
                        
                        // Update priorities
                        $stmt = $conn->prepare("UPDATE student_registrations SET priority_order = ?, last_updated_ip = ?, updated_at = NOW() WHERE regno = ? AND pool_id = ?");
                        $stmt->execute([json_encode($priorities), get_client_ip(), $regno, $pool_id]);
                        
                        log_activity($conn, 'student', $regno, 'priority_updated', 'student_registrations', null, null, $priorities);
                        $success_message = 'Priorities saved successfully!';
                    }
                }
            }
        } catch(Exception $e) {
            error_log("Priority save error: " . $e->getMessage());
            $error_message = 'An error occurred while saving priorities.';
        }
    }
}

// Handle freeze preferences
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'freeze_priorities') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        log_security_event($conn, 'csrf_violation', 'high', 'CSRF token validation failed for freeze preferences', $regno);
        $error_message = 'Security validation failed. Please try again.';
    } else if (!prevent_resubmit($conn, 'student', $regno, 'freeze_preferences')) {
        $error_message = 'Form already submitted. Please refresh the page.';
    } else {
        try {
            $stmt = $conn->prepare("SELECT priority_order FROM student_registrations WHERE regno = ? AND pool_id = ?");
            $stmt->execute([$regno, $pool_id]);
            $registration = $stmt->fetch();
            
            if (!$registration || empty($registration['priority_order'])) {
                $error_message = 'Please set your priorities before freezing.';
            } else {
                $stmt = $conn->prepare("UPDATE student_registrations SET status = 'frozen', frozen_at = NOW(), last_updated_ip = ? WHERE regno = ? AND pool_id = ?");
                $stmt->execute([get_client_ip(), $regno, $pool_id]);
                
                log_activity($conn, 'student', $regno, 'preferences_frozen', 'student_registrations');
                $success_message = 'Preferences frozen successfully! You can no longer modify your selections.';
            }
        } catch(Exception $e) {
            error_log("Freeze preferences error: " . $e->getMessage());
            $error_message = 'An error occurred while freezing preferences.';
        }
    }
}

// Get student registration details
try {
    $stmt = $conn->prepare("SELECT sr.*, sp.pool_name, sp.semester, sp.batch FROM student_registrations sr JOIN subject_pools sp ON sr.pool_id = sp.id WHERE sr.regno = ? AND sr.pool_id = ?");
    $stmt->execute([$regno, $pool_id]);
    $student_registration = $stmt->fetch();
    
    if (!$student_registration) {
        header("Location: student_login.php");
        exit();
    }
    
    // Get available subjects for this pool
    $stmt = $conn->prepare("SELECT * FROM subject_pools WHERE id = ? OR (pool_name = ? AND semester = ? AND batch = ? AND is_active = 1) ORDER BY subject_name");
    $stmt->execute([$pool_id, $student_registration['pool_name'], $student_registration['semester'], $student_registration['batch']]);
    $available_subjects = $stmt->fetchAll();
    
    // Get current priorities
    $current_priorities = [];
    if (!empty($student_registration['priority_order'])) {
        $priority_data = json_decode($student_registration['priority_order'], true);
        if ($priority_data) {
            foreach ($priority_data as $item) {
                $current_priorities[$item['subject_code']] = $item['priority'];
            }
        }
    }
    
    // Get allotment result if exists
    $stmt = $conn->prepare("SELECT sa.*, sp.subject_name FROM subject_allotments sa JOIN subject_pools sp ON sa.subject_code = sp.subject_code WHERE sa.regno = ? AND sa.pool_id = ?");
    $stmt->execute([$regno, $pool_id]);
    $allotment_result = $stmt->fetch();
    
    // Get student academic data
    $stmt = $conn->prepare("SELECT * FROM student_academic_data WHERE regno = ?");
    $stmt->execute([$regno]);
    $academic_data = $stmt->fetch();

} catch(Exception $e) {
    error_log("Student dashboard data error: " . $e->getMessage());
    $available_subjects = [];
    $current_priorities = [];
    $allotment_result = null;
    $academic_data = null;
}

$csrf_token = generate_csrf_token();
$form_token = generate_token();
log_activity($conn, 'student', $regno, 'dashboard_view');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - Subject Allotment System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .navbar-brand {
            font-weight: bold;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        .card:hover {
            transform: translateY(-2px);
            transition: all 0.3s ease;
        }
        .profile-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .priority-card {
            border-left: 5px solid #28a745;
        }
        .allotment-card {
            border-left: 5px solid #007bff;
        }
        .academic-card {
            border-left: 5px solid #ffc107;
        }
        .subject-item {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 10px;
            border: 2px solid transparent;
            transition: all 0.3s ease;
        }
        .subject-item:hover {
            border-color: #667eea;
            background: white;
        }
        .priority-input {
            max-width: 80px;
        }
        .status-frozen {
            background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%);
            color: white;
        }
        .status-saved {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
        }
        .allotment-success {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
        }
        @media (max-width: 768px) {
            .card {
                margin-bottom: 15px;
            }
            .subject-item {
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container-fluid">
            <a class="navbar-brand" href="#">
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
                            <?php echo htmlspecialchars($regno); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#" onclick="refreshPage()">
                                <i class="fas fa-sync me-2"></i>Refresh
                            </a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="student_logout.php">
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
                <!-- Student Profile Card -->
                <div class="card profile-card">
                    <div class="card-body text-center">
                        <i class="fas fa-user-graduate fa-4x mb-3"></i>
                        <h4><?php echo htmlspecialchars($regno); ?></h4>
                        <p class="mb-2">
                            <i class="fas fa-envelope me-2"></i>
                            <?php echo htmlspecialchars($_SESSION['student_email']); ?>
                        </p>
                        <p class="mb-2">
                            <i class="fas fa-phone me-2"></i>
                            <?php echo htmlspecialchars($_SESSION['student_mobile']); ?>
                        </p>
                        <hr class="bg-white">
                        <div class="row text-center">
                            <div class="col-6">
                                <strong>Pool</strong><br>
                                <?php echo htmlspecialchars($student_registration['pool_name']); ?>
                            </div>
                            <div class="col-6">
                                <strong>Status</strong><br>
                                <span class="badge <?php echo $student_registration['status'] == 'frozen' ? 'status-frozen' : 'status-saved'; ?>">
                                    <?php echo ucfirst($student_registration['status']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Academic Data Card -->
                <?php if ($academic_data): ?>
                <div class="card academic-card">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0">
                            <i class="fas fa-chart-line me-2"></i>Academic Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-6">
                                <strong>CGPA:</strong><br>
                                <span class="h4 text-primary">
                                    <?php echo $academic_data['cgpa'] ? number_format($academic_data['cgpa'], 2) : 'N/A'; ?>
                                </span>
                            </div>
                            <div class="col-6">
                                <strong>Backlogs:</strong><br>
                                <span class="h4 <?php echo $academic_data['backlogs'] > 0 ? 'text-danger' : 'text-success'; ?>">
                                    <?php echo $academic_data['backlogs']; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Allotment Result Card -->
                <?php if ($allotment_result): ?>
                <div class="card allotment-success">
                    <div class="card-body text-center text-white">
                        <i class="fas fa-trophy fa-3x mb-3"></i>
                        <h4>Subject Allotted!</h4>
                        <h5><?php echo htmlspecialchars($allotment_result['subject_name']); ?></h5>
                        <p class="mb-2">
                            <strong>Subject Code:</strong> <?php echo htmlspecialchars($allotment_result['subject_code']); ?>
                        </p>
                        <?php if ($allotment_result['allotment_reason']): ?>
                        <p class="mb-0">
                            <small><strong>Allotment Basis:</strong> <?php echo htmlspecialchars($allotment_result['allotment_reason']); ?></small>
                        </p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Right Column -->
            <div class="col-lg-8">
                <!-- Priority Selection Card -->
                <div class="card priority-card">
                    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <i class="fas fa-list-ol me-2"></i>Subject Priority Selection
                        </h5>
                        <?php if ($student_registration['status'] != 'frozen' && !$allotment_result): ?>
                        <button type="button" class="btn btn-light btn-sm" onclick="freezePreferences()">
                            <i class="fas fa-lock me-1"></i>Freeze Preferences
                        </button>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if ($student_registration['status'] == 'frozen'): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-lock me-2"></i>
                                Your preferences are frozen and cannot be modified. 
                                <?php if ($student_registration['frozen_at']): ?>
                                    Frozen on <?php echo date('d M Y, h:i A', strtotime($student_registration['frozen_at'])); ?>
                                <?php endif; ?>
                            </div>
                        <?php elseif ($allotment_result): ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                Subject allotment has been completed. You cannot modify your preferences now.
                            </div>
                        <?php endif; ?>

                        <form method="POST" action="" id="priorityForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="form_token" value="<?php echo $form_token; ?>">
                            <input type="hidden" name="action" value="save_priorities">
                            
                            <?php if (empty($available_subjects)): ?>
                                <div class="alert alert-info text-center">
                                    <i class="fas fa-info-circle fa-2x mb-2"></i>
                                    <p>No subjects available for your pool at the moment.</p>
                                </div>
                            <?php else: ?>
                                <div class="row">
                                    <?php foreach ($available_subjects as $subject): ?>
                                    <div class="col-md-6">
                                        <div class="subject-item">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div class="flex-grow-1">
                                                    <h6 class="mb-2">
                                                        <strong><?php echo htmlspecialchars($subject['subject_name']); ?></strong>
                                                    </h6>
                                                    <p class="text-muted small mb-2">
                                                        <i class="fas fa-code me-1"></i>
                                                        <?php echo htmlspecialchars($subject['subject_code']); ?>
                                                    </p>
                                                    <p class="text-muted small mb-0">
                                                        <i class="fas fa-users me-1"></i>
                                                        Intake: <?php echo $subject['intake']; ?>
                                                    </p>
                                                </div>
                                                <div>
                                                    <label class="form-label small">Priority</label>
                                                    <input type="number" 
                                                           class="form-control priority-input text-center" 
                                                           name="subject_priority[<?php echo htmlspecialchars($subject['subject_code']); ?>]"
                                                           min="1" 
                                                           max="10" 
                                                           value="<?php echo $current_priorities[$subject['subject_code']] ?? ''; ?>"
                                                           <?php echo ($student_registration['status'] == 'frozen' || $allotment_result) ? 'readonly' : ''; ?>
                                                           placeholder="1-10">
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <?php if ($student_registration['status'] != 'frozen' && !$allotment_result): ?>
                                <div class="row mt-3">
                                    <div class="col-12">
                                        <div class="alert alert-info">
                                            <i class="fas fa-lightbulb me-2"></i>
                                            <strong>Instructions:</strong>
                                            <ul class="mb-0 mt-2">
                                                <li>Set priority numbers (1-10) for subjects you want to select</li>
                                                <li>1 = highest priority, 10 = lowest priority</li>
                                                <li>No duplicate priorities allowed</li>
                                                <li>You can save multiple times before freezing</li>
                                                <li>Once frozen, you cannot modify your selections</li>
                                            </ul>
                                        </div>
                                        
                                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                            <button type="submit" class="btn btn-success me-md-2">
                                                <i class="fas fa-save me-2"></i>Save Priorities
                                            </button>
                                            <button type="button" class="btn btn-warning" onclick="clearPriorities()">
                                                <i class="fas fa-times me-2"></i>Clear All
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </form>

                        <!-- Current Priorities Display -->
                        <?php if (!empty($current_priorities)): ?>
                        <div class="mt-4">
                            <h6><i class="fas fa-list me-2"></i>Your Current Priorities:</h6>
                            <div class="row">
                                <?php 
                                $sorted_priorities = $current_priorities;
                                asort($sorted_priorities);
                                foreach ($sorted_priorities as $subject_code => $priority): 
                                    $subject_name = '';
                                    foreach ($available_subjects as $subject) {
                                        if ($subject['subject_code'] == $subject_code) {
                                            $subject_name = $subject['subject_name'];
                                            break;
                                        }
                                    }
                                ?>
                                <div class="col-md-6 mb-2">
                                    <div class="d-flex justify-content-between align-items-center bg-light p-2 rounded">
                                        <span><strong><?php echo $priority; ?>.</strong> <?php echo htmlspecialchars($subject_name); ?></span>
                                        <small class="text-muted"><?php echo htmlspecialchars($subject_code); ?></small>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Freeze Confirmation Modal -->
    <div class="modal fade" id="freezeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-lock me-2"></i>Freeze Preferences
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Warning!</strong> Once you freeze your preferences, you will not be able to modify them.
                    </div>
                    <p>Are you sure you want to freeze your current subject priorities?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" action="" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="form_token" value="<?php echo generate_token(); ?>">
                        <input type="hidden" name="action" value="freeze_priorities">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-lock me-2"></i>Yes, Freeze Preferences
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function freezePreferences() {
            // Check if any priorities are set
            const priorityInputs = document.querySelectorAll('input[name^="subject_priority"]');
            let hasPriority = false;
            
            priorityInputs.forEach(input => {
                if (input.value && input.value.trim() !== '') {
                    hasPriority = true;
                }
            });
            
            if (!hasPriority) {
                alert('Please set at least one priority before freezing.');
                return;
            }
            
            const modal = new bootstrap.Modal(document.getElementById('freezeModal'));
            modal.show();
        }

        function clearPriorities() {
            if (confirm('Are you sure you want to clear all priorities?')) {
                const priorityInputs = document.querySelectorAll('input[name^="subject_priority"]');
                priorityInputs.forEach(input => {
                    input.value = '';
                });
            }
        }

        function refreshPage() {
            window.location.reload();
        }

        // Form validation
        document.getElementById('priorityForm').addEventListener('submit', function(e) {
            const priorityInputs = document.querySelectorAll('input[name^="subject_priority"]');
            const priorities = [];
            let hasError = false;

            priorityInputs.forEach(input => {
                const value = parseInt(input.value);
                if (value && value > 0) {
                    if (priorities.includes(value)) {
                        alert('Duplicate priority numbers are not allowed. Please use unique priority numbers.');
                        hasError = true;
                        return;
                    }
                    priorities.push(value);
                }
            });

            if (hasError) {
                e.preventDefault();
                return;
            }

            if (priorities.length === 0) {
                alert('Please set at least one priority.');
                e.preventDefault();
                return;
            }

            // Show loading state
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';
            submitBtn.disabled = true;
        });

        // Auto-refresh every 10 minutes
        setTimeout(function() {
            window.location.reload();
        }, 600000);

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>