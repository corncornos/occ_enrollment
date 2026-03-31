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

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $submission_id = $_POST['submission_id'];
    $action = $_POST['action'];
    $notes = $_POST['notes'] ?? '';
    
    try {
        $conn->beginTransaction();
        
        if ($action === 'approve') {
            // Get submission details first
            $submission_stmt = $conn->prepare("SELECT * FROM curriculum_submissions WHERE id = :submission_id");
            $submission_stmt->bindParam(':submission_id', $submission_id);
            $submission_stmt->execute();
            $submission = $submission_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$submission) {
                throw new Exception('Submission not found');
            }
            
            // Get submission items
            $items_stmt = $conn->prepare("SELECT * FROM curriculum_submission_items WHERE submission_id = :submission_id");
            $items_stmt->bindParam(':submission_id', $submission_id);
            $items_stmt->execute();
            $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Batch check for existing courses (optimization: single query instead of N queries)
            $existing_courses = [];
            $existing_stmt = $conn->prepare("SELECT course_code, year_level, semester 
                                            FROM curriculum 
                                            WHERE program_id = :program_id");
            $existing_stmt->execute([':program_id' => $submission['program_id']]);
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
                        ':program_id' => $submission['program_id'],
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
            
            // Update submission status
            $update_query = "UPDATE curriculum_submissions 
                           SET dean_approved = 1, dean_approved_by = :dean_id, 
                               dean_approved_at = NOW(), dean_notes = :notes,
                               status = 'approved'
                           WHERE id = :submission_id";
            $stmt = $conn->prepare($update_query);
            $stmt->bindParam(':dean_id', $dean_id);
            $stmt->bindParam(':notes', $notes);
            $stmt->bindParam(':submission_id', $submission_id);
            $stmt->execute();
            
            // Mark submission items as added
            $update_items_stmt = $conn->prepare("UPDATE curriculum_submission_items SET status = 'added' WHERE submission_id = :submission_id");
            $update_items_stmt->execute([':submission_id' => $submission_id]);
            
            // Update program total units after syncing curriculum items (use same connection for transaction)
            if ($added_count > 0) {
                $curriculum->updateProgramTotalUnits($submission['program_id'], $conn);
            }
            
            $message = 'Curriculum submission approved successfully!';
            if ($added_count > 0) {
                $message .= " Added {$added_count} new subjects to curriculum.";
            }
            if ($skipped_count > 0) {
                $message .= " Skipped {$skipped_count} existing subjects.";
            }
            $_SESSION['message'] = $message;
        } elseif ($action === 'reject') {
            $update_query = "UPDATE curriculum_submissions 
                           SET status = 'rejected', dean_notes = :notes,
                               dean_approved_by = :dean_id, dean_approved_at = NOW()
                           WHERE id = :submission_id";
            $stmt = $conn->prepare($update_query);
            $stmt->bindParam(':dean_id', $dean_id);
            $stmt->bindParam(':notes', $notes);
            $stmt->bindParam(':submission_id', $submission_id);
            $stmt->execute();
            
            $_SESSION['message'] = 'Curriculum submission rejected.';
        }
        
        // Log the action
        $log_query = "INSERT INTO curriculum_submission_logs 
                     (submission_id, action, performed_by, role, notes) 
                     VALUES (:submission_id, :action, :dean_id, 'dean', :notes)";
        $log_stmt = $conn->prepare($log_query);
        $log_stmt->bindParam(':submission_id', $submission_id);
        $log_stmt->bindParam(':action', $action);
        $log_stmt->bindParam(':dean_id', $dean_id);
        $log_stmt->bindParam(':notes', $notes);
        $log_stmt->execute();
        
        $conn->commit();
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['message'] = 'Error: ' . $e->getMessage();
    }
    
    redirect('dean/curriculum_approvals.php');
}

// Get curriculum submissions
$status_filter = $_GET['status'] ?? 'pending';

// Check if new columns exist
$columns_exist = true;
try {
    $check = $conn->query("SHOW COLUMNS FROM curriculum_submissions LIKE 'admin_approved'");
    $columns_exist = $check->rowCount() > 0;
} catch (PDOException $e) {
    $columns_exist = false;
}

