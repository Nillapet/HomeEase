<?php

session_start();

require_once "../config/database.php";

// Protect the administrator dashboard.
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: ../login.php");
    exit;
}

// Retrieve system statistics.
function getTotal($conn, $sql)
{
    $result = $conn->query($sql);

    if (!$result) {
        return 0;
    }

    $row = $result->fetch_assoc();

    return (int) ($row["total"] ?? 0);
}

$totalCustomers = getTotal(
    $conn,
    "SELECT COUNT(*) AS total FROM users WHERE role = 'customer'"
);

$totalProviders = getTotal(
    $conn,
    "SELECT COUNT(*) AS total FROM users WHERE role = 'provider'"
);

$totalServices = getTotal(
    $conn,
    "SELECT COUNT(*) AS total FROM services"
);

$totalBookings = getTotal(
    $conn,
    "SELECT COUNT(*) AS total FROM bookings"
);

$totalPending = getTotal(
    $conn,
    "SELECT COUNT(*) AS total FROM bookings WHERE status = 'pending'"
);

$totalAccepted = getTotal(
    $conn,
    "SELECT COUNT(*) AS total FROM bookings WHERE status = 'accepted'"
);

$totalRejected = getTotal(
    $conn,
    "SELECT COUNT(*) AS total FROM bookings WHERE status = 'rejected'"
);

// Calculate booking percentages.
$pendingPercentage = $totalBookings > 0
    ? ($totalPending / $totalBookings) * 100
    : 0;

$acceptedPercentage = $totalBookings > 0
    ? ($totalAccepted / $totalBookings) * 100
    : 0;

$rejectedPercentage = $totalBookings > 0
    ? ($totalRejected / $totalBookings) * 100
    : 0;

// Administrator information.
$adminName = $_SESSION["full_name"] ?? "Administrator";
$firstName = explode(" ", trim($adminName))[0];

$safeFirstName = htmlspecialchars(
    $firstName,
    ENT_QUOTES,
    "UTF-8"
);

// Current date.
$today = date("l, d F Y");

// Retrieve the five most recent bookings.
$recentBookings = [];

$recentBookingsQuery = "
    SELECT
        b.id,
        b.booking_date,
        b.booking_time,
        b.status,
        u.full_name AS customer_name,
        s.service_name

    FROM bookings b

    LEFT JOIN users u
        ON b.customer_id = u.id

    LEFT JOIN services s
        ON b.service_id = s.id

    ORDER BY b.created_at DESC

    LIMIT 5
";

$recentResult = $conn->query($recentBookingsQuery);

if ($recentResult) {
    while ($row = $recentResult->fetch_assoc()) {
        $recentBookings[] = $row;
    }
}

