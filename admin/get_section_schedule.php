<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';

// Check if user is logged in and is an admin
if (!isLoggedIn() || !isAdmin()) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

if (!isset($_GET['section_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Section ID required']);
    exit;
}

$section_id = $_GET['section_id'];

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
        http_response_code(404);
        echo json_encode(['error' => 'Section not found']);
        exit;
    }
    
    // Get all curriculum courses for this section's program, year_level, and semester
    // LEFT JOIN with section_schedules to include both scheduled and unscheduled courses
    $schedule_sql = "SELECT 
                        c.id as curriculum_id,
                        c.course_code,
                        c.course_name,
                        c.units,
                        COALESCE(ss.id, 0) as schedule_id,
                        COALESCE(ss.schedule_monday, 0) as schedule_monday,
                        COALESCE(ss.schedule_tuesday, 0) as schedule_tuesday,
                        COALESCE(ss.schedule_wednesday, 0) as schedule_wednesday,
                        COALESCE(ss.schedule_thursday, 0) as schedule_thursday,
                        COALESCE(ss.schedule_friday, 0) as schedule_friday,
                        COALESCE(ss.schedule_saturday, 0) as schedule_saturday,
                        COALESCE(ss.schedule_sunday, 0) as schedule_sunday,
                        COALESCE(ss.time_start, '') as time_start,
                        COALESCE(ss.time_end, '') as time_end,
                        COALESCE(ss.room, '') as room,
                        COALESCE(ss.professor_name, '') as professor_name,
                        COALESCE(ss.professor_initial, '') as professor_initial
                     FROM curriculum c
                     LEFT JOIN section_schedules ss ON c.id = ss.curriculum_id AND ss.section_id = :section_id
                     WHERE c.program_id = :program_id
                     AND c.year_level = :year_level
                     AND c.semester = :semester
                     ORDER BY c.course_code";
    
    $schedule_stmt = $conn->prepare($schedule_sql);
    $schedule_stmt->bindParam(':section_id', $section_id);
    $schedule_stmt->bindParam(':program_id', $section['program_id']);
    $schedule_stmt->bindParam(':year_level', $section['year_level']);
    $schedule_stmt->bindParam(':semester', $section['semester']);
    $schedule_stmt->execute();
    $schedule = $schedule_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode(['schedule' => $schedule]);
    
} catch(Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error: ' . $e->getMessage()]);
}
?>

