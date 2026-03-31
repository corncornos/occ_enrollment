<?php
require_once 'config/database.php';

$db = new Database();
$conn = $db->getConnection();

$stmt = $conn->query("SELECT ph.id, ph.username, ph.email, ph.first_name, ph.last_name, p.program_code, p.program_name 
                      FROM program_heads ph 
                      JOIN programs p ON ph.program_id = p.id 
                      ORDER BY p.program_code");
$heads = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "All Program Head Accounts:\n";
echo str_repeat("=", 80) . "\n";
foreach($heads as $h) {
    echo sprintf("%-10s %-25s %-20s %s\n", 
        $h['program_code'], 
        $h['email'], 
        $h['first_name'] . ' ' . $h['last_name'],
        $h['username']
    );
}
echo str_repeat("=", 80) . "\n";
echo "Total: " . count($heads) . " program head(s)\n";
?>


