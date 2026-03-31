<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('public/login.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('admin/dashboard.php');
}

$user_id = $_POST['user_id'] ?? null;

if (!$user_id) {
    $_SESSION['message'] = 'User ID required.';
    redirect('admin/dashboard.php');
}

try {
    $conn = (new Database())->getConnection();
    
    // Verify student has active section assignments
    $section_check = "SELECT COUNT(*) as section_count FROM section_enrollments WHERE user_id = :user_id AND status = 'active'";
    $section_stmt = $conn->prepare($section_check);
    $section_stmt->bindParam(':user_id', $user_id);
    $section_stmt->execute();
    $section_result = $section_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($section_result['section_count'] == 0) {
        $_SESSION['message'] = 'Student must have active section assignments before enrollment.';
        redirect('admin/dashboard.php');
    }
    
    // Update user enrollment status
    $update_user = "UPDATE users SET enrollment_status = 'enrolled' WHERE id = :user_id";
    $user_stmt = $conn->prepare($update_user);
    $user_stmt->bindParam(':user_id', $user_id);
    $user_stmt->execute();
    
    // Sync user data to enrolled_students table
    require_once 'sync_user_to_enrolled_students.php';
    
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
    
    syncUserToEnrolledStudents($conn, $user_id, $additional_data);
    
    $_SESSION['message'] = 'Student successfully enrolled after verification!';
    
} catch (Exception $e) {
    $_SESSION['message'] = 'Error enrolling student: ' . $e->getMessage();
}

redirect('admin/dashboard.php');
?>
