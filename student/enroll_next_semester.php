<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/Curriculum.php';

if (!isLoggedIn()) {
    redirect('public/login.php');
}

$conn = (new Database())->getConnection();
$user_id = $_SESSION['user_id'];

// Get all active programs for the form
$curriculum = new Curriculum();
$all_programs = $curriculum->getAllPrograms();
$active_programs = array_filter($all_programs, function($p) {
    return ($p['status'] ?? 'active') === 'active';
});

// Check enrollment status from database (not session)
// Check if student is enrolled (check both users.enrollment_status and enrolled_students table)
$enrollment_check = "SELECT u.enrollment_status, 
                     (SELECT COUNT(*) FROM enrolled_students es WHERE es.user_id = u.id) as has_enrollment_record
                     FROM users u 
                     WHERE u.id = :user_id";
$enrollment_stmt = $conn->prepare($enrollment_check);
$enrollment_stmt->bindParam(':user_id', $user_id);
$enrollment_stmt->execute();
$enrollment_data = $enrollment_stmt->fetch(PDO::FETCH_ASSOC);

// Student is considered enrolled if enrollment_status is 'enrolled' OR has a record in enrolled_students table
$is_enrolled = ($enrollment_data && (
    $enrollment_data['enrollment_status'] === 'enrolled' || 
    $enrollment_data['has_enrollment_record'] > 0
));

// Only enrolled students can enroll for next semester
if (!$is_enrolled) {
    $_SESSION['message'] = 'Only enrolled students can enroll for the next semester.';
    redirect('student/dashboard.php');
}

// Check if next semester enrollment is currently open
require_once '../classes/Admin.php';
$admin = new Admin();
$enrollment_control = $admin->getEnrollmentControl();
$is_enrollment_open = $admin->isNextSemesterEnrollmentOpen();

// Store enrollment control info for display (even if closed, we'll show a message)
$enrollment_status_message = '';
$enrollment_status_class = 'info';
if (!$is_enrollment_open) {
    $announcement = $enrollment_control['announcement'] ?? 'Enrollment for next semester is currently closed. Please check back later for updates.';
    $enrollment_status_message = $announcement;
    $enrollment_status_class = 'warning';
    
    // Only redirect if there's no pending request (allow viewing existing requests)
    $check_pending = "SELECT id FROM next_semester_enrollments 
                     WHERE user_id = :user_id 
                     AND request_status IN ('draft', 'pending_program_head', 'pending_registrar', 'pending_admin')
                     LIMIT 1";
    $pending_stmt = $conn->prepare($check_pending);
    $pending_stmt->bindParam(':user_id', $user_id);
    $pending_stmt->execute();
    $has_pending = $pending_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$has_pending) {
        $_SESSION['message'] = $announcement;
        redirect('student/dashboard.php');
    }
}

// Note: Students can enroll for next semester even without final grades
// The program head will assess irregular students and determine appropriate subjects
// Regular students will be auto-assigned subjects based on curriculum

// Get student's highest completed semester from grades (most accurate)
// This determines what they should enroll for next based on actual academic progress
$highest_completed_query = "SELECT c.year_level, sg.semester, sg.academic_year
                           FROM student_grades sg
                           JOIN curriculum c ON sg.curriculum_id = c.id
                           WHERE sg.user_id = :user_id
                           AND sg.status IN ('verified', 'finalized')
                           ORDER BY 
                               CASE c.year_level
                                   WHEN '1st Year' THEN 1
                                   WHEN '2nd Year' THEN 2
                                   WHEN '3rd Year' THEN 3
                                   WHEN '4th Year' THEN 4
                                   WHEN '5th Year' THEN 5
                                   ELSE 0
                               END DESC,
                               CASE sg.semester
                                   WHEN 'First Semester' THEN 1
                                   WHEN 'Second Semester' THEN 2
                                   ELSE 0
                               END DESC,
                               sg.academic_year DESC
                           LIMIT 1";
$highest_stmt = $conn->prepare($highest_completed_query);
$highest_stmt->bindParam(':user_id', $user_id);
$highest_stmt->execute();
$highest_completed = $highest_stmt->fetch(PDO::FETCH_ASSOC);

// Get student's CURRENT enrollment level from enrolled_students table (as fallback)
$current_level_query = "SELECT es.year_level, es.semester, es.academic_year
                        FROM enrolled_students es
                        WHERE es.user_id = :user_id
                        ORDER BY es.updated_at DESC, es.created_at DESC
                        LIMIT 1";
$current_level_stmt = $conn->prepare($current_level_query);
$current_level_stmt->bindParam(':user_id', $user_id);
$current_level_stmt->execute();
$current_level = $current_level_stmt->fetch(PDO::FETCH_ASSOC);

// Use highest completed semester from grades if available, otherwise use enrolled_students
// This ensures students enroll for the semester above their highest completed work
if ($highest_completed && !empty($highest_completed['year_level'])) {
    $current_level = $highest_completed;
} elseif (!$current_level || empty($current_level['year_level'])) {
    // Final fallback: get from current active section
    $fallback_query = "SELECT s.year_level, s.semester, s.academic_year
                       FROM section_enrollments se
                       JOIN sections s ON se.section_id = s.id
                       WHERE se.user_id = :user_id AND se.status = 'active'
                       ORDER BY s.academic_year DESC, s.semester DESC, se.enrolled_date DESC
                       LIMIT 1";
    $fallback_stmt = $conn->prepare($fallback_query);
    $fallback_stmt->bindParam(':user_id', $user_id);
    $fallback_stmt->execute();
    $current_level = $fallback_stmt->fetch(PDO::FETCH_ASSOC);
}

// Get preferred shift from student's current section
$shift_query = "SELECT DISTINCT s.section_type
                FROM section_enrollments se
                JOIN sections s ON se.section_id = s.id
                WHERE se.user_id = :user_id AND se.status = 'active'
                ORDER BY se.enrolled_date DESC
                LIMIT 1";
