<?php
require_once '../config/database.php';
require_once '../classes/User.php';

$message = '';
$error = '';
$step = isset($_GET['step']) ? $_GET['step'] : 1;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($step == 1) {
        // Step 1: Verify student identity
        $student_id = trim($_POST['student_id']);
        $first_name = trim($_POST['first_name']);
        $last_name = trim($_POST['last_name']);
        $email = trim($_POST['email']);
        
        $db = new Database();
        $conn = $db->getConnection();
        
        // Check if student exists in enrolled_students and doesn't have an account yet
        $query = "SELECT * FROM enrolled_students 
                  WHERE student_id = :student_id 
                  AND LOWER(first_name) = LOWER(:first_name)
                  AND LOWER(last_name) = LOWER(:last_name)
                  AND LOWER(email) = LOWER(:email)
                  AND user_id IS NULL";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':student_id', $student_id);
        $stmt->bindParam(':first_name', $first_name);
        $stmt->bindParam(':last_name', $last_name);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        
        $enrolled_student = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($enrolled_student) {
            // Store enrolled_student data in session for step 2
            $_SESSION['claim_data'] = $enrolled_student;
            header("Location: claim_account.php?step=2");
            exit();
        } else {
            $error = "We couldn't verify your information. Please check your details and try again. If you already have an account, please login instead.";
        }
    } elseif ($step == 2) {
        // Step 2: Create user account
        if (!isset($_SESSION['claim_data'])) {
            header("Location: claim_account.php?step=1");
            exit();
        }
        
        $password = trim($_POST['password']);
        $confirm_password = trim($_POST['confirm_password']);
        
        if ($password !== $confirm_password) {
            $error = "Passwords do not match";
        } elseif (strlen($password) < 6) {
            $error = "Password must be at least 6 characters long";
        } else {
            $db = new Database();
            $conn = $db->getConnection();
            $enrolled_data = $_SESSION['claim_data'];
            
            try {
                $conn->beginTransaction();
                
                // Create user account
                $user = new User();
                $user_id = $user->createStudent(
                    $enrolled_data['student_id'],
                    $enrolled_data['first_name'],
                    $enrolled_data['last_name'],
                    $enrolled_data['email'],
                    $enrolled_data['phone'] ?? '',
                    $password
                );
                
                if ($user_id) {
                    // Link user account to enrolled_students record
                    $update_query = "UPDATE enrolled_students 
                                    SET user_id = :user_id 
                                    WHERE id = :enrolled_id";
                    $update_stmt = $conn->prepare($update_query);
                    $update_stmt->bindParam(':user_id', $user_id);
                    $update_stmt->bindParam(':enrolled_id', $enrolled_data['id']);
                    $update_stmt->execute();
                    
                    $conn->commit();
                    
                    unset($_SESSION['claim_data']);
                    $_SESSION['message'] = "Account created successfully! You can now login with your email and password.";
                    header("Location: login.php");
                    exit();
                } else {
                    throw new Exception("Failed to create user account");
                }
            } catch (Exception $e) {
                $conn->rollBack();
                $error = "Error creating account: " . $e->getMessage();
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Claim Your Account - OCC Enrollment System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .claim-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            max-width: 500px;
            width: 100%;
        }
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
        }
        .step {
            flex: 1;
            text-align: center;
            padding: 10px;
            background: #f0f0f0;
            position: relative;
        }
        .step.active {
            background: #667eea;
            color: white;
        }
        .step.completed {
            background: #28a745;
            color: white;
        }
        .step:not(:last-child)::after {
            content: '';
            position: absolute;
            right: -10px;
            top: 50%;
            transform: translateY(-50%);
            width: 0;
            height: 0;
            border-top: 25px solid transparent;
            border-bottom: 25px solid transparent;
            border-left: 10px solid #f0f0f0;
            z-index: 1;
        }
        .step.active:not(:last-child)::after {
            border-left-color: #667eea;
        }
        .step.completed:not(:last-child)::after {
            border-left-color: #28a745;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="claim-card p-5">
            <div class="text-center mb-4">
                <i class="fas fa-user-check fa-3x text-primary mb-3"></i>
                <h2>Claim Your Student Account</h2>
                <p class="text-muted">Verify your identity to create your account</p>
            </div>

            <!-- Step Indicator -->
            <div class="step-indicator">
                <div class="step <?php echo $step == 1 ? 'active' : ($step > 1 ? 'completed' : ''); ?>">
                    <strong>1</strong> Verify
                </div>
                <div class="step <?php echo $step == 2 ? 'active' : ($step > 2 ? 'completed' : ''); ?>">
                    <strong>2</strong> Create Password
                </div>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
                </div>
            <?php endif; ?>

            <?php if ($message): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle me-2"></i><?php echo $message; ?>
                </div>
            <?php endif; ?>

            <?php if ($step == 1): ?>
                <!-- Step 1: Identity Verification -->
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">
                            <i class="fas fa-id-card me-2"></i>Student ID
                        </label>
                        <input type="text" name="student_id" class="form-control" required 
                               placeholder="Enter your student ID">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            <i class="fas fa-user me-2"></i>First Name
                        </label>
                        <input type="text" name="first_name" class="form-control" required 
                               placeholder="Enter your first name">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            <i class="fas fa-user me-2"></i>Last Name
                        </label>
                        <input type="text" name="last_name" class="form-control" required 
                               placeholder="Enter your last name">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            <i class="fas fa-envelope me-2"></i>Email
                        </label>
                        <input type="email" name="email" class="form-control" required 
                               placeholder="Enter your email address">
                    </div>

                    <div class="alert alert-info">
                        <small>
                            <i class="fas fa-info-circle me-2"></i>
                            Enter the information exactly as provided by your institution.
                        </small>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 mb-3">
                        <i class="fas fa-arrow-right me-2"></i>Verify Identity
                    </button>

                    <div class="text-center">
                        <a href="login.php" class="text-decoration-none">
                            <i class="fas fa-arrow-left me-2"></i>Back to Login
                        </a>
                    </div>
                </form>

            <?php elseif ($step == 2 && isset($_SESSION['claim_data'])): ?>
                <!-- Step 2: Create Password -->
                <?php $data = $_SESSION['claim_data']; ?>
                <div class="alert alert-success mb-4">
                    <i class="fas fa-check-circle me-2"></i>
                    <strong>Identity Verified!</strong><br>
                    <small>Welcome, <?php echo htmlspecialchars($data['first_name'] . ' ' . $data['last_name']); ?></small>
                </div>

                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">
                            <i class="fas fa-lock me-2"></i>Create Password
                        </label>
                        <input type="password" name="password" class="form-control" required 
                               placeholder="Enter a secure password" minlength="6">
                        <small class="text-muted">Minimum 6 characters</small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">
                            <i class="fas fa-lock me-2"></i>Confirm Password
                        </label>
                        <input type="password" name="confirm_password" class="form-control" required 
                               placeholder="Re-enter your password">
                    </div>

                    <div class="alert alert-warning">
                        <small>
                            <i class="fas fa-shield-alt me-2"></i>
                            Keep your password secure and don't share it with anyone.
                        </small>
                    </div>

                    <button type="submit" class="btn btn-success w-100 mb-3">
                        <i class="fas fa-check me-2"></i>Create Account
                    </button>

                    <div class="text-center">
                        <a href="claim_account.php?step=1" class="text-decoration-none">
                            <i class="fas fa-arrow-left me-2"></i>Back
                        </a>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <div class="text-center mt-4">
            <p class="text-white">
                <small>
                    Already have an account? <a href="login.php" class="text-white fw-bold">Login here</a>
                </small>
            </p>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

