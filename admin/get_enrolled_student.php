<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../config/student_data_helper.php';

// Check if user is logged in and is an admin or registrar staff
if (!isLoggedIn() || (!isAdmin() && !isRegistrarStaff())) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!isset($_GET['user_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'User ID required']);
    exit;
}

$user_id = $_GET['user_id'];

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Use centralized student data helper with sections
    $enrolled_student = getStudentDataWithSections($conn, $user_id, true);
    
    // If no record exists, return default values
    if (!$enrolled_student) {
        $enrolled_student = [
            'course' => '',
            'year_level' => '1st Year',
            'student_type' => 'Regular',
            'academic_year' => 'AY 2024-2025',
            'semester' => 'First Semester',
            'section_programs' => null,
            'section_year_levels' => null,
            'section_semesters' => null,
            'section_academic_years' => null,
            'section_names' => null
        ];
    } else {
        // Extract section information for compatibility
        $sections = $enrolled_student['sections'] ?? [];
        $section_programs = [];
        $section_year_levels = [];
        $section_semesters = [];
        $section_academic_years = [];
        $section_names = [];
        
        foreach ($sections as $section) {
            if (!empty($section['program_code'])) $section_programs[] = $section['program_code'];
            if (!empty($section['year_level'])) $section_year_levels[] = $section['year_level'];
            if (!empty($section['semester'])) $section_semesters[] = $section['semester'];
            if (!empty($section['academic_year'])) $section_academic_years[] = $section['academic_year'];
            if (!empty($section['section_name'])) $section_names[] = $section['section_name'];
        }
        
        $enrolled_student['section_programs'] = !empty($section_programs) ? implode(', ', array_unique($section_programs)) : null;
        $enrolled_student['section_year_levels'] = !empty($section_year_levels) ? implode(', ', array_unique($section_year_levels)) : null;
        $enrolled_student['section_semesters'] = !empty($section_semesters) ? implode(', ', array_unique($section_semesters)) : null;
        $enrolled_student['section_academic_years'] = !empty($section_academic_years) ? implode(', ', array_unique($section_academic_years)) : null;
        $enrolled_student['section_names'] = !empty($section_names) ? implode(', ', array_unique($section_names)) : null;
        
        // For display, prioritize the single current enrollment record (enrolled_students)
        // so that admin/registrar views match the student dashboard "Current Enrollment".
        // Section aggregates are kept for reference but not used as the primary display source.
        $enrolled_student['display_course'] = $enrolled_student['course'] ?? $enrolled_student['section_programs'] ?? '';
        $enrolled_student['display_year_level'] = $enrolled_student['year_level'] ?? $enrolled_student['section_year_levels'] ?? '1st Year';
        $enrolled_student['display_semester'] = $enrolled_student['semester'] ?? $enrolled_student['section_semesters'] ?? 'First Semester';
        $enrolled_student['display_academic_year'] = $enrolled_student['academic_year'] ?? $enrolled_student['section_academic_years'] ?? 'AY 2024-2025';
    }
    
    header('Content-Type: application/json');
    echo json_encode($enrolled_student);
    
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>

