<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/Admin.php';
require_once '../classes/User.php';
require_once '../classes/Curriculum.php';
require_once '../classes/Section.php';
require_once '../classes/Chatbot.php';

// Check if user is logged in and is an admin
if (!isLoggedIn() || !isAdmin()) {
    redirect('public/login.php');
}

// Redirect dean users to dean dashboard
if (isDean()) {
    redirect('dean/dashboard.php');
}

$admin = new Admin();
$user = new User();
$curriculum = new Curriculum();
$section = new Section();
$chatbot = new Chatbot();

// Validate session against admins table to prevent session confusion
$current_admin_info = $admin->getAdminById($_SESSION['user_id']);
if (!$current_admin_info) {
    // Admin not found in database, logout
    session_destroy();
    redirect('public/login.php');
}

// Update session role if it somehow got corrupted
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['role'] = 'admin';
}

// Update session to ensure admin flag is set
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    $_SESSION['is_admin'] = true;
}

// Initialize database connection first
$db = new Database();
$conn = $db->getConnection();

$all_users = $user->getAllUsers();
$registrar_queue_query = "SELECT user_id FROM application_workflow WHERE passed_to_registrar = 1";
$registrar_queue_stmt = $conn->prepare($registrar_queue_query);
$registrar_queue_stmt->execute();
$registrar_queue_user_ids = $registrar_queue_stmt->fetchAll(PDO::FETCH_COLUMN);
$registrar_queue_lookup = [];
if ($registrar_queue_user_ids) {
    foreach ($registrar_queue_user_ids as $registrar_user_id) {
        $registrar_queue_lookup[(int)$registrar_user_id] = true;
    }
}
$all_programs = $curriculum->getAllPrograms();
$all_sections = $section->getAllSections();
$program_lookup_by_name = [];
$program_lookup_by_code = [];
foreach ($all_programs as $program_item) {
    $program_lookup_by_name[strtolower(trim($program_item['program_name']))] = $program_item['id'];
    $program_lookup_by_code[strtolower(trim($program_item['program_code']))] = $program_item['id'];
}

// Get curriculum submissions for review with enhanced information (including dean direct uploads)
$curriculum_submissions_query = "SELECT cs.*, 
                                        ph.first_name, ph.last_name, ph.email as program_head_email,
                                        p.program_name, p.program_code,
                                        COUNT(DISTINCT csi.id) as total_subjects,
                                        a.first_name as reviewer_first_name, a.last_name as reviewer_last_name,
                                        a.admin_id as reviewer_id,
                                        a.is_dean as reviewer_is_dean,
                                        dean_admin.first_name as dean_first_name,
                                        dean_admin.last_name as dean_last_name,
                                        dean_admin.admin_id as dean_id,
                                        (SELECT COUNT(*) FROM curriculum_submission_logs csl WHERE csl.submission_id = cs.id) as log_count,
                                        (SELECT MAX(created_at) FROM curriculum_submission_logs csl WHERE csl.submission_id = cs.id) as last_action_date,
                                        (SELECT role FROM curriculum_submission_logs csl WHERE csl.submission_id = cs.id AND csl.action = 'dean_direct_upload' LIMIT 1) as is_dean_upload,
                                        (SELECT performed_by FROM curriculum_submission_logs csl WHERE csl.submission_id = cs.id AND csl.action = 'dean_direct_upload' LIMIT 1) as dean_uploader_id
                                 FROM curriculum_submissions cs
                                 LEFT JOIN program_heads ph ON cs.program_head_id = ph.id
                                 JOIN programs p ON cs.program_id = p.id
                                 LEFT JOIN curriculum_submission_items csi ON cs.id = csi.submission_id
                                 LEFT JOIN admins a ON cs.reviewed_by = a.id
                                 LEFT JOIN curriculum_submission_logs csl_dean ON csl_dean.submission_id = cs.id AND csl_dean.action = 'dean_direct_upload'
                                 LEFT JOIN admins dean_admin ON csl_dean.performed_by = dean_admin.id
                                 GROUP BY cs.id
                                 ORDER BY cs.created_at DESC";
$curriculum_submissions_stmt = $conn->prepare($curriculum_submissions_query);
$curriculum_submissions_stmt->execute();
$curriculum_submissions = $curriculum_submissions_stmt->fetchAll(PDO::FETCH_ASSOC);


// Get enrolled students with their details and program information from assigned sections
$enrolled_query = "SELECT es.*, u.first_name, u.last_name, u.email, u.phone, u.status, u.created_at,
                   GROUP_CONCAT(DISTINCT p.program_code ORDER BY p.program_code SEPARATOR ', ') as program_codes,
                   GROUP_CONCAT(DISTINCT p.program_name ORDER BY p.program_code SEPARATOR ', ') as program_names,
                   GROUP_CONCAT(DISTINCT s.year_level ORDER BY s.year_level SEPARATOR ', ') as section_year_levels,
                   GROUP_CONCAT(DISTINCT s.semester ORDER BY s.semester SEPARATOR ', ') as section_semesters,
                   GROUP_CONCAT(DISTINCT s.academic_year ORDER BY s.academic_year SEPARATOR ', ') as section_academic_years,
                   GROUP_CONCAT(DISTINCT CONCAT(s.section_name, ' (', s.year_level, ' - ', s.semester, ')') ORDER BY s.section_name SEPARATOR ', ') as assigned_sections
                   FROM enrolled_students es
                   JOIN users u ON es.user_id = u.id
                   LEFT JOIN section_enrollments se ON u.id = se.user_id AND se.status = 'active'
                   LEFT JOIN sections s ON se.section_id = s.id
                   LEFT JOIN programs p ON s.program_id = p.id
                   GROUP BY es.user_id, es.id, u.first_name, u.last_name, u.email, u.phone, u.status, u.created_at,
                            es.course, es.year_level, es.student_type, es.academic_year, es.semester, es.enrolled_date
                   ORDER BY es.enrolled_date DESC";
$enrolled_stmt = $conn->prepare($enrolled_query);
$enrolled_stmt->execute();
$all_enrolled_students = $enrolled_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get section assignments for all students
$section_assignments_query = "SELECT se.user_id, se.section_id, se.enrolled_date,
                               s.section_name, s.year_level, s.semester, s.academic_year,
                               p.program_code, p.program_name
                               FROM section_enrollments se
                               JOIN sections s ON se.section_id = s.id
                               JOIN programs p ON s.program_id = p.id
                               WHERE se.status = 'active'";
$section_assignments_stmt = $conn->prepare($section_assignments_query);
$section_assignments_stmt->execute();
$section_assignments_raw = $section_assignments_stmt->fetchAll(PDO::FETCH_ASSOC);

// Group section assignments by user_id
$section_assignments = [];
foreach ($section_assignments_raw as $assignment) {
    $user_id = $assignment['user_id'];
    if (!isset($section_assignments[$user_id])) {
        $section_assignments[$user_id] = [];
    }
    $section_assignments[$user_id][] = $assignment;
}

$message = '';
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

// Count statistics
$total_students = count(array_filter($all_users, function($u) { return $u['role'] == 'student'; }));
$active_students = count(array_filter($all_users, function($u) { return $u['role'] == 'student' && $u['status'] == 'active'; }));
// Get user IDs who have next semester enrollment requests (to exclude from pending students)
$next_sem_user_ids_query = "SELECT DISTINCT user_id FROM next_semester_enrollments 
                            WHERE request_status IN ('draft', 'pending_program_head', 'pending_registrar', 'pending_admin')";
$next_sem_user_ids_stmt = $conn->prepare($next_sem_user_ids_query);
$next_sem_user_ids_stmt->execute();
$next_sem_user_ids = $next_sem_user_ids_stmt->fetchAll(PDO::FETCH_COLUMN);
$next_sem_user_ids_lookup = [];
if ($next_sem_user_ids) {
    foreach ($next_sem_user_ids as $user_id) {
        $next_sem_user_ids_lookup[(int)$user_id] = true;
    }
}

$pending_students = count(array_filter($all_users, function($u) use ($registrar_queue_lookup, $next_sem_user_ids_lookup) {
    return $u['role'] == 'student'
        && ($u['enrollment_status'] ?? 'pending') == 'pending'
        && isset($registrar_queue_lookup[$u['id'] ?? 0])
        && !isset($next_sem_user_ids_lookup[$u['id'] ?? 0]); // Exclude students with next semester enrollments
}));
$enrolled_students = count($all_enrolled_students);

// Get total courses from curriculum
$curriculum_courses_query = "SELECT COUNT(DISTINCT course_code) as total FROM curriculum";
$curriculum_courses_stmt = $conn->prepare($curriculum_courses_query);
$curriculum_courses_stmt->execute();
$total_courses = $curriculum_courses_stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Course enrollments = enrolled students count
$total_enrollments = $enrolled_students;

// Get latest enrollments from enrolled_students
$latest_enrollments_query = "SELECT es.*, u.first_name, u.last_name, u.student_id, u.email
                             FROM enrolled_students es
                             JOIN users u ON es.user_id = u.id
                             ORDER BY es.enrolled_date DESC
                             LIMIT 5";
$latest_enrollments_stmt = $conn->prepare($latest_enrollments_query);
$latest_enrollments_stmt->execute();
$latest_enrollments = $latest_enrollments_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .sidebar {
            background: #1e293b;
            min-height: 100vh;
            color: #e2e8f0;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            overflow-y: auto;
            overflow-x: hidden;
            z-index: 1000;
            width: 260px;
            transition: all 0.3s ease;
            box-shadow: 2px 0 10px rgba(0, 0, 0, 0.1);
        }
        @media (min-width: 992px) {
            .sidebar {
                width: 260px;
            }
        }
        @media (max-width: 767px) {
            .sidebar {
                transform: translateX(-100%);
                width: 260px;
            }
            .sidebar.active {
                transform: translateX(0);
            }
        }
        /* Top Navigation Bar */
        .top-nav {
            position: fixed;
            top: 0;
            left: 260px;
            right: 0;
            height: 60px;
            background: #1e293b;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            z-index: 999;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 25px;
            transition: all 0.3s ease;
        }
        @media (max-width: 767px) {
            .top-nav {
                left: 0;
                padding: 0 15px;
            }
        }
        .top-nav-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .top-nav-left h4 {
            margin: 0;
            color: white;
            font-weight: 600;
            font-size: 18px;
        }
        .top-nav-right {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .top-nav-user {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            text-align: right;
        }
        .top-nav-user h6 {
            color: #94a3b8;
            font-size: 10px;
            margin-bottom: 2px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .top-nav-user h5 {
            color: white;
            font-size: 13px;
            margin-bottom: 2px;
            font-weight: 600;
        }
        .top-nav-user small {
            color: #94a3b8;
            font-size: 11px;
        }
        .main-content {
            margin-left: 260px;
            margin-top: 60px;
            transition: all 0.3s ease;
        }
        @media (min-width: 992px) {
            .main-content {
                margin-left: 260px;
                margin-top: 60px;
            }
        }
        @media (max-width: 767px) {
            .main-content {
                margin-left: 0;
                margin-top: 60px;
            }
        }
        .sidebar-logo {
            width: 50px;
            height: 50px;
            object-fit: contain;
            background: white;
            border-radius: 12px;
            padding: 0.5rem;
            margin-bottom: 0;
        }
        .sidebar-header {
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .sidebar-header h4 {
            margin: 0;
            color: white;
            font-size: 18px;
            font-weight: 600;
        }
        .sidebar-menu {
            padding: 18px 0;
        }
        .nav-link {
            color: #cbd5e1;
            padding: 10px 18px;
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
            position: relative;
            font-size: 14px;
        }
        .nav-link i {
            width: 20px;
            font-size: 16px;
        }
        .nav-link:hover {
            background: rgba(255, 255, 255, 0.05);
            color: white;
            border-left-color: #3b82f6;
        }
        .nav-link.active {
            background: rgba(59, 130, 246, 0.1);
            color: #3b82f6;
            border-left-color: #3b82f6;
            font-weight: 500;
        }
        .nav-item.has-dropdown > .nav-link {
            position: relative;
        }
        .nav-item.has-dropdown > .nav-link::after {
            content: '\f107';
            font-family: 'Font Awesome 6 Free';
            font-weight: 900;
            position: absolute;
            right: 20px;
            transition: transform 0.3s ease;
        }
        .nav-item.has-dropdown.active > .nav-link::after {
            transform: rotate(180deg);
        }
        .submenu {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            background: rgba(0, 0, 0, 0.2);
        }
        .nav-item.has-dropdown.active > .submenu {
            max-height: 500px;
        }
        .submenu .nav-link {
            padding-left: 45px;
            font-size: 13px;
            padding-top: 7px;
            padding-bottom: 7px;
        }
        .submenu .nav-link:hover {
            background: rgba(255, 255, 255, 0.08);
        }
        .sidebar::-webkit-scrollbar {
            width: 6px;
        }
        .sidebar::-webkit-scrollbar-track {
            background: rgba(255, 255, 255, 0.05);
        }
        .sidebar::-webkit-scrollbar-thumb {
            background: rgba(255, 255, 255, 0.2);
            border-radius: 3px;
        }
        .sidebar::-webkit-scrollbar-thumb:hover {
            background: rgba(255, 255, 255, 0.3);
        }
        .menu-toggle {
            display: none;
            position: fixed;
            top: 20px;
            left: 20px;
            z-index: 1001;
            background: #1e293b;
            color: white;
            border: none;
            padding: 10px 15px;
            border-radius: 8px;
            cursor: pointer;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.2);
        }
        @media (max-width: 767px) {
            .menu-toggle {
                display: block;
            }
        }
        .sidebar-user {
            padding: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            margin-top: auto;
        }
        .sidebar-user h6 {
            color: #94a3b8;
            font-size: 12px;
            margin-bottom: 5px;
        }
        .sidebar-user h5 {
            color: white;
            font-size: 14px;
            margin-bottom: 3px;
        }
        .sidebar-user small {
            color: #94a3b8;
            font-size: 11px;
        }
        .card {
            border: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border-radius: 15px;
        }
        .card-body {
            padding: 15px !important;
        }
        .card-header {
            background: #1e40af;
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 12px 15px !important;
        }
        .card-header h5 {
            font-size: 14px !important;
            margin: 0 !important;
        }
        .stat-card {
            background: #1e40af;
            color: white;
            border-radius: 15px;
        }
        .stat-card .card-body {
            padding: 15px !important;
        }
        .stat-card i {
            font-size: 2rem !important;
        }
        .stat-card h3 {
            font-size: 24px !important;
            margin: 10px 0 !important;
        }
        .stat-card p {
            font-size: 13px !important;
            margin: 0 !important;
        }
        .content-section {
            padding: 20px !important;
        }
        .content-section h2 {
            font-size: 1.5rem !important;
            margin-bottom: 15px !important;
        }
        .content-section h3 {
            font-size: 1.25rem !important;
            margin-bottom: 12px !important;
        }
        .row {
            margin-bottom: 15px !important;
        }
        .mb-4 {
            margin-bottom: 15px !important;
        }
        .btn-action {
            border-radius: 20px;
            padding: 5px 15px;
            margin: 2px;
        }
        
        /* Filter Section Styles */
        .filter-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .filter-card .card-header {
            background: #1e40af;
            color: white;
            border-radius: 8px 8px 0 0;
            transition: background-color 0.3s ease;
        }
        
        .filter-card .card-header:hover {
            background: #1e3a8a;
        }
        
        #filter-toggle-icon {
            transition: transform 0.3s ease;
        }
        
        /* Schedule Edit Row Styles */
        .schedule-edit-row {
            background-color: #f8f9fa;
        }
        
        .schedule-edit-container {
            animation: slideDown 0.3s ease-out;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .schedule-edit-row[style*="display: none"] {
            display: none !important;
        }
        
        .form-label {
            font-weight: 600;
            color: #495057;
            font-size: 0.9rem;
        }
        
        .form-select:focus, .form-control:focus {
            border-color: #3b82f6;
            box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.25);
        }
        /* Override Bootstrap primary colors */
        .btn-primary, .bg-primary {
            background-color: #1e40af !important;
            border-color: #1e40af !important;
        }
        .btn-primary:hover {
            background-color: #1e3a8a !important;
            border-color: #1e3a8a !important;
        }
        .text-primary {
            color: #1e40af !important;
        }
        .badge.bg-primary {
            background-color: #1e40af !important;
        }
        .bg-info, .btn-info {
            background-color: #1e40af !important;
            border-color: #1e40af !important;
        }
        .btn-info:hover {
            background-color: #1e3a8a !important;
            border-color: #1e3a8a !important;
        }
        .text-info {
            color: #1e40af !important;
        }
        .badge.bg-info {
            background-color: #1e40af !important;
        }
        
        .input-group-text {
            background-color: #f8f9fa;
            border-color: #ced4da;
        }
        
        /* Sortable Column Headers */
        th[data-sort] {
            position: relative;
            user-select: none;
        }
        
        th[data-sort]:hover {
            background-color: rgba(255,255,255,0.1);
        }
        
        th[data-sort] i {
            opacity: 0.5;
            margin-left: 5px;
        }
        
        th[data-sort]:hover i {
            opacity: 0.8;
        }
        
        /* No Results Styling */
        #no-results {
            background-color: #f8f9fa;
            border-radius: 8px;
            border: 2px dashed #dee2e6;
        }
        
        /* Responsive Filter Layout */
        @media (max-width: 768px) {
            .filter-card .row .col-md-2 {
                margin-bottom: 1rem;
            }
        }
        
        /* Filter Summary */
        .filter-summary {
            background-color: #e9ecef;
            border-radius: 4px;
            padding: 8px 12px;
            font-size: 0.875rem;
        }
        
        /* Clickable Row Styles */
        .clickable-row:hover {
            background-color: #f8f9fa !important;
        }
        
        .clickable-row .expand-icon {
            transition: transform 0.3s ease;
        }
        
        .action-row {
            border-top: 2px solid #dee2e6;
        }
        
        .action-row td {
            padding: 0 !important;
        }
    </style>
