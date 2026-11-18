
<?php

session_start();

require_once "../config/database.php";

// Protect the administrator page.
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: ../login.php");
    exit;
}


// ======================================
// HELPER FUNCTIONS
// ======================================

function escapeOutput($value)
{
    return htmlspecialchars((string)($value ?? ""), ENT_QUOTES, "UTF-8");
}

function isValidDate($date)
{
    if (!is_string($date)) {
        return false;
    }

    $dateObject = DateTime::createFromFormat("!Y-m-d", $date);

    return $dateObject &&
        $dateObject->format("Y-m-d") === $date;
}

function formatReportDate($date)
{
    if (empty($date)) {
        return "Not available";
    }

    $timestamp = strtotime($date);

    return $timestamp ? date("d M Y", $timestamp) : "Not available";
}


// ======================================
// ADMINISTRATOR INFORMATION
// ======================================

$adminName = $_SESSION["full_name"] ?? "Administrator";
$firstName = explode(" ", trim($adminName))[0];

$safeFirstName = escapeOutput($firstName);


// ======================================
// DATE FILTERS
// ======================================

$startDate = date("Y-m-01");
$endDate = date("Y-m-d");

if (isset($_GET["start_date"], $_GET["end_date"])) {

    $requestedStart = $_GET["start_date"];
    $requestedEnd = $_GET["end_date"];

    if (
        isValidDate($requestedStart) &&
        isValidDate($requestedEnd) &&
        $requestedStart <= $requestedEnd &&
        $requestedEnd <= date("Y-m-d")
    ) {
        $startDate = $requestedStart;
        $endDate = $requestedEnd;
    }
}


// ======================================
// CSV EXPORT
// ======================================

if (isset($_GET["export"]) && $_GET["export"] === "csv") {

    $exportSql = "
        SELECT
            b.id,
            c.full_name AS customer_name,
            s.service_name,
            p.full_name AS provider_name,
            b.booking_date,
            b.booking_time,
            b.status,
            b.created_at
        FROM bookings b
        INNER JOIN users c ON b.customer_id = c.id
        INNER JOIN users p ON b.provider_id = p.id
        INNER JOIN services s ON b.service_id = s.id
        WHERE b.booking_date BETWEEN ? AND ?
        ORDER BY b.booking_date DESC, b.booking_time DESC
    ";

    $exportStmt = $conn->prepare($exportSql);

    if (!$exportStmt) {
        die("Unable to prepare the CSV export.");
    }

    $exportStmt->bind_param("ss", $startDate, $endDate);
    $exportStmt->execute();

    $exportResult = $exportStmt->get_result();

    $filename = "HomeEase_Report_" . $startDate . "_to_" . $endDate . ".csv";

    header("Content-Type: text/csv; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"" . $filename . "\"");

    // UTF-8 BOM helps spreadsheet applications display text correctly.
    echo "\xEF\xBB\xBF";

    $output = fopen("php://output", "w");

    fputcsv($output, [
        "Booking ID",
        "Customer",
        "Service",
        "Provider",
        "Appointment Date",
        "Appointment Time",
        "Status",
        "Request Date"
    ]);

    while ($booking = $exportResult->fetch_assoc()) {

        fputcsv($output, [
            $booking["id"],
            $booking["customer_name"],
            $booking["service_name"],
            $booking["provider_name"],
            $booking["booking_date"],
            $booking["booking_time"],
            $booking["status"],
            $booking["created_at"]
        ]);
    }

    fclose($output);
    $exportStmt->close();
    $conn->close();

    exit;
}


// ======================================
// BOOKING STATISTICS
// ======================================

$summarySql = "
    SELECT
        COUNT(*) AS total_bookings,
        COALESCE(SUM(status = 'pending'), 0) AS pending_bookings,
        COALESCE(SUM(status = 'accepted'), 0) AS accepted_bookings,
        COALESCE(SUM(status = 'rejected'), 0) AS rejected_bookings
    FROM bookings
    WHERE booking_date BETWEEN ? AND ?
";

$summaryStmt = $conn->prepare($summarySql);

if (!$summaryStmt) {
    die("Unable to prepare booking statistics.");
}

$summaryStmt->bind_param("ss", $startDate, $endDate);
$summaryStmt->execute();

$summary = $summaryStmt->get_result()->fetch_assoc();
$summaryStmt->close();

$totalBookings = (int)$summary["total_bookings"];
$pendingBookings = (int)$summary["pending_bookings"];
$acceptedBookings = (int)$summary["accepted_bookings"];
$rejectedBookings = (int)$summary["rejected_bookings"];

// Account for any other booking statuses.
$otherBookings = max(
    0,
    $totalBookings - (
        $pendingBookings +
        $acceptedBookings +
        $rejectedBookings
    )
);