$shift_stmt = $conn->prepare($shift_query);
$shift_stmt->bindParam(':user_id', $user_id);
$shift_stmt->execute();
$shift_data = $shift_stmt->fetch(PDO::FETCH_ASSOC);
$current_preferred_shift = $shift_data['section_type'] ?? '';

// Determine next term (year level and semester) based on highest completed semester
// Uses the student's highest completed semester from grades to determine what they should enroll for next
// This ensures students enroll for the semester above their highest completed work
$next_year_level = '1st Year';
$next_semester = 'First Semester';
$next_academic_year = 'AY 2024-2025'; // Default

if ($current_level) {
    $current_year = $current_level['year_level'];
    $current_sem = $current_level['semester'];
    $current_academic_year = $current_level['academic_year'];

    // Normalize checks for semester strings
    $is_first = stripos($current_sem, 'First') !== false || stripos($current_sem, '1st') !== false;
    $is_second = stripos($current_sem, 'Second') !== false || stripos($current_sem, '2nd') !== false;

    if ($is_first) {
        // Currently in First Semester → Next is Second Semester (same year, same academic year)
        $next_year_level = $current_year;
        $next_semester = 'Second Semester';
        $next_academic_year = $current_academic_year; // Stay in same academic year
    } elseif ($is_second) {
        // Currently in Second Semester → Next is First Semester (next year level)
        switch ($current_year) {
            case '1st Year': 
                $next_year_level = '2nd Year'; 
                break;
            case '2nd Year': 
                $next_year_level = '3rd Year'; 
                break;
            case '3rd Year': 
                $next_year_level = '4th Year'; 
                break;
            case '4th Year': 
                $next_year_level = '5th Year'; 
                break;
            default: 
                $next_year_level = $current_year; 
                break;
        }
        $next_semester = 'First Semester';
        // Keep the same academic year - all year levels for a given academic year are in the same AY
        // The academic year only changes when the institution starts a new school year
        $next_academic_year = $current_academic_year;
    } else {
        // Fallback if semester not recognized
        $next_year_level = $current_year;
        $next_semester = 'Second Semester';
        $next_academic_year = $current_academic_year;
    }
} else {
    // No current enrollment found - default to 1st Year, First Semester
    $next_year_level = '1st Year';
    $next_semester = 'First Semester';
    $next_academic_year = 'AY 2024-2025';
}

// Check for existing PENDING enrollment request for the NEXT semester
// Note: Approved/confirmed requests should not block new submissions as they've been processed
$existing_request = "SELECT * FROM next_semester_enrollments 
                    WHERE user_id = :user_id 
                    AND target_academic_year = :target_academic_year
                    AND target_semester = :target_semester
                    AND request_status IN ('draft', 'pending_program_head', 'pending_registrar', 'pending_admin')
                    ORDER BY created_at DESC LIMIT 1";
$existing_stmt = $conn->prepare($existing_request);
$existing_stmt->bindParam(':user_id', $user_id);
$existing_stmt->bindParam(':target_academic_year', $next_academic_year);
$existing_stmt->bindParam(':target_semester', $next_semester);
$existing_stmt->execute();
$pending_request = $existing_stmt->fetch(PDO::FETCH_ASSOC);

// Get student's program from their sections
$program_query = "SELECT DISTINCT p.id as program_id, p.program_code, p.program_name
                  FROM section_enrollments se
                  JOIN sections s ON se.section_id = s.id
                  JOIN programs p ON s.program_id = p.id
                  WHERE se.user_id = :user_id
                  AND se.status = 'active'
                  LIMIT 1";
$program_stmt = $conn->prepare($program_query);
$program_stmt->bindParam(':user_id', $user_id);
$program_stmt->execute();
$student_program = $program_stmt->fetch(PDO::FETCH_ASSOC);

// Get user information for pre-filling form
$user_query = "SELECT * FROM users WHERE id = :user_id";
$user_stmt = $conn->prepare($user_query);
$user_stmt->bindParam(':user_id', $user_id);
$user_stmt->execute();
$user_info = $user_stmt->fetch(PDO::FETCH_ASSOC);

// Build complete address from available address fields
$complete_address = '';
if (!empty($user_info['permanent_address'])) {
    $complete_address = $user_info['permanent_address'];
    if (!empty($user_info['barangay'])) {
        $complete_address .= ', ' . $user_info['barangay'];
    }
    if (!empty($user_info['municipality_city'])) {
        $complete_address .= ', ' . $user_info['municipality_city'];
    }
} elseif (!empty($user_info['address'])) {
    $complete_address = $user_info['address'];
}

// Get middle initial from middle_name (first character)
$middle_initial = '';
if (!empty($user_info['middle_name'])) {
    $middle_initial = strtoupper(substr(trim($user_info['middle_name']), 0, 1));
}

// Build disability type string from individual disability fields
$disability_type = '';
if (!empty($user_info['is_pwd']) && $user_info['is_pwd']) {
    $disabilities = [];
    if (!empty($user_info['hearing_disability'])) $disabilities[] = 'Hearing';
    if (!empty($user_info['physical_disability'])) $disabilities[] = 'Physical';
    if (!empty($user_info['mental_disability'])) $disabilities[] = 'Mental';
    if (!empty($user_info['intellectual_disability'])) $disabilities[] = 'Intellectual';
    if (!empty($user_info['psychosocial_disability'])) $disabilities[] = 'Psychosocial';
    if (!empty($user_info['chronic_illness_disability'])) $disabilities[] = 'Chronic Illness';
    if (!empty($user_info['learning_disability'])) $disabilities[] = 'Learning';
    
    if (!empty($disabilities)) {
        $disability_type = implode(', ', $disabilities);
    }
}

// Check if pre-enrollment form already exists for this enrollment request
$pre_enrollment_form = null;
if ($pending_request) {
    $pre_form_query = "SELECT * FROM pre_enrollment_forms 
                       WHERE enrollment_request_id = :enrollment_request_id 
                       OR (user_id = :user_id AND enrollment_request_id IS NULL)
                       ORDER BY created_at DESC LIMIT 1";
    $pre_form_stmt = $conn->prepare($pre_form_query);
    $pre_form_stmt->bindParam(':enrollment_request_id', $pending_request['id']);
    $pre_form_stmt->bindParam(':user_id', $user_id);
    $pre_form_stmt->execute();
    $pre_enrollment_form = $pre_form_stmt->fetch(PDO::FETCH_ASSOC);
}

