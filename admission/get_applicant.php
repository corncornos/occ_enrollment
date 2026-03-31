<?php
require_once '../config/database.php';
require_once '../classes/Admission.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !isAdmission()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$user_id = $_GET['user_id'] ?? null;

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'User ID required']);
    exit();
}

$admission = new Admission();
$applicant = $admission->getApplicantById($user_id);

if ($applicant) {
    echo json_encode(['success' => true, 'applicant' => $applicant]);
} else {
    echo json_encode(['success' => false, 'message' => 'Applicant not found']);
}
?>







