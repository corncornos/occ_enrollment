<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';

header('Content-Type: application/json');

// Check if user is logged in and is an admin
if (!isLoggedIn() || (!isAdmin() && !isRegistrarStaff())) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access.'
    ]);
    exit;
}

$user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
if ($user_id <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid user ID.'
    ]);
    exit;
}

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $query = "SELECT id, document_type, file_name, file_path, file_size, upload_date, verification_status, verified_by, verified_at, rejection_reason 
              FROM document_uploads 
              WHERE user_id = :user_id 
              ORDER BY upload_date DESC";
    $stmt = $conn->prepare($query);
    $stmt->bindValue(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'documents' => $documents
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>

