
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

$conversation_id = filter_input(
    INPUT_GET,
    "conversation_id",
    FILTER_VALIDATE_INT
);

if (!$conversation_id || $conversation_id < 1) {
    header("Location: messages.php");
    exit;
}

// Confirm that this conversation belongs to the logged-in provider.
$stmt = $conn->prepare(
    "SELECT c.id, c.customer_id, u.full_name AS customer_name
     FROM conversations c
     INNER JOIN users u ON u.id = c.customer_id
     WHERE c.id = ? AND c.provider_id = ?
     LIMIT 1"
);

$stmt->bind_param("ii", $conversation_id, $provider_id);
$stmt->execute();

$result = $stmt->get_result();
$conversation = $result->fetch_assoc();

$stmt->close();

if (!$conversation) {
    $conn->close();
    http_response_code(403);
    exit("You are not authorized to access this conversation.");
}

// Create CSRF token if one does not already exist.
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$csrf_token = $_SESSION["csrf_token"];
$customer_name = $conversation["customer_name"];

// Generate customer initials for the avatar.
$name_parts = preg_split('/\s+/', trim($customer_name));
$initials = "";

if (!empty($name_parts[0])) {
    $initials .= mb_substr($name_parts[0], 0, 1);
}

if (count($name_parts) > 1) {
    $initials .= mb_substr($name_parts[count($name_parts) - 1], 0, 1);
}

