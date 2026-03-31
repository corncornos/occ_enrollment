<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../public/login.php");
    exit();
}

$admin_name = $_SESSION['first_name'] . ' ' . $_SESSION['last_name'];
$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);

// Get programs for dropdown
$db = new Database();
$conn = $db->getConnection();

$programs_query = "SELECT * FROM programs ORDER BY program_code";
$programs_stmt = $conn->prepare($programs_query);
$programs_stmt->execute();
$programs = $programs_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Import Students - OCC Enrollment System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            min-height: 100vh;
        }
        .main-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            margin: 30px auto;
            max-width: 1200px;
            padding: 40px;
        }
        .upload-zone {
            border: 3px dashed #667eea;
            border-radius: 10px;
            padding: 60px 20px;
            text-align: center;
            background: #f8f9ff;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .upload-zone:hover {
            background: #e8ebff;
            border-color: #764ba2;
        }
        .upload-zone.dragover {
            background: #e8ebff;
            border-color: #764ba2;
            transform: scale(1.02);
        }
        .upload-icon {
            font-size: 4rem;
            color: #667eea;
            margin-bottom: 20px;
        }
        .template-card {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 30px;
        }
        .step-badge {
            background: white;
            color: #667eea;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 10px;
        }
        .preview-table {
            max-height: 400px;
            overflow-y: auto;
        }
        .error-row {
            background-color: #fff5f5;
        }
        .success-row {
            background-color: #f0fdf4;
        }
        .back-btn {
            position: absolute;
            top: 20px;
            left: 20px;
        }
    </style>
