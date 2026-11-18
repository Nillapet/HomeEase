
<?php
session_start();

require_once "../config/database.php";

// Only logged-in providers may access this page.
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "provider"
) {
    header("Location: ../login.php");
    exit;
}

$provider_id = (int) $_SESSION["user_id"];
$provider_name = $_SESSION["full_name"] ?? "Provider";

$message = "";
$message_type = "";

$service_name = "";
$description = "";

// Safely escape output.
function provider_services_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

// Create CSRF token.
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION["csrf_token"];

// Generate provider initials.
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

// DISPLAY FLASH MESSAGE AFTER REDIRECT
if (isset($_SESSION["services_flash"])) {
    $message = $_SESSION["services_flash"]["message"] ?? "";
    $message_type = $_SESSION["services_flash"]["type"] ?? "";

    unset($_SESSION["services_flash"]);
}

// PROCESS SERVICE MANAGEMENT ACTIONS
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $submitted_token = $_POST["csrf_token"] ?? "";

    if (
        !is_string($submitted_token) ||
        !hash_equals($csrf_token, $submitted_token)
    ) {
        $message = "Your session could not be verified. Please try again.";
        $message_type = "error";

    } elseif (isset($_POST["add_service"])) {

        // ADD SERVICE
        $service_name = trim($_POST["service_name"] ?? "");
        $description = trim($_POST["description"] ?? "");

        if ($service_name === "") {

            $message = "Please enter a service name.";
            $message_type = "error";

        } elseif (strlen($service_name) > 100) {

            $message = "The service name must not exceed 100 characters.";
            $message_type = "error";

        } elseif (strlen($description) > 2000) {

            $message = "The service description must not exceed 2,000 characters.";
            $message_type = "error";

        } else {

            $stmt = $conn->prepare(
                "INSERT INTO services (provider_id, service_name, description)
                 VALUES (?, ?, ?)"
            );

            if ($stmt) {

                $stmt->bind_param(
                    "iss",
                    $provider_id,
                    $service_name,
                    $description
                );

                if ($stmt->execute()) {

                    $_SESSION["services_flash"] = [
                        "message" => "Service added successfully!",
                        "type" => "success"
                    ];

                    $stmt->close();

                    header("Location: services.php");
                    exit;

                } else {

                    $message = "Unable to add the service. Please try again.";
                    $message_type = "error";
                }

                $stmt->close();

            } else {

                $message = "Something went wrong. Please try again.";
                $message_type = "error";
            }
        }

    } elseif (isset($_POST["delete_service"])) {

        // DELETE SERVICE
        $delete_service_id = filter_input(
            INPUT_POST,
            "service_id",
            FILTER_VALIDATE_INT
        );

        if (!$delete_service_id || $delete_service_id < 1) {

            $message = "Invalid service selected.";
            $message_type = "error";

        } else {

            // Confirm that the service belongs to this provider.
            $ownership_stmt = $conn->prepare(
                "SELECT id
                 FROM services
                 WHERE id = ?
                   AND provider_id = ?"
            );

            if (!$ownership_stmt) {

                $message = "Unable to verify this service. Please try again.";
                $message_type = "error";

            } else {

                $ownership_stmt->bind_param(
                    "ii",
                    $delete_service_id,
                    $provider_id
                );

                $ownership_stmt->execute();
                $ownership_result = $ownership_stmt->get_result();

                if ($ownership_result->num_rows !== 1) {

                    $message = "This service could not be found in your listings.";
                    $message_type = "error";

                } else {

                    // Protect services that have existing bookings.
                    $booking_check = $conn->prepare(
                        "SELECT COUNT(*) AS total
                         FROM bookings
                         WHERE service_id = ?"
                    );

                    if (!$booking_check) {

                        $message = "Unable to check the booking history. Please try again.";
                        $message_type = "error";

                    } else {

                        $booking_check->bind_param("i", $delete_service_id);
                        $booking_check->execute();

                        $booking_result = $booking_check->get_result();
                        $booking_data = $booking_result->fetch_assoc();

                        $linked_bookings = (int) ($booking_data["total"] ?? 0);

                        $booking_check->close();

                        if ($linked_bookings > 0) {

                            $message = "This service cannot be deleted because it has existing booking records. This protects your booking history.";
                            $message_type = "error";

                        } else {

                            // Delete only the provider's own service.
                            $delete_stmt = $conn->prepare(
                                "DELETE FROM services
                                 WHERE id = ?
                                   AND provider_id = ?"
                            );

                            if (!$delete_stmt) {

                                $message = "Unable to prepare the deletion. Please try again.";
                                $message_type = "error";

                            } else {

                                $delete_stmt->bind_param(
                                    "ii",
                                    $delete_service_id,
                                    $provider_id
                                );

                                if (
                                    $delete_stmt->execute() &&
                                    $delete_stmt->affected_rows === 1
                                ) {

                                    $_SESSION["services_flash"] = [
                                        "message" => "Service deleted successfully.",
                                        "type" => "success"
                                    ];

                                    $delete_stmt->close();
                                    $ownership_stmt->close();

                                    header("Location: services.php");
                                    exit;

                                } else {

                                    $message = "Unable to delete this service. Please try again.";
                                    $message_type = "error";
                                }

                                $delete_stmt->close();
                            }
                        }
                    }
                }

                $ownership_stmt->close();
            }
        }
    }
}

