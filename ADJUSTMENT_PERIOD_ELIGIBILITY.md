# Adjustment Period Eligibility - Documentation

## Overview
The adjustment period flow is available for **ALL enrolled students from 1st Year First Semester onwards**. There are no year level restrictions - any student who is currently enrolled or has confirmed enrollment for the next semester can access the adjustment period.

## Eligibility Criteria

### Current Implementation
Students can access the adjustment period if they meet **either** of the following conditions:
1. **Currently enrolled** - Student has `enrollment_status = 'enrolled'` in the `users` table
2. **Confirmed next semester enrollment** - Student has a record in `next_semester_enrollments` with `request_status = 'confirmed'`

### Year Level Coverage
- ✅ **1st Year First Semester** - Eligible
- ✅ **1st Year Second Semester** - Eligible
- ✅ **2nd Year First Semester** - Eligible
- ✅ **2nd Year Second Semester** - Eligible
- ✅ **3rd Year (any semester)** - Eligible
- ✅ **4th Year (any semester)** - Eligible
- ✅ **5th Year (any semester)** - Eligible

**No year level restrictions apply** - all enrolled students from 1st Year First Semester onwards have access.

## Implementation Details

### Files Involved
1. **`student/adjustment_period.php`** (lines 27-48)
   - Main eligibility check
   - Verifies enrollment status or confirmed next semester enrollment
   - No year level validation

2. **`student/process_adjustment.php`** (lines 26-46)
   - API endpoint eligibility check
   - Same logic as main page
   - Returns JSON error if not eligible

3. **`student/dashboard.php`** (lines 1471-1495)
   - Shows adjustment period button
   - Visible for all enrolled students
   - No year level restrictions

### Year Level Detection
The system detects year level from multiple sources (in order of priority):
1. Most recent Certificate of Registration (COR)
2. Most recent active section enrollment
3. `enrolled_students` table
4. Defaults to '1st Year' if not found

### Features Available by Year Level

#### All Students (1st Year onwards)
- ✅ Add subjects from current year level
- ✅ Remove enrolled subjects
- ✅ Change schedules for enrolled subjects
- ✅ View current enrolled subjects
- ✅ Submit adjustment requests (workflow: Program Head → Dean → Registrar)

#### 2nd Year and Above Only
- ✅ View backload subjects (subjects from lower year levels)
- ✅ Add backload subjects during adjustment period

**Note:** 1st Year students do not see backload subjects, which is correct behavior since they are the entry level and cannot have subjects from lower year levels.

## Verification Results

### ✅ Eligibility Checks
- No year level restrictions found in eligibility logic
- All enrolled students can access adjustment period
- 1st Year students are explicitly included

### ✅ Year Level Detection
- Year level detection works for all year levels including 1st Year
- Multiple fallback mechanisms ensure year level is always detected
- Defaults to '1st Year' if no data found

### ✅ Dashboard Access
- Adjustment period button visible for all enrolled students
- No year level restrictions hiding the button
- Button appears when adjustment period is open AND student is enrolled

### ✅ Feature Availability
- All core features (add/remove/change schedule) work for 1st Year students
- Backload subjects correctly restricted to 2nd Year and above
- Current year subjects display correctly for all year levels

## Code Comments Added
Clarifying comments have been added to the following files to document that all students from 1st Year First Semester onwards are eligible:
- `student/adjustment_period.php` - Eligibility check and backload logic
- `student/process_adjustment.php` - API eligibility check
- `student/dashboard.php` - Button visibility logic

## Conclusion
The adjustment period flow is **correctly implemented** to allow all enrolled students from 1st Year First Semester onwards to access the adjustment period. No changes were needed - only clarifying comments were added to document this behavior.

