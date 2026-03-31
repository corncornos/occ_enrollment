<?php
require_once '../config/database.php';
require_once '../classes/Admission.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !isAdmission()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$admission = new Admission();
$applicants = $admission->getPendingApplicants();

echo json_encode(['success' => true, 'applicants' => $applicants]);
?>







