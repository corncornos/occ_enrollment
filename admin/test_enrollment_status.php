<?php
/**
 * Test script to check enrollment status update
 * Run this to diagnose the issue
 */
require_once '../config/database.php';

$conn = (new Database())->getConnection();

// Check current status of enrollment ID 44
$check_sql = "SELECT id, request_status, enrollment_type, processed_by, processed_at 
              FROM next_semester_enrollments 
              WHERE id = 44";
$stmt = $conn->prepare($check_sql);
$stmt->execute();
$enrollment = $stmt->fetch(PDO::FETCH_ASSOC);

echo "<h2>Current Enrollment Status (ID: 44)</h2>";
echo "<pre>";
print_r($enrollment);
echo "</pre>";

// Check what ENUM values are allowed for request_status
$enum_sql = "SHOW COLUMNS FROM next_semester_enrollments WHERE Field = 'request_status'";
$enum_stmt = $conn->prepare($enum_sql);
$enum_stmt->execute();
$enum_info = $enum_stmt->fetch(PDO::FETCH_ASSOC);

echo "<h2>Allowed request_status Values</h2>";
echo "<pre>";
print_r($enum_info);
echo "</pre>";

// Try to update directly
echo "<h2>Testing Direct Update</h2>";
try {
    $test_update = "UPDATE next_semester_enrollments 
                    SET request_status = 'pending_registrar',
                        processed_by = 1,
                        processed_at = NOW()
                    WHERE id = 44";
    $test_stmt = $conn->prepare($test_update);
    $result = $test_stmt->execute();
    
    if ($result) {
        echo "<p style='color: green;'>✓ Direct update succeeded!</p>";
        echo "<p>Rows affected: " . $test_stmt->rowCount() . "</p>";
    } else {
        echo "<p style='color: red;'>✗ Direct update failed!</p>";
        $error_info = $test_stmt->errorInfo();
        echo "<pre>";
        print_r($error_info);
        echo "</pre>";
    }
} catch (PDOException $e) {
    echo "<p style='color: red;'>✗ Exception: " . $e->getMessage() . "</p>";
    echo "<pre>";
    print_r($e->errorInfo());
    echo "</pre>";
}

// Check if enrollment_approvals table exists
$table_check = $conn->query("SHOW TABLES LIKE 'enrollment_approvals'");
echo "<h2>enrollment_approvals Table</h2>";
if ($table_check->rowCount() > 0) {
    echo "<p style='color: green;'>✓ Table exists</p>";
} else {
    echo "<p style='color: red;'>✗ Table does NOT exist - need to run migration!</p>";
}
?>

