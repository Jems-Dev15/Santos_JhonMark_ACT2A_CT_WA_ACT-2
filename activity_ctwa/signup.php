<?php
session_start();

require __DIR__ . "/config.php";

$error = "";

if (!isset($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $fullName = trim($_POST["full_name"] ?? "");
    $username = trim($_POST["username"] ?? "");
    $password = $_POST["password"] ?? "";
    $confirmPassword = $_POST["confirm_password"] ?? "";

    if (
        !isset($_POST["csrf_token"]) ||
        !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
    ) {
        $error = "Invalid request. Please refresh the page.";
    } elseif ($fullName === "" || $username === "" || $password === "") {
        $error = "Please complete all required fields.";
    } elseif (strlen($fullName) > 100) {
        $error = "Full name cannot exceed 100 characters.";
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
        $error = "Username must be 3-30 characters using letters, numbers, or underscores.";
    } elseif (strlen($password) < 8) {
        $error = "Password must contain at least 8 characters.";
    } elseif ($password !== $confirmPassword) {
        $error = "Passwords do not match.";
    } else {
        try {
            $pdo = getPDO();

            $check = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
            $check->execute([$username]);

            if ($check->fetch()) {
                $error = "Username already exists. Please choose another.";
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO users (full_name, username, password, role)
                     VALUES (?, ?, ?, 'teacher')"
                );
                $stmt->execute([
                    $fullName,
                    $username,
                    password_hash($password, PASSWORD_DEFAULT)
                ]);

                header("Location: index.php?registered=1");
                exit;
            }
        } catch (PDOException $e) {
            error_log($e->getMessage());
            // 1062 = duplicate key (race condition on username)
            $error = ($e->errorInfo[1] ?? 0) === 1062
                ? "Username already exists. Please choose another."
                : "Could not create the account. Make sure MySQL is running and schema.sql was imported.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Sign Up | Attendance Monitoring System</title>

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
    display: flex;
    background: #fff;
    border-radius: 22px;
    overflow: hidden;
    box-shadow: 0 25px 65px #0004;
    animation: fadeIn .4s ease;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(12px); }
    to { opacity: 1; transform: translateY(0); }
}

/* Welcome panel for desktop */

.welcome {
    width: 43%;
    flex-shrink: 0;
    padding: 40px 30px;
    background: linear-gradient(145deg, #08734f, #03422f);
    color: white;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    text-align: center;
}

.logo {
    width: 82px;
    height: 82px;
    border-radius: 24px;
    background: #e0ffed;
    color: var(--green);
    display: grid;
    place-items: center;
    font-size: 40px;
    font-weight: 800;
    margin-bottom: 24px;
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

/* Registration form */

.form-panel {
    flex: 1;
    min-width: 0;
    padding: 38px 45px;
    display: flex;
    flex-direction: column;
    justify-content: center;
}

.form-panel h2 {
    color: #123e2d;
    font-size: 30px;
    margin-bottom: 8px;
}

.subtitle {
    color: #7b8981;
    font-size: 14px;
    line-height: 1.6;
    margin-bottom: 24px;
}

.form-group {
    margin-bottom: 16px;
}

label {
    display: block;
    color: #30473b;
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 8px;
}

input {
    width: 100%;
    padding: 13px 14px;
    border: 1px solid #d8e4dc;
    border-radius: 9px;
    background: #f8fbf9;
    font-size: 14px;
    outline: none;
    transition: .2s;
}

input:focus {
    border-color: var(--green);
    background: #fff;
    box-shadow: 0 0 0 3px #087f5b18;
}

.password-wrap {
    position: relative;
}

.password-wrap input {
    padding-right: 65px;
}

.toggle {
    position: absolute;
    right: 12px;
    top: 50%;
    transform: translateY(-50%);
    background: transparent;
    border: none;
    color: var(--green);
    font-weight: 700;
    cursor: pointer;
}

.btn {
    display: block;
    width: 100%;
    padding: 14px;
    border-radius: 9px;
    font-size: 15px;
    font-weight: 700;
    text-align: center;
    text-decoration: none;
    cursor: pointer;
    transition: .2s;
}

.btn-primary {
    color: white;
    background: var(--green);
    border: 2px solid var(--green);
}

.btn-primary:hover {
    background: #055b40;
    border-color: #055b40;
}

.btn-secondary {
    color: var(--green);
    background: white;
    border: 2px solid var(--green);
    margin-top: 11px;
}

.btn-secondary:hover {
    background: #edf8f1;
}

.message {
    padding: 12px;
    border-radius: 8px;
    margin-bottom: 17px;
    font-size: 13px;
    line-height: 1.6;
}

.error {
    color: #a51d25;
    background: #fff0f0;
    border: 1px solid #ffd2d2;
}

.footer {
    text-align: center;
    border-top: 1px solid #edf1ee;
    margin-top: 21px;
    padding-top: 15px;
    color: #89958d;
    font-size: 12px;
    line-height: 1.8;
}

/* Tablet */

@media (max-width: 800px) and (min-width: 601px) {
    .welcome {
        width: 38%;
        padding: 25px 18px;
    }

    .welcome h1 {
        font-size: 22px;
    }

    .form-panel {
        padding: 30px 25px;
    }
}

/* Mobile: hide welcome panel */

@media (max-width: 600px) {
    body {
        display: block;
        padding: 0;
        background: white;
    }

    .container {
        display: block;
        width: 100%;
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
        font-size: 16px;
        padding: 14px;
    }
}

@media (max-width: 360px) {
    .form-panel {
        padding: 25px 18px;
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

    <section class="welcome">
        <div class="logo">A</div>
        <h1>Attendance Monitoring System</h1>
        <p>
            Create your account to manage attendance,
            monitor records, and keep your school organized.
        </p>
    </section>

    <main class="form-panel">

        <h2>Create Account</h2>
        <p class="subtitle">
            Register to access the attendance monitoring system.
        </p>

        <?php if ($error !== ""): ?>
            <div class="message error">
                <?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?>
            </div>
        <?php endif; ?>

        <form action="signup.php" method="POST">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= htmlspecialchars($_SESSION["csrf_token"], ENT_QUOTES, "UTF-8") ?>"
            >

            <div class="form-group">
                <label for="full_name">Full Name</label>
                <input
                    type="text"
                    id="full_name"
                    name="full_name"
                    placeholder="Enter your full name"
                    autocomplete="name"
                    maxlength="100"
                    required
                >
            </div>

            <div class="form-group">
                <label for="username">Username</label>
                <input
                    type="text"
                    id="username"
                    name="username"
                    placeholder="Choose a username"
                    autocomplete="username"
                    minlength="3"
                    maxlength="30"
                    pattern="[A-Za-z0-9_]{3,30}"
                    title="Use 3-30 letters, numbers, or underscores."
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
                        placeholder="At least 8 characters"
                        autocomplete="new-password"
                        minlength="8"
                        required
                    >
                    <button
                        class="toggle"
                        type="button"
                        onclick="togglePassword('password', this)"
                    >Show</button>
                </div>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>

                <div class="password-wrap">
                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        placeholder="Re-enter your password"
                        autocomplete="new-password"
                        minlength="8"
                        required
                    >
                    <button
                        class="toggle"
                        type="button"
                        onclick="togglePassword('confirm_password', this)"
                    >Show</button>
                </div>
            </div>

            <button type="submit" class="btn btn-primary">
                Create Account
            </button>

            <a href="index.php" class="btn btn-secondary">
                Back to Login
            </a>

        </form>

        <div class="footer">
            Attendance Monitoring System<br>
            Secure Account Portal
        </div>

    </main>
</div>

<script>
function togglePassword(id, button) {
    const field = document.getElementById(id);
    const showing = field.type === "password";

    field.type = showing ? "text" : "password";
    button.textContent = showing ? "Hide" : "Show";
}
</script>

</body>
</html>
