<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/ProgramHead.php';

// Check if program head is logged in
if (!isProgramHead()) {
    redirect('public/login.php');
}

$programHead = new ProgramHead();
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['create_submission'])) {
        $data = [
            'program_head_id' => $_SESSION['user_id'],
            'program_id' => $_SESSION['program_id'],
            'submission_title' => sanitizeInput($_POST['submission_title']),
            'submission_description' => sanitizeInput($_POST['submission_description']),
            'academic_year' => sanitizeInput($_POST['academic_year']),
            'semester' => sanitizeInput($_POST['semester'])
        ];

        $result = $programHead->createCurriculumSubmission($data);
        if ($result['success']) {
            $message = 'Curriculum submission created successfully!';
            $message_type = 'success';
        } else {
            $message = 'Failed to create submission: ' . $result['message'];
            $message_type = 'danger';
        }
    }
}

// Get existing curriculum
$existing_curriculum = $programHead->getExistingCurriculum($_SESSION['program_id']);

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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Curriculum Management - Program Head Dashboard</title>
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
        .card-header-custom {
            background: #1e40af;
            color: white;
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
                        <a class="nav-link active" href="curriculum.php">
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
                    <h4>Curriculum Management</h4>
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
                        <h2><i class="fas fa-book me-2"></i>Curriculum Management</h2>
                        <p class="text-muted mb-0">Create and manage curriculum submissions</p>
                    </div>
                    <div>
                        <a href="dashboard.php" class="btn btn-outline-secondary">
                            <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
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

                <div class="row">
                    <!-- Create New Submission -->
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header card-header-custom">
                                <h5 class="mb-0"><i class="fas fa-plus me-2"></i>Create New Submission</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST">
                                    <div class="mb-3">
                                        <label for="submission_title" class="form-label">Submission Title *</label>
                                        <input type="text" class="form-control" id="submission_title" name="submission_title"
                                               placeholder="e.g., Curriculum Update - AY 2024-2025" required
                                               value="<?php echo isset($_POST['submission_title']) ? htmlspecialchars($_POST['submission_title']) : ''; ?>">
                                    </div>

                                    <div class="mb-3">
                                        <label for="submission_description" class="form-label">Description</label>
                                        <textarea class="form-control" id="submission_description" name="submission_description"
                                                  rows="3" placeholder="Optional description of this submission"><?php echo isset($_POST['submission_description']) ? htmlspecialchars($_POST['submission_description']) : ''; ?></textarea>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label for="academic_year" class="form-label">Academic Year *</label>
                                            <input type="text" class="form-control" id="academic_year" name="academic_year"
                                                   placeholder="e.g., AY 2024-2025" required
                                                   value="<?php echo isset($_POST['academic_year']) ? htmlspecialchars($_POST['academic_year']) : ''; ?>">
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label for="semester" class="form-label">Semester *</label>
                                            <select class="form-control" id="semester" name="semester" required>
                                                <option value="">Select Semester</option>
                                                <option value="First Semester" <?php echo (isset($_POST['semester']) && $_POST['semester'] == 'First Semester') ? 'selected' : ''; ?>>First Semester</option>
                                                <option value="Second Semester" <?php echo (isset($_POST['semester']) && $_POST['semester'] == 'Second Semester') ? 'selected' : ''; ?>>Second Semester</option>
                                                <option value="Summer" <?php echo (isset($_POST['semester']) && $_POST['semester'] == 'Summer') ? 'selected' : ''; ?>>Summer</option>
                                            </select>
                                        </div>
                                    </div>

                                    <button type="submit" name="create_submission" class="btn btn-primary">
                                        <i class="fas fa-plus me-2"></i>Create Submission
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Existing Curriculum Overview -->
                    <div class="col-lg-6">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-list me-2"></i>Current Curriculum</h5>
                            </div>
                            <div class="card-body">
                                <div class="row text-center">
                                    <?php
                                    $yearCounts = [];
                                    foreach ($existing_curriculum as $subject) {
                                        $year = $subject['year_level'];
                                        if (!isset($yearCounts[$year])) {
                                            $yearCounts[$year] = 0;
                                        }
                                        $yearCounts[$year]++;
                                    }
                                    ?>
                                    <?php foreach ($yearCounts as $year => $count): ?>
                                        <div class="col-6 col-md-3 mb-3">
                                            <div class="card bg-light">
                                                <div class="card-body p-2">
                                                    <div class="h5 mb-0"><?php echo $count; ?></div>
                                                    <small class="text-muted"><?php echo $year; ?></small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>

                                <hr>

                                <div class="mb-3">
                                    <h6>Total Subjects: <span class="badge bg-primary"><?php echo count($existing_curriculum); ?></span></h6>
                                </div>

                                <div class="text-center">
                                    <button class="btn btn-outline-info btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#curriculumDetails">
                                        <i class="fas fa-eye me-1"></i>View Details
                                    </button>
                                </div>

                                <div class="collapse mt-3" id="curriculumDetails">
                                    <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                                        <table class="table table-sm table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Code</th>
                                                    <th>Name</th>
                                                    <th>Year</th>
                                                    <th>Semester</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach (array_slice($existing_curriculum, 0, 20) as $subject): ?>
                                                    <tr>
                                                        <td><small><?php echo htmlspecialchars($subject['course_code']); ?></small></td>
                                                        <td><small><?php echo htmlspecialchars($subject['course_name']); ?></small></td>
                                                        <td><small><?php echo htmlspecialchars($subject['year_level']); ?></small></td>
                                                        <td><small><?php echo htmlspecialchars($subject['semester']); ?></small></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                        <?php if (count($existing_curriculum) > 20): ?>
                                            <div class="text-center">
                                                <small class="text-muted">Showing first 20 subjects (<?php echo count($existing_curriculum) - 20; ?> more...)</small>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Actions -->
                <div class="row mt-4">
                    <div class="col-12">
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="fas fa-rocket me-2"></i>Quick Actions</h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-4">
                                        <a href="bulk_upload.php" class="btn btn-success btn-lg w-100 mb-2">
                                            <i class="fas fa-upload me-2"></i>
                                            <div class="small">Bulk Upload Subjects</div>
                                        </a>
                                    </div>
                                    <div class="col-md-4">
                                        <a href="submissions.php" class="btn btn-info btn-lg w-100 mb-2">
                                            <i class="fas fa-paper-plane me-2"></i>
                                            <div class="small">View Submissions</div>
                                        </a>
                                    </div>
                                    <div class="col-md-4">
                                        <button class="btn btn-warning btn-lg w-100 mb-2" onclick="location.reload()">
                                            <i class="fas fa-sync me-2"></i>
                                            <div class="small">Refresh Data</div>
                                        </button>
                                    </div>
                                </div>
                            </div>
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
