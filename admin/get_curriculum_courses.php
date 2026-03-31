<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/Section.php';

// Check if user is logged in and is an admin
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!isset($_GET['program_id']) || !isset($_GET['year_level']) || !isset($_GET['semester'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

$program_id = $_GET['program_id'];
$year_level = $_GET['year_level'];
$semester = $_GET['semester'];

try {
    $section = new Section();
    $courses = $section->getAvailableCurriculumCourses($program_id, $year_level, $semester);
    
    header('Content-Type: application/json');
    echo json_encode(['courses' => $courses]);
    
} catch(Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}
?>

