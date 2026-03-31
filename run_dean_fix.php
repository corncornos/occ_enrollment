<?php
// Simple Dean System Column Fix
require_once 'config/database.php';

echo "<h2>Dean System - Column Fix</h2>";
echo "<pre>";

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    echo "Running column updates...\n\n";
    
    // Read the SQL file
    $sql_file = 'database/dean_fix_columns.sql';
    $sql = file_get_contents($sql_file);
    
    // Split by semicolons
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (empty($statement) || substr(trim($statement), 0, 2) === '--') {
            continue;
        }
        
        try {
            $conn->exec($statement);
            echo "✓ " . substr($statement, 0, 80) . "...\n";
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate') !== false) {
                echo "→ Already exists: " . substr($statement, 0, 60) . "...\n";
            } else {
                echo "✗ Error: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "\n========================================\n";
    echo "Column updates completed!\n";
    echo "========================================\n\n";
    
    // Verify columns
    $check = $conn->query("SHOW COLUMNS FROM curriculum_submissions LIKE 'admin_approved'");
    if ($check->rowCount() > 0) {
        echo "✓ admin_approved column exists\n";
    } else {
        echo "✗ admin_approved column missing\n";
    }
    
    $check2 = $conn->query("SHOW COLUMNS FROM curriculum_submissions LIKE 'dean_approved'");
    if ($check2->rowCount() > 0) {
        echo "✓ dean_approved column exists\n";
    } else {
        echo "✗ dean_approved column missing\n";
    }
    
    $check3 = $conn->query("SHOW TABLES LIKE 'enrollment_reports'");
    if ($check3->rowCount() > 0) {
        echo "✓ enrollment_reports table exists\n";
    } else {
        echo "✗ enrollment_reports table missing\n";
    }
    
    echo "\n✓ All done! You can now use the dean dashboard.\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "</pre>";
echo '<br><a href="public/login.php">Go to Login Page</a>';
?>

