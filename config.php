<?php
/**
 * SUBJECT ALLOTMENT SYSTEM CONFIGURATION
 * 
 * IMPORTANT: Configure these settings according to your environment
 * before deploying the system.
 */

// =============================================================================
// DATABASE CONFIGURATION
// =============================================================================

// Main Subject Allotment System Database
define('DB_HOST', 'localhost');
define('DB_USERNAME', 'subject_user');
define('DB_PASSWORD', 'S@UbJ#2025!');
define('DB_NAME', 'subject_allotment_system');

// Existing Student Database (camattendance) - FOR STUDENT VALIDATION
define('EXISTING_DB_HOST', 'localhost');           
define('EXISTING_DB_USERNAME', 'camatt');            
define('EXISTING_DB_PASSWORD', '#Swrn#2023????#@');                
define('EXISTING_DB_NAME', 'camattendance');

// =============================================================================
// STUDENT VALIDATION CONFIGURATION
// =============================================================================

// Enable/disable validation against existing database
define('ENABLE_EXTERNAL_VALIDATION', true);        // Set to false to disable

// Table and column configuration for existing database
define('EXISTING_USER_TABLE', 'user');             // Your user table name
define('EXISTING_REGNO_COLUMN', 'regid');          // Registration number column
define('EXISTING_NAME_COLUMN', 'name');            // Student name column
define('EXISTING_EMAIL_COLUMN', 'email');          // Email column
define('EXISTING_MOBILE_COLUMN', 'mobile');        // Mobile column
define('EXISTING_PROGRAMME_COLUMN', 'programme');  // Programme column
define('EXISTING_SEMESTER_COLUMN', 'semester');    // Semester column
define('EXISTING_BATCH_COLUMN', 'batch');          // Batch column

// Validation strictness settings
define('REQUIRE_STUDENT_IN_EXISTING_DB', false);   // Set true to require student exists in existing DB
define('REQUIRE_EXACT_SEMESTER_MATCH', false);     // Set true to enforce semester matching
define('REQUIRE_EXACT_BATCH_MATCH', false);        // Set true to enforce batch matching

// =============================================================================
// REGISTRATION NUMBER FORMAT VALIDATION
// =============================================================================

// Customize this regex pattern according to your registration number format
// Current pattern: 20A21F0001 (2 digits + letter + 2 digits + letter + 4 digits)
define('REGNO_VALIDATION_PATTERN', '/^[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{4}$/');

// Examples of other common patterns:
// For format like: 2020CSE001  → '/^[0-9]{4}[A-Z]{3}[0-9]{3}$/'
// For format like: 20-CSE-001  → '/^[0-9]{2}-[A-Z]{3}-[0-9]{3}$/'
// For format like: CSE20001    → '/^[A-Z]{3}[0-9]{5}$/'

// =============================================================================
// EMAIL AND MOBILE VALIDATION
// =============================================================================

// Allowed email domains (empty array = allow all domains)
$ALLOWED_EMAIL_DOMAINS = [
    // 'gmail.com',
    // 'yourcollege.edu',
    // 'students.yourdomain.com'
];

// Mobile number validation (Indian format by default)
define('MOBILE_VALIDATION_PATTERN', '/^[6-9][0-9]{9}$/');

// For other countries, use different patterns:
// US format: '/^[0-9]{10}$/'
// UK format: '/^[0-9]{11}$/'

// =============================================================================
// SECURITY SETTINGS
// =============================================================================

// Session timeout (in seconds) - Default: 30 minutes
define('SESSION_TIMEOUT', 1800);

// Login attempt limits
define('MAX_LOGIN_ATTEMPTS', 5);           // Max attempts per IP
define('LOGIN_ATTEMPT_WINDOW', 300);       // Time window in seconds (5 minutes)

// File upload limits
define('MAX_UPLOAD_SIZE', 5242880);        // 5MB in bytes
define('ALLOWED_UPLOAD_TYPES', ['csv', 'xlsx', 'xls']);

// =============================================================================
// SYSTEM BEHAVIOR SETTINGS
// =============================================================================

// Default admin credentials (CHANGE THESE!)
define('DEFAULT_ADMIN_USERNAME', 'admin');
define('DEFAULT_ADMIN_PASSWORD', 'admin123');  // ← CHANGE THIS!

// Timezone setting
define('SYSTEM_TIMEZONE', 'Asia/Kolkata');     // Adjust for your location

// Enable debug mode (set to false in production)
define('DEBUG_MODE', false);

// =============================================================================
// PROGRAMME CONFIGURATION
// =============================================================================

// Available programmes in your institution
$AVAILABLE_PROGRAMMES = [
    'B.Tech - AIML',
    'B.Tech - CSEDS',
    'B.Tech - CSEAIDS',
    'B.Tech - CSECS',
    'B.Tech - CSEBS',
    'B.Tech - Civil',
    'B.Tech - EEE',
    'B.Tech - Mech',
    'B.Tech - ECE',
    'B.Tech - CSE',
    'B.Tech - IT',
    'B.Tech - Robotics'
   
];

