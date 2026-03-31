<?php
/**
 * Prerequisites Sync Utility
 * 
 * This script syncs prerequisites from the curriculum table's pre_requisites column
 * to the subject_prerequisites table for proper prerequisite checking during enrollment.
 * 
 * Run this script after:
 * - Importing new curriculum data
 * - Bulk uploading courses
 * - Modifying prerequisite requirements
 */

require_once '../config/database.php';
require_once '../config/session_helper.php';

// Check if admin is logged in
if (!isAdmin()) {
    die("Access denied. Admin privileges required.");
}

$conn = (new Database())->getConnection();

// Check if this is a form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'sync') {
    try {
        $synced_count = 0;
        $error_count = 0;
        $skipped_count = 0;
        $messages = [];
        
        // Get all courses that have pre_requisites defined
        $all_prereq_query = "SELECT id, course_code, course_name, pre_requisites, program_id, year_level, semester
                             FROM curriculum
                             WHERE pre_requisites IS NOT NULL 
                             AND pre_requisites != '' 
                             AND LOWER(pre_requisites) != 'none'
                             ORDER BY program_id, year_level, semester";
        $all_prereq_stmt = $conn->prepare($all_prereq_query);
        $all_prereq_stmt->execute();
        $all_prereq_results = $all_prereq_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($all_prereq_results as $row) {
            $course_code = $row['course_code'];
            $curriculum_id = $row['id'];
            $program_id = $row['program_id'];
            
            // Parse prerequisites
            $prereq_text = trim($row['pre_requisites']);
            
            // Skip invalid formats
            if (strtolower($prereq_text) == 'yes' || strlen($prereq_text) < 2) {
                $messages[] = "Skipped {$course_code}: invalid prerequisite format '{$prereq_text}'";
                $skipped_count++;
                continue;
            }
            
            $prereq_codes = array_map('trim', explode(',', $prereq_text));
            
            foreach ($prereq_codes as $prereq_code) {
                if (empty($prereq_code) || strtolower($prereq_code) == 'none') {
                    continue;
                }
                
                // Find the prerequisite course in the same program
                $prereq_curr_query = "SELECT id FROM curriculum 
                                     WHERE course_code = :course_code 
                                     AND program_id = :program_id 
                                     LIMIT 1";
                $prereq_curr_stmt = $conn->prepare($prereq_curr_query);
                $prereq_curr_stmt->execute([
                    ':course_code' => $prereq_code, 
                    ':program_id' => $program_id
                ]);
                $prereq_curr_result = $prereq_curr_stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$prereq_curr_result) {
                    // Try without space variations
                    $prereq_code_no_space = str_replace(' ', '', $prereq_code);
                    $prereq_curr_stmt2 = $conn->prepare("SELECT id FROM curriculum 
                                                        WHERE REPLACE(course_code, ' ', '') = :course_code 
                                                        AND program_id = :program_id 
                                                        LIMIT 1");
                    $prereq_curr_stmt2->execute([
                        ':course_code' => $prereq_code_no_space, 
                        ':program_id' => $program_id
                    ]);
                    $prereq_curr_result = $prereq_curr_stmt2->fetch(PDO::FETCH_ASSOC);
                }
                
                if (!$prereq_curr_result) {
                    $messages[] = "Warning: Could not find prerequisite '{$prereq_code}' for {$course_code}";
                    $error_count++;
                    continue;
                }
                
                $prereq_curriculum_id = $prereq_curr_result['id'];
                
                // Check if this prerequisite relationship already exists
                $check_query = "SELECT id FROM subject_prerequisites 
                               WHERE curriculum_id = :curriculum_id 
                               AND prerequisite_curriculum_id = :prereq_curriculum_id";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->execute([
                    ':curriculum_id' => $curriculum_id,
                    ':prereq_curriculum_id' => $prereq_curriculum_id
                ]);
                
                if ($check_stmt->rowCount() == 0) {
                    // Insert the prerequisite relationship
                    $insert_query = "INSERT INTO subject_prerequisites 
                                    (curriculum_id, prerequisite_curriculum_id, minimum_grade, is_required)
                                    VALUES (:curriculum_id, :prereq_curriculum_id, 3.00, 1)";
                    $insert_stmt = $conn->prepare($insert_query);
                    $insert_stmt->execute([
                        ':curriculum_id' => $curriculum_id,
                        ':prereq_curriculum_id' => $prereq_curriculum_id
                    ]);
                    
                    $synced_count++;
                }
            }
        }
        
        // Handle NSTP courses specifically
        $nstp2_query = "SELECT id, course_code, program_id 
                       FROM curriculum 
                       WHERE (course_code LIKE '%NSTP%2%' OR course_code = 'NSTP 2' OR course_code = 'NSTP2')";
        $nstp2_stmt = $conn->prepare($nstp2_query);
        $nstp2_stmt->execute();
        $nstp2_courses = $nstp2_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($nstp2_courses as $nstp2) {
            $nstp1_query = "SELECT id FROM curriculum 
                           WHERE (course_code LIKE '%NSTP%1%' OR course_code = 'NSTP 1' OR course_code = 'NSTP1')
                           AND program_id = :program_id
                           LIMIT 1";
            $nstp1_stmt = $conn->prepare($nstp1_query);
            $nstp1_stmt->execute([':program_id' => $nstp2['program_id']]);
            $nstp1_result = $nstp1_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($nstp1_result) {
                $check_query = "SELECT id FROM subject_prerequisites 
                               WHERE curriculum_id = :curriculum_id 
                               AND prerequisite_curriculum_id = :prereq_curriculum_id";
                $check_stmt = $conn->prepare($check_query);
                $check_stmt->execute([
                    ':curriculum_id' => $nstp2['id'],
                    ':prereq_curriculum_id' => $nstp1_result['id']
                ]);
                
                if ($check_stmt->rowCount() == 0) {
                    $insert_query = "INSERT INTO subject_prerequisites 
                                    (curriculum_id, prerequisite_curriculum_id, minimum_grade, is_required)
                                    VALUES (:curriculum_id, :prereq_curriculum_id, 3.00, 1)";
                    $insert_stmt = $conn->prepare($insert_query);
                    $insert_stmt->execute([
                        ':curriculum_id' => $nstp2['id'],
                        ':prereq_curriculum_id' => $nstp1_result['id']
                    ]);
                    
                    $synced_count++;
                }
            }
        }
        
        $total_prereqs = $conn->query("SELECT COUNT(*) FROM subject_prerequisites")->fetchColumn();
        
        $_SESSION['sync_result'] = [
            'success' => true,
            'synced' => $synced_count,
            'errors' => $error_count,
            'skipped' => $skipped_count,
            'total' => $total_prereqs,
            'messages' => $messages
        ];
        
        header('Location: sync_prerequisites.php');
        exit;
        
    } catch (Exception $e) {
        $_SESSION['sync_result'] = [
            'success' => false,
            'error' => $e->getMessage()
        ];
        header('Location: sync_prerequisites.php');
        exit;
    }
}

