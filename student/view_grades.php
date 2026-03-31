<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';

if (!isLoggedIn()) {
    redirect('public/login.php');
}

// Check enrollment status from database (not session)
$conn = (new Database())->getConnection();
$user_id = $_SESSION['user_id'];

// Check if student is enrolled (check both users.enrollment_status and enrolled_students table)
$enrollment_check = "SELECT u.enrollment_status, 
                     (SELECT COUNT(*) FROM enrolled_students es WHERE es.user_id = u.id) as has_enrollment_record
                     FROM users u 
                     WHERE u.id = :user_id";
$enrollment_stmt = $conn->prepare($enrollment_check);
$enrollment_stmt->bindParam(':user_id', $user_id);
$enrollment_stmt->execute();
$enrollment_data = $enrollment_stmt->fetch(PDO::FETCH_ASSOC);

// Student is considered enrolled if enrollment_status is 'enrolled' OR has a record in enrolled_students table
$is_enrolled = ($enrollment_data && (
    $enrollment_data['enrollment_status'] === 'enrolled' || 
    $enrollment_data['has_enrollment_record'] > 0
));

if (!$is_enrolled) {
    $_SESSION['message'] = 'Only enrolled students can view grades.';
    redirect('student/dashboard.php');
}

// Get student's grades (using curriculum table)
$grades_query = "SELECT sg.*, 
                 c.course_code as subject_code, 
                 c.course_name as subject_name, 
                 c.units,
                 sg.grade, sg.grade_letter, sg.status,
                 gs.grade_description, gs.is_passing
                 FROM student_grades sg
                 JOIN curriculum c ON sg.curriculum_id = c.id
                 LEFT JOIN grade_scale gs ON sg.grade = gs.grade_numeric
                 WHERE sg.user_id = :user_id
                 AND sg.status IN ('verified', 'finalized')
                 ORDER BY sg.academic_year DESC, sg.semester DESC, c.course_code";

$grades_stmt = $conn->prepare($grades_query);
$grades_stmt->bindParam(':user_id', $user_id);
$grades_stmt->execute();
$all_grades = $grades_stmt->fetchAll(PDO::FETCH_ASSOC);

// Group grades by academic year and semester
$grades_by_term = [];
foreach ($all_grades as $grade) {
    $key = $grade['academic_year'] . ' - ' . $grade['semester'];
    if (!isset($grades_by_term[$key])) {
        $grades_by_term[$key] = [];
    }
    $grades_by_term[$key][] = $grade;
}

// Calculate GPA for each term
function calculateGPA($grades) {
    $total_grade_points = 0;
    $total_units = 0;
    foreach ($grades as $grade) {
        if ($grade['is_passing']) {
            $total_grade_points += $grade['grade'] * $grade['units'];
            $total_units += $grade['units'];
        }
    }
    return $total_units > 0 ? round($total_grade_points / $total_units, 2) : 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Grades - Student Portal</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px 0;
        }
        .card {
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            margin-bottom: 20px;
        }
        .grade-excellent { background-color: #d4edda; }
        .grade-good { background-color: #d1ecf1; }
        .grade-passing { background-color: #fff3cd; }
        .grade-failed { background-color: #f8d7da; }
    </style>
</head>
<body>
    <div class="container">
        <div class="row mb-3">
            <div class="col">
                <a href="dashboard.php" class="btn btn-light">
                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                </a>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0"><i class="fas fa-chart-line me-2"></i>My Grades</h4>
            </div>
            <div class="card-body">
                <?php if (empty($grades_by_term)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        No grades available yet. Grades will appear here once they are verified by your instructors and admin.
                    </div>
                <?php else: ?>
                    <?php foreach ($grades_by_term as $term => $grades): ?>
                        <div class="card mb-4">
                            <div class="card-header bg-light">
                                <div class="row align-items-center">
                                    <div class="col">
                                        <h5 class="mb-0"><?php echo htmlspecialchars($term); ?></h5>
                                    </div>
                                    <div class="col-auto">
                                        <span class="badge bg-primary">GPA: <?php echo calculateGPA($grades); ?></span>
                                    </div>
                                </div>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Subject Code</th>
                                                <th>Subject Name</th>
                                                <th>Units</th>
                                                <th>Grade</th>
                                                <th>Remarks</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($grades as $grade): ?>
                                                <tr class="<?php 
                                                    if ($grade['grade'] <= 1.5) echo 'grade-excellent';
                                                    elseif ($grade['grade'] <= 2.5) echo 'grade-good';
                                                    elseif ($grade['grade'] <= 3.0) echo 'grade-passing';
                                                    else echo 'grade-failed';
                                                ?>">
                                                    <td><strong><?php echo htmlspecialchars($grade['subject_code']); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($grade['subject_name']); ?></td>
                                                    <td><?php echo $grade['units']; ?></td>
                                                    <td>
                                                        <strong><?php echo $grade['grade_letter']; ?></strong>
                                                        <small class="text-muted">(<?php echo $grade['grade']; ?>)</small>
                                                    </td>
                                                    <td>
                                                        <?php if ($grade['is_passing']): ?>
                                                            <span class="badge bg-success">Passed</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-danger">Failed</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

