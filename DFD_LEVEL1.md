# Data Flow Diagram - Level 1 DFDs

## Overview
Level 1 DFDs provide detailed breakdowns of each major process from the Level 0 DFD, showing sub-processes and their interactions with data stores and external entities.

---

## 1.0 User Management (Level 1)

### Sub-Processes
- **1.1 Register User** - Creates new user accounts (students)
- **1.2 Authenticate User** - Validates login credentials for all user types
- **1.3 Approve User Account** - Admin approves/rejects pending student accounts
- **1.4 Update User Status** - Admin updates user account status (active/inactive)

### Data Flows
- **Inputs:**
  - Student Registration Data → 1.1
  - Login Credentials → 1.2 (from all user types)
  - User Management Commands → 1.3, 1.4
- **Outputs:**
  - Account Status → Student
  - User List → Admin
  - Authentication Result → All users

### Mermaid Diagram

```mermaid
graph TB
    Student[Student]
    Admin[Admin]
    AllUsers[All User Types]
    
    P11[1.1<br/>Register User]
    P12[1.2<br/>Authenticate User]
    P13[1.3<br/>Approve User Account]
    P14[1.4<br/>Update User Status]
    
    D1[("D1: Users")]
    D2[("D2: Admins")]
    D3[("D3: Admissions")]
    D4[("D4: Program Heads")]
    
    Student -->|Student Registration Data| P11
    AllUsers -->|Login Credentials| P12
    Admin -->|User Management Commands| P13
    Admin -->|User Management Commands| P14
    
    P11 -->|Account Status| Student
    P12 -->|Authentication Result| AllUsers
    P13 -->|User List| Admin
    P14 -->|User List| Admin
    
    P11 -->|Write| D1
    P12 -->|Read| D1
    P12 -->|Read| D2
    P12 -->|Read| D3
    P12 -->|Read| D4
    P13 -->|Read/Write| D1
    P14 -->|Read/Write| D1
    
    style P11 fill:#e3f2fd,stroke:#1976d2
    style P12 fill:#e3f2fd,stroke:#1976d2
    style P13 fill:#e3f2fd,stroke:#1976d2
    style P14 fill:#e3f2fd,stroke:#1976d2
```

---

## 2.0 Application Processing (Level 1)

### Sub-Processes
- **2.1 Submit Application** - Student submits enrollment application
- **2.2 Verify Documents** - Admission verifies uploaded documents
- **2.3 Assign Student Number** - Admission assigns unique student ID
- **2.4 Pass to Registrar** - Admission passes verified applicants to registrar

### Data Flows
- **Inputs:**
  - Application Data → 2.1
  - Document Verification → 2.2
  - Student Number Assignment → 2.3
  - Applicant Processing → 2.4
- **Outputs:**
  - Applicant List → Admission
  - Enrollment Status → Student
  - Enrollment Control Status → Admission

### Mermaid Diagram

```mermaid
graph TB
    Student[Student]
    Admission[Admission Officer]
    
    P21[2.1<br/>Submit Application]
    P22[2.2<br/>Verify Documents]
    P23[2.3<br/>Assign Student Number]
    P24[2.4<br/>Pass to Registrar]
    
    D1[("D1: Users")]
    D5[("D5: Applicants")]
    D13[("D13: Documents")]
    D16[("D16: Enrollment Control")]
    
    Student -->|Application Data| P21
    Admission -->|Document Verification| P22
    Admission -->|Student Number Assignment| P23
    Admission -->|Applicant Processing| P24
    
    P21 -->|Enrollment Status| Student
    P22 -->|Document Status| Admission
    P24 -->|Applicant List| Admission
    P24 -->|Enrollment Control Status| Admission
    
    P21 -->|Write| D5
    P21 -->|Read/Write| D1
    P22 -->|Read/Write| D13
    P23 -->|Read/Write| D1
    P23 -->|Read/Write| D5
    P24 -->|Read/Write| D5
    P24 -->|Read| D16
    P24 -->|Read/Write| D1
    
    style P21 fill:#e8f5e9,stroke:#388e3c
    style P22 fill:#e8f5e9,stroke:#388e3c
    style P23 fill:#e8f5e9,stroke:#388e3c
    style P24 fill:#e8f5e9,stroke:#388e3c
```

