<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';

// Check if user is logged in and is an admin
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

try {
    $conn = (new Database())->getConnection();

    // Get counts of active enrollments per section with capacity
    $sql = "SELECT s.id as section_id,
                   s.max_capacity,
                   COALESCE(SUM(CASE WHEN se.status = 'active' THEN 1 ELSE 0 END), 0) AS current_enrolled
            FROM sections s
            LEFT JOIN section_enrollments se ON se.section_id = s.id
            GROUP BY s.id, s.max_capacity";

    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['success' => true, 'data' => $rows]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>


