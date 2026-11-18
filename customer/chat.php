
<?php
session_start();

require_once __DIR__ . "/../config/database.php";

// Only logged-in customers may access this page.
if (
    !isset($_SESSION["user_id"]) ||
    ($_SESSION["role"] ?? "") !== "customer"
) {
    header("Location: ../login.php");
    exit;
}

$customer_id = (int) $_SESSION["user_id"];

// Create a CSRF token for secure message submission.
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION["csrf_token"];

$conversation_id = filter_input(
    INPUT_GET,
    "conversation_id",
    FILTER_VALIDATE_INT
);

$provider_id = filter_input(
    INPUT_GET,
    "provider_id",
    FILTER_VALIDATE_INT
);

$error = "";
$provider_name = "";
$conversation_id = $conversation_id ?: 0;

// Open an existing conversation.
if ($conversation_id > 0) {
    $stmt = $conn->prepare(
        "SELECT c.id, u.full_name
         FROM conversations c
         JOIN users u ON u.id = c.provider_id
         WHERE c.id = ?
           AND c.customer_id = ?
           AND u.role = 'provider'"
    );

    $stmt->bind_param("ii", $conversation_id, $customer_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $conversation = $result->fetch_assoc();
    $stmt->close();

    if ($conversation) {
        $provider_name = $conversation["full_name"];
    } else {
        $conversation_id = 0;
        $error = "This conversation could not be found.";
    }
}

// Start a conversation from a provider's profile.
elseif ($provider_id > 0) {
    $stmt = $conn->prepare(
        "SELECT id, full_name
         FROM users
         WHERE id = ? AND role = 'provider'"
    );

    $stmt->bind_param("i", $provider_id);
    $stmt->execute();

    $result = $stmt->get_result();
    $provider = $result->fetch_assoc();
    $stmt->close();

    if ($provider) {
        $provider_name = $provider["full_name"];

        // Reuse an existing customer-provider conversation if available.
        $stmt = $conn->prepare(
            "INSERT INTO conversations (customer_id, provider_id)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)"
        );

        $stmt->bind_param("ii", $customer_id, $provider_id);

        if ($stmt->execute()) {
            $conversation_id = (int) $conn->insert_id;
        } else {
            $error = "Unable to open this conversation. Please try again.";
        }

        $stmt->close();
    } else {
        $error = "The selected service provider could not be found.";
    }
} else {
    $error = "Please select a conversation from your messages.";
}

$conn->close();

function escape_html($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

// Generate provider initials.
$provider_parts = preg_split('/\s+/', trim($provider_name));
$provider_initials = "";

foreach (array_slice($provider_parts, 0, 2) as $part) {
    $provider_initials .= strtoupper(substr($part, 0, 1));
}

if ($provider_initials === "") {
    $provider_initials = "P";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Chat | HomeEase</title>

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

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: var(--frost-white);
            color: var(--midnight-ink);
        }

        .chat-page {
            min-height: 100vh;
            padding: 35px 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            background:
                radial-gradient(circle at top left, #BFE8F5 0%, transparent 35%),
                var(--frost-white);
        }

        .chat-wrapper {
            width: 100%;
            max-width: 1000px;
        }

        /* Page heading */

        .page-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 20px;
        }

        .page-heading h1 {
            margin: 0 0 6px;
            font-size: 25px;
            color: var(--midnight-ink);
            font-weight: 800;
        }

        .page-heading p {
            margin: 0;
            color: var(--slate-blue);
            font-size: 13px;
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            color: var(--midnight-ink);
            text-decoration: none;
            font-size: 13px;
            font-weight: 700;
            padding: 11px 15px;
            border: 1px solid var(--border-color);
            border-radius: 9px;
            background: #fff;
            transition: .2s;
        }

        .back-link:hover {
            background: var(--soft-blue);
            border-color: var(--ice-blue);
        }

        /* Main chat card */

        .chat-card {
            background: #fff;
            border-radius: 17px;
            overflow: hidden;
            border: 1px solid var(--border-color);
            box-shadow: 0 12px 35px rgba(20, 37, 54, .09);
        }

        /* Conversation header */

        .chat-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
            padding: 20px 25px;
            background: var(--midnight-ink);
            border-bottom: 3px solid var(--ice-blue);
        }

        .provider-info {
            display: flex;
            align-items: center;
            gap: 13px;
            min-width: 0;
        }

        .provider-avatar {
            width: 49px;
            height: 49px;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 15px;
            background: var(--ice-blue);
            color: var(--midnight-ink);
            font-size: 18px;
            font-weight: 800;
        }

        .provider-details {
            min-width: 0;
        }

        .provider-details h2 {
            margin: 0 0 5px;
            font-size: 16px;
            color: #fff;
            overflow-wrap: anywhere;
        }

        .provider-details p {
            margin: 0;
            font-size: 12px;
            color: #BFE8F5;
        }

        .provider-label {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--ice-blue);
            font-size: 12px;
            font-weight: 700;
            white-space: nowrap;
        }

        .provider-label span {
            width: 9px;
            height: 9px;
            background: #63D6A0;
            border-radius: 50%;
            box-shadow: 0 0 0 4px rgba(99, 214, 160, .13);
        }

        /* Message area */

        .messages {
            height: 500px;
            overflow-y: auto;
            padding: 28px 30px;
            background-color: var(--frost-white);
            background-image:
                radial-gradient(#BFE8F5 0.8px, transparent 0.8px);
            background-size: 19px 19px;
            scroll-behavior: smooth;
        }

        .message-row {
            display: flex;
            margin-bottom: 18px;
        }

        .message-row.mine {
            justify-content: flex-end;
        }

        .message-row.theirs {
            justify-content: flex-start;
        }

        .bubble {
            max-width: 72%;
            padding: 13px 16px 9px;
            border-radius: 16px;
            overflow-wrap: anywhere;
            box-shadow: 0 3px 9px rgba(20, 37, 54, .07);
        }

        /* Customer messages */

        .mine .bubble {
            background: var(--vermillion);
            color: #fff;
            border-bottom-right-radius: 4px;
        }

        /* Provider messages */

        .theirs .bubble {
            background: #fff;
            color: var(--midnight-ink);
            border: 1px solid var(--border-color);
            border-left: 4px solid var(--ice-blue);
            border-bottom-left-radius: 4px;
        }

        .message-text {
            white-space: pre-wrap;
            line-height: 1.6;
            font-size: 14px;
        }

        .message-meta {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 8px;
            margin-top: 7px;
            font-size: 10px;
        }

        .mine .message-meta {
            color: rgba(255, 255, 255, .85);
        }

        .theirs .message-meta {
            color: var(--slate-blue);
        }

        .message-status {
            font-size: 10px;
            white-space: nowrap;
        }

        .message-status.read {
            color: #D7F5FF;
            font-weight: bold;
        }

        .message-status.delivered {
            color: #FFE0D9;
        }

        /* Empty conversation */

        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 350px;
            text-align: center;
            color: var(--slate-blue);
            padding: 20px;
        }

        .empty-state-icon {
            width: 58px;
            height: 58px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 17px;
            background: var(--ice-blue);
            color: var(--midnight-ink);
            font-size: 24px;
            margin-bottom: 15px;
        }

        .empty-state strong {
            color: var(--midnight-ink);
            font-size: 15px;
            margin-bottom: 7px;
        }

        .empty-state span {
            font-size: 13px;
        }

        /* Message feedback */

        .feedback {
            padding: 0 22px;
            color: var(--vermillion);
            font-size: 12px;
        }

        /* Message composer */

        .composer-area {
            padding: 18px 22px 20px;
            background: #fff;
            border-top: 1px solid var(--border-color);
        }

        .chat-form {
            display: flex;
            align-items: flex-end;
            gap: 12px;
        }

        .chat-form textarea {
            flex: 1;
            min-width: 0;
            min-height: 48px;
            max-height: 120px;
            resize: vertical;
            padding: 14px 16px;
            border: 1px solid #D7E4EB;
            border-radius: 11px;
            outline: none;
            background: var(--frost-white);
            color: var(--midnight-ink);
            font-family: inherit;
            font-size: 14px;
            line-height: 1.5;
            transition: border-color .2s, box-shadow .2s;
        }

        .chat-form textarea:focus {
            border-color: var(--ice-blue);
            box-shadow: 0 0 0 3px rgba(191, 232, 245, .65);
            background: #fff;
        }

        .chat-form textarea::placeholder {
            color: #8499A7;
        }

        .chat-form button {
            min-width: 105px;
            height: 48px;
            border: none;
            border-radius: 10px;
            padding: 0 19px;
            background: var(--vermillion);
            color: #fff;
            font-size: 13px;
            font-weight: 800;
            cursor: pointer;
            transition: background .2s, transform .2s, box-shadow .2s;
            box-shadow: 0 4px 12px rgba(240, 68, 56, .2);
        }

        .chat-form button:hover:not(:disabled) {
            background: #D9342B;
            transform: translateY(-2px);
            box-shadow: 0 6px 15px rgba(240, 68, 56, .25);
        }

        .chat-form button:disabled {
            opacity: .6;
            cursor: not-allowed;
            transform: none;
        }

        .composer-hint {
            margin: 9px 0 0 3px;
            color: var(--slate-blue);
            font-size: 11px;
        }

        /* Error state */

        .notice {
            margin: 20px;
            padding: 18px;
            background: #FFF0EE;
            color: #B52F27;
            border: 1px solid #FFD2CC;
            border-radius: 10px;
            font-size: 14px;
        }

        .notice a {
            color: var(--midnight-ink);
            font-weight: 700;
        }

        /* Scrollbar */

        .messages::-webkit-scrollbar {
            width: 7px;
        }

        .messages::-webkit-scrollbar-track {
            background: transparent;
        }

        .messages::-webkit-scrollbar-thumb {
            background: #B7DCE8;
            border-radius: 10px;
        }

        .messages::-webkit-scrollbar-thumb:hover {
            background: var(--slate-blue);
        }

        /* Mobile layout */

        @media (max-width: 600px) {

            .chat-page {
                padding: 14px 9px;
                align-items: flex-start;
            }

            .page-heading {
                align-items: flex-start;
                flex-direction: column;
                margin-bottom: 13px;
            }

            .page-heading h1 {
                font-size: 21px;
            }

            .chat-card {
                border-radius: 12px;
            }

            .chat-header {
                padding: 15px;
            }

            .provider-avatar {
                width: 42px;
                height: 42px;
                font-size: 16px;
                border-radius: 12px;
            }

            .provider-details h2 {
                font-size: 14px;
            }

            .provider-label {
                font-size: 10px;
            }

            .messages {
                height: 60vh;
                min-height: 350px;
                padding: 20px 13px;
            }

            .bubble {
                max-width: 88%;
                padding: 11px 13px 8px;
            }

            .message-text {
                font-size: 13px;
            }

            .composer-area {
                padding: 13px;
            }

            .chat-form {
                gap: 8px;
            }

            .chat-form textarea {
                padding: 12px;
                font-size: 13px;
            }

            .chat-form button {
                min-width: 75px;
                padding: 0 12px;
                font-size: 12px;
            }
        }

        @media (max-width: 380px) {
            .provider-label {
                display: none;
            }
        }
    </style>
