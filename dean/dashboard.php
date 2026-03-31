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

// Get statistics for dashboard
// Total enrollments
$total_enrollments_query = "SELECT COUNT(*) as count FROM next_semester_enrollments";
$total_enrollments_stmt = $conn->prepare($total_enrollments_query);
$total_enrollments_stmt->execute();
$total_enrollments = $total_enrollments_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Pending enrollments
$pending_enrollments_query = "SELECT COUNT(*) as count FROM next_semester_enrollments WHERE request_status = 'pending'";
$pending_enrollments_stmt = $conn->prepare($pending_enrollments_query);
$pending_enrollments_stmt->execute();
$pending_enrollments = $pending_enrollments_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Under review enrollments
$under_review_query = "SELECT COUNT(*) as count FROM next_semester_enrollments WHERE request_status = 'under_review'";
$under_review_stmt = $conn->prepare($under_review_query);
$under_review_stmt->execute();
$under_review_enrollments = $under_review_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Approved enrollments
$approved_query = "SELECT COUNT(*) as count FROM next_semester_enrollments WHERE request_status = 'approved'";
$approved_stmt = $conn->prepare($approved_query);
$approved_stmt->execute();
$approved_enrollments = $approved_stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Pending curriculum submissions
try {
    $pending_curriculum_query = "SELECT COUNT(*) as count FROM curriculum_submissions WHERE status = 'pending' OR (admin_approved = 1 AND dean_approved = 0)";
    $pending_curriculum_stmt = $conn->prepare($pending_curriculum_query);
    $pending_curriculum_stmt->execute();
    $pending_curriculum = $pending_curriculum_stmt->fetch(PDO::FETCH_ASSOC)['count'];
} catch (PDOException $e) {
    // If columns don't exist yet (migration not run), just count pending submissions
    $pending_curriculum_query = "SELECT COUNT(*) as count FROM curriculum_submissions WHERE status = 'pending'";
    $pending_curriculum_stmt = $conn->prepare($pending_curriculum_query);
    $pending_curriculum_stmt->execute();
    $pending_curriculum = $pending_curriculum_stmt->fetch(PDO::FETCH_ASSOC)['count'];
}

