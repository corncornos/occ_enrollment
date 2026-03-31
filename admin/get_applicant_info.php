<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/User.php';

header('Content-Type: application/json');

if (!isLoggedIn() || (!isAdmin() && !isRegistrarStaff())) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access.'
    ]);
    exit;
}

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if ($user_id <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid user ID.'
    ]);
    exit;
}

$userModel = new User();
$database = new Database();
$conn = $database->getConnection();

$applicant = $userModel->getUserById($user_id);
if (!$applicant) {
    echo json_encode([
        'success' => false,
        'message' => 'Applicant record not found.'
    ]);
    exit;
}

// Remove sensitive fields that should not be exposed.
unset($applicant['password']);

$workflow = [
    'admission_status' => null,
    'passed_to_registrar' => null,
    'passed_to_registrar_at' => null
];

try {
    $workflow_query = "SELECT admission_status, passed_to_registrar, passed_to_registrar_at 
                       FROM application_workflow 
                       WHERE user_id = :user_id LIMIT 1";
    $workflow_stmt = $conn->prepare($workflow_query);
    $workflow_stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $workflow_stmt->execute();
    $workflow_data = $workflow_stmt->fetch(PDO::FETCH_ASSOC);
    if ($workflow_data) {
        $workflow['admission_status'] = $workflow_data['admission_status'] ?? null;
        $workflow['passed_to_registrar'] = (int)($workflow_data['passed_to_registrar'] ?? 0) === 1;
        $workflow['passed_to_registrar_at'] = $workflow_data['passed_to_registrar_at'] ?? null;
    }
} catch (PDOException $e) {
    // Ignore workflow errors but log them if needed.
}

echo json_encode([
    'success' => true,
    'applicant' => $applicant,
    'workflow' => $workflow
]);