</head>

<body>

<div class="chat-page">

    <div class="chat-wrapper">

        <div class="page-heading">

            <div>
                <h1>Messages</h1>
                <p>Stay connected with your service provider.</p>
            </div>

            <a href="messages.php" class="back-link">
                ← Back to My Messages
            </a>

        </div>

        <div class="chat-card">

            <header class="chat-header">

                <div class="provider-info">

                    <div class="provider-avatar">
                        <?= escape_html($provider_initials) ?>
                    </div>

                    <div class="provider-details">

                        <h2>
                            <?= escape_html($provider_name ?: "Service Provider") ?>
                        </h2>

                        <p>HomeEase Service Provider</p>

                    </div>

                </div>

                <div class="provider-label">
                    <span></span>
                    <div>HomeEase Chat</div>
                </div>

            </header>

            <?php if ($error !== ""): ?>

                <div class="notice">
                    <?= escape_html($error) ?>

                    <p>
                        <a href="messages.php">Return to My Messages</a>
                    </p>
                </div>

            <?php else: ?>

                <div
                    class="messages"
                    id="messagesContainer"
                    aria-live="polite"
                    aria-label="Conversation messages"
                >

                    <div class="empty-state">
                        <div class="empty-state-icon">✉</div>
                        <strong>Loading conversation...</strong>
                        <span>Your messages will appear here.</span>
                    </div>

                </div>

                <div class="feedback" id="feedback" role="status"></div>

                <div class="composer-area">

                    <form class="chat-form" id="messageForm">

                        <textarea
                            id="messageInput"
                            maxlength="2000"
                            placeholder="Write your message..."
                            aria-label="Your message"
                            required
                        ></textarea>

                        <button type="submit" id="sendButton">
                            Send →
                        </button>

                    </form>

                    <p class="composer-hint">
                        Press Enter to send. Use Shift + Enter for a new line.
                    </p>

                </div>

            <?php endif; ?>

        </div>

    </div>

