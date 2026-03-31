<?php
require_once '../config/database.php';
require_once '../classes/Admission.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !isAdmission()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$user_id = $_POST['user_id'] ?? null;
$reason = $_POST['rejection_reason'] ?? '';

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'User ID required']);
    exit();
}

$admission = new Admission();

// Mark as rejected instead of deleting
if ($admission->updateApplicationStatus($user_id, 'rejected', $reason)) {
    echo json_encode(['success' => true, 'message' => 'Application rejected successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to reject application']);
}
?>







