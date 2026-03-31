<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/Enrollment.php';

// Check if user is logged in and is a student
if (!isLoggedIn() || isAdmin()) {
    redirect('public/login.php');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['course_id'])) {
    $enrollment = new Enrollment();
    $course_id = (int)$_POST['course_id'];
    $user_id = $_SESSION['user_id'];
    
    $result = $enrollment->enrollStudent($user_id, $course_id);
    
    if ($result['success']) {
        $_SESSION['message'] = $result['message'];
    } else {
        $_SESSION['message'] = 'Error: ' . $result['message'];
    }
}

redirect('student/dashboard.php');
?>
