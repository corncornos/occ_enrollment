# Student Data Consistency Across All Modules

## Overview
This document describes the centralized system for ensuring student data consistency across all modules (student, admin, program_head, registrar_staff, admission, dean).

## Problem
Previously, different modules retrieved student data from different sources:
- Some modules used `users` table directly
- Some modules used `enrolled_students` table
- Data could become inconsistent between these tables
- Updates in one module might not reflect in other modules

## Solution
A centralized student data helper system has been implemented to ensure consistency.

## Implementation

### 1. Centralized Helper Functions (`config/student_data_helper.php`)

#### `getStudentData($conn, $user_id, $auto_sync = true)`
- Retrieves student data from `enrolled_students` table (preferred source)
- Automatically syncs with `users` table if data is inconsistent
- Returns consistent student data across all modules

#### `getStudentDataWithSections($conn, $user_id, $auto_sync = true)`
- Gets student data with section information
- Includes active section enrollments

#### `getStudentDataWithGrades($conn, $user_id, $auto_sync = true)`
- Gets student data with grade information
- Includes all verified/finalized grades

#### `getCompleteStudentData($conn, $user_id, $auto_sync = true)`
- Gets complete student data with all related information:
  - Personal information
  - Sections
  - Grades
  - Certificate of Registration (CORs)
  - Enrollment requests

#### `ensureStudentDataSync($conn, $user_id, $additional_data = [])`
- Ensures data is synced between `users` and `enrolled_students` tables
- Should be called whenever student data is updated

### 2. Updated API Endpoints

The following API endpoints now use centralized helpers:
- `admin/get_enrolled_student_info.php` - Uses `getStudentData()`
- `admin/get_enrolled_student.php` - Uses `getStudentDataWithSections()`

### 3. Automatic Sync on Updates

The following update operations now trigger automatic sync:
- `admin/update_enrolled_student.php` - Syncs after student data update
- `admin/update_enrollment_status.php` - Syncs after enrollment status change

## Data Flow

### Reading Student Data
1. Module requests student data
2. Helper function checks `enrolled_students` table first
3. If data exists, compares with `users` table for consistency
4. If inconsistent, automatically syncs
5. Returns consistent data

### Updating Student Data
1. Module updates data in `users` table
2. Calls `ensureStudentDataSync()` or `syncUserToEnrolledStudents()`
3. Data is synced to `enrolled_students` table
4. All modules see updated data immediately

## Usage Examples

### In Admin Module
```php
require_once '../config/student_data_helper.php';

$student_data = getStudentData($conn, $user_id, true);
```

### In Program Head Module
```php
require_once '../config/student_data_helper.php';

$student_data = getStudentDataWithSections($conn, $user_id, true);
```

### In Registrar Staff Module
```php
require_once '../config/student_data_helper.php';

$student_data = getCompleteStudentData($conn, $user_id, true);
```

### After Updating Student Data
```php
require_once '../config/student_data_helper.php';

// Update users table
$update_query = "UPDATE users SET first_name = :first_name WHERE id = :user_id";
// ... execute update ...

// Ensure sync
ensureStudentDataSync($conn, $user_id);
```

## Benefits

1. **Consistency**: All modules see the same student data
2. **Automatic Sync**: Data is automatically synced when inconsistencies are detected
3. **Single Source of Truth**: `enrolled_students` table is the primary source for enrolled students
4. **Easy Maintenance**: Centralized functions make updates easier
5. **Error Prevention**: Reduces data inconsistencies across modules

## Migration Notes

### For Existing Code
When updating existing code to use centralized helpers:

1. Replace direct database queries with helper functions
2. Add `require_once '../config/student_data_helper.php';` at the top
3. Replace `SELECT` queries with appropriate helper function
4. Add `ensureStudentDataSync()` after any `UPDATE` operations

### Example Migration

**Before:**
```php
$query = "SELECT * FROM enrolled_students WHERE user_id = :user_id";
$stmt = $conn->prepare($query);
$stmt->bindParam(':user_id', $user_id);
$stmt->execute();
$student = $stmt->fetch(PDO::FETCH_ASSOC);
```

**After:**
```php
require_once '../config/student_data_helper.php';
$student = getStudentData($conn, $user_id, true);
```

## Future Improvements

1. Update all modules to use centralized helpers
2. Add database triggers for automatic sync (optional)
3. Add logging for sync operations
4. Create admin interface to view sync status

## Files Modified

- `config/student_data_helper.php` - NEW: Centralized helper functions
- `admin/get_enrolled_student_info.php` - Updated to use helper
- `admin/get_enrolled_student.php` - Updated to use helper
- `admin/update_enrolled_student.php` - Updated to use helper
- `admin/update_enrollment_status.php` - Updated to use helper

## Testing

To test data consistency:
1. Update student data in one module
2. Check if the update appears in all other modules
3. Verify that `users` and `enrolled_students` tables are in sync

