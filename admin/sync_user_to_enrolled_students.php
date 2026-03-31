<?php
/**
 * Helper function to sync user data to enrolled_students table
 * Copies all fields from users table to enrolled_students (except password, role, and account activation fields)
 * 
 * @param PDO $conn Database connection
 * @param int $user_id User ID to sync
 * @param array $additional_data Optional additional data (e.g., course, year_level, semester, academic_year)
 * @return bool Success status
 */
function syncUserToEnrolledStudents($conn, $user_id, $additional_data = []) {
    try {
        // Get all user data
        $user_query = "SELECT * FROM users WHERE id = :user_id";
        $user_stmt = $conn->prepare($user_query);
        $user_stmt->bindParam(':user_id', $user_id);
        $user_stmt->execute();
        $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user_data) {
            return false;
        }
        
        // Check if record exists
        $check_query = "SELECT id FROM enrolled_students WHERE user_id = :user_id";
        $check_stmt = $conn->prepare($check_query);
        $check_stmt->bindParam(':user_id', $user_id);
        $check_stmt->execute();
        $exists = $check_stmt->fetch();
        
        // Prepare all fields from users table (excluding password, role, activation fields)
        $fields = [
            'user_id' => $user_id,
            'student_id' => $user_data['student_id'],
            'first_name' => $user_data['first_name'],
            'last_name' => $user_data['last_name'],
            'email' => $user_data['email'],
            'phone' => $user_data['phone'],
            'date_of_birth' => $user_data['date_of_birth'],
            'address' => $user_data['address'],
            'status' => $user_data['status'],
            'enrollment_status' => $user_data['enrollment_status'],
            'middle_name' => $user_data['middle_name'] ?? null,
            'lrn' => $user_data['lrn'] ?? null,
            'occ_examinee_number' => $user_data['occ_examinee_number'] ?? null,
            'sex_at_birth' => $user_data['sex_at_birth'] ?? null,
            'age' => $user_data['age'] ?? null,
            'civil_status' => $user_data['civil_status'] ?? null,
            'spouse_name' => $user_data['spouse_name'] ?? null,
            'contact_number' => $user_data['contact_number'] ?? null,
            'father_name' => $user_data['father_name'] ?? null,
            'father_occupation' => $user_data['father_occupation'] ?? null,
            'father_education' => $user_data['father_education'] ?? null,
            'mother_maiden_name' => $user_data['mother_maiden_name'] ?? null,
            'mother_occupation' => $user_data['mother_occupation'] ?? null,
            'mother_education' => $user_data['mother_education'] ?? null,
            'number_of_brothers' => $user_data['number_of_brothers'] ?? 0,
            'number_of_sisters' => $user_data['number_of_sisters'] ?? 0,
            'combined_family_income' => $user_data['combined_family_income'] ?? null,
            'guardian_name' => $user_data['guardian_name'] ?? null,
            'school_last_attended' => $user_data['school_last_attended'] ?? null,
            'school_address' => $user_data['school_address'] ?? null,
            'is_pwd' => $user_data['is_pwd'] ?? 0,
            'hearing_disability' => $user_data['hearing_disability'] ?? 0,
            'physical_disability' => $user_data['physical_disability'] ?? 0,
            'mental_disability' => $user_data['mental_disability'] ?? 0,
            'intellectual_disability' => $user_data['intellectual_disability'] ?? 0,
            'psychosocial_disability' => $user_data['psychosocial_disability'] ?? 0,
            'chronic_illness_disability' => $user_data['chronic_illness_disability'] ?? 0,
            'learning_disability' => $user_data['learning_disability'] ?? 0,
            'shs_track' => $user_data['shs_track'] ?? null,
            'shs_strand' => $user_data['shs_strand'] ?? null,
            'is_working_student' => $user_data['is_working_student'] ?? 0,
            'employer' => $user_data['employer'] ?? null,
            'work_position' => $user_data['work_position'] ?? null,
            'working_hours' => $user_data['working_hours'] ?? null,
            'municipality_city' => $user_data['municipality_city'] ?? null,
            'permanent_address' => $user_data['permanent_address'] ?? null,
            'barangay' => $user_data['barangay'] ?? null,
            'preferred_program' => $user_data['preferred_program'] ?? null
        ];
        
        // Merge additional data (e.g., course, year_level, semester, academic_year)
        $fields = array_merge($fields, $additional_data);
        
        // Determine student type (Regular or Irregular) based on subject enrollment
        if (!isset($fields['student_type'])) {
            $fields['student_type'] = determineStudentType($conn, $user_id, $fields);
        }
        
        if ($exists) {
            // Update existing record (preserve enrolled_date if it exists)
            $update_fields = [];
            $update_values = [];
            foreach ($fields as $key => $value) {
                if ($key !== 'user_id' && $key !== 'enrolled_date') { // Don't update user_id or enrolled_date
                    $update_fields[] = "`{$key}` = :{$key}";
                    $update_values[":{$key}"] = $value;
                }
            }
            $update_values[':user_id'] = $user_id;
            
            $update_sql = "UPDATE enrolled_students SET " . implode(', ', $update_fields) . ", updated_at = NOW() WHERE user_id = :user_id";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->execute($update_values);
        } else {
            // Insert new record
            $insert_fields = array_keys($fields);
            $insert_placeholders = array_map(function($field) {
                return ":{$field}";
            }, $insert_fields);
            
            $insert_sql = "INSERT INTO enrolled_students (" . implode(', ', array_map(function($f) { return "`{$f}`"; }, $insert_fields)) . ", enrolled_date) 
                          VALUES (" . implode(', ', $insert_placeholders) . ", NOW())";
            $insert_stmt = $conn->prepare($insert_sql);
            
            $insert_values = [];
            foreach ($fields as $key => $value) {
                $insert_values[":{$key}"] = $value;
            }
            
            $insert_stmt->execute($insert_values);
        }
        
        return true;
    } catch (PDOException $e) {
        error_log('Error syncing user to enrolled_students: ' . $e->getMessage());
        return false;
    }
}

