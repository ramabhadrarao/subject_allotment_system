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
$upload_results = [];

// Handle file upload
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['student_data_file'])) {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        log_security_event($conn, 'csrf_violation', 'high', 'CSRF token validation failed for student data upload', $_SESSION['admin_username']);
        $error_message = 'Security validation failed. Please try again.';
    } else if (!prevent_resubmit($conn, 'admin', $_SESSION['admin_username'], 'upload_student_data')) {
        $error_message = 'File already being processed. Please refresh the page.';
    } else {
        $file = $_FILES['student_data_file'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $error_message = 'File upload failed. Please try again.';
        } else if (!in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), ['csv', 'xlsx', 'xls'])) {
            $error_message = 'Please upload a CSV or Excel file.';
        } else if ($file['size'] > 5 * 1024 * 1024) { // 5MB limit
            $error_message = 'File size must be less than 5MB.';
        } else {
            try {
                $upload_dir = 'uploads/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $filename = uniqid() . '_' . basename($file['name']);
                $filepath = $upload_dir . $filename;
                
                if (move_uploaded_file($file['tmp_name'], $filepath)) {
                    $processed_count = 0;
                    $error_count = 0;
                    $update_count = 0;
                    $errors = [];
                    
                    // Process CSV file
                    if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) == 'csv') {
                        if (($handle = fopen($filepath, "r")) !== FALSE) {
                            $header = fgetcsv($handle, 1000, ",");
                            $expected_headers = ['regno', 'cgpa', 'backlogs'];
                            
                            // Validate headers
                            $header_lower = array_map('strtolower', array_map('trim', $header));
                            $missing_headers = array_diff($expected_headers, $header_lower);
                            
                            if (!empty($missing_headers)) {
                                $error_message = 'Missing required columns: ' . implode(', ', $missing_headers);
                            } else {
                                // Map headers to positions
                                $header_map = array_flip($header_lower);
                                
                                $conn->beginTransaction();
                                
                                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                                    $processed_count++;
                                    
                                    $regno = strtoupper(trim($data[$header_map['regno']] ?? ''));
                                    $cgpa = trim($data[$header_map['cgpa']] ?? '');
                                    $backlogs = trim($data[$header_map['backlogs']] ?? '');
                                    
                                    // Validate data
                                    if (empty($regno)) {
                                        $errors[] = "Row $processed_count: Registration number is required";
                                        $error_count++;
                                        continue;
                                    }
                                    
                                    // Convert CGPA
                                    $cgpa_value = null;
                                    if (!empty($cgpa) && is_numeric($cgpa)) {
                                        $cgpa_value = floatval($cgpa);
                                        if ($cgpa_value < 0 || $cgpa_value > 10) {
                                            $errors[] = "Row $processed_count: CGPA must be between 0 and 10";
                                            $error_count++;
                                            continue;
                                        }
                                    }
                                    
                                    // Convert backlogs
                                    $backlogs_value = 0;
                                    if (!empty($backlogs) && is_numeric($backlogs)) {
                                        $backlogs_value = intval($backlogs);
                                        if ($backlogs_value < 0) {
                                            $errors[] = "Row $processed_count: Backlogs cannot be negative";
                                            $error_count++;
                                            continue;
                                        }
                                    }
                                    
                                    // Check if record exists
                                    $stmt = $conn->prepare("SELECT id FROM student_academic_data WHERE regno = ?");
                                    $stmt->execute([$regno]);
                                    $existing = $stmt->fetch();
                                    
                                    if ($existing) {
                                        // Update existing record
                                        $stmt = $conn->prepare("UPDATE student_academic_data SET cgpa = ?, backlogs = ?, uploaded_by = ?, uploaded_at = NOW(), uploaded_ip = ? WHERE regno = ?");
                                        $stmt->execute([$cgpa_value, $backlogs_value, $_SESSION['admin_id'], get_client_ip(), $regno]);
                                        $update_count++;
                                    } else {
                                        // Insert new record
                                        $stmt = $conn->prepare("INSERT INTO student_academic_data (regno, cgpa, backlogs, uploaded_by, uploaded_ip) VALUES (?, ?, ?, ?, ?)");
                                        $stmt->execute([$regno, $cgpa_value, $backlogs_value, $_SESSION['admin_id'], get_client_ip()]);
                                    }
                                }
                                
                                $conn->commit();
                                fclose($handle);
                                
                                $success_count = $processed_count - $error_count;
                                $success_message = "File processed successfully! $success_count records processed, $update_count updated. ";
                                if ($error_count > 0) {
                                    $success_message .= "$error_count errors encountered.";
                                }
                                
                                $upload_results = [
                                    'total' => $processed_count,
                                    'success' => $success_count,
                                    'updated' => $update_count,
                                    'errors' => $error_count,
                                    'error_details' => array_slice($errors, 0, 10) // Show first 10 errors
                                ];
                                
                                log_activity($conn, 'admin', $_SESSION['admin_username'], 'student_data_uploaded', 'student_academic_data', null, null, $upload_results);
                            }
                        } else {
                            $error_message = 'Unable to read the uploaded file.';
                        }
                    } else {
                        // Handle Excel files (basic implementation)
                        $error_message = 'Excel file processing not yet implemented. Please use CSV format.';
                    }
                    
                    // Clean up uploaded file
                    unlink($filepath);
                } else {
                    $error_message = 'Failed to save uploaded file.';
                }
            } catch(Exception $e) {
                if (isset($conn) && $conn->inTransaction()) {
                    $conn->rollBack();
                }
                error_log("Student data upload error: " . $e->getMessage());
                $error_message = 'An error occurred while processing the file: ' . $e->getMessage();
            }
        }
    }
}

