
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

if (
    !isset($_GET["id"]) ||
    filter_var($_GET["id"], FILTER_VALIDATE_INT) === false ||
    (int) $_GET["id"] < 1
) {
    header("Location: search.php");
    exit;
}

$provider_id = (int) $_GET["id"];

// Retrieve provider profile and contact details.
$stmt = $conn->prepare(
    "SELECT
        users.id,
        users.full_name,
        users.email,
        users.phone,
        provider_profiles.location,
        provider_profiles.description,
        provider_profiles.proof_of_work
     FROM users
     JOIN provider_profiles
        ON users.id = provider_profiles.user_id
     WHERE users.id = ?
       AND users.role = 'provider'"
);

$stmt->bind_param("i", $provider_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows !== 1) {
    $stmt->close();
    http_response_code(404);
    $provider_not_found = true;
} else {
    $provider = $result->fetch_assoc();
    $provider_not_found = false;
    $stmt->close();
}

if ($provider_not_found):
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Provider Not Found | HomeEase</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        body {
            background: #F5F8FA !important;
            color: #142536;
            font-family: Arial, sans-serif;
        }

        .not-found {
            max-width: 650px;
            margin: 100px auto;
            padding: 50px 25px;
            text-align: center;
            background: white;
            border-radius: 22px;
            box-shadow: 0 12px 35px rgba(20,37,54,.08);
        }

        .not-found-icon {
            width: 75px;
            height: 75px;
            display: grid;
            place-items: center;
            margin: 0 auto 20px;
            border-radius: 22px;
            background: #BFE8F5;
            font-size: 32px;
        }

        .not-found h1 {
            color: #142536;
            margin-bottom: 12px;
        }

        .not-found p {
            color: #426174;
            line-height: 1.7;
        }

        .not-found a {
            display: inline-block;
            margin-top: 20px;
            padding: 13px 23px;
            background: #F04438;
            color: white;
            text-decoration: none;
            border-radius: 10px;
            font-weight: 700;
        }
    </style>
</head>
<body class="customer-dashboard-page">

    <main class="not-found">
        <div class="not-found-icon">⌂</div>
        <h1>Provider not found</h1>
        <p>This provider profile may no longer be available.</p>
        <a href="search.php">← Back to Search</a>
    </main>

</body>
</html>
<?php
exit;
endif;

// Retrieve services offered by this provider.
$service_stmt = $conn->prepare(
    "SELECT id, service_name, description
     FROM services
     WHERE provider_id = ?
     ORDER BY service_name ASC"
);

$service_stmt->bind_param("i", $provider_id);
$service_stmt->execute();
$services = $service_stmt->get_result();

// Retrieve provider availability.
$availability_stmt = $conn->prepare(
    "SELECT day_of_week, start_time, end_time
     FROM availability
     WHERE provider_id = ?
     ORDER BY id ASC"
);

$availability_stmt->bind_param("i", $provider_id);
$availability_stmt->execute();
$availability = $availability_stmt->get_result();

function display_time($time) {
    return date("g:i A", strtotime($time));
}

// Prepare safe contact links.
$phone = trim($provider["phone"] ?? "");
$email = trim($provider["email"] ?? "");

$phone_link = preg_replace('/[^0-9+]/', '', $phone);
$email_valid = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;

// Prepare provider initials.
$name_parts = preg_split('/\s+/', trim($provider["full_name"]));
$initials = "";