// Pending enrollment reports
try {
    $pending_reports_query = "SELECT COUNT(*) as count FROM enrollment_reports WHERE status = 'pending'";
    $pending_reports_stmt = $conn->prepare($pending_reports_query);
    $pending_reports_stmt->execute();
    $pending_reports = $pending_reports_stmt->fetch(PDO::FETCH_ASSOC)['count'];
} catch (PDOException $e) {
    // If table doesn't exist yet (migration not run), set to 0
    $pending_reports = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dean Dashboard - OCC Enrollment System</title>
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
        
        /* Main content */
        .main-content {
            margin-left: var(--sidebar-width);
            padding: 1.5rem;
            min-height: 100vh;
            background: #f1f5f9;
        }
        
        /* Header */
        .content-header {
            background: #fff;
            padding: 1rem 1.5rem;
            margin: -1.5rem -1.5rem 1.5rem;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .content-header h4 {
            margin: 0;
            font-size: 1.25rem;
        }
        
        /* Stats cards */
        .stat-card {
            background: #fff;
            border-radius: 8px;
            padding: 1.25rem;
            border-left: 4px solid #3b82f6;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .stat-card h3 {
            font-size: 1.75rem;
            font-weight: 700;
            margin: 0;
        }
        
        .stat-card p {
            color: #64748b;
            margin: 0.25rem 0 0;
            font-size: 0.875rem;
        }
        
        /* Tables */
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
        }
        
        /* Cards */
        .card {
            border: none;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 1.5rem;
        }
        
        .card-header {
            background: #fff;
            border-bottom: 1px solid #e2e8f0;
            padding: 0.75rem 1rem;
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .card-body {
            padding: 1rem;
        }
        
        /* Badges */
        .badge {
            font-size: 0.75rem;
            padding: 0.35rem 0.6rem;
        }
        
        /* Buttons */
        .btn-sm {
            padding: 0.35rem 0.75rem;
            font-size: 0.8rem;
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
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-logo">
            <h5><i class="fas fa-university me-2"></i>Dean Panel</h5>
            <small class="text-muted">OCC Enrollment System</small>
        </div>
        
        <div class="sidebar-menu">
            <a href="dashboard.php" class="sidebar-menu-item active">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
            <a href="curriculum_approvals.php" class="sidebar-menu-item">
                <i class="fas fa-book"></i>
                <span>Curriculum Approvals</span>
                <?php if ($pending_curriculum > 0): ?>
                    <span class="badge bg-danger ms-auto"><?php echo $pending_curriculum; ?></span>
                <?php endif; ?>
            </a>
            <a href="bulk_upload.php" class="sidebar-menu-item">
                <i class="fas fa-upload"></i>
                <span>Upload Curriculum</span>
            </a>
            <a href="enrollment_reports.php" class="sidebar-menu-item">
                <i class="fas fa-chart-bar"></i>
                <span>Enrollment Reports</span>
                <?php if ($pending_reports > 0): ?>
                    <span class="badge bg-info ms-auto"><?php echo $pending_reports; ?></span>
                <?php endif; ?>
            </a>
            <a href="review_adjustments.php" class="sidebar-menu-item">
                <i class="fas fa-exchange-alt"></i>
                <span>Review Adjustments</span>
            </a>
            <a href="adjustment_history.php" class="sidebar-menu-item">
                <i class="fas fa-history"></i>
                <span>Adjustment History</span>
            </a>
            <a href="logout.php" class="sidebar-menu-item">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
        
        <div class="user-info">
            <strong><?php echo htmlspecialchars($dean_info['first_name'] . ' ' . $dean_info['last_name']); ?></strong>
            <small>Dean (<?php echo htmlspecialchars($dean_info['admin_id']); ?>)</small>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="content-header">
            <div>
                <h4>Dean Panel</h4>
                <small class="text-muted">Welcome, <?php echo htmlspecialchars($dean_info['first_name'] . ' ' . $dean_info['last_name']); ?></small>
            </div>
            <div>
                <span class="text-muted"><i class="fas fa-calendar-alt me-2"></i><?php echo date('F d, Y'); ?></span>
            </div>
        </div>
        
        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-info alert-dismissible fade show">
                <?php echo htmlspecialchars($_SESSION['message']); unset($_SESSION['message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Welcome Message -->
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="fas fa-university" style="font-size: 4rem; color: #3b82f6; margin-bottom: 1.5rem;"></i>
                <h3 class="mb-3">Welcome to Dean Panel</h3>
                <p class="text-muted mb-4">Please select an option from the sidebar to begin.</p>
                
                <div class="row justify-content-center">
                    <div class="col-md-5 mb-3">
                        <a href="curriculum_approvals.php" class="text-decoration-none">
                            <div class="card h-100 border-primary">
                                <div class="card-body">
                                    <i class="fas fa-book text-primary mb-3" style="font-size: 2.5rem;"></i>
                                    <h5 class="card-title">Curriculum Approvals</h5>
                                    <p class="card-text text-muted">Final approval for curriculum changes</p>
                                    <?php if ($pending_curriculum > 0): ?>
                                        <span class="badge bg-danger"><?php echo $pending_curriculum; ?> pending</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    </div>
                    <div class="col-md-5 mb-3">
                        <a href="enrollment_reports.php" class="text-decoration-none">
                            <div class="card h-100 border-info">
                                <div class="card-body">
                                    <i class="fas fa-chart-bar text-info mb-3" style="font-size: 2.5rem;"></i>
                                    <h5 class="card-title">Enrollment Reports</h5>
                                    <p class="card-text text-muted">Review enrollment reports</p>
                                    <?php if ($pending_reports > 0): ?>
                                        <span class="badge bg-info"><?php echo $pending_reports; ?> pending</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