// RETRIEVE PROVIDER'S SERVICES
$services_stmt = $conn->prepare(
    "SELECT id, service_name, description
     FROM services
     WHERE provider_id = ?
     ORDER BY id DESC"
);

if (!$services_stmt) {
    die("Unable to retrieve your services. Please try again later.");
}

$services_stmt->bind_param("i", $provider_id);
$services_stmt->execute();

$result = $services_stmt->get_result();
$service_count = $result->num_rows;

// PENDING BOOKING COUNT
$pending_stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM bookings
     WHERE provider_id = ?
       AND status = 'pending'"
);

$pending_count = 0;

if ($pending_stmt) {
    $pending_stmt->bind_param("i", $provider_id);
    $pending_stmt->execute();

    $pending_data = $pending_stmt->get_result()->fetch_assoc();
    $pending_count = (int) ($pending_data["total"] ?? 0);

    $pending_stmt->close();
}

// UNREAD MESSAGE COUNT
$unread_stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM messages m
     INNER JOIN conversations c
        ON c.id = m.conversation_id
     WHERE c.provider_id = ?
       AND m.sender_id <> ?
       AND m.is_read = 0"
);

$unread_count = 0;

if ($unread_stmt) {
    $unread_stmt->bind_param("ii", $provider_id, $provider_id);
    $unread_stmt->execute();

    $unread_data = $unread_stmt->get_result()->fetch_assoc();
    $unread_count = (int) ($unread_data["total"] ?? 0);

    $unread_stmt->close();
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Services | HomeEase</title>

    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/provider.css">
</head>

<body class="provider-services-page">

<div class="provider-dashboard-layout">

    <!-- PROVIDER SIDEBAR -->
    <aside class="provider-sidebar">

        <a href="../index.php" class="provider-brand">

            <div class="provider-brand-icon" aria-hidden="true">
                &#127968;
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

        <!-- Provider Profile -->
        <div class="provider-sidebar-profile">

            <div class="provider-avatar">
                <?= provider_services_escape($provider_initials) ?>
            </div>

            <div class="provider-profile-details">

                <h3>
                    <?= provider_services_escape($provider_name) ?>
                </h3>

                <p>Service Provider</p>

            </div>

        </div>

        <p class="provider-sidebar-label">Main Menu</p>

        <!-- Navigation -->
        <nav class="provider-sidebar-nav">

            <a href="dashboard.php">
                <span class="provider-nav-icon">&#127968;</span>
                <span>Dashboard</span>
            </a>

            <a href="profile.php">
                <span class="provider-nav-icon">&#128100;</span>
                <span>My Profile</span>
            </a>

            <a href="services.php" class="active" aria-current="page">
                <span class="provider-nav-icon">&#128736;</span>
                <span>My Services</span>
            </a>

            <a href="availability.php">
                <span class="provider-nav-icon">&#128197;</span>
                <span>Availability</span>
            </a>

            <a href="bookings.php">
                <span class="provider-nav-icon">&#128203;</span>
                <span>My Bookings</span>

                <?php if ($pending_count > 0): ?>
                    <span class="provider-nav-badge">
                        <?= $pending_count > 99 ? "99+" : $pending_count ?>
                    </span>
                <?php endif; ?>
            </a>

            <a href="messages.php">
                <span class="provider-nav-icon">&#128172;</span>
                <span>Messages</span>

                <?php if ($unread_count > 0): ?>
                    <span class="provider-nav-badge">
                        <?= $unread_count > 99 ? "99+" : $unread_count ?>
                    </span>
                <?php endif; ?>
            </a>

        </nav>

        <!-- Sidebar Bottom -->
        <div class="provider-sidebar-bottom">

            <a href="../logout.php">
                <span class="provider-nav-icon">&#10162;</span>
                <span>Logout</span>
            </a>

            <div class="provider-sidebar-footer">
                Your Skills.<br>
                Our <span>Community.</span>
            </div>

        </div>

    </aside>

    <!-- MAIN CONTENT -->
    <main class="provider-dashboard-content provider-services-content">

        <!-- HERO -->
        <section class="provider-services-hero">

            <div class="provider-services-hero-content">

                <p class="services-eyebrow">YOUR SKILLS. YOUR BUSINESS.</p>

                <h2>Show Customers What You Do</h2>

                <p>
                    Add the home services you provide and give customers
                    a clear picture of how you can help them.
                    Keep your listings accurate and up to date.
                </p>

                <div class="services-hero-summary">

                    <span class="services-count-number">
                        <?= $service_count ?>
                    </span>

                    <span>
                        <?= $service_count === 1
                            ? "service currently listed"
                            : "services currently listed" ?>
                    </span>

                </div>

            </div>

            <div class="provider-services-hero-decoration" aria-hidden="true">
                &#127968;
            </div>

        </section>

        <!-- FEEDBACK MESSAGE -->
        <?php if ($message !== ""): ?>

            <div
                class="provider-services-message <?= provider_services_escape($message_type) ?>"
                role="status"
            >
                <?= provider_services_escape($message) ?>
            </div>

        <?php endif; ?>

        <!-- SERVICES CONTENT -->
        <div class="provider-services-grid-layout">

            <!-- ADD SERVICE FORM -->
            <section class="provider-services-card">

                <div class="provider-services-card-heading">

                    <div class="services-heading-icon" aria-hidden="true">
                        +
                    </div>

                    <div>
                        <p class="services-eyebrow">BUILD YOUR LISTING</p>
                        <h2>Add a Service</h2>
                    </div>

                </div>

                <p class="provider-services-card-description">
                    Enter the service name and explain the work you
                    provide to potential customers.
                </p>

                <form
                    method="POST"
                    action="services.php"
                    class="provider-services-form"
                >

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= provider_services_escape($csrf_token) ?>"
                    >

                    <div class="provider-services-field">

                        <label for="service_name">
                            Service Name
                        </label>

                        <input
                            type="text"
                            id="service_name"
                            name="service_name"
                            placeholder="e.g. Plumbing, Cleaning, Electrical Repairs"
                            value="<?= provider_services_escape($service_name) ?>"
                            maxlength="100"
                            required
                        >

                        <small>
                            Choose a clear name customers will recognise.
                        </small>

                    </div>

                    <div class="provider-services-field">

                        <label for="description">
                            Service Description
                        </label>

                        <textarea
                            id="description"
                            name="description"
                            rows="6"
                            maxlength="2000"
                            placeholder="Describe the work you do and how you can help customers."
                        ><?= provider_services_escape($description) ?></textarea>

                        <small>
                            Include useful details about the work you offer.
                            Maximum 2,000 characters.
                        </small>

                    </div>

                    <button
                        type="submit"
                        name="add_service"
                        class="provider-services-submit"
                    >
                        <span>+</span> Add Service
                    </button>

                </form>

            </section>

            <!-- EXISTING SERVICES -->
            <section class="provider-services-card provider-services-list-card">

                <div class="provider-services-list-heading">

                    <div>
                        <p class="services-eyebrow">YOUR OFFERINGS</p>
                        <h2>Services You Provide</h2>
                        <p>These are the services customers can view.</p>
                    </div>

                    <span class="services-total-badge">
                        <?= $service_count ?>
                    </span>

                </div>

                <?php if ($service_count > 0): ?>

                    <div class="provider-services-list">

                        <?php while ($service = $result->fetch_assoc()): ?>

                            <article class="provider-service-card">

                                <div class="provider-service-icon" aria-hidden="true">
                                    &#10003;
                                </div>

                                <div class="provider-service-content">

                                    <h3>
                                        <?= provider_services_escape($service["service_name"]) ?>
                                    </h3>

                                    <p>
                                        <?php
                                        echo !empty($service["description"])
                                            ? nl2br(provider_services_escape($service["description"]))
                                            : "No description has been added for this service yet.";
                                        ?>
                                    </p>

                                    <span class="provider-service-status">
                                        <span class="status-dot"></span>
                                        Listed
                                    </span>

                                </div>

                                <div class="provider-service-actions">

                                    <form
                                        method="POST"
                                        action="services.php"
                                        onsubmit="return confirm('Are you sure you want to delete this service? This action cannot be undone.');"
                                    >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= provider_services_escape($csrf_token) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="service_id"
                                            value="<?= (int) $service["id"] ?>"
                                        >

                                        <button
                                            type="submit"
                                            name="delete_service"
                                            class="provider-delete-service-btn"
                                        >
                                            &#128465; Delete
                                        </button>

                                    </form>

                                </div>

                            </article>

                        <?php endwhile; ?>

                    </div>

                <?php else: ?>

                    <div class="provider-services-empty">

                        <div class="services-empty-icon" aria-hidden="true">
                            +
                        </div>

                        <h3>No services listed yet</h3>

                        <p>
                            Your service listings will appear here.
                            Add your first service using the form.
                        </p>

                    </div>

                <?php endif; ?>

            </section>

        </div>

        <div class="provider-services-back-link">
            <a href="dashboard.php">
                &larr; Back to Provider Dashboard
            </a>
        </div>

    </main>

</div>

<?php
$services_stmt->close();
$conn->close();
?>

</body>
</html>
