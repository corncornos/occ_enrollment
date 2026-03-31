<?php
declare(strict_types=1);

require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/AuditLog.php';
require_once '../classes/Admin.php';

// Check if user is logged in and is an admin
if (!isLoggedIn() || !isAdmin()) {
    redirect('../public/login.php');
}

$auditLog = new AuditLog();
$admin = new Admin();

// Get filter parameters
$filter_admin_id = $_GET['admin_id'] ?? null;
$filter_action_type = $_GET['action_type'] ?? '';
$filter_entity_type = $_GET['entity_type'] ?? '';
$filter_entity_id = $_GET['entity_id'] ?? null;
$filter_date_from = $_GET['date_from'] ?? null;
$filter_date_to = $_GET['date_to'] ?? null;
$filter_status = $_GET['status'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

// Build filters
$filters = [
    'admin_id' => $filter_admin_id ? (int)$filter_admin_id : null,
    'action_type' => $filter_action_type ?: null,
    'entity_type' => $filter_entity_type ?: null,
    'entity_id' => $filter_entity_id ? (int)$filter_entity_id : null,
    'date_from' => $filter_date_from,
    'date_to' => $filter_date_to,
    'status' => $filter_status ?: null,
    'limit' => $limit,
    'offset' => $offset
];

// Get audit logs
$logs = $auditLog->getLogs($filters);
$total_logs = $auditLog->getLogCount($filters);
$total_pages = ceil($total_logs / $limit);

// Get filter options
$action_types = $auditLog->getActionTypes();
$entity_types = $auditLog->getEntityTypes();
$all_admins = $admin->getAllAdmins();

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
    <title>Audit Logs - Admin Panel</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .page-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }
        .filter-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .log-entry {
            background: white;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            border-left: 4px solid #3b82f6;
        }
        .log-entry.failed {
            border-left-color: #ef4444;
        }
        .log-entry.partial {
            border-left-color: #f59e0b;
        }
        .log-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }
        .log-action {
            font-weight: 600;
            color: #1e293b;
        }
        .log-meta {
            font-size: 0.875rem;
            color: #64748b;
        }
        .log-description {
            margin: 0.5rem 0;
            color: #475569;
        }
        .badge-custom {
            padding: 0.25rem 0.75rem;
            border-radius: 6px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .json-values {
            background: #f1f5f9;
            padding: 0.5rem;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 0.875rem;
            max-height: 200px;
            overflow-y: auto;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-light bg-light mb-3">
        <div class="container">
            <a href="dashboard.php" class="btn btn-outline-primary">
                <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
            </a>
        </div>
    </nav>
    
    <div class="page-header">
        <div class="container">
            <h1><i class="fas fa-clipboard-list me-2"></i>Audit Logs</h1>
            <p class="mb-0">Track all administrative actions and changes</p>
        </div>
    </div>

    <div class="container">
        <?php if ($message): ?>
            <div class="alert alert-info alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Filters -->
        <div class="filter-card">
            <h5 class="mb-3"><i class="fas fa-filter me-2"></i>Filters</h5>
            <form method="GET" action="audit_logs.php">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Admin</label>
                        <select class="form-select" name="admin_id">
                            <option value="">All Admins</option>
                            <?php foreach ($all_admins as $adm): ?>
                                <option value="<?php echo $adm['id']; ?>" <?php echo $filter_admin_id == $adm['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($adm['first_name'] . ' ' . $adm['last_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Action Type</label>
                        <select class="form-select" name="action_type">
                            <option value="">All Actions</option>
                            <?php foreach ($action_types as $type): ?>
                                <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $filter_action_type == $type ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(str_replace('_', ' ', ucwords($type, '_'))); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Entity Type</label>
                        <select class="form-select" name="entity_type">
                            <option value="">All Entities</option>
                            <?php foreach ($entity_types as $type): ?>
                                <option value="<?php echo htmlspecialchars($type); ?>" <?php echo $filter_entity_type == $type ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(ucfirst($type)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <option value="">All Statuses</option>
                            <option value="success" <?php echo $filter_status == 'success' ? 'selected' : ''; ?>>Success</option>
                            <option value="failed" <?php echo $filter_status == 'failed' ? 'selected' : ''; ?>>Failed</option>
                            <option value="partial" <?php echo $filter_status == 'partial' ? 'selected' : ''; ?>>Partial</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date From</label>
                        <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($filter_date_from ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date To</label>
                        <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($filter_date_to ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Entity ID</label>
                        <input type="number" class="form-control" name="entity_id" value="<?php echo htmlspecialchars($filter_entity_id ?? ''); ?>" placeholder="Entity ID">
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search me-2"></i>Filter
                        </button>
                    </div>
                </div>
            </form>
        </div>

        <!-- Results Summary -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <div>
                <strong>Total Logs:</strong> <?php echo number_format($total_logs); ?>
                <?php if ($total_logs > 0): ?>
                    <span class="text-muted">(Page <?php echo $page; ?> of <?php echo $total_pages; ?>)</span>
                <?php endif; ?>
            </div>
            <div>
                <a href="audit_logs.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-times me-2"></i>Clear Filters
                </a>
            </div>
        </div>

        <!-- Audit Logs -->
        <?php if (empty($logs)): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>No audit logs found matching your filters.
            </div>
        <?php else: ?>
            <?php foreach ($logs as $log): ?>
                <div class="log-entry <?php echo $log['status']; ?>">
                    <div class="log-header">
                        <div>
                            <span class="log-action"><?php echo htmlspecialchars(str_replace('_', ' ', ucwords($log['action_type'], '_'))); ?></span>
                            <?php if ($log['entity_type']): ?>
                                <span class="badge bg-secondary badge-custom ms-2">
                                    <?php echo htmlspecialchars(ucfirst($log['entity_type'])); ?>
                                    <?php if ($log['entity_id']): ?>
                                        #<?php echo $log['entity_id']; ?>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                            <span class="badge bg-<?php echo $log['status'] == 'success' ? 'success' : ($log['status'] == 'failed' ? 'danger' : 'warning'); ?> badge-custom ms-2">
                                <?php echo ucfirst($log['status']); ?>
                            </span>
                        </div>
                        <div class="log-meta">
                            <i class="fas fa-clock me-1"></i><?php echo date('M j, Y g:i A', strtotime($log['created_at'])); ?>
                        </div>
                    </div>
                    <div class="log-description">
                        <strong><?php echo htmlspecialchars($log['admin_name']); ?></strong> - 
                        <?php echo htmlspecialchars($log['action_description']); ?>
                    </div>
                    
                    <?php if ($log['old_values'] || $log['new_values']): ?>
                        <div class="row mt-2">
                            <?php if ($log['old_values']): ?>
                                <div class="col-md-6">
                                    <small class="text-muted d-block mb-1"><strong>Old Values:</strong></small>
                                    <div class="json-values">
                                        <?php echo htmlspecialchars(json_encode($log['old_values'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <?php if ($log['new_values']): ?>
                                <div class="col-md-6">
                                    <small class="text-muted d-block mb-1"><strong>New Values:</strong></small>
                                    <div class="json-values">
                                        <?php echo htmlspecialchars(json_encode($log['new_values'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($log['error_message']): ?>
                        <div class="alert alert-danger mt-2 mb-0 py-2">
                            <small><strong>Error:</strong> <?php echo htmlspecialchars($log['error_message']); ?></small>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($log['ip_address']): ?>
                        <div class="mt-2">
                            <small class="text-muted">
                                <i class="fas fa-network-wired me-1"></i>IP: <?php echo htmlspecialchars($log['ip_address']); ?>
                            </small>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <nav aria-label="Page navigation">
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">Previous</a>
                            </li>
                        <?php endif; ?>
                        
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">Next</a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