// Acceptance rate is calculated against all bookings.
$acceptanceRate = $totalBookings > 0
    ? round(($acceptedBookings / $totalBookings) * 100, 1)
    : 0;


// ======================================
// MOST REQUESTED SERVICES
// ======================================

$servicesSql = "
    SELECT
        s.service_name,
        COUNT(b.id) AS total_requests,
        COALESCE(SUM(b.status = 'accepted'), 0) AS accepted_requests
    FROM bookings b
    INNER JOIN services s ON b.service_id = s.id
    WHERE b.booking_date BETWEEN ? AND ?
    GROUP BY s.id, s.service_name
    ORDER BY total_requests DESC
";

$servicesStmt = $conn->prepare($servicesSql);

if (!$servicesStmt) {
    die("Unable to prepare service statistics.");
}

$servicesStmt->bind_param("ss", $startDate, $endDate);
$servicesStmt->execute();

$servicesResult = $servicesStmt->get_result();

$services = [];

while ($service = $servicesResult->fetch_assoc()) {
    $services[] = $service;
}

$servicesStmt->close();

$maxServiceRequests = 0;

foreach ($services as $service) {
    $maxServiceRequests = max(
        $maxServiceRequests,
        (int)$service["total_requests"]
    );
}


// ======================================
// DAILY BOOKING ACTIVITY
// ======================================

$activitySql = "
    SELECT
        booking_date,
        COUNT(*) AS total_bookings,
        COALESCE(SUM(status = 'accepted'), 0) AS accepted_bookings,
        COALESCE(SUM(status = 'pending'), 0) AS pending_bookings,
        COALESCE(SUM(status = 'rejected'), 0) AS rejected_bookings
    FROM bookings
    WHERE booking_date BETWEEN ? AND ?
    GROUP BY booking_date
    ORDER BY booking_date DESC
    LIMIT 10
";

$activityStmt = $conn->prepare($activitySql);

if (!$activityStmt) {
    die("Unable to prepare booking activity.");
}

$activityStmt->bind_param("ss", $startDate, $endDate);
$activityStmt->execute();

$activityResult = $activityStmt->get_result();

$activities = [];

while ($activity = $activityResult->fetch_assoc()) {
    $activities[] = $activity;
}

$activityStmt->close();

// Display dates from oldest to newest in the chart.
$activities = array_reverse($activities);

$maxDailyBookings = 0;

foreach ($activities as $activity) {
    $maxDailyBookings = max(
        $maxDailyBookings,
        (int)$activity["total_bookings"]
    );
}


// ======================================
// DONUT CHART CALCULATIONS
// ======================================

$acceptedPercent = $totalBookings > 0
    ? ($acceptedBookings / $totalBookings) * 100
    : 0;

$pendingPercent = $totalBookings > 0
    ? ($pendingBookings / $totalBookings) * 100
    : 0;

$rejectedPercent = $totalBookings > 0
    ? ($rejectedBookings / $totalBookings) * 100
    : 0;

$otherPercent = $totalBookings > 0
    ? ($otherBookings / $totalBookings) * 100
    : 0;

$acceptedEnd = $acceptedPercent;
$pendingEnd = $acceptedEnd + $pendingPercent;
$rejectedEnd = $pendingEnd + $rejectedPercent;

if ($totalBookings > 0) {

    $donutStyle = "background: conic-gradient(
        #450011 0% {$acceptedEnd}%,
        #D9A441 {$acceptedEnd}% {$pendingEnd}%,
        #92909B {$pendingEnd}% {$rejectedEnd}%,
        #D9CDD1 {$rejectedEnd}% 100%
    );";

} else {

    $donutStyle = "background: conic-gradient(#EAE5E7 0% 100%);";
}


// ======================================
// EXPORT URL
// ======================================

