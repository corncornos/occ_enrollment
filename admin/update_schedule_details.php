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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$schedule_id = $_POST['schedule_id'] ?? 0;

try {
    $conn = (new Database())->getConnection();
    
    if ($schedule_id > 0) {
        // Update existing schedule details
        $update_sql = "UPDATE section_schedules SET
                       schedule_monday = :monday,
                       schedule_tuesday = :tuesday,
                       schedule_wednesday = :wednesday,
                       schedule_thursday = :thursday,
                       schedule_friday = :friday,
                       schedule_saturday = :saturday,
                       schedule_sunday = :sunday,
                       time_start = :time_start,
                       time_end = :time_end,
                       room = :room,
                       professor_name = :professor_name,
                       professor_initial = :professor_initial
                       WHERE id = :schedule_id";
        
        $monday = $_POST['schedule_monday'] ?? 0;
        $tuesday = $_POST['schedule_tuesday'] ?? 0;
        $wednesday = $_POST['schedule_wednesday'] ?? 0;
        $thursday = $_POST['schedule_thursday'] ?? 0;
        $friday = $_POST['schedule_friday'] ?? 0;
        $saturday = $_POST['schedule_saturday'] ?? 0;
        $sunday = $_POST['schedule_sunday'] ?? 0;
        $time_start = $_POST['time_start'] ?? '';
        $time_end = $_POST['time_end'] ?? '';
        $room = $_POST['room'] ?? '';
        $professor_name = $_POST['professor_name'] ?? '';
        $professor_initial = $_POST['professor_initial'] ?? '';
        
        $stmt = $conn->prepare($update_sql);
        $stmt->bindParam(':monday', $monday);
        $stmt->bindParam(':tuesday', $tuesday);
        $stmt->bindParam(':wednesday', $wednesday);
        $stmt->bindParam(':thursday', $thursday);
        $stmt->bindParam(':friday', $friday);
        $stmt->bindParam(':saturday', $saturday);
        $stmt->bindParam(':sunday', $sunday);
        $stmt->bindParam(':time_start', $time_start);
        $stmt->bindParam(':time_end', $time_end);
        $stmt->bindParam(':room', $room);
        $stmt->bindParam(':professor_name', $professor_name);
        $stmt->bindParam(':professor_initial', $professor_initial);
        $stmt->bindParam(':schedule_id', $schedule_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Schedule details updated successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to update schedule details']);
        }
    } else {
        // Insert new schedule entry (schedule_id == 0 means it doesn't exist yet)
        $section_id = $_POST['section_id'] ?? null;
        $curriculum_id = $_POST['curriculum_id'] ?? null;
        $course_code = $_POST['course_code'] ?? '';
        $course_name = $_POST['course_name'] ?? '';
        $units = $_POST['units'] ?? 0;
        
        if (!$section_id || !$curriculum_id) {
            echo json_encode(['success' => false, 'message' => 'Section ID and Curriculum ID required']);
            exit();
        }
        
        $insert_sql = "INSERT INTO section_schedules 
                      (section_id, curriculum_id, course_code, course_name, units,
                       schedule_monday, schedule_tuesday, schedule_wednesday, schedule_thursday,
                       schedule_friday, schedule_saturday, schedule_sunday,
                       time_start, time_end, room, professor_name, professor_initial)
                      VALUES
                      (:section_id, :curriculum_id, :course_code, :course_name, :units,
                       :monday, :tuesday, :wednesday, :thursday,
                       :friday, :saturday, :sunday,
                       :time_start, :time_end, :room, :professor_name, :professor_initial)";
        
        $monday = $_POST['schedule_monday'] ?? 0;
        $tuesday = $_POST['schedule_tuesday'] ?? 0;
        $wednesday = $_POST['schedule_wednesday'] ?? 0;
        $thursday = $_POST['schedule_thursday'] ?? 0;
        $friday = $_POST['schedule_friday'] ?? 0;
        $saturday = $_POST['schedule_saturday'] ?? 0;
        $sunday = $_POST['schedule_sunday'] ?? 0;
        $time_start = $_POST['time_start'] ?? '';
        $time_end = $_POST['time_end'] ?? '';
        $room = $_POST['room'] ?? '';
        $professor_name = $_POST['professor_name'] ?? '';
        $professor_initial = $_POST['professor_initial'] ?? '';
        
        $stmt = $conn->prepare($insert_sql);
        $stmt->bindParam(':section_id', $section_id);
        $stmt->bindParam(':curriculum_id', $curriculum_id);
        $stmt->bindParam(':course_code', $course_code);
        $stmt->bindParam(':course_name', $course_name);
        $stmt->bindParam(':units', $units);
        $stmt->bindParam(':monday', $monday);
        $stmt->bindParam(':tuesday', $tuesday);
        $stmt->bindParam(':wednesday', $wednesday);
        $stmt->bindParam(':thursday', $thursday);
        $stmt->bindParam(':friday', $friday);
        $stmt->bindParam(':saturday', $saturday);
        $stmt->bindParam(':sunday', $sunday);
        $stmt->bindParam(':time_start', $time_start);
        $stmt->bindParam(':time_end', $time_end);
        $stmt->bindParam(':room', $room);
        $stmt->bindParam(':professor_name', $professor_name);
        $stmt->bindParam(':professor_initial', $professor_initial);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Schedule details added successfully']);
        } else {
            echo json_encode(['success' => false, 'message' => 'Failed to add schedule details']);
        }
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>

