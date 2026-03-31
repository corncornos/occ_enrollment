<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';

if (!isLoggedIn()) {
    redirect('public/login.php');
}

$conn = (new Database())->getConnection();
$user_id = $_SESSION['user_id'];

$request_id = isset($_GET['request_id']) ? (int) $_GET['request_id'] : 0;

if ($request_id <= 0) {
    $_SESSION['message'] = 'Invalid enrollment request.';
    redirect('student/dashboard.php');
}

// Load enrollment request and section
$request_sql = "SELECT nse.*, s.section_name, s.section_type, s.year_level, s.semester, 
                       s.program_id, p.program_code, p.program_name
                FROM next_semester_enrollments nse
                JOIN sections s ON nse.selected_section_id = s.id
                JOIN programs p ON s.program_id = p.id
                WHERE nse.id = :request_id AND nse.user_id = :user_id";
$req_stmt = $conn->prepare($request_sql);
$req_stmt->bindParam(':request_id', $request_id, PDO::PARAM_INT);
$req_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$req_stmt->execute();
$request = $req_stmt->fetch(PDO::FETCH_ASSOC);

if (!$request) {
    $_SESSION['message'] = 'Enrollment request not found.';
    redirect('student/dashboard.php');
}

// Helper to normalize year-level strings (e.g., "2nd Year", "Second Year") to numeric (1–5)
function mapYearLevelToNumeric(?string $yearLevel): int
{
    if ($yearLevel === null) {
        return 0;
    }

    $normalized = strtolower(trim($yearLevel));

    if (strpos($normalized, '1st') !== false || strpos($normalized, 'first') !== false) {
        return 1;
    }
    if (strpos($normalized, '2nd') !== false || strpos($normalized, 'second') !== false) {
        return 2;
    }
    if (strpos($normalized, '3rd') !== false || strpos($normalized, 'third') !== false) {
        return 3;
    }
    if (strpos($normalized, '4th') !== false || strpos($normalized, 'fourth') !== false) {
        return 4;
    }
    if (strpos($normalized, '5th') !== false || strpos($normalized, 'fifth') !== false) {
        return 5;
    }

    return 0;
}

// Only irregular students 2nd year and above can use this page
if ($request['enrollment_type'] !== 'irregular') {
    $_SESSION['message'] = 'Backload subject selection is only available for irregular students.';
    redirect('student/dashboard.php');
}

// Determine numeric year level (1–5) from multiple sources to ensure accuracy:
//   1. The YEAR BEING ENROLLED (from section in the request)
//   2. Student's highest completed year level from grades (most accurate academic progress)
//   3. Student's latest year level from enrolled_students table
//   4. Current active section as final fallback
// We take the HIGHEST of all these to ensure irregular students who have reached
// 2nd year (or above) can always access backload selection.

$year_levels_to_check = [];

// 1. Year level from the enrollment request (target term)
$current_year_level_str = $request['year_level'] ?? $request['target_year_level'] ?? $request['current_year_level'] ?? '';
if (!empty($current_year_level_str)) {
    $year_levels_to_check[] = mapYearLevelToNumeric($current_year_level_str);
}

// 2. Highest completed year level from student grades (most accurate for academic progress)
$highest_completed_query = "SELECT c.year_level
                            FROM student_grades sg
                            JOIN curriculum c ON sg.curriculum_id = c.id
                            WHERE sg.user_id = :user_id
                            AND sg.status IN ('verified', 'finalized')
                            ORDER BY 
                                CASE c.year_level
                                    WHEN '1st Year' THEN 1
                                    WHEN '2nd Year' THEN 2
                                    WHEN '3rd Year' THEN 3
                                    WHEN '4th Year' THEN 4
                                    WHEN '5th Year' THEN 5
                                    ELSE 0
                                END DESC
                            LIMIT 1";
$highest_completed_stmt = $conn->prepare($highest_completed_query);
$highest_completed_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$highest_completed_stmt->execute();
$highest_completed_row = $highest_completed_stmt->fetch(PDO::FETCH_ASSOC);
if ($highest_completed_row && !empty($highest_completed_row['year_level'])) {
    $year_levels_to_check[] = mapYearLevelToNumeric($highest_completed_row['year_level']);
}

// 3. Latest year level from enrolled_students table
$student_level_query = "SELECT es.year_level
                        FROM enrolled_students es
                        WHERE es.user_id = :user_id
                        ORDER BY es.updated_at DESC, es.created_at DESC
                        LIMIT 1";
