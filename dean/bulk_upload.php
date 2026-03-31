<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/Curriculum.php';

if (!isLoggedIn() || !isDean()) {
    redirect('public/login.php');
}

$conn = (new Database())->getConnection();
$curriculum = new Curriculum();
$dean_id = $_SESSION['user_id'];

// Get dean information
$admin_query = "SELECT * FROM admins WHERE id = :id AND is_dean = 1";
$admin_stmt = $conn->prepare($admin_query);
$admin_stmt->bindParam(':id', $dean_id);
$admin_stmt->execute();
$dean_info = $admin_stmt->fetch(PDO::FETCH_ASSOC);

if (!$dean_info) {
    $_SESSION['message'] = 'Access denied. Dean account not found.';
    redirect('public/login.php');
}

// Get all programs for selection
$programs_query = "SELECT * FROM programs ORDER BY program_code";
$programs_stmt = $conn->prepare($programs_query);
$programs_stmt->execute();
$programs = $programs_stmt->fetchAll(PDO::FETCH_ASSOC);

$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_csv'])) {
    // Validate program selection
    $program_id = (int)($_POST['program_id'] ?? 0);
    if ($program_id <= 0) {
        $message = 'Please select a program.';
        $message_type = 'danger';
    } elseif (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == 0) {
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
                try {
                    $conn->beginTransaction();
                    
                    // Parse CSV file
                    $items = parseCurriculumCSV($temp_path);
                    if (empty($items)) {
                        throw new Exception('No valid curriculum items found in CSV file');
                    }

                    // Batch check for existing courses (optimization: single query instead of N queries)
                    $existing_courses = [];
                    $existing_stmt = $conn->prepare("SELECT course_code, year_level, semester 
                                                    FROM curriculum 
                                                    WHERE program_id = :program_id");
                    $existing_stmt->execute([':program_id' => $program_id]);
                    $existing_results = $existing_stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    foreach ($existing_results as $existing) {
                        $key = $existing['course_code'] . '|' . $existing['year_level'] . '|' . $existing['semester'];
                        $existing_courses[$key] = true;
                    }

                    // Filter out existing items and prepare batch insert
                    $new_items = [];
                    $added_count = 0;
                    $skipped_count = 0;

                    foreach ($items as $item) {
                        $key = $item['course_code'] . '|' . $item['year_level'] . '|' . $item['semester'];
                        if (isset($existing_courses[$key])) {
                            $skipped_count++;
                        } else {
                            $new_items[] = $item;
                            $existing_courses[$key] = true; // Mark as added to prevent duplicates in same batch
                        }
                    }

                    // Batch insert new items using prepared statement (optimization: reuse prepared statement)
                    if (!empty($new_items)) {
                        $insert_stmt = $conn->prepare("INSERT INTO curriculum
                            (program_id, course_code, course_name, units, year_level, semester, is_required, pre_requisites)
                            VALUES (:program_id, :course_code, :course_name, :units, :year_level, :semester, :is_required, :pre_requisites)");
                        
                        foreach ($new_items as $item) {
                            $insert_stmt->execute([
                                ':program_id' => $program_id,
                                ':course_code' => $item['course_code'],
                                ':course_name' => $item['course_name'],
                                ':units' => $item['units'],
                                ':year_level' => $item['year_level'],
                                ':semester' => $item['semester'],
                                ':is_required' => $item['is_required'],
                                ':pre_requisites' => $item['pre_requisites']
                            ]);
                            $added_count++;
                        }
                    }

                    // Update program total units (use same connection for transaction)
                    if ($added_count > 0) {
                        $curriculum->updateProgramTotalUnits($program_id, $conn);
                        
                        // Create a submission record for dean direct upload (for tracking/history)
                        // Get a program head for this program (or use NULL if none exists)
                        $ph_stmt = $conn->prepare("SELECT id FROM program_heads WHERE program_id = :program_id LIMIT 1");
                        $ph_stmt->execute([':program_id' => $program_id]);
                        $program_head = $ph_stmt->fetch(PDO::FETCH_ASSOC);
                        $program_head_id = $program_head ? $program_head['id'] : null;
                        
                        // If no program head exists, we'll need to handle this differently
                        // For now, create submission with a placeholder program_head_id
                        if (!$program_head_id) {
                            // Get first program head as fallback (or create a system one)
                            $ph_fallback = $conn->query("SELECT id FROM program_heads LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                            $program_head_id = $ph_fallback ? $ph_fallback['id'] : 1; // Fallback to ID 1 if exists
                        }
                        
                        if ($program_head_id) {
                            // Create submission record for dean upload
                            $submission_title = "Dean Direct Upload - " . date('Y-m-d H:i:s');
                            $submission_desc = "Curriculum uploaded directly by Dean. Added {$added_count} new subjects.";
                            
                            // Get current academic year and semester (you may need to adjust this logic)
                            $current_year = date('Y') . '-' . (date('Y') + 1);
                            $current_month = (int)date('m');
                            $current_semester = ($current_month >= 6 && $current_month <= 10) ? 'First Semester' : 
                                               (($current_month >= 11 || $current_month <= 2) ? 'Second Semester' : 'Summer');
                            
                            $submission_sql = "INSERT INTO curriculum_submissions
                                (program_head_id, program_id, submission_title, submission_description,
                                 academic_year, semester, status, submitted_at, reviewed_at, reviewed_by, reviewer_comments)
                                VALUES (:program_head_id, :program_id, :submission_title, :submission_description,
                                        :academic_year, :semester, 'approved', NOW(), NOW(), :dean_id, 'Direct upload by Dean - Auto-approved')";
                            
                            $submission_stmt = $conn->prepare($submission_sql);
                            $submission_stmt->execute([
                                ':program_head_id' => $program_head_id,
                                ':program_id' => $program_id,
                                ':submission_title' => $submission_title,
                                ':submission_description' => $submission_desc,
                                ':academic_year' => $current_year,
                                ':semester' => $current_semester,
                                ':dean_id' => $dean_id
                            ]);
                            
                            $submission_id = $conn->lastInsertId();
                            
                            // Create submission items for tracking
                            foreach ($new_items as $item) {
                                $item_sql = "INSERT INTO curriculum_submission_items
                                    (submission_id, course_code, course_name, units, year_level, semester, is_required, pre_requisites, status)
                                    VALUES (:submission_id, :course_code, :course_name, :units, :year_level, :semester, :is_required, :pre_requisites, 'added')";
                                $item_stmt = $conn->prepare($item_sql);
                                $item_stmt->execute([
                                    ':submission_id' => $submission_id,
                                    ':course_code' => $item['course_code'],
                                    ':course_name' => $item['course_name'],
                                    ':units' => $item['units'],
                                    ':year_level' => $item['year_level'],
                                    ':semester' => $item['semester'],
                                    ':is_required' => $item['is_required'],
                                    ':pre_requisites' => $item['pre_requisites']
                                ]);
                            }
                            
                            // Log the dean upload action
                            $log_sql = "INSERT INTO curriculum_submission_logs
                                (submission_id, action, performed_by, role, notes)
                                VALUES (:submission_id, 'dean_direct_upload', :dean_id, 'dean', :notes)";
                            $log_stmt = $conn->prepare($log_sql);
                            $log_stmt->execute([
                                ':submission_id' => $submission_id,
                                ':dean_id' => $dean_id,
                                ':notes' => "Dean directly uploaded {$added_count} subjects to curriculum"
                            ]);
                        }
                    }

                    $conn->commit();
                    
                    // Clean up temp file
                    unlink($temp_path);

                    $message = "Successfully uploaded {$added_count} subjects to curriculum!";
                    if ($skipped_count > 0) {
                        $message .= " Skipped {$skipped_count} existing subjects.";
                    }
                    $message_type = 'success';
                } catch (Exception $e) {
                    $conn->rollBack();
                    if (file_exists($temp_path)) {
                        unlink($temp_path);
                    }
                    $message = 'Upload failed: ' . $e->getMessage();
                    $message_type = 'danger';
                }
            } else {
                $message = 'Failed to upload file. Please try again.';
                $message_type = 'danger';
            }
        }
    } else {
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

// Helper function to parse CSV (similar to ProgramHead class)
function parseCurriculumCSV($file_path) {
    $items = [];
    $handle = fopen($file_path, 'r');

    if ($handle === false) {
        return $items;
    }

    // Read header row to determine format
    $header = fgetcsv($handle);
    if (!$header) {
        fclose($handle);
        return $items;
    }

    // Normalize header to lowercase for comparison
    $header_lower = array_map('strtolower', array_map('trim', $header));
    
    // Determine if first column is program_code
    $has_program_code = false;
    $offset = 0;
    if (isset($header_lower[0]) && in_array($header_lower[0], ['program_code', 'program code', 'program'])) {
        $has_program_code = true;
        $offset = 1; // Skip program_code column
    }

    // Expected columns (after program_code if present): course_code, course_name, units, year_level, semester, is_required, pre_requisites
    while (($data = fgetcsv($handle)) !== false) {
        // Need at least 6 columns (course_code through is_required) plus offset
        if (count($data) >= (6 + $offset)) {
            $course_code = trim($data[0 + $offset]);
            $course_name = trim($data[1 + $offset]);
            $units = (int)trim($data[2 + $offset]);
            $year_level = trim($data[3 + $offset]);
            $semester = trim($data[4 + $offset]);
            
            // Skip empty rows
            if (empty($course_code) || empty($course_name)) {
                continue;
            }
            
            // Parse is_required: can be 1/0, Yes/No, true/false
            $is_required_str = isset($data[5 + $offset]) ? trim($data[5 + $offset]) : '1';
            $is_required = 1;
            if (in_array(strtolower($is_required_str), ['no', '0', 'false', 'n'])) {
                $is_required = 0;
            }
            
            $pre_requisites = isset($data[6 + $offset]) ? trim($data[6 + $offset]) : null;
            
            $items[] = [
                'course_code' => $course_code,
                'course_name' => $course_name,
                'units' => $units > 0 ? $units : 3, // Default to 3 if invalid
                'year_level' => $year_level,
                'semester' => $semester,
                'is_required' => $is_required,
                'pre_requisites' => !empty($pre_requisites) ? $pre_requisites : null
            ];
        }
    }

    fclose($handle);
    return $items;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Curriculum - Dean Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --sidebar-width: 240px;
            --header-height: 56px;
        }
        
        body {
            font-size: 0.875rem;
            overflow-x: hidden;
        }
        
        /* Sidebar */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            bottom: 0;
            width: var(--sidebar-width);
            background: #1e293b;
            color: #e2e8f0;
            overflow-y: auto;
            z-index: 1000;
            transition: all 0.3s;
            display: flex;
            flex-direction: column;
        }
        
        .sidebar-logo {
            padding: 1rem;
            border-bottom: 1px solid #334155;
        }
        
        .sidebar-logo h5 {
            margin: 0;
            font-size: 1rem;
            color: #fff;
        }
        
        .sidebar-menu {
            padding: 0.5rem 0;
            flex: 1;
        }
        
        .sidebar-menu-item {
            padding: 0.6rem 1rem;
            color: #cbd5e1;
            text-decoration: none;
            display: flex;
            align-items: center;
            transition: all 0.2s;
            font-size: 0.875rem;
        }
        
        .sidebar-menu-item:hover {
            background: #334155;
            color: #fff;
        }
        
        .sidebar-menu-item.active {
            background: #3b82f6;
            color: #fff;
        }
        
        .sidebar-menu-item i {
            width: 20px;
            margin-right: 0.6rem;
            font-size: 0.9rem;
        }
        
        .user-info {
            background: #334155;
            padding: 0.75rem 1rem;
            border-top: 1px solid #475569;
            margin-top: auto;
        }
        
        .user-info small {
            display: block;
            color: #94a3b8;
            font-size: 0.75rem;
        }
        
        /* Main content */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 1.5rem;
            min-height: 100vh;
            background: #f1f5f9;
        }
        
        .content-header {
            background: #fff;
            padding: 1rem 1.5rem;
            margin: -1.5rem -1.5rem 1.5rem;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .content-header h4 {
            margin: 0;
            font-size: 1.25rem;
        }
        
        .card {
            border: none;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
        }
        
        .card-header {
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
            padding: 0.75rem 1rem;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-logo">
            <h5><i class="fas fa-university me-2"></i>Dean Panel</h5>
        </div>
        <div class="sidebar-menu">
            <a href="dashboard.php" class="sidebar-menu-item"><i class="fas fa-home"></i><span>Dashboard</span></a>
            <a href="curriculum_approvals.php" class="sidebar-menu-item"><i class="fas fa-book"></i><span>Curriculum Approvals</span></a>
            <a href="bulk_upload.php" class="sidebar-menu-item active"><i class="fas fa-upload"></i><span>Upload Curriculum</span></a>
            <a href="enrollment_reports.php" class="sidebar-menu-item"><i class="fas fa-chart-bar"></i><span>Enrollment Reports</span></a>
            <a href="review_adjustments.php" class="sidebar-menu-item"><i class="fas fa-exchange-alt"></i><span>Review Adjustments</span></a>
            <a href="adjustment_history.php" class="sidebar-menu-item"><i class="fas fa-history"></i><span>Adjustment History</span></a>
            <a href="logout.php" class="sidebar-menu-item"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
        </div>
        
        <div class="user-info">
            <strong><?php echo htmlspecialchars($dean_info['first_name'] . ' ' . $dean_info['last_name']); ?></strong>
            <small>Dean (<?php echo htmlspecialchars($dean_info['admin_id']); ?>)</small>
        </div>
    </div>
    
    <div class="main-content">
        <div class="content-header">
            <h4><i class="fas fa-upload me-2"></i>Upload Curriculum</h4>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $message_type; ?> alert-dismissible fade show">
                <i class="fas fa-info-circle me-2"></i>
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="row">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-file-csv me-2"></i>Upload CSV File</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" enctype="multipart/form-data">
                            <!-- Program Selection -->
                            <div class="mb-3">
                                <label for="program_id" class="form-label">Program *</label>
                                <select class="form-select" id="program_id" name="program_id" required>
                                    <option value="">Select a program...</option>
                                    <?php foreach ($programs as $program): ?>
                                        <option value="<?php echo $program['id']; ?>" 
                                                <?php echo (isset($_POST['program_id']) && $_POST['program_id'] == $program['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($program['program_code'] . ' - ' . $program['program_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Select the program for this curriculum</small>
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

                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Note:</strong> As Dean, your curriculum uploads will be added directly to the curriculum table without requiring approval.
                            </div>

                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>Warning:</strong> Uploading will add courses to the existing curriculum. If you want to replace the entire curriculum, delete the old one first using the button below.
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" name="upload_csv" class="btn btn-success">
                                    <i class="fas fa-upload me-2"></i>Upload & Add to Curriculum
                                </button>
                                <a href="dashboard.php" class="btn btn-outline-secondary">Cancel</a>
                            </div>
                            
                            <hr class="my-4">
                            
                            <!-- Delete Curriculum Section -->
                            <div class="card border-danger">
                                <div class="card-header bg-danger text-white">
                                    <h6 class="mb-0"><i class="fas fa-trash-alt me-2"></i>Delete Curriculum</h6>
                                </div>
                                <div class="card-body">
                                    <p class="text-muted mb-3">
                                        <strong>Delete all curriculum</strong> for the selected program. This action cannot be undone.
                                        Use this if you want to upload a completely new curriculum.
                                    </p>
                                    <div class="d-flex align-items-center gap-2">
                                        <select class="form-select" id="delete_program_id" style="max-width: 300px;">
                                            <option value="">Select program to delete curriculum...</option>
                                            <?php foreach ($programs as $program): ?>
                                                <option value="<?php echo $program['id']; ?>">
                                                    <?php echo htmlspecialchars($program['program_code'] . ' - ' . $program['program_name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="button" class="btn btn-danger" onclick="deleteProgramCurriculum()" id="delete-btn" disabled>
                                            <i class="fas fa-trash-alt me-2"></i>Delete All Curriculum
                                        </button>
                                    </div>
                                    <div id="delete-message" class="mt-3"></div>
                                </div>
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
                            <li><strong>Course Code</strong> - e.g., GE-100, BSN-101</li>
                            <li><strong>Course Name</strong> - e.g., Understanding the Self</li>
                            <li><strong>Units</strong> - e.g., 3</li>
                            <li><strong>Year Level</strong> - 1st Year, 2nd Year, 3rd Year, 4th Year, 5th Year</li>
                            <li><strong>Semester</strong> - First Semester, Second Semester, Summer</li>
                            <li><strong>Is Required</strong> - 1/Yes (required) or 0/No (elective)</li>
                            <li><strong>Prerequisites</strong> - <em>Optional</em>, course codes separated by commas</li>
                        </ol>

                        <hr>
                        <h6>Sample CSV:</h6>
                        <pre class="small bg-light p-2 rounded"><code>Course Code,Course Name,Units,Year Level,Semester,Is Required,Prerequisites
BSN-101,Anatomy and Physiology,4,1st Year,First Semester,Yes,
BSN-102,Fundamentals of Nursing,4,1st Year,First Semester,Yes,BSN-101</code></pre>
                    </div>
                </div>

                <!-- Upload Limits -->
                <div class="card mt-3">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-chart-bar me-2"></i>Upload Limits</h6>
                    </div>
                    <div class="card-body">
                        <ul class="list-unstyled small mb-0">
                            <li><i class="fas fa-check text-success me-2"></i>Max file size: 5MB</li>
                            <li><i class="fas fa-check text-success me-2"></i>Format: CSV only</li>
                            <li><i class="fas fa-check text-success me-2"></i>Directly added to curriculum</li>
                            <li><i class="fas fa-check text-success me-2"></i>Auto-updates program total units</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Enable/disable delete button based on selection
        document.getElementById('delete_program_id').addEventListener('change', function() {
            const deleteBtn = document.getElementById('delete-btn');
            deleteBtn.disabled = !this.value;
        });
        
        function deleteProgramCurriculum() {
            const programId = document.getElementById('delete_program_id').value;
            const programSelect = document.getElementById('delete_program_id');
            const selectedOption = programSelect.options[programSelect.selectedIndex];
            const programName = selectedOption ? selectedOption.text : 'this program';
            
            if (!programId) {
                alert('Please select a program first');
                return;
            }
            
            if (!confirm('Are you sure you want to delete ALL curriculum for ' + programName + '?\n\nThis action cannot be undone and will remove all courses from the curriculum.')) {
                return;
            }
            
            // Show loading state
            const deleteBtn = document.getElementById('delete-btn');
            const deleteMessage = document.getElementById('delete-message');
            const originalText = deleteBtn.innerHTML;
            deleteBtn.disabled = true;
            deleteBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Deleting...';
            deleteMessage.innerHTML = '';
            
            // Send delete request
            const formData = new FormData();
            formData.append('program_id', programId);
            
            fetch('delete_curriculum.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    deleteMessage.innerHTML = '<div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>' + data.message + '</div>';
                    // Reset form
                    programSelect.value = '';
                    deleteBtn.disabled = true;
                    
                    // If the deleted program is selected in the upload form, refresh it
                    const uploadProgramId = document.getElementById('program_id').value;
                    if (uploadProgramId == programId) {
                        // Optionally refresh or show message
                        setTimeout(() => {
                            deleteMessage.innerHTML = '<div class="alert alert-info"><i class="fas fa-info-circle me-2"></i>You can now upload a new curriculum for this program.</div>';
                        }, 2000);
                    }
                } else {
                    deleteMessage.innerHTML = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i>' + data.message + '</div>';
                }
                deleteBtn.innerHTML = originalText;
                deleteBtn.disabled = false;
            })
            .catch(error => {
                deleteMessage.innerHTML = '<div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i>Error: ' + error.message + '</div>';
                deleteBtn.innerHTML = originalText;
                deleteBtn.disabled = false;
            });
        }
    </script>
</body>
</html>

