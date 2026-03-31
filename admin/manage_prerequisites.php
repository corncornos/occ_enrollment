<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('public/login.php');
}

$conn = (new Database())->getConnection();

// Get all subjects (from curriculum)
$subjects_query = "SELECT c.*, p.program_name, p.program_code 
                   FROM curriculum c 
                   LEFT JOIN programs p ON c.program_id = p.id
                   ORDER BY p.program_code, c.course_code";
$subjects_stmt = $conn->prepare($subjects_query);
$subjects_stmt->execute();
$all_subjects = $subjects_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action']) && $_POST['action'] === 'add_prerequisite') {
        $subject_id = $_POST['subject_id'];
        $prerequisite_subject_id = $_POST['prerequisite_subject_id'];
        $minimum_grade = $_POST['minimum_grade'] ?? 3.00;
        
        try {
            $insert = "INSERT INTO subject_prerequisites (curriculum_id, prerequisite_curriculum_id, minimum_grade)
                      VALUES (:curriculum_id, :prerequisite_curriculum_id, :minimum_grade)";
            $stmt = $conn->prepare($insert);
            $stmt->bindParam(':curriculum_id', $subject_id);
            $stmt->bindParam(':prerequisite_curriculum_id', $prerequisite_subject_id);
            $stmt->bindParam(':minimum_grade', $minimum_grade);
            $stmt->execute();
            $_SESSION['message'] = 'Prerequisite added successfully!';
        } catch (Exception $e) {
            $_SESSION['message'] = 'Error: ' . $e->getMessage();
        }
        redirect('admin/manage_prerequisites.php');
    } elseif (isset($_POST['action']) && $_POST['action'] === 'delete_prerequisite') {
        $prerequisite_id = $_POST['prerequisite_id'];
        try {
            $delete = "DELETE FROM subject_prerequisites WHERE id = :id";
            $stmt = $conn->prepare($delete);
            $stmt->bindParam(':id', $prerequisite_id);
            $stmt->execute();
            $_SESSION['message'] = 'Prerequisite deleted successfully!';
        } catch (Exception $e) {
            $_SESSION['message'] = 'Error: ' . $e->getMessage();
        }
        redirect('admin/manage_prerequisites.php');
    }
}

// Get all prerequisites with subject names (from curriculum)
$prereq_query = "SELECT sp.*, 
                 c1.course_code as subject_code, c1.course_name as subject_name,
                 c2.course_code as prereq_code, c2.course_name as prereq_name
                 FROM subject_prerequisites sp
                 JOIN curriculum c1 ON sp.curriculum_id = c1.id
                 JOIN curriculum c2 ON sp.prerequisite_curriculum_id = c2.id
                 ORDER BY c1.course_code";
$prereq_stmt = $conn->prepare($prereq_query);
$prereq_stmt->execute();
$prerequisites = $prereq_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Prerequisites - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body class="bg-light">
    <div class="container mt-5">
        <div class="row mb-3">
            <div class="col">
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                </a>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-primary text-white">
                <h4><i class="fas fa-link me-2"></i>Manage Subject Prerequisites</h4>
            </div>
            <div class="card-body">
                <?php if (isset($_SESSION['message'])): ?>
                    <div class="alert alert-info alert-dismissible fade show">
                        <?php echo htmlspecialchars($_SESSION['message']); unset($_SESSION['message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <button class="btn btn-success mb-3" data-bs-toggle="modal" data-bs-target="#addPrereqModal">
                    <i class="fas fa-plus me-2"></i>Add Prerequisite
                </button>

                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>Subject</th>
                                <th>Requires Prerequisite</th>
                                <th>Minimum Grade</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($prerequisites as $prereq): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($prereq['subject_code']); ?></strong><br>
                                        <small><?php echo htmlspecialchars($prereq['subject_name']); ?></small>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($prereq['prereq_code']); ?></strong><br>
                                        <small><?php echo htmlspecialchars($prereq['prereq_name']); ?></small>
                                    </td>
                                    <td>
                                        <span class="badge bg-info"><?php echo $prereq['minimum_grade']; ?></span>
                                    </td>
                                    <td>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="delete_prerequisite">
                                            <input type="hidden" name="prerequisite_id" value="<?php echo $prereq['id']; ?>">
                                            <button type="submit" class="btn btn-danger btn-sm" 
                                                    onclick="return confirm('Remove this prerequisite?')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Prerequisite Modal -->
    <div class="modal fade" id="addPrereqModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Add Prerequisite</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_prerequisite">
                        
                        <div class="mb-3">
                            <label class="form-label">Subject</label>
                            <select name="subject_id" class="form-select" required>
                                <option value="">Select subject...</option>
                                <?php foreach ($all_subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>">
                                        <?php echo htmlspecialchars($subject['course_code'] . ' - ' . $subject['course_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Requires (Prerequisite Subject)</label>
                            <select name="prerequisite_subject_id" class="form-select" required>
                                <option value="">Select prerequisite...</option>
                                <?php foreach ($all_subjects as $subject): ?>
                                    <option value="<?php echo $subject['id']; ?>">
                                        <?php echo htmlspecialchars($subject['course_code'] . ' - ' . $subject['course_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Minimum Passing Grade</label>
                            <select name="minimum_grade" class="form-select" required>
                                <option value="3.00">3.00 (Passing)</option>
                                <option value="2.75">2.75</option>
                                <option value="2.50">2.50</option>
                                <option value="2.25">2.25</option>
                                <option value="2.00">2.00</option>
                                <option value="1.75">1.75</option>
                                <option value="1.50">1.50</option>
                                <option value="1.25">1.25</option>
                                <option value="1.00">1.00</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Add Prerequisite</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

