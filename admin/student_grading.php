<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';

if (!isLoggedIn() || !isAdmin()) {
    redirect('public/login.php');
}

$conn = (new Database())->getConnection();

// Get all enrolled students
$students_query = "SELECT DISTINCT u.id, u.student_id, u.first_name, u.last_name, u.email,
                   GROUP_CONCAT(DISTINCT p.program_code ORDER BY p.program_code SEPARATOR ', ') as programs
                   FROM users u
                   JOIN enrolled_students es ON u.id = es.user_id
                   LEFT JOIN section_enrollments se ON u.id = se.user_id AND se.status = 'active'
                   LEFT JOIN sections s ON se.section_id = s.id
                   LEFT JOIN programs p ON s.program_id = p.id
                   WHERE u.enrollment_status = 'enrolled'
                   GROUP BY u.id, u.student_id, u.first_name, u.last_name, u.email
                   ORDER BY u.last_name, u.first_name";
$students_stmt = $conn->prepare($students_query);
$students_stmt->execute();
$enrolled_students = $students_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get grade scale for dropdown
$grade_scale_query = "SELECT * FROM grade_scale ORDER BY grade_numeric ASC";
$grade_scale_stmt = $conn->prepare($grade_scale_query);
$grade_scale_stmt->execute();
$grade_scales = $grade_scale_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Grading System - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px 0;
        }
        .container-main {
            max-width: 1400px;
        }
        .card {
            border-radius: 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .grade-badge {
            font-size: 0.9rem;
            padding: 5px 10px;
        }
        .student-row:hover {
            background-color: #f8f9fa;
            cursor: pointer;
        }
        .verified-badge {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }
        .pending-badge {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
    </style>
</head>
<body>
    <div class="container container-main">
        <div class="row mb-4">
            <div class="col">
                <a href="dashboard.php" class="btn btn-light">
                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                </a>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-primary text-white">
                <h4 class="mb-0"><i class="fas fa-clipboard-list me-2"></i>Student Grading System</h4>
            </div>
            <div class="card-body">
                <?php if (isset($_SESSION['message'])): ?>
                    <div class="alert alert-info alert-dismissible fade show">
                        <?php 
                        echo htmlspecialchars($_SESSION['message']); 
                        unset($_SESSION['message']);
                        ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="row mb-4">
                    <div class="col-md-6">
                        <h5>Select Student to Enter Grades</h5>
                        <p class="text-muted">Click on a student to view and enter their grades</p>
                    </div>
                    <div class="col-md-6 text-end">
                        <div class="btn-group">
                            <button class="btn btn-outline-primary" onclick="filterStudents('all')">All Students</button>
                            <button class="btn btn-outline-warning" onclick="filterStudents('pending')">Pending Grades</button>
                            <button class="btn btn-outline-success" onclick="filterStudents('verified')">Verified Grades</button>
                        </div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-dark">
                            <tr>
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Program</th>
                                <th>Grades Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($enrolled_students as $student): ?>
                                <?php
                                // Get grade statistics for this student
                                $grade_stats_query = "SELECT 
                                    COUNT(*) as total_grades,
                                    SUM(CASE WHEN status = 'verified' OR status = 'finalized' THEN 1 ELSE 0 END) as verified_grades,
                                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_grades
                                    FROM student_grades WHERE user_id = :user_id";
                                $grade_stats_stmt = $conn->prepare($grade_stats_query);
                                $grade_stats_stmt->bindParam(':user_id', $student['id']);
                                $grade_stats_stmt->execute();
                                $grade_stats = $grade_stats_stmt->fetch(PDO::FETCH_ASSOC);
                                ?>
                                <tr class="student-row" data-status="<?php echo $grade_stats['pending_grades'] > 0 ? 'pending' : 'verified'; ?>">
                                    <td><strong><?php echo htmlspecialchars($student['student_id']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($student['programs'] ?: 'N/A'); ?></td>
                                    <td>
                                        <?php if ($grade_stats['total_grades'] == 0): ?>
                                            <span class="badge bg-secondary">No grades yet</span>
                                        <?php else: ?>
                                            <span class="badge bg-success"><?php echo $grade_stats['verified_grades']; ?> Verified</span>
                                            <?php if ($grade_stats['pending_grades'] > 0): ?>
                                                <span class="badge bg-warning"><?php echo $grade_stats['pending_grades']; ?> Pending</span>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-primary btn-sm" onclick="openGradeModal(<?php echo $student['id']; ?>, '<?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name']); ?>', '<?php echo htmlspecialchars($student['student_id']); ?>')">
                                            <i class="fas fa-edit me-1"></i>Enter/View Grades
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Grade Entry Modal -->
    <div class="modal fade" id="gradeModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-graduation-cap me-2"></i>
                        Student Grades: <span id="modal_student_name"></span> (<span id="modal_student_id"></span>)
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="current_user_id">
                    
                    <div class="row mb-3">
                        <div class="col-md-12">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle me-2"></i>
                                <strong>Instructions:</strong> Enter grades for each subject the student is enrolled in. Grades must be verified before the student can enroll for the next semester.
                            </div>
                        </div>
                    </div>

                    <div id="grades_content">
                        <div class="text-center py-5">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p class="mt-2">Loading student grades...</p>
                        </div>
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
        function openGradeModal(userId, fullName, studentId) {
            document.getElementById('current_user_id').value = userId;
            document.getElementById('modal_student_name').textContent = fullName;
            document.getElementById('modal_student_id').textContent = studentId;
            
            loadStudentGrades(userId);
            
            const modal = new bootstrap.Modal(document.getElementById('gradeModal'));
            modal.show();
        }

        function loadStudentGrades(userId) {
            fetch(`get_student_grades.php?user_id=${userId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayGrades(data.subjects, data.grades, userId);
                    } else {
                        document.getElementById('grades_content').innerHTML = 
                            '<div class="alert alert-warning">Error loading grades: ' + data.message + '</div>';
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('grades_content').innerHTML = 
                        '<div class="alert alert-danger">Error loading grades. Please try again.</div>';
                });
        }

        function displayGrades(subjects, grades, userId) {
            if (subjects.length === 0) {
                document.getElementById('grades_content').innerHTML = 
                    '<div class="alert alert-warning">This student is not enrolled in any subjects yet (no COR found).</div>';
                return;
            }

            // Group subjects by academic year and semester for better organization
            const groupedSubjects = {};
            subjects.forEach(subject => {
                const key = `${subject.academic_year}|${subject.semester}`;
                if (!groupedSubjects[key]) {
                    groupedSubjects[key] = {
                        academic_year: subject.academic_year,
                        semester: subject.semester,
                        subjects: []
                    };
                }
                groupedSubjects[key].subjects.push(subject);
            });

            // Sort groups by academic year (desc) and semester (desc)
            const sortedGroups = Object.values(groupedSubjects).sort((a, b) => {
                const yearCompare = b.academic_year.localeCompare(a.academic_year);
                if (yearCompare !== 0) return yearCompare;
                return b.semester.localeCompare(a.semester);
            });

            let html = '';
            
            sortedGroups.forEach((group, groupIndex) => {
                // Add section header for each academic year/semester
                html += `<div class="mb-4">`;
                html += `<h6 class="text-primary mb-3">`;
                html += `<i class="fas fa-calendar-alt me-2"></i>`;
                html += `${group.academic_year} - ${group.semester}`;
                html += `</h6>`;
                
                html += '<div class="table-responsive"><table class="table table-bordered table-hover">';
                html += '<thead class="table-light"><tr>';
                html += '<th>Subject Code</th><th>Subject Name</th>';
                html += '<th>Curriculum Info</th>';
                html += '<th>Grade</th><th>Status</th><th>Actions</th>';
                html += '</tr></thead><tbody>';

                // Sort subjects within group by course code
                group.subjects.sort((a, b) => a.subject_code.localeCompare(b.subject_code));

                group.subjects.forEach(subject => {
                    const grade = grades.find(g => g.subject_id == subject.subject_id);
                    const gradeValue = grade ? grade.grade : '';
                    const gradeLetter = grade ? grade.grade_letter : '';
                    const gradeStatus = grade ? grade.status : 'pending';
                    const gradeId = grade ? grade.id : null;

                    // Build curriculum label
                    let curriculumLabel = '';
                    if (subject.year_level) {
                        curriculumLabel += `<span class="badge bg-info me-1">${subject.year_level}</span>`;
                    }
                    if (subject.curriculum_semester) {
                        curriculumLabel += `<span class="badge bg-secondary me-1">${subject.curriculum_semester}</span>`;
                    }
                    if (subject.is_backload && subject.backload_year_level) {
                        curriculumLabel += `<span class="badge bg-warning text-dark me-1" title="Backload from ${subject.backload_year_level}">Backload</span>`;
                    }
                    if (subject.section_name) {
                        curriculumLabel += `<small class="text-muted d-block mt-1"><i class="fas fa-users me-1"></i>${subject.section_name}</small>`;
                    }

                    html += '<tr>';
                    html += `<td><strong>${subject.subject_code}</strong></td>`;
                    html += `<td>${subject.subject_name}</td>`;
                    html += `<td>${curriculumLabel || '<span class="text-muted">N/A</span>'}</td>`;
                    html += '<td>';
                    if (gradeStatus === 'verified' || gradeStatus === 'finalized') {
                        html += `<span class="badge bg-success">${gradeLetter} (${gradeValue})</span>`;
                    } else {
                        const inputId = `grade_${subject.subject_id || subject.subject_code}`;
                        html += `<input type="number" step="0.01" min="0" max="5" class="form-control form-control-sm" 
                                id="${inputId}" value="${gradeValue}" 
                                onblur="saveGrade(${userId}, ${subject.subject_id || 'null'}, '${subject.academic_year}', '${subject.semester}', this.value, ${gradeId || 'null'}, '${subject.subject_code}')"
                                onkeypress="if(event.key==='Enter') saveGrade(${userId}, ${subject.subject_id || 'null'}, '${subject.academic_year}', '${subject.semester}', this.value, ${gradeId || 'null'}, '${subject.subject_code}')"
                                placeholder="Enter grade (0.00-5.00)">`;
                    }
                    html += '</td>';
                    html += '<td>';
                    if (gradeStatus === 'verified') {
                        html += '<span class="badge verified-badge text-white">Verified</span>';
                    } else if (gradeStatus === 'finalized') {
                        html += '<span class="badge bg-success">Finalized</span>';
                    } else {
                        html += '<span class="badge pending-badge text-white">Pending</span>';
                    }
                    html += '</td>';
                    html += '<td>';
                    if (grade && (gradeStatus === 'pending' || gradeStatus === 'verified')) {
                        if (gradeStatus === 'pending') {
                            html += `<button class="btn btn-success btn-sm" onclick="verifyGrade(${gradeId}, ${userId})">
                                    <i class="fas fa-check"></i> Verify
                                    </button>`;
                        } else {
                            html += `<button class="btn btn-warning btn-sm" onclick="unverifyGrade(${gradeId}, ${userId})">
                                    <i class="fas fa-undo"></i> Unverify
                                    </button>`;
                        }
                    }
                    html += '</td>';
                    html += '</tr>';
                });

                html += '</tbody></table></div></div>';
            });

            document.getElementById('grades_content').innerHTML = html;
        }

        function saveGrade(userId, subjectId, academicYear, semester, grade, gradeId, courseCode) {
            if (!grade || grade < 0 || grade > 5) {
                alert('Please enter a valid grade between 0.00 and 5.00');
                return;
            }

            const formData = new FormData();
            formData.append('user_id', userId);
            if (subjectId && subjectId !== 'null') {
                formData.append('subject_id', subjectId);
            }
            if (courseCode) {
                formData.append('course_code', courseCode);
            }
            formData.append('academic_year', academicYear);
            formData.append('semester', semester);
            formData.append('grade', grade);
            if (gradeId && gradeId !== 'null') {
                formData.append('grade_id', gradeId);
            }

                    // Show loading indicator
            const inputId = subjectId && subjectId !== 'null' ? `grade_${subjectId}` : `grade_${courseCode || 'unknown'}`;
            const inputElement = document.getElementById(inputId);
            if (inputElement) {
                inputElement.disabled = true;
                inputElement.style.backgroundColor = '#f8f9fa';
            }

            fetch('save_student_grade.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success indicator instead of reloading
                    if (inputElement) {
                        inputElement.style.backgroundColor = '#d4edda';
                        inputElement.style.borderColor = '#c3e6cb';
                        inputElement.disabled = false;
                        
                        // Show success message briefly
                        const successMsg = document.createElement('small');
                        successMsg.className = 'text-success';
                        successMsg.textContent = ' ✓ Saved';
                        successMsg.style.marginLeft = '5px';
                        
                        // Remove any existing success message
                        const existingMsg = inputElement.parentNode.querySelector('.text-success');
                        if (existingMsg) {
                            existingMsg.remove();
                        }
                        
                        inputElement.parentNode.appendChild(successMsg);
                        
                        // Remove success message after 3 seconds
                        setTimeout(() => {
                            if (successMsg.parentNode) {
                                successMsg.remove();
                            }
                            inputElement.style.backgroundColor = '';
                            inputElement.style.borderColor = '';
                        }, 3000);
                    }
                } else {
                    alert('Error saving grade: ' + data.message);
                    if (inputElement) {
                        inputElement.disabled = false;
                        inputElement.style.backgroundColor = '';
                    }
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error saving grade. Please try again.');
                if (inputElement) {
                    inputElement.disabled = false;
                    inputElement.style.backgroundColor = '';
                }
            });
        }

        function verifyGrade(gradeId, userId) {
            if (!confirm('Verify this grade? Students will be able to see verified grades.')) {
                return;
            }

            const formData = new FormData();
            formData.append('grade_id', gradeId);
            formData.append('action', 'verify');

            fetch('verify_student_grade.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadStudentGrades(userId);
                } else {
                    alert('Error verifying grade: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error verifying grade. Please try again.');
            });
        }

        function unverifyGrade(gradeId, userId) {
            if (!confirm('Unverify this grade? This will allow you to edit it again.')) {
                return;
            }

            const formData = new FormData();
            formData.append('grade_id', gradeId);
            formData.append('action', 'unverify');

            fetch('verify_student_grade.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadStudentGrades(userId);
                } else {
                    alert('Error unverifying grade: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error unverifying grade. Please try again.');
            });
        }

        function filterStudents(filter) {
            const rows = document.querySelectorAll('.student-row');
            rows.forEach(row => {
                if (filter === 'all') {
                    row.style.display = '';
                } else {
                    const status = row.getAttribute('data-status');
                    row.style.display = status === filter ? '' : 'none';
                }
            });
        }
    </script>
</body>
</html>

