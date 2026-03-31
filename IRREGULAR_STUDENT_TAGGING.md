# Irregular Student Tagging System

## Overview
The system now automatically tags students as **"Irregular"** when they are enrolled for a semester but are missing one or more **required subjects** for their year level and semester.

## How It Works

### Automatic Determination
When a student is enrolled (approved by admin/registrar), the system:

1. **Gets all required subjects** for the student's:
   - Program (e.g., BSIS, BTVTED, BSE)
   - Year Level (e.g., 1st Year, 2nd Year)
   - Semester (e.g., First Semester, Second Semester)

2. **Checks enrolled subjects** from:
   - `next_semester_subject_selections` (subjects checked during approval)
   - `student_schedules` (active subjects in student's schedule)

3. **Compares** required vs enrolled:
   - **All required subjects enrolled** → Student Type: `Regular`
   - **Missing ANY required subject(s)** → Student Type: `Irregular`

### Where Tagging Happens
- **File**: `admin/sync_user_to_enrolled_students.php`
- **Function**: `determineStudentType()`
- **Triggered**: When admin approves enrollment in `admin/review_next_semester.php`
- **Stored**: `enrolled_students.student_type` column

## Student Types

```sql
student_type ENUM('Regular', 'Irregular', 'Transferee')
```

### Regular Student
- Enrolled in **ALL required subjects** for their year level and semester
- Following the standard curriculum flow
- No missing required courses

### Irregular Student
- **Missing one or more required subjects** for their year level and semester
- Reasons can include:
  - Failed prerequisite courses (e.g., F in NSTP 1, cannot take NSTP 2)
  - Schedule conflicts
  - Personal circumstances
  - Academic performance issues
  - Incomplete grades (INC) in prerequisites

### Transferee
- Transfer students from other institutions
- May have different subject requirements
- Must be manually set by admin

## Examples

### Example 1: Regular Student
**Program**: BSIS 1st Year, First Semester  
**Required Subjects**: CC101, CC102, GE1, GE2, GE3, NSTP 1, PE 1 (7 subjects)  
**Enrolled Subjects**: All 7 subjects  
**Result**: ✅ **Regular**

### Example 2: Irregular Student - Failed Prerequisite
**Program**: BSIS 1st Year, Second Semester  
**Required Subjects**: CC103, GE4, GE5, GE6, IS101, NSTP 2, PE 2 (7 subjects)  
**Student has**: F grade in NSTP 1  
**Enrolled Subjects**: CC103, GE4, GE5, GE6, IS101, PE 2 (6 subjects - NSTP 2 excluded due to failed prerequisite)  
**Result**: ❌ **Irregular** (Missing NSTP 2)

### Example 3: Irregular Student - Schedule Conflict
**Program**: BSIS 2nd Year, First Semester  
**Required Subjects**: 8 subjects  
**Enrolled Subjects**: 6 subjects (2 subjects have schedule conflicts)  
**Result**: ❌ **Irregular** (Missing 2 required subjects)

## Implementation Details

### Database Schema
```sql
-- enrolled_students table
ALTER TABLE enrolled_students 
ADD COLUMN student_type ENUM('Regular','Irregular','Transferee') DEFAULT 'Regular';
```

### Code Changes

#### 1. Modified: `admin/sync_user_to_enrolled_students.php`
```php
// Added determineStudentType() function
function determineStudentType($conn, $user_id, $student_data) {
    // Gets required subjects for program/year/semester
    // Gets enrolled subjects for the student
    // Compares and returns 'Regular' or 'Irregular'
}
```

#### 2. Integration Point: `admin/review_next_semester.php`
```php
// Line ~605: When admin approves enrollment
syncUserToEnrolledStudents($conn, $request['user_id'], [
    'course' => $program_code,
    'year_level' => $new_year_level,
    'academic_year' => $target_academic_year,
    'semester' => $target_semester
    // student_type is automatically determined
]);
```

## Benefits

### 1. Automatic Classification
- No manual intervention needed
- Consistent and accurate tagging
- Updates automatically on enrollment

### 2. Academic Tracking
- Easy identification of students needing support
- Track irregular student population
- Monitor academic performance issues

### 3. Reporting & Analytics
- Count regular vs irregular students per program
- Track irregular student progression
- Identify common subjects causing irregularity

### 4. Academic Advising
- Advisors can quickly identify students needing guidance
- Prioritize support for irregular students
- Track progress toward regularization

## Usage

### Viewing Student Type

**In Admin Dashboard** (example query):
```sql
SELECT student_id, first_name, last_name, student_type, year_level, semester
FROM enrolled_students
WHERE student_type = 'Irregular'
ORDER BY student_id;
```

**In Reports**:
- Student lists show type badges (Regular/Irregular/Transferee)
- Filtering by student type
- Statistics: X% Regular, Y% Irregular

### Changing Student Type Manually
If needed, admins can override:
```sql
UPDATE enrolled_students 
SET student_type = 'Regular' 
WHERE user_id = [student_user_id];
```

## Future Enhancements

### 1. Student Dashboard Notification
- Show irregular status to students
- List missing required subjects
- Suggest enrollment path to regularization

### 2. Advisor Tools
- Irregular student list with details
- Missing subjects report per student
- Progress tracking toward regular status

### 3. Email Notifications
- Alert students when tagged as irregular
- Notify advisors of irregular students
- Remedial course suggestions

### 4. Regularization Tracking
- Track when irregular students complete missing subjects
- Auto-update to Regular when requirements met
- Progress reports

### 5. Analytics Dashboard
- Irregular student trends by program
- Common subjects causing irregularity
- Success rates for regularization

## Technical Notes

### Performance
- Determination runs only during enrollment approval
- Uses indexed queries (program_id, year_level, semester)
- Cached in `enrolled_students` table (no real-time calculation)

### Edge Cases
1. **No required subjects defined**: Defaults to 'Regular'
2. **Can't determine program**: Defaults to 'Regular'
3. **Database error**: Defaults to 'Regular' and logs error
4. **Transferees**: Must be manually set (not auto-determined)

### Maintenance
- Review irregular classification periodically
- Update curriculum requirements as needed
- Run prerequisite sync after curriculum changes

## Testing

### Test Scenario 1: Regular Student
1. Enroll student in all required subjects
2. Approve enrollment
3. Check `enrolled_students.student_type` → Should be 'Regular'

### Test Scenario 2: Irregular Student
1. Student has F in prerequisite (e.g., NSTP 1)
2. Approve enrollment without NSTP 2 (blocked by prerequisite)
3. Check `enrolled_students.student_type` → Should be 'Irregular'

### Test Scenario 3: Partial Enrollment
1. Program head unchecks some required subjects
2. Approve enrollment with partial subjects
3. Check `enrolled_students.student_type` → Should be 'Irregular'

## Related Files
- `admin/sync_user_to_enrolled_students.php` - Main logic
- `admin/review_next_semester.php` - Integration point
- `database/enrollment_occ.sql` - Database schema
- `admin/sync_prerequisites.php` - Prerequisite management

## Summary
The irregular student tagging system provides automatic, accurate classification of students based on their subject enrollment status. This helps academic advisors, administrators, and students themselves track academic progress and identify students who may need additional support or guidance to complete their program requirements.

**Key Rule**: Missing ANY required subject = Irregular Student

