<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/RegistrarStaff.php';
require_once '../classes/User.php';
require_once '../classes/Curriculum.php';
require_once '../classes/Section.php';

// Check if user is logged in and is registrar staff
if (!isLoggedIn() || !isRegistrarStaff()) {
    redirect('../public/login.php');
}

$registrarStaff = new RegistrarStaff();
$user = new User();
$curriculum = new Curriculum();
$section = new Section();

// Validate session against registrar_staff table
$current_staff_info = $registrarStaff->getStaffById($_SESSION['user_id']);
if (!$current_staff_info) {
    session_destroy();
    redirect('../public/login.php');
}

// Update session role if needed
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'registrar_staff') {
    $_SESSION['role'] = 'registrar_staff';
}

if (!isset($_SESSION['is_registrar_staff']) || $_SESSION['is_registrar_staff'] !== true) {
    $_SESSION['is_registrar_staff'] = true;
}

// Initialize database connection
$db = new Database();
$conn = $db->getConnection();

$all_users = $user->getAllUsers();
// Get students who have been passed by admission to registrar
// CRITICAL: Only students with passed_to_registrar = 1 in application_workflow should appear in pending students
// We explicitly check passed_to_registrar = 1 to ensure only admission-approved students appear
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

// Also create a lookup for ALL application_workflow records to verify students are actually passed
// This ensures we can check if a student exists in workflow and verify their status
$all_workflow_query = "SELECT user_id, passed_to_registrar FROM application_workflow";
$all_workflow_stmt = $conn->prepare($all_workflow_query);
$all_workflow_stmt->execute();
$all_workflow_records = $all_workflow_stmt->fetchAll(PDO::FETCH_ASSOC);
$workflow_status_lookup = [];
foreach ($all_workflow_records as $workflow_record) {
    $workflow_status_lookup[(int)$workflow_record['user_id']] = (int)$workflow_record['passed_to_registrar'];
}
$all_programs = $curriculum->getAllPrograms();
$all_sections = $section->getAllSections();
$program_lookup_by_name = [];
$program_lookup_by_code = [];
foreach ($all_programs as $program_item) {
    $program_lookup_by_name[strtolower(trim($program_item['program_name']))] = $program_item['id'];
    $program_lookup_by_code[strtolower(trim($program_item['program_code']))] = $program_item['id'];
}

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

// Get enrolled students using centralized helper for consistency
// Note: For listing multiple students, we still need to query enrolled_students directly
// but we ensure data is synced by using the helper when viewing individual students
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

// Next semester enrollment requests for Registrar review
// Registrar processes enrollments with status 'pending_registrar' (both regular and irregular after program head approval)
$next_semester_query = "SELECT nse.*, 
                               u.student_id, u.first_name, u.last_name, u.email,
                               s.section_name, s.section_type, s.year_level AS section_year_level, s.semester AS section_semester,
                               p.program_code, p.program_name,
                               (SELECT COUNT(*) FROM certificate_of_registration cor WHERE cor.enrollment_id = nse.id) as has_cor
                        FROM next_semester_enrollments nse
                        JOIN users u ON nse.user_id = u.id
                        LEFT JOIN sections s ON nse.selected_section_id = s.id
                        LEFT JOIN programs p ON s.program_id = p.id
                        WHERE nse.request_status = 'pending_registrar'
                        ORDER BY nse.created_at DESC";
$next_semester_stmt = $conn->prepare($next_semester_query);
$next_semester_stmt->execute();
$next_semester_requests = $next_semester_stmt->fetchAll(PDO::FETCH_ASSOC);
$pending_next_semester_requests = count($next_semester_requests);

$message = '';
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

// Get enrolled_students records to check year levels
$enrolled_students_lookup = [];
$enrolled_check_query = "SELECT user_id, year_level FROM enrolled_students WHERE user_id IS NOT NULL";
$enrolled_check_stmt = $conn->prepare($enrolled_check_query);
$enrolled_check_stmt->execute();
$enrolled_check_results = $enrolled_check_stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($enrolled_check_results as $enrolled_check) {
    if ($enrolled_check['user_id']) {
        $enrolled_students_lookup[(int)$enrolled_check['user_id']] = $enrolled_check['year_level'];
    }
}

// Count pending students - ONLY first year admissions passed by admission
// Requirements:
// 1. MUST have a record in application_workflow
// 2. MUST have passed_to_registrar = 1 (explicitly passed by admission)
// 3. Must be first year admission:
//    - Students with no enrolled_students record (new admissions), OR
//    - Students with enrolled_students record where year_level = '1st Year' and still pending
$pending_students = count(array_filter($all_users, function($u) use ($registrar_queue_lookup, $enrolled_students_lookup, $workflow_status_lookup) {
    if ($u['role'] != 'student' || ($u['enrollment_status'] ?? 'pending') != 'pending') {
        return false;
    }
    
    $user_id = (int)($u['id'] ?? 0);
    
    // CRITICAL: Must have a record in application_workflow AND passed_to_registrar must be 1
    // Check if student exists in workflow
    if (!isset($workflow_status_lookup[$user_id])) {
        // No workflow record = not passed by admission
        return false;
    }
    
    // Verify passed_to_registrar is explicitly 1
    if ($workflow_status_lookup[$user_id] !== 1) {
        // passed_to_registrar is 0 or NULL = not passed by admission
        return false;
    }
    
    // Double-check with registrar_queue_lookup (should match, but extra safety)
    if (!isset($registrar_queue_lookup[$user_id])) {
        return false;
    }
    
    // Must be first year admission
    // If student has no enrolled_students record, they're a new first year admission
    if (!isset($enrolled_students_lookup[$user_id])) {
        return true;
    }
    
    // If student has enrolled_students record, check if it's 1st Year
    $year_level = $enrolled_students_lookup[$user_id] ?? null;
    return $year_level === '1st Year';
}));