$student_level_stmt = $conn->prepare($student_level_query);
$student_level_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$student_level_stmt->execute();
$student_level_row = $student_level_stmt->fetch(PDO::FETCH_ASSOC);
if ($student_level_row && !empty($student_level_row['year_level'])) {
    $year_levels_to_check[] = mapYearLevelToNumeric($student_level_row['year_level']);
}

// 4. Fallback: derive from current active section
$fallback_level_query = "SELECT s.year_level
                         FROM section_enrollments se
                         JOIN sections s ON se.section_id = s.id
                         WHERE se.user_id = :user_id AND se.status = 'active'
                         ORDER BY s.academic_year DESC, s.semester DESC, se.enrolled_date DESC
                         LIMIT 1";
$fallback_level_stmt = $conn->prepare($fallback_level_query);
$fallback_level_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$fallback_level_stmt->execute();
$fallback_level_row = $fallback_level_stmt->fetch(PDO::FETCH_ASSOC);
if ($fallback_level_row && !empty($fallback_level_row['year_level'])) {
    $year_levels_to_check[] = mapYearLevelToNumeric($fallback_level_row['year_level']);
}

// Use the highest known year level when deciding backload eligibility
$year_numeric = !empty($year_levels_to_check) ? max($year_levels_to_check) : 0;

if ($year_numeric < 2) {
    $_SESSION['message'] = 'Backload subject selection is only available for 2nd year and above.';
    redirect('student/dashboard.php');
}

// Determine the student's CURRENT year level for display purposes
// This should be their actual academic progress, not the target year they're enrolling into
$student_current_year_level_str = '1st Year'; // Default fallback
if (!empty($year_levels_to_check)) {
    $highest_year_numeric = max($year_levels_to_check);
    // Convert numeric back to string format for display
    switch ($highest_year_numeric) {
        case 1: $student_current_year_level_str = '1st Year'; break;
        case 2: $student_current_year_level_str = '2nd Year'; break;
        case 3: $student_current_year_level_str = '3rd Year'; break;
        case 4: $student_current_year_level_str = '4th Year'; break;
        case 5: $student_current_year_level_str = '5th Year'; break;
    }
}

$program_id       = (int) $request['program_id'];
$target_semester  = $request['semester'];     // section semester (target term)

// Use the student's ACTUAL current year level (not the section's year level) for subject selection
// The section might be for a different year level, but we want to show subjects based on 
// where the student actually is academically (2nd Year student enrolling for 2nd Year Second Semester)
$target_year = $student_current_year_level_str; // Use calculated student year level, not section year level

// Get curriculum IDs the student has already passed (so we don't show them)
$passed_curr_query = "SELECT DISTINCT sg.curriculum_id
                      FROM student_grades sg
                      WHERE sg.user_id = :user_id
                      AND sg.status IN ('verified', 'finalized')
                      AND sg.grade < 5.0
                      AND UPPER(COALESCE(sg.grade_letter, '')) NOT IN ('F','FA','FAILED','W','WITHDRAWN','DROPPED','INC','INCOMPLETE')";
$passed_stmt = $conn->prepare($passed_curr_query);
$passed_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
$passed_stmt->execute();
$passed_curriculum_ids = $passed_stmt->fetchAll(PDO::FETCH_COLUMN);

// Build list of year levels to include for backloads (all lower years)
$lower_years = [];
if ($year_numeric >= 2) $lower_years[] = '1st Year';
if ($year_numeric >= 3) $lower_years[] = '2nd Year';
if ($year_numeric >= 4) $lower_years[] = '3rd Year';
if ($year_numeric >= 5) $lower_years[] = '4th Year';

// Determine if student should see backloads from both semesters
// Special case: 4th year second semester students enrolling for 5th year first semester
// should be able to enroll backloads from both semesters to complete remaining requirements.
// Note: Section assignment will attempt to find sections offering these backloads in the target semester.
// If a backload subject is only offered in a different semester, it may need manual scheduling.
$is_4th_year_2nd_sem_to_5th_year = false;
if ($year_numeric == 4) {
    // Check if student is enrolling for 5th year first semester
    $enrollment_section_query = "SELECT s.year_level, s.semester 
                                 FROM next_semester_enrollments nse
                                 JOIN sections s ON nse.selected_section_id = s.id
                                 WHERE nse.id = :request_id";
    $enrollment_section_stmt = $conn->prepare($enrollment_section_query);
    $enrollment_section_stmt->bindParam(':request_id', $request_id, PDO::PARAM_INT);
    $enrollment_section_stmt->execute();
    $enrollment_section = $enrollment_section_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($enrollment_section) {
        $target_section_year = $enrollment_section['year_level'];
        $target_section_sem = $enrollment_section['semester'];
        // Check if enrolling for 5th year first semester
        if ((stripos($target_section_year, '5th') !== false || stripos($target_section_year, 'Fifth') !== false) &&
            (stripos($target_section_sem, 'First') !== false || stripos($target_section_sem, '1st') !== false)) {
            $is_4th_year_2nd_sem_to_5th_year = true;
        }
    }
}