// Get current statistics
$total_prereqs = $conn->query("SELECT COUNT(*) FROM subject_prerequisites")->fetchColumn();
$total_courses = $conn->query("SELECT COUNT(*) FROM curriculum")->fetchColumn();
$courses_with_prereqs = $conn->query("SELECT COUNT(DISTINCT curriculum_id) FROM subject_prerequisites")->fetchColumn();

$sync_result = $_SESSION['sync_result'] ?? null;
unset($_SESSION['sync_result']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sync Prerequisites - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0"><i class="fas fa-sync-alt me-2"></i>Sync Prerequisites</h4>
                    </div>
                    <div class="card-body">
                        <?php if ($sync_result): ?>
                            <?php if ($sync_result['success']): ?>
                                <div class="alert alert-success">
                                    <h5><i class="fas fa-check-circle me-2"></i>Sync Completed Successfully!</h5>
                                    <ul class="mb-0">
                                        <li>New prerequisites synced: <strong><?php echo $sync_result['synced']; ?></strong></li>
                                        <li>Errors/Warnings: <strong><?php echo $sync_result['errors']; ?></strong></li>
                                        <li>Skipped invalid: <strong><?php echo $sync_result['skipped']; ?></strong></li>
                                        <li>Total prerequisites in system: <strong><?php echo $sync_result['total']; ?></strong></li>
                                    </ul>
                                    <?php if (!empty($sync_result['messages'])): ?>
                                        <hr>
                                        <h6>Details:</h6>
                                        <div class="small" style="max-height: 200px; overflow-y: auto;">
                                            <?php foreach ($sync_result['messages'] as $msg): ?>
                                                <div><?php echo htmlspecialchars($msg); ?></div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-danger">
                                    <h5><i class="fas fa-exclamation-triangle me-2"></i>Sync Failed</h5>
                                    <p class="mb-0"><?php echo htmlspecialchars($sync_result['error']); ?></p>
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        
                        <h5>Current Statistics</h5>
                        <div class="row mb-4">
                            <div class="col-md-4">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <h2 class="text-primary"><?php echo $total_courses; ?></h2>
                                        <p class="mb-0 small">Total Courses</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <h2 class="text-success"><?php echo $courses_with_prereqs; ?></h2>
                                        <p class="mb-0 small">Courses with Prerequisites</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="card bg-light">
                                    <div class="card-body text-center">
                                        <h2 class="text-info"><?php echo $total_prereqs; ?></h2>
                                        <p class="mb-0 small">Total Prerequisites</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info">
                            <h6><i class="fas fa-info-circle me-2"></i>About This Tool</h6>
                            <p class="mb-0 small">
                                This tool syncs prerequisite relationships from the curriculum table's <code>pre_requisites</code> column 
                                to the <code>subject_prerequisites</code> table. This ensures that the system properly checks prerequisites 
                                during student enrollment. Run this after importing new curriculum data or updating prerequisites.
                            </p>
                        </div>
                        
                        <form method="POST" onsubmit="return confirm('Are you sure you want to sync prerequisites? This will add missing prerequisite relationships.');">
                            <input type="hidden" name="action" value="sync">
                            <button type="submit" class="btn btn-primary btn-lg w-100">
                                <i class="fas fa-sync-alt me-2"></i>Sync Prerequisites Now
                            </button>
                        </form>
                        
                        <div class="mt-3 text-center">
                            <a href="dashboard.php" class="btn btn-outline-secondary">
                                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

