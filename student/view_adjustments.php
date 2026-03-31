<?php
declare(strict_types=1);

require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../config/adjustment_helper.php';

if (!isLoggedIn()) {
    redirect('../public/login.php');
}

$conn = (new Database())->getConnection();
$user_id = (int)$_SESSION['user_id'];

// Get all adjustment requests for this student
$adjustments_query = "SELECT apc.*, 
                     c.course_code, c.course_name, c.units,
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
                     ph.first_name as ph_first_name,
                     ph.last_name as ph_last_name,
                     dean.first_name as dean_first_name,
                     dean.last_name as dean_last_name,
                     reg.first_name as reg_first_name,
                     reg.last_name as reg_last_name
                     FROM adjustment_period_changes apc
                     LEFT JOIN curriculum c ON apc.curriculum_id = c.id
                     LEFT JOIN section_schedules ss_old ON apc.old_section_schedule_id = ss_old.id
                     LEFT JOIN sections s_old ON ss_old.section_id = s_old.id
                     LEFT JOIN section_schedules ss_new ON apc.new_section_schedule_id = ss_new.id
                     LEFT JOIN sections s_new ON ss_new.section_id = s_new.id
                     LEFT JOIN users ph ON apc.program_head_reviewed_by = ph.id
                     LEFT JOIN users dean ON apc.dean_reviewed_by = dean.id
                     LEFT JOIN users reg ON apc.registrar_reviewed_by = reg.id
                     WHERE apc.user_id = :user_id
                     ORDER BY apc.change_date DESC";
$adjustments_stmt = $conn->prepare($adjustments_query);
$adjustments_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$adjustments_stmt->execute();
$adjustments = $adjustments_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get status counts
$status_counts = [
    'pending_program_head' => 0,
    'pending_dean' => 0,
    'pending_registrar' => 0,
    'approved' => 0,
    'rejected' => 0
];

