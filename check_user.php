<!DOCTYPE html>
<html>
<head>
    <title>User Status Checker</title>
    <style>
        body { font-family: Arial, sans-serif; max-width: 800px; margin: 50px auto; padding: 20px; }
        .success { color: green; background: #d4edda; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .error { color: #721c24; background: #f8d7da; padding: 15px; border-radius: 5px; margin: 10px 0; }
        .info { color: #004085; background: #cce5ff; padding: 15px; border-radius: 5px; margin: 10px 0; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
        th { background-color: #667eea; color: white; }
        .status-active { color: green; font-weight: bold; }
        .status-pending { color: orange; font-weight: bold; }
        .status-inactive { color: red; font-weight: bold; }
        form { margin: 20px 0; padding: 20px; background: #f9f9f9; border-radius: 5px; }
        input[type="email"] { padding: 8px; width: 300px; margin-right: 10px; }
        button { padding: 8px 20px; background: #667eea; color: white; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #5568d3; }
    </style>
</head>
<body>
    <h1>🔍 User Status Checker</h1>
    
    <?php
    require_once 'config/database.php';
    
    $db = new Database();
    $conn = $db->getConnection();
    
    // Check specific user if email provided
    if (isset($_GET['email']) && !empty($_GET['email'])) {
        $email = $_GET['email'];
        $stmt = $conn->prepare('SELECT id, student_id, first_name, last_name, email, status, enrollment_status, created_at FROM users WHERE email = :email');
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo "<h2>Search Results for: " . htmlspecialchars($email) . "</h2>";
        
        if ($user) {
            $status_class = 'status-' . $user['status'];
            echo "<div class='success'>";
            echo "<h3>✅ User Found!</h3>";
            echo "<p><strong>Student ID:</strong> {$user['student_id']}</p>";
            echo "<p><strong>Name:</strong> {$user['first_name']} {$user['last_name']}</p>";
            echo "<p><strong>Email:</strong> {$user['email']}</p>";
            echo "<p><strong>Account Status:</strong> <span class='$status_class'>" . strtoupper($user['status']) . "</span></p>";
            echo "<p><strong>Enrollment Status:</strong> " . strtoupper($user['enrollment_status']) . "</p>";
            echo "<p><strong>Created:</strong> " . date('F j, Y g:i A', strtotime($user['created_at'])) . "</p>";
            echo "</div>";
            
            if ($user['status'] !== 'active') {
                echo "<div class='error'>";
                echo "<h3>⚠️ Login Issue Detected</h3>";
                echo "<p>This user's status is '<strong>{$user['status']}</strong>' but needs to be '<strong>active</strong>' to log in.</p>";
                echo "<p><strong>Solution:</strong> Run the SQL script at <code>database/fix_user_status.sql</code> in phpMyAdmin to activate all users.</p>";
                echo "</div>";
            } else {
                echo "<div class='success'>";
                echo "<h3>✅ Status is ACTIVE - User can log in!</h3>";
                echo "<p>If login still fails, please verify the password is correct.</p>";
                echo "</div>";
            }
        } else {
            echo "<div class='error'>";
            echo "<h3>❌ User Not Found</h3>";
            echo "<p>No user exists with email: <strong>" . htmlspecialchars($email) . "</strong></p>";
            echo "<p><strong>Solution:</strong> This user needs to register first at <a href='public/register.php'>public/register.php</a></p>";
            echo "</div>";
        }
    }
    ?>
    
    <form method="GET">
        <h3>Check User Status</h3>
        <input type="email" name="email" placeholder="Enter email address" required value="<?php echo isset($_GET['email']) ? htmlspecialchars($_GET['email']) : ''; ?>">
        <button type="submit">Check Status</button>
    </form>
    
    <h2>Recent Users (Last 10)</h2>
    <table>
        <thead>
            <tr>
                <th>Student ID</th>
                <th>Name</th>
                <th>Email</th>
                <th>Status</th>
                <th>Enrollment</th>
                <th>Registered</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $stmt = $conn->query('SELECT id, student_id, first_name, last_name, email, status, enrollment_status, created_at FROM users ORDER BY created_at DESC LIMIT 10');
            $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($users)) {
                echo "<tr><td colspan='6' style='text-align:center;'>No users found. Register the first user!</td></tr>";
            } else {
                foreach ($users as $u) {
                    $status_class = 'status-' . $u['status'];
                    echo "<tr>";
                    echo "<td>{$u['student_id']}</td>";
                    echo "<td>{$u['first_name']} {$u['last_name']}</td>";
                    echo "<td>{$u['email']}</td>";
                    echo "<td class='$status_class'>" . strtoupper($u['status']) . "</td>";
                    echo "<td>" . strtoupper($u['enrollment_status']) . "</td>";
                    echo "<td>" . date('M j, Y', strtotime($u['created_at'])) . "</td>";
                    echo "</tr>";
                }
            }
            ?>
        </tbody>
    </table>
    
    <div class="info">
        <h3>ℹ️ Quick Tips</h3>
        <ul>
            <li><strong>Active Status:</strong> User can log in ✅</li>
            <li><strong>Pending Status:</strong> User cannot log in (needs activation) ⏳</li>
            <li><strong>Inactive Status:</strong> User account is disabled ❌</li>
        </ul>
        <p>To activate all pending users, run: <code>database/fix_user_status.sql</code> in phpMyAdmin</p>
    </div>
    
    <p><a href="public/login.php">← Back to Login</a> | <a href="public/register.php">Register New User</a></p>
</body>
</html>