// Count enrolled students
$enrolled_students = count($all_enrolled_students);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrar Staff Dashboard - <?php echo SITE_NAME; ?></title>
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
            margin: 0;
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
        .card-body {
            padding: 15px !important;
        }
        .card-header {
            padding: 12px 15px !important;
        }
        .card-header h5 {
            font-size: 14px !important;
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
        .card {
            border: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border-radius: 15px;
        }
        .stat-card {
            background: #1e40af;
            color: white;
            border-radius: 15px;
        }
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
        .btn-action {
            border-radius: 20px;
            padding: 5px 15px;
            margin: 2px;
        }
        .clickable-row:hover {
            background-color: #f8f9fa !important;
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
                         onerror="this.style.display='none'; document.getElementById('registrarSidebarFallback').classList.remove('d-none');">
                    <i id="registrarSidebarFallback" class="fas fa-user-tie d-none" style="font-size: 24px; color: white;"></i>
                    <h4>Registrar Staff</h4>
                </div>
                <div class="sidebar-menu">
                    <nav class="nav flex-column">
                        <a href="dashboard.php#pending-students" class="nav-link active" data-section="pending-students">
                            <i class="fas fa-user-clock"></i>
                            <span>Pending Students</span>
                        </a>
                        <a href="dashboard.php#enrolled-students" class="nav-link" data-section="enrolled-students">
                            <i class="fas fa-user-graduate"></i>
                            <span>Enrolled Students</span>
                        </a>
                        <a href="dashboard.php#next-semester-enrollments" class="nav-link" data-section="next-semester-enrollments">
                            <i class="fas fa-calendar-check"></i>
                            <span>Next Semester Confirmations</span>
                            <?php if ($pending_next_semester_requests > 0): ?>
                                <span class="badge bg-warning text-dark ms-auto"><?php echo $pending_next_semester_requests; ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="review_adjustments.php" class="nav-link">
                            <i class="fas fa-exchange-alt"></i>
                            <span>Review Adjustments</span>
                        </a>
                        <a href="logout.php" class="nav-link">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </nav>
                </div>
                <div class="sidebar-user">
                    <h6>Welcome,</h6>
                    <h5><?php echo $_SESSION['first_name'] . ' ' . $_SESSION['last_name']; ?></h5>
                    <small>ID: <?php echo $_SESSION['staff_id'] ?? 'N/A'; ?></small>
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
                        <small>ID: <?php echo $_SESSION['staff_id'] ?? 'N/A'; ?></small>
                    </div>
                </div>
            </nav>
            
            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 p-3 main-content">
                <?php if ($message): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($message); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <!-- Pending Students Section -->
                <div id="pending-students" class="content-section">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h2>Pending Students</h2>
                        <span class="text-muted">Students awaiting enrollment approval</span>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-3">
                            <div class="card stat-card text-center">
                                <div class="card-body">
                                    <i class="fas fa-user-clock fa-3x mb-3"></i>
                                    <h3><?php echo $pending_students; ?></h3>
                                    <p class="mb-0">Pending Students</p>
                                </div>
                            </div>
                        </div>
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
                                    $user_id = (int)($student['id'] ?? 0);
                                    
                                    // CRITICAL: Must have a record in application_workflow AND passed_to_registrar must be 1
                                    // Check if student exists in workflow
                                    if (!isset($workflow_status_lookup[$user_id])) {
                                        continue; // No workflow record = not passed by admission
                                    }
                                    
                                    // Verify passed_to_registrar is explicitly 1
                                    if ($workflow_status_lookup[$user_id] !== 1) {
                                        continue; // passed_to_registrar is 0 or NULL = not passed by admission
                                    }
                                    
                                    // Double-check with registrar_queue_lookup (should match, but extra safety)
                                    if (!isset($registrar_queue_lookup[$user_id])) {
                                        continue; // Not in registrar queue = not passed by admission
                                    }
                                    
                                    // Must be pending student
                                    if ($student['role'] != 'student' 
                                        || ($student['enrollment_status'] ?? 'pending') != 'pending') {
                                        continue;
                                    }
                                    
                                    // Must be first year admission
                                    $is_first_year = false;
                                    if (!isset($enrolled_students_lookup[$user_id])) {
                                        // No enrolled_students record = new first year admission
                                        $is_first_year = true;
                                    } else {
                                        // Has enrolled_students record, check if 1st Year
                                        $year_level = $enrolled_students_lookup[$user_id] ?? null;
                                        $is_first_year = ($year_level === '1st Year');
                                    }
                                    
                                    if ($is_first_year):
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
                                                                <button type="button" class="btn btn-info btn-action btn-sm" data-action="view-applicant" data-user-id="<?php echo $student_id_attr; ?>">
                                                                    <i class="fas fa-user"></i> View Applicant
                                                                </button>
                                                                <button type="button" class="btn btn-outline-primary btn-action btn-sm" data-action="create-cor" data-user-id="<?php echo $student_id_attr; ?>">
                                                                    <i class="fas fa-file-alt"></i> Create COR
                                                                </button>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-5">
                                                            <!-- Status change buttons removed -->
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
                                            <p class="text-muted">No enrolled students yet.</p>
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
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Next Semester Enrollments Section -->
                <div id="next-semester-enrollments" class="content-section" style="display: none;">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <div>
                            <h2>Next Semester Confirmations</h2>
                            <span class="text-muted">Requests submitted by Program Heads awaiting registrar confirmation.</span>
                        </div>
                        <span class="badge bg-<?php echo $pending_next_semester_requests > 0 ? 'warning text-dark' : 'success'; ?> fs-6">
                            Pending: <?php echo $pending_next_semester_requests; ?>
                        </span>
                    </div>
                    
                    <?php if (empty($next_semester_requests)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-calendar-check fa-3x text-muted mb-3"></i>
                            <p class="text-muted">No next semester enrollment requests awaiting confirmation.</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Student ID</th>
                                        <th>Name</th>
                                        <th>Program / Section</th>
                                        <th>Target Term</th>
                                        <th>Status</th>
                                        <th>Submitted</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($next_semester_requests as $request): ?>
                                        <?php
                                            $review_url = add_session_to_url('../admin/review_next_semester.php?request_id=' . urlencode($request['id']));
                                            $status = strtolower($request['request_status']);
                                            $status_map = [
                                                'pending' => 'secondary',
                                                'under_review' => 'warning text-dark',
                                                'approved' => 'success',
                                                'rejected' => 'danger'
                                            ];
                                            $status_class = $status_map[$status] ?? 'secondary';
                                        ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($request['student_id']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></td>
                                            <td>
                                                <?php if (!empty($request['section_name'])): ?>
                                                    <div class="fw-semibold"><?php echo htmlspecialchars($request['section_name']); ?></div>
                                                    <small class="text-muted">
                                                        <?php echo htmlspecialchars(($request['program_code'] ?? 'Program') . ' • ' . ($request['section_year_level'] ?? '')); ?>
                                                    </small>
                                                <?php else: ?>
                                                    <span class="text-muted">No section assigned yet</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($request['target_academic_year']); ?><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($request['target_semester']); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $status_class; ?>">
                                                    <?php echo strtoupper($request['request_status']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('M j, Y g:i A', strtotime($request['created_at'])); ?></td>
                                            <td>
                                                <a href="<?php echo htmlspecialchars($review_url); ?>" 
                                                   class="btn btn-primary btn-sm"
                                                   style="text-decoration: none;"
                                                   rel="nofollow"
                                                   data-no-intercept="true">
                                                    <i class="fas fa-eye me-1"></i>Review
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
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
    
    <!-- Edit Documents Modal -->
    <div class="modal fade" id="editDocumentsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-file-alt me-2"></i>Edit Document Checklist</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="editDocumentsForm">
                    <div class="modal-body">
                        <input type="hidden" id="edit_doc_user_id" name="user_id">
                        <p class="text-muted mb-3" id="edit_doc_student_name"></p>
                        
                        <h6 class="mb-3">Required Documents</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="edit_id_pictures" name="id_pictures">
                                    <label class="form-check-label" for="edit_id_pictures">
                                        2x2 ID Pictures (4 pcs)
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="edit_psa_birth_certificate" name="psa_birth_certificate">
                                    <label class="form-check-label" for="edit_psa_birth_certificate">
                                        PSA Birth Certificate
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="edit_barangay_certificate" name="barangay_certificate">
                                    <label class="form-check-label" for="edit_barangay_certificate">
                                        Barangay Certificate of Residency
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="edit_voters_id" name="voters_id">
                                    <label class="form-check-label" for="edit_voters_id">
                                        Voter's ID or Registration Stub
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="edit_high_school_diploma" name="high_school_diploma">
                                    <label class="form-check-label" for="edit_high_school_diploma">
                                        High School Diploma
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="edit_sf10_form" name="sf10_form">
                                    <label class="form-check-label" for="edit_sf10_form">
                                        SF10 (Senior High School Permanent Record)
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="edit_form_138" name="form_138">
                                    <label class="form-check-label" for="edit_form_138">
                                        Form 138 (Report Card)
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="edit_good_moral" name="good_moral">
                                    <label class="form-check-label" for="edit_good_moral">
                                        Certificate of Good Moral Character
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <hr>
                        <h6 class="mb-3">Submission Status</h6>
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="edit_documents_submitted" name="documents_submitted">
                                    <label class="form-check-label" for="edit_documents_submitted">
                                        Original documents submitted in long brown envelope
                                    </label>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="edit_photocopies_submitted" name="photocopies_submitted">
                                    <label class="form-check-label" for="edit_photocopies_submitted">
                                        Photocopies submitted in separate envelope
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="edit_doc_notes" class="form-label">Notes</label>
                            <textarea class="form-control" id="edit_doc_notes" name="notes" rows="3" placeholder="Additional notes or comments..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>Save Changes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('active');
        }
        // Handle clickable rows for pending students
        document.addEventListener('DOMContentLoaded', function() {
            const clickableRows = document.querySelectorAll('.clickable-row');
            
            clickableRows.forEach(row => {
                row.addEventListener('click', function(e) {
                    // Don't trigger row click if clicking on buttons, selects, forms, or action buttons
                    if (e.target.tagName === 'BUTTON' || 
                        e.target.tagName === 'SELECT' || 
                        e.target.tagName === 'INPUT' ||
                        e.target.closest('button[data-action]') ||
                        e.target.closest('button') || 
                        e.target.closest('form') ||
                        e.target.closest('.action-row')) {
                        return;
                    }
                    
                    const studentId = this.getAttribute('data-student-id');
                    const actionRow = document.getElementById('actions-' + studentId);
                    const expandIcon = this.querySelector('.expand-icon');
                    
                    if (actionRow.style.display === 'none') {
                        document.querySelectorAll('.action-row').forEach(ar => {
                            ar.style.display = 'none';
                        });
                        document.querySelectorAll('.expand-icon').forEach(icon => {
                            icon.classList.remove('fa-chevron-up');
                            icon.classList.add('fa-chevron-down');
                        });
                        
                        actionRow.style.display = 'table-row';
                        expandIcon.classList.remove('fa-chevron-down');
                        expandIcon.classList.add('fa-chevron-up');
                    } else {
                        actionRow.style.display = 'none';
                        expandIcon.classList.remove('fa-chevron-up');
                        expandIcon.classList.add('fa-chevron-down');
                    }
                });
            });
            
            // Handle action buttons using event delegation
            document.addEventListener('click', function(e) {
                // Check if clicked element or parent is an action button
                const button = e.target.closest('button[data-action]');
                if (!button) return;
                
                const action = button.getAttribute('data-action');
                const userId = parseInt(button.getAttribute('data-user-id')) || 0;
                
                // Don't process if no user ID
                if (!userId) {
                    console.error('No user ID found for action:', action);
                    return;
                }
                
                // Prevent default behavior and stop propagation for action buttons
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                
                try {
                    switch(action) {
                        case 'view-applicant':
                            viewApplicantInfo(userId);
                            break;
                        case 'create-cor':
                            openCreateCOR(userId);
                            break;
                    }
                } catch (error) {
                    console.error('Error handling action:', error);
                    alert('An error occurred: ' + error.message);
                }
                
                return false;
            });
            
            // Handle assign section buttons in applicant modal
            document.addEventListener('click', function(e) {
                const assignBtn = e.target.closest('.assign-applicant-section-btn');
                if (assignBtn) {
                    e.preventDefault();
                    e.stopPropagation();
                    const sectionId = parseInt(assignBtn.getAttribute('data-section-id'));
                    const sectionName = assignBtn.getAttribute('data-section-name');
                    const userId = window.currentApplicantUserId;
                    
                    if (sectionId && userId) {
                        performApplicantSectionAssignment(userId, sectionId, sectionName);
                    }
                }
            });
        });

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

            // Fetch applicant info, document checklist, and uploaded documents
            Promise.all([
                fetch(`../admin/get_applicant_info.php?user_id=${userId}`).then(r => r.json()),
                fetch(`../admin/get_documents.php?user_id=${userId}`).then(r => r.json()).catch(() => ({})),
                fetch(`../admin/get_uploaded_documents.php?user_id=${userId}`).then(r => r.json()).catch(() => ({ success: false, documents: [] }))
            ])
                .then(([applicantData, documentsData, uploadedDocsData]) => {
                    if (!applicantData.success) {
                        throw new Error(applicantData.message || 'Unable to load applicant information.');
                    }
                    const applicant = applicantData.applicant || {};
                    const workflow = applicantData.workflow || {};
                    const documents = documentsData || {};
                    const uploadedDocs = uploadedDocsData.documents || [];
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
                                <h6 class="text-primary mb-3"><i class="fas fa-users-cog me-2"></i>Section Assignment</h6>
                                <div id="applicant_section_assignment">
                                    <div class="text-center py-3">
                                        <div class="spinner-border spinner-border-sm text-primary" role="status">
                                            <span class="visually-hidden">Loading...</span>
                                        </div>
                                        <p class="mt-2 text-muted small">Loading section information...</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    `;
                    
                    // Store preferred program for section reloading
                    window.currentApplicantPreferredProgram = applicant.preferred_program || '';
                    
                    // Load section assignment data after modal content is set
                    loadApplicantSectionAssignment(userId, window.currentApplicantPreferredProgram);
                })
                .catch(error => {
                    modalBody.innerHTML = `
                        <div class="alert alert-danger mb-0">
                            <i class="fas fa-exclamation-circle me-2"></i>${error.message}
                        </div>
                    `;
                });
        }

        // Load section assignment for applicant info modal
        function loadApplicantSectionAssignment(userId, preferredProgram) {
            const container = document.getElementById('applicant_section_assignment');
            if (!container) return;
            
            // Store current user ID for section assignment functions
            window.currentApplicantUserId = userId;
            
            // Show loading state
            container.innerHTML = `
                <div class="text-center py-3">
                    <div class="spinner-border spinner-border-sm text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-2 text-muted small">Loading section information...</p>
                </div>
            `;
            
            // Load current sections
            fetch(`../admin/get_student_section.php?user_id=${userId}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('Section data received for user', userId, ':', data); // Debug log
                    const currentSections = data.success && data.sections ? data.sections : [];
                    console.log('Current sections parsed:', currentSections); // Debug log
                    
                    if (currentSections.length === 0) {
                        // If no sections found, log warning
                        console.warn('No sections found for user:', userId, '- This may indicate the student did not select a section during registration or it was not saved.');
                    } else {
                        console.log('Found', currentSections.length, 'section(s) for user:', userId);
                        currentSections.forEach((sec, idx) => {
                            console.log(`  Section ${idx + 1}:`, sec.section_name, '- Status:', sec.enrollment_status || 'unknown');
                        });
                    }
                    
                    renderApplicantSectionAssignment(container, userId, preferredProgram, currentSections);
                })
                .catch(error => {
                    console.error('Error loading current sections for user', userId, ':', error);
                    // Show error message to user
                    container.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>Error loading section information:</strong> ${error.message}
                            <br><small>Please refresh and try again, or contact technical support if the issue persists.</small>
                        </div>
                    `;
                });
        }
        
        function renderApplicantSectionAssignment(container, userId, preferredProgram, currentSections) {
            // Get all sections data
            const allSections = <?php echo json_encode($all_sections ?? []); ?>;
            const allPrograms = <?php echo json_encode($all_programs ?? []); ?>;
            
            // Find preferred program ID
            let preferredProgramId = null;
            if (preferredProgram) {
                const preferredNormalized = preferredProgram.toLowerCase().trim();
                for (let program of allPrograms) {
                    if (program.program_name && program.program_name.toLowerCase().includes(preferredNormalized) ||
                        program.program_code && program.program_code.toLowerCase().includes(preferredNormalized)) {
                        preferredProgramId = program.id;
                        break;
                    }
                }
            }
            
            // Build current sections HTML
            // Show active sections (sections assigned during registration are now active)
            const activeSections = currentSections.filter(s => s.enrollment_status === 'active');
            
            let currentSectionsHtml = '';
            
            // Show active sections (including those assigned during registration)
            if (activeSections.length > 0) {
                currentSectionsHtml += '<div class="mb-3"><h6 class="text-success"><i class="fas fa-check-circle me-2"></i>Assigned Sections:</h6>';
                activeSections.forEach(section => {
                    currentSectionsHtml += `
                        <div class="alert alert-success d-flex justify-content-between align-items-center mb-2">
                            <div>
                                <strong>${section.section_name}</strong><br>
                                <small>${section.program_code} - ${section.year_level} - ${section.semester}</small>
                            </div>
                            <button class="btn btn-sm btn-danger" onclick="removeApplicantSectionAssignment(${userId}, ${section.section_id})">
                                <i class="fas fa-times"></i> Remove
                            </button>
                        </div>
                    `;
                });
                currentSectionsHtml += '</div>';
            } else {
                // If no active section found, show message
                currentSectionsHtml += '<div class="alert alert-warning mb-3">';
                currentSectionsHtml += '<i class="fas fa-exclamation-triangle me-2"></i><strong>No section found:</strong> The student has not been assigned a section. Please assign a section manually below.';
                currentSectionsHtml += '</div>';
            }
            
            // Build programs dropdown
            let programsOptions = '<option value="">All Programs</option>';
            allPrograms.forEach(program => {
                const selected = preferredProgramId && program.id == preferredProgramId ? 'selected' : '';
                programsOptions += `<option value="${program.id}" ${selected}>${program.program_code}</option>`;
            });
            
            // Preferred program notice
            let preferredNotice = '';
            if (preferredProgram && preferredProgramId) {
                preferredNotice = `<div class="alert alert-info mb-3">
                    <i class="fas fa-info-circle me-1"></i>Preferred program: <strong>${preferredProgram}</strong>
                </div>`;
            } else if (preferredProgram) {
                preferredNotice = `<div class="alert alert-warning mb-3">
                    <i class="fas fa-exclamation-triangle me-1"></i>No matching section found for preferred program "<strong>${preferredProgram}</strong>". Showing all programs.
                </div>`;
            }
            
            // If no sections at all (neither pending nor active), show a message
            if (currentSections.length === 0) {
                currentSectionsHtml = '<div class="alert alert-warning mb-3">';
                currentSectionsHtml += '<i class="fas fa-exclamation-triangle me-2"></i><strong>No section found:</strong> The student has not selected a section during registration. Please assign a section manually below.';
                currentSectionsHtml += '</div>';
            }
            
            container.innerHTML = `
                ${preferredNotice}
                ${currentSectionsHtml}
                <div class="card mb-3">
                    <div class="card-header bg-light">
                        <h6 class="mb-0"><i class="fas fa-filter me-2"></i>Filter Sections</h6>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <label class="form-label small">Program:</label>
                                <select class="form-select form-select-sm" id="applicant_filter_program" onchange="filterApplicantSections()">
                                    ${programsOptions}
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small">Year Level:</label>
                                <select class="form-select form-select-sm" id="applicant_filter_year" onchange="filterApplicantSections()">
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
                                <select class="form-select form-select-sm" id="applicant_filter_semester" onchange="filterApplicantSections()">
                                    <option value="">All Semesters</option>
                                    <option value="First Semester">First Semester</option>
                                    <option value="Second Semester">Second Semester</option>
                                    <option value="Summer">Summer</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small">Section Type:</label>
                                <select class="form-select form-select-sm" id="applicant_filter_type" onchange="filterApplicantSections()">
                                    <option value="">All Types</option>
                                    <option value="Morning">Morning</option>
                                    <option value="Afternoon">Afternoon</option>
                                    <option value="Evening">Evening</option>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-9">
                                <label class="form-label small">Search:</label>
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                                    <input type="text" class="form-control" id="applicant_search" 
                                           placeholder="Search by section name..." 
                                           onkeyup="filterApplicantSections()">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label small">&nbsp;</label>
                                <button class="btn btn-sm btn-outline-secondary w-100" onclick="clearApplicantFilters()">
                                    <i class="fas fa-times-circle me-1"></i>Clear Filters
                                </button>
                            </div>
                        </div>
                        <div class="mt-2">
                            <small class="text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                Showing <strong id="applicant_sections_count">0</strong> section(s)
                            </small>
                        </div>
                    </div>
                </div>
                <div id="applicant_sections_list" style="max-height: 400px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: 4px; padding: 10px;">
                    <p class="text-muted text-center">Loading sections...</p>
                </div>
            `;
            
            // Store sections data globally for filtering
            window.applicantSectionsData = allSections;
            // Store current user ID for button actions
            window.currentApplicantUserId = userId;
            
            // Filter and display sections
            filterApplicantSections();
        }
        
        function filterApplicantSections() {
            const programFilter = document.getElementById('applicant_filter_program')?.value || '';
            const yearFilter = document.getElementById('applicant_filter_year')?.value || '';
            const semesterFilter = document.getElementById('applicant_filter_semester')?.value || '';
            const typeFilter = document.getElementById('applicant_filter_type')?.value || '';
            const searchText = (document.getElementById('applicant_search')?.value || '').toLowerCase();
            const container = document.getElementById('applicant_sections_list');
            const countElement = document.getElementById('applicant_sections_count');
            
            if (!container || !window.applicantSectionsData) return;
            
            let filteredSections = window.applicantSectionsData.filter(section => {
                if (programFilter && section.program_id != programFilter) return false;
                if (yearFilter && section.year_level !== yearFilter) return false;
                if (semesterFilter && section.semester !== semesterFilter) return false;
                if (typeFilter && section.section_type !== typeFilter) return false;
                if (searchText && !section.section_name.toLowerCase().includes(searchText)) return false;
                return true;
            });
            
            // Get pending section IDs from global scope
            const pendingSectionIds = window.applicantPendingSectionIds || [];
            
            // Display sections
            let html = '';
            if (filteredSections.length === 0) {
                html = '<div class="text-center py-4"><i class="fas fa-search fa-3x text-muted mb-3"></i><p class="text-muted mb-0">No sections found matching the filters.</p></div>';
            } else {
                filteredSections.forEach(section => {
                    const capacity = (section.max_capacity || 0) - (section.current_enrolled || 0);
                    const isFull = capacity <= 0;
                    const isAlmostFull = !isFull && (section.current_enrolled / section.max_capacity) >= 0.9;
                    
                    // Highlight based on capacity only
                    const borderClass = isFull ? 'border-danger' : (isAlmostFull ? 'border-warning' : '');
                    
                    html += '<div class="card mb-2 ' + borderClass + '">';
                    html += '<div class="card-body p-3">';
                    html += '<div class="row align-items-center">';
                    html += '<div class="col-md-7">';
                    html += '<h6 class="mb-1"><i class="fas fa-users me-2 text-primary"></i>' + section.section_name;
                    if (isFull) {
                        html += ' <span class="badge bg-danger ms-2">FULL</span>';
                    } else if (isAlmostFull) {
                        html += ' <span class="badge bg-warning ms-2">Almost Full</span>';
                    }
                    html += '</h6>';
                    html += '<small class="text-muted"><strong>' + section.program_code + '</strong> | ' + section.year_level + ' | ' + section.semester + '</small><br>';
                    html += '<small class="text-muted"><span class="badge bg-info">' + section.section_type + '</span> ' + section.academic_year + '</small>';
                    html += '</div>';
                    html += '<div class="col-md-3">';
                    html += '<small class="d-block"><strong>Capacity:</strong></small>';
                    html += '<span class="' + (isFull ? 'text-danger' : (isAlmostFull ? 'text-warning' : 'text-success')) + '"><strong>' + section.current_enrolled + '/' + section.max_capacity + '</strong></span>';
                    if (!isFull) {
                        html += '<br><small class="text-muted">' + capacity + ' slot(s) available</small>';
                    }
                    html += '</div>';
                    html += '<div class="col-md-2 text-end">';
                    if (isFull) {
                        html += '<button class="btn btn-sm btn-secondary" disabled><i class="fas fa-ban"></i> Full</button>';
                    } else {
                        const escapedName = section.section_name.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                        html += '<button class="btn btn-sm btn-primary assign-applicant-section-btn" data-section-id="' + section.id + '" data-section-name="' + escapedName + '">';
                        html += '<i class="fas fa-plus"></i> Assign</button>';
                    }
                    html += '</div>';
                    html += '</div>';
                    html += '</div>';
                    html += '</div>';
                });
            }
            
            container.innerHTML = html;
            if (countElement) {
                countElement.textContent = filteredSections.length;
            }
        }
        
        function clearApplicantFilters() {
            document.getElementById('applicant_filter_program').value = '';
            document.getElementById('applicant_filter_year').value = '';
            document.getElementById('applicant_filter_semester').value = '';
            document.getElementById('applicant_filter_type').value = '';
            document.getElementById('applicant_search').value = '';
            filterApplicantSections();
        }
        
        // Activate pending section (student's selection from registration)
        function activatePendingSection(userId, sectionId, sectionName) {
            if (!userId) {
                alert('Error: No student selected');
                return;
            }
            
            if (!confirm('Activate the student\'s selected section "' + sectionName + '"?\n\nThis will activate the section assignment that the student chose during registration.')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('user_id', userId);
            formData.append('section_id', sectionId);
            formData.append('activate_pending', '1'); // Flag to activate pending enrollment
            
            fetch('../admin/assign_section.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Section activated successfully! The student\'s selected section is now active.');
                    const container = document.getElementById('applicant_section_assignment');
                    if (container) {
                        const preferredProgram = window.currentApplicantPreferredProgram || '';
                        loadApplicantSectionAssignment(userId, preferredProgram);
                    }
                    // Reload the page to refresh the pending students list
                    setTimeout(() => {
                        location.reload();
                    }, 1000);
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error activating section: ' + error);
            });
        }
        
        function performApplicantSectionAssignment(userId, sectionId, sectionName) {
            if (!userId) {
                alert('Error: No student selected');
                return;
            }
            
            if (!confirm('Assign section "' + sectionName + '" to this student?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('user_id', userId);
            formData.append('section_id', sectionId);
            
            fetch('../admin/assign_section.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Section assigned successfully!');
                    const container = document.getElementById('applicant_section_assignment');
                    if (container) {
                        const preferredProgram = window.currentApplicantPreferredProgram || '';
                        loadApplicantSectionAssignment(userId, preferredProgram);
                    }
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                alert('Error assigning section: ' + error);
            });
        }

        function removeApplicantSectionAssignment(userId, sectionId) {
            if (!confirm('Remove this section assignment?')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('user_id', userId);
            formData.append('section_id', sectionId);
            
            fetch('../admin/remove_section.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Section removed successfully!');
                    const container = document.getElementById('applicant_section_assignment');
                    if (container) {
                        const preferredProgram = window.currentApplicantPreferredProgram || '';
                        loadApplicantSectionAssignment(userId, preferredProgram);
                    }
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error removing section. Please try again.');
            });
        }

        function openCreateCOR(userId) {
            if (!userId || userId <= 0) {
                alert('Unable to determine the selected student.');
                return false;
            }
            try {
                const url = `../admin/generate_cor.php?user_id=${encodeURIComponent(userId)}`;
                console.log('Opening COR page for user ID:', userId, 'URL:', url);
                const newWindow = window.open(url, '_blank');
                if (!newWindow || newWindow.closed || typeof newWindow.closed === 'undefined') {
                    alert('Popup was blocked. Please allow popups for this site and try again.');
                    return false;
                }
                // Focus the new window
                if (newWindow.focus) {
                    newWindow.focus();
                }
                return false;
            } catch (error) {
                console.error('Error opening COR page:', error);
                alert('Error opening COR page: ' + error.message);
                return false;
            }
        }
        
        // Section Navigation Functions
        function showSection(sectionId, event) {
            if (event) {
                event.preventDefault();
                event.stopPropagation();
            }
            
            // Hide all sections
            document.querySelectorAll('.content-section').forEach(section => {
                section.style.display = 'none';
            });
            
            // Update nav link active states
            const navLinks = document.querySelectorAll('.nav-link');
            navLinks.forEach(link => {
                link.classList.remove('active');
            });
            
            // Add active class to the correct link
            document.querySelectorAll('.nav-link[data-section]').forEach(link => {
                const linkSection = link.getAttribute('data-section');
                if (linkSection === sectionId) {
                    link.classList.add('active');
                }
            });
            
            // Show target section
            const finalSection = document.getElementById(sectionId);
            if (finalSection) {
                finalSection.style.display = 'block';
            }
            
            // Update URL hash
            if (window.history && window.history.replaceState) {
                const baseUrl = window.location.pathname + window.location.search;
                window.history.replaceState(null, null, baseUrl + '#' + sectionId);
            } else {
                window.location.hash = sectionId;
            }
            
            return false;
        }
        
        // Handle hash navigation on page load and hash changes
        function handleHashNavigation() {
            const hash = window.location.hash ? window.location.hash.substring(1).trim() : '';
            const validSections = ['pending-students', 'enrolled-students', 'next-semester-enrollments'];
            
            let sectionToShow = 'pending-students'; // default
            
            if (hash && validSections.includes(hash)) {
                sectionToShow = hash;
            }
            
            showSection(sectionToShow);
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
            
            // Fetch enrolled student info, sections, CORs, and document checklist
            Promise.all([
                fetch(`../admin/get_enrolled_student_info.php?user_id=${userId}`).then(r => r.json()).catch(() => ({ success: false, message: 'Error loading student info' })),
                fetch(`../admin/get_student_section.php?user_id=${userId}`).then(r => r.json()).catch(() => ({ success: false, sections: [] })),
                fetch(`../admin/get_user_cors.php?user_id=${userId}`).then(r => r.json()).catch(() => ({ success: false, cors: [] })),
                fetch(`../admin/get_enrolled_student.php?user_id=${userId}`).then(r => r.json()).catch(() => ({})),
                fetch(`../admin/get_documents.php?user_id=${userId}`).then(r => r.json()).catch(() => ({}))
            ])
                .then(([studentData, sectionsData, corsData, enrolledData, documentsData]) => {
                    if (!studentData.success) {
                        throw new Error(studentData.message || 'Unable to load student information.');
                    }
                    const student = studentData.student || {};
                    const sections = sectionsData.success ? sectionsData.sections : [];
                    const cors = corsData.success ? corsData.cors : [];
                    const documents = documentsData || {};
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
                        <div class="row g-4 mt-3">
                            <div class="col-12">
                                <hr>
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="text-primary mb-0"><i class="fas fa-file-alt me-2"></i>Document Checklist</h6>
                                    <button class="btn btn-sm btn-primary" onclick="editDocuments(${userId}, '${fullName.replace(/'/g, "\\'")}')">
                                        <i class="fas fa-edit me-1"></i>Edit Documents
                                    </button>
                                </div>
                                ${generateDocumentChecklistHTML(documents)}
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
        
        // Handle action buttons
        document.addEventListener('click', function(e) {
            // Check for action buttons
            const actionButton = e.target.closest('button[data-action]');
            if (actionButton) {
                const action = actionButton.getAttribute('data-action');
                const userId = parseInt(actionButton.getAttribute('data-user-id')) || 0;
                
                if (action === 'view-enrolled-student' && userId > 0) {
                    e.preventDefault();
                    e.stopPropagation();
                    const studentName = actionButton.getAttribute('data-student-name') || 'Student';
                    viewEnrolledStudent(userId, studentName);
                    return false;
                }
            }
        }, true);
        
        // Handle browser back/forward buttons
        window.addEventListener('hashchange', function() {
            handleHashNavigation();
        });
        
        // Initialize navigation on page load
        document.addEventListener('DOMContentLoaded', function() {
            handleHashNavigation();
            
            // Handle ONLY navigation links with data-section attribute
            // All other links work normally without any interference
            document.querySelectorAll('a[data-section]').forEach(function(navLink) {
                navLink.addEventListener('click', function(e) {
                    const sectionId = this.getAttribute('data-section');
                    if (sectionId) {
                        e.preventDefault();
                        e.stopPropagation();
                        showSection(sectionId, e);
                        return false;
                    }
                });
            });
            
            // Explicitly ensure review links are NOT intercepted
            // Add a handler that explicitly allows navigation for links with data-no-intercept
            document.querySelectorAll('a[data-no-intercept]').forEach(function(link) {
                link.addEventListener('click', function(e) {
                    // Don't prevent default - allow normal navigation
                    console.log('Review link clicked, allowing navigation to:', this.href);
                    // Explicitly navigate to ensure it works
                    window.location.href = this.href;
                    return false; // Prevent default to use our explicit navigation
                }, false); // Use bubble phase, don't capture
            });
        });
        
        
        // Generate document checklist HTML
        function generateDocumentChecklistHTML(documents) {
            const documentList = [
                { key: 'id_pictures', label: '2x2 ID Pictures (4 pcs)', icon: 'fa-id-card' },
                { key: 'psa_birth_certificate', label: 'PSA Birth Certificate', icon: 'fa-certificate' },
                { key: 'barangay_certificate', label: 'Barangay Certificate of Residency', icon: 'fa-home' },
                { key: 'voters_id', label: 'Voter\'s ID or Registration Stub', icon: 'fa-vote-yea' },
                { key: 'high_school_diploma', label: 'High School Diploma', icon: 'fa-graduation-cap' },
                { key: 'sf10_form', label: 'SF10 (Senior High School Permanent Record)', icon: 'fa-file-alt' },
                { key: 'form_138', label: 'Form 138 (Report Card)', icon: 'fa-file-alt' },
                { key: 'good_moral', label: 'Certificate of Good Moral Character', icon: 'fa-certificate' }
            ];
            
            let html = '<div class="list-group">';
            documentList.forEach(doc => {
                const isChecked = documents[doc.key] == 1 || documents[doc.key] === true || documents[doc.key] === '1';
                html += `
                    <div class="list-group-item">
                        <div class="d-flex justify-content-between align-items-center">
                            <div class="d-flex align-items-center">
                                <i class="fas ${doc.icon} text-primary me-3"></i>
                                <span>${doc.label}</span>
                            </div>
                            <span class="badge ${isChecked ? 'bg-success' : 'bg-secondary'}">
                                <i class="fas ${isChecked ? 'fa-check-circle' : 'fa-times-circle'} me-1"></i>
                                ${isChecked ? 'Passed' : 'Not Passed'}
                            </span>
                        </div>
                    </div>
                `;
            });
            html += '</div>';
            
            if (documents.documents_submitted || documents.photocopies_submitted) {
                html += '<div class="alert alert-info mt-3 mb-0">';
                html += '<strong><i class="fas fa-info-circle me-2"></i>Submission Status:</strong><ul class="mb-0 mt-2">';
                if (documents.documents_submitted) {
                    html += '<li><i class="fas fa-check-circle text-success me-2"></i>Original documents submitted</li>';
                }
                if (documents.photocopies_submitted) {
                    html += '<li><i class="fas fa-check-circle text-success me-2"></i>Photocopies submitted</li>';
                }
                html += '</ul></div>';
            }
            
            if (documents.notes) {
                html += `<div class="alert alert-warning mt-3 mb-0"><strong><i class="fas fa-sticky-note me-2"></i>Notes:</strong><p class="mb-0 mt-2">${documents.notes}</p></div>`;
            }
            
            return html;
        }
        
        // Edit documents function
        function editDocuments(userId, studentName) {
            document.getElementById('edit_doc_user_id').value = userId;
            document.getElementById('edit_doc_student_name').textContent = 'Student: ' + studentName;
            
            // Fetch current document checklist
            fetch(`../admin/get_documents.php?user_id=${userId}`)
                .then(response => response.json())
                .catch(() => ({}))
                .then(documents => {
                    // Set checkbox values
                    document.getElementById('edit_id_pictures').checked = documents.id_pictures == 1;
                    document.getElementById('edit_psa_birth_certificate').checked = documents.psa_birth_certificate == 1;
                    document.getElementById('edit_barangay_certificate').checked = documents.barangay_certificate == 1;
                    document.getElementById('edit_voters_id').checked = documents.voters_id == 1;
                    document.getElementById('edit_high_school_diploma').checked = documents.high_school_diploma == 1;
                    document.getElementById('edit_sf10_form').checked = documents.sf10_form == 1;
                    document.getElementById('edit_form_138').checked = documents.form_138 == 1;
                    document.getElementById('edit_good_moral').checked = documents.good_moral == 1;
                    document.getElementById('edit_documents_submitted').checked = documents.documents_submitted == 1;
                    document.getElementById('edit_photocopies_submitted').checked = documents.photocopies_submitted == 1;
                    document.getElementById('edit_doc_notes').value = documents.notes || '';
                    
                    // Show modal
                    const modal = new bootstrap.Modal(document.getElementById('editDocumentsModal'));
                    modal.show();
                });
        }
        
        // Handle form submission
        document.getElementById('editDocumentsForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('ajax_request', '1');
            
            fetch('../admin/update_documents.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Document checklist updated successfully!');
                    bootstrap.Modal.getInstance(document.getElementById('editDocumentsModal')).hide();
                    // Reload the student info to show updated checklist
                    const userId = document.getElementById('edit_doc_user_id').value;
                    const studentName = document.getElementById('edit_doc_student_name').textContent.replace('Student: ', '');
                    viewEnrolledStudent(userId, studentName);
                } else {
                    alert('Error: ' + (data.message || 'Failed to update document checklist'));
                }
            })
            .catch(error => {
                alert('Error updating document checklist: ' + error);
            });
        });
    </script>
</body>
</html>

