
<?php

session_start();

require_once "../config/database.php";

// Only administrators can access this page.
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: ../login.php");
    exit;
}


// Safely display database values.
function escapeOutput($value)
{
    return htmlspecialchars(
        (string)($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}


// Format appointment dates.
function formatBookingDate($date)
{
    if (empty($date)) {
        return "Not provided";
    }

    $timestamp = strtotime($date);

    return $timestamp === false
        ? "Not provided"
        : date("d M Y", $timestamp);
}


// Format appointment times.
function formatBookingTime($time)
{
    if (empty($time)) {
        return "Not provided";
    }

    $timestamp = strtotime($time);

    return $timestamp === false
        ? "Not provided"
        : date("h:i A", $timestamp);
}


// Get readable booking status.
function statusLabel($status)
{
    return ucfirst(strtolower((string)$status));
}


// Get a safe CSS class for booking status.
function getStatusClass($status)
{
    switch (strtolower((string)$status)) {

        case "accepted":
            return "accepted";

        case "rejected":
            return "rejected";

        case "completed":
            return "completed";

        case "cancelled":
            return "cancelled";

        case "pending":
            return "pending";

        default:
            return "default";
    }
}


// Get administrator details.
$adminName = $_SESSION["full_name"] ?? "Administrator";
$firstName = explode(" ", trim($adminName))[0];


// Validate the booking status filter.
$allowedStatuses = [
    "all",
    "pending",
    "accepted",
    "rejected"
];

$selectedStatus = $_GET["status"] ?? "all";

if (!in_array($selectedStatus, $allowedStatuses, true)) {
    $selectedStatus = "all";
}


// Get search term.
$searchTerm = trim($_GET["search"] ?? "");
$searchPattern = "%" . $searchTerm . "%";


// Retrieve overall booking statistics.
$statisticsSql = "
    SELECT
        COUNT(*) AS total_bookings,
        COALESCE(SUM(status = 'pending'), 0) AS pending_bookings,
        COALESCE(SUM(status = 'accepted'), 0) AS accepted_bookings,
        COALESCE(SUM(status = 'rejected'), 0) AS rejected_bookings
    FROM bookings
";

$statisticsResult = $conn->query($statisticsSql);

$statistics = [
    "total_bookings" => 0,
    "pending_bookings" => 0,
    "accepted_bookings" => 0,
    "rejected_bookings" => 0
];

if ($statisticsResult) {
    $statistics = $statisticsResult->fetch_assoc();
}


// Retrieve bookings using the selected filters.
$sql = "
    SELECT
        b.id,
        b.booking_date,
        b.booking_time,
        b.status,
        b.created_at,
        c.full_name AS customer_name,
        c.email AS customer_email,
        p.full_name AS provider_name,
        s.service_name
    FROM bookings b
    INNER JOIN users c ON b.customer_id = c.id
    INNER JOIN users p ON b.provider_id = p.id
    INNER JOIN services s ON b.service_id = s.id
    WHERE
        (? = 'all' OR b.status = ?)
        AND (
            ? = ''
            OR c.full_name LIKE ?
            OR c.email LIKE ?
            OR p.full_name LIKE ?
            OR s.service_name LIKE ?
        )
    ORDER BY b.created_at DESC
";

$stmt = $conn->prepare($sql);

$bookings = [];
$error = "";

if (!$stmt) {

    $error = "Unable to prepare the bookings query.";

} else {

    $stmt->bind_param(
        "sssssss",
        $selectedStatus,
        $selectedStatus,
        $searchTerm,
        $searchPattern,
        $searchPattern,
        $searchPattern,
        $searchPattern
    );

    if ($stmt->execute()) {

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $bookings[] = $row;
        }

    } else {

        $error = "Unable to retrieve bookings.";

    }

    $stmt->close();
}

$totalDisplayed = count($bookings);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Booking Management | HomeEase Admin</title>

    <link rel="stylesheet" href="../css/admin.css">

    <style>

        :root {
            --burgundy: #450011;
            --burgundy-dark: #2D000B;
            --burgundy-light: #F4E9EC;
            --ivory: #FAF7F3;
            --white: #FFFFFF;
            --text: #30272A;
            --muted: #82777A;
            --border: #EEE5E5;
            --shadow: 0 8px 25px rgba(69, 0, 17, 0.06);
        }

        * {
            box-sizing: border-box;
        }

        body.admin-bookings-page {
            margin: 0;
            background:
                radial-gradient(
                    circle at 90% 5%,
                    rgba(69, 0, 17, 0.045),
                    transparent 25%
                ),
                var(--ivory);
            color: var(--text);
            font-family: Arial, Helvetica, sans-serif;
        }


        /* MAIN LAYOUT */

        .bookings-layout {
            display: flex;
            min-height: 100vh;
        }


        /* SIDEBAR */

        .bookings-sidebar {
            width: 255px;
            min-width: 255px;
            background: var(--burgundy-dark);
            color: white;
            padding: 30px 18px;
            display: flex;
            flex-direction: column;
        }

        .bookings-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 12px 35px;
            text-decoration: none;
            color: white;
        }

        .bookings-brand-icon {
            width: 43px;
            height: 43px;
            display: grid;
            place-items: center;
            border-radius: 13px;
            background: rgba(255,255,255,0.12);
            font-size: 27px;
        }

        .bookings-brand-copy strong {
            display: block;
            font-size: 21px;
            letter-spacing: -0.7px;
        }

        .bookings-brand-copy strong span {
            color: #D9AAB6;
        }

        .bookings-brand-copy small {
            display: block;
            margin-top: 4px;
            font-size: 9px;
            letter-spacing: 2px;
            color: #D2BFC4;
        }

        .sidebar-label {
            padding: 0 13px;
            margin: 8px 0 13px;
            color: #BBA5AB;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.6px;
        }

        .bookings-nav {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .bookings-nav a {
            display: flex;
            align-items: center;
            gap: 13px;
            padding: 13px 14px;
            border-radius: 9px;
            color: #E7DADF;
            font-size: 13px;
            text-decoration: none;
            transition: 0.2s ease;
        }

        .bookings-nav a:hover {
            background: rgba(255,255,255,0.09);
            color: white;
        }

        .bookings-nav a.active {
            background: white;
            color: var(--burgundy);
            font-weight: 700;
        }

        .nav-icon {
            width: 20px;
            text-align: center;
            font-size: 16px;
        }

        .sidebar-bottom {
            margin-top: auto;
            padding-top: 30px;
        }

        .sidebar-bottom a {
            display: block;
            padding: 12px 14px;
            color: #E7DADF;
            text-decoration: none;
            font-size: 13px;
            border-radius: 9px;
        }

        .sidebar-bottom a:hover {
            background: rgba(255,255,255,0.09);
        }

        .sidebar-bottom .logout-link {
            color: #F0B8C5;
            margin-top: 5px;
        }


        /* MAIN CONTENT */

        .bookings-main {
            flex: 1;
            min-width: 0;
            padding: 30px 38px;
        }

        .bookings-topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            margin-bottom: 38px;
        }

        .topbar-label {
            color: var(--muted);
            font-size: 12px;
        }

        .admin-greeting {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .admin-avatar {
            width: 42px;
            height: 42px;
            display: grid;
            place-items: center;
            background: var(--burgundy-light);
            color: var(--burgundy);
            border-radius: 50%;
            font-weight: 700;
            font-size: 15px;
        }

        .admin-greeting strong {
            display: block;
            font-size: 13px;
        }

        .admin-greeting small {
            display: block;
            margin-top: 4px;
            color: var(--muted);
            font-size: 11px;
        }


        /* PAGE INTRODUCTION */

        .page-intro {
            margin-bottom: 28px;
        }

        .section-kicker {
            display: inline-block;
            margin-bottom: 10px;
            color: var(--burgundy);
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.8px;
        }

        .page-intro h1 {
            margin: 0;
            color: var(--burgundy);
            font-size: 29px;
            letter-spacing: -0.8px;
        }

        .page-intro p {
            margin: 10px 0 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.7;
        }


        /* STATISTICS */

        .booking-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 17px;
            margin-bottom: 30px;
        }

        .booking-stat-card {
            background: var(--white);
            padding: 21px;
            border-radius: 13px;
            box-shadow: var(--shadow);
            border: 1px solid rgba(69,0,17,0.04);
        }

        .stat-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }

        .stat-top span:first-child {
            color: var(--muted);
            font-size: 12px;
            font-weight: 600;
        }

        .stat-icon {
            width: 35px;
            height: 35px;
            display: grid;
            place-items: center;
            border-radius: 10px;
            background: var(--burgundy-light);
            color: var(--burgundy);
            font-size: 16px;
        }

        .stat-number {
            margin: 16px 0 5px;
            color: var(--burgundy);
            font-size: 29px;
            font-weight: 700;
        }

        .stat-caption {
            color: var(--muted);
            font-size: 11px;
        }


        /* BOOKING DIRECTORY */

        .booking-panel {
            background: var(--white);
            border: 1px solid rgba(69,0,17,0.05);
            border-radius: 15px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .panel-heading {
            padding: 24px 25px 18px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
        }

        .panel-heading h2 {
            margin: 0;
            color: var(--burgundy);
            font-size: 18px;
        }

        .panel-heading p {
            margin: 7px 0 0;
            color: var(--muted);
            font-size: 12px;
        }

        .booking-count {
            padding: 8px 12px;
            background: var(--burgundy-light);
            color: var(--burgundy);
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }


        /* FILTERS */

        .filter-area {
            margin: 0 25px 20px;
            padding: 18px;
            background: #FBF9F8;
            border: 1px solid var(--border);
            border-radius: 11px;
        }

        .filter-form {
            display: grid;
            grid-template-columns: minmax(180px, 1fr) 210px auto auto;
            align-items: end;
            gap: 12px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #514448;
            font-size: 11px;
            font-weight: 700;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            height: 42px;
            padding: 0 12px;
            border: 1px solid #E6DCDD;
            border-radius: 7px;
            background: white;
            color: var(--text);
            font-size: 12px;
            outline: none;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: var(--burgundy);
            box-shadow: 0 0 0 3px rgba(69,0,17,0.08);
        }

        .filter-button,
        .clear-button {
            height: 42px;
            padding: 0 17px;
            display: inline-flex;
            justify-content: center;
            align-items: center;
            border-radius: 7px;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            white-space: nowrap;
        }

        .filter-button {
            border: none;
            background: var(--burgundy);
            color: white;
        }

        .filter-button:hover {
            background: var(--burgundy-dark);
        }

        .clear-button {
            border: 1px solid #E5D9DC;
            background: white;
            color: var(--burgundy);
        }

        .clear-button:hover {
            background: var(--burgundy-light);
        }


        /* BOOKING TABLE */

        .table-scroll {
            width: 100%;
            overflow-x: auto;
        }

        .bookings-table {
            width: 100%;
            min-width: 1050px;
            border-collapse: collapse;
        }

        .bookings-table thead {
            background: #FBF8F7;
        }

        .bookings-table th {
            padding: 15px 16px;
            color: #796B70;
            font-size: 10px;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.7px;
            white-space: nowrap;
        }

        .bookings-table td {
            padding: 16px;
            border-top: 1px solid #F1EAEA;
            color: #55494D;
            font-size: 12px;
            vertical-align: middle;
        }

        .bookings-table tbody tr:hover {
            background: #FDFBFA;
        }

        .booking-id {
            color: var(--burgundy);
            font-weight: 700;
        }

        .customer-name {
            color: #35272C;
            font-weight: 700;
            white-space: nowrap;
        }

        .customer-email {
            display: block;
            margin-top: 5px;
            color: var(--muted);
            font-size: 10px;
        }

        .service-name {
            min-width: 130px;
            color: #35272C;
            font-weight: 600;
        }

        .provider-name {
            min-width: 130px;
        }

        .appointment-date {
            min-width: 110px;
            white-space: nowrap;
        }

        .appointment-time {
            white-space: nowrap;
        }

        .status-badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
        }

        .status-badge.pending {
            background: #FFF3D9;
            color: #95671A;
        }

        .status-badge.accepted {
            background: #E8F3EA;
            color: #367347;
        }

        .status-badge.rejected {
            background: #FCEBED;
            color: #A23A4C;
        }

        .status-badge.completed {
            background: #EAF2FC;
            color: #315E91;
        }

        .status-badge.cancelled {
            background: #F0EEEE;
            color: #6B6063;
        }

        .status-badge.default {
            background: #F0EEEE;
            color: #6B6063;
        }

        .empty-message {
            padding: 40px 20px !important;
            text-align: center;
            color: var(--muted) !important;
            line-height: 1.7;
        }

        .error-message {
            margin: 20px 25px;
            padding: 15px;
            border-radius: 8px;
            background: #FCEBED;
            color: #922D3E;
            font-size: 13px;
        }


        /* FOOTER */

        .bookings-footer {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            margin-top: 28px;
            padding: 20px 0;
            border-top: 1px solid #E9DFDF;
            color: var(--muted);
            font-size: 11px;
        }


        /* RESPONSIVE */

        @media (max-width: 1100px) {

            .bookings-sidebar {
                width: 220px;
                min-width: 220px;
            }

            .bookings-main {
                padding: 25px;
            }

            .booking-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .filter-form {
                grid-template-columns: 1fr 1fr;
            }

        }

        @media (max-width: 700px) {

            .bookings-layout {
                flex-direction: column;
            }

            .bookings-sidebar {
                width: 100%;
                min-width: 0;
                padding: 18px;
            }

            .bookings-brand {
                padding: 0 5px 18px;
            }

            .sidebar-label,
            .sidebar-bottom {
                display: none;
            }

            .bookings-nav {
                flex-direction: row;
                overflow-x: auto;
                padding-bottom: 5px;
            }

            .bookings-nav a {
                flex: 0 0 auto;
                padding: 10px 12px;
                font-size: 11px;
            }

            .bookings-main {
                padding: 22px 15px;
            }

            .bookings-topbar {
                margin-bottom: 28px;
            }

            .page-intro h1 {
                font-size: 25px;
            }

            .booking-stats {
                gap: 10px;
            }

            .booking-stat-card {
                padding: 15px;
            }

            .stat-number {
                font-size: 25px;
            }

            .filter-form {
                grid-template-columns: 1fr;
            }

            .panel-heading {
                padding: 20px 15px;
            }

            .filter-area {
                margin: 0 15px 18px;
                padding: 14px;
            }

            .bookings-footer {
                flex-direction: column;
            }

        }

        @media (max-width: 380px) {

            .booking-stats {
                grid-template-columns: 1fr;
            }

        }

    </style>

