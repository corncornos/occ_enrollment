<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/ProgramHead.php';

// Check if program head is logged in
if (!isProgramHead()) {
    redirect('public/login.php');
}

$programHead = new ProgramHead();

// Get database connection
$db = new Database();
$conn = $db->getConnection();

// Get next semester enrollment requests for this program head's program
// Program heads only review irregular students (pending_program_head status)
$program_code = $_SESSION['program_code'] ?? '';
$next_sem_query = "SELECT DISTINCT nse.*, u.student_id, u.first_name, u.last_name, u.email,
                   COUNT(nsss.id) as subject_count
                   FROM next_semester_enrollments nse
                   JOIN users u ON nse.user_id = u.id
                   LEFT JOIN next_semester_subject_selections nsss ON nse.id = nsss.enrollment_request_id
                   LEFT JOIN pre_enrollment_forms pef ON (pef.enrollment_request_id = nse.id OR (pef.user_id = nse.user_id AND pef.enrollment_request_id IS NULL))
                   LEFT JOIN sections selected_section ON nse.selected_section_id = selected_section.id
                   LEFT JOIN programs selected_program ON selected_section.program_id = selected_program.id
                   LEFT JOIN section_enrollments se ON u.id = se.user_id AND se.status = 'active'
                   LEFT JOIN sections s ON se.section_id = s.id
                   LEFT JOIN programs p ON s.program_id = p.id
                   WHERE nse.request_status = 'pending_program_head'
                   AND nse.enrollment_type = 'irregular'
                   AND (
                       pef.current_course = :program_code
                       OR p.program_code = :program_code
                       OR selected_program.program_code = :program_code
                   )
                   GROUP BY nse.id
                   ORDER BY nse.created_at DESC";
$next_sem_stmt = $conn->prepare($next_sem_query);
$next_sem_stmt->bindParam(':program_code', $program_code);
$next_sem_stmt->execute();
$next_sem_requests = $next_sem_stmt->fetchAll(PDO::FETCH_ASSOC);

