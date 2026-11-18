
<?php
session_start();

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-store, no-cache, must-revalidate");

function respond($status, $data) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    !in_array($_SESSION["role"], ["customer", "provider"], true)
) {
    respond(401, [
        "success" => false,
        "error" => "Please log in first."
    ]);
}

require_once __DIR__ . "/config/database.php";

$user_id = (int) $_SESSION["user_id"];
$role = $_SESSION["role"];
$method = $_SERVER["REQUEST_METHOD"];

function get_conversation($conn, $conversation_id, $user_id, $role) {
    if ($role === "customer") {
        $sql = "SELECT id, customer_id, provider_id
                FROM conversations
                WHERE id = ? AND customer_id = ?";
    } else {
        $sql = "SELECT id, customer_id, provider_id
                FROM conversations
                WHERE id = ? AND provider_id = ?";
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $conversation_id, $user_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $conversation = $result->fetch_assoc();

    $stmt->close();

    return $conversation ?: null;
}

if (!in_array($method, ["GET", "POST"], true)) {
    header("Allow: GET, POST");
    respond(405, [
        "success" => false,
        "error" => "Method not allowed."
    ]);
}

/*
|--------------------------------------------------------------------------
| GET: Retrieve messages and mark incoming messages as read.
|--------------------------------------------------------------------------
*/
if ($method === "GET") {
    $conversation_id = filter_input(
        INPUT_GET,
        "conversation_id",
        FILTER_VALIDATE_INT
    );

    if (!$conversation_id || $conversation_id < 1) {
        respond(400, [
            "success" => false,
            "error" => "Invalid conversation."
        ]);
    }

    $conversation = get_conversation(
        $conn,
        $conversation_id,
        $user_id,
        $role
    );

    if (!$conversation) {
        respond(403, [
            "success" => false,
            "error" => "You cannot access this conversation."
        ]);
    }

    // Opening the conversation marks incoming messages as read.
    $stmt = $conn->prepare(
        "UPDATE messages
         SET is_read = 1
         WHERE conversation_id = ?
           AND sender_id <> ?"
    );

    $stmt->bind_param("ii", $conversation_id, $user_id);
    $stmt->execute();
    $stmt->close();

    // Return messages in chronological order, including delivery status.
    $stmt = $conn->prepare(
        "SELECT id, sender_id, message_text, is_read,
                delivered_at, created_at
         FROM messages
         WHERE conversation_id = ?
         ORDER BY id ASC"
    );

    $stmt->bind_param("i", $conversation_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $messages = [];

    while ($row = $result->fetch_assoc()) {
        $messages[] = [
            "id" => (int) $row["id"],
            "sender_id" => (int) $row["sender_id"],
            "message_text" => $row["message_text"],
            "is_read" => (bool) $row["is_read"],
            "delivered_at" => $row["delivered_at"],
            "created_at" => $row["created_at"]
        ];
    }

    $stmt->close();
    $conn->close();

    respond(200, [
        "success" => true,
        "conversation_id" => (int) $conversation_id,
        "current_user_id" => $user_id,
        "messages" => $messages
    ]);
}

/*
|--------------------------------------------------------------------------
| POST: Validate and save a new message.
|--------------------------------------------------------------------------
*/
$input = json_decode(file_get_contents("php://input"), true);

if (!is_array($input)) {
    respond(400, [
        "success" => false,
        "error" => "Invalid request data."
    ]);
}

// CSRF protection.
$csrf_token = $input["csrf_token"] ?? "";
$session_token = $_SESSION["csrf_token"] ?? "";

if (
    !is_string($csrf_token) ||
    $session_token === "" ||
    !hash_equals($session_token, $csrf_token)
) {
    respond(403, [
        "success" => false,
        "error" => "Security check failed. Refresh the page and try again."
    ]);
}

$conversation_id = filter_var(
    $input["conversation_id"] ?? null,
    FILTER_VALIDATE_INT
);

$message_text = $input["message_text"] ?? "";

if (!$conversation_id || $conversation_id < 1) {
    respond(400, [
        "success" => false,
        "error" => "Invalid conversation."
    ]);
}

if (!is_string($message_text)) {
    respond(400, [
        "success" => false,
        "error" => "Invalid message."
    ]);
}

$message_text = trim($message_text);

if ($message_text === "") {
    respond(400, [
        "success" => false,
        "error" => "Please enter a message."
    ]);
}

if (mb_strlen($message_text, "UTF-8") > 2000) {
    respond(400, [
        "success" => false,
        "error" => "Messages cannot exceed 2,000 characters."
    ]);
}

// Confirm that the logged-in user belongs to this conversation.
$conversation = get_conversation(
    $conn,
    $conversation_id,
    $user_id,
    $role
);

if (!$conversation) {
    respond(403, [
        "success" => false,
        "error" => "You cannot send messages in this conversation."
    ]);
}

/*
 * delivered_at means the message is saved and available in HomeEase.
 * is_read becomes true when the recipient opens the conversation.
 */
$stmt = $conn->prepare(
    "INSERT INTO messages
        (conversation_id, sender_id, message_text, delivered_at)
     VALUES (?, ?, ?, CURRENT_TIMESTAMP)"
);

$stmt->bind_param(
    "iis",
    $conversation_id,
    $user_id,
    $message_text
);

if (!$stmt->execute()) {
    $stmt->close();
    $conn->close();

    respond(500, [
        "success" => false,
        "error" => "The message could not be sent."
    ]);
}

$message_id = (int) $conn->insert_id;

$stmt->close();

// Update the conversation's latest activity time.
$stmt = $conn->prepare(
    "UPDATE conversations
     SET updated_at = CURRENT_TIMESTAMP
     WHERE id = ?"
);

$stmt->bind_param("i", $conversation_id);
$stmt->execute();
$stmt->close();

$conn->close();

respond(200, [
    "success" => true,
    "message" => "Message sent.",
    "message_id" => $message_id,
    "delivered_at" => date("Y-m-d H:i:s")
]);
