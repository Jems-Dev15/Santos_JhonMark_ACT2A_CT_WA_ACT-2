<?php
session_start();

require __DIR__ . "/config.php";

if (isset($_SESSION["user_id"])) {
    header("Location: dashboard.php");
    exit;
}

$error = "";
$success = "";

if (isset($_GET["registered"]) && $_GET["registered"] === "1") {
    $success = "Account created successfully! Please log in.";
}

if (!isset($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $error = "Invalid request. Please refresh the page.";
    } else {
        $username = trim($_POST["username"] ?? "");
        $password = $_POST["password"] ?? "";

        if ($username === "" || $password === "") {
            $error = "Please enter your username and password.";
        } else {
            try {
                $pdo = getPDO();

                $stmt = $pdo->prepare(
                    "SELECT id, full_name, username, password, role
                     FROM users
                     WHERE username = ?
                     LIMIT 1"
                );

                $stmt->execute([$username]);
                $user = $stmt->fetch();

                if (
                    $user &&
                    password_verify($password, $user["password"])
                ) {
                    session_regenerate_id(true);
                    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

                    $_SESSION["user_id"] = $user["id"];
                    $_SESSION["full_name"] = $user["full_name"];
                    $_SESSION["username"] = $user["username"];
                    $_SESSION["role"] = $user["role"];

                    header("Location: dashboard.php");
                    exit;
                } else {
                    $error = "Incorrect username or password.";
                }

            } catch (PDOException $e) {
                error_log($e->getMessage());
                $error = "Database connection failed. Make sure MySQL is running and schema.sql was imported.";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Login | Attendance Monitoring System</title>

<style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: "Segoe UI", Arial, sans-serif;
}

:root {
    --green: #087f5b;
    --dark-green: #03422f;
    --text: #173c2d;
    --muted: #7b8981;
    --border: #dce7df;
}

body {
    min-height: 100vh;
    min-height: 100dvh;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 24px;
    background: linear-gradient(135deg, #052e23, #087f5b, #16a36f);
}

.container {
    width: 940px;
    max-width: 100%;
    min-height: 570px;
    display: flex;
    background: #ffffff;
    border-radius: 22px;
    overflow: hidden;
    box-shadow: 0 25px 65px rgba(0, 0, 0, 0.25);
    animation: fadeIn 0.4s ease;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(12px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }
}

/* Desktop welcome column */

.welcome {
    width: 43%;
    flex-shrink: 0;
    padding: 40px 30px;
    background: linear-gradient(145deg, #08734f, #03422f);
    color: #ffffff;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    text-align: center;
}

.logo {
    width: 82px;
    height: 82px;
    border-radius: 24px;
    background: #e0ffed;
    color: var(--green);
    display: flex;
    justify-content: center;
    align-items: center;
    font-size: 40px;
    font-weight: 800;
    margin-bottom: 25px;
}

.welcome h1 {
    font-size: 27px;
    line-height: 1.4;
    margin-bottom: 15px;
}

.welcome p {
    color: #d8f3e5;
    font-size: 14px;
    line-height: 1.9;
}

/* Login panel */

.form-panel {
    flex: 1;
    min-width: 0;
    padding: 48px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.form-panel h2 {
    color: var(--text);
    font-size: 30px;
    margin-bottom: 8px;
}

.subtitle {
    color: var(--muted);
    font-size: 14px;
    line-height: 1.7;
    margin-bottom: 30px;
}

.form-group {
    margin-bottom: 20px;
}

label {
    display: block;
    color: #30473b;
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 9px;
}

input {
    display: block;
    width: 100%;
    padding: 14px;
    border: 1px solid var(--border);
    border-radius: 10px;
    background: #f8fbf9;
    color: #20392d;
    font-size: 14px;
    outline: none;
    transition: border-color 0.2s, box-shadow 0.2s;
}

input:focus {
    border-color: var(--green);
    background: #ffffff;
    box-shadow: 0 0 0 3px rgba(8, 127, 91, 0.1);
}

.password-wrap {
    position: relative;
}

.password-wrap input {
    padding-right: 65px;
}

.toggle {
    position: absolute;
    right: 13px;
    top: 50%;
    transform: translateY(-50%);
    background: transparent;
    border: none;
    color: var(--green);
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
}

/* Buttons */

.btn {
    display: block;
    width: 100%;
    padding: 15px;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 700;
    text-align: center;
    text-decoration: none;
    cursor: pointer;
    transition: background 0.2s, transform 0.2s;
}

.btn-primary {
    color: #ffffff;
    background: var(--green);
    border: 2px solid var(--green);
}

.btn-primary:hover {
    background: #056447;
    border-color: #056447;
}

.btn-secondary {
    color: var(--green);
    background: #ffffff;
    border: 2px solid var(--green);
    margin-top: 12px;
}

.btn-secondary:hover {
    background: #edf8f1;
}

button:focus-visible,
a:focus-visible {
    outline: 3px solid #70d7a4;
    outline-offset: 3px;
}

/* Messages */

.message {
    padding: 13px;
    border-radius: 9px;
    margin-bottom: 20px;
    font-size: 13px;
    line-height: 1.6;
}

.error {
    color: #a51d25;
    background: #fff0f0;
    border: 1px solid #ffd2d2;
}

.success {
    color: #086747;
    background: #e8f8ed;
    border: 1px solid #c5ebd1;
}

/* Footer */

.footer {
    text-align: center;
    border-top: 1px solid #edf1ee;
    margin-top: 25px;
    padding-top: 18px;
    color: #89958d;
    font-size: 12px;
    line-height: 1.8;
}

/* Tablet */

@media (max-width: 800px) and (min-width: 601px) {
    .welcome {
        width: 38%;
        padding: 28px 20px;
    }

    .welcome h1 {
        font-size: 23px;
    }

    .form-panel {
        padding: 35px 28px;
    }
}

/* Mobile: login form only */

@media (max-width: 600px) {
    body {
        display: block;
        padding: 0;
        background: #ffffff;
    }

    .container {
        display: block;
        width: 100%;
        max-width: 100%;
        min-height: 100vh;
        min-height: 100dvh;
        border-radius: 0;
        box-shadow: none;
        animation: none;
    }

    .welcome {
        display: none !important;
    }

    .form-panel {
        width: 100%;
        min-height: 100vh;
        min-height: 100dvh;
        padding: 30px 24px;
        justify-content: center;
    }

    .form-panel h2 {
        font-size: 28px;
    }

    input {
        padding: 15px 13px;
        font-size: 16px;
    }
}

@media (max-width: 360px) {
    .form-panel {
        padding: 25px 18px;
    }

    .form-panel h2 {
        font-size: 26px;
    }
}

@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after {
        animation: none !important;
        transition: none !important;
    }
}
</style>
</head>

<body>

<div class="container">

    <!-- Desktop-only welcome panel -->
    <section class="welcome">
        <div class="logo">A</div>

        <h1>Attendance Monitoring System</h1>

        <p>
            Manage student attendance, monitor daily records,
            and keep your school organized in one place.
        </p>
    </section>

    <!-- Login form -->
    <main class="form-panel">

        <h2>Welcome Back!</h2>

        <p class="subtitle">
            Sign in to access your attendance dashboard.
        </p>

        <?php if ($error !== ""): ?>
            <div class="message error">
                <?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?>
            </div>
        <?php endif; ?>

        <?php if ($success !== ""): ?>
            <div class="message success">
                <?= htmlspecialchars($success, ENT_QUOTES, "UTF-8") ?>
            </div>
        <?php endif; ?>

        <form action="index.php" method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8") ?>"
            >

            <div class="form-group">
                <label for="username">Username</label>

                <input
                    type="text"
                    id="username"
                    name="username"
                    placeholder="Enter your username"
                    autocomplete="username"
                    required
                >
            </div>

            <div class="form-group">
                <label for="password">Password</label>

                <div class="password-wrap">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        required
                    >

                    <button
                        class="toggle"
                        type="button"
                        onclick="togglePassword()"
                    >Show</button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                Login
            </button>

            <a href="signup.php" class="btn btn-secondary">
                Sign Up — Create Account
            </a>

        </form>

        <div class="footer">
            Attendance Monitoring System<br>
            Secure Account Portal
        </div>

    </main>

</div>

<script>
function togglePassword() {
    const password = document.getElementById("password");
    const button = document.querySelector(".toggle");

    const showing = password.type === "password";

    password.type = showing ? "text" : "password";
    button.textContent = showing ? "Hide" : "Show";
}
</script>

</body>
</html>
