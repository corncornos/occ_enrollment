<?php
declare(strict_types=1);

/**
 * Section Assignment Helper
 * Shared functionality for assigning sections to students
 * Used by both registration and registrar staff assignment
 */

/**
 * Assign a section to a student
 * 
 * @param PDO $conn Database connection
 * @param int $user_id Student user ID
 * @param int $section_id Section ID
 * @param string $status Enrollment status ('pending' for registration, 'active' for direct assignment)
 * @param bool $create_schedules Whether to create student_schedules entries
 * @param bool $increment_capacity Whether to increment section current_enrolled count
 * @return array ['success' => bool, 'message' => string, 'enrollment_id' => int|null]
 */
function assignSectionToStudent(PDO $conn, int $user_id, int $section_id, string $status = 'pending', bool $create_schedules = false, bool $increment_capacity = false): array {
    try {
        // Verify section exists and has capacity
        $check_section_sql = "SELECT s.id, s.section_name, s.max_capacity, s.current_enrolled, s.year_level, s.semester, s.academic_year, s.program_id,
                             p.program_code, p.program_name
                             FROM sections s 
                             JOIN programs p ON s.program_id = p.id
                             WHERE s.id = :section_id AND s.status = 'active'";
        $check_section_stmt = $conn->prepare($check_section_sql);
        $check_section_stmt->bindParam(':section_id', $section_id, PDO::PARAM_INT);
        $check_section_stmt->execute();
        $section = $check_section_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$section) {
            return ['success' => false, 'message' => 'Section not found or inactive'];
        }
        
        // Check capacity - always validate, even if not incrementing yet
        // This prevents students from selecting full sections during registration
        if ($section['current_enrolled'] >= $section['max_capacity']) {
            return ['success' => false, 'message' => 'Section "' . htmlspecialchars($section['section_name']) . '" is full. Please select another section.'];
        }
        
        // Additional check if incrementing (double-check before incrementing)
        if ($increment_capacity && $section['current_enrolled'] >= $section['max_capacity']) {
            return ['success' => false, 'message' => 'Section is full'];
        }
        
        // Check if enrollment already exists
        $check_existing = "SELECT id, status FROM section_enrollments 
                          WHERE user_id = :user_id AND section_id = :section_id";
        $check_existing_stmt = $conn->prepare($check_existing);
        $check_existing_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $check_existing_stmt->bindParam(':section_id', $section_id, PDO::PARAM_INT);
        $check_existing_stmt->execute();
        $existing_enrollment = $check_existing_stmt->fetch(PDO::FETCH_ASSOC);
        
        // If enrollment exists with same status, return success
        if ($existing_enrollment && $existing_enrollment['status'] === $status) {
            return [
                'success' => true, 
                'message' => 'Section already assigned',
                'enrollment_id' => (int)$existing_enrollment['id'],
                'action' => 'exists'
            ];
        }
        
        // If enrollment exists but with different status, update it
        if ($existing_enrollment) {
            $update_enrollment = $conn->prepare("
                UPDATE section_enrollments 
                SET status = :status, enrolled_date = NOW() 
                WHERE id = :enrollment_id
            ");
            $update_enrollment->bindParam(':status', $status);
            $update_enrollment->bindParam(':enrollment_id', $existing_enrollment['id'], PDO::PARAM_INT);
            $update_enrollment->execute();
            
            $enrollment_id = (int)$existing_enrollment['id'];
            $action = 'updated';
        } else {
            // Insert new enrollment
            $insert_enrollment = $conn->prepare("
                INSERT INTO section_enrollments (section_id, user_id, enrolled_date, status) 
                VALUES (:section_id, :user_id, NOW(), :status)
            ");
            $insert_enrollment->bindParam(':section_id', $section_id, PDO::PARAM_INT);
            $insert_enrollment->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $insert_enrollment->bindParam(':status', $status);
            $insert_enrollment->execute();
            
            $enrollment_id = (int)$conn->lastInsertId();
            $action = 'created';
        }
        
        // Increment section capacity if needed
        if ($increment_capacity && $action !== 'exists') {
            // Only increment if the enrollment was newly created or updated to active
            if ($action === 'created' || ($action === 'updated' && $status === 'active' && ($existing_enrollment['status'] ?? '') !== 'active')) {
                $update_section = $conn->prepare("UPDATE sections SET current_enrolled = current_enrolled + 1 WHERE id = :section_id");
                $update_section->bindParam(':section_id', $section_id, PDO::PARAM_INT);
                $update_section->execute();
            }
        }
        
        // Create student_schedules entries if requested
        if ($create_schedules && $status === 'active') {
            $section_schedules_query = "SELECT * FROM section_schedules WHERE section_id = :section_id";
            $section_schedules_stmt = $conn->prepare($section_schedules_query);
            $section_schedules_stmt->bindParam(':section_id', $section_id, PDO::PARAM_INT);
            $section_schedules_stmt->execute();
            $section_schedules = $section_schedules_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($section_schedules as $schedule) {
                // Check if student_schedule already exists
                $check_student_schedule = $conn->prepare("SELECT id FROM student_schedules 
                                                          WHERE user_id = :user_id 
                                                          AND section_schedule_id = :section_schedule_id 
                                                          AND status = 'active'");
                $check_student_schedule->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                $check_student_schedule->bindParam(':section_schedule_id', $schedule['id'], PDO::PARAM_INT);
                $check_student_schedule->execute();
                
                if ($check_student_schedule->rowCount() == 0) {
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
                    $insert_student_schedule->bindParam(':section_schedule_id', $schedule['id'], PDO::PARAM_INT);
                    $insert_student_schedule->bindParam(':course_code', $schedule['course_code']);
                    $insert_student_schedule->bindParam(':course_name', $schedule['course_name']);
                    $insert_student_schedule->bindParam(':units', $schedule['units']);
                    $insert_student_schedule->bindParam(':schedule_monday', $schedule['schedule_monday']);
                    $insert_student_schedule->bindParam(':schedule_tuesday', $schedule['schedule_tuesday']);
                    $insert_student_schedule->bindParam(':schedule_wednesday', $schedule['schedule_wednesday']);
                    $insert_student_schedule->bindParam(':schedule_thursday', $schedule['schedule_thursday']);
                    $insert_student_schedule->bindParam(':schedule_friday', $schedule['schedule_friday']);
                    $insert_student_schedule->bindParam(':schedule_saturday', $schedule['schedule_saturday']);
                    $insert_student_schedule->bindParam(':schedule_sunday', $schedule['schedule_sunday']);
                    $insert_student_schedule->bindParam(':time_start', $schedule['time_start']);
                    $insert_student_schedule->bindParam(':time_end', $schedule['time_end']);
                    $insert_student_schedule->bindParam(':room', $schedule['room']);
                    $insert_student_schedule->bindParam(':professor_name', $schedule['professor_name']);
                    $insert_student_schedule->bindParam(':professor_initial', $schedule['professor_initial']);
                    $insert_student_schedule->execute();
                }
            }
        }
        
        return [
            'success' => true,
            'message' => 'Section assigned successfully',
            'enrollment_id' => $enrollment_id,
            'action' => $action,
            'section' => $section
        ];
        
    } catch (PDOException $e) {
        error_log('Error in assignSectionToStudent: ' . $e->getMessage());
        return [
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ];
    }
}