if ($columns_exist) {
    $query = "SELECT cs.*, a.first_name, a.last_name, a.admin_id, p.program_name, p.program_code
              FROM curriculum_submissions cs
              LEFT JOIN admins a ON cs.submitted_by = a.id
              LEFT JOIN programs p ON cs.program_id = p.id
              WHERE 1=1";
    
    if ($status_filter === 'pending') {
        $query .= " AND cs.admin_approved = 1 AND cs.dean_approved = 0";
    } elseif ($status_filter === 'approved') {
        $query .= " AND cs.dean_approved = 1";
    } elseif ($status_filter === 'rejected') {
        $query .= " AND cs.status = 'rejected'";
    }
} else {
    // Fallback for when migration hasn't run yet
    $query = "SELECT cs.*, a.first_name, a.last_name, a.admin_id, p.program_name, p.program_code
              FROM curriculum_submissions cs
              LEFT JOIN admins a ON cs.program_head_id = a.id
              LEFT JOIN programs p ON cs.program_id = p.id
              WHERE 1=1";
    
    if ($status_filter === 'pending') {
        $query .= " AND cs.status = 'pending'";
    } elseif ($status_filter === 'approved') {
        $query .= " AND cs.status = 'approved'";
    } elseif ($status_filter === 'rejected') {
        $query .= " AND cs.status = 'rejected'";
    }
}

$query .= " ORDER BY cs.submitted_at DESC, cs.created_at DESC";

