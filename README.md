# OCC Enrollment System

A comprehensive student enrollment system built with PHP and MySQL, featuring a modern responsive interface and complete course management functionality.

## Features

### Student Features
- **User Registration & Login**: Secure account creation with email validation
- **Course Browsing**: View available courses with detailed information
- **Course Enrollment**: Enroll in courses with automatic waitlist management
- **Enrollment Management**: View enrolled courses and drop courses
- **Dashboard**: Overview of enrollment status and statistics

### Admin Features
- **User Management**: Approve/suspend student accounts
- **Course Management**: Add, edit, and manage courses
- **Enrollment Monitoring**: View all enrollments and statistics
- **Department Management**: Organize courses by departments
- **Capacity Management**: Automatic waitlist when courses are full

### Technical Features
- **Responsive Design**: Modern Bootstrap-based UI that works on all devices
- **Security**: Password hashing, SQL injection prevention, input sanitization
- **Database Design**: Normalized database with proper relationships
- **Session Management**: Secure user sessions with role-based access
- **Automatic Waitlist**: Smart enrollment management with capacity limits

## Installation

### Prerequisites
- PHP 7.4 or higher
- MySQL 5.7 or higher
- Web server (Apache/Nginx) or XAMPP for local development

### Setup Instructions

1. **Clone/Download the project** to your web server directory:
   ```
   c:\xampp\htdocs\enrollment_occ\
   ```

2. **Create the database**:
   - Open phpMyAdmin or MySQL command line
   - Import the database schema from `database/schema.sql`
   - Or run the SQL commands directly:
     ```sql
     CREATE DATABASE enrollment_occ;
     ```

3. **Configure database connection**:
   - Edit `config/database.php`
   - Update the database credentials:
     ```php
     private $host = 'localhost';
     private $db_name = 'enrollment_occ';
     private $username = 'root';     // Your MySQL username
     private $password = '';         // Your MySQL password
     ```

4. **Set up the database tables**:
   - Run the SQL script in `database/schema.sql` to create all tables and sample data

5. **Access the application**:
   - Navigate to `http://localhost/enrollment_occ/public/`
   - You'll be redirected to the login page

## Default Accounts

### Administrator Account
- **Email**: admin@occ.edu
- **Password**: admin123
- **Role**: Administrator (can manage users and courses)

### Test Student Account
You can register a new student account through the registration page, or create one manually for testing.

## File Structure

```
enrollment_occ/
├── admin/                 # Admin panel files
│   ├── dashboard.php      # Admin dashboard
│   ├── add_course.php     # Add new courses
│   └── update_user.php    # Update user status
├── classes/               # PHP classes
│   ├── User.php          # User management
│   ├── Course.php        # Course management
│   └── Enrollment.php    # Enrollment management
├── config/               # Configuration files
│   └── database.php      # Database config & helper functions
├── database/             # Database files
│   └── schema.sql        # Database schema and sample data
├── public/               # Public access files
│   ├── index.php         # Main entry point
│   ├── login.php         # Login page
│   └── register.php      # Registration page
├── student/              # Student portal files
│   ├── dashboard.php     # Student dashboard
│   ├── enroll.php        # Enrollment handler
│   ├── drop.php          # Drop course handler
│   └── logout.php        # Logout handler
└── README.md            # This file
```

## Usage Guide

### For Students

1. **Registration**:
   - Go to the registration page
   - Fill in all required information
   - Wait for admin approval (account status will be "pending")

2. **Course Enrollment**:
   - Browse available courses in the "Available Courses" section
   - View course details including schedule, instructor, and prerequisites
   - Click "Enroll" to register for a course
   - If the course is full, you'll be added to the waitlist

3. **Managing Enrollments**:
   - View your enrolled courses in "My Enrollments"
   - Drop courses if needed (this may promote waitlisted students)
   - Monitor your enrollment status (Enrolled/Waitlisted)

### For Administrators

1. **User Management**:
   - Approve pending student registrations
   - Suspend or reactivate student accounts
   - View all student information

2. **Course Management**:
   - Add new courses with all details
   - Set course capacity and prerequisites
   - Assign instructors and schedules
   - Monitor enrollment numbers

3. **System Monitoring**:
   - View dashboard statistics
   - Monitor enrollment trends
   - Track course capacity utilization

## Database Schema

### Main Tables
- **users**: Student and admin accounts
- **departments**: Academic departments
- **courses**: Course information and details
- **enrollments**: Student course enrollments
- **enrollment_history**: Audit trail for enrollment changes

### Key Relationships
- Courses belong to departments
- Enrollments link users to courses
- Automatic capacity management with waitlist functionality

## Security Features

- **Password Hashing**: All passwords are hashed using PHP's password_hash()
- **SQL Injection Prevention**: All queries use prepared statements
- **Input Sanitization**: All user input is sanitized
- **Session Security**: Secure session management with role-based access
- **CSRF Protection**: Built-in protection against cross-site request forgery

## Customization

### Adding New Departments
1. Insert into the `departments` table via admin panel or SQL
2. Departments will automatically appear in course creation

### Modifying Course Fields
1. Update the database schema in `database/schema.sql`
2. Modify the Course class in `classes/Course.php`
3. Update the admin forms in `admin/dashboard.php`

### Styling Changes
The system uses Bootstrap 5 with custom CSS. Modify the `<style>` sections in the HTML files to customize the appearance.

## Troubleshooting

### Common Issues

1. **Database Connection Error**:
   - Check database credentials in `config/database.php`
   - Ensure MySQL service is running
   - Verify database exists

2. **Login Issues**:
   - Check if student account is approved (status = 'active')
   - Verify email and password are correct
   - Ensure session is working properly

3. **Enrollment Problems**:
   - Check course capacity limits
   - Verify student account is active
   - Check for duplicate enrollments

4. **Permission Errors**:
   - Ensure web server has read/write permissions
   - Check PHP error logs for detailed messages

## Support

For technical support or questions about the enrollment system:
1. Check the troubleshooting section above
2. Review PHP error logs
3. Verify database connectivity and schema
4. Ensure all file permissions are correct

## Future Enhancements

Potential features that could be added:
- Email notifications for enrollment status
- Grade management system
- Course prerequisites validation
- Payment integration
- Academic calendar integration
- Advanced reporting features
- Mobile app support

---

**Note**: This system was designed for educational purposes and includes comprehensive functionality for course enrollment management. Make sure to test thoroughly before deploying in a production environment.
