<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../config/student_data_helper.php';
require_once '../classes/User.php';
require_once '../classes/Section.php';

// Check if user is logged in and is a student
if (!isLoggedIn() || isAdmin()) {
    redirect('public/login.php');
}

$user = new User();
$section = new Section();

// Get database connection
$conn = (new Database())->getConnection();

// Use centralized student data helper to get consistent data
$student_data = getStudentData($conn, $_SESSION['user_id'], true);
if (!$student_data) {
    // User not found in database, logout
    session_destroy();
    redirect('public/login.php');
}

// Validate session against users table to prevent session confusion
$user_info = $user->getUserById($_SESSION['user_id']);
if (!$user_info) {
    // User not found in database, logout
    session_destroy();
    redirect('public/login.php');
}

// Update session role if it somehow got corrupted
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'student') {
    $_SESSION['role'] = 'student';
}

// Update session to ensure is_admin flag is set correctly
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== false) {
    $_SESSION['is_admin'] = false;
}

// Ensure student_id is in session - use data from centralized helper
if (empty($_SESSION['student_id']) && !empty($student_data['student_id'])) {
    $_SESSION['student_id'] = $student_data['student_id'];
    
    // If users table doesn't have student_id but enrolled_students does, update users table
    if (empty($user_info['student_id']) && !empty($student_data['student_id'])) {
        $update_query = "UPDATE users SET student_id = :student_id WHERE id = :user_id";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bindParam(':student_id', $student_data['student_id']);
        $update_stmt->bindParam(':user_id', $_SESSION['user_id']);
        $update_stmt->execute();
        // Sync after update
        ensureStudentDataSync($conn, $_SESSION['user_id']);
    }
}

// Get student's current enrolled semester info
// Prefer the latest COR (official enrollment), then fall back to most recent active section,
// then finally to enrolled_students data.
$current_enrollment = null;

// 1) Try to derive current enrollment from the most recent COR.
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
$latest_cor_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
$latest_cor_stmt->execute();
$latest_cor = $latest_cor_stmt->fetch(PDO::FETCH_ASSOC);

if ($latest_cor && !empty($latest_cor['semester'])) {
    $current_enrollment = [
        'academic_year' => $latest_cor['academic_year'],
        'semester' => $latest_cor['semester'],
        'year_level' => $latest_cor['year_level'],
        'section_id' => $latest_cor['section_id'],
    ];
} else {
    // 2) Fallback to most recent active or pending section if no COR exists yet
    // Include pending sections so pending students can see their selected section
    $latest_section_query = "SELECT s.academic_year, s.semester, s.year_level, s.id as section_id
                            FROM section_enrollments se
                            JOIN sections s ON se.section_id = s.id
                            WHERE se.user_id = :user_id
                            AND se.status IN ('active', 'pending')
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
    $latest_section_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
    $latest_section_stmt->execute();
    $latest_section = $latest_section_stmt->fetch(PDO::FETCH_ASSOC);

    if ($latest_section && !empty($latest_section['semester'])) {
        $current_enrollment = [
            'academic_year' => $latest_section['academic_year'],
            'semester' => $latest_section['semester'],
            'year_level' => $latest_section['year_level'],
            'section_id' => $latest_section['section_id'],
        ];
    } elseif ($student_data) {
        // 3) Fallback to enrolled_students data from centralized helper
        $current_enrollment = [
            'academic_year' => $student_data['academic_year'] ?? null,
            'semester' => $student_data['semester'] ?? null,
            'year_level' => $student_data['year_level'] ?? null
        ];
    }
}

// Get student's CURRENT sections (only those matching the current enrollment semester)
// This ensures only the active/current semester sections are shown here.
// Include both 'active' and 'pending' statuses so pending students can see their selected section
$current_sections_query = "SELECT se.id as enrollment_id, se.section_id, se.enrolled_date, 
                           se.status as enrollment_status,
                           s.section_name, s.year_level, s.semester, s.academic_year,
                           s.current_enrolled, s.max_capacity, s.section_type,
                           p.program_code, p.program_name
                           FROM section_enrollments se
                           JOIN sections s ON se.section_id = s.id
                           JOIN programs p ON s.program_id = p.id
                           WHERE se.user_id = :user_id 
                           AND se.status IN ('active', 'pending')";

// Only show sections that match the current enrollment semester (and section, if known)
if ($current_enrollment) {
    $current_sections_query .= " AND s.academic_year = :academic_year 
                                 AND s.semester = :semester";
    if (!empty($current_enrollment['section_id'])) {
        $current_sections_query .= " AND s.id = :section_id";
    }
} else {
    // If no enrolled_students record exists, still show pending sections
    // This handles the case for pending students who selected a section during registration
    // but haven't been approved/enrolled yet
    $current_sections_query .= " AND se.status = 'pending'";
}
$current_sections_query .= " ORDER BY se.enrolled_date DESC";

$current_sections_stmt = $conn->prepare($current_sections_query);
$current_sections_stmt->bindParam(':user_id', $_SESSION['user_id']);
if ($current_enrollment) {
    $current_sections_stmt->bindParam(':academic_year', $current_enrollment['academic_year']);
    $current_sections_stmt->bindParam(':semester', $current_enrollment['semester']);
    if (!empty($current_enrollment['section_id'])) {
        $current_sections_stmt->bindParam(':section_id', $current_enrollment['section_id'], PDO::PARAM_INT);
    }
}
$current_sections_stmt->execute();
$my_sections = $current_sections_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get student's CURRENT schedules from the most recent COR
// Show ONLY subjects that are in the most recent Certificate of Registration
// Get schedule details from student_schedules to display time, room, professor, etc.
$my_schedules = [];

// Get the COR for the current enrollment semester
// Use the same logic as view_cor.php to ensure consistency
// Filter by current enrollment semester to get the correct COR
$cor_query = "SELECT cor.id, cor.subjects_json, cor.academic_year, cor.semester, cor.section_id,
              s.section_name, s.year_level, p.program_code
              FROM certificate_of_registration cor
              JOIN sections s ON cor.section_id = s.id
              JOIN programs p ON cor.program_id = p.id
              WHERE cor.user_id = :user_id";
              
// Filter by current enrollment if available
if ($current_enrollment && !empty($current_enrollment['academic_year']) && !empty($current_enrollment['semester'])) {
    $cor_query .= " AND cor.academic_year = :academic_year
                    AND cor.semester = :semester";
}

// Order by academic progression hierarchy (same as view_cor.php)
// Hierarchy: 1st Year First > 1st Year Second > 2nd Year First > 2nd Year Second > etc.
$cor_query .= " ORDER BY cor.academic_year DESC,
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

$cor_stmt = $conn->prepare($cor_query);
$cor_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
if ($current_enrollment && !empty($current_enrollment['academic_year']) && !empty($current_enrollment['semester'])) {
    $cor_stmt->bindParam(':academic_year', $current_enrollment['academic_year']);
    $cor_stmt->bindParam(':semester', $current_enrollment['semester']);
}
$cor_stmt->execute();
$cor_record = $cor_stmt->fetch(PDO::FETCH_ASSOC);

if ($cor_record && !empty($cor_record['subjects_json'])) {
    $cor_subjects = json_decode($cor_record['subjects_json'], true);
    
    if (is_array($cor_subjects) && !empty($cor_subjects)) {
        // Use COR subjects directly - they contain all schedule information
        // This ensures the dashboard always matches the current COR
        foreach ($cor_subjects as $cor_subject) {
            $course_code = $cor_subject['course_code'] ?? '';
            if (empty($course_code)) {
                continue;
            }
            
            // Get schedule days from COR
            $schedule_days = $cor_subject['section_info']['schedule_days'] ?? [];
            $schedule_info = $cor_subject['section_info'] ?? [];
            
            // Convert schedule_days array to individual day flags
            $schedule_monday = in_array('Monday', $schedule_days) ? 1 : 0;
            $schedule_tuesday = in_array('Tuesday', $schedule_days) ? 1 : 0;
            $schedule_wednesday = in_array('Wednesday', $schedule_days) ? 1 : 0;
            $schedule_thursday = in_array('Thursday', $schedule_days) ? 1 : 0;
            $schedule_friday = in_array('Friday', $schedule_days) ? 1 : 0;
            $schedule_saturday = in_array('Saturday', $schedule_days) ? 1 : 0;
            $schedule_sunday = in_array('Sunday', $schedule_days) ? 1 : 0;
            
            // Build schedule array matching the format expected by the dashboard
            $schedule_item = [
                'course_code' => $course_code,
                'course_name' => $cor_subject['course_name'] ?? '',
                'units' => $cor_subject['units'] ?? 0,
                'schedule_monday' => $schedule_monday,
                'schedule_tuesday' => $schedule_tuesday,
                'schedule_wednesday' => $schedule_wednesday,
                'schedule_thursday' => $schedule_thursday,
                'schedule_friday' => $schedule_friday,
                'schedule_saturday' => $schedule_saturday,
                'schedule_sunday' => $schedule_sunday,
                'time_start' => $schedule_info['time_start'] ?? '',
                'time_end' => $schedule_info['time_end'] ?? '',
                'room' => $schedule_info['room'] ?? 'TBA',
                'professor_name' => $schedule_info['professor_name'] ?? 'TBA',
                'professor_initial' => $schedule_info['professor_initial'] ?? '',
                'section_name' => $schedule_info['section_name'] ?? $cor_record['section_name'] ?? '',
                'year_level' => $cor_subject['year_level'] ?? $cor_record['year_level'] ?? '',
                'semester' => $cor_subject['semester'] ?? $cor_record['semester'] ?? '',
                'academic_year' => $cor_record['academic_year'] ?? '',
                'program_code' => $cor_record['program_code'] ?? ''
            ];
            
            $my_schedules[] = $schedule_item;
        }
        
        // Fallback: If no schedules were built from COR, try student_schedules
        if (empty($my_schedules)) {
        }
        
        // Fallback: If no schedules were built from COR, try student_schedules
        if (empty($my_schedules)) {
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
                                    sts.course_code,
                                    sts.course_name,
                                    sts.units,
                                    sts.schedule_monday,
                                    sts.schedule_tuesday,
                                    sts.schedule_wednesday,
                                    sts.schedule_thursday,
                                    sts.schedule_friday,
                                    sts.schedule_saturday,
                                    sts.schedule_sunday,
                                    sts.time_start,
                                    sts.time_end,
                                    sts.room,
                                    sts.professor_name,
                                    sts.professor_initial,
                                    s.section_name, s.year_level, s.semester, s.academic_year, p.program_code
                                    FROM student_schedules sts
                                    JOIN section_schedules ss ON sts.section_schedule_id = ss.id
                                    JOIN sections s ON ss.section_id = s.id
                                    JOIN programs p ON s.program_id = p.id
                                    WHERE sts.user_id = :user_id 
                                    AND sts.status = 'active'
                                    AND UPPER(TRIM(sts.course_code)) IN ($placeholders)
                                    ORDER BY sts.course_code";
                
                $schedules_stmt = $conn->prepare($schedules_query);
                $schedules_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
                
                // Bind all course codes using named parameters
                foreach ($cor_subject_codes as $idx => $code) {
                    $param_name = ':course_code_' . $idx;
                    $schedules_stmt->bindValue($param_name, $code);
                }
                
                $schedules_stmt->execute();
                $my_schedules = $schedules_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    }
}

// If no COR exists but student has a pending section, show section schedules as fallback
// This allows pending students to see the schedule for their selected section
if (empty($my_schedules) && !empty($my_sections)) {
    $pending_section = null;
    foreach ($my_sections as $sec) {
        if (isset($sec['enrollment_status']) && $sec['enrollment_status'] == 'pending') {
            $pending_section = $sec;
            break;
        }
    }
    
    // If we have a pending section, get its schedules from section_schedules
    if ($pending_section) {
        $pending_schedule_query = "SELECT 
                                   ss.course_code,
                                   ss.course_name,
                                   ss.units,
                                   ss.schedule_monday,
                                   ss.schedule_tuesday,
                                   ss.schedule_wednesday,
                                   ss.schedule_thursday,
                                   ss.schedule_friday,
                                   ss.schedule_saturday,
                                   ss.schedule_sunday,
                                   ss.time_start,
                                   ss.time_end,
                                   ss.room,
                                   ss.professor_name,
                                   ss.professor_initial,
                                   s.section_name, s.year_level, s.semester, s.academic_year, p.program_code
                                   FROM section_schedules ss
                                   JOIN sections s ON ss.section_id = s.id
                                   JOIN programs p ON s.program_id = p.id
                                   WHERE ss.section_id = :section_id
                                   ORDER BY ss.course_code";
        
        $pending_schedule_stmt = $conn->prepare($pending_schedule_query);
        $pending_schedule_stmt->bindParam(':section_id', $pending_section['section_id'], PDO::PARAM_INT);
        $pending_schedule_stmt->execute();
        $my_schedules = $pending_schedule_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

// Get student's PAST sections (ALL sections that DON'T match the current enrollment semester)
// This includes First Year First Semester and all previous semesters as the student progresses
$past_sections_query = "SELECT se.id as enrollment_id, se.section_id, se.enrolled_date, 
                        s.section_name, s.year_level, s.semester, s.academic_year,
                        s.current_enrolled, s.max_capacity, s.section_type,
                        p.program_code, p.program_name
                        FROM section_enrollments se
                        JOIN sections s ON se.section_id = s.id
                        JOIN programs p ON s.program_id = p.id
                        WHERE se.user_id = :user_id 
                        AND se.status = 'active'";

// Show all sections EXCEPT the current enrolled semester (matching semester and academic year)
if ($current_enrollment) {
    // Use NOT condition to exclude current enrollment - everything else is "past"
    $past_sections_query .= " AND NOT (s.academic_year = :academic_year 
                              AND s.semester = :semester)";
}
// Order by most recent first
$past_sections_query .= " ORDER BY s.academic_year DESC, 
                         CASE s.semester 
                             WHEN 'Second Semester' THEN 2 
                             WHEN 'First Semester' THEN 1 
                             ELSE 0 
                         END DESC, 
                         se.enrolled_date DESC";

$past_sections_stmt = $conn->prepare($past_sections_query);
$past_sections_stmt->bindParam(':user_id', $_SESSION['user_id']);
if ($current_enrollment) {
    $past_sections_stmt->bindParam(':academic_year', $current_enrollment['academic_year']);
    $past_sections_stmt->bindParam(':semester', $current_enrollment['semester']);
}
$past_sections_stmt->execute();
$past_sections = $past_sections_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get student's PAST schedules (ALL schedules that DON'T match the current enrolled semester)
// Use student_schedules if available, otherwise fall back to section_schedules
$past_schedules_query = "SELECT 
                         COALESCE(sts.course_code, ss.course_code) as course_code,
                         COALESCE(sts.course_name, ss.course_name) as course_name,
                         COALESCE(sts.units, ss.units) as units,
                         COALESCE(sts.schedule_monday, ss.schedule_monday) as schedule_monday,
                         COALESCE(sts.schedule_tuesday, ss.schedule_tuesday) as schedule_tuesday,
                         COALESCE(sts.schedule_wednesday, ss.schedule_wednesday) as schedule_wednesday,
                         COALESCE(sts.schedule_thursday, ss.schedule_thursday) as schedule_thursday,
                         COALESCE(sts.schedule_friday, ss.schedule_friday) as schedule_friday,
                         COALESCE(sts.schedule_saturday, ss.schedule_saturday) as schedule_saturday,
                         COALESCE(sts.schedule_sunday, ss.schedule_sunday) as schedule_sunday,
                         COALESCE(sts.time_start, ss.time_start) as time_start,
                         COALESCE(sts.time_end, ss.time_end) as time_end,
                         COALESCE(sts.room, ss.room) as room,
                         COALESCE(sts.professor_name, ss.professor_name) as professor_name,
                         COALESCE(sts.professor_initial, ss.professor_initial) as professor_initial,
                         s.section_name, s.year_level, s.semester, s.academic_year, p.program_code
                         FROM section_enrollments se
                         JOIN sections s ON se.section_id = s.id
                         JOIN programs p ON s.program_id = p.id
                         LEFT JOIN section_schedules ss ON ss.section_id = se.section_id
                         LEFT JOIN student_schedules sts ON sts.section_schedule_id = ss.id AND sts.user_id = se.user_id AND sts.status = 'active'
                         JOIN certificate_of_registration cor 
                            ON cor.user_id = se.user_id
                            AND cor.section_id = s.id
                            AND cor.academic_year = s.academic_year
                            AND cor.semester = s.semester
                         WHERE se.user_id = :user_id AND se.status = 'active'";

// Show all schedules EXCEPT the current enrolled semester (matching year level, semester, and academic year)
if ($current_enrollment) {
    // Use NOT condition to exclude current enrollment
    $past_schedules_query .= " AND NOT (s.academic_year = :academic_year 
                               AND s.semester = :semester
                               AND s.year_level = :year_level)";
}
$past_schedules_query .= " AND (sts.id IS NOT NULL OR ss.id IS NOT NULL)
                           ORDER BY s.academic_year DESC, 
                           CASE s.semester 
                               WHEN 'Second Semester' THEN 2 
                               WHEN 'First Semester' THEN 1 
                               ELSE 0 
                           END DESC, 
                           COALESCE(sts.course_code, ss.course_code)";

