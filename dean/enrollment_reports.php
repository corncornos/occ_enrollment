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

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $report_id = $_POST['report_id'] ?? null;
    $action = $_POST['action'];
    
    if ($report_id && in_array($action, ['acknowledge', 'comment'])) {
        try {
            if ($action === 'acknowledge') {
                $update_sql = "UPDATE enrollment_reports 
                             SET status = 'acknowledged', 
                                 reviewed_by = :dean_id, 
                                 reviewed_at = NOW() 
                             WHERE id = :report_id";
                $update_stmt = $conn->prepare($update_sql);
                $update_stmt->bindParam(':dean_id', $user_id);
                $update_stmt->bindParam(':report_id', $report_id);
                $update_stmt->execute();
                
                $_SESSION['message'] = 'Report acknowledged successfully.';
            } elseif ($action === 'comment') {
                $comment = trim($_POST['comment'] ?? '');
                if (!empty($comment)) {
                    $update_sql = "UPDATE enrollment_reports 
                                 SET dean_comment = :comment, 
                                     reviewed_by = :dean_id, 
                                     reviewed_at = NOW() 
                                 WHERE id = :report_id";
                    $update_stmt = $conn->prepare($update_sql);
                    $update_stmt->bindParam(':comment', $comment);
                    $update_stmt->bindParam(':dean_id', $user_id);
                    $update_stmt->bindParam(':report_id', $report_id);
                    $update_stmt->execute();
                    
                    $_SESSION['message'] = 'Comment added successfully.';
                }
            }
        } catch (PDOException $e) {
            $_SESSION['message'] = 'Error processing action: ' . $e->getMessage();
        }
        
        redirect('enrollment_reports.php');
    }
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$program_filter = $_GET['program'] ?? 'all';
$academic_year_filter = $_GET['academic_year'] ?? 'all';

// Check if enrollment_reports table exists
$reports = [];
try {
// Build query - only show reports sent to dean (not drafts)
$reports_query = "SELECT er.*, 
                       a.first_name, a.last_name, a.admin_id,
                       rev.first_name as reviewer_fname, rev.last_name as reviewer_lname
                FROM enrollment_reports er
                LEFT JOIN admins a ON er.generated_by = a.id
                LEFT JOIN admins rev ON er.reviewed_by = rev.id
                WHERE er.status != 'draft'";

    $params = [];

if ($status_filter !== 'all') {
    $reports_query .= " AND er.status = :status";
    $params[':status'] = $status_filter;
} else {
    // Default: show pending and acknowledged (not drafts)
    $reports_query .= " AND er.status IN ('pending', 'acknowledged')";
}

    if ($program_filter !== 'all') {
        $reports_query .= " AND er.program_code = :program";
        $params[':program'] = $program_filter;
    }

    if ($academic_year_filter !== 'all') {
        $reports_query .= " AND er.academic_year = :academic_year";
        $params[':academic_year'] = $academic_year_filter;
    }

    $reports_query .= " ORDER BY er.generated_at DESC";

    $reports_stmt = $conn->prepare($reports_query);
    foreach ($params as $key => $value) {
        $reports_stmt->bindValue($key, $value);
    }
    $reports_stmt->execute();
    $reports = $reports_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table doesn't exist or has issues - show empty array
    $reports = [];
    $_SESSION['db_error'] = 'Enrollment reports table not found. Please run the dean system migration.';
}

