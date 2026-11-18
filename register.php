
<?php
session_start();

require_once "config/database.php";

$message = "";
$message_type = "";

$full_name = "";
$email = "";
$phone = "";
$role = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["register"])) {

    $full_name = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $password = $_POST["password"] ?? "";
    $role = $_POST["role"] ?? "";

    if (
        $full_name === "" ||
        $email === "" ||
        $password === "" ||
        !in_array($role, ["customer", "provider"], true)
    ) {
        $message = "Please complete all required fields and select a valid account type.";
        $message_type = "error";

    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = "Please enter a valid email address.";
        $message_type = "error";

    } elseif (strlen($password) < 8) {
        $message = "Your password must contain at least 8 characters.";
        $message_type = "error";

    } else {
        $check = $conn->prepare(
            "SELECT id FROM users WHERE email = ?"
        );

        if (!$check) {
            $message = "Something went wrong. Please try again.";
            $message_type = "error";
        } else {
            $check->bind_param("s", $email);
            $check->execute();

            $result = $check->get_result();

            if ($result->num_rows > 0) {
                $message = "An account with this email already exists.";
                $message_type = "error";

            } else {
                $hashed_password = password_hash(
                    $password,
                    PASSWORD_DEFAULT
                );

                $stmt = $conn->prepare(
                    "INSERT INTO users
                    (full_name, email, password, role, phone)
                    VALUES (?, ?, ?, ?, ?)"
                );

                if (!$stmt) {
                    $message = "Something went wrong. Please try again.";
                    $message_type = "error";
                } else {
                    $stmt->bind_param(
                        "sssss",
                        $full_name,
                        $email,
                        $hashed_password,
                        $role,
                        $phone
                    );

                    if ($stmt->execute()) {
                        $message = "Your account has been created successfully. You can now log in.";
                        $message_type = "success";

                        $full_name = "";
                        $email = "";
                        $phone = "";
                        $role = "";
                    } else {
                        $message = "Registration failed. Please try again.";
                        $message_type = "error";
                    }

                    $stmt->close();
                }
            }

            $check->close();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Create your HomeEase account to find home services or manage your service business.">
    <title>Create Account | HomeEase</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/register.css">
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
            <a href="login.php">Login</a>
            <a href="register.php" class="nav-register">Create Account</a>
        </nav>
    </div>
</header>

<main class="he-auth-main">

    <section class="he-register-layout">

        <div class="he-register-form-panel">

            <div class="he-register-heading">
                <span class="he-auth-eyebrow">WELCOME TO HOME EASE</span>
                <h1>Create your account<span>.</span></h1>
                <p>
                    Get connected with home services or introduce your
                    services to customers in your area.
                </p>
            </div>

            <?php if ($message !== ""): ?>
                <div
                    class="he-auth-message <?= $message_type === "success" ? "he-auth-success" : "he-auth-error" ?>"
                    role="status"
                    aria-live="polite"
                >
                    <?= htmlspecialchars($message) ?>

                    <?php if ($message_type === "success"): ?>
                        <p class="he-register-login-link">
                            <a href="login.php">Continue to login →</a>
                        </p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="register.php" class="he-register-form">

                <div class="he-form-group">
                    <label for="full_name">Full name <span>*</span></label>
                    <input
                        type="text"
                        id="full_name"
                        name="full_name"
                        placeholder="Enter your full name"
                        value="<?= htmlspecialchars($full_name) ?>"
                        autocomplete="name"
                        maxlength="100"
                        required
                    >
                </div>

                <div class="he-form-group">
                    <label for="email">Email address <span>*</span></label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="you@example.com"
                        value="<?= htmlspecialchars($email) ?>"
                        autocomplete="email"
                        maxlength="254"
                        required
                    >
                </div>

                <div class="he-form-group">
                    <label for="phone">Phone number</label>
                    <input
                        type="tel"
                        id="phone"
                        name="phone"
                        placeholder="Enter your phone number"
                        value="<?= htmlspecialchars($phone) ?>"
                        autocomplete="tel"
                    >
                </div>

                <div class="he-form-group">
                    <label for="password">Password <span>*</span></label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="At least 8 characters"
                        autocomplete="new-password"
                        minlength="8"
                        required
                    >
                    <small>Use at least 8 characters.</small>
                </div>

                <div class="he-form-group">
                    <label for="role">Choose your account type <span>*</span></label>
                    <select id="role" name="role" required>
                        <option value="">Select an account type</option>
                        <option
                            value="customer"
                            <?= $role === "customer" ? "selected" : "" ?>
                        >
                            Customer - I need home services
                        </option>
                        <option
                            value="provider"
                            <?= $role === "provider" ? "selected" : "" ?>
                        >
                            Service provider - I offer services
                        </option>
                    </select>
                </div>

                <button type="submit" name="register" class="he-register-submit">
                    Create My Account <span aria-hidden="true">→</span>
                </button>

            </form>

            <p class="he-register-login">
                Already have an account?
                <a href="login.php">Log in here</a>
            </p>

            <a href="index.php" class="he-register-back">← Back to HomeEase</a>

        </div>

        <aside class="he-register-aside">

            <div class="he-register-aside-content">
                <span class="he-aside-kicker">A BETTER WAY TO CONNECT</span>

                <div class="he-aside-symbol">⌂</div>

                <h2>Your home.<br> Your services.<br> One easier connection.</h2>

                <p>
                    HomeEase brings customers and home-service providers
                    together, making it easier to discover services and
                    manage bookings.
                </p>

                <div class="he-register-benefits">
                    <div class="he-register-benefit">
                        <span>✓</span>
                        <div>
                            <strong>Find the help you need</strong>
                            <p>Explore services and providers by location.</p>
                        </div>
                    </div>

                    <div class="he-register-benefit">
                        <span>✓</span>
                        <div>
                            <strong>Showcase your services</strong>
                            <p>Create a provider profile and list your services.</p>
                        </div>
                    </div>

                    <div class="he-register-benefit">
                        <span>✓</span>
                        <div>
                            <strong>Keep track of bookings</strong>
                            <p>View requests and follow their status.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="he-register-aside-footer">
                HOME EASE · YOUR HOME, OUR PRIORITY
            </div>

        </aside>

    </section>

</main>
</body>
</html>
