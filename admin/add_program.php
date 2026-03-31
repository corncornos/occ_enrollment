<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/Curriculum.php';

// Check if user is logged in and is an admin
if (!isLoggedIn() || !isAdmin()) {
    redirect('public/login.php');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $data = [
        'program_code' => strtoupper(sanitizeInput($_POST['program_code'])),
        'program_name' => sanitizeInput($_POST['program_name']),
        'description' => sanitizeInput($_POST['description'] ?? ''),
        'total_units' => 0, // Will be calculated automatically from curriculum submissions
        'years_to_complete' => (int)($_POST['years_to_complete'] ?? 4),
        'status' => $_POST['status'] ?? 'active'
    ];
    
    $curriculum = new Curriculum();
    
    $result = $curriculum->addProgram($data);
    
    if (is_array($result) && isset($result['success'])) {
        if ($result['success']) {
            $_SESSION['message'] = $result['message'] ?? 'Program added successfully';
        } else {
            $_SESSION['message'] = $result['message'] ?? 'Error adding program. Program code may already exist.';
        }
    } else {
        // Legacy return format (boolean) - for backward compatibility
        if ($result) {
            $_SESSION['message'] = 'Program added successfully';
        } else {
            $_SESSION['message'] = 'Error adding program. Program code may already exist.';
        }
    }
}

redirect('admin/dashboard.php#curriculum');
?>