---

## 3.0 Curriculum Management (Level 1)

### Sub-Processes
- **3.1 Submit Curriculum** - Program Head submits curriculum proposals
- **3.2 Review Curriculum** - Admin reviews curriculum submissions
- **3.3 Approve Curriculum** - Dean approves/rejects curriculum submissions
- **3.4 Sync Approved Curriculum** - System syncs approved curriculum to main curriculum table

### Data Flows
- **Inputs:**
  - Curriculum Submission → 3.1
  - Curriculum Management → 3.2
  - Curriculum Approval → 3.3
- **Outputs:**
  - Curriculum Submission Status → Program Head
  - Curriculum Data → Admin
  - Curriculum Submissions → Dean

### Mermaid Diagram

```mermaid
graph TB
    ProgramHead[Program Head]
    Admin[Admin]
    Dean[Dean]
    
    P31[3.1<br/>Submit Curriculum]
    P32[3.2<br/>Review Curriculum]
    P33[3.3<br/>Approve Curriculum]
    P34[3.4<br/>Sync Approved Curriculum]
    
    D4[("D4: Program Heads")]
    D7[("D7: Curriculum")]
    D8[("D8: Curriculum Submissions")]
    D2[("D2: Admins")]
    
    ProgramHead -->|Curriculum Submission| P31
    Admin -->|Curriculum Management| P32
    Dean -->|Curriculum Approval| P33
    
    P31 -->|Curriculum Submission Status| ProgramHead
    P32 -->|Curriculum Data| Admin
    P33 -->|Curriculum Submissions| Dean
    
    P31 -->|Write| D8
    P31 -->|Read| D4
    P32 -->|Read/Write| D8
    P32 -->|Read| D2
    P33 -->|Read/Write| D8
    P34 -->|Read| D8
    P34 -->|Write| D7
    
    P32 -.->|Trigger| P34
    P33 -.->|Trigger| P34
    
    style P31 fill:#fff3e0,stroke:#f57c00
    style P32 fill:#fff3e0,stroke:#f57c00
    style P33 fill:#fff3e0,stroke:#f57c00
    style P34 fill:#fff3e0,stroke:#f57c00
```

---

## 4.0 Enrollment Processing (Level 1)

### Sub-Processes
- **4.1 Submit Enrollment Request** - Student submits enrollment request for next semester
- **4.2 Review Enrollment Request** - Admin/Registrar reviews enrollment requests
- **4.3 Assign to Section** - Admin assigns student to section
- **4.4 Create Schedule** - Admin creates section schedules
- **4.5 Generate COR** - System generates Certificate of Registration

### Data Flows
- **Inputs:**
  - Enrollment Request → 4.1
  - Section Management → 4.3
  - Schedule Management → 4.4
  - Enrollment Approval → 4.2
  - COR Generation Request → 4.5
- **Outputs:**
  - Student Schedule → Student
  - Section Information → Admin
  - Student COR → Student

### Mermaid Diagram