// Handle pre-enrollment form submission
$form_submitted = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pre_enrollment_form'])) {
    // Verify enrollment is open before processing
    if (!$is_enrollment_open) {
        $_SESSION['message'] = 'Enrollment is currently closed. Please check back during the enrollment period.';
        redirect('student/enroll_next_semester.php');
    }
    try {
        $conn->beginTransaction();
        
        // Validate required fields
        $required_fields = ['last_name', 'first_name', 'student_number', 'complete_address', 
                           'birth_date', 'sex', 'current_course', 'year_level', 'preferred_shift'];
        $errors = [];
        
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required.';
            }
        }
        
        if (!empty($errors)) {
            $_SESSION['message'] = implode('<br>', $errors);
            $conn->rollBack();
        } else {
            // Get and validate program code from form submission
            $current_course = $_POST['current_course'] ?? '';
            
            // Validate that the submitted program code exists in active programs
            $valid_program_codes = array_column($active_programs, 'program_code');
            if (!in_array($current_course, $valid_program_codes)) {
                // If invalid, use student's current program or first available
                $current_course = ($student_program['program_code'] ?? null);
                if (!$current_course || !in_array($current_course, $valid_program_codes)) {
                    $current_course = $valid_program_codes[0] ?? 'BSIS'; // Use first available program
                }
            }
            
            $program_code = $current_course;
            
            // Insert or update pre-enrollment form
            if ($pre_enrollment_form) {
                // Update existing form
                $update_form = "UPDATE pre_enrollment_forms SET
                               last_name = :last_name,
                               first_name = :first_name,
                               middle_initial = :middle_initial,
                               student_number = :student_number,
                               complete_address = :complete_address,
                               birth_date = :birth_date,
                               sex = :sex,
                               father_name = :father_name,
                               mother_name = :mother_name,
                               is_4ps_beneficiary = :is_4ps,
                               is_listahan_beneficiary = :is_listahan,
                               is_pwd = :is_pwd,
                               disability_type = :disability_type,
                               is_working_student = :is_working,
                               company_name = :company_name,
                               work_position = :work_position,
                               current_course = :current_course,
                               year_level = :year_level,
                               preferred_shift = :preferred_shift,
                               form_status = 'submitted',
                               submitted_at = NOW(),
                               updated_at = NOW()
                               WHERE id = :form_id";
                $form_stmt = $conn->prepare($update_form);
                $form_stmt->bindParam(':form_id', $pre_enrollment_form['id']);
            } else {
                // Insert new form
                $insert_form = "INSERT INTO pre_enrollment_forms 
                               (user_id, last_name, first_name, middle_initial, student_number,
                                complete_address, birth_date, sex, father_name, mother_name,
                                is_4ps_beneficiary, is_listahan_beneficiary, is_pwd, disability_type,
                                is_working_student, company_name, work_position, current_course,
                                year_level, preferred_shift, form_status, submitted_at)
                               VALUES 
                               (:user_id, :last_name, :first_name, :middle_initial, :student_number,
                                :complete_address, :birth_date, :sex, :father_name, :mother_name,
                                :is_4ps, :is_listahan, :is_pwd, :disability_type,
                                :is_working, :company_name, :work_position, :current_course,
                                :year_level, :preferred_shift, 'submitted', NOW())";
                $form_stmt = $conn->prepare($insert_form);
                $form_stmt->bindParam(':user_id', $user_id);
            }
            
            // Bind parameters (using bindValue for expressions and array access)
            $form_stmt->bindValue(':last_name', $_POST['last_name']);
            $form_stmt->bindValue(':first_name', $_POST['first_name']);
            $form_stmt->bindValue(':middle_initial', $_POST['middle_initial'] ?? null);
            $form_stmt->bindValue(':student_number', $_POST['student_number']);
            $form_stmt->bindValue(':complete_address', $_POST['complete_address']);
            $form_stmt->bindValue(':birth_date', $_POST['birth_date']);
            $form_stmt->bindValue(':sex', $_POST['sex']);
            $form_stmt->bindValue(':father_name', $_POST['father_name'] ?? null);
            $form_stmt->bindValue(':mother_name', $_POST['mother_name'] ?? null);
            $form_stmt->bindValue(':is_4ps', $_POST['is_4ps_beneficiary'] ?? 0, PDO::PARAM_INT);
            $form_stmt->bindValue(':is_listahan', $_POST['is_listahan_beneficiary'] ?? 0, PDO::PARAM_INT);
            $form_stmt->bindValue(':is_pwd', $_POST['is_pwd'] ?? 0, PDO::PARAM_INT);
            $form_stmt->bindValue(':disability_type', $_POST['disability_type'] ?? null);
            $form_stmt->bindValue(':is_working', $_POST['is_working_student'] ?? 0, PDO::PARAM_INT);
            $form_stmt->bindValue(':company_name', $_POST['company_name'] ?? null);
            $form_stmt->bindValue(':work_position', $_POST['work_position'] ?? null);
            $form_stmt->bindValue(':current_course', $current_course);
            $form_stmt->bindValue(':year_level', $_POST['year_level']);
            $form_stmt->bindValue(':preferred_shift', $_POST['preferred_shift']);
            
            $form_stmt->execute();
            
            $conn->commit();
            $form_submitted = true;
            $_SESSION['message'] = 'Pre-enrollment form submitted successfully! Please proceed to select your section.';
            
            // Refresh to show section selection
            redirect('student/enroll_next_semester.php');
        }
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['message'] = 'Error submitting form: ' . $e->getMessage();
    }
}

