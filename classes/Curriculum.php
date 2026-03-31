<?php
// Curriculum class for managing academic programs and curriculum

class Curriculum {
    private $conn;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    // Get all programs
    public function getAllPrograms() {
        try {
            $sql = "SELECT * FROM programs ORDER BY program_code";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch(PDOException $e) {
            return [];
        }
    }
    
    // Get program by ID
    public function getProgramById($program_id) {
        try {
            $sql = "SELECT * FROM programs WHERE id = :program_id";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':program_id', $program_id);
            $stmt->execute();
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch(PDOException $e) {
            return null;
        }
    }
    
    // Check if program can be deleted (has active enrollments or sections)
    public function canDeleteProgram($program_id) {
        try {
            // Check for sections with active enrollments
            $sections_sql = "SELECT COUNT(DISTINCT s.id) as section_count,
                            COUNT(DISTINCT se.id) as enrollment_count
                            FROM sections s
                            LEFT JOIN section_enrollments se ON s.id = se.section_id 
                            AND (se.status = 'active' OR se.status IS NULL)
                            WHERE s.program_id = :program_id";
            $sections_stmt = $this->conn->prepare($sections_sql);
            $sections_stmt->bindParam(':program_id', $program_id);
            $sections_stmt->execute();
            $sections_result = $sections_stmt->fetch(PDO::FETCH_ASSOC);
            
            $has_sections = ($sections_result['section_count'] ?? 0) > 0;
            $has_enrollments = ($sections_result['enrollment_count'] ?? 0) > 0;
            
            // Check for program heads assigned to this program
            $program_heads_sql = "SELECT COUNT(*) as count FROM program_heads WHERE program_id = :program_id";
            $program_heads_stmt = $this->conn->prepare($program_heads_sql);
            $program_heads_stmt->bindParam(':program_id', $program_id);
            $program_heads_stmt->execute();
            $program_heads_result = $program_heads_stmt->fetch(PDO::FETCH_ASSOC);
            $has_program_heads = ($program_heads_result['count'] ?? 0) > 0;
            
            return [
                'can_delete' => !$has_enrollments,
                'has_sections' => $has_sections,
                'has_enrollments' => $has_enrollments,
                'has_program_heads' => $has_program_heads,
                'section_count' => $sections_result['section_count'] ?? 0,
                'enrollment_count' => $sections_result['enrollment_count'] ?? 0,
                'program_head_count' => $program_heads_result['count'] ?? 0
            ];
            
        } catch(PDOException $e) {
            error_log('Error checking if program can be deleted: ' . $e->getMessage());
            return [
                'can_delete' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    // Delete program (with validation)
    public function deleteProgram($program_id) {
        try {
            // Check if program exists
            $program = $this->getProgramById($program_id);
            if (!$program) {
                return ['success' => false, 'message' => 'Program not found'];
            }
            
            // Check if program can be deleted
            $can_delete_check = $this->canDeleteProgram($program_id);
            if (!$can_delete_check['can_delete']) {
                $reasons = [];
                if ($can_delete_check['has_enrollments']) {
                    $reasons[] = "has {$can_delete_check['enrollment_count']} active student enrollment(s)";
                }
                if ($can_delete_check['has_sections']) {
                    $reasons[] = "has {$can_delete_check['section_count']} section(s)";
                }
                if ($can_delete_check['has_program_heads']) {
                    $reasons[] = "has {$can_delete_check['program_head_count']} program head(s) assigned";
                }
                
                return [
                    'success' => false,
                    'message' => 'Cannot delete program: ' . implode(', ', $reasons) . '. Please remove all enrollments and sections first.',
                    'details' => $can_delete_check
                ];
            }
            
            // Begin transaction
            $this->conn->beginTransaction();
            
            // Delete program (cascade will handle related records: curriculum, sections, program_heads, curriculum_submissions)
            $delete_sql = "DELETE FROM programs WHERE id = :program_id";
            $delete_stmt = $this->conn->prepare($delete_sql);
            $delete_stmt->bindParam(':program_id', $program_id);
            $delete_stmt->execute();
            
            if ($delete_stmt->rowCount() == 0) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'Program not found or already deleted'];
            }
            
            // Commit transaction
            $this->conn->commit();
            
            return [
                'success' => true,
                'message' => 'Program "' . htmlspecialchars($program['program_code']) . '" deleted successfully'
            ];
            
        } catch(PDOException $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            error_log('Error deleting program: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Database error: ' . $e->getMessage()
            ];
        }
    }
    
    // Add new program
    public function addProgram($data) {
        try {
            // Check if program code already exists
            $check_sql = "SELECT id FROM programs WHERE program_code = :program_code";
            $check_stmt = $this->conn->prepare($check_sql);
            $check_stmt->bindParam(':program_code', $data['program_code']);
            $check_stmt->execute();
            
            if ($check_stmt->fetch()) {
                return ['success' => false, 'message' => 'A program with the code "' . htmlspecialchars($data['program_code']) . '" already exists.'];
            }
            
            // Default total_units to 0 - will be calculated automatically from curriculum
            $total_units = $data['total_units'] ?? 0;
            
            $sql = "INSERT INTO programs (program_code, program_name, description, total_units, years_to_complete, status)
                    VALUES (:program_code, :program_name, :description, :total_units, :years_to_complete, :status)";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':program_code', $data['program_code']);
            $stmt->bindParam(':program_name', $data['program_name']);
            $stmt->bindParam(':description', $data['description']);
            $stmt->bindParam(':total_units', $total_units);
            $stmt->bindParam(':years_to_complete', $data['years_to_complete']);
            $stmt->bindParam(':status', $data['status']);
            
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'Program added successfully'];
            } else {
                return ['success' => false, 'message' => 'Failed to add program'];
            }
            
        } catch(PDOException $e) {
            // Check for duplicate key error (23000)
            if ($e->getCode() == 23000) {
                return ['success' => false, 'message' => 'A program with this code already exists.'];
            }
            error_log('Error adding program: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Calculate and update program total units from curriculum
    public function updateProgramTotalUnits($program_id, $connection = null) {
        try {
            // Use provided connection if available (for transactions), otherwise use class connection
            $conn = $connection ?? $this->conn;
            
            $sql = "UPDATE programs 
                    SET total_units = (
                        SELECT COALESCE(SUM(units), 0) 
                        FROM curriculum 
                        WHERE program_id = :program_id
                    )
                    WHERE id = :program_id";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':program_id', $program_id);
            
            $result = $stmt->execute();
            
            // Verify the update worked by checking the new value
            if ($result) {
                $check_sql = "SELECT total_units FROM programs WHERE id = :program_id";
                $check_stmt = $conn->prepare($check_sql);
                $check_stmt->bindParam(':program_id', $program_id);
                $check_stmt->execute();
                $program = $check_stmt->fetch(PDO::FETCH_ASSOC);
                error_log("Program {$program_id} total_units updated to: " . ($program['total_units'] ?? 'NULL'));
            }
            
            return $result;
            
        } catch(PDOException $e) {
            error_log('Error updating program total units: ' . $e->getMessage());
            return false;
        }
    }
    
    // Get curriculum by program
    public function getCurriculumByProgram($program_id) {
        try {
            $sql = "SELECT * FROM curriculum 
                    WHERE program_id = :program_id 
                    ORDER BY 
                        FIELD(year_level, '1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year'),
                        FIELD(semester, 'First Semester', 'Second Semester', 'Summer'),
                        course_code";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':program_id', $program_id);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch(PDOException $e) {
            return [];
        }
    }
    
    // Get curriculum by program, year, and semester
    public function getCurriculumByYearSemester($program_id, $year_level, $semester) {
        try {
            $sql = "SELECT * FROM curriculum 
                    WHERE program_id = :program_id 
                    AND year_level = :year_level 
                    AND semester = :semester
                    ORDER BY course_code";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':program_id', $program_id);
            $stmt->bindParam(':year_level', $year_level);
            $stmt->bindParam(':semester', $semester);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch(PDOException $e) {
            return [];
        }
    }
    
    // Add course to curriculum
    public function addCurriculumCourse($data, $connection = null) {
        try {
            // Use provided connection if available (for transactions), otherwise use class connection
            $conn = $connection ?? $this->conn;
            
            $sql = "INSERT INTO curriculum (program_id, course_code, course_name, units, year_level, semester, is_required, pre_requisites)
                    VALUES (:program_id, :course_code, :course_name, :units, :year_level, :semester, :is_required, :pre_requisites)";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':program_id', $data['program_id']);
            $stmt->bindParam(':course_code', $data['course_code']);
            $stmt->bindParam(':course_name', $data['course_name']);
            $stmt->bindParam(':units', $data['units']);
            $stmt->bindParam(':year_level', $data['year_level']);
            $stmt->bindParam(':semester', $data['semester']);
            $stmt->bindParam(':is_required', $data['is_required']);
            $stmt->bindParam(':pre_requisites', $data['pre_requisites']);
            
            $result = $stmt->execute();
            
            // Update program total units after adding course (use same connection)
            if ($result) {
                $this->updateProgramTotalUnits($data['program_id'], $conn);
            }
            
            return $result;
            
        } catch(PDOException $e) {
            error_log('Error adding curriculum course: ' . $e->getMessage());
            return false;
        }
    }
    
    // Update curriculum course
    public function updateCurriculumCourse($id, $data, $connection = null) {
        try {
            // Use provided connection if available (for transactions), otherwise use class connection
            $conn = $connection ?? $this->conn;
            
            // Get program_id before updating
            $get_sql = "SELECT program_id FROM curriculum WHERE id = :id";
            $get_stmt = $conn->prepare($get_sql);
            $get_stmt->bindParam(':id', $id);
            $get_stmt->execute();
            $course = $get_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$course) {
                return false;
            }
            
            $program_id = $course['program_id'];
            
            $sql = "UPDATE curriculum 
                    SET course_code = :course_code,
                        course_name = :course_name,
                        units = :units,
                        year_level = :year_level,
                        semester = :semester,
                        is_required = :is_required,
                        pre_requisites = :pre_requisites
                    WHERE id = :id";
            
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':id', $id);
            $stmt->bindParam(':course_code', $data['course_code']);
            $stmt->bindParam(':course_name', $data['course_name']);
            $stmt->bindParam(':units', $data['units']);
            $stmt->bindParam(':year_level', $data['year_level']);
            $stmt->bindParam(':semester', $data['semester']);
            $stmt->bindParam(':is_required', $data['is_required']);
            $stmt->bindParam(':pre_requisites', $data['pre_requisites']);
            
            $result = $stmt->execute();
            
            // Update program total units after updating course (units may have changed, use same connection)
            if ($result) {
                $this->updateProgramTotalUnits($program_id, $conn);
            }
            
            return $result;
            
        } catch(PDOException $e) {
            error_log('Error updating curriculum course: ' . $e->getMessage());
            return false;
        }
    }
    
    // Delete curriculum course
    public function deleteCurriculumCourse($id, $connection = null) {
        try {
            // Use provided connection if available (for transactions), otherwise use class connection
            $conn = $connection ?? $this->conn;
            
            // Get program_id before deleting
            $get_sql = "SELECT program_id FROM curriculum WHERE id = :id";
            $get_stmt = $conn->prepare($get_sql);
            $get_stmt->bindParam(':id', $id);
            $get_stmt->execute();
            $course = $get_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$course) {
                return false;
            }
            
            $program_id = $course['program_id'];
            
            // Delete the course
            $sql = "DELETE FROM curriculum WHERE id = :id";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':id', $id);
            
            $result = $stmt->execute();
            
            // Update program total units after deletion (use same connection)
            if ($result) {
                $this->updateProgramTotalUnits($program_id, $conn);
            }
            
            return $result;
            
        } catch(PDOException $e) {
            error_log('Error deleting curriculum course: ' . $e->getMessage());
            return false;
        }
    }
    
