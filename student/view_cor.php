<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/User.php';

// Check if user is logged in and is a student
if (!isLoggedIn() || isAdmin()) {
    redirect('public/login.php');
}

$database = new Database();
$conn = $database->getConnection();

// Get all CORs for this student
$cor_query = "SELECT cor.*, p.program_code, p.program_name 
              FROM certificate_of_registration cor
              JOIN programs p ON cor.program_id = p.id
              WHERE cor.user_id = :user_id
              ORDER BY cor.academic_year DESC,
                       CASE cor.semester
                           WHEN 'Second Semester' THEN 2
                           WHEN 'First Semester' THEN 1
                           ELSE 0
                       END DESC,
                       cor.created_at DESC";
$cor_stmt = $conn->prepare($cor_query);
$cor_stmt->bindParam(':user_id', $_SESSION['user_id']);
$cor_stmt->execute();
$all_cors = $cor_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get current enrollment info (same logic as dashboard)
$current_enrollment = null;
$current_cor_id = null; // Store the ID of the single current COR

// Get the most recent COR to determine current semester
// Hierarchy: 1st Year First > 1st Year Second > 2nd Year First > 2nd Year Second > 3rd Year First > etc.
// Most recent = highest year level, then highest semester within that year
$latest_cor_query = "SELECT cor.id, cor.academic_year, cor.semester, cor.year_level, cor.section_id
                     FROM certificate_of_registration cor
                     WHERE cor.user_id = :user_id
                     ORDER BY cor.academic_year DESC,
                              CASE cor.year_level
                                  WHEN '4th Year' THEN 4
                                  WHEN '3rd Year' THEN 3
                                  WHEN '2nd Year' THEN 2
                                  WHEN '1st Year' THEN 1
                                  ELSE 0
                              END DESC,
                              CASE cor.semester
                                  WHEN 'Second Semester' THEN 2
                                  WHEN 'First Semester' THEN 1
                                  ELSE 0
                              END DESC,
                              cor.created_at DESC
                     LIMIT 1";
$latest_cor_stmt = $conn->prepare($latest_cor_query);
$latest_cor_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
$latest_cor_stmt->execute();
$latest_cor = $latest_cor_stmt->fetch(PDO::FETCH_ASSOC);

if ($latest_cor && !empty($latest_cor['semester'])) {
    $current_enrollment = [
        'academic_year' => $latest_cor['academic_year'],
        'semester' => $latest_cor['semester'],
        'year_level' => $latest_cor['year_level']
    ];
    $current_cor_id = $latest_cor['id']; // Store the ID of the current COR
}

// Categorize CORs
$current_cors = [];
$past_cors = [];
$revised_cors = [];
$original_cors = [];

// Group CORs by semester to identify revised ones
$cors_by_semester = [];
foreach ($all_cors as $cor) {
    $key = $cor['academic_year'] . '|' . $cor['semester'];
    if (!isset($cors_by_semester[$key])) {
        $cors_by_semester[$key] = [];
    }
    $cors_by_semester[$key][] = $cor;
}

// Identify revised CORs (most recent for each semester is revised if there are multiple)
foreach ($cors_by_semester as $key => $semester_cors) {
    if (count($semester_cors) > 1) {
        // Multiple CORs for same semester - most recent is revised
        $revised_cors[] = $semester_cors[0]; // First one is most recent
        // Others are original
        for ($i = 1; $i < count($semester_cors); $i++) {
            $original_cors[] = $semester_cors[$i];
        }
    } else {
        // Only one COR for this semester - it's original
        $original_cors[] = $semester_cors[0];
    }
}

// Separate current and past enrollment CORs
// IMPORTANT: Only the MOST RECENT COR for the current semester should be marked as "current"
foreach ($all_cors as $cor) {
    $is_current = false;
    if ($current_enrollment && $current_cor_id) {
        // Only mark as current if it matches the current semester AND is the most recent COR
        $is_current = ($cor['academic_year'] == $current_enrollment['academic_year'] && 
                       $cor['semester'] == $current_enrollment['semester'] &&
                       $cor['id'] == $current_cor_id);
    }
    
    if ($is_current) {
        $current_cors[] = $cor;
    } else {
        $past_cors[] = $cor;
    }
}

// Use all_cors for display (will be filtered by tabs)
$cors = $all_cors;

