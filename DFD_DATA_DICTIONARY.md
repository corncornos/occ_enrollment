# Data Flow Diagram - Data Dictionary

## Overview
This data dictionary defines all data stores, data flows, and data elements used in the OCC Enrollment System DFDs.

---

## Data Stores

### D1: Users
**Description:** Stores all user account information including students, administrators, and other system users.

**Key Attributes:**
- id (Primary Key)
- student_id
- first_name
- last_name
- email
- password (hashed)
- phone
- date_of_birth
- address
- role (student/admin)
- status (active/inactive/pending)
- enrollment_status (enrolled/pending)
- created_at
- updated_at

**Related Processes:** 1.0, 2.0, 4.0, 5.0, 6.0, 7.0

---

### D2: Admins
**Description:** Stores administrator account information.

**Key Attributes:**
- id (Primary Key)
- admin_id
- first_name
- last_name
- email
- password (hashed)
- phone
- role
- status
- is_dean (flag)
- created_at
- updated_at

**Related Processes:** 1.0, 3.0

---

### D3: Admissions
**Description:** Stores admission officer account information.

**Key Attributes:**
- id (Primary Key)
- username
- email
- password (hashed)
- first_name
- last_name
- status
- created_at
- updated_at

**Related Processes:** 1.0

---

### D4: Program Heads
**Description:** Stores program head account information.

**Key Attributes:**
- id (Primary Key)
- username
- email
- password (hashed)
- first_name
- last_name
- program_id
- status
- created_at
- updated_at

**Related Processes:** 1.0, 3.0

---

### D5: Applicants
**Description:** Stores applicant information and application status.

**Key Attributes:**
- id (Primary Key)
- user_id (Foreign Key to Users)
- lrn
- occ_examinee_number
- program_id
- application_status (pending/verified/passed_to_registrar)
- student_number (assigned by admission)
- created_at
- updated_at

**Related Processes:** 2.0

---

### D6: Enrolled Students
**Description:** Stores enrolled student records with academic information.

**Key Attributes:**
- id (Primary Key)
- user_id (Foreign Key to Users)
- student_id
- program_id
- year_level
- semester
- academic_year
- student_type (Regular/Irregular/Transferee)
- enrollment_date
- created_at
- updated_at

**Related Processes:** 4.0, 7.0

---

### D7: Curriculum
**Description:** Stores curriculum course information for all programs.

**Key Attributes:**
- id (Primary Key)
- program_id
- course_code
- course_name
- units
- year_level
- semester
- is_required
- pre_requisites
- created_at
- updated_at

**Related Processes:** 3.0, 4.0

---

### D8: Curriculum Submissions
**Description:** Stores curriculum submission records from program heads.

**Key Attributes:**
- id (Primary Key)
- program_head_id
- program_id
- submission_title
- submission_description
- academic_year
- semester
- status (draft/submitted/approved/rejected)
- admin_approved
- dean_approved
- submitted_at
- reviewed_at
- reviewed_by
- reviewer_comments
- created_at
- updated_at

**Related Processes:** 3.0

---

### D9: Sections
**Description:** Stores section information for classes.

**Key Attributes:**
- id (Primary Key)
- section_name
- program_id
- year_level
- semester
- academic_year
- section_type
- max_capacity
- current_enrolled
- created_at
- updated_at

**Related Processes:** 4.0, 7.0

---

### D10: Section Enrollments
**Description:** Stores student-section enrollment relationships.

**Key Attributes:**
- id (Primary Key)
- section_id (Foreign Key to Sections)
- user_id (Foreign Key to Users)
- enrolled_date
- status (active/dropped)
- created_at
- updated_at

**Related Processes:** 4.0, 7.0

---

### D11: Schedules (section_schedules)
**Description:** Stores section schedule information.

**Key Attributes:**
- id (Primary Key)
- section_id (Foreign Key to Sections)
- curriculum_id
- course_code
- course_name
- units
- schedule_monday through schedule_sunday
- time_start
- time_end
- room
- professor_name
- professor_initial
- created_at
- updated_at

**Related Processes:** 4.0