// Handle individual student entry
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'add_individual') {
    if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
        log_security_event($conn, 'csrf_violation', 'high', 'CSRF token validation failed for individual student entry', $_SESSION['admin_username']);
        $error_message = 'Security validation failed. Please try again.';
    } else if (!prevent_resubmit($conn, 'admin', $_SESSION['admin_username'], 'add_individual_student')) {
        $error_message = 'Data already submitted. Please refresh the page.';
    } else {
        $regno = strtoupper(trim($_POST['regno'] ?? ''));
        $cgpa = trim($_POST['cgpa'] ?? '');
        $backlogs = trim($_POST['backlogs'] ?? '');
        
        if (empty($regno)) {
            $error_message = 'Registration number is required.';
        } else {
            $cgpa_value = null;
            if (!empty($cgpa)) {
                if (!is_numeric($cgpa)) {
                    $error_message = 'CGPA must be a valid number.';
                } else {
                    $cgpa_value = floatval($cgpa);
                    if ($cgpa_value < 0 || $cgpa_value > 10) {
                        $error_message = 'CGPA must be between 0 and 10.';
                    }
                }
            }
            
            $backlogs_value = 0;
            if (!empty($backlogs)) {
                if (!is_numeric($backlogs)) {
                    $error_message = 'Backlogs must be a valid number.';
                } else {
                    $backlogs_value = intval($backlogs);
                    if ($backlogs_value < 0) {
                        $error_message = 'Backlogs cannot be negative.';
                    }
                }
            }
            
            if (empty($error_message)) {
                try {
                    // Check if record exists
                    $stmt = $conn->prepare("SELECT id FROM student_academic_data WHERE regno = ?");
                    $stmt->execute([$regno]);
                    $existing = $stmt->fetch();
                    
                    if ($existing) {
                        $stmt = $conn->prepare("UPDATE student_academic_data SET cgpa = ?, backlogs = ?, uploaded_by = ?, uploaded_at = NOW(), uploaded_ip = ? WHERE regno = ?");
                        $stmt->execute([$cgpa_value, $backlogs_value, $_SESSION['admin_id'], get_client_ip(), $regno]);
                        $success_message = 'Student data updated successfully!';
                    } else {
                        $stmt = $conn->prepare("INSERT INTO student_academic_data (regno, cgpa, backlogs, uploaded_by, uploaded_ip) VALUES (?, ?, ?, ?, ?)");
                        $stmt->execute([$regno, $cgpa_value, $backlogs_value, $_SESSION['admin_id'], get_client_ip()]);
                        $success_message = 'Student data added successfully!';
                    }
                    
                    log_activity($conn, 'admin', $_SESSION['admin_username'], 'individual_student_data_added', 'student_academic_data', null, null, ['regno' => $regno, 'cgpa' => $cgpa_value, 'backlogs' => $backlogs_value]);
                } catch(Exception $e) {
                    error_log("Individual student data error: " . $e->getMessage());
                    $error_message = 'An error occurred while saving student data.';
                }
            }
        }
    }
}

