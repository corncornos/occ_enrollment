<?php
declare(strict_types=1);

require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/Admin.php';

// Check if user is logged in and is an admin
if (!isLoggedIn() || !isAdmin()) {
    redirect('../public/login.php');
}

$admin = new Admin();
$conn = (new Database())->getConnection();

// Get current adjustment period control settings
$adjustment_control = $admin->getAdjustmentPeriodControl();

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $academic_year = sanitizeInput($_POST['academic_year'] ?? '');
    $semester = sanitizeInput($_POST['semester'] ?? '');
    $status = sanitizeInput($_POST['adjustment_status'] ?? 'closed');
    $opening_date = $_POST['opening_date'] ?? null;
    $closing_date = $_POST['closing_date'] ?? null;
    $announcement = sanitizeInput($_POST['announcement'] ?? '');
    
    if (empty($academic_year) || empty($semester)) {
        $_SESSION['message'] = 'Academic year and semester are required.';
    } else {
        if ($admin->updateAdjustmentPeriodControl($academic_year, $semester, $status, $opening_date, $closing_date, $announcement)) {
            $_SESSION['message'] = 'Adjustment period control updated successfully!';
            redirect('admin/adjustment_period_control.php');
        } else {
            $_SESSION['message'] = 'Failed to update adjustment period control.';
        }
    }
}

$message = '';
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Adjustment Period Control - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f2f6fc;
        }
        .card {
            border: none;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border-radius: 15px;
        }
        .status-badge {
            font-size: 1.2rem;
            padding: 0.5rem 1rem;
        }
    </style>
</head>
<body>
    <div class="container mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1 class="h4 mb-0"><i class="fas fa-exchange-alt me-2 text-primary"></i>Adjustment Period Control</h1>
            <a href="<?php echo function_exists('add_session_to_url') ? add_session_to_url('dashboard.php') : 'dashboard.php'; ?>" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
            </a>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-info alert-dismissible fade show">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-sliders-h me-2"></i>Adjustment Period Settings</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Academic Year</label>
                                    <input type="text" class="form-control" name="academic_year" 
                                           value="<?php echo htmlspecialchars($adjustment_control['academic_year'] ?? 'AY 2024-2025'); ?>" 
                                           placeholder="e.g., AY 2024-2025" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Semester</label>
                                    <select class="form-select" name="semester" required>
                                        <option value="First Semester" <?php echo (isset($adjustment_control['semester']) && $adjustment_control['semester'] == 'First Semester') ? 'selected' : ''; ?>>First Semester</option>
                                        <option value="Second Semester" <?php echo (isset($adjustment_control['semester']) && $adjustment_control['semester'] == 'Second Semester') ? 'selected' : ''; ?>>Second Semester</option>
                                        <option value="Summer" <?php echo (isset($adjustment_control['semester']) && $adjustment_control['semester'] == 'Summer') ? 'selected' : ''; ?>>Summer</option>
                                    </select>
                                </div>
                            </div>

                            <div class="row mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Opening Date</label>
                                    <input type="date" class="form-control" name="opening_date" 
                                           value="<?php echo htmlspecialchars($adjustment_control['opening_date'] ?? ''); ?>">
                                    <small class="text-muted">Leave empty for no opening date restriction</small>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Closing Date</label>
                                    <input type="date" class="form-control" name="closing_date" 
                                           value="<?php echo htmlspecialchars($adjustment_control['closing_date'] ?? ''); ?>">
                                    <small class="text-muted">Leave empty for no closing date restriction</small>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Announcement Message</label>
                                <textarea class="form-control" name="announcement" rows="3" 
                                          placeholder="Message to display to students about adjustment period..."><?php echo htmlspecialchars($adjustment_control['announcement'] ?? ''); ?></textarea>
                                <small class="text-muted">This message will be shown to students on the adjustment period page</small>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Adjustment Period Status</label>
                                <select class="form-select" name="adjustment_status" required>
                                    <option value="open" <?php echo (isset($adjustment_control['adjustment_status']) && $adjustment_control['adjustment_status'] == 'open') ? 'selected' : ''; ?>>Open</option>
                                    <option value="closed" <?php echo (!isset($adjustment_control['adjustment_status']) || $adjustment_control['adjustment_status'] == 'closed') ? 'selected' : ''; ?>>Closed</option>
                                </select>
                                <small class="text-muted">When closed, students cannot make adjustments to their enrollment</small>
                            </div>

                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="fas fa-save me-2"></i>Save Settings
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Current Status</h5>
                    </div>
                    <div class="card-body text-center">
                        <?php 
                        $is_open = $admin->isAdjustmentPeriodOpen();
                        $current_status = $adjustment_control['adjustment_status'] ?? 'closed';
                        ?>
                        <div class="mb-3">
                            <span class="badge <?php echo $is_open ? 'bg-success' : 'bg-danger'; ?> status-badge">
                                <?php echo $is_open ? 'OPEN' : 'CLOSED'; ?>
                            </span>
                        </div>
                        <p class="text-muted mb-2">
                            <strong>Status:</strong> <?php echo strtoupper($current_status); ?>
                        </p>
                        <?php if ($adjustment_control): ?>
                            <?php if ($adjustment_control['opening_date']): ?>
                                <p class="text-muted mb-1">
                                    <strong>Opens:</strong> <?php echo date('M j, Y', strtotime($adjustment_control['opening_date'])); ?>
                                </p>
                            <?php endif; ?>
                            <?php if ($adjustment_control['closing_date']): ?>
                                <p class="text-muted mb-1">
                                    <strong>Closes:</strong> <?php echo date('M j, Y', strtotime($adjustment_control['closing_date'])); ?>
                                </p>
                            <?php endif; ?>
                            <?php if ($adjustment_control['updated_at']): ?>
                                <p class="text-muted mb-0 small">
                                    <strong>Last Updated:</strong><br>
                                    <?php echo date('M j, Y g:i A', strtotime($adjustment_control['updated_at'])); ?>
                                </p>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card mt-3">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="fas fa-exclamation-triangle me-2"></i>Important Notes</h5>
                    </div>
                    <div class="card-body">
                        <ul class="mb-0 small">
                            <li>Only students with <strong>confirmed enrollment</strong> can access the adjustment period.</li>
                            <li>Students can add/remove subjects and change schedules during the adjustment period.</li>
                            <li>Prerequisites are still enforced when adding subjects.</li>
                            <li>Irregular students can add backload subjects from their program.</li>
                            <li>Opening and closing dates are optional but recommended for better control.</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>


