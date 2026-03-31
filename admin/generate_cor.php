<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/Admin.php';
require_once '../classes/Curriculum.php';
require_once '../classes/Section.php';
require_once '../classes/User.php';
require_once 'enrollment_workflow_helper.php';

if (!isLoggedIn() || (!isAdmin() && !isRegistrarStaff())) {
    redirect('public/login.php');
}

$curriculumModel = new Curriculum();
$sectionModel = new Section();
$userModel = new User();
$database = new Database();
$conn = $database->getConnection();

$programs = $curriculumModel->getAllPrograms();
$sections = $sectionModel->getAllSections();

$errors = [];
$corData = null;

$selected_program_id = '';
$selected_section_id = '';
$selected_student_id = '';
$student_number = '';
$student_last_name = '';
$student_first_name = '';
$student_middle_name = '';
$student_address = '';
$registration_date = date('Y-m-d');
$academic_year = '';
$year_level = '';
$semester = '';
$college_name = 'One Cainta College';
$registrar_name = 'Mr. Christopher De Veyra';
$dean_name = 'Dr. Cristine M. Tabien';
$adviser_name = '';
$section_students = [];
$selected_student_data = null;
$next_semester_id = null; // Initialize to avoid undefined variable warnings

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['user_id'])) {
    $prefill_user_id = (int)$_GET['user_id'];
    if ($prefill_user_id > 0) {
        $prefill_user = $userModel->getUserById($prefill_user_id);
        if ($prefill_user) {
            $selected_student_id = (int)$prefill_user['id'];
            if (empty($student_number)) {
                $student_number = $prefill_user['student_id'] ?? '';
            }
            $student_last_name = $prefill_user['last_name'] ?? $student_last_name;
            $student_first_name = $prefill_user['first_name'] ?? $student_first_name;
            $student_middle_name = $prefill_user['middle_name'] ?? $student_middle_name;
            if (empty($student_address)) {
                $student_address = $prefill_user['permanent_address'] 
                    ?: $prefill_user['address'] 
                    ?: $student_address;
            }
            if (!empty($prefill_user['created_at'])) {
                $registration_date = substr($prefill_user['created_at'], 0, 10);
            }

            // Check if this is for regenerating after adjustments - use current student_schedules
            $regenerate_after_adjustment = isset($_GET['regenerate_after_adjustment']) || isset($_POST['regenerate_after_adjustment']);
            
            if ($regenerate_after_adjustment) {
                // Get the current semester and section from student_schedules (most accurate after adjustments)
                // Hierarchy: 1st Year First > 1st Year Second > 2nd Year First > 2nd Year Second > etc.
                // Most recent = highest year level, then highest semester within that year
                $current_semester_query = "SELECT DISTINCT s.academic_year, s.semester, s.year_level, s.id as section_id, s.program_id, s.section_name
                                          FROM student_schedules sts
                                          JOIN section_schedules ss ON sts.section_schedule_id = ss.id
                                          JOIN sections s ON ss.section_id = s.id
                                          WHERE sts.user_id = :user_id
                                          AND sts.status = 'active'
                                          ORDER BY s.academic_year DESC,
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
                                              END DESC
                                          LIMIT 1";
                $current_semester_stmt = $conn->prepare($current_semester_query);
                $current_semester_stmt->bindParam(':user_id', $prefill_user_id, PDO::PARAM_INT);
                $current_semester_stmt->execute();
                $current_semester = $current_semester_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($current_semester) {
                    $selected_section_id = (int)$current_semester['section_id'];
                    $selected_program_id = (int)$current_semester['program_id'];
                    $academic_year = $current_semester['academic_year'];
                    $year_level = $current_semester['year_level'] ?? '';
                    $semester = $current_semester['semester'] ?? '';
                }
            } else {
                // Get current enrollment from most recent COR (using academic progression hierarchy)
                // Hierarchy: 1st Year First > 1st Year Second > 2nd Year First > 2nd Year Second > etc.
                $current_cor_query = "SELECT cor.academic_year, cor.semester, cor.year_level, cor.section_id, cor.program_id
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
                $current_cor_stmt = $conn->prepare($current_cor_query);
                $current_cor_stmt->bindParam(':user_id', $prefill_user_id, PDO::PARAM_INT);
                $current_cor_stmt->execute();
                $current_cor_data = $current_cor_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($current_cor_data) {
                    // Use the current COR's section, year level, and semester
                    $selected_section_id = (int)$current_cor_data['section_id'];
                    $selected_program_id = (int)$current_cor_data['program_id'];
                    $academic_year = $current_cor_data['academic_year'];
                    $year_level = $current_cor_data['year_level'] ?? '';
                    $semester = $current_cor_data['semester'] ?? '';
                } else {
                    // Fallback: Get from active student_schedules (using same hierarchy)
                    $current_schedule_query = "SELECT DISTINCT s.academic_year, s.semester, s.year_level, s.id as section_id, s.program_id
                                             FROM student_schedules sts
                                             JOIN section_schedules ss ON sts.section_schedule_id = ss.id
                                             JOIN sections s ON ss.section_id = s.id
                                             WHERE sts.user_id = :user_id
                                             AND sts.status = 'active'
                                             ORDER BY s.academic_year DESC,
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
                                                      END DESC
                                             LIMIT 1";
                    $current_schedule_stmt = $conn->prepare($current_schedule_query);
                    $current_schedule_stmt->bindParam(':user_id', $prefill_user_id, PDO::PARAM_INT);
                    $current_schedule_stmt->execute();
                    $current_schedule_data = $current_schedule_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($current_schedule_data) {
                        $selected_section_id = (int)$current_schedule_data['section_id'];
                        $selected_program_id = (int)$current_schedule_data['program_id'];
                        $academic_year = $current_schedule_data['academic_year'];
                        $year_level = $current_schedule_data['year_level'] ?? '';
                        $semester = $current_schedule_data['semester'] ?? '';
                    } else {
                        // Final fallback: Get from section_enrollments
                        $section_stmt = $conn->prepare("SELECT se.section_id, s.program_id, s.year_level, s.semester, s.academic_year
                                                       FROM section_enrollments se
                                                       JOIN sections s ON se.section_id = s.id
                                                       WHERE se.user_id = :user_id 
                                                       AND se.status IN ('active', 'pending')
                                                       ORDER BY s.academic_year DESC,
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
                                                       LIMIT 1");
                        $section_stmt->bindValue(':user_id', $prefill_user_id, PDO::PARAM_INT);
                        $section_stmt->execute();
                        $active_section_data = $section_stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($active_section_data) {
                            $selected_section_id = (int)$active_section_data['section_id'];
                            $selected_program_id = (int)$active_section_data['program_id'];
                            $academic_year = $active_section_data['academic_year'] ?? $academic_year;
                            $year_level = $active_section_data['year_level'] ?? '';
                            $semester = $active_section_data['semester'] ?? '';
                        }
                    }
                }
            }

            // Fallback to preferred program if no active section found.
            if (empty($selected_program_id) && !empty($prefill_user['preferred_program'])) {
                $preferred_normalized = strtolower(trim($prefill_user['preferred_program']));
                foreach ($programs as $program) {
                    $program_name_normalized = strtolower(trim($program['program_name']));
                    $program_code_normalized = strtolower(trim($program['program_code']));
                    if ($preferred_normalized === $program_name_normalized || $preferred_normalized === $program_code_normalized ||
                        strpos($program_name_normalized, $preferred_normalized) !== false || strpos($preferred_normalized, $program_name_normalized) !== false) {
                        $selected_program_id = (int)$program['id'];
                        break;
                    }
                }
            }
        }
    }
    
    // Handle next semester enrollment pre-fill
    if (isset($_GET['next_semester_id']) && !empty($_GET['next_semester_id'])) {
        $next_semester_id = (int)$_GET['next_semester_id'];
        
        $next_sem_query = "SELECT nse.*, s.program_id, s.year_level, s.semester, s.academic_year, s.section_name,
                          p.program_code, p.program_name
                          FROM next_semester_enrollments nse
                          JOIN sections s ON nse.selected_section_id = s.id
                          JOIN programs p ON s.program_id = p.id
                          WHERE nse.id = :next_semester_id
                          AND nse.user_id = :user_id";
        $next_sem_stmt = $conn->prepare($next_sem_query);
        $next_sem_stmt->bindParam(':next_semester_id', $next_semester_id);
        $next_sem_stmt->bindParam(':user_id', $prefill_user_id);
        $next_sem_stmt->execute();
        $next_sem_data = $next_sem_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($next_sem_data) {
            // Pre-fill section and program from next semester enrollment
            $selected_section_id = (int)$next_sem_data['selected_section_id'];
            $selected_program_id = (int)$next_sem_data['program_id'];
            $academic_year = $next_sem_data['target_academic_year'] ?? $next_sem_data['academic_year'] ?? $academic_year;
            
            // Store next_semester_id for later use in subject selection
            $GLOBALS['next_semester_enrollment_id'] = $next_semester_id;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selected_program_id = isset($_POST['program_id']) ? (int)$_POST['program_id'] : 0;
    $selected_section_id = isset($_POST['section_id']) ? (int)$_POST['section_id'] : 0;
    $selected_student_id = isset($_POST['selected_student_id']) ? (int)$_POST['selected_student_id'] : 0;

    $student_number = sanitizeInput($_POST['student_number'] ?? '');
    $student_last_name = sanitizeInput($_POST['student_last_name'] ?? '');
    $student_first_name = sanitizeInput($_POST['student_first_name'] ?? '');
    $student_middle_name = sanitizeInput($_POST['student_middle_name'] ?? '');
    $student_address = sanitizeInput($_POST['student_address'] ?? '');
    $registration_date = $_POST['registration_date'] ?? date('Y-m-d');
    $academic_year = sanitizeInput($_POST['academic_year'] ?? '');
    $college_name = sanitizeInput($_POST['college_name'] ?? 'One Cainta College');
    $registrar_name = sanitizeInput($_POST['registrar_name'] ?? 'Mr. Christopher De Veyra');
    $dean_name = sanitizeInput($_POST['dean_name'] ?? 'Dr. Cristine M. Tabien');
    $adviser_name = sanitizeInput($_POST['adviser_name'] ?? '');

    $program = $curriculumModel->getProgramById($selected_program_id);
    if (!$program) {
        $errors[] = 'Invalid program selected.';
    }

    $section = $sectionModel->getSectionById($selected_section_id);
    if (!$section) {
        $errors[] = 'Invalid section selected.';
    } elseif (!$program || (int)$section['program_id'] !== (int)$program['id']) {
        $errors[] = 'Selected section does not belong to the chosen program.';
    }

    if ($section) {
        $section_students = $sectionModel->getStudentsInSection($selected_section_id);
        
        // Check if student_id was provided via GET parameter (pre-filled from applicant)
        $is_prefilled_from_get = isset($_GET['user_id']) && !empty($_POST['selected_student_id']) && 
                                 (int)$_POST['selected_student_id'] === (int)$_GET['user_id'];
        
        if ($selected_student_id) {
            foreach ($section_students as $student) {
                if ((int)$student['id'] === $selected_student_id) {
                    $selected_student_data = $student;
                    break;
                }
            }
            
            // If student not found in section but was pre-filled from GET parameter, 
            // allow it (for pending students who haven't been assigned a section yet)
            if (!$selected_student_data && $is_prefilled_from_get) {
                // Get student data from users table instead
                $prefill_user = $userModel->getUserById($selected_student_id);
                if ($prefill_user) {
                    $selected_student_data = [
                        'id' => $prefill_user['id'],
                        'student_id' => $prefill_user['student_id'] ?? '',
                        'last_name' => $prefill_user['last_name'] ?? '',
                        'first_name' => $prefill_user['first_name'] ?? '',
                        'middle_name' => $prefill_user['middle_name'] ?? '',
                        'permanent_address' => $prefill_user['permanent_address'] ?? $prefill_user['address'] ?? '',
                        'address' => $prefill_user['address'] ?? $prefill_user['permanent_address'] ?? ''
                    ];
                }
            } elseif (!$selected_student_data) {
                $errors[] = 'Selected student is not enrolled in the chosen section.';
            }
        }
    }

    if ($selected_student_data) {
        $student_number = $selected_student_data['student_id'] ?? $student_number;
        $student_last_name = $selected_student_data['last_name'] ?? $student_last_name;
        $student_first_name = $selected_student_data['first_name'] ?? $student_first_name;
        $student_middle_name = $selected_student_data['middle_name'] ?? $student_middle_name;
        if (empty($student_address)) {
            $student_address = $selected_student_data['permanent_address'] 
                ?: $selected_student_data['address'] 
                ?: $student_address;
        }
    }

    if (empty($student_last_name) || empty($student_first_name)) {
        $errors[] = 'Student first name and last name are required.';
    }

    if (empty($errors) && $program && $section) {
        $year_level = $section['year_level'];
        $semester = $section['semester'];
        $section_name = $section['section_name'];
        if (empty($academic_year)) {
            $academic_year = $section['academic_year'] ?? '';
        }

        // Check if this is a regeneration after adjustments - use current student_schedules
        $regenerate_after_adjustment = isset($_GET['regenerate_after_adjustment']) || isset($_POST['regenerate_after_adjustment']);
        
        if ($regenerate_after_adjustment && $selected_student_id > 0) {
            // First, get the academic_year and semester from the student's active schedules
            // Hierarchy: 1st Year First > 1st Year Second > 2nd Year First > 2nd Year Second > etc.
            // Most recent = highest year level, then highest semester within that year
            $semester_info_query = "SELECT DISTINCT s.academic_year, s.semester, s.year_level, s.id as section_id, s.program_id
                                    FROM student_schedules sts
                                    JOIN section_schedules ss ON sts.section_schedule_id = ss.id
                                    JOIN sections s ON ss.section_id = s.id
                                    WHERE sts.user_id = :user_id
                                    AND sts.status = 'active'
                                    ORDER BY s.academic_year DESC,
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
                                        END DESC
                                    LIMIT 1";
            $semester_info_stmt = $conn->prepare($semester_info_query);
            $semester_info_stmt->bindParam(':user_id', $selected_student_id, PDO::PARAM_INT);
            $semester_info_stmt->execute();
            $semester_info = $semester_info_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($semester_info) {
                // When regenerating after adjustments, first check if there's an existing COR
                // Preserve the original year_level and section_id from the existing COR
                $existing_cor_query = "SELECT id, subjects_json, year_level, section_id, section_name, academic_year, semester, program_id
                                      FROM certificate_of_registration 
                                      WHERE user_id = :user_id 
                                      ORDER BY created_at DESC
                                      LIMIT 1";
                $existing_cor_stmt = $conn->prepare($existing_cor_query);
                $existing_cor_stmt->bindParam(':user_id', $selected_student_id, PDO::PARAM_INT);
                $existing_cor_stmt->execute();
                $existing_cor = $existing_cor_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($regenerate_after_adjustment && $existing_cor) {
                    // When regenerating after adjustments, preserve original COR's year_level and section
                    $academic_year = $existing_cor['academic_year'];
                    $semester = $existing_cor['semester'];
                    $year_level = $existing_cor['year_level'];
                    $selected_section_id = (int)$existing_cor['section_id'];
                    $section_name = $existing_cor['section_name'];
                    $selected_program_id = (int)$existing_cor['program_id'];
                    // Get section object for display
                    $section = $sectionModel->getSectionById($selected_section_id);
                } else {
                    // Normal flow: Update academic_year, semester, and year_level from active schedules
                    $academic_year = $semester_info['academic_year'];
                    $semester = $semester_info['semester'];
                    $year_level = $semester_info['year_level'];
                    
                    // Update section if not set or if it doesn't match
                    if (empty($selected_section_id) || $selected_section_id != $semester_info['section_id']) {
                        $selected_section_id = $semester_info['section_id'];
                        $section = $sectionModel->getSectionById($selected_section_id);
                        if ($section) {
                            $selected_program_id = (int)$section['program_id'];
                            $section_name = $section['section_name'];
                            // Ensure semester and year_level match the section
                            $semester = $section['semester'];
                            $year_level = $section['year_level'];
                        }
                    }
                }
                
                if ($existing_cor && !empty($existing_cor['subjects_json'])) {
                    // Use subjects from existing COR (which already has adjustments applied)
                    $cor_subjects_data = json_decode($existing_cor['subjects_json'], true) ?: [];
                    $subjects = [];
                    
                    // Convert COR subjects format to match the expected format for display
                    foreach ($cor_subjects_data as $cor_subject) {
                        $schedule_days = $cor_subject['section_info']['schedule_days'] ?? [];
                        $schedule_days_map = [
                            'Monday' => 'Mon', 'Tuesday' => 'Tue', 'Wednesday' => 'Wed',
                            'Thursday' => 'Thu', 'Friday' => 'Fri', 'Saturday' => 'Sat', 'Sunday' => 'Sun'
                        ];
                        $schedule_days_short = array_map(function($day) use ($schedule_days_map) {
                            return $schedule_days_map[$day] ?? $day;
                        }, $schedule_days);
                        
                        $subjects[] = [
                            'id' => 0, // Not needed for display
                            'course_code' => $cor_subject['course_code'] ?? '',
                            'course_name' => $cor_subject['course_name'] ?? '',
                            'units' => (float)($cor_subject['units'] ?? 0),
                            'year_level' => $cor_subject['year_level'] ?? '',
                            'semester' => $cor_subject['semester'] ?? '',
                            'is_backload' => $cor_subject['is_backload'] ?? false,
                            'backload_year_level' => $cor_subject['backload_year_level'] ?? null,
                            'schedule_info' => [
                                'section_name' => $cor_subject['section_info']['section_name'] ?? '',
                                'schedule_days' => $schedule_days_short,
                                'time_start' => $cor_subject['section_info']['time_start'] ?? '',
                                'time_end' => $cor_subject['section_info']['time_end'] ?? '',
                                'room' => $cor_subject['section_info']['room'] ?? 'TBA',
                                'professor_name' => $cor_subject['section_info']['professor_name'] ?? 'TBA',
                                'professor_initial' => ''
                            ]
                        ];
                    }
                } else {
                    // Fallback: Get current enrolled subjects with full schedule details from student_schedules
                    $current_subjects_query = "SELECT DISTINCT c.id, c.course_code, c.course_name, c.units, c.year_level, c.semester,
                                              sts.schedule_monday, sts.schedule_tuesday, sts.schedule_wednesday, sts.schedule_thursday,
                                              sts.schedule_friday, sts.schedule_saturday, sts.schedule_sunday,
                                              sts.time_start, sts.time_end, sts.room, sts.professor_name, sts.professor_initial,
                                              s.section_name, s.id as section_id
                                              FROM student_schedules sts
                                              JOIN section_schedules ss ON sts.section_schedule_id = ss.id
                                              JOIN curriculum c ON ss.curriculum_id = c.id
                                              JOIN sections s ON ss.section_id = s.id
                                              WHERE sts.user_id = :user_id
                                              AND sts.status = 'active'
                                              AND s.academic_year = :academic_year
                                              AND s.semester = :semester
                                              ORDER BY c.course_code";
                    $current_subjects_stmt = $conn->prepare($current_subjects_query);
                    $current_subjects_stmt->bindParam(':user_id', $selected_student_id, PDO::PARAM_INT);
                    $current_subjects_stmt->bindParam(':academic_year', $academic_year);
                    $current_subjects_stmt->bindParam(':semester', $semester);
                    $current_subjects_stmt->execute();
                    $subjects = $current_subjects_stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Mark backload subjects if needed and prepare schedule info
                    // Only mark as backload if subject's year level is LOWER than the section's year level
                    if (!empty($subjects) && !empty($year_level)) {
                        // Convert section year level to numeric for comparison
                        $section_year_numeric = 0;
                        if (stripos($year_level, '1st') !== false || stripos($year_level, 'First') !== false) {
                            $section_year_numeric = 1;
                        } elseif (stripos($year_level, '2nd') !== false || stripos($year_level, 'Second') !== false) {
                            $section_year_numeric = 2;
                        } elseif (stripos($year_level, '3rd') !== false || stripos($year_level, 'Third') !== false) {
                            $section_year_numeric = 3;
                        } elseif (stripos($year_level, '4th') !== false || stripos($year_level, 'Fourth') !== false) {
                            $section_year_numeric = 4;
                        } elseif (stripos($year_level, '5th') !== false || stripos($year_level, 'Fifth') !== false) {
                            $section_year_numeric = 5;
                        }
                        
                        foreach ($subjects as &$subject) {
                            $subject_year_numeric = 0;
                            if (stripos($subject['year_level'], '1st') !== false || stripos($subject['year_level'], 'First') !== false) {
                                $subject_year_numeric = 1;
                            } elseif (stripos($subject['year_level'], '2nd') !== false || stripos($subject['year_level'], 'Second') !== false) {
                                $subject_year_numeric = 2;
                            } elseif (stripos($subject['year_level'], '3rd') !== false || stripos($subject['year_level'], 'Third') !== false) {
                                $subject_year_numeric = 3;
                            } elseif (stripos($subject['year_level'], '4th') !== false || stripos($subject['year_level'], 'Fourth') !== false) {
                                $subject_year_numeric = 4;
                            } elseif (stripos($subject['year_level'], '5th') !== false || stripos($subject['year_level'], 'Fifth') !== false) {
                                $subject_year_numeric = 5;
                            }
                            
                            // Only mark as backload if subject's year level is LOWER than section's year level
                            if ($subject_year_numeric > 0 && $section_year_numeric > 0 && $subject_year_numeric < $section_year_numeric) {
                                $subject['is_backload'] = true;
                                $subject['backload_year_level'] = $subject['year_level'];
                            } else {
                                $subject['is_backload'] = false;
                            }
                            
                            // Prepare schedule info for display
                            $schedule_days = [];
                            if (!empty($subject['schedule_monday'])) $schedule_days[] = 'Mon';
                            if (!empty($subject['schedule_tuesday'])) $schedule_days[] = 'Tue';
                            if (!empty($subject['schedule_wednesday'])) $schedule_days[] = 'Wed';
                            if (!empty($subject['schedule_thursday'])) $schedule_days[] = 'Thu';
                            if (!empty($subject['schedule_friday'])) $schedule_days[] = 'Fri';
                            if (!empty($subject['schedule_saturday'])) $schedule_days[] = 'Sat';
                            if (!empty($subject['schedule_sunday'])) $schedule_days[] = 'Sun';
                            
                            $subject['schedule_info'] = [
                                'section_name' => $subject['section_name'] ?? '',
                                'schedule_days' => $schedule_days,
                                'time_start' => $subject['time_start'] ?? '',
                                'time_end' => $subject['time_end'] ?? '',
                                'room' => $subject['room'] ?? 'TBA',
                                'professor_name' => $subject['professor_name'] ?? 'TBA',
                                'professor_initial' => $subject['professor_initial'] ?? 'TBA'
                            ];
                        }
                        unset($subject);
                    }
                }
            } else {
                // No active schedules found - fall back to curriculum
                $subjects = $curriculumModel->getCurriculumByYearSemester($selected_program_id, $year_level, $semester);
            }
        } elseif (isset($GLOBALS['next_semester_enrollment_id']) || isset($_POST['next_semester_id'])) {
            // Check if this is for a next semester enrollment - use selected subjects instead of curriculum
            $next_semester_id = isset($GLOBALS['next_semester_enrollment_id']) ? $GLOBALS['next_semester_enrollment_id'] : (isset($_POST['next_semester_id']) ? (int)$_POST['next_semester_id'] : null);
            
            if ($next_semester_id) {
            // Use exactly the subjects reviewed/approved in the next_semester_enrollments flow.
            // We trust the Program Head / Registrar workflow (including any prerequisite overrides),
            // so we no longer re-filter by prerequisites here.

            // Get enrollment request details and section info to determine target year level
            $enrollment_query = "SELECT nse.user_id, nse.current_year_level, nse.target_semester, nse.selected_section_id,
                                s.year_level as target_year_level
                               FROM next_semester_enrollments nse
                               LEFT JOIN sections s ON nse.selected_section_id = s.id
                               WHERE nse.id = :next_semester_id";
            $enrollment_stmt = $conn->prepare($enrollment_query);
            $enrollment_stmt->bindParam(':next_semester_id', $next_semester_id);
            $enrollment_stmt->execute();
            $enrollment_data = $enrollment_stmt->fetch(PDO::FETCH_ASSOC);
            $current_year_level = $enrollment_data['current_year_level'] ?? null;
            $target_year_level = $enrollment_data['target_year_level'] ?? $year_level ?? null;

            // Load subjects from next_semester_subject_selections (from the checklist)
            // Only get approved subjects - unchecked subjects will have status 'pending' or 'rejected' and should not appear in COR
            // This ensures COR only includes subjects that were checked/selected and approved in the "Subjects to be Enrolled" checklist
            $subjects_query = "SELECT c.id, c.course_code, c.course_name, c.units, c.year_level, c.semester
                              FROM next_semester_subject_selections nsss
                              JOIN curriculum c ON nsss.curriculum_id = c.id
                              WHERE nsss.enrollment_request_id = :next_semester_id
                              AND nsss.status = 'approved'
                              ORDER BY c.year_level, c.course_code";
            $subjects_stmt = $conn->prepare($subjects_query);
            $subjects_stmt->bindParam(':next_semester_id', $next_semester_id);
            $subjects_stmt->execute();
            $subjects = $subjects_stmt->fetchAll(PDO::FETCH_ASSOC);

            // Mark backload subjects: only if subject's year level is LOWER than target year level
            // Subjects from the target year level are regular subjects, not backload
            if (!empty($subjects) && $target_year_level) {
                // Convert year levels to numeric for comparison
                $target_year_numeric = 0;
                if (stripos($target_year_level, '1st') !== false || stripos($target_year_level, 'First') !== false) {
                    $target_year_numeric = 1;
                } elseif (stripos($target_year_level, '2nd') !== false || stripos($target_year_level, 'Second') !== false) {
                    $target_year_numeric = 2;
                } elseif (stripos($target_year_level, '3rd') !== false || stripos($target_year_level, 'Third') !== false) {
                    $target_year_numeric = 3;
                } elseif (stripos($target_year_level, '4th') !== false || stripos($target_year_level, 'Fourth') !== false) {
                    $target_year_numeric = 4;
                } elseif (stripos($target_year_level, '5th') !== false || stripos($target_year_level, 'Fifth') !== false) {
                    $target_year_numeric = 5;
                }
                
                foreach ($subjects as &$subject) {
                    $subject_year_numeric = 0;
                    if (stripos($subject['year_level'], '1st') !== false || stripos($subject['year_level'], 'First') !== false) {
                        $subject_year_numeric = 1;
                    } elseif (stripos($subject['year_level'], '2nd') !== false || stripos($subject['year_level'], 'Second') !== false) {
                        $subject_year_numeric = 2;
                    } elseif (stripos($subject['year_level'], '3rd') !== false || stripos($subject['year_level'], 'Third') !== false) {
                        $subject_year_numeric = 3;
                    } elseif (stripos($subject['year_level'], '4th') !== false || stripos($subject['year_level'], 'Fourth') !== false) {
                        $subject_year_numeric = 4;
                    } elseif (stripos($subject['year_level'], '5th') !== false || stripos($subject['year_level'], 'Fifth') !== false) {
                        $subject_year_numeric = 5;
                    }
                    
                    // Only mark as backload if subject's year level is LOWER than target year level
                    if ($subject_year_numeric > 0 && $target_year_numeric > 0 && $subject_year_numeric < $target_year_numeric) {
                        $subject['is_backload'] = true;
                        $subject['backload_year_level'] = $subject['year_level'];
                    } else {
                        $subject['is_backload'] = false;
                    }
                }
                unset($subject);
            }

            // Do NOT fall back to curriculum-based subjects
            // COR must only include subjects from the checklist (next_semester_subject_selections)
            // If no subjects are found in the checklist, that's intentional - the Program Head
            // may have unchecked all subjects or not yet made selections
            }
        } else {
            // Use regular curriculum subjects
            $subjects = $curriculumModel->getCurriculumByYearSemester($selected_program_id, $year_level, $semester);
        }

        if (empty($subjects)) {
            $errors[] = 'No subjects found for this enrollment.';
        } else {
            $total_units = 0;
            foreach ($subjects as $subject) {
                $total_units += (float)$subject['units'];
            }

            // Fetch approved adjustments ONLY for the current COR's academic year and semester
            // Adjustments should only apply to the current/most recent COR
            // DO NOT show adjustments for next semester enrollment CORs
            $adjustments = [];
            $subject_adjustment_map = []; // Map curriculum_id to adjustment type
            
            // Check if this is a next semester enrollment - if so, skip adjustments
            $is_next_semester_enrollment = isset($GLOBALS['next_semester_enrollment_id']) || isset($_POST['next_semester_id']);
            
            if (!$is_next_semester_enrollment) {
                // Get the most recent COR's academic year, semester, and created_at timestamp
                // Only show adjustments that were approved after this COR was created (latest adjustments for current COR)
                $current_cor_query = "SELECT academic_year, semester, created_at 
                                    FROM certificate_of_registration 
                                    WHERE user_id = :user_id 
                                    AND academic_year = :academic_year
                                    AND semester = :semester
                                    ORDER BY created_at DESC
                                    LIMIT 1";
                $current_cor_stmt = $conn->prepare($current_cor_query);
                $current_cor_stmt->bindParam(':user_id', $selected_student_id, PDO::PARAM_INT);
                $current_cor_stmt->bindParam(':academic_year', $academic_year);
                $current_cor_stmt->bindParam(':semester', $semester);
                $current_cor_stmt->execute();
                $current_cor_info = $current_cor_stmt->fetch(PDO::FETCH_ASSOC);
                
                // Only fetch adjustments if this COR matches the current COR's academic year and semester
                $is_current_cor = false;
                $cor_created_at = null;
                if ($current_cor_info && !empty($academic_year) && !empty($semester)) {
                    $is_current_cor = ($current_cor_info['academic_year'] === $academic_year && 
                                      $current_cor_info['semester'] === $semester);
                    $cor_created_at = $current_cor_info['created_at'] ?? null;
                }
                
                if ($selected_student_id > 0 && $is_current_cor && !empty($academic_year) && !empty($semester)) {
                // Get all approved adjustments for this student
                // Filter by matching academic year and semester from either old or new section
                // Only show adjustments for the current COR
                // Only fetch adjustments approved after the most recent COR was created
                // This ensures we only show the latest adjustments for the current COR
                $adjustments_query = "SELECT apc.*, 
                                     c.course_code, c.course_name, c.units,
                                     s_old.section_name as old_section_name,
                                     s_old.academic_year as old_academic_year,
                                     s_old.semester as old_semester,
                                     ss_old.time_start as old_time_start,
                                     ss_old.time_end as old_time_end,
                                     ss_old.room as old_room,
                                     ss_old.professor_name as old_professor_name,
                                     ss_old.schedule_monday as old_monday,
                                     ss_old.schedule_tuesday as old_tuesday,
                                     ss_old.schedule_wednesday as old_wednesday,
                                     ss_old.schedule_thursday as old_thursday,
                                     ss_old.schedule_friday as old_friday,
                                     ss_old.schedule_saturday as old_saturday,
                                     ss_old.schedule_sunday as old_sunday,
                                     s_new.section_name as new_section_name,
                                     s_new.academic_year as new_academic_year,
                                     s_new.semester as new_semester,
                                     ss_new.time_start as new_time_start,
                                     ss_new.time_end as new_time_end,
                                     ss_new.room as new_room,
                                     ss_new.professor_name as new_professor_name,
                                     ss_new.schedule_monday as new_monday,
                                     ss_new.schedule_tuesday as new_tuesday,
                                     ss_new.schedule_wednesday as new_wednesday,
                                     ss_new.schedule_thursday as new_thursday,
                                     ss_new.schedule_friday as new_friday,
                                     ss_new.schedule_saturday as new_saturday,
                                     ss_new.schedule_sunday as new_sunday,
                                     apc.remarks as student_remarks,
                                     apc.program_head_remarks,
                                     apc.dean_remarks,
                                     apc.registrar_remarks
                                     FROM adjustment_period_changes apc
                                     LEFT JOIN curriculum c ON apc.curriculum_id = c.id
                                     LEFT JOIN section_schedules ss_old ON apc.old_section_schedule_id = ss_old.id
                                     LEFT JOIN sections s_old ON ss_old.section_id = s_old.id
                                     LEFT JOIN section_schedules ss_new ON apc.new_section_schedule_id = ss_new.id
                                     LEFT JOIN sections s_new ON ss_new.section_id = s_new.id
                                     WHERE apc.user_id = :user_id
                                     AND apc.status = 'approved'
                                     AND (
                                         (s_new.academic_year = :academic_year AND s_new.semester = :semester)
                                         OR (s_old.academic_year = :academic_year AND s_old.semester = :semester)
                                     )";
                
                // Only show adjustments approved after the most recent COR was created
                if ($cor_created_at) {
                    $adjustments_query .= " AND COALESCE(apc.registrar_reviewed_at, apc.dean_reviewed_at, apc.change_date) >= :cor_created_at";
                }
                
                $adjustments_query .= " ORDER BY apc.change_date ASC";
                $adjustments_stmt = $conn->prepare($adjustments_query);
                $adjustments_stmt->bindParam(':user_id', $selected_student_id, PDO::PARAM_INT);
                $adjustments_stmt->bindParam(':academic_year', $academic_year);
                $adjustments_stmt->bindParam(':semester', $semester);
                if ($cor_created_at) {
                    $adjustments_stmt->bindParam(':cor_created_at', $cor_created_at);
                }
                $adjustments_stmt->execute();
                $adjustments = $adjustments_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                // If no adjustments found with section matching, try fetching all approved adjustments for this student
                // and filter by matching the current COR's academic_year and semester, and created after COR
                if (empty($adjustments)) {
                    $fallback_query = "SELECT apc.*, 
                                      c.course_code, c.course_name, c.units,
                                      s_old.section_name as old_section_name,
                                      s_old.academic_year as old_academic_year,
                                      s_old.semester as old_semester,
                                      ss_old.time_start as old_time_start,
                                      ss_old.time_end as old_time_end,
                                      ss_old.room as old_room,
                                      ss_old.professor_name as old_professor_name,
                                      ss_old.schedule_monday as old_monday,
                                      ss_old.schedule_tuesday as old_tuesday,
                                      ss_old.schedule_wednesday as old_wednesday,
                                      ss_old.schedule_thursday as old_thursday,
                                      ss_old.schedule_friday as old_friday,
                                      ss_old.schedule_saturday as old_saturday,
                                      ss_old.schedule_sunday as old_sunday,
                                      s_new.section_name as new_section_name,
                                      s_new.academic_year as new_academic_year,
                                      s_new.semester as new_semester,
                                      ss_new.time_start as new_time_start,
                                      ss_new.time_end as new_time_end,
                                      ss_new.room as new_room,
                                      ss_new.professor_name as new_professor_name,
                                      ss_new.schedule_monday as new_monday,
                                      ss_new.schedule_tuesday as new_tuesday,
                                      ss_new.schedule_wednesday as new_wednesday,
                                      ss_new.schedule_thursday as new_thursday,
                                      ss_new.schedule_friday as new_friday,
                                      ss_new.schedule_saturday as new_saturday,
                                      ss_new.schedule_sunday as new_sunday,
                                      apc.remarks as student_remarks,
                                      apc.program_head_remarks,
                                      apc.dean_remarks,
                                      apc.registrar_remarks
                                      FROM adjustment_period_changes apc
                                      LEFT JOIN curriculum c ON apc.curriculum_id = c.id
                                      LEFT JOIN section_schedules ss_old ON apc.old_section_schedule_id = ss_old.id
                                      LEFT JOIN sections s_old ON ss_old.section_id = s_old.id
                                      LEFT JOIN section_schedules ss_new ON apc.new_section_schedule_id = ss_new.id
                                      LEFT JOIN sections s_new ON ss_new.section_id = s_new.id
                                      WHERE apc.user_id = :user_id
                                      AND apc.status = 'approved'";
                    
                    if ($cor_created_at) {
                        $fallback_query .= " AND COALESCE(apc.registrar_reviewed_at, apc.dean_reviewed_at, apc.change_date) >= :cor_created_at";
                    }
                    
                    $fallback_query .= " ORDER BY apc.change_date DESC";
                    
                    $fallback_stmt = $conn->prepare($fallback_query);
                    $fallback_stmt->bindParam(':user_id', $selected_student_id, PDO::PARAM_INT);
                    if ($cor_created_at) {
                        $fallback_stmt->bindParam(':cor_created_at', $cor_created_at);
                    }
                    $fallback_stmt->execute();
                    $all_adjustments = $fallback_stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Filter by academic_year and semester manually - ONLY match current COR and latest adjustments
                    foreach ($all_adjustments as $adj) {
                        $adj_academic_year = $adj['new_academic_year'] ?? $adj['old_academic_year'] ?? null;
                        $adj_semester = $adj['new_semester'] ?? $adj['old_semester'] ?? null;
                        // Only include if it matches the current COR's academic_year and semester
                        if ($adj_academic_year === $academic_year && $adj_semester === $semester) {
                            $adjustments[] = $adj;
                        }
                    }
                }
                
                // Create a map of curriculum_id and course_code to adjustment type for quick lookup
                foreach ($adjustments as $adj) {
                    if (!empty($adj['curriculum_id'])) {
                        $subject_adjustment_map[$adj['curriculum_id']] = $adj['change_type'];
                    }
                    // Also map by course_code as fallback
                    if (!empty($adj['course_code'])) {
                        $subject_adjustment_map['code_' . $adj['course_code']] = $adj['change_type'];
                    }
                }
                }
            }

            $corData = [
                'college_name' => $college_name ?: 'One Cainta College',
                'program' => $program,
                'section' => $section,
                'section_name' => $section_name,
                'academic_year' => $academic_year,
                'year_level' => $year_level,
                'semester' => $semester,
                'student_number' => $student_number,
                'student_last_name' => $student_last_name,
                'student_first_name' => $student_first_name,
                'student_middle_name' => $student_middle_name,
                'student_address' => $student_address,
                'registration_date' => $registration_date,
                'registrar_name' => $registrar_name ?: 'Mr. Christopher De Veyra',
                'dean_name' => $dean_name ?: 'Dr. Cristine M. Tabien',
                'adviser_name' => $adviser_name,
                'subjects' => $subjects,
                'total_units' => $total_units,
                'prepared_by' => ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''),
                'is_revised_after_adjustment' => $regenerate_after_adjustment,
                'adjustments' => $adjustments,
                'subject_adjustment_map' => $subject_adjustment_map
            ];
            
            // Save COR to database for student access (only if student is selected)
            if ($selected_student_id > 0) {
                try {
                    // Check if this is a regeneration after adjustments
                    $regenerate_after_adjustment = isset($_GET['regenerate_after_adjustment']) || isset($_POST['regenerate_after_adjustment']);
                    
                    // Get adjustment remarks for added subjects (if regenerating after adjustments)
                    $adjustment_remarks_map = [];
                    if ($regenerate_after_adjustment) {
                        $remarks_query = "SELECT apc.curriculum_id, apc.remarks
                                         FROM adjustment_period_changes apc
                                         WHERE apc.user_id = :user_id
                                         AND apc.change_type = 'add'
                                         AND apc.status = 'approved'
                                         AND apc.remarks IS NOT NULL
                                         AND apc.remarks != ''";
                        $remarks_stmt = $conn->prepare($remarks_query);
                        $remarks_stmt->bindParam(':user_id', $selected_student_id, PDO::PARAM_INT);
                        $remarks_stmt->execute();
                        $remarks_data = $remarks_stmt->fetchAll(PDO::FETCH_ASSOC);
                        foreach ($remarks_data as $remark) {
                            $adjustment_remarks_map[$remark['curriculum_id']] = $remark['remarks'];
                        }
                    }
                    
                    // Prepare subjects data for JSON storage (include backload info and section details)
                    $subjects_data = [];
                    foreach ($subjects as $subject) {
                        // Get the actual section where this subject is scheduled
                        $subject_section_info = null;
                        $curriculum_id = $subject['id'] ?? null;
                        
                        if ($curriculum_id) {
                            // Try to get from student_schedules first (most accurate)
                            $schedule_query = "SELECT sts.*, ss.section_id, s.section_name, s.section_type,
                                              ss.time_start, ss.time_end, ss.room, ss.professor_name,
                                              ss.schedule_monday, ss.schedule_tuesday, ss.schedule_wednesday,
                                              ss.schedule_thursday, ss.schedule_friday, ss.schedule_saturday, ss.schedule_sunday
                                              FROM student_schedules sts
                                              JOIN section_schedules ss ON sts.section_schedule_id = ss.id
                                              JOIN sections s ON ss.section_id = s.id
                                              WHERE sts.user_id = :user_id
                                              AND ss.curriculum_id = :curriculum_id
                                              AND sts.status = 'active'
                                              LIMIT 1";
                            $schedule_stmt = $conn->prepare($schedule_query);
                            $schedule_stmt->bindParam(':user_id', $selected_student_id, PDO::PARAM_INT);
                            $schedule_stmt->bindParam(':curriculum_id', $curriculum_id, PDO::PARAM_INT);
                            $schedule_stmt->execute();
                            $schedule_data = $schedule_stmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($schedule_data) {
                                // Format schedule days
                                $schedule_days = [];
                                $day_map = [
                                    'schedule_monday' => 'Monday',
                                    'schedule_tuesday' => 'Tuesday',
                                    'schedule_wednesday' => 'Wednesday',
                                    'schedule_thursday' => 'Thursday',
                                    'schedule_friday' => 'Friday',
                                    'schedule_saturday' => 'Saturday',
                                    'schedule_sunday' => 'Sunday'
                                ];
                                foreach ($day_map as $field => $day_name) {
                                    if (!empty($schedule_data[$field])) {
                                        $schedule_days[] = $day_name;
                                    }
                                }
                                
                                $subject_section_info = [
                                    'section_id' => (int)$schedule_data['section_id'],
                                    'section_name' => $schedule_data['section_name'],
                                    'section_type' => $schedule_data['section_type'],
                                    'schedule_days' => $schedule_days,
                                    'time_start' => $schedule_data['time_start'],
                                    'time_end' => $schedule_data['time_end'],
                                    'room' => $schedule_data['room'],
                                    'professor_name' => $schedule_data['professor_name']
                                ];
                            } else {
                                // Fallback: try to get from section_schedules in main section
                                if ($selected_section_id) {
                                    $fallback_query = "SELECT ss.*, s.section_name, s.section_type
                                                      FROM section_schedules ss
                                                      JOIN sections s ON ss.section_id = s.id
                                                      WHERE ss.section_id = :section_id
                                                      AND ss.curriculum_id = :curriculum_id
                                                      LIMIT 1";
                                    $fallback_stmt = $conn->prepare($fallback_query);
                                    $fallback_stmt->bindParam(':section_id', $selected_section_id, PDO::PARAM_INT);
                                    $fallback_stmt->bindParam(':curriculum_id', $curriculum_id, PDO::PARAM_INT);
                                    $fallback_stmt->execute();
                                    $fallback_data = $fallback_stmt->fetch(PDO::FETCH_ASSOC);
                                    
                                    if ($fallback_data) {
                                        $schedule_days = [];
                                        $day_map = [
                                            'schedule_monday' => 'Monday',
                                            'schedule_tuesday' => 'Tuesday',
                                            'schedule_wednesday' => 'Wednesday',
                                            'schedule_thursday' => 'Thursday',
                                            'schedule_friday' => 'Friday',
                                            'schedule_saturday' => 'Saturday',
                                            'schedule_sunday' => 'Sunday'
                                        ];
                                        foreach ($day_map as $field => $day_name) {
                                            if (!empty($fallback_data[$field])) {
                                                $schedule_days[] = $day_name;
                                            }
                                        }
                                        
                                        $subject_section_info = [
                                            'section_id' => (int)$fallback_data['section_id'],
                                            'section_name' => $fallback_data['section_name'],
                                            'section_type' => $fallback_data['section_type'],
                                            'schedule_days' => $schedule_days,
                                            'time_start' => $fallback_data['time_start'],
                                            'time_end' => $fallback_data['time_end'],
                                            'room' => $fallback_data['room'],
                                            'professor_name' => $fallback_data['professor_name']
                                        ];
                                    }
                                }
                            }
                        }
                        
                        // Check if this subject was added via adjustment period
                        $is_added = false;
                        $remarks = null;
                        if ($regenerate_after_adjustment && $curriculum_id && isset($adjustment_remarks_map[$curriculum_id])) {
                            $is_added = true;
                            $remarks = $adjustment_remarks_map[$curriculum_id];
                        }
                        
                        $subjects_data[] = [
                            'course_code' => $subject['course_code'],
                            'course_name' => $subject['course_name'],
                            'units' => (float)$subject['units'],
                            'year_level' => $subject['year_level'] ?? null,
                            'semester' => $subject['semester'] ?? null,
                            'is_backload' => $subject['is_backload'] ?? false,
                            'backload_year_level' => $subject['backload_year_level'] ?? null,
                            'is_added' => $is_added,
                            'remarks' => $remarks,
                            'section_info' => $subject_section_info
                        ];
                    }
                    
                    // Check if COR already exists for this enrollment (user_id + academic_year + semester + section_id)
                    // When regenerating after adjustments, UPDATE the existing COR for the current semester
                    // This ensures adjustments only affect the current enrollment semester
                    $check_cor_query = "SELECT id FROM certificate_of_registration 
                                       WHERE user_id = :user_id 
                                       AND academic_year = :academic_year
                                       AND semester = :semester
                                       AND section_id = :section_id
                                       ORDER BY created_at DESC
                                       LIMIT 1";
                    $check_cor_stmt = $conn->prepare($check_cor_query);
                    $check_cor_stmt->bindParam(':user_id', $selected_student_id);
                    $check_cor_stmt->bindParam(':academic_year', $academic_year);
                    $check_cor_stmt->bindParam(':semester', $semester);
                    $check_cor_stmt->bindParam(':section_id', $selected_section_id);
                    $check_cor_stmt->execute();
                    $existing_cor = $check_cor_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existing_cor) {
                        // When regenerating after adjustments, preserve original year_level and section_name
                        // Only update subjects_json and other non-structural fields
                        if ($regenerate_after_adjustment) {
                            $update_cor = "UPDATE certificate_of_registration 
                                          SET student_number = :student_number,
                                              student_last_name = :student_last_name,
                                              student_first_name = :student_first_name,
                                              student_middle_name = :student_middle_name,
                                              student_address = :student_address,
                                              registration_date = :registration_date,
                                              college_name = :college_name,
                                              registrar_name = :registrar_name,
                                              dean_name = :dean_name,
                                              adviser_name = :adviser_name,
                                              prepared_by = :prepared_by,
                                              subjects_json = :subjects_json,
                                              total_units = :total_units,
                                              created_by = :created_by,
                                              created_at = NOW()
                                          WHERE id = :cor_id";
                            
                            $cor_stmt = $conn->prepare($update_cor);
                            $cor_stmt->execute([
                                ':cor_id' => $existing_cor['id'],
                                ':student_number' => $student_number,
                                ':student_last_name' => $student_last_name,
                                ':student_first_name' => $student_first_name,
                                ':student_middle_name' => $student_middle_name ?: null,
                                ':student_address' => $student_address ?: null,
                                ':registration_date' => $registration_date,
                                ':college_name' => $college_name ?: 'One Cainta College',
                                ':registrar_name' => $registrar_name ?: 'Mr. Christopher De Veyra',
                                ':dean_name' => $dean_name ?: 'Dr. Cristine M. Tabien',
                                ':adviser_name' => $adviser_name ?: null,
                                ':prepared_by' => ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''),
                                ':subjects_json' => json_encode($subjects_data, JSON_UNESCAPED_UNICODE),
                                ':total_units' => $total_units,
                                ':created_by' => $_SESSION['user_id']
                            ]);
                        } else {
                            // Normal update: Update all fields including year_level and section_name
                            $update_cor = "UPDATE certificate_of_registration 
                                          SET student_number = :student_number,
                                              student_last_name = :student_last_name,
                                              student_first_name = :student_first_name,
                                              student_middle_name = :student_middle_name,
                                              student_address = :student_address,
                                              year_level = :year_level,
                                              section_name = :section_name,
                                              registration_date = :registration_date,
                                              college_name = :college_name,
                                              registrar_name = :registrar_name,
                                              dean_name = :dean_name,
                                              adviser_name = :adviser_name,
                                              prepared_by = :prepared_by,
                                              subjects_json = :subjects_json,
                                              total_units = :total_units,
                                              created_by = :created_by,
                                              created_at = NOW()
                                          WHERE id = :cor_id";
                            
                            $cor_stmt = $conn->prepare($update_cor);
                            $cor_stmt->execute([
                                ':cor_id' => $existing_cor['id'],
                                ':student_number' => $student_number,
                                ':student_last_name' => $student_last_name,
                                ':student_first_name' => $student_first_name,
                                ':student_middle_name' => $student_middle_name ?: null,
                                ':student_address' => $student_address ?: null,
                                ':year_level' => $year_level,
                                ':section_name' => $section_name,
                                ':registration_date' => $registration_date,
                                ':college_name' => $college_name ?: 'One Cainta College',
                                ':registrar_name' => $registrar_name ?: 'Mr. Christopher De Veyra',
                                ':dean_name' => $dean_name ?: 'Dr. Cristine M. Tabien',
                                ':adviser_name' => $adviser_name ?: null,
                                ':prepared_by' => ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''),
                                ':subjects_json' => json_encode($subjects_data, JSON_UNESCAPED_UNICODE),
                                ':total_units' => $total_units,
                                ':created_by' => $_SESSION['user_id']
                            ]);
                        }
                        $cor_id = $existing_cor['id'];
                    } else {
                        // Insert new COR
                        $insert_cor = "INSERT INTO certificate_of_registration 
                            (user_id, program_id, section_id, student_number, student_last_name, 
                             student_first_name, student_middle_name, student_address, academic_year, 
                             year_level, semester, section_name, registration_date, college_name, 
                             registrar_name, dean_name, adviser_name, prepared_by, subjects_json, 
                             total_units, created_by)
                            VALUES 
                            (:user_id, :program_id, :section_id, :student_number, :student_last_name,
                             :student_first_name, :student_middle_name, :student_address, :academic_year,
                             :year_level, :semester, :section_name, :registration_date, :college_name,
                             :registrar_name, :dean_name, :adviser_name, :prepared_by, :subjects_json,
                             :total_units, :created_by)";
                        
                        $cor_stmt = $conn->prepare($insert_cor);
                        $cor_stmt->execute([
                            ':user_id' => $selected_student_id,
                            ':program_id' => $selected_program_id,
                            ':section_id' => $selected_section_id,
                            ':student_number' => $student_number,
                            ':student_last_name' => $student_last_name,
                            ':student_first_name' => $student_first_name,
                            ':student_middle_name' => $student_middle_name ?: null,
                            ':student_address' => $student_address ?: null,
                            ':academic_year' => $academic_year,
                            ':year_level' => $year_level,
                            ':semester' => $semester,
                            ':section_name' => $section_name,
                            ':registration_date' => $registration_date,
                            ':college_name' => $college_name ?: 'One Cainta College',
                            ':registrar_name' => $registrar_name ?: 'Mr. Christopher De Veyra',
                            ':dean_name' => $dean_name ?: 'Dr. Cristine M. Tabien',
                            ':adviser_name' => $adviser_name ?: null,
                            ':prepared_by' => ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''),
                            ':subjects_json' => json_encode($subjects_data, JSON_UNESCAPED_UNICODE),
                            ':total_units' => $total_units,
                            ':created_by' => $_SESSION['user_id']
                        ]);
                        $cor_id = $conn->lastInsertId();
                    }
                    
                    // Link COR to enrollment if next_semester_id exists
                    if (isset($next_semester_id) && $next_semester_id) {
                        $link_cor_query = "UPDATE certificate_of_registration 
                                          SET enrollment_id = :enrollment_id
                                          WHERE id = :cor_id";
                        $link_cor_stmt = $conn->prepare($link_cor_query);
                        $link_cor_stmt->bindParam(':enrollment_id', $next_semester_id, PDO::PARAM_INT);
                        $link_cor_stmt->bindParam(':cor_id', $cor_id, PDO::PARAM_INT);
                        $link_cor_stmt->execute();
                        
                        // Mark COR as generated in enrollment record
                        markCORGenerated($conn, $next_semester_id, $_SESSION['user_id']);
                    }
                    
                    // Set success message based on whether COR was created or updated
                    if ($regenerate_after_adjustment) {
                        $_SESSION['message'] = 'COR updated successfully with adjusted schedule for current semester!';
                    } elseif (isset($existing_cor) && $existing_cor) {
                        $_SESSION['message'] = 'COR updated successfully! Only one COR per enrollment is allowed to avoid confusion.';
                    } else {
                        $_SESSION['message'] = 'COR created successfully! Enrollment is now ready for admin final approval.';
                    }
                    
                    // Sync student_schedules to match COR subjects (only for next semester enrollments)
                    if (isset($next_semester_id) && $next_semester_id && !empty($subjects)) {
                        // Get curriculum IDs from COR subjects
                        $cor_curriculum_ids = array_column($subjects, 'id');
                        
                        if (!empty($cor_curriculum_ids)) {
                            // Get section_schedules for these curriculum IDs
                            $placeholders = implode(',', array_fill(0, count($cor_curriculum_ids), '?'));
                            $sync_schedules_query = "SELECT ss.* FROM section_schedules ss
                                                    WHERE ss.section_id = ?
                                                    AND ss.curriculum_id IN ($placeholders)";
                            $sync_schedules_stmt = $conn->prepare($sync_schedules_query);
                            $sync_schedules_stmt->bindValue(1, $selected_section_id, PDO::PARAM_INT);
                            foreach ($cor_curriculum_ids as $idx => $curriculum_id) {
                                $sync_schedules_stmt->bindValue($idx + 2, $curriculum_id, PDO::PARAM_INT);
                            }
                            $sync_schedules_stmt->execute();
                            $sync_schedules = $sync_schedules_stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            // Deactivate ALL student_schedules for subjects not in COR
                            $placeholders_deactivate = implode(',', array_fill(0, count($cor_curriculum_ids), '?'));
                            $deactivate_sync_query = "UPDATE student_schedules sts
                                                     JOIN section_schedules ss ON sts.section_schedule_id = ss.id
                                                     SET sts.status = 'dropped', sts.updated_at = NOW()
                                                     WHERE sts.user_id = ?
                                                     AND ss.section_id = ?
                                                     AND sts.status = 'active'
                                                     AND ss.curriculum_id NOT IN ($placeholders_deactivate)";
                            $deactivate_sync_stmt = $conn->prepare($deactivate_sync_query);
                            $deactivate_sync_stmt->bindValue(1, $selected_student_id, PDO::PARAM_INT);
                            $deactivate_sync_stmt->bindValue(2, $selected_section_id, PDO::PARAM_INT);
                            foreach ($cor_curriculum_ids as $idx => $curriculum_id) {
                                $deactivate_sync_stmt->bindValue($idx + 3, $curriculum_id, PDO::PARAM_INT);
                            }
                            $deactivate_sync_stmt->execute();
                            
                            // Create or reactivate student_schedules for COR subjects
                            foreach ($sync_schedules as $sync_schedule) {
                                $check_sync_stmt = $conn->prepare("SELECT id FROM student_schedules 
                                                                   WHERE user_id = :user_id 
                                                                   AND section_schedule_id = :section_schedule_id 
                                                                   AND status = 'active'");
                                $check_sync_stmt->bindParam(':user_id', $selected_student_id);
                                $check_sync_stmt->bindParam(':section_schedule_id', $sync_schedule['id']);
                                $check_sync_stmt->execute();
                                
                                if ($check_sync_stmt->rowCount() == 0) {
                                    // Insert new student_schedule
                                    $insert_sync_stmt = $conn->prepare("INSERT INTO student_schedules 
                                                                       (user_id, section_schedule_id, course_code, course_name, units,
                                                                        schedule_monday, schedule_tuesday, schedule_wednesday, schedule_thursday,
                                                                        schedule_friday, schedule_saturday, schedule_sunday,
                                                                        time_start, time_end, room, professor_name, professor_initial, status)
                                                                       VALUES 
                                                                       (:user_id, :section_schedule_id, :course_code, :course_name, :units,
                                                                        :schedule_monday, :schedule_tuesday, :schedule_wednesday, :schedule_thursday,
                                                                        :schedule_friday, :schedule_saturday, :schedule_sunday,
                                                                        :time_start, :time_end, :room, :professor_name, :professor_initial, 'active')");
                                    $insert_sync_stmt->bindParam(':user_id', $selected_student_id);
                                    $insert_sync_stmt->bindParam(':section_schedule_id', $sync_schedule['id']);
                                    $insert_sync_stmt->bindParam(':course_code', $sync_schedule['course_code']);
                                    $insert_sync_stmt->bindParam(':course_name', $sync_schedule['course_name']);
                                    $insert_sync_stmt->bindParam(':units', $sync_schedule['units']);
                                    $insert_sync_stmt->bindParam(':schedule_monday', $sync_schedule['schedule_monday']);
                                    $insert_sync_stmt->bindParam(':schedule_tuesday', $sync_schedule['schedule_tuesday']);
                                    $insert_sync_stmt->bindParam(':schedule_wednesday', $sync_schedule['schedule_wednesday']);
                                    $insert_sync_stmt->bindParam(':schedule_thursday', $sync_schedule['schedule_thursday']);
                                    $insert_sync_stmt->bindParam(':schedule_friday', $sync_schedule['schedule_friday']);
                                    $insert_sync_stmt->bindParam(':schedule_saturday', $sync_schedule['schedule_saturday']);
                                    $insert_sync_stmt->bindParam(':schedule_sunday', $sync_schedule['schedule_sunday']);
                                    $insert_sync_stmt->bindParam(':time_start', $sync_schedule['time_start']);
                                    $insert_sync_stmt->bindParam(':time_end', $sync_schedule['time_end']);
                                    $insert_sync_stmt->bindParam(':room', $sync_schedule['room']);
                                    $insert_sync_stmt->bindParam(':professor_name', $sync_schedule['professor_name']);
                                    $insert_sync_stmt->bindParam(':professor_initial', $sync_schedule['professor_initial']);
                                    $insert_sync_stmt->execute();
                                } else {
                                    // Reactivate if dropped
                                    $reactivate_sync_stmt = $conn->prepare("UPDATE student_schedules 
                                                                           SET status = 'active', updated_at = NOW()
                                                                           WHERE user_id = :user_id 
                                                                           AND section_schedule_id = :section_schedule_id
                                                                           AND status = 'dropped'");
                                    $reactivate_sync_stmt->bindParam(':user_id', $selected_student_id);
                                    $reactivate_sync_stmt->bindParam(':section_schedule_id', $sync_schedule['id']);
                                    $reactivate_sync_stmt->execute();
                                }
                            }
                        }
                    }
                } catch (PDOException $e) {
                    // Log error but don't prevent COR display
                    error_log('Error saving COR to database: ' . $e->getMessage());
                }
            }
        }
    }
}

function format_date($date)
{
    if (empty($date)) {
        return '';
    }

    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date;
    }

    return date('F j, Y', $timestamp);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate Certificate of Registration - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f2f6fc;
        }
        .page-container {
            max-width: 1200px;
            margin: 2rem auto;
        }
        .card {
            border: none;
            box-shadow: 0 10px 25px rgba(15, 27, 75, 0.08);
        }
        .cor-wrapper {
            background: white;
            padding: 40px;
            border: 1px solid #ddd;
        }
        .cor-header {
            text-align: center;
            margin-bottom: 20px;
        }
        .cor-header h2 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 0;
        }
        .cor-header h3 {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 0;
        }
        .cor-header h4 {
            font-size: 16px;
            font-weight: 600;
        }
        .cor-table th,
        .cor-table td {
            font-size: 12px;
            vertical-align: middle;
            border: 1px solid #333;
            padding: 6px;
            text-align: center;
        }
        .cor-table td.text-start {
            text-align: left;
        }
        .cor-footer {
            font-size: 12px;
            margin-top: 20px;
        }
        .signature-line {
            display: flex;
            justify-content: space-between;
            margin-top: 40px;
        }
        .signature-line .sig {
            width: 30%;
            text-align: center;
            font-size: 12px;
        }
        .signature-line .sig span {
            display: block;
            border-top: 1px solid #000;
            padding-top: 4px;
            font-weight: 600;
        }
        .adjustments-section {
            page-break-before: always;
            page-break-after: avoid;
            margin-top: 0;
        }
        @media print {
            body {
                background: white;
            }
            .no-print {
                display: none !important;
            }
            .cor-wrapper {
                border: none;
                padding: 20px;
                box-shadow: none;
            }
            .cor-wrapper:not(.print-this) {
                display: none !important;
            }
            .adjustments-section {
                page-break-before: always !important;
                page-break-after: avoid !important;
                page-break-inside: avoid !important;
                margin-top: 0 !important;
            }
            @page {
                size: letter;
                margin: 0.5in;
            }
        }
    </style>
