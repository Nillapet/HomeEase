
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

require_once "../config/database.php";

$provider_id = (int) $_SESSION["user_id"];

/*
|--------------------------------------------------------------------------
| HELPER FUNCTION
|--------------------------------------------------------------------------
*/

function messageEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

/*
|--------------------------------------------------------------------------
| PROVIDER DETAILS
|--------------------------------------------------------------------------
*/

$provider_name = $_SESSION["full_name"]
    ?? $_SESSION["name"]
    ?? "Service Provider";

$name_stmt = $conn->prepare(
    "SELECT full_name FROM users WHERE id = ? LIMIT 1"
);

if ($name_stmt) {
    $name_stmt->bind_param("i", $provider_id);
    $name_stmt->execute();

    $name_result = $name_stmt->get_result();

    if ($name_row = $name_result->fetch_assoc()) {
        if (!empty($name_row["full_name"])) {
            $provider_name = $name_row["full_name"];
        }
    }

    $name_stmt->close();
}

$initial = strtoupper(substr(trim($provider_name), 0, 1));

/*
|--------------------------------------------------------------------------
| RETRIEVE PROVIDER CONVERSATIONS
|--------------------------------------------------------------------------
*/

$sql = "SELECT
            c.id AS conversation_id,
            c.customer_id,
            u.full_name AS customer_name,
            c.updated_at,

            (
                SELECT m.message_text
                FROM messages m
                WHERE m.conversation_id = c.id
                ORDER BY m.id DESC
                LIMIT 1
            ) AS last_message,

            (
                SELECT m.created_at
                FROM messages m
                WHERE m.conversation_id = c.id
                ORDER BY m.id DESC
                LIMIT 1
            ) AS last_message_time,

            (
                SELECT COUNT(*)
                FROM messages m
                WHERE m.conversation_id = c.id
                  AND m.sender_id <> ?
                  AND m.is_read = 0
            ) AS unread_count

        FROM conversations c
        INNER JOIN users u
            ON u.id = c.customer_id
        WHERE c.provider_id = ?
        ORDER BY c.updated_at DESC";

$stmt = $conn->prepare($sql);

$conversations = [];
$total_unread = 0;

if ($stmt) {
    $stmt->bind_param("ii", $provider_id, $provider_id);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $conversations[] = $row;
        $total_unread += (int) $row["unread_count"];
    }

    $stmt->close();
}

/*
|--------------------------------------------------------------------------
| SIDEBAR COUNTS
|--------------------------------------------------------------------------
*/

$pending_count = 0;

$pending_stmt = $conn->prepare(
    "SELECT COUNT(*) AS total
     FROM bookings
     WHERE provider_id = ?
       AND status = 'pending'"
);

if ($pending_stmt) {
    $pending_stmt->bind_param("i", $provider_id);
    $pending_stmt->execute();

    $pending_result = $pending_stmt->get_result();
    $pending_row = $pending_result->fetch_assoc();

    $pending_count = (int) ($pending_row["total"] ?? 0);

    $pending_stmt->close();
}

$conn->close();

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Messages | HomeEase</title>

    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/provider.css">

</head>

<body class="provider-messages-page">

