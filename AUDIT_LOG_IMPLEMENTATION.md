# Audit Log System Implementation

## Overview

A comprehensive audit logging system has been implemented for the admin module to track all administrative actions and changes in the system.

## Components

### 1. Database Table (`database/create_audit_logs_table.sql`)
- Table: `audit_logs`
- Stores all admin actions with:
  - Admin information (ID, name)
  - Action details (type, description)
  - Entity information (type, ID)
  - Old and new values (JSON format)
  - Status (success, failed, partial)
  - IP address and user agent
  - Timestamp

### 2. AuditLog Class (`classes/AuditLog.php`)
- Methods:
  - `log(array $data)` - Log an admin action
  - `getLogs(array $filters)` - Retrieve logs with filters
  - `getLogCount(array $filters)` - Get count of logs
  - `getActionTypes()` - Get distinct action types
  - `getEntityTypes()` - Get distinct entity types

### 3. Audit Helper Functions (`config/audit_helper.php`)
- `logAdminAction()` - Convenience function for logging actions
- Action type constants (e.g., `AUDIT_ACTION_USER_STATUS_UPDATE`)
- Entity type constants (e.g., `AUDIT_ENTITY_USER`)

### 4. Admin UI (`admin/audit_logs.php`)
- View all audit logs
- Filter by:
  - Admin
  - Action type
  - Entity type
  - Date range
  - Status
  - Entity ID
- Pagination support
- View old/new values
- Display error messages

## Installation

1. **Run the database migration:**
   ```sql
   SOURCE database/create_audit_logs_table.sql;
   ```

2. **The system is ready to use!**

## Usage

### Logging an Action

Use the `logAdminAction()` helper function:

```php
require_once '../config/audit_helper.php';

logAdminAction(
    AUDIT_ACTION_USER_STATUS_UPDATE,  // Action type
    "Updated user status for user ID 123",  // Description
    AUDIT_ENTITY_USER,  // Entity type
    123,  // Entity ID
    ['status' => 'inactive'],  // Old values
    ['status' => 'active'],  // New values
    'success',  // Status
    null  // Error message (if failed)
);
```

### Available Action Types

- `AUDIT_ACTION_USER_STATUS_UPDATE`
- `AUDIT_ACTION_USER_UPDATE`
- `AUDIT_ACTION_ENROLLED_STUDENT_UPDATE`
- `AUDIT_ACTION_SECTION_ASSIGNMENT`
- `AUDIT_ACTION_SECTION_REMOVAL`
- `AUDIT_ACTION_GRADE_ENTRY`
- `AUDIT_ACTION_GRADE_UPDATE`
- `AUDIT_ACTION_GRADE_VERIFY`
- `AUDIT_ACTION_COR_GENERATION`
- `AUDIT_ACTION_ENROLLMENT_APPROVAL`
- `AUDIT_ACTION_ENROLLMENT_REJECTION`
- `AUDIT_ACTION_SECTION_CREATE`
- `AUDIT_ACTION_SECTION_UPDATE`
- `AUDIT_ACTION_SECTION_DELETE`
- `AUDIT_ACTION_SCHEDULE_CREATE`
- `AUDIT_ACTION_SCHEDULE_UPDATE`
- `AUDIT_ACTION_SCHEDULE_DELETE`
- `AUDIT_ACTION_CURRICULUM_UPDATE`
- `AUDIT_ACTION_DOCUMENT_VERIFY`
- `AUDIT_ACTION_DOCUMENT_REJECT`
- `AUDIT_ACTION_ENROLLMENT_CONTROL_UPDATE`
- `AUDIT_ACTION_BULK_IMPORT`
- `AUDIT_ACTION_FAQ_CREATE`
- `AUDIT_ACTION_FAQ_UPDATE`
- `AUDIT_ACTION_FAQ_DELETE`

### Available Entity Types

