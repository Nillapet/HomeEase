
<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

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

$provider_id = (int) $_SESSION["user_id"];

// Generate a CSRF token for secure forms.
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION["csrf_token"];

/* HELPER FUNCTIONS */

function providerBookingEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

function provider_booking_status_class($status): string
{
    $status = strtolower((string) $status);

    if (in_array($status, ["pending", "accepted", "rejected"], true)) {
        return $status;
    }

    return "other";
}

function provider_booking_date($date): string
{
    $timestamp = strtotime($date);

    return $timestamp ? date("d M Y", $timestamp) : (string) $date;
}

function provider_booking_time($time): string
{
    $timestamp = strtotime($time);

    return $timestamp ? date("g:i A", $timestamp) : (string) $time;
}

/* PROVIDER DETAILS */

$provider_name = $_SESSION["full_name"]
    ?? $_SESSION["name"]
    ?? "Service Provider";

$name_stmt = $conn->prepare(
    "SELECT full_name FROM users WHERE id = ? LIMIT 1"
);

if ($name_stmt) {
    $name_stmt->bind_param("i", $provider_id);
    $name_stmt->execute();

    $name_result = $name_stmt->get_result();

    if ($name_row = $name_result->fetch_assoc()) {
        if (!empty($name_row["full_name"])) {
            $provider_name = $name_row["full_name"];
        }
    }

    $name_stmt->close();
}

$name_parts = preg_split('/\s+/', trim($provider_name));
$initial = strtoupper(substr($name_parts[0] ?? "P", 0, 1));

/* RETRIEVE PROVIDER BOOKINGS */

$bookings = [];

$counts = [
    "total" => 0,
    "pending" => 0,
    "accepted" => 0,
    "rejected" => 0
];

$stmt = $conn->prepare(
    "SELECT
        bookings.id,
        users.full_name AS customer_name,
        services.service_name,
        bookings.booking_date,
        bookings.booking_time,
        bookings.status
     FROM bookings
     INNER JOIN users
        ON bookings.customer_id = users.id
     INNER JOIN services
        ON bookings.service_id = services.id
     WHERE bookings.provider_id = ?
     ORDER BY bookings.id DESC"
);

if ($stmt) {
    $stmt->bind_param("i", $provider_id);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($booking = $result->fetch_assoc()) {
        $bookings[] = $booking;

        $counts["total"]++;

        $status = strtolower((string) $booking["status"]);

        if (isset($counts[$status])) {
            $counts[$status]++;
        }
    }

    $stmt->close();
}

/* SIDEBAR COUNTS */

$pending_count = $counts["pending"];
$unread_count = 0;

$unread_stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM messages m
     INNER JOIN conversations c
        ON m.conversation_id = c.id
     WHERE c.provider_id = ?
       AND m.sender_id != ?
       AND m.is_read = 0"
);

if ($unread_stmt) {
    $unread_stmt->bind_param("ii", $provider_id, $provider_id);
    $unread_stmt->execute();

    $unread_result = $unread_stmt->get_result();
    $unread_row = $unread_result->fetch_assoc();

    $unread_count = (int) ($unread_row["total"] ?? 0);

    $unread_stmt->close();
}

$conn->close();

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Bookings | HomeEase</title>

    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/provider.css">
</head>

<body class="provider-bookings-page">

