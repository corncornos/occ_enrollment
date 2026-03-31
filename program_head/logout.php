<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/ProgramHead.php';

// Check if program head is logged in
if (isProgramHead()) {
    $programHead = new ProgramHead();
    $programHead->logout();
}

// Destroy session completely
session_destroy();

// Redirect to login page
redirect('public/login.php');
?>