// Helper for displaying status labels.
function getStatusClass($status)
{
    $status = strtolower(trim($status));

    if ($status === "accepted") {
        return "accepted";
    }

    if ($status === "rejected") {
        return "rejected";
    }

    if ($status === "completed") {
        return "completed";
    }

    if ($status === "cancelled") {
        return "cancelled";
    }

    return "pending";
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Admin Dashboard | HomeEase</title>

    <link rel="stylesheet" href="../css/admin.css">

    <style>

        /* =========================================
           DASHBOARD DESIGN
           Primary colour: #450011
        ========================================= */

        :root {
            --dash-primary: #450011;
            --dash-dark: #2D000B;
            --dash-light: #F2E6E9;
            --dash-ivory: #FAF7F3;
            --dash-text: #30272A;
            --dash-muted: #82777A;
            --dash-border: #EAE1E3;
        }

        * {
            box-sizing: border-box;
        }

        body.admin-dashboard-page {
            background: var(--dash-ivory);
            color: var(--dash-text);
            margin: 0;
            font-family: "Segoe UI", Arial, sans-serif;
        }

        .dashboard-layout {
            min-height: 100vh;
            display: flex;
        }


        /* =========================================
           SIDEBAR
        ========================================= */

        .dashboard-sidebar {
            width: 245px;
            min-width: 245px;

            background:
                linear-gradient(
                    180deg,
                    #450011 0%,
                    #35000D 65%,
                    #250008 100%
                );

            color: white;

            min-height: 100vh;
            padding: 28px 16px;

            display: flex;
            flex-direction: column;

            position: sticky;
            top: 0;
            height: 100vh;

            overflow-y: auto;
            z-index: 10;
        }

        .dashboard-brand {
            display: flex;
            align-items: center;
            gap: 12px;

            padding: 0 12px 30px;
            text-decoration: none;
            color: white;
        }

        .dashboard-brand-icon {
            width: 43px;
            height: 43px;

            display: flex;
            align-items: center;
            justify-content: center;

            background: rgba(255,255,255,0.12);
            border-radius: 12px;

            font-size: 26px;
        }

        .dashboard-brand-text strong {
            display: block;
            font-size: 21px;
            letter-spacing: -0.5px;
        }

        .dashboard-brand-text strong span {
            color: #E6B9C6;
        }

        .dashboard-brand-text small {
            display: block;
            margin-top: 3px;

            font-size: 9px;
            letter-spacing: 2px;

            color: rgba(255,255,255,0.7);
        }

        .sidebar-label {
            padding: 15px 13px 10px;

            color: rgba(255,255,255,0.48);

            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.5px;
        }

        .sidebar-nav {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .sidebar-nav a {
            display: flex;
            align-items: center;
            gap: 13px;

            padding: 13px 14px;

            border-radius: 10px;

            color: rgba(255,255,255,0.82);
            text-decoration: none;

            font-size: 13px;
            font-weight: 600;

            transition: 0.2s ease;
        }

        .sidebar-nav a:hover {
            background: rgba(255,255,255,0.11);
            color: white;
        }

        .sidebar-nav a.active {
            background: linear-gradient(
                90deg,
                #8B2946,
                #64142D
            );

            color: white;

            box-shadow: 0 5px 15px rgba(0,0,0,0.12);

            border-left: 4px solid #E9AFC0;
            padding-left: 10px;
        }

        .sidebar-icon {
            width: 22px;
            text-align: center;
            font-size: 18px;
        }

        .sidebar-divider {
            height: 1px;
            background: rgba(255,255,255,0.2);
            margin: 22px 10px;
        }

        .sidebar-bottom {
            margin-top: auto;
        }

        .sidebar-bottom a {
            display: flex;
            align-items: center;
            gap: 13px;

            padding: 13px 14px;

            color: rgba(255,255,255,0.85);
            text-decoration: none;

            font-size: 13px;
            border-radius: 10px;
        }

        .sidebar-bottom a:hover {
            background: rgba(255,255,255,0.12);
        }


        /* =========================================
           MAIN DASHBOARD AREA
        ========================================= */

        .dashboard-main {
            flex: 1;
            min-width: 0;
            padding: 28px 32px;

            background:
                radial-gradient(
                    circle at 95% 8%,
                    rgba(69,0,17,0.045),
                    transparent 23%
                ),
                radial-gradient(
                    circle at 5% 90%,
                    rgba(69,0,17,0.04),
                    transparent 22%
                ),
                #FAF7F3;

            position: relative;
            overflow: hidden;
        }

        .dashboard-main::before {
            content: "⌂";

            position: absolute;
            top: 230px;
            right: 15px;

            font-size: 250px;
            line-height: 1;

            color: rgba(69,0,17,0.025);

            pointer-events: none;
            z-index: 0;
        }

        .dashboard-content {
            max-width: 1550px;
            margin: 0 auto;

            position: relative;
            z-index: 1;
        }


        /* =========================================
           TOP HEADER
        ========================================= */

        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;

            gap: 20px;
            margin-bottom: 25px;
        }

        .dashboard-header h2 {
            margin: 0;
            color: var(--dash-primary);
            font-size: 22px;
        }

        .dashboard-header p {
            margin: 5px 0 0;

            color: var(--dash-muted);
            font-size: 12px;
        }

        .admin-profile {
            display: flex;
            align-items: center;
            gap: 11px;

            background: white;

            border: 1px solid var(--dash-border);
            border-radius: 12px;

            padding: 9px 15px;
        }

        .admin-avatar {
            width: 36px;
            height: 36px;

            display: flex;
            align-items: center;
            justify-content: center;

            background: var(--dash-light);
            color: var(--dash-primary);

            border-radius: 50%;
            font-weight: 750;
        }

        .admin-profile strong {
            display: block;
            color: var(--dash-primary);
            font-size: 12px;
        }

        .admin-profile small {
            display: block;
            color: var(--dash-muted);
            font-size: 10px;
            margin-top: 3px;
        }


        /* =========================================
           WELCOME BANNER
        ========================================= */

        .dashboard-hero {
            position: relative;
            overflow: hidden;

            background:
                linear-gradient(
                    115deg,
                    #450011 0%,
                    #64142D 65%,
                    #8A3049 100%
                );

            color: white;

            padding: 33px 35px;
            border-radius: 17px;

            min-height: 170px;

            display: flex;
            justify-content: space-between;
            align-items: center;

            box-shadow: 0 10px 28px rgba(69,0,17,0.16);

            margin-bottom: 30px;
        }

        .dashboard-hero::before,
        .dashboard-hero::after {
            content: "";

            position: absolute;

            border: 1px solid rgba(255,255,255,0.09);
            border-radius: 50%;
        }

        .dashboard-hero::before {
            width: 260px;
            height: 260px;
            right: 100px;
            top: -150px;
        }

        .dashboard-hero::after {
            width: 180px;
            height: 180px;
            right: -40px;
            bottom: -110px;
        }

        .hero-copy {
            position: relative;
            z-index: 2;
            max-width: 600px;
        }

        .hero-eyebrow {
            display: flex;
            align-items: center;
            gap: 9px;

            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1.3px;

            color: rgba(255,255,255,0.8);
        }

        .hero-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: #E9AFC0;
        }

        .dashboard-hero h1 {
            color: white;
            font-size: 30px;
            margin: 13px 0 9px;
            line-height: 1.2;
        }

        .dashboard-hero p {
            color: rgba(255,255,255,0.82);
            font-size: 13px;
            line-height: 1.7;
            margin: 0;
        }

        .hero-illustration {
            position: relative;
            z-index: 1;

            width: 170px;
            height: 125px;

            display: flex;
            justify-content: center;
            align-items: center;

            font-size: 100px;
            color: rgba(255,255,255,0.2);
        }

        .hero-illustration small {
            position: absolute;
            bottom: 0;
            right: 8px;

            color: rgba(255,255,255,0.65);
            font-size: 11px;
            letter-spacing: 3px;
        }


        /* =========================================
           SECTION HEADINGS
        ========================================= */

        .dashboard-section {
            margin-bottom: 30px;
        }

        .section-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;

            gap: 15px;
            margin-bottom: 17px;
        }

        .section-heading h2 {
            color: var(--dash-primary);
            font-size: 18px;
            margin: 3px 0 0;
        }

        .section-kicker {
            color: var(--dash-muted);

            font-size: 9px;
            font-weight: 750;
            letter-spacing: 1.5px;
        }

        .live-indicator {
            display: flex;
            align-items: center;
            gap: 7px;

            color: #287A50;
            font-size: 11px;
            font-weight: 700;
        }

        .live-indicator span {
            width: 7px;
            height: 7px;

            background: #287A50;
            border-radius: 50%;
        }


        /* =========================================
           STATISTIC CARDS
        ========================================= */

        .dashboard-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 17px;
        }

        .dashboard-stat {
            background: white;

            padding: 21px;

            border: 1px solid #EEDFE3;
            border-radius: 15px;

            box-shadow: 0 5px 18px rgba(69,0,17,0.045);

            transition: 0.25s ease;
        }

        .dashboard-stat:hover {
            transform: translateY(-4px);

            box-shadow: 0 12px 25px rgba(69,0,17,0.1);
        }

        .stat-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .stat-icon {
            width: 45px;
            height: 45px;

            display: flex;
            justify-content: center;
            align-items: center;

            background: var(--dash-light);
            color: var(--dash-primary);

            border-radius: 50%;
            font-size: 21px;
        }

        .stat-label {
            color: var(--dash-muted);

            font-size: 9px;
            font-weight: 750;
            letter-spacing: 1px;
        }

        .stat-number {
            color: var(--dash-primary);

            font-size: 29px;
            font-weight: 800;

            margin: 15px 0 3px;
        }

        .stat-title {
            color: var(--dash-primary);

            font-size: 12px;
            font-weight: 650;

            margin: 0 0 7px;
        }

        .stat-description {
            color: var(--dash-muted);

            font-size: 10px;
            line-height: 1.5;

            margin: 0;
        }

        .stat-link {
            display: inline-block;
            margin-top: 15px;

            color: var(--dash-primary);
            font-size: 11px;
            font-weight: 750;

            text-decoration: none;
        }

        .stat-link:hover {
            text-decoration: underline;
        }


        /* =========================================
           CONTENT GRID
        ========================================= */

        .dashboard-panels {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
            align-items: stretch;
        }

        .dashboard-panel {
            background: white;

            padding: 22px;

            border: 1px solid var(--dash-border);
            border-radius: 16px;

            box-shadow: 0 5px 18px rgba(69,0,17,0.045);

            min-width: 0;
        }

        .panel-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;

            gap: 12px;
            margin-bottom: 8px;
        }

        .panel-heading h2 {
            color: var(--dash-primary);
            font-size: 16px;
            margin: 0;
        }

        .panel-link {
            color: var(--dash-primary);
            font-size: 11px;
            font-weight: 750;
            text-decoration: none;
        }

        .panel-link:hover {
            text-decoration: underline;
        }

        .panel-description {
            color: var(--dash-muted);
            font-size: 11px;
            line-height: 1.6;
            margin: 8px 0 20px;
        }


        /* =========================================
           BOOKING STATUS OVERVIEW
        ========================================= */

        .booking-status-item {
            margin-bottom: 21px;
        }

        .booking-status-top {
            display: flex;
            justify-content: space-between;
            align-items: center;

            margin-bottom: 9px;
        }

        .booking-status-label {
            display: flex;
            align-items: center;
            gap: 9px;

            color: var(--dash-text);
            font-size: 12px;
            font-weight: 650;
        }

        .status-dot {
            width: 9px;
            height: 9px;
            border-radius: 50%;
        }

        .status-dot.pending {
            background: #D99B32;
        }

        .status-dot.accepted {
            background: #38845B;
        }

        .status-dot.rejected {
            background: #D35A63;
        }

        .booking-status-top strong {
            color: var(--dash-primary);
            font-size: 13px;
        }

        .booking-progress {
            height: 8px;
            width: 100%;

            background: #F0E9EA;
            border-radius: 20px;
            overflow: hidden;
        }

        .booking-progress span {
            display: block;
            height: 100%;
            border-radius: 20px;
        }

        .progress-pending {
            background: #D99B32;
        }

        .progress-accepted {
            background: #38845B;
        }

        .progress-rejected {
            background: #D35A63;
        }

        .booking-total {
            background: var(--dash-light);

            padding: 14px;
            border-radius: 10px;

            display: flex;
            justify-content: space-between;
            align-items: center;

            margin-top: 25px;
        }

        .booking-total span {
            color: var(--dash-primary);
            font-size: 11px;
            font-weight: 650;
        }

        .booking-total strong {
            color: var(--dash-primary);
            font-size: 20px;
        }


        /* =========================================
           RECENT BOOKINGS
        ========================================= */

        .recent-bookings {
            display: flex;
            flex-direction: column;
        }

        .recent-booking {
            display: grid;
            grid-template-columns: 38px minmax(0, 1fr) auto;

            gap: 11px;
            align-items: center;

            padding: 13px 0;

            border-bottom: 1px solid #F0E8E9;
        }

        .recent-booking:last-child {
            border-bottom: none;
        }

        .recent-booking-icon {
            width: 37px;
            height: 37px;

            display: flex;
            align-items: center;
            justify-content: center;

            background: var(--dash-light);
            color: var(--dash-primary);

            border-radius: 50%;
            font-size: 17px;
        }

        .recent-booking-info {
            min-width: 0;
        }

        .recent-booking-info strong {
            display: block;

            color: var(--dash-primary);
            font-size: 11px;

            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .recent-booking-info small {
            display: block;

            color: var(--dash-muted);
            font-size: 10px;

            margin-top: 4px;

            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .recent-booking-right {
            text-align: right;
        }

        .booking-badge {
            display: inline-block;

            padding: 6px 9px;
            border-radius: 20px;

            font-size: 9px;
            font-weight: 750;
            text-transform: capitalize;
        }

        .booking-badge.pending {
            background: #FFF1D7;
            color: #94600C;
        }

        .booking-badge.accepted,
        .booking-badge.completed {
            background: #E3F2E8;
            color: #287A50;
        }

        .booking-badge.rejected,
        .booking-badge.cancelled {
            background: #FCE8E9;
            color: #B52F3A;
        }

        .booking-date {
            display: block;
            color: var(--dash-muted);
            font-size: 9px;
            margin-top: 5px;
        }

        .empty-bookings {
            padding: 30px 10px;
            text-align: center;
            color: var(--dash-muted);
            font-size: 12px;
        }


        /* =========================================
           QUICK ACTIONS
        ========================================= */

        .quick-actions-panel {
            margin-top: 19px;
        }

        .quick-actions {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-top: 17px;
        }

        .quick-action {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;

            padding: 13px 10px;

            background: var(--dash-light);
            color: var(--dash-primary);

            border-radius: 10px;
            text-decoration: none;

            font-size: 10px;
            font-weight: 700;

            transition: 0.2s ease;
        }

        .quick-action:hover {
            background: #E8CED5;
            transform: translateY(-2px);
        }

        .quick-action span {
            font-size: 17px;
        }


        /* =========================================
           SYSTEM NOTE
        ========================================= */

        .dashboard-note {
            margin-top: 19px;

            display: flex;
            align-items: center;
            gap: 14px;

            padding: 17px 20px;

            background: linear-gradient(
                100deg,
                #F3E1E6,
                #FAEDF0
            );

            border: 1px solid #EED5DD;
            border-radius: 13px;
        }

        .note-icon {
            width: 38px;
            height: 38px;

            display: flex;
            align-items: center;
            justify-content: center;

            background: var(--dash-primary);
            color: white;

            border-radius: 50%;
            font-weight: 800;
        }

        .note-copy strong {
            display: block;
            color: var(--dash-primary);
            font-size: 12px;
        }

        .note-copy p {
            color: var(--dash-muted);
            font-size: 10px;
            margin: 5px 0 0;
            line-height: 1.5;
        }


        /* =========================================
           FOOTER
        ========================================= */

        .dashboard-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;

            gap: 15px;

            margin: 35px -32px -28px;
            padding: 19px 32px;

            background: var(--dash-dark);
            color: rgba(255,255,255,0.8);

            font-size: 10px;
        }

        .dashboard-footer strong {
            color: white;
        }


        /* =========================================
           RESPONSIVE DESIGN
        ========================================= */

        @media (max-width: 1150px) {

            .dashboard-sidebar {
                width: 210px;
                min-width: 210px;
            }

            .dashboard-main {
                padding: 25px 22px;
            }

            .dashboard-stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .dashboard-panels {
                grid-template-columns: 1fr;
            }

            .dashboard-footer {
                margin-left: -22px;
                margin-right: -22px;
                padding-left: 22px;
                padding-right: 22px;
            }

        }


        @media (max-width: 700px) {

            .dashboard-layout {
                display: block;
            }

            .dashboard-sidebar {
                position: relative;

                width: 100%;
                min-width: 0;
                height: auto;
                min-height: auto;

                padding: 15px;
            }

            .dashboard-brand {
                padding: 0 5px 15px;
            }

            .sidebar-label,
            .sidebar-divider,
            .sidebar-bottom {
                display: none;
            }

            .sidebar-nav {
                display: grid;
                grid-template-columns: repeat(2, 1fr);
                gap: 6px;
            }

            .sidebar-nav a {
                padding: 10px;
                font-size: 11px;
            }

            .dashboard-main {
                padding: 20px 15px;
            }

            .dashboard-header {
                align-items: flex-start;
            }

            .dashboard-header h2 {
                font-size: 18px;
            }

            .admin-profile {
                padding: 7px;
            }

            .admin-profile > div:last-child {
                display: none;
            }

            .dashboard-hero {
                padding: 25px 22px;
                min-height: 150px;
            }

            .dashboard-hero h1 {
                font-size: 23px;
            }

            .hero-illustration {
                width: 75px;
                font-size: 65px;
            }

            .dashboard-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 11px;
            }

            .dashboard-stat {
                padding: 15px;
            }

            .stat-number {
                font-size: 24px;
            }

            .stat-icon {
                width: 37px;
                height: 37px;
            }

            .stat-label {
                font-size: 8px;
            }

            .quick-actions {
                grid-template-columns: repeat(2, 1fr);
            }

            .dashboard-footer {
                margin: 30px -15px -20px;
                padding: 17px 15px;
                flex-direction: column;
                text-align: center;
            }

        }


        @media (max-width: 380px) {

            .dashboard-stats {
                grid-template-columns: 1fr;
            }

            .dashboard-hero {
                display: block;
            }

            .hero-illustration {
                display: none;
            }

            .recent-booking {
                grid-template-columns: 32px minmax(0, 1fr);
            }

            .recent-booking-right {
                grid-column: 2;
                text-align: left;
            }

        }

    </style>

