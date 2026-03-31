<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../config/audit_helper.php';

// Check if user is logged in and is an admin
if (!isLoggedIn() || !isAdmin()) {
    redirect('../public/login.php');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = (int)$_POST['user_id'];
    $course = $_POST['course'] ?? '';
    $year_level = $_POST['year_level'] ?? '1st Year';
    $student_type = $_POST['student_type'] ?? 'Regular';
    $academic_year = $_POST['academic_year'] ?? 'AY 2024-2025';
    $semester = $_POST['semester'] ?? 'Fall 2024';
    
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        // Get old values for audit log
        $old_query = "SELECT course, year_level, student_type, academic_year, semester 
                     FROM enrolled_students WHERE user_id = :user_id LIMIT 1";
        $old_stmt = $conn->prepare($old_query);
        $old_stmt->bindParam(':user_id', $user_id);
        $old_stmt->execute();
        $old_values = $old_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        
        // Sync user data to enrolled_students table with updated enrollment info
        require_once 'sync_user_to_enrolled_students.php';
        require_once '../config/student_data_helper.php';
        
        $new_values = [
            'course' => $course,
            'year_level' => $year_level,
            'student_type' => $student_type,
            'academic_year' => $academic_year,
            'semester' => $semester
        ];
        
        // Ensure data sync after update
        $success = ensureStudentDataSync($conn, $user_id, $new_values);
        
        if ($success) {
            // Log the action
            logAdminAction(
                AUDIT_ACTION_ENROLLED_STUDENT_UPDATE,
                "Updated enrolled student information for user ID {$user_id}",
                AUDIT_ENTITY_ENROLLED_STUDENT,
                $user_id,
                $old_values,
                $new_values,
                'success'
            );
            
            $_SESSION['message'] = 'Enrolled student information updated successfully';
        } else {
            // Log failed action
            logAdminAction(
                AUDIT_ACTION_ENROLLED_STUDENT_UPDATE,
                "Failed to update enrolled student information for user ID {$user_id}",
                AUDIT_ENTITY_ENROLLED_STUDENT,
                $user_id,
                $old_values,
                $new_values,
                'failed',
                'Data sync failed'
            );
            
            $_SESSION['message'] = 'Failed to update enrolled student information';
        }
        
    } catch(PDOException $e) {
        // Log error
        logAdminAction(
            AUDIT_ACTION_ENROLLED_STUDENT_UPDATE,
            "Error updating enrolled student information for user ID {$user_id}",
            AUDIT_ENTITY_ENROLLED_STUDENT,
            $user_id,
            null,
            null,
            'failed',
            $e->getMessage()
        );
        
        $_SESSION['message'] = 'Database error: ' . $e->getMessage();
    }
}

redirect('admin/dashboard.php');
?>

