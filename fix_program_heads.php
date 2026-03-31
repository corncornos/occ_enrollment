<?php
require_once 'config/database.php';

try {
    $db = new Database();
    $conn = $db->getConnection();

    // Check if program_heads table exists
    $result = $conn->query("SHOW TABLES LIKE 'program_heads'");
    if ($result->rowCount() == 0) {
        echo "Program heads table doesn't exist. Please run the migration first.<br>";
        echo "Go to phpMyAdmin and run the contents of: database/create_program_head_system.sql<br>";
        exit;
    }

    // Check if program heads exist
    $stmt = $conn->prepare("SELECT COUNT(*) as count FROM program_heads");
    $stmt->execute();
    $count = $stmt->fetch()['count'];

    if ($count == 0) {
        echo "No program heads found. Creating them now...<br>";

        // Generate correct password hash
        $password = 'programhead123';
        $hash = password_hash($password, PASSWORD_DEFAULT);

        echo "Generated password hash: $hash<br>";
        echo "Password verification: " . (password_verify($password, $hash) ? 'VALID' : 'INVALID') . "<br><br>";

        // Insert program heads with correct hash
        $programHeads = [
            ['username' => 'bse_head', 'email' => 'bse.head@occ.edu', 'first_name' => 'Maria', 'last_name' => 'Santos', 'program_id' => 1],
            ['username' => 'btvted_head', 'email' => 'btvted.head@occ.edu', 'first_name' => 'Juan', 'last_name' => 'Dela Cruz', 'program_id' => 2],
            ['username' => 'bsis_head', 'email' => 'bsis.head@occ.edu', 'first_name' => 'Ana', 'last_name' => 'Reyes', 'program_id' => 3]
        ];

        foreach ($programHeads as $ph) {
            $stmt = $conn->prepare("INSERT INTO program_heads (username, email, password, first_name, last_name, program_id, status) VALUES (?, ?, ?, ?, ?, ?, 'active')");
            $stmt->execute([
                $ph['username'],
                $ph['email'],
                $hash,
                $ph['first_name'],
                $ph['last_name'],
                $ph['program_id']
            ]);
            echo "Created: {$ph['email']} / programhead123<br>";
        }

        echo "<br><strong>Program Head accounts created successfully!</strong><br>";
        echo "You can now login with:<br>";
        echo "- BSE: bse.head@occ.edu / programhead123<br>";
        echo "- BTVTED: btvted.head@occ.edu / programhead123<br>";
        echo "- BSIS: bsis.head@occ.edu / programhead123<br>";

    } else {
        echo "Program heads already exist ($count found).<br>";

        // Check if passwords are working
        $stmt = $conn->prepare("SELECT email, password FROM program_heads LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();

        $testPassword = password_verify('programhead123', $row['password']);
        echo "Password test for {$row['email']}: " . ($testPassword ? 'WORKING' : 'NOT WORKING') . "<br>";

        if (!$testPassword) {
            echo "Fixing password hash...<br>";

            $correctHash = password_hash('programhead123', PASSWORD_DEFAULT);
            $stmt = $conn->prepare("UPDATE program_heads SET password = ?");
            $stmt->execute([$correctHash]);

            echo "Password hash updated. Try logging in again.<br>";
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "<br>";
}
?>