// Build curriculum query: current year/target semester + backloads
// - Always include current year + target_semester (the main term)
// - For backloads: 
//   * Normal case: include ONLY subjects from the SAME semester in lower years
//   * Special case (4th year 2nd sem → 5th year 1st sem): include backloads from BOTH semesters
// - DO NOT include same-year previous semester subjects (removed per requirement)
$placeholders_lower = !empty($lower_years) ? implode(',', array_fill(0, count($lower_years), '?')) : '0';
$placeholders_passed = !empty($passed_curriculum_ids) ? implode(',', array_fill(0, count($passed_curriculum_ids), '?')) : '0';

if ($is_4th_year_2nd_sem_to_5th_year) {
    // Special case: Show backloads from BOTH semesters for 4th year students enrolling for 5th year
    $curr_query = "SELECT c.id, c.course_code, c.course_name, c.units, c.year_level, c.semester
                   FROM curriculum c
                   WHERE c.program_id = ?
                   AND (
                        -- Main term: current year level + target semester
                        (c.year_level = ? AND c.semester = ?)
                        " . (!empty($lower_years) ? " OR (c.year_level IN ($placeholders_lower))" : "") . "
                   )
                   AND c.id NOT IN ($placeholders_passed)
                   ORDER BY c.year_level, c.semester, c.course_code";
    
    $curr_stmt = $conn->prepare($curr_query);
    $idx = 1;
    // Program
    $curr_stmt->bindValue($idx++, $program_id, PDO::PARAM_INT);
    // Current year + target semester
    $curr_stmt->bindValue($idx++, $target_year);
    $curr_stmt->bindValue($idx++, $target_semester);
    // Lower years (both semesters allowed)
    foreach ($lower_years as $yl) {
        $curr_stmt->bindValue($idx++, $yl);
    }
    // Exclude already-passed subjects
    foreach ($passed_curriculum_ids as $cid) {
        $curr_stmt->bindValue($idx++, $cid, PDO::PARAM_INT);
    }
} else {
    // Normal case: Backloads from SAME SEMESTER only
    $curr_query = "SELECT c.id, c.course_code, c.course_name, c.units, c.year_level, c.semester
                   FROM curriculum c
                   WHERE c.program_id = ?
                   AND (
                        -- Main term: current year level + target semester
                        (c.year_level = ? AND c.semester = ?)
                        " . (!empty($lower_years) ? " OR (c.year_level IN ($placeholders_lower) AND c.semester = ?)" : "") . "
                   )
                   AND c.id NOT IN ($placeholders_passed)
                   ORDER BY c.year_level, c.semester, c.course_code";
    
    $curr_stmt = $conn->prepare($curr_query);
    $idx = 1;
    // Program
    $curr_stmt->bindValue($idx++, $program_id, PDO::PARAM_INT);
    // Current year + target semester
    $curr_stmt->bindValue($idx++, $target_year);
    $curr_stmt->bindValue($idx++, $target_semester);
    // Lower years (same semester only)
    foreach ($lower_years as $yl) {
        $curr_stmt->bindValue($idx++, $yl);
    }
    if (!empty($lower_years)) {
        // Semester filter for lower-year backloads (must match target semester)
        $curr_stmt->bindValue($idx++, $target_semester);
    }
    // Exclude already-passed subjects
    foreach ($passed_curriculum_ids as $cid) {
        $curr_stmt->bindValue($idx++, $cid, PDO::PARAM_INT);
    }
}

$curr_stmt->execute();
$subjects = $curr_stmt->fetchAll(PDO::FETCH_ASSOC);

// Existing selections (if student already saved once)
$existing_sel_query = "SELECT curriculum_id FROM next_semester_subject_selections
                       WHERE enrollment_request_id = :request_id";
