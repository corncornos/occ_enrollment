<?php
// Run Dean System Migration
require_once 'config/database.php';

echo "<h2>Dean System Migration</h2>";
echo "<pre>";

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Read the SQL file
    $sql_file = 'database/create_dean_system.sql';
    if (!file_exists($sql_file)) {
        throw new Exception("Migration file not found: $sql_file");
    }
    
    $sql = file_get_contents($sql_file);
    
    // Split by semicolons but not inside prepared statements
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    $success_count = 0;
    $error_count = 0;
    
    foreach ($statements as $statement) {
        // Skip empty statements and comments
        if (empty($statement) || substr(trim($statement), 0, 2) === '--') {
            continue;
        }
        
        try {
            // Execute each statement
            $conn->exec($statement);
            $success_count++;
            echo "✓ Executed: " . substr($statement, 0, 60) . "...\n";
        } catch (PDOException $e) {
            $error_count++;
            // Only show error if it's not about existing columns/tables
            if (strpos($e->getMessage(), 'already exists') === false && 
                strpos($e->getMessage(), 'Duplicate') === false) {
                echo "✗ Error: " . $e->getMessage() . "\n";
                echo "  Statement: " . substr($statement, 0, 100) . "...\n";
            } else {
                echo "→ Skipped (already exists): " . substr($statement, 0, 60) . "...\n";
            }
        }
    }
    
    echo "\n========================================\n";
    echo "Migration completed!\n";
    echo "Successful statements: $success_count\n";
    echo "Errors: $error_count\n";
    echo "========================================\n\n";
    
    // Verify the dean account
    $check_dean = $conn->query("SELECT * FROM admins WHERE admin_id = 'DEAN001'");
    $dean = $check_dean->fetch(PDO::FETCH_ASSOC);
    
    if ($dean) {
        echo "✓ Dean account verified:\n";
        echo "  Email: " . $dean['email'] . "\n";
        echo "  Role: " . $dean['role'] . "\n";
        echo "  Is Dean: " . ($dean['is_dean'] ? 'Yes' : 'No') . "\n";
        echo "  Status: " . $dean['status'] . "\n\n";
        
        echo "You can now login with:\n";
        echo "  Email: dean@occ.edu.ph\n";
        echo "  Password: password\n";
    } else {
        echo "✗ Dean account not found. Please check the migration.\n";
    }
    
} catch (Exception $e) {
    echo "✗ Migration failed: " . $e->getMessage() . "\n";
}

echo "</pre>";
echo '<br><a href="public/login.php">Go to Login Page</a>';
?>

