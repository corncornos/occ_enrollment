<?php
declare(strict_types=1);

/**
 * Audit Helper Functions
 * Convenience functions for logging admin actions
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../classes/AuditLog.php';

/**
 * Log an admin action
 * 
 * @param string $action_type Type of action (e.g., 'user_status_update', 'section_assignment')
 * @param string $action_description Human-readable description
 * @param string|null $entity_type Type of entity affected
 * @param int|null $entity_id ID of entity affected
 * @param array|null $old_values Old values before change
 * @param array|null $new_values New values after change
 * @param string $status 'success', 'failed', or 'partial'
 * @param string|null $error_message Error message if failed
 * @return bool True on success
 */
function logAdminAction(
    string $action_type,
    string $action_description,
    ?string $entity_type = null,
    ?int $entity_id = null,
    ?array $old_values = null,
    ?array $new_values = null,
    string $status = 'success',
    ?string $error_message = null
): bool {
    // Check if admin is logged in
    if (!isLoggedIn() || !isAdmin()) {
        return false;
    }
    
    try {
        $auditLog = new AuditLog();
        
        // Get admin information from session
        $admin_id = $_SESSION['user_id'] ?? null;
        $admin_name = ($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '');
        
        if (empty($admin_name)) {
            $admin_name = $_SESSION['email'] ?? 'Unknown Admin';
        }
        
        if (!$admin_id) {
            return false;
        }
        
        return $auditLog->log([
            'admin_id' => (int)$admin_id,
            'admin_name' => trim($admin_name),
            'action_type' => $action_type,
            'action_description' => $action_description,
            'entity_type' => $entity_type,
            'entity_id' => $entity_id,
            'old_values' => $old_values,
            'new_values' => $new_values,
            'status' => $status,
            'error_message' => $error_message
        ]);
        
    } catch (Exception $e) {
        error_log('Audit log helper error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Common action type constants
 */
define('AUDIT_ACTION_USER_STATUS_UPDATE', 'user_status_update');
define('AUDIT_ACTION_USER_UPDATE', 'user_update');
define('AUDIT_ACTION_ENROLLED_STUDENT_UPDATE', 'enrolled_student_update');
define('AUDIT_ACTION_SECTION_ASSIGNMENT', 'section_assignment');
define('AUDIT_ACTION_SECTION_REMOVAL', 'section_removal');
define('AUDIT_ACTION_GRADE_ENTRY', 'grade_entry');
define('AUDIT_ACTION_GRADE_UPDATE', 'grade_update');
define('AUDIT_ACTION_GRADE_VERIFY', 'grade_verify');
define('AUDIT_ACTION_COR_GENERATION', 'cor_generation');
define('AUDIT_ACTION_ENROLLMENT_APPROVAL', 'enrollment_approval');
define('AUDIT_ACTION_ENROLLMENT_REJECTION', 'enrollment_rejection');
define('AUDIT_ACTION_SECTION_CREATE', 'section_create');
define('AUDIT_ACTION_SECTION_UPDATE', 'section_update');
define('AUDIT_ACTION_SECTION_DELETE', 'section_delete');
define('AUDIT_ACTION_SCHEDULE_CREATE', 'schedule_create');
define('AUDIT_ACTION_SCHEDULE_UPDATE', 'schedule_update');
define('AUDIT_ACTION_SCHEDULE_DELETE', 'schedule_delete');
define('AUDIT_ACTION_CURRICULUM_UPDATE', 'curriculum_update');
define('AUDIT_ACTION_DOCUMENT_VERIFY', 'document_verify');
define('AUDIT_ACTION_DOCUMENT_REJECT', 'document_reject');
define('AUDIT_ACTION_ENROLLMENT_CONTROL_UPDATE', 'enrollment_control_update');
define('AUDIT_ACTION_BULK_IMPORT', 'bulk_import');
define('AUDIT_ACTION_FAQ_CREATE', 'faq_create');
define('AUDIT_ACTION_FAQ_UPDATE', 'faq_update');
define('AUDIT_ACTION_FAQ_DELETE', 'faq_delete');

/**
 * Entity type constants
 */
define('AUDIT_ENTITY_USER', 'user');
define('AUDIT_ENTITY_ENROLLED_STUDENT', 'enrolled_student');
define('AUDIT_ENTITY_SECTION', 'section');
define('AUDIT_ENTITY_GRADE', 'grade');
define('AUDIT_ENTITY_COR', 'cor');
define('AUDIT_ENTITY_ENROLLMENT', 'enrollment');
define('AUDIT_ENTITY_SCHEDULE', 'schedule');
define('AUDIT_ENTITY_CURRICULUM', 'curriculum');
define('AUDIT_ENTITY_DOCUMENT', 'document');
define('AUDIT_ENTITY_ENROLLMENT_CONTROL', 'enrollment_control');
define('AUDIT_ENTITY_FAQ', 'faq');

?>

