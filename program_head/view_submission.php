<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/ProgramHead.php';

// Check if program head is logged in
if (!isProgramHead()) {
    redirect('public/login.php');
}

$programHead = new ProgramHead();

$submission_id = (int)($_GET['id'] ?? 0);

if ($submission_id <= 0) {
    echo "<script>alert('Invalid submission ID'); window.history.back();</script>";
    exit();
}

// Get submission details
$submissions = $programHead->getCurriculumSubmissions($_SESSION['user_id']);
$submission = null;

foreach ($submissions as $sub) {
    if ($sub['id'] == $submission_id) {
        $submission = $sub;
        break;
    }
}

if (!$submission) {
    echo "<script>alert('Submission not found or access denied'); window.history.back();</script>";
    exit();
}

// Get submission items
$items = $programHead->getCurriculumSubmissionItems($submission_id);

// Get database connection
$db = new Database();
$conn = $db->getConnection();

// Count pending next semester enrollment requests for this program head's program
// Program heads only review irregular students (pending_program_head status)
$program_code = $_SESSION['program_code'] ?? '';
$next_sem_count_query = "SELECT COUNT(DISTINCT nse.id) as count
                         FROM next_semester_enrollments nse
                         JOIN users u ON nse.user_id = u.id
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
                         )";
$next_sem_count_stmt = $conn->prepare($next_sem_count_query);
$next_sem_count_stmt->bindParam(':program_code', $program_code);
$next_sem_count_stmt->execute();
$next_sem_count_result = $next_sem_count_stmt->fetch(PDO::FETCH_ASSOC);
$pending_next_sem_count = $next_sem_count_result['count'] ?? 0;

// Handle form submissions
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['submit_curriculum']) && $submission['status'] == 'draft') {
        if ($programHead->submitCurriculumToRegistrar($submission_id)) {
            $message = 'Curriculum submitted to Registrar successfully!';
            $message_type = 'success';
            // Refresh submission data
            $submissions = $programHead->getCurriculumSubmissions($_SESSION['user_id']);
            foreach ($submissions as $sub) {
                if ($sub['id'] == $submission_id) {
                    $submission = $sub;
                    break;
                }
            }
        } else {
            $message = 'Failed to submit curriculum. Please try again.';
            $message_type = 'danger';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Submission - Program Head Dashboard</title>
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
        .status-draft { background: #ffc107; color: #000; }
        .status-submitted { background: #17a2b8; color: white; }
        .status-approved { background: #28a745; color: white; }
        .status-rejected { background: #dc3545; color: white; }
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
                        <a class="nav-link active" href="submissions.php">
                            <i class="fas fa-paper-plane"></i>
                            <span>My Submissions</span>
                        </a>
                        <a class="nav-link" href="next_semester_enrollments.php">
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
                    <h4>View Submission</h4>
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
                        <h2><i class="fas fa-eye me-2"></i>View Submission</h2>
                        <p class="text-muted mb-0"><?php echo htmlspecialchars($submission['submission_title']); ?></p>
                    </div>
                    <div>
                        <a href="submissions.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Submissions
                        </a>
                    </div>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show" role="alert">
                        <i class="fas fa-info-circle me-2"></i>
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Submission Overview -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Submission Overview</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Submission Title:</strong> <?php echo htmlspecialchars($submission['submission_title']); ?></p>
                                <p><strong>Program:</strong> <?php echo htmlspecialchars($submission['program_code'] . ' - ' . $submission['program_name']); ?></p>
                                <p><strong>Description:</strong> <?php echo htmlspecialchars($submission['submission_description'] ?: 'No description provided'); ?></p>
                                <p><strong>Created:</strong> <?php echo date('M j, Y g:i A', strtotime($submission['created_at'])); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Academic Year:</strong> <?php echo htmlspecialchars($submission['academic_year']); ?></p>
                                <p><strong>Semester:</strong> <?php echo htmlspecialchars($submission['semester']); ?></p>
                                <p><strong>Status:</strong>
                                    <span class="badge status-<?php echo $submission['status']; ?> ms-2">
                                        <?php echo ucfirst($submission['status']); ?>
                                    </span>
                                </p>
                                <p><strong>Last Updated:</strong> <?php echo date('M j, Y g:i A', strtotime($submission['updated_at'])); ?></p>
                            </div>
                        </div>

                        <?php if ($submission['submitted_at']): ?>
                            <div class="mt-3">
                                <p><strong>Submitted to Registrar:</strong> <?php echo date('M j, Y g:i A', strtotime($submission['submitted_at'])); ?></p>
                            </div>
                        <?php endif; ?>

                        <?php if ($submission['reviewer_comments']): ?>
                            <div class="mt-3">
                                <strong>Reviewer Comments:</strong>
                                <div class="alert alert-info mt-2">
                                    <?php echo htmlspecialchars($submission['reviewer_comments']); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Curriculum Items -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            <i class="fas fa-list me-2"></i>Curriculum Subjects
                            <span class="badge bg-secondary ms-2"><?php echo count($items); ?> subjects</span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($items)): ?>
                            <div class="text-center py-4">
                                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                <p class="text-muted">No subjects found in this submission.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-striped table-hover">
                                    <thead>
                                        <tr>
                                            <th>Course Code</th>
                                            <th>Course Name</th>
                                            <th>Units</th>
                                            <th>Year Level</th>
                                            <th>Semester</th>
                                            <th>Required</th>
                                            <th>Prerequisites</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        // Group by year level and semester
                                        $grouped_items = [];
                                        foreach ($items as $item) {
                                            $key = $item['year_level'] . '|' . $item['semester'];
                                            if (!isset($grouped_items[$key])) {
                                                $grouped_items[$key] = [];
                                            }
                                            $grouped_items[$key][] = $item;
                                        }

                                        foreach ($grouped_items as $group_key => $group_items):
                                            list($year_level, $semester) = explode('|', $group_key);
                                        ?>
                                            <tr class="table-secondary">
                                                <td colspan="7" class="fw-bold">
                                                    <i class="fas fa-graduation-cap me-2"></i>
                                                    <?php echo htmlspecialchars($year_level . ' - ' . $semester); ?>
                                                </td>
                                            </tr>
                                            <?php foreach ($group_items as $item): ?>
                                                <tr>
                                                    <td><code><?php echo htmlspecialchars($item['course_code']); ?></code></td>
                                                    <td><?php echo htmlspecialchars($item['course_name']); ?></td>
                                                    <td><?php echo $item['units']; ?></td>
                                                    <td><?php echo htmlspecialchars($item['year_level']); ?></td>
                                                    <td><?php echo htmlspecialchars($item['semester']); ?></td>
                                                    <td>
                                                        <?php if ($item['is_required']): ?>
                                                            <span class="badge bg-success">Required</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">Optional</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <small class="text-muted">
                                                            <?php echo htmlspecialchars($item['pre_requisites'] ?: 'None'); ?>
                                                        </small>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Action Buttons -->
                <?php if ($submission['status'] == 'draft'): ?>
                    <div class="d-flex gap-2 mt-4">
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="submission_id" value="<?php echo $submission['id']; ?>">
                            <button type="submit" name="submit_curriculum" class="btn btn-success">
                                <i class="fas fa-paper-plane me-2"></i>Submit to Registrar
                            </button>
                        </form>
                        <a href="submissions.php" class="btn btn-outline-secondary">Back to Submissions</a>
                    </div>
                <?php else: ?>
                    <div class="mt-4">
                        <a href="submissions.php" class="btn btn-outline-secondary">Back to Submissions</a>
                    </div>
                <?php endif; ?>
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
