
<?php

session_start();

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "provider"
) {
    header("Location: ../login.php");
    exit;
}

require_once __DIR__ . "/../config/database.php";

$provider_id = (int) $_SESSION["user_id"];

// Only accept POST requests.
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: bookings.php");
    exit;
}

// Verify CSRF token.
$csrf_token = $_POST["csrf_token"] ?? "";
$session_token = $_SESSION["csrf_token"] ?? "";

if (
    !is_string($csrf_token) ||
    $session_token === "" ||
    !hash_equals($session_token, $csrf_token)
) {
    $conn->close();
    http_response_code(403);
    exit("Security check failed. Please refresh the bookings page and try again.");
}

// Validate booking ID.
$booking_id = filter_input(
    INPUT_POST,
    "booking_id",
    FILTER_VALIDATE_INT
);

if (!$booking_id || $booking_id < 1) {
    $conn->close();
    header("Location: bookings.php");
    exit;
}

// Confirm that this booking belongs to the logged-in provider.
$stmt = $conn->prepare(
    "SELECT customer_id
     FROM bookings
     WHERE id = ? AND provider_id = ?
     LIMIT 1"
);

$stmt->bind_param("ii", $booking_id, $provider_id);
$stmt->execute();

$result = $stmt->get_result();
$booking = $result->fetch_assoc();

$stmt->close();

if (!$booking) {
    $conn->close();
    http_response_code(403);
    exit("You are not authorized to access this booking.");
}

$customer_id = (int) $booking["customer_id"];

// Check whether a conversation already exists.
$stmt = $conn->prepare(
    "SELECT id
     FROM conversations
     WHERE customer_id = ?
       AND provider_id = ?
     LIMIT 1"
);

$stmt->bind_param("ii", $customer_id, $provider_id);
$stmt->execute();

$result = $stmt->get_result();
$conversation = $result->fetch_assoc();

$stmt->close();

if ($conversation) {

    // Reuse the existing conversation.
    $conversation_id = (int) $conversation["id"];

} else {

    // Create a new conversation.
    $stmt = $conn->prepare(
        "INSERT INTO conversations
            (customer_id, provider_id, updated_at)
         VALUES (?, ?, CURRENT_TIMESTAMP)"
    );

    if (!$stmt) {
        $conn->close();
        http_response_code(500);
        exit("Could not prepare the conversation.");
    }

    $stmt->bind_param("ii", $customer_id, $provider_id);

    if (!$stmt->execute()) {
        $stmt->close();
        $conn->close();
        http_response_code(500);
        exit("Could not create the conversation. Please try again.");
    }

    $conversation_id = (int) $conn->insert_id;

    $stmt->close();
}

$conn->close();

// Open the existing provider chat page.
header(
    "Location: chat.php?conversation_id=" . $conversation_id
);
exit;

?>
