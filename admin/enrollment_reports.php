<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/Admin.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('../public/login.php');
}

$admin = new Admin();

$conn = (new Database())->getConnection();
$user_id = $_SESSION['user_id'];

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $report_id = $_POST['report_id'] ?? null;
    
    if ($action === 'send_to_dean' && $report_id) {
        try {
            $update_sql = "UPDATE enrollment_reports SET status = 'pending' WHERE id = :report_id AND status = 'draft'";
            $update_stmt = $conn->prepare($update_sql);
            $update_stmt->bindParam(':report_id', $report_id);
            $update_stmt->execute();
            
            $_SESSION['message'] = 'Report sent to Dean successfully!';
            redirect('admin/enrollment_reports.php');
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Error sending report: ' . $e->getMessage();
        }
    } elseif ($action === 'delete' && $report_id) {
        try {
            $delete_sql = "DELETE FROM enrollment_reports WHERE id = :report_id AND status = 'draft'";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bindParam(':report_id', $report_id);
            $delete_stmt->execute();
            
            $_SESSION['message'] = 'Draft report deleted successfully.';
            redirect('admin/enrollment_reports.php');
        } catch (PDOException $e) {
            $_SESSION['error'] = 'Error deleting report: ' . $e->getMessage();
        }
    }
}

// Handle report generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate') {
    $report_title = trim($_POST['report_title'] ?? '');
    $program_code = trim($_POST['program_code'] ?? '');
    $academic_year = trim($_POST['academic_year'] ?? '');
    $semester = trim($_POST['semester'] ?? '');
    
    try {
        // Build enrollment query based on filters
        // Join through section_enrollments and sections to get program info
        $enrollment_query = "SELECT 
                                es.*, 
                                u.first_name, u.last_name, u.email, u.phone, u.student_id,
                                p.program_name, p.program_code,
                                s.section_name
                            FROM enrolled_students es
                            LEFT JOIN users u ON es.user_id = u.id
                            LEFT JOIN section_enrollments se ON es.user_id = se.user_id AND se.status = 'active'
                            LEFT JOIN sections s ON se.section_id = s.id
                            LEFT JOIN programs p ON s.program_id = p.id
                            WHERE 1=1";
        
        $params = [];
        
        if (!empty($program_code) && $program_code !== 'all') {
            $enrollment_query .= " AND p.program_code = :program_code";
            $params[':program_code'] = $program_code;
        }
        
        if (!empty($academic_year) && $academic_year !== 'all') {
            $enrollment_query .= " AND es.academic_year = :academic_year";
            $params[':academic_year'] = $academic_year;
        }
        
        if (!empty($semester) && $semester !== 'all') {
            $enrollment_query .= " AND es.semester = :semester";
            $params[':semester'] = $semester;
        }
        
        $enrollment_query .= " GROUP BY es.id ORDER BY es.enrolled_date DESC";
        
        $enrollment_stmt = $conn->prepare($enrollment_query);
        foreach ($params as $key => $value) {
            $enrollment_stmt->bindValue($key, $value);
        }
        $enrollment_stmt->execute();
        $enrollments = $enrollment_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Calculate Demographics and Analytics
        $analytics = [
            'total_enrollments' => count($enrollments),
            'program_distribution' => [],
            'year_level_distribution' => [],
            'student_type_distribution' => [],
            'section_distribution' => [],
            'enrollment_by_date' => [],
            'program_stats' => []
        ];
        
        foreach ($enrollments as $enrollment) {
            // Program distribution
            $prog = $enrollment['program_code'] ?? 'Unknown';
            $analytics['program_distribution'][$prog] = ($analytics['program_distribution'][$prog] ?? 0) + 1;
            
            // Year level distribution
            $year = $enrollment['year_level'] ?? 'Unknown';
            $analytics['year_level_distribution'][$year] = ($analytics['year_level_distribution'][$year] ?? 0) + 1;
            
            // Student type distribution
            $type = $enrollment['student_type'] ?? 'Regular';
            $analytics['student_type_distribution'][$type] = ($analytics['student_type_distribution'][$type] ?? 0) + 1;
            
            // Section distribution
            $section = $enrollment['section_name'] ?? 'No Section';
            $analytics['section_distribution'][$section] = ($analytics['section_distribution'][$section] ?? 0) + 1;
            
            // Enrollment by date
            $date = date('Y-m-d', strtotime($enrollment['enrolled_date']));
            $analytics['enrollment_by_date'][$date] = ($analytics['enrollment_by_date'][$date] ?? 0) + 1;
        }
        
        // Program statistics
        foreach ($analytics['program_distribution'] as $prog_code => $count) {
            $analytics['program_stats'][] = [
                'program_code' => $prog_code,
                'count' => $count,
                'percentage' => round(($count / $analytics['total_enrollments']) * 100, 2)
            ];
        }
        
        // Generate text report data
        $report_data_text = "ENROLLMENT REPORT\n";
        $report_data_text .= "=================\n\n";
        $report_data_text .= "Generated: " . date('Y-m-d H:i:s') . "\n";
        $report_data_text .= "Generated By: " . $_SESSION['first_name'] . ' ' . $_SESSION['last_name'] . "\n";
        $report_data_text .= "Report Title: $report_title\n\n";
        
        if (!empty($program_code) && $program_code !== 'all') {
            $report_data_text .= "Program: $program_code\n";
        }
        if (!empty($academic_year) && $academic_year !== 'all') {
            $report_data_text .= "Academic Year: $academic_year\n";
        }
        if (!empty($semester) && $semester !== 'all') {
            $report_data_text .= "Semester: $semester\n";
        }
        
        $report_data_text .= "\n";
        $report_data_text .= "Total Enrollments: " . $analytics['total_enrollments'] . "\n\n";
        
        $report_data_text .= "PROGRAM DISTRIBUTION\n";
        $report_data_text .= "====================\n";
        foreach ($analytics['program_distribution'] as $prog => $count) {
            $report_data_text .= "$prog: $count (" . round(($count / $analytics['total_enrollments']) * 100, 2) . "%)\n";
        }
        
        $report_data_text .= "\nYEAR LEVEL DISTRIBUTION\n";
        $report_data_text .= "=======================\n";
        foreach ($analytics['year_level_distribution'] as $year => $count) {
            $report_data_text .= "$year: $count\n";
        }
        
        $report_data_text .= "\nENROLLMENT DETAILS\n";
        $report_data_text .= "==================\n\n";
        
        foreach ($enrollments as $idx => $enrollment) {
            $report_data_text .= ($idx + 1) . ". ";
            $report_data_text .= $enrollment['student_id'] ?? 'N/A';
            $report_data_text .= " - ";
            $report_data_text .= $enrollment['first_name'] . ' ' . $enrollment['last_name'];
            $report_data_text .= "\n   Program: " . ($enrollment['program_name'] ?? 'N/A');
            $report_data_text .= "\n   Year Level: " . ($enrollment['year_level'] ?? 'N/A');
            $report_data_text .= "\n   Section: " . ($enrollment['section_name'] ?? 'N/A');
            $report_data_text .= "\n   Enrolled: " . date('M d, Y', strtotime($enrollment['enrolled_date']));
            $report_data_text .= "\n\n";
        }
        
        // Create comprehensive report data (JSON for analytics, text for display)
        $report_data = json_encode([
            'text' => $report_data_text,
            'analytics' => $analytics,
            'enrollments' => $enrollments,
            'metadata' => [
                'generated_at' => date('Y-m-d H:i:s'),
                'generated_by' => $_SESSION['first_name'] . ' ' . $_SESSION['last_name'],
                'report_title' => $report_title,
                'filters' => [
                    'program_code' => $program_code !== 'all' ? $program_code : null,
                    'academic_year' => $academic_year !== 'all' ? $academic_year : null,
                    'semester' => $semester !== 'all' ? $semester : null
                ]
            ]
        ]);
        
        // Check if enrollment_reports table exists and has required columns
        $table_check = $conn->query("SHOW TABLES LIKE 'enrollment_reports'");
        if ($table_check->rowCount() == 0) {
            throw new Exception('enrollment_reports table does not exist. Please run the dean system migration first.');
        }
        
        // Check which columns exist and their NULL constraints
        $columns_check = $conn->query("SHOW COLUMNS FROM enrollment_reports");
        $existing_columns = [];
        $column_info = [];
        while ($col = $columns_check->fetch(PDO::FETCH_ASSOC)) {
            $existing_columns[] = $col['Field'];
            $column_info[$col['Field']] = [
                'null' => $col['Null'] === 'YES',
                'default' => $col['Default']
            ];
        }
        
        // Build INSERT statement based on available columns
        $insert_fields = ['report_title', 'report_data', 'generated_by', 'status'];
        $insert_values = [':report_title', ':report_data', ':generated_by', "'draft'"];
        $bind_params = [];
        
        // Handle program_code
        if (in_array('program_code', $existing_columns)) {
            $program_code_val = (!empty($program_code) && $program_code !== 'all') ? $program_code : null;
            // Only include if column allows NULL or we have a value
            if ($column_info['program_code']['null'] || $program_code_val !== null) {
                $insert_fields[] = 'program_code';
                $insert_values[] = ':program_code';
                $bind_params[':program_code'] = $program_code_val;
            }
        }
        
        // Handle academic_year
        if (in_array('academic_year', $existing_columns)) {
            $academic_year_val = (!empty($academic_year) && $academic_year !== 'all') ? $academic_year : 'All Years';
            // If column doesn't allow NULL, use default value
            if (!$column_info['academic_year']['null'] && $academic_year_val === null) {
                $academic_year_val = 'All Years';
            }
            $insert_fields[] = 'academic_year';
            $insert_values[] = ':academic_year';
            $bind_params[':academic_year'] = $academic_year_val;
        }
        
        // Handle semester
        if (in_array('semester', $existing_columns)) {
            $semester_val = (!empty($semester) && $semester !== 'all') ? $semester : null;
            // Only include if column allows NULL or we have a value
            if ($column_info['semester']['null'] || $semester_val !== null) {
                $insert_fields[] = 'semester';
                $insert_values[] = ':semester';
                $bind_params[':semester'] = $semester_val;
            }
        }
        
        // Insert report into database
        $insert_sql = "INSERT INTO enrollment_reports (" . implode(', ', $insert_fields) . ") 
                      VALUES (" . implode(', ', $insert_values) . ")";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bindParam(':report_title', $report_title);
        $insert_stmt->bindParam(':report_data', $report_data);
        $insert_stmt->bindParam(':generated_by', $user_id);
        
        // Bind optional parameters
        foreach ($bind_params as $param => $value) {
            $insert_stmt->bindValue($param, $value);
        }
        
        $insert_stmt->execute();
        $report_id = $conn->lastInsertId();
        
        $_SESSION['message'] = 'Enrollment report generated successfully. You can now review and send it to the Dean.';
        redirect('admin/enrollment_reports.php');
    } catch (PDOException $e) {
        $_SESSION['error'] = 'Error generating report: ' . $e->getMessage();
    }
}

