<?php
declare(strict_types=1);

require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../config/adjustment_helper.php';

if (!isRegistrarStaff()) {
    redirect('../public/login.php');
}

$conn = (new Database())->getConnection();

// Handle rejection of adjustment requests
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reject') {
    $change_id = (int)($_POST['change_id'] ?? 0);
    $remarks = $_POST['remarks'] ?? null;
    
    if ($change_id > 0) {
        try {
            $conn->beginTransaction();
            
            $update_query = "UPDATE adjustment_period_changes 
                           SET status = 'rejected',
                               registrar_reviewed_by = :reviewer_id,
                               registrar_reviewed_at = NOW(),
                               registrar_remarks = :remarks
                           WHERE id = :change_id
                           AND status = 'pending_registrar'";
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bindParam(':reviewer_id', $_SESSION['user_id'], PDO::PARAM_INT);
            $update_stmt->bindParam(':change_id', $change_id, PDO::PARAM_INT);
            $update_stmt->bindParam(':remarks', $remarks);
            $update_stmt->execute();
            
            $conn->commit();
            $_SESSION['message'] = 'Adjustment request rejected.';
        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $_SESSION['message'] = 'Error processing request: ' . $e->getMessage();
        }
    }
}

// Get pending adjustment requests (approved by Dean)
// Filter to only show adjustments for current semester
$adjustments_query = "SELECT apc.*, u.student_id, u.first_name, u.last_name, u.email,
                     c.course_code, c.course_name, c.units, c.year_level, c.semester as subject_semester,
                     s_old.section_name as old_section_name, 
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
                     s_old.academic_year as old_academic_year,
                     s_old.semester as old_semester,
                     s_new.academic_year as new_academic_year,
                     s_new.semester as new_semester,
                     d.first_name as dean_first_name, d.last_name as dean_last_name,
                     apc.dean_remarks,
                     reg.first_name as registrar_first_name, reg.last_name as registrar_last_name,
                     apc.registrar_reviewed_at, apc.registrar_remarks, apc.status
                     FROM adjustment_period_changes apc
                     JOIN users u ON apc.user_id = u.id
                     LEFT JOIN curriculum c ON apc.curriculum_id = c.id
                     LEFT JOIN section_schedules ss_old ON apc.old_section_schedule_id = ss_old.id
                     LEFT JOIN sections s_old ON ss_old.section_id = s_old.id
                     LEFT JOIN section_schedules ss_new ON apc.new_section_schedule_id = ss_new.id
                     LEFT JOIN sections s_new ON ss_new.section_id = s_new.id
                     LEFT JOIN users d ON apc.dean_reviewed_by = d.id
                     LEFT JOIN users reg ON apc.registrar_reviewed_by = reg.id
                     WHERE apc.status IN ('pending_registrar', 'approved')
                     ORDER BY 
                         CASE WHEN apc.status = 'pending_registrar' THEN 1 ELSE 2 END,
                         apc.change_date DESC";
$adjustments_stmt = $conn->prepare($adjustments_query);
$adjustments_stmt->execute();
$adjustments = $adjustments_stmt->fetchAll(PDO::FETCH_ASSOC);

// Group adjustments by student for better organization
// Separate pending and approved for display
$pending_by_student = [];
$approved_by_student_receipt = [];
foreach ($adjustments as $adj) {
    $user_id = $adj['user_id'];
    
    if ($adj['status'] === 'approved') {
        // Group approved adjustments for receipt display
        if (!isset($approved_by_student_receipt[$user_id])) {
            $approved_by_student_receipt[$user_id] = [
                'user_id' => $user_id,
                'student_id' => $adj['student_id'],
                'first_name' => $adj['first_name'],
                'last_name' => $adj['last_name'],
                'email' => $adj['email'],
                'adjustments' => []
            ];
        }
        $approved_by_student_receipt[$user_id]['adjustments'][] = $adj;
    } else {
        // Group pending adjustments
        if (!isset($pending_by_student[$user_id])) {
            $pending_by_student[$user_id] = [
                'user_id' => $user_id,
                'student_id' => $adj['student_id'],
                'first_name' => $adj['first_name'],
                'last_name' => $adj['last_name'],
                'email' => $adj['email'],
                'adjustments' => []
            ];
        }
        $pending_by_student[$user_id]['adjustments'][] = $adj;
    }
}

