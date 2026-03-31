<?php
// Chatbot class for managing FAQ and student inquiries

class Chatbot {
    private $conn;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    // Admin functions
    
    public function getAllFAQs() {
        try {
            $sql = "SELECT f.*, a.first_name, a.last_name
                    FROM chatbot_faqs f
                    LEFT JOIN admins a ON f.created_by = a.id
                    ORDER BY f.category, f.created_at DESC";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            return [];
        }
    }
    
    public function getFAQById($id) {
        try {
            $sql = "SELECT * FROM chatbot_faqs WHERE id = :id";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            return null;
        }
    }
    
    public function addFAQ($data) {
        try {
            $sql = "INSERT INTO chatbot_faqs (question, answer, keywords, category, is_active, created_by) 
                    VALUES (:question, :answer, :keywords, :category, :is_active, :created_by)";
            $stmt = $this->conn->prepare($sql);
            
            $stmt->bindParam(':question', $data['question']);
            $stmt->bindParam(':answer', $data['answer']);
            $stmt->bindParam(':keywords', $data['keywords']);
            $stmt->bindParam(':category', $data['category']);
            $stmt->bindParam(':is_active', $data['is_active']);
            $stmt->bindParam(':created_by', $data['created_by']);
            
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'FAQ added successfully'];
            }
            return ['success' => false, 'message' => 'Failed to add FAQ'];
        } catch(PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    public function updateFAQ($id, $data) {
        try {
            $sql = "UPDATE chatbot_faqs 
                    SET question = :question, 
                        answer = :answer, 
                        keywords = :keywords, 
                        category = :category, 
                        is_active = :is_active
                    WHERE id = :id";
            $stmt = $this->conn->prepare($sql);
            
            $stmt->bindParam(':id', $id);
            $stmt->bindParam(':question', $data['question']);
            $stmt->bindParam(':answer', $data['answer']);
            $stmt->bindParam(':keywords', $data['keywords']);
            $stmt->bindParam(':category', $data['category']);
            $stmt->bindParam(':is_active', $data['is_active']);
            
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'FAQ updated successfully'];
            }
            return ['success' => false, 'message' => 'Failed to update FAQ'];
        } catch(PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    public function deleteFAQ($id) {
        try {
            $sql = "DELETE FROM chatbot_faqs WHERE id = :id";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':id', $id);
            
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'FAQ deleted successfully'];
            }
            return ['success' => false, 'message' => 'Failed to delete FAQ'];
        } catch(PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Student functions
    
    public function searchFAQs($query) {
        try {
            $searchTerm = '%' . $query . '%';
            $sql = "SELECT * FROM chatbot_faqs 
                    WHERE is_active = 1 
                    AND (question LIKE :search1 
                         OR answer LIKE :search2 
                         OR keywords LIKE :search3)
                    ORDER BY view_count DESC
                    LIMIT 5";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':search1', $searchTerm);
            $stmt->bindParam(':search2', $searchTerm);
            $stmt->bindParam(':search3', $searchTerm);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            return [];
        }
    }
    
    public function getActiveFAQsByCategory() {
        try {
            $sql = "SELECT * FROM chatbot_faqs 
                    WHERE is_active = 1 
                    ORDER BY category, view_count DESC";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            
            $faqs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $grouped = [];
            foreach ($faqs as $faq) {
                $category = $faq['category'] ?? 'General';
                if (!isset($grouped[$category])) {
                    $grouped[$category] = [];
                }
                $grouped[$category][] = $faq;
            }
            return $grouped;
        } catch(PDOException $e) {
            return [];
        }
    }
    
    public function incrementViewCount($faq_id) {
        try {
            $sql = "UPDATE chatbot_faqs SET view_count = view_count + 1 WHERE id = :id";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':id', $faq_id);
            $stmt->execute();
        } catch(PDOException $e) {
            // Silent fail
        }
    }
    
    public function saveChatHistory($user_id, $question, $answer, $faq_id = null) {
        try {
            $sql = "INSERT INTO chatbot_history (user_id, question, answer, faq_id) 
                    VALUES (:user_id, :question, :answer, :faq_id)";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':question', $question);
            $stmt->bindParam(':answer', $answer);
            $stmt->bindParam(':faq_id', $faq_id);
            $stmt->execute();
        } catch(PDOException $e) {
            // Silent fail
        }
    }
    
    public function getRecentInquiries($limit = 10) {
        try {
            $sql = "SELECT h.*, u.student_id, u.first_name, u.last_name
                    FROM chatbot_history h
                    JOIN users u ON h.user_id = u.id
                    ORDER BY h.created_at DESC
                    LIMIT :limit";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            return [];
        }
    }
    
    public function getChatStatistics() {
        try {
            $stats = [];
            
            // Total FAQs
            $stmt = $this->conn->query("SELECT COUNT(*) as total FROM chatbot_faqs WHERE is_active = 1");
            $stats['total_faqs'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Total inquiries
            $stmt = $this->conn->query("SELECT COUNT(*) as total FROM chatbot_history");
            $stats['total_inquiries'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Most viewed FAQ
            $stmt = $this->conn->query("SELECT question, view_count FROM chatbot_faqs WHERE is_active = 1 ORDER BY view_count DESC LIMIT 1");
            $stats['most_viewed'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $stats;
        } catch(PDOException $e) {
            return ['total_faqs' => 0, 'total_inquiries' => 0, 'most_viewed' => null];
        }
    }
}
?>

