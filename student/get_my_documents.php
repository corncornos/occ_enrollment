<?php
require_once '../config/database.php';

header('Content-Type: application/json');

// Check if user is logged in and is a student
if (!isLoggedIn() || !isStudent()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$conn = (new Database())->getConnection();

try {
    $stmt = $conn->prepare("SELECT * FROM document_uploads 
                           WHERE user_id = :user_id 
                           ORDER BY upload_date DESC");
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    $stmt->execute();
    
    $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'documents' => $documents
    ]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error']);
}
?>







