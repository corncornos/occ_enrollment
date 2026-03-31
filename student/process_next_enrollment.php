<?php
require_once '../config/database.php';
require_once '../classes/Section.php';

// Check if user is logged in and is student
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

header('Content-Type: application/json');

$section_id = $_POST['section_id'] ?? null;
$user_id = $_SESSION['user_id'];

if (!$section_id) {
    echo json_encode(['success' => false, 'message' => 'Section ID is required']);
    exit();
}

try {
    $section = new Section();
    
    // Assign student to section
    $result = $section->assignStudentToSection($user_id, $section_id);
    
    if ($result['success']) {
        echo json_encode([
            'success' => true,
            'message' => 'Successfully enrolled for next semester!'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => $result['message'] ?? 'Failed to enroll in section'
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
?>

