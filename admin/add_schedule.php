<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/Section.php';

// Check if user is logged in and is an admin
if (!isLoggedIn() || !isAdmin()) {
    redirect('public/login.php');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $data = [
        'section_id' => (int)$_POST['section_id'],
        'curriculum_id' => (int)$_POST['curriculum_id'],
        'course_code' => sanitizeInput($_POST['course_code']),
        'course_name' => sanitizeInput($_POST['course_name']),
        'units' => (int)$_POST['units'],
        'schedule_monday' => isset($_POST['schedule_monday']) ? 1 : 0,
        'schedule_tuesday' => isset($_POST['schedule_tuesday']) ? 1 : 0,
        'schedule_wednesday' => isset($_POST['schedule_wednesday']) ? 1 : 0,
        'schedule_thursday' => isset($_POST['schedule_thursday']) ? 1 : 0,
        'schedule_friday' => isset($_POST['schedule_friday']) ? 1 : 0,
        'schedule_saturday' => isset($_POST['schedule_saturday']) ? 1 : 0,
        'schedule_sunday' => isset($_POST['schedule_sunday']) ? 1 : 0,
        'time_start' => $_POST['time_start'],
        'time_end' => $_POST['time_end'],
        'room' => sanitizeInput($_POST['room']),
        'professor_name' => sanitizeInput($_POST['professor_name'] ?? ''),
        'professor_initial' => sanitizeInput($_POST['professor_initial'])
    ];
    
    $section = new Section();
    
    if ($section->addSchedule($data)) {
        $_SESSION['message'] = 'Course added to schedule successfully';
    } else {
        $_SESSION['message'] = 'Error adding course to schedule. Course may already be in schedule.';
    }
}

redirect('admin/dashboard.php#sections');
?>

