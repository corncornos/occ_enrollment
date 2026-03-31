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
$enrollment_date = $_POST['enrollment_date'] ?? null;

if (!$user_id || !$enrollment_date) {
	$_SESSION['message'] = 'Missing required fields.';
	redirect('admin/dashboard.php');
}

try {
	$conn = (new Database())->getConnection();

	// Does an enrolled_students row exist?
	// Sync user data to enrolled_students table first
    require_once 'sync_user_to_enrolled_students.php';
    syncUserToEnrolledStudents($conn, $user_id);
    
    // Update enrollment_date
    $upd = $conn->prepare("UPDATE enrolled_students SET enrollment_date = :d WHERE user_id = :user_id");
    $upd->bindParam(':d', $enrollment_date);
    $upd->bindParam(':user_id', $user_id);
    $upd->execute();

	$_SESSION['message'] = 'Enrollment date saved.';
} catch (Exception $e) {
	$_SESSION['message'] = 'Error: ' . $e->getMessage();
}

redirect('admin/dashboard.php');