$sel_stmt = $conn->prepare($existing_sel_query);
$sel_stmt->bindParam(':request_id', $request_id, PDO::PARAM_INT);
$sel_stmt->execute();
$existing_curr_ids = $sel_stmt->fetchAll(PDO::FETCH_COLUMN);

// Handle form submission (student saving choices)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $chosen = $_POST['selected_subjects'] ?? [];

    try {
        $conn->beginTransaction();

        // Clear previous selections for this request
        $del_sql = "DELETE FROM next_semester_subject_selections WHERE enrollment_request_id = :request_id";
        $del_stmt = $conn->prepare($del_sql);
        $del_stmt->bindParam(':request_id', $request_id, PDO::PARAM_INT);
        $del_stmt->execute();

        if (!empty($chosen)) {
            $ins_sql = "INSERT INTO next_semester_subject_selections (enrollment_request_id, curriculum_id, status)
                        VALUES (:request_id, :curriculum_id, 'pending')";
            $ins_stmt = $conn->prepare($ins_sql);
            foreach ($chosen as $cid) {
                $curr_id = (int) $cid;
                if ($curr_id > 0) {
                    $ins_stmt->bindParam(':request_id', $request_id, PDO::PARAM_INT);
                    $ins_stmt->bindParam(':curriculum_id', $curr_id, PDO::PARAM_INT);
                    $ins_stmt->execute();
                }
            }
        }

        $conn->commit();
        $_SESSION['message'] = 'Your subject choices have been saved. Your Program Head will review and may adjust them before final approval.';
        redirect('student/dashboard.php');
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $_SESSION['message'] = 'Error saving subject choices: ' . $e->getMessage();
        redirect('student/dashboard.php');
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Select Backload Subjects - Next Semester Enrollment</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px 0;
        }
        .card {
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            margin-bottom: 20px;
        }
        .subject-item:hover {
            background-color: #f8fafc;
        }
    </style>
</head>
<body>
<div class="container">
    <div class="mb-3">
        <a href="dashboard.php" class="btn btn-light btn-sm">
            <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
        </a>
    </div>

    <div class="card">
        <div class="card-header bg-primary text-white">
            <h4 class="mb-0"><i class="fas fa-list-check me-2"></i>Select Backload Subjects</h4>
        </div>
        <div class="card-body">
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                You are an <strong>irregular <?php echo htmlspecialchars($student_current_year_level_str); ?></strong> student.
                Please choose the subjects (including backload subjects from previous years) that you want to enroll.
                Your Program Head will review and may adjust these before final approval.
            </div>

            <h5 class="mb-3">Selected Section</h5>
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="mb-2">
                        <strong><?php echo htmlspecialchars($request['section_name']); ?></strong>
                        <span class="badge <?php echo $request['section_type'] == 'Morning' ? 'bg-info' : ($request['section_type'] == 'Afternoon' ? 'bg-warning' : 'bg-dark'); ?> ms-2">
                            <?php echo htmlspecialchars($request['section_type']); ?>
                        </span>
                    </h5>
                    <p class="mb-1"><strong>Program:</strong> <?php echo htmlspecialchars($request['program_code'] . ' - ' . $request['program_name']); ?></p>
                    <p class="mb-1"><strong>Year Level:</strong> <?php echo htmlspecialchars($student_current_year_level_str); ?></p>
                    <p class="mb-1"><strong>Semester:</strong> <?php echo htmlspecialchars($request['target_semester'] ?? $request['semester']); ?></p>
                    <p class="mb-0"><strong>Academic Year:</strong> <?php echo htmlspecialchars($request['target_academic_year'] ?? 'N/A'); ?></p>
                </div>
            </div>

