<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';

// Check if user is logged in and is an admin or registrar staff
if (!isLoggedIn() || (!isAdmin() && !isRegistrarStaff())) {
    // Check if AJAX request
    if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
    redirect('../public/login.php');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_id = $_POST['user_id'];
    
    // Get checkbox values (unchecked boxes won't be in $_POST)
    $id_pictures = isset($_POST['id_pictures']) ? 1 : 0;
    $psa_birth_certificate = isset($_POST['psa_birth_certificate']) ? 1 : 0;
    $barangay_certificate = isset($_POST['barangay_certificate']) ? 1 : 0;
    $voters_id = isset($_POST['voters_id']) ? 1 : 0;
    $high_school_diploma = isset($_POST['high_school_diploma']) ? 1 : 0;
    $sf10_form = isset($_POST['sf10_form']) ? 1 : 0;
    $form_138 = isset($_POST['form_138']) ? 1 : 0;
    $good_moral = isset($_POST['good_moral']) ? 1 : 0;
    $documents_submitted = isset($_POST['documents_submitted']) ? 1 : 0;
    $photocopies_submitted = isset($_POST['photocopies_submitted']) ? 1 : 0;
    $notes = isset($_POST['notes']) ? sanitizeInput($_POST['notes']) : '';
    
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        // Check if checklist exists
        $check_sql = "SELECT id FROM document_checklists WHERE user_id = :user_id";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bindParam(':user_id', $user_id);
        $check_stmt->execute();
        
        if ($check_stmt->rowCount() > 0) {
            // Update existing checklist
            $sql = "UPDATE document_checklists SET 
                    id_pictures = :id_pictures,
                    psa_birth_certificate = :psa_birth_certificate,
                    barangay_certificate = :barangay_certificate,
                    voters_id = :voters_id,
                    high_school_diploma = :high_school_diploma,
                    sf10_form = :sf10_form,
                    form_138 = :form_138,
                    good_moral = :good_moral,
                    documents_submitted = :documents_submitted,
                    photocopies_submitted = :photocopies_submitted,
                    notes = :notes
                    WHERE user_id = :user_id";
        } else {
            // Insert new checklist
            $sql = "INSERT INTO document_checklists 
                    (user_id, id_pictures, psa_birth_certificate, barangay_certificate, voters_id, 
                     high_school_diploma, sf10_form, form_138, good_moral, 
                     documents_submitted, photocopies_submitted, notes) 
                    VALUES 
                    (:user_id, :id_pictures, :psa_birth_certificate, :barangay_certificate, :voters_id, 
                     :high_school_diploma, :sf10_form, :form_138, :good_moral, 
                     :documents_submitted, :photocopies_submitted, :notes)";
        }
        
        $stmt = $conn->prepare($sql);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->bindParam(':id_pictures', $id_pictures);
        $stmt->bindParam(':psa_birth_certificate', $psa_birth_certificate);
        $stmt->bindParam(':barangay_certificate', $barangay_certificate);
        $stmt->bindParam(':voters_id', $voters_id);
        $stmt->bindParam(':high_school_diploma', $high_school_diploma);
        $stmt->bindParam(':sf10_form', $sf10_form);
        $stmt->bindParam(':form_138', $form_138);
        $stmt->bindParam(':good_moral', $good_moral);
        $stmt->bindParam(':documents_submitted', $documents_submitted);
        $stmt->bindParam(':photocopies_submitted', $photocopies_submitted);
        $stmt->bindParam(':notes', $notes);
        
        // Check if AJAX request (check both header and form data)
        $is_ajax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') 
                   || isset($_POST['ajax_request']);
        
        if ($stmt->execute()) {
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'message' => 'Document checklist updated successfully']);
                exit;
            }
            $_SESSION['message'] = 'Document checklist updated successfully';
        } else {
            if ($is_ajax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Failed to update document checklist']);
                exit;
            }
            $_SESSION['message'] = 'Failed to update document checklist';
        }
        
    } catch(PDOException $e) {
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
            header('Content-Type: application/json');
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
            exit;
        }
        $_SESSION['message'] = 'Database error: ' . $e->getMessage();
    }
}

// Only redirect if not AJAX request
$is_ajax_final = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') 
                 || isset($_POST['ajax_request']);
if (!$is_ajax_final) {
    redirect('admin/dashboard.php');
}
?>

