<?php
session_start();
require_once 'config/database.php';
require_once 'classes/Admin.php';

echo '<h1>Login Flow Test</h1>';
echo '<pre>';

$test_email = 'admin@occ.edu.ph';
$test_password = 'admin123';

echo "Testing login for: $test_email\n";
echo "Password: $test_password\n\n";

echo "Step 1: Testing Admin class login...\n";
$admin = new Admin();
$result = $admin->login($test_email, $test_password);

echo "Login result:\n";
print_r($result);
echo "\n";

if ($result['success']) {
    echo "✓ Login successful!\n\n";
    
    echo "Step 2: Checking session variables...\n";
    echo "Session contents:\n";
    foreach ($_SESSION as $key => $value) {
        if (is_array($value)) {
            echo "  $key => " . print_r($value, true) . "\n";
        } else {
            echo "  $key => $value\n";
        }
    }
    echo "\n";
    
    echo "Step 3: Testing helper functions...\n";
    require_once 'config/session_helper.php';
    
    echo "  isLoggedIn(): " . (isLoggedIn() ? 'TRUE' : 'FALSE') . "\n";
    echo "  isAdmin(): " . (isAdmin() ? 'TRUE' : 'FALSE') . "\n";
    echo "  isDean(): " . (isDean() ? 'TRUE' : 'FALSE') . "\n\n";
    
    echo "Step 4: Determining redirect...\n";
    if (isDean()) {
        $redirect = 'dean/dashboard.php';
        echo "  Would redirect to: $redirect (DEAN)\n";
    } elseif (isAdmin()) {
        $redirect = 'admin/dashboard.php';
        echo "  Would redirect to: $redirect (ADMIN)\n";
    } else {
        $redirect = 'UNKNOWN';
        echo "  ERROR: No valid redirect!\n";
    }
    
    echo "\n✓✓✓ LOGIN FLOW COMPLETE ✓✓✓\n";
    echo "Expected redirect: $redirect\n";
    
} else {
    echo "✗ Login FAILED!\n";
    echo "Error: " . ($result['message'] ?? 'Unknown error') . "\n";
}

echo '</pre>';
echo '<br><br>';
echo '<a href="public/login.php" style="background: #2563eb; color: white; padding: 15px 30px; text-decoration: none; border-radius: 8px;">Go to Login Page</a>';
?>

