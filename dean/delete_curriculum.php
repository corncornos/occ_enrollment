<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/Curriculum.php';

if (!isLoggedIn() || !isDean()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['program_id'])) {
    $program_id = (int)$_POST['program_id'];
    
    if ($program_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid program ID']);
        exit;
    }
    
    // Verify program exists
    $curriculum = new Curriculum();
    $program = $curriculum->getProgramById($program_id);
    
    if (!$program) {
        echo json_encode(['success' => false, 'message' => 'Program not found']);
        exit;
    }
    
    // Delete all curriculum for this program
    $result = $curriculum->deleteAllCurriculumForProgram($program_id);
    
    if ($result['success']) {
        echo json_encode([
            'success' => true, 
            'message' => "Successfully deleted {$result['deleted_count']} courses from curriculum.",
            'deleted_count' => $result['deleted_count']
        ]);
    } else {
        echo json_encode([
            'success' => false, 
            'message' => 'Error deleting curriculum: ' . ($result['message'] ?? 'Unknown error')
        ]);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?>

