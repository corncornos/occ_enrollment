<?php
declare(strict_types=1);

/**
 * Adjustment Period Helper Functions
 * 
 * Provides helper functions for adjustment period operations:
 * - Prerequisite checking
 * - Schedule conflict detection
 * - Adding/removing subjects
 * - Changing schedules
 */

/**
 * Check if student meets prerequisites for a subject
 * 
 * @param PDO $conn Database connection
 * @param int $user_id Student user ID
 * @param int $curriculum_id Subject curriculum ID
 * @return array ['met' => bool, 'unmet_prerequisites' => array]
 */
function checkPrerequisitesForSubject(PDO $conn, int $user_id, int $curriculum_id): array {
    try {
        // Get prerequisites for this subject
        $prereq_query = "SELECT sp.prerequisite_curriculum_id, sp.minimum_grade,
                        pc.course_code AS prereq_code, pc.course_name AS prereq_name
                        FROM subject_prerequisites sp
                        JOIN curriculum pc ON sp.prerequisite_curriculum_id = pc.id
                        WHERE sp.curriculum_id = :curriculum_id";
        $prereq_stmt = $conn->prepare($prereq_query);
        $prereq_stmt->bindParam(':curriculum_id', $curriculum_id, PDO::PARAM_INT);
        $prereq_stmt->execute();
        $prerequisites = $prereq_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($prerequisites)) {
            return ['met' => true, 'unmet_prerequisites' => []];
        }
        
        // Get student's grades
        $grades_query = "SELECT curriculum_id, grade, grade_letter
                        FROM student_grades
                        WHERE user_id = :user_id
                        AND status IN ('verified', 'finalized')";
        $grades_stmt = $conn->prepare($grades_query);
        $grades_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $grades_stmt->execute();
        $student_grades = [];
        while ($row = $grades_stmt->fetch(PDO::FETCH_ASSOC)) {
            $student_grades[$row['curriculum_id']] = $row;
        }
        
        $unmet_prerequisites = [];
        $all_met = true;
        
        foreach ($prerequisites as $prereq) {
            $prereq_curriculum_id = $prereq['prerequisite_curriculum_id'];
            $prereq_grade = $student_grades[$prereq_curriculum_id] ?? null;
            
            if (!$prereq_grade) {
                // Student hasn't taken this prerequisite
                $all_met = false;
                $unmet_prerequisites[] = [
                    'code' => $prereq['prereq_code'],
                    'name' => $prereq['prereq_name'],
                    'reason' => 'Not taken',
                    'minimum_grade' => $prereq['minimum_grade']
                ];
            } elseif (strtoupper($prereq_grade['grade_letter']) == 'INC' || strtoupper($prereq_grade['grade_letter']) == 'INCOMPLETE') {
                // Student has INC (Incomplete) grade
                $all_met = false;
                $unmet_prerequisites[] = [
                    'code' => $prereq['prereq_code'],
                    'name' => $prereq['prereq_name'],
                    'reason' => 'Grade is INC (Incomplete) - must complete this course first',
                    'minimum_grade' => $prereq['minimum_grade'],
                    'student_grade' => $prereq_grade['grade_letter']
                ];
            } elseif (in_array(strtoupper($prereq_grade['grade_letter']), ['F', 'FA', 'FAILED', 'W', 'WITHDRAWN', 'DROPPED'])) {
                // Student has failing, withdrawn, or dropped grade
                $grade_label = strtoupper($prereq_grade['grade_letter']);
                if ($grade_label == 'W' || $grade_label == 'WITHDRAWN') {
                    $reason_text = 'Grade ' . $prereq_grade['grade_letter'] . ' (Withdrawn) - must retake and pass this course';
                } elseif ($grade_label == 'DROPPED') {
                    $reason_text = 'Grade ' . $prereq_grade['grade_letter'] . ' (Dropped) - must retake and pass this course';
                } else {
                    $reason_text = 'Grade ' . $prereq_grade['grade_letter'] . ' (Failed) - must retake and pass this course';
                }
                $all_met = false;
                $unmet_prerequisites[] = [
                    'code' => $prereq['prereq_code'],
                    'name' => $prereq['prereq_name'],
                    'reason' => $reason_text,
                    'minimum_grade' => $prereq['minimum_grade'],
                    'student_grade' => $prereq_grade['grade_letter']
                ];
            } elseif ($prereq_grade['grade'] >= 5.0) {
                // Student has grade of 5.0 or higher (failing/incomplete)
                $all_met = false;
                $unmet_prerequisites[] = [
                    'code' => $prereq['prereq_code'],
                    'name' => $prereq['prereq_name'],
                    'reason' => 'Grade ' . $prereq_grade['grade_letter'] . ' (' . $prereq_grade['grade'] . ') indicates failed or incomplete course',
                    'minimum_grade' => $prereq['minimum_grade'],
                    'student_grade' => $prereq_grade['grade_letter']
                ];
            } elseif ($prereq_grade['grade'] > $prereq['minimum_grade']) {
                // Student's grade doesn't meet minimum requirement
                $all_met = false;
                $unmet_prerequisites[] = [
                    'code' => $prereq['prereq_code'],
                    'name' => $prereq['prereq_name'],
                    'reason' => 'Grade ' . $prereq_grade['grade_letter'] . ' (' . $prereq_grade['grade'] . ') does not meet minimum ' . $prereq['minimum_grade'],
                    'minimum_grade' => $prereq['minimum_grade'],
                    'student_grade' => $prereq_grade['grade_letter']
                ];
            }
        }
        
        return ['met' => $all_met, 'unmet_prerequisites' => $unmet_prerequisites];
        
    } catch (Throwable $e) {
        error_log('Error checking prerequisites: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
        return ['met' => false, 'unmet_prerequisites' => [['code' => 'ERROR', 'name' => 'Error checking prerequisites', 'reason' => $e->getMessage()]]];
    }
}

/**
 * Get available schedules for a subject
 * 
 * @param PDO $conn Database connection
 * @param int $curriculum_id Subject curriculum ID
 * @param string $academic_year Academic year
 * @param string $semester Semester
 * @param int|null $exclude_schedule_id Section schedule ID to exclude (for schedule changes)
 * @return array Array of available schedules
 */
