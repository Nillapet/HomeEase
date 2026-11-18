
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

$customer_id = (int) $_SESSION["user_id"];
$customer_name = $_SESSION["full_name"] ?? "Customer";

$conversations = [];
$error = "";

/*
 * Retrieve this customer's conversations, provider details,
 * latest messages and unread provider messages.
 */
$sql = "
    SELECT
        c.id AS conversation_id,
        c.provider_id,
        u.full_name AS provider_name,
        u.email AS provider_email,
        latest.message_text AS latest_message,
        latest.created_at AS latest_message_time,
        latest.sender_id AS latest_sender_id,
        (
            SELECT COUNT(*)
            FROM messages unread
            WHERE unread.conversation_id = c.id
              AND unread.sender_id = c.provider_id
              AND unread.is_read = 0
        ) AS unread_count
    FROM conversations c
    INNER JOIN users u
        ON u.id = c.provider_id
    LEFT JOIN messages latest
        ON latest.id = (
            SELECT m2.id
            FROM messages m2
            WHERE m2.conversation_id = c.id
            ORDER BY m2.created_at DESC, m2.id DESC
            LIMIT 1
        )
    WHERE c.customer_id = ?
    ORDER BY
        COALESCE(latest.created_at, c.updated_at) DESC,
        c.id DESC
";

$stmt = $conn->prepare($sql);

if ($stmt) {
    $stmt->bind_param("i", $customer_id);

    if ($stmt->execute()) {
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $conversations[] = $row;
        }
    } else {
        $error = "We couldn't load your messages. Please try again.";
    }

    $stmt->close();
} else {
    $error = "We couldn't load your messages. Please try again.";
}

/*
 * Safely display text from the database.
 */
function messageEscape($value)
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        "UTF-8"
    );
}

// Generate customer initials.
$name_parts = preg_split('/\s+/', trim($customer_name));
$customer_initials = "";

foreach (array_slice($name_parts, 0, 2) as $part) {
    $customer_initials .= strtoupper(substr($part, 0, 1));
}

if ($customer_initials === "") {
    $customer_initials = "C";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Messages | HomeEase</title>

    <link rel="stylesheet" href="../css/style.css">
    <link rel="stylesheet" href="../css/customer.css">

    <style>
        .messages-content {
            width: 100%;
        }

        .messages-heading {
            margin-bottom: 25px;
        }

        .messages-heading h1 {
            margin-bottom: 8px;
        }

        .messages-heading p {
            color: #777;
            margin: 0;
        }

        .conversation-list {
            display: grid;
            gap: 14px;
        }

        .conversation-card {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            padding: 20px;
            background: #fff;
            border: 1px solid #e9e9e3;
            border-radius: 12px;
            text-decoration: none;
            color: inherit;
            transition: border-color .2s, transform .2s;
        }

        .conversation-card:hover {
            border-color: #174d38;
            transform: translateY(-2px);
        }

        .conversation-person {
            display: flex;
            align-items: center;
            gap: 15px;
            min-width: 0;
        }

        .conversation-avatar {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: #e8f0eb;
            color: #174d38;
            font-size: 20px;
            font-weight: 700;
        }

        .conversation-details {
            min-width: 0;
        }

        .conversation-details h2 {
            margin: 0 0 6px;
            font-size: 16px;
            color: #292d26;
        }

        .conversation-details p {
            margin: 0;
            color: #666;
            font-size: 13px;
            line-height: 1.5;
            overflow-wrap: anywhere;
        }

        .conversation-email {
            display: block;
            margin-top: 5px;
            color: #888;
            font-size: 12px;
        }

        .conversation-meta {
            flex-shrink: 0;
            text-align: right;
        }

        .conversation-date {
            display: block;
            margin-bottom: 8px;
            color: #888;
            font-size: 11px;
        }

        .conversation-unread {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            background: #450011;
            color: #fff;
            font-size: 11px;
            font-weight: 700;
        }

        .conversation-arrow {
            display: block;
            margin-top: 8px;
            color: #174d38;
            font-size: 12px;
            font-weight: 600;
        }

        .messages-empty {
            padding: 40px 24px;
            text-align: center;
            background: #fff;
            border: 1px solid #e9e9e3;
            border-radius: 12px;
        }

        .messages-empty h2 {
            margin-top: 0;
            color: #292d26;
        }

        .messages-empty p {
            color: #777;
            line-height: 1.7;
            max-width: 480px;
            margin: 0 auto 20px;
            font-size: 14px;
        }

        .messages-empty-icon {
            font-size: 35px;
            margin-bottom: 12px;
        }

        .messages-primary-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 11px 17px;
            background: #174d38;
            color: #fff;
            border-radius: 8px;
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
        }

        .messages-primary-btn:hover {
            background: #103b2b;
        }

        .messages-error {
            padding: 18px;
            background: #f8e8e8;
            color: #a33b45;
            border: 1px solid #efd0d0;
            border-radius: 10px;
            font-size: 14px;
        }

        @media (max-width: 600px) {
            .conversation-card {
                align-items: flex-start;
                padding: 15px;
                gap: 10px;
            }

            .conversation-person {
                gap: 10px;
            }

            .conversation-avatar {
                width: 40px;
                height: 40px;
                font-size: 17px;
            }

            .conversation-meta {
                max-width: 100px;
            }
        }

        @media (max-width: 420px) {
            .conversation-card {
                flex-direction: column;
            }

            .conversation-meta {
                width: 100%;
                max-width: none;
                text-align: left;
                padding-left: 50px;
            }
        }
    </style>
