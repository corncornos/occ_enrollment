<?php
require_once '../config/database.php';
require_once '../classes/Admission.php';

header('Content-Type: application/json');

if (!isLoggedIn() || !isAdmission()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$document_id = $_POST['document_id'] ?? null;
$status = $_POST['status'] ?? null; // 'verified' or 'rejected'
$rejection_reason = $_POST['rejection_reason'] ?? null;

if (!$document_id || !$status) {
    echo json_encode(['success' => false, 'message' => 'Document ID and status are required']);
    exit();
}

if (!in_array($status, ['verified', 'rejected'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid status']);
    exit();
}

if ($status === 'rejected' && empty($rejection_reason)) {
    echo json_encode(['success' => false, 'message' => 'Rejection reason is required']);
    exit();
}

$admission = new Admission();

if ($admission->updateDocumentStatus($document_id, $status, $rejection_reason)) {
    echo json_encode(['success' => true, 'message' => 'Document status updated successfully']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update document status']);
}
?>