$past_schedules_stmt = $conn->prepare($past_schedules_query);
$past_schedules_stmt->bindParam(':user_id', $_SESSION['user_id']);
if ($current_enrollment) {
    $past_schedules_stmt->bindParam(':academic_year', $current_enrollment['academic_year']);
    $past_schedules_stmt->bindParam(':semester', $current_enrollment['semester']);
    $past_schedules_stmt->bindParam(':year_level', $current_enrollment['year_level']);
}
$past_schedules_stmt->execute();
$past_schedules = $past_schedules_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get enrollment status from enrolled_students table joined with users
// Prioritize enrolled_students data (actual enrollment semester) over section data for display
// Section data is used for program code, but enrollment semester should reflect when student actually enrolled
$enrolled_query = "SELECT es.*, u.enrollment_status,
                   CASE 
                       WHEN s.program_code IS NOT NULL THEN s.program_code
                       WHEN es.course IS NOT NULL THEN es.course
                       ELSE 'N/A'
                   END as display_program,
                   COALESCE(es.year_level, s.year_level, '1st Year') as year_level,
                   COALESCE(es.semester, s.semester, 'First Semester') as semester,
                   COALESCE(es.academic_year, s.academic_year, 'AY 2024-2025') as academic_year
                   FROM enrolled_students es
                   JOIN users u ON es.user_id = u.id
                   LEFT JOIN (
                       SELECT se.user_id, pr.program_code, s.year_level, s.semester, s.academic_year
                       FROM section_enrollments se
                       JOIN sections s ON se.section_id = s.id
                       JOIN programs pr ON s.program_id = pr.id
                       WHERE se.status = 'active'
                       ORDER BY se.enrolled_date DESC
                       LIMIT 1
                   ) s ON es.user_id = s.user_id
                   WHERE es.user_id = :user_id";
$enrolled_stmt = $conn->prepare($enrolled_query);
$enrolled_stmt->bindParam(':user_id', $_SESSION['user_id']);
$enrolled_stmt->execute();
$enrollment_info = $enrolled_stmt->fetch(PDO::FETCH_ASSOC);

// If no enrolled_students record, get enrollment_status from users table
if (!$enrollment_info) {
    $user_query = "SELECT u.enrollment_status, u.created_at as enrolled_date, 
                   COALESCE(s.program_code, 'N/A') as display_program,
                   NULL as student_type, 
                   COALESCE(s.year_level, '1st Year') as year_level, 
                   COALESCE(s.academic_year, 'AY 2024-2025') as academic_year, 
                   COALESCE(s.semester, 'First Semester') as semester, 
                   NULL as course
                   FROM users u
                   LEFT JOIN (
                       SELECT se.user_id, pr.program_code, s.year_level, s.semester, s.academic_year
                       FROM section_enrollments se
                       JOIN sections s ON se.section_id = s.id
                       JOIN programs pr ON s.program_id = pr.id
                       WHERE se.status = 'active'
                       ORDER BY se.enrolled_date ASC
                       LIMIT 1
                   ) s ON u.id = s.user_id
                   WHERE u.id = :user_id";
    $user_stmt = $conn->prepare($user_query);
    $user_stmt->bindParam(':user_id', $_SESSION['user_id']);
    $user_stmt->execute();
    $enrollment_info = $user_stmt->fetch(PDO::FETCH_ASSOC);
}

// Get document checklist
$checklist_query = "SELECT * FROM document_checklists WHERE user_id = :user_id";
$stmt = (new Database())->getConnection()->prepare($checklist_query);
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$checklist = $stmt->fetch(PDO::FETCH_ASSOC);

// If no checklist exists, create one
if (!$checklist) {
    $create_checklist = "INSERT INTO document_checklists (user_id) VALUES (:user_id)";
    $create_stmt = (new Database())->getConnection()->prepare($create_checklist);
    $create_stmt->bindParam(':user_id', $_SESSION['user_id']);
    $create_stmt->execute();
    
    // Fetch the newly created checklist
    $stmt->execute();
    $checklist = $stmt->fetch(PDO::FETCH_ASSOC);
}


// Check if student is eligible for next enrollment
// Eligible if currently in 1st Year Second Semester or higher
$can_enroll_next = false;
$next_enrollment_info = [];

if ($enrollment_info && isset($enrollment_info['year_level']) && isset($enrollment_info['semester'])) {
    $current_year = $enrollment_info['year_level'];
    $current_semester = $enrollment_info['semester'];
    
    // Helper function to check if semester is "second" (handles multiple formats)
    // Second semester: "Second Semester", "2nd Semester", "Spring"
    // First semester: "First Semester", "1st Semester", "Fall"
    $isSecondSemester = (
        stripos($current_semester, 'Second') !== false || 
        stripos($current_semester, '2nd') !== false ||
        stripos($current_semester, 'Spring') !== false
    );
    $isFirstSemester = (
        stripos($current_semester, 'First') !== false || 
        stripos($current_semester, '1st') !== false ||
        stripos($current_semester, 'Fall') !== false
    );
    
    // Determine if eligible (1st Year Second Semester or higher)
    if ($current_year === '1st Year' && $isSecondSemester) {
        $can_enroll_next = true;
        $next_enrollment_info = ['year_level' => '2nd Year', 'semester' => 'First Semester'];
    } elseif ($current_year === '2nd Year' && $isFirstSemester) {
        $can_enroll_next = true;
        $next_enrollment_info = ['year_level' => '2nd Year', 'semester' => 'Second Semester'];
    } elseif ($current_year === '2nd Year' && $isSecondSemester) {
        $can_enroll_next = true;
        $next_enrollment_info = ['year_level' => '3rd Year', 'semester' => 'First Semester'];
    } elseif ($current_year === '3rd Year' && $isFirstSemester) {
        $can_enroll_next = true;
        $next_enrollment_info = ['year_level' => '3rd Year', 'semester' => 'Second Semester'];
    } elseif ($current_year === '3rd Year' && $isSecondSemester) {
        $can_enroll_next = true;
        $next_enrollment_info = ['year_level' => '4th Year', 'semester' => 'First Semester'];
    } elseif ($current_year === '4th Year' && $isFirstSemester) {
        $can_enroll_next = true;
        $next_enrollment_info = ['year_level' => '4th Year', 'semester' => 'Second Semester'];
    } elseif ($current_year === '4th Year' && $isSecondSemester) {
        $can_enroll_next = true;
        $next_enrollment_info = ['year_level' => '5th Year', 'semester' => 'First Semester'];
    } elseif ($current_year === '5th Year' && $isFirstSemester) {
        $can_enroll_next = true;
        $next_enrollment_info = ['year_level' => '5th Year', 'semester' => 'Second Semester'];
    }
}

// Force-disable next enrollment feature and hide related UI
$can_enroll_next = false;
$next_enrollment_info = [];

// Check for pending enrollment with COR generated
$pending_enrollment_with_cor = null;
$pending_cor_query = "SELECT nse.*, 
                      (SELECT COUNT(*) FROM certificate_of_registration cor WHERE cor.enrollment_id = nse.id) as has_cor
                      FROM next_semester_enrollments nse
                      WHERE nse.user_id = :user_id 
                      AND nse.request_status IN ('pending_registrar', 'pending_admin')
                      AND nse.cor_generated = TRUE
                      ORDER BY nse.created_at DESC
                      LIMIT 1";
$pending_cor_stmt = $conn->prepare($pending_cor_query);
$pending_cor_stmt->bindParam(':user_id', $_SESSION['user_id']);
$pending_cor_stmt->execute();
$pending_enrollment_with_cor = $pending_cor_stmt->fetch(PDO::FETCH_ASSOC);

