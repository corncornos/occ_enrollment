<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/User.php';
require_once '../classes/Admin.php';
require_once '../classes/Admission.php';
require_once '../classes/ProgramHead.php';

// Redirect if already logged in
if (isLoggedIn()) {
    if (isDean()) {
        redirect('dean/dashboard.php');
    } elseif (isAdmin()) {
        redirect('admin/dashboard.php');
    } elseif (isAdmission()) {
        redirect('admission/dashboard.php');
    } elseif (isProgramHead()) {
        redirect('program_head/dashboard.php');
    } elseif (isRegistrarStaff()) {
        redirect('registrar_staff/dashboard.php');
    } else {
        redirect('student/dashboard.php');
    }
}

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = sanitizeInput($_POST['email']);
    $password = $_POST['password'];

    if (!empty($email) && !empty($password)) {
        // Try logging in as admin/dean first
        $admin = new Admin();
        $admin_result = $admin->login($email, $password);

        if ($admin_result['success']) {
            // Check if dean or regular admin
            if (isDean()) {
                redirect('dean/dashboard.php');
            } else {
                redirect('admin/dashboard.php');
            }
        }

        // Try logging in as admission
        $admission = new Admission();
        $admission_result = $admission->login($email, $password);

        if ($admission_result['success']) {
            // Admission login successful
            redirect('admission/dashboard.php');
        }

        // Try logging in as program head
        $programHead = new ProgramHead();
        $programHead_result = $programHead->login($email, $password);

        if ($programHead_result['success']) {
            // Program head login successful
            redirect('program_head/dashboard.php');
        }

        // Try logging in as registrar staff
        require_once '../classes/RegistrarStaff.php';
        $registrarStaff = new RegistrarStaff();
        $registrarStaff_result = $registrarStaff->login($email, $password);

        if ($registrarStaff_result['success']) {
            // Registrar staff login successful
            redirect('registrar_staff/dashboard.php');
        }

        // Try logging in as student
        $user = new User();
        $student_result = $user->login($email, $password);

        if ($student_result['success']) {
            // Student login successful
            redirect('student/dashboard.php');
        }

        // All failed - show specific error message if available
        if (isset($admin_result['message'])) {
            $error_message = $admin_result['message'];
        } elseif (isset($admission_result['message'])) {
            $error_message = $admission_result['message'];
        } elseif (isset($programHead_result['message'])) {
            $error_message = $programHead_result['message'];
        } elseif (isset($registrarStaff_result['message'])) {
            $error_message = $registrarStaff_result['message'];
        } elseif (isset($student_result['message']) && strpos($student_result['message'], 'not active') !== false) {
            $error_message = $student_result['message'];
        } else {
            $error_message = 'Invalid email or password';
        }
    } else {
        $error_message = 'Please fill in all fields';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            background: white;
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0,0,0,0.1);
            overflow: hidden;
            max-width: 400px;
            width: 100%;
        }
        .login-header {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        .login-logo {
            width: 90px;
            height: 90px;
            object-fit: contain;
            margin-bottom: 1rem;
            background: white;
            border-radius: 50%;
            padding: 0.5rem;
        }
        .login-body {
            padding: 2rem;
        }
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        .btn-login {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            border: none;
            border-radius: 50px;
            padding: 12px 30px;
            font-weight: 600;
            transition: transform 0.2s;
        }
        .btn-login:hover {
            transform: translateY(-2px);
        }
        .input-group-text {
            background: #f8f9fa;
            border-right: none;
        }
        .form-control {
            border-left: none;
        }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-header">
            <img src="assets/images/occ_logo.png" alt="One Cainta College Logo" class="login-logo"
                 onerror="this.style.display='none'; document.getElementById('loginFallbackIcon').classList.remove('d-none');">
            <i id="loginFallbackIcon" class="fas fa-graduation-cap fa-3x mb-3 d-none"></i>
            <h3><?php echo SITE_NAME; ?></h3>
            <p class="mb-0">Please sign in to your account</p>
        </div>
        <div class="login-body">
            <?php if ($error_message): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <?php echo $error_message; ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="mb-3">
                    <label for="email" class="form-label">Email Address</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="fas fa-envelope"></i>
                        </span>
                        <input type="email" class="form-control" id="email" name="email" required
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                    </div>
                </div>

                <div class="mb-4">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text">
                            <i class="fas fa-lock"></i>
                        </span>
                        <input type="password" class="form-control" id="password" name="password" required>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-login w-100 mb-3">
                    <i class="fas fa-sign-in-alt me-2"></i>Sign In
                </button>

                <div class="text-center">
                    <p class="mb-0">Don't have an account?
                        <a href="register.php" class="text-decoration-none fw-bold">Register here</a>
                    </p>
                </div>
            </form>
            
            <hr class="my-4">
            <div class="text-center mb-3">
                <small class="text-muted">
                    Don't have an account? <a href="register.php">Register here</a><br>
                    Existing student? <a href="claim_account.php" class="text-primary fw-bold">Claim your account</a>
                </small>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
