<?php
require_once 'dbconfig.php';

// Check admin authentication
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header("Location: admin_login.php");
    exit();
}

// Get all data with simplified queries
try {
    // 1. Subject Pool Summary with Registration and Allotment Counts
    $stmt = $conn->prepare("
        SELECT
            sp.subject_name,
            sp.subject_code,
            sp.intake,
            (SELECT COUNT(DISTINCT sr.regno)
             FROM student_registrations sr
             JOIN subject_pools sp2 ON sr.pool_id = sp2.id
             WHERE sp2.pool_name = sp.pool_name
               AND sp2.allowed_programmes = sp.allowed_programmes
               AND JSON_SEARCH(sr.priority_order, 'one', sp.subject_code, NULL, '$[*].subject_code') IS NOT NULL
            ) as registrations,
            COUNT(DISTINCT sa.regno) as allotments
        FROM subject_pools sp
        LEFT JOIN subject_allotments sa ON sp.subject_code = sa.subject_code
        WHERE sp.is_active = 1
        GROUP BY sp.subject_code, sp.subject_name, sp.intake
        ORDER BY sp.subject_code
    ");
    $stmt->execute();
    $subjects = $stmt->fetchAll();

    // 2. Student Registration Details
    $stmt = $conn->prepare("
        SELECT 
            sr.regno,
            sr.email,
            sr.mobile,
            sr.pool_id,
            sp.pool_name,
            sr.priority_order,
            sr.status,
            sr.registered_at,
            sr.frozen_at,
            -- Get academic data
            sad.cgpa,
            sad.backlogs,
            -- Check if allotted
            sa.subject_code as allotted_subject,
            sa.allotment_reason,
            sa.allotment_rank
        FROM student_registrations sr
        LEFT JOIN subject_pools sp ON sr.pool_id = sp.id
        LEFT JOIN student_academic_data sad ON sr.regno = sad.regno
        LEFT JOIN subject_allotments sa ON sr.regno = sa.regno
        ORDER BY sr.pool_id, sr.regno
    ");
    $stmt->execute();
    $registrations = $stmt->fetchAll();

    // 3. Summary Statistics
    $stats = [];
    
    // Total counts
    $stmt = $conn->query("SELECT COUNT(DISTINCT pool_name) FROM subject_pools WHERE is_active = 1");
    $stats['total_pools'] = $stmt->fetchColumn();
    
    $stmt = $conn->query("SELECT COUNT(DISTINCT subject_code) FROM subject_pools WHERE is_active = 1");
    $stats['total_subjects'] = $stmt->fetchColumn();
    
    $stmt = $conn->query("SELECT SUM(intake) FROM subject_pools WHERE is_active = 1");
    $stats['total_seats'] = $stmt->fetchColumn();
    
    $stmt = $conn->query("SELECT COUNT(DISTINCT regno) FROM student_registrations");
    $stats['total_students'] = $stmt->fetchColumn();
    
    $stmt = $conn->query("SELECT COUNT(DISTINCT regno) FROM student_registrations WHERE status = 'frozen'");
    $stats['frozen_count'] = $stmt->fetchColumn();
    
    $stmt = $conn->query("SELECT COUNT(*) FROM subject_allotments");
    $stats['total_allotments'] = $stmt->fetchColumn();

    // 4. Pool-wise Summary
    // $stmt = $conn->prepare("
    //     SELECT 
    //         sp.pool_name,
    //         sp.batch,
    //         sp.semester,
    //         COUNT(DISTINCT sp.subject_code) as subjects_count,
    //         SUM(sp.intake) as total_seats,
    //         COUNT(DISTINCT sr.regno) as registered_students,
    //         COUNT(DISTINCT sa.regno) as allotted_students
    //     FROM subject_pools sp
    //     LEFT JOIN student_registrations sr ON sp.id = sr.pool_id
    //     LEFT JOIN subject_allotments sa ON sp.id = sa.pool_id
    //     WHERE sp.is_active = 1
    //     GROUP BY sp.pool_name, sp.batch, sp.semester
    //     ORDER BY sp.pool_name
    // ");
    // $stmt->execute();
    // $pool_summary = $stmt->fetchAll();

} catch(Exception $e) {
    die("Error: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simplified Report - Subject Allotment System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8f9fa; }
        .card { margin-bottom: 20px; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .stat-box { text-align: center; padding: 20px; background: white; border-radius: 10px; margin-bottom: 15px; }
        .stat-number { font-size: 2rem; font-weight: bold; color: #007bff; }
        .stat-label { color: #6c757d; font-size: 0.9rem; }
        .table-title { background-color: #007bff; color: white; padding: 10px; border-radius: 5px 5px 0 0; }
        .priority-badge { margin: 2px; }
        .status-badge { font-size: 0.8rem; }
        @media print {
            .no-print { display: none !important; }
            .card { box-shadow: none !important; }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary no-print">
        <div class="container-fluid">
            <a class="navbar-brand" href="admin_dashboard.php">
                <i class="fas fa-graduation-cap me-2"></i>Subject Allotment System - Report
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="admin_dashboard.php">
                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                </a>
                <button class="btn btn-light ms-2" onclick="window.print()">
                    <i class="fas fa-print me-2"></i>Print
                </button>
            </div>
        </div>
    </nav>

    <div class="container-fluid py-4">
        <!-- Overall Statistics -->
        <h2 class="mb-4"><i class="fas fa-chart-bar me-2"></i>Overall Statistics</h2>
        
        <div class="row mb-4">
            <div class="col-md-2">
                <div class="stat-box">
                    <div class="stat-number"><?php echo $stats['total_pools']; ?></div>
                    <div class="stat-label">Active Pools</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-box">
                    <div class="stat-number"><?php echo $stats['total_subjects']; ?></div>
                    <div class="stat-label">Total Subjects</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-box">
                    <div class="stat-number"><?php echo $stats['total_seats']; ?></div>
                    <div class="stat-label">Total Seats</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-box">
                    <div class="stat-number"><?php echo $stats['total_students']; ?></div>
                    <div class="stat-label">Registered Students</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-box">
                    <div class="stat-number"><?php echo $stats['frozen_count']; ?></div>
                    <div class="stat-label">Frozen Choices</div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="stat-box">
                    <div class="stat-number"><?php echo $stats['total_allotments']; ?></div>
                    <div class="stat-label">Total Allotments</div>
                </div>
            </div>
        </div>

        <!-- Pool-wise Summary -->
        

        <!-- Subject-wise Details -->
        <div class="card">
            <div class="table-title">
                <h5 class="mb-0"><i class="fas fa-book me-2"></i>Subject-wise Registration & Allotment Summary</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>Subject Code</th>
                                <th>Subject Name</th>
                                <th>Intake</th>
                                <th>Registrations</th>
                                <th>Allotments</th>
                                <th>Available</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjects as $subject): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($subject['subject_code']); ?></strong></td>
                                <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                <td class="text-center"><span class="badge bg-primary"><?php echo $subject['intake']; ?></span></td>
                                <td class="text-center"><span class="badge bg-warning"><?php echo $subject['registrations']; ?></span></td>
                                <td class="text-center"><span class="badge bg-success"><?php echo $subject['allotments']; ?></span></td>
                                <td class="text-center">
                                    <?php 
                                    $available = $subject['intake'] - $subject['allotments'];
                                    $badge_color = $available > 0 ? 'info' : 'danger';
                                    ?>
                                    <span class="badge bg-<?php echo $badge_color; ?>"><?php echo $available; ?></span>
                                </td>
                                <td class="text-center">
                                    <?php if ($subject['allotments'] >= $subject['intake']): ?>
                                        <span class="badge bg-danger">FULL</span>
                                    <?php elseif ($subject['allotments'] > 0): ?>
                                        <span class="badge bg-warning">PARTIAL</span>
                                    <?php else: ?>
                                        <span class="badge bg-success">EMPTY</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Student Registration Details -->
        <div class="card">
            <div class="table-title">
                <h5 class="mb-0"><i class="fas fa-users me-2"></i>Student Registration & Allotment Details</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead>
                            <tr>
                                <th>Reg No</th>
                                <th>Pool</th>
                                <th>Priority Order</th>
                                <th>CGPA</th>
                                <th>Backlogs</th>
                                <th>Status</th>
                                <th>Allotted Subject</th>
                                <th>Allotment Reason</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($registrations as $reg): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($reg['regno']); ?></strong></td>
                                <td><?php echo htmlspecialchars($reg['pool_name']); ?></td>
                                <td>
                                    <?php 
                                    $priorities = json_decode($reg['priority_order'], true);
                                    if ($priorities) {
                                        foreach ($priorities as $p) {
                                            $badge_color = $p['priority'] == 1 ? 'primary' : 'secondary';
                                            echo '<span class="badge bg-' . $badge_color . ' priority-badge">';
                                            echo $p['priority'] . ': ' . htmlspecialchars($p['subject_code']);
                                            echo '</span> ';
                                        }
                                    }
                                    ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($reg['cgpa']): ?>
                                        <span class="badge bg-info"><?php echo number_format($reg['cgpa'], 2); ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($reg['backlogs'] !== null): ?>
                                        <span class="badge bg-<?php echo $reg['backlogs'] > 0 ? 'danger' : 'success'; ?>">
                                            <?php echo $reg['backlogs']; ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <span class="badge bg-<?php echo $reg['status'] == 'frozen' ? 'primary' : 'warning'; ?> status-badge">
                                        <?php echo strtoupper($reg['status']); ?>
                                    </span>
                                </td>
                                <td class="text-center">
                                    <?php if ($reg['allotted_subject']): ?>
                                        <span class="badge bg-success"><?php echo htmlspecialchars($reg['allotted_subject']); ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">NOT ALLOTTED</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($reg['allotment_reason']): ?>
                                        <small><?php echo htmlspecialchars($reg['allotment_reason']); ?></small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Report Footer -->
        <div class="text-center mt-4 text-muted">
            <small>Report generated on <?php echo date('F j, Y \a\t g:i A'); ?></small>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>