<?php
    // Build prerequisite maps similar to admin/review_next_semester.php so we can
    // show which subjects have unmet prerequisites.
    $prerequisites_map = [];
    $student_grades_map = [];

    if (!empty($subjects)) {
        // Collect curriculum IDs for all candidate subjects
        $curriculum_ids = array_column($subjects, 'id');

        if (!empty($curriculum_ids)) {
            // Get prerequisites for all candidate subjects
            $placeholders = implode(',', array_fill(0, count($curriculum_ids), '?'));
            $prereq_query = "SELECT c.id as curriculum_id, c.course_code,
                                    sp.prerequisite_curriculum_id, sp.minimum_grade,
                                    pc.course_code as prereq_code, pc.course_name as prereq_name
                             FROM curriculum c
                             LEFT JOIN subject_prerequisites sp ON c.id = sp.curriculum_id
                             LEFT JOIN curriculum pc ON sp.prerequisite_curriculum_id = pc.id
                             WHERE c.id IN ($placeholders)";
            $prereq_stmt = $conn->prepare($prereq_query);
            foreach ($curriculum_ids as $idx => $curriculum_id) {
                $prereq_stmt->bindValue($idx + 1, $curriculum_id, PDO::PARAM_INT);
            }
            $prereq_stmt->execute();
            while ($row = $prereq_stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($row['prerequisite_curriculum_id']) {
                    if (!isset($prerequisites_map[$row['curriculum_id']])) {
                        $prerequisites_map[$row['curriculum_id']] = [];
                    }
                    $prerequisites_map[$row['curriculum_id']][] = [
                        'prereq_code' => $row['prereq_code'],
                        'prereq_name' => $row['prereq_name'],
                        'prereq_curriculum_id' => $row['prerequisite_curriculum_id'],
                        'minimum_grade' => $row['minimum_grade'],
                    ];
                }
            }

            // Get student's grades for all subjects they've taken
            $grades_query = "SELECT sg.curriculum_id, sg.grade, sg.grade_letter
                             FROM student_grades sg
                             WHERE sg.user_id = :user_id
                             AND sg.status IN ('verified', 'finalized')";
            $grades_stmt = $conn->prepare($grades_query);
            $grades_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
            $grades_stmt->execute();
            while ($row = $grades_stmt->fetch(PDO::FETCH_ASSOC)) {
                $student_grades_map[$row['curriculum_id']] = [
                    'grade' => $row['grade'],
                    'grade_letter' => $row['grade_letter'],
                ];
            }
        }
    }