</head>

<body class="customer-dashboard-page">

    <div class="customer-layout">

        <!-- Sidebar -->
        <aside class="customer-sidebar">

            <a href="dashboard.php" class="customer-brand">
                <span class="customer-brand-mark">H</span>
                <span class="customer-brand-name">HomeEase</span>
            </a>

            <div class="sidebar-user">

                <div class="sidebar-user-avatar">
                    <?= messageEscape($customer_initials) ?>
                </div>

                <div class="sidebar-user-details">
                    <strong><?= messageEscape($customer_name) ?></strong>
                    <span>Customer</span>
                </div>

            </div>

            <span class="sidebar-section-label">MENU</span>

            <nav class="customer-sidebar-nav">

                <a href="dashboard.php" class="sidebar-link">
                    <span class="sidebar-icon">⌂</span>
                    <span>Dashboard</span>
                </a>

                <a href="search.php" class="sidebar-link">
                    <span class="sidebar-icon">⌕</span>
                    <span>Find a Service</span>
                </a>

                <a href="bookings.php" class="sidebar-link">
                    <span class="sidebar-icon">▣</span>
                    <span>My Bookings</span>
                </a>

                <a href="messages.php" class="sidebar-link active" aria-current="page">
                    <span class="sidebar-icon">✉</span>
                    <span>My Messages</span>
                </a>

            </nav>

            <div class="sidebar-footer">
                <div class="sidebar-footer-mark">H</div>
                <span>HomeEase Services</span>
            </div>

        </aside>

        <!-- Main content -->
        <main class="customer-main-content">

            <div class="messages-content">

                <section class="messages-heading">

                    <h1>My Messages</h1>

                    <p>
                        Chat with your service providers, ask questions
                        and check their replies.
                    </p>

                </section>

                <?php if ($error !== ""): ?>

                    <div class="messages-error">
                        <?= messageEscape($error) ?>
                    </div>

                <?php elseif (empty($conversations)): ?>

                    <section class="messages-empty">

                        <div class="messages-empty-icon" aria-hidden="true">
                            ✉
                        </div>

                        <h2>No conversations yet</h2>

                        <p>
                            When you message a service provider, your
                            conversation will appear here. You can return
                            at any time to read their replies and continue
                            chatting.
                        </p>

                        <a href="search.php" class="messages-primary-btn">
                            Find a Service Provider →
                        </a>

                    </section>

                <?php else: ?>

                    <section class="conversation-list">

                        <?php foreach ($conversations as $conversation): ?>

                            <?php
                                $provider_name = $conversation["provider_name"]
                                    ?: "Provider";

                                $latest_message = $conversation["latest_message"]
                                    ?? "Start your conversation with this provider.";

                                $preview = mb_substr($latest_message, 0, 100);

                                if (mb_strlen($latest_message) > 100) {
                                    $preview .= "...";
                                }

                                $unread = (int) $conversation["unread_count"];

                                $initial = strtoupper(
                                    mb_substr($provider_name, 0, 1)
                                );
                            ?>

                            <a
                                class="conversation-card"
                                href="chat.php?conversation_id=<?= (int) $conversation["conversation_id"] ?>"
                            >

                                <div class="conversation-person">

                                    <div class="conversation-avatar">
                                        <?= messageEscape($initial) ?>
                                    </div>

                                    <div class="conversation-details">

                                        <h2>
                                            <?= messageEscape($provider_name) ?>
                                        </h2>

                                        <p>

                                            <?php if (
                                                (int) $conversation["latest_sender_id"] === $customer_id
                                            ): ?>

                                                <strong>You:</strong>

                                            <?php endif; ?>

                                            <?= messageEscape($preview) ?>

                                        </p>

                                        <span class="conversation-email">
                                            <?= messageEscape(
                                                $conversation["provider_email"] ?? ""
                                            ) ?>
                                        </span>

                                    </div>

                                </div>

                                <div class="conversation-meta">

                                    <span class="conversation-date">

                                        <?php if (!empty($conversation["latest_message_time"])): ?>

                                            <?= date(
                                                "d M Y, H:i",
                                                strtotime($conversation["latest_message_time"])
                                            ) ?>

                                        <?php endif; ?>

                                    </span>

                                    <?php if ($unread > 0): ?>

                                        <span class="conversation-unread">
                                            <?= $unread ?> unread
                                        </span>

                                    <?php endif; ?>

                                    <span class="conversation-arrow">
                                        Open chat →
                                    </span>

                                </div>

                            </a>

                        <?php endforeach; ?>

                    </section>

                <?php endif; ?>

            </div>

        </main>

    </div>

</body>
</html>
