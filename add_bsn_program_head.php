<?php
/**
 * Script to add BSN Program Head account
 * This creates a program head account for BSN (Bachelor of Science in Nursing)
 * with the same functions as other program heads
 */

require_once __DIR__ . '/config/database.php';

$db = new Database();
$conn = $db->getConnection();

try {
    // Check if BSN program exists
    $check_program_sql = "SELECT id, program_code, program_name FROM programs WHERE program_code = 'BSN'";
    $check_program_stmt = $conn->prepare($check_program_sql);
    $check_program_stmt->execute();
    $bsn_program = $check_program_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$bsn_program) {
        echo "❌ BSN program not found in the database.\n";
        echo "Please add the BSN program first using the admin module, then run this script again.\n";
        echo "\nTo add BSN program:\n";
        echo "1. Login as admin\n";
        echo "2. Go to Curriculum Management\n";
        echo "3. Click 'Add New Program'\n";
        echo "4. Enter:\n";
        echo "   - Program Code: BSN\n";
        echo "   - Program Name: Bachelor of Science in Nursing\n";
        echo "   - Total Units: (enter appropriate value)\n";
        echo "   - Years to Complete: 4\n";
        exit(1);
    }
    
    echo "✓ Found BSN program: {$bsn_program['program_name']} (ID: {$bsn_program['id']})\n\n";
    
    // Check if BSN program head already exists
    $check_head_sql = "SELECT id, email, username FROM program_heads WHERE program_id = :program_id OR email = 'bsn.head@occ.edu'";
    $check_head_stmt = $conn->prepare($check_head_sql);
    $check_head_stmt->bindParam(':program_id', $bsn_program['id'], PDO::PARAM_INT);
    $check_head_stmt->execute();
    $existing_head = $check_head_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing_head) {
        echo "⚠️  BSN Program Head already exists:\n";
        echo "   Email: {$existing_head['email']}\n";
        echo "   Username: {$existing_head['username']}\n";
        echo "   ID: {$existing_head['id']}\n\n";
        echo "If you want to reset the password, you can update it manually or delete and recreate the account.\n";
        exit(0);
    }
    
    // Generate password hash (same password as other program heads: programhead123)
    $password = 'programhead123';
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    
    // Insert BSN program head
    $insert_sql = "INSERT INTO program_heads (username, email, password, first_name, last_name, program_id, status) 
                   VALUES (:username, :email, :password, :first_name, :last_name, :program_id, 'active')";
    $insert_stmt = $conn->prepare($insert_sql);
    
    $username = 'bsn_head';
    $email = 'bsn.head@occ.edu';
    $first_name = 'Dr. Sarah';
    $last_name = 'Garcia';
    $program_id = $bsn_program['id'];
    
    $insert_stmt->bindParam(':username', $username);
    $insert_stmt->bindParam(':email', $email);
    $insert_stmt->bindParam(':password', $password_hash);
    $insert_stmt->bindParam(':first_name', $first_name);
    $insert_stmt->bindParam(':last_name', $last_name);
    $insert_stmt->bindParam(':program_id', $program_id, PDO::PARAM_INT);
    
    if ($insert_stmt->execute()) {
        echo "✅ BSN Program Head account created successfully!\n\n";
        echo "Login Credentials:\n";
        echo "   Email: {$email}\n";
        echo "   Password: {$password}\n";
        echo "   Username: {$username}\n";
        echo "   Name: {$first_name} {$last_name}\n";
        echo "   Program: {$bsn_program['program_name']} ({$bsn_program['program_code']})\n\n";
        echo "The BSN program head will have access to all the same functions as other program heads:\n";
        echo "   - Dashboard\n";
        echo "   - Curriculum Management\n";
        echo "   - Bulk Upload\n";
        echo "   - My Submissions\n";
        echo "   - Next Semester Enrollments\n";
        echo "   - Review Adjustments\n";
        echo "   - Adjustment History\n";
    } else {
        echo "❌ Failed to create BSN Program Head account.\n";
        exit(1);
    }
    
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>


