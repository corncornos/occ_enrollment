<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/Admin.php';
require_once '../classes/User.php';

// Check if user is logged in and is an admin
if (!isLoggedIn() || !isAdmin()) {
    redirect('public/login.php');
}

$admin = new Admin();
$user = new User();

// Validate session against admins table to prevent session confusion
$current_admin_info = $admin->getAdminById($_SESSION['user_id']);
if (!$current_admin_info) {
    // Admin not found in database, logout
    session_destroy();
    redirect('public/login.php');
}

// Get database connection
$conn = (new Database())->getConnection();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['action']) && $_POST['action'] == 'schedule_enrollment') {
        $user_id = $_POST['user_id'];
        $enrollment_date = $_POST['enrollment_date'];
        
        try {
            // Check if student has an enrolled_students record
            $check_query = "SELECT id FROM enrolled_students WHERE user_id = :user_id";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->bindParam(':user_id', $user_id);
            $check_stmt->execute();
            $existing_record = $check_stmt->fetch();
            
            // Sync user data to enrolled_students table with enrollment date
            require_once 'sync_user_to_enrolled_students.php';
            
            // Check if enrolled_students record exists to get current enrollment_date
            $check_date = $conn->prepare("SELECT enrollment_date FROM enrolled_students WHERE user_id = :user_id");
            $check_date->bindParam(':user_id', $user_id);
            $check_date->execute();
            $existing = $check_date->fetch(PDO::FETCH_ASSOC);
            
            // Sync user data (this will create or update the record)
            syncUserToEnrolledStudents($conn, $user_id);
            
            // Update enrollment_date separately
            $update_date = $conn->prepare("UPDATE enrolled_students SET enrollment_date = :enrollment_date WHERE user_id = :user_id");
            $update_date->bindParam(':enrollment_date', $enrollment_date);
            $update_date->bindParam(':user_id', $user_id);
            
            if ($update_date->execute()) {
                $_SESSION['message'] = 'Enrollment date scheduled successfully!';
            } else {
                $_SESSION['message'] = 'Error scheduling enrollment date.';
            }
        } catch (Exception $e) {
            $_SESSION['message'] = 'Error: ' . $e->getMessage();
        }
        
        // Redirect to prevent form resubmission
        header('Location: schedule_enrollment.php');
        exit();
    }
}

// Get all pending students with their enrollment dates
$pending_query = "SELECT u.id as user_id, u.first_name, u.last_name, u.email, u.student_id, u.phone, u.created_at,
                   es.enrollment_date, es.enrolled_date,
                   GROUP_CONCAT(DISTINCT p.program_code ORDER BY p.program_code SEPARATOR ', ') as program_codes,
                   GROUP_CONCAT(DISTINCT s.year_level ORDER BY s.year_level SEPARATOR ', ') as section_year_levels
                   FROM users u
                   LEFT JOIN enrolled_students es ON u.id = es.user_id
                   LEFT JOIN section_enrollments se ON u.id = se.user_id AND se.status = 'active'
                   LEFT JOIN sections s ON se.section_id = s.id
                   LEFT JOIN programs p ON s.program_id = p.id
                   WHERE u.role = 'student' AND (u.enrollment_status = 'pending' OR u.enrollment_status IS NULL)
                   GROUP BY u.id, u.first_name, u.last_name, u.email, u.student_id, u.phone, u.created_at, es.enrollment_date, es.enrolled_date
                   ORDER BY u.created_at DESC";
$pending_stmt = $conn->prepare($pending_query);
$pending_stmt->execute();
$all_pending_students = $pending_stmt->fetchAll(PDO::FETCH_ASSOC);