// Handle section selection submission (only if pre-enrollment form is submitted)
// Allow submission even if a pending request already exists; duplicate-check logic below
// will remove the old request and create a fresh one for the new selection.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['selected_section'])) {
    // Check if pre-enrollment form exists
    $check_form_query = "SELECT * FROM pre_enrollment_forms 
                        WHERE user_id = :user_id 
                        AND form_status = 'submitted'
                        ORDER BY submitted_at DESC LIMIT 1";
    $check_form_stmt = $conn->prepare($check_form_query);
    $check_form_stmt->bindParam(':user_id', $user_id);
    $check_form_stmt->execute();
    $submitted_form = $check_form_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$submitted_form) {
        $_SESSION['message'] = 'Please complete the pre-enrollment form first.';
        redirect('student/enroll_next_semester.php');
    }
    
    $target_year = $next_academic_year; // Use the calculated next academic year
    $target_semester = $next_semester;
    $selected_section = $_POST['selected_section'] ?? null;
    $preferred_schedule = $_POST['preferred_schedule'] ?? '';
    
    // Get preferred shift from submitted form
    $preferred_shift = $submitted_form['preferred_shift'] ?? null;
    
    // Check if this is a second semester enrollment
    $is_second_semester = (stripos($target_semester, 'Second') !== false || stripos($target_semester, '2nd') !== false);
    
    // If no section selected and this is second semester, auto-assign based on preferred shift
    if (empty($selected_section) && $is_second_semester && $preferred_shift) {
        // Get student's program
        $program_query = "SELECT DISTINCT p.id as program_id
                         FROM section_enrollments se
                         JOIN sections s ON se.section_id = s.id
                         JOIN programs p ON s.program_id = p.id
                         WHERE se.user_id = :user_id AND se.status = 'active'
                         LIMIT 1";
        $program_stmt = $conn->prepare($program_query);
        $program_stmt->bindParam(':user_id', $user_id);
        $program_stmt->execute();
        $program_data = $program_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($program_data) {
            // Determine target year level (stays same for second semester)
            $target_year_level = $next_year_level;
            
            // Find available section matching preferred shift
            // Prioritize sections from the target academic year, but allow others if needed
            $auto_section_sql = "SELECT s.id 
                                FROM sections s
                                WHERE s.program_id = :program_id
                                AND s.year_level = :year_level
                                AND s.semester = :semester
                                AND s.section_type = :preferred_shift
                                AND s.status = 'active'
                                AND s.current_enrolled < s.max_capacity
                                ORDER BY 
                                    CASE WHEN s.academic_year = :academic_year THEN 0 ELSE 1 END,
                                    s.current_enrolled ASC, 
                                    s.section_name ASC
                                LIMIT 1";
            $auto_section_stmt = $conn->prepare($auto_section_sql);
            $auto_section_stmt->bindParam(':program_id', $program_data['program_id']);
            $auto_section_stmt->bindParam(':year_level', $target_year_level);
            $auto_section_stmt->bindParam(':semester', $target_semester);
            $auto_section_stmt->bindParam(':academic_year', $target_year);
            $auto_section_stmt->bindParam(':preferred_shift', $preferred_shift);
            $auto_section_stmt->execute();
            $auto_section = $auto_section_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($auto_section) {
                $selected_section = $auto_section['id'];
            }
        }
    }
    
    if (empty($selected_section)) {
        $_SESSION['message'] = 'Please select a section or ensure your preferred shift has available sections.';
    } else {
        // Validate section capacity before proceeding
        $capacity_check_query = "SELECT s.id, s.section_name, s.max_capacity, s.current_enrolled, s.status
                                FROM sections s
                                WHERE s.id = :section_id";
        $capacity_check_stmt = $conn->prepare($capacity_check_query);
        $capacity_check_stmt->bindParam(':section_id', $selected_section, PDO::PARAM_INT);
        $capacity_check_stmt->execute();
        $section_data = $capacity_check_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$section_data) {
            $_SESSION['message'] = 'Selected section not found.';
            redirect('student/enroll_next_semester.php');
        }
        
        if ($section_data['status'] !== 'active') {
            $_SESSION['message'] = 'Selected section is not active.';
            redirect('student/enroll_next_semester.php');
        }
        
        if ($section_data['current_enrolled'] >= $section_data['max_capacity']) {
            $_SESSION['message'] = 'The selected section "' . htmlspecialchars($section_data['section_name']) . '" is full. Please select another section.';
            redirect('student/enroll_next_semester.php');
        }
        
        try {
            $conn->beginTransaction();
            
            // Double-check for duplicate enrollment request before inserting
            $duplicate_check = "SELECT id FROM next_semester_enrollments 
                               WHERE user_id = :user_id 
                               AND target_academic_year = :target_year 
                               AND target_semester = :target_semester
                               LIMIT 1";
            $duplicate_stmt = $conn->prepare($duplicate_check);
            $duplicate_stmt->bindParam(':user_id', $user_id);
            $duplicate_stmt->bindParam(':target_year', $target_year);
            $duplicate_stmt->bindParam(':target_semester', $target_semester);
            $duplicate_stmt->execute();
            $duplicate = $duplicate_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($duplicate) {
                // If duplicate found, delete the old one and related records, then create a new request
                // First, delete related subject selections
                $delete_subjects = "DELETE FROM next_semester_subject_selections WHERE enrollment_request_id = :old_id";
                $delete_subjects_stmt = $conn->prepare($delete_subjects);
                $delete_subjects_stmt->bindParam(':old_id', $duplicate['id']);
                $delete_subjects_stmt->execute();
                
                // Then delete the enrollment request
                $delete_old = "DELETE FROM next_semester_enrollments WHERE id = :old_id";
                $delete_stmt = $conn->prepare($delete_old);
                $delete_stmt->bindParam(':old_id', $duplicate['id']);
                $delete_stmt->execute();
            }
            
            // Create enrollment request with selected section
            // Store the student's CURRENT year level (their highest completed semester, not what they're enrolling into)
            // This is already set from highest_completed grades above, which is the most accurate
            $student_current_year = $current_level['year_level'] ?? '1st Year';
            
            // Final validation: If still not set, check from sections as last resort
            if (empty($student_current_year)) {
                $validate_year_query = "SELECT s.year_level 
                                       FROM section_enrollments se 
                                       JOIN sections s ON se.section_id = s.id 
                                       WHERE se.user_id = :user_id AND se.status = 'active'
                                       ORDER BY s.academic_year DESC, se.enrolled_date DESC 
                                       LIMIT 1";
                $validate_stmt = $conn->prepare($validate_year_query);
                $validate_stmt->bindParam(':user_id', $user_id);
                $validate_stmt->execute();
                $validate_result = $validate_stmt->fetch(PDO::FETCH_ASSOC);
                if ($validate_result && !empty($validate_result['year_level'])) {
                    $student_current_year = $validate_result['year_level'];
                }
            }
            
            // All students (regular and irregular) follow the same enrollment flow
            // Route all students directly to registrar (no program head step)
            // Enrollment type is still tracked for reporting purposes, but workflow is the same
            require_once '../admin/sync_user_to_enrolled_students.php';
            $has_failed = hasFailedGrades($conn, $user_id);

            // Check latest student_type from enrolled_students (if any)
            $student_type_query = "SELECT student_type 
                                   FROM enrolled_students 
                                   WHERE user_id = :user_id 
                                   ORDER BY updated_at DESC, created_at DESC 
                                   LIMIT 1";
            $student_type_stmt = $conn->prepare($student_type_query);
            $student_type_stmt->bindParam(':user_id', $user_id);
            $student_type_stmt->execute();
            $student_type_row = $student_type_stmt->fetch(PDO::FETCH_ASSOC);

            $is_currently_irregular = false;
            if ($student_type_row && !empty($student_type_row['student_type'])) {
                $is_currently_irregular = (strcasecmp($student_type_row['student_type'], 'Irregular') === 0);
            }

            // Determine enrollment type for tracking purposes only
            // All students follow the same workflow: Student → Registrar → Admin
            $is_irregular_for_tracking = $has_failed || $is_currently_irregular;
            $enrollment_type = $is_irregular_for_tracking ? 'irregular' : 'regular';
            // All students go directly to registrar (same flow as regular students)
            $request_status = 'pending_registrar';
            
            $insert_request = "INSERT INTO next_semester_enrollments 
                              (user_id, target_academic_year, target_semester, current_year_level, 
                               enrollment_type, selected_section_id, preferred_schedule, request_status)
                              VALUES (:user_id, :target_year, :target_semester, :current_level, 
                                      :enrollment_type, :section_id, :preferred_schedule, :request_status)";
            $insert_stmt = $conn->prepare($insert_request);
            $insert_stmt->bindParam(':user_id', $user_id);
            $insert_stmt->bindParam(':target_year', $target_year);
            $insert_stmt->bindParam(':target_semester', $target_semester);
            $insert_stmt->bindParam(':current_level', $student_current_year);
            $insert_stmt->bindParam(':enrollment_type', $enrollment_type);
            $insert_stmt->bindParam(':section_id', $selected_section);
            $insert_stmt->bindParam(':preferred_schedule', $preferred_schedule);
            $insert_stmt->bindParam(':request_status', $request_status);
            $insert_stmt->execute();
            
            $enrollment_request_id = $conn->lastInsertId();
            
            // Link pre-enrollment form to enrollment request
            $link_form = "UPDATE pre_enrollment_forms 
                         SET enrollment_request_id = :enrollment_request_id
                         WHERE id = :form_id";
            $link_stmt = $conn->prepare($link_form);
            $link_stmt->bindParam(':enrollment_request_id', $enrollment_request_id);
            $link_stmt->bindParam(':form_id', $submitted_form['id']);
            $link_stmt->execute();
            
            // For second semester enrollment, automatically assign student to the section
            if ($is_second_semester && $selected_section) {
                require_once '../classes/Section.php';
                $section_obj = new Section();
                
                // Check if student is already in this section
                $check_enrollment = $conn->prepare("SELECT id FROM section_enrollments 
                                                    WHERE user_id = :user_id AND section_id = :section_id AND status = 'active'");
                $check_enrollment->bindParam(':user_id', $user_id);
                $check_enrollment->bindParam(':section_id', $selected_section);
                $check_enrollment->execute();
                
                if ($check_enrollment->rowCount() == 0) {
                    // Assign student to the section automatically
                    $assignment_result = $section_obj->assignStudentToSection($user_id, $selected_section);
                    if (!$assignment_result['success']) {
                        // Log error but don't fail the enrollment request
                        error_log('Auto-assignment failed for user ' . $user_id . ': ' . ($assignment_result['message'] ?? 'Unknown error'));
                    }
                }
            }
            
            // Update student's enrollment status to pending
            $update_status_query = "UPDATE users SET enrollment_status = 'pending' WHERE id = :user_id";
            $update_status_stmt = $conn->prepare($update_status_query);
            $update_status_stmt->bindParam(':user_id', $user_id);
            $update_status_stmt->execute();
            
            // Update enrolled_students to reflect the new semester (moves current to past)
            // This creates/updates the enrolled_students record with the target semester info
            $update_enrolled_query = "UPDATE enrolled_students 
                                     SET academic_year = :target_year, 
                                         semester = :target_semester, 
                                         year_level = :year_level,
                                         updated_at = NOW()
                                     WHERE user_id = :user_id";
            $update_enrolled_stmt = $conn->prepare($update_enrolled_query);
            $update_enrolled_stmt->bindParam(':target_year', $target_year);
            $update_enrolled_stmt->bindParam(':target_semester', $target_semester);
            $update_enrolled_stmt->bindParam(':year_level', $next_year_level);
            $update_enrolled_stmt->bindParam(':user_id', $user_id);
            $update_enrolled_stmt->execute();
            
            // Update session enrollment status
            $_SESSION['enrollment_status'] = 'pending';
            
            $conn->commit();
            
            // All students follow the same enrollment flow
            if ($is_second_semester && $selected_section) {
                $_SESSION['message'] = 'Second semester enrollment request submitted successfully! You have been automatically assigned to your preferred shift. Your enrollment status is now pending. Previous enrollments have been moved to past enrollment records.';
            } else {
                $_SESSION['message'] = 'Next semester enrollment request submitted successfully! Your enrollment status is now pending. Previous enrollments have been moved to past enrollment records.';
            }
            redirect('student/dashboard.php');
            
        } catch (Exception $e) {
            $conn->rollBack();
            $_SESSION['message'] = 'Error submitting request: ' . $e->getMessage();
        }
    }
}

