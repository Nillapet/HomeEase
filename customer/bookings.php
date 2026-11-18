
<?php
session_start();

require_once "../config/database.php";

// Only logged-in customers may access this page.
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "customer"
) {
    header("Location: ../login.php");
    exit;
}

$customer_id = (int) $_SESSION["user_id"];
$customer_name = $_SESSION["full_name"] ?? "Customer";

// Retrieve the customer's bookings and provider details.
$sql = "SELECT
            bookings.id,
            bookings.provider_id,
            users.full_name AS provider_name,
            services.service_name,
            bookings.booking_date,
            bookings.booking_time,
            bookings.status
        FROM bookings
        INNER JOIN users
            ON bookings.provider_id = users.id
        INNER JOIN services
            ON bookings.service_id = services.id
        WHERE bookings.customer_id = ?
        ORDER BY bookings.booking_date DESC,
                 bookings.booking_time DESC,
                 bookings.id DESC";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Unable to load your bookings at the moment.");
}

$stmt->bind_param("i", $customer_id);
$stmt->execute();

$result = $stmt->get_result();

$bookings = [];

$counts = [
    "total" => 0,
    "pending" => 0,
    "accepted" => 0,
    "rejected" => 0
];

while ($booking = $result->fetch_assoc()) {
    $bookings[] = $booking;

    $counts["total"]++;

    $status_key = strtolower(trim($booking["status"]));

    if (isset($counts[$status_key])) {
        $counts[$status_key]++;
    }
}

$stmt->close();

function booking_status_class($status) {
    $status = strtolower(trim($status));

    if ($status === "accepted") {
        return "status-accepted";
    }

    if ($status === "rejected") {
        return "status-rejected";
    }

    return "status-pending";
}

function booking_display_date($date) {
    $timestamp = strtotime($date);

    return $timestamp !== false
        ? date("D, d M Y", $timestamp)
        : $date;
}

function booking_display_time($time) {
    $timestamp = strtotime($time);

    return $timestamp !== false
        ? date("g:i A", $timestamp)
        : $time;
}

function booking_escape($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}

// Generate customer initials for the sidebar.
$name_parts = preg_split('/\s+/', trim($customer_name));
$customer_initials = "";

foreach (array_slice($name_parts, 0, 2) as $part) {
    $customer_initials .= strtoupper(substr($part, 0, 1));
}

