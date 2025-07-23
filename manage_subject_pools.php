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

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        log_security_event($conn, 'csrf_violation', 'high', 'CSRF token validation failed for subject pool management', $_SESSION['admin_username']);
        $error_message = 'Security validation failed. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        // Add new subject pool
        if ($action == 'add_pool' && prevent_resubmit($conn, 'admin', $_SESSION['admin_username'], 'add_subject_pool')) {
            $pool_name = trim($_POST['pool_name'] ?? '');
            $subject_code = strtoupper(trim($_POST['subject_code'] ?? ''));
            $subject_name = trim($_POST['subject_name'] ?? '');
            $intake = intval($_POST['intake'] ?? 0);
            $allowed_programmes = $_POST['allowed_programmes'] ?? [];
            $batch = trim($_POST['batch'] ?? '');
            $semester = trim($_POST['semester'] ?? '');

            if (empty($pool_name) || empty($subject_code) || empty($subject_name) || $intake <= 0 || empty($allowed_programmes) || empty($batch) || empty($semester)) {
                $error_message = 'Please fill all required fields.';
            } else {
                try {
                    // Check if subject code already exists
                    $stmt = $conn->prepare("SELECT id FROM subject_pools WHERE subject_code = ? AND is_active = 1");
                    $stmt->execute([$subject_code]);
                    
                    if ($stmt->rowCount() > 0) {
                        $error_message = 'Subject code already exists. Please use a different code.';
                    } else {
                        $stmt = $conn->prepare("INSERT INTO subject_pools (pool_name, subject_code, subject_name, intake, allowed_programmes, batch, semester, created_by, created_ip) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $stmt->execute([
                            $pool_name,
                            $subject_code,
                            $subject_name,
                            $intake,
                            json_encode($allowed_programmes),
                            $batch,
                            $semester,
                            $_SESSION['admin_id'],
                            get_client_ip()
                        ]);

                        log_activity($conn, 'admin', $_SESSION['admin_username'], 'subject_pool_created', 'subject_pools', $conn->lastInsertId());
                        $success_message = 'Subject pool created successfully!';
                    }
                } catch(Exception $e) {
                    error_log("Add subject pool error: " . $e->getMessage());
                    $error_message = 'An error occurred while creating the subject pool.';
                }
            }
        }
        
        // Edit subject pool
        elseif ($action == 'edit_pool' && prevent_resubmit($conn, 'admin', $_SESSION['admin_username'], 'edit_subject_pool')) {
            $pool_id = intval($_POST['pool_id'] ?? 0);
            $pool_name = trim($_POST['pool_name'] ?? '');
            $subject_code = strtoupper(trim($_POST['subject_code'] ?? ''));
            $subject_name = trim($_POST['subject_name'] ?? '');
            $intake = intval($_POST['intake'] ?? 0);
            $allowed_programmes = $_POST['allowed_programmes'] ?? [];
            $batch = trim($_POST['batch'] ?? '');
            $semester = trim($_POST['semester'] ?? '');

            if ($pool_id <= 0 || empty($pool_name) || empty($subject_code) || empty($subject_name) || $intake <= 0 || empty($allowed_programmes) || empty($batch) || empty($semester)) {
                $error_message = 'Please fill all required fields.';
            } else {
                try {
                    // Get old values for logging
                    $stmt = $conn->prepare("SELECT * FROM subject_pools WHERE id = ?");
                    $stmt->execute([$pool_id]);
                    $old_values = $stmt->fetch();

                    // Check if subject code already exists for other pools
                    $stmt = $conn->prepare("SELECT id FROM subject_pools WHERE subject_code = ? AND id != ? AND is_active = 1");
                    $stmt->execute([$subject_code, $pool_id]);
                    
                    if ($stmt->rowCount() > 0) {
                        $error_message = 'Subject code already exists for another pool. Please use a different code.';
                    } else {
                        $stmt = $conn->prepare("UPDATE subject_pools SET pool_name = ?, subject_code = ?, subject_name = ?, intake = ?, allowed_programmes = ?, batch = ?, semester = ?, updated_ip = ?, updated_at = NOW() WHERE id = ?");
                        $stmt->execute([
                            $pool_name,
                            $subject_code,
                            $subject_name,
                            $intake,
                            json_encode($allowed_programmes),
                            $batch,
                            $semester,
                            get_client_ip(),
                            $pool_id
                        ]);

                        $new_values = [
                            'pool_name' => $pool_name,
                            'subject_code' => $subject_code,
                            'subject_name' => $subject_name,
                            'intake' => $intake,
                            'allowed_programmes' => $allowed_programmes,
                            'batch' => $batch,
                            'semester' => $semester
                        ];

                        log_activity($conn, 'admin', $_SESSION['admin_username'], 'subject_pool_updated', 'subject_pools', $pool_id, $old_values, $new_values);
                        $success_message = 'Subject pool updated successfully!';
                    }
                } catch(Exception $e) {
                    error_log("Edit subject pool error: " . $e->getMessage());
                    $error_message = 'An error occurred while updating the subject pool.';
                }
            }
        }
        
        // Delete subject pool
        elseif ($action == 'delete_pool' && prevent_resubmit($conn, 'admin', $_SESSION['admin_username'], 'delete_subject_pool')) {
            $pool_id = intval($_POST['pool_id'] ?? 0);
            
            if ($pool_id <= 0) {
                $error_message = 'Invalid pool ID.';
            } else {
                try {
                    // Check if pool has registrations
                    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM student_registrations WHERE pool_id = ?");
                    $stmt->execute([$pool_id]);
                    $registration_count = $stmt->fetch()['count'];

                    if ($registration_count > 0) {
                        $error_message = 'Cannot delete pool with existing student registrations. Please remove registrations first.';
                    } else {
                        // Get old values for logging
                        $stmt = $conn->prepare("SELECT * FROM subject_pools WHERE id = ?");
                        $stmt->execute([$pool_id]);
                        $old_values = $stmt->fetch();

                        // Soft delete
                        $stmt = $conn->prepare("UPDATE subject_pools SET is_active = 0, updated_ip = ?, updated_at = NOW() WHERE id = ?");
                        $stmt->execute([get_client_ip(), $pool_id]);

                        log_activity($conn, 'admin', $_SESSION['admin_username'], 'subject_pool_deleted', 'subject_pools', $pool_id, $old_values);
                        $success_message = 'Subject pool deleted successfully!';
                    }
                } catch(Exception $e) {
                    error_log("Delete subject pool error: " . $e->getMessage());
                    $error_message = 'An error occurred while deleting the subject pool.';
                }
            }
        }
    }
}

