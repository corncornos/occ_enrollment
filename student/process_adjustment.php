<?php
declare(strict_types=1);

// Disable error display and start output buffering to prevent any warnings/errors from breaking JSON
ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);
ob_start();

try {
    require_once '../config/database.php';
    require_once '../config/session_helper.php';
    require_once '../config/adjustment_helper.php';
    require_once '../classes/Admin.php';
} catch (Throwable $e) {
    ob_end_clean();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Error loading required files']);
    exit();
}

// Clear any output that may have been generated and set JSON header
$output = ob_get_clean();
if (!empty($output)) {
    // Log any unexpected output (but don't display it)
    if (strpos($output, '{') !== 0 && strpos(trim($output), '{') !== 0) {
        error_log('Unexpected output in process_adjustment.php: ' . substr($output, 0, 500));
    }
}
ob_start();
header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$conn = (new Database())->getConnection();
$user_id = (int)$_SESSION['user_id'];

// Check if adjustment period is open
$admin = new Admin();
if (!$admin->isAdjustmentPeriodOpen()) {
    echo json_encode(['success' => false, 'message' => 'Adjustment period is closed']);
    exit();
}

/**
 * Get current enrolled units for a student in their current enrollment semester
 * Uses COR if available, otherwise falls back to student_schedules
 */
function getCurrentEnrolledUnits(PDO $conn, int $user_id, string $academic_year, string $semester): int {
    $current_units = 0;
    
    // Get the most recent COR for this student (matching current semester)
    $cor_query = "SELECT cor.id, cor.subjects_json, cor.academic_year, cor.semester
                  FROM certificate_of_registration cor
                  WHERE cor.user_id = :user_id
                  AND cor.academic_year = :academic_year
                  AND cor.semester = :semester
                  ORDER BY cor.created_at DESC
                  LIMIT 1";
    $cor_stmt = $conn->prepare($cor_query);
    $cor_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $cor_stmt->bindParam(':academic_year', $academic_year);
    $cor_stmt->bindParam(':semester', $semester);
    $cor_stmt->execute();
    $cor_record = $cor_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($cor_record && !empty($cor_record['subjects_json'])) {
        $cor_subjects = json_decode($cor_record['subjects_json'], true);
        if (is_array($cor_subjects) && !empty($cor_subjects)) {
            // Sum units from COR subjects
            foreach ($cor_subjects as $subject) {
                $current_units += (float)($subject['units'] ?? 0);
            }
        }
    } else {
        // Fallback: if no COR, get from student_schedules directly matching semester
        // Group by curriculum_id to avoid counting the same subject multiple times
        $current_units_query = "SELECT COALESCE(SUM(c.units), 0) as total_units
                               FROM (
                                   SELECT DISTINCT c.id, c.units
                                   FROM student_schedules sts
                                   JOIN section_schedules ss ON sts.section_schedule_id = ss.id
                                   JOIN curriculum c ON ss.curriculum_id = c.id
                                   JOIN sections s ON ss.section_id = s.id
                                   WHERE sts.user_id = :user_id
                                   AND sts.status = 'active'
                                   AND s.academic_year = :academic_year
                                   AND s.semester = :semester
                               ) as unique_subjects";
        $current_units_stmt = $conn->prepare($current_units_query);
        $current_units_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $current_units_stmt->bindParam(':academic_year', $academic_year);
        $current_units_stmt->bindParam(':semester', $semester);
        $current_units_stmt->execute();
        $current_units_data = $current_units_stmt->fetch(PDO::FETCH_ASSOC);
        $current_units = (int)($current_units_data['total_units'] ?? 0);
    }
    
    return (int)$current_units;
}

/**
 * Get student's current enrollment info (academic_year, semester, year_level)
 */
