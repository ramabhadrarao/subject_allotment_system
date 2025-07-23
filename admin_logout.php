<?php
// admin_logout.php
require_once 'dbconfig.php';

if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    $admin_username = $_SESSION['admin_username'] ?? '';
    
    // Log logout activity
    log_login($conn, 'admin', $admin_username, 'logout');
    log_activity($conn, 'admin', $admin_username, 'logout');
    
    // Destroy session in database
    destroy_session($conn, 'admin', $admin_username);
}

// Clear all session data
session_unset();
session_destroy();
session_start();

// Redirect to login page
header("Location: admin_login.php");
exit();
?>
