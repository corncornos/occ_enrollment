<?php
require_once '../config/database.php';

header('Content-Type: application/json');

// Check if user is logged in and is a student
if (!isLoggedIn() || !isStudent()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

// Check if user is pending
$conn = (new Database())->getConnection();
$stmt = $conn->prepare("SELECT enrollment_status FROM users WHERE id = :user_id");
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || $user['enrollment_status'] !== 'pending') {
    echo json_encode(['success' => false, 'message' => 'Only pending students can upload documents']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$document_type = $_POST['document_type'] ?? '';

if (empty($document_type)) {
    echo json_encode(['success' => false, 'message' => 'Document type is required']);
    exit();
}

if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
    exit();
}

$file = $_FILES['document'];

// Validate file type (PDF, JPG, PNG only)
$allowed_types = ['application/pdf', 'image/jpeg', 'image/jpg', 'image/png'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime_type = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mime_type, $allowed_types)) {
    echo json_encode(['success' => false, 'message' => 'Invalid file type. Only PDF, JPG, and PNG files are allowed']);
    exit();
}

// Validate file size (max 5MB)
if ($file['size'] > 5 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'File size exceeds 5MB limit']);
    exit();
}

// Create user directory if it doesn't exist
$user_dir = '../uploads/documents/' . $_SESSION['user_id'];
if (!file_exists($user_dir)) {
    mkdir($user_dir, 0755, true);
}

// Generate unique filename
$extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = $document_type . '_' . time() . '.' . $extension;
$file_path = $user_dir . '/' . $filename;

// Store path relative to web root (without ../)
$db_file_path = 'uploads/documents/' . $_SESSION['user_id'] . '/' . $filename;

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $file_path)) {
    echo json_encode(['success' => false, 'message' => 'Failed to save file']);
    exit();
}

// Save to database
try {
    // Check if document type already exists
    $check_stmt = $conn->prepare("SELECT id FROM document_uploads WHERE user_id = :user_id AND document_type = :document_type");
    $check_stmt->bindParam(':user_id', $_SESSION['user_id']);
    $check_stmt->bindParam(':document_type', $document_type);
    $check_stmt->execute();
    $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // Update existing record
        $stmt = $conn->prepare("UPDATE document_uploads 
                               SET file_name = :file_name, 
                                   file_path = :file_path, 
                                   file_size = :file_size,
                                   verification_status = 'pending',
                                   rejection_reason = NULL,
                                   verified_by = NULL,
                                   verified_at = NULL,
                                   upload_date = NOW()
                               WHERE id = :id");
        $stmt->bindParam(':id', $existing['id']);
    } else {
        // Insert new record
        $stmt = $conn->prepare("INSERT INTO document_uploads 
                               (user_id, document_type, file_name, file_path, file_size, verification_status) 
                               VALUES (:user_id, :document_type, :file_name, :file_path, :file_size, 'pending')");
        $stmt->bindParam(':user_id', $_SESSION['user_id']);
        $stmt->bindParam(':document_type', $document_type);
    }
    
    $stmt->bindParam(':file_name', $filename);
    $stmt->bindParam(':file_path', $db_file_path);
    $stmt->bindParam(':file_size', $file['size']);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true, 
            'message' => 'Document uploaded successfully',
            'filename' => $filename
        ]);
    } else {
        // Delete file if database insert failed
        unlink($file_path);
        echo json_encode(['success' => false, 'message' => 'Database error']);
    }
} catch (PDOException $e) {
    // Delete file if database operation failed
    if (file_exists($file_path)) {
        unlink($file_path);
    }
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>