// Get recently approved adjustments (for COR printing)
// Show adjustments approved in last 7 days, or if no registrar_reviewed_at, use change_date
// Include schedule details for better display
// IMPORTANT: Only show adjustments for each student's CURRENT semester enrollment
$approved_adjustments_query = "SELECT apc.*, u.student_id, u.first_name, u.last_name, u.email,
                              c.course_code, c.course_name, c.units,
                              reg.first_name as registrar_first_name, reg.last_name as registrar_last_name,
                              apc.registrar_reviewed_at,
                              COALESCE(apc.registrar_reviewed_at, apc.change_date) as sort_date,
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
                              cor_current.academic_year as current_academic_year,
                              cor_current.semester as current_semester
                              FROM adjustment_period_changes apc
                              JOIN users u ON apc.user_id = u.id
                              LEFT JOIN curriculum c ON apc.curriculum_id = c.id
                              LEFT JOIN users reg ON apc.registrar_reviewed_by = reg.id
                              LEFT JOIN section_schedules ss_old ON apc.old_section_schedule_id = ss_old.id
                              LEFT JOIN sections s_old ON ss_old.section_id = s_old.id
                              LEFT JOIN section_schedules ss_new ON apc.new_section_schedule_id = ss_new.id
                              LEFT JOIN sections s_new ON ss_new.section_id = s_new.id
                              -- Get student's current semester from latest COR
                              LEFT JOIN (
                                  SELECT cor1.user_id, cor1.academic_year, cor1.semester
                                  FROM certificate_of_registration cor1
                                  INNER JOIN (
                                      SELECT user_id, MAX(created_at) as max_created
                                      FROM certificate_of_registration
                                      GROUP BY user_id
                                  ) cor2 ON cor1.user_id = cor2.user_id AND cor1.created_at = cor2.max_created
                              ) cor_current ON cor_current.user_id = u.id
                              WHERE apc.status = 'approved'
                              ORDER BY 
                                  COALESCE(apc.registrar_reviewed_at, apc.change_date) DESC, 
                                  apc.change_date DESC";
$approved_stmt = $conn->prepare($approved_adjustments_query);
$approved_stmt->execute();
$approved_adjustments = $approved_stmt->fetchAll(PDO::FETCH_ASSOC);

// Group approved adjustments by user_id for easier display
// Filter to only include adjustments from each student's current semester
$approved_by_student = [];
$current_semesters_cache = []; // Cache current semester lookups

