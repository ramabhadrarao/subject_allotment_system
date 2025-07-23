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

// Get report parameters
$report_type = $_GET['type'] ?? 'overview';
$pool_filter = intval($_GET['pool'] ?? 0);
$date_from = $_GET['date_from'] ?? date('Y-m-01'); // First day of current month
$date_to = $_GET['date_to'] ?? date('Y-m-d'); // Today

try {
    // Get available pools for filter
    $stmt = $conn->prepare("
        SELECT id, pool_name, semester, batch, 
               COUNT(DISTINCT sr.regno) as registrations,
               COUNT(DISTINCT sa.regno) as allotments
        FROM subject_pools sp
        LEFT JOIN student_registrations sr ON sp.id = sr.pool_id
        LEFT JOIN subject_allotments sa ON sp.id = sa.pool_id
        WHERE sp.is_active = 1
        GROUP BY sp.id, sp.pool_name, sp.semester, sp.batch
        ORDER BY sp.pool_name, sp.semester
    ");
    $stmt->execute();
    $available_pools = $stmt->fetchAll();

    // Overall Statistics
    $stats = [];
    
    // Total counts
    $stmt = $conn->query("SELECT COUNT(*) as count FROM subject_pools WHERE is_active = 1");
    $stats['total_pools'] = $stmt->fetch()['count'];
    
    $stmt = $conn->query("SELECT COUNT(*) as count FROM student_registrations");
    $stats['total_registrations'] = $stmt->fetch()['count'];
    
    $stmt = $conn->query("SELECT COUNT(*) as count FROM student_registrations WHERE status = 'frozen'");
    $stats['frozen_registrations'] = $stmt->fetch()['count'];
    
    $stmt = $conn->query("SELECT COUNT(DISTINCT regno) as count FROM subject_allotments");
    $stats['allotted_students'] = $stmt->fetch()['count'];
    
    $stmt = $conn->query("SELECT COUNT(*) as count FROM student_academic_data");
    $stats['students_with_data'] = $stmt->fetch()['count'];

    // Registration trends (last 30 days)
    $stmt = $conn->prepare("
        SELECT DATE(registered_at) as date, COUNT(*) as count 
        FROM student_registrations 
        WHERE registered_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY DATE(registered_at) 
        ORDER BY date
    ");
    $stmt->execute();
    $registration_trends = $stmt->fetchAll();

    // Subject-wise allotment statistics
    $stmt = $conn->prepare("
        SELECT 
            sp.subject_code,
            sp.subject_name,
            sp.intake,
            COUNT(sa.id) as allotted,
            COUNT(DISTINCT sr.regno) as registered,
            ROUND((COUNT(sa.id) / sp.intake) * 100, 1) as utilization
        FROM subject_pools sp
        LEFT JOIN subject_allotments sa ON sp.subject_code = sa.subject_code
        LEFT JOIN student_registrations sr ON sp.id = sr.pool_id
        WHERE sp.is_active = 1
        GROUP BY sp.subject_code, sp.subject_name, sp.intake
        ORDER BY utilization DESC
    ");
    $stmt->execute();
    $subject_stats = $stmt->fetchAll();

    // CGPA Distribution Analysis
    $stmt = $conn->prepare("
        SELECT 
            CASE 
                WHEN cgpa >= 9.0 THEN '9.0 - 10.0'
                WHEN cgpa >= 8.0 THEN '8.0 - 8.9'
                WHEN cgpa >= 7.0 THEN '7.0 - 7.9'
                WHEN cgpa >= 6.0 THEN '6.0 - 6.9'
                WHEN cgpa >= 5.0 THEN '5.0 - 5.9'
                ELSE 'Below 5.0'
            END as cgpa_range,
            COUNT(*) as count
        FROM student_academic_data 
        WHERE cgpa IS NOT NULL
        GROUP BY 
            CASE 
                WHEN cgpa >= 9.0 THEN '9.0 - 10.0'
                WHEN cgpa >= 8.0 THEN '8.0 - 8.9'
                WHEN cgpa >= 7.0 THEN '7.0 - 7.9'
                WHEN cgpa >= 6.0 THEN '6.0 - 6.9'
                WHEN cgpa >= 5.0 THEN '5.0 - 5.9'
                ELSE 'Below 5.0'
            END
        ORDER BY MIN(cgpa) DESC
    ");
    $stmt->execute();
    $cgpa_distribution = $stmt->fetchAll();

    // Backlog Analysis
    $stmt = $conn->prepare("
        SELECT 
            backlogs,
            COUNT(*) as count
        FROM student_academic_data 
        WHERE backlogs IS NOT NULL
        GROUP BY backlogs
        ORDER BY backlogs
    ");
    $stmt->execute();
    $backlog_distribution = $stmt->fetchAll();

    // Pool-wise Registration Timeline
    $stmt = $conn->prepare("
        SELECT 
            sp.pool_name,
            DATE(sr.registered_at) as date,
            COUNT(*) as registrations
        FROM student_registrations sr
        JOIN subject_pools sp ON sr.pool_id = sp.id
        WHERE sr.registered_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY sp.pool_name, DATE(sr.registered_at)
        ORDER BY date, sp.pool_name
    ");
    $stmt->execute();
    $pool_timeline = $stmt->fetchAll();

    // Top Preferred Subjects
    $stmt = $conn->prepare("
        SELECT 
            JSON_UNQUOTE(JSON_EXTRACT(priority_order, '$[*].subject_code')) as subjects,
            COUNT(*) as preference_count
        FROM student_registrations 
        WHERE priority_order IS NOT NULL
        GROUP BY subjects
        ORDER BY preference_count DESC
        LIMIT 10
    ");
    $stmt->execute();
    $preference_data = $stmt->fetchAll();

    // Process preference data
    $subject_preferences = [];
    foreach ($preference_data as $data) {
        $subjects = json_decode('[' . $data['subjects'] . ']', true);
        if ($subjects) {
            foreach ($subjects as $subject) {
                if (!isset($subject_preferences[$subject])) {
                    $subject_preferences[$subject] = 0;
                }
                $subject_preferences[$subject]++;
            }
        }
    }
    arsort($subject_preferences);
    $subject_preferences = array_slice($subject_preferences, 0, 10, true);

    // Success Rate Analysis
    $stmt = $conn->prepare("
        SELECT 
            sp.pool_name,
            COUNT(DISTINCT sr.regno) as total_registered,
            COUNT(DISTINCT sa.regno) as total_allotted,
            ROUND((COUNT(DISTINCT sa.regno) / COUNT(DISTINCT sr.regno)) * 100, 1) as success_rate
        FROM student_registrations sr
        JOIN subject_pools sp ON sr.pool_id = sp.id
        LEFT JOIN subject_allotments sa ON sr.regno = sa.regno AND sr.pool_id = sa.pool_id
        WHERE sr.status = 'frozen'
        GROUP BY sp.pool_name
        ORDER BY success_rate DESC
    ");
    $stmt->execute();
    $success_rates = $stmt->fetchAll();

    // Recent Activity Log
    $stmt = $conn->prepare("
        SELECT 
            user_type,
            user_identifier,
            action,
            table_name,
            timestamp,
            ip_address
        FROM activity_logs 
        WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ORDER BY timestamp DESC 
        LIMIT 50
    ");
    $stmt->execute();
    $recent_activities = $stmt->fetchAll();

} catch(Exception $e) {
    error_log("Reports error: " . $e->getMessage());
    $stats = [];
    $registration_trends = [];
    $subject_stats = [];
    $cgpa_distribution = [];
    $backlog_distribution = [];
    $pool_timeline = [];
    $subject_preferences = [];
    $success_rates = [];
    $recent_activities = [];
}

log_activity($conn, 'admin', $_SESSION['admin_username'], 'reports_accessed', null, null, null, ['report_type' => $report_type]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - Subject Allotment System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .chart-card {
            border-left: 5px solid #28a745;
        }
        .analytics-card {
            border-left: 5px solid #007bff;
        }
        .activity-card {
            border-left: 5px solid #ffc107;
        }
        .stat-number {
            font-size: 2.5rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }
        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        .chart-container {
            position: relative;
            height: 300px;
        }
        .progress-custom {
            height: 25px;
            border-radius: 12px;
        }
        .activity-item {
            padding: 10px;
            border-bottom: 1px solid #eee;
        }
        .activity-item:last-child {
            border-bottom: none;
        }
        .filter-panel {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        @media (max-width: 768px) {
            .stat-number {
                font-size: 1.8rem;
            }
            .chart-container {
                height: 250px;
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
            <h2><i class="fas fa-chart-bar me-2"></i>Reports & Analytics</h2>
            <div class="btn-group">
                <button type="button" class="btn btn-success" onclick="exportReport()">
                    <i class="fas fa-download me-2"></i>Export Report
                </button>
                <button type="button" class="btn btn-info" onclick="refreshData()">
                    <i class="fas fa-sync me-2"></i>Refresh
                </button>
            </div>
        </div>

        <!-- Filter Panel -->
        <div class="filter-panel">
            <div class="row align-items-center">
                <div class="col-md-3">
                    <label for="reportType" class="form-label">Report Type</label>
                    <select class="form-select" id="reportType" onchange="changeReportType()">
                        <option value="overview" <?php echo $report_type == 'overview' ? 'selected' : ''; ?>>Overview</option>
                        <option value="registrations" <?php echo $report_type == 'registrations' ? 'selected' : ''; ?>>Registration Analytics</option>
                        <option value="allotments" <?php echo $report_type == 'allotments' ? 'selected' : ''; ?>>Allotment Analytics</option>
                        <option value="academic" <?php echo $report_type == 'academic' ? 'selected' : ''; ?>>Academic Performance</option>
                        <option value="activity" <?php echo $report_type == 'activity' ? 'selected' : ''; ?>>System Activity</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="poolFilter" class="form-label">Subject Pool</label>
                    <select class="form-select" id="poolFilter">
                        <option value="">All Pools</option>
                        <?php foreach ($available_pools as $pool): ?>
                            <option value="<?php echo $pool['id']; ?>" <?php echo $pool_filter == $pool['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($pool['pool_name'] . ' - ' . $pool['semester']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="dateFrom" class="form-label">Date From</label>
                    <input type="date" class="form-control" id="dateFrom" value="<?php echo $date_from; ?>">
                </div>
                <div class="col-md-2">
                    <label for="dateTo" class="form-label">Date To</label>
                    <input type="date" class="form-control" id="dateTo" value="<?php echo $date_to; ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-grid">
                        <button type="button" class="btn btn-light" onclick="applyFilters()">
                            <i class="fas fa-filter me-2"></i>Apply
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Overall Statistics -->
        <div class="row mb-4">
            <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                <div class="card stats-card h-100">
                    <div class="card-body text-center">
                        <div class="stat-number"><?php echo $stats['total_pools'] ?? 0; ?></div>
                        <div class="stat-label">Subject Pools</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                <div class="card stats-card h-100">
                    <div class="card-body text-center">
                        <div class="stat-number"><?php echo $stats['total_registrations'] ?? 0; ?></div>
                        <div class="stat-label">Total Registrations</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                <div class="card stats-card h-100">
                    <div class="card-body text-center">
                        <div class="stat-number"><?php echo $stats['frozen_registrations'] ?? 0; ?></div>
                        <div class="stat-label">Frozen Preferences</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                <div class="card stats-card h-100">
                    <div class="card-body text-center">
                        <div class="stat-number"><?php echo $stats['allotted_students'] ?? 0; ?></div>
                        <div class="stat-label">Students Allotted</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                <div class="card stats-card h-100">
                    <div class="card-body text-center">
                        <div class="stat-number"><?php echo $stats['students_with_data'] ?? 0; ?></div>
                        <div class="stat-label">Academic Records</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-2 col-md-4 col-sm-6 mb-3">
                <div class="card stats-card h-100">
                    <div class="card-body text-center">
                        <?php 
                        $success_rate = ($stats['frozen_registrations'] ?? 0) > 0 
                            ? round((($stats['allotted_students'] ?? 0) / $stats['frozen_registrations']) * 100, 1) 
                            : 0;
                        ?>
                        <div class="stat-number"><?php echo $success_rate; ?>%</div>
                        <div class="stat-label">Success Rate</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="row">
            <!-- Registration Trends -->
            <div class="col-lg-6 mb-4">
                <div class="card chart-card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-chart-line me-2"></i>Registration Trends (Last 30 Days)
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="registrationTrendChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Subject Utilization -->
            <div class="col-lg-6 mb-4">
                <div class="card chart-card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-chart-bar me-2"></i>Subject Utilization
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="utilizationChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Analytics Row -->
        <div class="row">
            <!-- CGPA Distribution -->
            <div class="col-lg-4 mb-4">
                <div class="card analytics-card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-chart-pie me-2"></i>CGPA Distribution
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="chart-container">
                            <canvas id="cgpaChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top Preferred Subjects -->
            <div class="col-lg-4 mb-4">
                <div class="card analytics-card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-heart me-2"></i>Most Preferred Subjects
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($subject_preferences)): ?>
                            <?php foreach (array_slice($subject_preferences, 0, 8) as $subject => $count): ?>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="fw-bold"><?php echo htmlspecialchars($subject); ?></span>
                                    <span class="badge bg-primary"><?php echo $count; ?></span>
                                </div>
                                <div class="progress progress-custom mb-3">
                                    <?php $percentage = ($count / max($subject_preferences)) * 100; ?>
                                    <div class="progress-bar bg-info" style="width: <?php echo $percentage; ?>%"></div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-chart-bar fa-3x mb-3"></i>
                                <p>No preference data available</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Pool Success Rates -->
            <div class="col-lg-4 mb-4">
                <div class="card analytics-card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-trophy me-2"></i>Pool Success Rates
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($success_rates)): ?>
                            <?php foreach ($success_rates as $rate): ?>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <small class="fw-bold"><?php echo htmlspecialchars($rate['pool_name']); ?></small>
                                        <small class="badge bg-success"><?php echo $rate['success_rate']; ?>%</small>
                                    </div>
                                    <div class="progress progress-custom">
                                        <div class="progress-bar bg-success" style="width: <?php echo $rate['success_rate']; ?>%"></div>
                                    </div>
                                    <small class="text-muted">
                                        <?php echo $rate['total_allotted']; ?>/<?php echo $rate['total_registered']; ?> students
                                    </small>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-trophy fa-3x mb-3"></i>
                                <p>No allotment data available</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Subject Statistics Table -->
        <div class="row">
            <div class="col-12">
                <div class="card analytics-card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-table me-2"></i>Subject-wise Statistics
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover" id="subjectStatsTable">
                                <thead class="table-light">
                                    <tr>
                                        <th>Subject Code</th>
                                        <th>Subject Name</th>
                                        <th>Intake</th>
                                        <th>Registered</th>
                                        <th>Allotted</th>
                                        <th>Utilization</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($subject_stats as $stat): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($stat['subject_code']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($stat['subject_name']); ?></td>
                                        <td>
                                            <span class="badge bg-info"><?php echo $stat['intake']; ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-warning"><?php echo $stat['registered']; ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-success"><?php echo $stat['allotted']; ?></span>
                                        </td>
                                        <td>
                                            <div class="progress" style="height: 20px;">
                                                <?php 
                                                $util_class = $stat['utilization'] >= 80 ? 'success' : ($stat['utilization'] >= 50 ? 'warning' : 'danger');
                                                ?>
                                                <div class="progress-bar bg-<?php echo $util_class; ?>" 
                                                     style="width: <?php echo $stat['utilization']; ?>%"
                                                     title="<?php echo $stat['utilization']; ?>%">
                                                    <?php echo $stat['utilization']; ?>%
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($stat['utilization'] >= 100): ?>
                                                <span class="badge bg-danger">Full</span>
                                            <?php elseif ($stat['utilization'] >= 80): ?>
                                                <span class="badge bg-warning">Nearly Full</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">Available</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card activity-card">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0">
                            <i class="fas fa-history me-2"></i>Recent System Activity (Last 7 Days)
                        </h5>
                    </div>
                    <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
                        <?php if (!empty($recent_activities)): ?>
                            <?php foreach ($recent_activities as $activity): ?>
                                <div class="activity-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <strong>
                                                <?php 
                                                $icon = $activity['user_type'] == 'admin' ? 'fa-user-shield' : 'fa-user-graduate';
                                                echo "<i class='fas $icon me-2'></i>";
                                                echo htmlspecialchars($activity['user_identifier']); 
                                                ?>
                                            </strong>
                                            <span class="text-muted">performed</span>
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
                                <p class="text-muted">No recent activity found</p>
                            </div>
                        <?php endif; ?>
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
        // Initialize DataTable
        $(document).ready(function() {
            $('#subjectStatsTable').DataTable({
                responsive: true,
                pageLength: 10,
                order: [[5, 'desc']] // Sort by utilization
            });
        });

        // Registration Trends Chart
        const registrationTrendCtx = document.getElementById('registrationTrendChart').getContext('2d');
        const registrationTrendData = <?php echo json_encode($registration_trends); ?>;
        
        new Chart(registrationTrendCtx, {
            type: 'line',
            data: {
                labels: registrationTrendData.map(item => new Date(item.date).toLocaleDateString()),
                datasets: [{
                    label: 'Daily Registrations',
                    data: registrationTrendData.map(item => item.count),
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40, 167, 69, 0.1)',
                    tension: 0.4,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            stepSize: 1
                        }
                    }
                }
            }
        });

        // Subject Utilization Chart
        const utilizationCtx = document.getElementById('utilizationChart').getContext('2d');
        const subjectStatsData = <?php echo json_encode(array_slice($subject_stats, 0, 10)); ?>;
        
        new Chart(utilizationCtx, {
            type: 'bar',
            data: {
                labels: subjectStatsData.map(item => item.subject_code),
                datasets: [{
                    label: 'Utilization %',
                    data: subjectStatsData.map(item => item.utilization),
                    backgroundColor: subjectStatsData.map(item => 
                        item.utilization >= 80 ? '#28a745' : 
                        item.utilization >= 50 ? '#ffc107' : '#dc3545'
                    ),
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        max: 100,
                        ticks: {
                            callback: function(value) {
                                return value + '%';
                            }
                        }
                    }
                }
            }
        });

        // CGPA Distribution Chart
        const cgpaCtx = document.getElementById('cgpaChart').getContext('2d');
        const cgpaData = <?php echo json_encode($cgpa_distribution); ?>;
        
        new Chart(cgpaCtx, {
            type: 'doughnut',
            data: {
                labels: cgpaData.map(item => item.cgpa_range),
                datasets: [{
                    data: cgpaData.map(item => item.count),
                    backgroundColor: [
                        '#28a745', '#20c997', '#17a2b8', 
                        '#ffc107', '#fd7e14', '#dc3545'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // Utility functions
        function changeReportType() {
            const reportType = document.getElementById('reportType').value;
            const url = new URL(window.location);
            url.searchParams.set('type', reportType);
            window.location.href = url.toString();
        }

        function applyFilters() {
            const url = new URL(window.location);
            const pool = document.getElementById('poolFilter').value;
            const dateFrom = document.getElementById('dateFrom').value;
            const dateTo = document.getElementById('dateTo').value;
            
            if (pool) url.searchParams.set('pool', pool);
            else url.searchParams.delete('pool');
            
            url.searchParams.set('date_from', dateFrom);
            url.searchParams.set('date_to', dateTo);
            
            window.location.href = url.toString();
        }

        function exportReport() {
            window.open('export_report.php?' + new URLSearchParams(window.location.search), '_blank');
        }

        function refreshData() {
            window.location.reload();
        }

        // Auto-refresh every 10 minutes
        setTimeout(function() {
            window.location.reload();
        }, 600000);
    </script>
</body>
</html>