</head>
<body>
    <button class="menu-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="sidebar" id="sidebar">
                <div class="sidebar-header">
                    <img src="../public/assets/images/occ_logo.png"
                         alt="One Cainta College Logo"
                         class="sidebar-logo"
                         onerror="this.style.display='none'; document.getElementById('adminSidebarFallback').classList.remove('d-none');">
                    <i id="adminSidebarFallback" class="fas fa-cog d-none" style="font-size: 24px; color: white;"></i>
                    <h4>Admin Panel</h4>
                </div>
                
                <div class="sidebar-menu">
                    <nav class="nav flex-column">
                        <a href="<?php echo add_session_to_url('dashboard.php'); ?>#dashboard" class="nav-link active" data-section="dashboard">
                            <i class="fas fa-home"></i>
                            <span>Dashboard</span>
                        </a>
                        
                        <div class="nav-item has-dropdown">
                            <a href="#" class="nav-link" onclick="toggleDropdown(this); return false;">
                                <i class="fas fa-users"></i>
                                <span>Students</span>
                            </a>
                            <div class="submenu">
                                <a href="<?php echo add_session_to_url('dashboard.php'); ?>#pending-students" class="nav-link" data-section="pending-students">
                                    <span>Pending Students</span>
                                </a>
                                <a href="<?php echo add_session_to_url('dashboard.php'); ?>#enrolled-students" class="nav-link" data-section="enrolled-students">
                                    <span>Enrolled Students</span>
                                </a>
                            </div>
                        </div>
                        
                        <a href="<?php echo add_session_to_url('dashboard.php'); ?>#sections" class="nav-link" data-section="sections">
                            <i class="fas fa-users-class"></i>
                            <span>Sections</span>
                        </a>
                        
                        <div class="nav-item has-dropdown">
                            <a href="#" class="nav-link" onclick="toggleDropdown(this); return false;">
                                <i class="fas fa-graduation-cap"></i>
                                <span>Curriculum</span>
                            </a>
                            <div class="submenu">
                                <a href="<?php echo add_session_to_url('dashboard.php'); ?>#curriculum" class="nav-link" data-section="curriculum">
                                    <span>Manage Curriculum</span>
                                </a>
                                <a href="<?php echo add_session_to_url('dashboard.php'); ?>#curriculum-submissions" class="nav-link" data-section="curriculum-submissions">
                                    <span>Submissions</span>
                                </a>
                            </div>
                        </div>
                        
                        <a href="<?php echo add_session_to_url('generate_cor.php'); ?>" class="nav-link">
                            <i class="fas fa-file-alt"></i>
                            <span>Generate COR</span>
                        </a>
                        <a href="student_grading.php" class="nav-link">
                            <i class="fas fa-clipboard-list"></i>
                            <span>Student Grading</span>
                        </a>
                        <a href="<?php echo add_session_to_url('dashboard.php'); ?>#next-semester" class="nav-link" data-section="next-semester">
                            <i class="fas fa-calendar-alt"></i>
                            <span>Next Semester</span>
                        </a>
                        <a href="<?php echo add_session_to_url('enrollment_control.php'); ?>" class="nav-link">
                            <i class="fas fa-cog"></i>
                            <span>Enrollment Control</span>
                        </a>
                        <a href="<?php echo add_session_to_url('adjustment_period_control.php'); ?>" class="nav-link">
                            <i class="fas fa-exchange-alt"></i>
                            <span>Adjustment Period</span>
                        </a>
                        <a href="<?php echo add_session_to_url('dashboard.php'); ?>#chatbot" class="nav-link" data-section="chatbot">
                            <i class="fas fa-robot"></i>
                            <span>Chatbot FAQs</span>
                        </a>
                        <a href="bulk_import.php" class="nav-link">
                            <i class="fas fa-file-import"></i>
                            <span>Bulk Import</span>
                        </a>
                        <a href="enrollment_reports.php" class="nav-link">
                            <i class="fas fa-chart-line"></i>
                            <span>Enrollment Reports</span>
                        </a>
                        <a href="audit_logs.php" class="nav-link">
                            <i class="fas fa-clipboard-list"></i>
                            <span>Audit Logs</span>
                        </a>
                        <a href="../student/logout.php" class="nav-link">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </nav>
                </div>
                
                <div class="sidebar-user">
                    <h6>Welcome,</h6>
                    <h5><?php echo $_SESSION['first_name'] . ' ' . $_SESSION['last_name']; ?></h5>
                    <small>ID: <?php echo $_SESSION['admin_id'] ?? 'N/A'; ?></small>
                </div>
            </div>
            
            <!-- Top Navigation Bar -->
            <nav class="top-nav">
                <div class="top-nav-left">
                    <h4>Dashboard</h4>
                </div>
                <div class="top-nav-right">
                    <div class="top-nav-user">
                        <h6>Welcome,</h6>
                        <h5><?php echo $_SESSION['first_name'] . ' ' . $_SESSION['last_name']; ?></h5>
                        <small>ID: <?php echo $_SESSION['admin_id'] ?? 'N/A'; ?></small>
                    </div>
                </div>
            </nav>
            
            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 p-3 main-content">
                <?php if ($message): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Dashboard Section -->
                <div id="dashboard" class="content-section">
                    <h2 class="mb-3" style="font-size: 1.5rem; margin-bottom: 15px !important;">Admin Dashboard</h2>
                    
                    <div class="row mb-4">
                        <div class="col-md-3 mb-3">
                            <div class="card stat-card text-center">
                                <div class="card-body">
                                    <i class="fas fa-user-clock fa-3x mb-3"></i>
                                    <h3><?php echo $pending_students; ?></h3>
                                    <p class="mb-0">Pending Students</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card stat-card text-center">
                                <div class="card-body">
                                    <i class="fas fa-user-graduate fa-3x mb-3"></i>
                                    <h3><?php echo $enrolled_students; ?></h3>
                                    <p class="mb-0">Enrolled Students</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card stat-card text-center">
                                <div class="card-body">
                                    <i class="fas fa-book fa-3x mb-3"></i>
                                    <h3><?php echo $total_courses; ?></h3>
                                    <p class="mb-0">Total Courses</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <div class="card stat-card text-center">
                                <div class="card-body">
                                    <i class="fas fa-clipboard-list fa-3x mb-3"></i>
                                    <h3><?php echo $total_enrollments; ?></h3>
                                    <p class="mb-0">Course Enrollments</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0"><i class="fas fa-user-clock me-2"></i>Pending Approvals</h5>
                                </div>
                                <div class="card-body">
                                    <?php if ($pending_students > 0): ?>
                                        <p class="mb-2">You have <strong><?php echo $pending_students; ?></strong> student(s) waiting for approval.</p>
                        <button class="btn btn-warning btn-sm" data-action="navigate" data-section="pending-students">
                                            <i class="fas fa-eye me-1"></i>Review Students
                                        </button>
                                    <?php else: ?>
                                        <p class="text-muted mb-0">No pending approvals at this time.</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Recent Activity</h5>
                                </div>
                                <div class="card-body">
                                    <p class="mb-1"><strong>Latest Enrollments:</strong></p>
                                    <?php if (empty($latest_enrollments)): ?>
                                        <p class="text-muted mb-0">No recent enrollment activity.</p>
                                    <?php else: ?>
                                        <?php foreach (array_slice($latest_enrollments, 0, 5) as $recent): ?>
                                            <small class="d-block mb-2">
                                                <i class="fas fa-user-graduate text-primary me-1"></i>
                                                <strong><?php echo htmlspecialchars($recent['student_id']); ?></strong> - 
                                                <?php echo htmlspecialchars($recent['first_name'] . ' ' . $recent['last_name']); ?>
                                                <br>
                                                <span class="text-muted ms-3">
                                                    <?php echo !empty($recent['course']) ? htmlspecialchars($recent['course']) : 'Program enrollment'; ?> - 
                                                    <?php echo date('M j, Y', strtotime($recent['enrolled_date'])); ?>
                                                </span>
                                            </small>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Users Management Section (Hidden - Content moved to Pending Students) -->
                <div id="users" class="content-section" style="display: none;">
                    <!-- Deprecated section - redirect handled by navigation system -->
                </div>
                
        <!-- Sections Management Section -->
        <div id="sections" class="content-section" style="display: none;">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2>Class Sections</h2>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSectionModal">
                    <i class="fas fa-plus me-2"></i>Add New Section
                </button>
            </div>
            
            <!-- Filters Section -->
            <div class="card mb-4">
                <div class="card-header" style="cursor: pointer;" onclick="toggleFilters()">
                    <h5 class="mb-0">
                        <i class="fas fa-filter me-2"></i>Filter Sections
                        <i class="fas fa-chevron-down float-end" id="filter-toggle-icon"></i>
                    </h5>
                </div>
                <div class="card-body" id="filter-body" style="display: none;">
                    <div class="row g-3">
                        <!-- Program Filter -->
                        <div class="col-md-2">
                            <label for="filter_program" class="form-label">Program</label>
                            <select class="form-select" id="filter_program" onchange="filterSections()">
                                <option value="">All Programs</option>
                                <?php foreach ($all_programs as $program): ?>
                                    <option value="<?php echo htmlspecialchars($program['program_code']); ?>">
                                        <?php echo htmlspecialchars($program['program_code']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <!-- Year Level Filter -->
                        <div class="col-md-2">
                            <label for="filter_year" class="form-label">Year Level</label>
                            <select class="form-select" id="filter_year" onchange="filterSections()">
                                <option value="">All Years</option>
                                <option value="1st Year">1st Year</option>
                                <option value="2nd Year">2nd Year</option>
                                <option value="3rd Year">3rd Year</option>
                                <option value="4th Year">4th Year</option>
                                <option value="5th Year">5th Year</option>
                            </select>
                        </div>
                        
                        <!-- Semester Filter -->
                        <div class="col-md-2">
                            <label for="filter_semester" class="form-label">Semester</label>
                            <select class="form-select" id="filter_semester" onchange="filterSections()">
                                <option value="">All Semesters</option>
                                <option value="First Semester">First Semester</option>
                                <option value="Second Semester">Second Semester</option>
                                <option value="Summer">Summer</option>
                            </select>
                        </div>
                        
                        <!-- Section Type Filter -->
                        <div class="col-md-2">
                            <label for="filter_type" class="form-label">Section Type</label>
                            <select class="form-select" id="filter_type" onchange="filterSections()">
                                <option value="">All Types</option>
                                <option value="Morning">Morning</option>
                                <option value="Afternoon">Afternoon</option>
                                <option value="Evening">Evening</option>
                            </select>
                        </div>
                        
                        <!-- Status Filter -->
                        <div class="col-md-2">
                            <label for="filter_status" class="form-label">Status</label>
                            <select class="form-select" id="filter_status" onchange="filterSections()">
                                <option value="">All Status</option>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        
                        <!-- Capacity Filter -->
                        <div class="col-md-2">
                            <label for="filter_capacity" class="form-label">Capacity</label>
                            <select class="form-select" id="filter_capacity" onchange="filterSections()">
                                <option value="">All Capacities</option>
                                <option value="available">Has Space</option>
                                <option value="full">Full</option>
                                <option value="almost-full">Almost Full (90%+)</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Search and Sort Row -->
                    <div class="row g-3 mt-2">
                        <!-- Search -->
                        <div class="col-md-6">
                            <label for="search_sections" class="form-label">Search Sections</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="fas fa-search"></i></span>
                                <input type="text" class="form-control" id="search_sections" 
                                       placeholder="Search by section name, program, or room..." 
                                       onkeyup="filterSections()">
                            </div>
                        </div>
                        
                        <!-- Sort Options -->
                        <div class="col-md-3">
                            <label for="sort_sections" class="form-label">Sort By</label>
                            <select class="form-select" id="sort_sections" onchange="filterSections()">
                                <option value="program">Program</option>
                                <option value="year">Year Level</option>
                                <option value="semester">Semester</option>
                                <option value="type">Section Type</option>
                                <option value="capacity">Capacity</option>
                                <option value="enrolled">Enrollment</option>
                                <option value="status">Status</option>
                            </select>
                        </div>
                        
                        <!-- Sort Direction -->
                        <div class="col-md-2">
                            <label for="sort_direction" class="form-label">Direction</label>
                            <select class="form-select" id="sort_direction" onchange="filterSections()">
                                <option value="asc">Ascending</option>
                                <option value="desc">Descending</option>
                            </select>
                        </div>
                        
                        <!-- Clear Filters -->
                        <div class="col-md-1">
                            <label class="form-label">&nbsp;</label>
                            <button type="button" class="btn btn-outline-secondary w-100" onclick="clearFilters()" 
                                    title="Clear all filters">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    
                    <!-- Filter Results Summary -->
                    <div class="mt-3">
                        <small class="text-muted">
                            Showing <span id="filtered-count"><?php echo count($all_sections); ?></span> of 
                            <span id="total-count"><?php echo count($all_sections); ?></span> sections
                        </small>
                    </div>
                </div>
            </div>
                    
                    <div class="table-responsive">
                        <table class="table table-hover" id="sections-table">
                            <thead class="table-dark">
                                <tr>
                                    <th data-sort="program">Program <i class="fas fa-sort"></i></th>
                                    <th data-sort="year">Year Level <i class="fas fa-sort"></i></th>
                                    <th data-sort="semester">Semester <i class="fas fa-sort"></i></th>
                                    <th data-sort="section">Section Name <i class="fas fa-sort"></i></th>
                                    <th data-sort="type">Type <i class="fas fa-sort"></i></th>
                                    <th data-sort="capacity">Capacity <i class="fas fa-sort"></i></th>
                                    <th data-sort="enrolled">Enrolled <i class="fas fa-sort"></i></th>
                                    <th data-sort="available">Available <i class="fas fa-sort"></i></th>
                                    <th data-sort="status">Status <i class="fas fa-sort"></i></th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="sections-table-body">
                                <?php foreach ($all_sections as $sec): ?>
                                    <?php 
                                    $available = $sec['max_capacity'] - $sec['current_enrolled'];
                                    $percentage = ($sec['current_enrolled'] / $sec['max_capacity']) * 100;
                                    $status_class = $percentage >= 90 ? 'bg-danger' : ($percentage >= 70 ? 'bg-warning' : 'bg-success');
                                    ?>
                                    <tr class="section-row" 
                                        data-id="<?php echo $sec['id']; ?>"
                                        data-program="<?php echo htmlspecialchars($sec['program_code']); ?>"
                                        data-year="<?php echo htmlspecialchars($sec['year_level']); ?>"
                                        data-semester="<?php echo htmlspecialchars($sec['semester']); ?>"
                                        data-section="<?php echo htmlspecialchars($sec['section_name']); ?>"
                                        data-type="<?php echo htmlspecialchars($sec['section_type']); ?>"
                                        data-capacity="<?php echo $sec['max_capacity']; ?>"
                                        data-enrolled="<?php echo $sec['current_enrolled']; ?>"
                                        data-available="<?php echo $available; ?>"
                                        data-status="<?php echo $sec['status']; ?>"
                                        data-percentage="<?php echo $percentage; ?>"
                                        data-search-text="<?php echo htmlspecialchars(strtolower($sec['program_code'] . ' ' . $sec['year_level'] . ' ' . $sec['semester'] . ' ' . $sec['section_name'] . ' ' . $sec['section_type'])); ?>">
                                        <td><strong><?php echo htmlspecialchars($sec['program_code']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($sec['year_level']); ?></td>
                                        <td><?php echo htmlspecialchars($sec['semester']); ?></td>
                                        <td><?php echo htmlspecialchars($sec['section_name']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $sec['section_type'] == 'Morning' ? 'bg-info' : ($sec['section_type'] == 'Afternoon' ? 'bg-warning' : 'bg-dark'); ?>">
                                                <?php echo htmlspecialchars($sec['section_type']); ?>
                                            </span>
                                        </td>
                                        <td id="capacity-<?php echo $sec['id']; ?>"><?php echo $sec['max_capacity']; ?></td>
                                        <td id="enrolled-<?php echo $sec['id']; ?>"><?php echo $sec['current_enrolled']; ?></td>
                                        <td><span class="badge <?php echo $status_class; ?>" id="available-<?php echo $sec['id']; ?>"><?php echo $available; ?></span></td>
                                        <td>
                                            <span class="badge <?php echo $sec['status'] == 'active' ? 'bg-success' : 'bg-secondary'; ?>">
                                                <?php echo ucfirst($sec['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <button class="btn btn-sm btn-success btn-action manage-schedule-btn" 
                                                    data-section-id="<?php echo (int)$sec['id']; ?>"
                                                    data-section-name="<?php echo htmlspecialchars($sec['section_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-program-id="<?php echo (int)$sec['program_id']; ?>"
                                                    data-year-level="<?php echo htmlspecialchars($sec['year_level'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-semester="<?php echo htmlspecialchars($sec['semester'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <i class="fas fa-calendar-alt"></i> Schedule
                                            </button>
                                            <button class="btn btn-sm btn-primary btn-action edit-section-btn" 
                                                    data-section-id="<?php echo (int)$sec['id']; ?>"
                                                    data-section-name="<?php echo htmlspecialchars($sec['section_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-max-capacity="<?php echo (int)$sec['max_capacity']; ?>"
                                                    data-status="<?php echo htmlspecialchars($sec['status'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            <button class="btn btn-sm btn-info btn-action view-section-students-btn" 
                                                    data-section-id="<?php echo (int)$sec['id']; ?>"
                                                    data-section-name="<?php echo htmlspecialchars($sec['section_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <i class="fas fa-users"></i> Students
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- No Results Message -->
                    <div id="no-results" class="text-center py-4" style="display: none;">
                        <i class="fas fa-search fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted">No sections found</h5>
                        <p class="text-muted">Try adjusting your filters or search terms.</p>
                        <button class="btn btn-outline-primary" onclick="clearFilters()">
                            <i class="fas fa-refresh me-2"></i>Clear Filters
                        </button>
                    </div>
                </div>
                <!-- Curriculum Management Section -->
                <div id="curriculum" class="content-section" style="display: none;">
                    <h2 class="mb-4">Curriculum Management</h2>
                    
                    <!-- Program Selector -->
                    <div class="card mb-4">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-book-open me-2"></i>Select Program</h5>
                            <div>
                                <button class="btn btn-outline-secondary btn-sm me-2" onclick="refreshProgramCards(event)" title="Refresh program data">
                                    <i class="fas fa-sync-alt me-1"></i>Refresh
                                </button>
                                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addProgramModal">
                                    <i class="fas fa-plus me-2"></i>Add New Program
                                </button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <?php foreach ($all_programs as $program): ?>
                                    <div class="col-md-4 mb-3">
                                        <div class="card h-100 curriculum-program-card" style="cursor: pointer;" 
                                             data-program-id="<?php echo (int)$program['id']; ?>" 
                                             data-program-code="<?php echo htmlspecialchars($program['program_code'], ENT_QUOTES, 'UTF-8'); ?>"
                                             data-program-name="<?php echo htmlspecialchars($program['program_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                            <div class="card-body text-center position-relative">
                                                <button class="btn btn-sm btn-danger position-absolute top-0 end-0 m-2" 
                                                        data-program-id="<?php echo (int)$program['id']; ?>"
                                                        data-program-code="<?php echo htmlspecialchars($program['program_code'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-program-name="<?php echo htmlspecialchars($program['program_name'], ENT_QUOTES, 'UTF-8'); ?>"
                                                        onclick="deleteProgram(event, <?php echo (int)$program['id']; ?>, '<?php echo htmlspecialchars($program['program_code'], ENT_QUOTES, 'UTF-8'); ?>', '<?php echo htmlspecialchars($program['program_name'], ENT_QUOTES, 'UTF-8'); ?>'); return false;"
                                                        title="Delete Program"
                                                        style="z-index: 10; opacity: 0.8;"
                                                        onmouseover="this.style.opacity='1'"
                                                        onmouseout="this.style.opacity='0.8'">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                                <i class="fas fa-graduation-cap fa-3x text-primary mb-3"></i>
                                                <h5><?php echo htmlspecialchars($program['program_code']); ?></h5>
                                                <p class="small text-muted"><?php echo htmlspecialchars($program['program_name']); ?></p>
                                                <div class="mt-2">
                                                    <span class="badge bg-info"><?php echo $program['total_units']; ?> units</span>
                                                    <span class="badge bg-secondary"><?php echo $program['years_to_complete']; ?> years</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Curriculum Display Area -->
                    <div id="curriculum-display" style="display: none;">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h3 id="curriculum-title">Program Curriculum</h3>
                            <button class="btn btn-primary" id="show-add-curriculum-btn">
                                <i class="fas fa-plus me-2"></i>Add Course to Curriculum
                            </button>
                        </div>
                        <div id="curriculum-content"></div>
                    </div>
                </div>

                <!-- Curriculum Submissions Review Section -->
                <div id="curriculum-submissions" class="content-section" style="display: none;">
                    <h2 class="mb-4"><i class="fas fa-paper-plane me-2"></i>Curriculum Submissions Review</h2>

                    <?php if (empty($curriculum_submissions)): ?>
                        <div class="card">
                            <div class="card-body text-center py-5">
                                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                <h4 class="text-muted">No Curriculum Submissions</h4>
                                <p class="text-muted">Program heads haven't submitted any curriculum updates yet.</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <?php
                            $status_counts = ['draft' => 0, 'submitted' => 0, 'approved' => 0, 'rejected' => 0];
                            foreach ($curriculum_submissions as $submission) {
                                $status_counts[$submission['status']]++;
                            }
                            ?>

                            <!-- Status Overview Cards -->
                            <div class="col-md-3 mb-4">
                                <div class="card bg-warning text-white">
                                    <div class="card-body text-center">
                                        <i class="fas fa-file-alt fa-2x mb-2"></i>
                                        <h4><?php echo $status_counts['draft']; ?></h4>
                                        <small>Draft</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-4">
                                <div class="card bg-info text-white">
                                    <div class="card-body text-center">
                                        <i class="fas fa-paper-plane fa-2x mb-2"></i>
                                        <h4><?php echo $status_counts['submitted']; ?></h4>
                                        <small>Submitted</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-4">
                                <div class="card bg-success text-white">
                                    <div class="card-body text-center">
                                        <i class="fas fa-check-circle fa-2x mb-2"></i>
                                        <h4><?php echo $status_counts['approved']; ?></h4>
                                        <small>Approved</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 mb-4">
                                <div class="card bg-danger text-white">
                                    <div class="card-body text-center">
                                        <i class="fas fa-times-circle fa-2x mb-2"></i>
                                        <h4><?php echo $status_counts['rejected']; ?></h4>
                                        <small>Rejected</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Filters -->
                        <div class="card mb-3">
                            <div class="card-body">
                                <div class="row g-3">
                                    <div class="col-md-3">
                                        <label class="form-label small">Filter by Status</label>
                                        <select class="form-select form-select-sm" id="filter-status">
                                            <option value="">All Statuses</option>
                                            <option value="draft">Draft</option>
                                            <option value="submitted">Submitted</option>
                                            <option value="approved">Approved</option>
                                            <option value="rejected">Rejected</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small">Filter by Program</label>
                                        <select class="form-select form-select-sm" id="filter-program">
                                            <option value="">All Programs</option>
                                            <?php foreach ($all_programs as $prog): ?>
                                                <option value="<?php echo htmlspecialchars($prog['program_code']); ?>">
                                                    <?php echo htmlspecialchars($prog['program_code']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small">Search</label>
                                        <input type="text" class="form-control form-control-sm" id="search-submissions" placeholder="Search by title, program head...">
                                    </div>
                                    <div class="col-md-3 d-flex align-items-end">
                                        <button class="btn btn-sm btn-outline-secondary w-100" onclick="clearSubmissionFilters()">
                                            <i class="fas fa-times me-1"></i>Clear Filters
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Submissions Table -->
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0"><i class="fas fa-list me-2"></i>All Curriculum Submissions</h5>
                                <div id="bulk-actions" style="display: none;">
                                    <button class="btn btn-success btn-sm" id="bulk-approve-submissions-btn">
                                        <i class="fas fa-check-circle me-1"></i>Bulk Approve Selected
                                    </button>
                                    <button class="btn btn-secondary btn-sm ms-2" id="clear-selection-btn">
                                        <i class="fas fa-times me-1"></i>Clear
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover" id="submissions-table">
                                        <thead>
                                            <tr>
                                                <th width="50">
                                                    <input type="checkbox" id="select-all">
                                                </th>
                                                <th>Program Head</th>
                                                <th>Program</th>
                                                <th>Title</th>
                                                <th>Academic Year</th>
                                                <th>Semester</th>
                                                <th>Subjects</th>
                                                <th>Status</th>
                                                <th>Reviewed By</th>
                                                <th>Submitted</th>
                                                <th>Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($curriculum_submissions as $submission): ?>
                                                <tr data-status="<?php echo htmlspecialchars($submission['status']); ?>" 
                                                    data-program="<?php echo htmlspecialchars($submission['program_code']); ?>"
                                                    data-search="<?php echo htmlspecialchars(strtolower($submission['submission_title'] . ' ' . $submission['first_name'] . ' ' . $submission['last_name'])); ?>">
                                                    <td>
                                                        <?php if ($submission['status'] == 'submitted'): ?>
                                                            <input type="checkbox" class="submission-checkbox" value="<?php echo $submission['id']; ?>">
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        $is_dean_upload = ($submission['is_dean_upload'] == 'dean' || 
                                                                         (isset($submission['reviewer_is_dean']) && $submission['reviewer_is_dean'] && 
                                                                          $submission['status'] == 'approved' && 
                                                                          strpos($submission['submission_title'] ?? '', 'Dean Direct Upload') !== false));
                                                        ?>
                                                        <?php if ($is_dean_upload): ?>
                                                            <span class="badge bg-success mb-1"><i class="fas fa-user-shield"></i> Dean Direct Upload</span><br>
                                                            <?php if (!empty($submission['dean_first_name']) && !empty($submission['dean_last_name'])): ?>
                                                                <strong><?php echo htmlspecialchars($submission['dean_first_name'] . ' ' . $submission['dean_last_name']); ?></strong>
                                                                <?php if (!empty($submission['dean_id'])): ?>
                                                                    <br><small class="text-muted"><?php echo htmlspecialchars($submission['dean_id']); ?></small>
                                                                <?php endif; ?>
                                                            <?php elseif (!empty($submission['reviewer_first_name']) && !empty($submission['reviewer_is_dean'])): ?>
                                                                <strong><?php echo htmlspecialchars($submission['reviewer_first_name'] . ' ' . $submission['reviewer_last_name']); ?></strong>
                                                                <?php if (!empty($submission['reviewer_id'])): ?>
                                                                    <br><small class="text-muted"><?php echo htmlspecialchars($submission['reviewer_id']); ?></small>
                                                                <?php endif; ?>
                                                            <?php else: ?>
                                                                <span class="text-muted">Dean</span>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <?php if (!empty($submission['first_name']) && !empty($submission['last_name'])): ?>
                                                                <?php echo htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']); ?>
                                                                <?php if (!empty($submission['program_head_email'])): ?>
                                                                    <br><small class="text-muted"><?php echo htmlspecialchars($submission['program_head_email']); ?></small>
                                                                <?php endif; ?>
                                                            <?php else: ?>
                                                                <span class="text-muted">-</span>
                                                            <?php endif; ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($submission['program_code']); ?></td>
                                                    <td>
                                                        <?php echo htmlspecialchars($submission['submission_title']); ?>
                                                        <?php if ($submission['is_dean_upload'] == 'dean' || strpos($submission['submission_title'] ?? '', 'Dean Direct Upload') !== false): ?>
                                                            <br><small class="text-success"><i class="fas fa-check-circle"></i> Direct upload by Dean</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($submission['academic_year']); ?></td>
                                                    <td><?php echo htmlspecialchars($submission['semester']); ?></td>
                                                    <td>
                                                        <button class="btn btn-sm btn-outline-info view-subjects-btn" 
                                                                data-submission-id="<?php echo (int)$submission['id']; ?>"
                                                                title="View subjects">
                                                            <i class="fas fa-list"></i> <?php echo $submission['total_subjects']; ?> subjects
                                                        </button>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $status_class = '';
                                                        switch ($submission['status']) {
                                                            case 'draft': $status_class = 'bg-warning'; break;
                                                            case 'submitted': $status_class = 'bg-info'; break;
                                                            case 'approved': $status_class = 'bg-success'; break;
                                                            case 'rejected': $status_class = 'bg-danger'; break;
                                                        }
                                                        ?>
                                                        <span class="badge <?php echo $status_class; ?>"><?php echo ucfirst($submission['status']); ?></span>
                                                    </td>
                                                    <td>
                                                        <?php if ($submission['reviewer_first_name']): ?>
                                                            <?php echo htmlspecialchars($submission['reviewer_first_name'] . ' ' . $submission['reviewer_last_name']); ?>
                                                            <?php if ($submission['reviewer_id']): ?>
                                                                <br><small class="text-muted"><?php echo htmlspecialchars($submission['reviewer_id']); ?></small>
                                                            <?php endif; ?>
                                                            <?php if ($submission['reviewed_at']): ?>
                                                                <br><small class="text-muted"><?php echo date('M j, Y', strtotime($submission['reviewed_at'])); ?></small>
                                                            <?php endif; ?>
                                                        <?php else: ?>
                                                            <span class="text-muted">-</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php echo $submission['submitted_at'] ? date('M j, Y', strtotime($submission['submitted_at'])) : 'Not submitted'; ?>
                                                        <?php if ($submission['submitted_at']): ?>
                                                            <br><small class="text-muted"><?php echo date('g:i A', strtotime($submission['submitted_at'])); ?></small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div class="btn-group-vertical btn-group-sm" role="group">
                                                            <button class="btn btn-outline-primary view-submission-btn" data-submission-id="<?php echo (int)$submission['id']; ?>">
                                                                <i class="fas fa-eye"></i> View
                                                            </button>
                                                            <?php if ($submission['log_count'] > 0): ?>
                                                                <button class="btn btn-outline-info view-history-btn mt-1" data-submission-id="<?php echo (int)$submission['id']; ?>">
                                                                    <i class="fas fa-history"></i> History
                                                                </button>
                                                            <?php endif; ?>
                                                            <?php if ($submission['status'] == 'submitted'): ?>
                                                                <div class="btn-group btn-group-sm mt-1" role="group">
                                                                    <button class="btn btn-success approve-submission-btn" data-submission-id="<?php echo (int)$submission['id']; ?>">
                                                                        <i class="fas fa-check"></i> Approve
                                                                    </button>
                                                                    <button class="btn btn-danger reject-submission-btn" data-submission-id="<?php echo (int)$submission['id']; ?>">
                                                                        <i class="fas fa-times"></i> Reject
                                                                    </button>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Submission History Modal -->
                <div class="modal fade" id="submissionHistoryModal" tabindex="-1">
                    <div class="modal-dialog modal-lg">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title"><i class="fas fa-history me-2"></i>Submission History</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body" id="submission-history-content">
                                <div class="text-center py-4">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Loading...</span>
                                    </div>
                                    <p class="mt-2 text-muted">Loading history...</p>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Chatbot FAQs Management Section -->
                <!-- Next Semester Enrollments Section -->
                <div id="next-semester" class="content-section" style="display: none;">
                    <h2 class="mb-4"><i class="fas fa-calendar-alt me-2"></i>Next Semester Enrollment Requests</h2>
                    
                    <?php
                    // Get all next semester enrollment requests (all statuses except approved/rejected)
                    // Show all pending and draft requests in Next Semester section
                    $next_sem_query = "SELECT nse.*, u.student_id, u.first_name, u.last_name, u.email,
                                       COUNT(nsss.id) as subject_count,
                                       (SELECT COUNT(*) FROM certificate_of_registration cor WHERE cor.enrollment_id = nse.id) as has_cor
                                       FROM next_semester_enrollments nse
                                       JOIN users u ON nse.user_id = u.id
                                       LEFT JOIN next_semester_subject_selections nsss ON nse.id = nsss.enrollment_request_id
                                       WHERE nse.request_status IN ('draft', 'pending_program_head', 'pending_registrar', 'pending_admin')
                                       GROUP BY nse.id
                                       ORDER BY 
                                           CASE nse.request_status
                                               WHEN 'pending_admin' THEN 1
                                               WHEN 'pending_registrar' THEN 2
                                               WHEN 'pending_program_head' THEN 3
                                               WHEN 'draft' THEN 4
                                               ELSE 5
                                           END,
                                           nse.created_at DESC";
                    $next_sem_stmt = $conn->prepare($next_sem_query);
                    $next_sem_stmt->execute();
                    $next_sem_requests = $next_sem_stmt->fetchAll(PDO::FETCH_ASSOC);
                    ?>
                    
                    <div class="card">
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-dark">
                                        <tr>
                                            <th>Student ID</th>
                                            <th>Name</th>
                                            <th>Current Level</th>
                                            <th>Target Term</th>
                                            <th>Subjects</th>
                                            <th>Status</th>
                                            <th>Submitted</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php if (empty($next_sem_requests)): ?>
                                            <tr>
                                                <td colspan="8" class="text-center py-4">
                                                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                                    <p class="text-muted">No enrollment requests yet.</p>
                                                </td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($next_sem_requests as $request): ?>
                                                <tr>
                                                    <td><strong><?php echo htmlspecialchars($request['student_id']); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></td>
                                                    <td><?php echo htmlspecialchars($request['current_year_level']); ?></td>
                                                    <td>
                                                        <?php echo htmlspecialchars($request['target_academic_year']); ?><br>
                                                        <small class="text-muted"><?php echo htmlspecialchars($request['target_semester']); ?></small>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-info"><?php echo $request['subject_count']; ?> subjects</span>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        $status_colors = [
                                                            'draft' => 'secondary',
                                                            'pending_program_head' => 'warning',
                                                            'pending_registrar' => 'info',
                                                            'pending_admin' => 'primary',
                                                            'confirmed' => 'success',
                                                            'rejected' => 'danger'
                                                        ];
                                                        $color = $status_colors[$request['request_status']] ?? 'secondary';
                                                        $status_labels = [
                                                            'draft' => 'Draft',
                                                            'pending_program_head' => 'Pending Program Head',
                                                            'pending_registrar' => 'Pending Registrar',
                                                            'pending_admin' => 'Pending Admin',
                                                            'confirmed' => 'Confirmed',
                                                            'rejected' => 'Rejected'
                                                        ];
                                                        $status_label = $status_labels[$request['request_status']] ?? strtoupper($request['request_status']);
                                                        ?>
                                                        <span class="badge bg-<?php echo $color; ?>">
                                                            <?php echo $status_label; ?>
                                                        </span>
                                                        <?php if ($request['has_cor'] > 0): ?>
                                                            <br><small class="text-success"><i class="fas fa-check-circle"></i> COR Generated</small>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo date('M j, Y', strtotime($request['created_at'])); ?></td>
                                                    <td>
                                                        <?php 
                                                        $status = $request['request_status'];
                                                        // Admin can enroll students from both pending_registrar and pending_admin status
                                                        if (in_array($status, ['pending_registrar', 'pending_admin'])): ?>
                                                            <div class="btn-group" role="group">
                                                                <button class="btn btn-success btn-sm enroll-btn" 
                                                                        data-request-id="<?php echo $request['id']; ?>"
                                                                        data-student-name="<?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?>">
                                                                    <i class="fas fa-check-circle"></i> Enroll
                                                                </button>
                                                                <a href="<?php echo htmlspecialchars(add_session_to_url('review_next_semester.php?request_id=' . $request['id'])); ?>"
                                                                   class="btn btn-primary btn-sm"
                                                                   data-no-intercept="true"
                                                                   rel="nofollow"
                                                                   title="Review Details">
                                                                    <i class="fas fa-clipboard-check"></i> Review
                                                                </a>
                                                            </div>
                                                        <?php elseif (in_array($status, ['draft', 'pending_program_head'])): ?>
                                                            <a href="<?php echo htmlspecialchars(add_session_to_url('review_next_semester.php?request_id=' . $request['id'])); ?>"
                                                               class="btn btn-primary btn-sm"
                                                               data-no-intercept="true"
                                                               rel="nofollow">
                                                                <i class="fas fa-clipboard-check"></i> Review
                                                            </a>
                                                        <?php elseif ($status == 'confirmed'): ?>
                                                            <span class="badge bg-success">Confirmed</span>
                                                        <?php elseif ($status == 'rejected'): ?>
                                                            <span class="badge bg-danger">Rejected</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $status))); ?></span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div id="chatbot" class="content-section" style="display: none;">
                    <h2 class="mb-4"><i class="fas fa-robot me-2"></i>Chatbot FAQs Management</h2>
                    
                    <!-- Statistics Cards -->
                    <div class="row mb-4">
                        <div class="col-md-3">
                            <div class="card text-center bg-primary text-white">
                                <div class="card-body">
                                    <i class="fas fa-question-circle fa-2x mb-2"></i>
                                    <h3 id="totalFAQs">0</h3>
                                    <p class="mb-0">Total FAQs</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card text-center bg-success text-white">
                                <div class="card-body">
                                    <i class="fas fa-comments fa-2x mb-2"></i>
                                    <h3 id="totalInquiries">0</h3>
                                    <p class="mb-0">Student Inquiries</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card text-center bg-info text-white">
                                <div class="card-body">
                                    <i class="fas fa-eye fa-2x mb-2"></i>
                                    <h3 id="mostViewedCount">0</h3>
                                    <p class="mb-0">Most Viewed</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="card text-center bg-warning text-white">
                                <div class="card-body">
                                    <i class="fas fa-layer-group fa-2x mb-2"></i>
                                    <h3 id="categoriesCount">0</h3>
                                    <p class="mb-0">Categories</p>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- FAQs Management -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="mb-0"><i class="fas fa-list me-2"></i>All FAQs</h5>
                            <button class="btn btn-primary" onclick="showAddFAQModal()">
                                <i class="fas fa-plus me-2"></i>Add New FAQ
                        </button>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 25%;">Question</th>
                                            <th style="width: 30%;">Answer</th>
                                            <th style="width: 10%;">Category</th>
                                            <th style="width: 10%;">Keywords</th>
                                            <th style="width: 5%;" class="text-center">Views</th>
                                            <th style="width: 8%;" class="text-center">Status</th>
                                            <th style="width: 12%;" class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="faqsTableBody">
                                        <tr>
                                            <td colspan="7" class="text-center">Loading FAQs...</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Recent Inquiries -->
                    <div class="card mt-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Student Inquiries</h5>
                        </div>
                        <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Student</th>
                                            <th>Question</th>
                                            <th>Answer</th>
                                            <th>Date</th>
                                </tr>
                            </thead>
                                    <tbody id="inquiriesTableBody">
                                        <tr>
                                            <td colspan="4" class="text-center">Loading inquiries...</td>
                                    </tr>
                            </tbody>
                        </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Pending Students Section -->
                <div id="pending-students" class="content-section" style="display: none;">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h2>Pending Students</h2>
                        <span class="text-muted">Students awaiting enrollment approval</span>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>Student ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Account Status</th>
                                    <th>Assigned Sections</th>
                                    <th>Registered</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $has_pending = false;
                                foreach ($all_users as $student): 
                                    if ($student['role'] == 'student' 
                                        && ($student['enrollment_status'] ?? 'pending') == 'pending'
                                        && isset($registrar_queue_lookup[$student['id'] ?? 0])
                                        && !isset($next_sem_user_ids_lookup[$student['id'] ?? 0])): // Exclude students with next semester enrollments
                                        $has_pending = true;
                                        $student_sections = $section_assignments[$student['id']] ?? [];
                                        $preferred_program_raw = $student['preferred_program'] ?? '';
                                        $preferred_program_id = null;
                                        if (!empty($preferred_program_raw)) {
                                            $normalized_preferred = strtolower(trim($preferred_program_raw));
                                            if (isset($program_lookup_by_name[$normalized_preferred])) {
                                                $preferred_program_id = $program_lookup_by_name[$normalized_preferred];
                                            } elseif (isset($program_lookup_by_code[$normalized_preferred])) {
                                                $preferred_program_id = $program_lookup_by_code[$normalized_preferred];
                                            } else {
                                                foreach ($all_programs as $program_item) {
                                                    $program_name_normalized = strtolower(trim($program_item['program_name']));
                                                    $program_code_normalized = strtolower(trim($program_item['program_code']));
                                                    if (strpos($program_name_normalized, $normalized_preferred) !== false || strpos($normalized_preferred, $program_name_normalized) !== false || strpos($program_code_normalized, $normalized_preferred) !== false) {
                                                        $preferred_program_id = $program_item['id'];
                                                        break;
                                                    }
                                                }
                                            }
                                        }
                                        $student_full_name = trim($student['first_name'] . ' ' . $student['last_name']);
                                        $student_id_attr = (int)$student['id'];
                                        $student_full_name_attr = htmlspecialchars($student_full_name, ENT_QUOTES, 'UTF-8');
                                        $preferred_program_id_attr = $preferred_program_id !== null ? (int)$preferred_program_id : 'null';
                                        $preferred_program_name_attr = htmlspecialchars($preferred_program_raw, ENT_QUOTES, 'UTF-8');
                                ?>
                                        <tr class="clickable-row" data-student-id="<?php echo $student_id_attr; ?>" style="cursor: pointer;">
                                            <td><strong><?php echo htmlspecialchars($student['student_id']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                                            <td><?php echo htmlspecialchars($student['email']); ?></td>
                                            <td><?php echo htmlspecialchars($student['phone']); ?></td>
                                            <td>
                                                <span class="badge <?php echo $student['status'] == 'active' ? 'bg-success' : ($student['status'] == 'pending' ? 'bg-warning' : 'bg-secondary'); ?>">
                                                    <?php echo ucfirst($student['status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if (empty($student_sections)): ?>
                                                    <span class="text-muted small">No section assigned</span>
                                                <?php else: ?>
                                                    <?php foreach ($student_sections as $section_info): ?>
                                                        <div class="badge bg-primary mb-1" style="display: block; text-align: left;">
                                                            <strong><?php echo htmlspecialchars($section_info['section_name']); ?></strong><br>
                                                            <small><?php echo htmlspecialchars($section_info['year_level'] . ' - ' . $section_info['semester']); ?></small>
                                                        </div>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo date('M j, Y', strtotime($student['created_at'])); ?></td>
                                            <td>
                                                <i class="fas fa-chevron-down expand-icon"></i>
                                            </td>
                                        </tr>
                                        <tr class="action-row" id="actions-<?php echo $student_id_attr; ?>" style="display: none;">
                                            <td colspan="8" class="bg-light">
                                                <div class="p-3">
                                                    <h6 class="mb-3">Actions for <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></h6>
                                                    <div class="row g-3">
                                                        <div class="col-md-7">
                                                            <div class="d-flex flex-wrap gap-2">
                                                                <button class="btn btn-info btn-action btn-sm" data-action="view-applicant" data-user-id="<?php echo $student_id_attr; ?>">
                                                                    <i class="fas fa-user"></i> View Applicant
                                                                </button>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-5">
                                                            <?php if ($student['status'] == 'pending'): ?>
                                                                <form method="POST" action="update_user.php" style="display: inline;">
                                                                    <input type="hidden" name="user_id" value="<?php echo $student['id']; ?>">
                                                                    <input type="hidden" name="status" value="active">
                                                                    <button type="submit" class="btn btn-success btn-action btn-sm mb-2 me-2">
                                                                        <i class="fas fa-check"></i> Approve
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                            
                                                            <?php if ($student['status'] == 'active'): ?>
                                                                <form method="POST" action="update_user.php" style="display: inline;">
                                                                    <input type="hidden" name="user_id" value="<?php echo $student['id']; ?>">
                                                                    <input type="hidden" name="status" value="inactive">
                                                                    <button type="submit" class="btn btn-warning btn-action btn-sm mb-2 me-2">
                                                                        <i class="fas fa-pause"></i> Suspend
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                            
                                                            <?php if ($student['status'] == 'inactive'): ?>
                                                                <form method="POST" action="update_user.php" style="display: inline;">
                                                                    <input type="hidden" name="user_id" value="<?php echo $student['id']; ?>">
                                                                    <input type="hidden" name="status" value="active">
                                                                    <button type="submit" class="btn btn-success btn-action btn-sm mb-2 me-2">
                                                                        <i class="fas fa-play"></i> Activate
                                                                    </button>
                                                                </form>
                                                            <?php endif; ?>
                                                            
                                                            <form method="POST" action="update_enrollment_status.php" style="display: inline;">
                                                                <input type="hidden" name="user_id" value="<?php echo $student['id']; ?>">
                                                                <select name="enrollment_status" class="form-select form-select-sm d-inline-block mb-2" style="width: auto;" onchange="this.form.submit()">
                                                                    <option value="pending" selected>Pending</option>
                                                                    <option value="enrolled">Enroll Student</option>
                                                                </select>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                <?php if (!$has_pending): ?>
                                    <tr>
                                        <td colspan="8" class="text-center py-4">
                                            <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                                            <p class="text-muted">No pending students. All students are either enrolled or inactive.</p>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                
                <!-- Enrolled Students Section -->
                <div id="enrolled-students" class="content-section" style="display: none;">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h2>Enrolled Students</h2>
                        <span class="text-muted">Students officially enrolled in the institution</span>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>Student ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Course/Program</th>
                                    <th>Year Level</th>
                                    <th>Student Type</th>
                                    <th>Academic Year</th>
                                    <th>Semester</th>
                                    <th>Assigned Sections</th>
                                    <th>Enrolled Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($all_enrolled_students)): ?>
                                    <tr>
                                        <td colspan="11" class="text-center py-4">
                                            <i class="fas fa-user-graduate fa-3x text-muted mb-3"></i>
                                            <p class="text-muted">No enrolled students yet. Go to Pending Students to enroll students.</p>
                                        </td>
                                    </tr>
                                <?php else: ?>
                                <?php foreach ($all_enrolled_students as $enrolled): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($enrolled['student_id']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($enrolled['first_name'] . ' ' . $enrolled['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($enrolled['email']); ?></td>
                                        <td><?php 
                                            // Display course from enrolled_students, fallback to sections if not set
                                            if (!empty($enrolled['course'])) {
                                                echo htmlspecialchars($enrolled['course']);
                                            } elseif (!empty($enrolled['program_codes'])) {
                                                echo htmlspecialchars($enrolled['program_codes']);
                                            } else {
                                                echo '-';
                                            }
                                        ?></td>
                                        <td><?php 
                                            // Display year level from enrolled_students table (primary source of truth)
                                            echo htmlspecialchars($enrolled['year_level'] ?? '1st Year');
                                        ?></td>
                                        <td>
                                            <span class="badge <?php echo ($enrolled['student_type'] ?? 'Regular') == 'Regular' ? 'bg-success' : 'bg-warning'; ?>">
                                                <?php echo htmlspecialchars($enrolled['student_type'] ?? 'Regular'); ?>
                                            </span>
                                        </td>
                                        <td><?php 
                                            // Display academic year from enrolled_students table (primary source of truth)
                                            echo htmlspecialchars($enrolled['academic_year'] ?? 'AY 2024-2025');
                                        ?></td>
                                        <td><?php 
                                            // Display semester from enrolled_students table (primary source of truth)
                                            echo htmlspecialchars($enrolled['semester'] ?? 'First Semester');
                                        ?></td>
                                        <td><?php 
                                            // Display assigned sections
                                            if (!empty($enrolled['assigned_sections'])) {
                                                echo '<span class="badge bg-primary">' . htmlspecialchars($enrolled['assigned_sections']) . '</span>';
                                            } else {
                                                echo '<span class="text-muted">No sections assigned</span>';
                                            }
                                        ?></td>
                                        <td><?php echo date('M j, Y', strtotime($enrolled['enrolled_date'])); ?></td>
                                        <td>
                                            <button type="button" class="btn btn-info btn-action btn-sm" data-action="view-enrolled-student" data-user-id="<?php echo (int)$enrolled['user_id']; ?>" data-student-name="<?php echo htmlspecialchars($enrolled['first_name'] . ' ' . $enrolled['last_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                                <i class="fas fa-eye"></i> View
                                            </button>
                                            
                                            <button type="button" class="btn btn-primary btn-action btn-sm" onclick="editEnrolledStudent(<?php echo $enrolled['user_id']; ?>, <?php echo json_encode($enrolled['first_name'] . ' ' . $enrolled['last_name'], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)">
                                                <i class="fas fa-edit"></i> Edit
                                            </button>
                                            
                                            <form method="POST" action="update_enrollment_status.php" style="display: inline;">
                                                <input type="hidden" name="user_id" value="<?php echo $enrolled['user_id']; ?>">
                                                <input type="hidden" name="enrollment_status" value="pending">
                                                <button type="submit" class="btn btn-warning btn-action btn-sm" onclick="return confirm('Are you sure you want to revert this student to pending status?')">
                                                    <i class="fas fa-undo"></i> Revert
                                                </button>
                                            </form>
                                            
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Section Modal -->
    <div class="modal fade" id="addSectionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Section</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="add_section.php">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="section_program_id" class="form-label">Program *</label>
                            <select class="form-select" name="program_id" id="section_program_id" required>
                                <option value="">Select Program</option>
                                <?php foreach ($all_programs as $prog): ?>
                                    <option value="<?php echo $prog['id']; ?>"><?php echo htmlspecialchars($prog['program_code'] . ' - ' . $prog['program_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="section_year_level" class="form-label">Year Level *</label>
                                <select class="form-select" name="year_level" id="section_year_level" required>
                                    <option value="1st Year">1st Year</option>
                                    <option value="2nd Year">2nd Year</option>
                                    <option value="3rd Year">3rd Year</option>
                                    <option value="4th Year">4th Year</option>
                                    <option value="5th Year">5th Year</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="section_semester" class="form-label">Semester *</label>
                                <select class="form-select" name="semester" id="section_semester" required>
                                    <option value="First Semester">First Semester</option>
                                    <option value="Second Semester">Second Semester</option>
                                    <option value="Summer">Summer</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="section_name" class="form-label">Section Name *</label>
                            <input type="text" class="form-control" name="section_name" id="section_name" readonly placeholder="Auto-generated (e.g., 24BSIS1M)" style="background-color: #f8f9fa;">
                            <small class="text-muted">
                                <i class="fas fa-info-circle"></i> Section name will be auto-generated in the format: 
                                <strong>YY + Program Code + Section Number + Type Code</strong> 
                                (e.g., 24BSIS1M for 2024-2025, BSIS, 1st Morning section)
                            </small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="section_type" class="form-label">Section Type *</label>
                            <select class="form-select" name="section_type" id="section_type" required>
                                <option value="Morning">Morning Section</option>
                                <option value="Afternoon">Afternoon Section</option>
                                <option value="Evening">Evening Section</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="section_max_capacity" class="form-label">Max Capacity *</label>
                            <input type="number" class="form-control" name="max_capacity" id="section_max_capacity" value="50" min="1" max="100" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="section_academic_year" class="form-label">Academic Year *</label>
                            <input type="text" class="form-control" name="academic_year" id="section_academic_year" value="AY 2024-2025" required>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Section</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Section Modal -->
    <div class="modal fade" id="editSectionModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Section</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="update_section.php">
                    <div class="modal-body">
                        <input type="hidden" name="section_id" id="edit_section_id">
                        
                        <div class="mb-3">
                            <label for="edit_section_name" class="form-label">Section Name *</label>
                            <input type="text" class="form-control" name="section_name" id="edit_section_name" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_max_capacity" class="form-label">Max Capacity *</label>
                            <input type="number" class="form-control" name="max_capacity" id="edit_max_capacity" min="1" max="100" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_status" class="form-label">Status</label>
                            <select class="form-select" name="status" id="edit_status">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Schedule Details Modal -->
    <div class="modal fade" id="editScheduleDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Edit Schedule Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="editScheduleDetailsForm">
                    <div class="modal-body">
                        <input type="hidden" id="edit_schedule_section_id">
                        <input type="hidden" id="edit_schedule_curriculum_id">
                        <input type="hidden" id="edit_schedule_id">
                        
                        <div class="alert alert-info">
                            <strong>Course:</strong> <span id="edit_course_code"></span>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Schedule Days</label>
                            <div class="row">
                                <div class="col-md-auto">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="edit_schedule_monday">
                                        <label class="form-check-label" for="edit_schedule_monday">Monday</label>
                                    </div>
                                </div>
                                <div class="col-md-auto">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="edit_schedule_tuesday">
                                        <label class="form-check-label" for="edit_schedule_tuesday">Tuesday</label>
                                    </div>
                                </div>
                                <div class="col-md-auto">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="edit_schedule_wednesday">
                                        <label class="form-check-label" for="edit_schedule_wednesday">Wednesday</label>
                                    </div>
                                </div>
                                <div class="col-md-auto">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="edit_schedule_thursday">
                                        <label class="form-check-label" for="edit_schedule_thursday">Thursday</label>
                                    </div>
                                </div>
                                <div class="col-md-auto">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="edit_schedule_friday">
                                        <label class="form-check-label" for="edit_schedule_friday">Friday</label>
                                    </div>
                                </div>
                                <div class="col-md-auto">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="edit_schedule_saturday">
                                        <label class="form-check-label" for="edit_schedule_saturday">Saturday</label>
                                    </div>
                                </div>
                                <div class="col-md-auto">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="edit_schedule_sunday">
                                        <label class="form-check-label" for="edit_schedule_sunday">Sunday</label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_time_start" class="form-label">Time Start</label>
                                <input type="time" class="form-control" id="edit_time_start">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="edit_time_end" class="form-label">Time End</label>
                                <input type="time" class="form-control" id="edit_time_end">
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_room" class="form-label">Room</label>
                            <input type="text" class="form-control" id="edit_room" placeholder="e.g., Room 101">
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_professor_initial" class="form-label">Professor's Initial</label>
                            <input type="text" class="form-control" id="edit_professor_initial" placeholder="e.g., JAB">
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_professor_name" class="form-label">Professor's Full Name</label>
                            <input type="text" class="form-control" id="edit_professor_name" placeholder="e.g., Dr. John A. Brown">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save me-1"></i>Save Schedule Details
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Manage Schedule Modal -->
    <div class="modal fade" id="manageScheduleModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="schedule_modal_title">Manage Section Schedule</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <h6 id="schedule_section_info"></h6>
                        <p class="text-muted small">
                            <i class="fas fa-info-circle me-1"></i>
                            Courses are automatically loaded from the curriculum. Click "Edit" to set schedule details (days, time, room, professor).
                        </p>
                    </div>
                    
                    <!-- Schedule Table -->
                    <div id="schedule-table-container">
                        <div class="table-responsive">
                            <table class="table table-bordered table-hover" id="schedule-table">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Code</th>
                                        <th>Subject Title</th>
                                        <th>Units/Hrs.</th>
                                        <th>M</th>
                                        <th>T</th>
                                        <th>W</th>
                                        <th>Th</th>
                                        <th>F</th>
                                        <th>Sat</th>
                                        <th>Sun</th>
                                        <th>Time</th>
                                        <th>Rm</th>
                                        <th>Professor</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody id="schedule-table-body">
                                    <tr>
                                        <td colspan="14" class="text-center">Loading schedule...</td>
                                    </tr>
                                </tbody>
                                <tfoot class="table-secondary">
                                    <tr>
                                        <td colspan="2" class="text-end"><strong>TOTAL UNITS/HRS.</strong></td>
                                        <td id="total-units"><strong>0</strong></td>
                                        <td colspan="11"></td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    
    <!-- Add/Edit FAQ Modal -->
    <div class="modal fade" id="faqModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="faqModalTitle">Add New FAQ</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="faqForm">
                    <div class="modal-body">
                        <input type="hidden" id="faq_id" name="id">
                        
                        <div class="mb-3">
                            <label for="faq_question" class="form-label">Question *</label>
                            <input type="text" class="form-control" id="faq_question" name="question" required maxlength="500">
                        </div>
                        
                        <div class="mb-3">
                            <label for="faq_answer" class="form-label">Answer *</label>
                            <textarea class="form-control" id="faq_answer" name="answer" required rows="5"></textarea>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="faq_category" class="form-label">Category</label>
                                <select class="form-select" id="faq_category" name="category">
                                    <option value="General">General</option>
                                    <option value="Enrollment">Enrollment</option>
                                    <option value="Requirements">Requirements</option>
                                    <option value="Schedule">Schedule</option>
                                    <option value="Sections">Sections</option>
                                    <option value="Account">Account</option>
                                    <option value="Documents">Documents</option>
                                </select>
                            </div>
                            
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Status</label>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="faq_is_active" name="is_active" checked>
                                    <label class="form-check-label" for="faq_is_active">Active</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="faq_keywords" class="form-label">Keywords</label>
                            <input type="text" class="form-control" id="faq_keywords" name="keywords" 
                                   placeholder="Enter comma-separated keywords (e.g., enroll, enrollment, register)">
                            <small class="text-muted">Keywords help students find this FAQ when searching</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save FAQ</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    </div>
    
    <!-- Add Program Modal -->
    <div class="modal fade" id="addProgramModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add New Program</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="add_program.php">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="program_code" class="form-label">Program Code *</label>
                            <input type="text" class="form-control" name="program_code" id="program_code" required 
                                   placeholder="e.g., BSE, BSIS, BTVTED" maxlength="20" 
                                   pattern="[A-Z0-9]+" title="Program code should contain only uppercase letters and numbers">
                            <small class="text-muted">Use uppercase letters and numbers only (e.g., BSE, BSIS)</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="program_name" class="form-label">Program Name *</label>
                            <input type="text" class="form-control" name="program_name" id="program_name" required 
                                   placeholder="e.g., Bachelor of Science in Entrepreneurship" maxlength="200">
                        </div>
                        
                        <div class="mb-3">
                            <label for="program_description" class="form-label">Description</label>
                            <textarea class="form-control" name="description" id="program_description" rows="3" 
                                      placeholder="Brief description of the program"></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label for="years_to_complete" class="form-label">Years to Complete *</label>
                            <input type="number" class="form-control" name="years_to_complete" id="years_to_complete" 
                                   value="4" min="1" max="10" required>
                            <small class="text-muted">Number of years to complete the program</small>
                        </div>
                        
                        <div class="mb-3">
                            <label for="program_status" class="form-label">Status *</label>
                            <select class="form-select" name="status" id="program_status" required>
                                <option value="active" selected>Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Program</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Add Curriculum Course Modal -->
    <div class="modal fade" id="addCurriculumModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Course to Curriculum</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="add_curriculum_course.php">
                    <div class="modal-body">
                        <input type="hidden" name="program_id" id="add_program_id">
                        
                        <div class="mb-3">
                            <label for="add_course_code" class="form-label">Course Code *</label>
                            <input type="text" class="form-control" name="course_code" id="add_course_code" required placeholder="e.g., BSE-C101">
                        </div>
                        
                        <div class="mb-3">
                            <label for="add_course_name" class="form-label">Course Name *</label>
                            <input type="text" class="form-control" name="course_name" id="add_course_name" required placeholder="e.g., Entrepreneurship Behavior">
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="add_units" class="form-label">Units *</label>
                                <input type="number" class="form-control" name="units" id="add_units" value="3" min="1" max="10" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Required Course?</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_required" id="add_is_required" value="1" checked>
                                    <label class="form-check-label" for="add_is_required">This course is required</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="add_year_level" class="form-label">Year Level *</label>
                                <select class="form-select" name="year_level" id="add_year_level" required>
                                    <option value="1st Year">1st Year</option>
                                    <option value="2nd Year">2nd Year</option>
                                    <option value="3rd Year">3rd Year</option>
                                    <option value="4th Year">4th Year</option>
                                    <option value="5th Year">5th Year</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="add_semester" class="form-label">Semester *</label>
                                <select class="form-select" name="semester" id="add_semester" required>
                                    <option value="First Semester">First Semester</option>
                                    <option value="Second Semester">Second Semester</option>
                                    <option value="Summer">Summer</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="add_pre_requisites" class="form-label">Pre-requisites</label>
                            <input type="text" class="form-control" name="pre_requisites" id="add_pre_requisites" placeholder="e.g., BSE-C100, GE-100">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Course</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Edit Enrolled Student Modal -->
    <div class="modal fade" id="editEnrolledStudentModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Enrolled Student - Manage Sections</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                    <div class="modal-body">
                    <input type="hidden" id="enrolled_user_id">
                        <h6 id="enrolled_student_name" class="mb-3"></h6>
                        
                    <!-- Current Sections -->
                    <div class="mb-4">
                        <h6 class="text-primary"><i class="fas fa-users me-2"></i>Current Section Assignments</h6>
                        <div id="edit_current_sections_list" class="mb-3">
                            <p class="text-muted">Loading...</p>
                        </div>
                        </div>
                        
                    <hr>
                    
                    <!-- Section Details Display -->
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <div class="card bg-light">
                                <div class="card-body">
                                    <h6 class="card-title"><i class="fas fa-graduation-cap me-2"></i>Current Details</h6>
                                    <p class="mb-1"><strong>Course/Program:</strong> <span id="display_course">-</span></p>
                                    <p class="mb-1"><strong>Year Level:</strong> <span id="display_year_level">-</span></p>
                                    <p class="mb-1"><strong>Semester:</strong> <span id="display_semester">-</span></p>
                                    <p class="mb-0"><strong>Academic Year:</strong> <span id="display_academic_year">-</span></p>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card">
                                <div class="card-body">
                                    <h6 class="card-title">Student Type</h6>
                                    <form method="POST" action="update_enrolled_student.php" id="studentTypeForm">
                                        <input type="hidden" name="user_id" id="student_type_user_id">
                                        <select class="form-select" name="student_type" id="student_type">
                                            <option value="Regular">Regular Student</option>
                                            <option value="Irregular">Irregular Student</option>
                                        </select>
                                        <button type="submit" class="btn btn-sm btn-primary mt-2">
                                            <i class="fas fa-save"></i> Update Type
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <!-- Change/Add Section -->
                        <div class="mb-3">
                        <h6 class="text-success"><i class="fas fa-exchange-alt me-2"></i>Change or Add Section</h6>
                        <p class="text-muted small">Select criteria to find and assign a different section</p>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="edit_filter_program" class="form-label small">Program:</label>
                                <select class="form-select form-select-sm" id="edit_filter_program" onchange="filterEditSections()">
                                    <option value="">Select Program</option>
                                    <?php foreach ($all_programs as $program): ?>
                                        <option value="<?php echo $program['id']; ?>"><?php echo htmlspecialchars($program['program_code'] . ' - ' . $program['program_name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_filter_year" class="form-label small">Year Level:</label>
                                <select class="form-select form-select-sm" id="edit_filter_year" onchange="filterEditSections()">
                                    <option value="">Select Year Level</option>
                                <option value="1st Year">1st Year</option>
                                <option value="2nd Year">2nd Year</option>
                                <option value="3rd Year">3rd Year</option>
                                <option value="4th Year">4th Year</option>
                                <option value="5th Year">5th Year</option>
                            </select>
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label for="edit_filter_semester" class="form-label small">Semester:</label>
                                <select class="form-select form-select-sm" id="edit_filter_semester" onchange="filterEditSections()">
                                    <option value="">Select Semester</option>
                                    <option value="First Semester">First Semester</option>
                                    <option value="Second Semester">Second Semester</option>
                                    <option value="Summer">Summer</option>
                            </select>
                            </div>
                            <div class="col-md-6">
                                <label for="edit_filter_type" class="form-label small">Section Type:</label>
                                <select class="form-select form-select-sm" id="edit_filter_type" onchange="filterEditSections()">
                                    <option value="">Any Type</option>
                                    <option value="Morning">Morning</option>
                                    <option value="Afternoon">Afternoon</option>
                                    <option value="Evening">Evening</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- Available Sections List -->
                        <div id="edit_sections_container" style="display: none;">
                            <label class="form-label small">Available Sections:</label>
                            <div id="edit_sections_list" style="max-height: 300px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 4px; padding: 10px;">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
                        </div>
                        
    <!-- View Section Students Modal -->
    <div class="modal fade" id="viewSectionStudentsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="section_students_title">Section Students</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="section_info" class="mb-3"></div>
                    
                    <div class="table-responsive" id="section_students_table_container">
                        <table class="table table-hover">
                            <thead class="table-dark">
                                <tr>
                                    <th>#</th>
                                    <th>Student ID</th>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Enrolled Date</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="section_students_list">
                                <tr>
                                    <td colspan="6" class="text-center">
                                        <div class="spinner-border text-primary" role="status">
                                            <span class="visually-hidden">Loading...</span>
                                        </div>
                                        <p class="mt-2">Loading students...</p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    
                    <div id="no_students_message" style="display: none;" class="text-center py-4">
                        <i class="fas fa-users fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No students enrolled in this section yet.</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
<!-- Applicant Information Modal -->
<div class="modal fade" id="applicantInfoModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="applicantInfoTitle"><i class="fas fa-user me-2"></i>Applicant Information</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="applicantInfoBody">
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">Loading applicant information...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Assign Section Modal -->
    <div class="modal fade" id="assignSectionModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Assign Section to Student</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="assign_student_id">
                    <h6 id="assign_student_name" class="mb-1 text-primary"></h6>
                    <p id="assign_preferred_program_notice" class="text-muted small mb-3" style="display: none;"></p>
                    
                    <!-- Current Sections -->
                    <div id="current_sections_container" class="mb-4" style="display: none;">
                        <h6 class="text-success"><i class="fas fa-check-circle me-2"></i>Currently Assigned Sections:</h6>
                        <div id="current_sections_list" class="mb-3"></div>
                    </div>
                    
                    <!-- Section Selection -->
                        <div class="mb-3">
                        <label class="form-label"><strong>Select Section to Assign:</strong></label>
                        
                        <!-- Filters Card -->
                        <div class="card mb-3">
                            <div class="card-header bg-light">
                                <h6 class="mb-0"><i class="fas fa-filter me-2"></i>Filter Sections</h6>
                            </div>
                            <div class="card-body">
                                <!-- First Row of Filters -->
                                <div class="row mb-3">
                                    <div class="col-md-3">
                                        <label class="form-label small">Program:</label>
                                        <select class="form-select form-select-sm" id="assign_filter_program" onchange="filterAssignSections()">
                                            <option value="">All Programs</option>
                                            <?php foreach ($all_programs as $program): ?>
                                                <option value="<?php echo $program['id']; ?>"><?php echo htmlspecialchars($program['program_code']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small">Year Level:</label>
                                        <select class="form-select form-select-sm" id="assign_filter_year" onchange="filterAssignSections()">
                                            <option value="">All Years</option>
                                            <option value="1st Year">1st Year</option>
                                            <option value="2nd Year">2nd Year</option>
                                            <option value="3rd Year">3rd Year</option>
                                            <option value="4th Year">4th Year</option>
                                            <option value="5th Year">5th Year</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small">Semester:</label>
                                        <select class="form-select form-select-sm" id="assign_filter_semester" onchange="filterAssignSections()">
                                            <option value="">All Semesters</option>
                                            <option value="First Semester">First Semester</option>
                                            <option value="Second Semester">Second Semester</option>
                                            <option value="Summer">Summer</option>
                                        </select>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small">Section Type:</label>
                                        <select class="form-select form-select-sm" id="assign_filter_type" onchange="filterAssignSections()">
                                            <option value="">All Types</option>
                                            <option value="Morning">Morning</option>
                                            <option value="Afternoon">Afternoon</option>
                                            <option value="Evening">Evening</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <!-- Second Row: Search and Clear -->
                                <div class="row">
                                    <div class="col-md-9">
                                        <label class="form-label small">Search:</label>
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text"><i class="fas fa-search"></i></span>
                                            <input type="text" class="form-control" id="assign_search" 
                                                   placeholder="Search by section name..." 
                                                   onkeyup="filterAssignSections()">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <label class="form-label small">&nbsp;</label>
                                        <button class="btn btn-sm btn-outline-secondary w-100" onclick="clearAssignFilters()">
                                            <i class="fas fa-times-circle me-1"></i>Clear Filters
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- Filter Summary -->
                                <div class="mt-2">
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle me-1"></i>
                                        Showing <strong id="assign_sections_count">0</strong> section(s)
                                    </small>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Sections List -->
                        <div id="sections_list" style="max-height: 400px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 4px; padding: 10px;">
                            <p class="text-muted text-center">Loading sections...</p>
                        </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <button class="menu-toggle" onclick="toggleSidebar()">
        <i class="fas fa-bars"></i>
    </button>
    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('active');
        }
        
        function toggleDropdown(element) {
            const navItem = element.closest('.nav-item');
            const isActive = navItem.classList.contains('active');
            
            // Close all other dropdowns
            document.querySelectorAll('.nav-item.has-dropdown').forEach(item => {
                if (item !== navItem) {
                    item.classList.remove('active');
                }
            });
            
            // Toggle current dropdown
            navItem.classList.toggle('active');
        }
        // ============================================
        // NAVIGATION FUNCTIONS - Defined first
        // ============================================
        
        // Define showSection on window object immediately to prevent "not defined" errors
        window.showSection = function(sectionId, event) {
            console.log('=== showSection called ===');
            console.log('Section ID:', sectionId);
            console.log('Event:', event);
            console.log('Current URL hash:', window.location.hash);
            
            if (event) {
                event.preventDefault();
                event.stopPropagation();
                event.stopImmediatePropagation();
            }
            
            // Validate section ID
            if (!sectionId || typeof sectionId !== 'string') {
                console.error('❌ Invalid section ID:', sectionId);
                return false;
            }
            
            // List all available sections for debugging
            const allSections = document.querySelectorAll('.content-section');
            console.log('📋 Available sections:', Array.from(allSections).map(s => s.id));
            
            const targetSection = document.getElementById(sectionId);
            if (!targetSection) {
                console.error('❌ Dashboard section not found:', sectionId);
                console.log('🔍 Searching for section with ID:', sectionId);
                // Try to show dashboard as fallback
                const dashboardSection = document.getElementById('dashboard');
                if (dashboardSection) {
                    console.log('⚠️ Falling back to dashboard');
                    sectionId = 'dashboard';
                } else {
                    console.error('❌ Dashboard section also not found!');
                    return false;
                }
                    } else {
                console.log('✅ Found target section:', sectionId);
            }
            
            // Hide all sections
            let hiddenCount = 0;
            document.querySelectorAll('.content-section').forEach(section => {
                if (section.style.display !== 'none') {
                    hiddenCount++;
                }
                section.style.display = 'none';
            });
            console.log(`👁️ Hid ${hiddenCount} visible sections`);
            
            // Update nav link active states - remove all first
            const navLinks = document.querySelectorAll('.nav-link');
            console.log('🔗 Found', navLinks.length, 'nav links');
            navLinks.forEach(link => {
                link.classList.remove('active');
            });
            
            // Add active class to the correct link
            let activeLinkFound = false;
            document.querySelectorAll('.nav-link[data-section]').forEach(link => {
                const linkSection = link.getAttribute('data-section');
                console.log('  - Nav link data-section:', linkSection, 'matches?', linkSection === sectionId);
                if (linkSection === sectionId) {
                    link.classList.add('active');
                    activeLinkFound = true;
                    console.log('✅ Activated nav link for:', sectionId);
                }
            });
            
            if (!activeLinkFound) {
                console.warn('⚠️ No nav link found with data-section="' + sectionId + '"');
            }
            
            // Show target section
            const finalSection = document.getElementById(sectionId);
            if (finalSection) {
                // Auto-refresh program cards when curriculum section is opened
                if (sectionId === 'curriculum') {
                    // Refresh program cards to show updated total units
                    setTimeout(() => {
                        refreshProgramCards(null);
                    }, 100);
                }
                finalSection.style.display = 'block';
                console.log('✅ Showing section:', sectionId);
                console.log('   Section display style:', finalSection.style.display);
                console.log('   Section computed display:', window.getComputedStyle(finalSection).display);
                    } else {
                console.error('❌ Final section not found after all checks!');
                return false;
            }
            
            // Update URL hash - check if we need to update it
            const currentHash = window.location.hash ? window.location.hash.substring(1) : '';
            if (currentHash !== sectionId) {
                console.log('🔄 Updating URL hash from', currentHash, 'to', sectionId);
                // Use replaceState to avoid adding to history stack unnecessarily
                if (window.history && window.history.replaceState) {
                    const baseUrl = window.location.pathname + window.location.search;
                    window.history.replaceState(null, null, baseUrl + '#' + sectionId);
                } else {
                    window.location.hash = sectionId;
                }
            }
            
            console.log('=== showSection completed ===');
            return false;
        }
        
        // Handle hash navigation on page load and hash changes
        function handleHashNavigation() {
            console.log('🔍 handleHashNavigation called');
            const hash = window.location.hash ? window.location.hash.substring(1).trim() : '';
            console.log('   Extracted hash:', hash);
            const validSections = ['dashboard', 'pending-students', 'enrolled-students', 'sections', 'documents', 'curriculum', 'curriculum-submissions', 'next-semester', 'chatbot'];
            
            let sectionToShow = 'dashboard'; // default
            
            if (hash && validSections.includes(hash)) {
                sectionToShow = hash;
                console.log('   ✅ Hash is valid, showing:', sectionToShow);
                } else {
                console.log('   ⚠️ Hash invalid or empty, defaulting to dashboard');
                if (hash) {
                    console.log('   Invalid hash value:', hash);
                }
            }
            
            console.log('   📞 Calling showSection with:', sectionToShow);
            showSection(sectionToShow);
        }

        function viewApplicantInfo(userId) {
            const modalElement = document.getElementById('applicantInfoModal');
            const modalTitle = document.getElementById('applicantInfoTitle');
            const modalBody = document.getElementById('applicantInfoBody');
            modalTitle.innerHTML = '<i class="fas fa-user me-2"></i>Applicant Information';
            modalBody.innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">Loading applicant information...</p>
                </div>
            `;
            const modalInstance = new bootstrap.Modal(modalElement);
            modalInstance.show();

            // Fetch applicant info, document checklist, uploaded documents, sections, and CORs
            Promise.all([
                fetch(`get_applicant_info.php?user_id=${userId}`).then(r => r.json()),
                fetch(`get_documents.php?user_id=${userId}`).then(r => r.json()).catch(() => ({})),
                fetch(`get_uploaded_documents.php?user_id=${userId}`).then(r => r.json()).catch(() => ({ success: false, documents: [] })),
                fetch(`get_student_section.php?user_id=${userId}`).then(r => r.json()).catch(() => ({ success: false, sections: [] })),
                fetch(`get_user_cors.php?user_id=${userId}`).then(r => r.json()).catch(() => ({ success: false, cors: [] }))
            ])
                .then(([applicantData, documentsData, uploadedDocsData, sectionsData, corsData]) => {
                    if (!applicantData.success) {
                        throw new Error(applicantData.message || 'Unable to load applicant information.');
                    }
                    const applicant = applicantData.applicant || {};
                    const workflow = applicantData.workflow || {};
                    const documents = documentsData || {};
                    const uploadedDocs = uploadedDocsData.documents || [];
                    const sections = sectionsData.success ? sectionsData.sections : [];
                    const cors = corsData.success ? corsData.cors : [];
                    const fullName = [applicant.first_name, applicant.middle_name, applicant.last_name].filter(Boolean).join(' ');
                    if (fullName) {
                        modalTitle.innerHTML = `<i class="fas fa-user me-2"></i>Applicant Information: ${fullName}`;
                    }

                    const formatValue = (value) => value ? value : '<span class="text-muted">Not provided</span>';
                    const formatDate = (value) => {
                        if (!value) return '<span class="text-muted">Not provided</span>';
                        const date = new Date(value);
                        if (Number.isNaN(date.getTime())) {
                            return formatValue(value);
                        }
                        return new Intl.DateTimeFormat('en-US', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
                    };

                    // Document mapping
                    const documentTypeMap = {
                        'id_pictures': '2x2 ID Pictures (4 pcs)',
                        'psa_birth_certificate': 'Birth Certificate (PSA)',
                        'barangay_certificate': 'Barangay Certificate of Residency',
                        'voters_id': 'Voter\'s ID or Registration Stub',
                        'high_school_diploma': 'High School Diploma',
                        'sf10_form': 'SF10 (Senior High School Permanent Record)',
                        'form_138': 'Form 137 (Report Card)',
                        'good_moral': 'Certificate of Good Moral Character',
                        'transfer_credentials': 'Transfer Credentials (if applicable)'
                    };

                    // Create a map of uploaded documents by type
                    const uploadedDocsMap = {};
                    uploadedDocs.forEach(doc => {
                        uploadedDocsMap[doc.document_type] = doc;
                    });

                    let documentsHtml = '<div class="list-group">';
                    Object.keys(documentTypeMap).forEach(docKey => {
                        const docLabel = documentTypeMap[docKey];
                        const uploadedDoc = uploadedDocsMap[docKey];
                        const isChecked = documents[docKey] == 1 || documents[docKey] === true || documents[docKey] === '1';
                        
                        let statusBadge = '';
                        let uploadInfo = '';
                        let viewButton = '';
                        
                        if (uploadedDoc) {
                            const uploadDate = formatDate(uploadedDoc.upload_date);
                            uploadInfo = `<small class="text-muted">Uploaded: ${uploadDate}</small>`;
                            
                            if (uploadedDoc.verification_status === 'verified') {
                                statusBadge = '<span class="badge bg-success">Verified</span>';
                            } else if (uploadedDoc.verification_status === 'rejected') {
                                statusBadge = '<span class="badge bg-danger">Rejected</span>';
                            } else {
                                statusBadge = '<span class="badge bg-warning text-dark">Pending Review</span>';
                            }
                            
                            if (uploadedDoc.file_path) {
                                viewButton = `<button class="btn btn-sm btn-primary" onclick="window.open('../${uploadedDoc.file_path}', '_blank')">
                                    <i class="fas fa-eye me-1"></i>View
                                </button>`;
                            }
                        } else {
                            uploadInfo = '<small class="text-muted">Student has not uploaded this document yet.</small>';
                            statusBadge = '<span class="badge bg-secondary">Not uploaded</span>';
                        }
                        
                        documentsHtml += `
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1">${docLabel}</h6>
                                        ${uploadInfo}
                            </div>
                                    <div class="d-flex align-items-center gap-2">
                                        ${statusBadge}
                                        ${viewButton}
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    documentsHtml += '</div>';

                    modalBody.innerHTML = `
                        <div class="row g-4">
                            <div class="col-md-6">
                                <h6 class="text-primary"><i class="fas fa-id-card me-2"></i>Personal Details</h6>
                                <ul class="list-unstyled small mb-0">
                                    <li><strong>Student ID:</strong> ${formatValue(applicant.student_id)}</li>
                                    <li><strong>Name:</strong> ${formatValue(fullName || null)}</li>
                                    <li><strong>Status:</strong> ${formatValue(applicant.status)}</li>
                                    <li><strong>Enrollment Status:</strong> ${formatValue(applicant.enrollment_status)}</li>
                                    <li><strong>Preferred Program:</strong> ${formatValue(applicant.preferred_program)}</li>
                                    <li><strong>Registered:</strong> ${formatDate(applicant.created_at)}</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-primary"><i class="fas fa-address-book me-2"></i>Contact</h6>
                                <ul class="list-unstyled small mb-0">
                                    <li><strong>Email:</strong> ${formatValue(applicant.email)}</li>
                                    <li><strong>Phone:</strong> ${formatValue(applicant.phone)}</li>
                                    <li><strong>Contact Number:</strong> ${formatValue(applicant.contact_number)}</li>
                                </ul>
                                <h6 class="text-primary mt-3"><i class="fas fa-map-marker-alt me-2"></i>Address</h6>
                                <ul class="list-unstyled small mb-0">
                                    <li><strong>Address:</strong> ${formatValue(applicant.address)}</li>
                                    <li><strong>Permanent Address:</strong> ${formatValue(applicant.permanent_address)}</li>
                                    <li><strong>Municipality:</strong> ${formatValue(applicant.municipality_city)}</li>
                                    <li><strong>Barangay:</strong> ${formatValue(applicant.barangay)}</li>
                                </ul>
                            </div>
                        </div>
                        <div class="row g-4 mt-1">
                            <div class="col-md-6">
                                <h6 class="text-primary"><i class="fas fa-users me-2"></i>Family / Guardian</h6>
                                <ul class="list-unstyled small mb-0">
                                    <li><strong>Guardian:</strong> ${formatValue(applicant.guardian_name)}</li>
                                    <li><strong>Father:</strong> ${formatValue(applicant.father_name)}</li>
                                    <li><strong>Mother:</strong> ${formatValue(applicant.mother_maiden_name)}</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-primary"><i class="fas fa-tasks me-2"></i>Workflow Status</h6>
                                <ul class="list-unstyled small mb-0">
                                    <li><strong>Admission Status:</strong> ${formatValue(workflow.admission_status)}</li>
                                    <li><strong>Passed to Registrar:</strong> ${workflow.passed_to_registrar ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-warning text-dark">No</span>'}</li>
                                    <li><strong>Passed On:</strong> ${formatDate(workflow.passed_to_registrar_at)}</li>
                                </ul>
                            </div>
                        </div>
                        <div class="row g-4 mt-1">
                            <div class="col-12">
                                <h6 class="text-primary"><i class="fas fa-file-alt me-2"></i>Document Checklist</h6>
                                ${documentsHtml}
                            </div>
                        </div>
                        <div class="row g-4 mt-3">
                            <div class="col-12">
                                <hr>
                                <h6 class="text-primary mb-3"><i class="fas fa-users-cog me-2"></i>Assigned Sections</h6>
                                ${sections.length > 0 ? sections.map(section => {
                                    const enrolledDate = section.enrolled_date ? new Date(section.enrolled_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
                                    return `
                                        <div class="alert alert-success mb-2">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <strong>${section.section_name || 'N/A'}</strong><br>
                                                    <small>${section.program_code || 'N/A'} - ${section.program_name || 'N/A'} | ${section.year_level || 'N/A'} | ${section.semester || 'N/A'}</small><br>
                                                    <small class="text-muted">Academic Year: ${section.academic_year || 'N/A'} | Enrolled: ${enrolledDate}</small>
                                                </div>
                                            </div>
                                        </div>
                                    `;
                                }).join('') : '<div class="alert alert-info mb-0"><i class="fas fa-info-circle me-2"></i>No sections assigned yet.</div>'}
                            </div>
                        </div>
                        <div class="row g-4 mt-3">
                            <div class="col-12">
                                <hr>
                                <h6 class="text-primary mb-3"><i class="fas fa-file-alt me-2"></i>Certificate of Registration (COR)</h6>
                                ${cors.length > 0 ? cors.map(cor => {
                                    const formatDate = (dateStr) => {
                                        if (!dateStr) return 'N/A';
                                        try {
                                            const date = new Date(dateStr);
                                            return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
                                        } catch (e) {
                                            return dateStr;
                                        }
                                    };
                                    const subjects = Array.isArray(cor.subjects) ? cor.subjects : [];
                                    const totalUnits = cor.total_units || 0;
                                    return `
                                        <div class="card mb-3">
                                            <div class="card-header bg-light">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <strong>${cor.program_code || 'N/A'} - ${cor.program_name || 'N/A'}</strong><br>
                                                        <small class="text-muted">${cor.section_name || 'N/A'} | ${cor.year_level || 'N/A'} | ${cor.semester || 'N/A'} | ${cor.academic_year || 'N/A'}</small>
                                                    </div>
                                                    <small class="text-muted">Created: ${formatDate(cor.created_at)}</small>
                                                </div>
                                            </div>
                                            <div class="card-body">
                                                <div class="row mb-2">
                                                    <div class="col-md-6">
                                                        <strong>Student:</strong> ${cor.student_last_name || ''}, ${cor.student_first_name || ''} ${cor.student_middle_name || ''}<br>
                                                        <strong>Student Number:</strong> ${cor.student_number || 'N/A'}<br>
                                                        <strong>Registration Date:</strong> ${formatDate(cor.registration_date)}
                                                    </div>
                                                    <div class="col-md-6">
                                                        <strong>Total Units:</strong> ${totalUnits}<br>
                                                        <strong>Number of Subjects:</strong> ${subjects.length}<br>
                                                        <strong>Prepared By:</strong> ${cor.prepared_by || 'N/A'}
                                                    </div>
                                                </div>
                                                ${subjects.length > 0 ? `
                                                    <div class="table-responsive mt-2">
                                                        <table class="table table-sm table-bordered mb-0">
                                                            <thead class="table-light">
                                                                <tr>
                                                                    <th>Course Code</th>
                                                                    <th>Course Name</th>
                                                                    <th>Units</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                ${subjects.map(subject => `
                                                                    <tr>
                                                                        <td>${subject.course_code || 'N/A'}</td>
                                                                        <td>${subject.course_name || 'N/A'}</td>
                                                                        <td>${subject.units || '0'}</td>
                                                                    </tr>
                                                                `).join('')}
                                                            </tbody>
                                                            <tfoot class="table-light">
                                                                <tr>
                                                                    <th colspan="2" class="text-end">Total Units:</th>
                                                                    <th>${totalUnits}</th>
                                                                </tr>
                                                            </tfoot>
                                                        </table>
                                                    </div>
                                                ` : '<p class="text-muted mb-0">No subjects listed.</p>'}
                                            </div>
                                        </div>
                                    `;
                                }).join('') : '<div class="alert alert-info mb-0"><i class="fas fa-info-circle me-2"></i>No Certificate of Registration has been created yet.</div>'}
                            </div>
                        </div>
                    `;
                })
                .catch(error => {
                    modalBody.innerHTML = `
                        <div class="alert alert-danger mb-0">
                            <i class="fas fa-exclamation-circle me-2"></i>${error.message}
                        </div>
                    `;
                });
        }



        // Handle clickable rows for pending students
        document.addEventListener('DOMContentLoaded', function() {
            const clickableRows = document.querySelectorAll('.clickable-row');
            
            clickableRows.forEach(row => {
                row.addEventListener('click', function(e) {
                    // Don't trigger if clicking on a button or form element
                    if (e.target.tagName === 'BUTTON' || e.target.tagName === 'SELECT' || e.target.closest('button') || e.target.closest('form')) {
                        return;
                    }
                    
                    const studentId = this.getAttribute('data-student-id');
                    const actionRow = document.getElementById('actions-' + studentId);
                    const expandIcon = this.querySelector('.expand-icon');
                    
                    if (actionRow.style.display === 'none') {
                        // Close all other open rows
                        document.querySelectorAll('.action-row').forEach(ar => {
                            ar.style.display = 'none';
                        });
                        document.querySelectorAll('.expand-icon').forEach(icon => {
                            icon.classList.remove('fa-chevron-up');
                            icon.classList.add('fa-chevron-down');
                        });
                        
                        // Open this row
                        actionRow.style.display = 'table-row';
                        expandIcon.classList.remove('fa-chevron-down');
                        expandIcon.classList.add('fa-chevron-up');
                    } else {
                        // Close this row
                        actionRow.style.display = 'none';
                        expandIcon.classList.remove('fa-chevron-up');
                        expandIcon.classList.add('fa-chevron-down');
                    }
                });
            });
            
            // Handle action buttons using event delegation
            document.addEventListener('click', function(e) {
                const button = e.target.closest('button[data-action]');
                if (!button) return;
                
                const action = button.getAttribute('data-action');
                const userId = parseInt(button.getAttribute('data-user-id')) || 0;
                
                if (!userId) return;
                
                try {
                    switch(action) {
                        case 'view-applicant':
                            if (typeof viewApplicantInfo === 'function') {
                                viewApplicantInfo(userId);
                            }
                            break;
                        case 'view-enrolled-student':
                            const studentName = button.getAttribute('data-student-name') || 'Student';
                            if (typeof viewEnrolledStudent === 'function') {
                                viewEnrolledStudent(userId, studentName);
                            }
                            break;
                        case 'assign-section':
                            const assignStudentName = button.getAttribute('data-student-name') || '';
                            const preferredProgramId = button.getAttribute('data-preferred-program-id');
                            const preferredProgramName = button.getAttribute('data-preferred-program-name') || '';
                            const programId = preferredProgramId && preferredProgramId !== 'null' ? parseInt(preferredProgramId) : null;
                            if (typeof assignSection === 'function') {
                                assignSection(userId, assignStudentName, programId, preferredProgramName);
                            }
                            break;
                    }
                } catch (error) {
                    console.error('Error handling action button:', error);
                }
            });
        });
        
        // Test function - can be called from browser console
        window.testNavigation = function() {
            console.log('🧪 Testing Navigation System');
            console.log('==========================');
            
            // Test 1: Check if sections exist
            console.log('\n1️⃣ Checking if sections exist:');
            const sections = ['dashboard', 'pending-students', 'enrolled-students', 'sections', 'documents', 'curriculum', 'curriculum-submissions', 'next-semester', 'chatbot'];
            sections.forEach(id => {
                const el = document.getElementById(id);
                console.log(`  ${el ? '✅' : '❌'} ${id}:`, el ? 'Found' : 'NOT FOUND');
            });
            
            // Test 2: Check nav links
            console.log('\n2️⃣ Checking nav links:');
            const navLinks = document.querySelectorAll('a[data-section]');
            console.log('  Found', navLinks.length, 'nav links with data-section');
            navLinks.forEach(link => {
                const section = link.getAttribute('data-section');
                const hasOnclick = link.hasAttribute('onclick');
                console.log(`  - ${section}: onclick=${hasOnclick}, href=${link.getAttribute('href')}`);
            });
            
            // Test 3: Try to show pending-students
            console.log('\n3️⃣ Testing showSection("pending-students"):');
            showSection('pending-students');
            
            // Test 4: Check current state
            console.log('\n4️⃣ Current state:');
            const pendingSection = document.getElementById('pending-students');
            if (pendingSection) {
                console.log('  pending-students display:', pendingSection.style.display);
                console.log('  pending-students computed:', window.getComputedStyle(pendingSection).display);
            }
            
            const dashboardSection = document.getElementById('dashboard');
            if (dashboardSection) {
                console.log('  dashboard display:', dashboardSection.style.display);
                console.log('  dashboard computed:', window.getComputedStyle(dashboardSection).display);
            }
            
            console.log('\n✅ Test complete! Check results above.');
        };
        
        // Add event delegation for navigation links and buttons
        document.addEventListener('click', function(e) {
            console.log('🖱️ Click detected on:', e.target.tagName, e.target.className);
            
            // Allow links marked as data-no-intercept to behave normally
            const noInterceptLink = e.target.closest('a[data-no-intercept]');
            if (noInterceptLink) {
                console.log('➡️ No-intercept link clicked:', noInterceptLink.href);
                return true;
            }
            
            // Check if clicked element or parent is a nav link with data-section
            const navLink = e.target.closest('a[data-section]');
            if (navLink && navLink.hasAttribute('data-section')) {
                const sectionId = navLink.getAttribute('data-section');
                console.log('🔗 Nav link clicked! Section:', sectionId);
                if (sectionId) {
                    e.preventDefault();
                    e.stopPropagation();
                    console.log('📞 Calling showSection with:', sectionId);
                    showSection(sectionId, e);
                    return false;
                }
            }
            
            // Check if clicked element is a button with data-action="navigate"
            const navButton = e.target.closest('button[data-action="navigate"]');
            if (navButton && navButton.hasAttribute('data-section')) {
                const sectionId = navButton.getAttribute('data-section');
                console.log('🔘 Navigate button clicked! Section:', sectionId);
                if (sectionId) {
                    e.preventDefault();
                    e.stopPropagation();
                    console.log('📞 Calling showSection with:', sectionId);
                    showSection(sectionId, e);
                    return false;
                }
            }
            
            // Check if clicked element is an assign section button (from assignSectionModal)
            const assignBtn = e.target.closest('.assign-section-btn');
            if (assignBtn) {
                e.preventDefault();
                e.stopPropagation();
                const sectionId = parseInt(assignBtn.getAttribute('data-section-id'));
                const sectionName = assignBtn.getAttribute('data-section-name');
                if (sectionId && typeof performSectionAssignment === 'function') {
                    performSectionAssignment(sectionId, sectionName);
                } else {
                    console.error('performSectionAssignment not available or invalid section ID');
                }
                return false;
            }
            
            // Handle delete program button click (check FIRST before card click)
            const deleteBtn = e.target.closest('button.btn-danger[onclick*="deleteProgram"]');
            if (deleteBtn) {
                console.log('Delete button clicked, executing deleteProgram');
                // Extract program info from button's onclick attribute or data attributes
                const programId = deleteBtn.getAttribute('data-program-id') || 
                                 parseInt(deleteBtn.getAttribute('onclick')?.match(/\d+/)?.[0] || '0');
                const programCode = deleteBtn.getAttribute('data-program-code') || '';
                const programName = deleteBtn.getAttribute('data-program-name') || '';
                
                if (programId && typeof deleteProgram === 'function') {
                    e.preventDefault();
                    e.stopPropagation();
                    deleteProgram(e, programId, programCode, programName);
                    return false;
                }
            }
            
            // Curriculum program card click
            const curriculumCard = e.target.closest('.curriculum-program-card');
            if (curriculumCard) {
                // Don't prevent default if clicking inside a button
                const clickedButton = e.target.closest('button');
                if (clickedButton) {
                    return; // Let button handle its own click
                }
                
                e.preventDefault();
                e.stopPropagation();
                const programId = parseInt(curriculumCard.getAttribute('data-program-id'));
                const programCode = curriculumCard.getAttribute('data-program-code');
                if (programId && programCode && typeof viewCurriculum === 'function') {
                    viewCurriculum(programId, programCode);
                }
                return false;
            }
            
            // Show add curriculum modal button
            if (e.target.closest('#show-add-curriculum-btn')) {
                e.preventDefault();
                e.stopPropagation();
                if (typeof showAddCurriculumModal === 'function') {
                    showAddCurriculumModal();
                }
                return false;
            }
            
            // View curriculum submission button
            const viewSubmissionBtn = e.target.closest('.view-submission-btn');
            if (viewSubmissionBtn) {
                e.preventDefault();
                e.stopPropagation();
                const submissionId = parseInt(viewSubmissionBtn.getAttribute('data-submission-id'));
                if (submissionId && typeof viewCurriculumSubmission === 'function') {
                    viewCurriculumSubmission(submissionId);
                }
                return false;
            }
            
            // Approve submission button
            const approveBtn = e.target.closest('.approve-submission-btn');
            if (approveBtn) {
                e.preventDefault();
                e.stopPropagation();
                const submissionId = parseInt(approveBtn.getAttribute('data-submission-id'));
                if (submissionId && typeof approveSubmission === 'function') {
                    approveSubmission(submissionId);
                }
                return false;
            }
            
            // Reject submission button
            const rejectBtn = e.target.closest('.reject-submission-btn');
            if (rejectBtn) {
                e.preventDefault();
                e.stopPropagation();
                const submissionId = parseInt(rejectBtn.getAttribute('data-submission-id'));
                if (submissionId && typeof rejectSubmission === 'function') {
                    rejectSubmission(submissionId);
                }
                return false;
            }
            
            // Bulk approve submissions button
            if (e.target.closest('#bulk-approve-submissions-btn')) {
                e.preventDefault();
                e.stopPropagation();
                if (typeof bulkApproveSubmissions === 'function') {
                    bulkApproveSubmissions();
                }
                return false;
            }
            
            // Clear selection button
            if (e.target.closest('#clear-selection-btn')) {
                e.preventDefault();
                e.stopPropagation();
                if (typeof clearSelection === 'function') {
                    clearSelection();
                }
                return false;
            }
            
            // Manage Schedule button
            const manageScheduleBtn = e.target.closest('.manage-schedule-btn');
            if (manageScheduleBtn) {
                e.preventDefault();
                e.stopPropagation();
                const sectionId = parseInt(manageScheduleBtn.getAttribute('data-section-id'));
                const sectionName = manageScheduleBtn.getAttribute('data-section-name');
                const programId = parseInt(manageScheduleBtn.getAttribute('data-program-id'));
                const yearLevel = manageScheduleBtn.getAttribute('data-year-level');
                const semester = manageScheduleBtn.getAttribute('data-semester');
                if (sectionId && sectionName && programId && yearLevel && semester && typeof manageSchedule === 'function') {
                    manageSchedule(sectionId, sectionName, programId, yearLevel, semester);
                }
                return false;
            }
            
            // Edit Section button
            const editSectionBtn = e.target.closest('.edit-section-btn');
            if (editSectionBtn) {
                e.preventDefault();
                e.stopPropagation();
                const sectionId = parseInt(editSectionBtn.getAttribute('data-section-id'));
                const sectionName = editSectionBtn.getAttribute('data-section-name');
                const maxCapacity = parseInt(editSectionBtn.getAttribute('data-max-capacity'));
                const status = editSectionBtn.getAttribute('data-status');
                if (sectionId && sectionName && maxCapacity && status && typeof editSection === 'function') {
                    editSection(sectionId, sectionName, maxCapacity, status);
                }
                return false;
            }
            
            // View Section Students button
            const viewSectionStudentsBtn = e.target.closest('.view-section-students-btn');
            if (viewSectionStudentsBtn) {
                e.preventDefault();
                e.stopPropagation();
                const sectionId = parseInt(viewSectionStudentsBtn.getAttribute('data-section-id'));
                const sectionName = viewSectionStudentsBtn.getAttribute('data-section-name');
                if (sectionId && sectionName && typeof viewSectionStudents === 'function') {
                    viewSectionStudents(sectionId, sectionName);
                }
                return false;
            }
        }, true); // Use capture phase to catch event early
        
        // Handle checkbox changes for curriculum submissions
        document.addEventListener('change', function(e) {
            // Select all checkbox
            if (e.target.id === 'select-all') {
                if (typeof toggleSelectAll === 'function') {
                    toggleSelectAll();
                }
                        return;
                    }
                    
            // Individual submission checkbox
            if (e.target.classList.contains('submission-checkbox')) {
                if (typeof updateBulkActions === 'function') {
                    updateBulkActions();
                }
                return;
            }
        });
        
        // Initialize when DOM is ready
        function initNavigation() {
            console.log('🚀 Initializing navigation...');
            console.log('   Document ready state:', document.readyState);
            console.log('   Current hash:', window.location.hash);
            
            // Wait a bit to ensure all DOM is ready
            setTimeout(function() {
                console.log('⏰ Timeout completed, calling handleHashNavigation');
                handleHashNavigation();
            }, 50);
        }
        
        console.log('📝 Script loaded, readyState:', document.readyState);
        if (document.readyState === 'loading') {
            console.log('⏳ DOM still loading, waiting for DOMContentLoaded');
            document.addEventListener('DOMContentLoaded', function() {
                console.log('✅ DOMContentLoaded fired');
                initNavigation();
            });
        } else {
            // DOM is already ready
            console.log('✅ DOM already ready, initializing immediately');
            initNavigation();
        }
        
        // Handle browser back/forward buttons
        window.addEventListener('hashchange', function() {
            console.log('🔄 Hash changed to:', window.location.hash);
            handleHashNavigation();
        });
        
        let currentProgramId = null;
        let currentProgramCode = null;
        
        function viewCurriculum(programId, programCode) {
            currentProgramId = programId;
            currentProgramCode = programCode;
            
            document.getElementById('curriculum-title').textContent = programCode + ' Curriculum';
            document.getElementById('curriculum-display').style.display = 'block';
            
            // Update program card total units badge (refresh from server)
            updateProgramCardBadge(programId);
            
            // Fetch curriculum data
            fetch('get_curriculum.php?program_id=' + programId)
                .then(response => response.json())
                .then(data => {
                    let html = '';
                    let currentYear = '';
                    let currentSemester = '';
                    let semesterUnits = 0;
                    
                    data.courses.forEach(function(course, index) {
                        // Check if we need a new year heading
                        if (course.year_level !== currentYear) {
                            if (currentYear !== '') {
                                html += '</tbody></table></div>';
                            }
                            currentYear = course.year_level;
                            currentSemester = '';
                            html += '<h4 class="mt-4 mb-3 text-primary"><i class="fas fa-calendar-alt me-2"></i>' + currentYear + '</h4>';
                        }
                        
                        // Check if we need a new semester table
                        if (course.semester !== currentSemester) {
                            if (currentSemester !== '') {
                                html += '<tr class="table-dark"><td colspan="2" class="text-end"><strong>Semester Total:</strong></td><td><strong>' + semesterUnits + ' units</strong></td><td></td></tr>';
                                html += '</tbody></table></div>';
                            }
                            currentSemester = course.semester;
                            semesterUnits = 0;
                            
                            html += '<div class="card mb-3">';
                            html += '<div class="card-header bg-success text-white">';
                            html += '<h5 class="mb-0">' + currentSemester + '</h5>';
                            html += '</div>';
                            html += '<div class="card-body p-0">';
                            html += '<table class="table table-hover mb-0">';
                            html += '<thead><tr><th>Course Code</th><th>Course Name</th><th>Units</th><th>Actions</th></tr></thead>';
                            html += '<tbody>';
                        }
                        
                        semesterUnits += parseInt(course.units);
                        
                        html += '<tr>';
                        html += '<td><strong>' + course.course_code + '</strong></td>';
                        html += '<td>' + course.course_name + '</td>';
                        html += '<td>' + course.units + '</td>';
                        html += '<td>';
                        html += '<button class="btn btn-sm btn-warning" onclick="editCurriculumCourse(' + course.id + ')"><i class="fas fa-edit"></i></button> ';
                        html += '<button class="btn btn-sm btn-danger" onclick="deleteCurriculumCourse(' + course.id + ', \'' + programCode + '\')"><i class="fas fa-trash"></i></button>';
                        html += '</td>';
                        html += '</tr>';
                    });
                    
                    // Close last semester
                    if (currentSemester !== '') {
                        html += '<tr class="table-dark"><td colspan="2" class="text-end"><strong>Semester Total:</strong></td><td><strong>' + semesterUnits + ' units</strong></td><td></td></tr>';
                        html += '</tbody></table></div></div>';
                    }
                    
                    // Add grand total
                    html += '<div class="alert alert-info mt-4">';
                    html += '<h5><i class="fas fa-calculator me-2"></i>Program Total: ' + data.program.total_units + ' units</h5>';
                    html += '</div>';
                    
                    document.getElementById('curriculum-content').innerHTML = html;
                    
                    // Update the program card badge with fresh total units
                    updateProgramCardBadge(programId, data.program.total_units);
                })
                .catch(error => {
                    alert('Error loading curriculum: ' + error);
                });
        }
        
        function showAddCurriculumModal() {
            if (!currentProgramId) {
                alert('Please select a program first');
                return;
            }
            
            // Set program ID in the form
            document.getElementById('add_program_id').value = currentProgramId;
            
            // Show modal
            var modal = new bootstrap.Modal(document.getElementById('addCurriculumModal'));
            modal.show();
        }
        
        function editCurriculumCourse(courseId) {
            alert('Edit curriculum course ID: ' + courseId);
        }
        
        function updateProgramCardBadge(programId, totalUnits = null) {
            // If totalUnits is provided, use it directly, otherwise fetch from server
            if (totalUnits !== null) {
                const programCard = document.querySelector('[data-program-id="' + programId + '"]');
                if (programCard) {
                    const badge = programCard.querySelector('.badge.bg-info');
                    if (badge) {
                        badge.textContent = totalUnits + ' units';
                    }
                }
            } else {
                // Fetch fresh program data from server
                fetch('get_curriculum.php?program_id=' + programId)
                    .then(response => response.json())
                    .then(data => {
                        const programCard = document.querySelector('[data-program-id="' + programId + '"]');
                        if (programCard && data.program) {
                            const badge = programCard.querySelector('.badge.bg-info');
                            if (badge) {
                                badge.textContent = data.program.total_units + ' units';
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error updating program badge:', error);
                    });
            }
        }
        
        function refreshProgramCards(event) {
            // Show loading state only if called from button click
            const refreshBtn = event?.target || document.querySelector('button[onclick*="refreshProgramCards"]');
            const originalHTML = refreshBtn ? refreshBtn.innerHTML : '';
            const isManualRefresh = !!event;
            
            if (isManualRefresh && refreshBtn) {
                refreshBtn.disabled = true;
                refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Refreshing...';
            }
            
            // Fetch fresh program data from server
            fetch('get_programs.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.programs) {
                        // Update all program card badges
                        data.programs.forEach(program => {
                            const programCard = document.querySelector('[data-program-id="' + program.id + '"]');
                            if (programCard) {
                                const badge = programCard.querySelector('.badge.bg-info');
                                if (badge) {
                                    badge.textContent = program.total_units + ' units';
                                }
                            }
                        });
                        
                        // If a program is currently being viewed, refresh its curriculum view too
                        if (currentProgramId) {
                            viewCurriculum(currentProgramId, currentProgramCode);
                        }
                    }
                    if (isManualRefresh && refreshBtn) {
                        refreshBtn.innerHTML = originalHTML;
                        refreshBtn.disabled = false;
                    }
                })
                .catch(error => {
                    console.error('Error refreshing programs:', error);
                    if (isManualRefresh) {
                        alert('Error refreshing program data. Please refresh the page.');
                    }
                    if (isManualRefresh && refreshBtn) {
                        refreshBtn.innerHTML = originalHTML;
                        refreshBtn.disabled = false;
                    }
                });
        }
        
        function deleteCurriculumCourse(courseId, programCode) {
            if (confirm('Are you sure you want to delete this course from the curriculum?')) {
                fetch('delete_curriculum_course.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'course_id=' + courseId + '&session_id=<?php echo CURRENT_SESSION_ID; ?>'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert(data.message);
                            // Refresh the curriculum view and program card
                        if (currentProgramId) {
                            viewCurriculum(currentProgramId, currentProgramCode);
                        } else {
                            // Reload the page to show updated curriculum
                            location.reload();
                        }
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Error deleting course: ' + error);
                });
            }
        }
        
        // Make deleteProgram globally accessible
        window.deleteProgram = function(event, programId, programCode, programName) {
            console.log('deleteProgram called', { event, programId, programCode, programName });
            
            // Prevent card click event
            if (event) {
                event.stopPropagation();
                event.preventDefault();
            }
            
            // Show confirmation dialog with warning
            const confirmMessage = `Are you sure you want to delete the program "${programCode} - ${programName}"?\n\n` +
                                 `This will permanently delete:\n` +
                                 `- All curriculum courses for this program\n` +
                                 `- All sections for this program\n` +
                                 `- All program head assignments\n` +
                                 `- All curriculum submissions\n\n` +
                                 `WARNING: This action cannot be undone!\n\n` +
                                 `Note: Programs with active student enrollments cannot be deleted.`;
            
            if (!confirm(confirmMessage)) {
                return;
            }
            
            // Show loading state
            const deleteBtn = event?.target?.closest('button') || event?.target;
            const originalHTML = deleteBtn.innerHTML;
            deleteBtn.disabled = true;
            deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
            
            console.log('Sending delete request for program:', programId);
            
            // Send delete request
            const formData = new FormData();
            formData.append('program_id', programId);
            
            fetch('delete_program.php', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                console.log('Delete response status:', response.status);
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                console.log('Delete response data:', data);
                if (data.success) {
                    // Show success message
                    alert(data.message);
                    
                    // Remove the program card from the DOM
                    const programCard = document.querySelector(`[data-program-id="${programId}"]`);
                    if (programCard) {
                        const cardContainer = programCard.closest('.col-md-4');
                        if (cardContainer) {
                            cardContainer.style.transition = 'opacity 0.3s';
                            cardContainer.style.opacity = '0';
                            setTimeout(() => {
                                cardContainer.remove();
                                
                                // If no programs left, show message
                                const remainingCards = document.querySelectorAll('.curriculum-program-card');
                                if (remainingCards.length === 0) {
                                    const cardBody = document.querySelector('.card-body .row');
                                    if (cardBody) {
                                        cardBody.innerHTML = '<div class="col-12 text-center py-5"><i class="fas fa-inbox fa-3x text-muted mb-3"></i><h5 class="text-muted">No programs found</h5><p class="text-muted">Click "Add New Program" to create one.</p></div>';
                                    }
                                }
                            }, 300);
                        }
                    }
                    
                    // If the deleted program was being viewed, hide the curriculum display
                    if (currentProgramId == programId) {
                        document.getElementById('curriculum-display').style.display = 'none';
                        currentProgramId = null;
                        currentProgramCode = null;
                    }
                } else {
                    // Show error message with details
                    let errorMsg = 'Error: ' + data.message;
                    if (data.details) {
                        if (data.details.has_enrollments) {
                            errorMsg += '\n\nThis program has active student enrollments and cannot be deleted.';
                            errorMsg += '\nPlease remove all student enrollments first.';
                        }
                        if (data.details.has_sections) {
                            errorMsg += '\n\nThis program has sections that need to be removed first.';
                        }
                        if (data.details.has_program_heads) {
                            errorMsg += '\n\nThis program has program heads assigned. Please reassign them first.';
                        }
                    }
                    alert(errorMsg);
                    deleteBtn.disabled = false;
                    deleteBtn.innerHTML = originalHTML;
                }
            })
            .catch(error => {
                console.error('Error deleting program:', error);
                alert('Error deleting program: ' + error.message);
                if (deleteBtn) {
                    deleteBtn.disabled = false;
                    deleteBtn.innerHTML = originalHTML;
                }
            });
        };
        
        function editSection(sectionId, sectionName, maxCapacity, status) {
            document.getElementById('edit_section_id').value = sectionId;
            document.getElementById('edit_section_name').value = sectionName;
            document.getElementById('edit_max_capacity').value = maxCapacity;
            document.getElementById('edit_status').value = status;
            
            var modal = new bootstrap.Modal(document.getElementById('editSectionModal'));
            modal.show();
        }
        
        function viewSectionStudents(sectionId, sectionName) {
            // Store current section info for removal functionality
            currentViewingSectionId = sectionId;
            currentViewingSectionName = sectionName;
            
            // Set modal title
            document.getElementById('section_students_title').textContent = 'Students in ' + sectionName;
            
            // Reset loading state
            document.getElementById('section_students_list').innerHTML = `
                <tr>
                    <td colspan="6" class="text-center">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2">Loading students...</p>
                    </td>
                </tr>
            `;
            document.getElementById('section_students_table_container').style.display = 'block';
            document.getElementById('no_students_message').style.display = 'none';
            
            // Show modal
            var modal = new bootstrap.Modal(document.getElementById('viewSectionStudentsModal'));
            modal.show();
            
            // Fetch students
            fetch('get_section_students.php?section_id=' + sectionId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displaySectionInfo(data.section);
                        displaySectionStudents(data.students);
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('section_students_list').innerHTML = `
                        <tr>
                            <td colspan="6" class="text-center text-danger">
                                <i class="fas fa-exclamation-triangle"></i> Error loading students
                            </td>
                        </tr>
                    `;
                });
        }
        
        function displaySectionInfo(section) {
            const infoHtml = `
                <div class="card bg-light">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p class="mb-1"><strong>Program:</strong> ${section.program_code} - ${section.program_name}</p>
                                <p class="mb-1"><strong>Year Level:</strong> ${section.year_level}</p>
                                <p class="mb-1"><strong>Semester:</strong> ${section.semester}</p>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-1"><strong>Section Type:</strong> <span class="badge bg-info">${section.section_type}</span></p>
                                <p class="mb-1"><strong>Academic Year:</strong> ${section.academic_year}</p>
                                <p class="mb-1"><strong>Capacity:</strong> <span class="${section.current_enrolled >= section.max_capacity ? 'text-danger' : 'text-success'}">${section.current_enrolled}/${section.max_capacity}</span></p>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            document.getElementById('section_info').innerHTML = infoHtml;
        }
        
        function displaySectionStudents(students) {
            if (students.length === 0) {
                document.getElementById('section_students_table_container').style.display = 'none';
                document.getElementById('no_students_message').style.display = 'block';
                return;
            }
            
            let html = '';
            students.forEach((student, index) => {
                const enrolledDate = new Date(student.enrolled_date).toLocaleDateString('en-US', {
                    year: 'numeric',
                    month: 'short',
                    day: 'numeric'
                });
                
                // Student type badge
                let studentTypeBadge = '';
                const studentType = student.student_type || 'Regular';
                if (studentType === 'Irregular') {
                    studentTypeBadge = '<span class="badge bg-warning text-dark ms-2" title="Irregular Student - Missing required subjects">⚠️ Irregular</span>';
                } else if (studentType === 'Transferee') {
                    studentTypeBadge = '<span class="badge bg-info ms-2" title="Transfer Student">→ Transferee</span>';
                } else {
                    studentTypeBadge = '<span class="badge bg-success ms-2" title="Regular Student">✓ Regular</span>';
                }
                
                html += `
                    <tr>
                        <td>${index + 1}</td>
                        <td><strong>${student.student_id}</strong></td>
                        <td>${student.first_name} ${student.last_name} ${studentTypeBadge}</td>
                        <td>${student.email}</td>
                        <td>${enrolledDate}</td>
                        <td>
                            <button class="btn btn-sm btn-danger" onclick="removeStudentFromSectionView(${student.id}, ${JSON.stringify(student.first_name + ' ' + student.last_name)})" 
                                    title="Remove from section">
                                <i class="fas fa-user-minus"></i> Remove
                            </button>
                        </td>
                    </tr>
                `;
            });
            
            document.getElementById('section_students_list').innerHTML = html;
        }
        
        function removeStudentFromSectionView(userId, studentName) {
            if (!confirm('Remove ' + studentName + ' from this section?')) {
                return;
            }
            
            // Get current section ID from the modal (we'll need to store it)
            const sectionId = currentViewingSectionId;
            
            const formData = new FormData();
            formData.append('user_id', userId);
            formData.append('section_id', sectionId);
            
            fetch('remove_section.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Student removed from section successfully!');
                    // Reload the student list
                    viewSectionStudents(sectionId, currentViewingSectionName);
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error removing student: ' + error);
            });
        }
        
        // Store current viewing section for removal
        let currentViewingSectionId = null;
        let currentViewingSectionName = null;
        
        
        let currentSectionId = null;
        let currentSectionProgramId = null;
        let currentSectionYearLevel = null;
        let currentSectionSemester = null;
        
        function manageSchedule(sectionId, sectionName, programId, yearLevel, semester) {
            currentSectionId = sectionId;
            currentSectionProgramId = programId;
            currentSectionYearLevel = yearLevel;
            currentSectionSemester = semester;
            
            document.getElementById('schedule_modal_title').textContent = 'Manage Schedule';
            document.getElementById('schedule_section_info').textContent = 'Section: ' + sectionName + ' (' + yearLevel + ' - ' + semester + ')';
            
            // Load all curriculum courses for this section (both scheduled and unscheduled)
            loadSectionSchedule(sectionId);
            
            // Show modal
            var modal = new bootstrap.Modal(document.getElementById('manageScheduleModal'));
            modal.show();
        }
        
        function loadCurriculumCourses(programId, yearLevel, semester) {
            fetch('get_curriculum_courses.php?program_id=' + programId + '&year_level=' + encodeURIComponent(yearLevel) + '&semester=' + encodeURIComponent(semester) + '&session_id=<?php echo CURRENT_SESSION_ID; ?>')
                .then(response => response.json())
                .then(data => {
                    let select = document.getElementById('schedule_curriculum_id');
                    select.innerHTML = '<option value="">-- Select Course --</option>';
                    
                    data.courses.forEach(function(course) {
                        let option = document.createElement('option');
                        option.value = course.id;
                        option.setAttribute('data-code', course.course_code);
                        option.setAttribute('data-name', course.course_name);
                        option.setAttribute('data-units', course.units);
                        option.textContent = course.course_code + ' - ' + course.course_name + ' (' + course.units + ' units)';
                        select.appendChild(option);
                    });
                })
                .catch(error => {
                    console.error('Error loading curriculum courses:', error);
                });
        }
        
        function loadCourseInfo(select) {
            if (select.value) {
                let selectedOption = select.options[select.selectedIndex];
                document.getElementById('display_course_code').value = selectedOption.getAttribute('data-code');
                document.getElementById('schedule_course_code').value = selectedOption.getAttribute('data-code');
                document.getElementById('display_course_name').value = selectedOption.getAttribute('data-name');
                document.getElementById('schedule_course_name').value = selectedOption.getAttribute('data-name');
                document.getElementById('display_units').value = selectedOption.getAttribute('data-units');
                document.getElementById('schedule_units').value = selectedOption.getAttribute('data-units');
            }
        }
        
        function loadSectionSchedule(sectionId) {
            fetch('get_section_schedule.php?section_id=' + sectionId + '&session_id=<?php echo CURRENT_SESSION_ID; ?>')
                .then(response => response.json())
                .then(data => {
                    let tbody = document.getElementById('schedule-table-body');
                    let html = '';
                    let totalUnits = 0;
                    
                    if (data.schedule.length === 0) {
                        html = '<tr><td colspan="14" class="text-center text-muted">No courses found in curriculum for this section\'s year level and semester.</td></tr>';
                    } else {
                        data.schedule.forEach(function(item, index) {
                            totalUnits += parseInt(item.units);
                            
                            // Check if schedule details are filled (schedule_id > 0 means it exists in section_schedules)
                            let hasSchedule = item.schedule_id > 0;
                            let scheduleClass = hasSchedule ? '' : 'table-warning';
                            let time_display = item.time_start && item.time_end ? item.time_start + '-' + item.time_end : '-';
                            let room_display = item.room || '-';
                            let prof_display = item.professor_initial || '-';
                            let rowId = 'schedule-row-' + item.curriculum_id;
                            let editRowId = 'edit-row-' + item.curriculum_id;
                            
                            html += '<tr class="' + scheduleClass + '" id="' + rowId + '">';
                            html += '<td><strong>' + item.course_code + '</strong></td>';
                            html += '<td>' + item.course_name + '</td>';
                            html += '<td class="text-center">' + item.units + '</td>';
                            html += '<td class="text-center">' + (item.schedule_monday ? '✓' : '') + '</td>';
                            html += '<td class="text-center">' + (item.schedule_tuesday ? '✓' : '') + '</td>';
                            html += '<td class="text-center">' + (item.schedule_wednesday ? '✓' : '') + '</td>';
                            html += '<td class="text-center">' + (item.schedule_thursday ? '✓' : '') + '</td>';
                            html += '<td class="text-center">' + (item.schedule_friday ? '✓' : '') + '</td>';
                            html += '<td class="text-center">' + (item.schedule_saturday ? '✓' : '') + '</td>';
                            html += '<td class="text-center">' + (item.schedule_sunday ? '✓' : '') + '</td>';
                            html += '<td>' + time_display + '</td>';
                            html += '<td>' + room_display + '</td>';
                            html += '<td>' + prof_display + '</td>';
                            html += '<td>';
                            html += '<button class="btn btn-sm btn-primary" onclick="toggleEditSchedule(' + sectionId + ', ' + item.curriculum_id + ', ' + item.schedule_id + ', \'' + escapeHtml(item.course_code) + '\', \'' + editRowId + '\'); return false;"><i class="fas fa-edit"></i> Edit</button>';
                            html += '</td>';
                            html += '</tr>';
                            
                            // Add collapsible edit form row
                            html += '<tr id="' + editRowId + '" class="schedule-edit-row" style="display: none;">';
                            html += '<td colspan="14" class="p-0">';
                            html += '<div class="schedule-edit-container p-3 bg-light border-top">';
                            html += '<form class="edit-schedule-form" data-section-id="' + sectionId + '" data-curriculum-id="' + item.curriculum_id + '" data-schedule-id="' + item.schedule_id + '">';
                            html += '<div class="alert alert-info mb-3">';
                            html += '<strong>Course:</strong> ' + escapeHtml(item.course_code);
                            html += '</div>';
                            html += '<div class="mb-3">';
                            html += '<label class="form-label fw-bold">Schedule Days</label>';
                            html += '<div class="row g-2">';
                            html += '<div class="col-md-auto"><div class="form-check"><input class="form-check-input" type="checkbox" id="edit_schedule_monday_' + item.curriculum_id + '" name="schedule_monday"><label class="form-check-label" for="edit_schedule_monday_' + item.curriculum_id + '">Monday</label></div></div>';
                            html += '<div class="col-md-auto"><div class="form-check"><input class="form-check-input" type="checkbox" id="edit_schedule_tuesday_' + item.curriculum_id + '" name="schedule_tuesday"><label class="form-check-label" for="edit_schedule_tuesday_' + item.curriculum_id + '">Tuesday</label></div></div>';
                            html += '<div class="col-md-auto"><div class="form-check"><input class="form-check-input" type="checkbox" id="edit_schedule_wednesday_' + item.curriculum_id + '" name="schedule_wednesday"><label class="form-check-label" for="edit_schedule_wednesday_' + item.curriculum_id + '">Wednesday</label></div></div>';
                            html += '<div class="col-md-auto"><div class="form-check"><input class="form-check-input" type="checkbox" id="edit_schedule_thursday_' + item.curriculum_id + '" name="schedule_thursday"><label class="form-check-label" for="edit_schedule_thursday_' + item.curriculum_id + '">Thursday</label></div></div>';
                            html += '<div class="col-md-auto"><div class="form-check"><input class="form-check-input" type="checkbox" id="edit_schedule_friday_' + item.curriculum_id + '" name="schedule_friday"><label class="form-check-label" for="edit_schedule_friday_' + item.curriculum_id + '">Friday</label></div></div>';
                            html += '<div class="col-md-auto"><div class="form-check"><input class="form-check-input" type="checkbox" id="edit_schedule_saturday_' + item.curriculum_id + '" name="schedule_saturday"><label class="form-check-label" for="edit_schedule_saturday_' + item.curriculum_id + '">Saturday</label></div></div>';
                            html += '<div class="col-md-auto"><div class="form-check"><input class="form-check-input" type="checkbox" id="edit_schedule_sunday_' + item.curriculum_id + '" name="schedule_sunday"><label class="form-check-label" for="edit_schedule_sunday_' + item.curriculum_id + '">Sunday</label></div></div>';
                            html += '</div>';
                            html += '</div>';
                            html += '<div class="row">';
                            html += '<div class="col-md-6 mb-3">';
                            html += '<label for="edit_time_start_' + item.curriculum_id + '" class="form-label">Time Start</label>';
                            html += '<input type="time" class="form-control" id="edit_time_start_' + item.curriculum_id + '" name="time_start">';
                            html += '</div>';
                            html += '<div class="col-md-6 mb-3">';
                            html += '<label for="edit_time_end_' + item.curriculum_id + '" class="form-label">Time End</label>';
                            html += '<input type="time" class="form-control" id="edit_time_end_' + item.curriculum_id + '" name="time_end">';
                            html += '</div>';
                            html += '</div>';
                            html += '<div class="mb-3">';
                            html += '<label for="edit_room_' + item.curriculum_id + '" class="form-label">Room</label>';
                            html += '<input type="text" class="form-control" id="edit_room_' + item.curriculum_id + '" name="room" placeholder="e.g., Room 101">';
                            html += '</div>';
                            html += '<div class="mb-3">';
                            html += '<label for="edit_professor_initial_' + item.curriculum_id + '" class="form-label">Professor\'s Initial</label>';
                            html += '<input type="text" class="form-control" id="edit_professor_initial_' + item.curriculum_id + '" name="professor_initial" placeholder="e.g., JAB">';
                            html += '</div>';
                            html += '<div class="mb-3">';
                            html += '<label for="edit_professor_name_' + item.curriculum_id + '" class="form-label">Professor\'s Full Name</label>';
                            html += '<input type="text" class="form-control" id="edit_professor_name_' + item.curriculum_id + '" name="professor_name" placeholder="e.g., Dr. John A. Brown">';
                            html += '</div>';
                            html += '<div class="d-flex gap-2">';
                            html += '<button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>Save Schedule Details</button>';
                            html += '<button type="button" class="btn btn-secondary" onclick="toggleEditSchedule(' + sectionId + ', ' + item.curriculum_id + ', ' + item.schedule_id + ', \'' + escapeHtml(item.course_code) + '\', \'' + editRowId + '\'); return false;">Cancel</button>';
                            html += '</div>';
                            html += '</form>';
                            html += '</div>';
                            html += '</td>';
                            html += '</tr>';
                        });
                    }
                    
                    tbody.innerHTML = html;
                    document.getElementById('total-units').innerHTML = '<strong>' + totalUnits + '</strong>';
                })
                .catch(error => {
                    console.error('Error loading schedule:', error);
                });
        }
        
        function escapeHtml(text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text.replace(/[&<>"']/g, function(m) { return map[m]; });
        }
        
        function toggleEditSchedule(sectionId, curriculumId, scheduleId, courseCode, editRowId) {
            let editRow = document.getElementById(editRowId);
            let isVisible = editRow.style.display !== 'none';
            
            // Close all other edit rows first
            document.querySelectorAll('.schedule-edit-row').forEach(function(row) {
                if (row.id !== editRowId) {
                    row.style.display = 'none';
                }
            });
            
            if (isVisible) {
                // Hide the edit row
                editRow.style.display = 'none';
            } else {
                // Show the edit row and populate form
                editRow.style.display = '';
                
                // If scheduleId > 0, fetch existing schedule details
                if (scheduleId > 0) {
                    fetch('get_section_schedule.php?section_id=' + sectionId + '&session_id=<?php echo CURRENT_SESSION_ID; ?>')
                        .then(response => response.json())
                        .then(data => {
                            // Find this specific course
                            let course = data.schedule.find(c => c.curriculum_id == curriculumId);
                            if (course) {
                                // Populate form with existing data
                                document.getElementById('edit_schedule_monday_' + curriculumId).checked = course.schedule_monday == 1;
                                document.getElementById('edit_schedule_tuesday_' + curriculumId).checked = course.schedule_tuesday == 1;
                                document.getElementById('edit_schedule_wednesday_' + curriculumId).checked = course.schedule_wednesday == 1;
                                document.getElementById('edit_schedule_thursday_' + curriculumId).checked = course.schedule_thursday == 1;
                                document.getElementById('edit_schedule_friday_' + curriculumId).checked = course.schedule_friday == 1;
                                document.getElementById('edit_schedule_saturday_' + curriculumId).checked = course.schedule_saturday == 1;
                                document.getElementById('edit_schedule_sunday_' + curriculumId).checked = course.schedule_sunday == 1;
                                document.getElementById('edit_time_start_' + curriculumId).value = course.time_start || '';
                                document.getElementById('edit_time_end_' + curriculumId).value = course.time_end || '';
                                document.getElementById('edit_room_' + curriculumId).value = course.room || '';
                                document.getElementById('edit_professor_initial_' + curriculumId).value = course.professor_initial || '';
                                document.getElementById('edit_professor_name_' + curriculumId).value = course.professor_name || '';
                            }
                        });
                } else {
                    // Clear form for new schedule
                    document.getElementById('edit_schedule_monday_' + curriculumId).checked = false;
                    document.getElementById('edit_schedule_tuesday_' + curriculumId).checked = false;
                    document.getElementById('edit_schedule_wednesday_' + curriculumId).checked = false;
                    document.getElementById('edit_schedule_thursday_' + curriculumId).checked = false;
                    document.getElementById('edit_schedule_friday_' + curriculumId).checked = false;
                    document.getElementById('edit_schedule_saturday_' + curriculumId).checked = false;
                    document.getElementById('edit_schedule_sunday_' + curriculumId).checked = false;
                    document.getElementById('edit_time_start_' + curriculumId).value = '';
                    document.getElementById('edit_time_end_' + curriculumId).value = '';
                    document.getElementById('edit_room_' + curriculumId).value = '';
                    document.getElementById('edit_professor_initial_' + curriculumId).value = '';
                    document.getElementById('edit_professor_name_' + curriculumId).value = '';
                }
            }
        }
        
        // Keep the old function for backward compatibility (in case it's called from elsewhere)
        function editScheduleDetails(sectionId, curriculumId, scheduleId, courseCode) {
            // This function is now redirected to toggle behavior
            let editRowId = 'edit-row-' + curriculumId;
            toggleEditSchedule(sectionId, curriculumId, scheduleId, courseCode, editRowId);
        }
        
        // Handle edit schedule details form submission
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('editScheduleDetailsForm').addEventListener('submit', function(e) {
                e.preventDefault();
                
                let sectionId = document.getElementById('edit_schedule_section_id').value;
                let curriculumId = document.getElementById('edit_schedule_curriculum_id').value;
                let scheduleId = document.getElementById('edit_schedule_id').value;
                
                let formData = new FormData();
                formData.append('section_id', sectionId);
                formData.append('curriculum_id', curriculumId);
                formData.append('schedule_id', scheduleId);
                formData.append('schedule_monday', document.getElementById('edit_schedule_monday').checked ? 1 : 0);
                formData.append('schedule_tuesday', document.getElementById('edit_schedule_tuesday').checked ? 1 : 0);
                formData.append('schedule_wednesday', document.getElementById('edit_schedule_wednesday').checked ? 1 : 0);
                formData.append('schedule_thursday', document.getElementById('edit_schedule_thursday').checked ? 1 : 0);
                formData.append('schedule_friday', document.getElementById('edit_schedule_friday').checked ? 1 : 0);
                formData.append('schedule_saturday', document.getElementById('edit_schedule_saturday').checked ? 1 : 0);
                formData.append('schedule_sunday', document.getElementById('edit_schedule_sunday').checked ? 1 : 0);
                formData.append('time_start', document.getElementById('edit_time_start').value);
                formData.append('time_end', document.getElementById('edit_time_end').value);
                formData.append('room', document.getElementById('edit_room').value);
                formData.append('professor_initial', document.getElementById('edit_professor_initial').value);
                formData.append('professor_name', document.getElementById('edit_professor_name').value);
                
                // Use same endpoint for both new and existing schedules
                let endpoint = 'update_schedule_details.php';
                
                // If creating new schedule (scheduleId == 0), we need additional course info
                if (scheduleId == 0) {
                    // Fetch course info from the schedule data
                    fetch('get_section_schedule.php?section_id=' + sectionId + '&session_id=<?php echo CURRENT_SESSION_ID; ?>')
                        .then(response => response.json())
                        .then(data => {
                            let course = data.schedule.find(c => c.curriculum_id == curriculumId);
                            if (course) {
                                formData.append('course_code', course.course_code);
                                formData.append('course_name', course.course_name);
                                formData.append('units', course.units);
                                
                                saveScheduleData(endpoint, formData, sectionId);
                            }
                        });
                } else {
                    saveScheduleData(endpoint, formData, sectionId);
                }
            });
        });
        
        function saveScheduleData(endpoint, formData, sectionId) {
            fetch(endpoint, {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Schedule details saved successfully!');
                    // Close edit modal if it exists
                    let modal = document.getElementById('editScheduleDetailsModal');
                    if (modal) {
                        let modalInstance = bootstrap.Modal.getInstance(modal);
                        if (modalInstance) {
                            modalInstance.hide();
                        }
                    }
                    // Close any open edit rows
                    document.querySelectorAll('.schedule-edit-row').forEach(function(row) {
                        row.style.display = 'none';
                    });
                    // Reload schedule table
                    loadSectionSchedule(sectionId);
                } else {
                    alert('Error: ' + (data.message || 'Failed to save schedule details'));
                }
            })
            .catch(error => {
                alert('Error saving schedule details: ' + error);
            });
        }
        
        // Handle inline edit schedule form submissions using event delegation
        document.addEventListener('DOMContentLoaded', function() {
            // Use event delegation on document to handle dynamically created forms
            document.addEventListener('submit', function(e) {
                if (e.target.classList.contains('edit-schedule-form')) {
                        e.preventDefault();
                        
                        let form = e.target;
                        let sectionId = form.getAttribute('data-section-id');
                        let curriculumId = form.getAttribute('data-curriculum-id');
                        let scheduleId = form.getAttribute('data-schedule-id');
                        
                        let formData = new FormData();
                        formData.append('section_id', sectionId);
                        formData.append('curriculum_id', curriculumId);
                        formData.append('schedule_id', scheduleId);
                        formData.append('schedule_monday', document.getElementById('edit_schedule_monday_' + curriculumId).checked ? 1 : 0);
                        formData.append('schedule_tuesday', document.getElementById('edit_schedule_tuesday_' + curriculumId).checked ? 1 : 0);
                        formData.append('schedule_wednesday', document.getElementById('edit_schedule_wednesday_' + curriculumId).checked ? 1 : 0);
                        formData.append('schedule_thursday', document.getElementById('edit_schedule_thursday_' + curriculumId).checked ? 1 : 0);
                        formData.append('schedule_friday', document.getElementById('edit_schedule_friday_' + curriculumId).checked ? 1 : 0);
                        formData.append('schedule_saturday', document.getElementById('edit_schedule_saturday_' + curriculumId).checked ? 1 : 0);
                        formData.append('schedule_sunday', document.getElementById('edit_schedule_sunday_' + curriculumId).checked ? 1 : 0);
                        formData.append('time_start', document.getElementById('edit_time_start_' + curriculumId).value);
                        formData.append('time_end', document.getElementById('edit_time_end_' + curriculumId).value);
                        formData.append('room', document.getElementById('edit_room_' + curriculumId).value);
                        formData.append('professor_initial', document.getElementById('edit_professor_initial_' + curriculumId).value);
                        formData.append('professor_name', document.getElementById('edit_professor_name_' + curriculumId).value);
                        
                        // Use same endpoint for both new and existing schedules
                        let endpoint = 'update_schedule_details.php';
                        
                        // If creating new schedule (scheduleId == 0), we need additional course info
                        if (scheduleId == 0) {
                            // Fetch course info from the schedule data
                            fetch('get_section_schedule.php?section_id=' + sectionId + '&session_id=<?php echo CURRENT_SESSION_ID; ?>')
                                .then(response => response.json())
                                .then(data => {
                                    let course = data.schedule.find(c => c.curriculum_id == curriculumId);
                                    if (course) {
                                        formData.append('course_code', course.course_code);
                                        formData.append('course_name', course.course_name);
                                        formData.append('units', course.units);
                                        
                                        saveScheduleData(endpoint, formData, sectionId);
                                    }
                                });
                        } else {
                            saveScheduleData(endpoint, formData, sectionId);
                        }
                    }
            });
        });
        
        function showAddScheduleForm() {
            document.getElementById('add-schedule-form').style.display = 'block';
        }
        
        function hideAddScheduleForm() {
            document.getElementById('add-schedule-form').style.display = 'none';
            document.getElementById('addScheduleForm').reset();
        }
        
        function deleteScheduleEntry(scheduleId) {
            if (confirm('Are you sure you want to remove this course from the schedule?')) {
                fetch('delete_schedule.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'schedule_id=' + scheduleId + '&session_id=<?php echo CURRENT_SESSION_ID; ?>'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        loadSectionSchedule(currentSectionId);
                        alert('Course removed from schedule');
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    alert('Error deleting schedule entry: ' + error);
                });
            }
        }
        
        // ===== SECTION FILTERING AND SORTING =====
        
        // Real-time update: refresh enrolled/available counts periodically
        document.addEventListener('DOMContentLoaded', function() {
            function refreshSectionCounts() {
                fetch('get_section_enrollment_counts.php?session_id=<?php echo CURRENT_SESSION_ID; ?>')
                    .then(response => response.json())
                    .then(payload => {
                        if (!payload.success) return;
                        const rows = payload.data || [];
                        rows.forEach(row => {
                            const sectionId = row.section_id;
                            const enrolledCell = document.getElementById('enrolled-' + sectionId);
                            const capacityCell = document.getElementById('capacity-' + sectionId);
                            const availableBadge = document.getElementById('available-' + sectionId);
                            if (enrolledCell && capacityCell && availableBadge) {
                                const capacity = parseInt(capacityCell.textContent, 10) || row.max_capacity || 0;
                                const enrolled = parseInt(row.current_enrolled, 10) || 0;
                                const available = Math.max(capacity - enrolled, 0);
                                enrolledCell.textContent = enrolled;
                                availableBadge.textContent = available;
                                const pct = capacity > 0 ? (enrolled / capacity) * 100 : 0;
                                availableBadge.classList.remove('bg-danger','bg-warning','bg-success');
                                availableBadge.classList.add(pct >= 90 ? 'bg-danger' : (pct >= 70 ? 'bg-warning' : 'bg-success'));
                                // update data attributes for filtering/sorting
                                const rowEl = enrolledCell.closest('tr.section-row');
                                if (rowEl) {
                                    rowEl.dataset.enrolled = enrolled;
                                    rowEl.dataset.available = available;
                                    rowEl.dataset.percentage = pct;
                                }
                            }
                        });
                    })
                    .catch(() => {});
            }
            // initial and periodic refresh every 10 seconds
            refreshSectionCounts();
            setInterval(refreshSectionCounts, 10000);
        });

        function filterSections() {
            const program = document.getElementById('filter_program').value;
            const year = document.getElementById('filter_year').value;
            const semester = document.getElementById('filter_semester').value;
            const type = document.getElementById('filter_type').value;
            const status = document.getElementById('filter_status').value;
            const capacity = document.getElementById('filter_capacity').value;
            const search = document.getElementById('search_sections').value.toLowerCase();
            const sortBy = document.getElementById('sort_sections').value;
            const sortDirection = document.getElementById('sort_direction').value;
            
            const rows = document.querySelectorAll('.section-row');
            let visibleRows = [];
            
            // Filter rows
            rows.forEach(row => {
                let show = true;
                
                // Program filter
                if (program && row.dataset.program !== program) {
                    show = false;
                }
                
                // Year filter
                if (year && row.dataset.year !== year) {
                    show = false;
                }
                
                // Semester filter
                if (semester && row.dataset.semester !== semester) {
                    show = false;
                }
                
                // Type filter
                if (type && row.dataset.type !== type) {
                    show = false;
                }
                
                // Status filter
                if (status && row.dataset.status !== status) {
                    show = false;
                }
                
                // Capacity filter
                if (capacity) {
                    const percentage = parseFloat(row.dataset.percentage);
                    if (capacity === 'available' && percentage >= 100) {
                        show = false;
                    } else if (capacity === 'full' && percentage < 100) {
                        show = false;
                    } else if (capacity === 'almost-full' && percentage < 90) {
                        show = false;
                    }
                }
                
                // Search filter
                if (search && !row.dataset.searchText.includes(search)) {
                    show = false;
                }
                
                if (show) {
                    visibleRows.push(row);
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Sort visible rows
            visibleRows.sort((a, b) => {
                let aVal, bVal;
                
                switch (sortBy) {
                    case 'program':
                        aVal = a.dataset.program;
                        bVal = b.dataset.program;
                        break;
                    case 'year':
                        aVal = a.dataset.year;
                        bVal = b.dataset.year;
                        break;
                    case 'semester':
                        aVal = a.dataset.semester;
                        bVal = b.dataset.semester;
                        break;
                    case 'section':
                        aVal = a.dataset.section;
                        bVal = b.dataset.section;
                        break;
                    case 'type':
                        aVal = a.dataset.type;
                        bVal = b.dataset.type;
                        break;
                    case 'capacity':
                        aVal = parseInt(a.dataset.capacity);
                        bVal = parseInt(b.dataset.capacity);
                        break;
                    case 'enrolled':
                        aVal = parseInt(a.dataset.enrolled);
                        bVal = parseInt(b.dataset.enrolled);
                        break;
                    case 'available':
                        aVal = parseInt(a.dataset.available);
                        bVal = parseInt(b.dataset.available);
                        break;
                    case 'status':
                        aVal = a.dataset.status;
                        bVal = b.dataset.status;
                        break;
                    default:
                        aVal = a.dataset.program;
                        bVal = b.dataset.program;
                }
                
                if (typeof aVal === 'string') {
                    return sortDirection === 'asc' ? 
                        aVal.localeCompare(bVal) : bVal.localeCompare(aVal);
                } else {
                    return sortDirection === 'asc' ? aVal - bVal : bVal - aVal;
                }
            });
            
            // Reorder rows in table
            const tbody = document.getElementById('sections-table-body');
            visibleRows.forEach(row => {
                tbody.appendChild(row);
            });
            
            // Update counts
            document.getElementById('filtered-count').textContent = visibleRows.length;
            
            // Show/hide no results message
            const noResults = document.getElementById('no-results');
            if (visibleRows.length === 0) {
                noResults.style.display = 'block';
            } else {
                noResults.style.display = 'none';
            }
            
            // Update sort indicators
            updateSortIndicators(sortBy, sortDirection);
        }
        
        function clearFilters() {
            document.getElementById('filter_program').value = '';
            document.getElementById('filter_year').value = '';
            document.getElementById('filter_semester').value = '';
            document.getElementById('filter_type').value = '';
            document.getElementById('filter_status').value = '';
            document.getElementById('filter_capacity').value = '';
            document.getElementById('search_sections').value = '';
            document.getElementById('sort_sections').value = 'program';
            document.getElementById('sort_direction').value = 'asc';
            
            filterSections();
        }
        
        function updateSortIndicators(sortBy, direction) {
            // Remove all sort indicators
            document.querySelectorAll('th[data-sort] i').forEach(icon => {
                icon.className = 'fas fa-sort';
            });
            
            // Add indicator to current sort column
            const sortColumn = document.querySelector(`th[data-sort="${sortBy}"] i`);
            if (sortColumn) {
                sortColumn.className = direction === 'asc' ? 'fas fa-sort-up' : 'fas fa-sort-down';
            }
        }
        
        // Toggle filter visibility
        function toggleFilters() {
            const filterBody = document.getElementById('filter-body');
            const toggleIcon = document.getElementById('filter-toggle-icon');
            
            if (filterBody.style.display === 'none') {
                filterBody.style.display = 'block';
                toggleIcon.className = 'fas fa-chevron-up float-end';
            } else {
                filterBody.style.display = 'none';
                toggleIcon.className = 'fas fa-chevron-down float-end';
            }
        }
        
        // Add click handlers to sortable columns
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('th[data-sort]').forEach(th => {
                th.style.cursor = 'pointer';
                th.addEventListener('click', function() {
                    const sortBy = this.dataset.sort;
                    const currentSort = document.getElementById('sort_sections').value;
                    const currentDirection = document.getElementById('sort_direction').value;
                    
                    if (currentSort === sortBy) {
                        // Toggle direction if same column
                        document.getElementById('sort_direction').value = 
                            currentDirection === 'asc' ? 'desc' : 'asc';
                    } else {
                        // New column, default to ascending
                        document.getElementById('sort_sections').value = sortBy;
                        document.getElementById('sort_direction').value = 'asc';
                    }
                    
                    filterSections();
                });
            });
        });
        
        let currentEditingUserId = null;
        
        function editEnrolledStudent(userId, studentName) {
            currentEditingUserId = userId;
            
            // Set user ID and name
            document.getElementById('enrolled_user_id').value = userId;
            document.getElementById('student_type_user_id').value = userId;
            document.getElementById('enrolled_student_name').textContent = 'Student: ' + studentName;
            
            // Reset filters
            document.getElementById('edit_filter_program').value = '';
            document.getElementById('edit_filter_year').value = '';
            document.getElementById('edit_filter_semester').value = '';
            document.getElementById('edit_filter_type').value = '';
            document.getElementById('edit_sections_container').style.display = 'none';
            
            // Fetch current enrolled student data
            fetch('get_enrolled_student.php?user_id=' + userId)
                .then(response => response.json())
                .then(data => {
                    // Update display fields
                    document.getElementById('display_course').textContent = data.display_course || data.course || '-';
                    document.getElementById('display_year_level').textContent = data.display_year_level || data.year_level || '1st Year';
                    document.getElementById('display_semester').textContent = data.display_semester || data.semester || 'Fall 2024';
                    document.getElementById('display_academic_year').textContent = data.display_academic_year || data.academic_year || 'AY 2024-2025';
                    document.getElementById('student_type').value = data.student_type || 'Regular';
                    
                    // Load and display current sections
                    loadEditCurrentSections(userId);
                    
                    // Show modal
                    var modal = new bootstrap.Modal(document.getElementById('editEnrolledStudentModal'));
                    modal.show();
                })
                .catch(error => {
                    alert('Error loading enrolled student data: ' + error);
                });
        }
        
        function loadEditCurrentSections(userId) {
            fetch('get_student_section.php?user_id=' + userId)
                .then(response => response.json())
                .then(data => {
                    const container = document.getElementById('edit_current_sections_list');
                    
                    if (data.success && data.sections && data.sections.length > 0) {
                        let html = '';
                        data.sections.forEach(section => {
                            html += '<div class="alert alert-success d-flex justify-content-between align-items-center mb-2">';
                            html += '<div>';
                            html += '<strong>' + section.section_name + '</strong><br>';
                            html += '<small class="text-muted">' + section.program_code + ' | ' + section.year_level + ' | ' + section.semester + ' | ' + section.academic_year + '</small>';
                            html += '</div>';
                            html += '<button class="btn btn-sm btn-danger" onclick="removeEditSection(' + userId + ', ' + section.section_id + ', ' + JSON.stringify(section.section_name) + ')">';
                            html += '<i class="fas fa-times"></i> Remove</button>';
                            html += '</div>';
                        });
                        container.innerHTML = html;
                    } else {
                        container.innerHTML = '<p class="text-muted">No sections assigned yet.</p>';
                    }
                })
                .catch(error => {
                    console.error('Error loading sections:', error);
                    document.getElementById('edit_current_sections_list').innerHTML = '<p class="text-danger">Error loading sections</p>';
                });
        }
        
        function filterEditSections() {
            const programId = document.getElementById('edit_filter_program').value;
            const yearLevel = document.getElementById('edit_filter_year').value;
            const semester = document.getElementById('edit_filter_semester').value;
            const sectionType = document.getElementById('edit_filter_type').value;
            
            // Need at least program, year, and semester
            if (!programId || !yearLevel || !semester) {
                document.getElementById('edit_sections_container').style.display = 'none';
                return;
            }
            
            // Filter sections
            const matchingSections = allSectionsData.filter(section => {
                if (section.program_id != programId) return false;
                if (section.year_level != yearLevel) return false;
                if (section.semester != semester) return false;
                if (sectionType && section.section_type != sectionType) return false;
                return true;
            });
            
            displayEditSections(matchingSections);
        }
        
        function displayEditSections(sections) {
            const container = document.getElementById('edit_sections_container');
            const list = document.getElementById('edit_sections_list');
            
            if (sections.length === 0) {
                list.innerHTML = '<p class="text-muted small mb-0">No sections found matching these criteria.</p>';
                container.style.display = 'block';
                return;
            }
            
            let html = '';
            sections.forEach(section => {
                const isFull = section.current_enrolled >= section.max_capacity;
                const capacity = section.max_capacity - section.current_enrolled;
                
                html += '<div class="card mb-2 ' + (isFull ? 'border-danger' : '') + '">';
                html += '<div class="card-body p-2">';
                html += '<div class="d-flex justify-content-between align-items-center">';
                html += '<div>';
                html += '<strong>' + section.section_name + '</strong>';
                if (isFull) {
                    html += ' <span class="badge bg-danger ms-1">FULL</span>';
                }
                html += '<br><small class="text-muted">';
                html += '<span class="badge bg-info">' + section.section_type + '</span> ';
                html += section.academic_year + ' | Capacity: ' + section.current_enrolled + '/' + section.max_capacity;
                html += '</small>';
                html += '</div>';
                html += '<div>';
                if (isFull) {
                    html += '<button class="btn btn-sm btn-secondary" disabled>Full</button>';
                } else {
                    html += '<button class="btn btn-sm btn-primary" onclick="assignEditSection(' + section.id + ', ' + JSON.stringify(section.section_name) + ')">';
                    html += '<i class="fas fa-plus"></i> Assign</button>';
                }
                html += '</div>';
                html += '</div>';
                html += '</div>';
                html += '</div>';
            });
            
            list.innerHTML = html;
            container.style.display = 'block';
        }
        
        function assignEditSection(sectionId, sectionName) {
            if (!currentEditingUserId) {
                alert('Error: No student selected');
                return;
            }
            
            if (!confirm('Assign student to section "' + sectionName + '"?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('user_id', currentEditingUserId);
            formData.append('section_id', sectionId);
            
            fetch('assign_section.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Section assigned successfully!');
                    // Reload current sections
                    loadEditCurrentSections(currentEditingUserId);
                    // Update display
                    fetch('get_enrolled_student.php?user_id=' + currentEditingUserId)
                        .then(r => r.json())
                        .then(d => {
                            document.getElementById('display_course').textContent = d.display_course || d.course || '-';
                            document.getElementById('display_year_level').textContent = d.display_year_level || d.year_level || '1st Year';
                            document.getElementById('display_semester').textContent = d.display_semester || d.semester || 'Fall 2024';
                            document.getElementById('display_academic_year').textContent = d.display_academic_year || d.academic_year || 'AY 2024-2025';
                        });
                    // Reset filters
                    document.getElementById('edit_filter_program').value = '';
                    document.getElementById('edit_filter_year').value = '';
                    document.getElementById('edit_filter_semester').value = '';
                    document.getElementById('edit_filter_type').value = '';
                    document.getElementById('edit_sections_container').style.display = 'none';
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error assigning section: ' + error);
            });
        }
        
        function removeEditSection(userId, sectionId, sectionName) {
            if (!confirm('Remove student from section "' + sectionName + '"?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('user_id', userId);
            formData.append('section_id', sectionId);
            
            fetch('remove_section.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Section removed successfully!');
                    // Reload current sections
                    loadEditCurrentSections(userId);
                    // Update display
                    fetch('get_enrolled_student.php?user_id=' + userId)
                        .then(r => r.json())
                        .then(d => {
                            document.getElementById('display_course').textContent = d.display_course || d.course || '-';
                            document.getElementById('display_year_level').textContent = d.display_year_level || d.year_level || '1st Year';
                            document.getElementById('display_semester').textContent = d.display_semester || d.semester || 'Fall 2024';
                            document.getElementById('display_academic_year').textContent = d.display_academic_year || d.academic_year || 'AY 2024-2025';
                        });
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error removing section: ' + error);
            });
        }
        
        // View enrolled student information
        function viewEnrolledStudent(userId, studentName) {
            // Reuse the applicantInfoModal but fetch enrolled student data
            const modalElement = document.getElementById('applicantInfoModal');
            const modalTitle = document.getElementById('applicantInfoTitle');
            const modalBody = document.getElementById('applicantInfoBody');
            modalTitle.innerHTML = '<i class="fas fa-user-graduate me-2"></i>Enrolled Student Information: ' + studentName;
            modalBody.innerHTML = `
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted">Loading student information...</p>
                </div>
            `;
            const modalInstance = new bootstrap.Modal(modalElement);
            modalInstance.show();
            
            // Fetch enrolled student info, sections, and CORs
            Promise.all([
                fetch(`get_enrolled_student_info.php?user_id=${userId}`).then(r => r.json()).catch(() => ({ success: false, message: 'Error loading student info' })),
                fetch(`get_student_section.php?user_id=${userId}`).then(r => r.json()).catch(() => ({ success: false, sections: [] })),
                fetch(`get_user_cors.php?user_id=${userId}`).then(r => r.json()).catch(() => ({ success: false, cors: [] })),
                fetch(`get_enrolled_student.php?user_id=${userId}`).then(r => r.json()).catch(() => ({}))
            ])
                .then(([studentData, sectionsData, corsData, enrolledData]) => {
                    if (!studentData.success) {
                        throw new Error(studentData.message || 'Unable to load student information.');
                    }
                    const student = studentData.student || {};
                    const sections = sectionsData.success ? sectionsData.sections : [];
                    const cors = corsData.success ? corsData.cors : [];
                    const fullName = [student.first_name, student.middle_name, student.last_name].filter(Boolean).join(' ');
                    
                    const formatValue = (value) => value ? value : '<span class="text-muted">Not provided</span>';
                    const formatDate = (value) => {
                        if (!value) return '<span class="text-muted">Not provided</span>';
                        try {
                            const date = new Date(value);
                            if (Number.isNaN(date.getTime())) {
                                return formatValue(value);
                            }
                            return new Intl.DateTimeFormat('en-US', { dateStyle: 'medium' }).format(date);
                        } catch (e) {
                            return formatValue(value);
                        }
                    };
                    
                    modalBody.innerHTML = `
                        <div class="row g-4">
                            <div class="col-md-6">
                                <h6 class="text-primary"><i class="fas fa-id-card me-2"></i>Personal Details</h6>
                                <ul class="list-unstyled small mb-0">
                                    <li><strong>Student ID:</strong> ${formatValue(student.student_id)}</li>
                                    <li><strong>Name:</strong> ${formatValue(fullName || null)}</li>
                                    <li><strong>Status:</strong> ${formatValue(student.status)}</li>
                                    <li><strong>Enrollment Status:</strong> ${formatValue(student.enrollment_status)}</li>
                                    <li><strong>Enrolled Date:</strong> ${formatDate(student.enrolled_date || null)}</li>
                                </ul>
                                <h6 class="text-primary mt-3"><i class="fas fa-map-marker-alt me-2"></i>Address</h6>
                                <ul class="list-unstyled small mb-0">
                                    <li><strong>Address:</strong> ${formatValue(student.address)}</li>
                                    <li><strong>Permanent Address:</strong> ${formatValue(student.permanent_address)}</li>
                                    <li><strong>Municipality:</strong> ${formatValue(student.municipality_city)}</li>
                                    <li><strong>Barangay:</strong> ${formatValue(student.barangay)}</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-primary"><i class="fas fa-address-book me-2"></i>Contact</h6>
                                <ul class="list-unstyled small mb-0">
                                    <li><strong>Email:</strong> ${formatValue(student.email)}</li>
                                    <li><strong>Phone:</strong> ${formatValue(student.phone)}</li>
                                    <li><strong>Contact Number:</strong> ${formatValue(student.contact_number)}</li>
                                </ul>
                                <h6 class="text-primary mt-3"><i class="fas fa-graduation-cap me-2"></i>Enrollment Details</h6>
                                <ul class="list-unstyled small mb-0">
                                    <li><strong>Course/Program:</strong> ${formatValue(enrolledData.display_course || enrolledData.course || student.course || '-')}</li>
                                    <li><strong>Year Level:</strong> ${formatValue(enrolledData.display_year_level || enrolledData.year_level || student.year_level || '1st Year')}</li>
                                    <li><strong>Semester:</strong> ${formatValue(enrolledData.display_semester || enrolledData.semester || student.semester || '-')}</li>
                                    <li><strong>Academic Year:</strong> ${formatValue(enrolledData.display_academic_year || enrolledData.academic_year || student.academic_year || '-')}</li>
                                    <li><strong>Student Type:</strong> <span class="badge ${(student.student_type || enrolledData.student_type || 'Regular') == 'Regular' ? 'bg-success' : 'bg-warning'}">${student.student_type || enrolledData.student_type || 'Regular'}</span></li>
                                </ul>
                                <h6 class="text-primary mt-3"><i class="fas fa-users me-2"></i>Family / Guardian</h6>
                                <ul class="list-unstyled small mb-0">
                                    <li><strong>Guardian:</strong> ${formatValue(student.guardian_name)}</li>
                                    <li><strong>Father:</strong> ${formatValue(student.father_name)}</li>
                                    <li><strong>Mother:</strong> ${formatValue(student.mother_maiden_name)}</li>
                                </ul>
                            </div>
                        </div>
                        <div class="row g-4 mt-3">
                            <div class="col-12">
                                <hr>
                                <h6 class="text-primary mb-3"><i class="fas fa-users-cog me-2"></i>Assigned Sections</h6>
                                ${sections.length > 0 ? sections.map(section => {
                                    const enrolledDate = section.enrolled_date ? new Date(section.enrolled_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
                                    return `
                                        <div class="alert alert-success mb-2">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <strong>${section.section_name || 'N/A'}</strong><br>
                                                    <small>${section.program_code || 'N/A'} - ${section.program_name || 'N/A'} | ${section.year_level || 'N/A'} | ${section.semester || 'N/A'}</small><br>
                                                    <small class="text-muted">Academic Year: ${section.academic_year || 'N/A'} | Enrolled: ${enrolledDate}</small>
                                                </div>
                                            </div>
                                        </div>
                                    `;
                                }).join('') : '<div class="alert alert-info mb-0"><i class="fas fa-info-circle me-2"></i>No sections assigned yet.</div>'}
                            </div>
                        </div>
                        <div class="row g-4 mt-3">
                            <div class="col-12">
                                <hr>
                                <h6 class="text-primary mb-3"><i class="fas fa-file-alt me-2"></i>Certificate of Registration (COR)</h6>
                                ${cors.length > 0 ? cors.map(cor => {
                                    const formatDate = (dateStr) => {
                                        if (!dateStr) return 'N/A';
                                        try {
                                            const date = new Date(dateStr);
                                            return date.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
                                        } catch (e) {
                                            return dateStr;
                                        }
                                    };
                                    const subjects = Array.isArray(cor.subjects) ? cor.subjects : [];
                                    const totalUnits = cor.total_units || 0;
                                    return `
                                        <div class="card mb-3">
                                            <div class="card-header bg-light">
                                                <div class="d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <strong>${cor.program_code || 'N/A'} - ${cor.program_name || 'N/A'}</strong><br>
                                                        <small class="text-muted">${cor.section_name || 'N/A'} | ${cor.year_level || 'N/A'} | ${cor.semester || 'N/A'} | ${cor.academic_year || 'N/A'}</small>
                                                    </div>
                                                    <small class="text-muted">Created: ${formatDate(cor.created_at)}</small>
                                                </div>
                                            </div>
                                            <div class="card-body">
                                                <div class="row mb-2">
                                                    <div class="col-md-6">
                                                        <strong>Student:</strong> ${cor.student_last_name || ''}, ${cor.student_first_name || ''} ${cor.student_middle_name || ''}<br>
                                                        <strong>Student Number:</strong> ${cor.student_number || 'N/A'}<br>
                                                        <strong>Registration Date:</strong> ${formatDate(cor.registration_date)}
                                                    </div>
                                                    <div class="col-md-6">
                                                        <strong>Total Units:</strong> ${totalUnits}<br>
                                                        <strong>Number of Subjects:</strong> ${subjects.length}<br>
                                                        <strong>Prepared By:</strong> ${cor.prepared_by || 'N/A'}
                                                    </div>
                                                </div>
                                                ${subjects.length > 0 ? `
                                                    <div class="table-responsive mt-2">
                                                        <table class="table table-sm table-bordered mb-0">
                                                            <thead class="table-light">
                                                                <tr>
                                                                    <th>Course Code</th>
                                                                    <th>Course Name</th>
                                                                    <th>Units</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                ${subjects.map(subject => `
                                                                    <tr>
                                                                        <td>${subject.course_code || 'N/A'}</td>
                                                                        <td>${subject.course_name || 'N/A'}</td>
                                                                        <td>${subject.units || '0'}</td>
                                                                    </tr>
                                                                `).join('')}
                                                            </tbody>
                                                            <tfoot class="table-light">
                                                                <tr>
                                                                    <th colspan="2" class="text-end">Total Units:</th>
                                                                    <th>${totalUnits}</th>
                                                                </tr>
                                                            </tfoot>
                                                        </table>
                                                    </div>
                                                ` : '<p class="text-muted mb-0">No subjects listed.</p>'}
                                            </div>
                                        </div>
                                    `;
                                }).join('') : '<div class="alert alert-info mb-0"><i class="fas fa-info-circle me-2"></i>No Certificate of Registration has been created yet.</div>'}
                            </div>
                        </div>
                    `;
                })
                .catch(error => {
                    modalBody.innerHTML = `
                        <div class="alert alert-danger mb-0">
                            <i class="fas fa-exclamation-circle me-2"></i>${error.message}
                        </div>
                    `;
                });
        }
        
        // Section Assignment Functions
        let allSectionsData = [];
        let currentStudentId = null;
        let currentPreferredProgramId = null;
        let currentPreferredProgramName = '';
        
        // Load sections data on page load
        document.addEventListener('DOMContentLoaded', function() {
            allSectionsData = <?php echo json_encode($all_sections); ?>;
        });
        
        function assignSection(userId, studentName, preferredProgramId = null, preferredProgramName = '') {
            currentStudentId = userId;
            document.getElementById('assign_student_id').value = userId;
            document.getElementById('assign_student_name').textContent = 'Student: ' + studentName;
            currentPreferredProgramId = preferredProgramId || null;
            currentPreferredProgramName = preferredProgramName || '';
            
            const preferredNotice = document.getElementById('assign_preferred_program_notice');
            if (preferredNotice) {
                if (currentPreferredProgramName) {
                    preferredNotice.style.display = 'block';
                    if (currentPreferredProgramId) {
                        preferredNotice.innerHTML = `<i class="fas fa-info-circle me-1"></i>Preferred program: <strong>${currentPreferredProgramName}</strong>`;
                    } else {
                        preferredNotice.innerHTML = `<i class="fas fa-exclamation-triangle me-1 text-warning"></i>No matching section found for preferred program "<strong>${currentPreferredProgramName}</strong>". Showing all programs.`;
                    }
                } else {
                    preferredNotice.style.display = 'none';
                    preferredNotice.textContent = '';
                }
            }
            
            const programFilter = document.getElementById('assign_filter_program');
            const yearFilter = document.getElementById('assign_filter_year');
            const semesterFilter = document.getElementById('assign_filter_semester');
            const typeFilter = document.getElementById('assign_filter_type');
            const searchInput = document.getElementById('assign_search');

            if (yearFilter) {
                yearFilter.value = '';
            }
            if (semesterFilter) {
                semesterFilter.value = '';
            }
            if (typeFilter) {
                typeFilter.value = '';
            }
            if (searchInput) {
                searchInput.value = '';
            }
            if (programFilter) {
                if (currentPreferredProgramId) {
                    programFilter.value = String(currentPreferredProgramId);
                } else {
                    programFilter.value = '';
                }
            }
            
            // Load all sections
            loadAllSections();
            
            // Load current sections for this student
            loadCurrentSections(userId);
            
            // Show modal
            var modal = new bootstrap.Modal(document.getElementById('assignSectionModal'));
            modal.show();
        }
        
        function loadAllSections() {
            allSectionsData = <?php echo json_encode($all_sections ?? []); ?>;
            if (!Array.isArray(allSectionsData)) {
                allSectionsData = [];
            }
            filterAssignSections();
        }
        
        function loadCurrentSections(userId) {
            fetch('get_student_section.php?user_id=' + userId)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.sections.length > 0) {
                        let html = '';
                        data.sections.forEach(section => {
                            html += '<div class="alert alert-success d-flex justify-content-between align-items-center">';
                            html += '<div>';
                            html += '<strong>' + section.section_name + '</strong><br>';
                            html += '<small>' + section.program_code + ' - ' + section.year_level + ' - ' + section.semester + '</small>';
                            html += '</div>';
                            html += '<button class="btn btn-sm btn-danger" onclick="removeSectionAssignment(' + userId + ', ' + section.section_id + ')">';
                            html += '<i class="fas fa-times"></i> Remove</button>';
                            html += '</div>';
                        });
                        document.getElementById('current_sections_list').innerHTML = html;
                        document.getElementById('current_sections_container').style.display = 'block';
                    } else {
                        document.getElementById('current_sections_container').style.display = 'none';
                    }
                })
                .catch(error => {
                    console.error('Error loading current sections:', error);
                });
        }
        
        function filterAssignSections() {
            const programFilter = document.getElementById('assign_filter_program').value;
            const yearFilter = document.getElementById('assign_filter_year').value;
            const semesterFilter = document.getElementById('assign_filter_semester').value;
            const typeFilter = document.getElementById('assign_filter_type').value;
            const searchText = document.getElementById('assign_search').value.toLowerCase();
            
            let filteredSections = allSectionsData.filter(section => {
                // Program filter
                if (programFilter && section.program_id != programFilter) return false;
                
                // Year level filter
                if (yearFilter && section.year_level != yearFilter) return false;
                
                // Semester filter
                if (semesterFilter && section.semester != semesterFilter) return false;
                
                // Section type filter
                if (typeFilter && section.section_type != typeFilter) return false;
                
                // Search filter
                if (searchText) {
                    const sectionName = section.section_name.toLowerCase();
                    const programCode = section.program_code.toLowerCase();
                    const searchIn = sectionName + ' ' + programCode;
                    if (!searchIn.includes(searchText)) return false;
                }
                
                return true;
            });
            
            displaySectionsList(filteredSections);
        }
        
        function clearAssignFilters() {
            document.getElementById('assign_filter_program').value = '';
            document.getElementById('assign_filter_year').value = '';
            document.getElementById('assign_filter_semester').value = '';
            document.getElementById('assign_filter_type').value = '';
            document.getElementById('assign_search').value = '';
            filterAssignSections();
        }
        
        function displaySectionsList(sections) {
            let html = '';
            
            // Update count display
            document.getElementById('assign_sections_count').textContent = sections.length;
            
            if (sections.length === 0) {
                html = '<div class="text-center py-4">';
                html += '<i class="fas fa-search fa-3x text-muted mb-3"></i>';
                html += '<p class="text-muted mb-0">No sections found matching the filters.</p>';
                html += '<small class="text-muted">Try adjusting your filter criteria.</small>';
                html += '</div>';
            } else {
                sections.forEach(section => {
                    const capacity = section.max_capacity - section.current_enrolled;
                    const isFull = capacity <= 0;
                    const isAlmostFull = !isFull && (section.current_enrolled / section.max_capacity) >= 0.9;
                    
                    html += '<div class="card mb-2 ' + (isFull ? 'border-danger' : (isAlmostFull ? 'border-warning' : '')) + '">';
                    html += '<div class="card-body p-3">';
                    html += '<div class="row align-items-center">';
                    html += '<div class="col-md-7">';
                    html += '<h6 class="mb-1">';
                    html += '<i class="fas fa-users me-2 text-primary"></i>' + section.section_name;
                    if (isFull) {
                        html += ' <span class="badge bg-danger ms-2">FULL</span>';
                    } else if (isAlmostFull) {
                        html += ' <span class="badge bg-warning ms-2">Almost Full</span>';
                    }
                    html += '</h6>';
                    html += '<small class="text-muted">';
                    html += '<strong>' + section.program_code + '</strong> | ';
                    html += section.year_level + ' | ';
                    html += section.semester;
                    html += '</small><br>';
                    html += '<small class="text-muted">';
                    html += '<span class="badge bg-info">' + section.section_type + '</span> ';
                    html += section.academic_year;
                    html += '</small>';
                    html += '</div>';
                    html += '<div class="col-md-3">';
                    html += '<small class="d-block"><strong>Capacity:</strong></small>';
                    html += '<span class="' + (isFull ? 'text-danger' : (isAlmostFull ? 'text-warning' : 'text-success')) + '">';
                    html += '<strong>' + section.current_enrolled + '/' + section.max_capacity + '</strong></span>';
                    if (!isFull) {
                        html += '<br><small class="text-muted">' + capacity + ' slot(s) available</small>';
                    }
                    html += '</div>';
                    html += '<div class="col-md-2 text-end">';
                    
                    if (isFull) {
                        html += '<button class="btn btn-sm btn-secondary" disabled>';
                        html += '<i class="fas fa-ban"></i> Full</button>';
                    } else {
                        // Use JSON.stringify to properly escape the section name for HTML attribute
                        const escapedName = section.section_name.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                        html += '<button class="btn btn-sm btn-primary assign-section-btn" data-section-id="' + section.id + '" data-section-name="' + escapedName + '">';
                        html += '<i class="fas fa-plus"></i> Assign</button>';
                    }
                    
                    html += '</div>';
                    html += '</div>';
                    html += '</div>';
                    html += '</div>';
                });
            }
            
            document.getElementById('sections_list').innerHTML = html;
        }
        
        function performSectionAssignment(sectionId, sectionName) {
            if (!currentStudentId) {
                alert('Error: No student selected');
                return;
            }
            
            if (!confirm('Assign section "' + sectionName + '" to this student?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('user_id', currentStudentId);
            formData.append('section_id', sectionId);
            
            fetch('assign_section.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Section assigned successfully!');
                    location.reload(); // Reload to show updated section assignments
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error assigning section: ' + error);
            });
        }
        
        function removeSectionAssignment(userId, sectionId) {
            if (!confirm('Remove this section assignment?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('user_id', userId);
            formData.append('section_id', sectionId);
            
            fetch('remove_section.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Section assignment removed successfully!');
                    location.reload(); // Reload to show updated section assignments
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error removing section: ' + error);
            });
        }
        
        // Chatbot FAQ Management Functions
        function loadChatbotStatistics() {
            fetch('chatbot_manage.php?action=statistics')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('totalFAQs').textContent = data.stats.total_faqs || 0;
                        document.getElementById('totalInquiries').textContent = data.stats.total_inquiries || 0;
                        document.getElementById('mostViewedCount').textContent = data.stats.most_viewed ? data.stats.most_viewed.view_count : 0;
                    }
                })
                .catch(error => console.error('Error:', error));
        }
        
        function loadChatbotFAQs() {
            fetch('chatbot_manage.php?action=get_all')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayFAQs(data.faqs);
                        
                        // Count categories
                        const categories = new Set(data.faqs.map(faq => faq.category));
                        document.getElementById('categoriesCount').textContent = categories.size;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('faqsTableBody').innerHTML = '<tr><td colspan="6" class="text-center text-danger">Error loading FAQs</td></tr>';
                });
        }
        
        function displayFAQs(faqs) {
            const tbody = document.getElementById('faqsTableBody');
            
            if (faqs.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center">No FAQs found. Click "Add New FAQ" to create one.</td></tr>';
                return;
            }
            
            tbody.innerHTML = '';
            faqs.forEach(faq => {
                const row = document.createElement('tr');
                
                const statusBadge = faq.is_active == 1 ? 
                    '<span class="badge bg-success">Active</span>' : 
                    '<span class="badge bg-secondary">Inactive</span>';
                
                row.innerHTML = `
                    <td><strong>${escapeHtml(faq.question)}</strong></td>
                    <td><small class="text-muted">${escapeHtml(faq.answer.substring(0, 120))}${faq.answer.length > 120 ? '...' : ''}</small></td>
                    <td><span class="badge bg-info">${escapeHtml(faq.category || 'General')}</span></td>
                    <td><small>${escapeHtml((faq.keywords || 'N/A').substring(0, 30))}${faq.keywords && faq.keywords.length > 30 ? '...' : ''}</small></td>
                    <td class="text-center"><span class="badge bg-primary">${faq.view_count || 0}</span></td>
                    <td class="text-center">${statusBadge}</td>
                    <td class="text-end">
                        <button class="btn btn-sm btn-warning" onclick="editFAQ(${faq.id})" title="Edit">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="btn btn-sm btn-danger" onclick="deleteFAQ(${faq.id})" title="Delete">
                            <i class="fas fa-trash"></i>
                        </button>
                    </td>
                `;
                tbody.appendChild(row);
            });
        }
        
        function showAddFAQModal() {
            document.getElementById('faqModalTitle').textContent = 'Add New FAQ';
            document.getElementById('faqForm').reset();
            document.getElementById('faq_id').value = '';
            document.getElementById('faq_is_active').checked = true;
            
            const modal = new bootstrap.Modal(document.getElementById('faqModal'));
            modal.show();
        }
        
        function editFAQ(faqId) {
            fetch(`chatbot_manage.php?action=get_one&id=${faqId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.faq) {
                        document.getElementById('faqModalTitle').textContent = 'Edit FAQ';
                        document.getElementById('faq_id').value = data.faq.id;
                        document.getElementById('faq_question').value = data.faq.question;
                        document.getElementById('faq_answer').value = data.faq.answer;
                        document.getElementById('faq_category').value = data.faq.category || 'General';
                        document.getElementById('faq_keywords').value = data.faq.keywords || '';
                        document.getElementById('faq_is_active').checked = data.faq.is_active == 1;
                        
                        const modal = new bootstrap.Modal(document.getElementById('faqModal'));
                        modal.show();
                    }
                })
                .catch(error => console.error('Error:', error));
        }
        
        function deleteFAQ(faqId) {
            if (!confirm('Are you sure you want to delete this FAQ?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('action', 'delete');
            formData.append('id', faqId);
            
            fetch('chatbot_manage.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('FAQ deleted successfully!');
                    loadChatbotFAQs();
                    loadChatbotStatistics();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error deleting FAQ');
            });
        }
        
        function loadRecentInquiries() {
            fetch('chatbot_manage.php?action=recent_inquiries')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayInquiries(data.inquiries);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('inquiriesTableBody').innerHTML = '<tr><td colspan="4" class="text-center text-danger">Error loading inquiries</td></tr>';
                });
        }
        
        function displayInquiries(inquiries) {
            const tbody = document.getElementById('inquiriesTableBody');
            
            if (inquiries.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="text-center">No student inquiries yet.</td></tr>';
                return;
            }
            
            tbody.innerHTML = '';
            inquiries.forEach(inquiry => {
                const row = document.createElement('tr');
                const createdDate = new Date(inquiry.created_at).toLocaleString();
                
                row.innerHTML = `
                    <td>
                        <strong>${escapeHtml(inquiry.student_id)}</strong>
                        <br><small>${escapeHtml(inquiry.first_name + ' ' + inquiry.last_name)}</small>
                    </td>
                    <td>${escapeHtml(inquiry.question)}</td>
                    <td><small>${escapeHtml((inquiry.answer || 'N/A').substring(0, 100))}${inquiry.answer && inquiry.answer.length > 100 ? '...' : ''}</small></td>
                    <td><small>${createdDate}</small></td>
                `;
                tbody.appendChild(row);
            });
        }
        
        // FAQ Form submission
        document.addEventListener('DOMContentLoaded', function() {
            const faqForm = document.getElementById('faqForm');
            if (faqForm) {
                faqForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    
                    const formData = new FormData(this);
                    const faqId = document.getElementById('faq_id').value;
                    
                    if (faqId) {
                        formData.append('action', 'update');
                    } else {
                        formData.append('action', 'add');
                    }
                    
                    fetch('chatbot_manage.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert(data.message);
                            bootstrap.Modal.getInstance(document.getElementById('faqModal')).hide();
                            loadChatbotFAQs();
                            loadChatbotStatistics();
                        } else {
                            alert('Error: ' + data.message);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('Error saving FAQ');
                    });
                });
            }
        });
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Update showSection to load chatbot data
        const originalShowSection = showSection;
        showSection = function(sectionId) {
            originalShowSection(sectionId);
            
            // Load chatbot FAQs if viewing chatbot section
            if (sectionId === 'chatbot') {
                loadChatbotFAQs();
                loadChatbotStatistics();
                loadRecentInquiries();
            }
        };
        
        // Next Semester Enrollment Review Function
        function reviewNextSemesterEnrollment(requestId, studentName) {
            window.location.href = `review_next_semester.php?request_id=${requestId}`;
        }

        // Handle enrollment approval (Enroll button) - use event delegation
        document.addEventListener('click', function(e) {
            const enrollBtn = e.target.closest('.enroll-btn');
            if (!enrollBtn) return;
            
            e.preventDefault();
            e.stopPropagation();
            
            const requestId = enrollBtn.getAttribute('data-request-id');
            const studentName = enrollBtn.getAttribute('data-student-name');
            
            if (!requestId) {
                alert('Error: Missing request ID');
                return;
            }
            
            if (!confirm(`Are you sure you want to ENROLL ${studentName}? This will approve their next semester enrollment request and update their enrollment status.`)) {
                return;
            }
            
            // Disable button during processing
            enrollBtn.disabled = true;
            const originalHTML = enrollBtn.innerHTML;
            enrollBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
            
            const formData = new FormData();
            formData.append('request_id', requestId);
            
            fetch('approve_enrollment.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✓ ' + data.message);
                    // Reload the page to refresh the table
                    window.location.reload();
                } else {
                    alert('Error: ' + data.message);
                    // Re-enable button
                    enrollBtn.disabled = false;
                    enrollBtn.innerHTML = originalHTML;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error approving enrollment. Please try again.');
                // Re-enable button
                enrollBtn.disabled = false;
                enrollBtn.innerHTML = originalHTML;
            });
        });

        // Curriculum Submission Functions
        function viewCurriculumSubmission(submissionId) {
            // Open submission details in a modal or redirect to a view page
            window.open(`view_curriculum_submission.php?id=${submissionId}`, '_blank');
        }

        function approveSubmission(submissionId) {
            if (confirm('Are you sure you want to approve this curriculum submission? This will add the subjects to the curriculum.')) {
                const formData = new FormData();
                formData.append('submission_id', submissionId);
                formData.append('action', 'approve');

                fetch('process_curriculum_submission.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Curriculum submission approved successfully!');
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error approving submission');
                });
            }
        }

        function rejectSubmission(submissionId) {
            const reason = prompt('Please provide a reason for rejecting this submission:');
            if (reason !== null && reason.trim() !== '') {
                const formData = new FormData();
                formData.append('submission_id', submissionId);
                formData.append('action', 'reject');
                formData.append('reason', reason.trim());

                fetch('process_curriculum_submission.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Curriculum submission rejected.');
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error rejecting submission');
                });
            }
        }

        // Bulk selection functions
        function toggleSelectAll() {
            const selectAllCheckbox = document.getElementById('select-all');
            const checkboxes = document.querySelectorAll('.submission-checkbox');

            checkboxes.forEach(checkbox => {
                checkbox.checked = selectAllCheckbox.checked;
            });

            updateBulkActions();
        }

        function updateBulkActions() {
            const checkboxes = document.querySelectorAll('.submission-checkbox:checked');
            const bulkActions = document.getElementById('bulk-actions');

            if (checkboxes.length > 0) {
                bulkActions.style.display = 'block';
            } else {
                bulkActions.style.display = 'none';
            }
        }

        function clearSelection() {
            const checkboxes = document.querySelectorAll('.submission-checkbox');
            const selectAllCheckbox = document.getElementById('select-all');

            checkboxes.forEach(checkbox => {
                checkbox.checked = false;
            });
            selectAllCheckbox.checked = false;

            updateBulkActions();
        }

        function bulkApproveSubmissions() {
            const checkboxes = document.querySelectorAll('.submission-checkbox:checked');
            const submissionIds = Array.from(checkboxes).map(cb => cb.value);

            if (submissionIds.length === 0) {
                alert('Please select at least one submission to approve.');
                return;
            }

            const confirmMessage = `Are you sure you want to approve ${submissionIds.length} curriculum submission(s)? This will add all subjects to their respective program curricula.`;

            if (!confirm(confirmMessage)) {
                return;
            }

            // Show loading state
            const bulkButton = document.querySelector('#bulk-actions .btn-success');
            const originalText = bulkButton.innerHTML;
            bulkButton.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Processing...';
            bulkButton.disabled = true;

            // Process submissions one by one
            let processed = 0;
            let successCount = 0;
            let errorMessages = [];

            function processNext() {
                if (processed >= submissionIds.length) {
                    // All done
                    bulkButton.innerHTML = originalText;
                    bulkButton.disabled = false;

                    if (successCount > 0) {
                        alert(`Successfully approved ${successCount} out of ${submissionIds.length} submissions.`);
                        location.reload();
                    } else {
                        alert('No submissions were approved. Errors: ' + errorMessages.join(', '));
                    }
                    return;
                }

                const submissionId = submissionIds[processed];
                processed++;

                const formData = new FormData();
                formData.append('submission_id', submissionId);
                formData.append('action', 'approve');

                fetch('process_curriculum_submission.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        successCount++;
                    } else {
                        errorMessages.push(`Submission ${submissionId}: ${data.message}`);
                    }
                    processNext();
                })
                .catch(error => {
                    errorMessages.push(`Submission ${submissionId}: Network error`);
                    processNext();
                });
            }

            processNext();
        }
        // Preview section name format for Add Section Modal
        function updateSectionNamePreview() {
            const programSelect = document.getElementById('section_program_id');
            const academicYearInput = document.getElementById('section_academic_year');
            const sectionTypeSelect = document.getElementById('section_type');
            const sectionNameInput = document.getElementById('section_name');
            
            if (!programSelect || !academicYearInput || !sectionTypeSelect || !sectionNameInput) {
                return;
            }
            
            const programText = programSelect.options[programSelect.selectedIndex]?.text || '';
            const programCode = programText.split(' - ')[0] || '';
            const academicYear = academicYearInput.value || '';
            const sectionType = sectionTypeSelect.value || '';
            
            // Extract last 2 digits from academic year
            let yearSuffix = '';
            const yearMatch = academicYear.match(/(\d{4})/);
            if (yearMatch) {
                yearSuffix = yearMatch[1].substring(2); // Last 2 digits of the year
            }
            
            // Map section type to code
            const typeMap = {
                'Morning': 'M',
                'Afternoon': 'P',
                'Evening': 'E'
            };
            const typeCode = typeMap[sectionType] || 'M';
            
            // Generate preview (section number will be determined on server)
            if (programCode && yearSuffix && typeCode) {
                sectionNameInput.value = yearSuffix + programCode + '?' + typeCode; // ? will be replaced with actual number
                sectionNameInput.placeholder = 'Preview: ' + yearSuffix + programCode + '1' + typeCode + ' (number will be auto-assigned)';
            } else {
                sectionNameInput.value = '';
                sectionNameInput.placeholder = 'Auto-generated (e.g., 24BSIS1M)';
            }
        }
        
        // Add event listeners for section name preview
        document.addEventListener('DOMContentLoaded', function() {
            const programSelect = document.getElementById('section_program_id');
            const academicYearInput = document.getElementById('section_academic_year');
            const sectionTypeSelect = document.getElementById('section_type');
            
            if (programSelect) {
                programSelect.addEventListener('change', updateSectionNamePreview);
            }
            if (academicYearInput) {
                academicYearInput.addEventListener('input', updateSectionNamePreview);
            }
            if (sectionTypeSelect) {
                sectionTypeSelect.addEventListener('change', updateSectionNamePreview);
            }
            
            // Initialize preview when modal is shown
            const addSectionModal = document.getElementById('addSectionModal');
            if (addSectionModal) {
                addSectionModal.addEventListener('show.bs.modal', function() {
                    setTimeout(updateSectionNamePreview, 100);
                });
            }
        });
        
        // Auto-uppercase program code input
        document.addEventListener('DOMContentLoaded', function() {
            const programCodeInput = document.getElementById('program_code');
            if (programCodeInput) {
                programCodeInput.addEventListener('input', function(e) {
                    e.target.value = e.target.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
                });
            }
            
            // Submission filters
            const filterStatus = document.getElementById('filter-status');
            const filterProgram = document.getElementById('filter-program');
            const searchSubmissions = document.getElementById('search-submissions');
            
            if (filterStatus) filterStatus.addEventListener('change', filterSubmissions);
            if (filterProgram) filterProgram.addEventListener('change', filterSubmissions);
            if (searchSubmissions) searchSubmissions.addEventListener('input', filterSubmissions);
            
            // View history button handlers
            document.querySelectorAll('.view-history-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const submissionId = this.getAttribute('data-submission-id');
                    viewSubmissionHistory(submissionId);
                });
            });
            
            // View subjects button handlers
            document.querySelectorAll('.view-subjects-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const submissionId = this.getAttribute('data-submission-id');
                    viewSubmissionSubjects(submissionId);
                });
            });
        });
        
        // Filter submissions table
        function filterSubmissions() {
            const statusFilter = document.getElementById('filter-status')?.value || '';
            const programFilter = document.getElementById('filter-program')?.value || '';
            const searchTerm = document.getElementById('search-submissions')?.value.toLowerCase() || '';
            
            const rows = document.querySelectorAll('#submissions-table tbody tr');
            let visibleCount = 0;
            
            rows.forEach(row => {
                const status = row.getAttribute('data-status') || '';
                const program = row.getAttribute('data-program') || '';
                const search = row.getAttribute('data-search') || '';
                
                const statusMatch = !statusFilter || status === statusFilter;
                const programMatch = !programFilter || program === programFilter;
                const searchMatch = !searchTerm || search.includes(searchTerm);
                
                if (statusMatch && programMatch && searchMatch) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            // Show message if no results
            const tbody = document.querySelector('#submissions-table tbody');
            let noResultsRow = tbody.querySelector('.no-results-row');
            if (visibleCount === 0 && rows.length > 0) {
                if (!noResultsRow) {
                    noResultsRow = document.createElement('tr');
                    noResultsRow.className = 'no-results-row';
                    noResultsRow.innerHTML = '<td colspan="11" class="text-center py-4"><i class="fas fa-search fa-2x text-muted mb-2"></i><p class="text-muted">No submissions match your filters.</p></td>';
                    tbody.appendChild(noResultsRow);
                }
                noResultsRow.style.display = '';
            } else if (noResultsRow) {
                noResultsRow.style.display = 'none';
            }
        }
        
        function clearSubmissionFilters() {
            document.getElementById('filter-status').value = '';
            document.getElementById('filter-program').value = '';
            document.getElementById('search-submissions').value = '';
            filterSubmissions();
        }
        
        // View submission history
        function viewSubmissionHistory(submissionId) {
            const modal = new bootstrap.Modal(document.getElementById('submissionHistoryModal'));
            const content = document.getElementById('submission-history-content');
            
            content.innerHTML = '<div class="text-center py-4"><div class="spinner-border text-primary" role="status"></div><p class="mt-2 text-muted">Loading history...</p></div>';
            modal.show();
            
            fetch('get_submission_history.php?submission_id=' + submissionId)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.logs) {
                        let html = '<div class="timeline">';
                        if (data.logs.length === 0) {
                            html += '<div class="text-center py-4"><i class="fas fa-info-circle fa-2x text-muted mb-2"></i><p class="text-muted">No history available for this submission.</p></div>';
                        } else {
                            data.logs.forEach((log, index) => {
                                const roleColors = {
                                    'program_head': 'primary',
                                    'dean': 'success',
                                    'admin': 'info'
                                };
                                const roleColor = roleColors[log.role] || 'secondary';
                                
                                html += `<div class="d-flex mb-3">
                                    <div class="flex-shrink-0">
                                        <div class="bg-${roleColor} text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px;">
                                            <i class="fas fa-user"></i>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1 ms-3">
                                        <div class="card">
                                            <div class="card-body">
                                                <h6 class="card-title mb-1">
                                                    <span class="badge bg-${roleColor}">${log.role.replace('_', ' ').toUpperCase()}</span>
                                                    <span class="badge bg-secondary ms-2">${log.action.replace('_', ' ').toUpperCase()}</span>
                                                </h6>
                                                <p class="card-text mb-1"><strong>${log.performer_name}</strong></p>
                                                <small class="text-muted">${log.created_at}</small>
                                                ${log.notes ? `<p class="mt-2 mb-0"><em>${log.notes}</em></p>` : ''}
                                            </div>
                                        </div>
                                    </div>
                                </div>`;
                            });
                        }
                        html += '</div>';
                        content.innerHTML = html;
                    } else {
                        content.innerHTML = '<div class="alert alert-danger">Error loading history: ' + (data.message || 'Unknown error') + '</div>';
                    }
                })
                .catch(error => {
                    content.innerHTML = '<div class="alert alert-danger">Error loading history: ' + error.message + '</div>';
                });
        }
        
        // View submission subjects (opens existing view modal or shows in alert)
        function viewSubmissionSubjects(submissionId) {
            // Use existing view submission functionality
            const viewBtn = document.querySelector(`.view-submission-btn[data-submission-id="${submissionId}"]`);
            if (viewBtn) {
                viewBtn.click();
            } else {
                window.open('view_curriculum_submission.php?id=' + submissionId, '_blank');
            }
        }
        
    </script>
    
    <?php inject_session_js(); ?>
</body>
</html>
