<?php
/**
 * Test script to verify irregular student tagging
 * Access: admin/test_irregular_tagging.php
 */

require_once '../config/database.php';
require_once '../config/session_helper.php';

if (!isAdmin()) {
    die("Admin access required");
}

$conn = (new Database())->getConnection();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Irregular Student Tagging Test - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body { background: #f8f9fa; padding: 20px; }
        .test-section { background: white; padding: 20px; margin-bottom: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        .stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 15px; border-radius: 8px; margin-bottom: 15px; }
        .irregular-badge { background: #ffc107; color: #000; padding: 3px 8px; border-radius: 4px; font-size: 0.85em; }
        .regular-badge { background: #28a745; color: #fff; padding: 3px 8px; border-radius: 4px; font-size: 0.85em; }
        .transferee-badge { background: #17a2b8; color: #fff; padding: 3px 8px; border-radius: 4px; font-size: 0.85em; }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row mb-4">
            <div class="col">
                <h1><i class="fas fa-vial me-2"></i>Irregular Student Tagging Test</h1>
                <p class="text-muted">Verify that students are correctly tagged as Regular/Irregular/Transferee</p>
                <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-arrow-left me-2"></i>Back to Dashboard</a>
            </div>
        </div>

        <?php
        // Get all enrolled students with their type
        $students_query = "SELECT 
                            es.student_id,
                            es.first_name,
                            es.last_name,
                            es.student_type,
                            es.year_level,
                            es.semester,
                            es.academic_year,
                            es.course,
                            COUNT(DISTINCT ss.id) as enrolled_subjects_count
                        FROM enrolled_students es
                        LEFT JOIN student_schedules ss ON es.user_id = ss.user_id AND ss.status = 'active'
                        GROUP BY es.id
                        ORDER BY es.student_type, es.student_id";
        $students_stmt = $conn->prepare($students_query);
        $students_stmt->execute();
        $students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

        $regular_count = 0;
        $irregular_count = 0;
        $transferee_count = 0;

        foreach ($students as $student) {
            switch ($student['student_type']) {
                case 'Regular': $regular_count++; break;
                case 'Irregular': $irregular_count++; break;
                case 'Transferee': $transferee_count++; break;
            }
        }

        $total = $regular_count + $irregular_count + $transferee_count;
        ?>

        <!-- Summary Statistics -->
        <div class="row">
            <div class="col-md-3">
                <div class="stat-card">
                    <h2><i class="fas fa-check-circle"></i> <?php echo $regular_count; ?></h2>
                    <p class="mb-0">Regular Students</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                    <h2><i class="fas fa-exclamation-triangle"></i> <?php echo $irregular_count; ?></h2>
                    <p class="mb-0">Irregular Students</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                    <h2><i class="fas fa-exchange-alt"></i> <?php echo $transferee_count; ?></h2>
                    <p class="mb-0">Transferees</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                    <h2><i class="fas fa-users"></i> <?php echo $total; ?></h2>
                    <p class="mb-0">Total Students</p>
                </div>
            </div>
        </div>

        <!-- All Students Table -->
        <div class="test-section">
            <h3><i class="fas fa-list me-2"></i>All Enrolled Students</h3>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead class="table-dark">
                        <tr>
                            <th>Icon</th>
                            <th>Student ID</th>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Year Level</th>
                            <th>Semester</th>
                            <th>Enrolled Subjects</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $student): ?>
                            <?php
                            $type_icon = '';
                            $type_badge_class = '';
                            switch ($student['student_type']) {
                                case 'Regular':
                                    $type_icon = '<i class="fas fa-check-circle text-success"></i>';
                                    $type_badge_class = 'regular-badge';
                                    break;
                                case 'Irregular':
                                    $type_icon = '<i class="fas fa-exclamation-triangle text-warning"></i>';
                                    $type_badge_class = 'irregular-badge';
                                    break;
                                case 'Transferee':
                                    $type_icon = '<i class="fas fa-exchange-alt text-info"></i>';
                                    $type_badge_class = 'transferee-badge';
                                    break;
                            }
                            ?>
                            <tr>
                                <td><?php echo $type_icon; ?></td>
                                <td><strong><?php echo htmlspecialchars($student['student_id']); ?></strong></td>
                                <td><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                                <td><span class="<?php echo $type_badge_class; ?>"><?php echo htmlspecialchars($student['student_type']); ?></span></td>
                                <td><?php echo htmlspecialchars($student['year_level']); ?></td>
                                <td><?php echo htmlspecialchars($student['semester']); ?></td>
                                <td><?php echo $student['enrolled_subjects_count']; ?> subjects</td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($irregular_count > 0): ?>
        <!-- Irregular Students Details -->
        <div class="test-section">
            <h3><i class="fas fa-exclamation-triangle text-warning me-2"></i>Irregular Students - Detailed Analysis</h3>
            <?php
            $irregular_details_query = "SELECT 
                                        es.student_id,
                                        es.first_name,
                                        es.last_name,
                                        es.year_level,
                                        es.semester,
                                        es.course,
                                        p.id as program_id,
                                        es.user_id
                                    FROM enrolled_students es
                                    LEFT JOIN section_enrollments se ON es.user_id = se.user_id AND se.status = 'active'
                                    LEFT JOIN sections s ON se.section_id = s.id
                                    LEFT JOIN programs p ON s.program_id = p.id
                                    WHERE es.student_type = 'Irregular'
                                    ORDER BY es.student_id";
            $irregular_stmt = $conn->prepare($irregular_details_query);
            $irregular_stmt->execute();
            $irregular_students = $irregular_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($irregular_students as $student):
            ?>
                <div class="card mb-3">
                    <div class="card-header bg-warning">
                        <h5 class="mb-0">
                            <i class="fas fa-user"></i> 
                            <?php echo htmlspecialchars($student['student_id']); ?> - 
                            <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <p><strong>Program:</strong> <?php echo htmlspecialchars($student['course']); ?></p>
                        <p><strong>Year Level:</strong> <?php echo htmlspecialchars($student['year_level']); ?></p>
                        <p><strong>Semester:</strong> <?php echo htmlspecialchars($student['semester']); ?></p>
                        
                        <?php if ($student['program_id']): ?>
                            <?php
                            // Get required subjects
                            $required_query = "SELECT course_code, course_name 
                                              FROM curriculum 
                                              WHERE program_id = :program_id 
                                              AND year_level = :year_level 
                                              AND semester = :semester
                                              AND is_required = 1
                                              ORDER BY course_code";
                            $required_stmt = $conn->prepare($required_query);
                            $required_stmt->execute([
                                ':program_id' => $student['program_id'],
                                ':year_level' => $student['year_level'],
                                ':semester' => $student['semester']
                            ]);
                            $required_subjects = $required_stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            // Get enrolled subjects
                            $enrolled_query = "SELECT course_code, course_name 
                                              FROM student_schedules 
                                              WHERE user_id = :user_id 
                                              AND status = 'active'
                                              ORDER BY course_code";
                            $enrolled_stmt = $conn->prepare($enrolled_query);
                            $enrolled_stmt->execute([':user_id' => $student['user_id']]);
                            $enrolled_subjects = $enrolled_stmt->fetchAll(PDO::FETCH_ASSOC);
                            
                            $enrolled_codes = array_column($enrolled_subjects, 'course_code');
                            $missing = [];
                            
                            foreach ($required_subjects as $req) {
                                if (!in_array($req['course_code'], $enrolled_codes)) {
                                    $missing[] = $req;
                                }
                            }
                            ?>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <h6>Required Subjects: <?php echo count($required_subjects); ?></h6>
                                    <ul class="list-group list-group-flush">
                                        <?php foreach ($required_subjects as $req): ?>
                                            <li class="list-group-item">
                                                <?php
                                                if (in_array($req['course_code'], $enrolled_codes)) {
                                                    echo '<i class="fas fa-check-circle text-success"></i> ';
                                                } else {
                                                    echo '<i class="fas fa-times-circle text-danger"></i> ';
                                                }
                                                echo htmlspecialchars($req['course_code'] . ' - ' . $req['course_name']);
                                                ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                                <div class="col-md-6">
                                    <h6>Missing Required Subjects: <?php echo count($missing); ?></h6>
                                    <?php if (!empty($missing)): ?>
                                        <div class="alert alert-danger">
                                            <ul class="mb-0">
                                                <?php foreach ($missing as $miss): ?>
                                                    <li><?php echo htmlspecialchars($miss['course_code'] . ' - ' . $miss['course_name']); ?></li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Statistics by Program -->
        <div class="test-section">
            <h3><i class="fas fa-chart-bar me-2"></i>Statistics by Program</h3>
            <?php
            $stats_query = "SELECT 
                            course as program,
                            student_type,
                            COUNT(*) as count
                        FROM enrolled_students
                        GROUP BY course, student_type
                        ORDER BY course, student_type";
            $stats_stmt = $conn->prepare($stats_query);
            $stats_stmt->execute();
            $stats = $stats_stmt->fetchAll(PDO::FETCH_ASSOC);

            $programs = [];
            foreach ($stats as $stat) {
                $program = $stat['program'];
                if (!isset($programs[$program])) {
                    $programs[$program] = ['Regular' => 0, 'Irregular' => 0, 'Transferee' => 0];
                }
                $programs[$program][$stat['student_type']] = $stat['count'];
            }
            ?>
            <div class="table-responsive">
                <table class="table table-striped">
                    <thead class="table-dark">
                        <tr>
                            <th>Program</th>
                            <th>Regular</th>
                            <th>Irregular</th>
                            <th>Transferee</th>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($programs as $program => $counts): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($program); ?></strong></td>
                                <td><span class="regular-badge"><?php echo $counts['Regular']; ?></span></td>
                                <td><span class="irregular-badge"><?php echo $counts['Irregular']; ?></span></td>
                                <td><span class="transferee-badge"><?php echo $counts['Transferee']; ?></span></td>
                                <td><strong><?php echo array_sum($counts); ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

