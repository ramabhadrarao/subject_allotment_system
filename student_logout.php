<?php
// student_logout.php
require_once 'dbconfig.php';

if (isset($_SESSION['student_logged_in']) && $_SESSION['student_logged_in'] === true) {
    $student_regno = $_SESSION['student_regno'] ?? '';
    
    // Log logout activity
    log_login($conn, 'student', $student_regno, 'logout');
    log_activity($conn, 'student', $student_regno, 'logout');
    
    // Destroy session in database
    destroy_session($conn, 'student', $student_regno);
}

// Clear all session data
session_unset();
session_destroy();
session_start();

// Redirect to login page
header("Location: student_login.php");
exit();
?>