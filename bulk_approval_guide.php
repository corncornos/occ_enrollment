<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Curriculum Approval Guide</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h2 class="mb-0"><i class="fas fa-check-circle me-2"></i>Bulk Curriculum Approval Feature</h2>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-success">
                            <h5><i class="fas fa-check-circle me-2"></i>Feature Successfully Implemented!</h5>
                            <p>The bulk approval feature for curriculum submissions has been added to the Registrar dashboard.</p>
                        </div>

                        <h4>How to Use Bulk Approval:</h4>

                        <div class="row">
                            <div class="col-md-6">
                                <h5><i class="fas fa-user-graduate me-2 text-primary"></i>Step 1: Program Head Actions</h5>
                                <ol>
                                    <li>Login as Program Head (e.g., <code>bse.head@occ.edu</code>)</li>
                                    <li>Go to "Bulk Upload" section</li>
                                    <li>Upload CSV file with curriculum subjects</li>
                                    <li>Click "Submit to Registrar"</li>
                                </ol>
                            </div>

                            <div class="col-md-6">
                                <h5><i class="fas fa-user-tie me-2 text-success"></i>Step 2: Registrar Actions</h5>
                                <ol>
                                    <li>Login as Admin/Registrar (<code>admin@occ.edu</code>)</li>
                                    <li>Go to "Curriculum Submissions" tab</li>
                                    <li>Check boxes next to submissions to approve</li>
                                    <li>Click "Bulk Approve Selected" button</li>
                                    <li>Confirm the approval action</li>
                                </ol>
                            </div>
                        </div>

                        <hr>

                        <h4><i class="fas fa-table me-2"></i>Table Features:</h4>
                        <ul>
                            <li><strong>Select All checkbox</strong> - Check/uncheck all submitted curricula</li>
                            <li><strong>Individual checkboxes</strong> - Only shown for "Submitted" status items</li>
                            <li><strong>Bulk Actions</strong> - Appears when items are selected</li>
                            <li><strong>Processing indicator</strong> - Shows progress during bulk approval</li>
                        </ul>

                        <h4><i class="fas fa-info-circle me-2"></i>What Happens During Bulk Approval:</h4>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <h6><i class="fas fa-plus-circle text-success me-1"></i>New Subjects Added</h6>
                                        <p>Each submission's subjects are added to the curriculum table with the correct program_id</p>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <h6><i class="fas fa-ban text-warning me-1"></i>Duplicates Skipped</h6>
                                        <p>Existing courses for each program are not duplicated</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <hr>

                        <h4><i class="fas fa-play-circle me-2"></i>Test the Feature:</h4>
                        <div class="list-group">
                            <a href="program_head/dashboard.php" class="list-group-item list-group-item-action" target="_blank">
                                <i class="fas fa-external-link-alt me-2"></i>Go to Program Head Dashboard
                            </a>
                            <a href="admin/dashboard.php" class="list-group-item list-group-item-action" target="_blank">
                                <i class="fas fa-external-link-alt me-2"></i>Go to Admin Dashboard (Curriculum Submissions Tab)
                            </a>
                            <a href="test_curriculum_approval.php" class="list-group-item list-group-item-action" target="_blank">
                                <i class="fas fa-external-link-alt me-2"></i>View Test Results
                            </a>
                        </div>

                        <div class="alert alert-info mt-4">
                            <h6><i class="fas fa-lightbulb me-1"></i>Pro Tips:</h6>
                            <ul class="mb-0">
                                <li>Use the "Select All" checkbox to quickly select all submitted curricula</li>
                                <li>The bulk approval processes submissions sequentially to avoid conflicts</li>
                                <li>Check the results page to see how many subjects were added per program</li>
                                <li>Each program's curriculum remains separate and organized</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