    // Delete all curriculum for a program
    public function deleteAllCurriculumForProgram($program_id, $connection = null) {
        try {
            // Use provided connection if available (for transactions), otherwise use class connection
            $conn = $connection ?? $this->conn;
            
            // Count courses before deletion
            $count_sql = "SELECT COUNT(*) as count FROM curriculum WHERE program_id = :program_id";
            $count_stmt = $conn->prepare($count_sql);
            $count_stmt->bindParam(':program_id', $program_id);
            $count_stmt->execute();
            $count_result = $count_stmt->fetch(PDO::FETCH_ASSOC);
            $deleted_count = $count_result['count'] ?? 0;
            
            // Delete all courses for this program
            $sql = "DELETE FROM curriculum WHERE program_id = :program_id";
            $stmt = $conn->prepare($sql);
            $stmt->bindParam(':program_id', $program_id);
            
            $result = $stmt->execute();
            
            // Update program total units after deletion (will be 0, use same connection)
            if ($result) {
                $this->updateProgramTotalUnits($program_id, $conn);
            }
            
            return [
                'success' => $result,
                'deleted_count' => $deleted_count
            ];
            
        } catch(PDOException $e) {
            error_log('Error deleting all curriculum: ' . $e->getMessage());
            return [
                'success' => false,
                'deleted_count' => 0,
                'message' => $e->getMessage()
            ];
        }
    }
    
    // Get curriculum summary (total units per year/semester)
    public function getCurriculumSummary($program_id) {
        try {
            $sql = "SELECT 
                        year_level, 
                        semester, 
                        COUNT(*) as course_count,
                        SUM(units) as total_units
                    FROM curriculum 
                    WHERE program_id = :program_id
                    GROUP BY year_level, semester
                    ORDER BY 
                        FIELD(year_level, '1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year'),
                        FIELD(semester, 'First Semester', 'Second Semester', 'Summer')";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':program_id', $program_id);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch(PDOException $e) {
            return [];
        }
    }
}
?>