function getCurrentEnrollmentInfo(PDO $conn, int $user_id): ?array {
    // First try to get from section_enrollments (most reliable)
    $enrollment_info_query = "SELECT s.year_level, s.semester, s.academic_year,
                             p.years_to_complete
                             FROM section_enrollments se
                             JOIN sections s ON se.section_id = s.id
                             JOIN programs p ON s.program_id = p.id
                             WHERE se.user_id = :user_id AND se.status = 'active'
                             ORDER BY 
                                 CASE s.year_level
                                     WHEN '4th Year' THEN 4
                                     WHEN '3rd Year' THEN 3
                                     WHEN '2nd Year' THEN 2
                                     WHEN '1st Year' THEN 1
                                     ELSE 0
                                 END DESC,
                                 CASE s.semester
                                     WHEN 'Second Semester' THEN 2
                                     WHEN 'First Semester' THEN 1
                                     ELSE 0
                                 END DESC,
                                 se.enrolled_date DESC
                             LIMIT 1";
    $enrollment_info_stmt = $conn->prepare($enrollment_info_query);
    $enrollment_info_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $enrollment_info_stmt->execute();
    $enrollment_info = $enrollment_info_stmt->fetch(PDO::FETCH_ASSOC);
    
    // Fallback: get from enrolled_students if section_enrollments doesn't have it
    if (!$enrollment_info) {
        $enrolled_students_query = "SELECT es.year_level, es.semester, es.academic_year,
                                   COALESCE(p.years_to_complete, 4) as years_to_complete
                                   FROM enrolled_students es
                                   LEFT JOIN users u ON es.user_id = u.id
                                   LEFT JOIN (
                                       SELECT se.user_id, pr.years_to_complete
                                       FROM section_enrollments se
                                       JOIN sections s ON se.section_id = s.id
                                       JOIN programs pr ON s.program_id = pr.id
                                       WHERE se.status = 'active'
                                       ORDER BY se.enrolled_date DESC
                                       LIMIT 1
                                   ) p ON es.user_id = p.user_id
                                   WHERE es.user_id = :user_id
                                   ORDER BY es.updated_at DESC LIMIT 1";
        $enrolled_students_stmt = $conn->prepare($enrolled_students_query);
        $enrolled_students_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $enrolled_students_stmt->execute();
        $enrollment_info = $enrolled_students_stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    return $enrollment_info ?: null;
}

// Check if student is currently enrolled OR has confirmed enrollment
// NOTE: Adjustment period is available for ALL enrolled students from 1st Year First Semester onwards
// No year level restrictions apply - all enrolled students are eligible
$enrollment_check = "SELECT id FROM next_semester_enrollments 
                    WHERE user_id = :user_id 
                    AND request_status = 'confirmed'
                    LIMIT 1";
$enrollment_check_stmt = $conn->prepare($enrollment_check);
$enrollment_check_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$enrollment_check_stmt->execute();
$has_confirmed = $enrollment_check_stmt->fetch();

$user_status_check = "SELECT enrollment_status FROM users WHERE id = :user_id";
$user_status_stmt = $conn->prepare($user_status_check);
$user_status_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$user_status_stmt->execute();
$user_status = $user_status_stmt->fetch(PDO::FETCH_ASSOC);
$is_currently_enrolled = ($user_status && $user_status['enrollment_status'] == 'enrolled');

if (!$has_confirmed && !$is_currently_enrolled) {
    echo json_encode(['success' => false, 'message' => 'Only enrolled students can make adjustments']);
    exit();
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'get_schedules':
        // Get available schedules for a subject
        try {
            $curriculum_id = (int)($_GET['curriculum_id'] ?? 0);
            $academic_year = $_GET['academic_year'] ?? '';
            $semester = $_GET['semester'] ?? '';
            $exclude_schedule_id = isset($_GET['exclude_schedule_id']) ? (int)$_GET['exclude_schedule_id'] : null;
            
            if ($curriculum_id <= 0 || empty($academic_year) || empty($semester)) {
                ob_end_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
                exit();
            }
            
            $schedules = getAvailableSchedulesForSubject($conn, $curriculum_id, $academic_year, $semester, $exclude_schedule_id);
            
            if (!is_array($schedules)) {
                $schedules = [];
            }
            
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode(['success' => true, 'schedules' => $schedules]);
        } catch (Throwable $e) {
            ob_end_clean();
            header('Content-Type: application/json');
            error_log('Error in get_schedules: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
            echo json_encode(['success' => false, 'message' => 'Error fetching schedules. Please try again.']);
        }
        exit();
        
    case 'get_curriculum_info':
        // Get curriculum information for pending adjustments
        try {
            $curriculum_id = (int)($_GET['curriculum_id'] ?? 0);
            
            if ($curriculum_id <= 0) {
                ob_end_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid curriculum ID']);
                exit();
            }
            
            $curriculum_query = "SELECT course_code, course_name, units FROM curriculum WHERE id = :curriculum_id";
            $curriculum_stmt = $conn->prepare($curriculum_query);
            $curriculum_stmt->bindParam(':curriculum_id', $curriculum_id, PDO::PARAM_INT);
            $curriculum_stmt->execute();
            $curriculum_data = $curriculum_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$curriculum_data) {
                ob_end_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Curriculum not found']);
                exit();
            }
            
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'course_code' => $curriculum_data['course_code'],
                'course_name' => $curriculum_data['course_name'],
                'units' => (float)$curriculum_data['units']
            ]);
        } catch (Throwable $e) {
            ob_end_clean();
            header('Content-Type: application/json');
            error_log('Error in get_curriculum_info: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
            echo json_encode(['success' => false, 'message' => 'Error fetching curriculum information. Please try again.']);
        }
        exit();
        
    case 'get_schedule_info':
        // Get schedule information for pending adjustments
        try {
            $schedule_id = (int)($_GET['schedule_id'] ?? 0);
            
            if ($schedule_id <= 0) {
                ob_end_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Invalid schedule ID']);
                exit();
            }
            
            $schedule_query = "SELECT ss.curriculum_id, ss.section_id, ss.time_start, ss.time_end,
                              ss.schedule_monday, ss.schedule_tuesday, ss.schedule_wednesday,
                              ss.schedule_thursday, ss.schedule_friday, ss.schedule_saturday, ss.schedule_sunday,
                              s.section_name, c.course_code, c.course_name, c.units
                              FROM section_schedules ss
                              JOIN sections s ON ss.section_id = s.id
                              JOIN curriculum c ON ss.curriculum_id = c.id
                              WHERE ss.id = :schedule_id";
            $schedule_stmt = $conn->prepare($schedule_query);
            $schedule_stmt->bindParam(':schedule_id', $schedule_id, PDO::PARAM_INT);
            $schedule_stmt->execute();
            $schedule_data = $schedule_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$schedule_data) {
                ob_end_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Schedule not found']);
                exit();
            }
            
            $days = [];
            if (!empty($schedule_data['schedule_monday'])) $days[] = 'Mon';
            if (!empty($schedule_data['schedule_tuesday'])) $days[] = 'Tue';
            if (!empty($schedule_data['schedule_wednesday'])) $days[] = 'Wed';
            if (!empty($schedule_data['schedule_thursday'])) $days[] = 'Thu';
            if (!empty($schedule_data['schedule_friday'])) $days[] = 'Fri';
            if (!empty($schedule_data['schedule_saturday'])) $days[] = 'Sat';
            if (!empty($schedule_data['schedule_sunday'])) $days[] = 'Sun';
            
            ob_end_clean();
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'curriculum_id' => (int)$schedule_data['curriculum_id'],
                'course_code' => $schedule_data['course_code'],
                'course_name' => $schedule_data['course_name'],
                'units' => (float)$schedule_data['units'],
                'old_section_name' => $schedule_data['section_name'],
                'old_schedule_days' => implode(', ', $days),
                'old_time_start' => $schedule_data['time_start'] ?? '',
                'old_time_end' => $schedule_data['time_end'] ?? ''
            ]);
        } catch (Throwable $e) {
            ob_end_clean();
            header('Content-Type: application/json');
            error_log('Error in get_schedule_info: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
            echo json_encode(['success' => false, 'message' => 'Error fetching schedule information. Please try again.']);
        }
        exit();
        
    case 'submit_all':
        // Submit all pending adjustments as requests
        try {
            $adjustments_json = $_POST['adjustments'] ?? '';
            if (empty($adjustments_json)) {
                echo json_encode(['success' => false, 'message' => 'No adjustments to submit']);
                exit();
            }
            
            $adjustments = json_decode($adjustments_json, true);
            if (!is_array($adjustments) || empty($adjustments)) {
                echo json_encode(['success' => false, 'message' => 'Invalid adjustments data']);
                exit();
            }
            
            // Get enrollment info for overload check
            $enrollment_info = getCurrentEnrollmentInfo($conn, $user_id);
            if (!$enrollment_info || empty($enrollment_info['academic_year']) || empty($enrollment_info['semester'])) {
                echo json_encode(['success' => false, 'message' => 'Unable to determine your current enrollment semester.']);
                exit();
            }
            
            $target_semester = $enrollment_info['semester'];
            $target_academic_year = $enrollment_info['academic_year'];
            
            // Check if student is in 4th year - overload feature does not apply to 4th year students
            $is_4th_year = false;
            if (!empty($enrollment_info['year_level'])) {
                $is_4th_year = (stripos($enrollment_info['year_level'], '4th') !== false || stripos($enrollment_info['year_level'], 'Fourth') !== false);
            }
            
            // Check if student is already overloaded (only for non-4th year students)
            $current_units = getCurrentEnrolledUnits($conn, $user_id, $target_academic_year, $target_semester);
            if (!$is_4th_year && $current_units >= 27) {
                $message = $current_units > 28 
                    ? "Cannot submit requests. Your current enrolled units ({$current_units}) exceed the maximum allowed (28 units). Overload requests are not allowed. Please contact the registrar to resolve this issue."
                    : "Cannot submit requests. Your current enrolled units ({$current_units}) are in the overload range (27-28 units). Overload requests are not allowed. Please contact the registrar to resolve this issue.";
                echo json_encode(['success' => false, 'message' => $message]);
                exit();
            }
            
            // Calculate total units after all adjustments
            $total_addition_units = 0;
            $total_removal_units = 0;
            foreach ($adjustments as $adj) {
                if ($adj['change_type'] === 'add') {
                    $total_addition_units += (float)($adj['units'] ?? 0);
                } elseif ($adj['change_type'] === 'remove') {
                    $total_removal_units += (float)($adj['units'] ?? 0);
                }
            }
            
            $total_units = $current_units + $total_addition_units - $total_removal_units;
            
            // Check overload (overload feature does not apply to 4th year students)
            if (!$is_4th_year && $total_units >= 27) {
                $message = $total_units > 28 
                    ? "Cannot submit requests. Total units would be {$total_units}, which exceeds the maximum allowed (28 units). Overload requests are not allowed. Please remove some additions from your pending adjustments."
                    : "Cannot submit requests. Total units would be {$total_units}, which is considered overload (27-28 units). Overload requests are not allowed. Please remove some additions from your pending adjustments.";
                echo json_encode(['success' => false, 'message' => $message]);
                exit();
            }
            
            // Submit all adjustments
            $conn->beginTransaction();
            $success_count = 0;
            $errors = [];
            
            foreach ($adjustments as $adj) {
                try {
                    $insert_query = "INSERT INTO adjustment_period_changes 
                                   (user_id, change_type, curriculum_id, old_section_schedule_id, 
                                    new_section_schedule_id, status, remarks, change_date)
                                   VALUES (:user_id, :change_type, :curriculum_id, :old_schedule_id, 
                                           :new_schedule_id, 'pending_program_head', :remarks, NOW())";
                    $insert_stmt = $conn->prepare($insert_query);
                    $insert_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                    $insert_stmt->bindParam(':change_type', $adj['change_type']);
                    $insert_stmt->bindParam(':curriculum_id', $adj['curriculum_id'], PDO::PARAM_INT);
                    
                    $old_schedule_id = ($adj['change_type'] === 'remove' || $adj['change_type'] === 'schedule_change') 
                        ? ($adj['old_section_schedule_id'] ?? null) : null;
                    $new_schedule_id = ($adj['change_type'] === 'add' || $adj['change_type'] === 'schedule_change') 
                        ? ($adj['new_section_schedule_id'] ?? null) : null;
                    
                    $insert_stmt->bindValue(':old_schedule_id', $old_schedule_id, $old_schedule_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
                    $insert_stmt->bindValue(':new_schedule_id', $new_schedule_id, $new_schedule_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
                    
                    $remarks = $adj['remarks'] ?? null;
                    $insert_stmt->bindValue(':remarks', $remarks, $remarks ? PDO::PARAM_STR : PDO::PARAM_NULL);
                    
                    $insert_stmt->execute();
                    $success_count++;
                } catch (Exception $e) {
                    $errors[] = ($adj['course_code'] ?? 'Unknown') . ' - ' . $e->getMessage();
                }
            }
            
            if ($success_count > 0) {
                $conn->commit();
                $message = "Successfully submitted {$success_count} adjustment request(s).";
                if (!empty($errors)) {
                    $message .= " " . count($errors) . " request(s) failed: " . implode(', ', $errors);
                }
                echo json_encode(['success' => true, 'message' => $message]);
            } else {
                $conn->rollBack();
                echo json_encode(['success' => false, 'message' => 'Failed to submit any requests. Errors: ' . implode(', ', $errors)]);
            }
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            error_log('Error submitting all adjustments: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'An error occurred while submitting requests. Please try again.']);
        }
        break;
        
    case 'add':
        // Add subject to student (creates pending request)
        try {
            // Check if student is already overloaded - block all requests if overloaded
            // NOTE: Overload feature does not apply to 4th year students
            $enrollment_info = getCurrentEnrollmentInfo($conn, $user_id);
            $is_4th_year = false;
            if ($enrollment_info && !empty($enrollment_info['year_level'])) {
                $is_4th_year = (stripos($enrollment_info['year_level'], '4th') !== false || stripos($enrollment_info['year_level'], 'Fourth') !== false);
            }
            
            if (!$is_4th_year && $enrollment_info && !empty($enrollment_info['academic_year']) && !empty($enrollment_info['semester'])) {
                $current_units = getCurrentEnrolledUnits($conn, $user_id, $enrollment_info['academic_year'], $enrollment_info['semester']);
                if ($current_units >= 27) {
                    $message = $current_units > 28 
                        ? "Cannot make adjustment requests. Your current enrolled units ({$current_units}) exceed the maximum allowed (28 units). Overload requests are not allowed. Please contact the registrar to resolve this issue."
                        : "Cannot make adjustment requests. Your current enrolled units ({$current_units}) are in the overload range (27-28 units). Overload requests are not allowed. Please contact the registrar to resolve this issue.";
                    echo json_encode(['success' => false, 'message' => $message]);
                    exit();
                }
            }
            
            $curriculum_id = (int)($_POST['curriculum_id'] ?? 0);
            $section_schedule_id = (int)($_POST['schedule_id'] ?? 0);
            $remarks = $_POST['remarks'] ?? null;
            
            if ($curriculum_id <= 0 || $section_schedule_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
                exit();
            }
            
            // Check if there's already a pending request for this subject
            $existing_check = "SELECT id, change_type, status 
                              FROM adjustment_period_changes 
                              WHERE user_id = :user_id 
                              AND curriculum_id = :curriculum_id 
                              AND status IN ('pending_program_head', 'pending_dean', 'pending_registrar')";
            $existing_stmt = $conn->prepare($existing_check);
            $existing_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $existing_stmt->bindParam(':curriculum_id', $curriculum_id, PDO::PARAM_INT);
            $existing_stmt->execute();
            $existing_request = $existing_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing_request) {
                $change_type_labels = [
                    'add' => 'addition',
                    'remove' => 'removal',
                    'schedule_change' => 'schedule change'
                ];
                $type_label = $change_type_labels[$existing_request['change_type']] ?? 'request';
                echo json_encode([
                    'success' => false, 
                    'message' => "You already have a pending $type_label request for this subject. Please wait for it to be reviewed before submitting another request."
                ]);
                exit();
            }
            
            // Verify the schedule exists and get section details with capacity
            $schedule_query = "SELECT ss.*, s.id as section_id, s.section_name, s.status as section_status, 
                              s.current_enrolled, s.max_capacity, s.academic_year, s.semester
                              FROM section_schedules ss
                              JOIN sections s ON ss.section_id = s.id
                              WHERE ss.id = :schedule_id";
            $schedule_stmt = $conn->prepare($schedule_query);
            $schedule_stmt->bindParam(':schedule_id', $section_schedule_id, PDO::PARAM_INT);
            $schedule_stmt->execute();
            $schedule_data = $schedule_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$schedule_data || $schedule_data['section_status'] !== 'active') {
                echo json_encode(['success' => false, 'message' => 'Schedule not found or section is inactive.']);
                exit();
            }
            
            // Check section capacity
            if ($schedule_data['current_enrolled'] >= $schedule_data['max_capacity']) {
                echo json_encode(['success' => false, 'message' => 'The selected section "' . htmlspecialchars($schedule_data['section_name']) . '" is full. Please select another section.']);
                exit();
            }
            
            // Get subject units
            $curriculum_query = "SELECT units FROM curriculum WHERE id = :curriculum_id";
            $curriculum_stmt = $conn->prepare($curriculum_query);
            $curriculum_stmt->bindParam(':curriculum_id', $curriculum_id, PDO::PARAM_INT);
            $curriculum_stmt->execute();
            $curriculum_data = $curriculum_stmt->fetch(PDO::FETCH_ASSOC);
            $subject_units = $curriculum_data['units'] ?? 0;
            
            // Get student's CURRENT enrollment info (not from the schedule being added)
            // This ensures we count units from the student's actual current enrollment semester
            $enrollment_info = getCurrentEnrollmentInfo($conn, $user_id);
            
            // Use student's CURRENT enrollment semester for overload check (not the schedule's semester)
            $target_semester = $enrollment_info['semester'] ?? null;
            $target_academic_year = $enrollment_info['academic_year'] ?? null;
            
            // Validate that we have semester and academic year
            if (empty($target_semester) || empty($target_academic_year)) {
                echo json_encode(['success' => false, 'message' => 'Unable to determine your current enrollment semester. Please contact administrator.']);
                exit();
            }
            
            // Get current enrolled units using helper function
            $current_units = getCurrentEnrolledUnits($conn, $user_id, $target_academic_year, $target_semester);
            
            // Get pending additions units
            $pending_additions_query = "SELECT COALESCE(SUM(c.units), 0) as total_units
                                       FROM adjustment_period_changes apc
                                       JOIN section_schedules ss ON apc.new_section_schedule_id = ss.id
                                       JOIN curriculum c ON ss.curriculum_id = c.id
                                       JOIN sections s ON ss.section_id = s.id
                                       WHERE apc.user_id = :user_id
                                       AND apc.change_type = 'add'
                                       AND apc.status IN ('pending_program_head', 'pending_dean', 'pending_registrar')
                                       AND s.academic_year = :academic_year
                                       AND s.semester = :semester";
            $pending_additions_stmt = $conn->prepare($pending_additions_query);
            $pending_additions_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $pending_additions_stmt->bindParam(':academic_year', $target_academic_year);
            $pending_additions_stmt->bindParam(':semester', $target_semester);
            $pending_additions_stmt->execute();
            $pending_additions_data = $pending_additions_stmt->fetch(PDO::FETCH_ASSOC);
            $pending_additions_units = (int)($pending_additions_data['total_units'] ?? 0);
            
            // Calculate total units: only current enrolled subjects + the new subject being added
            // Overload check should only apply to currently enrolled subjects, not pending additions/removals
            $total_units = $current_units + $subject_units;
            
            // Check if it's overload (27 or more units)
            // NOTE: Overload feature does not apply to 4th year students
            $is_overload = (!$is_4th_year && $total_units >= 27);
            $is_excess_overload = (!$is_4th_year && $total_units > 28);
            
            // Block ALL overload requests - they will not be reviewed by program head, dean, or registrar
            // (Only for non-4th year students)
            if ($is_overload) {
                if ($is_excess_overload) {
                    echo json_encode([
                        'success' => false, 
                        'message' => "Cannot add subject. Your current enrolled units ({$current_units}) plus this subject ({$subject_units} units) would total {$total_units} units, which exceeds the maximum allowed (28 units). Overload requests are not allowed and will not be reviewed. Please remove currently enrolled subjects first to reduce your total units."
                    ]);
                } else {
                    echo json_encode([
                        'success' => false, 
                        'message' => "Cannot add subject. Your current enrolled units ({$current_units}) plus this subject ({$subject_units} units) would total {$total_units} units, which is considered overload (27-28 units). Overload requests are not allowed and will not be reviewed. Please remove currently enrolled subjects first to reduce your total units."
                    ]);
                }
                exit();
            }
            
            // Check prerequisites (informational only - don't block)
            $prereq_check = checkPrerequisitesForSubject($conn, $user_id, $curriculum_id);
            $has_unmet_prereqs = !$prereq_check['met'];
            
            // Check schedule conflicts (informational only - don't block)
            $conflict_check = checkScheduleConflicts($conn, $user_id, $section_schedule_id);
            $has_conflicts = $conflict_check['has_conflict'];
            
            // Create pending adjustment request
            $conn->beginTransaction();
            
            $insert_query = "INSERT INTO adjustment_period_changes 
                           (user_id, change_type, curriculum_id, new_section_schedule_id, 
                            status, remarks, change_date)
                           VALUES (:user_id, 'add', :curriculum_id, :schedule_id, 
                                   'pending_program_head', :remarks, NOW())";
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $insert_stmt->bindParam(':curriculum_id', $curriculum_id, PDO::PARAM_INT);
            $insert_stmt->bindParam(':schedule_id', $section_schedule_id, PDO::PARAM_INT);
            if ($remarks === null) {
                $insert_stmt->bindValue(':remarks', null, PDO::PARAM_NULL);
            } else {
                $insert_stmt->bindParam(':remarks', $remarks);
            }
            $insert_stmt->execute();
            
            $conn->commit();
            
            $message = 'Subject addition request submitted successfully.';
            if ($has_unmet_prereqs) {
                $message .= ' Note: Prerequisites not met - will be reviewed by Program Head.';
            }
            if ($has_conflicts) {
                $message .= ' Note: Schedule conflicts detected - will be reviewed by Program Head.';
            }
            
            echo json_encode([
                'success' => true, 
                'message' => $message,
                'has_warnings' => $has_unmet_prereqs || $has_conflicts
            ]);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $error_message = $e->getMessage();
            $error_file = $e->getFile();
            $error_line = $e->getLine();
            error_log('Error in add case: ' . $error_message . ' | File: ' . $error_file . ' | Line: ' . $error_line . ' | Trace: ' . $e->getTraceAsString());
            
            // In development, show more details. In production, show generic message.
            $is_development = (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || 
                              strpos($_SERVER['HTTP_HOST'] ?? '', '127.0.0.1') !== false);
            
            if ($is_development) {
                echo json_encode([
                    'success' => false, 
                    'message' => 'Error: ' . $error_message . ' (File: ' . basename($error_file) . ', Line: ' . $error_line . ')'
                ]);
            } else {
                echo json_encode(['success' => false, 'message' => 'An error occurred while processing your request. Please try again or contact support.']);
            }
        }
        break;
        
    case 'remove':
        // Remove subject from student (creates pending request)
        // Check if student is already overloaded - block all requests if overloaded
        // NOTE: Overload feature does not apply to 4th year students
        $enrollment_info = getCurrentEnrollmentInfo($conn, $user_id);
        $is_4th_year = false;
        if ($enrollment_info && !empty($enrollment_info['year_level'])) {
            $is_4th_year = (stripos($enrollment_info['year_level'], '4th') !== false || stripos($enrollment_info['year_level'], 'Fourth') !== false);
        }
        
        if (!$is_4th_year && $enrollment_info && !empty($enrollment_info['academic_year']) && !empty($enrollment_info['semester'])) {
            $current_units = getCurrentEnrolledUnits($conn, $user_id, $enrollment_info['academic_year'], $enrollment_info['semester']);
            if ($current_units >= 27) {
                $message = $current_units > 28 
                    ? "Cannot make adjustment requests. Your current enrolled units ({$current_units}) exceed the maximum allowed (28 units). Overload requests are not allowed. Please contact the registrar to resolve this issue."
                    : "Cannot make adjustment requests. Your current enrolled units ({$current_units}) are in the overload range (27-28 units). Overload requests are not allowed. Please contact the registrar to resolve this issue.";
                echo json_encode(['success' => false, 'message' => $message]);
                exit();
            }
        }
        
        $section_schedule_id = (int)($_POST['schedule_id'] ?? 0);
        $remarks = $_POST['remarks'] ?? null;
        
        if ($section_schedule_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
            exit();
        }
        
        // Get curriculum_id from schedule
        $schedule_query = "SELECT curriculum_id FROM section_schedules WHERE id = :schedule_id";
        $schedule_stmt = $conn->prepare($schedule_query);
        $schedule_stmt->bindParam(':schedule_id', $section_schedule_id, PDO::PARAM_INT);
        $schedule_stmt->execute();
        $schedule_data = $schedule_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$schedule_data) {
            echo json_encode(['success' => false, 'message' => 'Schedule not found']);
            exit();
        }
        
        // Check if there's already a pending request for this subject
        $existing_check = "SELECT id, change_type, status 
                          FROM adjustment_period_changes 
                          WHERE user_id = :user_id 
                          AND curriculum_id = :curriculum_id 
                          AND status IN ('pending_program_head', 'pending_dean', 'pending_registrar')";
        $existing_stmt = $conn->prepare($existing_check);
        $existing_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $existing_stmt->bindParam(':curriculum_id', $schedule_data['curriculum_id'], PDO::PARAM_INT);
        $existing_stmt->execute();
        $existing_request = $existing_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing_request) {
            $change_type_labels = [
                'add' => 'addition',
                'remove' => 'removal',
                'schedule_change' => 'schedule change'
            ];
            $type_label = $change_type_labels[$existing_request['change_type']] ?? 'request';
            echo json_encode([
                'success' => false, 
                'message' => "You already have a pending $type_label request for this subject. Please wait for it to be reviewed before submitting another request."
            ]);
            exit();
        }
        
        // Create pending adjustment request
        try {
            $conn->beginTransaction();
            
            $insert_query = "INSERT INTO adjustment_period_changes 
                           (user_id, change_type, curriculum_id, old_section_schedule_id, 
                            status, remarks, change_date)
                           VALUES (:user_id, 'remove', :curriculum_id, :schedule_id, 
                                   'pending_program_head', :remarks, NOW())";
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $insert_stmt->bindParam(':curriculum_id', $schedule_data['curriculum_id'], PDO::PARAM_INT);
            $insert_stmt->bindParam(':schedule_id', $section_schedule_id, PDO::PARAM_INT);
            if ($remarks === null) {
                $insert_stmt->bindValue(':remarks', null, PDO::PARAM_NULL);
            } else {
                $insert_stmt->bindParam(':remarks', $remarks);
            }
            $insert_stmt->execute();
            
            $conn->commit();
            
            echo json_encode([
                'success' => true, 
                'message' => 'Subject removal request submitted successfully. Will be reviewed by Program Head.'
            ]);
        } catch (Exception $e) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        break;
        
    case 'change_schedule':
        // Change student schedule (creates pending request)
        // Check if student is already overloaded - block all requests if overloaded
        // NOTE: Overload feature does not apply to 4th year students
        $enrollment_info = getCurrentEnrollmentInfo($conn, $user_id);
        $is_4th_year = false;
        if ($enrollment_info && !empty($enrollment_info['year_level'])) {
            $is_4th_year = (stripos($enrollment_info['year_level'], '4th') !== false || stripos($enrollment_info['year_level'], 'Fourth') !== false);
        }
        
        if (!$is_4th_year && $enrollment_info && !empty($enrollment_info['academic_year']) && !empty($enrollment_info['semester'])) {
            $current_units = getCurrentEnrolledUnits($conn, $user_id, $enrollment_info['academic_year'], $enrollment_info['semester']);
            if ($current_units >= 27) {
                $message = $current_units > 28 
                    ? "Cannot make adjustment requests. Your current enrolled units ({$current_units}) exceed the maximum allowed (28 units). Overload requests are not allowed. Please contact the registrar to resolve this issue."
                    : "Cannot make adjustment requests. Your current enrolled units ({$current_units}) are in the overload range (27-28 units). Overload requests are not allowed. Please contact the registrar to resolve this issue.";
                echo json_encode(['success' => false, 'message' => $message]);
                exit();
            }
        }
        
        $old_section_schedule_id = (int)($_POST['old_schedule_id'] ?? 0);
        $new_section_schedule_id = (int)($_POST['new_schedule_id'] ?? 0);
        $remarks = $_POST['remarks'] ?? null;
        
        if ($old_section_schedule_id <= 0 || $new_section_schedule_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
            exit();
        }
        
        // Get curriculum_id from the student's current schedule
        // This ensures we're working with a schedule the student actually has
        $schedule_query = "SELECT ss.curriculum_id, sts.section_schedule_id
                          FROM student_schedules sts
                          JOIN section_schedules ss ON sts.section_schedule_id = ss.id
                          WHERE sts.section_schedule_id = :schedule_id 
                          AND sts.user_id = :user_id
                          AND sts.status = 'active'";
        $schedule_stmt = $conn->prepare($schedule_query);
        $schedule_stmt->bindParam(':schedule_id', $old_section_schedule_id, PDO::PARAM_INT);
        $schedule_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $schedule_stmt->execute();
        $schedule_data = $schedule_stmt->fetch(PDO::FETCH_ASSOC);
        
        // If not found via student_schedules, try section_schedules directly (fallback)
        if (!$schedule_data) {
            $schedule_query = "SELECT curriculum_id FROM section_schedules WHERE id = :schedule_id";
            $schedule_stmt = $conn->prepare($schedule_query);
            $schedule_stmt->bindParam(':schedule_id', $old_section_schedule_id, PDO::PARAM_INT);
            $schedule_stmt->execute();
            $schedule_data = $schedule_stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        if (!$schedule_data || empty($schedule_data['curriculum_id'])) {
            error_log("Schedule change error - old_schedule_id: $old_section_schedule_id, user_id: $user_id, schedule_data: " . json_encode($schedule_data));
            echo json_encode(['success' => false, 'message' => 'Schedule not found. The schedule may have been removed or is no longer available. Please refresh the page and try again.']);
            exit();
        }
        
        // Verify the new schedule exists and is for the same curriculum
        $new_schedule_query = "SELECT ss.curriculum_id, ss.id, s.id as section_id, s.section_name, 
                              s.current_enrolled, s.max_capacity, s.status as section_status
                              FROM section_schedules ss 
                              JOIN sections s ON ss.section_id = s.id
                              WHERE ss.id = :schedule_id";
        $new_schedule_stmt = $conn->prepare($new_schedule_query);
        $new_schedule_stmt->bindParam(':schedule_id', $new_section_schedule_id, PDO::PARAM_INT);
        $new_schedule_stmt->execute();
        $new_schedule_data = $new_schedule_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$new_schedule_data) {
            echo json_encode(['success' => false, 'message' => 'The selected schedule is no longer available. Please refresh the page and try again.']);
            exit();
        }
        
        if ($new_schedule_data['section_status'] !== 'active') {
            echo json_encode(['success' => false, 'message' => 'The selected section is not active.']);
            exit();
        }
        
        // Check section capacity
        if ($new_schedule_data['current_enrolled'] >= $new_schedule_data['max_capacity']) {
            echo json_encode(['success' => false, 'message' => 'The selected section "' . htmlspecialchars($new_schedule_data['section_name']) . '" is full. Please select another section.']);
            exit();
        }
        
        // Verify both schedules are for the same curriculum
        if ($schedule_data['curriculum_id'] != $new_schedule_data['curriculum_id']) {
            echo json_encode(['success' => false, 'message' => 'Cannot change schedule to a different subject.']);
            exit();
        }
        
        // Check if there's already a pending request for this subject
        $existing_check = "SELECT id, change_type, status 
                          FROM adjustment_period_changes 
                          WHERE user_id = :user_id 
                          AND curriculum_id = :curriculum_id 
                          AND status IN ('pending_program_head', 'pending_dean', 'pending_registrar')";
        $existing_stmt = $conn->prepare($existing_check);
        $existing_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $existing_stmt->bindParam(':curriculum_id', $schedule_data['curriculum_id'], PDO::PARAM_INT);
        $existing_stmt->execute();
        $existing_request = $existing_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing_request) {
            $change_type_labels = [
                'add' => 'addition',
                'remove' => 'removal',
                'schedule_change' => 'schedule change'
            ];
            $type_label = $change_type_labels[$existing_request['change_type']] ?? 'request';
            echo json_encode([
                'success' => false, 
                'message' => "You already have a pending $type_label request for this subject. Please wait for it to be reviewed before submitting another request."
            ]);
            exit();
        }
        
        // Check schedule conflicts (informational only)
        $conflict_check = checkScheduleConflicts($conn, $user_id, $new_section_schedule_id);
        $has_conflicts = $conflict_check['has_conflict'];
        
        // Create pending adjustment request
        try {
            $conn->beginTransaction();
            
            $insert_query = "INSERT INTO adjustment_period_changes 
                           (user_id, change_type, curriculum_id, old_section_schedule_id, 
                            new_section_schedule_id, status, remarks, change_date)
                           VALUES (:user_id, 'schedule_change', :curriculum_id, :old_schedule_id, 
                                   :new_schedule_id, 'pending_program_head', :remarks, NOW())";
            $insert_stmt = $conn->prepare($insert_query);
            $insert_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $insert_stmt->bindParam(':curriculum_id', $schedule_data['curriculum_id'], PDO::PARAM_INT);
            $insert_stmt->bindParam(':old_schedule_id', $old_section_schedule_id, PDO::PARAM_INT);
            $insert_stmt->bindParam(':new_schedule_id', $new_section_schedule_id, PDO::PARAM_INT);
            if ($remarks === null) {
                $insert_stmt->bindValue(':remarks', null, PDO::PARAM_NULL);
            } else {
                $insert_stmt->bindParam(':remarks', $remarks);
            }
            $insert_stmt->execute();
            
            $conn->commit();
            
            $message = 'Schedule change request submitted successfully.';
            if ($has_conflicts) {
                $message .= ' Note: Schedule conflicts detected - will be reviewed by Program Head.';
            }
            
            echo json_encode([
                'success' => true, 
                'message' => $message,
                'has_warnings' => $has_conflicts
            ]);
        } catch (Exception $e) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        break;
        
    case 'cancel_request':
        // Cancel a pending adjustment request
        $request_id = (int)($_POST['request_id'] ?? 0);
        
        if ($request_id <= 0) {
            echo json_encode(['success' => false, 'message' => 'Invalid request ID']);
            exit();
        }
        
        try {
            // Verify the request belongs to this user and is still pending
            $verify_query = "SELECT id, change_type, curriculum_id 
                           FROM adjustment_period_changes 
                           WHERE id = :request_id 
                           AND user_id = :user_id 
                           AND status IN ('pending_program_head', 'pending_dean', 'pending_registrar')";
            $verify_stmt = $conn->prepare($verify_query);
            $verify_stmt->bindParam(':request_id', $request_id, PDO::PARAM_INT);
            $verify_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $verify_stmt->execute();
            $request = $verify_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$request) {
                echo json_encode(['success' => false, 'message' => 'Request not found or cannot be cancelled']);
                exit();
            }
            
            // Delete the request
            $delete_query = "DELETE FROM adjustment_period_changes WHERE id = :request_id AND user_id = :user_id";
            $delete_stmt = $conn->prepare($delete_query);
            $delete_stmt->bindParam(':request_id', $request_id, PDO::PARAM_INT);
            $delete_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $delete_stmt->execute();
            
            echo json_encode([
                'success' => true, 
                'message' => 'Adjustment request cancelled successfully.'
            ]);
        } catch (Exception $e) {
            error_log('Error cancelling request: ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Error cancelling request: ' . $e->getMessage()]);
        }
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}

// Clean output buffer and ensure only JSON is sent
$final_output = ob_get_contents();
ob_end_clean();

// Remove any non-JSON content that might have been output (warnings, notices, etc.)
$json_start = strpos($final_output, '{');
if ($json_start !== false && $json_start > 0) {
    // If there's content before the JSON, log it and remove it
    error_log('Non-JSON content removed from output: ' . substr($final_output, 0, $json_start));
    $final_output = substr($final_output, $json_start);
}

// Verify it's valid JSON, if not, send error
if (!empty($final_output)) {
    json_decode($final_output);
    if (json_last_error() !== JSON_ERROR_NONE) {
        // If output is not valid JSON, send error response
        error_log('Invalid JSON output: ' . substr($final_output, 0, 500));
        $final_output = json_encode(['success' => false, 'message' => 'Server error: Invalid response format']);
    }
} else {
    // No output was generated
    $final_output = json_encode(['success' => false, 'message' => 'Server error: No response generated']);
}

echo $final_output;
