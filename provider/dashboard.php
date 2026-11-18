
<?php
session_start();

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "provider"
) {
    header("Location: ../login.php");
    exit;
}

require_once "../config/database.php";

$provider_id = (int) $_SESSION["user_id"];
$provider_name = $_SESSION["full_name"] ?? "Provider";

// Booking statistics
$booking_counts = [
    "total" => 0,
    "pending" => 0,
    "accepted" => 0,
    "rejected" => 0
];

$count_stmt = $conn->prepare(
    "SELECT
        COUNT(*) AS total,
        COALESCE(SUM(status = 'pending'), 0) AS pending,
        COALESCE(SUM(status = 'accepted'), 0) AS accepted,
        COALESCE(SUM(status = 'rejected'), 0) AS rejected
     FROM bookings
     WHERE provider_id = ?"
);

$count_stmt->bind_param("i", $provider_id);
$count_stmt->execute();
$booking_counts = $count_stmt->get_result()->fetch_assoc();
$count_stmt->close();

// Service count
$service_stmt = $conn->prepare(
    "SELECT COUNT(*) AS total FROM services WHERE provider_id = ?"
);

$service_stmt->bind_param("i", $provider_id);
$service_stmt->execute();
$service_count = (int) $service_stmt->get_result()->fetch_assoc()["total"];
$service_stmt->close();

// Availability count
$availability_stmt = $conn->prepare(
    "SELECT COUNT(*) AS total FROM availability WHERE provider_id = ?"
);

$availability_stmt->bind_param("i", $provider_id);
$availability_stmt->execute();
$availability_count = (int) $availability_stmt->get_result()->fetch_assoc()["total"];
$availability_stmt->close();

// Unread messages
$unread_stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM messages m
     INNER JOIN conversations c ON c.id = m.conversation_id
     WHERE c.provider_id = ?
       AND m.sender_id <> ?
       AND m.is_read = 0"
);

$unread_stmt->bind_param("ii", $provider_id, $provider_id);
$unread_stmt->execute();
$unread_count = (int) $unread_stmt->get_result()->fetch_assoc()["total"];
$unread_stmt->close();

// Recent bookings
$recent_stmt = $conn->prepare(
    "SELECT
        b.id,
        b.customer_id,
        b.status,
        b.booking_date,
        b.booking_time,
        u.full_name AS customer_name,
        s.service_name,
        c.id AS conversation_id
     FROM bookings b
     INNER JOIN users u ON u.id = b.customer_id
     INNER JOIN services s ON s.id = b.service_id
     LEFT JOIN conversations c
        ON c.customer_id = b.customer_id
       AND c.provider_id = b.provider_id
     WHERE b.provider_id = ?
     ORDER BY b.id DESC
     LIMIT 5"
);

$recent_stmt->bind_param("i", $provider_id);
$recent_stmt->execute();
$recent_result = $recent_stmt->get_result();

$recent_bookings = [];

while ($row = $recent_result->fetch_assoc()) {
    $recent_bookings[] = $row;
}

$recent_stmt->close();

// Helper functions
function provider_dashboard_status_class($status)
{
    $status = strtolower($status);

    return in_array($status, ["pending", "accepted", "rejected"], true)
        ? $status
        : "other";
}

function provider_dashboard_date($date)
{
    $timestamp = strtotime($date);
    return $timestamp ? date("d M Y", $timestamp) : $date;
}

function provider_dashboard_time($time)
{
    $timestamp = strtotime($time);
    return $timestamp ? date("g:i A", $timestamp) : $time;
}