// Get existing reports
$reports = [];
try {
    $reports_query = "SELECT er.*, 
                           a.first_name, a.last_name,
                           dean.first_name as dean_fname, dean.last_name as dean_lname
                    FROM enrollment_reports er
                    LEFT JOIN admins a ON er.generated_by = a.id
                    LEFT JOIN admins dean ON er.reviewed_by = dean.id
                    ORDER BY 
                        CASE er.status 
                            WHEN 'draft' THEN 1 
                            WHEN 'pending' THEN 2 
                            WHEN 'acknowledged' THEN 3 
                            ELSE 4 
                        END,
                        er.generated_at DESC";
    $reports_stmt = $conn->prepare($reports_query);
    $reports_stmt->execute();
    $reports = $reports_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table doesn't exist yet - show empty array
    $reports = [];
    if (strpos($e->getMessage(), "doesn't exist") !== false || 
        strpos($e->getMessage(), "Unknown column") !== false) {
        $_SESSION['db_warning'] = 'Enrollment reports table not found. Please run the dean system migration first.';
    }
}

// Get filter options
$programs_query = "SELECT * FROM programs ORDER BY program_code";
$programs_stmt = $conn->query($programs_query);
$programs = $programs_stmt->fetchAll(PDO::FETCH_ASSOC);

