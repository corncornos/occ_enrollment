<?php
/**
 * Script to reset admin password
 * Run this once via browser: http://localhost/enrollment_occ/admin/reset_admin_password.php
 * Then DELETE this file for security
 */

require_once '../config/database.php';

$db = new Database();
$conn = $db->getConnection();

// New password hash for "admin123"
$new_password_hash = password_hash('admin123', PASSWORD_DEFAULT);

try {
    // First, check if admin account exists
    $check_sql = "SELECT id, email, status, password FROM admins WHERE email = 'admin@occ.edu'";
    $check_stmt = $conn->prepare($check_sql);
    $check_stmt->execute();
    $admin = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin) {
        echo "❌ Admin account with email 'admin@occ.edu' not found!<br>";
        echo "Creating admin account...<br>";
        
        // Create admin account
        $create_sql = "INSERT INTO admins (admin_id, first_name, last_name, email, password, role, status) 
                       VALUES ('ADMIN001', 'System', 'Administrator', 'admin@occ.edu', :password, 'registrar', 'active')";
        $create_stmt = $conn->prepare($create_sql);
        $create_stmt->bindParam(':password', $new_password_hash);
        
        if ($create_stmt->execute()) {
            echo "✅ Admin account created successfully!<br>";
        } else {
            echo "❌ Error creating admin account.";
            exit;
        }
    } else {
        echo "✅ Admin account found!<br>";
        echo "Current status: " . htmlspecialchars($admin['status']) . "<br>";
        
        // Check if account is active
        if ($admin['status'] !== 'active') {
            echo "⚠️ Account is not active. Activating account...<br>";
            $activate_sql = "UPDATE admins SET status = 'active' WHERE email = 'admin@occ.edu'";
            $activate_stmt = $conn->prepare($activate_sql);
            $activate_stmt->execute();
            echo "✅ Account activated!<br>";
        }
        
        // Update admin password
        $sql = "UPDATE admins SET password = :password WHERE email = 'admin@occ.edu'";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':password', $new_password_hash);
        
        if ($stmt->execute()) {
            echo "✅ Admin password reset successfully!<br>";
        } else {
            echo "❌ Error resetting password.";
            exit;
        }
    }
    
    echo "<br>✅ Admin account ready!<br>";
    echo "Email: admin@occ.edu<br>";
    echo "Password: admin123<br>";
    echo "<br><strong>Please DELETE this file (reset_admin_password.php) for security!</strong>";
} catch (PDOException $e) {
    echo "❌ Database error: " . $e->getMessage();
}

// Also verify the password hash
echo "<br><br>Verifying password hash...<br>";
$verify_hash = password_verify('admin123', $new_password_hash);
echo $verify_hash ? "✅ Password hash verified successfully!" : "❌ Password hash verification failed!";
?>