---

### D12: Student Schedules
**Description:** Stores individual student schedule information.

**Key Attributes:**
- id (Primary Key)
- user_id (Foreign Key to Users)
- section_schedule_id
- course_code
- course_name
- units
- schedule_monday through schedule_sunday
- time_start
- time_end
- room
- professor_name
- professor_initial
- status (active/dropped)
- created_at
- updated_at

**Related Processes:** 4.0, 5.0, 7.0

---

### D13: Documents
**Description:** Stores document uploads and verification status.

**Key Attributes:**
- id (Primary Key)
- user_id (Foreign Key to Users)
- document_type
- file_name
- file_path
- file_size
- upload_date
- verification_status (pending/verified/rejected)
- rejection_reason
- verified_by
- verified_at

**Related Processes:** 6.0

---

### D14: Student Grades
**Description:** Stores student grade records.

**Key Attributes:**
- id (Primary Key)
- user_id (Foreign Key to Users)
- section_schedule_id
- course_code
- course_name
- academic_year
- semester
- grade
- remarks
- verified
- verified_by
- verified_at
- created_at
- updated_at

**Related Processes:** 4.0, 5.0

---

### D15: Certificate of Registration
**Description:** Stores generated Certificate of Registration records.

**Key Attributes:**
- id (Primary Key)
- user_id (Foreign Key to Users)
- program_id
- section_id
- student_number
- student_last_name
- student_first_name
- student_middle_name
- student_address
- academic_year
- year_level
- semester
- section_name
- registration_date
- subjects_json
- total_units
- created_by
- created_at

**Related Processes:** 4.0, 7.0

---

### D16: Enrollment Control
**Description:** Stores enrollment period control information.

**Key Attributes:**
- id (Primary Key)
- academic_year
- semester
- enrollment_status (open/closed)
- opening_date
- closing_date
- announcement
- created_by
- created_at
- updated_at

**Related Processes:** 2.0

---

### D17: Chatbot FAQs
**Description:** Stores frequently asked questions and answers.

**Key Attributes:**
- id (Primary Key)
- question
- answer
- keywords
- category
- is_active
- view_count
- created_by
- created_at
- updated_at

**Related Processes:** 8.0

---

### D18: Chatbot History
**Description:** Stores chatbot interaction history.

**Key Attributes:**
- id (Primary Key)
- user_id (Foreign Key to Users)
- question
- answer
- faq_id
- was_helpful
- created_at

**Related Processes:** 8.0

---

### D19: Enrollment Reports
**Description:** Stores generated enrollment reports.

**Key Attributes:**
- id (Primary Key)
- report_title
- program_code
- academic_year
- semester
- generated_by
- report_data
- report_file
- status (pending/acknowledged)
- reviewed_by
- reviewed_at
- dean_comment
- generated_at

**Related Processes:** 7.0

---

## Data Flows

### Student-Related Data Flows

#### Student Registration Data
**Description:** Information provided by student during registration.

**Components:**
- first_name
- last_name
- middle_name
- email
- password
- phone
- date_of_birth
- address
- lrn
- occ_examinee_number
- sex_at_birth
- age
- civil_status
- family information

**Source:** Student  
**Destination:** Process 1.1 (Register User)

---

#### Application Data
**Description:** Information provided by student during application submission.

**Components:**
- user_id
- program_id
- personal information
- educational background
- family information

**Source:** Student  
**Destination:** Process 2.1 (Submit Application)

---

#### Document Uploads
**Description:** Scanned document files uploaded by students.

**Components:**
- file (PDF, JPG, PNG)
- document_type
- file_name
- file_size

**Source:** Student  
**Destination:** Process 6.1 (Upload Document)

---

#### Enrollment Request
**Description:** Student's enrollment request for next semester.

**Components:**
- user_id
- academic_year
- semester
- selected_subjects
- preferred_section
- pre_enrollment_form_data

**Source:** Student  
**Destination:** Process 4.1 (Submit Enrollment Request)

---

#### Login Credentials
**Description:** User authentication credentials.

**Components:**
- email
- password

