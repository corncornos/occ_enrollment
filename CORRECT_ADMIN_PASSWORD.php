<?php
// Generate CORRECT password hash for admin123
require_once 'config/database.php';

echo '<h1>Correct Admin Password Fix</h1>';
echo '<pre>';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    echo "Step 1: Generating CORRECT password hash for 'admin123'...\n";
    $correct_hash = password_hash('admin123', PASSWORD_DEFAULT);
    echo "  New Hash: " . substr($correct_hash, 0, 40) . "...\n";
    echo "  Hash Length: " . strlen($correct_hash) . " characters\n\n";
    
    echo "Step 2: Verifying the hash works BEFORE updating database...\n";
    if (password_verify('admin123', $correct_hash)) {
        echo "  ✓ Pre-verification PASSED! This hash is correct.\n\n";
    } else {
        echo "  ✗ Pre-verification FAILED! Something is wrong.\n\n";
        exit;
    }
    
    echo "Step 3: Updating database with correct hash...\n";
    $stmt = $conn->prepare("UPDATE admins SET password = :password WHERE admin_id = 'ADMIN001'");
    $stmt->execute([':password' => $correct_hash]);
    echo "  ✓ Database updated!\n\n";
    
    echo "Step 4: Fetching updated record from database...\n";
    $verify_stmt = $conn->prepare("SELECT password FROM admins WHERE admin_id = 'ADMIN001'");
    $verify_stmt->execute();
    $result = $verify_stmt->fetch(PDO::FETCH_ASSOC);
    $db_hash = $result['password'];
    echo "  Retrieved Hash: " . substr($db_hash, 0, 40) . "...\n";
    echo "  Hash Length: " . strlen($db_hash) . " characters\n\n";
    
    echo "Step 5: Testing password verification against database hash...\n";
    if (password_verify('admin123', $db_hash)) {
        echo "  ✓✓✓ VERIFICATION SUCCESSFUL! ✓✓✓\n\n";
        echo "═══════════════════════════════════════════════\n";
        echo "  ✓✓✓ ADMIN LOGIN IS NOW FIXED! ✓✓✓\n";
        echo "═══════════════════════════════════════════════\n\n";
        echo "  Email: admin@occ.edu.ph\n";
        echo "  Password: admin123\n\n";
        echo "  YOU CAN NOW LOGIN!\n";
        echo "═══════════════════════════════════════════════\n";
    } else {
        echo "  ✗ Verification FAILED! Database hash doesn't match.\n";
    }
    
} catch (Exception $e) {
    echo "✗ ERROR: " . $e->getMessage() . "\n";
}

echo '</pre>';
echo '<br><br>';
echo '<div style="text-align: center;">';
echo '<a href="public/login.php" style="background: #10b981; color: white; padding: 20px 40px; text-decoration: none; border-radius: 8px; font-weight: bold; font-size: 18px; display: inline-block;">🎉 GO TO LOGIN PAGE 🎉</a>';
echo '</div>';
?>

