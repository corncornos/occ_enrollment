<?php
declare(strict_types=1);

// Database configuration will be included by the calling file

class User {
    private $conn;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    public function register($data) {
        try {
            $sql = "INSERT INTO users (
                lrn, occ_examinee_number, first_name, last_name, middle_name, 
                email, password, phone, contact_number, date_of_birth, age, sex_at_birth, 
                civil_status, spouse_name, address, permanent_address, municipality_city, barangay,
                father_name, father_occupation, father_education, 
                mother_maiden_name, mother_occupation, mother_education,
                number_of_brothers, number_of_sisters, combined_family_income, guardian_name,
                school_last_attended, school_address,
                is_pwd, hearing_disability, physical_disability, mental_disability, 
                intellectual_disability, psychosocial_disability, chronic_illness_disability, learning_disability,
                shs_track, shs_strand,
                is_working_student, employer, work_position, working_hours,
                preferred_program, status, enrollment_status
            ) VALUES (
                :lrn, :occ_examinee_number, :first_name, :last_name, :middle_name,
                :email, :password, :phone, :contact_number, :date_of_birth, :age, :sex_at_birth,
                :civil_status, :spouse_name, :address, :permanent_address, :municipality_city, :barangay,
                :father_name, :father_occupation, :father_education,
                :mother_maiden_name, :mother_occupation, :mother_education,
                :number_of_brothers, :number_of_sisters, :combined_family_income, :guardian_name,
                :school_last_attended, :school_address,
                :is_pwd, :hearing_disability, :physical_disability, :mental_disability,
                :intellectual_disability, :psychosocial_disability, :chronic_illness_disability, :learning_disability,
                :shs_track, :shs_strand,
                :is_working_student, :employer, :work_position, :working_hours,
                :preferred_program, 'active', 'pending'
            )";
            
            $stmt = $this->conn->prepare($sql);
            
            $hashed_password = hashPassword($data['password']);
            
            // Basic Information (student_id removed, will be assigned by Admission)
            $stmt->bindParam(':lrn', $data['lrn']);
            $stmt->bindParam(':occ_examinee_number', $data['occ_examinee_number']);
            $stmt->bindParam(':first_name', $data['first_name']);
            $stmt->bindParam(':last_name', $data['last_name']);
            $stmt->bindParam(':middle_name', $data['middle_name']);
            $stmt->bindParam(':email', $data['email']);
            $stmt->bindParam(':password', $hashed_password);
            $stmt->bindParam(':phone', $data['phone']);
            $stmt->bindParam(':contact_number', $data['contact_number']);
            $stmt->bindParam(':date_of_birth', $data['date_of_birth']);
            $stmt->bindParam(':age', $data['age']);
            $stmt->bindParam(':sex_at_birth', $data['sex_at_birth']);
            $stmt->bindParam(':civil_status', $data['civil_status']);
            $stmt->bindParam(':spouse_name', $data['spouse_name']);
            
            // Address Information
            $stmt->bindParam(':address', $data['address']);
            $stmt->bindParam(':permanent_address', $data['permanent_address']);
            $stmt->bindParam(':municipality_city', $data['municipality_city']);
            $stmt->bindParam(':barangay', $data['barangay']);
            
            // Family Information
            $stmt->bindParam(':father_name', $data['father_name']);
            $stmt->bindParam(':father_occupation', $data['father_occupation']);
            $stmt->bindParam(':father_education', $data['father_education']);
            $stmt->bindParam(':mother_maiden_name', $data['mother_maiden_name']);
            $stmt->bindParam(':mother_occupation', $data['mother_occupation']);
            $stmt->bindParam(':mother_education', $data['mother_education']);
            $stmt->bindParam(':number_of_brothers', $data['number_of_brothers']);
            $stmt->bindParam(':number_of_sisters', $data['number_of_sisters']);
            $stmt->bindParam(':combined_family_income', $data['combined_family_income']);
            $stmt->bindParam(':guardian_name', $data['guardian_name']);
            
            // Educational Background
            $stmt->bindParam(':school_last_attended', $data['school_last_attended']);
            $stmt->bindParam(':school_address', $data['school_address']);
            
            // PWD Information
            $is_pwd = isset($data['is_pwd']) && $data['is_pwd'] == '1' ? 1 : 0;
            $stmt->bindParam(':is_pwd', $is_pwd);
            $stmt->bindParam(':hearing_disability', $data['hearing_disability']);
            $stmt->bindParam(':physical_disability', $data['physical_disability']);
            $stmt->bindParam(':mental_disability', $data['mental_disability']);
            $stmt->bindParam(':intellectual_disability', $data['intellectual_disability']);
            $stmt->bindParam(':psychosocial_disability', $data['psychosocial_disability']);
            $stmt->bindParam(':chronic_illness_disability', $data['chronic_illness_disability']);
            $stmt->bindParam(':learning_disability', $data['learning_disability']);
            
            // Senior High School Information
            $stmt->bindParam(':shs_track', $data['shs_track']);
            $stmt->bindParam(':shs_strand', $data['shs_strand']);
            
            // Working Student Information
            $is_working = isset($data['is_working_student']) && $data['is_working_student'] == '1' ? 1 : 0;
            $stmt->bindParam(':is_working_student', $is_working);
            $stmt->bindParam(':employer', $data['employer']);
            $stmt->bindParam(':work_position', $data['work_position']);
            $stmt->bindParam(':working_hours', $data['working_hours']);
            
            // Program Preference
            $stmt->bindParam(':preferred_program', $data['preferred_program']);
            
            if ($stmt->execute()) {
                $user_id = $this->conn->lastInsertId();
                
                // Begin transaction for section assignment
                $this->conn->beginTransaction();
                
                try {
                    // Create application workflow entry
                    $workflow_sql = "INSERT INTO application_workflow (user_id, admission_status) VALUES (:user_id, 'pending_review')";
                    $workflow_stmt = $this->conn->prepare($workflow_sql);
                    $workflow_stmt->bindParam(':user_id', $user_id);
                    $workflow_stmt->execute();
                    
                    // Assign section if provided - use shared helper function
                    if (isset($data['selected_section']) && !empty($data['selected_section'])) {
                        $section_id = (int)$data['selected_section'];
                        
                        // Validate section capacity before assignment
                        $capacity_check_sql = "SELECT s.id, s.section_name, s.max_capacity, s.current_enrolled, s.status
                                              FROM sections s
                                              WHERE s.id = :section_id";
                        $capacity_check_stmt = $this->conn->prepare($capacity_check_sql);
                        $capacity_check_stmt->bindParam(':section_id', $section_id, PDO::PARAM_INT);
                        $capacity_check_stmt->execute();
                        $section_data = $capacity_check_stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if (!$section_data) {
                            $this->conn->rollBack();
                            return ['success' => false, 'message' => 'Selected section not found. Please select a valid section.'];
                        }
                        
                        if ($section_data['status'] !== 'active') {
                            $this->conn->rollBack();
                            return ['success' => false, 'message' => 'Selected section is not active. Please select another section.'];
                        }
                        
                        if ($section_data['current_enrolled'] >= $section_data['max_capacity']) {
                            $this->conn->rollBack();
                            return ['success' => false, 'message' => 'The selected section "' . htmlspecialchars($section_data['section_name']) . '" is full. Please select another section.'];
                        }
                        
                        // Use shared section assignment helper function
                        // Assign section as 'active' immediately - no need for registrar activation
                        require_once __DIR__ . '/../config/section_assignment_helper.php';
                        $assignment_result = assignSectionToStudent(
                            $this->conn,
                            (int)$user_id,  // Ensure user_id is an integer
                            $section_id,
                            'active',   // Status: active - immediately assigned during registration
                            true,       // Create schedules immediately
                            true        // Increment capacity immediately
                        );
                        
                        if ($assignment_result['success']) {
                            error_log("Registration: Successfully assigned section {$section_id} to user {$user_id} - Action: " . ($assignment_result['action'] ?? 'unknown'));
                        } else {
                            error_log("Registration: Failed to assign section {$section_id} to user {$user_id}: " . $assignment_result['message']);
                            $this->conn->rollBack();
                            return ['success' => false, 'message' => $assignment_result['message']];
                        }
                    } else {
                        error_log("Registration: No section selected for user {$user_id}");
                    }
                    
                    $this->conn->commit();
                    
                    return ['success' => true, 'message' => 'Application submitted successfully! Your application will be reviewed by the Admission Office. You will be notified once your student number is assigned. Your section has been assigned.'];
                    
                } catch (Exception $e) {
                    $this->conn->rollBack();
                    // Log detailed error for debugging
                    $error_msg = 'Section assignment error during registration for user_id ' . ($user_id ?? 'unknown') . 
                                ', section_id ' . ($section_id ?? 'unknown') . ': ' . $e->getMessage() . 
                                ' | Trace: ' . $e->getTraceAsString();
                    error_log($error_msg);
                    // Return success but mention the issue
                    return ['success' => true, 'message' => 'Registration successful, but section assignment encountered an issue. The registrar will assign your section manually.'];
                }
            }
            
            return ['success' => false, 'message' => 'Registration failed'];
            
        } catch(PDOException $e) {
            if ($e->getCode() == 23000) {
                return ['success' => false, 'message' => 'Email already exists'];
            }
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    public function login($email, $password) {
        try {
            $sql = "SELECT id, student_id, first_name, last_name, email, password, status, enrollment_status FROM users WHERE email = :email";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            
            if ($stmt->rowCount() == 1) {
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user['status'] !== 'active') {
                    return ['success' => false, 'message' => 'Account is not active. Please contact administration.'];
                }
                
                if (verifyPassword($password, $user['password'])) {
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['student_id'] = $user['student_id'];
                    $_SESSION['first_name'] = $user['first_name'];
                    $_SESSION['last_name'] = $user['last_name'];
                    $_SESSION['email'] = $user['email'];
                    $_SESSION['role'] = 'student';
                    $_SESSION['is_admin'] = false;
                    $_SESSION['enrollment_status'] = $user['enrollment_status'];
                    
                    return ['success' => true, 'user' => $user];
                } else {
                    return ['success' => false, 'message' => 'Invalid password'];
                }
            } else {
                return ['success' => false, 'message' => 'Student account not found'];
            }
            
        } catch(PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    public function getAllUsers() {
        try {
            $sql = "SELECT id, student_id, first_name, middle_name, last_name, email, phone, contact_number, preferred_program, status, enrollment_status, created_at FROM users ORDER BY created_at DESC";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            
            // Add 'role' field for compatibility (all users are students)
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($users as &$user) {
                $user['role'] = 'student';
            }
            
            return $users;
            
        } catch(PDOException $e) {
            return [];
        }
    }
    
    public function createStudent($student_id, $first_name, $last_name, $email, $phone, $password) {
        try {
            $sql = "INSERT INTO users (
                student_id, 
                first_name, 
                last_name, 
                email, 
                phone, 
                password, 
                role, 
                status, 
                enrollment_status,
                created_at
            ) VALUES (
                :student_id,
                :first_name,
                :last_name,
                :email,
                :phone,
                :password,
                'student',
                'active',
                'enrolled',
                NOW()
            )";
            
            $stmt = $this->conn->prepare($sql);
            $hashed_password = hashPassword($password);
            
            $stmt->bindParam(':student_id', $student_id);
            $stmt->bindParam(':first_name', $first_name);
            $stmt->bindParam(':last_name', $last_name);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':phone', $phone);
            $stmt->bindParam(':password', $hashed_password);
            
            if ($stmt->execute()) {
                return $this->conn->lastInsertId();
            }
            
            return false;
            
        } catch(PDOException $e) {
            // Log the error or handle it appropriately
            return false;
        }
    }
    