</div>

<?php if ($error === "" && $conversation_id > 0): ?>

<script>
const conversationId = <?= (int) $conversation_id ?>;
const currentUserId = <?= (int) $customer_id ?>;
const csrfToken = <?= json_encode($csrf_token) ?>;
const apiUrl = "../messages_api.php";

const messagesContainer = document.getElementById("messagesContainer");
const messageForm = document.getElementById("messageForm");
const messageInput = document.getElementById("messageInput");
const sendButton = document.getElementById("sendButton");
const feedback = document.getElementById("feedback");

let loadingMessages = false;
let sendingMessage = false;
let lastMessageSignature = "";

function formatTime(dateString) {
    const date = new Date(dateString.replace(" ", "T"));

    if (Number.isNaN(date.getTime())) {
        return dateString;
    }

    return date.toLocaleString([], {
        month: "short",
        day: "numeric",
        hour: "2-digit",
        minute: "2-digit"
    });
}

function createMessageElement(message) {

    const isMine = Number(message.sender_id) === currentUserId;

    const row = document.createElement("div");
    row.className = "message-row " + (isMine ? "mine" : "theirs");

    const bubble = document.createElement("div");
    bubble.className = "bubble";

    const text = document.createElement("div");
    text.className = "message-text";
    text.textContent = message.message_text;

    const meta = document.createElement("div");
    meta.className = "message-meta";

    const time = document.createElement("span");
    time.textContent = formatTime(message.created_at);

    meta.appendChild(time);

    // Show status only on messages sent by the logged-in customer.
    if (isMine) {

        const status = document.createElement("span");
        status.className = "message-status";

        if (message.is_read) {
            status.textContent = "✓✓ Read";
            status.classList.add("read");

        } else if (message.delivered_at) {
            status.textContent = "✓✓ Delivered";
            status.classList.add("delivered");

        } else {
            status.textContent = "✓ Sent";
        }

        status.title = message.is_read
            ? "The provider has read this message"
            : message.delivered_at
                ? "The message is available in HomeEase"
                : "The message has been sent";

        meta.appendChild(status);
    }

    bubble.appendChild(text);
    bubble.appendChild(meta);
    row.appendChild(bubble);

    return row;
}

