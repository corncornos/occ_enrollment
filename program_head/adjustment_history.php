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

// Get all adjustment requests reviewed by this program head (history)
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
                     dean.first_name as dean_first_name, dean.last_name as dean_last_name,
                     apc.dean_remarks,
                     reg.first_name as reg_first_name, reg.last_name as reg_last_name,
                     apc.registrar_remarks
                     FROM adjustment_period_changes apc
                     JOIN users u ON apc.user_id = u.id
                     LEFT JOIN curriculum c ON apc.curriculum_id = c.id
                     LEFT JOIN section_schedules ss_old ON apc.old_section_schedule_id = ss_old.id
                     LEFT JOIN sections s_old ON ss_old.section_id = s_old.id
                     LEFT JOIN section_schedules ss_new ON apc.new_section_schedule_id = ss_new.id
                     LEFT JOIN sections s_new ON ss_new.section_id = s_new.id
                     LEFT JOIN programs p_old ON s_old.program_id = p_old.id
                     LEFT JOIN programs p_new ON s_new.program_id = p_new.id
                     LEFT JOIN users dean ON apc.dean_reviewed_by = dean.id
                     LEFT JOIN users reg ON apc.registrar_reviewed_by = reg.id
                     WHERE apc.program_head_reviewed_by = :program_head_id
                     AND (p_old.program_code = :program_code OR p_new.program_code = :program_code OR 
                          EXISTS (SELECT 1 FROM section_enrollments se 
                                  JOIN sections s ON se.section_id = s.id 
                                  JOIN programs p ON s.program_id = p.id 
                                  WHERE se.user_id = apc.user_id AND p.program_code = :program_code))
                     ORDER BY apc.program_head_reviewed_at DESC, apc.change_date DESC";
$adjustments_stmt = $conn->prepare($adjustments_query);
$adjustments_stmt->bindParam(':program_head_id', $_SESSION['user_id'], PDO::PARAM_INT);
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

// Get pending next semester enrollment count for sidebar
$next_sem_count_query = "SELECT COUNT(DISTINCT nse.id) as count
                         FROM next_semester_enrollments nse
                         JOIN users u ON nse.user_id = u.id
                         LEFT JOIN pre_enrollment_forms pef ON (pef.enrollment_request_id = nse.id OR (pef.user_id = nse.user_id AND pef.enrollment_request_id IS NULL))
                         LEFT JOIN sections selected_section ON nse.selected_section_id = selected_section.id
                         LEFT JOIN programs selected_program ON selected_section.program_id = selected_program.id
                         LEFT JOIN section_enrollments se ON u.id = se.user_id AND se.status = 'active'
                         LEFT JOIN sections s ON se.section_id = s.id
                         LEFT JOIN programs p ON s.program_id = p.id
                         WHERE nse.request_status = 'pending_program_head'
                         AND nse.enrollment_type = 'irregular'
                         AND (
                             pef.current_course = :program_code
                             OR p.program_code = :program_code
                             OR selected_program.program_code = :program_code
                         )";
