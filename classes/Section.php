<?php
// Section class for managing class sections

class Section {
    private $conn;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
    }
    
    // Get all sections
    public function getAllSections() {
        try {
            $sql = "SELECT s.*, p.program_code, p.program_name
                    FROM sections s
                    JOIN programs p ON s.program_id = p.id
                    ORDER BY p.program_code, s.year_level, s.semester, s.section_type";
            $stmt = $this->conn->prepare($sql);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch(PDOException $e) {
            return [];
        }
    }
    
    // Get sections by program
    public function getSectionsByProgram($program_id) {
        try {
            $sql = "SELECT * FROM sections 
                    WHERE program_id = :program_id 
                    ORDER BY year_level, semester, section_type";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':program_id', $program_id);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch(PDOException $e) {
            return [];
        }
    }
    
    // Get section by ID
    public function getSectionById($section_id) {
        try {
            $sql = "SELECT s.*, p.program_code, p.program_name
                    FROM sections s
                    JOIN programs p ON s.program_id = p.id
                    WHERE s.id = :section_id";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':section_id', $section_id);
            $stmt->execute();
            
            return $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch(PDOException $e) {
            return null;
        }
    }
    
    // Add new section
    public function addSection($data) {
        try {
            // Auto-generate section name based on format: YY + ProgramCode + Number + TypeCode
            // Format: Last 2 digits of Academic Year + Program Code + Section Number + Type Code (M/P/E)
            
            // Get program code
            $program_sql = "SELECT program_code FROM programs WHERE id = :program_id";
            $program_stmt = $this->conn->prepare($program_sql);
            $program_stmt->bindParam(':program_id', $data['program_id']);
            $program_stmt->execute();
            $program = $program_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$program) {
                return ['success' => false, 'message' => 'Program not found'];
            }
            
            $program_code = $program['program_code'];
            
            // Extract last 2 digits from academic year
            // Handle formats like "AY 2024-2025", "2024-2025", "AY2024-2025", etc.
            // Use the starting year's last 2 digits (e.g., "2024" -> "24")
            $academic_year = $data['academic_year'];
            $year_suffix = '';
            
            // Try to match 4-digit year pattern
            if (preg_match('/(\d{4})/', $academic_year, $year_matches)) {
                $year = $year_matches[1];
                $year_suffix = substr($year, -2); // Last 2 digits of the year
            } else {
                return ['success' => false, 'message' => 'Invalid academic year format. Please use format like "AY 2024-2025"'];
            }
            
            // Map section type to code
            $type_map = [
                'Morning' => 'M',
                'Afternoon' => 'P',
                'Evening' => 'E'
            ];
            
            $type_code = $type_map[$data['section_type']] ?? 'M';
            
            // Count existing sections with same program, year level, semester, section type, and academic year
            $count_sql = "SELECT COUNT(*) as count FROM sections 
                        WHERE program_id = :program_id 
                        AND year_level = :year_level 
                        AND semester = :semester 
                        AND section_type = :section_type 
                        AND academic_year = :academic_year";
            $count_stmt = $this->conn->prepare($count_sql);
            $count_stmt->bindParam(':program_id', $data['program_id']);
            $count_stmt->bindParam(':year_level', $data['year_level']);
            $count_stmt->bindParam(':semester', $data['semester']);
            $count_stmt->bindParam(':section_type', $data['section_type']);
            $count_stmt->bindParam(':academic_year', $data['academic_year']);
            $count_stmt->execute();
            $count_result = $count_stmt->fetch(PDO::FETCH_ASSOC);
            $section_number = ($count_result['count'] ?? 0) + 1;
            
            // Generate section name: YY + ProgramCode + Number + TypeCode
            $generated_section_name = $year_suffix . $program_code . $section_number . $type_code;
            
            // Check if section with same name already exists in the same academic year
            $check_sql = "SELECT id, section_name FROM sections 
                         WHERE section_name = :section_name 
                         AND academic_year = :academic_year";
            $check_stmt = $this->conn->prepare($check_sql);
            $check_stmt->bindParam(':section_name', $generated_section_name);
            $check_stmt->bindParam(':academic_year', $data['academic_year']);
            $check_stmt->execute();
            
            $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);
            if ($existing) {
                // If generated name exists, increment section number and try again
                $section_number++;
                $generated_section_name = $year_suffix . $program_code . $section_number . $type_code;
            }
            
            // Use the generated section name
            $data['section_name'] = $generated_section_name;
            
            $sql = "INSERT INTO sections (program_id, year_level, semester, section_name, section_type, max_capacity, academic_year, status)
                    VALUES (:program_id, :year_level, :semester, :section_name, :section_type, :max_capacity, :academic_year, :status)";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':program_id', $data['program_id']);
            $stmt->bindParam(':year_level', $data['year_level']);
            $stmt->bindParam(':semester', $data['semester']);
            $stmt->bindParam(':section_name', $data['section_name']);
            $stmt->bindParam(':section_type', $data['section_type']);
            $stmt->bindParam(':max_capacity', $data['max_capacity']);
            $stmt->bindParam(':academic_year', $data['academic_year']);
            $stmt->bindParam(':status', $data['status']);
            
            if ($stmt->execute()) {
                return ['success' => true, 'message' => 'Section "' . $generated_section_name . '" added successfully'];
            } else {
                return ['success' => false, 'message' => 'Failed to add section'];
            }
            
        } catch(PDOException $e) {
            // Check for duplicate key error (23000)
            if ($e->getCode() == 23000) {
                return ['success' => false, 'message' => 'A section with this name already exists for this academic year. Please use a different section name.'];
            }
            error_log('Error adding section: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Update section
    public function updateSection($section_id, $data) {
        try {
            $sql = "UPDATE sections 
                    SET section_name = :section_name,
                        max_capacity = :max_capacity,
                        status = :status
                    WHERE id = :section_id";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':section_id', $section_id);
            $stmt->bindParam(':section_name', $data['section_name']);
            $stmt->bindParam(':max_capacity', $data['max_capacity']);
            $stmt->bindParam(':status', $data['status']);
            
            return $stmt->execute();
            
        } catch(PDOException $e) {
            return false;
        }
    }
    
    // Delete section
    public function deleteSection($section_id) {
        try {
            $sql = "DELETE FROM sections WHERE id = :section_id";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':section_id', $section_id);
            
            return $stmt->execute();
            
        } catch(PDOException $e) {
            return false;
        }
    }
    
    // Get students in a section
    public function getStudentsInSection($section_id) {
        try {
            $sql = "SELECT 
                        u.id,
                        u.student_id,
                        u.first_name,
                        u.middle_name,
                        u.last_name,
                        u.email,
                        u.contact_number,
                        u.address,
                        u.permanent_address,
                        u.municipality_city,
                        u.barangay,
                        se.enrolled_date,
                        COALESCE(es.student_type, 'Regular') as student_type,
                        es.year_level as enrolled_year_level
                    FROM section_enrollments se
                    JOIN users u ON se.user_id = u.id
                    LEFT JOIN enrolled_students es ON u.id = es.user_id
                    WHERE se.section_id = :section_id AND se.status = 'active'
                    ORDER BY u.last_name, u.first_name";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':section_id', $section_id);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch(PDOException $e) {
            return [];
        }
    }
    
    // ===== SCHEDULE MANAGEMENT =====
    
    // Get schedule for a section
    public function getSectionSchedule($section_id) {
        try {
            $sql = "SELECT * FROM section_schedules 
                    WHERE section_id = :section_id 
                    ORDER BY course_code";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':section_id', $section_id);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch(PDOException $e) {
            return [];
        }
    }
    
    // Add course to section schedule
    public function addSchedule($data) {
        try {
            $sql = "INSERT INTO section_schedules 
                    (section_id, curriculum_id, course_code, course_name, units, 
                     schedule_monday, schedule_tuesday, schedule_wednesday, schedule_thursday, 
                     schedule_friday, schedule_saturday, schedule_sunday,
                     time_start, time_end, room, professor_name, professor_initial)
                    VALUES 
                    (:section_id, :curriculum_id, :course_code, :course_name, :units,
                     :schedule_monday, :schedule_tuesday, :schedule_wednesday, :schedule_thursday,
                     :schedule_friday, :schedule_saturday, :schedule_sunday,
                     :time_start, :time_end, :room, :professor_name, :professor_initial)";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':section_id', $data['section_id']);
            $stmt->bindParam(':curriculum_id', $data['curriculum_id']);
            $stmt->bindParam(':course_code', $data['course_code']);
            $stmt->bindParam(':course_name', $data['course_name']);
            $stmt->bindParam(':units', $data['units']);
            $stmt->bindParam(':schedule_monday', $data['schedule_monday']);
            $stmt->bindParam(':schedule_tuesday', $data['schedule_tuesday']);
            $stmt->bindParam(':schedule_wednesday', $data['schedule_wednesday']);
            $stmt->bindParam(':schedule_thursday', $data['schedule_thursday']);
            $stmt->bindParam(':schedule_friday', $data['schedule_friday']);
            $stmt->bindParam(':schedule_saturday', $data['schedule_saturday']);
            $stmt->bindParam(':schedule_sunday', $data['schedule_sunday']);
            $stmt->bindParam(':time_start', $data['time_start']);
            $stmt->bindParam(':time_end', $data['time_end']);
            $stmt->bindParam(':room', $data['room']);
            $stmt->bindParam(':professor_name', $data['professor_name']);
            $stmt->bindParam(':professor_initial', $data['professor_initial']);
            
            return $stmt->execute();
            
        } catch(PDOException $e) {
            return false;
        }
    }
    
    // Update schedule
    public function updateSchedule($schedule_id, $data) {
        try {
            $sql = "UPDATE section_schedules 
                    SET schedule_monday = :schedule_monday,
                        schedule_tuesday = :schedule_tuesday,
                        schedule_wednesday = :schedule_wednesday,
                        schedule_thursday = :schedule_thursday,
                        schedule_friday = :schedule_friday,
                        schedule_saturday = :schedule_saturday,
                        schedule_sunday = :schedule_sunday,
                        time_start = :time_start,
                        time_end = :time_end,
                        room = :room,
                        professor_name = :professor_name,
                        professor_initial = :professor_initial
                    WHERE id = :schedule_id";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':schedule_id', $schedule_id);
            $stmt->bindParam(':schedule_monday', $data['schedule_monday']);
            $stmt->bindParam(':schedule_tuesday', $data['schedule_tuesday']);
            $stmt->bindParam(':schedule_wednesday', $data['schedule_wednesday']);
            $stmt->bindParam(':schedule_thursday', $data['schedule_thursday']);
            $stmt->bindParam(':schedule_friday', $data['schedule_friday']);
            $stmt->bindParam(':schedule_saturday', $data['schedule_saturday']);
            $stmt->bindParam(':schedule_sunday', $data['schedule_sunday']);
            $stmt->bindParam(':time_start', $data['time_start']);
            $stmt->bindParam(':time_end', $data['time_end']);
            $stmt->bindParam(':room', $data['room']);
            $stmt->bindParam(':professor_name', $data['professor_name']);
            $stmt->bindParam(':professor_initial', $data['professor_initial']);
            
            return $stmt->execute();
            
        } catch(PDOException $e) {
            return false;
        }
    }
    
    // Delete schedule entry
    public function deleteSchedule($schedule_id) {
        try {
            $sql = "DELETE FROM section_schedules WHERE id = :schedule_id";
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':schedule_id', $schedule_id);
            
            return $stmt->execute();
            
        } catch(PDOException $e) {
            return false;
        }
    }
    
    // Get available curriculum courses for a section
    public function getAvailableCurriculumCourses($program_id, $year_level, $semester) {
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
    
    // ===== STUDENT ENROLLMENT IN SECTIONS =====
    
    // Assign student to section
    public function assignStudentToSection($user_id, $section_id) {
        try {
            // Check if section exists and has capacity
            $section = $this->getSectionById($section_id);
            if (!$section) {
                return ['success' => false, 'message' => 'Section not found'];
            }
            
            if ($section['current_enrolled'] >= $section['max_capacity']) {
                return ['success' => false, 'message' => 'Section is full'];
            }
            
            // Check if student is already enrolled in this section
            $check_sql = "SELECT id FROM section_enrollments 
                         WHERE user_id = :user_id AND section_id = :section_id AND status = 'active'";
            $check_stmt = $this->conn->prepare($check_sql);
            $check_stmt->bindParam(':user_id', $user_id);
            $check_stmt->bindParam(':section_id', $section_id);
            $check_stmt->execute();
            
            if ($check_stmt->fetch()) {
                return ['success' => false, 'message' => 'Student is already enrolled in this section'];
            }
            
            // Begin transaction
            $this->conn->beginTransaction();
            
            // Insert into section_enrollments
            $insert_sql = "INSERT INTO section_enrollments (section_id, user_id, enrolled_date, status) 
                          VALUES (:section_id, :user_id, NOW(), 'active')";
            $insert_stmt = $this->conn->prepare($insert_sql);
            $insert_stmt->bindParam(':section_id', $section_id);
            $insert_stmt->bindParam(':user_id', $user_id);
            $insert_stmt->execute();
            
            // Update section current_enrolled count
            $update_sql = "UPDATE sections SET current_enrolled = current_enrolled + 1 WHERE id = :section_id";
            $update_stmt = $this->conn->prepare($update_sql);
            $update_stmt->bindParam(':section_id', $section_id);
            $update_stmt->execute();
            
            // Sync user data to enrolled_students table with section information
            require_once __DIR__ . '/../admin/sync_user_to_enrolled_students.php';
            syncUserToEnrolledStudents($this->conn, $user_id, [
                'course' => $section['program_code'],
                'year_level' => $section['year_level'],
                'semester' => $section['semester'],
                'academic_year' => $section['academic_year']
            ]);
            
            // Commit transaction
            $this->conn->commit();
            
            return ['success' => true, 'message' => 'Student assigned to section successfully'];
            
        } catch(PDOException $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Remove student from section
    public function removeStudentFromSection($user_id, $section_id) {
        try {
            // Begin transaction
            $this->conn->beginTransaction();
            
            // Update section_enrollments status to 'dropped'
            $update_sql = "UPDATE section_enrollments 
                          SET status = 'dropped' 
                          WHERE user_id = :user_id AND section_id = :section_id AND status = 'active'";
            $update_stmt = $this->conn->prepare($update_sql);
            $update_stmt->bindParam(':user_id', $user_id);
            $update_stmt->bindParam(':section_id', $section_id);
            $update_stmt->execute();
            
            if ($update_stmt->rowCount() == 0) {
                $this->conn->rollBack();
                return ['success' => false, 'message' => 'Section enrollment not found'];
            }
            
            // Update section current_enrolled count
            $update_section_sql = "UPDATE sections SET current_enrolled = current_enrolled - 1 WHERE id = :section_id";
            $update_section_stmt = $this->conn->prepare($update_section_sql);
            $update_section_stmt->bindParam(':section_id', $section_id);
            $update_section_stmt->execute();
            
            // Commit transaction
            $this->conn->commit();
            
            return ['success' => true, 'message' => 'Student removed from section successfully'];
            
        } catch(PDOException $e) {
            if ($this->conn->inTransaction()) {
                $this->conn->rollBack();
            }
            return ['success' => false, 'message' => 'Database error: ' . $e->getMessage()];
        }
    }
    
    // Get student's assigned sections
    public function getStudentSections($user_id) {
        try {
            $sql = "SELECT se.id as enrollment_id, se.section_id, se.enrolled_date, 
                    s.section_name, s.year_level, s.semester, s.academic_year,
                    s.current_enrolled, s.max_capacity, s.section_type,
                    p.program_code, p.program_name
                    FROM section_enrollments se
                    JOIN sections s ON se.section_id = s.id
                    JOIN programs p ON s.program_id = p.id
                    WHERE se.user_id = :user_id AND se.status = 'active'
                    ORDER BY se.enrolled_date DESC";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch(PDOException $e) {
            return [];
        }
    }
    
    /**
     * Find sections offering a specific curriculum/subject
     * 
     * @param int $curriculum_id Curriculum ID
     * @param string $semester Target semester
     * @param string $academic_year Target academic year
     * @param int|null $program_id Optional program filter
     * @param string|null $preferred_shift Optional preferred shift filter
     * @return array Array of sections with available capacity
     */
    public function findSectionsForSubject($curriculum_id, $semester, $academic_year, $program_id = null, $preferred_shift = null) {
        try {
            require_once __DIR__ . '/../config/section_assignment_helper.php';
            return findSectionsForSubject(
                $this->conn,
                $curriculum_id,
                $semester,
                $academic_year,
                $program_id,
                $preferred_shift
            );
        } catch(PDOException $e) {
            error_log('Error in findSectionsForSubject: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get section schedule by curriculum ID and section ID
     * 
     * @param int $section_id Section ID
     * @param int $curriculum_id Curriculum ID
     * @return array|null Section schedule or null if not found
     */
    public function getSectionScheduleByCurriculum($section_id, $curriculum_id) {
        try {
            $sql = "SELECT ss.*, s.section_name, s.section_type
                    FROM section_schedules ss
                    JOIN sections s ON ss.section_id = s.id
                    WHERE ss.section_id = :section_id
                    AND ss.curriculum_id = :curriculum_id
                    LIMIT 1";
            
            $stmt = $this->conn->prepare($sql);
            $stmt->bindParam(':section_id', $section_id, PDO::PARAM_INT);
            $stmt->bindParam(':curriculum_id', $curriculum_id, PDO::PARAM_INT);
            $stmt->execute();
            
            return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            
        } catch(PDOException $e) {
            error_log('Error in getSectionScheduleByCurriculum: ' . $e->getMessage());
            return null;
        }
    }
    
}
?>