async function loadMessages() {

    if (loadingMessages || document.hidden) {
        return;
    }

    loadingMessages = true;

    try {

        const response = await fetch(
            apiUrl + "?conversation_id=" + conversationId,
            { cache: "no-store" }
        );

        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.error || "Unable to load messages.");
        }

        const messages = data.messages || [];

        const signature = messages.map(message =>
            [
                message.id,
                message.is_read ? 1 : 0,
                message.delivered_at || ""
            ].join(":")
        ).join("|");

        if (signature !== lastMessageSignature) {

            const wasNearBottom =
                messagesContainer.scrollHeight -
                messagesContainer.scrollTop -
                messagesContainer.clientHeight < 100;

            messagesContainer.replaceChildren();

            if (messages.length === 0) {

                const empty = document.createElement("div");
                empty.className = "empty-state";

                const icon = document.createElement("div");
                icon.className = "empty-state-icon";
                icon.textContent = "✉";

                const heading = document.createElement("strong");
                heading.textContent = "No messages yet";

                const description = document.createElement("span");
                description.textContent =
                    "Say hello to your service provider and start the conversation!";

                empty.appendChild(icon);
                empty.appendChild(heading);
                empty.appendChild(description);

                messagesContainer.appendChild(empty);

            } else {

                messages.forEach(message => {
                    messagesContainer.appendChild(
                        createMessageElement(message)
                    );
                });

            }

            if (wasNearBottom || lastMessageSignature === "") {
                messagesContainer.scrollTop =
                    messagesContainer.scrollHeight;
            }

            lastMessageSignature = signature;
        }

        feedback.textContent = "";

    } catch (error) {

        feedback.textContent = error.message;

    } finally {

        loadingMessages = false;

    }
}

messageForm.addEventListener("submit", async function(event) {

    event.preventDefault();

    if (sendingMessage) {
        return;
    }

    const messageText = messageInput.value.trim();

    if (!messageText) {
        return;
    }

    sendingMessage = true;
    sendButton.disabled = true;
    feedback.textContent = "";

    try {

        const response = await fetch(apiUrl, {
            method: "POST",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                conversation_id: conversationId,
                message_text: messageText,
                csrf_token: csrfToken
            })
        });

        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(data.error || "Message could not be sent.");
        }

        messageInput.value = "";

        await loadMessages();

    } catch (error) {

        feedback.textContent = error.message;

    } finally {

        sendingMessage = false;
        sendButton.disabled = false;
        messageInput.focus();

    }
});

// Allow Enter to send; Shift + Enter creates a new line.
messageInput.addEventListener("keydown", function(event) {

    if (event.key === "Enter" && !event.shiftKey) {
        event.preventDefault();
        messageForm.requestSubmit();
    }

});

// Load messages and refresh every three seconds.
loadMessages();

setInterval(loadMessages, 3000);

</script>

<?php endif; ?>

</body>
</html>