$years_query = "SELECT DISTINCT academic_year FROM enrolled_students ORDER BY academic_year DESC";
$years_stmt = $conn->query($years_query);
$academic_years = $years_stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enrollment Reports - Admin Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
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
            padding: 1.5rem;
            transition: all 0.3s ease;
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
            margin-bottom: 1.5rem;
        }
        .card-header {
            background: #1e40af;
            color: white;
            border-radius: 15px 15px 0 0 !important;
            padding: 12px 15px !important;
        }
        .table-compact {
            font-size: 0.85rem;
        }
        .table-compact th {
            background: #f8fafc;
            padding: 0.6rem;
            font-weight: 600;
        }
        .table-compact td {
            padding: 0.6rem;
        }
        .content-header {
            background: #fff;
            padding: 1rem 1.5rem;
            margin: -1.5rem -1.5rem 1.5rem -1.5rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        /* Ensure modals render properly and don't float */
        .modal {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            z-index: 1055 !important;
            display: none;
            width: 100%;
            height: 100%;
            overflow-x: hidden;
            overflow-y: auto;
            outline: 0;
        }
        
        .modal-dialog {
            position: relative;
            width: auto;
            margin: 0.5rem;
            pointer-events: none;
        }
        
        .modal-content {
            position: relative;
            display: flex;
            flex-direction: column;
            width: 100%;
            pointer-events: auto;
            background-color: #fff;
            background-clip: padding-box;
            border: 1px solid rgba(0, 0, 0, 0.2);
            border-radius: 0.3rem;
            outline: 0;
        }
        
        .modal-footer {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: flex-end;
            padding: 0.75rem;
            border-top: 1px solid #dee2e6;
            border-bottom-right-radius: calc(0.3rem - 1px);
            border-bottom-left-radius: calc(0.3rem - 1px);
        }
        
        /* Ensure modals are hidden by default */
        .modal:not(.show) {
            display: none !important;
        }
        
        /* Prevent modal buttons from floating outside */
        .modal-footer {
            position: relative !important;
            display: flex !important;
        }
        
        /* Hide any elements that might be floating at bottom */
        body > .btn:not([data-bs-toggle]):not(.modal-footer .btn) {
            display: none !important;
        }
        
        /* Ensure table structure is preserved */
        table tbody tr {
            position: relative;
        }
        
        /* Hide any duplicate buttons outside their containers */
        .table-responsive + .btn,
        .card-body > .btn:not([data-bs-toggle]):not(.btn-group .btn) {
            display: none !important;
        }
        .content-header h4 {
            margin: 0;
            font-size: 1.25rem;
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
                         onerror="this.style.display='none'; document.getElementById('enrollmentReportsSidebarFallback').classList.remove('d-none');">
                    <i id="enrollmentReportsSidebarFallback" class="fas fa-cog d-none" style="font-size: 24px; color: white;"></i>
                    <h4>Admin Panel</h4>
                </div>
                
                <div class="sidebar-menu">
                    <nav class="nav flex-column">
                        <a href="<?php echo add_session_to_url('dashboard.php'); ?>" class="nav-link">
                            <i class="fas fa-home"></i>
                            <span>Dashboard</span>
                        </a>
                        
                        <div class="nav-item has-dropdown">
                            <a href="#" class="nav-link" onclick="toggleDropdown(this); return false;">
                                <i class="fas fa-users"></i>
                                <span>Students</span>
                            </a>
                            <div class="submenu">
                                <a href="<?php echo add_session_to_url('dashboard.php'); ?>#pending-students" class="nav-link">
                                    <span>Pending Students</span>
                                </a>
                                <a href="<?php echo add_session_to_url('dashboard.php'); ?>#enrolled-students" class="nav-link">
                                    <span>Enrolled Students</span>
                                </a>
                            </div>
                        </div>
                        
                        <a href="<?php echo add_session_to_url('dashboard.php'); ?>#sections" class="nav-link">
                            <i class="fas fa-users-class"></i>
                            <span>Sections</span>
                        </a>
                        
                        <div class="nav-item has-dropdown">
                            <a href="#" class="nav-link" onclick="toggleDropdown(this); return false;">
                                <i class="fas fa-graduation-cap"></i>
                                <span>Curriculum</span>
                            </a>
                            <div class="submenu">
                                <a href="<?php echo add_session_to_url('dashboard.php'); ?>#curriculum" class="nav-link">
                                    <span>Manage Curriculum</span>
                                </a>
                                <a href="<?php echo add_session_to_url('dashboard.php'); ?>#curriculum-submissions" class="nav-link">
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
                        <a href="<?php echo add_session_to_url('dashboard.php'); ?>#next-semester" class="nav-link">
                            <i class="fas fa-calendar-alt"></i>
                            <span>Next Semester</span>
                        </a>
                        <a href="<?php echo add_session_to_url('enrollment_control.php'); ?>" class="nav-link">
                            <i class="fas fa-cog"></i>
                            <span>Enrollment Control</span>
                        </a>
                        <a href="<?php echo add_session_to_url('dashboard.php'); ?>#chatbot" class="nav-link">
                            <i class="fas fa-robot"></i>
                            <span>Chatbot FAQs</span>
                        </a>
                        <a href="bulk_import.php" class="nav-link">
                            <i class="fas fa-file-import"></i>
                            <span>Bulk Import</span>
                        </a>
                        <a href="enrollment_reports.php" class="nav-link active">
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
                    <h4>Enrollment Reports</h4>
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
        <div class="content-header">
            <h4><i class="fas fa-chart-line me-2"></i>Enrollment Reports</h4>
            <small class="text-muted">Generate and manage enrollment reports for the Dean</small>
        </div>
        
        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo htmlspecialchars($_SESSION['message']); unset($_SESSION['message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['db_warning'])): ?>
            <div class="alert alert-warning alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['db_warning']); unset($_SESSION['db_warning']); ?>
                <br><br>
                <a href="../run_dean_fix.php" class="btn btn-sm btn-warning">
                    <i class="fas fa-tools me-1"></i>Run Migration Now
                </a>
            </div>
        <?php endif; ?>
        
        <!-- Generate Report Form -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-plus-circle me-2"></i>Generate New Report
            </div>
            <div class="card-body">
                <form method="POST" action="enrollment_reports.php">
                    <input type="hidden" name="action" value="generate">
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label small">Report Title *</label>
                            <input type="text" name="report_title" class="form-control form-control-sm" 
                                   placeholder="e.g., First Semester Enrollment Report AY 2024-2025" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Program</label>
                            <select name="program_code" class="form-select form-select-sm">
                                <option value="all">All Programs</option>
                                <?php foreach ($programs as $program): ?>
                                    <option value="<?php echo htmlspecialchars($program['program_code']); ?>">
                                        <?php echo htmlspecialchars($program['program_code'] . ' - ' . $program['program_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Academic Year</label>
                            <select name="academic_year" class="form-select form-select-sm">
                                <option value="all">All Years</option>
                                <?php foreach ($academic_years as $year): ?>
                                    <option value="<?php echo htmlspecialchars($year); ?>">
                                        <?php echo htmlspecialchars($year); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small">Semester</label>
                            <select name="semester" class="form-select form-select-sm">
                                <option value="all">All Semesters</option>
                                <option value="First Semester">First Semester</option>
                                <option value="Second Semester">Second Semester</option>
                                <option value="Summer">Summer</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fas fa-file-alt me-1"></i>Generate Report
                            </button>
                            <small class="text-muted ms-3">
                                <i class="fas fa-info-circle me-1"></i>Report will be saved as draft. You can review and send it to the Dean later.
                            </small>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Reports List -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-list me-2"></i>Generated Reports</span>
                <span class="badge bg-info"><?php echo count($reports); ?> reports</span>
            </div>
            <div class="card-body">
                <?php if (empty($reports)): ?>
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle me-2"></i>No reports generated yet.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-compact mb-0">
                            <thead>
                                <tr>
                                    <th>Report Title</th>
                                    <th>Period</th>
                                    <th>Generated</th>
                                    <th>Status</th>
                                    <th>Dean Review</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reports as $report): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($report['report_title']); ?></strong>
                                            <?php if (!empty($report['program_code'])): ?>
                                                <br><span class="badge bg-secondary"><?php echo htmlspecialchars($report['program_code']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small>
                                                <?php echo htmlspecialchars($report['academic_year'] ?? 'All Years'); ?>
                                                <br><?php echo htmlspecialchars($report['semester'] ?? 'All Semesters'); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <small>
                                                <?php echo htmlspecialchars($report['first_name'] . ' ' . $report['last_name']); ?>
                                                <br><?php echo date('M d, Y', strtotime($report['generated_at'])); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php
                                            $status_badge = [
                                                'draft' => 'secondary',
                                                'pending' => 'warning',
                                                'acknowledged' => 'success'
                                            ];
                                            $badge_color = $status_badge[$report['status']] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?php echo $badge_color; ?>">
                                                <?php echo ucfirst($report['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($report['status'] === 'acknowledged'): ?>
                                                <small class="text-success">
                                                    <i class="fas fa-check-circle me-1"></i>
                                                    Reviewed by <?php echo htmlspecialchars($report['dean_fname'] . ' ' . $report['dean_lname']); ?>
                                                    <br><?php echo date('M d, Y', strtotime($report['reviewed_at'])); ?>
                                                </small>
                                            <?php elseif ($report['status'] === 'pending'): ?>
                                                <small class="text-warning">
                                                    <i class="fas fa-clock me-1"></i>Pending review
                                                </small>
                                            <?php else: ?>
                                                <small class="text-muted">
                                                    <i class="fas fa-edit me-1"></i>Draft
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-info" data-bs-toggle="modal" 
                                                        data-bs-target="#viewModal<?php echo $report['id']; ?>">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                                <?php if ($report['status'] === 'draft'): ?>
                                                    <button type="button" class="btn btn-success" data-bs-toggle="modal" 
                                                            data-bs-target="#sendModal<?php echo $report['id']; ?>">
                                                        <i class="fas fa-paper-plane"></i> Send
                                                    </button>
                                                    <button type="button" class="btn btn-danger" data-bs-toggle="modal" 
                                                            data-bs-target="#deleteModal<?php echo $report['id']; ?>">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Modals (placed outside table for proper rendering) -->
                    <?php foreach ($reports as $report): ?>
                        <!-- View Modal -->
                        <div class="modal fade" id="viewModal<?php echo $report['id']; ?>" tabindex="-1">
                                        <div class="modal-dialog modal-xl">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">
                                                        <i class="fas fa-file-alt me-2"></i>
                                                        <?php echo htmlspecialchars($report['report_title']); ?>
                                                    </h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <?php 
                                                    // Parse report data
                                                    $report_data_parsed = null;
                                                    if (!empty($report['report_data'])) {
                                                        $report_data_parsed = json_decode($report['report_data'], true);
                                                        if (json_last_error() !== JSON_ERROR_NONE) {
                                                            $report_data_parsed = ['text' => $report['report_data']];
                                                        }
                                                    }
                                                    ?>
                                                    
                                                    <div class="report-metadata mb-3">
                                                        <div class="row">
                                                            <div class="col-md-3">
                                                                <small><strong>Program:</strong> <?php echo htmlspecialchars($report['program_code'] ?? 'All Programs'); ?></small>
                                                            </div>
                                                            <div class="col-md-3">
                                                                <small><strong>Academic Year:</strong> <?php echo htmlspecialchars($report['academic_year'] ?? 'All Years'); ?></small>
                                                            </div>
                                                            <div class="col-md-3">
                                                                <small><strong>Semester:</strong> <?php echo htmlspecialchars($report['semester'] ?? 'All Semesters'); ?></small>
                                                            </div>
                                                            <div class="col-md-3">
                                                                <small><strong>Generated:</strong> <?php echo date('M d, Y g:i A', strtotime($report['generated_at'])); ?></small>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <?php if ($report_data_parsed && isset($report_data_parsed['analytics'])): ?>
                                                        <!-- Analytics Dashboard (same as dean's view) -->
                                                        <div class="mt-3">
                                                            <h6 class="mb-3"><i class="fas fa-chart-bar me-2"></i>Analytics & Demographics</h6>
                                                            
                                                            <!-- Summary Cards -->
                                                            <div class="row mb-4">
                                                                <div class="col-md-3">
                                                                    <div class="card bg-primary text-white">
                                                                        <div class="card-body text-center">
                                                                            <h3><?php echo $report_data_parsed['analytics']['total_enrollments']; ?></h3>
                                                                            <small>Total Enrollments</small>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-3">
                                                                    <div class="card bg-info text-white">
                                                                        <div class="card-body text-center">
                                                                            <h3><?php echo count($report_data_parsed['analytics']['program_distribution']); ?></h3>
                                                                            <small>Programs</small>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-3">
                                                                    <div class="card bg-success text-white">
                                                                        <div class="card-body text-center">
                                                                            <h3><?php echo count($report_data_parsed['analytics']['section_distribution']); ?></h3>
                                                                            <small>Sections</small>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-3">
                                                                    <div class="card bg-warning text-white">
                                                                        <div class="card-body text-center">
                                                                            <h3><?php echo count($report_data_parsed['analytics']['year_level_distribution']); ?></h3>
                                                                            <small>Year Levels</small>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            
                                                            <!-- Charts Row 1 -->
                                                            <div class="row mb-4">
                                                                <div class="col-md-6">
                                                                    <div class="card">
                                                                        <div class="card-header">
                                                                            <h6 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Program Distribution</h6>
                                                                        </div>
                                                                        <div class="card-body">
                                                                            <canvas id="programChart<?php echo $report['id']; ?>" height="200"></canvas>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <div class="card">
                                                                        <div class="card-header">
                                                                            <h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Year Level Distribution</h6>
                                                                        </div>
                                                                        <div class="card-body">
                                                                            <canvas id="yearLevelChart<?php echo $report['id']; ?>" height="200"></canvas>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            
                                                            <!-- Charts Row 2 -->
                                                            <div class="row mb-4">
                                                                <div class="col-md-6">
                                                                    <div class="card">
                                                                        <div class="card-header">
                                                                            <h6 class="mb-0"><i class="fas fa-chart-pie me-2"></i>Student Type Distribution</h6>
                                                                        </div>
                                                                        <div class="card-body">
                                                                            <canvas id="studentTypeChart<?php echo $report['id']; ?>" height="200"></canvas>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <div class="col-md-6">
                                                                    <div class="card">
                                                                        <div class="card-header">
                                                                            <h6 class="mb-0"><i class="fas fa-chart-line me-2"></i>Enrollment Trend</h6>
                                                                        </div>
                                                                        <div class="card-body">
                                                                            <canvas id="trendChart<?php echo $report['id']; ?>" height="200"></canvas>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                            
                                                            <!-- Detailed Statistics Table -->
                                                            <div class="card">
                                                                <div class="card-header">
                                                                    <h6 class="mb-0"><i class="fas fa-table me-2"></i>Program Statistics</h6>
                                                                </div>
                                                                <div class="card-body">
                                                                    <div class="table-responsive">
                                                                        <table class="table table-sm table-hover">
                                                                            <thead>
                                                                                <tr>
                                                                                    <th>Program</th>
                                                                                    <th>Enrollments</th>
                                                                                    <th>Percentage</th>
                                                                                </tr>
                                                                            </thead>
                                                                            <tbody>
                                                                                <?php foreach ($report_data_parsed['analytics']['program_stats'] as $stat): ?>
                                                                                    <tr>
                                                                                        <td><?php echo htmlspecialchars($stat['program_code']); ?></td>
                                                                                        <td><?php echo $stat['count']; ?></td>
                                                                                        <td>
                                                                                            <div class="progress" style="height: 20px;">
                                                                                                <div class="progress-bar" role="progressbar" 
                                                                                                     style="width: <?php echo $stat['percentage']; ?>%">
                                                                                                    <?php echo $stat['percentage']; ?>%
                                                                                                </div>
                                                                                            </div>
                                                                                        </td>
                                                                                    </tr>
                                                                                <?php endforeach; ?>
                                                                            </tbody>
                                                                        </table>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        
                                                        <!-- Chart.js Script -->
                                                        <script>
                                                        document.addEventListener('DOMContentLoaded', function() {
                                                            const analytics<?php echo $report['id']; ?> = <?php echo json_encode($report_data_parsed['analytics']); ?>;
                                                            
                                                            // Program Distribution Pie Chart
                                                            new Chart(document.getElementById('programChart<?php echo $report['id']; ?>'), {
                                                                type: 'pie',
                                                                data: {
                                                                    labels: Object.keys(analytics<?php echo $report['id']; ?>.program_distribution),
                                                                    datasets: [{
                                                                        data: Object.values(analytics<?php echo $report['id']; ?>.program_distribution),
                                                                        backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#06b6d4']
                                                                    }]
                                                                },
                                                                options: {
                                                                    responsive: true,
                                                                    maintainAspectRatio: false,
                                                                    plugins: { legend: { position: 'bottom' } }
                                                                }
                                                            });
                                                            
                                                            // Year Level Bar Chart
                                                            new Chart(document.getElementById('yearLevelChart<?php echo $report['id']; ?>'), {
                                                                type: 'bar',
                                                                data: {
                                                                    labels: Object.keys(analytics<?php echo $report['id']; ?>.year_level_distribution),
                                                                    datasets: [{
                                                                        label: 'Enrollments',
                                                                        data: Object.values(analytics<?php echo $report['id']; ?>.year_level_distribution),
                                                                        backgroundColor: '#3b82f6'
                                                                    }]
                                                                },
                                                                options: {
                                                                    responsive: true,
                                                                    maintainAspectRatio: false,
                                                                    scales: { y: { beginAtZero: true } }
                                                                }
                                                            });
                                                            
                                                            // Student Type Pie Chart
                                                            new Chart(document.getElementById('studentTypeChart<?php echo $report['id']; ?>'), {
                                                                type: 'doughnut',
                                                                data: {
                                                                    labels: Object.keys(analytics<?php echo $report['id']; ?>.student_type_distribution),
                                                                    datasets: [{
                                                                        data: Object.values(analytics<?php echo $report['id']; ?>.student_type_distribution),
                                                                        backgroundColor: ['#10b981', '#f59e0b', '#ef4444']
                                                                    }]
                                                                },
                                                                options: {
                                                                    responsive: true,
                                                                    maintainAspectRatio: false,
                                                                    plugins: { legend: { position: 'bottom' } }
                                                                }
                                                            });
                                                            
                                                            // Enrollment Trend Line Chart
                                                            const dates = Object.keys(analytics<?php echo $report['id']; ?>.enrollment_by_date).sort();
                                                            new Chart(document.getElementById('trendChart<?php echo $report['id']; ?>'), {
                                                                type: 'line',
                                                                data: {
                                                                    labels: dates,
                                                                    datasets: [{
                                                                        label: 'Daily Enrollments',
                                                                        data: dates.map(d => analytics<?php echo $report['id']; ?>.enrollment_by_date[d]),
                                                                        borderColor: '#3b82f6',
                                                                        backgroundColor: 'rgba(59, 130, 246, 0.1)',
                                                                        fill: true,
                                                                        tension: 0.4
                                                                    }]
                                                                },
                                                                options: {
                                                                    responsive: true,
                                                                    maintainAspectRatio: false,
                                                                    scales: { y: { beginAtZero: true } }
                                                                }
                                                            });
                                                        });
                                                        </script>
                                                    <?php elseif ($report_data_parsed && isset($report_data_parsed['text'])): ?>
                                                        <div class="mt-3">
                                                            <h6>Report Summary:</h6>
                                                            <pre class="bg-light p-3 rounded" style="max-height: 400px; overflow-y: auto; font-size: 0.8rem;"><?php echo htmlspecialchars($report_data_parsed['text']); ?></pre>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="modal-footer">
                                                    <?php if ($report['status'] === 'draft'): ?>
                                                        <button type="button" class="btn btn-success" data-bs-toggle="modal" 
                                                                data-bs-target="#sendModal<?php echo $report['id']; ?>" 
                                                                onclick="$('#viewModal<?php echo $report['id']; ?>').modal('hide');">
                                                            <i class="fas fa-paper-plane me-1"></i>Send to Dean
                                                        </button>
                                                    <?php endif; ?>
                                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Send to Dean Modal -->
                                    <?php if ($report['status'] === 'draft'): ?>
                                        <div class="modal fade" id="sendModal<?php echo $report['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">
                                                            <i class="fas fa-paper-plane me-2"></i>Send Report to Dean
                                                        </h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form method="POST" action="enrollment_reports.php">
                                                        <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                                        <input type="hidden" name="action" value="send_to_dean">
                                                        <div class="modal-body">
                                                            <p>Are you sure you want to send this report to the Dean for review?</p>
                                                            <div class="alert alert-info">
                                                                <strong><?php echo htmlspecialchars($report['report_title']); ?></strong>
                                                                <br><small><?php echo htmlspecialchars($report['program_code'] ?? 'All Programs'); ?> - <?php echo htmlspecialchars($report['academic_year'] ?? 'All Years'); ?></small>
                                                            </div>
                                                            <p class="text-muted small">Once sent, you will not be able to edit or delete this report.</p>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-success btn-sm">
                                                                <i class="fas fa-paper-plane me-1"></i>Send to Dean
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Delete Modal -->
                                        <div class="modal fade" id="deleteModal<?php echo $report['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">
                                                            <i class="fas fa-trash me-2"></i>Delete Draft Report
                                                        </h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form method="POST" action="enrollment_reports.php">
                                                        <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                                        <input type="hidden" name="action" value="delete">
                                                        <div class="modal-body">
                                                            <p>Are you sure you want to delete this draft report?</p>
                                                            <div class="alert alert-warning">
                                                                <strong><?php echo htmlspecialchars($report['report_title']); ?></strong>
                                                                <br><small>This action cannot be undone.</small>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" class="btn btn-danger btn-sm">
                                                                <i class="fas fa-trash me-1"></i>Delete
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                    <?php endforeach; ?>
                    
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
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
        
        // Fix floating buttons issue - ensure modals are properly contained
        document.addEventListener('DOMContentLoaded', function() {
            // Hide any buttons that are floating outside their containers
            setTimeout(function() {
                const allButtons = document.querySelectorAll('body .btn-success, body .btn-danger');
                allButtons.forEach(function(btn) {
                    const modalFooter = btn.closest('.modal-footer');
                    const btnGroup = btn.closest('.btn-group');
                    const table = btn.closest('table');
                    
                    // If button is not properly contained, hide it
                    if (!modalFooter && !btnGroup && !table) {
                        // Check if it's a direct child of body or floating
                        if (btn.parentElement === document.body || 
                            window.getComputedStyle(btn).position === 'fixed' ||
                            window.getComputedStyle(btn).position === 'absolute') {
                            btn.style.display = 'none';
                            btn.remove(); // Remove it completely
                        }
                    }
                });
                
                // Ensure all modals are properly hidden when not shown
                document.querySelectorAll('.modal').forEach(function(modal) {
                    if (!modal.classList.contains('show')) {
                        modal.style.display = 'none';
                    }
                });
            }, 100);
        });
    </script>
</body>
</html>