$message = '';
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Schedule Enrollment - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .sidebar {
            background: linear-gradient(135deg, #1e40af 0%, #1e3a8a 100%);
            min-height: 100vh;
            color: white;
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            overflow-y: auto;
            z-index: 1000;
            width: 25%;
        }
        @media (min-width: 992px) {
            .sidebar {
                width: 16.666667%;
            }
        }
        @media (max-width: 767px) {
            .sidebar {
                width: 100%;
            }
        }
        .main-content {
            margin-left: 25%;
        }
        @media (min-width: 992px) {
            .main-content {
                margin-left: 16.666667%;
            }
        }
        @media (max-width: 767px) {
            .main-content {
                margin-left: 0;
            }
        }
        .nav-link {
            color: rgba(255,255,255,0.8);
            border-radius: 10px;
            margin: 5px 0;
        }
        .nav-link:hover, .nav-link.active {
            background: rgba(255,255,255,0.2);
            color: white;
        }
        .card {
            border: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border-radius: 15px;
        }
        .card-header {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white;
            border-radius: 15px 15px 0 0 !important;
        }
        .btn-schedule {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            border-radius: 20px;
        }
        .btn-edit {
            background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);
            border: none;
            border-radius: 20px;
        }
        .status-scheduled {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
        }
        .status-pending {
            background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%);
        }
        .enrollment-date {
            font-weight: bold;
            color: #2563eb;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-3 col-lg-2 sidebar p-3">
                <div class="text-center mb-4">
                    <i class="fas fa-cog fa-3x mb-2"></i>
                    <h5>Admin Panel</h5>
                </div>
                
                <div class="mb-4">
                    <h6 class="text-white-50">Welcome,</h6>
                    <h5><?php echo $_SESSION['first_name'] . ' ' . $_SESSION['last_name']; ?></h5>
                    <small class="text-white-50">ID: <?php echo $_SESSION['admin_id'] ?? 'N/A'; ?></small><br>
                    <small class="text-white-50">Registrar/Admin</small>
                </div>
                
                <nav class="nav flex-column">
                    <a href="dashboard.php" class="nav-link">
                        <i class="fas fa-tachometer-alt me-2"></i>Dashboard
                    </a>
                    <a href="#" class="nav-link active">
                        <i class="fas fa-calendar-check me-2"></i>Schedule Enrollment
                    </a>
                    <a href="dashboard.php#enrolled-students" class="nav-link">
                        <i class="fas fa-user-graduate me-2"></i>Enrolled Students
                    </a>
                    <a href="dashboard.php#sections" class="nav-link">
                        <i class="fas fa-users-class me-2"></i>Sections
                    </a>
                    <a href="dashboard.php#documents" class="nav-link">
                        <i class="fas fa-file-alt me-2"></i>Document Checklists
                    </a>
                    <a href="../student/logout.php" class="nav-link">
                        <i class="fas fa-sign-out-alt me-2"></i>Logout
                    </a>
                </nav>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-9 col-lg-10 p-4 main-content">
                <?php if ($message): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div>
                        <h2><i class="fas fa-calendar-check me-2"></i>Schedule Pending Student Enrollment</h2>
                        <p class="text-muted">Set enrollment dates for pending students to complete their enrollment process</p>
                    </div>
                </div>
                
                <!-- Enrollment Procedures Card -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-list-ol me-2"></i>Enrollment Procedures for Incoming Freshmen</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <ol class="list-group list-group-numbered">
                                    <li class="list-group-item d-flex justify-content-between align-items-start">
                                        <div class="ms-2 me-auto">
                                            <div class="fw-bold">Fill out the Online Pre-Registration Form</div>
                                        </div>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-start">
                                        <div class="ms-2 me-auto">
                                            <div class="fw-bold">Proceed to the Admissions Office on the scheduled date of enrolment</div>
                                            <small class="text-muted">Students will see their scheduled date in their dashboard</small>
                                        </div>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-start">
                                        <div class="ms-2 me-auto">
                                            <div class="fw-bold">Proceed to Interview and Assessment</div>
                                        </div>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-start">
                                        <div class="ms-2 me-auto">
                                            <div class="fw-bold">Submit Documentary Requirements</div>
                                            <ul class="mt-2 mb-0">
                                                <li><i class="fas fa-check text-success me-1"></i> Academic Requirements</li>
                                                <li><i class="fas fa-check text-success me-1"></i> Library Requirement</li>
                                                <li><i class="fas fa-check text-success me-1"></i> Medical Assessment</li>
                                            </ul>
                                        </div>
                                    </li>
                                    <li class="list-group-item d-flex justify-content-between align-items-start">
                                        <div class="ms-2 me-auto">
                                            <div class="fw-bold">Claim your Certificate of Registration (COR)</div>
                                        </div>
                                    </li>
                                </ol>
                            </div>
                            <div class="col-md-4">
                                <div class="alert alert-info">
                                    <h6><i class="fas fa-info-circle me-2"></i>Important Notes</h6>
                                    <ul class="mb-0 small">
                                        <li>Students will receive their scheduled enrollment date in their dashboard</li>
                                        <li>They must complete all procedures on their assigned date</li>
                                        <li>Late enrollment may result in additional fees</li>
                                        <li>All documents must be original and authenticated</li>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Students List -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0"><i class="fas fa-users me-2"></i>Pending Students - Schedule Enrollment Dates</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-dark">
                                    <tr>
                                        <th>Student ID</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Program</th>
                                        <th>Year Level</th>
                                        <th>Registered</th>
                                        <th>Scheduled Date</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($all_pending_students)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-4">
                                                <i class="fas fa-user-clock fa-3x text-muted mb-3"></i>
                                                <p class="text-muted">No pending students found.</p>
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                    <?php foreach ($all_pending_students as $student): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($student['student_id']); ?></strong></td>
                                            <td><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                                            <td><?php echo htmlspecialchars($student['email']); ?></td>
                                            <td><?php echo htmlspecialchars($student['phone'] ?? '-'); ?></td>
                                            <td><?php 
                                                if (!empty($student['program_codes'])) {
                                                    echo htmlspecialchars($student['program_codes']);
                                                } else {
                                                    echo '-';
                                                }
                                            ?></td>
                                            <td><?php 
                                                if (!empty($student['section_year_levels'])) {
                                                    echo htmlspecialchars($student['section_year_levels']);
                                                } else {
                                                    echo '1st Year';
                                                }
                                            ?></td>
                                            <td><?php echo date('M j, Y', strtotime($student['created_at'])); ?></td>
                                            <td>
                                                <?php if (!empty($student['enrollment_date'])): ?>
                                                    <span class="enrollment-date"><?php echo date('M j, Y', strtotime($student['enrollment_date'])); ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">Not set</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button class="btn btn-schedule btn-sm" onclick="scheduleEnrollment(<?php echo $student['user_id']; ?>, '<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>', '<?php echo $student['enrollment_date'] ?? ''; ?>')">
                                                    <i class="fas fa-calendar-plus me-1"></i>
                                                    <?php echo !empty($student['enrollment_date']) ? 'Edit' : 'Schedule'; ?>
                                                </button>
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
    
    <!-- Schedule Enrollment Modal -->
    <div class="modal fade" id="scheduleModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Schedule Enrollment Date</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="scheduleForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="schedule_enrollment">
                        <input type="hidden" name="user_id" id="schedule_user_id">
                        
                        <div class="mb-3">
                            <label class="form-label">Student Name</label>
                            <input type="text" class="form-control" id="student_name_display" readonly>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Enrollment Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="enrollment_date" id="enrollment_date" required>
                            <div class="form-text">Select the date when the student should complete their enrollment procedures</div>
                        </div>
                        
                        <div class="alert alert-info">
                            <h6><i class="fas fa-info-circle me-2"></i>What happens next?</h6>
                            <ul class="mb-0 small">
                                <li>The student will see their scheduled enrollment date in their dashboard</li>
                                <li>They will be shown the enrollment procedures they need to complete</li>
                                <li>They must visit the Admissions Office on the scheduled date</li>
                            </ul>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-schedule">
                            <i class="fas fa-calendar-check me-1"></i>Schedule Enrollment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function scheduleEnrollment(userId, studentName, currentDate) {
            document.getElementById('schedule_user_id').value = userId;
            document.getElementById('student_name_display').value = studentName;
            
            // Set current date if editing
            if (currentDate) {
                document.getElementById('enrollment_date').value = currentDate;
            } else {
                // Set default date to tomorrow
                const tomorrow = new Date();
                tomorrow.setDate(tomorrow.getDate() + 1);
                document.getElementById('enrollment_date').value = tomorrow.toISOString().split('T')[0];
            }
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('scheduleModal'));
            modal.show();
        }
    </script>
</body>
</html>
