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
$result = $admission->assignStudentNumber($user_id);

echo json_encode($result);
?>







