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
$allotment_results = [];

// Handle allotment execution
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'run_allotment') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        log_security_event($conn, 'csrf_violation', 'high', 'CSRF token validation failed for allotment execution', $_SESSION['admin_username']);
        $error_message = 'Security validation failed. Please try again.';
    } else if (!prevent_resubmit($conn, 'admin', $_SESSION['admin_username'], 'run_allotment')) {
        $error_message = 'Allotment already running. Please refresh the page.';
    } else {
        $pool_id = intval($_POST['pool_id'] ?? 0);
        
        if ($pool_id <= 0) {
            $error_message = 'Please select a valid subject pool.';
        } else {
            try {
                // Start transaction
                $conn->beginTransaction();
                
                // Clear existing allotments for this pool
                $stmt = $conn->prepare("DELETE FROM subject_allotments WHERE pool_id = ?");
                $stmt->execute([$pool_id]);
                
                // Get all subjects in the pool
                $stmt = $conn->prepare("SELECT * FROM subject_pools WHERE (id = ? OR pool_id = ?) AND is_active = 1 ORDER BY subject_code");
                $stmt->execute([$pool_id, $pool_id]);
                $subjects = $stmt->fetchAll();
                
                // Initialize subject intake tracking
                $subject_intake = [];
                foreach ($subjects as $subject) {
                    $subject_intake[$subject['subject_code']] = $subject['intake'];
                }
                
                // Get all students registered for this pool with frozen preferences
                $stmt = $conn->prepare("
                    SELECT sr.*, sad.cgpa, sad.backlogs 
                    FROM student_registrations sr 
                    LEFT JOIN student_academic_data sad ON sr.regno = sad.regno 
                    WHERE sr.pool_id = ? AND sr.status = 'frozen' AND sr.priority_order IS NOT NULL
                    ORDER BY 
                        CASE 
                            WHEN sad.cgpa IS NOT NULL AND (sad.backlogs IS NULL OR sad.backlogs = 0) THEN 1
                            WHEN sad.backlogs IS NOT NULL AND sad.backlogs > 0 THEN 2
                            ELSE 3
                        END,
                        sad.cgpa DESC,
                        sad.backlogs ASC,
                        sr.frozen_at ASC
                ");
                $stmt->execute([$pool_id]);
                $students = $stmt->fetchAll();
                
                $allotment_log = [];
                $total_students = count($students);
                $allotted_count = 0;
                
                // Process each student
                foreach ($students as $student) {
                    $regno = $student['regno'];
                    $priorities = json_decode($student['priority_order'], true);
                    
                    if (!$priorities) continue;
                    
                    // Sort priorities by priority number
                    usort($priorities, function($a, $b) {
                        return $a['priority'] - $b['priority'];
                    });
                    
                    $allotted = false;
                    
                    // Try to allot based on priority order
                    foreach ($priorities as $priority_item) {
                        $subject_code = $priority_item['subject_code'];
                        
                        // Check if seats available for this subject
                        if (isset($subject_intake[$subject_code]) && $subject_intake[$subject_code] > 0) {
                            // Allot the subject
                            $subject_intake[$subject_code]--;
                            
                            // Determine allotment reason
                            $reason_parts = [];
                            if ($student['cgpa'] !== null && ($student['backlogs'] === null || $student['backlogs'] == 0)) {
                                $reason_parts[] = "CGPA: " . number_format($student['cgpa'], 2);
                                $rank_type = "CGPA-based";
                            } else if ($student['backlogs'] !== null && $student['backlogs'] > 0) {
                                $reason_parts[] = "Backlogs: " . $student['backlogs'];
                                $rank_type = "Backlog-based";
                            } else {
                                $rank_type = "Registration order";
                            }
                            
                            $reason_parts[] = "Priority: " . $priority_item['priority'];
                            $reason_parts[] = "Frozen at: " . date('M j, H:i', strtotime($student['frozen_at']));
                            
                            $allotment_reason = $rank_type . " (" . implode(", ", $reason_parts) . ")";
                            
                            // Calculate rank among allotted students for this subject
                            $stmt = $conn->prepare("SELECT COUNT(*) + 1 as rank FROM subject_allotments WHERE subject_code = ?");
                            $stmt->execute([$subject_code]);
                            $rank = $stmt->fetch()['rank'];
                            
                            // Insert allotment
                            $stmt = $conn->prepare("INSERT INTO subject_allotments (regno, pool_id, subject_code, allotment_reason, allotment_rank, allotted_by, allotment_ip) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $stmt->execute([
                                $regno,
                                $pool_id,
                                $subject_code,
                                $allotment_reason,
                                $rank,
                                $_SESSION['admin_id'],
                                get_client_ip()
                            ]);
                            
                            $allotment_log[] = [
                                'regno' => $regno,
                                'subject_code' => $subject_code,
                                'reason' => $allotment_reason,
                                'rank' => $rank
                            ];
                            
                            $allotted = true;
                            $allotted_count++;
                            break; // Student got allotted, move to next student
                        }
                    }
                    
                    if (!$allotted) {
                        $allotment_log[] = [
                            'regno' => $regno,
                            'subject_code' => 'NOT_ALLOTTED',
                            'reason' => 'No seats available in preferred subjects',
                            'rank' => 0
                        ];
                    }
                }
                
                // Commit transaction
                $conn->commit();
                
                log_activity($conn, 'admin', $_SESSION['admin_username'], 'allotment_executed', 'subject_allotments', $pool_id, null, [
                    'total_students' => $total_students,
                    'allotted_students' => $allotted_count,
                    'pool_id' => $pool_id
                ]);
                
                $success_message = "Allotment completed successfully! $allotted_count out of $total_students students were allotted subjects.";
                $allotment_results = $allotment_log;
                
            } catch(Exception $e) {
                $conn->rollBack();
                error_log("Allotment execution error: " . $e->getMessage());
                $error_message = 'An error occurred during allotment execution: ' . $e->getMessage();
            }
        }
    }
}