foreach (array_slice($name_parts, 0, 2) as $part) {
    $initials .= strtoupper(substr($part, 0, 1));
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?= htmlspecialchars($provider["full_name"]) ?> | HomeEase
    </title>

    <link rel="stylesheet" href="../css/style.css">

    <style>
        :root {
            --midnight-ink: #142536;
            --ice-blue: #BFE8F5;
            --vermillion: #F04438;
            --frost-white: #F5F8FA;
            --slate-blue: #426174;
            --soft-blue: #E7F4F9;
            --border-color: #DFEAF0;
            --white: #FFFFFF;
        }

        body.customer-dashboard-page {
            background: var(--frost-white) !important;
            color: var(--midnight-ink);
        }

        /* Navigation */

        .main-header {
            background: var(--midnight-ink) !important;
            border-bottom: 1px solid rgba(255,255,255,.08);
        }

        .main-header .navbar {
            max-width: 1250px;
            margin: auto;
            padding: 15px 25px;
        }

        .main-header .brand {
            color: white !important;
            font-weight: 800;
        }

        .main-header .brand-mark {
            background: var(--ice-blue) !important;
            color: var(--midnight-ink) !important;
            border-radius: 10px;
        }

        .main-header .brand-accent {
            color: var(--ice-blue) !important;
        }

        .main-header .main-nav a {
            color: #DCEAF0 !important;
            transition: .2s ease;
        }

        .main-header .main-nav a:hover,
        .main-header .main-nav a[aria-current="page"] {
            color: var(--ice-blue) !important;
        }

        /* Main layout */

        .provider-page-main {
            max-width: 1250px;
            width: calc(100% - 40px);
            margin: 35px auto 70px;
        }

        .provider-back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--slate-blue) !important;
            text-decoration: none;
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 22px;
            padding: 9px 13px;
            border-radius: 9px;
            transition: .2s ease;
        }

        .provider-back-link:hover {
            background: var(--ice-blue);
            color: var(--midnight-ink) !important;
        }

        /* Provider hero */

        .provider-profile-hero {
            display: flex;
            align-items: center;
            gap: 25px;
            background: var(--midnight-ink);
            color: white;
            padding: 38px;
            border-radius: 22px;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 12px 30px rgba(20,37,54,.12);
        }

        .provider-profile-hero::after {
            content: "";
            position: absolute;
            width: 280px;
            height: 280px;
            border-radius: 50%;
            background: rgba(191,232,245,.08);
            right: -90px;
            top: -130px;
            pointer-events: none;
        }

        .provider-avatar {
            width: 105px;
            height: 105px;
            flex-shrink: 0;
            display: grid;
            place-items: center;
            background: var(--ice-blue);
            color: var(--midnight-ink);
            border: 5px solid rgba(255,255,255,.16);
            border-radius: 25px;
            font-size: 36px;
            font-weight: 800;
        }

        .provider-profile-intro {
            flex: 1;
            min-width: 0;
            position: relative;
            z-index: 1;
        }

        .dashboard-eyebrow {
            display: inline-block;
            color: var(--ice-blue) !important;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 1.8px;
            margin-bottom: 10px;
        }

        .provider-profile-intro h1 {
            color: white !important;
            font-size: clamp(25px, 4vw, 34px);
            margin: 0 0 10px;
            line-height: 1.2;
        }

        .provider-profile-intro > p {
            color: #D5E3EA !important;
            line-height: 1.7;
            margin: 10px 0 0;
            max-width: 620px;
        }

        .provider-location {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--ice-blue) !important;
            font-weight: 600;
        }

        .provider-profile-hero > a {
            position: relative;
            z-index: 2;
            flex-shrink: 0;
        }

        .dashboard-primary-btn {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            background: var(--vermillion) !important;
            color: white !important;
            padding: 14px 21px;
            border-radius: 11px;
            text-decoration: none;
            font-weight: 800;
            transition: .2s ease;
        }

        .dashboard-primary-btn:hover {
            background: #D9342B !important;
            transform: translateY(-2px);
        }

        /* Content grid */

        .provider-content-grid {
            display: grid;
            grid-template-columns: minmax(0, 1.7fr) minmax(280px, .85fr);
            gap: 25px;
            align-items: start;
        }

        .provider-main-column {
            display: flex;
            flex-direction: column;
            gap: 22px;
            min-width: 0;
        }

        .provider-content-card,
        .provider-contact-card,
        .provider-booking-card {
            background: var(--white) !important;
            border: 1px solid var(--border-color) !important;
            border-radius: 18px;
            padding: 27px;
            box-shadow: 0 7px 22px rgba(20,37,54,.045);
        }

        /* Section headings */

        .provider-section-heading {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 23px;
        }

        .provider-section-icon {
            width: 46px;
            height: 46px;
            flex-shrink: 0;
            display: grid;
            place-items: center;
            border-radius: 13px;
            background: var(--soft-blue) !important;
            color: var(--midnight-ink) !important;
            font-size: 21px;
            font-weight: 800;
        }

        .provider-section-heading h2 {
            color: var(--midnight-ink) !important;
            font-size: 19px;
            margin: 0 0 5px;
        }

        .provider-section-heading p {
            color: var(--slate-blue) !important;
            font-size: 13px;
            margin: 0;
        }

        .provider-description,
        .provider-subsection p {
            color: var(--slate-blue) !important;
            line-height: 1.85;
            font-size: 14px;
        }

        .provider-subsection {
            margin-top: 25px;
            padding-top: 22px;
            border-top: 1px solid var(--border-color);
        }

        .provider-subsection h3 {
            color: var(--midnight-ink) !important;
            font-size: 15px;
            margin: 0 0 10px;
        }

        /* Contact card */

        .provider-contact-card h2 {
            color: var(--midnight-ink) !important;
            font-size: 19px;
            margin: 0 0 8px;
        }

        .provider-contact-intro {
            color: var(--slate-blue) !important;
            font-size: 13px;
            line-height: 1.7;
            margin: 0 0 20px;
        }

        .provider-contact-item {
            display: flex;
            align-items: center;
            gap: 14px;
            padding: 15px 0;
            border-bottom: 1px solid var(--border-color);
            overflow-wrap: anywhere;
        }

        .provider-contact-item:last-of-type {
            border-bottom: none;
        }

        .provider-contact-icon {
            width: 44px;
            height: 44px;
            flex-shrink: 0;
            display: grid;
            place-items: center;
            background: var(--soft-blue) !important;
            color: var(--midnight-ink) !important;
            border-radius: 12px;
            font-size: 20px;
        }

        .provider-contact-details {
            flex: 1;
            min-width: 0;
        }

        .provider-contact-details strong {
            display: block;
            color: var(--midnight-ink) !important;
            margin-bottom: 5px;
            font-size: 13px;
        }

        .provider-contact-details a,
        .provider-contact-details span {
            color: var(--slate-blue) !important;
            text-decoration: none;
            font-size: 14px;
            overflow-wrap: anywhere;
        }

        .provider-contact-details a:hover {
            color: var(--vermillion) !important;
            text-decoration: underline;
        }

        .provider-contact-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 21px;
        }

        .provider-contact-button {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            padding: 12px 17px;
            border-radius: 10px;
            background: var(--vermillion) !important;
            color: white !important;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            transition: .2s ease;
        }

        .provider-contact-button:hover {
            background: #D9342B !important;
            transform: translateY(-1px);
        }

        .provider-contact-button.secondary {
            background: var(--soft-blue) !important;
            color: var(--midnight-ink) !important;
        }

        .provider-contact-button.secondary:hover {
            background: var(--ice-blue) !important;
        }

        .provider-contact-unavailable {
            color: var(--slate-blue) !important;
            background: var(--soft-blue) !important;
            border-radius: 10px;
            padding: 14px;
            line-height: 1.6;
            font-size: 13px;
        }

        /* Services */

        .provider-service-list {
            display: flex;
            flex-direction: column;
            gap: 13px;
        }

        .provider-service-item {
            display: flex;
            align-items: flex-start;
            gap: 14px;
            padding: 17px;
            background: var(--frost-white) !important;
            border: 1px solid var(--border-color);
            border-radius: 13px;
        }

        .provider-service-symbol {
            width: 42px;
            height: 42px;
            flex-shrink: 0;
            display: grid;
            place-items: center;
            background: var(--ice-blue) !important;
            color: var(--midnight-ink) !important;
            border-radius: 11px;
            font-size: 21px;
        }

        .provider-service-item h3 {
            color: var(--midnight-ink) !important;
            font-size: 15px;
            margin: 2px 0 7px;
        }

        .provider-service-item p {
            color: var(--slate-blue) !important;
            font-size: 13px;
            line-height: 1.7;
            margin: 0;
        }

        /* Availability */

        .availability-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .availability-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            padding: 15px 17px;
            background: var(--frost-white) !important;
            border: 1px solid var(--border-color);
            border-radius: 11px;
        }

        .availability-item strong {
            color: var(--midnight-ink) !important;
            font-size: 14px;
        }

        .availability-item span {
            color: var(--slate-blue) !important;
            font-size: 13px;
            text-align: right;
        }

        .provider-empty-message {
            color: var(--slate-blue) !important;
            background: var(--soft-blue) !important;
            padding: 16px;
            border-radius: 11px;
            font-size: 13px;
            line-height: 1.7;
        }

        /* Booking card */

        .provider-booking-column {
            position: sticky;
            top: 25px;
        }

        .provider-booking-card {
            border-top: 5px solid var(--vermillion) !important;
        }

        .provider-booking-card h2 {
            color: var(--midnight-ink) !important;
            font-size: 22px;
            margin: 0 0 10px;
        }

        .provider-booking-card > p:not(.provider-booking-note) {
            color: var(--slate-blue) !important;
            font-size: 13px;
            line-height: 1.7;
            margin: 0 0 23px;
        }

        .auth-form .form-group {
            margin-bottom: 18px;
        }

        .auth-form label {
            display: block;
            color: var(--midnight-ink) !important;
            font-size: 13px;
            font-weight: 700;
            margin-bottom: 8px;
        }

        .auth-form input,
        .auth-form select {
            width: 100%;
            box-sizing: border-box;
            min-height: 47px;
            padding: 11px 13px;
            border: 1px solid var(--border-color) !important;
            border-radius: 9px;
            background: var(--frost-white) !important;
            color: var(--midnight-ink) !important;
            font-size: 14px;
            outline: none;
        }

        .auth-form input:focus,
        .auth-form select:focus {
            border-color: var(--ice-blue) !important;
            box-shadow: 0 0 0 3px rgba(191,232,245,.5);
        }

        .auth-submit {
            width: 100%;
            min-height: 49px;
            border: none;
            border-radius: 10px;
            background: var(--vermillion) !important;
            color: white !important;
            font-size: 14px;
            font-weight: 800;
            cursor: pointer;
            transition: .2s ease;
        }

        .auth-submit:hover {
            background: #D9342B !important;
            transform: translateY(-2px);
        }

        .provider-booking-note {
            margin: 17px 0 0;
            padding: 12px;
            background: var(--soft-blue) !important;
            color: var(--slate-blue) !important;
            border-radius: 9px;
            font-size: 12px;
            line-height: 1.6;
        }

        /* Bottom links */

        .provider-bottom-links {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-top: 30px;
        }

        .dashboard-secondary-btn {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            padding: 12px 19px;
            background: var(--ice-blue) !important;
            color: var(--midnight-ink) !important;
            text-decoration: none;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
        }

        .dashboard-secondary-btn:hover {
            background: #A6DDED !important;
        }

        .provider-text-link {
            color: var(--vermillion) !important;
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
        }

        .provider-text-link:hover {
            text-decoration: underline;
        }

        /* Footer */

        .auth-footer {
            background: var(--midnight-ink) !important;
            color: #D5E3EA !important;
            padding: 22px;
            text-align: center;
            font-size: 13px;
        }

        /* Responsive design */

        @media (max-width: 900px) {
            .provider-content-grid {
                grid-template-columns: 1fr;
            }

            .provider-booking-column {
                position: static;
            }

            .provider-profile-hero {
                flex-wrap: wrap;
            }
        }

        @media (max-width: 600px) {
            .provider-page-main {
                width: calc(100% - 26px);
                margin-top: 20px;
            }

            .provider-profile-hero {
                padding: 25px 20px;
                gap: 17px;
                border-radius: 17px;
            }

            .provider-avatar {
                width: 75px;
                height: 75px;
                font-size: 27px;
                border-radius: 19px;
            }

            .provider-profile-intro {
                flex-basis: calc(100% - 100px);
            }

            .provider-profile-hero > a {
                width: 100%;
                box-sizing: border-box;
            }

            .provider-content-card,
            .provider-contact-card,
            .provider-booking-card {
                padding: 20px;
                border-radius: 15px;
            }

            .provider-contact-actions {
                flex-direction: column;
            }

            .provider-contact-button {
                width: 100%;
                box-sizing: border-box;
            }

            .availability-item {
                align-items: flex-start;
                flex-direction: column;
                gap: 7px;
            }

            .availability-item span {
                text-align: left;
            }

            .provider-bottom-links {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>
</head>

<body class="customer-dashboard-page">

<header class="main-header">
    <div class="navbar">

        <a href="../index.php" class="brand">
            <span class="brand-mark">H</span>
            <span>Home<span class="brand-accent">Ease</span></span>
        </a>

        <nav class="main-nav">
            <a href="dashboard.php">Dashboard</a>
            <a href="search.php" aria-current="page">Find a Service</a>
            <a href="bookings.php">My Bookings</a>
            <a href="messages.php">My Messages</a>
        </nav>

    </div>
</header>

<main class="provider-page-main">

    <a href="search.php" class="provider-back-link">
        ← Back to search results
    </a>

    <!-- Provider introduction -->

    <section class="provider-profile-hero">

        <div class="provider-avatar" aria-hidden="true">
            <?= htmlspecialchars($initials) ?>
        </div>

        <div class="provider-profile-intro">

            <span class="dashboard-eyebrow">HOME EASE SERVICE PROVIDER</span>

            <h1><?= htmlspecialchars($provider["full_name"]) ?></h1>

            <p class="provider-location">
                <span aria-hidden="true">⌖</span>
                <?= htmlspecialchars($provider["location"]) ?>
            </p>

            <p>
                Explore this provider's experience, services and availability.
                When you're ready, send a booking request.
            </p>

        </div>

        <a href="#booking-form" class="dashboard-primary-btn">
            Book a Service →
        </a>

    </section>

    <div class="provider-content-grid">

        <div class="provider-main-column">

            <!-- About the provider -->

            <section class="provider-content-card">

                <div class="provider-section-heading">

                    <span class="provider-section-icon" aria-hidden="true">
                        i
                    </span>

                    <div>
                        <h2>About the Provider</h2>
                        <p>Get to know the person behind the service.</p>
                    </div>

                </div>

                <p class="provider-description">
                    <?= nl2br(htmlspecialchars(
                        $provider["description"]
                        ?: "This provider has not added a profile description yet."
                    )) ?>
                </p>

                <div class="provider-subsection">

                    <h3>Proof of Work and Experience</h3>

                    <p>
                        <?= nl2br(htmlspecialchars(
                            $provider["proof_of_work"]
                            ?: "No proof of work or experience details have been added yet."
                        )) ?>
                    </p>

                </div>

            </section>

            <!-- Contact details -->

            <section class="provider-contact-card">

                <h2>Contact Provider</h2>

                <p class="provider-contact-intro">
                    Have a question before booking? Reach out directly
                    or start a conversation through HomeEase.
                </p>

                <?php if ($phone !== ""): ?>

                    <div class="provider-contact-item">

                        <div class="provider-contact-icon" aria-hidden="true">
                            &#9742;
                        </div>

                        <div class="provider-contact-details">

                            <strong>Phone number</strong>

                            <a href="tel:<?= htmlspecialchars($phone_link) ?>">
                                <?= htmlspecialchars($phone) ?>
                            </a>

                        </div>

                    </div>

                <?php endif; ?>

                <?php if ($email_valid): ?>

                    <div class="provider-contact-item">

                        <div class="provider-contact-icon" aria-hidden="true">
                            &#9993;
                        </div>

                        <div class="provider-contact-details">

                            <strong>Email address</strong>

                            <a href="mailto:<?= htmlspecialchars($email) ?>">
                                <?= htmlspecialchars($email) ?>
                            </a>

                        </div>

                    </div>

                <?php endif; ?>

                <?php if ($phone === "" && !$email_valid): ?>

                    <p class="provider-contact-unavailable">
                        This provider has not supplied valid contact details.
                        You can still review their services and submit a booking request.
                    </p>

                <?php else: ?>

                    <div class="provider-contact-actions">

                        <?php if ($phone !== "" && $phone_link !== ""): ?>

                            <a
                                href="tel:<?= htmlspecialchars($phone_link) ?>"
                                class="provider-contact-button"
                            >
                                &#9742; Call Provider
                            </a>

                        <?php endif; ?>

                        <?php if ($email_valid): ?>

                            <a
                                href="mailto:<?= htmlspecialchars($email) ?>"
                                class="provider-contact-button secondary"
                            >
                                &#9993; Send Email
                            </a>

                        <?php endif; ?>

                        <a
                            href="chat.php?provider_id=<?= (int) $provider_id ?>"
                            class="provider-contact-button secondary"
                        >
                            &#9998; Message Provider
                        </a>

                    </div>

                <?php endif; ?>

            </section>

            <!-- Services offered -->

            <section class="provider-content-card">

                <div class="provider-section-heading">

                    <span class="provider-section-icon" aria-hidden="true">
                        ✓
                    </span>

                    <div>
                        <h2>Services Offered</h2>
                        <p>Explore the services available from this provider.</p>
                    </div>

                </div>

                <?php if ($services->num_rows > 0): ?>

                    <div class="provider-service-list">

                        <?php while ($service = $services->fetch_assoc()): ?>

                            <article class="provider-service-item">

                                <div class="provider-service-symbol" aria-hidden="true">
                                    ⌂
                                </div>

                                <div>

                                    <h3>
                                        <?= htmlspecialchars($service["service_name"]) ?>
                                    </h3>

                                    <p>
                                        <?= nl2br(htmlspecialchars(
                                            $service["description"]
                                            ?: "No additional service details provided."
                                        )) ?>
                                    </p>

                                </div>

                            </article>

                        <?php endwhile; ?>

                    </div>

                <?php else: ?>

                    <p class="provider-empty-message">
                        This provider has not listed any services yet.
                    </p>

                <?php endif; ?>

            </section>

            <!-- Availability -->

            <section class="provider-content-card">

                <div class="provider-section-heading">

                    <span class="provider-section-icon" aria-hidden="true">
                        ◷
                    </span>

                    <div>
                        <h2>Availability</h2>
                        <p>Check the working hours listed by this provider.</p>
                    </div>

                </div>

                <?php if ($availability->num_rows > 0): ?>

                    <div class="availability-list">

                        <?php while ($slot = $availability->fetch_assoc()): ?>

                            <div class="availability-item">

                                <strong>
                                    <?= htmlspecialchars($slot["day_of_week"]) ?>
                                </strong>

                                <span>
                                    <?= htmlspecialchars(display_time($slot["start_time"])) ?>
                                    –
                                    <?= htmlspecialchars(display_time($slot["end_time"])) ?>
                                </span>

                            </div>

                        <?php endwhile; ?>

                    </div>

                <?php else: ?>

                    <p class="provider-empty-message">
                        This provider has not listed availability yet.
                        You can still submit a preferred date and time,
                        but confirm the appointment with the provider.
                    </p>

                <?php endif; ?>

            </section>

        </div>

        <!-- Booking form -->

        <aside class="provider-booking-column" id="booking-form">

            <section class="provider-booking-card">

                <span class="dashboard-eyebrow">
                    READY TO BOOK?
                </span>

                <h2>Request a Service</h2>

                <p>
                    Choose the service you need and your preferred
                    appointment date and time.
                </p>

                <?php if ($services->num_rows > 0): ?>

                    <form method="POST" action="book.php" class="auth-form">

                        <input
                            type="hidden"
                            name="provider_id"
                            value="<?= (int) $provider_id ?>"
                        >

                        <div class="form-group">

                            <label for="service_id">
                                Service needed *
                            </label>

                            <select
                                name="service_id"
                                id="service_id"
                                required
                            >
                                <option value="">Choose a service</option>

                                <?php
                                $booking_service_stmt = $conn->prepare(
                                    "SELECT id, service_name
                                     FROM services
                                     WHERE provider_id = ?
                                     ORDER BY service_name ASC"
                                );

                                $booking_service_stmt->bind_param("i", $provider_id);
                                $booking_service_stmt->execute();
                                $booking_services = $booking_service_stmt->get_result();

                                while ($booking_service = $booking_services->fetch_assoc()):
                                ?>

                                    <option value="<?= (int) $booking_service["id"] ?>">
                                        <?= htmlspecialchars($booking_service["service_name"]) ?>
                                    </option>

                                <?php endwhile; ?>

                                <?php $booking_service_stmt->close(); ?>

                            </select>

                        </div>

                        <div class="form-group">

                            <label for="booking_date">
                                Preferred date *
                            </label>

                            <input
                                type="date"
                                id="booking_date"
                                name="booking_date"
                                min="<?= date("Y-m-d") ?>"
                                required
                            >

                        </div>

                        <div class="form-group">

                            <label for="booking_time">
                                Preferred time *
                            </label>

                            <input
                                type="time"
                                id="booking_time"
                                name="booking_time"
                                required
                            >

                        </div>

                        <button type="submit" class="auth-submit">
                            Submit Booking Request →
                        </button>

                    </form>

                <?php else: ?>

                    <p class="provider-empty-message">
                        Booking is unavailable because this provider
                        has not listed any services yet.
                    </p>

                <?php endif; ?>

                <p class="provider-booking-note">
                    Your booking request will be recorded as pending
                    until the provider responds.
                </p>

            </section>

        </aside>

    </div>

    <!-- Bottom navigation -->

    <div class="provider-bottom-links">

        <a href="search.php" class="dashboard-secondary-btn">
            ← Back to Search
        </a>

        <a href="bookings.php" class="provider-text-link">
            View My Bookings →
        </a>

    </div>

</main>

<footer class="auth-footer">

    <p>
        &copy; <?= date("Y") ?> HomeEase.
        All rights reserved.
    </p>

</footer>

</body>
</html>

<?php
$service_stmt->close();
$availability_stmt->close();
$conn->close();
?>
