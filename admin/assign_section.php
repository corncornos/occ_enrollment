<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../config/section_assignment_helper.php';

// Check if user is logged in and is an admin
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
    
    // Check if student exists
    $check_student = $conn->prepare("SELECT id, first_name, last_name FROM users WHERE id = :user_id AND role = 'student'");
    $check_student->bindParam(':user_id', $user_id);
    $check_student->execute();
    $student = $check_student->fetch(PDO::FETCH_ASSOC);
    
    if (!$student) {
        throw new Exception('Student not found');
    }
    
    // Check if this is an activation of a pending section (from registration)
    $activate_pending = isset($_POST['activate_pending']) && $_POST['activate_pending'] == '1';
    
    // Check if student is already actively enrolled in this section
    $check_active = $conn->prepare("SELECT id FROM section_enrollments WHERE user_id = :user_id AND section_id = :section_id AND status = 'active'");
    $check_active->bindParam(':user_id', $user_id);
    $check_active->bindParam(':section_id', $section_id);
    $check_active->execute();
    
    if ($check_active->rowCount() > 0) {
        throw new Exception('Student is already enrolled in this section');
    }
    
    // Check if student is enrolled in another section with same year level and semester
    $check_conflict = $conn->prepare("
        SELECT s.section_name, s.year_level, s.semester 
        FROM section_enrollments se 
        JOIN sections s ON se.section_id = s.id 
        WHERE se.user_id = :user_id 
        AND se.status = 'active'
        AND se.section_id != :section_id
        AND s.year_level = (SELECT year_level FROM sections WHERE id = :section_id)
        AND s.semester = (SELECT semester FROM sections WHERE id = :section_id)
    ");
    $check_conflict->bindParam(':user_id', $user_id);
    $check_conflict->bindParam(':section_id', $section_id);
    $check_conflict->execute();
    $conflict = $check_conflict->fetch(PDO::FETCH_ASSOC);
    
    if ($conflict) {
        throw new Exception('Student is already enrolled in section ' . $conflict['section_name'] . ' for ' . $conflict['year_level'] . ' - ' . $conflict['semester']);
    }
    
    // Begin transaction
    $conn->beginTransaction();
    
    // Use shared section assignment helper function
    // For registrar assignment: status='active', create schedules, increment capacity
    // Ensure both user_id and section_id are integers
    $assignment_result = assignSectionToStudent(
        $conn,
        (int)$user_id,
        (int)$section_id,
        'active',    // Status: active for direct assignment by registrar
        true,        // Create student_schedules entries
        true         // Increment section capacity
    );
    
    if (!$assignment_result['success']) {
        $conn->rollBack();
        throw new Exception($assignment_result['message']);
    }
    
    // If activating a pending section or if schedules were created, sync user data
    $section = $assignment_result['section'];
    if ($assignment_result['action'] === 'updated' || $assignment_result['action'] === 'created') {
        require_once 'sync_user_to_enrolled_students.php';
        syncUserToEnrolledStudents($conn, $user_id, [
            'course' => $section['program_code'],
            'year_level' => $section['year_level'],
            'semester' => $section['semester'],
            'academic_year' => $section['academic_year']
        ]);
    }
    
    // Commit transaction
    $conn->commit();
    
    $action_message = $activate_pending ? 'Student\'s selected section activated successfully' : 'Section assigned successfully';
    $_SESSION['message'] = $action_message . ' to ' . $student['first_name'] . ' ' . $student['last_name'];
    
    echo json_encode([
        'success' => true, 
        'message' => $action_message,
        'section_name' => $section['section_name']
    ]);
    
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