**Source:** All User Types  
**Destination:** Process 1.2 (Authenticate User)

---

#### Account Status
**Description:** Current status of student account.

**Components:**
- status (pending/active/inactive)
- enrollment_status (enrolled/pending)
- approval_status

**Source:** Process 1.0 (User Management)  
**Destination:** Student

---

#### Student Schedule
**Description:** Student's class schedule information.

**Components:**
- course_code
- course_name
- schedule (days, times)
- room
- professor_name
- section_name

**Source:** Process 4.0 (Enrollment Processing)  
**Destination:** Student

---

#### Student Grades
**Description:** Student's academic grades.

**Components:**
- course_code
- course_name
- grade
- remarks
- academic_year
- semester

**Source:** Process 5.0 (Grade Management)  
**Destination:** Student

---

#### Student COR
**Description:** Certificate of Registration document.

**Components:**
- student information
- academic_year
- semester
- enrolled subjects
- total_units
- registration_date

**Source:** Process 4.5/7.3 (Generate COR)  
**Destination:** Student

---

#### Enrollment Status
**Description:** Current enrollment status of student.

**Components:**
- enrollment_status
- application_status
- verification_status

**Source:** Process 2.0 (Application Processing), Process 4.0 (Enrollment Processing)  
**Destination:** Student

---

#### Query
**Description:** Student query to chatbot.

**Components:**
- question text
- user_id

**Source:** Student  
**Destination:** Process 8.1 (Process Query)

---

#### Chatbot Response
**Description:** Response from chatbot system.

**Components:**
- answer text
- faq_id (if matched)
- related_questions

**Source:** Process 8.0 (Chatbot Service)  
**Destination:** Student

---

### Admin-Related Data Flows

#### User Management Commands
**Description:** Commands from admin to manage users.

**Components:**
- action (approve/reject/update)
- user_id
- status
- comments

**Source:** Admin  
**Destination:** Process 1.3, 1.4 (User Management)

---

#### Curriculum Management
**Description:** Admin actions for curriculum management.

**Components:**
- action (add/edit/delete)
- curriculum_data
- prerequisites

**Source:** Admin  
**Destination:** Process 3.2 (Review Curriculum)

---

#### Section Management
**Description:** Admin actions for section management.

**Components:**
- action (create/update/assign)
- section_data
- user_id
- section_id

**Source:** Admin  
**Destination:** Process 4.3 (Assign to Section)

---

#### Schedule Management
**Description:** Admin actions for schedule management.

**Components:**
- action (create/update/delete)
- schedule_data
- section_id

**Source:** Admin  
**Destination:** Process 4.4 (Create Schedule)

---

#### Enrollment Approval
**Description:** Admin approval/rejection of enrollment requests.

**Components:**
- action (approve/reject)
- enrollment_request_id
- section_id
- comments

**Source:** Admin  
**Destination:** Process 4.2 (Review Enrollment Request)

---

#### Grade Entry
**Description:** Grade data entered by admin.

**Components:**
- user_id
- course_code
- grade
- remarks
- academic_year
- semester

**Source:** Admin  
**Destination:** Process 5.1, 5.2 (Grade Management)

---

#### COR Generation Request
**Description:** Request to generate Certificate of Registration.

**Components:**
- user_id
- academic_year
- semester

**Source:** Admin  
**Destination:** Process 4.5/7.3 (Generate COR)

---

#### User List
**Description:** List of all users in the system.

**Components:**
- user records
- status information
- enrollment information

**Source:** Process 1.0 (User Management)  
**Destination:** Admin

---

#### Enrollment Reports
**Description:** Generated enrollment reports.

**Components:**
- report_data
- statistics
- program information
- enrollment trends

**Source:** Process 7.0 (Reporting)  
**Destination:** Admin, Dean

---

#### Curriculum Data
**Description:** Curriculum information.

**Components:**
- course records
- program information
- prerequisites

**Source:** Process 3.0 (Curriculum Management)  
**Destination:** Admin

---

#### Section Information
**Description:** Section and enrollment information.

**Components:**
- section records
- enrollment counts
- student lists

