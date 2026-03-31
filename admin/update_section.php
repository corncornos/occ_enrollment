<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/Section.php';

// Check if user is logged in and is an admin
if (!isLoggedIn() || !isAdmin()) {
    redirect('public/login.php');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $section_id = (int)$_POST['section_id'];
    $data = [
        'section_name' => sanitizeInput($_POST['section_name']),
        'max_capacity' => (int)$_POST['max_capacity'],
        'status' => $_POST['status']
    ];
    
    $section = new Section();
    
    if ($section->updateSection($section_id, $data)) {
        $_SESSION['message'] = 'Section updated successfully';
    } else {
        $_SESSION['message'] = 'Error updating section';
    }
}

// Redirect back to admin dashboard sections tab
redirect('admin/dashboard.php#sections');
?>


