<?php
declare(strict_types=1);

require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../config/adjustment_helper.php';

if (!isRegistrarStaff()) {
    redirect('../public/login.php');
}

$conn = (new Database())->getConnection();
$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

if ($user_id <= 0) {
    $_SESSION['message'] = 'Invalid student ID.';
    redirect('registrar_staff/review_adjustments.php');
}

// Get student information
$student_query = "SELECT id, student_id, first_name, last_name, middle_name, email, 
                 permanent_address, address
                 FROM users WHERE id = :user_id";
$student_stmt = $conn->prepare($student_query);
$student_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$student_stmt->execute();
$student = $student_stmt->fetch(PDO::FETCH_ASSOC);

if (!$student) {
    $_SESSION['message'] = 'Student not found.';
    redirect('registrar_staff/review_adjustments.php');
}

// Get current COR based on student's most recent active enrollment
// Determine current semester from active student_schedules (most accurate source)
// Hierarchy: 1st Year First > 1st Year Second > 2nd Year First > 2nd Year Second > 3rd Year First > etc.
// Most recent = highest year level, then highest semester within that year
$current_enrollment_query = "SELECT DISTINCT s.academic_year, s.semester, s.year_level, s.id as section_id
                            FROM student_schedules sts
                            JOIN section_schedules ss ON sts.section_schedule_id = ss.id
                            JOIN sections s ON ss.section_id = s.id
                            WHERE sts.user_id = :user_id
                            AND sts.status = 'active'
                            ORDER BY s.academic_year DESC,
                                     CASE s.year_level
                                         WHEN '4th Year' THEN 4
                                         WHEN '3rd Year' THEN 3
                                         WHEN '2nd Year' THEN 2
                                         WHEN '1st Year' THEN 1
                                         ELSE 0
                                     END DESC,
                                     CASE s.semester
                                         WHEN 'Second Semester' THEN 2
                                         WHEN 'First Semester' THEN 1
                                         ELSE 0
                                     END DESC
                            LIMIT 1";
$current_enrollment_stmt = $conn->prepare($current_enrollment_query);
$current_enrollment_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$current_enrollment_stmt->execute();
$current_enrollment = $current_enrollment_stmt->fetch(PDO::FETCH_ASSOC);

// Get COR that matches the current enrollment semester
if ($current_enrollment && !empty($current_enrollment['academic_year']) && !empty($current_enrollment['semester'])) {
    // Get the most recent COR for this specific semester
    $current_cor_query = "SELECT cor.*, s.program_id, s.section_name,
                         p.program_code, p.program_name
                         FROM certificate_of_registration cor
                         JOIN sections s ON cor.section_id = s.id
                         JOIN programs p ON cor.program_id = p.id
                         WHERE cor.user_id = :user_id
                         AND cor.academic_year = :academic_year
                         AND cor.semester = :semester
                         ORDER BY cor.created_at DESC
                         LIMIT 1";
    $current_cor_stmt = $conn->prepare($current_cor_query);
    $current_cor_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $current_cor_stmt->bindParam(':academic_year', $current_enrollment['academic_year']);
    $current_cor_stmt->bindParam(':semester', $current_enrollment['semester']);
    $current_cor_stmt->execute();
    $current_cor = $current_cor_stmt->fetch(PDO::FETCH_ASSOC);
} else {
    // Fallback: get most recent COR if no active schedules found
    $current_cor_query = "SELECT cor.*, s.program_id, s.section_name,
                         p.program_code, p.program_name
                         FROM certificate_of_registration cor
                         JOIN sections s ON cor.section_id = s.id
                         JOIN programs p ON cor.program_id = p.id
                         WHERE cor.user_id = :user_id
                         ORDER BY cor.academic_year DESC,
                                  CASE cor.semester
                                      WHEN 'Second Semester' THEN 2
                                      WHEN 'First Semester' THEN 1
                                      ELSE 0
                                  END DESC,
                                  cor.created_at DESC
                         LIMIT 1";
    $current_cor_stmt = $conn->prepare($current_cor_query);
    $current_cor_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $current_cor_stmt->execute();
    $current_cor = $current_cor_stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$current_cor) {
    $_SESSION['message'] = 'No COR found for this student. Please generate a COR first.';
    redirect('registrar_staff/review_adjustments.php');
}

