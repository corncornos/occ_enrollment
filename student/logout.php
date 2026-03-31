<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/User.php';

$user = new User();
$user->logout();

redirect('public/login.php');
?>
