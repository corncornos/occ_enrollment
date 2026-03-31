<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/Curriculum.php';

// Check if admin is logged in
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$db = new Database();
$conn = $db->getConnection();
$curriculum = new Curriculum();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $submission_id = (int)$_POST['submission_id'];
    $action = $_POST['action'];
    $admin_id = $_SESSION['user_id'];

    try {
        $conn->beginTransaction();

        if ($action == 'approve') {
            // Get submission details
            $stmt = $conn->prepare("SELECT * FROM curriculum_submissions WHERE id = :submission_id AND status = 'submitted'");
            $stmt->bindParam(':submission_id', $submission_id);
            $stmt->execute();

            if ($stmt->rowCount() == 0) {
                throw new Exception('Submission not found or not in submitted status');
            }

            $submission = $stmt->fetch(PDO::FETCH_ASSOC);

            // Get submission items
            $stmt = $conn->prepare("SELECT * FROM curriculum_submission_items WHERE submission_id = :submission_id");
            $stmt->bindParam(':submission_id', $submission_id);
            $stmt->execute();
            $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Batch check for existing courses (optimization: single query instead of N queries)
            $existing_courses = [];
            $existing_stmt = $conn->prepare("SELECT course_code, year_level, semester 
                                            FROM curriculum 
                                            WHERE program_id = :program_id");
            $existing_stmt->execute([':program_id' => $submission['program_id']]);
            $existing_results = $existing_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($existing_results as $existing) {
                $key = $existing['course_code'] . '|' . $existing['year_level'] . '|' . $existing['semester'];
                $existing_courses[$key] = true;
            }

            // Filter out existing items and prepare batch insert
            $new_items = [];
            $added_count = 0;
            $skipped_count = 0;

            foreach ($items as $item) {
                $key = $item['course_code'] . '|' . $item['year_level'] . '|' . $item['semester'];
                if (isset($existing_courses[$key])) {
                    $skipped_count++;
                } else {
                    $new_items[] = $item;
                    $existing_courses[$key] = true; // Mark as added to prevent duplicates in same batch
                }
            }

            // Batch insert new items using prepared statement (optimization: reuse prepared statement)
            if (!empty($new_items)) {
                $insert_stmt = $conn->prepare("INSERT INTO curriculum
                    (program_id, course_code, course_name, units, year_level, semester, is_required, pre_requisites)
                    VALUES (:program_id, :course_code, :course_name, :units, :year_level, :semester, :is_required, :pre_requisites)");
                
                foreach ($new_items as $item) {
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
                    $added_count++;
                }
            }

            // Update submission status
            $update_stmt = $conn->prepare("UPDATE curriculum_submissions
                SET status = 'approved', reviewed_by = :admin_id, reviewed_at = NOW(), reviewer_comments = 'Approved and added to curriculum'
                WHERE id = :submission_id");
            $update_stmt->execute([
                ':admin_id' => $admin_id,
                ':submission_id' => $submission_id
            ]);

            // Mark submission items as added
            $update_items_stmt = $conn->prepare("UPDATE curriculum_submission_items SET status = 'added' WHERE submission_id = :submission_id");
            $update_items_stmt->execute([':submission_id' => $submission_id]);

            // Update program total units BEFORE commit (use same connection for transaction)
            if ($added_count > 0) {
                $curriculum->updateProgramTotalUnits($submission['program_id'], $conn);
            }
            
            $conn->commit();
            $message = "Curriculum submission approved successfully! Added $added_count new subjects to curriculum";
            if ($skipped_count > 0) {
                $message .= ", skipped $skipped_count existing subjects";
            }
            echo json_encode([
                'success' => true,
                'message' => $message,
                'added_count' => $added_count,
                'skipped_count' => $skipped_count
            ]);

        } elseif ($action == 'reject') {
            $reason = sanitizeInput($_POST['reason']);

            $stmt = $conn->prepare("UPDATE curriculum_submissions
                SET status = 'rejected', reviewed_by = :admin_id, reviewed_at = NOW(), reviewer_comments = :reason
                WHERE id = :submission_id AND status = 'submitted'");
            $stmt->execute([
                ':admin_id' => $admin_id,
                ':submission_id' => $submission_id,
                ':reason' => $reason
            ]);

            if ($stmt->rowCount() > 0) {
                $conn->commit();
                echo json_encode(['success' => true, 'message' => 'Curriculum submission rejected']);
            } else {
                throw new Exception('Submission not found or not in submitted status');
            }

        } else {
            throw new Exception('Invalid action');
        }

    } catch (Exception $e) {
        $conn->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
}
?>
