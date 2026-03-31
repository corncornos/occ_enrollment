<?php
declare(strict_types=1);

require_once '../config/database.php';
require_once '../config/session_helper.php';

// Check if admin is logged in
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

$submission_id = isset($_GET['submission_id']) ? (int)$_GET['submission_id'] : 0;

if ($submission_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid submission ID']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Get submission logs with performer information
    $logs_query = "SELECT csl.*,
                          CASE 
                              WHEN csl.role = 'program_head' THEN CONCAT(ph.first_name, ' ', ph.last_name)
                              WHEN csl.role = 'dean' THEN CONCAT(a.first_name, ' ', a.last_name)
                              WHEN csl.role = 'admin' THEN CONCAT(a.first_name, ' ', a.last_name)
                              ELSE 'Unknown'
                          END as performer_name
                   FROM curriculum_submission_logs csl
                   LEFT JOIN program_heads ph ON csl.role = 'program_head' AND csl.performed_by = ph.id
                   LEFT JOIN admins a ON (csl.role = 'dean' OR csl.role = 'admin') AND csl.performed_by = a.id
                   WHERE csl.submission_id = :submission_id
                   ORDER BY csl.created_at ASC";
    
    $logs_stmt = $conn->prepare($logs_query);
    $logs_stmt->bindParam(':submission_id', $submission_id, PDO::PARAM_INT);
    $logs_stmt->execute();
    $logs = $logs_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format dates
    foreach ($logs as &$log) {
        $log['created_at'] = date('F j, Y g:i A', strtotime($log['created_at']));
    }
    unset($log);
    
    echo json_encode([
        'success' => true,
        'logs' => $logs
    ]);
    
} catch (Exception $e) {
    error_log('Error in get_submission_history.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while fetching submission history'
    ]);
}
?>