function getAvailableSchedulesForSubject(PDO $conn, int $curriculum_id, string $academic_year, string $semester, ?int $exclude_schedule_id = null): array {
    try {
        // Get all section schedules for this subject
        $query = "SELECT ss.*, s.section_name, s.year_level, s.program_id,
                 (s.max_capacity - s.current_enrolled) as available_slots,
                 p.program_code, p.program_name
                 FROM section_schedules ss
                 JOIN sections s ON ss.section_id = s.id
                 JOIN programs p ON s.program_id = p.id
                 WHERE ss.curriculum_id = :curriculum_id
                 AND s.academic_year = :academic_year
                 AND s.semester = :semester
                 AND s.status = 'active'
                 AND s.current_enrolled < s.max_capacity";
        
        // Exclude specific schedule if provided
        if ($exclude_schedule_id !== null) {
            $query .= " AND ss.id != :exclude_schedule_id";
        }
        
        $query .= " ORDER BY s.section_name, ss.time_start";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':curriculum_id', $curriculum_id, PDO::PARAM_INT);
        $stmt->bindParam(':academic_year', $academic_year);
        $stmt->bindParam(':semester', $semester);
        if ($exclude_schedule_id !== null) {
            $stmt->bindParam(':exclude_schedule_id', $exclude_schedule_id, PDO::PARAM_INT);
        }
        $stmt->execute();
        
        $schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $schedules;
        
    } catch (Throwable $e) {
        error_log('Error getting available schedules: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
        return [];
    }
}

/**
 * Check for schedule conflicts
 * 
 * @param PDO $conn Database connection
 * @param int $user_id Student user ID
 * @param int $section_schedule_id New schedule ID to check
 * @param int|null $exclude_subject_id Subject schedule ID to exclude from conflict check (for schedule changes)
 * @return array ['has_conflict' => bool, 'conflicts' => array]
 */
function checkScheduleConflicts(PDO $conn, int $user_id, int $section_schedule_id, ?int $exclude_subject_id = null): array {
    try {
        // Get the new schedule details
        $new_schedule_query = "SELECT ss.* FROM section_schedules ss WHERE ss.id = :schedule_id";
        $new_schedule_stmt = $conn->prepare($new_schedule_query);
        $new_schedule_stmt->bindParam(':schedule_id', $section_schedule_id, PDO::PARAM_INT);
        $new_schedule_stmt->execute();
        $new_schedule = $new_schedule_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$new_schedule) {
            return ['has_conflict' => false, 'conflicts' => []];
        }
        
        // Get student's current active schedules
        $current_schedules_query = "SELECT sts.*, ss.*, c.course_code, c.course_name
                                   FROM student_schedules sts
                                   JOIN section_schedules ss ON sts.section_schedule_id = ss.id
                                   JOIN curriculum c ON ss.curriculum_id = c.id
                                   WHERE sts.user_id = :user_id
                                   AND sts.status = 'active'";
        
        if ($exclude_subject_id !== null) {
            $current_schedules_query .= " AND sts.id != :exclude_id";
        }
        
        $current_schedules_stmt = $conn->prepare($current_schedules_query);
        $current_schedules_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        if ($exclude_subject_id !== null) {
            $current_schedules_stmt->bindParam(':exclude_id', $exclude_subject_id, PDO::PARAM_INT);
        }
        $current_schedules_stmt->execute();
        $current_schedules = $current_schedules_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $conflicts = [];
        
        // Check for time/day conflicts
        foreach ($current_schedules as $current) {
            // Check if days overlap
            $days_overlap = false;
            $days = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
            foreach ($days as $day) {
                if ($new_schedule['schedule_' . $day] && $current['schedule_' . $day]) {
                    $days_overlap = true;
                    break;
                }
            }
            
            if ($days_overlap) {
                // Check if times overlap
                $new_start_time = !empty($new_schedule['time_start']) ? $new_schedule['time_start'] : null;
                $new_end_time = !empty($new_schedule['time_end']) ? $new_schedule['time_end'] : null;
                $current_start_time = !empty($current['time_start']) ? $current['time_start'] : null;
                $current_end_time = !empty($current['time_end']) ? $current['time_end'] : null;
                
                if ($new_start_time && $new_end_time && $current_start_time && $current_end_time) {
                    $new_start = strtotime($new_start_time);
                    $new_end = strtotime($new_end_time);
                    $current_start = strtotime($current_start_time);
                    $current_end = strtotime($current_end_time);
                    
                    // Only check if strtotime succeeded (returns false on failure)
                    if ($new_start !== false && $new_end !== false && $current_start !== false && $current_end !== false) {
                        if (($new_start < $current_end && $new_end > $current_start)) {
                            $conflicts[] = [
                                'course_code' => $current['course_code'],
                                'course_name' => $current['course_name'],
                                'time' => $current_start_time . ' - ' . $current_end_time,
                                'days' => getScheduleDays($current)
                            ];
                        }
                    }
                }
            }
        }
        
        return [
            'has_conflict' => !empty($conflicts),
            'conflicts' => $conflicts
        ];
        
    } catch (Throwable $e) {
        error_log('Error checking schedule conflicts: ' . $e->getMessage() . ' | Trace: ' . $e->getTraceAsString());
        return ['has_conflict' => true, 'conflicts' => [['course_code' => 'ERROR', 'course_name' => 'Error checking conflicts', 'reason' => $e->getMessage()]]];
    }
}

/**
 * Helper function to get schedule days as string
 */
function getScheduleDays(array $schedule): string {
    $days = [];
    if (!empty($schedule['schedule_monday'])) $days[] = 'Mon';
    if (!empty($schedule['schedule_tuesday'])) $days[] = 'Tue';
    if (!empty($schedule['schedule_wednesday'])) $days[] = 'Wed';
    if (!empty($schedule['schedule_thursday'])) $days[] = 'Thu';
    if (!empty($schedule['schedule_friday'])) $days[] = 'Fri';
    if (!empty($schedule['schedule_saturday'])) $days[] = 'Sat';
    if (!empty($schedule['schedule_sunday'])) $days[] = 'Sun';
    return implode(', ', $days);
}

/**
 * Add subject to student
 * 
 * @param PDO $conn Database connection
 * @param int $user_id Student user ID
 * @param int $section_schedule_id Section schedule ID
 * @return array ['success' => bool, 'message' => string]
 */
