<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';

if (!isLoggedIn() || !isDean()) {
    redirect('public/login.php');
}

$conn = (new Database())->getConnection();
$user_id = $_SESSION['user_id'];

// Get dean information
$admin_query = "SELECT * FROM admins WHERE id = :id AND is_dean = 1";
$admin_stmt = $conn->prepare($admin_query);
$admin_stmt->bindParam(':id', $user_id);
$admin_stmt->execute();
$dean_info = $admin_stmt->fetch(PDO::FETCH_ASSOC);

if (!$dean_info) {
    $_SESSION['message'] = 'Access denied. Dean account not found.';
    redirect('public/login.php');
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$search = $_GET['search'] ?? '';

// Build query
$query = "SELECT nse.*, u.student_id, u.first_name, u.last_name, u.email,
          s.section_name, p.program_code, p.program_name
          FROM next_semester_enrollments nse
          JOIN users u ON nse.user_id = u.id
          LEFT JOIN sections s ON nse.selected_section_id = s.id
          LEFT JOIN programs p ON s.program_id = p.id
          WHERE 1=1";

if ($status_filter != 'all') {
    $query .= " AND nse.request_status = :status";
}

if (!empty($search)) {
    $query .= " AND (u.student_id LIKE :search OR u.first_name LIKE :search OR u.last_name LIKE :search)";
}

$query .= " ORDER BY nse.created_at DESC";

$stmt = $conn->prepare($query);

if ($status_filter != 'all') {
    $stmt->bindParam(':status', $status_filter);
}

if (!empty($search)) {
    $search_param = "%$search%";
    $stmt->bindParam(':search', $search_param);
}

$stmt->execute();
$enrollments = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Enrollments - Dean Panel</title>
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
            <a href="view_enrollments.php" class="sidebar-menu-item active"><i class="fas fa-users"></i><span>View Enrollments</span></a>
            <a href="curriculum_approvals.php" class="sidebar-menu-item"><i class="fas fa-book"></i><span>Curriculum Approvals</span></a>
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
            <h4><i class="fas fa-users me-2"></i>View Enrollments</h4>
        </div>
        
        <div class="card">
            <div class="card-body">
                <!-- Filters -->
                <form method="GET" class="row g-3 mb-3">
                    <div class="col-md-4">
                        <input type="text" class="form-control form-control-sm" name="search" placeholder="Search by ID or name..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                    <div class="col-md-3">
                        <select name="status" class="form-select form-select-sm">
                            <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All Status</option>
                            <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="under_review" <?php echo $status_filter == 'under_review' ? 'selected' : ''; ?>>Under Review</option>
                            <option value="approved" <?php echo $status_filter == 'approved' ? 'selected' : ''; ?>>Approved</option>
                            <option value="rejected" <?php echo $status_filter == 'rejected' ? 'selected' : ''; ?>>Rejected</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="fas fa-search me-1"></i>Filter</button>
                    </div>
                    <div class="col-md-3">
                        <a href="view_enrollments.php" class="btn btn-secondary btn-sm w-100"><i class="fas fa-redo me-1"></i>Reset</a>
                    </div>
                </form>
                
                <!-- Results -->
                <div class="table-responsive">
                    <table class="table table-compact table-hover">
                        <thead>
                            <tr>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Program</th>
                                <th>Target Term</th>
                                <th>Section</th>
                                <th>Status</th>
                                <th>Submitted</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($enrollments)): ?>
                                <tr><td colspan="8" class="text-center text-muted">No enrollments found</td></tr>
                            <?php else: ?>
                                <?php foreach ($enrollments as $enrollment): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($enrollment['student_id']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($enrollment['first_name'] . ' ' . $enrollment['last_name']); ?></td>
                                        <td><small><?php echo htmlspecialchars($enrollment['program_code']); ?></small></td>
                                        <td><small><?php echo htmlspecialchars($enrollment['target_academic_year'] . ' - ' . $enrollment['target_semester']); ?></small></td>
                                        <td><?php echo htmlspecialchars($enrollment['section_name'] ?? 'Not assigned'); ?></td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo $enrollment['request_status'] == 'approved' ? 'success' : 
                                                    ($enrollment['request_status'] == 'pending' ? 'warning' : 
                                                    ($enrollment['request_status'] == 'under_review' ? 'info' : 'danger')); 
                                            ?>">
                                                <?php echo ucfirst(str_replace('_', ' ', $enrollment['request_status'])); ?>
                                            </span>
                                        </td>
                                        <td><small><?php echo date('M d, Y', strtotime($enrollment['created_at'])); ?></small></td>
                                        <td>
                                            <a href="../admin/review_next_semester.php?request_id=<?php echo $enrollment['id']; ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <div class="alert alert-info mt-3 mb-0">
                    <small><i class="fas fa-info-circle me-2"></i><strong>Note:</strong> As dean, you have view-only access to enrollments. Final approvals are handled by administrators after registrar review.</small>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

