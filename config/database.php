<?php
// Database configuration
class Database {
    private $host = 'localhost';
    private $db_name = 'enrollment_occ';
    private $username = 'root';  // Change this to your MySQL username
    private $password = '';      // Change this to your MySQL password
    private $conn;
    
    public function getConnection() {
        $this->conn = null;
        
        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name, 
                                $this->username, $this->password);
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } catch(PDOException $exception) {
            echo "Connection error: " . $exception->getMessage();
        }
        
        return $this->conn;
    }
}

// Session configuration
// Only configure and start session if one isn't already active
if (session_status() === PHP_SESSION_NONE) {
    // Enable multiple concurrent sessions in the same browser
    ini_set('session.use_only_cookies', 0);
    ini_set('session.use_trans_sid', 1);

    // Helper: validate that a session ID only contains allowed characters
    $is_valid_session_id = static function (string $id): bool {
        // Only allow A-Z, a-z, 0-9, "-", and "," (same rule as PHP warning)
        return (bool) preg_match('/^[A-Za-z0-9,-]+$/', $id);
    };

    // First, validate any existing session ID coming from cookies
    $cookie_session_id = $_COOKIE[session_name()] ?? '';
    if (!empty($cookie_session_id) && !$is_valid_session_id($cookie_session_id)) {
        // Invalid cookie session id → clear it so PHP doesn't warn on session_start
        setcookie(session_name(), '', time() - 3600, '/');
        unset($_COOKIE[session_name()]);
    }

    // Next, check if a session ID is passed via URL or POST (for multi-session support)
    if (isset($_GET['session_id']) && !empty($_GET['session_id'])) {
        $session_id = $_GET['session_id'];
        if ($is_valid_session_id($session_id)) {
            session_id($session_id);
        } else {
            error_log('Invalid session ID format detected in GET: ' . substr($session_id, 0, 50));
            // Do NOT call session_regenerate_id() before session_start; just ignore invalid ID
        }
    } elseif (isset($_POST['session_id']) && !empty($_POST['session_id'])) {
        $session_id = $_POST['session_id'];
        if ($is_valid_session_id($session_id)) {
            session_id($session_id);
        } else {
            error_log('Invalid session ID format detected in POST: ' . substr($session_id, 0, 50));
            // Ignore invalid ID; session_start() will create a fresh one
        }
    }

    session_start();
}

// Store the current session ID for passing in URLs
define('CURRENT_SESSION_ID', session_id());

// Define constants
define('BASE_URL', 'http://localhost/enrollment_occ/');
define('SITE_NAME', 'OCC Enrollment System');

// Helper functions
function redirect($location) {
    // Add session ID to URL for multi-session support
    $separator = (strpos($location, '?') !== false) ? '&' : '?';
    $url = BASE_URL . $location . $separator . 'session_id=' . CURRENT_SESSION_ID;
    header("Location: " . $url);
    exit();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function isAdmin() {
    // Check both role and is_admin flag
    // Accept both 'admin' and 'registrar' roles
    return (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) ||
           (isset($_SESSION['role']) && in_array($_SESSION['role'], ['admin', 'registrar']));
}

function isAdmission() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'admission';
}

function isProgramHead() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'program_head';
}

function isRegistrarStaff() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'registrar_staff';
}

function isDean() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'dean';
}

function isStudent() {
    return isset($_SESSION['role']) && $_SESSION['role'] === 'student';
}

function sanitizeInput($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

function generateStudentId() {
    return 'STU' . date('Y') . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
}

function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}
?>
