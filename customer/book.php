
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

require "../config/database.php";

$message = "";
$success = false;

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: search.php");
    exit;
}

$customer_id = (int) $_SESSION["user_id"];
$provider_id = filter_input(INPUT_POST, "provider_id", FILTER_VALIDATE_INT);
$service_id = filter_input(INPUT_POST, "service_id", FILTER_VALIDATE_INT);

$booking_date = trim($_POST["booking_date"] ?? "");
$booking_time = trim($_POST["booking_time"] ?? "");

if (
    !$provider_id ||
    !$service_id ||
    $booking_date === "" ||
    $booking_time === ""
) {
    $message = "Please complete all booking fields.";

} else {

    $date = DateTime::createFromFormat("!Y-m-d", $booking_date);
    $valid_date = $date && $date->format("Y-m-d") === $booking_date;

    $time = DateTime::createFromFormat("!H:i", $booking_time);
    $valid_time = $time && $time->format("H:i") === $booking_time;

    if (!$valid_date || $booking_date < date("Y-m-d")) {

        $message = "Please select today or a future date.";

    } elseif (!$valid_time) {

        $message = "Please enter a valid booking time.";

    } elseif (
        $booking_date === date("Y-m-d") &&
        $booking_time <= date("H:i")
    ) {

        $message = "Please select a future time for today's booking.";

    } else {

        // Confirm the selected service belongs to the selected provider.
        $check = $conn->prepare(
            "SELECT services.id
             FROM services
             JOIN users ON services.provider_id = users.id
             WHERE services.id = ?
               AND services.provider_id = ?
               AND users.role = 'provider'"
        );

        $check->bind_param("ii", $service_id, $provider_id);
        $check->execute();

        $service_result = $check->get_result();

        if ($service_result->num_rows !== 1) {

            $message = "The selected service does not belong to that provider.";

        } else {

            // Find the weekday for the requested booking date.
            $day_of_week = date("l", strtotime($booking_date));

            // Check provider availability.
            $availability_check = $conn->prepare(
                "SELECT id
                 FROM availability
                 WHERE provider_id = ?
                   AND day_of_week = ?
                   AND start_time <= ?
                   AND end_time > ?
                 LIMIT 1"
            );

            $availability_check->bind_param(
                "isss",
                $provider_id,
                $day_of_week,
                $booking_time,
                $booking_time
            );

            $availability_check->execute();

            $availability_result = $availability_check->get_result();

            if ($availability_result->num_rows === 0) {

                $message = "The provider is not available on the selected day or at that time. Please choose a time within their listed availability.";

            } else {

                // Save the booking after all checks pass.
                $stmt = $conn->prepare(
                    "INSERT INTO bookings
                     (customer_id, provider_id, service_id, booking_date, booking_time)
                     VALUES (?, ?, ?, ?, ?)"
                );

                if ($stmt) {

                    $stmt->bind_param(
                        "iiiss",
                        $customer_id,
                        $provider_id,
                        $service_id,
                        $booking_date,
                        $booking_time
                    );

                    if ($stmt->execute()) {
                        $message = "Your booking request has been submitted successfully!";
                        $success = true;
                    } else {
                        $message = "Booking failed. Please try again.";
                    }

                    $stmt->close();

                } else {
                    $message = "Something went wrong while preparing your booking.";
                }
            }

            $availability_check->close();
        }

        $check->close();
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title><?= $success ? "Booking Request Submitted" : "Booking Update" ?> | HomeEase</title>

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
        }

        * {
            box-sizing: border-box;
        }

        body.booking-result-page {
            margin: 0;
            min-height: 100vh;
            background: linear-gradient(135deg, var(--soft-blue), var(--frost-white) 65%);
            color: var(--midnight-ink);
            font-family: Arial, sans-serif;
            display: flex;
            flex-direction: column;
        }

        .booking-navbar {
            width: min(1160px, 92%);
            margin: auto;
            min-height: 76px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .main-header {
            background: var(--midnight-ink);
            box-shadow: 0 4px 18px rgba(20, 37, 54, 0.12);
        }

        .booking-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            color: #ffffff;
            font-size: 22px;
            font-weight: 800;
            text-decoration: none;
            letter-spacing: -0.7px;
        }

        .booking-brand-mark {
            width: 38px;
            height: 38px;
            display: grid;
            place-items: center;
            background: var(--ice-blue);
            color: var(--midnight-ink);
            border-radius: 11px;
            font-size: 20px;
            font-weight: 900;
        }

        .booking-brand-accent {
            color: var(--ice-blue);
        }

        .booking-nav {
            display: flex;
            align-items: center;
            gap: 27px;
        }

        .booking-nav a {
            color: #E8F3F8;
            text-decoration: none;
            font-size: 14px;
            font-weight: 600;
            transition: color 0.2s ease;
        }

        .booking-nav a:hover {
            color: var(--ice-blue);
        }

        .booking-result-main {
            flex: 1;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 55px 20px;
        }

        .booking-result-card {
            width: 100%;
            max-width: 590px;
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 25px;
            padding: 45px 42px;
            text-align: center;
            box-shadow: 0 18px 55px rgba(20, 37, 54, 0.09);
        }

        .booking-result-icon {
            width: 82px;
            height: 82px;
            margin: 0 auto 23px;
            display: grid;
            place-items: center;
            border-radius: 25px;
            font-size: 38px;
            font-weight: 800;
        }

        .booking-result-icon.success {
            background: var(--ice-blue);
            color: var(--midnight-ink);
        }

        .booking-result-icon.error {
            background: #FDE8E6;
            color: var(--vermillion);
        }

        .booking-result-eyebrow {
            margin: 0 0 12px;
            color: var(--vermillion);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 2px;
        }

        .booking-result-card h1 {
            margin: 0 0 15px;
            color: var(--midnight-ink);
            font-size: clamp(25px, 5vw, 34px);
            line-height: 1.2;
            letter-spacing: -1px;
        }

        .booking-result-message {
            margin: 0;
            color: var(--slate-blue);
            font-size: 16px;
            line-height: 1.7;
        }

        .booking-result-note {
            margin: 25px 0;
            padding: 18px;
            background: var(--soft-blue);
            border: 1px solid var(--ice-blue);
            border-radius: 14px;
            color: var(--midnight-ink);
            font-size: 14px;
            line-height: 1.7;
            text-align: left;
        }

        .booking-status {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 17px;
            padding: 14px;
            background: #ffffff;
            border: 1px solid var(--border-color);
            border-radius: 12px;
        }

        .booking-status-dot {
            width: 10px;
            height: 10px;
            flex-shrink: 0;
            border-radius: 50%;
            background: var(--vermillion);
        }

        .booking-status-text {
            color: var(--midnight-ink);
            font-size: 13px;
            font-weight: 700;
        }

        .booking-result-primary,
        .booking-result-secondary {
            min-height: 49px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            padding: 13px 20px;
            border-radius: 12px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 700;
            transition: transform 0.2s ease, background 0.2s ease;
        }

        .booking-result-primary {
            margin-top: 25px;
            background: var(--vermillion);
            color: #ffffff;
        }

        .booking-result-primary:hover {
            background: #D9342B;
            transform: translateY(-2px);
        }

        .booking-result-secondary {
            margin-top: 12px;
            background: var(--soft-blue);
            color: var(--midnight-ink);
        }

        .booking-result-secondary:hover {
            background: var(--ice-blue);
        }

        .site-footer {
            padding: 22px 15px;
            background: var(--midnight-ink);
            color: #D8E7EF;
            text-align: center;
            font-size: 13px;
        }

        .site-footer p {
            margin: 0;
        }

        @media (max-width: 650px) {
            .booking-navbar {
                padding: 15px 0;
                flex-direction: column;
                align-items: flex-start;
            }

            .booking-nav {
                width: 100%;
                flex-wrap: wrap;
                gap: 16px;
            }

            .booking-result-main {
                padding: 35px 15px;
            }

            .booking-result-card {
                padding: 32px 22px;
                border-radius: 20px;
            }
        }
    </style>
</head>

<body class="booking-result-page">

    <header class="main-header">
        <div class="booking-navbar">

            <a href="dashboard.php" class="booking-brand">
                <span class="booking-brand-mark">H</span>
                <span>Home<span class="booking-brand-accent">Ease</span></span>
            </a>

            <nav class="booking-nav">
                <a href="dashboard.php">Dashboard</a>
                <a href="search.php">Find a Service</a>
                <a href="bookings.php">My Bookings</a>
            </nav>

        </div>
    </header>

    <main class="booking-result-main">

        <section class="booking-result-card">

            <div class="booking-result-icon <?= $success ? 'success' : 'error' ?>">
                <?= $success ? '&#10003;' : '!' ?>
            </div>

            <p class="booking-result-eyebrow">
                <?= $success ? 'BOOKING REQUEST RECEIVED' : 'BOOKING UPDATE' ?>
            </p>

            <h1>
                <?= $success ? 'Your Request Is on Its Way!' : 'Booking Not Completed' ?>
            </h1>

            <p class="booking-result-message">
                <?= htmlspecialchars($message) ?>
            </p>

            <?php if ($success): ?>

                <div class="booking-result-note">
                    Thank you for choosing HomeEase! Your booking request has
                    been recorded successfully. The service provider will
                    review your request and respond shortly.

                    <div class="booking-status">
                        <span class="booking-status-dot"></span>
                        <span class="booking-status-text">
                            Current status: Pending confirmation
                        </span>
                    </div>
                </div>

                <a href="bookings.php" class="booking-result-primary">
                    View My Bookings →
                </a>

            <?php else: ?>

                <a href="search.php" class="booking-result-primary">
                    Try Again or Find Another Service →
                </a>

            <?php endif; ?>

            <a href="dashboard.php" class="booking-result-secondary">
                Back to Customer Dashboard
            </a>

        </section>

    </main>

    <footer class="site-footer">
        <p>&copy; <?= date("Y") ?> HomeEase. All rights reserved.</p>
    </footer>

</body>
</html>

<?php
$conn->close();
?>
