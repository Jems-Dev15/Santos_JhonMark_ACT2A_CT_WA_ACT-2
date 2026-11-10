
<?php
session_start();
require "db.php";

if (isset($_SESSION["user_id"])) {
    header("Location: dashboard.php");
    exit;
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user["password"])) {
        session_regenerate_id(true);
        $_SESSION["user_id"] = $user["id"];
        $_SESSION["full_name"] = $user["full_name"];

        header("Location: dashboard.php");
        exit;
    } else {
        $error = "Invalid username or password.";
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

<div class="container">
    <form class="form-box" method="POST">
        <h1>Welcome Back</h1>
        <p class="subtitle">Sign in to your account</p>

        <?php if ($error): ?>
            <p class="error">
                <?= htmlspecialchars($error) ?>
            </p>
        <?php endif; ?>

        <label>Username</label>
        <input type="text" name="username"
               placeholder="Enter your username"
               autocomplete="username" required>

        <label>Password</label>
        <input type="password" name="password"
               placeholder="Enter your password"
               autocomplete="current-password" required>

        <button type="submit">Login</button>

        <p class="switch">
            Don't have an account?
            <a href="signup.php">Sign Up</a>
        </p>
    </form>
</div>

</body>
</html>
