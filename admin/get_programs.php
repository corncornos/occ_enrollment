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

try {
    $curriculum = new Curriculum();
    
    // Get all programs with fresh data
    $programs = $curriculum->getAllPrograms();
    
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'programs' => $programs
    ]);
    
} catch(Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}
?>

