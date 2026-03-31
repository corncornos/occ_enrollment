<?php
require_once 'config/database.php';

echo '<h2>Reset Admin Password</h2>';
echo '<pre>';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Generate new password hash for 'admin123'
    $new_password = password_hash('admin123', PASSWORD_DEFAULT);
    
    // Update admin password
    $stmt = $conn->prepare("UPDATE admins SET password = :password WHERE admin_id = 'ADMIN001'");
    $stmt->bindParam(':password', $new_password);
    $stmt->execute();
    
    echo "✓ Password reset successful!\n\n";
    echo "Login credentials:\n";
    echo "  Email: admin@occ.edu.ph\n";
    echo "  Password: admin123\n\n";
    
    // Verify
    $verify = $conn->query("SELECT admin_id, email, role FROM admins WHERE admin_id = 'ADMIN001'");
    $admin = $verify->fetch(PDO::FETCH_ASSOC);
    
    echo "Account details:\n";
    echo "  Admin ID: {$admin['admin_id']}\n";
    echo "  Email: {$admin['email']}\n";
    echo "  Role: {$admin['role']}\n";
    
} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage();
}

echo '</pre>';
echo '<br><a href="public/login.php">Go to Login Page</a>';
?>

