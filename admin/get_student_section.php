<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';

// Check if user is logged in and is an admin
if (!isLoggedIn() || (!isAdmin() && !isRegistrarStaff())) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$db = new Database();
$conn = $db->getConnection();

$user_id = $_GET['user_id'] ?? null;

if (!$user_id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing user_id']);
    exit();
}

try {
    // Get both active and pending sections (pending = student's selection during registration)
    // Also handle NULL status (treat as pending from registration)
    // ORDER BY ensures pending sections appear first
    $query = "SELECT se.id as enrollment_id, se.section_id, se.enrolled_date, 
              COALESCE(se.status, 'pending') as enrollment_status,
              s.section_name, s.year_level, s.semester, s.academic_year,
              p.program_code, p.program_name
              FROM section_enrollments se
              JOIN sections s ON se.section_id = s.id
              JOIN programs p ON s.program_id = p.id
              WHERE se.user_id = :user_id 
              AND (se.status IN ('active', 'pending') OR se.status IS NULL)
              ORDER BY 
                  CASE COALESCE(se.status, 'pending')
                      WHEN 'pending' THEN 1 
                      WHEN 'active' THEN 2 
                      ELSE 3 
                  END ASC,
                  se.enrolled_date DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // If no sections found, check if there are any enrollments with different status
    if (empty($sections)) {
        // Check for any section enrollments (regardless of status)
        $check_all_query = "SELECT se.*, s.section_name, s.status as section_status 
                           FROM section_enrollments se 
                           LEFT JOIN sections s ON se.section_id = s.id 
                           WHERE se.user_id = :user_id";
        $check_all_stmt = $conn->prepare($check_all_query);
        $check_all_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $check_all_stmt->execute();
        $all_enrollments = $check_all_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (!empty($all_enrollments)) {
            // Found enrollments but with different status - include them anyway for visibility
            foreach ($all_enrollments as $enrollment) {
                if ($enrollment['section_status'] === 'active') {
                    // Get full details even if enrollment status is not active/pending
                    $details_query = "SELECT se.id as enrollment_id, se.section_id, se.enrolled_date, se.status as enrollment_status,
                                     s.section_name, s.year_level, s.semester, s.academic_year,
                                     p.program_code, p.program_name
                                     FROM section_enrollments se
                                     JOIN sections s ON se.section_id = s.id
                                     JOIN programs p ON s.program_id = p.id
                                     WHERE se.id = :enrollment_id";
                    $details_stmt = $conn->prepare($details_query);
                    $details_stmt->bindParam(':enrollment_id', $enrollment['id'], PDO::PARAM_INT);
                    $details_stmt->execute();
                    $detail = $details_stmt->fetch(PDO::FETCH_ASSOC);
                    if ($detail) {
                        $sections[] = $detail;
                    }
                }
            }
        }
    }
    
    echo json_encode(['success' => true, 'sections' => $sections]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

