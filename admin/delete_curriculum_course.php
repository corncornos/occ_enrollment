<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/Curriculum.php';

// Check if user is logged in and is an admin
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['course_id'])) {
    $course_id = (int)$_POST['course_id'];
    
    $curriculum = new Curriculum();
    
    if ($curriculum->deleteCurriculumCourse($course_id)) {
        echo json_encode(['success' => true, 'message' => 'Course deleted from curriculum']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error deleting course']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?>

