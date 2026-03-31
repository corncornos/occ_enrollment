<?php
// Generate correct bcrypt hash for "programhead123"
$password = 'programhead123';
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "Password: $password\n";
echo "Hash: $hash\n";
echo "\nUse this hash in the SQL migration script.\n";
?>