// Available semesters
$AVAILABLE_SEMESTERS = [
    'First Semester',
    'Second Semester', 
    'Third Semester',
    'Fourth Semester',
    'Fifth Semester',
    'Sixth Semester',
    'Seventh Semester',
    'Eight Semester'
];

// =============================================================================
// ALLOTMENT ALGORITHM CONFIGURATION
// =============================================================================

// Priority weights (higher number = higher priority)
define('CGPA_PRIORITY_WEIGHT', 3);         // Students with CGPA, no backlogs
define('BACKLOG_PRIORITY_WEIGHT', 2);      // Students with backlogs
define('DEFAULT_PRIORITY_WEIGHT', 1);      // Others

// CGPA range validation
define('MIN_CGPA', 0.0);
define('MAX_CGPA', 10.0);

// =============================================================================
// CUSTOM FUNCTIONS FOR SPECIFIC REQUIREMENTS
// =============================================================================

/**
 * Custom student validation function
 * Modify this function to add your specific validation logic
 */
function customStudentValidation($regno, $email, $mobile, $existing_student_data = null) {
    $errors = [];
    
    // Add your custom validation logic here
    // Example: Check if regno belongs to current academic year
    /*
    $current_year = date('y');
    if (substr($regno, 0, 2) !== $current_year) {
        $errors[] = "Registration number must belong to current academic year (20$current_year)";
    }
    */
    
    // Example: Validate email format more strictly
    /*
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    */
    
    // Example: Check mobile number belongs to student in existing database
    /*
    if ($existing_student_data && isset($existing_student_data['mobile'])) {
        if ($mobile !== $existing_student_data['mobile']) {
            $errors[] = "Mobile number does not match records";
        }
    }
    */
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

/**
 * Custom eligibility check function
 * Modify this to add specific eligibility criteria
 */
function customEligibilityCheck($student_data, $pool_data) {
    $eligible = true;
    $message = '';
    
    // Add your custom eligibility logic here
    // Example: Check if student has minimum CGPA for certain subjects
    /*
    if (isset($student_data['cgpa']) && $pool_data['subject_code'] === 'ADVANCED_ALGO') {
        if ($student_data['cgpa'] < 7.5) {
            $eligible = false;
            $message = "Minimum CGPA of 7.5 required for Advanced Algorithms";
        }
    }
    */
    
    // Example: Restrict certain subjects to specific regulations
    /*
    if (isset($student_data['regulation']) && $pool_data['subject_code'] === 'ML_ADVANCED') {
        if (!in_array($student_data['regulation'], ['R20', 'R21'])) {
            $eligible = false;
            $message = "This subject is only available for R20 and R21 regulations";
        }
    }
    */
    
    return [
        'eligible' => $eligible,
        'message' => $message
    ];
}

// =============================================================================
// DO NOT MODIFY BELOW THIS LINE (System Constants)
// =============================================================================

// Set timezone
date_default_timezone_set(SYSTEM_TIMEZONE);

// Error reporting based on debug mode
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Export configuration for use in other files
$SYSTEM_CONFIG = [
    'db' => [
        'host' => DB_HOST,
        'username' => DB_USERNAME,
        'password' => DB_PASSWORD,
        'name' => DB_NAME
    ],
    'existing_db' => [
        'host' => EXISTING_DB_HOST,
        'username' => EXISTING_DB_USERNAME,
        'password' => EXISTING_DB_PASSWORD,
        'name' => EXISTING_DB_NAME,
        'table' => EXISTING_USER_TABLE,
        'columns' => [
            'regno' => EXISTING_REGNO_COLUMN,
            'name' => EXISTING_NAME_COLUMN,
            'email' => EXISTING_EMAIL_COLUMN,
            'mobile' => EXISTING_MOBILE_COLUMN,
            'programme' => EXISTING_PROGRAMME_COLUMN,
            'semester' => EXISTING_SEMESTER_COLUMN,
            'batch' => EXISTING_BATCH_COLUMN
        ]
    ],
    'validation' => [
        'enable_external' => ENABLE_EXTERNAL_VALIDATION,
        'require_existing_student' => REQUIRE_STUDENT_IN_EXISTING_DB,
        'require_semester_match' => REQUIRE_EXACT_SEMESTER_MATCH,
        'require_batch_match' => REQUIRE_EXACT_BATCH_MATCH,
        'regno_pattern' => REGNO_VALIDATION_PATTERN,
        'mobile_pattern' => MOBILE_VALIDATION_PATTERN,
        'allowed_email_domains' => $ALLOWED_EMAIL_DOMAINS
    ],
    'programmes' => $AVAILABLE_PROGRAMMES,
    'semesters' => $AVAILABLE_SEMESTERS
];
?>