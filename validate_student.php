<?php
/**
 * Student Validation Configuration
 * This file contains functions to validate students against existing database
 * Configure this file according to your existing database structure
 */

/**
 * Validate student against existing user table
 * @param PDO $attendance_conn - Connection to existing database
 * @param string $regno - Student registration number
 * @return array|false - Student data if found, false otherwise
 */
function validateStudentInExistingDB($attendance_conn, $regno) {
    if (!$attendance_conn) {
        // If no connection to existing database, skip validation
        return ['exists' => false, 'message' => 'External validation not available'];
    }
    
    try {
        // CONFIGURE THIS QUERY ACCORDING TO YOUR EXISTING USER TABLE STRUCTURE
        // Current query matches your provided table structure
        $stmt = $attendance_conn->prepare("
            SELECT 
                regid as regno,
                name as student_name,
                email,
                mobile,
                programme,
                semester,
                batch,
                regulation,
                gender,
                residence_status
            FROM user 
            WHERE regid = ? 
            AND regid IS NOT NULL 
            AND regid != ''
        ");
        
        $stmt->execute([$regno]);
        $student = $stmt->fetch();
        
        if ($student) {
            return [
                'exists' => true,
                'data' => $student,
                'message' => 'Student found in database'
            ];
        } else {
            return [
                'exists' => false,
                'message' => 'Student not found in database'
            ];
        }
        
    } catch(Exception $e) {
        error_log("Student validation error: " . $e->getMessage());
        return [
            'exists' => false,
            'message' => 'Database validation error'
        ];
    }
}

/**
 * Check if student is eligible for a specific subject pool
 * @param array $student_data - Student data from existing database
 * @param array $pool_data - Subject pool data
 * @return array - Eligibility status and message
 */
function checkStudentEligibility($student_data, $pool_data) {
    if (!$student_data || !isset($student_data['data'])) {
        return [
            'eligible' => true, // Allow if external validation not available
            'message' => 'External validation not available, allowing registration'
        ];
    }
    
    $student = $student_data['data'];
    $allowed_programmes = json_decode($pool_data['allowed_programmes'], true) ?? [];
    
    // Check programme eligibility
    if (!empty($allowed_programmes) && !in_array($student['programme'], $allowed_programmes)) {
        return [
            'eligible' => false,
            'message' => "Your programme ({$student['programme']}) is not eligible for this subject pool. Allowed programmes: " . implode(', ', $allowed_programmes)
        ];
    }
    
    // Check semester match (optional - you can customize this)
    if (!empty($pool_data['semester']) && $student['semester'] !== $pool_data['semester']) {
        return [
            'eligible' => false,
            'message' => "Your semester ({$student['semester']}) does not match the pool semester ({$pool_data['semester']})"
        ];
    }
    
    // Check batch match (optional - you can customize this)
    if (!empty($pool_data['batch']) && $student['batch'] !== $pool_data['batch']) {
        return [
            'eligible' => false,
            'message' => "Your batch ({$student['batch']}) does not match the pool batch ({$pool_data['batch']})"
        ];
    }
    
    return [
        'eligible' => true,
        'message' => 'Student is eligible for this subject pool'
    ];
}

/**
 * Get additional student information for display
 * @param array $student_data - Student data from existing database
 * @return array - Formatted student information
 */
function getStudentDisplayInfo($student_data) {
    if (!$student_data || !isset($student_data['data'])) {
        return [];
    }
    
    $student = $student_data['data'];
    
    return [
        'name' => $student['student_name'] ?? 'N/A',
        'programme' => $student['programme'] ?? 'N/A',
        'semester' => $student['semester'] ?? 'N/A',
        'batch' => $student['batch'] ?? 'N/A',
        'regulation' => $student['regulation'] ?? 'N/A'
    ];
}

/**
 * Custom validation rules - Add your own validation logic here
 * @param string $regno - Registration number
 * @param string $email - Email address
 * @param string $mobile - Mobile number
 * @return array - Validation result
 */
function customValidationRules($regno, $email, $mobile) {
    $errors = [];
    
    // Validate registration number format (customize as needed)
    if (!preg_match('/^[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{4}$/', $regno)) {
        // This pattern matches: 20A21F0001 format
        // Modify the regex pattern according to your registration number format
        $errors[] = "Registration number format is invalid. Expected format: 20A21F0001";
    }
    
    // Validate email domain (optional - customize as needed)
    $allowed_domains = ['gmail.com', 'college.edu']; // Add your allowed domains
    $email_domain = substr(strrchr($email, "@"), 1);
    // Uncomment below lines if you want to restrict email domains
    /*
    if (!in_array($email_domain, $allowed_domains)) {
        $errors[] = "Email domain not allowed. Please use: " . implode(', ', $allowed_domains);
    }
    */
    
    // Validate mobile number (Indian format)
    if (!preg_match('/^[6-9][0-9]{9}$/', $mobile)) {
        $errors[] = "Mobile number must be a valid 10-digit Indian number starting with 6-9";
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

/**
 * Configuration settings for student validation
 */
class StudentValidationConfig {
    // Enable/disable external database validation
    public static $ENABLE_EXTERNAL_VALIDATION = true;
    
    // Require exact semester match
    public static $REQUIRE_SEMESTER_MATCH = false;
    
    // Require exact batch match  
    public static $REQUIRE_BATCH_MATCH = false;
    
    // Allow registration even if student not found in external database
    public static $ALLOW_UNVERIFIED_STUDENTS = false;
    
    // Enable custom validation rules
    public static $ENABLE_CUSTOM_VALIDATION = true;
    
    // Table name in existing database (if different from 'user')
    public static $USER_TABLE_NAME = 'user';
    
    // Column mappings (if your table has different column names)
    public static $COLUMN_MAPPINGS = [
        'regno' => 'regid',           // Your regno column name
        'name' => 'name',             // Student name column
        'email' => 'email',           // Email column
        'mobile' => 'mobile',         // Mobile column
        'programme' => 'programme',   // Programme column
        'semester' => 'semester',     // Semester column
        'batch' => 'batch',           // Batch column
        'regulation' => 'regulation'  // Regulation column
    ];
}
?>