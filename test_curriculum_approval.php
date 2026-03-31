<?php
require_once 'config/database.php';

$db = new Database();
$conn = $db->getConnection();

echo "<h1>Curriculum Approval Test</h1>";

// Check recent submissions
echo "<h2>Recent Curriculum Submissions</h2>";
$stmt = $conn->prepare("SELECT cs.*, ph.first_name, ph.last_name, p.program_name, p.program_code
                       FROM curriculum_submissions cs
                       JOIN program_heads ph ON cs.program_head_id = ph.id
                       JOIN programs p ON cs.program_id = p.id
                       ORDER BY cs.created_at DESC LIMIT 5");
$stmt->execute();
$submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($submissions)) {
    echo "<p>No submissions found. Please create a submission first.</p>";
} else {
    foreach ($submissions as $sub) {
        echo "<div style='border: 1px solid #ccc; padding: 10px; margin: 10px 0;'>";
        echo "<h3>Submission #{$sub['id']}: {$sub['submission_title']}</h3>";
        echo "<p><strong>Program:</strong> {$sub['program_name']} ({$sub['program_code']})</p>";
        echo "<p><strong>Program Head:</strong> {$sub['first_name']} {$sub['last_name']}</p>";
        echo "<p><strong>Status:</strong> {$sub['status']}</p>";
        echo "<p><strong>Academic Year:</strong> {$sub['academic_year']}</p>";
        echo "<p><strong>Semester:</strong> {$sub['semester']}</p>";

        // Count items in this submission
        $count_stmt = $conn->prepare("SELECT COUNT(*) as count FROM curriculum_submission_items WHERE submission_id = ?");
        $count_stmt->execute([$sub['id']]);
        $item_count = $count_stmt->fetch()['count'];

        echo "<p><strong>Items:</strong> $item_count subjects</p>";

        if ($sub['status'] == 'approved') {
            // Check how many items were added to curriculum
            $curriculum_stmt = $conn->prepare("SELECT COUNT(*) as count FROM curriculum WHERE program_id = ?");
            $curriculum_stmt->execute([$sub['program_id']]);
            $curriculum_count = $curriculum_stmt->fetch()['count'];

            echo "<p><strong>Curriculum Items for this Program:</strong> $curriculum_count total subjects</p>";
        }

        echo "</div>";
    }
}

echo "<h2>Test Approval Process</h2>";
echo "<p>To test the approval process:</p>";
echo "<ol>";
echo "<li>Login as a Program Head and upload a CSV file</li>";
echo "<li>Submit the curriculum to Registrar</li>";
echo "<li>Login as Admin and go to 'Curriculum Submissions' tab</li>";
echo "<li>Click 'Approve' on a submitted curriculum</li>";
echo "<li>Check this page again to see the items added to curriculum table</li>";
echo "</ol>";

echo "<h2>Check Curriculum Table</h2>";
$stmt = $conn->prepare("SELECT c.*, p.program_name, p.program_code
                       FROM curriculum c
                       JOIN programs p ON c.program_id = p.id
                       ORDER BY p.program_name, c.year_level, c.semester, c.course_code
                       LIMIT 20");
$stmt->execute();
$curriculum_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($curriculum_items)) {
    echo "<p>No items in curriculum table yet.</p>";
} else {
    echo "<table border='1' style='border-collapse: collapse; width: 100%;'>";
    echo "<tr><th>Program</th><th>Course Code</th><th>Course Name</th><th>Year</th><th>Semester</th><th>Units</th><th>Required</th></tr>";

    foreach ($curriculum_items as $item) {
        $required = $item['is_required'] ? 'Yes' : 'No';
        echo "<tr>";
        echo "<td>{$item['program_code']} - {$item['program_name']}</td>";
        echo "<td>{$item['course_code']}</td>";
        echo "<td>{$item['course_name']}</td>";
        echo "<td>{$item['year_level']}</td>";
        echo "<td>{$item['semester']}</td>";
        echo "<td>{$item['units']}</td>";
        echo "<td>$required</td>";
        echo "</tr>";
    }
    echo "</table>";
}
?>