if ($customer_initials === "") {
    $customer_initials = "C";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Bookings | HomeEase</title>

    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/customer.css">

    <style>
        /* Booking page content */

        .booking-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid #edf0ee;
            flex-wrap: wrap;
        }

        .booking-action-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            padding: 11px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: 0.2s ease;
        }

        .booking-profile-btn {
            background: #edf5f1;
            color: #176b57;
            border: 1px solid #d6e8df;
        }

        .booking-profile-btn:hover {
            background: #dcece4;
        }

        .booking-message-btn {
            background: #176b57;
            color: #ffffff;
            border: 1px solid #176b57;
        }

        .booking-message-btn:hover {
            background: #105442;
            border-color: #105442;
        }

        .bookings-content {
            width: 100%;
        }

        .bookings-page-heading {
            margin-bottom: 25px;
        }

        .bookings-page-heading h1 {
            margin-bottom: 8px;
        }

        .bookings-page-heading p {
            color: #777;
            margin: 0;
        }

        .booking-summary {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 16px;
            margin-bottom: 32px;
        }

        .booking-stat-card {
            background: #ffffff;
            border: 1px solid #e9e9e3;
            border-radius: 12px;
            padding: 20px;
        }

        .booking-stat-label,
        .booking-stat-note {
            display: block;
            color: #777;
            font-size: 13px;
        }

        .booking-stat-card strong {
            display: block;
            font-size: 28px;
            color: #174d38;
            margin: 9px 0;
        }

        .pending-stat strong {
            color: #a66b16;
        }

        .accepted-stat strong {
            color: #176b57;
        }

        .rejected-stat strong {
            color: #a33b45;
        }

        .booking-list-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 20px;
        }

        .booking-list-heading h2 {
            font-size: 20px;
            margin: 0 0 6px;
        }

        .booking-list-heading p {
            margin: 0;
            color: #777;
            font-size: 14px;
        }

        .booking-total-label {
            background: #e8f0eb;
            color: #174d38;
            padding: 8px 13px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 600;
            white-space: nowrap;
        }

        .booking-cards-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
        }

        .customer-booking-card {
            background: #ffffff;
            border: 1px solid #e9e9e3;
            border-radius: 12px;
            padding: 22px;
        }

        .customer-booking-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
        }

        .booking-service-label {
            color: #777;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.8px;
        }

        .booking-status {
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
        }

        .status-pending {
            background: #fff3dc;
            color: #94620e;
        }

        .status-accepted {
            background: #e4f2e9;
            color: #176b45;
        }

        .status-rejected {
            background: #f8e8e8;
            color: #a33b45;
        }

        .customer-booking-card h3 {
            font-size: 18px;
            color: #292d26;
            margin: 0 0 18px;
        }

        .booking-provider,
        .booking-detail,
        .booking-detail-row {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .booking-provider {
            margin-bottom: 18px;
        }

        .booking-detail-row {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            padding: 15px 0;
            border-top: 1px solid #edf0ee;
            border-bottom: 1px solid #edf0ee;
        }

        .booking-detail-icon {
            font-size: 18px;
        }

        .booking-detail-label {
            display: block;
            color: #777;
            font-size: 11px;
            margin-bottom: 5px;
        }

        .booking-provider strong,
        .booking-detail strong {
            display: block;
            color: #292d26;
            font-size: 13px;
        }

        .booking-status-note {
            color: #777;
            font-size: 13px;
            line-height: 1.6;
            margin-top: 15px;
        }

        .customer-bookings-empty {
            background: #ffffff;
            border: 1px solid #e9e9e3;
            border-radius: 12px;
            padding: 45px 25px;
            text-align: center;
        }

        .booking-empty-icon {
            font-size: 35px;
            margin-bottom: 12px;
        }

        .customer-bookings-empty h3 {
            color: #292d26;
            margin-bottom: 10px;
        }

        .customer-bookings-empty p {
            color: #777;
            font-size: 14px;
            line-height: 1.7;
            max-width: 480px;
            margin: 0 auto 20px;
        }

        .bookings-primary-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 11px 17px;
            background: #174d38;
            color: #ffffff;
            border-radius: 8px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: 0.2s ease;
        }

        .bookings-primary-btn:hover {
            background: #103b2b;
        }

        .bookings-back-link {
            margin-top: 25px;
        }

        .bookings-back-link a {
            color: #174d38;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
        }

        .bookings-back-link a:hover {
            text-decoration: underline;
        }

        @media (max-width: 900px) {
            .booking-summary {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .booking-cards-grid {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 480px) {
            .booking-summary {
                grid-template-columns: 1fr 1fr;
                gap: 10px;
            }

            .booking-stat-card {
                padding: 14px;
            }

            .booking-stat-card strong {
                font-size: 23px;
            }

            .booking-detail-row {
                grid-template-columns: 1fr;
                gap: 15px;
            }

            .booking-actions {
                flex-direction: column;
            }

            .booking-action-btn {
                width: 100%;
            }

            .booking-list-heading {
                align-items: flex-start;
            }
        }
    </style>
</head>

<body class="customer-dashboard-page">

    <div class="customer-layout">

        <!-- CUSTOMER SIDEBAR -->
        <aside class="customer-sidebar">

            <a href="dashboard.php" class="customer-brand">
                <span class="customer-brand-mark">H</span>
                <span class="customer-brand-name">HomeEase</span>
            </a>

            <div class="sidebar-user">
                <div class="sidebar-user-avatar">
                    <?= booking_escape($customer_initials) ?>
                </div>

                <div class="sidebar-user-details">
                    <strong><?= booking_escape($customer_name) ?></strong>
                    <span>Customer</span>
                </div>
            </div>

            <span class="sidebar-section-label">MENU</span>

            <nav class="customer-sidebar-nav">

                <a href="dashboard.php" class="sidebar-link">
                    <span class="sidebar-icon">⌂</span>
                    <span>Dashboard</span>
                </a>

                <a href="search.php" class="sidebar-link">
                    <span class="sidebar-icon">⌕</span>
                    <span>Find a Service</span>
                </a>

                <a href="bookings.php" class="sidebar-link active" aria-current="page">
                    <span class="sidebar-icon">▣</span>
                    <span>My Bookings</span>
                </a>

                <a href="messages.php" class="sidebar-link">
                    <span class="sidebar-icon">✉</span>
                    <span>My Messages</span>
                </a>

            </nav>

            <div class="sidebar-footer">
                <div class="sidebar-footer-mark">H</div>
                <span>HomeEase Services</span>
            </div>

        </aside>


        <!-- MAIN CONTENT -->
        <main class="customer-main-content">

            <div class="bookings-content">

                <section class="bookings-page-heading">

                    <h1>My Bookings</h1>

                    <p>
                        Keep track of your service requests and appointment updates.
                    </p>

                </section>


                <!-- BOOKING SUMMARY -->
                <section class="booking-summary">

                    <article class="booking-stat-card">
                        <span class="booking-stat-label">Total Bookings</span>
                        <strong><?= $counts["total"] ?></strong>
                        <span class="booking-stat-note">All your requests</span>
                    </article>

                    <article class="booking-stat-card pending-stat">
                        <span class="booking-stat-label">Pending</span>
                        <strong><?= $counts["pending"] ?></strong>
                        <span class="booking-stat-note">Awaiting a response</span>
                    </article>

                    <article class="booking-stat-card accepted-stat">
                        <span class="booking-stat-label">Accepted</span>
                        <strong><?= $counts["accepted"] ?></strong>
                        <span class="booking-stat-note">Confirmed by providers</span>
                    </article>

                    <article class="booking-stat-card rejected-stat">
                        <span class="booking-stat-label">Rejected</span>
                        <strong><?= $counts["rejected"] ?></strong>
                        <span class="booking-stat-note">Not accepted by providers</span>
                    </article>

                </section>


                <!-- BOOKING LIST -->
                <section class="booking-list-section">

                    <div class="booking-list-heading">

                        <div>
                            <h2>Your appointment history</h2>
                            <p>
                                Review the details and status of each request.
                            </p>
                        </div>

                        <span class="booking-total-label">
                            <?= $counts["total"] ?>
                            <?= $counts["total"] === 1 ? "booking" : "bookings" ?>
                        </span>

                    </div>


                    <?php if (!empty($bookings)): ?>

                        <div class="booking-cards-grid">

                            <?php foreach ($bookings as $booking): ?>

                                <?php
                                $status = ucfirst(
                                    strtolower(trim($booking["status"]))
                                );

                                $provider_id = (int) $booking["provider_id"];
                                ?>

                                <article class="customer-booking-card">

                                    <div class="customer-booking-top">

                                        <span class="booking-service-label">
                                            SERVICE REQUEST
                                        </span>

                                        <span class="booking-status <?= booking_status_class($booking["status"]) ?>">
                                            <?= booking_escape($status) ?>
                                        </span>

                                    </div>

                                    <h3>
                                        <?= booking_escape($booking["service_name"]) ?>
                                    </h3>

                                    <div class="booking-provider">

                                        <span class="booking-detail-icon" aria-hidden="true">
                                            👤
                                        </span>

                                        <div>
                                            <span class="booking-detail-label">
                                                Service Provider
                                            </span>

                                            <strong>
                                                <?= booking_escape($booking["provider_name"]) ?>
                                            </strong>
                                        </div>

                                    </div>

                                    <div class="booking-detail-row">

                                        <div class="booking-detail">

                                            <span class="booking-detail-icon" aria-hidden="true">
                                                📅
                                            </span>

                                            <div>
                                                <span class="booking-detail-label">
                                                    Appointment Date
                                                </span>

                                                <strong>
                                                    <?= booking_escape(
                                                        booking_display_date($booking["booking_date"])
                                                    ) ?>
                                                </strong>
                                            </div>

                                        </div>

                                        <div class="booking-detail">

                                            <span class="booking-detail-icon" aria-hidden="true">
                                                🕒
                                            </span>

                                            <div>
                                                <span class="booking-detail-label">
                                                    Appointment Time
                                                </span>

                                                <strong>
                                                    <?= booking_escape(
                                                        booking_display_time($booking["booking_time"])
                                                    ) ?>
                                                </strong>
                                            </div>

                                        </div>

                                    </div>

                                    <div class="booking-status-note">

                                        <?php if (strtolower($status) === "accepted"): ?>

                                            Your provider has accepted this booking.

                                        <?php elseif (strtolower($status) === "rejected"): ?>

                                            This booking was not accepted. You can search for another provider.

                                        <?php else: ?>

                                            Your request is awaiting the provider's response.

                                        <?php endif; ?>

                                    </div>

                                    <div class="booking-actions">

                                        <a
                                            href="provider.php?id=<?= $provider_id ?>"
                                            class="booking-action-btn booking-profile-btn"
                                        >
                                            <span aria-hidden="true">👤</span>
                                            View Provider Profile
                                        </a>

                                        <a
                                            href="chat.php?provider_id=<?= $provider_id ?>"
                                            class="booking-action-btn booking-message-btn"
                                        >
                                            <span aria-hidden="true">💬</span>
                                            Message Provider
                                        </a>

                                    </div>

                                </article>

                            <?php endforeach; ?>

                        </div>


                    <?php else: ?>

                        <div class="customer-bookings-empty">

                            <div class="booking-empty-icon" aria-hidden="true">
                                📅
                            </div>

                            <h3>You haven't made any bookings yet</h3>

                            <p>
                                When you book a service, its details and status
                                will appear here. Start by finding a provider
                                in your area.
                            </p>

                            <a href="search.php" class="bookings-primary-btn">
                                Find a Service
                            </a>

                        </div>

                    <?php endif; ?>

                </section>


                <div class="bookings-back-link">
                    <a href="dashboard.php">← Back to Dashboard</a>
                </div>

            </div>

        </main>

    </div>

</body>
</html>
