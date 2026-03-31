<?php
declare(strict_types=1);

require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../config/adjustment_helper.php';

if (!isProgramHead()) {
    redirect('../public/login.php');
}

$conn = (new Database())->getConnection();
$program_id = $_SESSION['program_id'];
$program_code = $_SESSION['program_code'] ?? '';

// Handle approval/rejection
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $change_id = (int)($_POST['change_id'] ?? 0);
    $action = $_POST['action'];
    $remarks = $_POST['remarks'] ?? null;
    
    if ($change_id > 0) {
        try {
            $conn->beginTransaction();
            
            if ($action === 'approve') {
                // Move to pending_dean
                $update_query = "UPDATE adjustment_period_changes 
                               SET status = 'pending_dean',
                                   program_head_reviewed_by = :reviewer_id,
                                   program_head_reviewed_at = NOW(),
                                   program_head_remarks = :remarks
                               WHERE id = :change_id 
                               AND status = 'pending_program_head'";
            } else {
                // Reject
                $update_query = "UPDATE adjustment_period_changes 
                               SET status = 'rejected',
                                   program_head_reviewed_by = :reviewer_id,
                                   program_head_reviewed_at = NOW(),
                                   program_head_remarks = :remarks
                               WHERE id = :change_id 
                               AND status = 'pending_program_head'";
            }
            
            $update_stmt = $conn->prepare($update_query);
            $update_stmt->bindParam(':reviewer_id', $_SESSION['user_id'], PDO::PARAM_INT);
            $update_stmt->bindParam(':change_id', $change_id, PDO::PARAM_INT);
            $update_stmt->bindParam(':remarks', $remarks);
            $update_stmt->execute();
            
            $conn->commit();
            $_SESSION['message'] = $action === 'approve' ? 'Adjustment request approved and forwarded to Dean.' : 'Adjustment request rejected.';
        } catch (Exception $e) {
            $conn->rollBack();
            $_SESSION['message'] = 'Error processing request: ' . $e->getMessage();
        }
    }
}

// Get pending adjustment requests for this program
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
                     s_new.semester as new_semester
                     FROM adjustment_period_changes apc
                     JOIN users u ON apc.user_id = u.id
                     LEFT JOIN curriculum c ON apc.curriculum_id = c.id
                     LEFT JOIN section_schedules ss_old ON apc.old_section_schedule_id = ss_old.id
                     LEFT JOIN sections s_old ON ss_old.section_id = s_old.id
                     LEFT JOIN section_schedules ss_new ON apc.new_section_schedule_id = ss_new.id
                     LEFT JOIN sections s_new ON ss_new.section_id = s_new.id
                     LEFT JOIN programs p_old ON s_old.program_id = p_old.id
                     LEFT JOIN programs p_new ON s_new.program_id = p_new.id
                     WHERE apc.status = 'pending_program_head'
                     AND (p_old.program_code = :program_code OR p_new.program_code = :program_code OR 
                          EXISTS (SELECT 1 FROM section_enrollments se 
                                  JOIN sections s ON se.section_id = s.id 
                                  JOIN programs p ON s.program_id = p.id 
                                  WHERE se.user_id = apc.user_id AND p.program_code = :program_code))
                     ORDER BY apc.change_date DESC";
$adjustments_stmt = $conn->prepare($adjustments_query);
$adjustments_stmt->bindParam(':program_code', $program_code);
$adjustments_stmt->execute();
$adjustments = $adjustments_stmt->fetchAll(PDO::FETCH_ASSOC);

