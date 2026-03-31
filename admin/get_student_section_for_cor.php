<?php
declare(strict_types=1);

require_once '../config/database.php';
require_once '../config/session_helper.php';

// Check if user is logged in and is an admin or registrar staff
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
    // First, check if there are any section enrollments for this user (for debugging)
    $check_query = "SELECT id, section_id, status FROM section_enrollments WHERE user_id = :user_id";
    $check_stmt = $conn->prepare($check_query);
    $check_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $check_stmt->execute();
    $all_enrollments = $check_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get the most recent active or pending section enrollment (prioritize pending = student's selection)
    // Also handle NULL status (treat as pending from registration)
    $query = "SELECT se.id as enrollment_id, se.section_id, 
              COALESCE(se.status, 'pending') as enrollment_status,
              s.section_name, s.year_level, s.semester, s.academic_year, s.program_id,
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
                  se.enrolled_date DESC
              LIMIT 1";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $section = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($section) {
        echo json_encode([
            'success' => true,
            'section' => [
                'id' => (int)$section['section_id'],
                'name' => $section['section_name'],
                'year_level' => $section['year_level'],
                'semester' => $section['semester'],
                'academic_year' => $section['academic_year'],
                'program_id' => (int)$section['program_id'],
                'program_code' => $section['program_code'],
                'program_name' => $section['program_name'],
                'enrollment_status' => $section['enrollment_status']
            ]
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } else {
        // Provide more detailed error message
        $error_message = 'No assigned section found for this student';
        if (!empty($all_enrollments)) {
            $statuses = array_unique(array_column($all_enrollments, 'status'));
            $error_message .= '. Found enrollments with status: ' . implode(', ', $statuses);
            error_log("COR endpoint: User {$user_id} has enrollments but none are active/pending. Statuses: " . implode(', ', $statuses));
        } else {
            error_log("COR endpoint: User {$user_id} has no section enrollments at all");
        }
        
        echo json_encode([
            'success' => false,
            'message' => $error_message
        ]);
    }
    
} catch (PDOException $e) {
    error_log('Error fetching student section for COR: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error occurred: ' . $e->getMessage()
    ]);
}

