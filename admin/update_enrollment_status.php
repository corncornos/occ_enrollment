<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';

// Check if user is logged in and is an admin
if (!isLoggedIn() || (!isAdmin() && !isRegistrarStaff())) {
    redirect('../public/login.php');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_POST['user_id'];
    $enrollment_status = $_POST['enrollment_status'];
    
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        // Update enrollment status in users table
        $sql = "UPDATE users SET enrollment_status = :enrollment_status WHERE id = :user_id";
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':enrollment_status', $enrollment_status);
        $stmt->bindParam(':user_id', $user_id);
        
        if ($stmt->execute()) {
            // If status is 'enrolled', sync user data to enrolled_students table
            if ($enrollment_status == 'enrolled') {
                require_once 'sync_user_to_enrolled_students.php';
                require_once '../config/student_data_helper.php';
                
                // Get section info for additional data
                $section_info_query = "SELECT s.year_level, s.semester, s.academic_year, p.program_code 
                                      FROM section_enrollments se
                                      JOIN sections s ON se.section_id = s.id
                                      JOIN programs p ON s.program_id = p.id
                                      WHERE se.user_id = :user_id AND se.status = 'active'
                                      LIMIT 1";
                $section_info_stmt = $conn->prepare($section_info_query);
                $section_info_stmt->bindParam(':user_id', $user_id);
                $section_info_stmt->execute();
                $section_info = $section_info_stmt->fetch(PDO::FETCH_ASSOC);
                
                $additional_data = [];
                if ($section_info) {
                    $additional_data = [
                        'course' => $section_info['program_code'],
                        'year_level' => $section_info['year_level'],
                        'semester' => $section_info['semester'],
                        'academic_year' => $section_info['academic_year']
                    ];
                }
                
                // Ensure data sync after status update
                ensureStudentDataSync($conn, $user_id, $additional_data);
            } else if ($enrollment_status == 'pending') {
                // If status changed to pending, remove from enrolled_students table
                $remove_sql = "DELETE FROM enrolled_students WHERE user_id = :user_id";
                $remove_stmt = $conn->prepare($remove_sql);
                $remove_stmt->bindParam(':user_id', $user_id);
                $remove_stmt->execute();
            }
            
            $_SESSION['message'] = 'Enrollment status updated successfully';
            
            // Redirect to appropriate section based on new status
            if ($enrollment_status == 'enrolled') {
                header('Location: dashboard.php#enrolled-students');
                exit;
            } else {
                header('Location: dashboard.php#pending-students');
                exit;
            }
        } else {
            $_SESSION['message'] = 'Failed to update enrollment status';
        }
        
    } catch(PDOException $e) {
        $_SESSION['message'] = 'Database error: ' . $e->getMessage();
    }
}

redirect('admin/dashboard.php');
?>

