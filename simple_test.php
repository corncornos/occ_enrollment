<!DOCTYPE html>
<html>
<head>
    <title>Simple Form Test</title>
</head>
<body>
    <h1>Simple Form Test</h1>

    <?php
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        echo "<h2>Form Submitted Successfully!</h2>";
        echo "Email: " . ($_POST['email'] ?? 'not set') . "<br>";
        echo "Password: " . ($_POST['password'] ?? 'not set') . "<br>";
        echo "REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD'] . "<br>";
    } else {
        echo "<h2>Form Not Submitted Yet</h2>";
        echo "REQUEST_METHOD: " . $_SERVER['REQUEST_METHOD'] . "<br>";
    }
    ?>

    <form method="POST">
        Email: <input type="email" name="email" required><br>
        Password: <input type="password" name="password" required><br>
        <button type="submit">Submit</button>
    </form>
</body>
</html>
