<?php
// Fix enrollment_reports table structure
require_once 'config/database.php';

echo '<h2>Fix Enrollment Reports Table</h2>';
echo '<pre>';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    echo "Checking enrollment_reports table structure...\n\n";
    
    // Check if table exists
    $table_check = $conn->query("SHOW TABLES LIKE 'enrollment_reports'");
    if ($table_check->rowCount() == 0) {
        echo "✗ Table doesn't exist. Creating it now...\n";
        
        $create_sql = "CREATE TABLE `enrollment_reports` (
          `id` INT(11) NOT NULL AUTO_INCREMENT,
          `report_title` VARCHAR(255) NOT NULL,
          `program_code` VARCHAR(20) NULL,
          `academic_year` VARCHAR(20) NULL,
          `semester` VARCHAR(50) NULL,
          `generated_by` INT(11) NOT NULL,
          `report_data` LONGTEXT NULL,
          `report_file` VARCHAR(255) NULL,
          `status` VARCHAR(20) DEFAULT 'pending',
          `reviewed_by` INT(11) NULL,
          `reviewed_at` TIMESTAMP NULL,
          `dean_comment` TEXT NULL,
          `generated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (`id`),
          KEY `idx_status` (`status`),
          KEY `idx_generated_by` (`generated_by`),
          KEY `idx_reviewed_by` (`reviewed_by`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        $conn->exec($create_sql);
        echo "✓ Table created successfully!\n\n";
    } else {
        echo "✓ Table exists. Checking columns...\n\n";
        
        // Check and fix columns
        $columns = $conn->query("SHOW COLUMNS FROM enrollment_reports");
        $existing = [];
        while ($col = $columns->fetch(PDO::FETCH_ASSOC)) {
            $existing[$col['Field']] = $col;
        }
        
        // Fix academic_year to allow NULL
        if (isset($existing['academic_year']) && $existing['academic_year']['Null'] === 'NO') {
            echo "Fixing academic_year column to allow NULL...\n";
            $conn->exec("ALTER TABLE enrollment_reports MODIFY COLUMN academic_year VARCHAR(20) NULL");
            echo "✓ academic_year now allows NULL\n";
        }
        
        // Fix program_code to allow NULL
        if (isset($existing['program_code']) && $existing['program_code']['Null'] === 'NO') {
            echo "Fixing program_code column to allow NULL...\n";
            $conn->exec("ALTER TABLE enrollment_reports MODIFY COLUMN program_code VARCHAR(20) NULL");
            echo "✓ program_code now allows NULL\n";
        }
        
        // Fix semester to allow NULL
        if (isset($existing['semester']) && $existing['semester']['Null'] === 'NO') {
            echo "Fixing semester column to allow NULL...\n";
            $conn->exec("ALTER TABLE enrollment_reports MODIFY COLUMN semester VARCHAR(50) NULL");
            echo "✓ semester now allows NULL\n";
        }
        
        // Add missing columns
        $required_columns = [
            'program_code' => "VARCHAR(20) NULL",
            'academic_year' => "VARCHAR(20) NULL",
            'semester' => "VARCHAR(50) NULL",
            'report_file' => "VARCHAR(255) NULL",
            'reviewed_by' => "INT(11) NULL",
            'reviewed_at' => "TIMESTAMP NULL",
            'dean_comment' => "TEXT NULL",
            'generated_at' => "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
        ];
        
        foreach ($required_columns as $col_name => $col_def) {
            if (!isset($existing[$col_name])) {
                echo "Adding missing column: $col_name...\n";
                $conn->exec("ALTER TABLE enrollment_reports ADD COLUMN `$col_name` $col_def");
                echo "✓ Column $col_name added\n";
            }
        }
    }
    
    echo "\n✓✓✓ Table structure is now correct! ✓✓✓\n";
    echo "\nYou can now generate enrollment reports.\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
}

echo '</pre>';
echo '<br><a href="admin/enrollment_reports.php">Go to Enrollment Reports</a>';
?>

