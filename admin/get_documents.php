<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';

// Check if user is logged in and is an admin
if (!isLoggedIn() || (!isAdmin() && !isRegistrarStaff())) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!isset($_GET['user_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'User ID required']);
    exit;
}

$user_id = $_GET['user_id'];

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $sql = "SELECT * FROM document_checklists WHERE user_id = :user_id";
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    
    $checklist = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // If no checklist exists, create one
    if (!$checklist) {
        $create_sql = "INSERT INTO document_checklists (user_id) VALUES (:user_id)";
        $create_stmt = $conn->prepare($create_sql);
        $create_stmt->bindParam(':user_id', $user_id);
        $create_stmt->execute();
        
        // Fetch the newly created checklist
        $stmt->execute();
        $checklist = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    header('Content-Type: application/json');
    echo json_encode($checklist);
    
} catch(PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>

