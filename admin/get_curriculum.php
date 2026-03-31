<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/Curriculum.php';

// Check if user is logged in and is an admin
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!isset($_GET['program_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Program ID required']);
    exit;
}

$program_id = $_GET['program_id'];

try {
    $curriculum = new Curriculum();
    
    // Get curriculum courses
    $courses = $curriculum->getCurriculumByProgram($program_id);
    
    // Get curriculum summary
    $summary = $curriculum->getCurriculumSummary($program_id);
    
    // Get program info
    $program = $curriculum->getProgramById($program_id);
    
    header('Content-Type: application/json');
    echo json_encode([
        'program' => $program,
        'courses' => $courses,
        'summary' => $summary
    ]);
    
} catch(Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}
?>

