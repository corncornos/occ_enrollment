<?php
require_once '../config/database.php';
require_once '../classes/Admission.php';

// Check authentication
if (!isLoggedIn() || !isAdmission()) {
    redirect('public/login.php');
}

$admission = new Admission();
$stats = $admission->getDashboardStats();
$applicants = $admission->getPendingApplicants();
$enrollment_control = $admission->getEnrollmentControl();

$first_name = $_SESSION['first_name'] ?? 'Admission';
$last_name = $_SESSION['last_name'] ?? 'Officer';

// Handle document viewing
$view_documents_user_id = $_GET['view_documents'] ?? null;
$selected_applicant = null;
$applicant_documents = [];

if ($view_documents_user_id) {
    $selected_applicant = $admission->getApplicantById($view_documents_user_id);
    if ($selected_applicant) {
        $applicant_documents = $admission->getDocumentUploads($view_documents_user_id);
    }
}

$document_labels = [
    'id_pictures' => '2x2 ID Pictures (4 pcs)',
    'psa_birth_certificate' => 'PSA Birth Certificate',
    'barangay_certificate' => 'Barangay Certificate of Residency',
    'voters_id' => 'Voter\'s ID or Registration Stub',
    'high_school_diploma' => 'High School Diploma',
    'sf10_form' => 'SF10 (Senior High School Permanent Record)',
    'form_138' => 'Form 138 (Report Card)',
    'good_moral' => 'Certificate of Good Moral Character',
    'birth_certificate' => 'Birth Certificate (PSA)', // Legacy support
    'form_137' => 'Form 137 (Report Card)', // Legacy support
    'transfer_credentials' => 'Transfer Credentials (if applicable)'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admission Dashboard - OCC</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
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
        .stat-card { border-radius: 10px; border-left: 4px solid; padding: 20px; margin-bottom: 20px; }
        .stat-card.primary { border-color: #1e40af; background: #dbeafe; }
        .btn-primary, .bg-primary {
            background-color: #1e40af !important;
            border-color: #1e40af !important;
        }
        .btn-primary:hover {
            background-color: #1e3a8a !important;
            border-color: #1e3a8a !important;
        }
        .text-primary {
            color: #1e40af !important;
        }
        .badge.bg-primary {
            background-color: #1e40af !important;
        }
        .bg-info, .btn-info {
            background-color: #1e40af !important;
            border-color: #1e40af !important;
        }
        .btn-info:hover {
            background-color: #1e3a8a !important;
            border-color: #1e3a8a !important;
        }
        .text-info {
            color: #1e40af !important;
        }
        .badge.bg-info {
            background-color: #1e40af !important;
        }
        .stat-card.info { border-color: #1e40af; background: #dbeafe; }
        .stat-card.warning { border-color: #ffc107; background: #fff9e6; }
        .stat-card.success { border-color: #28a745; background: #e8f5e9; }
        .stat-card.info { border-color: #17a2b8; background: #e3f2fd; }
        .badge-status { padding: 5px 10px; border-radius: 5px; font-size: 0.85em; }
        .table-actions button { margin: 2px; padding: 5px 10px; font-size: 0.85em; }
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
        .content-section h2 {
            font-size: 1.5rem !important;
            margin-bottom: 15px !important;
        }
        .content-section h3 {
            font-size: 1.25rem !important;
            margin-bottom: 12px !important;
        }
        .row {
            margin-bottom: 15px !important;
        }
        .mb-4 {
            margin-bottom: 15px !important;
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
                         onerror="this.style.display='none'; document.getElementById('admissionSidebarFallback').classList.remove('d-none');">
                    <i id="admissionSidebarFallback" class="fas fa-user-graduate d-none" style="font-size: 24px; color: white;"></i>
                    <h4>Admission Office</h4>
                </div>
                <div class="sidebar-menu">
                    <nav class="nav flex-column">
                        <a class="nav-link active" href="#dashboard" onclick="showSection('dashboard'); return false;">
                            <i class="fas fa-home"></i>
                            <span>Dashboard</span>
                        </a>
                        <a class="nav-link" href="#applicants" onclick="showSection('applicants'); return false;">
                            <i class="fas fa-users"></i>
                            <span>Applicants</span>
                        </a>
                        <a class="nav-link" href="#documents" onclick="showSection('documents'); return false;">
                            <i class="fas fa-file-alt"></i>
                            <span>Documents</span>
                        </a>
                        <a class="nav-link" href="#enrollment-control" onclick="showSection('enrollment-control'); return false;">
                            <i class="fas fa-cog"></i>
                            <span>Enrollment Control</span>
                        </a>
                        <a class="nav-link" href="logout.php">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Logout</span>
                        </a>
                    </nav>
                </div>
                <div class="sidebar-user">
                    <h6>Welcome,</h6>
                    <h5><?php echo $first_name . ' ' . $last_name; ?></h5>
                </div>
            </div>

            <!-- Top Navigation Bar -->
            <nav class="top-nav">
                <div class="top-nav-left">
                    <h4>Dashboard</h4>
                </div>
                <div class="top-nav-right">
                    <div class="top-nav-user">
                        <h6>Welcome,</h6>
                        <h5><?php echo $first_name . ' ' . $last_name; ?></h5>
                    </div>
                </div>
            </nav>

            <!-- Main Content -->
            <div class="col-md-10 p-3 main-content">
                <!-- Dashboard Section -->
                <div id="dashboard-section" class="content-section">
                    <h2 class="mb-4"><i class="fas fa-tachometer-alt me-2"></i> Dashboard Overview</h2>
                    
                    <div class="row">
                        <div class="col-md-3">
                            <div class="stat-card primary">
                                <h6 class="text-muted">Total Pending</h6>
                                <h2 class="mb-0"><?php echo $stats['total_pending']; ?></h2>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card warning">
                                <h6 class="text-muted">Pending Review</h6>
                                <h2 class="mb-0"><?php echo $stats['pending_review']; ?></h2>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card info">
                                <h6 class="text-muted">Student Numbers Assigned</h6>
                                <h2 class="mb-0"><?php echo $stats['student_numbers_assigned']; ?></h2>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card success">
                                <h6 class="text-muted">Passed to Registrar</h6>
                                <h2 class="mb-0"><?php echo $stats['passed_to_registrar']; ?></h2>
                            </div>
                        </div>
                    </div>

                    <div class="row mt-4">
                        <div class="col-md-12">
                            <div class="card">
                                <div class="card-body">
                                    <h5><i class="fas fa-info-circle me-2"></i> Quick Stats</h5>
                                    <table class="table">
                                        <tr>
                                            <td>Documents Incomplete</td>
                                            <td class="text-end"><strong><?php echo $stats['documents_incomplete']; ?></strong></td>
                                        </tr>
                                        <tr>
                                            <td>Approved (Ready to Pass)</td>
                                            <td class="text-end"><strong><?php echo $stats['approved']; ?></strong></td>
                                        </tr>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Applicants Section -->
                <div id="applicants-section" class="content-section" style="display:none;">
                    <h2 class="mb-4"><i class="fas fa-users me-2"></i> Pending Applicants</h2>
                    
                    <div class="card">
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover" id="applicantsTable">
                                    <thead>
                                        <tr>
                                            <th>Name</th>
                                            <th>Email</th>
                                            <th>Program</th>
                                            <th>Student Number</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($applicants as $app): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?></td>
                                            <td><?php echo htmlspecialchars($app['email']); ?></td>
                                            <td><?php echo htmlspecialchars($app['preferred_program'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php if ($app['student_id']): ?>
                                                    <span class="badge bg-success"><?php echo $app['student_id']; ?></span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Not Assigned</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                $status = $app['admission_status'] ?? 'pending_review';
                                                $badge_class = 'bg-secondary';
                                                if ($status == 'approved') $badge_class = 'bg-success';
                                                elseif ($status == 'documents_incomplete') $badge_class = 'bg-warning';
                                                elseif ($status == 'rejected') $badge_class = 'bg-danger';
                                                ?>
                                                <span class="badge <?php echo $badge_class; ?>"><?php echo str_replace('_', ' ', ucfirst($status)); ?></span>
                                            </td>
                                            <td class="table-actions">
                                                <button class="btn btn-sm btn-primary" onclick="viewApplicant(<?php echo $app['user_id']; ?>)">
                                                    <i class="fas fa-eye"></i> View
                                                </button>
                                                <?php if (!$app['student_number_assigned']): ?>
                                                <button class="btn btn-sm btn-success" onclick="assignNumber(<?php echo $app['user_id']; ?>)">
                                                    <i class="fas fa-id-card"></i> Assign Number
                                                </button>
                                                <?php endif; ?>
                                                <?php if ($app['student_number_assigned'] && !$app['passed_to_registrar']): ?>
                                                <button class="btn btn-sm btn-info" onclick="passToRegistrar(<?php echo $app['user_id']; ?>)">
                                                    <i class="fas fa-arrow-right"></i> Pass to Registrar
                                                </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        <?php if (empty($applicants)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted py-4">
                                                <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                                                No pending applicants at this time
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Documents Section -->
                <div id="documents-section" class="content-section" style="display:none;">
                    <h2 class="mb-4"><i class="fas fa-file-alt me-2"></i> Document Verification</h2>
                    
                    <!-- Select Applicant -->
                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h5 class="mb-0">Select Applicant to View Documents</h5>
                                <?php if ($view_documents_user_id): ?>
                                <button class="btn btn-sm btn-outline-primary" onclick="refreshDocuments()" title="Refresh Documents">
                                    <i class="fas fa-sync-alt"></i> Refresh
                                </button>
                                <?php endif; ?>
                            </div>
                            <select class="form-select" id="documentApplicantSelect" onchange="window.location.href='?view_documents='+this.value+'&t='+Date.now()+'#documents'">
                                <option value="">-- Select Applicant --</option>
                                <?php foreach ($applicants as $app): ?>
                                <option value="<?php echo $app['user_id']; ?>" <?php echo ($view_documents_user_id == $app['user_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($app['first_name'] . ' ' . $app['last_name']); ?>
                                    <?php if ($app['student_id']): ?>
                                        (<?php echo $app['student_id']; ?>)
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Documents Display -->
                    <?php if ($selected_applicant): ?>
                    <div class="card">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0">
                                <i class="fas fa-user me-2"></i>
                                Documents for: <?php echo htmlspecialchars($selected_applicant['first_name'] . ' ' . $selected_applicant['last_name']); ?>
                                <?php if ($selected_applicant['student_id']): ?>
                                    (<?php echo $selected_applicant['student_id']; ?>)
                                <?php endif; ?>
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($applicant_documents)): ?>
                            <div class="row">
                                <?php foreach ($applicant_documents as $doc): ?>
                                <?php
                                $status_badge = 'secondary';
                                $status_text = 'Pending';
                                if ($doc['verification_status'] == 'verified') {
                                    $status_badge = 'success';
                                    $status_text = 'Verified';
                                } elseif ($doc['verification_status'] == 'rejected') {
                                    $status_badge = 'danger';
                                    $status_text = 'Rejected';
                                }
                                ?>
                                <div class="col-md-6 mb-3">
                                    <div class="card h-100">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start mb-3">
                                                <h6 class="mb-0">
                                                    <i class="fas fa-file-alt me-2"></i>
                                                    <?php echo $document_labels[$doc['document_type']] ?? $doc['document_type']; ?>
                                                </h6>
                                                <span class="badge bg-<?php echo $status_badge; ?>">
                                                    <?php echo $status_text; ?>
                                                </span>
                                            </div>
                                            
                                            <div class="mb-2">
                                                <small class="text-muted">
                                                    <i class="fas fa-calendar me-1"></i>
                                                    Uploaded: <?php echo date('M j, Y g:i A', strtotime($doc['upload_date'])); ?>
                                                </small>
                                                <br>
                                                <small class="text-muted">
                                                    <i class="fas fa-file me-1"></i>
                                                    <?php echo $doc['file_name']; ?>
                                                    (<?php echo number_format($doc['file_size'] / 1024, 1); ?> KB)
                                                </small>
                                            </div>
                                            
                                            <?php if ($doc['verification_status'] == 'rejected' && $doc['rejection_reason']): ?>
                                            <div class="alert alert-danger py-2 mb-2">
                                                <small>
                                                    <strong>Rejection Reason:</strong><br>
                                                    <?php echo htmlspecialchars($doc['rejection_reason']); ?>
                                                </small>
                                            </div>
                                            <?php endif; ?>
                                            
                                            <div class="btn-group w-100 mt-2">
                                                <button class="btn btn-sm btn-outline-primary" onclick="viewDocument('<?php echo htmlspecialchars($doc['file_path']); ?>')">
                                                    <i class="fas fa-eye me-1"></i> View
                                                </button>
                                                <?php if ($doc['verification_status'] != 'verified'): ?>
                                                <button class="btn btn-sm btn-outline-success" onclick="verifyDocument(<?php echo $doc['id']; ?>)">
                                                    <i class="fas fa-check me-1"></i> Verify
                                                </button>
                                                <?php endif; ?>
                                                <?php if ($doc['verification_status'] != 'rejected'): ?>
                                                <button class="btn btn-sm btn-outline-danger" onclick="rejectDocument(<?php echo $doc['id']; ?>)">
                                                    <i class="fas fa-times me-1"></i> Reject
                                                </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <!-- Document Summary -->
                            <?php
                            $total_docs = count($document_labels);
                            $uploaded_docs = count($applicant_documents);
                            $verified_docs = 0;
                            $rejected_docs = 0;
                            foreach ($applicant_documents as $doc) {
                                if ($doc['verification_status'] == 'verified') $verified_docs++;
                                if ($doc['verification_status'] == 'rejected') $rejected_docs++;
                            }
                            ?>
                            <div class="alert alert-<?php echo ($verified_docs == $total_docs) ? 'success' : 'info'; ?> mt-3">
                                <h6><i class="fas fa-chart-bar me-2"></i> Document Status Summary</h6>
                                <div class="row">
                                    <div class="col-md-3">
                                        <strong>Uploaded:</strong> <?php echo $uploaded_docs; ?>/<?php echo $total_docs; ?>
                                    </div>
                                    <div class="col-md-3">
                                        <strong>Verified:</strong> <span class="text-success"><?php echo $verified_docs; ?></span>
                                    </div>
                                    <div class="col-md-3">
                                        <strong>Rejected:</strong> <span class="text-danger"><?php echo $rejected_docs; ?></span>
                                    </div>
                                    <div class="col-md-3">
                                        <strong>Pending:</strong> <span class="text-warning"><?php echo $uploaded_docs - $verified_docs - $rejected_docs; ?></span>
                                    </div>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                No documents uploaded yet for this applicant.
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Enrollment Control Section -->
                <div id="enrollment-control-section" class="content-section" style="display:none;">
                    <h2 class="mb-4"><i class="fas fa-cog me-2"></i> Enrollment Control</h2>
                    
                    <div class="card">
                        <div class="card-body">
                            <form id="enrollmentControlForm">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Academic Year</label>
                                        <input type="text" class="form-control" name="academic_year" 
                                               value="<?php echo $enrollment_control['academic_year'] ?? 'AY 2024-2025'; ?>" required>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Semester</label>
                                        <select class="form-control" name="semester" required>
                                            <option value="First Semester" <?php echo (isset($enrollment_control['semester']) && $enrollment_control['semester'] == 'First Semester') ? 'selected' : ''; ?>>First Semester</option>
                                            <option value="Second Semester" <?php echo (isset($enrollment_control['semester']) && $enrollment_control['semester'] == 'Second Semester') ? 'selected' : ''; ?>>Second Semester</option>
                                            <option value="Summer" <?php echo (isset($enrollment_control['semester']) && $enrollment_control['semester'] == 'Summer') ? 'selected' : ''; ?>>Summer</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Opening Date</label>
                                        <input type="date" class="form-control" name="opening_date" 
                                               value="<?php echo $enrollment_control['opening_date'] ?? ''; ?>">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="form-label">Closing Date</label>
                                        <input type="date" class="form-control" name="closing_date" 
                                               value="<?php echo $enrollment_control['closing_date'] ?? ''; ?>">
                                    </div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Announcement Message</label>
                                    <textarea class="form-control" name="announcement" rows="3"><?php echo $enrollment_control['announcement'] ?? ''; ?></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Enrollment Status</label>
                                    <select class="form-control" name="enrollment_status" required>
                                        <option value="open" <?php echo (isset($enrollment_control['enrollment_status']) && $enrollment_control['enrollment_status'] == 'open') ? 'selected' : ''; ?>>Open</option>
                                        <option value="closed" <?php echo (!isset($enrollment_control['enrollment_status']) || $enrollment_control['enrollment_status'] == 'closed') ? 'selected' : ''; ?>>Closed</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-save me-2"></i> Save Changes
                                </button>
                            </form>
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
        
        function showSection(section) {
            document.querySelectorAll('.content-section').forEach(el => el.style.display = 'none');
            document.getElementById(section + '-section').style.display = 'block';
            
            document.querySelectorAll('.sidebar .nav-link').forEach(el => el.classList.remove('active'));
            if (event && event.target) {
                event.target.closest('.nav-link').classList.add('active');
            }
            
            // Close sidebar on mobile
            if (window.innerWidth <= 767) {
                const sidebar = document.getElementById('sidebar');
                sidebar.classList.remove('active');
            }
        }

        function assignNumber(userId) {
            if (confirm('Assign student number to this applicant?')) {
                const formData = new FormData();
                formData.append('user_id', userId);
                
                fetch('assign_student_number.php', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        alert('Student number assigned: ' + data.student_number);
                        location.reload();
                    } else {
                        alert('Error: ' + data.message);
                    }
                });
            }
        }

        function passToRegistrar(userId) {
            if (confirm('Pass this application to the Registrar?')) {
                const formData = new FormData();
                formData.append('user_id', userId);
                
                fetch('pass_to_registrar.php', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    alert(data.message);
                    if (data.success) {
                        location.reload();
                    }
                });
            }
        }

        function viewApplicant(userId) {
            window.location.href = `view_applicant.php?user_id=${userId}`;
        }

        document.getElementById('enrollmentControlForm')?.addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            fetch('enrollment_control.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                alert(data.message);
                if (data.success) {
                    location.reload();
                }
            });
        });
        
        // Document verification functions
        // Check if we should show documents section on load
        if (window.location.hash === '#documents') {
            showSection('documents');
        }
        
        function verifyDocument(documentId) {
            if (!confirm('Mark this document as verified?')) return;
            
            const formData = new FormData();
            formData.append('document_id', documentId);
            formData.append('status', 'verified');
            
            fetch('verify_document.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                alert(data.message);
                if (data.success) {
                    location.reload();
                }
            });
        }
        
        function rejectDocument(documentId) {
            const reason = prompt('Enter rejection reason:');
            if (!reason) return;
            
            const formData = new FormData();
            formData.append('document_id', documentId);
            formData.append('status', 'rejected');
            formData.append('rejection_reason', reason);
            
            fetch('verify_document.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                alert(data.message);
                if (data.success) {
                    location.reload();
                }
            });
        }
        
        function viewDocument(filePath) {
            window.open('../' + filePath, '_blank');
        }
        
        function refreshDocuments() {
            const userId = document.getElementById('documentApplicantSelect').value;
            if (userId) {
                // Reload with timestamp to prevent caching
                window.location.href = '?view_documents=' + userId + '&t=' + Date.now() + '#documents';
            }
        }
    </script>
</body>
</html>
<?php