$exportUrl = "reports.php?start_date=" . urlencode($startDate)
    . "&end_date=" . urlencode($endDate)
    . "&export=csv";

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Reports & Analytics | HomeEase</title>

    <link rel="stylesheet" href="../css/admin.css">

    <style>

        /* =========================================
           REPORTS PAGE DESIGN
           MATCHING THE ADMIN DASHBOARD
           PRIMARY COLOUR: #450011
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

        body.admin-reports-page {
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
           SAME STRUCTURE AS DASHBOARD
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
           MAIN CONTENT AREA
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
           REPORTS HERO
        ========================================= */

        .reports-hero {
            background: linear-gradient(
                115deg,
                #450011 0%,
                #64142D 65%,
                #8A3049 100%
            );

            border-radius: 17px;
            padding: 33px 35px;
            min-height: 190px;

            display: flex;
            align-items: center;
            justify-content: space-between;

            gap: 25px;
            overflow: hidden;
            position: relative;

            color: white;
            margin-bottom: 30px;

            box-shadow: 0 10px 28px rgba(69,0,17,0.16);
        }

        .reports-hero::before,
        .reports-hero::after {
            content: "";

            position: absolute;

            border: 1px solid rgba(255,255,255,0.09);
            border-radius: 50%;
        }

        .reports-hero::before {
            width: 260px;
            height: 260px;
            right: 100px;
            top: -150px;
        }

        .reports-hero::after {
            width: 180px;
            height: 180px;
            right: -40px;
            bottom: -110px;
        }

        .reports-hero-copy {
            position: relative;
            z-index: 2;
            max-width: 600px;
        }

        .reports-hero .hero-label {
            display: inline-flex;
            align-items: center;
            gap: 9px;

            color: rgba(255,255,255,0.8);

            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1.3px;
            text-transform: uppercase;
        }

        .reports-hero .hero-label::before {
            content: "";
            width: 9px;
            height: 9px;
            border-radius: 50%;
            background: #E9AFC0;
        }

        .reports-hero h1 {
            color: white;
            font-size: 30px;
            line-height: 1.25;
            margin: 13px 0 10px;
        }

        .reports-hero p {
            color: rgba(255,255,255,0.82);
            font-size: 13px;
            line-height: 1.7;
            margin: 0;
            max-width: 500px;
        }

        .reports-illustration {
            position: relative;
            z-index: 1;
            width: 250px;
            min-width: 200px;
        }

        .reports-illustration svg {
            width: 100%;
            height: auto;
            display: block;
        }


        /* =========================================
           DATE FILTER
        ========================================= */

        .reports-filter {
            background: white;
            padding: 22px 25px;
            border-radius: 15px;
            border: 1px solid var(--dash-border);
            margin-bottom: 30px;
            box-shadow: 0 5px 18px rgba(69,0,17,0.045);
        }

        .reports-filter-heading {
            margin-bottom: 17px;
        }

        .reports-filter-heading h2 {
            font-size: 16px;
            color: var(--dash-primary);
            margin: 0 0 5px;
        }

        .reports-filter-heading p {
            font-size: 12px;
            color: var(--dash-muted);
            margin: 0;
        }

        .reports-filter-form {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            gap: 15px;
        }

        .reports-filter-form .form-group {
            display: flex;
            flex-direction: column;
            gap: 7px;
            flex: 1;
            min-width: 170px;
        }

        .reports-filter-form label {
            font-size: 12px;
            font-weight: 700;
            color: #55474B;
        }

        .reports-filter-form input {
            width: 100%;
            min-height: 43px;
            padding: 10px 12px;
            border: 1px solid #E6DDE0;
            border-radius: 8px;
            background: #FFFEFE;
            color: var(--dash-text);
        }

        .reports-filter-form button,
        .reports-filter-form a {
            min-height: 43px;
            display: inline-flex;
            justify-content: center;
            align-items: center;
            padding: 0 19px;
            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }

        .reports-filter-form button {
            border: none;
            background: var(--dash-primary);
            color: white;
        }

        .reports-filter-form button:hover {
            background: var(--dash-dark);
        }

        .reports-filter-form a {
            border: 1px solid #E6DDE0;
            color: var(--dash-primary);
            background: white;
        }

        .reports-filter-form a:hover {
            background: #F8F0F2;
        }


        /* =========================================
           SECTION HEADINGS
        ========================================= */

        .reports-section {
            margin-bottom: 30px;
        }

        .reports-section-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 17px;
        }

        .reports-section-heading h2 {
            font-size: 18px;
            color: var(--dash-primary);
            margin: 3px 0 5px;
        }

        .reports-section-heading p {
            color: var(--dash-muted);
            font-size: 12px;
            margin: 0;
            line-height: 1.6;
        }

        .reports-period {
            color: var(--dash-primary);
            background: var(--dash-light);
            padding: 8px 13px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }


        /* =========================================
           STATISTICS CARDS
        ========================================= */

        .reports-stat-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 17px;
        }

        .reports-stat-card {
            background: white;
            border: 1px solid var(--dash-border);
            border-radius: 15px;
            padding: 21px;
            min-width: 0;

            box-shadow: 0 5px 18px rgba(69,0,17,0.045);

            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .reports-stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 25px rgba(69,0,17,0.09);
        }

        .reports-stat-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 17px;
        }

        .reports-stat-icon {
            width: 42px;
            height: 42px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            font-size: 19px;
        }

        .stat-total .reports-stat-icon {
            background: #F5E8EC;
            color: #450011;
        }

        .stat-pending .reports-stat-icon {
            background: #FFF3D9;
            color: #A66B0A;
        }

        .stat-accepted .reports-stat-icon {
            background: #E5F1E9;
            color: #39734F;
        }

        .stat-rejected .reports-stat-icon {
            background: #F0EFF3;
            color: #74717D;
        }

        .reports-stat-label {
            font-size: 12px;
            color: var(--dash-muted);
            font-weight: 600;
        }

        .reports-stat-value {
            font-size: 30px;
            font-weight: 800;
            letter-spacing: -1px;
            color: var(--dash-primary);
            margin-bottom: 5px;
        }

        .reports-stat-note {
            color: #94898C;
            font-size: 11px;
        }


        /* =========================================
           CHART PANELS
        ========================================= */

        .reports-chart-grid {
            display: grid;
            grid-template-columns: 0.9fr 1.1fr;
            gap: 20px;
            align-items: stretch;
        }

        .reports-panel {
            background: white;
            border: 1px solid var(--dash-border);
            border-radius: 16px;
            padding: 24px;
            min-width: 0;
            box-shadow: 0 5px 18px rgba(69,0,17,0.045);
        }

        .reports-panel-heading {
            margin-bottom: 23px;
        }

        .reports-panel-heading h3 {
            color: var(--dash-primary);
            font-size: 15px;
            margin: 0 0 6px;
        }

        .reports-panel-heading p {
            color: var(--dash-muted);
            font-size: 11px;
            margin: 0;
        }


        /* =========================================
           DONUT CHART
        ========================================= */

        .donut-layout {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 30px;
            flex-wrap: wrap;
            padding: 5px 0 10px;
        }

        .donut-chart {
            width: 185px;
            height: 185px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            flex-shrink: 0;
        }

        .donut-inner {
            width: 125px;
            height: 125px;
            background: white;
            border-radius: 50%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            box-shadow: 0 0 0 1px #F3EFF0;
        }

        .donut-inner strong {
            font-size: 29px;
            color: var(--dash-primary);
        }

        .donut-inner span {
            font-size: 10px;
            color: var(--dash-muted);
            margin-top: 3px;
        }

        .donut-legend {
            display: flex;
            flex-direction: column;
            gap: 17px;
            min-width: 130px;
        }

        .legend-item {
            display: flex;
            align-items: center;
            gap: 9px;
            font-size: 12px;
            color: #65595D;
        }

        .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 3px;
            flex-shrink: 0;
        }

        .legend-value {
            margin-left: auto;
            font-weight: 800;
            color: var(--dash-primary);
        }

        .legend-accepted {
            background: #450011;
        }

        .legend-pending {
            background: #D9A441;
        }

        .legend-rejected {
            background: #92909B;
        }

        .legend-other {
            background: #D9CDD1;
        }


        /* =========================================
           ACCEPTANCE RATE
        ========================================= */

        .acceptance-box {
            margin-top: 22px;
            padding: 17px;
            border-radius: 12px;
            background: #F8F3F4;
        }

        .acceptance-top {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
            margin-bottom: 11px;
        }

        .acceptance-top span {
            font-size: 12px;
            color: #65595D;
            font-weight: 600;
        }

        .acceptance-top strong {
            color: var(--dash-primary);
            font-size: 20px;
        }

        .acceptance-track {
            height: 8px;
            width: 100%;
            background: #E8DDE0;
            border-radius: 20px;
            overflow: hidden;
        }

        .acceptance-fill {
            height: 100%;
            background: var(--dash-primary);
            border-radius: 20px;
        }

        .acceptance-note {
            display: block;
            font-size: 10px;
            color: #94898C;
            margin-top: 9px;
        }


        /* =========================================
           SERVICE BAR CHART
        ========================================= */

        .service-chart-list {
            display: flex;
            flex-direction: column;
            gap: 21px;
        }

        .service-chart-item {
            display: flex;
            flex-direction: column;
            gap: 9px;
        }

        .service-chart-info {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            font-size: 12px;
        }

        .service-chart-name {
            font-weight: 700;
            color: #4A3D41;
            overflow-wrap: anywhere;
        }

        .service-chart-count {
            font-weight: 800;
            color: var(--dash-primary);
            white-space: nowrap;
        }

        .service-chart-track {
            width: 100%;
            height: 10px;
            border-radius: 20px;
            background: #F0EAEC;
            overflow: hidden;
        }

        .service-chart-fill {
            height: 100%;
            border-radius: 20px;
            background: linear-gradient(90deg, #450011, #9C4563);
            min-width: 4px;
        }

        .chart-empty {
            padding: 35px 15px;
            text-align: center;
            color: #94898C;
            font-size: 12px;
            background: #FAF8F8;
            border-radius: 12px;
        }


        /* =========================================
           DAILY ACTIVITY CHART
        ========================================= */

        .activity-chart {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .activity-row {
            display: grid;
            grid-template-columns: 85px minmax(0, 1fr) 35px;
            align-items: center;
            gap: 13px;
        }

        .activity-date {
            font-size: 11px;
            color: var(--dash-muted);
            font-weight: 600;
        }

        .activity-track {
            height: 12px;
            background: #F0EAEC;
            border-radius: 20px;
            overflow: hidden;
        }

        .activity-fill {
            height: 100%;
            background: linear-gradient(90deg, #450011, #9C4563);
            border-radius: 20px;
            min-width: 4px;
        }

        .activity-count {
            text-align: right;
            font-size: 12px;
            font-weight: 800;
            color: var(--dash-primary);
        }


        /* =========================================
           DATA TABLES
        ========================================= */

        .reports-table-panel {
            background: white;
            border: 1px solid var(--dash-border);
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 5px 18px rgba(69,0,17,0.045);
        }

        .reports-table-panel .table-wrapper {
            box-shadow: none;
            margin-top: 0;
            border: 1px solid #F0EAEC;
            overflow-x: auto;
            border-radius: 9px;
        }

        .reports-table-panel table {
            width: 100%;
            border-collapse: collapse;
            min-width: 550px;
        }

        .reports-table-panel th {
            background: #F8F3F4;
            color: #6B535B;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 14px;
            text-align: left;
            white-space: nowrap;
        }

        .reports-table-panel td {
            font-size: 12px;
            color: #51464A;
            padding: 15px 14px;
            border-bottom: 1px solid #F0EAEC;
        }

        .reports-table-panel tbody tr:last-child td {
            border-bottom: none;
        }

        .reports-table-panel tbody tr:hover {
            background: #FCF9FA;
        }

        .reports-export-button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 16px;
            background: var(--dash-primary);
            color: white;
            border-radius: 9px;
            font-size: 11px;
            font-weight: 700;
            text-decoration: none;
            transition: background 0.2s ease;
            white-space: nowrap;
        }

        .reports-export-button:hover {
            background: var(--dash-dark);
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

            .reports-stat-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .reports-chart-grid {
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

            .reports-hero {
                padding: 25px 22px;
                min-height: 150px;
            }

            .reports-hero h1 {
                font-size: 23px;
            }

            .reports-illustration {
                width: 100px;
                min-width: 75px;
            }

            .reports-stat-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 11px;
            }

            .reports-stat-card {
                padding: 15px;
            }

            .reports-stat-value {
                font-size: 25px;
            }

            .reports-stat-icon {
                width: 37px;
                height: 37px;
            }

            .reports-panel,
            .reports-table-panel {
                padding: 17px;
            }

            .donut-layout {
                gap: 20px;
            }

            .donut-chart {
                width: 155px;
                height: 155px;
            }

            .donut-inner {
                width: 105px;
                height: 105px;
            }

            .donut-inner strong {
                font-size: 24px;
            }

            .reports-section-heading {
                align-items: flex-start;
                flex-direction: column;
            }

            .reports-period {
                white-space: normal;
            }

            .activity-row {
                grid-template-columns: 70px minmax(0, 1fr) 25px;
                gap: 8px;
            }

            .dashboard-footer {
                margin: 30px -15px -20px;
                padding: 17px 15px;
                flex-direction: column;
                text-align: center;
            }

        }


        @media (max-width: 380px) {

            .reports-stat-grid {
                grid-template-columns: 1fr;
            }

            .reports-hero {
                display: block;
            }

            .reports-illustration {
                display: none;
            }

            .reports-filter-form .form-group {
                min-width: 100%;
            }

            .reports-filter-form button,
            .reports-filter-form a {
                width: 100%;
            }

        }

    </style>

</head>


<body class="admin-reports-page">

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

            <a href="dashboard.php">
                <span class="sidebar-icon">⌂</span>
                Dashboard
            </a>

            <a href="bookings.php">
                <span class="sidebar-icon">▦</span>
                Bookings
            </a>

            <a href="users.php">
                <span class="sidebar-icon">♙</span>
                Users &amp; Providers
            </a>

            <a href="services.php">
                <span class="sidebar-icon">✳</span>
                Services
            </a>

            <a href="reports.php" class="active">
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
         MAIN REPORTS AREA
    ========================================== -->

    <main class="dashboard-main">

        <div class="dashboard-content">


            <!-- TOP HEADER -->

            <header class="dashboard-header">

                <div>

                    <h2>Reports &amp; Analytics</h2>

                    <p>
                        Monitor platform performance and booking activity.
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



            <!-- HERO SECTION -->

            <section class="reports-hero">

                <div class="reports-hero-copy">

                    <span class="hero-label">
                        HomeEase Insights
                    </span>

                    <h1>
                        Understand Your Platform.
                    </h1>

                    <p>
                        Get a clearer picture of your platform's performance.
                        Monitor bookings, discover popular services and
                        understand customer activity.
                    </p>

                </div>


                <div class="reports-illustration">

                    <svg viewBox="0 0 320 220" fill="none"
                         xmlns="http://www.w3.org/2000/svg">

                        <circle cx="258" cy="42" r="29"
                                fill="#F1C9D4" fill-opacity="0.18"/>

                        <circle cx="35" cy="183" r="20"
                                fill="#FFFFFF" fill-opacity="0.12"/>

                        <rect x="37" y="30" width="235" height="155"
                              rx="14" fill="#FFFFFF"/>

                        <rect x="52" y="45" width="105" height="9"
                              rx="4.5" fill="#E8DDE0"/>

                        <rect x="52" y="62" width="65" height="5"
                              rx="2.5" fill="#F1E9EB"/>

                        <rect x="52" y="84" width="205" height="83"
                              rx="8" fill="#FAF6F7"/>

                        <path d="M65 146L93 125L119 134L151 101L180 115L215 87L244 98"
                              stroke="#8C3452" stroke-width="4"
                              stroke-linecap="round" stroke-linejoin="round"/>

                        <circle cx="93" cy="125" r="4" fill="#8C3452"/>
                        <circle cx="151" cy="101" r="4" fill="#8C3452"/>
                        <circle cx="215" cy="87" r="4" fill="#8C3452"/>

                        <rect x="69" y="139" width="12" height="17"
                              rx="3" fill="#DDB9C4"/>

                        <rect x="89" y="128" width="12" height="28"
                              rx="3" fill="#C78DA0"/>

                        <rect x="109" y="117" width="12" height="39"
                              rx="3" fill="#B66B83"/>

                        <rect x="129" y="108" width="12" height="48"
                              rx="3" fill="#9C4563"/>

                        <rect x="149" y="95" width="12" height="61"
                              rx="3" fill="#450011"/>

                        <circle cx="252" cy="163" r="35"
                                fill="#F0DCE2"/>

                        <path d="M252 128 A35 35 0 0 1 283 179"
                              stroke="#450011" stroke-width="10"
                              stroke-linecap="round"/>

                        <path d="M283 179 A35 35 0 0 1 222 179"
                              stroke="#D9A441" stroke-width="10"
                              stroke-linecap="round"/>

                        <path d="M222 179 A35 35 0 0 1 252 128"
                              stroke="#B5B1BC" stroke-width="10"
                              stroke-linecap="round"/>

                        <circle cx="282" cy="65" r="7" fill="#E7C0CC"/>
                        <circle cx="25" cy="65" r="5" fill="#FFFFFF" fill-opacity="0.7"/>

                    </svg>

                </div>

            </section>



            <!-- DATE FILTER -->

            <section class="reports-filter">

                <div class="reports-filter-heading">

                    <h2>Reporting Period</h2>

                    <p>
                        Select a date range to update the statistics and charts.
                    </p>

                </div>


                <form method="GET" action="reports.php"
                      class="reports-filter-form">

                    <div class="form-group">

                        <label for="start_date">Start Date</label>

                        <input
                            type="date"
                            id="start_date"
                            name="start_date"
                            value="<?= escapeOutput($startDate) ?>"
                            max="<?= date("Y-m-d") ?>"
                            required
                        >

                    </div>


                    <div class="form-group">

                        <label for="end_date">End Date</label>

                        <input
                            type="date"
                            id="end_date"
                            name="end_date"
                            value="<?= escapeOutput($endDate) ?>"
                            max="<?= date("Y-m-d") ?>"
                            required
                        >

                    </div>


                    <button type="submit">
                        Generate Report
                    </button>

                    <a href="reports.php">
                        Reset Dates
                    </a>

                </form>

            </section>



            <!-- BOOKING OVERVIEW -->

            <section class="reports-section">

                <div class="reports-section-heading">

                    <div>

                        <span class="section-kicker">
                            AT A GLANCE
                        </span>

                        <h2>Booking Overview</h2>

                        <p>
                            A summary of platform bookings for the selected period.
                        </p>

                    </div>

                    <span class="reports-period">

                        <?= escapeOutput(formatReportDate($startDate)) ?>
                        - <?= escapeOutput(formatReportDate($endDate)) ?>

                    </span>

                </div>


                <div class="reports-stat-grid">


                    <article class="reports-stat-card stat-total">

                        <div class="reports-stat-top">

                            <span class="reports-stat-label">
                                Total Bookings
                            </span>

                            <span class="reports-stat-icon">▤</span>

                        </div>

                        <div class="reports-stat-value">
                            <?= $totalBookings ?>
                        </div>

                        <div class="reports-stat-note">
                            All booking requests
                        </div>

                    </article>



                    <article class="reports-stat-card stat-pending">

                        <div class="reports-stat-top">

                            <span class="reports-stat-label">
                                Pending
                            </span>

                            <span class="reports-stat-icon">◷</span>

                        </div>

                        <div class="reports-stat-value">
                            <?= $pendingBookings ?>
                        </div>

                        <div class="reports-stat-note">
                            Awaiting provider response
                        </div>

                    </article>



                    <article class="reports-stat-card stat-accepted">

                        <div class="reports-stat-top">

                            <span class="reports-stat-label">
                                Accepted
                            </span>

                            <span class="reports-stat-icon">✓</span>

                        </div>

                        <div class="reports-stat-value">
                            <?= $acceptedBookings ?>
                        </div>

                        <div class="reports-stat-note">
                            Confirmed requests
                        </div>

                    </article>



                    <article class="reports-stat-card stat-rejected">

                        <div class="reports-stat-top">

                            <span class="reports-stat-label">
                                Rejected
                            </span>

                            <span class="reports-stat-icon">×</span>

                        </div>

                        <div class="reports-stat-value">
                            <?= $rejectedBookings ?>
                        </div>

                        <div class="reports-stat-note">
                            Declined requests
                        </div>

                    </article>


                </div>

            </section>



            <!-- PERFORMANCE ANALYTICS -->

            <section class="reports-section">

                <div class="reports-section-heading">

                    <div>

                        <span class="section-kicker">
                            PLATFORM PERFORMANCE
                        </span>

                        <h2>Performance Analytics</h2>

                        <p>
                            Visual insights into booking outcomes and service demand.
                        </p>

                    </div>

                </div>


                <div class="reports-chart-grid">


                    <!-- BOOKING STATUS DONUT -->

                    <div class="reports-panel">

                        <div class="reports-panel-heading">

                            <h3>Booking Status Distribution</h3>

                            <p>
                                Distribution of booking requests by status.
                            </p>

                        </div>


                        <div class="donut-layout">

                            <div class="donut-chart" style="<?= $donutStyle ?>">

                                <div class="donut-inner">

                                    <strong><?= $totalBookings ?></strong>

                                    <span>Total Bookings</span>

                                </div>

                            </div>


                            <div class="donut-legend">


                                <div class="legend-item">

                                    <span class="legend-dot legend-accepted"></span>

                                    Accepted

                                    <span class="legend-value">
                                        <?= $acceptedBookings ?>
                                    </span>

                                </div>


                                <div class="legend-item">

                                    <span class="legend-dot legend-pending"></span>

                                    Pending

                                    <span class="legend-value">
                                        <?= $pendingBookings ?>
                                    </span>

                                </div>


                                <div class="legend-item">

                                    <span class="legend-dot legend-rejected"></span>

                                    Rejected

                                    <span class="legend-value">
                                        <?= $rejectedBookings ?>
                                    </span>

                                </div>


                                <?php if ($otherBookings > 0): ?>

                                    <div class="legend-item">

                                        <span class="legend-dot legend-other"></span>

                                        Other statuses

                                        <span class="legend-value">
                                            <?= $otherBookings ?>
                                        </span>

                                    </div>

                                <?php endif; ?>


                            </div>

                        </div>


                        <div class="acceptance-box">

                            <div class="acceptance-top">

                                <span>Booking Acceptance Rate</span>

                                <strong>
                                    <?= number_format($acceptanceRate, 1) ?>%
                                </strong>

                            </div>

                            <div class="acceptance-track">

                                <div
                                    class="acceptance-fill"
                                    style="width: <?= $acceptanceRate ?>%;"
                                ></div>

                            </div>

                            <small class="acceptance-note">
                                Accepted bookings as a percentage of all bookings.
                            </small>

                        </div>

                    </div>



                    <!-- MOST REQUESTED SERVICES -->

                    <div class="reports-panel">

                        <div class="reports-panel-heading">

                            <h3>Most Requested Services</h3>

                            <p>
                                Service demand based on customer bookings.
                            </p>

                        </div>


                        <?php if (count($services) > 0): ?>

                            <div class="service-chart-list">

                                <?php foreach ($services as $service): ?>

                                    <?php

                                    $serviceWidth = $maxServiceRequests > 0
                                        ? ((int)$service["total_requests"] / $maxServiceRequests) * 100
                                        : 0;

                                    ?>

                                    <div class="service-chart-item">

                                        <div class="service-chart-info">

                                            <span class="service-chart-name">

                                                <?= escapeOutput($service["service_name"]) ?>

                                            </span>

                                            <span class="service-chart-count">

                                                <?= (int)$service["total_requests"] ?>
                                                requests

                                            </span>

                                        </div>


                                        <div class="service-chart-track">

                                            <div
                                                class="service-chart-fill"
                                                style="width: <?= $serviceWidth ?>%;"
                                            ></div>

                                        </div>

                                    </div>

                                <?php endforeach; ?>

                            </div>

                        <?php else: ?>

                            <div class="chart-empty">

                                No service requests were recorded during this period.

                            </div>

                        <?php endif; ?>

                    </div>


                </div>

            </section>



            <!-- DAILY BOOKING ACTIVITY -->

            <section class="reports-section">

                <div class="reports-panel">

                    <div class="reports-section-heading">

                        <div>

                            <span class="section-kicker">
                                BOOKING TRENDS
                            </span>

                            <h2>Daily Booking Activity</h2>

                            <p>
                                Booking volume across the latest ten active dates
                                in the selected period.
                            </p>

                        </div>

                    </div>


                    <?php if (count($activities) > 0): ?>

                        <div class="activity-chart">

                            <?php foreach ($activities as $activity): ?>

                                <?php

                                $activityWidth = $maxDailyBookings > 0
                                    ? ((int)$activity["total_bookings"] / $maxDailyBookings) * 100
                                    : 0;

                                ?>

                                <div class="activity-row">

                                    <span class="activity-date">

                                        <?= escapeOutput(
                                            date("d M", strtotime($activity["booking_date"]))
                                        ) ?>

                                    </span>


                                    <div class="activity-track">

                                        <div
                                            class="activity-fill"
                                            style="width: <?= $activityWidth ?>%;"
                                        ></div>

                                    </div>


                                    <span class="activity-count">

                                        <?= (int)$activity["total_bookings"] ?>

                                    </span>

                                </div>

                            <?php endforeach; ?>

                        </div>

                    <?php else: ?>

                        <div class="chart-empty">

                            No booking activity was recorded during this period.

                        </div>

                    <?php endif; ?>

                </div>

            </section>



            <!-- SERVICE PERFORMANCE TABLE -->

            <section class="reports-section">

                <div class="reports-table-panel">

                    <div class="reports-section-heading">

                        <div>

                            <span class="section-kicker">
                                SERVICE DEMAND
                            </span>

                            <h2>Service Performance</h2>

                            <p>
                                Detailed booking figures for each listed service.
                            </p>

                        </div>

                    </div>


                    <div class="table-wrapper">

                        <table>

                            <thead>

                                <tr>

                                    <th>Service Name</th>
                                    <th>Total Requests</th>
                                    <th>Accepted Requests</th>

                                </tr>

                            </thead>


                            <tbody>

                                <?php if (count($services) > 0): ?>

                                    <?php foreach ($services as $service): ?>

                                        <tr>

                                            <td>

                                                <strong>
                                                    <?= escapeOutput($service["service_name"]) ?>
                                                </strong>

                                            </td>

                                            <td>
                                                <?= (int)$service["total_requests"] ?>
                                            </td>

                                            <td>
                                                <?= (int)$service["accepted_requests"] ?>
                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                <?php else: ?>

                                    <tr>

                                        <td colspan="3">

                                            No service data available for this period.

                                        </td>

                                    </tr>

                                <?php endif; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            </section>



            <!-- BOOKING ACTIVITY RECORDS -->

            <section class="reports-section">

                <div class="reports-table-panel">

                    <div class="reports-section-heading">

                        <div>

                            <span class="section-kicker">
                                ACTIVITY RECORDS
                            </span>

                            <h2>Booking Activity Records</h2>

                            <p>
                                Daily breakdown of accepted, pending and rejected requests.
                            </p>

                        </div>


                        <a href="<?= escapeOutput($exportUrl) ?>"
                           class="reports-export-button">

                            ↓ Export CSV

                        </a>

                    </div>


                    <div class="table-wrapper">

                        <table>

                            <thead>

                                <tr>

                                    <th>Booking Date</th>
                                    <th>Total Bookings</th>
                                    <th>Accepted</th>
                                    <th>Pending</th>
                                    <th>Rejected</th>

                                </tr>

                            </thead>


                            <tbody>

                                <?php if (count($activities) > 0): ?>

                                    <?php foreach (array_reverse($activities) as $activity): ?>

                                        <tr>

                                            <td>

                                                <strong>

                                                    <?= escapeOutput(
                                                        formatReportDate($activity["booking_date"])
                                                    ) ?>

                                                </strong>

                                            </td>

                                            <td>
                                                <?= (int)$activity["total_bookings"] ?>
                                            </td>

                                            <td>
                                                <?= (int)$activity["accepted_bookings"] ?>
                                            </td>

                                            <td>
                                                <?= (int)$activity["pending_bookings"] ?>
                                            </td>

                                            <td>
                                                <?= (int)$activity["rejected_bookings"] ?>
                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                <?php else: ?>

                                    <tr>

                                        <td colspan="5">

                                            No booking activity found for this period.

                                        </td>

                                    </tr>

                                <?php endif; ?>

                            </tbody>

                        </table>

                    </div>

                </div>

            </section>



            <!-- FOOTER -->

            <footer class="dashboard-footer">

                <span>
                    © <?= date("Y") ?>
                    <strong>HomeEase Administration.</strong>
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

<?php
$conn->close();
?>