function addSubjectToStudent(PDO $conn, int $user_id, int $section_schedule_id): array {
    try {
        // Only start transaction if one isn't already active
        $transaction_started = false;
        if (!$conn->inTransaction()) {
            $conn->beginTransaction();
            $transaction_started = true;
        }
        
        // Get section schedule details
        $schedule_query = "SELECT ss.*, s.id as section_id, s.current_enrolled, s.max_capacity, c.course_code, c.course_name, c.units
                          FROM section_schedules ss
                          JOIN sections s ON ss.section_id = s.id
                          JOIN curriculum c ON ss.curriculum_id = c.id
                          WHERE ss.id = :schedule_id
                          AND s.status = 'active'";
        $schedule_stmt = $conn->prepare($schedule_query);
        $schedule_stmt->bindParam(':schedule_id', $section_schedule_id, PDO::PARAM_INT);
        $schedule_stmt->execute();
        $schedule_data = $schedule_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$schedule_data) {
            if ($transaction_started) {
                $conn->rollBack();
            }
            return ['success' => false, 'message' => 'Schedule not found or section is inactive'];
        }
        
        // Check if section has capacity
        if ($schedule_data['current_enrolled'] >= $schedule_data['max_capacity']) {
            if ($transaction_started) {
                $conn->rollBack();
            }
            return ['success' => false, 'message' => 'Section is full'];
        }
        
        // Check if student already has this schedule
        $existing_check = "SELECT id FROM student_schedules 
                          WHERE user_id = :user_id 
                          AND section_schedule_id = :schedule_id 
                          AND status = 'active'";
        $existing_stmt = $conn->prepare($existing_check);
        $existing_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $existing_stmt->bindParam(':schedule_id', $section_schedule_id, PDO::PARAM_INT);
        $existing_stmt->execute();
        $existing_schedule = $existing_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing_schedule) {
            // Student already has this schedule - adjustment already applied or duplicate request
            // Return success since the goal (student having this schedule) is already achieved
            // Don't increment capacity since student is already enrolled
            if ($transaction_started) {
                $conn->rollBack();
            }
            return ['success' => true, 'message' => 'Student already has this subject schedule - adjustment already applied'];
        }
        
        // Insert into student_schedules
        $insert_student_schedule = $conn->prepare("INSERT INTO student_schedules 
                                                   (user_id, section_schedule_id, course_code, course_name, units,
                                                    schedule_monday, schedule_tuesday, schedule_wednesday, schedule_thursday,
                                                    schedule_friday, schedule_saturday, schedule_sunday,
                                                    time_start, time_end, room, professor_name, professor_initial, status)
                                                   VALUES 
                                                   (:user_id, :section_schedule_id, :course_code, :course_name, :units,
                                                    :schedule_monday, :schedule_tuesday, :schedule_wednesday, :schedule_thursday,
                                                    :schedule_friday, :schedule_saturday, :schedule_sunday,
                                                    :time_start, :time_end, :room, :professor_name, :professor_initial, 'active')");
        $insert_student_schedule->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $insert_student_schedule->bindParam(':section_schedule_id', $section_schedule_id, PDO::PARAM_INT);
        $insert_student_schedule->bindParam(':course_code', $schedule_data['course_code']);
        $insert_student_schedule->bindParam(':course_name', $schedule_data['course_name']);
        $insert_student_schedule->bindParam(':units', $schedule_data['units']);
        $insert_student_schedule->bindParam(':schedule_monday', $schedule_data['schedule_monday']);
        $insert_student_schedule->bindParam(':schedule_tuesday', $schedule_data['schedule_tuesday']);
        $insert_student_schedule->bindParam(':schedule_wednesday', $schedule_data['schedule_wednesday']);
        $insert_student_schedule->bindParam(':schedule_thursday', $schedule_data['schedule_thursday']);
        $insert_student_schedule->bindParam(':schedule_friday', $schedule_data['schedule_friday']);
        $insert_student_schedule->bindParam(':schedule_saturday', $schedule_data['schedule_saturday']);
        $insert_student_schedule->bindParam(':schedule_sunday', $schedule_data['schedule_sunday']);
        $insert_student_schedule->bindParam(':time_start', $schedule_data['time_start']);
        $insert_student_schedule->bindParam(':time_end', $schedule_data['time_end']);
        $insert_student_schedule->bindParam(':room', $schedule_data['room']);
        $insert_student_schedule->bindParam(':professor_name', $schedule_data['professor_name']);
        $insert_student_schedule->bindParam(':professor_initial', $schedule_data['professor_initial']);
        $insert_student_schedule->execute();
        
        // Update section capacity
        $update_section = $conn->prepare("UPDATE sections SET current_enrolled = current_enrolled + 1 WHERE id = :section_id");
        $update_section->bindParam(':section_id', $schedule_data['section_id'], PDO::PARAM_INT);
        $update_section->execute();
        
        // Record change in adjustment_period_changes table (already exists from approval, just update status if needed)
        // The change record should already exist from when student submitted the request
        // We just need to ensure it's marked as approved
        
        if ($transaction_started) {
            $conn->commit();
        }
        
        return ['success' => true, 'message' => 'Subject added successfully'];
        
    } catch (PDOException $e) {
        if ($transaction_started && $conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('Error adding subject to student: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Error adding subject: ' . $e->getMessage()];
    }
}

/**
 * Remove subject from student
 * 
 * @param PDO $conn Database connection
 * @param int $user_id Student user ID
 * @param int $section_schedule_id Section schedule ID
 * @return array ['success' => bool, 'message' => string]
 */
function removeSubjectFromStudent(PDO $conn, int $user_id, int $section_schedule_id): array {
    try {
        // Only start transaction if one isn't already active
        $transaction_started = false;
        if (!$conn->inTransaction()) {
            $conn->beginTransaction();
            $transaction_started = true;
        }
        
        // Get schedule details before removal
        // Ensure we only work with active schedules from current semester (via section)
        $schedule_query = "SELECT sts.id, sts.section_schedule_id, ss.curriculum_id, ss.section_id,
                          s.academic_year, s.semester, s.status as section_status
                         FROM student_schedules sts
                         JOIN section_schedules ss ON sts.section_schedule_id = ss.id
                         JOIN sections s ON ss.section_id = s.id
                         WHERE sts.user_id = :user_id
                         AND sts.section_schedule_id = :schedule_id
                         AND sts.status = 'active'
                         AND s.status = 'active'";
        $schedule_stmt = $conn->prepare($schedule_query);
        $schedule_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $schedule_stmt->bindParam(':schedule_id', $section_schedule_id, PDO::PARAM_INT);
        $schedule_stmt->execute();
        $schedule_data = $schedule_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$schedule_data) {
            if ($transaction_started) {
                $conn->rollBack();
            }
            return ['success' => false, 'message' => 'Subject schedule not found or not active'];
        }
        
        // Update student_schedules status to 'dropped'
        $update_query = "UPDATE student_schedules 
                        SET status = 'dropped', updated_at = NOW()
                        WHERE id = :id";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bindParam(':id', $schedule_data['id'], PDO::PARAM_INT);
        $update_stmt->execute();
        
        // Update section capacity
        $section_query = "UPDATE sections 
                         SET current_enrolled = current_enrolled - 1
                         WHERE id = :section_id";
        $section_stmt = $conn->prepare($section_query);
        $section_stmt->bindParam(':section_id', $schedule_data['section_id'], PDO::PARAM_INT);
        $section_stmt->execute();
        
        // Record change in adjustment_period_changes table
        $change_query = "INSERT INTO adjustment_period_changes 
                       (user_id, change_type, curriculum_id, old_section_schedule_id, status)
                       VALUES (:user_id, 'remove', :curriculum_id, :schedule_id, 'approved')";
        $change_stmt = $conn->prepare($change_query);
        $change_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $change_stmt->bindParam(':curriculum_id', $schedule_data['curriculum_id'], PDO::PARAM_INT);
        $change_stmt->bindParam(':schedule_id', $section_schedule_id, PDO::PARAM_INT);
        $change_stmt->execute();
        
        if ($transaction_started) {
            $conn->commit();
        }
        return ['success' => true, 'message' => 'Subject removed successfully'];
        
    } catch (PDOException $e) {
        if ($transaction_started && $conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('Error removing subject from student: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Error removing subject: ' . $e->getMessage()];
    }
}

