<?php
/**
 * Centralized Student Data Helper
 * 
 * This file provides functions to retrieve student data consistently across all modules.
 * All modules (student, admin, program_head, registrar_staff, admission, dean) should use
 * these functions to ensure data consistency.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../admin/sync_user_to_enrolled_students.php';

/**
 * Get complete student data with automatic sync
 * This ensures enrolled_students table is always up-to-date with users table
 * 
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @param bool $auto_sync Whether to automatically sync if data is inconsistent (default: true)
 * @return array|false Student data array or false if not found
 */
function getStudentData($conn, $user_id, $auto_sync = true) {
    try {
        // First, get data from enrolled_students (preferred source for enrolled students)
        $enrolled_query = "SELECT es.*, u.email, u.status, u.enrollment_status, u.created_at, u.role
                          FROM enrolled_students es
                          JOIN users u ON es.user_id = u.id
                          WHERE es.user_id = :user_id
                          LIMIT 1";
        $enrolled_stmt = $conn->prepare($enrolled_query);
        $enrolled_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $enrolled_stmt->execute();
        $student_data = $enrolled_stmt->fetch(PDO::FETCH_ASSOC);
        
        // If enrolled_students record exists, return it
        if ($student_data) {
            // Auto-sync if enabled and data might be outdated
            if ($auto_sync) {
                // Check if sync is needed by comparing key fields
                $user_query = "SELECT first_name, last_name, email, phone, student_id FROM users WHERE id = :user_id";
                $user_stmt = $conn->prepare($user_query);
                $user_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                $user_stmt->execute();
                $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user_data) {
                    // Check if sync is needed
                    $needs_sync = false;
                    if ($student_data['first_name'] !== $user_data['first_name'] ||
                        $student_data['last_name'] !== $user_data['last_name'] ||
                        $student_data['email'] !== $user_data['email'] ||
                        $student_data['phone'] !== $user_data['phone'] ||
                        ($student_data['student_id'] !== $user_data['student_id'] && !empty($user_data['student_id']))) {
                        $needs_sync = true;
                    }
                    
                    if ($needs_sync) {
                        // Sync the data
                        syncUserToEnrolledStudents($conn, $user_id);
                        // Re-fetch after sync
                        $enrolled_stmt->execute();
                        $student_data = $enrolled_stmt->fetch(PDO::FETCH_ASSOC);
                    }
                }
            }
            
            return $student_data;
        }
        
        // If no enrolled_students record, get from users table and optionally sync
        $user_query = "SELECT * FROM users WHERE id = :user_id";
        $user_stmt = $conn->prepare($user_query);
        $user_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $user_stmt->execute();
        $user_data = $user_stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user_data) {
            return false;
        }
        
        // If user is enrolled, sync to enrolled_students
        if ($auto_sync && $user_data['enrollment_status'] === 'enrolled') {
            syncUserToEnrolledStudents($conn, $user_id);
            // Re-fetch from enrolled_students
            $enrolled_stmt->execute();
            $student_data = $enrolled_stmt->fetch(PDO::FETCH_ASSOC);
            if ($student_data) {
                return $student_data;
            }
        }
        
        // Return user data (for non-enrolled users)
        return $user_data;
        
    } catch (PDOException $e) {
        error_log('Error getting student data: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get student data with section information
 * 
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @param bool $auto_sync Whether to automatically sync (default: true)
 * @return array|false Student data with section info or false if not found
 */
function getStudentDataWithSections($conn, $user_id, $auto_sync = true) {
    $student_data = getStudentData($conn, $user_id, $auto_sync);
    
    if (!$student_data) {
        return false;
    }
    
    // Get section information
    $sections_query = "SELECT se.id as enrollment_id, se.section_id, se.enrolled_date, 
                      s.section_name, s.year_level, s.semester, s.academic_year,
                      s.current_enrolled, s.max_capacity, s.section_type,
                      p.program_code, p.program_name
                      FROM section_enrollments se
                      JOIN sections s ON se.section_id = s.id
                      JOIN programs p ON s.program_id = p.id
                      WHERE se.user_id = :user_id AND se.status = 'active'
                      ORDER BY se.enrolled_date DESC";
    $sections_stmt = $conn->prepare($sections_query);
    $sections_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $sections_stmt->execute();
    $sections = $sections_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $student_data['sections'] = $sections;
    
    return $student_data;
}

/**
 * Get student data with grades
 * 
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @param bool $auto_sync Whether to automatically sync (default: true)
 * @return array|false Student data with grades or false if not found
 */
function getStudentDataWithGrades($conn, $user_id, $auto_sync = true) {
    $student_data = getStudentData($conn, $user_id, $auto_sync);
    
    if (!$student_data) {
        return false;
    }
    
    // Get grades
    $grades_query = "SELECT sg.*, c.course_code, c.course_name, c.units
                    FROM student_grades sg
                    JOIN curriculum c ON sg.curriculum_id = c.id
                    WHERE sg.user_id = :user_id
                    ORDER BY sg.academic_year DESC, sg.semester DESC, c.course_code";
    $grades_stmt = $conn->prepare($grades_query);
    $grades_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $grades_stmt->execute();
    $grades = $grades_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $student_data['grades'] = $grades;
    
    return $student_data;
}

/**
 * Get student data with all related information (sections, grades, CORs, etc.)
 * 
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @param bool $auto_sync Whether to automatically sync (default: true)
 * @return array|false Complete student data or false if not found
 */
function getCompleteStudentData($conn, $user_id, $auto_sync = true) {
    $student_data = getStudentData($conn, $user_id, $auto_sync);
    
    if (!$student_data) {
        return false;
    }
    
    // Get sections
    $sections_query = "SELECT se.id as enrollment_id, se.section_id, se.enrolled_date, 
                      s.section_name, s.year_level, s.semester, s.academic_year,
                      s.current_enrolled, s.max_capacity, s.section_type,
                      p.program_code, p.program_name
                      FROM section_enrollments se
                      JOIN sections s ON se.section_id = s.id
                      JOIN programs p ON s.program_id = p.id
                      WHERE se.user_id = :user_id AND se.status = 'active'
                      ORDER BY se.enrolled_date DESC";
    $sections_stmt = $conn->prepare($sections_query);
    $sections_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $sections_stmt->execute();
    $student_data['sections'] = $sections_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get grades
    $grades_query = "SELECT sg.*, c.course_code, c.course_name, c.units
                    FROM student_grades sg
                    JOIN curriculum c ON sg.curriculum_id = c.id
                    WHERE sg.user_id = :user_id
                    ORDER BY sg.academic_year DESC, sg.semester DESC, c.course_code";
    $grades_stmt = $conn->prepare($grades_query);
    $grades_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $grades_stmt->execute();
    $student_data['grades'] = $grades_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get CORs
    $cors_query = "SELECT * FROM certificate_of_registration 
                  WHERE user_id = :user_id
                  ORDER BY academic_year DESC, semester DESC, created_at DESC";
    $cors_stmt = $conn->prepare($cors_query);
    $cors_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $cors_stmt->execute();
    $student_data['cors'] = $cors_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get enrollment requests
    $enrollment_requests_query = "SELECT * FROM next_semester_enrollments 
                                 WHERE user_id = :user_id
                                 ORDER BY created_at DESC";
    $er_stmt = $conn->prepare($enrollment_requests_query);
    $er_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $er_stmt->execute();
    $student_data['enrollment_requests'] = $er_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    return $student_data;
}

/**
 * Ensure student data is synced between users and enrolled_students tables
 * Call this function whenever student data is updated
 * 
 * @param PDO $conn Database connection
 * @param int $user_id User ID
 * @param array $additional_data Optional additional data for sync
 * @return bool Success status
 */
function ensureStudentDataSync($conn, $user_id, $additional_data = []) {
    return syncUserToEnrolledStudents($conn, $user_id, $additional_data);
}

