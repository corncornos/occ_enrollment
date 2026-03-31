<?php
require_once '../config/database.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

header('Content-Type: application/json');

$db = new Database();
$conn = $db->getConnection();

$action = $_POST['action'] ?? '';

if ($action === 'validate') {
    validateCSV();
} elseif ($action === 'import') {
    processImport();
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid action']);
}

function validateCSV() {
    global $conn;
    
    if (!isset($_FILES['csvFile']) || $_FILES['csvFile']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error']);
        return;
    }

    $file = $_FILES['csvFile']['tmp_name'];
    
    // Read CSV file
    $rows = [];
    $validCount = 0;
    $errorCount = 0;
    $lineNumber = 0;
    
    if (($handle = fopen($file, 'r')) !== false) {
        // Read header
        $header = fgetcsv($handle);
        
        // Validate header
        $expectedHeaders = ['student_id', 'first_name', 'last_name', 'email', 'phone', 'program', 'year_level', 'student_type', 'academic_year', 'semester'];
        if ($header !== $expectedHeaders) {
            echo json_encode([
                'success' => false, 
                'message' => 'Invalid CSV format. Please use the template. Expected columns: ' . implode(', ', $expectedHeaders)
            ]);
            fclose($handle);
            return;
        }
        
        // Get all programs
        $programs_query = "SELECT program_code FROM programs";
        $programs_stmt = $conn->prepare($programs_query);
        $programs_stmt->execute();
        $valid_programs = $programs_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Get existing emails and student IDs
        $existing_emails_query = "SELECT email FROM users";
        $existing_emails_stmt = $conn->prepare($existing_emails_query);
        $existing_emails_stmt->execute();
        $existing_emails = $existing_emails_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        $existing_ids_query = "SELECT student_id FROM users WHERE student_id IS NOT NULL";
        $existing_ids_stmt = $conn->prepare($existing_ids_query);
        $existing_ids_stmt->execute();
        $existing_student_ids = $existing_ids_stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // Track IDs and emails in current batch
        $batch_emails = [];
        $batch_student_ids = [];
        
        // Read data rows
        while (($data = fgetcsv($handle)) !== false) {
            $lineNumber++;
            
            if (count($data) !== count($expectedHeaders)) {
                continue; // Skip malformed rows
            }
            
            $row = [
                'student_id' => trim($data[0]),
                'first_name' => trim($data[1]),
                'last_name' => trim($data[2]),
                'email' => trim($data[3]),
                'phone' => trim($data[4]),
                'program' => trim($data[5]),
                'year_level' => trim($data[6]),
                'student_type' => trim($data[7]),
                'academic_year' => trim($data[8]),
                'semester' => trim($data[9]),
                'errors' => []
            ];
            
            // Validate student_id
            if (empty($row['student_id'])) {
                $row['errors'][] = 'Student ID is required';
            } elseif (in_array($row['student_id'], $existing_student_ids)) {
                $row['errors'][] = 'Student ID already exists in database';
            } elseif (in_array($row['student_id'], $batch_student_ids)) {
                $row['errors'][] = 'Duplicate Student ID in file';
            } else {
                $batch_student_ids[] = $row['student_id'];
            }
            
            // Validate first_name
            if (empty($row['first_name'])) {
                $row['errors'][] = 'First name is required';
            }
            
            // Validate last_name
            if (empty($row['last_name'])) {
                $row['errors'][] = 'Last name is required';
            }
            
            // Validate email
            if (empty($row['email'])) {
                $row['errors'][] = 'Email is required';
            } elseif (!filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                $row['errors'][] = 'Invalid email format';
            } elseif (in_array($row['email'], $existing_emails)) {
                $row['errors'][] = 'Email already exists in database';
            } elseif (in_array($row['email'], $batch_emails)) {
                $row['errors'][] = 'Duplicate email in file';
            } else {
                $batch_emails[] = $row['email'];
            }
            
            // Validate program
            if (empty($row['program'])) {
                $row['errors'][] = 'Program is required';
            } elseif (!in_array($row['program'], $valid_programs)) {
                $row['errors'][] = 'Invalid program code. Valid codes: ' . implode(', ', $valid_programs);
            }
            
            // Validate year_level
            $valid_years = ['1st Year', '2nd Year', '3rd Year', '4th Year', '5th Year'];
            if (empty($row['year_level'])) {
                $row['errors'][] = 'Year level is required';
            } elseif (!in_array($row['year_level'], $valid_years)) {
                $row['errors'][] = 'Invalid year level. Valid values: ' . implode(', ', $valid_years);
            }
            
            // Validate student_type
            $valid_types = ['Regular', 'Irregular', 'Transferee'];
            if (empty($row['student_type'])) {
                $row['errors'][] = 'Student type is required';
            } elseif (!in_array($row['student_type'], $valid_types)) {
                $row['errors'][] = 'Invalid student type. Valid values: ' . implode(', ', $valid_types);
            }
            
            // Validate academic_year
            if (empty($row['academic_year'])) {
                $row['errors'][] = 'Academic year is required';
            } elseif (!preg_match('/^\d{4}-\d{4}$/', $row['academic_year'])) {
                $row['errors'][] = 'Invalid academic year format. Use: YYYY-YYYY (e.g., 2023-2024)';
            }
            
            // Validate semester
            $valid_semesters = ['First Semester', 'Second Semester', 'Summer'];
            if (empty($row['semester'])) {
                $row['errors'][] = 'Semester is required';
            } elseif (!in_array($row['semester'], $valid_semesters)) {
                $row['errors'][] = 'Invalid semester. Valid values: ' . implode(', ', $valid_semesters);
            }
            
            // Track if row has errors
            if (!empty($row['errors'])) {
                $errorCount++;
            } else {
                $validCount++;
                unset($row['errors']); // Remove errors key for valid rows
            }
            
            $rows[] = $row;
        }
        
        fclose($handle);
        
        echo json_encode([
            'success' => true,
            'data' => $rows,
            'total_count' => count($rows),
            'valid_count' => $validCount,
            'error_count' => $errorCount
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Could not read CSV file']);
    }
}

function processImport() {
    global $conn;
    
    $data = json_decode($_POST['data'], true);
    
    if (!is_array($data)) {
        echo json_encode(['success' => false, 'message' => 'Invalid data format']);
        return;
    }
    
    $importedCount = 0;
    $enrolledCount = 0;
    $skippedCount = 0;
    
    try {
        $conn->beginTransaction();
        
        foreach ($data as $row) {
            // Skip rows with errors
            if (isset($row['errors']) && !empty($row['errors'])) {
                $skippedCount++;
                continue;
            }
            
            // Insert ONLY into enrolled_students table (no user account yet)
            // Students will claim their account later by verifying their identity
            $enrolled_query = "INSERT INTO enrolled_students (
                student_id,
                first_name,
                last_name,
                email,
                phone,
                course,
                student_type,
                year_level,
                academic_year,
                semester,
                enrolled_date,
                user_id
            ) VALUES (
                :student_id,
                :first_name,
                :last_name,
                :email,
                :phone,
                :course,
                :student_type,
                :year_level,
                :academic_year,
                :semester,
                NOW(),
                NULL
            )";
            
            $enrolled_stmt = $conn->prepare($enrolled_query);
            $enrolled_stmt->bindParam(':student_id', $row['student_id']);
            $enrolled_stmt->bindParam(':first_name', $row['first_name']);
            $enrolled_stmt->bindParam(':last_name', $row['last_name']);
            $enrolled_stmt->bindParam(':email', $row['email']);
            $enrolled_stmt->bindParam(':phone', $row['phone']);
            $enrolled_stmt->bindParam(':course', $row['program']);
            $enrolled_stmt->bindParam(':student_type', $row['student_type']);
            $enrolled_stmt->bindParam(':year_level', $row['year_level']);
            $enrolled_stmt->bindParam(':academic_year', $row['academic_year']);
            $enrolled_stmt->bindParam(':semester', $row['semester']);
            
            if ($enrolled_stmt->execute()) {
                $enrolledCount++;
                $importedCount++;
            }
        }
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'imported_count' => $importedCount,
            'enrolled_count' => $enrolledCount,
            'skipped_count' => $skippedCount,
            'note' => 'Students imported without user accounts. They can claim their accounts via the registration page.'
        ]);
        
    } catch (Exception $e) {
        $conn->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'Import failed: ' . $e->getMessage()
        ]);
    }
}
?>

