<?php
// Admin class for admin/registrar operations

class Admin {
    private $conn;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    public function login($email, $password) {
        try {
            // First check if is_dean column exists
            $check_column = $this->conn->query("SHOW COLUMNS FROM admins LIKE 'is_dean'");
            $has_dean_column = $check_column->rowCount() > 0;
            
            // Build query based on column existence
            if ($has_dean_column) {
                $sql = "SELECT id, admin_id, first_name, last_name, email, password, role, status, is_dean FROM admins WHERE email = :email";
            } else {
                $sql = "SELECT id, admin_id, first_name, last_name, email, password, role, status FROM admins WHERE email = :email";
            }
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            
            if ($stmt->rowCount() == 1) {
                $admin = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($admin['status'] !== 'active') {
                    return ['success' => false, 'message' => 'Account is not active. Please contact system administrator.'];
                }
                
                if (password_verify($password, $admin['password'])) {
                    $_SESSION['user_id'] = $admin['id'];
                    $_SESSION['admin_id'] = $admin['admin_id'];
                    $_SESSION['first_name'] = $admin['first_name'];
                    $_SESSION['last_name'] = $admin['last_name'];
                    $_SESSION['email'] = $admin['email'];
                    
                    // Check if this is a dean account (only if column exists)
                    if ($has_dean_column && !empty($admin['is_dean']) && $admin['is_dean'] == 1) {
                        $_SESSION['role'] = 'dean';
                        $_SESSION['is_dean'] = true;
                        $_SESSION['is_admin'] = false;
                    } else {
                        $_SESSION['role'] = $admin['role'] ?? 'admin';
                        $_SESSION['is_admin'] = true;
                        $_SESSION['is_dean'] = false;
                    }
                    
                    return ['success' => true, 'user' => $admin];
                } else {
                    return ['success' => false, 'message' => 'Invalid password'];
                }
            } else {
                return ['success' => false, 'message' => 'Admin account not found'];
            }
            
        } catch(PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    public function getAdminById($admin_id) {
        try {
            $sql = "SELECT * FROM admins WHERE id = :admin_id";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':admin_id', $admin_id);
            $stmt->execute();
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch(PDOException $e) {
            return null;
        }
    }
    
    public function getAllAdmins() {
        try {
            $sql = "SELECT id, admin_id, first_name, last_name, email, phone, role, status, created_at FROM admins ORDER BY created_at DESC";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch(PDOException $e) {
            return [];
        }
    }
    
    public function logout() {
        session_destroy();
        return true;
    }
    
    /**
     * Get enrollment control settings for next semester enrollment
     */
    public function getEnrollmentControl() {
        try {
            // Check if enrollment_type column exists
            $check_column = $this->conn->query("SHOW COLUMNS FROM enrollment_control LIKE 'enrollment_type'");
            $has_enrollment_type = $check_column->rowCount() > 0;
            
            if ($has_enrollment_type) {
                $sql = "SELECT * FROM enrollment_control 
                        WHERE enrollment_type = 'next_semester'
                        ORDER BY id DESC LIMIT 1";
            } else {
                // Fallback: get the most recent enrollment control
                // In this case, we'll use the latest one (assuming it's for next semester)
                $sql = "SELECT * FROM enrollment_control 
                        ORDER BY id DESC LIMIT 1";
            }
            
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch(PDOException $e) {
            error_log('Error getting enrollment control: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update enrollment control for next semester enrollment
     */
    public function updateEnrollmentControl($academic_year, $semester, $status, $opening_date, $closing_date, $announcement) {
        try {
            $admin_id = $_SESSION['user_id'];
            
            // Check if enrollment_type column exists
            $check_column = $this->conn->query("SHOW COLUMNS FROM enrollment_control LIKE 'enrollment_type'");
            $has_enrollment_type = $check_column->rowCount() > 0;
            
            if ($has_enrollment_type) {
                // Use enrollment_type to distinguish next semester enrollment
                // First, delete any existing next_semester enrollment control
                $delete_sql = "DELETE FROM enrollment_control WHERE enrollment_type = 'next_semester'";
                $delete_stmt = $this->conn->prepare($delete_sql);
                $delete_stmt->execute();
                
                // Insert new enrollment control
                $sql = "INSERT INTO enrollment_control 
                        (academic_year, semester, enrollment_status, opening_date, closing_date, announcement, enrollment_type, created_by)
                        VALUES (:academic_year, :semester, :status, :opening_date, :closing_date, :announcement, 'next_semester', :admin_id)";
            } else {
                // Fallback: update the most recent enrollment control
                // First, get the latest ID
                $get_latest = "SELECT id FROM enrollment_control ORDER BY id DESC LIMIT 1";
                $latest_stmt = $this->conn->prepare($get_latest);
                $latest_stmt->execute();
                $latest = $latest_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($latest) {
                    // Update existing
                    $sql = "UPDATE enrollment_control 
                            SET academic_year = :academic_year,
                                semester = :semester,
                                enrollment_status = :status, 
                                opening_date = :opening_date, 
                                closing_date = :closing_date, 
                                announcement = :announcement,
                                created_by = :admin_id,
                                updated_at = NOW()
                            WHERE id = :id";
                } else {
                    // Insert new
                    $sql = "INSERT INTO enrollment_control 
                            (academic_year, semester, enrollment_status, opening_date, closing_date, announcement, created_by)
                            VALUES (:academic_year, :semester, :status, :opening_date, :closing_date, :announcement, :admin_id)";
                }
            }
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':academic_year', $academic_year);
            $stmt->bindParam(':semester', $semester);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':opening_date', $opening_date);
            $stmt->bindParam(':closing_date', $closing_date);
            $stmt->bindParam(':announcement', $announcement);
            $stmt->bindParam(':admin_id', $admin_id);
            
            if (!$has_enrollment_type && isset($latest) && $latest) {
                $stmt->bindParam(':id', $latest['id'], PDO::PARAM_INT);
            }
            
            return $stmt->execute();
            
        } catch(PDOException $e) {
            error_log('Error updating enrollment control: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if next semester enrollment is currently open
     */
    public function isNextSemesterEnrollmentOpen() {
        try {
            $control = $this->getEnrollmentControl();
            
            if (!$control) {
                return false; // No control record means closed
            }
            
            // Check status
            if ($control['enrollment_status'] !== 'open') {
                return false;
            }
            
            // Check dates if set
            $today = date('Y-m-d');
            if (!empty($control['opening_date']) && $today < $control['opening_date']) {
                return false; // Not yet opened
            }
            if (!empty($control['closing_date']) && $today > $control['closing_date']) {
                return false; // Already closed
            }
            
            return true;
            
        } catch(PDOException $e) {
            error_log('Error checking enrollment status: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get adjustment period control settings
     */
    public function getAdjustmentPeriodControl() {
        try {
            $sql = "SELECT * FROM adjustment_period_control 
                    ORDER BY id DESC LIMIT 1";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch(PDOException $e) {
            error_log('Error getting adjustment period control: ' . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Update adjustment period control settings
     */
    public function updateAdjustmentPeriodControl($academic_year, $semester, $status, $opening_date, $closing_date, $announcement) {
        try {
            $admin_id = $_SESSION['user_id'];
            
            // Delete any existing adjustment period control
            $delete_sql = "DELETE FROM adjustment_period_control";
            $delete_stmt = $this->conn->prepare($delete_sql);
            $delete_stmt->execute();
            
            // Insert new adjustment period control
            $sql = "INSERT INTO adjustment_period_control 
                    (academic_year, semester, adjustment_status, opening_date, closing_date, announcement, created_by)
                    VALUES (:academic_year, :semester, :status, :opening_date, :closing_date, :announcement, :admin_id)";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':academic_year', $academic_year);
            $stmt->bindParam(':semester', $semester);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':opening_date', $opening_date);
            $stmt->bindParam(':closing_date', $closing_date);
            $stmt->bindParam(':announcement', $announcement);
            $stmt->bindParam(':admin_id', $admin_id);
            
            return $stmt->execute();
            
        } catch(PDOException $e) {
            error_log('Error updating adjustment period control: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check if adjustment period is currently open
     */
    public function isAdjustmentPeriodOpen() {
        try {
            $control = $this->getAdjustmentPeriodControl();
            
            if (!$control) {
                return false; // No control record means closed
            }
            
            // Check status
            if ($control['adjustment_status'] !== 'open') {
                return false;
            }
            
            // Check dates if set
            $today = date('Y-m-d');
            if (!empty($control['opening_date']) && $today < $control['opening_date']) {
                return false; // Not yet opened
            }
            if (!empty($control['closing_date']) && $today > $control['closing_date']) {
                return false; // Already closed
            }
            
            return true;
            
        } catch(PDOException $e) {
            error_log('Error checking adjustment period status: ' . $e->getMessage());
            return false;
        }
    }
}
?>