// Get existing student data
try {
    $stmt = $conn->prepare("
        SELECT 
            sad.*,
            a.name as uploaded_by_name
        FROM student_academic_data sad
        LEFT JOIN admin a ON sad.uploaded_by = a.id
        ORDER BY sad.uploaded_at DESC
        LIMIT 100
    ");
    $stmt->execute();
    $student_data = $stmt->fetchAll();
    
    // Get statistics
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM student_academic_data");
    $stmt->execute();
    $total_students = $stmt->fetch()['total'];
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM student_academic_data WHERE cgpa IS NOT NULL");
    $stmt->execute();
    $students_with_cgpa = $stmt->fetch()['count'];
    
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM student_academic_data WHERE backlogs > 0");
    $stmt->execute();
    $students_with_backlogs = $stmt->fetch()['count'];
    
} catch(Exception $e) {
    $student_data = [];
    $total_students = $students_with_cgpa = $students_with_backlogs = 0;
}

$csrf_token = generate_csrf_token();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Student Data - Subject Allotment System</title>
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
        .upload-card {
            border-left: 5px solid #28a745;
        }
        .stats-card {
            border-left: 5px solid #007bff;
        }
        .data-card {
            border-left: 5px solid #ffc107;
        }
        .individual-card {
            border-left: 5px solid #dc3545;
        }
        .upload-area {
            border: 2px dashed #28a745;
            border-radius: 10px;
            padding: 40px;
            text-align: center;
            background: #f8fff8;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .upload-area:hover {
            background: #f0fff0;
            border-color: #20c997;
        }
        .upload-area.dragover {
            background: #e8f5e8;
            border-color: #20c997;
        }
        .file-info {
            background: #e3f2fd;
            border-radius: 8px;
            padding: 10px;
            margin-top: 10px;
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
            <h2><i class="fas fa-upload me-2"></i>Upload Student Academic Data</h2>
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

        <!-- Upload Results -->
        <?php if (!empty($upload_results)): ?>
        <div class="alert alert-info">
            <h5><i class="fas fa-chart-bar me-2"></i>Upload Results</h5>
            <div class="row">
                <div class="col-md-3">
                    <strong>Total Processed:</strong> <?php echo $upload_results['total']; ?>
                </div>
                <div class="col-md-3">
                    <strong>Successful:</strong> <?php echo $upload_results['success']; ?>
                </div>
                <div class="col-md-3">
                    <strong>Updated:</strong> <?php echo $upload_results['updated']; ?>
                </div>
                <div class="col-md-3">
                    <strong>Errors:</strong> <?php echo $upload_results['errors']; ?>
                </div>
            </div>
            <?php if (!empty($upload_results['error_details'])): ?>
            <hr>
            <h6>Error Details:</h6>
            <ul class="mb-0">
                <?php foreach ($upload_results['error_details'] as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="row">
            <!-- Left Column -->
            <div class="col-lg-8">
                <!-- File Upload Card -->
                <div class="card upload-card mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-file-upload me-2"></i>Bulk Upload via CSV File
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" enctype="multipart/form-data" id="uploadForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="form_token" value="<?php echo generate_token(); ?>">
                            
                            <div class="upload-area" onclick="document.getElementById('fileInput').click()">
                                <i class="fas fa-cloud-upload-alt fa-3x text-success mb-3"></i>
                                <h5>Click to select file or drag and drop</h5>
                                <p class="text-muted mb-0">CSV files only (Max 5MB)</p>
                                <input type="file" id="fileInput" name="student_data_file" accept=".csv" style="display: none;" required>
                            </div>
                            
                            <div id="fileInfo" class="file-info d-none">
                                <strong>Selected file:</strong> <span id="fileName"></span><br>
                                <strong>Size:</strong> <span id="fileSize"></span>
                            </div>
                            
                            <div class="mt-3">
                                <button type="submit" class="btn btn-success me-2" id="uploadBtn" disabled>
                                    <i class="fas fa-upload me-2"></i>Upload File
                                </button>
                                <button type="button" class="btn btn-outline-info" data-bs-toggle="modal" data-bs-target="#formatModal">
                                    <i class="fas fa-info-circle me-2"></i>Required Format
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Individual Entry Card -->
                <div class="card individual-card">
                    <div class="card-header bg-danger text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-user-plus me-2"></i>Add Individual Student Data
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="" id="individualForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            <input type="hidden" name="form_token" value="<?php echo generate_token(); ?>">
                            <input type="hidden" name="action" value="add_individual">
                            
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="regno" class="form-label">Registration Number</label>
                                        <input type="text" class="form-control" id="regno" name="regno" required style="text-transform: uppercase;">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="cgpa" class="form-label">CGPA</label>
                                        <input type="number" class="form-control" id="cgpa" name="cgpa" step="0.01" min="0" max="10" placeholder="e.g., 8.5">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="mb-3">
                                        <label for="backlogs" class="form-label">Backlogs</label>
                                        <input type="number" class="form-control" id="backlogs" name="backlogs" min="0" value="0">
                                    </div>
                                </div>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-danger">
                                    <i class="fas fa-save me-2"></i>Add/Update Student Data
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Right Column -->
            <div class="col-lg-4">
                <!-- Statistics Card -->
                <div class="card stats-card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-chart-pie me-2"></i>Statistics
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row text-center">
                            <div class="col-12 mb-3">
                                <h3 class="text-primary"><?php echo $total_students; ?></h3>
                                <small class="text-muted">Total Students</small>
                            </div>
                            <div class="col-6">
                                <h4 class="text-success"><?php echo $students_with_cgpa; ?></h4>
                                <small class="text-muted">With CGPA</small>
                            </div>
                            <div class="col-6">
                                <h4 class="text-warning"><?php echo $students_with_backlogs; ?></h4>
                                <small class="text-muted">With Backlogs</small>
                            </div>
                        </div>
                        
                        <?php if ($total_students > 0): ?>
                        <hr>
                        <div class="text-center">
                            <strong>Coverage:</strong><br>
                            CGPA: <?php echo round(($students_with_cgpa / $total_students) * 100, 1); ?>%<br>
                            Backlogs: <?php echo round(($students_with_backlogs / $total_students) * 100, 1); ?>%
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-bolt me-2"></i>Quick Actions
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="#" class="btn btn-outline-primary" onclick="downloadTemplate()">
                                <i class="fas fa-download me-2"></i>Download CSV Template
                            </a>
                            <a href="student_registrations.php" class="btn btn-outline-success">
                                <i class="fas fa-users me-2"></i>View Registrations
                            </a>
                            <a href="run_allotment.php" class="btn btn-outline-warning">
                                <i class="fas fa-cogs me-2"></i>Run Allotment
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Data -->
        <div class="card data-card mt-4">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0">
                    <i class="fas fa-database me-2"></i>Recent Student Data (Last 100 entries)
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($student_data)): ?>
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-database fa-3x mb-3"></i>
                        <h5>No Student Data Found</h5>
                        <p>Upload your first CSV file or add individual student data to get started.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover" id="studentDataTable">
                            <thead class="table-light">
                                <tr>
                                    <th>Registration No</th>
                                    <th>CGPA</th>
                                    <th>Backlogs</th>
                                    <th>Uploaded By</th>
                                    <th>Upload Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($student_data as $data): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($data['regno']); ?></strong></td>
                                    <td>
                                        <?php if ($data['cgpa'] !== null): ?>
                                            <span class="badge bg-success"><?php echo number_format($data['cgpa'], 2); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?php echo $data['backlogs'] > 0 ? 'bg-danger' : 'bg-success'; ?>">
                                            <?php echo $data['backlogs']; ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($data['uploaded_by_name'] ?? 'Unknown'); ?></td>
                                    <td><?php echo date('M j, Y h:i A', strtotime($data['uploaded_at'])); ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="editStudentData('<?php echo htmlspecialchars($data['regno']); ?>', <?php echo $data['cgpa'] ?? 'null'; ?>, <?php echo $data['backlogs']; ?>)">
                                            <i class="fas fa-edit"></i>
                                        </button>
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

    <!-- CSV Format Modal -->
    <div class="modal fade" id="formatModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-file-csv me-2"></i>Required CSV Format
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle me-2"></i>CSV File Requirements:</h6>
                        <ul class="mb-0">
                            <li>File must be in CSV format (.csv)</li>
                            <li>First row must contain column headers</li>
                            <li>Required columns: <strong>regno, cgpa, backlogs</strong></li>
                            <li>Column names are case-insensitive</li>
                            <li>CGPA should be between 0 and 10 (can be empty)</li>
                            <li>Backlogs should be 0 or positive integer (can be empty, defaults to 0)</li>
                        </ul>
                    </div>
                    
                    <h6>Sample CSV Content:</h6>
                    <div class="bg-light p-3 rounded">
                        <code>
                            regno,cgpa,backlogs<br>
                            20A21F0001,8.75,0<br>
                            20A21F0002,7.50,2<br>
                            20A21F0003,,1<br>
                            20A21F0004,9.25,
                        </code>
                    </div>
                    
                    <div class="mt-3">
                        <h6>Column Descriptions:</h6>
                        <ul>
                            <li><strong>regno:</strong> Student registration number (required, case-insensitive)</li>
                            <li><strong>cgpa:</strong> Cumulative Grade Point Average (optional, 0-10)</li>
                            <li><strong>backlogs:</strong> Number of backlog subjects (optional, defaults to 0)</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="downloadTemplate()">
                        <i class="fas fa-download me-2"></i>Download Template
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Student Data Modal -->
    <div class="modal fade" id="editStudentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i>Edit Student Data
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="" id="editStudentForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="form_token" value="<?php echo generate_token(); ?>">
                    <input type="hidden" name="action" value="add_individual">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="edit_regno" class="form-label">Registration Number</label>
                            <input type="text" class="form-control" id="edit_regno" name="regno" readonly>
                        </div>
                        <div class="mb-3">
                            <label for="edit_cgpa" class="form-label">CGPA</label>
                            <input type="number" class="form-control" id="edit_cgpa" name="cgpa" step="0.01" min="0" max="10">
                        </div>
                        <div class="mb-3">
                            <label for="edit_backlogs" class="form-label">Backlogs</label>
                            <input type="number" class="form-control" id="edit_backlogs" name="backlogs" min="0">
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-2"></i>Update Data
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#studentDataTable').DataTable({
                responsive: true,
                pageLength: 25,
                order: [[4, 'desc']], // Sort by upload date desc
                columnDefs: [
                    { targets: [-1], orderable: false } // Disable sorting for Actions column
                ]
            });
        });

        // File upload handling
        const fileInput = document.getElementById('fileInput');
        const uploadArea = document.querySelector('.upload-area');
        const fileInfo = document.getElementById('fileInfo');
        const uploadBtn = document.getElementById('uploadBtn');

        fileInput.addEventListener('change', handleFileSelect);

        // Drag and drop
        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.classList.add('dragover');
        });

        uploadArea.addEventListener('dragleave', () => {
            uploadArea.classList.remove('dragover');
        });

        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                fileInput.files = files;
                handleFileSelect();
            }
        });

        function handleFileSelect() {
            const file = fileInput.files[0];
            if (file) {
                if (file.type !== 'text/csv' && !file.name.toLowerCase().endsWith('.csv')) {
                    alert('Please select a CSV file.');
                    fileInput.value = '';
                    return;
                }
                
                if (file.size > 5 * 1024 * 1024) {
                    alert('File size must be less than 5MB.');
                    fileInput.value = '';
                    return;
                }
                
                document.getElementById('fileName').textContent = file.name;
                document.getElementById('fileSize').textContent = formatFileSize(file.size);
                fileInfo.classList.remove('d-none');
                uploadBtn.disabled = false;
            } else {
                fileInfo.classList.add('d-none');
                uploadBtn.disabled = true;
            }
        }

        function formatFileSize(bytes) {
            if (bytes === 0) return '0 Bytes';
            const k = 1024;
            const sizes = ['Bytes', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
        }

        // Auto uppercase registration numbers
        document.getElementById('regno').addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });

        // Form submission handlers
        document.getElementById('uploadForm').addEventListener('submit', function() {
            const submitBtn = uploadBtn;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Uploading...';
            submitBtn.disabled = true;
        });

        document.getElementById('individualForm').addEventListener('submit', function() {
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Saving...';
            submitBtn.disabled = true;
        });

        // Edit student data
        function editStudentData(regno, cgpa, backlogs) {
            document.getElementById('edit_regno').value = regno;
            document.getElementById('edit_cgpa').value = cgpa || '';
            document.getElementById('edit_backlogs').value = backlogs || 0;
            
            const modal = new bootstrap.Modal(document.getElementById('editStudentModal'));
            modal.show();
        }

        // Download CSV template
        function downloadTemplate() {
            const csvContent = 'regno,cgpa,backlogs\n20A21F0001,8.75,0\n20A21F0002,7.50,2\n20A21F0003,,1\n20A21F0004,9.25,';
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.style.display = 'none';
            a.href = url;
            a.download = 'student_data_template.csv';
            document.body.appendChild(a);
            a.click();
            window.URL.revokeObjectURL(url);
            document.body.removeChild(a);
        }

        // Prevent form resubmission on page refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>