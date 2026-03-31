<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/User.php';
require_once '../classes/Curriculum.php';

// Redirect if already logged in
if (isLoggedIn()) {
    if (isAdmin()) {
        redirect('admin/dashboard.php');
    } else {
        redirect('student/dashboard.php');
    }
}

// Get all active programs for dropdown
$curriculum = new Curriculum();
$all_programs = $curriculum->getAllPrograms();
$active_programs = array_filter($all_programs, function($p) {
    return ($p['status'] ?? 'active') === 'active';
});

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
        'preferred_program' => sanitizeInput($_POST['preferred_program'] ?? ''),
        
        // Section Selection
        'selected_section' => isset($_POST['selected_section']) && $_POST['selected_section'] !== '' ? (int)$_POST['selected_section'] : null
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
    } elseif (empty($data['preferred_program'])) {
        $error_message = 'Please select a program';
    } elseif (!isset($data['selected_section']) || $data['selected_section'] === null || $data['selected_section'] === 0 || $data['selected_section'] === '') {
        $error_message = 'Please select a section';
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
    <title>Register - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background: #f8f9fa;
            min-height: 100vh;
            padding: 1rem 0;
        }
        .container-fluid {
            padding-left: 1rem;
            padding-right: 1rem;
        }
        .register-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            overflow: hidden;
            max-width: 100%;
            margin: 0 auto;
            width: 100%;
            border: none;
        }
        .register-header {
            background:rgb(16, 31, 81);
            color: white;
            padding: 2rem;
            text-align: center;
            border-radius: 15px 15px 0 0;
        }
        .register-body {
            padding: 2rem;
        }
        .form-control {
            max-width: 100%;
        }
        .form-control:focus {
            border-color: #1e40af;
            box-shadow: 0 0 0 0.2rem rgba(30, 64, 175, 0.25);
        }
        .register-header img {
            max-height: 80px;
            margin-bottom: 1rem;
        }
        @media (min-width: 992px) {
            .register-card {
                max-width: 1600px;
            }
        }
        .btn-register, .btn-primary {
            background-color: #1e40af;
            border-color: #1e40af;
            border-radius: 10px;
            padding: 12px 30px;
            font-weight: 600;
            transition: all 0.2s;
        }
        .btn-register:hover, .btn-primary:hover {
            background-color: #1e3a8a;
            border-color: #1e3a8a;
            transform: translateY(-2px);
        }
        .text-primary {
            color: #1e40af !important;
        }
        h6.text-primary {
            color: #1e40af !important;
        }
        h6.text-primary.fw-bold {
            border-bottom: 2px solid #1e40af;
            padding-bottom: 0.5rem;
        }
        .card-header {
            background: #1e40af;
            color: white;
            border-radius: 15px 15px 0 0;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="register-card">
            <div class="register-header">
                <img src="assets/images/occ_logo.png" alt="OCC Logo" class="mb-2">
                <h3>Student Registration</h3>
                <p class="mb-0">Create your student account</p>
            </div>
            <div class="register-body">
                <?php if ($error_message): ?>
                    <div class="alert alert-danger" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?php echo $error_message; ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success_message): ?>
                    <div class="alert alert-success" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo $success_message; ?>
                        <hr>
                        <a href="login.php" class="btn btn-sm btn-outline-success">Go to Login</a>
                    </div>
                <?php else: ?>
                
                <form method="POST" id="registrationForm">
                    <!-- Basic Information Section -->
                    <h6 class="text-primary mb-3 fw-bold">Basic Information</h6>
                    
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label for="lrn" class="form-label">LRN</label>
                            <input type="text" class="form-control" id="lrn" name="lrn"
                                   value="<?php echo isset($_POST['lrn']) ? htmlspecialchars($_POST['lrn']) : ''; ?>"
                                   placeholder="12 digits"
                                   maxlength="12" pattern="[0-9]{12}" 
                                   oninput="this.value = this.value.replace(/[^0-9]/g, '')">
                            <div class="form-text">12 digits</div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="occ_examinee_number" class="form-label">OCC Examinee No.</label>
                            <input type="text" class="form-control" id="occ_examinee_number" name="occ_examinee_number"
                                   value="<?php echo isset($_POST['occ_examinee_number']) ? htmlspecialchars($_POST['occ_examinee_number']) : ''; ?>"
                                   placeholder="Examinee No.">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="contact_number" class="form-label">Contact No.</label>
                            <input type="tel" class="form-control" id="contact_number" name="contact_number"
                                   value="<?php echo isset($_POST['contact_number']) ? htmlspecialchars($_POST['contact_number']) : ''; ?>"
                                   placeholder="Contact">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="email" class="form-label">Email *</label>
                            <input type="email" class="form-control" id="email" name="email" required
                                   value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>"
                                   placeholder="Email">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="last_name" class="form-label">Last Name *</label>
                            <input type="text" class="form-control" id="last_name" name="last_name" required
                                   value="<?php echo isset($_POST['last_name']) ? htmlspecialchars($_POST['last_name']) : ''; ?>"
                                   placeholder="Last name">
                        </div>
                        <div class="col-md-4 mb-3">
                            <label for="first_name" class="form-label">First Name *</label>
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
                            <label for="sex_at_birth" class="form-label">Sex</label>
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
                            <label for="password" class="form-label">Password *</label>
                            <input type="password" class="form-control" id="password" name="password" required
                                   placeholder="Password">
                            <div class="form-text">Min 6 chars</div>
                        </div>
                    </div>
                    
                    <div class="row" id="spouse_section" style="display: none;">
                        <div class="col-md-4 mb-3">
                            <label for="spouse_name" class="form-label">Spouse Name</label>
                            <input type="text" class="form-control" id="spouse_name" name="spouse_name"
                                   value="<?php echo isset($_POST['spouse_name']) ? htmlspecialchars($_POST['spouse_name']) : ''; ?>"
                                   placeholder="Spouse name">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="confirm_password" class="form-label">Confirm Password *</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" required
                                   placeholder="Confirm">
                        </div>
                    </div>
                    
                    <!-- Family Information Section -->
                    <h6 class="text-primary mb-3 fw-bold mt-4">Family Information</h6>
                    
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label for="father_name" class="form-label">Father's Name</label>
                            <input type="text" class="form-control" id="father_name" name="father_name"
                                   value="<?php echo isset($_POST['father_name']) ? htmlspecialchars($_POST['father_name']) : ''; ?>"
                                   placeholder="Father's name">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="father_occupation" class="form-label">Father's Occupation</label>
                            <input type="text" class="form-control" id="father_occupation" name="father_occupation"
                                   value="<?php echo isset($_POST['father_occupation']) ? htmlspecialchars($_POST['father_occupation']) : ''; ?>"
                                   placeholder="Occupation">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="father_education" class="form-label">Father's Education</label>
                            <input type="text" class="form-control" id="father_education" name="father_education"
                                   value="<?php echo isset($_POST['father_education']) ? htmlspecialchars($_POST['father_education']) : ''; ?>"
                                   placeholder="e.g. HS, College">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="mother_maiden_name" class="form-label">Mother's Maiden Name</label>
                            <input type="text" class="form-control" id="mother_maiden_name" name="mother_maiden_name"
                                   value="<?php echo isset($_POST['mother_maiden_name']) ? htmlspecialchars($_POST['mother_maiden_name']) : ''; ?>"
                                   placeholder="Maiden name">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label for="mother_occupation" class="form-label">Mother's Occupation</label>
                            <input type="text" class="form-control" id="mother_occupation" name="mother_occupation"
                                   value="<?php echo isset($_POST['mother_occupation']) ? htmlspecialchars($_POST['mother_occupation']) : ''; ?>"
                                   placeholder="Occupation">
                        </div>
                        <div class="col-md-3 mb-3">
                            <label for="mother_education" class="form-label">Mother's Education</label>
                            <input type="text" class="form-control" id="mother_education" name="mother_education"
                                   value="<?php echo isset($_POST['mother_education']) ? htmlspecialchars($_POST['mother_education']) : ''; ?>"
                                   placeholder="e.g. HS, College">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label for="number_of_brothers" class="form-label">No. of Brothers</label>
                            <input type="number" class="form-control" id="number_of_brothers" name="number_of_brothers"
                                   value="<?php echo isset($_POST['number_of_brothers']) ? htmlspecialchars($_POST['number_of_brothers']) : '0'; ?>"
                                   placeholder="0" min="0">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label for="number_of_sisters" class="form-label">No. of Sisters</label>
                            <input type="number" class="form-control" id="number_of_sisters" name="number_of_sisters"
                                   value="<?php echo isset($_POST['number_of_sisters']) ? htmlspecialchars($_POST['number_of_sisters']) : '0'; ?>"
                                   placeholder="0" min="0">
                        </div>
                        <div class="col-md-2 mb-3">
                            <label for="combined_family_income" class="form-label">Family Income</label>
                            <input type="text" class="form-control" id="combined_family_income" name="combined_family_income"
                                   value="<?php echo isset($_POST['combined_family_income']) ? htmlspecialchars($_POST['combined_family_income']) : ''; ?>"
                                   placeholder="₱10K-₱20K">
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label for="guardian_name" class="form-label">Guardian Name</label>
                            <input type="text" class="form-control" id="guardian_name" name="guardian_name"
                                   value="<?php echo isset($_POST['guardian_name']) ? htmlspecialchars($_POST['guardian_name']) : ''; ?>"
                                   placeholder="Guardian name">
                        </div>
                    </div>
                    
                    <!-- Educational Background Section -->
                    <h6 class="text-primary mb-3 fw-bold mt-4">Educational Background</h6>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label for="school_last_attended" class="form-label">School Last Attended</label>
                            <input type="text" class="form-control" id="school_last_attended" name="school_last_attended"
                                   value="<?php echo isset($_POST['school_last_attended']) ? htmlspecialchars($_POST['school_last_attended']) : ''; ?>"
                                   placeholder="School name">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="school_address" class="form-label">School Address</label>
                            <input type="text" class="form-control" id="school_address" name="school_address"
                                   value="<?php echo isset($_POST['school_address']) ? htmlspecialchars($_POST['school_address']) : ''; ?>"
                                   placeholder="School address">
                        </div>
                    </div>
                    
                    <!-- PWD Section -->
                    <h6 class="text-primary mb-3 fw-bold mt-4">Disability Information</h6>
                    
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
                    <h6 class="text-primary mb-3 fw-bold mt-4">Senior High School Track</h6>
                    
                    <div class="mb-3">
                        <label for="shs_track" class="form-label">Senior High School Track</label>
                        <select class="form-control" id="shs_track" name="shs_track">
                            <option value="">Select Track...</option>
                            <option value="Academic Track">Academic Track</option>
                            <option value="Arts and Design Track">Arts and Design Track</option>
                            <option value="Sports Track">Sports Track</option>
                            <option value="Technical-Vocational Livelihood Track">Technical-Vocational Livelihood Track</option>
                        </select>
                    </div>
                    
                    <div id="academic_strand_section" style="display: none;">
                        <div class="mb-3">
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
                    
                    <div id="tvl_strand_section" style="display: none;">
                        <div class="mb-3">
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
                    <h6 class="text-primary mb-3 fw-bold mt-4">Employment Information</h6>
                    
                    <div class="mb-3">
                        <label class="form-label">Are you a working student?</label>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" id="is_working_student" name="is_working_student">
                            <label class="form-check-label" for="is_working_student">Yes, I am currently working</label>
                        </div>
                    </div>
                    
                    <div id="working_student_details" style="display: none;">
                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="employer" class="form-label">Employer</label>
                                <input type="text" class="form-control" id="employer" name="employer"
                                       value="<?php echo isset($_POST['employer']) ? htmlspecialchars($_POST['employer']) : ''; ?>"
                                       placeholder="Employer">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="work_position" class="form-label">Position</label>
                                <input type="text" class="form-control" id="work_position" name="work_position"
                                       value="<?php echo isset($_POST['work_position']) ? htmlspecialchars($_POST['work_position']) : ''; ?>"
                                       placeholder="Position">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="working_hours" class="form-label">Working Hours</label>
                                <input type="text" class="form-control" id="working_hours" name="working_hours"
                                       value="<?php echo isset($_POST['working_hours']) ? htmlspecialchars($_POST['working_hours']) : ''; ?>"
                                       placeholder="e.g. 9AM-5PM">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Address Section -->
                    <h6 class="text-primary mb-3 fw-bold mt-4">Address Information</h6>
                    
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <label for="municipality_city" class="form-label">Municipality/City</label>
                            <select class="form-control" id="municipality_city" name="municipality_city">
                                <option value="">Select...</option>
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
                        <div class="col-md-3 mb-3">
                            <label for="barangay" class="form-label">Barangay</label>
                            <select class="form-control" id="barangay" name="barangay">
                                <option value="">Select...</option>
                                <option value="San Andres">San Andres</option>
                                <option value="Sto. Domingo">Sto. Domingo</option>
                                <option value="San Isidro">San Isidro</option>
                                <option value="San Juan">San Juan</option>
                                <option value="Sto. Nino">Sto. Nino</option>
                                <option value="San Roque">San Roque</option>
                                <option value="Sta. Rosa">Sta. Rosa</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label for="permanent_address" class="form-label">Permanent Address</label>
                            <input type="text" class="form-control" id="permanent_address" name="permanent_address"
                                   value="<?php echo isset($_POST['permanent_address']) ? htmlspecialchars($_POST['permanent_address']) : ''; ?>"
                                   placeholder="Bldg/Lot/Street">
                            <div class="form-text">Same as Proof of Residency</div>
                        </div>
                    </div>
                    
                    <!-- Program Selection Section -->
                    <h6 class="text-primary mb-3 fw-bold mt-4">Program Selection</h6>
                    
                    <div class="mb-3">
                        <label for="preferred_program" class="form-label">Choose your preferred degree/program course *</label>
                        <select class="form-control" id="preferred_program" name="preferred_program" required>
                            <option value="">Select Program...</option>
                            <?php foreach ($active_programs as $program): ?>
                                <option value="<?php echo htmlspecialchars($program['program_name']); ?>">
                                    <?php echo htmlspecialchars($program['program_name']); ?> (<?php echo htmlspecialchars($program['program_code']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <!-- Section/Schedule Selection Section -->
                    <h6 class="text-primary mb-3 fw-bold mt-4">Section and Schedule Selection</h6>
                    
                    <div id="section_selection_container" style="display: none;">
                        <div class="mb-3">
                            <label for="selected_section" class="form-label">Choose your preferred section and schedule *</label>
                            <select class="form-control" id="selected_section" name="selected_section" required>
                                <option value="">Select Section...</option>
                            </select>
                            <div class="form-text">Please select a program first to see available sections</div>
                        </div>
                        
                        <div id="section_schedule_details" class="mb-3" style="display: none;">
                            <div class="card border-primary">
                                <div class="card-header bg-primary text-white">
                                    <i class="fas fa-calendar-alt me-2"></i>Schedule Details
                                </div>
                                <div class="card-body">
                                    <div id="schedule_content"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-register w-100 mb-3">
                        <i class="fas fa-user-plus me-2"></i>Register
                    </button>
                    
                    <div class="text-center">
                        <p class="mb-0">Already have an account? 
                            <a href="login.php" class="text-decoration-none fw-bold">Login here</a>
                        </p>
                    </div>
                </form>
                
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
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
            
            // Load sections when program is selected
            const preferredProgram = document.getElementById('preferred_program');
            const sectionSelectionContainer = document.getElementById('section_selection_container');
            const selectedSection = document.getElementById('selected_section');
            const sectionScheduleDetails = document.getElementById('section_schedule_details');
            const scheduleContent = document.getElementById('schedule_content');
            
            if (preferredProgram) {
                preferredProgram.addEventListener('change', function() {
                    const programName = this.value;
                    
                    if (!programName) {
                        sectionSelectionContainer.style.display = 'none';
                        selectedSection.innerHTML = '<option value="">Select Section...</option>';
                        sectionScheduleDetails.style.display = 'none';
                        return;
                    }
                    
                    // Show loading state
                    selectedSection.innerHTML = '<option value="">Loading sections...</option>';
                    selectedSection.disabled = true;
                    sectionSelectionContainer.style.display = 'block';
                    sectionScheduleDetails.style.display = 'none';
                    
                    // Fetch sections from API
                    fetch(`get_sections_for_registration.php?program_name=${encodeURIComponent(programName)}`)
                        .then(response => response.json())
                        .then(data => {
                            selectedSection.disabled = false;
                            
                            if (data.success && data.sections.length > 0) {
                                selectedSection.innerHTML = '<option value="">Select Section...</option>';
                                
                                data.sections.forEach(section => {
                                    const option = document.createElement('option');
                                    option.value = section.id;
                                    option.textContent = `${section.section_name} (${section.section_type}) - ${section.available_slots} slots available`;
                                    option.dataset.sectionData = JSON.stringify(section);
                                    selectedSection.appendChild(option);
                                });
                            } else {
                                selectedSection.innerHTML = '<option value="">No sections available</option>';
                            }
                        })
                        .catch(error => {
                            console.error('Error loading sections:', error);
                            selectedSection.disabled = false;
                            selectedSection.innerHTML = '<option value="">Error loading sections. Please try again.</option>';
                        });
                });
                
                // Show schedule details when section is selected
                if (selectedSection) {
                    selectedSection.addEventListener('change', function() {
                        const selectedOption = this.options[this.selectedIndex];
                        
                        if (this.value && selectedOption.dataset.sectionData) {
                            const sectionData = JSON.parse(selectedOption.dataset.sectionData);
                            
                            // Display schedule details
                            let scheduleHtml = `<h6 class="mb-3">${sectionData.section_name} - ${sectionData.section_type}</h6>`;
                            scheduleHtml += `<p class="text-muted mb-3">Academic Year: ${sectionData.academic_year || 'Not specified'}</p>`;
                            
                            if (sectionData.schedules && sectionData.schedules.length > 0) {
                                scheduleHtml += '<table class="table table-sm table-bordered">';
                                scheduleHtml += '<thead><tr><th>Course Code</th><th>Course Name</th><th>Units</th><th>Schedule</th><th>Room</th><th>Professor</th></tr></thead>';
                                scheduleHtml += '<tbody>';
                                
                                sectionData.schedules.forEach(schedule => {
                                    const days = [];
                                    if (schedule.schedule_monday) days.push('Mon');
                                    if (schedule.schedule_tuesday) days.push('Tue');
                                    if (schedule.schedule_wednesday) days.push('Wed');
                                    if (schedule.schedule_thursday) days.push('Thu');
                                    if (schedule.schedule_friday) days.push('Fri');
                                    if (schedule.schedule_saturday) days.push('Sat');
                                    if (schedule.schedule_sunday) days.push('Sun');
                                    
                                    let timeDisplay = '';
                                    if (schedule.time_start && schedule.time_end) {
                                        const startTime = new Date('2000-01-01 ' + schedule.time_start);
                                        const endTime = new Date('2000-01-01 ' + schedule.time_end);
                                        timeDisplay = startTime.toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit', hour12: true}) + 
                                                    ' - ' + 
                                                    endTime.toLocaleTimeString('en-US', {hour: 'numeric', minute: '2-digit', hour12: true});
                                    }
                                    
                                    scheduleHtml += '<tr>';
                                    scheduleHtml += `<td>${schedule.course_code || '-'}</td>`;
                                    scheduleHtml += `<td>${schedule.course_name || '-'}</td>`;
                                    scheduleHtml += `<td>${schedule.units || '-'}</td>`;
                                    scheduleHtml += `<td>${days.length > 0 ? days.join(', ') : 'TBA'} ${timeDisplay ? '- ' + timeDisplay : ''}</td>`;
                                    scheduleHtml += `<td>${schedule.room || 'TBA'}</td>`;
                                    scheduleHtml += `<td>${schedule.professor_name || schedule.professor_initial || 'TBA'}</td>`;
                                    scheduleHtml += '</tr>';
                                });
                                
                                scheduleHtml += '</tbody></table>';
                            } else {
                                scheduleHtml += '<p class="text-muted">Schedule details will be available after enrollment approval.</p>';
                            }
                            
                            scheduleContent.innerHTML = scheduleHtml;
                            sectionScheduleDetails.style.display = 'block';
                        } else {
                            sectionScheduleDetails.style.display = 'none';
                        }
                    });
                }
            }
        });
    </script>
</body>
</html>
