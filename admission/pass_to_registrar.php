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

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'User ID required']);
    exit();
}

$admission = new Admission();

// Check if student number is assigned
$applicant = $admission->getApplicantById($user_id);
if (!$applicant || !$applicant['student_number_assigned']) {
    echo json_encode(['success' => false, 'message' => 'Student number must be assigned first']);
    exit();
}

// Pass to registrar
if ($admission->passToRegistrar($user_id)) {
    echo json_encode(['success' => true, 'message' => 'Applicant passed to registrar successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to pass to registrar']);
}
?>