<div class="provider-dashboard-layout">

    <!-- SIDEBAR: MATCHES PROVIDER DASHBOARD -->

    <aside class="provider-sidebar">

        <a href="../index.php" class="provider-brand">

            <span class="provider-brand-icon">H</span>

            <span class="provider-brand-copy">

                <span class="provider-brand-name">
                    Home<span>Ease</span>
                </span>

                <span class="provider-brand-tagline">
                    CONNECT. BOOK. THRIVE.
                </span>

            </span>

        </a>

        <div class="provider-sidebar-profile">

            <div class="provider-avatar">
                <?php echo messageEscape($initial); ?>
            </div>

            <div class="provider-profile-details">

                <h3>
                    <?php echo messageEscape($provider_name); ?>
                </h3>

                <p>Service Provider</p>

            </div>

        </div>

        <p class="provider-sidebar-label">MAIN MENU</p>

        <nav class="provider-sidebar-nav">

            <a href="dashboard.php">

                <span class="provider-nav-icon">⌂</span>

                <span class="provider-nav-text">Dashboard</span>

            </a>

            <a href="profile.php">

                <span class="provider-nav-icon">♙</span>

                <span class="provider-nav-text">My Profile</span>

            </a>

            <a href="services.php">

                <span class="provider-nav-icon">⚙</span>

                <span class="provider-nav-text">My Services</span>

            </a>

            <a href="availability.php">

                <span class="provider-nav-icon">◷</span>

                <span class="provider-nav-text">Availability</span>

            </a>

            <a href="bookings.php">

                <span class="provider-nav-icon">▣</span>

                <span class="provider-nav-text">My Bookings</span>

                <?php if ($pending_count > 0): ?>
                    <span class="provider-nav-badge">
                        <?php echo $pending_count; ?>
                    </span>
                <?php endif; ?>

            </a>

            <a href="messages.php" class="active" aria-current="page">

                <span class="provider-nav-icon">✉</span>

                <span class="provider-nav-text">Messages</span>

                <?php if ($total_unread > 0): ?>
                    <span class="provider-nav-badge">
                        <?php echo $total_unread; ?>
                    </span>
                <?php endif; ?>

            </a>

        </nav>

        <div class="provider-sidebar-bottom">

            <a href="../logout.php">

                <span class="provider-nav-icon">↪</span>

                <span>Logout</span>

            </a>

            <div class="provider-sidebar-footer">
                Your Skills. <span>Our Community.</span>
            </div>

        </div>

    </aside>

    <!-- MAIN CONTENT -->

    <main class="provider-dashboard-content">

        <div class="provider-messages-main">

            <!-- PAGE INTRODUCTION -->

            <section class="provider-messages-hero">

                <div>

                    <p class="provider-messages-eyebrow">
                        PROVIDER WORKSPACE
                    </p>

                    <h1>Customer Messages</h1>

                    <p>
                        Stay connected with your customers. View your conversations
                        and respond to enquiries about your services and bookings.
                    </p>

                </div>

                <a href="dashboard.php" class="provider-messages-back">
                    &larr; Back to Dashboard
                </a>

            </section>

            <!-- SUMMARY -->

            <section class="provider-messages-summary">

                <div class="provider-messages-summary-icon">
                    ✉
                </div>

                <div>

                    <h3>Keep in touch with your customers</h3>

                    <p>
                        Open a conversation to read messages and respond to
                        customer enquiries. Unread messages are highlighted
                        so you can easily identify conversations that need attention.
                    </p>

                </div>

            </section>

            <!-- CONVERSATIONS -->

            <section class="provider-messages-section">

                <div class="provider-messages-section-heading">

                    <h2>Your Conversations</h2>

                    <span class="provider-conversation-count">

                        <?php echo count($conversations); ?>

                        <?php echo count($conversations) === 1
                            ? "Conversation"
                            : "Conversations"; ?>

                    </span>

                </div>

                <?php if (empty($conversations)): ?>

                    <div class="provider-messages-empty">

                        <div class="provider-messages-empty-icon">
                            ✉
                        </div>

                        <h3>No messages yet</h3>

                        <p>
                            When customers message you through HomeEase,
                            their conversations will appear here. You can
                            open any conversation to communicate directly
                            with your customers.
                        </p>

                        <a href="services.php" class="provider-messages-empty-link">
                            View My Services
                        </a>

                    </div>

                <?php else: ?>

                    <div class="provider-conversation-list">

                        <?php foreach ($conversations as $conversation): ?>

                            <?php
                                $customer_name = $conversation["customer_name"]
                                    ?? "Customer";

                                $customer_initial = strtoupper(
                                    substr(trim($customer_name), 0, 1)
                                );

                                $preview = $conversation["last_message"]
                                    ?? "Open conversation to start messaging.";

                                if (
                                    function_exists("mb_strlen") &&
                                    mb_strlen($preview, "UTF-8") > 100
                                ) {
                                    $preview = mb_substr(
                                        $preview,
                                        0,
                                        100,
                                        "UTF-8"
                                    ) . "...";
                                } elseif (strlen($preview) > 100) {
                                    $preview = substr($preview, 0, 100) . "...";
                                }

                                $message_date = "No messages yet";

                                if (!empty($conversation["last_message_time"])) {
                                    $timestamp = strtotime(
                                        $conversation["last_message_time"]
                                    );

                                    if ($timestamp !== false) {
                                        $message_date = date(
                                            "d M Y, g:i A",
                                            $timestamp
                                        );
                                    }
                                }

                                $unread = (int) $conversation["unread_count"];
                            ?>

                            <a
                                class="provider-conversation"
                                href="chat.php?conversation_id=<?php
                                    echo (int) $conversation["conversation_id"];
                                ?>"
                            >

                                <div class="provider-conversation-top">

                                    <div class="provider-customer-info">

                                        <div class="provider-customer-avatar">
                                            <?php echo messageEscape($customer_initial); ?>
                                        </div>

                                        <div class="provider-customer-details">

                                            <div class="provider-customer-name">
                                                <?php echo messageEscape($customer_name); ?>
                                            </div>

                                            <div class="provider-conversation-label">
                                                Customer conversation
                                            </div>

                                        </div>

                                    </div>

                                    <span class="provider-conversation-date">
                                        <?php echo messageEscape($message_date); ?>
                                    </span>

                                </div>

                                <p class="provider-conversation-preview">
                                    <?php echo messageEscape($preview); ?>
                                </p>

                                <div class="provider-conversation-bottom">

                                    <div>

                                        <?php if ($unread > 0): ?>

                                            <span class="provider-unread-badge">

                                                <?php echo $unread; ?>

                                                unread

                                                <?php echo $unread === 1
                                                    ? "message"
                                                    : "messages"; ?>

                                            </span>

                                        <?php endif; ?>

                                    </div>

                                    <span class="provider-open-chat">
                                        Open Conversation &rarr;
                                    </span>

                                </div>

                            </a>

                        <?php endforeach; ?>

                    </div>

                <?php endif; ?>

            </section>

        </div>

    </main>

</div>

</body>
</html>
