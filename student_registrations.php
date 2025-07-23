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

// Handle actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        log_security_event($conn, 'csrf_violation', 'high', 'CSRF token validation failed for student registrations', $_SESSION['admin_username']);
        $error_message = 'Security validation failed. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';
        
        // Delete registration
        if ($action == 'delete_registration' && prevent_resubmit($conn, 'admin', $_SESSION['admin_username'], 'delete_registration')) {
            $registration_id = intval($_POST['registration_id'] ?? 0);
            
            if ($registration_id > 0) {
                try {
                    // Get registration details for logging
                    $stmt = $conn->prepare("SELECT * FROM student_registrations WHERE id = ?");
                    $stmt->execute([$registration_id]);
                    $registration = $stmt->fetch();
                    
                    if ($registration) {
                        // Check if student has allotment
                        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM subject_allotments WHERE regno = ? AND pool_id = ?");
                        $stmt->execute([$registration['regno'], $registration['pool_id']]);
                        $has_allotment = $stmt->fetch()['count'] > 0;
                        
                        if ($has_allotment) {
                            $error_message = 'Cannot delete registration. Student has been allotted a subject.';
                        } else {
                            // Delete registration
                            $stmt = $conn->prepare("DELETE FROM student_registrations WHERE id = ?");
                            $stmt->execute([$registration_id]);
                            
                            log_activity($conn, 'admin', $_SESSION['admin_username'], 'registration_deleted', 'student_registrations', $registration_id, $registration);
                            $success_message = "Registration for {$registration['regno']} deleted successfully.";
                        }
                    } else {
                        $error_message = 'Registration not found.';
                    }
                } catch(Exception $e) {
                    error_log("Delete registration error: " . $e->getMessage());
                    $error_message = 'An error occurred while deleting the registration.';
                }
            }
        }
        
        // Bulk actions
        elseif ($action == 'bulk_action' && prevent_resubmit($conn, 'admin', $_SESSION['admin_username'], 'bulk_registration_action')) {
            $bulk_action = $_POST['bulk_action'] ?? '';
            $selected_ids = $_POST['selected_ids'] ?? [];
            
            if (!empty($selected_ids) && !empty($bulk_action)) {
                try {
                    $processed = 0;
                    
                    foreach ($selected_ids as $id) {
                        $id = intval($id);
                        if ($id <= 0) continue;
                        
                        if ($bulk_action == 'delete') {
                            // Check for allotments
                            $stmt = $conn->prepare("SELECT regno, pool_id FROM student_registrations WHERE id = ?");
                            $stmt->execute([$id]);
                            $reg = $stmt->fetch();
                            
                            if ($reg) {
                                $stmt = $conn->prepare("SELECT COUNT(*) as count FROM subject_allotments WHERE regno = ? AND pool_id = ?");
                                $stmt->execute([$reg['regno'], $reg['pool_id']]);
                                $has_allotment = $stmt->fetch()['count'] > 0;
                                
                                if (!$has_allotment) {
                                    $stmt = $conn->prepare("DELETE FROM student_registrations WHERE id = ?");
                                    $stmt->execute([$id]);
                                    $processed++;
                                }
                            }
                        }
                    }
                    
                    log_activity($conn, 'admin', $_SESSION['admin_username'], 'bulk_registration_action', 'student_registrations', null, null, ['action' => $bulk_action, 'processed' => $processed]);
                    $success_message = "$processed registrations processed successfully.";
                    
                } catch(Exception $e) {
                    error_log("Bulk action error: " . $e->getMessage());
                    $error_message = 'An error occurred during bulk action.';
                }
            }
        }
    }
}

// Get filter parameters
$pool_filter = intval($_GET['pool'] ?? 0);
$status_filter = $_GET['status'] ?? '';
$search_query = trim($_GET['search'] ?? '');

// Build WHERE clause
$where_conditions = ['1=1'];
$params = [];