$initials = strtoupper($initials);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Chat | HomeEase</title>

    <style>
        * {
            box-sizing: border-box;
        }

        :root {
            --navy: #14243d;
            --dark-navy: #0c1b33;
            --pink: #ff3d9a;
            --deep-pink: #e91e83;
            --light-pink: #fff0f7;
            --background: #f3f5fa;
            --text: #18253d;
            --muted: #7b879d;
            --border: #e7eaf2;
            --white: #ffffff;
        }

        body {
            margin: 0;
            font-family: Arial, Helvetica, sans-serif;
            background: var(--background);
            color: var(--text);
            min-height: 100vh;
        }

        button,
        textarea {
            font-family: inherit;
        }

        /* Top navigation */

        .topbar {
            min-height: 76px;
            background: var(--white);
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 5%;
            gap: 20px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 11px;
        }

        .brand-icon {
            width: 43px;
            height: 43px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--pink), var(--deep-pink));
            display: flex;
            justify-content: center;
            align-items: center;
            color: white;
            font-size: 23px;
            font-weight: bold;
        }

        .brand-name {
            font-size: 22px;
            font-weight: bold;
            color: var(--navy);
            letter-spacing: -0.7px;
        }

        .brand-name span {
            color: var(--pink);
        }

        .brand-tagline {
            color: var(--muted);
            font-size: 9px;
            letter-spacing: 1.2px;
            margin-top: 3px;
        }

        .back-button {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 11px 17px;
            border: 1px solid var(--border);
            border-radius: 9px;
            color: var(--navy);
            text-decoration: none;
            font-size: 13px;
            font-weight: bold;
            transition: 0.2s;
        }

        .back-button:hover {
            background: var(--light-pink);
            border-color: #ffc0df;
            color: var(--deep-pink);
        }

        /* Main layout */

        .page-container {
            max-width: 1250px;
            margin: 30px auto;
            padding: 0 22px;
        }

        .page-heading {
            margin-bottom: 19px;
        }

        .page-heading h1 {
            margin: 0 0 7px;
            color: var(--navy);
            font-size: 27px;
        }

        .page-heading p {
            margin: 0;
            color: var(--muted);
            font-size: 13px;
        }

        .chat-card {
            background: var(--white);
            border-radius: 17px;
            overflow: hidden;
            box-shadow: 0 8px 30px rgba(20, 36, 61, 0.07);
            border: 1px solid #e9ebf4;
        }

        /* Conversation header */

        .chat-header {
            min-height: 100px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 20px 27px;
            border-bottom: 1px solid var(--border);
            background: white;
        }

        .customer-details {
            display: flex;
            align-items: center;
            gap: 15px;
            min-width: 0;
        }

        .customer-avatar {
            width: 59px;
            height: 59px;
            flex-shrink: 0;
            border-radius: 50%;
            background: linear-gradient(135deg, #ff62b0, #ed1685);
            display: flex;
            justify-content: center;
            align-items: center;
            color: white;
            font-size: 21px;
            font-weight: bold;
            box-shadow: 0 5px 13px rgba(255, 61, 154, 0.2);
        }

        .customer-info {
            min-width: 0;
        }

        .customer-info h2 {
            margin: 0 0 6px;
            font-size: 19px;
            color: var(--navy);
            overflow-wrap: anywhere;
        }

        .customer-info p {
            margin: 0;
            color: var(--muted);
            font-size: 12px;
        }

        .conversation-label {
            display: inline-flex;
            align-items: center;
            gap: 7px;
        }

        .conversation-dot {
            width: 8px;
            height: 8px;
            background: #22b573;
            border-radius: 50%;
        }

        .header-tag {
            background: var(--light-pink);
            color: var(--deep-pink);
            padding: 9px 13px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
            white-space: nowrap;
        }

        /* Messages area */

        #messages {
            height: 510px;
            overflow-y: auto;
            padding: 28px 35px;
            background:
                radial-gradient(circle at 10% 15%, rgba(255, 61, 154, 0.045) 0, transparent 23%),
                radial-gradient(circle at 90% 80%, rgba(99, 119, 190, 0.06) 0, transparent 25%),
                #f8f9fe;
            scroll-behavior: smooth;
        }

        #messages::-webkit-scrollbar {
            width: 7px;
        }

        #messages::-webkit-scrollbar-thumb {
            background: #d8dce9;
            border-radius: 10px;
        }

        .date-divider {
            display: flex;
            align-items: center;
            gap: 15px;
            margin: 5px 0 25px;
            color: #8791a8;
            font-size: 11px;
            font-weight: bold;
        }

        .date-divider::before,
        .date-divider::after {
            content: "";
            height: 1px;
            flex: 1;
            background: #e5e8f1;
        }

        .message-row {
            display: flex;
            margin-bottom: 19px;
            width: 100%;
        }

        .message-row.customer {
            justify-content: flex-start;
        }

        .message-row.provider {
            justify-content: flex-end;
        }

        .message {
            max-width: 72%;
            width: fit-content;
            min-width: 110px;
            padding: 14px 17px 10px;
            overflow-wrap: anywhere;
            box-shadow: 0 3px 9px rgba(20, 36, 61, 0.035);
        }

        .message.customer {
            background: white;
            color: var(--text);
            border: 1px solid #edf0f6;
            border-radius: 17px 17px 17px 5px;
        }

        .message.provider {
            background: linear-gradient(135deg, #ff64b3, #ff3d9a);
            color: #ffffff;
            border-radius: 17px 17px 5px 17px;
            box-shadow: 0 5px 13px rgba(255, 61, 154, 0.15);
        }

        .sender {
            font-size: 10px;
            font-weight: bold;
            margin-bottom: 7px;
            opacity: 0.75;
        }

        .message-text {
            font-size: 14px;
            line-height: 1.6;
            white-space: pre-wrap;
        }

        .message-meta {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
            font-size: 10px;
            opacity: 0.8;
        }

        .message-status {
            font-size: 10px;
            white-space: nowrap;
        }

        .message-status.read {
            color: #173d74;
            font-weight: bold;
        }

        .message-status.delivered {
            color: inherit;
        }

        .empty-state {
            min-height: 350px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            align-items: center;
            text-align: center;
            padding: 25px;
        }

        .empty-icon {
            width: 65px;
            height: 65px;
            border-radius: 50%;
            background: var(--light-pink);
            color: var(--deep-pink);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            margin-bottom: 17px;
        }

        .empty-state h3 {
            margin: 0 0 8px;
            color: var(--navy);
            font-size: 17px;
        }

        .empty-state p {
            max-width: 300px;
            margin: 0;
            color: var(--muted);
            font-size: 13px;
            line-height: 1.6;
        }

        /* Error/status area */

        #status {
            min-height: 0;
            padding: 0 25px;
            color: #c03955;
            font-size: 12px;
        }

        #status:not(:empty) {
            padding-top: 12px;
        }

        /* Message composer */

        .composer-area {
            padding: 18px 23px;
            background: white;
            border-top: 1px solid var(--border);
        }

        .composer {
            display: flex;
            align-items: flex-end;
            gap: 12px;
            padding: 8px 9px 8px 17px;
            border: 1px solid #e5e8f2;
            border-radius: 17px;
            background: #fbfcff;
            transition: 0.2s;
        }

        .composer:focus-within {
            border-color: #ff9dcc;
            box-shadow: 0 0 0 3px rgba(255, 61, 154, 0.07);
            background: white;
        }

        #messageInput {
            flex: 1;
            min-width: 0;
            min-height: 43px;
            max-height: 120px;
            padding: 12px 2px;
            border: none;
            outline: none;
            resize: none;
            background: transparent;
            color: var(--text);
            font-size: 14px;
            line-height: 1.5;
        }

        #messageInput::placeholder {
            color: #929bb0;
        }

        #sendButton {
            width: 48px;
            height: 46px;
            flex-shrink: 0;
            border: none;
            border-radius: 12px;
            background: linear-gradient(135deg, var(--pink), var(--deep-pink));
            color: white;
            font-size: 20px;
            cursor: pointer;
            transition: 0.2s;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #sendButton:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 13px rgba(255, 61, 154, 0.25);
        }

        #sendButton:disabled {
            opacity: 0.55;
            cursor: not-allowed;
            transform: none;
        }

        .composer-hint {
            margin: 9px 4px 0;
            color: #9aa3b5;
            font-size: 10px;
        }

        /* Responsive design */

        @media (max-width: 700px) {
            .topbar {
                padding: 12px 17px;
            }

            .brand-name {
                font-size: 19px;
            }

            .page-container {
                margin: 20px auto;
                padding: 0 12px;
            }

            .page-heading h1 {
                font-size: 23px;
            }

            .chat-header {
                padding: 17px;
                min-height: 85px;
            }

            .customer-avatar {
                width: 49px;
                height: 49px;
                font-size: 18px;
            }

            .customer-info h2 {
                font-size: 16px;
            }

            .header-tag {
                display: none;
            }

            #messages {
                height: 55vh;
                min-height: 330px;
                padding: 22px 15px;
            }

            .message {
                max-width: 87%;
                padding: 12px 14px 9px;
            }

            .message-text {
                font-size: 13px;
            }

            .composer-area {
                padding: 12px;
            }

            .back-button {
                padding: 10px 12px;
                font-size: 12px;
            }
        }

        @media (max-width: 380px) {
            .brand-tagline {
                font-size: 8px;
            }

            .brand-name {
                font-size: 17px;
            }

            .brand-icon {
                width: 37px;
                height: 37px;
                font-size: 20px;
            }

            .back-button {
                padding: 9px;
            }
        }
    </style>
