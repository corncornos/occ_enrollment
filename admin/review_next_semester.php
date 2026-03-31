<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../config/student_data_helper.php';
require_once 'enrollment_workflow_helper.php';

if (!isLoggedIn() || (!isAdmin() && !isProgramHead() && !isRegistrarStaff())) {
    redirect('public/login.php');
}

function redirectToRoleHome() {
    if (isProgramHead()) {
        redirect('program_head/dashboard.php');
    }
    if (isRegistrarStaff()) {
        redirect('registrar_staff/dashboard.php#next-semester-enrollments');
    }
    redirect('admin/dashboard.php');
}

$request_id = $_GET['request_id'] ?? null;

if (!$request_id) {
    $_SESSION['message'] = 'Invalid request ID.';
    redirectToRoleHome();
}

$conn = (new Database())->getConnection();

// Get request details
// Use centralized student data helper to ensure consistency
$request_query = "SELECT nse.*
                  FROM next_semester_enrollments nse
                  WHERE nse.id = :request_id";
$request_stmt = $conn->prepare($request_query);
$request_stmt->bindParam(':request_id', $request_id);
$request_stmt->execute();
$request = $request_stmt->fetch(PDO::FETCH_ASSOC);

// Get student data using centralized helper
if ($request) {
    $student_data = getStudentData($conn, $request['user_id'], true);
    if ($student_data) {
        // Merge student data into request array for consistency
        $request['student_id'] = $student_data['student_id'] ?? null;
        $request['first_name'] = $student_data['first_name'] ?? null;
        $request['last_name'] = $student_data['last_name'] ?? null;
        $request['email'] = $student_data['email'] ?? null;
    }
}

if (!$request) {
    $_SESSION['message'] = 'Request not found.';
    redirectToRoleHome();
}

// If program head, verify the request belongs to their program
// Use the same query logic as the dashboard to ensure consistency
if (isProgramHead()) {
    $program_code = $_SESSION['program_code'] ?? '';
    
    // Check if student's program matches program head's program
    // Use the same logic as the dashboard query
    $program_check_query = "SELECT DISTINCT pef.current_course, p.program_code
                           FROM next_semester_enrollments nse
                           JOIN users u ON nse.user_id = u.id
                           LEFT JOIN pre_enrollment_forms pef ON (pef.enrollment_request_id = nse.id OR (pef.user_id = nse.user_id AND pef.enrollment_request_id IS NULL))
                           LEFT JOIN section_enrollments se ON u.id = se.user_id AND se.status = 'active'
                           LEFT JOIN sections s ON se.section_id = s.id
                           LEFT JOIN programs p ON s.program_id = p.id
                           WHERE nse.id = :request_id
                           AND (
                               pef.current_course = :program_code
                               OR p.program_code = :program_code
                           )
                           LIMIT 1";
    $program_check_stmt = $conn->prepare($program_check_query);
    $program_check_stmt->bindParam(':request_id', $request_id);
    $program_check_stmt->bindParam(':program_code', $program_code);
    $program_check_stmt->execute();
    $program_check = $program_check_stmt->fetch(PDO::FETCH_ASSOC);
    
    // If no match found, redirect back
    if (!$program_check) {
        $_SESSION['message'] = 'You do not have permission to review this enrollment request. It belongs to a different program.';
        redirectToRoleHome();
    }
}

// Get pre-enrollment form to retrieve preferred shift
$pre_enrollment_form_query = "SELECT * FROM pre_enrollment_forms 
                              WHERE enrollment_request_id = :request_id 
                              OR (user_id = :user_id AND enrollment_request_id IS NULL)
                              ORDER BY created_at DESC LIMIT 1";
$pre_form_stmt = $conn->prepare($pre_enrollment_form_query);
$pre_form_stmt->bindParam(':request_id', $request_id);
$pre_form_stmt->bindParam(':user_id', $request['user_id']);
$pre_form_stmt->execute();
$pre_enrollment_form = $pre_form_stmt->fetch(PDO::FETCH_ASSOC);
$preferred_shift = $pre_enrollment_form['preferred_shift'] ?? null;

// Get selected section information (if any)
$selected_section = null;
$selected_section_subjects = [];
$is_irregular_2nd_year_above = false; // Initialize for UI use

