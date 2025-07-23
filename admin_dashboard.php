<?php
require_once 'dbconfig.php';

// Check admin authentication
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit();
}

// Validate session
if (!validate_session($conn, 'admin', $_SESSION['admin_username'])) {
    session_destroy();
    header("Location: admin_login.php");
    exit();
}

// Get dashboard statistics
try {
    // Total subject pools
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM subject_pools WHERE is_active = 1");
    $stmt->execute();
    $total_pools = $stmt->fetch()['total'];

    // Total registrations
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM student_registrations");
    $stmt->execute();
    $total_registrations = $stmt->fetch()['total'];

    // Total allotments
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM subject_allotments");
    $stmt->execute();
    $total_allotments = $stmt->fetch()['total'];

    // Students with academic data
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM student_academic_data");
    $stmt->execute();
    $students_with_data = $stmt->fetch()['total'];

    // Recent activities
    $stmt = $conn->prepare("SELECT * FROM activity_logs WHERE user_type = 'admin' ORDER BY timestamp DESC LIMIT 10");
    $stmt->execute();
    $recent_activities = $stmt->fetchAll();

    // Pool-wise statistics
    $stmt = $conn->prepare("
        SELECT 
            sp.pool_name,
            sp.subject_name,
            sp.intake,
            COUNT(sr.id) as registrations,
            COUNT(sa.id) as allotments
        FROM subject_pools sp
        LEFT JOIN student_registrations sr ON sp.id = sr.pool_id
        LEFT JOIN subject_allotments sa ON sp.id = sa.pool_id
        WHERE sp.is_active = 1
        GROUP BY sp.id
        ORDER BY sp.pool_name, sp.subject_name
    ");
    $stmt->execute();
    $pool_stats = $stmt->fetchAll();

} catch(Exception $e) {
    error_log("Dashboard stats error: " . $e->getMessage());
    $total_pools = $total_registrations = $total_allotments = $students_with_data = 0;
    $recent_activities = $pool_stats = [];
}

log_activity($conn, 'admin', $_SESSION['admin_username'], 'dashboard_view');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Subject Allotment System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
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
            transition: transform 0.2s;
        }
        .card:hover {
            transform: translateY(-5px);
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .stat-card-success {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
        }
        .stat-card-warning {
            background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%);
            color: #333;
        }
        .stat-card-info {
            background: linear-gradient(135deg, #a8edea 0%, #fed6e3 100%);
            color: #333;
        }
        .sidebar {
            min-height: calc(100vh - 56px);
            background: white;
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
        }
        .nav-link {
            color: #495057;
            border-radius: 8px;
            margin: 2px 0;
        }
        .nav-link:hover, .nav-link.active {
            background-color: #667eea;
            color: white;
        }
        .table-responsive {
            border-radius: 10px;
            overflow: hidden;
        }
        @media (max-width: 768px) {
            .sidebar {
                min-height: auto;
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
                            <?php echo htmlspecialchars($_SESSION['admin_name']); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="admin_profile.php">
                                <i class="fas fa-user me-2"></i>Profile
                            </a></li>
                            <li><a class="dropdown-item" href="admin_logs.php">
                                <i class="fas fa-history me-2"></i>Activity Logs
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

    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 px-0">
                <div class="sidebar p-3">
                    <ul class="nav flex-column">
                        <li class="nav-item">
                            <a class="nav-link active" href="admin_dashboard.php">
                                <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="manage_subject_pools.php">
                                <i class="fas fa-layer-group me-2"></i>Manage Subject Pools
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="upload_student_data.php">
                                <i class="fas fa-upload me-2"></i>Upload Student Data
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="student_registrations.php">
                                <i class="fas fa-users me-2"></i>Student Registrations
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="run_allotment.php">
                                <i class="fas fa-cogs me-2"></i>Run Allotment
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="allotment_results.php">
                                <i class="fas fa-list-alt me-2"></i>Allotment Results
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="reports.php">
                                <i class="fas fa-chart-bar me-2"></i>Reports
                            </a>
                        </li>
                    </ul>
                </div>
            </div>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10">
                <div class="p-4">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h2><i class="fas fa-tachometer-alt me-2"></i>Dashboard</h2>
                        <small class="text-muted">
                            Last login: <?php echo date('d M Y, h:i A'); ?>
                        </small>
                    </div>

                    <!-- Statistics Cards -->
                    <div class="row mb-4">
                        <div class="col-lg-3 col-md-6 mb-3">
                            <div class="card stat-card h-100">
                                <div class="card-body text-center">
                                    <i class="fas fa-layer-group fa-3x mb-3"></i>
                                    <h3><?php echo $total_pools; ?></h3>
                                    <p class="mb-0">Active Subject Pools</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6 mb-3">
                            <div class="card stat-card-success h-100">
                                <div class="card-body text-center">
                                    <i class="fas fa-user-graduate fa-3x mb-3"></i>
                                    <h3><?php echo $total_registrations; ?></h3>
                                    <p class="mb-0">Student Registrations</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6 mb-3">
                            <div class="card stat-card-warning h-100">
                                <div class="card-body text-center">
                                    <i class="fas fa-check-circle fa-3x mb-3"></i>
                                    <h3><?php echo $total_allotments; ?></h3>
                                    <p class="mb-0">Completed Allotments</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-md-6 mb-3">
                            <div class="card stat-card-info h-100">
                                <div class="card-body text-center">
                                    <i class="fas fa-database fa-3x mb-3"></i>
                                    <h3><?php echo $students_with_data; ?></h3>
                                    <p class="mb-0">Students with Academic Data</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Pool Statistics -->
                    <div class="row">
                        <div class="col-lg-8">
                            <div class="card">
                                <div class="card-header bg-primary text-white">
                                    <h5 class="mb-0">
                                        <i class="fas fa-chart-pie me-2"></i>Pool-wise Statistics
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="table-responsive">
                                        <table class="table table-hover" id="poolStatsTable">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Pool Name</th>
                                                    <th>Subject</th>
                                                    <th>Intake</th>
                                                    <th>Registrations</th>
                                                    <th>Allotments</th>
                                                    <th>Utilization</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($pool_stats as $stat): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($stat['pool_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($stat['subject_name']); ?></td>
                                                    <td>
                                                        <span class="badge bg-info"><?php echo $stat['intake']; ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-warning"><?php echo $stat['registrations']; ?></span>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-success"><?php echo $stat['allotments']; ?></span>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        $utilization = $stat['intake'] > 0 ? ($stat['allotments'] / $stat['intake']) * 100 : 0;
                                                        $utilization_class = $utilization >= 80 ? 'success' : ($utilization >= 50 ? 'warning' : 'danger');
                                                        ?>
                                                        <div class="progress" style="height: 20px;">
                                                            <div class="progress-bar bg-<?php echo $utilization_class; ?>" 
                                                                 style="width: <?php echo $utilization; ?>%"
                                                                 title="<?php echo round($utilization, 1); ?>%">
                                                                <?php echo round($utilization, 1); ?>%
                                                            </div>
                                                        </div>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4">
                            <div class="card">
                                <div class="card-header bg-success text-white">
                                    <h5 class="mb-0">
                                        <i class="fas fa-history me-2"></i>Recent Activities
                                    </h5>
                                </div>
                                <div class="card-body p-0">
                                    <div class="list-group list-group-flush">
                                        <?php if (empty($recent_activities)): ?>
                                            <div class="list-group-item text-center text-muted py-4">
                                                <i class="fas fa-info-circle me-2"></i>No recent activities
                                            </div>
                                        <?php else: ?>
                                            <?php foreach ($recent_activities as $activity): ?>
                                            <div class="list-group-item">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($activity['action']); ?></strong>
                                                        <br>
                                                        <small class="text-muted">
                                                            by <?php echo htmlspecialchars($activity['user_identifier']); ?>
                                                        </small>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?php echo date('M j, g:i A', strtotime($activity['timestamp'])); ?>
                                                    </small>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-footer">
                                    <a href="admin_logs.php" class="btn btn-sm btn-outline-success">
                                        View All Activities <i class="fas fa-arrow-right ms-1"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
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
                order: [[0, 'asc']],
                language: {
                    search: "Search pools:",
                    lengthMenu: "Show _MENU_ pools per page",
                    info: "Showing _START_ to _END_ of _TOTAL_ pools"
                }
            });
        });

        // Auto-refresh dashboard every 5 minutes
        setTimeout(function() {
            window.location.reload();
        }, 300000);
    </script>
</body>
</html>