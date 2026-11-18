
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


// Format registration dates.
function formatRegistrationDate($date)
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


// Get and validate the selected role.
$selectedRole = $_GET["role"] ?? "all";

$allowedRoles = [
    "all",
    "customer",
    "provider",
    "admin"
];

if (!in_array($selectedRole, $allowedRoles, true)) {
    $selectedRole = "all";
}


// Get search term.
$search = trim($_GET["search"] ?? "");


// Retrieve account statistics.
$statisticsSql = "
    SELECT
        COUNT(*) AS total_users,
        COALESCE(SUM(role = 'customer'), 0) AS total_customers,
        COALESCE(SUM(role = 'provider'), 0) AS total_providers,
        COALESCE(SUM(role = 'admin'), 0) AS total_admins
    FROM users
";

$statisticsResult = $conn->query($statisticsSql);

$statistics = [
    "total_users" => 0,
    "total_customers" => 0,
    "total_providers" => 0,
    "total_admins" => 0
];

if ($statisticsResult) {
    $statistics = $statisticsResult->fetch_assoc();
}


// Retrieve accounts using search and role filters.
$sql = "
    SELECT
        id,
        full_name,
        email,
        phone,
        role,
        created_at
    FROM users
    WHERE
        (? = 'all' OR role = ?)
        AND (
            ? = ''
            OR full_name LIKE ?
            OR email LIKE ?
            OR phone LIKE ?
        )
    ORDER BY created_at DESC
";

$stmt = $conn->prepare($sql);

$users = [];
$error = "";

if (!$stmt) {

    $error = "Unable to prepare the users query.";

} else {

    $searchPattern = "%" . $search . "%";

    $stmt->bind_param(
        "ssssss",
        $selectedRole,
        $selectedRole,
        $search,
        $searchPattern,
        $searchPattern,
        $searchPattern
    );

    if ($stmt->execute()) {

        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }

    } else {

        $error = "Unable to retrieve user accounts.";

    }

    $stmt->close();
}


