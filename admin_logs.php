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

// Get filter parameters
$log_type = $_GET['type'] ?? 'all';
$user_type_filter = $_GET['user_type'] ?? '';
$user_filter = $_GET['user'] ?? '';
$action_filter = $_GET['action'] ?? '';
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-90 days')); // Last 90 days to catch more logs
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$severity_filter = $_GET['severity'] ?? '';
$search_query = trim($_GET['search'] ?? '');
$per_page = intval($_GET['per_page'] ?? 50);
$page = intval($_GET['page'] ?? 1);

// Handle log cleanup
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'cleanup_logs') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        log_security_event($conn, 'csrf_violation', 'high', 'CSRF token validation failed for log cleanup', $_SESSION['admin_username']);
        $error_message = 'Security validation failed. Please try again.';
    } else if (!prevent_resubmit($conn, 'admin', $_SESSION['admin_username'], 'cleanup_logs')) {
        $error_message = 'Cleanup already in progress. Please refresh the page.';
    } else {
        $cleanup_days = intval($_POST['cleanup_days'] ?? 30);
        
        if ($cleanup_days < 7) {
            $error_message = 'Cannot cleanup logs newer than 7 days for security reasons.';
        } else {
            try {
                $conn->beginTransaction();
                
                // Cleanup old activity logs
                $stmt = $conn->prepare("DELETE FROM activity_logs WHERE timestamp < DATE_SUB(NOW(), INTERVAL ? DAY)");
                $stmt->execute([$cleanup_days]);
                $activity_deleted = $stmt->rowCount();
                
                // Cleanup old login logs
                $stmt = $conn->prepare("DELETE FROM login_logs WHERE login_time < DATE_SUB(NOW(), INTERVAL ? DAY)");
                $stmt->execute([$cleanup_days]);
                $login_deleted = $stmt->rowCount();
                
                // Keep security logs longer (double the cleanup period)
                $stmt = $conn->prepare("DELETE FROM security_logs WHERE timestamp < DATE_SUB(NOW(), INTERVAL ? DAY)");
                $stmt->execute([$cleanup_days * 2]);
                $security_deleted = $stmt->rowCount();
                
                $conn->commit();
                
                log_activity($conn, 'admin', $_SESSION['admin_username'], 'logs_cleanup', null, null, null, [
                    'cleanup_days' => $cleanup_days,
                    'activity_deleted' => $activity_deleted,
                    'login_deleted' => $login_deleted,
                    'security_deleted' => $security_deleted
                ]);
                
                $success_message = "Cleanup completed! Deleted $activity_deleted activity logs, $login_deleted login logs, and $security_deleted security logs older than $cleanup_days days.";
                
            } catch(Exception $e) {
                $conn->rollBack();
                error_log("Log cleanup error: " . $e->getMessage());
                $error_message = 'An error occurred during cleanup: ' . $e->getMessage();
            }
        }
    }
}

