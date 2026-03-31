<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/User.php';

// Redirect if already logged in
if (isLoggedIn()) {
    if (isAdmin()) {
        redirect('admin/dashboard.php');
    } else {
        redirect('student/dashboard.php');
    }
}

$error_message = '';
$success_message = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $data = [
        // Basic Information
        'lrn' => sanitizeInput($_POST['lrn'] ?? ''),
        'occ_examinee_number' => sanitizeInput($_POST['occ_examinee_number'] ?? ''),
        'first_name' => sanitizeInput($_POST['first_name']),
        'last_name' => sanitizeInput($_POST['last_name']),
        'middle_name' => sanitizeInput($_POST['middle_name'] ?? ''),
        'sex_at_birth' => sanitizeInput($_POST['sex_at_birth'] ?? ''),
        'age' => (int)($_POST['age'] ?? 0),
        'date_of_birth' => $_POST['date_of_birth'] ?? null,
        'civil_status' => sanitizeInput($_POST['civil_status'] ?? ''),
        'spouse_name' => sanitizeInput($_POST['spouse_name'] ?? ''),
        'contact_number' => sanitizeInput($_POST['contact_number'] ?? ''),
        'email' => sanitizeInput($_POST['email']),
        'password' => $_POST['password'],
        'confirm_password' => $_POST['confirm_password'],
        'phone' => sanitizeInput($_POST['phone'] ?? ''),
        
        // Family Information
        'father_name' => sanitizeInput($_POST['father_name'] ?? ''),
        'father_occupation' => sanitizeInput($_POST['father_occupation'] ?? ''),
        'father_education' => sanitizeInput($_POST['father_education'] ?? ''),
        'mother_maiden_name' => sanitizeInput($_POST['mother_maiden_name'] ?? ''),
        'mother_occupation' => sanitizeInput($_POST['mother_occupation'] ?? ''),
        'mother_education' => sanitizeInput($_POST['mother_education'] ?? ''),
        'number_of_brothers' => (int)($_POST['number_of_brothers'] ?? 0),
        'number_of_sisters' => (int)($_POST['number_of_sisters'] ?? 0),
        'combined_family_income' => sanitizeInput($_POST['combined_family_income'] ?? ''),
        'guardian_name' => sanitizeInput($_POST['guardian_name'] ?? ''),
        
        // Educational Background
        'school_last_attended' => sanitizeInput($_POST['school_last_attended'] ?? ''),
        'school_address' => sanitizeInput($_POST['school_address'] ?? ''),
        
        // PWD Information
        'is_pwd' => isset($_POST['is_pwd']) ? 1 : 0,
        'hearing_disability' => isset($_POST['hearing_disability']) ? 1 : 0,
        'physical_disability' => isset($_POST['physical_disability']) ? 1 : 0,
        'mental_disability' => isset($_POST['mental_disability']) ? 1 : 0,
        'intellectual_disability' => isset($_POST['intellectual_disability']) ? 1 : 0,
        'psychosocial_disability' => isset($_POST['psychosocial_disability']) ? 1 : 0,
        'chronic_illness_disability' => isset($_POST['chronic_illness_disability']) ? 1 : 0,
        'learning_disability' => isset($_POST['learning_disability']) ? 1 : 0,
        
        // Senior High School
        'shs_track' => sanitizeInput($_POST['shs_track'] ?? ''),
        'shs_strand' => sanitizeInput($_POST['shs_strand'] ?? ''),
        
        // Working Student
        'is_working_student' => isset($_POST['is_working_student']) ? 1 : 0,
        'employer' => sanitizeInput($_POST['employer'] ?? ''),
        'work_position' => sanitizeInput($_POST['work_position'] ?? ''),
        'working_hours' => sanitizeInput($_POST['working_hours'] ?? ''),
        
        // Address Information
        'municipality_city' => sanitizeInput($_POST['municipality_city'] ?? ''),
        'permanent_address' => sanitizeInput($_POST['permanent_address'] ?? ''),
        'barangay' => sanitizeInput($_POST['barangay'] ?? ''),
        'address' => sanitizeInput($_POST['address'] ?? ''),
        
        // Program Preference
        'preferred_program' => sanitizeInput($_POST['preferred_program'] ?? '')
    ];
    
    // Validation
    if (empty($data['first_name']) || empty($data['last_name']) || empty($data['email']) || empty($data['password'])) {
        $error_message = 'Please fill in all required fields';
    } elseif (!empty($data['lrn']) && (strlen($data['lrn']) !== 12 || !ctype_digit($data['lrn']))) {
        $error_message = 'LRN must be exactly 12 digits';
    } elseif ($data['password'] !== $data['confirm_password']) {
        $error_message = 'Passwords do not match';
    } elseif (strlen($data['password']) < 6) {
        $error_message = 'Password must be at least 6 characters long';
    } else {
        $user = new User();
        $result = $user->register($data);
        
        if ($result['success']) {
            $success_message = $result['message'];
        } else {
            $error_message = $result['message'];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to <?php echo SITE_NAME; ?> - Enroll Today!</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            font-family: 'Poppins', sans-serif;
        }
        
        .hero-section {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            position: relative;
            overflow: hidden;
        }
        
        .hero-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000"><polygon fill="%23ffffff" fill-opacity="0.1" points="0,1000 1000,0 1000,1000"/></svg>');
            background-size: cover;
        }
        
        .hero-content {
            position: relative;
            z-index: 2;
        }
        
        .floating-shapes {
            position: absolute;
            width: 100%;
            height: 100%;
            overflow: hidden;
            z-index: 1;
        }
        
        .shape {
            position: absolute;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            animation: float 6s ease-in-out infinite;
        }
        
        .shape:nth-child(1) {
            width: 80px;
            height: 80px;
            top: 10%;
            left: 10%;
            animation-delay: 0s;
        }
        
        .shape:nth-child(2) {
            width: 120px;
            height: 120px;
            top: 20%;
            right: 10%;
            animation-delay: 2s;
        }
        
        .shape:nth-child(3) {
            width: 60px;
            height: 60px;
            bottom: 20%;
            left: 20%;
            animation-delay: 4s;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            50% { transform: translateY(-20px) rotate(10deg); }
        }
        
        .enrollment-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            border: none;
            max-width: 100%;
            width: 100%;
        }
        .form-control {
            max-width: 100%;
        }
        .form-control:focus {
            border-color: #1e40af;
            box-shadow: 0 0 0 0.2rem rgba(30, 64, 175, 0.25);
        }
        #enrollment-section {
            background: #f8f9fa;
            padding: 2rem 0;
        }
        #enrollment-section .container-fluid {
            padding-left: 1rem;
            padding-right: 1rem;
        }
        .enrollment-card img {
            max-height: 80px;
            margin-bottom: 1rem;
        }
        .btn-primary, .bg-primary {
            background-color: #1e40af !important;
            border-color: #1e40af !important;
        }
        .btn-primary:hover {
            background-color: #1e3a8a !important;
            border-color: #1e3a8a !important;
        }
        .text-primary {
            color: #1e40af !important;
        }
        h5.text-primary, h3.text-primary {
            color: #1e40af !important;
        }
        h5.text-primary.border-bottom {
            border-bottom-color: #1e40af !important;
        }
        @media (min-width: 992px) {
            .enrollment-card {
                max-width: 1600px;
                margin: 0 auto;
            }
        }
        
        .welcome-text {
            color: white;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.3);
        }
        
        .welcome-text h1 {
            font-size: 3.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
            line-height: 1.2;
        }
        
        .welcome-text p {
            font-size: 1.2rem;
            margin-bottom: 2rem;
            opacity: 0.9;
        }
        
        .feature-icon {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            width: 60px;
            height: 60px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            font-size: 1.5rem;
        }
        
        .btn-enroll-now {
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border: none;
            border-radius: 50px;
            padding: 15px 30px;
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s ease;
            box-shadow: 0 5px 15px rgba(40, 167, 69, 0.3);
        }
        
        .btn-enroll-now:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(40, 167, 69, 0.4);
        }
        
        .form-control {
            border-radius: 10px;
            border: 2px solid #e9ecef;
            padding: 12px 15px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
        }
        
        .form-label {
            font-weight: 600;
            color: #495057;
            margin-bottom: 8px;
        }
        
        .stats-section {
            background: white;
            padding: 80px 0;
        }
        
        .stat-item {
            text-align: center;
            padding: 30px 20px;
            border-radius: 15px;
            background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
            margin-bottom: 30px;
            transition: transform 0.3s ease;
        }
        
        .stat-item:hover {
            transform: translateY(-5px);
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            color: #667eea;
            margin-bottom: 10px;
        }
        
        .login-link {
            color: white;
            text-decoration: none;
            font-weight: 600;
            padding: 10px 20px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 25px;
            transition: all 0.3s ease;
        }
        
        .login-link:hover {
            color: #667eea;
            background: white;
            text-decoration: none;
        }
        
        .section-title {
            font-size: 2.5rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 3rem;
            text-align: center;
        }
        
        @media (max-width: 768px) {
            .welcome-text h1 {
                font-size: 2.5rem;
            }
            .hero-section {
                padding: 20px 0;
            }
        }
    </style>
