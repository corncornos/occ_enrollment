<?php
declare(strict_types=1);

require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/Chatbot.php';

// Check if admin is logged in
if (!isLoggedIn() || !isAdmin()) {
    redirect('../public/login.php');
}

$chatbot = new Chatbot();

// Check if FAQ already exists
$existing_faqs = $chatbot->getAllFAQs();
$enrollment_guide_exists = false;
foreach ($existing_faqs as $faq) {
    if (stripos($faq['question'], 'enrollment process guide') !== false || 
        stripos($faq['question'], 'complete enrollment') !== false) {
        $enrollment_guide_exists = true;
        break;
    }
}

if (!$enrollment_guide_exists) {
    $faq_data = [
        'question' => 'What is the complete enrollment process guide?',
        'answer' => '<h5><strong>Complete Enrollment Process Guide</strong></h5>

<h6><strong>Phase 1: Initial Registration and Application</strong></h6>
<ol>
    <li><strong>Create Account:</strong> Register on the enrollment system with your personal information (name, email, password, contact details)</li>
    <li><strong>Complete Application:</strong> Fill out the comprehensive application form including:
        <ul>
            <li>Personal information (LRN, OCC Examinee Number, date of birth, etc.)</li>
            <li>Address and contact information</li>
            <li>Family information</li>
            <li>Educational background (school last attended, SHS track/strand)</li>
            <li>Program preference</li>
        </ul>
    </li>
    <li><strong>Upload Required Documents:</strong> Submit scanned copies of:
        <ul>
            <li>2x2 ID Pictures (4 pcs)</li>
            <li>PSA Birth Certificate</li>
            <li>Barangay Certificate of Residency</li>
            <li>Voter\'s ID or Registration Stub</li>
            <li>High School Diploma</li>
            <li>SF10 (Senior High School Permanent Record)</li>
            <li>Form 138 (Report Card)</li>
            <li>Certificate of Good Moral Character</li>
        </ul>
    </li>
    <li><strong>Wait for Review:</strong> The Admission Office will review your application and documents</li>
    <li><strong>Get Student ID:</strong> Once approved, you will be assigned a student ID number</li>
    <li><strong>Initial Enrollment:</strong> Admin will enroll you and assign you to sections for your first semester</li>
</ol>

<h6><strong>Phase 2: First Semester Enrollment</strong></h6>
<ol>
    <li>After approval, you will be automatically enrolled in your first semester</li>
    <li>Admin assigns you to appropriate sections based on your program</li>
    <li>View your schedule in the "My Schedule & Sections" section</li>
    <li>Access your Certificate of Registration (COR) once generated</li>
</ol>

<h6><strong>Phase 3: Next Semester Enrollment (For Continuing Students)</strong></h6>
<p>The enrollment process differs based on whether you are a <strong>Regular</strong> or <strong>Irregular</strong> student:</p>

<h6><strong>Regular Student Flow:</strong></h6>
<ol>
    <li><strong>Submit Enrollment Request:</strong> Go to "Enroll for Next Semester" and select your subjects</li>
    <li><strong>System Validation:</strong> The system checks prerequisites and blocks subjects you cannot take</li>
    <li><strong>Registrar Review:</strong> Your enrollment goes to the Registrar for review</li>
    <li><strong>COR Generation:</strong> Registrar generates your Certificate of Registration</li>
    <li><strong>Admin Approval:</strong> Admin gives final approval</li>
    <li><strong>Enrollment Confirmed:</strong> Your enrollment is confirmed and you can view your schedule</li>
</ol>

<h6><strong>Irregular Student Flow:</strong></h6>
<ol>
    <li><strong>Submit Enrollment Request:</strong> Go to "Enroll for Next Semester" and select your subjects</li>
    <li><strong>System Validation:</strong> The system checks prerequisites and identifies you as irregular if you have D/W/F grades</li>
    <li><strong>Program Head Review:</strong> Your enrollment goes to your Program Head for initial review</li>
    <li><strong>Program Head Approval:</strong> Program Head reviews and approves your subject selection</li>
    <li><strong>Registrar Review:</strong> Approved enrollment goes to Registrar</li>
    <li><strong>COR Generation:</strong> Registrar generates your Certificate of Registration</li>
    <li><strong>Admin Approval:</strong> Admin gives final approval</li>
    <li><strong>Enrollment Confirmed:</strong> Your enrollment is confirmed and you can view your schedule</li>
</ol>

<h6><strong>Important Notes:</strong></h6>
<ul>
    <li>Check enrollment period announcements - enrollment may be closed during certain times</li>
    <li>Regular students follow the standard curriculum progression</li>
    <li>Irregular students (those with D/W/F grades) require Program Head approval</li>
    <li>Prerequisites are automatically checked - you cannot enroll in subjects without completing prerequisites</li>
    <li>Monitor your enrollment status in the dashboard</li>
    <li>You can view your COR once it\'s generated by the Registrar</li>
    <li>Contact the Admission Office or Registrar if you have questions about your enrollment status</li>
</ul>

<h6><strong>Where to Check Your Status:</strong></h6>
<ul>
    <li><strong>Dashboard:</strong> View your current enrollment status</li>
    <li><strong>My Schedule:</strong> View your assigned sections and class schedule</li>
    <li><strong>Enrollment Status:</strong> Check the status of your enrollment requests</li>
    <li><strong>My COR:</strong> View and print your Certificate of Registration</li>
</ul>',
        'keywords' => 'enrollment guide,enrollment process,how to enroll,enrollment steps,enrollment flow,registration process,application process,enrollment workflow,regular student,irregular student,enrollment status,enrollment phases',
        'category' => 'Enrollment',
        'is_active' => 1,
        'created_by' => $_SESSION['admin_id'] ?? null
    ];
    
    $result = $chatbot->addFAQ($faq_data);
    
    if ($result['success']) {
        $_SESSION['message'] = 'Enrollment guide FAQ added successfully to the chatbot!';
    } else {
        $_SESSION['message'] = 'Error adding enrollment guide FAQ: ' . $result['message'];
    }
} else {
    $_SESSION['message'] = 'Enrollment guide FAQ already exists in the chatbot.';
}

redirect('admin/dashboard.php');
?>

