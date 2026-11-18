<?php

session_start();

require "../config/database.php";

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "provider") {
    header("Location: ../login.php");
    exit;
}

if (isset($_POST["booking_id"]) && isset($_POST["status"])) {

    $booking_id = $_POST["booking_id"];
    $status = $_POST["status"];
    $provider_id = $_SESSION["user_id"];

    if ($status !== "accepted" && $status !== "rejected") {
        die("Invalid booking status.");
    }

    $stmt = $conn->prepare(
        "UPDATE bookings
         SET status = ?
         WHERE id = ? AND provider_id = ?"
    );

    $stmt->bind_param(
        "sii",
        $status,
        $booking_id,
        $provider_id
    );

    $stmt->execute();

    $stmt->close();
}

header("Location: bookings.php");
exit;

?>