</head>

<body>

<!-- Top navigation -->
<header class="topbar">

    <div class="brand">
        <div class="brand-icon">H</div>

        <div>
            <div class="brand-name">
                Home<span>Ease</span>
            </div>

            <div class="brand-tagline">
                CONNECT. BOOK. THRIVE.
            </div>
        </div>
    </div>

    <a href="messages.php" class="back-button">
        <span>&larr;</span>
        Back to Messages
    </a>

</header>

<main class="page-container">

    <div class="page-heading">
        <h1>Messages</h1>
        <p>Stay connected with your customers and keep conversations going.</p>
    </div>

    <section class="chat-card">

        <!-- Customer conversation header -->
        <div class="chat-header">

            <div class="customer-details">

                <div class="customer-avatar">
                    <?= htmlspecialchars($initials, ENT_QUOTES, "UTF-8") ?>
                </div>

                <div class="customer-info">

                    <h2>
                        <?= htmlspecialchars(
                            $customer_name,
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>
                    </h2>

                    <p class="conversation-label">
                        <span class="conversation-dot"></span>
                        Customer conversation
                    </p>

                </div>

            </div>

            <div class="header-tag">
                HomeEase Customer
            </div>

        </div>

        <!-- Messages -->
        <div id="messages" aria-live="polite">

            <div class="empty-state">
                <div class="empty-icon">&#9993;</div>
                <h3>Getting your conversation ready...</h3>
                <p>Your messages will appear here.</p>
            </div>

        </div>

        <div id="status" role="status" aria-live="polite"></div>

        <!-- Message composer -->
        <div class="composer-area">

            <form id="messageForm" class="composer">

                <textarea
                    id="messageInput"
                    placeholder="Write your message..."
                    maxlength="2000"
                    required
                    aria-label="Your message"
                    rows="1"
                ></textarea>

                <button
                    type="submit"
                    id="sendButton"
                    aria-label="Send message"
                    title="Send message"
                >
                    &#10148;
                </button>

            </form>

            <p class="composer-hint">
                Press Enter to send. Use Shift + Enter for a new line.
            </p>

        </div>

    </section>

</main>

<script>
const conversationId = <?= (int) $conversation_id ?>;
const currentUserId = <?= (int) $provider_id ?>;
const csrfToken = <?= json_encode(
    $csrf_token,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
) ?>;
const customerName = <?= json_encode(
    $customer_name,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
) ?>;

const messagesBox = document.getElementById("messages");
const messageForm = document.getElementById("messageForm");
const messageInput = document.getElementById("messageInput");
const sendButton = document.getElementById("sendButton");
const statusBox = document.getElementById("status");

let lastMessageSignature = "";
let loading = false;
let sending = false;

// Convert the database timestamp into a JavaScript Date.
function parseMessageDate(value) {
    return new Date(String(value).replace(" ", "T"));
}

// Display a message time such as 10:25 AM.
function formatMessageTime(value) {
    const date = parseMessageDate(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleTimeString([], {
        hour: "numeric",
        minute: "2-digit"
    });
}

// Create a date key for grouping messages.
function getDateKey(value) {
    const date = parseMessageDate(value);

    if (Number.isNaN(date.getTime())) {
        return "";
    }

    return [
        date.getFullYear(),
        date.getMonth(),
        date.getDate()
    ].join("-");
}

// Display Today, Yesterday or a normal date.
function formatDateLabel(value) {
    const date = parseMessageDate(value);

    if (Number.isNaN(date.getTime())) {
        return "";
    }

    const today = new Date();

    const todayKey = getDateKey(
        today.toISOString().replace("T", " ").slice(0, 19)
    );

    const yesterday = new Date();
    yesterday.setDate(yesterday.getDate() - 1);

    const yesterdayKey = getDateKey(
        yesterday.toISOString().replace("T", " ").slice(0, 19)
    );

    const messageKey = getDateKey(value);

    if (messageKey === todayKey) {
        return "Today";
    }

    if (messageKey === yesterdayKey) {
        return "Yesterday";
    }

    return date.toLocaleDateString([], {
        day: "numeric",
        month: "long",
        year: "numeric"
    });
}

// Create an element safely using textContent.
function createTextElement(tag, className, value) {
    const element = document.createElement(tag);
    element.className = className;
    element.textContent = value;
    return element;
}

function renderMessages(messages) {

    const signature = JSON.stringify(
        messages.map(message => [
            message.id,
            message.message_text,
            message.is_read,
            message.delivered_at
        ])
    );

    if (signature === lastMessageSignature) {
        return;
    }

    const wasNearBottom =
        messagesBox.scrollHeight -
        messagesBox.scrollTop -
        messagesBox.clientHeight < 100;

    messagesBox.replaceChildren();

    if (messages.length === 0) {

        const emptyState = document.createElement("div");
        emptyState.className = "empty-state";

        const emptyIcon = createTextElement(
            "div",
            "empty-icon",
            "\u2709"
        );

        const heading = createTextElement(
            "h3",
            "",
            "Start a conversation"
        );

        const description = createTextElement(
            "p",
            "",
            "Send a message to your customer. You can discuss bookings, service details and any questions they may have."
        );

        emptyState.appendChild(emptyIcon);
        emptyState.appendChild(heading);
        emptyState.appendChild(description);

        messagesBox.appendChild(emptyState);

        lastMessageSignature = signature;
        return;
    }

    let previousDate = "";

    messages.forEach(message => {

        // Add a date separator when the date changes.
        const currentDate = getDateKey(message.created_at);

        if (currentDate && currentDate !== previousDate) {

            const divider = createTextElement(
                "div",
                "date-divider",
                formatDateLabel(message.created_at)
            );

            messagesBox.appendChild(divider);
            previousDate = currentDate;
        }

        const isOwnMessage =
            Number(message.sender_id) === currentUserId;

        const row = document.createElement("div");

        row.className = "message-row " +
            (isOwnMessage ? "provider" : "customer");

        const bubble = document.createElement("div");

        bubble.className = "message " +
            (isOwnMessage ? "provider" : "customer");

        const sender = createTextElement(
            "div",
            "sender",
            isOwnMessage ? "You" : customerName
        );

        const text = createTextElement(
            "div",
            "message-text",
            message.message_text
        );

        const meta = document.createElement("div");
        meta.className = "message-meta";

        const time = createTextElement(
            "span",
            "",
            formatMessageTime(message.created_at)
        );

        meta.appendChild(time);

        // Delivery and read receipts appear only on provider messages.
        if (isOwnMessage) {

            const messageStatus = document.createElement("span");

            messageStatus.className = "message-status";

            if (Number(message.is_read) === 1 || message.is_read === true) {

                messageStatus.textContent = "✓✓ Read";
                messageStatus.classList.add("read");

                messageStatus.title =
                    "The customer has read this message";

            } else if (message.delivered_at) {

                messageStatus.textContent = "✓✓ Delivered";
                messageStatus.classList.add("delivered");

                messageStatus.title =
                    "The message is available in HomeEase";

            } else {

                messageStatus.textContent = "✓ Sent";

                messageStatus.title =
                    "The message has been sent";
            }

            meta.appendChild(messageStatus);
        }

        bubble.appendChild(sender);
        bubble.appendChild(text);
        bubble.appendChild(meta);

        row.appendChild(bubble);

        messagesBox.appendChild(row);
    });

    lastMessageSignature = signature;

    if (wasNearBottom) {
        messagesBox.scrollTop = messagesBox.scrollHeight;
    }
}

// Retrieve messages from the existing messaging API.
async function loadMessages() {

    if (loading || document.hidden) {
        return;
    }

    loading = true;

    try {

        const response = await fetch(
            "../messages_api.php?conversation_id=" +
            encodeURIComponent(conversationId),
            {
                cache: "no-store"
            }
        );

        const data = await response.json();

        if (!response.ok || !data.success) {
            throw new Error(
                data.error || "Could not load messages."
            );
        }

        renderMessages(data.messages || []);

        statusBox.textContent = "";

    } catch (error) {

        statusBox.textContent =
            error.message || "Could not load messages.";

    } finally {

        loading = false;
    }
}

// Send a new message.
messageForm.addEventListener("submit", async function(event) {

    event.preventDefault();

    if (sending) {
        return;
    }

    const messageText = messageInput.value.trim();

    if (!messageText) {
        return;
    }

    sending = true;

    sendButton.disabled = true;
    statusBox.textContent = "";

    try {

        const response = await fetch("../messages_api.php", {

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
            throw new Error(
                data.error || "The message could not be sent."
            );
        }

        messageInput.value = "";
        messageInput.style.height = "43px";

        // Force the next refresh to render the updated conversation.
        lastMessageSignature = "";

        await loadMessages();

        messageInput.focus();

    } catch (error) {

        statusBox.textContent =
            error.message || "The message could not be sent.";

    } finally {

        sending = false;
        sendButton.disabled = false;
    }
});

// Enter sends. Shift + Enter creates a new line.
messageInput.addEventListener("keydown", function(event) {

    if (event.key === "Enter" && !event.shiftKey) {

        event.preventDefault();

        messageForm.requestSubmit();
    }
});

// Automatically expand the text area as the user types.
messageInput.addEventListener("input", function() {

    this.style.height = "43px";

    this.style.height = Math.min(
        this.scrollHeight,
        120
    ) + "px";
});

// Load messages immediately and refresh every three seconds.
loadMessages();

setInterval(loadMessages, 3000);

</script>

</body>
</html>
