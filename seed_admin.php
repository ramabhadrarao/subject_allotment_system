<?php
/**
 * Admin User Seeder Script
 * Run this script once to create the initial admin user
 * 
 * Usage: php seed_admin.php
 * Or access via browser: http://yourserver/seed_admin.php
 */

require_once 'config.php';

// Admin user configurations
$admin_users = [
    [
        'username' => 'admin',
        'password' => 'Swrn#Admin@2025',  // Change this to your desired password
        'email' => 'admin@swarnandhra.ac.in',
        'name' => 'System Administrator'
    ],
    [
        'username' => 'subjectadmin',
        'password' => 'Swrn#Admin@2025',  // Additional admin user
        'email' => 'subject.admin@swarnandhra.ac.in', 
        'name' => 'Subject Pool Administrator'
    ]
];

try {
    // Connect to database
    $conn = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USERNAME, DB_PASSWORD);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>?? Admin User Seeder</h2>\n";
    echo "<pre>\n";
    
    // Check if admin table exists
    $stmt = $conn->prepare("SHOW TABLES LIKE 'admin'");
    $stmt->execute();
    
    if ($stmt->rowCount() == 0) {
        echo "? Admin table does not exist. Please run the database schema first.\n";
        exit;
    }
    
    echo "? Admin table found.\n\n";
    
    // Process each admin user
    foreach ($admin_users as $admin) {
        echo "Processing admin user: {$admin['username']}\n";
        
        // Check if user already exists
        $stmt = $conn->prepare("SELECT id FROM admin WHERE username = ?");
        $stmt->execute([$admin['username']]);
        
        if ($stmt->rowCount() > 0) {
            echo "??  Admin user '{$admin['username']}' already exists. Updating...\n";
            
            // Update existing user
            $stmt = $conn->prepare("UPDATE admin SET password = ?, email = ?, name = ?, updated_at = NOW() WHERE username = ?");
            $stmt->execute([
                md5($admin['password']),  // Note: Consider upgrading to password_hash() for better security
                $admin['email'],
                $admin['name'],
                $admin['username']
            ]);
            
            echo "? Admin user '{$admin['username']}' updated successfully!\n";
            
        } else {
            // Create new user
            $stmt = $conn->prepare("INSERT INTO admin (username, password, email, name, created_at) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([
                $admin['username'],
                md5($admin['password']),  // Note: Consider upgrading to password_hash() for better security
                $admin['email'],
                $admin['name']
            ]);
            
            echo "? Admin user '{$admin['username']}' created successfully!\n";
        }
        
        echo "   Username: {$admin['username']}\n";
        echo "   Password: {$admin['password']}\n";
        echo "   Email: {$admin['email']}\n";
        echo "   Name: {$admin['name']}\n\n";
    }
    
    echo "?? Admin seeding completed!\n\n";
    
    // Display login information
    echo "=== LOGIN INFORMATION ===\n\n";
    foreach ($admin_users as $admin) {
        echo "Admin Panel: http://yourserver/admin_login.php\n";
        echo "Username: {$admin['username']}\n";
        echo "Password: {$admin['password']}\n\n";
    }
    
    echo "=== SECURITY RECOMMENDATIONS ===\n";
    echo "1. Change the default passwords after first login\n";
    echo "2. Consider upgrading password hashing from MD5 to bcrypt\n";
    echo "3. Delete this seeder file after use for security\n";
    echo "4. Enable HTTPS in production\n";
    echo "5. Set up proper firewall rules\n\n";
    
    // Log the seeding activity
    $stmt = $conn->prepare("INSERT INTO activity_logs (user_type, user_identifier, action, table_name, ip_address, user_agent, timestamp) VALUES (?, ?, ?, ?, ?, ?, NOW())");
    $stmt->execute([
        'system',
        'seeder',
        'admin_users_seeded',
        'admin',
        $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        $_SERVER['HTTP_USER_AGENT'] ?? 'Seeder Script'
    ]);
    
    echo "? Activity logged successfully.\n";
    echo "</pre>\n";
    
} catch(PDOException $e) {
    echo "<h2>? Database Error</h2>\n";
    echo "<pre>Error: " . $e->getMessage() . "</pre>\n";
    echo "<p>Please check your database configuration in config.php</p>\n";
} catch(Exception $e) {
    echo "<h2>? General Error</h2>\n";
    echo "<pre>Error: " . $e->getMessage() . "</pre>\n";
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Seeder</title>
    <style>
        body { font-family: monospace; margin: 20px; background: #f5f5f5; }
        pre { background: white; padding: 20px; border-radius: 5px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        h2 { color: #333; }
        .warning { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 20px 0; }
        .success { background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; border-radius: 5px; margin: 20px 0; }
    </style>
</head>
<body>
    <div class="warning">
        <h3>?? Security Notice</h3>
        <ul>
            <li><strong>Delete this file</strong> after running it once</li>
            <li><strong>Change default passwords</strong> after first login</li>
            <li><strong>Use HTTPS</strong> in production environment</li>
            <li><strong>Restrict database access</strong> to authorized IPs only</li>
        </ul>
    </div>
    
    <div class="success">
        <h3>?? Quick Links</h3>
        <ul>
            <li><a href="admin_login.php" target="_blank">Admin Login Panel</a></li>
            <li><a href="student_login.php" target="_blank">Student Portal</a></li>
            <li><a href="admin_dashboard.php" target="_blank">Admin Dashboard</a> (after login)</li>
        </ul>
    </div>
</body>
</html>