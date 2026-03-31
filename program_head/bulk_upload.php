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

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['upload_csv'])) {
        // Debug: Log file upload info
        error_log("File upload attempt: " . print_r($_FILES, true));

        // Handle CSV upload
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
            $file_name = $_FILES['csv_file']['name'];
            $file_tmp = $_FILES['csv_file']['tmp_name'];
            $file_size = $_FILES['csv_file']['size'];

            // Validate file type
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            if ($file_ext != 'csv') {
                $message = 'Please upload a valid CSV file.';
                $message_type = 'danger';
            } elseif ($file_size > 5 * 1024 * 1024) { // 5MB limit
                $message = 'File size must be less than 5MB.';
                $message_type = 'danger';
            } else {
                // Ensure temp directory exists
                $temp_dir = '../uploads/';
                if (!is_dir($temp_dir)) {
                    mkdir($temp_dir, 0755, true);
                }

                // Move uploaded file to temp location
                $temp_path = '../uploads/temp_' . session_id() . '_' . time() . '.csv';
                if (move_uploaded_file($file_tmp, $temp_path)) {
                    $academic_year = sanitizeInput($_POST['academic_year']);
                    // Semester is now extracted from CSV file, use "Mixed" as default for submission
                    $semester = 'Mixed';

                    // Process the bulk import
                    $result = $programHead->processBulkImport($_SESSION['user_id'], $temp_path, $academic_year, $semester);

                    // Clean up temp file
                    unlink($temp_path);

                    if ($result['success']) {
                        $message = "Successfully uploaded {$result['items_count']} subjects! ";
                        $message .= "Your curriculum submission has been sent to the Dean for final approval.";
                        $message_type = 'success';
                    } else {
                        $message = 'Upload failed: ' . $result['message'];
                        $message_type = 'danger';
                    }
                } else {
                    $message = 'Failed to upload file. Please try again.';
                    $message_type = 'danger';
                }
            }
        } else {
            // Debug file upload issues
            $error_msg = 'Please select a CSV file to upload.';
            if (isset($_FILES['csv_file'])) {
                $error_code = $_FILES['csv_file']['error'];
                switch ($error_code) {
                    case UPLOAD_ERR_NO_FILE:
                        $error_msg = 'No file was selected.';
                        break;
                    case UPLOAD_ERR_INI_SIZE:
                        $error_msg = 'File is too large (PHP upload limit exceeded).';
                        break;
                    case UPLOAD_ERR_FORM_SIZE:
                        $error_msg = 'File is too large (form limit exceeded).';
                        break;
                    case UPLOAD_ERR_PARTIAL:
                        $error_msg = 'File upload was interrupted.';
                        break;
                    default:
                        $error_msg = 'File upload error (code: ' . $error_code . ').';
                        break;
                }
            }
            $message = $error_msg;
            $message_type = 'warning';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Upload - Program Head Dashboard</title>
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
        .upload-area {
            border: 2px dashed #dee2e6;
            border-radius: 10px;
            padding: 40px;
            text-align: center;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }
        .upload-area:hover {
            border-color: #2563eb;
            background: #f0f2ff;
        }
        .upload-area.dragover {
            border-color: #2563eb;
            background: #e7f3ff;
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
                        <a class="nav-link" href="curriculum.php">
                            <i class="fas fa-book"></i>
                            <span>Curriculum Management</span>
                        </a>
                        <a class="nav-link active" href="bulk_upload.php">
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
                    <h4>Bulk Upload</h4>
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
                        <h2><i class="fas fa-upload me-2"></i>Bulk Upload Curriculum</h2>
                        <p class="text-muted mb-0">Upload multiple subjects at once using CSV format</p>
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

                <!-- Upload Form -->
                <div class="row">
                    <div class="col-lg-8">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="mb-0"><i class="fas fa-file-csv me-2"></i>Upload CSV File</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" enctype="multipart/form-data">
                                    <!-- Academic Year -->
                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label for="academic_year" class="form-label">Academic Year *</label>
                                            <input type="text" class="form-control" id="academic_year" name="academic_year"
                                                   placeholder="e.g., AY 2024-2025" required
                                                   value="<?php echo isset($_POST['academic_year']) ? htmlspecialchars($_POST['academic_year']) : ''; ?>">
                                        </div>
                                    </div>

                                    <!-- File Upload -->
                                    <div class="mb-3">
                                        <label for="csv_file" class="form-label">CSV File *</label>
                                        <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv" required>
                                        <div class="form-text">
                                            <small class="text-muted">
                                                File must be in CSV format. Required columns: Course Code, Course Name, Units, Year Level, Semester, Is Required (1/Yes or 0/No). Optional: Program Code (first column, will be ignored), Prerequisites.
                                            </small>
                                        </div>
                                    </div>

                                    <div class="d-flex gap-2">
                                        <button type="submit" name="upload_csv" class="btn btn-success">
                                            <i class="fas fa-upload me-2"></i>Upload & Process
                                        </button>
                                        <a href="dashboard.php" class="btn btn-outline-secondary">Cancel</a>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <div class="col-lg-4">
                        <!-- CSV Format Guide -->
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="fas fa-info-circle me-2"></i>CSV Format Guide</h6>
                            </div>
                            <div class="card-body">
                                <p class="small text-muted mb-2">Your CSV file should have the following columns (in order):</p>
                                <ol class="small">
                                    <li><strong>Program Code</strong> - <em>Optional</em>, will be ignored if present</li>
                                    <li><strong>Course Code</strong> - e.g., GE-100, BSE-C101</li>
                                    <li><strong>Course Name</strong> - e.g., Understanding the Self</li>
                                    <li><strong>Units</strong> - e.g., 3</li>
                                    <li><strong>Year Level</strong> - 1st Year, 2nd Year, 3rd Year, 4th Year, 5th Year</li>
                                    <li><strong>Semester</strong> - First Semester, Second Semester, Summer</li>
                                    <li><strong>Is Required</strong> - 1/Yes (required) or 0/No (elective)</li>
                                    <li><strong>Prerequisites</strong> - <em>Optional</em>, course codes separated by commas</li>
                                </ol>

                                <hr>
                                <h6>Sample CSV (with program_code):</h6>
                                <pre class="small bg-light p-2 rounded"><code>program_code,course_code,course_name,units,year_level,semester,is_required,pre_requisites
BTVTED,GE-100,Understanding the Self,3,1st Year,First Semester,1,
BTVTED,GE-101,Reading in Philippine History,3,1st Year,First Semester,1,</code></pre>
                                
                                <h6 class="mt-2">Or without program_code:</h6>
                                <pre class="small bg-light p-2 rounded"><code>course_code,course_name,units,year_level,semester,is_required,pre_requisites
GE-100,Understanding the Self,3,1st Year,First Semester,1,
GE-101,Reading in Philippine History,3,1st Year,First Semester,1,</code></pre>
                            </div>
                        </div>

                        <!-- Quick Stats -->
                        <div class="card mt-3">
                            <div class="card-header">
                                <h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Upload Limits</h6>
                            </div>
                            <div class="card-body">
                                <ul class="list-unstyled small">
                                    <li><i class="fas fa-check text-success me-2"></i>Max file size: 5MB</li>
                                    <li><i class="fas fa-check text-success me-2"></i>Format: CSV only</li>
                                    <li><i class="fas fa-check text-success me-2"></i>Auto-creates submission</li>
                                    <li><i class="fas fa-check text-success me-2"></i>Sent to Dean for approval</li>
                                </ul>
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