// Parse current COR subjects
$current_subjects = [];
if (!empty($current_cor['subjects_json'])) {
    $current_subjects = json_decode($current_cor['subjects_json'], true) ?: [];
}

// Get pending adjustment requests (approved by dean, pending registrar)
$adjustments_query = "SELECT apc.*, u.student_id, u.first_name, u.last_name,
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
                     s_new.academic_year as new_academic_year,
                     s_new.semester as new_semester,
                     s_new.year_level as new_year_level
                     FROM adjustment_period_changes apc
                     JOIN users u ON apc.user_id = u.id
                     LEFT JOIN curriculum c ON apc.curriculum_id = c.id
                     LEFT JOIN section_schedules ss_old ON apc.old_section_schedule_id = ss_old.id
                     LEFT JOIN sections s_old ON ss_old.section_id = s_old.id
                     LEFT JOIN section_schedules ss_new ON apc.new_section_schedule_id = ss_new.id
                     LEFT JOIN sections s_new ON ss_new.section_id = s_new.id
                     WHERE apc.user_id = :user_id
                     AND apc.status = 'pending_registrar'
                     ORDER BY apc.change_date ASC";
$adjustments_stmt = $conn->prepare($adjustments_query);
$adjustments_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$adjustments_stmt->execute();
$adjustments = $adjustments_stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($adjustments)) {
    $_SESSION['message'] = 'No pending adjustment requests found for this student.';
    redirect('registrar_staff/review_adjustments.php');
}

// Handle COR generation with adjustments
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'generate_cor') {
    try {
        $conn->beginTransaction();
        
        // Apply each adjustment to student_schedules
        foreach ($adjustments as $adj) {
            $result = false;
            
            if ($adj['change_type'] === 'add') {
                $result = addSubjectToStudent($conn, $user_id, $adj['new_section_schedule_id']);
            } elseif ($adj['change_type'] === 'remove') {
                $result = removeSubjectFromStudent($conn, $user_id, $adj['old_section_schedule_id']);
            } elseif ($adj['change_type'] === 'schedule_change') {
                $result = changeStudentSchedule($conn, $user_id, $adj['old_section_schedule_id'], $adj['new_section_schedule_id']);
            }
            
            if (!$result || !$result['success']) {
                throw new Exception('Failed to apply adjustment: ' . ($result['message'] ?? 'Unknown error'));
            }
            
            // Update adjustment status to approved
            $update_adj_query = "UPDATE adjustment_period_changes 
                               SET status = 'approved',
                                   registrar_reviewed_by = :reviewer_id,
                                   registrar_reviewed_at = NOW(),
                                   registrar_remarks = :remarks
                               WHERE id = :change_id";
            $update_adj_stmt = $conn->prepare($update_adj_query);
            $update_adj_stmt->bindParam(':reviewer_id', $_SESSION['user_id'], PDO::PARAM_INT);
            $update_adj_stmt->bindParam(':change_id', $adj['id'], PDO::PARAM_INT);
            $remarks = $_POST['remarks'] ?? 'COR generated with adjustments';
            $update_adj_stmt->bindParam(':remarks', $remarks);
            $update_adj_stmt->execute();
        }
        
        // Generate COR based on current COR subjects with adjustments applied
        // Start with current COR subjects, apply adjustments, then generate new COR
        $cor_result = generateCORFromCurrentWithAdjustments($conn, $user_id, $current_cor, $adjustments);
        
        if (!$cor_result['success']) {
            throw new Exception('Failed to generate COR: ' . $cor_result['message']);
        }
        
        $conn->commit();
        
        $_SESSION['message'] = 'COR generated successfully with adjustments applied.';
        redirect('registrar_staff/review_adjustments.php');
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $error_message = 'Error generating COR: ' . $e->getMessage();
    }
}