foreach ($adjustments as $adj) {
    if (isset($status_counts[$adj['status']])) {
        $status_counts[$adj['status']]++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Adjustment Requests - Student</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f2f6fc;
        }
        .card {
            border: none;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-radius: 10px;
            margin-bottom: 20px;
        }
        .status-badge {
            font-size: 0.85em;
            padding: 0.4em 0.8em;
        }
        .status-pending_program_head {
            background-color: #ffc107;
            color: #000;
        }
        .status-pending_dean {
            background-color: #17a2b8;
            color: #fff;
        }
        .status-pending_registrar {
            background-color: #6c757d;
            color: #fff;
        }
        .status-approved {
            background-color: #28a745;
            color: #fff;
        }
        .status-rejected {
            background-color: #dc3545;
            color: #fff;
        }
        .timeline-item {
            border-left: 3px solid #dee2e6;
            padding-left: 20px;
            margin-bottom: 15px;
            position: relative;
        }
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -6px;
            top: 5px;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #6c757d;
        }
        .timeline-item.approved::before {
            background: #28a745;
        }
        .timeline-item.rejected::before {
            background: #dc3545;
        }
        .timeline-item.pending::before {
            background: #ffc107;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h4 mb-0"><i class="fas fa-list-alt me-2 text-primary"></i>My Adjustment Requests</h1>
            <div>
                <a href="adjustment_period.php" class="btn btn-primary me-2">
                    <i class="fas fa-exchange-alt me-2"></i>Make Adjustments
                </a>
                <a href="dashboard.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                </a>
            </div>
        </div>

        <!-- Status Summary -->
        <div class="row mb-4">
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-warning"><?php echo $status_counts['pending_program_head']; ?></h3>
                        <small>Pending Program Head</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-info"><?php echo $status_counts['pending_dean']; ?></h3>
                        <small>Pending Dean</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-secondary"><?php echo $status_counts['pending_registrar']; ?></h3>
                        <small>Pending Registrar</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-success"><?php echo $status_counts['approved']; ?></h3>
                        <small>Approved</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-danger"><?php echo $status_counts['rejected']; ?></h3>
                        <small>Rejected</small>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card text-center">
                    <div class="card-body">
                        <h3><?php echo count($adjustments); ?></h3>
                        <small>Total Requests</small>
                    </div>
                </div>
            </div>
        </div>

        <?php if (empty($adjustments)): ?>
            <div class="card">
                <div class="card-body text-center py-5">
                    <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                    <h5>No Adjustment Requests</h5>
                    <p class="text-muted">You haven't submitted any adjustment requests yet.</p>
                    <a href="adjustment_period.php" class="btn btn-primary">
                        <i class="fas fa-plus me-2"></i>Make Your First Adjustment
                    </a>
                </div>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($adjustments as $adj): ?>
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <?php 
                                    $change_type_labels = [
                                        'add' => '<i class="fas fa-plus-circle me-2"></i>Add Subject',
                                        'remove' => '<i class="fas fa-minus-circle me-2"></i>Remove Subject',
                                        'schedule_change' => '<i class="fas fa-exchange-alt me-2"></i>Change Schedule'
                                    ];
                                    echo $change_type_labels[$adj['change_type']] ?? ucfirst($adj['change_type']);
                                    ?>
                                </h5>
                                <span class="badge status-badge status-<?php echo $adj['status']; ?>">
                                    <?php 
                                    $status_labels = [
                                        'pending_program_head' => 'Pending Program Head',
                                        'pending_dean' => 'Pending Dean',
                                        'pending_registrar' => 'Pending Registrar',
                                        'approved' => 'Approved',
                                        'rejected' => 'Rejected'
                                    ];
                                    echo $status_labels[$adj['status']] ?? $adj['status'];
                                    ?>
                                </span>
                            </div>
                            <div class="card-body">
                                <!-- Subject Information -->
                                <div class="mb-3">
                                    <h6 class="text-primary">
                                        <i class="fas fa-book me-2"></i>
                                        <?php echo htmlspecialchars($adj['course_code'] . ' - ' . $adj['course_name']); ?>
                                    </h6>
                                    <?php if ($adj['units']): ?>
                                        <small class="text-muted"><?php echo htmlspecialchars((string)$adj['units']); ?> Units</small>
                                    <?php endif; ?>
                                </div>

                                <!-- Change Details -->
                                <?php if ($adj['change_type'] === 'add'): ?>
                                    <div class="alert alert-info">
                                        <strong><i class="fas fa-plus me-2"></i>Adding Subject:</strong><br>
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
                                            <?php echo htmlspecialchars($adj['new_time_start'] . ' - ' . $adj['new_time_end']); ?><br>
                                            <?php if ($adj['new_room']): ?>
                                                <strong>Room:</strong> <?php echo htmlspecialchars($adj['new_room']); ?><br>
                                            <?php endif; ?>
                                            <?php if ($adj['new_professor_name']): ?>
                                                <strong>Professor:</strong> <?php echo htmlspecialchars($adj['new_professor_name']); ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            Schedule details pending
                                        <?php endif; ?>
                                    </div>

                                <?php elseif ($adj['change_type'] === 'remove'): ?>
                                    <div class="alert alert-warning">
                                        <strong><i class="fas fa-minus me-2"></i>Removing Subject:</strong><br>
                                        <?php if ($adj['old_section_name']): ?>
                                            <strong>Current Section:</strong> <?php echo htmlspecialchars($adj['old_section_name']); ?><br>
                                            <strong>Current Schedule:</strong> <?php echo htmlspecialchars($adj['old_time_start'] . ' - ' . $adj['old_time_end']); ?>
                                        <?php endif; ?>
                                    </div>

                                <?php elseif ($adj['change_type'] === 'schedule_change'): ?>
                                    <div class="row">
                                        <div class="col-md-6">
                                            <div class="alert alert-secondary">
                                                <strong><i class="fas fa-arrow-left me-2"></i>From:</strong><br>
                                                <?php if ($adj['old_section_name']): ?>
                                                    <strong>Section:</strong> <?php echo htmlspecialchars($adj['old_section_name']); ?><br>
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
                                                    <strong>Schedule:</strong> <?php echo htmlspecialchars(implode(', ', $old_days)); ?> 
                                                    <?php echo htmlspecialchars($adj['old_time_start'] . ' - ' . $adj['old_time_end']); ?><br>
                                                    <?php if ($adj['old_room']): ?>
                                                        <strong>Room:</strong> <?php echo htmlspecialchars($adj['old_room']); ?><br>
                                                    <?php endif; ?>
                                                    <?php if ($adj['old_professor_name']): ?>
                                                        <strong>Professor:</strong> <?php echo htmlspecialchars($adj['old_professor_name']); ?>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    N/A
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="alert alert-success">
                                                <strong><i class="fas fa-arrow-right me-2"></i>To:</strong><br>
                                                <?php if ($adj['new_section_name']): ?>
                                                    <strong>Section:</strong> <?php echo htmlspecialchars($adj['new_section_name']); ?><br>
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
                                                    <strong>Schedule:</strong> <?php echo htmlspecialchars(implode(', ', $new_days)); ?> 
                                                    <?php echo htmlspecialchars($adj['new_time_start'] . ' - ' . $adj['new_time_end']); ?><br>
                                                    <?php if ($adj['new_room']): ?>
                                                        <strong>Room:</strong> <?php echo htmlspecialchars($adj['new_room']); ?><br>
                                                    <?php endif; ?>
                                                    <?php if ($adj['new_professor_name']): ?>
                                                        <strong>Professor:</strong> <?php echo htmlspecialchars($adj['new_professor_name']); ?>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    N/A
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endif; ?>

                                <!-- Student Remarks -->
                                <?php if ($adj['remarks']): ?>
                                    <div class="mb-3">
                                        <strong><i class="fas fa-comment me-2"></i>Your Remarks:</strong>
                                        <p class="mb-0"><?php echo nl2br(htmlspecialchars($adj['remarks'])); ?></p>
                                    </div>
                                <?php endif; ?>

                                <!-- Review Timeline -->
                                <div class="mt-3 pt-3 border-top">
                                    <h6 class="mb-3"><i class="fas fa-history me-2"></i>Review Timeline</h6>
                                    
                                    <div class="timeline-item <?php echo $adj['status'] === 'approved' ? 'approved' : ($adj['status'] === 'rejected' ? 'rejected' : 'pending'); ?>">
                                        <strong>Submitted</strong><br>
                                        <small class="text-muted"><?php echo date('M d, Y h:i A', strtotime($adj['change_date'])); ?></small>
                                    </div>

                                    <?php if ($adj['program_head_reviewed_at']): ?>
                                        <div class="timeline-item <?php echo $adj['status'] === 'rejected' && !$adj['dean_reviewed_at'] ? 'rejected' : 'approved'; ?>">
                                            <strong>Program Head Review</strong><br>
                                            <small>
                                                <?php if ($adj['ph_first_name']): ?>
                                                    Reviewed by: <?php echo htmlspecialchars($adj['ph_first_name'] . ' ' . $adj['ph_last_name']); ?><br>
                                                <?php endif; ?>
                                                <?php echo date('M d, Y h:i A', strtotime($adj['program_head_reviewed_at'])); ?>
                                            </small>
                                            <?php if ($adj['program_head_remarks']): ?>
                                                <div class="mt-1">
                                                    <small><em><?php echo nl2br(htmlspecialchars($adj['program_head_remarks'])); ?></em></small>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($adj['dean_reviewed_at']): ?>
                                        <div class="timeline-item <?php echo $adj['status'] === 'rejected' && !$adj['registrar_reviewed_at'] ? 'rejected' : 'approved'; ?>">
                                            <strong>Dean Review</strong><br>
                                            <small>
                                                <?php if ($adj['dean_first_name']): ?>
                                                    Reviewed by: <?php echo htmlspecialchars($adj['dean_first_name'] . ' ' . $adj['dean_last_name']); ?><br>
                                                <?php endif; ?>
                                                <?php echo date('M d, Y h:i A', strtotime($adj['dean_reviewed_at'])); ?>
                                            </small>
                                            <?php if ($adj['dean_remarks']): ?>
                                                <div class="mt-1">
                                                    <small><em><?php echo nl2br(htmlspecialchars($adj['dean_remarks'])); ?></em></small>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($adj['registrar_reviewed_at']): ?>
                                        <div class="timeline-item <?php echo $adj['status'] === 'approved' ? 'approved' : 'rejected'; ?>">
                                            <strong>Registrar Staff Review</strong><br>
                                            <small>
                                                <?php if ($adj['reg_first_name']): ?>
                                                    Reviewed by: <?php echo htmlspecialchars($adj['reg_first_name'] . ' ' . $adj['reg_last_name']); ?><br>
                                                <?php endif; ?>
                                                <?php echo date('M d, Y h:i A', strtotime($adj['registrar_reviewed_at'])); ?>
                                            </small>
                                            <?php if ($adj['registrar_remarks']): ?>
                                                <div class="mt-1">
                                                    <small><em><?php echo nl2br(htmlspecialchars($adj['registrar_remarks'])); ?></em></small>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
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