</head>
<body>
    <div class="page-container">
        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <h1 class="h4 mb-0"><i class="fas fa-file-alt me-2 text-primary"></i>Generate Certificate of Registration</h1>
            <?php if (isRegistrarStaff()): ?>
                <a href="../registrar_staff/dashboard.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                </a>
            <?php else: ?>
                <a href="<?php echo add_session_to_url('dashboard.php'); ?>" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                </a>
            <?php endif; ?>
        </div>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <ul class="mb-0">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card mb-4 no-print">
            <div class="card-header bg-white">
                <h5 class="mb-0"><i class="fas fa-sliders-h me-2 text-primary"></i>COR Details</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <?php if (isset($GLOBALS['next_semester_enrollment_id'])): ?>
                        <input type="hidden" name="next_semester_id" value="<?php echo $GLOBALS['next_semester_enrollment_id']; ?>">
                    <?php elseif (isset($_GET['next_semester_id'])): ?>
                        <input type="hidden" name="next_semester_id" value="<?php echo (int)$_GET['next_semester_id']; ?>">
                    <?php endif; ?>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Program</label>
                            <select name="program_id" id="program_id" class="form-select" required>
                                <option value="">Select program</option>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?php echo $program['id']; ?>" <?php echo ($selected_program_id && (int)$selected_program_id === (int)$program['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($program['program_code'] . ' - ' . $program['program_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Section</label>
                            <select name="section_id" id="section_id" class="form-select" required>
                                <option value="">Select section</option>
                                <?php foreach ($sections as $section): ?>
                                    <option value="<?php echo $section['id']; ?>"
                                        data-program="<?php echo $section['program_id']; ?>"
                                        data-year="<?php echo htmlspecialchars($section['year_level']); ?>"
                                        data-semester="<?php echo htmlspecialchars($section['semester']); ?>"
                                        data-section-name="<?php echo htmlspecialchars($section['section_name']); ?>"
                                        data-academic-year="<?php echo htmlspecialchars($section['academic_year']); ?>"
                                        <?php echo ($selected_section_id && (int)$selected_section_id === (int)$section['id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($section['section_name'] . ' • ' . $section['year_level'] . ' • ' . $section['semester']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Year Level</label>
                            <input type="text" class="form-control" id="display_year_level" value="<?php echo htmlspecialchars($year_level); ?>" readonly>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Semester</label>
                            <input type="text" class="form-control" id="display_semester" value="<?php echo htmlspecialchars($semester); ?>" readonly>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Academic Year</label>
                            <input type="text" class="form-control" name="academic_year" id="academic_year"
                                   value="<?php echo htmlspecialchars($academic_year); ?>"
                                   placeholder="e.g., AY 2025-2026">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">College Name</label>
                            <input type="text" class="form-control" name="college_name"
                                   value="<?php echo htmlspecialchars($college_name); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Date of Registration</label>
                            <input type="date" class="form-control" name="registration_date"
                                   value="<?php echo htmlspecialchars($registration_date); ?>" required>
                        </div>
                    </div>

                    <hr>

                    <div class="row g-3">
                        <div class="col-lg-6">
                            <label class="form-label">Student (optional)</label>
                            <select class="form-select" name="selected_student_id" id="selected_student_id">
                                <option value="">Select student from section</option>
                            </select>
                            <div class="form-text">Selecting a student will auto-fill the dropdowns below using information from the users table.</div>
                        </div>
                        <div class="col-lg-6 col-md-4">
                            <label class="form-label">Student Number</label>
                            <select class="form-select" name="student_number" id="student_number_select" required>
                                <option value="">Select student number</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Last Name</label>
                            <select class="form-select" name="student_last_name" id="student_last_name_select" required>
                                <option value="">Select last name</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">First Name</label>
                            <select class="form-select" name="student_first_name" id="student_first_name_select" required>
                                <option value="">Select first name</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Middle Name</label>
                            <select class="form-select" name="student_middle_name" id="student_middle_name_select">
                                <option value="">Select middle name</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Address</label>
                            <select class="form-select" name="student_address" id="student_address_select">
                                <option value="">Select address</option>
                            </select>
                        </div>
                    </div>

                    <hr>

                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Dean / Director</label>
                            <input type="text" class="form-control" name="dean_name"
                                   value="<?php echo htmlspecialchars($dean_name); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">College Registrar</label>
                            <input type="text" class="form-control" name="registrar_name"
                                   value="<?php echo htmlspecialchars($registrar_name); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Adviser</label>
                            <input type="text" class="form-control" name="adviser_name"
                                   value="<?php echo htmlspecialchars($adviser_name); ?>"
                                   placeholder="Optional">
                        </div>
                    </div>

                    <div class="d-flex justify-content-end mt-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-file-alt me-2"></i>Generate COR
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($corData): ?>
            <div class="cor-wrapper">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div>
                        <strong>OCC FORM NO. 01</strong>
                    </div>
                    <div class="no-print">
                        <button class="btn btn-outline-primary print-cor-btn" data-cor-id="current">
                            <i class="fas fa-print me-2"></i>Print COR
                        </button>
                    </div>
                </div>

                <div class="cor-header">
                    <h2><?php echo htmlspecialchars(strtoupper($corData['college_name'])); ?></h2>
                    <h3>Office of the College Registrar</h3>
                    <h4><?php echo htmlspecialchars($corData['program']['program_name']); ?></h4>
                    <p class="mb-0 fw-bold">CERTIFICATE OF REGISTRATION</p>
                    <?php if (!empty($corData['is_revised_after_adjustment'])): ?>
                        <p class="mb-0 mt-2" style="font-size: 12px; color: #d9534f; font-weight: 600;">
                            <i class="fas fa-info-circle me-1"></i>REVISED - Updated after Adjustment Period
                        </p>
                    <?php endif; ?>
                </div>

                <table class="table table-bordered mb-3" style="font-size: 13px;">
                    <tbody>
                        <tr>
                            <td style="width: 20%;">STUDENT NUMBER</td>
                            <td style="width: 30%;"><strong><?php echo htmlspecialchars($corData['student_number'] ?: ''); ?></strong></td>
                            <td style="width: 20%;">COURSE</td>
                            <td style="width: 30%;"><strong><?php echo htmlspecialchars($corData['program']['program_code']); ?></strong></td>
                        </tr>
                        <tr>
                            <td>YEAR &amp; SECTION</td>
                            <td><strong><?php echo htmlspecialchars($corData['year_level'] . ' - ' . $corData['section_name']); ?></strong></td>
                            <td>SEMESTER</td>
                            <td><strong><?php echo htmlspecialchars($corData['semester']); ?></strong></td>
                        </tr>
                        <tr>
                            <td>SCHOOL YEAR</td>
                            <td><strong><?php echo htmlspecialchars($corData['academic_year']); ?></strong></td>
                            <td>DATE OF REGISTRATION</td>
                            <td><strong><?php echo htmlspecialchars(format_date($corData['registration_date'])); ?></strong></td>
                        </tr>
                    </tbody>
                </table>

                <table class="table table-bordered mb-3" style="font-size: 13px;">
                    <tbody>
                        <tr>
                            <td style="width: 10%;">LAST NAME</td>
                            <td style="width: 23%;"><strong><?php echo htmlspecialchars($corData['student_last_name']); ?></strong></td>
                            <td style="width: 10%;">FIRST NAME</td>
                            <td style="width: 23%;"><strong><?php echo htmlspecialchars($corData['student_first_name']); ?></strong></td>
                            <td style="width: 10%;">MIDDLE NAME</td>
                            <td style="width: 24%;"><strong><?php echo htmlspecialchars($corData['student_middle_name']); ?></strong></td>
                        </tr>
                        <tr>
                            <td>ADDRESS</td>
                            <td colspan="5"><strong><?php echo htmlspecialchars($corData['student_address']); ?></strong></td>
                        </tr>
                    </tbody>
                </table>

                <table class="table cor-table">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 9%;">Code</th>
                            <th style="width: 23%;">Subject Title</th>
                            <th style="width: 7%;">Sec.</th>
                            <th style="width: 6%;">Units/Hrs.</th>
                            <th style="width: 6%;">M</th>
                            <th style="width: 6%;">T</th>
                            <th style="width: 6%;">W</th>
                            <th style="width: 6%;">TH</th>
                            <th style="width: 6%;">F</th>
                            <th style="width: 6%;">Sat</th>
                            <th style="width: 6%;">Sun</th>
                            <th style="width: 7%;">Rm.</th>
                            <th style="width: 6%;">Prof's Initial</th>
                            <?php if (!empty($corData['adjustments']) && is_array($corData['adjustments']) && count($corData['adjustments']) > 0): ?>
                                <th style="width: 8%;">Remarks</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($corData['subjects'] as $subject): ?>
                            <?php
                            // Check if this subject has an adjustment
                            $curriculum_id = $subject['id'] ?? null;
                            $course_code = $subject['course_code'] ?? null;
                            $adjustment_type = null;
                            
                            // First try to match by curriculum_id
                            if ($curriculum_id && !empty($corData['subject_adjustment_map'][$curriculum_id])) {
                                $adjustment_type = $corData['subject_adjustment_map'][$curriculum_id];
                            }
                            // Fallback to course_code if curriculum_id not available or not found
                            elseif ($course_code && !empty($corData['subject_adjustment_map']['code_' . $course_code])) {
                                $adjustment_type = $corData['subject_adjustment_map']['code_' . $course_code];
                            }
                            ?>
                            <tr <?php echo (!empty($subject['is_backload']) && $subject['is_backload']) ? 'class="table-warning"' : ''; ?>>
                                <td>
                                    <?php echo htmlspecialchars($subject['course_code']); ?>
                                    <?php if (!empty($subject['is_backload']) && $subject['is_backload']): ?>
                                        <span class="badge bg-secondary" title="Backload from <?php echo htmlspecialchars($subject['backload_year_level'] ?? $subject['year_level']); ?>">BL</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-start">
                                    <?php echo htmlspecialchars($subject['course_name']); ?>
                                    <?php if (!empty($subject['is_backload']) && $subject['is_backload']): ?>
                                        <small class="text-muted d-block">(<?php echo htmlspecialchars($subject['backload_year_level'] ?? $subject['year_level']); ?>)</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php 
                                    // Show section name from schedule_info if available, otherwise use default section
                                    if (!empty($subject['schedule_info']['section_name'])) {
                                        echo htmlspecialchars($subject['schedule_info']['section_name']);
                                    } elseif (!empty($subject['section_info']['section_name'])) {
                                        echo htmlspecialchars($subject['section_info']['section_name']);
                                    } else {
                                        echo htmlspecialchars($corData['section_name']);
                                    }
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($subject['units']); ?></td>
                                <td><?php echo (!empty($subject['schedule_info']['schedule_days']) && in_array('Mon', $subject['schedule_info']['schedule_days'])) || (!empty($subject['schedule_monday'])) ? '✓' : ''; ?></td>
                                <td><?php echo (!empty($subject['schedule_info']['schedule_days']) && in_array('Tue', $subject['schedule_info']['schedule_days'])) || (!empty($subject['schedule_tuesday'])) ? '✓' : ''; ?></td>
                                <td><?php echo (!empty($subject['schedule_info']['schedule_days']) && in_array('Wed', $subject['schedule_info']['schedule_days'])) || (!empty($subject['schedule_wednesday'])) ? '✓' : ''; ?></td>
                                <td><?php echo (!empty($subject['schedule_info']['schedule_days']) && in_array('Thu', $subject['schedule_info']['schedule_days'])) || (!empty($subject['schedule_thursday'])) ? '✓' : ''; ?></td>
                                <td><?php echo (!empty($subject['schedule_info']['schedule_days']) && in_array('Fri', $subject['schedule_info']['schedule_days'])) || (!empty($subject['schedule_friday'])) ? '✓' : ''; ?></td>
                                <td><?php echo (!empty($subject['schedule_info']['schedule_days']) && in_array('Sat', $subject['schedule_info']['schedule_days'])) || (!empty($subject['schedule_saturday'])) ? '✓' : ''; ?></td>
                                <td><?php echo (!empty($subject['schedule_info']['schedule_days']) && in_array('Sun', $subject['schedule_info']['schedule_days'])) || (!empty($subject['schedule_sunday'])) ? '✓' : ''; ?></td>
                                <td><?php 
                                    $room = $subject['schedule_info']['room'] ?? $subject['section_info']['room'] ?? 'TBA';
                                    echo htmlspecialchars($room);
                                ?></td>
                                <td><?php 
                                    $prof_initial = $subject['schedule_info']['professor_initial'] ?? $subject['section_info']['professor_initial'] ?? 'TBA';
                                    echo htmlspecialchars($prof_initial);
                                ?></td>
                                <?php if (!empty($corData['adjustments']) && is_array($corData['adjustments']) && count($corData['adjustments']) > 0): ?>
                                    <td style="text-align: center;">
                                        <?php if ($adjustment_type === 'add'): ?>
                                            <span class="badge bg-success" style="font-size: 10px;">Added</span>
                                        <?php elseif ($adjustment_type === 'schedule_change'): ?>
                                            <span class="badge bg-primary" style="font-size: 10px;">Changed</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td colspan="3" class="text-end"><strong>TOTAL UNITS/HRS.</strong></td>
                            <td><strong><?php echo htmlspecialchars($corData['total_units']); ?></strong></td>
                            <?php if (!empty($corData['adjustments']) && is_array($corData['adjustments']) && count($corData['adjustments']) > 0): ?>
                                <td colspan="10"></td>
                            <?php else: ?>
                                <td colspan="9"></td>
                            <?php endif; ?>
                        </tr>
                    </tbody>
                </table>

                <div class="cor-footer">
                    <p class="mb-1"><strong>NOTE:</strong></p>
                    <ol style="padding-left: 16px;">
                        <li>Changes should be approved by the Dean/Director and acknowledged by the College Registrar.</li>
                        <li>Not valid without the facsimile of the College Registrar.</li>
                    </ol>
                </div>

                <div class="signature-line">
                    <div class="sig">
                        <span><?php echo htmlspecialchars($corData['dean_name']); ?></span>
                        Dean / Director
                    </div>
                    <div class="sig">
                        <span><?php echo htmlspecialchars($corData['adviser_name'] ?: ''); ?></span>
                        Adviser
                    </div>
                    <div class="sig">
                        <span><?php echo htmlspecialchars($corData['registrar_name']); ?></span>
                        College Registrar
                    </div>
                </div>

                <div class="mt-4" style="font-size: 12px;">
                    <strong>Prepared by:</strong> <?php echo htmlspecialchars(trim($corData['prepared_by'])) ?: '________________'; ?>
                </div>

                <?php 
                // Debug: Check if adjustments exist
                $has_adjustments = !empty($corData['adjustments']) && is_array($corData['adjustments']) && count($corData['adjustments']) > 0;
                if ($has_adjustments): ?>
                    <!-- Page Break for Page 2 -->
                    <div style="page-break-before: always; height: 0; margin: 0; padding: 0;"></div>
                    
                    <!-- Adjustments Table - Page 2 (Separate page but part of same COR) -->
                    <div class="adjustments-section mt-4 pt-3" style="border-top: 2px solid #000;">
                        <div class="mb-2" style="text-align: center;">
                            <h6 class="mb-1" style="font-size: 13px; font-weight: bold;">
                                ADJUSTMENTS MADE TO CERTIFICATE OF REGISTRATION
                            </h6>
                            <p class="mb-2" style="font-size: 11px;">
                                Academic Year: <strong><?php echo htmlspecialchars($corData['academic_year']); ?></strong> | 
                                Semester: <strong><?php echo htmlspecialchars($corData['semester']); ?></strong>
                            </p>
                        </div>

                        <table class="table table-bordered mb-2" style="font-size: 11px; width: 100%; border-collapse: collapse; margin-top: 5px;">
                            <thead class="table-light">
                                <tr style="background-color: #f8f9fa;">
                                    <th style="width: 10%; padding: 6px; border: 1px solid #333; text-align: center; font-weight: bold; font-size: 11px;">Type</th>
                                    <th style="width: 15%; padding: 6px; border: 1px solid #333; text-align: center; font-weight: bold; font-size: 11px;">Subject Code</th>
                                    <th style="width: 20%; padding: 6px; border: 1px solid #333; text-align: center; font-weight: bold; font-size: 11px;">Subject Name</th>
                                    <th style="width: 55%; padding: 6px; border: 1px solid #333; text-align: center; font-weight: bold; font-size: 11px;">Adjustment Details & Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($corData['adjustments'] as $adj): ?>
                                    <?php
                                    // Get schedule days for old schedule
                                    $old_days = [];
                                    if (!empty($adj['old_monday'])) $old_days[] = 'Mon';
                                    if (!empty($adj['old_tuesday'])) $old_days[] = 'Tue';
                                    if (!empty($adj['old_wednesday'])) $old_days[] = 'Wed';
                                    if (!empty($adj['old_thursday'])) $old_days[] = 'Thu';
                                    if (!empty($adj['old_friday'])) $old_days[] = 'Fri';
                                    if (!empty($adj['old_saturday'])) $old_days[] = 'Sat';
                                    if (!empty($adj['old_sunday'])) $old_days[] = 'Sun';
                                    
                                    // Get schedule days for new schedule
                                    $new_days = [];
                                    if (!empty($adj['new_monday'])) $new_days[] = 'Mon';
                                    if (!empty($adj['new_tuesday'])) $new_days[] = 'Tue';
                                    if (!empty($adj['new_wednesday'])) $new_days[] = 'Wed';
                                    if (!empty($adj['new_thursday'])) $new_days[] = 'Thu';
                                    if (!empty($adj['new_friday'])) $new_days[] = 'Fri';
                                    if (!empty($adj['new_saturday'])) $new_days[] = 'Sat';
                                    if (!empty($adj['new_sunday'])) $new_days[] = 'Sun';
                                    
                                    $change_type_label = '';
                                    $details = '';
                                    
                                    if ($adj['change_type'] === 'add') {
                                        $change_type_label = 'ADDED';
                                        $details = '<strong>Added Subject:</strong><br>';
                                        if (!empty($adj['new_section_name'])) {
                                            $details .= 'Section: ' . htmlspecialchars($adj['new_section_name']) . '<br>';
                                        }
                                        if (!empty($new_days) && !empty($adj['new_time_start']) && !empty($adj['new_time_end'])) {
                                            $details .= 'Schedule: ' . htmlspecialchars(implode(', ', $new_days) . ' ' . $adj['new_time_start'] . '-' . $adj['new_time_end']) . '<br>';
                                        }
                                        if (!empty($adj['new_room'])) {
                                            $details .= 'Room: ' . htmlspecialchars($adj['new_room']) . '<br>';
                                        }
                                        if (!empty($adj['new_professor_name'])) {
                                            $details .= 'Professor: ' . htmlspecialchars($adj['new_professor_name']) . '<br>';
                                        }
                                        if (!empty($adj['units'])) {
                                            $details .= 'Units: ' . htmlspecialchars((string)$adj['units']);
                                        }
                                    } elseif ($adj['change_type'] === 'remove') {
                                        $change_type_label = 'REMOVED';
                                        $details = '<strong>Removed Subject:</strong><br>';
                                        if (!empty($adj['old_section_name'])) {
                                            $details .= 'Section: ' . htmlspecialchars($adj['old_section_name']) . '<br>';
                                        }
                                        if (!empty($old_days) && !empty($adj['old_time_start']) && !empty($adj['old_time_end'])) {
                                            $details .= 'Schedule: ' . htmlspecialchars(implode(', ', $old_days) . ' ' . $adj['old_time_start'] . '-' . $adj['old_time_end']) . '<br>';
                                        }
                                        if (!empty($adj['old_room'])) {
                                            $details .= 'Room: ' . htmlspecialchars($adj['old_room']) . '<br>';
                                        }
                                        if (!empty($adj['old_professor_name'])) {
                                            $details .= 'Professor: ' . htmlspecialchars($adj['old_professor_name']);
                                        }
                                    } elseif ($adj['change_type'] === 'schedule_change') {
                                        $change_type_label = 'SCHEDULE<br>CHANGED';
                                        $details = '<strong>Schedule Change:</strong><br>';
                                        $details .= '<strong>From:</strong> ';
                                        if (!empty($adj['old_section_name'])) {
                                            $details .= htmlspecialchars($adj['old_section_name']);
                                        }
                                        if (!empty($old_days) && !empty($adj['old_time_start']) && !empty($adj['old_time_end'])) {
                                            $details .= ' (' . htmlspecialchars(implode(', ', $old_days) . ' ' . $adj['old_time_start'] . '-' . $adj['old_time_end']) . ')';
                                        }
                                        if (!empty($adj['old_room'])) {
                                            $details .= ' - Room: ' . htmlspecialchars($adj['old_room']);
                                        }
                                        $details .= '<br><strong>To:</strong> ';
                                        if (!empty($adj['new_section_name'])) {
                                            $details .= htmlspecialchars($adj['new_section_name']);
                                        }
                                        if (!empty($new_days) && !empty($adj['new_time_start']) && !empty($adj['new_time_end'])) {
                                            $details .= ' (' . htmlspecialchars(implode(', ', $new_days) . ' ' . $adj['new_time_start'] . '-' . $adj['new_time_end']) . ')';
                                        }
                                        if (!empty($adj['new_room'])) {
                                            $details .= ' - Room: ' . htmlspecialchars($adj['new_room']);
                                        }
                                    }
                                    
                                    // Remarks removed - adjustment type already indicates what happened
                                    ?>
                                    <tr>
                                        <td style="vertical-align: top; text-align: center; padding: 6px; border: 1px solid #333; font-size: 11px;">
                                            <strong><?php echo $change_type_label; ?></strong>
                                        </td>
                                        <td style="vertical-align: top; padding: 6px; border: 1px solid #333; font-size: 11px;">
                                            <strong><?php echo htmlspecialchars($adj['course_code'] ?? 'N/A'); ?></strong>
                                            <?php if (!empty($adj['units'])): ?>
                                                <br><small style="font-size: 10px;"><?php echo htmlspecialchars((string)$adj['units']); ?> units</small>
                                            <?php endif; ?>
                                        </td>
                                        <td style="vertical-align: top; padding: 6px; border: 1px solid #333; font-size: 11px;">
                                            <?php echo htmlspecialchars($adj['course_name'] ?? 'N/A'); ?>
                                        </td>
                                        <td style="vertical-align: top; padding: 6px; border: 1px solid #333; font-size: 10px;">
                                            <div style="line-height: 1.4;">
                                                <?php echo $details; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <div class="mt-2" style="font-size: 10px; text-align: center;">
                            <p class="mb-0"><em>This document is an extension of the Certificate of Registration above.</em></p>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
        (function() {
            const programSelect = document.getElementById('program_id');
            const sectionSelect = document.getElementById('section_id');
            const yearDisplay = document.getElementById('display_year_level');
            const semesterDisplay = document.getElementById('display_semester');
            const academicYearInput = document.getElementById('academic_year');

            const studentSelect = document.getElementById('selected_student_id');
            const studentNumberSelect = document.getElementById('student_number_select');
            const studentLastNameSelect = document.getElementById('student_last_name_select');
            const studentFirstNameSelect = document.getElementById('student_first_name_select');
            const studentMiddleNameSelect = document.getElementById('student_middle_name_select');
            const studentAddressSelect = document.getElementById('student_address_select');

            const initialProgramId = '<?php echo $selected_program_id ?: ''; ?>';
            const initialSectionId = '<?php echo $selected_section_id ?: ''; ?>';
            let initialStudentId = '<?php echo $selected_student_id ?: ''; ?>';
            const initialStudents = <?php echo json_encode($section_students); ?>;
            const initialFieldValues = <?php echo json_encode([
                'studentNumber' => $student_number,
                'lastName' => $student_last_name,
                'firstName' => $student_first_name,
                'middleName' => $student_middle_name,
                'address' => $student_address,
            ]); ?>;
            const hasInitialUserData = <?php echo (isset($_GET['user_id']) && !empty($selected_student_id) && (!empty($student_first_name) || !empty($student_last_name))) ? 'true' : 'false'; ?>;
            const initialUserData = <?php echo (isset($_GET['user_id']) && !empty($selected_student_id) && (!empty($student_first_name) || !empty($student_last_name))) ? json_encode([
                'id' => $selected_student_id,
                'student_id' => $student_number ?? '',
                'last_name' => $student_last_name ?? '',
                'first_name' => $student_first_name ?? '',
                'middle_name' => $student_middle_name ?? '',
                'permanent_address' => $student_address ?? '',
                'address' => $student_address ?? ''
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'null'; ?>;
            const sectionEndpointBase = '<?php echo add_session_to_url('get_section_students.php'); ?>';
            const studentSectionEndpointBase = '<?php echo add_session_to_url('get_student_section_for_cor.php'); ?>';

            const studentsCache = {};
            let currentStudents = [];

            if (initialSectionId && Array.isArray(initialStudents) && initialStudents.length) {
                studentsCache[initialSectionId] = initialStudents;
            }
            
            // If we have initial user data but no section, cache it for immediate use
            if (hasInitialUserData && initialUserData && !initialSectionId) {
                // This will be used when populateStudents is called without a section
                currentStudents = [initialUserData];
            }

            function resetSelect(selectElement, placeholder) {
                if (!selectElement) return;
                selectElement.innerHTML = '';
                const option = document.createElement('option');
                option.value = '';
                option.textContent = placeholder;
                selectElement.appendChild(option);
            }

            function setOptionData(option, student) {
                option.dataset.studentId = String(student.id);
                option.dataset.studentNumber = student.student_id || '';
                option.dataset.lastName = student.last_name || '';
                option.dataset.firstName = student.first_name || '';
                option.dataset.middleName = student.middle_name || '';
                option.dataset.address = student.permanent_address || student.address || '';
            }

            function createOption(selectElement, value, label, student) {
                if (!selectElement) return;
                const option = document.createElement('option');
                option.value = value ?? '';
                option.textContent = label ?? '';
                setOptionData(option, student);
                selectElement.appendChild(option);
            }

            function setSelectByValue(selectElement, value) {
                if (!selectElement) return false;
                const option = Array.from(selectElement.options).find(opt => opt.value === (value ?? ''));
                if (option) {
                    option.selected = true;
                    return true;
                }
                return false;
            }

            function findOptionByStudentId(selectElement, studentId) {
                if (!selectElement) return null;
                return Array.from(selectElement.options).find(opt => opt.dataset.studentId === String(studentId));
            }

            function syncToStudent(studentId) {
                if (!studentId) return false;
                let matched = false;
                [studentSelect, studentNumberSelect, studentLastNameSelect, studentFirstNameSelect, studentMiddleNameSelect, studentAddressSelect].forEach(select => {
                    const option = findOptionByStudentId(select, studentId);
                    if (option) {
                        option.selected = true;
                        matched = true;
                    }
                });
                return matched;
            }

            function populateStudents(students) {
                currentStudents = Array.isArray(students) ? students : [];
                
                // Always include initial user data if provided via GET parameter
                // This ensures the pre-filled student appears in dropdowns even if not in section
                if (hasInitialUserData && initialUserData && initialUserData.id) {
                    const userExists = currentStudents.some(s => s.id === initialUserData.id);
                    if (!userExists) {
                        // Add initial user at the beginning of the list
                        currentStudents.unshift(initialUserData);
                    } else {
                        // If user exists, update it with initial data to ensure all fields are correct
                        const userIndex = currentStudents.findIndex(s => s.id === initialUserData.id);
                        if (userIndex >= 0) {
                            currentStudents[userIndex] = { ...currentStudents[userIndex], ...initialUserData };
                        }
                    }
                }
                
                const hasStudents = currentStudents.length > 0;

                resetSelect(studentSelect, hasStudents ? 'Select student from section' : 'No enrolled students found');
                resetSelect(studentNumberSelect, hasStudents ? 'Select student number' : 'No student numbers available');
                resetSelect(studentLastNameSelect, hasStudents ? 'Select last name' : 'No last names available');
                resetSelect(studentFirstNameSelect, hasStudents ? 'Select first name' : 'No first names available');
                resetSelect(studentMiddleNameSelect, hasStudents ? 'Select middle name' : 'No middle names available');
                resetSelect(studentAddressSelect, hasStudents ? 'Select address' : 'No addresses available');

                currentStudents.forEach(student => {
                    const displayNumber = student.student_id || 'No Student Number';
                    const studentLabel = `${displayNumber} - ${student.last_name || ''}, ${student.first_name || ''}`.trim();
                    const fullAddress = student.permanent_address || student.address || '';
                    const addressDisplay = fullAddress ? (fullAddress.length > 80 ? fullAddress.slice(0, 80) + '…' : fullAddress) : 'No Address on Record';

                    createOption(studentSelect, student.id, studentLabel, student);
                    createOption(studentNumberSelect, student.student_id || '', displayNumber, student);
                    createOption(studentLastNameSelect, student.last_name || '', student.last_name || '(blank)', student);
                    createOption(studentFirstNameSelect, student.first_name || '', student.first_name || '(blank)', student);
                    createOption(studentMiddleNameSelect, student.middle_name || '', student.middle_name || '(blank)', student);
                    createOption(studentAddressSelect, fullAddress, addressDisplay, student);
                });

                let synced = false;
                if (initialStudentId) {
                    synced = syncToStudent(initialStudentId);
                    if (synced) {
                        initialStudentId = '';
                    }
                }

                // If not synced and we have initial field values, try to match by values
                if (!synced && (initialFieldValues.studentNumber || initialFieldValues.lastName || initialFieldValues.firstName)) {
                    const fallbacks = [
                        { select: studentNumberSelect, value: initialFieldValues.studentNumber },
                        { select: studentLastNameSelect, value: initialFieldValues.lastName },
                        { select: studentFirstNameSelect, value: initialFieldValues.firstName },
                        { select: studentMiddleNameSelect, value: initialFieldValues.middleName },
                        { select: studentAddressSelect, value: initialFieldValues.address }
                    ];

                    for (const { select, value } of fallbacks) {
                        if (select && value && setSelectByValue(select, value)) {
                            const option = select.options[select.selectedIndex];
                            if (option && option.dataset.studentId) {
                                syncToStudent(option.dataset.studentId);
                                synced = true;
                                break;
                            }
                        }
                    }
                }

                // If still not synced but we have initial user data, select it directly
                if (!synced && hasInitialUserData && initialUserData && initialUserData.id) {
                    synced = syncToStudent(initialUserData.id);
                    if (synced) {
                        initialStudentId = ''; // Clear it after syncing
                    }
                }
                
                // Final fallback: if we have initial user data but couldn't sync, try again after a short delay
                // This handles edge cases where the DOM might not be fully ready
                if (!synced && hasInitialUserData && initialUserData && initialUserData.id && hasStudents) {
                    setTimeout(() => {
                        const syncedNow = syncToStudent(initialUserData.id);
                        if (syncedNow && studentSelect) {
                            studentSelect.value = initialUserData.id;
                        }
                    }, 200);
                }
            }

            function buildEndpoint(sectionId) {
                if (!sectionId) {
                    return null;
                }
                return sectionEndpointBase + (sectionEndpointBase.includes('?') ? '&' : '?') + 'section_id=' + encodeURIComponent(sectionId);
            }

            function loadSectionStudents(sectionId) {
                if (!studentSelect) return;

                if (!sectionId) {
                    // If no section but we have initial user data, populate with just that user
                    if (hasInitialUserData && initialUserData) {
                        populateStudents([initialUserData]);
                    } else {
                        populateStudents([]);
                    }
                    return;
                }

                if (studentsCache[sectionId]) {
                    populateStudents(studentsCache[sectionId]);
                    return;
                }

                resetSelect(studentSelect, 'Loading students...');
                // Keep initial user data visible while loading if available
                if (hasInitialUserData && initialUserData) {
                    populateStudents([initialUserData]);
                } else {
                    populateStudents([]);
                }

                const endpoint = buildEndpoint(sectionId);
                if (!endpoint) {
                    // If no endpoint but we have initial user data, populate with just that user
                    if (hasInitialUserData && initialUserData) {
                        populateStudents([initialUserData]);
                    } else {
                        populateStudents([]);
                    }
                    return;
                }

                fetch(endpoint)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            studentsCache[sectionId] = data.students || [];
                            populateStudents(studentsCache[sectionId]);
                        } else {
                            resetSelect(studentSelect, data.message || 'Unable to load students');
                            // If we have initial user data, keep it visible
                            if (hasInitialUserData && initialUserData) {
                                populateStudents([initialUserData]);
                            } else {
                                populateStudents([]);
                            }
                        }
                    })
                    .catch(() => {
                        resetSelect(studentSelect, 'Error loading students');
                        // If we have initial user data, keep it visible
                        if (hasInitialUserData && initialUserData) {
                            populateStudents([initialUserData]);
                        } else {
                            populateStudents([]);
                        }
                    });
            }

            function filterSections() {
                const programId = programSelect.value;
                let hasVisibleOption = false;

                Array.from(sectionSelect.options).forEach(option => {
                    if (!option.value) {
                        option.hidden = false;
                        return;
                    }

                    if (option.dataset.program === programId) {
                        option.hidden = false;
                        if (!hasVisibleOption) {
                            hasVisibleOption = true;
                        }
                    } else {
                        option.hidden = true;
                        if (sectionSelect.value === option.value) {
                            sectionSelect.value = '';
                        }
                    }
                });

                updateSectionDetails();
                loadSectionStudents(sectionSelect.value);
            }

            function updateSectionDetails() {
                const selectedOption = sectionSelect.options[sectionSelect.selectedIndex];
                if (selectedOption && selectedOption.value) {
                    yearDisplay.value = selectedOption.dataset.year || '';
                    semesterDisplay.value = selectedOption.dataset.semester || '';
                    if (!academicYearInput.value) {
                        academicYearInput.value = selectedOption.dataset.academic_year || selectedOption.dataset.academicYear || '';
                    }
                } else {
                    yearDisplay.value = '';
                    semesterDisplay.value = '';
                }
            }

            function setSectionInDropdown(sectionId, yearLevel, semester, academicYear) {
                console.log('setSectionInDropdown called with:', { sectionId, yearLevel, semester, academicYear });
                if (!sectionSelect) {
                    console.warn('sectionSelect not found');
                    return;
                }
                
                // Force year level and semester to be set immediately (these fields should always work)
                if (yearDisplay && yearLevel) {
                    yearDisplay.value = yearLevel;
                    console.log('✓ Set year level to:', yearLevel);
                }
                if (semesterDisplay && semester) {
                    semesterDisplay.value = semester;
                    console.log('✓ Set semester to:', semester);
                }
                if (academicYearInput && academicYear && !academicYearInput.value) {
                    academicYearInput.value = academicYear;
                    console.log('✓ Set academic year to:', academicYear);
                }
                
                // Try to find the section option (check all options, including hidden ones)
                let sectionOption = Array.from(sectionSelect.options).find(
                    opt => opt.value === String(sectionId)
                );
                
                if (sectionOption) {
                    // Make section visible if it was hidden (force it visible)
                    if (sectionOption.hidden) {
                        sectionOption.hidden = false;
                        console.log('Made section option visible');
                    }
                    
                    // Also ensure the option's program matches the current program selection
                    // If it doesn't match, we might need to temporarily show it
                    const currentProgramId = programSelect ? programSelect.value : '';
                    if (sectionOption.dataset.program && sectionOption.dataset.program !== currentProgramId) {
                        console.log('Warning: Section program', sectionOption.dataset.program, 'does not match current program', currentProgramId);
                        // Make it visible anyway so it can be selected
                        sectionOption.hidden = false;
                    }
                    
                    console.log('Found section option:', {
                        value: sectionOption.value,
                        text: sectionOption.textContent,
                        program: sectionOption.dataset.program,
                        hidden: sectionOption.hidden
                    });
                    
                    // Set the section value
                    sectionSelect.value = sectionId;
                    console.log('✓ Set section dropdown value to:', sectionId);
                    
                    // Trigger change event to ensure all handlers run
                    const changeEvent = new Event('change', { bubbles: true });
                    sectionSelect.dispatchEvent(changeEvent);
                    
                    // Also explicitly call updateSectionDetails and loadSectionStudents
                    if (typeof updateSectionDetails === 'function') {
                        updateSectionDetails();
                    }
                    if (typeof loadSectionStudents === 'function') {
                        loadSectionStudents(sectionId);
                    }
                    
                    console.log('✓ Section successfully set in dropdown');
                } else {
                    console.warn('⚠ Section option not found in dropdown for section ID:', sectionId);
                    // Year level and semester are already set above, so at least those work
                    // Log available options for debugging
                    const allOptions = Array.from(sectionSelect.options).map(opt => ({
                        value: opt.value,
                        text: opt.textContent.substring(0, 50),
                        program: opt.dataset.program,
                        hidden: opt.hidden
                    }));
                    console.log('Available section options:', allOptions);
                    console.log('Note: Year Level and Semester have been set, but section dropdown could not be set. Please select the section manually.');
                }
            }

            function loadStudentSection(userId) {
                if (!userId) {
                    console.log('loadStudentSection called with no userId');
                    return;
                }
                
                console.log('Loading section for student ID:', userId);
                const endpoint = studentSectionEndpointBase + (studentSectionEndpointBase.includes('?') ? '&' : '?') + 'user_id=' + encodeURIComponent(userId);
                console.log('Endpoint:', endpoint);
                
                fetch(endpoint)
                    .then(response => {
                        console.log('Response status:', response.status);
                        return response.json();
                    })
                    .then(data => {
                        console.log('Section data received:', data);
                        if (data.success && data.section) {
                            const section = data.section;
                            console.log('Processing section:', section);
                            
                            // Always set year level and semester directly from the API response first
                            if (yearDisplay) {
                                yearDisplay.value = section.year_level || '';
                                console.log('Set year level to:', section.year_level);
                            }
                            if (semesterDisplay) {
                                semesterDisplay.value = section.semester || '';
                                console.log('Set semester to:', section.semester);
                            }
                            if (academicYearInput && !academicYearInput.value) {
                                academicYearInput.value = section.academic_year || '';
                                console.log('Set academic year to:', section.academic_year);
                            }
                            
                            // Set program first if available
                            if (programSelect && section.program_id) {
                                console.log('Setting program to:', section.program_id);
                                
                                // Check if program is already set correctly
                                if (programSelect.value === String(section.program_id)) {
                                    // Program is already correct, just set the section
                                    console.log('Program already set correctly, setting section immediately');
                                    setSectionInDropdown(section.id, section.year_level, section.semester, section.academic_year);
                                } else {
                                    // Set the program value
                                    programSelect.value = section.program_id;
                                    
                                    // Trigger filterSections to show only sections for this program
                                    if (typeof filterSections === 'function') {
                                        filterSections();
                                    } else {
                                        // Fallback: trigger change event
                                        const changeEvent = new Event('change', { bubbles: true });
                                        programSelect.dispatchEvent(changeEvent);
                                    }
                                    
                                    // Wait a bit longer for DOM to update, then set the section
                                    setTimeout(() => {
                                        console.log('Timeout completed, setting section now');
                                        setSectionInDropdown(section.id, section.year_level, section.semester, section.academic_year);
                                    }, 400);
                                }
                            } else {
                                console.log('No program_id found, trying to find section directly');
                                // If no program, still try to find and select the section
                                setSectionInDropdown(section.id, section.year_level, section.semester, section.academic_year);
                            }
                        } else {
                            console.warn('No section data received:', data);
                        }
                    })
                    .catch(error => {
                        console.error('Error loading student section:', error);
                    });
            }

            [studentSelect, studentNumberSelect, studentLastNameSelect, studentFirstNameSelect, studentMiddleNameSelect, studentAddressSelect].forEach(select => {
                if (!select) return;
                select.addEventListener('change', (e) => {
                    const option = select.options[select.selectedIndex];
                    console.log('Student dropdown changed:', select.id, 'Option:', option);
                    console.log('Option value:', option ? option.value : 'no option');
                    console.log('Option dataset:', option ? option.dataset : 'no option');
                    
                    // Try to get student ID from dataset first, fallback to option value for studentSelect
                    let studentId = null;
                    if (option) {
                        if (option.dataset.studentId) {
                            studentId = option.dataset.studentId;
                        } else if (select === studentSelect && option.value) {
                            // For the main student select, the value itself is the student ID
                            studentId = option.value;
                        } else {
                            // For other dropdowns, try to find the matching student in currentStudents
                            const matchedStudent = currentStudents.find(s => {
                                if (select === studentNumberSelect) return s.student_id === option.value;
                                if (select === studentLastNameSelect) return s.last_name === option.value;
                                if (select === studentFirstNameSelect) return s.first_name === option.value;
                                if (select === studentMiddleNameSelect) return s.middle_name === option.value;
                                if (select === studentAddressSelect) return (s.permanent_address || s.address) === option.value;
                                return false;
                            });
                            if (matchedStudent) {
                                studentId = String(matchedStudent.id);
                            }
                        }
                    }
                    
                    if (studentId) {
                        console.log('Student ID found:', studentId);
                        syncToStudent(studentId);
                        initialStudentId = studentId;
                        
                        // Auto-load student's assigned section
                        loadStudentSection(studentId);
                    } else {
                        console.log('No student ID found for selection');
                    }
                });
            });

            if (programSelect) {
                programSelect.addEventListener('change', filterSections);
            }

            if (sectionSelect) {
                sectionSelect.addEventListener('change', () => {
                    updateSectionDetails();
                    loadSectionStudents(sectionSelect.value);
                });
            }

            if (initialProgramId && programSelect) {
                programSelect.value = initialProgramId;
            }

            filterSections();

            if (initialSectionId && sectionSelect) {
                sectionSelect.value = initialSectionId;
                updateSectionDetails();
                loadSectionStudents(initialSectionId);
            } else {
                // If no section but we have initial user data from GET parameter, try to load student's section
                if (hasInitialUserData && initialUserData && initialUserData.id) {
                    populateStudents([initialUserData]);
                    // Try to load the student's assigned section to auto-fill section, year level, and semester
                    // Use a small delay to ensure DOM is fully ready
                    setTimeout(() => {
                        console.log('Loading section for initial user:', initialUserData.id);
                        loadStudentSection(initialUserData.id);
                    }, 200);
                } else {
                    populateStudents([]);
                }
            }
            
            // Also trigger section load if student is already selected in dropdown after initial load
            if (initialStudentId && studentSelect && studentSelect.value === initialStudentId) {
                setTimeout(() => {
                    console.log('Triggering section load for pre-selected student:', initialStudentId);
                    loadStudentSection(initialStudentId);
                }, 500);
            }
        })();
        
        // Print specific COR functionality
        document.querySelectorAll('.print-cor-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const corId = this.dataset.corId;
                const corWrapper = document.querySelector('.cor-wrapper');
                
                if (corWrapper) {
                    // Add print-this class to the COR wrapper
                    corWrapper.classList.add('print-this');
                    
                    // Trigger print
                    window.print();
                    
                    // Remove print-this class after printing
                    setTimeout(() => {
                        corWrapper.classList.remove('print-this');
                    }, 1000);
                }
            });
        });
        
        // Auto-print if print parameter is set
        <?php if (isset($_GET['print']) && $_GET['print'] == '1'): ?>
        window.addEventListener('load', function() {
            setTimeout(function() {
                const corWrapper = document.querySelector('.cor-wrapper');
                if (corWrapper) {
                    corWrapper.classList.add('print-this');
                }
                window.print();
                setTimeout(() => {
                    if (corWrapper) {
                        corWrapper.classList.remove('print-this');
                    }
                }, 1000);
            }, 1000);
        });
        <?php endif; ?>
    </script>
</body>
</html>

