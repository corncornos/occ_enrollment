<?php
// Direct Admin Login - Bypasses form
session_start();
require_once 'config/database.php';
require_once 'config/session_helper.php';
require_once 'classes/Admin.php';

$admin = new Admin();
$result = $admin->login('admin@occ.edu.ph', 'admin123');

if ($result['success']) {
    echo "Login successful! Redirecting to admin dashboard...<br>";
    echo "<script>setTimeout(function(){ window.location.href='admin/dashboard.php'; }, 1000);</script>";
    
    echo "<h3>Session Data:</h3><pre>";
    print_r($_SESSION);
    echo "</pre>";
} else {
    echo "<h2>Login Failed</h2>";
    echo "<p>Error: " . $result['message'] . "</p>";
}
?>