?>
<?php if (empty($subjects)): ?>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    No available subjects found for selection. Please contact your Program Head.
                </div>
            <?php else: ?>
                <form method="POST">
                    <div class="card mb-3">
                        <div class="card-body">
                            <h5 class="mb-3"><i class="fas fa-clipboard-list me-2"></i>Subjects</h5>
                            <?php foreach ($subjects as $index => $sub):
                                $cid = (int) $sub['id'];
                                $checked = empty($existing_curr_ids) || in_array($cid, $existing_curr_ids);

                                // Prerequisite evaluation (cannot be overridden at student level)
                                $has_prereq = isset($prerequisites_map[$cid]);
                                $prereq_met = true;
                                $unmet_prereqs = [];

                                if ($has_prereq) {
                                    foreach ($prerequisites_map[$cid] as $prereq) {
                                        $prereq_grade = $student_grades_map[$prereq['prereq_curriculum_id']] ?? null;

                                        if (!$prereq_grade) {
                                            $prereq_met = false;
                                            $unmet_prereqs[] = [
                                                'code' => $prereq['prereq_code'],
                                                'name' => $prereq['prereq_name'],
                                                'reason' => 'Not taken',
                                            ];
                                        } elseif (strtoupper($prereq_grade['grade_letter']) == 'INC' || strtoupper($prereq_grade['grade_letter']) == 'INCOMPLETE') {
                                            $prereq_met = false;
                                            $unmet_prereqs[] = [
                                                'code' => $prereq['prereq_code'],
                                                'name' => $prereq['prereq_name'],
                                                'reason' => 'Grade is INC (Incomplete) - must complete this course first',
                                            ];
                                        } elseif (in_array(strtoupper($prereq_grade['grade_letter']), ['F', 'FA', 'FAILED', 'W', 'WITHDRAWN', 'DROPPED'])) {
                                            $prereq_met = false;
                                            $unmet_prereqs[] = [
                                                'code' => $prereq['prereq_code'],
                                                'name' => $prereq['prereq_name'],
                                                'reason' => 'Failed / Withdrawn / Dropped - must retake and pass this course',
                                            ];
                                        } elseif ($prereq_grade['grade'] >= 5.0) {
                                            $prereq_met = false;
                                            $unmet_prereqs[] = [
                                                'code' => $prereq['prereq_code'],
                                                'name' => $prereq['prereq_name'],
                                                'reason' => 'Grade 5.0 indicates failed course',
                                            ];
                                        } elseif ($prereq_grade['grade'] > $prereq['minimum_grade']) {
                                            $prereq_met = false;
                                            $unmet_prereqs[] = [
                                                'code' => $prereq['prereq_code'],
                                                'name' => $prereq['prereq_name'],
                                                'reason' => 'Grade does not meet minimum required grade',
                                            ];
                                        }
                                    }
                                }

                                $can_select = $prereq_met; // students cannot override prerequisites
                            ?>
                                <div class="form-check mb-2 p-2 border rounded subject-item <?php echo !$prereq_met ? 'border-warning bg-light' : ''; ?>">
                                    <input class="form-check-input subject-checkbox" type="checkbox"
                                           name="selected_subjects[]"
                                           value="<?php echo $cid; ?>"
                                           id="sub_<?php echo $cid; ?>"
                                           data-units="<?php echo (float) $sub['units']; ?>"
                                           <?php echo $checked && $can_select ? 'checked' : ''; ?>
                                           <?php echo $can_select ? '' : 'disabled'; ?>>
                                    <label class="form-check-label ms-2 w-100" for="sub_<?php echo $cid; ?>">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <strong><?php echo htmlspecialchars($sub['course_code']); ?></strong>
                                                <span class="badge bg-secondary ms-2"><?php echo (float) $sub['units']; ?> unit(s)</span>
                                                <?php if ($sub['year_level'] !== $target_year): ?>
                                                    <span class="badge bg-info ms-2">
                                                        <i class="fas fa-history me-1"></i>Backload (<?php echo htmlspecialchars($sub['year_level']); ?>)
                                                    </span>
                                                <?php endif; ?>
                                                <?php if ($has_prereq && !$prereq_met): ?>
                                                    <span class="badge bg-danger ms-2">
                                                        <i class="fas fa-ban me-1"></i>Prerequisites Not Met
                                                    </span>
                                                <?php endif; ?>
                                                <div><?php echo htmlspecialchars($sub['course_name']); ?></div>

                                                <?php if (!empty($unmet_prereqs)): ?>
                                                    <div class="alert alert-danger small mb-1 mt-2 py-2">
                                                        <strong><i class="fas fa-ban me-1"></i>Unmet Prerequisites:</strong>
                                                        <ul class="mb-0 mt-1 ps-3">
                                                            <?php foreach ($unmet_prereqs as $unmet): ?>
                                                                <li>
                                                                    <strong><?php echo htmlspecialchars($unmet['code']); ?></strong>
                                                                    - <?php echo htmlspecialchars($unmet['name']); ?>
                                                                    <br><small class="text-muted"><?php echo htmlspecialchars($unmet['reason']); ?></small>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                            <div class="text-end text-muted small">
                                                <?php echo htmlspecialchars($sub['year_level'] . ' • ' . $sub['semester']); ?>
                                            </div>
                                        </div>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="card mb-3">
                        <div class="card-body bg-light">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <h6 class="mb-0"><i class="fas fa-calculator me-2"></i>Total Units</h6>
                                    <p class="mb-0 text-muted small">Selected subjects</p>
                                </div>
                                <div class="text-end">
                                    <h4 class="mb-0 text-primary" id="total_units_display">0</h4>
                                    <small class="text-muted">unit(s)</small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-end">
                        <button type="submit" class="btn btn-primary btn-lg" id="saveBtn">
                            <i class="fas fa-save me-2"></i>Save Subject Choices
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // Calculate and display total units
    function updateTotalUnits() {
        const checkboxes = document.querySelectorAll('.subject-checkbox:checked');
        let totalUnits = 0;
        
        checkboxes.forEach(checkbox => {
            const units = parseFloat(checkbox.getAttribute('data-units')) || 0;
            totalUnits += units;
        });
        
        document.getElementById('total_units_display').textContent = totalUnits.toFixed(1);
    }
    
    // Update total units when checkboxes change
    document.addEventListener('DOMContentLoaded', function() {
        // Initial calculation
        updateTotalUnits();
        
        // Add event listeners to all checkboxes
        const checkboxes = document.querySelectorAll('.subject-checkbox');
        checkboxes.forEach(checkbox => {
            checkbox.addEventListener('change', updateTotalUnits);
        });
        
        // Add form submission handler
        const form = document.querySelector('form');
        if (form) {
            form.addEventListener('submit', function(e) {
                const checkboxes = document.querySelectorAll('.subject-checkbox:checked');
                let totalUnits = 0;
                
                checkboxes.forEach(checkbox => {
                    const units = parseFloat(checkbox.getAttribute('data-units')) || 0;
                    totalUnits += units;
                });
                
                const confirmed = confirm('Are you sure you want to enroll these subjects? The number of your units are ' + totalUnits.toFixed(1) + '.');
                
                if (!confirmed) {
                    e.preventDefault();
                    return false;
                }
            });
        }
    });
</script>
</body>
</html>


