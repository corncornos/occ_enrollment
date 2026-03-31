<?php
declare(strict_types=1);

require_once '../config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    $program_name = $_GET['program_name'] ?? '';
    
    if (empty($program_name)) {
        echo json_encode(['success' => false, 'message' => 'Program name is required']);
        exit();
    }
    
    $db = new Database();
    $conn = $db->getConnection();
    
    // Get program ID from database dynamically (instead of hardcoded map)
    $program_sql = "SELECT id, program_code, program_name FROM programs WHERE program_name = :program_name AND status = 'active'";
    $program_stmt = $conn->prepare($program_sql);
    $program_stmt->bindParam(':program_name', $program_name);
    $program_stmt->execute();
    $program = $program_stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$program) {
        echo json_encode(['success' => false, 'message' => 'Invalid program name or program is inactive']);
        exit();
    }
    
    $program_id = $program['id'];
    
    // Get current or latest academic year (default to AY 2024-2025 if none set)
    // Get sections for first year, first semester with available capacity
    $sql = "SELECT s.id, s.section_name, s.section_type, s.max_capacity, s.current_enrolled,
                   (s.max_capacity - s.current_enrolled) as available_slots,
                   s.academic_year,
                   p.program_code, p.program_name,
                   COUNT(ss.id) as schedule_count
            FROM sections s
            JOIN programs p ON s.program_id = p.id
            LEFT JOIN section_schedules ss ON s.id = ss.section_id
            WHERE s.program_id = :program_id
            AND s.year_level = '1st Year'
            AND s.semester = 'First Semester'
            AND s.status = 'active'
            AND s.current_enrolled < s.max_capacity
            GROUP BY s.id
            ORDER BY s.section_type, s.section_name";
    
    $stmt = $conn->prepare($sql);
    $stmt->bindParam(':program_id', $program_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $sections = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get schedule details for each section
    foreach ($sections as &$section) {
        $schedule_sql = "SELECT course_code, course_name, units,
                        schedule_monday, schedule_tuesday, schedule_wednesday,
                        schedule_thursday, schedule_friday, schedule_saturday, schedule_sunday,
                        time_start, time_end, room, professor_name, professor_initial
                        FROM section_schedules
                        WHERE section_id = :section_id
                        ORDER BY course_code";
        
        $schedule_stmt = $conn->prepare($schedule_sql);
        $schedule_stmt->bindParam(':section_id', $section['id'], PDO::PARAM_INT);
        $schedule_stmt->execute();
        
        $section['schedules'] = $schedule_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Format schedule for display
        $section['schedule_summary'] = [];
        foreach ($section['schedules'] as $schedule) {
            $days = [];
            if ($schedule['schedule_monday']) $days[] = 'Mon';
            if ($schedule['schedule_tuesday']) $days[] = 'Tue';
            if ($schedule['schedule_wednesday']) $days[] = 'Wed';
            if ($schedule['schedule_thursday']) $days[] = 'Thu';
            if ($schedule['schedule_friday']) $days[] = 'Fri';
            if ($schedule['schedule_saturday']) $days[] = 'Sat';
            if ($schedule['schedule_sunday']) $days[] = 'Sun';
            
            $schedule_text = $schedule['course_code'] . ' (' . implode(', ', $days) . ')';
            if ($schedule['time_start'] && $schedule['time_end']) {
                $schedule_text .= ' ' . date('g:iA', strtotime($schedule['time_start'])) . '-' . date('g:iA', strtotime($schedule['time_end']));
            }
            if ($schedule['room']) {
                $schedule_text .= ' @ ' . $schedule['room'];
            }
            
            $section['schedule_summary'][] = $schedule_text;
        }
    }
    
    echo json_encode([
        'success' => true,
        'sections' => $sections
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error fetching sections: ' . $e->getMessage()
    ]);
}

