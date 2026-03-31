<?php
declare(strict_types=1);

require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../config/adjustment_helper.php';
require_once '../classes/Admin.php';

if (!isLoggedIn()) {
    redirect('../public/login.php');
}

$conn = (new Database())->getConnection();
$user_id = (int)$_SESSION['user_id'];

// Check if adjustment period is open
$admin = new Admin();
$adjustment_control = $admin->getAdjustmentPeriodControl();
$is_adjustment_open = $admin->isAdjustmentPeriodOpen();

if (!$is_adjustment_open) {
    $announcement = $adjustment_control['announcement'] ?? 'Adjustment period is currently closed.';
    $_SESSION['message'] = $announcement;
    redirect('dashboard.php');
}

// Check if student is currently enrolled OR has confirmed enrollment for next semester
// NOTE: Adjustment period is available for ALL enrolled students from 1st Year First Semester onwards
// No year level restrictions apply - all enrolled students are eligible
$enrollment_check_query = "SELECT * FROM next_semester_enrollments 
                          WHERE user_id = :user_id 
                          AND request_status = 'confirmed'
                          ORDER BY created_at DESC LIMIT 1";
$enrollment_check_stmt = $conn->prepare($enrollment_check_query);
$enrollment_check_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$enrollment_check_stmt->execute();
$confirmed_enrollment = $enrollment_check_stmt->fetch(PDO::FETCH_ASSOC);

// Also check if student is currently enrolled
$user_status_query = "SELECT enrollment_status FROM users WHERE id = :user_id";
$user_status_stmt = $conn->prepare($user_status_query);
$user_status_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$user_status_stmt->execute();
$user_status = $user_status_stmt->fetch(PDO::FETCH_ASSOC);
$is_currently_enrolled = ($user_status && $user_status['enrollment_status'] == 'enrolled');

if (!$confirmed_enrollment && !$is_currently_enrolled) {
    $_SESSION['message'] = 'Only enrolled students can access the adjustment period.';
    redirect('dashboard.php');
}

// Get current enrollment info (same logic as dashboard)
// Prefer the latest COR, then most recent active section, then enrolled_students
$current_enrollment = null;

// 1) Try to derive current enrollment from the most recent COR
// Hierarchy: 1st Year First > 1st Year Second > 2nd Year First > 2nd Year Second > 3rd Year First > etc.
// Most recent = highest year level, then highest semester within that year
$latest_cor_query = "SELECT cor.academic_year, cor.semester, cor.year_level, cor.section_id
                     FROM certificate_of_registration cor
                     WHERE cor.user_id = :user_id
                     ORDER BY cor.academic_year DESC,
                         CASE cor.year_level
                             WHEN '4th Year' THEN 4
                             WHEN '3rd Year' THEN 3
                             WHEN '2nd Year' THEN 2
                             WHEN '1st Year' THEN 1
                             ELSE 0
                         END DESC,
                         CASE cor.semester
                             WHEN 'Second Semester' THEN 2
                             WHEN 'First Semester' THEN 1
                             ELSE 0
                         END DESC,
                         cor.created_at DESC
                     LIMIT 1";
$latest_cor_stmt = $conn->prepare($latest_cor_query);
$latest_cor_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$latest_cor_stmt->execute();
$latest_cor = $latest_cor_stmt->fetch(PDO::FETCH_ASSOC);

if ($latest_cor && !empty($latest_cor['semester'])) {
    $current_enrollment = [
        'academic_year' => $latest_cor['academic_year'],
        'semester' => $latest_cor['semester'],
        'year_level' => $latest_cor['year_level'],
        'section_id' => $latest_cor['section_id']
    ];
} else {
    // 2) Fallback to most recent active section
    $latest_section_query = "SELECT s.academic_year, s.semester, s.year_level, s.id as section_id
                            FROM section_enrollments se
                            JOIN sections s ON se.section_id = s.id
                            WHERE se.user_id = :user_id
                            AND se.status = 'active'
                            ORDER BY s.academic_year DESC,
                                     CASE s.year_level
                                         WHEN '1st Year' THEN 1
                                         WHEN '2nd Year' THEN 2
                                         WHEN '3rd Year' THEN 3
                                         WHEN '4th Year' THEN 4
                                         ELSE 0
                                     END DESC,
                                     CASE s.semester
                                         WHEN 'Second Semester' THEN 2
                                         WHEN 'First Semester' THEN 1
                                         ELSE 0
                                     END DESC,
                                     se.enrolled_date DESC
                            LIMIT 1";
    $latest_section_stmt = $conn->prepare($latest_section_query);
    $latest_section_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $latest_section_stmt->execute();
    $latest_section = $latest_section_stmt->fetch(PDO::FETCH_ASSOC);

    if ($latest_section && !empty($latest_section['semester'])) {
        $current_enrollment = [
            'academic_year' => $latest_section['academic_year'],
            'semester' => $latest_section['semester'],
            'year_level' => $latest_section['year_level'],
            'section_id' => $latest_section['section_id']
        ];
    } else {
        // 3) Fallback to enrolled_students
        $enrollment_info_query = "SELECT academic_year, semester, year_level 
                                 FROM enrolled_students 
                                 WHERE user_id = :user_id 
                                 ORDER BY updated_at DESC LIMIT 1";
        $enrollment_info_stmt = $conn->prepare($enrollment_info_query);
        $enrollment_info_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $enrollment_info_stmt->execute();
        $enrollment_info = $enrollment_info_stmt->fetch(PDO::FETCH_ASSOC);

        if ($enrollment_info) {
            $current_enrollment = [
                'academic_year' => $enrollment_info['academic_year'],
                'semester' => $enrollment_info['semester'],
                'year_level' => $enrollment_info['year_level']
            ];
        }
    }
}

// Determine target academic year and semester
// For currently enrolled students, always use their current enrollment (not adjustment control)
if ($current_enrollment && !$confirmed_enrollment) {
    // For currently enrolled students, use their current enrollment semester
    $target_academic_year = $current_enrollment['academic_year'];
    $target_semester = $current_enrollment['semester'];
} elseif ($confirmed_enrollment) {
    // Use the confirmed enrollment's target term (for next semester)
    $target_academic_year = $confirmed_enrollment['target_academic_year'];
    $target_semester = $confirmed_enrollment['target_semester'];
} else {
    // Fallback to adjustment control
    $target_academic_year = $adjustment_control['academic_year'];
    $target_semester = $adjustment_control['semester'];
}

// Get student's current active schedules - use same logic as dashboard
// Get from COR first, then match with student_schedules
$current_schedules = [];

// Get the most recent COR for this student (matching current semester)
$cor_query = "SELECT cor.id, cor.subjects_json, cor.academic_year, cor.semester, cor.section_id,
              s.section_name, s.year_level, p.program_code
              FROM certificate_of_registration cor
              JOIN sections s ON cor.section_id = s.id
              JOIN programs p ON cor.program_id = p.id
              WHERE cor.user_id = :user_id
              AND cor.academic_year = :academic_year
              AND cor.semester = :semester
              ORDER BY cor.created_at DESC
              LIMIT 1";

$cor_stmt = $conn->prepare($cor_query);
$cor_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$cor_stmt->bindParam(':academic_year', $target_academic_year);
$cor_stmt->bindParam(':semester', $target_semester);
$cor_stmt->execute();
$cor_record = $cor_stmt->fetch(PDO::FETCH_ASSOC);