if (!empty($request['selected_section_id'])) {
    $section_query = "SELECT s.*, p.program_code, p.program_name,
                      (s.max_capacity - s.current_enrolled) as available_slots
                      FROM sections s
                      JOIN programs p ON s.program_id = p.id
                      WHERE s.id = :section_id";
    $section_stmt = $conn->prepare($section_query);
    $section_stmt->bindParam(':section_id', $request['selected_section_id']);
    $section_stmt->execute();
    $selected_section = $section_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($selected_section) {
        // All students (regular and irregular) follow the same enrollment flow
        // Show only current year level subjects for the target semester (no backloads)
        $section_subjects_query = "SELECT 
                                        c.course_code,
                                        c.course_name,
                                        c.units,
                                        c.year_level,
                                        c.semester,
                                        COALESCE(ss.schedule_monday, 0) AS schedule_monday,
                                        COALESCE(ss.schedule_tuesday, 0) AS schedule_tuesday,
                                        COALESCE(ss.schedule_wednesday, 0) AS schedule_wednesday,
                                        COALESCE(ss.schedule_thursday, 0) AS schedule_thursday,
                                        COALESCE(ss.schedule_friday, 0) AS schedule_friday,
                                        COALESCE(ss.schedule_saturday, 0) AS schedule_saturday,
                                        COALESCE(ss.schedule_sunday, 0) AS schedule_sunday,
                                        ss.time_start,
                                        ss.time_end,
                                        ss.room,
                                        ss.professor_name
                                   FROM curriculum c
                                   LEFT JOIN section_schedules ss 
                                        ON c.id = ss.curriculum_id 
                                        AND ss.section_id = :section_id
                                   WHERE c.program_id = :program_id
                                   AND c.year_level = :year_level
                                   AND c.semester = :semester
                                   ORDER BY c.course_code";
        $section_subjects_stmt = $conn->prepare($section_subjects_query);
        $section_subjects_stmt->bindParam(':section_id', $request['selected_section_id']);
        $section_subjects_stmt->bindParam(':program_id', $selected_section['program_id']);
        $section_subjects_stmt->bindParam(':year_level', $selected_section['year_level']);
        $section_subjects_stmt->bindParam(':semester', $selected_section['semester']);
        $section_subjects_stmt->execute();
        $selected_section_subjects = $section_subjects_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Get selected subjects with prerequisite information (using curriculum) - for legacy requests
$subjects_query = "SELECT nsss.*, c.course_code as subject_code, c.course_name as subject_name, c.units,
                   sp.prerequisite_curriculum_id, sp.minimum_grade,
                   pc.course_code as prereq_code, pc.course_name as prereq_name,
                   sg.grade as student_grade, sg.grade_letter
                   FROM next_semester_subject_selections nsss
                   JOIN curriculum c ON nsss.curriculum_id = c.id
                   LEFT JOIN subject_prerequisites sp ON c.id = sp.curriculum_id
                   LEFT JOIN curriculum pc ON sp.prerequisite_curriculum_id = pc.id
                   LEFT JOIN student_grades sg ON pc.id = sg.curriculum_id AND sg.user_id = :user_id
                   WHERE nsss.enrollment_request_id = :request_id
                   ORDER BY c.course_code";
$subjects_stmt = $conn->prepare($subjects_query);
$subjects_stmt->bindParam(':request_id', $request_id);
$subjects_stmt->bindParam(':user_id', $request['user_id']);
$subjects_stmt->execute();
$selected_subjects = $subjects_stmt->fetchAll(PDO::FETCH_ASSOC);

// Determine if all prerequisite subjects (i.e., subjects from the current/previous semesters
// that are required for the next semester subjects) have grades that meet the minimum
// requirement. If any prerequisite is "Not taken", failed, withdrawn, dropped, or below the minimum grade, we will
// mark prerequisites as unmet (informational, doesn't block if Program Head has assessed).
$all_prerequisites_met = true;
if (!empty($selected_subjects)) {
    foreach ($selected_subjects as $subject) {
        if ($subject['prerequisite_curriculum_id']) {
            // Check if prerequisite is met
            $prereq_met = false;
            if ($subject['student_grade']) {
                $grade_letter_upper = strtoupper($subject['grade_letter'] ?? '');
                // Check if grade is valid (not failed, incomplete, withdrawn, or dropped)
                if (!in_array($grade_letter_upper, ['F', 'FA', 'FAILED', 'INC', 'INCOMPLETE', 'W', 'WITHDRAWN', 'DROPPED']) 
                    && $subject['student_grade'] <= $subject['minimum_grade'] 
                    && $subject['student_grade'] < 5.0) {
                    $prereq_met = true;
                }
            }
            if (!$prereq_met) {
                $all_prerequisites_met = false;
                break;
            }
        }
    }
}

// Get student's current grades (using curriculum)
$grades_query = "SELECT sg.*, c.course_code as subject_code, c.course_name as subject_name, c.year_level
                 FROM student_grades sg
                 JOIN curriculum c ON sg.curriculum_id = c.id
                 WHERE sg.user_id = :user_id
                 AND sg.status IN ('verified', 'finalized')
                 ORDER BY sg.academic_year DESC, sg.semester DESC, c.course_code";
$grades_stmt = $conn->prepare($grades_query);
$grades_stmt->bindParam(':user_id', $request['user_id']);
$grades_stmt->execute();
$student_grades = $grades_stmt->fetchAll(PDO::FETCH_ASSOC);

// Group grades by year level and semester for better organization
$grades_by_term = [];
foreach ($student_grades as $grade) {
    // Create a key using year_level and semester for proper grouping
    $term_key = ($grade['year_level'] ?? 'Unknown Year') . ' - ' . $grade['semester'];
    $display_key = $grade['academic_year'] . ' - ' . $grade['semester'];
    
    if (!isset($grades_by_term[$term_key])) {
        $grades_by_term[$term_key] = [
            'display_name' => $display_key,
            'year_level' => $grade['year_level'] ?? 'Unknown Year',
            'semester' => $grade['semester'],
            'academic_year' => $grade['academic_year'],
            'grades' => []
        ];
    }
    $grades_by_term[$term_key]['grades'][] = $grade;
}

// Determine if the student has complete grades for all subjects in their current semester
// Rule: For Second Semester target, all First Semester curriculum subjects for the student's
// current year level and program must have verified/finalized grades.
$all_current_sem_grades_complete = true;

// Only enforce this rule when the target semester is a Second Semester-type term
$is_second_sem_target = (stripos($request['target_semester'], 'Second') !== false || stripos($request['target_semester'], '2nd') !== false);

if ($is_second_sem_target) {
    // Get student's active program (same logic used later for section assignment)
    $program_sql = "SELECT DISTINCT p.id as program_id
                   FROM section_enrollments se
                   JOIN sections s ON se.section_id = s.id
                   JOIN programs p ON s.program_id = p.id
                   WHERE se.user_id = :user_id AND se.status = 'active'
                   LIMIT 1";
    $program_stmt = $conn->prepare($program_sql);
    $program_stmt->bindParam(':user_id', $request['user_id']);
    $program_stmt->execute();
    $program_data = $program_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($program_data) {
        $current_year_level = $request['current_year_level'];
        // For Second Semester target, current semester is assumed to be First Semester
        $current_semester_label = 'First Semester';
        
        // Count curriculum subjects for this program/year/semester that DO NOT have a grade
        $missing_grades_query = "SELECT COUNT(*) AS missing_count
                                FROM curriculum c
                                WHERE c.program_id = :program_id
                                AND c.year_level = :year_level
                                AND c.semester = :semester
                                AND NOT EXISTS (
                                    SELECT 1 
                                    FROM student_grades sg
                                    WHERE sg.curriculum_id = c.id
                                    AND sg.user_id = :user_id
                                    AND sg.status IN ('verified', 'finalized')
                                )";
        $missing_stmt = $conn->prepare($missing_grades_query);
        $missing_stmt->bindParam(':program_id', $program_data['program_id']);
        $missing_stmt->bindParam(':year_level', $current_year_level);
        $missing_stmt->bindParam(':semester', $current_semester_label);
        $missing_stmt->bindParam(':user_id', $request['user_id']);
        $missing_stmt->execute();
        $missing_row = $missing_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!empty($missing_row['missing_count']) && (int)$missing_row['missing_count'] > 0) {
            $all_current_sem_grades_complete = false;
        }
    }
}

// Determine who can approve/reject based on status and role
$can_take_action = false;
// Determine who can take action based on workflow status
// All students follow the same flow: Student → Registrar → Admin
if ($request['request_status'] == 'pending_registrar') {
    // Registrar staff and admins can review at this stage
    $can_take_action = isRegistrarStaff() || isAdmin();
} elseif ($request['request_status'] == 'pending_admin') {
    // Only admins can give final approval
    $can_take_action = isAdmin();
} else {
    $can_take_action = false;
}

// Registrar staff can generate COR when status is pending_registrar
$can_create_cor = (isRegistrarStaff() || isAdmin()) && $request['request_status'] == 'pending_registrar';

// Auto-assign sections and subjects when students reach registrar
// All students go directly to 'pending_registrar' status, so we need to auto-assign here
if ($request['request_status'] == 'pending_registrar') {
    require_once 'sync_user_to_enrolled_students.php';
    $has_failed = hasFailedGrades($conn, $request['user_id']);
    
    // Auto-assign for all students (same flow)
    if (true) {
        // Check if subjects have already been assigned
        $subjects_check = "SELECT COUNT(*) as count FROM next_semester_subject_selections 
                          WHERE enrollment_request_id = :request_id";
        $subjects_check_stmt = $conn->prepare($subjects_check);
        $subjects_check_stmt->bindParam(':request_id', $request_id);
        $subjects_check_stmt->execute();
        $subjects_result = $subjects_check_stmt->fetch(PDO::FETCH_ASSOC);
        
        // If no subjects assigned yet, auto-assign based on curriculum
        if ($subjects_result['count'] == 0 && !empty($request['selected_section_id'])) {
            try {
                $conn->beginTransaction();
                
                // Get section info
                $section_info_query = "SELECT program_id, year_level, semester FROM sections WHERE id = :section_id";
                $section_info_stmt = $conn->prepare($section_info_query);
                $section_info_stmt->bindParam(':section_id', $request['selected_section_id']);
                $section_info_stmt->execute();
                $section_info = $section_info_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($section_info) {
                    // Get all curriculum subjects for this program/year/semester
                    $auto_subjects_query = "SELECT id FROM curriculum 
                                           WHERE program_id = :program_id 
                                           AND year_level = :year_level 
                                           AND semester = :semester";
                    $auto_subjects_stmt = $conn->prepare($auto_subjects_query);
                    $auto_subjects_stmt->bindParam(':program_id', $section_info['program_id']);
                    $auto_subjects_stmt->bindParam(':year_level', $section_info['year_level']);
                    $auto_subjects_stmt->bindParam(':semester', $section_info['semester']);
                    $auto_subjects_stmt->execute();
                    
                    // Insert all subjects as approved
                    $insert_auto = $conn->prepare("INSERT INTO next_semester_subject_selections 
                                                  (enrollment_request_id, curriculum_id, status) 
                                                  VALUES (:request_id, :curriculum_id, 'approved')");
                    
                    while ($curr = $auto_subjects_stmt->fetch(PDO::FETCH_ASSOC)) {
                        $insert_auto->bindParam(':request_id', $request_id);
                        $insert_auto->bindParam(':curriculum_id', $curr['id']);
                        $insert_auto->execute();
                    }
                }
                
                $conn->commit();
            } catch (Exception $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                // Log error but don't block the page
                error_log('Error auto-assigning subjects for regular student: ' . $e->getMessage());
            }
        }
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'];
    
    // Handle subject selection updates (Program Head and Registrar Staff)
    if ($action === 'update_subjects' && (isProgramHead() || isRegistrarStaff() || isAdmin())) {
        try {
            $conn->beginTransaction();
            
            $selected_subjects = $_POST['selected_subjects'] ?? [];
            
            // Delete all existing subject selections for this request
            $delete_sql = "DELETE FROM next_semester_subject_selections WHERE enrollment_request_id = :request_id";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bindParam(':request_id', $request_id);
            $delete_stmt->execute();
            
            // Insert new selections
            // For registrar staff, set status to 'approved' so COR generation picks them up
            // For program head, set to 'pending' (will be approved later)
            $status = (isRegistrarStaff() || (isAdmin() && $request['request_status'] == 'pending_registrar')) ? 'approved' : 'pending';
            
            if (!empty($selected_subjects)) {
                $insert_sql = "INSERT INTO next_semester_subject_selections (enrollment_request_id, curriculum_id, status) 
                              VALUES (:request_id, :curriculum_id, :status)";
                $insert_stmt = $conn->prepare($insert_sql);
                
                foreach ($selected_subjects as $curriculum_id) {
                    if (!empty($curriculum_id)) {
                        $insert_stmt->bindParam(':request_id', $request_id);
                        $insert_stmt->bindParam(':curriculum_id', $curriculum_id);
                        $insert_stmt->bindParam(':status', $status);
                        $insert_stmt->execute();
                    }
                }
            }
            
            $conn->commit();
            $_SESSION['message'] = 'Subject selection updated successfully!';
            
            // Reload the page to show updated selections
            redirect('admin/review_next_semester.php?request_id=' . $request_id);
            
        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $_SESSION['message'] = 'Error updating subjects: ' . $e->getMessage();
        }
    }
    
    try {
        if ($action === 'approve') {
            $conn->beginTransaction();
            $is_program_head = isProgramHead();
            $is_registrar = isRegistrarStaff() || (isAdmin() && $request['request_status'] == 'pending_registrar');
            
            // If Program Head or Registrar is approving, first treat current checkbox state
            // as the source of truth for subject selection. This makes the
            // "Approve" button behave like "Save Subject Selection + Approve".
            if ($is_program_head || $is_registrar) {
                $current_selected_subjects = $_POST['selected_subjects'] ?? null;
                if (is_array($current_selected_subjects)) {
                    // Delete all existing subject selections for this request
                    $delete_sql = "DELETE FROM next_semester_subject_selections WHERE enrollment_request_id = :request_id";
                    $delete_stmt = $conn->prepare($delete_sql);
                    $delete_stmt->bindParam(':request_id', $request_id);
                    $delete_stmt->execute();

                    // Insert new selections based on the checkboxes the Program Head/Registrar sees
                    if (!empty($current_selected_subjects)) {
                        $insert_sql = "INSERT INTO next_semester_subject_selections (enrollment_request_id, curriculum_id, status) 
                                      VALUES (:request_id, :curriculum_id, 'pending')";
                        $insert_stmt = $conn->prepare($insert_sql);

                        foreach ($current_selected_subjects as $curriculum_id) {
                            if (!empty($curriculum_id)) {
                                $insert_stmt->bindParam(':request_id', $request_id);
                                $insert_stmt->bindParam(':curriculum_id', $curriculum_id);
                                $insert_stmt->execute();
                            }
                        }
                    }
                }
            }

            // Check if student is irregular (has failed grades)
            require_once 'sync_user_to_enrolled_students.php';
            $has_failed = hasFailedGrades($conn, $request['user_id']);
            
            // All students follow the same enrollment flow
            // Check if section is assigned
            if (empty($request['selected_section_id'])) {
                throw new Exception('Section must be assigned before approving enrollment.');
            }
            
            // NOTE (2025-12-01): We no longer auto-remove subjects with unmet prerequisites
            // All students follow the same enrollment flow
            // authority: if they leave a subject checked (even with unmet prerequisites),
            // it will remain in the approved list and appear in the COR. Warnings are shown
            // in the UI, but no automatic deletion happens here.
            
            // Mark all selected subjects as approved/ready (only update existing selections)
            $approve_subjects = "UPDATE next_semester_subject_selections 
                                SET status = 'approved' 
                                WHERE enrollment_request_id = :request_id";
            $approve_stmt = $conn->prepare($approve_subjects);
            $approve_stmt->bindParam(':request_id', $request_id);
            $approve_stmt->execute();
            
            // Check if subjects were already selected by Program Head
            // For irregular students reviewed by Program Head: respect their selections, don't auto-insert
            // For regular students or if no selections exist: auto-insert all subjects
            $count_check = "SELECT COUNT(*) as count FROM next_semester_subject_selections 
                           WHERE enrollment_request_id = :request_id";
            $count_stmt = $conn->prepare($count_check);
            $count_stmt->bindParam(':request_id', $request_id);
            $count_stmt->execute();
            $count_result = $count_stmt->fetch(PDO::FETCH_ASSOC);
            
            // Only auto-insert if:
            // 1. No subjects exist AND
            // Check subject selections
            $should_auto_insert = false;
            if ($count_result['count'] == 0 && !empty($request['selected_section_id'])) {
                // Check if this is a Program Head approving an irregular student
                // All students follow the same flow - auto-insert subjects
                $should_auto_insert = true;
            }
            
            if ($should_auto_insert) {
                // Auto-insert all subjects from the section
                $section_info_query = "SELECT program_id, year_level, semester FROM sections WHERE id = :section_id";
                $section_info_stmt = $conn->prepare($section_info_query);
                $section_info_stmt->bindParam(':section_id', $request['selected_section_id']);
                $section_info_stmt->execute();
                $section_info = $section_info_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($section_info) {
                    // Check if student is enrolling for 5th year or higher
                    // This happens when:
                    // 1. Student's current year level is "4th Year" (enrolling for 5th year)
                    // 2. Section's year level is 5th year or higher
                    $target_year_level = $section_info['year_level'];
                    $current_year_level = $request['current_year_level'] ?? '';
                    $is_5th_year_or_higher = false;
                    
                    // Check if section is 5th year or higher
                    if (stripos($target_year_level, '5th') !== false || stripos($target_year_level, 'Fifth') !== false ||
                        stripos($target_year_level, '6th') !== false || stripos($target_year_level, 'Sixth') !== false ||
                        stripos($target_year_level, '7th') !== false || stripos($target_year_level, 'Seventh') !== false ||
                        stripos($target_year_level, '8th') !== false || stripos($target_year_level, 'Eighth') !== false) {
                        $is_5th_year_or_higher = true;
                    }
                    // Check if student is 4th year (enrolling for what would be 5th year)
                    elseif (stripos($current_year_level, '4th') !== false || stripos($current_year_level, 'Fourth') !== false) {
                        // If student is 4th year, they're enrolling for 5th year
                        $is_5th_year_or_higher = true;
                    }
                    
                    // Get subjects from the section's year level and semester
                    $auto_subjects_query = "SELECT id FROM curriculum 
                                           WHERE program_id = :program_id 
                                           AND year_level = :year_level 
                                           AND semester = :semester";
                    $auto_subjects_stmt = $conn->prepare($auto_subjects_query);
                    $auto_subjects_stmt->bindParam(':program_id', $section_info['program_id']);
                    $auto_subjects_stmt->bindParam(':year_level', $section_info['year_level']);
                    $auto_subjects_stmt->bindParam(':semester', $section_info['semester']);
                    $auto_subjects_stmt->execute();
                    
                    $insert_auto = $conn->prepare("INSERT INTO next_semester_subject_selections 
                                                  (enrollment_request_id, curriculum_id, status) 
                                                  VALUES (:request_id, :curriculum_id, 'approved')");
                    
                    // Insert subjects from the section's year level
                    while ($curr = $auto_subjects_stmt->fetch(PDO::FETCH_ASSOC)) {
                        $insert_auto->bindParam(':request_id', $request_id);
                        $insert_auto->bindParam(':curriculum_id', $curr['id']);
                        $insert_auto->execute();
                    }
                    
                    // For 5th year and up, also auto-insert backload subjects
                    if ($is_5th_year_or_higher) {
                        // Get curriculum IDs the student has already passed
                        $passed_curr_query = "SELECT DISTINCT sg.curriculum_id
                                              FROM student_grades sg
                                              WHERE sg.user_id = :user_id
                                              AND sg.status IN ('verified', 'finalized')
                                              AND sg.grade < 5.0
                                              AND UPPER(COALESCE(sg.grade_letter, '')) NOT IN ('F','FA','FAILED','W','WITHDRAWN','DROPPED','INC','INCOMPLETE')";
                        $passed_stmt = $conn->prepare($passed_curr_query);
                        $passed_stmt->bindParam(':user_id', $request['user_id'], PDO::PARAM_INT);
                        $passed_stmt->execute();
                        $passed_curriculum_ids = $passed_stmt->fetchAll(PDO::FETCH_COLUMN);
                        
                        // Build list of lower year levels for backloads (1st Year through 4th Year)
                        $backload_year_levels = ['1st Year', '2nd Year', '3rd Year', '4th Year'];
                        
                        // Get backload subjects (from lower year levels, same semester, not passed)
                        $backload_placeholders = [];
                        foreach ($backload_year_levels as $idx => $yl) {
                            $backload_placeholders[] = ':backload_yl_' . $idx;
                        }
                        
                        $backload_query = "SELECT c.id FROM curriculum c
                                          WHERE c.program_id = :program_id
                                          AND c.year_level IN (" . implode(',', $backload_placeholders) . ")
                                          AND c.semester = :semester";
                        
                        // Exclude already-passed subjects
                        if (!empty($passed_curriculum_ids)) {
                            $passed_placeholders = [];
                            foreach ($passed_curriculum_ids as $idx => $pid) {
                                $passed_placeholders[] = ':passed_id_' . $idx;
                            }
                            $backload_query .= " AND c.id NOT IN (" . implode(',', $passed_placeholders) . ")";
                        }
                        
                        $backload_query .= " ORDER BY c.year_level, c.course_code";
                        
                        $backload_stmt = $conn->prepare($backload_query);
                        $backload_stmt->bindParam(':program_id', $section_info['program_id'], PDO::PARAM_INT);
                        $backload_stmt->bindParam(':semester', $section_info['semester']);
                        
                        // Bind year levels
                        foreach ($backload_year_levels as $idx => $yl) {
                            $backload_stmt->bindValue(':backload_yl_' . $idx, $yl);
                        }
                        
                        // Bind passed curriculum IDs
                        if (!empty($passed_curriculum_ids)) {
                            foreach ($passed_curriculum_ids as $idx => $pid) {
                                $backload_stmt->bindValue(':passed_id_' . $idx, $pid, PDO::PARAM_INT);
                            }
                        }
                        
                        $backload_stmt->execute();
                        
                        // Insert backload subjects
                        while ($backload_curr = $backload_stmt->fetch(PDO::FETCH_ASSOC)) {
                            // Check if already inserted (avoid duplicates)
                            $check_duplicate = $conn->prepare("SELECT id FROM next_semester_subject_selections 
                                                               WHERE enrollment_request_id = :request_id 
                                                               AND curriculum_id = :curriculum_id");
                            $check_duplicate->bindParam(':request_id', $request_id);
                            $check_duplicate->bindParam(':curriculum_id', $backload_curr['id'], PDO::PARAM_INT);
                            $check_duplicate->execute();
                            
                            if ($check_duplicate->rowCount() == 0) {
                                $insert_auto->bindParam(':request_id', $request_id);
                                $insert_auto->bindParam(':curriculum_id', $backload_curr['id']);
                                $insert_auto->execute();
                            }
                        }
                    }
                }
            }
            
            // Assign student to section - either selected section or preferred shift section
            require_once '../classes/Section.php';
            $section_obj = new Section();
            
            $section_to_assign = null;
            
            // If a section was selected, use that
            if (!empty($request['selected_section_id'])) {
                $section_to_assign = $request['selected_section_id'];
            } 
            // Otherwise, automatically assign to a section matching preferred shift
            elseif ($preferred_shift) {
                // Get student's program and target semester info
                $program_sql = "SELECT DISTINCT p.id as program_id
                               FROM section_enrollments se
                               JOIN sections s ON se.section_id = s.id
                               JOIN programs p ON s.program_id = p.id
                               WHERE se.user_id = :user_id AND se.status = 'active'
                               LIMIT 1";
                $program_stmt = $conn->prepare($program_sql);
                $program_stmt->bindParam(':user_id', $request['user_id']);
                $program_stmt->execute();
                $program_data = $program_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($program_data) {
                    // Determine target year level
                    $target_year_level = $request['current_year_level'];
                    $is_first_sem = (stripos($request['target_semester'], 'First') !== false || stripos($request['target_semester'], '1st') !== false);
                    
                    if ($is_first_sem) {
                        // Moving to First Semester means advancing to next year level
                        switch ($request['current_year_level']) {
                            case '1st Year': $target_year_level = '2nd Year'; break;
                            case '2nd Year': $target_year_level = '3rd Year'; break;
                            case '3rd Year': $target_year_level = '4th Year'; break;
                            case '4th Year': $target_year_level = '4th Year'; break;
                            default: $target_year_level = $request['current_year_level']; break;
                        }
                    }
                    // else: Second Semester means staying in same year level
                    
                    // Find available section matching preferred shift
                    $auto_section_sql = "SELECT s.id 
                                        FROM sections s
                                        WHERE s.program_id = :program_id
                                        AND s.year_level = :year_level
                                        AND s.semester = :semester
                                        AND s.section_type = :preferred_shift
                                        AND s.status = 'active'
                                        AND s.current_enrolled < s.max_capacity
                                        ORDER BY s.current_enrolled ASC, s.section_name ASC
                                        LIMIT 1";
                    $auto_section_stmt = $conn->prepare($auto_section_sql);
                    $auto_section_stmt->bindParam(':program_id', $program_data['program_id']);
                    $auto_section_stmt->bindParam(':year_level', $target_year_level);
                    $auto_section_stmt->bindParam(':semester', $request['target_semester']);
                    $auto_section_stmt->bindParam(':preferred_shift', $preferred_shift);
                    $auto_section_stmt->execute();
                    $auto_section = $auto_section_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($auto_section) {
                        $section_to_assign = $auto_section['id'];
                        
                        // Update the enrollment request with the auto-assigned section
                        $update_section_sql = "UPDATE next_semester_enrollments 
                                              SET selected_section_id = :section_id
                                              WHERE id = :request_id";
                        $update_section_stmt = $conn->prepare($update_section_sql);
                        $update_section_stmt->bindParam(':section_id', $section_to_assign);
                        $update_section_stmt->bindParam(':request_id', $request_id);
                        $update_section_stmt->execute();
                        
                        // Reload selected_section after auto-assignment
                        $section_query = "SELECT s.*, p.program_code, p.program_name,
                                          (s.max_capacity - s.current_enrolled) as available_slots
                                          FROM sections s
                                          JOIN programs p ON s.program_id = p.id
                                          WHERE s.id = :section_id";
                        $section_stmt = $conn->prepare($section_query);
                        $section_stmt->bindParam(':section_id', $section_to_assign);
                        $section_stmt->execute();
                        $selected_section = $section_stmt->fetch(PDO::FETCH_ASSOC);
                    }
                }
            }
            
            // Assign student to section if we have one
            // Note: We'll update enrolled_students separately after approval to ensure correct semester/year
            if ($section_to_assign) {
                // Check if student is already in this section
                $check_enrollment = $conn->prepare("SELECT id FROM section_enrollments 
                                                    WHERE user_id = :user_id AND section_id = :section_id AND status = 'active'");
                $check_enrollment->bindParam(':user_id', $request['user_id']);
                $check_enrollment->bindParam(':section_id', $section_to_assign);
                $check_enrollment->execute();
                
                if ($check_enrollment->rowCount() == 0) {
                    // Insert into section_enrollments directly (without updating enrolled_students yet)
                    $insert_enrollment = $conn->prepare("INSERT INTO section_enrollments (section_id, user_id, enrolled_date, status) 
                                                         VALUES (:section_id, :user_id, NOW(), 'active')");
                    $insert_enrollment->bindParam(':section_id', $section_to_assign);
                    $insert_enrollment->bindParam(':user_id', $request['user_id']);
                    $insert_enrollment->execute();
                    
                    // Update section current_enrolled count
                    $update_section = $conn->prepare("UPDATE sections SET current_enrolled = current_enrolled + 1 WHERE id = :section_id");
                    $update_section->bindParam(':section_id', $section_to_assign);
                    $update_section->execute();
                    
                    // Create student_schedules entries ONLY for approved subjects from next_semester_subject_selections
                    // This ensures student schedule matches enrolled subjects and COR
                    // Enhanced to handle backload subjects by finding appropriate sections
                    require_once '../config/section_assignment_helper.php';
                    
                    $approved_subjects_query = "SELECT nsss.curriculum_id, c.course_code, c.course_name, c.units, c.year_level, c.semester
                                               FROM next_semester_subject_selections nsss
                                               JOIN curriculum c ON nsss.curriculum_id = c.id
                                               WHERE nsss.enrollment_request_id = :request_id
                                               AND nsss.status = 'approved'";
                    $approved_subjects_stmt = $conn->prepare($approved_subjects_query);
                    $approved_subjects_stmt->bindParam(':request_id', $request_id);
                    $approved_subjects_stmt->execute();
                    $approved_subjects = $approved_subjects_stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Get approved curriculum IDs
                    $approved_curriculum_ids = array_column($approved_subjects, 'curriculum_id');
                    
                    // Get student's program and preferred shift for section assignment
                    $program_query = "SELECT DISTINCT p.id as program_id
                                   FROM section_enrollments se
                                   JOIN sections s ON se.section_id = s.id
                                   JOIN programs p ON s.program_id = p.id
                                   WHERE se.user_id = :user_id AND se.status = 'active'
                                   LIMIT 1";
                    $program_stmt = $conn->prepare($program_query);
                    $program_stmt->bindParam(':user_id', $request['user_id']);
                    $program_stmt->execute();
                    $program_data = $program_stmt->fetch(PDO::FETCH_ASSOC);
                    $student_program_id = $program_data['program_id'] ?? null;
                    
                    // Get preferred shift from pre-enrollment form
                    $preferred_shift = $pre_enrollment_form['preferred_shift'] ?? null;
                    
                    if (!empty($approved_subjects)) {
                        // Get target semester and academic year
                        $target_semester = $request['target_semester'];
                        $target_academic_year = $request['target_academic_year'];
                        
                        // Ensure selected_section is loaded if not already set
                        if (!$selected_section && $section_to_assign) {
                            $section_query = "SELECT s.*, p.program_code, p.program_name,
                                              (s.max_capacity - s.current_enrolled) as available_slots
                                              FROM sections s
                                              JOIN programs p ON s.program_id = p.id
                                              WHERE s.id = :section_id";
                            $section_stmt = $conn->prepare($section_query);
                            $section_stmt->bindParam(':section_id', $section_to_assign);
                            $section_stmt->execute();
                            $selected_section = $section_stmt->fetch(PDO::FETCH_ASSOC);
                        }
                        
                        // Get main section's year level to identify backloads
                        $main_section_year_level = $selected_section['year_level'] ?? null;
                        
                        // Process each approved subject
                        foreach ($approved_subjects as $subject) {
                            $curriculum_id = (int)$subject['curriculum_id'];
                            $subject_year_level = $subject['year_level'];
                            
                            // Determine if this is a backload subject
                            $is_backload = ($subject_year_level !== $main_section_year_level);
                            
                            $section_schedule_id = null;
                            
                            if (!$is_backload) {
                                // Subject is in main section - check if it exists in main section
                                $main_section_schedule_query = "SELECT ss.* FROM section_schedules ss
                                                               WHERE ss.section_id = :section_id
                                                               AND ss.curriculum_id = :curriculum_id
                                                               LIMIT 1";
                                $main_schedule_stmt = $conn->prepare($main_section_schedule_query);
                                $main_schedule_stmt->bindParam(':section_id', $section_to_assign, PDO::PARAM_INT);
                                $main_schedule_stmt->bindParam(':curriculum_id', $curriculum_id, PDO::PARAM_INT);
                                $main_schedule_stmt->execute();
                                $main_schedule = $main_schedule_stmt->fetch(PDO::FETCH_ASSOC);
                                
                                if ($main_schedule) {
                                    $section_schedule_id = $main_schedule['id'];
                                }
                            } else {
                                // Backload subject - find appropriate section offering this subject
                                $available_sections = findSectionsForSubject(
                                    $conn,
                                    $curriculum_id,
                                    $target_semester,
                                    $target_academic_year,
                                    $student_program_id,
                                    $preferred_shift
                                );
                                
                                if (!empty($available_sections)) {
                                    // Select best section
                                    $best_section = selectBestSection($available_sections, $preferred_shift);
                                    
                                    if ($best_section && isset($best_section['section_schedule_id'])) {
                                        $section_schedule_id = $best_section['section_schedule_id'];
                                    }
                                }
                            }
                            
                            // If we found a section schedule, assign student to it
                            if ($section_schedule_id) {
                                $assignment_result = assignStudentToSectionSchedule(
                                    $conn,
                                    (int)$request['user_id'],
                                    (int)$section_schedule_id,
                                    true, // Check for conflicts
                                    false // Don't manage transaction - already in one
                                );
                                
                                if (!$assignment_result['success']) {
                                    // Log warning but continue with other subjects
                                    error_log('Warning: Failed to assign subject ' . $subject['course_code'] . 
                                             ' to student ' . $request['user_id'] . ': ' . 
                                             $assignment_result['message']);
                                }
                            } else {
                                // Log warning if no section found for subject
                                error_log('Warning: No section found for subject ' . $subject['course_code'] . 
                                         ' (curriculum_id: ' . $curriculum_id . ')');
                            }
                        }
                        
                        // Deactivate any student_schedules that are not in approved subjects
                        // This ensures only enrolled subjects appear in student schedule
                        if (!empty($approved_curriculum_ids)) {
                            $placeholders_deactivate = implode(',', array_fill(0, count($approved_curriculum_ids), '?'));
                            $deactivate_all_query = "UPDATE student_schedules sts
                                                     JOIN section_schedules ss ON sts.section_schedule_id = ss.id
                                                     SET sts.status = 'dropped', sts.updated_at = NOW()
                                                     WHERE sts.user_id = ?
                                                     AND sts.status = 'active'
                                                     AND ss.curriculum_id NOT IN ($placeholders_deactivate)";
                            $deactivate_all_stmt = $conn->prepare($deactivate_all_query);
                            $deactivate_all_stmt->bindValue(1, $request['user_id'], PDO::PARAM_INT);
                            foreach ($approved_curriculum_ids as $idx => $curriculum_id) {
                                $deactivate_all_stmt->bindValue($idx + 2, $curriculum_id, PDO::PARAM_INT);
                            }
                            $deactivate_all_stmt->execute();
                        }
                    }
                }
            }

            // Update enrolled_students and final data only when an admin (registrar) confirms
            // Program heads submit to registrar for confirmation (request_status = under_review)
            
            // Determine the new year level and program details for auto subject assignment / finalization
            $target_academic_year = $request['target_academic_year'];
            $target_semester = $request['target_semester'];
            $current_year_level = $request['current_year_level'];
            $new_year_level = $current_year_level;
            $is_first_sem = (stripos($target_semester, 'First') !== false || stripos($target_semester, '1st') !== false);
            if ($is_first_sem) {
                switch ($current_year_level) {
                    case '1st Year': $new_year_level = '2nd Year'; break;
                    case '2nd Year': $new_year_level = '3rd Year'; break;
                    case '3rd Year': $new_year_level = '4th Year'; break;
                    case '4th Year': $new_year_level = '5th Year'; break;
                    default: $new_year_level = $current_year_level; break;
                }
            }
            
            // Use centralized helper to get student data for consistency
            $student_data = getStudentData($conn, $request['user_id'], true);
            
            // Get first active program as default fallback (instead of hardcoded BSE)
            require_once '../classes/Curriculum.php';
            $curriculum = new Curriculum();
            $all_programs = $curriculum->getAllPrograms();
            $active_programs = array_filter($all_programs, function($p) {
                return ($p['status'] ?? 'active') === 'active';
            });
            $default_program = !empty($active_programs) ? reset($active_programs)['program_code'] : 'BSE';
            
            $program_code = $default_program;
            
            if ($student_data) {
                // Try to get program from enrolled_students first
                $program_code = $student_data['course'] ?? null;
                
                // If not found, get from sections
                if (!$program_code) {
                    $sections_data = getStudentDataWithSections($conn, $request['user_id'], true);
                    if ($sections_data && !empty($sections_data['sections'])) {
                        $program_code = $sections_data['sections'][0]['program_code'] ?? $default_program;
                    }
                }
            }
            
            // Final fallback - use first active program
            if (!$program_code) {
                $program_code = $default_program;
            }

            // Note: Subject selection is now handled earlier in the approval process
            // All students follow the same enrollment flow (no program head step)
            
            if ((isRegistrarStaff() || isAdmin()) && $request['request_status'] == 'pending_registrar') {
                // Registrar processes enrollment: generates COR and forwards to admin
                $approver_id = (int)$_SESSION['user_id'];
                $remarks = $_POST['remarks'] ?? null;
                
                // Update status to pending_admin (COR will be generated separately)
                // Don't manage transaction, we're already in one
                $success = updateEnrollmentStatus(
                    $conn, 
                    $request_id, 
                    'pending_admin', 
                    'registrar', 
                    $approver_id, 
                    'approved', 
                    $remarks,
                    false  // Don't manage transaction - already in one
                );
                
                if (!$success) {
                    throw new Exception('Failed to update enrollment status');
                }
                
                // Note: COR generation should be done separately via generate_cor.php
                // The status is now pending_admin, waiting for admin final approval
                $conn->commit();
                $request['request_status'] = 'pending_admin';
                $_SESSION['message'] = 'Enrollment processed. Please generate COR and forward to Admin for final approval.';
            } elseif (isAdmin() && $request['request_status'] == 'pending_admin') {
                // Final approval by admin
                $approver_id = (int)$_SESSION['user_id'];
                $remarks = $_POST['remarks'] ?? null;
                
                // Update status to confirmed
                // Don't manage transaction, we're already in one
                $success = updateEnrollmentStatus(
                    $conn, 
                    $request_id, 
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

                // Upsert into enrolled_students
                // Use centralized helper to get student data for consistency
                $student_check = getStudentData($conn, $request['user_id'], true);
                $existing = null;
                if ($student_check) {
                    // Check if enrolled_students record exists
                    $check_sql = "SELECT id FROM enrolled_students WHERE user_id = :user_id";
                    $check_stmt = $conn->prepare($check_sql);
                    $check_stmt->bindParam(':user_id', $request['user_id']);
                    $check_stmt->execute();
                    $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);
                }

                require_once 'sync_user_to_enrolled_students.php';
                require_once '../config/student_data_helper.php';
                
                // Ensure data sync after enrollment approval
                ensureStudentDataSync($conn, $request['user_id'], [
                    'course' => $program_code,
                    'year_level' => $new_year_level,
                    'academic_year' => $target_academic_year,
                    'semester' => $target_semester
                ]);

                $uupd = $conn->prepare("UPDATE users SET enrollment_status = 'enrolled' WHERE id = :user_id");
                $uupd->bindParam(':user_id', $request['user_id']);
                $uupd->execute();
                
                if ($section_to_assign) {
                    $request['selected_section_id'] = $section_to_assign;
                }
                $conn->commit();
                $request['request_status'] = 'approved';
                $_SESSION['message'] = 'Enrollment request approved successfully!';
            } else {
                // Unauthorized user trying to approve
                throw new Exception('You do not have permission to approve enrollments.');
            }
        } elseif ($action === 'reject') {
            // Only program heads and admins can reject (not registrar staff)
            if (!isProgramHead() && !isAdmin()) {
                throw new Exception('You do not have permission to reject enrollments.');
            }
            
            $rejection_reason = $_POST['rejection_reason'];
            
            // Determine approver role and ID based on current status
            $approver_id = (int)$_SESSION['user_id'];
            $approver_role = 'admin'; // Default
            
            if ($request['request_status'] == 'pending_program_head' && isProgramHead()) {
                $approver_role = 'program_head';
            } elseif ($request['request_status'] == 'pending_registrar' && (isRegistrarStaff() || isAdmin())) {
                $approver_role = 'registrar';
            } elseif ($request['request_status'] == 'pending_admin' && isAdmin()) {
                $approver_role = 'admin';
            }
            
            // Update status to rejected and record approval
            // Don't manage transaction - we're not in one for rejection
            $success = updateEnrollmentStatus(
                $conn, 
                $request_id, 
                'rejected', 
                $approver_role, 
                $approver_id, 
                'rejected', 
                $rejection_reason,
                true  // Manage transaction for rejection (no outer transaction)
            );
            
            if (!$success) {
                throw new Exception('Failed to reject enrollment');
            }
            
            // Also update rejection_reason field
            $update_reason = "UPDATE next_semester_enrollments 
                             SET rejection_reason = :reason
                             WHERE id = :request_id";
            $update_reason_stmt = $conn->prepare($update_reason);
            $update_reason_stmt->bindParam(':reason', $rejection_reason);
            $update_reason_stmt->bindParam(':request_id', $request_id);
            $update_reason_stmt->execute();
            
            $_SESSION['message'] = 'Enrollment request rejected.';
        } elseif ($action === 'revert') {
            if ($request['request_status'] !== 'approved') {
                throw new Exception('Only approved requests can be reverted.');
            }
            
            $revert_sql = "UPDATE next_semester_enrollments 
                           SET request_status = 'pending',
                               processed_by = NULL,
                               processed_at = NULL,
                               grades_verified = 0,
                               prerequisites_checked = 0,
                               rejection_reason = NULL
                           WHERE id = :request_id";
            $revert_stmt = $conn->prepare($revert_sql);
            $revert_stmt->bindParam(':request_id', $request_id);
            $revert_stmt->execute();
            
            $request['request_status'] = 'pending';
            $request['processed_by'] = null;
            $request['processed_at'] = null;
            $request['rejection_reason'] = null;
            
            $_SESSION['message'] = 'Enrollment request has been reverted back to pending status.';
        }
        
        // Redirect based on user role
        if (isProgramHead()) {
            redirect('program_head/dashboard.php');
        } else {
            redirect('admin/dashboard.php');
        }
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $error_message = 'Error processing request: ' . $e->getMessage();
        error_log('Enrollment approval error: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        $_SESSION['message'] = $error_message;
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $error_message = 'Database error: ' . $e->getMessage();
        error_log('PDO Error in enrollment approval: ' . $e->getMessage());
        error_log('SQL State: ' . $e->getCode());
        $_SESSION['message'] = $error_message;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Next Semester Enrollment - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px 0;
        }
        .card {
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            margin-bottom: 20px;
        }
        .prereq-met { background-color: #d4edda; }
        .prereq-not-met { background-color: #f8d7da; }
        .subject-item {
            transition: all 0.3s ease;
        }
        .subject-item:has(.subject-checkbox:checked) {
            border-color: #0d6efd !important;
            border-width: 2px !important;
        }
        .subject-item:has(.subject-checkbox:not(:checked)) {
            opacity: 0.6;
        }
        .subject-item:hover {
            transform: translateX(5px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if (!empty($_SESSION['message'])): ?>
            <div class="alert alert-info alert-dismissible fade show mt-2" role="alert">
                <i class="fas fa-info-circle me-2"></i>
                <?php 
                    echo htmlspecialchars($_SESSION['message']); 
                    unset($_SESSION['message']);
                ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>
        <div class="row mb-3">
            <div class="col">
                <?php if (isProgramHead()): ?>
                    <a href="<?php echo BASE_URL . 'program_head/dashboard.php'; ?>" class="btn btn-light">
                        <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                    </a>
                <?php else: ?>
                    <a href="dashboard.php" class="btn btn-light">
                        <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Review Next Semester Enrollment 
                    <?php if (isProgramHead()): ?>
                        <span class="badge bg-light text-dark ms-2">Program Head</span>
                    <?php endif; ?>
                </h4>
            </div>
            <div class="card-body">
                <div class="row mb-4">
                    <div class="col-md-6">
                        <h5>Student Information</h5>
                        <table class="table table-sm">
                            <tr><th>Student ID:</th><td><?php echo htmlspecialchars($request['student_id']); ?></td></tr>
                            <tr><th>Name:</th><td><?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></td></tr>
                            <tr><th>Email:</th><td><?php echo htmlspecialchars($request['email']); ?></td></tr>
                            <tr><th>Current Level:</th><td><?php echo htmlspecialchars($request['current_year_level']); ?></td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h5>Enrollment Details</h5>
                        <table class="table table-sm">
                            <tr><th>Target Academic Year:</th><td><?php echo htmlspecialchars($request['target_academic_year']); ?></td></tr>
                            <tr><th>Target Semester:</th><td><?php echo htmlspecialchars($request['target_semester']); ?></td></tr>
                            <tr><th>Request Status:</th><td><span class="badge bg-warning"><?php echo strtoupper($request['request_status']); ?></span></td></tr>
                            <tr><th>Submitted:</th><td><?php echo date('F j, Y g:i A', strtotime($request['created_at'])); ?></td></tr>
                        </table>
                    </div>
                </div>

                <?php if ($selected_section): ?>
                    <h5 class="mb-3">Selected Section for Next Semester</h5>
                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col-md-8">
                                    <h5 class="mb-2">
                                        <strong><?php echo htmlspecialchars($selected_section['section_name']); ?></strong>
                                        <span class="badge <?php echo $selected_section['section_type'] == 'Morning' ? 'bg-info' : ($selected_section['section_type'] == 'Afternoon' ? 'bg-warning' : 'bg-dark'); ?> ms-2">
                                            <?php echo htmlspecialchars($selected_section['section_type']); ?>
                                        </span>
                                    </h5>
                                    <p class="mb-1">
                                        <strong>Program:</strong> <?php echo htmlspecialchars($selected_section['program_code'] . ' - ' . $selected_section['program_name']); ?>
                                    </p>
                                    <p class="mb-1">
                                        <strong>Year Level:</strong> <?php echo htmlspecialchars($selected_section['year_level']); ?>
                                    </p>
                                    <p class="mb-0">
                                        <strong>Semester:</strong> <?php echo htmlspecialchars($selected_section['semester']); ?>
                                    </p>
                                </div>
                                <div class="col-md-4 text-end">
                                    <div class="mb-2">
                                        <span class="badge <?php 
                                            $pct = ($selected_section['current_enrolled'] / $selected_section['max_capacity']) * 100;
                                            echo $pct >= 90 ? 'bg-danger' : ($pct >= 70 ? 'bg-warning' : 'bg-success');
                                        ?> fs-6">
                                            <?php echo $selected_section['available_slots']; ?> slots available
                                        </span>
                                    </div>
                                    <p class="text-muted mb-0">
                                        <?php echo $selected_section['current_enrolled']; ?> / <?php echo $selected_section['max_capacity']; ?> enrolled
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php if (!empty($selected_section_subjects)): ?>
                        <?php
                        // Count total subjects and subjects with unmet prerequisites
                        $total_subjects_in_section = count($selected_section_subjects);
                        $subjects_with_unmet_prereqs = 0;
                        
                        // This will be calculated in the loop below
                        ?>
                        <h6 class="fw-bold mb-3">
                            <i class="fas fa-clipboard-check me-2"></i>Subjects to be Enrolled
                            <small class="text-muted">(Checklist)</small>
                            <span id="prerequisitesSummary" class="badge bg-warning ms-2" style="display: none;"></span>
                        </h6>
                        <div class="alert alert-info mb-3">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Note:</strong> 
                            Check/uncheck subjects to customize the enrollment. Only checked subjects will be enrolled for the student upon approval.
                            <br><small class="mt-1 d-block"><strong>Prerequisite Information:</strong> Subjects with unmet prerequisites will show warnings (informational only). All subjects are selectable regardless of prerequisites.</small>
                            <ul class="small mb-0 mt-1">
                                <li>Prerequisite warnings are informational only</li>
                                <li>All subjects can be selected regardless of prerequisite status</li>
                            </ul>
                        </div>
                        
                        <?php
                        // Get currently selected curriculum IDs from next_semester_subject_selections
                        $selected_curriculum_ids = [];
                        if ($request_id) {
                            $selected_curr_query = "SELECT curriculum_id FROM next_semester_subject_selections 
                                                   WHERE enrollment_request_id = :request_id";
                            $selected_curr_stmt = $conn->prepare($selected_curr_query);
                            $selected_curr_stmt->bindParam(':request_id', $request_id);
                            $selected_curr_stmt->execute();
                            $selected_curriculum_ids = $selected_curr_stmt->fetchAll(PDO::FETCH_COLUMN);
                        }
                        
                        // Map curriculum IDs by course code and get prerequisites
                        $curriculum_map = [];
                        $prerequisites_map = [];
                        $student_grades_map = [];
                        
                        if (!empty($selected_section_subjects)) {
                            $program_id = $selected_section['program_id'];
                            $semester = $selected_section['semester'];
                            
                            // Get curriculum IDs and course codes for all subjects shown (including backloads)
                            // Use the same year levels as the section_subjects_query
                            $year_levels_for_map = [];
                            $backload_year_levels = [];
                            if ($is_irregular_2nd_year_above) {
                                $target_year_level = $selected_section['year_level'];
                                $year_levels_for_map = [$target_year_level];
                                if (stripos($target_year_level, '2nd') !== false || stripos($target_year_level, 'Second') !== false) {
                                    $backload_year_levels = ['1st Year'];
                                } elseif (stripos($target_year_level, '3rd') !== false || stripos($target_year_level, 'Third') !== false) {
                                    $backload_year_levels = ['1st Year', '2nd Year'];
                                } elseif (stripos($target_year_level, '4th') !== false || stripos($target_year_level, 'Fourth') !== false) {
                                    $backload_year_levels = ['1st Year', '2nd Year', '3rd Year'];
                                }
                            } else {
                                $year_levels_for_map = [$selected_section['year_level']];
                            }
                            
                            // Build query: current year level (target semester only) + backloads (same semester)
                            $target_year_placeholder = '?';
                            $backload_placeholders = !empty($backload_year_levels) ? implode(',', array_fill(0, count($backload_year_levels), '?')) : '0';
                            
                            $curr_map_query = "SELECT id, course_code FROM curriculum 
                                              WHERE program_id = ? 
                                              AND (
                                                  (year_level = $target_year_placeholder AND semester = ?)  -- Current year: target semester only
                                                  " . (!empty($backload_year_levels) ? " OR (year_level IN ($backload_placeholders) AND semester = ?)" : "") . "
                                              )";
                            $curr_map_stmt = $conn->prepare($curr_map_query);
                            $param_idx = 1;
                            $curr_map_stmt->bindValue($param_idx++, $program_id, PDO::PARAM_INT);
                            // Bind target year level and semester
                            $curr_map_stmt->bindValue($param_idx++, $target_year_level ?? $selected_section['year_level']);
                            $curr_map_stmt->bindValue($param_idx++, $semester);
                            // Bind backload year levels + semester when applicable
                            foreach ($backload_year_levels as $yl) {
                                $curr_map_stmt->bindValue($param_idx++, $yl);
                            }
                            if (!empty($backload_year_levels)) {
                                // Semester filter for backload mapping (same semester)
                                $curr_map_stmt->bindValue($param_idx++, $semester);
                            }
                            $curr_map_stmt->execute();
                            while ($row = $curr_map_stmt->fetch(PDO::FETCH_ASSOC)) {
                                $curriculum_map[$row['course_code']] = $row['id'];
                            }
                            
                            // Get prerequisites for all subjects in this section (including backloads)
                            // Use curriculum IDs from the map which includes all shown subjects
                            $curriculum_ids = array_values($curriculum_map);
                            if (!empty($curriculum_ids)) {
                                $placeholders = implode(',', array_fill(0, count($curriculum_ids), '?'));
                                $prereq_query = "
                                    SELECT 
                                        c.course_code,
                                        sp.minimum_grade,
                                        pc.course_code AS prereq_code,
                                        pc.course_name AS prereq_name,
                                        pc.id AS prereq_curriculum_id
                                    FROM curriculum c
                                    LEFT JOIN subject_prerequisites sp ON c.id = sp.curriculum_id
                                    LEFT JOIN curriculum pc ON sp.prerequisite_curriculum_id = pc.id
                                    WHERE c.id IN ($placeholders)";
                                $prereq_stmt = $conn->prepare($prereq_query);
                                foreach ($curriculum_ids as $idx => $curriculum_id) {
                                    $prereq_stmt->bindValue($idx + 1, $curriculum_id, PDO::PARAM_INT);
                                }
                                $prereq_stmt->execute();
                                while ($row = $prereq_stmt->fetch(PDO::FETCH_ASSOC)) {
                                    if ($row['prereq_curriculum_id']) {
                                        if (!isset($prerequisites_map[$row['course_code']])) {
                                            $prerequisites_map[$row['course_code']] = [];
                                        }
                                        $prerequisites_map[$row['course_code']][] = [
                                            'prereq_code' => $row['prereq_code'],
                                            'prereq_name' => $row['prereq_name'],
                                            'prereq_curriculum_id' => $row['prereq_curriculum_id'],
                                            'minimum_grade' => $row['minimum_grade']
                                        ];
                                    }
                                }
                            }
                            
                            // Get student's grades for all subjects they've taken
                            $grades_query = "SELECT sg.curriculum_id, sg.grade, sg.grade_letter, c.course_code
                                           FROM student_grades sg
                                           JOIN curriculum c ON sg.curriculum_id = c.id
                                           WHERE sg.user_id = :user_id
                                           AND sg.status IN ('verified', 'finalized')";
                            $grades_stmt = $conn->prepare($grades_query);
                            $grades_stmt->bindParam(':user_id', $request['user_id']);
                            $grades_stmt->execute();
                            while ($row = $grades_stmt->fetch(PDO::FETCH_ASSOC)) {
                                $student_grades_map[$row['curriculum_id']] = [
                                    'grade' => $row['grade'],
                                    'grade_letter' => $row['grade_letter'],
                                    'course_code' => $row['course_code']
                                ];
                            }
                        }
                        ?>
                        
                        <form id="subjectsForm" method="POST" action="">
                            <input type="hidden" name="action" value="update_subjects">
                            <div class="card mb-4">
                                <div class="card-body">
                                    <?php 
                                    $total_units = 0;
                                    $selected_units = 0;
                                    foreach ($selected_section_subjects as $index => $section_subject): 
                                        $total_units += $section_subject['units'];
                                        
                                        $curriculum_id = $curriculum_map[$section_subject['course_code']] ?? null;
                                        
                                        // Check if subject has prerequisites (for informational purposes only)
                                        // All subjects are selectable regardless of prerequisites
                                        $has_prerequisites = isset($prerequisites_map[$section_subject['course_code']]);
                                        $prerequisites_met = true;
                                        $unmet_prerequisites = [];
                                        
                                        if ($has_prerequisites) {
                                            foreach ($prerequisites_map[$section_subject['course_code']] as $prereq) {
                                                $prereq_grade = $student_grades_map[$prereq['prereq_curriculum_id']] ?? null;
                                                
                                                if (!$prereq_grade) {
                                                    // Student hasn't taken this prerequisite
                                                    $prerequisites_met = false;
                                                    $unmet_prerequisites[] = [
                                                        'code' => $prereq['prereq_code'],
                                                        'name' => $prereq['prereq_name'],
                                                        'reason' => 'Not taken',
                                                        'minimum_grade' => $prereq['minimum_grade']
                                                    ];
                                                } elseif (strtoupper($prereq_grade['grade_letter']) == 'INC' || strtoupper($prereq_grade['grade_letter']) == 'INCOMPLETE') {
                                                    // Student has INC (Incomplete) grade
                                                    $prerequisites_met = false;
                                                    $unmet_prerequisites[] = [
                                                        'code' => $prereq['prereq_code'],
                                                        'name' => $prereq['prereq_name'],
                                                        'reason' => 'Grade is INC (Incomplete)',
                                                        'minimum_grade' => $prereq['minimum_grade'],
                                                        'student_grade' => $prereq_grade['grade_letter']
                                                    ];
                                                } elseif (in_array(strtoupper($prereq_grade['grade_letter']), ['F', 'FA', 'FAILED', 'W', 'WITHDRAWN', 'DROPPED'])) {
                                                    // Student has failing, withdrawn, or dropped grade
                                                    $grade_label = strtoupper($prereq_grade['grade_letter']);
                                                    if ($grade_label == 'W' || $grade_label == 'WITHDRAWN') {
                                                        $reason_text = 'Grade ' . $prereq_grade['grade_letter'] . ' (Withdrawn)';
                                                    } elseif ($grade_label == 'DROPPED') {
                                                        $reason_text = 'Grade ' . $prereq_grade['grade_letter'] . ' (Dropped)';
                                                    } else {
                                                        $reason_text = 'Grade ' . $prereq_grade['grade_letter'] . ' (Failed)';
                                                    }
                                                    $prerequisites_met = false;
                                                    $unmet_prerequisites[] = [
                                                        'code' => $prereq['prereq_code'],
                                                        'name' => $prereq['prereq_name'],
                                                        'reason' => $reason_text,
                                                        'minimum_grade' => $prereq['minimum_grade'],
                                                        'student_grade' => $prereq_grade['grade_letter']
                                                    ];
                                                } elseif ($prereq_grade['grade'] >= 5.0) {
                                                    // Student has grade of 5.0 or higher (failing/incomplete)
                                                    $prerequisites_met = false;
                                                    $unmet_prerequisites[] = [
                                                        'code' => $prereq['prereq_code'],
                                                        'name' => $prereq['prereq_name'],
                                                        'reason' => 'Grade ' . $prereq_grade['grade_letter'] . ' (' . $prereq_grade['grade'] . ')',
                                                        'minimum_grade' => $prereq['minimum_grade'],
                                                        'student_grade' => $prereq_grade['grade_letter']
                                                    ];
                                                } elseif ($prereq_grade['grade'] > $prereq['minimum_grade']) {
                                                    // Student's grade doesn't meet minimum requirement
                                                    $prerequisites_met = false;
                                                    $unmet_prerequisites[] = [
                                                        'code' => $prereq['prereq_code'],
                                                        'name' => $prereq['prereq_name'],
                                                        'reason' => 'Grade ' . $prereq_grade['grade_letter'] . ' (' . $prereq_grade['grade'] . ') does not meet minimum ' . $prereq['minimum_grade'],
                                                        'minimum_grade' => $prereq['minimum_grade'],
                                                        'student_grade' => $prereq_grade['grade_letter']
                                                    ];
                                                }
                                            }
                                        }
                                        
                                        // All subjects are selectable (prerequisites are informational only)
                                        $can_select = true;
                                        
                                        // Check if subject is currently selected
                                        $is_checked = (empty($selected_curriculum_ids) || in_array($curriculum_id, $selected_curriculum_ids));
                                        
                                        // Auto-check subjects when no prior selections exist (same for all students)
                                        if (empty($selected_curriculum_ids)) {
                                            $is_checked = true;
                                        }
                                        
                                        if ($is_checked) {
                                            $selected_units += $section_subject['units'];
                                        }
                                        
                                        $days = [];
                                        if (!empty($section_subject['schedule_monday'])) $days[] = 'Mon';
                                        if (!empty($section_subject['schedule_tuesday'])) $days[] = 'Tue';
                                        if (!empty($section_subject['schedule_wednesday'])) $days[] = 'Wed';
                                        if (!empty($section_subject['schedule_thursday'])) $days[] = 'Thu';
                                        if (!empty($section_subject['schedule_friday'])) $days[] = 'Fri';
                                        if (!empty($section_subject['schedule_saturday'])) $days[] = 'Sat';
                                        if (!empty($section_subject['schedule_sunday'])) $days[] = 'Sun';
                                        
                                        $schedule_parts = [];
                                        if (!empty($days)) {
                                            $schedule_parts[] = implode(' • ', $days);
                                        }
                                        if (!empty($section_subject['time_start']) && !empty($section_subject['time_end'])) {
                                            $schedule_parts[] = date('g:i A', strtotime($section_subject['time_start'])) . ' - ' . date('g:i A', strtotime($section_subject['time_end']));
                                        }
                                        if (!empty($section_subject['room'])) {
                                            $schedule_parts[] = 'Room ' . htmlspecialchars($section_subject['room']);
                                        }
                                        $schedule_display = !empty($schedule_parts) ? implode(' • ', $schedule_parts) : 'Schedule TBA';
                                    ?>
                                    <div class="form-check mb-3 p-3 border rounded subject-item <?php echo $index % 2 == 0 ? 'bg-light' : ''; ?> <?php echo !$prerequisites_met ? 'border-warning' : ''; ?>">
                                        <input class="form-check-input subject-checkbox" 
                                               type="checkbox" 
                                               <?php echo $is_checked ? 'checked' : ''; ?>
                                               <?php echo (!$can_take_action) ? 'disabled' : ''; ?>
                                               name="selected_subjects[]" 
                                               value="<?php echo htmlspecialchars($curriculum_id); ?>"
                                               data-units="<?php echo (float)$section_subject['units']; ?>"
                                               id="subject_<?php echo $index; ?>" 
                                               style="width: 20px; height: 20px; margin-top: 0.25rem;">
                                        <label class="form-check-label ms-2 w-100" for="subject_<?php echo $index; ?>" 
                                               style="cursor: <?php echo $can_take_action ? 'pointer' : 'not-allowed'; ?>;">
                                            <div class="row align-items-start">
                                                <div class="col-md-8">
                                                    <div class="mb-2">
                                                        <strong class="fs-6 <?php echo !$prerequisites_met ? 'text-warning' : 'text-primary'; ?>"><?php echo htmlspecialchars($section_subject['course_code']); ?></strong>
                                                        <span class="badge bg-secondary ms-2"><?php echo (float)$section_subject['units']; ?> Unit<?php echo $section_subject['units'] != 1 ? 's' : ''; ?></span>
                                                        <?php if (!$prerequisites_met): ?>
                                                            <span class="badge bg-warning ms-2"><i class="fas fa-exclamation-triangle me-1"></i>Prerequisites Not Met (Informational)</span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="mb-1">
                                                        <strong><?php echo htmlspecialchars($section_subject['course_name']); ?></strong>
                                                    </div>
                                                    
                                                    <?php if (!empty($unmet_prerequisites)): ?>
                                                        <div class="alert alert-danger small mb-2 py-2">
                                                            <strong><i class="fas fa-ban me-1"></i>Unmet Prerequisites:</strong>
                                                            <ul class="mb-0 mt-1 ps-3">
                                                                <?php foreach ($unmet_prerequisites as $unmet): ?>
                                                                    <li>
                                                                        <strong><?php echo htmlspecialchars($unmet['code']); ?></strong> - <?php echo htmlspecialchars($unmet['name']); ?>
                                                                        <br><small class="text-muted">Reason: <?php echo htmlspecialchars($unmet['reason']); ?></small>
                                                                    </li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <div class="text-muted small">
                                                        <i class="fas fa-clock me-1"></i><?php echo $schedule_display; ?>
                                                    </div>
                                                </div>
                                                <div class="col-md-4 text-end">
                                                    <div class="mb-1">
                                                        <i class="fas fa-chalkboard-teacher me-1"></i>
                                                        <strong>Professor:</strong>
                                                    </div>
                                                    <div class="text-muted">
                                                        <?php echo !empty($section_subject['professor_name']) ? htmlspecialchars($section_subject['professor_name']) : 'TBA'; ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </label>
                                    </div>
                                    <?php endforeach; ?>
                                    
                                    <div class="mt-4 p-3 bg-primary bg-opacity-10 rounded">
                                        <div class="row">
                                            <div class="col-md-6">
                                                <strong><i class="fas fa-list-check me-2"></i>Total Subjects:</strong> 
                                                <span class="badge bg-primary"><?php echo count($selected_section_subjects); ?></span>
                                                <span class="ms-2">
                                                    (<span id="selectedCount"><?php echo count(array_filter($selected_section_subjects, function($s) use ($curriculum_map, $selected_curriculum_ids) {
                                                        $cid = $curriculum_map[$s['course_code']] ?? null;
                                                        return empty($selected_curriculum_ids) || in_array($cid, $selected_curriculum_ids);
                                                    })); ?></span> selected)
                                                </span>
                                            </div>
                                            <div class="col-md-6 text-md-end">
                                                <strong><i class="fas fa-calculator me-2"></i>Selected Units:</strong> 
                                                <span class="badge bg-success" id="selectedUnits"><?php echo $selected_units; ?></span>
                                                <span class="text-muted ms-1">/ <?php echo $total_units; ?> total</span>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <?php if ((isProgramHead() || isRegistrarStaff() || isAdmin()) && ($can_take_action || $can_create_cor)): ?>
                                        <div class="mt-3 d-flex gap-2 justify-content-end">
                                            <button type="button" class="btn btn-outline-secondary" onclick="selectAllSubjects()">
                                                <i class="fas fa-check-double me-1"></i>Select All
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary" onclick="deselectAllSubjects()">
                                                <i class="fas fa-times me-1"></i>Deselect All
                                            </button>
                                            <button type="submit" class="btn btn-primary">
                                                <i class="fas fa-save me-1"></i>Save Subject Selection
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>

                <h5 class="mb-3">Student's Current Grades</h5>
                <?php if (empty($grades_by_term)): ?>
                    <div class="alert alert-info mb-4">
                        <i class="fas fa-info-circle me-2"></i>No grades available for this student yet.
                    </div>
                <?php else: ?>
                    <?php foreach ($grades_by_term as $term_key => $term_data): ?>
                        <div class="card mb-3">
                            <div class="card-header bg-light">
                                <h6 class="mb-0">
                                    <i class="fas fa-graduation-cap me-2"></i>
                                    <strong><?php echo htmlspecialchars($term_data['year_level']); ?></strong> - 
                                    <?php echo htmlspecialchars($term_data['semester']); ?>
                                    <span class="text-muted ms-2">(<?php echo htmlspecialchars($term_data['academic_year']); ?>)</span>
                                </h6>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-sm mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Subject Code</th>
                                                <th>Subject Name</th>
                                                <th>Grade</th>
                                                <th>Term</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($term_data['grades'] as $grade): ?>
                                                <tr>
                                                    <td><strong><?php echo htmlspecialchars($grade['subject_code']); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($grade['subject_name']); ?></td>
                                                    <td>
                                                        <strong><?php echo $grade['grade_letter']; ?></strong>
                                                        <small class="text-muted">(<?php echo $grade['grade']; ?>)</small>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($grade['academic_year'] . ' - ' . $grade['semester']); ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!empty($selected_subjects)): ?>
                <h5 class="mb-3">Selected Subjects for Next Semester</h5>
                <div class="table-responsive mb-4">
                    <table class="table table-bordered">
                        <thead class="table-dark">
                            <tr>
                                <th>Subject Code</th>
                                <th>Subject Name</th>
                                <th>Units</th>
                                <th>Prerequisite</th>
                                <th>Student Grade</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            $total_units = 0;
                            
                            foreach ($selected_subjects as $subject): 
                                $total_units += $subject['units'];
                                $prereq_met = true;
                                
                                if ($subject['prerequisite_curriculum_id']) {
                                    // Check if prerequisite is met
                                    $prereq_met = false;
                                    if ($subject['student_grade']) {
                                        $grade_letter_upper = strtoupper($subject['grade_letter'] ?? '');
                                        // Check if grade is valid (not failed, incomplete, withdrawn, or dropped)
                                        if (!in_array($grade_letter_upper, ['F', 'FA', 'FAILED', 'INC', 'INCOMPLETE', 'W', 'WITHDRAWN', 'DROPPED']) 
                                            && $subject['student_grade'] <= $subject['minimum_grade'] 
                                            && $subject['student_grade'] < 5.0) {
                                            $prereq_met = true;
                                        }
                                    }
                                }
                            ?>
                                <tr class="<?php echo $prereq_met ? 'prereq-met' : 'prereq-not-met'; ?>">
                                    <td><strong><?php echo htmlspecialchars($subject['subject_code']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($subject['subject_name']); ?></td>
                                    <td><?php echo $subject['units']; ?></td>
                                    <td>
                                        <?php if ($subject['prerequisite_curriculum_id']): ?>
                                            <?php echo htmlspecialchars($subject['prereq_code'] . ' - ' . $subject['prereq_name']); ?>
                                            <br><small class="text-muted">Min Grade: <?php echo $subject['minimum_grade']; ?></small>
                                        <?php else: ?>
                                            <span class="text-muted">None</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($subject['student_grade']): ?>
                                            <strong><?php echo $subject['grade_letter']; ?></strong> (<?php echo $subject['student_grade']; ?>)
                                        <?php else: ?>
                                            <span class="text-danger">Not taken</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($prereq_met): ?>
                                            <span class="badge bg-success"><i class="fas fa-check"></i> Eligible</span>
                                        <?php else: ?>
                                            <span class="badge bg-danger"><i class="fas fa-times"></i> Not Eligible</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot class="table-light">
                            <tr>
                                <td colspan="2"><strong>Total Units:</strong></td>
                                <td colspan="4"><strong><?php echo $total_units; ?></strong></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <?php if (!$all_prerequisites_met): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Note:</strong> Some subjects have unmet prerequisites. Enrollment can proceed once section is assigned and COR is created.
                    </div>
                <?php endif; ?>
                <?php endif; // End if (!empty($selected_subjects)) ?>

                <?php if (false): // Program head step removed - all students follow same flow ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Review Enrollment:</strong> Review the student's enrollment request and select which subjects to enroll. Click "Submit to Registrar" to forward this request for COR creation and final approval.
                    </div>
                <?php endif; ?>
                
                <?php if ((isRegistrarStaff() || isAdmin()) && $request['request_status'] == 'pending_registrar'): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i>
                        <strong>Administrator Approval Required:</strong> This enrollment request is ready for final approval. You can create/review the COR and approve the enrollment.
                    </div>
                <?php endif; ?>

                <!-- Registrar Staff: Can only create COR, cannot approve -->
                <?php if ($can_create_cor): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Registrar Staff:</strong> You can create the Certificate of Registration (COR) for this student. 
                        After creating the COR, an administrator must approve the final enrollment.
                    </div>
                    <div class="d-flex gap-2 justify-content-end flex-wrap">
                        <?php
                        // Check if COR already exists for this enrollment
                        $cor_check_query = "SELECT id FROM certificate_of_registration 
                                           WHERE user_id = :user_id 
                                           AND section_id = :section_id
                                           AND academic_year = :academic_year
                                           AND semester = :semester
                                           LIMIT 1";
                        $cor_check_stmt = $conn->prepare($cor_check_query);
                        $cor_check_stmt->bindParam(':user_id', $request['user_id']);
                        $cor_check_stmt->bindParam(':section_id', $request['selected_section_id']);
                        $cor_check_stmt->bindParam(':academic_year', $request['target_academic_year']);
                        $cor_check_stmt->bindParam(':semester', $request['target_semester']);
                        $cor_check_stmt->execute();
                        $existing_cor = $cor_check_stmt->fetch(PDO::FETCH_ASSOC);
                        ?>
                        <?php if ($existing_cor): ?>
                            <a href="<?php echo add_session_to_url('../admin/generate_cor.php?user_id=' . $request['user_id'] . '&next_semester_id=' . $request_id); ?>" 
                               class="btn btn-info btn-lg">
                                <i class="fas fa-file-alt me-2"></i>View/Edit COR
                            </a>
                            <small class="d-block text-muted mt-2">
                                <i class="fas fa-info-circle me-1"></i>COR already exists for this enrollment. Editing will update the existing COR.
                            </small>
                        <?php else: ?>
                            <a href="<?php echo add_session_to_url('../admin/generate_cor.php?user_id=' . $request['user_id'] . '&next_semester_id=' . $request_id); ?>" 
                               class="btn btn-primary btn-lg">
                                <i class="fas fa-file-alt me-2"></i>Create COR
                            </a>
                        <?php endif; ?>
                    </div>
                <?php elseif ($can_take_action): ?>
                <!-- Program Head or Admin: Can approve/reject -->
                <?php
                    // Labels and confirmation messages differ for Program Head vs Admin
                    $approve_label = (isProgramHead() && $request['request_status'] == 'pending_program_head')
                        ? 'Submit to Registrar for Confirmation' 
                        : 'Approve Enrollment';
                    $approve_confirm = (isProgramHead() && $request['request_status'] == 'pending_program_head')
                        ? 'Submit this enrollment request to Registrar for confirmation?'
                        : 'Approve this enrollment request?';
                ?>
                <div class="d-flex gap-2 justify-content-end flex-wrap">
                    <?php if ($request['request_status'] == 'pending_admin' && isAdmin()): ?>
                        <?php
                        // Check if COR already exists for this enrollment
                        $cor_check_query = "SELECT id FROM certificate_of_registration 
                                           WHERE user_id = :user_id 
                                           AND section_id = :section_id
                                           AND academic_year = :academic_year
                                           AND semester = :semester
                                           LIMIT 1";
                        $cor_check_stmt = $conn->prepare($cor_check_query);
                        $cor_check_stmt->bindParam(':user_id', $request['user_id']);
                        $cor_check_stmt->bindParam(':section_id', $request['selected_section_id']);
                        $cor_check_stmt->bindParam(':academic_year', $request['target_academic_year']);
                        $cor_check_stmt->bindParam(':semester', $request['target_semester']);
                        $cor_check_stmt->execute();
                        $existing_cor = $cor_check_stmt->fetch(PDO::FETCH_ASSOC);
                        ?>
                        <?php if ($existing_cor): ?>
                            <a href="<?php echo add_session_to_url('../admin/generate_cor.php?user_id=' . $request['user_id'] . '&next_semester_id=' . $request_id); ?>" 
                               class="btn btn-info">
                                <i class="fas fa-file-alt me-2"></i>View/Edit COR
                            </a>
                        <?php else: ?>
                            <a href="<?php echo add_session_to_url('../admin/generate_cor.php?user_id=' . $request['user_id'] . '&next_semester_id=' . $request_id); ?>" 
                               class="btn btn-primary">
                                <i class="fas fa-file-alt me-2"></i>Create COR
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <form method="POST" onsubmit="return confirm('Reject this enrollment request?')">
                        <input type="hidden" name="action" value="reject">
                        <div class="mb-2">
                            <textarea name="rejection_reason" class="form-control" rows="2" 
                                      placeholder="Reason for rejection (optional)"></textarea>
                        </div>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-times me-2"></i>Reject Request
                        </button>
                    </form>
                    
                    <form method="POST" id="approveForm" onsubmit="return confirm('<?php echo $approve_confirm; ?>')">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="fas fa-check me-2"></i><?php echo htmlspecialchars($approve_label); ?>
                        </button>
                    </form>
                </div>
                <?php else: ?>
                    <?php
                        $status_class = 'info';
                        $status_icon = 'info-circle';
                        if ($request['request_status'] == 'approved') {
                            $status_class = 'success';
                            $status_icon = 'check-circle';
                        } elseif ($request['request_status'] == 'rejected') {
                            $status_class = 'danger';
                            $status_icon = 'times-circle';
                        }
                    ?>
                    <div class="alert alert-<?php echo $status_class; ?>">
                        <i class="fas fa-<?php echo $status_icon; ?> me-2"></i>
                        <strong>Request <?php echo ucfirst($request['request_status']); ?>:</strong> 
                        <?php if ($request['request_status'] == 'under_review'): ?>
                            Submitted to Registrar for confirmation. 
                            <?php if (isAdmin()): ?>
                                You can approve this enrollment request after reviewing the COR.
                            <?php elseif (isRegistrarStaff()): ?>
                                You can create the COR. An administrator must approve the final enrollment.
                            <?php else: ?>
                                Awaiting administrator approval.
                            <?php endif; ?>
                        <?php else: ?>
                            This enrollment request has already been <?php echo $request['request_status']; ?>.
                        <?php endif; ?>
                        <?php if ($request['request_status'] == 'rejected' && !empty($request['rejection_reason'])): ?>
                            <br><strong>Reason:</strong> <?php echo htmlspecialchars($request['rejection_reason']); ?>
                        <?php endif; ?>
                        <?php if ($request['processed_at']): ?>
                            <br><small>Processed on: <?php echo date('F j, Y g:i A', strtotime($request['processed_at'])); ?></small>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($request['request_status'] == 'approved' && (isAdmin() || isRegistrarStaff())): ?>
                        <?php
                        // Check if COR already exists for this enrollment
                        $cor_check_query = "SELECT id FROM certificate_of_registration 
                                           WHERE user_id = :user_id 
                                           AND section_id = :section_id
                                           AND academic_year = :academic_year
                                           AND semester = :semester
                                           LIMIT 1";
                        $cor_check_stmt = $conn->prepare($cor_check_query);
                        $cor_check_stmt->bindParam(':user_id', $request['user_id']);
                        $cor_check_stmt->bindParam(':section_id', $request['selected_section_id']);
                        $cor_check_stmt->bindParam(':academic_year', $request['target_academic_year']);
                        $cor_check_stmt->bindParam(':semester', $request['target_semester']);
                        $cor_check_stmt->execute();
                        $existing_cor = $cor_check_stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($existing_cor):
                        ?>
                            <div class="mt-3">
                                <a href="<?php echo add_session_to_url('../admin/generate_cor.php?user_id=' . $request['user_id'] . '&next_semester_id=' . $request_id); ?>" 
                                   class="btn btn-info">
                                    <i class="fas fa-file-alt me-2"></i>View/Edit COR
                                </a>
                                <small class="d-block text-muted mt-2">
                                    <i class="fas fa-info-circle me-1"></i>COR already exists for this enrollment. Editing will update the existing COR.
                                </small>
                            </div>
                        <?php else: ?>
                            <div class="mt-3">
                                <a href="<?php echo add_session_to_url('../admin/generate_cor.php?user_id=' . $request['user_id'] . '&next_semester_id=' . $request_id); ?>" 
                                   class="btn btn-primary btn-lg">
                                    <i class="fas fa-file-alt me-2"></i>Create COR for Enrollment
                                </a>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php if ($request['request_status'] == 'approved' && isAdmin()): ?>
                        <div class="mt-3 d-flex justify-content-end">
                            <form method="POST" onsubmit="return confirm('Revert this approved enrollment back to pending for further review?');">
                                <input type="hidden" name="action" value="revert">
                                <button type="submit" class="btn btn-outline-warning">
                                    <i class="fas fa-undo me-2"></i>Revert to Pending
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Update selected count and units when checkboxes change
        document.querySelectorAll('.subject-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', updateSubjectCounts);
        });
        
        // Count and display prerequisites warning on page load
        document.addEventListener('DOMContentLoaded', function() {
            const allCheckboxes = document.querySelectorAll('.subject-checkbox');
            const disabledCheckboxes = document.querySelectorAll('.subject-checkbox:disabled');
            const unmetPrereqsCount = disabledCheckboxes.length;
            
            if (unmetPrereqsCount > 0) {
                const summary = document.getElementById('prerequisitesSummary');
                if (summary) {
                    summary.textContent = unmetPrereqsCount + ' subject' + (unmetPrereqsCount !== 1 ? 's' : '') + ' disabled due to unmet prerequisites';
                    summary.style.display = 'inline-block';
                }
            }
        });
        
        function updateSubjectCounts() {
            const checkboxes = document.querySelectorAll('.subject-checkbox');
            let selectedCount = 0;
            let selectedUnits = 0;
            
            checkboxes.forEach(checkbox => {
                if (checkbox.checked) {
                    selectedCount++;
                    selectedUnits += parseFloat(checkbox.dataset.units || 0);
                }
            });
            
            const selectedCountElement = document.getElementById('selectedCount');
            const selectedUnitsElement = document.getElementById('selectedUnits');
            
            if (selectedCountElement) selectedCountElement.textContent = selectedCount;
            if (selectedUnitsElement) selectedUnitsElement.textContent = selectedUnits;
        }
        
        function selectAllSubjects() {
            const checkboxes = document.querySelectorAll('.subject-checkbox');
            checkboxes.forEach(checkbox => {
                if (!checkbox.disabled) {
                    checkbox.checked = true;
                }
            });
            updateSubjectCounts();
        }
        
        function deselectAllSubjects() {
            const checkboxes = document.querySelectorAll('.subject-checkbox');
            checkboxes.forEach(checkbox => {
                if (!checkbox.disabled) {
                    checkbox.checked = false;
                }
            });
            updateSubjectCounts();
        }

        /**
         * When the Program Head or Registrar clicks "Approve", we need to treat the current
         * checkbox state as the source of truth for subject selections.
         * The checkboxes live in a different form from the Approve button,
         * so we mirror the checked subjects into hidden inputs on the
         * approve form right before submit.
         */
        document.addEventListener('DOMContentLoaded', function () {
            const approveForm = document.getElementById('approveForm');
            if (!approveForm) {
                return;
            }

            approveForm.addEventListener('submit', function () {
                // Remove any previously-added hidden fields to avoid duplicates
                const existingHidden = approveForm.querySelectorAll('input[name="selected_subjects[]"]');
                existingHidden.forEach(function (input) {
                    input.remove();
                });

                // Copy over all currently checked subject checkboxes
                const checkboxes = document.querySelectorAll('.subject-checkbox');
                checkboxes.forEach(function (checkbox) {
                    if (checkbox.checked && !checkbox.disabled) {
                        const hidden = document.createElement('input');
                        hidden.type = 'hidden';
                        hidden.name = 'selected_subjects[]';
                        hidden.value = checkbox.value;
                        approveForm.appendChild(hidden);
                    }
                });
            });
        });
    </script>
</body>
</html>