</head>


<body class="admin-dashboard-page">

<div class="dashboard-layout">


    <!-- =========================================
         SIDEBAR NAVIGATION
    ========================================== -->

    <aside class="dashboard-sidebar">

        <a href="dashboard.php" class="dashboard-brand">

            <span class="dashboard-brand-icon">
                ⌂
            </span>

            <span class="dashboard-brand-text">
                <strong>Home<span>Ease</span></strong>
                <small>ADMINISTRATION</small>
            </span>

        </a>


        <div class="sidebar-label">
            MAIN MENU
        </div>


        <nav class="sidebar-nav" aria-label="Admin navigation">

            <a href="dashboard.php" class="active">
                <span class="sidebar-icon">⌂</span>
                Dashboard
            </a>

            <a href="bookings.php">
                <span class="sidebar-icon">▦</span>
                Bookings
            </a>

            <a href="users.php">
                <span class="sidebar-icon">♙</span>
                Users & Providers
            </a>

            <a href="services.php">
                <span class="sidebar-icon">✳</span>
                Services
            </a>

            <a href="reports.php">
                <span class="sidebar-icon">▥</span>
                Reports
            </a>

        </nav>


        <div class="sidebar-divider"></div>


        <div class="sidebar-bottom">

            <a href="../index.php">
                <span class="sidebar-icon">↗</span>
                View Website
            </a>

            <a href="../logout.php">
                <span class="sidebar-icon">⇥</span>
                Logout
            </a>

        </div>

    </aside>



    <!-- =========================================
         MAIN DASHBOARD
    ========================================== -->

    <main class="dashboard-main">

        <div class="dashboard-content">


            <!-- TOP HEADER -->

            <header class="dashboard-header">

                <div>

                    <h2>Dashboard Overview</h2>

                    <p>
                        Welcome to your HomeEase administration workspace.
                    </p>

                </div>


                <div class="admin-profile">

                    <div class="admin-avatar">
                        <?= strtoupper(substr($firstName, 0, 1)) ?>
                    </div>

                    <div>

                        <strong><?= $safeFirstName ?></strong>

                        <small>Administrator</small>

                    </div>

                </div>

            </header>



            <!-- WELCOME BANNER -->

            <section class="dashboard-hero">

                <div class="hero-copy">

                    <span class="hero-eyebrow">
                        <span class="hero-dot"></span>
                        WELCOME BACK, ADMIN
                    </span>

                    <h1>
                        Manage. Monitor. Grow.
                    </h1>

                    <p>
                        Here's what's happening with your HomeEase platform
                        today. Keep track of your users, services and bookings
                        all in one place.
                    </p>

                </div>


                <div class="hero-illustration" aria-hidden="true">

                    ⌂

                    <small>HOME EASE</small>

                </div>

            </section>



            <!-- PLATFORM STATISTICS -->

            <section class="dashboard-section">

                <div class="section-heading">

                    <div>

                        <span class="section-kicker">
                            AT A GLANCE
                        </span>

                        <h2>Platform overview</h2>

                    </div>


                    <span class="live-indicator">
                        <span></span>
                        Live database totals
                    </span>

                </div>


                <div class="dashboard-stats">


                    <!-- CUSTOMERS -->

                    <article class="dashboard-stat">

                        <div class="stat-top">

                            <span class="stat-icon">♙</span>

                            <span class="stat-label">CUSTOMERS</span>

                        </div>

                        <p class="stat-number">
                            <?= $totalCustomers ?>
                        </p>

                        <h3 class="stat-title">
                            Registered customers
                        </h3>

                        <p class="stat-description">
                            People using HomeEase to find home services.
                        </p>

                        <a href="users.php" class="stat-link">
                            View customers →
                        </a>

                    </article>



                    <!-- PROVIDERS -->

                    <article class="dashboard-stat">

                        <div class="stat-top">

                            <span class="stat-icon">♧</span>

                            <span class="stat-label">PROVIDERS</span>

                        </div>

                        <p class="stat-number">
                            <?= $totalProviders ?>
                        </p>

                        <h3 class="stat-title">
                            Service providers
                        </h3>

                        <p class="stat-description">
                            Professionals registered on the platform.
                        </p>

                        <a href="users.php" class="stat-link">
                            View providers →
                        </a>

                    </article>



                    <!-- SERVICES -->

                    <article class="dashboard-stat">

                        <div class="stat-top">

                            <span class="stat-icon">✳</span>

                            <span class="stat-label">SERVICES</span>

                        </div>

                        <p class="stat-number">
                            <?= $totalServices ?>
                        </p>

                        <h3 class="stat-title">
                            Listed services
                        </h3>

                        <p class="stat-description">
                            Services currently recorded in the system.
                        </p>

                        <a href="services.php" class="stat-link">
                            Manage services →
                        </a>

                    </article>



                    <!-- BOOKINGS -->

                    <article class="dashboard-stat">

                        <div class="stat-top">

                            <span class="stat-icon">▦</span>

                            <span class="stat-label">BOOKINGS</span>

                        </div>

                        <p class="stat-number">
                            <?= $totalBookings ?>
                        </p>

                        <h3 class="stat-title">
                            Total bookings
                        </h3>

                        <p class="stat-description">
                            All booking requests recorded on HomeEase.
                        </p>

                        <a href="bookings.php" class="stat-link">
                            View bookings →
                        </a>

                    </article>


                </div>

            </section>



            <!-- BOOKING SUMMARY AND RECENT BOOKINGS -->

            <section class="dashboard-panels">


                <!-- BOOKING STATUS SUMMARY -->

                <div class="dashboard-panel">

                    <div class="panel-heading">

                        <h2>Booking overview</h2>

                        <a href="bookings.php" class="panel-link">
                            View all →
                        </a>

                    </div>

                    <p class="panel-description">
                        A breakdown of booking requests according to their
                        current status.
                    </p>


                    <!-- PENDING -->

                    <div class="booking-status-item">

                        <div class="booking-status-top">

                            <span class="booking-status-label">
                                <span class="status-dot pending"></span>
                                Pending
                            </span>

                            <strong><?= $totalPending ?></strong>

                        </div>

                        <div class="booking-progress">

                            <span
                                class="progress-pending"
                                style="width: <?= $pendingPercentage ?>%">
                            </span>

                        </div>

                    </div>



                    <!-- ACCEPTED -->

                    <div class="booking-status-item">

                        <div class="booking-status-top">

                            <span class="booking-status-label">
                                <span class="status-dot accepted"></span>
                                Accepted
                            </span>

                            <strong><?= $totalAccepted ?></strong>

                        </div>

                        <div class="booking-progress">

                            <span
                                class="progress-accepted"
                                style="width: <?= $acceptedPercentage ?>%">
                            </span>

                        </div>

                    </div>



                    <!-- REJECTED -->

                    <div class="booking-status-item">

                        <div class="booking-status-top">

                            <span class="booking-status-label">
                                <span class="status-dot rejected"></span>
                                Rejected
                            </span>

                            <strong><?= $totalRejected ?></strong>

                        </div>

                        <div class="booking-progress">

                            <span
                                class="progress-rejected"
                                style="width: <?= $rejectedPercentage ?>%">
                            </span>

                        </div>

                    </div>



                    <div class="booking-total">

                        <span>Total booking requests</span>

                        <strong><?= $totalBookings ?></strong>

                    </div>

                </div>



                <!-- RECENT BOOKINGS -->

                <div class="dashboard-panel">

                    <div class="panel-heading">

                        <h2>Recent bookings</h2>

                        <a href="bookings.php" class="panel-link">
                            View all →
                        </a>

                    </div>

                    <p class="panel-description">
                        The latest booking requests submitted by customers.
                    </p>


                    <div class="recent-bookings">

                        <?php if (!empty($recentBookings)): ?>

                            <?php foreach ($recentBookings as $booking): ?>

                                <?php

                                $bookingId = (int) $booking["id"];

                                $customerName = htmlspecialchars(
                                    $booking["customer_name"] ?? "Unknown customer",
                                    ENT_QUOTES,
                                    "UTF-8"
                                );

                                $serviceName = htmlspecialchars(
                                    $booking["service_name"] ?? "Unknown service",
                                    ENT_QUOTES,
                                    "UTF-8"
                                );

                                $status = strtolower(
                                    trim($booking["status"] ?? "pending")
                                );

                                $statusClass = getStatusClass($status);

                                $statusLabel = htmlspecialchars(
                                    ucfirst($status),
                                    ENT_QUOTES,
                                    "UTF-8"
                                );

                                $bookingDate = !empty($booking["booking_date"])
                                    ? date("d M Y", strtotime($booking["booking_date"]))
                                    : "Date unavailable";

                                ?>

                                <div class="recent-booking">

                                    <div class="recent-booking-icon">
                                        ⌂
                                    </div>


                                    <div class="recent-booking-info">

                                        <strong>
                                            #BK-<?= $bookingId ?>
                                            · <?= $customerName ?>
                                        </strong>

                                        <small>
                                            <?= $serviceName ?>
                                        </small>

                                    </div>


                                    <div class="recent-booking-right">

                                        <span class="booking-badge <?= $statusClass ?>">
                                            <?= $statusLabel ?>
                                        </span>

                                        <small class="booking-date">
                                            <?= $bookingDate ?>
                                        </small>

                                    </div>

                                </div>

                            <?php endforeach; ?>

                        <?php else: ?>

                            <div class="empty-bookings">

                                <strong>No bookings yet.</strong>

                                <p>
                                    New customer booking requests will appear here.
                                </p>

                            </div>

                        <?php endif; ?>

                    </div>

                </div>

            </section>



            <!-- QUICK ACTIONS -->

            <section class="dashboard-panel quick-actions-panel">

                <div class="panel-heading">

                    <h2>Quick actions</h2>

                </div>

                <p class="panel-description">
                    Access the main administration sections quickly.
                </p>


                <div class="quick-actions">

                    <a href="bookings.php" class="quick-action">
                        <span>▦</span>
                        Manage bookings
                    </a>

                    <a href="users.php" class="quick-action">
                        <span>♙</span>
                        Manage users
                    </a>

                    <a href="services.php" class="quick-action">
                        <span>✳</span>
                        Manage services
                    </a>

                    <a href="reports.php" class="quick-action">
                        <span>▥</span>
                        View reports
                    </a>

                </div>

            </section>



            <!-- SYSTEM INFORMATION -->

            <section class="dashboard-note">

                <div class="note-icon">
                    i
                </div>

                <div class="note-copy">

                    <strong>HomeEase system overview</strong>

                    <p>
                        Dashboard figures are retrieved directly from your
                        database whenever this page loads.
                    </p>

                </div>

            </section>



            <!-- FOOTER -->

            <footer class="dashboard-footer">

                <span>
                    © <?= date("Y") ?> <strong>HomeEase Administration.</strong>
                    All rights reserved.
                </span>

                <span>
                    ⌂ Powered by HomeEase
                </span>

            </footer>


        </div>

    </main>

</div>

</body>
</html>