// Check if pre-enrollment form is submitted (for showing section selection)
$check_submitted_form = "SELECT * FROM pre_enrollment_forms 
                        WHERE user_id = :user_id 
                        AND form_status = 'submitted'
                        ORDER BY submitted_at DESC LIMIT 1";
$check_submitted_stmt = $conn->prepare($check_submitted_form);
$check_submitted_stmt->bindParam(':user_id', $user_id);
$check_submitted_stmt->execute();
$submitted_pre_form = $check_submitted_stmt->fetch(PDO::FETCH_ASSOC);

// Get preferred shift from submitted pre-enrollment form
$preferred_shift = null;
if ($submitted_pre_form) {
    $preferred_shift = $submitted_pre_form['preferred_shift'];
} elseif ($pre_enrollment_form && $pre_enrollment_form['form_status'] == 'submitted') {
    $preferred_shift = $pre_enrollment_form['preferred_shift'];
}

// Get available sections for next term
// Prioritize sections matching preferred shift
// Note: We prioritize sections from the calculated next_academic_year, but also show 
// sections from the current academic year if they match the year level and semester
$sections_query = "SELECT s.*, p.program_code, p.program_name,
                   (s.max_capacity - s.current_enrolled) as available_slots,
                   CASE 
                       WHEN s.section_type = :preferred_shift AND s.academic_year = :next_academic_year THEN 1
                       WHEN s.section_type = :preferred_shift THEN 2
                       WHEN s.academic_year = :next_academic_year THEN 3
                       ELSE 4
                   END as priority_order
                   FROM sections s
                   JOIN programs p ON s.program_id = p.id
                   WHERE s.year_level = :next_year_level
                   AND s.semester = :next_semester
                   AND s.program_id = :program_id
                   AND s.status = 'active'
                   AND s.current_enrolled < s.max_capacity
                   ORDER BY priority_order, s.section_type, s.section_name";

