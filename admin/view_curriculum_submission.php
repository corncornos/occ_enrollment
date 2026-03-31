<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';

// Check if admin is logged in
if (!isLoggedIn() || !isAdmin()) {
    redirect('public/login.php');
}

$db = new Database();
$conn = $db->getConnection();

$submission_id = (int)($_GET['id'] ?? 0);

if ($submission_id <= 0) {
    echo "<script>alert('Invalid submission ID'); window.close();</script>";
    exit();
}

// Get submission details
$stmt = $conn->prepare("SELECT cs.*, ph.first_name, ph.last_name, p.program_name, p.program_code,
                               a.first_name as reviewer_first_name, a.last_name as reviewer_last_name
                        FROM curriculum_submissions cs
                        JOIN program_heads ph ON cs.program_head_id = ph.id
                        JOIN programs p ON cs.program_id = p.id
                        LEFT JOIN admins a ON cs.reviewed_by = a.id
                        WHERE cs.id = :submission_id");
$stmt->bindParam(':submission_id', $submission_id);
$stmt->execute();

if ($stmt->rowCount() == 0) {
    echo "<script>alert('Submission not found'); window.close();</script>";
    exit();
}

$submission = $stmt->fetch(PDO::FETCH_ASSOC);

// Get submission items
$stmt = $conn->prepare("SELECT * FROM curriculum_submission_items
                        WHERE submission_id = :submission_id
                        ORDER BY year_level, semester, course_code");
$stmt->bindParam(':submission_id', $submission_id);
$stmt->execute();
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Curriculum Submission Details - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .status-draft { background: #ffc107; color: #000; }
        .status-submitted { background: #17a2b8; color: white; }
        .status-approved { background: #28a745; color: white; }
        .status-rejected { background: #dc3545; color: white; }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-file-alt me-2"></i>Curriculum Submission Details</h2>
            <button class="btn btn-outline-secondary" onclick="window.close()">
                <i class="fas fa-times me-2"></i>Close
            </button>
        </div>

        <!-- Submission Overview -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Submission Overview</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>Submission Title:</strong> <?php echo htmlspecialchars($submission['submission_title']); ?></p>
                        <p><strong>Program Head:</strong> <?php echo htmlspecialchars($submission['first_name'] . ' ' . $submission['last_name']); ?></p>
                        <p><strong>Program:</strong> <?php echo htmlspecialchars($submission['program_code'] . ' - ' . $submission['program_name']); ?></p>
                        <p><strong>Description:</strong> <?php echo htmlspecialchars($submission['submission_description'] ?: 'No description provided'); ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>Academic Year:</strong> <?php echo htmlspecialchars($submission['academic_year']); ?></p>
                        <p><strong>Semester:</strong> <?php echo htmlspecialchars($submission['semester']); ?></p>
                        <p><strong>Status:</strong>
                            <span class="badge status-<?php echo $submission['status']; ?> ms-2">
                                <?php echo ucfirst($submission['status']); ?>
                            </span>
                        </p>
                        <p><strong>Submitted:</strong> <?php echo $submission['submitted_at'] ? date('M j, Y g:i A', strtotime($submission['submitted_at'])) : 'Not submitted'; ?></p>
                        <?php if ($submission['reviewed_at']): ?>
                            <p><strong>Reviewed:</strong> <?php echo date('M j, Y g:i A', strtotime($submission['reviewed_at'])); ?></p>
                            <p><strong>Reviewed by:</strong> <?php echo htmlspecialchars($submission['reviewer_first_name'] . ' ' . $submission['reviewer_last_name']); ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($submission['reviewer_comments']): ?>
                    <div class="mt-3">
                        <strong>Reviewer Comments:</strong>
                        <div class="alert alert-info mt-2">
                            <?php echo htmlspecialchars($submission['reviewer_comments']); ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Curriculum Items -->
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>Curriculum Subjects
                    <span class="badge bg-secondary ms-2"><?php echo count($items); ?> subjects</span>
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($items)): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                        <p class="text-muted">No subjects found in this submission.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-striped table-hover">
                            <thead>
                                <tr>
                                    <th>Course Code</th>
                                    <th>Course Name</th>
                                    <th>Units</th>
                                    <th>Year Level</th>
                                    <th>Semester</th>
                                    <th>Required</th>
                                    <th>Prerequisites</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // Group by year level and semester
                                $grouped_items = [];
                                foreach ($items as $item) {
                                    $key = $item['year_level'] . '|' . $item['semester'];
                                    if (!isset($grouped_items[$key])) {
                                        $grouped_items[$key] = [];
                                    }
                                    $grouped_items[$key][] = $item;
                                }

                                foreach ($grouped_items as $group_key => $group_items):
                                    list($year_level, $semester) = explode('|', $group_key);
                                ?>
                                    <tr class="table-secondary">
                                        <td colspan="7" class="fw-bold">
                                            <i class="fas fa-graduation-cap me-2"></i>
                                            <?php echo htmlspecialchars($year_level . ' - ' . $semester); ?>
                                        </td>
                                    </tr>
                                    <?php foreach ($group_items as $item): ?>
                                        <tr>
                                            <td><code><?php echo htmlspecialchars($item['course_code']); ?></code></td>
                                            <td><?php echo htmlspecialchars($item['course_name']); ?></td>
                                            <td><?php echo $item['units']; ?></td>
                                            <td><?php echo htmlspecialchars($item['year_level']); ?></td>
                                            <td><?php echo htmlspecialchars($item['semester']); ?></td>
                                            <td>
                                                <?php if ($item['is_required']): ?>
                                                    <span class="badge bg-success">Required</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Optional</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars($item['pre_requisites'] ?: 'None'); ?>
                                                </small>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Action Buttons -->
        <?php if ($submission['status'] == 'submitted'): ?>
            <div class="d-flex gap-2 mt-4">
                <button class="btn btn-success" onclick="approveSubmission(<?php echo $submission['id']; ?>)">
                    <i class="fas fa-check me-2"></i>Approve & Add to Curriculum
                </button>
                <button class="btn btn-danger" onclick="rejectSubmission(<?php echo $submission['id']; ?>)">
                    <i class="fas fa-times me-2"></i>Reject Submission
                </button>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function approveSubmission(submissionId) {
            if (confirm('Are you sure you want to approve this curriculum submission? This will add all subjects to the curriculum.')) {
                const formData = new FormData();
                formData.append('submission_id', submissionId);
                formData.append('action', 'approve');

                fetch('process_curriculum_submission.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Curriculum submission approved successfully!');
                        window.opener.location.reload(); // Refresh parent window
                        window.close();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error approving submission');
                });
            }
        }

        function rejectSubmission(submissionId) {
            const reason = prompt('Please provide a reason for rejecting this submission:');
            if (reason !== null && reason.trim() !== '') {
                const formData = new FormData();
                formData.append('submission_id', submissionId);
                formData.append('action', 'reject');
                formData.append('reason', reason.trim());

                fetch('process_curriculum_submission.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Curriculum submission rejected.');
                        window.opener.location.reload(); // Refresh parent window
                        window.close();
                    } else {
                        alert('Error: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error rejecting submission');
                });
            }
        }
    </script>
</body>
</html>
