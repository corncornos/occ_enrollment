<?php
require_once 'config/database.php';
require_once 'classes/ProgramHead.php';

echo "<h1>Program Head Login Test</h1>";

// Test each program head account
$programHeads = [
    ['email' => 'bse.head@occ.edu', 'password' => 'programhead123'],
    ['email' => 'btvted.head@occ.edu', 'password' => 'programhead123'],
    ['email' => 'bsis.head@occ.edu', 'password' => 'programhead123']
];

$programHead = new ProgramHead();

foreach ($programHeads as $ph) {
    echo "<h3>Testing: {$ph['email']}</h3>";

    $result = $programHead->login($ph['email'], $ph['password']);

    if ($result['success']) {
        echo "<p style='color: green;'>✅ LOGIN SUCCESSFUL</p>";
        echo "<p>User: {$result['user']['first_name']} {$result['user']['last_name']}</p>";
        echo "<p>Program: {$result['user']['program_name']} ({$result['user']['program_code']})</p>";
    } else {
        echo "<p style='color: red;'>❌ LOGIN FAILED: {$result['message']}</p>";
    }

    echo "<hr>";
}

echo "<h2>Database Check</h2>";

// Check database directly
$db = new Database();
$conn = $db->getConnection();

$stmt = $conn->prepare("SELECT email, password FROM program_heads");
$stmt->execute();
$accounts = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($accounts as $account) {
    $passwordValid = password_verify('programhead123', $account['password']);
    echo "<p>{$account['email']}: " . ($passwordValid ? 'VALID PASSWORD' : 'INVALID PASSWORD') . "</p>";
}
?>
