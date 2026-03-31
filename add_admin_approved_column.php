<?php
// Add missing admin_approved column
require_once 'config/database.php';

echo '<h2>Add Missing admin_approved Column</h2>';
echo '<pre>';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    echo "Checking if admin_approved column exists...\n";
    
    // Check if column exists
    $check = $conn->query("SELECT COUNT(*) as count 
                          FROM INFORMATION_SCHEMA.COLUMNS 
                          WHERE TABLE_SCHEMA = DATABASE()
                          AND TABLE_NAME = 'curriculum_submissions'
                          AND COLUMN_NAME = 'admin_approved'");
    $result = $check->fetch(PDO::FETCH_ASSOC);
    
    if ($result['count'] > 0) {
        echo "✓ Column 'admin_approved' already exists!\n";
    } else {
        echo "✗ Column 'admin_approved' is missing. Adding it now...\n";
        
        $conn->exec("ALTER TABLE `curriculum_submissions` ADD COLUMN `admin_approved` TINYINT(1) DEFAULT 0");
        
        echo "✓ Column 'admin_approved' added successfully!\n";
    }
    
    echo "\nVerifying all required columns...\n";
    $columns = ['admin_approved', 'admin_approved_at', 'dean_approved', 'dean_approved_by', 'dean_approved_at', 'dean_notes', 'submitted_by'];
    
    foreach ($columns as $col) {
        $check = $conn->query("SELECT COUNT(*) as count 
                              FROM INFORMATION_SCHEMA.COLUMNS 
                              WHERE TABLE_SCHEMA = DATABASE()
                              AND TABLE_NAME = 'curriculum_submissions'
                              AND COLUMN_NAME = '$col'");
        $result = $check->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] > 0) {
            echo "  ✓ $col exists\n";
        } else {
            echo "  ✗ $col MISSING\n";
        }
    }
    
    echo "\n✓✓✓ All done! ✓✓✓\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo '</pre>';
echo '<br><a href="run_dean_fix.php">Run Full Migration</a> | ';
echo '<a href="dean/dashboard.php">Go to Dean Dashboard</a>';
?>

