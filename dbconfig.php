<?php
// Include configuration settings
require_once 'config.php';

// Database Configuration - Main System Database
$servername = DB_HOST;
$username = DB_USERNAME;
$password = DB_PASSWORD;
$dbname = DB_NAME;

try {
    $conn = new PDO("mysql:host=$servername;dbname=$dbname", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// For accessing existing camattendance database
// UPDATE THESE SETTINGS IN config.php FILE
$existing_db_host = EXISTING_DB_HOST;        
$existing_db_username = EXISTING_DB_USERNAME;         
$existing_db_password = EXISTING_DB_PASSWORD;             
$existing_db_name = EXISTING_DB_NAME;    

try {
    $attendance_conn = new PDO("mysql:host=$existing_db_host;dbname=$existing_db_name", $existing_db_username, $existing_db_password);
    $attendance_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $attendance_conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Test connection and log success
    if (DEBUG_MODE) {
        error_log("Successfully connected to existing database: $existing_db_name");
    }
    
} catch(PDOException $e) {
    // Log connection error but don't stop the system
    error_log("Could not connect to existing database ($existing_db_name): " . $e->getMessage());
    $attendance_conn = null;
    
    // If external validation is required but DB is not available, show warning
    if (ENABLE_EXTERNAL_VALIDATION && REQUIRE_STUDENT_IN_EXISTING_DB) {
        error_log("WARNING: External validation is required but existing database is not accessible!");
    }
}

// Security and utility functions
function get_client_ip() {
    $ipkeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    foreach ($ipkeys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function generate_token($length = 32) {
    return bin2hex(random_bytes($length));
}

function log_activity($conn, $user_type, $user_identifier, $action, $table_name = null, $record_id = null, $old_values = null, $new_values = null) {
    try {
        $stmt = $conn->prepare("INSERT INTO activity_logs (user_type, user_identifier, action, table_name, record_id, old_values, new_values, ip_address, user_agent) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $user_type,
            $user_identifier,
            $action,
            $table_name,
            $record_id,
            $old_values ? json_encode($old_values) : null,
            $new_values ? json_encode($new_values) : null,
            get_client_ip(),
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch(Exception $e) {
        error_log("Activity log error: " . $e->getMessage());
    }
}

function log_login($conn, $user_type, $user_identifier, $action) {
    try {
        $stmt = $conn->prepare("INSERT INTO login_logs (user_type, user_identifier, action, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $user_type,
            $user_identifier,
            $action,
            get_client_ip(),
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch(Exception $e) {
        error_log("Login log error: " . $e->getMessage());
    }
}

function log_security_event($conn, $event_type, $severity, $description, $user_identifier = null) {
    try {
        $stmt = $conn->prepare("INSERT INTO security_logs (event_type, severity, user_identifier, ip_address, description, user_agent) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $event_type,
            $severity,
            $user_identifier,
            get_client_ip(),
            $description,
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch(Exception $e) {
        error_log("Security log error: " . $e->getMessage());
    }
}

function prevent_resubmit($conn, $user_type, $user_identifier, $form_type) {
    if (!isset($_POST['form_token'])) {
        return false;
    }
    
    $form_token = $_POST['form_token'];
    
    try {
        // Check if token already used
        $stmt = $conn->prepare("SELECT id FROM form_submissions WHERE form_token = ?");
        $stmt->execute([$form_token]);
        
        if ($stmt->rowCount() > 0) {
            return false; // Token already used
        }
        
        // Record the token
        $stmt = $conn->prepare("INSERT INTO form_submissions (form_token, user_type, user_identifier, form_type, ip_address) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$form_token, $user_type, $user_identifier, $form_type, get_client_ip()]);
        
        return true;
    } catch(Exception $e) {
        error_log("Form token error: " . $e->getMessage());
        return false;
    }
}

function create_session($conn, $user_type, $user_identifier) {
    $session_id = session_id();
    $expires_at = date('Y-m-d H:i:s', time() + (30 * 60)); // 30 minutes
    
    try {
        // Deactivate old sessions
        $stmt = $conn->prepare("UPDATE user_sessions SET is_active = 0 WHERE user_type = ? AND user_identifier = ?");
        $stmt->execute([$user_type, $user_identifier]);
        
        // Create new session
        $stmt = $conn->prepare("INSERT INTO user_sessions (session_id, user_type, user_identifier, ip_address, user_agent, expires_at) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $session_id,
            $user_type,
            $user_identifier,
            get_client_ip(),
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $expires_at
        ]);
    } catch(Exception $e) {
        error_log("Session creation error: " . $e->getMessage());
    }
}

function validate_session($conn, $user_type, $user_identifier) {
    $session_id = session_id();
    
    try {
        $stmt = $conn->prepare("SELECT id FROM user_sessions WHERE session_id = ? AND user_type = ? AND user_identifier = ? AND is_active = 1 AND expires_at > NOW()");
        $stmt->execute([$session_id, $user_type, $user_identifier]);
        
        if ($stmt->rowCount() > 0) {
            // Update last activity
            $stmt = $conn->prepare("UPDATE user_sessions SET last_activity = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE session_id = ?");
            $stmt->execute([$session_id]);
            return true;
        }
        
        return false;
    } catch(Exception $e) {
        error_log("Session validation error: " . $e->getMessage());
        return false;
    }
}

function destroy_session($conn, $user_type, $user_identifier) {
    $session_id = session_id();
    
    try {
        $stmt = $conn->prepare("UPDATE user_sessions SET is_active = 0 WHERE session_id = ? AND user_type = ? AND user_identifier = ?");
        $stmt->execute([$session_id, $user_type, $user_identifier]);
    } catch(Exception $e) {
        error_log("Session destruction error: " . $e->getMessage());
    }
}

// Clean up expired sessions (call this periodically)
function cleanup_sessions($conn) {
    try {
        $stmt = $conn->prepare("UPDATE user_sessions SET is_active = 0 WHERE expires_at < NOW()");
        $stmt->execute();
    } catch(Exception $e) {
        error_log("Session cleanup error: " . $e->getMessage());
    }
}

// CSRF Protection
function generate_csrf_token() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Rate limiting
function check_rate_limit($conn, $identifier, $action, $limit = 5, $window = 300) {
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM activity_logs WHERE user_identifier = ? AND action = ? AND timestamp > DATE_SUB(NOW(), INTERVAL ? SECOND)");
        $stmt->execute([$identifier, $action, $window]);
        $result = $stmt->fetch();
        
        return $result['count'] < $limit;
    } catch(Exception $e) {
        error_log("Rate limit check error: " . $e->getMessage());
        return true; // Allow on error
    }
}

// Start session with security settings
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
ini_set('session.use_strict_mode', 1);
session_start();

// Clean up expired sessions
cleanup_sessions($conn);
?>