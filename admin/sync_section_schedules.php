<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';

// Check if user is logged in and is an admin
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

header('Content-Type: application/json');

$section_id = $_POST['section_id'] ?? null;

if (!$section_id) {
    echo json_encode(['success' => false, 'message' => 'Section ID required']);
    exit();
}

try {
    $conn = (new Database())->getConnection();
    
    // Get section details including program, year_level, and semester
    $section_sql = "SELECT s.*, p.id as program_id
                    FROM sections s
                    JOIN programs p ON s.program_id = p.id
                    WHERE s.id = :section_id";
    $section_stmt = $conn->prepare($section_sql);
    $section_stmt->bindParam(':section_id', $section_id);
    $section_stmt->execute();
    $section = $section_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$section) {
        echo json_encode(['success' => false, 'message' => 'Section not found']);
        exit();
    }
    
    // Get all curriculum courses for this section's program, year_level, and semester
    $curriculum_sql = "SELECT id, course_code, course_name, units
                       FROM curriculum
                       WHERE program_id = :program_id
                       AND year_level = :year_level
                       AND semester = :semester
                       ORDER BY course_code";
    $curriculum_stmt = $conn->prepare($curriculum_sql);
    $curriculum_stmt->bindParam(':program_id', $section['program_id']);
    $curriculum_stmt->bindParam(':year_level', $section['year_level']);
    $curriculum_stmt->bindParam(':semester', $section['semester']);
    $curriculum_stmt->execute();
    $curriculum_courses = $curriculum_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $added_count = 0;
    $skipped_count = 0;
    
    // Insert each curriculum course into section_schedules if not already present
    foreach ($curriculum_courses as $course) {
        // Check if this course is already in the schedule
        $check_sql = "SELECT id FROM section_schedules 
                      WHERE section_id = :section_id AND curriculum_id = :curriculum_id";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bindParam(':section_id', $section_id);
        $check_stmt->bindParam(':curriculum_id', $course['id']);
        $check_stmt->execute();
        
        if ($check_stmt->rowCount() == 0) {
            // Insert with default/empty schedule details (admin will fill these in)
            $insert_sql = "INSERT INTO section_schedules 
                          (section_id, curriculum_id, course_code, course_name, units,
                           schedule_monday, schedule_tuesday, schedule_wednesday, schedule_thursday,
                           schedule_friday, schedule_saturday, schedule_sunday,
                           time_start, time_end, room, professor_name, professor_initial)
                          VALUES 
                          (:section_id, :curriculum_id, :course_code, :course_name, :units,
                           0, 0, 0, 0, 0, 0, 0,
                           '', '', '', '', '')";
            $insert_stmt = $conn->prepare($insert_sql);
            $insert_stmt->bindParam(':section_id', $section_id);
            $insert_stmt->bindParam(':curriculum_id', $course['id']);
            $insert_stmt->bindParam(':course_code', $course['course_code']);
            $insert_stmt->bindParam(':course_name', $course['course_name']);
            $insert_stmt->bindParam(':units', $course['units']);
            $insert_stmt->execute();
            $added_count++;
        } else {
            $skipped_count++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Synced: $added_count course(s) added, $skipped_count already present",
        'added' => $added_count,
        'skipped' => $skipped_count
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>



