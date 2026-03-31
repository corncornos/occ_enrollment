<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/Chatbot.php';

// Check if student is logged in
if (!isLoggedIn() || isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

$chatbot = new Chatbot();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'search':
        $query = $_POST['query'] ?? '';
        if (empty($query)) {
            echo json_encode(['success' => false, 'message' => 'Query is required']);
            exit();
        }
        
        $results = $chatbot->searchFAQs($query);
        
        if (!empty($results)) {
            // Save to history
            $answer = $results[0]['answer'];
            $faq_id = $results[0]['id'];
            $chatbot->saveChatHistory($_SESSION['user_id'], $query, $answer, $faq_id);
            $chatbot->incrementViewCount($faq_id);
            
            echo json_encode(['success' => true, 'results' => $results]);
        } else {
            // No results found
            $chatbot->saveChatHistory($_SESSION['user_id'], $query, 'No answer found', null);
            echo json_encode(['success' => true, 'results' => [], 'message' => 'No matching answers found. Please contact the admin for assistance.']);
        }
        break;
        
    case 'get_categories':
        $faqs = $chatbot->getActiveFAQsByCategory();
        echo json_encode(['success' => true, 'categories' => $faqs]);
        break;
        
    default:
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
}
?>