// Group adjustments by student
$adjustments_by_student = [];
foreach ($adjustments as $adj) {
    $user_id = $adj['user_id'];
    if (!isset($adjustments_by_student[$user_id])) {
        $adjustments_by_student[$user_id] = [
            'user_id' => $user_id,
            'student_id' => $adj['student_id'],
            'first_name' => $adj['first_name'],
            'last_name' => $adj['last_name'],
            'email' => $adj['email'],
            'adjustments' => []
        ];
    }
    $adjustments_by_student[$user_id]['adjustments'][] = $adj;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Adjustment Requests - Program Head</title>
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
            <div class="alert alert-info alert-dismissible fade show">
                <?php echo htmlspecialchars($_SESSION['message']); unset($_SESSION['message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <?php if (empty($adjustments_by_student)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>No pending adjustment requests at this time.
            </div>
        <?php else: ?>
            <div class="accordion" id="adjustmentsAccordion">
                <?php 
                $index = 0;
                foreach ($adjustments_by_student as $student): 
                    $index++;
                    $accordion_id = 'student_' . $student['user_id'];
                ?>
                    <div class="card mb-2">
                        <div class="card-header bg-primary text-white compact-header" id="heading<?php echo $accordion_id; ?>">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="mb-0">
                                        <button class="btn btn-link text-white text-decoration-none p-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?php echo $accordion_id; ?>" aria-expanded="false" aria-controls="collapse<?php echo $accordion_id; ?>">
                                            <i class="fas fa-user me-1"></i>
                                            <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                            <small class="text-white-50">(<?php echo htmlspecialchars($student['student_id']); ?>)</small>
                                        </button>
                                    </h5>
                                    <small class="text-white-50"><?php echo htmlspecialchars($student['email']); ?></small>
                                </div>
                                <div>
                                    <span class="badge bg-light text-dark compact-badge">
                                        <?php echo count($student['adjustments']); ?> request(s)
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div id="collapse<?php echo $accordion_id; ?>" class="collapse" aria-labelledby="heading<?php echo $accordion_id; ?>" data-bs-parent="#adjustmentsAccordion">
                            <div class="card-body compact-card">
                                <?php foreach ($student['adjustments'] as $adj): ?>
                                    <div class="border rounded compact-card">
                                        <div class="d-flex justify-content-between align-items-start mb-2">
                                            <div>
                                                <span class="badge compact-badge <?php 
                                                    echo $adj['change_type'] === 'add' ? 'bg-success' : 
                                                        ($adj['change_type'] === 'remove' ? 'bg-danger' : 'bg-warning'); 
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
                                                <strong class="text-primary"><?php echo htmlspecialchars($adj['course_code']); ?></strong>
                                                <span class="text-muted">- <?php echo htmlspecialchars($adj['course_name']); ?></span>
                                                <?php if ($adj['units']): ?>
                                                    <span class="badge compact-badge bg-info ms-1"><?php echo htmlspecialchars((string)$adj['units']); ?>U</span>
                                                <?php endif; ?>
                                            </div>
                                            <small class="text-muted">
                                                <i class="fas fa-clock me-1"></i><?php echo date('M d, Y', strtotime($adj['change_date'])); ?>
                                            </small>
                                        </div>
                                        
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
                                            <?php
                                            $prereq_check = checkPrerequisitesForSubject($conn, $adj['user_id'], (int)$adj['curriculum_id']);
                                            if (!$prereq_check['met']): ?>
                                                <div class="alert alert-warning compact-alert mb-2">
                                                    <i class="fas fa-exclamation-triangle me-1"></i><strong>Prereq:</strong> 
                                                    <?php echo implode(', ', array_column($prereq_check['unmet_prerequisites'], 'code')); ?>
                                                </div>
                                            <?php endif; ?>
                                            
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
                                        
                                        <form method="POST" class="compact-form">
                                            <input type="hidden" name="change_id" value="<?php echo $adj['id']; ?>">
                                            <div class="mb-2">
                                                <textarea name="remarks" class="form-control" rows="1" placeholder="Remarks (optional)"></textarea>
                                            </div>
                                            <div class="d-flex gap-2">
                                                <button type="submit" name="action" value="approve" class="btn btn-success btn-sm">
                                                    <i class="fas fa-check me-1"></i>Approve
                                                </button>
                                                <button type="submit" name="action" value="reject" class="btn btn-danger btn-sm">
                                                    <i class="fas fa-times me-1"></i>Reject
                                                </button>
                                            </div>
                                        </form>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