<div class="provider-dashboard-layout">

    <!-- PROVIDER SIDEBAR -->

    <aside class="provider-sidebar">

        <a href="../index.php" class="provider-brand">

            <span class="provider-brand-icon">H</span>

            <span class="provider-brand-copy">
                <span class="provider-brand-name">
                    Home<span>Ease</span>
                </span>

                <span class="provider-brand-tagline">
                    CONNECT. BOOK. THRIVE.
                </span>
            </span>

        </a>

        <div class="provider-sidebar-profile">

            <div class="provider-avatar">
                <?php echo providerBookingEscape($initial); ?>
            </div>

            <div class="provider-profile-details">

                <h3>
                    <?php echo providerBookingEscape($provider_name); ?>
                </h3>

                <p>Service Provider</p>

            </div>

        </div>

        <p class="provider-sidebar-label">MAIN MENU</p>

        <nav class="provider-sidebar-nav">

            <a href="dashboard.php">
                <span class="provider-nav-icon">⌂</span>
                <span class="provider-nav-text">Dashboard</span>
            </a>

            <a href="profile.php">
                <span class="provider-nav-icon">♙</span>
                <span class="provider-nav-text">My Profile</span>
            </a>

            <a href="services.php">
                <span class="provider-nav-icon">⚙</span>
                <span class="provider-nav-text">My Services</span>
            </a>

            <a href="availability.php">
                <span class="provider-nav-icon">◷</span>
                <span class="provider-nav-text">Availability</span>
            </a>

            <a href="bookings.php" class="active" aria-current="page">
                <span class="provider-nav-icon">▣</span>
                <span class="provider-nav-text">My Bookings</span>

                <?php if ($pending_count > 0): ?>
                    <span class="provider-nav-badge">
                        <?php echo $pending_count; ?>
                    </span>
                <?php endif; ?>
            </a>

            <a href="messages.php">
                <span class="provider-nav-icon">✉</span>
                <span class="provider-nav-text">Messages</span>

                <?php if ($unread_count > 0): ?>
                    <span class="provider-nav-badge">
                        <?php echo $unread_count; ?>
                    </span>
                <?php endif; ?>
            </a>

        </nav>

        <div class="provider-sidebar-bottom">

            <a href="../logout.php">
                <span class="provider-nav-icon">↪</span>
                <span>Logout</span>
            </a>

            <div class="provider-sidebar-footer">
                Your Skills. <span>Our Community.</span>
            </div>

        </div>

    </aside>

    <!-- MAIN CONTENT -->

    <main class="provider-dashboard-content provider-bookings-content">

        <div class="provider-bookings-main">

            <!-- PAGE INTRODUCTION -->

            <section class="provider-bookings-hero">

                <div>
                    <p class="provider-bookings-eyebrow">
                        PROVIDER WORKSPACE
                    </p>

                    <h1>Manage Your Bookings</h1>

                    <p>
                        Review customer requests, check appointment details,
                        and respond to pending bookings.
                    </p>
                </div>

                <a href="dashboard.php" class="provider-bookings-back">
                    &larr; Back to Dashboard
                </a>

            </section>

            <!-- BOOKING STATISTICS -->

            <section class="provider-booking-stats" aria-label="Booking summary">

                <article class="provider-booking-stat">

                    <span class="provider-stat-label">Total Bookings</span>

                    <strong>
                        <?php echo $counts["total"]; ?>
                    </strong>

                    <span class="provider-stat-note">
                        All your requests
                    </span>

                </article>

                <article class="provider-booking-stat provider-stat-pending">

                    <span class="provider-stat-label">Pending</span>

                    <strong>
                        <?php echo $counts["pending"]; ?>
                    </strong>

                    <span class="provider-stat-note">
                        Awaiting your response
                    </span>

                </article>

                <article class="provider-booking-stat provider-stat-accepted">

                    <span class="provider-stat-label">Accepted</span>

                    <strong>
                        <?php echo $counts["accepted"]; ?>
                    </strong>

                    <span class="provider-stat-note">
                        Confirmed requests
                    </span>

                </article>

                <article class="provider-booking-stat provider-stat-rejected">

                    <span class="provider-stat-label">Rejected</span>

                    <strong>
                        <?php echo $counts["rejected"]; ?>
                    </strong>

                    <span class="provider-stat-note">
                        Declined requests
                    </span>

                </article>

            </section>

            <!-- BOOKING REQUESTS -->

            <section class="provider-bookings-section">

                <div class="provider-bookings-heading">

                    <div>
                        <h2>Your Booking Requests</h2>

                        <p>
                            Review the details before responding to a request.
                        </p>
                    </div>

                    <span class="provider-booking-count">
                        <?php echo $counts["total"]; ?>
                        <?php echo $counts["total"] === 1 ? "booking" : "bookings"; ?>
                    </span>

                </div>

                <?php if (!empty($bookings)): ?>

                    <div class="provider-bookings-grid">

                        <?php foreach ($bookings as $booking): ?>

                            <?php
                            $status = strtolower((string) $booking["status"]);
                            $status_class = provider_booking_status_class($status);
                            ?>

                            <article class="provider-booking-card">

                                <div class="provider-booking-card-top">

                                    <div class="provider-booking-service-icon" aria-hidden="true">
                                        &#128221;
                                    </div>

                                    <span class="provider-booking-status status-<?php echo providerBookingEscape($status_class); ?>">
                                        <?php echo providerBookingEscape(ucfirst($status)); ?>
                                    </span>

                                </div>

                                <h3>
                                    <?php echo providerBookingEscape($booking["service_name"]); ?>
                                </h3>

                                <div class="provider-booking-details">

                                    <div class="provider-booking-detail">
                                        <span class="provider-detail-label">Customer</span>

                                        <strong>
                                            <?php echo providerBookingEscape($booking["customer_name"]); ?>
                                        </strong>
                                    </div>

                                    <div class="provider-booking-detail">
                                        <span class="provider-detail-label">Appointment Date</span>

                                        <strong>
                                            <?php echo providerBookingEscape(provider_booking_date($booking["booking_date"])); ?>
                                        </strong>
                                    </div>

                                    <div class="provider-booking-detail">
                                        <span class="provider-detail-label">Appointment Time</span>

                                        <strong>
                                            <?php echo providerBookingEscape(provider_booking_time($booking["booking_time"])); ?>
                                        </strong>
                                    </div>

                                    <div class="provider-booking-detail">
                                        <span class="provider-detail-label">Booking Reference</span>

                                        <strong>
                                            #<?php echo (int) $booking["id"]; ?>
                                        </strong>
                                    </div>

                                </div>

                                <!-- MESSAGE CUSTOMER -->

                                <form
                                    method="POST"
                                    action="start_conversation.php"
                                    class="provider-message-form"
                                >

                                    <input
                                        type="hidden"
                                        name="booking_id"
                                        value="<?php echo (int) $booking["id"]; ?>"
                                    >

                                    <input
                                        type="hidden"
                                        name="csrf_token"
                                        value="<?php echo providerBookingEscape($csrf_token); ?>"
                                    >

                                    <button type="submit" class="provider-message-button">
                                        &#9993; Message Customer
                                    </button>

                                </form>

                                <!-- BOOKING STATUS ACTIONS -->

                                <?php if ($status === "pending"): ?>

                                    <div class="provider-booking-action-area">

                                        <p>This request needs your response.</p>

                                        <form
                                            method="POST"
                                            action="update_booking.php"
                                            class="provider-booking-actions"
                                        >

                                            <input
                                                type="hidden"
                                                name="booking_id"
                                                value="<?php echo (int) $booking["id"]; ?>"
                                            >

                                            <button
                                                type="submit"
                                                name="status"
                                                value="accepted"
                                                class="provider-accept-button"
                                            >
                                                Accept Booking
                                            </button>

                                            <button
                                                type="submit"
                                                name="status"
                                                value="rejected"
                                                class="provider-reject-button"
                                                onclick="return confirm('Are you sure you want to reject this booking?');"
                                            >
                                                Reject
                                            </button>

                                        </form>

                                    </div>

                                <?php elseif ($status === "accepted"): ?>

                                    <div class="provider-booking-result result-accepted">
                                        This booking has been accepted.
                                    </div>

                                <?php elseif ($status === "rejected"): ?>

                                    <div class="provider-booking-result result-rejected">
                                        This booking has been rejected.
                                    </div>

                                <?php else: ?>

                                    <div class="provider-booking-result">
                                        Current status:
                                        <?php echo providerBookingEscape(ucfirst($status)); ?>
                                    </div>

                                <?php endif; ?>

                            </article>

                        <?php endforeach; ?>

                    </div>

                <?php else: ?>

                    <div class="provider-bookings-empty">

                        <div class="provider-empty-icon" aria-hidden="true">
                            &#128221;
                        </div>

                        <h3>No Booking Requests Yet</h3>

                        <p>
                            When customers book one of your services,
                            their requests will appear here.
                        </p>

                        <a href="services.php" class="provider-empty-button">
                            View My Services
                        </a>

                    </div>

                <?php endif; ?>

            </section>

        </div>

    </main>

</div>

</body>
</html>
