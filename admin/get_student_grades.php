<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_GET['user_id'] ?? null;

if (!$user_id) {
    echo json_encode(['success' => false, 'message' => 'User ID required']);
    exit;
}

try {
    $conn = (new Database())->getConnection();
    
    // Get all subjects from COR (Certificate of Registration) only
    // This ensures we only grade subjects that were officially enrolled via the checklist
    $cor_query = "SELECT cor.id as cor_id, cor.academic_year, cor.semester, cor.subjects_json,
                  cor.section_id, s.section_name, p.program_code, p.program_name
                  FROM certificate_of_registration cor
                  JOIN sections s ON cor.section_id = s.id
                  JOIN programs p ON cor.program_id = p.id
                  WHERE cor.user_id = :user_id
                  ORDER BY cor.academic_year DESC, cor.semester DESC, cor.created_at DESC";
    
    $cor_stmt = $conn->prepare($cor_query);
    $cor_stmt->bindParam(':user_id', $user_id);
    $cor_stmt->execute();
    $cor_records = $cor_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $subjects = [];
    $processed_subjects = []; // Track processed subjects by course_code + academic_year + semester to avoid duplicates
    
    // Extract subjects from all COR records
    foreach ($cor_records as $cor) {
        $subjects_json = json_decode($cor['subjects_json'], true);
        if (!is_array($subjects_json)) {
            continue;
        }
        
        foreach ($subjects_json as $cor_subject) {
            $course_code = strtoupper(trim($cor_subject['course_code'] ?? ''));
            if (empty($course_code)) {
                continue;
            }
            
            // Create unique key to avoid duplicates (same subject in same term)
            $unique_key = $course_code . '|' . $cor['academic_year'] . '|' . $cor['semester'];
            if (isset($processed_subjects[$unique_key])) {
                continue; // Skip duplicates
            }
            $processed_subjects[$unique_key] = true;
            
            // Match to curriculum table to get curriculum_id (needed for grade lookup)
            // Match by course_code, year_level, and semester from COR
            $curriculum = null;
            if (!empty($cor_subject['year_level']) && !empty($cor_subject['semester'])) {
                $curriculum_query = "SELECT c.id, c.course_code, c.course_name, c.units, c.year_level, c.semester
                                    FROM curriculum c
                                    JOIN programs p ON c.program_id = p.id
                                    WHERE UPPER(TRIM(c.course_code)) = :course_code
                                    AND c.year_level = :year_level
                                    AND c.semester = :semester
                                    AND p.id = (SELECT program_id FROM certificate_of_registration WHERE id = :cor_id)
                                    LIMIT 1";
                
                $curriculum_stmt = $conn->prepare($curriculum_query);
                $curriculum_stmt->bindParam(':course_code', $course_code);
                $curriculum_stmt->bindParam(':year_level', $cor_subject['year_level']);
                $curriculum_stmt->bindParam(':semester', $cor_subject['semester']);
                $curriculum_stmt->bindParam(':cor_id', $cor['cor_id']);
                $curriculum_stmt->execute();
                $curriculum = $curriculum_stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            // Fallback: if exact match not found, try matching by course_code and program only
            if (!$curriculum) {
                $curriculum_fallback_query = "SELECT c.id, c.course_code, c.course_name, c.units, c.year_level, c.semester
                                              FROM curriculum c
                                              JOIN programs p ON c.program_id = p.id
                                              WHERE UPPER(TRIM(c.course_code)) = :course_code
                                              AND p.id = (SELECT program_id FROM certificate_of_registration WHERE id = :cor_id)
                                              ORDER BY c.year_level, c.semester
                                              LIMIT 1";
                
                $curriculum_fallback_stmt = $conn->prepare($curriculum_fallback_query);
                $curriculum_fallback_stmt->bindParam(':course_code', $course_code);
                $curriculum_fallback_stmt->bindParam(':cor_id', $cor['cor_id']);
                $curriculum_fallback_stmt->execute();
                $curriculum = $curriculum_fallback_stmt->fetch(PDO::FETCH_ASSOC);
            }
            
            // If curriculum match found, use it; otherwise use data from COR
            if ($curriculum) {
                $subjects[] = [
                    'subject_id' => $curriculum['id'],
                    'subject_code' => $curriculum['course_code'],
                    'subject_name' => $curriculum['course_name'],
                    'units' => $curriculum['units'],
                    'academic_year' => $cor['academic_year'],
                    'semester' => $cor['semester'],
                    'year_level' => $curriculum['year_level'],
                    'curriculum_semester' => $curriculum['semester'],
                    'is_backload' => $cor_subject['is_backload'] ?? false,
                    'backload_year_level' => $cor_subject['backload_year_level'] ?? null,
                    'section_name' => $cor['section_name'],
                    'program_code' => $cor['program_code']
                ];
            } else {
                // Fallback: use data from COR if curriculum match not found
                $subjects[] = [
                    'subject_id' => null, // Will need to match by course_code for grades
                    'subject_code' => $cor_subject['course_code'],
                    'subject_name' => $cor_subject['course_name'],
                    'units' => $cor_subject['units'] ?? 0,
                    'academic_year' => $cor['academic_year'],
                    'semester' => $cor['semester'],
                    'year_level' => $cor_subject['year_level'] ?? null,
                    'curriculum_semester' => $cor_subject['semester'] ?? null,
                    'is_backload' => $cor_subject['is_backload'] ?? false,
                    'backload_year_level' => $cor_subject['backload_year_level'] ?? null,
                    'section_name' => $cor['section_name'],
                    'program_code' => $cor['program_code']
                ];
            }
        }
    }
    
    // Sort subjects by academic year (desc), semester (desc), then course code
    usort($subjects, function($a, $b) {
        $year_compare = strcmp($b['academic_year'], $a['academic_year']);
        if ($year_compare !== 0) return $year_compare;
        
        $sem_compare = strcmp($b['semester'], $a['semester']);
        if ($sem_compare !== 0) return $sem_compare;
        
        return strcmp($a['subject_code'], $b['subject_code']);
    });
    
    // Get all grades for this student
    $grades_query = "SELECT sg.*, 
                     sg.curriculum_id as subject_id,
                     gs.grade_letter,
                     a.first_name as verified_by_name,
                     a.last_name as verified_by_lastname
                     FROM student_grades sg
                     LEFT JOIN grade_scale gs ON sg.grade = gs.grade_numeric
                     LEFT JOIN admins a ON sg.verified_by = a.id
                     WHERE sg.user_id = :user_id";
    
    $grades_stmt = $conn->prepare($grades_query);
    $grades_stmt->bindParam(':user_id', $user_id);
    $grades_stmt->execute();
    $grades = $grades_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'subjects' => $subjects,
        'grades' => $grades
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>