// Count pending next semester enrollment requests
$pending_next_sem_count = count($next_sem_requests);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Next Semester Enrollments - Program Head Dashboard</title>
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
        .sidebar .nav-link {
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
        .sidebar .nav-link i {
            width: 20px;
            font-size: 16px;
        }
        .sidebar .nav-link:hover {
            background: rgba(255, 255, 255, 0.05);
            color: white;
            border-left-color: #3b82f6;
        }
        .sidebar .nav-link.active {
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
            font-size: 16px;
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
        .content-section {
            padding: 20px !important;
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
                         onerror="this.style.display='none'; document.getElementById('programHeadSidebarFallback').classList.remove('d-none');">
                    <i id="programHeadSidebarFallback" class="fas fa-graduation-cap d-none" style="font-size: 24px; color: white;"></i>
                    <h4>Program Head</h4>
                </div>
                <div class="sidebar-menu">
                    <nav class="nav flex-column">
                        <a class="nav-link" href="dashboard.php">
                            <i class="fas fa-home"></i>
                            <span>Dashboard</span>
                        </a>
                        <a class="nav-link" href="curriculum.php">
                            <i class="fas fa-book"></i>
                            <span>Curriculum Management</span>
                        </a>
                        <a class="nav-link" href="bulk_upload.php">
                            <i class="fas fa-upload"></i>
                            <span>Bulk Upload</span>
                        </a>
                        <a class="nav-link" href="submissions.php">
                            <i class="fas fa-paper-plane"></i>
                            <span>My Submissions</span>
                        </a>
                        <a class="nav-link active" href="next_semester_enrollments.php">
                            <i class="fas fa-calendar-alt"></i>
                            <span>Next Semester Enrollments</span>
                            <?php if ($pending_next_sem_count > 0): ?>
                                <span class="badge bg-warning text-dark ms-auto"><?php echo $pending_next_sem_count; ?></span>
                            <?php endif; ?>
                        </a>
                        <a class="nav-link" href="review_adjustments.php">
                            <i class="fas fa-exchange-alt"></i>
                            <span>Review Adjustments</span>
                        </a>
                        <a class="nav-link" href="logout.php">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </nav>
                </div>
                <div class="sidebar-user">
                    <h6>Welcome,</h6>
                    <h5><?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?></h5>
                    <small><?php echo htmlspecialchars($_SESSION['program_name']); ?></small>
                </div>
            </div>

            <!-- Top Navigation Bar -->
            <nav class="top-nav">
                <div class="top-nav-left">
                    <h4>Next Semester Enrollments</h4>
                </div>
                <div class="top-nav-right">
                    <div class="top-nav-user">
                        <h6>Welcome,</h6>
                        <h5><?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?></h5>
                        <small><?php echo htmlspecialchars($_SESSION['program_name']); ?></small>
                    </div>
                </div>
            </nav>

            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 px-3 py-3 main-content">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2><i class="fas fa-calendar-alt me-2"></i>Next Semester Enrollment Requests</h2>
                        <p class="text-muted mb-0">Review and approve second semester enrollment requests for <?php echo htmlspecialchars($_SESSION['program_name']); ?></p>
                    </div>
                    <div>
                        <a href="dashboard.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                        </a>
                    </div>
                </div>

                <?php if ($pending_next_sem_count > 0): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        You have <strong><?php echo $pending_next_sem_count; ?></strong> pending enrollment request(s) to review.
                    </div>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-list me-2"></i>Enrollment Requests</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Student ID</th>
                                        <th>Name</th>
                                        <th>Current Level</th>
                                        <th>Target Term</th>
                                        <th>Preferred Shift</th>
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
                                                <p class="text-muted">No pending enrollment requests for your program.</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($next_sem_requests as $request): 
                                            // Get preferred shift from pre-enrollment form
                                            $pre_form_query = "SELECT preferred_shift FROM pre_enrollment_forms 
                                                              WHERE enrollment_request_id = :request_id 
                                                              OR (user_id = :user_id AND enrollment_request_id IS NULL)
                                                              ORDER BY created_at DESC LIMIT 1";
                                            $pre_form_stmt = $conn->prepare($pre_form_query);
                                            $pre_form_stmt->bindParam(':request_id', $request['id']);
                                            $pre_form_stmt->bindParam(':user_id', $request['user_id']);
                                            $pre_form_stmt->execute();
                                            $pre_form = $pre_form_stmt->fetch(PDO::FETCH_ASSOC);
                                            $preferred_shift = $pre_form['preferred_shift'] ?? 'Not specified';
                                            
                                            // Check if this is second semester
                                            $is_second_sem = (stripos($request['target_semester'], 'Second') !== false || stripos($request['target_semester'], '2nd') !== false);
                                        ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars($request['student_id']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($request['first_name'] . ' ' . $request['last_name']); ?></td>
                                                <td><?php echo htmlspecialchars($request['current_year_level']); ?></td>
                                                <td>
                                                    <?php echo htmlspecialchars($request['target_academic_year']); ?><br>
                                                    <small class="text-muted"><?php echo htmlspecialchars($request['target_semester']); ?></small>
                                                    <?php if ($is_second_sem): ?>
                                                        <br><span class="badge bg-success mt-1">Second Semester</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($preferred_shift != 'Not specified'): ?>
                                                        <span class="badge <?php echo $preferred_shift == 'Morning' ? 'bg-info' : ($preferred_shift == 'Afternoon' ? 'bg-warning' : 'bg-dark'); ?>">
                                                            <?php echo htmlspecialchars($preferred_shift); ?>
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="text-muted"><?php echo htmlspecialchars($preferred_shift); ?></span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php
                                                    $status_colors = [
                                                        'pending' => 'warning',
                                                        'under_review' => 'info',
                                                        'approved' => 'success',
                                                        'rejected' => 'danger'
                                                    ];
                                                    $color = $status_colors[$request['request_status']] ?? 'secondary';
                                                    ?>
                                                    <span class="badge bg-<?php echo $color; ?>">
                                                        <?php echo strtoupper($request['request_status']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo date('M j, Y', strtotime($request['created_at'])); ?></td>
                                                <td>
                                                    <?php 
                                                    // Build URL for review page
                                                    $review_url = BASE_URL . 'admin/review_next_semester.php?request_id=' . urlencode($request['id']) . '&session_id=' . CURRENT_SESSION_ID;
                                                    ?>
                                                    <a href="<?php echo htmlspecialchars($review_url); ?>" 
                                                       class="btn btn-primary btn-sm">
                                                        <i class="fas fa-clipboard-check"></i> Review
                                                    </a>
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
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('active');
        }
    </script>
</body>
</html>

