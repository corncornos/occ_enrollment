<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';

// Check if user is logged in and is an admin
if (!isLoggedIn() || (!isAdmin() && !isRegistrarStaff())) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit;
}

if (!isset($_GET['user_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'User ID is required']);
    exit;
}

$user_id = (int)$_GET['user_id'];

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Get all CORs for this user
    // Use COALESCE to prefer section name from sections table, otherwise use the stored one from COR
    $cor_query = "SELECT cor.*, p.program_code, p.program_name, 
                  COALESCE(s.section_name, cor.section_name) as section_name
                  FROM certificate_of_registration cor
                  JOIN programs p ON cor.program_id = p.id
                  LEFT JOIN sections s ON cor.section_id = s.id
                  WHERE cor.user_id = :user_id
                  ORDER BY cor.created_at DESC";
    $cor_stmt = $conn->prepare($cor_query);
    $cor_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $cor_stmt->execute();
    $cors = $cor_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Decode subjects JSON for each COR
    foreach ($cors as &$cor) {
        if (!empty($cor['subjects_json'])) {
            $cor['subjects'] = json_decode($cor['subjects_json'], true);
        } else {
            $cor['subjects'] = [];
        }
    }
    
    echo json_encode([
        'success' => true,
        'cors' => $cors
    ]);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>