    public function updateUserStatus($user_id, $status) {
        try {
            $sql = "UPDATE users SET status = :status WHERE id = :user_id";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':user_id', $user_id);
            
            return $stmt->execute();
            
        } catch(PDOException $e) {
            return false;
        }
    }
    
    public function getUserById($user_id) {
        try {
            $sql = "SELECT * FROM users WHERE id = :user_id";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch(PDOException $e) {
            return null;
        }
    }
    
    /**
     * Update user profile information
     * Allows students to update their personal information
     * 
     * @param int $user_id The ID of the user to update
     * @param array $data Array containing the fields to update
     * @return array Result array with 'success' boolean and 'message' string
     */
    public function updateProfile(int $user_id, array $data): array {
        try {
            // Build dynamic UPDATE query based on provided fields
            $updateFields = [];
            $params = [':user_id' => $user_id];
            
            // Fields that students can update (excluding admin-only fields)
            $allowedFields = [
                'lrn', 'occ_examinee_number', 'first_name', 'last_name', 'middle_name',
                'email', 'phone', 'contact_number', 'date_of_birth', 'age', 'sex_at_birth',
                'civil_status', 'spouse_name', 'address', 'permanent_address', 
                'municipality_city', 'barangay', 'father_name', 'father_occupation', 
                'father_education', 'mother_maiden_name', 'mother_occupation', 
                'mother_education', 'number_of_brothers', 'number_of_sisters', 
                'combined_family_income', 'guardian_name', 'school_last_attended', 
                'school_address', 'is_pwd', 'hearing_disability', 'physical_disability', 
                'mental_disability', 'intellectual_disability', 'psychosocial_disability', 
                'chronic_illness_disability', 'learning_disability', 'shs_track', 
                'shs_strand', 'is_working_student', 'employer', 'work_position', 
                'working_hours', 'preferred_program'
            ];
            
            // Check if email is being changed and verify it's not already taken
            if (isset($data['email']) && !empty($data['email'])) {
                $checkEmailSql = "SELECT id FROM users WHERE email = :email AND id != :user_id";
                $checkEmailStmt = $this->conn->prepare($checkEmailSql);
                $checkEmailStmt->bindParam(':email', $data['email']);
                $checkEmailStmt->bindParam(':user_id', $user_id);
                $checkEmailStmt->execute();
                
                if ($checkEmailStmt->rowCount() > 0) {
                    return ['success' => false, 'message' => 'Email already exists. Please use a different email.'];
                }
            }
            
            // Build update fields dynamically
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updateFields[] = "$field = :$field";
                    $params[":$field"] = $data[$field];
                }
            }
            
            // Handle password update separately if provided
            if (isset($data['password']) && !empty($data['password'])) {
                $updateFields[] = "password = :password";
                $params[':password'] = hashPassword($data['password']);
            }
            
            if (empty($updateFields)) {
                return ['success' => false, 'message' => 'No fields to update'];
            }
            
            $sql = "UPDATE users SET " . implode(', ', $updateFields) . " WHERE id = :user_id";
            $stmt = $this->conn->prepare($sql);
            
            // Bind all parameters
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            if ($stmt->execute()) {
                // Update session variables if name or email changed
                if (isset($data['first_name'])) {
                    $_SESSION['first_name'] = $data['first_name'];
                }
                if (isset($data['last_name'])) {
                    $_SESSION['last_name'] = $data['last_name'];
                }
                if (isset($data['email'])) {
                    $_SESSION['email'] = $data['email'];
                }
                
                return ['success' => true, 'message' => 'Profile updated successfully'];
            }
            
            return ['success' => false, 'message' => 'Failed to update profile'];
            
        } catch(PDOException $e) {
            if ($e->getCode() == 23000) {
                return ['success' => false, 'message' => 'Email already exists. Please use a different email.'];
            }
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    public function logout() {
        session_destroy();
        return true;
    }
}
?>