/**
 * Check if a student has failed grades (F, INC, or grade >= 5.0)
 * Used to determine if student is regular (all passed) or irregular (has failed grades)
 * 
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @return bool True if student has failed grades, False if all grades are passed
 */
function hasFailedGrades($conn, $user_id) {
    try {
        // Check for failed grades: F, INC, W (Withdrawn), Dropped, or grade >= 5.0
        $failed_grades_query = "SELECT COUNT(*) as failed_count
                              FROM student_grades sg
                              WHERE sg.user_id = :user_id
                              AND sg.status IN ('verified', 'finalized')
                              AND (
                                  sg.grade_letter IN ('F', 'FA', 'FAILED', 'INC', 'INCOMPLETE', 'W', 'WITHDRAWN', 'DROPPED')
                                  OR sg.grade >= 5.0
                              )";
        $failed_stmt = $conn->prepare($failed_grades_query);
        $failed_stmt->bindParam(':user_id', $user_id);
        $failed_stmt->execute();
        $failed_result = $failed_stmt->fetch(PDO::FETCH_ASSOC);
        
        return ($failed_result && (int)$failed_result['failed_count'] > 0);
    } catch (PDOException $e) {
        error_log('Error checking failed grades: ' . $e->getMessage());
        return false; // Default to no failed grades on error
    }
}

/**
 * Determine if a student is Regular or Irregular based on subject enrollment
 * A student is considered Irregular if they are not enrolled in all required subjects
 * for their year level and semester
 * 
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @param array $student_data Student data including year_level, semester, course/program
 * @return string 'Regular' or 'Irregular'
 */
function determineStudentType($conn, $user_id, $student_data) {
    try {
        $year_level = $student_data['year_level'] ?? null;
        $semester = $student_data['semester'] ?? null;
        
        if (!$year_level || !$semester) {
            return 'Regular'; // Default to Regular if insufficient data
        }
        
        // Get student's program ID
        $program_id = null;
        
        // Try to get program from section enrollment
        $program_query = "SELECT DISTINCT p.id 
                         FROM section_enrollments se
                         JOIN sections s ON se.section_id = s.id
                         JOIN programs p ON s.program_id = p.id
                         WHERE se.user_id = :user_id AND se.status = 'active'
                         LIMIT 1";
        $program_stmt = $conn->prepare($program_query);
        $program_stmt->bindParam(':user_id', $user_id);
        $program_stmt->execute();
        $program_result = $program_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($program_result) {
            $program_id = $program_result['id'];
        }
        
        if (!$program_id) {
            return 'Regular'; // Can't determine without program
        }
        
        // Get all REQUIRED subjects for this program, year level, and semester
        $required_subjects_query = "SELECT id, course_code 
                                   FROM curriculum 
                                   WHERE program_id = :program_id 
                                   AND year_level = :year_level 
                                   AND semester = :semester
                                   AND is_required = 1";
        $required_stmt = $conn->prepare($required_subjects_query);
        $required_stmt->bindParam(':program_id', $program_id);
        $required_stmt->bindParam(':year_level', $year_level);
        $required_stmt->bindParam(':semester', $semester);
        $required_stmt->execute();
        $required_subjects = $required_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($required_subjects)) {
            return 'Regular'; // No required subjects defined, assume Regular
        }
        
        // Get subjects the student is enrolled in for this section/semester
        // First, check next_semester_subject_selections (for current approval)
        $selected_subjects_query = "SELECT DISTINCT nsss.curriculum_id
                                   FROM next_semester_subject_selections nsss
                                   JOIN next_semester_enrollments nse ON nsss.enrollment_request_id = nse.id
                                   WHERE nse.user_id = :user_id 
                                   AND nse.request_status = 'approved'
                                   ORDER BY nse.processed_at DESC
                                   LIMIT 50";
        $selected_stmt = $conn->prepare($selected_subjects_query);
        $selected_stmt->bindParam(':user_id', $user_id);
        $selected_stmt->execute();
        $enrolled_subjects = $selected_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // If no next semester selections, check student_schedules (active subjects for enrolled students)
        if (empty($enrolled_subjects)) {
            $enrolled_subjects_query = "SELECT DISTINCT c.id
                                       FROM student_schedules ss
                                       JOIN curriculum c ON ss.course_code = c.course_code 
                                       WHERE ss.user_id = :user_id 
                                       AND ss.status = 'active'
                                       AND c.program_id = :program_id
                                       AND c.year_level = :year_level
                                       AND c.semester = :semester";
            $enrolled_stmt = $conn->prepare($enrolled_subjects_query);
            $enrolled_stmt->bindParam(':user_id', $user_id);
            $enrolled_stmt->bindParam(':program_id', $program_id);
            $enrolled_stmt->bindParam(':year_level', $year_level);
            $enrolled_stmt->bindParam(':semester', $semester);
            $enrolled_stmt->execute();
            $enrolled_subjects = $enrolled_stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        // Check if all required subjects are in enrolled subjects
        $missing_subjects = array_diff($required_subjects, $enrolled_subjects);
        
        // If there are missing required subjects, student is Irregular
        if (!empty($missing_subjects)) {
            return 'Irregular';
        }
        
        return 'Regular';
        
    } catch (PDOException $e) {
        error_log('Error determining student type: ' . $e->getMessage());
        return 'Regular'; // Default to Regular on error
    }
}
?>