function provider_dashboard_escape($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

// Provider initials
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

$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Provider Dashboard | HomeEase</title>

    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/provider.css">
</head>

<body class="provider-dashboard-page">

<div class="provider-dashboard-layout">

    <!-- SIDEBAR -->
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
                <?= provider_dashboard_escape($provider_initials) ?>
            </div>

            <div class="provider-profile-details">
                <h3><?= provider_dashboard_escape($provider_name) ?></h3>
                <p>Service Provider</p>
            </div>

        </div>

        <p class="provider-sidebar-label">Main Menu</p>

        <!-- Navigation -->
        <nav class="provider-sidebar-nav">

            <a href="dashboard.php" class="active" aria-current="page">
                <span class="provider-nav-icon">&#127968;</span>
                <span>Dashboard</span>
            </a>

            <a href="profile.php">
                <span class="provider-nav-icon">&#128100;</span>
                <span>My Profile</span>
            </a>

            <a href="services.php">
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

                <?php if ((int) $booking_counts["pending"] > 0): ?>
                    <span class="provider-nav-badge">
                        <?= (int) $booking_counts["pending"] > 99
                            ? "99+"
                            : (int) $booking_counts["pending"] ?>
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


    <!-- MAIN DASHBOARD -->
    <main class="provider-dashboard-content">

        <!-- Welcome Banner -->
        <section class="provider-dashboard-welcome">

            <div class="provider-dashboard-welcome-text">

                <p class="provider-dashboard-eyebrow">
                    YOUR PROVIDER WORKSPACE
                </p>

                <h2>
                    Welcome back,
                    <?= provider_dashboard_escape($provider_name) ?>!
                </h2>

                <p>
                    Manage your home services, keep your availability updated,
                    respond to customers and stay on top of your bookings.
                </p>

                <a href="bookings.php" class="provider-dashboard-primary">
                    Review booking requests &rarr;
                </a>

            </div>

            <!-- Date on the right side of the banner -->
            <div class="provider-dashboard-welcome-art">

                <div class="provider-dashboard-date">
                    <?= date("d M Y") ?>
                </div>

                <div class="provider-dashboard-welcome-icon" aria-hidden="true">
                    &#128736;
                </div>

            </div>

        </section>


        <!-- Statistics -->
        <section
            class="provider-dashboard-stats"
            aria-label="Your activity overview"
        >

            <article class="provider-dashboard-stat">

                <div class="provider-dashboard-stat-top">
                    <span class="provider-dashboard-stat-label">
                        Total Bookings
                    </span>

                    <span class="provider-dashboard-stat-icon">
                        &#128203;
                    </span>
                </div>

                <strong><?= (int) $booking_counts["total"] ?></strong>

                <span class="provider-dashboard-stat-note">
                    All customer requests
                </span>

            </article>


            <article class="provider-dashboard-stat">

                <div class="provider-dashboard-stat-top">
                    <span class="provider-dashboard-stat-label">
                        Pending Requests
                    </span>

                    <span class="provider-dashboard-stat-icon">
                        &#9203;
                    </span>
                </div>

                <strong><?= (int) $booking_counts["pending"] ?></strong>

                <span class="provider-dashboard-stat-note">
                    Awaiting your response
                </span>

            </article>


            <article class="provider-dashboard-stat">

                <div class="provider-dashboard-stat-top">
                    <span class="provider-dashboard-stat-label">
                        Accepted Bookings
                    </span>

                    <span class="provider-dashboard-stat-icon">
                        &#10003;
                    </span>
                </div>

                <strong><?= (int) $booking_counts["accepted"] ?></strong>

                <span class="provider-dashboard-stat-note">
                    Requests you've accepted
                </span>

            </article>


            <article class="provider-dashboard-stat">

                <div class="provider-dashboard-stat-top">
                    <span class="provider-dashboard-stat-label">
                        My Services
                    </span>

                    <span class="provider-dashboard-stat-icon">
                        &#128736;
                    </span>
                </div>

                <strong><?= $service_count ?></strong>

                <span class="provider-dashboard-stat-note">
                    Services listed by you
                </span>

            </article>

        </section>


        <!-- Customer Messages -->
        <section class="provider-dashboard-message-banner">

            <div class="provider-dashboard-message-info">

                <div class="provider-dashboard-message-icon">
                    &#128172;
                </div>

                <div>
                    <h3>Your Customer Messages</h3>

                    <p>
                        Open conversations, read customer enquiries and send replies.

                        <?php if ($unread_count > 0): ?>
                            You have <?= $unread_count ?>
                            unread message<?= $unread_count === 1 ? "" : "s" ?>.
                        <?php else: ?>
                            Check your inbox for new enquiries.
                        <?php endif; ?>
                    </p>
                </div>

            </div>

            <a href="messages.php" class="provider-dashboard-message-button">
                Open Messages

                <?php if ($unread_count > 0): ?>
                    (<?= $unread_count ?>)
                <?php endif; ?>
            </a>

        </section>


        <!-- Business Management -->
        <section class="provider-dashboard-section">

            <div class="provider-dashboard-section-heading">

                <div>
                    <h2>Manage Your Business</h2>

                    <p>
                        Keep your provider information complete and up to date.
                    </p>
                </div>

            </div>


            <div class="provider-dashboard-actions-grid">

                <article class="provider-dashboard-action-card">

                    <div class="provider-dashboard-action-icon">
                        &#128100;
                    </div>

                    <h3>My Profile</h3>

                    <p>
                        Update your location, description and proof of work
                        to help customers learn about your services.
                    </p>

                    <a href="profile.php" class="provider-dashboard-card-meta">
                        Update Profile &rarr;
                    </a>

                </article>


                <article class="provider-dashboard-action-card">

                    <div class="provider-dashboard-action-icon">
                        &#128736;
                    </div>

                    <h3>My Services</h3>

                    <p>
                        Add new services or review the services currently
                        listed on your provider profile.
                    </p>

                    <a href="services.php" class="provider-dashboard-card-meta">
                        Manage Services &rarr;
                    </a>

                </article>


                <article class="provider-dashboard-action-card">

                    <div class="provider-dashboard-action-icon">
                        &#128197;
                    </div>

                    <h3>My Availability</h3>

                    <p>
                        Set the days and times when customers can request
                        your services.
                    </p>

                    <span class="provider-dashboard-card-meta">
                        <?= $availability_count ?>
                        <?= $availability_count === 1
                            ? "availability entry"
                            : "availability entries" ?>
                    </span>

                    <a href="availability.php" class="provider-dashboard-card-meta">
                        Manage Availability &rarr;
                    </a>

                </article>


                <article class="provider-dashboard-action-card">

                    <div class="provider-dashboard-action-icon">
                        &#128221;
                    </div>

                    <h3>Booking Requests</h3>

                    <p>
                        Review customer appointments and accept or reject
                        requests that are still pending.
                    </p>

                    <span class="provider-dashboard-card-meta">
                        <?= (int) $booking_counts["pending"] ?>
                        pending
                        <?= (int) $booking_counts["pending"] === 1
                            ? "request"
                            : "requests" ?>
                    </span>

                    <a href="bookings.php" class="provider-dashboard-card-meta">
                        View Requests &rarr;
                    </a>

                </article>

            </div>

        </section>


        <!-- Recent Bookings -->
        <section class="provider-dashboard-section provider-dashboard-recent">

            <div class="provider-dashboard-section-heading">

                <div>
                    <h2>Recent Booking Requests</h2>

                    <p>
                        View customer details and open conversations where available.
                    </p>
                </div>

                <a href="bookings.php" class="provider-dashboard-text-link">
                    View All Bookings &rarr;
                </a>

            </div>


            <?php if (!empty($recent_bookings)): ?>

                <div class="provider-dashboard-recent-list">

                    <?php foreach ($recent_bookings as $booking): ?>

                        <?php
                        $status = strtolower($booking["status"]);
                        $status_class = provider_dashboard_status_class($status);
                        $conversation_id = (int) ($booking["conversation_id"] ?? 0);
                        ?>

                        <article class="provider-dashboard-recent-card">

                            <div class="provider-dashboard-recent-icon">
                                &#128221;
                            </div>


                            <div class="provider-dashboard-recent-info">

                                <h3>
                                    <?= provider_dashboard_escape($booking["service_name"]) ?>
                                </h3>

                                <p>
                                    Customer:

                                    <?php if ($conversation_id > 0): ?>

                                        <a
                                            class="provider-dashboard-customer-link"
                                            href="chat.php?conversation_id=<?= $conversation_id ?>"
                                        >
                                            <?= provider_dashboard_escape($booking["customer_name"]) ?>
                                        </a>

                                    <?php else: ?>

                                        <a
                                            class="provider-dashboard-customer-link"
                                            href="bookings.php"
                                        >
                                            <?= provider_dashboard_escape($booking["customer_name"]) ?>
                                        </a>

                                    <?php endif; ?>

                                </p>


                                <p>
                                    <?= provider_dashboard_escape(
                                        provider_dashboard_date($booking["booking_date"])
                                    ) ?>

                                    at

                                    <?= provider_dashboard_escape(
                                        provider_dashboard_time($booking["booking_time"])
                                    ) ?>
                                </p>


                                <?php if ($conversation_id > 0): ?>

                                    <a
                                        class="provider-dashboard-message-action"
                                        href="chat.php?conversation_id=<?= $conversation_id ?>"
                                    >
                                        Open Conversation &rarr;
                                    </a>

                                <?php else: ?>

                                    <a
                                        class="provider-dashboard-message-action"
                                        href="messages.php"
                                    >
                                        View Messages &rarr;
                                    </a>

                                <?php endif; ?>

                            </div>


                            <span class="provider-dashboard-status <?= provider_dashboard_escape($status_class) ?>">
                                <?= provider_dashboard_escape(ucfirst($status)) ?>
                            </span>

                        </article>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <div class="provider-dashboard-empty">

                    <div class="provider-dashboard-empty-icon">
                        &#128221;
                    </div>

                    <h3>No Booking Requests Yet</h3>

                    <p>
                        New customer requests will appear here when someone
                        books one of your listed services.
                    </p>

                    <a href="services.php" class="provider-dashboard-primary">
                        Review My Services
                    </a>

                </div>

            <?php endif; ?>

        </section>

    </main>

</div>

</body>
</html>