$message = '';
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .sidebar {
            background: #1e293b;
            min-height: 100vh;
            color: #e2e8f0;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            overflow-y: auto;
            overflow-x: hidden;
            z-index: 1000;
            width: 260px;
            transition: all 0.3s ease;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        }
        @media (min-width: 992px) {
            .sidebar {
                width: 260px;
            }
        }
        @media (max-width: 767px) {
            .sidebar {
                transform: translateX(-100%);
                width: 260px;
            }
            .sidebar.active {
                transform: translateX(0);
            }
        }
        /* Top Navigation Bar */
        .top-nav {
            position: fixed;
            top: 0;
            left: 260px;
            right: 0;
            height: 60px;
            background: #1e293b;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            z-index: 999;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 25px;
            transition: all 0.3s ease;
        }
        @media (max-width: 767px) {
            .top-nav {
                left: 0;
                padding: 0 15px;
            }
        }
        .top-nav-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .top-nav-left h4 {
            margin: 0;
            color: white;
            font-weight: 600;
            font-size: 17px;
        }
        .top-nav-right {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .top-nav-user {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            text-align: right;
        }
        .top-nav-user h6 {
            color: #94a3b8;
            font-size: 10px;
            margin-bottom: 2px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .top-nav-user h5 {
            color: white;
            font-size: 13px;
            margin-bottom: 2px;
            font-weight: 600;
        }
        .top-nav-user small {
            color: #94a3b8;
            font-size: 11px;
        }
        .main-content {
            margin-left: 260px;
            margin-top: 60px;
            transition: all 0.3s ease;
        }
        @media (min-width: 992px) {
            .main-content {
                margin-left: 260px;
                margin-top: 60px;
            }
        }
        @media (max-width: 767px) {
            .main-content {
                margin-left: 0;
                margin-top: 60px;
            }
        }
        .sidebar-logo {
            width: 50px;
            height: 50px;
            object-fit: contain;
            background: white;
            border-radius: 12px;
            padding: 0.5rem;
            margin-bottom: 0;
        }
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .sidebar-header h4 {
            margin: 0;
            color: white;
            font-size: 18px;
            font-weight: 600;
        }
        .sidebar-menu {
            padding: 18px 0;
        }
        .nav-link {
            color: #cbd5e1;
            padding: 10px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
            position: relative;
            font-size: 14px;
        }
        .nav-link i {
            width: 20px;
            font-size: 16px;
        }
        .nav-link:hover {
            background: rgba(255, 255, 255, 0.05);
            color: white;
            border-left-color: #3b82f6;
        }
        .nav-link.active {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
            border-left-color: #3b82f6;
            font-weight: 500;
        }
        .nav-item.has-dropdown > .nav-link {
            position: relative;
        }
        .nav-item.has-dropdown > .nav-link::after {
            content: '\f107';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 20px;
            transition: transform 0.3s ease;
        }
        .nav-item.has-dropdown.active > .nav-link::after {
            transform: rotate(180deg);
        }
        .submenu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            background: rgba(0, 0, 0, 0.2);
        }
        .nav-item.has-dropdown.active > .submenu {
            max-height: 500px;
        }
        .submenu .nav-link {
            padding-left: 45px;
            font-size: 13px;
            padding-top: 7px;
            padding-bottom: 7px;
        }
        .submenu .nav-link:hover {
            background: rgba(255, 255, 255, 0.08);
        }
        /* Custom Scrollbar */
        .sidebar::-webkit-scrollbar {
            width: 6px;
        }
        .sidebar::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
        }
        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 3px;
        }
        .sidebar::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        /* Mobile Menu Toggle */
        .menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: #1e293b;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 8px;
            cursor: pointer;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }
        @media (max-width: 767px) {
            .menu-toggle {
                display: block;
            }
        }
        /* User Info Section */
        .sidebar-user {
            padding: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            margin-top: auto;
        }
        .sidebar-user h6 {
            color: #94a3b8;
            font-size: 12px;
            margin-bottom: 5px;
        }
        .sidebar-user h5 {
            color: white;
            font-size: 14px;
            margin-bottom: 3px;
        }
        .sidebar-user small {
            color: #94a3b8;
            font-size: 11px;
        }
        .card {
            border: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border-radius: 15px;
        }
        .card-body {
            padding: 15px !important;
        }
        .card-header {
            padding: 12px 15px !important;
        }
        .card-header h5 {
            font-size: 14px !important;
            margin: 0 !important;
        }
        .content-section {
            padding: 20px !important;
        }
        .content-section h2 {
            font-size: 1.5rem !important;
            margin-bottom: 15px !important;
        }
        .content-section h3 {
            font-size: 1.25rem !important;
            margin-bottom: 12px !important;
        }
        .row {
            margin-bottom: 15px !important;
        }
        .mb-4 {
            margin-bottom: 15px !important;
        }
        .card-header {
            background: #1e40af;
            color: white;
            border-radius: 15px 15px 0 0 !important;
        }
        .btn-primary, .bg-primary {
            background-color: #1e40af !important;
            border-color: #1e40af !important;
        }
        .btn-primary:hover {
            background-color: #1e3a8a !important;
            border-color: #1e3a8a !important;
        }
        .text-primary {
            color: #1e40af !important;
        }
        .badge.bg-primary {
            background-color: #1e40af !important;
        }
        .bg-info, .btn-info {
            background-color: #1e40af !important;
            border-color: #1e40af !important;
        }
        .btn-info:hover {
            background-color: #1e3a8a !important;
            border-color: #1e3a8a !important;
        }
        .text-info {
            color: #1e40af !important;
        }
        .badge.bg-info {
            background-color: #1e40af !important;
        }
        .enrollment-status-card {
            background: #1e40af;
            color: white;
            padding: 15px;
            border-radius: 15px;
            margin-bottom: 15px;
        }
        .btn-enroll {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            border-radius: 20px;
        }
        .btn-drop {
            background: linear-gradient(135deg, #dc3545 0%, #fd7e14 100%);
            border: none;
            border-radius: 20px;
        }
        .status-badge {
            border-radius: 20px;
            padding: 5px 15px;
        }
        .status-enrolled {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        }
        .status-waitlisted {
            background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);
        }
        .status-pending {
            background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);
        }
        .checklist-item {
            padding: 10px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .checklist-item:last-child {
            border-bottom: none;
        }
        .checklist-icon {
            font-size: 1.2em;
        }
        .enrollment-status-card {
            background: #3b82f6;
            color: white;
            padding: 15px;
            border-radius: 15px;
            margin-bottom: 15px;
        }
        
        /* Compact spacing styles */
        .main-content {
            padding: 1rem !important;
        }
        .content-section h2 {
            margin-bottom: 1rem !important;
            font-size: 1.5rem;
        }
        .content-section h3 {
            margin-bottom: 0.75rem !important;
            font-size: 1.25rem;
        }
        .card {
            margin-bottom: 1rem !important;
        }
        .card-body {
            padding: 1rem !important;
        }
        .card-header {
            padding: 0.75rem 1rem !important;
        }
        .row {
            margin-bottom: 0.75rem !important;
        }
        .row.mb-4, .row.mb-5 {
            margin-bottom: 1rem !important;
        }
        .mb-4, .mb-5 {
            margin-bottom: 1rem !important;
        }
        .mt-4, .mt-5 {
            margin-top: 1rem !important;
        }
        .py-5, .py-4 {
            padding-top: 1.5rem !important;
            padding-bottom: 1.5rem !important;
        }
        .p-4, .p-5 {
            padding: 1rem !important;
        }
        .alert {
            padding: 0.75rem 1rem !important;
            margin-bottom: 1rem !important;
        }
        .btn {
            padding: 0.5rem 1rem !important;
        }
        .table {
            margin-bottom: 0 !important;
        }
        .table td, .table th {
            padding: 0.5rem !important;
        }
        .card-body .fa-3x {
            font-size: 2rem !important;
        }
        .card-body h3 {
            font-size: 1.75rem !important;
            margin-bottom: 0.25rem !important;
        }
        .card-body .mb-3 {
            margin-bottom: 0.75rem !important;
        }
        
        .chatbot-toggle {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background: linear-gradient(135deg,rgb(175, 183, 200) 0%,rgb(15, 16, 17) 100%);
            color: white;
            border: none;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            font-size: 24px;
            cursor: pointer;
            z-index: 1000;
            transition: all 0.3s;
            overflow: hidden;
        }
        .chatbot-toggle-icon {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .chatbot-icon-img {
            width: 36px;
            height: 36px;
            object-fit: cover;
            border-radius: 50%;
            background: white;
            padding: 2px;
        }
        .chatbot-welcome-img {
            width: 80px;
            height: 80px;
            object-fit: cover;
            border-radius: 50%;
            background: white;
            padding: 4px;
        }
        
        .chatbot-toggle:hover {
            transform: scale(1.1);
            box-shadow: 0 6px 16px rgba(0,0,0,0.2);
        }
        
        .chatbot-widget {
            position: fixed;
            bottom: 100px;
            right: 30px;
            width: 380px;
            height: 550px;
            background: white;
            border-radius: 15px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.15);
            z-index: 1001;
            display: flex;
            flex-direction: column;
            animation: slideUp 0.3s;
        }
        
        @keyframes slideUp {
            from {
                transform: translateY(20px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }
        
        .chatbot-header {
            background: linear-gradient(135deg,rgb(24, 25, 28) 0%,rgb(114, 116, 121) 100%);
            color: white;
            padding: 15px;
            border-radius: 15px 15px 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .chatbot-body {
            flex: 1;
            padding: 15px;
            overflow-y: auto;
        }
        
        .chatbot-welcome {
            text-align: left;
        }
        
        .chatbot-footer {
            padding: 15px;
            border-top: 1px solid #ddd;
        }
        
        .chat-message {
            margin-bottom: 15px;
            animation: fadeIn 0.3s;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .message-user {
            text-align: right;
        }
        
        .message-user .message-bubble {
            background: #667eea;
            color: white;
            display: inline-block;
            padding: 10px 15px;
            border-radius: 18px 18px 0 18px;
            max-width: 80%;
            word-wrap: break-word;
        }
        
        .message-bot {
            text-align: left;
        }
        
        .message-bot .message-bubble {
            background: #f1f1f1;
            color: #333;
            display: inline-block;
            padding: 10px 15px;
            border-radius: 18px 18px 18px 0;
            max-width: 80%;
            word-wrap: break-word;
        }
        
        .message-time {
            font-size: 11px;
            color: #999;
            margin-top: 5px;
        }
        
        .category-btn {
            display: inline-block;
            margin: 5px;
            padding: 5px 12px;
            background: #f0f0f0;
            border: 1px solid #ddd;
            border-radius: 15px;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .category-btn:hover {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        .quick-question {
            padding: 8px 12px;
            margin: 5px 0;
            background: #f8f9fa;
            border-left: 3px solid #667eea;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            transition: all 0.2s;
        }
        
        .quick-question:hover {
            background: #e9ecef;
            transform: translateX(5px);
        }
        
        .typing-indicator {
            padding: 10px;
            background: #f1f1f1;
            border-radius: 18px;
            display: inline-block;
        }
        
        .typing-indicator span {
            height: 8px;
            width: 8px;
            background: #999;
            border-radius: 50%;
            display: inline-block;
            margin: 0 2px;
            animation: typing 1.4s infinite;
        }
        
        .typing-indicator span:nth-child(2) {
            animation-delay: 0.2s;
        }
        
        .typing-indicator span:nth-child(3) {
            animation-delay: 0.4s;
        }
        
        @keyframes typing {
            0%, 60%, 100% { transform: translateY(0); }
            30% { transform: translateY(-10px); }
        }
    </style>
</head>
<body>
    <button class="menu-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="sidebar" id="sidebar">
                <div class="sidebar-header">
                    <img src="../public/assets/images/occ_logo.png" alt="One Cainta College Logo" class="sidebar-logo"
                         onerror="this.style.display='none'; document.getElementById('studentSidebarFallback').classList.remove('d-none');">
                    <i id="studentSidebarFallback" class="fas fa-graduation-cap d-none" style="font-size: 24px; color: white;"></i>
                    <h4><?php echo SITE_NAME; ?></h4>
                </div>
                
                <div class="sidebar-menu">
                    <nav class="nav flex-column">
                        <a href="#" class="nav-link active" onclick="showSection('dashboard'); return false;">
                            <i class="fas fa-home"></i>
                            <span>Dashboard</span>
                        </a>
                        
                        <div class="nav-item has-dropdown">
                            <a href="#" class="nav-link" onclick="toggleDropdown(this); return false;">
                                <i class="fas fa-calendar-alt"></i>
                                <span>My Schedule</span>
                            </a>
                            <div class="submenu">
                                <a href="#" class="nav-link" onclick="showSection('schedule-sections'); return false;">
                                    <span>Schedule & Sections</span>
                                </a>
                                <a href="#" class="nav-link" onclick="showSection('enrollment'); return false;">
                                    <span>Enrollment Status</span>
                                </a>
                            </div>
                        </div>
                        
                        <a href="#" class="nav-link" onclick="showSection('past-enrollment'); return false;">
                            <i class="fas fa-history"></i>
                            <span>Past Enrollment</span>
                        </a>
                        
                        <?php if ($user_info['enrollment_status'] == 'enrolled'): ?>
                        <div class="nav-item has-dropdown">
                            <a href="#" class="nav-link" onclick="toggleDropdown(this); return false;">
                                <i class="fas fa-file-alt"></i>
                                <span>Documents</span>
                            </a>
                            <div class="submenu">
                                <a href="#" class="nav-link" onclick="showSection('upload-documents'); return false;">
                                    <span>Document Checklist</span>
                                </a>
                                <a href="view_cor.php" class="nav-link">
                                    <span>My COR</span>
                                </a>
                            </div>
                        </div>
                        <?php else: ?>
                        <a href="#" class="nav-link" onclick="showSection('upload-documents'); return false;">
                            <i class="fas fa-upload"></i>
                            <span>Upload Documents</span>
                        </a>
                        <?php endif; ?>
                        
                        <a href="view_cor.php" class="nav-link">
                            <i class="fas fa-file-pdf"></i>
                            <span>My COR</span>
                        </a>
                        
                        <a href="#" class="nav-link" onclick="showSection('user-management'); return false;">
                            <i class="fas fa-user-edit"></i>
                            <span>User Management</span>
                        </a>
                        
                        <a href="logout.php" class="nav-link">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </nav>
                </div>
                
                <div class="sidebar-user">
                    <h6>Welcome,</h6>
                    <h5><?php echo $_SESSION['first_name'] . ' ' . $_SESSION['last_name']; ?></h5>
                    <small>ID: <?php echo !empty($_SESSION['student_id']) ? $_SESSION['student_id'] : 'Pending Assignment'; ?></small>
                </div>
            </div>
            
            <!-- Top Navigation Bar -->
            <nav class="top-nav">
                <div class="top-nav-left">
                    <h4>Dashboard</h4>
                </div>
                <div class="top-nav-right">
                    <div class="top-nav-user">
                        <h6>Welcome,</h6>
                        <h5><?php echo $_SESSION['first_name'] . ' ' . $_SESSION['last_name']; ?></h5>
                        <small>ID: <?php echo !empty($_SESSION['student_id']) ? $_SESSION['student_id'] : 'Pending Assignment'; ?></small>
                    </div>
                </div>
            </nav>
            
            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 p-3 main-content">
                <?php if ($message): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Dashboard Section -->
                <div id="dashboard" class="content-section">
                    <h2 class="mb-3">Dashboard</h2>
                    
                    <!-- Enrollment Status Card -->
                    <div class="enrollment-status-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h5 class="mb-1"><i class="fas fa-user-graduate me-2"></i>Enrollment Status</h5>
                                <p class="mb-0 small">Your current enrollment status with the institution</p>
                            </div>
                            <div class="text-end">
                                <h3 class="mb-0">
                                    <span class="badge bg-<?php echo $user_info['enrollment_status'] == 'enrolled' ? 'success' : 'warning'; ?> fs-5">
                                        <?php echo strtoupper($user_info['enrollment_status']); ?>
                                    </span>
                                </h3>
                                <?php if ($user_info['enrollment_status'] == 'pending'): ?>
                                    <small>Awaiting admin approval</small>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <div class="card text-center">
                                <div class="card-body">
                                    <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                    <h3 class="text-success"><?php echo $enrollment_info && isset($enrollment_info['enrollment_status']) && $enrollment_info['enrollment_status'] == 'enrolled' ? 1 : 0; ?></h3>
                                    <p class="mb-0">Enrollment Status</p>
                                    <?php if ($enrollment_info && isset($enrollment_info['enrollment_status']) && $enrollment_info['enrollment_status'] == 'enrolled'): ?>
                                        <small class="text-success">Enrolled</small>
                                    <?php else: ?>
                                        <small class="text-muted">Not Enrolled</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4 mb-3">
                            <div class="card text-center">
                                <div class="card-body">
                                    <i class="fas fa-graduation-cap fa-3x text-info mb-3"></i>
                                    <h3 class="text-info"><?php echo $enrollment_info ? htmlspecialchars($enrollment_info['display_program'] ?? 'N/A') : 'N/A'; ?></h3>
                                    <p class="mb-0">Program</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- COR Available Notification -->
                    <?php if ($pending_enrollment_with_cor && $pending_enrollment_with_cor['has_cor'] > 0): ?>
                    <div class="row mb-3">
                        <div class="col-12">
                            <div class="alert alert-success alert-dismissible fade show">
                                <h5 class="alert-heading"><i class="fas fa-file-alt me-2"></i>Certificate of Registration Available!</h5>
                                <p class="mb-2">Your COR has been generated by the Registrar. You can now view and print your Certificate of Registration.</p>
                                <a href="view_cor.php" class="btn btn-success">
                                    <i class="fas fa-file-pdf me-2"></i>View My COR
                                </a>
                                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <!-- Quick Actions for Enrolled Students -->
                    <?php if ($user_info['enrollment_status'] == 'enrolled'): ?>
                    <div class="row mb-3">
                        <div class="col-12">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h5 class="mb-2"><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
                                    <div class="d-flex flex-wrap gap-2">
                                        <a href="view_grades.php" class="btn btn-primary">
                                            <i class="fas fa-chart-line me-2"></i>View My Grades
                                        </a>
                                        <a href="view_cor.php" class="btn btn-info">
                                            <i class="fas fa-file-pdf me-2"></i>View My COR
                                        </a>
                                        <a href="enroll_next_semester.php" class="btn btn-success">
                                            <i class="fas fa-calendar-plus me-2"></i>Enroll for Next Semester
                                        </a>
                                        <?php
                                        // Check if adjustment period is open and student is currently enrolled
                                        // NOTE: Adjustment period button is available for ALL enrolled students from 1st Year First Semester onwards
                                        // No year level restrictions apply - all enrolled students can access adjustment period
                                        require_once '../classes/Admin.php';
                                        $admin_check = new Admin();
                                        $is_adjustment_open = $admin_check->isAdjustmentPeriodOpen();
                                        if ($is_adjustment_open) {
                                            // Check if student is currently enrolled (for current semester adjustments)
                                            // OR has confirmed enrollment for next semester
                                            $enrollment_check = "SELECT id FROM next_semester_enrollments 
                                                               WHERE user_id = :user_id 
                                                               AND request_status = 'confirmed'
                                                               LIMIT 1";
                                            $enrollment_check_stmt = $conn->prepare($enrollment_check);
                                            $enrollment_check_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
                                            $enrollment_check_stmt->execute();
                                            $has_confirmed_enrollment = $enrollment_check_stmt->fetch();
                                            
                                            // Also check if student is currently enrolled (has active enrollment status)
                                            $is_currently_enrolled = ($user_info['enrollment_status'] == 'enrolled');
                                            
                                            if ($has_confirmed_enrollment || $is_currently_enrolled) {
                                                echo '<a href="adjustment_period.php" class="btn btn-warning">
                                                    <i class="fas fa-exchange-alt me-2"></i>Adjustment Period
                                                </a>';
                                            }
                                        }
                                        ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- My Schedule & Sections Section -->
                <div id="schedule-sections" class="content-section" style="display: none;">
                    <h2 class="mb-3"><i class="fas fa-calendar-week me-2"></i>My Schedule & Sections</h2>
                    
                    <!-- My Sections Section -->
                    <div class="mb-3">
                        <h3 class="mb-3"><i class="fas fa-users-class me-2"></i>My Sections</h3>
                        
                        <?php if (empty($my_sections)): ?>
                            <div class="card">
                                <div class="card-body text-center py-3">
                                    <i class="fas fa-users-class fa-3x text-muted mb-2"></i>
                                    <h5 class="mb-2">No Sections Assigned</h5>
                                    <p class="text-muted mb-0">
                                        <?php if ($user_info['enrollment_status'] == 'pending'): ?>
                                            You have not selected a section yet, or your section selection is pending approval.
                                        <?php else: ?>
                                            You are not enrolled in any sections yet. Please contact the admin to assign you to sections.
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                        <?php else: ?>
                        <div class="row">
                                <?php foreach ($my_sections as $sec): ?>
                                <div class="col-md-6 col-lg-4 mb-3">
                                    <div class="card h-100">
                                            <div class="card-header <?php echo (isset($sec['enrollment_status']) && $sec['enrollment_status'] == 'pending') ? 'bg-warning text-dark' : 'bg-primary text-white'; ?>">
                                                <h5 class="mb-0">
                                                    <?php echo htmlspecialchars($sec['section_name']); ?>
                                                    <?php if (isset($sec['enrollment_status']) && $sec['enrollment_status'] == 'pending'): ?>
                                                        <span class="badge bg-secondary ms-2">Pending Approval</span>
                                                    <?php endif; ?>
                                                </h5>
                                            </div>
                                            <div class="card-body">
                                                <h6 class="text-primary mb-3"><?php echo htmlspecialchars($sec['program_code']); ?></h6>
                                                
                                                <div class="mb-2">
                                                    <i class="fas fa-layer-group text-primary me-2"></i>
                                                    <strong>Year Level:</strong> <?php echo htmlspecialchars($sec['year_level'] ?? $current_enrollment['year_level'] ?? $enrollment_info['year_level'] ?? 'N/A'); ?>
                                                </div>
                                                <div class="mb-2">
                                                    <i class="fas fa-calendar-alt text-primary me-2"></i>
                                                    <strong>Semester:</strong> <?php 
                                                        // Display semester from section first, then fallback to current_enrollment
                                                        $semester_display = $sec['semester'] ?? $current_enrollment['semester'] ?? $enrollment_info['semester'] ?? 'N/A';
                                                        if (stripos($semester_display, 'First') !== false) {
                                                            echo 'First Semester';
                                                        } elseif (stripos($semester_display, 'Second') !== false) {
                                                            echo 'Second Semester';
                                                        } elseif (stripos($semester_display, 'Summer') !== false) {
                                                            echo 'Summer';
                                                        } else {
                                                            echo htmlspecialchars($semester_display);
                                                        }
                                                    ?>
                                                </div>
                                                <div class="mb-2">
                                                    <i class="fas fa-calendar text-primary me-2"></i>
                                                    <strong>Academic Year:</strong> <?php echo htmlspecialchars($sec['academic_year']); ?>
                                                </div>
                                                <div class="mb-2">
                                                    <i class="fas fa-users text-primary me-2"></i>
                                                    <strong>Capacity:</strong> <?php echo $sec['current_enrolled'] ?? 0; ?>/<?php echo $sec['max_capacity'] ?? 0; ?>
                                                </div>
                                                <div class="mb-2">
                                                    <i class="fas fa-tag text-primary me-2"></i>
                                                    <strong>Type:</strong> 
                                                    <span class="badge bg-info"><?php echo ucfirst($sec['section_type'] ?? 'regular'); ?></span>
                                                </div>
                                                <?php if (isset($sec['enrollment_status']) && $sec['enrollment_status'] == 'pending'): ?>
                                                <div class="mt-3 pt-3 border-top">
                                                    <div class="alert alert-warning mb-0" role="alert">
                                                        <i class="fas fa-exclamation-triangle me-2"></i>
                                                        <strong>Pending Approval:</strong> Your section selection is awaiting approval from the admission office.
                                                    </div>
                                                </div>
                                                <?php else: ?>
                                                <div class="mt-3 pt-3 border-top">
                                                    <small class="text-muted">
                                                        <i class="fas fa-clock me-1"></i>
                                                        Enrolled: <?php echo date('M j, Y', strtotime($sec['enrolled_date'])); ?>
                                                    </small>
                                                </div>
                                                <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- My Schedule Section -->
                    <div>
                        <h3 class="mb-3"><i class="fas fa-calendar-week me-2"></i>My Class Schedule</h3>
                        <?php if ($current_enrollment): ?>
                        <div class="alert alert-info mb-3">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Current Enrollment:</strong> 
                            <?php echo htmlspecialchars($current_enrollment['year_level'] . ' - ' . $current_enrollment['semester'] . ' (' . $current_enrollment['academic_year'] . ')'); ?>
                            <br><small class="text-muted">Only subjects from this semester are shown below. Previous semesters are in "Past Enrollment".</small>
                        </div>
                        <?php endif; ?>
                    
                    <?php if (empty($my_schedules)): ?>
                        <div class="card">
                            <div class="card-body text-center py-3">
                                <i class="fas fa-calendar-times fa-3x text-muted mb-2"></i>
                                <h5 class="mb-2">No Schedule Available</h5>
                                <p class="text-muted mb-0">
                                    <?php if ($user_info['enrollment_status'] == 'pending'): ?>
                                        Your schedule will be available once your section selection is approved and your enrollment is processed.
                                    <?php else: ?>
                                        You don't have any class schedules yet. Schedules will appear here once the admin assigns them to your sections.
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php 
                        // Check if any section is pending
                        $has_pending_section = false;
                        if (!empty($my_sections)) {
                            foreach ($my_sections as $sec) {
                                if (isset($sec['enrollment_status']) && $sec['enrollment_status'] == 'pending') {
                                    $has_pending_section = true;
                                    break;
                                }
                            }
                        }
                        ?>
                        <?php if ($has_pending_section): ?>
                        <div class="alert alert-warning mb-3">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Pending Approval:</strong> The schedule shown below is for your selected section. This will become official once your enrollment is approved by the admission office.
                        </div>
                        <?php endif; ?>
                        <!-- Summary Cards -->
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <div class="card text-center bg-primary text-white">
                                    <div class="card-body">
                                        <h3><?php echo count($my_schedules); ?></h3>
                                        <p class="mb-0">Total Subjects</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card text-center bg-success text-white">
                                    <div class="card-body">
                                        <h3><?php echo array_sum(array_column($my_schedules, 'units')); ?></h3>
                                        <p class="mb-0">Total Units</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card text-center bg-info text-white">
                                    <div class="card-body">
                                        <h3><?php echo count(array_unique(array_column($my_schedules, 'section_id'))); ?></h3>
                                        <p class="mb-0">Sections</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="card text-center bg-warning text-white">
                                    <div class="card-body">
                                        <h3><?php echo count(array_unique(array_column($my_schedules, 'professor_name'))); ?></h3>
                                        <p class="mb-0">Professors</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Schedule Table -->
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-list me-2"></i>Class Schedule Details</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Course Code</th>
                                                <th>Course Name</th>
                                                <th>Units</th>
                                                <th>Schedule</th>
                                                <th>Time</th>
                                                <th>Room</th>
                                                <th>Professor</th>
                                                <th>Section</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($my_schedules as $sched): ?>
                                                <?php
                                                // Build schedule days string
                                                $days = [];
                                                if ($sched['schedule_monday']) $days[] = 'Mon';
                                                if ($sched['schedule_tuesday']) $days[] = 'Tue';
                                                if ($sched['schedule_wednesday']) $days[] = 'Wed';
                                                if ($sched['schedule_thursday']) $days[] = 'Thu';
                                                if ($sched['schedule_friday']) $days[] = 'Fri';
                                                if ($sched['schedule_saturday']) $days[] = 'Sat';
                                                if ($sched['schedule_sunday']) $days[] = 'Sun';
                                                $schedule_days = !empty($days) ? implode(', ', $days) : 'TBA';
                                                
                                                // Format time
                                                $time_display = 'TBA';
                                                if (!empty($sched['time_start']) && !empty($sched['time_end'])) {
                                                    $time_display = date('g:i A', strtotime($sched['time_start'])) . ' - ' . date('g:i A', strtotime($sched['time_end']));
                                                }
                                                ?>
                                                <tr>
                                                    <td><strong class="text-primary"><?php echo htmlspecialchars($sched['course_code']); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($sched['course_name']); ?></td>
                                                    <td><span class="badge bg-success"><?php echo $sched['units']; ?></span></td>
                                                    <td><span class="badge bg-info"><?php echo $schedule_days; ?></span></td>
                                                    <td><?php echo $time_display; ?></td>
                                                    <td><?php echo htmlspecialchars($sched['room'] ?? 'TBA'); ?></td>
                                                    <td>
                                                        <?php if (!empty($sched['professor_name'])): ?>
                                                            <div><?php echo htmlspecialchars($sched['professor_name']); ?></div>
                                                            <?php if (!empty($sched['professor_initial'])): ?>
                                                                <small class="text-muted"><?php echo htmlspecialchars($sched['professor_initial']); ?></small>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <span class="text-muted">TBA</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($sched['section_name']); ?></span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Weekly Calendar View -->
                        <div class="card mt-3">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-calendar-alt me-2"></i>Weekly Calendar View</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width: 100px;">Time</th>
                                                <th>Monday</th>
                                                <th>Tuesday</th>
                                                <th>Wednesday</th>
                                                <th>Thursday</th>
                                                <th>Friday</th>
                                                <th>Saturday</th>
                                                <th>Sunday</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php
                                            // Weekly Calendar View: Uses $my_schedules which contains subjects from the most recent COR
                                            // Create time slots from schedules
                                            $time_slots = [];
                                            foreach ($my_schedules as $sched) {
                                                if (!empty($sched['time_start'])) {
                                                    $time_slots[$sched['time_start']] = $sched['time_start'];
                                                }
                                            }
                                            ksort($time_slots);
                                            
                                            if (empty($time_slots)) {
                                                echo '<tr><td colspan="8" class="text-center text-muted">No scheduled time slots</td></tr>';
                                            } else {
                                                foreach ($time_slots as $time_start) {
                                                    echo '<tr>';
                                                    // Find the matching schedule for end time
                                                    $time_end = '';
                                                    foreach ($my_schedules as $s) {
                                                        if ($s['time_start'] == $time_start && !empty($s['time_end'])) {
                                                            $time_end = $s['time_end'];
                                                            break;
                                                        }
                                                    }
                                                    $time_display = date('g:i A', strtotime($time_start));
                                                    if ($time_end) {
                                                        $time_display .= '<br><small class="text-muted">' . date('g:i A', strtotime($time_end)) . '</small>';
                                                    }
                                                    echo '<td class="text-center">' . $time_display . '</td>';
                                                    
                                                    // Days
                                                    $days_cols = ['schedule_monday', 'schedule_tuesday', 'schedule_wednesday', 'schedule_thursday', 'schedule_friday', 'schedule_saturday', 'schedule_sunday'];
                                                    foreach ($days_cols as $day_col) {
                                                        $found = false;
                                                        foreach ($my_schedules as $sched) {
                                                            if ($sched['time_start'] == $time_start && $sched[$day_col]) {
                                                                echo '<td class="bg-light">';
                                                                echo '<strong class="text-primary">' . htmlspecialchars($sched['course_code']) . '</strong><br>';
                                                                echo '<small>' . htmlspecialchars($sched['course_name']) . '</small><br>';
                                                                echo '<small class="text-muted">' . htmlspecialchars($sched['room'] ?? 'TBA') . '</small><br>';
                                                                echo '<small class="text-info">' . htmlspecialchars($sched['professor_initial'] ?? '') . '</small>';
                                                                echo '</td>';
                                                                $found = true;
                                                                break;
                                                            }
                                                        }
                                                        if (!$found) {
                                                            echo '<td></td>';
                                                        }
                                                    }
                                                    echo '</tr>';
                                                }
                                            }
                                            ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                    </div>
                </div>
                
                <!-- Enrollment Status Section -->
                <div id="enrollment" class="content-section" style="display: none;">
                    <h2 class="mb-3">Enrollment Status</h2>
                    
                    <?php if (!$enrollment_info): ?>
                        <div class="card">
                            <div class="card-body text-center py-3">
                                <i class="fas fa-user-clock fa-3x text-muted mb-2"></i>
                                <h5 class="mb-2">No Enrollment Record</h5>
                                <p class="text-muted mb-0">You don't have an enrollment record yet. Please contact the admin for assistance.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <div class="col-md-8">
                                <div class="card mb-3">
                                    <div class="card-header bg-primary text-white">
                                        <h5 class="mb-0"><i class="fas fa-user-graduate me-2"></i>Enrollment Information</h5>
                                    </div>
                                    <div class="card-body">
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <h6 class="text-muted mb-2">Program/Course</h6>
                                                <p class="h5"><?php echo htmlspecialchars($enrollment_info['display_program'] ?? 'N/A'); ?></p>
                                            </div>
                                            <div class="col-md-6">
                                                <h6 class="text-muted mb-2">Student Type</h6>
                                                <p class="h5"><?php echo htmlspecialchars($enrollment_info['student_type'] ?? 'N/A'); ?></p>
                                            </div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <h6 class="text-muted mb-2">Year Level</h6>
                                                <p class="h5"><?php echo htmlspecialchars($current_enrollment['year_level'] ?? $enrollment_info['year_level'] ?? 'N/A'); ?></p>
                                            </div>
                                            <div class="col-md-6">
                                                <h6 class="text-muted mb-2">Academic Year</h6>
                                                <p class="h5"><?php echo htmlspecialchars($current_enrollment['academic_year'] ?? $enrollment_info['academic_year'] ?? 'N/A'); ?></p>
                                            </div>
                                        </div>
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <h6 class="text-muted mb-2">Semester</h6>
                                                <p class="h5">
                                                    <?php 
                                                        $status_semester = $current_enrollment['semester'] ?? $enrollment_info['semester'] ?? 'N/A';
                                                        echo htmlspecialchars($status_semester);
                                                    ?>
                                                </p>
                                            </div>
                                            <div class="col-md-6">
                                                <h6 class="text-muted mb-2">Enrollment Status</h6>
                                                <p>
                                                    <?php if (isset($enrollment_info['enrollment_status']) && $enrollment_info['enrollment_status'] == 'enrolled'): ?>
                                                        <span class="badge bg-success" style="font-size: 1.1rem;">
                                                            <i class="fas fa-check-circle me-1"></i>Enrolled
                                                        </span>
                                                    <?php elseif (isset($enrollment_info['enrollment_status']) && $enrollment_info['enrollment_status'] == 'pending'): ?>
                                                        <span class="badge bg-warning" style="font-size: 1.1rem;">
                                                            <i class="fas fa-clock me-1"></i>Pending
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary" style="font-size: 1.1rem;">
                                                            <?php echo htmlspecialchars(ucfirst($enrollment_info['enrollment_status'] ?? 'Unknown')); ?>
                                                        </span>
                                                    <?php endif; ?>
                                                </p>
                                            </div>
                                        </div>
                                        <div class="row">
                                            <div class="col-12">
                                                <h6 class="text-muted mb-2">Registration Date</h6>
                                                <p><i class="fas fa-calendar me-2"></i>
                                                    <?php 
                                                    if (isset($enrollment_info['enrolled_date']) && $enrollment_info['enrolled_date']) {
                                                        echo date('F j, Y', strtotime($enrollment_info['enrolled_date'])); 
                                                    } else {
                                                        echo 'N/A';
                                                    }
                                                    ?>
                                                </p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-header bg-info text-white">
                                        <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>Quick Stats</h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="mb-3 pb-3 border-bottom">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <span class="text-muted">Total Sections</span>
                                                <span class="h4 mb-0 text-primary"><?php echo count($my_sections); ?></span>
                                            </div>
                                        </div>
                                        <div class="mb-3 pb-3 border-bottom">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <span class="text-muted">Document Progress</span>
                                                <span class="h4 mb-0 text-success">
                                                    <?php 
                                                    $docs = ['id_pictures', 'psa_birth_certificate', 'barangay_certificate', 'voters_id', 'high_school_diploma', 'sf10_form', 'form_138', 'good_moral'];
                                                    $completed = 0;
                                                    foreach ($docs as $doc) {
                                                        if (isset($checklist[$doc]) && $checklist[$doc]) $completed++;
                                                    }
                                                    echo $completed . '/' . count($docs);
                                                    ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div>
                                            <div class="d-flex justify-content-between align-items-center">
                                                <span class="text-muted">Enrollment Status</span>
                                                <span class="badge bg-<?php echo (isset($enrollment_info['enrollment_status']) && $enrollment_info['enrollment_status'] == 'enrolled') ? 'success' : 'warning'; ?>">
                                                    <?php echo ucfirst($enrollment_info['enrollment_status'] ?? 'pending'); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <!-- Upload Documents / Document Checklist Section -->
                <div id="upload-documents" class="content-section" style="display: none;">
                    <?php if ($user_info['enrollment_status'] == 'enrolled'): ?>
                        <!-- Document Checklist View for Enrolled Students -->
                        <?php
                        // Get document checklist from database
                        $checklist_query = "SELECT * FROM document_checklists WHERE user_id = :user_id";
                        $checklist_stmt = $conn->prepare($checklist_query);
                        $checklist_stmt->bindParam(':user_id', $_SESSION['user_id']);
                        $checklist_stmt->execute();
                        $checklist_data = $checklist_stmt->fetch(PDO::FETCH_ASSOC);
                        
                        // If no checklist exists, create one
                        if (!$checklist_data) {
                            $create_checklist = "INSERT INTO document_checklists (user_id) VALUES (:user_id)";
                            $create_stmt = $conn->prepare($create_checklist);
                            $create_stmt->bindParam(':user_id', $_SESSION['user_id']);
                            $create_stmt->execute();
                            $checklist_data = ['id_pictures' => 0, 'psa_birth_certificate' => 0, 'barangay_certificate' => 0, 
                                             'voters_id' => 0, 'high_school_diploma' => 0, 'sf10_form' => 0, 
                                             'form_138' => 0, 'good_moral' => 0, 'documents_submitted' => 0, 
                                             'photocopies_submitted' => 0];
                        }
                        
                        // Document list with labels
                        $documents = [
                            'id_pictures' => ['label' => '2x2 ID Pictures (4 pcs)', 'icon' => 'fa-id-card'],
                            'psa_birth_certificate' => ['label' => 'PSA Birth Certificate', 'icon' => 'fa-certificate'],
                            'barangay_certificate' => ['label' => 'Barangay Certificate of Residency', 'icon' => 'fa-home'],
                            'voters_id' => ['label' => 'Voter\'s ID or Registration Stub', 'icon' => 'fa-vote-yea'],
                            'high_school_diploma' => ['label' => 'High School Diploma', 'icon' => 'fa-graduation-cap'],
                            'sf10_form' => ['label' => 'SF10 (Senior High School Permanent Record)', 'icon' => 'fa-file-alt'],
                            'form_138' => ['label' => 'Form 138 (Report Card)', 'icon' => 'fa-file-alt'],
                            'good_moral' => ['label' => 'Certificate of Good Moral Character', 'icon' => 'fa-certificate']
                        ];
                        
                        // Calculate progress
                        $completed = 0;
                        foreach ($documents as $key => $doc) {
                            if (isset($checklist_data[$key]) && $checklist_data[$key] == 1) {
                                $completed++;
                            }
                        }
                        $total = count($documents);
                        $progress_percentage = ($completed / $total) * 100;
                        ?>
                        <h2 class="mb-3"><i class="fas fa-file-alt me-2"></i>Document Checklist</h2>
                        
                        <div class="card mb-3">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Document Submission Status</h5>
                            </div>
                            <div class="card-body">
                                <div class="row mb-3">
                                    <div class="col-md-6">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <span><strong>Documents Completed:</strong></span>
                                            <span class="badge bg-<?php echo $completed == $total ? 'success' : 'info'; ?> fs-6">
                                                <?php echo $completed; ?>/<?php echo $total; ?>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="progress" style="height: 25px;">
                                            <div class="progress-bar bg-<?php echo $completed == $total ? 'success' : 'primary'; ?>" 
                                                 role="progressbar" 
                                                 style="width: <?php echo $progress_percentage; ?>%"
                                                 aria-valuenow="<?php echo $completed; ?>" 
                                                 aria-valuemin="0" 
                                                 aria-valuemax="<?php echo $total; ?>">
                                                <?php echo round($progress_percentage); ?>%
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <?php if ($checklist_data['documents_submitted'] || $checklist_data['photocopies_submitted']): ?>
                                <div class="alert alert-info mb-3">
                                    <strong><i class="fas fa-info-circle me-2"></i>Submission Status:</strong>
                                    <ul class="mb-0 mt-2">
                                        <?php if ($checklist_data['documents_submitted']): ?>
                                        <li><i class="fas fa-check-circle text-success me-2"></i>Original documents submitted in long brown envelope</li>
                                        <?php endif; ?>
                                        <?php if ($checklist_data['photocopies_submitted']): ?>
                                        <li><i class="fas fa-check-circle text-success me-2"></i>Photocopies submitted in separate envelope</li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                                <?php endif; ?>
                                
                                <?php if (!empty($checklist_data['notes'])): ?>
                                <div class="alert alert-warning">
                                    <strong><i class="fas fa-sticky-note me-2"></i>Notes:</strong>
                                    <p class="mb-0 mt-2"><?php echo htmlspecialchars($checklist_data['notes']); ?></p>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-list-check me-2"></i>Required Documents</h5>
                            </div>
                            <div class="card-body p-0">
                                <?php foreach ($documents as $key => $doc): ?>
                                <div class="checklist-item">
                                    <div class="d-flex align-items-center">
                                        <i class="fas <?php echo $doc['icon']; ?> text-primary me-3" style="width: 24px;"></i>
                                        <span class="flex-grow-1"><?php echo $doc['label']; ?></span>
                                        <span class="checklist-icon">
                                            <?php if (isset($checklist_data[$key]) && $checklist_data[$key] == 1): ?>
                                                <i class="fas fa-check-circle text-success fa-lg"></i>
                                            <?php else: ?>
                                                <i class="fas fa-times-circle text-danger fa-lg"></i>
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <!-- Upload Documents View for Pending Students -->
                        <?php
                        // Get uploaded documents
                        $docs_query = "SELECT * FROM document_uploads WHERE user_id = :user_id ORDER BY upload_date DESC";
                        $docs_stmt = $conn->prepare($docs_query);
                        $docs_stmt->bindParam(':user_id', $_SESSION['user_id']);
                        $docs_stmt->execute();
                        $uploaded_documents = $docs_stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        // Organize by document type
                        $docs_by_type = [];
                        foreach ($uploaded_documents as $doc) {
                            $docs_by_type[$doc['document_type']] = $doc;
                        }
                        
                        // Required documents
                        $required_documents = [
                            'id_pictures' => ['label' => '2x2 ID Pictures (4 pcs)', 'icon' => 'fa-id-card'],
                            'psa_birth_certificate' => ['label' => 'PSA Birth Certificate', 'icon' => 'fa-certificate'],
                            'barangay_certificate' => ['label' => 'Barangay Certificate of Residency', 'icon' => 'fa-home'],
                            'voters_id' => ['label' => 'Voter\'s ID or Registration Stub', 'icon' => 'fa-vote-yea'],
                            'high_school_diploma' => ['label' => 'High School Diploma', 'icon' => 'fa-graduation-cap'],
                            'sf10_form' => ['label' => 'SF10 (Senior High School Permanent Record)', 'icon' => 'fa-file-alt'],
                            'form_138' => ['label' => 'Form 138 (Report Card)', 'icon' => 'fa-file-alt'],
                            'good_moral' => ['label' => 'Certificate of Good Moral Character', 'icon' => 'fa-certificate']
                        ];
                        ?>
                        <h2 class="mb-3"><i class="fas fa-upload me-2"></i>Upload Required Documents</h2>
                    
                        <div class="card">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-upload me-2"></i>Upload Required Documents</h5>
                            </div>
                            <div class="card-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Important:</strong> Upload clear scanned copies or photos of your documents. 
                            The Admission Office will review and verify your documents. 
                            <strong>Accepted formats:</strong> PDF, JPG, PNG (Max 5MB per file)
                        </div>
                        
                        <div class="row">
                            <?php foreach ($required_documents as $doc_type => $doc_info): ?>
                            <?php
                            $uploaded = isset($docs_by_type[$doc_type]);
                            $status = 'Not Uploaded';
                            $status_class = 'secondary';
                            $status_icon = 'fa-upload';
                            
                            if ($uploaded) {
                                $doc = $docs_by_type[$doc_type];
                                if ($doc['verification_status'] == 'verified') {
                                    $status = 'Verified';
                                    $status_class = 'success';
                                    $status_icon = 'fa-check-circle';
                                } elseif ($doc['verification_status'] == 'rejected') {
                                    $status = 'Rejected';
                                    $status_class = 'danger';
                                    $status_icon = 'fa-times-circle';
                                } else {
                                    $status = 'Pending Review';
                                    $status_class = 'warning';
                                    $status_icon = 'fa-clock';
                                }
                            }
                            ?>
                            <div class="col-md-6 mb-3">
                                <div class="card h-100">
                                    <div class="card-body">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <h6 class="mb-0">
                                                <i class="fas <?php echo $doc_info['icon']; ?> me-2"></i>
                                                <?php echo $doc_info['label']; ?>
                                            </h6>
                                            <span class="badge bg-<?php echo $status_class; ?>">
                                                <i class="fas <?php echo $status_icon; ?> me-1"></i>
                                                <?php echo $status; ?>
                                            </span>
                                        </div>
                                        
                                        <?php if ($uploaded): ?>
                                            <div class="mt-2">
                                                <small class="text-muted">
                                                    <i class="fas fa-calendar me-1"></i>
                                                    Uploaded: <?php echo date('M j, Y', strtotime($doc['upload_date'])); ?>
                                                </small>
                                                <br>
                                                <small class="text-muted">
                                                    <i class="fas fa-file me-1"></i>
                                                    <?php echo $doc['file_name']; ?>
                                                </small>
                                                
                                                <?php if ($doc['verification_status'] == 'rejected' && $doc['rejection_reason']): ?>
                                                <div class="alert alert-danger mt-2 mb-0 py-2">
                                                    <small>
                                                        <strong>Rejection Reason:</strong><br>
                                                        <?php echo htmlspecialchars($doc['rejection_reason']); ?>
                                                    </small>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="mt-3">
                                                <a href="<?php echo '../' . htmlspecialchars($doc['file_path']); ?>" target="_blank" class="btn btn-sm btn-outline-primary me-2">
                                                    <i class="fas fa-eye me-1"></i> View
                                                </a>
                                                <?php if ($doc['verification_status'] != 'verified'): ?>
                                                <button class="btn btn-sm btn-outline-secondary" onclick="replaceDocument('<?php echo $doc_type; ?>')">
                                                    <i class="fas fa-redo me-1"></i> Replace
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        <?php else: ?>
                                            <div class="mt-3">
                                                <button class="btn btn-sm btn-primary" onclick="uploadDocument('<?php echo $doc_type; ?>')">
                                                    <i class="fas fa-upload me-1"></i> Upload Document
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="mt-3">
                            <?php
                            $total_docs = count($required_documents);
                            $uploaded_count = count($docs_by_type);
                            $verified_count = 0;
                            foreach ($docs_by_type as $doc) {
                                if ($doc['verification_status'] == 'verified') {
                                    $verified_count++;
                                }
                            }
                            $progress_percentage = ($uploaded_count / $total_docs) * 100;
                            ?>
                            <div class="alert alert-<?php echo $verified_count == $total_docs ? 'success' : ($uploaded_count >= $total_docs ? 'info' : 'warning'); ?>">
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <strong>Document Upload Progress</strong>
                                    <span><?php echo $uploaded_count; ?>/<?php echo $total_docs; ?> uploaded | 
                                          <?php echo $verified_count; ?>/<?php echo $total_docs; ?> verified</span>
                                </div>
                                <div class="progress" style="height: 20px;">
                                    <div class="progress-bar bg-success" style="width: <?php echo ($verified_count / $total_docs) * 100; ?>%">
                                        Verified: <?php echo $verified_count; ?>
                                    </div>
                                    <div class="progress-bar bg-warning" style="width: <?php echo (($uploaded_count - $verified_count) / $total_docs) * 100; ?>%">
                                        Pending: <?php echo $uploaded_count - $verified_count; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Hidden file input for uploads -->
                <input type="file" id="documentFileInput" style="display:none" accept=".pdf,.jpg,.jpeg,.png">

                <!-- User Management Section -->
                <div id="user-management" class="content-section" style="display: none;">
                    <h2 class="mb-3"><i class="fas fa-user-edit me-2"></i>User Management</h2>
                    
                    <?php 
                    // Ensure $user_info is set and has at least basic data
                    if (!$user_info || empty($user_info)) {
                        // Fallback: get user info directly
                        $fallback_query = "SELECT * FROM users WHERE id = :user_id";
                        $fallback_stmt = $conn->prepare($fallback_query);
                        $fallback_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
                        $fallback_stmt->execute();
                        $user_info = $fallback_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
                    }
                    ?>
                    
                    <div class="alert alert-info mb-3">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Note:</strong> You can update your personal information here. Some fields like Student ID and Enrollment Status are managed by the administration and cannot be changed.
                        <?php if (($user_info['enrollment_status'] ?? '') == 'pending'): ?>
                            <br><small class="text-muted"><i class="fas fa-clock me-1"></i>Your enrollment is currently pending approval. Once approved, additional fields may become available.</small>
                        <?php endif; ?>
                    </div>
                    
                    <form action="update_profile.php" method="POST" id="profileForm">
                        <!-- Basic Information -->
                        <div class="card mb-3">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-user me-2"></i>Basic Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label for="first_name" class="form-label">First Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="first_name" name="first_name" 
                                               value="<?php echo htmlspecialchars($user_info['first_name'] ?? ''); ?>" required>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="middle_name" class="form-label">Middle Name</label>
                                        <input type="text" class="form-control" id="middle_name" name="middle_name" 
                                               value="<?php echo htmlspecialchars($user_info['middle_name'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="last_name" class="form-label">Last Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" id="last_name" name="last_name" 
                                               value="<?php echo htmlspecialchars($user_info['last_name'] ?? ''); ?>" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                                        <input type="email" class="form-control" id="email" name="email" 
                                               value="<?php echo htmlspecialchars($user_info['email'] ?? ''); ?>" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="phone" class="form-label">Phone</label>
                                        <input type="text" class="form-control" id="phone" name="phone" 
                                               value="<?php echo htmlspecialchars($user_info['phone'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="contact_number" class="form-label">Contact Number</label>
                                        <input type="text" class="form-control" id="contact_number" name="contact_number" 
                                               value="<?php echo htmlspecialchars($user_info['contact_number'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="date_of_birth" class="form-label">Date of Birth</label>
                                        <input type="date" class="form-control" id="date_of_birth" name="date_of_birth" 
                                               value="<?php echo $user_info['date_of_birth'] ?? ''; ?>">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="age" class="form-label">Age</label>
                                        <input type="number" class="form-control" id="age" name="age" 
                                               value="<?php echo htmlspecialchars($user_info['age'] ?? ''); ?>" min="1" max="150">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="sex_at_birth" class="form-label">Sex at Birth</label>
                                        <select class="form-select" id="sex_at_birth" name="sex_at_birth">
                                            <option value="">Select...</option>
                                            <option value="Male" <?php echo ($user_info['sex_at_birth'] ?? '') == 'Male' ? 'selected' : ''; ?>>Male</option>
                                            <option value="Female" <?php echo ($user_info['sex_at_birth'] ?? '') == 'Female' ? 'selected' : ''; ?>>Female</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="civil_status" class="form-label">Civil Status</label>
                                        <select class="form-select" id="civil_status" name="civil_status">
                                            <option value="">Select...</option>
                                            <option value="Single" <?php echo ($user_info['civil_status'] ?? '') == 'Single' ? 'selected' : ''; ?>>Single</option>
                                            <option value="Married" <?php echo ($user_info['civil_status'] ?? '') == 'Married' ? 'selected' : ''; ?>>Married</option>
                                            <option value="Widowed" <?php echo ($user_info['civil_status'] ?? '') == 'Widowed' ? 'selected' : ''; ?>>Widowed</option>
                                            <option value="Separated" <?php echo ($user_info['civil_status'] ?? '') == 'Separated' ? 'selected' : ''; ?>>Separated</option>
                                            <option value="Divorced" <?php echo ($user_info['civil_status'] ?? '') == 'Divorced' ? 'selected' : ''; ?>>Divorced</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="spouse_name" class="form-label">Spouse Name (if married)</label>
                                        <input type="text" class="form-control" id="spouse_name" name="spouse_name" 
                                               value="<?php echo htmlspecialchars($user_info['spouse_name'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label for="lrn" class="form-label">LRN</label>
                                        <input type="text" class="form-control" id="lrn" name="lrn" 
                                               value="<?php echo htmlspecialchars($user_info['lrn'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label for="occ_examinee_number" class="form-label">OCC Examinee Number</label>
                                        <input type="text" class="form-control" id="occ_examinee_number" name="occ_examinee_number" 
                                               value="<?php echo htmlspecialchars($user_info['occ_examinee_number'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Address Information -->
                        <div class="card mb-3">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-map-marker-alt me-2"></i>Address Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-12 mb-3">
                                        <label for="address" class="form-label">Address</label>
                                        <input type="text" class="form-control" id="address" name="address" 
                                               value="<?php echo htmlspecialchars($user_info['address'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-12 mb-3">
                                        <label for="permanent_address" class="form-label">Permanent Address</label>
                                        <textarea class="form-control" id="permanent_address" name="permanent_address" rows="2"><?php echo htmlspecialchars($user_info['permanent_address'] ?? ''); ?></textarea>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="municipality_city" class="form-label">Municipality/City</label>
                                        <input type="text" class="form-control" id="municipality_city" name="municipality_city" 
                                               value="<?php echo htmlspecialchars($user_info['municipality_city'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="barangay" class="form-label">Barangay</label>
                                        <input type="text" class="form-control" id="barangay" name="barangay" 
                                               value="<?php echo htmlspecialchars($user_info['barangay'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Family Information -->
                        <div class="card mb-3">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-users me-2"></i>Family Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4 mb-3">
                                        <label for="father_name" class="form-label">Father's Name</label>
                                        <input type="text" class="form-control" id="father_name" name="father_name" 
                                               value="<?php echo htmlspecialchars($user_info['father_name'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="father_occupation" class="form-label">Father's Occupation</label>
                                        <input type="text" class="form-control" id="father_occupation" name="father_occupation" 
                                               value="<?php echo htmlspecialchars($user_info['father_occupation'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="father_education" class="form-label">Father's Education</label>
                                        <input type="text" class="form-control" id="father_education" name="father_education" 
                                               value="<?php echo htmlspecialchars($user_info['father_education'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="mother_maiden_name" class="form-label">Mother's Maiden Name</label>
                                        <input type="text" class="form-control" id="mother_maiden_name" name="mother_maiden_name" 
                                               value="<?php echo htmlspecialchars($user_info['mother_maiden_name'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="mother_occupation" class="form-label">Mother's Occupation</label>
                                        <input type="text" class="form-control" id="mother_occupation" name="mother_occupation" 
                                               value="<?php echo htmlspecialchars($user_info['mother_occupation'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="mother_education" class="form-label">Mother's Education</label>
                                        <input type="text" class="form-control" id="mother_education" name="mother_education" 
                                               value="<?php echo htmlspecialchars($user_info['mother_education'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label for="number_of_brothers" class="form-label">Number of Brothers</label>
                                        <input type="number" class="form-control" id="number_of_brothers" name="number_of_brothers" 
                                               value="<?php echo htmlspecialchars($user_info['number_of_brothers'] ?? 0); ?>" min="0">
                                    </div>
                                    <div class="col-md-3 mb-3">
                                        <label for="number_of_sisters" class="form-label">Number of Sisters</label>
                                        <input type="number" class="form-control" id="number_of_sisters" name="number_of_sisters" 
                                               value="<?php echo htmlspecialchars($user_info['number_of_sisters'] ?? 0); ?>" min="0">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="combined_family_income" class="form-label">Combined Family Income</label>
                                        <input type="text" class="form-control" id="combined_family_income" name="combined_family_income" 
                                               value="<?php echo htmlspecialchars($user_info['combined_family_income'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="guardian_name" class="form-label">Guardian Name (if applicable)</label>
                                        <input type="text" class="form-control" id="guardian_name" name="guardian_name" 
                                               value="<?php echo htmlspecialchars($user_info['guardian_name'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Educational Background -->
                        <div class="card mb-3">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-graduation-cap me-2"></i>Educational Background</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="school_last_attended" class="form-label">School Last Attended</label>
                                        <input type="text" class="form-control" id="school_last_attended" name="school_last_attended" 
                                               value="<?php echo htmlspecialchars($user_info['school_last_attended'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="school_address" class="form-label">School Address</label>
                                        <textarea class="form-control" id="school_address" name="school_address" rows="2"><?php echo htmlspecialchars($user_info['school_address'] ?? ''); ?></textarea>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="shs_track" class="form-label">SHS Track</label>
                                        <input type="text" class="form-control" id="shs_track" name="shs_track" 
                                               value="<?php echo htmlspecialchars($user_info['shs_track'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="shs_strand" class="form-label">SHS Strand</label>
                                        <input type="text" class="form-control" id="shs_strand" name="shs_strand" 
                                               value="<?php echo htmlspecialchars($user_info['shs_strand'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="preferred_program" class="form-label">Preferred Program</label>
                                        <input type="text" class="form-control" id="preferred_program" name="preferred_program" 
                                               value="<?php echo htmlspecialchars($user_info['preferred_program'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Working Student Information -->
                        <div class="card mb-3">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-briefcase me-2"></i>Working Student Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-12 mb-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="is_working_student" name="is_working_student" value="1" 
                                                   <?php echo ($user_info['is_working_student'] ?? 0) == 1 ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="is_working_student">
                                                I am a working student
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="employer" class="form-label">Employer</label>
                                        <input type="text" class="form-control" id="employer" name="employer" 
                                               value="<?php echo htmlspecialchars($user_info['employer'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="work_position" class="form-label">Work Position</label>
                                        <input type="text" class="form-control" id="work_position" name="work_position" 
                                               value="<?php echo htmlspecialchars($user_info['work_position'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-4 mb-3">
                                        <label for="working_hours" class="form-label">Working Hours</label>
                                        <input type="text" class="form-control" id="working_hours" name="working_hours" 
                                               value="<?php echo htmlspecialchars($user_info['working_hours'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- PWD Information -->
                        <div class="card mb-3">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-wheelchair me-2"></i>PWD Information</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-12 mb-3">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="is_pwd" name="is_pwd" value="1" 
                                                   <?php echo ($user_info['is_pwd'] ?? 0) == 1 ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="is_pwd">
                                                Person with Disability (PWD)
                                            </label>
                                        </div>
                                    </div>
                                    <div class="col-md-12">
                                        <label class="form-label">Disability Type (if applicable):</label>
                                        <div class="row">
                                            <div class="col-md-3 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="hearing_disability" name="hearing_disability" value="1" 
                                                           <?php echo ($user_info['hearing_disability'] ?? 0) == 1 ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="hearing_disability">Hearing</label>
                                                </div>
                                            </div>
                                            <div class="col-md-3 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="physical_disability" name="physical_disability" value="1" 
                                                           <?php echo ($user_info['physical_disability'] ?? 0) == 1 ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="physical_disability">Physical</label>
                                                </div>
                                            </div>
                                            <div class="col-md-3 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="mental_disability" name="mental_disability" value="1" 
                                                           <?php echo ($user_info['mental_disability'] ?? 0) == 1 ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="mental_disability">Mental</label>
                                                </div>
                                            </div>
                                            <div class="col-md-3 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="intellectual_disability" name="intellectual_disability" value="1" 
                                                           <?php echo ($user_info['intellectual_disability'] ?? 0) == 1 ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="intellectual_disability">Intellectual</label>
                                                </div>
                                            </div>
                                            <div class="col-md-3 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="psychosocial_disability" name="psychosocial_disability" value="1" 
                                                           <?php echo ($user_info['psychosocial_disability'] ?? 0) == 1 ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="psychosocial_disability">Psychosocial</label>
                                                </div>
                                            </div>
                                            <div class="col-md-3 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="chronic_illness_disability" name="chronic_illness_disability" value="1" 
                                                           <?php echo ($user_info['chronic_illness_disability'] ?? 0) == 1 ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="chronic_illness_disability">Chronic Illness</label>
                                                </div>
                                            </div>
                                            <div class="col-md-3 mb-2">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" id="learning_disability" name="learning_disability" value="1" 
                                                           <?php echo ($user_info['learning_disability'] ?? 0) == 1 ? 'checked' : ''; ?>>
                                                    <label class="form-check-label" for="learning_disability">Learning</label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Password Change (Optional) -->
                        <div class="card mb-3">
                            <div class="card-header bg-primary text-white">
                                <h5 class="mb-0"><i class="fas fa-lock me-2"></i>Change Password (Optional)</h5>
                            </div>
                            <div class="card-body">
                                <div class="alert alert-warning">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    Leave blank if you don't want to change your password.
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="password" class="form-label">New Password</label>
                                        <input type="password" class="form-control" id="password" name="password" 
                                               placeholder="Enter new password">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="confirm_password" class="form-label">Confirm New Password</label>
                                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                               placeholder="Confirm new password">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Read-only Information -->
                        <div class="card mb-3">
                            <div class="card-header bg-secondary text-white">
                                <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Administrative Information (Read-Only)</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Student ID</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($user_info['student_id'] ?? 'Pending Assignment'); ?>" readonly>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Enrollment Status</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars(ucfirst($user_info['enrollment_status'] ?? 'Pending')); ?>" readonly>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="d-flex justify-content-end gap-2 mb-3">
                            <button type="button" class="btn btn-secondary" onclick="showSection('dashboard'); return false;">
                                <i class="fas fa-times me-2"></i>Cancel
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>Save Changes
                            </button>
                        </div>
                    </form>
                </div>

                <!-- Past Enrollment Section -->
                <div id="past-enrollment" class="content-section" style="display: none;">
                        <h2 class="mb-3"><i class="fas fa-history me-2"></i>Past Enrollment</h2>
                        
                        <?php if ($current_enrollment): ?>
                        <div class="alert alert-secondary mb-3">
                            <i class="fas fa-history me-2"></i>
                            <strong>Note:</strong> All semesters except your current enrollment 
                            (<strong><?php echo htmlspecialchars($current_enrollment['year_level'] . ' - ' . $current_enrollment['semester']); ?></strong>) 
                            are shown here as past enrollments.
                        </div>
                        <?php endif; ?>
                        
                        <?php if (empty($past_sections) && empty($past_schedules)): ?>
                            <div class="card">
                                <div class="card-body text-center py-3">
                                    <i class="fas fa-folder-open fa-3x text-muted mb-2"></i>
                                    <h5 class="mb-2">No Past Enrollment Records</h5>
                                    <p class="text-muted mb-0">You don't have any past enrollment records yet. Past sections and schedules from previous semesters will appear here.</p>
                                </div>
                            </div>
                        <?php else: ?>
                    <!-- Group past data by academic year and semester -->
                    <?php 
                    $past_by_term = [];
                    foreach ($past_sections as $ps) {
                        $key = $ps['academic_year'] . '|' . $ps['semester'];
                        if (!isset($past_by_term[$key])) {
                            $past_by_term[$key] = [
                                'academic_year' => $ps['academic_year'],
                                'semester' => $ps['semester'],
                                'sections' => [],
                                'schedules' => []
                            ];
                        }
                        $past_by_term[$key]['sections'][] = $ps;
                    }
                    
                    foreach ($past_schedules as $psch) {
                        $key = $psch['academic_year'] . '|' . $psch['semester'];
                        if (!isset($past_by_term[$key])) {
                            $past_by_term[$key] = [
                                'academic_year' => $psch['academic_year'],
                                'semester' => $psch['semester'],
                                'sections' => [],
                                'schedules' => []
                            ];
                        }
                        $past_by_term[$key]['schedules'][] = $psch;
                    }
                    
                    // Sort by most recent first
                    krsort($past_by_term);
                    ?>
                    
                    <?php foreach ($past_by_term as $term): ?>
                        <div class="card mb-3">
                            <div class="card-header bg-secondary text-white">
                                <h5 class="mb-0">
                                    <i class="fas fa-calendar me-2"></i>
                                    <?php echo htmlspecialchars($term['academic_year'] . ' - ' . $term['semester']); ?>
                                </h5>
                            </div>
                            <div class="card-body">
                                <!-- Past Sections -->
                                <?php if (!empty($term['sections'])): ?>
                                    <h6 class="mb-3"><i class="fas fa-users-class me-2"></i>Sections</h6>
                                    <div class="row mb-3">
                                        <?php foreach ($term['sections'] as $sec): ?>
                                            <div class="col-md-6 col-lg-4 mb-3">
                                                <div class="card border">
                                                    <div class="card-body">
                                                        <h6 class="text-primary"><?php echo htmlspecialchars($sec['section_name']); ?></h6>
                                                        <p class="mb-1"><strong><?php echo htmlspecialchars($sec['program_code']); ?></strong></p>
                                                        <div class="small text-muted">
                                                            <div><i class="fas fa-layer-group me-1"></i><?php echo htmlspecialchars($sec['year_level']); ?></div>
                                                            <div><i class="fas fa-tag me-1"></i>
                                                                <span class="badge <?php echo $sec['section_type'] == 'Morning' ? 'bg-info' : ($sec['section_type'] == 'Afternoon' ? 'bg-warning' : 'bg-dark'); ?>">
                                                                    <?php echo htmlspecialchars($sec['section_type']); ?>
                                                                </span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Past Schedules -->
                                <?php if (!empty($term['schedules'])): ?>
                                    <h6 class="mb-3"><i class="fas fa-calendar-alt me-2"></i>Class Schedule</h6>
                                    <div class="table-responsive">
                                        <table class="table table-bordered table-sm">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Course Code</th>
                                                    <th>Course Name</th>
                                                    <th>Units</th>
                                                    <th>Schedule</th>
                                                    <th>Time</th>
                                                    <th>Room</th>
                                                    <th>Professor</th>
                                                    <th>Section</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($term['schedules'] as $sched): 
                                                    $days = '';
                                                    if ($sched['schedule_monday']) $days .= 'M ';
                                                    if ($sched['schedule_tuesday']) $days .= 'T ';
                                                    if ($sched['schedule_wednesday']) $days .= 'W ';
                                                    if ($sched['schedule_thursday']) $days .= 'Th ';
                                                    if ($sched['schedule_friday']) $days .= 'F ';
                                                    if ($sched['schedule_saturday']) $days .= 'Sat ';
                                                    if ($sched['schedule_sunday']) $days .= 'Sun ';
                                                ?>
                                                    <tr>
                                                        <td><strong><?php echo htmlspecialchars($sched['course_code']); ?></strong></td>
                                                        <td><?php echo htmlspecialchars($sched['course_name']); ?></td>
                                                        <td class="text-center"><?php echo $sched['units']; ?></td>
                                                        <td><?php echo $days ? $days : '-'; ?></td>
                                                        <td>
                                                            <?php 
                                                            if ($sched['time_start'] && $sched['time_end']) {
                                                                echo date('g:i A', strtotime($sched['time_start'])) . ' - ' . date('g:i A', strtotime($sched['time_end']));
                                                            } else {
                                                                echo '-';
                                                            }
                                                            ?>
                                                        </td>
                                                        <td><?php echo htmlspecialchars($sched['room']) ?: '-'; ?></td>
                                                        <td>
                                                            <?php if (!empty($sched['professor_name'])): ?>
                                                                <div><?php echo htmlspecialchars($sched['professor_name']); ?></div>
                                                                <?php if (!empty($sched['professor_initial'])): ?>
                                                                    <small class="text-muted"><?php echo htmlspecialchars($sched['professor_initial']); ?></small>
                                                                <?php endif; ?>
                                                            <?php else: ?>
                                                                <span class="text-muted"><?php echo htmlspecialchars($sched['professor_initial']) ?: 'TBA'; ?></span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($sched['section_name']); ?></span></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot class="table-secondary">
                                                <tr>
                                                    <td colspan="2" class="text-end"><strong>Total Units:</strong></td>
                                                    <td class="text-center"><strong><?php echo array_sum(array_column($term['schedules'], 'units')); ?></strong></td>
                                                    <td colspan="5"></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                        <?php endif; ?>
            </div>
            
            <?php if ($can_enroll_next): ?>
            <!-- Next Enrollment Section -->
            <div id="next-enrollment" class="content-section" style="display: none;">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <h2><i class="fas fa-calendar-plus me-2"></i>Enroll for Next Semester</h2>
                        <p class="text-muted">Select sections for <?php echo htmlspecialchars($next_enrollment_info['year_level'] . ' - ' . $next_enrollment_info['semester']); ?></p>
                    </div>
                </div>
                
                <?php
                // Get current program from enrollment info
                $current_program = $enrollment_info['display_program'] ?? $enrollment_info['course'] ?? '';
                $next_year = $next_enrollment_info['year_level'];
                $next_semester = $next_enrollment_info['semester'];
                
                // Get available sections for next semester
                $sections_query = "SELECT 
                    s.id,
                    s.section_name,
                    s.year_level,
                    s.semester,
                    s.academic_year,
                    s.max_capacity,
                    s.current_enrolled,
                    s.section_type,
                    p.program_code,
                    p.program_name
                FROM sections s
                JOIN programs p ON s.program_id = p.id
                WHERE s.year_level = :year_level
                AND s.semester = :semester
                ORDER BY p.program_code, s.section_name";
                
                $sections_stmt = $conn->prepare($sections_query);
                $sections_stmt->bindParam(':year_level', $next_year);
                $sections_stmt->bindParam(':semester', $next_semester);
                $sections_stmt->execute();
                $available_sections = $sections_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // Check if student already enrolled for next semester
                $check_next_enrollment = "SELECT se.*, s.section_name, s.year_level, s.semester
                                         FROM section_enrollments se
                                         JOIN sections s ON se.section_id = s.id
                                         WHERE se.user_id = :user_id
                                         AND s.year_level = :year_level
                                         AND s.semester = :semester
                                         AND se.status = 'active'";
                $check_stmt = $conn->prepare($check_next_enrollment);
                $check_stmt->bindParam(':user_id', $_SESSION['user_id']);
                $check_stmt->bindParam(':year_level', $next_year);
                $check_stmt->bindParam(':semester', $next_semester);
                $check_stmt->execute();
                $next_enrollments = $check_stmt->fetchAll(PDO::FETCH_ASSOC);
                ?>
                
                <?php if (count($next_enrollments) > 0): ?>
                    <!-- Already Enrolled -->
                    <div class="alert alert-success">
                        <h5><i class="fas fa-check-circle me-2"></i>You're Already Enrolled for Next Semester!</h5>
                        <p class="mb-2">You have successfully enrolled in the following sections:</p>
                        <ul class="mb-0">
                            <?php foreach ($next_enrollments as $enrollment): ?>
                                <li>
                                    <strong><?php echo htmlspecialchars($enrollment['section_name']); ?></strong>
                                    - <?php echo htmlspecialchars($enrollment['year_level'] . ' - ' . $enrollment['semester']); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php else: ?>
                    <!-- Enrollment Form -->
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Next Enrollment Period:</strong> You can now enroll for <?php echo htmlspecialchars($next_year . ' - ' . $next_semester); ?>
                    </div>
                    
                    <!-- Filters -->
                    <div class="card mb-3">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-filter me-2"></i>Filter Sections</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <label class="form-label">Program</label>
                                    <select class="form-select" id="filter_next_program" onchange="filterNextSections()">
                                        <option value="">All Programs</option>
                                        <?php
                                        $programs_list = [];
                                        foreach ($available_sections as $sec) {
                                            if (!in_array($sec['program_code'], $programs_list)) {
                                                $programs_list[] = $sec['program_code'];
                                                $selected = ($sec['program_code'] === $current_program) ? 'selected' : '';
                                                echo '<option value="' . htmlspecialchars($sec['program_code']) . '" ' . $selected . '>' . htmlspecialchars($sec['program_code']) . '</option>';
                                            }
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Section Type</label>
                                    <select class="form-select" id="filter_next_type" onchange="filterNextSections()">
                                        <option value="">All Types</option>
                                        <option value="Regular">Regular</option>
                                        <option value="Irregular">Irregular</option>
                                        <option value="Special">Special</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Search Section</label>
                                    <input type="text" class="form-control" id="search_next_section" placeholder="Search..." onkeyup="filterNextSections()">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Available Sections -->
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-list me-2"></i>Available Sections</h5>
                        </div>
                        <div class="card-body">
                            <div id="next_sections_list" class="row">
                                <?php if (count($available_sections) > 0): ?>
                                    <?php foreach ($available_sections as $section): ?>
                                        <?php
                                        $is_full = $section['current_enrolled'] >= $section['max_capacity'];
                                        $percentage = ($section['max_capacity'] > 0) ? ($section['current_enrolled'] / $section['max_capacity']) * 100 : 0;
                                        ?>
                                        <div class="col-md-6 mb-3 section-card" 
                                             data-program="<?php echo htmlspecialchars($section['program_code']); ?>"
                                             data-type="<?php echo htmlspecialchars($section['section_type']); ?>"
                                             data-name="<?php echo htmlspecialchars($section['section_name']); ?>">
                                            <div class="card h-100 <?php echo $is_full ? 'border-danger' : 'border-primary'; ?>">
                                                <div class="card-body">
                                                    <h5 class="card-title">
                                                        <?php echo htmlspecialchars($section['section_name']); ?>
                                                        <?php if ($is_full): ?>
                                                            <span class="badge bg-danger ms-2">Full</span>
                                                        <?php endif; ?>
                                                    </h5>
                                                    <p class="mb-2">
                                                        <i class="fas fa-graduation-cap me-2"></i>
                                                        <strong><?php echo htmlspecialchars($section['program_code']); ?></strong>
                                                        - <?php echo htmlspecialchars($section['program_name']); ?>
                                                    </p>
                                                    <p class="mb-2">
                                                        <i class="fas fa-calendar me-2"></i>
                                                        <?php echo htmlspecialchars($section['year_level'] . ' - ' . $section['semester']); ?>
                                                    </p>
                                                    <p class="mb-2">
                                                        <i class="fas fa-clock me-2"></i>
                                                        <?php echo htmlspecialchars($section['academic_year']); ?>
                                                    </p>
                                                    <p class="mb-2">
                                                        <i class="fas fa-tag me-2"></i>
                                                        Type: <?php echo htmlspecialchars($section['section_type']); ?>
                                                    </p>
                                                    <div class="mb-3">
                                                        <small class="text-muted">Capacity:</small>
                                                        <div class="progress">
                                                            <div class="progress-bar <?php echo $percentage >= 90 ? 'bg-danger' : ($percentage >= 70 ? 'bg-warning' : 'bg-success'); ?>" 
                                                                 style="width: <?php echo $percentage . '%'; ?>">
                                                                <?php echo $section['current_enrolled']; ?> / <?php echo $section['max_capacity']; ?>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <?php if (!$is_full): ?>
                                                        <button class="btn btn-primary w-100" 
                                                                onclick="enrollNextSemester(<?php echo $section['id']; ?>, '<?php echo htmlspecialchars($section['section_name'], ENT_QUOTES); ?>')">
                                                            <i class="fas fa-check me-2"></i>Enroll in This Section
                                                        </button>
                                                    <?php else: ?>
                                                        <button class="btn btn-secondary w-100" disabled>
                                                            <i class="fas fa-times me-2"></i>Section Full
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <div class="col-12">
                                        <div class="alert alert-warning">
                                            <i class="fas fa-exclamation-triangle me-2"></i>
                                            No sections available for <?php echo htmlspecialchars($next_year . ' - ' . $next_semester); ?> yet.
                                            Please check back later.
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Chatbot Floating Button -->
    <button id="chatbotToggle" class="chatbot-toggle" onclick="toggleChatbot()">
        <img src="../public/assets/images/chatbot_icon.png"
             alt="Chatbot"
             class="chatbot-toggle-icon"
             onerror="this.style.display='none'; document.getElementById('chatbotToggleFallback').classList.remove('d-none');">
        <i id="chatbotToggleFallback" class="fas fa-comments d-none"></i>
    </button>
    
    <!-- Chatbot Widget -->
    <div id="chatbotWidget" class="chatbot-widget" style="display: none;">
        <div class="chatbot-header">
            <h6 class="mb-0 d-flex align-items-center gap-2">
                <img src="../public/assets/images/chatbot_icon.png"
                     alt="Chatbot"
                     class="chatbot-icon-img"
                     onerror="this.style.display='none'; document.getElementById('chatbotHeaderFallback').classList.remove('d-none');">
                <i id="chatbotHeaderFallback" class="fas fa-robot d-none"></i>
                <span>Ask me anything!</span>
            </h6>
            <button class="btn-close btn-close-white" onclick="toggleChatbot()"></button>
        </div>
        <div class="chatbot-body" id="chatbotBody">
            <div class="chatbot-welcome">
                <div class="text-center mb-3">
                    <img src="../public/assets/images/chatbot_icon.png"
                         alt="Chatbot"
                         class="chatbot-welcome-img"
                         onerror="this.style.display='none'; document.getElementById('chatbotWelcomeFallback').classList.remove('d-none');">
                    <i id="chatbotWelcomeFallback" class="fas fa-robot fa-3x text-primary d-none"></i>
                </div>
                <h6>Hello! I'm your virtual assistant James</h6>
                <p class="small text-muted">I can help you with:</p>
                <ul class="small text-muted">
                    <li>Enrollment information</li>
                    <li>Document requirements</li>
                    <li>Schedule inquiries</li>
                    <li>General questions</li>
                </ul>
                <p class="small"><strong>Type your question below or browse categories:</strong></p>
                <div id="categoriesContainer"></div>
            </div>
            <div id="chatMessages"></div>
        </div>
        <div class="chatbot-footer">
            <form id="chatForm" onsubmit="sendMessage(event)">
                <div class="input-group">
                    <input type="text" class="form-control" id="chatInput" placeholder="Type your question..." required>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleDropdown(element) {
            const navItem = element.closest('.nav-item');
            const isActive = navItem.classList.contains('active');
            
            // Close all other dropdowns
            document.querySelectorAll('.nav-item.has-dropdown').forEach(item => {
                if (item !== navItem) {
                    item.classList.remove('active');
                }
            });
            
            // Toggle current dropdown
            navItem.classList.toggle('active');
        }
        
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('active');
        }
        
        function showSection(sectionId) {
            // Hide all sections
            document.querySelectorAll('.content-section').forEach(section => {
                section.style.display = 'none';
            });
            
            // Remove active class from all nav links
            document.querySelectorAll('.nav-link').forEach(link => {
                link.classList.remove('active');
            });
            
            // Show selected section
            const targetSection = document.getElementById(sectionId);
            if (targetSection) {
                targetSection.style.display = 'block';
            }
            
            // Add active class to clicked nav link
            if (event && event.target) {
                const clickedLink = event.target.closest('.nav-link');
                if (clickedLink) {
                    clickedLink.classList.add('active');
                    // Also activate parent if it's in a dropdown
                    const parentItem = clickedLink.closest('.nav-item.has-dropdown');
                    if (parentItem) {
                        parentItem.classList.add('active');
                    }
                }
            }
            
            // Close sidebar on mobile after selection
            if (window.innerWidth <= 767) {
                const sidebar = document.getElementById('sidebar');
                sidebar.classList.remove('active');
            }
        }
        
        // Chatbot functions
        function toggleChatbot() {
            const widget = document.getElementById('chatbotWidget');
            if (widget.style.display === 'none') {
                widget.style.display = 'flex';
                loadCategories();
            } else {
                widget.style.display = 'none';
            }
        }
        
        function loadCategories() {
            fetch('chatbot_query.php?action=get_categories')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const container = document.getElementById('categoriesContainer');
                        container.innerHTML = '';
                        
                        Object.keys(data.categories).forEach(category => {
                            const btn = document.createElement('span');
                            btn.className = 'category-btn';
                            btn.textContent = category;
                            btn.onclick = () => showCategory(category, data.categories[category]);
                            container.appendChild(btn);
                        });
                    }
                })
                .catch(error => console.error('Error:', error));
        }
        
        function showCategory(category, faqs) {
            const welcome = document.querySelector('.chatbot-welcome');
            welcome.style.display = 'none';
            
            const chatMessages = document.getElementById('chatMessages');
            chatMessages.innerHTML = '<div class="mb-3"><button class="btn btn-sm btn-outline-secondary" onclick="resetChat()"><i class="fas fa-arrow-left me-1"></i>Back</button></div>';
            
            const categoryDiv = document.createElement('div');
            categoryDiv.innerHTML = `<h6 class="mb-3"><i class="fas fa-folder me-2"></i>${category}</h6>`;
            
            faqs.forEach(faq => {
                const questionDiv = document.createElement('div');
                questionDiv.className = 'quick-question';
                questionDiv.textContent = faq.question;
                questionDiv.onclick = () => selectQuestion(faq.question);
                categoryDiv.appendChild(questionDiv);
            });
            
            chatMessages.appendChild(categoryDiv);
        }
        
        function resetChat() {
            document.querySelector('.chatbot-welcome').style.display = 'block';
            document.getElementById('chatMessages').innerHTML = '';
        }
        
        function selectQuestion(question) {
            document.getElementById('chatInput').value = question;
            const event = new Event('submit', { bubbles: true, cancelable: true });
            document.getElementById('chatForm').dispatchEvent(event);
        }
        
        function sendMessage(event) {
            event.preventDefault();
            
            const input = document.getElementById('chatInput');
            const question = input.value.trim();
            
            if (!question) return;
            
            // Hide welcome message
            document.querySelector('.chatbot-welcome').style.display = 'none';
            
            // Display user message
            addMessage(question, 'user');
            input.value = '';
            
            // Show typing indicator
            const chatMessages = document.getElementById('chatMessages');
            const typingDiv = document.createElement('div');
            typingDiv.className = 'chat-message message-bot';
            typingDiv.id = 'typingIndicator';
            typingDiv.innerHTML = '<div class="typing-indicator"><span></span><span></span><span></span></div>';
            chatMessages.appendChild(typingDiv);
            chatMessages.scrollTop = chatMessages.scrollHeight;
            
            // Send query to server
            const formData = new FormData();
            formData.append('action', 'search');
            formData.append('query', question);
            
            fetch('chatbot_query.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                // Remove typing indicator
                document.getElementById('typingIndicator')?.remove();
                
                if (data.success) {
                    if (data.results && data.results.length > 0) {
                        // Display best match
                        const answer = data.results[0].answer;
                        addMessage(answer, 'bot');
                        
                        // Show other matches if available
                        if (data.results.length > 1) {
                            const moreDiv = document.createElement('div');
                            moreDiv.className = 'chat-message message-bot';
                            moreDiv.innerHTML = '<div class="message-bubble"><small><strong>Related questions:</strong></small></div>';
                            
                            const relatedDiv = document.createElement('div');
                            relatedDiv.className = 'mt-2';
                            data.results.slice(1, 3).forEach(result => {
                                const relatedQ = document.createElement('div');
                                relatedQ.className = 'quick-question';
                                relatedQ.textContent = result.question;
                                relatedQ.onclick = () => selectQuestion(result.question);
                                relatedDiv.appendChild(relatedQ);
                            });
                            
                            moreDiv.appendChild(relatedDiv);
                            chatMessages.appendChild(moreDiv);
                        }
                    } else {
                        // No results
                        addMessage(data.message || 'I couldn\'t find an answer to that question. Please contact the admin for assistance.', 'bot');
                    }
                } else {
                    addMessage('Sorry, something went wrong. Please try again.', 'bot');
                }
                
                chatMessages.scrollTop = chatMessages.scrollHeight;
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('typingIndicator')?.remove();
                addMessage('Sorry, I\'m having trouble connecting. Please try again later.', 'bot');
            });
        }
        
        function addMessage(text, type) {
            const chatMessages = document.getElementById('chatMessages');
            const messageDiv = document.createElement('div');
            messageDiv.className = `chat-message message-${type}`;
            
            const bubble = document.createElement('div');
            bubble.className = 'message-bubble';
            bubble.textContent = text;
            
            messageDiv.appendChild(bubble);
            
            const time = document.createElement('div');
            time.className = 'message-time';
            time.textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
            messageDiv.appendChild(time);
            
            chatMessages.appendChild(messageDiv);
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }
        
        // Next Enrollment Functions
        function filterNextSections() {
            const programFilter = document.getElementById('filter_next_program').value.toLowerCase();
            const typeFilter = document.getElementById('filter_next_type').value.toLowerCase();
            const searchText = document.getElementById('search_next_section').value.toLowerCase();
            
            const sectionCards = document.querySelectorAll('#next_sections_list .section-card');
            
            sectionCards.forEach(card => {
                const program = card.getAttribute('data-program').toLowerCase();
                const type = card.getAttribute('data-type').toLowerCase();
                const name = card.getAttribute('data-name').toLowerCase();
                
                const programMatch = !programFilter || program === programFilter;
                const typeMatch = !typeFilter || type === typeFilter;
                const searchMatch = !searchText || name.includes(searchText);
                
                if (programMatch && typeMatch && searchMatch) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }
        
        function enrollNextSemester(sectionId, sectionName) {
            if (!confirm('Are you sure you want to enroll in ' + sectionName + ' for next semester?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('section_id', sectionId);
            
            fetch('process_next_enrollment.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Success! You have been enrolled for next semester.');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while enrolling. Please try again.');
            });
        }
        
        // Document Upload Functions
        let currentDocumentType = '';
        
        function uploadDocument(docType) {
            currentDocumentType = docType;
            document.getElementById('documentFileInput').click();
        }
        
        function replaceDocument(docType) {
            if (confirm('Are you sure you want to replace this document? The old file will be overwritten.')) {
                uploadDocument(docType);
            }
        }
        
        // Handle file selection
        document.getElementById('documentFileInput')?.addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (!file) return;
            
            // Validate file size (5MB max)
            if (file.size > 5 * 1024 * 1024) {
                alert('File size exceeds 5MB limit. Please choose a smaller file.');
                e.target.value = '';
                return;
            }
            
            // Validate file type
            const allowedTypes = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
            if (!allowedTypes.includes(file.type)) {
                alert('Invalid file type. Only PDF, JPG, and PNG files are allowed.');
                e.target.value = '';
                return;
            }
            
            // Show loading indicator
            const uploadBtn = document.querySelector(`button[onclick="uploadDocument('${currentDocumentType}')"]`) ||
                             document.querySelector(`button[onclick="replaceDocument('${currentDocumentType}')"]`);
            if (uploadBtn) {
                uploadBtn.disabled = true;
                uploadBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i> Uploading...';
            }
            
            // Create form data and upload
            const formData = new FormData();
            formData.append('document', file);
            formData.append('document_type', currentDocumentType);
            
            fetch('upload_document.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Document uploaded successfully! The Admission Office will review it soon.');
                    location.reload();
                } else {
                    alert('Upload failed: ' + data.message);
                    if (uploadBtn) {
                        uploadBtn.disabled = false;
                        uploadBtn.innerHTML = '<i class="fas fa-upload me-1"></i> Upload Document';
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred while uploading. Please try again.');
                if (uploadBtn) {
                    uploadBtn.disabled = false;
                    uploadBtn.innerHTML = '<i class="fas fa-upload me-1"></i> Upload Document';
                }
            })
            .finally(() => {
                e.target.value = ''; // Reset file input
            });
        });
    </script>
    
    <?php inject_session_js(); ?>
</body>
</html>