// Get role badge class.
function getRoleClass($role)
{
    switch ($role) {
        case "customer":
            return "customer";

        case "provider":
            return "provider";

        case "admin":
            return "administrator";

        default:
            return "default";
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Users & Providers | HomeEase Admin</title>

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

        body.admin-users-page {
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

        .users-layout {
            display: flex;
            min-height: 100vh;
        }

        /* SIDEBAR */

        .users-sidebar {
            width: 255px;
            min-width: 255px;
            background: var(--burgundy-dark);
            color: white;
            padding: 30px 18px;
            display: flex;
            flex-direction: column;
        }

        .users-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 0 12px 35px;
            text-decoration: none;
            color: white;
        }

        .users-brand-icon {
            width: 43px;
            height: 43px;
            display: grid;
            place-items: center;
            border-radius: 13px;
            background: rgba(255,255,255,0.12);
            font-size: 27px;
        }

        .users-brand-copy strong {
            display: block;
            font-size: 21px;
            letter-spacing: -0.7px;
        }

        .users-brand-copy strong span {
            color: #D9AAB6;
        }

        .users-brand-copy small {
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

        .users-nav {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        .users-nav a {
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

        .users-nav a:hover {
            background: rgba(255,255,255,0.09);
            color: white;
        }

        .users-nav a.active {
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


        /* MAIN AREA */

        .users-main {
            flex: 1;
            min-width: 0;
            padding: 30px 38px;
        }

        .users-topbar {
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


        /* PAGE HEADING */

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

        .user-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 17px;
            margin-bottom: 30px;
        }

        .user-stat-card {
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
            width: 34px;
            height: 34px;
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


        /* ACCOUNT DIRECTORY */

        .account-panel {
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

        .account-count {
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


        /* TABLE */

        .table-scroll {
            width: 100%;
            overflow-x: auto;
        }

        .users-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px;
        }

        .users-table thead {
            background: #FBF8F7;
        }

        .users-table th {
            padding: 15px 17px;
            color: #796B70;
            font-size: 10px;
            text-align: left;
            text-transform: uppercase;
            letter-spacing: 0.7px;
            white-space: nowrap;
        }

        .users-table td {
            padding: 16px 17px;
            border-top: 1px solid #F1EAEA;
            color: #55494D;
            font-size: 12px;
            vertical-align: middle;
        }

        .users-table tbody tr:hover {
            background: #FDFBFA;
        }

        .user-identity {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 160px;
        }

        .user-initial {
            width: 34px;
            height: 34px;
            min-width: 34px;
            display: grid;
            place-items: center;
            border-radius: 50%;
            background: var(--burgundy-light);
            color: var(--burgundy);
            font-size: 12px;
            font-weight: 700;
        }

        .user-name {
            color: #35272C;
            font-weight: 700;
        }

        .user-id {
            color: #94878B;
            font-size: 10px;
        }

        .role-badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            white-space: nowrap;
        }

        .role-badge.customer {
            background: #EAF2FC;
            color: #315E91;
        }

        .role-badge.provider {
            background: #E8F3EA;
            color: #367347;
        }

        .role-badge.administrator {
            background: #F4E5E9;
            color: var(--burgundy);
        }

        .role-badge.default {
            background: #F1EEEE;
            color: #655B5E;
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

        .users-footer {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            margin-top: 28px;
            padding: 20px 0;
            border-top: 1px solid #E9DFDF;
            color: var(--muted);
            font-size: 11px;
        }


        /* RESPONSIVE DESIGN */

        @media (max-width: 1100px) {

            .users-sidebar {
                width: 220px;
                min-width: 220px;
            }

            .users-main {
                padding: 25px;
            }

            .user-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .filter-form {
                grid-template-columns: 1fr 1fr;
            }

        }

        @media (max-width: 700px) {

            .users-layout {
                flex-direction: column;
            }

            .users-sidebar {
                width: 100%;
                min-width: 0;
                padding: 18px;
            }

            .users-brand {
                padding: 0 5px 18px;
            }

            .sidebar-label,
            .sidebar-bottom {
                display: none;
            }

            .users-nav {
                flex-direction: row;
                overflow-x: auto;
                padding-bottom: 5px;
            }

            .users-nav a {
                flex: 0 0 auto;
                padding: 10px 12px;
                font-size: 11px;
            }

            .users-main {
                padding: 22px 15px;
            }

            .users-topbar {
                margin-bottom: 28px;
            }

            .page-intro h1 {
                font-size: 25px;
            }

            .user-stats {
                gap: 10px;
            }

            .user-stat-card {
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

            .users-footer {
                flex-direction: column;
            }

        }

        @media (max-width: 380px) {

            .user-stats {
                grid-template-columns: 1fr;
            }

            .admin-greeting {
                gap: 7px;
            }

            .admin-greeting small {
                font-size: 10px;
            }

        }

    </style>

</head>


<body class="admin-users-page">

<div class="users-layout">


    <!-- SIDEBAR -->

    <aside class="users-sidebar">

        <a href="dashboard.php" class="users-brand">

            <span class="users-brand-icon">⌂</span>

            <span class="users-brand-copy">
                <strong>Home<span>Ease</span></strong>
                <small>ADMINISTRATION</small>
            </span>

        </a>


        <div class="sidebar-label">
            MAIN MENU
        </div>


        <nav class="users-nav" aria-label="Administrator navigation">

            <a href="dashboard.php">
                <span class="nav-icon">▦</span>
                Dashboard
            </a>

            <a href="users.php" class="active">
                <span class="nav-icon">♙</span>
                Users & Providers
            </a>

            <a href="services.php">
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

    <main class="users-main">


        <!-- TOP BAR -->

        <header class="users-topbar">

            <div class="topbar-label">
                HomeEase / Account Management
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

            <h1>Users & Providers</h1>

            <p>
                Manage and monitor customer accounts, service providers
                and administrator accounts registered on HomeEase.
            </p>

        </section>



        <!-- STATISTICS -->

        <section class="user-stats">


            <article class="user-stat-card">

                <div class="stat-top">
                    <span>Total Accounts</span>
                    <span class="stat-icon">♙</span>
                </div>

                <div class="stat-number">
                    <?= (int)$statistics["total_users"] ?>
                </div>

                <div class="stat-caption">
                    All registered accounts
                </div>

            </article>


            <article class="user-stat-card">

                <div class="stat-top">
                    <span>Customers</span>
                    <span class="stat-icon">♧</span>
                </div>

                <div class="stat-number">
                    <?= (int)$statistics["total_customers"] ?>
                </div>

                <div class="stat-caption">
                    People seeking home services
                </div>

            </article>


            <article class="user-stat-card">

                <div class="stat-top">
                    <span>Service Providers</span>
                    <span class="stat-icon">⌂</span>
                </div>

                <div class="stat-number">
                    <?= (int)$statistics["total_providers"] ?>
                </div>

                <div class="stat-caption">
                    Professionals offering services
                </div>

            </article>


            <article class="user-stat-card">

                <div class="stat-top">
                    <span>Administrators</span>
                    <span class="stat-icon">✦</span>
                </div>

                <div class="stat-number">
                    <?= (int)$statistics["total_admins"] ?>
                </div>

                <div class="stat-caption">
                    Platform management accounts
                </div>

            </article>


        </section>



        <!-- ACCOUNT DIRECTORY -->

        <section class="account-panel">


            <div class="panel-heading">

                <div>

                    <h2>Registered Accounts</h2>

                    <p>
                        Browse and find accounts registered on the platform.
                    </p>

                </div>

                <span class="account-count">
                    <?= count($users) ?> found
                </span>

            </div>



            <!-- SEARCH AND FILTER -->

            <div class="filter-area">

                <form
                    method="GET"
                    action="users.php"
                    class="filter-form"
                >

                    <div class="form-group">

                        <label for="search">
                            Search Accounts
                        </label>

                        <input
                            type="search"
                            name="search"
                            id="search"
                            placeholder="Name, email or phone number"
                            value="<?= escapeOutput($search) ?>"
                        >

                    </div>


                    <div class="form-group">

                        <label for="role">
                            Account Type
                        </label>

                        <select name="role" id="role">

                            <option value="all"
                                <?= $selectedRole === "all" ? "selected" : "" ?>>
                                All Accounts
                            </option>

                            <option value="customer"
                                <?= $selectedRole === "customer" ? "selected" : "" ?>>
                                Customers
                            </option>

                            <option value="provider"
                                <?= $selectedRole === "provider" ? "selected" : "" ?>>
                                Service Providers
                            </option>

                            <option value="admin"
                                <?= $selectedRole === "admin" ? "selected" : "" ?>>
                                Administrators
                            </option>

                        </select>

                    </div>


                    <button type="submit" class="filter-button">
                        Apply Filters
                    </button>


                    <a href="users.php" class="clear-button">
                        Clear Filters
                    </a>

                </form>

            </div>



            <!-- ACCOUNTS TABLE -->

            <?php if ($error !== ""): ?>

                <div class="error-message">
                    <?= escapeOutput($error) ?>
                </div>

            <?php else: ?>

                <div class="table-scroll">

                    <table class="users-table">

                        <thead>

                            <tr>
                                <th>Account</th>
                                <th>Email Address</th>
                                <th>Phone Number</th>
                                <th>Account Role</th>
                                <th>Date Registered</th>
                            </tr>

                        </thead>


                        <tbody>

                            <?php if (count($users) > 0): ?>

                                <?php foreach ($users as $user): ?>

                                    <?php
                                        $fullName = trim($user["full_name"] ?? "");
                                        $initial = strtoupper(
                                            substr($fullName !== "" ? $fullName : "U", 0, 1)
                                        );
                                    ?>

                                    <tr>

                                        <td>

                                            <div class="user-identity">

                                                <span class="user-initial">
                                                    <?= escapeOutput($initial) ?>
                                                </span>

                                                <div>
                                                    <div class="user-name">
                                                        <?= escapeOutput($fullName ?: "Unnamed User") ?>
                                                    </div>

                                                    <div class="user-id">
                                                        Account #<?= (int)$user["id"] ?>
                                                    </div>
                                                </div>

                                            </div>

                                        </td>


                                        <td>
                                            <?= escapeOutput($user["email"]) ?>
                                        </td>


                                        <td>
                                            <?= escapeOutput(
                                                $user["phone"] ?: "Not provided"
                                            ) ?>
                                        </td>


                                        <td>

                                            <span class="role-badge <?= escapeOutput(getRoleClass($user["role"])) ?>">
                                                <?= escapeOutput(ucfirst($user["role"])) ?>
                                            </span>

                                        </td>


                                        <td>
                                            <?= escapeOutput(
                                                formatRegistrationDate($user["created_at"])
                                            ) ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>

                                    <td colspan="5" class="empty-message">

                                        No accounts were found.

                                        <br>

                                        Try changing your search
                                        or filter selection.

                                    </td>

                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            <?php endif; ?>


        </section>



        <!-- FOOTER -->

        <footer class="users-footer">

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
