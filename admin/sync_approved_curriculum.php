<?php
/**
 * Sync Approved Curriculum Submissions to Curriculum Table
 * 
 * This script checks approved curriculum submissions and ensures
 * all subjects are properly added to the curriculum table.
 * 
 * Run this script to fix missing curriculum subjects from approved submissions.
 */

require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/Curriculum.php';

// Check if admin is logged in
if (!isLoggedIn() || !isAdmin()) {
    die('Unauthorized access');
}

$db = new Database();
$conn = $db->getConnection();
$curriculum = new Curriculum();

try {
    // Get all approved curriculum submissions
    $submissions_query = "SELECT * FROM curriculum_submissions 
                         WHERE status = 'approved' 
                         ORDER BY id DESC";
    $submissions_stmt = $conn->prepare($submissions_query);
    $submissions_stmt->execute();
    $submissions = $submissions_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total_added = 0;
    $total_skipped = 0;
    $results = [];
    
    foreach ($submissions as $submission) {
        // Get all items for this submission
        $items_query = "SELECT * FROM curriculum_submission_items 
                       WHERE submission_id = :submission_id";
        $items_stmt = $conn->prepare($items_query);
        $items_stmt->bindParam(':submission_id', $submission['id']);
        $items_stmt->execute();
        $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $submission_added = 0;
        $submission_skipped = 0;
        
        foreach ($items as $item) {
            // Check if course already exists for this program, year_level, and semester
            // This is the correct check - includes year_level and semester
            $check_query = "SELECT id FROM curriculum 
                           WHERE program_id = :program_id 
                           AND course_code = :course_code
                           AND year_level = :year_level
                           AND semester = :semester";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->execute([
                ':program_id' => $submission['program_id'],
                ':course_code' => $item['course_code'],
                ':year_level' => $item['year_level'],
                ':semester' => $item['semester']
            ]);
            
            if ($check_stmt->rowCount() == 0) {
                // Insert new course
                $insert_query = "INSERT INTO curriculum
                    (program_id, course_code, course_name, units, year_level, semester, is_required, pre_requisites)
                    VALUES (:program_id, :course_code, :course_name, :units, :year_level, :semester, :is_required, :pre_requisites)";
                $insert_stmt = $conn->prepare($insert_query);
                $insert_stmt->execute([
                    ':program_id' => $submission['program_id'],
                    ':course_code' => $item['course_code'],
                    ':course_name' => $item['course_name'],
                    ':units' => $item['units'],
                    ':year_level' => $item['year_level'],
                    ':semester' => $item['semester'],
                    ':is_required' => $item['is_required'],
                    ':pre_requisites' => $item['pre_requisites']
                ]);
                $submission_added++;
                $total_added++;
            } else {
                $submission_skipped++;
                $total_skipped++;
            }
        }
        
        if ($submission_added > 0) {
            // Update program total units after syncing curriculum (use same connection)
            $curriculum->updateProgramTotalUnits($submission['program_id'], $conn);
            
            $results[] = [
                'submission_id' => $submission['id'],
                'submission_title' => $submission['submission_title'],
                'added' => $submission_added,
                'skipped' => $submission_skipped
            ];
        }
    }
    
    // Output results
    echo "<h2>Curriculum Sync Results</h2>";
    echo "<p><strong>Total Added:</strong> $total_added subjects</p>";
    echo "<p><strong>Total Skipped:</strong> $total_skipped subjects (already exist)</p>";
    
    if (!empty($results)) {
        echo "<h3>Submissions Processed:</h3>";
        echo "<ul>";
        foreach ($results as $result) {
            echo "<li>Submission #{$result['submission_id']}: {$result['submission_title']} - Added {$result['added']} subjects, Skipped {$result['skipped']}</li>";
        }
        echo "</ul>";
    } else {
        echo "<p>All approved submissions are already synced to curriculum table.</p>";
    }
    
    echo "<hr>";
    echo "<p><a href='dashboard.php'>Back to Dashboard</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>

