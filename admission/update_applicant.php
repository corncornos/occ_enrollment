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
$status = $_POST['admission_status'] ?? null;
$remarks = $_POST['admission_remarks'] ?? null;

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'User ID required']);
    exit();
}

$admission = new Admission();

if ($admission->updateApplicationStatus($user_id, $status, $remarks)) {
    echo json_encode(['success' => true, 'message' => 'Application updated successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update application']);
}
?>







