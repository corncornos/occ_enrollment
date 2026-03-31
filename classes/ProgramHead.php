<?php
// ProgramHead class for program head operations

class ProgramHead {
    private $conn;

    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }

    public function login($email, $password) {
        try {
            $sql = "SELECT ph.id, ph.username, ph.email, ph.password, ph.first_name, ph.last_name,
                           ph.program_id, ph.status, p.program_name, p.program_code
                    FROM program_heads ph
                    JOIN programs p ON ph.program_id = p.id
                    WHERE ph.email = :email";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':email', $email);
            $stmt->execute();

            if ($stmt->rowCount() == 1) {
                $programHead = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($programHead['status'] !== 'active') {
                    return ['success' => false, 'message' => 'Account is not active. Please contact system administrator.'];
                }

                if (password_verify($password, $programHead['password'])) {
                    $_SESSION['user_id'] = $programHead['id'];
                    $_SESSION['username'] = $programHead['username'];
                    $_SESSION['first_name'] = $programHead['first_name'];
                    $_SESSION['last_name'] = $programHead['last_name'];
                    $_SESSION['email'] = $programHead['email'];
                    $_SESSION['role'] = 'program_head';
                    $_SESSION['program_id'] = $programHead['program_id'];
                    $_SESSION['program_name'] = $programHead['program_name'];
                    $_SESSION['program_code'] = $programHead['program_code'];
                    $_SESSION['is_program_head'] = true;

                    return ['success' => true, 'user' => $programHead];
                } else {
                    return ['success' => false, 'message' => 'Invalid password'];
                }
            } else {
                return ['success' => false, 'message' => 'Program head account not found'];
            }

        } catch(PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }

    public function getProgramHeadById($program_head_id) {
        try {
            $sql = "SELECT ph.*, p.program_name, p.program_code
                    FROM program_heads ph
                    JOIN programs p ON ph.program_id = p.id
                    WHERE ph.id = :program_head_id";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':program_head_id', $program_head_id);
            $stmt->execute();

            return $stmt->fetch(PDO::FETCH_ASSOC);

        } catch(PDOException $e) {
            return null;
        }
    }

    public function getAllProgramHeads() {
        try {
            $sql = "SELECT ph.id, ph.username, ph.first_name, ph.last_name, ph.email,
                           ph.program_id, ph.status, ph.created_at,
                           p.program_name, p.program_code
                    FROM program_heads ph
                    JOIN programs p ON ph.program_id = p.id
                    ORDER BY ph.created_at DESC";
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

    // Curriculum Submission Methods
    public function createCurriculumSubmission($data) {
        try {
            // Create submission
            $sql = "INSERT INTO curriculum_submissions
                    (program_head_id, program_id, submission_title, submission_description,
                     academic_year, semester, status)
                    VALUES (:program_head_id, :program_id, :submission_title, :submission_description,
                            :academic_year, :semester, 'draft')";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute([
                ':program_head_id' => $data['program_head_id'],
                ':program_id' => $data['program_id'],
                ':submission_title' => $data['submission_title'],
                ':submission_description' => $data['submission_description'],
                ':academic_year' => $data['academic_year'],
                ':semester' => $data['semester']
            ]);

            $submissionId = $this->conn->lastInsertId();

            // Add curriculum items if provided
            if (isset($data['curriculum_items']) && is_array($data['curriculum_items'])) {
                $this->addCurriculumItems($submissionId, $data['curriculum_items']);
            }

            return ['success' => true, 'submission_id' => $submissionId];

        } catch(PDOException $e) {
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }

    public function addCurriculumItems($submissionId, $items) {
        try {
            $sql = "INSERT INTO curriculum_submission_items
                    (submission_id, course_code, course_name, units, year_level, semester, is_required, pre_requisites)
                    VALUES (:submission_id, :course_code, :course_name, :units, :year_level, :semester, :is_required, :pre_requisites)";

            $stmt = $this->conn->prepare($sql);

            foreach ($items as $item) {
                $stmt->execute([
                    ':submission_id' => $submissionId,
                    ':course_code' => $item['course_code'],
                    ':course_name' => $item['course_name'],
                    ':units' => $item['units'] ?? 3,
                    ':year_level' => $item['year_level'],
                    ':semester' => $item['semester'],
                    ':is_required' => isset($item['is_required']) ? $item['is_required'] : 1,
                    ':pre_requisites' => $item['pre_requisites'] ?? null
                ]);
            }

            return ['success' => true];

        } catch(PDOException $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function getCurriculumSubmissions($program_head_id = null) {
        try {
            $sql = "SELECT cs.*, ph.first_name, ph.last_name, p.program_name, p.program_code,
                           a.first_name as reviewer_first_name, a.last_name as reviewer_last_name
                    FROM curriculum_submissions cs
                    JOIN program_heads ph ON cs.program_head_id = ph.id
                    JOIN programs p ON cs.program_id = p.id
                    LEFT JOIN admins a ON cs.reviewed_by = a.id";

            $params = [];
            if ($program_head_id) {
                $sql .= " WHERE cs.program_head_id = :program_head_id";
                $params[':program_head_id'] = $program_head_id;
            }

            $sql .= " ORDER BY cs.created_at DESC";

            $stmt = $this->conn->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch(PDOException $e) {
            return [];
        }
    }

    public function getCurriculumSubmissionItems($submission_id) {
        try {
            $sql = "SELECT * FROM curriculum_submission_items
                    WHERE submission_id = :submission_id
                    ORDER BY year_level, semester, course_code";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':submission_id', $submission_id);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch(PDOException $e) {
            return [];
        }
    }

    public function submitCurriculumToRegistrar($submission_id) {
        try {
            $sql = "UPDATE curriculum_submissions
                    SET status = 'submitted', submitted_at = NOW()
                    WHERE id = :submission_id AND status = 'draft'";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':submission_id', $submission_id);
            $stmt->execute();

            return $stmt->rowCount() > 0;

        } catch(PDOException $e) {
            return false;
        }
    }

    public function getExistingCurriculum($program_id) {
        try {
            $sql = "SELECT * FROM curriculum
                    WHERE program_id = :program_id
                    ORDER BY year_level, semester, course_code";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':program_id', $program_id);
            $stmt->execute();

            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch(PDOException $e) {
            return [];
        }
    }

    // Bulk import functionality
    public function processBulkImport($program_head_id, $file_path, $academic_year, $semester) {
        try {
            $this->conn->beginTransaction();

            $program_id = $_SESSION['program_id'];

            // Create submission
            $submissionData = [
                'program_head_id' => $program_head_id,
                'program_id' => $program_id,
                'submission_title' => 'Bulk Import - ' . date('Y-m-d H:i:s'),
                'submission_description' => 'Bulk imported curriculum from CSV file',
                'academic_year' => $academic_year,
                'semester' => $semester
            ];

            $result = $this->createCurriculumSubmission($submissionData);
            if (!$result['success']) {
                throw new Exception($result['message']);
            }

            $submissionId = $result['submission_id'];

            // Parse CSV and add items
            $items = $this->parseCurriculumCSV($file_path);
            if (empty($items)) {
                throw new Exception('No valid curriculum items found in CSV file');
            }

            // Add items to submission (for tracking)
            $result = $this->addCurriculumItems($submissionId, $items);
            if (!$result['success']) {
                throw new Exception($result['message']);
            }

            // Update submission status to 'submitted' for dean review
            // Items will be added to curriculum table only after dean approval
            $update_stmt = $this->conn->prepare("UPDATE curriculum_submissions
                SET status = 'submitted', 
                    submitted_at = NOW(),
                    submitted_by = :program_head_id,
                    admin_approved = 1,
                    admin_approved_at = NOW(),
                    dean_approved = 0
                WHERE id = :submission_id");
            $update_stmt->execute([
                ':submission_id' => $submissionId,
                ':program_head_id' => $program_head_id
            ]);

            $this->conn->commit();
            return [
                'success' => true, 
                'submission_id' => $submissionId, 
                'items_count' => count($items),
                'message' => 'Curriculum submitted successfully and pending dean approval'
            ];

        } catch(Exception $e) {
            // Only rollback if there's an active transaction
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function parseCurriculumCSV($file_path) {
        $items = [];
        $handle = fopen($file_path, 'r');

        if ($handle === false) {
            return $items;
        }

        // Read header row to determine format
        $header = fgetcsv($handle);
        if (!$header) {
            fclose($handle);
            return $items;
        }

        // Normalize header to lowercase for comparison
        $header_lower = array_map('strtolower', array_map('trim', $header));
        
        // Determine if first column is program_code
        $has_program_code = false;
        $offset = 0;
        if (isset($header_lower[0]) && in_array($header_lower[0], ['program_code', 'program code', 'program'])) {
            $has_program_code = true;
            $offset = 1; // Skip program_code column
        }

        // Expected columns (after program_code if present): course_code, course_name, units, year_level, semester, is_required, pre_requisites
        while (($data = fgetcsv($handle)) !== false) {
            // Need at least 6 columns (course_code through is_required) plus offset
            if (count($data) >= (6 + $offset)) {
                $course_code = trim($data[0 + $offset]);
                $course_name = trim($data[1 + $offset]);
                $units = (int)trim($data[2 + $offset]);
                $year_level = trim($data[3 + $offset]);
                $semester = trim($data[4 + $offset]);
                
                // Skip empty rows
                if (empty($course_code) || empty($course_name)) {
                    continue;
                }
                
                // Parse is_required: can be 1/0, Yes/No, true/false
                $is_required_str = isset($data[5 + $offset]) ? trim($data[5 + $offset]) : '1';
                $is_required = 1;
                if (in_array(strtolower($is_required_str), ['no', '0', 'false', 'n'])) {
                    $is_required = 0;
                }
                
                $pre_requisites = isset($data[6 + $offset]) ? trim($data[6 + $offset]) : null;
                
                $items[] = [
                    'course_code' => $course_code,
                    'course_name' => $course_name,
                    'units' => $units > 0 ? $units : 3, // Default to 3 if invalid
                    'year_level' => $year_level,
                    'semester' => $semester,
                    'is_required' => $is_required,
                    'pre_requisites' => !empty($pre_requisites) ? $pre_requisites : null
                ];
            }
        }

        fclose($handle);
        return $items;
    }
}
?>
