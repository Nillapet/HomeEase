
<?php
session_start();

require_once "../config/database.php";

// Only logged-in providers can access this page.
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "provider"
) {
    header("Location: ../login.php");
    exit;
}

$user_id = (int) $_SESSION["user_id"];
$provider_name = $_SESSION["full_name"] ?? "Provider";

$message = "";
$message_type = "";

$profile = [
    "location" => "",
    "description" => "",
    "proof_of_work" => ""
];

// Escape output safely.
function provider_profile_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

// Get provider initials.
$name_parts = preg_split('/\s+/', trim($provider_name));
$provider_initials = "";

foreach (array_slice($name_parts, 0, 2) as $part) {
    if ($part !== "") {
        $provider_initials .= strtoupper(substr($part, 0, 1));
    }
}

if ($provider_initials === "") {
    $provider_initials = "P";
}

// Load saved profile.
$stmt = $conn->prepare(
    "SELECT location, description, proof_of_work
     FROM provider_profiles
     WHERE user_id = ?"
);

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $profile = $result->fetch_assoc();
}

$stmt->close();

// Process profile submission.
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["save_profile"])) {

    $location = trim($_POST["location"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $proof_of_work = trim($_POST["proof_of_work"] ?? "");

    // Preserve submitted values if validation fails.
    $profile = [
        "location" => $location,
        "description" => $description,
        "proof_of_work" => $proof_of_work
    ];

    if ($location === "") {

        $message = "Please enter your service location.";
        $message_type = "error";

    } elseif (
        strlen($location) > 150 ||
        strlen($description) > 2000 ||
        strlen($proof_of_work) > 2000
    ) {

        $message = "Please check the length of the information entered.";
        $message_type = "error";

    } else {

        $check = $conn->prepare(
            "SELECT id
             FROM provider_profiles
             WHERE user_id = ?"
        );

        $check->bind_param("i", $user_id);
        $check->execute();
        $check_result = $check->get_result();

        if ($check_result->num_rows > 0) {

            $save_stmt = $conn->prepare(
                "UPDATE provider_profiles
                 SET location = ?, description = ?, proof_of_work = ?
                 WHERE user_id = ?"
            );

            $save_stmt->bind_param(
                "sssi",
                $location,
                $description,
                $proof_of_work,
                $user_id
            );

        } else {

            $save_stmt = $conn->prepare(
                "INSERT INTO provider_profiles
                 (user_id, location, description, proof_of_work)
                 VALUES (?, ?, ?, ?)"
            );

            $save_stmt->bind_param(
                "isss",
                $user_id,
                $location,
                $description,
                $proof_of_work
            );
        }

        if ($save_stmt->execute()) {

            $message = "Your profile has been saved successfully.";
            $message_type = "success";

        } else {

            $message = "We could not save your profile. Please try again.";
            $message_type = "error";
        }

        $save_stmt->close();
        $check->close();
    }
}

// Get pending bookings for sidebar badge.
$pending_count = 0;

$pending_stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM bookings
     WHERE provider_id = ?
       AND status = 'pending'"
);

$pending_stmt->bind_param("i", $user_id);
$pending_stmt->execute();

$pending_count = (int) $pending_stmt
    ->get_result()
    ->fetch_assoc()["total"];

$pending_stmt->close();

// Get unread messages for sidebar badge.
$unread_count = 0;

$unread_stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM messages m
     INNER JOIN conversations c
        ON c.id = m.conversation_id
     WHERE c.provider_id = ?
       AND m.sender_id <> ?
       AND m.is_read = 0"
);

$unread_stmt->bind_param("ii", $user_id, $user_id);
$unread_stmt->execute();

$unread_count = (int) $unread_stmt
    ->get_result()
    ->fetch_assoc()["total"];

$unread_stmt->close();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Profile | HomeEase</title>

    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/provider.css">
</head>

<body class="provider-profile-page">