</head>
<body>
    <a href="dashboard.php" class="btn btn-light back-btn">
        <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
    </a>

    <div class="main-container">
        <div class="text-center mb-5">
            <h1 class="display-4 mb-3">
                <i class="fas fa-file-import text-primary me-3"></i>
                Bulk Student Import
            </h1>
            <p class="lead text-muted">Migrate existing students from manual system to automated enrollment</p>
        </div>

        <?php if ($message): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <?php echo $message; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Template Download Section -->
        <div class="template-card">
            <h4 class="mb-3">
                <i class="fas fa-download me-2"></i>Step 1: Download Template
            </h4>
            <p class="mb-3">Download the CSV template and fill in your student data following the format.</p>
            <button onclick="downloadTemplate()" class="btn btn-light btn-lg">
                <i class="fas fa-file-csv me-2"></i>Download CSV Template
            </button>
            <button onclick="showInstructions()" class="btn btn-outline-light btn-lg ms-2">
                <i class="fas fa-question-circle me-2"></i>Import Instructions
            </button>
        </div>

        <!-- Upload Section -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    <span class="step-badge">2</span>
                    Upload Completed CSV File
                </h5>
            </div>
            <div class="card-body">
                <form id="uploadForm" enctype="multipart/form-data">
                    <div class="upload-zone" id="uploadZone" onclick="document.getElementById('csvFile').click()">
                        <i class="fas fa-cloud-upload-alt upload-icon"></i>
                        <h4>Drag & Drop CSV File Here</h4>
                        <p class="text-muted">or click to browse</p>
                        <input type="file" id="csvFile" name="csvFile" accept=".csv" style="display: none;" onchange="handleFileSelect(event)">
                    </div>
                    
                    <div id="fileInfo" class="mt-3" style="display: none;">
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i>
                            <strong>File selected:</strong> <span id="fileName"></span>
                            <button type="button" class="btn-close float-end" onclick="clearFile()"></button>
                        </div>
                    </div>

                    <div class="mt-4 text-center">
                        <button type="button" class="btn btn-primary btn-lg" onclick="validateImport()" id="validateBtn" disabled>
                            <i class="fas fa-check-double me-2"></i>Validate Data
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Preview Section -->
        <div id="previewSection" class="card" style="display: none;">
            <div class="card-header bg-info text-white">
                <h5 class="mb-0">
                    <span class="step-badge">3</span>
                    Review & Import
                </h5>
            </div>
            <div class="card-body">
                <div id="validationSummary" class="mb-3"></div>
                
                <div class="preview-table">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>#</th>
                                <th>Student ID</th>
                                <th>First Name</th>
                                <th>Last Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Program</th>
                                <th>Year Level</th>
                                <th>Student Type</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody id="previewTableBody">
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 text-center">
                    <button type="button" class="btn btn-success btn-lg" onclick="processImport()" id="importBtn">
                        <i class="fas fa-upload me-2"></i>Import Students
                    </button>
                    <button type="button" class="btn btn-secondary btn-lg ms-2" onclick="cancelImport()">
                        <i class="fas fa-times me-2"></i>Cancel
                    </button>
                </div>
            </div>
        </div>

        <!-- Results Section -->
        <div id="resultsSection" class="card" style="display: none;">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0">
                    <i class="fas fa-check-circle me-2"></i>Import Complete
                </h5>
            </div>
            <div class="card-body">
                <div id="resultsContent"></div>
                <div class="mt-4 text-center">
                    <a href="dashboard.php" class="btn btn-primary btn-lg">
                        <i class="fas fa-home me-2"></i>Return to Dashboard
                    </a>
                    <button type="button" class="btn btn-secondary btn-lg ms-2" onclick="location.reload()">
                        <i class="fas fa-redo me-2"></i>Import More Students
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Instructions Modal -->
    <div class="modal fade" id="instructionsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-info-circle me-2"></i>Import Instructions
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <h6 class="text-primary">CSV File Format:</h6>
                    <p>Your CSV file must contain the following columns in this exact order:</p>
                    
                    <ol>
                        <li><strong>student_id</strong> - Unique student ID number (required)</li>
                        <li><strong>first_name</strong> - Student's first name (required)</li>
                        <li><strong>last_name</strong> - Student's last name (required)</li>
                        <li><strong>email</strong> - Valid email address (required, must be unique)</li>
                        <li><strong>phone</strong> - Contact phone number (optional)</li>
                        <li><strong>program</strong> - Program code: BSE, BTVTED, or BSIS (required)</li>
                        <li><strong>year_level</strong> - 1st Year, 2nd Year, 3rd Year, 4th Year, or 5th Year (required)</li>
                        <li><strong>student_type</strong> - Regular, Irregular, or Transferee (required)</li>
                        <li><strong>academic_year</strong> - e.g., 2023-2024 (required)</li>
                        <li><strong>semester</strong> - First Semester, Second Semester, or Summer (required)</li>
                    </ol>

                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>Important Notes:</strong>
                        <ul class="mb-0">
                            <li>All students will be imported with enrollment status "enrolled"</li>
                            <li>A default password will be set (password123) - students should change it on first login</li>
                            <li>Email addresses must be unique and valid</li>
                            <li>Student IDs must be unique</li>
                            <li>The system will validate all data before importing</li>
                        </ul>
                    </div>

                    <h6 class="text-primary mt-4">Program Codes:</h6>
                    <div class="row">
                        <?php foreach ($programs as $program): ?>
                        <div class="col-md-6 mb-2">
                            <span class="badge bg-info"><?php echo htmlspecialchars($program['program_code']); ?></span>
                            - <?php echo htmlspecialchars($program['program_name']); ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let selectedFile = null;
        let validatedData = null;

        // Drag and drop handlers
        const uploadZone = document.getElementById('uploadZone');
        
        uploadZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadZone.classList.add('dragover');
        });

        uploadZone.addEventListener('dragleave', () => {
            uploadZone.classList.remove('dragover');
        });

        uploadZone.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadZone.classList.remove('dragover');
            
            const files = e.dataTransfer.files;
            if (files.length > 0 && files[0].name.endsWith('.csv')) {
                document.getElementById('csvFile').files = files;
                handleFileSelect({ target: { files: files } });
            } else {
                alert('Please upload a CSV file');
            }
        });

        function handleFileSelect(event) {
            const file = event.target.files[0];
            if (file) {
                selectedFile = file;
                document.getElementById('fileName').textContent = file.name;
                document.getElementById('fileInfo').style.display = 'block';
                document.getElementById('validateBtn').disabled = false;
            }
        }

        function clearFile() {
            selectedFile = null;
            document.getElementById('csvFile').value = '';
            document.getElementById('fileInfo').style.display = 'none';
            document.getElementById('validateBtn').disabled = true;
            document.getElementById('previewSection').style.display = 'none';
        }

        function showInstructions() {
            const modal = new bootstrap.Modal(document.getElementById('instructionsModal'));
            modal.show();
        }

        function downloadTemplate() {
            const csvContent = 'student_id,first_name,last_name,email,phone,program,year_level,student_type,academic_year,semester\n' +
                '2021001,Juan,Dela Cruz,juan.delacruz@example.com,09123456789,BSE,1st Year,Regular,2023-2024,First Semester\n' +
                '2021002,Maria,Santos,maria.santos@example.com,09187654321,BTVTED,2nd Year,Regular,2023-2024,First Semester\n' +
                '2021003,Pedro,Reyes,pedro.reyes@example.com,09161234567,BSIS,3rd Year,Transferee,2023-2024,Second Semester';
            
            const blob = new Blob([csvContent], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'student_import_template.csv';
            a.click();
            window.URL.revokeObjectURL(url);
        }

        function validateImport() {
            if (!selectedFile) {
                alert('Please select a file first');
                return;
            }

            const formData = new FormData();
            formData.append('csvFile', selectedFile);
            formData.append('action', 'validate');

            document.getElementById('validateBtn').innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Validating...';
            document.getElementById('validateBtn').disabled = true;

            fetch('process_bulk_import.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    validatedData = data.data;
                    displayPreview(data);
                } else {
                    alert('Validation failed: ' + data.message);
                }
                document.getElementById('validateBtn').innerHTML = '<i class="fas fa-check-double me-2"></i>Validate Data';
                document.getElementById('validateBtn').disabled = false;
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred during validation');
                document.getElementById('validateBtn').innerHTML = '<i class="fas fa-check-double me-2"></i>Validate Data';
                document.getElementById('validateBtn').disabled = false;
            });
        }

        function displayPreview(data) {
            const validCount = data.valid_count;
            const errorCount = data.error_count;
            const totalCount = data.total_count;

            // Display summary
            let summaryHtml = '<div class="row text-center">';
            summaryHtml += `<div class="col-md-4"><div class="alert alert-info"><strong>${totalCount}</strong> Total Records</div></div>`;
            summaryHtml += `<div class="col-md-4"><div class="alert alert-success"><strong>${validCount}</strong> Valid Records</div></div>`;
            summaryHtml += `<div class="col-md-4"><div class="alert alert-danger"><strong>${errorCount}</strong> Errors Found</div></div>`;
            summaryHtml += '</div>';

            if (errorCount > 0) {
                summaryHtml += '<div class="alert alert-warning"><i class="fas fa-exclamation-triangle me-2"></i>Please review errors below. Only valid records will be imported.</div>';
            }

            document.getElementById('validationSummary').innerHTML = summaryHtml;

            // Display preview table
            const tbody = document.getElementById('previewTableBody');
            tbody.innerHTML = '';

            data.data.forEach((row, index) => {
                const tr = document.createElement('tr');
                tr.className = row.errors ? 'error-row' : 'success-row';
                
                tr.innerHTML = `
                    <td>${index + 1}</td>
                    <td>${escapeHtml(row.student_id || '')}</td>
                    <td>${escapeHtml(row.first_name || '')}</td>
                    <td>${escapeHtml(row.last_name || '')}</td>
                    <td>${escapeHtml(row.email || '')}</td>
                    <td>${escapeHtml(row.phone || '')}</td>
                    <td>${escapeHtml(row.program || '')}</td>
                    <td>${escapeHtml(row.year_level || '')}</td>
                    <td>${escapeHtml(row.student_type || '')}</td>
                    <td>
                        ${row.errors ? 
                            '<span class="badge bg-danger" title="' + escapeHtml(row.errors.join(', ')) + '">Error</span>' : 
                            '<span class="badge bg-success">Valid</span>'}
                    </td>
                `;
                tbody.appendChild(tr);
            });

            document.getElementById('previewSection').style.display = 'block';
            document.getElementById('previewSection').scrollIntoView({ behavior: 'smooth' });
        }

        function processImport() {
            if (!validatedData) {
                alert('Please validate data first');
                return;
            }

            if (!confirm('Are you sure you want to import these students? This action cannot be undone.')) {
                return;
            }

            const formData = new FormData();
            formData.append('action', 'import');
            formData.append('data', JSON.stringify(validatedData));

            document.getElementById('importBtn').innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Importing...';
            document.getElementById('importBtn').disabled = true;

            fetch('process_bulk_import.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    displayResults(data);
                } else {
                    alert('Import failed: ' + data.message);
                    document.getElementById('importBtn').innerHTML = '<i class="fas fa-upload me-2"></i>Import Students';
                    document.getElementById('importBtn').disabled = false;
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('An error occurred during import');
                document.getElementById('importBtn').innerHTML = '<i class="fas fa-upload me-2"></i>Import Students';
                document.getElementById('importBtn').disabled = false;
            });
        }

        function displayResults(data) {
            let resultsHtml = '<div class="row text-center mb-4">';
            resultsHtml += `<div class="col-md-6"><div class="alert alert-success"><h3>${data.imported_count}</h3>Students Imported Successfully</div></div>`;
            resultsHtml += `<div class="col-md-6"><div class="alert alert-info"><h3>${data.enrolled_count}</h3>Enrollment Records Created</div></div>`;
            resultsHtml += '</div>';

            if (data.skipped_count > 0) {
                resultsHtml += `<div class="alert alert-warning">
                    <i class="fas fa-info-circle me-2"></i>
                    ${data.skipped_count} records were skipped due to validation errors
                </div>`;
            }

            resultsHtml += '<div class="alert alert-info">';
            resultsHtml += '<h6><i class="fas fa-info-circle me-2"></i>Account Claiming Process:</h6>';
            resultsHtml += '<p><strong>Important:</strong> Students have been imported but do not have user accounts yet.</p>';
            resultsHtml += '<p class="mb-2">Students must <strong>claim their accounts</strong> by:</p>';
            resultsHtml += '<ol class="mb-2">';
            resultsHtml += '<li>Visiting the <strong>"Claim Account"</strong> page from the login screen</li>';
            resultsHtml += '<li>Entering their Student ID, Name, and Email to verify identity</li>';
            resultsHtml += '<li>Creating their own secure password</li>';
            resultsHtml += '</ol>';
            resultsHtml += '<p class="mb-0"><small><i class="fas fa-shield-alt me-2"></i>This ensures only legitimate students can access their accounts.</small></p>';
            resultsHtml += '</div>';

            document.getElementById('resultsContent').innerHTML = resultsHtml;
            document.getElementById('previewSection').style.display = 'none';
            document.getElementById('resultsSection').style.display = 'block';
            document.getElementById('resultsSection').scrollIntoView({ behavior: 'smooth' });
        }

        function cancelImport() {
            if (confirm('Are you sure you want to cancel? All validated data will be lost.')) {
                location.reload();
            }
        }

        function escapeHtml(text) {
            const map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return text ? text.toString().replace(/[&<>"']/g, m => map[m]) : '';
        }
    </script>
</body>
</html>

