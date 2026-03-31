<?php
require_once 'config/database.php';

echo '<h2>Fix Admin Password</h2>';

echo '<pre>';
try {
     = new Database();
     = ->getConnection();

     = password_hash('admin123', PASSWORD_DEFAULT);

     = ->prepare("UPDATE admins SET password = :password WHERE admin_id = 'ADMIN001'");
    ->bindParam(':password', );
    ->execute();

    echo "Password reset successful.\n";
    echo "New credentials:\n";
    echo "Email: admin@occ.edu.ph\n";
    echo "Password: admin123\n";
} catch (Exception ) {
    echo "Error: " . ->getMessage();
}

echo '</pre>';
?>
