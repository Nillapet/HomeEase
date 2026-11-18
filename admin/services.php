
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


// Format dates.
function formatServiceDate($date)
{
    if (empty($date)) {
        return "Not available";
    }

    $timestamp = strtotime($date);

    return $timestamp === false
        ? "Not available"
        : date("d M Y", $timestamp);
}


// Get administrator details.
$adminName = $_SESSION["full_name"] ?? "Administrator";
$firstName = explode(" ", trim($adminName))[0];


// Get search and location filters.
$searchTerm = trim($_GET["search"] ?? "");
$locationFilter = trim($_GET["location"] ?? "");

$searchPattern = "%" . $searchTerm . "%";
$locationPattern = "%" . $locationFilter . "%";


// Retrieve service statistics.
$statisticsSql = "
    SELECT
        COUNT(s.id) AS total_services,
        COUNT(DISTINCT s.provider_id) AS total_providers,
        COUNT(DISTINCT NULLIF(TRIM(p.location), '')) AS total_locations
    FROM services s
    INNER JOIN users u ON s.provider_id = u.id
    LEFT JOIN provider_profiles p ON p.user_id = u.id
";

$statisticsResult = $conn->query($statisticsSql);

$statistics = [
    "total_services" => 0,
    "total_providers" => 0,
    "total_locations" => 0
];

if ($statisticsResult) {
    $statistics = $statisticsResult->fetch_assoc();
}


// Retrieve services using the selected filters.
$sql = "
    SELECT
        s.id,
        s.service_name,
        s.description,
        s.created_at,
        u.full_name AS provider_name,
        u.email AS provider_email,
        p.location
    FROM services s
    INNER JOIN users u ON s.provider_id = u.id
    LEFT JOIN provider_profiles p ON p.user_id = u.id
    WHERE
        (
            ? = ''
            OR s.service_name LIKE ?
            OR s.description LIKE ?
            OR u.full_name LIKE ?
            OR u.email LIKE ?
        )
        AND (
            ? = ''
            OR COALESCE(p.location, '') LIKE ?
        )
    ORDER BY s.created_at DESC
";

$stmt = $conn->prepare($sql);

$services = [];
$error = "";

if (!$stmt) {

    $error = "Unable to prepare the services query.";

} else {

    $stmt->bind_param(
        "sssssss",
        $searchTerm,
        $searchPattern,
        $searchPattern,
        $searchPattern,
        $searchPattern,
        $locationFilter,
        $locationPattern
    );

    if ($stmt->execute()) {

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $services[] = $row;
        }

    } else {

        $error = "Unable to retrieve the listed services.";

    }

    $stmt->close();
}

$totalDisplayed = count($services);


