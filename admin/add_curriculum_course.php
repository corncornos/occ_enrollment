<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/Curriculum.php';

// Check if user is logged in and is an admin
if (!isLoggedIn() || !isAdmin()) {
    redirect('public/login.php');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $data = [
        'program_id' => $_POST['program_id'],
        'course_code' => sanitizeInput($_POST['course_code']),
        'course_name' => sanitizeInput($_POST['course_name']),
        'units' => (int)$_POST['units'],
        'year_level' => $_POST['year_level'],
        'semester' => $_POST['semester'],
        'is_required' => isset($_POST['is_required']) ? 1 : 0,
        'pre_requisites' => sanitizeInput($_POST['pre_requisites'])
    ];
    
    $curriculum = new Curriculum();
    
    $db = new Database();
    $conn = $db->getConnection();
    
    // Pass connection to ensure updateProgramTotalUnits uses same connection
    if ($curriculum->addCurriculumCourse($data, $conn)) {
        $_SESSION['message'] = 'Course added to curriculum successfully';
    } else {
        $_SESSION['message'] = 'Error adding course to curriculum';
    }
}

redirect('admin/dashboard.php#curriculum');
?>

