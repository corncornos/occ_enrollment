<?php
require_once __DIR__ . '/../config/database.php';

class RegistrarStaff {
    private $conn;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    /**
     * Login registrar staff
     */
    public function login($email, $password) {
        try {
            $sql = "SELECT id, staff_id, first_name, last_name, email, password, status 
                    FROM registrar_staff 
                    WHERE email = :email AND status = 'active'";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            
            if ($stmt->rowCount() == 1) {
                $staff = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (password_verify($password, $staff['password'])) {
                    // Set session variables
                    $_SESSION['user_id'] = $staff['id'];
                    $_SESSION['staff_id'] = $staff['staff_id'];
                    $_SESSION['first_name'] = $staff['first_name'];
                    $_SESSION['last_name'] = $staff['last_name'];
                    $_SESSION['email'] = $staff['email'];
                    $_SESSION['role'] = 'registrar_staff';
                    $_SESSION['is_registrar_staff'] = true;
                    
                    return ['success' => true, 'staff' => $staff];
                } else {
                    return ['success' => false, 'message' => 'Invalid password'];
                }
            } else {
                return ['success' => false, 'message' => 'Registrar staff account not found or inactive'];
            }
        } catch(PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get registrar staff by ID
     */
    public function getStaffById($id) {
        try {
            $sql = "SELECT * FROM registrar_staff WHERE id = :id";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            
            if ($stmt->rowCount() == 1) {
                return $stmt->fetch(PDO::FETCH_ASSOC);
            }
            return false;
        } catch(PDOException $e) {
            return false;
        }
    }
    
    /**
     * Get all registrar staff
     */
    public function getAllStaff() {
        try {
            $sql = "SELECT * FROM registrar_staff ORDER BY created_at DESC";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch(PDOException $e) {
            return [];
        }
    }
    
    /**
     * Create new registrar staff account
     */
    public function createStaff($data) {
        try {
            $sql = "INSERT INTO registrar_staff (staff_id, first_name, last_name, email, password, phone, status) 
                    VALUES (:staff_id, :first_name, :last_name, :email, :password, :phone, :status)";
            $stmt = $this->conn->prepare($sql);
            
            $hashed_password = password_hash($data['password'], PASSWORD_DEFAULT);
            
            $stmt->bindParam(':staff_id', $data['staff_id']);
            $stmt->bindParam(':first_name', $data['first_name']);
            $stmt->bindParam(':last_name', $data['last_name']);
            $stmt->bindParam(':email', $data['email']);
            $stmt->bindParam(':password', $hashed_password);
            $stmt->bindParam(':phone', $data['phone']);
            $stmt->bindParam(':status', $data['status']);
            
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'Registrar staff account created successfully'];
            } else {
                return ['success' => false, 'message' => 'Failed to create registrar staff account'];
            }
        } catch(PDOException $e) {
            if ($e->getCode() == 23000) {
                return ['success' => false, 'message' => 'Email or Staff ID already exists'];
            }
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Update registrar staff
     */
    public function updateStaff($id, $data) {
        try {
            $sql = "UPDATE registrar_staff 
                    SET first_name = :first_name, 
                        last_name = :last_name, 
                        email = :email, 
                        phone = :phone, 
                        status = :status";
            
            if (!empty($data['password'])) {
                $sql .= ", password = :password";
            }
            
            $sql .= " WHERE id = :id";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':id', $id);
            $stmt->bindParam(':first_name', $data['first_name']);
            $stmt->bindParam(':last_name', $data['last_name']);
            $stmt->bindParam(':email', $data['email']);
            $stmt->bindParam(':phone', $data['phone']);
            $stmt->bindParam(':status', $data['status']);
            
            if (!empty($data['password'])) {
                $hashed_password = password_hash($data['password'], PASSWORD_DEFAULT);
                $stmt->bindParam(':password', $hashed_password);
            }
            
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'Registrar staff updated successfully'];
            } else {
                return ['success' => false, 'message' => 'Failed to update registrar staff'];
            }
        } catch(PDOException $e) {
            if ($e->getCode() == 23000) {
                return ['success' => false, 'message' => 'Email already exists'];
            }
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
}
?>