- `AUDIT_ENTITY_USER`
- `AUDIT_ENTITY_ENROLLED_STUDENT`
- `AUDIT_ENTITY_SECTION`
- `AUDIT_ENTITY_GRADE`
- `AUDIT_ENTITY_COR`
- `AUDIT_ENTITY_ENROLLMENT`
- `AUDIT_ENTITY_SCHEDULE`
- `AUDIT_ENTITY_CURRICULUM`
- `AUDIT_ENTITY_DOCUMENT`
- `AUDIT_ENTITY_ENROLLMENT_CONTROL`
- `AUDIT_ENTITY_FAQ`

## Examples

### Example 1: User Status Update

```php
// Get old values
$old_user = $user->getUserById($user_id);
$old_status = $old_user['status'];

// Update user
if ($user->updateUserStatus($user_id, $new_status)) {
    logAdminAction(
        AUDIT_ACTION_USER_STATUS_UPDATE,
        "Updated user status for user ID {$user_id} from '{$old_status}' to '{$new_status}'",
        AUDIT_ENTITY_USER,
        $user_id,
        ['status' => $old_status],
        ['status' => $new_status],
        'success'
    );
} else {
    logAdminAction(
        AUDIT_ACTION_USER_STATUS_UPDATE,
        "Failed to update user status for user ID {$user_id}",
        AUDIT_ENTITY_USER,
        $user_id,
        ['status' => $old_status],
        ['status' => $new_status],
        'failed',
        'Database update failed'
    );
}
```

### Example 2: Grade Entry

```php
logAdminAction(
    AUDIT_ACTION_GRADE_ENTRY,
    "Entered grade for student ID {$student_id}, subject: {$subject_code}",
    AUDIT_ENTITY_GRADE,
    $grade_id,
    null,  // No old values for new entry
    ['student_id' => $student_id, 'subject' => $subject_code, 'grade' => $grade],
    'success'
);
```

### Example 3: Section Assignment

```php
logAdminAction(
    AUDIT_ACTION_SECTION_ASSIGNMENT,
    "Assigned student ID {$student_id} to section {$section_name}",
    AUDIT_ENTITY_SECTION,
    $section_id,
    ['section_id' => null],
    ['section_id' => $section_id, 'section_name' => $section_name],
    'success'
);
```

## Accessing Audit Logs

1. **Via Admin Dashboard:**
   - Navigate to "Audit Logs" in the sidebar
   - View all logs with filtering options

2. **Via Direct URL:**
   - `admin/audit_logs.php`

## Filtering Logs

The audit logs page supports filtering by:
- **Admin** - Filter by specific admin who performed the action
- **Action Type** - Filter by type of action
- **Entity Type** - Filter by type of entity affected
- **Entity ID** - Filter by specific entity ID
- **Date Range** - Filter by date from/to
- **Status** - Filter by success/failed/partial status

## Security Features

- IP address tracking
- User agent tracking
- Admin name caching (preserves historical reference even if admin is deleted)
- Foreign key constraint on admin_id
- Silent failure on logging errors (doesn't break application)

## Best Practices

1. **Always log before/after values** when updating data
2. **Use descriptive action descriptions** that explain what happened
3. **Include error messages** when actions fail
4. **Log both successful and failed actions**
5. **Use appropriate action and entity type constants**

## Integration Status

The following admin actions have been integrated with audit logging:
- ✅ User status updates (`admin/update_user.php`)
- ✅ Enrolled student updates (`admin/update_enrolled_student.php`)

## Next Steps

To fully utilize the audit logging system, integrate it into other admin actions:
- Section assignments
- Grade entries
- COR generation
- Enrollment approvals
- Schedule management
- Curriculum updates
- Document verification
- And more...

## Notes

- Audit logs are never automatically deleted (preserve for compliance)
- Logging failures are silent and won't break the application
- Old and new values are stored as JSON for flexibility
- All timestamps are automatically recorded
- The system tracks IP addresses for security purposes