/**
 * Change student schedule
 * 
 * @param PDO $conn Database connection
 * @param int $user_id Student user ID
 * @param int $old_section_schedule_id Old schedule ID
 * @param int $new_section_schedule_id New schedule ID
 * @return array ['success' => bool, 'message' => string]
 */
function changeStudentSchedule(PDO $conn, int $user_id, int $old_section_schedule_id, int $new_section_schedule_id): array {
    try {
        // Only start transaction if one isn't already active
        $transaction_started = false;
        if (!$conn->inTransaction()) {
            $conn->beginTransaction();
            $transaction_started = true;
        }
        
        // Get old schedule details
        // Ensure we only work with active schedules from current semester (via section)
        $old_schedule_query = "SELECT sts.id, sts.section_schedule_id, ss.curriculum_id, ss.section_id as old_section_id,
                              s.academic_year, s.semester, s.status as section_status
                              FROM student_schedules sts
                              JOIN section_schedules ss ON sts.section_schedule_id = ss.id
                              JOIN sections s ON ss.section_id = s.id
                              WHERE sts.user_id = :user_id
                              AND sts.section_schedule_id = :schedule_id
                              AND sts.status = 'active'
                              AND s.status = 'active'";
        $old_schedule_stmt = $conn->prepare($old_schedule_query);
        $old_schedule_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $old_schedule_stmt->bindParam(':schedule_id', $old_section_schedule_id, PDO::PARAM_INT);
        $old_schedule_stmt->execute();
        $old_schedule_data = $old_schedule_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$old_schedule_data) {
            if ($transaction_started) {
                $conn->rollBack();
            }
            return ['success' => false, 'message' => 'Old schedule not found or not active'];
        }
        
        // Verify new schedule is also from an active section (should be same semester)
        $new_schedule_verify_query = "SELECT s.status as section_status, s.academic_year, s.semester
                                     FROM section_schedules ss
                                     JOIN sections s ON ss.section_id = s.id
                                     WHERE ss.id = :schedule_id";
        $new_schedule_verify_stmt = $conn->prepare($new_schedule_verify_query);
        $new_schedule_verify_stmt->bindParam(':schedule_id', $new_section_schedule_id, PDO::PARAM_INT);
        $new_schedule_verify_stmt->execute();
        $new_schedule_verify = $new_schedule_verify_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$new_schedule_verify || $new_schedule_verify['section_status'] !== 'active') {
            if ($transaction_started) {
                $conn->rollBack();
            }
            return ['success' => false, 'message' => 'New schedule section is not active'];
        }
        
        // Get new schedule details
        $new_schedule_query = "SELECT ss.*, s.id as section_id, s.current_enrolled, s.max_capacity
                              FROM section_schedules ss
                              JOIN sections s ON ss.section_id = s.id
                              WHERE ss.id = :schedule_id";
        $new_schedule_stmt = $conn->prepare($new_schedule_query);
        $new_schedule_stmt->bindParam(':schedule_id', $new_section_schedule_id, PDO::PARAM_INT);
        $new_schedule_stmt->execute();
        $new_schedule_data = $new_schedule_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$new_schedule_data) {
            if ($transaction_started) {
                $conn->rollBack();
            }
            return ['success' => false, 'message' => 'New schedule not found'];
        }
        
        // Check capacity
        if ($new_schedule_data['current_enrolled'] >= $new_schedule_data['max_capacity']) {
            if ($transaction_started) {
                $conn->rollBack();
            }
            return ['success' => false, 'message' => 'New section is full'];
        }
        
        // Update student_schedules with new schedule details
        $update_query = "UPDATE student_schedules 
                        SET section_schedule_id = :new_schedule_id,
                            course_code = :course_code,
                            course_name = :course_name,
                            units = :units,
                            schedule_monday = :monday,
                            schedule_tuesday = :tuesday,
                            schedule_wednesday = :wednesday,
                            schedule_thursday = :thursday,
                            schedule_friday = :friday,
                            schedule_saturday = :saturday,
                            schedule_sunday = :sunday,
                            time_start = :time_start,
                            time_end = :time_end,
                            room = :room,
                            professor_name = :professor_name,
                            professor_initial = :professor_initial,
                            updated_at = NOW()
                        WHERE id = :id";
        $update_stmt = $conn->prepare($update_query);
        $update_stmt->bindParam(':new_schedule_id', $new_section_schedule_id, PDO::PARAM_INT);
        $update_stmt->bindParam(':course_code', $new_schedule_data['course_code']);
        $update_stmt->bindParam(':course_name', $new_schedule_data['course_name']);
        $update_stmt->bindParam(':units', $new_schedule_data['units'], PDO::PARAM_INT);
        $update_stmt->bindParam(':monday', $new_schedule_data['schedule_monday'], PDO::PARAM_INT);
        $update_stmt->bindParam(':tuesday', $new_schedule_data['schedule_tuesday'], PDO::PARAM_INT);
        $update_stmt->bindParam(':wednesday', $new_schedule_data['schedule_wednesday'], PDO::PARAM_INT);
        $update_stmt->bindParam(':thursday', $new_schedule_data['schedule_thursday'], PDO::PARAM_INT);
        $update_stmt->bindParam(':friday', $new_schedule_data['schedule_friday'], PDO::PARAM_INT);
        $update_stmt->bindParam(':saturday', $new_schedule_data['schedule_saturday'], PDO::PARAM_INT);
        $update_stmt->bindParam(':sunday', $new_schedule_data['schedule_sunday'], PDO::PARAM_INT);
        $update_stmt->bindParam(':time_start', $new_schedule_data['time_start']);
        $update_stmt->bindParam(':time_end', $new_schedule_data['time_end']);
        $update_stmt->bindParam(':room', $new_schedule_data['room']);
        $update_stmt->bindParam(':professor_name', $new_schedule_data['professor_name']);
        $update_stmt->bindParam(':professor_initial', $new_schedule_data['professor_initial']);
        $update_stmt->bindParam(':id', $old_schedule_data['id'], PDO::PARAM_INT);
        $update_stmt->execute();
        
        // Update old section capacity (decrease)
        $old_section_query = "UPDATE sections 
                             SET current_enrolled = current_enrolled - 1
                             WHERE id = :section_id";
        $old_section_stmt = $conn->prepare($old_section_query);
        $old_section_stmt->bindParam(':section_id', $old_schedule_data['old_section_id'], PDO::PARAM_INT);
        $old_section_stmt->execute();
        
        // Update new section capacity (increase)
        $new_section_query = "UPDATE sections 
                             SET current_enrolled = current_enrolled + 1
                             WHERE id = :section_id";
        $new_section_stmt = $conn->prepare($new_section_query);
        $new_section_stmt->bindParam(':section_id', $new_schedule_data['section_id'], PDO::PARAM_INT);
        $new_section_stmt->execute();
        
        // Record change in adjustment_period_changes table
        $change_query = "INSERT INTO adjustment_period_changes 
                       (user_id, change_type, curriculum_id, old_section_schedule_id, new_section_schedule_id, status)
                       VALUES (:user_id, 'schedule_change', :curriculum_id, :old_schedule_id, :new_schedule_id, 'approved')";
        $change_stmt = $conn->prepare($change_query);
        $change_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $change_stmt->bindParam(':curriculum_id', $old_schedule_data['curriculum_id'], PDO::PARAM_INT);
        $change_stmt->bindParam(':old_schedule_id', $old_section_schedule_id, PDO::PARAM_INT);
        $change_stmt->bindParam(':new_schedule_id', $new_section_schedule_id, PDO::PARAM_INT);
        $change_stmt->execute();
        
        if ($transaction_started) {
            $conn->commit();
        }
        return ['success' => true, 'message' => 'Schedule changed successfully'];
        
    } catch (PDOException $e) {
        if ($transaction_started && $conn->inTransaction()) {
            $conn->rollBack();
        }
        error_log('Error changing student schedule: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Error changing schedule: ' . $e->getMessage()];
    }
}

