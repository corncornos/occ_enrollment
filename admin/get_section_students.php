<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/Section.php';

// Check if user is logged in and is an admin
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$section_id = $_GET['section_id'] ?? null;

if (!$section_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing section_id']);
    exit();
}

$section = new Section();

try {
    // Get section details
    $section_info = $section->getSectionById($section_id);
    
    if (!$section_info) {
        throw new Exception('Section not found');
    }
    
    // Get students in the section
    $students = $section->getStudentsInSection($section_id);
    
    echo json_encode([
        'success' => true,
        'section' => $section_info,
        'students' => $students
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