</head>


<body class="admin-bookings-page">

<div class="bookings-layout">


    <!-- SIDEBAR -->

    <aside class="bookings-sidebar">

        <a href="dashboard.php" class="bookings-brand">

            <span class="bookings-brand-icon">⌂</span>

            <span class="bookings-brand-copy">
                <strong>Home<span>Ease</span></strong>
                <small>ADMINISTRATION</small>
            </span>

        </a>


        <div class="sidebar-label">
            MAIN MENU
        </div>


        <nav class="bookings-nav" aria-label="Administrator navigation">

            <a href="dashboard.php">
                <span class="nav-icon">▦</span>
                Dashboard
            </a>

            <a href="users.php">
                <span class="nav-icon">♙</span>
                Users & Providers
            </a>

            <a href="services.php">
                <span class="nav-icon">⌂</span>
                Service Listings
            </a>

            <a href="bookings.php" class="active">
                <span class="nav-icon">▤</span>
                Booking Management
            </a>

            <a href="reports.php">
                <span class="nav-icon">▥</span>
                Reports & Insights
            </a>

        </nav>


        <div class="sidebar-bottom">

            <a href="../index.php">
                ↗ &nbsp; View Website
            </a>

            <a href="../logout.php" class="logout-link">
                ⇥ &nbsp; Logout
            </a>

        </div>

    </aside>



    <!-- MAIN CONTENT -->

    <main class="bookings-main">


        <!-- TOP BAR -->

        <header class="bookings-topbar">

            <div class="topbar-label">
                HomeEase / Booking Management
            </div>

            <div class="admin-greeting">

                <div class="admin-avatar">
                    <?= escapeOutput(strtoupper(substr($firstName, 0, 1))) ?>
                </div>

                <div>
                    <strong><?= escapeOutput($adminName) ?></strong>
                    <small>Administrator</small>
                </div>

            </div>

        </header>



        <!-- PAGE INTRODUCTION -->

        <section class="page-intro">

            <span class="section-kicker">
                PLATFORM MANAGEMENT
            </span>

            <h1>Booking Management</h1>

            <p>
                Monitor customer service requests, appointment details
                and booking statuses across HomeEase.
            </p>

        </section>



        <!-- BOOKING STATISTICS -->

        <section class="booking-stats">


            <article class="booking-stat-card">

                <div class="stat-top">
                    <span>Total Bookings</span>
                    <span class="stat-icon">▤</span>
                </div>

                <div class="stat-number">
                    <?= (int)$statistics["total_bookings"] ?>
                </div>

                <div class="stat-caption">
                    All booking requests
                </div>

            </article>


            <article class="booking-stat-card">

                <div class="stat-top">
                    <span>Pending Bookings</span>
                    <span class="stat-icon">◷</span>
                </div>

                <div class="stat-number">
                    <?= (int)$statistics["pending_bookings"] ?>
                </div>

                <div class="stat-caption">
                    Awaiting provider response
                </div>

            </article>


            <article class="booking-stat-card">

                <div class="stat-top">
                    <span>Accepted Bookings</span>
                    <span class="stat-icon">✓</span>
                </div>

                <div class="stat-number">
                    <?= (int)$statistics["accepted_bookings"] ?>
                </div>

                <div class="stat-caption">
                    Confirmed service requests
                </div>

            </article>


            <article class="booking-stat-card">

                <div class="stat-top">
                    <span>Rejected Bookings</span>
                    <span class="stat-icon">×</span>
                </div>

                <div class="stat-number">
                    <?= (int)$statistics["rejected_bookings"] ?>
                </div>

                <div class="stat-caption">
                    Declined service requests
                </div>

            </article>


        </section>



        <!-- BOOKING DIRECTORY -->

        <section class="booking-panel">


            <div class="panel-heading">

                <div>

                    <h2>Booking Records</h2>

                    <p>
                        Browse customer requests and their appointment information.
                    </p>

                </div>

                <span class="booking-count">
                    <?= $totalDisplayed ?> found
                </span>

            </div>



            <!-- SEARCH AND FILTER -->

            <div class="filter-area">

                <form
                    method="GET"
                    action="bookings.php"
                    class="filter-form"
                >

                    <div class="form-group">

                        <label for="search">
                            Search Bookings
                        </label>

                        <input
                            type="search"
                            id="search"
                            name="search"
                            placeholder="Customer, provider or service..."
                            value="<?= escapeOutput($searchTerm) ?>"
                        >

                    </div>


                    <div class="form-group">

                        <label for="status">
                            Booking Status
                        </label>

                        <select id="status" name="status">

                            <option value="all"
                                <?= $selectedStatus === "all" ? "selected" : "" ?>>
                                All Bookings
                            </option>

                            <option value="pending"
                                <?= $selectedStatus === "pending" ? "selected" : "" ?>>
                                Pending
                            </option>

                            <option value="accepted"
                                <?= $selectedStatus === "accepted" ? "selected" : "" ?>>
                                Accepted
                            </option>

                            <option value="rejected"
                                <?= $selectedStatus === "rejected" ? "selected" : "" ?>>
                                Rejected
                            </option>

                        </select>

                    </div>


                    <button type="submit" class="filter-button">
                        Apply Filters
                    </button>


                    <a href="bookings.php" class="clear-button">
                        Clear Filters
                    </a>

                </form>

            </div>



            <!-- BOOKINGS TABLE -->

            <?php if ($error !== ""): ?>

                <div class="error-message">
                    <?= escapeOutput($error) ?>
                </div>

            <?php else: ?>

                <div class="table-scroll">

                    <table class="bookings-table">

                        <thead>

                            <tr>
                                <th>Booking ID</th>
                                <th>Customer</th>
                                <th>Service</th>
                                <th>Provider</th>
                                <th>Appointment Date</th>
                                <th>Appointment Time</th>
                                <th>Status</th>
                                <th>Request Date</th>
                            </tr>

                        </thead>


                        <tbody>

                            <?php if ($totalDisplayed > 0): ?>

                                <?php foreach ($bookings as $booking): ?>

                                    <tr>

                                        <td>
                                            <span class="booking-id">
                                                #<?= (int)$booking["id"] ?>
                                            </span>
                                        </td>


                                        <td>

                                            <span class="customer-name">
                                                <?= escapeOutput($booking["customer_name"]) ?>
                                            </span>

                                            <span class="customer-email">
                                                <?= escapeOutput($booking["customer_email"]) ?>
                                            </span>

                                        </td>


                                        <td>
                                            <span class="service-name">
                                                <?= escapeOutput($booking["service_name"]) ?>
                                            </span>
                                        </td>


                                        <td>
                                            <span class="provider-name">
                                                <?= escapeOutput($booking["provider_name"]) ?>
                                            </span>
                                        </td>


                                        <td>
                                            <span class="appointment-date">
                                                <?= escapeOutput(
                                                    formatBookingDate($booking["booking_date"])
                                                ) ?>
                                            </span>
                                        </td>


                                        <td>
                                            <span class="appointment-time">
                                                <?= escapeOutput(
                                                    formatBookingTime($booking["booking_time"])
                                                ) ?>
                                            </span>
                                        </td>


                                        <td>

                                            <span class="status-badge <?= escapeOutput(getStatusClass($booking["status"])) ?>">
                                                <?= escapeOutput(statusLabel($booking["status"])) ?>
                                            </span>

                                        </td>


                                        <td>
                                            <?= escapeOutput(
                                                formatBookingDate($booking["created_at"])
                                            ) ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>

                                    <td colspan="8" class="empty-message">

                                        No bookings were found.

                                        <br>

                                        Try changing your search
                                        or status filter.

                                    </td>

                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>


        </section>



        <!-- FOOTER -->

        <footer class="bookings-footer">

            <span>
                © <?= date("Y") ?> HomeEase Administration
            </span>

            <span>
                Home services, made easier.
            </span>

        </footer>


    </main>

</div>

</body>
</html>