function format_date($date)
{
    if (empty($date)) {
        return '';
    }

    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date;
    }

    return date('F j, Y', $timestamp);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Certificate of Registration - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f2f6fc;
        }
        .page-container {
            max-width: 1200px;
            margin: 2rem auto;
        }
        .card {
            border: none;
            box-shadow: 0 10px 25px rgba(15, 27, 75, 0.08);
        }
        .cor-wrapper {
            background: white;
            padding: 40px;
            border: 1px solid #ddd;
            margin-bottom: 30px;
        }
        .cor-header {
            text-align: center;
            margin-bottom: 20px;
        }
        .cor-header h2 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 0;
        }
        .cor-header h3 {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 0;
        }
        .cor-header h4 {
            font-size: 16px;
            font-weight: 600;
        }
        .cor-table th,
        .cor-table td {
            font-size: 12px;
            vertical-align: middle;
            border: 1px solid #333;
            padding: 6px;
            text-align: center;
        }
        .cor-table td.text-start {
            text-align: left;
        }
        .cor-footer {
            font-size: 12px;
            margin-top: 20px;
        }
        .signature-line {
            display: flex;
            justify-content: space-between;
            margin-top: 40px;
        }
        .signature-line .sig {
            width: 30%;
            text-align: center;
            font-size: 12px;
        }
        .signature-line .sig span {
            display: block;
            border-top: 1px solid #000;
            padding-top: 4px;
            font-weight: 600;
        }
        .adjustments-section {
            page-break-before: always;
            page-break-after: avoid;
            margin-top: 0;
        }
        @media print {
            body {
                background: white;
            }
            .no-print {
                display: none !important;
            }
            .cor-wrapper {
                border: none;
                padding: 20px;
                box-shadow: none;
            }
            .cor-wrapper:not(.print-this) {
                display: none !important;
            }
            .adjustments-section {
                page-break-before: always !important;
                page-break-after: avoid !important;
                page-break-inside: avoid !important;
                margin-top: 0 !important;
            }
            @page {
                size: letter;
                margin: 0.5in;
            }
        }
        .cor-list-item {
            border-left: 4px solid #2563eb;
            padding: 15px;
            margin-bottom: 15px;
            background: white;
            border-radius: 5px;
        }
    </style>
