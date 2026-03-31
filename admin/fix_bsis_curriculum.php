<?php
/**
 * Fix BSIS Curriculum - Diagnostic and Sync Tool
 * 
 * This script:
 * 1. Checks if approved BSIS curriculum submissions have been added to curriculum table
 * 2. Syncs any missing subjects
 * 3. Shows what's in the curriculum vs what should be there
 */

require_once '../config/database.php';
require_once '../config/session_helper.php';

// Check if admin is logged in
if (!isLoggedIn() || !isAdmin()) {
    die('Unauthorized access');
}

$db = new Database();
$conn = $db->getConnection();

// Get BSIS program ID
$program_query = "SELECT id, program_code, program_name FROM programs WHERE program_code = 'BSIS'";
$program_stmt = $conn->prepare($program_query);
$program_stmt->execute();
$bsis_program = $program_stmt->fetch(PDO::FETCH_ASSOC);

if (!$bsis_program) {
    die('BSIS program not found in database');
}

$bsis_program_id = $bsis_program['id'];

echo "<h2>BSIS Curriculum Diagnostic & Sync Tool</h2>";
echo "<p><strong>Program:</strong> {$bsis_program['program_code']} - {$bsis_program['program_name']}</p>";
echo "<hr>";

try {
    // Get all approved BSIS curriculum submissions
    $submissions_query = "SELECT * FROM curriculum_submissions 
                         WHERE program_id = :program_id 
                         AND status = 'approved' 
                         ORDER BY id DESC";
    $submissions_stmt = $conn->prepare($submissions_query);
    $submissions_stmt->bindParam(':program_id', $bsis_program_id);
    $submissions_stmt->execute();
    $submissions = $submissions_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($submissions)) {
        echo "<p>No approved BSIS curriculum submissions found.</p>";
        exit;
    }
    
    echo "<h3>Approved Submissions Found: " . count($submissions) . "</h3>";
    
    $total_missing = 0;
    $total_added = 0;
    $all_missing_items = [];
    
    foreach ($submissions as $submission) {
        echo "<h4>Submission #{$submission['id']}: {$submission['submission_title']}</h4>";
        echo "<p><strong>Status:</strong> {$submission['status']}</p>";
        echo "<p><strong>Semester:</strong> {$submission['semester']}</p>";
        
        // Get all items for this submission
        $items_query = "SELECT * FROM curriculum_submission_items 
                       WHERE submission_id = :submission_id
                       ORDER BY year_level, semester, course_code";
        $items_stmt = $conn->prepare($items_query);
        $items_stmt->bindParam(':submission_id', $submission['id']);
        $items_stmt->execute();
        $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<p><strong>Total Items:</strong> " . count($items) . "</p>";
        
        $missing_items = [];
        $existing_items = [];
        
        foreach ($items as $item) {
            // Check if course exists in curriculum table with correct program, year, and semester
            $check_query = "SELECT id FROM curriculum 
                           WHERE program_id = :program_id 
                           AND course_code = :course_code
                           AND year_level = :year_level
                           AND semester = :semester";
            $check_stmt = $conn->prepare($check_query);
            $check_stmt->execute([
                ':program_id' => $bsis_program_id,
                ':course_code' => $item['course_code'],
                ':year_level' => $item['year_level'],
                ':semester' => $item['semester']
            ]);
            
            if ($check_stmt->rowCount() == 0) {
                $missing_items[] = $item;
                $all_missing_items[] = [
                    'submission_id' => $submission['id'],
                    'item' => $item
                ];
            } else {
                $existing_items[] = $item;
            }
        }
        
        echo "<p><strong>Missing from Curriculum:</strong> " . count($missing_items) . "</p>";
        echo "<p><strong>Already in Curriculum:</strong> " . count($existing_items) . "</p>";
        
        if (!empty($missing_items)) {
            echo "<h5>Missing Items:</h5>";
            echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
            echo "<tr><th>Course Code</th><th>Course Name</th><th>Year Level</th><th>Semester</th><th>Units</th></tr>";
            foreach ($missing_items as $missing) {
                echo "<tr>";
                echo "<td>{$missing['course_code']}</td>";
                echo "<td>{$missing['course_name']}</td>";
                echo "<td>{$missing['year_level']}</td>";
                echo "<td>{$missing['semester']}</td>";
                echo "<td>{$missing['units']}</td>";
                echo "</tr>";
            }
            echo "</table>";
            $total_missing += count($missing_items);
        }
        
        echo "<hr>";
    }
    
    // Sync missing items
    if (!empty($all_missing_items) && isset($_GET['sync']) && $_GET['sync'] == '1') {
        echo "<h3>Syncing Missing Items to Curriculum Table...</h3>";
        
        $conn->beginTransaction();
        
        try {
            foreach ($all_missing_items as $entry) {
                $item = $entry['item'];
                $submission_id = $entry['submission_id'];
                
                // Get submission to get program_id
                $sub_stmt = $conn->prepare("SELECT program_id FROM curriculum_submissions WHERE id = :id");
                $sub_stmt->bindParam(':id', $submission_id);
                $sub_stmt->execute();
                $sub = $sub_stmt->fetch(PDO::FETCH_ASSOC);
                
                // Insert missing course
                $insert_query = "INSERT INTO curriculum
                    (program_id, course_code, course_name, units, year_level, semester, is_required, pre_requisites)
                    VALUES (:program_id, :course_code, :course_name, :units, :year_level, :semester, :is_required, :pre_requisites)";
                $insert_stmt = $conn->prepare($insert_query);
                $insert_stmt->execute([
                    ':program_id' => $sub['program_id'],
                    ':course_code' => $item['course_code'],
                    ':course_name' => $item['course_name'],
                    ':units' => $item['units'],
                    ':year_level' => $item['year_level'],
                    ':semester' => $item['semester'],
                    ':is_required' => $item['is_required'],
                    ':pre_requisites' => $item['pre_requisites']
                ]);
                $total_added++;
            }
            
            $conn->commit();
            echo "<p style='color: green;'><strong>Successfully added $total_added subjects to curriculum table!</strong></p>";
            echo "<p><a href='?'>Refresh to verify</a></p>";
            
        } catch (Exception $e) {
            $conn->rollBack();
            echo "<p style='color: red;'>Error syncing: " . $e->getMessage() . "</p>";
        }
        
    } elseif (!empty($all_missing_items)) {
        echo "<h3>Action Required</h3>";
        echo "<p><strong>Found $total_missing missing subjects</strong> that need to be added to the curriculum table.</p>";
        echo "<p><a href='?sync=1' style='background: #28a745; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Sync Missing Subjects to Curriculum</a></p>";
    } else {
        echo "<p style='color: green;'><strong>✓ All approved submission items are in the curriculum table.</strong></p>";
    }
    
    // Show current curriculum count by year/semester
    echo "<hr>";
    echo "<h3>Current Curriculum Table Status</h3>";
    $curriculum_query = "SELECT year_level, semester, COUNT(*) as count 
                        FROM curriculum 
                        WHERE program_id = :program_id 
                        GROUP BY year_level, semester 
                        ORDER BY 
                            CASE year_level
                                WHEN '1st Year' THEN 1
                                WHEN '2nd Year' THEN 2
                                WHEN '3rd Year' THEN 3
                                WHEN '4th Year' THEN 4
                                WHEN '5th Year' THEN 5
                            END,
                            CASE semester
                                WHEN 'First Semester' THEN 1
                                WHEN 'Second Semester' THEN 2
                                WHEN 'Summer' THEN 3
                            END";
    $curriculum_stmt = $conn->prepare($curriculum_query);
    $curriculum_stmt->bindParam(':program_id', $bsis_program_id);
    $curriculum_stmt->execute();
    $curriculum_stats = $curriculum_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($curriculum_stats)) {
        echo "<table border='1' cellpadding='5' style='border-collapse: collapse;'>";
        echo "<tr><th>Year Level</th><th>Semester</th><th>Subject Count</th></tr>";
        foreach ($curriculum_stats as $stat) {
            echo "<tr>";
            echo "<td>{$stat['year_level']}</td>";
            echo "<td>{$stat['semester']}</td>";
            echo "<td>{$stat['count']}</td>";
            echo "</tr>";
        }
        echo "</table>";
    } else {
        echo "<p>No subjects found in curriculum table for BSIS.</p>";
    }
    
    echo "<hr>";
    echo "<p><a href='dashboard.php'>Back to Dashboard</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>Error: " . $e->getMessage() . "</p>";
}
?>

