<?php
/**
 * One-time synchronization script to update enrolled_students table
 * 
 * This script updates the course, year_level, semester, and academic_year
 * fields in the enrolled_students table based on students' active section assignments.
 * 
 * Run this once to sync existing data, then the system will automatically
 * maintain sync for all future enrollments.
 */

require_once '../config/database.php';
require_once '../config/session_helper.php';

// Check if user is logged in and is an admin
if (!isLoggedIn() || !isAdmin()) {
    redirect('../public/login.php');
}

$db = new Database();
$conn = $db->getConnection();

$message = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['sync'])) {
    try {
        // Update enrolled_students table based on active section assignments
        $update_query = "UPDATE enrolled_students es
                         JOIN (
                             SELECT se.user_id, 
                                    p.program_code as course,
                                    s.year_level, 
                                    s.semester, 
                                    s.academic_year
                             FROM section_enrollments se
                             JOIN sections s ON se.section_id = s.id
                             JOIN programs p ON s.program_id = p.id
                             WHERE se.status = 'active'
                             GROUP BY se.user_id
                         ) section_data ON es.user_id = section_data.user_id
                         SET es.course = section_data.course,
                             es.year_level = section_data.year_level,
                             es.semester = section_data.semester,
                             es.academic_year = section_data.academic_year,
                             es.updated_at = NOW()";
        
        $stmt = $conn->prepare($update_query);
        $stmt->execute();
        $updated_count = $stmt->rowCount();
        
        $message = "Successfully synchronized {$updated_count} student record(s). The enrolled_students table now matches section assignments.";
        $success = true;
        
    } catch (Exception $e) {
        $message = "Error: " . $e->getMessage();
        $success = false;
    }
}

// Get count of students with mismatched data
try {
    $mismatch_query = "SELECT COUNT(*) as mismatch_count
                       FROM enrolled_students es
                       JOIN section_enrollments se ON es.user_id = se.user_id AND se.status = 'active'
                       JOIN sections s ON se.section_id = s.id
                       JOIN programs p ON s.program_id = p.id
                       WHERE es.course != p.program_code 
                          OR es.year_level != s.year_level 
                          OR es.semester != s.semester 
                          OR es.academic_year != s.academic_year
                          OR es.course IS NULL 
                          OR es.semester IS NULL 
                          OR es.academic_year IS NULL";
    
    $mismatch_stmt = $conn->prepare($mismatch_query);
    $mismatch_stmt->execute();
    $mismatch_result = $mismatch_stmt->fetch(PDO::FETCH_ASSOC);
    $mismatch_count = $mismatch_result['mismatch_count'];
    
} catch (Exception $e) {
    $mismatch_count = 0;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sync Enrolled Students Data</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0"><i class="fas fa-sync me-2"></i>Synchronize Enrolled Students Data</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($message): ?>
                            <div class="alert alert-<?php echo $success ? 'success' : 'danger'; ?> alert-dismissible fade show">
                                <?php echo htmlspecialchars($message); ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <div class="alert alert-info">
                            <h5><i class="fas fa-info-circle me-2"></i>What does this do?</h5>
                            <p>This synchronization updates the <code>enrolled_students</code> table to match students' current section assignments.</p>
                            <p class="mb-0"><strong>Updates:</strong> Course/Program, Year Level, Semester, and Academic Year based on assigned sections.</p>
                        </div>
                        
                        <?php if ($mismatch_count > 0): ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong><?php echo $mismatch_count; ?> student record(s)</strong> have mismatched data between enrolled_students table and section assignments.
                            </div>
                        <?php else: ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i>
                                All student records are currently in sync!
                            </div>
                        <?php endif; ?>
                        
                        <div class="mb-4">
                            <h6>How it works:</h6>
                            <ul>
                                <li>Finds all students with active section assignments</li>
                                <li>Updates their enrolled_students record with section data</li>
                                <li>Future enrollments will automatically stay in sync</li>
                            </ul>
                        </div>
                        
                        <form method="POST">
                            <div class="d-grid gap-2">
                                <button type="submit" name="sync" class="btn btn-primary btn-lg">
                                    <i class="fas fa-sync me-2"></i>Synchronize Now
                                </button>
                                <a href="dashboard.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                                </a>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="card shadow mt-3">
                    <div class="card-header bg-secondary text-white">
                        <h6 class="mb-0"><i class="fas fa-question-circle me-2"></i>When to use this</h6>
                    </div>
                    <div class="card-body">
                        <p class="mb-2"><strong>Run this sync if:</strong></p>
                        <ul class="mb-0">
                            <li>You just upgraded the system</li>
                            <li>You notice enrolled_students data doesn't match section assignments</li>
                            <li>You manually assigned sections outside the normal enrollment process</li>
                        </ul>
                        <p class="mt-3 mb-0 text-muted small">
                            <i class="fas fa-lightbulb me-1"></i>
                            <strong>Note:</strong> After running this once, future enrollments will automatically keep data in sync.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

