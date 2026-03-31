<?php
declare(strict_types=1);

require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/User.php';

// Check if user is logged in and is a student
if (!isLoggedIn() || isAdmin()) {
    redirect('../public/login.php');
}

// Only allow students to update their own profile
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('dashboard.php');
}

$user = new User();
$user_id = (int)$_SESSION['user_id'];

// Prepare data array with sanitized inputs
$data = [];

// Basic Information
if (isset($_POST['lrn'])) {
    $data['lrn'] = sanitizeInput($_POST['lrn']);
}
if (isset($_POST['occ_examinee_number'])) {
    $data['occ_examinee_number'] = sanitizeInput($_POST['occ_examinee_number']);
}
if (isset($_POST['first_name'])) {
    $data['first_name'] = sanitizeInput($_POST['first_name']);
}
if (isset($_POST['last_name'])) {
    $data['last_name'] = sanitizeInput($_POST['last_name']);
}
if (isset($_POST['middle_name'])) {
    $data['middle_name'] = sanitizeInput($_POST['middle_name']);
}
if (isset($_POST['email'])) {
    $data['email'] = sanitizeInput($_POST['email']);
}
if (isset($_POST['phone'])) {
    $data['phone'] = sanitizeInput($_POST['phone']);
}
if (isset($_POST['contact_number'])) {
    $data['contact_number'] = sanitizeInput($_POST['contact_number']);
}
if (isset($_POST['date_of_birth'])) {
    $data['date_of_birth'] = $_POST['date_of_birth'] ?: null;
}
if (isset($_POST['age'])) {
    $data['age'] = (int)$_POST['age'];
}
if (isset($_POST['sex_at_birth'])) {
    $data['sex_at_birth'] = sanitizeInput($_POST['sex_at_birth']);
}
if (isset($_POST['civil_status'])) {
    $data['civil_status'] = sanitizeInput($_POST['civil_status']);
}
if (isset($_POST['spouse_name'])) {
    $data['spouse_name'] = sanitizeInput($_POST['spouse_name']);
}

// Address Information
if (isset($_POST['address'])) {
    $data['address'] = sanitizeInput($_POST['address']);
}
if (isset($_POST['permanent_address'])) {
    $data['permanent_address'] = sanitizeInput($_POST['permanent_address']);
}
if (isset($_POST['municipality_city'])) {
    $data['municipality_city'] = sanitizeInput($_POST['municipality_city']);
}
if (isset($_POST['barangay'])) {
    $data['barangay'] = sanitizeInput($_POST['barangay']);
}

// Family Information
if (isset($_POST['father_name'])) {
    $data['father_name'] = sanitizeInput($_POST['father_name']);
}
if (isset($_POST['father_occupation'])) {
    $data['father_occupation'] = sanitizeInput($_POST['father_occupation']);
}
if (isset($_POST['father_education'])) {
    $data['father_education'] = sanitizeInput($_POST['father_education']);
}
if (isset($_POST['mother_maiden_name'])) {
    $data['mother_maiden_name'] = sanitizeInput($_POST['mother_maiden_name']);
}
if (isset($_POST['mother_occupation'])) {
    $data['mother_occupation'] = sanitizeInput($_POST['mother_occupation']);
}
if (isset($_POST['mother_education'])) {
    $data['mother_education'] = sanitizeInput($_POST['mother_education']);
}
if (isset($_POST['number_of_brothers'])) {
    $data['number_of_brothers'] = (int)$_POST['number_of_brothers'];
}
if (isset($_POST['number_of_sisters'])) {
    $data['number_of_sisters'] = (int)$_POST['number_of_sisters'];
}
if (isset($_POST['combined_family_income'])) {
    $data['combined_family_income'] = sanitizeInput($_POST['combined_family_income']);
}
if (isset($_POST['guardian_name'])) {
    $data['guardian_name'] = sanitizeInput($_POST['guardian_name']);
}

// Educational Background
if (isset($_POST['school_last_attended'])) {
    $data['school_last_attended'] = sanitizeInput($_POST['school_last_attended']);
}
if (isset($_POST['school_address'])) {
    $data['school_address'] = sanitizeInput($_POST['school_address']);
}

// PWD Information
if (isset($_POST['is_pwd'])) {
    $data['is_pwd'] = $_POST['is_pwd'] == '1' ? 1 : 0;
}
if (isset($_POST['hearing_disability'])) {
    $data['hearing_disability'] = $_POST['hearing_disability'] == '1' ? 1 : 0;
}
if (isset($_POST['physical_disability'])) {
    $data['physical_disability'] = $_POST['physical_disability'] == '1' ? 1 : 0;
}
if (isset($_POST['mental_disability'])) {
    $data['mental_disability'] = $_POST['mental_disability'] == '1' ? 1 : 0;
}
if (isset($_POST['intellectual_disability'])) {
    $data['intellectual_disability'] = $_POST['intellectual_disability'] == '1' ? 1 : 0;
}
if (isset($_POST['psychosocial_disability'])) {
    $data['psychosocial_disability'] = $_POST['psychosocial_disability'] == '1' ? 1 : 0;
}
if (isset($_POST['chronic_illness_disability'])) {
    $data['chronic_illness_disability'] = $_POST['chronic_illness_disability'] == '1' ? 1 : 0;
}
if (isset($_POST['learning_disability'])) {
    $data['learning_disability'] = $_POST['learning_disability'] == '1' ? 1 : 0;
}

// Senior High School Information
if (isset($_POST['shs_track'])) {
    $data['shs_track'] = sanitizeInput($_POST['shs_track']);
}
if (isset($_POST['shs_strand'])) {
    $data['shs_strand'] = sanitizeInput($_POST['shs_strand']);
}

// Working Student Information
if (isset($_POST['is_working_student'])) {
    $data['is_working_student'] = $_POST['is_working_student'] == '1' ? 1 : 0;
}
if (isset($_POST['employer'])) {
    $data['employer'] = sanitizeInput($_POST['employer']);
}
if (isset($_POST['work_position'])) {
    $data['work_position'] = sanitizeInput($_POST['work_position']);
}
if (isset($_POST['working_hours'])) {
    $data['working_hours'] = sanitizeInput($_POST['working_hours']);
}

// Program Preference
if (isset($_POST['preferred_program'])) {
    $data['preferred_program'] = sanitizeInput($_POST['preferred_program']);
}

// Password update (optional)
if (isset($_POST['password']) && !empty($_POST['password'])) {
    if (isset($_POST['confirm_password']) && $_POST['password'] === $_POST['confirm_password']) {
        $data['password'] = $_POST['password'];
    } else {
        $_SESSION['message'] = 'Password and confirm password do not match';
        redirect('dashboard.php');
    }
}

// Validate required fields
if (isset($data['first_name']) && empty($data['first_name'])) {
    $_SESSION['message'] = 'First name is required';
    redirect('dashboard.php');
}

if (isset($data['last_name']) && empty($data['last_name'])) {
    $_SESSION['message'] = 'Last name is required';
    redirect('dashboard.php');
}

if (isset($data['email']) && empty($data['email'])) {
    $_SESSION['message'] = 'Email is required';
    redirect('dashboard.php');
}

// Update profile
$result = $user->updateProfile($user_id, $data);

if ($result['success']) {
    $_SESSION['message'] = $result['message'];
} else {
    $_SESSION['message'] = $result['message'];
}

redirect('dashboard.php');
?>