</head>
<body>
    <!-- Hero Section -->
    <section class="hero-section">
        <div class="floating-shapes">
            <div class="shape"></div>
            <div class="shape"></div>
            <div class="shape"></div>
        </div>
        
        <div class="container hero-content">
            <div class="row align-items-center">
                <!-- Welcome Content -->
                <div class="col-lg-6 mb-5 mb-lg-0">
                    <div class="welcome-text">
                        <h1><i class="fas fa-graduation-cap me-3"></i>Welcome to OCC</h1>
                        <p class="lead">Start your academic journey with us! Join thousands of students who have transformed their lives through quality education.</p>
                        
                        <div class="row g-4 mb-4">
                            <div class="col-md-6">
                                <div class="feature-icon">
                                    <i class="fas fa-star"></i>
                                </div>
                                <h5>Excellence in Education</h5>
                                <p class="mb-0 opacity-75">Top-rated courses and experienced faculty</p>
                            </div>
                            <div class="col-md-6">
                                <div class="feature-icon">
                                    <i class="fas fa-users"></i>
                                </div>
                                <h5>Vibrant Community</h5>
                                <p class="mb-0 opacity-75">Join a diverse and supportive student body</p>
                            </div>
                        </div>
                        
                        <div class="d-flex flex-wrap gap-3 align-items-center">
                            <button class="btn btn-enroll-now text-white" onclick="scrollToEnrollment()">
                                <i class="fas fa-rocket me-2"></i>Enroll Now
                            </button>
                            <a href="public/login.php" class="login-link">
                                <i class="fas fa-sign-in-alt me-2"></i>Already a Student? Login
                            </a>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Stats -->
                <div class="col-lg-6">
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="stat-item">
                                <div class="stat-number">500+</div>
                                <div class="text-muted">Active Students</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-item">
                                <div class="stat-number">50+</div>
                                <div class="text-muted">Courses</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-item">
                                <div class="stat-number">95%</div>
                                <div class="text-muted">Success Rate</div>
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="stat-item">
                                <div class="stat-number">4.8</div>
                                <div class="text-muted">Rating</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
    
    <!-- Enrollment Form Section -->
    <section id="enrollment-section" class="stats-section">
        <div class="container-fluid px-2">
            <div class="section-title">
                <i class="fas fa-edit text-primary me-3"></i>Start Your Journey Today
            </div>
            
            <div class="row justify-content-center">
                <div class="col-12">
                    <div class="enrollment-card p-4">
                        <?php if ($error_message): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <?php echo $error_message; ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ($success_message): ?>
                            <div class="alert alert-success alert-dismissible fade show" role="alert">
                                <i class="fas fa-check-circle me-2"></i>
                                <?php echo $success_message; ?>
                                <hr>
                                <div class="d-flex gap-2">
                                    <a href="public/login.php" class="btn btn-success btn-sm">
                                        <i class="fas fa-sign-in-alt me-1"></i>Login Now
                                    </a>
                                    <button type="button" class="btn btn-outline-success btn-sm" onclick="location.reload()">
                                        <i class="fas fa-plus me-1"></i>Register Another Student
                                    </button>
                                </div>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>
                        
                        <div class="text-center mb-4">
                            <img src="public/assets/images/occ_logo.png" alt="OCC Logo" class="mb-2">
                            <h3 class="text-primary mb-2">Student Enrollment Form</h3>
                            <p class="text-muted">Fill out the form below to create your student account and begin your academic journey with us.</p>
                        </div>
                        
                        <form method="POST" action="" id="enrollmentForm">
                            <!-- Basic Information Section -->
                            <h5 class="text-primary mb-3 border-bottom pb-2">Basic Information</h5>
                            
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="lrn" class="form-label">LRN</label>
                                    <input type="text" class="form-control" id="lrn" name="lrn"
                                           value="<?php echo isset($_POST['lrn']) ? htmlspecialchars($_POST['lrn']) : ''; ?>"
                                           placeholder="LRN (12 digits)"
                                           maxlength="12" pattern="[0-9]{12}" 
                                           oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                                    <div class="form-text">Must be exactly 12 digits</div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="occ_examinee_number" class="form-label">OCC Examinee Number</label>
                                    <input type="text" class="form-control" id="occ_examinee_number" name="occ_examinee_number"
                                           value="<?php echo isset($_POST['occ_examinee_number']) ? htmlspecialchars($_POST['occ_examinee_number']) : ''; ?>"
                                           placeholder="OCC Examinee No.">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="contact_number" class="form-label"><i class="fas fa-phone me-1"></i>Contact Number</label>
                                    <input type="tel" class="form-control" id="contact_number" name="contact_number"
                                           value="<?php echo isset($_POST['contact_number']) ? htmlspecialchars($_POST['contact_number']) : ''; ?>"
                                           placeholder="Contact no.">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="last_name" class="form-label"><i class="fas fa-user me-1"></i>Last Name *</label>
                                    <input type="text" class="form-control" id="last_name" name="last_name" required
                                           value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>"
                                           placeholder="Last name">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="first_name" class="form-label"><i class="fas fa-user me-1"></i>First Name *</label>
                                    <input type="text" class="form-control" id="first_name" name="first_name" required
                                           value="<?php echo isset($_POST['first_name']) ? htmlspecialchars($_POST['first_name']) : ''; ?>"
                                           placeholder="First name">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="middle_name" class="form-label">Middle Name</label>
                                    <input type="text" class="form-control" id="middle_name" name="middle_name"
                                           value="<?php echo isset($_POST['middle_name']) ? htmlspecialchars($_POST['middle_name']) : ''; ?>"
                                           placeholder="Middle name">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-2 mb-3">
                                    <label for="sex_at_birth" class="form-label">Sex at Birth</label>
                                    <select class="form-control" id="sex_at_birth" name="sex_at_birth">
                                        <option value="">Select...</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                    </select>
                                </div>
                                <div class="col-md-2 mb-3">
                                    <label for="age" class="form-label">Age</label>
                                    <input type="number" class="form-control" id="age" name="age"
                                           value="<?php echo isset($_POST['age']) ? htmlspecialchars($_POST['age']) : ''; ?>"
                                           placeholder="Age">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="date_of_birth" class="form-label">Date of Birth</label>
                                    <input type="date" class="form-control" id="date_of_birth" name="date_of_birth"
                                           value="<?php echo isset($_POST['date_of_birth']) ? $_POST['date_of_birth'] : ''; ?>">
                                </div>
                                <div class="col-md-3 mb-3">
                                    <label for="civil_status" class="form-label">Civil Status</label>
                                    <select class="form-control" id="civil_status" name="civil_status">
                                        <option value="">Select...</option>
                                        <option value="Single">Single</option>
                                        <option value="Married">Married</option>
                                        <option value="Widowed">Widowed</option>
                                        <option value="Separated">Separated</option>
                                        <option value="Divorced">Divorced</option>
                                    </select>
                                </div>
                                <div class="col-md-2 mb-3">
                                    <label for="email" class="form-label"><i class="fas fa-envelope me-1"></i>Email *</label>
                                    <input type="email" class="form-control" id="email" name="email" required
                                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                                           placeholder="Email">
                                </div>
                            </div>
                            
                            <div class="row" id="spouse_section" style="display: none;">
                                <div class="col-md-6 mb-3">
                                    <label for="spouse_name" class="form-label">Name of Spouse (if married)</label>
                                    <input type="text" class="form-control" id="spouse_name" name="spouse_name"
                                           value="<?php echo isset($_POST['spouse_name']) ? htmlspecialchars($_POST['spouse_name']) : ''; ?>"
                                           placeholder="Spouse name">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="password" class="form-label"><i class="fas fa-lock me-1"></i>Password *</label>
                                    <input type="password" class="form-control" id="password" name="password" required
                                           placeholder="Password">
                                    <div class="form-text">Minimum 6 characters required.</div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="confirm_password" class="form-label"><i class="fas fa-lock me-1"></i>Confirm Password *</label>
                                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required
                                           placeholder="Confirm">
                                </div>
                            </div>
                            
                            <!-- Family Information Section -->
                            <h5 class="text-primary mb-3 border-bottom pb-2 mt-4">Family Information</h5>
                            
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="father_name" class="form-label">Father's Name</label>
                                    <input type="text" class="form-control" id="father_name" name="father_name"
                                           value="<?php echo isset($_POST['father_name']) ? htmlspecialchars($_POST['father_name']) : ''; ?>"
                                           placeholder="Father's name">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="father_occupation" class="form-label">Father's Occupation</label>
                                    <input type="text" class="form-control" id="father_occupation" name="father_occupation"
                                           value="<?php echo isset($_POST['father_occupation']) ? htmlspecialchars($_POST['father_occupation']) : ''; ?>"
                                           placeholder="Occupation">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="father_education" class="form-label">Father's Education</label>
                                    <input type="text" class="form-control" id="father_education" name="father_education"
                                           value="<?php echo isset($_POST['father_education']) ? htmlspecialchars($_POST['father_education']) : ''; ?>"
                                           placeholder="e.g. HS, College">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="mother_maiden_name" class="form-label">Mother's Maiden Name</label>
                                    <input type="text" class="form-control" id="mother_maiden_name" name="mother_maiden_name"
                                           value="<?php echo isset($_POST['mother_maiden_name']) ? htmlspecialchars($_POST['mother_maiden_name']) : ''; ?>"
                                           placeholder="Mother's maiden name">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="mother_occupation" class="form-label">Mother's Occupation</label>
                                    <input type="text" class="form-control" id="mother_occupation" name="mother_occupation"
                                           value="<?php echo isset($_POST['mother_occupation']) ? htmlspecialchars($_POST['mother_occupation']) : ''; ?>"
                                           placeholder="Occupation">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="mother_education" class="form-label">Mother's Education</label>
                                    <input type="text" class="form-control" id="mother_education" name="mother_education"
                                           value="<?php echo isset($_POST['mother_education']) ? htmlspecialchars($_POST['mother_education']) : ''; ?>"
                                           placeholder="e.g. HS, College">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <label for="number_of_brothers" class="form-label">Number of Brother/s</label>
                                    <input type="number" class="form-control" id="number_of_brothers" name="number_of_brothers"
                                           value="<?php echo isset($_POST['number_of_brothers']) ? htmlspecialchars($_POST['number_of_brothers']) : '0'; ?>"
                                           placeholder="0" min="0">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="number_of_sisters" class="form-label">Number of Sister/s</label>
                                    <input type="number" class="form-control" id="number_of_sisters" name="number_of_sisters"
                                           value="<?php echo isset($_POST['number_of_sisters']) ? htmlspecialchars($_POST['number_of_sisters']) : '0'; ?>"
                                           placeholder="0" min="0">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <label for="combined_family_income" class="form-label">Combined Family Income</label>
                                    <input type="text" class="form-control" id="combined_family_income" name="combined_family_income"
                                           value="<?php echo isset($_POST['combined_family_income']) ? htmlspecialchars($_POST['combined_family_income']) : ''; ?>"
                                           placeholder="e.g. ₱10K-₱20K">
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="guardian_name" class="form-label">Name of Guardian (If Applicable)</label>
                                    <input type="text" class="form-control" id="guardian_name" name="guardian_name"
                                           value="<?php echo isset($_POST['guardian_name']) ? htmlspecialchars($_POST['guardian_name']) : ''; ?>"
                                           placeholder="Guardian name">
                                </div>
                            </div>
                            
                            <!-- Educational Background Section -->
                            <h5 class="text-primary mb-3 border-bottom pb-2 mt-4">Educational Background</h5>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="school_last_attended" class="form-label">Name of School Last Attended</label>
                                    <input type="text" class="form-control" id="school_last_attended" name="school_last_attended"
                                           value="<?php echo isset($_POST['school_last_attended']) ? htmlspecialchars($_POST['school_last_attended']) : ''; ?>"
                                           placeholder="School name">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="school_address" class="form-label">Address of School Last Attended</label>
                                    <input type="text" class="form-control" id="school_address" name="school_address"
                                           value="<?php echo isset($_POST['school_address']) ? htmlspecialchars($_POST['school_address']) : ''; ?>"
                                           placeholder="School address">
                                </div>
                            </div>
                            
                            <!-- PWD Section -->
                            <h5 class="text-primary mb-3 border-bottom pb-2 mt-4">Disability Information</h5>
                            
                            <div class="mb-3">
                                <label class="form-label">Are you a Person with Disability (PWD)?</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="is_pwd" name="is_pwd">
                                    <label class="form-check-label" for="is_pwd">Yes, I have a disability</label>
                                </div>
                            </div>
                            
                            <div id="disability_types" style="display: none;">
                                <label class="form-label">Type of Disability (check all that apply):</label>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="hearing_disability" name="hearing_disability">
                                            <label class="form-check-label" for="hearing_disability">Hearing Disability</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="physical_disability" name="physical_disability">
                                            <label class="form-check-label" for="physical_disability">Physical Disability</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="mental_disability" name="mental_disability">
                                            <label class="form-check-label" for="mental_disability">Mental Disability</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="intellectual_disability" name="intellectual_disability">
                                            <label class="form-check-label" for="intellectual_disability">Intellectual Disability</label>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="psychosocial_disability" name="psychosocial_disability">
                                            <label class="form-check-label" for="psychosocial_disability">Psychosocial Disability</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="chronic_illness_disability" name="chronic_illness_disability">
                                            <label class="form-check-label" for="chronic_illness_disability">Chronic Illnesses Disability</label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" id="learning_disability" name="learning_disability">
                                            <label class="form-check-label" for="learning_disability">Learning Disability</label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Senior High School Track Section -->
                            <h5 class="text-primary mb-3 border-bottom pb-2 mt-4">Senior High School Track</h5>
                            
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="shs_track" class="form-label">Senior High School Track</label>
                                    <select class="form-control" id="shs_track" name="shs_track">
                                        <option value="">Select Track...</option>
                                        <option value="Academic Track">Academic Track</option>
                                        <option value="Arts and Design Track">Arts and Design Track</option>
                                        <option value="Sports Track">Sports Track</option>
                                        <option value="Technical-Vocational Livelihood Track">Technical-Vocational Livelihood Track</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div id="academic_strand_section" class="row" style="display: none;">
                                <div class="col-md-12 mb-3">
                                    <label for="shs_strand" class="form-label">Academic Strand</label>
                                    <select class="form-control" id="academic_strand" name="shs_strand">
                                        <option value="">Select Strand...</option>
                                        <option value="Accountancy, Business and Management (ABM) Strand">Accountancy, Business and Management (ABM) Strand</option>
                                        <option value="Science, Technology, Engineering and Mathematics (STEM) Strand">Science, Technology, Engineering and Mathematics (STEM) Strand</option>
                                        <option value="Humanities and Social Science (HUMSS) Strand">Humanities and Social Science (HUMSS) Strand</option>
                                        <option value="General Academic Strand (GAS)">General Academic Strand (GAS)</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div id="tvl_strand_section" class="row" style="display: none;">
                                <div class="col-md-12 mb-3">
                                    <label for="shs_strand" class="form-label">Technical-Vocational Strand</label>
                                    <select class="form-control" id="tvl_strand" name="shs_strand">
                                        <option value="">Select Strand...</option>
                                        <option value="Agricultural-Fishery Arts (AFA) Strand">Agricultural-Fishery Arts (AFA) Strand</option>
                                        <option value="Home Economics (HE) Strand">Home Economics (HE) Strand</option>
                                        <option value="Industrial Arts (IA) Strand">Industrial Arts (IA) Strand</option>
                                        <option value="Information and Communication Technology (ICT) Strand">Information and Communication Technology (ICT) Strand</option>
                                    </select>
                                </div>
                            </div>
                            
                            <!-- Working Student Section -->
                            <h5 class="text-primary mb-3 border-bottom pb-2 mt-4">Employment Information</h5>
                            
                            <div class="mb-3">
                                <label class="form-label">Are you a working student?</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="is_working_student" name="is_working_student">
                                    <label class="form-check-label" for="is_working_student">Yes, I am currently working</label>
                                </div>
                            </div>
                            
                            <div id="working_student_details" style="display: none;">
                                <div class="row">
                                    <div class="col-md-12 mb-3">
                                        <label for="employer" class="form-label">Who is your Employer?</label>
                                        <input type="text" class="form-control" id="employer" name="employer"
                                               value="<?php echo isset($_POST['employer']) ? htmlspecialchars($_POST['employer']) : ''; ?>"
                                               placeholder="Employer">
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label for="work_position" class="form-label">What is your position?</label>
                                        <input type="text" class="form-control" id="work_position" name="work_position"
                                               value="<?php echo isset($_POST['work_position']) ? htmlspecialchars($_POST['work_position']) : ''; ?>"
                                               placeholder="Position">
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label for="working_hours" class="form-label">Indicate your working hours</label>
                                        <input type="text" class="form-control" id="working_hours" name="working_hours"
                                               value="<?php echo isset($_POST['working_hours']) ? htmlspecialchars($_POST['working_hours']) : ''; ?>"
                                               placeholder="e.g. 9AM-5PM">
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Address Section -->
                            <h5 class="text-primary mb-3 border-bottom pb-2 mt-4">Address Information</h5>
                            
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label for="municipality_city" class="form-label">Municipality/City</label>
                                    <select class="form-control" id="municipality_city" name="municipality_city">
                                        <option value="">Select Municipality/City...</option>
                                        <option value="Antipolo City">Antipolo City</option>
                                        <option value="Binangonan">Binangonan</option>
                                        <option value="Cainta">Cainta</option>
                                        <option value="Cardona">Cardona</option>
                                        <option value="Montalban">Montalban</option>
                                        <option value="Morong">Morong</option>
                                        <option value="Rodriguez">Rodriguez</option>
                                        <option value="San Mateo">San Mateo</option>
                                        <option value="Pililia">Pililia</option>
                                        <option value="Tanay">Tanay</option>
                                        <option value="Taytay">Taytay</option>
                                        <option value="Teresa">Teresa</option>
                                    </select>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label for="barangay" class="form-label">Barangay</label>
                                    <select class="form-control" id="barangay" name="barangay">
                                        <option value="">Select Barangay...</option>
                                        <option value="San Andres">San Andres</option>
                                        <option value="Sto. Domingo">Sto. Domingo</option>
                                        <option value="San Isidro">San Isidro</option>
                                        <option value="San Juan">San Juan</option>
                                        <option value="Sto. Nino">Sto. Nino</option>
                                        <option value="San Roque">San Roque</option>
                                        <option value="Sta. Rosa">Sta. Rosa</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="permanent_address" class="form-label">Permanent Address No. (Bldg Number, Lot No. Street)</label>
                                    <input type="text" class="form-control" id="permanent_address" name="permanent_address"
                                           value="<?php echo isset($_POST['permanent_address']) ? htmlspecialchars($_POST['permanent_address']) : ''; ?>"
                                           placeholder="Bldg/Lot/Street">
                                    <div class="form-text">Must be the same with the address in the Proof of Residency</div>
                                </div>
                            </div>
                            
                            <!-- Program Selection Section -->
                            <h5 class="text-primary mb-3 border-bottom pb-2 mt-4">Program Selection</h5>
                            
                            <div class="row">
                                <div class="col-md-12 mb-3">
                                    <label for="preferred_program" class="form-label">Choose your preferred degree/program course</label>
                                    <select class="form-control" id="preferred_program" name="preferred_program">
                                        <option value="">Select Program...</option>
                                        <option value="Bachelor in Technical Vocational Teacher Education">Bachelor in Technical Vocational Teacher Education</option>
                                        <option value="Bachelor of Science in Entrepreneurship">Bachelor of Science in Entrepreneurship</option>
                                        <option value="Bachelor of Science in Information System">Bachelor of Science in Information System</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="mb-4">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="terms" required>
                                    <label class="form-check-label" for="terms">
                                        I agree to the <a href="#" class="text-primary">Terms and Conditions</a> and <a href="#" class="text-primary">Privacy Policy</a> *
                                    </label>
                                </div>
                            </div>
                            
                            <div class="d-grid">
                                <button type="submit" class="btn btn-enroll-now text-white btn-lg">
                                    <i class="fas fa-user-plus me-2"></i>Complete Enrollment
                                </button>
                            </div>
                            
                            <div class="text-center mt-4">
                                <p class="mb-0 text-muted">Already have an account? 
                                    <a href="public/login.php" class="text-primary fw-bold text-decoration-none">Login here</a>
                                </p>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </section>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function scrollToEnrollment() {
            document.getElementById('enrollment-section').scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
        
        // Add some interactive effects
        document.addEventListener('DOMContentLoaded', function() {
            // Animate stats on scroll
            const statItems = document.querySelectorAll('.stat-item');
            
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };
            
            const observer = new IntersectionObserver(function(entries) {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.animation = 'fadeInUp 0.6s ease forwards';
                    }
                });
            }, observerOptions);
            
            statItems.forEach(item => {
                observer.observe(item);
            });
        });
        
        // Add CSS for animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes fadeInUp {
                from {
                    opacity: 0;
                    transform: translateY(30px);
                }
                to {
                    opacity: 1;
                    transform: translateY(0);
                }
            }
        `;
        document.head.appendChild(style);
        
        // Conditional field logic
        // Show spouse name field if civil status is married
        const civilStatus = document.getElementById('civil_status');
        const spouseSection = document.getElementById('spouse_section');
        
        if (civilStatus) {
            civilStatus.addEventListener('change', function() {
                if (this.value === 'Married') {
                    spouseSection.style.display = 'block';
                } else {
                    spouseSection.style.display = 'none';
                }
            });
        }
        
        // Show disability types if PWD is checked
        const isPwd = document.getElementById('is_pwd');
        const disabilityTypes = document.getElementById('disability_types');
        
        if (isPwd) {
            isPwd.addEventListener('change', function() {
                if (this.checked) {
                    disabilityTypes.style.display = 'block';
                } else {
                    disabilityTypes.style.display = 'none';
                    // Uncheck all disability types
                    document.querySelectorAll('#disability_types input[type="checkbox"]').forEach(cb => cb.checked = false);
                }
            });
        }
        
        // Show appropriate strand options based on SHS track
        const shsTrack = document.getElementById('shs_track');
        const academicStrandSection = document.getElementById('academic_strand_section');
        const tvlStrandSection = document.getElementById('tvl_strand_section');
        
        if (shsTrack) {
            shsTrack.addEventListener('change', function() {
                // Hide all strand sections first
                academicStrandSection.style.display = 'none';
                tvlStrandSection.style.display = 'none';
                
                // Clear strand selections
                document.getElementById('academic_strand').value = '';
                document.getElementById('tvl_strand').value = '';
                
                // Show appropriate strand section
                if (this.value === 'Academic Track') {
                    academicStrandSection.style.display = 'block';
                } else if (this.value === 'Technical-Vocational Livelihood Track') {
                    tvlStrandSection.style.display = 'block';
                }
            });
        }
        
        // Show working student details if checked
        const isWorkingStudent = document.getElementById('is_working_student');
        const workingStudentDetails = document.getElementById('working_student_details');
        
        if (isWorkingStudent) {
            isWorkingStudent.addEventListener('change', function() {
                if (this.checked) {
                    workingStudentDetails.style.display = 'block';
                } else {
                    workingStudentDetails.style.display = 'none';
                }
            });
        }
    </script>
</body>
</html>
