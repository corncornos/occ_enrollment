<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/Section.php';

// Check if user is logged in and is an admin
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['schedule_id'])) {
    $schedule_id = (int)$_POST['schedule_id'];
    
    $section = new Section();
    
    if ($section->deleteSchedule($schedule_id)) {
        echo json_encode(['success' => true, 'message' => 'Schedule entry deleted']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Error deleting schedule entry']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
?>

