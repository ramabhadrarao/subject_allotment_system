<?php
require_once 'dbconfig.php';

header('Content-Type: application/json');

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

// Validate CSRF token
if (!validate_csrf_token($_POST['csrf_token'] ?? '')) {
    log_security_event($conn, 'csrf_violation', 'medium', 'CSRF token validation failed for eligibility check');
    echo json_encode(['success' => false, 'message' => 'Security validation failed']);
    exit();
}

// Rate limiting
$client_ip = get_client_ip();
if (!check_rate_limit($conn, $client_ip, 'eligibility_check', 10, 300)) {
    log_security_event($conn, 'rate_limit_exceeded', 'medium', 'Eligibility check rate limit exceeded');
    echo json_encode(['success' => false, 'message' => 'Too many requests. Please try again later.']);
    exit();
}

$regno = strtoupper(trim($_POST['regno'] ?? ''));
$email = strtolower(trim($_POST['email'] ?? ''));
$mobile = trim(preg_replace('/\s+/', '', $_POST['mobile'] ?? '')); // Remove all spaces from mobile

// Normalize mobile to 10 digits
$mobile = preg_replace('/^(\+91|91)/', '', $mobile);

// Validate input
if (empty($regno) || empty($email) || empty($mobile)) {
    echo json_encode(['success' => false, 'message' => 'All fields are required']);
    exit();
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Invalid email format']);
    exit();
}

// More flexible mobile validation - allow 10 digits with optional country code
if (!preg_match('/^[6-9][0-9]{9}$/', $mobile)) {
    echo json_encode(['success' => false, 'message' => 'Mobile number must be 10 digits starting with 6-9']);
    exit();
}

try {
    // Check student in existing database
    if (!$attendance_conn) {
        echo json_encode(['success' => false, 'message' => 'Student database not available']);
        exit();
    }
    
    $stmt = $attendance_conn->prepare("
        SELECT 
            regid as regno,
            name,
            email,
            mobile,
            programme,
            semester,
            batch,
            regulation
        FROM user 
        WHERE regid = ?
    ");
    $stmt->execute([$regno]);
    $student = $stmt->fetch();
    
    if (!$student) {
        log_activity($conn, 'system', 'eligibility_check', 'student_not_found', null, null, null, ['regno' => $regno, 'ip' => $client_ip]);
        echo json_encode(['success' => false, 'message' => 'Registration number not found in student database']);
        exit();
    }
    
    // Validate email and mobile (normalize both for comparison)
    $student_email = trim(strtolower($student['email']));
    $student_mobile = trim(preg_replace('/\s+/', '', $student['mobile']));
    
    // Normalize student mobile to 10 digits if it has country code
    $student_mobile = preg_replace('/^(\+91|91)/', '', $student_mobile);
    
    // Debug logging
    error_log("Checking eligibility for: $regno");
    error_log("Input email: '$email' vs DB email: '$student_email'");
    error_log("Input mobile: '$mobile' vs DB mobile: '$student_mobile'");
    
    if ($student_email !== $email) {
        log_activity($conn, 'system', 'eligibility_check', 'email_mismatch', null, null, null, [
            'regno' => $regno, 
            'input_email' => $email, 
            'db_email' => $student_email,
            'ip' => $client_ip
        ]);
        echo json_encode([
            'success' => false, 
            'message' => 'Email address does not match our records'
        ]);
        exit();
    }
    
    if ($student_mobile !== $mobile) {
        log_activity($conn, 'system', 'eligibility_check', 'mobile_mismatch', null, null, null, [
            'regno' => $regno, 
            'input_mobile' => $mobile, 
            'db_mobile' => $student_mobile,
            'ip' => $client_ip
        ]);
        echo json_encode([
            'success' => false, 
            'message' => 'Mobile number does not match our records'
        ]);
        exit();
    }
    
    // Get all active subject pools (individual subjects, not grouped)
    $stmt = $conn->prepare("
        SELECT 
            id,
            pool_name,
            subject_name,
            subject_code,
            intake,
            semester,
            batch,
            allowed_programmes
        FROM subject_pools 
        WHERE is_active = 1 
        ORDER BY pool_name, subject_name
    ");
    $stmt->execute();
    $all_subjects = $stmt->fetchAll();
    
    // Find eligible subjects
    $eligible_subjects = [];
    foreach ($all_subjects as $subject) {
        $allowed_programmes = json_decode($subject['allowed_programmes'], true);
        
        if (in_array($student['programme'], $allowed_programmes) && 
            $subject['semester'] === $student['semester'] && 
            $subject['batch'] === $student['batch']) {
            $eligible_subjects[] = [
                'id' => $subject['id'],
                'pool_name' => $subject['pool_name'],
                'subject_name' => $subject['subject_name'],
                'subject_code' => $subject['subject_code'],
                'intake' => $subject['intake'],
                'semester' => $subject['semester'],
                'batch' => $subject['batch']
            ];
        }
    }
    
    // Group eligible subjects by pool name for display
    $eligible_pools_grouped = [];
    foreach ($eligible_subjects as $subject) {
        $pool_key = $subject['pool_name'] . '_' . $subject['semester'] . '_' . $subject['batch'];
        
        if (!isset($eligible_pools_grouped[$pool_key])) {
            $eligible_pools_grouped[$pool_key] = [
                'pool_name' => $subject['pool_name'],
                'semester' => $subject['semester'],
                'batch' => $subject['batch'],
                'subjects' => [],
                'pool_ids' => [],
                'id' => $subject['id'] // Use first subject's ID as default
            ];
        }
        
        $eligible_pools_grouped[$pool_key]['subjects'][] = $subject['subject_name'] . ' (' . $subject['subject_code'] . ')';
        $eligible_pools_grouped[$pool_key]['pool_ids'][] = $subject['id'];
    }
    
    // Convert to indexed array and format for frontend
    $eligible_pools_display = [];
    foreach ($eligible_pools_grouped as $group) {
        $eligible_pools_display[] = [
            'id' => $group['id'],
            'pool_name' => $group['pool_name'],
            'subjects' => implode(', ', $group['subjects']),
            'pool_ids' => implode(',', $group['pool_ids']),
            'semester' => $group['semester'],
            'batch' => $group['batch']
        ];
    }
    
    // Log successful check
    log_activity($conn, 'system', 'eligibility_check', 'successful_check', null, null, null, [
        'regno' => $regno,
        'eligible_subjects_count' => count($eligible_subjects),
        'ip' => $client_ip
    ]);
    
    echo json_encode([
        'success' => true,
        'student' => [
            'regno' => $student['regno'],
            'name' => $student['name'],
            'programme' => $student['programme'],
            'semester' => $student['semester'],
            'batch' => $student['batch'],
            'regulation' => $student['regulation']
        ],
        'eligible_pools' => $eligible_pools_display,
        'eligible_subjects' => $eligible_subjects,
        'message' => count($eligible_subjects) > 0 ? 
            'Student verified successfully. ' . count($eligible_subjects) . ' eligible subject(s) found.' :
            'Student verified but no eligible subjects found for your programme and semester.'
    ]);
    
} catch(Exception $e) {
    error_log("Eligibility check error: " . $e->getMessage());
    log_security_event($conn, 'eligibility_check_error', 'medium', 'Error during eligibility check: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred while checking eligibility']);
}
?>