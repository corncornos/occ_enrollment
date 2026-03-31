<?php
require_once '../config/database.php';
require_once '../config/session_helper.php';
require_once '../classes/User.php';
require_once '../config/audit_helper.php';

// Check if user is logged in and is an admin
if (!isLoggedIn() || (!isAdmin() && !isRegistrarStaff())) {
    redirect('public/login.php');
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['user_id']) && isset($_POST['status'])) {
    $user = new User();
    $user_id = (int)$_POST['user_id'];
    $status = $_POST['status'];
    
    // Get old values for audit log
    $old_user = $user->getUserById($user_id);
    $old_status = $old_user['status'] ?? 'unknown';
    
    if ($user->updateUserStatus($user_id, $status)) {
        // Log the action
        logAdminAction(
            AUDIT_ACTION_USER_STATUS_UPDATE,
            "Updated user status for user ID {$user_id} from '{$old_status}' to '{$status}'",
            AUDIT_ENTITY_USER,
            $user_id,
            ['status' => $old_status],
            ['status' => $status],
            'success'
        );
        
        $_SESSION['message'] = 'User status updated successfully.';
    } else {
        // Log failed action
        logAdminAction(
            AUDIT_ACTION_USER_STATUS_UPDATE,
            "Failed to update user status for user ID {$user_id}",
            AUDIT_ENTITY_USER,
            $user_id,
            ['status' => $old_status],
            ['status' => $status],
            'failed',
            'Database update failed'
        );
        
        $_SESSION['message'] = 'Error updating user status.';
    }
}

redirect('admin/dashboard.php');
?>