</head>
<body>
    <div class="page-container">
        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <h1 class="h4 mb-0"><i class="fas fa-file-alt me-2 text-primary"></i>My Certificate of Registration</h1>
            <a href="dashboard.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
            </a>
        </div>

        <?php if (empty($cors)): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="fas fa-file-alt fa-3x text-muted mb-3"></i>
                    <h4 class="text-muted">No Certificate of Registration Found</h4>
                    <p class="text-muted">Your COR will appear here once it has been generated by the registrar.</p>
                </div>
            </div>
        <?php else: ?>
            <!-- Filter Tabs -->
            <div class="card mb-4 no-print">
                <div class="card-header">
                    <ul class="nav nav-tabs card-header-tabs" id="corTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="all-tab" data-bs-toggle="tab" data-bs-target="#all" type="button" role="tab">
                                <i class="fas fa-list me-1"></i>All CORs (<?php echo count($cors); ?>)
                            </button>
                        </li>
                        <?php if (!empty($current_cors)): ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="current-tab" data-bs-toggle="tab" data-bs-target="#current" type="button" role="tab">
                                <i class="fas fa-calendar-check me-1"></i>Current Enrollment (<?php echo count($current_cors); ?>)
                            </button>
                        </li>
                        <?php endif; ?>
                        <?php if (!empty($past_cors)): ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="past-tab" data-bs-toggle="tab" data-bs-target="#past" type="button" role="tab">
                                <i class="fas fa-history me-1"></i>Past Enrollment (<?php echo count($past_cors); ?>)
                            </button>
                        </li>
                        <?php endif; ?>
                        <?php if (!empty($revised_cors)): ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="revised-tab" data-bs-toggle="tab" data-bs-target="#revised" type="button" role="tab">
                                <i class="fas fa-edit me-1"></i>Revised CORs (<?php echo count($revised_cors); ?>)
                            </button>
                        </li>
                        <?php endif; ?>
                        <?php if (!empty($original_cors)): ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="original-tab" data-bs-toggle="tab" data-bs-target="#original" type="button" role="tab">
                                <i class="fas fa-file me-1"></i>Original CORs (<?php echo count($original_cors); ?>)
                            </button>
                        </li>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="card-body">
                    <div class="tab-content" id="corTabContent">
                        <!-- All CORs -->
                        <div class="tab-pane fade show active" id="all" role="tabpanel">
                            <?php foreach ($cors as $index => $cor): 
                                $is_revised = in_array($cor['id'], array_column($revised_cors, 'id'));
                                $is_current = in_array($cor['id'], array_column($current_cors, 'id'));
                            ?>
                                <div class="cor-list-item mb-2 p-3 border rounded">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="flex-grow-1">
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <h6 class="mb-0">
                                                    <strong><?php echo htmlspecialchars($cor['program_code']); ?></strong> - 
                                                    <?php echo htmlspecialchars($cor['academic_year']); ?> - 
                                                    <?php echo htmlspecialchars($cor['semester']); ?>
                                                </h6>
                                                <?php if ($is_revised): ?>
                                                    <span class="badge bg-warning text-dark">
                                                        <i class="fas fa-edit me-1"></i>Revised
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($is_current): ?>
                                                    <span class="badge bg-success">
                                                        <i class="fas fa-check-circle me-1"></i>Current
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($cor['year_level']); ?> - 
                                                <?php echo htmlspecialchars($cor['section_name']); ?> | 
                                                Generated: <?php echo date('M j, Y', strtotime($cor['created_at'])); ?>
                                            </small>
                                        </div>
                                        <button class="btn btn-primary btn-sm" onclick="window.scrollTo({top: document.getElementById('cor-<?php echo $cor['id']; ?>').offsetTop - 20, behavior: 'smooth'})">
                                            <i class="fas fa-eye me-1"></i>View
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <!-- Current Enrollment CORs -->
                        <?php if (!empty($current_cors)): ?>
                        <div class="tab-pane fade" id="current" role="tabpanel">
                            <?php foreach ($current_cors as $cor): 
                                $is_revised = in_array($cor['id'], array_column($revised_cors, 'id'));
                            ?>
                                <div class="cor-list-item mb-2 p-3 border rounded border-success">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="flex-grow-1">
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <h6 class="mb-0">
                                                    <strong><?php echo htmlspecialchars($cor['program_code']); ?></strong> - 
                                                    <?php echo htmlspecialchars($cor['academic_year']); ?> - 
                                                    <?php echo htmlspecialchars($cor['semester']); ?>
                                                </h6>
                                                <?php if ($is_revised): ?>
                                                    <span class="badge bg-warning text-dark">
                                                        <i class="fas fa-edit me-1"></i>Revised
                                                    </span>
                                                <?php endif; ?>
                                                <span class="badge bg-success">
                                                    <i class="fas fa-check-circle me-1"></i>Current
                                                </span>
                                            </div>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($cor['year_level']); ?> - 
                                                <?php echo htmlspecialchars($cor['section_name']); ?> | 
                                                Generated: <?php echo date('M j, Y', strtotime($cor['created_at'])); ?>
                                            </small>
                                        </div>
                                        <button class="btn btn-primary btn-sm" onclick="window.scrollTo({top: document.getElementById('cor-<?php echo $cor['id']; ?>').offsetTop - 20, behavior: 'smooth'})">
                                            <i class="fas fa-eye me-1"></i>View
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Past Enrollment CORs -->
                        <?php if (!empty($past_cors)): ?>
                        <div class="tab-pane fade" id="past" role="tabpanel">
                            <?php foreach ($past_cors as $cor): 
                                $is_revised = in_array($cor['id'], array_column($revised_cors, 'id'));
                            ?>
                                <div class="cor-list-item mb-2 p-3 border rounded">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="flex-grow-1">
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <h6 class="mb-0">
                                                    <strong><?php echo htmlspecialchars($cor['program_code']); ?></strong> - 
                                                    <?php echo htmlspecialchars($cor['academic_year']); ?> - 
                                                    <?php echo htmlspecialchars($cor['semester']); ?>
                                                </h6>
                                                <?php if ($is_revised): ?>
                                                    <span class="badge bg-warning text-dark">
                                                        <i class="fas fa-edit me-1"></i>Revised
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($cor['year_level']); ?> - 
                                                <?php echo htmlspecialchars($cor['section_name']); ?> | 
                                                Generated: <?php echo date('M j, Y', strtotime($cor['created_at'])); ?>
                                            </small>
                                        </div>
                                        <button class="btn btn-primary btn-sm" onclick="window.scrollTo({top: document.getElementById('cor-<?php echo $cor['id']; ?>').offsetTop - 20, behavior: 'smooth'})">
                                            <i class="fas fa-eye me-1"></i>View
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Revised CORs -->
                        <?php if (!empty($revised_cors)): ?>
                        <div class="tab-pane fade" id="revised" role="tabpanel">
                            <?php foreach ($revised_cors as $cor): 
                                $is_current = in_array($cor['id'], array_column($current_cors, 'id'));
                            ?>
                                <div class="cor-list-item mb-2 p-3 border rounded border-warning">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="flex-grow-1">
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <h6 class="mb-0">
                                                    <strong><?php echo htmlspecialchars($cor['program_code']); ?></strong> - 
                                                    <?php echo htmlspecialchars($cor['academic_year']); ?> - 
                                                    <?php echo htmlspecialchars($cor['semester']); ?>
                                                </h6>
                                                <span class="badge bg-warning text-dark">
                                                    <i class="fas fa-edit me-1"></i>Revised
                                                </span>
                                                <?php if ($is_current): ?>
                                                    <span class="badge bg-success">
                                                        <i class="fas fa-check-circle me-1"></i>Current
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($cor['year_level']); ?> - 
                                                <?php echo htmlspecialchars($cor['section_name']); ?> | 
                                                Generated: <?php echo date('M j, Y', strtotime($cor['created_at'])); ?>
                                            </small>
                                        </div>
                                        <button class="btn btn-primary btn-sm" onclick="window.scrollTo({top: document.getElementById('cor-<?php echo $cor['id']; ?>').offsetTop - 20, behavior: 'smooth'})">
                                            <i class="fas fa-eye me-1"></i>View
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        
                        <!-- Original CORs -->
                        <?php if (!empty($original_cors)): ?>
                        <div class="tab-pane fade" id="original" role="tabpanel">
                            <?php foreach ($original_cors as $cor): 
                                $is_current = in_array($cor['id'], array_column($current_cors, 'id'));
                            ?>
                                <div class="cor-list-item mb-2 p-3 border rounded">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="flex-grow-1">
                                            <div class="d-flex align-items-center gap-2 mb-1">
                                                <h6 class="mb-0">
                                                    <strong><?php echo htmlspecialchars($cor['program_code']); ?></strong> - 
                                                    <?php echo htmlspecialchars($cor['academic_year']); ?> - 
                                                    <?php echo htmlspecialchars($cor['semester']); ?>
                                                </h6>
                                                <?php if ($is_current): ?>
                                                    <span class="badge bg-success">
                                                        <i class="fas fa-check-circle me-1"></i>Current
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted">
                                                <?php echo htmlspecialchars($cor['year_level']); ?> - 
                                                <?php echo htmlspecialchars($cor['section_name']); ?> | 
                                                Generated: <?php echo date('M j, Y', strtotime($cor['created_at'])); ?>
                                            </small>
                                        </div>
                                        <button class="btn btn-primary btn-sm" onclick="window.scrollTo({top: document.getElementById('cor-<?php echo $cor['id']; ?>').offsetTop - 20, behavior: 'smooth'})">
                                            <i class="fas fa-eye me-1"></i>View
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- COR Display -->
            <?php foreach ($cors as $cor): ?>
                <?php
                $subjects = json_decode($cor['subjects_json'], true);
                if (!is_array($subjects)) {
                    $subjects = [];
                }
                
                // Fetch approved adjustments ONLY for the current COR (most recent)
                // Only show latest adjustments approved after this COR was created
                // Past CORs should not show adjustments
                $adjustments = [];
                $subject_adjustment_map = [];
                $is_current_cor = ($current_cor_id && $cor['id'] == $current_cor_id);
                
                if ($is_current_cor && !empty($cor['academic_year']) && !empty($cor['semester'])) {
                    // Only fetch adjustments approved after this COR was created (latest adjustments)
                    $adjustments_query = "SELECT apc.*, 
                                         c.course_code, c.course_name, c.units,
                                         s_old.section_name as old_section_name,
                                         s_old.academic_year as old_academic_year,
                                         s_old.semester as old_semester,
                                         ss_old.time_start as old_time_start,
                                         ss_old.time_end as old_time_end,
                                         ss_old.room as old_room,
                                         ss_old.professor_name as old_professor_name,
                                         ss_old.schedule_monday as old_monday,
                                         ss_old.schedule_tuesday as old_tuesday,
                                         ss_old.schedule_wednesday as old_wednesday,
                                         ss_old.schedule_thursday as old_thursday,
                                         ss_old.schedule_friday as old_friday,
                                         ss_old.schedule_saturday as old_saturday,
                                         ss_old.schedule_sunday as old_sunday,
                                         s_new.section_name as new_section_name,
                                         s_new.academic_year as new_academic_year,
                                         s_new.semester as new_semester,
                                         ss_new.time_start as new_time_start,
                                         ss_new.time_end as new_time_end,
                                         ss_new.room as new_room,
                                         ss_new.professor_name as new_professor_name,
                                         ss_new.schedule_monday as new_monday,
                                         ss_new.schedule_tuesday as new_tuesday,
                                         ss_new.schedule_wednesday as new_wednesday,
                                         ss_new.schedule_thursday as new_thursday,
                                         ss_new.schedule_friday as new_friday,
                                         ss_new.schedule_saturday as new_saturday,
                                         ss_new.schedule_sunday as new_sunday,
                                         apc.remarks as student_remarks,
                                         apc.program_head_remarks,
                                         apc.dean_remarks,
                                         apc.registrar_remarks
                                         FROM adjustment_period_changes apc
                                         LEFT JOIN curriculum c ON apc.curriculum_id = c.id
                                         LEFT JOIN section_schedules ss_old ON apc.old_section_schedule_id = ss_old.id
                                         LEFT JOIN sections s_old ON ss_old.section_id = s_old.id
                                         LEFT JOIN section_schedules ss_new ON apc.new_section_schedule_id = ss_new.id
                                         LEFT JOIN sections s_new ON ss_new.section_id = s_new.id
                                         WHERE apc.user_id = :user_id
                                         AND apc.status = 'approved'
                                         AND (
                                             (s_new.academic_year = :academic_year AND s_new.semester = :semester)
                                             OR (s_old.academic_year = :academic_year AND s_old.semester = :semester)
                                         )";
                    
                    // Only show adjustments approved after this COR was created
                    if (!empty($cor['created_at'])) {
                        $adjustments_query .= " AND COALESCE(apc.registrar_reviewed_at, apc.dean_reviewed_at, apc.change_date) >= :cor_created_at";
                    }
                    
                    $adjustments_query .= " ORDER BY apc.change_date ASC";
                    
                    $adjustments_stmt = $conn->prepare($adjustments_query);
                    $adjustments_stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
                    $adjustments_stmt->bindParam(':academic_year', $cor['academic_year']);
                    $adjustments_stmt->bindParam(':semester', $cor['semester']);
                    if (!empty($cor['created_at'])) {
                        $adjustments_stmt->bindParam(':cor_created_at', $cor['created_at']);
                    }
                    $adjustments_stmt->execute();
                    $adjustments = $adjustments_stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Create adjustment map
                    foreach ($adjustments as $adj) {
                        if (!empty($adj['curriculum_id'])) {
                            $subject_adjustment_map[$adj['curriculum_id']] = $adj['change_type'];
                        }
                        if (!empty($adj['course_code'])) {
                            $subject_adjustment_map['code_' . $adj['course_code']] = $adj['change_type'];
                        }
                    }
                }
                ?>
                <div class="cor-wrapper" id="cor-<?php echo $cor['id']; ?>" data-cor-id="<?php echo $cor['id']; ?>">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div>
                            <strong>OCC FORM NO. 01</strong>
                        </div>
                        <div class="no-print">
                            <button class="btn btn-outline-primary print-cor-btn" data-cor-id="<?php echo $cor['id']; ?>">
                                <i class="fas fa-print me-2"></i>Print COR
                            </button>
                        </div>
                    </div>

                    <div class="cor-header">
                        <h2><?php echo htmlspecialchars(strtoupper($cor['college_name'])); ?></h2>
                        <h3>Office of the College Registrar</h3>
                        <h4><?php echo htmlspecialchars($cor['program_name']); ?></h4>
                        <p class="mb-0 fw-bold">CERTIFICATE OF REGISTRATION</p>
                        <?php 
                        // Check if this is a revised COR (has multiple CORs for same semester, and this is the most recent)
                        $is_revised = false;
                        if (count($cors) > 1) {
                            // Check if there are multiple CORs for the same academic_year and semester
                            $same_semester_cors = array_filter($cors, function($c) use ($cor) {
                                return $c['academic_year'] == $cor['academic_year'] && 
                                       $c['semester'] == $cor['semester'] && 
                                       $c['id'] != $cor['id'];
                            });
                            if (!empty($same_semester_cors)) {
                                // Check if this is the most recent one
                                $most_recent = reset($cors); // First one is most recent due to ORDER BY
                                $is_revised = ($cor['id'] == $most_recent['id']);
                            }
                        }
                        if ($is_revised): ?>
                            <p class="mb-0 mt-2" style="font-size: 12px; color: #d9534f; font-weight: 600;">
                                <i class="fas fa-info-circle me-1"></i>REVISED - Updated after Adjustment Period
                            </p>
                        <?php endif; ?>
                    </div>

                    <table class="table table-bordered mb-3" style="font-size: 13px;">
                        <tbody>
                            <tr>
                                <td style="width: 20%;">STUDENT NUMBER</td>
                                <td style="width: 30%;"><strong><?php echo htmlspecialchars($cor['student_number'] ?: ''); ?></strong></td>
                                <td style="width: 20%;">COURSE</td>
                                <td style="width: 30%;"><strong><?php echo htmlspecialchars($cor['program_code']); ?></strong></td>
                            </tr>
                            <tr>
                                <td>YEAR &amp; SECTION</td>
                                <td><strong><?php echo htmlspecialchars($cor['year_level'] . ' - ' . $cor['section_name']); ?></strong></td>
                                <td>SEMESTER</td>
                                <td><strong><?php echo htmlspecialchars($cor['semester']); ?></strong></td>
                            </tr>
                            <tr>
                                <td>SCHOOL YEAR</td>
                                <td><strong><?php echo htmlspecialchars($cor['academic_year']); ?></strong></td>
                                <td>DATE OF REGISTRATION</td>
                                <td><strong><?php echo htmlspecialchars(format_date($cor['registration_date'])); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>

                    <table class="table table-bordered mb-3" style="font-size: 13px;">
                        <tbody>
                            <tr>
                                <td style="width: 10%;">LAST NAME</td>
                                <td style="width: 23%;"><strong><?php echo htmlspecialchars($cor['student_last_name']); ?></strong></td>
                                <td style="width: 10%;">FIRST NAME</td>
                                <td style="width: 23%;"><strong><?php echo htmlspecialchars($cor['student_first_name']); ?></strong></td>
                                <td style="width: 10%;">MIDDLE NAME</td>
                                <td style="width: 24%;"><strong><?php echo htmlspecialchars($cor['student_middle_name'] ?: ''); ?></strong></td>
                            </tr>
                            <tr>
                                <td>ADDRESS</td>
                                <td colspan="5"><strong><?php echo htmlspecialchars($cor['student_address'] ?: ''); ?></strong></td>
                            </tr>
                        </tbody>
                    </table>

                    <table class="table cor-table">
                        <thead class="table-light">
                            <tr>
                                <th style="width: 9%;">Code</th>
                                <th style="width: 22%;">Subject Title</th>
                                <th style="width: 7%;">Sec.</th>
                                <th style="width: 6%;">Units/Hrs.</th>
                                <th style="width: 6%;">M</th>
                                <th style="width: 6%;">T</th>
                                <th style="width: 6%;">W</th>
                                <th style="width: 6%;">TH</th>
                                <th style="width: 6%;">F</th>
                                <th style="width: 6%;">Sat</th>
                                <th style="width: 6%;">Sun</th>
                                <th style="width: 7%;">Rm.</th>
                                <th style="width: 6%;">Prof's Initial</th>
                                <th style="width: 11%;">Remarks</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjects as $subject): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($subject['course_code']); ?></td>
                                    <td class="text-start"><?php echo htmlspecialchars($subject['course_name']); ?></td>
                                    <td>
                                        <?php 
                                        // Show section name from schedule_info if available, otherwise use default section
                                        if (!empty($subject['section_info']['section_name'])) {
                                            echo htmlspecialchars($subject['section_info']['section_name']);
                                        } else {
                                            echo htmlspecialchars($cor['section_name']);
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($subject['units']); ?></td>
                                    <td>
                                        <?php 
                                        $schedule_days = $subject['section_info']['schedule_days'] ?? [];
                                        echo (in_array('Monday', $schedule_days) || in_array('Mon', $schedule_days)) ? '✓' : '';
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        echo (in_array('Tuesday', $schedule_days) || in_array('Tue', $schedule_days)) ? '✓' : '';
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        echo (in_array('Wednesday', $schedule_days) || in_array('Wed', $schedule_days)) ? '✓' : '';
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        echo (in_array('Thursday', $schedule_days) || in_array('Thu', $schedule_days)) ? '✓' : '';
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        echo (in_array('Friday', $schedule_days) || in_array('Fri', $schedule_days)) ? '✓' : '';
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        echo (in_array('Saturday', $schedule_days) || in_array('Sat', $schedule_days)) ? '✓' : '';
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        echo (in_array('Sunday', $schedule_days) || in_array('Sun', $schedule_days)) ? '✓' : '';
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $room = $subject['section_info']['room'] ?? 'TBA';
                                        echo htmlspecialchars($room);
                                        ?>
                                    </td>
                                    <td>
                                        <?php 
                                        $prof_initial = $subject['section_info']['professor_initial'] ?? 
                                                       (isset($subject['section_info']['professor_name']) ? substr($subject['section_info']['professor_name'], 0, 3) : 'TBA');
                                        echo htmlspecialchars($prof_initial);
                                        ?>
                                    </td>
                                    <td style="font-size: 11px;">
                                        <?php 
                                        if (!empty($subject['is_added']) && !empty($subject['remarks'])) {
                                            echo '<span class="badge bg-info text-dark" title="' . htmlspecialchars($subject['remarks']) . '">Added</span><br>';
                                            echo '<small>' . htmlspecialchars($subject['remarks']) . '</small>';
                                        } elseif (!empty($subject['is_added'])) {
                                            echo '<span class="badge bg-info text-dark">Added</span>';
                                        } elseif (!empty($subject['is_backload'])) {
                                            echo '<span class="badge bg-warning text-dark">Backload</span>';
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr>
                                <td colspan="3" class="text-end"><strong>TOTAL UNITS/HRS.</strong></td>
                                <td><strong><?php echo htmlspecialchars($cor['total_units']); ?></strong></td>
                                <td colspan="10"></td>
                            </tr>
                        </tbody>
                    </table>

                    <div class="cor-footer">
                        <p class="mb-1"><strong>NOTE:</strong></p>
                        <ol style="padding-left: 16px;">
                            <li>Changes should be approved by the Dean/Director and acknowledged by the College Registrar.</li>
                            <li>Not valid without the facsimile of the College Registrar.</li>
                        </ol>
                    </div>

                    <div class="signature-line">
                        <div class="sig">
                            <span><?php echo htmlspecialchars($cor['dean_name']); ?></span>
                            Dean / Director
                        </div>
                        <div class="sig">
                            <span><?php echo htmlspecialchars($cor['adviser_name'] ?: ''); ?></span>
                            Adviser
                        </div>
                        <div class="sig">
                            <span><?php echo htmlspecialchars($cor['registrar_name']); ?></span>
                            College Registrar
                        </div>
                    </div>

                    <div class="mt-4" style="font-size: 12px;">
                        <strong>Prepared by:</strong> <?php echo htmlspecialchars(trim($cor['prepared_by'])) ?: '________________'; ?>
                    </div>

                    <?php if (!empty($adjustments) && is_array($adjustments) && count($adjustments) > 0): ?>
                        <!-- Page Break for Page 2 -->
                        <div style="page-break-before: always; height: 0; margin: 0; padding: 0;"></div>
                        
                        <!-- Adjustments Table - Page 2 (Separate page but part of same COR) -->
                        <div class="adjustments-section mt-4 pt-3" style="border-top: 2px solid #000;">
                        <div class="mb-2" style="text-align: center;">
                            <h6 class="mb-1" style="font-size: 13px; font-weight: bold;">
                                ADJUSTMENTS MADE TO CERTIFICATE OF REGISTRATION
                            </h6>
                            <p class="mb-2" style="font-size: 11px;">
                                Academic Year: <strong><?php echo htmlspecialchars($cor['academic_year']); ?></strong> | 
                                Semester: <strong><?php echo htmlspecialchars($cor['semester']); ?></strong>
                            </p>
                        </div>

                        <table class="table table-bordered mb-2" style="font-size: 11px; width: 100%; border-collapse: collapse; margin-top: 5px;">
                            <thead class="table-light">
                                <tr style="background-color: #f8f9fa;">
                                    <th style="width: 10%; padding: 6px; border: 1px solid #333; text-align: center; font-weight: bold; font-size: 11px;">Type</th>
                                    <th style="width: 15%; padding: 6px; border: 1px solid #333; text-align: center; font-weight: bold; font-size: 11px;">Subject Code</th>
                                    <th style="width: 20%; padding: 6px; border: 1px solid #333; text-align: center; font-weight: bold; font-size: 11px;">Subject Name</th>
                                    <th style="width: 55%; padding: 6px; border: 1px solid #333; text-align: center; font-weight: bold; font-size: 11px;">Adjustment Details & Remarks</th>
                                </tr>
                            </thead>
                                <tbody>
                                    <?php foreach ($adjustments as $adj): ?>
                                        <?php
                                        // Get schedule days for old schedule
                                        $old_days = [];
                                        if (!empty($adj['old_monday'])) $old_days[] = 'Mon';
                                        if (!empty($adj['old_tuesday'])) $old_days[] = 'Tue';
                                        if (!empty($adj['old_wednesday'])) $old_days[] = 'Wed';
                                        if (!empty($adj['old_thursday'])) $old_days[] = 'Thu';
                                        if (!empty($adj['old_friday'])) $old_days[] = 'Fri';
                                        if (!empty($adj['old_saturday'])) $old_days[] = 'Sat';
                                        if (!empty($adj['old_sunday'])) $old_days[] = 'Sun';
                                        
                                        // Get schedule days for new schedule
                                        $new_days = [];
                                        if (!empty($adj['new_monday'])) $new_days[] = 'Mon';
                                        if (!empty($adj['new_tuesday'])) $new_days[] = 'Tue';
                                        if (!empty($adj['new_wednesday'])) $new_days[] = 'Wed';
                                        if (!empty($adj['new_thursday'])) $new_days[] = 'Thu';
                                        if (!empty($adj['new_friday'])) $new_days[] = 'Fri';
                                        if (!empty($adj['new_saturday'])) $new_days[] = 'Sat';
                                        if (!empty($adj['new_sunday'])) $new_days[] = 'Sun';
                                        
                                        $change_type_label = '';
                                        $details = '';
                                        
                                        if ($adj['change_type'] === 'add') {
                                            $change_type_label = 'ADDED';
                                            $details = '<strong>Added Subject:</strong><br>';
                                            if (!empty($adj['new_section_name'])) {
                                                $details .= 'Section: ' . htmlspecialchars($adj['new_section_name']) . '<br>';
                                            }
                                            if (!empty($new_days) && !empty($adj['new_time_start']) && !empty($adj['new_time_end'])) {
                                                $details .= 'Schedule: ' . htmlspecialchars(implode(', ', $new_days) . ' ' . $adj['new_time_start'] . '-' . $adj['new_time_end']) . '<br>';
                                            }
                                            if (!empty($adj['new_room'])) {
                                                $details .= 'Room: ' . htmlspecialchars($adj['new_room']) . '<br>';
                                            }
                                            if (!empty($adj['new_professor_name'])) {
                                                $details .= 'Professor: ' . htmlspecialchars($adj['new_professor_name']) . '<br>';
                                            }
                                            if (!empty($adj['units'])) {
                                                $details .= 'Units: ' . htmlspecialchars((string)$adj['units']);
                                            }
                                        } elseif ($adj['change_type'] === 'remove') {
                                            $change_type_label = 'REMOVED';
                                            $details = '<strong>Removed Subject:</strong><br>';
                                            if (!empty($adj['old_section_name'])) {
                                                $details .= 'Section: ' . htmlspecialchars($adj['old_section_name']) . '<br>';
                                            }
                                            if (!empty($old_days) && !empty($adj['old_time_start']) && !empty($adj['old_time_end'])) {
                                                $details .= 'Schedule: ' . htmlspecialchars(implode(', ', $old_days) . ' ' . $adj['old_time_start'] . '-' . $adj['old_time_end']) . '<br>';
                                            }
                                            if (!empty($adj['old_room'])) {
                                                $details .= 'Room: ' . htmlspecialchars($adj['old_room']) . '<br>';
                                            }
                                            if (!empty($adj['old_professor_name'])) {
                                                $details .= 'Professor: ' . htmlspecialchars($adj['old_professor_name']);
                                            }
                                        } elseif ($adj['change_type'] === 'schedule_change') {
                                            $change_type_label = 'SCHEDULE<br>CHANGED';
                                            $details = '<strong>Schedule Change:</strong><br>';
                                            $details .= '<strong>From:</strong> ';
                                            if (!empty($adj['old_section_name'])) {
                                                $details .= htmlspecialchars($adj['old_section_name']);
                                            }
                                            if (!empty($old_days) && !empty($adj['old_time_start']) && !empty($adj['old_time_end'])) {
                                                $details .= ' (' . htmlspecialchars(implode(', ', $old_days) . ' ' . $adj['old_time_start'] . '-' . $adj['old_time_end']) . ')';
                                            }
                                            if (!empty($adj['old_room'])) {
                                                $details .= ' - Room: ' . htmlspecialchars($adj['old_room']);
                                            }
                                            $details .= '<br><strong>To:</strong> ';
                                            if (!empty($adj['new_section_name'])) {
                                                $details .= htmlspecialchars($adj['new_section_name']);
                                            }
                                            if (!empty($new_days) && !empty($adj['new_time_start']) && !empty($adj['new_time_end'])) {
                                                $details .= ' (' . htmlspecialchars(implode(', ', $new_days) . ' ' . $adj['new_time_start'] . '-' . $adj['new_time_end']) . ')';
                                            }
                                            if (!empty($adj['new_room'])) {
                                                $details .= ' - Room: ' . htmlspecialchars($adj['new_room']);
                                            }
                                        }
                                        
                                        // Remarks removed - adjustment type already indicates what happened
                                        ?>
                                        <tr>
                                            <td style="vertical-align: top; text-align: center; padding: 6px; border: 1px solid #333; font-size: 11px;">
                                                <strong><?php echo $change_type_label; ?></strong>
                                            </td>
                                            <td style="vertical-align: top; padding: 6px; border: 1px solid #333; font-size: 11px;">
                                                <strong><?php echo htmlspecialchars($adj['course_code'] ?? 'N/A'); ?></strong>
                                                <?php if (!empty($adj['units'])): ?>
                                                    <br><small style="font-size: 10px;"><?php echo htmlspecialchars((string)$adj['units']); ?> units</small>
                                                <?php endif; ?>
                                            </td>
                                            <td style="vertical-align: top; padding: 6px; border: 1px solid #333; font-size: 11px;">
                                                <?php echo htmlspecialchars($adj['course_name'] ?? 'N/A'); ?>
                                            </td>
                                            <td style="vertical-align: top; padding: 6px; border: 1px solid #333; font-size: 10px;">
                                                <div style="line-height: 1.4;">
                                                    <?php echo $details; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <div class="mt-2" style="font-size: 10px; text-align: center;">
                                <p class="mb-0"><em>This document is an extension of the Certificate of Registration above.</em></p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Print specific COR functionality
        document.querySelectorAll('.print-cor-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const corId = this.dataset.corId;
                const corWrapper = document.getElementById('cor-' + corId);
                
                if (corWrapper) {
                    // Add print-this class to the specific COR
                    corWrapper.classList.add('print-this');
                    
                    // Remove print-this from all other CORs
                    document.querySelectorAll('.cor-wrapper').forEach(wrapper => {
                        if (wrapper.id !== 'cor-' + corId) {
                            wrapper.classList.remove('print-this');
                        }
                    });
                    
                    // Trigger print
                    window.print();
                    
                    // Remove print-this class after printing
                    setTimeout(() => {
                        corWrapper.classList.remove('print-this');
                    }, 1000);
                }
            });
        });
    </script>
</body>
</html>