foreach ($approved_adjustments as $adj) {
    $user_id = $adj['user_id'];
    
    // Get student's current semester from cache or database
    if (!isset($current_semesters_cache[$user_id])) {
        $current_semester_query = "SELECT academic_year, semester 
                                   FROM certificate_of_registration 
                                   WHERE user_id = :user_id 
                                   ORDER BY academic_year DESC,
                                            CASE semester
                                                WHEN 'Second Semester' THEN 2
                                                WHEN 'First Semester' THEN 1
                                                ELSE 0
                                            END DESC,
                                            created_at DESC
                                   LIMIT 1";
        $current_semester_stmt = $conn->prepare($current_semester_query);
        $current_semester_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $current_semester_stmt->execute();
        $current_semesters_cache[$user_id] = $current_semester_stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Include all approved adjustments for history - no semester filtering needed
    
    if (!isset($approved_by_student[$user_id])) {
        // Use registrar_reviewed_at if available, otherwise use change_date
        $initial_approval_time = $adj['registrar_reviewed_at'] ?? $adj['change_date'];
        $approved_by_student[$user_id] = [
            'user_id' => $user_id,
            'student_id' => $adj['student_id'],
            'first_name' => $adj['first_name'],
            'last_name' => $adj['last_name'],
            'email' => $adj['email'],
            'adjustments' => [],
            'latest_approval' => $initial_approval_time
        ];
    }
    $approved_by_student[$user_id]['adjustments'][] = $adj;
    // Update latest approval time if this one is more recent
    // Use registrar_reviewed_at if available, otherwise use change_date
    $current_approval_time = $adj['registrar_reviewed_at'] ?? $adj['change_date'];
    $existing_approval_time = $approved_by_student[$user_id]['latest_approval'];
    if (strtotime($current_approval_time) > strtotime($existing_approval_time)) {
        $approved_by_student[$user_id]['latest_approval'] = $current_approval_time;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Adjustment Requests - Registrar Staff</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .compact-card { padding: 0.75rem; margin-bottom: 0.75rem; }
        .compact-card .card-body { padding: 0.75rem; }
        .compact-alert { padding: 0.5rem 0.75rem; margin-bottom: 0.5rem; font-size: 0.875rem; }
        .compact-badge { font-size: 0.75rem; padding: 0.25rem 0.5rem; }
        .compact-form { margin-top: 0.5rem; }
        .compact-form textarea { font-size: 0.875rem; padding: 0.375rem; }
        .compact-form .btn { font-size: 0.875rem; padding: 0.375rem 0.75rem; }
        .schedule-info { font-size: 0.875rem; line-height: 1.4; }
        .schedule-info strong { font-weight: 600; }
        .compact-header { padding: 0.5rem 0.75rem; }
        .compact-header h5 { font-size: 1rem; margin: 0; }
        .compact-header small { font-size: 0.75rem; }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0"><i class="fas fa-clipboard-check me-2"></i>Review Adjustment Requests</h2>
            <a href="dashboard.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
            </a>
        </div>
        
        <?php if (!empty($_SESSION['message'])): ?>
            <?php 
            $message = $_SESSION['message'];
            $approved_user_id = $_SESSION['approved_user_id'] ?? null;
            $approved_student_info = $_SESSION['approved_student_info'] ?? null;
            unset($_SESSION['message']);
            unset($_SESSION['approved_user_id']);
            unset($_SESSION['approved_student_info']);
            ?>
            <div class="alert alert-success alert-dismissible fade show mb-4">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="flex-grow-1">
                        <div class="mb-3">
                            <i class="fas fa-check-circle me-2"></i>
                            <strong><?php echo htmlspecialchars($message); ?></strong>
                        </div>
                        <?php if ($approved_user_id && $approved_student_info): ?>
                            <div class="card border-success">
                                <div class="card-header bg-success text-white">
                                    <h5 class="mb-0">
                                        <i class="fas fa-user me-2"></i>Student Information
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <p class="mb-2">
                                                <strong><i class="fas fa-id-card me-2"></i>Student ID:</strong>
                                                <?php echo htmlspecialchars($approved_student_info['student_id'] ?? 'N/A'); ?>
                                            </p>
                                            <p class="mb-2">
                                                <strong><i class="fas fa-user me-2"></i>Name:</strong>
                                                <?php 
                                                $full_name = trim(($approved_student_info['first_name'] ?? '') . ' ' . 
                                                                ($approved_student_info['middle_name'] ?? '') . ' ' . 
                                                                ($approved_student_info['last_name'] ?? ''));
                                                echo htmlspecialchars($full_name);
                                                ?>
                                            </p>
                                            <p class="mb-2">
                                                <strong><i class="fas fa-envelope me-2"></i>Email:</strong>
                                                <?php echo htmlspecialchars($approved_student_info['email'] ?? 'N/A'); ?>
                                            </p>
                                        </div>
                                        <div class="col-md-6">
                                            <?php if ($approved_student_info['course_code'] ?? false): ?>
                                                <p class="mb-2">
                                                    <strong><i class="fas fa-book me-2"></i>Subject:</strong>
                                                    <?php echo htmlspecialchars($approved_student_info['course_code']); ?>
                                                    <?php if ($approved_student_info['course_name']): ?>
                                                        - <?php echo htmlspecialchars($approved_student_info['course_name']); ?>
                                                    <?php endif; ?>
                                                </p>
                                                <p class="mb-2">
                                                    <strong><i class="fas fa-edit me-2"></i>Adjustment Type:</strong>
                                                    <?php 
                                                    $change_type_labels = [
                                                        'add' => 'Add Subject',
                                                        'remove' => 'Remove Subject',
                                                        'schedule_change' => 'Schedule Change'
                                                    ];
                                                    echo $change_type_labels[$approved_student_info['change_type']] ?? ucfirst($approved_student_info['change_type']);
                                                    ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <hr class="my-3">
                                    <div class="mt-3">
                                        <h6 class="mb-3">
                                            <i class="fas fa-file-alt me-2 text-primary"></i>
                                            <strong>Certificate of Registration (COR)</strong>
                                        </h6>
                                        <div class="d-flex gap-2 flex-wrap">
                                            <a href="../admin/generate_cor.php?user_id=<?php echo htmlspecialchars($approved_user_id); ?>&regenerate_after_adjustment=1" 
                                               class="btn btn-primary btn-lg" target="_blank">
                                                <i class="fas fa-file-alt me-2"></i>View Revised COR
                                            </a>
                                            <a href="../admin/generate_cor.php?user_id=<?php echo htmlspecialchars($approved_user_id); ?>&regenerate_after_adjustment=1&print=1" 
                                               class="btn btn-success btn-lg" target="_blank">
                                                <i class="fas fa-print me-2"></i>Print Revised COR
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php elseif ($approved_user_id): ?>
                            <div class="d-flex gap-2 flex-wrap mt-2">
                                <a href="../admin/generate_cor.php?user_id=<?php echo htmlspecialchars($approved_user_id); ?>&regenerate_after_adjustment=1" 
                                   class="btn btn-primary" target="_blank">
                                    <i class="fas fa-file-alt me-1"></i>View Revised COR
                                </a>
                                <a href="../admin/generate_cor.php?user_id=<?php echo htmlspecialchars($approved_user_id); ?>&regenerate_after_adjustment=1&print=1" 
                                   class="btn btn-success" target="_blank">
                                    <i class="fas fa-print me-1"></i>Print Revised COR
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            </div>
        <?php endif; ?>
        
        <?php if (empty($pending_by_student)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>No pending adjustment requests at this time.
            </div>
        <?php else: ?>
            <h4 class="mt-4 mb-2"><i class="fas fa-clock me-2 text-warning"></i>Pending Requests</h4>
            <div class="accordion" id="pendingAdjustmentsAccordion">
                <?php 
                $pending_index = 0;
                foreach ($pending_by_student as $student): 
                    $pending_index++;
                    $accordion_id = 'pending_' . $student['user_id'];
                ?>
                    <div class="card mb-2">
                        <div class="card-header bg-warning text-dark compact-header" id="heading<?php echo $accordion_id; ?>">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="mb-0">
                                        <button class="btn btn-link text-dark text-decoration-none p-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?php echo $accordion_id; ?>" aria-expanded="true" aria-controls="collapse<?php echo $accordion_id; ?>">
                                            <i class="fas fa-user me-1"></i>
                                            <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                            <small class="text-muted">(<?php echo htmlspecialchars($student['student_id']); ?>)</small>
                                        </button>
                                    </h5>
                                    <small class="text-muted"><?php echo htmlspecialchars($student['email']); ?></small>
                                </div>
                                <div>
                                    <span class="badge bg-warning text-dark compact-badge">
                                        <?php echo count($student['adjustments']); ?> request(s)
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div id="collapse<?php echo $accordion_id; ?>" class="collapse" aria-labelledby="heading<?php echo $accordion_id; ?>" data-bs-parent="#pendingAdjustmentsAccordion">
                            <div class="card-body compact-card">
                                <?php foreach ($student['adjustments'] as $adj): ?>
                                    <?php 
                                    $is_approved = ($adj['status'] === 'approved');
                                    $border_class = $is_approved ? 'border-success border-2' : 'border';
                                    $bg_class = $is_approved ? 'bg-light' : '';
                                    ?>
                                    <div class="<?php echo $border_class; ?> rounded compact-card <?php echo $bg_class; ?>">
                                        <?php if ($is_approved): ?>
                                            <div class="alert alert-success compact-alert mb-2">
                                                <i class="fas fa-check-circle me-1"></i><strong>APPROVED</strong> | 
                                                <?php echo $adj['registrar_reviewed_at'] ? date('M d, Y', strtotime($adj['registrar_reviewed_at'])) : 'N/A'; ?>
                                                <?php if ($adj['registrar_first_name']): ?>
                                                    | By: <?php echo htmlspecialchars($adj['registrar_first_name'] . ' ' . $adj['registrar_last_name']); ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <span class="badge compact-badge <?php 
                                                    $badge_class = [
                                                        'add' => 'bg-success',
                                                        'remove' => 'bg-danger',
                                                        'schedule_change' => 'bg-primary'
                                                    ];
                                                    echo $badge_class[$adj['change_type']] ?? 'bg-secondary'; 
                                                ?> me-2">
                                                    <?php 
                                                    $change_type_labels = [
                                                        'add' => 'Add',
                                                        'remove' => 'Remove',
                                                        'schedule_change' => 'Change'
                                                    ];
                                                    echo $change_type_labels[$adj['change_type']] ?? ucfirst($adj['change_type']); 
                                                    ?>
                                                </span>
                                                <?php if (!$is_approved): ?>
                                                    <span class="badge compact-badge bg-light text-dark">Dean Approved</span>
                                                <?php endif; ?>
                                                <strong class="text-primary ms-2"><?php echo htmlspecialchars($adj['course_code']); ?></strong>
                                                <span class="text-muted">- <?php echo htmlspecialchars($adj['course_name']); ?></span>
                                                <?php if ($adj['units']): ?>
                                                    <span class="badge compact-badge bg-info ms-1"><?php echo htmlspecialchars((string)$adj['units']); ?>U</span>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted">
                                                <i class="fas fa-clock me-1"></i><?php echo date('M d, Y', strtotime($adj['change_date'])); ?>
                                            </small>
                                        </div>

                                        <?php 
                                        $first_adj = $student['adjustments'][0] ?? null;
                                        if ($first_adj && ($first_adj['dean_first_name'] ?? false)): 
                                        ?>
                                            <div class="alert alert-success compact-alert mb-2">
                                                <i class="fas fa-user-check me-1"></i><strong>Dean:</strong> <?php echo htmlspecialchars($first_adj['dean_first_name'] . ' ' . $first_adj['dean_last_name']); ?>
                                                <?php if ($first_adj['dean_remarks'] ?? false): ?>
                                                    | <em><?php echo htmlspecialchars($first_adj['dean_remarks']); ?></em>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($adj['change_type'] === 'add'): ?>
                                            <div class="alert alert-info compact-alert schedule-info mb-2">
                                                <i class="fas fa-plus me-1"></i><strong>Section:</strong> <?php echo htmlspecialchars($adj['new_section_name'] ?? 'N/A'); ?> | 
                                                <?php
                                                $days = [];
                                                if ($adj['new_monday']) $days[] = 'Mon';
                                                if ($adj['new_tuesday']) $days[] = 'Tue';
                                                if ($adj['new_wednesday']) $days[] = 'Wed';
                                                if ($adj['new_thursday']) $days[] = 'Thu';
                                                if ($adj['new_friday']) $days[] = 'Fri';
                                                if ($adj['new_saturday']) $days[] = 'Sat';
                                                if ($adj['new_sunday']) $days[] = 'Sun';
                                                ?>
                                                <strong>Time:</strong> <?php echo htmlspecialchars(implode(', ', $days)); ?> <?php echo htmlspecialchars($adj['new_time_start'] . '-' . $adj['new_time_end']); ?> | 
                                                <strong>Room:</strong> <?php echo htmlspecialchars($adj['new_room'] ?? 'TBA'); ?> | 
                                                <strong>Prof:</strong> <?php echo htmlspecialchars($adj['new_professor_name'] ?? 'TBA'); ?>
                                            </div>
                                            
                                        <?php elseif ($adj['change_type'] === 'remove'): ?>
                                            <div class="alert alert-warning compact-alert schedule-info mb-2">
                                                <i class="fas fa-minus me-1"></i><strong>Section:</strong> <?php echo htmlspecialchars($adj['old_section_name'] ?? 'N/A'); ?> | 
                                                <strong>Time:</strong> <?php echo htmlspecialchars($adj['old_time_start'] . '-' . $adj['old_time_end']); ?>
                                            </div>
                                            
                                        <?php elseif ($adj['change_type'] === 'schedule_change'): ?>
                                            <div class="row g-2 mb-2">
                                                <div class="col-md-6">
                                                    <div class="alert alert-secondary compact-alert schedule-info">
                                                        <i class="fas fa-arrow-left me-1"></i><strong>From:</strong> <?php echo htmlspecialchars($adj['old_section_name'] ?? 'N/A'); ?><br>
                                                        <?php
                                                        $old_days = [];
                                                        if ($adj['old_monday']) $old_days[] = 'Mon';
                                                        if ($adj['old_tuesday']) $old_days[] = 'Tue';
                                                        if ($adj['old_wednesday']) $old_days[] = 'Wed';
                                                        if ($adj['old_thursday']) $old_days[] = 'Thu';
                                                        if ($adj['old_friday']) $old_days[] = 'Fri';
                                                        if ($adj['old_saturday']) $old_days[] = 'Sat';
                                                        if ($adj['old_sunday']) $old_days[] = 'Sun';
                                                        ?>
                                                        <?php echo htmlspecialchars(implode(', ', $old_days)); ?> <?php echo htmlspecialchars($adj['old_time_start'] . '-' . $adj['old_time_end']); ?>
                                                    </div>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="alert alert-success compact-alert schedule-info">
                                                        <i class="fas fa-arrow-right me-1"></i><strong>To:</strong> <?php echo htmlspecialchars($adj['new_section_name'] ?? 'N/A'); ?><br>
                                                        <?php
                                                        $new_days = [];
                                                        if ($adj['new_monday']) $new_days[] = 'Mon';
                                                        if ($adj['new_tuesday']) $new_days[] = 'Tue';
                                                        if ($adj['new_wednesday']) $new_days[] = 'Wed';
                                                        if ($adj['new_thursday']) $new_days[] = 'Thu';
                                                        if ($adj['new_friday']) $new_days[] = 'Fri';
                                                        if ($adj['new_saturday']) $new_days[] = 'Sat';
                                                        if ($adj['new_sunday']) $new_days[] = 'Sun';
                                                        ?>
                                                        <?php echo htmlspecialchars(implode(', ', $new_days)); ?> <?php echo htmlspecialchars($adj['new_time_start'] . '-' . $adj['new_time_end']); ?>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($adj['remarks']): ?>
                                            <div class="mb-2">
                                                <small><strong><i class="fas fa-comment me-1"></i>Remarks:</strong> <?php echo htmlspecialchars($adj['remarks']); ?></small>
                                            </div>
                                        <?php endif; ?>
                                        
                                        <?php if ($is_approved): ?>
                                            <div class="mt-2">
                                                <a href="../admin/generate_cor.php?user_id=<?php echo $student['user_id']; ?>&regenerate_after_adjustment=1" 
                                                   class="btn btn-primary btn-sm" target="_blank">
                                                    <i class="fas fa-file-alt me-1"></i>View COR
                                                </a>
                                                <a href="../admin/generate_cor.php?user_id=<?php echo $student['user_id']; ?>&regenerate_after_adjustment=1&print=1" 
                                                   class="btn btn-success btn-sm" target="_blank">
                                                    <i class="fas fa-print me-1"></i>Print
                                                </a>
                                            </div>
                                        <?php else: ?>
                                            <form method="POST" class="compact-form">
                                                <input type="hidden" name="change_id" value="<?php echo $adj['id']; ?>">
                                                <div class="mb-2">
                                                    <textarea name="remarks" class="form-control" rows="1" placeholder="Remarks (optional)"></textarea>
                                                </div>
                                                <div class="d-flex gap-2">
                                                    <button type="submit" name="action" value="reject" class="btn btn-danger btn-sm">
                                                        <i class="fas fa-times me-1"></i>Reject
                                                    </button>
                                                </div>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                                
                                <?php 
                                $has_pending = false;
                                foreach ($student['adjustments'] as $adj_check) {
                                    if ($adj_check['status'] === 'pending_registrar') {
                                        $has_pending = true;
                                        break;
                                    }
                                }
                                if ($has_pending): 
                                ?>
                                    <div class="mt-2 p-2 bg-light border rounded">
                                        <a href="<?php echo htmlspecialchars('generate_cor_with_adjustments.php?user_id=' . $student['user_id']); ?>" 
                                           class="btn btn-primary btn-sm">
                                            <i class="fas fa-file-alt me-1"></i>Generate COR with Adjustments
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <?php if (!empty($approved_by_student)): ?>
            <div class="mt-5">
                <h3 class="mb-3"><i class="fas fa-history me-2 text-success"></i>Approved Adjustments History</h3>
                <p class="text-muted mb-3">All approved adjustments are shown below for reference and history tracking.</p>
                <div class="row">
                    <?php foreach ($approved_by_student as $student): ?>
                        <div class="col-md-12 mb-4">
                            <div class="card border-success">
                                <div class="card-header bg-success text-white">
                                    <h5 class="mb-0">
                                        <i class="fas fa-user me-2"></i>
                                        <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                        <small>(<?php echo htmlspecialchars($student['student_id']); ?>)</small>
                                        <span class="badge bg-light text-dark ms-2"><?php echo count($student['adjustments']); ?> change(s)</span>
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <p class="text-muted mb-3">
                                        <i class="fas fa-clock me-1"></i>
                                        Latest Approval: <?php echo date('M d, Y h:i A', strtotime($student['latest_approval'])); ?>
                                    </p>
                                    
                                    <h6 class="mb-3"><i class="fas fa-list me-2"></i>Adjustment Details:</h6>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-bordered">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Type</th>
                                                    <th>Subject</th>
                                                    <th>Details</th>
                                                    <th>Date</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $change_type_labels = [
                                                    'add' => '<span class="badge bg-success">Add Subject</span>',
                                                    'remove' => '<span class="badge bg-danger">Remove Subject</span>',
                                                    'schedule_change' => '<span class="badge bg-info">Schedule Change</span>'
                                                ];
                                                
                                                foreach ($student['adjustments'] as $adj): 
                                                    $change_type_label = $change_type_labels[$adj['change_type']] ?? '<span class="badge bg-secondary">' . ucfirst($adj['change_type']) . '</span>';
                                                ?>
                                                    <tr>
                                                        <td><?php echo $change_type_label; ?></td>
                                                        <td>
                                                            <?php if ($adj['course_code']): ?>
                                                                <strong><?php echo htmlspecialchars($adj['course_code']); ?></strong><br>
                                                                <small class="text-muted"><?php echo htmlspecialchars($adj['course_name'] ?? ''); ?></small>
                                                            <?php else: ?>
                                                                <span class="text-muted">N/A</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?php if ($adj['change_type'] === 'add'): ?>
                                                                <?php if ($adj['new_section_name']): ?>
                                                                    <strong>Section:</strong> <?php echo htmlspecialchars($adj['new_section_name']); ?><br>
                                                                    <?php
                                                                    $days = [];
                                                                    if ($adj['new_monday']) $days[] = 'Mon';
                                                                    if ($adj['new_tuesday']) $days[] = 'Tue';
                                                                    if ($adj['new_wednesday']) $days[] = 'Wed';
                                                                    if ($adj['new_thursday']) $days[] = 'Thu';
                                                                    if ($adj['new_friday']) $days[] = 'Fri';
                                                                    if ($adj['new_saturday']) $days[] = 'Sat';
                                                                    if ($adj['new_sunday']) $days[] = 'Sun';
                                                                    ?>
                                                                    <strong>Schedule:</strong> <?php echo htmlspecialchars(implode(', ', $days)); ?> 
                                                                    <?php if ($adj['new_time_start'] && $adj['new_time_end']): ?>
                                                                        <?php echo htmlspecialchars($adj['new_time_start'] . ' - ' . $adj['new_time_end']); ?>
                                                                    <?php endif; ?><br>
                                                                    <?php if ($adj['new_room']): ?>
                                                                        <strong>Room:</strong> <?php echo htmlspecialchars($adj['new_room']); ?><br>
                                                                    <?php endif; ?>
                                                                    <?php if ($adj['new_professor_name']): ?>
                                                                        <strong>Professor:</strong> <?php echo htmlspecialchars($adj['new_professor_name']); ?>
                                                                    <?php endif; ?>
                                                                <?php else: ?>
                                                                    <span class="text-muted">Schedule details pending</span>
                                                                <?php endif; ?>
                                                            <?php elseif ($adj['change_type'] === 'remove'): ?>
                                                                <?php if ($adj['old_section_name']): ?>
                                                                    <strong>Removed from:</strong> <?php echo htmlspecialchars($adj['old_section_name']); ?><br>
                                                                    <?php if ($adj['old_time_start'] && $adj['old_time_end']): ?>
                                                                        <strong>Schedule:</strong> <?php echo htmlspecialchars($adj['old_time_start'] . ' - ' . $adj['old_time_end']); ?>
                                                                    <?php endif; ?>
                                                                <?php else: ?>
                                                                    <span class="text-muted">Subject removed</span>
                                                                <?php endif; ?>
                                                            <?php elseif ($adj['change_type'] === 'schedule_change'): ?>
                                                                <div class="row">
                                                                    <div class="col-md-6">
                                                                        <small class="text-muted"><strong>From:</strong></small><br>
                                                                        <?php if ($adj['old_section_name']): ?>
                                                                            <?php echo htmlspecialchars($adj['old_section_name']); ?><br>
                                                                            <?php
                                                                            $old_days = [];
                                                                            if ($adj['old_monday']) $old_days[] = 'Mon';
                                                                            if ($adj['old_tuesday']) $old_days[] = 'Tue';
                                                                            if ($adj['old_wednesday']) $old_days[] = 'Wed';
                                                                            if ($adj['old_thursday']) $old_days[] = 'Thu';
                                                                            if ($adj['old_friday']) $old_days[] = 'Fri';
                                                                            if ($adj['old_saturday']) $old_days[] = 'Sat';
                                                                            if ($adj['old_sunday']) $old_days[] = 'Sun';
                                                                            ?>
                                                                            <?php echo htmlspecialchars(implode(', ', $old_days)); ?>
                                                                            <?php if ($adj['old_time_start'] && $adj['old_time_end']): ?>
                                                                                <?php echo htmlspecialchars($adj['old_time_start'] . ' - ' . $adj['old_time_end']); ?>
                                                                            <?php endif; ?>
                                                                        <?php else: ?>
                                                                            <span class="text-muted">N/A</span>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <small class="text-muted"><strong>To:</strong></small><br>
                                                                        <?php if ($adj['new_section_name']): ?>
                                                                            <?php echo htmlspecialchars($adj['new_section_name']); ?><br>
                                                                            <?php
                                                                            $new_days = [];
                                                                            if ($adj['new_monday']) $new_days[] = 'Mon';
                                                                            if ($adj['new_tuesday']) $new_days[] = 'Tue';
                                                                            if ($adj['new_wednesday']) $new_days[] = 'Wed';
                                                                            if ($adj['new_thursday']) $new_days[] = 'Thu';
                                                                            if ($adj['new_friday']) $new_days[] = 'Fri';
                                                                            if ($adj['new_saturday']) $new_days[] = 'Sat';
                                                                            if ($adj['new_sunday']) $new_days[] = 'Sun';
                                                                            ?>
                                                                            <?php echo htmlspecialchars(implode(', ', $new_days)); ?>
                                                                            <?php if ($adj['new_time_start'] && $adj['new_time_end']): ?>
                                                                                <?php echo htmlspecialchars($adj['new_time_start'] . ' - ' . $adj['new_time_end']); ?>
                                                                            <?php endif; ?>
                                                                        <?php else: ?>
                                                                            <span class="text-muted">N/A</span>
                                                                        <?php endif; ?>
                                                                    </div>
                                                                </div>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <small class="text-muted">
                                                                <?php 
                                                                $display_date = $adj['registrar_reviewed_at'] ?? $adj['change_date'];
                                                                echo date('M d, Y', strtotime($display_date)); 
                                                                ?>
                                                            </small>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                    
                                    <div class="mt-3">
                                        <a href="../admin/generate_cor.php?user_id=<?php echo $student['user_id']; ?>&regenerate_after_adjustment=1" 
                                           class="btn btn-primary" target="_blank">
                                            <i class="fas fa-file-alt me-1"></i>Generate/Print Updated COR
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