// Get all subject pools
try {
    $stmt = $conn->prepare("
        SELECT 
            sp.*,
            COUNT(sr.id) as registration_count,
            COUNT(sa.id) as allotment_count
        FROM subject_pools sp
        LEFT JOIN student_registrations sr ON sp.id = sr.pool_id
        LEFT JOIN subject_allotments sa ON sp.id = sa.pool_id
        WHERE sp.is_active = 1
        GROUP BY sp.id
        ORDER BY sp.pool_name, sp.subject_name
    ");
    $stmt->execute();
    $subject_pools = $stmt->fetchAll();
} catch(Exception $e) {
    $subject_pools = [];
}

// Get available programmes from main database or hardcoded list
$available_programmes = [];
try {
    if ($attendance_conn) {
        $stmt = $attendance_conn->prepare("SELECT DISTINCT programme FROM user ORDER BY programme");
        $stmt->execute();
        $programmes = $stmt->fetchAll();
        foreach ($programmes as $prog) {
            if (!empty($prog['programme'])) {
                $available_programmes[] = $prog['programme'];
            }
        }
    }
} catch(Exception $e) {
    // Fallback to hardcoded list
    $available_programmes = ['MCA', 'MBA', 'B.Tech - AIML', 'B.Tech - CSEDS', 'B.Tech - CSEAIDS', 'B.Tech - CSECS', 'B.Tech - CSEBS', 'B.Tech - Civil', 'B.Tech - EEE', 'B.Tech - Mech', 'B.Tech - ECE', 'B.Tech - CSE', 'B.Tech - IT', 'B.Tech - Robotics', 'BCA(H)', 'BBA(H)'];
}

$available_semesters = ['First Semester', 'Second Semester', 'Third Semester', 'Fourth Semester', 'Fifth Semester', 'Sixth Semester', 'Seventh Semester', 'Eight Semester'];

$csrf_token = generate_csrf_token();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Subject Pools - Subject Allotment System</title>
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
        .add-card {
            border-left: 5px solid #28a745;
        }
        .list-card {
            border-left: 5px solid #007bff;
        }
        .programme-badge {
            margin: 2px;
            font-size: 0.75rem;
        }
        .action-buttons .btn {
            margin: 2px;
        }
        @media (max-width: 768px) {
            .action-buttons {
                display: flex;
                flex-direction: column;
            }
            .action-buttons .btn {
                margin: 1px 0;
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
            <h2><i class="fas fa-layer-group me-2"></i>Manage Subject Pools</h2>
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addPoolModal">
                <i class="fas fa-plus me-2"></i>Add New Pool
            </button>
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

        <!-- Subject Pools List -->
        <div class="card list-card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Subject Pools (<?php echo count($subject_pools); ?>)
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($subject_pools)): ?>
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-layer-group fa-4x mb-3"></i>
                        <h5>No Subject Pools Found</h5>
                        <p>Create your first subject pool to get started.</p>
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addPoolModal">
                            <i class="fas fa-plus me-2"></i>Add New Pool
                        </button>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover" id="poolsTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Pool Name</th>
                                    <th>Subject</th>
                                    <th>Code</th>
                                    <th>Intake</th>
                                    <th>Programmes</th>
                                    <th>Semester</th>
                                    <th>Batch</th>
                                    <th>Registrations</th>
                                    <th>Allotments</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($subject_pools as $pool): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($pool['pool_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($pool['subject_name']); ?></td>
                                    <td>
                                        <span class="badge bg-info"><?php echo htmlspecialchars($pool['subject_code']); ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary"><?php echo $pool['intake']; ?></span>
                                    </td>
                                    <td>
                                        <?php 
                                        $programmes = json_decode($pool['allowed_programmes'], true);
                                        if ($programmes):
                                            foreach ($programmes as $prog):
                                        ?>
                                            <span class="badge bg-secondary programme-badge"><?php echo htmlspecialchars($prog); ?></span>
                                        <?php 
                                            endforeach;
                                        endif; 
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($pool['semester']); ?></td>
                                    <td><?php echo htmlspecialchars($pool['batch']); ?></td>
                                    <td>
                                        <span class="badge bg-warning"><?php echo $pool['registration_count']; ?></span>
                                    </td>
                                    <td>
                                        <span class="badge bg-success"><?php echo $pool['allotment_count']; ?></span>
                                    </td>
                                    <td>
                                        <div class="action-buttons">
                                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="editPool(<?php echo htmlspecialchars(json_encode($pool)); ?>)">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="deletePool(<?php echo $pool['id']; ?>, '<?php echo htmlspecialchars($pool['subject_name']); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Add Pool Modal -->
    <div class="modal fade" id="addPoolModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-plus me-2"></i>Add New Subject Pool
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" id="addPoolForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="form_token" value="<?php echo generate_token(); ?>">
                    <input type="hidden" name="action" value="add_pool">
                    
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="add_pool_name" class="form-label">Pool Name</label>
                                    <input type="text" class="form-control" id="add_pool_name" name="pool_name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="add_subject_code" class="form-label">Subject Code</label>
                                    <input type="text" class="form-control" id="add_subject_code" name="subject_code" required style="text-transform: uppercase;">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="add_subject_name" class="form-label">Subject Name</label>
                            <input type="text" class="form-control" id="add_subject_name" name="subject_name" required>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="add_intake" class="form-label">Intake</label>
                                    <input type="number" class="form-control" id="add_intake" name="intake" min="1" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="add_semester" class="form-label">Semester</label>
                                    <select class="form-select" id="add_semester" name="semester" required>
                                        <option value="">Select Semester</option>
                                        <?php foreach ($available_semesters as $sem): ?>
                                            <option value="<?php echo $sem; ?>"><?php echo $sem; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="add_batch" class="form-label">Batch</label>
                                    <input type="text" class="form-control" id="add_batch" name="batch" placeholder="e.g., 2024-2026" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Allowed Programmes</label>
                            <div class="row">
                                <?php foreach ($available_programmes as $prog): ?>
                                <div class="col-md-4 col-sm-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="allowed_programmes[]" value="<?php echo $prog; ?>" id="add_prog_<?php echo str_replace([' ', '-', '(', ')'], '_', $prog); ?>">
                                        <label class="form-check-label" for="add_prog_<?php echo str_replace([' ', '-', '(', ')'], '_', $prog); ?>">
                                            <?php echo $prog; ?>
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save me-2"></i>Create Pool
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Pool Modal -->
    <div class="modal fade" id="editPoolModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Edit Subject Pool
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" id="editPoolForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="form_token" value="<?php echo generate_token(); ?>">
                    <input type="hidden" name="action" value="edit_pool">
                    <input type="hidden" name="pool_id" id="edit_pool_id">
                    
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_pool_name" class="form-label">Pool Name</label>
                                    <input type="text" class="form-control" id="edit_pool_name" name="pool_name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="edit_subject_code" class="form-label">Subject Code</label>
                                    <input type="text" class="form-control" id="edit_subject_code" name="subject_code" required style="text-transform: uppercase;">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_subject_name" class="form-label">Subject Name</label>
                            <input type="text" class="form-control" id="edit_subject_name" name="subject_name" required>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="edit_intake" class="form-label">Intake</label>
                                    <input type="number" class="form-control" id="edit_intake" name="intake" min="1" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="edit_semester" class="form-label">Semester</label>
                                    <select class="form-select" id="edit_semester" name="semester" required>
                                        <option value="">Select Semester</option>
                                        <?php foreach ($available_semesters as $sem): ?>
                                            <option value="<?php echo $sem; ?>"><?php echo $sem; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="edit_batch" class="form-label">Batch</label>
                                    <input type="text" class="form-control" id="edit_batch" name="batch" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Allowed Programmes</label>
                            <div class="row" id="edit_programmes_container">
                                <?php foreach ($available_programmes as $prog): ?>
                                <div class="col-md-4 col-sm-6">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="allowed_programmes[]" value="<?php echo $prog; ?>" id="edit_prog_<?php echo str_replace([' ', '-', '(', ')'], '_', $prog); ?>">
                                        <label class="form-check-label" for="edit_prog_<?php echo str_replace([' ', '-', '(', ')'], '_', $prog); ?>">
                                            <?php echo $prog; ?>
                                        </label>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Update Pool
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div class="modal fade" id="deleteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle me-2"></i>Confirm Delete
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger">
                        <strong>Warning!</strong> This action cannot be undone.
                    </div>
                    <p>Are you sure you want to delete the subject pool "<span id="deletePoolName"></span>"?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" action="" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="form_token" value="<?php echo generate_token(); ?>">
                        <input type="hidden" name="action" value="delete_pool">
                        <input type="hidden" name="pool_id" id="deletePoolId">
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-trash me-2"></i>Yes, Delete
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
    <script>
        $(document).ready(function() {
            $('#poolsTable').DataTable({
                responsive: true,
                pageLength: 10,
                order: [[0, 'asc']],
                columnDefs: [
                    { targets: [-1], orderable: false } // Disable sorting for Actions column
                ]
            });
        });

        // Auto uppercase subject codes
        document.getElementById('add_subject_code').addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });
        document.getElementById('edit_subject_code').addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });

        function editPool(pool) {
            document.getElementById('edit_pool_id').value = pool.id;
            document.getElementById('edit_pool_name').value = pool.pool_name;
            document.getElementById('edit_subject_code').value = pool.subject_code;
            document.getElementById('edit_subject_name').value = pool.subject_name;
            document.getElementById('edit_intake').value = pool.intake;
            document.getElementById('edit_semester').value = pool.semester;
            document.getElementById('edit_batch').value = pool.batch;
            
            // Clear all programme checkboxes first
            const editCheckboxes = document.querySelectorAll('#edit_programmes_container input[type="checkbox"]');
            editCheckboxes.forEach(cb => cb.checked = false);
            
            // Set selected programmes
            const allowedProgrammes = JSON.parse(pool.allowed_programmes || '[]');
            allowedProgrammes.forEach(prog => {
                const checkbox = document.getElementById('edit_prog_' + prog.replace(/[ \-\(\)]/g, '_'));
                if (checkbox) {
                    checkbox.checked = true;
                }
            });
            
            const modal = new bootstrap.Modal(document.getElementById('editPoolModal'));
            modal.show();
        }

        function deletePool(poolId, poolName) {
            document.getElementById('deletePoolId').value = poolId;
            document.getElementById('deletePoolName').textContent = poolName;
            
            const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
            modal.show();
        }

        // Form submission handlers
        document.getElementById('addPoolForm').addEventListener('submit', function() {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Creating...';
            submitBtn.disabled = true;
        });

        document.getElementById('editPoolForm').addEventListener('submit', function() {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Updating...';
            submitBtn.disabled = true;
        });

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>