/**
 * Automatically regenerate COR after adjustment approval
 * Updates the current semester's COR based on active student_schedules
 * 
 * @param PDO $conn Database connection
 * @param int $user_id Student user ID
 * @return array ['success' => bool, 'message' => string, 'cor_id' => int|null]
 */
function regenerateCORAfterAdjustment(PDO $conn, int $user_id): array {
    try {
        // Get current semester info from student_schedules
        $semester_info_query = "SELECT DISTINCT s.academic_year, s.semester, s.year_level, s.id as section_id, s.program_id, s.section_name
                                FROM student_schedules sts
                                JOIN section_schedules ss ON sts.section_schedule_id = ss.id
                                JOIN sections s ON ss.section_id = s.id
                                WHERE sts.user_id = :user_id
                                AND sts.status = 'active'
                                ORDER BY s.academic_year DESC,
                                    CASE s.semester
                                        WHEN 'Second Semester' THEN 2
                                        WHEN 'First Semester' THEN 1
                                        ELSE 0
                                    END DESC,
                                    CASE s.year_level
                                        WHEN '4th Year' THEN 4
                                        WHEN '3rd Year' THEN 3
                                        WHEN '2nd Year' THEN 2
                                        WHEN '1st Year' THEN 1
                                        ELSE 0
                                    END DESC
                                LIMIT 1";
        $semester_info_stmt = $conn->prepare($semester_info_query);
        $semester_info_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $semester_info_stmt->execute();
        $semester_info = $semester_info_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$semester_info) {
            return ['success' => false, 'message' => 'No active schedules found for student.', 'cor_id' => null];
        }
        
        $academic_year = $semester_info['academic_year'];
        $semester = $semester_info['semester'];
        $year_level = $semester_info['year_level'];
        $section_id = $semester_info['section_id'];
        $program_id = $semester_info['program_id'];
        $section_name = $semester_info['section_name'];
        
        // Get all active subjects from student_schedules for current semester
        $subjects_query = "SELECT DISTINCT c.id, c.course_code, c.course_name, c.units, c.year_level, c.semester,
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
        $subjects_stmt = $conn->prepare($subjects_query);
        $subjects_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $subjects_stmt->bindParam(':academic_year', $academic_year);
        $subjects_stmt->bindParam(':semester', $semester);
        $subjects_stmt->execute();
        $subjects = $subjects_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($subjects)) {
            return ['success' => false, 'message' => 'No active subjects found for current semester.', 'cor_id' => null];
        }
        
        // Get student info
        $student_query = "SELECT student_id, first_name, last_name, middle_name, permanent_address, address 
                         FROM users WHERE id = :user_id";
        $student_stmt = $conn->prepare($student_query);
        $student_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $student_stmt->execute();
        $student = $student_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$student) {
            return ['success' => false, 'message' => 'Student not found.', 'cor_id' => null];
        }
        
        // Prepare subjects data for JSON
        $subjects_data = [];
        $total_units = 0;
        foreach ($subjects as $subject) {
            $total_units += (float)$subject['units'];
            
            // Mark backload if year_level doesn't match
            $is_backload = ($subject['year_level'] !== $year_level);
            
            // Format schedule days
            $schedule_days = [];
            if ($subject['schedule_monday']) $schedule_days[] = 'Monday';
            if ($subject['schedule_tuesday']) $schedule_days[] = 'Tuesday';
            if ($subject['schedule_wednesday']) $schedule_days[] = 'Wednesday';
            if ($subject['schedule_thursday']) $schedule_days[] = 'Thursday';
            if ($subject['schedule_friday']) $schedule_days[] = 'Friday';
            if ($subject['schedule_saturday']) $schedule_days[] = 'Saturday';
            if ($subject['schedule_sunday']) $schedule_days[] = 'Sunday';
            
            $subjects_data[] = [
                'course_code' => $subject['course_code'],
                'course_name' => $subject['course_name'],
                'units' => (float)$subject['units'],
                'year_level' => $subject['year_level'],
                'semester' => $subject['semester'],
                'is_backload' => $is_backload,
                'backload_year_level' => $is_backload ? $subject['year_level'] : null,
                'section_info' => [
                    'section_id' => (int)$subject['section_id'],
                    'section_name' => $subject['section_name'],
                    'schedule_days' => $schedule_days,
                    'time_start' => $subject['time_start'],
                    'time_end' => $subject['time_end'],
                    'room' => $subject['room'] ?: 'TBA',
                    'professor_name' => $subject['professor_name'] ?: 'TBA'
                ]
            ];
        }
        
        // Check if COR exists for this semester
        $check_cor_query = "SELECT id FROM certificate_of_registration 
                           WHERE user_id = :user_id 
                           AND academic_year = :academic_year
                           AND semester = :semester
                           AND section_id = :section_id
                           ORDER BY created_at DESC
                           LIMIT 1";
        $check_cor_stmt = $conn->prepare($check_cor_query);
        $check_cor_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $check_cor_stmt->bindParam(':academic_year', $academic_year);
        $check_cor_stmt->bindParam(':semester', $semester);
        $check_cor_stmt->bindParam(':section_id', $section_id, PDO::PARAM_INT);
        $check_cor_stmt->execute();
        $existing_cor = $check_cor_stmt->fetch(PDO::FETCH_ASSOC);
        
        $student_address = $student['permanent_address'] ?: $student['address'] ?: '';
        $registration_date = date('Y-m-d');
        
        if ($existing_cor) {
            // Update existing COR
            $update_cor = "UPDATE certificate_of_registration 
                          SET student_number = :student_number,
                              student_last_name = :student_last_name,
                              student_first_name = :student_first_name,
                              student_middle_name = :student_middle_name,
                              student_address = :student_address,
                              year_level = :year_level,
                              section_id = :section_id,
                              section_name = :section_name,
                              subjects_json = :subjects_json,
                              total_units = :total_units,
                              created_at = NOW()
                          WHERE id = :cor_id";
            
            $cor_stmt = $conn->prepare($update_cor);
            $cor_stmt->execute([
                ':cor_id' => $existing_cor['id'],
                ':student_number' => $student['student_id'],
                ':student_last_name' => $student['last_name'],
                ':student_first_name' => $student['first_name'],
                ':student_middle_name' => $student['middle_name'] ?: null,
                ':student_address' => $student_address ?: null,
                ':year_level' => $year_level,
                ':section_id' => $section_id,
                ':section_name' => $section_name,
                ':subjects_json' => json_encode($subjects_data, JSON_UNESCAPED_UNICODE),
                ':total_units' => $total_units
            ]);
            
            return ['success' => true, 'message' => 'COR updated successfully.', 'cor_id' => $existing_cor['id']];
        } else {
            // Create new COR
            $insert_cor = "INSERT INTO certificate_of_registration 
                          (user_id, program_id, section_id, student_number, student_last_name, 
                           student_first_name, student_middle_name, student_address, academic_year, 
                           year_level, semester, section_name, registration_date, subjects_json, 
                           total_units, created_by)
                          VALUES 
                          (:user_id, :program_id, :section_id, :student_number, :student_last_name,
                           :student_first_name, :student_middle_name, :student_address, :academic_year,
                           :year_level, :semester, :section_name, :registration_date, :subjects_json,
                           :total_units, :created_by)";
            
            $cor_stmt = $conn->prepare($insert_cor);
            $cor_stmt->execute([
                ':user_id' => $user_id,
                ':program_id' => $program_id,
                ':section_id' => $section_id,
                ':student_number' => $student['student_id'],
                ':student_last_name' => $student['last_name'],
                ':student_first_name' => $student['first_name'],
                ':student_middle_name' => $student['middle_name'] ?: null,
                ':student_address' => $student_address ?: null,
                ':academic_year' => $academic_year,
                ':year_level' => $year_level,
                ':semester' => $semester,
                ':section_name' => $section_name,
                ':registration_date' => $registration_date,
                ':subjects_json' => json_encode($subjects_data, JSON_UNESCAPED_UNICODE),
                ':total_units' => $total_units,
                ':created_by' => $_SESSION['user_id'] ?? null
            ]);
            
            $cor_id = $conn->lastInsertId();
            return ['success' => true, 'message' => 'COR created successfully.', 'cor_id' => $cor_id];
        }
        
    } catch (PDOException $e) {
        error_log('Error regenerating COR after adjustment: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Error regenerating COR: ' . $e->getMessage(), 'cor_id' => null];
    }
}

