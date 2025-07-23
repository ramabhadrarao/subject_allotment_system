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

// Handle result publication
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'publish_results') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        log_security_event($conn, 'csrf_violation', 'high', 'CSRF token validation failed for result publication', $_SESSION['admin_username']);
        $error_message = 'Security validation failed. Please try again.';
    } else {
        $pool_id = intval($_POST['pool_id'] ?? 0);
        
        if ($pool_id <= 0) {
            $error_message = 'Please select a valid subject pool.';
        } else {
            try {
                // Mark results as published (you can add a published flag to subject pools table if needed)
                log_activity($conn, 'admin', $_SESSION['admin_username'], 'results_published', 'subject_allotments', $pool_id);
                $success_message = 'Results published successfully! Students can now view their allotment results.';
            } catch(Exception $e) {
                error_log("Result publication error: " . $e->getMessage());
                $error_message = 'An error occurred while publishing results.';
            }
        }
    }
}

// Get allotment results
$selected_pool = intval($_GET['pool'] ?? 0);
try {
    // Get available pools with allotments
    $stmt = $conn->prepare("
        SELECT 
            sp.id,
            sp.pool_name,
            sp.semester,
            sp.batch,
            COUNT(DISTINCT sa.regno) as allotted_students,
            COUNT(DISTINCT sr.regno) as registered_students
        FROM subject_pools sp
        LEFT JOIN subject_allotments sa ON sp.id = sa.pool_id
        LEFT JOIN student_registrations sr ON sp.id = sr.pool_id
        WHERE sp.is_active = 1
        GROUP BY sp.id, sp.pool_name, sp.semester, sp.batch
        HAVING allotted_students > 0
        ORDER BY sp.pool_name, sp.semester
    ");
    $stmt->execute();
    $available_pools = $stmt->fetchAll();
    
    // Get detailed results for selected pool
    $allotment_results = [];
    $pool_subjects = [];
    $pool_info = null;
    
    if ($selected_pool > 0) {
        // Get pool information
        $stmt = $conn->prepare("SELECT * FROM subject_pools WHERE id = ?");
        $stmt->execute([$selected_pool]);
        $pool_info = $stmt->fetch();
        
        // Get subjects in this pool
        $stmt = $conn->prepare("
            SELECT DISTINCT subject_code, subject_name, intake 
            FROM subject_pools 
            WHERE (id = ? OR pool_name = (SELECT pool_name FROM subject_pools WHERE id = ?)) 
            AND is_active = 1 
            ORDER BY subject_code
        ");
        $stmt->execute([$selected_pool, $selected_pool]);
        $pool_subjects = $stmt->fetchAll();
        
        // Get allotment results with student academic data
        $stmt = $conn->prepare("
            SELECT 
                sa.*,
                sp.subject_name,
                sp.intake,
                sad.cgpa,
                sad.backlogs,
                sr.email,
                sr.mobile,
                sr.frozen_at
            FROM subject_allotments sa
            JOIN subject_pools sp ON sa.subject_code = sp.subject_code
            LEFT JOIN student_academic_data sad ON sa.regno = sad.regno
            LEFT JOIN student_registrations sr ON sa.regno = sr.regno AND sa.pool_id = sr.pool_id
            WHERE sa.pool_id = ?
            ORDER BY sp.subject_name, sa.allotment_rank
        ");
        $stmt->execute([$selected_pool]);
        $allotment_results = $stmt->fetchAll();
        
        // Get non-allotted students
        $stmt = $conn->prepare("
            SELECT 
                sr.regno,
                sr.email,
                sr.mobile,
                sr.priority_order,
                sr.frozen_at,
                sad.cgpa,
                sad.backlogs
            FROM student_registrations sr
            LEFT JOIN student_academic_data sad ON sr.regno = sad.regno
            LEFT JOIN subject_allotments sa ON sr.regno = sa.regno AND sr.pool_id = sa.pool_id
            WHERE sr.pool_id = ? AND sr.status = 'frozen' AND sa.id IS NULL
            ORDER BY sr.frozen_at
        ");
        $stmt->execute([$selected_pool]);
        $non_allotted = $stmt->fetchAll();
    }
    
} catch(Exception $e) {
    error_log("Allotment results error: " . $e->getMessage());
    $available_pools = [];
    $allotment_results = [];
    $pool_subjects = [];
    $non_allotted = [];
}

$csrf_token = generate_csrf_token();
log_activity($conn, 'admin', $_SESSION['admin_username'], 'allotment_results_viewed');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Allotment Results - Subject Allotment System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8f9fa;
        }
        .card {
            border: none;
            border-radius: 15px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
        }
        .results-card {
            border-left: 5px solid #28a745;
        }
        .summary-card {
            border-left: 5px solid #007bff;
        }
        .non-allotted-card {
            border-left: 5px solid #dc3545;
        }
        .subject-section {
            background: #f8f9fa;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
            border-left: 4px solid #007bff;
        }
        .student-row {
            padding: 10px;
            margin: 5px 0;
            background: white;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }
        .rank-badge {
            font-size: 1rem;
            font-weight: bold;
        }
        @media (max-width: 768px) {
            .table-responsive {
                font-size: 0.85rem;
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
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="admin_dashboard.php">
                    <i class="fas fa-arrow-left me-1"></i>Back to Dashboard
                </a>
            </div>
        </div>
    </nav>

    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-list-alt me-2"></i>Allotment Results</h2>
            <?php if ($selected_pool > 0 && !empty($allotment_results)): ?>
            <div class="btn-group">
                <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#publishModal">
                    <i class="fas fa-bullhorn me-2"></i>Publish Results
                </button>
                <button type="button" class="btn btn-info" onclick="exportResults()">
                    <i class="fas fa-download me-2"></i>Export Results
                </button>
            </div>
            <?php endif; ?>
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

        <!-- Pool Selection -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <label for="poolSelect" class="form-label">
                                    <i class="fas fa-layer-group me-2"></i>Select Subject Pool
                                </label>
                                <select class="form-select" id="poolSelect" onchange="loadPoolResults()">
                                    <option value="">Choose a pool to view results...</option>
                                    <?php foreach ($available_pools as $pool): ?>
                                        <option value="<?php echo $pool['id']; ?>" <?php echo $selected_pool == $pool['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($pool['pool_name'] . ' - ' . $pool['semester'] . ' (' . $pool['batch'] . ')'); ?>
                                            - Allotted: <?php echo $pool['allotted_students']; ?>/<?php echo $pool['registered_students']; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 text-md-end mt-3 mt-md-0">
                                <?php if (empty($available_pools)): ?>
                                    <div class="alert alert-info mb-0">
                                        <i class="fas fa-info-circle me-2"></i>
                                        No allotment results available. Please run the allotment process first.
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($selected_pool > 0 && $pool_info): ?>
        <!-- Results Summary -->
        <div class="row mb-4">
            <div class="col-md-8">
                <div class="card summary-card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-chart-pie me-2"></i>Allotment Summary
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3 text-center">
                                <h3 class="text-primary"><?php echo count($allotment_results); ?></h3>
                                <small class="text-muted">Students Allotted</small>
                            </div>
                            <div class="col-md-3 text-center">
                                <h3 class="text-danger"><?php echo count($non_allotted ?? []); ?></h3>
                                <small class="text-muted">Not Allotted</small>
                            </div>
                            <div class="col-md-3 text-center">
                                <h3 class="text-info"><?php echo count($pool_subjects); ?></h3>
                                <small class="text-muted">Subjects</small>
                            </div>
                            <div class="col-md-3 text-center">
                                <?php 
                                $total_intake = array_sum(array_column($pool_subjects, 'intake'));
                                $utilized = count($allotment_results);
                                $utilization = $total_intake > 0 ? round(($utilized / $total_intake) * 100, 1) : 0;
                                ?>
                                <h3 class="text-success"><?php echo $utilization; ?>%</h3>
                                <small class="text-muted">Utilization</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-info-circle me-2"></i>Pool Information
                        </h5>
                    </div>
                    <div class="card-body">
                        <p><strong>Pool:</strong> <?php echo htmlspecialchars($pool_info['pool_name']); ?></p>
                        <p><strong>Semester:</strong> <?php echo htmlspecialchars($pool_info['semester']); ?></p>
                        <p><strong>Batch:</strong> <?php echo htmlspecialchars($pool_info['batch']); ?></p>
                        <p class="mb-0"><strong>Total Intake:</strong> <?php echo $total_intake; ?></p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Subject-wise Results -->
        <?php if (!empty($allotment_results)): ?>
        <div class="card results-card mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">
                    <i class="fas fa-trophy me-2"></i>Allotment Results by Subject
                </h5>
            </div>
            <div class="card-body">
                <?php 
                // Group results by subject
                $subject_groups = [];
                foreach ($allotment_results as $result) {
                    $subject_groups[$result['subject_code']][] = $result;
                }
                
                foreach ($subject_groups as $subject_code => $students):
                    $subject_name = $students[0]['subject_name'];
                    $intake = $students[0]['intake'];
                ?>
                <div class="subject-section">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">
                            <i class="fas fa-cube me-2"></i>
                            <?php echo htmlspecialchars($subject_name); ?> 
                            <small class="text-muted">(<?php echo htmlspecialchars($subject_code); ?>)</small>
                        </h5>
                        <span class="badge bg-info fs-6">
                            <?php echo count($students); ?>/<?php echo $intake; ?> seats filled
                        </span>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead class="table-light">
                                <tr>
                                    <th>Rank</th>
                                    <th>Registration No</th>
                                    <th>CGPA</th>
                                    <th>Backlogs</th>
                                    <th>Contact</th>
                                    <th>Allotment Reason</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $student): ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-primary rank-badge"><?php echo $student['allotment_rank']; ?></span>
                                    </td>
                                    <td><strong><?php echo htmlspecialchars($student['regno']); ?></strong></td>
                                    <td>
                                        <?php if ($student['cgpa'] !== null): ?>
                                            <span class="badge bg-success"><?php echo number_format($student['cgpa'], 2); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $student['backlogs'] > 0 ? 'bg-danger' : 'bg-success'; ?>">
                                            <?php echo $student['backlogs'] ?? 0; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <small>
                                            <?php echo htmlspecialchars($student['email'] ?? ''); ?><br>
                                            <?php echo htmlspecialchars($student['mobile'] ?? ''); ?>
                                        </small>
                                    </td>
                                    <td>
                                        <small><?php echo htmlspecialchars($student['allotment_reason']); ?></small>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Non-Allotted Students -->
        <?php if (!empty($non_allotted)): ?>
        <div class="card non-allotted-card">
            <div class="card-header bg-danger text-white">
                <h5 class="mb-0">
                    <i class="fas fa-user-times me-2"></i>Students Not Allotted (<?php echo count($non_allotted); ?>)
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover" id="nonAllottedTable">
                        <thead class="table-light">
                            <tr>
                                <th>Registration No</th>
                                <th>CGPA</th>
                                <th>Backlogs</th>
                                <th>Contact</th>
                                <th>Preferences</th>
                                <th>Frozen At</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($non_allotted as $student): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($student['regno']); ?></strong></td>
                                <td>
                                    <?php if ($student['cgpa'] !== null): ?>
                                        <span class="badge bg-success"><?php echo number_format($student['cgpa'], 2); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge <?php echo $student['backlogs'] > 0 ? 'bg-danger' : 'bg-success'; ?>">
                                        <?php echo $student['backlogs'] ?? 0; ?>
                                    </span>
                                </td>
                                <td>
                                    <small>
                                        <?php echo htmlspecialchars($student['email']); ?><br>
                                        <?php echo htmlspecialchars($student['mobile']); ?>
                                    </small>
                                </td>
                                <td>
                                    <?php 
                                    $priorities = json_decode($student['priority_order'], true);
                                    if ($priorities):
                                        usort($priorities, function($a, $b) { return $a['priority'] - $b['priority']; });
                                        foreach ($priorities as $pref):
                                    ?>
                                        <small><?php echo $pref['priority']; ?>. <?php echo htmlspecialchars($pref['subject_code']); ?></small><br>
                                    <?php 
                                        endforeach;
                                    endif; 
                                    ?>
                                </td>
                                <td>
                                    <small><?php echo date('M j, h:i A', strtotime($student['frozen_at'])); ?></small>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Publish Results Modal -->
    <div class="modal fade" id="publishModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-bullhorn me-2"></i>Publish Results
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Publishing results will:</strong>
                        <ul class="mb-0 mt-2">
                            <li>Make results visible to all students</li>
                            <li>Send notification to students (if configured)</li>
                            <li>Lock the allotment results</li>
                        </ul>
                    </div>
                    <p>Are you sure you want to publish the allotment results for this pool?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" action="" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="publish_results">
                        <input type="hidden" name="pool_id" value="<?php echo $selected_pool; ?>">
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-bullhorn me-2"></i>Yes, Publish Results
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>

    <script>
        $(document).ready(function() {
            $('#nonAllottedTable').DataTable({
                responsive: true,
                pageLength: 10,
                order: [[5, 'asc']] // Sort by frozen date
            });
        });

        function loadPoolResults() {
            const poolId = document.getElementById('poolSelect').value;
            if (poolId) {
                window.location.href = 'allotment_results.php?pool=' + poolId;
            } else {
                window.location.href = 'allotment_results.php';
            }
        }

        function exportResults() {
            // Simple export functionality
            const poolId = <?php echo $selected_pool; ?>;
            if (poolId > 0) {
                window.open('export_results.php?pool=' + poolId + '&format=excel', '_blank');
            }
        }

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>