// Get filter options
$programs = [];
$academic_years = [];
try {
    $programs_query = "SELECT DISTINCT program_code FROM enrollment_reports WHERE program_code IS NOT NULL ORDER BY program_code";
    $programs_stmt = $conn->query($programs_query);
    $programs = $programs_stmt->fetchAll(PDO::FETCH_COLUMN);

    $years_query = "SELECT DISTINCT academic_year FROM enrollment_reports WHERE academic_year IS NOT NULL ORDER BY academic_year DESC";
    $years_stmt = $conn->query($years_query);
    $academic_years = $years_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {
    // Table doesn't exist or is empty
    $programs = [];
    $academic_years = [];
}

// Statistics
$pending_count = count(array_filter($reports, fn($r) => $r['status'] === 'pending'));
$acknowledged_count = count(array_filter($reports, fn($r) => $r['status'] === 'acknowledged'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Enrollment Reports - Dean Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --sidebar-width: 240px;
        }
        
        body {
            font-size: 0.875rem;
            overflow-x: hidden;
        }
        
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
        }
        
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
        
        .stat-card {
            background: #fff;
            border-radius: 8px;
            padding: 1rem;
            border-left: 4px solid #3b82f6;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .stat-card h3 {
            font-size: 1.5rem;
            font-weight: 700;
            margin: 0;
        }
        
        .stat-card p {
            color: #64748b;
            margin: 0.25rem 0 0;
            font-size: 0.8rem;
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
        
        .badge {
            font-size: 0.75rem;
            padding: 0.35rem 0.6rem;
        }
        
        .btn-sm {
            padding: 0.35rem 0.75rem;
            font-size: 0.8rem;
        }
        
        .user-info {
            background: #334155;
            padding: 0.75rem 1rem;
            border-top: 1px solid #475569;
            position: absolute;
            bottom: 0;
            width: 100%;
        }
        
        .user-info small {
            display: block;
            color: #94a3b8;
            font-size: 0.75rem;
        }
        
        .report-metadata {
            background: #f8fafc;
            padding: 0.75rem;
            border-radius: 4px;
            margin-bottom: 0.5rem;
        }
        
        .report-metadata small {
            display: block;
            color: #64748b;
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
            <a href="dashboard.php" class="sidebar-menu-item">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
            <a href="curriculum_approvals.php" class="sidebar-menu-item">
                <i class="fas fa-book"></i>
                <span>Curriculum Approvals</span>
            </a>
            <a href="enrollment_reports.php" class="sidebar-menu-item active">
                <i class="fas fa-chart-bar"></i>
                <span>Enrollment Reports</span>
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
            <h4><i class="fas fa-chart-bar me-2"></i>Enrollment Reports</h4>
            <small class="text-muted">Review and acknowledge enrollment reports generated by administrators</small>
        </div>
        
        <?php if (isset($_SESSION['message'])): ?>
            <div class="alert alert-info alert-dismissible fade show">
                <?php echo htmlspecialchars($_SESSION['message']); unset($_SESSION['message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['db_error'])): ?>
            <div class="alert alert-warning alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?php echo htmlspecialchars($_SESSION['db_error']); unset($_SESSION['db_error']); ?>
                <br><br>
                <a href="../run_dean_fix.php" class="btn btn-sm btn-warning">
                    <i class="fas fa-tools me-1"></i>Run Migration Now
                </a>
            </div>
        <?php endif; ?>
        
        <!-- Statistics -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="stat-card">
                    <h3><?php echo number_format(count($reports)); ?></h3>
                    <p><i class="fas fa-file-alt me-1"></i>Total Reports</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card" style="border-left-color: #f59e0b;">
                    <h3><?php echo number_format($pending_count); ?></h3>
                    <p><i class="fas fa-clock me-1"></i>Pending Review</p>
                </div>
            </div>
            <div class="col-md-4">
                <div class="stat-card" style="border-left-color: #10b981;">
                    <h3><?php echo number_format($acknowledged_count); ?></h3>
                    <p><i class="fas fa-check me-1"></i>Acknowledged</p>
                </div>
            </div>
        </div>
        
        <!-- Filters -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-filter me-2"></i>Filter Reports
            </div>
            <div class="card-body">
                <form method="GET" action="enrollment_reports.php" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label small">Status</label>
                        <select name="status" class="form-select form-select-sm">
                            <option value="all" <?php echo $status_filter === 'all' ? 'selected' : ''; ?>>All Statuses</option>
                            <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>>Pending</option>
                            <option value="acknowledged" <?php echo $status_filter === 'acknowledged' ? 'selected' : ''; ?>>Acknowledged</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Program</label>
                        <select name="program" class="form-select form-select-sm">
                            <option value="all">All Programs</option>
                            <?php foreach ($programs as $program): ?>
                                <option value="<?php echo htmlspecialchars($program); ?>" 
                                        <?php echo $program_filter === $program ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($program); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label small">Academic Year</label>
                        <select name="academic_year" class="form-select form-select-sm">
                            <option value="all">All Years</option>
                            <?php foreach ($academic_years as $year): ?>
                                <option value="<?php echo htmlspecialchars($year); ?>" 
                                        <?php echo $academic_year_filter === $year ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($year); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary btn-sm me-2">
                            <i class="fas fa-search me-1"></i>Filter
                        </button>
                        <a href="enrollment_reports.php" class="btn btn-secondary btn-sm">
                            <i class="fas fa-redo me-1"></i>Reset
                        </a>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Reports List -->
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="fas fa-list me-2"></i>Enrollment Reports</span>
                <span class="badge bg-info"><?php echo count($reports); ?> reports</span>
            </div>
            <div class="card-body">
                <?php if (empty($reports)): ?>
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle me-2"></i>No enrollment reports found.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-compact mb-0">
                            <thead>
                                <tr>
                                    <th>Report Details</th>
                                    <th>Period</th>
                                    <th>Generated By</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($reports as $report): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($report['report_title'] ?? 'Enrollment Report'); ?></strong>
                                            <?php if (!empty($report['program_code'])): ?>
                                                <br><span class="badge bg-secondary"><?php echo htmlspecialchars($report['program_code']); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($report['report_file'])): ?>
                                                <br><small class="text-muted">
                                                    <i class="fas fa-file-pdf me-1"></i><?php echo htmlspecialchars(basename($report['report_file'])); ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small>
                                                <?php echo htmlspecialchars($report['academic_year'] ?? 'N/A'); ?>
                                                <br><?php echo htmlspecialchars($report['semester'] ?? 'N/A'); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <small>
                                                <?php echo htmlspecialchars($report['first_name'] . ' ' . $report['last_name']); ?>
                                                <br><span class="text-muted"><?php echo date('M d, Y', strtotime($report['generated_at'])); ?></span>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="badge bg-<?php echo $report['status'] === 'acknowledged' ? 'success' : 'warning'; ?>">
                                                <?php echo ucfirst($report['status']); ?>
                                            </span>
                                            <?php if ($report['status'] === 'acknowledged' && !empty($report['reviewer_fname'])): ?>
                                                <br><small class="text-muted">
                                                    By: <?php echo htmlspecialchars($report['reviewer_fname'] . ' ' . $report['reviewer_lname']); ?>
                                                    <br><?php echo date('M d, Y', strtotime($report['reviewed_at'])); ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button type="button" class="btn btn-info" data-bs-toggle="modal" 
                                                        data-bs-target="#viewModal<?php echo $report['id']; ?>">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                                <?php if ($report['status'] === 'pending'): ?>
                                                    <button type="button" class="btn btn-success" data-bs-toggle="modal" 
                                                            data-bs-target="#acknowledgeModal<?php echo $report['id']; ?>">
                                                        <i class="fas fa-check"></i> Acknowledge
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    
                                    <!-- View Modal -->
                                    <div class="modal fade" id="viewModal<?php echo $report['id']; ?>" tabindex="-1">
                                        <div class="modal-dialog modal-lg">
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">
                                                        <i class="fas fa-file-alt me-2"></i>
                                                        <?php echo htmlspecialchars($report['report_title'] ?? 'Enrollment Report'); ?>
                                                    </h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <div class="report-metadata">
                                                        <div class="row">
                                                            <div class="col-md-6">
                                                                <small><strong>Program:</strong> <?php echo htmlspecialchars($report['program_code'] ?? 'N/A'); ?></small>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <small><strong>Academic Year:</strong> <?php echo htmlspecialchars($report['academic_year'] ?? 'N/A'); ?></small>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <small><strong>Semester:</strong> <?php echo htmlspecialchars($report['semester'] ?? 'N/A'); ?></small>
                                                            </div>
                                                            <div class="col-md-6">
                                                                <small><strong>Generated:</strong> <?php echo date('M d, Y g:i A', strtotime($report['generated_at'])); ?></small>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    
                                                    <?php 
                                                    // Parse report data
                                                    $report_data_parsed = null;
                                                    if (!empty($report['report_data'])) {
                                                        $report_data_parsed = json_decode($report['report_data'], true);
                                                        // If not JSON, treat as plain text
                                                        if (json_last_error() !== JSON_ERROR_NONE) {
                                                            $report_data_parsed = ['text' => $report['report_data']];
                                                        }
                                                    }
                                                    ?>
                                                    
                                                    <?php if ($report_data_parsed && isset($report_data_parsed['analytics'])): ?>
                                                        <!-- Analytics Dashboard -->
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
                                                                    plugins: {
                                                                        legend: { position: 'bottom' }
                                                                    }
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
                                                                    scales: {
                                                                        y: { beginAtZero: true }
                                                                    }
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
                                                                    plugins: {
                                                                        legend: { position: 'bottom' }
                                                                    }
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
                                                                    scales: {
                                                                        y: { beginAtZero: true }
                                                                    }
                                                                }
                                                            });
                                                        });
                                                        </script>
                                                    <?php elseif ($report_data_parsed && isset($report_data_parsed['text'])): ?>
                                                        <!-- Fallback to text display if no analytics -->
                                                        <div class="mt-3">
                                                            <h6>Report Summary:</h6>
                                                            <pre class="bg-light p-3 rounded" style="max-height: 400px; overflow-y: auto; font-size: 0.8rem;"><?php echo htmlspecialchars($report_data_parsed['text']); ?></pre>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (!empty($report['dean_comment'])): ?>
                                                        <div class="alert alert-info mt-3">
                                                            <strong><i class="fas fa-comment me-2"></i>Dean's Comment:</strong>
                                                            <p class="mb-0 mt-2"><?php echo nl2br(htmlspecialchars($report['dean_comment'])); ?></p>
                                                        </div>
                                                    <?php endif; ?>
                                                    
                                                    <?php if (!empty($report['report_file'])): ?>
                                                        <div class="mt-3">
                                                            <a href="<?php echo htmlspecialchars($report['report_file']); ?>" 
                                                               class="btn btn-primary btn-sm" target="_blank">
                                                                <i class="fas fa-download me-1"></i>Download Report File
                                                            </a>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <!-- Acknowledge Modal -->
                                    <?php if ($report['status'] === 'pending'): ?>
                                        <div class="modal fade" id="acknowledgeModal<?php echo $report['id']; ?>" tabindex="-1">
                                            <div class="modal-dialog">
                                                <div class="modal-content">
                                                    <div class="modal-header">
                                                        <h5 class="modal-title">
                                                            <i class="fas fa-check me-2"></i>Acknowledge Report
                                                        </h5>
                                                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                    </div>
                                                    <form method="POST" action="enrollment_reports.php">
                                                        <input type="hidden" name="report_id" value="<?php echo $report['id']; ?>">
                                                        <div class="modal-body">
                                                            <p>Are you sure you want to acknowledge this enrollment report?</p>
                                                            <div class="report-metadata">
                                                                <strong><?php echo htmlspecialchars($report['report_title'] ?? 'Enrollment Report'); ?></strong>
                                                                <br><small><?php echo htmlspecialchars($report['program_code'] ?? 'N/A'); ?> - <?php echo htmlspecialchars($report['academic_year'] ?? 'N/A'); ?> (<?php echo htmlspecialchars($report['semester'] ?? 'N/A'); ?>)</small>
                                                            </div>
                                                            <div class="mt-3">
                                                                <label class="form-label small">Add Comment (Optional):</label>
                                                                <textarea name="comment" class="form-control form-control-sm" rows="3" 
                                                                          placeholder="Enter your comments or feedback..."></textarea>
                                                            </div>
                                                        </div>
                                                        <div class="modal-footer">
                                                            <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                                                            <button type="submit" name="action" value="acknowledge" class="btn btn-success btn-sm">
                                                                <i class="fas fa-check me-1"></i>Acknowledge
                                                            </button>
                                                        </div>
                                                    </form>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