try {
    // Debug: Let's first check if we have any logs at all
    $debug_info = [];
    
    $stmt = $conn->query("SELECT COUNT(*) as count FROM activity_logs");
    $debug_info['total_activity_logs'] = $stmt->fetch()['count'];
    
    $stmt = $conn->query("SELECT COUNT(*) as count FROM login_logs");
    $debug_info['total_login_logs'] = $stmt->fetch()['count'];
    
    $stmt = $conn->query("SELECT COUNT(*) as count FROM security_logs");
    $debug_info['total_security_logs'] = $stmt->fetch()['count'];
    
    // Build WHERE clause based on filters
    $where_conditions = ['1=1'];
    $params = [];
    
    // Date filters - fix for proper date handling
    if ($date_from) {
        $where_conditions[] = 'timestamp >= ?';
        $params[] = $date_from . ' 00:00:00';
    }
    if ($date_to) {
        $where_conditions[] = 'timestamp <= ?';
        $params[] = $date_to . ' 23:59:59';
    }
    
    // User type filter
    if (!empty($user_type_filter)) {
        $where_conditions[] = 'user_type = ?';
        $params[] = $user_type_filter;
    }
    
    // User filter
    if (!empty($user_filter)) {
        $where_conditions[] = 'user_identifier LIKE ?';
        $params[] = "%$user_filter%";
    }
    
    // Action filter
    if (!empty($action_filter)) {
        $where_conditions[] = 'action LIKE ?';
        $params[] = "%$action_filter%";
    }
    
    // Search query
    if (!empty($search_query)) {
        $where_conditions[] = '(action LIKE ? OR user_identifier LIKE ? OR table_name LIKE ? OR ip_address LIKE ?)';
        $search_param = "%$search_query%";
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
    }

    // Get logs based on type
    $logs = [];
    $total_count = 0;
    $offset = ($page - 1) * $per_page;
    
    if ($log_type == 'all' || $log_type == 'activity') {
        // Activity Logs
        $where_clause = implode(' AND ', $where_conditions);
        
        // Count total records
        $count_sql = "SELECT COUNT(*) as total FROM activity_logs WHERE $where_clause";
        $stmt = $conn->prepare($count_sql);
        $stmt->execute($params);
        $total_count = $stmt->fetch()['total'];
        
        // Get paginated results
        $sql = "
            SELECT 
                'activity' as log_type,
                id,
                user_type,
                user_identifier,
                action,
                table_name,
                record_id,
                old_values,
                new_values,
                ip_address,
                user_agent,
                timestamp,
                NULL as severity,
                NULL as description
            FROM activity_logs 
            WHERE $where_clause
            ORDER BY timestamp DESC 
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute(array_merge($params, [$per_page, $offset]));
        $logs = $stmt->fetchAll();
    }
    
    if ($log_type == 'all' || $log_type == 'login') {
        // Login Logs - fix date field reference
        $login_where_conditions = $where_conditions;
        
        // Replace timestamp with login_time for login logs
        $login_where_str = implode(' AND ', $login_where_conditions);
        $login_where_str = str_replace('timestamp', 'login_time', $login_where_str);
        
        $sql = "
            SELECT 
                'login' as log_type,
                id,
                user_type,
                user_identifier,
                action,
                NULL as table_name,
                NULL as record_id,
                NULL as old_values,
                NULL as new_values,
                ip_address,
                user_agent,
                login_time as timestamp,
                NULL as severity,
                NULL as description
            FROM login_logs 
            WHERE $login_where_str
            ORDER BY login_time DESC 
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute(array_merge($params, [$per_page, $offset]));
        
        if ($log_type == 'login') {
            $logs = $stmt->fetchAll();
            
            // Count for login logs only
            $count_sql = "SELECT COUNT(*) as total FROM login_logs WHERE $login_where_str";
            $stmt = $conn->prepare($count_sql);
            $stmt->execute($params);
            $total_count = $stmt->fetch()['total'];
        } else {
            $login_logs = $stmt->fetchAll();
            $logs = array_merge($logs, $login_logs);
        }
    }
    
    if ($log_type == 'all' || $log_type == 'security') {
        // Security Logs
        $security_where = $where_conditions;
        
        // Add severity filter for security logs
        if (!empty($severity_filter)) {
            $security_where[] = 'severity = ?';
            $params[] = $severity_filter;
        }
        
        $security_where_clause = implode(' AND ', $security_where);
        
        $sql = "
            SELECT 
                'security' as log_type,
                id,
                'system' as user_type,
                user_identifier,
                event_type as action,
                NULL as table_name,
                NULL as record_id,
                NULL as old_values,
                NULL as new_values,
                ip_address,
                user_agent,
                timestamp,
                severity,
                description
            FROM security_logs 
            WHERE $security_where_clause
            ORDER BY timestamp DESC 
            LIMIT ? OFFSET ?
        ";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute(array_merge($params, [$per_page, $offset]));
        
        if ($log_type == 'security') {
            $logs = $stmt->fetchAll();
            
            // Count for security logs only
            $count_sql = "SELECT COUNT(*) as total FROM security_logs WHERE $security_where_clause";
            $stmt = $conn->prepare($count_sql);
            $stmt->execute($params);
            $total_count = $stmt->fetch()['total'];
        } else {
            $security_logs = $stmt->fetchAll();
            $logs = array_merge($logs, $security_logs);
        }
    }
    
    // Sort combined logs by timestamp if showing all
    if ($log_type == 'all') {
        usort($logs, function($a, $b) {
            return strtotime($b['timestamp']) - strtotime($a['timestamp']);
        });
        $logs = array_slice($logs, 0, $per_page);
    }
    
    // Get filter options
    $stmt = $conn->prepare("SELECT DISTINCT user_type FROM activity_logs ORDER BY user_type");
    $stmt->execute();
    $user_types = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $stmt = $conn->prepare("SELECT DISTINCT action FROM activity_logs ORDER BY action");
    $stmt->execute();
    $actions = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $stmt = $conn->prepare("SELECT DISTINCT severity FROM security_logs ORDER BY severity");
    $stmt->execute();
    $severities = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Get log statistics
    $stmt = $conn->query("SELECT COUNT(*) as count FROM activity_logs WHERE DATE(timestamp) = CURDATE()");
    $today_activity = $stmt->fetch()['count'];
    
    $stmt = $conn->query("SELECT COUNT(*) as count FROM login_logs WHERE DATE(login_time) = CURDATE()");
    $today_logins = $stmt->fetch()['count'];
    
    $stmt = $conn->query("SELECT COUNT(*) as count FROM security_logs WHERE DATE(timestamp) = CURDATE()");
    $today_security = $stmt->fetch()['count'];
    
    $stmt = $conn->query("SELECT COUNT(*) as count FROM security_logs WHERE severity IN ('high', 'critical') AND DATE(timestamp) = CURDATE()");
    $critical_events = $stmt->fetch()['count'];

} catch(Exception $e) {
    error_log("Admin logs error: " . $e->getMessage());
    $logs = [];
    $total_count = 0;
    $user_types = $actions = $severities = [];
    $today_activity = $today_logins = $today_security = $critical_events = 0;
}

