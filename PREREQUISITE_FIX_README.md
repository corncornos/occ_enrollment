# Prerequisites System Fix

## Problem
NSTP 2 was being checked/allowed for enrollment even though the student had an F (failing) grade in NSTP 1, which is a prerequisite.

## Root Cause
The `subject_prerequisites` table existed but was **completely empty** (0 prerequisites defined). The prerequisite checking logic in `admin/review_next_semester.php` relies on this table to validate prerequisites during enrollment approval.

## Solution
1. **Created sync script**: `admin/sync_prerequisites.php`
   - Syncs prerequisites from `curriculum.pre_requisites` column to `subject_prerequisites` table
   - Handles all prerequisite relationships automatically
   - Specifically ensures all NSTP2 courses require NSTP1
   - Can be run anytime to sync new curriculum data

2. **Populated prerequisites**: Ran initial sync to populate the system
   - Added 30 prerequisite relationships
   - All NSTP2 courses now properly require NSTP1 (minimum grade: 3.00)
   - Includes prerequisites for other courses (PE, IS, CC, DM, BTVE, EDUC, etc.)

## How Prerequisites Work Now

### Prerequisite Checking Logic
Located in `admin/review_next_semester.php` (lines 966-1024):

1. **Gets student grades** from `student_grades` table
2. **Checks each subject's prerequisites** from `subject_prerequisites` table
3. **Validates prerequisites**:
   - Student hasn't taken prerequisite → Subject disabled
   - Prerequisite grade is INC (Incomplete) → Subject disabled
   - Prerequisite grade is F/FA/Failed → Subject disabled
   - Prerequisite grade ≥ 5.0 → Subject disabled
   - Prerequisite grade doesn't meet minimum (default 3.00) → Subject disabled

4. **Auto-unchecks subjects** with unmet prerequisites
5. **Only checked subjects** are enrolled when approved

### Example: NSTP 2
- **Prerequisite**: NSTP 1
- **Minimum Grade**: 3.00 (passing)
- **If student has F in NSTP 1**: NSTP 2 is automatically disabled and unchecked
- **Student cannot enroll** until they retake and pass NSTP 1

## Files Modified/Created
- ✅ `admin/sync_prerequisites.php` - Web interface to sync prerequisites (NEW)
- ✅ `subject_prerequisites` table - Populated with 30 prerequisite relationships

## Testing the Fix
1. Log in as Program Head for BSIS
2. Go to "Next Semester Enrollments"
3. Review a student who has F in NSTP 1
4. NSTP 2 should now be:
   - **Automatically unchecked**
   - **Disabled/grayed out**
   - **Showing red warning**: "Grade F (Failed) - must retake and pass this course"

## Maintenance
- **After bulk curriculum upload**: Run `admin/sync_prerequisites.php`
- **After manual prerequisite changes**: Run the sync tool
- **Minimum grade requirement**: Currently set to 3.00 (passing grade)
- **To modify minimum grade**: Update `subject_prerequisites.minimum_grade` column

## Database Schema

### subject_prerequisites Table
```sql
CREATE TABLE IF NOT EXISTS `subject_prerequisites` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    curriculum_id INT NOT NULL COMMENT 'The subject that requires prerequisites',
    prerequisite_curriculum_id INT NOT NULL COMMENT 'The prerequisite subject',
    minimum_grade DECIMAL(3,2) DEFAULT 3.00 COMMENT 'Minimum passing grade',
    is_required BOOLEAN DEFAULT TRUE COMMENT 'Whether this prerequisite is mandatory',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (curriculum_id) REFERENCES curriculum(id) ON DELETE CASCADE,
    FOREIGN KEY (prerequisite_curriculum_id) REFERENCES curriculum(id) ON DELETE CASCADE
);
```

## Impact
- ✅ Prerequisites now properly enforced during enrollment
- ✅ Students cannot enroll in advanced courses without passing prerequisites
- ✅ Failing grades (F, INC) properly prevent enrollment in next courses
- ✅ Program heads see clear warnings about unmet prerequisites
- ✅ System maintains academic integrity

## Future Enhancements
1. Add UI in admin dashboard to manage prerequisites manually
2. Allow different minimum grades per prerequisite
3. Add "corequisite" support (courses that must be taken together)
4. Add prerequisite waiver/override functionality for special cases

