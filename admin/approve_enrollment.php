<?php
declare(strict_types=1);

require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../config/student_data_helper.php';
require_once 'enrollment_workflow_helper.php';
require_once 'sync_user_to_enrolled_students.php';

// Check if user is logged in and is an admin
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

$request_id = $_POST['request_id'] ?? null;

if (!$request_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing request_id']);
    exit();
}

$db = new Database();
$conn = $db->getConnection();

try {
    $conn->beginTransaction();
    
    // Get enrollment request details
    $request_query = "SELECT nse.*, u.student_id, u.first_name, u.last_name
                     FROM next_semester_enrollments nse
                     JOIN users u ON nse.user_id = u.id
                     WHERE nse.id = :request_id";
    $request_stmt = $conn->prepare($request_query);
    $request_stmt->bindParam(':request_id', $request_id, PDO::PARAM_INT);
    $request_stmt->execute();
    $request = $request_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        throw new Exception('Enrollment request not found');
    }
    
    // Admin can approve both pending_registrar and pending_admin enrollments
    if (!in_array($request['request_status'], ['pending_registrar', 'pending_admin'])) {
        throw new Exception('Only pending_registrar or pending_admin enrollments can be approved by admin');
    }
    
    $approver_id = (int)$_SESSION['user_id'];
    $remarks = $_POST['remarks'] ?? null;
    
    // Update status to confirmed
    $success = updateEnrollmentStatus(
        $conn, 
        (int)$request_id, 
        'confirmed', 
        'admin', 
        $approver_id, 
        'approved', 
        $remarks,
        false  // Don't manage transaction - already in one
    );
    
    if (!$success) {
        throw new Exception('Failed to update enrollment status');
    }
    
    // Get section information if assigned
    $target_academic_year = $request['target_academic_year'];
    $target_semester = $request['target_semester'];
    $new_year_level = null;
    
    // Determine new year level based on target semester
    $current_year_level = $request['current_year_level'];
    if (stripos($target_semester, 'Second') !== false || stripos($target_semester, '2nd') !== false) {
        // Second semester - same year level
        $new_year_level = $current_year_level;
    } else {
        // First semester - next year level
        switch ($current_year_level) {
            case '1st Year': $new_year_level = '2nd Year'; break;
            case '2nd Year': $new_year_level = '3rd Year'; break;
            case '3rd Year': $new_year_level = '4th Year'; break;
            case '4th Year': $new_year_level = '5th Year'; break;
            default: $new_year_level = $current_year_level;
        }
    }
    
    // Get program code from section if available
    $program_code = null;
    if ($request['selected_section_id']) {
        $section_query = "SELECT p.program_code 
                         FROM sections s 
                         JOIN programs p ON s.program_id = p.id 
                         WHERE s.id = :section_id";
        $section_stmt = $conn->prepare($section_query);
        $section_stmt->bindParam(':section_id', $request['selected_section_id'], PDO::PARAM_INT);
        $section_stmt->execute();
        $section_data = $section_stmt->fetch(PDO::FETCH_ASSOC);
        if ($section_data) {
            $program_code = $section_data['program_code'];
        }
    }
    
    // Fallback to getting program from student's current sections
    if (!$program_code) {
        $program_fallback = "SELECT p.program_code 
                            FROM section_enrollments se
                            JOIN sections s ON se.section_id = s.id
                            JOIN programs p ON s.program_id = p.id
                            WHERE se.user_id = :user_id AND se.status = 'active'
                            LIMIT 1";
        $program_stmt = $conn->prepare($program_fallback);
        $program_stmt->bindParam(':user_id', $request['user_id'], PDO::PARAM_INT);
        $program_stmt->execute();
        $program_data = $program_stmt->fetch(PDO::FETCH_ASSOC);
        if ($program_data) {
            $program_code = $program_data['program_code'];
        }
    }
    
    // Sync student data to enrolled_students
    ensureStudentDataSync($conn, (int)$request['user_id'], [
        'course' => $program_code,
        'year_level' => $new_year_level,
        'academic_year' => $target_academic_year,
        'semester' => $target_semester
    ]);
    
    // Update user enrollment status
    $update_user = $conn->prepare("UPDATE users SET enrollment_status = 'enrolled' WHERE id = :user_id");
    $update_user->bindParam(':user_id', $request['user_id'], PDO::PARAM_INT);
    $update_user->execute();
    
    // If section is assigned, activate the enrollment
    if ($request['selected_section_id']) {
        require_once '../config/section_assignment_helper.php';
        $assignment_result = assignSectionToStudent(
            $conn,
            (int)$request['user_id'],
            (int)$request['selected_section_id'],
            'active',
            true,  // Create schedules
            true   // Increment capacity
        );
        
        if (!$assignment_result['success']) {
            error_log('Warning: Section assignment failed during enrollment approval: ' . $assignment_result['message']);
        }
    }
    
    $conn->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'Enrollment approved successfully! Student has been enrolled.',
        'student_name' => $request['first_name'] . ' ' . $request['last_name']
    ]);
    
} catch (Exception $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log('Error approving enrollment: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

