<?php
require_once 'dbconfig.php';

echo "<h2>Debug: Subject Pools Analysis</h2>";

try {
    // Check subject_pools table
    echo "<h3>1. Subject Pools Table Structure</h3>";
    $stmt = $conn->query("DESCRIBE subject_pools");
    $columns = $stmt->fetchAll();
    
    echo "<table border='1' style='border-collapse: collapse;'>";
    echo "<tr><th>Column</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th></tr>";
    foreach ($columns as $col) {
        echo "<tr>";
        echo "<td>{$col['Field']}</td>";
        echo "<td>{$col['Type']}</td>";
        echo "<td>{$col['Null']}</td>";
        echo "<td>{$col['Key']}</td>";
        echo "<td>{$col['Default']}</td>";
        echo "</tr>";
    }
    echo "</table>";
    
    // Check total pools
    echo "<h3>2. Total Pools Count</h3>";
    $stmt = $conn->query("SELECT COUNT(*) as total FROM subject_pools");
    $total = $stmt->fetch()['total'];
    echo "Total pools: $total<br>";
    
    $stmt = $conn->query("SELECT COUNT(*) as active FROM subject_pools WHERE is_active = 1");
    $active = $stmt->fetch()['active'];
    echo "Active pools: $active<br>";
    
    // Show all pools
    echo "<h3>3. All Subject Pools (Active Only)</h3>";
    $stmt = $conn->query("SELECT * FROM subject_pools WHERE is_active = 1 ORDER BY pool_name, subject_name");
    $pools = $stmt->fetchAll();
    
    if (empty($pools)) {
        echo "<div style='color: red;'>? No active subject pools found!</div>";
        echo "<p>You need to create subject pools first through the admin panel.</p>";
    } else {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr style='background: #f0f0f0;'>";
        echo "<th>ID</th><th>Pool Name</th><th>Subject Name</th><th>Subject Code</th>";
        echo "<th>Semester</th><th>Batch</th><th>Programmes</th><th>Intake</th>";
        echo "</tr>";
        
        foreach ($pools as $pool) {
            echo "<tr>";
            echo "<td>{$pool['id']}</td>";
            echo "<td><strong>{$pool['pool_name']}</strong></td>";
            echo "<td>{$pool['subject_name']}</td>";
            echo "<td>{$pool['subject_code']}</td>";
            echo "<td>{$pool['semester']}</td>";
            echo "<td>{$pool['batch']}</td>";
            echo "<td>" . htmlspecialchars($pool['allowed_programmes']) . "</td>";
            echo "<td>{$pool['intake']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Test grouped query
    echo "<h3>4. Grouped Pools Query Test</h3>";
    $stmt = $conn->prepare("
        SELECT 
            pool_name,
            GROUP_CONCAT(CONCAT(subject_name, ' (', subject_code, ')') ORDER BY subject_name SEPARATOR ', ') as subjects,
            GROUP_CONCAT(id ORDER BY subject_name SEPARATOR ',') as pool_ids,
            semester,
            batch,
            allowed_programmes,
            MIN(id) as first_pool_id,
            COUNT(*) as subject_count
        FROM subject_pools 
        WHERE is_active = 1 
        GROUP BY pool_name, semester, batch, allowed_programmes
        ORDER BY pool_name
    ");
    $stmt->execute();
    $grouped = $stmt->fetchAll();
    
    if (empty($grouped)) {
        echo "<div style='color: red;'>? No grouped pools found!</div>";
    } else {
        echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
        echo "<tr style='background: #f0f0f0;'>";
        echo "<th>Pool Name</th><th>Subjects</th><th>Subject Count</th><th>Semester</th><th>Batch</th><th>Programmes</th>";
        echo "</tr>";
        
        foreach ($grouped as $group) {
            echo "<tr>";
            echo "<td><strong>{$group['pool_name']}</strong></td>";
            echo "<td>{$group['subjects']}</td>";
            echo "<td>{$group['subject_count']}</td>";
            echo "<td>{$group['semester']}</td>";
            echo "<td>{$group['batch']}</td>";
            echo "<td>" . htmlspecialchars($group['allowed_programmes']) . "</td>";
            echo "</tr>";
        }
        echo "</table>";
    }
    
    // Test student data
    echo "<h3>5. Test Student Data</h3>";
    $test_regno = '23A21A6549';
    
    if ($attendance_conn) {
        $stmt = $attendance_conn->prepare("SELECT regid, name, programme, semester, batch, email, mobile FROM user WHERE regid = ?");
        $stmt->execute([$test_regno]);
        $student = $stmt->fetch();
        
        if ($student) {
            echo "<table border='1' style='border-collapse: collapse;'>";
            echo "<tr><th>Field</th><th>Value</th></tr>";
            foreach ($student as $key => $value) {
                echo "<tr><td>$key</td><td>$value</td></tr>";
            }
            echo "</table>";
            
            // Check eligible pools for this student
            echo "<h4>Eligible Pools for {$student['regid']}:</h4>";
            $eligible_count = 0;
            
            foreach ($grouped as $group) {
                $allowed_programmes = json_decode($group['allowed_programmes'], true);
                if (in_array($student['programme'], $allowed_programmes) && 
                    $group['semester'] === $student['semester'] && 
                    $group['batch'] === $student['batch']) {
                    echo "<div style='color: green;'>? {$group['pool_name']} - {$group['subjects']}</div>";
                    $eligible_count++;
                } else {
                    echo "<div style='color: red;'>? {$group['pool_name']} (Programme: " . implode(', ', $allowed_programmes) . 
                         ", Semester: {$group['semester']}, Batch: {$group['batch']})</div>";
                }
            }
            
            echo "<p><strong>Total eligible pools: $eligible_count</strong></p>";
            
        } else {
            echo "<div style='color: red;'>? Student $test_regno not found in attendance database</div>";
        }
    } else {
        echo "<div style='color: red;'>? Attendance database connection not available</div>";
    }

} catch(Exception $e) {
    echo "? Error: " . $e->getMessage();
    echo "<br>Stack trace:<br><pre>" . $e->getTraceAsString() . "</pre>";
}
?>

<style>
body { font-family: Arial, sans-serif; margin: 20px; }
table { margin: 10px 0; }
th, td { padding: 8px; border: 1px solid #ccc; text-align: left; }
th { background-color: #f0f0f0; }
</style>