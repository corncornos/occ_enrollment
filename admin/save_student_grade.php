<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$user_id = $_POST['user_id'] ?? null;
$subject_id = $_POST['subject_id'] ?? null; // This will be curriculum_id
$course_code = $_POST['course_code'] ?? null; // Fallback: course code from COR
$academic_year = $_POST['academic_year'] ?? null;
$semester = $_POST['semester'] ?? null;
$grade = $_POST['grade'] ?? null;
$grade_id = $_POST['grade_id'] ?? null;

if (!$user_id || (!$subject_id && !$course_code) || !$academic_year || !$semester || $grade === null) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit;
}

// Validate grade
if ($grade < 0 || $grade > 5) {
    echo json_encode(['success' => false, 'message' => 'Invalid grade value']);
    exit;
}

try {
    $conn = (new Database())->getConnection();
    
    // If subject_id is not provided, try to find curriculum_id from COR by course_code
    $curriculum_id = $subject_id;
    if (!$curriculum_id && $course_code) {
        // Find curriculum_id from COR subjects_json
        $cor_query = "SELECT cor.subjects_json, cor.program_id
                      FROM certificate_of_registration cor
                      WHERE cor.user_id = :user_id
                      AND cor.academic_year = :academic_year
                      AND cor.semester = :semester
                      ORDER BY cor.created_at DESC
                      LIMIT 1";
        $cor_stmt = $conn->prepare($cor_query);
        $cor_stmt->bindParam(':user_id', $user_id);
        $cor_stmt->bindParam(':academic_year', $academic_year);
        $cor_stmt->bindParam(':semester', $semester);
        $cor_stmt->execute();
        $cor_record = $cor_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($cor_record) {
            $subjects_json = json_decode($cor_record['subjects_json'], true);
            if (is_array($subjects_json)) {
                foreach ($subjects_json as $cor_subject) {
                    if (strtoupper(trim($cor_subject['course_code'] ?? '')) === strtoupper(trim($course_code))) {
                        // Found matching subject in COR, now find curriculum_id
                        $curriculum_lookup_query = "SELECT c.id
                                                   FROM curriculum c
                                                   WHERE UPPER(TRIM(c.course_code)) = :course_code
                                                   AND c.program_id = :program_id
                                                   AND c.year_level = :year_level
                                                   AND c.semester = :semester
                                                   LIMIT 1";
                        $curriculum_lookup_stmt = $conn->prepare($curriculum_lookup_query);
                        $curriculum_lookup_stmt->bindParam(':course_code', $course_code);
                        $curriculum_lookup_stmt->bindParam(':program_id', $cor_record['program_id']);
                        $curriculum_lookup_stmt->bindParam(':year_level', $cor_subject['year_level'] ?? null);
                        $curriculum_lookup_stmt->bindParam(':semester', $cor_subject['semester'] ?? null);
                        $curriculum_lookup_stmt->execute();
                        $curriculum_result = $curriculum_lookup_stmt->fetch(PDO::FETCH_ASSOC);
                        if ($curriculum_result) {
                            $curriculum_id = $curriculum_result['id'];
                            break;
                        }
                    }
                }
            }
        }
    }
    
    if (!$curriculum_id) {
        echo json_encode([
            'success' => false, 
            'message' => 'Cannot find curriculum for this subject. Please ensure the subject exists in the COR.'
        ]);
        exit;
    }
    
    // Validate that the subject is in the student's COR (enrollment verification)
    // For new grades, verify subject is in COR
    if (!$grade_id) {
        $cor_check_query = "SELECT cor.subjects_json
                           FROM certificate_of_registration cor
                           WHERE cor.user_id = :user_id
                           AND cor.academic_year = :academic_year
                           AND cor.semester = :semester
                           ORDER BY cor.created_at DESC
                           LIMIT 1";
        $cor_check_stmt = $conn->prepare($cor_check_query);
        $cor_check_stmt->bindParam(':user_id', $user_id);
        $cor_check_stmt->bindParam(':academic_year', $academic_year);
        $cor_check_stmt->bindParam(':semester', $semester);
        $cor_check_stmt->execute();
        $cor_check_record = $cor_check_stmt->fetch(PDO::FETCH_ASSOC);
        
        $subject_in_cor = false;
        if ($cor_check_record && !empty($cor_check_record['subjects_json'])) {
            $cor_subjects = json_decode($cor_check_record['subjects_json'], true);
            if (is_array($cor_subjects)) {
                // Check if curriculum_id matches or course_code matches
                foreach ($cor_subjects as $cor_subject) {
                    if ($course_code && strtoupper(trim($cor_subject['course_code'] ?? '')) === strtoupper(trim($course_code))) {
                        $subject_in_cor = true;
                        break;
                    }
                    // Also check if we can match by curriculum (if curriculum_id was found in COR)
                    // This is a fallback if course_code matching didn't work
                }
            }
        }
        
        if (!$subject_in_cor) {
            echo json_encode([
                'success' => false, 
                'message' => 'Cannot enter grade: Subject is not in the student\'s Certificate of Registration for ' . htmlspecialchars($academic_year) . ' ' . htmlspecialchars($semester) . '. Only subjects from COR can receive grades.'
            ]);
            exit;
        }
    }
    
    // For updates, verify the grade belongs to this student
    if ($grade_id) {
        $verify_grade_query = "SELECT id FROM student_grades 
                              WHERE id = :grade_id 
                              AND user_id = :user_id 
                              AND curriculum_id = :curriculum_id";
        $verify_stmt = $conn->prepare($verify_grade_query);
        $verify_stmt->bindParam(':grade_id', $grade_id);
        $verify_stmt->bindParam(':user_id', $user_id);
        $verify_stmt->bindParam(':curriculum_id', $curriculum_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->fetch();
        
        if (!$verify_result) {
            echo json_encode([
                'success' => false, 
                'message' => 'Cannot update grade: Grade record does not belong to this student or subject.'
            ]);
            exit;
        }
    }
    
    // Get the corresponding letter grade
    $grade_scale_query = "SELECT grade_letter FROM grade_scale 
                         WHERE grade_numeric = :grade 
                         OR (grade_numeric >= :grade - 0.12 AND grade_numeric <= :grade + 0.12)
                         ORDER BY ABS(grade_numeric - :grade)
                         LIMIT 1";
    $grade_scale_stmt = $conn->prepare($grade_scale_query);
    $grade_scale_stmt->bindParam(':grade', $grade);
    $grade_scale_stmt->execute();
    $grade_scale = $grade_scale_stmt->fetch(PDO::FETCH_ASSOC);
    $grade_letter = $grade_scale ? $grade_scale['grade_letter'] : 'N/A';
    
    if ($grade_id) {
        // Update existing grade
        $update_query = "UPDATE student_grades 
                        SET grade = :grade,
                            grade_letter = :grade_letter,
                            status = 'pending',
                            updated_at = NOW()
                        WHERE id = :grade_id AND user_id = :user_id";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bindParam(':grade', $grade);
        $update_stmt->bindParam(':grade_letter', $grade_letter);
        $update_stmt->bindParam(':grade_id', $grade_id);
        $update_stmt->bindParam(':user_id', $user_id);
        $update_stmt->execute();
    } else {
        // Insert new grade (curriculum_id is now properly resolved)
        $insert_query = "INSERT INTO student_grades 
                        (user_id, curriculum_id, academic_year, semester, grade, grade_letter, status)
                        VALUES (:user_id, :curriculum_id, :academic_year, :semester, :grade, :grade_letter, 'pending')
                        ON DUPLICATE KEY UPDATE 
                        grade = :grade,
                        grade_letter = :grade_letter,
                        status = 'pending',
                        updated_at = NOW()";
        $insert_stmt = $conn->prepare($insert_query);
        $insert_stmt->bindParam(':user_id', $user_id);
        $insert_stmt->bindParam(':curriculum_id', $curriculum_id);
        $insert_stmt->bindParam(':academic_year', $academic_year);
        $insert_stmt->bindParam(':semester', $semester);
        $insert_stmt->bindParam(':grade', $grade);
        $insert_stmt->bindParam(':grade_letter', $grade_letter);
        $insert_stmt->execute();
    }
    
    echo json_encode(['success' => true, 'message' => 'Grade saved successfully']);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>

