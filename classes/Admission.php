<?php
// Admission class for authentication and operations

class Admission {
    private $conn;
    private $table_name = 'admissions';
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    /**
     * Authenticate admission user
     */
    public function login($email, $password) {
        try {
            $sql = "SELECT id, username, email, password, first_name, last_name, status 
                    FROM " . $this->table_name . " 
                    WHERE email = :email AND status = 'active'";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':email', $email);
            $stmt->execute();
            
            if ($stmt->rowCount() == 1) {
                $admission = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (verifyPassword($password, $admission['password'])) {
                    // Core session identifiers (keep aligned with other roles)
                    $_SESSION['user_id'] = $admission['id'];

                    // Role-specific session data
                    $_SESSION['admission_id'] = $admission['id'];
                    $_SESSION['admission_username'] = $admission['username'];
                    $_SESSION['admission_email'] = $admission['email'];
                    $_SESSION['first_name'] = $admission['first_name'];
                    $_SESSION['last_name'] = $admission['last_name'];
                    $_SESSION['role'] = 'admission';
                    $_SESSION['is_admission'] = true;
                    
                    return ['success' => true, 'user' => $admission];
                } else {
                    return ['success' => false, 'message' => 'Invalid password'];
                }
            } else {
                return ['success' => false, 'message' => 'Admission account not found or inactive'];
            }
            
        } catch(PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    /**
     * Get all pending applicants
     */
    public function getPendingApplicants() {
        try {
            $sql = "SELECT u.*, 
                    aw.id AS workflow_id,
                    aw.admission_status, 
                    aw.admission_remarks,
                    aw.documents_verified, 
                    aw.student_number_assigned, 
                    aw.enrollment_scheduled, 
                    aw.passed_to_registrar,
                    aw.passed_to_registrar_at,
                    aw.registrar_status,
                    u.id AS user_id
                    FROM users u
                    LEFT JOIN application_workflow aw ON u.id = aw.user_id
                    WHERE u.enrollment_status = 'pending'
                    ORDER BY u.created_at DESC";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch(PDOException $e) {
            return [];
        }
    }
    
    /**
     * Get applicant details
     */
    public function getApplicantById($user_id) {
        try {
            $sql = "SELECT u.*, 
                    aw.id AS workflow_id,
                    aw.admission_status,
                    aw.admission_remarks,
                    aw.documents_verified,
                    aw.student_number_assigned,
                    aw.enrollment_scheduled,
                    aw.admission_approved_by,
                    aw.admission_approved_at,
                    aw.passed_to_registrar,
                    aw.passed_to_registrar_at,
                    aw.registrar_status,
                    es.id AS schedule_id,
                    es.scheduled_date, es.scheduled_time, es.status as schedule_status,
                    u.id AS user_id
                    FROM users u
                    LEFT JOIN application_workflow aw ON u.id = aw.user_id
                    LEFT JOIN enrollment_schedules es ON u.id = es.user_id
                    WHERE u.id = :user_id";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch(PDOException $e) {
            return null;
        }
    }
    
    /**
     * Update application workflow status
     */
    public function updateApplicationStatus($user_id, $status, $remarks = null) {
        try {
            $sql = "UPDATE application_workflow 
                    SET admission_status = :status, 
                        admission_remarks = :remarks,
                        updated_at = NOW()
                    WHERE user_id = :user_id";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':remarks', $remarks);
            $stmt->bindParam(':user_id', $user_id);
            
            return $stmt->execute();
            
        } catch(PDOException $e) {
            return false;
        }
    }
    
    /**
     * Generate and assign student number
     */
    public function assignStudentNumber($user_id) {
        try {
            $this->conn->beginTransaction();
            
            // Get current year
            $year = date('Y');
            
            // Get or create sequence for this year
            $seq_sql = "INSERT INTO student_number_sequence (year, last_number) 
                        VALUES (:year, 0) 
                        ON DUPLICATE KEY UPDATE last_number = last_number + 1";
            $seq_stmt = $this->conn->prepare($seq_sql);
            $seq_stmt->bindParam(':year', $year);
            $seq_stmt->execute();
            
            // Get the new number
            $get_sql = "SELECT last_number FROM student_number_sequence WHERE year = :year FOR UPDATE";
            $get_stmt = $this->conn->prepare($get_sql);
            $get_stmt->bindParam(':year', $year);
            $get_stmt->execute();
            $row = $get_stmt->fetch(PDO::FETCH_ASSOC);
            $number = $row['last_number'];
            
            // Format: YEAR-#####
            $student_number = $year . '-' . str_pad($number, 5, '0', STR_PAD_LEFT);
            
            // Update user table
            $update_sql = "UPDATE users SET student_id = :student_number WHERE id = :user_id";
            $update_stmt = $this->conn->prepare($update_sql);
            $update_stmt->bindParam(':student_number', $student_number);
            $update_stmt->bindParam(':user_id', $user_id);
            $update_stmt->execute();
            
            // Update workflow
            $workflow_sql = "UPDATE application_workflow 
                            SET student_number_assigned = 1, updated_at = NOW() 
                            WHERE user_id = :user_id";
            $workflow_stmt = $this->conn->prepare($workflow_sql);
            $workflow_stmt->bindParam(':user_id', $user_id);
            $workflow_stmt->execute();
            
            $this->conn->commit();
            
            return ['success' => true, 'student_number' => $student_number];
            
        } catch(PDOException $e) {
            $this->conn->rollBack();
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
    
    
    /**
     * Pass applicant to registrar for final enrollment
     * Also activates section enrollment if student selected a section during registration
     */
    public function passToRegistrar($user_id) {
        try {
            $this->conn->beginTransaction();
            $admission_id = $_SESSION['admission_id'];
            
            // Update workflow
            $sql = "UPDATE application_workflow 
                    SET passed_to_registrar = 1, 
                        passed_to_registrar_at = NOW(),
                        admission_approved_by = :admission_id,
                        admission_approved_at = NOW(),
                        updated_at = NOW()
                    WHERE user_id = :user_id";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':admission_id', $admission_id);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            
            // Activate section enrollment if exists (pending status from registration)
            $enrollment_check = "SELECT se.id, se.section_id, s.max_capacity, s.current_enrolled
                                FROM section_enrollments se
                                JOIN sections s ON se.section_id = s.id
                                WHERE se.user_id = :user_id 
                                AND se.status = 'pending'
                                AND s.status = 'active'
                                AND s.current_enrolled < s.max_capacity";
            $enrollment_stmt = $this->conn->prepare($enrollment_check);
            $enrollment_stmt->bindParam(':user_id', $user_id);
            $enrollment_stmt->execute();
            $pending_enrollment = $enrollment_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($pending_enrollment) {
                $section_id = $pending_enrollment['section_id'];
                
                // Activate the enrollment
                $activate_sql = "UPDATE section_enrollments 
                                SET status = 'active', enrolled_date = NOW()
                                WHERE id = :enrollment_id";
                $activate_stmt = $this->conn->prepare($activate_sql);
                $activate_stmt->bindParam(':enrollment_id', $pending_enrollment['id']);
                $activate_stmt->execute();
                
                // Increment section enrollment count
                $increment_sql = "UPDATE sections 
                                 SET current_enrolled = current_enrolled + 1 
                                 WHERE id = :section_id";
                $increment_stmt = $this->conn->prepare($increment_sql);
                $increment_stmt->bindParam(':section_id', $section_id);
                $increment_stmt->execute();
                
                // Get section details for syncing enrolled_students
                $section_query = "SELECT s.year_level, s.semester, s.academic_year, p.program_code 
                                 FROM sections s 
                                 JOIN programs p ON s.program_id = p.id 
                                 WHERE s.id = :section_id";
                $section_stmt = $this->conn->prepare($section_query);
                $section_stmt->bindParam(':section_id', $section_id);
                $section_stmt->execute();
                $section_info = $section_stmt->fetch(PDO::FETCH_ASSOC);
                
                // Create student_schedules entries from section_schedules for this section
                // This ensures the student has their full schedule assigned
                $section_schedules_query = "SELECT * FROM section_schedules WHERE section_id = :section_id";
                $section_schedules_stmt = $this->conn->prepare($section_schedules_query);
                $section_schedules_stmt->bindParam(':section_id', $section_id);
                $section_schedules_stmt->execute();
                $section_schedules = $section_schedules_stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($section_schedules as $schedule) {
                    // Check if student_schedule already exists
                    $check_student_schedule = $this->conn->prepare("SELECT id FROM student_schedules 
                                                                  WHERE user_id = :user_id 
                                                                  AND section_schedule_id = :section_schedule_id 
                                                                  AND status = 'active'");
                    $check_student_schedule->bindParam(':user_id', $user_id);
                    $check_student_schedule->bindParam(':section_schedule_id', $schedule['id']);
                    $check_student_schedule->execute();
                    
                    if ($check_student_schedule->rowCount() == 0) {
                        // Insert into student_schedules
                        $insert_student_schedule = $this->conn->prepare("INSERT INTO student_schedules 
                                                                       (user_id, section_schedule_id, course_code, course_name, units,
                                                                        schedule_monday, schedule_tuesday, schedule_wednesday, schedule_thursday,
                                                                        schedule_friday, schedule_saturday, schedule_sunday,
                                                                        time_start, time_end, room, professor_name, professor_initial, status)
                                                                       VALUES 
                                                                       (:user_id, :section_schedule_id, :course_code, :course_name, :units,
                                                                        :schedule_monday, :schedule_tuesday, :schedule_wednesday, :schedule_thursday,
                                                                        :schedule_friday, :schedule_saturday, :schedule_sunday,
                                                                        :time_start, :time_end, :room, :professor_name, :professor_initial, 'active')");
                        $insert_student_schedule->bindParam(':user_id', $user_id);
                        $insert_student_schedule->bindParam(':section_schedule_id', $schedule['id']);
                        $insert_student_schedule->bindParam(':course_code', $schedule['course_code']);
                        $insert_student_schedule->bindParam(':course_name', $schedule['course_name']);
                        $insert_student_schedule->bindParam(':units', $schedule['units']);
                        $insert_student_schedule->bindParam(':schedule_monday', $schedule['schedule_monday']);
                        $insert_student_schedule->bindParam(':schedule_tuesday', $schedule['schedule_tuesday']);
                        $insert_student_schedule->bindParam(':schedule_wednesday', $schedule['schedule_wednesday']);
                        $insert_student_schedule->bindParam(':schedule_thursday', $schedule['schedule_thursday']);
                        $insert_student_schedule->bindParam(':schedule_friday', $schedule['schedule_friday']);
                        $insert_student_schedule->bindParam(':schedule_saturday', $schedule['schedule_saturday']);
                        $insert_student_schedule->bindParam(':schedule_sunday', $schedule['schedule_sunday']);
                        $insert_student_schedule->bindParam(':time_start', $schedule['time_start']);
                        $insert_student_schedule->bindParam(':time_end', $schedule['time_end']);
                        $insert_student_schedule->bindParam(':room', $schedule['room']);
                        $insert_student_schedule->bindParam(':professor_name', $schedule['professor_name']);
                        $insert_student_schedule->bindParam(':professor_initial', $schedule['professor_initial']);
                        $insert_student_schedule->execute();
                    }
                }
                
                // Sync user data to enrolled_students table with section information
                // This ensures the student appears as enrolled with correct year level and semester
                if ($section_info) {
                    $sync_file = __DIR__ . '/../admin/sync_user_to_enrolled_students.php';
                    if (file_exists($sync_file)) {
                        require_once $sync_file;
                        if (function_exists('syncUserToEnrolledStudents')) {
                            syncUserToEnrolledStudents($this->conn, $user_id, [
                                'course' => $section_info['program_code'],
                                'year_level' => $section_info['year_level'],
                                'semester' => $section_info['semester'],
                                'academic_year' => $section_info['academic_year']
                            ]);
                        }
                    }
                }
            }
            
            $this->conn->commit();
            return true;
            
        } catch(PDOException $e) {
            $this->conn->rollBack();
            return false;
        }
    }
    
    /**
     * Get enrollment control settings
     */
    public function getEnrollmentControl() {
        try {
            $sql = "SELECT * FROM enrollment_control ORDER BY id DESC LIMIT 1";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch(PDOException $e) {
            return null;
        }
    }
    
    /**
     * Update enrollment control
     */
    public function updateEnrollmentControl($academic_year, $semester, $status, $opening_date, $closing_date, $announcement) {
        try {
            $admission_id = $_SESSION['admission_id'];
            
            $sql = "INSERT INTO enrollment_control 
                    (academic_year, semester, enrollment_status, opening_date, closing_date, announcement, created_by)
                    VALUES (:academic_year, :semester, :status, :opening_date, :closing_date, :announcement, :admission_id)
                    ON DUPLICATE KEY UPDATE 
                    enrollment_status = :status, 
                    opening_date = :opening_date, 
                    closing_date = :closing_date, 
                    announcement = :announcement,
                    updated_at = NOW()";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':academic_year', $academic_year);
            $stmt->bindParam(':semester', $semester);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':opening_date', $opening_date);
            $stmt->bindParam(':closing_date', $closing_date);
            $stmt->bindParam(':announcement', $announcement);
            $stmt->bindParam(':admission_id', $admission_id);
            
            return $stmt->execute();
            
        } catch(PDOException $e) {
            return false;
        }
    }
    
    /**
     * Get document uploads for user
     * Returns the latest document for each document type
     */
    public function getDocumentUploads($user_id) {
        try {
            // Get the latest document for each document type using a subquery
            $sql = "SELECT d1.* FROM document_uploads d1
                    INNER JOIN (
                        SELECT document_type, MAX(upload_date) as max_date, user_id
                        FROM document_uploads
                        WHERE user_id = :user_id
                        GROUP BY document_type, user_id
                    ) d2 ON d1.document_type = d2.document_type 
                    AND d1.upload_date = d2.max_date
                    AND d1.user_id = d2.user_id
                    WHERE d1.user_id = :user_id
                    ORDER BY d1.upload_date DESC";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch(PDOException $e) {
            // Fallback to simple query if the above fails
            try {
                $sql = "SELECT * FROM document_uploads 
                        WHERE user_id = :user_id 
                        ORDER BY upload_date DESC";
                $stmt = $this->conn->prepare($sql);
                $stmt->bindParam(':user_id', $user_id);
                $stmt->execute();
                return $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch(PDOException $e2) {
                return [];
            }
        }
    }
    
    /**
     * Verify or reject document
     */
    public function updateDocumentStatus($document_id, $status, $rejection_reason = null) {
        try {
            $admission_id = $_SESSION['admission_id'];
            
            $sql = "UPDATE document_uploads 
                    SET verification_status = :status, 
                        rejection_reason = :rejection_reason,
                        verified_by = :admission_id,
                        verified_at = NOW()
                    WHERE id = :document_id";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':status', $status);
            $stmt->bindParam(':rejection_reason', $rejection_reason);
            $stmt->bindParam(':admission_id', $admission_id);
            $stmt->bindParam(':document_id', $document_id);
            
            if ($stmt->execute() && $status == 'rejected' && $rejection_reason) {
                // Log rejection
                $doc_sql = "SELECT user_id, document_type FROM document_uploads WHERE id = :document_id";
                $doc_stmt = $this->conn->prepare($doc_sql);
                $doc_stmt->bindParam(':document_id', $document_id);
                $doc_stmt->execute();
                $doc = $doc_stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($doc) {
                    $log_sql = "INSERT INTO document_rejection_history 
                                (user_id, document_type, rejection_reason, rejected_by)
                                VALUES (:user_id, :document_type, :rejection_reason, :admission_id)";
                    $log_stmt = $this->conn->prepare($log_sql);
                    $log_stmt->bindParam(':user_id', $doc['user_id']);
                    $log_stmt->bindParam(':document_type', $doc['document_type']);
                    $log_stmt->bindParam(':rejection_reason', $rejection_reason);
                    $log_stmt->bindParam(':admission_id', $admission_id);
                    $log_stmt->execute();
                }
            }
            
            return true;
            
        } catch(PDOException $e) {
            return false;
        }
    }
    
    /**
     * Get dashboard statistics
     */
    public function getDashboardStats() {
        try {
            $stats = [];
            
            // Total pending applications
            $sql = "SELECT COUNT(*) as total FROM users WHERE enrollment_status = 'pending'";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $stats['total_pending'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Pending review
            $sql = "SELECT COUNT(*) as total FROM application_workflow WHERE admission_status = 'pending_review'";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $stats['pending_review'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Documents incomplete
            $sql = "SELECT COUNT(*) as total FROM application_workflow WHERE admission_status = 'documents_incomplete'";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $stats['documents_incomplete'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Approved (ready for registrar)
            $sql = "SELECT COUNT(*) as total FROM application_workflow WHERE admission_status = 'approved' AND passed_to_registrar = 0";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $stats['approved'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Passed to registrar
            $sql = "SELECT COUNT(*) as total FROM application_workflow WHERE passed_to_registrar = 1";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $stats['passed_to_registrar'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            // Student numbers assigned
            $sql = "SELECT COUNT(*) as total FROM application_workflow WHERE student_number_assigned = 1";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            $stats['student_numbers_assigned'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            return $stats;
            
        } catch(PDOException $e) {
            return [
                'total_pending' => 0,
                'pending_review' => 0,
                'documents_incomplete' => 0,
                'approved' => 0,
                'passed_to_registrar' => 0,
                'student_numbers_assigned' => 0
            ];
        }
    }
}
?>

