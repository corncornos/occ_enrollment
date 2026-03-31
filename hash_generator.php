<?php
// Generate correct bcrypt hash for "programhead123"
$password = 'programhead123';
$hash = password_hash($password, PASSWORD_DEFAULT);

echo "<h2>Password Hash Generator</h2>";
echo "<p><strong>Password:</strong> $password</p>";
echo "<p><strong>Hash:</strong> <code>$hash</code></p>";
echo "<p><strong>Verification:</strong> " . (password_verify($password, $hash) ? 'Valid' : 'Invalid') . "</p>";
echo "<hr>";
echo "<p>Use this hash in your SQL migration script.</p>";
?>