**Source:** Process 4.0 (Enrollment Processing)  
**Destination:** Admin

---

#### Grade Reports
**Description:** Grade reports and statistics.

**Components:**
- grade records
- statistics
- performance data

**Source:** Process 5.0 (Grade Management)  
**Destination:** Admin

---

### Admission-Related Data Flows

#### Document Verification
**Description:** Document verification decision from admission.

**Components:**
- document_id
- verification_status (verified/rejected)
- rejection_reason

**Source:** Admission Officer  
**Destination:** Process 2.2, 6.2 (Document Verification)

---

#### Student Number Assignment
**Description:** Assignment of student number to applicant.

**Components:**
- user_id
- student_number

**Source:** Admission Officer  
**Destination:** Process 2.3 (Assign Student Number)

---

#### Enrollment Control
**Description:** Enrollment period control settings.

**Components:**
- academic_year
- semester
- enrollment_status (open/closed)
- opening_date
- closing_date
- announcement

**Source:** Admission Officer  
**Destination:** Process 2.0 (Application Processing)

---

#### Applicant Processing
**Description:** Actions on applicant records.

**Components:**
- action (verify/pass_to_registrar)
- applicant_id
- comments

**Source:** Admission Officer  
**Destination:** Process 2.4 (Pass to Registrar)

---

#### Applicant List
**Description:** List of applicants with status.

**Components:**
- applicant records
- verification status
- document status

**Source:** Process 2.0 (Application Processing)  
**Destination:** Admission Officer

---

#### Document Status
**Description:** Status of document verification.

**Components:**
- document_id
- verification_status
- verified_by
- verified_at
- rejection_reason

**Source:** Process 6.0 (Document Management)  
**Destination:** Admission Officer

---

#### Enrollment Control Status
**Description:** Current enrollment control status.

**Components:**
- enrollment_status
- opening_date
- closing_date
- announcement

**Source:** Process 2.0 (Application Processing)  
**Destination:** Admission Officer

---

### Program Head-Related Data Flows

#### Curriculum Submission
**Description:** Curriculum proposal from program head.

**Components:**
- program_id
- submission_title
- submission_description
- academic_year
- semester
- curriculum_items

**Source:** Program Head  
**Destination:** Process 3.1 (Submit Curriculum)

---

#### Curriculum Submission Status
**Description:** Status of curriculum submission.

**Components:**
- submission_id
- status (pending/approved/rejected)
- reviewer_comments
- admin_approved
- dean_approved

**Source:** Process 3.0 (Curriculum Management)  
**Destination:** Program Head

---

#### Program Data
**Description:** Program-specific information.

**Components:**
- program_id
- program_name
- program_code
- curriculum information

**Source:** Process 1.0 (User Management)  
**Destination:** Program Head

---

### Dean-Related Data Flows

#### Curriculum Approval
**Description:** Dean's approval/rejection of curriculum.

**Components:**
- submission_id
- action (approve/reject)
- notes

**Source:** Dean  
**Destination:** Process 3.3 (Approve Curriculum)

---

#### Curriculum Submissions
**Description:** Curriculum submissions pending dean approval.

**Components:**
- submission records
- program information
- submission_items
- admin_review_status

**Source:** Process 3.0 (Curriculum Management)  
**Destination:** Dean

---

### Registrar Staff-Related Data Flows

#### Enrollment Assistance
**Description:** Enrollment assistance actions from registrar staff.

**Components:**
- action
- enrollment_data
- user_id

**Source:** Registrar Staff  
**Destination:** Process 4.0 (Enrollment Processing)

---

#### Enrollment Data
**Description:** Enrollment information for registrar staff.

**Components:**
- enrollment records
- student information
- section information

**Source:** Process 4.0 (Enrollment Processing)  
**Destination:** Registrar Staff

---

## Notes

- All data flows represent logical data, not physical implementation
- Data stores use standard database table structures
- Data flows may contain multiple data elements
- Some data flows are bidirectional (e.g., query/response patterns)
- Control flows (triggers) are shown with dotted lines in Level 1 DFDs