/**
 * Generate COR from current COR subjects with adjustments applied
 * This function starts with the current COR subjects and only applies the requested adjustments
 * 
 * @param PDO $conn Database connection
 * @param int $user_id Student user ID
 * @param array $current_cor Current COR data
 * @param array $adjustments Array of adjustment requests
 * @return array ['success' => bool, 'message' => string, 'cor_id' => int|null]
 */
function generateCORFromCurrentWithAdjustments(PDO $conn, int $user_id, array $current_cor, array $adjustments): array {
    try {
        // Start with current COR subjects - include all existing subjects
        $subjects_data = [];
        if (!empty($current_cor['subjects_json'])) {
            $subjects_data = json_decode($current_cor['subjects_json'], true) ?: [];
        }
        
        // Apply adjustments to the subjects list
        foreach ($adjustments as $adj) {
            if ($adj['change_type'] === 'add') {
                // Check if subject already exists in current COR to avoid duplicates
                $course_code = $adj['course_code'] ?? null;
                $already_exists = false;
                if ($course_code) {
                    foreach ($subjects_data as $existing_subject) {
                        if (($existing_subject['course_code'] ?? '') === $course_code) {
                            $already_exists = true;
                            break;
                        }
                    }
                }
                
                // Only add if it doesn't already exist
                if (!$already_exists) {
                    // Build subject data using the same structure as preview to ensure exact match
                    $schedule_days = [];
                    if (!empty($adj['new_monday'])) $schedule_days[] = 'Monday';
                    if (!empty($adj['new_tuesday'])) $schedule_days[] = 'Tuesday';
                    if (!empty($adj['new_wednesday'])) $schedule_days[] = 'Wednesday';
                    if (!empty($adj['new_thursday'])) $schedule_days[] = 'Thursday';
                    if (!empty($adj['new_friday'])) $schedule_days[] = 'Friday';
                    if (!empty($adj['new_saturday'])) $schedule_days[] = 'Saturday';
                    if (!empty($adj['new_sunday'])) $schedule_days[] = 'Sunday';
                    
                    // Get section_id from new_section_schedule_id if needed
                    $section_id = 0;
                    if (!empty($adj['new_section_schedule_id'])) {
                        $section_query = "SELECT s.id FROM section_schedules ss
                                         JOIN sections s ON ss.section_id = s.id
                                         WHERE ss.id = :section_schedule_id";
                        $section_stmt = $conn->prepare($section_query);
                        $section_stmt->bindParam(':section_schedule_id', $adj['new_section_schedule_id'], PDO::PARAM_INT);
                        $section_stmt->execute();
                        $section_data = $section_stmt->fetch(PDO::FETCH_ASSOC);
                        if ($section_data) {
                            $section_id = (int)$section_data['id'];
                        }
                    }
                    
                    $subjects_data[] = [
                        'course_code' => $adj['course_code'],
                        'course_name' => $adj['course_name'],
                        'units' => (float)$adj['units'],
                        'year_level' => $adj['new_year_level'] ?? $current_cor['year_level'],
                        'semester' => $adj['new_semester'] ?? $current_cor['semester'],
                        'is_backload' => false,
                        'is_added' => true,
                        'remarks' => $adj['remarks'] ?? null,
                        'section_info' => [
                            'section_id' => $section_id,
                            'section_name' => $adj['new_section_name'] ?? '',
                            'schedule_days' => $schedule_days,
                            'time_start' => $adj['new_time_start'] ?? '',
                            'time_end' => $adj['new_time_end'] ?? '',
                            'room' => $adj['new_room'] ?: 'TBA',
                            'professor_name' => $adj['new_professor_name'] ?: 'TBA'
                        ]
                    ];
                }
            } elseif ($adj['change_type'] === 'remove') {
                // Remove subject - find by old_section_schedule_id
                $removed = removeSubjectFromCORList($conn, $subjects_data, $adj['old_section_schedule_id']);
                if ($removed) {
                    $subjects_data = array_values($subjects_data); // Re-index array
                }
            } elseif ($adj['change_type'] === 'schedule_change') {
                // Change subject schedule - update existing subject
                $updated = updateSubjectInCORList($conn, $subjects_data, $adj['old_section_schedule_id'], $adj['new_section_schedule_id'], $current_cor['year_level']);
                // No need to re-index for updates
            }
        }
        
        // Calculate total units
        $total_units = 0;
        foreach ($subjects_data as $subject) {
            $total_units += (float)($subject['units'] ?? 0);
        }
        
        // Update or create COR
        $check_cor_query = "SELECT id FROM certificate_of_registration 
                           WHERE user_id = :user_id 
                           AND academic_year = :academic_year
                           AND semester = :semester
                           AND section_id = :section_id
                           ORDER BY created_at DESC
                           LIMIT 1";
        $check_cor_stmt = $conn->prepare($check_cor_query);
        $check_cor_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $check_cor_stmt->bindParam(':academic_year', $current_cor['academic_year']);
        $check_cor_stmt->bindParam(':semester', $current_cor['semester']);
        $check_cor_stmt->bindParam(':section_id', $current_cor['section_id'], PDO::PARAM_INT);
        $check_cor_stmt->execute();
        $existing_cor = $check_cor_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing_cor) {
            // Update existing COR
            $update_cor = "UPDATE certificate_of_registration 
                          SET subjects_json = :subjects_json,
                              total_units = :total_units
                          WHERE id = :cor_id";
            $cor_stmt = $conn->prepare($update_cor);
            $cor_stmt->execute([
                ':subjects_json' => json_encode($subjects_data, JSON_UNESCAPED_UNICODE),
                ':total_units' => $total_units,
                ':cor_id' => $existing_cor['id']
            ]);
            
            return ['success' => true, 'message' => 'COR updated successfully.', 'cor_id' => $existing_cor['id']];
        } else {
            // Create new COR (shouldn't happen, but handle it)
            $insert_cor = "INSERT INTO certificate_of_registration 
                          (user_id, program_id, section_id, student_number, student_last_name, 
                           student_first_name, student_middle_name, student_address, academic_year, 
                           year_level, semester, section_name, registration_date, subjects_json, 
                           total_units, created_by)
                          VALUES 
                          (:user_id, :program_id, :section_id, :student_number, :student_last_name,
                           :student_first_name, :student_middle_name, :student_address, :academic_year,
                           :year_level, :semester, :section_name, :registration_date, :subjects_json,
                           :total_units, :created_by)";
            
            $cor_stmt = $conn->prepare($insert_cor);
            $cor_stmt->execute([
                ':user_id' => $user_id,
                ':program_id' => $current_cor['program_id'],
                ':section_id' => $current_cor['section_id'],
                ':student_number' => $current_cor['student_number'],
                ':student_last_name' => $current_cor['student_last_name'],
                ':student_first_name' => $current_cor['student_first_name'],
                ':student_middle_name' => $current_cor['student_middle_name'] ?: null,
                ':student_address' => $current_cor['student_address'] ?: null,
                ':academic_year' => $current_cor['academic_year'],
                ':year_level' => $current_cor['year_level'],
                ':semester' => $current_cor['semester'],
                ':section_name' => $current_cor['section_name'],
                ':registration_date' => date('Y-m-d'),
                ':subjects_json' => json_encode($subjects_data, JSON_UNESCAPED_UNICODE),
                ':total_units' => $total_units,
                ':created_by' => $_SESSION['user_id'] ?? null
            ]);
            
            $cor_id = $conn->lastInsertId();
            return ['success' => true, 'message' => 'COR created successfully.', 'cor_id' => $cor_id];
        }
        
    } catch (PDOException $e) {
        error_log('Error generating COR from current with adjustments: ' . $e->getMessage());
        return ['success' => false, 'message' => 'Error generating COR: ' . $e->getMessage(), 'cor_id' => null];
    }
}

