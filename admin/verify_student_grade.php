<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$grade_id = $_POST['grade_id'] ?? null;
$action = $_POST['action'] ?? null;

if (!$grade_id || !$action) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

try {
    $conn = (new Database())->getConnection();
    $admin_id = $_SESSION['user_id'];
    
    if ($action === 'verify') {
        $update_query = "UPDATE student_grades 
                        SET status = 'verified',
                            verified_by = :admin_id,
                            verified_at = NOW()
                        WHERE id = :grade_id";
    } elseif ($action === 'unverify') {
        $update_query = "UPDATE student_grades 
                        SET status = 'pending',
                            verified_by = NULL,
                            verified_at = NULL
                        WHERE id = :grade_id";
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
    }
    
    $update_stmt = $conn->prepare($update_query);
    $update_stmt->bindParam(':grade_id', $grade_id);
    if ($action === 'verify') {
        $update_stmt->bindParam(':admin_id', $admin_id);
    }
    $update_stmt->execute();
    
    echo json_encode(['success' => true, 'message' => 'Grade ' . $action . 'd successfully']);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>