// Prepare preview of what the new COR will look like
// Start with current COR subjects and apply adjustments
$preview_subjects = $current_subjects;
$preview_total_units = $current_cor['total_units'];

// Apply adjustments to preview
foreach ($adjustments as $adj) {
    if ($adj['change_type'] === 'add') {
        // Check if subject already exists in current COR to avoid duplicates
        $course_code = $adj['course_code'] ?? null;
        $already_exists = false;
        if ($course_code) {
            foreach ($preview_subjects as $existing_subject) {
                if (($existing_subject['course_code'] ?? '') === $course_code) {
                    $already_exists = true;
                    break;
                }
            }
        }
        
        // Only add if it doesn't already exist
        if (!$already_exists) {
            // Add subject to preview
            $days = [];
            if ($adj['new_monday']) $days[] = 'Monday';
            if ($adj['new_tuesday']) $days[] = 'Tuesday';
            if ($adj['new_wednesday']) $days[] = 'Wednesday';
            if ($adj['new_thursday']) $days[] = 'Thursday';
            if ($adj['new_friday']) $days[] = 'Friday';
            if ($adj['new_saturday']) $days[] = 'Saturday';
            if ($adj['new_sunday']) $days[] = 'Sunday';
            
            $preview_subjects[] = [
                'course_code' => $adj['course_code'],
                'course_name' => $adj['course_name'],
                'units' => (float)$adj['units'],
                'year_level' => $adj['new_year_level'] ?? $current_cor['year_level'],
                'semester' => $adj['new_semester'] ?? $current_cor['semester'],
                'is_backload' => false,
                'is_added' => true,
                'remarks' => $adj['remarks'] ?? null,
                'section_info' => [
                    'section_id' => 0,
                    'section_name' => $adj['new_section_name'],
                    'schedule_days' => $days,
                    'time_start' => $adj['new_time_start'],
                    'time_end' => $adj['new_time_end'],
                    'room' => $adj['new_room'] ?: 'TBA',
                    'professor_name' => $adj['new_professor_name'] ?: 'TBA'
                ]
            ];
            $preview_total_units += (float)$adj['units'];
        }
    } elseif ($adj['change_type'] === 'remove') {
        // Remove subject from preview
        $preview_subjects = array_filter($preview_subjects, function($subject) use ($adj) {
            return $subject['course_code'] !== $adj['course_code'];
        });
        $preview_subjects = array_values($preview_subjects); // Re-index
        $preview_total_units -= (float)$adj['units'];
    } elseif ($adj['change_type'] === 'schedule_change') {
        // Update subject schedule in preview
        foreach ($preview_subjects as &$subject) {
            if ($subject['course_code'] === $adj['course_code']) {
                $days = [];
                if ($adj['new_monday']) $days[] = 'Monday';
                if ($adj['new_tuesday']) $days[] = 'Tuesday';
                if ($adj['new_wednesday']) $days[] = 'Wednesday';
                if ($adj['new_thursday']) $days[] = 'Thursday';
                if ($adj['new_friday']) $days[] = 'Friday';
                if ($adj['new_saturday']) $days[] = 'Saturday';
                if ($adj['new_sunday']) $days[] = 'Sunday';
                
                $subject['section_info'] = [
                    'section_id' => 0,
                    'section_name' => $adj['new_section_name'],
                    'schedule_days' => $days,
                    'time_start' => $adj['new_time_start'],
                    'time_end' => $adj['new_time_end'],
                    'room' => $adj['new_room'] ?: 'TBA',
                    'professor_name' => $adj['new_professor_name'] ?: 'TBA'
                ];
                break;
            }
        }
        unset($subject);
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generate COR with Adjustments - Registrar Staff</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .subject-card {
            border-left: 4px solid #007bff;
            margin-bottom: 1rem;
        }
        .adjustment-badge {
            font-size: 0.75rem;
        }
        .preview-section {
            background-color: #f8f9fa;
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-top: 2rem;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-file-alt me-2"></i>Generate COR with Adjustments</h2>
            <a href="review_adjustments.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Review Adjustments
            </a>
        </div>
        
        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Student Information -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-user me-2"></i>Student Information</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Student ID:</strong> <?php echo htmlspecialchars($student['student_id']); ?></p>
                        <p><strong>Name:</strong> <?php echo htmlspecialchars($student['first_name'] . ' ' . ($student['middle_name'] ? $student['middle_name'] . ' ' : '') . $student['last_name']); ?></p>
                        <p><strong>Email:</strong> <?php echo htmlspecialchars($student['email']); ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Academic Year:</strong> <?php echo htmlspecialchars($current_cor['academic_year']); ?></p>
                        <p><strong>Semester:</strong> <?php echo htmlspecialchars($current_cor['semester']); ?></p>
                        <p><strong>Year Level:</strong> <?php echo htmlspecialchars($current_cor['year_level']); ?></p>
                        <p><strong>Section:</strong> <?php echo htmlspecialchars($current_cor['section_name']); ?></p>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Current COR Subjects -->
        <div class="card mb-4">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0"><i class="fas fa-list me-2"></i>Current COR Subjects</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered">
                        <thead class="table-light">
                            <tr>
                                <th>Course Code</th>
                                <th>Course Name</th>
                                <th>Units</th>
                                <th>Schedule</th>
                                <th>Room</th>
                                <th>Professor</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($current_subjects as $subject): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($subject['course_code'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars($subject['course_name'] ?? ''); ?></td>
                                    <td><?php echo htmlspecialchars((string)($subject['units'] ?? 0)); ?></td>
                                    <td>
                                        <?php 
                                        $schedule_days = $subject['section_info']['schedule_days'] ?? [];
                                        echo htmlspecialchars(implode(', ', $schedule_days));
                                        if (!empty($subject['section_info']['time_start'])) {
                                            echo ' ' . htmlspecialchars($subject['section_info']['time_start'] . ' - ' . $subject['section_info']['time_end']);
                                        }
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($subject['section_info']['room'] ?? 'TBA'); ?></td>
                                    <td><?php echo htmlspecialchars($subject['section_info']['professor_name'] ?? 'TBA'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-info">
                                <th colspan="2">Total Units</th>
                                <th><?php echo htmlspecialchars((string)($current_cor['total_units'] ?? 0)); ?></th>
                                <th colspan="3"></th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Adjustment Requests -->
        <div class="card mb-4">
            <div class="card-header bg-warning text-dark">
                <h5 class="mb-0"><i class="fas fa-edit me-2"></i>Pending Adjustment Requests</h5>
            </div>
            <div class="card-body">
                <?php foreach ($adjustments as $adj): ?>
                    <div class="card mb-3 subject-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <div>
                                    <span class="badge <?php echo $adj['change_type'] === 'add' ? 'bg-success' : ($adj['change_type'] === 'remove' ? 'bg-danger' : 'bg-primary'); ?> adjustment-badge">
                                        <?php echo ucfirst(str_replace('_', ' ', $adj['change_type'])); ?>
                                    </span>
                                    <h6 class="mt-2 mb-0">
                                        <?php echo htmlspecialchars($adj['course_code'] . ' - ' . $adj['course_name']); ?>
                                    </h6>
                                    <small class="text-muted"><?php echo htmlspecialchars((string)($adj['units'] ?? 0)); ?> Units</small>
                                </div>
                            </div>
                            
                            <?php if ($adj['change_type'] === 'add'): ?>
                                <div class="alert alert-success mb-0">
                                    <strong><i class="fas fa-plus me-2"></i>Adding:</strong><br>
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
                                </div>
                            <?php elseif ($adj['change_type'] === 'remove'): ?>
                                <div class="alert alert-danger mb-0">
                                    <strong><i class="fas fa-minus me-2"></i>Removing:</strong><br>
                                    <strong>Section:</strong> <?php echo htmlspecialchars($adj['old_section_name']); ?><br>
                                    <strong>Schedule:</strong> <?php echo htmlspecialchars($adj['old_time_start'] . ' - ' . $adj['old_time_end']); ?>
                                </div>
                            <?php elseif ($adj['change_type'] === 'schedule_change'): ?>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="alert alert-secondary mb-0">
                                            <strong><i class="fas fa-arrow-left me-2"></i>From:</strong><br>
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
                                            <?php echo htmlspecialchars($adj['old_time_start'] . ' - ' . $adj['old_time_end']); ?>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="alert alert-success mb-0">
                                            <strong><i class="fas fa-arrow-right me-2"></i>To:</strong><br>
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
                                            <?php echo htmlspecialchars($adj['new_time_start'] . ' - ' . $adj['new_time_end']); ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($adj['remarks'])): ?>
                                <div class="mt-2">
                                    <small><strong>Student Remarks:</strong> <?php echo nl2br(htmlspecialchars($adj['remarks'])); ?></small>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- Preview of New COR -->
        <div class="preview-section">
            <h4 class="mb-3"><i class="fas fa-eye me-2"></i>Preview of New COR</h4>
            <div class="table-responsive">
                <table class="table table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Course Code</th>
                            <th>Course Name</th>
                            <th>Units</th>
                            <th>Schedule</th>
                            <th>Room</th>
                            <th>Professor</th>
                            <th>Remarks</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($preview_subjects as $subject): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($subject['course_code'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($subject['course_name'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars((string)($subject['units'] ?? 0)); ?></td>
                                <td>
                                    <?php 
                                    $schedule_days = $subject['section_info']['schedule_days'] ?? [];
                                    echo htmlspecialchars(implode(', ', $schedule_days));
                                    if (!empty($subject['section_info']['time_start'])) {
                                        echo ' ' . htmlspecialchars($subject['section_info']['time_start'] . ' - ' . $subject['section_info']['time_end']);
                                    }
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($subject['section_info']['room'] ?? 'TBA'); ?></td>
                                <td><?php echo htmlspecialchars($subject['section_info']['professor_name'] ?? 'TBA'); ?></td>
                                <td>
                                    <?php 
                                    if (!empty($subject['is_added']) && !empty($subject['remarks'])) {
                                        echo '<span class="badge bg-info text-dark">Added</span><br>';
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
                    </tbody>
                    <tfoot>
                        <tr class="table-info">
                            <th colspan="2">Total Units</th>
                            <th><?php echo htmlspecialchars((string)$preview_total_units); ?></th>
                            <th colspan="3"></th>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        
        <!-- Generate COR Form -->
        <div class="card mt-4">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="fas fa-check-circle me-2"></i>Generate New COR</h5>
            </div>
            <div class="card-body">
                <form method="POST" onsubmit="return confirm('Are you sure you want to generate the new COR with these adjustments? This will update the student\'s current COR and apply all changes.');">
                    <input type="hidden" name="action" value="generate_cor">
                    <div class="mb-3">
                        <label for="remarks" class="form-label">Remarks (Optional)</label>
                        <textarea name="remarks" id="remarks" class="form-control" rows="3" placeholder="Add any remarks about this COR generation...">COR generated with adjustments</textarea>
                    </div>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Note:</strong> Clicking the button below will:
                        <ul class="mb-0 mt-2">
                            <li>Apply all adjustment requests to the student's schedule</li>
                            <li>Update the student's current COR with the new subjects</li>
                            <li>Mark all adjustment requests as approved</li>
                        </ul>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success btn-lg">
                            <i class="fas fa-file-alt me-2"></i>Generate New COR with Adjustments
                        </button>
                        <a href="review_adjustments.php" class="btn btn-secondary btn-lg">
                            <i class="fas fa-times me-2"></i>Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

