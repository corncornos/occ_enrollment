<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';

// Check if user is logged in and is an admin or registrar staff
if (!isLoggedIn() || (!isAdmin() && !isRegistrarStaff())) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$db = new Database();
$conn = $db->getConnection();

try {
    $user_id = $_POST['user_id'] ?? null;
    $section_id = $_POST['section_id'] ?? null;
    
    if (!$user_id || !$section_id) {
        throw new Exception('Missing required parameters');
    }
    
    // Begin transaction
    $conn->beginTransaction();
    
    // First, check if the enrollment exists and get its current status
    $check_enrollment = $conn->prepare("
        SELECT id, status, COALESCE(status, 'pending') as actual_status
        FROM section_enrollments 
        WHERE user_id = :user_id AND section_id = :section_id
    ");
    $check_enrollment->bindParam(':user_id', $user_id);
    $check_enrollment->bindParam(':section_id', $section_id);
    $check_enrollment->execute();
    $enrollment = $check_enrollment->fetch(PDO::FETCH_ASSOC);
    
    if (!$enrollment) {
        $conn->rollBack();
        throw new Exception('Section enrollment not found');
    }
    
    // Determine the actual status (handle NULL as 'pending' since that's what registration creates)
    $actual_status = $enrollment['status'] ?? 'pending';
    
    // Only allow removal of active or pending enrollments (not already dropped)
    if ($actual_status === 'dropped') {
        $conn->rollBack();
        throw new Exception('Section enrollment has already been removed');
    }
    
    // Update section_enrollments status to 'dropped' 
    // Handle NULL status as well (treat as pending from registration)
    $update_enrollment = $conn->prepare("
        UPDATE section_enrollments 
        SET status = 'dropped' 
        WHERE user_id = :user_id AND section_id = :section_id 
        AND (status IN ('active', 'pending') OR status IS NULL)
    ");
    $update_enrollment->bindParam(':user_id', $user_id);
    $update_enrollment->bindParam(':section_id', $section_id);
    $update_enrollment->execute();
    
    if ($update_enrollment->rowCount() == 0) {
        $conn->rollBack();
        throw new Exception('Section enrollment not found or cannot be removed (already dropped or inactive)');
    }
    
    // Only decrement section count if the enrollment was 'active' (pending enrollments don't count toward capacity)
    if ($actual_status === 'active') {
        // Update section current_enrolled count
        $update_section = $conn->prepare("UPDATE sections SET current_enrolled = current_enrolled - 1 WHERE id = :section_id");
        $update_section->bindParam(':section_id', $section_id);
        $update_section->execute();
    }
    
    // Commit transaction
    $conn->commit();
    
    $_SESSION['message'] = 'Section assignment removed successfully';
    
    echo json_encode(['success' => true, 'message' => 'Section assignment removed successfully']);
    
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

