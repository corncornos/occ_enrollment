<?php
// FINAL ADMIN FIX - Complete Reset
require_once 'config/database.php';

echo '<h1>Final Admin Account Fix</h1>';
echo '<pre>';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    echo "Step 1: Checking current admin account...\n";
    $check = $conn->query("SELECT admin_id, email, role, status, LENGTH(password) as pwd_length FROM admins WHERE admin_id = 'ADMIN001'");
    $current = $check->fetch(PDO::FETCH_ASSOC);
    
    if ($current) {
        echo "  Admin ID: {$current['admin_id']}\n";
        echo "  Email: {$current['email']}\n";
        echo "  Role: {$current['role']}\n";
        echo "  Status: {$current['status']}\n";
        echo "  Password Length: {$current['pwd_length']} characters\n\n";
    }
    
    echo "Step 2: Creating fresh password hash for 'admin123'...\n";
    $password_hash = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';
    echo "  Hash: " . substr($password_hash, 0, 30) . "...\n\n";
    
    echo "Step 3: Updating admin account...\n";
    $sql = "UPDATE admins 
            SET email = 'admin@occ.edu.ph',
                password = :password,
                role = 'registrar',
                status = 'active'
            WHERE admin_id = 'ADMIN001'";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute([':password' => $password_hash]);
    
    echo "  ✓ Admin account updated!\n\n";
    
    echo "Step 4: Verifying the fix...\n";
    $verify = $conn->query("SELECT admin_id, email, role, status, LENGTH(password) as pwd_length FROM admins WHERE admin_id = 'ADMIN001'");
    $updated = $verify->fetch(PDO::FETCH_ASSOC);
    
    echo "  Admin ID: {$updated['admin_id']}\n";
    echo "  Email: {$updated['email']}\n";
    echo "  Role: {$updated['role']}\n";
    echo "  Status: {$updated['status']}\n";
    echo "  Password Length: {$updated['pwd_length']} characters\n\n";
    
    if ($updated['pwd_length'] > 0) {
        echo "✓✓✓ SUCCESS! ✓✓✓\n\n";
        echo "═══════════════════════════════════════\n";
        echo "  LOGIN CREDENTIALS:\n";
        echo "  Email: admin@occ.edu.ph\n";
        echo "  Password: admin123\n";
        echo "═══════════════════════════════════════\n\n";
        
        echo "Step 5: Testing password verification...\n";
        $test_password = 'admin123';
        if (password_verify($test_password, $password_hash)) {
            echo "  ✓ Password verification TEST: PASSED!\n";
        } else {
            echo "  ✗ Password verification TEST: FAILED!\n";
        }
    } else {
        echo "✗✗✗ ERROR: Password is still empty!\n";
    }
    
} catch (Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString();
}

echo '</pre>';
echo '<br><br>';
echo '<a href="public/login.php" style="background: #2563eb; color: white; padding: 15px 30px; text-decoration: none; border-radius: 5px; font-weight: bold;">GO TO LOGIN PAGE</a>';
?>

