
<?php
session_start();

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "customer"
) {
    header("Location: ../login.php");
    exit;
}

require_once "../config/database.php";

$customer_id = (int) $_SESSION["user_id"];
$customer_name = $_SESSION["full_name"] ?? "Customer";

$total_bookings = 0;
$pending_bookings = 0;
$accepted_bookings = 0;
$rejected_bookings = 0;
$unread_messages = 0;

// Get booking statistics.
$sql = "SELECT status, COUNT(*) AS total
        FROM bookings
        WHERE customer_id = ?
        GROUP BY status";

$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $count = (int) $row["total"];
        $total_bookings += $count;

        if ($row["status"] === "pending") {
            $pending_bookings = $count;
        } elseif ($row["status"] === "accepted") {
            $accepted_bookings = $count;
        } elseif ($row["status"] === "rejected") {
            $rejected_bookings = $count;
        }
    }

    $stmt->close();
}

// Count unread messages sent by providers.
$sql = "SELECT COUNT(*) AS unread_total
        FROM messages m
        INNER JOIN conversations c
            ON m.conversation_id = c.id
        WHERE c.customer_id = ?
          AND m.sender_id = c.provider_id
          AND m.is_read = 0";

$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param("i", $customer_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($row = $result->fetch_assoc()) {
        $unread_messages = (int) $row["unread_total"];
    }

    $stmt->close();
}

$display_name = htmlspecialchars(
    $customer_name,
    ENT_QUOTES,
    "UTF-8"
);

$message_badge = $unread_messages > 99
    ? "99+"
    : $unread_messages;
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Customer Dashboard | HomeEase</title>

    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/customer.css">
</head>

<body class="customer-dashboard-page">