<div class="provider-profile-layout">

    <!-- VERTICAL SIDEBAR -->
    <aside class="provider-sidebar">

        <a href="../index.php" class="provider-brand">

            <div class="provider-brand-icon" aria-hidden="true">
                🏠
            </div>

            <div class="provider-brand-copy">
                <div class="provider-brand-name">
                    Home<span>Ease</span>
                </div>

                <div class="provider-brand-tagline">
                    CONNECT. BOOK. THRIVE.
                </div>
            </div>

        </a>

        <div class="provider-sidebar-profile">

            <div class="provider-avatar">
                <?= provider_profile_escape($provider_initials) ?>
            </div>

            <div class="provider-profile-details">
                <h3>
                    <?= provider_profile_escape($provider_name) ?>
                </h3>

                <p>Service Provider</p>
            </div>

        </div>

        <p class="provider-sidebar-label">Main Menu</p>

        <nav class="provider-sidebar-nav">

            <a href="dashboard.php">
                <span class="provider-nav-icon">🏠</span>
                <span>Dashboard</span>
            </a>

            <a href="profile.php" class="active" aria-current="page">
                <span class="provider-nav-icon">👤</span>
                <span>My Profile</span>
            </a>

            <a href="services.php">
                <span class="provider-nav-icon">🛠</span>
                <span>My Services</span>
            </a>

            <a href="availability.php">
                <span class="provider-nav-icon">📅</span>
                <span>Availability</span>
            </a>

            <a href="bookings.php">
                <span class="provider-nav-icon">📋</span>
                <span>My Bookings</span>

                <?php if ($pending_count > 0): ?>
                    <span class="provider-nav-badge">
                        <?= $pending_count > 99 ? "99+" : $pending_count ?>
                    </span>
                <?php endif; ?>
            </a>

            <a href="messages.php">
                <span class="provider-nav-icon">💬</span>
                <span>Messages</span>

                <?php if ($unread_count > 0): ?>
                    <span class="provider-nav-badge">
                        <?= $unread_count > 99 ? "99+" : $unread_count ?>
                    </span>
                <?php endif; ?>
            </a>

        </nav>

        <div class="provider-sidebar-bottom">
            <a href="../logout.php">
                <span class="provider-nav-icon">⇥</span>
                <span>Logout</span>
            </a>
        </div>

        <div class="provider-sidebar-footer">
            Your Skills.<br>
            Our <span>Community.</span>
        </div>

    </aside>

    <!-- MAIN PROFILE CONTENT -->
    <main class="provider-profile-content">

        <section class="provider-profile-hero">

            <p class="provider-profile-eyebrow">
                YOUR PROFESSIONAL PROFILE
            </p>

            <h2>Tell customers about yourself</h2>

            <p>
                Share where you work, what you do, and the experience you bring.
                Keeping your information accurate helps customers make informed
                booking decisions.
            </p>

        </section>

        <div class="provider-profile-grid">

            <!-- PROFILE FORM -->
            <section class="provider-profile-form-card">

                <div class="provider-profile-card-heading">
                    <h2>Profile Information</h2>

                    <p>
                        Complete the details below and save your changes.
                    </p>
                </div>

                <?php if ($message !== ""): ?>

                    <div
                        class="provider-profile-message <?= $message_type === "success"
                            ? "profile-message-success"
                            : "profile-message-error" ?>"
                        role="status"
                    >
                        <?= provider_profile_escape($message) ?>
                    </div>

                <?php endif; ?>

                <form
                    method="POST"
                    action="profile.php"
                    class="provider-profile-form"
                >

                    <div class="provider-profile-field">

                        <label for="location">
                            Service Location <span>*</span>
                        </label>

                        <input
                            type="text"
                            id="location"
                            name="location"
                            placeholder="e.g. Kisii, Nairobi, Ngong"
                            value="<?= provider_profile_escape($profile["location"]) ?>"
                            maxlength="150"
                            required
                        >

                        <small>
                            Enter the town or area where you provide services.
                        </small>

                    </div>

                    <div class="provider-profile-field">

                        <label for="description">
                            About Your Services
                        </label>

                        <textarea
                            id="description"
                            name="description"
                            rows="5"
                            maxlength="2000"
                            placeholder="Describe your skills, experience and the services you offer."
                        ><?= provider_profile_escape($profile["description"]) ?></textarea>

                        <small>
                            Help customers understand what makes your service useful.
                        </small>

                    </div>

                    <div class="provider-profile-field">

                        <label for="proof_of_work">
                            Experience and Proof of Work
                        </label>

                        <textarea
                            id="proof_of_work"
                            name="proof_of_work"
                            rows="5"
                            maxlength="2000"
                            placeholder="Describe previous jobs, relevant experience, completed projects or examples of your work."
                        ><?= provider_profile_escape($profile["proof_of_work"]) ?></textarea>

                        <small>
                            Describe genuine experience or previous work.
                            This field currently stores text, not uploaded files or photographs.
                        </small>

                    </div>

                    <div class="provider-profile-form-actions">

                        <button
                            type="submit"
                            name="save_profile"
                            class="provider-profile-save"
                        >
                            Save Profile
                        </button>

                        <a
                            href="dashboard.php"
                            class="provider-profile-cancel"
                        >
                            Back to Dashboard
                        </a>

                    </div>

                </form>

            </section>

            <!-- PROFILE TIPS -->
            <aside class="provider-profile-tips">

                <div class="provider-profile-tip-icon" aria-hidden="true">
                    ✓
                </div>

                <h2>Build Customer Confidence</h2>

                <p>
                    A clear and honest profile helps customers understand
                    your services before making a booking.
                </p>

                <ul>
                    <li>Use an accurate service location.</li>
                    <li>Explain the services you actually provide.</li>
                    <li>Describe relevant skills and past experience.</li>
                    <li>Keep your information up to date.</li>
                </ul>

                <div class="provider-profile-tip-note">

                    <strong>Next Step</strong>

                    <p>
                        Make sure your services and availability are also
                        up to date so customers can find and book you.
                    </p>

                    <a href="services.php">
                        Manage My Services →
                    </a>

                    <a href="availability.php">
                        Set My Availability →
                    </a>

                </div>

            </aside>

        </div>

    </main>

</div>

<?php $conn->close(); ?>

</body>
</html>