```mermaid
graph TB
    Student[Student]
    Admin[Admin]
    
    P41[4.1<br/>Submit Enrollment Request]
    P42[4.2<br/>Review Enrollment Request]
    P43[4.3<br/>Assign to Section]
    P44[4.4<br/>Create Schedule]
    P45[4.5<br/>Generate COR]
    
    D1[("D1: Users")]
    D6[("D6: Enrolled Students")]
    D7[("D7: Curriculum")]
    D9[("D9: Sections")]
    D10[("D10: Section Enrollments")]
    D11[("D11: Schedules")]
    D12[("D12: Student Schedules")]
    D14[("D14: Student Grades")]
    D15[("D15: Certificate of Registration")]
    
    Student -->|Enrollment Request| P41
    Admin -->|Enrollment Approval| P42
    Admin -->|Section Management| P43
    Admin -->|Schedule Management| P44
    Admin -->|COR Generation Request| P45
    
    P41 -->|Enrollment Status| Student
    P42 -->|Section Information| Admin
    P43 -->|Section Information| Admin
    P44 -->|Section Information| Admin
    P45 -->|Student COR| Student
    
    P41 -->|Write| D6
    P41 -->|Read| D1
    P41 -->|Read| D7
    P42 -->|Read/Write| D6
    P42 -->|Read| D14
    P43 -->|Read/Write| D9
    P43 -->|Read/Write| D10
    P43 -->|Read/Write| D6
    P44 -->|Read/Write| D11
    P44 -->|Read| D7
    P44 -->|Read| D9
    P45 -->|Read| D6
    P45 -->|Read| D10
    P45 -->|Read| D12
    P45 -->|Write| D15
    
    P43 -.->|Creates| P44
    P43 -.->|Triggers| P45
    
    style P41 fill:#f3e5f5,stroke:#7b1fa2
    style P42 fill:#f3e5f5,stroke:#7b1fa2
    style P43 fill:#f3e5f5,stroke:#7b1fa2
    style P44 fill:#f3e5f5,stroke:#7b1fa2
    style P45 fill:#f3e5f5,stroke:#7b1fa2
```

---

## 5.0 Grade Management (Level 1)

### Sub-Processes
- **5.1 Enter Grades** - Admin enters student grades
- **5.2 Verify Grades** - Admin verifies entered grades
- **5.3 View Grades** - Students and admins view grades

### Data Flows
- **Inputs:**
  - Grade Entry → 5.1, 5.2
- **Outputs:**
  - Student Grades → Student
  - Grade Reports → Admin

### Mermaid Diagram

```mermaid
graph TB
    Student[Student]
    Admin[Admin]
    
    P51[5.1<br/>Enter Grades]
    P52[5.2<br/>Verify Grades]
    P53[5.3<br/>View Grades]
    
    D1[("D1: Users")]
    D12[("D12: Student Schedules")]
    D14[("D14: Student Grades")]
    
    Admin -->|Grade Entry| P51
    Admin -->|Grade Entry| P52
    Student -->|View Request| P53
    Admin -->|View Request| P53
    
    P51 -->|Grade Reports| Admin
    P52 -->|Grade Reports| Admin
    P53 -->|Student Grades| Student
    P53 -->|Grade Reports| Admin
    
    P51 -->|Write| D14
    P51 -->|Read| D12
    P52 -->|Read/Write| D14
    P53 -->|Read| D14
    P53 -->|Read| D1
    P53 -->|Read| D12
    
    style P51 fill:#fce4ec,stroke:#c2185b
    style P52 fill:#fce4ec,stroke:#c2185b
    style P53 fill:#fce4ec,stroke:#c2185b
```

---

## 6.0 Document Management (Level 1)

### Sub-Processes
- **6.1 Upload Document** - Student uploads required documents
- **6.2 Verify Document** - Admission verifies uploaded documents
- **6.3 Update Document Status** - System updates document verification status

### Data Flows
- **Inputs:**
  - Document Uploads → 6.1
  - Document Verification → 6.2
- **Outputs:**
  - Document Status → Admission

### Mermaid Diagram

