
<?php
session_start();

require_once "config/database.php";

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    if (empty($email) || empty($password)) {
        $error = "Please enter your email and password.";
    } else {
        $sql = "SELECT id, full_name, email, password, role
                FROM users
                WHERE email = ?";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            $error = "Something went wrong. Please try again.";
        } else {
            $stmt->bind_param("s", $email);
            $stmt->execute();

            $result = $stmt->get_result();

            if ($result && $result->num_rows === 1) {
                $user = $result->fetch_assoc();

                if (password_verify($password, $user["password"])) {
                    session_regenerate_id(true);

                    $_SESSION["user_id"] = $user["id"];
                    $_SESSION["full_name"] = $user["full_name"];
                    $_SESSION["email"] = $user["email"];
                    $_SESSION["role"] = $user["role"];

                    if ($user["role"] === "admin") {
                        header("Location: admin/dashboard.php");
                    } elseif ($user["role"] === "customer") {
                        header("Location: customer/dashboard.php");
                    } elseif ($user["role"] === "provider") {
                        header("Location: provider/dashboard.php");
                    } else {
                        session_unset();
                        session_destroy();
                        header("Location: login.php");
                    }

                    exit;
                } else {
                    $error = "Invalid email or password.";
                }
            } else {
                $error = "Invalid email or password.";
            }

            $stmt->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Log in to HomeEase to find home services or manage your bookings.">
    <title>Login | HomeEase</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/login.css">
</head>

<body class="auth-page homeease-auth-page">

<header class="main-header">
    <div class="container navbar">
        <a href="index.php" class="brand">
            <span class="brand-icon">⌂</span>
            <span class="homeease-brand-text">
                Home<span class="brand-accent">Ease</span>
                <small>YOUR HOME, OUR PRIORITY</small>
            </span>
        </a>

        <nav class="main-nav">
            <a href="index.php">Home</a>
            <a href="register.php">Create Account</a>
        </nav>
    </div>
</header>

<main class="he-auth-main">

    <section class="he-login-layout">

        <div class="he-login-form-panel">

            <div class="he-login-heading">
                <span class="he-auth-eyebrow">WELCOME BACK</span>
                <h1>Good to have you back<span>.</span></h1>
                <p>
                    Log in to book a service, manage your requests,
                    or continue helping customers.
                </p>
            </div>

            <?php if (!empty($error)): ?>
                <div class="he-auth-message he-auth-error" role="alert" aria-live="polite">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form action="login.php" method="POST" class="he-login-form">

                <div class="he-form-group">
                    <label for="email">Email address <span>*</span></label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="you@example.com"
                        value="<?= htmlspecialchars($_POST["email"] ?? "") ?>"
                        autocomplete="email"
                        maxlength="254"
                        required
                    >
                </div>

                <div class="he-form-group">
                    <label for="password">Password <span>*</span></label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        required
                    >
                </div>

                <button type="submit" class="he-register-submit">
                    Log In to HomeEase <span aria-hidden="true">→</span>
                </button>

            </form>

            <p class="he-register-login">
                Don't have an account?
                <a href="register.php">Create one here</a>
            </p>

            <a href="index.php" class="he-register-back">← Back to HomeEase</a>

        </div>

        <aside class="he-login-aside">

            <div class="he-login-aside-content">
                <span class="he-aside-kicker">HOME SERVICES, MADE SIMPLE</span>

                <div class="he-login-symbol">⌂</div>

                <h2>The help you need.<br>The connections you need.</h2>

                <p>
                    Find local service providers, explore their services,
                    and manage your bookings in one convenient place.
                </p>

                <div class="he-register-benefits">

                    <div class="he-register-benefit">
                        <span>✓</span>
                        <div>
                            <strong>Discover local services</strong>
                            <p>Search for services in your preferred location.</p>
                        </div>
                    </div>

                    <div class="he-register-benefit">
                        <span>✓</span>
                        <div>
                            <strong>Manage your bookings</strong>
                            <p>Submit requests and follow their status.</p>
                        </div>
                    </div>

                    <div class="he-register-benefit">
                        <span>✓</span>
                        <div>
                            <strong>Your account, in one place</strong>
                            <p>Access the tools available for your account type.</p>
                        </div>
                    </div>

                </div>
            </div>

            <div class="he-login-aside-footer">
                HOME EASE · YOUR HOME, OUR PRIORITY
            </div>

        </aside>

    </section>

</main>
</body>
</html>