// Count providers with at least one listed service.
$providerCount = (int)($statistics["total_providers"] ?? 0);

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Service Listings | HomeEase Admin</title>

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

        body.admin-services-page {
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

        .services-layout {
            display: flex;
            min-height: 100vh;
        }


        /* SIDEBAR */

        .services-sidebar {
            width: 255px;
            min-width: 255px;
            background: var(--burgundy-dark);
            color: white;
            padding: 30px 18px;
            display: flex;
            flex-direction: column;
        }

        .services-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 12px 35px;
            text-decoration: none;
            color: white;
        }

        .services-brand-icon {
            width: 43px;
            height: 43px;
            display: grid;
            place-items: center;
            border-radius: 13px;
            background: rgba(255,255,255,0.12);
            font-size: 27px;
        }

        .services-brand-copy strong {
            display: block;
            font-size: 21px;
            letter-spacing: -0.7px;
        }

        .services-brand-copy strong span {
            color: #D9AAB6;
        }

        .services-brand-copy small {
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

        .services-nav {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .services-nav a {
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

        .services-nav a:hover {
            background: rgba(255,255,255,0.09);
            color: white;
        }

        .services-nav a.active {
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

        .services-main {
            flex: 1;
            min-width: 0;
            padding: 30px 38px;
        }

        .services-topbar {
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

        .service-stats {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 17px;
            margin-bottom: 30px;
        }

        .service-stat-card {
            background: var(--white);
            padding: 22px;
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
            width: 36px;
            height: 36px;
            display: grid;
            place-items: center;
            border-radius: 10px;
            background: var(--burgundy-light);
            color: var(--burgundy);
            font-size: 17px;
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


        /* SERVICE DIRECTORY */

        .service-panel {
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

        .service-count {
            padding: 8px 12px;
            background: var(--burgundy-light);
            color: var(--burgundy);
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            white-space: nowrap;
        }


        /* FILTER FORM */

        .filter-area {
            margin: 0 25px 20px;
            padding: 18px;
            background: #FBF9F8;
            border: 1px solid var(--border);
            border-radius: 11px;
        }

        .filter-form {
            display: grid;
            grid-template-columns: minmax(180px, 1fr) minmax(170px, 0.8fr) auto auto;
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

        .form-group input {
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

        .form-group input:focus {
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


        /* TABLE */

        .table-scroll {
            width: 100%;
            overflow-x: auto;
        }

        .services-table {
            width: 100%;
            min-width: 950px;
            border-collapse: collapse;
        }

        .services-table thead {
            background: #FBF8F7;
        }

        .services-table th {
            padding: 15px 16px;
            color: #796B70;
            font-size: 10px;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.7px;
            white-space: nowrap;
        }

        .services-table td {
            padding: 16px;
            border-top: 1px solid #F1EAEA;
            color: #55494D;
            font-size: 12px;
            vertical-align: middle;
        }

        .services-table tbody tr:hover {
            background: #FDFBFA;
        }

        .service-name {
            min-width: 145px;
            color: #35272C;
            font-weight: 700;
        }

        .service-id {
            display: block;
            margin-top: 5px;
            color: #94878B;
            font-size: 10px;
            font-weight: 400;
        }

        .service-description {
            max-width: 260px;
            min-width: 200px;
            color: #776A6E;
            line-height: 1.6;
        }

        .provider-name {
            min-width: 130px;
            color: #35272C;
            font-weight: 600;
        }

        .provider-email {
            min-width: 180px;
            overflow-wrap: anywhere;
        }

        .location-label {
            display: inline-block;
            padding: 6px 9px;
            background: #F3F0ED;
            color: #65575B;
            border-radius: 6px;
            white-space: nowrap;
            font-size: 10px;
            font-weight: 600;
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

        .services-footer {
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

            .services-sidebar {
                width: 220px;
                min-width: 220px;
            }

            .services-main {
                padding: 25px;
            }

            .filter-form {
                grid-template-columns: 1fr 1fr;
            }

        }

        @media (max-width: 700px) {

            .services-layout {
                flex-direction: column;
            }

            .services-sidebar {
                width: 100%;
                min-width: 0;
                padding: 18px;
            }

            .services-brand {
                padding: 0 5px 18px;
            }

            .sidebar-label,
            .sidebar-bottom {
                display: none;
            }

            .services-nav {
                flex-direction: row;
                overflow-x: auto;
                padding-bottom: 5px;
            }

            .services-nav a {
                flex: 0 0 auto;
                padding: 10px 12px;
                font-size: 11px;
            }

            .services-main {
                padding: 22px 15px;
            }

            .services-topbar {
                margin-bottom: 28px;
            }

            .page-intro h1 {
                font-size: 25px;
            }

            .service-stats {
                grid-template-columns: 1fr;
                gap: 10px;
            }

            .service-stat-card {
                padding: 17px;
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

            .services-footer {
                flex-direction: column;
            }

        }

    </style>

</head>


<body class="admin-services-page">

<div class="services-layout">


    <!-- SIDEBAR -->

    <aside class="services-sidebar">

        <a href="dashboard.php" class="services-brand">

            <span class="services-brand-icon">⌂</span>

            <span class="services-brand-copy">
                <strong>Home<span>Ease</span></strong>
                <small>ADMINISTRATION</small>
            </span>

        </a>


        <div class="sidebar-label">
            MAIN MENU
        </div>


        <nav class="services-nav" aria-label="Administrator navigation">

            <a href="dashboard.php">
                <span class="nav-icon">▦</span>
                Dashboard
            </a>

            <a href="users.php">
                <span class="nav-icon">♙</span>
                Users & Providers
            </a>

            <a href="services.php" class="active">
                <span class="nav-icon">⌂</span>
                Service Listings
            </a>

            <a href="bookings.php">
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

    <main class="services-main">


        <!-- TOP BAR -->

        <header class="services-topbar">

            <div class="topbar-label">
                HomeEase / Service Management
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

            <h1>Service Listings</h1>

            <p>
                View and monitor home services listed by registered
                providers across different locations.
            </p>

        </section>



        <!-- SERVICE STATISTICS -->

        <section class="service-stats">


            <article class="service-stat-card">

                <div class="stat-top">
                    <span>Total Listed Services</span>
                    <span class="stat-icon">⌂</span>
                </div>

                <div class="stat-number">
                    <?= (int)$statistics["total_services"] ?>
                </div>

                <div class="stat-caption">
                    Services listed on HomeEase
                </div>

            </article>


            <article class="service-stat-card">

                <div class="stat-top">
                    <span>Providers Offering Services</span>
                    <span class="stat-icon">♙</span>
                </div>

                <div class="stat-number">
                    <?= $providerCount ?>
                </div>

                <div class="stat-caption">
                    Providers with service listings
                </div>

            </article>


            <article class="service-stat-card">

                <div class="stat-top">
                    <span>Locations Covered</span>
                    <span class="stat-icon">⌖</span>
                </div>

                <div class="stat-number">
                    <?= (int)$statistics["total_locations"] ?>
                </div>

                <div class="stat-caption">
                    Different provider locations
                </div>

            </article>


        </section>



        <!-- SERVICE DIRECTORY -->

        <section class="service-panel">


            <div class="panel-heading">

                <div>

                    <h2>Listed Services</h2>

                    <p>
                        Browse services and the providers offering them.
                    </p>

                </div>

                <span class="service-count">
                    <?= $totalDisplayed ?> found
                </span>

            </div>



            <!-- SEARCH AND LOCATION FILTER -->

            <div class="filter-area">

                <form
                    method="GET"
                    action="services.php"
                    class="filter-form"
                >

                    <div class="form-group">

                        <label for="search">
                            Search Services or Providers
                        </label>

                        <input
                            type="search"
                            id="search"
                            name="search"
                            placeholder="Service name, provider or email..."
                            value="<?= escapeOutput($searchTerm) ?>"
                        >

                    </div>


                    <div class="form-group">

                        <label for="location">
                            Provider Location
                        </label>

                        <input
                            type="text"
                            id="location"
                            name="location"
                            placeholder="Enter a location..."
                            value="<?= escapeOutput($locationFilter) ?>"
                        >

                    </div>


                    <button type="submit" class="filter-button">
                        Apply Filters
                    </button>


                    <a href="services.php" class="clear-button">
                        Clear Filters
                    </a>

                </form>

            </div>



            <!-- SERVICES TABLE -->

            <?php if ($error !== ""): ?>

                <div class="error-message">
                    <?= escapeOutput($error) ?>
                </div>

            <?php else: ?>

                <div class="table-scroll">

                    <table class="services-table">

                        <thead>

                            <tr>
                                <th>Service</th>
                                <th>Description</th>
                                <th>Provider</th>
                                <th>Provider Email</th>
                                <th>Location</th>
                                <th>Date Added</th>
                            </tr>

                        </thead>


                        <tbody>

                            <?php if ($totalDisplayed > 0): ?>

                                <?php foreach ($services as $service): ?>

                                    <tr>

                                        <td>

                                            <div class="service-name">
                                                <?= escapeOutput($service["service_name"]) ?>

                                                <span class="service-id">
                                                    Service #<?= (int)$service["id"] ?>
                                                </span>
                                            </div>

                                        </td>


                                        <td>

                                            <div class="service-description">
                                                <?= escapeOutput(
                                                    $service["description"] ?: "No description provided"
                                                ) ?>
                                            </div>

                                        </td>


                                        <td>
                                            <div class="provider-name">
                                                <?= escapeOutput($service["provider_name"]) ?>
                                            </div>
                                        </td>


                                        <td>
                                            <div class="provider-email">
                                                <?= escapeOutput($service["provider_email"]) ?>
                                            </div>
                                        </td>


                                        <td>

                                            <span class="location-label">
                                                <?= escapeOutput(
                                                    $service["location"] ?: "Not provided"
                                                ) ?>
                                            </span>

                                        </td>


                                        <td>
                                            <?= escapeOutput(
                                                formatServiceDate($service["created_at"])
                                            ) ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>

                                    <td colspan="6" class="empty-message">

                                        No services were found.

                                        <br>

                                        Try changing your search
                                        or location filter.

                                    </td>

                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>


        </section>



        <!-- FOOTER -->

        <footer class="services-footer">

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