```mermaid
graph TB
    Student[Student]
    Admission[Admission Officer]
    
    P61[6.1<br/>Upload Document]
    P62[6.2<br/>Verify Document]
    P63[6.3<br/>Update Document Status]
    
    D1[("D1: Users")]
    D13[("D13: Documents")]
    
    Student -->|Document Uploads| P61
    Admission -->|Document Verification| P62
    
    P61 -->|Upload Confirmation| Student
    P62 -->|Document Status| Admission
    P63 -->|Document Status| Admission
    
    P61 -->|Write| D13
    P61 -->|Read| D1
    P62 -->|Read| D13
    P62 -.->|Trigger| P63
    P63 -->|Read/Write| D13
    
    style P61 fill:#e0f2f1,stroke:#00796b
    style P62 fill:#e0f2f1,stroke:#00796b
    style P63 fill:#e0f2f1,stroke:#00796b
```

---

## 7.0 Reporting (Level 1)

### Sub-Processes
- **7.1 Generate Enrollment Reports** - System generates various enrollment reports
- **7.2 View Reports** - Admins and Deans view generated reports
- **7.3 Generate COR** - System generates Certificate of Registration (also part of 4.5)

### Data Flows
- **Inputs:**
  - COR Generation Request → 7.3
  - Report Request → 7.1
- **Outputs:**
  - Enrollment Reports → Admin, Dean
  - Student COR → Student

### Mermaid Diagram

```mermaid
graph TB
    Student[Student]
    Admin[Admin]
    Dean[Dean]
    
    P71[7.1<br/>Generate Enrollment Reports]
    P72[7.2<br/>View Reports]
    P73[7.3<br/>Generate COR]
    
    D1[("D1: Users")]
    D6[("D6: Enrolled Students")]
    D9[("D9: Sections")]
    D10[("D10: Section Enrollments")]
    D15[("D15: Certificate of Registration")]
    D19[("D19: Enrollment Reports")]
    
    Admin -->|Report Request| P71
    Admin -->|COR Generation Request| P73
    Admin -->|View Request| P72
    Dean -->|View Request| P72
    
    P71 -->|Enrollment Reports| Admin
    P71 -->|Enrollment Reports| Dean
    P72 -->|Enrollment Reports| Admin
    P72 -->|Enrollment Reports| Dean
    P73 -->|Student COR| Student
    
    P71 -->|Read| D6
    P71 -->|Read| D9
    P71 -->|Read| D10
    P71 -->|Read| D1
    P71 -->|Write| D19
    P72 -->|Read| D19
    P73 -->|Read| D6
    P73 -->|Read| D10
    P73 -->|Read| D12
    P73 -->|Write| D15
    
    style P71 fill:#fff9c4,stroke:#f9a825
    style P72 fill:#fff9c4,stroke:#f9a825
    style P73 fill:#fff9c4,stroke:#f9a825
```

---

## 8.0 Chatbot Service (Level 1)

### Sub-Processes
- **8.1 Process Query** - System processes user query
- **8.2 Retrieve FAQ** - System retrieves matching FAQ
- **8.3 Log Interaction** - System logs chatbot interaction

### Data Flows
- **Inputs:**
  - Query → 8.1
- **Outputs:**
  - Chatbot Response → Student

### Mermaid Diagram

```mermaid
graph TB
    Student[Student]
    
    P81[8.1<br/>Process Query]
    P82[8.2<br/>Retrieve FAQ]
    P83[8.3<br/>Log Interaction]
    
    D17[("D17: Chatbot FAQs")]
    D18[("D18: Chatbot History")]
    
    Student -->|Query| P81
    
    P81 -->|Chatbot Response| Student
    
    P81 -.->|Trigger| P82
    P82 -->|Read| D17
    P82 -.->|Trigger| P83
    P83 -->|Write| D18
    
    style P81 fill:#e1bee7,stroke:#8e24aa
    style P82 fill:#e1bee7,stroke:#8e24aa
    style P83 fill:#e1bee7,stroke:#8e24aa
```

---

## Notes

- Level 1 DFDs show detailed sub-processes for each major process
- Dotted lines indicate control flows or triggers
- Each sub-process maintains the same data store relationships as its parent process
- Processes are numbered hierarchically (e.g., 1.1, 1.2 under process 1.0)
- All data flows are labeled with descriptive names