if ($cor_record && !empty($cor_record['subjects_json'])) {
    $cor_subjects = json_decode($cor_record['subjects_json'], true);
    
    if (is_array($cor_subjects) && !empty($cor_subjects)) {
        // Extract course codes from COR
        $cor_subject_codes = array_map(
            'strtoupper',
            array_filter(array_column($cor_subjects, 'course_code') ?: [])
        );
        
        // Get schedule details from student_schedules for subjects in COR
        if (!empty($cor_subject_codes)) {
            // Build named parameters for IN clause
            $named_params = [];
            foreach ($cor_subject_codes as $idx => $code) {
                $param_name = ':course_code_' . $idx;
                $named_params[] = $param_name;
            }
            $placeholders = implode(',', $named_params);
            
            $schedules_query = "SELECT 
                                sts.*, sts.id as student_schedule_id,
                                ss.curriculum_id, ss.id as section_schedule_id,
                                c.year_level, c.semester as subject_semester,
                                s.section_name, s.year_level as section_year_level, 
                                s.semester as section_semester, s.academic_year,
                                p.program_code, p.program_name
                                FROM student_schedules sts
                                JOIN section_schedules ss ON sts.section_schedule_id = ss.id
                                JOIN curriculum c ON ss.curriculum_id = c.id
                                JOIN sections s ON ss.section_id = s.id
                                JOIN programs p ON s.program_id = p.id
                                WHERE sts.user_id = :user_id 
                                AND sts.status = 'active'
                                AND s.academic_year = :academic_year
                                AND s.semester = :semester
                                AND UPPER(TRIM(sts.course_code)) IN ($placeholders)
                                ORDER BY sts.course_code";
            
            $schedules_stmt = $conn->prepare($schedules_query);
            $schedules_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $schedules_stmt->bindParam(':academic_year', $target_academic_year);
            $schedules_stmt->bindParam(':semester', $target_semester);
            
            // Bind all course codes using named parameters
            foreach ($cor_subject_codes as $idx => $code) {
                $param_name = ':course_code_' . $idx;
                $schedules_stmt->bindValue($param_name, $code);
            }
            
            $schedules_stmt->execute();
            $current_schedules = $schedules_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} else {
    // Fallback: if no COR, get from student_schedules directly matching semester
    $current_schedules_query = "SELECT sts.*, sts.id as student_schedule_id,
                               ss.curriculum_id, ss.id as section_schedule_id,
                               c.year_level, c.semester as subject_semester,
                               c.course_code, c.course_name, c.units,
                               s.section_name, s.year_level as section_year_level, 
                               s.semester as section_semester, s.academic_year,
                               p.program_code, p.program_name
                               FROM student_schedules sts
                               JOIN section_schedules ss ON sts.section_schedule_id = ss.id
                               JOIN curriculum c ON ss.curriculum_id = c.id
                               JOIN sections s ON ss.section_id = s.id
                               JOIN programs p ON s.program_id = p.id
                               WHERE sts.user_id = :user_id
                               AND sts.status = 'active'
                               AND s.academic_year = :academic_year
                               AND s.semester = :semester
                               ORDER BY sts.course_code";
    $current_schedules_stmt = $conn->prepare($current_schedules_query);
    $current_schedules_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $current_schedules_stmt->bindParam(':academic_year', $target_academic_year);
    $current_schedules_stmt->bindParam(':semester', $target_semester);
    $current_schedules_stmt->execute();
    $current_schedules = $current_schedules_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get student's program
$program_query = "SELECT DISTINCT p.id, p.program_code, p.program_name
                  FROM section_enrollments se
                  JOIN sections s ON se.section_id = s.id
                  JOIN programs p ON s.program_id = p.id
                  WHERE se.user_id = :user_id
                  AND se.status = 'active'
                  LIMIT 1";
$program_stmt = $conn->prepare($program_query);
$program_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$program_stmt->execute();
$student_program = $program_stmt->fetch(PDO::FETCH_ASSOC);

if (!$student_program) {
    $_SESSION['message'] = 'Student program not found.';
    redirect('dashboard.php');
}

// Get student's current year level
// Use year level from current_enrollment if available (most reliable), otherwise fallback to enrolled_students
if ($current_enrollment && !empty($current_enrollment['year_level'])) {
    $current_year_level = $current_enrollment['year_level'];
} else {
    $year_level_query = "SELECT year_level FROM enrolled_students WHERE user_id = :user_id ORDER BY updated_at DESC LIMIT 1";
    $year_level_stmt = $conn->prepare($year_level_query);
    $year_level_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $year_level_stmt->execute();
    $year_level_data = $year_level_stmt->fetch(PDO::FETCH_ASSOC);
    $current_year_level = $year_level_data['year_level'] ?? '1st Year';
}

// Get student's passed subjects (for backload filtering)
$passed_subjects_query = "SELECT DISTINCT curriculum_id 
                         FROM student_grades 
                         WHERE user_id = :user_id 
                         AND status IN ('verified', 'finalized')
                         AND grade_letter NOT IN ('F', 'FA', 'FAILED', 'INC', 'INCOMPLETE', 'W', 'WITHDRAWN', 'DROPPED')
                         AND grade < 5.0";
$passed_subjects_stmt = $conn->prepare($passed_subjects_query);
$passed_subjects_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$passed_subjects_stmt->execute();
$passed_subject_ids = $passed_subjects_stmt->fetchAll(PDO::FETCH_COLUMN);

// Get enrolled curriculum IDs
$enrolled_curriculum_ids = array_column($current_schedules, 'curriculum_id');
$enrolled_curriculum_ids = array_filter($enrolled_curriculum_ids);

// Get pending adjustment requests with full details
$pending_requests_query = "SELECT apc.*, 
                           c.course_code, c.course_name, c.units,
                           CASE 
                               WHEN apc.change_type = 'add' THEN ss_new.section_id
                               WHEN apc.change_type = 'remove' THEN ss_old.section_id
                               WHEN apc.change_type = 'schedule_change' THEN ss_new.section_id
                           END as section_id,
                           CASE 
                               WHEN apc.change_type = 'add' THEN s_new.section_name
                               WHEN apc.change_type = 'remove' THEN s_old.section_name
                               WHEN apc.change_type = 'schedule_change' THEN s_new.section_name
                           END as section_name,
                           CASE 
                               WHEN apc.change_type = 'add' THEN ss_new.time_start
                               WHEN apc.change_type = 'remove' THEN ss_old.time_start
                               WHEN apc.change_type = 'schedule_change' THEN ss_new.time_start
                           END as time_start,
                           CASE 
                               WHEN apc.change_type = 'add' THEN ss_new.time_end
                               WHEN apc.change_type = 'remove' THEN ss_old.time_end
                               WHEN apc.change_type = 'schedule_change' THEN ss_new.time_end
                           END as time_end,
                           CASE 
                               WHEN apc.change_type = 'add' THEN ss_new.schedule_monday
                               WHEN apc.change_type = 'remove' THEN ss_old.schedule_monday
                               WHEN apc.change_type = 'schedule_change' THEN ss_new.schedule_monday
                           END as schedule_monday,
                           CASE 
                               WHEN apc.change_type = 'add' THEN ss_new.schedule_tuesday
                               WHEN apc.change_type = 'remove' THEN ss_old.schedule_tuesday
                               WHEN apc.change_type = 'schedule_change' THEN ss_new.schedule_tuesday
                           END as schedule_tuesday,
                           CASE 
                               WHEN apc.change_type = 'add' THEN ss_new.schedule_wednesday
                               WHEN apc.change_type = 'remove' THEN ss_old.schedule_wednesday
                               WHEN apc.change_type = 'schedule_change' THEN ss_new.schedule_wednesday
                           END as schedule_wednesday,
                           CASE 
                               WHEN apc.change_type = 'add' THEN ss_new.schedule_thursday
                               WHEN apc.change_type = 'remove' THEN ss_old.schedule_thursday
                               WHEN apc.change_type = 'schedule_change' THEN ss_new.schedule_thursday
                           END as schedule_thursday,
                           CASE 
                               WHEN apc.change_type = 'add' THEN ss_new.schedule_friday
                               WHEN apc.change_type = 'remove' THEN ss_old.schedule_friday
                               WHEN apc.change_type = 'schedule_change' THEN ss_new.schedule_friday
                           END as schedule_friday,
                           CASE 
                               WHEN apc.change_type = 'add' THEN ss_new.schedule_saturday
                               WHEN apc.change_type = 'remove' THEN ss_old.schedule_saturday
                               WHEN apc.change_type = 'schedule_change' THEN ss_new.schedule_saturday
                           END as schedule_saturday,
                           CASE 
                               WHEN apc.change_type = 'add' THEN ss_new.schedule_sunday
                               WHEN apc.change_type = 'remove' THEN ss_old.schedule_sunday
                               WHEN apc.change_type = 'schedule_change' THEN ss_new.schedule_sunday
                           END as schedule_sunday,
                           s_old.section_name as old_section_name,
                           ss_old.time_start as old_time_start,
                           ss_old.time_end as old_time_end
                           FROM adjustment_period_changes apc
                           JOIN curriculum c ON apc.curriculum_id = c.id
                           LEFT JOIN section_schedules ss_new ON apc.new_section_schedule_id = ss_new.id
                           LEFT JOIN section_schedules ss_old ON apc.old_section_schedule_id = ss_old.id
                           LEFT JOIN sections s_new ON ss_new.section_id = s_new.id
                           LEFT JOIN sections s_old ON ss_old.section_id = s_old.id
                           WHERE apc.user_id = :user_id 
                           AND apc.status IN ('pending_program_head', 'pending_dean', 'pending_registrar')
                           ORDER BY apc.change_date DESC";
$pending_requests_stmt = $conn->prepare($pending_requests_query);
$pending_requests_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$pending_requests_stmt->execute();
$pending_requests = $pending_requests_stmt->fetchAll(PDO::FETCH_ASSOC);
$pending_curriculum_ids = array_column($pending_requests, 'curriculum_id');
$pending_requests_map = [];
foreach ($pending_requests as $req) {
    $pending_requests_map[$req['curriculum_id']] = $req;
}

// Calculate total units: current enrolled + pending additions - pending removals
$current_units = 0;
foreach ($current_schedules as $schedule) {
    $current_units += (int)($schedule['units'] ?? 0);
}

$pending_additions_units = 0;
$pending_removals_units = 0;
foreach ($pending_requests as $req) {
    if ($req['change_type'] === 'add') {
        $pending_additions_units += (int)($req['units'] ?? 0);
    } elseif ($req['change_type'] === 'remove') {
        $pending_removals_units += (int)($req['units'] ?? 0);
    }
}

$total_units = $current_units + $pending_additions_units - $pending_removals_units;

// Check if student is in 4th year - overload feature should not apply for 4th year students
$is_4th_year = (stripos($current_year_level, '4th') !== false || stripos($current_year_level, 'Fourth') !== false);

// Overload checks only apply to non-4th year students
if ($is_4th_year) {
    $is_overload = false;
    $is_excess_overload = false;
} else {
    $is_overload = ($total_units >= 27 && $total_units <= 28);
    $is_excess_overload = ($total_units > 28);
}

// Get current year level subjects from current semester (not yet enrolled)
$current_year_subjects = [];
if (!empty($current_year_level) && !empty($target_semester)) {
    $current_year_query = "SELECT c.*, p.program_code, p.program_name
                          FROM curriculum c
                          JOIN programs p ON c.program_id = p.id
                          WHERE c.program_id = :program_id
                          AND c.year_level = :year_level
                          AND c.semester = :semester";
    
    // Exclude currently enrolled subjects
    if (!empty($enrolled_curriculum_ids)) {
        $exclude_placeholders = [];
        foreach ($enrolled_curriculum_ids as $idx => $curr_id) {
            $exclude_placeholders[] = ':exclude_enrolled_' . $idx;
        }
        $current_year_query .= " AND c.id NOT IN (" . implode(',', $exclude_placeholders) . ")";
    }
    
    // Exclude passed subjects
    if (!empty($passed_subject_ids)) {
        $passed_placeholders = [];
        foreach ($passed_subject_ids as $idx => $passed_id) {
            $passed_placeholders[] = ':exclude_passed_' . $idx;
        }
        $current_year_query .= " AND c.id NOT IN (" . implode(',', $passed_placeholders) . ")";
    }
    
    $current_year_query .= " ORDER BY c.course_code";
    
    $current_year_stmt = $conn->prepare($current_year_query);
    $current_year_stmt->bindParam(':program_id', $student_program['id'], PDO::PARAM_INT);
    $current_year_stmt->bindParam(':year_level', $current_year_level);
    $current_year_stmt->bindParam(':semester', $target_semester);
    
    if (!empty($enrolled_curriculum_ids)) {
        foreach ($enrolled_curriculum_ids as $idx => $curr_id) {
            $current_year_stmt->bindValue(':exclude_enrolled_' . $idx, $curr_id, PDO::PARAM_INT);
        }
    }
    
    if (!empty($passed_subject_ids)) {
        foreach ($passed_subject_ids as $idx => $passed_id) {
            $current_year_stmt->bindValue(':exclude_passed_' . $idx, $passed_id, PDO::PARAM_INT);
        }
    }
    
    $current_year_stmt->execute();
    $current_year_subjects = $current_year_stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get backload subjects (from lower year levels)
// NOTE: Backload subjects are only available for 2nd Year and above students
// 1st Year students cannot have backload subjects since they are the entry level
$backload_subjects = [];
$year_level_numeric = 0;
if (stripos($current_year_level, '1st') !== false || stripos($current_year_level, 'First') !== false) {
    $year_level_numeric = 1;
} elseif (stripos($current_year_level, '2nd') !== false || stripos($current_year_level, 'Second') !== false) {
    $year_level_numeric = 2;
} elseif (stripos($current_year_level, '3rd') !== false || stripos($current_year_level, 'Third') !== false) {
    $year_level_numeric = 3;
} elseif (stripos($current_year_level, '4th') !== false || stripos($current_year_level, 'Fourth') !== false) {
    $year_level_numeric = 4;
}

// Get backload subjects for students in 2nd year and above
// 1st Year students will have empty backload_subjects array (correct behavior)
if ($year_level_numeric >= 2) {
    $backload_year_levels = [];
    for ($i = 1; $i < $year_level_numeric; $i++) {
        $backload_year_levels[] = $i . ($i == 1 ? 'st' : ($i == 2 ? 'nd' : ($i == 3 ? 'rd' : 'th'))) . ' Year';
    }
    
    if (!empty($backload_year_levels)) {
        $year_level_placeholders = [];
        foreach ($backload_year_levels as $idx => $yl) {
            $year_level_placeholders[] = ':year_level_' . $idx;
        }
        
        $backload_query = "SELECT c.*, p.program_code, p.program_name
                          FROM curriculum c
                          JOIN programs p ON c.program_id = p.id
                          WHERE c.program_id = :program_id
                          AND c.year_level IN (" . implode(',', $year_level_placeholders) . ")
                          AND c.semester = :semester";
        
        // Exclude currently enrolled subjects
        if (!empty($enrolled_curriculum_ids)) {
            $exclude_placeholders = [];
            foreach ($enrolled_curriculum_ids as $idx => $curr_id) {
                $exclude_placeholders[] = ':exclude_enrolled_' . $idx;
            }
            $backload_query .= " AND c.id NOT IN (" . implode(',', $exclude_placeholders) . ")";
        }
        
        // Exclude subjects student has already passed
        if (!empty($passed_subject_ids)) {
            $passed_placeholders = [];
            foreach ($passed_subject_ids as $idx => $passed_id) {
                $passed_placeholders[] = ':exclude_passed_' . $idx;
            }
            $backload_query .= " AND c.id NOT IN (" . implode(',', $passed_placeholders) . ")";
        }
        
        $backload_query .= " ORDER BY c.year_level, c.course_code";
        
        $backload_stmt = $conn->prepare($backload_query);
        $backload_stmt->bindValue(':program_id', $student_program['id'], PDO::PARAM_INT);
        
        // Bind year levels
        foreach ($backload_year_levels as $idx => $yl) {
            $backload_stmt->bindValue(':year_level_' . $idx, $yl);
        }
        
        // Bind semester
        $backload_stmt->bindValue(':semester', $target_semester);
        
        // Bind excluded enrolled subjects
        if (!empty($enrolled_curriculum_ids)) {
            foreach ($enrolled_curriculum_ids as $idx => $curr_id) {
                $backload_stmt->bindValue(':exclude_enrolled_' . $idx, $curr_id, PDO::PARAM_INT);
            }
        }
        
        // Bind excluded passed subjects
        if (!empty($passed_subject_ids)) {
            foreach ($passed_subject_ids as $idx => $passed_id) {
                $backload_stmt->bindValue(':exclude_passed_' . $idx, $passed_id, PDO::PARAM_INT);
            }
        }
        
        $backload_stmt->execute();
        $backload_subjects = $backload_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Adjustment Period - Student</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f2f6fc;
        }
        .card {
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .schedule-item {
            border-left: 4px solid #007bff;
            padding: 15px;
            margin-bottom: 10px;
            background: #f8f9fa;
            border-radius: 5px;
        }
        .prereq-warning {
            color: #dc3545;
            font-size: 0.9em;
        }
        .conflict-warning {
            background: #fff3cd;
            border-left-color: #ffc107;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h4 mb-0"><i class="fas fa-exchange-alt me-2 text-primary"></i>Adjustment Period</h1>
            <div>
                <a href="view_adjustments.php" class="btn btn-info me-2">
                    <i class="fas fa-list-alt me-2"></i>View My Requests
                </a>
                <a href="dashboard.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                </a>
            </div>
        </div>

        <?php if ($adjustment_control && !empty($adjustment_control['announcement'])): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i><?php echo htmlspecialchars($adjustment_control['announcement']); ?>
            </div>
        <?php endif; ?>

        <!-- Units Summary -->
        <div class="card mb-4">
            <div class="card-header <?php echo $is_excess_overload ? 'bg-danger' : ($is_overload ? 'bg-warning' : 'bg-info'); ?> text-white">
                <h5 class="mb-0"><i class="fas fa-calculator me-2"></i>Units Summary</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <div class="text-center">
                            <h6 class="text-muted mb-1">Current Enrolled</h6>
                            <h3 class="mb-0"><?php echo $current_units; ?></h3>
                            <small class="text-muted">units</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center">
                            <h6 class="text-muted mb-1">Pending Additions</h6>
                            <h3 class="mb-0 text-success" data-pending-additions>+<?php echo $pending_additions_units; ?></h3>
                            <small class="text-muted">units</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center">
                            <h6 class="text-muted mb-1">Pending Removals</h6>
                            <h3 class="mb-0 text-danger" data-pending-removals>-<?php echo $pending_removals_units; ?></h3>
                            <small class="text-muted">units</small>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="text-center">
                            <h6 class="text-muted mb-1">Total Units</h6>
                            <h3 class="mb-0 <?php echo $is_excess_overload ? 'text-danger' : ($is_overload ? 'text-warning' : 'text-primary'); ?>" data-total-units>
                                <?php echo $total_units; ?>
                            </h3>
                            <small class="text-muted">units</small>
                        </div>
                    </div>
                </div>
                <?php if ($is_excess_overload): ?>
                    <div class="alert alert-danger mt-3 mb-0">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Overload Warning:</strong> Your total units (<?php echo $total_units; ?>) exceed the maximum allowed (28 units). 
                        <strong>Overload requests are not allowed and will not be reviewed.</strong> Please remove some pending additions to reduce your total units before adding new subjects.
                    </div>
                <?php elseif ($is_overload): ?>
                    <div class="alert alert-warning mt-3 mb-0">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <strong>Overload Notice:</strong> Your total units (<?php echo $total_units; ?>) are in the overload range (27-28 units). 
                        <strong>Overload requests are not allowed and will not be reviewed.</strong> Please remove some pending additions to reduce your total units before adding new subjects.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Pending Adjustments (Not Yet Submitted) -->
        <div class="card mb-4" id="pendingAdjustmentsCard" style="display: none;">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>Pending Adjustments</h5>
            </div>
            <div class="card-body">
                <p class="text-muted mb-3">Review your adjustments below. Click "Submit All Requests" when ready to submit them for review.</p>
                <div class="table-responsive">
                    <table class="table table-hover" id="pendingAdjustmentsTable">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Subject</th>
                                <th>Units</th>
                                <th>Schedule</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="pendingAdjustmentsBody">
                            <!-- Will be populated by JavaScript -->
                        </tbody>
                    </table>
                </div>
                <div class="mt-3 d-flex justify-content-between align-items-center">
                    <div>
                        <strong>Total Pending Adjustments: <span id="pendingAdjustmentsCount">0</span></strong>
                    </div>
                    <button type="button" class="btn btn-success btn-lg" id="submitAllRequestsBtn">
                        <i class="fas fa-paper-plane me-2"></i>Submit All Requests
                    </button>
                </div>
            </div>
        </div>

        <!-- Pending Adjustment Requests (Already Submitted) -->
        <?php if (!empty($pending_requests)): ?>
        <div class="card mb-4">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0"><i class="fas fa-clock me-2"></i>Pending Adjustment Requests</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Subject</th>
                                <th>Units</th>
                                <th>Schedule</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_requests as $req): ?>
                                <?php
                                $days = [];
                                if (!empty($req['schedule_monday'])) $days[] = 'Mon';
                                if (!empty($req['schedule_tuesday'])) $days[] = 'Tue';
                                if (!empty($req['schedule_wednesday'])) $days[] = 'Wed';
                                if (!empty($req['schedule_thursday'])) $days[] = 'Thu';
                                if (!empty($req['schedule_friday'])) $days[] = 'Fri';
                                if (!empty($req['schedule_saturday'])) $days[] = 'Sat';
                                if (!empty($req['schedule_sunday'])) $days[] = 'Sun';
                                $schedule_days = implode(', ', $days);
                                
                                $status_labels = [
                                    'pending_program_head' => 'Pending Program Head Review',
                                    'pending_dean' => 'Pending Dean Review',
                                    'pending_registrar' => 'Pending Registrar Review'
                                ];
                                $type_labels = [
                                    'add' => 'Addition',
                                    'remove' => 'Removal',
                                    'schedule_change' => 'Schedule Change'
                                ];
                                ?>
                                <tr>
                                    <td>
                                        <span class="badge <?php 
                                            echo $req['change_type'] === 'add' ? 'bg-success' : 
                                                ($req['change_type'] === 'remove' ? 'bg-danger' : 'bg-warning'); 
                                        ?>">
                                            <?php echo $type_labels[$req['change_type']] ?? ucfirst($req['change_type']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($req['course_code']); ?></strong><br>
                                        <small class="text-muted"><?php echo htmlspecialchars($req['course_name']); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars((string)$req['units']); ?></td>
                                    <td>
                                        <?php if ($req['change_type'] === 'schedule_change'): ?>
                                            <div>
                                                <small class="text-muted">From:</small><br>
                                                <strong><?php echo htmlspecialchars($req['old_section_name'] ?? 'N/A'); ?></strong><br>
                                                <small><?php echo htmlspecialchars(($req['old_time_start'] ?? '') . ' - ' . ($req['old_time_end'] ?? '')); ?></small>
                                            </div>
                                            <div class="mt-2">
                                                <small class="text-muted">To:</small><br>
                                                <strong><?php echo htmlspecialchars($req['section_name'] ?? 'N/A'); ?></strong><br>
                                                <small><?php echo htmlspecialchars($schedule_days . ' | ' . ($req['time_start'] ?? '') . ' - ' . ($req['time_end'] ?? '')); ?></small>
                                            </div>
                                        <?php elseif ($req['change_type'] === 'add'): ?>
                                            <strong><?php echo htmlspecialchars($req['section_name'] ?? 'N/A'); ?></strong><br>
                                            <small><?php echo htmlspecialchars($schedule_days . ' | ' . ($req['time_start'] ?? '') . ' - ' . ($req['time_end'] ?? '')); ?></small>
                                        <?php else: ?>
                                            <strong><?php echo htmlspecialchars($req['section_name'] ?? 'N/A'); ?></strong><br>
                                            <small><?php echo htmlspecialchars($schedule_days . ' | ' . ($req['time_start'] ?? '') . ' - ' . ($req['time_end'] ?? '')); ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-warning">
                                            <?php echo $status_labels[$req['status']] ?? 'Pending Review'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($req['change_type'] === 'add' && ($is_overload || $is_excess_overload)): ?>
                                            <button class="btn btn-sm btn-danger cancel-request-btn" 
                                                    data-request-id="<?php echo (int)$req['id']; ?>"
                                                    data-course-code="<?php echo htmlspecialchars($req['course_code']); ?>">
                                                <i class="fas fa-times"></i> Cancel
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-outline-danger cancel-request-btn" 
                                                    data-request-id="<?php echo (int)$req['id']; ?>"
                                                    data-course-code="<?php echo htmlspecialchars($req['course_code']); ?>">
                                                <i class="fas fa-times"></i> Cancel
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($is_overload || $is_excess_overload): ?>
                    <div class="alert alert-info mt-3 mb-0">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Tip:</strong> You can cancel pending addition requests above to reduce your total units and avoid overload.
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Current Enrolled Subjects -->
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>Current Enrolled Subjects</h5>
            </div>
            <div class="card-body">
                <?php if (empty($current_schedules)): ?>
                    <p class="text-muted">No enrolled subjects found.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Course Code</th>
                                    <th>Course Name</th>
                                    <th>Units</th>
                                    <th>Schedule</th>
                                    <th>Room</th>
                                    <th>Professor</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($current_schedules as $schedule): ?>
                                    <?php
                                    $days = [];
                                    if (!empty($schedule['schedule_monday'])) $days[] = 'Mon';
                                    if (!empty($schedule['schedule_tuesday'])) $days[] = 'Tue';
                                    if (!empty($schedule['schedule_wednesday'])) $days[] = 'Wed';
                                    if (!empty($schedule['schedule_thursday'])) $days[] = 'Thu';
                                    if (!empty($schedule['schedule_friday'])) $days[] = 'Fri';
                                    if (!empty($schedule['schedule_saturday'])) $days[] = 'Sat';
                                    if (!empty($schedule['schedule_sunday'])) $days[] = 'Sun';
                                    $schedule_days = implode(', ', $days);
                                    $has_pending_request = isset($pending_requests_map[$schedule['curriculum_id']]);
                                    $pending_request = $has_pending_request ? $pending_requests_map[$schedule['curriculum_id']] : null;
                                    ?>
                                    <tr class="<?php echo $has_pending_request ? 'table-secondary' : ''; ?>">
                                        <td><?php echo htmlspecialchars($schedule['course_code']); ?></td>
                                        <td><?php echo htmlspecialchars($schedule['course_name']); ?></td>
                                        <td><?php echo htmlspecialchars((string)$schedule['units']); ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($schedule_days); ?><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($schedule['time_start'] . ' - ' . $schedule['time_end']); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($schedule['room'] ?? 'TBA'); ?></td>
                                        <td><?php echo htmlspecialchars($schedule['professor_name'] ?? 'TBA'); ?></td>
                                        <td>
                                            <?php if ($has_pending_request): ?>
                                                <span class="badge bg-warning mb-2 d-block">
                                                    <i class="fas fa-clock me-1"></i>
                                                    Pending <?php 
                                                    $status_labels = [
                                                        'pending_program_head' => 'Program Head',
                                                        'pending_dean' => 'Dean',
                                                        'pending_registrar' => 'Registrar'
                                                    ];
                                                    echo $status_labels[$pending_request['status']] ?? 'Review';
                                                    ?>
                                                </span>
                                                <small class="text-muted">Request in progress</small>
                                            <?php elseif (!empty($schedule['section_schedule_id']) && !empty($schedule['curriculum_id'])): ?>
                                            <button class="btn btn-sm btn-warning change-schedule-btn" 
                                                    data-schedule-id="<?php echo (int)$schedule['section_schedule_id']; ?>"
                                                    data-student-schedule-id="<?php echo (int)$schedule['student_schedule_id']; ?>"
                                                    data-curriculum-id="<?php echo (int)$schedule['curriculum_id']; ?>"
                                                    data-course-code="<?php echo htmlspecialchars($schedule['course_code']); ?>">
                                                <i class="fas fa-exchange-alt"></i> Change Schedule
                                            </button>
                                            <button class="btn btn-sm btn-danger remove-subject-btn" 
                                                    data-schedule-id="<?php echo (int)$schedule['section_schedule_id']; ?>"
                                                    data-curriculum-id="<?php echo (int)$schedule['curriculum_id']; ?>"
                                                    data-course-code="<?php echo htmlspecialchars($schedule['course_code']); ?>">
                                                <i class="fas fa-trash"></i> Remove
                                            </button>
                                            <?php else: ?>
                                            <span class="text-muted">N/A</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Add Subjects -->
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Add Subjects</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info mb-3">
                    <i class="fas fa-info-circle me-2"></i>
                    <strong>Note:</strong> You can add any subject from your program. Subjects with unmet prerequisites will show warnings but can still be added. All changes will be reviewed by your Program Head, then Dean, before final approval.
                </div>
                
                <?php 
                // Combine current year subjects and backload subjects
                $all_addable_subjects = array_merge($current_year_subjects, $backload_subjects);
                // Remove duplicates based on curriculum ID
                $unique_subjects = [];
                foreach ($all_addable_subjects as $subject) {
                    $unique_subjects[$subject['id']] = $subject;
                }
                $all_addable_subjects = array_values($unique_subjects);
                ?>
                
                <?php if (!empty($all_addable_subjects)): ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>Course Code</th>
                                    <th>Course Name</th>
                                    <th>Units</th>
                                    <th>Year Level</th>
                                    <th>Prerequisites</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($all_addable_subjects as $subject): ?>
                                    <?php
                                    $prereq_check = checkPrerequisitesForSubject($conn, $user_id, (int)$subject['id']);
                                    $has_unmet_prereqs = !$prereq_check['met'];
                                    $is_backload = in_array($subject['id'], array_column($backload_subjects, 'id'));
                                    $has_pending_request = isset($pending_requests_map[$subject['id']]);
                                    $pending_request = $has_pending_request ? $pending_requests_map[$subject['id']] : null;
                                    ?>
                                    <tr class="subject-row <?php echo $has_unmet_prereqs ? 'table-warning' : ($has_pending_request ? 'table-secondary' : ''); ?>" 
                                        data-curriculum-id="<?php echo (int)$subject['id']; ?>"
                                        data-course-code="<?php echo htmlspecialchars($subject['course_code']); ?>">
                                        <td>
                                            <strong><?php echo htmlspecialchars($subject['course_code']); ?></strong>
                                            <?php if ($is_backload): ?>
                                                <span class="badge bg-info ms-1"><i class="fas fa-history"></i> Backload</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($subject['course_name']); ?></td>
                                        <td><?php echo htmlspecialchars((string)$subject['units']); ?></td>
                                        <td><?php echo htmlspecialchars($subject['year_level']); ?></td>
                                        <td>
                                            <?php if ($has_unmet_prereqs): ?>
                                                <span class="badge bg-warning" title="<?php echo htmlspecialchars(implode(', ', array_column($prereq_check['unmet_prerequisites'], 'code'))); ?>">
                                                    <i class="fas fa-exclamation-triangle me-1"></i>
                                                    <?php echo count($prereq_check['unmet_prerequisites']); ?> unmet
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-success">Met</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($has_pending_request): ?>
                                                <span class="badge bg-warning">
                                                    <i class="fas fa-clock me-1"></i>
                                                    Pending <?php 
                                                    $status_labels = [
                                                        'pending_program_head' => 'Program Head',
                                                        'pending_dean' => 'Dean',
                                                        'pending_registrar' => 'Registrar'
                                                    ];
                                                    echo $status_labels[$pending_request['status']] ?? 'Review';
                                                    ?>
                                                </span>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-primary add-subject-btn" 
                                                        data-curriculum-id="<?php echo $subject['id']; ?>"
                                                        data-course-code="<?php echo htmlspecialchars($subject['course_code']); ?>"
                                                        data-has-unmet-prereqs="<?php echo $has_unmet_prereqs ? '1' : '0'; ?>">
                                                    <i class="fas fa-plus"></i> Add
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted">No subjects available to add at this time.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modals -->
    <!-- Add Subject Modal -->
    <div class="modal fade" id="addSubjectModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Select Schedule for <span id="addSubjectCourseCode"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="addSubjectSchedules"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Change Schedule Modal -->
    <div class="modal fade" id="changeScheduleModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Change Schedule for <span id="changeScheduleCourseCode"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="changeScheduleOptions"></div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const academicYear = '<?php echo htmlspecialchars($target_academic_year); ?>';
        const semester = '<?php echo htmlspecialchars($target_semester); ?>';

        // Add subject button handler
        document.querySelectorAll('.add-subject-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const curriculumId = this.dataset.curriculumId;
                const courseCode = this.dataset.courseCode;
                const hasUnmetPrereqs = this.dataset.hasUnmetPrereqs === '1';
                document.getElementById('addSubjectCourseCode').textContent = courseCode;
                
                if (hasUnmetPrereqs && !confirm('This subject has unmet prerequisites. Do you want to continue?')) {
                    return;
                }
                
                // Fetch available schedules
                fetch(`process_adjustment.php?action=get_schedules&curriculum_id=${curriculumId}&academic_year=${encodeURIComponent(academicYear)}&semester=${encodeURIComponent(semester)}`)
                    .then(async response => {
                        const responseText = await response.text();
                        let data;
                        try {
                            data = JSON.parse(responseText);
                        } catch (e) {
                            console.error('JSON Parse Error:', e);
                            console.error('Response Text:', responseText);
                            return {
                                success: false,
                                message: 'Invalid response from server. Please check the console for details.'
                            };
                        }
                        return data;
                    })
                    .then(data => {
                        if (data.success && data.schedules && data.schedules.length > 0) {
                            let html = '<div class="list-group">';
                            data.schedules.forEach(schedule => {
                                const days = [];
                                if (schedule.schedule_monday) days.push('Mon');
                                if (schedule.schedule_tuesday) days.push('Tue');
                                if (schedule.schedule_wednesday) days.push('Wed');
                                if (schedule.schedule_thursday) days.push('Thu');
                                if (schedule.schedule_friday) days.push('Fri');
                                if (schedule.schedule_saturday) days.push('Sat');
                                if (schedule.schedule_sunday) days.push('Sun');
                                
                                html += `<div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong>${schedule.section_name}</strong><br>
                                            <small>${days.join(', ')} | ${schedule.time_start} - ${schedule.time_end}</small><br>
                                            <small class="text-muted">Room: ${schedule.room || 'TBA'} | Professor: ${schedule.professor_name || 'TBA'}</small>
                                        </div>
                                        <button type="button" class="btn btn-primary btn-sm select-schedule-btn" 
                                                data-schedule-id="${schedule.id}"
                                                data-curriculum-id="${curriculumId}">
                                            Select
                                        </button>
                                    </div>
                                </div>`;
                            });
                            html += '</div>';
                            document.getElementById('addSubjectSchedules').innerHTML = html;
                            
                            // Add event listeners to select buttons
                            document.querySelectorAll('.select-schedule-btn').forEach(selectBtn => {
                                selectBtn.addEventListener('click', function(event) {
                                    event.preventDefault();
                                    event.stopPropagation();
                                    
                                    const scheduleId = this.dataset.scheduleId;
                                    const currId = this.dataset.curriculumId;
                                    
                                    if (!scheduleId || !currId) {
                                        alert('Error: Missing schedule information. Please try again.');
                                        return;
                                    }
                                    
                                    // Disable button to prevent double-clicking
                                    this.disabled = true;
                                    this.textContent = 'Processing...';
                                    
                                    // Get schedule details from the list item
                                    const listItem = this.closest('.list-group-item');
                                    const sectionName = listItem.querySelector('strong').textContent;
                                    const scheduleText = listItem.querySelector('small').textContent;
                                    const [days, time] = scheduleText.split(' | ');
                                    
                                    // Fetch curriculum details and add to pending
                                    fetch(`process_adjustment.php?action=get_curriculum_info&curriculum_id=${currId}`)
                                        .then(async response => {
                                            const responseText = await response.text();
                                            let data;
                                            try {
                                                data = JSON.parse(responseText);
                                            } catch (e) {
                                                console.error('JSON Parse Error:', e);
                                                console.error('Response Text:', responseText);
                                                return {
                                                    success: false,
                                                    message: 'Invalid response from server.'
                                                };
                                            }
                                            return data;
                                        })
                                        .then(data => {
                                            if (data.success) {
                                                const adjustment = {
                                                    change_type: 'add',
                                                    curriculum_id: parseInt(currId),
                                                    new_section_schedule_id: parseInt(scheduleId),
                                                    course_code: data.course_code,
                                                    course_name: data.course_name,
                                                    units: data.units,
                                                    section_name: sectionName,
                                                    schedule_days: days ? days.trim() : '',
                                                    time_start: time ? time.split(' - ')[0].trim() : '',
                                                    time_end: time ? time.split(' - ')[1].trim() : ''
                                                };
                                                
                                                if (addPendingAdjustment(adjustment)) {
                                                    alert(`${data.course_code} added to pending adjustments. Click "Submit All Requests" when ready.`);
                                                    bootstrap.Modal.getInstance(document.getElementById('addSubjectModal')).hide();
                                                } else {
                                                    alert('This adjustment is already in your pending list.');
                                                    this.disabled = false;
                                                    this.textContent = 'Select';
                                                }
                                            } else {
                                                alert('Error: ' + (data.message || 'Failed to get subject details.'));
                                                this.disabled = false;
                                                this.textContent = 'Select';
                                            }
                                        })
                                        .catch(error => {
                                            console.error('Error:', error);
                                            alert('Error: Failed to add subject. Please check your connection and try again.');
                                            this.disabled = false;
                                            this.textContent = 'Select';
                                        });
                                });
                            });
                            
                            new bootstrap.Modal(document.getElementById('addSubjectModal')).show();
                        } else {
                            alert(data.message || 'No available schedules found for this subject.');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Error fetching schedules. Please check your connection and try again.');
                    });
            });
        });

        // Change schedule button handler
        document.querySelectorAll('.change-schedule-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const scheduleId = this.dataset.scheduleId;
                const curriculumId = this.dataset.curriculumId;
                const courseCode = this.dataset.courseCode;
                
                if (!scheduleId || !curriculumId) {
                    alert('Error: Missing schedule information. Please refresh the page and try again.');
                    return;
                }
                
                document.getElementById('changeScheduleCourseCode').textContent = courseCode;
                
                // Fetch available schedules (excluding current)
                fetch(`process_adjustment.php?action=get_schedules&curriculum_id=${curriculumId}&academic_year=${encodeURIComponent(academicYear)}&semester=${encodeURIComponent(semester)}&exclude_schedule_id=${scheduleId}`)
                    .then(async response => {
                        const responseText = await response.text();
                        let data;
                        try {
                            data = JSON.parse(responseText);
                        } catch (e) {
                            console.error('JSON Parse Error:', e);
                            console.error('Response Text:', responseText);
                            return {
                                success: false,
                                message: 'Invalid response from server. Please check the console for details.'
                            };
                        }
                        return data;
                    })
                    .then(data => {
                        if (data.success && data.schedules && data.schedules.length > 0) {
                            let html = '<div class="list-group">';
                            data.schedules.forEach(schedule => {
                                const days = [];
                                if (schedule.schedule_monday) days.push('Mon');
                                if (schedule.schedule_tuesday) days.push('Tue');
                                if (schedule.schedule_wednesday) days.push('Wed');
                                if (schedule.schedule_thursday) days.push('Thu');
                                if (schedule.schedule_friday) days.push('Fri');
                                if (schedule.schedule_saturday) days.push('Sat');
                                if (schedule.schedule_sunday) days.push('Sun');
                                
                                html += `<div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong>${schedule.section_name}</strong><br>
                                            <small>${days.join(', ')} | ${schedule.time_start} - ${schedule.time_end}</small><br>
                                            <small class="text-muted">Room: ${schedule.room || 'TBA'} | Professor: ${schedule.professor_name || 'TBA'}</small>
                                        </div>
                                        <button type="button" class="btn btn-primary btn-sm select-change-schedule-btn" 
                                                data-old-schedule-id="${scheduleId}"
                                                data-new-schedule-id="${schedule.id}">
                                            Select
                                        </button>
                                    </div>
                                </div>`;
                            });
                            html += '</div>';
                            document.getElementById('changeScheduleOptions').innerHTML = html;
                            
                            // Add event listeners
                            document.querySelectorAll('.select-change-schedule-btn').forEach(selectBtn => {
                                selectBtn.addEventListener('click', function(event) {
                                    event.preventDefault();
                                    event.stopPropagation();
                                    
                                    const oldScheduleId = this.dataset.oldScheduleId;
                                    const newScheduleId = this.dataset.newScheduleId;
                                    
                                    if (!oldScheduleId || !newScheduleId) {
                                        alert('Error: Missing schedule information. Please try again.');
                                        return;
                                    }
                                    
                                    // Disable button to prevent double-clicking
                                    this.disabled = true;
                                    this.textContent = 'Processing...';
                                    
                                    // Get schedule details
                                    const listItem = this.closest('.list-group-item');
                                    const newSectionName = listItem.querySelector('strong').textContent;
                                    const scheduleText = listItem.querySelector('small').textContent;
                                    const [days, time] = scheduleText.split(' | ');
                                    
                                    // Get old schedule details and curriculum info
                                    fetch(`process_adjustment.php?action=get_schedule_info&schedule_id=${oldScheduleId}`)
                                        .then(async response => {
                                            const responseText = await response.text();
                                            let data;
                                            try {
                                                data = JSON.parse(responseText);
                                            } catch (e) {
                                                console.error('JSON Parse Error:', e);
                                                console.error('Response Text:', responseText);
                                                return {
                                                    success: false,
                                                    message: 'Invalid response from server.'
                                                };
                                            }
                                            return data;
                                        })
                                        .then(data => {
                                            if (data.success) {
                                                const adjustment = {
                                                    change_type: 'schedule_change',
                                                    curriculum_id: parseInt(data.curriculum_id),
                                                    old_section_schedule_id: parseInt(oldScheduleId),
                                                    new_section_schedule_id: parseInt(newScheduleId),
                                                    course_code: data.course_code,
                                                    course_name: data.course_name,
                                                    units: data.units,
                                                    old_section_name: data.old_section_name,
                                                    old_schedule_days: data.old_schedule_days || '',
                                                    old_time_start: data.old_time_start || '',
                                                    old_time_end: data.old_time_end || '',
                                                    section_name: newSectionName,
                                                    schedule_days: days ? days.trim() : '',
                                                    time_start: time ? time.split(' - ')[0].trim() : '',
                                                    time_end: time ? time.split(' - ')[1].trim() : ''
                                                };
                                                
                                                if (addPendingAdjustment(adjustment)) {
                                                    alert(`${data.course_code} schedule change added to pending adjustments. Click "Submit All Requests" when ready.`);
                                                    bootstrap.Modal.getInstance(document.getElementById('changeScheduleModal')).hide();
                                                } else {
                                                    alert('This adjustment is already in your pending list.');
                                                    this.disabled = false;
                                                    this.textContent = 'Select';
                                                }
                                            } else {
                                                alert('Error: ' + (data.message || 'Failed to get schedule details.'));
                                                this.disabled = false;
                                                this.textContent = 'Select';
                                            }
                                        })
                                        .catch(error => {
                                            console.error('Error:', error);
                                            alert('Error: Failed to change schedule. Please check your connection and try again.');
                                            this.disabled = false;
                                            this.textContent = 'Select';
                                        });
                                });
                            });
                            
                            new bootstrap.Modal(document.getElementById('changeScheduleModal')).show();
                        } else {
                            alert('No alternative schedules available for this subject.');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Error fetching schedules.');
                    });
            });
        });

        // Remove subject button handler
        document.querySelectorAll('.remove-subject-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const scheduleId = this.dataset.scheduleId;
                const curriculumId = this.dataset.curriculumId;
                const courseCode = this.dataset.courseCode;
                
                if (!confirm(`Add ${courseCode} removal to pending adjustments?`)) {
                    return;
                }
                
                // Get schedule and curriculum details
                fetch(`process_adjustment.php?action=get_schedule_info&schedule_id=${scheduleId}`)
                    .then(async response => {
                        const responseText = await response.text();
                        let data;
                        try {
                            data = JSON.parse(responseText);
                        } catch (e) {
                            console.error('JSON Parse Error:', e);
                            console.error('Response Text:', responseText);
                            return {
                                success: false,
                                message: 'Invalid response from server.'
                            };
                        }
                        return data;
                    })
                    .then(data => {
                        if (data.success) {
                            const adjustment = {
                                change_type: 'remove',
                                curriculum_id: parseInt(data.curriculum_id),
                                old_section_schedule_id: parseInt(scheduleId),
                                course_code: data.course_code,
                                course_name: data.course_name,
                                units: data.units,
                                old_section_name: data.old_section_name || '',
                                old_schedule_days: data.old_schedule_days || '',
                                old_time_start: data.old_time_start || '',
                                old_time_end: data.old_time_end || ''
                            };
                            
                            if (addPendingAdjustment(adjustment)) {
                                alert(`${data.course_code} removal added to pending adjustments. Click "Submit All Requests" when ready.`);
                            } else {
                                alert('This adjustment is already in your pending list.');
                            }
                        } else {
                            alert('Error: ' + (data.message || 'Failed to get schedule details.'));
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Error: Failed to add removal. Please check your connection and try again.');
                    });
            });
        });

        // Cancel request button handler
        document.querySelectorAll('.cancel-request-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const requestId = this.dataset.requestId;
                const courseCode = this.dataset.courseCode;
                
                if (!confirm(`Are you sure you want to cancel the pending request for ${courseCode}?`)) {
                    return;
                }
                
                // Disable button to prevent double-clicking
                this.disabled = true;
                this.textContent = 'Cancelling...';
                
                fetch('process_adjustment.php', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: `action=cancel_request&request_id=${requestId}`
                })
                .then(async response => {
                    const responseText = await response.text();
                    let result;
                    try {
                        result = JSON.parse(responseText);
                    } catch (e) {
                        console.error('JSON Parse Error:', e);
                        console.error('Response Text:', responseText);
                        return {
                            success: false,
                            message: 'Invalid response from server. Please check the console for details.'
                        };
                    }
                    return result;
                })
                .then(result => {
                    if (result.success) {
                        alert(result.message);
                        location.reload();
                    } else {
                        alert('Error: ' + (result.message || 'Failed to cancel request. Please try again.'));
                        this.disabled = false;
                        this.textContent = 'Cancel';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error: Failed to cancel request. Please check your connection and try again.');
                    this.disabled = false;
                    this.textContent = 'Cancel';
                });
                });
        });

        // Pending Adjustments Management (localStorage)
        const PENDING_ADJUSTMENTS_KEY = 'pending_adjustments';
        
        function getPendingAdjustments() {
            const stored = localStorage.getItem(PENDING_ADJUSTMENTS_KEY);
            return stored ? JSON.parse(stored) : [];
        }
        
        function savePendingAdjustments(adjustments) {
            localStorage.setItem(PENDING_ADJUSTMENTS_KEY, JSON.stringify(adjustments));
            updatePendingAdjustmentsDisplay();
        }
        
        function addPendingAdjustment(adjustment) {
            const adjustments = getPendingAdjustments();
            // Check for duplicates (same type and curriculum_id)
            const exists = adjustments.find(a => 
                a.change_type === adjustment.change_type && 
                a.curriculum_id === adjustment.curriculum_id &&
                (adjustment.change_type !== 'schedule_change' || a.old_section_schedule_id === adjustment.old_section_schedule_id)
            );
            if (!exists) {
                adjustments.push(adjustment);
                savePendingAdjustments(adjustments);
                // Hide the subject row immediately
                if (adjustment.change_type === 'add' && adjustment.curriculum_id) {
                    const subjectRow = document.querySelector(`.subject-row[data-curriculum-id="${adjustment.curriculum_id}"]`);
                    if (subjectRow) {
                        subjectRow.style.display = 'none';
                    }
                }
                return true;
            }
            return false;
        }
        
        function removePendingAdjustment(index) {
            const adjustments = getPendingAdjustments();
            const removed = adjustments[index];
            adjustments.splice(index, 1);
            savePendingAdjustments(adjustments);
            // Show the subject row again if it was an 'add' adjustment
            if (removed && removed.change_type === 'add' && removed.curriculum_id) {
                const subjectRow = document.querySelector(`.subject-row[data-curriculum-id="${removed.curriculum_id}"]`);
                if (subjectRow) {
                    subjectRow.style.display = '';
                }
            }
        }
        
        function clearPendingAdjustments() {
            localStorage.removeItem(PENDING_ADJUSTMENTS_KEY);
            updatePendingAdjustmentsDisplay();
        }
        
        function updatePendingAdjustmentsDisplay() {
            const adjustments = getPendingAdjustments();
            const tbody = document.getElementById('pendingAdjustmentsBody');
            const card = document.getElementById('pendingAdjustmentsCard');
            const countSpan = document.getElementById('pendingAdjustmentsCount');
            
            // Get curriculum IDs from pending adjustments
            const pendingCurriculumIds = adjustments
                .filter(adj => adj.curriculum_id)
                .map(adj => parseInt(adj.curriculum_id));
            
            // Hide/show subject rows based on pending adjustments
            document.querySelectorAll('.subject-row').forEach(row => {
                const curriculumId = parseInt(row.dataset.curriculumId);
                if (pendingCurriculumIds.includes(curriculumId)) {
                    row.style.display = 'none';
                } else {
                    row.style.display = '';
                }
            });
            
            if (adjustments.length === 0) {
                card.style.display = 'none';
                updateUnitsSummary();
                return;
            }
            
            card.style.display = 'block';
            countSpan.textContent = adjustments.length;
            
            tbody.innerHTML = adjustments.map((adj, index) => {
                const typeBadge = adj.change_type === 'add' ? 'bg-success' : 
                                 adj.change_type === 'remove' ? 'bg-danger' : 'bg-warning';
                const typeLabel = adj.change_type === 'add' ? 'Addition' : 
                                 adj.change_type === 'remove' ? 'Removal' : 'Schedule Change';
                
                let scheduleInfo = '';
                if (adj.change_type === 'add') {
                    scheduleInfo = `<strong>${adj.section_name || 'N/A'}</strong><br>
                                    <small>${adj.schedule_days || ''} | ${adj.time_start || ''} - ${adj.time_end || ''}</small>`;
                } else if (adj.change_type === 'remove') {
                    scheduleInfo = `<strong>${adj.old_section_name || 'N/A'}</strong><br>
                                    <small>${adj.old_schedule_days || ''} | ${adj.old_time_start || ''} - ${adj.old_time_end || ''}</small>`;
                } else {
                    scheduleInfo = `<div><small class="text-muted">From:</small><br>
                                    <strong>${adj.old_section_name || 'N/A'}</strong><br>
                                    <small>${adj.old_schedule_days || ''} | ${adj.old_time_start || ''} - ${adj.old_time_end || ''}</small></div>
                                    <div class="mt-2"><small class="text-muted">To:</small><br>
                                    <strong>${adj.section_name || 'N/A'}</strong><br>
                                    <small>${adj.schedule_days || ''} | ${adj.time_start || ''} - ${adj.time_end || ''}</small></div>`;
                }
                
                return `<tr>
                    <td><span class="badge ${typeBadge}">${typeLabel}</span></td>
                    <td>
                        <strong>${adj.course_code || 'N/A'}</strong><br>
                        <small class="text-muted">${adj.course_name || ''}</small>
                    </td>
                    <td>${adj.units || 0}</td>
                    <td>${scheduleInfo}</td>
                    <td>
                        <button type="button" class="btn btn-sm btn-outline-danger remove-pending-btn" data-index="${index}">
                            <i class="fas fa-times"></i> Remove
                        </button>
                    </td>
                </tr>`;
            }).join('');
            
            // Add event listeners to remove buttons
            document.querySelectorAll('.remove-pending-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const index = parseInt(this.dataset.index);
                    if (confirm('Remove this adjustment from your pending list?')) {
                        removePendingAdjustment(index);
                    }
                });
            });
            
            updateUnitsSummary();
        }
        
        function updateUnitsSummary() {
            const adjustments = getPendingAdjustments();
            const currentUnits = <?php echo $current_units; ?>;
            
            let pendingAdditions = 0;
            let pendingRemovals = 0;
            
            adjustments.forEach(adj => {
                if (adj.change_type === 'add') {
                    pendingAdditions += parseFloat(adj.units || 0);
                } else if (adj.change_type === 'remove') {
                    pendingRemovals += parseFloat(adj.units || 0);
                }
            });
            
            // Also include already submitted pending requests
            pendingAdditions += <?php echo $pending_additions_units; ?>;
            pendingRemovals += <?php echo $pending_removals_units; ?>;
            
            const totalUnits = currentUnits + pendingAdditions - pendingRemovals;
            // Overload checks disabled for 4th year students
            const is4thYear = <?php echo $is_4th_year ? 'true' : 'false'; ?>;
            const isOverload = is4thYear ? false : (totalUnits >= 27 && totalUnits <= 28);
            const isExcessOverload = is4thYear ? false : (totalUnits > 28);
            
            // Update display if elements exist
            const pendingAddEl = document.querySelector('[data-pending-additions]');
            const pendingRemEl = document.querySelector('[data-pending-removals]');
            const totalEl = document.querySelector('[data-total-units]');
            
            if (pendingAddEl) pendingAddEl.textContent = `+${pendingAdditions}`;
            if (pendingRemEl) pendingRemEl.textContent = `-${pendingRemovals}`;
            if (totalEl) {
                totalEl.textContent = totalUnits;
                totalEl.className = 'mb-0 ' + (isExcessOverload ? 'text-danger' : (isOverload ? 'text-warning' : 'text-primary'));
            }
            
            // Update overload warnings
            const warningDiv = document.querySelector('.alert-danger, .alert-warning');
            if (warningDiv && (isOverload || isExcessOverload)) {
                const warningType = isExcessOverload ? 'danger' : 'warning';
                const warningIcon = isExcessOverload ? 'exclamation-triangle' : 'exclamation-circle';
                const warningTitle = isExcessOverload ? 'Overload Warning' : 'Overload Notice';
                const warningText = isExcessOverload 
                    ? `Your total units (${totalUnits}) exceed the maximum allowed (28 units). Overload requests are not allowed and will not be reviewed. Please remove some pending additions to reduce your total units before adding new subjects.`
                    : `Your total units (${totalUnits}) are in the overload range (27-28 units). Overload requests are not allowed and will not be reviewed. Please remove some pending additions to reduce your total units before adding new subjects.`;
                
                warningDiv.className = `alert alert-${warningType} mt-3 mb-0`;
                warningDiv.innerHTML = `<i class="fas fa-${warningIcon} me-2"></i><strong>${warningTitle}:</strong> ${warningText}`;
            } else if (warningDiv && !isOverload && !isExcessOverload) {
                warningDiv.style.display = 'none';
            }
        }
        
        // Call updateUnitsSummary on page load
        updateUnitsSummary();
        
        // Submit all pending adjustments
        document.getElementById('submitAllRequestsBtn').addEventListener('click', function() {
            const adjustments = getPendingAdjustments();
            if (adjustments.length === 0) {
                alert('No pending adjustments to submit.');
                return;
            }
            
            if (!confirm(`Submit ${adjustments.length} adjustment request(s) for review?`)) {
                return;
            }
            
            this.disabled = true;
            this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Submitting...';
            
            fetch('process_adjustment.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=submit_all&adjustments=${encodeURIComponent(JSON.stringify(adjustments))}`
            })
            .then(async response => {
                const responseText = await response.text();
                let result;
                try {
                    result = JSON.parse(responseText);
                } catch (e) {
                    console.error('JSON Parse Error:', e);
                    console.error('Response Text:', responseText);
                    return {
                        success: false,
                        message: 'Invalid response from server.'
                    };
                }
                return result;
            })
            .then(result => {
                if (result.success) {
                    alert(result.message || 'All requests submitted successfully!');
                    clearPendingAdjustments();
                    location.reload();
                } else {
                    alert('Error: ' + (result.message || 'Failed to submit requests. Please try again.'));
                    this.disabled = false;
                    this.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Submit All Requests';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error: Failed to submit requests. Please check your connection and try again.');
                this.disabled = false;
                this.innerHTML = '<i class="fas fa-paper-plane me-2"></i>Submit All Requests';
            });
        });
        
        // Initialize display on page load
        updatePendingAdjustmentsDisplay();
    </script>
</body>
</html>

