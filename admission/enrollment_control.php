<?php
require_once '../config/database.php';
require_once '../classes/Admission.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !isAdmission()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    // Get current settings
    $admission = new Admission();
    $settings = $admission->getEnrollmentControl();
    echo json_encode(['success' => true, 'settings' => $settings]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update settings
    $academic_year = $_POST['academic_year'] ?? '';
    $semester = $_POST['semester'] ?? '';
    $status = $_POST['enrollment_status'] ?? 'closed';
    $opening_date = $_POST['opening_date'] ?? null;
    $closing_date = $_POST['closing_date'] ?? null;
    $announcement = $_POST['announcement'] ?? '';
    
    if (empty($academic_year) || empty($semester)) {
        echo json_encode(['success' => false, 'message' => 'Academic year and semester are required']);
        exit();
    }
    
    $admission = new Admission();
    if ($admission->updateEnrollmentControl($academic_year, $semester, $status, $opening_date, $closing_date, $announcement)) {
        echo json_encode(['success' => true, 'message' => 'Enrollment control updated successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update enrollment control']);
    }
    exit();
}

echo json_encode(['success' => false, 'message' => 'Invalid request method']);
?>







