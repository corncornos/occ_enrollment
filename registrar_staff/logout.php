<?php
// Handle session ID from URL if present (for multi-session support)
if (isset($_GET['session_id']) && !empty($_GET['session_id'])) {
    session_id($_GET['session_id']);
} elseif (isset($_POST['session_id']) && !empty($_POST['session_id'])) {
    session_id($_POST['session_id']);
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Unset all session variables
$_SESSION = array();

// Destroy the session cookie
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time()-3600, '/');
}

// Destroy the session
session_destroy();

// Redirect to login page (without session ID)
header('Location: ../public/login.php');
exit();
?>