$stmt = $conn->prepare($query);
$stmt->execute();
$submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch curriculum items for each submission
foreach ($submissions as &$submission) {
    $items_stmt = $conn->prepare("SELECT * FROM curriculum_submission_items 
                                  WHERE submission_id = :submission_id 
                                  ORDER BY year_level, semester, course_code");
    $items_stmt->bindParam(':submission_id', $submission['id']);
    $items_stmt->execute();
    $submission['items'] = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
}
unset($submission); // Break reference

// Get dean information for sidebar
$dean_query = "SELECT * FROM admins WHERE id = :id AND is_dean = 1";
$dean_stmt = $conn->prepare($dean_query);
$dean_stmt->bindParam(':id', $dean_id);
$dean_stmt->execute();
$dean_info = $dean_stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Curriculum Approvals - Dean Panel</title>
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
        
        .table-compact {
            font-size: 0.875rem;
        }
        
        .table-compact th {
            background: #f8fafc;
            padding: 0.6rem;
            font-weight: 600;
        }
        
        .table-compact td {
            padding: 0.6rem;
            vertical-align: middle;
        }
        
        .badge {
            font-size: 0.75rem;
            padding: 0.35rem 0.6rem;
        }
        
        .btn-sm {
            padding: 0.35rem 0.75rem;
            font-size: 0.8rem;
        }
        
        .submission-details {
            background: #f8fafc;
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1rem;
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
            <a href="curriculum_approvals.php" class="sidebar-menu-item active"><i class="fas fa-book"></i><span>Curriculum Approvals</span></a>
            <a href="bulk_upload.php" class="sidebar-menu-item"><i class="fas fa-upload"></i><span>Upload Curriculum</span></a>
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
            <h4><i class="fas fa-check-double me-2"></i>Curriculum Approvals</h4>
        </div>
        
        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-info alert-dismissible fade show">
                <?php echo htmlspecialchars($_SESSION['message']); unset($_SESSION['message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <div class="card-body">
                <!-- Filter Tabs -->
                <ul class="nav nav-tabs mb-3">
                    <li class="nav-item">
                        <a class="nav-link <?php echo $status_filter == 'pending' ? 'active' : ''; ?>" href="?status=pending">
                            <i class="fas fa-clock me-1"></i>Pending
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $status_filter == 'approved' ? 'active' : ''; ?>" href="?status=approved">
                            <i class="fas fa-check-circle me-1"></i>Approved
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $status_filter == 'rejected' ? 'active' : ''; ?>" href="?status=rejected">
                            <i class="fas fa-times-circle me-1"></i>Rejected
                        </a>
                    </li>
                </ul>
                
                <!-- Submissions List -->
                <?php if (empty($submissions)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>No curriculum submissions found for this filter.
                    </div>
                <?php else: ?>
                    <?php foreach ($submissions as $submission): ?>
                        <div class="submission-details">
                            <div class="row">
                                <div class="col-md-8">
                                    <h6><i class="fas fa-book me-2"></i><?php echo htmlspecialchars($submission['program_code']); ?> - <?php echo htmlspecialchars($submission['program_name'] ?? 'Unknown Program'); ?></h6>
                                    <small class="text-muted">
                                        <i class="fas fa-calendar me-1"></i><?php echo htmlspecialchars($submission['academic_year']); ?> - <?php echo htmlspecialchars($submission['semester']); ?>
                                    </small>
                                    <br><small class="text-muted">
                                        <i class="fas fa-user me-1"></i>Submitted by: <?php echo htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']); ?>
                                        (<?php echo htmlspecialchars($submission['admin_id']); ?>)
                                    </small>
                                    <br><small class="text-muted">
                                        <i class="fas fa-clock me-1"></i>Submitted: <?php echo date('F d, Y g:i A', strtotime($submission['submitted_at'] ?? $submission['created_at'])); ?>
                                    </small>
                                </div>
                                <div class="col-md-4 text-end">
                                    <span class="badge bg-<?php 
                                        echo $submission['status'] == 'approved' ? 'success' : 
                                            ($submission['status'] == 'pending' ? 'warning' : 'danger'); 
                                    ?>">
                                        <?php echo ucfirst($submission['status']); ?>
                                    </span>
                                    <?php if ($submission['dean_approved']): ?>
                                        <br><span class="badge bg-success mt-1"><i class="fas fa-check me-1"></i>Dean Approved</span>
                                    <?php endif; ?>
                                    <br>
                                    <?php if ($status_filter == 'pending'): ?>
                                        <button class="btn btn-sm btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#reviewModal<?php echo $submission['id']; ?>">
                                            <i class="fas fa-eye me-1"></i>Review
                                        </button>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-outline-info mt-2" data-bs-toggle="modal" data-bs-target="#viewModal<?php echo $submission['id']; ?>">
                                            <i class="fas fa-eye me-1"></i>View Details
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <?php if (!empty($submission['dean_notes'])): ?>
                                <div class="mt-2 p-2 bg-white rounded">
                                    <small><strong>Dean Notes:</strong> <?php echo htmlspecialchars($submission['dean_notes']); ?></small>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Review Modal -->
                        <div class="modal fade" id="reviewModal<?php echo $submission['id']; ?>" tabindex="-1">
                            <div class="modal-dialog modal-xl">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Review Curriculum Submission</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <form method="POST">
                                        <div class="modal-body">
                                            <input type="hidden" name="submission_id" value="<?php echo $submission['id']; ?>">
                                            
                                            <!-- Submission Info -->
                                            <div class="row mb-3">
                                                <div class="col-md-6">
                                                    <p><strong>Program:</strong> <?php echo htmlspecialchars($submission['program_code'] . ' - ' . ($submission['program_name'] ?? 'Unknown Program')); ?></p>
                                                    <p><strong>Term:</strong> <?php echo htmlspecialchars($submission['academic_year'] . ' - ' . $submission['semester']); ?></p>
                                                </div>
                                                <div class="col-md-6">
                                                    <p><strong>Submitted by:</strong> <?php echo htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']); ?></p>
                                                    <p><strong>Total Subjects:</strong> <span class="badge bg-info"><?php echo count($submission['items'] ?? []); ?></span></p>
                                                </div>
                                            </div>
                                            
                                            <!-- Curriculum Subjects Table -->
                                            <?php if (!empty($submission['items'])): ?>
                                                <div class="mb-3">
                                                    <h6 class="mb-2"><i class="fas fa-list me-2"></i>Curriculum Subjects</h6>
                                                    <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                                        <table class="table table-sm table-striped table-hover table-bordered">
                                                            <thead class="table-light sticky-top">
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
                                                                foreach ($submission['items'] as $item) {
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
                                                                            <td class="text-center"><?php echo $item['units']; ?></td>
                                                                            <td><?php echo htmlspecialchars($item['year_level']); ?></td>
                                                                            <td><?php echo htmlspecialchars($item['semester']); ?></td>
                                                                            <td class="text-center">
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
                                                </div>
                                            <?php else: ?>
                                                <div class="alert alert-warning">
                                                    <i class="fas fa-exclamation-triangle me-2"></i>No subjects found in this submission.
                                                </div>
                                            <?php endif; ?>
                                            
                                            <!-- Dean Notes -->
                                            <div class="mb-3">
                                                <label class="form-label">Dean Notes (Optional)</label>
                                                <textarea name="notes" class="form-control" rows="3" placeholder="Add notes or comments about this curriculum submission..."></textarea>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                                            <button type="submit" name="action" value="reject" class="btn btn-danger">
                                                <i class="fas fa-times me-1"></i>Reject
                                            </button>
                                            <button type="submit" name="action" value="approve" class="btn btn-success">
                                                <i class="fas fa-check me-1"></i>Approve
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                        
                        <!-- View Modal -->
                        <div class="modal fade" id="viewModal<?php echo $submission['id']; ?>" tabindex="-1">
                            <div class="modal-dialog modal-xl">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title">Curriculum Submission Details</h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                    </div>
                                    <div class="modal-body">
                                        <!-- Submission Info -->
                                        <div class="row mb-3">
                                            <div class="col-md-6">
                                                <p><strong>Program:</strong> <?php echo htmlspecialchars($submission['program_code'] . ' - ' . ($submission['program_name'] ?? 'Unknown Program')); ?></p>
                                                <p><strong>Term:</strong> <?php echo htmlspecialchars($submission['academic_year'] . ' - ' . $submission['semester']); ?></p>
                                                <p><strong>Status:</strong> <span class="badge bg-<?php 
                                                    echo $submission['status'] == 'approved' ? 'success' : 'danger'; 
                                                ?>"><?php echo ucfirst($submission['status']); ?></span></p>
                                            </div>
                                            <div class="col-md-6">
                                                <p><strong>Submitted by:</strong> <?php echo htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']); ?></p>
                                                <?php if ($submission['dean_approved_at']): ?>
                                                    <p><strong>Reviewed:</strong> <?php echo date('F d, Y g:i A', strtotime($submission['dean_approved_at'])); ?></p>
                                                <?php endif; ?>
                                                <p><strong>Total Subjects:</strong> <span class="badge bg-info"><?php echo count($submission['items'] ?? []); ?></span></p>
                                            </div>
                                        </div>
                                        
                                        <?php if (!empty($submission['dean_notes'])): ?>
                                            <div class="alert alert-info mb-3">
                                                <strong>Dean Notes:</strong><br><?php echo nl2br(htmlspecialchars($submission['dean_notes'])); ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <!-- Curriculum Subjects Table -->
                                        <?php if (!empty($submission['items'])): ?>
                                            <div class="mb-3">
                                                <h6 class="mb-2"><i class="fas fa-list me-2"></i>Curriculum Subjects</h6>
                                                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                                                    <table class="table table-sm table-striped table-hover table-bordered">
                                                        <thead class="table-light sticky-top">
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
                                                            foreach ($submission['items'] as $item) {
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
                                                                        <td class="text-center"><?php echo $item['units']; ?></td>
                                                                        <td><?php echo htmlspecialchars($item['year_level']); ?></td>
                                                                        <td><?php echo htmlspecialchars($item['semester']); ?></td>
                                                                        <td class="text-center">
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
                                            </div>
                                        <?php else: ?>
                                            <div class="alert alert-warning">
                                                <i class="fas fa-exclamation-triangle me-2"></i>No subjects found in this submission.
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

