<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../config/student_data_helper.php';

// Check if user is logged in and is an admin or registrar staff
if (!isLoggedIn() || (!isAdmin() && !isRegistrarStaff())) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

if (!isset($_GET['user_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'User ID is required']);
    exit;
}

$user_id = (int)$_GET['user_id'];

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Use centralized student data helper to ensure consistency
    $enrolled_student = getStudentData($conn, $user_id, true);
    
    if (!$enrolled_student) {
        echo json_encode([
            'success' => false,
            'message' => 'Student record not found.'
        ]);
        exit;
    }
    
    // Remove sensitive fields
    unset($enrolled_student['password']);
    
    // Get workflow information if available
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
        // Ignore workflow errors
    }
    
    echo json_encode([
        'success' => true,
        'student' => $enrolled_student,
        'workflow' => $workflow
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>