$next_sem_count_stmt = $conn->prepare($next_sem_count_query);
$next_sem_count_stmt->bindParam(':program_code', $program_code);
$next_sem_count_stmt->execute();
$next_sem_count_result = $next_sem_count_stmt->fetch(PDO::FETCH_ASSOC);
$pending_next_sem_count = $next_sem_count_result['count'] ?? 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Adjustment History - Program Head</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
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
        @media (min-width: 992px) {
            .sidebar {
                width: 260px;
            }
        }
        @media (max-width: 767px) {
            .sidebar {
                transform: translateX(-100%);
                width: 260px;
            }
        }
        .sidebar-logo {
            padding: 1.5rem 1rem;
            border-bottom: 1px solid #334155;
        }
        .sidebar-logo h5 {
            margin: 0;
            font-size: 1rem;
            color: #fff;
        }
        .sidebar-menu {
            padding: 0.5rem 0;
        }
        .sidebar-menu-item {
            padding: 0.75rem 1rem;
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
            margin-right: 0.75rem;
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
        .main-content {
            margin-left: 260px;
            padding: 2rem;
            min-height: 100vh;
            background: #f1f5f9;
        }
        @media (max-width: 767px) {
            .main-content {
                margin-left: 0;
            }
        }
        .badge {
            font-size: 0.7rem;
        }
    </style>
</head>
<body>
    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-logo">
            <h5><i class="fas fa-user-tie me-2"></i>Program Head</h5>
            <small class="text-muted">OCC Enrollment System</small>
        </div>
        
        <div class="sidebar-menu">
            <a href="dashboard.php" class="sidebar-menu-item">
                <i class="fas fa-home"></i>
                <span>Dashboard</span>
            </a>
            <a href="curriculum.php" class="sidebar-menu-item">
                <i class="fas fa-book"></i>
                <span>Curriculum</span>
            </a>
            <a href="bulk_upload.php" class="sidebar-menu-item">
                <i class="fas fa-upload"></i>
                <span>Bulk Upload</span>
            </a>
            <a href="submissions.php" class="sidebar-menu-item">
                <i class="fas fa-file-alt"></i>
                <span>Submissions</span>
            </a>
            <a href="next_semester_enrollments.php" class="sidebar-menu-item">
                <i class="fas fa-calendar-alt"></i>
                <span>Next Semester Enrollments</span>
                <?php if ($pending_next_sem_count > 0): ?>
                    <span class="badge bg-warning text-dark ms-auto"><?php echo $pending_next_sem_count; ?></span>
                <?php endif; ?>
            </a>
            <a href="review_adjustments.php" class="sidebar-menu-item">
                <i class="fas fa-exchange-alt"></i>
                <span>Review Adjustments</span>
            </a>
            <a href="adjustment_history.php" class="sidebar-menu-item active">
                <i class="fas fa-history"></i>
                <span>Adjustment History</span>
            </a>
            <a href="logout.php" class="sidebar-menu-item">
                <i class="fas fa-sign-out-alt"></i>
                <span>Logout</span>
            </a>
        </div>
        
        <div class="user-info">
            <strong><?php echo htmlspecialchars($_SESSION['first_name'] . ' ' . $_SESSION['last_name']); ?></strong>
            <small>Program Head (<?php echo htmlspecialchars($_SESSION['program_code']); ?>)</small>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="main-content">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="mb-0"><i class="fas fa-history me-2"></i>Adjustment History</h2>
            <a href="review_adjustments.php" class="btn btn-primary">
                <i class="fas fa-exchange-alt me-2"></i>Review Pending Adjustments
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
                <i class="fas fa-info-circle me-2"></i>No adjustment history found.
            </div>
        <?php else: ?>
            <div class="accordion" id="adjustmentsAccordion">
                <?php 
                $index = 0;
                foreach ($adjustments_by_student as $student): 
                    $index++;
                    $accordion_id = 'student_' . $student['user_id'];
                ?>
                    <div class="card mb-3">
                        <div class="card-header" id="heading<?php echo $accordion_id; ?>">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h5 class="mb-0">
                                        <button class="btn btn-link text-dark text-decoration-none p-0" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?php echo $accordion_id; ?>" aria-expanded="false" aria-controls="collapse<?php echo $accordion_id; ?>">
                                            <i class="fas fa-user me-2"></i>
                                            <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                                            <small class="text-muted">(<?php echo htmlspecialchars($student['student_id']); ?>)</small>
                                        </button>
                                    </h5>
                                    <small class="text-muted"><?php echo htmlspecialchars($student['email']); ?></small>
                                </div>
                                <div>
                                    <span class="badge bg-secondary">
                                        <?php echo count($student['adjustments']); ?> request(s)
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div id="collapse<?php echo $accordion_id; ?>" class="collapse" aria-labelledby="heading<?php echo $accordion_id; ?>" data-bs-parent="#adjustmentsAccordion">
                            <div class="card-body">
                                <?php foreach ($student['adjustments'] as $adj): 
                                    $status_badge_class = 'bg-secondary';
                                    $status_text = ucfirst(str_replace('_', ' ', $adj['status']));
                                    if ($adj['status'] === 'approved') {
                                        $status_badge_class = 'bg-success';
                                    } elseif ($adj['status'] === 'rejected') {
                                        $status_badge_class = 'bg-danger';
                                    } elseif ($adj['status'] === 'pending_dean') {
                                        $status_badge_class = 'bg-warning text-dark';
                                    } elseif ($adj['status'] === 'pending_registrar') {
                                        $status_badge_class = 'bg-info';
                                    }
                                ?>
                                    <div class="border rounded p-3 mb-3">
                                        <div class="d-flex justify-content-between align-items-start mb-3">
                                            <div>
                                                <h6 class="text-primary mb-1">
                                                    <?php 
                                                    $change_type_labels = [
                                                        'add' => 'Add Subject',
                                                        'remove' => 'Remove Subject',
                                                        'schedule_change' => 'Change Schedule'
                                                    ];
                                                    ?>
                                                    <span class="badge bg-primary me-2">
                                                        <?php echo $change_type_labels[$adj['change_type']] ?? ucfirst($adj['change_type']); ?>
                                                    </span>
                                                    <span class="badge <?php echo $status_badge_class; ?>">
                                                        <?php echo $status_text; ?>
                                                    </span>
                                                </h6>
                                            </div>
                                            <div class="text-end">
                                                <small class="text-muted d-block">
                                                    <i class="fas fa-clock me-1"></i>
                                                    Reviewed: <?php echo $adj['program_head_reviewed_at'] ? date('M d, Y h:i A', strtotime($adj['program_head_reviewed_at'])) : 'N/A'; ?>
                                                </small>
                                                <small class="text-muted">
                                                    <i class="fas fa-calendar me-1"></i>
                                                    Requested: <?php echo date('M d, Y h:i A', strtotime($adj['change_date'])); ?>
                                                </small>
                                            </div>
                                        </div>

                                        <?php if ($adj['program_head_remarks']): ?>
                                            <div class="alert alert-success mb-3">
                                                <strong><i class="fas fa-user-check me-2"></i>Your Remarks:</strong><br>
                                                <?php echo nl2br(htmlspecialchars($adj['program_head_remarks'])); ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($adj['dean_first_name']): ?>
                                            <div class="alert alert-info mb-3">
                                                <strong><i class="fas fa-user-check me-2"></i>Reviewed by Dean:</strong><br>
                                                <?php echo htmlspecialchars($adj['dean_first_name'] . ' ' . $adj['dean_last_name']); ?>
                                                <?php if ($adj['dean_remarks']): ?>
                                                    <br><small><em><?php echo nl2br(htmlspecialchars($adj['dean_remarks'])); ?></em></small>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($adj['reg_first_name']): ?>
                                            <div class="alert alert-warning mb-3">
                                                <strong><i class="fas fa-user-check me-2"></i>Reviewed by Registrar:</strong><br>
                                                <?php echo htmlspecialchars($adj['reg_first_name'] . ' ' . $adj['reg_last_name']); ?>
                                                <?php if ($adj['registrar_remarks']): ?>
                                                    <br><small><em><?php echo nl2br(htmlspecialchars($adj['registrar_remarks'])); ?></em></small>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                        <div class="mb-3">
                                            <h6 class="text-success">
                                                <i class="fas fa-book me-2"></i>
                                                <?php echo htmlspecialchars($adj['course_code'] . ' - ' . $adj['course_name']); ?>
                                            </h6>
                                            <?php if ($adj['units']): ?>
                                                <span class="badge bg-info"><?php echo htmlspecialchars((string)$adj['units']); ?> Units</span>
                                            <?php endif; ?>
                                            <?php if ($adj['year_level']): ?>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($adj['year_level']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        
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
                                        
                                        <?php if ($adj['remarks']): ?>
                                            <div class="mb-3">
                                                <strong><i class="fas fa-comment me-2"></i>Student Remarks:</strong>
                                                <p class="mb-0"><?php echo nl2br(htmlspecialchars($adj['remarks'])); ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div> <!-- main-content -->
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