// Get sections for next term
$sections_stmt = $conn->prepare($sections_query);
$sections_stmt->bindParam(':next_year_level', $next_year_level);
$sections_stmt->bindParam(':next_semester', $next_semester);
$sections_stmt->bindParam(':next_academic_year', $next_academic_year);
$sections_stmt->bindParam(':program_id', $student_program['program_id']);
$preferred_shift_param = $preferred_shift ?? '';
$sections_stmt->bindParam(':preferred_shift', $preferred_shift_param);
$sections_stmt->execute();
$available_sections = $sections_stmt->fetchAll(PDO::FETCH_ASSOC);

// Determine what to show: pre-enrollment form or section selection
// Allow editing even if there's a pending request - students should be able to update their enrollment
$show_pre_enrollment_form = !$submitted_pre_form;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enroll for Next Semester - Student Portal</title>
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
        }
        .section-card {
            border: 2px solid #e0e0e0;
            transition: all 0.3s;
            cursor: pointer;
        }
        .section-card:hover {
            border-color: #667eea;
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
        }
        .section-card input[type="radio"]:checked ~ label {
            color: #667eea;
            font-weight: 500;
        }
        .form-check-input[type="radio"] {
            margin-top: 0.5em;
        }
        .form-label {
            font-weight: 500;
            margin-bottom: 0.5rem;
        }
        .required-field::after {
            content: " *";
            color: red;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="row mb-3">
            <div class="col">
                <a href="dashboard.php" class="btn btn-light">
                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                </a>
            </div>
        </div>

        <?php if ($show_pre_enrollment_form): ?>
            <!-- Pre-Enrollment Form -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0"><i class="fas fa-file-alt me-2"></i>Student Enrollment Form</h4>
                </div>
                <div class="card-body">
                    <?php if (isset($_SESSION['message'])): ?>
                        <div class="alert alert-warning alert-dismissible fade show">
                            <?php echo htmlspecialchars($_SESSION['message']); unset($_SESSION['message']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <form method="POST" id="preEnrollmentForm">
                        <input type="hidden" name="pre_enrollment_form" value="1">
                        
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label required-field">Last Name</label>
                                <input type="text" class="form-control" name="last_name" 
                                       value="<?php echo htmlspecialchars($user_info['last_name'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label required-field">First Name</label>
                                <input type="text" class="form-control" name="first_name" 
                                       value="<?php echo htmlspecialchars($user_info['first_name'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Middle Initial</label>
                                <input type="text" class="form-control" name="middle_initial" 
                                       value="<?php echo htmlspecialchars($middle_initial ?? ($user_info['middle_name'] ?? '')); ?>" maxlength="10">
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label required-field">Student Number</label>
                                <input type="text" class="form-control" name="student_number" 
                                       value="<?php echo htmlspecialchars($user_info['student_id'] ?? ''); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required-field">Birth Date (mm/dd/yyyy)</label>
                                <input type="date" class="form-control" name="birth_date" 
                                       value="<?php echo htmlspecialchars($user_info['date_of_birth'] ?? ''); ?>" required>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-12">
                                <label class="form-label required-field">Complete Address</label>
                                <textarea class="form-control" name="complete_address" rows="2" required><?php echo htmlspecialchars($complete_address ?? ($user_info['address'] ?? '')); ?></textarea>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label required-field">Sex</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="sex" id="sex_male" value="Male" 
                                           <?php echo (($user_info['sex_at_birth'] ?? '') == 'Male') ? 'checked' : ''; ?> required>
                                    <label class="form-check-label" for="sex_male">Male</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="sex" id="sex_female" value="Female" 
                                           <?php echo (($user_info['sex_at_birth'] ?? '') == 'Female') ? 'checked' : ''; ?> required>
                                    <label class="form-check-label" for="sex_female">Female</label>
                                </div>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Father's Name</label>
                                <input type="text" class="form-control" name="father_name" 
                                       value="<?php echo htmlspecialchars($user_info['father_name'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Mother's Name</label>
                                <input type="text" class="form-control" name="mother_name" 
                                       value="<?php echo htmlspecialchars($user_info['mother_maiden_name'] ?? ''); ?>">
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Are you a 4Ps beneficiary?</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_4ps_beneficiary" id="is_4ps" value="1">
                                    <label class="form-check-label" for="is_4ps">Yes</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Are you included in the DSWD's Listahan 2.0?</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_listahan_beneficiary" id="is_listahan" value="1">
                                    <label class="form-check-label" for="is_listahan">Yes</label>
                                </div>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Are you a PWD?</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_pwd" id="is_pwd" value="1" 
                                           <?php echo ($user_info['is_pwd'] ?? 0) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="is_pwd">Yes</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">If yes, Indicate the Disability</label>
                                <input type="text" class="form-control" name="disability_type" 
                                       placeholder="Enter disability type" id="disability_type" 
                                       value="<?php echo htmlspecialchars($disability_type ?? ''); ?>"
                                       <?php echo ($user_info['is_pwd'] ?? 0) ? '' : 'disabled'; ?>>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Are you a Working Student?</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_working_student" id="is_working" value="1" 
                                           <?php echo ($user_info['is_working_student'] ?? 0) ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="is_working">Yes</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">If yes, Indicate the Company and Position</label>
                                <input type="text" class="form-control mb-2" name="company_name" 
                                       placeholder="Company Name" id="company_name" 
                                       value="<?php echo htmlspecialchars($user_info['employer'] ?? ''); ?>"
                                       <?php echo ($user_info['is_working_student'] ?? 0) ? '' : 'disabled'; ?>>
                                <input type="text" class="form-control" name="work_position" 
                                       placeholder="Position" id="work_position" 
                                       value="<?php echo htmlspecialchars($user_info['work_position'] ?? ''); ?>"
                                       <?php echo ($user_info['is_working_student'] ?? 0) ? '' : 'disabled'; ?>>
                            </div>
                        </div>

                        <hr class="my-4">
                        <h5 class="mb-3">Bachelor's Degree Program</h5>

                        <div class="row mb-3">
                            <div class="col-12">
                                <label class="form-label required-field">What is your Current Course/Program?</label>
                                <?php foreach ($active_programs as $program): 
                                    $program_code = $program['program_code'];
                                    $is_checked = (($student_program['program_code'] ?? '') == $program_code);
                                    $input_id = 'course_' . strtolower($program_code);
                                ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="current_course" id="<?php echo $input_id; ?>" 
                                           value="<?php echo htmlspecialchars($program_code); ?>" 
                                           <?php echo $is_checked ? 'checked' : ''; ?> required>
                                    <label class="form-check-label" for="<?php echo $input_id; ?>">
                                        <?php echo htmlspecialchars($program['program_name']); ?> (<?php echo htmlspecialchars($program_code); ?>)
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label required-field">Year Level</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="year_level" id="year_1" value="1st Year" 
                                           <?php echo ($next_year_level == '1st Year') ? 'checked' : ''; ?> required>
                                    <label class="form-check-label" for="year_1">1st Year</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="year_level" id="year_2" value="2nd Year" 
                                           <?php echo ($next_year_level == '2nd Year') ? 'checked' : ''; ?> required>
                                    <label class="form-check-label" for="year_2">2nd Year</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="year_level" id="year_3" value="3rd Year" 
                                           <?php echo ($next_year_level == '3rd Year') ? 'checked' : ''; ?> required>
                                    <label class="form-check-label" for="year_3">3rd Year</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="year_level" id="year_4" value="4th Year" 
                                           <?php echo ($next_year_level == '4th Year') ? 'checked' : ''; ?> required>
                                    <label class="form-check-label" for="year_4">4th Year</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label required-field">Preferred Shift/Schedule</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="preferred_shift" id="shift_morning" value="Morning" 
                                           <?php echo ($current_preferred_shift == 'Morning') ? 'checked' : ''; ?> required>
                                    <label class="form-check-label" for="shift_morning">Morning</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="preferred_shift" id="shift_afternoon" value="Afternoon" 
                                           <?php echo ($current_preferred_shift == 'Afternoon') ? 'checked' : ''; ?> required>
                                    <label class="form-check-label" for="shift_afternoon">Afternoon</label>
                                </div>
                                <div class="form-check">
                                    <input class="form-check-input" type="radio" name="preferred_shift" id="shift_evening" value="Evening" 
                                           <?php echo ($current_preferred_shift == 'Evening') ? 'checked' : ''; ?> required>
                                    <label class="form-check-label" for="shift_evening">Evening</label>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-paper-plane me-2"></i>Submit Pre-Enrollment Form
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php else: ?>
            <!-- Section Selection -->
            <div class="card">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0"><i class="fas fa-calendar-plus me-2"></i>Enroll for Next Semester</h4>
                </div>
                <div class="card-body">
                    <?php if (!empty($enrollment_status_message) && !$is_enrollment_open): ?>
                        <div class="alert alert-<?php echo $enrollment_status_class; ?> alert-dismissible fade show">
                            <h5 class="alert-heading"><i class="fas fa-info-circle me-2"></i>Enrollment Status</h5>
                            <p class="mb-0"><?php echo htmlspecialchars($enrollment_status_message); ?></p>
                            <?php if ($enrollment_control && $enrollment_control['opening_date']): ?>
                                <hr>
                                <p class="mb-0 small">
                                    <strong>Enrollment Opens:</strong> <?php echo date('F j, Y', strtotime($enrollment_control['opening_date'])); ?>
                                </p>
                            <?php endif; ?>
                            <?php if ($enrollment_control && $enrollment_control['closing_date']): ?>
                                <p class="mb-0 small">
                                    <strong>Enrollment Closes:</strong> <?php echo date('F j, Y', strtotime($enrollment_control['closing_date'])); ?>
                                </p>
                            <?php endif; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php elseif ($is_enrollment_open && $enrollment_control && !empty($enrollment_control['announcement'])): ?>
                        <div class="alert alert-info alert-dismissible fade show">
                            <i class="fas fa-info-circle me-2"></i>
                            <?php echo htmlspecialchars($enrollment_control['announcement']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>
                    <?php if (isset($_SESSION['message'])): ?>
                        <div class="alert alert-warning alert-dismissible fade show">
                            <?php echo htmlspecialchars($_SESSION['message']); unset($_SESSION['message']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if (!$pending_request): ?>
                    <?php if (!$pending_request): ?>
                        <div class="alert alert-success">
                            <h5><i class="fas fa-check-circle me-2"></i>Pre-Enrollment Form Completed</h5>
                            <p class="mb-2">Your pre-enrollment form has been submitted successfully.</p>
                            <p class="mb-1"><strong>Enrolling for:</strong> <?php echo htmlspecialchars($next_year_level . ' - ' . $next_semester); ?></p>
                            <?php if ($preferred_shift): ?>
                                <p class="mb-0"><strong>Preferred Shift:</strong> <span class="badge bg-info"><?php echo htmlspecialchars($preferred_shift); ?></span> - Sections matching your preferred shift are shown first.</p>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <h5><i class="fas fa-info-circle me-2"></i>Enrollment Form</h5>
                            <p class="mb-1"><strong>Enrolling for:</strong> <?php echo htmlspecialchars($next_year_level . ' - ' . $next_semester); ?></p>
                            <?php if ($preferred_shift): ?>
                                <p class="mb-0"><strong>Preferred Shift:</strong> <span class="badge bg-info"><?php echo htmlspecialchars($preferred_shift); ?></span> - Sections matching your preferred shift are shown first.</p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php else: ?>
                        <div class="alert alert-info">
                            <h5><i class="fas fa-info-circle me-2"></i>Enrollment Form</h5>
                            <p class="mb-1"><strong>Enrolling for:</strong> <?php echo htmlspecialchars($next_year_level . ' - ' . $next_semester); ?></p>
                            <?php if ($preferred_shift): ?>
                                <p class="mb-0"><strong>Preferred Shift:</strong> <span class="badge bg-info"><?php echo htmlspecialchars($preferred_shift); ?></span> - Sections matching your preferred shift are shown first.</p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST">
                        <input type="hidden" name="target_academic_year" value="<?php echo htmlspecialchars($next_academic_year); ?>">
                        <input type="hidden" name="preferred_schedule" value="">
                        
                        <h5 class="mb-3">Select Section</h5>
                        <p class="text-muted">Choose a section for <?php echo htmlspecialchars($next_year_level . ' - ' . $next_semester); ?>. Courses will be enrolled automatically upon approval.</p>

                        <?php if (empty($available_sections)): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                No sections available for <?php echo htmlspecialchars($next_year_level . ' - ' . $next_semester); ?>. Please contact the registrar's office.
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($available_sections as $section): 
                                    $percentage = ($section['current_enrolled'] / $section['max_capacity']) * 100;
                                    $badge_class = $percentage >= 90 ? 'bg-danger' : ($percentage >= 70 ? 'bg-warning' : 'bg-success');
                                ?>
                                    <div class="col-md-12 mb-3">
                                        <div class="card section-card">
                                            <div class="card-body">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="radio" 
                                                           name="selected_section" value="<?php echo $section['id']; ?>"
                                                           id="section_<?php echo $section['id']; ?>"
                                                           required>
                                                    <label class="form-check-label w-100" for="section_<?php echo $section['id']; ?>">
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <div>
                                                                <strong style="font-size: 1.1em;"><?php echo htmlspecialchars($section['section_name']); ?></strong>
                                                                <span class="badge <?php echo $section['section_type'] == 'Morning' ? 'bg-info' : ($section['section_type'] == 'Afternoon' ? 'bg-warning' : 'bg-dark'); ?> ms-2">
                                                                    <?php echo htmlspecialchars($section['section_type']); ?>
                                                                </span>
                                                                <br>
                                                                <small class="text-muted">
                                                                    <?php echo htmlspecialchars($section['program_code']); ?> • 
                                                                    <?php echo htmlspecialchars($section['year_level']); ?> • 
                                                                    <?php echo htmlspecialchars($section['semester']); ?>
                                                                </small>
                                                            </div>
                                                            <div class="text-end">
                                                                <div class="mb-2">
                                                                    <span class="badge <?php echo $badge_class; ?>">
                                                                        <?php echo $section['available_slots']; ?> slots available
                                                                    </span>
                                                                </div>
                                                                <small class="text-muted">
                                                                    <?php echo $section['current_enrolled']; ?> / <?php echo $section['max_capacity']; ?> enrolled
                                                                </small>
                                                            </div>
                                                        </div>
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div class="mt-4">
                                <?php if ($is_enrollment_open): ?>
                                    <button type="submit" class="btn btn-primary btn-lg">
                                        <i class="fas fa-paper-plane me-2"></i>Submit Enrollment Request
                                    </button>
                                <?php else: ?>
                                    <button type="button" class="btn btn-secondary btn-lg" disabled>
                                        <i class="fas fa-lock me-2"></i>Enrollment is Currently Closed
                                    </button>
                                    <p class="text-muted mt-2 small">
                                        Enrollment is currently closed. Please check back during the enrollment period.
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Enable/disable disability type field based on PWD checkbox
        document.getElementById('is_pwd')?.addEventListener('change', function() {
            const disabilityField = document.getElementById('disability_type');
            if (disabilityField) {
                disabilityField.disabled = !this.checked;
                if (!this.checked) {
                    disabilityField.value = '';
                }
            }
        });

        // Enable/disable company and position fields based on working student checkbox
        document.getElementById('is_working')?.addEventListener('change', function() {
            const companyField = document.getElementById('company_name');
            const positionField = document.getElementById('work_position');
            if (companyField) {
                companyField.disabled = !this.checked;
                if (!this.checked) {
                    companyField.value = '';
                }
            }
            if (positionField) {
                positionField.disabled = !this.checked;
                if (!this.checked) {
                    positionField.value = '';
                }
            }
        });
    </script>
</body>
</html>
