<?php
require_once '../config/database.php';

// Redirect to login if not logged in
if (!isLoggedIn()) {
    redirect('public/login.php');
}

// Redirect based on role
if (isAdmin()) {
    redirect('admin/dashboard.php');
} else {
    redirect('student/dashboard.php');
}
?>