/**
 * Get subject details from section_schedule_id
 * 
 * @param PDO $conn Database connection
 * @param int $section_schedule_id Section schedule ID
 * @param string $current_year_level Current year level for backload detection
 * @return array|null Subject data array or null if not found
 */
function getSubjectDetailsFromSchedule(PDO $conn, int $section_schedule_id, string $current_year_level): ?array {
    try {
        $query = "SELECT c.id, c.course_code, c.course_name, c.units, c.year_level, c.semester,
                  ss.schedule_monday, ss.schedule_tuesday, ss.schedule_wednesday, ss.schedule_thursday,
                  ss.schedule_friday, ss.schedule_saturday, ss.schedule_sunday,
                  ss.time_start, ss.time_end, ss.room, ss.professor_name, ss.professor_initial,
                  s.section_name, s.id as section_id
                  FROM section_schedules ss
                  JOIN curriculum c ON ss.curriculum_id = c.id
                  JOIN sections s ON ss.section_id = s.id
                  WHERE ss.id = :section_schedule_id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':section_schedule_id', $section_schedule_id, PDO::PARAM_INT);
        $stmt->execute();
        $subject = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$subject) {
            return null;
        }
        
        // Mark backload if year_level doesn't match
        $is_backload = ($subject['year_level'] !== $current_year_level);
        
        // Format schedule days
        $schedule_days = [];
        if ($subject['schedule_monday']) $schedule_days[] = 'Monday';
        if ($subject['schedule_tuesday']) $schedule_days[] = 'Tuesday';
        if ($subject['schedule_wednesday']) $schedule_days[] = 'Wednesday';
        if ($subject['schedule_thursday']) $schedule_days[] = 'Thursday';
        if ($subject['schedule_friday']) $schedule_days[] = 'Friday';
        if ($subject['schedule_saturday']) $schedule_days[] = 'Saturday';
        if ($subject['schedule_sunday']) $schedule_days[] = 'Sunday';
        
        return [
            'course_code' => $subject['course_code'],
            'course_name' => $subject['course_name'],
            'units' => (float)$subject['units'],
            'year_level' => $subject['year_level'],
            'semester' => $subject['semester'],
            'is_backload' => $is_backload,
            'backload_year_level' => $is_backload ? $subject['year_level'] : null,
            'section_info' => [
                'section_id' => (int)$subject['section_id'],
                'section_name' => $subject['section_name'],
                'schedule_days' => $schedule_days,
                'time_start' => $subject['time_start'],
                'time_end' => $subject['time_end'],
                'room' => $subject['room'] ?: 'TBA',
                'professor_name' => $subject['professor_name'] ?: 'TBA'
            ]
        ];
    } catch (PDOException $e) {
        error_log('Error getting subject details from schedule: ' . $e->getMessage());
        return null;
    }
}