// Calculate pagination
$total_pages = ceil($total_count / $per_page);

$csrf_token = generate_csrf_token();
log_activity($conn, 'admin', $_SESSION['admin_username'], 'logs_viewed', null, null, null, [
    'log_type' => $log_type,
    'filters' => compact('user_type_filter', 'user_filter', 'action_filter', 'date_from', 'date_to')
]);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>System Logs - Subject Allotment System</title>
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
            margin-bottom: 20px;
        }
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .logs-card {
            border-left: 5px solid #28a745;
        }
        .filter-card {
            border-left: 5px solid #007bff;
        }
        .cleanup-card {
            border-left: 5px solid #ffc107;
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
        .log-entry {
            border-left: 4px solid #dee2e6;
            padding: 15px;
            margin-bottom: 10px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .log-entry.activity { border-left-color: #28a745; }
        .log-entry.login { border-left-color: #007bff; }
        .log-entry.security { border-left-color: #dc3545; }
        .log-entry.security.critical { border-left-color: #6f42c1; background: #f8f9fa; }
        .log-entry.security.high { border-left-color: #dc3545; }
        .log-entry.security.medium { border-left-color: #fd7e14; }
        .log-entry.security.low { border-left-color: #28a745; }
        .log-details {
            font-size: 0.9rem;
            color: #6c757d;
        }
        .log-changes {
            background: #f8f9fa;
            border-radius: 5px;
            padding: 10px;
            margin-top: 10px;
            font-family: monospace;
            font-size: 0.85rem;
            max-height: 200px;
            overflow-y: auto;
        }
        .pagination-custom {
            background: white;
            border-radius: 10px;
            padding: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        @media (max-width: 768px) {
            .stat-number {
                font-size: 1.5rem;
            }
            .log-entry {
                padding: 10px;
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
                            <li><a class="dropdown-item" href="admin_profile.php">
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
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-list-alt me-2"></i>System Logs & Audit Trail</h2>
            <div class="btn-group">
                <button type="button" class="btn btn-primary" onclick="showAllLogs()">
                    <i class="fas fa-eye me-2"></i>Show All Logs
                </button>
                <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#cleanupModal">
                    <i class="fas fa-broom me-2"></i>Cleanup Logs
                </button>
                <button type="button" class="btn btn-info" onclick="refreshLogs()">
                    <i class="fas fa-sync me-2"></i>Refresh
                </button>
                <button type="button" class="btn btn-success" onclick="exportLogs()">
                    <i class="fas fa-download me-2"></i>Export
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
                        <div class="stat-number"><?php echo $today_activity; ?></div>
                        <div>Today's Activity</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card stats-card h-100">
                    <div class="card-body stat-item">
                        <div class="stat-number"><?php echo $today_logins; ?></div>
                        <div>Today's Logins</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card stats-card h-100">
                    <div class="card-body stat-item">
                        <div class="stat-number"><?php echo $today_security; ?></div>
                        <div>Security Events</div>
                    </div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="card stats-card h-100">
                    <div class="card-body stat-item">
                        <div class="stat-number text-warning"><?php echo $critical_events; ?></div>
                        <div>Critical Events</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card filter-card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    <i class="fas fa-filter me-2"></i>Filter Logs
                </h5>
            </div>
            <div class="card-body">
                <form method="GET" action="" class="row g-3">
                    <div class="col-md-2">
                        <label for="type" class="form-label">Log Type</label>
                        <select class="form-select" id="type" name="type">
                            <option value="all" <?php echo $log_type == 'all' ? 'selected' : ''; ?>>All Logs</option>
                            <option value="activity" <?php echo $log_type == 'activity' ? 'selected' : ''; ?>>Activity</option>
                            <option value="login" <?php echo $log_type == 'login' ? 'selected' : ''; ?>>Login</option>
                            <option value="security" <?php echo $log_type == 'security' ? 'selected' : ''; ?>>Security</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="user_type" class="form-label">User Type</label>
                        <select class="form-select" id="user_type" name="user_type">
                            <option value="">All Types</option>
                            <?php foreach ($user_types as $type): ?>
                                <option value="<?php echo $type; ?>" <?php echo $user_type_filter == $type ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="severity" class="form-label">Severity</label>
                        <select class="form-select" id="severity" name="severity">
                            <option value="">All Levels</option>
                            <?php foreach ($severities as $sev): ?>
                                <option value="<?php echo $sev; ?>" <?php echo $severity_filter == $sev ? 'selected' : ''; ?>>
                                    <?php echo ucfirst($sev); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="date_from" class="form-label">Date From</label>
                        <input type="date" class="form-control" id="date_from" name="date_from" value="<?php echo $date_from; ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="date_to" class="form-label">Date To</label>
                        <input type="date" class="form-control" id="date_to" name="date_to" value="<?php echo $date_to; ?>">
                    </div>
                    <div class="col-md-2">
                        <label for="per_page" class="form-label">Per Page</label>
                        <select class="form-select" id="per_page" name="per_page">
                            <option value="25" <?php echo $per_page == 25 ? 'selected' : ''; ?>>25</option>
                            <option value="50" <?php echo $per_page == 50 ? 'selected' : ''; ?>>50</option>
                            <option value="100" <?php echo $per_page == 100 ? 'selected' : ''; ?>>100</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label for="search" class="form-label">Search</label>
                        <input type="text" class="form-control" id="search" name="search" 
                               placeholder="Action, user, table, or IP address" 
                               value="<?php echo htmlspecialchars($search_query); ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="user" class="form-label">User</label>
                        <input type="text" class="form-control" id="user" name="user" 
                               placeholder="Username or registration number" 
                               value="<?php echo htmlspecialchars($user_filter); ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="action" class="form-label">Action</label>
                        <input type="text" class="form-control" id="action" name="action" 
                               placeholder="Specific action" 
                               value="<?php echo htmlspecialchars($action_filter); ?>">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary me-2">
                            <i class="fas fa-search me-2"></i>Apply Filters
                        </button>
                        <a href="admin_logs.php" class="btn btn-secondary">
                            <i class="fas fa-times me-2"></i>Clear Filters
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Logs Display -->
        <div class="card logs-card">
            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>System Logs
                    <?php if ($total_count > 0): ?>
                        <span class="badge bg-light text-dark"><?php echo number_format($total_count); ?></span>
                    <?php endif; ?>
                </h5>
                <div class="d-flex align-items-center">
                    <small class="me-3">
                        Page <?php echo $page; ?> of <?php echo $total_pages; ?>
                        (<?php echo number_format($total_count); ?> total)
                    </small>
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-outline-light" onclick="autoRefresh()" id="autoRefreshBtn">
                            <i class="fas fa-play me-1"></i>Auto Refresh
                        </button>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <?php if (empty($logs)): ?>
                    <div class="text-center py-5">
                        <i class="fas fa-list-alt fa-4x text-muted mb-3"></i>
                        <h5 class="text-muted">No Logs Found</h5>
                        <p class="text-muted">
                            <?php if ($user_type_filter || $user_filter || $action_filter || $search_query): ?>
                                No logs match your current filters. Try adjusting the filters above.
                            <?php else: ?>
                                No system logs are available for the selected date range.
                            <?php endif; ?>
                        </p>
                        
                        <!-- Debug Information -->
                        <?php if (isset($debug_info)): ?>
                        <div class="alert alert-info mt-3 text-start">
                            <h6>Debug Information:</h6>
                            <ul class="mb-0">
                                <li>Total Activity Logs in DB: <?php echo $debug_info['total_activity_logs']; ?></li>
                                <li>Total Login Logs in DB: <?php echo $debug_info['total_login_logs']; ?></li>
                                <li>Total Security Logs in DB: <?php echo $debug_info['total_security_logs']; ?></li>
                                <li>Current Log Type Filter: <?php echo $log_type; ?></li>
                                <li>Date From: <?php echo $date_from; ?></li>
                                <li>Date To: <?php echo $date_to; ?></li>
                                <li>Search Query: "<?php echo htmlspecialchars($search_query); ?>"</li>
                                <li>User Filter: "<?php echo htmlspecialchars($user_filter); ?>"</li>
                                <?php
                                // Show a sample query for debugging
                                $sample_query = "SELECT * FROM activity_logs WHERE timestamp >= '$date_from 00:00:00' AND timestamp <= '$date_to 23:59:59' ORDER BY timestamp DESC LIMIT 5";
                                try {
                                    $stmt = $conn->query($sample_query);
                                    $sample_logs = $stmt->fetchAll();
                                    echo "<li>Sample query returned: " . count($sample_logs) . " records</li>";
                                    if (!empty($sample_logs)) {
                                        echo "<li>Latest log timestamp: " . $sample_logs[0]['timestamp'] . "</li>";
                                        echo "<li>Latest log user: " . htmlspecialchars($sample_logs[0]['user_identifier']) . "</li>";
                                    }
                                } catch(Exception $e) {
                                    echo "<li>Sample query error: " . $e->getMessage() . "</li>";
                                }
                                ?>
                            </ul>
                            <div class="mt-2">
                                <a href="admin_logs.php" class="btn btn-primary btn-sm">Clear All Filters</a>
                                <a href="admin_logs.php?date_from=<?php echo date('Y-m-d', strtotime('-90 days')); ?>&date_to=<?php echo date('Y-m-d'); ?>" class="btn btn-success btn-sm">Last 90 Days</a>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <div class="log-entry <?php echo $log['log_type']; ?> <?php echo $log['severity'] ?? ''; ?>" 
                             data-bs-toggle="collapse" data-bs-target="#log-<?php echo $log['id']; ?>" 
                             style="cursor: pointer;">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <div class="d-flex align-items-center mb-2">
                                        <?php
                                        $icon = match($log['log_type']) {
                                            'activity' => 'fa-cog',
                                            'login' => 'fa-sign-in-alt',
                                            'security' => 'fa-shield-alt',
                                            default => 'fa-list'
                                        };
                                        
                                        $type_badge = match($log['log_type']) {
                                            'activity' => 'success',
                                            'login' => 'primary',
                                            'security' => 'danger',
                                            default => 'secondary'
                                        };
                                        ?>
                                        <span class="badge bg-<?php echo $type_badge; ?> me-2">
                                            <i class="fas <?php echo $icon; ?> me-1"></i>
                                            <?php echo ucfirst($log['log_type']); ?>
                                        </span>
                                        
                                        <?php if ($log['severity']): ?>
                                            <span class="badge bg-<?php echo $log['severity'] == 'critical' ? 'dark' : ($log['severity'] == 'high' ? 'danger' : ($log['severity'] == 'medium' ? 'warning' : 'success')); ?> me-2">
                                                <?php echo ucfirst($log['severity']); ?>
                                            </span>
                                        <?php endif; ?>
                                        
                                        <strong><?php echo htmlspecialchars($log['action']); ?></strong>
                                        
                                        <?php if ($log['table_name']): ?>
                                            <span class="text-muted">on</span>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($log['table_name']); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <div class="log-details">
                                        <div class="row">
                                            <div class="col-md-3">
                                                <i class="fas fa-user me-1"></i>
                                                <strong><?php echo htmlspecialchars($log['user_identifier']); ?></strong>
                                                <span class="text-muted">(<?php echo ucfirst($log['user_type']); ?>)</span>
                                            </div>
                                            <div class="col-md-3">
                                                <i class="fas fa-globe me-1"></i>
                                                <?php echo htmlspecialchars($log['ip_address']); ?>
                                            </div>
                                            <div class="col-md-3">
                                                <i class="fas fa-clock me-1"></i>
                                                <?php echo date('M j, Y g:i:s A', strtotime($log['timestamp'])); ?>
                                            </div>
                                            <div class="col-md-3">
                                                <?php if ($log['record_id']): ?>
                                                    <i class="fas fa-hashtag me-1"></i>
                                                    ID: <?php echo $log['record_id']; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <i class="fas fa-chevron-down text-muted"></i>
                            </div>
                            
                            <!-- Collapsible Details -->
                            <div class="collapse mt-3" id="log-<?php echo $log['id']; ?>">
                                <?php if ($log['description']): ?>
                                    <div class="mb-2">
                                        <strong>Description:</strong> <?php echo htmlspecialchars($log['description']); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($log['user_agent']): ?>
                                    <div class="mb-2">
                                        <strong>User Agent:</strong> 
                                        <small class="text-muted"><?php echo htmlspecialchars($log['user_agent']); ?></small>
                                    </div>
                                <?php endif; ?>
                                
                                <?php if ($log['old_values'] || $log['new_values']): ?>
                                    <div class="log-changes">
                                        <?php if ($log['old_values']): ?>
                                            <strong>Old Values:</strong><br>
                                            <pre class="mb-2"><?php echo htmlspecialchars(json_encode(json_decode($log['old_values'], true), JSON_PRETTY_PRINT)); ?></pre>
                                        <?php endif; ?>
                                        
                                        <?php if ($log['new_values']): ?>
                                            <strong>New Values:</strong><br>
                                            <pre class="mb-0"><?php echo htmlspecialchars(json_encode(json_decode($log['new_values'], true), JSON_PRETTY_PRINT)); ?></pre>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
        <div class="pagination-custom">
            <nav aria-label="Log pagination">
                <ul class="pagination justify-content-center mb-0">
                    <?php if ($page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">
                                <i class="fas fa-angle-double-left"></i>
                            </a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                <i class="fas fa-angle-left"></i>
                            </a>
                        </li>
                    <?php endif; ?>
                    
                    <?php
                    $start = max(1, $page - 2);
                    $end = min($total_pages, $page + 2);
                    
                    for ($i = $start; $i <= $end; $i++):
                    ?>
                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                <?php echo $i; ?>
                            </a>
                        </li>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                <i class="fas fa-angle-right"></i>
                            </a>
                        </li>
                        <li class="page-item">
                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>">
                                <i class="fas fa-angle-double-right"></i>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            </nav>
            
            <div class="text-center mt-2">
                <small class="text-muted">
                    Showing <?php echo (($page - 1) * $per_page) + 1; ?> to 
                    <?php echo min($page * $per_page, $total_count); ?> of 
                    <?php echo number_format($total_count); ?> entries
                </small>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Cleanup Modal -->
    <div class="modal fade" id="cleanupModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-broom me-2"></i>Cleanup System Logs
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" id="cleanupForm">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="form_token" value="<?php echo generate_token(); ?>">
                        <input type="hidden" name="action" value="cleanup_logs">
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Warning!</strong> This action will permanently delete old log entries and cannot be undone.
                        </div>
                        
                        <div class="mb-3">
                            <label for="cleanup_days" class="form-label">Delete logs older than (days):</label>
                            <select class="form-select" id="cleanup_days" name="cleanup_days">
                                <option value="30">30 days</option>
                                <option value="60">60 days</option>
                                <option value="90">90 days</option>
                                <option value="180">6 months</option>
                                <option value="365">1 year</option>
                            </select>
                            <small class="text-muted">Security logs will be kept for double this period.</small>
                        </div>
                        
                        <div class="alert alert-info">
                            <h6>What will be cleaned:</h6>
                            <ul class="mb-0">
                                <li>Activity logs older than selected period</li>
                                <li>Login logs older than selected period</li>
                                <li>Security logs older than double the selected period</li>
                            </ul>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-broom me-2"></i>Cleanup Logs
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let autoRefreshInterval = null;
        
        function showAllLogs() {
            window.location.href = 'admin_logs.php';
        }
        
        function refreshLogs() {
            window.location.reload();
        }
        
        function exportLogs() {
            const params = new URLSearchParams(window.location.search);
            params.set('export', '1');
            window.open('export_logs.php?' + params.toString(), '_blank');
        }
        
        function autoRefresh() {
            const btn = document.getElementById('autoRefreshBtn');
            
            if (autoRefreshInterval) {
                clearInterval(autoRefreshInterval);
                autoRefreshInterval = null;
                btn.innerHTML = '<i class="fas fa-play me-1"></i>Auto Refresh';
                btn.classList.remove('btn-outline-warning');
                btn.classList.add('btn-outline-light');
            } else {
                autoRefreshInterval = setInterval(refreshLogs, 30000); // 30 seconds
                btn.innerHTML = '<i class="fas fa-pause me-1"></i>Stop Auto';
                btn.classList.remove('btn-outline-light');
                btn.classList.add('btn-outline-warning');
            }
        }
        
        // Form submission handler
        document.getElementById('cleanupForm').addEventListener('submit', function() {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Cleaning...';
            submitBtn.disabled = true;
        });
        
        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
        
        // Auto-focus search field if it has a value
        document.addEventListener('DOMContentLoaded', function() {
            const searchField = document.getElementById('search');
            if (searchField.value) {
                searchField.focus();
            }
        });
    </script>
</body>
</html>