<div class="customer-layout">

    <!-- CUSTOMER SIDEBAR -->
    <aside class="customer-sidebar">

        <a href="../index.php" class="customer-brand">
            <span class="customer-brand-mark">H</span>

            <span class="customer-brand-name">
                Home<span>Ease</span>
            </span>
        </a>

        <div class="sidebar-user">
            <div class="sidebar-user-avatar">
                <?= strtoupper(substr($display_name, 0, 1)) ?>
            </div>

            <div class="sidebar-user-details">
                <strong><?= $display_name ?></strong>
                <span>Customer Account</span>
            </div>
        </div>

        <div class="sidebar-section-label">
            MAIN MENU
        </div>

        <nav class="customer-sidebar-nav">

            <a href="dashboard.php"
               class="sidebar-link active"
               aria-current="page">
                <span class="sidebar-icon">⌂</span>
                <span>Dashboard</span>
            </a>

            <a href="search.php" class="sidebar-link">
                <span class="sidebar-icon">⌕</span>
                <span>Find a Service</span>
            </a>

            <a href="bookings.php" class="sidebar-link">
                <span class="sidebar-icon">▤</span>
                <span>My Bookings</span>
            </a>

            <a href="messages.php" class="sidebar-link">
                <span class="sidebar-icon">✉</span>
                <span>My Messages</span>

                <?php if ($unread_messages > 0): ?>
                    <span class="sidebar-message-badge">
                        <?= $message_badge ?>
                    </span>
                <?php endif; ?>
            </a>

        </nav>

        <div class="sidebar-section-label sidebar-bottom-label">
            ACCOUNT
        </div>

        <nav class="customer-sidebar-nav">

            <a href="../login.php" class="sidebar-link">
                <span class="sidebar-icon">⚙</span>
                <span>Account</span>
            </a>

        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-footer-mark">H</div>

            <div>
                <strong>HomeEase</strong>
                <span>Services made easier.</span>
            </div>
        </div>

    </aside>


    <!-- MAIN DASHBOARD CONTENT -->
    <main class="customer-main-content">


        <!-- WELCOME SECTION -->
        <section class="customer-welcome">

            <div class="welcome-content">
                <span class="welcome-label">
                    YOUR HOME, YOUR CONVENIENCE
                </span>

                <h2>
                    Welcome back, <?= $display_name ?>!
                </h2>

                <p>
                    What do you need help with today? Find trusted
                    service providers, manage your bookings and
                    communicate directly with providers.
                </p>

                <a href="search.php" class="customer-primary-btn">
                    Find a Service <span>→</span>
                </a>
            </div>

            <div class="welcome-decoration" aria-hidden="true">
                <span>H</span>
            </div>

        </section>


        <!-- BOOKING STATISTICS -->
        <section class="customer-dashboard-section">

            <div class="customer-section-heading">
                <div>
                    <span class="section-eyebrow">AT A GLANCE</span>
                    <h2>Your Booking Overview</h2>
                    <p>Keep track of your service requests.</p>
                </div>

                <a href="bookings.php" class="customer-view-link">
                    View all bookings →
                </a>
            </div>


            <div class="customer-stats-grid">

                <article class="customer-stat-card">
                    <div class="customer-stat-top">
                        <span class="customer-stat-icon green-icon">▤</span>
                        <span class="customer-stat-label">TOTAL BOOKINGS</span>
                    </div>

                    <strong><?= $total_bookings ?></strong>
                    <p>All your service requests</p>
                </article>


                <article class="customer-stat-card">
                    <div class="customer-stat-top">
                        <span class="customer-stat-icon burgundy-icon">◷</span>
                        <span class="customer-stat-label">PENDING</span>
                    </div>

                    <strong><?= $pending_bookings ?></strong>
                    <p>Awaiting provider response</p>
                </article>


                <article class="customer-stat-card">
                    <div class="customer-stat-top">
                        <span class="customer-stat-icon green-icon">✓</span>
                        <span class="customer-stat-label">ACCEPTED</span>
                    </div>

                    <strong><?= $accepted_bookings ?></strong>
                    <p>Approved service requests</p>
                </article>


                <article class="customer-stat-card">
                    <div class="customer-stat-top">
                        <span class="customer-stat-icon neutral-icon">↺</span>
                        <span class="customer-stat-label">REJECTED</span>
                    </div>

                    <strong><?= $rejected_bookings ?></strong>
                    <p>Requests not accepted</p>
                </article>

            </div>

        </section>


        <!-- CUSTOMER QUICK ACTIONS -->
        <section class="customer-dashboard-section">

            <div class="customer-section-heading">
                <div>
                    <span class="section-eyebrow">GET STARTED</span>
                    <h2>What would you like to do?</h2>
                    <p>Everything you need is just a click away.</p>
                </div>
            </div>


            <div class="customer-action-grid">

                <article class="customer-action-card">

                    <div class="customer-action-icon green-icon">
                        ⌕
                    </div>

                    <h3>Find a Service Provider</h3>

                    <p>
                        Search for service providers by service type
                        and location to find help that suits your needs.
                    </p>

                    <a href="search.php" class="customer-action-link">
                        Search services <span>→</span>
                    </a>

                </article>


                <article class="customer-action-card">

                    <div class="customer-action-icon burgundy-icon">
                        ▤
                    </div>

                    <h3>Manage My Bookings</h3>

                    <p>
                        Review your appointments and check whether
                        each request is pending, accepted or rejected.
                    </p>

                    <a href="bookings.php" class="customer-action-link">
                        View my bookings <span>→</span>
                    </a>

                </article>


                <article class="customer-action-card">

                    <div class="customer-action-icon burgundy-icon">
                        ✉
                    </div>

                    <h3>My Messages</h3>

                    <?php if ($unread_messages > 0): ?>
                        <span class="customer-unread-label">
                            <?= $unread_messages ?> unread messages
                        </span>
                    <?php endif; ?>

                    <p>
                        Communicate with service providers, read their
                        replies and discuss your service requirements.
                    </p>

                    <a href="messages.php" class="customer-action-link">
                        Open messages <span>→</span>
                    </a>

                </article>

            </div>

        </section>


        <!-- HELP SECTION -->
        <section class="customer-help-section">

            <div class="help-section-icon">✦</div>

            <div class="help-section-content">
                <h2>Need help around your home?</h2>

                <p>
                    Find the right service provider and make your
                    next booking through HomeEase.
                </p>
            </div>

            <a href="search.php" class="customer-help-btn">
                Explore Services →
            </a>

        </section>

    </main>

</div>

</body>
</html>
