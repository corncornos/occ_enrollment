<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/Chatbot.php';

// Check if admin is logged in
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

$chatbot = new Chatbot();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'get_all':
        $faqs = $chatbot->getAllFAQs();
        echo json_encode(['success' => true, 'faqs' => $faqs]);
        break;
        
    case 'get_one':
        $id = $_GET['id'] ?? 0;
        $faq = $chatbot->getFAQById($id);
        echo json_encode(['success' => true, 'faq' => $faq]);
        break;
        
    case 'add':
        $data = [
            'question' => $_POST['question'] ?? '',
            'answer' => $_POST['answer'] ?? '',
            'keywords' => $_POST['keywords'] ?? '',
            'category' => $_POST['category'] ?? 'General',
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
            'created_by' => $_SESSION['admin_id']
        ];
        $result = $chatbot->addFAQ($data);
        echo json_encode($result);
        break;
        
    case 'update':
        $id = $_POST['id'] ?? 0;
        $data = [
            'question' => $_POST['question'] ?? '',
            'answer' => $_POST['answer'] ?? '',
            'keywords' => $_POST['keywords'] ?? '',
            'category' => $_POST['category'] ?? 'General',
            'is_active' => isset($_POST['is_active']) ? 1 : 0
        ];
        $result = $chatbot->updateFAQ($id, $data);
        echo json_encode($result);
        break;
        
    case 'delete':
        $id = $_POST['id'] ?? 0;
        $result = $chatbot->deleteFAQ($id);
        echo json_encode($result);
        break;
        
    case 'statistics':
        $stats = $chatbot->getChatStatistics();
        echo json_encode(['success' => true, 'stats' => $stats]);
        break;
        
    case 'recent_inquiries':
        $inquiries = $chatbot->getRecentInquiries(20);
        echo json_encode(['success' => true, 'inquiries' => $inquiries]);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
?>