// Get available pools for allotment
try {
    $stmt = $conn->prepare("
        SELECT 
            sp.id, 
            sp.pool_name, 
            sp.semester, 
            sp.batch,
            COUNT(DISTINCT sr.regno) as registered_students,
            COUNT(DISTINCT CASE WHEN sr.status = 'frozen' THEN sr.regno END) as frozen_students,
            COUNT(DISTINCT sa.regno) as allotted_students
        FROM subject_pools sp
        LEFT JOIN student_registrations sr ON sp.id = sr.pool_id
        LEFT JOIN subject_allotments sa ON sp.id = sa.pool_id
        WHERE sp.is_active = 1
        GROUP BY sp.id, sp.pool_name, sp.semester, sp.batch
        HAVING registered_students > 0
        ORDER BY sp.pool_name, sp.semester
    ");
    $stmt->execute();
    $available_pools = $stmt->fetchAll();
} catch(Exception $e) {
    $available_pools = [];
}

$csrf_token = generate_csrf_token();
$form_token = generate_token();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Run Allotment - Subject Allotment System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .allotment-card {
            border-left: 5px solid #dc3545;
        }
        .results-card {
            border-left: 5px solid #28a745;
        }
        .pool-card {
            border-left: 5px solid #007bff;
        }
        .algorithm-steps {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
        }
        .step-item {
            padding: 10px 0;
            border-bottom: 1px solid rgba(255,255,255,0.2);
        }
        .step-item:last-child {
            border-bottom: none;
        }
        .result-success {
            background-color: #d4edda;
            border-left: 4px solid #28a745;
        }
        .result-failed {
            background-color: #f8d7da;
            border-left: 4px solid #dc3545;
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
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="admin_dashboard.php">
                    <i class="fas fa-arrow-left me-1"></i>Back to Dashboard
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-cogs me-2"></i>Run Subject Allotment</h2>
            <div class="text-muted">
                <small>Logged in as: <?php echo htmlspecialchars($_SESSION['admin_name']); ?></small>
            </div>
        </div>

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
            <!-- Left Column - Allotment Controls -->
            <div class="col-lg-6">
                <!-- Algorithm Overview -->
                <div class="card mb-4">
                    <div class="card-body algorithm-steps">
                        <h5 class="text-white mb-4">
                            <i class="fas fa-robot me-2"></i>Allotment Algorithm
                        </h5>
                        <div class="step-item">
                            <strong>Step 1:</strong> Sort students by CGPA (highest first) for those without backlogs
                        </div>
                        <div class="step-item">
                            <strong>Step 2:</strong> Sort students with backlogs by lowest backlogs first
                        </div>
                        <div class="step-item">
                            <strong>Step 3:</strong> For equal criteria, sort by preference freeze time (earliest first)
                        </div>
                        <div class="step-item">
                            <strong>Step 4:</strong> Allot subjects based on student priority order (1st choice → 2nd choice → etc.)
                        </div>
                        <div class="step-item">
                            <strong>Step 5:</strong> Track intake limits - move to next priority if subject is full
                        </div>
                    </div>
                </div>

                <!-- Pool Selection -->
                <div class="card allotment-card">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-play-circle me-2"></i>Execute Allotment
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($available_pools)): ?>
                            <div class="alert alert-info text-center">
                                <i class="fas fa-info-circle fa-2x mb-2"></i>
                                <p>No pools available for allotment. Make sure:</p>
                                <ul class="list-unstyled">
                                    <li>• Students have registered for subject pools</li>
                                    <li>• Students have frozen their preferences</li>
                                    <li>• Subject pools are active</li>
                                </ul>
                            </div>
                        <?php else: ?>
                            <form method="POST" action="" id="allotmentForm">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                <input type="hidden" name="form_token" value="<?php echo $form_token; ?>">
                                <input type="hidden" name="action" value="run_allotment">
                                
                                <div class="mb-3">
                                    <label for="pool_id" class="form-label">
                                        <i class="fas fa-layer-group me-2"></i>Select Subject Pool
                                    </label>
                                    <select class="form-select" id="pool_id" name="pool_id" required>
                                        <option value="">Choose a pool to run allotment...</option>
                                        <?php foreach ($available_pools as $pool): ?>
                                            <option value="<?php echo $pool['id']; ?>" 
                                                    data-registered="<?php echo $pool['registered_students']; ?>"
                                                    data-frozen="<?php echo $pool['frozen_students']; ?>"
                                                    data-allotted="<?php echo $pool['allotted_students']; ?>">
                                                <?php echo htmlspecialchars($pool['pool_name'] . ' - ' . $pool['semester'] . ' (' . $pool['batch'] . ')'); ?>
                                                - Registered: <?php echo $pool['registered_students']; ?>
                                                | Frozen: <?php echo $pool['frozen_students']; ?>
                                                | Allotted: <?php echo $pool['allotted_students']; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div id="poolInfo" class="alert alert-info d-none">
                                    <h6><i class="fas fa-info-circle me-2"></i>Pool Information</h6>
                                    <div class="row">
                                        <div class="col-4">
                                            <strong>Registered:</strong><br>
                                            <span id="infoRegistered" class="h5 text-primary">0</span>
                                        </div>
                                        <div class="col-4">
                                            <strong>Frozen:</strong><br>
                                            <span id="infoFrozen" class="h5 text-success">0</span>
                                        </div>
                                        <div class="col-4">
                                            <strong>Allotted:</strong><br>
                                            <span id="infoAllotted" class="h5 text-warning">0</span>
                                        </div>
                                    </div>
                                </div>

                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    <strong>Warning:</strong> Running allotment will clear any existing allotments for the selected pool and create new ones based on current preferences.
                                </div>

                                <div class="d-grid">
                                    <button type="button" class="btn btn-danger btn-lg" onclick="confirmAllotment()">
                                        <i class="fas fa-cogs me-2"></i>Run Allotment
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Right Column - Pool Statistics -->
            <div class="col-lg-6">
                <div class="card pool-card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-chart-bar me-2"></i>Pool Statistics
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($available_pools)): ?>
                            <div class="text-center text-muted">
                                <i class="fas fa-chart-bar fa-3x mb-3"></i>
                                <p>No pool data available</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover" id="poolStatsTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Pool Name</th>
                                            <th>Semester</th>
                                            <th>Registered</th>
                                            <th>Frozen</th>
                                            <th>Allotted</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($available_pools as $pool): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($pool['pool_name']); ?></td>
                                            <td><?php echo htmlspecialchars($pool['semester']); ?></td>
                                            <td>
                                                <span class="badge bg-info"><?php echo $pool['registered_students']; ?></span>
                                            </td>
                                            <td>
                                                <span class="badge bg-success"><?php echo $pool['frozen_students']; ?></span>
                                            </td>
                                            <td>
                                                <span class="badge bg-warning"><?php echo $pool['allotted_students']; ?></span>
                                            </td>
                                            <td>
                                                <?php if ($pool['allotted_students'] > 0): ?>
                                                    <span class="badge bg-success">Completed</span>
                                                <?php elseif ($pool['frozen_students'] > 0): ?>
                                                    <span class="badge bg-warning">Ready</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Pending</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Allotment Results -->
                <?php if (!empty($allotment_results)): ?>
                <div class="card results-card mt-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-list-alt me-2"></i>Allotment Results
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Regno</th>
                                        <th>Subject</th>
                                        <th>Rank</th>
                                        <th>Reason</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($allotment_results as $result): ?>
                                    <tr class="<?php echo $result['subject_code'] == 'NOT_ALLOTTED' ? 'result-failed' : 'result-success'; ?>">
                                        <td><?php echo htmlspecialchars($result['regno']); ?></td>
                                        <td>
                                            <?php if ($result['subject_code'] == 'NOT_ALLOTTED'): ?>
                                                <span class="text-danger">Not Allotted</span>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($result['subject_code']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($result['rank'] > 0): ?>
                                                <span class="badge bg-primary"><?php echo $result['rank']; ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small><?php echo htmlspecialchars($result['reason']); ?></small>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div class="modal fade" id="confirmModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle me-2"></i>Confirm Allotment Execution
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <strong>Warning!</strong> This action will:
                        <ul class="mb-0 mt-2">
                            <li>Clear all existing allotments for the selected pool</li>
                            <li>Generate new allotments based on current preferences</li>
                            <li>Cannot be undone</li>
                        </ul>
                    </div>
                    <p>Are you sure you want to proceed with the allotment execution?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-danger" onclick="executeAllotment()">
                        <i class="fas fa-cogs me-2"></i>Yes, Run Allotment
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#poolStatsTable').DataTable({
                responsive: true,
                pageLength: 10,
                order: [[0, 'asc']]
            });
        });

        // Pool selection handler
        document.getElementById('pool_id').addEventListener('change', function() {
            const selectedOption = this.selectedOptions[0];
            const poolInfo = document.getElementById('poolInfo');
            
            if (this.value) {
                document.getElementById('infoRegistered').textContent = selectedOption.dataset.registered || '0';
                document.getElementById('infoFrozen').textContent = selectedOption.dataset.frozen || '0';
                document.getElementById('infoAllotted').textContent = selectedOption.dataset.allotted || '0';
                poolInfo.classList.remove('d-none');
            } else {
                poolInfo.classList.add('d-none');
            }
        });

        function confirmAllotment() {
            const poolSelect = document.getElementById('pool_id');
            if (!poolSelect.value) {
                alert('Please select a subject pool first.');
                return;
            }
            
            const modal = new bootstrap.Modal(document.getElementById('confirmModal'));
            modal.show();
        }

        function executeAllotment() {
            const modal = bootstrap.Modal.getInstance(document.getElementById('confirmModal'));
            modal.hide();
            
            // Show loading state
            const form = document.getElementById('allotmentForm');
            const submitBtn = form.querySelector('button[type="button"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Running Allotment...';
            submitBtn.disabled = true;
            
            // Submit the form
            form.submit();
        }

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>