/**
 * Remove subject from COR subjects list by old_section_schedule_id
 * 
 * @param PDO $conn Database connection
 * @param array $subjects_data Current subjects array (passed by reference)
 * @param int $old_section_schedule_id Old section schedule ID
 * @return bool True if removed, false if not found
 */
function removeSubjectFromCORList(PDO $conn, array &$subjects_data, int $old_section_schedule_id): bool {
    try {
        // Get the course details from the old section_schedule to match precisely
        $query = "SELECT c.course_code, s.section_name, ss.time_start, ss.time_end,
                  ss.schedule_monday, ss.schedule_tuesday, ss.schedule_wednesday, ss.schedule_thursday,
                  ss.schedule_friday, ss.schedule_saturday, ss.schedule_sunday
                  FROM section_schedules ss
                  JOIN curriculum c ON ss.curriculum_id = c.id
                  JOIN sections s ON ss.section_id = s.id
                  WHERE ss.id = :section_schedule_id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':section_schedule_id', $old_section_schedule_id, PDO::PARAM_INT);
        $stmt->execute();
        $schedule = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$schedule) {
            return false;
        }
        
        $course_code = $schedule['course_code'];
        $section_name = $schedule['section_name'];
        $time_start = $schedule['time_start'];
        $time_end = $schedule['time_end'];
        
        // Build schedule days array for matching
        $schedule_days = [];
        if ($schedule['schedule_monday']) $schedule_days[] = 'Monday';
        if ($schedule['schedule_tuesday']) $schedule_days[] = 'Tuesday';
        if ($schedule['schedule_wednesday']) $schedule_days[] = 'Wednesday';
        if ($schedule['schedule_thursday']) $schedule_days[] = 'Thursday';
        if ($schedule['schedule_friday']) $schedule_days[] = 'Friday';
        if ($schedule['schedule_saturday']) $schedule_days[] = 'Saturday';
        if ($schedule['schedule_sunday']) $schedule_days[] = 'Sunday';
        
        // Find and remove the subject from the list - match by course_code, section_name, and schedule
        foreach ($subjects_data as $index => $subject) {
            if ($subject['course_code'] === $course_code) {
                // Additional matching by section_name and schedule if available
                $subject_section = $subject['section_info']['section_name'] ?? '';
                $subject_time_start = $subject['section_info']['time_start'] ?? '';
                $subject_time_end = $subject['section_info']['time_end'] ?? '';
                $subject_schedule_days = $subject['section_info']['schedule_days'] ?? [];
                
                // Match by course_code + section_name + time (most precise)
                if ($subject_section === $section_name && 
                    $subject_time_start === $time_start && 
                    $subject_time_end === $time_end) {
                    unset($subjects_data[$index]);
                    return true;
                }
                // Fallback: match by course_code + section_name only
                elseif ($subject_section === $section_name) {
                    unset($subjects_data[$index]);
                    return true;
                }
                // Last resort: match by course_code only (if only one instance exists)
                elseif (count(array_filter($subjects_data, function($s) use ($course_code) {
                    return $s['course_code'] === $course_code;
                })) === 1) {
                    unset($subjects_data[$index]);
                    return true;
                }
            }
        }
        
        return false;
    } catch (PDOException $e) {
        error_log('Error removing subject from COR list: ' . $e->getMessage());
        return false;
    }
}

/**
 * Update subject in COR subjects list by changing from old_section_schedule_id to new_section_schedule_id
 * 
 * @param PDO $conn Database connection
 * @param array $subjects_data Current subjects array (passed by reference)
 * @param int $old_section_schedule_id Old section schedule ID
 * @param int $new_section_schedule_id New section schedule ID
 * @param string $current_year_level Current year level for backload detection
 * @return bool True if updated, false if not found
 */
function updateSubjectInCORList(PDO $conn, array &$subjects_data, int $old_section_schedule_id, int $new_section_schedule_id, string $current_year_level): bool {
    try {
        // Get the course details from the old section_schedule to match precisely
        $old_query = "SELECT c.course_code, s.section_name, ss.time_start, ss.time_end,
                      ss.schedule_monday, ss.schedule_tuesday, ss.schedule_wednesday, ss.schedule_thursday,
                      ss.schedule_friday, ss.schedule_saturday, ss.schedule_sunday
                      FROM section_schedules ss
                      JOIN curriculum c ON ss.curriculum_id = c.id
                      JOIN sections s ON ss.section_id = s.id
                      WHERE ss.id = :section_schedule_id";
        $old_stmt = $conn->prepare($old_query);
        $old_stmt->bindParam(':section_schedule_id', $old_section_schedule_id, PDO::PARAM_INT);
        $old_stmt->execute();
        $old_schedule = $old_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$old_schedule) {
            return false;
        }
        
        $course_code = $old_schedule['course_code'];
        $section_name = $old_schedule['section_name'];
        $time_start = $old_schedule['time_start'];
        $time_end = $old_schedule['time_end'];
        
        // Get new subject details
        $new_subject = getSubjectDetailsFromSchedule($conn, $new_section_schedule_id, $current_year_level);
        if (!$new_subject) {
            return false;
        }
        
        // Find and update the subject in the list - match by course_code, section_name, and schedule
        foreach ($subjects_data as $index => $subject) {
            if ($subject['course_code'] === $course_code) {
                // Additional matching by section_name and schedule if available
                $subject_section = $subject['section_info']['section_name'] ?? '';
                $subject_time_start = $subject['section_info']['time_start'] ?? '';
                $subject_time_end = $subject['section_info']['time_end'] ?? '';
                
                // Match by course_code + section_name + time (most precise)
                if ($subject_section === $section_name && 
                    $subject_time_start === $time_start && 
                    $subject_time_end === $time_end) {
                    $subjects_data[$index] = $new_subject;
                    return true;
                }
                // Fallback: match by course_code + section_name only
                elseif ($subject_section === $section_name) {
                    $subjects_data[$index] = $new_subject;
                    return true;
                }
                // Last resort: match by course_code only (if only one instance exists)
                elseif (count(array_filter($subjects_data, function($s) use ($course_code) {
                    return $s['course_code'] === $course_code;
                })) === 1) {
                    $subjects_data[$index] = $new_subject;
                    return true;
                }
            }
        }
        
        return false;
    } catch (PDOException $e) {
        error_log('Error updating subject in COR list: ' . $e->getMessage());
        return false;
    }
}