if ($pool_filter > 0) {
    $where_conditions[] = 'sr.pool_id = ?';
    $params[] = $pool_filter;
}

if (!empty($status_filter)) {
    $where_conditions[] = 'sr.status = ?';
    $params[] = $status_filter;
}

if (!empty($search_query)) {
    $where_conditions[] = '(sr.regno LIKE ? OR sr.email LIKE ? OR sr.mobile LIKE ?)';
    $search_param = "%$search_query%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
}

$where_clause = implode(' AND ', $where_conditions);

try {
    // Get student registrations with related data
    $stmt = $conn->prepare("
        SELECT 
            sr.*,
            sp.pool_name,
            sp.subject_name as pool_subject,
            sp.semester,
            sp.batch,
            sad.cgpa,
            sad.backlogs,
            sa.subject_code as allotted_subject,
            sa.allotment_reason,
            sa.allotted_at
        FROM student_registrations sr
        JOIN subject_pools sp ON sr.pool_id = sp.id
        LEFT JOIN student_academic_data sad ON sr.regno = sad.regno
        LEFT JOIN subject_allotments sa ON sr.regno = sa.regno AND sr.pool_id = sa.pool_id
        WHERE $where_clause
        ORDER BY sr.registered_at DESC
    ");
    $stmt->execute($params);
    $registrations = $stmt->fetchAll();
    
    // Get available pools for filter
    $stmt = $conn->prepare("
        SELECT DISTINCT sp.id, sp.pool_name, sp.semester, sp.batch,
               COUNT(sr.id) as registration_count
        FROM subject_pools sp
        LEFT JOIN student_registrations sr ON sp.id = sr.pool_id
        WHERE sp.is_active = 1
        GROUP BY sp.id, sp.pool_name, sp.semester, sp.batch
        ORDER BY sp.pool_name, sp.semester
    ");
    $stmt->execute();
    $available_pools = $stmt->fetchAll();
    
    // Get statistics
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM student_registrations sr WHERE $where_clause");
    $stmt->execute($params);
    $total_registrations = $stmt->fetch()['total'];
    
    $stmt = $conn->prepare("SELECT COUNT(*) as frozen FROM student_registrations sr WHERE sr.status = 'frozen' AND $where_clause");
    $stmt->execute($params);
    $frozen_registrations = $stmt->fetch()['frozen'];
    
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT sr.regno) as allotted 
        FROM student_registrations sr 
        JOIN subject_allotments sa ON sr.regno = sa.regno AND sr.pool_id = sa.pool_id 
        WHERE $where_clause
    ");
    $stmt->execute($params);
    $allotted_students = $stmt->fetch()['allotted'];

} catch(Exception $e) {
    error_log("Student registrations query error: " . $e->getMessage());
    $registrations = [];
    $available_pools = [];
    $total_registrations = $frozen_registrations = $allotted_students = 0;
}

$csrf_token = generate_csrf_token();
log_activity($conn, 'admin', $_SESSION['admin_username'], 'registrations_viewed');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Registrations - Subject Allotment System</title>
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
        .stats-card {
            border-left: 5px solid #007bff;
        }
        .registrations-card {
            border-left: 5px solid #28a745;
        }
        .stat-item {
            text-align: center;
            padding: 1rem;
        }
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }
        .status-badge {
            font-size: 0.75rem;
        }
        .priority-list {
            font-size: 0.85rem;
        }
        .priority-item {
            background: #f8f9fa;
            padding: 2px 6px;
            margin: 1px;
            border-radius: 3px;
            display: inline-block;
        }
        .allotted-subject {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            color: white;
            padding: 8px 12px;
            border-radius: 8px;
            font-weight: bold;
        }
        .filter-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
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
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-users me-2"></i>Student Registrations</h2>
            <div class="btn-group">
                <button type="button" class="btn btn-primary" onclick="exportRegistrations()">
                    <i class="fas fa-download me-2"></i>Export
                </button>
                <button type="button" class="btn btn-info" onclick="refreshData()">
                    <i class="fas fa-sync me-2"></i>Refresh
                </button>
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

        <!-- Statistics Row -->
        <div class="row mb-4">
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card stats-card h-100">
                    <div class="card-body stat-item">
                        <div class="stat-number text-primary"><?php echo $total_registrations; ?></div>
                        <div class="text-muted">Total Registrations</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card stats-card h-100">
                    <div class="card-body stat-item">
                        <div class="stat-number text-success"><?php echo $frozen_registrations; ?></div>
                        <div class="text-muted">Frozen Preferences</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card stats-card h-100">
                    <div class="card-body stat-item">
                        <div class="stat-number text-warning"><?php echo $allotted_students; ?></div>
                        <div class="text-muted">Students Allotted</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card stats-card h-100">
                    <div class="card-body stat-item">
                        <?php 
                        $pending = $frozen_registrations - $allotted_students;
                        $pending = max(0, $pending);
                        ?>
                        <div class="stat-number text-info"><?php echo $pending; ?></div>
                        <div class="text-muted">Pending Allotment</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card filter-card mb-4">
            <div class="card-body">
                <form method="GET" action="" class="row g-3">
                    <div class="col-md-3">
                        <label for="pool" class="form-label">
                            <i class="fas fa-layer-group me-1"></i>Subject Pool
                        </label>
                        <select class="form-select" id="pool" name="pool">
                            <option value="">All Pools</option>
                            <?php foreach ($available_pools as $pool): ?>
                                <option value="<?php echo $pool['id']; ?>" <?php echo $pool_filter == $pool['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pool['pool_name'] . ' - ' . $pool['semester']); ?>
                                    (<?php echo $pool['registration_count']; ?> registrations)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="status" class="form-label">
                            <i class="fas fa-flag me-1"></i>Status
                        </label>
                        <select class="form-select" id="status" name="status">
                            <option value="">All Status</option>
                            <option value="saved" <?php echo $status_filter == 'saved' ? 'selected' : ''; ?>>Saved</option>
                            <option value="frozen" <?php echo $status_filter == 'frozen' ? 'selected' : ''; ?>>Frozen</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="search" class="form-label">
                            <i class="fas fa-search me-1"></i>Search
                        </label>
                        <input type="text" class="form-control" id="search" name="search" 
                               placeholder="Registration No, Email, or Mobile" 
                               value="<?php echo htmlspecialchars($search_query); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">&nbsp;</label>
                        <div class="d-grid">
                            <button type="submit" class="btn btn-light">
                                <i class="fas fa-filter me-2"></i>Apply Filters
                            </button>
                        </div>
                    </div>
                </form>
                
                <?php if ($pool_filter || $status_filter || $search_query): ?>
                <div class="mt-3">
                    <a href="student_registrations.php" class="btn btn-outline-light btn-sm">
                        <i class="fas fa-times me-1"></i>Clear Filters
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Registrations Table -->
        <div class="card registrations-card">
            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Student Registrations
                    <?php if ($total_registrations > 0): ?>
                        <span class="badge bg-light text-dark"><?php echo $total_registrations; ?></span>
                    <?php endif; ?>
                </h5>
                <div class="btn-group">
                    <button type="button" class="btn btn-outline-light btn-sm" onclick="selectAll()">
                        <i class="fas fa-check-square me-1"></i>Select All
                    </button>
                    <button type="button" class="btn btn-outline-light btn-sm" onclick="clearSelection()">
                        <i class="fas fa-square me-1"></i>Clear
                    </button>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($registrations)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-users fa-4x text-muted mb-3"></i>
                        <h5 class="text-muted">No Registrations Found</h5>
                        <p class="text-muted">
                            <?php if ($pool_filter || $status_filter || $search_query): ?>
                                No registrations match your current filters. Try adjusting the filters above.
                            <?php else: ?>
                                No students have registered yet. Students can register through the student portal.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php else: ?>
                    <!-- Bulk Actions -->
                    <form method="POST" action="" id="bulkForm">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="form_token" value="<?php echo generate_token(); ?>">
                        <input type="hidden" name="action" value="bulk_action">
                        
                        <div class="mb-3">
                            <div class="row align-items-center">
                                <div class="col-md-6">
                                    <div class="input-group">
                                        <select class="form-select" name="bulk_action" id="bulkAction">
                                            <option value="">Choose bulk action...</option>
                                            <option value="delete">Delete Selected</option>
                                        </select>
                                        <button type="button" class="btn btn-outline-secondary" onclick="executeBulkAction()">
                                            <i class="fas fa-play me-1"></i>Execute
                                        </button>
                                    </div>
                                </div>
                                <div class="col-md-6 text-md-end mt-2 mt-md-0">
                                    <small class="text-muted">
                                        <span id="selectedCount">0</span> selected
                                    </small>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover" id="registrationsTable">
                                <thead class="table-light">
                                    <tr>
                                        <th width="30">
                                            <input type="checkbox" class="form-check-input" id="selectAllCheck">
                                        </th>
                                        <th>Registration No</th>
                                        <th>Contact</th>
                                        <th>Pool</th>
                                        <th>Academic Data</th>
                                        <th>Status</th>
                                        <th>Preferences</th>
                                        <th>Allotment</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($registrations as $reg): ?>
                                    <tr>
                                        <td>
                                            <input type="checkbox" class="form-check-input row-select" 
                                                   name="selected_ids[]" value="<?php echo $reg['id']; ?>">
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($reg['regno']); ?></strong>
                                            <br>
                                            <small class="text-muted">
                                                Registered: <?php echo date('M j, Y h:i A', strtotime($reg['registered_at'])); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <div class="small">
                                                <i class="fas fa-envelope me-1"></i>
                                                <?php echo htmlspecialchars($reg['email']); ?>
                                                <br>
                                                <i class="fas fa-phone me-1"></i>
                                                <?php echo htmlspecialchars($reg['mobile']); ?>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="small">
                                                <strong><?php echo htmlspecialchars($reg['pool_name']); ?></strong>
                                                <br>
                                                <?php echo htmlspecialchars($reg['semester']); ?>
                                                <br>
                                                <span class="text-muted"><?php echo htmlspecialchars($reg['batch']); ?></span>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($reg['cgpa'] !== null || $reg['backlogs'] !== null): ?>
                                                <div class="small">
                                                    <?php if ($reg['cgpa'] !== null): ?>
                                                        <span class="badge bg-success">CGPA: <?php echo number_format($reg['cgpa'], 2); ?></span>
                                                        <br>
                                                    <?php endif; ?>
                                                    <span class="badge <?php echo $reg['backlogs'] > 0 ? 'bg-danger' : 'bg-success'; ?>">
                                                        Backlogs: <?php echo $reg['backlogs'] ?? 0; ?>
                                                    </span>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted small">No data</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($reg['status'] == 'frozen'): ?>
                                                <span class="badge bg-danger status-badge">
                                                    <i class="fas fa-lock me-1"></i>Frozen
                                                </span>
                                                <?php if ($reg['frozen_at']): ?>
                                                    <br>
                                                    <small class="text-muted">
                                                        <?php echo date('M j, h:i A', strtotime($reg['frozen_at'])); ?>
                                                    </small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="badge bg-warning status-badge">
                                                    <i class="fas fa-edit me-1"></i>Saved
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($reg['priority_order'])): ?>
                                                <?php 
                                                $priorities = json_decode($reg['priority_order'], true);
                                                if ($priorities):
                                                    usort($priorities, function($a, $b) { return $a['priority'] - $b['priority']; });
                                                ?>
                                                    <div class="priority-list">
                                                        <?php foreach (array_slice($priorities, 0, 3) as $pref): ?>
                                                            <div class="priority-item">
                                                                <?php echo $pref['priority']; ?>. <?php echo htmlspecialchars($pref['subject_code']); ?>
                                                            </div>
                                                        <?php endforeach; ?>
                                                        <?php if (count($priorities) > 3): ?>
                                                            <div class="small text-muted">
                                                                +<?php echo count($priorities) - 3; ?> more
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted small">No preferences set</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($reg['allotted_subject']): ?>
                                                <div class="allotted-subject">
                                                    <i class="fas fa-trophy me-1"></i>
                                                    <?php echo htmlspecialchars($reg['allotted_subject']); ?>
                                                </div>
                                                <?php if ($reg['allotted_at']): ?>
                                                    <small class="text-muted">
                                                        <?php echo date('M j, Y', strtotime($reg['allotted_at'])); ?>
                                                    </small>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-muted small">Not allotted</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group" role="group">
                                                <button type="button" class="btn btn-sm btn-outline-info" 
                                                        onclick="viewDetails(<?php echo htmlspecialchars(json_encode($reg)); ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if (!$reg['allotted_subject']): ?>
                                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                                            onclick="deleteRegistration(<?php echo $reg['id']; ?>, '<?php echo htmlspecialchars($reg['regno']); ?>')">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- View Details Modal -->
    <div class="modal fade" id="detailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-user me-2"></i>Registration Details
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detailsContent">
                    <!-- Content will be loaded here -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
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
                    <div class="alert alert-warning">
                        <strong>Warning!</strong> This action cannot be undone.
                    </div>
                    <p>Are you sure you want to delete the registration for student "<span id="deleteStudentRegno"></span>"?</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" action="" style="display: inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="form_token" value="<?php echo generate_token(); ?>">
                        <input type="hidden" name="action" value="delete_registration">
                        <input type="hidden" name="registration_id" id="deleteRegistrationId">
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
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>

    <script>
        $(document).ready(function() {
            $('#registrationsTable').DataTable({
                responsive: true,
                pageLength: 25,
                order: [[1, 'asc']],
                columnDefs: [
                    { targets: [0, -1], orderable: false }
                ],
                dom: 'Bfrtip',
                buttons: [
                    {
                        extend: 'excel',
                        text: '<i class="fas fa-file-excel me-1"></i>Excel',
                        className: 'btn btn-success btn-sm'
                    },
                    {
                        extend: 'csv',
                        text: '<i class="fas fa-file-csv me-1"></i>CSV',
                        className: 'btn btn-info btn-sm'
                    }
                ]
            });
        });

        // Selection handling
        document.getElementById('selectAllCheck').addEventListener('change', function() {
            const checkboxes = document.querySelectorAll('.row-select');
            checkboxes.forEach(cb => cb.checked = this.checked);
            updateSelectedCount();
        });

        document.addEventListener('change', function(e) {
            if (e.target.classList.contains('row-select')) {
                updateSelectedCount();
            }
        });

        function updateSelectedCount() {
            const selected = document.querySelectorAll('.row-select:checked').length;
            document.getElementById('selectedCount').textContent = selected;
            
            const selectAllCheck = document.getElementById('selectAllCheck');
            const checkboxes = document.querySelectorAll('.row-select');
            
            if (selected === 0) {
                selectAllCheck.indeterminate = false;
                selectAllCheck.checked = false;
            } else if (selected === checkboxes.length) {
                selectAllCheck.indeterminate = false;
                selectAllCheck.checked = true;
            } else {
                selectAllCheck.indeterminate = true;
            }
        }

        function selectAll() {
            document.querySelectorAll('.row-select').forEach(cb => cb.checked = true);
            document.getElementById('selectAllCheck').checked = true;
            updateSelectedCount();
        }

        function clearSelection() {
            document.querySelectorAll('.row-select').forEach(cb => cb.checked = false);
            document.getElementById('selectAllCheck').checked = false;
            updateSelectedCount();
        }

        function executeBulkAction() {
            const action = document.getElementById('bulkAction').value;
            const selected = document.querySelectorAll('.row-select:checked');
            
            if (!action) {
                alert('Please select a bulk action.');
                return;
            }
            
            if (selected.length === 0) {
                alert('Please select at least one registration.');
                return;
            }
            
            if (action === 'delete') {
                if (confirm(`Are you sure you want to delete ${selected.length} registration(s)? This action cannot be undone.`)) {
                    document.getElementById('bulkForm').submit();
                }
            }
        }

        function viewDetails(registration) {
            const content = `
                <div class="row">
                    <div class="col-md-6">
                        <h6>Student Information</h6>
                        <p><strong>Registration No:</strong> ${registration.regno}</p>
                        <p><strong>Email:</strong> ${registration.email}</p>
                        <p><strong>Mobile:</strong> ${registration.mobile}</p>
                        <p><strong>Status:</strong> <span class="badge bg-${registration.status === 'frozen' ? 'danger' : 'warning'}">${registration.status}</span></p>
                    </div>
                    <div class="col-md-6">
                        <h6>Pool Information</h6>
                        <p><strong>Pool:</strong> ${registration.pool_name}</p>
                        <p><strong>Semester:</strong> ${registration.semester}</p>
                        <p><strong>Batch:</strong> ${registration.batch}</p>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <h6>Academic Data</h6>
                        <p><strong>CGPA:</strong> ${registration.cgpa || 'N/A'}</p>
                        <p><strong>Backlogs:</strong> ${registration.backlogs || '0'}</p>
                    </div>
                    <div class="col-md-6">
                        <h6>Registration Timeline</h6>
                        <p><strong>Registered:</strong> ${new Date(registration.registered_at).toLocaleString()}</p>
                        ${registration.frozen_at ? `<p><strong>Frozen:</strong> ${new Date(registration.frozen_at).toLocaleString()}</p>` : ''}
                        ${registration.allotted_at ? `<p><strong>Allotted:</strong> ${new Date(registration.allotted_at).toLocaleString()}</p>` : ''}
                    </div>
                </div>
                ${registration.priority_order ? `
                <div class="row">
                    <div class="col-12">
                        <h6>Preferences</h6>
                        <div class="preferences-list">
                            ${JSON.parse(registration.priority_order || '[]').sort((a,b) => a.priority - b.priority).map(p => 
                                `<span class="badge bg-info me-2 mb-1">${p.priority}. ${p.subject_code}</span>`
                            ).join('')}
                        </div>
                    </div>
                </div>
                ` : ''}
                ${registration.allotted_subject ? `
                <div class="row">
                    <div class="col-12">
                        <h6>Allotment Result</h6>
                        <div class="alert alert-success">
                            <strong>Allotted Subject:</strong> ${registration.allotted_subject}<br>
                            ${registration.allotment_reason ? `<strong>Reason:</strong> ${registration.allotment_reason}` : ''}
                        </div>
                    </div>
                </div>
                ` : ''}
            `;
            
            document.getElementById('detailsContent').innerHTML = content;
            const modal = new bootstrap.Modal(document.getElementById('detailsModal'));
            modal.show();
        }

        function deleteRegistration(id, regno) {
            document.getElementById('deleteRegistrationId').value = id;
            document.getElementById('deleteStudentRegno').textContent = regno;
            
            const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
            modal.show();
        }

        function exportRegistrations() {
            window.open('export_registrations.php?' + new URLSearchParams(window.location.search), '_blank');
        }

        function refreshData() {
            window.location.reload();
        }

        // Auto-refresh every 5 minutes
        setTimeout(function() {
            window.location.reload();
        }, 300000);

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>