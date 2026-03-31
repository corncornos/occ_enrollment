<?php
try {
    $pdo = new PDO('mysql:host=localhost;dbname=enrollment_occ', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql = file_get_contents('database/create_program_head_system.sql');
    $pdo->exec($sql);

    echo 'Program Head system migration completed successfully!';
} catch (PDOException $e) {
    echo 'Migration failed: ' . $e->getMessage();
}
?>
