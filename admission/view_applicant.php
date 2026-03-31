<?php
require_once '../config/database.php';
require_once '../classes/Admission.php';

if (!isLoggedIn() || !isAdmission()) {
    header('Location: ../login.php');
    exit();
}

$user_id = $_GET['user_id'] ?? null;

if (!$user_id) {
    header('Location: dashboard.php');
    exit();
}

$admission = new Admission();
$applicant = $admission->getApplicantById($user_id);

if (!$applicant) {
    header('Location: dashboard.php');
    exit();
}

// Get uploaded documents
$conn = (new Database())->getConnection();
$docs_query = "SELECT * FROM document_uploads WHERE user_id = :user_id ORDER BY upload_date DESC";
$docs_stmt = $conn->prepare($docs_query);
$docs_stmt->bindParam(':user_id', $user_id);
$docs_stmt->execute();
$documents = $docs_stmt->fetchAll(PDO::FETCH_ASSOC);

// Required documents - match document types used in student upload
$required_docs = [
    'id_pictures' => '2x2 ID Pictures (4 pcs)',
    'psa_birth_certificate' => 'Birth Certificate (PSA)',
    'barangay_certificate' => 'Barangay Certificate of Residency',
    'voters_id' => 'Voter\'s ID or Registration Stub',
    'high_school_diploma' => 'High School Diploma',
    'sf10_form' => 'SF10 (Senior High School Permanent Record)',
    'form_138' => 'Form 138 (Report Card)',
    'good_moral' => 'Certificate of Good Moral Character',
    'transfer_credentials' => 'Transfer Credentials (if applicable)'
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Applicant - Admission Office</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .info-label {
            font-weight: 600;
            color: #6c757d;
            margin-bottom: 0.25rem;
        }
        .info-value {
            margin-bottom: 1rem;
        }
        .document-card {
            border: 1px solid #dee2e6;
            border-radius: 0.5rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .status-badge {
            font-size: 0.875rem;
        }
        .form-text {
            font-size: 0.875rem;
            color: #6c757d;
        }
    </style>
</head>
<body>
    <div class="container-fluid py-4">
        <div class="row mb-4">
            <div class="col">
                <a href="dashboard.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-2"></i> Back to Dashboard
                </a>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-8">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-user me-2"></i> Applicant Information</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4">
                                <div class="info-label">Student Number</div>
                                <div class="info-value">
                                    <?php if ($applicant['student_id']): ?>
                                        <span class="badge bg-success"><?php echo htmlspecialchars($applicant['student_id']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">Not assigned</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-label">Status</div>
                                <div class="info-value">
                                    <?php
                                    $status_colors = [
                                        'pending' => 'warning',
                                        'pending_review' => 'info',
                                        'verified' => 'success',
                                        'approved' => 'success',
                                        'rejected' => 'danger'
                                    ];
                                    $status = $applicant['admission_status'] ?? 'pending';
                                    $color = $status_colors[$status] ?? 'secondary';
                                    ?>
                                    <span class="badge bg-<?php echo $color; ?>"><?php echo ucfirst(str_replace('_', ' ', $status)); ?></span>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-label">Registration Date</div>
                                <div class="info-value"><?php echo date('M d, Y', strtotime($applicant['created_at'])); ?></div>
                            </div>
                        </div>

                        <hr>

                        <h6 class="text-primary mb-3">Personal Information</h6>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="info-label">First Name</div>
                                <div class="info-value"><?php echo htmlspecialchars($applicant['first_name']); ?></div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-label">Middle Name</div>
                                <div class="info-value"><?php echo htmlspecialchars($applicant['middle_name'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-label">Last Name</div>
                                <div class="info-value"><?php echo htmlspecialchars($applicant['last_name']); ?></div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="info-label">Sex at Birth</div>
                                <div class="info-value"><?php echo htmlspecialchars($applicant['sex_at_birth']); ?></div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-label">Date of Birth</div>
                                <div class="info-value"><?php echo date('M d, Y', strtotime($applicant['date_of_birth'])); ?></div>
                            </div>
                            <div class="col-md-4">
                                <div class="info-label">Age</div>
                                <div class="info-value"><?php echo $applicant['age']; ?> years old</div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="info-label">Civil Status</div>
                                <div class="info-value"><?php echo htmlspecialchars($applicant['civil_status']); ?></div>
                            </div>
                            <div class="col-md-8">
                                <div class="info-label">Address</div>
                                <div class="info-value"><?php echo htmlspecialchars($applicant['address']); ?></div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <div class="info-label">Contact Number</div>
                                <div class="info-value"><?php echo htmlspecialchars($applicant['phone'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="col-md-8">
                                <div class="info-label">Email</div>
                                <div class="info-value"><?php echo htmlspecialchars($applicant['email']); ?></div>
                            </div>
                        </div>

                        <hr>

                        <h6 class="text-primary mb-3">Educational Background</h6>
                        <div class="row">
                            <div class="col-md-12">
                                <div class="info-label">Last School Attended</div>
                                <div class="info-value"><?php echo htmlspecialchars($applicant['last_attended'] ?? 'N/A'); ?></div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-label">Program</div>
                                <div class="info-value"><?php echo htmlspecialchars($applicant['preferred_program'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-label">Learner's Reference Number (LRN)</div>
                                <div class="info-value"><?php echo htmlspecialchars($applicant['number_lrn'] ?? 'N/A'); ?></div>
                            </div>
                        </div>

                        <hr>

                        <h6 class="text-primary mb-3">Family Information</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-label">Father's Name</div>
                                <div class="info-value"><?php echo htmlspecialchars($applicant['father_name'] ?? 'N/A'); ?></div>
                                <div class="info-label">Occupation</div>
                                <div class="info-value"><?php echo htmlspecialchars($applicant['father_occupation'] ?? 'N/A'); ?></div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-label">Mother's Maiden Name</div>
                                <div class="info-value"><?php echo htmlspecialchars($applicant['mother_maiden_name'] ?? 'N/A'); ?></div>
                                <div class="info-label">Occupation</div>
                                <div class="info-value"><?php echo htmlspecialchars($applicant['mother_occupation'] ?? 'N/A'); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card shadow-sm mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="fas fa-file-alt me-2"></i> Documents</h5>
                    </div>
                    <div class="card-body">
                        <?php foreach ($required_docs as $doc_type => $doc_name): ?>
                            <?php
                            // Get the latest document of this type (most recent upload_date)
                            $doc = null;
                            $latest_date = null;
                            foreach ($documents as $d) {
                                if ($d['document_type'] === $doc_type) {
                                    $doc_date = strtotime($d['upload_date']);
                                    if ($doc === null || $doc_date > $latest_date) {
                                        $doc = $d;
                                        $latest_date = $doc_date;
                                    }
                                }
                            }
                            ?>
                            <div class="document-card">
                                <div class="d-flex justify-content-between align-items-start mb-2">
                                    <div>
                                        <strong><?php echo $doc_name; ?></strong>
                                    </div>
                                    <?php if ($doc): ?>
                                        <?php
                                        $status_colors = [
                                            'pending' => 'warning',
                                            'verified' => 'success',
                                            'rejected' => 'danger'
                                        ];
                                        $color = $status_colors[$doc['verification_status']] ?? 'secondary';
                                        ?>
                                        <span class="badge bg-<?php echo $color; ?> status-badge">
                                            <?php echo ucfirst($doc['verification_status']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary status-badge">Not uploaded</span>
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($doc): ?>
                                    <div class="mb-2">
                                        <small class="text-muted">
                                            Uploaded: <?php echo date('M d, Y g:i A', strtotime($doc['upload_date'])); ?>
                                        </small>
                                    </div>
                                    
                                    <?php if ($doc['rejection_reason']): ?>
                                        <div class="alert alert-danger alert-sm mb-2" style="padding: 0.5rem; font-size: 0.875rem;">
                                            <strong>Rejected:</strong> <?php echo htmlspecialchars($doc['rejection_reason']); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="btn-group btn-group-sm" role="group">
                                        <button class="btn btn-outline-primary" onclick="viewDocument('<?php echo htmlspecialchars($doc['file_path']); ?>')">
                                            <i class="fas fa-eye"></i> View
                                        </button>
                                        <?php if ($doc['verification_status'] === 'pending'): ?>
                                            <button class="btn btn-outline-success" onclick="verifyDocument(<?php echo $doc['id']; ?>)">
                                                <i class="fas fa-check"></i> Verify
                                            </button>
                                            <button class="btn btn-outline-danger" onclick="rejectDocument(<?php echo $doc['id']; ?>)">
                                                <i class="fas fa-times"></i> Reject
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <small class="text-muted">Student has not uploaded this document yet.</small>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="card shadow-sm">
                    <div class="card-header bg-secondary text-white">
                        <h5 class="mb-0"><i class="fas fa-tasks me-2"></i> Actions</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!$applicant['student_number_assigned']): ?>
                            <button class="btn btn-success w-100 mb-2" onclick="assignNumber(<?php echo $applicant['user_id']; ?>)">
                                <i class="fas fa-id-card me-2"></i> Assign Student Number
                            </button>
                        <?php else: ?>
                            <div class="alert alert-success mb-2">
                                <i class="fas fa-check-circle me-2"></i> Student number assigned
                            </div>
                        <?php endif; ?>

                        <?php if ($applicant['student_number_assigned'] && !$applicant['passed_to_registrar']): ?>
                            <button class="btn btn-info w-100 mb-2" onclick="passToRegistrar(<?php echo $applicant['user_id']; ?>)">
                                <i class="fas fa-arrow-right me-2"></i> Pass to Registrar
                            </button>
                        <?php elseif ($applicant['passed_to_registrar']): ?>
                            <div class="alert alert-info mb-2">
                                <i class="fas fa-check-circle me-2"></i> Passed to Registrar
                            </div>
                        <?php endif; ?>

                        <button class="btn btn-danger w-100" onclick="deleteApplicant(<?php echo $applicant['user_id']; ?>)">
                            <i class="fas fa-trash me-2"></i> Delete Application
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function viewDocument(filePath) {
            window.open('../' + filePath, '_blank');
        }

        function verifyDocument(documentId) {
            if (!confirm('Mark this document as verified?')) return;
            
            const formData = new FormData();
            formData.append('document_id', documentId);
            
            fetch('verify_document.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                alert(data.message);
                if (data.success) {
                    location.reload();
                }
            });
        }

        function rejectDocument(documentId) {
            const reason = prompt('Enter rejection reason:');
            if (!reason) return;
            
            const formData = new FormData();
            formData.append('document_id', documentId);
            formData.append('rejection_reason', reason);
            
            fetch('verify_document.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                alert(data.message);
                if (data.success) {
                    location.reload();
                }
            });
        }

        function assignNumber(userId) {
            if (!confirm('Assign student number to this applicant? The number will be generated automatically.')) {
                return;
            }
            
            const formData = new FormData();
            formData.append('user_id', userId);
            
            fetch('assign_student_number.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    alert('Student number assigned: ' + data.student_number);
                    location.reload();
                } else {
                    alert(data.message || 'Failed to assign student number');
                }
            })
            .catch(error => {
                alert('An error occurred while assigning student number');
                console.error('Error:', error);
            });
        }

        function passToRegistrar(userId) {
            if (!confirm('Pass this application to the Registrar?')) return;
            
            const formData = new FormData();
            formData.append('user_id', userId);
            
            fetch('pass_to_registrar.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                alert(data.message);
                if (data.success) {
                    location.reload();
                }
            });
        }

        function deleteApplicant(userId) {
            if (!confirm('Are you sure you want to delete this application? This action cannot be undone.')) return;
            
            const formData = new FormData();
            formData.append('user_id', userId);
            
            fetch('delete_applicant.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                alert(data.message);
                if (data.success) {
                    window.location.href = 'dashboard.php';
                }
            });
        }
    </script>
</body>
</html>

