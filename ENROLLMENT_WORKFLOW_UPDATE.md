# Enrollment Workflow System Update

## Overview
Updated the enrollment system to implement the new workflow:
- **Regular Students**: Student → Registrar → Admin
- **Irregular Students**: Student → Program Head → Registrar → Admin

## Database Changes

### Migration File
`database/update_enrollment_workflow.sql`

**Changes:**
1. Updated `next_semester_enrollments.request_status` enum to:
   - `draft` - Initial state
   - `pending_program_head` - Waiting for program head (irregular only)
   - `pending_registrar` - Waiting for registrar
   - `pending_admin` - Waiting for admin final approval
   - `confirmed` - Final approval complete
   - `rejected` - Rejected at any stage

2. Added `enrollment_type` column (regular/irregular)

3. Created `enrollment_approvals` table to track approval chain

4. Added COR tracking fields (`cor_generated`, `cor_generated_at`, `cor_generated_by`)

5. Linked `certificate_of_registration` to enrollment requests

## Code Changes

### 1. Student Enrollment (`student/enroll_next_semester.php`)
- Updated to classify students as regular/irregular based on D/W/F grades
- Routes regular students to `pending_registrar`
- Routes irregular students to `pending_program_head`

### 2. Program Head Review (`program_head/next_semester_enrollments.php`)
- Updated query to show only `pending_program_head` status enrollments
- Filters by `enrollment_type = 'irregular'`

### 3. Enrollment Workflow Helper (`admin/enrollment_workflow_helper.php`)
- New helper functions for managing workflow:
  - `recordEnrollmentApproval()` - Records approval actions
  - `updateEnrollmentStatus()` - Updates status and records approval
  - `markCORGenerated()` - Marks COR as generated
  - `getEnrollmentApprovalHistory()` - Gets approval history

### 4. Review Page (`admin/review_next_semester.php`)
- Updated status checks to use new workflow statuses
- Program Head approval: `pending_program_head` → `pending_registrar`
- Registrar approval: `pending_registrar` → `pending_admin` (after COR generation)
- Admin approval: `pending_admin` → `confirmed`
- Updated rejection logic to work with new workflow

## Workflow Status Flow

### Regular Student
```
draft → pending_registrar → pending_admin → confirmed
```

### Irregular Student
```
draft → pending_program_head → pending_registrar → pending_admin → confirmed
```

## Next Steps

1. **Run Database Migration**
   ```sql
   SOURCE database/update_enrollment_workflow.sql;
   ```

2. **Update UI References**
   - Update status badges in dashboards
   - Update button visibility based on new statuses
   - Update status display text

3. **Update Registrar Dashboard**
   - Show enrollments with `pending_registrar` status
   - Add COR generation workflow

4. **Update Admin Dashboard**
   - Show enrollments with `pending_admin` status
   - Add final approval workflow

5. **Update Student Dashboard**
   - Show COR viewing capability when `cor_generated = TRUE`
   - Display enrollment status with workflow progress

## Testing Checklist

- [ ] Regular student enrollment routes to registrar
- [ ] Irregular student enrollment routes to program head
- [ ] Program head can approve irregular students
- [ ] Registrar can generate COR and forward to admin
- [ ] Admin can give final approval
- [ ] Rejection works at all stages
- [ ] COR generation links to enrollment
- [ ] Student can view COR after registrar generates it
- [ ] Approval history is recorded correctly

